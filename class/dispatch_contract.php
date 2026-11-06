<?php
/* Dispatch states, allowed transitions and submission outcomes. Pure; PHP 7.2 compatible. */

const DISPATCH_PENDING            = 'PENDING';
const DISPATCH_ASSESSING          = 'ASSESSING';
const DISPATCH_WAITING            = 'WAITING';
const DISPATCH_BLOCKED            = 'BLOCKED';
const DISPATCH_STAGING            = 'STAGING';
const DISPATCH_SUBMITTING         = 'SUBMITTING';
const DISPATCH_SUBMISSION_UNKNOWN = 'SUBMISSION_UNKNOWN';
const DISPATCH_ACCEPTED           = 'ACCEPTED';
const DISPATCH_MONITORED          = 'MONITORED';
const DISPATCH_CLOSED             = 'CLOSED';
const DISPATCH_FAILED             = 'FAILED';
const DISPATCH_EXPIRED            = 'EXPIRED';
const DISPATCH_CANCELLED          = 'CANCELLED';

## Submission certainty, from the scheduler's point of view.
const SUBMIT_ACCEPTED     = 'ACCEPTED';
const SUBMIT_NOT_ACCEPTED = 'NOT_ACCEPTED';
const SUBMIT_UNKNOWN      = 'UNKNOWN';

## Assessment verdicts for one candidate plan.
const ASSESS_READY    = 'READY';
const ASSESS_DELAYED  = 'DELAYED';
const ASSESS_REJECTED = 'REJECTED';
const ASSESS_UNKNOWN  = 'UNKNOWN';

/** Every dispatch state, in lifecycle order. */
function dispatch_states()
{
   return array( DISPATCH_PENDING, DISPATCH_ASSESSING, DISPATCH_WAITING, DISPATCH_BLOCKED,
                 DISPATCH_STAGING, DISPATCH_SUBMITTING, DISPATCH_SUBMISSION_UNKNOWN,
                 DISPATCH_ACCEPTED, DISPATCH_MONITORED, DISPATCH_CLOSED, DISPATCH_FAILED,
                 DISPATCH_EXPIRED, DISPATCH_CANCELLED );
}

function dispatch_state_is_terminal( $state )
{
   return in_array( $state, array( DISPATCH_CLOSED, DISPATCH_FAILED, DISPATCH_EXPIRED,
                                   DISPATCH_CANCELLED ), true );
}

/** True when sbatch may have run, so the request must never be resubmitted blindly. */
function dispatch_state_may_be_submitted( $state )
{
   return in_array( $state, array( DISPATCH_SUBMITTING, DISPATCH_SUBMISSION_UNKNOWN,
                                   DISPATCH_ACCEPTED, DISPATCH_MONITORED, DISPATCH_CLOSED ), true );
}

/** The design's transition table: from-state => allowed to-states. */
function dispatch_transition_table()
{
   return array(
      DISPATCH_PENDING   => array( DISPATCH_ASSESSING, DISPATCH_FAILED, DISPATCH_EXPIRED, DISPATCH_CANCELLED ),
      DISPATCH_WAITING   => array( DISPATCH_ASSESSING, DISPATCH_FAILED, DISPATCH_EXPIRED, DISPATCH_CANCELLED ),
      DISPATCH_ASSESSING => array( DISPATCH_WAITING, DISPATCH_BLOCKED, DISPATCH_STAGING, DISPATCH_FAILED,
                                   DISPATCH_EXPIRED, DISPATCH_CANCELLED ),
      ## Leaves only by operator action, pool deactivation or cancellation; never expires.
      DISPATCH_BLOCKED   => array( DISPATCH_WAITING, DISPATCH_FAILED, DISPATCH_CANCELLED ),
      ## Back to ASSESSING is the bounded reselection after staging.
      DISPATCH_STAGING   => array( DISPATCH_SUBMITTING, DISPATCH_ASSESSING, DISPATCH_WAITING,
                                   DISPATCH_FAILED, DISPATCH_EXPIRED, DISPATCH_CANCELLED ),
      ## Past the submission gate: no expiry, no direct cancellation, no deactivation failure.
      DISPATCH_SUBMITTING => array( DISPATCH_ACCEPTED, DISPATCH_SUBMISSION_UNKNOWN, DISPATCH_ASSESSING,
                                    DISPATCH_WAITING, DISPATCH_BLOCKED, DISPATCH_FAILED ),
      DISPATCH_SUBMISSION_UNKNOWN => array( DISPATCH_ACCEPTED, DISPATCH_WAITING, DISPATCH_BLOCKED,
                                            DISPATCH_FAILED ),
      DISPATCH_ACCEPTED  => array( DISPATCH_MONITORED, DISPATCH_CANCELLED ),
      DISPATCH_MONITORED => array( DISPATCH_CLOSED, DISPATCH_CANCELLED ),
      DISPATCH_CLOSED    => array(),
      DISPATCH_FAILED    => array(),
      DISPATCH_EXPIRED   => array(),
      DISPATCH_CANCELLED => array(),
   );
}

