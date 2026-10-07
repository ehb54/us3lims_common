<?php
/*
 * Execute cluster commands and copies over SSH/SCP, including local clusters.
 * Applies timeouts, transport retries and a shared circuit breaker.
 * Results distinguish remote command failures from transport faults;
 * callers must not infer job state from a transport fault.
 */

class remote_exec
{
   ## Default execution budgets and retry policy.
   const CONNECT_TIMEOUT_SECONDS = 15;
   const COMMAND_TIMEOUT_SECONDS = 120;
   const COPY_TIMEOUT_SECONDS    = 900;
   const TRANSPORT_RETRIES       = 3;
   const RETRY_WAIT_SECONDS      = 5;
   const BREAKER_FAILURES        = 3;
   const BREAKER_COOLDOWN_SECONDS = 120;

   ## Use a shared path outside service-private /tmp directories.
   ## Override with $global_circuit_breaker_dir when needed.
   ## Under ~us3/lims/etc (see breaker()); /var/tmp is split by PrivateTmp and aged out.
   const BREAKER_DIR = '/home/us3/lims/etc/circuit-breaker';

   ## A submission makes three separate connections to the cluster login
   ## (one mkdir, one scp, one sbatch); the ssh-launched monitor is a fourth
   ## ssh call but to the LIMS host, not the cluster, so it never shares this
   ## socket. Each of the three paid full TCP+auth separately, measured at
   ## ~2.7s/job. An SSH
   ## ControlMaster socket, left open for a short while after the first
   ## connection, lets the rest of the submission reuse it instead. Same
   ## PrivateTmp reasoning as the breaker directory: a service-private /tmp
   ## would make the socket invisible to the next call if it runs as a
   ## different unit, so this lives beside the breaker, not under /tmp.
   ## Override with $global_ssh_control_dir when needed.
   const CONTROL_DIR = '/home/us3/lims/etc/ssh-control';

   ## Kept open long enough to cover one submission's calls, not so long that
   ## a stale master lingers past the jobs it was opened for.
   const CONTROL_PERSIST_SECONDS = 60;

   ## Result classifications. Callers branch on these, never on exit codes.

   ## The command ran and exited 0.
   const OK          = 'OK';

   ## Transport failed; remote job state is unknown.
   const UNREACHABLE = 'UNREACHABLE';

   ## The call exceeded its time budget; remote job state is unknown.
   const TIMED_OUT   = 'TIMED_OUT';

   ## The remote command exited non-zero.
   const REMOTE_FAIL = 'REMOTE_FAIL';

   ## timeout(1) exit codes: 124 = deadline hit, 137 = SIGKILL after --kill-after.
   const EXIT_TIMEOUT = 124;
   const EXIT_KILLED  = 137;

   ## ssh reserves 255 for its own errors, as distinct from the remote command's
   ## exit status. scp has no such reservation, hence the stderr patterns below.
   const EXIT_SSH_ERROR = 255;

   ## Markers that fence the remote command's own stdout. See frame().
   const FRAME_BEGIN = '__us3_rx_begin_';
   const FRAME_END   = '__us3_rx_end_';

   private $cluster;
   private $details;
   private $policy;
   private $overrides;
   private $log;

   ## Once a multiplexed call on this instance has actually failed on its
   ## control socket, every later call through it skips straight to a
   ## plain connection instead of paying one wasted connect attempt first
   ## -- the failure (a too-long path, SELinux, EL9's quoting) is a
   ## property of this host/account/cluster for as long as this instance
   ## is used, not of any one call. submit_slurm keeps one instance per
   ## cluster per submission (remote($cluster)), which is the scope this
   ## is meant to cover; it is an instance property, not a static, so nothing
   ## leaks between separate remote_exec objects in the same process (tests
   ## construct a fresh one per case).
   private $muxUnusable = false;

   ## Logs controlPath() refusing a usable directory once per instance,
   ## instead of once per call.
   private $muxDirectoryWarned = false;

   ## Resolved lazily by timeout_bin(); '' means "not available on this host".
   protected $timeout_bin = null;

   ## Optional caller-supplied exec seam; see set_executor().
   private $executor = null;

   ## null = not yet resolved, false = explicitly disabled, else a circuit_breaker.
   private $breaker = null;

   ## Populated by the last run/copy call, for callers that want detail.
   public $last = array();

   /**
    * $cluster         short name, the key into $cluster_details
    * $cluster_details the whole $cluster_details map from global_config.php
    * $log             optional callable( string ) for diagnostics; the
    *                  procedural callers pass write_log/write_logld, the
    *                  classes pass elog2. Defaults to error_log().
    */
   public function __construct( $cluster, $cluster_details, $log = null,
                                $policy = array() )
   {
      $this->cluster = $cluster;
      $this->details = isset( $cluster_details[ $cluster ] ) ? $cluster_details[ $cluster ] : array();
      $this->log     = is_callable( $log ) ? $log : function( $m ) { error_log( "remote_exec: $m" ); };
      $this->policy  = $this->validatePolicy( $policy );
      $this->overrides = $this->validateClusterOverrides();
   }

   ## Whether the cluster has a named configuration entry.
   public function is_configured()
   {
      return ! empty( $this->details ) && isset( $this->details[ 'name' ] );
   }

   ## user@host for ssh/scp. 'login' is already a user@host string when present.
   public function login()
   {
      if ( isset( $this->details[ 'login' ] ) )
      {
         return $this->details[ 'login' ];
      }

      return isset( $this->details[ 'name' ] ) ? 'us3@' . $this->details[ 'name' ] : '';
   }

   public function port()
   {
      return isset( $this->details[ 'sshport' ] ) ? $this->details[ 'sshport' ] : 22;
   }

