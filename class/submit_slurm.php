<?php
/* Submit Slurm jobs through SSH and SCP, including co-located clusters. */
require_once $class_dir . 'jobsubmit.php';
require_once $class_dir . 'remote_exec.php';
include_once $class_dir . 'priority.php';

function elog2( $msg )
{
   ## Path comes from $elog2_path in global_config.php.
   global $elog2_path;
   $path = isset( $elog2_path ) ? $elog2_path : '/home/us3/lims/etc/elog2.txt';
   error_log( "$msg\n", 3, $path );
}

elog2( "submit_slurm start" );

class submit_slurm extends jobsubmit
{
   ## Set by stage_files() when staging fails, so submit() can persist a
   ## specific reason rather than a generic "submission aborted".
   protected $stage_error = '';

   ## Top-level: stage files then submit
   public function submit()
   {
      if ( ! isset( $this->data[ 'job' ][ 'cluster_shortname' ] ) )
      {
         $this->message[] = "ERROR: data profile is not defined. Return to Queue Setup.\n";
         return;
      }

      $savedir = getcwd();
      try
      {
         chdir( $this->data[ 'job' ][ 'directory' ] );

         if ( ! $this->stage_files() ) {
            ## Persist staging failure for autoflow requests.
            $this->message[] = "ERROR: stage_files failed - submission aborted";
            $this->markAutoflowSubmitFailed( $this->stage_error !== '' ? $this->stage_error : 'staging failed' );
            return;
         }

         $this->submit_job();

         ## Only write DB records and launch jobmonitor if we have a valid Slurm job ID.
         ## A missing ID means sbatch failed; the error is already in $this->message.
         if ( ! empty( $this->data[ 'eprfile' ] ) ) {
            ## Report success only after recording the job and launching its monitor.
            if ( $this->update_db() )
            {
               $this->message[] = "submit complete";
            }
         } else {
            $this->message[] = "ERROR: submit_job failed — no valid Slurm job ID; DB not updated";
         }
      }
      catch ( InvalidArgumentException | UnexpectedValueException $error )
      {
         if ( empty( $this->data[ 'eprfile' ] ) )
         {
            $this->message[] = "ERROR: submission configuration invalid: " . $error->getMessage();
            $this->markAutoflowSubmitFailed( $error->getMessage() );
         }
         else
         {
            $this->message[] = "WARNING: job {$this->data['eprfile']} was accepted, but submission"
                             . " setup failed: " . $error->getMessage();
         }
      }
      finally
      {
         chdir( $savedir );
      }
   }

   /**
    * Create the remote directory, write the script and copy the input files.
    * Staging operations are idempotent and may retry transport faults.
    * Return false on failure; the caller persists the error.
    */
   public function stage_files()
   {
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $login     = $this->remote( $cluster )->login();
      $workdir   = $this->workdir( $cluster, $requestID );
      $tarfile   = $this->tarfile();

      $this->message[] = "stage_files: cluster=$cluster login=$login workdir=$workdir";

      ## Generate (and check) the slurm script locally before touching the cluster
      $slufile = $this->write_slurm_script( $cluster, $requestID, $workdir, $tarfile );
      if ( $slufile === false ) {
         ## The specific reason is already in message[]; this is the
         ## autoflow failure category.
         $this->stage_error = 'resource plan rejected';
         return false;
      }

      ## Create the working directory on the submithost
      $mk = $this->ssh( $cluster, "/bin/mkdir -p $workdir", [ 'label' => 'stage:mkdir' ] );
      if ( ! $mk[ 'ok' ] ) {
         $this->stage_error = $this->stageFailure( 'mkdir', $workdir, $mk );
         return false;
      }

      ## Copy input tar and slurm script to the working directory.
      $cp = $this->scp( $cluster, [ $tarfile, $slufile ], $workdir, [ 'label' => 'stage:copy' ] );
      if ( ! $cp[ 'ok' ] ) {
         $this->stage_error = $this->stageFailure( 'copy', "$tarfile $slufile", $cp );
      }

      return $cp[ 'ok' ];
   }

