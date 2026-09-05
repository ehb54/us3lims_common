<?php
/*
 * circuit_breaker.php
 *
 * Shared, cross-process memory of which clusters are currently not answering.
 *
 * WHY IT EXISTS
 *
 * remote_exec retries transport faults with backoff, which is correct for one
 * call in isolation and wrong in aggregate. Every job on a cluster is watched
 * by its own jobmonitor.php process, and the gridctl.php cron sweep walks all
 * of them once a minute. During an outage each of those independently
 * discovers the same fact -- the login node is not answering -- by making its
 * own 4 attempts against it. Two hundred queued jobs means eight hundred
 * connection attempts against a site that is already struggling, which is more
 * load than the unretried code produced, not less.
 *
 * Backoff cannot fix that, because the processes share no state and so cannot
 * coordinate. This class is that shared state: the first few processes to
 * discover the outage record it, and everyone else fails fast without touching
 * the network until the cooldown expires.
 *
 * WHY THE FILESYSTEM AND NOT THE DATABASE
 *
 * gfac.cluster_status already holds a health verdict, but reaching it needs a
 * live DB handle, and remote_exec is called from contexts that hold different
 * handles to different databases (the web tier, the cron sweep, per-job
 * daemons). A small state file per cluster needs no handle, no schema, and no
 * migration. Every process that calls a cluster runs on the LIMS host as the
 * us3 user, so a directory they all share is sufficient.
 *
 * STATES
 *
 *   closed     normal. Calls go through. Consecutive transport faults are counted.
 *   open       $threshold consecutive faults reached. Calls fail fast for
 *              $cooldown seconds without touching the network.
 *   half-open  the cooldown has expired. The next call is allowed through as a
 *              probe. If it succeeds the breaker closes and the count resets;
 *              if it fails the count is still at the threshold, so it reopens
 *              immediately for another cooldown.
 *
 * The half-open state is implicit rather than a stored value: once open_until
 * has passed, is_open() returns false and the next caller is the probe. That
 * keeps the file format to three fields and makes a torn write harmless.
 */

class circuit_breaker
{
   private $dir;
   private $threshold;
   private $cooldown;
   private $log;
   private $usable = null;

   /**
    * $dir       directory for state files. Created if absent.
    * $threshold consecutive transport faults before the breaker opens.
    * $cooldown  seconds to stay open before allowing a probe through.
    */
   public function __construct( $dir, $threshold = 3, $cooldown = 120, $log = null )
   {
      $this->dir       = rtrim( (string) $dir, '/' );
      $this->threshold = max( 1, (int) $threshold );
      $this->cooldown  = max( 1, (int) $cooldown );
      $this->log       = is_callable( $log ) ? $log : function ( $m ) { error_log( "circuit_breaker: $m" ); };
   }

   /**
    * Should this call be refused without touching the network?
    *
    * Deliberately fail-safe rather than fail-fast on its own errors: if the
    * state directory is unusable or the file is unreadable, the answer is
    * "no, go ahead and try". A broken breaker must degrade to the behaviour we
    * had before it existed, never to refusing every call.
    */
   public function is_open( $cluster )
   {
      $state = $this->read( $cluster );

      if ( $state === null )
         return false;

      return $state[ 'open_until' ] > time();
   }

   ## Seconds remaining before a probe is allowed through. 0 when closed.
   public function seconds_remaining( $cluster )
   {
      $state = $this->read( $cluster );

      if ( $state === null )
         return 0;

      return max( 0, $state[ 'open_until' ] - time() );
   }

   /**
    * A call reached the cluster and got an answer -- OK or a genuine remote
    * failure, both of which prove the transport works. Reset everything.
    */
   public function record_success( $cluster )
   {
      $state = $this->read( $cluster );

      ## Avoid a write on the overwhelmingly common path where nothing is wrong.
      if ( $state === null || ( $state[ 'failures' ] === 0 && $state[ 'open_until' ] === 0 ) )
         return;

      $this->log( "$cluster answering again; breaker closed" );

      $this->write( $cluster, array( 'failures' => 0, 'open_until' => 0, 'updated' => time() ) );
   }

