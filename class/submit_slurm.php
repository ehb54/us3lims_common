<?php
/*
 * submit_slurm.php
 *
 * Submits an analysis job to a Slurm cluster.
 *
 * Remote clusters use: ssh mkdir → scp → ssh sbatch.
 *
 * A cluster whose global_config.php entry sets 'localhost' => true runs every
 * one of those steps directly instead — mkdir, cp, sbatch, scontrol — with no
 * ssh or scp in the path.
 *
 * Going through ssh on a single-node appliance would require generating a
 * loopback keypair for us3 and granting it shell access, which the appliance's
 * sshd deliberately withholds (AllowGroups usiab-admins usiab-users, and us3
 * is in neither). Native submission avoids opening that hole.
 *
 * Caveat: under ssh, every command arrived as the 'login' user regardless of
 * who invoked PHP. Natively it runs as the invoking user — us3 for the
 * submitone.php/submitctl.php autoflow path, apache for a direct web-UI
 * submission. The workdir must therefore be writable by both.
 *
 */
require_once $class_dir . 'jobsubmit.php';
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
   ## Top-level: stage files then submit
   public function submit()
   {
      if ( ! isset( $this->data[ 'job' ][ 'cluster_shortname' ] ) )
      {
         $this->message[] = "Data profile is not defined. Return to Queue Setup.\n";
         return;
      }

      $savedir = getcwd();
      chdir( $this->data[ 'job' ][ 'directory' ] );

      if ( ! $this->stage_files() ) {
         $this->message[] = "ERROR: stage_files failed — submission aborted";
         chdir( $savedir );
         return;
      }

      $this->submit_job();

      ## Only write DB records and launch jobmonitor if we have a valid Slurm job ID.
      ## A missing ID means sbatch failed; the error is already in $this->message.
      if ( ! empty( $this->data[ 'eprfile' ] ) ) {
         $this->update_db();
         $this->message[] = "submit complete";
      } else {
         $this->message[] = "ERROR: submit_job failed — no valid Slurm job ID; DB not updated";
      }

      chdir( $savedir );
   }

   ## Create the working directory (ssh or local), write the slurm script
   ## locally, then copy both files to the submithost (scp or local cp).
   ## Returns true on success, false if any step fails (error already in $this->message).
   public function stage_files()
   {
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $login     = $this->login( $cluster );
      $port      = $this->grid[ $cluster ][ 'sshport' ];
      $workdir   = $this->workdir( $cluster, $requestID );
      $tarfile   = $this->tarfile();

      $local     = $this->is_local( $cluster );

      $this->message[] = "stage_files: cluster=$cluster login=$login workdir=$workdir"
                       . ( $local ? " (local submission, no ssh)" : '' );

      ## Create the working directory on the submithost
      if ( $this->ssh( $cluster, $port, $login, "/bin/mkdir -p $workdir" ) !== 0 ) {
         $this->message[] = "ERROR: stage_files: mkdir failed for $workdir";
         return false;
      }

      ## Generate slurm script locally
      $slufile = $this->write_slurm_script( $cluster, $requestID, $workdir, $tarfile );

      ## Copy input tar and slurm script to the working directory. For a local
      ## cluster the destination is a plain path; scp() turns the copy into cp.
      $dest = $local ? $workdir : "$login:$workdir";
      if ( $this->scp( $cluster, $port, "$tarfile $slufile", $dest ) !== 0 ) {
         $this->message[] = "ERROR: stage_files: copy failed (tarfile=$tarfile slufile=$slufile)";
         return false;
      }

      return true;
   }

   ## Generate the Slurm batch script and write it to disk; return filename
   public function write_slurm_script( $cluster, $requestID, $workdir, $tarfile )
   {
      elog2( "write_slurm_script: cluster=$cluster queue=" . $this->grid[ $cluster ][ 'queue' ] );

      ## Compute parallel group count and node count FIRST. nodes() rewrites
      ## grid[ppn]/[ppbj]/[maxproc] in place for fixed-capacity clusters, so
      ## every cluster value below has to be read after it has run -- reading
      ## them first silently emits the pre-sizing ppbj into #SBATCH -n.
      $mgroupcount = $this->resolve_mgroupcount();
      $nodes       = $this->nodes() * $mgroupcount;
      $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount;

      $cfg     = $this->grid[ $cluster ];
      $quename = $cfg[ 'queue' ];
      $ppbj    = $cfg[ 'ppbj' ];
      $ppmg    = (int) ( $cfg[ 'procs_per_mgroup' ] ?? 16 );

      ## For GA analysis, double procs-per-base-job if under one model group
      if ( preg_match( "/GA/", $this->data[ 'method' ] ) && $ppbj < $ppmg )
         $ppbj *= 2;

      ## single_node: confine the job to one node. The total rank count
      ## (#SBATCH -n, below) is $ppbj either way, so collapsing the node count
      ## is the whole of the transformation -- there is no per-node figure in
      ## the emitted script to recompute.
      if ( ! empty( $cfg[ 'single_node' ] ) )
         $nodes = 1;

      ## Resolve wall time
      list( $walltime, $wallmins ) = $this->resolve_walltime( $cfg );

      ## Build environment setup lines from config
      $env_lines = $this->build_env_lines( $cfg );

      ## Optional sbatch directives
      $mempercore_line = isset( $cfg[ 'mempercore' ] )
         ? "#SBATCH --mem-per-cpu=" . $cfg[ 'mempercore' ]
         : "";

      $priority_nice = priority_nice_string();
      if ( strlen( $priority_nice ) )
         $this->message[] = "Priority set " . str_replace( "\n", "; ", $priority_nice );

      ## Per-cluster MPI launcher: 'mpirun' (default), 'srun', or 'ibrun'.
      ## ibrun: TACC clusters — reads SLURM_NTASKS, no -n argument.
      ## srun:  Anvil, Expanse — Slurm-native, reads SLURM_NTASKS.
      ## mpirun: all others — explicit -n argument needed.
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
      } else if ( $launcher === 'srun' ) {
         ## srun: task count also from SLURM env, but explicit -n is harmless and clear
         $launch_cmd = "srun -n $ppbj us_mpi_analysis -walltime $wallmins"
                     . " -mgroupcount $mgroupcount $tarfile";
      } else {
         ## mpirun: explicit -n required
         $launch_cmd = "mpirun -n $ppbj us_mpi_analysis -walltime $wallmins"
                     . " -mgroupcount $mgroupcount $tarfile";
      }

      $script =
         "#!/bin/bash\n"
         . "#SBATCH -p $quename\n"
         . "#SBATCH -J US3_Job_$requestID\n"
         . "#SBATCH -N $nodes\n"
         . "#SBATCH -n $ppbj\n"
         . "#SBATCH -t $walltime\n"
         . "#SBATCH -e $workdir/stderr\n"
         . "#SBATCH -o $workdir/stdout\n"
         . ( $mempercore_line ? "$mempercore_line\n" : "" )
         . ( $priority_nice   ? "$priority_nice\n"   : "" )
         . $env_lines
         . "export UCX_LOG_LEVEL=error\n"
         . $ompi_mca
         . "export QT_LOGGING_RULES='*.debug=true'\n\n"
         . "cd $workdir\n\n"
         . "$launch_cmd\n";

      $this->data[ 'pbsfile' ] = $script;   ## stored for update_db()

      $filename = "us3.slurm";
      file_put_contents( $filename, $script );
      return $filename;
   }

   ## SSH sbatch --parsable and capture the Slurm job ID.
   ## Retries with exponential backoff on transient failures (e.g. sbatch
   ## "Socket timed out" under scheduler load); configurable via
   ## $global_sbatch_submit_retries / $global_sbatch_submit_retry_wait_seconds
   ## in global_config.php, overridable per-cluster via 'submit_retries' /
   ## 'submit_retry_wait'.
   ## Sets $this->data['eprfile'] only on confirmed success; leaves it empty
   ## and marks the autoflow request SUBMIT_TIMEOUT on exhausted retries.
   public function submit_job()
   {
      global $global_sbatch_submit_retries;
      global $global_sbatch_submit_retry_wait_seconds;

      ## set defaults if not defined in global_config.php
      if ( ! isset( $global_sbatch_submit_retries ) )
         $global_sbatch_submit_retries = 3;
      if ( ! isset( $global_sbatch_submit_retry_wait_seconds ) )
         $global_sbatch_submit_retry_wait_seconds = 5;

      date_default_timezone_set( "America/Chicago" );

      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $login     = $this->login( $cluster );
      $port      = $this->grid[ $cluster ][ 'sshport' ];
      $workdir   = $this->workdir( $cluster, $requestID );

      ## allow per-cluster override of submit retry/backoff
      $submit_retries    = $this->grid[ $cluster ][ 'submit_retries' ]
                          ?? $global_sbatch_submit_retries;
      $submit_retry_wait = $this->grid[ $cluster ][ 'submit_retry_wait' ]
                          ?? $global_sbatch_submit_retry_wait_seconds;

      $submitResult = $this->attemptSubmit( $cluster, $port, $login, $workdir, $submit_retries, $submit_retry_wait );

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
      $this->confirm_slurm_job( $cluster, $port, $login, $slurm_job_id );

      $this->data[ 'eprfile' ] = $slurm_job_id;
      elog2( "submit_job: slurm_job_id=$slurm_job_id confirmed after " . $submitResult[ 'attempt' ] . " attempt(s)" );
   }

   ## Run sbatch once via SSH, validate its result, and retry with
   ## exponential backoff on failure. Returns an array describing the
   ## outcome of the last attempt: submit_ok, job_id, error, attempt.
   private function attemptSubmit( $cluster, $port, $login, $workdir, $submit_retries, $secwait )
   {
      $attempt = 0;
      $error   = '';

      do
      {
         $attempt++;
         $result = $this->sbatchOnce( $cluster, $port, $login, $workdir, $attempt );

         if ( $result[ 'ok' ] ) {
            return array(
               'submit_ok' => true,
               'job_id'    => $result[ 'job_id' ],
               'error'     => '',
               'attempt'   => $attempt,
            );
         }

         $error = $result[ 'error' ];

         if ( $attempt <= $submit_retries ) {
            elog2( "submit_job: submit failed (attempt $attempt), retrying in {$secwait}s: $error" );
            sleep( $secwait );
            $secwait *= 2;
         }
      } while ( $attempt <= $submit_retries );

      return array(
         'submit_ok' => false,
         'job_id'    => '',
         'error'     => $error,
         'attempt'   => $attempt,
      );
   }

   ## Run sbatch --parsable once via SSH and validate the result.
   ## Stdout and stderr are captured into fresh arrays/temp files on every
   ## call, so a retry never sees stale output carried over from a previous
   ## attempt (exec() appends to an existing array rather than replacing it).
   ## Stdout/stderr are also kept separate so --parsable's stdout is never
   ## contaminated by SSH warnings or sbatch error text.
   ## Returns ['ok' => bool, 'job_id' => string, 'error' => string].
   private function sbatchOnce( $cluster, $port, $login, $workdir, $attempt )
   {
      $stderr_tmp = tempnam( sys_get_temp_dir(), 'us3sbatch_' );
      $sbatch_cmd = "sbatch --parsable --get-user-env $workdir/us3.slurm";

      ## The remote form single-quotes $sbatch_cmd so ssh passes it as one
      ## argument to the login shell; run locally that quoting would make the
      ## whole string a single command name, so drop it.
      $cmd = $this->is_local( $cluster )
           ? "$sbatch_cmd 2>$stderr_tmp"
           : "/usr/bin/ssh -p $port -x $login '$sbatch_cmd' 2>$stderr_tmp";

      elog2( "sbatchOnce cmd: $cmd (attempt $attempt)" );

      $stdout_lines = [];
      $this->runExec( $cmd, $stdout_lines, $exit_code );

      $stderr_text = is_readable( $stderr_tmp ) ? trim( file_get_contents( $stderr_tmp ) ) : '';
      @unlink( $stderr_tmp );

      $stdout_text = implode( "\n", $stdout_lines );

      ## Always log full diagnostics
      $this->message[] = "sbatchOnce (attempt $attempt): cmd=$cmd";
      $this->message[] = "sbatchOnce (attempt $attempt): exit=$exit_code";
      $this->message[] = "sbatchOnce (attempt $attempt): stdout=$stdout_text";
      if ( $stderr_text !== '' )
         $this->message[] = "sbatchOnce (attempt $attempt): stderr=$stderr_text";

      elog2( "sbatchOnce (attempt $attempt): exit=$exit_code stdout=$stdout_text stderr=$stderr_text" );

      ## Gate: nonzero exit means sbatch itself (or the ssh transport) reported failure
      if ( $exit_code !== 0 ) {
         return array( 'ok' => false, 'job_id' => '',
            'error' => "sbatch exited $exit_code: " . ( $stderr_text !== '' ? $stderr_text : $stdout_text ) );
      }

      ## Parse --parsable output: "12345" or "12345;clustername"
      $job_id = $this->parse_parsable_sbatch_output( $stdout_lines );

      if ( $job_id === '' ) {
         ## parse method already appended a specific error to $this->message
         return array( 'ok' => false, 'job_id' => '', 'error' => "invalid sbatch output: $stdout_text" );
      }

      return array( 'ok' => true, 'job_id' => $job_id, 'error' => '' );
   }

   ## Mark an autoflow request as failed when job submission could not
   ## obtain a real job ID, after exhausting retries, so it isn't left
   ## tracked with a bogus/empty gfacID or watched by a jobmonitor for a
   ## job that was never actually submitted.
   private function markAutoflowSubmitFailed( $statusMsg )
   {
      global $dbusername, $dbpasswd, $dbhost, $dbname;
      global $ID, $is_cli;

      $autoflowID = ( $is_cli && $ID ) ? $ID : 0;
      if ( $autoflowID <= 0 )
         return;

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
         $this->message[] = "markAutoflowSubmitFailed: invalid query: $query " . mysqli_error( $link );

      mysqli_close( $link );
   }

   ## Write submission record to instance DB and global gfac DB, then launch jobmonitor
   public function update_db()
   {
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
         $this->message[] = "Cannot connect to $dbhost:$dbname";
         return;
      }

      $jobfile = mysqli_real_escape_string( $link, $this->data[ 'pbsfile' ] );
      $query = "INSERT INTO HPCAnalysisResult SET "
             . "HPCAnalysisRequestID='$requestID', "
             . "jobfile='$jobfile', "
             . "gfacID='$slurm_id'";
      $result = mysqli_query( $link, $query );
      if ( ! $result ) {
         $this->message[] = "DB insert failed: " . mysqli_error( $link );
         mysqli_close( $link );
         return;
      }

      if ( $autoflowID > 0 ) {
         $query = "UPDATE autoflowAnalysis SET "
                . "currentGfacID='$slurm_id', "
                . "currentHPCARID='$requestID', "
                . "status='SUBMITTED', "
                . "statusMsg='Job submitted' "
                . "WHERE requestID='$autoflowID'";
         $result = mysqli_query( $link, $query );
         if ( ! $result )
            $this->message[] = "autoflow update failed: " . mysqli_error( $link );
      }

      mysqli_close( $link );

      ## Write to global gfac DB (job tracking)
      $gfac_link = mysqli_connect( $globaldbhost, $globaldbuser, $globaldbpasswd, $globaldbname );
      if ( ! $gfac_link ) {
         $this->message[] = "Cannot connect to global DB $globaldbhost:$globaldbname";
         return;
      }

      $query = "INSERT INTO analysis SET "
             . "gfacID='$slurm_id', "
             . "autoflowAnalysisID='$autoflowID', "
             . "cluster='$cluster', "
             . "us3_db='$dbname'";
      $result = mysqli_query( $gfac_link, $query );
      if ( ! $result )
         $this->message[] = "gfac insert failed: " . mysqli_error( $gfac_link );

      mysqli_close( $gfac_link );

      $this->message[] = "DB updated: requestID=$requestID slurm_id=$slurm_id";

      ## Launch per-job monitor daemon.
      ##
      ## Do not add an unconditional `sudo -u us3` here. Production runs the
      ## whole web tier as us3 (httpd.conf "User us3", php-fpm "user = us3")
      ## and the playbooks put us3 in no sudoers file, so a sudo hop fails
      ## there with "us3 is not in the sudoers file" and the monitor never
      ## starts. Sudo is only for a deployment whose web tier runs as some
      ## other user.
      $php     = PHP_BINARY ?: '/usr/bin/php';
      $monitor = "/home/us3/lims/bin/jobmonitor/jobmonitor.php";
      $args    = "$dbname $slurm_id $requestID";

      $whoami  = function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' )
                 ? ( posix_getpwuid( posix_geteuid() )[ 'name' ] ?? '' )
                 : '';

      if ( $whoami === 'us3' || $whoami === '' )
         $cmd = "nice -15 $php $monitor $args 2>&1";
      else
         ## `nice` must wrap `sudo`, not the reverse: a NOPASSWD rule matches
         ## sudo's direct target command, so `sudo -u us3 nice ... php` would
         ## make nice the target and fail to match. Niceness is inherited
         ## across exec(), so the outer process has the same effect.
         $cmd = "nice -15 sudo -u us3 /usr/bin/php $monitor $args 2>&1";

      exec( $cmd, $null, $exit_code );
      $this->message[] = "jobmonitor launch: exit=$exit_code";
   }

   public function close_transport() { /* no-op: no persistent transport */ }

   ## -------------------------------------------------------------------------
   ## Private helpers
   ## -------------------------------------------------------------------------

   ## Resolve the login target: 'login' key if set, otherwise 'name'
   private function login( $cluster )
   {
      $cfg = $this->grid[ $cluster ];
      return $cfg[ 'login' ] ?? $cfg[ 'name' ];
   }

   ## Build the remote working directory path for this request
   private function workdir( $cluster, $requestID )
   {
      $jobid = $this->data[ 'db' ][ 'name' ] . sprintf( "-%06d", $requestID );
      return $this->grid[ $cluster ][ 'workdir' ] . $jobid;
   }

   ## Build the input tar filename for this request
   private function tarfile()
   {
      return sprintf( "hpcinput-%s-%s-%05d.tar",
         $this->data[ 'db' ][ 'host' ],
         $this->data[ 'db' ][ 'name' ],
         $this->data[ 'job' ][ 'requestID' ] );
   }

   ## True when this cluster runs on the same host as the LIMS stack, i.e. its
   ## global_config.php entry sets 'localhost' => true. Such a cluster is
   ## driven with plain local commands rather than ssh/scp. Absent or false
   ## means ssh/scp, as with any remote cluster.
   private function is_local( $cluster )
   {
      return ! empty( $this->grid[ $cluster ][ 'localhost' ] );
   }

   ## Run a command on the submithost; log and return exit code.
   ## Local clusters execute it directly, remote ones via SSH.
   private function ssh( $cluster, $port, $login, $remote_cmd )
   {
      $cmd = $this->is_local( $cluster )
           ? "$remote_cmd 2>&1"
           : "/usr/bin/ssh -p $port -x $login $remote_cmd 2>&1";

      $output = [];
      $this->runExec( $cmd, $output, $exit_code );
      $this->message[] = "exec: $cmd  exit=$exit_code"
                       . ( $exit_code !== 0 ? "  out=" . ( $output[0] ?? '' ) : '' );
      return $exit_code;
   }

   ## Copy the staged files into the working directory. Remote clusters use
   ## scp; local ones use cp, since source and destination are the same
   ## filesystem and $dest is already a bare path (see stage_files).
   private function scp( $cluster, $port, $files, $dest )
   {
      $cmd = $this->is_local( $cluster )
           ? "/bin/cp $files $dest 2>&1"
           : "/usr/bin/scp -P $port $files $dest 2>&1";

      $output = [];
      $this->runExec( $cmd, $output, $exit_code );
      $this->message[] = "copy: $cmd  exit=$exit_code"
                       . ( $exit_code !== 0 ? "  out=" . ( $output[0] ?? '' ) : '' );
      return $exit_code;
   }

   ## Run a shell command, capturing output and exit code. Thin wrapper
   ## around exec() so tests can substitute a scripted fake (no real shell,
   ## SSH, or network call) by overriding this single method in a subclass.
   protected function runExec( $cmd, &$output, &$exit_code )
   {
      $output = [];
      exec( $cmd, $output, $exit_code );
   }

   ## Parse sbatch --parsable stdout lines.
   ## Valid forms: "12345"  or  "12345;clustername"
   ## Returns the numeric job ID string on success, empty string on any failure.
   private function parse_parsable_sbatch_output( $stdout_lines )
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
   private function confirm_slurm_job( $cluster, $port, $login, $slurm_job_id )
   {
      $scontrol_cmd = "scontrol show job $slurm_job_id";
      $cmd = $this->is_local( $cluster )
           ? "$scontrol_cmd 2>&1"
           : "/usr/bin/ssh -p $port -x $login $scontrol_cmd 2>&1";

      $output = [];
      $this->runExec( $cmd, $output, $exit_code );

      if ( $exit_code === 0 ) {
         $this->message[] = "submit_job: scontrol confirmed job $slurm_job_id exists";
         elog2( "submit_job: scontrol confirmed job $slurm_job_id" );
      } else {
         $detail = trim( implode( ' ', $output ) );
         $this->message[] = "WARNING: scontrol show job $slurm_job_id failed (exit=$exit_code): $detail";
         elog2( "submit_job: scontrol check failed for job $slurm_job_id exit=$exit_code: $detail" );
         ## Not fatal: --parsable already gave us a valid ID
      }
   }

   ## Resolve wall time from config; return [ "HH:MM:SS", minutes_int ]
   ##
   ## Precedence:
   ##   1. usemaxtime: true  → use the cluster's configured maxtime
   ##                          (maxtime = 0 means unlimited → 00:00:00 / Slurm no-limit)
   ##   2. wall_override > 0 → use that fixed value in minutes
   ##   3. otherwise         → use the computed estimate from maxwall()
   ##
   ## In cases 2 and 3 the result is clamped to the cluster's configured
   ## maxtime (when maxtime > 0). Without the clamp a long estimate -- or a
   ## wall_override left over from a cluster whose queue limit has since been
   ## lowered -- is handed to sbatch unchanged and the scheduler rejects the
   ## job outright, which surfaces to the user as a submission failure rather
   ## than a job that runs up to the queue limit. maxtime = 0 means the
   ## cluster advertises no limit, so nothing is clamped.
   private function resolve_walltime( $cfg )
   {
      ## usemaxtime: skip computed estimate, use the configured cluster maximum
      if ( ! empty( $cfg[ 'usemaxtime' ] ) ) {
         $max_time = (int) $cfg[ 'maxtime' ];
         if ( $max_time === 0 )
            return [ "00:00:00", 999999 ];  ## maxtime=0 means no limit
         $hours    = (int)( $max_time / 60 );
         $mins     = (int)( $max_time % 60 );
         return [ sprintf( "%02d:%02d:00", $hours, $mins ), $max_time ];
      }

      $wall = $this->maxwall() * 3.0;

      if ( ! empty( $cfg[ 'wall_override' ] ) )
         $wall = (float) $cfg[ 'wall_override' ];

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
   private function build_env_lines( $cfg )
   {
      $block = trim( $cfg[ 'env_script_lines' ] ?? '' );
      return $block !== '' ? "\n" . $block . "\n\n" : "\n";
   }

   ## Clamp mgroupcount to the computed maximum
   private function resolve_mgroupcount()
   {
      $requested = $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] ?? 1;
      return min( $this->max_mgroupcount(), (int) $requested );
   }
}
