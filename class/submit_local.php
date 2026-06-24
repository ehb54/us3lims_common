<?php
/*
 * submit_local.php
 *
 * Submits an analysis to a local system (bcf/alamo)
 *
 */
require_once $class_dir . 'jobsubmit.php';
include_once $class_dir . 'priority.php';

function elog2( $msg )
{
   error_log( "$msg\n", 3, "/home/us3/lims/etc/elog2.txt" );
}

elog2( "submit_local from common_class start" );

class submit_local extends jobsubmit
{ 
   ## Submits data
   function submit()
   {
      ## a preliminary test to see if data is still defined
      if ( ! isset( $this->data[ 'job' ][ 'cluster_shortname' ] ) )
      {
        $this->message[] = "Data profile is not defined. Return to Queue Setup.\n";
        return;
      }

      $savedir  = getcwd();
      chdir( $this->data[ 'job' ][ 'directory' ] );
      $this->copy_files    ();
      $this->submit_job    ();
      $this->update_db     ();
      chdir( $savedir );
$this->message[] = "End of submit_local.php";
   }
 
   ## Copy needed files to supercomputer
   function copy_files()
   {
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $clusname  = $this->data[ 'job' ][ 'cluster_name' ];
      $gwhostid  = $this->data[ 'job' ][ 'gwhostid' ];
      $subtype   = $this->grid[ $cluster ][ 'submittype' ];
      $is_us3iab = preg_match( "/us3iab-node0/", $cluster );
      ## || preg_match( "/" . $clusname ."/", $gwhostid ) );
      $no_us3iab = 1 - $is_us3iab;
      $is_slurm  = preg_match( "/slurm/", $subtype );
##$this->message[] = "cluster=$cluster is_us3iab=$is_us3iab is_slurm=$is_slurm";
$this->message[] = "cluster=$cluster $clusname  gwhostid=$gwhostid  is_us3iab=$is_us3iab  is_slurm=$is_slurm";
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $jobid     = $this->data[ 'db' ][ 'name' ] . sprintf( "-%06d", $requestID );
      $workdir   = $this->grid[ $cluster ][ 'workdir' ] . $jobid;
      $address   = $this->grid[ $cluster ][ 'name' ];
      $login     = isset( $this->grid[ $cluster ][ 'login' ] ) ? $this->grid[ $cluster ][ 'login' ] : $address;
      if ( $is_us3iab )
         $address   = "localhost";
      $port      = $this->grid[ $cluster ][ 'sshport' ]; 
      $tarfile   = sprintf( "hpcinput-%s-%s-%05d.tar",
                         $this->data['db']['host'],
                         $this->data['db']['name'],
                         $this->data['job']['requestID'] );
    
      ## Create working directory
      $output    = array();
      $cmd       = "/bin/mkdir $workdir 2>&1";
      if ( $no_us3iab )
      {
         $cmd       = "/usr/bin/ssh -p $port -x $login " . $cmd;
      }

      exec( $cmd, $output, $status );
$this->message[] = "$cmd status=$status";
if($status != 0)
 $this->message[] = "  ++++ output=$output[0]";

      ##  Create pbs/slurm file, then copy it and tar file to work directory
      if ( $is_slurm )
         $pbsfile  = $this->create_slurm();
      else
         $pbsfile  = $this->create_pbs();

      if ( $no_us3iab )
      {
	 $cmd      = "/usr/bin/scp -P $port $tarfile $pbsfile $login:$workdir 2>&1";
      }
      else
      {
         $cmd      = "/bin/cp $tarfile $pbsfile $workdir/ 2>&1";
      }

      exec( $cmd, $output, $status );
$this->message[] = "$cmd status=$status";
if($status != 0)
 $this->message[] = "++++ output=$output[0]";
      
$this->message[] = "Files copied to $address:$workdir";
   }
 
