<?php
/*
 * runtime_dataset_facts.php
 *
 * The six dataset properties the runtime model reads, assembled at submission
 * time from the request the submission already holds plus two database reads.
 *
 * FOUR OF THE SIX ARE FREE. The request XML carries the speed step list and
 * the simulation point count, so step count, rotor speed and duration need no
 * query at all.
 *
 * TWO ARE DERIVED AND APPEAR NOWHERE IN THE REQUEST:
 *
 *   edited_scans         the AUC file's scan count minus the edit's excludes
 *   edited_radial_points floor( ( data_range.right - left ) / radius_delta )
 *
 * The data range and the excludes are in the edited-data XML, and radius_delta
 * and the scan count are in the AUC file's fixed-size header. Both are columns
 * in this instance's database, so this reads two rows: the edited-data row by
 * the filename the request names, and 512 bytes of the raw-data row's header.
 * Reading the header rather than the blob keeps a multi-megabyte AUC file off
 * the wire during a submission.
 *
 * PARITY IS THE POINT. These values must be the ones the model was fitted on,
 * which means matching uslims_job_metadata.php exactly, including two rules
 * that are easy to get wrong:
 *
 *   - rotor speed and duration are the MAXIMUM across the steps, and a profile
 *     with any unreadable step is unknown rather than the max of the readable
 *     ones;
 *   - the edited-data row is the most recently updated one for that filename.
 *
 * A value this cannot determine is reported as null, which is the same claim
 * the historical extraction makes, and the adapter passes it to the fitted
 * imputer. A value it could not read when it should have been readable is an
 * issue, which the caller records rather than treating as missing data.
 */

class runtime_dataset_facts
{
   ## Fixed offsets into the AUC header. Laid out by auc2obj.php: magic 4,
   ## version 2, type 2, cell 1, channel 1, guid 16, description 240, then
   ## minimum and maximum radius, radius delta, two data ranges, scan count.
   const AUC_RADIUS_DELTA_OFFSET = 274;
   const AUC_SCAN_COUNT_OFFSET   = 294;
   const AUC_HEADER_BYTES        = 512;

   /**
    * Everything the model needs about the first dataset.
    *
    * @param mysqli  $link           the instance database
    * @param string  $db             the instance database name
    * @param string  $edit_filename  the dataset's edit filename, from the request
    * @param array   $speedsteps     the request's speedstep list for this dataset
    * @param mixed   $simpoints      the request's simulation point count
    *
    * @return array the six properties, plus issues: operands that should have
    *               been readable and were not
    */
   public static function build( $link, $db, $edit_filename, array $speedsteps, $simpoints )
   {
      $facts = self::from_speedsteps( $speedsteps );
      $facts[ 'simpoints' ] = ( $simpoints === null || $simpoints === '' ) ? null : $simpoints;

      $counts = self::edited_counts( $link, $db, $edit_filename );

      $facts[ 'edited_scans' ]         = $counts[ 'edited_scans' ];
      $facts[ 'edited_radial_points' ] = $counts[ 'edited_radial_points' ];
      $facts[ 'issues' ]               = $counts[ 'issues' ];

      return $facts;
   }

   /**
    * Step count, rotor speed and duration, from the request alone.
    *
    * The maximum across steps, and null when any step is unreadable: a
    * partially known profile is not the maximum of the part that was read.
    */
   public static function from_speedsteps( array $speedsteps )
   {
      $speeds    = array();
      $durations = array();

      foreach ( $speedsteps as $step )
      {
         $speeds[]    = self::numeric( isset( $step[ 'rotorspeed' ] ) ? $step[ 'rotorspeed' ] : null );
         $durations[] = self::duration_seconds( $step );
      }

      return array(
         'speedstep_count'  => count( $speedsteps ) > 0 ? count( $speedsteps ) : null,
         'rotorspeed'       => self::max_or_null( $speeds ),
         'duration_seconds' => self::max_or_null( $durations ),
      );
   }

