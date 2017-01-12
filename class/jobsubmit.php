<?php
/*
 * jobsubmit.php
 *
 * Base class for common elements used to submit an analysis
 *
 */
class jobsubmit
{
   protected $data    = array();   // Global parsed input
   protected $jobfile = "";        // Global string
   protected $message = array();   // Errors and other messages
   protected $grid    = array();   // Information about the clusters
   protected $xmlfile = "";        // Base name of the experiment xml file
 
   function __construct()
   {
      global $globaldbname;

      $subhost = "http://gridfarm005.ucs.indiana.edu";
      $subport = 8080;

      $this->grid[ 'us3iab-node0' ] = array 
      (
        "name"       => "us3iab-node0.aucsolutions.com",
        "submithost" => "localhost",
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/export/home/us3/lims/work/",
        "sshport"    => 22,
        "queue"      => "normal",
        "maxtime"    => 2160,
        "ppn"        => 8,
        "ppbj"       => 8,
        "maxproc"    => 16
      );

      $this->grid[ 'us3iab-node1' ] = array 
      (
        "name"       => "us3iab-node1.aucsolutions.com",
        "submithost" => "localhost",
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/export/home/us3/lims/work/",
        "sshport"    => 22,
        "queue"      => "normal",
        "maxtime"    => 2160,
        "ppn"        => 8,
        "ppbj"       => 8,
        "maxproc"    => 16
      );

      $this->grid[ 'us3iab-devel' ] = array 
      (
        "name"       => "us3iab-devel.attlocal.net",
        "submithost" => "localhost",
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/export/home/us3/lims/work/",
        "sshport"    => 22,
        "queue"      => "normal",
        "maxtime"    => 2160,
        "ppn"        => 8,
        "ppbj"       => 8,
        "maxproc"    => 16
      );
    
      $this->grid[ 'bcf' ] = array 
      (
        "name"       => "bcf.uthscsa.edu",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/ogce-rest/job/runjob/async",
        "sshport"    => 22,
        "queue"      => "default",
        "maxtime"    => 60000,
        "ppn"        => 2,
        "ppbj"       => 8,
        "maxproc"    => 12
      );
    
      $this->grid[ 'bcf-local' ] = array 
      (
        "name"       => "bcf.uthscsa.edu",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "local",
        "httpport"   => $subport,
        "workdir"    => "/home/us3/work/",  // Need trailing slash
        "sshport"    => 22,
        "queue"      => "default",
        "maxtime"    => 60000,
        "ppn"        => 2,
        "ppbj"       => 8,
        "maxproc"    => 12
      );

      $this->grid[ 'jacinto' ] = array 
      (
        "name"       => "jacinto.uthscsa.edu",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/ogce-rest/job/runjob/async",
        "sshport"    => 22,
        "queue"      => "default",
        "maxtime"    => 2160,
        "ppn"        => 4,
        "ppbj"       => 8,
        "maxproc"    => 32
      );
    
      $this->grid[ 'jacinto-local' ] = array 
      (
        "name"       => "jacinto.uthscsa.edu",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "local",
        "httpport"   => $subport,
        "workdir"    => "/home/us3/work/",  // Need trailing slash
        "sshport"    => 22,
        "queue"      => "default",
        "maxtime"    => 2160,
        "ppn"        => 4,
        "ppbj"       => 8,
        "maxproc"    => 32
      );

      $this->grid[ 'alamo' ] = array 
      (
        "name"       => "alamo.uthscsa.edu",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/ogce-rest/job/runjob/async",
        "sshport"    => 22,
        "queue"      => "default",
        "maxtime"    => 2160,
        "ppn"        => 24,
        "ppbj"       => 24,
        "maxproc"    => 48
      );
    
      $this->grid[ 'alamo-local' ] = array 
      (
        "name"       => "alamo.uthscsa.edu",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "local",
        "httpport"   => $subport,
        "workdir"    => "/home/us3/work/",  // Need trailing slash
        "sshport"    => 22,
        "queue"      => "default",
        "maxtime"    => 2160,
        "ppn"        => 24,
        "ppbj"       => 24,
        "maxproc"    => 48
      );

      $this->grid[ 'lonestar5' ] = array
      (
        "name"       => "ls5.tacc.utexas.edu",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/ogce-rest/job/runjob/async",
        "sshport"    => 22,
        "queue"      => "normal",
        "maxtime"    => 1440,
        "ppn"        => 12,
        "ppbj"       => 24,
        "maxproc"    => 72
      );

      $this->grid[ 'gordon' ] = array 
      (
        "name"       => "gordon.sdsc.xsede.org",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/ogce-rest/job/runjob/async",
        "sshport"    => 22,
        "queue"      => "normal",
        "maxtime"    => 1440,
        "ppn"        => 16,
        "ppbj"       => 32,
        "maxproc"    => 64
      );

      $this->grid[ 'comet' ] = array 
      (
        "name"       => "comet.sdsc.xsede.org",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/ogce-rest/job/runjob/async",
        "sshport"    => 22,
        "queue"      => "normal",
        "maxtime"    => 1440,
        "ppn"        => 24,
        "ppbj"       => 24,
        "maxproc"    => 72
      );

      $this->grid[ 'stampede' ] = array 
      (
        "name"       => "stampede.tacc.xsede.org",
        "submithost" => $subhost,
        "userdn"     => "/C=US/O=National Center for Supercomputing Applications/CN=Ultrascan3 Community User",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/ogce-rest/job/runjob/async",
        "sshport"    => 22,
        "queue"      => "normal",
        "maxtime"    => 1440,
        "ppn"        => 16,
        "ppbj"       => 32,
        "maxproc"    => 64
      );
    
      $this->grid[ 'jureca' ] = array 
      (
        "name"       => "jureca.fz-juelich.de",
        "submithost" => $subhost,
        "userdn"     => "CN=_USER_, O=Ultrascan Gateway, C=DE",
        "submittype" => "http",
        "httpport"   => $subport,
        "workdir"    => "/ogce-rest/job/runjob/async",
        "sshport"    => 22,
        "queue"      => "batch",
        "maxtime"    => 1440,
        "ppn"        => 24,
        "ppbj"       => 24,
        "maxproc"    => 72
      );
    
   }
   