   /** Describe whether staging failed through transport loss or remote rejection. */
   private function stageFailure( $step, $subject, $res )
   {
      $infra  = remote_exec_infra_fault( $res );
      $detail = $res[ 'stderr' ] !== '' ? $res[ 'stderr' ] : $res[ 'text' ];

      $msg = $infra
           ? "cluster unreachable during staging ($step): {$res['class']} after {$res['attempts']} attempt(s): $detail"
           : "staging $step rejected by cluster: $detail";

      $this->message[] = "ERROR: stage_files: $msg [$subject]";
      elog2( "stage_files: $msg [$subject]" );

      return $msg;
   }

   ## Generate the Slurm batch script and write it to disk; return filename
   public function write_slurm_script( $cluster, $requestID, $workdir, $tarfile )
   {
      elog2( "write_slurm_script: cluster=$cluster queue=" . $this->grid[ $cluster ][ 'queue' ] );

      ## Do not recompute any of the plan's quantities here.
      $plan = $this->resource_plan();
      if ( $plan === false )
      {
         return false;
      }

      $cfg         = $this->grid[ $cluster ];
      $quename     = $cfg[ 'queue' ];
      $mgroupcount = $plan[ 'resolved_groups' ];
      $nodes       = $plan[ 'node_count' ];
      $ranks       = $plan[ 'total_tasks' ];
      $tasks_per_node = $plan[ 'tasks_per_node' ];

      ## Resolve wall time
      list( $walltime, $wallmins ) = $this->resolveWalltime( $cfg );

      ## Build environment setup lines from config
      $env_lines = $this->buildEnvLines( $cfg );

      ## Entries are complete, single-line #SBATCH directives.
      $directives = (array)( $cfg[ 'sbatch_directives' ] ?? [] );

      ## Fall back to mempercore when no batch directives are configured.
      if ( ! $directives  &&  isset( $cfg[ 'mempercore' ] ) )
      {
         $directives = [ '#SBATCH --mem-per-cpu=' . (int) $cfg[ 'mempercore' ] ];
      }

      $site_directives = '';
      foreach ( $directives as $directive )
      {
         if ( ! is_string( $directive )
              || strpos( $directive, "\n" ) !== false
              || strpos( $directive, "\r" ) !== false
              || strpos( $directive, '#SBATCH ' ) !== 0 )
         {
            throw new UnexpectedValueException( "invalid sbatch directive for cluster $cluster" );
         }

         $site_directives .= "$directive\n";
      }

      $priority_nice = priority_nice_string();
      if ( strlen( $priority_nice ) )
      {
         $this->message[] = "Priority set " . str_replace( "\n", "; ", $priority_nice );
      }

      ## ibrun reads SLURM_NTASKS; mpirun and srun receive an explicit rank count.
      $launcher = $cfg[ 'mpi_launcher' ] ?? 'mpirun';

      ## OMPI_MCA_btl is OpenMPI-specific; suppress for srun/ibrun launchers
      $ompi_mca = ( $launcher === 'mpirun' )
         ? "export OMPI_MCA_btl=vader,self,tcp\n"
         : "";

      ## Build the mpirun / srun / ibrun invocation line
      if ( $launcher === 'ibrun' ) {
         ## ibrun: task count comes from SLURM_NTASKS (#SBATCH -n), no -n flag
         $launch_cmd = "ibrun us_mpi_analysis -walltime $wallmins"
                     . " -mgroupcount $mgroupcount $tarfile";
      } elseif ( $launcher === 'srun' ) {
         ## srun: task count also from SLURM env, but explicit -n is harmless and clear
         $launch_cmd = "srun -n $ranks us_mpi_analysis -walltime $wallmins"
                     . " -mgroupcount $mgroupcount $tarfile";
      } else {
         ## mpirun: explicit -n required
         $launch_cmd = "mpirun -n $ranks us_mpi_analysis -walltime $wallmins"
                     . " -mgroupcount $mgroupcount $tarfile";
      }

      $script =
         "#!/bin/bash\n"
         . "#SBATCH -p $quename\n"
         . "#SBATCH -J US3_Job_$requestID\n"
         . "#SBATCH -N $nodes\n"
         . "#SBATCH -n $ranks\n"
         . "#SBATCH --ntasks-per-node=$tasks_per_node\n"
         . "#SBATCH -t $walltime\n"
         . "#SBATCH -e $workdir/stderr\n"
         . "#SBATCH -o $workdir/stdout\n"
         . $site_directives
         . ( $priority_nice   ? "$priority_nice\n"   : "" )
         . $env_lines
         . "export UCX_LOG_LEVEL=error\n"
         . $ompi_mca
         . "export QT_LOGGING_RULES='*.debug=true'\n\n"
         . "cd $workdir\n\n"
         . "$launch_cmd\n";

      ## Held for update_db(), which writes it to HPCAnalysisResult.jobfile.
      $this->data[ 'jobfile' ] = $script;

      $filename = "us3.slurm";
      file_put_contents( $filename, $script );
      return $filename;
   }

