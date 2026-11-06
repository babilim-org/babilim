<?php
/**
 * Babilim_Error_Reporter: consent-gated capture of error events.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Error_Reporter extends TestCase {

	/** @var array<string,mixed> */
	private array $store = array();
	private int $clock   = 1800000000;

	private function reporter( ?callable $get = null, ?callable $update = null ): Babilim_Error_Reporter {
		$get    = $get ?? function ( string $name, $default ) {
			return array_key_exists( $name, $this->store ) ? $this->store[ $name ] : $default;
		};
		$update = $update ?? function ( string $name, $value ): void {
			$this->store[ $name ] = $value;
		};
		return new Babilim_Error_Reporter( $get, $update, fn(): int => $this->clock );
	}

	private function capture( Babilim_Error_Reporter $r ): void {
		$r->capture( 'verify_failed', 'sync', 'site.verify_installed' );
	}

	/** Captures the $i-th distinct (error_code, action, origin_id) combination. */
	private function capture_distinct( Babilim_Error_Reporter $r, int $i ): void {
		$r->capture(
			Babilim_Error_Catalog::ERROR_CODES[ $i % 5 ],
			Babilim_Error_Catalog::ACTIONS[ intdiv( $i, 5 ) % 7 ],
			Babilim_Error_Catalog::ORIGIN_IDS[ intdiv( $i, 35 ) % 9 ]
		);
	}

	public function test_capture_with_sharing_off_goes_to_awaiting_not_queue(): void {
		$r = $this->reporter();
		$this->capture( $r );
		$this->assertCount( 1, $r->awaiting() );
		$this->assertSame( array(), $r->pending() );
		$e = $r->awaiting()[0];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $e['event_id'] );
		$this->assertSame( 0, $e['attempts'] );
		$this->assertSame( gmdate( 'c', $this->clock ), $e['at'] );
	}

	public function test_capture_rejects_values_outside_catalog(): void {
		$r = $this->reporter();
		$r->capture( 'bogus', 'sync', 'site.sync' );
		$r->capture( 'verify_failed', 'bogus', 'site.sync' );
		$r->capture( 'verify_failed', 'sync', '/var/www/secret' );
		$this->assertSame( array(), $r->awaiting() );
		$this->assertSame( array(), $r->pending() );
		$this->assertSame( array(), $this->store );
	}

	public function test_awaiting_is_capped_at_ten_dropping_the_oldest(): void {
		$r = $this->reporter();
		for ( $i = 0; $i < 12; $i++ ) {
			$this->clock++;
			$this->capture_distinct( $r, $i );
		}
		$a = $r->awaiting();
		$this->assertCount( 10, $a );
		$this->assertSame( gmdate( 'c', 1800000003 ), $a[0]['at'] );
	}

	public function test_share_once_authorizes_only_that_event(): void {
		$r = $this->reporter();
		$this->capture_distinct( $r, 0 );
		$this->capture_distinct( $r, 1 );
		$ids = array_column( $r->awaiting(), 'event_id' );
		$this->assertTrue( $r->share_once( $ids[0] ) );
		$this->assertSame( array( $ids[0] ), array_column( $r->pending(), 'event_id' ) );
		$this->assertSame( array( $ids[1] ), array_column( $r->awaiting(), 'event_id' ) );
		$this->capture_distinct( $r, 2 );
		$this->assertCount( 2, $r->awaiting() );
		$this->assertCount( 1, $r->pending() );
		$this->assertFalse( $r->share_once( 'f' . str_repeat( '0', 31 ) ) );
	}

	public function test_share_always_moves_awaiting_and_future_captures_to_queue(): void {
		$r = $this->reporter();
		$this->capture( $r );
		$r->share_always();
		$this->assertSame( '1', $this->store['babilim_share_errors'] );
		$this->assertSame( array(), $r->awaiting() );
		$this->assertCount( 1, $r->pending() );
		$this->capture_distinct( $r, 1 );
		$this->assertCount( 2, $r->pending() );
		$this->assertSame( array(), $r->awaiting() );
	}

	public function test_decline_clears_awaiting_only(): void {
		$r = $this->reporter();
		$this->capture( $r );
		$r->decline( array_column( $r->awaiting(), 'event_id' ) );
		$this->assertSame( array(), $r->awaiting() );
		$this->assertArrayNotHasKey( 'babilim_share_errors', $this->store );
	}

	public function test_decline_drops_only_the_listed_events(): void {
		$r = $this->reporter();
		$this->capture( $r );
		$this->capture_distinct( $r, 1 );
		$ids = array_column( $r->awaiting(), 'event_id' );
		$this->assertCount( 2, $ids );

		$r->decline( array( $ids[0] ) );

		$this->assertSame( array( $ids[1] ), array_column( $r->awaiting(), 'event_id' ) );
		$r->decline( array() );
		$this->assertCount( 1, $r->awaiting(), 'an empty list drops nothing' );
	}

	public function test_pending_excludes_expired_and_exhausted(): void {
		$r = $this->reporter();
		$r->share_always();
		$this->capture( $r ); // Will expire.
		$this->clock += 31 * 86400;
		$this->capture( $r ); // Fresh.
		$this->capture_distinct( $r, 1 ); // Fresh, exhausted below.
		$all = $this->store['babilim_error_queue'];
		$this->assertCount( 3, $all );
		$this->store['babilim_error_queue'][2]['attempts'] = 3;
		$pending = $r->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( $all[1]['event_id'], $pending[0]['event_id'] );
	}

	public function test_pending_returns_at_most_twenty_and_queue_is_capped_at_fifty(): void {
		$r = $this->reporter();
		$r->share_always();
		for ( $i = 0; $i < 60; $i++ ) {
			$this->capture_distinct( $r, $i );
		}
		$this->assertCount( 50, $this->store['babilim_error_queue'] );
		$this->assertCount( 20, $r->pending() );
	}

	public function test_mark_sent_removes_and_mark_failed_counts_then_drops(): void {
		$r = $this->reporter();
		$r->share_always();
		$this->capture_distinct( $r, 0 );
		$this->capture_distinct( $r, 1 );
		$ids = array_column( $r->pending(), 'event_id' );
		$r->mark_sent( array( $ids[0] ) );
		$this->assertSame( array( $ids[1] ), array_column( $r->pending(), 'event_id' ) );
		$r->mark_failed( array( $ids[1] ) );
		$this->assertSame( 1, $r->pending()[0]['attempts'] );
		$r->mark_failed( array( $ids[1] ) );
		$r->mark_failed( array( $ids[1] ) );
		$this->assertSame( array(), $this->store['babilim_error_queue'] );
	}

	public function test_disable_clears_error_queue(): void {
		$r = $this->reporter();
		$this->capture( $r );
		$r->share_always();
		$this->capture( $r );
		$this->store['babilim_error_awaiting'] = array( array( 'event_id' => str_repeat( 'a', 32 ) ) );
		$r->clear_all();
		$this->assertSame( array(), $r->pending() );
		$this->assertSame( array(), $r->awaiting() );
		$this->assertSame( array(), $this->store['babilim_error_queue'] );
		$this->assertSame( array(), $this->store['babilim_error_awaiting'] );
	}

	public function test_capture_never_throws_when_option_store_fails(): void {
		$boom = function () {
			throw new \RuntimeException( 'store down' );
		};
		$r = $this->reporter( $boom, $boom );
		$this->capture( $r );
		$this->assertSame( array(), $this->store );
		$r2 = $this->reporter( null, $boom );
		$this->capture( $r2 );
		$this->assertSame( array(), $this->store );
	}

	public function test_preview_lists_exactly_the_fields_that_would_be_sent(): void {
		$r = $this->reporter();
		$this->capture( $r );
		$p = $r->preview( $r->awaiting()[0] );
		$this->assertSame( array( 'event_id', 'error_code', 'action', 'origin_id', 'at' ), array_keys( $p ) );
		$this->assertSame( 'verify_failed', $p['error_code'] );
	}

	public function test_sharing_flag_defaults_off_and_is_a_string(): void {
		$r = $this->reporter();
		$this->assertFalse( $r->sharing_enabled() );
		$this->store['babilim_share_errors'] = '1';
		$this->assertTrue( $r->sharing_enabled() );
	}

	public function test_repeated_capture_of_the_same_failure_is_recorded_once_and_writes_once(): void {
		$writes = 0;
		$r      = $this->reporter(
			null,
			function ( string $name, $value ) use ( &$writes ): void {
				++$writes;
				$this->store[ $name ] = $value;
			}
		);
		for ( $i = 0; $i < 5; $i++ ) {
			$this->capture( $r );
		}
		$this->assertCount( 1, $r->awaiting() );
		$this->assertSame( 1, $writes );
		$r->capture( 'load_failed', 'load', 'loader.translation_file' );
		$this->assertCount( 2, $r->awaiting(), 'a different fingerprint is still recorded' );
	}

	public function test_a_failure_already_in_the_queue_is_not_added_again(): void {
		$r = $this->reporter();
		$r->share_always();
		$this->capture( $r );
		$this->capture( $r );
		$this->assertCount( 1, $r->pending() );
	}

	public function test_expected_answers_are_excluded_codes(): void {
		foreach ( array( 'quota_exceeded', 'babilim_quota_exceeded', 'babilim_unauthorized', 'babilim_rate_limited', 'verification_required', 'no_token', 'no_consent' ) as $code ) {
			$this->assertTrue( Babilim_Error_Reporter::is_excluded_code( $code ), $code );
		}
		foreach ( array( 'integrity_failed', 'upload_part_failed', 'babilim_write_failed', 'unexpected_response' ) as $code ) {
			$this->assertFalse( Babilim_Error_Reporter::is_excluded_code( $code ), $code );
		}
	}

	/** @return array<string,array{string}> */
	public static function expected_state_codes(): array {
		$out = array();
		foreach ( array( 'license_required', 'not_available', 'plugin_outdated', 'capacity_exhausted', 'install_busy' ) as $code ) {
			$out[ $code ]                = array( $code );
			$out[ 'babilim_' . $code ] = array( 'babilim_' . $code );
		}
		return $out;
	}

	/**
	 * @dataProvider expected_state_codes
	 */
	public function test_expected_build_states_are_excluded_codes( string $code ): void {
		$this->assertTrue( Babilim_Error_Reporter::is_excluded_code( $code ), $code );
	}

	public function test_slot_required_is_an_expected_service_answer_not_an_error_event(): void {
		$this->assertTrue( Babilim_Error_Reporter::is_excluded_code( 'slot_required' ) );
		$this->assertTrue( Babilim_Error_Reporter::is_excluded_code( 'babilim_slot_required' ) );
	}

	public function test_request_body_is_the_environment_plus_each_events_preview(): void {
		$r = $this->reporter();
		$this->capture( $r );
		$event = $r->awaiting()[0];
		$body  = $r->request_body( array( $event ) );
		$this->assertSame( array( 'plugin_version', 'wp_version', 'php_version', 'locale', 'events' ), array_keys( $body ) );
		$this->assertSame( array( $r->preview( $event ) ), $body['events'] );
		$this->assertSame( PHP_VERSION, $body['php_version'] );
		$this->assertSame( BABILIM_VERSION, $body['plugin_version'] );
	}
}