   ## Create a pbs file
   function create_pbs()
   {
      $output    = array();
      exec( "ls -d ~us3/lims/work", $output, $status );
      $pbswork   = $output[ 0 ];
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $clusname  = $this->data[ 'job' ][ 'cluster_name' ];
      $gwhostid  = $this->data[ 'job' ][ 'gwhostid' ];
      $is_us3iab = preg_match( "/us3iab-node0/", $cluster );
      ## || preg_match( "/" . $clusname ."/", $gwhostid ) );
      $no_us3iab = 1 - $is_us3iab;
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $jobid     = $this->data[ 'db' ][ 'name' ] . sprintf( "-%06d", $requestID );
      $workdir   = $this->grid[ $cluster ][ 'workdir' ] . $jobid;
      $tarfile   = sprintf( "hpcinput-%s-%s-%05d.tar",
                         $this->data['db']['host'],
                         $this->data['db']['name'],
                         $this->data['job']['requestID'] );
      $mgroupcount = 1;
      $nodes       = $this->nodes();
      if ( isset( $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] ) )
      {
         $mgroupcount  = min( $this->max_mgroupcount(),
            $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] );
      }

      $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount;
      $pbsfile = "us3.pbs";
      $pbspath = $pbsfile;
      $wall    = $this->maxwall() * 3.0;
       if ( $is_us3iab )
         $wall    = 2880.0;
      $nodes   = $this->nodes() * $mgroupcount;

      $hours   = (int)( $wall / 60 );
      $mins    = (int)( $wall % 60 );
      $ppn     = $this->grid[ $cluster ][ 'ppn' ]; 
      $ppbj    = $this->grid[ $cluster ][ 'ppbj' ]; 

      $walltime = sprintf( "%02.2d:%02.2d:00", $hours, $mins );  ## 01:09:00
      $wallmins = $hours * 60 + $mins;

      $can_load = 0;
      $demeler3_load = 0;

      $plines   = "";
      $mpirun   = "mpirun --bind-to none";
      ##$mpirun   = "mpirun";
      ##$mpirun   = "mpirun --bind-to-core";
      ##$mpiana   = "/home/us3/cluster/bin/us_mpi_analysis";
      $mpiana   = "us_mpi_analysis";

$this->message[] = "cluster=$cluster  ppn=$ppn  ppbj=$ppbj  wall=$wall";

      switch( $cluster )
      {
        case 'demeler3-local':
         $demeler3_load = 1;
         $libpath  = "/home/us3/cluster/lib";
         $path     = "/home/us3/cluster/bin";
         $ppn      = max( $ppn, 9 );
         break;

        case 'us3iab-node0':
        case 'us3iab-node1':
        case 'us3iab-devel':
         $can_load = 0;
         if ( $nodes > 1 )
         {
            $ppn      = $nodes * $ppn;
            $nodes    = 1;
         }
         $libpath  = "/usr/local/lib64:/export/home/us3/cluster/lib:/usr/lib64/openmpi-1.10/lib:/opt/qt/lib";
         $path     = "/export/home/us3/cluster/bin:/usr/lib64/openmpi-1.10/bin";
         $ppn      = max( $ppn, 8 );
         $wall     = 2880;
         break;

        default:
         $libpath  = "/share/apps/openmpi/lib:/share/apps/qt/lib";
         $path     = "/share/apps/openmpi/bin";
         $ppn      = 2;
         break;
      }
