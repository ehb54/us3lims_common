<?php
/* Durable dispatch ledger. Every state change is a compare-and-set on state and
 * claim generation, written with its event in one transaction; all times are the
 * database server's UTC clock. $link must reach the instance database $db (and
 * $gdb for coupled gfac changes) on one connection. PHP 7.2 compatible. */

include_once __DIR__ . '/dispatch_contract.php';

## Lease held across sbatch: covers the full safe-retry schedule (four attempts
## of up to 150s plus their waits), so a live submitter is not taken for lost.
const DISPATCH_SUBMIT_LEASE_SECONDS = 1200;

## Run $fn inside a transaction; commit when it returns true, otherwise roll back.
function dispatch_tx( $link, callable $fn )
{
   mysqli_begin_transaction( $link );
   try {
      $ok = $fn();
   }
   catch ( Exception $e ) {
      mysqli_rollback( $link );
      throw $e;
   }
   $ok ? mysqli_commit( $link ) : mysqli_rollback( $link );
   return $ok;
}

## Prepared statement helper: returns the statement after execution, or false.
## Works under either mysqli reporting mode (gridctl turns exceptions off; PHP 8.1+ web defaults throw).
## A failure is logged, since callers treat it as no rows or no change.
function dispatch_exec( $link, $sql, $types = '', array $args = array() )
{
   $log = function ( $error ) use ( $sql ) {
      dispatch_last_error( $error );
      error_log( 'dispatch ledger: ' . $error . ' in: ' . preg_replace( '/\s+/', ' ', substr( $sql, 0, 160 ) ) );
   };
   try {
      $stmt = mysqli_prepare( $link, $sql );
      if ( ! $stmt ) {
         $log( mysqli_error( $link ) );
         return false;
      }
      if ( $types !== '' ) {
         $refs = array( $stmt, $types );
         foreach ( $args as $k => $v ) {
            $refs[] = &$args[ $k ];
         }
         call_user_func_array( 'mysqli_stmt_bind_param', $refs );
      }
      if ( ! mysqli_stmt_execute( $stmt ) ) {
         $log( mysqli_stmt_error( $stmt ) );
         mysqli_stmt_close( $stmt );
         return false;
      }
      return $stmt;
   }
   catch ( mysqli_sql_exception $e ) {
      $log( $e->getMessage() );
      return false;
   }
}

## The last statement error dispatch_exec() met, kept after its statement is closed; $set records one.
function dispatch_last_error( $set = null )
{
   static $last = '';
   if ( $set !== null ) {
      $last = (string) $set;
   }
   return $last;
}

function dispatch_query( $link, $sql )
{
   try {
      return mysqli_query( $link, $sql );
   }
   catch ( mysqli_sql_exception $e ) {
      return false;
   }
}

function dispatch_affected( $link, $sql, $types = '', array $args = array() )
{
   $stmt = dispatch_exec( $link, $sql, $types, $args );
   if ( ! $stmt ) {
      return -1;
   }
   $n = mysqli_stmt_affected_rows( $stmt );
   mysqli_stmt_close( $stmt );
   return $n;
}

function dispatch_rows( $link, $sql, $types = '', array $args = array() )
{
   $stmt = dispatch_exec( $link, $sql, $types, $args );
   if ( ! $stmt ) {
      return array();
   }
   $res = mysqli_stmt_get_result( $stmt );
   $rows = $res ? mysqli_fetch_all( $res, MYSQLI_ASSOC ) : array();
   mysqli_stmt_close( $stmt );
   return $rows;
}

function dispatch_db_name_ok( $db )
{
   return is_string( $db ) && preg_match( '/^[A-Za-z0-9_]+$/', $db );
}

## The logical identity of one dispatch unit; retries and browser refreshes map to the same key.
function dispatch_logical_key( $db, $request_id, $stage = 'interactive', $occurrence = 1 )
{
   return "$db|$request_id|$stage|$occurrence";
}

## Keys for one submission's requests, fixed before any request ID exists: an
## interactive submission's nonce, or an autoflow stage occurrence.
function dispatch_intake_prefix( $db, $submitter_id, $nonce )
{
   return "$db|intake|" . (int) $submitter_id . "|$nonce|";
}

function dispatch_intake_key( $db, $submitter_id, $nonce, $child )
{
   return dispatch_intake_prefix( $db, $submitter_id, $nonce ) . (int) $child;
}

function dispatch_intake_batch_key( $db, $submitter_id, $nonce )
{
   return dispatch_intake_prefix( $db, $submitter_id, $nonce ) . 'batch';
}

## Requests already received under a submission's keys, in child order.
function dispatch_intake_existing( $link, $db, $submitter_id, $nonce )
{
   $prefix = dispatch_intake_prefix( $db, $submitter_id, $nonce );
   return dispatch_rows( $link, "SELECT dispatchRequestID, HPCAnalysisRequestID, state, intakeChecksum, reason
      FROM `$db`.dispatch_request WHERE LEFT(logicalKey, ?) = ? ORDER BY dispatchRequestID",
      'is', array( strlen( $prefix ), $prefix ) );
}

/**
 * Record a received request, once. $req: logicalKey, HPCAnalysisRequestID,
 * autoflowAnalysisID, stageName, stageOccurrence, parentBatchKey, batchSealed,
 * submitterID, investigatorID, pool, inputRef, inputChecksum, intakeChecksum,
 * priorityDirective, deadline_seconds, notify_seconds. Returns array( id, created ).
 */
function dispatch_intake( $link, $db, array $req )
{
   if ( ! dispatch_db_name_ok( $db ) ) {
      return array( 0, false );
   }
   $deadline = isset( $req[ 'deadline_seconds' ] ) ? (int) $req[ 'deadline_seconds' ] : 86400;
   $notify   = isset( $req[ 'notify_seconds' ] ) ? (int) $req[ 'notify_seconds' ] : 3600;
   $sql = "INSERT INTO `$db`.dispatch_request
              (logicalKey, HPCAnalysisRequestID, autoflowAnalysisID, stageName, stageOccurrence, parentBatchKey,
               batchSealed, submitterID, investigatorID, pool, inputRef, inputChecksum, intakeChecksum, priorityDirective,
               deadlineAt, notifyAt, createdAt, updatedAt)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, UTC_TIMESTAMP() + INTERVAL ? SECOND, UTC_TIMESTAMP() + INTERVAL ? SECOND,
                   UTC_TIMESTAMP(), UTC_TIMESTAMP())
           ON DUPLICATE KEY UPDATE dispatchRequestID = LAST_INSERT_ID(dispatchRequestID)";
   $args = array(
      $req[ 'logicalKey' ],
      isset( $req[ 'HPCAnalysisRequestID' ] ) ? (int) $req[ 'HPCAnalysisRequestID' ] : null,
      isset( $req[ 'autoflowAnalysisID' ] ) ? (int) $req[ 'autoflowAnalysisID' ] : null,
      isset( $req[ 'stageName' ] ) ? $req[ 'stageName' ] : 'interactive',
      isset( $req[ 'stageOccurrence' ] ) ? (int) $req[ 'stageOccurrence' ] : 1,
      isset( $req[ 'parentBatchKey' ] ) ? $req[ 'parentBatchKey' ] : null,
      isset( $req[ 'batchSealed' ] ) ? (int) (bool) $req[ 'batchSealed' ] : 1,
      (int) $req[ 'submitterID' ], (int) $req[ 'investigatorID' ], $req[ 'pool' ],
      isset( $req[ 'inputRef' ] ) ? $req[ 'inputRef' ] : '', $req[ 'inputChecksum' ],
      isset( $req[ 'intakeChecksum' ] ) ? $req[ 'intakeChecksum' ] : '',
      isset( $req[ 'priorityDirective' ] ) ? $req[ 'priorityDirective' ] : '',
      $deadline, $notify,
   );
   $n = dispatch_affected( $link, $sql, 'siisisiiisssssii', $args );
   if ( $n < 0 ) {
      return array( 0, false );
   }
   ## affected rows: 1 for a new row, 0 when the key already existed.
   $id = (int) mysqli_insert_id( $link );
   ## A failed event (a deadlock rolls back the page's whole transaction) fails the intake.
   if ( $n === 1 && ! dispatch_append_event( $link, $db, $id, null, DISPATCH_PENDING, 0, 'received' ) ) {
      return array( 0, false );
   }
   return array( $id, $n === 1 );
}

