<?php
/**
 * Coordinated writes from LIMS PHP (ultrascan-tickets#1353): the PHP side of the rules the C++
 * library in UltraScan implements, on the same us3_sql manifest (us3_row_version_tables.tsv).
 *
 * One unit of work is one short transaction. It:
 *  - sets @US3_ACTING_ID, @US3_CLIENT and @US3_COORDINATED, and clears @US3_ID, when it begins,
 *    and clears all four when it ends, on every path;
 *  - locks rows in the manifest's global order and refuses a lock out of that order;
 *  - compares aggregate versions ("agg-v1", SHA-256 over every row's key and rowVersion) with
 *    locking reads;
 *  - records its operation in operationRecord as the first write after its locks, so a retry with
 *    the same operation ID resolves to the first attempt's receipt;
 *  - calls only the procedures its operation declares, reading every result set.
 *
 *    $result = us3_unit_of_work::run( $link, array(
 *                 'operationID' => ..., 'kind' => 'hpc.submit', 'request' => $canonical_bytes,
 *                 'actingPersonID' => 0, 'client' => 'lims/submit_slurm 4.3.0',
 *                 'procedures' => array() ),
 *              function ( us3_unit_of_work $uow ) { ...; return array( 'receipt' => ... ); } );
 *
 * $result['status'] is ok, replayed (an earlier attempt committed; its receipt is returned),
 * conflict, busy (deadlocks after three tries), mismatch (the operation ID was used for another
 * request), refused (a rule of this class) or error. $result['session_cleared'] is false when
 * the session variables could not be cleared: close that connection rather than reuse it.
 */
class us3_unit_of_work_refusal extends RuntimeException {}
class us3_unit_of_work_conflict extends RuntimeException {}
class us3_unit_of_work_replay extends RuntimeException {}

class us3_unit_of_work
{
   const RETRIES = 3;   // reruns after the first attempt

   private $link;
   private $op;
   private $manifest;
   private $last_lock = null;   // [ rank, id ]
   private $recorded  = false;

   private static $manifest_cache = null;
   private static $schema_ok = array();

   ## ------------------------------------------------------------ entry points

   /** Runs $body as one unit of work, retrying a deadlock with the same request. */
   public static function run( $link, array $op, callable $body )
   {
      foreach ( array( 'operationID', 'kind', 'request', 'client' ) as $k )
         if ( ! isset( $op[ $k ] ) || $op[ $k ] === '' )
            return array( 'status' => 'refused', 'error' => "operation needs $k", 'session_cleared' => true );
      $op += array( 'actingPersonID' => 0, 'procedures' => array() );

      ## Looked up before any work, so a retry gets its receipt even when what it changed has moved on
      $prior = self::lookup( $link, $op[ 'operationID' ] );
      if ( $prior !== null )
         return self::prior_result( $prior, $op ) + array( 'session_cleared' => true );

      for ( $try = 1; ; $try++ )
      {
         $uow    = new us3_unit_of_work( $link, $op );
         $result = $uow->attempt( $body, $try <= self::RETRIES );
         $result[ 'session_cleared' ] = $uow->end();
         if ( $result[ 'status' ] !== 'retry' )
            return $result;
      }
   }

   private function attempt( callable $body, $may_retry )
   {
      try
      {
         $this->begin();
         $out = $body( $this );
         $out = is_array( $out ) ? $out : array();
         $this->record();
         $receipt = json_encode( isset( $out[ 'receipt' ] ) ? $out[ 'receipt' ] : new stdClass() );
         $this->exec( "UPDATE operationRecord SET receipt = ? WHERE operationID = ?", 'ss',
                      array( $receipt, $this->op[ 'operationID' ] ) );
         if ( ! mysqli_commit( $this->link ) )
            throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
         return array( 'status' => 'ok', 'receipt' => json_decode( $receipt, true ) ) + $out;
      }
      catch ( us3_unit_of_work_replay $e )
      {
         ## Another attempt with this ID committed while this one waited on its record
         $this->rollback();
         return self::prior_result( self::lookup( $this->link, $this->op[ 'operationID' ] ), $this->op );
      }
      catch ( us3_unit_of_work_conflict $e )
      {
         $this->rollback();
         return array( 'status' => 'conflict', 'error' => $e->getMessage() );
      }
      catch ( us3_unit_of_work_refusal $e )
      {
         $this->rollback();
         return array( 'status' => 'refused', 'error' => $e->getMessage() );
      }
      catch ( Throwable $e )
      {
         $errno = self::errno_of( $e, $this->link );
         $this->rollback();
         $deadlock = in_array( $errno, array( 1213, 1205 ), true );
         if ( $deadlock && $may_retry )
            return array( 'status' => 'retry' );
         return array( 'status' => $deadlock ? 'busy' : 'error', 'error' => $e->getMessage() );
      }
   }

