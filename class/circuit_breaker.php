<?php
/*
 * Share per-cluster transport failure counts across processes.
 * Calls fail fast after the threshold until the cooldown expires.
 * State files require a directory writable by all callers.
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

   /** Fail open when the state directory or file is unavailable. */
   public function is_open( $cluster )
   {
      $state = $this->read( $cluster );

      if ( $state === null )
      {
         return false;
      }

      return $state[ 'open_until' ] > time();
   }

   ## Seconds remaining before a probe is allowed through. 0 when closed.
   public function seconds_remaining( $cluster )
   {
      $state = $this->read( $cluster );

      if ( $state === null )
      {
         return 0;
      }

      return max( 0, $state[ 'open_until' ] - time() );
   }

   /** Reset after any remote answer, including a command failure. */
   public function record_success( $cluster )
   {
      $state = $this->read( $cluster );

      ## Avoid a write on the overwhelmingly common path where nothing is wrong.
      if ( $state === null || ( $state[ 'failures' ] === 0 && $state[ 'open_until' ] === 0 ) )
      {
         return;
      }

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
      {
         $state = array( 'failures' => 0, 'open_until' => 0, 'updated' => 0 );
      }

      $failures = $state[ 'failures' ] + 1;

      if ( $failures >= $this->threshold )
      {
         $open_until = time() + $this->cooldown;

         ## Only announce the transition, not every failure while already open.
         if ( $state[ 'open_until' ] <= time() )
         {
            $this->log( "$cluster unreachable $failures time(s) in a row;"
                        . " breaker open for {$this->cooldown}s -- further calls will fail fast" );
         }

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
      {
         return 'closed';
      }

      $description = 'closed';
      if ( $state[ 'open_until' ] > time() )
      {
         $description = 'open (' . ( $state[ 'open_until' ] - time() ) . 's remaining, '
                        . $state[ 'failures' ] . ' consecutive failures)';
      }
      elseif ( $state[ 'failures' ] >= $this->threshold )
      {
         $description = 'half-open (next call is a probe)';
      }
      elseif ( $state[ 'failures' ] > 0 )
      {
         $description = 'closed (' . $state[ 'failures' ] . ' consecutive failures)';
      }

      return $description;
   }

   ## ---------------------------------------------------------------- internals

   private function path( $cluster )
   {
      ## Restrict cluster names to filename-safe characters.
      $safe = preg_replace( '/[^A-Za-z0-9._-]/', '_', (string) $cluster );

      return $this->dir . '/' . $safe . '.brk';
   }

   private function usable()
   {
      if ( $this->usable !== null )
      {
         return $this->usable;
      }

      if ( $this->dir === '' )
      {
         return $this->usable = false;
      }

      if ( ! is_dir( $this->dir ) )
      {
         @mkdir( $this->dir, 0770, true );
      }

      ## Shared state that decides whether a cluster is contacted: refuse a
      ## symlinked directory or one owned by anyone but us3 or root.
      $us3   = function_exists( 'posix_getpwnam' ) ? posix_getpwnam( 'us3' ) : false;
      $owner = @fileowner( $this->dir );
      if ( is_link( $this->dir ) || ( $us3 && $owner !== $us3[ 'uid' ] && $owner !== 0 ) )
      {
         $this->log( "state directory {$this->dir} is a symlink or not owned by us3; breaker disabled" );
         return $this->usable = false;
      }

      return $this->usable = ( is_dir( $this->dir ) && is_writable( $this->dir ) );
   }

   /** Returns null when there is no usable state, which callers read as "closed". */
   private function read( $cluster )
   {
      if ( ! $this->usable() )
      {
         return null;
      }

      if ( is_link( $this->path( $cluster ) ) )
      {
         return null;
      }

      $raw = @file_get_contents( $this->path( $cluster ) );

      $state = $raw === false || $raw === '' ? null : json_decode( $raw, true );

      if ( ! is_array( $state ) || ! isset( $state[ 'failures' ], $state[ 'open_until' ] ) )
      {
         return null;
      }   ## torn or corrupt write: treat as closed and let it be overwritten

      ## Never open for longer than one cooldown, whatever the file says.
      return array(
         'failures'   => (int) $state[ 'failures' ],
         'open_until' => min( (int) $state[ 'open_until' ], time() + $this->cooldown ),
         'updated'    => (int) ( $state[ 'updated' ] ?? 0 ),
      );
   }

   /**
    * Atomic replacement keeps readers from seeing partial JSON.
    * Concurrent updates can overwrite each other's failure counts.
    */
   private function write( $cluster, $state )
   {
      if ( ! $this->usable() )
      {
         return;
      }

      $path = $this->path( $cluster );
      $tmp  = @tempnam( $this->dir, '.brk-' );   ## a new file: never follows a planted symlink

      if ( $tmp === false || @file_put_contents( $tmp, json_encode( $state ) ) === false )
      {
         if ( $tmp !== false ) @unlink( $tmp );
         return;
      }

      if ( ! @rename( $tmp, $path ) )
      {
         @unlink( $tmp );
      }
   }

   private function log( $msg )
   {
      call_user_func( $this->log, $msg );
   }
}
