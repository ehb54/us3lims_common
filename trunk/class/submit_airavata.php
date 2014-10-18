<?php
/*
 * submit_airavata.php
 *
 * Submits an analysis using the airvata thrift API method
 *
 */
require_once $class_dir . 'jobsubmit_aira.php';

include 'thrift_includes.php';

use Airavata\Model\Workspace\Experiment\Experiment;
use Airavata\Model\Workspace\Experiment\DataObjectType;
use Airavata\Model\Workspace\Experiment\UserConfigurationData;
use Airavata\Model\Workspace\Experiment\ComputationalResourceScheduling;
use Airavata\Model\Workspace\Experiment\AdvancedOutputDataHandling;
use Airavata\Model\Workspace\Experiment\DataType;

class submit_airavata extends airavata_jobsubmit
{
 
   // Submits data
   function submit()
   {
      global $globaldbname, $globaldbhost,$filename,$airavataclient,$transport;
      chdir( $this->data['job']['directory'] );
      $expId = $this->createExperiment();
      $airavataclient->launchExperiment($expId, 'airavataToken');
      $this->update_db($expId);
      $transport->close();
      $this->message[] = "End of submit_airavata.php\n";
   }

   // Function to create the job xml
   function createExperiment()
   {
      global $globaldbname, $globaldbhost,$filename,$airavataclient;
      $cluster     = $this->data[ 'job' ][ 'cluster_shortname' ];
      $hostname    = $this->grid[ $cluster ][ 'name' ];
      $workdir     = $this->grid[ $cluster ][ 'workdir' ];
      $userdn      = $this->grid[ $cluster ][ 'userdn' ];
      $queue       = $this->data[ 'job' ][ 'cluster_queue' ];
      $dbname      = $this->data[ 'db'  ][ 'name' ];
      $mgroupcount = min( $this->max_mgroupcount() ,
                          $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] );

      $ppn         = $this->grid[ $cluster ][ 'ppn' ];
      $nodes       = $this->nodes() * $mgroupcount;
      $cores       = $nodes * $ppn;

      $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount;
      $maxWallTime = $this->maxwall();

      $this->data[ 'cores'       ] = $cores;
      $this->data[ 'nodes'       ] = $nodes;
      $this->data[ 'ppn'         ] = $ppn;
      $this->data[ 'maxWallTime' ] = $maxWallTime;
      $this->data[ 'dataset' ][ 'status' ] = 'queued';

      $us3_appId   = 'ultrascan_e76ab5cf-79f6-44df-a244-10a734183fec';
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
      $exp_name    = 'US3-AIRA';

      $cmRST = new ComputationalResourceScheduling();
      switch($hostname)
      {
         case 'trestles.sdsc.edu':
            $hostname = "trestles.sdsc.xsede.org_1ccc526f-ab74-4a5a-970a-c464cb9def5a";
            break;
         case 'stampede.tacc.xsede.org':
            $hostname = "stampede.tacc.xsede.org_af57850b-103b-49a1-aab2-27cb070d3bd9";
            break;
         case 'lonestar.tacc.teragrid.org':
            $hostname = "lonestar.tacc.teragrid.org_2e0273bc-324b-419b-9786-38a360d44772";
            break;
         case 'alamo.uthscsa.edu':
            $hostname = "alamo.uthscsa.edu_7b6cf99a-af2e-4e8b-9eff-998a5ef60fe5";
            break;
         default:
            echo "set the right host" . $hostname;
            break;
      }
      $cmRST->resourceHostId = $hostname;
      $cmRST->totalCPUCount = $ppn;
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

      /* Experiment input and output data. */
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

      $exOutputs = array($output);

      $user = "ultrascan";
      $proj = "ultrascan_41574ef5-b054-4d03-ab20-2cfe768d5096";

      $experiment = new Experiment();
      $experiment->projectID = $proj;
      $experiment->userName = $user;
      $experiment->name = $exp_name;
      $experiment->applicationId = $us3_appId;
      $experiment->userConfigurationData = $userConfigurationData;
      $experiment->experimentInputs = $exInputs;
      $experiment->experimentOutputs = $exOutputs;
           
      $expId = $airavataclient->createExperiment($experiment);
      $this->message[] = "Experiment $expId created";
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
