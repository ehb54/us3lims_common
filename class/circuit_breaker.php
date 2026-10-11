<?php
/*
 * Per-cluster transport failure counts, shared across the processes of one
 * account. Calls fail fast after the threshold until the cooldown expires.
 *
 * State is kept per account, in a subdirectory of $dir owned by the account that
 * writes it, with files at 0600. The web tier and the us3 daemons therefore each
 * keep their own counts rather than sharing one group-writable file: a state file
 * that decides whether a cluster is contacted should not be writable by another
 * account. The cost is that a breaker tripped by the web tier does not back off
 * the daemons, and the other way round; each still backs itself off.
 */

class circuit_breaker
{
   private $dir;
   private $threshold;
   private $cooldown;
   private $max_cooldown;
   private $log;
   private $usable = null;

   /**
    * $dir       directory for state files. Created if absent.
    * $threshold consecutive transport faults before the breaker opens.
    * $cooldown  seconds to stay open before allowing a probe through, the
    *            first time the breaker opens.
    * $max_cooldown
    *            ceiling on the cooldown once it has doubled on consecutive
    *            trips (a trip the probe immediately fails again). Defaults
    *            to $cooldown itself, i.e. no progressive backoff, so an
    *            existing caller that doesn't pass this keeps today's
    *            behavior exactly.
    */
   public function __construct( $dir, $threshold = 3, $cooldown = 120, $log = null, $max_cooldown = null )
   {
      $this->dir          = rtrim( (string) $dir, '/' );
      $this->threshold    = max( 1, (int) $threshold );
      $this->cooldown     = max( 1, (int) $cooldown );
      $this->max_cooldown = max( $this->cooldown, (int) ( $max_cooldown ?? $this->cooldown ) );
      $this->log          = is_callable( $log ) ? $log : function ( $m ) { error_log( "circuit_breaker: $m" ); };
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

      $this->write( $cluster, array( 'failures' => 0, 'open_until' => 0, 'trips' => 0, 'updated' => time() ) );
   }

   /**
    * A call failed at the transport layer. Count it, and open the breaker once
    * the threshold is reached.
    *
    * is_open()'s gate means this is only ever reached for a cluster that is
    * NOT currently open (a fast-failed call never gets here): either it has
    * never tripped, or its cooldown already expired and this failure is the
    * half-open probe failing again. Either way, "about to open" here always
    * means a fresh trip, so $trips can be incremented unconditionally
    * whenever $failures crosses the threshold.
    */
   public function record_failure( $cluster )
   {
      $state = $this->read( $cluster );

      if ( $state === null )
      {
         $state = array( 'failures' => 0, 'open_until' => 0, 'trips' => 0, 'updated' => 0 );
      }

      $failures = $state[ 'failures' ] + 1;

      if ( $failures >= $this->threshold )
      {
         $trips      = $state[ 'trips' ] + 1;
         $cooldown   = min( $this->max_cooldown, $this->cooldown * ( 2 ** ( $trips - 1 ) ) );
         $open_until = time() + $cooldown;

         $this->log( "$cluster unreachable $failures time(s) in a row (trip $trips);"
                     . " breaker open for {$cooldown}s -- further calls will fail fast" );

         $this->write( $cluster, array(
            'failures' => $failures, 'open_until' => $open_until, 'trips' => $trips, 'updated' => time()
         ) );
         return;
      }

      $this->write( $cluster, array(
         'failures' => $failures, 'open_until' => 0, 'trips' => $state[ 'trips' ], 'updated' => time()
      ) );
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
                        . $state[ 'failures' ] . ' consecutive failures, trip ' . $state[ 'trips' ] . ')';
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

   ## The account this process runs as, for the state subdirectory. The name when
   ## it can be read, else the uid: both are stable and filename-safe.
   private function account()
   {
      $uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;

      if ( $uid !== null && function_exists( 'posix_getpwuid' ) )
      {
         $pw = @posix_getpwuid( $uid );
         if ( is_array( $pw ) && isset( $pw[ 'name' ] ) && $pw[ 'name' ] !== '' )
         {
            return preg_replace( '/[^A-Za-z0-9._-]/', '_', $pw[ 'name' ] );
         }
      }

      return $uid === null ? 'unknown' : (string) $uid;
   }

   /** Where this account's state files live. Public so an installer check and the
    *  tests can look at the real location rather than rebuild the rule. */
   public function state_directory()
   {
      return $this->dir . '/' . $this->account();
   }

   private function state_dir()
   {
      return $this->state_directory();
   }

   private function path( $cluster )
   {
      ## Restrict cluster names to filename-safe characters.
      $safe = preg_replace( '/[^A-Za-z0-9._-]/', '_', (string) $cluster );

      return $this->state_dir() . '/' . $safe . '.brk';
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

      ## State that decides whether a cluster is contacted: refuse a symlinked
      ## parent or one owned by anyone but us3 or root. The parent is shared
      ## ground, so another account could otherwise plant something in it.
      $us3   = function_exists( 'posix_getpwnam' ) ? posix_getpwnam( 'us3' ) : false;
      $owner = @fileowner( $this->dir );
      if ( is_link( $this->dir ) || ( $us3 && $owner !== $us3[ 'uid' ] && $owner !== 0 ) )
      {
         $this->log( "state directory {$this->dir} is a symlink or not owned by us3; breaker disabled" );
         return $this->usable = false;
      }

      ## This account's own subdirectory, 0700: nothing another account writes can
      ## change what this one believes about a cluster.
      $mine = $this->state_dir();

      if ( ! is_dir( $mine ) && ! @mkdir( $mine, 0700, true ) && ! is_dir( $mine ) )
      {
         ## The second is_dir() catches two processes racing the first use:
         ## the loser's mkdir() fails with EEXIST once the winner's mkdir()
         ## has already landed, which is success, not the permission problem
         ## this message describes.
         ##
         ## Distinct from the symlink/ownership check below: this account
         ## could not even create its own subdirectory, almost always
         ## because $this->dir's group does not include it. The generic
         ## "symlink or not owned" message used to fire here too, naming a
         ## cause this account had no way to have caused.
         $this->log( "could not create $mine under {$this->dir}, which this account"
                     . " may lack permission to write into; breaker disabled" );
         return $this->usable = false;
      }

      $euid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
      $own  = @fileowner( $mine );
      if ( is_link( $mine ) || ( $euid !== null && $own !== $euid && $own !== 0 ) )
      {
         $this->log( "state directory $mine is a symlink or not owned by this account; breaker disabled" );
         return $this->usable = false;
      }

      return $this->usable = ( is_dir( $mine ) && is_writable( $mine ) );
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

      ## Never open for longer than the progressive ceiling, whatever the file says.
      return array(
         'failures'   => (int) $state[ 'failures' ],
         'open_until' => min( (int) $state[ 'open_until' ], time() + $this->max_cooldown ),
         'trips'      => (int) ( $state[ 'trips' ] ?? 0 ),
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
      $tmp  = @tempnam( $this->state_dir(), '.brk-' );   ## a new file: never follows a planted symlink

      if ( $tmp === false || @file_put_contents( $tmp, json_encode( $state ) ) === false )
      {
         if ( $tmp !== false ) @unlink( $tmp );
         return;
      }

      ## tempnam() already creates 0600; said explicitly because the mode is part
      ## of the contract, not an incidental property of how the file was made.
      @chmod( $tmp, 0600 );

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