   /**
    * Run a command on the cluster.
    *
    * $opts: 'timeout' (seconds, default COMMAND_TIMEOUT_SECONDS),
    *        'retries' / 'retry_wait' (internal/call-level test seams),
    *        'label'   (appears in diagnostics).
    *
    * Returns the result array described at result().
    */
   public function run( $remote_cmd, $opts = array() )
   {
      $timeout = $this->operationTimeout(
         $opts, 'command_timeout_seconds', 'command_timeout_seconds' );

      $nonce = $this->frameNonce();
      $multiplex = ! empty( $opts[ 'multiplex' ] );

      ## The command has to survive one extra round of shell parsing on the
      ## login node, so it is passed as a single quoted argument.
      $cmd = '/usr/bin/ssh ' . $this->sshOpts( $multiplex ) . ' '
             . escapeshellarg( $this->login() )
             . ' ' . escapeshellarg( $this->frame( $remote_cmd, $nonce ) );

      $fallback_cmd = $multiplex
         ? '/usr/bin/ssh ' . $this->sshOpts( false ) . ' '
           . escapeshellarg( $this->login() )
           . ' ' . escapeshellarg( $this->frame( $remote_cmd, $nonce ) )
         : null;

      $result = $this->unframe(
         $this->attempt( $cmd, $timeout, $opts, isset( $opts['label'] ) ? $opts['label'] : 'run', $fallback_cmd, $nonce ),
         $nonce
      );

      ## attempt() recorded the unsliced result; callers reading ->last must
      ## see the same stdout run() returns.
      $this->last = $result;

      return $result;
   }

   /**
    * Fence stdout to exclude login-shell banners and preserve the command's
    * exit status. Requires a POSIX-compatible login shell.
    */
   private function frame( $remote_cmd, $nonce )
   {
      ## The braces are there so a multi-command payload still yields one status.
      ## The end marker carries the remote command's own exit status
      ## ($__us3_rx_rc), not just its presence: ssh can still exit 255 if
      ## the connection drops between the remote side printing this line
      ## and ssh relaying its own exit code, which otherwise reads as a
      ## transport failure for a command that already finished and told us
      ## how. once() trusts this embedded status over ssh's own exit code
      ## whenever it can be read back.
      return 'echo ' . self::FRAME_BEGIN . $nonce . "\n"
             . '{ ' . $remote_cmd . "\n" . '}' . "\n"
             . '__us3_rx_rc=$?' . "\n"
             . 'echo ' . self::FRAME_END . $nonce . ':$__us3_rx_rc' . "\n"
             . 'exit $__us3_rx_rc' . "\n";
   }

   /**
    * Extract framed stdout, preserving an unterminated final line.
    * Leave unframed output unchanged; keep partial output if the end is absent.
    */
   private function unframe( $result, $nonce )
   {
      ## 'began': the framed command started on the remote side (its begin
      ## marker came back). Without it, the remote command provably never ran.
      $result[ 'began' ] = false;

      if ( ! isset( $result[ 'stdout' ] ) || ! is_array( $result[ 'stdout' ] ) )
      {
         return $result;
      }

      $begin = self::FRAME_BEGIN . $nonce;
      $end   = self::FRAME_END . $nonce;
      $lines = array_values( $result[ 'stdout' ] );
      $first = null;

      foreach ( $lines as $i => $line )
      {
         if ( strpos( $line, $begin ) !== false )
         {
            $first = $i;
            break;
         }
      }

      if ( $first === null )
      {
         return $result;
      }

      $result[ 'began' ] = true;
      $kept = array();

      for ( $i = $first + 1; $i < count( $lines ); $i++ )
      {
         $at = strpos( $lines[ $i ], $end );

         if ( $at === false )
         {
            $kept[] = $lines[ $i ];
            continue;
         }

         ## Whatever precedes the end fence on its line is the unterminated
         ## last line of the answer, not noise.
         if ( $at > 0 )
         {
            $kept[] = substr( $lines[ $i ], 0, $at );
         }

         break;
      }

      $result[ 'stdout' ] = $kept;
      $result[ 'text' ]   = trim( implode( "\n", $kept ) );

      return $result;
   }

   /** Unique per call, and cheap. Not a security boundary. */
   private function frameNonce()
   {
      return bin2hex( random_bytes( 8 ) );
   }

   /**
    * Copy $remote_path (a path on the cluster) into the local $local_dest.
    */
   public function copy_from( $remote_path, $local_dest, $opts = array() )
   {
      $timeout = $this->operationTimeout(
         $opts, 'copy_timeout_seconds', 'copy_timeout_seconds' );

      $multiplex = ! empty( $opts[ 'multiplex' ] );

      $cmd = '/usr/bin/scp ' . $this->scpOpts( $multiplex )
             . ' ' . escapeshellarg( $this->login() . ':' . $remote_path )
             . ' ' . escapeshellarg( $local_dest );

      $fallback_cmd = $multiplex
         ? '/usr/bin/scp ' . $this->scpOpts( false )
           . ' ' . escapeshellarg( $this->login() . ':' . $remote_path )
           . ' ' . escapeshellarg( $local_dest )
         : null;

      return $this->attempt( $cmd, $timeout, $opts,
         isset( $opts['label'] ) ? $opts['label'] : 'copy_from', $fallback_cmd );
   }

   /**
    * Copy local files to $remote_dest on the cluster. $local_paths may be a
    * single path or an array of them.
    */
   public function copy_to( $local_paths, $remote_dest, $opts = array() )
   {
      $timeout = $this->operationTimeout(
         $opts, 'copy_timeout_seconds', 'copy_timeout_seconds' );

      $paths = is_array( $local_paths ) ? $local_paths : array( $local_paths );
      $srcs  = implode( ' ', array_map( 'escapeshellarg', $paths ) );
      $multiplex = ! empty( $opts[ 'multiplex' ] );

      $cmd = '/usr/bin/scp ' . $this->scpOpts( $multiplex ) . ' ' . $srcs
             . ' ' . escapeshellarg( $this->login() . ':' . $remote_dest );

      $fallback_cmd = $multiplex
         ? '/usr/bin/scp ' . $this->scpOpts( false ) . ' ' . $srcs
           . ' ' . escapeshellarg( $this->login() . ':' . $remote_dest )
         : null;

      return $this->attempt( $cmd, $timeout, $opts,
         isset( $opts['label'] ) ? $opts['label'] : 'copy_to', $fallback_cmd );
   }

