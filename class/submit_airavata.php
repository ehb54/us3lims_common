<?php
/*
 * submit_airavata.php
 *
 * Submits an analysis using the airvata thrift API method
 *
 */
require_once $class_dir . 'jobsubmit_aira.php';

include 'thrift_includes.php';
use Airavata\Model\Workspace\Experiment\ComputationalResourceScheduling;
use Airavata\Model\Workspace\Experiment\UserConfigurationData;
use Airavata\Model\Workspace\Experiment\AdvancedOutputDataHandling;
use Airavata\Model\Workspace\Experiment\Experiment;
use Airavata\Model\AppCatalog\AppInterface\InputDataObjectType;

class submit_airavata extends airavata_jobsubmit
{
 
   // Submits data
   function submit()
   {
      global $globaldbname, $globaldbhost,$filename,$airavataclient,$transport;
      chdir( $this->data['job']['directory'] );
      $expId = $this->createExperiment();
      $airavataclient->launchExperiment($expId, '00409bfe-8e5f-4e50-b8eb-138bf0158e90');
      $this->update_db($expId);
      $transport->close();
      $this->message[] = "End of submit_airavata.php\n";
   }

   // Function to create the job xml
   function createExperiment()
   {
      global $globaldbname, $globaldbhost,$filename,$airavataclient,$class_dir;
      $cluster     = $this->data[ 'job' ][ 'cluster_shortname' ];
      $hostname    = $this->grid[ $cluster ][ 'name' ];
      $workdir     = $this->grid[ $cluster ][ 'workdir' ];
      $userdn      = $this->grid[ $cluster ][ 'userdn' ];
      $queue       = $this->data[ 'job' ][ 'cluster_queue' ];
      $dbname      = $this->data[ 'db'  ][ 'name' ];
      $mgroupcount = min( $this->max_mgroupcount() ,
                          $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] );

      $ppn         = $this->grid[ $cluster ][ 'ppn' ];
      $tnodes      = $this->nodes();
      $nodes       = $tnodes * $mgroupcount;

      if ( $cluster == 'alamo'  &&  $nodes > 16 )
      {  // If more nodes needed on Alamo than available, try to adjust
         $hnode      = (int)( $nodes / 2 );
         $tnode      = $hnode * 2;   // Test that nodes is an even number
         if ( $tnode == $nodes )
         {
            $nodes      = $hnode;    // Half the number of nodes
            $ppn       *= 2;         // Twice the processors per node
            $this->grid[ $cluster ][ 'ppn' ] = $ppn;
         }
      }

      $cores       = $nodes * $ppn;
      if( $cores < 1 )
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

      $us3_appId   = 'Ultrascan_856df1d5-944a-49d3-a476-d969e57a8f37';
      $host        = $this->data[ 'db' ][ 'host' ];
      $tarFilename = sprintf( "hpcinput-%s-%s-%05d.tar", $host,
                              $this->data['db']['name'],
                              $this->data['job']['requestID'] );
      $thishost    = $host;
      if ( $thishost == 'localhost' )
         $thishost    = gethostname();
      $dirPath     = "file://raminder@$thishost:/" . getcwd();
      $input_data  = $dirPath . "/" . $tarFilename;
      $output_data = 'analysis.tar';

      if ( preg_match( "/class_devel/", $class_dir ) )
         $exp_name    = 'US3-ADEV';
      else if ( preg_match( "/class/", $class_dir ) )
         $exp_name    = 'US3-AIRA';
      else
         $exp_name    = 'US3-AIRX';

      $cmRST = new ComputationalResourceScheduling();
      switch($hostname)
      {
         case 'stampede.tacc.xsede.org':
            $hostname = "stampede.tacc.xsede.org_28c4bf70-ed52-4f87-b481-31a64a1f5808";
            break;
         case 'lonestar.tacc.teragrid.org':
            $hostname = "lonestar.tacc.utexas.edu_6d62fa0c-a9b1-4414-a76a-a4e2cbd9d290";
            break;
         case 'alamo.uthscsa.edu':
            $hostname = "alamo.uthscsa.edu_a591c220-345b-4f67-9337-901b76360df6";
            break;
         case 'comet.sdsc.edu':
            $hostname = "comet-ln1.sdsc.edu_0bb9bd78-b5e7-40cf-a5dd-fd6f8bd6b537";
            break;
         case 'gordon.sdsc.edu':
            $hostname = "gordon.sdsc.edu_9ee43a5a-cee7-4efd-996b-4fc11662a726";
            break;
         default:
            echo "set the right host" . $hostname;
            break;
      }
      $cmRST->resourceHostId = $hostname;
      $cmRST->totalCPUCount = $cores;
      $cmRST->nodeCount = $nodes;
      $cmRST->numberOfThreads = 0;
      $cmRST->queueName = $queue;
      $cmRST->wallTimeLimit = $maxWallTime;
      $cmRST->jobStartTime = 0;
      $cmRST->totalPhysicalMemory = 0;

      $cmRS = $cmRST;
      $userConfigurationData = new UserConfigurationData();
      $userConfigurationData->airavataAutoSchedule = 0;
      $userConfigurationData->overrideManualScheduledParams = 0;
      $userConfigurationData->computationalResourceScheduling = $cmRS;

