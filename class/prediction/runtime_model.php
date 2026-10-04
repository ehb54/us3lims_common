<?php
/*
 * runtime_model.php
 *
 * Evaluates a frozen runtime-prediction artifact in process: no service, no
 * network call, no Python. Arithmetic only, so a submission never waits on
 * anything but local CPU.
 *
 * The artifact owns the whole contract: input names, which of them are
 * categorical, the vocabulary, the numeric transform, the imputer statistics,
 * the scaler, the coefficients, the policy multiplier and the admission gate.
 * Nothing here is a second copy of a value that lives there, because two copies
 * drift and the drift is invisible in the output.
 *
 * The design matrix is built in the artifact's own column order: for each input
 * name, either one indicator per vocabulary entry or one transformed numeric,
 * then one missing-value flag. load() checks the order it would generate
 * against the order the artifact records, so an artifact built by a different
 * exporter is refused rather than silently scored against shifted coefficients.
 */

class runtime_model
{
   ## Target clamp, in log10 seconds, from the exporter's inverse transform.
   const LOG_MIN = -6.0;
   const LOG_MAX = 12.0;

   ## The gate's acceptance band, in standard deviations.
   const GATE_SIGMA = 3.0;

   private $spec;
   private $categorical;   ## name => true
   private $fingerprint;

   private function __construct( $spec, $fingerprint )
   {
      $this->spec        = $spec;
      $this->fingerprint = $fingerprint;
      $this->categorical = array();

      foreach ( $spec[ 'cats' ] as $name )
      {
         $this->categorical[ $name ] = true;
      }
   }

   /**
    * Read and validate an artifact. Throws on anything malformed, so a caller
    * that holds an instance holds a usable model.
    */
   public static function load( $path )
   {
      if ( ! is_readable( $path ) )
      {
         throw new RuntimeException( "runtime_model: artifact is not readable: $path" );
      }

      $raw = file_get_contents( $path );

      if ( $raw === false || $raw === '' )
      {
         throw new RuntimeException( "runtime_model: artifact is empty: $path" );
      }

      $spec = json_decode( $raw, true );

      if ( ! is_array( $spec ) )
      {
         throw new RuntimeException( "runtime_model: artifact is not valid JSON: $path" );
      }

      foreach ( array( 'family', 'names', 'cats', 'encoding', 'imputer_statistics',
                       'scaler_mean', 'scaler_scale', 'coef', 'intercept', 'k', 'gate' ) as $key )
      {
         if ( ! array_key_exists( $key, $spec ) )
         {
            throw new RuntimeException( "runtime_model: artifact has no '$key': $path" );
         }
      }

      $model = new self( $spec, hash( 'sha256', $raw ) );
      $model->check_shapes( $path );

      return $model;
   }

   /** Every vector is one per design column, and the order is the recorded one. */
   private function check_shapes( $path )
   {
      $n = (int) $this->spec[ 'encoding' ][ 'n_columns' ];

      foreach ( array( 'imputer_statistics', 'scaler_mean', 'scaler_scale', 'coef' ) as $key )
      {
         if ( count( $this->spec[ $key ] ) !== $n )
         {
            throw new RuntimeException( "runtime_model: $key has " . count( $this->spec[ $key ] )
                                        . " entries for $n columns: $path" );
         }
      }

      $expected = $this->spec[ 'encoding' ][ 'column_order' ];
      $built    = $this->column_order();

      if ( count( $built ) !== $n )
      {
         throw new RuntimeException( 'runtime_model: the names and vocabulary describe '
                                     . count( $built ) . " columns, not $n: $path" );
      }

      ## Positional, because a coefficient is only meaningful against its column.
      foreach ( $built as $i => $label )
      {
         if ( ! isset( $expected[ $i ] ) || $expected[ $i ] !== $label )
         {
            $was = isset( $expected[ $i ] ) ? $expected[ $i ] : '(none)';
            throw new RuntimeException( "runtime_model: column $i is '$label' here but '$was'"
                                        . " in the artifact; the exporter and this evaluator disagree" );
         }
      }

      foreach ( array( 'columns', 'median', 'mean', 'sd' ) as $key )
      {
         if ( ! isset( $this->spec[ 'gate' ][ $key ] ) || ! is_array( $this->spec[ 'gate' ][ $key ] ) )
         {
            throw new RuntimeException( "runtime_model: gate has no '$key': $path" );
         }
      }
   }

   /** The design columns this evaluator produces, in order, labelled as the artifact labels them. */
   private function column_order()
   {
      $order = array();

      foreach ( $this->spec[ 'names' ] as $name )
      {
         if ( isset( $this->categorical[ $name ] ) )
         {
            foreach ( $this->spec[ 'encoding' ][ 'vocabulary' ][ $name ] as $level )
            {
               $order[] = $name . '==' . self::level_label( $level );
            }
         }
         else
         {
            $order[] = "log1p($name)";
         }

         $order[] = "isnan($name)";
      }

      return $order;
    }