function dispatch_transition_allowed( $from, $to )
{
   $table = dispatch_transition_table();
   return isset( $table[ $from ] ) && in_array( $to, $table[ $from ], true );
}

/** States a pool deactivation fails outright; STAGING finishes at its frozen destination. */
function dispatch_deactivation_fails( $state )
{
   return in_array( $state, array( DISPATCH_PENDING, DISPATCH_ASSESSING, DISPATCH_WAITING,
                                   DISPATCH_BLOCKED ), true );
}

/** States the dispatch deadline may expire. */
function dispatch_deadline_may_expire( $state )
{
   return in_array( $state, array( DISPATCH_PENDING, DISPATCH_ASSESSING, DISPATCH_WAITING,
                                   DISPATCH_STAGING ), true );
}

/** States a user cancellation settles without touching the scheduler. */
function dispatch_cancel_is_local( $state )
{
   return in_array( $state, array( DISPATCH_PENDING, DISPATCH_ASSESSING, DISPATCH_WAITING,
                                   DISPATCH_STAGING, DISPATCH_BLOCKED ), true );
}

/**
 * Map one submit_slurm::sbatchOnce() result onto a submission outcome:
 * array( 'certainty' => SUBMIT_*, 'retryable' => bool, 'reason' => string, 'job_id' => string ).
 * Anything not positively classified as never submitted is UNKNOWN.
 */
function dispatch_outcome_from_sbatch_result( $res )
{
   $job_id = isset( $res[ 'job_id' ] ) ? (string) $res[ 'job_id' ] : '';

   if ( ! empty( $res[ 'ok' ] ) && $job_id !== '' ) {
      return array( 'certainty' => SUBMIT_ACCEPTED, 'retryable' => false, 'reason' => 'accepted',
                    'job_id' => $job_id );
   }

   $not_accepted = array( 'certainty' => SUBMIT_NOT_ACCEPTED, 'job_id' => '' );

   if ( ! empty( $res[ 'breaker_open' ] ) ) {
      return $not_accepted + array( 'retryable' => true, 'reason' => 'breaker_open' );
   }
   $unreachable = isset( $res[ 'class' ] ) && $res[ 'class' ] === 'UNREACHABLE';
   if ( $unreachable && empty( $res[ 'began' ] ) ) {
      return $not_accepted + array( 'retryable' => true, 'reason' => 'never_started' );
   }
   if ( ! empty( $res[ 'connect_failure' ] ) ) {
      return $not_accepted + array( 'retryable' => true, 'reason' => 'connect_failure' );
   }
   if ( ! empty( $res[ 'definite_rejection' ] ) ) {
      return $not_accepted + array( 'retryable' => false, 'reason' => 'definite_rejection' );
   }
   if ( ! empty( $res[ 'busy' ] ) ) {
      return $not_accepted + array( 'retryable' => true, 'reason' => 'busy' );
   }

   ## Includes a zero exit with unparsable output: the job may exist.
   return array( 'certainty' => SUBMIT_UNKNOWN, 'retryable' => false, 'reason' => 'result_lost',
                 'job_id' => '' );
}

/** Missing required keys in a contract array; empty when complete. */
function dispatch_contract_missing( $kind, $data )
{
   $required = array(
      'request'     => array( 'instance', 'request_id', 'stage', 'stage_occurrence', 'submitter_id',
                              'investigator_id', 'pool', 'input_checksum' ),
      'plan'        => array( 'profile', 'cluster', 'method', 'groups', 'ranks', 'cpus_per_task',
                              'tasks_per_node', 'nodes', 'memory_basis', 'walltime_minutes',
                              'partition', 'exclusive' ),
      'observation' => array( 'cluster', 'verdict', 'observed_at', 'reason' ),
      'decision'    => array( 'pool', 'policy_version', 'strategy', 'admission', 'candidates',
                              'chosen', 'reason' ),
      'outcome'     => array( 'certainty', 'retryable', 'reason', 'job_id' ),
   );

   if ( ! isset( $required[ $kind ] ) ) {
      return array( "unknown contract '$kind'" );
   }

   $missing = array();
   foreach ( $required[ $kind ] as $key ) {
      if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
         $missing[] = $key;
      }
   }
   return $missing;
}

/** Whether an observation's verdict is one of the four assessment verdicts. */
function dispatch_verdict_is_valid( $verdict )
{
   return in_array( $verdict, array( ASSESS_READY, ASSESS_DELAYED, ASSESS_REJECTED, ASSESS_UNKNOWN ), true );
}
