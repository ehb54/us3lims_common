<?php
/*
 * remote_exec.php
 *
 * The single place this codebase shells out to an HPC cluster.
 *
 * WHAT IT GUARANTEES
 *
 * Nothing else in this codebase may build an "exec( 'ssh ...' )" string. Three
 * properties hold only because every call goes through here:
 *
 *   1. Every invocation is hardened (BatchMode, ConnectTimeout, keepalives)
 *      AND wrapped in timeout(1), so no call can outlive its budget even when
 *      the remote side is blocked in the kernel and ignoring SIGTERM.
 *   2. Every invocation returns a CLASSIFIED result, so callers can tell an
 *      infrastructure fault from a real remote answer. Callers must branch on
 *      class(), never on exit code alone.
 *   3. Transient transport faults are retried with backoff, in one place.
 *      Genuine remote failures are NOT retried -- rerunning a command that
 *      really failed just multiplies the damage.
 *   4. Every invocation returns the remote command's OWN stdout and nothing
 *      else. stderr is captured separately, and stdout is fenced so a login
 *      node's ~/.bashrc cannot put its welcome banner where a caller is
 *      looking for a job id. See frame().
 *
 * USAGE (procedural callers included -- gridctl reaches this file through
 * $class_dir, the same way it already reaches global_config.php):
 *
 *   $rx  = new remote_exec( $cluster, $cluster_details );
 *   $res = $rx->run( "squeue -h -o %T -t all -j $jobid" );
 *   if ( $res['class'] === remote_exec::UNREACHABLE ) { ...leave state alone... }
 *
 * ONE TRANSPORT. Every cluster is reached over ssh/scp, a cluster running on
 * the same host as the LIMS included. Such a host needs us3 able to ssh to
 * itself.
 */

class remote_exec
{
   ## Operational policy. These are deliberately code-owned rather than
   ## deployment knobs: every site needs bounded calls and retry protection,
   ## while ordinary operators have no reason to tune the mechanism.
   const CONNECT_TIMEOUT_SECONDS = 15;
   const COMMAND_TIMEOUT_SECONDS = 120;
   const COPY_TIMEOUT_SECONDS    = 900;
   const TRANSPORT_RETRIES       = 3;
   const RETRY_WAIT_SECONDS      = 5;
   const BREAKER_FAILURES        = 3;
   const BREAKER_COOLDOWN_SECONDS = 120;

   ## Sole owner of the breaker directory default. $global_circuit_breaker_dir
   ## overrides it, but the template must not restate this value: a deployment
   ## that has not copied the current template would otherwise get a different
   ## directory than one that has. That is not cosmetic here. The breaker's
   ## whole purpose is cross-process memory, and under systemd PrivateTmp the
   ## web tier and the us3 cron/daemon user get separate /tmp, so a
   ## sys_get_temp_dir() default would give the process that trips the breaker
   ## and the process that reads it two unshared state directories.
   const BREAKER_DIR = '/var/tmp/us3-circuit-breaker';

   ## Result classifications. Callers branch on these, never on exit codes.

   ## The command ran and exited 0.
   const OK          = 'OK';

   ## The transport itself failed: connection refused/timed out/reset, DNS
   ## failure, host key rejection, or auth refusal. We learned NOTHING about
   ## the remote state. Never let this mutate job status.
   const UNREACHABLE = 'UNREACHABLE';

   ## The call exceeded its wall-clock budget and was killed. Typically an
   ## unresponsive filesystem on the remote side (the classic symptom: ssh
   ## connects fine, then squeue/ls/scp never returns). Also tells us nothing
   ## about remote state.
   const TIMED_OUT   = 'TIMED_OUT';