   ## SSH sbatch --parsable exactly once and capture the Slurm job ID.
   ##
   ## sbatch is not idempotent. If SSH drops after Slurm accepted the job but
   ## before the reply reaches LIMS, a second sbatch can create a duplicate.
   ## Without a durable scheduler-side receipt there is no safe automatic
   ## retry, so an uncertain outcome is surfaced for operator reconciliation.
   ## Sets $this->data['eprfile'] only when that one call returns a valid ID.
   public function submit_job()
   {
      date_default_timezone_set( "America/Chicago" );

      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $workdir   = $this->workdir( $cluster, $requestID );

      $submitResult = $this->attemptSubmit( $cluster, $workdir );

      if ( ! $submitResult[ 'submit_ok' ] ) {
         $this->data[ 'eprfile' ] = '';
         $this->message[] = "ERROR: job submission failed after " . $submitResult[ 'attempt' ]
                           . " attempt(s): " . $submitResult[ 'error' ];
         elog2( "submit_job: FAILED after " . $submitResult[ 'attempt' ] . " attempts: " . $submitResult[ 'error' ] );
         $this->markAutoflowSubmitFailed( $submitResult[ 'error' ] );
         return;
      }

      $slurm_job_id = $submitResult[ 'job_id' ];

      ## Best-effort scontrol check; does not gate submission (see below).
      $this->confirmSlurmJob( $cluster, $slurm_job_id );

      $this->data[ 'eprfile' ] = $slurm_job_id;
      elog2( "submit_job: slurm_job_id=$slurm_job_id confirmed after " . $submitResult[ 'attempt' ] . " attempt(s)" );
   }

   ## Run sbatch once via SSH and validate its result. A failure is deliberately
   ## not retried: the remote command may have reached Slurm even when its job
   ## ID did not make it back across SSH.
   private function attemptSubmit( $cluster, $workdir )
   {
      ## A retry is safe only when sbatch provably never started (the command's
      ## begin marker never came back, e.g. a connect failure or an open breaker).
      $retries = (int) ( $this->grid[ $cluster ][ 'submit_retries' ]
                         ?? $GLOBALS[ 'global_sbatch_submit_retries' ] ?? 3 );
      $wait    = (int) ( $this->grid[ $cluster ][ 'submit_retry_wait' ]
                         ?? $GLOBALS[ 'global_sbatch_submit_retry_wait_seconds' ] ?? 5 );

      for ( $attempt = 1; ; $attempt++ )
      {
         $result = $this->sbatchOnce( $cluster, $workdir, $attempt );

         if ( $result[ 'ok' ] ) {
            return array( 'submit_ok' => true, 'job_id' => $result[ 'job_id' ], 'error' => '', 'attempt' => $attempt );
         }

         ## Unreachable with no begin marker: ssh never ran the command.
         $never_started = ( $result[ 'class' ] ?? '' ) === remote_exec::UNREACHABLE && empty( $result[ 'began' ] );
         if ( ! $never_started || $attempt > $retries ) {
            break;
         }

         $this->pause( $wait );
         $wait *= 2;
      }

      $error = $never_started
             ? $result[ 'error' ] . "; sbatch never started, so nothing was submitted"
             : $result[ 'error' ] . "; sbatch started but its result was lost, so the submission"
               . " outcome is unknown: reconcile on the cluster before resubmitting";

      return array( 'submit_ok' => false, 'job_id' => '', 'error' => $error, 'attempt' => $attempt );
   }

   protected function pause( $seconds )
   {
      sleep( $seconds );
   }

