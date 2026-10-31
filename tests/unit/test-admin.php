<?php
/**
 * Tests for Babilim_Admin -- the admin-post.php action
 * handlers (nonce + capability + sanitized input), and the rendered
 * screens' own content rules (no quality/grade/verified wording, every
 * string translatable).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-kses.php';

final class Test_Admin extends TestCase {

	/**
	 * Real-disk paths a test wrote through Babilim_Wp_Fs and must clean
	 * up -- see tests/unit/test-uninstall.php's own $realDiskCleanup for
	 * why this is deleted in tearDown() rather than at the end of the
	 * test's own body.
	 *
	 * @var array{fs: Babilim_Wp_Fs, paths: string[]}|null
	 */
	private $realDiskCleanup;

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$this->realDiskCleanup = null;
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		Babilim::reset_for_tests();
		if ( null !== $this->realDiskCleanup ) {
			foreach ( $this->realDiskCleanup['paths'] as $path ) {
				$this->realDiskCleanup['fs']->delete( $path );
			}
		}
	}

	private function admin( ?Babilim_Http $http = null ): Babilim_Admin {
		return new Babilim_Admin( new Babilim_Memory_Fs(), $http ?? new Babilim_Fake_Http( array() ) );
	}

	private function post( string $action, array $fields ): void {
		$fields['action']    = $action;
		$fields['_wpnonce'] = wp_create_nonce( $action );
		$_POST               = $fields;
		$_REQUEST             = $fields;
	}

	/**
	 * Makes $build_id "known" for (kind, slug, locale) via the last-
	 * synced state -- what validate_feedback_request()'s own
	 * is_a_known_build() accepts a report's build_id against (see
	 * Babilim_Admin).
	 */
	private function set_known_build( string $kind, string $slug, string $locale, string $build_id ): void {
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => $kind,
						'slug'    => $slug,
						'locales' => array( $locale => array( 'status' => 'babilim_installed', 'build_id' => $build_id ) ),
					),
				),
			)
		);
	}

	// -- fixtures for a genuinely-installed component with two builds -----
	// (needed for a real Babilim_Installer::rollback() to succeed -- see
	// tests/unit/test-installer.php, whose fake transform/parse/unzip
	// callables this mirrors; Babilim_Admin's own installer() is bound to
	// Babilim::instance()'s real callables, which no-op without a real
	// WordPress install, so installing the fixture data must go through a
	// standalone Babilim_Installer against the same Babilim_Fs/Babilim_
	// Manifest instead -- rollback() itself never touches those callables
	// (see Babilim_Installer::finalize_build()), so Babilim_Admin's own
	// installer() can still perform the rollback the tests below dispatch.)

	private function transform_fake( Babilim_Fs $fs ): callable {
		return static function ( string $mo_path ) use ( $fs ) {
			$content = $fs->get( $mo_path );
			$entries = is_string( $content ) ? babilim_test_read_mo( $content ) : array();
			unset( $entries[''] );
			return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
		};
	}

	private function parse_fake( Babilim_Fs $fs ): callable {
		return static function ( string $path, string $format ) use ( $fs ) {
			$content = $fs->get( $path );
			if ( ! is_string( $content ) ) {
				return false;
			}
			if ( 'php' === $format ) {
				$code = (string) preg_replace( '/^<\?php\s*/', '', $content );
				$data = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
				return is_array( $data['messages'] ?? null ) ? count( $data['messages'] ) : false;
			}
			$entries = babilim_test_read_mo( $content );
			unset( $entries[''] );
			return count( $entries );
		};
	}

	private function unzip_fake(): callable {
		return static function ( string $zip_path ) {
			return $GLOBALS['babilim_test_fake_zips'][ $zip_path ] ?? false;
		};
	}

	private function put_fake_zip( string $zip_path, array $entries ): void {
		$GLOBALS['babilim_test_fake_zips'][ $zip_path ] = $entries;
	}

	private function mo_entry_count( string $bytes ): int {
		$parsed = babilim_test_read_mo( $bytes );
		unset( $parsed[''] );
		return count( $parsed );
	}

	private function file_digest( string $bytes ): array {
		return array( 'sha256' => hash( 'sha256', $bytes ), 'size' => strlen( $bytes ) );
	}

	private function basic_package( string $domain, string $locale, string $server_build_id, array $main_entries ): array {
		$mo = Babilim_Mo_Writer::write( $main_entries, array() );
		$po = "# fake po\n";

		$zip = array(
			$domain . '-' . $locale . '.mo' => $mo,
			$domain . '-' . $locale . '.po' => $po,
		);

		$manifest_json = array(
			'build_id'        => $domain . '-1.0.0-' . $locale . '-20260101T000000-abc123',
			'entries'         => $this->mo_entry_count( $mo ),
			'files'           => array(
				$domain . '-' . $locale . '.mo' => $this->file_digest( $mo ),
				$domain . '-' . $locale . '.po' => $this->file_digest( $po ),
			),
			'locale'          => $locale,
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => $domain,
			'version'         => '1.0.0',
			'text_domain'     => $domain,
			'extra_domains'   => array(),
			'server_build_id' => $server_build_id,
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.1.0',
		);
		$zip['manifest.json'] = wp_json_encode( $manifest_json );

		return array( $manifest_json, $zip );
	}

	private function new_installer( Babilim_Fs $fs, Babilim_Manifest $manifest ): Babilim_Installer {
		return new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $this->unzip_fake() );
	}

	/**
	 * Installs two builds of one plugin/locale (so a real rollback() has
	 * something to roll back to) and returns [build_id_v1, build_id_v2].
	 *
	 * @return array{0:string,1:string}
	 */
	private function install_two_builds( Babilim_Fs $fs, Babilim_Manifest $manifest, string $domain, string $locale ): array {
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected1, $zip1 ) = $this->basic_package( $domain, $locale, 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip1 );
		$result1 = $installer->install( 'plugin', $domain, $locale, 'zip://1', $expected1 );
		$this->assertTrue( $result1->is_ok(), $result1->message() );

		list( $expected2, $zip2 ) = $this->basic_package( $domain, $locale, 'bd_000000000002', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://2', $zip2 );
		$result2 = $installer->install( 'plugin', $domain, $locale, 'zip://2', $expected2 );
		$this->assertTrue( $result2->is_ok(), $result2->message() );

		return array( 'bd_000000000001', 'bd_000000000002' );
	}

	// -- render(): late-escaped through wp_kses(), not a bare echo ----------

	/**
	 * render() must actually route its output through wp_kses() with
	 * the declared allowlist -- not merely produce output that happens
	 * to look the same as an unescaped echo would (every render_*()
	 * method already escapes its own dynamic content, so real markup
	 * passes through wp_kses() unchanged either way; only a spy on the
	 * call itself proves the call happened at all). A mutation reverting
	 * render() to a bare `echo $this->render_current_page();` leaves
	 * $GLOBALS['babilim_test_wp_kses_calls'] empty -- this test goes
	 * red.
	 */
	public function test_render_routes_through_wp_kses_with_the_declared_allowlist(): void {
		$admin = $this->admin();

		ob_start();
		$admin->render();
		$output = ob_get_clean();

		$this->assertCount( 1, $GLOBALS['babilim_test_wp_kses_calls'], 'render() must call wp_kses() exactly once' );
		$call = $GLOBALS['babilim_test_wp_kses_calls'][0];
		$this->assertSame( Babilim_Admin::allowed_admin_html(), $call['allowed_html'], 'render() must pass its own declared allowlist to wp_kses()' );
		// The string handed to wp_kses() must be real screen markup, not
		// an empty/placeholder value -- proves this isn't calling
		// wp_kses() on the wrong (or no-op) input.
		$this->assertStringContainsString( '<h1', $call['string'] );
		// And the echoed output is genuinely what wp_kses() returned for
		// that same input, not the original unfiltered string.
		$this->assertSame( wp_kses( $call['string'], Babilim_Admin::allowed_admin_html() ), $output );
	}

	/**
	 * The allowlist itself must actually restrict something: a tag
	 * outside it (script) and an attribute outside an allowed tag's own
	 * sub-array (onclick on a <div>) must not survive being routed
	 * through wp_kses() with Babilim_Admin::allowed_admin_html() --
	 * while ordinary allowed markup (a <div class="..."> and its text)
	 * passes through untouched. A mutation that widens the allowlist
	 * (e.g. adding 'script' as an allowed tag, or 'onclick' to <div>'s
	 * own attributes) makes this test go red.
	 */
	public function test_allowed_admin_html_strips_a_disallowed_tag_and_attribute(): void {
		$dangerous = '<div class="babilim-box" onclick="doEvil()">Hello<script>doEvil()</script></div>';
		$filtered  = wp_kses( $dangerous, Babilim_Admin::allowed_admin_html() );

		$this->assertStringNotContainsString( '<script', $filtered );
		$this->assertStringNotContainsString( 'doEvil', $filtered );
		$this->assertStringNotContainsString( 'onclick', $filtered );
		$this->assertStringContainsString( '<div class="babilim-box">Hello</div>', $filtered );
	}

	/**
	 * The allowlist's own shape: never 'script', 'style', 'iframe', or
	 * 'object' as an allowed tag, and never an `on*` event-handler
	 * attribute on any allowed tag.
	 */
	public function test_allowed_admin_html_never_declares_script_style_or_event_handlers(): void {
		$allowed = Babilim_Admin::allowed_admin_html();
		foreach ( array( 'script', 'style', 'iframe', 'object', 'embed' ) as $dangerous_tag ) {
			$this->assertArrayNotHasKey( $dangerous_tag, $allowed, "{$dangerous_tag} must never be an allowed tag" );
		}
		foreach ( $allowed as $tag => $attrs ) {
			foreach ( array_keys( $attrs ) as $attr ) {
				$this->assertDoesNotMatchRegularExpression( '/^on/i', $attr, "{$tag} must never allow an event-handler attribute ({$attr})" );
			}
		}
	}

	// -- guard(): nonce + capability ----------------------------------------

	public function test_action_handler_without_a_nonce_calls_wp_die_and_makes_no_change(): void {
		update_option( 'babilim_consent', true );
		$_POST    = array( 'action' => 'babilim_save_settings', 'consent' => '' );
		$_REQUEST = $_POST;

		$admin = $this->admin();
		try {
			$admin->dispatch_babilim_save_settings();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}

		$this->assertNotEmpty( $GLOBALS['babilim_test_wp_die_calls'] );
		// Consent must be untouched -- the nonce check must happen before
		// any input is acted on.
		$this->assertTrue( (bool) get_option( 'babilim_consent' ) );
	}

	public function test_action_handler_without_the_capability_calls_wp_die(): void {
		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->post( 'babilim_sync_now', array() );

		$admin = $this->admin();
		$this->expectException( Babilim_Test_Wp_Die_Exception::class );
		$admin->dispatch_babilim_sync_now();
	}

	// -- guard(): multisite network-wide-write actions need
	// manage_network_options, not just manage_options -- a single
	// site's own admin (manage_options, granted below) must not be able
	// to overwrite, roll back, remove, rebuild, sweep, or purge the
	// network-wide shared translation tree every other site on the
	// network reads from. Each test seeds real state first (where the
	// action has any to disturb), asserts the blocked action still
	// throws wp_die(), and then asserts that state is byte-for-byte
	// unchanged -- not just that an exception was thrown. -------------------

	public function test_download_on_multisite_with_only_site_capability_is_refused_and_makes_zero_http_calls(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$http = new Babilim_Fake_Http( array() );
		$this->post( 'babilim_download', array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE' ) );

		$admin = $this->admin( $http );
		try {
			$admin->dispatch_babilim_download();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}
		$this->assertCount( 0, $http->requests );
	}

	public function test_rollback_on_multisite_with_only_site_capability_is_refused_and_leaves_the_installed_build_unchanged(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( , $build_v2 ) = $this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );

		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$this->post( 'babilim_rollback', array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW' ) );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		try {
			$admin->dispatch_babilim_rollback();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}
		$after = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( $build_v2, $after['build_id'], 'a blocked rollback must not have changed the installed build' );
	}

	public function test_remove_on_multisite_with_only_site_capability_is_refused_and_keeps_the_manifest_record(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( , $build_v2 ) = $this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );

		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$this->post( 'babilim_remove', array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW' ) );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		try {
			$admin->dispatch_babilim_remove();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}
		$after = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertNotNull( $after, 'a blocked remove must not have cleared the manifest record' );
		$this->assertSame( $build_v2, $after['build_id'] );
	}

	public function test_rollback_report_on_multisite_with_only_site_capability_is_refused_and_makes_zero_http_calls(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( , $build_v2 ) = $this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );
		$http = new Babilim_Fake_Http( array() );

		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$this->post(
			'babilim_rollback_report',
			array(
				'kind'     => 'plugin',
				'slug'     => 'woocommerce',
				'locale'   => 'zh_TW',
				'build_id' => $build_v2,
				'category' => 'incorrect',
			)
		);

		$admin = new Babilim_Admin( $fs, $http );
		try {
			$admin->dispatch_babilim_rollback_report();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}
		$this->assertCount( 0, $http->requests );
		$after = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( $build_v2, $after['build_id'], 'a blocked rollback-and-report must not have changed the installed build' );
	}

	public function test_rebuild_index_on_multisite_with_only_site_capability_is_refused(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$this->post( 'babilim_rebuild_index', array() );

		$admin = $this->admin();
		try {
			$admin->dispatch_babilim_rebuild_index();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}
		// A blocked action must never reach the point of recording an
		// outcome -- guard() is the first statement in the dispatch
		// method, before rebuild_all()/rebuild() is ever called.
		$this->assertEmpty( get_settings_errors( 'babilim' ) );
	}

	public function test_sweep_now_on_multisite_with_only_site_capability_is_refused(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$this->post( 'babilim_sweep_now', array() );

		$admin = $this->admin();
		try {
			$admin->dispatch_babilim_sweep_now();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}
		$this->assertEmpty( get_settings_errors( 'babilim' ) );
	}

	public function test_purge_all_on_multisite_with_only_site_capability_is_refused_and_keeps_the_manifest_record(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );

		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$this->post( 'babilim_purge_all', array() );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		try {
			$admin->dispatch_babilim_purge_all();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}
		$after = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertNotNull( $after, 'a blocked purge_all must not have removed the manifest record' );
	}

	// Positive controls: this permission tightening must not lock out
	// anyone who should still have access -- a real network admin
	// (manage_network_options granted) can still act on multisite, and
	// a single-site install still only ever needs manage_options.

	public function test_download_on_multisite_with_network_capability_is_not_blocked_by_the_guard(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options', 'manage_network_options' );
		$http = new Babilim_Fake_Http( array() );
		$this->post( 'babilim_download', array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE' ) );

		$admin = $this->admin( $http );
		// No consent has been granted, so do_download() itself still
		// reports an error -- the point here is only that guard() lets
		// this request through to that real logic instead of wp_die()ing.
		$admin->dispatch_babilim_download();
		$errors = get_settings_errors( 'babilim' );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'no_consent', $errors[0]['code'] );
	}

	public function test_download_on_a_single_site_install_with_only_manage_options_is_not_blocked_by_the_guard(): void {
		$GLOBALS['babilim_test_multisite']        = false;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$http = new Babilim_Fake_Http( array() );
		$this->post( 'babilim_download', array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE' ) );

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_download();
		$errors = get_settings_errors( 'babilim' );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'no_consent', $errors[0]['code'] );
	}

	// -- UI: network-wide-write buttons are hidden (with an explanation),
	// not just server-side guarded, for a multisite admin who lacks
	// manage_network_options. The server-side guard() tests above already
	// prove a submitted action gets refused either way; these prove the
	// button offering it in the first place is gone too. Each has a
	// reverse control (manage_network_options granted) proving this
	// isn't over-hiding on single-site or for a real network admin.

	public function test_settings_screen_hides_rebuild_and_sweep_buttons_without_network_capability_on_multisite(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$html = $this->admin()->render_current_page();

		$this->assertStringNotContainsString( 'name="action" value="babilim_rebuild_index"', $html );
		$this->assertStringNotContainsString( 'name="action" value="babilim_sweep_now"', $html );
		$this->assertStringContainsString( 'Only a network administrator can do this', $html );
	}

	public function test_settings_screen_shows_rebuild_and_sweep_buttons_with_network_capability_on_multisite(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options', 'manage_network_options' );
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'name="action" value="babilim_rebuild_index"', $html );
		$this->assertStringContainsString( 'name="action" value="babilim_sweep_now"', $html );
	}

	public function test_settings_screen_hides_purge_all_button_without_network_capability_on_multisite(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$html = $this->admin()->render_current_page();

		$this->assertStringNotContainsString( 'name="action" value="babilim_purge_all"', $html );
		$this->assertStringContainsString( 'Danger zone', $html );
		$this->assertStringContainsString( 'Only a network administrator can do this', $html );
	}

	public function test_settings_screen_shows_purge_all_button_with_network_capability_on_multisite(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options', 'manage_network_options' );
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'name="action" value="babilim_purge_all"', $html );
	}

	public function test_single_site_install_shows_every_network_wide_button_with_only_manage_options(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'woocommerce/woocommerce.php' => array( 'Name' => 'Woo', 'Version' => '1.0.0', 'TextDomain' => 'woocommerce' ) );
		( new Babilim_State() )->set(
			array(
				'fetched_at' => gmdate( 'c' ),
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'woocommerce',
						'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ),
					),
				),
			)
		);
		$GLOBALS['babilim_test_multisite']        = false;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );

		$_GET = array( 'page' => 'babilim' );
		$html = ( new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) ) )->render_current_page();
		$this->assertStringContainsString( 'name="action" value="babilim_rollback"', $html );
		$this->assertStringContainsString( 'name="action" value="babilim_remove"', $html );

		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );
		$html = $this->admin()->render_current_page();
		$this->assertStringContainsString( 'name="action" value="babilim_rebuild_index"', $html );
		$this->assertStringContainsString( 'name="action" value="babilim_purge_all"', $html );
	}

	/**
	 * Real WordPress core's wp_kses() -- specifically
	 * safecss_filter_attr(), which every allowed `style` attribute's
	 * value is run through regardless of what allowed_admin_html()
	 * itself declares -- strips a `display` property (never on
	 * safe_style_css's own allow-list, on every core version checked).
	 * A hand-written wp_kses() test double has no reason to know that
	 * unless it happens to reimplement the same allow-list, which is
	 * exactly why this test runs the real thing (tests/support/
	 * real-kses.php loads the actual pinned wp-includes/kses.php) rather
	 * than the stand-in every other test in this suite still uses.
	 *
	 * First: a minimal literal snippet, confirming the harness itself is
	 * faithful (real core really does strip `display`, keeps other CSS).
	 */
	public function test_real_wp_kses_strips_display_but_keeps_other_style_properties(): void {
		if ( ! babilim_test_real_kses_available() ) {
			$this->markTestSkipped( 'The real wp-includes/kses.php is not present under tools/wp-core/ -- run `make tools` to fetch it (pinned by tag+SHA256 in the Makefile) before this test can run for real.' );
		}
		$html     = '<label style="display:inline-block;margin-right:1em">x</label>';
		$stripped = babilim_real_wp_kses( $html, array( 'label' => array( 'style' => true ) ) );
		$this->assertStringNotContainsString( 'display', $stripped );
		$this->assertStringContainsString( 'margin-right:1em', $stripped );
	}

	/**
	 * The regression this class's own render() methods must not have:
	 * every layout-bearing element on the detail and settings screens
	 * now carries a `class="babilim-..."` instead of an inline `style`
	 * (see admin_css()/enqueue_admin_assets()) -- run the real, actually-
	 * produced HTML through real core's own wp_kses() with this class's
	 * own allowed_admin_html() allow-list, and confirm every one of
	 * those classes, and the forms/labels they're on, survive untouched.
	 * Reverting any one of them back to an inline `style="display:..."`
	 * would both fail this test's own class-string assertions (the
	 * class attribute would be gone from that element) and reintroduce
	 * exactly the layout regression test_real_wp_kses_strips_display_
	 * but_keeps_other_style_properties() demonstrates above.
	 */
	public function test_admin_render_output_keeps_its_layout_classes_through_real_wp_kses(): void {
		if ( ! babilim_test_real_kses_available() ) {
			$this->markTestSkipped( 'The real wp-includes/kses.php is not present under tools/wp-core/ -- run `make tools` to fetch it (pinned by tag+SHA256 in the Makefile) before this test can run for real.' );
		}
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'woocommerce/woocommerce.php' => array( 'Name' => 'Woo', 'Version' => '1.0.0', 'TextDomain' => 'woocommerce' ) );
		( new Babilim_State() )->set(
			array(
				'fetched_at' => gmdate( 'c' ),
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'woocommerce',
						'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ),
					),
				),
			)
		);
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options', 'manage_network_options' );

		$_GET     = array( 'page' => 'babilim' );
		$list_raw = ( new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) ) )->render_current_page();

		$_GET          = array( 'screen' => 'settings' );
		$settings_raw  = $this->admin()->render_current_page();
		$_GET          = array( 'screen' => 'settings', 'tab' => 'advanced' );
		$settings_raw .= $this->admin()->render_current_page();

		$allowed = Babilim_Admin::allowed_admin_html();

		// Before kses: the raw markup itself must not emit any style
		// attribute. Asserting only after kses could never fail -- `style`
		// is not in the allowlist, so kses always strips it.
		foreach ( array( 'list' => $list_raw, 'settings' => $settings_raw ) as $screen => $raw ) {
			$this->assertStringNotContainsString( 'style=', $raw, "$screen screen: raw markup must not emit an inline style attribute" );
			$this->assertStringNotContainsString( 'display:', $raw, "$screen screen: raw markup must not contain inline \"display:\"" );
		}

		$kses_list     = babilim_real_wp_kses( $list_raw, $allowed );
		$kses_settings = babilim_real_wp_kses( $settings_raw, $allowed );

		// After kses: each layout class is still on its own element.
		// The row-action buttons (roll back / remove) keep their classes and confirm attributes.
		$this->assertMatchesRegularExpression( '/<button[^>]*class="button-link"[^>]*data-babilim-confirmed="1"/', $kses_list, "the row-action button must survive real kses" );
		// render_managed_locales()'s per-locale checkbox <label> (General tab).
		$this->assertMatchesRegularExpression( '/<label[^>]*class="babilim-inline-block babilim-mr-1em"/', $kses_settings, "render_managed_locales()'s label class must survive real kses" );
		// render_maintenance_buttons()'s two forms, each checked on its own.
		$this->assertMatchesRegularExpression( '/<form[^>]*class="babilim-inline-block babilim-mr-1em"[^>]*>\s*<input type="hidden" name="action" value="babilim_rebuild_index"/', $kses_settings, 'the rebuild-index form class must survive real kses' );
		$this->assertMatchesRegularExpression( '/<form[^>]*class="babilim-inline-block"[^>]*>\s*<input type="hidden" name="action" value="babilim_sweep_now"/', $kses_settings, 'the sweep form class must survive real kses' );
	}

	// -- enqueue_admin_assets() / admin_css() -----------------------------------

	/**
	 * The layout stylesheet reaches this plugin's own page -- with a
	 * version and every layout rule the markup's classes depend on.
	 */
	public function test_enqueue_admin_assets_loads_versioned_layout_css_on_the_plugin_page(): void {
		$admin = $this->admin();
		$admin->register_menu();
		// The real WordPress hook suffix (`toplevel_page_{slug}`), which is
		// what admin_enqueue_scripts hands a callback on this page -- not
		// the bare menu slug.
		$hook = 'toplevel_page_' . Babilim_Admin::MENU_SLUG;
		$this->assertNotSame( Babilim_Admin::MENU_SLUG, $hook );

		$admin->enqueue_admin_assets( $hook );

		$this->assertArrayHasKey( 'babilim-admin', $GLOBALS['babilim_test_registered_styles'] );
		$this->assertSame( BABILIM_VERSION, $GLOBALS['babilim_test_registered_styles']['babilim-admin']['ver'] );
		$this->assertArrayHasKey( 'babilim-admin', $GLOBALS['babilim_test_enqueued_styles'] );
		$this->assertArrayHasKey( 'babilim-admin', $GLOBALS['babilim_test_inline_styles'] );
		$css = implode( '', $GLOBALS['babilim_test_inline_styles']['babilim-admin'] );
		$this->assertNotSame( '', $css );
		$this->assertStringContainsString( '.babilim-inline{display:inline}', $css );
		$this->assertStringContainsString( '.babilim-inline-block{display:inline-block}', $css );
		$this->assertStringContainsString( '.babilim-mr-1em{margin-right:1em}', $css );
		$this->assertMatchesRegularExpression( '/\.babilim-cell-line\{[^}]*align-items:start/', $css, 'the three cells of a line share their top edge' );
		$this->assertDoesNotMatchRegularExpression( '/\.babilim-cell-line\{[^}]*align-items:center/', $css );
		$this->assertStringNotContainsString( 'babilim-filter-controls', $css, 'the Filter button container is gone' );
		$this->assertStringContainsString( '.babilim-locale-links{clear:both;margin:.5em 0 0}', $css );
		$this->assertStringContainsString( '.babilim-advanced-first{margin-top:1em}', $css );
	}

	/**
	 * The menu double answers with core's hook suffix shape, so a callback
	 * comparing against the slug cannot pass by accident.
	 */
	public function test_menu_doubles_return_the_real_core_hook_suffix_shapes(): void {
		$this->assertSame( 'toplevel_page_babilim', add_menu_page( 'T', 'Babilim Top', 'manage_options', 'babilim' ) );
		$this->assertSame( 'babilim-top_page_sub-slug', add_submenu_page( 'babilim', 'T', 'S', 'manage_options', 'sub-slug' ) );
		$this->assertSame( 'admin_page_orphan', add_submenu_page( 'not-registered', 'T', 'S', 'manage_options', 'orphan' ) );
	}

	/**
	 * The top-level menu uses the Babilim mark as a single-colour SVG data
	 * URI: WordPress only recolours an icon given that way, and only when
	 * every shape is filled with one colour.
	 */
	public function test_register_menu_uses_the_single_colour_svg_mark_as_its_icon(): void {
		$GLOBALS['babilim_test_menu_pages'] = array();
		$this->admin()->register_menu();

		$icon   = $GLOBALS['babilim_test_menu_pages'][0]['icon_url'];
		$prefix = 'data:image/svg+xml;base64,';
		$this->assertStringStartsWith( $prefix, $icon );
		$svg = base64_decode( substr( $icon, strlen( $prefix ) ), true );
		$this->assertIsString( $svg );
		$this->assertMatchesRegularExpression( '/^<svg xmlns="[^"]+" viewBox="[0-9 ]+">/', $svg );
		$this->assertSame( 3, substr_count( $svg, '<path ' ) );
		preg_match_all( '/fill="([^"]*)"/', $svg, $fills );
		$this->assertSame( array( 'black' ), array_values( array_unique( $fills[1] ) ) );
		$this->assertStringNotContainsString( '<script', $svg );
		$this->assertStringNotContainsString( 'style', $svg );
	}

	public function test_register_menu_hooks_enqueue_admin_assets_and_it_loads_only_on_the_real_suffix(): void {
		$admin = $this->admin();
		$admin->register_menu();

		$hooked = array_map(
			static function ( $entry ) {
				return $entry['callback'];
			},
			$GLOBALS['babilim_test_actions']['admin_enqueue_scripts'] ?? array()
		);
		$this->assertContains( array( $admin, 'enqueue_admin_assets' ), $hooked, 'register_menu() must hook enqueue_admin_assets to admin_enqueue_scripts' );

		// The bare slug is not a hook suffix WordPress ever passes.
		$admin->enqueue_admin_assets( Babilim_Admin::MENU_SLUG );
		$this->assertSame( array(), $GLOBALS['babilim_test_enqueued_styles'], 'the bare menu slug must not load the stylesheet' );

		$admin->enqueue_admin_assets( 'toplevel_page_' . Babilim_Admin::MENU_SLUG );
		$this->assertArrayHasKey( 'babilim-admin', $GLOBALS['babilim_test_enqueued_styles'] );
	}

	public function test_enqueue_admin_assets_does_nothing_on_other_admin_screens(): void {
		$admin = $this->admin();
		$admin->register_menu();

		$admin->enqueue_admin_assets( 'edit.php' );
		$admin->enqueue_admin_assets( '' );

		$this->assertSame( array(), $GLOBALS['babilim_test_enqueued_styles'] );
		$this->assertSame( array(), $GLOBALS['babilim_test_inline_styles'] );
	}

	public function test_enqueue_admin_assets_does_nothing_before_the_menu_is_registered(): void {
		$this->admin()->enqueue_admin_assets( '' );
		$this->assertSame( array(), $GLOBALS['babilim_test_enqueued_styles'] );
	}

	// -- consent gating -------------------------------------------------------

	public function test_download_without_consent_is_refused_and_makes_zero_http_calls(): void {
		$http  = new Babilim_Fake_Http( array() );
		$admin = $this->admin( $http );

		$result = $admin->do_download( 'plugin', 'a', 'de_DE' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'no_consent', $result->code() );
		$this->assertCount( 0, $http->requests );
	}

	/**
	 * The full "has an update -> download -> install" main line, driven
	 * through the real assembly layer (Babilim_Admin::do_download(), not
	 * a hand-rolled call straight into Babilim_Installer). Proves a real
	 * build_id bug: when a locale has an update pending, the build
	 * actually downloaded (and installed) must be updates[]'s own
	 * build_id, not the (potentially stale) build_id on the locale's own
	 * status entry.
	 *
	 * Babilim_Admin's own installer() is normally bound to
	 * Babilim::instance()'s real transform/parse/unzip callables, which
	 * need a real WordPress install (WP_Translation_File) and a real zip
	 * on disk -- neither available in this test's Babilim_Memory_Fs
	 * sandbox. The constructor's own (new) $installer override lets this
	 * test supply the same kind of fake callables tests/unit/test-
	 * installer.php already uses, so the download/install round trip
	 * through the real do_download() can actually succeed end to end.
	 */
	public function test_download_installs_the_updates_build_id_not_the_stale_locale_entry_one(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => 'tok_test' ), false ); // Downloads are authenticated; a site without a token makes none.
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'woocommerce',
						'locales' => array(
							'zh_TW' => array(
								'status'           => 'babilim_installed',
								// Deliberately stale/different from the real
								// update's own build_id below.
								'build_id'         => 'bd_000000000001',
								'update_available' => true,
							),
						),
					),
				),
				'updates'    => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000002', 'build_no' => 2 ),
				),
			)
		);

		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
		$unzip_fixed            = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$http = new Babilim_Fake_Http(
			array(
				array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/bd_000000000002' ) ),
				array( 'response' => array( 'code' => 200 ), 'body' => 'zip-bytes-placeholder', 'headers' => array() ),
			)
		);

		$admin  = new Babilim_Admin( $fs, $http, $installer );
		$result = $admin->do_download( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$download_body = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( 'bd_000000000002', $download_body['build_id'], "must request the update's own build_id, not the stale locale-entry one" );
		$installed = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( 'bd_000000000002', $installed['build_id'] );
		// Derived from the zip's own manifest.json (strictly validated),
		// not left empty -- component_version must never go missing on a
		// real install.
		$this->assertSame( '1.0.0', $installed['component_version'] );
		// The build offered as an update is consumed once installed.
		$this->assertNull( ( new Babilim_State() )->update_for( 'plugin', 'woocommerce', 'zh_TW' ) );
		// A successful install schedules a one-off sync
		// (wp_schedule_single_event(), never a synchronous call that
		// would block this same request) so this plugin's own state
		// reflects the just-installed build without waiting for the
		// next twice-daily cron run.
		$scheduled = $GLOBALS['babilim_test_single_events_scheduled'];
		$this->assertNotEmpty( $scheduled, 'a sync must be scheduled after a successful install' );
		$this->assertSame( 'babilim_sync', $scheduled[0]['hook'] );
	}

	/**
	 * A `POST /v1/downloads` response whose own Location points at a
	 * DIFFERENT origin than the configured API base must never be
	 * followed -- the contract defines Location as relative to the API
	 * base, so an absolute, different-origin Location is either a
	 * misconfigured or a compromised service response; do_download()
	 * must refuse it and make no second HTTP request (the GET that would
	 * otherwise fetch the actual package bytes) at all.
	 */
	public function test_download_refuses_a_location_pointing_at_a_different_origin(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => 'tok_test' ), false ); // Downloads are authenticated; a site without a token makes none.
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'woocommerce',
						'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available' ) ),
					),
				),
				'updates'    => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000002', 'build_no' => 2 ),
				),
			)
		);
		$http = new Babilim_Fake_Http(
			array(
				array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => 'https://evil.example/v1/packages/bd_000000000002' ) ),
			)
		);

		$admin  = new Babilim_Admin( $fs, $http );
		$result = $admin->do_download( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertCount( 1, $http->requests, 'only the POST /v1/downloads request must have been made -- never a second request to the off-origin Location' );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	/**
	 * The download path must strictly validate the zip's own
	 * manifest.json (not just trust whatever files happen to classify),
	 * so a package that doesn't match its own claims can never install
	 * "successfully" with nothing to show for it. Each of these probes
	 * mirrors a real failure mode found in testing.
	 */
	private function fake_download_http( string $build_id ): Babilim_Fake_Http {
		return new Babilim_Fake_Http(
			array(
				array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/' . $build_id ) ),
				array( 'response' => array( 'code' => 200 ), 'body' => 'zip-bytes-placeholder', 'headers' => array() ),
			)
		);
	}

	/**
	 * Probe E: the zip's own manifest.json declares a text_domain that
	 * doesn't match the actual filenames inside the zip (still
	 * 'foo-plugin-zh_TW.*', from basic_package()'s own naming) -- a
	 * package inconsistent with itself must be rejected, not silently
	 * installed with zero main files. This is distinct from a package
	 * whose slug and text_domain genuinely differ from each other but
	 * are each consistent with their own half of the contract (slug for
	 * identity, text_domain for filenames) -- see
	 * test_download_installs_a_package_whose_slug_differs_from_its_own_text_domain()
	 * for that legitimate case.
	 */
	public function test_download_rejects_a_package_whose_declared_text_domain_does_not_match_its_own_files(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'foo-plugin', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000003' ) ) ),
				),
			)
		);
		list( $expected, $zip ) = $this->basic_package( 'foo-plugin', 'zh_TW', 'bd_000000000003', array( 'Save' => 'x' ) );
		$broken                  = $expected;
		$broken['text_domain']   = 'a-different-domain';
		$zip['manifest.json']    = wp_json_encode( $broken );
		$unzip_fixed = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$admin  = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_000000000003' ), $installer );
		$result = $admin->do_download( 'plugin', 'foo-plugin', 'zh_TW' );

		$this->assertTrue( $result->is_error(), 'a package disagreeing with itself on text_domain must never report success' );
		$this->assertNull( $manifest->installed( 'plugin', 'foo-plugin', 'zh_TW' ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'foo-plugin', 'zh_TW', 'mo' ) ) );
	}

	/**
	 * The product-gap fix: a component's slug ('foo-plugin', what
	 * do_download() knows ahead of the download and what the record is
	 * keyed under) is legitimately allowed to differ from its own
	 * text_domain ('foo', only known once the zip's manifest.json is in
	 * hand, and what served filenames + the loading layer key on). Uses
	 * a real Babilim_Wp_Fs (not the in-memory fake) so the loading
	 * layer's own real-disk lookup (Babilim_Loader) can be exercised
	 * against what install() actually wrote.
	 */
	public function test_download_installs_a_package_whose_slug_differs_from_its_own_text_domain(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => 'tok_test' ), false ); // Downloads are authenticated; a site without a token makes none.
		$fs       = new Babilim_Wp_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->realDiskCleanup = array(
			'fs'    => $fs,
			'paths' => array(
				Babilim_Paths::file( 'plugins', 'foo', 'zh_TW', 'mo' ),
				Babilim_Paths::file( 'plugins', 'foo', 'zh_TW', 'po' ),
				Babilim_Paths::manifest_file(),
			),
		);
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'foo-plugin', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000010' ) ) ),
				),
			)
		);
		// basic_package()'s own $domain arg names both the files and
		// the manifest's slug/text_domain the same way -- overriding
		// just 'slug' afterwards keeps the files (and text_domain)
		// named 'foo-zh_TW.*' while giving the package a different slug,
		// exactly the "proper package, two different identities" shape.
		list( $expected, $zip ) = $this->basic_package( 'foo', 'zh_TW', 'bd_000000000010', array( 'Save' => 'x' ) );
		$expected['slug']      = 'foo-plugin';
		$zip['manifest.json']  = wp_json_encode( $expected );
		$unzip_fixed = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$admin  = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_000000000010' ), $installer );
		$result = $admin->do_download( 'plugin', 'foo-plugin', 'zh_TW' );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$record = $manifest->installed( 'plugin', 'foo-plugin', 'zh_TW' );
		$this->assertNotNull( $record, 'the record must be findable by slug' );
		$this->assertSame( 'foo', $record['text_domain'] );

		$mo_path = Babilim_Paths::file( 'plugins', 'foo', 'zh_TW', 'mo' );
		$this->assertTrue( $fs->exists( $mo_path ), 'the served file must be named after the text_domain, not the slug' );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'foo-plugin', 'zh_TW', 'mo' ) ), 'must never also serve under the slug' );

		// The loading layer looks files up by text_domain -- confirm it
		// actually finds and loads what install() just wrote.
		$original = '/wp/languages/plugins/foo-zh_TW.mo';
		Babilim_Loader::load_translation_file( $original, 'foo', 'zh_TW' );
		$calls = $GLOBALS['babilim_test_load_textdomain_calls'];
		$this->assertNotEmpty( $calls, 'the loading layer must have called load_textdomain() for text_domain foo' );
		$this->assertSame( 'foo', $calls[0]['domain'] );
		$this->assertSame( $mo_path, $calls[0]['mofile'] );
	}

	/**
	 * A slug that genuinely doesn't match what the site asked to
	 * download (not merely differing from its own text_domain -- an
	 * outright wrong component) must still fail with an identity error,
	 * caught by install()'s own pre-unzip identity cross-check before
	 * any file is ever written.
	 */
	public function test_download_rejects_a_package_whose_own_manifest_slug_does_not_match_the_requested_slug(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => 'tok_test' ), false ); // Downloads are authenticated; a site without a token makes none.
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'foo-plugin', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000011' ) ) ),
				),
			)
		);
		list( $expected, $zip ) = $this->basic_package( 'foo-plugin', 'zh_TW', 'bd_000000000011', array( 'Save' => 'x' ) );
		$broken               = $expected;
		$broken['slug']       = 'some-other-plugin';
		$zip['manifest.json'] = wp_json_encode( $broken );
		$unzip_fixed = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$admin  = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_000000000011' ), $installer );
		$result = $admin->do_download( 'plugin', 'foo-plugin', 'zh_TW' );

		$this->assertTrue( $result->is_error(), 'a package for a genuinely different slug must never report success' );
		$this->assertSame( 'package_manifest_mismatch', $result->code() );
		$this->assertNull( $manifest->installed( 'plugin', 'foo-plugin', 'zh_TW' ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'foo-plugin', 'zh_TW', 'mo' ) ) );
	}

	/**
	 * Probe G: the zip's own manifest.json claims status "draft" and an
	 * entries count that doesn't match the real file -- both must be
	 * rejected by the strict shape/entries checks, not silently accepted.
	 */
	public function test_download_rejects_a_package_whose_own_manifest_is_a_draft_with_a_wrong_entries_count(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000004' ) ) ),
				),
			)
		);
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000004', array( 'Save' => 'x' ) );
		$broken               = $expected;
		$broken['status']     = 'draft';
		$broken['entries']    = 99;
		$zip['manifest.json'] = wp_json_encode( $broken );
		$unzip_fixed = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$admin  = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_000000000004' ), $installer );
		$result = $admin->do_download( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	/**
	 * A draft manifest whose entries count is otherwise CORRECT (unlike
	 * probe G above, which broke both 'status' and 'entries' in the same
	 * fixture, so the entries-count check alone would have caught it
	 * regardless of whether the status check ran at all). This isolates
	 * the status='published' check by itself -- only it can explain a
	 * rejection here.
	 */
	public function test_download_rejects_a_package_whose_own_manifest_is_draft_only(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000012' ) ) ),
				),
			)
		);
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000012', array( 'Save' => 'x' ) );
		$broken                = $expected;
		$broken['status']      = 'draft';
		// entries left exactly as basic_package() computed it -- correct.
		$zip['manifest.json'] = wp_json_encode( $broken );
		$unzip_fixed = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$admin  = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_000000000012' ), $installer );
		$result = $admin->do_download( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error(), 'a draft package must never install, even with a correct entries count' );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	/**
	 * An empty 'version' is otherwise the only broken field (entries,
	 * status, slug, text_domain, files/sha256 all correct/consistent) --
	 * only the strict shape validation's own generic non-empty-string
	 * check can explain a rejection here (no other check inspects
	 * 'version' at all).
	 */
	public function test_download_rejects_a_package_whose_own_manifest_has_an_empty_version(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000013' ) ) ),
				),
			)
		);
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000013', array( 'Save' => 'x' ) );
		$broken                = $expected;
		$broken['version']     = '';
		$zip['manifest.json']  = wp_json_encode( $broken );
		$unzip_fixed = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$admin  = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_000000000013' ), $installer );
		$result = $admin->do_download( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error(), 'a package with an empty version must never install' );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	/**
	 * A package that declares only a `.po` (never served directly --
	 * only `.mo`/`.l10n.php` are) must fail rather than report success
	 * with nothing actually written; a failed download must also leave
	 * the pending update hint alone, not consume it as if the install
	 * had actually happened.
	 */
	public function test_download_rejects_a_package_that_declares_only_a_po_file(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available' ) ) ),
				),
				'updates'    => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000014', 'build_no' => 1 ),
				),
			)
		);
		$po  = "# fake po\n";
		$zip = array( 'woocommerce-zh_TW.po' => $po );
		$manifest_json = array(
			'build_id'        => 'woocommerce-1.0.0-zh_TW-20260101T000000-abc123',
			'entries'         => 0,
			'files'           => array( 'woocommerce-zh_TW.po' => $this->file_digest( $po ) ),
			'locale'          => 'zh_TW',
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => 'woocommerce',
			'version'         => '1.0.0',
			'text_domain'     => 'woocommerce',
			'extra_domains'   => array(),
			'server_build_id' => 'bd_000000000014',
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.1.0',
		);
		$zip['manifest.json'] = wp_json_encode( $manifest_json );
		$unzip_fixed = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$admin  = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_000000000014' ), $installer );
		$result = $admin->do_download( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error(), 'a .po-only package must never report success' );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
		$this->assertNotNull( ( new Babilim_State() )->update_for( 'plugin', 'woocommerce', 'zh_TW' ), 'a failed install must not consume the pending update hint' );
	}

	/**
	 * Downloading component A (slug `a-plugin`) whose package declares
	 * the text domain `b` -- already served by the installed component B
	 * (slug `b`) -- goes through the real action handler: the screen
	 * shows the text_domain_in_use error, A's pending update hint stays,
	 * and B's served file keeps B's own bytes.
	 */
	public function test_download_of_a_package_whose_text_domain_another_component_serves_shows_the_conflict(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => 'tok_test' ), false ); // Downloads are authenticated; a site without a token makes none.
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );

		list( $expected_b, $zip_b ) = $this->basic_package( 'b', 'zh_TW', 'bd_0000000000b1', array( 'Save' => 'B-OWN' ) );
		$unzip_b     = static function () use ( $zip_b ) {
			return $zip_b;
		};
		$installer_b = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_b );
		$this->assertTrue( $installer_b->install( 'plugin', 'b', 'zh_TW', 'zip://b', $expected_b )->is_ok() );
		$b_served = $fs->get( Babilim_Paths::file( 'plugins', 'b', 'zh_TW', 'mo' ) );

		list( $expected_a, $zip_a ) = $this->basic_package( 'b', 'zh_TW', 'bd_0000000000a1', array( 'Save' => 'A-OVERWRITE' ) );
		$expected_a['slug']         = 'a-plugin';
		$expected_a['build_id']     = 'a-plugin-1.0.0-zh_TW-20260101T000000-abc123';
		$zip_a['manifest.json']     = wp_json_encode( $expected_a );
		$unzip_a     = static function () use ( $zip_a ) {
			return $zip_a;
		};
		$installer_a = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_a );
		( new Babilim_State() )->set(
			array(
				'components' => array(),
				'updates'    => array(
					array( 'kind' => 'plugin', 'slug' => 'a-plugin', 'locale' => 'zh_TW', 'build_id' => 'bd_0000000000a1', 'build_no' => 1 ),
				),
			)
		);
		$this->post( 'babilim_download', array( 'kind' => 'plugin', 'slug' => 'a-plugin', 'locale' => 'zh_TW' ) );

		$admin = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_0000000000a1' ), $installer_a );
		$admin->dispatch_babilim_download();

		$errors = get_settings_errors( 'babilim' );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'text_domain_in_use', $errors[0]['code'] );
		$this->assertSame( 'error', $errors[0]['type'] );
		$this->assertNull( $manifest->installed( 'plugin', 'a-plugin', 'zh_TW' ) );
		$this->assertNotNull( ( new Babilim_State() )->update_for( 'plugin', 'a-plugin', 'zh_TW' ), 'a failed install must not consume the pending update hint' );
		$this->assertSame( $b_served, $fs->get( Babilim_Paths::file( 'plugins', 'b', 'zh_TW', 'mo' ) ) );
		$this->assertTrue( $installer_b->verify( 'plugin', 'b', 'zh_TW' )->is_ok() );
	}

	/**
	 * Probe L: the zip's own manifest.json declares zero main files --
	 * never a "successful" install of nothing.
	 */
	public function test_download_rejects_a_package_that_declares_zero_main_files(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => 'tok_test' ), false ); // Downloads are authenticated; a site without a token makes none.
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000005' ) ) ),
				),
			)
		);
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000005', array( 'Save' => 'x' ) );
		$broken               = $expected;
		$broken['files']      = array();
		$broken['entries']    = 0;
		$zip['manifest.json'] = wp_json_encode( $broken );
		$unzip_fixed = static function ( string $zip_path ) use ( $zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $unzip_fixed );

		$admin  = new Babilim_Admin( $fs, $this->fake_download_http( 'bd_000000000005' ), $installer );
		$result = $admin->do_download( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'no_main_files', $result->code() );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	public function test_request_without_consent_is_refused_and_makes_zero_http_calls(): void {
		$http  = new Babilim_Fake_Http( array() );
		$admin = $this->admin( $http );

		$result = $admin->do_request( 'plugin', 'a', '1.0.0', 'de_DE' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'no_consent', $result->code() );
		$this->assertCount( 0, $http->requests );
	}

	// -- "Request translation": the version comes from the installed component --

	/**
	 * A 202 reply for POST /v1/requests, shaped like the contract's own example.
	 */
	private function request_accepted_http(): Babilim_Fake_Http {
		return new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 202 ),
					'body'     => wp_json_encode( array( 'status' => 'queued' ) ),
					'headers'  => array(),
				),
			)
		);
	}

	/**
	 * Renders the translations list for a requestable locale and parses the
	 * "Request translation" form back out of the HTML, exactly as a
	 * browser would submit it.
	 *
	 * @return array<string,string> The form's named fields.
	 */
	private function rendered_request_form_fields( Babilim_Admin $admin, string $kind, string $slug, string $locale ): array {
		( new Babilim_State() )->set(
			array(
				'fetched_at' => gmdate( 'c' ),
				'components' => array(
					array(
						'kind'    => $kind,
						'slug'    => $slug,
						'locales' => array( $locale => array( 'status' => 'requestable' ) ),
					),
				),
			)
		);
		$_GET = array( 'page' => 'babilim' );
		$html = $admin->render_current_page();

		return $this->form_fields( $html, 'babilim_request' );
	}

	/**
	 * Parses $html with DOMDocument and returns the named fields of the
	 * one form whose `action` input is $action, exactly as a browser
	 * would submit them -- whatever order the attributes are written in.
	 *
	 * @return array<string,string>
	 */
	private function form_fields( string $html, string $action ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		$found = array();
		foreach ( $dom->getElementsByTagName( 'form' ) as $form ) {
			foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
				if ( 'action' === $input->getAttribute( 'name' ) && $action === $input->getAttribute( 'value' ) ) {
					$found[] = $form;
					break;
				}
			}
		}
		$this->assertCount( 1, $found, "exactly one {$action} form is rendered" );
		$fields = array();
		foreach ( $found[0]->getElementsByTagName( 'input' ) as $input ) {
			if ( '' !== $input->getAttribute( 'name' ) ) {
				$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
			}
		}
		return $fields;
	}

	/**
	 * Records, as the last sync would, the locales of one component with
	 * their status -- the rows a request button (and the request action's
	 * own check) is decided from.
	 *
	 * @param array<string,string> $statuses locale => status.
	 */
	private function set_locale_statuses( string $kind, string $slug, array $statuses ): void {
		$locales = array();
		foreach ( $statuses as $locale => $status ) {
			$locales[ $locale ] = array( 'status' => $status );
		}
		( new Babilim_State() )->set( array( 'components' => array( array( 'kind' => $kind, 'slug' => $slug, 'locales' => $locales ) ) ) );
	}

	private function assertRequestPayloadMatchesSchema( string $raw_json ): void {
		$errors = Babilim_Schema_Validator::validate(
			json_decode( $raw_json ),
			json_decode( file_get_contents( __DIR__ . '/../contract/v1/schemas/requests.request.schema.json' ), true )
		);
		$this->assertSame( array(), $errors, implode( "\n", $errors ) );
	}

	public function test_rendered_plugin_request_form_submits_the_installed_version(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ),
		);
		$http  = $this->request_accepted_http();
		$admin = $this->admin( $http );

		$fields = $this->rendered_request_form_fields( $admin, 'plugin', 'acme', 'de_DE' );
		$this->assertSame( 'babilim_request', $fields['action'] );
		$_POST    = $fields;
		$_REQUEST = $fields;
		$admin->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests );
		$this->assertRequestPayloadMatchesSchema( $http->requests[0]['args']['body'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( array( 'kind' => 'plugin', 'slug' => 'acme', 'version' => '3.4.5', 'locale' => 'de_DE', 'client_caps' => array( 'upload' ) ), $sent );
	}

	public function test_rendered_theme_request_form_submits_the_installed_version(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_wp_get_themes_return'] = array(
			'acme-theme' => new class() {
				public function get( $key ) {
					return array( 'Name' => 'Acme Theme', 'Version' => '2.0.1' )[ $key ] ?? false;
				}
				public function get_stylesheet_directory() {
					return '/fake-wp-content/themes/acme-theme';
				}
			},
		);
		$http  = $this->request_accepted_http();
		$admin = $this->admin( $http );

		$fields = $this->rendered_request_form_fields( $admin, 'theme', 'acme-theme', 'fr_FR' );
		$_POST    = $fields;
		$_REQUEST = $fields;
		$admin->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests );
		$this->assertRequestPayloadMatchesSchema( $http->requests[0]['args']['body'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( array( 'kind' => 'theme', 'slug' => 'acme-theme', 'version' => '2.0.1', 'locale' => 'fr_FR', 'client_caps' => array( 'upload' ) ), $sent );
	}

	public function test_a_one_cell_request_records_the_new_status_in_the_cell(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'de_DE' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE' ) );

		$this->admin( $this->request_accepted_http() )->dispatch_babilim_request();

		$this->assertSame( 'queued', ( new Babilim_State() )->status( 'plugin', 'acme', 'de_DE' )['status'] );
	}

	public function test_a_one_cell_request_answered_available_makes_the_cell_downloadable(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'de_DE' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status' => 'available', 'build_id' => 'bd_00000000000a' ) ), 'headers' => array() ) ) );
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE' ) );

		$this->admin( $http )->dispatch_babilim_request();

		$cell = ( new Babilim_State() )->status( 'plugin', 'acme', 'de_DE' );
		$this->assertSame( 'babilim_available', $cell['status'] );
		$this->assertSame( 'bd_00000000000a', $cell['build_id'] );
	}

	public function test_request_ignores_a_tampered_version_field(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'de_DE' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ),
		);
		$http = $this->request_accepted_http();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE', 'version' => '99.0.0' ) );

		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( '3.4.5', $sent['version'] );
	}

	public function test_request_for_an_unknown_component_sends_nothing_and_reports_an_error(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ),
		);
		$http = $this->request_accepted_http();
		$admin = $this->admin( $http );

		// Not installed at all, and installed under the other kind.
		foreach ( array( array( 'plugin', 'not-installed' ), array( 'theme', 'acme' ) ) as $pair ) {
			$GLOBALS['babilim_test_settings_errors'] = array();
			$this->post( 'babilim_request', array( 'kind' => $pair[0], 'slug' => $pair[1], 'locale' => 'de_DE', 'version' => '3.4.5' ) );
			$admin->dispatch_babilim_request();
			$this->assertCount( 0, $http->requests );
			$errors = array_values( array_filter( get_settings_errors( 'babilim' ), static function ( $e ) { return 'error' === $e['type']; } ) );
			$this->assertNotEmpty( $errors );
			$this->assertSame( 'unknown_component', $errors[0]['code'] );
		}
	}

	/**
	 * Installs one plugin with the given version and returns the fake
	 * HTTP that would receive the request.
	 */
	private function request_setup( string $version, array $locales ): Babilim_Fake_Http {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array_fill_keys( $locales, 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => $version, 'TextDomain' => 'acme' ),
		);
		return $this->request_accepted_http();
	}

	private function last_error(): array {
		$errors = array_values( array_filter( get_settings_errors( 'babilim' ), static function ( $e ) { return 'error' === $e['type']; } ) );
		$this->assertNotEmpty( $errors, 'an error notice was recorded' );
		return $errors[0];
	}

	public function test_request_with_a_version_of_exactly_64_characters_is_sent_and_matches_the_schema(): void {
		$version = str_repeat( '1', 64 );
		$http    = $this->request_setup( $version, array( 'de_DE' ) );
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE' ) );

		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests );
		$this->assertRequestPayloadMatchesSchema( $http->requests[0]['args']['body'] );
		$this->assertSame( $version, json_decode( $http->requests[0]['args']['body'], true )['version'] );
	}

	public function test_request_with_a_version_over_64_characters_is_refused_and_never_shortened(): void {
		$http = $this->request_setup( str_repeat( '1', 65 ), array( 'de_DE' ) );
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE' ) );

		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 0, $http->requests, 'a cut-down version would name another release, so nothing is sent' );
		$error = $this->last_error();
		$this->assertSame( 'version_too_long', $error['code'] );
		$this->assertStringContainsString( '64', $error['message'] );
	}

	/**
	 * A request may only name a locale that has a row with a request
	 * button on that component -- status `requestable` or `failed` -- and
	 * that check reads the stored state only: no call to the service.
	 */
	public function test_request_locale_must_be_one_the_component_offers_a_request_button_for(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0.0', 'TextDomain' => 'acme' ),
		);
		// A real registry locale (cached), but not one this site manages for acme.
		set_transient( 'babilim_meta_locales_cache', array( 'de_DE', 'fr_FR', 'ja', 'it_IT', 'es_ES' ), 12 * HOUR_IN_SECONDS );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'requestable', 'ja' => 'failed', 'it_IT' => 'babilim_installed', 'es_ES' => 'unsupported' ) );
		$http = $this->request_accepted_http();

		foreach ( array( 'de_DE', 'it_IT', 'es_ES' ) as $refused ) {
			$GLOBALS['babilim_test_settings_errors'] = array();
			$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => $refused ) );
			$this->admin( $http )->dispatch_babilim_request();
			$this->assertSame( 'unknown_locale', $this->last_error()['code'], $refused );
		}
		$this->assertCount( 0, $http->requests, 'nothing was sent, and the locale registry was not asked either' );

		foreach ( array( 'fr_FR', 'ja' ) as $accepted ) {
			$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => $accepted ) );
			$this->admin( $http )->dispatch_babilim_request();
		}
		$this->assertCount( 2, $http->requests );
		foreach ( $http->requests as $request ) {
			$this->assertSame( 'https://api.babilim.org/v1/requests', $request['url'] );
			$this->assertRequestPayloadMatchesSchema( $request['args']['body'] );
		}
	}

	/**
	 * The request action and the button use the same rule: every locale
	 * row the translations list draws a "Request translation" button for is
	 * accepted, and a row without one is refused.
	 */
	public function test_the_request_button_and_the_request_check_agree(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0.0', 'TextDomain' => 'acme' ),
		);
		$statuses = array( 'de_DE' => 'requestable', 'fr_FR' => 'failed', 'ja' => 'babilim_available', 'it_IT' => 'queued' );
		$this->set_locale_statuses( 'plugin', 'acme', $statuses );
		$_GET = array( 'page' => 'babilim' );
		$html = $this->admin()->render_current_page();

		foreach ( $statuses as $locale => $status ) {
			$has_button = 1 === preg_match( '#name="action" value="babilim_request"[^>]*/><input[^>]*name="kind"[^>]*/><input[^>]*name="slug"[^>]*/><input[^>]*name="locale" value="' . $locale . '"#', $html );
			$http = $this->request_accepted_http();
			$GLOBALS['babilim_test_settings_errors'] = array();
			$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => $locale ) );
			$this->admin( $http )->dispatch_babilim_request();
			$this->assertSame( $has_button, 1 === count( $http->requests ), "{$locale} ({$status}): button shown iff the request is accepted" );
		}
	}

	public function test_request_locale_of_exactly_35_characters_is_sent_and_a_36_character_one_is_not(): void {
		$long = str_repeat( 'l', 35 );
		$http = $this->request_setup( '1.0.0', array( $long, $long . 'x' ) );

		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => $long ) );
		$this->admin( $http )->dispatch_babilim_request();
		$this->assertCount( 1, $http->requests );
		$this->assertRequestPayloadMatchesSchema( $http->requests[0]['args']['body'] );

		$GLOBALS['babilim_test_settings_errors'] = array();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => $long . 'x' ) );
		$this->admin( $http )->dispatch_babilim_request();
		$this->assertCount( 1, $http->requests, 'the 36-character locale was not sent' );
		$this->assertSame( 'unknown_locale', $this->last_error()['code'] );
	}

	public function test_request_slug_of_exactly_200_characters_is_sent_and_matches_the_schema(): void {
		$slug = str_repeat( 's', 200 );
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', $slug, array( 'de_DE' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			"{$slug}/{$slug}.php" => array( 'Name' => 'Long', 'Version' => '1.0.0', 'TextDomain' => 'long' ),
		);
		$http = $this->request_accepted_http();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => $slug, 'locale' => 'de_DE' ) );

		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests );
		$this->assertRequestPayloadMatchesSchema( $http->requests[0]['args']['body'] );
	}

	/**
	 * A wp.org slug that differs from the file name (hello.php ->
	 * hello-dolly) must stay requestable after WordPress clears the
	 * `update_plugins` transient: the request form, the inventory and the
	 * default index all resolve the slug through one function.
	 */
	public function test_request_for_a_root_file_plugin_still_resolves_after_the_update_transient_is_cleared(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'hello-dolly', array( 'de_DE' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'hello.php' => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2', 'TextDomain' => 'hello-dolly' ),
		);
		set_site_transient(
			'update_plugins',
			(object) array(
				'response'  => array(),
				'no_update' => array( 'hello.php' => (object) array( 'slug' => 'hello-dolly', 'id' => 'w.org/plugins/hello-dolly' ) ),
			)
		);
		$http = $this->request_accepted_http();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'hello-dolly', 'locale' => 'de_DE' ) );
		$this->admin( $http )->dispatch_babilim_request();
		$this->assertCount( 1, $http->requests, 'sanity: requestable while the transient is present' );

		delete_site_transient( 'update_plugins' );
		// The first request moved the cell on; put it back as the last sync left it.
		$this->set_locale_statuses( 'plugin', 'hello-dolly', array( 'de_DE' => 'requestable' ) );
		$GLOBALS['babilim_test_settings_errors'] = array();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'hello-dolly', 'locale' => 'de_DE' ) );
		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 2, $http->requests, 'still requestable after WordPress cleared the transient' );
		$sent = json_decode( $http->requests[1]['args']['body'], true );
		$this->assertSame( 'hello-dolly', $sent['slug'] );
		$this->assertSame( '1.7.2', $sent['version'] );
	}

	public function test_request_for_a_component_without_a_version_sends_nothing_and_says_why(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'de_DE' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'TextDomain' => 'acme' ),
		);
		$http = $this->request_accepted_http();

		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE', 'version' => '1.0.0' ) );
		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 0, $http->requests );
		$errors = array_values( array_filter( get_settings_errors( 'babilim' ), static function ( $e ) { return 'error' === $e['type']; } ) );
		$this->assertCount( 1, $errors, 'the owner is told the request was not sent' );
		// Its own code and wording: "installed, but declares no version"
		// is not "not installed".
		$this->assertSame( 'no_version', $errors[0]['code'] );
		$this->assertStringContainsString( 'does not declare a version', $errors[0]['message'] );
		$this->assertStringNotContainsString( 'not installed', $errors[0]['message'] );
	}

	public function test_feedback_without_consent_is_refused_and_makes_zero_http_calls(): void {
		update_option( 'babilim_consent', false );
		$http = new Babilim_Fake_Http( array() );
		$this->post(
			'babilim_feedback',
			array(
				'kind'     => 'plugin',
				'slug'     => 'a',
				'locale'   => 'de_DE',
				'build_id' => 'bd_000000000001',
				'category' => 'mistranslation',
			)
		);

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_feedback();

		$this->assertCount( 0, $http->requests );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'no_consent', $errors[0]['code'] );
	}

	// -- babilim_feedback: the report payload's own shape --------------------

	public function test_feedback_sends_the_expected_payload_shape(): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'woocommerce', 'zh_TW', 'bd_000000000001' );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'feedback_id' => 'fb_000000000001' ) ), 'headers' => array() ) ) );
		$this->post(
			'babilim_feedback',
			array(
				'kind'         => 'plugin',
				'slug'         => 'woocommerce',
				'locale'       => 'zh_TW',
				'build_id'     => 'bd_000000000001',
				'category'     => 'terminology',
				'msgid'        => 'Cart',
				'msgctxt'      => '',
				'plural_index' => '0',
				'suggested'    => 'suggestion',
				'note'         => 'note',
				'screen_path'  => '/wp-admin/',
			)
		);

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_feedback();

		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( 'plugin', $sent['kind'] );
		$this->assertSame( 'woocommerce', $sent['slug'] );
		$this->assertSame( 'zh_TW', $sent['locale'] );
		$this->assertSame( 'bd_000000000001', $sent['build_id'] );
		$this->assertSame( 'terminology', $sent['category'] );
		$this->assertSame( 'Cart', $sent['msgid'] );
		$this->assertSame( 0, $sent['plural_index'] );
		$this->assertSame( 'suggestion', $sent['suggested'] );
	}

	/**
	 * msgid and msgctxt must both reach the service byte-for-byte,
	 * including HTML markup, embedded newlines, and leading/trailing
	 * whitespace (real translation strings sometimes carry all three,
	 * and msgctxt is gettext's own disambiguating-context string, drawn
	 * from the same source as msgid, not free-form user text) --
	 * sanitize_text_field()/sanitize_textarea_field() would have
	 * stripped/collapsed/trimmed them. current_msgstr, a genuinely
	 * free-form multi-line <textarea> field, must keep its own line
	 * breaks -- sanitize_text_field() would have collapsed them to
	 * spaces.
	 */
	public function test_feedback_preserves_html_and_line_breaks_in_msgid_msgctxt_and_multiline_fields(): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'woocommerce', 'zh_TW', 'bd_000000000001' );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'feedback_id' => 'fb_000000000001' ) ), 'headers' => array() ) ) );
		$msgctxt_raw = "\tbutton label <b>context</b>\nline two ";
		$this->post(
			'babilim_feedback',
			array(
				'kind'           => 'plugin',
				'slug'           => 'woocommerce',
				'locale'         => 'zh_TW',
				'build_id'       => 'bd_000000000001',
				'category'       => 'terminology',
				'msgid'          => "Save <strong>changes</strong>\nnow",
				'msgctxt'        => $msgctxt_raw,
				'current_msgstr' => "Line one\nLine two",
			)
		);

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_feedback();

		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( "Save <strong>changes</strong>\nnow", $sent['msgid'] );
		$this->assertSame( $msgctxt_raw, $sent['msgctxt'], 'msgctxt must survive untrimmed and uncollapsed, unlike sanitize_textarea_field()' );
		$this->assertSame( "Line one\nLine two", $sent['current_msgstr'] );
	}

	/**
	 * The feedback fields with a contract limit, as [field, limit,
	 * label shown in the error].
	 *
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public static function feedback_limited_fields(): array {
		return array(
			'msgid'          => array( 'msgid', 4000, 'Original string' ),
			'msgctxt'        => array( 'msgctxt', 4000, 'String context' ),
			'current_msgstr' => array( 'current_msgstr', 4000, 'Current translation' ),
			'suggested'      => array( 'suggested', 4000, 'Suggested translation' ),
			'note'           => array( 'note', 4000, 'Note (optional)' ),
			'screen_path'    => array( 'screen_path', 500, 'Where you saw this (optional)' ),
		);
	}

	/**
	 * @param array<string,string> $extra Fields to add to a valid report.
	 */
	private function post_feedback( array $extra ): void {
		$this->post(
			'babilim_feedback',
			array_merge(
				array(
					'kind'     => 'plugin',
					'slug'     => 'woocommerce',
					'locale'   => 'zh_TW',
					'build_id' => 'bd_000000000001',
					'category' => 'terminology',
				),
				$extra
			)
		);
	}

	private function feedback_created_http(): Babilim_Fake_Http {
		return new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'feedback_id' => 'fb_000000000001' ) ), 'headers' => array() ) ) );
	}

	private function assertFeedbackPayloadMatchesSchema( string $raw_json ): void {
		$errors = Babilim_Schema_Validator::validate(
			json_decode( $raw_json ),
			json_decode( file_get_contents( __DIR__ . '/../contract/v1/schemas/feedback.request.schema.json' ), true )
		);
		$this->assertSame( array(), $errors, implode( "\n", $errors ) );
	}

	/**
	 * @dataProvider feedback_limited_fields
	 */
	public function test_feedback_field_exactly_at_its_limit_is_sent_and_matches_the_schema( string $field, int $limit ): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'woocommerce', 'zh_TW', 'bd_000000000001' );
		$http = $this->feedback_created_http();
		// Characters, not bytes: a 3-byte character must count once.
		$value = str_repeat( '€', $limit );
		$this->post_feedback( array( $field => $value ) );

		$this->admin( $http )->dispatch_babilim_feedback();

		$this->assertCount( 1, $http->requests );
		$this->assertFeedbackPayloadMatchesSchema( $http->requests[0]['args']['body'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( $value, $sent[ $field ], 'nothing is cut from a value that fits' );
	}

	/**
	 * @dataProvider feedback_limited_fields
	 */
	public function test_feedback_field_one_over_its_limit_is_not_sent_and_the_error_names_it( string $field, int $limit, string $label ): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'woocommerce', 'zh_TW', 'bd_000000000001' );
		$http = $this->feedback_created_http();
		$this->post_feedback( array( $field => str_repeat( 'x', $limit + 1 ) ) );

		$this->admin( $http )->dispatch_babilim_feedback();

		$this->assertCount( 0, $http->requests, 'an over-long field is never sent, and never cut short' );
		$errors = array_values( array_filter( get_settings_errors( 'babilim' ), static function ( $e ) { return 'error' === $e['type']; } ) );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'field_too_long', $errors[0]['code'] );
		$this->assertStringContainsString( '"' . $label . '"', $errors[0]['message'] );
		$this->assertStringContainsString( (string) ( $limit + 1 ), $errors[0]['message'], 'says how long it is' );
		$this->assertStringContainsString( 'limit is ' . $limit, $errors[0]['message'] );
	}

	/**
	 * A browser submits each textarea line break as CRLF although its
	 * `maxlength` counts one character per break, so a text the browser
	 * accepted at exactly the limit must still be accepted here.
	 */
	public function test_feedback_textarea_at_its_limit_with_crlf_line_breaks_is_accepted(): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'woocommerce', 'zh_TW', 'bd_000000000001' );
		$http = $this->feedback_created_http();
		$this->post_feedback( array( 'note' => str_repeat( 'a', 1999 ) . "\r\n" . str_repeat( 'b', 2000 ) ) );

		$this->admin( $http )->dispatch_babilim_feedback();

		$this->assertCount( 1, $http->requests );
		$this->assertFeedbackPayloadMatchesSchema( $http->requests[0]['args']['body'] );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( str_repeat( 'a', 1999 ) . "\n" . str_repeat( 'b', 2000 ), $sent['note'] );
	}

	public function test_feedback_with_a_slug_or_locale_outside_the_contract_is_not_sent(): void {
		update_option( 'babilim_consent', true );
		$http = $this->feedback_created_http();
		$cases = array(
			'slug 201'   => array( 'slug' => str_repeat( 's', 201 ) ),
			'locale 36'  => array( 'locale' => str_repeat( 'l', 36 ) ),
			'bad kind'   => array( 'kind' => 'mu-plugin' ),
			'empty slug' => array( 'slug' => '' ),
		);
		foreach ( $cases as $label => $override ) {
			$GLOBALS['babilim_test_settings_errors'] = array();
			$this->post_feedback( $override );
			$this->admin( $http )->dispatch_babilim_feedback();
			$errors = array_values( array_filter( get_settings_errors( 'babilim' ), static function ( $e ) { return 'error' === $e['type']; } ) );
			$this->assertNotEmpty( $errors, $label );
			$this->assertSame( 'invalid_component', $errors[0]['code'], $label );
		}
		$this->assertCount( 0, $http->requests );
	}

	public function test_feedback_slug_200_and_locale_35_are_sent_and_match_the_schema(): void {
		update_option( 'babilim_consent', true );
		$slug   = str_repeat( 's', 200 );
		$locale = str_repeat( 'l', 35 );
		$this->set_known_build( 'plugin', $slug, $locale, 'bd_000000000001' );
		$http = $this->feedback_created_http();
		$this->post_feedback( array( 'slug' => $slug, 'locale' => $locale ) );

		$this->admin( $http )->dispatch_babilim_feedback();

		$this->assertCount( 1, $http->requests );
		$this->assertFeedbackPayloadMatchesSchema( $http->requests[0]['args']['body'] );
	}

	/**
	 * msgid / msgctxt are handed over by the translations list, never typed:
	 * they cannot be cut, so a report page for one over the limit offers
	 * no form (nothing to fill in and then lose) and says why.
	 *
	 * @dataProvider over_long_report_strings
	 */
	public function test_the_report_page_offers_no_form_for_a_string_over_the_limit( string $field, int $length, bool $form_expected ): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'woocommerce', 'zh_TW', 'bd_000000000001' );
		$_GET = array( 'screen' => 'report', 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000001', $field => str_repeat( '€', $length ) );

		$html = $this->admin()->render_current_page();

		if ( $form_expected ) {
			$this->assertStringContainsString( 'value="babilim_feedback"', $html );
			$this->assertStringContainsString( str_repeat( '€', $length ), $html, 'a string that fits is passed on whole' );
		} else {
			$this->assertStringNotContainsString( '<form', $html );
			$this->assertStringNotContainsString( 'babilim_feedback', $html );
			$this->assertStringContainsString( 'too long to be sent as a report', $html );
		}
	}

	/** @return array<string,array{0:string,1:int,2:bool}> */
	public static function over_long_report_strings(): array {
		return array(
			'msgid 4000'    => array( 'msgid', 4000, true ),
			'msgid 4001'    => array( 'msgid', 4001, false ),
			'msgid 10001'   => array( 'msgid', 10001, false ),
			'msgctxt 4000'  => array( 'msgctxt', 4000, true ),
			'msgctxt 4001'  => array( 'msgctxt', 4001, false ),
		);
	}

	public function test_a_report_for_an_over_long_string_never_sends_a_request(): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'woocommerce', 'zh_TW', 'bd_000000000001' );
		$http = $this->feedback_created_http();
		$this->post_feedback( array( 'msgid' => str_repeat( 'x', 4001 ) ) );

		$this->admin( $http )->dispatch_babilim_feedback();

		$this->assertCount( 0, $http->requests );
	}

	public function test_the_report_form_limits_what_can_be_typed_to_the_contract(): void {
		$_GET = array( 'screen' => 'report', 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000001' );
		if ( ! babilim_test_real_kses_available() ) {
			$this->markTestSkipped( 'The real wp-includes/kses.php is not present under tools/wp-core/ -- run `make tools`.' );
		}
		// What the browser gets is the page after real kses, not the raw markup.
		$html = babilim_real_wp_kses( $this->admin()->render_current_page(), Babilim_Admin::allowed_admin_html() );

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		foreach ( array(
			'current_msgstr' => 'textarea',
			'suggested'      => 'textarea',
			'note'           => 'textarea',
			'screen_path'    => 'input',
		) as $name => $tag ) {
			$nodes = $xpath->query( "//{$tag}[@name='{$name}']" );
			$this->assertSame( 1, $nodes->length, $name );
			$node = $nodes->item( 0 );
			if ( $node instanceof DOMElement ) {
				$expected = 'screen_path' === $name ? '500' : '4000';
				$this->assertSame( $expected, $node->getAttribute( 'maxlength' ), "{$name} maxlength" );
			} else {
				$this->fail( "{$name} is not an element" );
			}
		}
	}

	/**
	 * An array value for msgid or msgctxt in the submitted form (e.g.
	 * `msgid[]=x`) is dropped, never coerced into the literal string
	 * "Array" and sent to the service.
	 */
	public function test_feedback_drops_array_msgid_and_msgctxt_fields(): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'woocommerce', 'zh_TW', 'bd_000000000001' );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'feedback_id' => 'fb_000000000001' ) ), 'headers' => array() ) ) );
		$this->post(
			'babilim_feedback',
			array(
				'kind'     => 'plugin',
				'slug'     => 'woocommerce',
				'locale'   => 'zh_TW',
				'build_id' => 'bd_000000000001',
				'category' => 'terminology',
				'msgid'    => array( 'x' ),
				'msgctxt'  => array( 'y' ),
			)
		);

		$this->admin( $http )->dispatch_babilim_feedback();

		$this->assertCount( 1, $http->requests );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'msgid', $sent );
		$this->assertArrayNotHasKey( 'msgctxt', $sent );
	}

	/**
	 * A hidden "Report a problem" link is only
	 * one line of defense (a direct POST bypasses it entirely) --
	 * submit_feedback_from_request() itself must reject a malformed
	 * build_id before ever sending it.
	 */
	public function test_feedback_rejects_a_malformed_build_id_without_making_a_request(): void {
		update_option( 'babilim_consent', true );
		$this->set_known_build( 'plugin', 'a', 'de_DE', 'bd_000000000001' );
		$http = new Babilim_Fake_Http( array() );
		$this->post(
			'babilim_feedback',
			array(
				'kind'     => 'plugin',
				'slug'     => 'a',
				'locale'   => 'de_DE',
				'build_id' => '',
				'category' => 'mistranslation',
			)
		);

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_feedback();

		$this->assertCount( 0, $http->requests );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'invalid_build_id', $errors[0]['code'] );
	}

	/**
	 * A build_id that merely *looks* right (matches `^bd_[0-9a-f]{12}$`)
	 * but isn't one this site actually knows about for this component/
	 * locale (its last-synced status, or a genuinely installed manifest
	 * record) must also be rejected -- reporting a problem is only ever
	 * meaningful against a build this site has actually seen.
	 */
	public function test_feedback_rejects_a_well_formed_but_unknown_build_id(): void {
		update_option( 'babilim_consent', true );
		// Known build is a DIFFERENT one from what's submitted below.
		$this->set_known_build( 'plugin', 'a', 'de_DE', 'bd_aaaaaaaaaaaa' );
		$http = new Babilim_Fake_Http( array() );
		$this->post(
			'babilim_feedback',
			array(
				'kind'     => 'plugin',
				'slug'     => 'a',
				'locale'   => 'de_DE',
				'build_id' => 'bd_bbbbbbbbbbbb',
				'category' => 'mistranslation',
			)
		);

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_feedback();

		$this->assertCount( 0, $http->requests );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'unknown_build', $errors[0]['code'] );
	}

	public function test_feedback_rejects_an_invalid_category_without_making_a_request(): void {
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http( array() );
		$this->post(
			'babilim_feedback',
			array(
				'kind'     => 'plugin',
				'slug'     => 'a',
				'locale'   => 'de_DE',
				'build_id' => 'bd_000000000001',
				'category' => 'not-a-real-category',
			)
		);

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_feedback();

		$this->assertCount( 0, $http->requests );
	}

	// -- overview: reaching the settings screen once consent is given ----

	public function test_overview_links_to_settings_after_consent_with_no_components(): void {
		update_option( 'babilim_consent', true );
		( new Babilim_State() )->set( array( 'fetched_at' => gmdate( 'c' ) ) );
		$_GET = array();

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'page=babilim-settings', $html, 'the settings page must stay reachable' );
	}

	public function test_overview_links_to_settings_after_consent_with_components(): void {
		update_option( 'babilim_consent', true );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'requestable' ) ) ),
				),
				'fetched_at' => gmdate( 'c' ),
			)
		);
		$_GET = array();

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'page=babilim-settings', $html );
	}

	/**
	 * A rate-limited sync (Babilim_Site::rate_limited_until()) must be
	 * visible to the site owner on the overview screen -- otherwise it
	 * would look identical to any other quiet sync.
	 */
	public function test_overview_shows_a_notice_while_sync_is_rate_limited(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		( new Babilim_State() )->set( array( 'rate_limited_until' => time() + 3600 ) );
		$_GET = array();

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'rate limited', $html );
		// b11-fix1.md (review Minor #7): a bare H:i:s reads as "today",
		// which is actively misleading once Retry-After is long enough
		// to land on a different day -- the notice must carry a full
		// date, not just a time of day.
		$expected_date = gmdate( 'F j, Y', time() + 3600 );
		$this->assertStringContainsString( $expected_date, $html, 'the rate-limit notice must show a full date, not just a time of day' );
	}

	public function test_overview_shows_only_the_rate_limit_notice_while_the_service_holds_the_site(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		( new Babilim_State() )->set( array( 'rate_limited_until' => time() + 3600 ) );
		update_option( Babilim_Site::OPTION_LAST_SYNC_ERROR, array( 'code' => 'not_registered', 'message' => 'This site is not registered with Babilim yet.', 'since' => gmdate( 'c' ), 'at' => gmdate( 'c' ) ) );
		$_GET = array();

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'rate limited', $html );
		$this->assertStringNotContainsString( 'notice-error', $html, 'no red box next to the yellow notice' );
		$this->assertStringNotContainsString( 'babilim_sync_restart', $html, 'a retry cannot work before the wait is over' );
	}

	public function test_overview_still_shows_the_error_box_once_the_wait_is_over(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		update_option( Babilim_Site::OPTION_LAST_SYNC_ERROR, array( 'code' => 'not_registered', 'message' => 'This site is not registered with Babilim yet.', 'since' => gmdate( 'c' ), 'at' => gmdate( 'c' ) ) );
		( new Babilim_State() )->set( array( 'rate_limited_until' => time() - 60 ) );
		$_GET = array();

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'babilim_sync_restart', $html );
	}

	public function test_overview_does_not_repeat_the_rate_limit_notice_a_click_already_queued(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		$until = time() + 3600;
		( new Babilim_State() )->set( array( 'rate_limited_until' => $until ) );
		$_GET = array();
		add_settings_error( 'babilim', 'rate_limited', 'queued by a click', 'warning' );

		$html = $this->admin()->render_current_page();

		$this->assertSame( 1, substr_count( $html, 'notice-warning' ), 'the queued notice is the only yellow box; this page adds no second one' );
	}

	public function test_overview_shows_no_rate_limit_notice_once_the_deadline_has_passed(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		( new Babilim_State() )->set( array( 'rate_limited_until' => time() - 60 ) );
		$_GET = array();

		$html = $this->admin()->render_current_page();

		$this->assertStringNotContainsString( 'rate limited', $html );
	}

	// -- Advanced settings: supplementary translations section ------------------

	public function test_advanced_settings_shows_a_components_included_supplement_contribution(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_default_index(
			'zh_TW',
			array(
				'built_at'  => '2026-01-01T00:00:00Z',
				'included'  => array( array( 'slug' => 'woocommerce', 'component_version' => '1.0.0', 'entries' => 12 ) ),
				'excluded'  => array(),
				'conflicts' => array(),
				'entries'   => 12,
			)
		);
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) ),
				),
			)
		);
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$html  = $admin->render_current_page();

		$this->assertStringContainsString( 'Supplementary translations', $html );
		$this->assertStringContainsString( '<td>12</td>', $html );
	}

	public function test_advanced_settings_shows_why_a_components_supplement_was_excluded(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_default_index(
			'zh_TW',
			array(
				'built_at'  => '2026-01-01T00:00:00Z',
				'included'  => array(),
				'excluded'  => array( array( 'slug' => 'woocommerce', 'reason' => 'inactive' ) ),
				'conflicts' => array(),
				'entries'   => 0,
			)
		);
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) ),
				),
			)
		);
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$html  = $admin->render_current_page();

		$this->assertStringContainsString( 'component inactive', $html );
	}

	public function test_advanced_settings_shows_a_conflict_count_involving_a_component(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_default_index(
			'zh_TW',
			array(
				'built_at'  => '2026-01-01T00:00:00Z',
				'included'  => array( array( 'slug' => 'woocommerce', 'component_version' => '1.0.0', 'entries' => 5 ) ),
				'excluded'  => array(),
				'conflicts' => array(
					array( 'msgctxt' => '', 'msgid' => 'Save', 'slugs' => array( 'woocommerce', 'other-plugin' ) ),
				),
				'entries'   => 5,
			)
		);
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) ),
				),
			)
		);
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$html  = $admin->render_current_page();

		$this->assertStringContainsString( 'Conflicting keys', $html );
		$this->assertMatchesRegularExpression( '/<td>1<\/td><\/tr>/', $html );
	}

	// -- babilim_rollback_report -- the "Roll back and report" one-click action --

	/**
	 * Validate everything the report needs
	 * BEFORE rolling back, not after -- a report that was never going to
	 * be sendable (missing category here) must leave the installed files
	 * untouched, not roll back first and only then discover the report
	 * can't be sent.
	 */
	public function test_rollback_report_validates_before_rolling_back_not_after(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( $build_v1, $build_v2 ) = $this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );
		$http = new Babilim_Fake_Http( array() );
		$this->post(
			'babilim_rollback_report',
			array(
				'kind'     => 'plugin',
				'slug'     => 'woocommerce',
				'locale'   => 'zh_TW',
				'build_id' => $build_v2,
				// No category.
			)
		);

		$admin = new Babilim_Admin( $fs, $http );
		$admin->dispatch_babilim_rollback_report();

		$this->assertCount( 0, $http->requests );
		$after = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( $build_v2, $after['build_id'], 'no rollback must have happened -- validation must run first' );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'invalid_category', $errors[0]['code'] );
	}

	/**
	 * "Roll back and report" must actually send a report (with the
	 * category the user chose, and the build_id of the build being
	 * rolled back away from), not just silently claim success. Verified
	 * against the pinned contract schema -- a hand-rolled assertion on
	 * individual fields wouldn't catch e.g. an extra/renamed field the
	 * schema itself would reject.
	 */
	public function test_rollback_report_actually_sends_a_schema_valid_report_and_rolls_back(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( $build_v1, $build_v2 ) = $this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'woocommerce',
						'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => $build_v2 ) ),
					),
				),
			)
		);
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'feedback_id' => 'fb_000000000001' ) ), 'headers' => array() ) ) );
		$this->post(
			'babilim_rollback_report',
			array(
				'kind'     => 'plugin',
				'slug'     => 'woocommerce',
				'locale'   => 'zh_TW',
				'build_id' => $build_v2,
				'category' => 'mistranslation',
			)
		);

		$admin = new Babilim_Admin( $fs, $http );
		$admin->dispatch_babilim_rollback_report();

		$this->assertCount( 1, $http->requests, 'exactly one HTTP call -- feedback (rollback itself is filesystem-only)' );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( 'plugin', $sent['kind'] );
		$this->assertSame( 'woocommerce', $sent['slug'] );
		$this->assertSame( 'zh_TW', $sent['locale'] );
		$this->assertSame( $build_v2, $sent['build_id'], 'must report the build being rolled back away from' );
		$this->assertSame( 'mistranslation', $sent['category'] );
		$errors = Babilim_Schema_Validator::validate(
			json_decode( $http->requests[0]['args']['body'] ),
			json_decode( file_get_contents( __DIR__ . '/../contract/v1/schemas/feedback.request.schema.json' ), true )
		);
		$this->assertSame( array(), $errors, implode( "\n", $errors ) );

		// The rollback itself genuinely happened -- read through a fresh
		// Babilim_Manifest, since $manifest's own in-memory copy was
		// loaded (and cached) before the dispatch ran.
		$after = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( $build_v1, $after['build_id'] );

		$success = array_values( array_filter( get_settings_errors( 'babilim' ), static function ( $e ) { return 'success' === $e['type']; } ) );
		$this->assertNotEmpty( $success );
		$this->assertStringContainsString( 'reported', strtolower( $success[0]['message'] ) );
	}

	/**
	 * A failed report must be shown as a failure, distinctly from the
	 * rollback (which still succeeded) -- never silently swallowed the
	 * way the pre-fix "Rolled back and reported." message did.
	 */
	public function test_rollback_report_shows_a_distinct_error_when_the_report_fails(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( $build_v1, $build_v2 ) = $this->install_two_builds( $fs, $manifest, 'woocommerce', 'zh_TW' );
		$http = new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 422 ),
					'body'     => wp_json_encode( array( 'error' => array( 'code' => 'unprocessable', 'message' => 'build_id does not belong to this site', 'hint' => '' ) ) ),
					'headers'  => array(),
				),
			)
		);
		$this->post(
			'babilim_rollback_report',
			array(
				'kind'     => 'plugin',
				'slug'     => 'woocommerce',
				'locale'   => 'zh_TW',
				'build_id' => $build_v2,
				'category' => 'mistranslation',
			)
		);

		$admin = new Babilim_Admin( $fs, $http );
		$admin->dispatch_babilim_rollback_report();

		// The rollback still happened...
		$after = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( $build_v1, $after['build_id'] );
		// ...but the failure must be shown honestly, not hidden behind a
		// blanket "Rolled back and reported." success message.
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'error', $errors[0]['type'] );
		$this->assertStringContainsString( 'build_id does not belong to this site', $errors[0]['message'] );
	}

	/**
	 * When the rollback itself fails, no report is sent at all -- there's
	 * nothing to report against yet.
	 */
	public function test_rollback_report_sends_no_report_when_the_rollback_itself_fails(): void {
		update_option( 'babilim_consent', true );
		// A known build (so validation passes) for a component that was
		// never actually installed (so rollback() itself fails with
		// not_installed) -- isolates "the rollback failed" from "the
		// report was never even valid to begin with".
		$this->set_known_build( 'plugin', 'nonexistent', 'zh_TW', 'bd_000000000099' );
		$http = new Babilim_Fake_Http( array() );
		$this->post(
			'babilim_rollback_report',
			array(
				'kind'     => 'plugin',
				'slug'     => 'nonexistent',
				'locale'   => 'zh_TW',
				'build_id' => 'bd_000000000099',
				'category' => 'mistranslation',
			)
		);

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_rollback_report();

		$this->assertCount( 0, $http->requests );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'error', $errors[0]['type'] );
	}

	// -- babilim_save_settings ------------------------------------------------

	public function test_save_settings_persists_manual_locales(): void {
		$GLOBALS['babilim_test_site_locale'] = 'en_US';
		$this->post(
			'babilim_save_settings',
			array(
				'consent' => 'on',
				'locales' => array( 'de_DE', 'fr_FR' ),
			)
		);

		$admin = $this->admin();
		$admin->dispatch_babilim_save_settings();

		$this->assertSame( array( 'de_DE', 'fr_FR' ), get_option( 'babilim_manual_locales' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function invalid_manual_locales(): array {
		return array(
			'36 characters'  => array( str_repeat( 'l', 36 ) ),
			'has a slash'    => array( 'de/DE' ),
			'has a space'    => array( 'de DE' ),
			'has a comma'    => array( 'de,DE' ),
		'script variant' => array( 'sr_RS@latin' ),
		);
	}

	/**
	 * @dataProvider invalid_manual_locales
	 */
	public function test_a_manually_added_locale_that_is_not_valid_is_refused_and_nothing_is_saved( string $typed ): void {
		update_option( 'babilim_consent', false );
		update_option( 'babilim_manual_locales', array( 'pt_BR' ) );
		$this->post( 'babilim_save_settings', array( 'consent' => 'on', 'locales' => array( 'pt_BR' ), 'new_locale' => $typed ) );

		$this->admin()->dispatch_babilim_save_settings();

		$this->assertSame( array( 'pt_BR' ), get_option( 'babilim_manual_locales' ), 'the list is unchanged' );
		$this->assertFalse( (bool) get_option( 'babilim_consent' ), 'nothing else was saved either' );
		$codes = array_column( get_settings_errors( 'babilim' ), 'code' );
		$this->assertContains( 'invalid_locale', $codes );
		$this->assertNotContains( 'settings_saved', $codes, 'no "Settings saved." next to the error' );
		$errors = array_values( array_filter( get_settings_errors( 'babilim' ), static function ( $e ) { return 'invalid_locale' === $e['code']; } ) );
		$this->assertSame( 'That is not a valid WordPress locale code. Nothing was saved.', $errors[0]['message'] );
	}

	public function test_a_manually_added_locale_is_saved_in_its_canonical_form(): void {
		$GLOBALS['babilim_test_site_locale'] = 'en_US';
		$this->post( 'babilim_save_settings', array( 'consent' => 'on', 'locales' => array(), 'new_locale' => 'pt-br' ) );

		$this->admin()->dispatch_babilim_save_settings();

		$this->assertSame( array( 'pt_BR' ), get_option( 'babilim_manual_locales' ) );
	}

	public function test_a_manually_added_locale_of_exactly_35_characters_is_saved_and_sent(): void {
		$GLOBALS['babilim_test_site_locale'] = 'en_US';
		$typed = 'de_DE_' . str_repeat( 'l', 29 );
		$this->post( 'babilim_save_settings', array( 'consent' => 'on', 'locales' => array(), 'new_locale' => $typed ) );

		$this->admin()->dispatch_babilim_save_settings();

		$this->assertSame( array( $typed ), get_option( 'babilim_manual_locales' ) );
		$this->assertContains( 'settings_saved', array_column( get_settings_errors( 'babilim' ), 'code' ) );
		$this->assertContains( $typed, ( new Babilim_Inventory( static function () { return array(); }, static function () { return array(); }, static function () { return false; }, static function () { return array(); } ) )->locales() );
	}

	/**
	 * The core bug: a detected locale (e.g. the site locale, checked in
	 * the rendered form because it's already managed) must never be
	 * written into the manual list just because its checkbox was
	 * submitted checked -- otherwise it's stuck there forever even after
	 * the site stops detecting it on its own.
	 */
	public function test_save_settings_does_not_write_a_detected_locale_into_the_manual_list(): void {
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		$this->post( 'babilim_save_settings', array( 'locales' => array( 'de_DE', 'pt_BR' ) ) );

		$admin = $this->admin();
		$admin->dispatch_babilim_save_settings();

		$this->assertSame( array( 'pt_BR' ), get_option( 'babilim_manual_locales' ) );
	}

	public function test_save_settings_can_add_a_new_locale_via_the_text_field(): void {
		$GLOBALS['babilim_test_site_locale'] = 'en_US';
		$this->post( 'babilim_save_settings', array( 'locales' => array(), 'new_locale' => 'vi' ) );

		$admin = $this->admin();
		$admin->dispatch_babilim_save_settings();

		$this->assertSame( array( 'vi' ), get_option( 'babilim_manual_locales' ) );
	}

	public function test_save_settings_unchecking_a_previously_manual_locale_removes_it(): void {
		$GLOBALS['babilim_test_site_locale'] = 'en_US';
		update_option( 'babilim_manual_locales', array( 'pt_BR', 'vi' ) );
		// 'vi' is no longer checked -- only 'pt_BR' comes back.
		$this->post( 'babilim_save_settings', array( 'locales' => array( 'pt_BR' ) ) );

		$admin = $this->admin();
		$admin->dispatch_babilim_save_settings();

		$this->assertSame( array( 'pt_BR' ), get_option( 'babilim_manual_locales' ) );
	}

	// -- keep-custom-on-purge setting ---------------------------------------

	public function test_keep_custom_defaults_to_checked(): void {
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin()->render_current_page();

		$this->assertMatchesRegularExpression( '/name="keep_custom"[^>]*checked=\'checked\'/', $html );
	}

	public function test_save_settings_persists_keep_custom_being_unchecked(): void {
		update_option( 'babilim_keep_custom_on_purge', 1 );
		$this->post( 'babilim_save_settings', array( 'locales' => array() ) ); // keep_custom omitted -> unchecked.

		$admin = $this->admin();
		$admin->dispatch_babilim_save_settings();

		$this->assertFalse( (bool) get_option( 'babilim_keep_custom_on_purge' ) );
	}

	public function test_save_settings_persists_keep_custom_being_unchecked_on_a_fresh_site(): void {
		// No option yet: core's update_option() returns early when the new value equals get_option()'s default (false).
		$this->post( 'babilim_save_settings', array( 'locales' => array() ) );
		$this->admin()->dispatch_babilim_save_settings();

		$this->assertSame( 0, (int) get_option( 'babilim_keep_custom_on_purge', 1 ) );
	}

	public function test_feedback_category_options_show_translated_labels_not_enum_values(): void {
		$_GET = array( 'screen' => 'report', 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000001' );
		$html = $this->admin()->render_current_page();

		$expected = array(
			'mistranslation' => 'Wrong meaning',
			'terminology'    => 'Wrong term',
			'placeholder'    => 'Broken placeholder',
			'context'        => 'Wrong for this context',
			'plural'         => 'Wrong plural form',
			'negation'       => 'Negation reversed',
			'loading'        => 'Translation does not load',
			'other'          => 'Other',
		);
		foreach ( $expected as $value => $label ) {
			$this->assertStringContainsString( '<option value="' . $value . '">' . $label . '</option>', $html, $value );
		}
	}

	public function test_save_settings_persists_keep_custom_being_checked(): void {
		$this->post( 'babilim_save_settings', array( 'locales' => array(), 'keep_custom' => 'on' ) );

		$admin = $this->admin();
		$admin->dispatch_babilim_save_settings();

		$this->assertTrue( (bool) get_option( 'babilim_keep_custom_on_purge' ) );
	}

	// -- babilim_purge_all -- the settings screen's danger zone ------------

	public function test_purge_all_respects_keep_custom_and_reports_what_it_removed(): void {
		update_option( 'babilim_keep_custom_on_purge', 1 );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'woocommerce',
			'zh_TW',
			array(
				'component_version' => '1.0.0',
				'files'             => array(),
				'default_files'     => array(),
			)
		);
		$fs->put( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ), 'site override' );
		$this->post( 'babilim_purge_all', array() );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$admin->dispatch_babilim_purge_all();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ) ), 'custom/ must survive -- keep_custom is on' );
		$this->assertNull( ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'success', $errors[0]['type'] );
		$this->assertStringContainsString( '1', $errors[0]['message'] );
	}

	public function test_purge_all_turns_automatic_installs_off_so_cron_does_not_put_everything_back(): void {
		$fs = new Babilim_Memory_Fs();
		$this->post( 'babilim_purge_all', array() );
		$this->assertTrue( (bool) get_option( 'babilim_auto_install', true ), 'on until the purge' );

		( new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) ) )->dispatch_babilim_purge_all();

		$this->assertFalse( (bool) get_option( 'babilim_auto_install', true ) );
	}

	public function test_purge_all_success_notice_says_automatic_installs_are_off(): void {
		$this->post( 'babilim_purge_all', array() );
		( new Babilim_Admin( new Babilim_Memory_Fs(), new Babilim_Fake_Http( array() ) ) )->dispatch_babilim_purge_all();

		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'success', $errors[0]['type'] );
		$this->assertStringContainsString( 'Automatic installs are now off', $errors[0]['message'] );
	}

	public function test_purge_all_clears_custom_when_keep_custom_is_off(): void {
		update_option( 'babilim_keep_custom_on_purge', 0 );
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ), 'site override' );
		$this->post( 'babilim_purge_all', array() );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$admin->dispatch_babilim_purge_all();

		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
	}

	// -- settings screen: managed locales checkbox state --------------------

	public function test_managed_locales_checkbox_is_checked_for_the_actually_managed_set(): void {
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		update_option( 'babilim_manual_locales', array( 'pt_BR' ) );
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin()->render_current_page();

		$this->assertMatchesRegularExpression( '/value="de_DE"[^>]*checked=\'checked\'/', $html );
		$this->assertMatchesRegularExpression( '/value="pt_BR"[^>]*checked=\'checked\'/', $html );
	}

	public function test_managed_locales_uses_the_service_locale_catalog_when_consent_is_granted(): void {
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( array( 'wp_locale' => 'ja', 'english_name' => 'Japanese', 'native_name' => 'Japanese (native)' ) ) ),
					'headers'  => array(),
				),
			)
		);
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin( $http )->render_current_page();

		$this->assertStringContainsString( 'value="ja"', $html );
		$this->assertNotEmpty( $http->requests );
		$this->assertStringContainsString( '/v1/meta/locales', $http->requests[0]['url'] );
	}

	public function test_managed_locales_never_calls_the_service_without_consent(): void {
		$http = new Babilim_Fake_Http( array() );
		$_GET = array( 'screen' => 'settings' );

		$this->admin( $http )->render_current_page();

		$this->assertCount( 0, $http->requests );
	}

	/**
	 * The settings screen renders (so would otherwise call
	 * out) on every single request -- the parsed catalog is cached in a
	 * transient so a second render within the TTL makes zero requests.
	 */
	public function test_managed_locales_uses_the_cached_catalog_on_a_second_render(): void {
		update_option( 'babilim_consent', true );
		set_transient( 'babilim_meta_locales_cache', array( 'ja', 'de_DE' ), 12 * HOUR_IN_SECONDS );
		$http = new Babilim_Fake_Http( array() ); // Would exhaust immediately if called.
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin( $http )->render_current_page();

		$this->assertCount( 0, $http->requests );
		$this->assertStringContainsString( 'value="ja"', $html );
		$this->assertStringContainsString( 'value="de_DE"', $html );
	}

	/**
	 * The read side of the cache (the test above) only proves a
	 * pre-seeded transient is used -- it says nothing about whether a
	 * successful service call actually writes one. This drives an
	 * empty-cache render through a real (fake) successful call and
	 * confirms the transient now holds the parsed catalog.
	 */
	public function test_managed_locales_writes_the_cache_after_a_successful_service_call(): void {
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( array( 'wp_locale' => 'ja', 'english_name' => 'Japanese', 'native_name' => 'Japanese (native)' ) ) ),
					'headers'  => array(),
				),
			)
		);
		$_GET = array( 'screen' => 'settings' );

		$this->admin( $http )->render_current_page();

		$cached = get_transient( 'babilim_meta_locales_cache' );
		$this->assertSame( array( 'ja' ), $cached, 'a successful service call must write the parsed catalog to the cache transient' );
	}

	public function test_a_failed_locale_catalog_fetch_is_not_retried_within_five_minutes(): void {
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 500 ), 'body' => '{}', 'headers' => array() ) ) );
		$_GET = array( 'screen' => 'settings' );

		$this->admin( $http )->render_current_page();
		$this->assertCount( 1, $http->requests, 'the first render tries once' );
		$this->assertSame( 5, $http->requests[0]['args']['timeout'], 'with the short interactive timeout' );

		$this->admin( $http )->render_current_page();
		$this->assertCount( 1, $http->requests, 'the second render makes no request' );
		$this->assertNotFalse( get_transient( 'babilim_meta_locales_failed' ) );
	}

	public function test_managed_locales_falls_back_with_a_notice_when_the_service_call_fails(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		$http = new Babilim_Fake_Http( array() ); // Exhausted -> WP_Error.
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin( $http )->render_current_page();

		$this->assertStringContainsString( 'value="de_DE"', $html );
		$this->assertStringContainsString( 'Could not load the full WordPress.org locale list', $html );
	}

	/**
	 * A manually-added locale that the service's own
	 * catalog doesn't (or no longer) lists must still get a checkbox
	 * (marked as not currently listed) rather than silently vanishing
	 * the next time settings are saved -- the save handler only ever
	 * keeps what it sees checked, so a locale with no checkbox at all
	 * can never survive a save.
	 */
	public function test_managed_locales_keeps_a_manual_locale_the_service_catalog_does_not_list(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_manual_locales', array( 'xx_YY' ) );
		$http = new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( array( 'wp_locale' => 'ja', 'english_name' => 'Japanese', 'native_name' => 'Japanese (native)' ) ) ),
					'headers'  => array(),
				),
			)
		);
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin( $http )->render_current_page();

		$this->assertMatchesRegularExpression( '/value="xx_YY"[^>]*checked=\'checked\'/', $html );
	}

	// -- babilim_rebuild_index / babilim_sweep_now: manual triggers ----------

	public function test_rebuild_index_action_shows_its_result(): void {
		$this->post( 'babilim_rebuild_index', array( 'locale' => '' ) );

		$admin = $this->admin();
		$admin->dispatch_babilim_rebuild_index();

		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'success', $errors[0]['type'] );
	}

	public function test_sweep_now_action_shows_its_result(): void {
		$this->post( 'babilim_sweep_now', array() );

		$admin = $this->admin();
		$admin->dispatch_babilim_sweep_now();

		$errors = get_settings_errors( 'babilim' );
		$this->assertNotEmpty( $errors );
	}

	// -- rendered HTML never carries quality/grade/verified wording -------
	// (adding a quality badge back to any admin screen must turn this red).

	public function test_rendered_overview_never_contains_quality_grade_or_verified_wording(): void {
		update_option( 'babilim_consent', true );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'woocommerce',
						'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ),
					),
				),
			)
		);

		$html = $this->admin()->render_current_page();

		$this->assertRenderedHtmlHasNoQualityWording( $html );
	}

	/**
	 * This must also cover the "Supplementary translations" section, not
	 * just the static per-locale table -- rendering with real
	 * default_index data is what exercises that section's own markup;
	 * rendering with no default_index data at all would leave a
	 * quality/grade word slipped in there undetected.
	 */
	public function test_rendered_supplementary_translations_never_contain_quality_grade_or_verified_wording(): void {
		update_option( 'babilim_consent', true );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_default_index(
			'zh_TW',
			array(
				'built_at'  => '2026-01-01T00:00:00Z',
				'included'  => array( array( 'slug' => 'woocommerce', 'component_version' => '1.0.0', 'entries' => 3 ) ),
				'excluded'  => array( array( 'slug' => 'other-plugin', 'reason' => 'inactive' ) ),
				'conflicts' => array( array( 'msgctxt' => '', 'msgid' => 'x', 'slugs' => array( 'woocommerce', 'other-plugin' ) ) ),
				'core_dupes' => 1,
				'entries'    => 3,
			)
		);
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'woocommerce',
						'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed', 'built_at' => '2026-01-01T00:00:00Z' ) ),
					),
				),
			)
		);
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$html  = $admin->render_current_page();

		$this->assertStringContainsString( 'Supplementary translations', $html, 'the section this test is meant to cover must actually be present' );
		$this->assertRenderedHtmlHasNoQualityWording( $html );
	}

	/**
	 * This must also cover the "Supplementary translation
	 * index" table -- see the supplementary-translations tests above
	 * for why rendering with no default_index data at all would leave
	 * that section entirely unchecked.
	 */
	public function test_rendered_settings_never_contains_quality_grade_or_verified_wording(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_default_index(
			'zh_TW',
			array(
				'built_at'   => '2026-01-01T00:00:00Z',
				'included'   => array( array( 'slug' => 'woocommerce', 'component_version' => '1.0.0', 'entries' => 3 ) ),
				'excluded'   => array(),
				'conflicts'  => array(),
				'core_dupes' => 0,
				'entries'    => 3,
			)
		);
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$html  = $admin->render_current_page();

		$this->assertStringContainsString( 'Supplementary translation index', $html, 'the section this test is meant to cover must actually be present' );
		$this->assertRenderedHtmlHasNoQualityWording( $html );
	}

	public function test_rendered_report_never_contains_quality_grade_or_verified_wording(): void {
		$_GET = array( 'screen' => 'report', 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000001' );

		$html = $this->admin()->render_current_page();

		$this->assertRenderedHtmlHasNoQualityWording( $html );
	}

	/**
	 * A msgid/msgctxt arriving via the report page's own query string
	 * (e.g. a future list link that carries string context) must
	 * reach the rendered form exactly as it was, the same way the send
	 * side already preserves it (see
	 * test_feedback_preserves_html_and_line_breaks_in_msgid_msgctxt_and_
	 * multiline_fields) -- sanitize_text_field()/sanitize_textarea_field()
	 * would strip the HTML and (the former) collapse the line break
	 * before the site owner ever sees it. Both are still escaped where
	 * they're actually rendered (esc_attr() on the hidden field's own
	 * value attribute), so the raw HTML never becomes live markup.
	 */
	public function test_report_page_preserves_html_and_line_breaks_in_the_msgid_and_msgctxt_query_params(): void {
		// msgctxt's leading tab and trailing space are deliberate --
		// sanitize_textarea_field() collapses/trims whitespace, so
		// without them a stub that (unlike real WordPress core) doesn't
		// also strip HTML would let a mutation back to
		// sanitize_textarea_field() pass this test undetected.
		$msgctxt_raw = "\tbutton <b>context</b>\nline two ";
		$_GET         = array(
			'screen'   => 'report',
			'kind'     => 'plugin',
			'slug'     => 'woocommerce',
			'locale'   => 'zh_TW',
			'build_id' => 'bd_000000000001',
			'msgid'    => "Save <strong>now</strong>\nplease",
			'msgctxt'  => $msgctxt_raw,
		);

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( "Save &lt;strong&gt;now&lt;/strong&gt;\nplease", $html );
		$this->assertStringContainsString( "\tbutton &lt;b&gt;context&lt;/b&gt;\nline two ", $html );
	}

	/**
	 * P-4: an array value for msgid (e.g. `?msgid[]=x`) must be rejected
	 * outright, not coerced into the literal string "Array" by a bare
	 * (string) cast.
	 */
	public function test_report_page_rejects_an_array_msgid_query_param(): void {
		$_GET = array(
			'screen'   => 'report',
			'kind'     => 'plugin',
			'slug'     => 'woocommerce',
			'locale'   => 'zh_TW',
			'build_id' => 'bd_000000000001',
			'msgid'    => array( 'x' ),
		);

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'name="msgid" value=""', $html, 'an array msgid must never be coerced into a literal "Array" string' );
	}

	private function assertRenderedHtmlHasNoQualityWording( string $html ): void {
		$lower = strtolower( $html );
		$this->assertStringNotContainsString( 'quality', $lower );
		$this->assertStringNotContainsString( 'grade', $lower );
		$this->assertStringNotContainsString( 'verified', $lower );
		$this->assertStringNotContainsString( 'evidence', $lower );
	}

	// -- render_notices() escapes the underlying error message ------------

	/**
	 * The error message rendered into a notice comes from the service's
	 * own response body (Babilim_Api_Client::error_from_response()) --
	 * attacker-controlled if the service is ever compromised or
	 * impersonated. render_notices() must esc_html() it like every other
	 * dynamic value on the page.
	 */
	public function test_rendered_notices_escape_the_underlying_error_message(): void {
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 422 ),
					'body'     => wp_json_encode( array( 'error' => array( 'code' => 'unprocessable', 'message' => '"><script>alert(1)</script>', 'hint' => '' ) ) ),
					'headers'  => array(),
				),
			)
		);
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0.0', 'TextDomain' => 'a' ),
		);
		$this->set_locale_statuses( 'plugin', 'a', array( 'de_DE' => 'requestable' ) );
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE', 'version' => '1.0.0' ) );

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_request();

		$_GET = array( 'page' => 'babilim' );
		$html = $admin->render_current_page();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	// -- the site token never reaches any rendered admin screen -----------

	public function test_settings_screen_never_exposes_the_site_token(): void {
		update_option( 'babilim_site_token', 'tok_0123456789abcdef0123456789ab' );
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin()->render_current_page();

		$this->assertStringNotContainsString( 'tok_0123456789abcdef0123456789ab', $html );
	}

	// -- a failed translation request tells the site owner it can be retried --

	// -- settings screen: supplementary translation index table -----------

	public function test_settings_screen_shows_the_default_index_table(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_default_index(
			'zh_TW',
			array(
				'built_at'   => '2026-01-01T00:00:00Z',
				'included'   => array( array( 'slug' => 'a', 'component_version' => '1.0.0', 'entries' => 3 ) ),
				'excluded'   => array( array( 'slug' => 'b', 'reason' => 'inactive' ) ),
				'conflicts'  => array( array( 'msgctxt' => '', 'msgid' => 'x', 'slugs' => array( 'a', 'c' ) ) ),
				'core_dupes' => 2,
				'entries'    => 3,
			)
		);
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$html  = $admin->render_current_page();

		$this->assertStringContainsString( 'Supplementary translation index', $html );
		$this->assertStringContainsString( 'January 1, 2026 12:00 am', $html );
		$this->assertStringContainsString( '<td>1</td>', $html ); // 1 included
		$this->assertStringContainsString( '<td>2</td>', $html ); // core_dupes
	}

	// -- settings screen shows free quota in plain words, not raw JSON ----

	public function test_settings_screen_shows_quota_in_plain_words(): void {
		update_option( 'babilim_consent', true );
		( new Babilim_State() )->set(
			array(
				'license' => array( 'kind' => 'free', 'status' => 'active' ),
				'quota'   => array(
					'free_slots' => array(
						'theme'  => array( 'used' => 0, 'limit' => 1 ),
						'plugin' => array( 'used' => 1, 'limit' => 2 ),
					),
					'downloads'  => array( 'used' => 3, 'limit' => 500, 'period' => 'daily' ),
				),
			)
		);
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'Theme 0/1', $html );
		$this->assertStringContainsString( 'Plugin 1/2', $html );
		$this->assertStringContainsString( 'Downloads: 3/500 (per day)', $html );
		// Never a raw JSON dump of the quota structure.
		$this->assertStringNotContainsString( '{&quot;', $html );
		$this->assertStringNotContainsString( 'free_slots', $html );
	}

	// -- settings screen shows the sweep guard/summary in plain words -----

	public function test_settings_screen_shows_the_sweep_summary_in_plain_words(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_sweep_summary(
			array(
				'swept_at'        => '2026-01-01T00:00:00Z',
				'orphans_marked'  => 2,
				'orphans_deleted' => 1,
				'records_purged'  => 0,
			)
		);
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$html  = $admin->render_current_page();

		$this->assertStringContainsString( 'January 1, 2026 12:00 am', $html );
	}

	public function test_settings_screen_shows_a_translation_index_write_that_did_not_land(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->assertTrue( $manifest->set_sweep_summary( array( 'swept_at' => '2026-01-01T00:00:00Z', 'orphans_marked' => 0, 'orphans_deleted' => 0, 'records_purged' => 0 ) ) );
		unset( $GLOBALS['wpdb'] );
		$this->assertFalse( $manifest->set( 'plugin', 'woocommerce', 'de_DE', array( 'files' => array() ) ) );
		$GLOBALS['wpdb'] = new Babilim_Test_Wpdb( null );
		$_GET            = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		$html  = $admin->render_current_page();

		$this->assertStringContainsString( 'could not be completed and was not applied', $html );
		$this->assertStringContainsString( 'plugin|woocommerce|de_DE', $html );
		$this->assertStringContainsString( 'lock_unavailable', $html );
	}

	/**
	 * On multisite, a failed write recorded while serving one site shows
	 * on every site's settings screen -- the translation index is one
	 * file shared by the whole network -- and is gone from all of them
	 * once a later write lands.
	 */
	public function test_settings_screen_on_every_site_shows_a_failed_write_recorded_on_another_site_until_one_lands(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );
		$fs = new Babilim_Memory_Fs();

		switch_to_blog( 2 );
		$db = $GLOBALS['wpdb'];
		unset( $GLOBALS['wpdb'] );
		$this->assertFalse( ( new Babilim_Manifest( $fs ) )->set( 'plugin', 'woocommerce', 'de_DE', array( 'files' => array() ) ) );
		$GLOBALS['wpdb'] = $db;
		restore_current_blog();

		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );
		$html = ( new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) ) )->render_current_page();
		$this->assertStringContainsString( 'could not be completed and was not applied', $html );
		$this->assertStringContainsString( 'plugin|woocommerce|de_DE', $html );

		$this->assertTrue( ( new Babilim_Manifest( $fs ) )->set( 'plugin', 'woocommerce', 'de_DE', array( 'files' => array() ) ) );
		switch_to_blog( 2 );
		$html = ( new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) ) )->render_current_page();
		restore_current_blog();
		$this->assertStringContainsString( 'babilim_sweep_now', $html, 'site 2\'s settings screen must still render' );
		$this->assertStringNotContainsString( 'could not be completed and was not applied', $html );
	}

	// -- recovery UI for the verification-stalled/token-conflict flags -----

	public function test_settings_screen_shows_the_recovery_button_when_stalled(): void {
		update_option( 'babilim_verify_stalled', true );
		$_GET = array( 'screen' => 'settings', 'tab' => 'advanced' );

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'babilim_restart_registration', $html );
	}

	public function test_settings_screen_hides_the_recovery_button_when_nothing_is_wrong(): void {
		$_GET = array( 'screen' => 'settings' );

		$html = $this->admin()->render_current_page();

		$this->assertStringNotContainsString( 'babilim_restart_registration', $html );
	}

	public function test_restart_registration_action_is_wired_to_babilim_site(): void {
		update_option( 'babilim_verify_stalled', true );
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http( array() ); // No queued response -> ensure_registered() errors, which is fine; we're proving the wire-up, not a full flow.
		$this->post( 'babilim_restart_registration', array() );

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_restart_registration();

		// A request was actually attempted -- restart_registration() was reached.
		$this->assertNotEmpty( $http->requests );
	}

	// -- consent disclosure list is rendered on the settings screen -------

	public function test_settings_screen_discloses_client_events(): void {
		$_GET = array( 'page' => 'babilim-settings', 'tab' => 'advanced' );

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( 'client_events', $html );
	}

	// -- babilim_sync_now: wording must match what actually happened ------

	public function test_sync_now_without_consent_shows_an_honest_error_and_does_not_claim_sync_complete(): void {
		$http = new Babilim_Fake_Http( array() );
		$this->post( 'babilim_sync_now', array() );

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_sync_now();

		$this->assertCount( 0, $http->requests, 'sync() itself must never be reached without consent' );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'error', $errors[0]['type'] );
		$this->assertStringNotContainsString( 'Sync complete', $errors[0]['message'] );
	}

	public function test_sync_now_with_consent_does_not_claim_complete_when_the_sync_did_not_finish(): void {
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http( array() ); // sync() is best-effort and swallows the failure itself.
		$this->post( 'babilim_sync_now', array() );

		$admin = $this->admin( $http );
		$admin->dispatch_babilim_sync_now();

		$errors = get_settings_errors( 'babilim' );
		$this->assertNotSame( 'success', $errors[0]['type'], 'the fake service answered nothing, so the sync did not finish' );
		$this->assertStringNotContainsString( 'Sync complete', $errors[0]['message'] );
	}

	private function upload_offer_http( string $upload_id = 'up_9a1b2c3d4e5f' ): Babilim_Fake_Http {
		return new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 202 ),
					'body'     => wp_json_encode(
						array(
							'request_id'  => 'rq_4b7c1e0a3d6f',
							'status'      => 'queued',
							'eta_minutes' => null,
							'upload'      => array( 'upload_id' => $upload_id, 'part_bytes' => 16777216, 'max_parts' => 32, 'max_bytes' => 524288000, 'expires_at' => '2099-01-01T00:00:00Z' ),
						)
					),
					'headers'  => array(),
				),
			)
		);
	}

	public function test_request_answered_with_an_upload_redirects_to_the_confirm_screen_without_uploading(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$http = $this->upload_offer_http();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );

		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests );
		$this->assertSame( 'up_9a1b2c3d4e5f', Babilim_Upload::pending()['upload_id'] );
		$this->assertStringContainsString( 'screen=upload-confirm', $GLOBALS['babilim_test_redirects'][0]['location'] );
		$cell = ( new Babilim_State() )->status( 'plugin', 'acme', 'fr_FR' );
		$this->assertSame( 'queued', $cell['status'] );
		$this->assertTrue( $cell['upload_pending'] );
	}

	public function test_request_answered_with_an_upload_on_multisite_without_network_capability_offers_nothing(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );

		$this->admin( $this->upload_offer_http() )->dispatch_babilim_request();

		$this->assertNull( Babilim_Upload::pending() );
		$this->assertStringNotContainsString( 'upload-confirm', $GLOBALS['babilim_test_redirects'][0]['location'] );
		$this->assertStringContainsString( 'Only a network administrator', wp_json_encode( get_settings_errors( 'babilim' ) ) );
	}

	public function test_request_on_multisite_without_network_capability_does_not_declare_upload(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );
		$http = $this->request_accepted_http();

		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests );
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'client_caps', $sent );

		// A network administrator still declares it.
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options', 'manage_network_options' );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'requestable' ) );
		$http = $this->request_accepted_http();
		$this->admin( $http )->dispatch_babilim_request();
		$this->assertSame( array( 'upload' ), json_decode( $http->requests[0]['args']['body'], true )['client_caps'] );
	}

	/**
	 * @dataProvider upload_actions
	 */
	public function test_upload_actions_on_multisite_with_only_site_capability_are_refused( string $action, string $method ): void {
		update_option( Babilim_Upload::OPTION, array( 'upload_id' => 'up_9a1b2c3d4e5f', 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR', 'version' => '1.0' ) );
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$http = new Babilim_Fake_Http( array() );
		$this->post( $action, array() );
		try {
			$this->admin( $http )->$method();
			$this->fail( 'Expected wp_die() to be reached.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			// Expected.
		}
		$this->assertCount( 0, $http->requests );
		$this->assertSame( 'up_9a1b2c3d4e5f', Babilim_Upload::pending()['upload_id'] );
	}

	public static function upload_actions(): array {
		return array(
			'confirm' => array( 'babilim_upload_confirm', 'dispatch_babilim_upload_confirm' ),
			'step'    => array( 'babilim_upload_step', 'dispatch_babilim_upload_step' ),
			'cancel'  => array( 'babilim_upload_cancel', 'dispatch_babilim_upload_cancel' ),
		);
	}

	public function test_a_repeated_request_for_the_same_offer_resumes_it_and_a_new_offer_replaces_it(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'requestable' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );
		$this->admin( $this->upload_offer_http() )->dispatch_babilim_request();

		// The cell is now queued with an upload waiting: the button asks again.
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );
		$this->admin( $this->upload_offer_http() )->dispatch_babilim_request();
		$this->assertSame( 'up_9a1b2c3d4e5f', Babilim_Upload::pending()['upload_id'] );
		$this->assertStringContainsString( 'screen=upload-confirm', $GLOBALS['babilim_test_redirects'][1]['location'] );

		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );
		$http = new Babilim_Fake_Http( array( $this->upload_offer_http( 'up_aaaaaaaaaaaa' )->request( 'POST', 'x', array() ), array( 'response' => array( 'code' => 200 ), 'body' => '{"state":"cancelled"}', 'headers' => array() ) ) );
		$this->admin( $http )->dispatch_babilim_request();
		$this->assertSame( 'up_aaaaaaaaaaaa', Babilim_Upload::pending()['upload_id'] );
		$this->assertContains( 'DELETE', array_column( $http->requests, 'method' ) );
	}

	public function test_upload_confirm_screen_discloses_every_point(): void {
		update_option( 'babilim_consent', true );
		update_option( Babilim_Upload::OPTION, array( 'upload_id' => 'up_9a1b2c3d4e5f', 'kind' => 'theme', 'slug' => 'avada', 'locale' => 'fr_FR', 'version' => '7.11.4' ) );
		$_GET = array( 'page' => 'babilim', 'screen' => 'upload-confirm' );
		$html = $this->admin()->render_current_page();
		foreach ( array( 'entire', 'keys, logs or customer data', 'until you ask', 'AI', 'other sites', 'right to upload', 'Upload and request', 'Cancel' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		$this->assertStringContainsString( 'babilim_upload_confirm', $html );
		$this->assertStringContainsString( 'babilim_upload_cancel', $html );
	}

	public function test_upload_progress_screen_shows_the_part_and_an_autosubmit_continue_form(): void {
		update_option( 'babilim_consent', true );
		update_option( Babilim_Upload::OPTION, array( 'upload_id' => 'up_9a1b2c3d4e5f', 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR', 'zip' => '/x', 'parts' => 4, 'next' => 2 ) );
		$_GET = array( 'page' => 'babilim', 'screen' => 'upload-progress' );
		$html = $this->admin()->render_current_page();
		$this->assertStringContainsString( 'Uploading part 2 of 4', $html );
		$this->assertStringContainsString( 'data-babilim-autosubmit', $html );
		$this->assertStringContainsString( 'babilim_upload_step', $html );
		$this->assertStringContainsString( 'Continue', $html );
		// And the autosubmit attribute survives the real wp_kses allow-list.
		$kept = wp_kses( $html, Babilim_Admin::allowed_admin_html() );
		$this->assertStringContainsString( 'data-babilim-autosubmit', $kept );
	}

	public function test_upload_progress_autosubmit_waits_for_the_backoff(): void {
		update_option( 'babilim_consent', true );
		update_option( Babilim_Upload::OPTION, array( 'upload_id' => 'up_9a1b2c3d4e5f', 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR', 'zip' => '/x', 'parts' => 4, 'next' => 2, 'not_before' => time() + 60 ) );
		$_GET = array( 'page' => 'babilim', 'screen' => 'upload-progress' );
		$html = $this->admin()->render_current_page();
		$this->assertMatchesRegularExpression( '/data-babilim-autosubmit-delay="(59|60)\d{3}"/', $html );
		$kept = wp_kses( $html, Babilim_Admin::allowed_admin_html() );
		$this->assertStringContainsString( 'data-babilim-autosubmit-delay', $kept );
	}

	public function test_upload_progress_autosubmit_without_backoff_keeps_the_short_delay(): void {
		update_option( 'babilim_consent', true );
		update_option( Babilim_Upload::OPTION, array( 'upload_id' => 'up_9a1b2c3d4e5f', 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR', 'zip' => '/x', 'parts' => 4, 'next' => 2 ) );
		$_GET = array( 'page' => 'babilim', 'screen' => 'upload-progress' );
		$this->assertStringContainsString( 'data-babilim-autosubmit-delay="500"', $this->admin()->render_current_page() );
	}

	public function test_the_upload_actions_refuse_a_request_without_a_nonce(): void {
		update_option( Babilim_Upload::OPTION, array( 'upload_id' => 'up_9a1b2c3d4e5f', 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR', 'zip' => '/x', 'parts' => 2, 'next' => 1 ) );
		foreach ( array( 'babilim_upload_confirm', 'babilim_upload_step', 'babilim_upload_cancel' ) as $action ) {
			$_POST = $_REQUEST = array( 'action' => $action );
			$http  = new Babilim_Fake_Http( array() );
			try {
				$this->admin( $http )->{'dispatch_' . $action}();
				$this->fail( $action . ' ran without a nonce' );
			} catch ( Babilim_Test_Wp_Die_Exception $e ) {
				$this->assertCount( 0, $http->requests, $action );
			}
			$this->assertSame( 'up_9a1b2c3d4e5f', Babilim_Upload::pending()['upload_id'], $action . ' must leave the upload alone' );
		}
	}

	public function test_requesting_again_while_the_service_verifies_a_finished_upload_sends_nothing_more(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'queued' ) );
		( new Babilim_State() )->set_cell_status( 'plugin', 'acme', 'fr_FR', array( 'upload_pending' => true ) );
		update_option( Babilim_Upload::DONE_OPTION, 'up_9a1b2c3d4e5f' );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$http = $this->upload_offer_http();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );

		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests, 'only the request itself, no DELETE and no part' );
		$this->assertSame( 'POST', $http->requests[0]['method'] );
		$this->assertNull( Babilim_Upload::pending() );
		$cell = ( new Babilim_State() )->status( 'plugin', 'acme', 'fr_FR' );
		$this->assertFalse( $cell['upload_pending'] );
		$this->assertStringNotContainsString( 'screen=upload', end( $GLOBALS['babilim_test_redirects'] )['location'] );
	}

	public function test_installed_cell_with_a_newer_version_upload_waiting_may_request_again(): void {
		update_option( 'babilim_consent', true );
		( new Babilim_State() )->set( array( 'components' => array( array( 'kind' => 'plugin', 'slug' => 'acme', 'locales' => array( 'fr_FR' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_aaaaaaaaaaaa', 'newer_version' => array( 'version' => '3.4.5', 'status' => 'queued' ), 'upload_pending' => true ), 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_bbbbbbbbbbbb', 'newer_version' => array( 'version' => '3.4.5', 'status' => 'queued' ) ) ) ) ) ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$http = $this->upload_offer_http();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );
		$this->admin( $http )->dispatch_babilim_request();
		$this->assertCount( 1, $http->requests );
		$this->assertSame( 'up_9a1b2c3d4e5f', Babilim_Upload::pending()['upload_id'] );
		$this->assertStringContainsString( 'screen=upload-confirm', $GLOBALS['babilim_test_redirects'][0]['location'] );

		// Queued newer version without an upload waiting: still no request.
		$http2 = $this->upload_offer_http();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE' ) );
		$this->admin( $http2 )->dispatch_babilim_request();
		$this->assertCount( 0, $http2->requests );
	}

	public function test_a_finished_upload_id_coming_back_leaves_a_different_upload_in_progress_alone(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'queued' ) );
		( new Babilim_State() )->set_cell_status( 'plugin', 'acme', 'fr_FR', array( 'upload_pending' => true ) );
		update_option( Babilim_Upload::DONE_OPTION, 'up_9a1b2c3d4e5f' );
		update_option( Babilim_Upload::OPTION, array( 'upload_id' => 'up_other0000000', 'kind' => 'theme', 'slug' => 'avada', 'locale' => 'fr_FR', 'version' => '1' ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '3.4.5', 'TextDomain' => 'acme' ) );
		$http = $this->upload_offer_http();
		$this->post( 'babilim_request', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );

		$this->admin( $http )->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests, 'no DELETE for the other upload' );
		$this->assertSame( 'up_other0000000', Babilim_Upload::pending()['upload_id'] );
	}

	public function test_cancel_action_gives_the_offer_up_and_restores_the_cell(): void {
		update_option( 'babilim_consent', true );
		$this->set_locale_statuses( 'plugin', 'acme', array( 'fr_FR' => 'requestable' ) );
		( new Babilim_State() )->set_cell_status( 'plugin', 'acme', 'fr_FR', array( 'status' => 'queued', 'upload_pending' => true ) );
		// Cancel: the pending offer goes, the cell is requestable again.
		update_option( Babilim_Upload::OPTION, array( 'upload_id' => 'up_9a1b2c3d4e5f', 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'fr_FR' ) );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 200 ), 'body' => '{"state":"cancelled"}', 'headers' => array() ) ) );
		$this->post( 'babilim_upload_cancel', array() );
		$this->admin( $http )->dispatch_babilim_upload_cancel();
		$this->assertNull( Babilim_Upload::pending() );
		$this->assertSame( 'requestable', ( new Babilim_State() )->status( 'plugin', 'acme', 'fr_FR' )['status'] );
		$this->assertSame( 'DELETE', $http->requests[0]['method'] );
		$this->assertStringNotContainsString( 'screen=upload', end( $GLOBALS['babilim_test_redirects'] )['location'] );
	}

	public function test_step_and_confirm_with_nothing_pending_end_on_the_list_with_an_error(): void {
		update_option( 'babilim_consent', true );
		foreach ( array( 'babilim_upload_step', 'babilim_upload_confirm' ) as $action ) {
			$this->post( $action, array() );
			$http = new Babilim_Fake_Http( array() );
			$this->admin( $http )->{'dispatch_' . $action}();
			$this->assertCount( 0, $http->requests );
			$this->assertStringNotContainsString( 'screen=upload', end( $GLOBALS['babilim_test_redirects'] )['location'] );
		}
	}
}