   ## The transport worked and the remote command ran and exited non-zero.
   ## This is a real answer from the cluster and is safe to act on.
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
      $this->policy  = $this->validate_policy( $policy );
      $this->overrides = $this->validate_cluster_overrides();
   }

   ## True when the cluster is not present in global_config.php at all. Callers
   ## should treat this as a configuration error, not an outage.
   public function is_configured()
   {
      return ! empty( $this->details ) && isset( $this->details[ 'name' ] );
   }

   ## user@host for ssh/scp. 'login' is already a user@host string when present.
   public function login()
   {
      if ( isset( $this->details[ 'login' ] ) )
         return $this->details[ 'login' ];

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
      $timeout = $this->operation_timeout(
         $opts, 'command_timeout_seconds', 'command_timeout_seconds' );

      $nonce = $this->frame_nonce();

      ## The command has to survive one extra round of shell parsing on the
      ## login node, so it is passed as a single quoted argument.
      $cmd = '/usr/bin/ssh ' . $this->ssh_opts() . ' ' . escapeshellarg( $this->login() )
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
    * Fence the command's own stdout so a talkative login node cannot be
    * mistaken for its output.
    *
    * THE PROBLEM. bash sources ~/.bashrc for a non-interactive command
    * arriving over ssh, and sourcing happens before our command runs. Anything
    * that file echoes lands on the same stdout, ahead of the output we asked
    * for. Sites do this routinely: a welcome banner, module-load chatter, a
    * "you have N jobs queued" notice, a conda or spack shell hook. sshd's own
    * Banner directive is not this: that goes to stderr, which once() already
    * keeps separate, and handling stderr is what makes this one easy to miss.
    *
    * There is no client-side way to prevent it. sshd runs the account's login
    * shell as `$SHELL -c command`, and bash sources ~/.bashrc when it is
    * non-interactive with a socket on stdin. ssh -q silences only the sshd
    * banner. On a cluster we do not administer, the profile is not ours to
    * fix. So the output is fenced instead, and only what lies between the
    * fences is given to the caller.
    *
    * The nonce is per call, so a marker cannot be produced by anything but
    * this invocation -- including a ~/.bashrc that someone copied out of these
    * comments.
    *
    * WHY THE EXIT CODE IS RE-RAISED. ssh exits with the remote command's
    * status, and classify() reads it. Without the trailing exit, the final
    * echo would be the last command in the payload and every call would come
    * back 0.
    *
    * ASSUMES A POSIX LOGIN SHELL for the cluster account (sh, bash, ksh,
    * zsh). csh and tcsh do not understand this payload; on such an account
    * every call fails immediately and visibly rather than subtly, which is the
    * right way for an unsupported configuration to present.
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
    * Keep only the stdout between the fences.
    *
    * WHEN THE FENCES ARE ABSENT the result is returned untouched. That is the
    * transport-fault case -- ssh failed, or the remote shell died, so nothing
    * of ours ever ran -- and there is nothing to slice. It is also what makes
    * this change inert for any caller that never reaches a real login node.
    *
    * A begin fence with no end fence means the command was killed part way
    * through, typically by the timeout wrapper. What arrived is still the
    * command's own output, so it is kept.
    *
    * The fences are located WITHIN a line rather than as whole lines, because
    * output need not end in a newline on either side of them: a ~/.bashrc
    * using `printf` or `echo -n` puts its text on the same line as the begin
    * fence, and a command whose last line is unterminated shares a line with
    * the end fence. In the first case the prefix is noise and is dropped with
    * the fence line; in the second it is the tail of the answer and is kept.
    */
   private function unframe( $result, $nonce )
   {
      if ( ! isset( $result[ 'stdout' ] ) || ! is_array( $result[ 'stdout' ] ) )
         return $result;

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
         return $result;

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
            $kept[] = substr( $lines[ $i ], 0, $at );

         break;
      }

      $result[ 'stdout' ] = $kept;
      $result[ 'text' ]   = trim( implode( "\n", $kept ) );

      return $result;
   }

   /** Unique per call, and cheap. Not a security boundary. */
   private function frame_nonce()
   {
      return bin2hex( random_bytes( 8 ) );
   }

   /**
    * Copy $remote_path (a path on the cluster) into the local $local_dest.
    */
   public function copy_from( $remote_path, $local_dest, $opts = array() )
   {
      $timeout = $this->operation_timeout(
         $opts, 'copy_timeout_seconds', 'copy_timeout_seconds' );

      $cmd = '/usr/bin/scp ' . $this->scp_opts()
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
      $timeout = $this->operation_timeout(
         $opts, 'copy_timeout_seconds', 'copy_timeout_seconds' );

      $paths = is_array( $local_paths ) ? $local_paths : array( $local_paths );
      $srcs  = implode( ' ', array_map( 'escapeshellarg', $paths ) );

      $cmd = '/usr/bin/scp ' . $this->scp_opts() . ' ' . $srcs
             . ' ' . escapeshellarg( $this->login() . ':' . $remote_dest );

      return $this->attempt( $cmd, $timeout, $opts, isset( $opts['label'] ) ? $opts['label'] : 'copy_to' );
   }

   /**
    * Cheap reachability probe: can we open an ssh session and get a command
    * back at all? Used by the cluster health probe and as a disambiguator when
    * a job status call fails -- if this succeeds, the cluster is up and the
    * earlier failure was about the job, not the transport.
    *
    * Deliberately unretried and short-budgeted: a health probe that retries is
    * a health probe that reports stale news.
    *
    * Also bypasses the circuit breaker. This is the call whose entire job is to
    * find out whether the cluster is back, so refusing it because the breaker
    * is open would make the breaker self-confirming: nothing would ever
    * discover recovery. Its result still feeds the breaker, so a successful
    * ping closes it for everyone.
    */
   public function ping( $opts = array() )
   {
      $timeout = $this->operation_timeout(
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
            $breaker->record_failure( $this->cluster );
         else
            $breaker->record_success( $this->cluster );
      }

      return $res;
   }

   ## ---------------------------------------------------------------- internals

   /**
    * Run one command with retry/backoff. Only UNREACHABLE and TIMED_OUT are
    * retried: a REMOTE_FAIL is a real answer from the cluster and repeating it
    * neither changes the answer nor is necessarily side-effect free (sbatch).
    *
    * Gated by the circuit breaker. Retrying is right for one call in isolation
    * and wrong in aggregate -- every job on a cluster has its own jobmonitor
    * process, so without shared state each of them independently rediscovers
    * the same outage at 4 attempts apiece. See circuit_breaker.php.
    */
   private function attempt( $cmd, $timeout, $opts, $label )
   {
      $retries = array_key_exists( 'retries', $opts )
               ? $opts[ 'retries' ] : $this->policy[ 'retries' ];
      $retry_wait = array_key_exists( 'retry_wait', $opts )
                  ? $opts[ 'retry_wait' ] : $this->policy[ 'retry_wait_seconds' ];

      ## 'breaker' => false opts out. Used by ping(): the health probe is the
      ## call that has to find out whether the cluster is back, so it must not
      ## be refused on the strength of the breaker's own record.
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

      $wrapped = $this->with_timeout( $cmd, $timeout );
      $attempt = 0;
      $secwait = $retry_wait;
      $result  = null;

      do
      {
         $attempt++;
         $result = $this->once( $wrapped, $cmd, $label, $attempt );

         if ( $result[ 'class' ] === self::OK || $result[ 'class' ] === self::REMOTE_FAIL )
            break;

         if ( $attempt > $retries )
            break;

         $this->logf( "$label: {$result['class']} on attempt $attempt, retrying in {$secwait}s" );
         $this->pause( $secwait );
         $secwait *= 2;
      }
      while ( true );

      ## Both OK and REMOTE_FAIL prove the transport works, so both close the
      ## breaker. Only a transport fault counts against the cluster.
      if ( $breaker !== null )
      {
         if ( remote_exec_infra_fault( $result ) )
            $breaker->record_failure( $this->cluster );
         else
            $breaker->record_success( $this->cluster );
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

   /** Returns null when no breaker is configured or available. */
   private function breaker()
   {
      if ( $this->breaker !== null )
         return $this->breaker === false ? null : $this->breaker;

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
         $GLOBALS[ 'global_circuit_breaker_dir' ]              ?? self::BREAKER_DIR,
         self::BREAKER_FAILURES,
         self::BREAKER_COOLDOWN_SECONDS,
         $this->log
      );

      return $this->breaker;
   }

   ## One invocation. Stdout and stderr are captured separately (stderr via a
   ## temp file) so transport noise never contaminates the value a caller is
   ## trying to parse out of stdout -- the bug that made get_local_status()
   ## read field 5 of an "ssh: connect to host ..." error as a job state.
   private function once( $wrapped, $cmd, $label, $attempt )
   {
      $stderr_tmp = tempnam( sys_get_temp_dir(), 'us3rx_' );
      $stdout     = array();
      $exit_code  = 0;

      $this->runExec( "$wrapped 2>$stderr_tmp", $stdout, $exit_code );

      $stderr = is_readable( $stderr_tmp ) ? trim( file_get_contents( $stderr_tmp ) ) : '';
      @unlink( $stderr_tmp );

      $class = $this->classify( $exit_code, $stderr );

      $this->logf( "$label attempt $attempt: exit=$exit_code class=$class cmd=$cmd"
                   . ( $stderr !== '' ? " stderr=$stderr" : '' ) );

      return $this->result( $class, $exit_code, $stdout, $stderr, $cmd );
   }

   /**
    * Decide what a given exit code and stderr actually mean.
    *
    * Order matters. timeout(1)'s codes are checked first because a killed ssh
    * may also have written partial transport errors to stderr; then ssh's
    * reserved 255; then the stderr patterns, which are the only signal scp
    * gives (scp reports transport failures with a plain exit 1, the same code
    * it uses for "no such file").
    */
   public function classify( $exit_code, $stderr )
   {
      if ( $exit_code === self::EXIT_TIMEOUT || $exit_code === self::EXIT_KILLED )
         return self::TIMED_OUT;

      if ( $exit_code === 0 )
         return self::OK;

      if ( $this->is_transport_error( $stderr ) )
         return self::UNREACHABLE;

      ## ssh reserves 255 for its own errors. A remote command exiting 255 is
      ## possible but vanishingly rare next to a transport failure, and the
      ## safe misread is "infrastructure problem, change nothing".
      if ( $exit_code === self::EXIT_SSH_ERROR )
         return self::UNREACHABLE;

      return self::REMOTE_FAIL;
   }

   /**
    * stderr patterns that mean the transport failed, not the remote command.
    *
    * ssh_exchange_identification in particular is what a login node under
    * connection-rate limiting returns, and is the single most common transient
    * fault against large shared clusters -- the old code recognised it but its
    * retry loop slept without ever re-running the command, so the retry never
    * happened.
    */
   public function is_transport_error( $stderr )
   {
      if ( $stderr === '' )
         return false;

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
         if ( preg_match( '/' . $p . '/i', $stderr ) )
            return true;

      return false;
   }

   /**
    * Hardened ssh options.
    *
    * BatchMode          never block on a password/passphrase prompt. Without
    *                    it a key problem turns into an indefinite stall on
    *                    stdin rather than a clean, classifiable failure.
    * ConnectTimeout     bound the TCP/handshake phase. This is what a dropped
    *                    (as opposed to refused) packet needs: without it the
    *                    kernel's SYN retry schedule governs, which is minutes.
    * ServerAlive*       bound an ESTABLISHED-but-dead session, the state a
    *                    firewall change or a wedged login node leaves behind.
    *                    3 x 15s, so a dead session is torn down in ~45s.
    * StrictHostKeyChecking=accept-new
    *                    trust on first use, but still refuse a CHANGED key.
    *                    'no' would accept a swapped host key silently.
    *                    A provisioned cluster can require preinstalled trust
    *                    with ssh_host_key_policy='yes'; SSH and SCP agree.
    */
   private function ssh_opts()
   {
      $connect = $this->operation_timeout(
         array(), 'connect_timeout_seconds', 'connect_timeout_seconds' );

      return '-p ' . (int) $this->port() . ' -x'
           . ' -o BatchMode=yes'
           . ' -o ConnectTimeout=' . (int) $connect
           . ' -o ServerAliveInterval=15'
           . ' -o ServerAliveCountMax=3'
           . ' -o StrictHostKeyChecking=' . $this->host_key_policy();
   }

   ## scp takes the same options but spells the port -P, and must not be given -x.
   private function scp_opts()
   {
      $connect = $this->operation_timeout(
         array(), 'connect_timeout_seconds', 'connect_timeout_seconds' );

      return '-P ' . (int) $this->port()
           . ' -o BatchMode=yes'
           . ' -o ConnectTimeout=' . (int) $connect
           . ' -o ServerAliveInterval=15'
           . ' -o ServerAliveCountMax=3'
           . ' -o StrictHostKeyChecking=' . $this->host_key_policy();
   }

   private function host_key_policy()
   {
      $value = $this->details[ 'ssh_host_key_policy' ] ?? 'accept-new';
      if ( ! in_array( $value, [ 'yes', 'accept-new' ], true ) )
         throw new InvalidArgumentException( 'ssh_host_key_policy must be yes or accept-new' );
      return $value;
   }

   /**
    * Wrap in timeout(1).
    *
    * ssh's own ConnectTimeout/ServerAlive options cannot save us once the
    * session is established and the remote command is blocked in
    * uninterruptible I/O on an unresponsive filesystem: the connection is
    * healthy, the keepalives are answered, and the command simply never
    * returns. Only an external wall-clock kill bounds that case.
    *
    * --kill-after sends SIGKILL 10s after the SIGTERM, since a process wedged
    * in D state will not act on SIGTERM either.
    */
   private function with_timeout( $cmd, $seconds )
   {
      $bin = $this->timeout_bin();

      if ( $bin === '' || (int) $seconds <= 0 )
         return $cmd;

      return "$bin -k 10 " . (int) $seconds . " $cmd";
   }

   /**
    * Locate GNU timeout(1). This is a Linux production runtime requirement
    * supplied by the coreutils package; it is not a CMake/build dependency.
    *
    * If none is found the command still runs, but WITHOUT a wall-clock bound,
    * which is exactly the failure mode this class exists to prevent. That is a
    * deployment defect, so it is logged on every call rather than once, and
    * cluster_status.php reports it as a health finding.
    */
   protected function timeout_bin()
   {
      if ( $this->timeout_bin !== null )
         return $this->timeout_bin;

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
   private function operation_timeout( $opts, $override_key, $policy_key )
   {
      if ( array_key_exists( 'timeout', $opts ) )
         return $opts[ 'timeout' ];

      if ( array_key_exists( $override_key, $this->overrides ) )
         return $this->overrides[ $override_key ];

      return $this->policy[ $policy_key ];
   }

   /**
    * Test/internal policy injection. Production callers omit this argument and
    * receive the constants above; keeping this seam out of deployment config
    * prevents test controls from becoming public knobs.
    */
   private function validate_policy( $policy )
   {
      if ( ! is_array( $policy ) )
         throw new InvalidArgumentException( 'remote_exec policy must be an array' );

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
            throw new InvalidArgumentException( "remote_exec policy has unknown key '$key'" );
         if ( ! is_int( $value ) || $value < $limits[ $key ][ 0 ] ||
              $value > $limits[ $key ][ 1 ] )
            throw new InvalidArgumentException(
               "remote_exec policy '$key' must be an integer from "
               . $limits[ $key ][ 0 ] . ' through ' . $limits[ $key ][ 1 ] );
      }

      return array_merge( $defaults, $policy );
   }

   /** Validate the one exception-only configuration surface. */
   private function validate_cluster_overrides()
   {
      $legacy = array( 'ssh_connect_timeout', 'ssh_command_timeout',
                       'ssh_copy_timeout', 'ssh_retries', 'ssh_retry_wait' );
      foreach ( $legacy as $key )
         if ( array_key_exists( $key, $this->details ) )
            throw new InvalidArgumentException(
               "remote_exec: cluster '{$this->cluster}' uses removed key '$key'; "
               . "use remote_exec_overrides for a demonstrated timeout exception" );

      if ( ! array_key_exists( 'remote_exec_overrides', $this->details ) )
         return array();

      $overrides = $this->details[ 'remote_exec_overrides' ];
      if ( ! is_array( $overrides ) || $overrides === array() )
         throw new InvalidArgumentException(
            "remote_exec: cluster '{$this->cluster}' remote_exec_overrides must be a non-empty array" );

      $limits = array(
         'connect_timeout_seconds' => array( 1, 300 ),
         'command_timeout_seconds' => array( 1, 3600 ),
         'copy_timeout_seconds'    => array( 1, 86400 ),
      );
      foreach ( $overrides as $key => $value )
      {
         if ( ! isset( $limits[ $key ] ) )
            throw new InvalidArgumentException(
               "remote_exec: cluster '{$this->cluster}' has unknown remote_exec_overrides key '$key'" );
         if ( ! is_int( $value ) || $value < $limits[ $key ][ 0 ] ||
              $value > $limits[ $key ][ 1 ] )
            throw new InvalidArgumentException(
               "remote_exec: cluster '{$this->cluster}' override '$key' must be an integer from "
               . $limits[ $key ][ 0 ] . ' through ' . $limits[ $key ][ 1 ] );
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

   /**
    * Delegate actual command execution to the caller's own exec seam.
    *
    * submit_slurm already exposes a protected runExec() that its test double
    * scripts; routing this class through that same seam means moving
    * submit_slurm onto remote_exec does not invalidate the submission tests,
    * and a single scripted response queue still covers the whole call chain.
    *
    * The callable takes ( $cmd, &$output, &$exit_code ).
    */
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

/**
 * True when a result means "we learned nothing about the remote side".
 *
 * Provided as a free function because the procedural gridctl code checks this
 * constantly and `remote_exec::UNREACHABLE` scattered through it reads worse
 * than one named predicate.
 */
function remote_exec_infra_fault( $result )
{
   return isset( $result[ 'class' ] )
          && ( $result[ 'class' ] === remote_exec::UNREACHABLE
               || $result[ 'class' ] === remote_exec::TIMED_OUT );
}