   /** The committed record of an operation ID, or null. */
   public static function lookup( $link, $operationID )
   {
      ## Whatever mysqli_report() mode the caller runs in: a database without the table has no record
      try
      {
         $stmt = @mysqli_prepare( $link, "SELECT requestSHA256, receipt FROM operationRecord WHERE operationID = ?" );
         if ( ! $stmt || ! mysqli_stmt_bind_param( $stmt, 's', $operationID ) || ! mysqli_stmt_execute( $stmt ) )
            return null;
         $row = mysqli_fetch_assoc( mysqli_stmt_get_result( $stmt ) );
         mysqli_stmt_close( $stmt );
         return $row ?: null;
      }
      catch ( Throwable $e )
      {
         return null;
      }
   }

   /** An operation ID derived from what makes an unattended job's write unique. */
   public static function derived_operation_id( $kind, array $parts )
   {
      $h = hash( 'sha256', $kind . "\n" . implode( "\n", $parts ) );
      return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-5' . substr( $h, 13, 3 ) . '-'
           . dechex( 8 | ( hexdec( $h[ 16 ] ) & 3 ) ) . substr( $h, 17, 3 ) . '-' . substr( $h, 20, 12 );
   }

   /** The aggregate's version on $link, read without locks, as a client records it when it reads. */
   public static function version( $link, $aggregate, $rootID )
   {
      return self::read_version( $link, $aggregate, $rootID, false );
   }

   private static function read_version( $link, $aggregate, $rootID, $locking )
   {
      $ctx = hash_init( 'sha256' );
      hash_update( $ctx, "agg-v1\n$aggregate\n" );
      $tables = self::aggregate_tables( $aggregate );
      if ( ! $tables )
         throw new us3_unit_of_work_refusal( "no aggregate $aggregate in the manifest" );
      foreach ( $tables as $t )
      {
         $sql = "SELECT {$t['key']}, rowVersion FROM {$t['table']} WHERE "
              . str_replace( '?', (string) (int) $rootID, $t[ 'predicate' ] )
              . " ORDER BY {$t['key']}" . ( $locking ? ' FOR UPDATE' : '' );
         $res = mysqli_query( $link, $sql );
         if ( ! $res )
            throw new RuntimeException( mysqli_error( $link ) );
         $rows = mysqli_fetch_all( $res, MYSQLI_NUM );
         hash_update( $ctx, "{$t['table']}\n" . count( $rows ) . "\n" );
         foreach ( $rows as $row )
            hash_update( $ctx, implode( ',', array_map( array( 'us3_unit_of_work', 'field' ), $row ) ) . "\n" );
      }
      return hash_final( $ctx );
   }

   /** The bytes of the agg-v1 encoding for given rows, for the shared test vectors. */
   public static function encode( $aggregate, array $tables )
   {
      $b = "agg-v1\n$aggregate\n";
      foreach ( $tables as $t )
      {
         $b .= "{$t[0]}\n" . count( $t[1] ) . "\n";
         foreach ( $t[1] as $row )
            $b .= implode( ',', array_map( array( 'us3_unit_of_work', 'field' ), $row ) ) . "\n";
      }
      return $b;
   }

   public static function field( $v )
   {
      if ( $v === null )
         return '0:';
      $s = (string) $v;
      if ( ! preg_match( '/^(0|[1-9][0-9]*)$/', $s ) )
         throw new InvalidArgumentException( "not an unsigned integer: $s" );
      return strlen( $s ) . ':' . $s;
   }

   /** Where to find us3_sql: $us3_sql_dir, then US3_SQL_DIR, then the host and container checkouts. */
   public static function manifest_path()
   {
      global $us3_sql_dir;
      $dirs = array_filter( array( isset( $us3_sql_dir ) ? $us3_sql_dir : '', (string) getenv( 'US3_SQL_DIR' ),
                                   '/home/us3/lims/database/sql', '/home/us3/lims/sql' ) );
      foreach ( $dirs as $d )
         if ( is_readable( rtrim( $d, '/' ) . '/us3_row_version_tables.tsv' ) )
            return rtrim( $d, '/' ) . '/us3_row_version_tables.tsv';
      return null;
   }

