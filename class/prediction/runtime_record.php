<?php
/*
 * runtime_record.php
 *
 * Writes one prediction record to gfac.runtime_prediction.
 *
 * SPLIT ON PURPOSE. row() composes the record and is pure, so what gets stored
 * is testable without a database. insert() is the thin part that binds and
 * executes. The division matters because the risk here is not the query, it is
 * storing the wrong thing: a field read from the wrong place, or a payload that
 * should never have been kept.
 *
 * WHAT IS NEVER STORED. No request XML, no researcher identity, no result
 * content. The feature vector is kept because the evaluation needs the inputs
 * the prediction was made from, and it holds numbers, category codes and
 * explicit nulls only. A rejected value reaches the record as a short reason,
 * truncated, never as the value itself.
 *
 * A FAILED WRITE IS NOT A FAILED SUBMISSION. insert() returns false and never
 * throws, so a caller can record the failure and carry on. Losing a record
 * costs coverage, which the readout counts; losing a submission costs a job.
 */

class runtime_record
{
   ## The record contract. Bump when a column's meaning changes, so a readout
   ## can tell two shapes apart instead of guessing from which fields are null.
   const SCHEMA = '1';

   ## The adapter's own version, recorded beside the model's hash: the inputs
   ## can change meaning without the model changing at all.
   const ADAPTER_VERSION = 'runtime_features/1';

   const TABLE = 'runtime_prediction';

   ## A reason is a log line, not a payload.
   const REASON_LIMIT = 255;

   /**
    * Compose the record.
    *
    * @param array $context us3_db, request_id, gfac_id, family, destination,
    *                       prediction_cluster, prediction_cluster_source,
    *                       formula_reference_seconds, formula_version,
    *                       emitted_directive, requested_ranks, artifact_sha256,
    *                       evaluate_us, decided_at
    * @param array $built   what runtime_features::build() returned
    * @param array $outcome prediction_seconds, multiplier, allowance_seconds,
    *                       gate_accepted; empty when no recommendation was made
    */
   public static function row( array $context, array $built, array $outcome = array() )
   {
      $ok = isset( $built[ 'status' ] ) && $built[ 'status' ] === 'ok';

      return array(
         'record_schema'   => self::SCHEMA,
         'artifact_sha256' => self::text( isset( $context[ 'artifact_sha256' ] ) ? $context[ 'artifact_sha256' ] : '', 64 ),
         'adapter_version' => self::ADAPTER_VERSION,

         'us3_db'      => self::text( isset( $context[ 'us3_db' ] ) ? $context[ 'us3_db' ] : '', 64 ),
         'request_id'  => self::integer( isset( $context[ 'request_id' ] ) ? $context[ 'request_id' ] : null ),
         ## Null unless the caller passes one: observe_runtime_advisory() does not
         ## look the Slurm job id up, so a later pass fills this in by (us3_db, request_id).
         'gfac_id'     => isset( $context[ 'gfac_id' ] ) && $context[ 'gfac_id' ] !== ''
                          ? self::text( $context[ 'gfac_id' ], 80 ) : null,
         'decided_at'  => isset( $context[ 'decided_at' ] )
                          ? $context[ 'decided_at' ] : gmdate( 'Y-m-d H:i:s' ),

         'family'      => self::text( isset( $context[ 'family' ] ) ? $context[ 'family' ] : '', 16 ),
         'destination' => self::text( isset( $context[ 'destination' ] ) ? $context[ 'destination' ] : '', 80 ),

         ## The identity actually scored against the cluster map, and why: a
         ## local/renamed destination routinely differs from the identity the
         ## model recognizes (see submit_slurm::resolvePredictionClusterIdentity()).
         ## Null source means a caller on the old contract set destination only.
         'prediction_cluster'        => isset( $context[ 'prediction_cluster' ] )
                                         ? self::text( $context[ 'prediction_cluster' ], 80 ) : null,
         'prediction_cluster_source' => isset( $context[ 'prediction_cluster_source' ] )
                                         ? self::text( $context[ 'prediction_cluster_source' ], 16 ) : null,

         ## Only when there is a vector: a failed extraction has no inputs to keep.
         'features'    => $ok && isset( $built[ 'features' ] ) ? self::features_json( $built[ 'features' ] ) : null,

         'prediction_seconds' => $ok ? self::number( self::pick( $outcome, 'prediction_seconds' ) ) : null,
         'multiplier'         => $ok ? self::number( self::pick( $outcome, 'multiplier' ) ) : null,
         'allowance_seconds'  => $ok ? self::integer( self::pick( $outcome, 'allowance_seconds' ) ) : null,

         'formula_reference_seconds' => self::integer( self::pick( $context, 'formula_reference_seconds' ) ),
         'formula_version'           => self::text( self::pick( $context, 'formula_version' ), 32 ),
         'emitted_directive'         => self::text( self::pick( $context, 'emitted_directive' ), 32 ),
         'requested_ranks'           => self::integer( self::pick( $context, 'requested_ranks' ) ),

         ## Recorded, never applied to the allowance.
         'gate_accepted' => $ok && array_key_exists( 'gate_accepted', $outcome )
                            ? ( $outcome[ 'gate_accepted' ] ? 1 : 0 ) : null,

         'status'      => self::text( isset( $built[ 'status' ] ) ? $built[ 'status' ] : 'input_error', 24 ),
         'reason'      => isset( $built[ 'reason' ] ) && $built[ 'reason' ] !== ''
                          ? self::text( $built[ 'reason' ], self::REASON_LIMIT ) : null,
         'evaluate_us' => self::integer( self::pick( $context, 'evaluate_us' ) ),
      );
   }