$this->message[] = "can_load=$can_load  ppn=$ppn";

      if ( $can_load )
      {  ## Can use module load to set paths and environ
         $plines  = 
            "\n"                    .
            "module load $load1 \n" .
            "module load $load2 \n" .
            "module load $load3 \n" .
            "module load $load4 \n" .
            "\n";
      }

      else
      {  ## Can't use module load; must set paths
         $plines  = 
            "\nexport LD_LIBRARY_PATH=$libpath:\$LD_LIBRARY_PATH\n" .
            "export PATH=$path:\$PATH\n\n";

         if ( $demeler3_load )
         {
            $plines .= "module load mpi/openmpi-x86_64 \n\n";
         }
      }

      $procs   = $nodes * $ppbj;
      $quename = "batch";
      if ( preg_match( "/us3iab-dev/", $cluster ) )
         $quename = "normal";

      $contents = 
      "#!/bin/bash\n"                                       .
      "#\n"                                                 .
      "#PBS -S /bin/bash\n"                                 .
      "#PBS -q $quename\n"                                     .
      "#PBS -N US3_Job_$requestID\n"                        .
      "#PBS -l nodes=$nodes:ppn=$ppbj,walltime=$walltime\n"  .
      "#PBS -V\n"                                           .
      "#PBS -o $workdir/stdout\n"                           .
      "#PBS -e $workdir/stderr\n"                           .
      "#pmgroups=$mgroupcount\n"                            .
      "$plines"                                             .
      "\n"                                                  .
      "cd $workdir\n"                                       .
      "if [ -f $pbswork/aux.pbs ]; then\n"         .
      " .  $pbswork/aux.pbs\n"                     .
      "fi\n"                                                .
      "\n"                                                  .
      "$mpirun -np $procs $mpiana -walltime $wallmins"      .
      " -mgroupcount $mgroupcount $tarfile\n";

      $this->data[ 'pbsfile' ] = $contents;

      $h = fopen( $pbspath, "w" );
      fwrite( $h, $contents );
      fclose( $h );

      return $pbsfile;
   }

   ## Create a slurm file
   function create_slurm()
   {
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $clusname  = $this->data[ 'job' ][ 'cluster_name' ];
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $jobid     = $this->data[ 'db' ][ 'name' ] . sprintf( "-%06d", $requestID );
      $quename   = $this->grid[ $cluster ][ 'queue' ];
      $workdir   = $this->grid[ $cluster ][ 'workdir' ] . $jobid;
      $gwhostid  = $this->data[ 'job' ][ 'gwhostid' ];
      $is_us3iab = preg_match( "/us3iab/", $cluster );
      ## || preg_match( "/" . $clusname ."/", $gwhostid ) );
      $no_us3iab = 1 - $is_us3iab;
elog2( "create slurm cluster $cluster clusname $clusname quename $quename" );
      $tarfile   = sprintf( "hpcinput-%s-%s-%05d.tar",
                         $this->data['db']['host'],
                         $this->data['db']['name'],
                         $this->data['job']['requestID'] );
      $mgroupcount = 1;
      $nodes       = $this->nodes();
      if ( isset( $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] ) )
      {
         $mgroupcount  = min( $this->max_mgroupcount(),
            $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] );
      }

      $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount;
      $slufile = "us3.slurm";
      $slupath = $slufile;
      $wall    = $this->maxwall() * 3.0;
      if ( $is_us3iab )
         $wall    = 999999;
      $nodes   = $this->nodes() * $mgroupcount;

      $hours   = (int)( $wall / 60 );
      $mins    = (int)( $wall % 60 );
      $ppn     = $this->grid[ $cluster ][ 'ppn' ]; 
      $ppbj    = $this->grid[ $cluster ][ 'ppbj' ]; 
      ## For GA analysis, procs-per-base-job may need to be doubled
      if ( preg_match( "/GA/", $this->data[ 'method' ] )  &&
           $ppbj < 16 )
         $ppbj   *= 2;

      $walltime = sprintf( "%02.2d:%02.2d:00", $hours, $mins );  ## 01:09:00
      $wallmins = $hours * 60 + $mins;
      $can_load = 0;
      $plines   = "";
      $mpirun   = "mpirun";
      $mpiana   = "us_mpi_analysis";
      $slurm332bug = "";