   /**
    * A call failed at the transport layer. Count it, and open the breaker once
    * the threshold is reached.
    */
   public function record_failure( $cluster )
   {
      $state = $this->read( $cluster );

      if ( $state === null )
         $state = array( 'failures' => 0, 'open_until' => 0, 'updated' => 0 );

      $failures = $state[ 'failures' ] + 1;

      if ( $failures >= $this->threshold )
      {
         $open_until = time() + $this->cooldown;

         ## Only announce the transition, not every failure while already open.
         if ( $state[ 'open_until' ] <= time() )
            $this->log( "$cluster unreachable $failures time(s) in a row;"
                        . " breaker open for {$this->cooldown}s -- further calls will fail fast" );

         $this->write( $cluster, array( 'failures' => $failures, 'open_until' => $open_until, 'updated' => time() ) );
         return;
      }

      $this->write( $cluster, array( 'failures' => $failures, 'open_until' => 0, 'updated' => time() ) );
   }

   ## Human-readable state, for the health probe and logs.
   public function describe( $cluster )
   {
      $state = $this->read( $cluster );

      if ( $state === null )
         return 'closed';

      if ( $state[ 'open_until' ] > time() )
         return 'open (' . ( $state[ 'open_until' ] - time() ) . 's remaining, '
                . $state[ 'failures' ] . ' consecutive failures)';

      if ( $state[ 'failures' ] >= $this->threshold )
         return 'half-open (next call is a probe)';

      if ( $state[ 'failures' ] > 0 )
         return 'closed (' . $state[ 'failures' ] . ' consecutive failures)';

      return 'closed';
   }

   ## ---------------------------------------------------------------- internals

   private function path( $cluster )
   {
      ## Cluster short names come from config, but they end up in a filename,
      ## so anything outside a conservative set is folded away rather than
      ## trusted.
      $safe = preg_replace( '/[^A-Za-z0-9._-]/', '_', (string) $cluster );

      return $this->dir . '/' . $safe . '.brk';
   }

   private function usable()
   {
      if ( $this->usable !== null )
         return $this->usable;

      if ( $this->dir === '' )
         return $this->usable = false;

      if ( ! is_dir( $this->dir ) )
         @mkdir( $this->dir, 0770, true );

      return $this->usable = ( is_dir( $this->dir ) && is_writable( $this->dir ) );
   }

   /** Returns null when there is no usable state, which callers read as "closed". */
   private function read( $cluster )
   {
      if ( ! $this->usable() )
         return null;

      $raw = @file_get_contents( $this->path( $cluster ) );

      if ( $raw === false || $raw === '' )
         return null;

      $state = json_decode( $raw, true );

      if ( ! is_array( $state ) || ! isset( $state[ 'failures' ], $state[ 'open_until' ] ) )
         return null;   ## torn or corrupt write: treat as closed and let it be overwritten

      return array(
         'failures'   => (int) $state[ 'failures' ],
         'open_until' => (int) $state[ 'open_until' ],
         'updated'    => (int) ( $state[ 'updated' ] ?? 0 ),
      );
   }

   /**
    * Write via a temp file and rename, so a concurrent reader never sees a
    * half-written file. Several processes racing here is expected and benign:
    * they are all recording the same outage, and the worst case is a failure
    * count that is one low.
    */
   private function write( $cluster, $state )
   {
      if ( ! $this->usable() )
         return;

      $path = $this->path( $cluster );
      $tmp  = $path . '.' . getmypid() . '.tmp';

      if ( @file_put_contents( $tmp, json_encode( $state ) ) === false )
         return;

      if ( ! @rename( $tmp, $path ) )
         @unlink( $tmp );
   }

   private function log( $msg )
   {
      call_user_func( $this->log, $msg );
   }
}