   /**
    * A vocabulary level as the exporter writes it: numeric levels carry a
    * decimal point ('10.0'), which is why this is not a plain cast to string.
    */
   private static function level_label( $level )
   {
      if ( is_float( $level ) || is_int( $level ) )
      {
         $float = (float) $level;

         return $float == floor( $float ) && abs( $float ) < 1e15
                ? sprintf( '%.1f', $float )
                : (string) $float;
      }

      return (string) $level;
   }

   /**
    * Predicted runtime in seconds, as a positive float.
    *
    * A name absent from $features, or present as null, is the missing case: the
    * imputed value plus a set missing flag, which is what the model was fitted
    * with. A caller that could not extract a required operand must report that
    * rather than passing null, since the two are different claims.
    */
   public function predict( array $features )
   {
      $mean  = $this->spec[ 'scaler_mean' ];
      $scale = $this->spec[ 'scaler_scale' ];
      $coef  = $this->spec[ 'coef' ];
      $imp   = $this->spec[ 'imputer_statistics' ];

      $z = (float) $this->spec[ 'intercept' ];
      $j = 0;

      foreach ( $this->spec[ 'names' ] as $name )
      {
         $value   = array_key_exists( $name, $features ) ? $features[ $name ] : null;
         $missing = ( $value === null || $value === '' );

         if ( isset( $this->categorical[ $name ] ) )
         {
            foreach ( $this->spec[ 'encoding' ][ 'vocabulary' ][ $name ] as $level )
            {
               ## Loose comparison on purpose: a level of 10.0 must match '10'.
               $x = ( ! $missing && $value == $level ) ? 1.0 : 0.0;
               $z += self::contribution( $x, $mean[ $j ], $scale[ $j ], $coef[ $j ] );
               $j++;
            }
         }
         else
         {
            $x = $missing ? (float) $imp[ $j ] : log1p( max( (float) $value, 0.0 ) );
            $z += self::contribution( $x, $mean[ $j ], $scale[ $j ], $coef[ $j ] );
            $j++;
         }

         $z += self::contribution( $missing ? 1.0 : 0.0, $mean[ $j ], $scale[ $j ], $coef[ $j ] );
         $j++;
      }

      return pow( 10.0, min( max( $z, self::LOG_MIN ), self::LOG_MAX ) );
   }

   /** One scaled column's contribution. A zero scale is a constant column. */
   private static function contribution( $x, $mean, $scale, $coef )
   {
      $scale = (float) $scale;

      if ( $scale == 0.0 )
      {
         return 0.0;
      }

      return ( $x - (float) $mean ) / $scale * (float) $coef;
   }

   /**
    * Does the submission sit inside the fitted population, by the artifact's
    * own gate? Recorded alongside the recommendation; it does not change it.
    *
    * A gate column the caller did not supply uses the training median, which
    * is the gate's own rule for an unobserved operand, and a gate column that
    * is not a model input is normal: the gate describes the population, not the
    * design matrix.
    */
   public function gate_accepts( array $features )
   {
      $gate = $this->spec[ 'gate' ];

      foreach ( $gate[ 'columns' ] as $i => $name )
      {
         $sd = (float) $gate[ 'sd' ][ $i ];

         if ( $sd <= 0.0 )
         {
            continue;
         }

         $value = ( array_key_exists( $name, $features ) && $features[ $name ] !== null )
                  ? (float) $features[ $name ]
                  : (float) $gate[ 'median' ][ $i ];

         if ( abs( ( $value - (float) $gate[ 'mean' ][ $i ] ) / $sd ) > self::GATE_SIGMA )
         {
            return false;
         }
      }

      return true;
   }

   /**
    * The candidate allowance in seconds: the multiplied prediction, capped by
    * the formula reference and rounded up to whole minutes, with a floor.
    *
    * Ungated by design. The gate flag is recorded separately so the two can be
    * compared offline on identical predictions.
    */
   public function allowance_seconds( $prediction_seconds, $formula_reference_seconds )
   {
      if ( $formula_reference_seconds === null )
      {
         return null;
      }

      $candidate = $this->multiplier() * (float) $prediction_seconds;
      $capped    = min( $candidate, (float) $formula_reference_seconds );

      return (int) max( 60.0, ceil( $capped / 60.0 ) * 60.0 );
   }

   public function multiplier()
   {
      return (float) $this->spec[ 'k' ];
   }

   public function family()
   {
      return $this->spec[ 'family' ];
   }

   /** The artifact's content hash, recorded with every recommendation. */
   public function fingerprint()
   {
      return $this->fingerprint;
   }

   /** The input names this model reads, for an adapter to fill. */
   public function input_names()
   {
      return $this->spec[ 'names' ];
   }
}
