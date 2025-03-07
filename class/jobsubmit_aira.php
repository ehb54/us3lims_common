<?php
/*
 * jobsubmit_aira.php
 *
 * Base class for common elements used to submit an analysis
 *
 */
class airavata_jobsubmit
{
    protected $data    = array();   ## Global parsed input
    protected $jobfile = "";        ## Global string
    protected $message = array();   ## Errors and other messages
    protected $grid    = array();   ## Information about the clusters
    protected $xmlfile = "";        ## Base name of the experiment xml file

    function __construct()
    {
        global $full_path;
        global $class_dir;

        $debug = false;

        ## anonymous error message function - local in scope

        $error_msg = function( $msg ) {
            $emsg = "ERROR: class/jobsubmit_aira.php : $msg";
            echo "$emsg<br>";
            error_log( $emsg );
        };

        ## anonymous info message function - local in scope

        $debug_msg = function( $msg, $debug ) {
            if ( $debug ) {
                $emsg = "info: class/jobsubmit_aira.php : $msg";
                echo "$emsg<br>";
            }
        };
        
        if ( !isset( $class_dir ) ) {
            $error_msg( "\$class_dir is not set" );
            return;
        }

        if ( !is_dir( $class_dir ) ) {
            $error_msg( "\$class_dir [$class_dir] is not a directory" );
            return;
        }

        if ( !isset( $full_path ) ) {
            $error_msg( "\$full_path is not set" );
            return;
        }

        if ( !is_dir( $full_path ) ) {
            $error_msg( "\$full_path [$full_path] is not a directory" );
            return;
        }

        ## dbinst specific configs

        $dbinst_config_file = '$full_path/cluster_config.php';

        if ( !file_exists( $dbinst_config_file ) ) {
            $dbinst_config_file = '../uslims3_newlims/cluster_config.php';
            if ( !file_exists( $dbinst_config_file ) ) {
                $error_msg( "no cluster_config.php file found" );
                return;
            }
        }

        ## global configs

        $global_config_file = "$class_dir/../global_config.php";

        if ( !file_exists( $global_config_file ) ) {
            $error_msg("\$global_config_file_dir [$global_config_file] does not exist");
            return;
        }
        
        ## read global config first, so dbinst overrides

        try {
            include( $global_config_file );
        } catch ( Exception $e ) {
            $error_msg( "including $global_config_file " . $e->getMessage() );
            return;
        }

        try {
            include( $dbinst_config_file );
        } catch ( Exception $e ) {
            $error_msg ( "including $dbinst_config_file " . $e->getMessage() );
            return;
        }
        
        if ( !isset( $cluster_configuration ) || !is_array( $cluster_configuration ) ) {
            $error_msg( "\$cluster_configuration not set or is not an array" );
            return;
        }

        if ( !isset( $cluster_details ) || !is_array( $cluster_details ) ) {
            $error_msg( "\$cluster_details not set or is not an array" );
            return;
        }

        $reqkey = [
            'active'
            ,'airavata'
            ,'name'
            ,'submithost'
            ,'userdn'
            ,'submittype'
            ,'httpport'
            ,'workdir'
            ,'sshport'
            ,'queue'
            ,'maxtime'
            ,'ppn'
            ,'ppbj'
            ,'maxproc'
            ];

        $reqkey_metascheduler = [
            'active'
            ,'name'
            ,'airavata'
            ,'clusters'
            ];

        foreach ( $cluster_details as $k => $v ) {
            $ok = true;

            ## do all required keys exist for this cluster?

            if ( array_key_exists( 'active', $v ) && !$v['active'] ) {
                $debug_msg( "cluster $k not active", $debug );
                continue;
            }

            foreach ( array_key_exists( "clusters", $v )
                      ? $reqkey_metascheduler
                      : $reqkey
                      as $key ) {
                if ( !array_key_exists( $key, $v ) ) {
                    $error_msg( "\$cluster_details for cluster $k is missing required key $key" );
                    $ok = false;
                    continue;
                }
            }
            if ( !$ok ) {
                continue;
            }

            if ( !$v['airavata'] ) {
                $debug_msg( "cluster $k not airavata, skipped", $debug );
                continue;
            }

            ## go with entry

            $this->grid[ $k ] = $v;
        }
    }

    ## Deconstructor
    function __destruct()
    {
        $this->clear();
    }

