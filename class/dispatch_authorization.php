<?php
/* Cluster authorization shared by the web tier and the dispatch worker. The
 * worker has no PHP session, so it passes the person's current user level and
 * cluster list read fresh from the people table. PHP 7.2 compatible. */

## The 'localhost' cluster flag, parsed so a configured string 'false' is false.
function dispatch_flag_is_true( $value )
{
   return is_string( $value ) ? filter_var( $value, FILTER_VALIDATE_BOOLEAN ) : (bool) $value;
}

## people.clusterAuthorizations ("a:b:c") as a list.
function dispatch_person_clusters( $value )
{
   return array_values( array_filter( array_map( 'trim', explode( ':', (string) $value ) ), 'strlen' ) );
}

/**
 * Whether a person may submit to one real cluster:
 * array( 'authorized' => bool, 'reason' => string ).
 * $configured lists the instance's configured cluster short names; a
 * co-located ('localhost') cluster needs no per-person entry but still needs
 * analyst level and instance configuration. A pool's own authorization never
 * extends to its members: each member is checked here by name.
 */
function dispatch_cluster_authorization( $shortname, $userlevel, array $configured, array $cluster_details,
                                         array $person_clusters )
{
   if ( (int) $userlevel < 2 ) {
      return array( 'authorized' => false, 'reason' => 'user level below analyst' );
   }
   if ( ! in_array( $shortname, $configured, true ) ) {
      return array( 'authorized' => false, 'reason' => "cluster '$shortname' is not configured for this instance" );
   }
   if ( isset( $cluster_details[ $shortname ][ 'localhost' ] )
        && dispatch_flag_is_true( $cluster_details[ $shortname ][ 'localhost' ] ) ) {
      return array( 'authorized' => true, 'reason' => 'co-located cluster' );
   }
   if ( in_array( $shortname, $person_clusters, true ) ) {
      return array( 'authorized' => true, 'reason' => 'in the person\'s cluster authorizations' );
   }
   return array( 'authorized' => false, 'reason' => "cluster '$shortname' is not authorized for this account" );
}

/**
 * Current user level and cluster list for one person, read fresh, or null
 * when the person no longer exists. $db is the instance database name.
 */
function dispatch_load_person_authorization( $link, $db, $person_id )
{
   if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $db ) ) {
      return null;
   }
   $stmt = mysqli_prepare( $link, "SELECT userlevel, clusterAuthorizations FROM `$db`.people WHERE personID = ?" );
   if ( ! $stmt ) {
      return null;
   }
   $id = (int) $person_id;
   mysqli_stmt_bind_param( $stmt, 'i', $id );
   mysqli_stmt_execute( $stmt );
   mysqli_stmt_bind_result( $stmt, $level, $clusters );
   $row = mysqli_stmt_fetch( $stmt ) ? array( 'userlevel' => (int) $level,
                                             'clusters' => dispatch_person_clusters( $clusters ) ) : null;
   mysqli_stmt_close( $stmt );
   return $row;
}

## Authorization of every member of a pool for one person: member => result.
function dispatch_member_authorizations( array $members, $userlevel, array $configured, array $cluster_details,
                                         array $person_clusters )
{
   $out = array();
   foreach ( $members as $m ) {
      $out[ $m ] = dispatch_cluster_authorization( $m, $userlevel, $configured, $cluster_details, $person_clusters );
   }
   return $out;
}
