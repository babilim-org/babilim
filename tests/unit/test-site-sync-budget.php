<?php
/**
 * Tests for the request deadline and for the consent check that sits at
 * the one place requests leave the plugin (Babilim_Api_Client::transport()):
 * consent is read straight from the database there, so a revocation that
 * lands mid-run stops the very next request.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Site_Sync_Budget extends TestCase {

	/** @var int */
	private $now = 1000;

	protected function setUp(): void {
		babilim_test_reset();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		update_option( 'babilim_consent', true );
		$this->now = 1000;
		$now       = &$this->now;
		add_filter(
			'babilim_now',
			static function () use ( &$now ) {
				return $now;
			}
		);
	}

	private function ok_response( array $body = array() ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	private function client( Babilim_Http $http, ?string $token = 'tok_x' ): Babilim_Api_Client {
		return new Babilim_Api_Client( $http, 'https://api.babilim.org', $token );
	}

	// -- Babilim_Deadline -------------------------------------------------

	public function test_a_null_budget_is_unlimited_but_a_step_is_still_capped(): void {
		$deadline = new Babilim_Deadline( null );

		$this->assertNull( $deadline->remaining() );
		$this->assertTrue( $deadline->can_start( 3 ) );
		$this->assertSame( 15, $deadline->step( 15 ) );
	}

	public function test_remaining_and_step_are_recomputed_from_the_clock_on_every_call(): void {
		$deadline = new Babilim_Deadline( 45 );
		$this->assertSame( 45, $deadline->remaining() );
		$this->assertSame( 15, $deadline->step( 15 ) );

		$this->now += 40;

		$this->assertSame( 5, $deadline->remaining() );
		$this->assertSame( 5, $deadline->step( 15 ), 'the cap shrinks to what is left' );
		$this->assertSame( 5, $deadline->step( 15 ) );
		$this->now += 3;
		$this->assertSame( 2, $deadline->step( 15 ) );
	}

	public function test_can_start_needs_the_minimum_to_be_left(): void {
		$deadline = new Babilim_Deadline( 10 );
		$this->assertTrue( $deadline->can_start( 3 ) );
		$this->now += 7;
		$this->assertTrue( $deadline->can_start( 3 ), 'exactly the minimum is enough' );
		$this->now += 1;
		$this->assertFalse( $deadline->can_start( 3 ) );
	}

	public function test_a_zero_budget_never_starts_and_the_remainder_never_goes_negative(): void {
		$deadline = new Babilim_Deadline( 0 );
		$this->assertFalse( $deadline->can_start( 3 ) );
		$this->assertSame( 0, $deadline->remaining() );
		$this->now += 100;
		$this->assertSame( 0, $deadline->remaining() );
		$this->assertSame( 0, $deadline->step( 15 ) );
	}

	// -- timeout parameters ---------------------------------------------

	public function test_every_client_call_passes_the_timeout_through_and_omits_it_when_null(): void {
		$calls = array(
			'register'            => static function ( $c, $t ) { return $c->register( array( 'home_url' => 'https://example.org' ), $t ); },
			'verify'              => static function ( $c, $t ) { return $c->verify( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a', 'b64:abc', $t ); },
			'inventory'           => static function ( $c, $t ) { return $c->inventory( 'st_x', array( 'components' => array(), 'locales' => array() ), $t ); },
			'download'            => static function ( $c, $t ) { return $c->download( 'bd_0123456789ab', $t ); },
			'request_translation' => static function ( $c, $t ) { return $c->request_translation( array( 'kind' => 'plugin' ), $t ); },
		);
		foreach ( $calls as $name => $call ) {
			$http = new Babilim_Fake_Http( array( $this->ok_response() ) );
			$call( $this->client( $http ), 7 );
			$call( $this->client( $http ), null );
			$this->assertSame( 7, $http->requests[0]['args']['timeout'] ?? null, $name . ' passes the timeout through' );
			$this->assertArrayNotHasKey( 'timeout', $http->requests[1]['args'], $name . ' leaves the transport default when null' );
		}
	}

	public function test_fetch_package_passes_its_timeout_too(): void {
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => 'zip', 'headers' => array() ) ) );
		$fs   = new Babilim_Memory_Fs();

		$result = $this->client( $http )->fetch_package( 'https://api.babilim.org/v1/packages/bd_0123456789ab', Babilim_Paths::incoming_dir( 'abc-def' ) . '/package.zip', $fs, 9 );

		$this->assertTrue( $result );
		$this->assertSame( 9, $http->requests[0]['args']['timeout'] );
	}

	// -- consent is read from the database at the point of sending --------

	public function test_no_request_leaves_the_client_without_consent_in_the_database(): void {
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( false ) );
		$http   = new Babilim_Fake_Http( array( $this->ok_response() ) );
		$client = $this->client( $http );

		$results = array(
			$client->register( array( 'home_url' => 'https://example.org' ) ),
			$client->verify( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a', 'b64:abc' ),
			$client->inventory( 'st_x', array( 'components' => array(), 'locales' => array() ) ),
			$client->download( 'bd_0123456789ab' ),
			$client->fetch_package( 'https://api.babilim.org/v1/packages/x', Babilim_Paths::incoming_dir( 'abc-def' ) . '/package.zip', new Babilim_Memory_Fs() ),
			$client->request_translation( array( 'kind' => 'plugin' ) ),
		);

		foreach ( $results as $result ) {
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'babilim_no_consent', $result->get_error_code() );
		}
		$this->assertCount( 0, $http->requests );
	}

	public function test_consent_withdrawn_after_the_object_cache_was_filled_still_stops_the_request(): void {
		// get_option() would still answer true from this process's cache;
		// the check must look at the database.
		$this->assertTrue( (bool) get_option( 'babilim_consent' ) );
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( 0 ) );
		$http = new Babilim_Fake_Http( array( $this->ok_response() ) );

		$result = $this->client( $http )->inventory( 'st_x', array( 'components' => array(), 'locales' => array() ) );

		$this->assertSame( 'babilim_no_consent', $result->get_error_code() );
		$this->assertCount( 0, $http->requests );
	}

	public function test_revoking_between_download_and_the_package_get_stops_the_get(): void {
		$http = new Babilim_Fake_Http(
			array(
				array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/bd_0123456789ab' ) ),
				array( 'response' => array( 'code' => 200 ), 'body' => 'zip', 'headers' => array() ),
			)
		);
		$client   = $this->client( $http );
		$download = $client->download( 'bd_0123456789ab' );
		$this->assertSame( '/v1/packages/bd_0123456789ab', $download['location'] );

		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( false ) );
		$fetch = $client->fetch_package( 'https://api.babilim.org/v1/packages/bd_0123456789ab', Babilim_Paths::incoming_dir( 'abc-def' ) . '/package.zip', new Babilim_Memory_Fs() );

		$this->assertTrue( is_wp_error( $fetch ) );
		$this->assertSame( 'babilim_no_consent', $fetch->get_error_code() );
		$this->assertCount( 1, $http->requests, 'only the POST was sent' );
	}

	public function test_meta_locales_goes_through_the_same_check(): void {
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( false ) );
		$http = new Babilim_Fake_Http( array( $this->ok_response() ) );

		$result = $this->client( $http, null )->meta_locales();

		$this->assertSame( 'babilim_no_consent', $result->get_error_code() );
		$this->assertCount( 0, $http->requests );
	}

	public function test_granted_db_normalizes_stored_types(): void {
		$consent = new Babilim_Consent();
		foreach ( array(
			array( serialize( true ), true ),
			array( serialize( 1 ), true ),
			array( serialize( '1' ), true ),
			array( serialize( false ), false ),
			array( serialize( 0 ), false ),
			array( serialize( '0' ), false ),
			array( serialize( '' ), false ),
			array( serialize( 'yes' ), false ),
			array( serialize( 2 ), false ),
			array( serialize( array( 1 ) ), false ),
		) as $case ) {
			$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', $case[0] );
			$this->assertSame( $case[1], $consent->granted_db(), $case[0] );
		}
	}

	public function test_granted_db_is_false_when_the_row_is_missing(): void {
		$GLOBALS['wpdb']->babilim_test_delete_row( 'babilim_consent' );
		delete_option( 'babilim_consent' );

		$this->assertFalse( ( new Babilim_Consent() )->granted_db() );
	}

	public function test_granted_db_is_false_when_there_is_no_database(): void {
		$saved = $GLOBALS['wpdb'];
		unset( $GLOBALS['wpdb'] );
		try {
			$this->assertFalse( ( new Babilim_Consent() )->granted_db() );
		} finally {
			$GLOBALS['wpdb'] = $saved;
		}
	}

	public function test_granted_db_reads_the_options_table_of_the_current_site(): void {
		delete_option( 'babilim_consent' );
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['wpdb']->options          = 'wp_2_options';
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( true ), 'wp_2_options' );
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( false ) );

		$this->assertTrue( ( new Babilim_Consent() )->granted_db() );

		$GLOBALS['wpdb']->options = 'wp_3_options';
		$this->assertFalse( ( new Babilim_Consent() )->granted_db() );
	}

	// -- sync(): budget, batches, and the last-error record --------------

	/**
	 * @param int $plugins   How many plugins the site has.
	 * @param int $languages How many managed languages it reports.
	 */
	private function sync_site( array $responses, int $plugins = 1, int $languages = 0 ): array {
		update_option( 'babilim_identity', array( 'site_id' => 'st_sync', 'token' => 'tok_sync' ), false );
		$list = array();
		for ( $i = 1; $i <= $plugins; $i++ ) {
			$list[ 'p' . $i . '/p' . $i . '.php' ] = array( 'Name' => 'P' . $i, 'Version' => '1.0', 'TextDomain' => 'p' . $i );
		}
		$inventory = new Babilim_Inventory(
			static function () use ( $list ) { return $list; },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		if ( $languages > 0 ) {
			$locales = array();
			for ( $i = 0; $i < $languages; $i++ ) {
				$locales[] = sprintf( 'qa_%03d', $i );
			}
			$inventory->set_manual_locales( $locales );
		}
		$http  = new Babilim_Fake_Http( $responses );
		$state = new Babilim_State();
		$site  = new Babilim_Site( $http, 'https://api.babilim.org', $state, $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );
		return array( $site, $http, $state );
	}

	private function inventory_ok(): array {
		return $this->ok_response(
			array(
				'components' => array(),
				'updates'    => array(),
				'license'    => array(),
				'quota'      => array(),
				'features'   => array(),
				'contract'   => '1.3.0',
			)
		);
	}

	private function error_response( int $status, string $code, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) wp_json_encode( array( 'error' => array( 'code' => $code, 'message' => 'm', 'hint' => 'h' ) ) ),
			'headers'  => $headers,
		);
	}

	public function test_a_zero_budget_sends_nothing_and_says_in_progress(): void {
		list( $site, $http ) = $this->sync_site( array( $this->inventory_ok() ) );

		$result = $site->sync( 0 );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'in_progress', $result->code() );
		$this->assertCount( 0, $http->requests );
	}

	public function test_each_inventory_request_gets_the_time_left_when_it_is_sent(): void {
		list( $site, $http ) = $this->sync_site( array( $this->inventory_ok() ), 160, 256 );
		$http2               = null;
		$now                 = &$this->now;
		$clock               = new Babilim_Test_Clock_Http( array( $this->inventory_ok() ), static function () use ( &$now ) { $now += 10; } );
		$reflect             = new ReflectionProperty( Babilim_Site::class, 'http' );
		$reflect->setAccessible( true );
		$reflect->setValue( $site, $clock );

		$result = $site->sync( 24 );

		// 24s budget, 10s per request: 15 (cap), 14, 4 -- then 0 left.
		$this->assertSame( array( 15, 14, 4 ), array_map( static function ( $r ) { return $r['args']['timeout']; }, $clock->requests ) );
		$this->assertTrue( $result->is_ok(), (string) $result->message() );
		unset( $http2 );
	}

	public function test_with_no_budget_each_inventory_request_still_has_its_own_cap(): void {
		list( $site, $http ) = $this->sync_site( array( $this->inventory_ok() ) );

		$this->assertTrue( $site->sync( null )->is_ok() );

		$this->assertSame( 15, $http->requests[0]['args']['timeout'] );
	}

	public function test_a_transient_failure_is_in_progress_and_an_explicit_refusal_keeps_its_code(): void {
		$cases = array(
			'transport error' => array( new WP_Error( 'http_request_failed', 'cURL error 28' ), 'in_progress' ),
			'server error'    => array( $this->error_response( 503, 'service_unavailable' ), 'in_progress' ),
			'rate limited'    => array( $this->error_response( 429, 'rate_limited', array( 'retry-after' => '30' ) ), 'in_progress' ),
			'explicit'        => array( $this->error_response( 403, 'forbidden' ), 'forbidden' ),
		);
		foreach ( $cases as $name => $case ) {
			babilim_test_reset();
			update_option( 'babilim_consent', true );
			list( $site ) = $this->sync_site( array( $case[0] ) );

			$result = $site->sync( 45 );

			$this->assertSame( $case[1], $result->code(), $name );
			$this->assertSame( $case[1], get_option( 'babilim_sync_last_error' )['code'], $name . ' is what gets recorded' );
		}
	}

	public function test_a_run_that_stops_halfway_stores_no_state_and_the_rerun_sends_every_batch_again(): void {
		list( $site, $http, $state ) = $this->sync_site(
			array( $this->inventory_ok(), new WP_Error( 'http_request_failed', 'timeout' ), $this->inventory_ok(), $this->inventory_ok(), $this->inventory_ok() ),
			160,
			256
		);

		$first = $site->sync( 45 );

		$this->assertSame( 'in_progress', $first->code() );
		$this->assertCount( 2, $http->requests, 'the first batch went, the second failed' );
		$this->assertArrayNotHasKey( 'fetched_at', $state->get(), 'nothing from the half run is kept' );
		$this->assertNotFalse( get_option( 'babilim_sync_last_error', false ) );

		$second = $site->sync( 45 );

		$this->assertTrue( $second->is_ok(), (string) $second->message() );
		$this->assertCount( 5, $http->requests, 'all three batches were sent again' );
		$first_bodies = json_decode( $http->requests[0]['args']['body'], true );
		$rerun_bodies = json_decode( $http->requests[2]['args']['body'], true );
		$this->assertSame( $first_bodies['components'], $rerun_bodies['components'], 'the first batch again, not the one after it' );
		$this->assertArrayHasKey( 'fetched_at', $state->get() );
		$this->assertFalse( get_option( 'babilim_sync_last_error', false ), 'success removes the record' );
	}

	public function test_a_site_with_nothing_to_send_records_that_the_check_finished(): void {
		$GLOBALS['babilim_test_site_locale'] = '';
		list( $site, $http, $state ) = $this->sync_site( array( $this->inventory_ok() ), 1 );

		$result = $site->sync( 45 );

		$this->assertTrue( $result->is_ok(), (string) $result->message() );
		$this->assertCount( 0, $http->requests, 'no languages, nothing sent' );
		$this->assertArrayHasKey( 'fetched_at', $state->get(), 'so a page load does not check again' );
	}

	public function test_more_batches_than_one_run_can_send_is_reported_with_zero_requests(): void {
		list( $site, $http ) = $this->sync_site( array( $this->inventory_ok() ), 600, 256 );

		$result = $site->sync( 120 );

		$this->assertSame( 'too_many_components', $result->code() );
		$this->assertSame(
			'This site has 600 components across 256 languages, which needs 8 check batches; Babilim can handle at most 7 per run. Reduce the managed languages in Settings or deactivate unused plugins and themes, then press Check again now.',
			$result->message()
		);
		$this->assertCount( 0, $http->requests );
		$this->assertSame( 'too_many_components', get_option( 'babilim_sync_last_error' )['code'] );
	}

	public function test_seven_batches_are_still_sent(): void {
		list( $site, $http ) = $this->sync_site( array( $this->inventory_ok() ), 7 * 78, 256 );

		$this->assertTrue( $site->sync( 120 )->is_ok() );

		$this->assertCount( 7, $http->requests );
	}

	public function test_persistent_failure_keeps_since_and_moves_at_and_start_over_resets_it(): void {
		update_option(
			'babilim_sync_last_error',
			array( 'code' => 'in_progress', 'message' => 'old', 'since' => '2026-01-01T00:00:00+00:00', 'at' => '2026-01-01T00:00:00+00:00' ),
			false
		);
		list( $site ) = $this->sync_site( array( new WP_Error( 'http_request_failed', 'timeout again' ) ) );

		$site->sync( 45 );

		$stored = get_option( 'babilim_sync_last_error' );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $stored['since'], 'the first time stays' );
		$this->assertNotSame( '2026-01-01T00:00:00+00:00', $stored['at'], 'the latest attempt moves' );
		$this->assertSame( 'timeout again', $stored['message'] );

		delete_option( 'babilim_sync_last_error' ); // "Start over".
		$site->sync( 45 );

		$fresh = get_option( 'babilim_sync_last_error' );
		$this->assertNotSame( '2026-01-01T00:00:00+00:00', $fresh['since'] );
		$this->assertSame( $fresh['since'], $fresh['at'], 'a new run of failures starts now' );
	}

	public function test_without_consent_sync_sends_nothing_and_records_nothing(): void {
		list( $site, $http ) = $this->sync_site( array( $this->inventory_ok() ) );
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( false ) );

		$result = $site->sync( 45 );

		$this->assertSame( 'no_consent', $result->code() );
		$this->assertCount( 0, $http->requests );
		$this->assertFalse( get_option( 'babilim_sync_last_error', false ) );
	}

	public function test_registration_checks_consent_in_the_database_not_the_object_cache(): void {
		list( $site, $http ) = $this->sync_site( array( $this->inventory_ok() ) );
		delete_option( 'babilim_identity' );
		$this->assertTrue( (bool) get_option( 'babilim_consent' ), 'this request still has consent cached' );
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( false ) );

		$result = $site->ensure_registered();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_no_consent', $result->get_error_code() );
		$this->assertFalse( get_option( 'babilim_install_sk', false ), 'no key pair was made for a site that has withdrawn consent' );
		$this->assertCount( 0, $http->requests );
	}

	public function test_a_server_error_during_a_budgeted_sync_is_not_sent_a_second_time(): void {
		update_option( 'babilim_identity', array( 'site_id' => 'st_sync', 'token' => 'tok_sync' ), false );
		$GLOBALS['babilim_test_wp_remote_responses'] = array( $this->error_response( 503, 'service_unavailable' ), $this->inventory_ok() );
		$GLOBALS['babilim_test_wp_remote_requests']  = array();
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$site = new Babilim_Site( new Babilim_Wp_Http(), 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );

		$result = $site->sync( 45 );

		$this->assertSame( 'in_progress', $result->code() );
		$this->assertCount( 1, $GLOBALS['babilim_test_wp_remote_requests'], 'no resend inside a budget: the second attempt would spend time the deadline no longer has' );
		$this->assertSame( 15, $GLOBALS['babilim_test_wp_remote_requests'][0]['args']['timeout'] );
	}

	public function test_without_a_budget_the_inventory_request_may_still_be_retried_once(): void {
		update_option( 'babilim_identity', array( 'site_id' => 'st_sync', 'token' => 'tok_sync' ), false );
		$GLOBALS['babilim_test_wp_remote_responses'] = array( $this->error_response( 503, 'service_unavailable' ), $this->inventory_ok() );
		$GLOBALS['babilim_test_wp_remote_requests']  = array();
		$client = new Babilim_Api_Client( new Babilim_Wp_Http(), 'https://api.babilim.org', 'tok_sync' );

		$result = $client->inventory( 'st_sync', array( 'components' => array(), 'locales' => array() ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 2, $GLOBALS['babilim_test_wp_remote_requests'] );
	}
}