    ## Clear out data for another request
    function clear()
    {
        $this->data    = array();
        $this->jobfile = "";
        $this->message = array();
        $this->xmlfile = "";
    }

    ## Request status
    function status()
    {
        if ( isset( $this->data['dataset']['status'] ) )
            return $this->data['dataset']['status'];

        return 'Status unavailable';
    }

    ## Return any messages
    function get_messages()
    {
        return $this->message;
    }

    ## Read and parse submitted xml file
    function parse_input( $xmlfile )
    {
        $this->xmlfile = $xmlfile;          ## Save for other methods
        $contents = implode( "", file( $xmlfile ) );

        $parser = new XMLReader();
        $parser->xml( $contents );

        while( $parser->read() )
        {
            if ( $parser->nodeType == XMLReader::ELEMENT )
            {
                $tag = $parser->name;

                switch ( $tag )
                {
                    case 'US_JobSubmit':
                        $this->parse_submit( $parser );
                    break;

                    case 'job':
                        $this->parse_job( $parser );
                    break;

                    case 'dataset':
                        $this->parse_dataset( $parser );
                    break;
                }
            }
        }
    }

    function parse_submit( &$parser )
    {
        $this->data[ 'method'  ] = $parser->getAttribute( 'method'  );
        $this->data[ 'version' ] = $parser->getAttribute( 'version' );
    }

    function parse_job( &$parser )
    {
        $job = array();

        while ( $parser->read() )
        {
            if ( $parser->nodeType == XMLReader::END_ELEMENT &&
                 $parser->name     == 'job' )
                break;

            if ( $parser->nodeType == XMLReader::ELEMENT )
            {
                $tag = $parser->name;

                switch ( $tag )
                {
                    case 'gateway':
                        $job[ 'gwhostid' ]   = $parser->getAttribute( 'id' );
                    break;

                    case 'cluster':
                        $job[ 'cluster_name'      ] = $parser->getAttribute( 'name' );
                    $job[ 'cluster_shortname' ] = $parser->getAttribute( 'shortname' );
                    $job[ 'cluster_queue'     ] = $parser->getAttribute( 'queue' );
                    break;

                    case 'udp':
                        $job[ 'udp_server' ] = $parser->getAttribute( 'server' );
                    $job[ 'udp_port'   ] = $parser->getAttribute( 'port' );
                    break;

                    case 'directory':
                        $job[ 'directory' ] = $parser->getAttribute( 'name' );
                    break;

                    case 'datasetCount':
                        $job[ 'datasetCount' ] = $parser->getAttribute( 'value' );
                    break;

                    case 'request':
                        $job[ 'requestID' ] = $parser->getAttribute( 'id' );
                    break;

                    case 'database':
                        $this->parse_db( $parser );
                    break;

                    case 'jobParameters':
                        $this->parse_jobParameters( $parser, $job );
                    break;
                }
            }
        }

        $this->data[ 'job' ] = $job;
    }
    function parse_db( &$parser )
    {
        $db = array();

        while ( $parser->read() )
        {
            if ( $parser->nodeType == XMLReader::END_ELEMENT &&
                 $parser->name     == 'database' )
                break;

            if ( $parser->nodeType == XMLReader::ELEMENT )
            {
                $tag = $parser->name;

                switch ( $tag )
                {
                    case 'name':
                        $db[ 'name' ] = $parser->getAttribute( 'value' );
                    break;

                    case 'host':
                        $db[ 'host' ] = $parser->getAttribute( 'value' );
                    break;

                    case 'user':
                        $db[ 'user' ] = $parser->getAttribute( 'email' );
                    $db[ 'user_id' ] = $parser->getAttribute( 'user_id' );
                    break;

                    case 'submitter':
                        $db[ 'submitter' ] = $parser->getAttribute( 'email' );
                    break;
                }
            }
        }

        $this->data[ 'db' ] = $db;
    }

    function parse_jobParameters( &$parser, &$job )
    {
        $parameters = array();

        while ( $parser->read() )
        {
            if ( $parser->nodeType == XMLReader::END_ELEMENT &&
                 $parser->name     == 'jobParameters' )
                break;

            $tag = $parser->name;
            if ( $tag == "#text" ) continue;

            $parameters[ $tag ] = $parser->getAttribute( 'value' );
        }

        $job[ 'jobParameters' ] = $parameters;
    }

