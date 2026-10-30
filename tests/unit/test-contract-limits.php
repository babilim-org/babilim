<?php
/**
 * Every request the plugin sends must stay inside the service's request
 * schemas, so the service never answers 422 for something the plugin
 * could have caught itself. Each test here builds a real request through
 * the real code path, sits it exactly on a limit (it must go out and
 * validate against the pinned schema) and one step over it (it must not
 * go out, or the offending piece must be left out and reported).
 *
 * The feedback and translation-request actions are covered next to the
 * admin tests (test-admin.php); this file covers inventory,
 * registration, verification and download, the constants themselves, and
 * the plugin-slug resolution the request form shares with the inventory.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/fake-wp-content/plugins' );
}

final class Test_Contract_Limits extends TestCase {

	private const SCHEMAS_DIR = __DIR__ . '/../contract/v1/schemas';

	protected function setUp(): void {
		babilim_test_reset();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		update_option( 'babilim_consent', true );
		update_option( 'babilim_install_sk', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
	}

	private function schema( string $name ): array {
		$decoded = json_decode( (string) file_get_contents( self::SCHEMAS_DIR . '/' . $name ), true );
		$this->assertIsArray( $decoded, $name );
		return $decoded;
	}

	private function assertMatchesSchema( string $raw_json, string $schema_name ): void {
		$errors = Babilim_Schema_Validator::validate( json_decode( $raw_json ), $this->schema( $schema_name ) );
		$this->assertSame( array(), $errors, "{$schema_name}:\n" . implode( "\n", $errors ) );
	}

	private function ok_inventory_response(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) ),
			'headers'  => array(),
		);
	}

	/**
	 * @param array<string,array>  $plugins get_plugins() shape.
	 * @param string[]             $files   What every plugin directory listing returns.
	 */
	private function site( array $plugins, array $files = array(), ?Babilim_Manifest $manifest = null, ?Babilim_Fake_Http $http = null ): array {
		$http      = $http ?? new Babilim_Fake_Http( array( $this->ok_inventory_response() ) );
		$state     = new Babilim_State();
		$inventory = new Babilim_Inventory(
			static function () use ( $plugins ) { return $plugins; },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); },
			static function () use ( $files ) { return $files; }
		);
		$site = new Babilim_Site( $http, 'https://api.babilim.org', $state, $inventory, $manifest ?? new Babilim_Manifest( new Babilim_Memory_Fs() ) );
		return array( $site, $http, $inventory );
	}

	private function sent_inventory( Babilim_Fake_Http $http ): array {
		$this->assertNotEmpty( $http->requests, 'an inventory request was sent' );
		$raw = $http->requests[0]['args']['body'];
		$this->assertMatchesSchema( $raw, 'inventory.request.schema.json' );
		return json_decode( $raw, true );
	}

	// -- the constants are the schemas' numbers ------------------------------

	public function test_the_limit_constants_equal_the_pinned_schemas(): void {
		$req  = $this->schema( 'requests.request.schema.json' )['properties'];
		$fb   = $this->schema( 'feedback.request.schema.json' )['properties'];
		$inv  = $this->schema( 'inventory.request.schema.json' )['properties'];
		$comp = $inv['components']['items']['properties'];
		$evt  = $inv['client_events']['items']['properties'];
		$reg  = $this->schema( 'sites.register.request.schema.json' )['properties'];

		$this->assertSame( Babilim_Contract_Limits::SLUG, $req['slug']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::SLUG, $fb['slug']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::SLUG, $comp['slug']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::SLUG, $evt['slug']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::LOCALE, $req['locale']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::LOCALE, $fb['locale']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::LOCALE, $evt['locale']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::LOCALE, $inv['locales']['items']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::LOCALE, $comp['bundled_locales']['items']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::LOCALE, $reg['site_locale']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::VERSION, $req['version']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::VERSION, $comp['version']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::TEXT_DOMAIN, $comp['text_domain']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::TEXT_DOMAIN, $comp['extra_domains']['items']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::MAX_DOMAIN_LIST, $comp['extra_domains']['maxItems'] );
		$this->assertSame( Babilim_Contract_Limits::MAX_DOMAIN_LIST, $comp['bundled_locales']['maxItems'] );
		$this->assertSame( Babilim_Contract_Limits::MAX_INSTALLED, $comp['installed']['maxProperties'] );
		$this->assertSame( Babilim_Contract_Limits::EVENT_CODE, $evt['code']['maxLength'] );
		foreach ( array( 'msgid', 'msgctxt', 'current_msgstr', 'suggested', 'note' ) as $field ) {
			$this->assertSame( Babilim_Contract_Limits::FEEDBACK_TEXT, $fb[ $field ]['maxLength'], $field );
		}
		$this->assertSame( Babilim_Contract_Limits::SCREEN_PATH, $fb['screen_path']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::HOME_URL, $reg['home_url']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::ENV_VERSION, $reg['wp_version']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::ENV_VERSION, $reg['php_version']['maxLength'] );
	}

	// -- inventory: component version ---------------------------------------

	public function test_an_inventory_component_with_a_64_character_version_is_sent(): void {
		$version = str_repeat( '9', 64 );
		list( $site, $http ) = $this->site( array( 'a/a.php' => array( 'Name' => 'A', 'Version' => $version, 'TextDomain' => 'a' ) ) );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertSame( $version, $body['components'][0]['version'] );
		$this->assertArrayNotHasKey( 'client_events', $body );
	}

	public function test_a_component_with_a_65_character_version_is_left_out_and_reported_never_cut_short(): void {
		$plugins = array(
			'a/a.php' => array( 'Name' => 'A', 'Version' => str_repeat( '9', 65 ), 'TextDomain' => 'a' ),
			'b/b.php' => array( 'Name' => 'B', 'Version' => '2.0', 'TextDomain' => 'b' ),
		);
		list( $site, $http ) = $this->site( $plugins );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertSame( array( 'b' ), array_column( $body['components'], 'slug' ), 'only the component that fits is sent' );
		$this->assertStringNotContainsString( str_repeat( '9', 64 ) . '"', $http->requests[0]['args']['body'], 'the version is not truncated into a different one' );
		$this->assertCount( 1, $body['client_events'] );
		$this->assertSame( 'version_too_long', $body['client_events'][0]['code'] );
		$this->assertSame( 'a', $body['client_events'][0]['slug'] );
		$this->assertSame( 'plugin', $body['client_events'][0]['kind'] );
	}

	public function test_a_slug_over_200_characters_is_left_out_and_one_of_exactly_200_is_sent(): void {
		$fits = str_repeat( 'f', 200 );
		$over = str_repeat( 'o', 201 );
		$plugins = array(
			"{$fits}/{$fits}.php" => array( 'Name' => 'Fits', 'Version' => '1', 'TextDomain' => $fits ),
			"{$over}/{$over}.php" => array( 'Name' => 'Over', 'Version' => '1', 'TextDomain' => $over ),
		);
		list( $site, $http ) = $this->site( $plugins );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertSame( array( $fits ), array_column( $body['components'], 'slug' ) );
	}

	public function test_a_text_domain_over_200_characters_falls_back_to_the_slug_and_200_is_kept(): void {
		$kept = str_repeat( 't', 200 );
		$plugins = array(
			'a/a.php' => array( 'Name' => 'A', 'Version' => '1', 'TextDomain' => $kept ),
			'b/b.php' => array( 'Name' => 'B', 'Version' => '1', 'TextDomain' => str_repeat( 't', 201 ) ),
		);
		list( $site, $http ) = $this->site( $plugins );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertSame( array( 'a' => $kept, 'b' => 'b' ), array_column( $body['components'], 'text_domain', 'slug' ) );
	}

	// -- inventory: domain and locale lists ----------------------------------

	public function test_extra_domain_lists_at_their_limits_are_sent_untouched(): void {
		$files = array( str_repeat( 'd', 200 ) . '-fr_FR.mo' );
		for ( $i = 1; $i < 512; $i++ ) {
			$files[] = sprintf( 'dom%04d-fr_FR.mo', $i );
		}
		list( $site, $http ) = $this->site( array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1', 'TextDomain' => 'a' ) ), $files );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertCount( 512, $body['components'][0]['extra_domains'] );
		$this->assertArrayNotHasKey( 'client_events', $body );
	}

	public function test_an_over_long_extra_domain_is_dropped_and_a_513th_is_cut_and_both_are_reported(): void {
		$files = array( str_repeat( 'd', 201 ) . '-fr_FR.mo' );
		for ( $i = 1; $i <= 513; $i++ ) {
			$files[] = sprintf( 'dom%04d-fr_FR.mo', $i );
		}
		list( $site, $http ) = $this->site( array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1', 'TextDomain' => 'a' ) ), $files );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertCount( 512, $body['components'][0]['extra_domains'] );
		$this->assertNotContains( str_repeat( 'd', 201 ), $body['components'][0]['extra_domains'] );
		$this->assertSame( array( 'list_trimmed' ), array_column( $body['client_events'], 'code' ) );
	}

	public function test_a_bundled_locale_of_35_characters_is_sent_and_one_of_36_is_dropped(): void {
		$fits = 'de_DE_' . str_repeat( 'a', 29 );   // 35 characters.
		$over = 'de_DE_' . str_repeat( 'b', 30 );   // 36 characters.
		$this->assertSame( 35, strlen( $fits ) );
		list( $site, $http ) = $this->site(
			array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1', 'TextDomain' => 'a' ) ),
			array( "a-{$fits}.mo", "a-{$over}.mo" )
		);

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertSame( array( $fits ), $body['components'][0]['bundled_locales'] );
		$this->assertSame( array( 'list_trimmed' ), array_column( $body['client_events'], 'code' ) );
	}

	public function test_the_locale_list_drops_a_locale_over_35_characters(): void {
		list( $site, $http, $inventory ) = $this->site( array() );
		$inventory->set_manual_locales( array( str_repeat( 'x', 35 ), str_repeat( 'y', 36 ) ) );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertContains( str_repeat( 'x', 35 ), $body['locales'] );
		$this->assertNotContains( str_repeat( 'y', 36 ), $body['locales'] );
	}

	// -- inventory: installed map --------------------------------------------

	private function manifest_with_locales( int $count ): Babilim_Manifest {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		for ( $i = 0; $i < $count; $i++ ) {
			$locale = 'q' . chr( 97 + intdiv( $i, 26 ) ) . chr( 97 + $i % 26 ) . '_QQ';
			$manifest->set( 'plugin', 'a', $locale, array( 'build_id' => sprintf( 'bd_%012x', $i + 1 ), 'files' => array(), 'integrity' => array( 'ok' => true, 'checked_at' => '2026-01-01T00:00:00Z' ) ) );
		}
		return $manifest;
	}

	public function test_an_installed_map_of_256_locales_is_sent_whole(): void {
		list( $site, $http ) = $this->site( array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1', 'TextDomain' => 'a' ) ), array(), $this->manifest_with_locales( 256 ) );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertCount( 256, (array) $body['components'][0]['installed'] );
		$this->assertArrayNotHasKey( 'client_events', $body );
	}

	public function test_an_installed_map_of_257_locales_is_cut_to_256_and_reported(): void {
		list( $site, $http ) = $this->site( array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1', 'TextDomain' => 'a' ) ), array(), $this->manifest_with_locales( 257 ) );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertCount( 256, (array) $body['components'][0]['installed'] );
		$this->assertSame( array( 'installed_trimmed' ), array_column( $body['client_events'], 'code' ) );
	}

	// -- client events -------------------------------------------------------

	public function test_a_client_event_outside_the_schema_limits_is_never_kept(): void {
		$state = new Babilim_State();
		$state->record_client_event( str_repeat( 'c', 101 ), 'plugin', 'a', 'de_DE' );
		$state->record_client_event( 'x', 'plugin', str_repeat( 's', 201 ), 'de_DE' );
		$state->record_client_event( 'x', 'plugin', 'a', str_repeat( 'l', 36 ) );
		$state->record_client_event( 'x', 'plugin', '', 'de_DE' );
		$this->assertSame( array(), $state->pending_client_events() );

		$state->record_client_event( str_repeat( 'c', 100 ), 'plugin', str_repeat( 's', 200 ), str_repeat( 'l', 35 ) );
		$this->assertCount( 1, $state->pending_client_events() );
	}

	// -- registration --------------------------------------------------------

	private function register_and_capture(): array {
		$http = new Babilim_Fake_Http( array( new WP_Error( 'babilim_fake', 'stop after the first request' ) ) );
		list( $site ) = $this->site( array(), array(), null, $http );
		delete_option( 'babilim_site_id' );
		delete_option( 'babilim_site_token' );
		$site->ensure_registered();
		return array( $http, $site );
	}

	public function test_registration_with_every_field_at_its_limit_is_sent_and_matches_the_schema(): void {
		$GLOBALS['wp_version']                = str_repeat( '6', 32 );
		$GLOBALS['babilim_test_home_url']     = 'https://example.org/' . str_repeat( 'p', 2048 - 20 );
		$GLOBALS['babilim_test_site_locale']  = str_repeat( 'l', 35 );

		list( $http ) = $this->register_and_capture();

		$this->assertCount( 1, $http->requests );
		$this->assertMatchesSchema( $http->requests[0]['args']['body'], 'sites.register.request.schema.json' );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( 2048, strlen( $sent['home_url'] ) );
	}

	public function test_registration_shortens_only_the_informational_version_strings(): void {
		$GLOBALS['wp_version'] = str_repeat( '6', 40 );

		list( $http ) = $this->register_and_capture();

		$this->assertMatchesSchema( $http->requests[0]['args']['body'], 'sites.register.request.schema.json' );
		$this->assertSame( str_repeat( '6', 32 ), json_decode( $http->requests[0]['args']['body'], true )['wp_version'] );
	}

	public function test_registration_is_not_sent_for_a_home_url_over_2048_characters(): void {
		$GLOBALS['babilim_test_home_url'] = 'https://example.org/' . str_repeat( 'p', 2049 - 20 );

		list( $http, $site ) = $this->register_and_capture();

		$this->assertCount( 0, $http->requests );
		$this->assertSame( 'babilim_home_url_invalid', $site->ensure_registered()->get_error_code() );
	}

	public function test_registration_is_not_sent_for_a_site_locale_over_35_characters(): void {
		$GLOBALS['babilim_test_site_locale'] = str_repeat( 'l', 36 );

		list( $http, $site ) = $this->register_and_capture();

		$this->assertCount( 0, $http->requests );
		$this->assertSame( 'babilim_site_locale_invalid', $site->ensure_registered()->get_error_code() );
	}

	public function test_registration_caps_the_locale_list_at_256_and_keeps_the_site_locale(): void {
		list( $site, , $inventory ) = $this->site( array() );
		$manual = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$manual[] = 'a' . chr( 97 + intdiv( $i, 26 ) ) . chr( 97 + $i % 26 ) . '_AA';
		}
		$inventory->set_manual_locales( $manual );
		$http = new Babilim_Fake_Http( array( new WP_Error( 'babilim_fake', 'stop' ) ) );
		$site = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );
		delete_option( 'babilim_site_id' );
		delete_option( 'babilim_site_token' );

		$site->ensure_registered();

		$this->assertMatchesSchema( $http->requests[0]['args']['body'], 'sites.register.request.schema.json' );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertCount( 256, $sent['locales'] );
		$this->assertContains( 'de_DE', $sent['locales'] );
	}

	// -- verify / download ---------------------------------------------------

	public function test_verify_with_a_nonce_outside_the_contract_pattern_is_not_sent(): void {
		$http   = new Babilim_Fake_Http( array() );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );

		foreach ( array( 'short', str_repeat( 'a', 15 ), str_repeat( 'a', 65 ), str_repeat( 'A', 32 ), str_repeat( 'g', 32 ) ) as $nonce ) {
			$this->assertSame( 'babilim_invalid_nonce', $client->verify( 'st_x', $nonce, 'b64:x' )->get_error_code(), $nonce );
		}
		$this->assertSame( 'babilim_invalid_nonce', $client->verify( 'st_x', str_repeat( 'a', 32 ), '' )->get_error_code(), 'empty proof' );
		$this->assertSame( 'babilim_invalid_nonce', $client->verify( 'st_x', str_repeat( 'a', 32 ), str_repeat( 'p', 201 ) )->get_error_code(), 'proof over 200' );
		$this->assertCount( 0, $http->requests );
	}

	public function test_verify_at_the_nonce_and_proof_limits_is_sent_and_matches_the_schema(): void {
		foreach ( array( 16, 64 ) as $length ) {
			$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ) ) );
			$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', null );
			$client->verify( 'st_x', str_repeat( 'a', $length ), str_repeat( 'p', 200 ) );
			$this->assertCount( 1, $http->requests );
			$this->assertMatchesSchema( $http->requests[0]['args']['body'], 'sites.verify.request.schema.json' );
		}
	}

	public function test_the_plugins_own_proof_fits_the_schema(): void {
		$sk    = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$proof = 'b64:' . base64_encode( sodium_crypto_sign_detached( "babilim-challenge-v1\nst_x\n" . str_repeat( 'a', 32 ), $sk ) );
		$this->assertLessThanOrEqual( 200, strlen( $proof ) );
	}

	public function test_download_with_a_build_id_outside_the_pattern_is_not_sent_and_a_valid_one_matches_the_schema(): void {
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 302, 'message' => '' ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/x' ) ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok' );

		foreach ( array( '', 'bd_123', 'bd_00000000000G', 'bd_0000000000001', 'BD_000000000001' ) as $bad ) {
			$this->assertSame( 'babilim_invalid_build_id', $client->download( $bad )->get_error_code(), $bad );
		}
		$this->assertCount( 0, $http->requests );

		$client->download( 'bd_0123456789ab' );
		$this->assertCount( 1, $http->requests );
		$this->assertMatchesSchema( $http->requests[0]['args']['body'], 'downloads.request.schema.json' );
	}

	public function test_rotate_token_body_still_matches_its_schema(): void {
		$http   = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ) ) );
		$client = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok' );
		$client->rotate_token( 'st_x' );
		$this->assertMatchesSchema( $http->requests[0]['args']['body'], 'sites.rotate-token.request.schema.json' );
	}

	// -- plugin slug resolution -------------------------------------

	/**
	 * @return array<string,array{0:string,1:?string,2:?string,3:string}> [file, transient slug, persisted slug, expected slug].
	 */
	public static function slug_cases(): array {
		return array(
			'root file, transient knows the slug'                 => array( 'hello.php', 'hello-dolly', null, 'hello-dolly' ),
			'root file, no transient, persisted map remembers'    => array( 'hello.php', null, 'hello-dolly', 'hello-dolly' ),
			'root file, no transient, nothing persisted'          => array( 'hello.php', null, null, 'hello' ),
			'root file, transient wins over an older persisted'   => array( 'hello.php', 'hello-dolly', 'old-name', 'hello-dolly' ),
			'root file, unsafe persisted value is ignored'        => array( 'hello.php', null, 'not/safe', 'hello' ),
			'directory plugin, transient knows the slug'          => array( 'dolly-dir/main.php', 'dolly', null, 'dolly' ),
			'directory plugin, no transient, persisted remembers' => array( 'dolly-dir/main.php', null, 'dolly', 'dolly' ),
			'directory plugin, no transient, nothing persisted'   => array( 'dolly-dir/main.php', null, null, 'dolly-dir' ),
		);
	}

	private function inventory_for_file( string $file ): Babilim_Inventory {
		return new Babilim_Inventory(
			static function () use ( $file ) { return array( $file => array( 'Name' => 'X', 'Version' => '1.2.3', 'TextDomain' => 'x' ) ); },
			static function () { return array(); },
			static function ( $name ) { return get_site_transient( $name ); },
			static function () { return array(); },
			static function () { return array(); }
		);
	}

	/**
	 * @dataProvider slug_cases
	 */
	public function test_the_inventory_resolves_a_plugin_slug_from_transient_then_persisted_map_then_file_name( string $file, ?string $transient_slug, ?string $persisted, string $expected ): void {
		if ( null !== $persisted ) {
			update_option( Babilim_Inventory::PLUGIN_SLUGS_OPTION, array( $file => $persisted ) );
		}
		if ( null !== $transient_slug ) {
			set_site_transient( 'update_plugins', (object) array( 'response' => array(), 'no_update' => array( $file => (object) array( 'slug' => $transient_slug ) ) ) );
		}

		$slugs = array_column( $this->inventory_for_file( $file )->components(), 'slug' );

		$this->assertSame( array( $expected ), $slugs );
	}

	/**
	 * @dataProvider slug_cases
	 */
	public function test_the_shared_slug_function_gives_the_inventory_the_same_answer( string $file, ?string $transient_slug, ?string $persisted, string $expected ): void {
		if ( null !== $persisted ) {
			update_option( Babilim_Inventory::PLUGIN_SLUGS_OPTION, array( $file => $persisted ) );
		}
		$transient = null === $transient_slug ? false : (object) array( 'response' => array(), 'no_update' => array( $file => (object) array( 'slug' => $transient_slug ) ) );

		$this->assertSame( $expected, Babilim_Inventory::plugin_slug_from_file( $file, $transient ) );
	}

	public function test_a_slug_seen_in_the_transient_survives_the_transient_being_cleared(): void {
		set_site_transient( 'update_plugins', (object) array( 'response' => array(), 'no_update' => array( 'hello.php' => (object) array( 'slug' => 'hello-dolly' ) ) ) );
		$inventory = $this->inventory_for_file( 'hello.php' );
		$this->assertSame( array( 'hello-dolly' ), array_column( $inventory->components(), 'slug' ) );

		delete_site_transient( 'update_plugins' );

		$this->assertSame( array( 'hello-dolly' ), array_column( $inventory->components(), 'slug' ) );
		$this->assertSame( array( 'hello.php' => 'hello-dolly' ), get_option( Babilim_Inventory::PLUGIN_SLUGS_OPTION ) );
	}

	public function test_the_remembered_slug_of_a_plugin_that_is_no_longer_installed_is_forgotten(): void {
		update_option( Babilim_Inventory::PLUGIN_SLUGS_OPTION, array( 'hello.php' => 'hello-dolly', 'gone/gone.php' => 'gone' ) );

		$this->inventory_for_file( 'hello.php' )->components();

		$this->assertSame( array( 'hello.php' => 'hello-dolly' ), get_option( Babilim_Inventory::PLUGIN_SLUGS_OPTION ) );
	}

	public function test_a_steady_transient_writes_nothing_after_the_first_read(): void {
		set_site_transient( 'update_plugins', (object) array( 'response' => array(), 'no_update' => array( 'hello.php' => (object) array( 'slug' => 'hello-dolly' ) ) ) );
		$writes = 0;
		add_filter(
			'sanitize_option_' . Babilim_Inventory::PLUGIN_SLUGS_OPTION,
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);
		$inventory = $this->inventory_for_file( 'hello.php' );
		$inventory->components();
		$this->assertSame( 1, $writes, 'the first read remembers the slug (the probe itself sees writes)' );

		$inventory->components();

		$this->assertSame( 1, $writes, 'a second read with the same transient writes nothing' );
	}

	public function test_an_empty_plugin_list_does_not_wipe_the_remembered_slugs(): void {
		update_option( Babilim_Inventory::PLUGIN_SLUGS_OPTION, array( 'hello.php' => 'hello-dolly' ) );
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); },
			static function () { return array(); }
		);

		$inventory->components();

		$this->assertSame( array( 'hello.php' => 'hello-dolly' ), get_option( Babilim_Inventory::PLUGIN_SLUGS_OPTION ) );
	}

	public function test_an_installed_record_with_a_malformed_build_id_is_left_out_and_reported(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$manifest->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'not-a-build', 'files' => array(), 'integrity' => array( 'ok' => true, 'checked_at' => '2026-01-01T00:00:00Z' ) ) );
		$manifest->set( 'plugin', 'a', 'fr_FR', array( 'build_id' => 'bd_0123456789ab', 'files' => array(), 'integrity' => array( 'ok' => true, 'checked_at' => '2026-01-01T00:00:00Z' ) ) );
		list( $site, $http ) = $this->site( array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1', 'TextDomain' => 'a' ) ), array(), $manifest );

		$site->sync();

		$body = $this->sent_inventory( $http );
		$this->assertSame( array( 'fr_FR' ), array_keys( (array) $body['components'][0]['installed'] ) );
		$this->assertSame( array( 'build_id_invalid' ), array_column( $body['client_events'], 'code' ) );
	}
}
