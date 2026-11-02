<?php
/* Dispatch ledger tables: their names, the checks, and the loader for their
 * definitions in us3_sql. Instance tables hold requests, attempts and events;
 * gfac tables hold controller claims, rotation state, turn events and decisions.
 * All InnoDB, all times UTC from the database server. PHP 7.4 compatible. */

## The dispatch tables are defined in us3_sql: us3_dispatch.sql (each instance) and
## gfac/gfac_dispatch.sql (gfac). These name them for checks.
function dispatch_instance_tables()
{
   return array( 'dispatch_request', 'dispatch_attempt', 'dispatch_event' );
}

function dispatch_gfac_tables()
{
   return array( 'dispatch_controller_claim', 'dispatch_rotation', 'dispatch_turn_event', 'dispatch_decision',
                 'dispatch_alert', 'dispatch_alert_request', 'dispatch_heartbeat' );
}

## Which database server a connection reached. Two connections with the same
## identity can share one transaction across gfac and instance databases.
function dispatch_server_identity( $link )
{
   try {
      $res = mysqli_query( $link, 'SELECT @@hostname, @@port, @@datadir' );
   }
   catch ( mysqli_sql_exception $e ) {
      return '';
   }
   $row = $res ? mysqli_fetch_row( $res ) : null;
   return $row ? implode( ':', $row ) : '';
}

## Runs a us3_sql dispatch file's CREATE TABLE IF NOT EXISTS statements against $db, which
## must be the only kind it holds; returns a list of errors (empty on success).
function dispatch_schema_ensure( $link, $db, $sql_file )
{
   $sql = is_readable( $sql_file ) ? file_get_contents( $sql_file ) : false;
   if ( $sql === false ) {
      return array( "$sql_file cannot be read" );
   }
   if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $db ) ) {
      return array( "'$db' is not a database name" );
   }
   $errors = array();
   $body = preg_replace( '/^\s*--.*$/m', '', $sql );
   ## A statement ends at a semicolon closing its line; one inside a COMMENT does not end it.
   foreach ( array_filter( array_map( 'trim', preg_split( '/;\s*$/m', $body ) ) ) as $stmt ) {
      if ( ! preg_match( '/^CREATE TABLE IF NOT EXISTS\s+`?([A-Za-z0-9_]+)`?\s*\(/', $stmt, $m ) ) {
         $errors[] = "$sql_file: not a CREATE TABLE IF NOT EXISTS statement: " . substr( $stmt, 0, 60 );
         continue;
      }
      $qualified = preg_replace( '/^CREATE TABLE IF NOT EXISTS\s+`?[A-Za-z0-9_]+`?/', "CREATE TABLE IF NOT EXISTS `$db`.`{$m[1]}`", $stmt );
      if ( ! @mysqli_query( $link, $qualified ) ) {
         $errors[] = "{$m[1]}: " . mysqli_error( $link );
      }
   }
   return $errors;
}

## Tables missing or not InnoDB in $db; empty when the schema is ready.
function dispatch_schema_problems( $link, $db, array $tables )
{
   $problems = array();
   foreach ( $tables as $table ) {
      $stmt = mysqli_prepare( $link,
         'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?' );
      mysqli_stmt_bind_param( $stmt, 'ss', $db, $table );
      mysqli_stmt_execute( $stmt );
      mysqli_stmt_bind_result( $stmt, $engine );
      $found = mysqli_stmt_fetch( $stmt );
      mysqli_stmt_close( $stmt );
      if ( ! $found ) {
         $problems[] = "$db.$table is missing";
      }
      elseif ( strcasecmp( (string) $engine, 'InnoDB' ) !== 0 ) {
         $problems[] = "$db.$table uses $engine, not InnoDB";
      }
   }
   return $problems;
}