## The event keeps the request's cluster and reason code as they stand after the change it records.
function dispatch_append_event( $link, $db, $id, $from, $to, $generation, $reason )
{
   return dispatch_affected( $link,
      "INSERT INTO `$db`.dispatch_event (dispatchRequestID, fromState, toState, claimGeneration, reason, cluster, reasonCode,
                                         createdAt)
       SELECT ?,?,?,?,?, selectedCluster, reasonCode, UTC_TIMESTAMP() FROM `$db`.dispatch_request WHERE dispatchRequestID = ?",
      'issisi', array( (int) $id, $from, $to, (int) $generation, $reason, (int) $id ) ) === 1;
}

function dispatch_get( $link, $db, $id )
{
   $rows = dispatch_rows( $link, "SELECT * FROM `$db`.dispatch_request WHERE dispatchRequestID = ?", 'i', array( (int) $id ) );
   return $rows ? $rows[ 0 ] : null;
}

/**
 * Claim one request for assessment: PENDING or WAITING whose retry time has
 * come, or ASSESSING/STAGING whose lease expired before the submission gate. A
 * sealed batch is required. Returns the new claim generation, or 0 when another
 * owner holds it. Work past the gate is never reclaimed.
 */
function dispatch_claim( $link, $db, $id, $owner, $lease_seconds )
{
   $claimed = 0;
   dispatch_tx( $link, function () use ( $link, $db, $id, $owner, $lease_seconds, &$claimed ) {
      $rows = dispatch_rows( $link, "SELECT state, claimGeneration FROM `$db`.dispatch_request
         WHERE dispatchRequestID = ? AND batchSealed = 1 AND (
            (state IN ('PENDING','WAITING') AND (retryAt IS NULL OR retryAt <= UTC_TIMESTAMP()))
            OR (state IN ('ASSESSING','STAGING') AND claimExpiresAt < UTC_TIMESTAMP()) ) FOR UPDATE", 'i', array( (int) $id ) );
      if ( ! $rows ) {
         return false;
      }
      $from = $rows[ 0 ][ 'state' ];
      $gen = (int) $rows[ 0 ][ 'claimGeneration' ] + 1;
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'ASSESSING', claimOwner = ?,
            claimGeneration = ?, claimExpiresAt = UTC_TIMESTAMP() + INTERVAL ? SECOND, updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND state = ? AND claimGeneration = ?",
         'siiisi', array( $owner, $gen, (int) $lease_seconds, (int) $id, $from, $gen - 1 ) );
      if ( $n !== 1 || ! dispatch_append_event( $link, $db, $id, $from, DISPATCH_ASSESSING, $gen, "claimed by $owner" ) ) {
         return false;
      }
      $claimed = $gen;
      return true;
   } );
   return $claimed;
}

## Extend the current claim's lease; false once it was lost. A renewal within
## the same second changes nothing, so the claim is confirmed by a read.
function dispatch_renew( $link, $db, $id, $generation, $owner, $lease_seconds )
{
   $where = "dispatchRequestID = ? AND claimOwner = ? AND claimGeneration = ? AND state IN ('ASSESSING','STAGING')";
   $args = array( (int) $id, $owner, (int) $generation );
   $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request
         SET claimExpiresAt = UTC_TIMESTAMP() + INTERVAL ? SECOND, updatedAt = UTC_TIMESTAMP() WHERE $where",
      'iisi', array_merge( array( (int) $lease_seconds ), $args ) );
   return $n === 1 || ( $n === 0 && dispatch_rows( $link, "SELECT 1 FROM `$db`.dispatch_request WHERE $where",
                                                   'isi', $args ) !== array() );
}

/**
 * Requests a worker may claim now, oldest first: due PENDING/WAITING and
 * ASSESSING/STAGING with an expired lease, sealed and not past their deadline.
 */
