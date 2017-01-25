<?php
/*
 * submit_airavata.php
 *
 * Submits an analysis using the airvata thrift API method
 *
 */
require_once(dirname(__FILE__) . '/jobsubmit_aira.php');
require_once(dirname(__FILE__) . '/ultrascan-airavata-bridge/AiravataWrapper.php');
use SCIGAP\AiravataWrapper;

class submit_airavata extends airavata_jobsubmit
{

   // Submits data
   function submit()
   {
      chdir( $this->data['job']['directory'] );
      $expId = $this->createExperiment();
      $this->update_db( $expId );
      $this->message[] = "End of submit_airavata.php\n";
   }

   // Function to create experimemt and launch to airavata
   function createExperiment()
   {
      global $user, $class_dir;
      $cluster     = $this->data[ 'job' ][ 'cluster_shortname' ];
      $limsHost    = $this->data[ 'db'  ][ 'host' ];
      if ( isset( $this->data[ 'db' ][ 'user_id' ] ) )
         $limsUser    = $this->data[ 'db' ][ 'user_id' ];
      else
         $limsUser    = 'Gary_Gorbet_1234';
      $clus_host   = $this->grid[ $cluster ][ 'name' ];
      $userdn      = $this->grid[ $cluster ][ 'userdn' ];
      $queue       = $this->data[ 'job'    ][ 'cluster_queue' ];
      $dbname      = $this->data[ 'db'     ][ 'name' ];
      $mgroupcount = min( $this->max_mgroupcount(),
            $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] );
      $ppn         = $this->grid[ $cluster ][ 'ppn' ];
      $tnodes      = $this->nodes();
      $nodes       = $tnodes * $mgroupcount;
      $clus_user   = 'us3';
      $clus_scrd   = 'NONE';

      if ( $cluster == 'jureca' )
      {
         $clus_group  = 'ultrascn';
         switch ( $dbname )
         {
            case 'uslims3_cauma3':
               $clus_user   = 'swus1';
               break;

            case 'uslims3_cauma3d':
               $clus_user   = 'swus2';
               break;

            case 'uslims3_Uni_KN':
               $clus_user   = 'hkn001';
               $clus_group  = 'hkn00';
               break;

            case 'uslims3_HHU':
               $clus_user   = 'jics6301';
               $clus_group  = 'jics63';
               break;

            case 'uslims3_FAU':
               $clus_user   = 'her210';
               $clus_group  = 'her21';
               break;

            default :
               $clus_user   = 'swus1';
               break;
         }

         $userdn      = str_replace( '_USER_', $user, $userdn );
         $clus_scrd   = '/work/$clus_group/$clus_user/airavata-workdirs';
      }

      if ( $cluster == 'alamo'  &&  $nodes > 16 )
      {  // If more nodes needed on Alamo than available, try to adjust
         $hnode      = (int)($nodes / 2);
         $tnode      = $hnode * 2;   // Test that nodes is an even number
         if ( $tnode == $nodes )
         {
            $nodes      = $hnode;    // Half the number of nodes
            $ppn       *= 2;         // Twice the processors per node
            $this->grid[ $cluster ][ 'ppn' ] = $ppn;
         }
      }
      $cores      = $nodes * $ppn;
      if ( $cores < 1 )
      {
         $this->message[] = "Requested cores is zero (ns=$nodes, pp=$ppn, n0=$tnodes, gc=$mgroupcount)";
      }

      $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount;
      $maxWallTime = $this->maxwall();
      $this->data[ 'cores'       ] = $cores;
      $this->data[ 'nodes'       ] = $nodes;
      $this->data[ 'ppn'         ] = $ppn;
      $this->data[ 'maxWallTime' ] = $maxWallTime;
      $this->data[ 'dataset' ][ 'status' ] = 'queued';
      $tarFilename = sprintf( "hpcinput-%s-%s-%05d.tar", $limsHost,
                              $this->data[ 'db'  ][ 'name' ],
                              $this->data[ 'job' ][ 'requestID' ] );

      $workingDirPath = $this->data[ 'job' ][ 'directory' ];
      $inputTarFile   = $workingDirPath . $tarFilename;
      $outputDirName  = basename( $workingDirPath );

      if ( preg_match( "/class_devel/", $class_dir ) )
         $exp_name   = 'US3-ADEV';
      else if ( preg_match( "/class/", $class_dir ) )
         $exp_name   = 'US3-AIRA';
      else
         $exp_name   = 'US3-AIRX';

