<?php
/*
 * runtime_advisory.php
 *
 * The advisory path, behind one configuration switch.
 *
 * WHY THIS CLASS EXISTS AT ALL. The call site in submit_slurm is one statement,
 * and it has to stay that way: everything that can go wrong here belongs on
 * this side of the call, not in the submission path. So this holds the switch,
 * the artifact, the per-process cache, the timing and the whole failure
 * surface, and it returns a status string the caller may log and may ignore.
 *
 * ADVISORY MEANS ADVISORY. Nothing here can change what is submitted. The
 * caller is handed no value it could use, and observe() returns a status, not a
 * limit. The scheduler directive is still whatever resolveWalltime() produced.
 *
 * NOTHING HERE MAY THROW. A prediction is worth less than a submission, so
 * every failure is caught, recorded if it can be, and swallowed. That is also
 * why the switch is checked before anything is loaded: with the advisory off,
 * this costs one array lookup and touches no file and no database.
 *
 * TURNING IT OFF. $global_runtime_advisory_enabled in global_config.php, which
 * is per-host and survives a deploy. Editing the call site out of this
 * repository's code would be undone by the next pull, silently.
 */

if ( ! class_exists( 'runtime_model' ) )
{
   require_once __DIR__ . '/runtime_model.php';
   require_once __DIR__ . '/runtime_features.php';
   require_once __DIR__ . '/runtime_dataset_facts.php';
   require_once __DIR__ . '/runtime_record.php';
}

class runtime_advisory
{
   ## Where the frozen artifact is installed. Override with
   ## $global_runtime_advisory_artifact only where a host differs.
   const ARTIFACT = '/home/us3/lims/etc/runtime_advisory.json';

   ## The reference's own version, recorded beside the value so a later change
   ## to how the incumbent is computed is visible in the records.
   const FORMULA_VERSION = 'resolveWalltime/1';

   ## Resolved once per process: the artifact does not change under a running
   ## pool, and re-reading it per submission would be the one real cost here.
   private static $model = false;
   private static $load_error = null;

   /**
    * Record a recommendation for one submission. Never throws, never alters
    * the submission, and returns a short status for the caller's log.
    *
    * @param array $context see runtime_record::row(), plus:
    *                       parameters   the request's jobParameters
    *                       speedsteps   the first dataset's speed steps
    *                       simpoints    the first dataset's simulation points
    *                       edit_filename the first dataset's edit filename
    *                       link         the instance database
    *                       gfac_link    the global database, where records go
    */
   public static function observe( array $context )
   {
      if ( ! self::enabled() )
      {
         return 'disabled';
      }

      $started = hrtime( true );

      try {
         return self::run( $context, $started );
      } catch ( Throwable $e ) {
         ## Including an error in the recording of an error. The submission is
         ## already past this point and must not learn about any of it.
         self::note( $context, 'advisory_error', $e->getMessage(), $started );
         return 'advisory_error';
      }
   }

   /**
    * Does a request's method belong to the artifact's family?
    *
    * A prefix match, so 2DSA-MC and 2DSA-CG count as 2DSA while GA and PCSA do
    * not. Case-insensitive, because the method reaches here from the request.
    */
   public static function family_matches( $family, $method )
   {
      $family = strtolower( trim( (string) $family ) );
      $method = strtolower( trim( (string) $method ) );

      if ( $family === '' || $method === '' )
      {
         return false;
      }

      return strpos( $method, $family ) === 0;
   }

   /** Is the advisory on? Absent, false or 0 all mean off. */
   public static function enabled()
   {
      return ! empty( $GLOBALS[ 'global_runtime_advisory_enabled' ] );
   }

   /** Forget the cached artifact. For tests, and for a long-lived worker. */
   public static function reset()
   {
      self::$model      = false;
      self::$load_error = null;
   }