$this->message[] = "cluster=$cluster  ppn=$ppn  ppbj=$ppbj  wall=$wall";

      ## details should all be moved to global_config.php

      switch( $cluster )
      {
        case 'jetstream-local':
          $can_load = 1;
          $load1    = "mpi";
          $load2    = "qt5";
          $load3    = "ultrascan3";
          break;

        case 'chinook-local':
          $can_load = 0;
          $load1    = "mpi";
          $load2    = "";
          $load3    = "";
          $libpath  = "/home/us3/cluster/lib:/opt/qt/lib";
          $path     = "/home/us3/cluster/bin";
          $ppn      = max( $ppn, 8 );
          $wall     = 2880;
          break;

        case 'umontana-local':
          $can_load = 2;
          $load1    = "use /home/bd142854e/local/modulefiles";
          $load2    = "load rocks/openmpi";
          $load3    = "load qt5";
          $libpath  = "/home/bd142854e/cluster/lib";
          $path     = "/home/bd142854e/cluster/bin";
          $ppn      = max( $ppn, 8 );
          $wall     = 2880;
          $plines   = "\n" .
                      "module $load1\n" .
                      "module $load2\n" .
                      "module $load3\n\n" .
                      "export LD_LIBRARY_PATH=$libpath:\$LD_LIBRARY_PATH\n" .
                      "export PATH=$path:\$PATH\n\n";
          break;

	case 'demeler9-local':
          $can_load = 1;
          $load1    = "module purge \n";
          $load2    = "module load mpi/mpich-x86_64 \n";
          $load3    = "module load ultrascan/mpi \n";
          $load4    = '';
          
          if ( $nodes > 1 )
          {
             $ppn      = $nodes * $ppn;
             $nodes    = 1;
          }
          $libpath  = "/usr/local/lib64:/export/home/us3/cluster/lib:/usr/lib64/openmpi-1.10/lib:/opt/qt/lib";
          $path     = "/export/home/us3/cluster/bin:/usr/lib64/openmpi-1.10/bin";
          $ppn      = max( $ppn, 8 );
          $wall     = 2880;
          break;

        case 'us3iab-node0':
        case 'us3iab-node1':
        case 'us3iab-devel':
        case 'demeler1-local':
          $can_load = 1;
          $load1    = "module purge \n";
          $load2    = "module load mpi/mpich-x86_64 \n";
          $load3    = "module load ultrascan/mpi \n";
          $load4    = '';
          
          $slurm332bug = '-hosts=$SLURM_NODELIST -np $SLURM_NTASKS';
          if ( $nodes > 1 )
          {
             $ppn      = $nodes * $ppn;
             $nodes    = 1;
          }
          $libpath  = "/usr/local/lib64:/export/home/us3/cluster/lib:/usr/lib64/openmpi-1.10/lib:/opt/qt/lib";
          $path     = "/export/home/us3/cluster/bin:/usr/lib64/openmpi-1.10/bin";
          $ppn      = max( $ppn, 8 );
          $wall     = 0; ## no time limit
          $walltime = "00:00:00";
          $wallmins = 999999; ## arbitrary limit for mpirun (~2 years!)
          break;

        default:
          $libpath  = "/share/apps/openmpi/lib:/share/apps/qt/lib";
          $path     = "/share/apps/openmpi/bin";
          $ppn      = 2;
          break;
      }