   // Deconstructor
   function __destruct()
   {
      $this->clear();
   }

   // Clear out data for another request
   function clear()
   {
      $this->data    = array();
      $this->jobfile = "";
      $this->message = array();
      $this->xmlfile = "";
   }

   // Request status
   function status()
   {
      if ( isset( $this->data['dataset']['status'] ) )
         return $this->data['dataset']['status'];

      return 'Status unavailable';
   }

   // Return any messages
   function get_messages()
   {
      return $this->message;
   }

   // Read and parse submitted xml file
   function parse_input( $xmlfile )
   {
      $this->xmlfile = $xmlfile;          // Save for other methods
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
      $max_time   = $this->grid[ $cluster ][ 'maxtime' ];
 
      if ( preg_match( "/GA/", $this->data[ 'method' ] ) )
      {
         // Assume 1 sec a basic unit

         $generations = $parameters[ 'generations' ];
         $population  = $parameters[ 'population' ];

         // The constant 125 is an empirical value from doing a Hessian
         // minimization

         $time        = ( 125 + $population ) * $generations;
 
         $time *= 1.2;  // Pad things a bit
         $time  = (int)( ($time + 59) / 60 ); // Round up to minutes
      }

      else // 2DSA or PCSA
      {
         $ti_noise   = isset( $parameters[ 'tinoise_option' ] )
                       ? $parameters[ 'tinoise_option' ] > 0 
                       : false;

         $ri_noise   = isset( $parameters[ 'rinoise_option' ] )
                       ? $parameters[ 'rinoise_option' ] > 0
                       : false;

         $time       = 5;  // Base time in minutes

         if ( isset( $parameters[ 'meniscus_points' ] ) )
         {
            $points = $parameters[ 'meniscus_points' ];
            if ( $points > 0 )  $time *= $points;
         }

         if ( $ti_noise || $ri_noise ) $time *= 2;

         if ( preg_match( "/PCSA/", $this->data[ 'method' ] ) )
         {
            $time *= 3;
         }

         if ( preg_match( "/CG/", $this->data[ 'method' ] ) )
         {
            $time *= 2;
            if ( preg_match( "/us3iab/", $cluster ) )
               $time *= 4;
         }
      }
 
      $montecarlo = 1;
 
      if ( isset( $parameters[ 'mc_iterations' ] ) )
      {
         $montecarlo = $parameters[ 'mc_iterations' ];
         if ( $montecarlo > 0 )  $time *= $montecarlo;
      }

      if ( isset( $parameters[ 'max_iterations' ] ) )
      {
         $mxiters = $parameters[ 'max_iterations' ];
         if ( $mxiters > 0 )  $time *= $mxiters;
      }

      $time *= $dset_count;                   // times number of datasets
      $time  = (int)( ( $time * 11 ) / 10 );  // Padding
 
      // Account for parallel group count in max walltime
      if ( $montecarlo > 1 )
         $mgroupcount = $this->data[ 'job' ][ 'mgroupcount' ];
      else if ( $dset_count > 1 )
         $mgroupcount = $this->data[ 'job' ][ 'mgroupcount' ];
      else
         $mgroupcount = 1;

      // Adjust max wall time down based on parallel group count
      switch ( $mgroupcount )
      {
         case 1  :
         case 2  :
            $time = (int)( ( $time * 10 ) / 15 );
            break;

         case 3  :
         case 4  :
         case 5  :
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

         case 1  :
         default :
            break;
      }

      $time = max( $time, 5 );         // Minimum time is 5 minutes

      if ( $cluster == 'alamo' || $cluster == 'alamo-local' )
      {  // For alamo, $max_time is hardwired to 2160, and no PMG
         $time        = $max_time;
         //$mgroupcount = min( 2, $mgroupcount );
      }

      else if ( $cluster == 'jacinto' || $cluster == 'jacinto-local' )
      {  // For jacinto, $max_time is hardwired to 2160, and no PMG
         $time        = $max_time;
         $mgroupcount = min( 2, $mgroupcount );
      }

      else if ( $cluster == 'bcf' || $cluster == 'bcf-local' )
      {  // For bcf, hardwire $max_time to 240 (4 hours), and no PMG
         $time        = $max_time;
         $mgroupcount = 1;
      }

      else
      {  // Maximum time is defined for each cluster
         $time        = min( $time, $max_time );
      }
 
      return (int)$time;
   }
 
