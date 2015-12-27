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

      $savedir   = getcwd();
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
      $ssladr    = " -x us3@$address";
      $sshcmd    = "ssh -p $port ";
      $scpcmd    = "scp -P $port ";
      $cmd       = "mkdir -p $workdir 2>&1";
      if ( $no_us3iab )
         $cmd       = $sshcmd . $ssladr . " " . $cmd;
      exec( $cmd, $output, $status );

      // Copy tar file
      if ( $no_us3iab )
         $cmd       = $scpcmd . "$tarfile " . $ssladr . ":$workdir 2>&1";
      else
         $cmd       = "cp $tarfile $workdir/ 2>&1";
      exec( $cmd, $output, $status );

      //  Create and copy pbs file
      $pbsfile   = $this->create_pbs();
      if ( $no_us3iab )
         $cmd       = $scpcmd . "$pbsfile " . $ssladr . ":$workdir 2>&1";
      else
         $cmd       = "cp $pbsfile $workdir/ 2>&1";
      exec( $cmd, $output, $status );
      
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
      if ( isset( $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] ) )
      {
         $mgroupcount  = min( $this->max_mgroupcount() ,
                          $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] );
      }
      $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount;

      $pbsfile = "us3.pbs";
      $wall    = $this->maxwall() * 3.0;
      $nodes   = $this->nodes() * $mgroupcount;

      $hours   = (int)( $wall / 60 );
      $mins    = (int)( $wall % 60 );
      $ppn     = $this->grid[ $cluster ][ 'ppn' ]; 

      $walltime = sprintf( "%02.2d:%02.2d:00", $hours, $mins );  // 01:09:00
      $wallmins = $hours * 60 + $mins;
      $havepl   = 1;
      $mpirun   = "mpirun";
      $mpiana   = "us_mpi_analysis";

      switch( $cluster )
      {
        case 'bcf-local':
          $libpath  = "/share/apps64/openmpi/lib";
          $path     = "/share/apps64/openmpi/bin";
          break;

        case 'jacinto-local':
          $libpath  = "/share/apps/openmpi/lib";
          $path     = "/share/apps/openmpi/bin";
          break;

        case 'alamo-local':
          $havepl   = false;
          $load1    = "intel/2015/64";
          $load2    = "openmpi/intel/1.8.4";
          $load3    = "qt4/4.8.6";
          $load4    = "ultrascan3/3.3";
          break;

        case 'us3iab-local':
        case 'us3iab-node0':
        case 'us3iab-node1':
          $havepl   = 1;
          $load1    = "";
          $load2    = "";
          $load3    = "";
          $load4    = "";
          if ( $nodes > 1 )
          {
             $ppn      = $nodes * $ppn;
             $ppn      = min( $ppn, 64 );
          }
          $us3cdir  = exec( "ls -d ~us3/cluster" );
          $libpath  = "$us3cdir/lib:/usr/lib64/openmpi/lib:/opt/qt4/lib";
          $path     = "$us3cdir/bin:/usr/lib64/openmpi/bin";
          $ppn      = max( $ppn, 8 );
          $nodes    = 1;
          break;

        default:
          $libpath  = "/share/apps/openmpi/lib:/share/apps/qt4/lib";
          $path     = "/share/apps/openmpi/bin";
          $ppn      = 2;
          break;
      }

      if ( $havepl != 0 )
      {
         $plines   = 
            "\n"                                                  .
            "export LD_LIBRARY_PATH=$libpath:\$LD_LIBRARY_PATH\n" .
            "export PATH=$path:\$PATH\n"                          .
            "\n";
         $dlines   = '';
      }

      else
      {
         if ( $no_us3iab )
            $plines  = 
               "\n"                    .
               "module load $load1 \n" .
               "module load $load2 \n" .
               "module load $load3 \n" .
               "module load $load4 \n" .
               "\n";
         else
            $plines  = 
               "\n"                    .
               "module load $load4 \n" .
               "\n";
         $dlines  = "";
      }

      $procs    = $nodes * $ppn;

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
      "$dlines"                                             .
      "\n"                                                  .
      "cd $workdir\n"                                       .
      "$mpirun -np $procs $mpiana -walltime $wallmins -pmgc $mgroupcount $tarfile\n\n";

      $this->data[ 'pbsfile' ] = $contents;

      $h = fopen( $pbsfile, "w" );
      fwrite( $h, $contents );
      fclose( $h );

      return $pbsfile;
   }

   // Schedule the job
   function submit_job()
   {
      date_default_timezone_set( "America/Chicago" );
 
      $cluster   = $this->data[ 'job' ][ 'cluster_shortname' ];
      $is_us3iab = preg_match( "/us3iab/", $cluster );
      $no_us3iab = 1 - $is_us3iab;
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
      $cmd       = "qsub $workdir/us3.pbs 2>&1";
      if ( $no_us3iab )
         $cmd       = "ssh -p $port -x us3@$address " . $cmd;
      $jobid     = exec( $cmd, $output, $status );

      // Save the job ID
      $this->data[ 'eprfile' ] = rtrim( $jobid );
$this->message[] = "Job submitted; ID:" . $this->data[ 'eprfile' ] . " status=" . $status;
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
}
?>