   ## ------------------------------------------------------------ inside a unit of work

   /** Locks an aggregate's root row, in the global order. Returns its row, or null when absent. */
   public function lock_root( $aggregate, $id )
   {
      $root = $this->root_table( $aggregate );
      $this->check_order( $aggregate, $id );
      $res = mysqli_query( $this->link, "SELECT * FROM {$root['table']} WHERE "
                                      . str_replace( '?', (string) (int) $id, $root[ 'predicate' ] ) . " FOR UPDATE" );
      if ( ! $res )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
      $row = mysqli_fetch_assoc( $res );
      return $row ?: null;
   }

   /** Locks an aggregate's rows in every table, in manifest order, and returns its version. */
   public function lock_aggregate( $aggregate, $id )
   {
      $this->check_order( $aggregate, $id );
      return self::read_version( $this->link, $aggregate, $id, true );
   }

   /** Share-locks a referenced root, at its aggregate's place in the order. */
   public function share_lock( $aggregate, $id )
   {
      $root = $this->root_table( $aggregate );
      $this->check_order( $aggregate, $id );
      if ( ! mysqli_query( $this->link, "SELECT 1 FROM {$root['table']} WHERE "
                                      . str_replace( '?', (string) (int) $id, $root[ 'predicate' ] ) . " LOCK IN SHARE MODE" ) )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
   }

   /** Locks the aggregate and fails the unit of work with a conflict when it changed since $seen. */
   public function expect_version( $aggregate, $id, $seen )
   {
      if ( $this->lock_aggregate( $aggregate, $id ) !== $seen )
         throw new us3_unit_of_work_conflict( "$aggregate $id changed since it was read" );
   }

   /** Records the operation: the first write after the locks, made before the unit's first write. */
   public function record()
   {
      if ( $this->recorded )
         return;
      $this->recorded = true;
      $stmt = mysqli_prepare( $this->link, "INSERT INTO operationRecord ( operationID, kind, roots, actingPersonID, authPersonID, client, requestSHA256 )"
                                         . " VALUES ( ?, ?, ?, NULLIF( ?, 0 ), @US3_ID, ?, ? )" );
      $roots = json_encode( $this->roots() );
      $sha   = hash( 'sha256', $this->op[ 'request' ] );
      $acting = (int) $this->op[ 'actingPersonID' ];
      if ( ! $stmt || ! mysqli_stmt_bind_param( $stmt, 'sssiss', $this->op[ 'operationID' ], $this->op[ 'kind' ], $roots,
                                                $acting, $this->op[ 'client' ], $sha ) )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
      try
      {
         $ok = mysqli_stmt_execute( $stmt );
      }
      catch ( mysqli_sql_exception $e )
      {
         if ( $e->getCode() === 1062 )
            throw new us3_unit_of_work_replay( $e->getMessage() );
         throw $e;
      }
      if ( ! $ok )
      {
         $errno = mysqli_stmt_errno( $stmt );
         $error = mysqli_stmt_error( $stmt );
         mysqli_stmt_close( $stmt );
         if ( $errno === 1062 )
            throw new us3_unit_of_work_replay( $error );
         throw new RuntimeException( $error, $errno );
      }
      mysqli_stmt_close( $stmt );
   }

   /** A write with bound values, after the operation record. Returns the affected row count. */
   public function exec( $sql, $types = '', array $values = array() )
   {
      if ( ! $this->recorded && $this->link_in_transaction )
         $this->record();
      $stmt = mysqli_prepare( $this->link, $sql );
      if ( ! $stmt )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
      if ( $types !== '' )
         mysqli_stmt_bind_param( $stmt, $types, ...$values );
      if ( ! mysqli_stmt_execute( $stmt ) )
      {
         $errno = mysqli_stmt_errno( $stmt );
         $error = mysqli_stmt_error( $stmt );
         mysqli_stmt_close( $stmt );
         throw new RuntimeException( $error, $errno );
      }
      $n = mysqli_stmt_affected_rows( $stmt );
      mysqli_stmt_close( $stmt );
      return $n;
   }

