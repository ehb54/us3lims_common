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

   ## A submission makes three separate connections to the same login (stage,
   ## sbatch, and the ssh-launched monitor): one mkdir, one scp, one sbatch.
   ## Each paid full TCP+auth separately, which measured at ~2.7s/job. An SSH
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

      ## The command has to survive one extra round of shell parsing on the
      ## login node, so it is passed as a single quoted argument.
      $cmd = '/usr/bin/ssh ' . $this->sshOpts() . ' ' . escapeshellarg( $this->login() )
             . ' ' . escapeshellarg( $this->frame( $remote_cmd, $nonce ) );

      $result = $this->unframe(
         $this->attempt( $cmd, $timeout, $opts, isset( $opts['label'] ) ? $opts['label'] : 'run' ),
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
      return 'echo ' . self::FRAME_BEGIN . $nonce . "\n"
             . '{ ' . $remote_cmd . "\n" . '}' . "\n"
             . '__us3_rx_rc=$?' . "\n"
             . 'echo ' . self::FRAME_END . $nonce . "\n"
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

      $cmd = '/usr/bin/scp ' . $this->scpOpts()
             . ' ' . escapeshellarg( $this->login() . ':' . $remote_path )
             . ' ' . escapeshellarg( $local_dest );

      return $this->attempt( $cmd, $timeout, $opts, isset( $opts['label'] ) ? $opts['label'] : 'copy_from' );
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

      $cmd = '/usr/bin/scp ' . $this->scpOpts() . ' ' . $srcs
             . ' ' . escapeshellarg( $this->login() . ':' . $remote_dest );

      return $this->attempt( $cmd, $timeout, $opts, isset( $opts['label'] ) ? $opts['label'] : 'copy_to' );
   }

   /** Probe without retries or breaker gating; update the breaker with the result. */
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

      ## Feed the verdict back in by hand, since the bypass skipped the
      ## bookkeeping in attempt().
      $breaker = $this->breaker();

      if ( $breaker !== null )
      {
         if ( remote_exec_infra_fault( $res ) )
         {
            $breaker->record_failure( $this->cluster );
         }
         else
         {
            $breaker->record_success( $this->cluster );
         }
      }

      return $res;
   }

   ## ---------------------------------------------------------------- internals

   /** Retry transport faults with backoff, subject to the shared circuit breaker. */
   private function attempt( $cmd, $timeout, $opts, $label )
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

      if ( $breaker !== null && $breaker->is_open( $this->cluster ) )
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

      do
      {
         $attempt++;
         $result = $this->once( $wrapped, $cmd, $label, $attempt );

         if ( $result[ 'class' ] === self::OK || $result[ 'class' ] === self::REMOTE_FAIL
              || $attempt > $retries || $this->is_permanent_failure( $result[ 'stderr' ] ) )
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

   /**
    * The -o ControlPath value, or '' when the directory cannot be made
    * available: multiplexing is a speed-up, not a requirement, so a
    * permission problem here degrades to a plain connection per call rather
    * than failing the submission.
    */
   private function controlPath()
   {
      $dir = $GLOBALS[ 'global_ssh_control_dir' ] ?? self::default_control_dir();

      if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) )
      {
         return '';
      }

      ## %C: OpenSSH's own hash of local host + target host + port + user,
      ## so the socket path is always short regardless of hostname length,
      ## and shared between the ssh and scp calls that target the same login.
      return rtrim( $dir, '/' ) . '/cm-%C';
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

   ## Capture stderr separately so callers can parse stdout.
   private function once( $wrapped, $cmd, $label, $attempt )
   {
      $stderr_tmp = tempnam( sys_get_temp_dir(), 'us3rx_' );
      $stdout     = array();
      $exit_code  = 0;

      $this->runExec( "$wrapped 2>$stderr_tmp", $stdout, $exit_code );

      $stderr = is_readable( $stderr_tmp ) ? trim( file_get_contents( $stderr_tmp ) ) : '';
      @unlink( $stderr_tmp );

      $class = $this->classify( $exit_code, $stderr, strpos( $cmd, '/usr/bin/scp' ) === 0 );

      $this->logf( "$label attempt $attempt: exit=$exit_code class=$class cmd=$cmd"
                   . ( $stderr !== '' ? " stderr=$stderr" : '' ) );

      return $this->result( $class, $exit_code, $stdout, $stderr, $cmd );
   }

   /** Classify timeout codes, success, transport errors, then command failures. */
   public function classify( $exit_code, $stderr, $is_scp = false )
   {
      if ( $exit_code === self::EXIT_TIMEOUT || $exit_code === self::EXIT_KILLED )
      {
         return self::TIMED_OUT;
      }

      if ( $exit_code === 0 )
      {
         return self::OK;
      }

      ## ssh reports its own failures as exit 255; a remote command's stderr (for
      ## example munge's "Connection refused") must not read as a transport fault.
      ## scp reports transport failures as exit 1, so only scp needs the patterns.
      $transportFailed = $exit_code === self::EXIT_SSH_ERROR
                         || ( $is_scp && $this->is_transport_error( $stderr ) );

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
         'Slurmctld running but not accepting requests',
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
   private function sshOpts()
   {
      $connect = $this->operationTimeout(
         array(), 'connect_timeout_seconds', 'connect_timeout_seconds' );

      return '-p ' . (int) $this->port() . ' -n -x'
           . ' -o BatchMode=yes'
           . ' -o ConnectTimeout=' . (int) $connect
           . ' -o ServerAliveInterval=15'
           . ' -o ServerAliveCountMax=3'
           . ' -o StrictHostKeyChecking=' . $this->hostKeyPolicy()
           . $this->multiplexOpts();
   }

   ## scp takes the same options but spells the port -P, and must not be given -x.
   private function scpOpts()
   {
      $connect = $this->operationTimeout(
         array(), 'connect_timeout_seconds', 'connect_timeout_seconds' );

      return '-P ' . (int) $this->port()
           . ' -o BatchMode=yes'
           . ' -o ConnectTimeout=' . (int) $connect
           . ' -o ServerAliveInterval=15'
           . ' -o ServerAliveCountMax=3'
           . ' -o StrictHostKeyChecking=' . $this->hostKeyPolicy()
           . $this->multiplexOpts();
   }

   ## Shared by sshOpts() and scpOpts(): the same ControlPath for both means
   ## an scp that follows an ssh to the same login reuses its connection, and
   ## the other way round. '' (no directory available) omits the options
   ## entirely, so a host where the control directory cannot be created just
   ## connects plainly, exactly as before this existed.
   private function multiplexOpts()
   {
      $path = $this->controlPath();

      if ( $path === '' )
      {
         return '';
      }

      return ' -o ControlMaster=auto -o ControlPath=' . $path
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