   /** One step's duration in whole seconds, from its hours and minutes. */
   private static function duration_seconds( array $step )
   {
      $hours   = self::numeric( isset( $step[ 'duration_hrs' ] ) ? $step[ 'duration_hrs' ] : null );
      $minutes = self::numeric( isset( $step[ 'duration_mins' ] ) ? $step[ 'duration_mins' ] : null );

      if ( $hours === null || $minutes === null )
      {
         return null;
      }

      return (int) round( ( $hours * 60.0 + $minutes ) * 60.0 );
   }

   /**
    * The two derived counts, from the edited-data XML and the AUC header.
    *
    * Everything that stops a count being computed is an issue rather than a
    * silent null, because each of these is normally present: a submission got
    * this far, so its edit and its raw data exist.
    */
   public static function edited_counts( $link, $db, $edit_filename )
   {
      $absent = array( 'edited_scans' => null, 'edited_radial_points' => null, 'issues' => array() );

      ## Checked before anything else: an unusable database name is a
      ## deployment fault, true whether or not a connection exists, and it is
      ## interpolated into the statements below.
      self::assert_database_name( $db );

      if ( $edit_filename === null || $edit_filename === '' )
      {
         $absent[ 'issues' ][] = 'the request names no edit filename';
         return $absent;
      }

      ## An absent or dead connection is a read failure to report, not an
      ## exception to propagate: mysqli_prepare() throws on a non-connection,
      ## and mysqli_connect() returns false when the database is unreachable.
      if ( ! ( $link instanceof mysqli ) )
      {
         $absent[ 'issues' ][] = 'no usable database connection for the edited data';
         return $absent;
      }

      $edited = self::edited_row( $link, $db, $edit_filename );

      if ( $edited === null )
      {
         $absent[ 'issues' ][] = 'no edited-data row for the request\'s edit filename';
         return $absent;
      }

      $range = self::parse_edit_xml( $edited[ 'data' ] );

      if ( $range === null )
      {
         $absent[ 'issues' ][] = 'the edited-data XML could not be read';
         return $absent;
      }

      $header = self::auc_header( $link, $db, $edited[ 'rawDataID' ] );

      if ( $header === null )
      {
         $absent[ 'issues' ][] = 'no readable raw-data header for the edited data';
         return $absent;
      }

      $scans = $header[ 'scan_count' ] - $range[ 'excludes' ];
      $points = ( $range[ 'left' ] !== null && $range[ 'right' ] !== null && $header[ 'radius_delta' ] > 0 )
                ? (int) floor( ( $range[ 'right' ] - $range[ 'left' ] ) / $header[ 'radius_delta' ] )
                : null;

      return array(
         ## Zero or negative is not a count, which is the extraction's own rule.
         'edited_scans'         => $scans > 0 ? (int) $scans : null,
         'edited_radial_points' => ( $points !== null && $points > 0 ) ? $points : null,
         'issues'               => array(),
      );
   }

   /**
    * The edited-data row for a filename: the most recently updated one, which
    * is the row the historical extraction reads.
    */
   private static function edited_row( $link, $db, $edit_filename )
   {
      $table = self::qualified( $db, 'editedData' );
      $sql   = "SELECT rawDataID, data FROM $table WHERE filename = ? ORDER BY lastUpdated DESC LIMIT 1";

      $stmt = ( $link instanceof mysqli ) ? @mysqli_prepare( $link, $sql ) : false;

      if ( $stmt === false )
      {
         return null;
      }

      mysqli_stmt_bind_param( $stmt, 's', $edit_filename );

      if ( ! mysqli_stmt_execute( $stmt ) )
      {
         mysqli_stmt_close( $stmt );
         return null;
      }

      $raw_id = null;
      $data   = null;
      mysqli_stmt_bind_result( $stmt, $raw_id, $data );
      $found = mysqli_stmt_fetch( $stmt );
      mysqli_stmt_close( $stmt );

      return $found ? array( 'rawDataID' => $raw_id, 'data' => $data ) : null;
   }