   function nodes()
   {
      $cluster    = $this->data[ 'job' ][ 'cluster_shortname' ];
      $is_us3iab  = preg_match( "/us3iab/", $cluster );
      $parameters = $this->data[ 'job' ][ 'jobParameters' ];
      $max_procs  = $this->grid[ $cluster ][ 'maxproc' ];
      $ppn        = $this->grid[ $cluster ][ 'ppn'     ];
      $ppbj       = $this->grid[ $cluster ][ 'ppbj'    ];
 
      if ( $is_us3iab )
      {  // It is "us3iab"
         $mgroup     = 1;
         $dset_count = $this->data[ 'job' ][ 'datasetCount' ];
         $montecarlo = $parameters[ 'mc_iterations' ];

         if ( preg_match( "/GA/", $this->data[ 'method' ] ) )
         {  // GA or DMGA
            if ( $montecarlo < 2 )
            {  // Non-MC GA
               $ppn        = 16;
            }
            else
            {  // GA-MC
               if ( isset( $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] ) )
               {
                  $mgroup     = $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ];
                  if ( $mgroup > 1 )
                     $ppn     = (int)( $max_procs / $mgroup );
                  $ppn        = max( $ppn, 16 );
               }
               else
               {
                  $mgroup     = 1;
                  $ppn        = 16;
                  $this->data[ 'job' ][ 'jobParameters' ][ 'req_mgroupcount' ] = $mgroup;
               }
               $this->data[ 'job' ][ 'mgroupcount' ] = $mgroup;
            }
         }

         $max_procs  = $ppn;
         $this->grid[ $cluster ][ 'maxproc' ] = $max_procs;
         $this->grid[ $cluster ][ 'ppn'     ] = $ppn;
      }  // End: us3iab
 
      if ( preg_match( "/GA/", $this->data[ 'method' ] ) )
      {  // GA: procs is demes+1 rounded to procs-per-node
         $demes = $parameters[ 'demes' ];
         if ( $demes == 1 )
         {
            $demes = $ppbj - 1;
            if ( $is_us3iab )
               $demes = max( 15, $demes );
         }
         $procs = $demes + $ppn;                  // Procs = demes+1
         $procs = (int)( $procs / $ppn ) * $ppn;  // Rounded to procs-per-node
      }
      else if ( preg_match( "/2DSA/", $this->data[ 'method' ] ) )
      {  // 2DSA:  procs is max_procs, but no more than subgrid count
         $gsize = $parameters[ 'uniform_grid' ];
         $gsize = $gsize * $gsize;           // Subgrid count
         $procs = min( $ppbj, $gsize );      // Procs = base or subgrid count
      }
      else if ( preg_match( "/PCSA/", $this->data[ 'method' ] ) )
      {  // PCSA:  procs is max_procs, but no more than vars_count
         $vsize = $parameters[ 'vars_count' ];
         if ( $parameters[ 'curve_type' ] != 'HL' )
            $vsize = $vsize * $vsize;        // Variations count
         $procs = min( $ppbj, $vsize );      // Procs = base or subgrid count
      }

      $procs = max( $procs, $ppn );          // Minimum procs is procs-per-node
      $procs = min( $procs, $max_procs );    // Maximum procs depends on cluster

      $nodes = (int)$procs / $ppn;    // Return nodes, procs divided by procs-per-node
      return $nodes;
   }

   function max_mgroupcount()
   {
      $cluster    = $this->data[ 'job' ][ 'cluster_shortname' ];
      $max_procs  = $this->grid[ $cluster ][ 'maxproc' ];
      $max_groups = 32;

      if ( preg_match( "/SA/", $this->data[ 'method' ] ) )
      {  // For 2DSA/PCSA, PMGs is always 1
         $max_groups = 1;
      }

      else if ( preg_match( "/us3iab/", $cluster ) )
      {   // Us3iab PMGs limited by max procs available
         $max_groups = $max_procs / 16;
      }
 
      else if ( preg_match( "/alamo/", $cluster ) )
      {  // Alamo can have no more than 16 PMGs
         $max_groups = 16;
      }

      return $max_groups;
   }
}
?>
