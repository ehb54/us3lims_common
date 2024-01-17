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
    protected $testing = false;

    ## Submits data
    function submit()
    {
        chdir( $this->data['job']['directory'] );
        $expId = $this->createExperiment();
        if ( !$this->testing ) {
            $this->update_db( $expId );
            $this->message[] = "End of submit_airavata.php\n";
        }
    }

    ## Function to create experimemt and launch to airavata
    function createExperiment()
    {
        global $user, $class_dir;

        static $airavataWrapper;
        static $wrapper_set = false;
        
        $cluster     = $this->data[ 'job' ][ 'cluster_shortname' ];
        $limsHost    = $this->data[ 'db'  ][ 'host' ];
        ##      if ( preg_match( "/uslims.uleth/", $limsHost ) )
        ##         $limsHost    = 'demeler6.uleth.ca';
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
        $ppbj        = $this->grid[ $cluster ][ 'ppbj' ];
        ## $tnodes      = $this->nodes();
        ## $nodes       = $tnodes * $mgroupcount;
        $nodes       = $this->nodes();
        $clus_user   = 'us3';
        $clus_scrd   = 'NONE';
        $clus_acct   = 'NONE';

        ## removed juwels group account specific details
        ## left as a hint in case the need recurs in the future

        ## if ( $cluster == 'juwels' )
        ## {
        ## $clus_group  = 'cpaj1846';
        ## switch ( $dbname )
        ## {
        ## case 'uslims3_cauma3':
        ## $clus_user   = 'gorbet1';
        ## $clus_acct   = 'paj1846';
        ## break;
        ## 
        ## case 'uslims3_cauma3d':
        ## $clus_user   = 'sureshmarru1';
        ## $clus_acct   = 'paj1846';
        ## break;
        ## 
        ## case 'uslims3_Uni_KN':
        ## $clus_user   = 'schneider3';
        ## $clus_acct   = 'hkn00';
        ## break;
        ## 
        ## case 'uslims3_HHU':
        ## $clus_user   = 'jics6301';
        ## $clus_group  = 'jics63';
        ## break;
        ## 
        ## case 'uslims3_FAU':
        ## $clus_user   = 'uttinger1';
        ## $clus_acct   = 'her21';
        ## break;
        ## 
        ## case 'uslims3_JSC':
        ## $clus_user   = 'm.memon';
        ## break;
        ## 
        ## default :
        ## $clus_user   = 'gorbet1';
        ## break;
        ## }
        ## 
        ## $userdn      = str_replace( '_USER_', $user, $userdn );
        ## $clus_scrd   = '/p/scratch/' . $clus_group . '/' . $clus_user;
        ## }

        $cores      = $nodes * $ppn;
        if ( $cores < 1 )
        {
            $this->message[] = "Requested cores is zero (ns=$nodes, pp=$ppn, gc=$mgroupcount)";
        }

        $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount;
        $maxWallTime = $this->maxwall();
        if ( preg_match( "/swus/", $clus_user ) )
        {  ## Development users on Jureca limited to 6 hours wall time
            $maxWallTime = min( $maxWallTime, 360 );
        }
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

        ## removed cluster specific resets
        ## these first 2 appear to be remenants for running lims servers on jetstream1
        ## if ( preg_match( "/lims4.noval/", $uslimsVMHost ) )
        ## {
        ## $uslimsVMHost = "uslims4.aucsolutions.com";
        ## }
        ## else if ( preg_match( "/noval/", $uslimsVMHost ) )
        ## {
        ## $uslimsVMHost = "uslims3.aucsolutions.com";
        ## }
        ##      else if ( preg_match( "/uslims.uleth/", $uslimsVMHost ) )
        ##      {
        ##         $uslimsVMHost = "demeler6.uleth.ca";
        ##      }

        $memoryreq    = 0;

        ## compute memoryreq if compute_memoryreq set

        if ( isset( $this->grid[ $cluster ][ 'compute_memoryreq' ] ) ) {
            $use_mem_per_core = 2000;
            if ( isset( $this->grid[ $cluster ][ 'mempercore' ] ) ) {
                $use_mem_per_core = $this->grid[ $cluster ][ 'mempercore' ];
            }
            
            $memoryreq = (int)( ( $cores * $use_mem_per_core ) / $nodes );

            $memoryreq = (int)( $memoryreq + 999 );  ## Rounded up multiple of 1000
            $memoryreq = (int)( $memoryreq / 1000 );
            $memoryreq = (int)( $memoryreq * 1000 );
        }

        ## maximum memory limit if maxmem limit set

        if (
            isset( $this->grid[ $cluster ][ 'maxmem' ] ) &&
            $memoryreq > $this->grid[ $cluster ][ 'maxmem' ]
            ) {
            $memoryreq = $this->grid[ $cluster ][ 'maxmem' ];
        }

        if ( !$wrapper_set ) {
            $airavataWrapper = new AiravataWrapper();
            $wrapper_set     = true;
        }
            
        ##var_dump('dumperr', $uslimsVMHost, $limsUser, $exp_name, $expReqId, $clus_host, $queue, $cores, $nodes,
        ##          $mgroupcount, $maxWallTime, $clus_user, $clus_scrd, $inputTarFile, $outputDirName );

        if ( $this->testing ) {
            echo
                'testing on, submission disabled<br>'
                ;
            $this->message[] =
                "Testing\n"
                . "------------------------------\n"
                . "ppn          $ppn\n"
                . "ppbj         $ppbj\n"
                . "nodes        $nodes\n"
                . "cores        $cores\n"
                . "mgroupcount  $mgroupcount\n"
                . "maxWallTime  $maxWallTime\n"
                . "memoryreq    $memoryreq\n"
                . "------------------------------"
                ;
            
        } else {
            $expResult  = $airavataWrapper->launch_airavata_experiment( $uslimsVMHost, $limsUser,
                                                                        $exp_name, $expReqId,
                                                                        $clus_host, $queue, $cores, $nodes, $mgroupcount,
                                                                        $maxWallTime, $clus_user, $clus_scrd, $clus_acct,
                                                                        $inputTarFile, $outputDirName, $memoryreq );
        }

        $expId      = 0;

        if ( $expResult[ 'launchStatus' ] )
        {
            $expId      = $expResult[ 'experimentId' ];
            ##var_dump($expId);
            $this->message[] = "Experiment $expId created";
            $this->message[] = "    ppn=$ppn nodes=$nodes  cores=$cores";
            $this->message[] = "    uslimsVMHost=$uslimsVMHost  limsUser=$limsUser";
            $this->message[] = "    exp_name=$exp_name  expReqId=$expReqId  memoryreq=$memoryreq";
            $this->message[] = "    clus_host=$clus_host  queue=$queue  clus_user=$clus_user  clus_scrd=$clus_scrd";
            $this->message[] = "    mgroupcount=$mgroupcount  maxWallTime=$maxWallTime";
            $this->message[] = "    inputTarFile=$inputTarFile";
            $this->message[] = "    outputDirName=$outputDirName";
        }
        else
        {
            if ( !$this->testing ) {
                $this->message[] = "Experiment creation failed: "
                    . $expResult[ 'message' ];
                echo "err-message=" . $expResult['message'];
            }
        }

        return $expId;
    }

    function close_transport( )
    {  ## Dummy function since new class functions have already closed
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
        $epr       = isset( $this->data[ 'eprfile' ] )
            ?  $this->data[ 'eprfile' ] : '';
        $xml       = isset( $this->data[ 'jobxmlfile' ] )
            ?  $this->data[ 'jobxmlfile' ] : '';

        $link      = mysqli_connect( $host, $dbusername, $dbpasswd, $dbname );

        if ( !$link )
        {
            $this->message[] = "Cannot open database on $host to $dbname\n";
            return;
        }

        $gfacID    = $expId;
        if ( strpos( $gfacID, "Error report" ) !== false  ||  $gfacID == '' )
        {  ## "...Error..." or Nothing returned
            $gfacID    = "US3-" . basename( $this->data['job']['directory'] );
        }

        $query  = "INSERT INTO HPCAnalysisResult SET " .
            "HPCAnalysisRequestID='$requestID', " .
            "queueStatus='$status', " .
            "jobfile='" . mysqli_real_escape_string($link,$xml) . "', " .
            "gfacID='$gfacID'";

        $result = mysqli_query( $link, $query );

        if ( !$result )
        {
            $this->message[] = "Invalid query:\n$query\n" . mysqli_error($link) . "\n";
            return;
        }

        mysqli_close( $link );

        ## Update global db
        $gfac_link = mysqli_connect( $host, $globaldbuser, $globaldbpasswd, $globaldbname );

        if ( !$gfac_link )
        {
            $this->message[] = "Cannot open global database on $host to $globaldbname\n";
            return;
        }

        $cluster = $this->data['job']['cluster_shortname'];

        $query   = "INSERT INTO analysis SET " .
            "gfacID='$gfacID', " .
            "cluster='$cluster', " .
            "status='SUBMITTED', " .
            "us3_db='$dbname'";
        $result  = mysqli_query( $gfac_link, $query );

        if ( !$result )
        {
            $this->message[] = "Invalid query:\n$query\n" . mysqli_error($gfac_link) . "\n";
            return;
        }

        mysqli_close( $gfac_link );
        $this->message[] = "Global database $globaldbname updated: gfacID = $gfacID";

        $cmd = "php /home/us3/lims/bin/jobmonitor/jobmonitor.php $dbname $gfacID $requestID 2>&1";
        exec( $cmd, $null, $status );
        $this->message[] = "$cmd status=$status";
        if($status != 0) {
            $this->message[] = "  ++++ output=$output[0]";
        }
    }
}