    function parse_dataset( &$parser )
    {
        $dataset = array();

        if ( ! isset( $this->data[ 'dataset' ] ) ) $this->data[ 'dataset' ] = array();

        while ( $parser->read() )
        {
            if ( $parser->nodeType == XMLReader::END_ELEMENT &&
                 $parser->name     == 'dataset' )
                break;

            $tag = $parser->name;

            switch ( $tag )
            {
                case 'files':
                    $this->parse_files( $parser, $dataset );
                break;

                case 'parameters':
                    $this->parse_parameters( $parser, $dataset );
                break;
            }
        }

        array_push( $this->data[ 'dataset' ], $dataset );
    }

    function parse_files( &$parser, &$dataset )
    {
        $files = array();

        while ( $parser->read() )
        {
            if ( $parser->nodeType == XMLReader::END_ELEMENT &&
                 $parser->name     == 'files' )
                break;

            $tag = $parser->name;

            switch ( $tag )
            {
                case 'experiment':
                    case 'auc'       :
                    case 'edit'      :
                    case 'model'     :
                    case 'noise'     :
                    array_push( $files, $parser->getAttribute( 'filename' ) );
                break;
            }
        }
        $dataset[ 'files' ] = $files;
    }

    function parse_parameters( &$parser, &$dataset )
    {
        $parameters = array();

        while ( $parser->read() )
        {
            if ( $parser->nodeType == XMLReader::END_ELEMENT &&
                 $parser->name     == 'parameters' )
                break;

            $tag = $parser->name;
            if ( $tag == "#text" ) continue;

            $parameters[ $tag ] = $parser->getAttribute( 'value' );
        }

        $dataset[ 'parameters' ] = $parameters;
    }

