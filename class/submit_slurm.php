<?php
/*
 * submit_slurm.php
 *
 * Submits an analysis job to a Slurm cluster.
 *
 * Every cluster uses: ssh mkdir -> scp -> ssh sbatch. A cluster running on the
 * same host as the LIMS is reached the same way, so us3 must be able to ssh to
 * itself there.
 *
 * Every command therefore arrives as the 'login' user, whatever invoked PHP.
 *
 */
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
      chdir( $this->data[ 'job' ][ 'directory' ] );

      if ( ! $this->stage_files() ) {
         ## A staging failure is a submission failure and is recorded as one,
         ## exactly as an exhausted sbatch retry is. Without the write,
         ## autoflowAnalysis keeps whatever status it had and a request whose
         ## files never reached the cluster is indistinguishable from one still
         ## waiting to be picked up.
         $this->message[] = "ERROR: stage_files failed - submission aborted";
         $this->markAutoflowSubmitFailed( $this->stage_error !== '' ? $this->stage_error : 'staging failed' );
         chdir( $savedir );
         return;
      }

      $this->submit_job();

      ## Only write DB records and launch jobmonitor if we have a valid Slurm job ID.
      ## A missing ID means sbatch failed; the error is already in $this->message.
      if ( ! empty( $this->data[ 'eprfile' ] ) ) {
         ## "submit complete" is the only success signal the caller gets, so it
         ## must not be appended after a failed update_db(): the two together
         ## read as a successful submission carrying an incidental note.
         if ( $this->update_db() )
            $this->message[] = "submit complete";
      } else {
         $this->message[] = "ERROR: submit_job failed — no valid Slurm job ID; DB not updated";
      }

      chdir( $savedir );
   }

   /**
    * Create the working directory (ssh or local), write the slurm script
    * locally, then copy both files to the submithost (scp or local cp).
    *
    * Both steps are idempotent -- mkdir -p and a whole-file copy into a
    * per-request directory -- so remote_exec is allowed to retry them through
    * a transport fault. This is the step that was failing during the 2026
    * Expanse SFTP timeouts, and it had no retry at all: one timed-out copy
    * aborted the submission outright.
    *
    * Returns true on success, false if any step fails, with the failure
    * already recorded in $this->message AND persisted by the caller -- see
    * submit(), which must not let a staging failure vanish into a message
    * array nobody reads.
    */
   public function stage_files()
   {
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $login     = $this->login( $cluster );
      $workdir   = $this->workdir( $cluster, $requestID );
      $tarfile   = $this->tarfile();

      $this->message[] = "stage_files: cluster=$cluster login=$login workdir=$workdir";

      ## Create the working directory on the submithost
      $mk = $this->ssh( $cluster, "/bin/mkdir -p $workdir", [ 'label' => 'stage:mkdir' ] );
      if ( ! $mk[ 'ok' ] ) {
         $this->stage_error = $this->stageFailure( 'mkdir', $workdir, $mk );
         return false;
      }

      ## Generate slurm script locally
      $slufile = $this->write_slurm_script( $cluster, $requestID, $workdir, $tarfile );

      ## Copy input tar and slurm script to the working directory.
      $cp = $this->scp( $cluster, [ $tarfile, $slufile ], $workdir, [ 'label' => 'stage:copy' ] );
      if ( ! $cp[ 'ok' ] ) {
         $this->stage_error = $this->stageFailure( 'copy', "$tarfile $slufile", $cp );
         return false;
      }

      return true;
   }

   /**
    * Describe a staging failure in terms the operator can act on.
    *
    * The distinction is the point: an UNREACHABLE/TIMED_OUT staging failure is
    * a site problem and the request should be resubmitted once the cluster is
    * back, whereas a REMOTE_FAIL is a real rejection (bad path, full quota,
    * wrong permissions) that resubmitting will not fix.
    */
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

      ## Compute the parallel group count, the node count and the rank count
      ## FIRST. nodes() is the sizing pass: it rewrites grid[ppn]/[ppbj]/
      ## [maxproc] in place for fixed-capacity clusters and publishes the
      ## total rank count it settled on, so everything below has to be read
      ## after it has run.
      $mgroupcount = $this->resolve_mgroupcount();
      $nodes       = $this->nodes() * $mgroupcount;
      $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount;

      $cfg     = $this->grid[ $cluster ];
      $quename = $cfg[ 'queue' ];

      ## The rank count comes from the sizing pass, not from a second
      ## derivation here.
      ##
      ## This function used to build its own from grid[ppbj] plus a copy of
      ## the GA rule ("double procs-per-base-job if under one model group"),
      ## which dated from 2020 and the first Slurm script writer. That figure
      ## disagreed with the one nodes() had just computed, in both directions:
      ## it over-requested when demes was small and under-requested badly once
      ## demes grew past a single base job, because doubling ppbj cannot track
      ## a total that scales with demes. nodes() already implements GA's real
      ## rule, master plus demes, so there is nothing here left to decide.
      ## SubmitSlurmRankCountBaselineTest records what each cluster shape
      ## emitted before and after.
      ##
      ## nodes() sizes ONE model group. Parallel-masters runs $mgroupcount of
      ## them side by side inside a single MPI job, so the rank count scales by
      ## the group count exactly as the node count does on the line above --
      ## both axes are the one-group figure times the number of groups.
      ##
      ## us_mpi_analysis derives each group's share back out by dividing the
      ## MPI world size by the group count (us_mpi_analysis.cpp:1057), so
      ## leaving the rank count unscaled handed every group a fraction of the
      ## cores the sizing pass had allotted it: a 4-group job on demeler1-local
      ## reserved 4 nodes, ran 8 ranks, and left its first group a master with
      ## no workers. The predecessor PBS emitter did scale it -- "#PBS -l
      ## nodes=$nodes:ppn=$ppbj" multiplies out, and submit_local.php:211 wrote
      ## that product as $procs = $nodes * $ppbj -- and the scaling was lost in
      ## translation to Slurm, where -n is a job total rather than a per-node
      ## figure.
      ##
      ## Multiplying is right on fixed-capacity boxes too, and not a double
      ## count: nodes() divides that box's capacity by the group count
      ## (jobsubmit.php:687) so procs is already one group's share, and the
      ## product comes back to the configured maxproc.
      $ranks = (int) $this->data[ 'job' ][ 'procs' ] * $mgroupcount;

      ## single_node: confine the job to one node. The rank count is the same
      ## either way, so collapsing the node count is the whole of the
      ## transformation -- there is no per-node figure in the emitted script
      ## to recompute.
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

      ## Held for update_db(), which writes it to HPCAnalysisResult.jobfile.
      ## The key was 'pbsfile' back when this class had a PBS sibling emitter;
      ## it has only ever held whatever script the emitter produced, which is
      ## now always Slurm.
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
      $login     = $this->login( $cluster );
      $port      = $this->grid[ $cluster ][ 'sshport' ];
      $workdir   = $this->workdir( $cluster, $requestID );

      $submitResult = $this->attemptSubmit( $cluster, $port, $login, $workdir );

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

   ## Run sbatch once via SSH and validate its result. A failure is deliberately
   ## not retried: the remote command may have reached Slurm even when its job
   ## ID did not make it back across SSH.
   private function attemptSubmit( $cluster, $port, $login, $workdir )
   {
      $result = $this->sbatchOnce( $cluster, $port, $login, $workdir, 1 );

      if ( $result[ 'ok' ] ) {
         return array(
            'submit_ok' => true,
            'job_id'    => $result[ 'job_id' ],
            'error'     => '',
            'attempt'   => 1,
         );
      }

      $error = $result[ 'error' ]
             . "; automatic retry disabled because sbatch is non-idempotent; "
             . "the submission outcome may be unknown, so reconcile on the cluster before resubmitting";

      return array(
         'submit_ok' => false,
         'job_id'    => '',
         'error'     => $error,
         'attempt'   => 1,
      );
   }

   ## Run sbatch --parsable once via SSH and validate the result.
   ## Stdout and stderr are kept separate so --parsable's stdout is never
   ## contaminated by SSH warnings or sbatch error text.
   ## Returns ['ok' => bool, 'job_id' => string, 'error' => string].
   private function sbatchOnce( $cluster, $port, $login, $workdir, $attempt )
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
         $this->message[] = "sbatchOnce (attempt $attempt): stderr=$stderr_text";

      elog2( "sbatchOnce (attempt $attempt): exit=$exit_code class={$res['class']} stdout=$stdout_text stderr=$stderr_text" );

      ## Gate: anything but a clean exit means we have no job ID to trust.
      ## The classification is carried out to the caller so an exhausted
      ## submission can say whether the cluster refused the job or was simply
      ## not reachable -- one is a bad request, the other is an outage.
      if ( ! $res[ 'ok' ] ) {
         $detail = $stderr_text !== '' ? $stderr_text : $stdout_text;
         $error  = remote_exec_infra_fault( $res )
                 ? "cluster unreachable ({$res['class']}): $detail"
                 : "sbatch exited $exit_code: $detail";

         return array( 'ok' => false, 'job_id' => '', 'error' => $error, 'class' => $res[ 'class' ] );
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

   ## Write submission record to instance DB and global gfac DB, then launch
   ## jobmonitor. Returns false if any of that failed.
   ##
   ## Every failure here is reported with an "ERROR:" prefix because that is
   ## what the submit pages scan for when deciding whether to warn the user
   ## (2DSA_2.php and its siblings match /^ERROR:/ against get_messages()).
   ## Without the prefix these read as ordinary progress notes, so a job that
   ## reached the cluster but was never recorded rendered as a clean success --
   ## and an unrecorded job is invisible to jobmonitor, to the queue views, and
   ## to cleanup.
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
         $this->message[] = "ERROR: cannot connect to $dbhost:$dbname - job is running but unrecorded";
         return false;
      }

      $jobfile = mysqli_real_escape_string( $link, $this->data[ 'jobfile' ] );
      $query = "INSERT INTO HPCAnalysisResult SET "
             . "HPCAnalysisRequestID='$requestID', "
             . "jobfile='$jobfile', "
             . "gfacID='$slurm_id'";
      $result = mysqli_query( $link, $query );
      if ( ! $result ) {
         $this->message[] = "ERROR: HPCAnalysisResult insert failed - job is running but "
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
            $this->message[] = "ERROR: autoflowAnalysis update failed - the pipeline will not "
                             . "advance: " . mysqli_error( $link );
            $ok = false;
         }
      }

      mysqli_close( $link );

      ## Write to global gfac DB (job tracking)
      $gfac_link = mysqli_connect( $globaldbhost, $globaldbuser, $globaldbpasswd, $globaldbname );
      if ( ! $gfac_link ) {
         $this->message[] = "ERROR: cannot connect to global DB $globaldbhost:$globaldbname - "
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
         $this->message[] = "ERROR: gfac.analysis insert failed - job will not be tracked or "
                          . "cleaned up: " . mysqli_error( $gfac_link );
         $ok = false;
      }

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

      if ( $exit_code !== 0 ) {
         ## The job is on the cluster and recorded, but nothing is watching it,
         ## so it will sit at SUBMITTED until the cron sweep times it out.
         $this->message[] = "ERROR: jobmonitor launch failed (exit=$exit_code) - job will not "
                          . "be monitored";
         $ok = false;
      } else {
         $this->message[] = "jobmonitor launch: exit=$exit_code";
      }

      return $ok;
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

   ## Run a command on the submithost; log and return exit code.
   ## Local clusters execute it directly, remote ones via SSH.
   /**
    * Build a remote_exec for one cluster, wired to this object's own exec
    * seam so a test double's scripted responses cover the whole call chain.
    *
    * Every ssh/scp this class performs goes through here. Nothing in this
    * file should ever build an "ssh ..." string again: the timeout budget,
    * the hardening options, and the failure classification all live in
    * remote_exec, and a second copy of them is how the pre-2026 code ended up
    * with four mutually inconsistent notions of what a failed call meant.
    */
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

   ## Copy the staged files into the working directory. Remote clusters use
   ## scp; local ones use cp, since source and destination are the same
   ## filesystem and $dest is already a bare path (see stage_files).
   private function scp( $cluster, $files, $dest, $opts = [] )
   {
      $res = $this->remote( $cluster )->copy_to( $files, $dest, $opts );
      $this->recordRemote( 'copy', $res );
      return $res;
   }

   ## One place that turns a remote_exec result into user-visible diagnostics,
   ## so every call site reports failures the same way.
   private function recordRemote( $label, $res )
   {
      $this->message[] = "$label: {$res['cmd']}  exit={$res['exit_code']}"
                       . "  class={$res['class']}  attempts={$res['attempts']}"
                       . ( $res[ 'ok' ] ? '' : "  err=" . ( $res[ 'stderr' ] !== '' ? $res[ 'stderr' ] : $res[ 'text' ] ) );
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
      ## An idempotent read, but explicitly unretried: this is a best-effort
      ## confirmation of an ID we already hold, so spending the full transport
      ## backoff on it would delay a successful submission for no benefit.
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
