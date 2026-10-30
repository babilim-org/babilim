<?php
/**
 * Babilim_Manifest's own cross-request write lock, against real
 * WordPress core and its real database layer (the SQLite driver WordPress
 * Playground runs on, which translates INSERT IGNORE the same way it
 * translates every other MySQL statement WordPress core itself issues).
 *
 * A single `wp eval-file` process can't run two requests at once, so
 * "another request" is played by direct $wpdb statements on the options
 * table, made at an exact point between two of the lock's own statements
 * through WordPress core's own `query` filter (every $wpdb query passes
 * through it) and `add_option` action. Each scenario checks the three
 * properties a mutex has to have: never two holders at once, a released
 * lock is seen as released (not a stale cached copy), and a released lock
 * is immediately takeable again.
 *
 * The same scenarios, with the same injection points, run as unit tests
 * against a database stub in tests/unit/test-manifest-lock.php; this
 * file is what proves them against real WordPress core.
 */

declare( strict_types=1 );

/**
 * A reflection handle on the manifest's own private lock methods, plus
 * helpers for "another request" acting directly on the lock's row.
 */
final class Babilim_Pg_Lock_Harness {
	public const LOCK = 'babilim_manifest_lock';

	/** @var Babilim_Manifest */
	private $manifest;

	/** @var callable|null fn( string $verb, string $query ): void */
	private $on_lock_query = null;

	/** @var bool */
	private $busy = false;

	/** @var int */
	public $lock_selects = 0;

	/** @var callable */
	private $filter;

	/** @var callable */
	private $action;

	/** @var callable */
	private $read;

	public function __construct() {
		global $wpdb;
		$this->manifest = ( new ReflectionClass( 'Babilim_Manifest' ) )->newInstanceWithoutConstructor();
		$this->reset();

		$this->filter = function ( $query ) {
			if ( ! $this->busy && is_string( $query ) && false !== strpos( $query, self::LOCK ) ) {
				$verb = strtolower( (string) strtok( ltrim( $query ), " \t\n" ) );
				if ( 'select' === $verb ) {
					++$this->lock_selects;
				}
				if ( null !== $this->on_lock_query ) {
					$this->busy = true;
					try {
						call_user_func( $this->on_lock_query, $verb, $query );
					} finally {
						$this->busy = false;
					}
				}
			}
			return $query;
		};
		// add_option() fires this between its own existence check and its
		// own INSERT -- the one point an add_option()-based lock could be
		// raced that the `query` filter alone doesn't reach.
		$this->action = function ( $option ) {
			if ( ! $this->busy && self::LOCK === $option && null !== $this->on_lock_query ) {
				$this->busy = true;
				try {
					call_user_func( $this->on_lock_query, 'add_option', '' );
				} finally {
					$this->busy = false;
				}
			}
		};
		// get_option() of the lock hands its (possibly cached) value
		// through this filter -- the point an Options-API-based lock has
		// "seen" the lock, without issuing any query of its own.
		$this->read = function ( $value ) {
			if ( ! $this->busy && null !== $this->on_lock_query ) {
				$this->busy = true;
				try {
					call_user_func( $this->on_lock_query, 'read_option', '' );
				} finally {
					$this->busy = false;
				}
			}
			return $value;
		};
		add_filter( 'query', $this->filter );
		add_action( 'add_option', $this->action );
		add_filter( 'option_' . self::LOCK, $this->read );
	}

	public function done(): void {
		remove_filter( 'query', $this->filter );
		remove_filter( 'add_option', $this->action ); // Actions are filters underneath; remove_filter() removes either.
		remove_filter( 'option_' . self::LOCK, $this->read );
		$this->on_lock_query = null;
		$this->reset();
	}

	public function on_lock_query( ?callable $fn ): void {
		$this->on_lock_query = $fn;
		$this->lock_selects  = 0;
	}

	public function acquire(): ?string {
		$m = new ReflectionMethod( 'Babilim_Manifest', 'acquire_manifest_lock' );
		$m->setAccessible( true );
		return $m->invoke( $this->manifest );
	}

	public function release( string $held ): void {
		$m = new ReflectionMethod( 'Babilim_Manifest', 'release_manifest_lock' );
		$m->setAccessible( true );
		$m->invoke( $this->manifest, $held );
	}