      $advHandling = new AdvancedOutputDataHandling();
      $advHandling->outputDataDir = $dirPath;
      $userConfigurationData->advanceOutputDataHandling = $advHandling;
      //var_dump($userConfigurationData);
      //Exp Inputs 
      $applicationInputs = $airavataclient->getApplicationInputs($us3_appId);
      foreach ($applicationInputs as $applicationInput){
       if($applicationInput->name =='input'){
	$applicationInput->value = $input_data;
	}
 	else if($applicationInput->name =='walltime'){
	$applicationInput->value = "-walltime=" . $maxWallTime;
        }
	else if($applicationInput->name =='mgroupcount'){
	$applicationInput->value = "-mgroupcount=" . $mgroupcount;
        }
	}
	$applicationOutputs = $airavataclient->getApplicationOutputs($us3_appId);
      /* Experiment input and output data. 
      $input = new DataObjectType();
      $input->key = "input";
      $input->value = $input_data;
      $input->type = DataType::URI;

      $input1 = new DataObjectType();
      $input1->key = "walltime";
      $input1->value = "-walltime=" . $maxWallTime;
      $input1->type = DataType::STRING;

      $input2 = new DataObjectType();
      $input2->key = "mgroupcount";
      $input2->value = "-mgroupcount=" . $mgroupcount;
      $input2->type = DataType::STRING;

      $exInputs = array($input,$input1,$input2);

      $output = new DataObjectType();
      $output->key = "output";
      $output->type = DataType::URI;

      $output1 = new DataObjectType();
      $output1->key = "stdout";
      $output1->type = DataType::STDOUT;

      $output2 = new DataObjectType();
      $output2->key = "stderr";
      $output2->type = DataType::STDERR;

      $exOutputs = array($output);*/

      $user = "us3";
      $proj = "ultrascan_cd0900d4-2b4d-4919-9aa2-b7649ea1f391";

      $experiment = new Experiment();
      $experiment->projectID = $proj;
      $experiment->userName = $user;
      $experiment->name = $exp_name;
      $experiment->applicationId = $us3_appId;
      $experiment->userConfigurationData = $userConfigurationData;
      $experiment->experimentInputs = $applicationInputs;
      $experiment->experimentOutputs = $applicationOutputs;
      //var_dump($experiment);     
      $expId = $airavataclient->createExperiment('ultrascan',$experiment);
      //var_dump($expId);
      $this->message[] = "Experiment $expId created";
$this->message[] = "    ppn=$ppn  tnodes=$tnodes  nodes=$nodes  cores=$cores";
      return $expId;
   }

   function update_db($expId)
   {
      global $dbusername;
      global $dbpasswd;
      global $globaldbname;
      global $globaldbuser;
      global $globaldbpasswd;

      $requestID  = $this->data[ 'job' ][ 'requestID' ];
      $dbname     = $this->data[ 'db' ][ 'name' ];
      $host       = $this->data[ 'db' ][ 'host' ];
      $user       = $this->data[ 'db' ][ 'user' ];
      $status     = $this->data[ 'dataset' ][ 'status' ];
      $epr        = $this->data[ 'eprfile' ];
      $xml        = $this->data[ 'jobxmlfile' ];

      $link = mysql_connect( $host, $dbusername, $dbpasswd );

      if ( ! $link )
      {
         $this->message[] = "Cannot open database on $host\n";
         return;
      }

      if ( ! mysql_select_db( $dbname, $link ) )
      {
         $this->message[] = "Cannot change to database $dbname\n";
         return;
      }

      $gfacID = $expId;
      if ( strpos( $gfacID, "Error report" ) !== false  ||
           $gfacID == '' ) // "...Error..." or Nothing returned
         $gfacID      = "US3-" . basename( $this->data['job']['directory'] );

      $query = "INSERT INTO HPCAnalysisResult SET "                   .
               "HPCAnalysisRequestID='$requestID', "                  .
               "queueStatus='$status', "                              .
               "jobfile='" . mysql_real_escape_string( $xml ) . "', " .
               "gfacID='$gfacID'";
      
      $result = mysql_query( $query, $link );
 
      if ( ! $result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysql_error( $link ) . "\n";
         return;
      }
 
      mysql_close( $link );

      // Update global db
      $gfac_link = mysql_connect( $host, $globaldbuser, $globaldbpasswd );

      if ( ! $gfac_link )
      {
         $this->message[] = "Cannot open global database on $host\n";
         return;
      }
 
      if ( ! mysql_select_db( $globaldbname, $gfac_link ) ) 
      {
         $this->message[] = "Cannot change to global database $globaldbname\n";
         return;
      }

      $cluster = $this->data['job']['cluster_shortname'];

      $query   = "INSERT INTO analysis SET " .
                 "gfacID='$gfacID', "        .
                 "cluster='$cluster', "      .
                 "status='SUBMITTED', "      .
                 "us3_db='$dbname'"; 
      $result  = mysql_query( $query, $gfac_link );
 
      if ( ! $result )
      {
         $this->message[] = "Invalid query:\n$query\n" . mysql_error( $gfac_link ) . "\n";
         return;
      }
 
      mysql_close( $gfac_link );
      $this->message[] = "Global database $globaldbname updated: gfacID = $gfacID";
   }

}
?>