   /** Probe without retries or breaker gating. Feeds an infra fault back into
    *  the breaker as a failure; never records a success, since bare ssh
    *  reachability says nothing about the controller behind it. */
   public function ping( $opts = array() )
   {
      $timeout = $this->operationTimeout(
         $opts, 'connect_timeout_seconds', 'connect_timeout_seconds' );

      $res = $this->run( '/bin/true', array(
         'timeout' => $timeout,
         'retries' => 0,
         'breaker' => false,
         'label'   => 'ping',
      ) );

      ## Feed a failure back in by hand, since the bypass skipped the
      ## bookkeeping in attempt(). Not a success, even though this is also
      ## the probe outage_timeout_verdict() reads to decide whether a stall
      ## is the cluster's fault: /bin/true over ssh answering says the
      ## transport is up, not that the controller is -- a sbatch/squeue
      ## failing against a down controller is a real failure the breaker
      ## tracks through attempt(), and a ping that never asked the
      ## controller anything is not grounds to erase it.
      $breaker = $this->breaker();

      if ( $breaker !== null && remote_exec_infra_fault( $res ) )
      {
         $breaker->record_failure( $this->cluster );
      }

      return $res;
   }

   ## ---------------------------------------------------------------- internals

   /**
    * Retry transport faults with backoff, subject to the shared circuit
    * breaker. $fallback_cmd, when given, is the same command built without
    * multiplexing: a ControlPath failure (socket path too long, cannot
    * bind, an SELinux denial the PHP-side checks in controlPath() cannot
    * see) is deterministic, so retrying $cmd unchanged would just fail the
    * same way every time. That failure happens at the ssh/scp level before
    * the remote command ever runs, so switching command for the rest of
    * this same retry budget is always safe, including for sbatch.
    */
   private function attempt( $cmd, $timeout, $opts, $label, $fallback_cmd = null, $nonce = null )
   {
      $retries = array_key_exists( 'retries', $opts )
               ? $opts[ 'retries' ] : $this->policy[ 'retries' ];
      $retry_wait = array_key_exists( 'retry_wait', $opts )
                  ? $opts[ 'retry_wait' ] : $this->policy[ 'retry_wait_seconds' ];

      ## M4: a cluster with no configuration entry has no host to contact.
      if ( ! $this->is_configured() )
      {
         $this->logf( "$label: cluster '{$this->cluster}' is not configured; not contacting it" );
         $result = $this->result( self::REMOTE_FAIL, self::EXIT_SSH_ERROR, array(),
            "cluster '{$this->cluster}' is not configured in \$cluster_details", $cmd );
         $result[ 'attempts' ] = 0;
         $this->last = $result;
         return $result;
      }

      ## Health probes bypass the breaker to detect recovery.
      $use_breaker = ! isset( $opts[ 'breaker' ] ) || $opts[ 'breaker' ] !== false;
      $breaker     = $use_breaker ? $this->breaker() : null;

      ## 'breaker_gate' => false: use the breaker for its bookkeeping (a
      ## real failure here still counts against the cluster) but skip the
      ## open-breaker check that would otherwise refuse the call outright.
      ## For a caller that already has fresher evidence than the breaker
      ## does -- a ping that just confirmed the cluster is reachable right
      ## now -- refusing locally on an unrelated earlier failure would be
      ## stale information overriding a live one. Plain 'breaker' => false
      ## skips both the gate and the bookkeeping together, for callers (like
      ## health probes) that want neither.
      $skip_gate = $breaker !== null && array_key_exists( 'breaker_gate', $opts )
                 && $opts[ 'breaker_gate' ] === false;

      if ( $breaker !== null && ! $skip_gate && $breaker->is_open( $this->cluster ) )
      {
         $wait = $breaker->seconds_remaining( $this->cluster );
         $this->logf( "$label: skipped, breaker open for {$wait}s more" );

         $result = $this->result(
            self::UNREACHABLE, self::EXIT_SSH_ERROR, array(),
            "circuit breaker open for {$this->cluster}: the cluster failed repeated"
            . " connection attempts and is not being contacted again for {$wait}s",
            $cmd
         );
         $result[ 'attempts' ]     = 0;
         $result[ 'breaker_open' ] = true;
         $this->last = $result;

         return $result;
      }

      $wrapped = $this->withTimeout( $cmd, $timeout );
      $attempt = 0;
      $secwait = $retry_wait;
      $result  = null;
      $switchedToFallback = false;

      do
      {
         $attempt++;
         $result = $this->once( $wrapped, $cmd, $label, $attempt, $nonce );

         if ( $result[ 'class' ] === self::OK || $result[ 'class' ] === self::REMOTE_FAIL )
         {
            break;
         }

         ## Tested before the retry-budget break below: sbatch and the
         ## scontrol confirm both pass retries => 0, so with this check
         ## after the break instead, it was never reached for either of
         ## them -- despite the docblock on this method claiming it covered
         ## sbatch too. The fallback only ever re-runs a command that
         ## provably never started (a control-socket-specific failure, not
         ## a transport fault), so it needs no budget of its own.
         ##
         ## !$result['began']: a control-socket pattern in stderr is not
         ## proof the command never ran -- it can also be a line from the
         ## remote side's own banner/wrapper that happens to contain one of
         ## these strings, in which case the begin marker already came
         ## back and re-running on a plain connection would submit a
         ## second job for the same request. multiplexSocketFailed() is
         ## checked first since it is cheap and $began is only meaningful
         ## for a framed call in the first place.
         if ( ! $switchedToFallback && $fallback_cmd !== null
            && $this->multiplexSocketFailed( $result ) && empty( $result[ 'began' ] ) )
         {
            ## Single-line: $result['stderr'] can be multi-line (ssh's own
            ## banner/warning lines ahead of the real error), which
            ## otherwise breaks one log entry across several lines in the
            ## log file.
            $this->logf( "$label: control socket unusable ("
                       . str_replace( "\n", ' / ', (string) $result[ 'stderr' ] ) . ");"
                       . " switching to a plain connection for the rest of this retry budget"
                       . " and for the rest of this process" );
            $this->muxUnusable = true;
            $cmd     = $fallback_cmd;
            $wrapped = $this->withTimeout( $cmd, $timeout );
            $switchedToFallback = true;
            continue;
         }

         if ( $attempt > $retries || $this->is_permanent_failure( $result[ 'stderr' ] ) )
         {
            break;
         }

         $this->logf( "$label: {$result['class']} on attempt $attempt, retrying in {$secwait}s" );
         $this->pause( $secwait );
         $secwait *= 2;
      }
      while ( true );

      ## Both OK and REMOTE_FAIL prove the transport works, so both close the
      ## breaker. Only a transport fault counts against the cluster.
      if ( $breaker !== null && ! $this->is_permanent_failure( $result[ 'stderr' ] ) )
      {
         if ( remote_exec_infra_fault( $result ) )
         {
            $breaker->record_failure( $this->cluster );
         }
         else
         {
            $breaker->record_success( $this->cluster );
         }
      }

      $result[ 'attempts' ] = $attempt;
      $this->last = $result;

      return $result;
   }