   private static function run( array $context, $started )
   {
      $model = self::model();

      if ( $model === null )
      {
         return self::note( $context, 'artifact_error', self::$load_error, $started );
      }

      $context[ 'artifact_sha256' ] = $model->fingerprint();
      $context[ 'family' ]          = $model->family();
      $context[ 'formula_version' ] = self::FORMULA_VERSION;

      ## The artifact is fitted for one family. A request from another one can
      ## still carry enough parameters by the same names to build a vector, so
      ## without this a GA or PCSA job would be scored by the 2DSA model and the
      ## answer would look exactly like a good one. Narrowing further, to the
      ## standard variant only, is the readout's population rule rather than a
      ## runtime check: the artifact declares a family, not a method list.
      $method = isset( $context[ 'method' ] ) ? (string) $context[ 'method' ] : '';

      if ( ! self::family_matches( $model->family(), $method ) )
      {
         return self::note( $context, 'unsupported',
            "method '" . substr( $method, 0, 32 ) . "' is not "
            . $model->family() . ", which this model was fitted for", $started );
      }

      $dataset = runtime_dataset_facts::build(
         isset( $context[ 'link' ] ) ? $context[ 'link' ] : null,
         isset( $context[ 'us3_db' ] ) ? $context[ 'us3_db' ] : '',
         isset( $context[ 'edit_filename' ] ) ? $context[ 'edit_filename' ] : null,
         isset( $context[ 'speedsteps' ] ) && is_array( $context[ 'speedsteps' ] )
            ? $context[ 'speedsteps' ] : array(),
         isset( $context[ 'simpoints' ] ) ? $context[ 'simpoints' ] : null
      );

      $built = runtime_features::build(
         $model,
         isset( $context[ 'parameters' ] ) && is_array( $context[ 'parameters' ] )
            ? $context[ 'parameters' ] : array(),
         $dataset,
         isset( $context[ 'destination' ] ) ? $context[ 'destination' ] : ''
      );

      ## An operand that should have been readable and was not is the adapter's
      ## business to report, not something to quietly impute.
      if ( $built[ 'status' ] === 'ok' && $dataset[ 'issues' ] )
      {
         $built[ 'status' ] = 'input_error';
         $built[ 'reason' ] = implode( '; ', $dataset[ 'issues' ] );
      }

      $outcome = array();

      if ( $built[ 'status' ] === 'ok' )
      {
         $prediction = $model->predict( $built[ 'features' ] );
         $outcome    = array(
            'prediction_seconds' => $prediction,
            'multiplier'         => $model->multiplier(),
            'allowance_seconds'  => $model->allowance_seconds(
                                       $prediction,
                                       isset( $context[ 'formula_reference_seconds' ] )
                                          ? $context[ 'formula_reference_seconds' ] : null ),
            'gate_accepted'      => $model->gate_accepts( $built[ 'features' ] ),
         );
      }

      $context[ 'evaluate_us' ] = self::elapsed_us( $started );

      $stored = runtime_record::insert(
         isset( $context[ 'gfac_link' ] ) ? $context[ 'gfac_link' ] : null,
         runtime_record::row( $context, $built, $outcome )
      );

      ## A lost record costs coverage, which the readout counts. It must still be
      ## visible, or a window with no records looks like a window with no jobs.
      return $stored ? $built[ 'status' ] : 'record_error';
   }

   /**
    * The frozen artifact, loaded once. Null when it cannot be used, with the
    * reason kept for the record: an advisory that silently stops producing
    * recommendations is worse than one that says why.
    */
   private static function model()
   {
      if ( self::$model !== false )
      {
         return self::$model;
      }

      try {
         self::$model = runtime_model::load( self::artifact_path() );
      } catch ( Throwable $e ) {
         self::$model      = null;
         self::$load_error = $e->getMessage();
      }

      return self::$model;
   }

   private static function artifact_path()
   {
      $configured = isset( $GLOBALS[ 'global_runtime_advisory_artifact' ] )
                    ? trim( (string) $GLOBALS[ 'global_runtime_advisory_artifact' ] ) : '';

      return $configured !== '' ? $configured : self::ARTIFACT;
   }

   /** Record a failure that happened before there was anything to predict. */
   private static function note( array $context, $status, $reason, $started )
   {
      $context[ 'evaluate_us' ] = self::elapsed_us( $started );

      runtime_record::insert(
         isset( $context[ 'gfac_link' ] ) ? $context[ 'gfac_link' ] : null,
         runtime_record::row(
            $context,
            array( 'status' => $status, 'features' => null, 'missing' => array(), 'reason' => $reason ),
            array()
         )
      );

      return $status;
   }

   /** Monotonic, in microseconds: the advisory path's own cost. */
   private static function elapsed_us( $started )
   {
      return (int) round( ( hrtime( true ) - $started ) / 1000 );
   }
}
