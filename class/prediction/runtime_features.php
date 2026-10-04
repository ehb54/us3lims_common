<?php
/*
 * runtime_features.php
 *
 * Builds a model's input vector from a submission, at the point where the
 * request and the destination are both fixed.
 *
 * DRIVEN BY THE ARTIFACT, NOT BY A LIST HERE. The model declares its inputs,
 * which of them describe the dataset rather than the job, and how often the
 * extraction could not determine each one. This reads all three, so one adapter
 * serves every family and adding a family needs an artifact rather than a code
 * change. An earlier version held the 2DSA inputs as three literal lists, and
 * four of its twelve "always present" names were wrong against the training
 * data. That is the argument against writing them by hand.
 *
 * WHERE EACH INPUT COMES FROM
 *
 *   cluster           the destination name, through the frozen code mapping
 *   ds0.*             the first dataset's properties, supplied by the caller
 *   <name>_fixedtype  an attribute of a request element, kept by
 *   <name>_xtype      parse_jobParameters() alongside the element's value
 *   <name>_ytype
 *   anything else     the request's job parameter of that name
 *
 * The request XML's element names are the model's feature names, so parameters
 * are read straight through without a renaming table: a rename table is a
 * second place for the contract to live and the first place for it to drift.
 *
 * STATUS, AND WHY IT IS NOT A BOOLEAN. An operand can be legitimately absent,
 * which the fitted imputer handles. It can also be unextractable, which is a
 * different claim: a populated operand passed off as missing is a silently
 * wrong prediction. So an input the extraction always had and the request does
 * not carry is an input_error, an input that was sometimes missing in training
 * is null, and a destination with no code is unsupported.
 */

class runtime_features
{
   /** Dataset-scoped inputs carry this prefix in the model's names. */
   const DATASET_PREFIX = 'ds0.';

   /**
    * Build the vector.
    *
    * @param runtime_model $model        the loaded artifact
    * @param array         $parameters   jobsubmit's $job['jobParameters']
    * @param array         $dataset      first dataset's properties, keyed without the prefix
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

      $features = array();
      $missing  = array();

      foreach ( $model->input_names() as $name )
      {
         if ( $name === 'cluster' )
         {
            $features[ $name ] = (float) $cluster_code;
            continue;
         }

         $source = $model->input_is_dataset_scoped( $name )
                   ? self::dataset_value( $dataset, $name )
                   : self::parameter_value( $parameters, $name );

         if ( $source === null || $source === '' )
         {
            if ( $model->input_required( $name ) )
            {
               ## The extraction always had this one, so a request without it
               ## means this read the wrong place. Imputing it would hide that.
               return self::failure( 'input_error',
                  "'$name' is absent from the request, and the model was fitted"
                  . ' with it present on every job' );
            }

            $features[ $name ] = null;
            $missing[]         = $name;
            continue;
         }

         $value = self::numeric( $source );

         if ( $value === null )
         {
            return self::failure( 'input_error',
               "'$name' is not numeric: " . self::describe( $source ) );
         }

         $features[ $name ] = $value;
      }

      return array(
         'status'   => 'ok',
         'features' => $features,
         'missing'  => $missing,
         'reason'   => null,
      );
   }

   /** A dataset property, by the model's name less the prefix. */
   private static function dataset_value( array $dataset, $name )
   {
      $key = strpos( $name, self::DATASET_PREFIX ) === 0
             ? substr( $name, strlen( self::DATASET_PREFIX ) ) : $name;

      return array_key_exists( $key, $dataset ) ? $dataset[ $key ] : null;
   }

   /** A job parameter, by the request element's own name. */
   private static function parameter_value( array $parameters, $name )
   {
      return array_key_exists( $name, $parameters ) ? $parameters[ $name ] : null;
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