   /**
    * The feature vector as JSON: numbers and explicit nulls, nothing else.
    *
    * Anything that is not a number is dropped rather than stringified, because
    * a feature vector is where a free-text value would end up stored by
    * accident, and this table must not hold one.
    */
   public static function features_json( array $features )
   {
      $clean = array();

      foreach ( $features as $name => $value )
      {
         if ( $value === null )
         {
            $clean[ $name ] = null;
         }
         elseif ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) )
         {
            $clean[ $name ] = $value + 0;
         }
         elseif ( is_string( $value ) && is_numeric( trim( $value ) ) )
         {
            $clean[ $name ] = (float) trim( $value );
         }
      }

      ## Preserve the zero fraction: a stored 10 and a stored 10.0 compare
      ## differently against the Python side during the acceptance check, and
      ## the distinction costs nothing to keep.
      $json = json_encode( $clean, JSON_PRESERVE_ZERO_FRACTION );

      return $json === false ? null : $json;
   }

   /**
    * Store the record. True when the row landed.
    *
    * Never throws: a recording failure must leave the submission untouched.
    * The caller is expected to report the return value through the application
    * error mechanism, so a run with no records does not look like a run with no
    * submissions.
    */
   public static function insert( $link, array $row )
   {
      if ( ! $link )
      {
         return false;
      }

      $columns = array_keys( $row );
      $sql     = 'INSERT INTO ' . self::TABLE . ' (`' . implode( '`, `', $columns ) . '`) VALUES ('
                 . implode( ', ', array_fill( 0, count( $columns ), '?' ) ) . ')';

      $types  = '';
      $values = array();

      foreach ( $row as $value )
      {
         if ( is_int( $value ) )
         {
            $types .= 'i';
         }
         elseif ( is_float( $value ) )
         {
            $types .= 'd';
         }
         else
         {
            $types .= 's';
         }

         $values[] = $value;
      }

      $stmt = @mysqli_prepare( $link, $sql );

      if ( $stmt === false )
      {
         return false;
      }

      $bind = array( $stmt, $types );

      foreach ( $values as $i => $ignored )
      {
         $bind[] = &$values[ $i ];
      }

      if ( ! @call_user_func_array( 'mysqli_stmt_bind_param', $bind ) )
      {
         mysqli_stmt_close( $stmt );
         return false;
      }

      $stored = @mysqli_stmt_execute( $stmt );
      mysqli_stmt_close( $stmt );

      return (bool) $stored;
   }

   private static function pick( array $source, $key )
   {
      return array_key_exists( $key, $source ) ? $source[ $key ] : null;
   }

   private static function text( $value, $limit )
   {
      if ( $value === null )
      {
         return null;
      }

      $text = is_scalar( $value ) ? (string) $value : '';

      return strlen( $text ) > $limit ? substr( $text, 0, $limit ) : $text;
   }

   private static function integer( $value )
   {
      if ( $value === null || $value === '' || ! is_numeric( $value ) )
      {
         return null;
      }

      return (int) $value;
   }

   private static function number( $value )
   {
      if ( $value === null || $value === '' || ! is_numeric( $value ) )
      {
         return null;
      }

      $number = (float) $value;

      return is_finite( $number ) ? $number : null;
   }
}
