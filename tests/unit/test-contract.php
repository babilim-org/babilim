<?php
/**
 * Plugin-side contract tests: every request body Babilim_Api_Client
 * builds is checked, key-by-key, against the pinned contract's own
 * example request bodies (tests/contract/v1/examples/, never
 * hand-edited); every example response is fed back
 * through the client and checked against the expected decoded shape or
 * mapped WP_Error code.
 *
 * Endpoints without a dedicated Babilim_Api_Client method
 * (`GET /v1/sites/{id}`, `GET /v1/health`) aren't required by this
 * task's method list (see class-babilim-api-client.php's own docblock)
 * and so have no request-direction test here; `GET /v1/requests/{id}`'s
 * `failed` response shape is exercised through request_translation()'s
 * shared response-decoding path instead of a dedicated polling method,
 * since only the response shape (not the HTTP verb) is this class's own
 * concern.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Contract extends TestCase {

	private const EXAMPLES_DIR = __DIR__ . '/../contract/v1/examples';
	private const SCHEMAS_DIR  = __DIR__ . '/../contract/v1/schemas';

	protected function setUp(): void {
		babilim_test_reset();
		// transport() refuses to send without consent.
		update_option( 'babilim_consent', true );
	}

	/**
	 * @return array The decoded example fixture.
	 */
	private function fixture( string $name ): array {
		$raw = file_get_contents( self::EXAMPLES_DIR . '/' . $name );
		$this->assertIsString( $raw, "Missing contract fixture: {$name}" );
		$decoded = json_decode( $raw, true );
		$this->assertIsArray( $decoded, "Fixture did not decode: {$name}" );
		return $decoded;
	}

	/**
	 * @return array The decoded JSON Schema.
	 */
	private function schema( string $name ): array {
		$raw = file_get_contents( self::SCHEMAS_DIR . '/' . $name );
		$this->assertIsString( $raw, "Missing contract schema: {$name}" );
		$decoded = json_decode( $raw, true );
		$this->assertIsArray( $decoded, "Schema did not decode: {$name}" );
		return $decoded;
	}

	/**
	 * Validates $raw_json against $schema_name using
	 * Babilim_Schema_Validator -- decoded WITHOUT $assoc, so a JSON `{}`
	 * and `[]` stay distinguishable (see that class's own docblock).
	 */
	private function assertMatchesRequestSchema( string $raw_json, string $schema_name ): void {
		$data   = json_decode( $raw_json );
		$errors = Babilim_Schema_Validator::validate( $data, $this->schema( $schema_name ) );
		$this->assertSame( array(), $errors, "{$schema_name}:\n" . implode( "\n", $errors ) );
	}

	/**
	 * The `{id}` path segment out of an example's own `http.path`.
	 */
	private function site_id_from_path( array $example ): string {
		$this->assertSame( 1, preg_match( '#/v1/sites/([^/]+)#', $example['http']['path'], $m ) );
		return $m[1];
	}

	private function response_from_fixture( array $example ): array {
		return array(
			'response' => array( 'code' => $example['response']['status'] ),
			'body'     => (string) wp_json_encode( $example['response']['body'] ),
			'headers'  => $example['response']['headers'] ?? array(),
		);
	}

	// -- POST /v1/sites ---------------------------------------------------

	public function test_register_request_matches_the_contract_example(): void {
		$example = $this->fixture( 'sites.register.ok.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$client->register( $example['request']['body'] );

		$this->assertEquals( $example['request']['body'], json_decode( $http->requests[0]['args']['body'], true ) );
		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'sites.register.request.schema.json' );
	}

	/**
	 * The test above only proves Babilim_Api_Client::register() faithfully
	 * transmits whatever body it's handed -- it says nothing about the
	 * body Babilim_Site itself actually builds via registration_payload()
	 * (this is exactly the gap
	 * `installed` sent as `[]` once slipped through, because nothing here ever
	 * exercised the real body-building code). This one drives a real
	 * Babilim_Site through ensure_registered() and validates the raw JSON
	 * it actually sends against the schema.
	 */
	public function test_babilim_site_builds_a_register_request_that_matches_the_schema(): void {
		update_option( 'babilim_consent', true );
		$registered = $this->response_from_fixture( $this->fixture( 'sites.register.ok.json' ) );
		$http       = new Babilim_Fake_Http( array( $registered ) );
		$inventory  = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array( 'de_DE' ); }
		);
		$site = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );

		$site->ensure_registered();

		$this->assertSame( 'https://api.babilim.org/v1/sites', $http->requests[0]['url'] );
		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'sites.register.request.schema.json' );
	}

	public function test_register_response_parses_into_the_expected_shape(): void {
		$example = $this->fixture( 'sites.register.ok.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$result = $client->register( $example['request']['body'] );

		$this->assertEquals( $example['response']['body'], $result );
	}

	// -- POST /v1/sites/{id}/verify ---------------------------------------

	private function call_verify_from_fixture( array $example, ?WP_Error &$error_out = null ) {
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );
		$site_id = $this->site_id_from_path( $example );

		$result = $client->verify( $site_id, $example['request']['body']['nonce'], $example['request']['body']['proof'] );

		$this->assertEquals(
			array( 'nonce' => $example['request']['body']['nonce'], 'proof' => $example['request']['body']['proof'] ),
			json_decode( $http->requests[0]['args']['body'], true )
		);
		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'sites.verify.request.schema.json' );
		return $result;
	}

	public function test_verify_ok_request_and_response(): void {
		$example = $this->fixture( 'sites.verify.ok.json' );
		$result  = $this->call_verify_from_fixture( $example );

		$this->assertEquals( $example['response']['body'], $result );
	}

	/**
	 * 1.3.0: a verify whose callback did not succeed is refused outright
	 * (403 verification_required + a machine-readable reason) and never
	 * returns a token -- it replaces the old "verified:false but here is a
	 * working token" example, which the contract no longer contains.
	 */
	public function test_verify_verification_required_maps_to_403_error_carrying_its_reason(): void {
		$example = $this->fixture( 'sites.verify.verification-required.json' );
		$result  = $this->call_verify_from_fixture( $example );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_verification_required', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
		$this->assertSame( 'callback_unreachable', $data['reason'] );
		$this->assertSame( $example['response']['body']['error']['reason'], $data['reason'] );
		$this->assertArrayNotHasKey( 'site_token', (array) $data );
	}

	public function test_an_error_without_a_reason_carries_an_empty_reason(): void {
		$example = $this->fixture( 'sites.verify.conflict.json' );
		$result  = $this->call_verify_from_fixture( $example );

		$this->assertSame( '', $result->get_error_data()['reason'] );
	}

	public function test_verify_partner_auto_response(): void {
		$example = $this->fixture( 'sites.verify.partner-auto.json' );
		$result  = $this->call_verify_from_fixture( $example );

		$this->assertEquals( $example['response']['body'], $result );
	}

	public function test_verify_invalid_proof_maps_to_401_error(): void {
		$example = $this->fixture( 'sites.verify.invalid-proof.json' );
		$result  = $this->call_verify_from_fixture( $example );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_' . $example['response']['body']['error']['code'], $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_verify_conflict_maps_to_409_error(): void {
		$example = $this->fixture( 'sites.verify.conflict.json' );
		$result  = $this->call_verify_from_fixture( $example );

		$this->assertSame( 'babilim_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	// -- POST /v1/sites/{id}/rotate-token -----------------------------------

	public function test_rotate_token_request_and_response(): void {
		$example = $this->fixture( 'sites.rotate.ok.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b' );
		$site_id = $this->site_id_from_path( $example );

		$result = $client->rotate_token( $site_id );

		$this->assertSame( 'Bearer tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b', $http->requests[0]['args']['headers']['Authorization'] );
		$this->assertEquals( $example['response']['body'], $result );
	}

	/**
	 * The request schema is `"type":"object"` -- rejects the JSON array
	 * literal `[]` a naive `wp_json_encode( array() )` would have sent.
	 * Decoded WITHOUT $assoc (json_decode($raw), no second argument) so
	 * `{}` and `[]` stay distinguishable -- see
	 * Babilim_Schema_Validator's own class docblock.
	 */
	public function test_rotate_token_request_matches_the_contract_schema(): void {
		$http   = new Babilim_Fake_Http( array( $this->response_from_fixture( $this->fixture( 'sites.rotate.ok.json' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$client->rotate_token( 'st_x' );

		$errors = Babilim_Schema_Validator::validate(
			json_decode( $http->requests[0]['args']['body'] ),
			$this->schema( 'sites.rotate-token.request.schema.json' )
		);
		$this->assertSame( array(), $errors, implode( "\n", $errors ) );
	}

	// -- POST /v1/sites/{id}/inventory -------------------------------------

	/**
	 * This only proves Babilim_Api_Client::inventory() faithfully
	 * transmits whatever payload array it's handed -- it's a lossy round
	 * trip (the fixture is decoded with json_decode($raw, true), so a
	 * JSON `{}` already became an indistinguishable-from-`[]` empty PHP
	 * array before this test ever sees it) and deliberately isn't checked
	 * against the schema here for that reason; schema conformance of a
	 * genuinely *built* request is covered below by
	 * test_babilim_site_builds_an_inventory_request_that_matches_the_schema(),
	 * which drives the real Babilim_Site body-building code instead of
	 * echoing a pre-decoded fixture through the transport.
	 */
	public function test_inventory_request_matches_the_contract_example(): void {
		$example = $this->fixture( 'inventory.ok.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b' );

		$client->inventory( $this->site_id_from_path( $example ), $example['request']['body'] );

		$this->assertEquals( $example['request']['body'], json_decode( $http->requests[0]['args']['body'], true ) );
		$this->assertSame( 'Bearer tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b', $http->requests[0]['args']['headers']['Authorization'] );
	}

	/**
	 * The test above only proves Babilim_Api_Client::inventory() faithfully
	 * transmits whatever body it's handed -- it says nothing about the
	 * body Babilim_Site::sync() itself builds. This drives a real
	 * Babilim_Site through sync() and validates the raw JSON it actually
	 * sends -- in particular that a component with no Babilim-installed
	 * locale yet sends `"installed":{}`, a JSON object, never `[]` (the
	 * exact regression this guards against; see
	 * Babilim_Site::sync()).
	 */
	public function test_babilim_site_builds_an_inventory_request_that_matches_the_schema(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$ok = $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) );
		$http = new Babilim_Fake_Http( array( $ok ) );
		$site = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );

		$site->sync();

		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'inventory.request.schema.json' );
		$this->assertStringContainsString( '"installed":{}', $http->requests[0]['args']['body'] );
	}

	/**
	 * The test above only ever produces an EMPTY `installed` map, so it
	 * can't catch a shape error inside a populated entry -- exactly a
	 * gap once found in testing: the schema validator used to silently
	 * accept any shape for an `installed` entry (no `additionalProperties`-
	 * as-schema support), so a wrong key name or a wrong enum value inside
	 * one would sail through undetected while the real service's pydantic
	 * model would 422 it. This builds a real installed entry (via a
	 * manifest record with a failed integrity check) and a client_event,
	 * and validates both against the schema.
	 */
	public function test_babilim_site_builds_an_inventory_request_with_a_populated_installed_entry_and_client_events_that_matches_the_schema(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );

		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		// One failed locale (also exercises the client_events path) and
		// one genuinely ok locale -- so the schema check below exercises
		// both `integrity` enum values, not just `failed`.
		$manifest->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_a1b2c3d4e5f6', 'files' => array(), 'integrity' => array( 'ok' => false, 'checked_at' => '2026-01-01T00:00:00Z' ) ) );
		$manifest->set( 'plugin', 'a', 'fr_FR', array( 'build_id' => 'bd_f1e2d3c4b5a6', 'files' => array(), 'integrity' => array( 'ok' => true, 'checked_at' => '2026-01-01T00:00:00Z' ) ) );

		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$ok   = $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) );
		$http = new Babilim_Fake_Http( array( $ok ) );
		$site = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, $manifest );

		$site->sync();

		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'inventory.request.schema.json' );
		$body = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayHasKey( 'client_events', $body );
		$this->assertSame( 'failed', $body['components'][0]['installed']['de_DE']['integrity'] );
		$this->assertSame( 'ok', $body['components'][0]['installed']['fr_FR']['integrity'] );
	}

	/**
	 * A manifest write conflict must never reach the inventory request's
	 * own client_events[] -- that schema only accepts
	 * `kind: 'plugin'|'theme'`, and a manifest write conflict has no
	 * such identity to honestly claim (see
	 * Babilim_Manifest::write_conflict()'s own docblock). This
	 * reproduces a real conflict (two Babilim_Manifest instances writing
	 * the same record), pre-seeds a stale invalid entry directly in the
	 * option (the shape an install with a stuck ring-buffer entry would
	 * carry), and confirms Babilim_Site::sync() sends a request that
	 * both validates against the real schema AND excludes the invalid
	 * entry.
	 */
	public function test_sync_never_sends_a_manifest_conflict_and_purges_a_legacy_invalid_event(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		// A legacy entry the same shape a pre-guard version of this
		// plugin could have already written -- record_client_event()
		// itself can no longer produce one (see Test_State's own
		// coverage of that guard).
		update_option( 'babilim_client_events', array(
			array( 'at' => '2026-01-01T00:00:00Z', 'code' => 'manifest_write_conflict', 'kind' => 'manifest', 'slug' => 'default_index', 'locale' => 'de_DE' ),
		) );

		$fs = new Babilim_Memory_Fs();
		$m1 = new Babilim_Manifest( $fs );
		$m2 = new Babilim_Manifest( $fs );
		$m1->is_available();
		$m2->is_available();
		$m1->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_a1b2c3d4e5f6', 'files' => array() ) );
		// A real, same-record conflict -- m2's own baseline predates m1's
		// write above.
		$m2->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_ffffffffffff', 'files' => array() ) );
		$this->assertNotNull( $m2->write_conflict(), 'test setup bug: no conflict was actually produced' );

		$manifest  = new Babilim_Manifest( $fs );
		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$ok   = $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) );
		$http = new Babilim_Fake_Http( array( $ok ) );
		$site = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, $manifest );

		$site->sync();

		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'inventory.request.schema.json' );
		$body = json_decode( $http->requests[0]['args']['body'], true );
		$sent_codes = array_column( (array) ( $body['client_events'] ?? array() ), 'code' );
		$this->assertNotContains( 'manifest_write_conflict', $sent_codes, 'a manifest write conflict must never be sent as a client_event' );
	}

	/**
	 * @return array wp_remote_request()-shaped fake response.
	 */
	private function json_response( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	/**
	 * Response direction: every per-locale status the fixture covers
	 * (bundled, wporg, babilim_installed, babilim_available, requestable,
	 * queued, running, failed, unsupported, quota_exceeded), plus the
	 * `updates[]` list -- including component-a's rollback (an active
	 * build older than what this site has installed) -- must survive
	 * parsing unchanged.
	 */
	public function test_inventory_response_parses_every_status_and_the_rollback_update(): void {
		$example = $this->fixture( 'inventory.ok.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->inventory( $this->site_id_from_path( $example ), array() );

		$this->assertEquals( $example['response']['body'], $result );

		$statuses = array();
		foreach ( $result['components'] as $component ) {
			foreach ( $component['locales'] as $locale ) {
				$statuses[] = $locale['status'];
			}
		}
		foreach ( array( 'bundled', 'wporg', 'babilim_installed', 'babilim_available', 'requestable', 'queued', 'running', 'failed', 'unsupported', 'quota_exceeded' ) as $expected_status ) {
			$this->assertContains( $expected_status, $statuses, "fixture is expected to cover status '{$expected_status}'" );
		}

		$rollback = $result['updates'][0];
		$this->assertSame( 'component-a', $rollback['slug'] );
		$this->assertSame( 3, $rollback['build_no'] );
	}

	/**
	 * 1.4.0: the new `license_required` status, and a `babilim_installed`
	 * entry carrying `built_for_version` and `newer_version`, must survive
	 * parsing unchanged -- the cell model reads both straight from state.
	 */
	public function test_inventory_response_parses_the_1_4_0_fields(): void {
		foreach ( array( 'inventory.license-required.json', 'inventory.stale-installed.json' ) as $name ) {
			$example = $this->fixture( $name );
			$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
			$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

			$result = $client->inventory( $this->site_id_from_path( $example ), array() );

			$this->assertEquals( $example['response']['body'], $result, $name );
		}

		$stale = $this->fixture( 'inventory.stale-installed.json' )['response']['body']['components'][0]['locales']['de_DE'];
		$this->assertSame( 'babilim_installed', $stale['status'] );
		$this->assertSame( '2.3.0', $stale['built_for_version'] );
		$this->assertSame( 'requestable', $stale['newer_version']['status'] );

		$licence = $this->fixture( 'inventory.license-required.json' )['response']['body']['components'][0]['locales']['de_DE'];
		$this->assertSame( 'license_required', $licence['status'] );
	}

	/**
	 * 1.6.0: `hardcoded_text_warning` on a locale entry survives parsing
	 * and ends up in the cell model.
	 */
	public function test_inventory_response_parses_the_1_6_0_hardcoded_text_warning(): void {
		$example = $this->fixture( 'inventory.hardcoded-warning.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->inventory( $this->site_id_from_path( $example ), array() );

		$this->assertEquals( $example['response']['body'], $result );
		$flagged = 0;
		foreach ( $result['components'] as $component ) {
			foreach ( $component['locales'] as $entry ) {
				$cell = Babilim_Cell::from_entry( $entry, 'de_DE', false, true, null );
				$this->assertSame( isset( $entry['hardcoded_text_warning'] ), '' !== $cell['hardcoded_warning'] );
				$flagged += '' !== $cell['hardcoded_warning'] ? 1 : 0;
			}
		}
		$this->assertGreaterThan( 0, $flagged );
	}

	public function test_401_unauthorized_maps_correctly(): void {
		$example = $this->fixture( 'errors.401.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$result = $client->inventory( 'st_3f9c2a1b7e4d', array() );

		$this->assertSame( 'babilim_unauthorized', $result->get_error_code() );
	}

	public function test_426_plugin_outdated_maps_correctly(): void {
		$example = $this->fixture( 'errors.426.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b' );

		$result = $client->inventory( 'st_3f9c2a1b7e4d', array() );

		$this->assertSame( 'babilim_plugin_outdated', $result->get_error_code() );
	}

	public function test_429_rate_limited_carries_retry_after(): void {
		$example = $this->fixture( 'errors.429.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b' );

		$result = $client->inventory( 'st_3f9c2a1b7e4d', array() );

		$this->assertSame( 'babilim_rate_limited', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'retry_after', $data, 'a 429 must always carry retry_after' );
		$this->assertSame( 37, $data['retry_after'] );
	}

	// -- POST /v1/downloads -------------------------------------------------

	public function test_download_request_matches_the_contract_example(): void {
		$example = $this->fixture( 'downloads.302.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b' );

		$result = $client->download( $example['request']['body']['build_id'] );

		$this->assertEquals( $example['request']['body'], json_decode( $http->requests[0]['args']['body'], true ) );
		$this->assertSame( $example['response']['headers']['Location'], $result['location'] );
		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'downloads.request.schema.json' );
	}

	public function test_download_quota_exceeded_maps_to_403_error(): void {
		$example = $this->fixture( 'downloads.403-quota.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->download( $example['request']['body']['build_id'] );

		$this->assertSame( 'babilim_quota_exceeded', $result->get_error_code() );
	}

	public function test_download_not_available_maps_to_404_error(): void {
		$example = $this->fixture( 'downloads.404-not-available.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->download( $example['request']['body']['build_id'] );

		$this->assertSame( 'babilim_not_available', $result->get_error_code() );
	}

	public function test_packages_expired_maps_to_410_error(): void {
		$example = $this->fixture( 'packages.410.json' );
		$dest    = Babilim_Paths::incoming_dir( 'contract-test' ) . '/package.zip';
		$http    = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => $example['response']['status'] ), 'body' => wp_json_encode( $example['response']['body'] ), 'headers' => array() ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->fetch_package( 'https://api.babilim.org/v1/packages/bd_9e8d7c6b5a4f', $dest, new Babilim_Memory_Fs() );

		$this->assertSame( 'babilim_' . $example['response']['body']['error']['code'], $result->get_error_code() );
	}

	// -- POST /v1/requests --------------------------------------------------

	public function test_request_translation_queued_request_and_response(): void {
		$example = $this->fixture( 'requests.202.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->request_translation( $example['request']['body'] );

		// The client always adds its capability declaration on top of the example body.
		$this->assertEquals( $example['request']['body'] + array( 'client_caps' => array( 'upload' ) ), json_decode( $http->requests[0]['args']['body'], true ) );
		$this->assertEquals( $example['response']['body'], $result );
		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'requests.request.schema.json' );
	}

	public function test_request_translation_available_response(): void {
		$example = $this->fixture( 'requests.available.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->request_translation( $example['request']['body'] );

		$this->assertEquals( $example['response']['body'], $result );
	}

	/**
	 * The `failed` shape is only ever returned by `GET /v1/requests/{id}`
	 * (see docs/api-contract.md) -- no dedicated polling method exists
	 * yet (see this file's own docblock), so this exercises the shared
	 * response-decoding path request_translation() itself uses.
	 */
	public function test_failed_job_response_shape_parses_correctly(): void {
		$example = $this->fixture( 'requests.get.failed.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->request_translation( array( 'kind' => 'plugin', 'slug' => 'component-d', 'version' => '3.2.1', 'locale' => 'fr_FR' ) );

		$this->assertEquals( $example['response']['body'], $result );
		$this->assertSame( 'failed', $result['status'] );
	}

	// -- POST /v1/feedback ----------------------------------------------------

	public function test_feedback_request_and_response(): void {
		$example = $this->fixture( 'feedback.201.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );

		$result = $client->feedback( $example['request']['body'] );

		$this->assertEquals( $example['request']['body'], json_decode( $http->requests[0]['args']['body'], true ) );
		$this->assertEquals( $example['response']['body'], $result );
		$this->assertMatchesRequestSchema( $http->requests[0]['args']['body'], 'feedback.request.schema.json' );
	}

	// -- GET /v1/meta/locales ------------------------------------------------

	public function test_meta_locales_response_matches_the_contract_example(): void {
		$example = $this->fixture( 'meta.locales.ok.json' );
		$http    = new Babilim_Fake_Http( array( $this->response_from_fixture( $example ) ) );
		$client  = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		$result = $client->meta_locales();

		$this->assertEquals( $example['response']['body'], $result );
		$errors = Babilim_Schema_Validator::validate(
			json_decode( wp_json_encode( $result ) ),
			$this->schema( 'meta.locales.response.schema.json' )
		);
		$this->assertSame( array(), $errors );
	}

	// -- GET /wp-json/babilim/v1/challenge ------------------------------------

	public function test_challenge_endpoint_rejects_the_non_hex_example(): void {
		$example = $this->fixture( 'challenge.400.json' );
		$site    = new Babilim_Site( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', new Babilim_State(), $this->empty_inventory(), new Babilim_Manifest( new Babilim_Memory_Fs() ) );

		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => $example['request']['query']['nonce'] ) ) );

		$this->assertSame( $example['response']['status'], $response->get_status() );
		$this->assertSame( $example['response']['body']['error']['code'], $response->get_data()['error']['code'] );
	}

	public function test_challenge_endpoint_answers_the_pending_nonce_from_the_example(): void {
		$example = $this->fixture( 'challenge.ok.json' );
		$nonce   = $example['request']['query']['nonce'];

		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_3f9c2a1b7e4d' );
		update_option( 'babilim_install_sk', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
		update_option( 'babilim_pending_verification', array( 'site_id' => 'st_3f9c2a1b7e4d', 'nonce' => $nonce, 'expires_at' => 'x' ) );

		$site = new Babilim_Site( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', new Babilim_State(), $this->empty_inventory(), new Babilim_Manifest( new Babilim_Memory_Fs() ) );

		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => $nonce ) ) );

		$this->assertSame( $example['response']['status'], $response->get_status() );
		$this->assertSame( $nonce, $response->get_data()['nonce'] );
		$this->assertStringStartsWith( 'b64:', $response->get_data()['proof'] );
	}

	private function empty_inventory(): Babilim_Inventory {
		return new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
	}

	// -- Babilim_Schema_Validator itself -----------------------------------

	/**
	 * Walks every schema file under tests/contract/v1/schemas/ and asserts
	 * Babilim_Schema_Validator::assert_known_keywords() doesn't throw for
	 * any of them -- i.e. every JSON Schema keyword the pinned contract
	 * actually uses anywhere (not just in the request schemas this file's
	 * other tests validate real data against) is one this validator
	 * recognizes. A schema keyword outside that list must fail this test
	 * loudly, not let some other schema's validation silently pass data it
	 * never really checked (see Babilim_Schema_Validator::KNOWN_KEYWORDS's
	 * own docblock for why).
	 */
	public function test_schema_validator_recognizes_every_keyword_used_across_the_pinned_contract_schemas(): void {
		$files = glob( self::SCHEMAS_DIR . '/*.json' );
		$this->assertNotEmpty( $files, 'expected to find schema files under ' . self::SCHEMAS_DIR );

		foreach ( $files as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );
			$this->assertIsArray( $decoded, "schema file did not decode as an object/array: {$file}" );

			try {
				Babilim_Schema_Validator::assert_known_keywords( $decoded );
			} catch ( \RuntimeException $e ) {
				$this->fail( basename( $file ) . ': ' . $e->getMessage() );
			}
		}
	}
}
