<?php
/*
 * jobsubmit.php
 *
 * Base class for common elements used to submit an analysis
 *
 */
class jobsubmit
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
           $emsg = "ERROR: class/jobsubmit.php : $msg";
           echo "$emsg<br>";
           error_log( $emsg );
       };

       ## anonymous info message function - local in scope

       $debug_msg = function( $msg, $debug ) {
           if ( $debug ) {
               $emsg = "info: class/jobsubmit.php : $msg";
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

       ## Cluster configuration comes from three levels, in this precedence
       ## order, matching lib/utility.php exactly:
       ##
       ##   1. dbinst    $full_path/cluster_config.php
       ##   2. site      ../cluster_config.php  (legacy, not a designed level)
       ##   3. newlims   ../uslims3_newlims/cluster_config.php
       ##
       ## First file found wins outright; the levels do not merge, because
       ## each file assigns $cluster_configuration whole.
       ##
       ## The site-level candidate is here only to keep the two loaders
       ## byte-identical in what they resolve. utility.php reached that path
       ## by accident, through a relative include resolved against the working
       ## directory, and it was the only dbinst candidate it had -- so an
       ## instance with its own cluster_config.php was honoured here and
       ## ignored there.

       $dbinst_config_candidates = array(
           rtrim( $full_path, '/' ) . '/cluster_config.php'
           ,'../cluster_config.php'
           ,'../uslims3_newlims/cluster_config.php'
           );

       $dbinst_config_file = null;

       foreach ( $dbinst_config_candidates as $candidate ) {
           if ( file_exists( $candidate ) ) {
               $dbinst_config_file = $candidate;
               break;
           }
       }

       if ( $dbinst_config_file === null ) {
           $error_msg( "no cluster_config.php file found" );
           return;
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

       ## Required keys for every SSH-Slurm cluster entry.
       ## 'submittype' and 'httpport' are historical Airavata fields; no longer
       ## required by submit_slurm.php but may still appear in config — tolerated.
       $reqkey = [
           'active'
           ,'name'
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
            ,'clusters'
            ];

       foreach ( $cluster_details as $k => $v ) {
           $ok = true;

           if ( array_key_exists( 'active', $v ) && !$v['active'] ) {
               $debug_msg( "cluster $k not active", $debug );
               continue;
           }

           ## The instance's cluster_config.php is the per-instance override:
           ## it decides which of global_config.php's clusters THIS LIMS
           ## instance is allowed to use. lib/utility.php applies it when it
           ## builds the queue-setup list, so a cluster switched off there
           ## disappears from the UI.
           ##
           ## This loop used to consult $cluster_details alone. The two filters
           ## therefore disagreed: a cluster the instance had switched off was
           ## hidden from the UI but still accepted here, so a CLI submission
           ## or a hand-built request could still land a job on it. Apply the
           ## same test, so "not offered" and "not accepted" are one decision.

           if ( !array_key_exists( $k, $cluster_configuration ) ) {
               $debug_msg( "cluster $k not present in \$cluster_configuration", $debug );
               continue;
           }

           if ( !is_array( $cluster_configuration[ $k ] ) ) {
               $error_msg( "cluster configuration for cluster $k is not an array" );
               continue;
           }

           if ( !array_key_exists( 'active', $cluster_configuration[ $k ] )
                || !$cluster_configuration[ $k ][ 'active' ] ) {
               $debug_msg( "cluster $k inactive in \$cluster_configuration", $debug );
               continue;
           }

           ## do all required keys exist for this cluster?

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

   function maxwall()
   {
      $parameters = $this->data[ 'job' ][ 'jobParameters' ];
      $cluster    = $this->data[ 'job' ][ 'cluster_shortname' ];
      $queue      = $this->data[ 'job' ][ 'cluster_queue' ];
      $dset_count = $this->data[ 'job' ][ 'datasetCount' ];
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

      if ( preg_match( "/GA/", $this->data[ 'method' ] ) )
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

         if ( $ti_noise || $ri_noise ) $time *= 2;
         ## Double time for each noise option used
         if ( $ti_noise )  $time *= 2;
         if ( $ri_noise )  $time *= 2;

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

         if ( preg_match( "/CG/", $this->data[ 'method' ] ) )
         {
            $time *= 8;
            ## How much slower custom-grid work runs here is a performance
            ## property of the box, not a topology or capacity one, so it is
            ## a per-cluster magnitude rather than a boolean: a fast
            ## fixed-capacity box and a slow one need not share the same x4.
            $fixed_capacity = (bool) $this->cluster_opt( $cluster, 'fixed_capacity', false );
            if ( $fixed_capacity )
               $time *= (float) $this->cluster_opt( $cluster, 'cg_time_multiplier', 4.0 );
            else if ( $mxiters > 0 )  $time *= 2;
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
      $time  = (int)( ( $time * 11 ) / 10 );  ## Padding (+10%)

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

      return (int)$time;
   }

   ## Size ONE intact analysis group. Capacity does not clamp the answer: a
   ## group too large for the cluster is refused by the caller, because
   ## shrinking it changes the analysis that was asked for.
   ##
   ## Not pure: the GA demes==1 branch backfills grid[ppbj]. Re-running
   ## converges on the same value, so the repeat calls are safe.
   protected function tasks_per_group_plan()
   {
      $cluster       = $this->data[ 'job' ][ 'cluster_shortname' ];
      $cfg           = $this->grid[ $cluster ];
      $parameters    = $this->data[ 'job' ][ 'jobParameters' ];
      $fixed         = (bool) $this->cluster_opt( $cluster, 'fixed_capacity', false );
      $tasks_per_node = max( 1, (int) $cfg[ 'ppn' ] );
      $ppbj          = max( 1, (int) $cfg[ 'ppbj' ] );
      $ppmg          = max( 1, (int) $this->cluster_opt( $cluster, 'procs_per_mgroup', 16 ) );
      $minimum       = $fixed ? min( $ppmg, $tasks_per_node ) : 1;

      ## What one intact group asks for; refused below if it cannot fit.
      if ( preg_match( "/GA/", $this->data[ 'method' ] ) )
      {
         $demes = isset( $parameters[ 'demes' ] ) ? (int) $parameters[ 'demes' ] : 1;
         if ( $demes == 1 )
         {
            $demes = $ppbj - 1;
            if ( $fixed )
               $demes = max( $minimum - 1, $demes );
            if ( $ppbj == 9 )
               $demes = max( 17, $demes );
            $ppbj = $demes + 1;
            $this->grid[ $cluster ][ 'ppbj' ] = $ppbj;
         }
         $method_demand = (int)( ( $demes + $ppbj ) / $ppbj ) * $ppbj;
      }
      else if ( preg_match( "/2DSA/", $this->data[ 'method' ] ) )
      {
         $gsize = (int) $parameters[ 'uniform_grid' ];
         $method_demand = min( $ppbj, $gsize * $gsize );
      }
      else if ( preg_match( "/PCSA/", $this->data[ 'method' ] ) )
      {
         $vsize = (int) $parameters[ 'vars_count' ];
         if ( $parameters[ 'curve_type' ] != 'HL' )
            $vsize *= $vsize;
         $method_demand = min( $ppbj, $vsize );
      }
      else
      {
         $method_demand = $ppbj;
      }

      $method_demand = max( $method_demand, $ppbj );
      $desired       = max( $method_demand, $minimum );

      return [
         'minimum'      => (int) $minimum,
         'desired'      => (int) $desired,
         'tasks_per_node' => (int) $tasks_per_node,
      ];
   }

   ## The single authority on how this job is sized and placed. Returns the
   ## whole plan, or false with an explanation appended to message[] when the
   ## job cannot be run on this cluster as configured.
   ##
   ## Keys, all integers:
   ##   minimum_tasks_per_group    allocation floor for one group
   ##   desired_tasks_per_group    what one intact group asks for
   ##   requested_groups           what the user asked for, unclamped
   ##   resolved_groups            what the cluster will actually run
   ##   allocated_tasks_per_group  ranks each resolved group receives
   ##   total_tasks                the MPI world size (#SBATCH -n)
   ##   tasks_per_node             the node's rank capacity (--ntasks-per-node)
   ##   node_count                 nodes required to hold total_tasks
   ##
   ## Callers print these. Nothing downstream derives them a second time.
   function resource_plan()
   {
      $cluster       = $this->data[ 'job' ][ 'cluster_shortname' ];
      $cfg           = $this->grid[ $cluster ];
      $parameters    = $this->data[ 'job' ][ 'jobParameters' ];
      $single        = (bool) $this->cluster_opt( $cluster, 'single_node', false );
      $maxproc       = max( 0, (int) $cfg[ 'maxproc' ] );
      $configured_ppn = (int) $cfg[ 'ppn' ];

      if ( $configured_ppn < 1 )
      {
         $this->message[] = "ERROR: cluster $cluster has an invalid tasks-per-node capacity";
         return false;
      }

      if ( $single  &&  $maxproc > $configured_ppn )
      {
         $this->message[] = "ERROR: single-node cluster $cluster permits $maxproc tasks per job,"
                          . " but only $configured_ppn tasks on its node";
         return false;
      }

      $group_plan    = $this->tasks_per_group_plan();
      $minimum       = $group_plan[ 'minimum' ];
      $desired       = $group_plan[ 'desired' ];
      $tasks_per_node = $group_plan[ 'tasks_per_node' ];

      $requested = max( 1, (int)( $parameters[ 'req_mgroupcount' ] ?? 1 ) );
      $limit     = 32;
      $mciters   = (int)( $parameters[ 'mc_iterations' ] ?? 1 );

      if ( preg_match( "/SA/", $this->data[ 'method' ] ) )
         $limit = 1;
      else if ( $mciters > 1 )
         $limit = min( $limit, max( 1, (int)( $mciters / 2 ) ) );

      ## Parallel masters needs at least three ranks per group.  Below that,
      ## use the standard single-master path instead.
      if ( $desired < 3 )
         $limit = 1;

      $capacity_groups = $desired > 0 ? (int)( $maxproc / $desired ) : 0;
      $resolved        = min( $requested, $limit, $capacity_groups );

      if ( $resolved < 1 )
      {
         $this->message[] = "ERROR: one intact analysis group needs $desired tasks,"
                          . " but cluster $cluster permits at most $maxproc";
         return false;
      }

      $allocated = $desired;
      $total     = $allocated * $resolved;

      ## Defensive: total is bounded by maxproc, and maxproc by the node's
      ## capacity, so the checks above should already have caught this.
      if ( $single  &&  $total > $tasks_per_node )
      {
         $this->message[] = "ERROR: single-node cluster $cluster permits $tasks_per_node"
                          . " tasks per node, but the resolved plan needs $total";
         return false;
      }

      $plan = [
         'minimum_tasks_per_group'   => (int) $minimum,
         'desired_tasks_per_group'   => (int) $desired,
         'requested_groups'          => (int) $requested,
         'resolved_groups'           => (int) $resolved,
         'allocated_tasks_per_group' => (int) $allocated,
         'total_tasks'               => (int) $total,
         'tasks_per_node'            => (int) $tasks_per_node,
         'node_count'                => max( 1, (int) ceil( $total / $tasks_per_node ) ),
      ];

      $this->data[ 'job' ][ 'procs' ]       = $plan[ 'allocated_tasks_per_group' ];
      $this->data[ 'job' ][ 'mgroupcount' ] = $plan[ 'resolved_groups' ];
      $this->data[ 'job' ][ 'resource_plan' ] = $plan;

      return $plan;
   }

   ## Node count alone, for the tests that assert placement in isolation.
   ## No production caller remains: submit_slurm consumes the whole plan.
   function nodes()
   {
      $plan = $this->resource_plan();
      return $plan === false ? 0 : $plan[ 'node_count' ];
   }

   ## Read a per-cluster tuning key, falling back to $default when absent.
   protected function cluster_opt( $cluster, $key, $default )
   {
      if ( ! array_key_exists( $cluster, $this->grid ) )
         return $default;

      return array_key_exists( $key, $this->grid[ $cluster ] )
             ? $this->grid[ $cluster ][ $key ]
             : $default;
   }

   ## The group ceiling on its own, for the UI and for focused tests.
   ## These limit rules duplicate resource_plan()'s and will drift if only
   ## one is edited. resource_plan() is the authority; this wants merging.
   function max_mgroupcount()
   {
      $cluster    = $this->data[ 'job' ][ 'cluster_shortname' ];
      $parameters = $this->data[ 'job' ][ 'jobParameters' ];
      $maxproc    = max( 0, (int) $this->grid[ $cluster ][ 'maxproc' ] );
      $desired    = $this->tasks_per_group_plan()[ 'desired' ];
      $mciters    = (int)( $parameters[ 'mc_iterations' ] ?? 1 );
      $limit      = 32;

      if ( preg_match( "/SA/", $this->data[ 'method' ] ) )
         $limit = 1;
      else if ( $mciters > 1 )
         $limit = min( $limit, max( 1, (int)( $mciters / 2 ) ) );

      if ( $desired < 3 )
         $limit = 1;

      return min( $limit, $desired > 0 ? (int)( $maxproc / $desired ) : 0 );
   }
}
