<?php
/* Dispatch operations: operator alert items, the wait notification, the
 * worker heartbeat, operational metrics and operator actions. Alert items live
 * in gfac; the open item is the system of record and email is an optional
 * delivery on top. Needs dispatch_ledger.php. PHP 7.2 compatible. */

include_once __DIR__ . '/dispatch_ledger.php';

## A worker heartbeat older than this is reported as lost.
const DISPATCH_HEARTBEAT_STALE_SECONDS = 300;
## An accepted job still untracked after this long is an operator alert.
const DISPATCH_UNTRACKED_ALERT_SECONDS = 900;

/**
 * Open the item for a condition, or note that it is still occurring. One open
 * item per $key; $requests (instance => IDs) are listed under it. Returns the
 * alert ID.
 */
function dispatch_alert_raise( $link, $gdb, $key, $kind, $urgency, $destination, $instance, $detail, array $requests = array() )
{
   if ( dispatch_affected( $link, "INSERT INTO `$gdb`.dispatch_alert
         (dedupKey, kind, urgency, destination, instanceDb, detail, firstAt, lastAt)
      VALUES (?,?,?,?,?,?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE lastAt = UTC_TIMESTAMP(), detail = VALUES(detail)",
      'ssssss', array( $key, $kind, $urgency, $destination, $instance, $detail ) ) < 0 ) {
      error_log( "dispatch alert '$kind' could not be recorded: " . dispatch_last_error() . "; detail: $detail" );
      return 0;
   }
   $rows = dispatch_rows( $link, "SELECT alertID FROM `$gdb`.dispatch_alert WHERE dedupKey = ? AND openFlag = 1",
                          's', array( $key ) );
   if ( ! $rows ) {
      return 0;
   }
   $alert = (int) $rows[ 0 ][ 'alertID' ];
   foreach ( $requests as $db => $ids ) {
      foreach ( $ids as $id ) {
         dispatch_affected( $link, "INSERT IGNORE INTO `$gdb`.dispatch_alert_request VALUES (?,?,?)",
                            'isi', array( $alert, $db, (int) $id ) );
      }
   }
   return $alert;
}

## Close the open item for a condition that no longer holds.
function dispatch_alert_resolve( $link, $gdb, $key, $resolution )
{
   return dispatch_affected( $link, "UPDATE `$gdb`.dispatch_alert SET resolvedAt = UTC_TIMESTAMP(), resolution = ?,
         openFlag = NULL WHERE dedupKey = ? AND openFlag = 1", 'ss', array( $resolution, $key ) ) === 1;
}

## Close an item by ID, as an operator does once the fault is handled.
function dispatch_alert_close( $link, $gdb, $alert_id, $resolution )
{
   return dispatch_affected( $link, "UPDATE `$gdb`.dispatch_alert SET resolvedAt = UTC_TIMESTAMP(), resolution = ?,
         openFlag = NULL WHERE alertID = ? AND openFlag = 1", 'si', array( $resolution, (int) $alert_id ) ) === 1;
}

## Whether the worker can use the tables it decides with: gfac's coordination tables and the request
## tables of $busy. WHERE 1 = 0 checks each table, column and privilege and touches no row.
function dispatch_tables_usable( $link, $gdb, array $busy )
{
   $gfac = array( 'dispatch_controller_claim' => 'controller, claimOwner, claimGeneration, claimExpiresAt',
                  'dispatch_rotation' => 'scopeKey, stateJson, updatedAt' );
   foreach ( $gfac as $table => $columns ) {
      $key = strtok( $columns, ',' );
      if ( dispatch_affected( $link, "INSERT INTO `$gdb`.`$table` ($columns) SELECT $columns FROM `$gdb`.`$table` WHERE 1 = 0" ) < 0
           || dispatch_affected( $link, "UPDATE `$gdb`.`$table` SET $key = $key WHERE 1 = 0" ) < 0 ) {
         return false;
      }
   }
   foreach ( $busy as $db ) {
      if ( dispatch_rows_checked( $link, "SELECT 1 FROM `$db`.dispatch_attempt LIMIT 1" ) === null
           || dispatch_rows_checked( $link, "SELECT 1 FROM `$db`.dispatch_event LIMIT 1" ) === null
           || dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = state WHERE 1 = 0" ) < 0 ) {
         return false;
      }
   }
   return true;
}

## Close $db's open items of the kinds in $seen whose condition this scan did not find.
function dispatch_alert_scan_close( $link, $gdb, $db, array $open, array $seen )
{
   foreach ( $open as $item ) {
      if ( $item[ 'instanceDb' ] === $db && isset( $seen[ $item[ 'kind' ] ] ) && ! isset( $seen[ $item[ 'kind' ] ][ $item[ 'dedupKey' ] ] ) ) {
         dispatch_alert_resolve( $link, $gdb, $item[ 'dedupKey' ], 'condition cleared' );
      }
   }
}

## $db's open items as one value, so a scan can tell whether any opened or closed since the last one.
function dispatch_alert_open_mark( array $items, $db )
{
   $keys = array();
   foreach ( $items as $item ) {
      if ( $item[ 'instanceDb' ] === $db ) {
         $keys[] = $item[ 'dedupKey' ];
      }
   }
   sort( $keys );
   return hash( 'sha256', implode( "\n", $keys ) );
}

## $db's items open once a scan that found $seen has raised and closed its own: those of other kinds stay.
function dispatch_alert_scan_left( array $open, $db, array $seen )
{
   $left = array();
   foreach ( $open as $item ) {
      if ( $item[ 'instanceDb' ] === $db && ! isset( $seen[ $item[ 'kind' ] ] ) ) {
         $left[] = $item;
      }
   }
   foreach ( $seen as $keys ) {
      foreach ( array_keys( $keys ) as $key ) {
         $left[] = array( 'dedupKey' => $key, 'instanceDb' => $db );
      }
   }
   return $left;
}

/**
 * Raise and resolve alert items from the ledger, deduplicated by condition:
 *   unresolved_submission (urgent)  acceptance still unknown after the automatic checks
 *   untracked_acceptance (urgent)   an accepted job whose tracking has not been restored
 *   destination_unreachable         requests waiting because a destination could not be
 *                                   reached; controller-level, closed only by later
 *                                   evidence that the destination works, never by expiry
 *   configuration                   requests blocked by pool configuration, fit or permission
 *   local_failure                   requests waiting because staging fails on this server
 * Routine capacity waiting raises nothing.
 *
 * $busy lists the instances with an unfinished request (null: all of $dbs). The others can hold no
 * such condition, so their requests are not read and their open items close.
 *
 * $sigs gives a busy instance's request signature (dispatch_worker_agenda()'s sig). An instance whose
 * signature and open items are as its last scan left them, recorded in $scanned, is not read again.
 * Returns the instances whose requests held for the database were released, due now.
 */
function dispatch_alert_scan( $link, $gdb, array $dbs, array $busy = null, array $sigs = array(), array &$scanned = null )
{
   $busy = $busy === null ? $dbs : array_values( array_intersect( $dbs, $busy ) );
   $open = dispatch_rows( $link, "SELECT dedupKey, kind, instanceDb FROM `$gdb`.dispatch_alert WHERE openFlag = 1" );
   $long_concat = false;
   foreach ( $dbs as $db ) {
      $seen = array( 'unresolved_submission' => array(), 'untracked_acceptance' => array(), 'configuration' => array(),
                     'local_failure' => array() );

      if ( ! in_array( $db, $busy, true ) ) {
         dispatch_alert_scan_close( $link, $gdb, $db, $open, $seen );
         continue;
      }
      $mark = isset( $sigs[ $db ] ) ? $sigs[ $db ] . '#' . dispatch_alert_open_mark( $open, $db ) : null;
      if ( $mark !== null && isset( $scanned[ $db ] ) && $scanned[ $db ] === $mark ) {
         continue;
      }
      unset( $scanned[ $db ] );
      $failed = false;
      $rows = function ( $sql, $types = '', array $args = array() ) use ( $link, &$failed ) {
         $r = dispatch_rows_checked( $link, $sql, $types, $args );
         $failed = $failed || $r === null;
         return (array) $r;
      };
      $raise = function ( ...$args ) use ( $link, $gdb, &$failed ) {
         $id = dispatch_alert_raise( $link, $gdb, ...$args );
         $failed = $failed || ! $id;
         return $id;
      };
      if ( ! $long_concat ) {
         ## The request lists below come from GROUP_CONCAT, cut at 1024 bytes by default.
         @mysqli_query( $link, 'SET SESSION group_concat_max_len = 1048576' );
         $long_concat = true;
      }

      $held = $rows( "SELECT r.dispatchRequestID, r.selectedCluster, r.reason,
            (SELECT COUNT(*) FROM `$db`.dispatch_event e WHERE e.dispatchRequestID = r.dispatchRequestID
              AND e.fromState = 'SUBMISSION_UNKNOWN' AND e.toState = 'SUBMISSION_UNKNOWN') AS checks
         FROM `$db`.dispatch_request r WHERE r.state = 'SUBMISSION_UNKNOWN'" );
      foreach ( $held as $r ) {
         if ( (int) $r[ 'checks' ] >= DISPATCH_RECONCILE_ALERT_AFTER ) {
            $key = "unresolved_submission|$db|{$r['dispatchRequestID']}";
            $seen[ 'unresolved_submission' ][ $key ] = true;
            $raise( $key, 'unresolved_submission', 'urgent', $r[ 'selectedCluster' ], $db,
               "acceptance of request {$r['dispatchRequestID']} at {$r['selectedCluster']} is unresolved: {$r['reason']}",
               array( $db => array( $r[ 'dispatchRequestID' ] ) ) );
         }
      }

      ## Timed from the acceptance: each failed tracking repair's release touches updatedAt.
      $untracked = $rows( "SELECT r.dispatchRequestID, r.selectedCluster, r.reason FROM `$db`.dispatch_request r
         WHERE r.state = 'ACCEPTED' AND COALESCE( ( SELECT MAX( e.createdAt ) FROM `$db`.dispatch_event e
               WHERE e.dispatchRequestID = r.dispatchRequestID AND e.toState = 'ACCEPTED' ), r.updatedAt )
               < UTC_TIMESTAMP() - INTERVAL ? SECOND",
         'i', array( DISPATCH_UNTRACKED_ALERT_SECONDS ) );
      foreach ( $untracked as $r ) {
         $key = "untracked_acceptance|$db|{$r['dispatchRequestID']}";
         $seen[ 'untracked_acceptance' ][ $key ] = true;
         $raise( $key, 'untracked_acceptance', 'urgent', $r[ 'selectedCluster' ], $db,
            "request {$r['dispatchRequestID']} was accepted but is not tracked: {$r['reason']}",
            array( $db => array( $r[ 'dispatchRequestID' ] ) ) );
      }

      $blocked = $rows( "SELECT pool, reasonCode, GROUP_CONCAT(dispatchRequestID) ids, MAX(reason) reason
         FROM `$db`.dispatch_request WHERE state = 'BLOCKED' AND reasonCode IN ('configuration','no_fit','no_permission','input')
         GROUP BY pool, reasonCode" );
      foreach ( $blocked as $r ) {
         $key = "configuration|$db|{$r['pool']}|{$r['reasonCode']}";
         $seen[ 'configuration' ][ $key ] = true;
         $raise( $key, 'configuration', 'normal', $r[ 'pool' ], $db,
            "requests for '{$r['pool']}' are blocked ({$r['reasonCode']}): {$r['reason']}",
            array( $db => explode( ',', $r[ 'ids' ] ) ) );
      }

      ## Staging that keeps failing on this server (a full or unwritable disk) waits without contacting
      ## any member, so nothing else would tell the operator.
      $local = $rows( "SELECT GROUP_CONCAT(dispatchRequestID) ids, MAX(reason) reason
         FROM `$db`.dispatch_request WHERE state = 'WAITING' AND reasonCode = 'local_failure' HAVING ids IS NOT NULL" );
      foreach ( $local as $r ) {
         $key = "local_failure|$db";
         $seen[ 'local_failure' ][ $key ] = true;
         $raise( $key, 'local_failure', 'normal', null, $db,
            "requests in $db cannot be prepared for submission on this server: {$r['reason']}",
            array( $db => explode( ',', $r[ 'ids' ] ) ) );
      }

      dispatch_alert_scan_close( $link, $gdb, $db, $open, $seen );

      $unreachable = $rows( "SELECT COALESCE(selectedCluster, pool) destination,
            GROUP_CONCAT(dispatchRequestID) ids, MAX(reason) reason
         FROM `$db`.dispatch_request WHERE state = 'WAITING' AND reasonCode = 'unreachable'
         GROUP BY COALESCE(selectedCluster, pool)" );
      foreach ( $unreachable as $r ) {
         $raise( "destination_unreachable|{$r['destination']}", 'destination_unreachable',
            'normal', $r[ 'destination' ], null, "{$r['destination']} could not be reached: {$r['reason']}",
            array( $db => explode( ',', $r[ 'ids' ] ) ) );
      }
      if ( $mark !== null && ! $failed ) {
         $scanned[ $db ] = $sigs[ $db ] . '#' . dispatch_alert_open_mark( dispatch_alert_scan_left( $open, $db, $seen ), $db );
      }
   }

   $open_kinds = array_column( $open, 'kind' );

   ## The database item stands while its cause does, whoever is waiting. When the tables check out, held
   ## requests go at once; it closes when none is held any more, so a cause the check cannot see, such as
   ## a full table, keeps one item instead of a new one and a new mail at each scan.
   $released = array();
   if ( in_array( 'dispatch_database', $open_kinds, true ) && dispatch_tables_usable( $link, $gdb, $busy ) ) {
      $held = false;
      foreach ( $busy as $db ) {
         if ( dispatch_rows_checked( $link, "SELECT 1 FROM `$db`.dispatch_request
               WHERE state = 'WAITING' AND reasonCode = 'dispatch_unavailable' LIMIT 1" ) !== array() ) {
            $held = true;
            $released[] = $db;
            dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET retryAt = UTC_TIMESTAMP()
               WHERE state = 'WAITING' AND reasonCode = 'dispatch_unavailable'" );
         }
      }
      if ( ! $held ) {
         dispatch_alert_resolve( $link, $gdb, 'dispatch_database', 'the dispatch tables can be used again' );
      }
   }

   ## An unreachable destination is resolved by a later acceptance there.
   $unreachable_items = ! in_array( 'destination_unreachable', $open_kinds, true ) ? array()
      : dispatch_rows( $link, "SELECT alertID, dedupKey, destination, lastAt FROM `$gdb`.dispatch_alert
         WHERE kind = 'destination_unreachable' AND openFlag = 1" );
   foreach ( $unreachable_items as $a ) {
      foreach ( $dbs as $db ) {
         $worked = dispatch_rows( $link, "SELECT 1 FROM `$db`.dispatch_attempt t JOIN `$db`.dispatch_request r USING (dispatchRequestID)
            WHERE t.phase = 'ACCEPTED' AND t.resolvedAt > ? AND (t.cluster = ? OR r.pool = ?) LIMIT 1",
            'sss', array( $a[ 'lastAt' ], $a[ 'destination' ], $a[ 'destination' ] ) );
         if ( $worked ) {
            dispatch_alert_resolve( $link, $gdb, $a[ 'dedupKey' ], 'a later submission there was accepted' );
            break;
         }
      }
   }
   return $released;
}

/**
 * Email urgent open items once each, when the installation enables it
 * ($global_dispatch_alert_email), or only those of $kind. $send( $subject, $body )
 * returns whether the mail was handed off. Returns the alert IDs mailed.
 */
function dispatch_alert_email_pending( $link, $gdb, $enabled, callable $send, $kind = null )
{
   if ( ! $enabled ) {
      return array();
   }
   $mailed = array();
   foreach ( dispatch_rows( $link, "SELECT alertID, kind, destination, instanceDb, detail, firstAt FROM `$gdb`.dispatch_alert
         WHERE openFlag = 1 AND urgency = 'urgent' AND emailedAt IS NULL AND ( ? IS NULL OR kind = ? )",
         'ss', array( $kind, $kind ) ) as $a ) {
      $subject = "UltraScan dispatch alert: {$a['kind']}" . ( $a[ 'destination' ] ? " at {$a['destination']}" : '' );
      $body = "{$a['detail']}\n\nInstance: " . ( $a[ 'instanceDb' ] ?: 'all' ) . "\nFirst seen (UTC): {$a['firstAt']}\n"
            . "This item stays open on the dispatch dashboard until the condition is resolved.\n";
      if ( $send( $subject, $body )
           && dispatch_affected( $link, "UPDATE `$gdb`.dispatch_alert SET emailedAt = UTC_TIMESTAMP()
                 WHERE alertID = ? AND emailedAt IS NULL", 'i', array( (int) $a[ 'alertID' ] ) ) === 1 ) {
         $mailed[] = (int) $a[ 'alertID' ];
      }
   }
   return $mailed;
}

## The host's site administrator: $global_site_admin_email, else listen-config.php's
## $admin_email, else root@localhost, as gridctl's own notices fall back.
function dispatch_site_admin_address( $global_site_admin_email, $listen_admin_email )
{
   foreach ( array( $global_site_admin_email, $listen_admin_email ) as $address ) {
      if ( trim( (string) $address ) !== '' ) {
         return trim( (string) $address );
      }
   }
   return 'root@localhost';
}

## A $send( $subject, $body ) for dispatch notices, mailed to $to and sent from it.
function dispatch_site_admin_sender( $to, $org_name )
{
   $from = ( (string) $org_name !== '' ? $org_name : 'UltraScan' ) . " Admin<$to>";
   return function ( $subject, $body, $recipient = null ) use ( $to, $from ) {
      return mail( $recipient !== null ? $recipient : $to, $subject, $body,
                   "From: $from\nReply-To: $from\nMIME-Version: 1.0\nContent-Transfer-Encoding: 8bit\n" );
   };
}

## A blocked request waits for an operator: the site admin is asked to act, and the submitter
## (when known) is told it is held, not failed, since a dashboard retry can still run it.
function dispatch_notify_blocked( callable $send, $db, array $req, $reason, $submitter_email, $code = '' )
{
   ## A request whose input is gone or changed cannot run again; it is cancelled and resubmitted.
   $input = $code === 'input';
   $what = "dispatch request {$req['dispatchRequestID']} (HPC request " . (int) $req[ 'HPCAnalysisRequestID' ]
         . ", pool {$req['pool']}) in $db";
   $told = $send( "UltraScan dispatch: request {$req['dispatchRequestID']} in $db is blocked",
          "The $what is blocked and needs an administrator:\n\n$reason\n\n"
          . ( $input ? "Its received input is missing or changed, so it cannot run; cancel it on the queue page.\n"
                     : "Retry it from the dispatch dashboard once the cause is fixed.\n" ) );
   if ( (string) $submitter_email !== '' ) {
      $send( $input ? "US3 analysis could not be run" : "US3 analysis held for an administrator",
             "Your analysis (HPC request " . (int) $req[ 'HPCAnalysisRequestID' ] . ") in $db "
             . ( $input ? "cannot be run:\n\n$reason\n\nPlease resubmit it.\n"
                        : "is held for an administrator:\n\n$reason\n\n"
                          . ( $told ? "The site administrator has been told; it runs once they clear the cause.\n"
                                    : "It runs once an administrator clears the cause.\n" ) ), $submitter_email );
   }
}

## A pool made inactive failed these requests: one notice to the site admin, and one to each submitter.
## $submitters is dispatch_request_submitters()'s result.
function dispatch_notify_pool_disabled( callable $send, $db, $reason, array $submitters )
{
   $lines = array();
   foreach ( $submitters as $id => $who ) {
      $lines[] = "request $id (HPC request {$who[1]}, {$who[0]})";
   }
   $told = $send( "UltraScan dispatch: " . count( $submitters ) . " request(s) failed in $db",
                  "These dispatch requests in $db failed:\n\n$reason\n\n" . implode( "\n", $lines ) . "\n" );
   foreach ( $submitters as $who ) {
      if ( $who[ 0 ] !== '' ) {
         $send( "US3 job submission failed",
                "Your analysis (HPC request {$who[1]}) in $db was not run:\n\n$reason\n\n"
                . ( $told ? "The system administrator has been notified. " : '' ) . "Resubmit it if you still need it.\n", $who[ 0 ] );
      }
   }
}

## An operator closed an uncertain submission: the submitter learns it will not run again from here.
function dispatch_notify_closed( callable $send, $db, array $req, $reason, $submitter_email )
{
   if ( (string) $submitter_email !== '' ) {
      $send( "US3 analysis closed by an administrator",
             "Your analysis (HPC request " . (int) $req[ 'HPCAnalysisRequestID' ] . ") in $db could not be confirmed as"
             . " submitted, and no further attempt will be made:\n\n$reason\n\nA job may still have reached the cluster."
             . " Ask your administrator to check before you resubmit, or the analysis may run twice.\n",
             $submitter_email );
   }
}

## Submitter e-mail addresses of $ids in $db: [ dispatchRequestID => [ email, HPCAnalysisRequestID ] ].
function dispatch_request_submitters( $link, $db, array $ids )
{
   $out = array();
   foreach ( $ids as $id ) {
      foreach ( dispatch_rows( $link, "SELECT p.email, r.HPCAnalysisRequestID FROM `$db`.dispatch_request r
            JOIN `$db`.people p ON p.personID = r.submitterID WHERE r.dispatchRequestID = ?", 'i', array( (int) $id ) ) as $r ) {
         $out[ (int) $id ] = array( (string) $r[ 'email' ], (int) $r[ 'HPCAnalysisRequestID' ] );
      }
   }
   return $out;
}

## Mark requests waiting past their notification time; shown to the submitter and on the dashboard.
function dispatch_mark_waiting_notified( $link, $db )
{
   return max( 0, dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET notifiedAt = UTC_TIMESTAMP()
      WHERE notifiedAt IS NULL AND notifyAt <= UTC_TIMESTAMP()
        AND state IN ('PENDING','ASSESSING','WAITING','STAGING')" ) );
}

## Heartbeat rows of workers gone for a day: each submitctl process beats under its own name.
function dispatch_heartbeat_purge( $link, $gdb )
{
   return dispatch_affected( $link, "DELETE FROM `$gdb`.dispatch_heartbeat WHERE beatAt < UTC_TIMESTAMP() - INTERVAL 1 DAY" );
}

function dispatch_heartbeat_beat( $link, $gdb, $worker )
{
   return dispatch_affected( $link, "INSERT INTO `$gdb`.dispatch_heartbeat (worker, beatAt) VALUES (?, UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE beatAt = UTC_TIMESTAMP()", 's', array( $worker ) ) >= 0;
}

/**
 * The independent heartbeat check: the worker cannot report its own death.
 * Returns array( ok, age, worker ): ok when a worker beat within the limit;
 * age in seconds since the newest beat, or null when none was ever recorded;
 * 'error' when the heartbeat table could not be read.
 */
function dispatch_heartbeat_status( $link, $gdb, $stale_seconds = DISPATCH_HEARTBEAT_STALE_SECONDS )
{
   $rows = dispatch_rows_checked( $link, "SELECT worker, TIMESTAMPDIFF(SECOND, beatAt, UTC_TIMESTAMP()) age
      FROM `$gdb`.dispatch_heartbeat ORDER BY beatAt DESC LIMIT 1" );
   if ( $rows === null ) {
      return array( 'ok' => false, 'age' => null, 'worker' => null, 'error' => dispatch_last_error() );
   }
   if ( ! $rows ) {
      return array( 'ok' => false, 'age' => null, 'worker' => null );
   }
   return array( 'ok' => (int) $rows[ 0 ][ 'age' ] <= $stale_seconds, 'age' => (int) $rows[ 0 ][ 'age' ],
                 'worker' => $rows[ 0 ][ 'worker' ] );
}

/**
 * Operator action: send a waiting or blocked request back to assessment now.
 * A blocked request past its deadline gets a renewed one ($renew_seconds).
 */
function dispatch_operator_retry( $link, $db, $id, $operator, $renew_seconds = 86400 )
{
   $req = dispatch_get( $link, $db, $id );
   if ( ! $req || ! in_array( $req[ 'state' ], array( DISPATCH_WAITING, DISPATCH_BLOCKED ), true ) ) {
      return false;
   }
   $why = "assessment retried by $operator";
   return dispatch_tx( $link, function () use ( $link, $db, $id, $req, $why, $renew_seconds ) {
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'WAITING', reason = ?, reasonCode = NULL,
            retryAt = UTC_TIMESTAMP(),
            deadlineAt = IF(deadlineAt <= UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND, deadlineAt),
            updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND state = ? AND claimGeneration = ?",
         'siisi', array( $why, (int) $renew_seconds, (int) $id, $req[ 'state' ], (int) $req[ 'claimGeneration' ] ) );
      return $n === 1 && ( $req[ 'state' ] === DISPATCH_WAITING
                           || dispatch_append_event( $link, $db, $id, $req[ 'state' ], DISPATCH_WAITING,
                                                     (int) $req[ 'claimGeneration' ], $why ) );
   } );
}

/**
 * Operational metrics for one instance over the last $days: request-to-
 * acceptance time, current waiting by reason and by destination, the share of
 * UNKNOWN assessments and reconciliation outcomes.
 */
function dispatch_metrics( $link, $gdb, $db, $days = 7 )
{
   $since = "UTC_TIMESTAMP() - INTERVAL " . (int) $days . " DAY";
   $accept = dispatch_rows( $link, "SELECT COUNT(*) n, AVG(TIMESTAMPDIFF(SECOND, r.createdAt, e.createdAt)) avg_s,
         MAX(TIMESTAMPDIFF(SECOND, r.createdAt, e.createdAt)) max_s
      FROM `$db`.dispatch_event e JOIN `$db`.dispatch_request r USING (dispatchRequestID)
      WHERE e.toState = 'ACCEPTED' AND e.createdAt >= $since" );
   $states = dispatch_rows( $link, "SELECT state, COALESCE(selectedCluster, pool) destination, COUNT(*) n
      FROM `$db`.dispatch_request WHERE state IN ('PENDING','ASSESSING','WAITING','BLOCKED','STAGING','SUBMITTING',
         'SUBMISSION_UNKNOWN','ACCEPTED') GROUP BY state, COALESCE(selectedCluster, pool) ORDER BY state, destination" );
   $waiting = dispatch_rows( $link, "SELECT COALESCE(reasonCode, 'unspecified') reason, COUNT(*) n,
         AVG(TIMESTAMPDIFF(SECOND, createdAt, UTC_TIMESTAMP())) avg_age_s
      FROM `$db`.dispatch_request WHERE state = 'WAITING' GROUP BY COALESCE(reasonCode, 'unspecified')" );
   $verdicts = array( 'READY' => 0, 'DELAYED' => 0, 'REJECTED' => 0, 'UNKNOWN' => 0 );
   foreach ( dispatch_rows( $link, "SELECT candidatesJson FROM `$gdb`.dispatch_decision WHERE instanceDb = ? AND createdAt >= $since",
                            's', array( $db ) ) as $d ) {
      foreach ( (array) json_decode( $d[ 'candidatesJson' ], true ) as $c ) {
         if ( isset( $c[ 'verdict' ] ) && isset( $verdicts[ $c[ 'verdict' ] ] ) ) {
            $verdicts[ $c[ 'verdict' ] ]++;
         }
      }
   }
   $assessed = array_sum( $verdicts );
   $reconciled = dispatch_rows( $link, "SELECT
         SUM(e.toState = 'ACCEPTED' AND e.fromState = 'SUBMISSION_UNKNOWN') accepted,
         SUM(e.fromState = 'SUBMISSION_UNKNOWN' AND e.toState = 'WAITING') not_accepted,
         SUM(e.fromState = 'SUBMISSION_UNKNOWN' AND e.toState = 'SUBMISSION_UNKNOWN') unresolved_checks
      FROM `$db`.dispatch_event e WHERE e.createdAt >= $since" );
   ## A failed query (an instance without the dispatch tables) returns no rows.
   $accept = $accept ? $accept : array( array( 'n' => 0, 'avg_s' => null, 'max_s' => null ) );
   $reconciled = $reconciled ? $reconciled : array( array( 'accepted' => 0, 'not_accepted' => 0, 'unresolved_checks' => 0 ) );
   return array(
      'acceptance' => array( 'count' => (int) $accept[ 0 ][ 'n' ], 'avg_seconds' => $accept[ 0 ][ 'avg_s' ] !== null
                             ? (int) round( $accept[ 0 ][ 'avg_s' ] ) : null,
                             'max_seconds' => $accept[ 0 ][ 'max_s' ] !== null ? (int) $accept[ 0 ][ 'max_s' ] : null ),
      'open_by_state_and_destination' => $states,
      'waiting_by_reason' => $waiting,
      'assessments' => $verdicts + array( 'unknown_share' => $assessed ? round( $verdicts[ 'UNKNOWN' ] / $assessed, 3 ) : null ),
      'reconciliation' => array_map( 'intval', $reconciled[ 0 ] ),
   );
}