   /**
    * Inject a breaker, or false to disable it for this instance.
    * Left unset, one is built lazily from the code-owned policy and the one
    * deployment fact it needs: the shared state directory.
    */
   public function set_breaker( $breaker )
   {
      $this->breaker = $breaker;
      return $this;
   }

   ## The us3 account's lims/etc, shared by the web tier and the daemons.
   private static function default_breaker_dir()
   {
      $us3 = function_exists( 'posix_getpwnam' ) ? posix_getpwnam( 'us3' ) : false;
      return $us3 ? $us3[ 'dir' ] . '/lims/etc/circuit-breaker' : self::BREAKER_DIR;
   }

   ## Beside the breaker directory, same reasoning. Created on first use, one
   ## account's sockets never readable by another: 0700, like the breaker's
   ## own per-account state.
   private static function default_control_dir()
   {
      $us3 = function_exists( 'posix_getpwnam' ) ? posix_getpwnam( 'us3' ) : false;
      return $us3 ? $us3[ 'dir' ] . '/lims/etc/ssh-control' : self::CONTROL_DIR;
   }

   ## The account this process runs as, for the per-account control
   ## subdirectory below. The login name when it can be read, else the uid:
   ## both are stable and filename-safe. Mirrors circuit_breaker::account().
   private function account()
   {
      $uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;

      if ( $uid !== null && function_exists( 'posix_getpwuid' ) )
      {
         $pw = @posix_getpwuid( $uid );
         if ( is_array( $pw ) && isset( $pw[ 'name' ] ) && $pw[ 'name' ] !== '' )
         {
            return preg_replace( '/[^A-Za-z0-9._-]/', '_', $pw[ 'name' ] );
         }
      }

      return $uid === null ? 'unknown' : (string) $uid;
   }

   /**
    * The -o ControlPath value, or '' when a safe one is not available:
    * multiplexing is a speed-up, not a requirement, so any check below
    * failing degrades to a plain connection per call rather than failing
    * the submission.
    */
   private function controlPath()
   {
      $dir = $GLOBALS[ 'global_ssh_control_dir' ] ?? self::default_control_dir();

      if ( $dir === '' )
      {
         return '';
      }

      ## Whitelist, not a blacklist: ssh splits the -o ControlPath=value
      ## option on whitespace and '=', expands any '%' sequence it does not
      ## itself recognise, ends an unquoted value early at a literal '"',
      ## and (OpenSSH 8.7+, EL9) rejects a "'" with "invalid quotes" that is
      ## not one of the fallback patterns -- each discovered as one more
      ## character to add to a blacklist. Only the characters a Unix path
      ## actually needs are let through instead, closing the whole class at
      ## once. Checked before the mkdir below, not after: a value this
      ## check is about to refuse should not get a directory created for it
      ## on disk first.
      if ( ! preg_match( '#^/[A-Za-z0-9._/-]+$#', $dir ) )
      {
         return '';
      }

      ## Shared top-level directory, group-writable so every local account
      ## that needs it can create its own subdirectory below -- unlike that
      ## subdirectory itself, this one is not where the socket lives.
      if ( ! is_dir( $dir ) )
      {
         @mkdir( $dir, 0770, true );
      }

      if ( ! is_dir( $dir ) || is_link( $dir ) )
      {
         return '';
      }

      ## Per-account subdirectory, 0700, exactly like the breaker's own state
      ## directory. OpenSSH's %C hashes local host + remote host + port +
      ## remote user, but not the local user, so two accounts logging in as
      ## the same remote user would otherwise compute the identical socket
      ## path and lock each other out of it: whichever creates it first owns
      ## it, and the other account's ssh fails to bind with "Permission
      ## denied" before it ever runs the command.
      $mine = rtrim( $dir, '/' ) . '/' . $this->account();

      ## The second is_dir() catches two processes racing the first use: the
      ## loser's mkdir() fails with EEXIST once the winner's has landed,
      ## which is success, not a reason to give up multiplexing.
      if ( ! is_dir( $mine ) && ! @mkdir( $mine, 0700, true ) && ! is_dir( $mine ) )
      {
         return '';
      }

      ## ssh_config(5) requires the ControlPath directory not be writable by
      ## other users. Checked here too, not just left to ssh to enforce,
      ## because a mux client does not itself verify the master before
      ## trusting it: another account planting a socket ahead of us3's own
      ## would otherwise be used silently. A root-owned directory is only
      ## trusted when this account can actually use it (e.g. an ACL); plain
      ## 0700-owned-by-root is not writable by us3 and would just exit 255,
      ## so is_writable() -- not a bare $owner === 0 exception -- decides it.
      $euid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
      $owner = @fileowner( $mine );
      $perms = @fileperms( $mine );

      if ( is_link( $mine )
         || $perms === false || ( $perms & 0077 ) !== 0
         || $this->controlDirOwnedByAnotherUnwritableAccount( $mine, $euid, $owner ) )
      {
         return '';
      }

      ## %C: OpenSSH's own hash of local host + target host + port + user,
      ## so the socket path is always short regardless of hostname length,
      ## and shared between the ssh and scp calls that target the same login.
      $path = rtrim( $mine, '/' ) . '/cm-%C';

      ## Unix domain socket paths are limited to a little over 100 bytes
      ## depending on platform. %C itself expands to a fixed 40-character
      ## hash, and ssh appends a further 17-character suffix when it binds
      ## the socket, so the directory portion ($mine) has to leave headroom
      ## for both, not just for the 2 literal characters "%C" takes up in
      ## $path above -- checking strlen($path) against a much larger number
      ## let a 42-character $mine through, which is fine for us3 but exits
      ## 255 ("ControlPath too long") for every apache call once apache's
      ## own home directory pushes $mine past this real ceiling.
      if ( strlen( $mine ) > 46 )
      {
         return '';
      }

      return $path;
   }

