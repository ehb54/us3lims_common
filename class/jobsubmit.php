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

       ## A pool-definition finding, not a refusal of this submission: callers that scan the output
       ## (gridctl) fail a stage on ERROR: only.
       $warning_msg = function( $msg ) {
           $wmsg = "WARNING: class/jobsubmit.php : $msg";
           echo "$wmsg<br>";
           error_log( $wmsg );
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

       ## Match lib/utility.php: instance, site, then newlims configuration.
       ## Use the first existing file; configurations do not merge.

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

       ## Required keys for SSH-Slurm cluster entries.
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

           ## Only accept clusters enabled in both global and instance configuration.

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

           ## A metascheduler pool whose 'clusters' list names a key that
           ## does not exist in $cluster_details at all (a typo, a renamed
           ## or removed cluster) must fail loudly here rather than silently
           ## shrinking the pool -- the dispatch selector itself only sees the
           ## candidate list it is handed and has no way to tell "this name
           ## was never configured anywhere" from "this real cluster just
           ## happens to be inactive right now".
           ##
           ## This validation accepted a 'clusters'
           ## value that was not an array at all (foreach on a string warns
           ## and iterates zero times, so the pool entry was silently kept
           ## with an unusable member list), an empty array (a pool with
           ## nothing to ever choose from), a pool naming itself as its own
           ## member, another pool nested inside this one's member list
           ## (the dispatch selector expects real clusters only), and a member that
           ## exists in $cluster_details but is not actually enabled for
           ## this instance (the same active/present checks already run
           ## for the pool entry itself above, never run per-member).
           if ( array_key_exists( "clusters", $v ) ) {
               if ( !is_array( $v[ 'clusters' ] ) ) {
                   $warning_msg( "metascheduler '$k' has a 'clusters' value that is not an array" );
                   $ok = false;
               } elseif ( count( $v[ 'clusters' ] ) === 0 ) {
                   $warning_msg( "metascheduler '$k' has an empty 'clusters' list" );
                   $ok = false;
               } else {
                   foreach ( $v[ 'clusters' ] as $member ) {
                       if ( $member === $k ) {
                           $warning_msg( "metascheduler '$k' lists itself in its own 'clusters'" );
                           $ok = false;
                           continue;
                       }
                       if ( !array_key_exists( $member, $cluster_details ) ) {
                           $warning_msg( "metascheduler '$k' lists '$member' in its 'clusters', "
                                     . "but no such cluster exists in \$cluster_details" );
                           $ok = false;
                           continue;
                       }
                       if ( is_array( $cluster_details[ $member ] )
                            && array_key_exists( 'clusters', $cluster_details[ $member ] ) ) {
                           $warning_msg( "metascheduler '$k' lists another pool ('$member') in its"
                                     . " 'clusters', not a real cluster" );
                           $ok = false;
                           continue;
                       }
                       ## The per-instance check below (cluster_configuration) is not
                       ## the same thing as this host-level one: a cluster the
                       ## upgrade retired ($cluster_details
                       ## ['active'] => false, e.g. a dead Airavata entry) can still
                       ## be enabled in a given instance's own cluster_configuration,
                       ## which never gets revisited once a cluster is retired.
                       if ( !is_array( $cluster_details[ $member ] )
                            || empty( $cluster_details[ $member ][ 'active' ] ) ) {
                           $warning_msg( "metascheduler '$k' lists '$member' in its 'clusters', but it"
                                     . " is not active in \$cluster_details" );
                           $ok = false;
                           continue;
                       }
                       if ( !array_key_exists( $member, $cluster_configuration )
                            || !is_array( $cluster_configuration[ $member ] )
                            || empty( $cluster_configuration[ $member ][ 'active' ] ) ) {
                           $warning_msg( "metascheduler '$k' lists '$member' in its 'clusters', but it"
                                     . " is not enabled for this instance (missing or inactive in"
                                     . " \$cluster_configuration)" );
                           $ok = false;
                           continue;
                       }
                   }
               }
               if ( !$ok ) {
                   continue;
               }
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

   ## Record the real cluster a metascheduler pseudo-cluster submission
   ## resolved to, so update_db() can write it to gfac.analysis separately
   ## from cluster_shortname (which stays the pseudo name). Call after
   ## parse_input()/clear(), since clear() wipes $data. A direct submission
   ## to a real cluster never calls this, so the field stays unset/NULL.
   public function set_metascheduler_cluster_executing( $cluster )
   {
      $this->data[ 'job' ][ 'metaschedulerClusterExecuting' ] = $cluster;
   }

   ## The real $this->grid[...] entry to read for every execution-time
   ## config lookup (workdir, queue, sshport, maxtime, ppn/ppbj/maxproc,
   ## login, ...): metaschedulerClusterExecuting when a metascheduler
   ## pseudo-cluster has been resolved to a real one, else cluster_shortname
   ## itself for a direct submission. cluster_shortname is still what gets
   ## written to gfac.analysis and shown to the user -- this is only for
   ## "which real machine do we actually run this on".
   public function execution_cluster()
   {
      return $this->data[ 'job' ][ 'metaschedulerClusterExecuting' ]
             ?? $this->data[ 'job' ][ 'cluster_shortname' ];
   }

   ## Request status
   function status()
   {
      if ( isset( $this->data['dataset']['status'] ) )
      {
         return $this->data['dataset']['status'];
      }

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
         {
              break;
         }

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
         {
              break;
         }

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
      $parameters  = array();
      $occurrences = array();

      while ( $parser->read() )
      {
         if ( $parser->nodeType == XMLReader::END_ELEMENT &&
              $parser->name     == 'jobParameters' )
         {
              break;
         }

         $tag = $parser->name;
         if ( $tag == "#text" )
         { continue;
         }

         $parameters[ $tag ] = $parser->getAttribute( 'value' );
         $occurrences[ $tag ] = isset( $occurrences[ $tag ] ) ? $occurrences[ $tag ] + 1 : 1;

         ## A few elements carry their meaning in further attributes rather than
         ## in 'value': GA's bucket_fixed records which axes are held fixed in
         ## fixedtype, xtype and ytype. Keeping only 'value' discarded those,
         ## so anything reading this array could not tell a fixed axis from a
         ## free one. Named "<tag>_<attribute>", which is how the historical
         ## extraction names them, so one spelling serves both.
         foreach ( array( 'fixedtype', 'xtype', 'ytype' ) as $attribute )
         {
            $value = $parser->getAttribute( $attribute );

            if ( $value !== null )
            {
               $parameters[ $tag . '_' . $attribute ] = $value;
            }
         }
      }

      ## A repeated element carries its meaning in how many there are rather
      ## than in any one of them: GA's buckets are written as one <bucket> each
      ## with no count element anywhere, and only the last of them survives the
      ## assignment above. Named "<tag>_count", which is how the historical
      ## extraction names it, and never written over an element that already
      ## holds that name, so a real vars_count stays the request's own value.
      foreach ( $occurrences as $tag => $seen )
      {
         $key = $tag . '_count';

         if ( ! array_key_exists( $key, $parameters ) )
         {
            $parameters[ $key ] = $seen;
         }
      }

      $job[ 'jobParameters' ] = $parameters;
   }

   function parse_dataset( &$parser )
   {
      $dataset = array();

      if ( ! isset( $this->data[ 'dataset' ] ) )
      { $this->data[ 'dataset' ] = array();
      }

      while ( $parser->read() )
      {
         if ( $parser->nodeType == XMLReader::END_ELEMENT &&
              $parser->name     == 'dataset' )
         {
              break;
         }

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
         {
              break;
         }

         $tag = $parser->name;

         switch ( $tag )
         {
            case 'experiment':
            case 'auc'       :
            case 'edit'      :
            case 'model'     :
               ## Keyed by role, not positional: a <files> block names each of
               ## these at most once, and callers (the runtime advisory) need
               ## the filename for a specific role, not the Nth one present.
               $files[ $tag ] = $parser->getAttribute( 'filename' );
              break;

            case 'noise'     :
               ## Unlike the roles above, dbinst writes one <noise> per noise
               ## file (ri and ti noise can both be in use), so this is a list.
               if ( ! isset( $files[ 'noise' ] ) )
               {
                  $files[ 'noise' ] = array();
               }
               $files[ 'noise' ][] = $parser->getAttribute( 'filename' );
              break;
         }
      }
      $dataset[ 'files' ] = $files;
   }

   function parse_parameters( &$parser, &$dataset )
   {
      $parameters = array();
      $speedsteps = array();

      while ( $parser->read() )
      {
         if ( $parser->nodeType == XMLReader::END_ELEMENT &&
              $parser->name     == 'parameters' )
         {
              break;
         }

         $tag = $parser->name;
         if ( $tag == "#text" )
         { continue;
         }

         ## <speedstep> repeats, one per step, and carries its own attributes
         ## rather than a single "value" like every other parameter tag.
         if ( $tag == 'speedstep' )
         {
            array_push( $speedsteps, array(
               'rotorspeed'    => $parser->getAttribute( 'rotorspeed' ),
               'duration_hrs'  => $parser->getAttribute( 'duration_hrs' ),
               'duration_mins' => $parser->getAttribute( 'duration_mins' ),
            ) );
            continue;
         }

         $parameters[ $tag ] = $parser->getAttribute( 'value' );
      }

      $dataset[ 'parameters' ]  = $parameters;
      $dataset[ 'speedsteps' ]  = $speedsteps;
   }

   ## Estimated wall minutes for one candidate. $cluster and $mgroupcount
   ## default to the selected execution cluster and the published group count.
   public function maxwall( $cluster = null, $mgroupcount = null )
   {
      $parameters = $this->data[ 'job' ][ 'jobParameters' ];
      ## execution_cluster(), not cluster_shortname directly: a metascheduler
      ## pool submission resolves to a real cluster whose maxtime/ppn/maxproc
      ## can differ sharply from the pool's own pseudo-entry -- reading
      ## cluster_shortname here instead used the pool's own (often
      ## unconfigured/default) wall-time limit, confirmed giving a CG job 44
      ## minutes instead of the real cluster's 176.
      $cluster    = $cluster === null ? $this->execution_cluster() : $cluster;
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

      elseif ( preg_match( "/PCSA/", $this->data[ 'method' ] ) )  ## PCSA
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
         {
            $time       = $vsize * $gfiters;
         }
         else
         {
            $time       = $vsize * $vsize * $gfiters;
         }
         if ( $ti_noise || $ri_noise )
         { $time *= 2;
         }
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
                  {
                     $time      *= $points;
                  }
               }
            }
         }

         if ( $ti_noise || $ri_noise )
         { $time *= 2;
         }
         ## Double time for each noise option used
         if ( $ti_noise )
         {  $time *= 2;
         }
         if ( $ri_noise )
         {  $time *= 2;
         }

         if (  isset( $parameters[ 's_grid_points' ] )  &&
               isset( $parameters[ 'ff0_grid_points' ] ) )
         {
            $gpts_s     = $parameters[ 's_grid_points' ];
            $gpts_k     = $parameters[ 'ff0_grid_points' ];
            $gpts_t     = $gpts_s * $gpts_k;
            if ( $gpts_t > 200000 )
            {
               $time      *= 8;
            }
            elseif ( $gpts_t > 100000 )
            {
               $time      *= 4;
            }
            elseif ( $gpts_t > 50000 )
            {
               $time      *= 2;
            }
         }

         if ( isset( $dsparams[ 'simpoints' ] ) )
         {
            $simpts     = $dsparams[ 'simpoints' ];
            if ( $simpts < 1 )
            { $simpts = 1;
            }
            $spfact     = (int)( ( $simpts + 999 ) / 1000 );
            $time      *= $spfact;
         }

         if ( preg_match( "/CG/", $this->data[ 'method' ] ) )
         {
            $time *= 8;
            ## Apply the configured custom-grid slowdown for fixed-capacity clusters.
            $fixed_capacity = (bool) $this->cluster_opt( $cluster, 'fixed_capacity', false );
            if ( $fixed_capacity )
            {
               $time *= (float) $this->cluster_opt( $cluster, 'cg_time_multiplier', 4.0 );
            }
            elseif ( $mxiters > 0 )
            {  $time *= 2;
            }
         }
      }

      $montecarlo = 1;

      if ( isset( $parameters[ 'mc_iterations' ] ) )
      {
         $montecarlo = $parameters[ 'mc_iterations' ];
         if ( $montecarlo > 0 )
         {  $time *= $montecarlo;
         }
      }

      if ( $mxiters > 0 )
      {  $time *= $mxiters;
      }

      $time *= $dset_count;                   ## times number of datasets
      $time  = (int)( ( $time * 11 ) / 10 );  ## Padding (+10%)

      ## Account for parallel group count in max walltime
      if ( $montecarlo > 1  ||  $dset_count > 1 )
      {
         if ( $mgroupcount !== null )
         {
            $mgroupcount = (int) $mgroupcount;
         }
         elseif ( isset( $this->data[ 'job' ][ 'mgroupcount' ] ) )
         {
            $mgroupcount = $this->data[ 'job' ][ 'mgroupcount' ];
         }
         else
         {
            $mgroupcount = 1;
         }
      }
      else
      {
         $mgroupcount = 1;
      }

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

      return (int)$time;
   }

   ## Size one analysis group without reducing it to fit cluster capacity.
   ## Pure: reads $cluster's configuration and returns the GA effective ppbj
   ## and any capacity adjustment instead of writing them back.
   protected function tasks_per_group_plan( $cluster = null )
   {
      $cluster       = $cluster === null ? $this->execution_cluster() : $cluster;
      $cfg           = $this->grid[ $cluster ];
      $parameters    = $this->data[ 'job' ][ 'jobParameters' ];
      $fixed         = (bool) $this->cluster_opt( $cluster, 'fixed_capacity', false );
      $tasks_per_node = max( 1, (int) $cfg[ 'ppn' ] );
      $ppbj          = max( 1, (int) $cfg[ 'ppbj' ] );
      $effective_ppbj = $ppbj;
      $ppmg          = max( 1, (int) $this->cluster_opt( $cluster, 'procs_per_mgroup', 16 ) );
      $minimum       = $fixed ? min( $ppmg, $tasks_per_node ) : 1;
      $adjustments   = [];

      ## What one intact group asks for; refused below if it cannot fit.
      if ( preg_match( "/GA/", $this->data[ 'method' ] ) )
      {
         list( $method_demand, $effective_ppbj ) = $this->gaTasksPerGroup( $parameters, $ppbj, $fixed, $minimum );
      }
      elseif ( preg_match( "/2DSA/", $this->data[ 'method' ] ) )
      {
         $gsize = (int) $parameters[ 'uniform_grid' ];
         $method_demand = min( $ppbj, $gsize * $gsize );
      }
      elseif ( preg_match( "/PCSA/", $this->data[ 'method' ] ) )
      {
         $vsize = (int) $parameters[ 'vars_count' ];
         if ( $parameters[ 'curve_type' ] != 'HL' )
         {
            $vsize *= $vsize;
         }
         $method_demand = min( $ppbj, $vsize );
      }
      else
      {
         $method_demand = $ppbj;
      }

      $method_demand = max( $method_demand, $ppbj );
      $desired       = max( $method_demand, $minimum );

      ## GA: one MPI rank per deme plus the master. More demes than the cluster
      ## permits are limited to its capacity, as the GA page says, not refused.
      $maxproc = (int) $cfg[ 'maxproc' ];
      if ( preg_match( "/GA/", $this->data[ 'method' ] )  &&  $maxproc > 1  &&  $desired > $maxproc )
      {
         $adjustments[] = "GA limited to $maxproc tasks (" . ( $maxproc - 1 ) . " demes) on cluster $cluster";
         $desired = $maxproc;
      }

      return [
         'minimum'      => (int) $minimum,
         'desired'      => (int) $desired,
         'tasks_per_node' => (int) $tasks_per_node,
         'ppbj'         => (int) $effective_ppbj,
         'adjustments'  => $adjustments,
      ];
   }

   ## GA tasks per group and the effective ppbj, without changing configuration.
   private function gaTasksPerGroup( $parameters, $ppbj, $fixed, $minimum )
   {
      $demes = isset( $parameters[ 'demes' ] ) ? (int) $parameters[ 'demes' ] : 1;
      $auto  = ( $demes == 1 );    ## the user left the deme count to the system
      if ( $demes == 1 )
      {
         $demes = $ppbj - 1;
         if ( $fixed )
         {
            $demes = max( $minimum - 1, $demes );
         }
         if ( $ppbj == 9 )
         {
            $demes = max( 17, $demes );
         }
         $ppbj = $demes + 1;
      }
      $tasks = (int)( ( $demes + $ppbj ) / $ppbj ) * $ppbj;

      ## As on main: with the deme count left to the system and fewer than 16
      ## tasks per base job, GA runs twice the ranks. Several small demes evolve
      ## better than one large one, where a dominant trait can eliminate
      ## competitors before they are fully expressed. An explicit count is kept.
      if ( $auto  &&  $ppbj < 16 )
      {
         $tasks *= 2;
      }
      return [ $tasks, $ppbj ];
   }

   ## Return the selected cluster's resource plan, or false with an error when
   ## it cannot fit, and publish procs/mgroupcount for the submission. Integer
   ## fields describe per-group demand, requested/resolved group counts,
   ## allocated ranks per group, total MPI tasks, node capacity and node count.
   public function resource_plan()
   {
      $plan = $this->candidate_plan( $this->execution_cluster(), [ 'sizing_only' => true ] );
      if ( ! $plan[ 'ok' ] )
      {
         $this->message[] = $plan[ 'error' ];
         return false;
      }

      foreach ( $plan[ 'sizing_adjustments' ] as $note )
      {
         if ( ! in_array( $note, $this->message, true ) )
            $this->message[] = $note;
      }

      $this->data[ 'job' ][ 'procs' ]       = $plan[ 'allocated_tasks_per_group' ];
      $this->data[ 'job' ][ 'mgroupcount' ] = $plan[ 'resolved_groups' ];
      $this->data[ 'job' ][ 'resource_plan' ] = $plan;

      return $plan;
   }

   ## Why a cluster entry's sbatch_directives and structured keys would refuse every submission to it,
   ## or ''. For checking a configuration before the first submission (uslims_upgrade.php step 8).
   public static function site_directive_error( $cluster, array $cfg )
   {
      list( , $error ) = self::plan_directive_fields( $cluster, $cfg );
      return preg_replace( '/^ERROR:\s*/', '', (string) $error );
   }

   ## Directive flags the plan itself owns; a site directive may not set them.
   const PLAN_OWNED_DIRECTIVES =
      '/^#SBATCH\s+(-p|--partition|-J|--job-name|-N|--nodes|-n|--ntasks|--ntasks-per-node|-t|--time'
      . '|-o|--output|-e|--error|--nice)(=|\s|$)/';

   /**
    * The canonical plan for one explicit candidate cluster. Pure: it never
    * changes $this->grid, $this->data or the message list, so evaluating
    * candidates in any order gives the same plans. Returns ok => false with
    * an error when the job cannot run there. $options:
    *   priority_directive  the request's own #SBATCH --nice block, if any
    *   sizing_only         size only: skip site directives and wall time
    */
   public function candidate_plan( $cluster, array $options = [] )
   {
      if ( ! isset( $this->grid[ $cluster ] ) || ! is_array( $this->grid[ $cluster ] ) )
      {
         return [ 'ok' => false, 'cluster' => $cluster, 'error' => "ERROR: cluster $cluster is not configured" ];
      }

      $cfg           = $this->grid[ $cluster ];
      $parameters    = $this->data[ 'job' ][ 'jobParameters' ];
      $single        = (bool) $this->cluster_opt( $cluster, 'single_node', false );
      $maxproc       = max( 0, (int) $cfg[ 'maxproc' ] );
      $configured_ppn = (int) $cfg[ 'ppn' ];

      $fail = function ( $error ) use ( $cluster )
      {
         return [ 'ok' => false, 'cluster' => $cluster, 'error' => $error ];
      };

      if ( $configured_ppn < 1 )
      {
         return $fail( "ERROR: cluster $cluster has an invalid tasks-per-node capacity" );
      }
      if ( $single  &&  $maxproc > $configured_ppn )
      {
         return $fail( "ERROR: single-node cluster $cluster permits $maxproc tasks per job,"
                     . " but only $configured_ppn tasks on its node" );
      }

      $group_plan    = $this->tasks_per_group_plan( $cluster );
      $minimum       = $group_plan[ 'minimum' ];
      $desired       = $group_plan[ 'desired' ];
      $tasks_per_node = $group_plan[ 'tasks_per_node' ];

      $requested = max( 1, (int)( $parameters[ 'req_mgroupcount' ] ?? 1 ) );
      $resolved  = min( $requested, $this->group_ceiling( $desired, $maxproc, $cluster ) );

      if ( $resolved < 1 )
      {
         return $fail( "ERROR: one intact analysis group needs $desired tasks,"
                     . " but cluster $cluster permits at most $maxproc" );
      }

      $allocated = $desired;
      $total     = $allocated * $resolved;
      if ( $total % $resolved !== 0  ||  $total > max( $maxproc, $allocated ) )
      {
         return $fail( "ERROR: $total ranks cannot be split into $resolved intact groups within $maxproc on $cluster" );
      }

      ## Sizing alone never depends on site directives (resource_plan()).
      list( $directive_fields, $directive_error ) = empty( $options[ 'sizing_only' ] )
         ? $this->plan_directive_fields( $cluster, $cfg )
         : $this->plan_directive_fields( $cluster, [ 'sbatch_directives' => [] ] );
      if ( $directive_error !== '' )
      {
         return [ 'error_kind' => 'configuration' ] + $fail( $directive_error );
      }

      $priority = isset( $options[ 'priority_directive' ] ) ? (string) $options[ 'priority_directive' ] : '';
      foreach ( $priority === '' ? [] : explode( "\n", $priority ) as $line )
      {
         if ( $line !== '' && $line[ 0 ] !== '#' )
         {
            return $fail( "ERROR: priority directive for $cluster contains a non-directive line" );
         }
      }

      list( $walltime, $wallmins, $wall_note ) = empty( $options[ 'sizing_only' ] )
         ? $this->resolve_walltime( $cfg, $cluster, $resolved )
         : [ '', 0, '' ];

      $plan = [
         ## Legacy resource_plan() fields, unchanged.
         'minimum_tasks_per_group'   => (int) $minimum,
         'desired_tasks_per_group'   => (int) $desired,
         'requested_groups'          => (int) $requested,
         'resolved_groups'           => (int) $resolved,
         'allocated_tasks_per_group' => (int) $allocated,
         'total_tasks'               => (int) $total,
         'tasks_per_node'            => (int) $tasks_per_node,
         'node_count'                => max( 1, (int) ceil( $total / $tasks_per_node ) ),

         ## Canonical plan fields.
         'ok'                 => true,
         'cluster'            => $cluster,
         'profile'            => $cluster,
         'method'             => (string) $this->data[ 'method' ],
         'groups'             => (int) $resolved,
         'ranks'              => (int) $total,
         'cpus_per_task'      => 1,
         'nodes'              => max( 1, (int) ceil( $total / $tasks_per_node ) ),
         'single_node'        => $single,
         'effective_ppbj'     => (int) $group_plan[ 'ppbj' ],
         'walltime'           => $walltime,
         'walltime_minutes'   => (int) $wallmins,
         'partition'          => (string)( $cfg[ 'queue' ] ?? '' ),
         'launcher'           => (string)( $cfg[ 'mpi_launcher' ] ?? 'mpirun' ),
         'runtime_version'    => (string)( $cfg[ 'runtime_version' ] ?? '' ),
         'priority_directive' => $priority,
         'sizing_adjustments' => $group_plan[ 'adjustments' ],
         'adjustments'        => array_values( array_filter( array_merge(
                                    $group_plan[ 'adjustments' ], [ $wall_note ] ) ) ),
      ] + $directive_fields;

      $plan[ 'hash' ] = self::plan_hash( $plan );
      return $plan;
   }

   ## Plan every child of a batch on one cluster before pinning the batch
   ## there: ok only when every child fits. Returns
   ## [ 'ok' => bool, 'plans' => [ index => plan ], 'failed' => [ index => error ] ].
   public static function batch_preflight( array $children, $cluster, array $options = [] )
   {
      $plans  = [];
      $failed = [];
      foreach ( $children as $index => $child )
      {
         $plan = $child->candidate_plan( $cluster, $options );
         $plans[ $index ] = $plan;
         if ( ! $plan[ 'ok' ] )
            $failed[ $index ] = $plan[ 'error' ];
      }
      return [ 'ok' => ! $failed && $children, 'plans' => $plans, 'failed' => $failed ];
   }

   ## A stable fingerprint of a plan's contents, excluding the hash itself.
   public static function plan_hash( array $plan )
   {
      unset( $plan[ 'hash' ] );
      ksort( $plan );
      return substr( sha1( json_encode( $plan ) ), 0, 16 );
   }

   ## Wall time for one candidate: [ 'HH:MM:SS', minutes, clamp note or '' ].
   public function resolve_walltime( $cfg, $cluster, $mgroupcount )
   {
      ## usemaxtime: skip computed estimate, use the configured cluster maximum
      if ( ! empty( $cfg[ 'usemaxtime' ] ) ) {
         $max_time = (int) $cfg[ 'maxtime' ];
         if ( $max_time === 0 )
         {
            return [ "00:00:00", 999999, '' ];
         }  ## maxtime=0 means no limit
         $hours    = (int)( $max_time / 60 );
         $mins     = (int)( $max_time % 60 );
         return [ sprintf( "%02d:%02d:00", $hours, $mins ), $max_time, '' ];
      }

      $wall = $this->maxwall( $cluster, $mgroupcount ) * 3.0;

      if ( ! empty( $cfg[ 'wall_override' ] ) )
      {
         $wall = (float) $cfg[ 'wall_override' ];
      }

      ## Clamp to the cluster's queue limit; maxtime = 0 means unlimited
      $note = '';
      $max_time = isset( $cfg[ 'maxtime' ] ) ? (int) $cfg[ 'maxtime' ] : 0;
      if ( $max_time > 0  &&  $wall > $max_time ) {
         $note = "NOTE: walltime " . (int)$wall . " min exceeds cluster maxtime $max_time min; clamped to $max_time";
         $wall = (float) $max_time;
      }

      $hours    = (int)( $wall / 60 );
      $mins     = (int)( $wall % 60 );
      return [ sprintf( "%02d:%02d:00", $hours, $mins ), $hours * 60 + $mins, $note ];
   }

   ## Resolve site sbatch_directives and structured keys into plan fields:
   ## [ fields, error ]. A directive may not set what the plan owns, and a
   ## structured key and a directive may not disagree.
   protected static function plan_directive_fields( $cluster, array $cfg )
   {
      $directives = (array)( $cfg[ 'sbatch_directives' ] ?? [] );
      $fields = [ 'account' => '', 'qos' => '', 'constraint' => '', 'reservation' => '',
                  'exclusive' => false, 'memory_basis' => 'none', 'memory_value' => '',
                  'site_directives' => [] ];

      $named = [ 'account' => '/^#SBATCH\s+(?:--account=|-A\s*)(\S+)$/',
                 'qos' => '/^#SBATCH\s+--qos=(\S+)$/',
                 'constraint' => '/^#SBATCH\s+(?:--constraint=|-C\s*)(\S+)$/',
                 'reservation' => '/^#SBATCH\s+--reservation=(\S+)$/' ];

      foreach ( $directives as $directive )
      {
         if ( ! is_string( $directive )
              || strpos( $directive, "\n" ) !== false
              || strpos( $directive, "\r" ) !== false
              || strpos( $directive, '#SBATCH ' ) !== 0 )
         {
            return [ [], "invalid sbatch directive for cluster $cluster" ];
         }
         if ( preg_match( self::PLAN_OWNED_DIRECTIVES, $directive ) )
         {
            return [ [], "ERROR: sbatch directive '$directive' on cluster $cluster overrides a planned resource" ];
         }

         foreach ( $named as $field => $pattern )
         {
            if ( preg_match( $pattern, $directive, $m ) )
               $fields[ $field ] = $m[ 1 ];
         }
         if ( preg_match( '/^#SBATCH\s+--exclusive(=\S+)?$/', $directive ) )
         {
            $fields[ 'exclusive' ] = true;
         }
         if ( preg_match( '/^#SBATCH\s+--mem(-per-cpu)?=(\S+)$/', $directive, $m ) )
         {
            $basis = $m[ 1 ] !== '' ? 'per_cpu' : 'per_node';
            if ( $fields[ 'memory_basis' ] !== 'none' )
            {
               return [ [], "ERROR: cluster $cluster sets memory more than once in sbatch_directives" ];
            }
            $fields[ 'memory_basis' ] = $basis;
            $fields[ 'memory_value' ] = $m[ 2 ];
         }
         $fields[ 'site_directives' ][] = $directive;
      }

      ## Fall back to mempercore only when no batch directives are configured.
      if ( ! $directives  &&  isset( $cfg[ 'mempercore' ] ) )
      {
         $fields[ 'memory_basis' ] = 'per_cpu';
         $fields[ 'memory_value' ] = (string)(int) $cfg[ 'mempercore' ];
         $fields[ 'site_directives' ][] = '#SBATCH --mem-per-cpu=' . (int) $cfg[ 'mempercore' ];
      }

      foreach ( [ 'account', 'qos', 'constraint', 'reservation' ] as $field )
      {
         if ( ! isset( $cfg[ $field ] ) || (string) $cfg[ $field ] === '' )
            continue;
         $value = (string) $cfg[ $field ];
         if ( $fields[ $field ] !== '' && $fields[ $field ] !== $value )
         {
            return [ [], "ERROR: cluster $cluster sets $field to '$value' and to '{$fields[$field]}' in sbatch_directives" ];
         }
         if ( $fields[ $field ] === '' )
         {
            $fields[ $field ] = $value;
            $fields[ 'site_directives' ][] = "#SBATCH --$field=$value";
         }
      }
      if ( ! empty( $cfg[ 'exclusive' ] ) && ! $fields[ 'exclusive' ] )
      {
         $fields[ 'exclusive' ] = true;
         $fields[ 'site_directives' ][] = '#SBATCH --exclusive';
      }

      ## A profile's declared sharing policy: shared execution needs a memory
      ## request, so an unknown one cannot look like a fit; exclusive asks for
      ## whole nodes. A profile that declares neither keeps its current behavior.
      if ( isset( $cfg[ 'sharing' ] ) )
      {
         if ( $cfg[ 'sharing' ] === 'exclusive' && ! $fields[ 'exclusive' ] )
         {
            $fields[ 'exclusive' ] = true;
            $fields[ 'site_directives' ][] = '#SBATCH --exclusive';
         }
         elseif ( $cfg[ 'sharing' ] === 'shared' && $fields[ 'exclusive' ] )
         {
            return [ [], "ERROR: cluster $cluster is set for shared execution but also requests exclusive nodes" ];
         }
         elseif ( $cfg[ 'sharing' ] === 'shared' && $fields[ 'memory_basis' ] === 'none' )
         {
            return [ [], "ERROR: cluster $cluster is set for shared execution but requests no memory;"
                         . " set mempercore or a --mem directive, or use exclusive execution" ];
         }
         elseif ( ! in_array( $cfg[ 'sharing' ], [ 'shared', 'exclusive' ], true ) )
         {
            return [ [], "ERROR: cluster $cluster sharing must be 'shared' or 'exclusive'" ];
         }
      }

      return [ $fields, '' ];
   }

   ## Return the planned node count, or zero when sizing fails.
   public function nodes()
   {
      $plan = $this->resource_plan();
      return $plan === false ? 0 : $plan[ 'node_count' ];
   }

   ## Read a per-cluster tuning key, falling back to $default when absent.
   protected function cluster_opt( $cluster, $key, $default )
   {
      if ( ! array_key_exists( $cluster, $this->grid ) )
      {
         return $default;
      }

      return array_key_exists( $key, $this->grid[ $cluster ] )
             ? $this->grid[ $cluster ][ $key ]
             : $default;
   }

   ## How many groups this job may run, ignoring what was requested. The
   ## ceiling is the lower of what the method allows and what the cluster's
   ## rank budget holds, and is 0 when one intact group does not fit at all.
   protected function group_ceiling( $desired, $maxproc, $cluster = null )
   {
      $mciters = (int)( $this->data[ 'job' ][ 'jobParameters' ][ 'mc_iterations' ] ?? 1 );
      $limit   = 32;

      $cluster = $cluster === null ? $this->execution_cluster() : $cluster;
      if ( ! $this->cluster_opt( $cluster, 'pmg', false )
           || preg_match( "/SA/", $this->data[ 'method' ] ) )
      {
         $limit = 1;
      }
      elseif ( $mciters > 1 )
      {
         $limit = min( $limit, max( 1, (int)( $mciters / 2 ) ) );
      }

      ## Parallel masters needs at least three ranks per group. Below that,
      ## force one group so the standard single-master path runs instead.
      if ( $desired < 3 )
      {
         $limit = 1;
      }

      return min( $limit, $desired > 0 ? (int)( $maxproc / $desired ) : 0 );
   }

   ## Return the group ceiling before applying the requested group count.
   public function max_mgroupcount()
   {
      $cluster = $this->execution_cluster();
      $group_plan = $this->tasks_per_group_plan( $cluster );
      foreach ( $group_plan[ 'adjustments' ] as $note )
      {
         if ( ! in_array( $note, $this->message, true ) )
            $this->message[] = $note;
      }

      return $this->group_ceiling(
         $group_plan[ 'desired' ],
         max( 0, (int) $this->grid[ $cluster ][ 'maxproc' ] ), $cluster );
   }
}
