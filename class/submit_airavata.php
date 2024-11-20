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
    protected $testing      = false;

    protected $debug_arrays = [
        "grid"              => false
        ,"data"             => false
        ,"clusters"         => false
        ,"clusters_encoded" => false
        ,"exp_result"       => false
        ];

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
        if ( isset( $this->data[ 'db' ][ 'user_id' ] ) )
            $limsUser    = $this->data[ 'db' ][ 'user_id' ];
        else
            $limsUser    = 'Gary_Gorbet_1234';

        ## not used / deprecated
        # $userdn      = $this->grid[ $cluster ][ 'userdn' ];
        ## should come from grid, not job
        # $queue       = $this->data[ 'job'    ][ 'cluster_queue' ];
        ## note needs $mgroupcount defined first
        ## $tnodes      = $this->nodes();
        ## $nodes       = $tnodes * $mgroupcount;

        ## arrays for metascheduler values

        $clus_host   = [];
        $queue       = [];
        $ppn         = [];
        $ppbj        = [];
        $nodes       = [];
        $mgroupcount = [];
        $cores       = [];
        $maxWallTime = [];
        $memoryreq   = [];

        if ( array_key_exists( 'clusters', $this->grid[ $cluster ] ) ) {
            foreach ( $this->grid[ $cluster ][ 'clusters' ] as $v ) {
                if ( array_key_exists( $v, $this->grid ) ) {
                    $clus_host[]   = $this->grid[ $v ][ 'name' ];
                    $queue[]       = $this->grid[ $v ][ 'queue' ];
                    $ppn[]         = $this->grid[ $v ][ 'ppn' ];
                    $ppbj[]        = $this->grid[ $v ][ 'ppbj' ];

                    $nodes[]       = $this->nodes( $v );
                    $mgroupcount[] = min( $this->max_mgroupcount( $v ),
                                          $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] );
                    $cores[]       = end( $nodes ) * end( $ppn );

                    ## compute memoryreq if compute_memoryreq set

                    $use_memoryreq = 0;

                    if ( isset( $this->grid[ $v ][ 'compute_memoryreq' ] ) ) {
                        $use_mem_per_core = 2000;
                        if ( isset( $this->grid[ $v ][ 'mempercore' ] ) ) {
                            $use_mem_per_core = $this->grid[ $v ][ 'mempercore' ];
                        }

                        $use_memoryreq = (int)( ( end( $cores ) * $use_mem_per_core ) / end($nodes) );

                        $use_memoryreq = (int)( $use_memoryreq + 999 );  ## Rounded up multiple of 1000
                        $use_memoryreq = (int)( $use_memoryreq / 1000 );
                        $use_memoryreq = (int)( $use_memoryreq * 1000 );
                    }

                    ## maximum memory limit if maxmem limit set

                    if (
                        isset( $this->grid[ $v ][ 'maxmem' ] ) &&
                        $use_memoryreq > $this->grid[ $v ][ 'maxmem' ]
                        ) {
                        $use_memoryreq = $this->grid[ $v ][ 'maxmem' ];
                    }

                    $memoryreq[]   = $use_memoryreq;

                    $maxWallTime[] = $this->maxwall( $v );
                }
            }
        } else {

            # one grid target specified

            $clus_host[]   = $this->grid[ $cluster ][ 'name' ];
            $queue[]       = $this->grid[ $cluster ][ 'queue' ];
            $ppn[]         = $this->grid[ $cluster ][ 'ppn' ];
            $ppbj[]        = $this->grid[ $cluster ][ 'ppbj' ];
            $nodes[]       = $this->nodes( $cluster );
            $mgroupcount[] = min( $this->max_mgroupcount( $cluster ),
                                  $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] );
            $cores[]       = end( $nodes ) * end( $ppn );

            ## compute memoryreq if compute_memoryreq set

            $use_memoryreq = 0;

            if ( isset( $this->grid[ $cluster ][ 'compute_memoryreq' ] ) ) {
                $use_mem_per_core = 2000;
                if ( isset( $this->grid[ $cluster ][ 'mempercore' ] ) ) {
                    $use_mem_per_core = $this->grid[ $cluster ][ 'mempercore' ];
                }

                $use_memoryreq = (int)( ( end( $cores ) * $use_mem_per_core ) / end($nodes) );

                $use_memoryreq = (int)( $use_memoryreq + 999 );  ## Rounded up multiple of 1000
                $use_memoryreq = (int)( $use_memoryreq / 1000 );
                $use_memoryreq = (int)( $use_memoryreq * 1000 );
            }

            ## maximum memory limit if maxmem limit set

            if (
                isset( $this->grid[ $cluster ][ 'maxmem' ] ) &&
                $use_memoryreq > $this->grid[ $cluster ][ 'maxmem' ]
                ) {
                $use_memoryreq = $this->grid[ $cluster ][ 'maxmem' ];
            }

            $memoryreq[]   = $use_memoryreq;

            $maxWallTime[] = $this->maxwall( $cluster );
        }

        # global
        $dbname      = $this->data[ 'db'     ][ 'name' ];

        $clus_user   = 'us3';
        $clus_scrd   = 'NONE';
        $clus_acct   = 'NONE';

        if ( array_key_exists( 'grid', $this->debug_arrays ) &&
            $this->debug_arrays[ 'grid' ] ) {
            $this->message[] = "grid:" . json_encode( $this->grid, JSON_PRETTY_PRINT );
        }

        if ( array_key_exists( 'data', $this->debug_arrays ) &&
            $this->debug_arrays[ 'data' ] ) {
            $this->message[] = "data:" . json_encode( $this->data, JSON_PRETTY_PRINT );
        }

        ## build $computeClusters array

        $computeClusters = [];

        for ( $i = 0; $i < count( $clus_host ); ++$i ) {
            if ( $cores[$i] < 1 ) {
                $this->message[] = "Requested cores for $clus_host[$i] is zero (ns=$nodes[$i], pp=$ppn[$i], gc=$mgroupcount[$i])";
            } else {
                $computeClusters[] =
                    [
                     "name"                      => $clus_host  [$i]
                     ,"queue"                    => $queue      [$i]
                     ,"cores"                    => $cores      [$i]
                     ,"nodes"                    => $nodes      [$i]
                     ,"mGroupCount"              => $mgroupcount[$i]
                     ,"wallTime"                 => $maxWallTime[$i]
                     ,"clusterUserName"          => $clus_user
                     ,"clusterScratch"           => $clus_scrd
                     #,"clusterScratch"           => "/expanse/lustre/scratch/us3/temp_project/airavata-workingdirs"
                     ,"clusterAllocationAccount" => $clus_acct
                     #,"clusterAllocationAccount" => "uot111"
                     #,"extimatedMaxWallTime"     => $maxWallTime[$i]
                     ,"memreq"                   => $memoryreq  [$i]
                    ]
                    ;
            }
        }

        if ( !count( $computeClusters ) ) {
            $this->message[] = "No compute resource available";
        }


        ## does this data get written somewhere?
        ## could be a major complication if contained in input
        ## more likely goes into HPCAnalysisRequest or Result (?)

        $this->data[ 'job' ][ 'mgroupcount' ] = $mgroupcount[0];
        $this->data[ 'cores'       ]          = $cores[0];
        $this->data[ 'nodes'       ]          = $nodes[0];
        $this->data[ 'ppn'         ]          = $ppn[0];
        $this->data[ 'maxWallTime' ]          = $maxWallTime[0];
        $this->data[ 'dataset' ][ 'status' ]  = 'queued';
        $tarFilename                          = sprintf(
            "hpcinput-%s-%s-%05d.tar"
            ,$limsHost
            ,$this->data[ 'db'  ][ 'name' ]
            ,$this->data[ 'job' ][ 'requestID' ]
            );
        $workingDirPath                       = $this->data[ 'job' ][ 'directory' ];
        $inputTarFile                         = $workingDirPath . $tarFilename;
        $outputDirName                        = basename( $workingDirPath );

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

        ### --- removed this scalar value memoryreq calc as it is done in an array version above
        ###        $memoryreq    = 0;

        ###        ## compute memoryreq if compute_memoryreq set

        ###        if ( isset( $this->grid[ $cluster ][ 'compute_memoryreq' ] ) ) {
        ###            $use_mem_per_core = 2000;
        ###            if ( isset( $this->grid[ $cluster ][ 'mempercore' ] ) ) {
        ###                $use_mem_per_core = $this->grid[ $cluster ][ 'mempercore' ];
        ###            }
            
        ###            $memoryreq = (int)( ( $cores * $use_mem_per_core ) / $nodes );

        ###            $memoryreq = (int)( $memoryreq + 999 );  ## Rounded up multiple of 1000
        ###            $memoryreq = (int)( $memoryreq / 1000 );
        ###            $memoryreq = (int)( $memoryreq * 1000 );
        ###        }

        ###        ## maximum memory limit if maxmem limit set

        ###        if (
        ###            isset( $this->grid[ $cluster ][ 'maxmem' ] ) &&
        ###            $memoryreq > $this->grid[ $cluster ][ 'maxmem' ]
        ###            ) {
        ###            $memoryreq = $this->grid[ $cluster ][ 'maxmem' ];
        ###        }

        if ( !$wrapper_set ) {
            $airavataWrapper = new AiravataWrapper();
            $wrapper_set     = true;
        }

        ##var_dump('dumperr', $uslimsVMHost, $limsUser, $exp_name, $expReqId, $clus_host, $queue, $cores, $nodes,
        ##          $mgroupcount, $maxWallTime, $clus_user, $clus_scrd, $inputTarFile, $outputDirName );


        if ( array_key_exists( 'clusters', $this->debug_arrays ) &&
            $this->debug_arrays[ 'clusters' ] ) {
            $this->message[] = "computeClusters:" . json_encode( $computeClusters, JSON_PRETTY_PRINT );
        }

        if ( $this->testing ) {
            $this->message[] = "    ppn=" . implode( ":", $ppn ) . " nodes=" . implode( ":", $nodes ) . "  cores=" . implode( ":", $cores );
            $this->message[] = "    uslimsVMHost=$uslimsVMHost  limsUser=$limsUser";
            $this->message[] = "    exp_name=$exp_name  expReqId=$expReqId  memoryreq=" . implode( ":", $memoryreq );
            $this->message[] = "    clus_host=" . implode( ":", $clus_host ) . " queue=" . implode( ":", $queue ) . " clus_user=$clus_user  clus_scrd=$clus_scrd";
            $this->message[] = "    mgroupcount=" . implode( ":", $mgroupcount ) . " maxWallTime=" . implode( ":", $maxWallTime );
            $this->message[] = "    inputTarFile=$inputTarFile";
            $this->message[] = "    outputDirName=$outputDirName";

            echo
                'testing on, submission disabled<br>'
                ;
            return 0;
        } else {

## always submit to metascheduler

            $computeClustersEnc = json_encode( $computeClusters );

            if ( array_key_exists( 'clusters_encoded', $this->debug_arrays ) &&
                 $this->debug_arrays[ 'clusters_encoded' ] ) {
                $this->message[] = "computeClustersEnc: $computeClustersEnc";
            }

            $expResult  = $airavataWrapper->launch_autoscheduled_airavata_experiment(
                $uslimsVMHost
                ,$limsUser
                ,$exp_name
                ,$expReqId
                ,$computeClustersEnc
                ,$inputTarFile
                ,$outputDirName
                );

## old way
#            $expResult  = $airavataWrapper->launch_airavata_experiment( $uslimsVMHost, $limsUser,
#                                                                        $exp_name, $expReqId,
#                                                                        $clus_host, $queue, $cores, $nodes, $mgroupcount,
#                                                                        $maxWallTime, $clus_user, $clus_scrd, $clus_acct,
#                                                                        $inputTarFile, $outputDirName, $memoryreq );

            if ( array_key_exists( 'exp_result', $this->debug_arrays ) &&
                 $this->debug_arrays[ 'exp_result' ] ) {
                 $this->message[] = "exp result " . json_encode( $expResult, JSON_PRETTY_PRINT );
            }
        }

        $expId      = 0;

        if ( $expResult[ 'launchStatus' ] )
        {
            $expId      = $expResult[ 'experimentId' ];
            ##var_dump($expId);
            $this->message[] = "Experiment $expId created";
            $this->message[] = "    ppn=" . implode( ":", $ppn ) . " nodes=" . implode( ":", $nodes ) . "  cores=" . implode( ":", $cores );
            $this->message[] = "    uslimsVMHost=$uslimsVMHost  limsUser=$limsUser";
            $this->message[] = "    exp_name=$exp_name  expReqId=$expReqId  memoryreq=" . implode( ":", $memoryreq );
            $this->message[] = "    clus_host=" . implode( ":", $clus_host ) . " queue=" . implode( ":", $queue ) . " clus_user=$clus_user  clus_scrd=$clus_scrd";
            $this->message[] = "    mgroupcount=" . implode( ":", $mgroupcount ) . " maxWallTime=" . implode( ":", $maxWallTime );
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