   /** A read inside the unit of work. Rows as associative arrays. */
   public function rows( $sql, $types = '', array $values = array() )
   {
      $stmt = mysqli_prepare( $this->link, $sql );
      if ( ! $stmt )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
      if ( $types !== '' )
         mysqli_stmt_bind_param( $stmt, $types, ...$values );
      if ( ! mysqli_stmt_execute( $stmt ) )
         throw new RuntimeException( mysqli_stmt_error( $stmt ), mysqli_stmt_errno( $stmt ) );
      $rows = mysqli_fetch_all( mysqli_stmt_get_result( $stmt ), MYSQLI_ASSOC );
      mysqli_stmt_close( $stmt );
      return $rows;
   }

   /**
    * Calls a procedure the operation declares. Returns every result set; the status is the one
    * set with a status column, and a status other than 0 fails the unit of work.
    */
   public function call( $procedure, array $args )
   {
      if ( ! in_array( $procedure, $this->op[ 'procedures' ], true ) )
         throw new us3_unit_of_work_refusal( "$procedure is not declared by {$this->op['kind']}" );
      $this->record();
      $sql = "CALL $procedure(" . implode( ', ', array_map( function ( $v ) {
                return $v === null ? 'NULL' : ( is_int( $v ) ? (string) $v : "'" . mysqli_real_escape_string( $this->link, (string) $v ) . "'" );
             }, $args ) ) . ")";
      if ( ! mysqli_multi_query( $this->link, $sql ) )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
      $sets     = array();
      $status   = null;
      $statuses = 0;
      do
      {
         $res = mysqli_store_result( $this->link );
         if ( $res instanceof mysqli_result )
         {
            $rows = mysqli_fetch_all( $res, MYSQLI_ASSOC );
            $sets[] = $rows;
            if ( $rows && array_key_exists( 'status', $rows[ 0 ] ) )
            {
               $statuses++;
               $status = (int) $rows[ 0 ][ 'status' ];
            }
         }
      } while ( mysqli_more_results( $this->link ) && mysqli_next_result( $this->link ) );
      ## Every result set is read first, so the connection is in step for the rollback
      if ( mysqli_errno( $this->link ) )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
      if ( $statuses > 1 )
         throw new us3_unit_of_work_refusal( "$procedure returned more than one status" );
      if ( $status === null )
         throw new us3_unit_of_work_refusal( "$procedure returned no status" );
      if ( $status !== 0 )
         throw new us3_unit_of_work_refusal( "$procedure returned status $status" );
      return $sets;
   }

   ## ------------------------------------------------------------ internals

   private function __construct( $link, array $op )
   {
      $this->link = $link;
      $this->op   = $op;
      $this->manifest = self::manifest();
   }

   private function begin()
   {
      if ( $this->manifest === null )
         throw new us3_unit_of_work_refusal( 'us3_row_version_tables.tsv not found; coordinated writes need us3_sql 4.3.0' );
      self::require_schema( $this->link );
      $acting = (int) $this->op[ 'actingPersonID' ];
      $client = substr( $this->op[ 'client' ], 0, 64 );
      $stmt = mysqli_prepare( $this->link, "SET @US3_ACTING_ID = ?, @US3_CLIENT = ?, @US3_COORDINATED = 1, @US3_ID = NULL" );
      if ( ! $stmt || ! mysqli_stmt_bind_param( $stmt, 'is', $acting, $client ) || ! mysqli_stmt_execute( $stmt ) )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
      mysqli_stmt_close( $stmt );
      if ( ! mysqli_query( $this->link, 'START TRANSACTION' ) )
         throw new RuntimeException( mysqli_error( $this->link ), mysqli_errno( $this->link ) );
      $this->link_in_transaction = true;
   }

   private $link_in_transaction = false;

   ## Never throws: it runs inside the error handling, whatever mysqli_report() mode is set.
   private function rollback()
   {
      try
      {
         @mysqli_rollback( $this->link );
      }
      catch ( Throwable $e )
      {
      }
   }

   ## The server error number behind $e: mysqli's own exception, or one of this class's, which
   ## carry it because closing a statement resets the connection's error.
   private static function errno_of( Throwable $e, $link )
   {
      if ( $e instanceof mysqli_sql_exception || ( $e instanceof RuntimeException && $e->getCode() > 0 ) )
         return (int) $e->getCode();
      try
      {
         return (int) mysqli_errno( $link );
      }
      catch ( Throwable $e2 )
      {
         return 0;
      }
   }

   /** Clears the session variables. False when that failed: the caller must not reuse the connection. */
   public function end()
   {
      return self::clear_session( $this->link );
   }

