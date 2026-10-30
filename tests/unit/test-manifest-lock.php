<?php
/**
 * Tests for Babilim_Manifest's own cross-request write lock -- the
 * single-statement database primitives it is built on (INSERT IGNORE to
 * take a free lock, a value-conditional UPDATE to take over an expired
 * one, a value-conditional DELETE to release only its own), exercised
 * against tests/bootstrap.php's Babilim_Test_Wpdb. That stub's
 * $before_statement hook lets "another request" act at an exact point
 * between two of the lock's own statements, so every interleaving below
 * is deterministic rather than timing-dependent.
 *
 * The same three contention scenarios also run against real WordPress
 * core and its real database layer in
 * tests/playground/checks/m3-manifest-lock.php.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Manifest_Lock extends TestCase {

	private const LOCK = 'babilim_manifest_lock';

	protected function setUp(): void {
		babilim_test_reset();
	}

	private function manifest(): Babilim_Manifest {
		return new Babilim_Manifest( new Babilim_Memory_Fs() );
	}

	private function acquire( Babilim_Manifest $m ): ?string {
		$method = new ReflectionMethod( Babilim_Manifest::class, 'acquire_manifest_lock' );
		$method->setAccessible( true );
		return $method->invoke( $m );
	}

	private function release( Babilim_Manifest $m, string $held ): void {
		$method = new ReflectionMethod( Babilim_Manifest::class, 'release_manifest_lock' );
		$method->setAccessible( true );
		$method->invoke( $m, $held );
	}

	/** A lock value in the manifest's own format, held by $token until $expires_at. */
	private function lock_value( string $token, int $expires_at ): string {
		$method = new ReflectionMethod( Babilim_Manifest::class, 'lock_value' );
		$method->setAccessible( true );
		return (string) $method->invoke( null, $token, $expires_at );
	}

	private function db(): Babilim_Test_Wpdb {
		return $GLOBALS['wpdb'];
	}

	private function row(): ?string {
		return $this->db()->babilim_test_row( self::LOCK );
	}

	private function lock_selects_so_far(): int {
		return count(
			array_filter(
				$this->db()->statements,
				static function ( $s ) {
					return 'select' === $s[0];
				}
			)
		);
	}

	public function test_an_uncontended_lock_is_taken_with_insert_ignore_and_released_with_a_value_conditional_delete(): void {
		$m    = $this->manifest();
		$held = $this->acquire( $m );

		$this->assertNotNull( $held );
		$this->assertSame( $held, $this->row(), 'the row holds exactly the value acquire() returned' );
		// Real core wraps an %i-substituted identifier in backticks (see
		// tests/bootstrap.php's Babilim_Test_Wpdb::prepare()).
		$this->assertStringStartsWith( 'INSERT IGNORE INTO `wp_options`', $this->db()->statements[0][1] );

		$this->release( $m, $held );
		$this->assertNull( $this->row() );
		$last = end( $this->db()->statements );
		$this->assertMatchesRegularExpression( "/^DELETE FROM `wp_options` WHERE option_name = '.*' AND option_value = '.*'$/", $last[1] );

		// Released means immediately takeable again, with no wait.
		$start = microtime( true );
		$again = $this->acquire( $m );
		$this->assertNotNull( $again );
		$this->assertLessThan( 0.05, microtime( true ) - $start, 'a released lock must be takeable immediately' );
		$this->release( $m, $again );
	}

	/**
	 * After this request's own earlier acquire/release, another
	 * request takes a valid lock. It must never be taken from under it --
	 * only once that request itself releases it.
	 */
	public function test_a_valid_lock_another_request_holds_is_never_taken_before_that_request_releases_it(): void {
		$m = $this->manifest();
		$this->release( $m, (string) $this->acquire( $m ) );

		$foreign = $this->lock_value( 'FOREIGN', time() + 60 );
		$this->db()->babilim_test_put_row( self::LOCK, $foreign );

		$released = false;
		$this->db()->before_statement = function ( $verb ) use ( &$released ) {
			if ( ! $released && $this->lock_selects_so_far() >= 3 ) {
				$released = true;
				$this->db()->babilim_test_delete_row( self::LOCK );
			}
		};

		$held = $this->acquire( $m );
		$this->db()->before_statement = null;

		$this->assertTrue( $released, 'acquire() returned while the other request still held a valid lock -- two holders at once' );
		$this->assertNotNull( $held, 'once the other request released it, acquire() must get the lock' );
		$this->assertSame( $held, $this->row() );
	}

	/**
	 * The other request releases right after this one first sees it
	 * held -- acquire() must see the release (the database, not a cached
	 * copy) and get the lock promptly, never wait out its whole budget.
	 */
	public function test_a_lock_released_right_after_it_was_first_seen_held_is_taken_promptly(): void {
		$m = $this->manifest();
		$this->db()->babilim_test_put_row( self::LOCK, $this->lock_value( 'FOREIGN', time() + 60 ) );

		$released = false;
		$this->db()->before_statement = function ( $verb ) use ( &$released ) {
			if ( ! $released && $this->lock_selects_so_far() >= 2 ) {
				$released = true;
				$this->db()->babilim_test_delete_row( self::LOCK );
			}
		};

		$start = microtime( true );
		$held  = $this->acquire( $m );
		$took  = microtime( true ) - $start;
		$this->db()->before_statement = null;

		$this->assertNotNull( $held );
		$this->assertLessThan( 1.0, $took, sprintf( 'acquire() took %.2fs after the lock was released', $took ) );
	}

	/**
	 * The other request's own insert lands immediately before this
	 * one's -- this one must see the row as taken and wait, never
	 * overwrite it.
	 */
	public function test_an_insert_racing_ahead_of_this_requests_own_is_never_overwritten(): void {
		$m       = $this->manifest();
		$foreign = $this->lock_value( 'FOREIGN', time() + 60 );

		$inserted = false;
		$released = false;
		$this->db()->before_statement = function ( $verb ) use ( &$inserted, &$released, $foreign ) {
			if ( ! $inserted && 'insert' === $verb ) {
				$inserted = true;
				$this->db()->babilim_test_put_row( self::LOCK, $foreign );
				return;
			}
			if ( $inserted && ! $released && $this->lock_selects_so_far() >= 3 ) {
				$this->assertSame( $foreign, $this->row(), 'the other request\'s lock was overwritten while it held it' );
				$released = true;
				$this->db()->babilim_test_delete_row( self::LOCK );
			}
		};

		$held = $this->acquire( $m );
		$this->db()->before_statement = null;

		$this->assertTrue( $released, 'acquire() returned while the other request still held the lock it inserted first' );
		$this->assertNotNull( $held );
		$this->assertSame( $held, $this->row() );
	}

	public function test_an_expired_lock_is_taken_over(): void {
		$m     = $this->manifest();
		$stale = $this->lock_value( 'DEAD', time() - 5 );
		$this->db()->babilim_test_put_row( self::LOCK, $stale );

		$held = $this->acquire( $m );

		$this->assertNotNull( $held );
		$this->assertSame( $held, $this->row() );
		$updates = array_values(
			array_filter(
				$this->db()->statements,
				static function ( $s ) {
					return 'update' === $s[0];
				}
			)
		);
		$this->assertCount( 1, $updates );
		$this->assertMatchesRegularExpression( "/ AND option_value = '.*'$/", $updates[0][1], 'the takeover must be conditional on the exact expired value' );
	}

	/**
	 * Two requests both see the same expired lock; the other one's
	 * takeover lands first. This one's own takeover must then fail (its
	 * UPDATE is conditional on the exact expired value it saw), and it
	 * must wait for the new holder, never overwrite it.
	 */
	public function test_a_takeover_that_loses_the_race_for_an_expired_lock_never_overwrites_the_winner(): void {
		$m      = $this->manifest();
		$stale  = $this->lock_value( 'DEAD', time() - 5 );
		$winner = $this->lock_value( 'WINNER', time() + 60 );
		$this->db()->babilim_test_put_row( self::LOCK, $stale );

		$took_over = false;
		$released  = false;
		$this->db()->before_statement = function ( $verb ) use ( &$took_over, &$released, $winner ) {
			if ( ! $took_over && 'update' === $verb ) {
				$took_over = true;
				$this->db()->babilim_test_put_row( self::LOCK, $winner );
				return;
			}
			if ( $took_over && ! $released && $this->lock_selects_so_far() >= 4 ) {
				$this->assertSame( $winner, $this->row(), 'the winning takeover was overwritten while its holder still held it' );
				$released = true;
				$this->db()->babilim_test_delete_row( self::LOCK );
			}
		};

		$held = $this->acquire( $m );
		$this->db()->before_statement = null;

		$this->assertTrue( $took_over );
		$this->assertTrue( $released, 'acquire() returned while the winning takeover still held the lock -- two holders at once' );
		$this->assertNotNull( $held );
	}

	/**
	 * This request's own lock expired and was taken over by another
	 * request; releasing must leave the new holder's lock in place.
	 */
	public function test_release_never_deletes_a_lock_another_request_has_since_taken_over(): void {
		$m    = $this->manifest();
		$held = (string) $this->acquire( $m );

		$other = $this->lock_value( 'OTHER', time() + 60 );
		$this->db()->babilim_test_put_row( self::LOCK, $other );

		$this->release( $m, $held );

		$this->assertSame( $other, $this->row(), 'release() deleted a lock this request no longer held' );
	}

	/**
	 * Nothing else in the same request may read a stale copy of the lock
	 * option from the object cache after the lock's own direct writes.
	 */
	public function test_acquire_and_release_leave_no_stale_cached_copy_of_the_lock_option(): void {
		wp_cache_set( self::LOCK, 'stale', 'options' );
		wp_cache_set( 'notoptions', array( self::LOCK => true, 'unrelated' => true ), 'options' );
		wp_cache_set( 'alloptions', array( self::LOCK => 'stale', 'blogname' => 'x' ), 'options' );

		$m    = $this->manifest();
		$held = (string) $this->acquire( $m );

		$this->assertFalse( wp_cache_get( self::LOCK, 'options' ) );
		$this->assertArrayNotHasKey( self::LOCK, (array) wp_cache_get( 'notoptions', 'options' ) );
		$this->assertArrayHasKey( 'unrelated', (array) wp_cache_get( 'notoptions', 'options' ), 'only the lock\'s own entry is dropped' );
		$this->assertArrayNotHasKey( self::LOCK, (array) wp_cache_get( 'alloptions', 'options' ) );
		$this->assertArrayHasKey( 'blogname', (array) wp_cache_get( 'alloptions', 'options' ) );

		wp_cache_set( self::LOCK, 'stale', 'options' );
		$this->release( $m, $held );
		$this->assertFalse( wp_cache_get( self::LOCK, 'options' ) );
	}

	/**
	 * On multisite, the lock lives in the main site's options table
	 * whichever site the request is for: `_manifest.json` is one file
	 * shared by the whole network, so a request for site 2 must never
	 * take the lock while a request for site 1 holds it -- and must get
	 * it once that request releases it.
	 */
	public function test_on_multisite_a_lock_held_while_serving_one_site_excludes_every_other_site(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );

		$on_one = $this->manifest();
		$held   = (string) $this->acquire( $on_one );
		$this->assertNotSame( '', $held );
		$this->assertSame( $held, $this->db()->babilim_test_row( self::LOCK, 'wp_options' ) );

		switch_to_blog( 2 );
		$this->assertSame( 'wp_2_options', $this->db()->options, 'the stub must point $wpdb->options at the switched-to site\'s own table' );
		$on_two   = $this->manifest();
		$released = false;
		$this->db()->before_statement = function () use ( &$released, $held ) {
			if ( ! $released && $this->lock_selects_so_far() >= 3 ) {
				$this->assertSame( $held, $this->row(), 'site 1\'s lock must still be in place while site 2 waits' );
				$released = true;
				$this->release( $this->manifest(), $held );
			}
		};
		$held_two = $this->acquire( $on_two );
		$this->db()->before_statement = null;

		$this->assertTrue( $released, 'a request for site 2 took the manifest lock while a request for site 1 still held it -- two writers on one shared file' );
		$this->assertNotNull( $held_two, 'once site 1\'s request released it, site 2\'s must get the lock' );
		$this->assertSame( $held_two, $this->db()->babilim_test_row( self::LOCK, 'wp_options' ), 'the lock row lives in the main site\'s table' );
		$this->assertNull( $this->db()->babilim_test_row( self::LOCK, 'wp_2_options' ), 'never in the current site\'s own table' );
		foreach ( $this->db()->statements as $statement ) {
			$this->assertStringNotContainsString( 'wp_2_options', $statement[1] );
		}

		$this->release( $on_two, (string) $held_two );
		$this->assertNull( $this->row() );
		restore_current_blog();
		$this->assertSame( 'wp_options', $this->db()->options );
	}

	/**
	 * Lock values only ever consist of lowercase hex, "|" and digits --
	 * the premise that makes case-insensitive, trailing-space-ignoring
	 * value comparisons (MySQL's default collations) harmless.
	 */
	public function test_every_lock_value_is_lowercase_hex_and_digits_and_a_malformed_one_is_never_written(): void {
		$m = $this->manifest();
		for ( $i = 0; $i < 20; $i++ ) {
			$held = (string) $this->acquire( $m );
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}\|[0-9]+$/D', $held );
			$this->release( $m, $held );
		}

		$check = new ReflectionMethod( Babilim_Manifest::class, 'well_formed_lock_value' );
		$check->setAccessible( true );
		$this->assertTrue( $check->invoke( null, $this->lock_value( str_repeat( 'a', 32 ), 123 ) ) );
		foreach ( array( str_repeat( 'A', 32 ) . '|123', str_repeat( 'a', 32 ) . '|123 ', 'babilim_5f1a.1|123', str_repeat( 'a', 31 ) . '|123', str_repeat( 'a', 32 ) . '|' ) as $bad ) {
			$this->assertFalse( $check->invoke( null, $bad ), $bad );
		}
	}

	public function test_without_a_database_connection_the_lock_fails_closed_and_the_write_reports_failure(): void {
		$fs = new Babilim_Memory_Fs();
		$m  = new Babilim_Manifest( $fs );
		$this->assertTrue( $m->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'one' ) ) );

		unset( $GLOBALS['wpdb'] );
		$this->assertNull( $this->acquire( $m ) );
		$this->assertFalse( $m->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'two' ) ) );

		$failure = $m->last_save_failure();
		$this->assertIsArray( $failure );
		$this->assertSame( 'lock_unavailable', $failure['reason'] );
	}
}