   ## Overridable test seam: a root-owned, not-writable-by-us3 directory
   ## is the one controlPath() branch no
   ## single test process can fake honestly -- is_writable() bypasses the
   ## permission check for root, and this account's own euid can't be
   ## something other than itself. Everything controlPath() does with the
   ## result is exercised already; only the real privilege boundary behind
   ## this one decision was previously untested.
   protected function controlDirOwnedByAnotherUnwritableAccount( $mine, $euid, $owner )
   {
      return $euid !== null && $owner !== $euid && ! @is_writable( $mine );
   }

   /** Returns null when no breaker is configured or available. */
   private function breaker()
   {
      if ( $this->breaker !== null )
      {
         return $this->breaker === false ? null : $this->breaker;
      }

      if ( ! class_exists( 'circuit_breaker' ) )
      {
         $dir = __DIR__ . '/circuit_breaker.php';

         if ( ! is_readable( $dir ) )
         {
            $this->breaker = false;
            return null;
         }

         require_once $dir;
      }

      $this->breaker = new circuit_breaker(
         $GLOBALS[ 'global_circuit_breaker_dir' ] ?? self::default_breaker_dir(),
         self::BREAKER_FAILURES,
         self::BREAKER_COOLDOWN_SECONDS,
         $this->log
      );

      return $this->breaker;
   }

   /**
    * True for a multiplexed call's control socket failing on its own
    * terms (path too long, cannot bind, a denial), as opposed to the host
    * or scheduler itself being unreachable -- the distinction attempt()
    * needs to decide whether switching to a plain connection can help.
    */
   private function multiplexSocketFailed( $result )
   {
      return $result[ 'class' ] === self::UNREACHABLE
         && preg_match(
            ## muxserver_listen|link mux listener: OpenSSH 8.0+ binds a temporary
            ## control socket, then link()s it into place (mux.c) -- a denied
            ## link() (e.g. an SELinux policy that allows create/unlink/write on
            ## the socket type but not link) exits 255 the same way a denied
            ## bind() does.
            '/unix_listener|ControlPath too long|Bad configuration option.*ControlPath'
            . '|muxserver_listen.*link mux listener/i',
            (string) $result[ 'stderr' ]
         );
   }

   ## Capture stderr separately so callers can parse stdout.
   private function once( $wrapped, $cmd, $label, $attempt, $nonce = null )
   {
      $stderr_tmp = tempnam( sys_get_temp_dir(), 'us3rx_' );
      $stdout     = array();
      $exit_code  = 0;

      $this->runExec( "$wrapped 2>$stderr_tmp", $stdout, $exit_code );

      $stderr = is_readable( $stderr_tmp ) ? trim( file_get_contents( $stderr_tmp ) ) : '';
      @unlink( $stderr_tmp );

      $joined = implode( "\n", $stdout );

      ## Did the framed command actually start on the remote side? Only
      ## meaningful for a framed (non-scp) command. Without this, attempt()'s
      ## plain-connection fallback could re-run a command from the start on
      ## nothing more than a coincidental stderr pattern, even though the
      ## remote side already began -- and for sbatch, already submitted.
      $began = $nonce !== null && strpos( $joined, self::FRAME_BEGIN . $nonce ) !== false;

      ## Did the framed command's own end marker come back, and if so, what
      ## exit status did it carry? Real Slurm 20.11.9's sbatch itself exits
      ## 255 for a bad #SBATCH directive (invalid --time, an unknown option,
      ## --mem-per-cpu or -N) -- the same value ssh uses for its own
      ## transport failures -- so the marker's own embedded status, not
      ## merely its presence, is what classify() needs to trust the remote
      ## side's answer over ssh's: a connection that drops between the
      ## remote side printing this line and ssh relaying its own exit code
      ## still leaves the marker (and the status in it) intact in stdout.
      $ended      = false;
      $remoteExit = null;

      if ( $nonce !== null
         && preg_match( '/' . preg_quote( self::FRAME_END . $nonce, '/' ) . ':(\d+)/', $joined, $m ) )
      {
         $ended      = true;
         $remoteExit = (int) $m[1];
      }

      $class = $this->classify( $exit_code, $stderr, strpos( $cmd, '/usr/bin/scp' ) === 0, $ended, $remoteExit );

      ## Single-line: $cmd is the framed, multi-line wrapped command, and
      ## $stderr can hold several lines of its own (ssh banners, the
      ## remote wrapper's output) -- either one otherwise breaks this one
      ## log entry across several lines in the log file.
      $this->logf( "$label attempt $attempt: exit=$exit_code class=$class cmd="
                   . str_replace( "\n", ' / ', $cmd )
                   . ( $stderr !== '' ? " stderr=" . str_replace( "\n", ' / ', $stderr ) : '' ) );

      $result = $this->result( $class, $exit_code, $stdout, $stderr, $cmd );
      $result[ 'began' ] = $began;

      return $result;
   }

