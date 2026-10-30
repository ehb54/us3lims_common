<?php
/* Slurm assessment adapter: builds read-only queries from a canonical plan, parses
 * their output into observations, and runs them through remote_exec. PHP 7.2 compatible.
 * Missing or unusable data is always UNKNOWN, never free capacity. */

include_once __DIR__ . '/dispatch_contract.php';

## sbatch messages that reject the request itself; sending it elsewhere unchanged would fail too.
const SLURM_REQUEST_REJECTIONS = '/Requested node configuration is not available|Invalid partition'
   . '|invalid account|Invalid qos|violates accounting\/QOS policy|More processors requested than permitted'
   . '|Requested time limit is invalid|Invalid generic resource|Memory required by task is not available'
   . '|Node count specification invalid|Requested partition configuration not available/i';

## The test-only command for a plan. The script reaches sbatch on stdin, so no
## dataset or file is staged; output paths are dropped because nothing runs.
function slurm_assessment_command( array $plan )
{
   $lines = array( '#!/bin/bash' );
   foreach ( submit_slurm_plan_directives_for_assessment( $plan ) as $line ) {
      $lines[] = $line;
   }
   $lines[] = 'true';
   $script = implode( "\n", $lines );

   return "cd /tmp && out=\$(sbatch --test-only <<'US3_ASSESS_EOF' 2>&1\n$script\nUS3_ASSESS_EOF\n); rc=\$?\n"
        . "printf '%s\\n' \"\$out\"; echo \"__rc=\$rc\"; echo \"__now=\$(date +%s)\"\n"
        . "start=\$(printf '%s\\n' \"\$out\" | sed -n 's/.* to start at \\([0-9T:-]*\\) .*/\\1/p' | head -1)\n"
        . "[ -n \"\$start\" ] && echo \"__start=\$(date -d \"\$start\" +%s)\"\n"
        . "true";
}

## The plan's directives, as real submission renders them, minus output paths.
function submit_slurm_plan_directives_for_assessment( array $plan )
{
   $lines = class_exists( 'submit_slurm' )
          ? submit_slurm::plan_directives( $plan, 'assess', '/tmp' )
          : slurm_assessment_directives_fallback( $plan );
   return array_values( array_filter( $lines, function ( $l ) {
      return ! preg_match( '/^#SBATCH -[eo] /', $l );
   } ) );
}

## Same lines as submit_slurm::plan_directives(), for callers that do not load submit_slurm.
function slurm_assessment_directives_fallback( array $plan )
{
   $lines = array(
      "#SBATCH -p {$plan['partition']}",
      "#SBATCH -J US3_Job_assess",
      "#SBATCH -N {$plan['node_count']}",
      "#SBATCH -n {$plan['total_tasks']}",
      "#SBATCH --ntasks-per-node={$plan['tasks_per_node']}",
      "#SBATCH -t {$plan['walltime']}",
   );
   foreach ( $plan[ 'site_directives' ] as $d ) {
      $lines[] = $d;
   }
   if ( $plan[ 'priority_directive' ] !== '' ) {
      $lines[] = $plan[ 'priority_directive' ];
   }
   return $lines;
}

## Marker values ("__now=123") from a framed remote_exec result.
function slurm_assessment_markers( array $result )
{
   $markers = array();
   foreach ( isset( $result[ 'stdout' ] ) ? (array) $result[ 'stdout' ] : array() as $line ) {
      if ( preg_match( '/^__([a-z]+)=(-?\d+)$/', trim( $line ), $m ) ) {
         $markers[ $m[ 1 ] ] = (int) $m[ 2 ];
      }
   }
   return $markers;
}

function slurm_assessment_text( array $result )
{
   $text = array();
   foreach ( isset( $result[ 'stdout' ] ) ? (array) $result[ 'stdout' ] : array() as $line ) {
      if ( strpos( trim( $line ), '__' ) !== 0 ) {
         $text[] = $line;
      }
   }
   return trim( implode( "\n", $text ) . "\n" . ( isset( $result[ 'stderr' ] ) ? $result[ 'stderr' ] : '' ) );
}

/**
 * Normalize one test-only result into an observation:
 * cluster, verdict (ASSESS_*), observed_at (controller epoch), start_at,
 * wait_seconds, reason. $allowance is the waiting allowance in seconds;
 * $tolerance absorbs timestamp rounding, not extra waiting.
 */