   ## Run sbatch --parsable once via SSH and validate the result.
   ## Stdout and stderr are kept separate so --parsable's stdout is never
   ## contaminated by SSH warnings or sbatch error text.
   ## Returns ['ok' => bool, 'job_id' => string, 'error' => string].
   private function sbatchOnce( $cluster, $workdir, $attempt )
   {
      $sbatch_cmd = "sbatch --parsable --get-user-env $workdir/us3.slurm";

      ## retries => 0 deliberately. Neither remote_exec nor submit_slurm may
      ## repeat this non-idempotent operation without first reconciling it.
      $res = $this->remote( $cluster )->run( $sbatch_cmd, [
         'retries' => 0,
         'label'   => "sbatch attempt $attempt",
      ] );

      $stdout_lines = $res[ 'stdout' ];
      $stdout_text  = $res[ 'text' ];
      $stderr_text  = $res[ 'stderr' ];
      $exit_code    = $res[ 'exit_code' ];

      ## Always log full diagnostics
      $this->message[] = "sbatchOnce (attempt $attempt): cmd={$res['cmd']}";
      $this->message[] = "sbatchOnce (attempt $attempt): exit=$exit_code class={$res['class']}";
      $this->message[] = "sbatchOnce (attempt $attempt): stdout=$stdout_text";
      if ( $stderr_text !== '' )
      {
         $this->message[] = "sbatchOnce (attempt $attempt): stderr=$stderr_text";
      }

      elog2( "sbatchOnce (attempt $attempt): exit=$exit_code class={$res['class']} stdout=$stdout_text stderr=$stderr_text" );

      ## Require a successful command before trusting its job ID.
      if ( ! $res[ 'ok' ] ) {
         $detail = $stderr_text !== '' ? $stderr_text : $stdout_text;
         $error  = remote_exec_infra_fault( $res )
                 ? "cluster unreachable ({$res['class']}): $detail"
                 : "sbatch exited $exit_code: $detail";

         return array( 'ok' => false, 'job_id' => '', 'error' => $error, 'class' => $res[ 'class' ],
                       'began' => ! empty( $res[ 'began' ] ) );
      }

      ## Parse --parsable output: "12345" or "12345;clustername"
      $job_id = $this->parseParsableSbatchOutput( $stdout_lines );

      if ( $job_id === '' ) {
         ## parse method already appended a specific error to $this->message
         return array( 'ok' => false, 'job_id' => '', 'error' => "invalid sbatch output: $stdout_text" );
      }

      return array( 'ok' => true, 'job_id' => $job_id, 'error' => '' );
   }

   ## Mark autoflow submission failed when no valid job ID was received.
   protected function markAutoflowSubmitFailed( $statusMsg )
   {
      global $dbusername, $dbpasswd, $dbhost, $dbname;
      global $ID, $is_cli;

      $autoflowID = ( $is_cli && $ID ) ? $ID : 0;
      if ( $autoflowID <= 0 )
      {
         return;
      }

      $link = mysqli_connect( $dbhost, $dbusername, $dbpasswd, $dbname );
      if ( ! $link ) {
         $this->message[] = "markAutoflowSubmitFailed: cannot connect to $dbhost:$dbname";
         return;
      }

      $qfmsg = mysqli_real_escape_string( $link, $statusMsg );
      $query = "UPDATE autoflowAnalysis SET "
             . "status='SUBMIT_TIMEOUT', "
             . "statusMsg='Job submission failed: $qfmsg' "
             . "WHERE requestID='$autoflowID'";
      $result = mysqli_query( $link, $query );
      if ( ! $result )
      {
         $this->message[] = "markAutoflowSubmitFailed: invalid query: $query " . mysqli_error( $link );
      }

      mysqli_close( $link );
   }

