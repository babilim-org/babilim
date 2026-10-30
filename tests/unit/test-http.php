<?php
/**
 * Tests for Babilim_Wp_Http -- the one production Babilim_Http
 * implementation, wrapping wp_remote_request().
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Http extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	private function queue_response( array $response ): void {
		$GLOBALS['babilim_test_wp_remote_responses'][] = $response;
	}

	private function ok_response( int $code = 200, string $body = '{}' ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
			'headers'  => array(),
		);
	}

	public function test_sends_method_and_default_timeout(): void {
		$this->queue_response( $this->ok_response() );

		( new Babilim_Wp_Http() )->request( 'POST', 'https://api.babilim.org/v1/health', array( 'body' => '{}' ) );

		$call = $GLOBALS['babilim_test_wp_remote_requests'][0];
		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( 'https://api.babilim.org/v1/health', $call['url'] );
		$this->assertSame( 20, $call['args']['timeout'] );
	}

	public function test_does_not_override_an_explicit_timeout(): void {
		$this->queue_response( $this->ok_response() );

		( new Babilim_Wp_Http() )->request( 'GET', 'https://api.babilim.org/v1/health', array( 'timeout' => 5 ) );

		$this->assertSame( 5, $GLOBALS['babilim_test_wp_remote_requests'][0]['args']['timeout'] );
	}

	public function test_retries_once_on_a_5xx_response_when_the_caller_opts_in(): void {
		$this->queue_response( $this->ok_response( 503, '{"error":{"code":"unavailable","message":"x","hint":""}}' ) );
		$this->queue_response( $this->ok_response( 200, '{"ok":true}' ) );

		$result = ( new Babilim_Wp_Http() )->request( 'GET', 'https://api.babilim.org/v1/health', array( 'retry' => true ) );

		$this->assertCount( 2, $GLOBALS['babilim_test_wp_remote_requests'] );
		$this->assertSame( '{"ok":true}', wp_remote_retrieve_body( $result ) );
	}

	public function test_retries_at_most_once_on_repeated_5xx_when_the_caller_opts_in(): void {
		$this->queue_response( $this->ok_response( 500 ) );
		$this->queue_response( $this->ok_response( 500 ) );

		( new Babilim_Wp_Http() )->request( 'GET', 'https://api.babilim.org/v1/health', array( 'retry' => true ) );

		$this->assertCount( 2, $GLOBALS['babilim_test_wp_remote_requests'] );
	}

	/**
	 * The default -- no `retry` key at all -- must NOT retry: a
	 * non-idempotent call like register()/verify() never opts in (see
	 * Babilim_Api_Client), and a retry after the service already
	 * processed the first attempt could create a duplicate site or land
	 * on an already-consumed nonce.
	 */
	public function test_does_not_retry_a_5xx_response_by_default(): void {
		$this->queue_response( $this->ok_response( 503 ) );

		( new Babilim_Wp_Http() )->request( 'POST', 'https://api.babilim.org/v1/sites', array() );

		$this->assertCount( 1, $GLOBALS['babilim_test_wp_remote_requests'] );
	}

	public function test_does_not_retry_a_5xx_response_when_the_caller_explicitly_declines(): void {
		$this->queue_response( $this->ok_response( 503 ) );

		( new Babilim_Wp_Http() )->request( 'POST', 'https://api.babilim.org/v1/sites', array( 'retry' => false ) );

		$this->assertCount( 1, $GLOBALS['babilim_test_wp_remote_requests'] );
	}

	public function test_does_not_retry_a_4xx_response(): void {
		$this->queue_response( $this->ok_response( 404 ) );

		( new Babilim_Wp_Http() )->request( 'GET', 'https://api.babilim.org/v1/health', array() );

		$this->assertCount( 1, $GLOBALS['babilim_test_wp_remote_requests'] );
	}

	public function test_does_not_retry_a_transport_level_error(): void {
		$GLOBALS['babilim_test_wp_remote_responses'][] = new WP_Error( 'http_request_failed', 'Connection refused' );

		$result = ( new Babilim_Wp_Http() )->request( 'GET', 'https://api.babilim.org/v1/health', array() );

		$this->assertCount( 1, $GLOBALS['babilim_test_wp_remote_requests'] );
		$this->assertTrue( is_wp_error( $result ) );
	}
}