   /**
    * Classify timeout codes, success, transport errors, then command
    * failures. $ended/$remoteExit come from the framed command's own end
    * marker (see once()): when present, $remoteExit is the remote
    * command's own exit status, trusted over $exit_code, which is ssh's --
    * ssh can still exit 255 if the connection drops after the remote side
    * already printed the marker and its status but before ssh relays its
    * own exit code.
    */
   public function classify( $exit_code, $stderr, $is_scp = false, $ended = false, $remoteExit = null )
   {
      if ( $exit_code === self::EXIT_TIMEOUT || $exit_code === self::EXIT_KILLED )
      {
         return self::TIMED_OUT;
      }

      $remoteExitKnown = $ended && $remoteExit !== null;

      if ( $remoteExitKnown )
      {
         $exit_code = $remoteExit;
      }

      if ( $exit_code === 0 )
      {
         return self::OK;
      }

      ## ssh reports its own failures as exit 255; a remote command's stderr (for
      ## example munge's "Connection refused") must not read as a transport fault.
      ## scp has no framing to confirm completion, so its own 255 (e.g. a local
      ## fork() failure under EAGAIN) is always transport-level, same as the
      ## exit-1 cases its own stderr patterns below catch.
      $transportFailed = $is_scp
                        ? ( $exit_code === self::EXIT_SSH_ERROR || $this->is_transport_error( $stderr ) )
                        : ( $exit_code === self::EXIT_SSH_ERROR && ! $remoteExitKnown );

      if ( $transportFailed )
      {
         return self::UNREACHABLE;
      }

      ## ssh succeeded but the scheduler behind it did not answer. That is an
      ## infrastructure fault, not a command result: nothing was learned about the
      ## job, and the cluster cannot take work. Classed as a command failure it
      ## recorded a breaker *success* throughout a controller outage, and on a host
      ## that reaches its own scheduler over ssh nothing else would ever fault.
      if ( ! $is_scp && $this->is_scheduler_unreachable( $stderr ) )
      {
         return self::UNREACHABLE;
      }

      return self::REMOTE_FAIL;
   }

   /**
    * Did the scheduler itself fail to answer? Separate from a job's own failure:
    * these are slurmctld being down, restarting or saturated, which no retry of
    * the command can turn into an answer about the job.
    */
   public function is_scheduler_unreachable( $stderr )
   {
      if ( (string) $stderr === '' )
      {
         return false;
      }

      $patterns = array(
         ## sbatch, squeue, sinfo and scancel all report the controller this way.
         'Unable to contact slurm ?controller',
         'Unable to contact slurm controller \(connect failure\)',
         ## The controller accepted the connection and then stopped answering.
         'Socket timed out on send/recv operation',
         'Zero Bytes were transmitted or received',
         ## slurmctld up but its database is not, so it cannot answer either.
         'Slurm temporarily unable to accept job',
      );

      foreach ( $patterns as $pattern )
      {
         if ( preg_match( '#' . $pattern . '#i', (string) $stderr ) )
         {
            return true;
         }
      }

      return false;
   }

   /**
    * A subset of is_scheduler_unreachable(): the whole sbatch invocation
    * definitely ended without creating a job, as opposed to a dropped or
    * timed-out connection where whether a job was created is genuinely
    * unknown. Safe for a caller to retry outright, unlike the ambiguous
    * cases, which need reconciling against the scheduler (e.g. a job-name
    * lookup) before resubmitting.
    *
    * sbatch answers EAGAIN by printing "Slurm temporarily unable to accept
    * job, sleeping and retrying" and then retrying internally on its own,
    * up to ~120s/15 more attempts -- that notice can appear in stderr from
    * an attempt sbatch went on to succeed at, so matching it anywhere in
    * stderr (the previous implementation) misclassifies a pending outcome
    * as a definite one. The only trustworthy verdict is sbatch's own final
    * "Batch job submission failed: <reason>" line, so only that line's
    * reason is checked.
    *
    * A dropped-connection phrase (lost mid-dialogue, so the controller's
    * actual answer to that attempt is unknown) overrides any final line:
    * even if sbatch went on to report failure afterward, an earlier attempt
    * within the same invocation may already have been accepted.
    *
    * Any "Batch job submission failed: <reason>" line is trusted as a
    * definite rejection, whatever the reason -- an invalid partition, a
    * full queue, a bad --time, EAGAIN, a connect failure, or anything else
    * sbatch's own last line names. A narrower whitelist of specific
    * reasons here previously meant real rejections (invalid partition, a
    * full queue, a bad --time) fell through to "outcome unknown: reconcile"
    * instead, even though sbatch had already given a final, definite
    * answer. The two dropped-connection phrases are excluded first because
    * they are genuinely ambiguous regardless of what line they appear on;
    * every other final line from sbatch itself is not.
    */
   public function is_scheduler_rejection( $stderr )
   {
      $stderr = (string) $stderr;

      if ( $stderr === '' )
      {
         return false;
      }

      $ambiguous = array(
         'Socket timed out on send/recv operation',
         'Zero Bytes were transmitted or received',
      );

      foreach ( $ambiguous as $pattern )
      {
         if ( preg_match( '#' . $pattern . '#i', $stderr ) )
         {
            return false;
         }
      }

      return (bool) preg_match( '/Batch job submission failed:\s*\S/i', $stderr );
   }