    function maxwall( $cluster = '' )
    {
        $spfact=7;
        $parameters = $this->data[ 'job' ][ 'jobParameters' ];
        $cluster    = strlen( $cluster ) ? $cluster : $this->data[ 'job' ][ 'cluster_shortname' ];
        # not used  $queue      = $this->data[ 'job' ][ 'cluster_queue' ];
        $dset_count = $this->data[ 'job' ][ 'datasetCount' ];
        $max_time   = $this->grid[ $cluster ][ 'maxtime' ];
        $ti_noise   = isset( $parameters[ 'tinoise_option' ] )
            ? $parameters[ 'tinoise_option' ] > 0
            : false;
        $ri_noise   = isset( $parameters[ 'rinoise_option' ] )
            ? $parameters[ 'rinoise_option' ] > 0
            : false;
        $mxiters    = isset( $parameters[ 'max_iterations' ] )
            ? $parameters[ 'max_iterations' ]
            : 0;
        $dsparams   = $this->data[ 'dataset' ][ 0 ][ 'parameters' ];

        if ( preg_match( "/GA/", $this->data[ 'method' ] ) )  ## GA or DMGA
        {
            ## Assume 1 sec a basic unit

            $generations = $parameters[ 'generations' ];
            $population  = $parameters[ 'population' ];

            ## The constant 125 is an empirical value from doing a Hessian
            ## minimization

            $time        = ( 125 + $population ) * $generations;

            $time *= 1.2;  ## Pad things a bit
            $time  = (int)( ($time + 59) / 60 ); ## Round up to minutes
        }

        else if ( preg_match( "/PCSA/", $this->data[ 'method' ] ) )  ## PCSA
        {  ## PCSA
            $vsize      = isset( $parameters[ 'vars_count' ] )
                ? $parameters[ 'vars_count' ]
                : 1;
            $gfiters    = isset( $parameters[ 'gfit_iterations' ] )
                ? $parameters[ 'gfit_iterations' ]
                : 1;
            $curvtype   = isset( $parameters[ 'curve_type' ] )
                ? $parameters[ 'curve_type' ]
                : "SL";
            if ( preg_match( "/HL/", $curvtype ) )
                $time       = $vsize * $gfiters;
            else
                $time       = $vsize * $vsize * $gfiters;
            if ( $ti_noise || $ri_noise ) $time *= 2;
            $time       = $time / 4;        ## Base time is 15 seconds
            $time       = max( $time, 30 ); ## Minimum PCSA time is 30 minutes
        }

        else ## 2DSA or 2DSA-CG
        {
            $time       = 5;  ## Base time in minutes

            if ( isset( $parameters[ 'meniscus_points' ] ) )
            {
                $points     = $parameters[ 'meniscus_points' ];
                if ( $points > 1 )
                {  ## If fit-meniscus|bottom, multiply by fit points
                    $time      *= $points;
                    if ( isset( $parameters[ 'fit_mb_select' ] ) )
                    {  ## If fitting both meniscus and bottom, multiply again
                        $fselect    = $parameters[ 'fit_mb_select' ];
                        if ( $fselect == 3 )
                            $time      *= $points;
                    }
                }
            }

            if (  isset( $parameters[ 's_grid_points' ] )  &&
                  isset( $parameters[ 'ff0_grid_points' ] ) )
            {
                $gpts_s     = $parameters[ 's_grid_points' ];
                $gpts_k     = $parameters[ 'ff0_grid_points' ];
                $gpts_t     = $gpts_s * $gpts_k;
                if ( $gpts_t > 200000 )
                    $time      *= 8;
                else if ( $gpts_t > 100000 )
                    $time      *= 4;
                else if ( $gpts_t > 50000 )
                    $time      *= 2;
            }

            if ( isset( $dsparams[ 'simpoints' ] ) )
            {
                $simpts     = $dsparams[ 'simpoints' ];
                if ( $simpts < 1 ) $simpts = 1;
                $spfact     = (int)( ( $simpts + 999 ) / 1000 );
                $time      *= $spfact;
            }
            ##else
            ## $spfact=8;

            ## Double time for each noise calculation
            ##         if ( $ti_noise || $ri_noise ) $time *= 2;
            if ( $ti_noise )  $time *= 2;
            if ( $ri_noise )  $time *= 2;

            if ( preg_match( "/CG/", $this->data[ 'method' ] ) )
            {
                $time      *= 8;
                if ( $mxiters > 0 )  $time *= 2;
            }
        }

        $montecarlo = 1;

        if ( isset( $parameters[ 'mc_iterations' ] ) )
        {
            $montecarlo = $parameters[ 'mc_iterations' ];
            if ( $montecarlo > 0 )  $time *= $montecarlo;
        }

        if ( $mxiters > 0 )  $time *= $mxiters;

        $time *= $dset_count;                   ## times number of datasets
        $time  = (int)( ( $time * 12 ) / 10 );  ## Padding

        ## Account for parallel group count in max walltime
        if ( $montecarlo > 1  ||  $dset_count > 1 )
        {
            if ( isset( $this->data[ 'job' ][ 'mgroupcount' ] ) )
                $mgroupcount = $this->data[ 'job' ][ 'mgroupcount' ];
            else
                $mgroupcount = 1;
        }
        else
            $mgroupcount = 1;

        $mgroupcount = max( $mgroupcount, 1 );

        ## Adjust max wall time down based on parallel group count
        switch ( $mgroupcount )
        {
            case 1  :
                break;

            case 2  :
                case 3  :
                $time = (int)( ( $time * 10 ) / 15 );
            break;

            case 4  :
                case 5  :
                case 6  :
                $time = (int)( ( $time * 10 ) / 35 );
            break;

            case 7  :
                case 8  :
                $time = (int)( ( $time * 10 ) / 75 );
            break;

            case 16 :
                $time = (int)( ( $time * 10 ) / 150 );
            break;

            case 32 :
                $time = (int)( ( $time * 10 ) / 300 );
            break;

            default :
                $time = (int)( ( $time * 10 ) / ( ( $mgroupcount - 1 ) * 10 ) );
            break;
        }

        $time = max( $time, 5 );         ## Minimum time is 5 minutes

        ## pmg is only enabled on clusters that have it set

        if ( !array_key_exists( 'pmg', $this->grid[ $cluster ] ) ||
             !$this->grid[ $cluster ]['pmg'] ) {
            $mgroupcount = 1;
        }          

        ## if usemaxtime is set, use the max time

        if ( array_key_exists( 'usemaxtime', $this->grid[ $cluster ] ) &&
             $this->grid[ $cluster ]['usemaxtime'] ) {
            $time = $max_time;
        }          

        ## if ( $cluster == 'alamo' || $cluster == 'alamo-local' )
        ## {
        ## ## For alamo, $max_time is hardwired to 2160
        ## $time        = $max_time;
        ## }
        ## 
        ## if ( $cluster == 'jacinto' || $cluster == 'jacinto-local' )
        ## {
        ## ## For jacinto, $max_time is hardwired to 2160, and no PMG
        ## $time        = $max_time;
        ## $mgroupcount = 1;
        ## }
        ## 
        ## else if ( $cluster == 'bcf' || $cluster == 'bcf-local' )
        ## {
        ## ## For bcf, hardwire $max_time to 240 (4 hours), and no PMG
        ## $time        = $max_time;
        ## $mgroupcount = 1;
        ## }
        ## else
        ## {

        $time        = min( $time, $max_time ); ## Maximum time is defined for each cluster

        ## }
        ##$spfact=(int)$spfact;
        ##if($time < 480) $time=$spfact * 100;
        ##$time=$spfact * 100;

        return (int)$time;
    }

