<?php
/*
 * Process-local mysqli connection reuse: checkout() hands back an idle
 * connection matching the same (host, user, pass, db) signature instead of
 * opening a new one, release() returns it to the pool instead of closing it.
 *
 * Scoped to one PHP process via a private static instance -- never a
 * mysqli-level 'p:' persistent connection, which PHP pools per worker
 * process keyed only by that same signature and so is shared with every
 * other script using the same credentials, not just the caller that asked
 * for pooling. This pool is only ever touched by code that explicitly calls
 * db_connection_pool::shared(), so nothing else in LIMS is affected by it.
 *
 * Not used for any connection whose correctness depends on a guaranteed,
 * non-reused session -- e.g. pick_cluster_reserve()'s GET_LOCK()/
 * RELEASE_LOCK() connection, which must close (and so release the advisory
 * lock) exactly when the caller is done with it, not linger idle for reuse.
 */

class db_connection_pool
{
   private static $instance = null;

   private $idle = array();   ## signature => array of idle mysqli connections

   /** @var \SplObjectStorage connection => signature, while checked out.
    *  An SplObjectStorage, not spl_object_id() => signature: PHP recycles
    *  object ids once an object is garbage collected, so a plain array
    *  keyed by id could, after a leaked checkout() (one never returned to
    *  release()), hand a LATER, unrelated connection's release() the stale
    *  entry an earlier connection left behind -- pooling it under the wrong
    *  signature with no error, a false positive indistinguishable from a
    *  race condition. SplObjectStorage keys by the object itself (available
    *  since PHP 5.3, unlike WeakMap, which needs 8.0 and isn't an option on
    *  this stack's PHP 7.2 target), so this can't happen regardless of id
    *  reuse. It holds a strong reference to each key while tracked, which
    *  is harmless here: a connection is only ever untracked by this class's
    *  own release()/close_all(), so a caller that leaks a checkout (never
    *  releases it) just keeps that connection alive and un-pooled, not
    *  silently corrupted. */
   private $owner;

   public static function shared()
   {
      if ( self::$instance === null )
      {
         self::$instance = new self();
      }

      return self::$instance;
   }

   public function __construct()
   {
      $this->owner = new \SplObjectStorage();
   }

   /**
    * An idle connection for this exact (host, user, pass, db) signature if
    * one is available and still alive, else a freshly opened one. Returns
    * false on connect failure, exactly as mysqli_connect() does, so an
    * existing `if ( ! $conn )` caller needs no other change.
    */
   public function checkout( $host, $user, $pass, $db )
   {
      $key = $this->signature( $host, $user, $pass, $db );

      while ( ! empty( $this->idle[ $key ] ) )
      {
         $conn = array_pop( $this->idle[ $key ] );

         if ( $this->is_alive( $conn ) )
         {
            $this->owner[ $conn ] = $key;
            return $conn;
         }

         ## Dropped by the server while idle (timeout, restart): discard, try the next.
         ## The query probe above can itself have left the handle in a state where
         ## mysqli considers it already closed, which close() then reports as a plain
         ## \Error rather than something @ suppresses.
         try { @mysqli_close( $conn ); } catch ( \Throwable $e ) { }
      }

      try
      {
         $conn = @mysqli_connect( $host, $user, $pass, $db );
      }
      catch ( \Throwable $e )
      {
         return false;
      }

      if ( $conn )
      {
         $this->owner[ $conn ] = $key;
      }

      return $conn;
   }

   /**
    * Returns a checked-out connection to the pool for reuse. Call from a
    * `finally` block so a connection is always returned (or closed, if
    * unhealthy) however the caller's try block exits.
    *
    * $healthy -- false discards and closes the connection instead of
    * pooling it, for a caller that already knows it is in a bad state
    * (a fatal query error, a transaction it can't guarantee was rolled
    * back) rather than letting the next checkout() discover that itself.
    */
   public function release( $conn, $healthy = true )
   {
      if ( ! $conn )
      {
         return;
      }

      $key = isset( $this->owner[ $conn ] ) ? $this->owner[ $conn ] : null;
      unset( $this->owner[ $conn ] );

      ## Not a connection this pool checked out (a caller mistake, or one already
      ## released once): never guess which bucket it belongs in.
      if ( $key === null || ! $healthy )
      {
         try { @mysqli_close( $conn ); } catch ( \Throwable $e ) { }
         return;
      }

      $this->idle[ $key ][] = $conn;
   }

   /** Closes every idle connection. Call at the end of a request/job that used the pool. */
   public function close_all()
   {
      foreach ( $this->idle as $conns )
      {
         foreach ( $conns as $conn )
         {
            try { @mysqli_close( $conn ); } catch ( \Throwable $e ) { }
         }
      }

      $this->idle  = array();
      $this->owner = new \SplObjectStorage();
   }

   ## ---------------------------------------------------------------- internals

   ## sha256, not md5: this key never needs to be short or fast to compute --
   ## it is only ever compared to itself as an array index -- so there is no
   ## reason to use a weak hash merely because the input happens to include
   ## a password.
   private function signature( $host, $user, $pass, $db )
   {
      return hash( 'sha256', $host . "\0" . $user . "\0" . $pass . "\0" . $db );
   }

   /** A lightweight round-trip: an idle connection can have been dropped by the
    *  server (timeout, restart) without this process doing anything wrong. */
   private function is_alive( $conn )
   {
      try
      {
         $result = @mysqli_query( $conn, 'SELECT 1' );
      }
      catch ( \Throwable $e )
      {
         ## Not just mysqli_sql_exception: a connection the server already dropped
         ## can leave the object itself unusable, which mysqli reports as a plain
         ## \Error ("mysqli object is already closed"), not a SQL exception.
         return false;
      }

      if ( $result === false )
      {
         return false;
      }

      if ( $result instanceof mysqli_result )
      {
         mysqli_free_result( $result );
      }

      return true;
   }
}