      $expReqId   = sprintf( "%s-%05d",
                             $this->data[ 'db'  ][ 'name' ],
                             $this->data[ 'job' ][ 'requestID' ] );

      $uslimsVMHost = gethostname();

      $airavataWrapper = new AiravataWrapper();
//var_dump( $uslimsVMHost, $limsUser, $exp_name, $expReqId, $clus_host, $queue, $cores, $nodes,
//          $mgroupcount, $maxWallTime, $clus_user, $clus_scrd, $inputTarFile, $outputDirName );

      $expResult  = $airavataWrapper->launch_airavata_experiment( $uslimsVMHost, $limsUser,
                       $exp_name, $expReqId,
                       $clus_host, $queue, $cores, $nodes, $mgroupcount,
                       $maxWallTime, $clus_user, $clus_scrd,
                       $inputTarFile, $outputDirName );
//                       $maxWallTime, $clus_user,

      $expId      = 0;

      if ( $expResult[ 'launchStatus' ] )
      {
         $expId      = $expResult[ 'experimentId' ];
         //var_dump($expId);
         $this->message[] = "Experiment $expId created";
         $this->message[] = "    ppn=$ppn  tnodes=$tnodes  nodes=$nodes  cores=$cores";
         $this->message[] = "    uslimsVMHost=$uslimsVMHost  limsUser=$limsUser";
         $this->message[] = "    exp_name=$exp_name  expReqId=$expReqId";
         $this->message[] = "    clus_host=$clus_host  queue=$queue  clus_user=$clus_user";
         $this->message[] = "    mgroupcount=$mgroupcount  maxWallTime=$maxWallTime";
         $this->message[] = "    inputTarFile=$inputTarFile";
         $this->message[] = "    outputDirName=$outputDirName";
      }
      else
      {
         $this->message[] = "Experiment creation failed: "
                            . $expResult[ 'message' ];
echo "err-message=" . $expResult['message'];
      }

      return $expId;
   }

   function close_transport( )
   {  // Dummy function since new class functions have already closed
   }

   function update_db( $expId )
   {
      global $dbusername;
      global $dbpasswd;
      global $globaldbname;
      global $globaldbuser;
      global $globaldbpasswd;

      $requestID = $this->data[ 'job' ][ 'requestID' ];
      $dbname    = $this->data[ 'db' ][ 'name' ];
      $host      = $this->data[ 'db' ][ 'host' ];
      $user      = $this->data[ 'db' ][ 'user' ];
      $status    = $this->data[ 'dataset' ][ 'status' ];
      $epr       = $this->data[ 'eprfile' ];
      $xml       = $this->data[ 'jobxmlfile' ];

      $link      = mysql_connect( $host, $dbusername, $dbpasswd );

      if ( !$link )
      {
         $this->message[] = "Cannot open database on $host\n";
         return;
      }

      if ( !mysql_select_db( $dbname, $link ) )
      {
         $this->message[] = "Cannot change to database $dbname\n";
         return;
      }

      $gfacID    = $expId;
      if ( strpos( $gfacID, "Error report" ) !== false  ||  $gfacID == '' )
      {  // "...Error..." or Nothing returned
         $gfacID    = "US3-" . basename( $this->data['job']['directory'] );
      }

      $query  = "INSERT INTO HPCAnalysisResult SET " .
                "HPCAnalysisRequestID='$requestID', " .
                "queueStatus='$status', " .
                "jobfile='" . mysql_real_escape_string($xml) . "', " .
                "gfacID='$gfacID'";

      $result = mysql_query( $query, $link );

      if ( !$result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysql_error($link) . "\n";
         return;
      }

      mysql_close( $link );

      // Update global db
      $gfac_link = mysql_connect( $host, $globaldbuser, $globaldbpasswd );

      if ( !$gfac_link )
      {
         $this->message[] = "Cannot open global database on $host\n";
         return;
      }

      if ( !mysql_select_db( $globaldbname, $gfac_link ) )
      {
         $this->message[] = "Cannot change to global database $globaldbname\n";
         return;
      }

      $cluster = $this->data['job']['cluster_shortname'];

      $query   = "INSERT INTO analysis SET " .
                 "gfacID='$gfacID', " .
                 "cluster='$cluster', " .
                 "status='SUBMITTED', " .
                 "us3_db='$dbname'";
      $result  = mysql_query( $query, $gfac_link );

      if ( !$result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysql_error($gfac_link) . "\n";
         return;
      }

      mysql_close( $gfac_link );
      $this->message[] = "Global database $globaldbname updated: gfacID = $gfacID";
   }

}

?>