   /**
    * The AUC header, read as a bounded substring so a large file never crosses
    * the wire during a submission.
    */
   private static function auc_header( $link, $db, $raw_data_id )
   {
      $table = self::qualified( $db, 'rawData' );
      $bytes = self::AUC_HEADER_BYTES;
      $sql   = "SELECT SUBSTRING( data, 1, $bytes ) FROM $table WHERE rawDataID = ?";

      $stmt = ( $link instanceof mysqli ) ? @mysqli_prepare( $link, $sql ) : false;

      if ( $stmt === false )
      {
         return null;
      }

      mysqli_stmt_bind_param( $stmt, 'i', $raw_data_id );

      if ( ! mysqli_stmt_execute( $stmt ) )
      {
         mysqli_stmt_close( $stmt );
         return null;
      }

      $header = null;
      mysqli_stmt_bind_result( $stmt, $header );
      $found = mysqli_stmt_fetch( $stmt );
      mysqli_stmt_close( $stmt );

      return $found ? self::parse_auc_header( $header ) : null;
   }

   /**
    * radius_delta and the scan count from the header's fixed offsets, or null
    * when the bytes are too short or the values are not usable.
    */
   public static function parse_auc_header( $header )
   {
      if ( ! is_string( $header ) || strlen( $header ) < self::AUC_SCAN_COUNT_OFFSET + 2 )
      {
         return null;
      }

      $delta = unpack( 'f', substr( $header, self::AUC_RADIUS_DELTA_OFFSET, 4 ) );
      $scans = unpack( 's', substr( $header, self::AUC_SCAN_COUNT_OFFSET, 2 ) );

      if ( $delta === false || $scans === false )
      {
         return null;
      }

      $delta = (float) reset( $delta );
      $scans = (int) reset( $scans );

      if ( ! is_finite( $delta ) || $delta <= 0.0 || $scans <= 0 )
      {
         return null;
      }

      return array( 'radius_delta' => $delta, 'scan_count' => $scans );
   }

   /** The data range and the number of excluded scans from the edited-data XML. */
   public static function parse_edit_xml( $xml_text )
   {
      if ( ! is_string( $xml_text ) || $xml_text === '' )
      {
         return null;
      }

      ## An edit is site data, so a parse failure is reported, never thrown.
      $previous = libxml_use_internal_errors( true );
      $xml      = simplexml_load_string( $xml_text );
      libxml_clear_errors();
      libxml_use_internal_errors( $previous );

      if ( $xml === false || ! isset( $xml->run ) )
      {
         return null;
      }

      $left     = null;
      $right    = null;
      $excludes = 0;

      if ( isset( $xml->run->parameters->data_range ) )
      {
         $range = $xml->run->parameters->data_range;
         $left  = self::numeric( isset( $range[ 'left' ] ) ? (string) $range[ 'left' ] : null );
         $right = self::numeric( isset( $range[ 'right' ] ) ? (string) $range[ 'right' ] : null );
      }

      if ( isset( $xml->run->excludes->exclude ) )
      {
         foreach ( $xml->run->excludes->exclude as $ignored )
         {
            $excludes++;
         }
      }

      return array( 'left' => $left, 'right' => $right, 'excludes' => $excludes );
   }

   /** The maximum, or null when any entry is unknown. */
   private static function max_or_null( array $values )
   {
      if ( ! $values )
      {
         return null;
      }

      foreach ( $values as $value )
      {
         if ( $value === null )
         {
            return null;
         }
      }

      return max( $values );
   }

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

   /**
    * A table in the instance database. The name comes from deployment
    * configuration rather than from a request, and it is interpolated, so it is
    * restricted to what a database name can be.
    */
   private static function qualified( $db, $table )
   {
      self::assert_database_name( $db );

      return "`$db`.$table";
   }

   private static function assert_database_name( $db )
   {
      if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $db ) )
      {
         throw new InvalidArgumentException( "runtime_dataset_facts: unusable database name '$db'" );
      }
   }
}