	public function value( string $token, int $expires_at ): string {
		$m = new ReflectionMethod( 'Babilim_Manifest', 'lock_value' );
		$m->setAccessible( true );
		return (string) $m->invoke( null, $token, $expires_at );
	}

	/** The lock row's value straight from the database, or null if there is no row. */
	public function row(): ?string {
		global $wpdb;
		$was        = $this->busy;
		$this->busy = true;
		$v          = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->base_prefix}options WHERE option_name = %s", self::LOCK ) );
		$this->busy = $was;
		return null === $v ? null : (string) $v;
	}

	/** "Another request" holds the lock with $value (replacing any row). */
	public function foreign_hold( string $value ): void {
		global $wpdb;
		$was        = $this->busy;
		$this->busy = true;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->base_prefix}options WHERE option_name = %s", self::LOCK ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->base_prefix}options (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK, $value ) );
		$this->busy = $was;
	}

	/** "Another request" releases whatever lock is held. */
	public function foreign_release(): void {
		global $wpdb;
		$was        = $this->busy;
		$this->busy = true;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->base_prefix}options WHERE option_name = %s", self::LOCK ) );
		$this->busy = $was;
	}

	/** No row, and nothing about the lock option left in this request's object cache. */
	public function reset(): void {
		$this->foreign_release();
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}

babilim_pg_run(
	'manifest-lock-uncontended-roundtrip',
	'manifest lock, uncontended: taken, released, immediately takeable again, and no stale cached copy afterwards (real core, real DB layer)',
	static function () {
		$h = new Babilim_Pg_Lock_Harness();
		try {
			$held = $h->acquire();
			babilim_pg_assert( null !== $held, 'an uncontended acquire failed' );
			babilim_pg_assert( $held === $h->row(), 'the row does not hold the value acquire() returned' );
			babilim_pg_assert( $held === get_option( Babilim_Pg_Lock_Harness::LOCK ), 'get_option() in the same request disagrees with the database after acquire' );

			$h->release( (string) $held );
			babilim_pg_assert( null === $h->row(), 'release left the row in place' );
			babilim_pg_assert( false === get_option( Babilim_Pg_Lock_Harness::LOCK ), 'get_option() in the same request still sees the released lock' );

			$start = microtime( true );
			$again = $h->acquire();
			$took  = microtime( true ) - $start;
			babilim_pg_assert( null !== $again, 'a just-released lock could not be taken again' );
			babilim_pg_assert( $took < 0.5, sprintf( 'taking a just-released lock took %.2fs', $took ) );
			$h->release( (string) $again );
		} finally {
			$h->done();
		}
	}
);

babilim_pg_run(
	'manifest-lock-waits-for-valid-holder',
	'manifest lock: after this request\'s own acquire/release, a lock another request then validly holds is never taken until that request releases it',
	static function () {
		$h = new Babilim_Pg_Lock_Harness();
		try {
			$h->release( (string) $h->acquire() );
			$foreign = $h->value( 'FOREIGN', time() + 60 );
			$h->foreign_hold( $foreign );

			$released = false;
			$h->on_lock_query(
				static function () use ( $h, &$released, $foreign ) {
					if ( ! $released && $h->lock_selects >= 3 ) {
						babilim_pg_assert( $foreign === $h->row(), 'the other request\'s valid lock was overwritten while it still held it' );
						$released = true;
						$h->foreign_release();
					}
				}
			);
			$held = $h->acquire();
			$h->on_lock_query( null );

			babilim_pg_assert( $released, 'acquire() returned while the other request still held a valid lock (two holders at once); row now ' . var_export( $h->row(), true ) );
			babilim_pg_assert( null !== $held, 'once the other request released it, acquire() must get the lock' );
			babilim_pg_assert( $held === $h->row(), 'the row does not hold this request\'s own value' );
		} finally {
			$h->done();
		}
	}
);

