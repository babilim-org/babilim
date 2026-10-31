<?php
/**
 * Tests for Babilim_State -- the last-synced inventory state and the
 * client-event ring buffer.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_State extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	public function test_get_defaults_to_empty_array(): void {
		$this->assertSame( array(), ( new Babilim_State() )->get() );
	}

	public function test_set_then_get_round_trips(): void {
		$state = new Babilim_State();
		$state->set( array( 'contract' => '1.1.1' ) );
		$this->assertSame( array( 'contract' => '1.1.1' ), $state->get() );
	}

	public function test_state_and_events_options_are_stored_without_autoload(): void {
		$state = new Babilim_State();
		$state->set( array( 'components' => array() ) );
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );
		$this->assertFalse( $GLOBALS['babilim_test_options_autoload']['babilim_state'] );
		$this->assertFalse( $GLOBALS['babilim_test_options_autoload']['babilim_client_events'] );

		// Every later write to the events option keeps it out of autoload.
		$state->record_client_event( 'integrity_failed', 'plugin', 'b', 'de_DE' );
		$GLOBALS['babilim_test_options_autoload']['babilim_client_events'] = true;
		$state->drain_client_events();
		$this->assertFalse( $GLOBALS['babilim_test_options_autoload']['babilim_client_events'] );

		$state->record_client_event( 'integrity_failed', 'plugin', 'c', 'de_DE' );
		$sent = $state->pending_client_events();
		$state->record_client_event( 'integrity_failed', 'plugin', 'd', 'de_DE' );
		$GLOBALS['babilim_test_options_autoload']['babilim_client_events'] = true;
		$state->drain_client_events( $sent );
		$this->assertFalse( $GLOBALS['babilim_test_options_autoload']['babilim_client_events'] );
	}

	public function test_updates_reads_from_stored_state(): void {
		$state = new Babilim_State();
		$state->set( array( 'updates' => array( array( 'kind' => 'plugin', 'slug' => 'a' ) ) ) );
		$this->assertSame( array( array( 'kind' => 'plugin', 'slug' => 'a' ) ), $state->updates() );
	}

	public function test_updates_defaults_to_empty_array(): void {
		$this->assertSame( array(), ( new Babilim_State() )->updates() );
	}

	public function test_status_finds_matching_component_and_locale(): void {
		$state = new Babilim_State();
		$state->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'a', 'locales' => array( 'de_DE' => array( 'status' => 'babilim_installed' ) ) ),
				),
			)
		);

		$this->assertSame( array( 'status' => 'babilim_installed' ), $state->status( 'plugin', 'a', 'de_DE' ) );
	}

	public function test_status_returns_null_for_unknown_component(): void {
		$state = new Babilim_State();
		$state->set( array( 'components' => array() ) );

		$this->assertNull( $state->status( 'plugin', 'a', 'de_DE' ) );
	}

	public function test_status_returns_null_for_unknown_locale_on_a_known_component(): void {
		$state = new Babilim_State();
		$state->set( array( 'components' => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locales' => array( 'de_DE' => array( 'status' => 'x' ) ) ) ) ) );

		$this->assertNull( $state->status( 'plugin', 'a', 'fr_FR' ) );
	}

	public function test_update_for_finds_the_matching_entry(): void {
		$state = new Babilim_State();
		$state->set(
			array(
				'updates' => array(
					array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE', 'build_id' => 'bd_000000000002', 'build_no' => 2 ),
				),
			)
		);

		$update = $state->update_for( 'plugin', 'a', 'de_DE' );

		$this->assertSame( 'bd_000000000002', $update['build_id'] );
	}

	public function test_update_for_returns_null_when_there_is_no_update(): void {
		$state = new Babilim_State();
		$state->set( array( 'updates' => array() ) );

		$this->assertNull( $state->update_for( 'plugin', 'a', 'de_DE' ) );
	}

	public function test_record_client_event_stores_exactly_the_five_fields(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );

		$events = $state->drain_client_events();
		$this->assertCount( 1, $events );
		$this->assertSame( array( 'at', 'code', 'kind', 'slug', 'locale' ), array_keys( $events[0] ) );
		$this->assertSame( 'integrity_failed', $events[0]['code'] );
		$this->assertSame( 'plugin', $events[0]['kind'] );
		$this->assertSame( 'a', $events[0]['slug'] );
		$this->assertSame( 'de_DE', $events[0]['locale'] );
	}

	public function test_ring_buffer_caps_at_twenty_dropping_the_oldest(): void {
		$state = new Babilim_State();
		for ( $i = 0; $i < 25; $i++ ) {
			$state->record_client_event( 'integrity_failed', 'plugin', 'component-' . $i, 'de_DE' );
		}

		$events = $state->drain_client_events();

		$this->assertCount( 20, $events );
		// The first 5 (component-0..component-4) must have been dropped;
		// the buffer keeps only the most recent 20.
		$this->assertSame( 'component-5', $events[0]['slug'] );
		$this->assertSame( 'component-24', $events[19]['slug'] );
	}

	public function test_record_client_event_does_not_duplicate_an_identical_pending_entry(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );

		$this->assertCount( 1, $state->pending_client_events() );
	}

	public function test_drain_removes_only_the_events_that_were_sent(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );
		$sent = $state->pending_client_events();
		$state->record_client_event( 'install_failed', 'plugin', 'b', 'fr_FR' ); // Arrives during the sync.

		$state->drain_client_events( $sent );

		$this->assertCount( 1, $state->pending_client_events() );
		$this->assertSame( 'install_failed', $state->pending_client_events()[0]['code'] );
	}

	public function test_record_client_event_allows_a_repeat_once_the_first_is_drained(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );
		$state->drain_client_events();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );

		$this->assertCount( 1, $state->pending_client_events() );
	}

	public function test_record_client_event_allows_a_different_code_kind_slug_or_locale(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );
		$state->record_client_event( 'orphaned', 'plugin', 'a', 'de_DE' );
		$state->record_client_event( 'integrity_failed', 'theme', 'a', 'de_DE' );
		$state->record_client_event( 'integrity_failed', 'plugin', 'b', 'de_DE' );
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'fr_FR' );

		$this->assertCount( 5, $state->pending_client_events() );
	}

	/**
	 * Regression: a manifest write conflict used to be reported through
	 * this same ring buffer with `kind: 'manifest'` -- the inventory
	 * request's own schema only accepts `plugin`/`theme` and rejects the
	 * whole request with a 422 when it sees anything else, and since a
	 * rejected sync never drains the buffer, the same invalid event kept
	 * getting resent and rejected forever. This guard stops any call
	 * site (existing or future) from getting a bad kind into the buffer
	 * at all.
	 */
	public function test_record_client_event_refuses_a_kind_outside_plugin_or_theme(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'manifest_write_conflict', 'manifest', 'default_index', 'de_DE' );

		$this->assertSame( array(), $state->pending_client_events(), 'an invalid kind must never be recorded at all' );
	}

	/**
	 * purge_invalid_client_events() migrates a buffer that already has a
	 * `kind: 'manifest'` (or any other non-plugin/theme) entry stuck in
	 * it from before record_client_event()'s own guard existed --
	 * Babilim_Site::sync() calls this before building its request body
	 * so an install upgrading into the fix self-heals on its very next
	 * sync. Valid entries are kept, untouched, in their original order.
	 */
	public function test_purge_invalid_client_events_removes_only_the_invalid_entries(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );
		// Simulates an entry a pre-guard version of this class already
		// wrote directly to the option -- record_client_event() itself
		// can no longer produce one.
		update_option( 'babilim_client_events', array_merge(
			array( array( 'at' => '2026-01-01T00:00:00Z', 'code' => 'manifest_write_conflict', 'kind' => 'manifest', 'slug' => 'default_index', 'locale' => 'de_DE' ) ),
			$state->pending_client_events()
		) );

		$removed = $state->purge_invalid_client_events();

		$this->assertSame( 1, $removed );
		$remaining = $state->pending_client_events();
		$this->assertCount( 1, $remaining );
		$this->assertSame( 'integrity_failed', $remaining[0]['code'] );
		$this->assertFalse( $GLOBALS['babilim_test_options_autoload']['babilim_client_events'] );
	}

	public function test_purge_invalid_client_events_is_a_no_op_when_everything_is_already_valid(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );

		$this->assertSame( 0, $state->purge_invalid_client_events() );
		$this->assertCount( 1, $state->pending_client_events() );
	}

	public function test_drain_empties_the_buffer(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'a', 'de_DE' );

		$state->drain_client_events();

		$this->assertSame( array(), $state->drain_client_events() );
	}

	public function test_remove_update_drops_only_the_matching_build_id(): void {
		$state = new Babilim_State();
		$state->set(
			array(
				'updates' => array(
					array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE', 'build_id' => 'bd_000000000001', 'build_no' => 1 ),
					array( 'kind' => 'plugin', 'slug' => 'b', 'locale' => 'de_DE', 'build_id' => 'bd_000000000002', 'build_no' => 1 ),
				),
			)
		);

		$state->remove_update( 'bd_000000000001' );

		$updates = $state->updates();
		$this->assertCount( 1, $updates );
		$this->assertSame( 'bd_000000000002', $updates[0]['build_id'] );
	}

	public function test_remove_update_is_a_no_op_for_an_unknown_build_id(): void {
		$state = new Babilim_State();
		$state->set( array( 'updates' => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE', 'build_id' => 'bd_000000000001', 'build_no' => 1 ) ) ) );

		$state->remove_update( 'bd_does_not_exist' );

		$this->assertCount( 1, $state->updates() );
	}

	public function test_remove_update_is_a_no_op_for_an_empty_build_id(): void {
		$state = new Babilim_State();
		$state->set( array( 'updates' => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE', 'build_id' => 'bd_000000000001', 'build_no' => 1 ) ) ) );

		$state->remove_update( '' );

		$this->assertCount( 1, $state->updates() );
	}

	public function test_remove_update_leaves_other_state_fields_untouched(): void {
		$state = new Babilim_State();
		$state->set(
			array(
				'contract' => '1.1.1',
				'updates'  => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE', 'build_id' => 'bd_000000000001', 'build_no' => 1 ) ),
			)
		);

		$state->remove_update( 'bd_000000000001' );

		$this->assertSame( '1.1.1', $state->get()['contract'] );
		$this->assertSame( array(), $state->updates() );
	}
}