function slurm_assessment_parse( $cluster, array $result, $allowance, $tolerance = 30 )
{
   $obs = array( 'cluster' => $cluster, 'verdict' => ASSESS_UNKNOWN, 'observed_at' => null,
                 'start_at' => null, 'wait_seconds' => null, 'reason' => '' );

   $class = isset( $result[ 'class' ] ) ? $result[ 'class' ] : '';
   if ( empty( $result[ 'began' ] ) || ( $class !== 'OK' && $class !== 'REMOTE_FAIL' ) ) {
      $obs[ 'reason' ] = 'controller not reached (' . ( $class !== '' ? $class : 'no result' ) . ')';
      return $obs;
   }

   $markers = slurm_assessment_markers( $result );
   if ( ! isset( $markers[ 'now' ], $markers[ 'rc' ] ) ) {
      $obs[ 'reason' ] = 'malformed assessment reply';
      return $obs;
   }
   $obs[ 'observed_at' ] = $markers[ 'now' ];
   $text = slurm_assessment_text( $result );

   if ( $markers[ 'rc' ] !== 0 ) {
      if ( preg_match( '/unrecognized option|invalid option|unknown option/i', $text ) ) {
         $obs[ 'reason' ] = 'sbatch --test-only is not supported here';
      }
      elseif ( preg_match( SLURM_REQUEST_REJECTIONS, $text, $m ) ) {
         $obs[ 'verdict' ] = ASSESS_REJECTED;
         $obs[ 'reason' ] = $m[ 0 ];
      }
      else {
         $obs[ 'reason' ] = 'unclassified sbatch failure: ' . substr( preg_replace( '/\s+/', ' ', $text ), 0, 160 );
      }
      return $obs;
   }

   if ( ! isset( $markers[ 'start' ] ) ) {
      $obs[ 'reason' ] = 'no usable start estimate';
      return $obs;
   }

   $wait = max( 0, $markers[ 'start' ] - $markers[ 'now' ] );
   $obs[ 'start_at' ] = $markers[ 'start' ];
   $obs[ 'wait_seconds' ] = $wait;
   $obs[ 'verdict' ] = $wait <= (int) $allowance + (int) $tolerance ? ASSESS_READY : ASSESS_DELAYED;
   $obs[ 'reason' ] = $obs[ 'verdict' ] === ASSESS_READY ? 'start estimate within allowance'
                    : "start estimate {$wait}s away";
   return $obs;
}

function slurm_node_snapshot_command( $partition )
{
   return 'sinfo -h -N -p ' . escapeshellarg( $partition ) . " -o '%N|%T|%C|%m|%e'; echo \"__rc=\$?\"; "
        . 'echo "__now=$(date +%s)"';
}

## States in which a node can start new work.
function slurm_node_state_usable( $state )
{
   $base = strtolower( rtrim( $state, '*~#!%$@^-+' ) );
   return in_array( $base, array( 'idle', 'mixed', 'allocated', 'completing' ), true );
}

/**
 * Per-node snapshot: node => state, usable, cpus_alloc/idle/other/total,
 * memory_mb (configured, schedulable) and os_free_mb (informational only).
 * Reserved, drained and down nodes are unusable regardless of idle CPUs.
 */
function slurm_node_snapshot_parse( $cluster, array $result )
{
   $snap = array( 'cluster' => $cluster, 'ok' => false, 'observed_at' => null, 'nodes' => array(), 'reason' => '' );
   if ( empty( $result[ 'began' ] ) || ( isset( $result[ 'class' ] ) && $result[ 'class' ] !== 'OK' ) ) {
      $snap[ 'reason' ] = 'controller not reached';
      return $snap;
   }
   $markers = slurm_assessment_markers( $result );
   if ( ! isset( $markers[ 'now' ] ) || ( isset( $markers[ 'rc' ] ) && $markers[ 'rc' ] !== 0 ) ) {
      $snap[ 'reason' ] = 'malformed or failed sinfo reply';
      return $snap;
   }
   foreach ( (array) $result[ 'stdout' ] as $line ) {
      $f = explode( '|', trim( $line ) );
      if ( count( $f ) !== 5 || ! preg_match( '#^(\d+)/(\d+)/(\d+)/(\d+)$#', $f[ 2 ], $c ) ) {
         continue;
      }
      $snap[ 'nodes' ][ $f[ 0 ] ] = array(
         'state' => $f[ 1 ], 'usable' => slurm_node_state_usable( $f[ 1 ] ),
         'cpus_alloc' => (int) $c[ 1 ], 'cpus_idle' => (int) $c[ 2 ], 'cpus_other' => (int) $c[ 3 ],
         'cpus_total' => (int) $c[ 4 ],
         'memory_mb' => is_numeric( $f[ 3 ] ) ? (int) $f[ 3 ] : null,
         'os_free_mb' => is_numeric( $f[ 4 ] ) ? (int) $f[ 4 ] : null,
      );
   }
   $snap[ 'observed_at' ] = $markers[ 'now' ];
   $snap[ 'ok' ] = true;
   return $snap;
}

function slurm_pending_command( $partition )
{
   return 'squeue -h -p ' . escapeshellarg( $partition ) . " -t PD -o '%i|%D|%C|%l|%r|%Q'; echo \"__rc=\$?\"";
}

## Pending jobs in the partition: diagnostic context only, never a readiness veto.
function slurm_pending_parse( array $result )
{
   $jobs = array();
   foreach ( isset( $result[ 'stdout' ] ) ? (array) $result[ 'stdout' ] : array() as $line ) {
      $f = explode( '|', trim( $line ) );
      if ( count( $f ) === 6 && ctype_digit( $f[ 0 ] ) ) {
         $jobs[] = array( 'job' => $f[ 0 ], 'nodes' => (int) $f[ 1 ], 'cpus' => (int) $f[ 2 ], 'limit' => $f[ 3 ],
                          'reason' => $f[ 4 ], 'priority' => $f[ 5 ] );
      }
   }
   return $jobs;
}