babilim_pg_run(
	'manifest-lock-takes-promptly-after-release',
	'manifest lock: a lock released right after this request first saw it held is seen as released and taken promptly, never waited out to the retry budget',
	static function () {
		$h = new Babilim_Pg_Lock_Harness();
		try {
			$h->foreign_hold( $h->value( 'FOREIGN', time() + 60 ) );

			$released = false;
			$h->on_lock_query(
				static function ( $verb ) use ( $h, &$released ) {
					// This request has just seen the lock held (its read
					// of the row returned it); the other request releases
					// right after.
					if ( ! $released && ( 'read_option' === $verb || ( $h->lock_selects >= 1 && 'select' !== $verb ) ) ) {
						$released = true;
						$h->foreign_release();
					}
				}
			);
			$start = microtime( true );
			$held  = $h->acquire();
			$took  = microtime( true ) - $start;
			$h->on_lock_query( null );

			babilim_pg_assert( $released, 'the scenario never reached the point where the other request releases' );
			babilim_pg_assert( null !== $held, sprintf( 'acquire() gave up (%.2fs) on a lock that had already been released', $took ) );
			babilim_pg_assert( $took < 1.0, sprintf( 'acquire() took %.2fs to notice the release', $took ) );
		} finally {
			$h->done();
		}
	}
);

babilim_pg_run(
	'manifest-lock-insert-race-no-overwrite',
	'manifest lock: another request\'s insert landing immediately before this request\'s own is never overwritten',
	static function () {
		$h = new Babilim_Pg_Lock_Harness();
		try {
			$foreign  = $h->value( 'FOREIGN', time() + 60 );
			$inserted = false;
			$released = false;
			$h->on_lock_query(
				static function ( $verb ) use ( $h, &$inserted, &$released, $foreign ) {
					if ( ! $inserted && ( 'insert' === $verb || 'add_option' === $verb ) ) {
						$inserted = true;
						$h->foreign_hold( $foreign );
						return;
					}
					if ( $inserted && ! $released && $h->lock_selects >= 3 ) {
						babilim_pg_assert( $foreign === $h->row(), 'the other request\'s lock was overwritten while it held it' );
						$released = true;
						$h->foreign_release();
					}
				}
			);
			$held = $h->acquire();
			$h->on_lock_query( null );

			babilim_pg_assert( $inserted, 'the scenario never reached this request\'s own insert' );
			babilim_pg_assert( $released, 'acquire() returned while the other request still held the lock it inserted first (two holders at once); row now ' . var_export( $h->row(), true ) );
			babilim_pg_assert( null !== $held && $held === $h->row(), 'once released, acquire() must get the lock' );
		} finally {
			$h->done();
		}
	}
);

babilim_pg_run(
	'manifest-lock-expired-takeover-race',
	'manifest lock: of two requests taking over the same expired lock, the one that loses never overwrites the winner',
	static function () {
		$h = new Babilim_Pg_Lock_Harness();
		try {
			$h->foreign_hold( $h->value( 'DEAD', time() - 5 ) );
			$winner    = $h->value( 'WINNER', time() + 60 );
			$took_over = false;
			$released  = false;
			$h->on_lock_query(
				static function ( $verb ) use ( $h, &$took_over, &$released, $winner ) {
					if ( ! $took_over && 'update' === $verb ) {
						$took_over = true;
						$h->foreign_hold( $winner );
						return;
					}
					if ( $took_over && ! $released && $h->lock_selects >= 4 ) {
						babilim_pg_assert( $winner === $h->row(), 'the winning takeover was overwritten while its holder held it' );
						$released = true;
						$h->foreign_release();
					}
				}
			);
			$held = $h->acquire();
			$h->on_lock_query( null );

			babilim_pg_assert( $took_over, 'the scenario never reached this request\'s own takeover' );
			babilim_pg_assert( $released, 'acquire() returned while the winning takeover still held the lock (two holders at once); row now ' . var_export( $h->row(), true ) );
			babilim_pg_assert( null !== $held, 'once released, acquire() must get the lock' );
		} finally {
			$h->done();
		}
	}
);

babilim_pg_run(
	'manifest-lock-release-no-stale-delete',
	'manifest lock: releasing never deletes a lock another request has since taken over',
	static function () {
		$h = new Babilim_Pg_Lock_Harness();
		try {
			$held  = (string) $h->acquire();
			$other = $h->value( 'OTHER', time() + 60 );
			$h->foreign_hold( $other );

			$h->release( $held );

			babilim_pg_assert( $other === $h->row(), 'release() deleted a lock this request no longer held; row now ' . var_export( $h->row(), true ) );
		} finally {
			$h->done();
		}
	}
);
