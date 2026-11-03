<?php
/**
 * Babilim_Site::sync() sends consented error reports after the inventory,
 * and Babilim_Api_Client::error_reports() posts them.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Error_Reporting_Send extends TestCase {

	private const EVENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	protected function setUp(): void {
		babilim_test_reset();
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
	}

	private function json_response( int $code, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}

	private function inventory_ok(): array {
		return $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.9.0' ) );
	}

	private function make_site( array $responses ): array {
		$http      = new Babilim_Fake_Http( $responses );
		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$site      = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );
		return array( $site, $http );
	}

	private function queue_event( string $id = self::EVENT_ID, int $attempts = 0 ): void {
		$queue   = (array) get_option( Babilim_Error_Reporter::OPTION_QUEUE, array() );
		$queue[] = array(
			'event_id'   => $id,
			'error_code' => 'download_failed',
			'action'     => 'download',
			'origin_id'  => 'site.sync',
			'at'         => gmdate( 'c' ),
			'attempts'   => $attempts,
		);
		update_option( Babilim_Error_Reporter::OPTION_QUEUE, $queue, false );
	}

	private function report_requests( Babilim_Fake_Http $http ): array {
		return array_values(
			array_filter(
				$http->requests,
				static function ( array $r ): bool {
					return false !== strpos( $r['url'], '/v1/error-reports' );
				}
			)
		);
	}

	public function test_sync_sends_pending_error_reports_after_inventory_and_marks_sent(): void {
		$this->queue_event();
		list( $site, $http ) = $this->make_site( array( $this->inventory_ok(), $this->json_response( 202, array( 'accepted' => 1, 'ignored' => 0 ) ) ) );

		$result = $site->sync();

		$this->assertTrue( $result->is_ok() );
		$this->assertCount( 2, $http->requests );
		$this->assertStringContainsString( '/inventory', $http->requests[0]['url'] );
		$this->assertStringContainsString( '/v1/error-reports', $http->requests[1]['url'] );
		$this->assertSame( 'POST', $http->requests[1]['method'] );
		$raw  = $http->requests[1]['args']['body'];
		$body = json_decode( $raw, true );
		$this->assertSame( BABILIM_VERSION, $body['plugin_version'] );
		$this->assertSame( PHP_VERSION, $body['php_version'] );
		$this->assertArrayHasKey( 'locale', $body );
		$this->assertCount( 1, $body['events'] );
		$this->assertSame( array( 'action', 'at', 'error_code', 'event_id', 'origin_id' ), array_keys( $this->sorted( $body['events'][0] ) ) );
		$this->assertStringNotContainsString( 'attempts', $raw );
		$this->assertStringNotContainsString( 'home_url', $raw );
		$this->assertStringNotContainsString( 'example', $raw );
		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_QUEUE ), 'an accepted event leaves the queue' );
	}

	private function sorted( array $a ): array {
		ksort( $a );
		return $a;
	}

	public function test_sync_marks_failed_on_5xx_without_new_capture(): void {
		$this->queue_event();
		list( $site, $http ) = $this->make_site( array( $this->inventory_ok(), $this->json_response( 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'x', 'hint' => '' ) ) ) ) );

		$result = $site->sync();

		$this->assertTrue( $result->is_ok(), 'a failed report must not change the sync result' );
		$this->assertCount( 1, $this->report_requests( $http ), 'no retry within the request' );
		$queue = get_option( Babilim_Error_Reporter::OPTION_QUEUE );
		$this->assertCount( 1, $queue );
		$this->assertSame( 1, $queue[0]['attempts'] );
		$this->assertSame( array(), (array) get_option( Babilim_Error_Reporter::OPTION_AWAITING, array() ), 'no new event captured' );
		$this->assertSame( array(), (new Babilim_State())->pending_client_events(), 'no client event recorded' );
	}

	public function test_sync_marks_failed_on_a_4xx_and_on_a_transport_error(): void {
		$this->queue_event();
		list( $site ) = $this->make_site( array( $this->inventory_ok(), $this->json_response( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'x', 'hint' => '' ) ), array( 'retry-after' => '30' ) ) ) );
		$site->sync();
		$this->assertSame( 1, get_option( Babilim_Error_Reporter::OPTION_QUEUE )[0]['attempts'] );

		list( $site2 ) = $this->make_site( array( $this->inventory_ok(), new WP_Error( 'http_request_failed', 'down' ) ) );
		$site2->sync();
		$this->assertSame( 2, get_option( Babilim_Error_Reporter::OPTION_QUEUE )[0]['attempts'] );
		$this->assertSame( array(), (array) get_option( Babilim_Error_Reporter::OPTION_AWAITING, array() ) );
	}

	public function test_sync_marks_failed_on_a_422_answer(): void {
		$this->queue_event();
		list( $site, $http ) = $this->make_site( array( $this->inventory_ok(), $this->json_response( 422, array( 'error' => array( 'code' => 'unprocessable', 'message' => 'x', 'hint' => '' ) ) ) ) );

		$result = $site->sync();

		$this->assertTrue( $result->is_ok() );
		$this->assertCount( 1, $this->report_requests( $http ) );
		$this->assertSame( 1, get_option( Babilim_Error_Reporter::OPTION_QUEUE )[0]['attempts'] );
	}

	public function test_sync_sends_nothing_when_queue_empty(): void {
		list( $site, $http ) = $this->make_site( array( $this->inventory_ok() ) );

		$site->sync();

		$this->assertCount( 0, $this->report_requests( $http ) );
	}

	public function test_sync_does_not_send_events_that_still_await_consent(): void {
		update_option(
			Babilim_Error_Reporter::OPTION_AWAITING,
			array( array( 'event_id' => self::EVENT_ID, 'error_code' => 'download_failed', 'action' => 'download', 'origin_id' => 'site.sync', 'at' => gmdate( 'c' ), 'attempts' => 0 ) ),
			false
		);
		list( $site, $http ) = $this->make_site( array( $this->inventory_ok() ) );

		$site->sync();

		$this->assertCount( 0, $this->report_requests( $http ) );
	}

	public function test_sync_sends_nothing_when_the_inventory_failed(): void {
		$this->queue_event();
		list( $site, $http ) = $this->make_site( array( $this->json_response( 500, array( 'error' => array( 'code' => 'internal', 'message' => 'x', 'hint' => '' ) ) ) ) );

		$site->sync();

		$this->assertCount( 0, $this->report_requests( $http ) );
	}

	public function test_send_drops_an_event_whose_field_is_over_the_contract_limit(): void {
		$this->queue_event( self::EVENT_ID );
		$queue                          = get_option( Babilim_Error_Reporter::OPTION_QUEUE );
		$queue[0]['origin_id']          = str_repeat( 'x', Babilim_Contract_Limits::ERROR_ORIGIN + 1 );
		$queue[]                        = array_merge( $queue[0], array( 'event_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'origin_id' => 'site.sync' ) );
		update_option( Babilim_Error_Reporter::OPTION_QUEUE, $queue, false );
		list( $site, $http ) = $this->make_site( array( $this->inventory_ok(), $this->json_response( 202, array( 'accepted' => 1, 'ignored' => 0 ) ) ) );

		$site->sync();

		$body = json_decode( $this->report_requests( $http )[0]['args']['body'], true );
		$this->assertSame( array( 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ), array_column( $body['events'], 'event_id' ) );
		$left = array_column( get_option( Babilim_Error_Reporter::OPTION_QUEUE ), 'event_id' );
		$this->assertNotContains( self::EVENT_ID, $left, 'an unsendable event is discarded, not retried forever' );
	}

	public function test_error_reports_client_posts_to_the_versioned_path_with_the_token(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 202, array( 'accepted' => 0, 'ignored' => 0 ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$client->error_reports( array( 'plugin_version' => '1.1.0', 'events' => array() ) );

		$this->assertSame( 'POST', $http->requests[0]['method'] );
		$this->assertSame( 'https://api.babilim.org/v1/error-reports', $http->requests[0]['url'] );
		$this->assertStringContainsString( 'tok_x', wp_json_encode( $http->requests[0]['args']['headers'] ) );
	}
}
