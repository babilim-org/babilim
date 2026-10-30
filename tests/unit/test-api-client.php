<?php
/**
 * Tests for Babilim_Api_Client -- request shape, auth headers, and
 * response/error mapping against the pinned contract's own examples
 * (tests/contract/v1/examples/). Every test injects a fake Babilim_Http
 * that just records what it was asked to send and returns a
 * caller-configured wp_remote_request()-shaped array -- no real network
 * call is ever made.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Babilim_Fake_Http (records every request, returns queued responses --
// exactly like the fake $get_plugins/$get_themes callables this codebase
// already uses elsewhere for isolating a class from WordPress) is a
// shared fixture now: tests/fixtures/class-babilim-fake-http.php, loaded
// by tests/bootstrap.php -- see that fixture's own docblock for why it
// no longer lives only here.

final class Test_Api_Client extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		// transport() refuses to send without consent.
		update_option( 'babilim_consent', true );
	}

	private function json_response( int $code, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}

	public function test_register_sends_no_authorization_header(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 201, array( 'site_id' => 'st_x', 'challenge' => array( 'nonce' => 'a', 'expires_at' => 'x' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$client->register( array( 'home_url' => 'https://example.org' ) );

		$this->assertArrayNotHasKey( 'Authorization', $http->requests[0]['args']['headers'] );
		$this->assertSame( 'https://api.babilim.org/v1/sites', $http->requests[0]['url'] );
	}

	/**
	 * register() must never send Bearer auth, even when the client it's
	 * called through happens to have a token available -- unlike the
	 * sibling test above (which proves nothing about this, since that
	 * client has no token to send in the first place).
	 */
	public function test_register_sends_no_authorization_header_even_when_a_token_is_available(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 201, array( 'site_id' => 'st_x', 'challenge' => array( 'nonce' => 'a', 'expires_at' => 'x' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_available' );

		$client->register( array( 'home_url' => 'https://example.org' ) );

		$this->assertArrayNotHasKey( 'Authorization', $http->requests[0]['args']['headers'] );
	}

	/**
	 * Non-idempotent calls must never opt into Babilim_Http's retry (see
	 * that interface's own docblock) -- a retry after the service already
	 * processed a register() could create a duplicate site.
	 */
	public function test_register_does_not_mark_its_request_as_retryable(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 201, array( 'site_id' => 'st_x', 'challenge' => array( 'nonce' => 'a', 'expires_at' => 'x' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$client->register( array( 'home_url' => 'https://example.org' ) );

		$this->assertFalse( $http->requests[0]['args']['retry'] ?? false );
	}

	// -- rotate_token() -- POST /v1/sites/{id}/rotate-token ---------------

	public function test_rotate_token_sends_to_the_expected_path_with_bearer_auth(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'site_token' => 'tok_new', 'rotated_at' => '2026-01-01T00:00:00Z' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_old' );

		$result = $client->rotate_token( 'st_x' );

		$this->assertSame( 'https://api.babilim.org/v1/sites/st_x/rotate-token', $http->requests[0]['url'] );
		$this->assertSame( 'Bearer tok_old', $http->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'tok_new', $result['site_token'] );
	}

	/**
	 * The contract's own request schema (sites.rotate-token.request.
	 * schema.json) is `"type":"object"` -- `wp_json_encode( array() )`
	 * produces the JSON array literal `[]`, not the empty object `{}`,
	 * which a real server (pydantic on the FastAPI side) rejects
	 * outright. Asserted against the RAW body string, not
	 * `json_decode(..., true)`, which would silently turn both `[]` and
	 * `{}` into the same PHP `array()` and hide the bug.
	 */
	public function test_rotate_token_sends_a_json_object_body_not_an_array(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'site_token' => 'tok_new', 'rotated_at' => 'x' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_old' );

		$client->rotate_token( 'st_x' );

		$this->assertSame( '{}', $http->requests[0]['args']['body'] );
	}

	/**
	 * Non-idempotent (rotating twice would issue two different tokens,
	 * invalidating the first) -- same rationale as register()/verify().
	 */
	public function test_rotate_token_does_not_mark_its_request_as_retryable(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'site_token' => 'tok_new', 'rotated_at' => 'x' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_old' );

		$client->rotate_token( 'st_x' );

		$this->assertFalse( $http->requests[0]['args']['retry'] ?? false );
	}

	public function test_verify_does_not_mark_its_request_as_retryable(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'site_token' => 'tok_x', 'verified' => true, 'license' => array(), 'quota' => array() ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$client->verify( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a', 'b64:x' );

		$this->assertFalse( $http->requests[0]['args']['retry'] ?? false );
	}

	/**
	 * Inventory sync is safely re-sendable (the service just recomputes
	 * the same status from the same snapshot), unlike register()/verify().
	 */
	public function test_inventory_marks_its_request_as_retryable(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$client->inventory( 'st_x', array( 'components' => array(), 'locales' => array( 'de_DE' ) ) );

		$this->assertTrue( $http->requests[0]['args']['retry'] ?? false );
	}

	public function test_register_returns_decoded_body_on_201(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 201, array( 'site_id' => 'st_3f9c2a1b7e4d', 'challenge' => array( 'nonce' => 'n', 'expires_at' => 'e' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$result = $client->register( array( 'home_url' => 'https://example.org' ) );

		$this->assertSame( 'st_3f9c2a1b7e4d', $result['site_id'] );
	}

	public function test_authenticated_calls_send_bearer_token(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_abc' );

		$client->inventory( 'st_x', array( 'components' => array(), 'locales' => array( 'de_DE' ) ) );

		$this->assertSame( 'Bearer tok_abc', $http->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_verify_sends_no_authorization_header(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'site_token' => 'tok_x', 'verified' => true, 'license' => array(), 'quota' => array() ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$client->verify( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a', 'b64:x' );

		$this->assertArrayNotHasKey( 'Authorization', $http->requests[0]['args']['headers'] );
	}

	// -- meta_locales() -- GET /v1/meta/locales ----------------------------

	public function test_meta_locales_sends_a_get_with_no_authorization_header(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( array( 'wp_locale' => 'de_DE', 'english_name' => 'German', 'native_name' => 'Deutsch' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->meta_locales();

		$this->assertSame( 'GET', $http->requests[0]['method'] );
		$this->assertSame( 'https://api.babilim.org/v1/meta/locales', $http->requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $http->requests[0]['args']['headers'] );
		$this->assertSame( 'de_DE', $result[0]['wp_locale'] );
	}

	public function test_meta_locales_maps_a_non_2xx_response_to_a_wp_error(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 500, array( 'error' => array( 'code' => 'unexpected_response', 'message' => 'boom', 'hint' => '' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$result = $client->meta_locales();

		$this->assertTrue( is_wp_error( $result ) );
	}

	/**
	 * When the service's own response carries neither a `{error:{code,
	 * message}}` shape nor a 2xx/302 status (a response this plugin's
	 * own contract doesn't expect at all, e.g. a proxy's plain-text 502),
	 * error_from_response() falls back to its own message -- which must
	 * be the translated `Unexpected HTTP %d response.` string, not a
	 * bare English literal forwarded as-is. Guards the one message this
	 * class builds and assigns to a variable before the WP_Error
	 * constructor call, which the static i18n scanner in Test_I18n
	 * (which only inspects the literal argument tokens at a
	 * `Babilim_Result::error()`/`new WP_Error()` call site, not values
	 * assigned earlier and forwarded in as a variable) cannot see back
	 * into.
	 */
	public function test_error_from_response_falls_back_to_a_translated_message_when_the_body_has_no_error_shape(): void {
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 502 ), 'body' => 'Bad Gateway', 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$result = $client->meta_locales();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_unexpected_response', $result->get_error_code() );
		$this->assertSame( 'Unexpected HTTP 502 response.', $result->get_error_message() );
	}

	public function test_429_maps_to_rate_limited_with_retry_after(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'Too many requests.', 'hint' => '' ) ), array( 'Retry-After' => '37' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->inventory( 'st_x', array( 'components' => array(), 'locales' => array( 'de_DE' ) ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_rate_limited', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'retry_after', $data, 'a 429 must always carry retry_after' );
		$this->assertSame( 37, $data['retry_after'] );
	}

	public function test_426_maps_to_plugin_outdated(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 426, array( 'error' => array( 'code' => 'plugin_outdated', 'message' => 'x', 'hint' => 'y' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->inventory( 'st_x', array( 'components' => array(), 'locales' => array( 'de_DE' ) ) );

		$this->assertSame( 'babilim_plugin_outdated', $result->get_error_code() );
	}

	public function test_download_404_maps_to_not_available(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 404, array( 'error' => array( 'code' => 'not_available', 'message' => 'x', 'hint' => '' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->download( 'bd_deadbeefdead' );

		$this->assertSame( 'babilim_not_available', $result->get_error_code() );
	}

	public function test_download_302_returns_location(): void {
		$http = new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 302 ),
					'body'     => '',
					'headers'  => array( 'Location' => '/v1/packages/bd_x?sig=y' ),
				),
			)
		);
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->download( 'bd_9e8d7c6b5a4f' );

		$this->assertSame( array( 'location' => '/v1/packages/bd_x?sig=y' ), $result );
		$this->assertSame( 0, $http->requests[0]['args']['redirection'] );
	}

	// -- resolve_location() -- shared by do_download() and Babilim_Sync_Installer --

	public function test_resolve_location_resolves_a_relative_path_against_the_base(): void {
		$this->assertSame(
			'https://api.babilim.org/v1/packages/bd_x?sig=y',
			Babilim_Api_Client::resolve_location( '/v1/packages/bd_x?sig=y', 'https://api.babilim.org' )
		);
	}

	public function test_resolve_location_accepts_a_same_origin_absolute_url_unchanged(): void {
		$this->assertSame(
			'https://api.babilim.org/v1/packages/bd_x?sig=y',
			Babilim_Api_Client::resolve_location( 'https://api.babilim.org/v1/packages/bd_x?sig=y', 'https://api.babilim.org' )
		);
	}

	/**
	 * The contract defines Location as relative to the API base -- an
	 * absolute URL pointing at a DIFFERENT origin is never something a
	 * well-behaved service response would ever send, so it's treated as
	 * untrusted (never resolved to a fetchable URL) rather than followed
	 * -- otherwise a compromised or misconfigured response could redirect
	 * fetch_package()'s own download to an arbitrary host.
	 */
	public function test_resolve_location_rejects_a_different_origin_absolute_url(): void {
		$this->assertSame( '', Babilim_Api_Client::resolve_location( 'https://evil.example/v1/packages/bd_x', 'https://api.babilim.org' ) );
	}

	public function test_resolve_location_rejects_a_different_scheme_on_the_same_host(): void {
		$this->assertSame( '', Babilim_Api_Client::resolve_location( 'http://api.babilim.org/v1/packages/bd_x', 'https://api.babilim.org' ) );
	}

	public function test_resolve_location_rejects_a_different_port_on_the_same_host(): void {
		$this->assertSame( '', Babilim_Api_Client::resolve_location( 'https://api.babilim.org:8443/v1/packages/bd_x', 'https://api.babilim.org' ) );
	}

	public function test_resolve_location_returns_empty_for_an_empty_location(): void {
		$this->assertSame( '', Babilim_Api_Client::resolve_location( '', 'https://api.babilim.org' ) );
	}

	public function test_fetch_package_returns_true_on_200_and_writes_the_body_through_babilim_fs(): void {
		$dest   = Babilim_Paths::incoming_dir( 'test-token' ) . '/package.zip';
		$fs     = new Babilim_Memory_Fs();
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => 'zip-bytes', 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', $dest, $fs );

		$this->assertTrue( $result );
		$this->assertSame( 'zip-bytes', $fs->get( $dest ) );
		// Never the raw stream/filename side channel that bypasses
		// WP_Filesystem -- see the class docblock.
		$this->assertArrayNotHasKey( 'stream', $http->requests[0]['args'] );
		$this->assertArrayNotHasKey( 'filename', $http->requests[0]['args'] );
	}

	/**
	 * A GET has no side effect on the service, so it's safe to retry once
	 * on a 5xx -- unlike register()/verify() (see Babilim_Http's own
	 * docblock).
	 */
	public function test_fetch_package_marks_its_request_as_retryable(): void {
		$dest   = Babilim_Paths::incoming_dir( 'test-token' ) . '/package.zip';
		$fs     = new Babilim_Memory_Fs();
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', $dest, $fs );

		$this->assertTrue( $http->requests[0]['args']['retry'] ?? false );
	}

	/**
	 * A defensive ceiling on the buffered response size -- fetch_package()
	 * buffers the whole body in memory before writing it through
	 * Babilim_Fs (see that method's own docblock), so an unbounded
	 * response (a misbehaving/compromised server, or a signed link that
	 * ends up pointed somewhere else) shouldn't be able to exhaust memory.
	 */
	public function test_fetch_package_sets_a_response_size_limit(): void {
		$dest   = Babilim_Paths::incoming_dir( 'test-token' ) . '/package.zip';
		$fs     = new Babilim_Memory_Fs();
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', $dest, $fs );

		$this->assertArrayHasKey( 'limit_response_size', $http->requests[0]['args'] );
		$this->assertGreaterThan( 0, $http->requests[0]['args']['limit_response_size'] );
	}

	/**
	 * The packages endpoint answers with the zip itself, so a redirect is
	 * never followed (same as download()).
	 */
	public function test_fetch_package_never_follows_redirects(): void {
		$dest   = Babilim_Paths::incoming_dir( 'test-token' ) . '/package.zip';
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', $dest, new Babilim_Memory_Fs() );

		$this->assertSame( 0, $http->requests[0]['args']['redirection'] );
	}

	/**
	 * fetch_package() writes to a caller-supplied path through
	 * WP_Filesystem, and only inside WP_LANG_DIR/babilim/ -- a
	 * destination outside that directory must be refused before ever
	 * making the request.
	 */
	public function test_fetch_package_refuses_a_destination_outside_the_lang_dir_allow_list(): void {
		$http   = new Babilim_Fake_Http( array() );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', '/tmp/babilim-outside-allow-list.zip', new Babilim_Memory_Fs() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_invalid_destination', $result->get_error_code() );
		$this->assertCount( 0, $http->requests, 'must refuse before ever making the request' );
	}

	/**
	 * A destination that is under the allow-list root but not inside the
	 * one area that accepts an arbitrary extension (`_incoming/{token}/`)
	 * must also be refused -- a raw `.zip` is never a form a served
	 * directory allows.
	 *
	 * @dataProvider disallowedDestinationProvider
	 */
	public function test_fetch_package_refuses_a_destination_the_allow_list_does_not_recognize( string $dest ): void {
		$http   = new Babilim_Fake_Http( array() );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', $dest, new Babilim_Memory_Fs() );

		$this->assertTrue( is_wp_error( $result ), "expected a WP_Error for destination: {$dest}" );
		$this->assertSame( 'babilim_invalid_destination', $result->get_error_code() );
		$this->assertCount( 0, $http->requests, 'must refuse before ever making the request' );
	}

	public function disallowedDestinationProvider(): array {
		$root = Babilim_Paths::root();
		return array(
			'dot-dot segments escaping the root' => array( $root . '/../../../../tmp/evil' ),
			'dot-dot segment right after the root' => array( $root . '/../evil.zip' ),
			'NUL byte' => array( $root . "/_incoming/x/evil.zip\0.php" ),
			'a raw .zip directly at root, not under _incoming' => array( $root . '/babilim-test-fetch.zip' ),
		);
	}

	public function test_fetch_package_allows_a_destination_under_the_incoming_staging_area(): void {
		$dest   = Babilim_Paths::incoming_dir( 'test-token' ) . '/package..name.zip';
		$fs     = new Babilim_Memory_Fs();
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', $dest, $fs );

		$this->assertTrue( $result );
	}

	public function test_fetch_package_maps_410_and_never_writes_the_error_body_to_disk(): void {
		$dest   = Babilim_Paths::incoming_dir( 'test-token' ) . '/package.zip';
		$fs     = new Babilim_Memory_Fs();
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 410 ), 'body' => wp_json_encode( array( 'error' => array( 'code' => 'expired', 'message' => 'x', 'hint' => 'y' ) ) ), 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', $dest, $fs );

		$this->assertSame( 'babilim_expired', $result->get_error_code() );
		$this->assertNull( $fs->get( $dest ) );
	}

	public function test_fetch_package_returns_a_write_failed_error_when_babilim_fs_put_fails(): void {
		$dest   = Babilim_Paths::incoming_dir( 'test-token' ) . '/package.zip';
		$fs     = new Babilim_Test_Failing_Put_Api_Fs();
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => 'x', 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->fetch_package( 'https://api.babilim.org/v1/packages/bd_x', $dest, $fs );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_write_failed', $result->get_error_code() );
	}

	public function test_feedback_returns_feedback_id_on_201(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 201, array( 'feedback_id' => 'fb_6c9e2a5d8f1b' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->feedback( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE', 'build_id' => 'bd_a1b2c3d4e5f6', 'category' => 'mistranslation' ) );

		$this->assertSame( 'fb_6c9e2a5d8f1b', $result['feedback_id'] );
	}

	public function test_transport_error_is_returned_unchanged(): void {
		$http   = new Babilim_Fake_Http( array( new WP_Error( 'http_request_failed', 'timed out' ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->inventory( 'st_x', array( 'components' => array(), 'locales' => array( 'de_DE' ) ) );

		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_sends_content_type_and_user_agent(): void {
		$GLOBALS['babilim_test_home_url'] = 'https://example.org';
		$http   = new Babilim_Fake_Http( array( $this->json_response( 201, array( 'feedback_id' => 'fb_x' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$client->feedback( array() );

		$headers = $http->requests[0]['args']['headers'];
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertStringContainsString( 'https://example.org', $headers['User-Agent'] );
		$this->assertStringStartsWith( 'Babilim/', $headers['User-Agent'] );
	}

	private function assertRequestPayloadMatchesSchema( string $raw_json ): void {
		$errors = Babilim_Schema_Validator::validate(
			json_decode( $raw_json ),
			json_decode( (string) file_get_contents( __DIR__ . '/../contract/v1/schemas/requests.request.schema.json' ), true )
		);
		$this->assertSame( array(), $errors, implode( "\n", $errors ) );
	}

	public function test_upload_part_sends_raw_bytes_with_octet_stream_and_no_retry(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'n' => 2, 'size' => 3 ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		update_option( 'babilim_consent', true );

		$out = $client->upload_part( 'up_9a1b2c3d4e5f', 2, 'abc' );

		$this->assertSame( array( 'n' => 2, 'size' => 3 ), $out );
		$req = $http->requests[0];
		$this->assertSame( 'PUT', $req['method'] );
		$this->assertSame( 'https://api.babilim.org/v1/uploads/up_9a1b2c3d4e5f/parts/2', $req['url'] );
		$this->assertSame( 'abc', $req['args']['body'] );
		$this->assertSame( 'application/octet-stream', $req['args']['headers']['Content-Type'] );
		$this->assertSame( 'Bearer tok_x', $req['args']['headers']['Authorization'] );
		$this->assertFalse( $req['args']['retry'] );
		$this->assertSame( 60, $req['args']['timeout'] );
	}

	public function test_complete_upload_is_json_and_retryable_and_cancel_is_delete(): void {
		$http   = new Babilim_Fake_Http( array(
			$this->json_response( 202, array( 'state' => 'verifying' ) ),
			$this->json_response( 200, array( 'state' => 'cancelled' ) ),
		) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		update_option( 'babilim_consent', true );

		$this->assertSame( array( 'state' => 'verifying' ), $client->complete_upload( 'up_9a1b2c3d4e5f', 2, 1027 ) );
		$this->assertSame( array( 'parts' => 2, 'size' => 1027 ), json_decode( $http->requests[0]['args']['body'], true ) );
		$this->assertTrue( $http->requests[0]['args']['retry'] );
		$this->assertSame( array( 'state' => 'cancelled' ), $client->cancel_upload( 'up_9a1b2c3d4e5f' ) );
		$this->assertSame( 'DELETE', $http->requests[1]['method'] );
	}

	public function test_upload_part_413_and_409_map_to_babilim_error_codes(): void {
		$http   = new Babilim_Fake_Http( array(
			$this->json_response( 413, array( 'error' => array( 'code' => 'payload_too_large', 'message' => 'x', 'hint' => '' ) ) ),
			$this->json_response( 409, array( 'error' => array( 'code' => 'upload_sealed', 'message' => 'x', 'hint' => '' ) ) ),
		) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		update_option( 'babilim_consent', true );
		$this->assertSame( 'babilim_payload_too_large', $client->upload_part( 'up_9a1b2c3d4e5f', 1, 'x' )->get_error_code() );
		$this->assertSame( 'babilim_upload_sealed', $client->upload_part( 'up_9a1b2c3d4e5f', 1, 'x' )->get_error_code() );
	}

	public function test_request_translation_declares_the_upload_capability(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 202, array( 'request_id' => 'rq_4b7c1e0a3d6f', 'status' => 'queued', 'eta_minutes' => null ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		update_option( 'babilim_consent', true );
		$client->request_translation( array( 'kind' => 'theme', 'slug' => 'avada', 'version' => '7.11.4', 'locale' => 'fr_FR' ) );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( array( 'upload' ), $sent['client_caps'] );
		$this->assertRequestPayloadMatchesSchema( $http->requests[0]['args']['body'] );
	}

	// -- checkout / restore (contract 1.7.0) ------------------------------

	public function test_get_site_is_an_authenticated_retryable_get(): void {
		$http   = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'site_id' => 'st_x', 'price' => array( 'amount' => '8.99', 'currency' => 'USD' ) ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->get_site( 'st_x' );

		$this->assertSame( 'GET', $http->requests[0]['method'] );
		$this->assertSame( 'https://api.babilim.org/v1/sites/st_x', $http->requests[0]['url'] );
		$this->assertSame( 'Bearer tok_x', $http->requests[0]['args']['headers']['Authorization'] );
		$this->assertTrue( $http->requests[0]['args']['retry'] );
		$this->assertSame( '8.99', $result['price']['amount'] );
	}

	public function test_start_checkout_posts_an_empty_object_and_is_not_retried(): void {
		$body   = array( 'checkout_url' => 'https://pay.babilim.example/checkout?s=abc', 'expires_at' => '2026-10-19T12:30:00Z', 'price' => array( 'amount' => '8.99', 'currency' => 'USD' ) );
		$http   = new Babilim_Fake_Http( array( $this->json_response( 201, $body ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->start_checkout( 'st_x' );

		$this->assertSame( 'POST', $http->requests[0]['method'] );
		$this->assertSame( 'https://api.babilim.org/v1/sites/st_x/checkout', $http->requests[0]['url'] );
		$this->assertSame( 'Bearer tok_x', $http->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( '{}', $http->requests[0]['args']['body'] );
		$this->assertFalse( $http->requests[0]['args']['retry'] ?? false );
		$this->assertSame( $body['checkout_url'], $result['checkout_url'] );
	}

	public function test_restore_purchase_sends_the_code_and_new_restore_code_posts_empty(): void {
		$code   = 'rc_9f3a1c5e7b2d4086a1c3e5f70912b4d6';
		$http   = new Babilim_Fake_Http( array(
			$this->json_response( 200, array( 'license' => array( 'kind' => 'paid', 'status' => 'active' ) ) ),
			$this->json_response( 200, array( 'restore_code' => $code ) ),
		) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$restored = $client->restore_purchase( 'st_x', $code );
		$fresh    = $client->new_restore_code( 'st_x' );

		$this->assertSame( 'https://api.babilim.org/v1/sites/st_x/restore', $http->requests[0]['url'] );
		$this->assertSame( array( 'restore_code' => $code ), json_decode( $http->requests[0]['args']['body'], true ) );
		$this->assertFalse( $http->requests[0]['args']['retry'] ?? false );
		$this->assertSame( 'paid', $restored['license']['kind'] );
		$this->assertSame( 'https://api.babilim.org/v1/sites/st_x/restore-code', $http->requests[1]['url'] );
		$this->assertSame( '{}', $http->requests[1]['args']['body'] );
		$this->assertFalse( $http->requests[1]['args']['retry'] ?? false );
		$this->assertSame( $code, $fresh['restore_code'] );
	}

	public function test_checkout_error_codes_pass_through_as_wp_error_codes(): void {
		$err = static function ( string $code ): array {
			return array( 'error' => array( 'code' => $code, 'message' => 'm', 'hint' => '' ) );
		};
		$http   = new Babilim_Fake_Http( array(
			$this->json_response( 409, $err( 'already_licensed' ) ),
			$this->json_response( 403, $err( 'checkout_unavailable' ) ),
			$this->json_response( 409, $err( 'no_purchase' ) ),
			$this->json_response( 404, $err( 'not_found' ) ),
			$this->json_response( 429, $err( 'rate_limited' ), array( 'Retry-After' => '12' ) ),
		) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$this->assertSame( 'babilim_already_licensed', $client->start_checkout( 'st_x' )->get_error_code() );
		$this->assertSame( 'babilim_checkout_unavailable', $client->start_checkout( 'st_x' )->get_error_code() );
		$this->assertSame( 'babilim_no_purchase', $client->new_restore_code( 'st_x' )->get_error_code() );
		$this->assertSame( 'babilim_not_found', $client->restore_purchase( 'st_x', 'rc_9f3a1c5e7b2d4086a1c3e5f70912b4d6' )->get_error_code() );
		$limited = $client->get_site( 'st_x' );
		$this->assertSame( 'babilim_rate_limited', $limited->get_error_code() );
		$this->assertSame( 12, $limited->get_error_data()['retry_after'] );
	}

	public function test_sync_stores_price_and_features_from_the_inventory_answer(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		$answer    = array(
			'components' => array(),
			'updates'    => array(),
			'license'    => array( 'kind' => 'free', 'status' => 'active' ),
			'quota'      => array(),
			'features'   => array( 'checkout' => true, 'upload' => true ),
			'contract'   => '1.7.0',
			'price'      => array( 'amount' => '8.99', 'currency' => 'USD' ),
		);
		$http      = new Babilim_Fake_Http( array( $this->json_response( 200, $answer ) ) );
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$state = new Babilim_State();
		$site  = new Babilim_Site( $http, 'https://api.babilim.org', $state, $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );

		$site->sync();

		$stored = $state->get();
		$this->assertSame( array( 'amount' => '8.99', 'currency' => 'USD' ), $stored['price'] );
		$this->assertSame( array( 'checkout' => true, 'upload' => true ), $stored['features'] );
	}
}

/**
 * A Babilim_Fs whose put() always fails -- used to prove
 * fetch_package() reports babilim_write_failed rather than a success it
 * never actually achieved.
 */
final class Babilim_Test_Failing_Put_Api_Fs implements Babilim_Fs {

	public function put( string $path, string $contents ): bool {
		return false;
	}

	public function get( string $path ) {
		return null;
	}

	public function exists( string $path ): bool {
		return false;
	}

	public function delete( string $path ): bool {
		return true;
	}

	public function rename( string $from, string $to ): bool {
		return false;
	}

	public function mkdir( string $path ): bool {
		return true;
	}

	public function list( string $dir, string $glob = '*' ): array {
		return array();
	}

	public function sha256( string $path ) {
		return null;
	}

	public function rmdir( string $path ): bool {
		return true;
	}

	public function is_dir( string $path ): bool {
		return false;
	}
}