    function nodes( $cluster = '' )
    {
        $cluster    = strlen( $cluster ) ? $cluster : $this->data[ 'job' ][ 'cluster_shortname' ];
        $parameters = $this->data[ 'job' ][ 'jobParameters' ];
        $max_procs  = $this->grid[ $cluster ][ 'maxproc' ];
        $ppn        = $this->grid[ $cluster ][ 'ppn'     ];
        $ppbj       = $this->grid[ $cluster ][ 'ppbj'    ];

        if ( preg_match( "/GA/", $this->data[ 'method' ] ) )
        {  ## GA: procs is demes+1 rounded to procs-per-node
            $demes = $parameters[ 'demes' ];
            if ( $demes == 1 )
                $demes = $ppbj - 1;
            $procs = $demes + $ppn;                  ## Procs = demes+1
            $procs = (int)( $procs / $ppn ) * $ppn;  ## Rounded to procs-per-node

            if ( isset( $this->data[ 'job' ][ 'mgroupcount' ] ) )
                $mgroupcount = $this->data[ 'job' ][ 'mgroupcount' ];
            if ( isset( $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] ) )
                $mgroupcount = $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ];
            $mgroupcount = min( $this->max_mgroupcount(), $mgroupcount );
            if ( $mgroupcount > 1 )
            {
                $procs = ( $demes + 1 ) * $mgroupcount;
            }
            
        }
        else if ( preg_match( "/2DSA/", $this->data[ 'method' ] ) )
        {  ## 2DSA:  procs is max_procs, but no more than subgrid count
            $gsize = $parameters[ 'uniform_grid' ];
            $gsize = $gsize * $gsize;           ## Subgrid count
            $procs = min( $ppbj, $gsize );      ## Procs = base or subgrid count
        }
        else if ( preg_match( "/PCSA/", $this->data[ 'method' ] ) )
        {  ## PCSA:  procs is max_procs, but no more than vars_count
            $vsize = $parameters[ 'vars_count' ];
            if ( $parameters[ 'curve_type' ] != 'HL' )
                $vsize = $vsize * $vsize;        ## Variations count
            $procs = min( $ppbj, $vsize );      ## Procs = base or subgrid count
        }

        $procs = max( $procs, $ppn );          ## Minimum procs is procs-per-node
        $procs = min( $procs, $max_procs );    ## Maximum procs depends on cluster

        $nodes = ceil($procs / $ppn);    ## Return nodes, procs divided by procs-per-node
        $nodes = max( $nodes, 1 );      ## Minimum nodes is 1
        return $nodes;
    }

    function max_mgroupcount( $cluster = '' )
    {
        $cluster    = strlen( $cluster ) ? $cluster : $this->data[ 'job' ][ 'cluster_shortname' ];
        $max_procs  = $this->grid[ $cluster ][ 'maxproc' ];
        $parameters = $this->data[ 'job' ][ 'jobParameters' ];
        $mciters    = $parameters[ 'mc_iterations' ];
        $max_groups = 32;

        if ( preg_match( "/SA/", $this->data[ 'method' ] ) )
        {  ## For 2DSA/PCSA, PMGs is always 1
            $max_groups = 1;
        }

        else if ( preg_match( "/us3iab/", $cluster ) )
        {   ## Us3iab PMGs limited by max procs available
            $max_groups = $max_procs / 16;
        }

        else if ( $mciters > 1 )
        {
            ## No more PMGs than half of MC iterations
            ## hoping to remove this restriction

            $max_groups = min( $max_groups, floor( $mciters / 2 ) );
        }

        return $max_groups;
    }
}
