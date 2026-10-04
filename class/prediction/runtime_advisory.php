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
 *
 * ONE ARTIFACT PER FAMILY. A model is fitted for one method family, so the
 * request's method chooses the artifact rather than the other way round: the
 * family is read from the method, that family's artifact is loaded, and the
 * artifact's own declared family is then checked against it. Two independent
 * statements of the same fact, because the quiet failure here is a job scored
 * by another family's model, which produces a plausible number from the
 * parameters that happen to share a name. A family with no artifact installed
 * is unsupported, which is how a family is left out of the window.
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
   ## Where the frozen artifacts are installed, one file per family named
   ## runtime_advisory_<family>.json. Override with
   ## $global_runtime_advisory_artifact only where a host differs.
   const ARTIFACT_DIR    = '/home/us3/lims/etc';
   const ARTIFACT_PREFIX = 'runtime_advisory_';

   ## The families an artifact can be fitted for, longest name first so a
   ## method is matched against the most specific one that fits.
   private static $families = array( 'PCSA', '2DSA', 'GA' );

   ## The reference's own version, recorded beside the value so a later change
   ## to how the incumbent is computed is visible in the records.
   const FORMULA_VERSION = 'resolveWalltime/1';

   ## Resolved once per process per family: an artifact does not change under a
   ## running pool, and re-reading it per submission would be the one real cost
   ## here. A family that failed to load is cached as a failure too, so a
   ## missing file is read once rather than on every submission.
   private static $models = array();
   private static $load_errors = array();

   ## Whether a family's path was chosen for that family, which decides what a
   ## family mismatch in the loaded file means. Filled in alongside the model.
   private static $per_family = array();

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

      $started = self::clock();

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

   /**
    * Which family's model should score this request, or null for none.
    *
    * The same prefix rule as family_matches(), applied to the installed
    * families: 2DSA-MC is 2DSA, GA-MC is GA, and DMGA is nothing, because the
    * match is anchored at the start of the method rather than anywhere in it.
    */
   public static function family_of_method( $method )
   {
      foreach ( self::$families as $family )
      {
         if ( self::family_matches( $family, $method ) )
         {
            return $family;
         }
      }

      return null;
   }

   /** The families this build can serve, whatever is installed on a host. */
   public static function families()
   {
      return self::$families;
   }

   /** Is the advisory on? Absent, false or 0 all mean off. */
   public static function enabled()
   {
      return ! empty( $GLOBALS[ 'global_runtime_advisory_enabled' ] );
   }

   /** Forget the cached artifacts. For tests, and for a long-lived worker. */
   public static function reset()
   {
      self::$models      = array();
      self::$load_errors = array();
      self::$per_family  = array();
   }

   private static function run( array $context, $started )
   {
      $context[ 'formula_version' ] = self::FORMULA_VERSION;

      ## Narrowing further, to the standard variant only, is the readout's
      ## population rule rather than a runtime check: an artifact declares a
      ## family, not a method list.
      $method = isset( $context[ 'method' ] ) ? (string) $context[ 'method' ] : '';
      $family = self::family_of_method( $method );

      if ( $family === null )
      {
         return self::note( $context, 'unsupported',
            "method '" . substr( $method, 0, 32 ) . "' belongs to no family"
            . ' the advisory serves', $started );
      }

      $context[ 'family' ] = $family;
      $model               = self::model( $family );

      if ( $model === null )
      {
         ## No reason recorded means no artifact was configured for this family,
         ## rather than one that was configured and could not be read.
         return self::$load_errors[ $family ] === null
            ? self::note( $context, 'unsupported',
                 "no artifact is configured for $family", $started )
            : self::note( $context, 'artifact_error', self::$load_errors[ $family ], $started );
      }

      ## The method said which artifact to load; the artifact says what it was
      ## fitted for. The result of ignoring a disagreement would be a prediction
      ## built from whichever parameters the two families name alike, which
      ## looks exactly like a good one.
      ##
      ## What the disagreement means depends on how the path was chosen. A file
      ## named for this family holding another family's model is a misinstalled
      ## artifact. A single file configured for the whole host is simply not
      ## this family's model, so this family is one the host does not serve.
      if ( ! self::family_matches( $model->family(), $method ) )
      {
         $declared = substr( (string) $model->family(), 0, 32 );

         return self::$per_family[ $family ]
            ? self::note( $context, 'artifact_error',
                 "the artifact installed for $family declares family $declared", $started )
            : self::note( $context, 'unsupported',
                 "this host's artifact is fitted for $declared, not $family", $started );
      }

      $context[ 'artifact_sha256' ] = $model->fingerprint();

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
    * One family's frozen artifact, loaded once. Null when it cannot be used,
    * with the reason kept for the record: an advisory that silently stops
    * producing recommendations is worse than one that says why.
    */
   private static function model( $family )
   {
      if ( array_key_exists( $family, self::$models ) )
      {
         return self::$models[ $family ];
      }

      $chosen                      = self::artifact_path( $family );
      self::$per_family[ $family ] = $chosen[ 'per_family' ];

      if ( $chosen[ 'path' ] === null )
      {
         self::$models[ $family ]      = null;
         self::$load_errors[ $family ] = null;

         return null;
      }

      try {
         self::$models[ $family ] = runtime_model::load( $chosen[ 'path' ] );
      } catch ( Throwable $e ) {
         self::$models[ $family ]      = null;
         self::$load_errors[ $family ] = $e->getMessage();
      }

      return self::$models[ $family ];
   }

   /**
    * Where a family's artifact lives.
    *
    * $global_runtime_advisory_artifact is read three ways, because a host may
    * be running one family or several: an array keyed by family names the file
    * per family, a single string names one file for whichever family it was
    * fitted for, and absent means the installed layout. The string form is not
    * a shortcut past the family check, which still has to pass.
    *
    * @return array path, null where this family is not configured at all, and
    *               whether the path was chosen for this family
    */
   private static function artifact_path( $family )
   {
      $configured = isset( $GLOBALS[ 'global_runtime_advisory_artifact' ] )
                    ? $GLOBALS[ 'global_runtime_advisory_artifact' ] : null;

      if ( is_array( $configured ) )
      {
         foreach ( $configured as $key => $path )
         {
            if ( strcasecmp( (string) $key, (string) $family ) === 0 && trim( (string) $path ) !== '' )
            {
               return array( 'path' => trim( (string) $path ), 'per_family' => true );
            }
         }

         ## A map that does not name this family is a decision to leave it out,
         ## which is different from a file that should be there and is not.
         return array( 'path' => null, 'per_family' => true );
      }
      else if ( is_string( $configured ) && trim( $configured ) !== '' )
      {
         return array( 'path' => trim( $configured ), 'per_family' => false );
      }

      return array(
         'path'       => self::ARTIFACT_DIR . '/' . self::ARTIFACT_PREFIX . $family . '.json',
         'per_family' => true,
      );
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

   /**
    * A clock reading, in nanoseconds where one is available.
    *
    * hrtime() is monotonic and is what this wants, but it arrived in PHP 7.3
    * and the appliances run 7.2: calling it there is a fatal Error, thrown
    * before the guard in observe() can catch it, inside a submission. So 7.2
    * falls back to microtime(), which is not monotonic and so can be perturbed
    * by a clock step. That only distorts a recorded duration; it cannot affect
    * a submission, which is the right way round for this trade.
    */
   private static function clock()
   {
      return function_exists( 'hrtime' ) ? hrtime( true ) : (int) round( microtime( true ) * 1e9 );
   }

   /** The advisory path's own cost, in microseconds. */
   private static function elapsed_us( $started )
   {
      $elapsed = (int) round( ( self::clock() - $started ) / 1000 );

      ## A non-monotonic fallback can read backwards across a clock step.
      return $elapsed < 0 ? 0 : $elapsed;
   }
}