   public static function clear_session( $link )
   {
      try
      {
         return (bool) @mysqli_query( $link, "SET @US3_ACTING_ID = NULL, @US3_CLIENT = NULL, @US3_COORDINATED = NULL, @US3_ID = NULL" );
      }
      catch ( Throwable $e )
      {
         return false;
      }
   }

   private static function prior_result( $prior, array $op )
   {
      if ( $prior === null )
         return array( 'status' => 'error', 'error' => 'the operation record disappeared' );
      if ( $prior[ 'requestSHA256' ] !== hash( 'sha256', $op[ 'request' ] ) )
         return array( 'status' => 'mismatch', 'error' => "operation {$op['operationID']} was used for another request" );
      return array( 'status' => 'replayed', 'receipt' => json_decode( (string) $prior[ 'receipt' ], true ) );
   }

   private $locked = array();

   private function roots()
   {
      return $this->locked;
   }

   private function check_order( $aggregate, $id )
   {
      $rank = $this->rank( $aggregate );
      if ( $this->recorded )
         throw new us3_unit_of_work_refusal( "lock on $aggregate $id after the operation was recorded" );
      if ( $this->last_lock !== null
           && ( $rank < $this->last_lock[ 0 ] || ( $rank === $this->last_lock[ 0 ] && (int) $id < $this->last_lock[ 1 ] ) ) )
         throw new us3_unit_of_work_refusal( "lock on $aggregate $id out of the global order" );
      $this->last_lock = array( $rank, (int) $id );
      $this->locked[]  = array( 'aggregate' => $aggregate, 'id' => (int) $id );
   }

   ## An aggregate's place in the lock order: the manifest position of its first row.
   private function rank( $aggregate )
   {
      foreach ( $this->manifest as $i => $r )
         if ( $r[ 'aggregate' ] === $aggregate )
            return $i;
      throw new us3_unit_of_work_refusal( "no aggregate $aggregate in the manifest" );
   }

   private function root_table( $aggregate )
   {
      foreach ( self::aggregate_tables( $aggregate, true ) as $t )
         if ( $t[ 'role' ] !== 'child' )
            return $t;
      throw new us3_unit_of_work_refusal( "no aggregate $aggregate in the manifest" );
   }

   private static function aggregate_tables( $aggregate, $with_claims = false )
   {
      $out = array();
      foreach ( (array) self::manifest() as $r )
         if ( $r[ 'aggregate' ] === $aggregate && ( $with_claims || strpos( $r[ 'options' ], 'claim' ) === false ) )
            $out[] = $r;
      return $out;
   }

   private static function manifest()
   {
      if ( self::$manifest_cache !== null )
         return self::$manifest_cache ?: null;
      $path = self::manifest_path();
      $rows = array();
      $header = null;
      foreach ( $path ? file( $path, FILE_IGNORE_NEW_LINES ) : array() as $line )
      {
         if ( $line === '' || $line[ 0 ] === '#' )
            continue;
         $cols = explode( "\t", $line );
         if ( $header === null )
            $header = $cols;
         elseif ( count( $cols ) === count( $header ) )
            $rows[] = array_combine( $header, $cols );
      }
      self::$manifest_cache = $rows;
      return $rows ?: null;
   }

   ## The schema check at connect: the sequence, the operation record table and both triggers
   ## on every manifest table. Reads still work without them; coordinated writes refuse.
   private static function require_schema( $link )
   {
      ## Keyed by the connection's object and server thread, so a closed link is not kept alive
      ## and a new link that reuses its object ID is checked again
      $key = spl_object_id( $link ) . ':' . (int) mysqli_thread_id( $link );
      if ( isset( self::$schema_ok[ $key ] ) )
         return;
      $tables = array_unique( array_map( function ( $r ) { return $r[ 'table' ]; }, self::manifest() ) );
      $res = mysqli_query( $link, "SELECT"
           . " ( SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
           . "   AND TABLE_NAME IN ( 'us3_row_version_seq', 'operationRecord' ) ),"
           . " ( SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()"
           . "   AND TRIGGER_NAME LIKE '%\\_version' )" );
      $row = $res ? mysqli_fetch_row( $res ) : array( 0, 0 );
      if ( (int) $row[ 0 ] < 2 || (int) $row[ 1 ] < 2 * count( $tables ) )
         throw new us3_unit_of_work_refusal( 'this database lacks row versions; upgrade it (uslims_upgrade.php) before coordinated writes' );
      self::$schema_ok[ $key ] = true;
   }
}