$this->message[] = "can_load=$can_load  ppn=$ppn";

      if ( $can_load == 1 )
      {  ## Can use module load to set paths and environ
         $plines .= ( "\n" . $load1 . $load2 . $load3 . $load4 . "\n" );
      }

      else if ( $can_load == 0 )
      {  ## Can't use module load; must set paths
         $plines  = 
            "\nexport LD_LIBRARY_PATH=$libpath:\$LD_LIBRARY_PATH\n" .
            "export PATH=$path:\$PATH\n\n";
      }

      $procs   = $nodes * $ppn;

      $priority_nice = priority_nice_string();
      if ( strlen( $priority_nice ) ) {
         $this->message[] = "Priority set " . str_replace( "\n", "; ", $priority_nice );
      }

      ### for low core machine, set here
      # $ppbj = 2;
 
      if ( isset( $this->grid[ $cluster ][ 'mempercore' ] ) ) {
          $sbatch_mempercore = "#SBATCH --mem-per-cpu=" . $this->grid[ $cluster ][ 'mempercore' ];
      }

      $contents = 
      "#!/bin/bash\n" .
      "#SBATCH -p $quename\n" .
      "#SBATCH -J US3_Job_$requestID\n" .
      "#SBATCH -N $nodes\n" .
      "#SBATCH -n $ppbj\n" .
      "#SBATCH -t $walltime\n" .
      "#SBATCH -e $workdir/stderr\n" .
      "#SBATCH -o $workdir/stdout\n" .
      "$sbatch_mempercore\n" .
      "$priority_nice\n" .
       "$plines" .
      "export UCX_LOG_LEVEL=error\n" .
      "export OMPI_MCA_btl=self,sm,tcp\n" .
      "export QT_LOGGING_RULES='*.debug=true'\n\n" .
      "cd $workdir\n\n" .
      "$mpirun $slurm332bug $mpiana -walltime $wallmins" .
      " -mgroupcount $mgroupcount $tarfile\n";

      $this->data[ 'pbsfile' ] = $contents;

      $h = fopen( $slupath, "w" );
      fwrite( $h, $contents );
      fclose( $h );

      return $slufile;
   }

   ## Schedule the job
   function submit_job()
   {
      global $global_sbatch_submit_retries;
      global $global_sbatch_submit_retry_wait_seconds;

      ## set defaults if not defined in global_config.php
      if ( !isset( $global_sbatch_submit_retries ) ) {
          $global_sbatch_submit_retries = 3;
      }
      if ( !isset( $global_sbatch_submit_retry_wait_seconds ) ) {
          $global_sbatch_submit_retry_wait_seconds = 5;
      }

      date_default_timezone_set( "America/Chicago" );

      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $clusname  = $this->data[ 'job' ][ 'cluster_name' ];
      $gwhostid  = $this->data[ 'job' ][ 'gwhostid' ];
      $subtype   = $this->grid[ $cluster ][ 'submittype' ];
      $is_us3iab = preg_match( "/us3iab-node0/", $cluster );
      ## preg_match( "/" . $clusname ."/", $gwhostid ) );
      $no_us3iab = 1 - $is_us3iab;

      $is_slurm  = preg_match( "/slurm/", $subtype );
      $is_demel3 = preg_match( "/demeler3/", $cluster );

      ## allow per-cluster override of submit retry/backoff
      $submit_retries    = array_key_exists( 'submit_retries', $this->grid[ $cluster ] )
                          ? $this->grid[ $cluster ][ 'submit_retries' ]
                          : $global_sbatch_submit_retries;
      $submit_retry_wait = array_key_exists( 'submit_retry_wait', $this->grid[ $cluster ] )
                          ? $this->grid[ $cluster ][ 'submit_retry_wait' ]
                          : $global_sbatch_submit_retry_wait_seconds;

      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $jobid     = $this->data[ 'db' ][ 'name' ] . sprintf( "-%06d", $requestID );
      $workdir   = $this->grid[ $cluster ][ 'workdir' ] . $jobid;
      $address   = $this->grid[ $cluster ][ 'name' ];
      $login     = isset( $this->grid[ $cluster ][ 'login' ] ) ? $this->grid[ $cluster ][ 'login' ] : $address;
      $port      = $this->grid[ $cluster ][ 'sshport' ]; 
      $tarfile   = sprintf( "hpcinput-%s-%s-%05d.tar",
                         $this->data['db']['host'],
                         $this->data['db']['name'],
                         $this->data['job']['requestID'] );

      ## Submit job to the queue
      $output    = array();
      if ( $is_slurm )
      {
         $cmd   = "sbatch --get-user-env $workdir/us3.slurm 2>&1";
      }
      else
      {
         $cmd   = "/usr/bin/qsub $workdir/us3.pbs 2>&1";
      }
      if ( $no_us3iab )
      {
         $cmd   = "ssh -p $port -x $login " . $cmd;
      }

      $submitResult = $this->attemptSubmit( $cmd, $is_slurm, $is_demel3, $submit_retries, $submit_retry_wait );

      if ( ! $submitResult[ 'submit_ok' ] )
      {
         $this->data[ 'eprfile' ] = '';
$this->message[] = "ERROR: job submission failed after " . $submitResult['attempt'] . " attempt(s): status=" . $submitResult['status'] . " out0=" . $submitResult['output'][0];
elog2( "submit_local: FAILED after " . $submitResult['attempt'] . " attempts, status=" . $submitResult['status'] . " out0=" . $submitResult['output'][0] );
         return;
      }

      $this->data[ 'eprfile' ] = $submitResult[ 'jobnum' ];
$this->message[] = "Job submitted; jobid=" . $submitResult['jobid'] . " ID=" . $this->data[ 'eprfile' ]
 . " status=" . $submitResult['status'] . " out0=" . $submitResult['output'][0];
elog2( "submit_local 0: jobid=" . $submitResult['jobid'] . " ID=" . $this->data[ 'eprfile' ] );
   }

   ## Run sbatch/qsub once, validate its result, and retry with
   ## exponential backoff on failure. Returns an array describing the
   ## outcome of the last attempt: submit_ok, jobnum, jobid, status,
   ## output, attempt.
   private function attemptSubmit( $cmd, $is_slurm, $is_demel3, $submit_retries, $secwait )
   {
      $attempt = 0;
      $jobnum  = false;
      $jobid   = '';
      $output  = array();
      $status  = null;

      do
      {
         $attempt++;
         $output = array(); // exec() appends rather than replaces - reset per attempt
elog2( "submit_local cmd = $cmd (attempt $attempt)" );
         $jobid  = exec( $cmd, $output, $status );
$this->message[] = "$cmd status=$status  jobid=$jobid (attempt $attempt)";

         $jobnum = $this->parseSubmitResult( $status, $output, $jobid, $is_slurm, $is_demel3 );

         if ( $jobnum === false && $attempt <= $submit_retries )
         {
elog2( "submit_local: submit failed (attempt $attempt), retrying in ${secwait}s: status=$status out0=" . $output[0] );
            sleep( $secwait );
            $secwait *= 2;
         }
      } while ( $jobnum === false && $attempt <= $submit_retries );

      return array(
          'submit_ok' => $jobnum !== false,
          'jobnum'    => $jobnum,
          'jobid'     => $jobid,
          'status'    => $status,
          'output'    => $output,
          'attempt'   => $attempt,
      );
   }

   ## Validate a single submission attempt's exec() result and extract
   ## the job ID. Returns the job number string on success, or false.
   private function parseSubmitResult( $status, $output, $jobid, $is_slurm, $is_demel3 )
   {
      if ( $status != 0 )
      {
         return false;
      }

      if ( $is_slurm )
      {
         if ( preg_match( "/^Submitted batch job\s+(\d+)/", $output[ 0 ], $m ) )
         {
            return $m[ 1 ];
         }
         return false;
      }

      if ( $is_demel3 )
      {
         $parts_b = preg_split( "/\./", rtrim( $jobid ) );
         return $parts_b[ 0 ];
      }

      return rtrim( $jobid );
   }

   ## Mark an autoflow request as failed when job submission could not
   ## obtain a real job ID, after exhausting retries.
   private function markAutoflowSubmitFailed( $autoflowID, $statusMsg )
   {
      global $dbusername;
      global $dbpasswd;
      global $dbhost;
      global $dbname;

      $link = mysqli_connect( $dbhost, $dbusername, $dbpasswd, $dbname );
      if ( ! $link )
      {
         $this->message[] = "Cannot open $dbhost : $dbname\n";
         return;
      }

      $qfmsg = mysqli_real_escape_string( $link, $statusMsg );
      $query = "UPDATE autoflowAnalysis SET "               .
               "status='SUBMIT_TIMEOUT', "                  .
               "statusMsg='Job submission failed: $qfmsg' " .
               "WHERE requestID='$autoflowID'";
      $result = mysqli_query( $link, $query );
      if ( ! $result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysqli_error( $link ) . "\n";
      }
      mysqli_close( $link );
   }

   function update_db()
   {
      global $globaldbuser;
      global $globaldbpasswd;
      global $globaldbhost;
      global $globaldbname;

      global $dbusername;
      global $dbpasswd;
      global $dbhost;
      global $dbname;

      global $ID;
      global $is_cli;

      $cluster   = $this->data['job']['cluster_shortname'];
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $eprfile   = $this->data['eprfile'];
      $autoflowID = 0;
      if ( $is_cli ) {
          $autoflowID = $ID;
      }

      if ( empty( $eprfile ) )
      {
         ## submit_job() failed to obtain a real job ID; don't record a
         ## bogus/empty gfacID or start a jobmonitor watching a job that
         ## was never actually submitted.
         $this->message[] = "Job submission failed, not updating database for requestID=$requestID";

         if ( $autoflowID > 0 )
         {
            $this->markAutoflowSubmitFailed( $autoflowID, implode( '; ', $this->message ) );
         }
         return;
      }

      $link = mysqli_connect( $dbhost, $dbusername, $dbpasswd, $dbname );
 
      if ( ! $link )
      {
         $this->message[] = "Cannot open $dbhost : $dbname\n";
         return;
      }
 
      $pbs       = mysqli_real_escape_string( $link, $this->data[ 'pbsfile' ] );
      $query = "INSERT INTO HPCAnalysisResult SET "  .
               "HPCAnalysisRequestID='$requestID', " .
               "jobfile='$pbs', "                    .
               "gfacID='$eprfile' ";
      
      $result = mysqli_query( $link, $query );
 
      if ( ! $result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysqli_error( $link ) . "\n";
         return;
      }
 
      if ( $autoflowID > 0 ) {
          $query = "UPDATE autoflowAnalysis SET "  .
                   "currentGfacID='$eprfile', "    .
                   "currentHPCARID='$requestID', " .
                   "status='SUBMITTED', "          .
                   "statusMsg='Job submitted' "    .
                   "WHERE requestID='$autoflowID'";

          $result = mysqli_query( $link, $query );
          
          echo __FILE__ . " : update query $query\n";

          if ( ! $result )
          {
              $this->message[] = "Invalid query:\n$query\n" . mysqli_error( $link ) . "\n";
              return;
          }
      }

      mysqli_close( $link );

      ## Insert initial data into global DB
      $gfac_link = mysqli_connect( $globaldbhost, $globaldbuser, $globaldbpasswd, $globaldbname );
 
      if ( ! $gfac_link )
      {
         $this->message[] = "Cannot open database on $globaldbhost : $globaldbname\n";
         return;
      }

      $query = "INSERT INTO analysis SET "          .
               "gfacID='$eprfile', "                .
               "autoflowAnalysisID='$autoflowID', " .
               "cluster='$cluster', "               .
               "us3_db='$dbname'";

      $result = mysqli_query( $gfac_link, $query );
 
      ## echo __FILE__ . " : insert query $query\n";
      
      if ( ! $result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysqli_error( $gfac_link ) . "\n";
         return;
      }
 
      mysqli_close( $gfac_link );

      
$this->message[] = "Database $dbname updated: requestID = $requestID";

      $cmd = "nice -15 php /home/us3/lims/bin/jobmonitor/jobmonitor.php $dbname $eprfile $requestID 2>&1";
      exec( $cmd, $null, $status );
      $this->message[] = "$cmd status=$status";
      if($status != 0) {
          $this->message[] = "  ++++ output=$output[0]";
      }

   }

   function close_transport( )
   {  ## Dummy function since new class functions have already closed
   }

}