   /** Authentication and host-key failures are configuration errors: retrying cannot help. */
   public function is_permanent_failure( $stderr )
   {
      return (bool) preg_match(
         '/Permission denied \(publickey|Host key verification failed|Too many authentication failures/i',
         (string) $stderr );
   }

   /** Recognize transport failures, including SCP failures reported as exit 1. */
   public function is_transport_error( $stderr )
   {
      if ( $stderr === '' )
      {
         return false;
      }

      $patterns = array(
         'ssh_exchange_identification',
         'Connection timed out',
         'Operation timed out',
         'Connection refused',
         'Connection reset',
         'Connection closed by',
         'No route to host',
         'Network is unreachable',
         'Host is down',
         'Name or service not known',
         'Temporary failure in name resolution',
         'Could not resolve hostname',
         'Host key verification failed',
         'Permission denied \(publickey',
         'Too many authentication failures',
         'kex_exchange_identification',
         'Broken pipe',
         'Timeout, server .* not responding',
         'banner exchange',
         ## The multiplexed master died or could not be reached mid-call:
         ## scp then exits 1 with only one of these on stderr, which used to
         ## read as a remote command failure rather than a transport fault.
         ## Deliberately not matching mux_client_request_session or
         ## "ControlSocket ... already exists", which are fallback notices
         ## on calls that still succeed.
         'lost connection',
         'unix_listener',
         'ControlPath too long',
      );

      foreach ( $patterns as $p )
      {
         if ( preg_match( '/' . $p . '/i', $stderr ) )
         {
            return true;
         }
      }

      return false;
   }

   /** Non-interactive SSH with connection/keepalive limits and host-key checking. */
   private function sshOpts( $multiplex = false )
   {
      $connect = $this->operationTimeout(
         array(), 'connect_timeout_seconds', 'connect_timeout_seconds' );

      return '-p ' . (int) $this->port() . ' -n -x'
           . ' -o BatchMode=yes'
           . ' -o ConnectTimeout=' . (int) $connect
           . ' -o ServerAliveInterval=15'
           . ' -o ServerAliveCountMax=3'
           . ' -o StrictHostKeyChecking=' . $this->hostKeyPolicy()
           . $this->multiplexOpts( $multiplex );
   }

   ## scp takes the same options but spells the port -P, and must not be given -x.
   private function scpOpts( $multiplex = false )
   {
      $connect = $this->operationTimeout(
         array(), 'connect_timeout_seconds', 'connect_timeout_seconds' );

      return '-P ' . (int) $this->port()
           . ' -o BatchMode=yes'
           . ' -o ConnectTimeout=' . (int) $connect
           . ' -o ServerAliveInterval=15'
           . ' -o ServerAliveCountMax=3'
           . ' -o StrictHostKeyChecking=' . $this->hostKeyPolicy()
           . $this->multiplexOpts( $multiplex );
   }

   ## Shared by sshOpts() and scpOpts(): the same ControlPath for both means
   ## an scp that follows an ssh to the same login reuses its connection, and
   ## the other way round. Opt-in, not the default: without $multiplex, a
   ## caller like gridctl's probes, results fetch and queue viewer connects
   ## plainly, so a 60s ControlPersist on one of those does not keep a
   ## cluster's master open for as long as any job against it is running.
   ## Only submit_slurm's own ssh()/scp()/sbatchOnce()/confirmSlurmJob() --
   ## the calls actually staging, submitting and polling once per submission
   ## -- ask for it. '' (no directory
   ## available) omits the options entirely, so a host where the control
   ## directory cannot be created just connects plainly, exactly as before
   ## this existed.
   private function multiplexOpts( $multiplex )
   {
      if ( ! $multiplex || $this->muxUnusable )
      {
         return '';
      }

      $path = $this->controlPath();

      if ( $path === '' )
      {
         if ( ! $this->muxDirectoryWarned )
         {
            $this->muxDirectoryWarned = true;
            $this->logf( "multiplexing disabled for {$this->cluster}: no usable ssh control directory" );
         }

         return '';
      }

      return ' -o ControlMaster=auto -o ' . escapeshellarg( 'ControlPath=' . $path )
           . ' -o ControlPersist=' . self::CONTROL_PERSIST_SECONDS;
   }

   ## Called from the constructor, and again where the ssh options are built: the
   ## second call costs nothing and keeps the guarantee local to the thing it
   ## protects, so the policy cannot be weakened by a later change to $details.
   private function hostKeyPolicy()
   {
      ## Unknown host keys are rejected unless a cluster opts in (e.g. while provisioning).
      $value = $this->details[ 'ssh_host_key_policy' ] ?? 'yes';
      if ( ! in_array( $value, [ 'yes', 'accept-new' ], true ) )
      {
         throw new InvalidArgumentException(
            "remote_exec: cluster '{$this->cluster}' ssh_host_key_policy must be yes or accept-new" );
      }
      return $value;
   }

   /** Bound commands even when SSH stays connected; allow 10 seconds before SIGKILL. */
   private function withTimeout( $cmd, $seconds )
   {
      $bin = $this->timeout_bin();

      if ( $bin === '' || (int) $seconds <= 0 )
      {
         return $cmd;
      }

      return "$bin -k 10 " . (int) $seconds . " $cmd";
   }

   /** Locate GNU timeout; warn if calls must run without a wall-clock limit. */
   protected function timeout_bin()
   {
      if ( $this->timeout_bin !== null )
      {
         return $this->timeout_bin;
      }

      $candidates = array( '/usr/bin/timeout', '/bin/timeout' );

      foreach ( $candidates as $c )
      {
         if ( is_executable( $c ) )
         {
            $this->timeout_bin = $c;
            return $this->timeout_bin;
         }
      }

      $this->logf( 'WARNING: timeout(1) not found; remote calls run without a wall-clock bound' );
      $this->timeout_bin = '';

      return $this->timeout_bin;
   }

