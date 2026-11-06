<?php
/**
 * The toolbar "Report translation" panel: the toolbar node, the script
 * it loads, and the two admin-ajax endpoints behind it.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A stand-in for WP_Admin_Bar that records the nodes added. */
final class Babilim_Test_Admin_Bar {
	/** @var array<int,array> */
	public $nodes = array();

	public function add_node( array $args ): void {
		$this->nodes[] = $args;
	}
}

final class Test_Admin_Report_Panel extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$GLOBALS['babilim_test_is_admin']           = true;
		$GLOBALS['babilim_test_current_user_can']   = true;
		$GLOBALS['babilim_test_current_locale']     = 'fr_FR';
		$GLOBALS['babilim_test_get_plugins_return'] = array();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		Babilim::reset_for_tests();
	}

	/** @var Babilim_Memory_Fs|null The fs the installed-translation fixture lives in. */
	private $mem_fs = null;

	/** @var array<string,mixed> What the canned parser returns for the installed file. */
	private $entries = array( 'Cart' => 'Panier' );

	/** @var callable|null Runs while the installed file is being parsed. */
	private $on_parse = null;

	private function admin( ?Babilim_Http $http = null, ?Babilim_Fs $fs = null ): Babilim_Admin {
		$test  = $this;
		$parse = static function ( string $path ) use ( $test ): array {
			return $test->parse_for_test( $path );
		};
		return new Babilim_Admin( $fs ?? $this->mem_fs ?? new Babilim_Memory_Fs(), $http ?? new Babilim_Fake_Http( array() ), null, null, $parse );
	}

	/** @internal Used by the canned parser closure. */
	public function parse_for_test( string $path ): array {
		if ( null !== $this->on_parse ) {
			call_user_func( $this->on_parse, $path );
		}
		return $this->entries;
	}

	private function mo_path(): string {
		return Babilim_Paths::file( 'plugins', 'woocommerce', 'fr_FR', 'mo' );
	}

	private function install_record( string $build_id, string $bytes = 'MO' ): void {
		$this->mem_fs = $this->mem_fs ?? new Babilim_Memory_Fs();
		$path         = $this->mo_path();
		$this->mem_fs->put( $path, $bytes );
		( new Babilim_Manifest( $this->mem_fs ) )->set(
			'plugin',
			'woocommerce',
			'fr_FR',
			array(
				'component_version' => '1.0.0',
				'build_id'          => $build_id,
				'files'             => array( str_replace( Babilim_Paths::root() . '/', '', $path ) => hash( 'sha256', $bytes ) ),
				'default_files'     => array(),
			)
		);
	}

	private function request( string $action, array $fields ): void {
		$fields['_ajax_nonce'] = wp_create_nonce( $action );
		$_POST                 = $fields;
		$_REQUEST              = $fields;
	}

	/** Runs an ajax handler; returns what it sent via wp_send_json_*(). */
	private function sent( callable $handler ): array {
		$GLOBALS['babilim_test_json'] = null;
		try {
			$handler();
		} catch ( Babilim_Test_Wp_Send_Json_Exception $e ) {
			return (array) $GLOBALS['babilim_test_json'];
		}
		$this->fail( 'the handler sent nothing' );
		return array();
	}

	private function nodes(): array {
		$bar = new Babilim_Test_Admin_Bar();
		$this->admin()->register_toolbar_node( $bar );
		return $bar->nodes;
	}

	public function test_init_hooks_the_toolbar_script_and_the_ajax_actions(): void {
		$this->admin()->init();
		$bar = array_column( $GLOBALS['babilim_test_actions']['admin_bar_menu'] ?? array(), 'priority' );
		$this->assertSame( array( 100 ), $bar );
		$this->assertArrayHasKey( 'wp_ajax_babilim_report_panel', $GLOBALS['babilim_test_actions'] );
		$this->assertArrayHasKey( 'wp_ajax_babilim_translation_search', $GLOBALS['babilim_test_actions'] );
		$this->assertArrayHasKey( 'wp_ajax_babilim_translation_report', $GLOBALS['babilim_test_actions'] );
	}

	/** The JSON the loader is configured with (its inline "before" script). */
	private function loader_config(): array {
		$this->assertArrayHasKey( 'babilim-report-loader', $GLOBALS['babilim_test_inline_scripts'] );
		foreach ( $GLOBALS['babilim_test_inline_scripts']['babilim-report-loader'] as $js ) {
			if ( 1 === preg_match( '/^var babilimReportLoader = (\{.*\});$/s', $js, $m ) ) {
				return (array) json_decode( $m[1], true );
			}
		}
		$this->fail( 'the loader has no configuration' );
		return array();
	}

	/** Runs the panel-config ajax action; returns what it sent. */
	private function panel_config(): array {
		$this->request( 'babilim_report_panel', array() );
		return $this->sent( array( $this->admin(), 'ajax_babilim_report_panel' ) );
	}

	public function test_toolbar_node_only_for_capable_users_with_consent(): void {
		update_option( 'babilim_consent', true );
		$nodes = $this->nodes();
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'babilim-report', $nodes[0]['id'] );
		$this->assertSame( '#', $nodes[0]['href'] );
		$this->assertSame( 'Report translation', $nodes[0]['title'] );

		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->assertSame( array(), $this->nodes(), 'no capability' );

		$GLOBALS['babilim_test_current_user_can'] = true;
		$GLOBALS['babilim_test_is_admin']         = false;
		$this->assertSame( array(), $this->nodes(), 'not the admin side' );

		$GLOBALS['babilim_test_is_admin'] = true;
		update_option( 'babilim_consent', false );
		$this->assertSame( array(), $this->nodes(), 'no consent' );
	}

	public function test_only_a_small_loader_is_enqueued_and_only_when_the_node_is_shown(): void {
		$this->admin()->enqueue_report_script();
		$this->assertSame( array(), $GLOBALS['babilim_test_enqueued_scripts'] );

		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_x', 'token' => 'tok_SECRET_value' ), false );
		$this->admin()->enqueue_report_script();
		$this->assertSame( array( 'babilim-report-loader' => true ), $GLOBALS['babilim_test_enqueued_scripts'], 'the panel script itself waits for a click' );
		$this->assertFalse( $GLOBALS['babilim_test_registered_scripts']['babilim-report-loader']['src'], 'the loader has no file of its own' );
		$this->assertSame( array(), $GLOBALS['babilim_test_localized_scripts'], 'the panel settings wait for a click too' );
		$config = $this->loader_config();
		$this->assertSame( array( 'ajaxUrl', 'nonce', 'src' ), array_keys( $config ) );
		$this->assertSame( wp_create_nonce( 'babilim_report_panel' ), $config['nonce'] );
		$this->assertSame( plugins_url( 'admin/js/babilim-report.js', BABILIM_FILE ) . '?ver=' . BABILIM_VERSION, $config['src'] );
		$this->assertNotEmpty( $config['ajaxUrl'] );
		$this->assertStringNotContainsString( 'tok_SECRET_value', implode( "\n", $GLOBALS['babilim_test_inline_scripts']['babilim-report-loader'] ) );
	}

	public function test_the_loader_fetches_the_settings_and_the_script_on_the_first_click(): void {
		$m = new ReflectionMethod( Babilim_Admin::class, 'report_loader_js' );
		$m->setAccessible( true );
		$js = (string) $m->invoke( null );
		$this->assertStringContainsString( "'#wp-admin-bar-babilim-report > a'", $js );
		$this->assertStringContainsString( "'babilim_report_panel'", $js );
		$this->assertStringContainsString( 'window.babilimReport = ', $js );
		$this->assertStringContainsString( "document.createElement('script')", $js );
		$this->assertStringContainsString( 'link.click()', $js, 'the click that loaded the panel then opens it' );
		$this->assertStringNotContainsString( 'innerHTML', $js );
	}

	/**
	 * The loader's script, run in Node against a fake DOM
	 * (tests/support/report-loader-sim.mjs). A success opens the panel
	 * once. Any failure (the ajax answer -1, an error answer, no network,
	 * the script not loading) puts the loader back as it was -- no
	 * settings left, no script left, no reload, no navigation, a
	 * developer-only console.error -- so the next click tries again.
	 */
	public function test_the_loader_opens_the_panel_or_resets_so_the_next_click_tries_again(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed' );
		}
		$m = new ReflectionMethod( Babilim_Admin::class, 'report_loader_js' );
		$m->setAccessible( true );
		$file = BABILIM_TEST_TMP_ROOT . '/report-loader-' . bin2hex( random_bytes( 4 ) ) . '.js';
		file_put_contents( $file, (string) $m->invoke( null ) );
		try {
			exec( escapeshellarg( $node ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/support/report-loader-sim.mjs' ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $out, $rc );
		} finally {
			unlink( $file );
		}
		$this->assertSame( 0, $rc, implode( "\n", $out ) );
		$r = json_decode( (string) end( $out ), true );
		$this->assertIsArray( $r, implode( "\n", $out ) );

		$ok = $r['ok'];
		$this->assertTrue( $ok['firstPrevented'] );
		$this->assertTrue( $ok['whileLoadingPrevented'], 'a second click while loading does nothing' );
		$this->assertSame( array( 1, 1, 1, true ), array( $ok['afterFirst']['fetches'], $ok['afterFirst']['scripts'], $ok['afterFirst']['opens'], $ok['afterFirst']['settings'] ), 'asked once, script added once, panel opened' );
		$this->assertFalse( $ok['secondPrevented'], 'later clicks are left to the panel script' );
		$this->assertFalse( $ok['thirdPrevented'] );
		$this->assertSame( array( 1, 1, 0, 0, 0 ), array( $ok['end']['fetches'], $ok['end']['scripts'], $ok['end']['reloads'], $ok['end']['assigned'], $ok['end']['errors'] ) );

		foreach ( array( 'refusedThenOk', 'errorThenOk', 'networkThenOk', 'scriptFailsThenOk' ) as $case ) {
			$c = $r[ $case ];
			$after = $c['afterFirst'];
			$this->assertSame( 0, $after['reloads'], $case . ': no reload' );
			$this->assertSame( 0, $after['assigned'], $case . ': no navigation' );
			$this->assertSame( 0, $after['opens'], $case . ': no panel' );
			$this->assertFalse( $after['settings'], $case . ': no settings left behind' );
			$this->assertSame( $after['scripts'], $after['removed'], $case . ': no script left behind' );
			$this->assertSame( 1, $after['errors'], $case . ': one console.error for developers' );
			$this->assertTrue( $c['secondPrevented'], $case . ': the next click is the loader\'s again' );
			$this->assertSame( 2, $c['afterSecond']['fetches'], $case . ': the next click asks again' );
			$this->assertSame( 1, $c['afterSecond']['opens'], $case . ': and opens the panel when that works' );
			$this->assertTrue( $c['afterSecond']['settings'], $case );
			$this->assertFalse( $c['thirdPrevented'], $case . ': then clicks are the panel\'s' );
			$this->assertSame( array( 0, 0 ), array( $c['end']['reloads'], $c['end']['assigned'] ), $case );
		}

		$never = $r['alwaysRefused'];
		$this->assertTrue( $never['secondPrevented'] );
		$this->assertTrue( $never['thirdPrevented'] );
		$this->assertSame( array( 3, 0, 0, 0, 3 ), array( $never['end']['fetches'], $never['end']['opens'], $never['end']['reloads'], $never['end']['assigned'], $never['end']['errors'] ), 'every click tries again; nothing else happens' );
		foreach ( $never['errorText'] as $text ) {
			$this->assertStringStartsWith( 'Babilim: ', $text, 'a plain English developer message' );
		}
	}

	public function test_the_panel_settings_come_from_the_ajax_action(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_x', 'token' => 'tok_SECRET_value' ), false );
		$out = $this->panel_config();
		$this->assertTrue( $out['success'] );
		$data = $out['data'];
		$this->assertSame( 'fr_FR', $data['locale'] );
		$this->assertSame( Babilim_Admin::FEEDBACK_CATEGORIES, $data['categories'] );
		$this->assertSame( wp_create_nonce( 'babilim_translation_search' ), $data['searchNonce'] );
		$this->assertSame( wp_create_nonce( 'babilim_translation_report' ), $data['reportNonce'] );
		$this->assertNotEmpty( $data['ajaxUrl'] );
		$this->assertNotEmpty( $data['strings'] );
		$this->assertStringNotContainsString( 'tok_SECRET_value', (string) wp_json_encode( $data ) );
	}

	public function test_the_panel_settings_need_the_nonce_the_capability_consent_and_the_setting(): void {
		update_option( 'babilim_consent', true );
		$_REQUEST = array();
		try {
			$this->admin()->ajax_babilim_report_panel();
			$this->fail( 'a missing nonce must stop the request' );
		} catch ( \Throwable $e ) {
			$this->assertNull( $GLOBALS['babilim_test_json'] ?? null );
		}

		$GLOBALS['babilim_test_current_user_can'] = false;
		$out = $this->panel_config();
		$this->assertFalse( $out['success'] );
		$this->assertSame( 403, $out['status'] );
		$GLOBALS['babilim_test_current_user_can'] = true;

		update_option( 'babilim_consent', false );
		$this->assertFalse( $this->panel_config()['success'], 'no consent' );
		update_option( 'babilim_consent', true );

		update_option( Babilim_Admin::OPTION_TOOLBAR_REPORT, 0 );
		$this->assertFalse( $this->panel_config()['success'], 'the toolbar entry is off' );
	}

	public function test_the_script_is_not_enqueued_without_an_admin_bar(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_admin_bar_showing'] = false;
		$this->admin()->enqueue_report_script();
		$this->assertSame( array(), $GLOBALS['babilim_test_enqueued_scripts'] );
	}

	public function test_the_toolbar_entry_and_script_disappear_when_the_setting_is_off(): void {
		update_option( 'babilim_consent', true );
		update_option( Babilim_Admin::OPTION_TOOLBAR_REPORT, 0 );
		$this->assertSame( array(), $this->nodes() );
		$this->admin()->enqueue_report_script();
		$this->assertSame( array(), $GLOBALS['babilim_test_enqueued_scripts'] );

		update_option( Babilim_Admin::OPTION_TOOLBAR_REPORT, 1 );
		$this->assertCount( 1, $this->nodes() );
		$this->admin()->enqueue_report_script();
		$this->assertArrayHasKey( 'babilim-report-loader', $GLOBALS['babilim_test_enqueued_scripts'] );
	}

	public function test_search_ajax_requires_nonce_and_returns_no_locale_flag(): void {
		$_REQUEST = array( 'q' => 'Save' );
		try {
			$this->admin()->ajax_babilim_translation_search();
			$this->fail( 'a missing nonce must stop the request' );
		} catch ( \Throwable $e ) {
			$this->assertNull( $GLOBALS['babilim_test_json'] );
		}

		$this->request( 'babilim_translation_search', array( 'q' => 'Save' ) );
		$out = $this->sent( array( $this->admin(), 'ajax_babilim_translation_search' ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( array( 'candidates' => array(), 'no_locale' => true ), $out['data'] );
	}

	public function test_search_ajax_refuses_a_user_without_the_capability(): void {
		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->request( 'babilim_translation_search', array( 'q' => 'Save' ) );
		$out = $this->sent( array( $this->admin(), 'ajax_babilim_translation_search' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 403, $out['status'] );
	}

	public function test_search_ajax_answers_an_error_when_the_manifest_is_unavailable(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::root() . '/_manifest.json', '{not valid json' );
		$this->request( 'babilim_translation_search', array( 'q' => 'Save' ) );
		$out = $this->sent( array( $this->admin( null, $fs ), 'ajax_babilim_translation_search' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'manifest_unavailable', $out['data']['code'] );
	}

	public function test_component_label_uses_the_plugin_or_theme_name_and_falls_back_to_the_slug(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce' ),
			'hello.php'                   => array( 'Name' => 'Hello Dolly' ),
		);
		$this->assertSame( 'WooCommerce', Babilim_Admin::component_label( 'plugin', 'woocommerce' ) );
		$this->assertSame( 'Hello Dolly', Babilim_Admin::component_label( 'plugin', 'hello' ) );
		$this->assertSame( 'unknown-plugin', Babilim_Admin::component_label( 'plugin', 'unknown-plugin' ) );

		$GLOBALS['babilim_test_wp_get_theme_by_stylesheet']['twentyx'] = new class() {
			public function exists() {
				return true;
			}
			public function get( $key ) {
				return 'Name' === $key ? 'Twenty X' : false;
			}
		};
		$this->assertSame( 'Twenty X', Babilim_Admin::component_label( 'theme', 'twentyx' ) );
		$this->assertSame( 'no-such-theme', Babilim_Admin::component_label( 'theme', 'no-such-theme' ) );
	}

	private function report_fields( array $over = array() ): array {
		return array_merge(
			array(
				'kind'           => 'plugin',
				'slug'           => 'woocommerce',
				'locale'         => 'fr_FR',
				'build_id'       => 'bd_000000000001',
				'string_ref'     => Babilim_Translation_Search::ref( '', 'Cart' ),
				'suggested'      => 'Chariot',
				'note'           => 'n',
			),
			$over
		);
	}

	private function known_build(): void {
		update_option( 'babilim_consent', true );
		$this->install_record( 'bd_000000000001' );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'woocommerce',
						'locales' => array( 'fr_FR' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000001' ) ),
					),
				),
			)
		);
	}

	public function test_report_ajax_builds_payload_without_screen_path_and_defaults_category_other(): void {
		$this->known_build();
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'feedback_id' => 'fb_000000000001' ) ), 'headers' => array() ) ) );
		$this->request( 'babilim_translation_report', $this->report_fields( array( 'screen_path' => '/wp-admin/secret' ) ) );

		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );

		$this->assertTrue( $out['success'] );
		$this->assertSame( array( 'feedback_id' => 'fb_000000000001' ), $out['data'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( 'other', $sent['category'] );
		$this->assertArrayNotHasKey( 'screen_path', $sent );
		$this->assertSame( 'Panier', $sent['current_msgstr'] );
		$this->assertSame( 'Chariot', $sent['suggested'] );
		$this->assertSame( 'Cart', $sent['msgid'] );
		$this->assertArrayNotHasKey( 'msgctxt', $sent );
	}

	public function test_report_ajax_requires_nonce_and_capability(): void {
		$this->known_build();
		$http = new Babilim_Fake_Http( array() );
		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->request( 'babilim_translation_report', $this->report_fields() );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 403, $out['status'] );
		$this->assertCount( 0, $http->requests );
	}

	public function test_report_ajax_passes_a_validation_error_code_on_without_sending(): void {
		$this->known_build();
		$http = new Babilim_Fake_Http( array() );
		$this->request( 'babilim_translation_report', $this->report_fields( array( 'build_id' => 'nope' ) ) );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'invalid_build_id', $out['data']['code'] );
		$this->assertCount( 0, $http->requests );
	}

	public function test_report_422_unprocessable_maps_to_cannot_report_message(): void {
		$this->known_build();
		$body = wp_json_encode( array( 'error' => array( 'code' => 'unprocessable', 'message' => 'm', 'hint' => 'h' ) ) );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 422 ), 'body' => $body, 'headers' => array() ) ) );
		$this->request( 'babilim_translation_report', $this->report_fields() );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'babilim_unprocessable', $out['data']['code'] );

		// The panel script turns that code into the fixed sentence.
		$data = $this->localized_strings();
		$this->assertSame( "This string can't be reported from this site.", $data['unprocessable'] );
		$this->assertSame( 'Too many reports today. Try again tomorrow.', $data['rate_limited'] );
		$this->assertSame( 'The report could not be sent.', $data['failed'] );
	}

	public function test_report_payload_msgid_matches_a_600_character_original_exactly(): void {
		$this->known_build();
		$id            = str_repeat( 'k', 600 );
		$this->entries = array( $id => 'Panier' );
		$http          = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'feedback_id' => 'fb_000000000001' ) ), 'headers' => array() ) ) );
		$this->request( 'babilim_translation_report', $this->report_fields( array( 'string_ref' => Babilim_Translation_Search::ref( '', $id ) ) ) );
		$this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( $id, $sent['msgid'] );
	}

	private function created_http(): Babilim_Fake_Http {
		return new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'feedback_id' => 'fb_000000000001' ) ), 'headers' => array() ) ) );
	}

	public function test_report_ajax_builds_payload_from_the_installed_string_not_the_request(): void {
		$this->known_build();
		$this->entries = array( "ctx\x04Cart" => 'Panier' );
		$http          = $this->created_http();
		$this->request( 'babilim_translation_report', $this->report_fields( array( 'string_ref' => Babilim_Translation_Search::ref( 'ctx', 'Cart' ) ) ) );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertTrue( $out['success'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( 'Cart', $sent['msgid'] );
		$this->assertSame( 'ctx', $sent['msgctxt'] );
		$this->assertSame( 'Panier', $sent['current_msgstr'] );
	}

	public function test_report_ajax_refuses_a_reference_that_is_not_installed(): void {
		$this->known_build();
		$http = new Babilim_Fake_Http( array() );
		$this->request( 'babilim_translation_report', $this->report_fields( array( 'string_ref' => Babilim_Translation_Search::ref( '', 'Nope' ) ) ) );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'unknown_string', $out['data']['code'] );
		$this->assertCount( 0, $http->requests );
	}

	/** @dataProvider malformed_references */
	public function test_report_ajax_refuses_a_malformed_reference( $ref ): void {
		$this->known_build();
		$http = new Babilim_Fake_Http( array() );
		$this->request( 'babilim_translation_report', $this->report_fields( array( 'string_ref' => $ref ) ) );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'invalid_string_ref', $out['data']['code'] );
		$this->assertCount( 0, $http->requests );
	}

	/** @return array<string,array{0:mixed}> */
	public static function malformed_references(): array {
		$ref = hash( 'sha256', 'x' );
		return array(
			'upper case' => array( strtoupper( $ref ) ),
			'63 chars'   => array( substr( $ref, 0, 63 ) ),
			'array'      => array( array( $ref ) ),
			'empty'      => array( '' ),
		);
	}

	public function test_report_ajax_ignores_msgid_msgctxt_and_current_msgstr_sent_by_the_browser(): void {
		$this->known_build();
		$http = $this->created_http();
		$this->request(
			'babilim_translation_report',
			$this->report_fields(
				array(
					'msgid'          => 'Forged',
					'msgctxt'        => 'forged-ctx',
					'current_msgstr' => 'Forged translation',
				)
			)
		);
		$this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( 'Cart', $sent['msgid'] );
		$this->assertSame( 'Panier', $sent['current_msgstr'] );
		$this->assertArrayNotHasKey( 'msgctxt', $sent );
	}

	public function test_report_ajax_refuses_a_string_whose_installed_build_differs_from_the_reported_one(): void {
		$this->known_build();
		// The translation was updated after the search: same msgid, new msgstr, new build.
		$this->install_record( 'bd_000000000002', 'MO2' );
		$this->entries = array( 'Cart' => 'Chariot' );
		$http          = new Babilim_Fake_Http( array() );
		$this->request( 'babilim_translation_report', $this->report_fields() );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'stale_string', $out['data']['code'] );
		$this->assertCount( 0, $http->requests );
	}

	public function test_report_ajax_refuses_when_the_translation_file_changed_during_lookup(): void {
		$this->known_build();
		$fs             = $this->mem_fs;
		$path           = $this->mo_path();
		$this->on_parse = static function () use ( $fs, $path ): void {
			$fs->put( $path, 'CHANGED' );
		};
		$http = new Babilim_Fake_Http( array() );
		$this->request( 'babilim_translation_report', $this->report_fields() );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertSame( 'unknown_string', $out['data']['code'] );
		$this->assertCount( 0, $http->requests );
	}

	public function test_report_ajax_treats_a_lookup_that_throws_as_an_unknown_string(): void {
		$this->known_build();
		// A manifest record whose path is outside the allow-list makes Babilim_Fs::sha256() throw.
		( new Babilim_Manifest( $this->mem_fs ) )->set(
			'plugin',
			'woocommerce',
			'fr_FR',
			array(
				'component_version' => '1.0.0',
				'build_id'          => 'bd_000000000001',
				'files'             => array( '../../escape.mo' => hash( 'sha256', 'MO' ) ),
				'default_files'     => array(),
			)
		);
		$http = new Babilim_Fake_Http( array() );
		$this->request( 'babilim_translation_report', $this->report_fields() );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'unknown_string', $out['data']['code'] );
		$this->assertCount( 0, $http->requests );
	}

	public function test_report_omits_current_msgstr_for_a_single_4001_character_translation_but_still_sends(): void {
		$this->known_build();
		$this->entries = array( 'Cart' => str_repeat( 'z', 4001 ) );
		$http          = $this->created_http();
		$this->request( 'babilim_translation_report', $this->report_fields() );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertTrue( $out['success'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'current_msgstr', $sent );
		$this->assertSame( 'Cart', $sent['msgid'] );
	}

	public function test_report_omits_current_msgstr_when_plural_forms_fit_alone_but_not_combined(): void {
		$this->known_build();
		$this->entries = array( 'Cart' => 'Panier' . str_repeat( 'a', 2494 ) . "\0" . str_repeat( 'b', 2500 ) );
		$http          = $this->created_http();
		$this->request( 'babilim_translation_report', $this->report_fields() );
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertTrue( $out['success'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'current_msgstr', $sent );
	}

	public function test_report_sends_the_whole_current_msgstr_when_the_combined_forms_fit(): void {
		$this->known_build();
		$a             = str_repeat( 'a', 1999 );
		$b             = str_repeat( 'b', 1999 );
		$this->entries = array( 'Cart' => $a . "\0" . $b );
		$http          = $this->created_http();
		$this->request( 'babilim_translation_report', $this->report_fields() );
		$this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( $a . "\n" . $b, $sent['current_msgstr'] );
	}

	public function test_panel_script_sends_the_reference_not_the_strings(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/js/babilim-report.js' );
		$this->assertStringContainsString( 'string_ref: selected.ref', $js );
		$this->assertStringNotContainsString( 'msgid: selected.msgid', $js );
		$this->assertStringNotContainsString( 'msgctxt: selected.msgctxt', $js );
		$this->assertStringNotContainsString( 'current_msgstr: selected.current_msgstr', $js );
	}

	public function test_report_unknown_build_error_carries_a_message_the_panel_can_show(): void {
		$this->known_build();
		$this->request( 'babilim_translation_report', $this->report_fields( array( 'build_id' => 'bd_ffffffffffff' ) ) );
		$out = $this->sent( array( $this->admin(), 'ajax_babilim_translation_report' ) );
		$this->assertSame( 'unknown_build', $out['data']['code'] );
		$this->assertNotSame( '', $out['data']['message'] );
	}

	public function test_component_label_is_cached_for_the_request(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'alpha/alpha.php' => array( 'Name' => 'First' ) );
		$this->assertSame( 'First', Babilim_Admin::component_label( 'plugin', 'alpha' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'alpha/alpha.php' => array( 'Name' => 'Second' ) );
		$this->assertSame( 'First', Babilim_Admin::component_label( 'plugin', 'alpha' ) );
	}

	public function test_preview_strings_name_every_sent_field(): void {
		$strings = $this->localized_strings();
		foreach ( array( 'field_kind', 'field_slug', 'field_locale', 'field_build', 'field_context' ) as $key ) {
			$this->assertNotEmpty( $strings[ $key ] ?? '', $key );
		}
	}

	public function test_preview_sentence_says_reports_are_processed_by_ai(): void {
		$this->assertStringContainsString( 'AI models', $this->localized_strings()['processed'] );
	}

	public function test_search_query_is_sanitized_and_capped(): void {
		$m = new ReflectionMethod( Babilim_Admin::class, 'search_query' );
		$m->setAccessible( true );
		$_REQUEST = array( 'q' => '  Save   changes  ' );
		$this->assertSame( 'Save changes', $m->invoke( null ), 'passed through sanitize_text_field()' );
		$_REQUEST = array( 'q' => str_repeat( "\xC3\xA9", 250 ) );
		$this->assertSame( 200, mb_strlen( $m->invoke( null ), 'UTF-8' ) );
		$_REQUEST = array( 'q' => array( 'x' ) );
		$this->assertSame( '', $m->invoke( null ) );
	}

	public function test_panel_script_traps_tab_returns_focus_and_sends_the_whole_translation(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/js/babilim-report.js' );
		$this->assertStringContainsString( "'Tab' === event.key", $js );
		$this->assertStringContainsString( 'opener.focus()', $js );
		$this->assertMatchesRegularExpression( '/if \( ! panel \) \{\s*return;\s*\}\s*if \( \'Escape\' === event\.key \)/', $js );
	}

	public function test_report_free_text_fields_are_sanitized_as_textareas(): void {
		$this->known_build();
		$http = $this->created_http();
		$this->request(
			'babilim_translation_report',
			$this->report_fields(
				array(
					'note'      => "see <b>x</b> and <script>y</script>\r\nline 2",
					'suggested' => '<a href="#">Chariot</a>',
				)
			)
		);
		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );
		$this->assertTrue( $out['success'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( "see x and \nline 2", $sent['note'] );
		$this->assertSame( 'Chariot', $sent['suggested'] );
	}

	public function test_report_free_text_with_invalid_utf8_is_left_out_so_the_body_stays_encodable(): void {
		$this->known_build();
		$http = $this->created_http();
		$this->request( 'babilim_translation_report', $this->report_fields( array( 'note' => "caf\xC3\xA9 bad\xFF\xFEend" ) ) );

		$out = $this->sent( array( $this->admin( $http ), 'ajax_babilim_translation_report' ) );

		$this->assertTrue( $out['success'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'note', $sent );
		$this->assertSame( 'Chariot', $sent['suggested'] );
	}

	public function test_report_text_handling_does_not_need_mbstring_scrub(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-babilim-admin.php' );
		$this->assertStringNotContainsString( 'mb_scrub(', $src );
	}

	public function test_short_query_string_is_translatable_and_has_a_translator_comment(): void {
		$this->assertArrayHasKey( 'query_too_short', $this->localized_strings() );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-babilim-admin.php' );
		$this->assertMatchesRegularExpression( '/translators:[^\n]*\*\/\s*\n\s*\'query_too_short\'/', $src );
	}

	public function test_panel_script_asks_for_two_characters_and_separates_title_from_close(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/js/babilim-report.js' );
		$this->assertStringContainsString( 'S.query_too_short', $js );
		$this->assertStringContainsString( 'var MIN_QUERY = 2;', $js );
		$this->assertStringContainsString( '.length < MIN_QUERY', $js );
		$this->assertStringContainsString( 'justify-content:space-between', $js );
		$this->assertStringContainsString( 'flex-wrap:wrap', $js );
	}

	private function localized_strings(): array {
		update_option( 'babilim_consent', true );
		return $this->panel_config()['data']['strings'];
	}
}