   ## Record the job in both databases and launch jobmonitor.
   ## After sbatch accepted the job, problems are WARNING: (the job is running);
   ## ERROR: is kept for submissions that did not happen.
   public function update_db()
   {
      $ok = true;

      global $globaldbuser, $globaldbpasswd, $globaldbhost, $globaldbname;
      global $dbusername, $dbpasswd, $dbhost, $dbname;
      global $ID, $is_cli;

      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $slurm_id  = $this->data[ 'eprfile' ];
      $autoflowID = ( $is_cli && $ID ) ? $ID : 0;

      ## Write to instance DB
      $link = mysqli_connect( $dbhost, $dbusername, $dbpasswd, $dbname );
      if ( ! $link ) {
         $this->message[] = "WARNING: job {$this->data['eprfile']}: cannot connect to $dbhost:$dbname - job is running but unrecorded";
         return false;
      }

      $jobfile = mysqli_real_escape_string( $link, $this->data[ 'jobfile' ] );
      $query = "INSERT INTO HPCAnalysisResult SET "
             . "HPCAnalysisRequestID='$requestID', "
             . "jobfile='$jobfile', "
             . "gfacID='$slurm_id'";
      $result = mysqli_query( $link, $query );
      if ( ! $result ) {
         $this->message[] = "WARNING: job {$this->data['eprfile']}: HPCAnalysisResult insert failed - job is running but "
                          . "unrecorded: " . mysqli_error( $link );
         mysqli_close( $link );
         return false;
      }

      if ( $autoflowID > 0 ) {
         $query = "UPDATE autoflowAnalysis SET "
                . "currentGfacID='$slurm_id', "
                . "currentHPCARID='$requestID', "
                . "status='SUBMITTED', "
                . "statusMsg='Job submitted' "
                . "WHERE requestID='$autoflowID'";
         $result = mysqli_query( $link, $query );
         if ( ! $result ) {
            $this->message[] = "WARNING: job {$this->data['eprfile']}: autoflowAnalysis update failed - the pipeline will not "
                             . "advance: " . mysqli_error( $link );
            $ok = false;
         }
      }

      mysqli_close( $link );

      ## Write to global gfac DB (job tracking)
      $gfac_link = mysqli_connect( $globaldbhost, $globaldbuser, $globaldbpasswd, $globaldbname );
      if ( ! $gfac_link ) {
         $this->message[] = "WARNING: job {$this->data['eprfile']}: cannot connect to global DB $globaldbhost:$globaldbname - "
                          . "job will not be tracked";
         return false;
      }

      $query = "INSERT INTO analysis SET "
             . "gfacID='$slurm_id', "
             . "autoflowAnalysisID='$autoflowID', "
             . "cluster='$cluster', "
             . "us3_db='$dbname'";
      $result = mysqli_query( $gfac_link, $query );
      if ( ! $result ) {
         $this->message[] = "WARNING: job {$this->data['eprfile']}: gfac.analysis insert failed - job will not be tracked or "
                          . "cleaned up: " . mysqli_error( $gfac_link );
         $ok = false;
      }

      mysqli_close( $gfac_link );

      $this->message[] = "DB updated: requestID=$requestID slurm_id=$slurm_id";

      ## Run the monitor as us3; use sudo only when PHP runs under another account.
      $monitor_host = getenv( 'US3_JOBMONITOR_SSH_HOST' );

      if ( $monitor_host !== false && $monitor_host !== '' ) {
         ## The deployment's own LIMS host, never the job's compute cluster.
         $res = $this->launch_monitor_over_ssh( $monitor_host, $dbname, $slurm_id, $requestID );
         $exit_code = $res[ 'class' ] === remote_exec::OK ? 0 : 1;
         if ( $exit_code !== 0 )
         {
            $this->message[] = "WARNING: job {$this->data['eprfile']}: monitor transport failed: " . $res[ 'class' ];
         }
      } else {
         $php     = escapeshellarg( $this->monitor_php_binary() );
         $monitor = "/home/us3/lims/bin/jobmonitor/jobmonitor.php";
         $args    = "$dbname $slurm_id $requestID";

         $whoami  = function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' )
                    ? ( posix_getpwuid( posix_geteuid() )[ 'name' ] ?? '' )
                    : '';

         if ( $whoami === 'us3' || $whoami === '' )
         {
            $cmd = "nice -15 $php $monitor $args 2>&1";
         }
         else
         {
            ## NOPASSWD rules match sudo's direct command; keep PHP there and wrap sudo with nice.
            $cmd = "nice -15 sudo -u us3 /usr/bin/php $monitor $args 2>&1";
         }

         exec( $cmd, $null, $exit_code );
      }

      if ( $exit_code !== 0 ) {
         ## A failed monitor launch leaves the recorded job without monitoring.
         $this->message[] = "WARNING: job {$this->data['eprfile']}: jobmonitor launch failed (exit=$exit_code) - job will not "
                          . "be monitored";
         $ok = false;
      } else {
         $this->message[] = "jobmonitor launch: exit=$exit_code";
      }

      return $ok;
   }