   /** Resolve a wall-clock budget: explicit call -> sparse cluster exception -> policy. */
   private function operationTimeout( $opts, $override_key, $policy_key )
   {
      if ( array_key_exists( 'timeout', $opts ) )
      {
         return $opts[ 'timeout' ];
      }

      if ( array_key_exists( $override_key, $this->overrides ) )
      {
         return $this->overrides[ $override_key ];
      }

      return $this->policy[ $policy_key ];
   }

   /** Validate internal policy overrides; production defaults are the constants above. */
   private function validatePolicy( $policy )
   {
      if ( ! is_array( $policy ) )
      {
         throw new InvalidArgumentException( 'remote_exec policy must be an array' );
      }

      $defaults = array(
         'connect_timeout_seconds' => self::CONNECT_TIMEOUT_SECONDS,
         'command_timeout_seconds' => self::COMMAND_TIMEOUT_SECONDS,
         'copy_timeout_seconds'    => self::COPY_TIMEOUT_SECONDS,
         'retries'                 => self::TRANSPORT_RETRIES,
         'retry_wait_seconds'      => self::RETRY_WAIT_SECONDS,
      );
      $limits = array(
         'connect_timeout_seconds' => array( 1, 300 ),
         'command_timeout_seconds' => array( 1, 3600 ),
         'copy_timeout_seconds'    => array( 1, 86400 ),
         'retries'                 => array( 0, 10 ),
         'retry_wait_seconds'      => array( 0, 300 ),
      );

      foreach ( $policy as $key => $value )
      {
         if ( ! isset( $limits[ $key ] ) )
         {
            throw new InvalidArgumentException( "remote_exec policy has unknown key '$key'" );
         }
         if ( ! is_int( $value ) || $value < $limits[ $key ][ 0 ] ||
              $value > $limits[ $key ][ 1 ] )
         {
            throw new InvalidArgumentException(
               "remote_exec policy '$key' must be an integer from "
               . $limits[ $key ][ 0 ] . ' through ' . $limits[ $key ][ 1 ] );
         }
      }

      return array_merge( $defaults, $policy );
   }

   /** Validate the one exception-only configuration surface. */
   private function validateClusterOverrides()
   {
      $legacy = array( 'ssh_connect_timeout', 'ssh_command_timeout',
                       'ssh_copy_timeout', 'ssh_retries', 'ssh_retry_wait' );
      foreach ( $legacy as $key )
      {
         if ( array_key_exists( $key, $this->details ) )
         {
            throw new InvalidArgumentException(
               "remote_exec: cluster '{$this->cluster}' uses removed key '$key'; "
               . "use remote_exec_overrides for a demonstrated timeout exception" );
         }
      }

      ## Validated here, with the other cluster-entry checks, rather than only
      ## where the ssh options are built: a typo used to surface as an exception
      ## from deep inside a poll, which ended a jobmonitor mid-job. The submit
      ## path already catches a constructor throw and reports a configuration
      ## failure, which is what should happen to a bad cluster entry.
      $this->hostKeyPolicy();

      if ( ! array_key_exists( 'remote_exec_overrides', $this->details ) )
      {
         return array();
      }

      $overrides = $this->details[ 'remote_exec_overrides' ];
      if ( ! is_array( $overrides ) || $overrides === array() )
      {
         throw new InvalidArgumentException(
            "remote_exec: cluster '{$this->cluster}' remote_exec_overrides must be a non-empty array" );
      }

      $limits = array(
         'connect_timeout_seconds' => array( 1, 300 ),
         'command_timeout_seconds' => array( 1, 3600 ),
         'copy_timeout_seconds'    => array( 1, 86400 ),
      );
      foreach ( $overrides as $key => $value )
      {
         if ( ! isset( $limits[ $key ] ) )
         {
            throw new InvalidArgumentException(
               "remote_exec: cluster '{$this->cluster}' has unknown remote_exec_overrides key '$key'" );
         }
         if ( ! is_int( $value ) || $value < $limits[ $key ][ 0 ] ||
              $value > $limits[ $key ][ 1 ] )
         {
            throw new InvalidArgumentException(
               "remote_exec: cluster '{$this->cluster}' override '$key' must be an integer from "
               . $limits[ $key ][ 0 ] . ' through ' . $limits[ $key ][ 1 ] );
         }
      }

      return $overrides;
   }

   private function result( $class, $exit_code, $stdout, $stderr, $cmd )
   {
      return array(
         'ok'        => $class === self::OK,
         'class'     => $class,
         'exit_code' => $exit_code,
         'stdout'    => $stdout,
         'text'      => trim( implode( "\n", $stdout ) ),
         'stderr'    => $stderr,
         'cmd'       => $cmd,
         'attempts'  => 1,
         'cluster'   => $this->cluster,
      );
   }

   private function logf( $msg )
   {
      call_user_func( $this->log, "[{$this->cluster}] $msg" );
   }

   /** Set an executor callable accepting ($cmd, &$output, &$exit_code). */
   public function set_executor( $fn )
   {
      $this->executor = $fn;
      return $this;
   }

   ## Seams. Overridden by the test double so the retry/classification logic can
   ## be exercised with no shell, no network, and no real sleeping.
   protected function runExec( $cmd, &$output, &$exit_code )
   {
      if ( is_callable( $this->executor ) )
      {
         $fn = $this->executor;
         $fn( $cmd, $output, $exit_code );
         return;
      }

      $output = array();
      exec( $cmd, $output, $exit_code );
   }

   protected function pause( $seconds )
   {
      sleep( $seconds );
   }
}

/** Whether a transport fault leaves remote state unknown. */
function remote_exec_infra_fault( $result )
{
   return isset( $result[ 'class' ] )
          && ( $result[ 'class' ] === remote_exec::UNREACHABLE
               || $result[ 'class' ] === remote_exec::TIMED_OUT );
}
