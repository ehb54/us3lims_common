<?php
/*
 * submit_local.php
 *
 * Submits an analysis to a local system (bcf/alamo)
 *
 */
require_once $class_dir . 'jobsubmit.php';

class submit_local extends jobsubmit
{ 
   // Submits data
   function submit()
   {
      // a preliminary test to see if data is still defined
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
 
   // Copy needed files to supercomputer
   function copy_files()
   {
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $is_us3iab = preg_match( "/us3iab/", $cluster );
      $no_us3iab = 1 - $is_us3iab;
      $is_jetstr = preg_match( "/jetstream/", $cluster );
$this->message[] = "cluster=$cluster is_us3iab=$is_us3iab is_jetstr=$is_jetstr";
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $jobid     = $this->data[ 'db' ][ 'name' ] . sprintf( "-%06d", $requestID );
      $workdir   = $this->grid[ $cluster ][ 'workdir' ] . $jobid;
      $address   = $this->grid[ $cluster ][ 'name' ];
      if ( $is_us3iab )
         $address   = "localhost";
      $port      = $this->grid[ $cluster ][ 'sshport' ]; 
      $tarfile   = sprintf( "hpcinput-%s-%s-%05d.tar",
                         $this->data['db']['host'],
                         $this->data['db']['name'],
                         $this->data['job']['requestID'] );
    
      // Create working directory
      $output    = array();
      $cmd       = "/bin/mkdir $workdir 2>&1";
      if ( $no_us3iab )
         $cmd       = "/usr/bin/ssh -p $port -x us3@$address " . $cmd;

      exec( $cmd, $output, $status );
$this->message[] = "$cmd status=$status";
if($status != 0)
 $this->message[] = "  ++++ output=$output[0]";

      //  Create pbs/slurm file, then copy it and tar file to work directory
      if ( $is_jetstr )
         $pbsfile  = $this->create_slurm();
      else
         $pbsfile  = $this->create_pbs();

      if ( $no_us3iab )
         $cmd      = "/usr/bin/scp -P $port $tarfile $pbsfile us3@$address:$workdir 2>&1";
      else
         $cmd      = "/bin/cp $tarfile $pbsfile $workdir/ 2>&1";

      exec( $cmd, $output, $status );
$this->message[] = "$cmd status=$status";
if($status != 0)
 $this->message[] = "++++ output=$output[0]";
      
$this->message[] = "Files copied to $address:$workdir";
   }
 
   // Create a pbs file
   function create_pbs()
   {
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $is_us3iab = preg_match( "/us3iab/", $cluster );
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

      $walltime = sprintf( "%02.2d:%02.2d:00", $hours, $mins );  // 01:09:00
      $wallmins = $hours * 60 + $mins;
      $can_load = 0;
      $plines   = "";
      $mpirun   = "mpirun --bind-to none";
      //$mpirun   = "mpirun";
      //$mpirun   = "mpirun --bind-to-core";
      //$mpiana   = "/home/us3/cluster/bin/us_mpi_analysis";
      $mpiana   = "us_mpi_analysis";

$this->message[] = "cluster=$cluster  ppn=$ppn  ppbj=$ppbj  wall=$wall";

      switch( $cluster )
      {
        case 'bcf-local':
         $can_load = 0;
         $libpath  = "/share/apps64/openmpi/lib";
         $path     = "/share/apps64/openmpi/bin";
         break;

        case 'jacinto-local':
         $can_load = 0;
         $libpath  = "/share/apps/openmpi/lib";
         $path     = "/share/apps/openmpi/bin";
         break;

        case 'alamo-local':
         $can_load = 1;
         $load1    = "intel/2015/64";
         $load2    = "openmpi/intel/2.1.1";
         $load3    = "qt5/5.6.2";
         $load4    = "ultrascan3/3.5";
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
      {  // Can use module load to set paths and environ
         $plines  = 
            "\n"                    .
            "module load $load1 \n" .
            "module load $load2 \n" .
            "module load $load3 \n" .
            "module load $load4 \n" .
            "\n";
      }

      else
      {  // Can't use module load; must set paths
         $plines  = 
            "\n"                                                  .
            "export LD_LIBRARY_PATH=$libpath:\$LD_LIBRARY_PATH\n" .
            "export PATH=$path:\$PATH\n"                          .
            "\n";
      }

      $procs   = $nodes * $ppn;

      $contents = 
      "#! /bin/bash\n"                                      .
      "#\n"                                                 .
      "#PBS -N US3_Job_$requestID\n"                        .
      "#PBS -l nodes=$nodes:ppn=$ppn,walltime=$walltime\n"  .
      "#PBS -V\n"                                           .
      "#PBS -o $workdir/stdout\n"                           .
      "#PBS -e $workdir/stderr\n"                           .
      "#pmgroups=$mgroupcount\n"                            .
      "$plines"                                             .
      "\n"                                                  .
      "cd $workdir\n"                                       .
      "if [ -f \$PBS_O_HOME/work/aux.pbs ]; then\n"         .
      " .  \$PBS_O_HOME/work/aux.pbs\n"                     .
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

   // Create a slurm file
   function create_slurm()
   {
      $quename   = "batch";
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
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
      $slufile = "us3.slurm";
      $slupath = $slufile;
      $wall    = $this->maxwall() * 3.0;
       if ( $is_us3iab )
         $wall    = 2880.0;
      $nodes   = $this->nodes() * $mgroupcount;

      $hours   = (int)( $wall / 60 );
      $mins    = (int)( $wall % 60 );
      $ppn     = $this->grid[ $cluster ][ 'ppn' ]; 
      $ppbj    = $this->grid[ $cluster ][ 'ppbj' ]; 

      $walltime = sprintf( "%02.2d:%02.2d:00", $hours, $mins );  // 01:09:00
      $wallmins = $hours * 60 + $mins;
      $can_load = 0;
      $plines   = "";
      $mpirun   = "mpirun";
      $mpiana   = "us_mpi_analysis";

$this->message[] = "cluster=$cluster  ppn=$ppn  ppbj=$ppbj  wall=$wall";

      switch( $cluster )
      {
        case 'jetstream-local':
         $can_load = 1;
         $load1    = "mpi";
         $load2    = "qt5";
         $load3    = "ultrascan3";
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
      {  // Can use module load to set paths and environ
         $plines  = 
            "\n"                    .
            "module load $load1 \n" .
            "module load $load2 \n" .
            "module load $load3 \n" .
            "\n";
      }

      else
      {  // Can't use module load; must set paths
         $plines  = 
            "\n"                                                  .
            "export LD_LIBRARY_PATH=$libpath:\$LD_LIBRARY_PATH\n" .
            "export PATH=$path:\$PATH\n"                          .
            "\n";
      }

      $procs   = $nodes * $ppn;

      $contents = 
      "#! /bin/bash\n"                      .
      "#\n"                                 .
      "#SBATCH -p $quename\n"               .
      "#SBATCH -J US3_Job_$requestID\n"     .
      "#SBATCH -N $nodes\n"                 .
      "#SBATCH -n $ppn\n"                   .
      "#SBATCH -t $walltime\n"              .
      "#SBATCH -o $workdir/stdout\n"        .
      "#SBATCH -e $workdir/stderr\n"        .
      "$plines"                             .
      "\n"                                  .
      "cd $workdir\n"                       .
      "\n"                                  .
      "$mpirun $mpiana -walltime $wallmins" .
      " -mgroupcount $mgroupcount $tarfile\n";

      $this->data[ 'pbsfile' ] = $contents;

      $h = fopen( $slupath, "w" );
      fwrite( $h, $contents );
      fclose( $h );

      return $slufile;
   }

   // Schedule the job
   function submit_job()
   {
      date_default_timezone_set( "America/Chicago" );
 
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $is_us3iab = preg_match( "/us3iab/", $cluster );
      $no_us3iab = 1 - $is_us3iab;
      $is_jetstr = preg_match( "/jetstream/", $cluster );
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $jobid     = $this->data[ 'db' ][ 'name' ] . sprintf( "-%06d", $requestID );
      $workdir   = $this->grid[ $cluster ][ 'workdir' ] . $jobid;
      $address   = $this->grid[ $cluster ][ 'name' ];
      $port      = $this->grid[ $cluster ][ 'sshport' ]; 
      $tarfile   = sprintf( "hpcinput-%s-%s-%05d.tar",
                         $this->data['db']['host'],
                         $this->data['db']['name'],
                         $this->data['job']['requestID'] );

      // Submit job to the queue
      if ( $is_jetstr )
         $cmd   = "sbatch $workdir/us3.slurm 2>&1";
      else
         $cmd   = "/usr/bin/qsub $workdir/us3.pbs 2>&1";
      if ( $no_us3iab )
         $cmd   = "ssh -p $port -x us3@$address " . $cmd;

      $jobid = exec( $cmd, $output, $status );

      // Save the job ID
//      if ( $status == 0 )
      if ( $is_jetstr )
      {
         $parts = preg_split( "/\s+/", $output[ 0 ] );
         $this->data[ 'eprfile' ] = $parts[ 3 ];
//$this->data[ 'eprfile' ] = $jobid;
      }
      else
         $this->data[ 'eprfile' ] = rtrim( $jobid );
//      else
$this->message[] = "Job submitted; ID:" . $this->data[ 'eprfile' ] . " status=" . $status
 . " out0=" . $output[0];
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

      $cluster   = $this->data['job']['cluster_shortname'];
      $pbs       = mysql_real_escape_string( $this->data[ 'pbsfile' ] );
      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $eprfile   = $this->data['eprfile'];

      $link = mysql_connect( $dbhost, $dbusername, $dbpasswd );
 
      if ( ! $link )
      {
         $this->message[] = "Cannot open database on $dbhost\n";
         return;
      }
 
      if ( ! mysql_select_db( $dbname, $link ) ) 
      {
         $this->message[] = "Cannot change to database $dbname\n";
         return;
      }
 
      $query = "INSERT INTO HPCAnalysisResult SET "  .
               "HPCAnalysisRequestID='$requestID', " .
               "jobfile='$pbs', "                    .
               "gfacID='$eprfile' ";
      
      $result = mysql_query( $query, $link );
 
      if ( ! $result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysql_error( $link ) . "\n";
         return;
      }
 
      mysql_close( $link );

      // Insert initial data into global DB
      $gfac_link = mysql_connect( $globaldbhost, $globaldbuser, $globaldbpasswd );
 
      if ( ! $gfac_link )
      {
         $this->message[] = "Cannot open database on $globaldbhost\n";
         return;
      }
 
      if ( ! mysql_select_db( $globaldbname, $gfac_link ) ) 
      {
         $this->message[] = "Cannot change to database $globaldbname\n";
         return;
      }

      $query = "INSERT INTO analysis SET " .
               "gfacID='$eprfile', "       .
               "cluster='$cluster', "      .
               "us3_db='$dbname'";

      $result = mysql_query( $query, $gfac_link );
 
      if ( ! $result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysql_error( $gfac_link ) . "\n";
         return;
      }
 
      mysql_close( $gfac_link );

$this->message[] = "Database $dbname updated: requestID = $requestID";
   }

   function close_transport( )
   {  // Dummy function since new class functions have already closed
   }

}
?>