function dispatch_ready( $link, $db, $limit )
{
   return array_map( 'intval', array_column( dispatch_rows( $link, "SELECT dispatchRequestID FROM `$db`.dispatch_request
      WHERE batchSealed = 1 AND deadlineAt > UTC_TIMESTAMP() AND (
         (state IN ('PENDING','WAITING') AND (retryAt IS NULL OR retryAt <= UTC_TIMESTAMP()))
         OR (state IN ('ASSESSING','STAGING') AND claimExpiresAt < UTC_TIMESTAMP()) )
      ORDER BY createdAt, dispatchRequestID LIMIT ?", 'i', array( (int) $limit ) ), 'dispatchRequestID' ) );
}

## Every child of a batch, in intake order: what the batch preflight plans.
function dispatch_batch_children( $link, $db, $batch_key )
{
   return dispatch_rows( $link, "SELECT dispatchRequestID, inputRef, inputChecksum, priorityDirective
      FROM `$db`.dispatch_request WHERE parentBatchKey = ? ORDER BY dispatchRequestID", 's', array( $batch_key ) );
}

## The destination a batch's unsubmitted children are pinned to, or null before one is staged.
function dispatch_batch_pin( $link, $db, $batch_key )
{
   if ( $batch_key === null || $batch_key === '' ) {
      return null;
   }
   $rows = dispatch_rows( $link, "SELECT pinnedCluster FROM `$db`.dispatch_request
      WHERE parentBatchKey = ? AND pinnedCluster IS NOT NULL AND state IN ('PENDING','ASSESSING','WAITING','STAGING','BLOCKED') LIMIT 1", 's', array( $batch_key ) );
   return $rows ? $rows[ 0 ][ 'pinnedCluster' ] : null;
}

/**
 * Re-pin a batch away from an unreachable $cluster: every child not yet past the
 * submission gate is unpinned and, if waiting, retried now, so the children move
 * together to a reachable member. Children submitted or accepted there stay.
 * Returns whether any child was unpinned.
 */
function dispatch_batch_unpin( $link, $db, $batch_key, $cluster )
{
   if ( $batch_key === null || $batch_key === '' ) {
      return false;
   }
   return dispatch_tx( $link, function () use ( $link, $db, $batch_key, $cluster ) {
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request
            SET pinnedCluster = NULL, retryAt = IF(state = 'WAITING', UTC_TIMESTAMP(), retryAt)
         WHERE parentBatchKey = ? AND pinnedCluster = ? AND state IN ('PENDING','ASSESSING','WAITING','STAGING','BLOCKED')", 'ss', array( $batch_key, $cluster ) );
      return $n > 0;
   } );
}

## The member a request's latest wait found unreachable while staging or submitting, or null.
function dispatch_request_unreachable_at( $link, $db, $id )
{
   $rows = dispatch_rows( $link, "SELECT toState, reasonCode, fromState, cluster FROM `$db`.dispatch_event
      WHERE dispatchRequestID = ? ORDER BY eventID DESC LIMIT 1", 'i', array( (int) $id ) );
   if ( ! $rows ) {
      return null;
   }
   $e = $rows[ 0 ];
   return $e[ 'toState' ] === 'WAITING' && $e[ 'reasonCode' ] === 'unreachable'
          && in_array( $e[ 'fromState' ], array( 'STAGING', 'SUBMITTING' ), true ) ? $e[ 'cluster' ] : null;
}

/**
 * ASSESSING to STAGING at $cluster for the current claim and an unexpired
 * deadline. A batch child pins every sibling to $cluster in the same
 * transaction, and loses if the batch is already pinned elsewhere.
 * $turn: array( gdb, scopeKey, state, lane, laneOwner, laneGeneration ) saves the rotation turn
 * with the transition while that rotation lane is still held, or null.
 */
function dispatch_stage( $link, $db, $id, $generation, $cluster, $plan_hash, $policy_version, $reason, $turn = null )
{
   return dispatch_tx( $link, function () use ( $link, $db, $id, $generation, $cluster, $plan_hash, $policy_version, $reason, $turn ) {
      $rows = dispatch_rows( $link, "SELECT parentBatchKey FROM `$db`.dispatch_request
         WHERE dispatchRequestID = ? AND state = 'ASSESSING' AND claimGeneration = ? AND deadlineAt > UTC_TIMESTAMP()
         FOR UPDATE", 'ii', array( (int) $id, (int) $generation ) );
      if ( ! $rows ) {
         return false;
      }
      $batch = $rows[ 0 ][ 'parentBatchKey' ];
      if ( $batch !== null ) {
         $pins = dispatch_rows( $link, "SELECT DISTINCT pinnedCluster FROM `$db`.dispatch_request
            WHERE parentBatchKey = ? AND pinnedCluster IS NOT NULL AND state IN ('PENDING','ASSESSING','WAITING','STAGING','BLOCKED') FOR UPDATE", 's', array( $batch ) );
         if ( $pins && $pins[ 0 ][ 'pinnedCluster' ] !== $cluster ) {
            return false;
         }
         if ( dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET pinnedCluster = ?
               WHERE parentBatchKey = ? AND pinnedCluster IS NULL AND state IN ('PENDING','ASSESSING','WAITING','STAGING','BLOCKED')", 'ss', array( $cluster, $batch ) ) < 0 ) {
            return false;
         }
      }
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'STAGING', selectedCluster = ?,
            planHash = ?, policyVersion = ?, reason = ?, updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND state = 'ASSESSING' AND claimGeneration = ?",
         'ssssii', array( $cluster, $plan_hash, $policy_version, $reason, (int) $id, (int) $generation ) );
      return $n === 1 && dispatch_append_event( $link, $db, $id, DISPATCH_ASSESSING, DISPATCH_STAGING, $generation, $reason )
             && ( $turn === null || ( dispatch_rows( $link, "SELECT 1 FROM `{$turn[0]}`.dispatch_controller_claim
                       WHERE controller = ? AND claimOwner = ? AND claimGeneration = ? AND claimExpiresAt >= UTC_TIMESTAMP()
                       FOR UPDATE", 'ssi', array( $turn[ 3 ], $turn[ 4 ], (int) $turn[ 5 ] ) ) !== array()
                                      && dispatch_rotation_save( $link, $turn[ 0 ], $turn[ 1 ], $turn[ 2 ] ) ) );
   } );
}

## How many times a request has entered $state; drives backoff and the reselection limit.
function dispatch_state_entries( $link, $db, $id, $state, $from = null )
{
   $sql = "SELECT COUNT(*) n FROM `$db`.dispatch_event WHERE dispatchRequestID = ? AND toState = ?";
   $types = 'is';
   $args = array( (int) $id, $state );
   if ( $from !== null ) {
      $sql .= ' AND fromState = ?';
      $types .= 's';
      $args[] = $from;
   }
   $rows = dispatch_rows( $link, $sql, $types, $args );
   return $rows ? (int) $rows[ 0 ][ 'n' ] : 0;
}

/**
 * Move a claimed request from $from to $to when it is still in $from with
 * this claim generation. $fields may set retryAt (seconds from now),
 * selectedCluster, planHash, policyVersion, pinnedCluster, reasonCode, reason.
 * Returns true only when this owner made the change.
 */
function dispatch_transition( $link, $db, $id, $generation, $from, $to, $reason, array $fields = array() )
{
   if ( ! dispatch_transition_allowed( $from, $to ) ) {
      return false;
   }
   return dispatch_tx( $link, function () use ( $link, $db, $id, $generation, $from, $to, $reason, $fields ) {
      list( $set, $types, $args ) = dispatch_field_updates( $fields, $reason );
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = ?, $set updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND state = ? AND claimGeneration = ?",
         's' . $types . 'isi', array_merge( array( $to ), $args, array( (int) $id, $from, (int) $generation ) ) );
      return $n === 1 && dispatch_append_event( $link, $db, $id, $from, $to, $generation, $reason );
   } );
}

function dispatch_field_updates( array $fields, $reason )
{
   $set = 'reason = ?, ';
   $types = 's';
   $args = array( $reason );
   foreach ( array( 'selectedCluster', 'planHash', 'policyVersion', 'pinnedCluster', 'reasonCode' ) as $k ) {
      if ( array_key_exists( $k, $fields ) ) {
         $set .= "$k = ?, ";
         $types .= 's';
         $args[] = $fields[ $k ];
      }
   }
   if ( array_key_exists( 'retry_seconds', $fields ) ) {
      $set .= 'retryAt = UTC_TIMESTAMP() + INTERVAL ? SECOND, ';
      $types .= 'i';
      $args[] = (int) $fields[ 'retry_seconds' ];
   }
   return array( $set, $types, $args );
}

/**
 * The submission gate: STAGING to SUBMITTING for the current, unexpired claim
 * and deadline, writing the attempt intent in one transaction. Only the
 * winner may run sbatch. $artifact: the finalized request XML's checksum,
 * when the stager made one. Returns the attempt token, or '' when the gate was lost.
 */
function dispatch_submission_gate( $link, $db, $id, $generation, $cluster, array $plan,
                                   $submit_lease = DISPATCH_SUBMIT_LEASE_SECONDS, $artifact = null )
{
   $token = bin2hex( random_bytes( 16 ) );
   $ok = dispatch_tx( $link, function () use ( $link, $db, $id, $generation, $cluster, $plan, $token, $submit_lease,
                                               $artifact ) {
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'SUBMITTING', selectedCluster = ?,
            planHash = ?, claimExpiresAt = UTC_TIMESTAMP() + INTERVAL ? SECOND, updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND state = 'STAGING' AND claimGeneration = ?
           AND claimExpiresAt >= UTC_TIMESTAMP() AND deadlineAt > UTC_TIMESTAMP()",
         'ssiii', array( $cluster, $plan[ 'hash' ], (int) $submit_lease, (int) $id, (int) $generation ) );
      if ( $n !== 1 ) {
         return false;
      }
      $n = dispatch_affected( $link, "INSERT INTO `$db`.dispatch_attempt
            (dispatchRequestID, attemptToken, cluster, planHash, planJson, artifactChecksum, createdAt)
         VALUES (?,?,?,?,?,?, UTC_TIMESTAMP())", 'isssss',
         array( (int) $id, $token, $cluster, $plan[ 'hash' ], json_encode( $plan ), $artifact ) );
      return $n === 1 && dispatch_append_event( $link, $db, $id, DISPATCH_STAGING, DISPATCH_SUBMITTING, $generation,
                                                "attempt $token at $cluster" );
   } );
   return $ok ? $token : '';
}

/**
 * Record a submission outcome for an attempt, identified by its token so a
 * reconciling owner can settle it after a lease expires. $outcome is a
 * dispatch_outcome_from_sbatch_result() array. On acceptance the request
 * becomes ACCEPTED; on established nonacceptance it becomes $next (WAITING,
 * BLOCKED or FAILED); an uncertain outcome holds it in SUBMISSION_UNKNOWN.
 */
function dispatch_record_outcome( $link, $db, $token, array $outcome, $next = DISPATCH_WAITING, $retry_seconds = null )
{
   return dispatch_tx( $link, function () use ( $link, $db, $token, $outcome, $next, $retry_seconds ) {
      $rows = dispatch_rows( $link, "SELECT a.dispatchRequestID, a.phase, a.cluster, r.state, r.claimGeneration,
            r.cancelRequested
         FROM `$db`.dispatch_attempt a JOIN `$db`.dispatch_request r USING (dispatchRequestID)
         WHERE a.attemptToken = ? FOR UPDATE", 's', array( $token ) );
      if ( ! $rows || ! in_array( $rows[ 0 ][ 'phase' ], array( 'INTENT', 'UNKNOWN' ), true ) ) {
         return false;
      }
      $r = $rows[ 0 ];
      $id = (int) $r[ 'dispatchRequestID' ];
      $phase = array( SUBMIT_ACCEPTED => 'ACCEPTED', SUBMIT_NOT_ACCEPTED => 'NOT_ACCEPTED', SUBMIT_UNKNOWN => 'UNKNOWN' );
      $to = $outcome[ 'certainty' ] === SUBMIT_ACCEPTED ? DISPATCH_ACCEPTED
          : ( $outcome[ 'certainty' ] === SUBMIT_UNKNOWN ? DISPATCH_SUBMISSION_UNKNOWN : $next );
      if ( ! dispatch_transition_allowed( $r[ 'state' ], $to ) && $r[ 'state' ] !== $to ) {
         return false;
      }
      $job = $outcome[ 'certainty' ] === SUBMIT_ACCEPTED ? $outcome[ 'job_id' ] : null;
      if ( dispatch_affected( $link, "UPDATE `$db`.dispatch_attempt SET phase = ?, schedulerJobID = ?, outcomeReason = ?,
               resolvedAt = UTC_TIMESTAMP() WHERE attemptToken = ?",
            'ssss', array( $phase[ $outcome[ 'certainty' ] ], $job, $outcome[ 'reason' ], $token ) ) !== 1 ) {
         return false;
      }
      if ( $r[ 'state' ] !== $to ) {
         $why = $job !== null ? "accepted as {$r['cluster']} job $job" : $outcome[ 'reason' ];
         $retry = $to === DISPATCH_WAITING && $retry_seconds !== null ? (int) $retry_seconds : null;
         ## An uncertain outcome is released to reconciliation, which starts after a short wait.
         if ( $to === DISPATCH_SUBMISSION_UNKNOWN ) {
            $retry = DISPATCH_RECONCILE_FIRST_SECONDS;
            dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET claimOwner = NULL, claimExpiresAt = NULL
               WHERE dispatchRequestID = ?", 'i', array( $id ) );
         }
         if ( dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = ?, reason = ?, reasonCode = ?,
                    retryAt = IF(? IS NULL, retryAt, UTC_TIMESTAMP() + INTERVAL ? SECOND), updatedAt = UTC_TIMESTAMP()
                 WHERE dispatchRequestID = ? AND state = ?", 'sssiiis',
                 array( $to, $why, dispatch_outcome_code( $outcome ), $retry, $retry, $id, $r[ 'state' ] ) ) !== 1
              || ! dispatch_append_event( $link, $db, $id, $r[ 'state' ], $to, (int) $r[ 'claimGeneration' ], $why ) ) {
            return false;
         }
      }
      ## A cancellation requested while the gate was held settles once nothing was submitted.
      if ( $to === DISPATCH_WAITING && (int) $r[ 'cancelRequested' ] > 0
           && ( dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'CANCELLED',
                    reason = 'cancelled; nothing was submitted', reasonCode = 'cancelled', updatedAt = UTC_TIMESTAMP()
                 WHERE dispatchRequestID = ? AND state = 'WAITING'", 'i', array( $id ) ) !== 1
                || ! dispatch_append_event( $link, $db, $id, DISPATCH_WAITING, DISPATCH_CANCELLED,
                                            (int) $r[ 'claimGeneration' ], 'cancelled; nothing was submitted' ) ) ) {
         return false;
      }
      return true;
   } );
}

## The reason code an sbatch outcome leaves on its request.
function dispatch_outcome_code( array $outcome )
{
   if ( $outcome[ 'certainty' ] === SUBMIT_ACCEPTED ) {
      return null;
   }
   if ( $outcome[ 'certainty' ] === SUBMIT_UNKNOWN ) {
      return 'uncertain';
   }
   $codes = array( 'never_started' => 'unreachable', 'connect_failure' => 'unreachable', 'breaker_open' => 'unreachable',
                   'busy' => 'occupied', 'definite_rejection' => 'rejected' );
   return isset( $codes[ $outcome[ 'reason' ] ] ) ? $codes[ $outcome[ 'reason' ] ] : 'not_submitted';
}

## Cancel a never-submitted request. Wins only if it beats the submission gate.
function dispatch_cancel_local( $link, $db, $id, $reason )
{
   $req = dispatch_get( $link, $db, $id );
   if ( ! $req || ! dispatch_cancel_is_local( $req[ 'state' ] ) ) {
      return false;
   }
   return dispatch_tx( $link, function () use ( $link, $db, $id, $req, $reason ) {
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'CANCELLED', reason = ?, reasonCode = 'cancelled',
            updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND state = ? AND claimGeneration = ?",
         'sisi', array( $reason, (int) $id, $req[ 'state' ], (int) $req[ 'claimGeneration' ] ) );
      return $n === 1 && dispatch_append_event( $link, $db, $id, $req[ 'state' ], DISPATCH_CANCELLED,
                                                (int) $req[ 'claimGeneration' ], $reason );
   } );
}

## Members a request found unreachable in the last $seconds, as select()'s 'reachable' input
## (member => false): later decisions, the failed request's own retry included, go elsewhere until
## the window passes or a job is accepted there. Read from the events, which keep the failed member;
## only a wait leaving staging or submission contacted it, so a wait caused by the exclusion does not renew it.
function dispatch_recently_unreachable( $link, $db, $seconds )
{
   $out = array();
   foreach ( dispatch_rows( $link, "SELECT DISTINCT e.cluster FROM `$db`.dispatch_event e
         WHERE e.reasonCode = 'unreachable' AND e.toState = 'WAITING' AND e.cluster IS NOT NULL
           AND e.fromState IN ('STAGING','SUBMITTING')
           AND e.createdAt >= UTC_TIMESTAMP() - INTERVAL ? SECOND
           AND NOT EXISTS (SELECT 1 FROM `$db`.dispatch_attempt a WHERE a.cluster = e.cluster AND a.phase = 'ACCEPTED'
                                                                    AND a.resolvedAt >= e.createdAt)",
         'i', array( (int) $seconds ) ) as $r ) {
      $out[ $r[ 'cluster' ] ] = false;
   }
   return $out;
}

## Expire every request past its intake deadline that was never submitted. Returns the expired IDs.
function dispatch_expire_due( $link, $db )
{
   $expired = array();
   $due = dispatch_rows( $link, "SELECT dispatchRequestID, state, claimGeneration FROM `$db`.dispatch_request
      WHERE state IN ('PENDING','ASSESSING','WAITING','STAGING') AND deadlineAt <= UTC_TIMESTAMP()" );
   foreach ( $due as $r ) {
      $id = (int) $r[ 'dispatchRequestID' ];
      $ok = dispatch_tx( $link, function () use ( $link, $db, $id, $r ) {
         $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'EXPIRED',
               reason = 'not submitted within the maximum wait', reasonCode = 'expired', updatedAt = UTC_TIMESTAMP()
            WHERE dispatchRequestID = ? AND state = ? AND claimGeneration = ? AND deadlineAt <= UTC_TIMESTAMP()",
            'isi', array( $id, $r[ 'state' ], (int) $r[ 'claimGeneration' ] ) );
         return $n === 1 && dispatch_append_event( $link, $db, $id, $r[ 'state' ], DISPATCH_EXPIRED,
                                                   (int) $r[ 'claimGeneration' ], 'not submitted within the maximum wait' );
      } );
      if ( $ok ) {
         $expired[] = $id;
      }
   }
   return $expired;
}

## Fail every never-submitted request of a disabled pool. Staging work finishes at its frozen destination.
function dispatch_fail_disabled_pool( $link, $db, $pool )
{
   $failed = array();
   $reason = "metascheduler '$pool' is disabled (active=false)";
   $rows = dispatch_rows( $link, "SELECT dispatchRequestID, state, claimGeneration FROM `$db`.dispatch_request
      WHERE pool = ? AND state IN ('PENDING','ASSESSING','WAITING','BLOCKED')", 's', array( $pool ) );
   foreach ( $rows as $r ) {
      $id = (int) $r[ 'dispatchRequestID' ];
      $ok = dispatch_tx( $link, function () use ( $link, $db, $id, $r, $reason ) {
         $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'FAILED', reason = ?, reasonCode = 'pool_disabled',
               updatedAt = UTC_TIMESTAMP()
            WHERE dispatchRequestID = ? AND state = ? AND claimGeneration = ?",
            'sisi', array( $reason, $id, $r[ 'state' ], (int) $r[ 'claimGeneration' ] ) );
         return $n === 1 && dispatch_append_event( $link, $db, $id, $r[ 'state' ], DISPATCH_FAILED,
                                                   (int) $r[ 'claimGeneration' ], $reason );
      } );
      if ( $ok ) {
         $failed[] = $id;
      }
   }
   return $failed;
}

## Delete terminal requests (and their attempts and events) last updated longer ago than the retention period.
function dispatch_purge_terminal( $link, $db, $retention_seconds )
{
   if ( (int) $retention_seconds <= 0 ) {
      return 0;
   }
   $ids = array_map( 'intval', array_column( dispatch_rows( $link, "SELECT dispatchRequestID FROM `$db`.dispatch_request
      WHERE state IN ('CLOSED','FAILED','EXPIRED','CANCELLED') AND updatedAt < UTC_TIMESTAMP() - INTERVAL ? SECOND",
      'i', array( (int) $retention_seconds ) ), 'dispatchRequestID' ) );
   if ( ! $ids ) {
      return 0;
   }
   $in = implode( ',', $ids );
   dispatch_tx( $link, function () use ( $link, $db, $in ) {
      return dispatch_query( $link, "DELETE FROM `$db`.dispatch_event WHERE dispatchRequestID IN ($in)" )
          && dispatch_query( $link, "DELETE FROM `$db`.dispatch_attempt WHERE dispatchRequestID IN ($in)" )
          && dispatch_query( $link, "DELETE FROM `$db`.dispatch_request WHERE dispatchRequestID IN ($in)
                                   AND state IN ('CLOSED','FAILED','EXPIRED','CANCELLED')" );
   } );
   return count( $ids );
}

## Retention periods from global_config.php: array( 'requests' => s, 'decisions' => s ); 0 disables a purge.
function dispatch_retention_settings()
{
   $requests  = isset( $GLOBALS[ 'global_dispatch_retention_seconds' ] )
              ? $GLOBALS[ 'global_dispatch_retention_seconds' ] : 7776000;
   $decisions = isset( $GLOBALS[ 'global_dispatch_decision_retention_seconds' ] )
              ? $GLOBALS[ 'global_dispatch_decision_retention_seconds' ] : 31536000;
   $legacy    = isset( $GLOBALS[ 'global_metascheduler_decision_retention_seconds' ] )
              ? $GLOBALS[ 'global_metascheduler_decision_retention_seconds' ] : 31536000;
   return array( 'requests' => max( 0, (int) $requests ), 'decisions' => max( 0, (int) $decisions ),
                 'legacy_decisions' => max( 0, (int) $legacy ) );
}

## Decision history from the retired job-count selector, kept until its own
## retention setting ages it out. Returns the rows purged; 0 without the table.
function dispatch_purge_legacy_decisions( $link, $gdb, $retention_seconds )
{
   if ( (int) $retention_seconds <= 0 || ! dispatch_rows( $link, "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'metascheduler_decision'", 's', array( $gdb ) ) ) {
      return 0;
   }
   return max( 0, dispatch_affected( $link, "DELETE FROM `$gdb`.metascheduler_decision
      WHERE time < FROM_UNIXTIME(UNIX_TIMESTAMP() - ?)", 'i', array( (int) $retention_seconds ) ) );
}

## Delete decision history older than the retention period. Returns rows removed.
function dispatch_purge_decisions( $link, $gdb, $retention_seconds )
{
   if ( (int) $retention_seconds <= 0 ) {
      return 0;
   }
   return max( 0, dispatch_affected( $link, "DELETE FROM `$gdb`.dispatch_decision
      WHERE createdAt < UTC_TIMESTAMP() - INTERVAL ? SECOND", 'i', array( (int) $retention_seconds ) ) );
}

## Rotation state for one scope (instance, pool, group, policy version).
function dispatch_rotation_scope( $db, $pool, $group, $policy_version )
{
   return "$db|$pool|$group|$policy_version";
}

function dispatch_rotation_load( $link, $gdb, $scope )
{
   $rows = dispatch_rows( $link, "SELECT stateJson FROM `$gdb`.dispatch_rotation WHERE scopeKey = ?", 's', array( $scope ) );
   $state = $rows ? json_decode( $rows[ 0 ][ 'stateJson' ], true ) : null;
   return is_array( $state ) ? $state : array();
}

## Save a scope's rotation state: the turn a selection took.
function dispatch_rotation_save( $link, $gdb, $scope, array $state )
{
   ## A sole eligible member leaves the state as it was; zero rows changed is still saved.
   return dispatch_affected( $link, "INSERT INTO `$gdb`.dispatch_rotation (scopeKey, stateJson, updatedAt)
         VALUES (?,?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE stateJson = VALUES(stateJson), updatedAt = UTC_TIMESTAMP()",
      'ss', array( $scope, json_encode( $state ) ) ) >= 0;
}

/**
 * Take the availability-decision lane for one physical scheduler. Returns the
 * new generation, or 0 while another owner's lease is live.
 */
function dispatch_controller_claim( $link, $gdb, $controller, $owner, $lease_seconds )
{
   dispatch_affected( $link, "INSERT IGNORE INTO `$gdb`.dispatch_controller_claim (controller) VALUES (?)", 's', array( $controller ) );
   $n = dispatch_affected( $link, "UPDATE `$gdb`.dispatch_controller_claim SET claimOwner = ?,
         claimGeneration = claimGeneration + 1, claimExpiresAt = UTC_TIMESTAMP() + INTERVAL ? SECOND
      WHERE controller = ? AND (claimOwner IS NULL OR claimOwner = ? OR claimExpiresAt < UTC_TIMESTAMP())",
      'siss', array( $owner, (int) $lease_seconds, $controller, $owner ) );
   if ( $n !== 1 ) {
      return 0;
   }
   $rows = dispatch_rows( $link, "SELECT claimGeneration FROM `$gdb`.dispatch_controller_claim WHERE controller = ?",
                          's', array( $controller ) );
   return $rows ? (int) $rows[ 0 ][ 'claimGeneration' ] : 0;
}

## Extend a held lane's lease, keeping its generation; false once it was lost.
function dispatch_controller_renew( $link, $gdb, $controller, $owner, $generation, $lease_seconds )
{
   $n = dispatch_affected( $link, "UPDATE `$gdb`.dispatch_controller_claim SET claimExpiresAt = UTC_TIMESTAMP() + INTERVAL ? SECOND
      WHERE controller = ? AND claimOwner = ? AND claimGeneration = ?", 'issi',
      array( (int) $lease_seconds, $controller, $owner, (int) $generation ) );
   return $n === 1 || ( $n === 0 && dispatch_rows( $link, "SELECT 1 FROM `$gdb`.dispatch_controller_claim
      WHERE controller = ? AND claimOwner = ? AND claimGeneration = ?", 'ssi', array( $controller, $owner, (int) $generation ) ) !== array() );
}

function dispatch_controller_release( $link, $gdb, $controller, $owner, $generation )
{
   return dispatch_affected( $link, "UPDATE `$gdb`.dispatch_controller_claim SET claimOwner = NULL, claimExpiresAt = NULL
      WHERE controller = ? AND claimOwner = ? AND claimGeneration = ?", 'ssi',
      array( $controller, $owner, (int) $generation ) ) === 1;
}

## Claim keys sharing dispatch_controller_claim: one per physical scheduler, one per rotation scope.
function dispatch_lane_controller( $controller )
{
   return 'controller:' . $controller;
}

function dispatch_lane_rotation( $db, $pool, $policy_version )
{
   return 'rotation:' . hash( 'sha256', "$db|$pool|$policy_version" );
}

## $policy: the strategy, admission and weights the decision applied, for the operator's history.
function dispatch_record_decision( $link, $gdb, $db, $id, $pool, array $decision, array $policy = array() )
{
   return dispatch_affected( $link, "INSERT INTO `$gdb`.dispatch_decision
         (instanceDb, dispatchRequestID, pool, policyVersion, outcome, chosen, reason, candidatesJson, policyJson, createdAt)
      VALUES (?,?,?,?,?,?,?,?,?, UTC_TIMESTAMP())", 'sisssssss',
      array( $db, (int) $id, $pool, $decision[ 'policy_version' ], $decision[ 'outcome' ], $decision[ 'chosen' ],
             $decision[ 'reason' ], json_encode( $decision[ 'candidates' ] ), json_encode( $policy ) ) ) === 1;
}

## The part of a pool policy an operator reads in decision history.
function dispatch_policy_summary( array $policy )
{
   $groups = array();
   foreach ( array( 'primary', 'overflow' ) as $g ) {
      if ( ! empty( $policy[ $g ][ 'members' ] ) ) {
         $groups[ $g ] = array( 'members' => $policy[ $g ][ 'members' ],
                                'strategy' => isset( $policy[ $g ][ 'strategy' ] ) ? $policy[ $g ][ 'strategy' ] : null,
                                'weights' => isset( $policy[ $g ][ 'weights' ] ) ? $policy[ $g ][ 'weights' ] : null );
      }
   }
   return array( 'admission' => isset( $policy[ 'admission' ] ) ? $policy[ 'admission' ] : null,
                 'overflow_enabled' => ! empty( $policy[ 'overflow_enabled' ] ), 'groups' => $groups );
}

## -------------------------------------------------------------------------
## Acceptance recovery: lost workers, lost replies, the receipt spool and
## tracking repair. None of these ever submits again.
## -------------------------------------------------------------------------

const DISPATCH_RECONCILE_FIRST_SECONDS = 60;
const DISPATCH_RECONCILE_MAX_SECONDS   = 3600;
## Checks after which an unresolved submission needs the operator.
const DISPATCH_RECONCILE_ALERT_AFTER   = 3;

/**
 * A worker lost after winning the submission gate leaves SUBMITTING with an
 * expired lease. sbatch may have run, so the request is held as
 * SUBMISSION_UNKNOWN for reconciliation; it is never reclaimed or expired.
 * Returns the IDs held.
 */
function dispatch_hold_lost_submissions( $link, $db )
{
   $held = array();
   $rows = dispatch_rows( $link, "SELECT dispatchRequestID, claimGeneration FROM `$db`.dispatch_request
      WHERE state = 'SUBMITTING' AND claimExpiresAt < UTC_TIMESTAMP()" );
   $reason = 'the worker was lost after the submission gate; checking whether the job was accepted';
   foreach ( $rows as $r ) {
      $id = (int) $r[ 'dispatchRequestID' ];
      $gen = (int) $r[ 'claimGeneration' ];
      $ok = dispatch_tx( $link, function () use ( $link, $db, $id, $gen, $reason ) {
         $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'SUBMISSION_UNKNOWN', reason = ?, reasonCode = 'uncertain',
               claimOwner = NULL, claimExpiresAt = NULL, retryAt = UTC_TIMESTAMP(), updatedAt = UTC_TIMESTAMP()
            WHERE dispatchRequestID = ? AND state = 'SUBMITTING' AND claimGeneration = ? AND claimExpiresAt < UTC_TIMESTAMP()",
            'sii', array( $reason, $id, $gen ) );
         return $n === 1
             && dispatch_affected( $link, "UPDATE `$db`.dispatch_attempt SET phase = 'UNKNOWN', outcomeReason = 'worker_lost'
                   WHERE dispatchRequestID = ? AND phase = 'INTENT'", 'i', array( $id ) ) >= 0
             && dispatch_append_event( $link, $db, $id, DISPATCH_SUBMITTING, DISPATCH_SUBMISSION_UNKNOWN, $gen, $reason );
      } );
      if ( $ok ) {
         $held[] = $id;
      }
   }
   return $held;
}

## Requests due a recovery check, oldest first: held submissions (reconciliation),
## accepted ones (tracking repair) and monitored ones with a cancellation to deliver.
function dispatch_recovery_ready( $link, $db, $limit )
{
   return array_map( 'intval', array_column( dispatch_rows( $link, "SELECT dispatchRequestID FROM `$db`.dispatch_request
      WHERE ( state IN ('SUBMISSION_UNKNOWN','ACCEPTED') OR ( state = 'MONITORED' AND cancelRequested = 1 ) )
        AND (retryAt IS NULL OR retryAt <= UTC_TIMESTAMP())
        AND (claimExpiresAt IS NULL OR claimExpiresAt < UTC_TIMESTAMP())
      ORDER BY createdAt, dispatchRequestID LIMIT ?", 'i', array( (int) $limit ) ), 'dispatchRequestID' ) );
}

/**
 * Claim a held or accepted request for one recovery check without changing its
 * state, so two workers never check it at once. Returns the generation, or 0.
 */
function dispatch_claim_recovery( $link, $db, $id, $owner, $lease_seconds )
{
   $rows = dispatch_rows( $link, "SELECT claimGeneration FROM `$db`.dispatch_request WHERE dispatchRequestID = ?
      AND state IN ('SUBMISSION_UNKNOWN','ACCEPTED','MONITORED')", 'i', array( (int) $id ) );
   if ( ! $rows ) {
      return 0;
   }
   $gen = (int) $rows[ 0 ][ 'claimGeneration' ] + 1;
   $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET claimOwner = ?, claimGeneration = ?,
         claimExpiresAt = UTC_TIMESTAMP() + INTERVAL ? SECOND
      WHERE dispatchRequestID = ? AND claimGeneration = ? AND state IN ('SUBMISSION_UNKNOWN','ACCEPTED','MONITORED')
        AND (claimExpiresAt IS NULL OR claimExpiresAt < UTC_TIMESTAMP())",
      'siiii', array( $owner, $gen, (int) $lease_seconds, (int) $id, $gen - 1 ) );
   return $n === 1 ? $gen : 0;
}

## The attempt a held or accepted request is about: its newest attempt row.
function dispatch_latest_attempt( $link, $db, $id )
{
   $rows = dispatch_rows( $link, "SELECT * FROM `$db`.dispatch_attempt WHERE dispatchRequestID = ?
      ORDER BY attemptID DESC LIMIT 1", 'i', array( (int) $id ) );
   return $rows ? $rows[ 0 ] : null;
}

/**
 * Record a reconciliation check that settled nothing. The request stays
 * SUBMISSION_UNKNOWN (absence from the queue never proves nonacceptance) and
 * is checked again with backoff. Returns how many checks have been made.
 */
function dispatch_reconcile_unresolved( $link, $db, $id, $generation, $reason )
{
   $checks = dispatch_reconcile_checks( $link, $db, $id ) + 1;
   $delay = min( DISPATCH_RECONCILE_MAX_SECONDS, DISPATCH_RECONCILE_FIRST_SECONDS * ( 1 << min( $checks - 1, 6 ) ) );
   if ( $checks >= DISPATCH_RECONCILE_ALERT_AFTER ) {
      $reason .= '; operator reconciliation required';
   }
   dispatch_tx( $link, function () use ( $link, $db, $id, $generation, $reason, $delay ) {
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET reason = ?, claimOwner = NULL, claimExpiresAt = NULL,
            retryAt = UTC_TIMESTAMP() + INTERVAL ? SECOND, updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND state = 'SUBMISSION_UNKNOWN' AND claimGeneration = ?",
         'siii', array( $reason, $delay, (int) $id, (int) $generation ) );
      return $n === 1 && dispatch_append_event( $link, $db, $id, DISPATCH_SUBMISSION_UNKNOWN, DISPATCH_SUBMISSION_UNKNOWN,
                                                $generation, "reconcile: $reason" );
   } );
   return $checks;
}

function dispatch_reconcile_checks( $link, $db, $id )
{
   $rows = dispatch_rows( $link, "SELECT COUNT(*) n FROM `$db`.dispatch_event WHERE dispatchRequestID = ?
      AND fromState = 'SUBMISSION_UNKNOWN' AND toState = 'SUBMISSION_UNKNOWN'", 'i', array( (int) $id ) );
   return $rows ? (int) $rows[ 0 ][ 'n' ] : 0;
}

/**
 * Operator resolution of an uncertain submission, with evidence: a scheduler
 * job ID records the acceptance; an empty ID records established
 * nonacceptance and returns the request to WAITING for a new attempt.
 */
function dispatch_resolve_unknown( $link, $db, $id, $job_id, $evidence )
{
   $req = dispatch_get( $link, $db, $id );
   $attempt = dispatch_latest_attempt( $link, $db, $id );
   if ( ! $req || $req[ 'state' ] !== DISPATCH_SUBMISSION_UNKNOWN || ! $attempt || trim( (string) $evidence ) === '' ) {
      return false;
   }
   $job_id = trim( (string) $job_id );
   $outcome = $job_id !== ''
            ? array( 'certainty' => SUBMIT_ACCEPTED, 'retryable' => false, 'job_id' => $job_id,
                     'reason' => "operator: $evidence" )
            : array( 'certainty' => SUBMIT_NOT_ACCEPTED, 'retryable' => true, 'job_id' => '',
                     'reason' => "operator: $evidence" );
   return dispatch_record_outcome( $link, $db, $attempt[ 'attemptToken' ], $outcome, DISPATCH_WAITING, 0 );
}

/** ACCEPTED to MONITORED once tracking and the monitor are in place. */
function dispatch_mark_monitored( $link, $db, $id, $reason = 'tracking recorded and monitor attached' )
{
   return dispatch_tx( $link, function () use ( $link, $db, $id, $reason ) {
      $rows = dispatch_rows( $link, "SELECT claimGeneration FROM `$db`.dispatch_request
         WHERE dispatchRequestID = ? AND state = 'ACCEPTED' FOR UPDATE", 'i', array( (int) $id ) );
      if ( ! $rows ) {
         return false;
      }
      $gen = (int) $rows[ 0 ][ 'claimGeneration' ];
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'MONITORED', reason = ?, reasonCode = NULL, claimOwner = NULL,
            claimExpiresAt = NULL, retryAt = NULL, updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND state = 'ACCEPTED'", 'si', array( $reason, (int) $id ) );
      return $n === 1 && dispatch_append_event( $link, $db, $id, DISPATCH_ACCEPTED, DISPATCH_MONITORED, $gen, $reason );
   } );
}

/** Tracking repair failed: keep ACCEPTED and try again later. Never a reason to submit again. */
function dispatch_repair_later( $link, $db, $id, $generation, $reason, $retry_seconds = 300 )
{
   return dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET reason = ?, reasonCode = 'untracked', claimOwner = NULL,
         claimExpiresAt = NULL, retryAt = UTC_TIMESTAMP() + INTERVAL ? SECOND, updatedAt = UTC_TIMESTAMP()
      WHERE dispatchRequestID = ? AND state = 'ACCEPTED' AND claimGeneration = ?",
      'siii', array( $reason, (int) $retry_seconds, (int) $id, (int) $generation ) ) === 1;
}

/**
 * Receipt spool: when the ledger cannot be written after sbatch may have run,
 * the outcome is kept in a restricted local file and replayed once the
 * database is back. Written atomically (temporary file then rename), mode 0600.
 */
function dispatch_spool_write( $dir, $db, $token, array $outcome )
{
   if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
      return false;
   }
   $file = rtrim( $dir, '/' ) . "/$db-$token.json";
   $tmp = "$file.tmp";
   $body = json_encode( array( 'db' => $db, 'token' => $token, 'outcome' => $outcome, 'spooledAt' => gmdate( 'c' ) ) );
   $old = umask( 077 );
   $ok = @file_put_contents( $tmp, $body ) === strlen( $body ) && @rename( $tmp, $file );
   umask( $old );
   return $ok;
}

/**
 * Replay spooled receipts into the ledger. A receipt whose attempt is already
 * settled is removed; one that cannot be recorded yet is kept. Returns the
 * tokens replayed.
 */
function dispatch_spool_replay( $link, $dir )
{
   $done = array();
   foreach ( glob( rtrim( $dir, '/' ) . '/*.json' ) ?: array() as $file ) {
      $r = json_decode( (string) @file_get_contents( $file ), true );
      if ( ! is_array( $r ) || ! dispatch_db_name_ok( $r[ 'db' ] ) || ! isset( $r[ 'token' ], $r[ 'outcome' ] ) ) {
         continue;
      }
      $rows = dispatch_rows( $link, "SELECT phase FROM `{$r['db']}`.dispatch_attempt WHERE attemptToken = ?",
                             's', array( $r[ 'token' ] ) );
      if ( ! $rows ) {
         continue;
      }
      $settled = ! in_array( $rows[ 0 ][ 'phase' ], array( 'INTENT', 'UNKNOWN' ), true );
      $recorded = ! $settled && dispatch_record_outcome( $link, $r[ 'db' ], $r[ 'token' ], $r[ 'outcome' ], DISPATCH_WAITING, 0 );
      if ( $recorded ) {
         ## Recorded here: the worker that spooled it has exited, so its submit claim is released
         ## (and fenced) for tracking to start now, not when the claim would have expired.
         dispatch_affected( $link, "UPDATE `{$r['db']}`.dispatch_request r JOIN `{$r['db']}`.dispatch_attempt a
               USING (dispatchRequestID)
            SET r.claimOwner = NULL, r.claimExpiresAt = NULL, r.claimGeneration = r.claimGeneration + 1,
                r.retryAt = UTC_TIMESTAMP()
            WHERE a.attemptToken = ? AND r.state = 'ACCEPTED'", 's', array( $r[ 'token' ] ) );
      }
      if ( $settled || $recorded ) {
         @unlink( $file );
         $done[] = $r[ 'token' ];
      }
   }
   return $done;
}

/**
 * Fail requests of a batch whose materialization was interrupted before it was
 * sealed and has not resumed within $grace_seconds. Never submitted, so the
 * submitter is told to resubmit. Returns the IDs failed.
 */
function dispatch_abandon_unsealed( $link, $db, $grace_seconds )
{
   $failed = array();
   $reason = 'the submission was interrupted before all of its parts were recorded; please resubmit';
   $rows = dispatch_rows( $link, "SELECT dispatchRequestID, claimGeneration FROM `$db`.dispatch_request
      WHERE batchSealed = 0 AND state = 'PENDING' AND createdAt < UTC_TIMESTAMP() - INTERVAL ? SECOND",
      'i', array( (int) $grace_seconds ) );
   foreach ( $rows as $r ) {
      $id = (int) $r[ 'dispatchRequestID' ];
      $ok = dispatch_tx( $link, function () use ( $link, $db, $id, $r, $reason ) {
         $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = 'FAILED', reason = ?, reasonCode = 'interrupted',
               updatedAt = UTC_TIMESTAMP()
            WHERE dispatchRequestID = ? AND state = 'PENDING' AND batchSealed = 0", 'si', array( $reason, $id ) );
         return $n === 1 && dispatch_append_event( $link, $db, $id, DISPATCH_PENDING, DISPATCH_FAILED,
                                                   (int) $r[ 'claimGeneration' ], $reason );
      } );
      if ( $ok ) {
         $failed[] = $id;
      }
   }
   return $failed;
}

## Seal a batch: every child becomes eligible together.
function dispatch_seal_batch( $link, $db, $batch_key )
{
   return dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET batchSealed = 1, updatedAt = UTC_TIMESTAMP()
      WHERE parentBatchKey = ? AND batchSealed = 0", 's', array( $batch_key ) ) >= 0;
}

/**
 * A user's cancellation. A never-submitted request is cancelled at once and
 * can no longer pass the submission gate. Past the gate the intent is
 * recorded: nonacceptance then settles as CANCELLED, and an accepted job is
 * cancelled through the existing remote path once it is known. Returns
 * 'cancelled', 'requested' or 'final' (nothing left to cancel).
 */
function dispatch_request_cancel( $link, $db, $id, $reason = 'cancelled by the submitter before submission' )
{
   $req = dispatch_get( $link, $db, $id );
   if ( ! $req || dispatch_state_is_terminal( $req[ 'state' ] ) ) {
      return 'final';
   }
   if ( dispatch_cancel_is_local( $req[ 'state' ] ) ) {
      if ( dispatch_cancel_local( $link, $db, $id, $reason ) ) {
         return 'cancelled';
      }
      $req = dispatch_get( $link, $db, $id );
      if ( ! $req || dispatch_state_is_terminal( $req[ 'state' ] ) ) {
         return 'final';
      }
   }
   dispatch_tx( $link, function () use ( $link, $db, $id, $req ) {
      $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET cancelRequested = 1, retryAt = NULL,
            updatedAt = UTC_TIMESTAMP() WHERE dispatchRequestID = ? AND cancelRequested = 0", 'i', array( (int) $id ) );
      return $n >= 0 && ( $n === 0 || dispatch_append_event( $link, $db, $id, $req[ 'state' ], $req[ 'state' ],
                                                            (int) $req[ 'claimGeneration' ], 'cancellation requested' ) );
   } );
   return 'requested';
}

## The scheduler confirmed the cancellation; the monitor finalizes the job.
function dispatch_cancel_delivered( $link, $db, $id, $generation, $job )
{
   return dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET cancelRequested = 2, claimOwner = NULL,
         claimExpiresAt = NULL, reason = ?, updatedAt = UTC_TIMESTAMP()
      WHERE dispatchRequestID = ? AND claimGeneration = ? AND cancelRequested = 1",
      'sii', array( "cancellation of job $job confirmed by the scheduler", (int) $id, (int) $generation ) ) === 1;
}

/**
 * Close monitored requests whose job reached a final result status: CLOSED,
 * or CANCELLED when it ended as aborted or canceled after a confirmed
 * cancellation. Job IDs compare byte for byte, since the instance schema's
 * collation can differ from the dispatch tables'. Returns the IDs closed.
 */
function dispatch_close_finished( $link, $db )
{
   $closed = array();
   $rows = dispatch_rows( $link, "SELECT r.dispatchRequestID, r.claimGeneration, r.cancelRequested, res.queueStatus
      FROM `$db`.dispatch_request r
      JOIN `$db`.dispatch_attempt a ON a.dispatchRequestID = r.dispatchRequestID AND a.phase = 'ACCEPTED'
      JOIN `$db`.HPCAnalysisResult res ON res.HPCAnalysisRequestID = r.HPCAnalysisRequestID
       AND CAST(res.gfacID AS BINARY) = CAST(a.schedulerJobID AS BINARY)
      WHERE r.state = 'MONITORED' AND res.queueStatus IN ('completed','failed','aborted','canceled')" );
   foreach ( $rows as $r ) {
      $id = (int) $r[ 'dispatchRequestID' ];
      $cancelled = (int) $r[ 'cancelRequested' ] === 2 && in_array( $r[ 'queueStatus' ], array( 'aborted', 'canceled' ), true );
      $to = $cancelled ? DISPATCH_CANCELLED : DISPATCH_CLOSED;
      $why = $cancelled ? 'cancelled at the scheduler' : "finished: {$r['queueStatus']}";
      $ok = dispatch_tx( $link, function () use ( $link, $db, $id, $r, $to, $why ) {
         $n = dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET state = ?, reason = ?, updatedAt = UTC_TIMESTAMP()
            WHERE dispatchRequestID = ? AND state = 'MONITORED'", 'ssi', array( $to, $why, $id ) );
         return $n === 1 && dispatch_append_event( $link, $db, $id, DISPATCH_MONITORED, $to, (int) $r[ 'claimGeneration' ], $why );
      } );
      if ( $ok ) {
         $closed[] = $id;
      }
   }
   return $closed;
}

/**
 * Show each autoflow stage's dispatch state in autoflowAnalysis.status, as
 * existing consumers read it: READY while waiting for dispatch, SUBMIT_TIMEOUT
 * when acceptance is uncertain, BLOCKED for operator action, failed for a
 * never-submitted failure or expiry, canceled for a cancellation before
 * acceptance, each with the user message. Accepted stages are left to the
 * existing submission and monitor path, and an existing failure report is
 * kept. Returns the rows changed.
 */
function dispatch_mirror_autoflow( $link, $db )
{
   include_once __DIR__ . '/dispatch_messages.php';
   $exists = dispatch_rows( $link, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'autoflowAnalysis'",
                            's', array( $db ) );
   if ( ! $exists ) {
      return 0;
   }
   $statuses = array( DISPATCH_SUBMISSION_UNKNOWN => 'SUBMIT_TIMEOUT', DISPATCH_BLOCKED => 'BLOCKED',
                      DISPATCH_EXPIRED => 'failed', DISPATCH_FAILED => 'failed', DISPATCH_CANCELLED => 'canceled' );
   $rows = dispatch_rows( $link, "SELECT a.requestID, a.status, a.statusMsg, r.state, r.reasonCode, r.selectedCluster,
         r.retryAt, r.notifiedAt, r.cancelRequested
      FROM `$db`.autoflowAnalysis a
      JOIN `$db`.dispatch_request r ON r.autoflowAnalysisID = a.requestID AND r.HPCAnalysisRequestID = a.currentHPCARID
      WHERE r.state IN ('PENDING','ASSESSING','WAITING','STAGING','SUBMITTING','SUBMISSION_UNKNOWN','BLOCKED',
                        'EXPIRED','FAILED','CANCELLED')
        AND (a.currentGfacID IS NULL OR a.currentGfacID = '')
        AND LOWER(a.status) NOT IN ('failed','error','canceled','complete','done','finished')" );
   $changed = 0;
   foreach ( $rows as $r ) {
      $status = isset( $statuses[ $r[ 'state' ] ] ) ? $statuses[ $r[ 'state' ] ] : 'READY';
      $msg = dispatch_public_message( $r );
      if ( $status === $r[ 'status' ] && $msg === $r[ 'statusMsg' ] ) {
         continue;
      }
      $changed += max( 0, dispatch_affected( $link, "UPDATE `$db`.autoflowAnalysis SET status = ?, statusMsg = ?
         WHERE requestID = ? AND (currentGfacID IS NULL OR currentGfacID = '')", 'ssi', array( $status, $msg, (int) $r[ 'requestID' ] ) ) );
   }
   return $changed;
}