/**
 * Node-based readiness for a profile whose site validated it
 * ('assessment_fallback' => 'node_snapshot'). READY only when enough usable
 * nodes each have the plan's tasks per node idle, and the configured memory
 * holds a per-node memory request; otherwise DELAYED, or UNKNOWN without data.
 */
function slurm_node_fallback_verdict( array $cfg, array $snapshot, array $plan )
{
   if ( ( isset( $cfg[ 'assessment_fallback' ] ) ? $cfg[ 'assessment_fallback' ] : '' ) !== 'node_snapshot' ) {
      return array( 'verdict' => ASSESS_UNKNOWN, 'reason' => 'node-based readiness is not validated for this profile' );
   }
   if ( empty( $snapshot[ 'ok' ] ) ) {
      return array( 'verdict' => ASSESS_UNKNOWN, 'reason' => 'no node snapshot' );
   }
   $need_mb = $plan[ 'memory_basis' ] === 'per_node' && ctype_digit( (string) $plan[ 'memory_value' ] )
            ? (int) $plan[ 'memory_value' ] : 0;
   $fit = 0;
   foreach ( $snapshot[ 'nodes' ] as $node ) {
      if ( $node[ 'usable' ] && $node[ 'cpus_idle' ] >= $plan[ 'tasks_per_node' ]
           && ( $need_mb === 0 || ( $node[ 'memory_mb' ] !== null && $node[ 'memory_mb' ] >= $need_mb ) ) ) {
         $fit++;
      }
   }
   return $fit >= $plan[ 'node_count' ]
        ? array( 'verdict' => ASSESS_READY, 'reason' => "$fit usable nodes have {$plan['tasks_per_node']} idle CPUs" )
        : array( 'verdict' => ASSESS_DELAYED, 'reason' => "only $fit of {$plan['node_count']} needed nodes are free" );
}

## Queue admission may submit on any verdict except a definite rejection.
function slurm_verdict_allows_queue( $verdict )
{
   return $verdict !== ASSESS_REJECTED;
}

## Whether an observation may still be used for an availability-based choice.
function slurm_observation_is_fresh( array $obs, $now, $max_age )
{
   return isset( $obs[ 'observed_at' ] ) && $obs[ 'observed_at' ] !== null
          && $now - $obs[ 'observed_at' ] <= $max_age && $now >= $obs[ 'observed_at' ] - 5;
}

/**
 * Assess candidate plans within one bounded cycle. $run is
 * function( $cluster, $command, array $opts ) returning a remote_exec result,
 * normally a remote_exec instance's run(). Identical requests to one controller
 * in this cycle share one query. Candidates left when the cycle budget is spent
 * are UNKNOWN. $clock returns the local time, for the budget only.
 */
function slurm_assess_candidates( callable $run, array $plans, array $opts = array(), callable $clock = null )
{
   $clock     = $clock !== null ? $clock : 'time';
   $allowance = isset( $opts[ 'allowance' ] ) ? (int) $opts[ 'allowance' ] : 0;
   $per_call  = isset( $opts[ 'controller_budget' ] ) ? (int) $opts[ 'controller_budget' ] : 10;
   $cycle     = isset( $opts[ 'cycle_budget' ] ) ? (int) $opts[ 'cycle_budget' ] : 30;
   $deadline  = $clock() + $cycle;

   $memo = array();
   $observations = array();
   foreach ( $plans as $key => $plan ) {
      $cluster = $plan[ 'cluster' ];
      if ( empty( $plan[ 'ok' ] ) ) {
         $observations[ $key ] = array( 'cluster' => $cluster, 'verdict' => ASSESS_REJECTED, 'observed_at' => null,
                                        'start_at' => null, 'wait_seconds' => null,
                                        'reason' => isset( $plan[ 'error' ] ) ? $plan[ 'error' ] : 'no valid plan' );
         continue;
      }
      $remaining = $deadline - $clock();
      if ( $remaining < 1 ) {
         $observations[ $key ] = array( 'cluster' => $cluster, 'verdict' => ASSESS_UNKNOWN, 'observed_at' => null,
                                        'start_at' => null, 'wait_seconds' => null,
                                        'reason' => 'assessment cycle budget exhausted' );
         continue;
      }
      $command = slurm_assessment_command( $plan );
      $memo_key = $cluster . "\0" . hash( 'sha256', $command );
      if ( ! isset( $memo[ $memo_key ] ) ) {
         $memo[ $memo_key ] = $run( $cluster, $command,
            array( 'timeout' => min( $per_call, $remaining ), 'retries' => 0, 'label' => "assess $cluster" ) );
      }
      $observations[ $key ] = slurm_assessment_parse( $cluster, $memo[ $memo_key ], $allowance );
   }
   return $observations;
}