   public function close_transport() { /* no-op: no persistent transport */ }

   ## PHP_BINARY may name php-fpm; use the CLI binary for the monitor.
   protected function monitor_php_binary( $sapi = PHP_SAPI, $binary = PHP_BINARY,
                                          $bindir = PHP_BINDIR )
   {
      return $sapi === 'cli' && $binary !== '' ? $binary : $bindir . '/php';
   }

   ## Launch through the LIMS host when the web pool cannot spawn the monitor.
   ## The monitor must daemonize and close its streams before SSH returns.
   ## The host key must be installed during provisioning.
   protected function launch_monitor_over_ssh( $host, $dbname, $jobID, $requestID )
   {
      if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $host ) )
      {
         throw new InvalidArgumentException( 'Invalid US3_JOBMONITOR_SSH_HOST' );
      }
      $rx = new remote_exec( 'lims-jobmonitor', [
         'lims-jobmonitor' => [ 'name' => $host, 'login' => 'us3@' . $host, 'sshport' => 22,
                               'ssh_host_key_policy' => 'yes' ],
      ], 'elog2' );
      $rx->set_executor( function ( $cmd, &$output, &$exit_code ) {
         $this->runExec( $cmd, $output, $exit_code );
      } );
      $cmd = '/usr/bin/php /home/us3/lims/bin/jobmonitor/jobmonitor.php '
           . escapeshellarg( $dbname ) . ' ' . escapeshellarg( (string) $jobID )
           . ' ' . escapeshellarg( (string) $requestID );
      ## A lost response may follow a successful launch. Do not launch twice.
      return $rx->run( $cmd, [ 'retries' => 0 ] );
   }

   ## -------------------------------------------------------------------------
   ## Private helpers
   ## -------------------------------------------------------------------------

   ## Build the remote working directory path for this request
   private function workdir( $cluster, $requestID )
   {
      $jobid = $this->data[ 'db' ][ 'name' ] . sprintf( "-%06d", $requestID );
      return rtrim( $this->grid[ $cluster ][ 'workdir' ], '/' ) . '/' . $jobid;
   }

   ## Build the input tar filename for this request
   private function tarfile()
   {
      return sprintf( "hpcinput-%s-%s-%05d.tar",
         $this->data[ 'db' ][ 'host' ],
         $this->data[ 'db' ][ 'name' ],
         $this->data[ 'job' ][ 'requestID' ] );
   }


   /** Build the cluster transport using this object's executor. */
   protected function remote( $cluster )
   {
      $rx = new remote_exec( $cluster, $this->grid, 'elog2' );

      return $rx->set_executor( function ( $cmd, &$output, &$exit_code ) {
         $this->runExec( $cmd, $output, $exit_code );
      } );
   }

   ## Run a command on the cluster. Returns remote_exec's classified result.
   private function ssh( $cluster, $remote_cmd, $opts = [] )
   {
      $res = $this->remote( $cluster )->run( $remote_cmd, $opts );
      $this->recordRemote( 'exec', $res );
      return $res;
   }

   ## Copy staged files to the cluster over SCP.
   private function scp( $cluster, $files, $dest, $opts = [] )
   {
      $res = $this->remote( $cluster )->copy_to( $files, $dest, $opts );
      $this->recordRemote( 'copy', $res );
      return $res;
   }

   ## Append transport diagnostics to the submission messages.
   private function recordRemote( $label, $res )
   {
      $error = '';
      if ( ! $res[ 'ok' ] ) {
         $detail = $res[ 'stderr' ] !== '' ? $res[ 'stderr' ] : $res[ 'text' ];
         $error = "  err=$detail";
      }
      $this->message[] = "$label: {$res['cmd']}  exit={$res['exit_code']}"
                       . "  class={$res['class']}  attempts={$res['attempts']}" . $error;
   }

   ## Overridable executor for shell commands.
   protected function runExec( $cmd, &$output, &$exit_code )
   {
      $output = [];
      exec( $cmd, $output, $exit_code );
   }

   ## Parse sbatch --parsable stdout lines.
   ## Valid forms: "12345"  or  "12345;clustername"
   ## Returns the numeric job ID string on success, empty string on any failure.
   private function parseParsableSbatchOutput( $stdout_lines )
   {
      ## --parsable emits exactly one line; use the first non-empty line
      $line = '';
      foreach ( $stdout_lines as $l ) {
         $l = trim( $l );
         if ( $l !== '' ) { $line = $l; break; }
      }

      if ( $line === '' ) {
         $this->message[] = "ERROR: sbatch --parsable returned empty stdout";
         return '';
      }

      ## Strip optional cluster suffix: "12345;clustername" → "12345"
      $job_id_str = explode( ';', $line )[0];

      ## Validate: must be purely numeric and non-zero
      if ( ! preg_match( '/^\d+$/', $job_id_str ) || (int)$job_id_str === 0 ) {
         $this->message[] = "ERROR: sbatch --parsable output is not a valid job ID: '$line'";
         return '';
      }

      return $job_id_str;
   }

   ## Confirm the submitted job is visible to Slurm via scontrol show job.
   ## This is a best-effort check: a lookup failure is logged but does not
   ## abort submission — the ID came from a successful --parsable response.
   private function confirmSlurmJob( $cluster, $slurm_job_id )
   {
      ## Skip retries for this optional check to avoid delaying submission.
      $res = $this->remote( $cluster )->run( "scontrol show job $slurm_job_id", [
         'retries' => 0,
         'label'   => 'scontrol confirm',
      ] );

      if ( $res[ 'ok' ] ) {
         $this->message[] = "submit_job: scontrol confirmed job $slurm_job_id exists";
         elog2( "submit_job: scontrol confirmed job $slurm_job_id" );
         return;
      }

      $detail = $res[ 'stderr' ] !== '' ? $res[ 'stderr' ] : $res[ 'text' ];
      $this->message[] = "WARNING: scontrol show job $slurm_job_id failed"
                       . " (exit={$res['exit_code']} class={$res['class']}): $detail";
      elog2( "submit_job: scontrol check failed for job $slurm_job_id class={$res['class']}: $detail" );
      ## Not fatal: --parsable already gave us a valid ID, and a transport
      ## fault here says nothing about whether the job was accepted.
   }

   ## Walltime precedence: usemaxtime, wall_override, then maxwall() * 3.
   ## Clamp estimates and overrides to maxtime; zero means unlimited.
   private function resolveWalltime( $cfg )
   {
      ## usemaxtime: skip computed estimate, use the configured cluster maximum
      if ( ! empty( $cfg[ 'usemaxtime' ] ) ) {
         $max_time = (int) $cfg[ 'maxtime' ];
         if ( $max_time === 0 )
         {
            return [ "00:00:00", 999999 ];
         }  ## maxtime=0 means no limit
         $hours    = (int)( $max_time / 60 );
         $mins     = (int)( $max_time % 60 );
         return [ sprintf( "%02d:%02d:00", $hours, $mins ), $max_time ];
      }

      $wall = $this->maxwall() * 3.0;

      if ( ! empty( $cfg[ 'wall_override' ] ) )
      {
         $wall = (float) $cfg[ 'wall_override' ];
      }

      ## Clamp to the cluster's queue limit; maxtime = 0 means unlimited
      $max_time = isset( $cfg[ 'maxtime' ] ) ? (int) $cfg[ 'maxtime' ] : 0;
      if ( $max_time > 0  &&  $wall > $max_time ) {
         $this->message[] = "NOTE: walltime "
                          . (int)$wall
                          . " min exceeds cluster maxtime $max_time min; clamped to $max_time";
         $wall = (float) $max_time;
      }

      $hours    = (int)( $wall / 60 );
      $mins     = (int)( $wall % 60 );
      $walltime = sprintf( "%02d:%02d:00", $hours, $mins );
      $wallmins = $hours * 60 + $mins;

      return [ $walltime, $wallmins ];
   }

   ## Return the environment setup block for the Slurm script.
   ## Reads env_script_lines directly from cluster config.
   ## Ensures the block is separated from the surrounding script with a trailing newline.
   private function buildEnvLines( $cfg )
   {
      $block = trim( $cfg[ 'env_script_lines' ] ?? '' );
      if ( $block === '' )
      {
         ## Without it the job starts with no modules or MPI and fails at runtime.
         throw new UnexpectedValueException( "cluster has no env_script_lines in global_config.php;"
            . " run php ~us3/lims/database/utils/uslims_upgrade.php" );
      }
      return "\n" . $block . "\n\n";
   }

}
