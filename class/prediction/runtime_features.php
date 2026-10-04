<?php
/*
 * runtime_features.php
 *
 * Builds the model's input vector from a submission, at the point where the
 * request and the destination are both fixed.
 *
 * Sixteen of the inputs are job parameters, and the request XML's element names
 * are the model's feature names, so they are read straight through without a
 * renaming table: jobsubmit's parse_jobParameters() keys them by element name.
 * That is deliberate. A rename table is a second place for the contract to live
 * and the first place for it to drift.
 *
 * Six are properties of the first dataset, which the caller supplies. They are
 * not read here, because two of them need a database and this has to stay
 * testable without one.
 *
 * One is the destination, as the numeric code the model was fitted with.
 *
 * STATUS, AND WHY IT IS NOT A BOOLEAN. An operand can be legitimately absent,
 * which the fitted imputer handles and which is recorded as a set missing flag.
 * It can also be unextractable, which is a different claim and must not be
 * passed off as the first: a populated operand turned into missing data is a
 * silently wrong prediction. So a value that is present but not a number is an
 * input_error, an absent optional operand is null, and an unmapped destination
 * is unsupported.
 */

class runtime_features
{
   /**
    * Job parameters the standard 2DSA submission pages always post. Absent is
    * an extraction failure rather than a scientific missing value, because the
    * form cannot produce a request without them.
    */
   private static $required_parameters = array(
      'ff0_grid_points', 'ff0_max', 'ff0_min', 'max_iterations', 'meniscus_points',
      'meniscus_range', 'rinoise_option', 's_grid_points', 's_max', 's_min',
      'tinoise_option', 'uniform_grid',
   );

   /**
    * Job parameters that a valid request may omit. Passed as null so the fitted
    * imputer handles them exactly as it did in training.
    */
   private static $optional_parameters = array(
      'ff0_resolution', 's_resolution', 'mc_iterations', 'fit_mb_select',
   );

   /** Dataset properties, supplied by the caller, keyed without the ds0 prefix. */
   private static $dataset_inputs = array(
      'duration_seconds', 'edited_radial_points', 'edited_scans',
      'rotorspeed', 'simpoints', 'speedstep_count',
   );

   /**
    * Build the vector.
    *
    * The destination is passed by name, the way the request records it.
    * runtime_cluster_map turns it into a code and the model confirms it was
    * fitted with that code, so a map and an artifact that disagree are refused
    * rather than scored.
    *
    * @param runtime_model $model        the loaded artifact
    * @param array         $parameters   jobsubmit's $job['jobParameters']
    * @param array         $dataset      first dataset's properties, keys as in $dataset_inputs
    * @param string        $cluster_name the destination, as the request names it
    *
    * @return array status, features, missing, reason
    */
   public static function build( runtime_model $model, array $parameters, array $dataset, $cluster_name )
   {
      $cluster_code = runtime_cluster_map::code( $cluster_name );

      if ( $cluster_code === null )
      {
         return self::failure( 'unsupported',
            "destination '" . self::describe( $cluster_name ) . "' has no cluster code" );
      }

      ## Two checks, because they can disagree: the map says what the name means,
      ## the artifact says whether this model was fitted with it.
      if ( ! $model->knows_cluster_code( $cluster_code ) )
      {
         return self::failure( 'unsupported',
            "destination '" . self::describe( $cluster_name ) . "' is code $cluster_code,"
            . ' which this model has no indicator for' );
      }

      $features = array( 'cluster' => (float) $cluster_code );
      $missing  = array();

      foreach ( self::$required_parameters as $name )
      {
         if ( ! array_key_exists( $name, $parameters ) )
         {
            return self::failure( 'input_error', "job parameter '$name' is absent from the request" );
         }

         $value = self::numeric( $parameters[ $name ] );

         if ( $value === null )
         {
            return self::failure( 'input_error',
               "job parameter '$name' is not numeric: " . self::describe( $parameters[ $name ] ) );
         }

         $features[ $name ] = $value;
      }

      foreach ( self::$optional_parameters as $name )
      {
         if ( ! array_key_exists( $name, $parameters )
              || $parameters[ $name ] === null || $parameters[ $name ] === '' )
         {
            $features[ $name ] = null;
            $missing[]         = $name;
            continue;
         }

         $value = self::numeric( $parameters[ $name ] );

         if ( $value === null )
         {
            return self::failure( 'input_error',
               "job parameter '$name' is not numeric: " . self::describe( $parameters[ $name ] ) );
         }

         $features[ $name ] = $value;
      }

      foreach ( self::$dataset_inputs as $name )
      {
         $key = "ds0.$name";

         if ( ! array_key_exists( $name, $dataset )
              || $dataset[ $name ] === null || $dataset[ $name ] === '' )
         {
            ## The provider reports an operand it could not determine as null,
            ## which is the same claim the historical extraction makes.
            $features[ $key ] = null;
            $missing[]        = $key;
            continue;
         }

         $value = self::numeric( $dataset[ $name ] );

         if ( $value === null )
         {
            return self::failure( 'input_error',
               "dataset property '$name' is not numeric: " . self::describe( $dataset[ $name ] ) );
         }

         $features[ $key ] = $value;
      }

      return array(
         'status'   => 'ok',
         'features' => $features,
         'missing'  => $missing,
         'reason'   => null,
      );
   }

   /**
    * Every input the model declares is present as a key, so a model input this
    * adapter does not know about is caught here rather than being imputed
    * silently by the evaluator.
    */
   public static function covers( runtime_model $model, array $features )
   {
      $absent = array();

      foreach ( $model->input_names() as $name )
      {
         if ( ! array_key_exists( $name, $features ) )
         {
            $absent[] = $name;
         }
      }

      return $absent;
   }

   /** Numeric or null. Accepts the strings the XML parser hands back. */
   private static function numeric( $value )
   {
      if ( is_int( $value ) || is_float( $value ) )
      {
         return is_finite( (float) $value ) ? (float) $value : null;
      }

      if ( is_string( $value ) && is_numeric( trim( $value ) ) )
      {
         return (float) trim( $value );
      }

      return null;
   }

   /** A short, non-leaking description of a rejected value, for the record. */
   private static function describe( $value )
   {
      if ( is_array( $value ) )
      {
         return 'array(' . count( $value ) . ')';
      }

      if ( is_bool( $value ) || $value === null )
      {
         return var_export( $value, true );
      }

      $text = (string) $value;

      return strlen( $text ) > 32 ? substr( $text, 0, 32 ) . '...' : $text;
   }

   private static function failure( $status, $reason )
   {
      return array(
         'status'   => $status,
         'features' => null,
         'missing'  => array(),
         'reason'   => $reason,
      );
   }
}
