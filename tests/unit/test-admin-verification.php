<?php
/**
 * Tests for the admin side of the "verification required" state: the
 * settings-screen warning with per-reason advice, the admin notice on
 * other screens, the nonce- and capability-protected "Re-verify" action,
 * and the "Delete all Babilim data on uninstall" control with its three
 * risk statements (checkbox text, plugins-list row, readme).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-kses.php';

final class Test_Admin_Verification extends TestCase {

	private const NONCE = '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a';

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['hook_suffix'] );
		update_option( 'babilim_consent', true );
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['hook_suffix'] );
		Babilim::reset_for_tests();
	}

	private function admin( ?Babilim_Http $http = null ): Babilim_Admin {
		return new Babilim_Admin( new Babilim_Memory_Fs(), $http ?? new Babilim_Fake_Http( array() ) );
	}

	private function post( string $action, array $fields = array() ): void {
		$fields['action']    = $action;
		$fields['_wpnonce'] = wp_create_nonce( $action );
		$_POST               = $fields;
		$_REQUEST            = $fields;
	}

	private function json( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	private function verification_required( string $reason ): array {
		return $this->json( 403, array( 'error' => array( 'code' => 'verification_required', 'message' => 'callback failed', 'reason' => $reason ) ) );
	}

	/** A schema-valid site token derived from a readable name. */
	private function tok( string $name ): string {
		return 'tok_' . substr( md5( $name ), 0, 32 );
	}

	/**
	 * A 200 verify answer shaped exactly like the contract's
	 * (schemas/sites.verify.response.schema.json), checked against it.
	 */
	private function verified( string $token = 'tok_fresh' ): array {
		$body   = array(
			'site_token' => $this->tok( $token ),
			'verified'   => true,
			'license'    => array( 'kind' => 'free', 'status' => 'active' ),
			'quota'      => array(
				'free_slots' => array( 'theme' => array( 'used' => 0, 'limit' => 1 ), 'plugin' => array( 'used' => 0, 'limit' => 2 ) ),
				'downloads'  => array( 'used' => 0, 'limit' => 500, 'period' => 'daily' ),
			),
		);
		$schema = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/contract/v1/schemas/sites.verify.response.schema.json' ), true );
		$common = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/contract/v1/schemas/common.schema.json' ), true );
		$this->assertIsArray( $schema );
		$this->assertIsArray( $common );
		// The validator resolves only local refs, so the two shared
		// definitions are inlined next to their siblings.
		$schema['$defs']                   = $common['$defs'];
		$schema['properties']['license']   = array( '$ref' => '#/$defs/license' );
		$schema['properties']['quota']     = array( '$ref' => '#/$defs/quota' );
		$errors = Babilim_Schema_Validator::validate( json_decode( (string) wp_json_encode( $body ) ), $schema );
		$this->assertSame( array(), $errors, 'the verify double must match the contract' );
		return $this->json( 200, $body );
	}

	/** Puts the site into the warning state as if a verify already failed. */
	private function seed_warning( string $reason ): void {
		update_option( 'babilim_install_sk', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
		update_option( 'babilim_site_id', 'st_old' );
		update_option( 'babilim_pending_verification', array( 'site_id' => 'st_old', 'nonce' => self::NONCE, 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 600 ) ) );
		update_option(
			Babilim_Site::OPTION_VERIFICATION_WARNING,
			array( 'reason' => $reason, 'since' => time() - 3600, 'retries' => 1, 'next_retry_at' => time() + 3600 )
		);
	}

	private function settings_html(): string {
		$_GET = array( 'screen' => 'settings' );
		return $this->admin()->render_current_page();
	}

	private function notice_html( Babilim_Admin $admin ): string {
		ob_start();
		$admin->render_verification_admin_notice();
		return (string) ob_get_clean();
	}

	// -- settings screen warning ------------------------------------------

	public function test_each_reason_shows_its_own_advice_on_the_settings_screen(): void {
		$expect = array(
			'callback_unreachable' => array( '/wp-json/babilim/v1/challenge', 'firewall' ),
			'callback_timeout'     => array( 'try again later', 'slow' ),
			'callback_invalid'     => array( 'REST API', 'cache' ),
		);
		$seen   = array();
		foreach ( $expect as $reason => $needles ) {
			babilim_test_reset();
			update_option( 'babilim_consent', true );
			$this->seed_warning( $reason );
			$html = $this->settings_html();
			$this->assertStringContainsString( 'notice-warning', $html, $reason );
			foreach ( $needles as $needle ) {
				$this->assertStringContainsString( $needle, $html, "$reason advice must mention $needle" );
			}
			$seen[ $reason ] = $html;
		}
		// The advice differs per reason: each page holds its own hint and
		// not the other reasons' hints.
		$this->assertStringNotContainsString( '/wp-json/babilim/v1/challenge', $seen['callback_timeout'] );
		$this->assertStringNotContainsString( 'try again later', $seen['callback_invalid'] );
		$this->assertStringNotContainsString( 'slow', $seen['callback_invalid'] );
	}

	public function test_an_unknown_reason_shows_generic_advice_not_a_reason_specific_one(): void {
		$this->seed_warning( 'unknown' );
		$html = $this->settings_html();
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'could not verify this site', $html );
		$this->assertStringNotContainsString( 'try again later', $html );
		$this->assertStringNotContainsString( 'cache', $html );
	}

	public function test_no_warning_is_shown_when_the_site_is_healthy_or_consent_is_off(): void {
		$this->assertStringNotContainsString( 'notice-warning', $this->settings_html() );
		$this->assertStringNotContainsString( 'babilim_reverify', $this->settings_html() );

		$this->seed_warning( 'callback_unreachable' );
		delete_option( 'babilim_consent' );
		$this->assertStringNotContainsString( 'babilim_reverify', $this->settings_html() );
	}

	public function test_the_reverify_button_is_a_nonce_protected_post_form_and_no_token_is_ever_rendered(): void {
		$this->seed_warning( 'callback_unreachable' );
		update_option( 'babilim_identity', array( 'site_id' => 'st_old', 'token' => 'SECRET_TOKEN_VALUE' ), false );
		$html = $this->settings_html();

		$this->assertStringContainsString( 'name="action" value="babilim_reverify"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( wp_create_nonce( 'babilim_reverify' ), $html );
		$this->assertStringContainsString( 'Re-verify', $html );
		$this->assertStringNotContainsString( 'SECRET_TOKEN_VALUE', $html );
		$this->assertStringNotContainsString( self::NONCE, $html, 'the pending challenge value is never rendered either' );
	}

	public function test_the_warning_markup_survives_the_kses_allow_list(): void {
		if ( ! babilim_test_real_kses_available() ) {
			$this->markTestSkipped( 'The real wp-includes/kses.php is not present under tools/wp-core/ -- run `make tools`.' );
		}
		$this->seed_warning( 'callback_unreachable' );
		$raw      = $this->settings_html();
		$filtered = babilim_real_wp_kses( $raw, Babilim_Admin::allowed_admin_html() );
		foreach ( array( 'notice-warning', 'name="action" value="babilim_reverify"', 'name="_wpnonce"', 'Re-verify' ) as $needle ) {
			$this->assertStringContainsString( $needle, $filtered );
		}
	}

	public function test_every_layout_class_the_warning_uses_is_defined_in_the_enqueued_css(): void {
		$this->seed_warning( 'callback_unreachable' );
		$html  = $this->settings_html();
		$admin = $this->admin();
		$admin->register_menu();
		$admin->enqueue_admin_assets( 'toplevel_page_' . Babilim_Admin::MENU_SLUG );
		$css = implode( '', $GLOBALS['babilim_test_inline_styles']['babilim-admin'] ?? array() );

		$this->assertSame( 1, preg_match( '/<form[^>]*class="([^"]*babilim-[^"]*)"[^>]*>\s*<input type="hidden" name="action" value="babilim_reverify"/', $html, $m ), 'the re-verify form carries a layout class' );
		foreach ( explode( ' ', $m[1] ) as $class ) {
			if ( 0 === strpos( $class, 'babilim-' ) ) {
				$this->assertStringContainsString( '.' . $class . '{', $css, "$class must be defined by the enqueued stylesheet" );
			}
		}
	}

	// -- admin notice on other screens --------------------------------------

	public function test_the_admin_notice_shows_on_other_screens_for_an_administrator_only(): void {
		$this->seed_warning( 'callback_timeout' );
		$admin = $this->admin();
		$admin->register_menu();
		$GLOBALS['hook_suffix'] = 'plugins.php';

		$html = $this->notice_html( $admin );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'could not verify this site', $html );
		$this->assertStringContainsString( 'page=babilim-settings', $html );

		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->assertSame( '', $this->notice_html( $admin ), 'a user without the capability sees nothing' );
	}

	public function test_the_admin_notice_shows_only_on_the_dashboard_and_plugins_screens(): void {
		$this->seed_warning( 'callback_timeout' );
		$admin = $this->admin();
		$admin->register_menu();

		foreach ( array( 'index.php', 'plugins.php' ) as $screen ) {
			$GLOBALS['hook_suffix'] = $screen;
			$this->assertStringContainsString( 'notice-warning', $this->notice_html( $admin ), $screen );
		}
		foreach ( array( 'edit.php', 'upload.php', 'options-general.php', 'themes.php', 'post-new.php', 'tools.php', 'toplevel_page_' . Babilim_Admin::MENU_SLUG, '' ) as $screen ) {
			$GLOBALS['hook_suffix'] = $screen;
			$this->assertSame( '', $this->notice_html( $admin ), "no warning on '{$screen}'" );
		}
	}

	public function test_the_admin_notice_is_not_repeated_on_the_plugins_own_screen_and_needs_a_warning(): void {
		$admin = $this->admin();
		$admin->register_menu();
		$GLOBALS['hook_suffix'] = 'plugins.php';
		$this->assertSame( '', $this->notice_html( $admin ), 'no warning recorded, nothing shown' );

		$this->seed_warning( 'callback_timeout' );
		$GLOBALS['hook_suffix'] = 'toplevel_page_' . Babilim_Admin::MENU_SLUG;
		$this->assertSame( '', $this->notice_html( $admin ), 'the settings screen already shows the warning' );

		delete_option( 'babilim_consent' );
		$GLOBALS['hook_suffix'] = 'plugins.php';
		$this->assertSame( '', $this->notice_html( $admin ), 'no consent, no notice' );
	}

	public function test_init_wires_the_notice_the_action_and_the_setting(): void {
		$admin = $this->admin();
		$admin->init();

		$hooked = static function ( string $tag ): array {
			return array_map(
				static function ( $entry ) {
					return $entry['callback'];
				},
				$GLOBALS['babilim_test_actions'][ $tag ] ?? array()
			);
		};
		$this->assertContains( array( $admin, 'render_verification_admin_notice' ), $hooked( 'admin_notices' ) );
		$this->assertContains( array( $admin, 'render_verification_admin_notice' ), $hooked( 'network_admin_notices' ) );
		$this->assertContains( array( $admin, 'dispatch_babilim_reverify' ), $hooked( 'admin_post_babilim_reverify' ) );
		$this->assertContains( array( $admin, 'register_settings' ), $hooked( 'admin_init' ) );
	}

	// -- the Re-verify action -----------------------------------------------

	public function test_reverify_without_a_nonce_or_capability_dies_and_makes_no_request(): void {
		$this->seed_warning( 'callback_unreachable' );
		$http = new Babilim_Fake_Http( array( $this->verified() ) );

		$_POST    = array( 'action' => 'babilim_reverify' );
		$_REQUEST = $_POST;
		try {
			$this->admin( $http )->dispatch_babilim_reverify();
			$this->fail( 'Expected wp_die() for a missing nonce.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertNotEmpty( $GLOBALS['babilim_test_wp_die_calls'] );
		}

		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->post( 'babilim_reverify' );
		try {
			$this->admin( $http )->dispatch_babilim_reverify();
			$this->fail( 'Expected wp_die() for a missing capability.' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertTrue( true );
		}

		$this->assertCount( 0, $http->requests );
		$this->assertIsArray( get_option( Babilim_Site::OPTION_VERIFICATION_WARNING ), 'the warning is untouched by a refused request' );
	}

	public function test_reverify_that_succeeds_clears_the_warning_stores_the_token_and_says_so(): void {
		$this->seed_warning( 'callback_unreachable' );
		$http = new Babilim_Fake_Http( array( $this->verified( 'tok_after_fix' ) ) );
		$this->post( 'babilim_reverify' );

		$this->admin( $http )->dispatch_babilim_reverify();

		$this->assertCount( 1, $http->requests );
		$this->assertFalse( get_option( Babilim_Site::OPTION_VERIFICATION_WARNING ) );
		$this->assertSame( $this->tok( 'tok_after_fix' ), ( get_option( 'babilim_identity' ) ?: array() )['token'] ?? null );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'success', $errors[0]['type'] );
		$this->assertStringContainsString( 'verified', $errors[0]['message'] );
		$this->assertStringContainsString( 'page=babilim-settings', $GLOBALS['babilim_test_redirects'][0]['location'] );
	}

	public function test_reverify_that_fails_again_keeps_the_warning_stores_no_token_and_reports_it(): void {
		$this->seed_warning( 'callback_unreachable' );
		$http = new Babilim_Fake_Http( array( $this->verification_required( 'callback_timeout' ) ) );
		$this->post( 'babilim_reverify' );

		$this->admin( $http )->dispatch_babilim_reverify();

		$this->assertCount( 1, $http->requests );
		$this->assertFalse( get_option( 'babilim_identity' ) );
		$warning = get_option( Babilim_Site::OPTION_VERIFICATION_WARNING );
		$this->assertSame( 'callback_timeout', $warning['reason'], 'the new reason replaces the old one' );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'error', $errors[0]['type'] );
		$this->assertSame( 'babilim_verification_required', $errors[0]['code'] );
		$this->assertSame( 'st_old', get_option( 'babilim_site_id' ), 'the site id is never deleted for a reason failure' );
	}

	// -- download in the warning state ----------------------------------------

	public function test_a_manual_download_in_the_warning_state_is_refused_with_no_request(): void {
		$this->seed_warning( 'callback_unreachable' );
		update_option( 'babilim_identity', array( 'site_id' => 'st_old', 'token' => 'tok_held' ), false );
		( new Babilim_State() )->set(
			array(
				'components' => array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_aaaaaaaaaaaa' ) ) ) ),
			)
		);
		$http = new Babilim_Fake_Http( array( $this->json( 200, array() ) ) );

		$result = $this->admin( $http )->do_download( 'plugin', 'woocommerce', 'de_DE' );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'verification_required', $result->code() );
		$this->assertCount( 0, $http->requests );
	}

	// -- delete-all setting -----------------------------------------------------

	public function test_the_delete_all_setting_is_registered_with_a_boolean_sanitizer_and_defaults_off(): void {
		$this->admin()->register_settings();

		$this->assertArrayHasKey( 'babilim_delete_data_on_uninstall', $GLOBALS['babilim_test_registered_settings'] );
		$args = $GLOBALS['babilim_test_registered_settings']['babilim_delete_data_on_uninstall'];
		$this->assertSame( 'boolean', $args['type'] );
		$this->assertFalse( $args['default'] );
		$this->assertTrue( is_callable( $args['sanitize_callback'] ) );

		foreach ( array( 'on', '1', 1, true, 'yes' ) as $truthy ) {
			$this->assertTrue( $args['sanitize_callback']( $truthy ), var_export( $truthy, true ) );
		}
		foreach ( array( '', '0', 0, false, null, 'off', array( 'x' ) ) as $falsy ) {
			$this->assertFalse( $args['sanitize_callback']( $falsy ), var_export( $falsy, true ) );
		}
	}

	public function test_the_settings_screen_has_the_delete_all_checkbox_off_by_default_with_the_risk_text(): void {
		$html = $this->settings_html();

		$this->assertStringContainsString( 'name="delete_data_on_uninstall"', $html );
		$this->assertStringContainsString( 'Delete all Babilim data on uninstall', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="delete_data_on_uninstall"[^>]*checked/', $html );
		$this->assertStringContainsString( 'paid license', $html );
		$this->assertStringContainsString( 'cannot be restored automatically', $html );
		$this->assertStringContainsString( 'contact Babilim support at hi@babilim.org to get it back', $html, 'the settings-screen risk text names the support address' );

		update_option( 'babilim_delete_data_on_uninstall', true );
		$this->assertMatchesRegularExpression( '/name="delete_data_on_uninstall"[^>]*checked/', $this->settings_html() );
	}

	public function test_saving_settings_stores_the_delete_all_choice_through_the_sanitizer(): void {
		$admin = $this->admin();
		$admin->register_settings();

		$this->post( 'babilim_save_settings', array( 'delete_data_on_uninstall' => 'on' ) );
		$admin->dispatch_babilim_save_settings();
		$this->assertTrue( get_option( 'babilim_delete_data_on_uninstall' ) );

		$this->post( 'babilim_save_settings', array() );
		$admin->dispatch_babilim_save_settings();
		$this->assertFalse( get_option( 'babilim_delete_data_on_uninstall' ), 'an unticked box stores false' );
	}

	public function test_saving_settings_without_the_capability_never_touches_the_delete_all_choice(): void {
		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->post( 'babilim_save_settings', array( 'delete_data_on_uninstall' => 'on' ) );
		try {
			$this->admin()->dispatch_babilim_save_settings();
			$this->fail( 'Expected wp_die().' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertFalse( get_option( 'babilim_delete_data_on_uninstall' ) );
		}
	}

	// -- Deactivate link confirmation -----------------------------------------------

	private function action_links( Babilim_Admin $admin ): array {
		$deactivate = '<a href="plugins.php?action=deactivate&amp;plugin=babilim%2Fbabilim.php&amp;_wpnonce=abc" id="deactivate-babilim" aria-label="Deactivate Babilim">Deactivate</a>';
		$links      = array( 'settings' => '<a href="x">Settings</a>', 'deactivate' => $deactivate );
		$result     = $admin->confirm_deactivate_link( $links );
		$this->assertIsArray( $result );
		return $result;
	}

	public function test_the_deactivate_link_is_hooked_on_the_plugins_list_filters(): void {
		$this->admin()->init();
		$tag = 'plugin_action_links_' . plugin_basename( BABILIM_FILE );
		$this->assertNotEmpty( $GLOBALS['babilim_test_filters'][ $tag ] ?? array() );
		$this->assertNotEmpty( $GLOBALS['babilim_test_filters'][ 'network_admin_' . $tag ] ?? array() );
	}

	public function test_the_deactivate_link_is_unchanged_while_delete_all_is_off(): void {
		$links = $this->action_links( $this->admin() );

		$this->assertStringNotContainsString( 'confirm(', $links['deactivate'] );
		$this->assertStringStartsWith( '<a href="plugins.php?action=deactivate', $links['deactivate'] );
	}

	public function test_the_deactivate_link_asks_for_confirmation_while_delete_all_is_on(): void {
		update_option( 'babilim_delete_data_on_uninstall', true );
		$links = $this->action_links( $this->admin() );

		$this->assertStringNotContainsString( 'onclick', $links['deactivate'], 'no inline handler' );
		$this->assertStringContainsString( 'data-babilim-confirm="', $links['deactivate'] );
		$this->assertStringContainsString( 'not be restored automatically', $links['deactivate'] );
		$this->assertStringContainsString( 'press Cancel', $links['deactivate'] );
		$this->assertStringContainsString( 'contact Babilim support at hi@babilim.org.', $links['deactivate'] );
		$this->assertStringContainsString( 'href="plugins.php?action=deactivate', $links['deactivate'], 'the link itself still deactivates when confirmed' );
		$this->assertSame( '<a href="x">Settings</a>', $links['settings'], 'other links are untouched' );
	}

	public function test_the_confirmation_text_is_escaped_for_an_html_attribute(): void {
		update_option( 'babilim_delete_data_on_uninstall', true );
		$html = $this->action_links( $this->admin() )['deactivate'];

		$this->assertSame( 1, preg_match( '/data-babilim-confirm="([^"]*)"/', $html, $m ), 'the attribute value has no raw double quote' );
		$this->assertStringContainsString( '&quot;Delete all Babilim data on uninstall&quot;', $m[1] );
		$this->assertStringContainsString( 'support at hi@babilim.org.', $m[1] );
		$this->assertStringContainsString( "this site's identity", html_entity_decode( $m[1], ENT_QUOTES ) );
	}

	/**
	 * Goes through the hook wiring: init() registers the callback, and the
	 * hook fires with what core passes on the site and the network Plugins
	 * screens. The network admin never fires admin_menu.
	 */
	public function test_the_plugins_screens_get_the_confirm_script_through_the_real_hooks(): void {
		$admin = $this->admin();
		$admin->init();

		foreach ( array( false, true ) as $network ) {
			$GLOBALS['babilim_test_multisite']        = $network;
			$GLOBALS['babilim_test_network_admin']    = $network;
			$GLOBALS['babilim_test_current_user_can'] = $network ? array( 'manage_options', 'manage_network_options' ) : array( 'manage_options' );
			$GLOBALS['babilim_test_enqueued_scripts'] = array();
			$GLOBALS['babilim_test_inline_scripts']   = array();

			do_action( 'admin_enqueue_scripts', 'plugins.php' );

			$this->assertArrayHasKey( 'babilim-plugins-list', $GLOBALS['babilim_test_enqueued_scripts'], $network ? 'network admin' : 'site admin' );
			$inline = implode( '', $GLOBALS['babilim_test_inline_scripts']['babilim-plugins-list'] ?? array() );
			$this->assertStringContainsString( 'data-babilim-confirm', $inline );
			$this->assertStringContainsString( 'preventDefault', $inline );
		}

		$GLOBALS['babilim_test_enqueued_scripts'] = array();
		do_action( 'admin_enqueue_scripts', 'edit.php' );
		$this->assertArrayNotHasKey( 'babilim-plugins-list', $GLOBALS['babilim_test_enqueued_scripts'] );

		$GLOBALS['babilim_test_current_user_can'] = false;
		do_action( 'admin_enqueue_scripts', 'plugins.php' );
		$this->assertArrayNotHasKey( 'babilim-plugins-list', $GLOBALS['babilim_test_enqueued_scripts'], 'no capability, no script' );
	}

	public function test_the_deactivate_link_is_left_alone_for_users_without_the_capability(): void {
		update_option( 'babilim_delete_data_on_uninstall', true );
		$GLOBALS['babilim_test_current_user_can'] = false;
		$links                                    = $this->action_links( $this->admin() );

		$this->assertStringNotContainsString( 'confirm(', $links['deactivate'] );
	}

	// -- Deactivate link confirmation on a network -------------------------

	/**
	 * A multisite network of $site_ids, viewed from the network admin
	 * (or, with $network_admin false, from one site's own admin), where
	 * only the sites in $erasing have "Delete all Babilim data on
	 * uninstall" on -- set through each site's own options, the way a
	 * site owner would.
	 *
	 * @param int[] $site_ids
	 * @param int[] $erasing
	 */
	private function network( array $site_ids, array $erasing, bool $network_admin = true ): void {
		$GLOBALS['babilim_test_multisite']     = true;
		$GLOBALS['babilim_test_sites']         = $site_ids;
		$GLOBALS['babilim_test_network_admin'] = $network_admin;
		foreach ( $erasing as $site_id ) {
			switch_to_blog( $site_id );
			update_option( 'babilim_delete_data_on_uninstall', true );
			restore_current_blog();
		}
	}

	private function confirm_text( array $links ): ?string {
		if ( 1 !== preg_match( '/data-babilim-confirm="([^"]*)"/', $links['deactivate'], $m ) ) {
			return null;
		}
		return html_entity_decode( $m[1], ENT_QUOTES );
	}

	public function test_the_network_list_asks_when_only_a_subsite_has_delete_all_on(): void {
		$this->network( array( 1, 2, 3 ), array( 3 ) );
		$this->assertFalse( (bool) get_option( 'babilim_delete_data_on_uninstall', false ), 'the main site itself has it off' );

		$links = $this->action_links( $this->admin() );
		$text  = $this->confirm_text( $links );

		$this->assertNotNull( $text, 'one site erasing is enough to ask' );
		$this->assertStringContainsString( '1 site on this network', $text );
		$this->assertStringContainsString( 'not be restored automatically', $text );
		$this->assertStringContainsString( 'contact Babilim support at hi@babilim.org.', $text );
		$this->assertStringContainsString( 'href="plugins.php?action=deactivate', $links['deactivate'] );
	}

	public function test_the_network_list_counts_every_site_with_delete_all_on(): void {
		$this->network( array( 1, 2, 3, 4 ), array( 1, 2, 4 ) );

		$text = $this->confirm_text( $this->action_links( $this->admin() ) );

		$this->assertNotNull( $text );
		$this->assertStringContainsString( '3 sites on this network', $text );
		$this->assertStringContainsString( 'contact Babilim support at hi@babilim.org.', $text );
	}

	public function test_the_network_list_leaves_the_link_alone_when_no_site_has_delete_all_on(): void {
		$this->network( array( 1, 2, 3 ), array() );

		$links = $this->action_links( $this->admin() );

		$this->assertNull( $this->confirm_text( $links ) );
		$this->assertStringNotContainsString( 'confirm(', $links['deactivate'] );
		$this->assertStringStartsWith( '<a href="plugins.php?action=deactivate', $links['deactivate'] );
	}

	public function test_the_network_list_asks_generically_once_the_scan_limit_is_reached(): void {
		$this->network( range( 1, 1000 ), array() );
		$text = $this->confirm_text( $this->action_links( $this->admin() ) );
		$this->assertNotNull( $text, 'a full page of sites is never read to the end, so the question is asked' );
		$this->assertStringContainsString( 'too many sites', $text );
		$this->assertStringContainsString( 'may be set to erase', $text );
		$this->assertStringContainsString( 'contact Babilim support at hi@babilim.org.', $text );
		$this->assertSame( 0, preg_match( '/\d+ sites? on this network/', $text ), 'the generic text carries no number' );

		babilim_test_reset();
		$this->network( range( 1, 1500 ), array( 2 ) );
		$this->assertStringContainsString( 'too many sites', (string) $this->confirm_text( $this->action_links( $this->admin() ) ) );
	}

	public function test_the_network_list_still_counts_just_below_the_scan_limit(): void {
		$this->network( range( 1, 999 ), array( 999 ) );

		$text = $this->confirm_text( $this->action_links( $this->admin() ) );

		$this->assertNotNull( $text );
		$this->assertStringContainsString( '1 site on this network', $text );
	}

	public function test_a_single_sites_own_plugins_list_keeps_looking_only_at_that_site(): void {
		$this->network( array( 1, 2, 3 ), array( 2, 3 ), false );
		$this->assertNull( $this->confirm_text( $this->action_links( $this->admin() ) ), 'other sites having it on does not matter here' );

		switch_to_blog( 1 );
		update_option( 'babilim_delete_data_on_uninstall', true );
		restore_current_blog();
		$text = $this->confirm_text( $this->action_links( $this->admin() ) );
		$this->assertNotNull( $text );
		$this->assertStringContainsString( "this site's identity", html_entity_decode( str_replace( "\\'", "'", $text ), ENT_QUOTES ) );
	}

	public function test_a_single_site_install_is_unchanged_and_never_lists_sites(): void {
		$GLOBALS['babilim_test_sites'] = array( 1, 2 );
		update_option( 'babilim_delete_data_on_uninstall', true );

		$text = $this->confirm_text( $this->action_links( $this->admin() ) );

		$this->assertNotNull( $text );
		$this->assertStringNotContainsString( 'on this network', $text );
	}

	public function test_the_get_blog_option_double_follows_core_for_an_empty_id_and_the_filter(): void {
		$this->network( array( 1, 2, 3 ), array( 2 ) );

		switch_to_blog( 2 );
		$this->assertTrue( (bool) get_blog_option( 0, 'babilim_delete_data_on_uninstall', false ), 'an empty id means the current blog' );
		restore_current_blog();
		$this->assertFalse( (bool) get_blog_option( 0, 'babilim_delete_data_on_uninstall', false ), 'back on the main site the empty id reads the main site' );

		add_filter(
			'blog_option_babilim_delete_data_on_uninstall',
			static function ( $value, $id ) {
				return 3 === $id ? true : $value;
			},
			10,
			2
		);
		$this->assertTrue( (bool) get_blog_option( 3, 'babilim_delete_data_on_uninstall', false ), 'another blog goes through blog_option_{$option}' );
		$this->assertFalse( (bool) get_blog_option( 1, 'babilim_delete_data_on_uninstall', false ), 'the current blog does not, like core' );
	}

	public function test_the_network_question_is_left_alone_for_users_without_the_capability(): void {
		$this->network( array( 1, 2 ), array( 2 ) );
		$GLOBALS['babilim_test_current_user_can'] = false;

		$this->assertNull( $this->confirm_text( $this->action_links( $this->admin() ) ) );
	}

	public function test_a_links_list_without_a_deactivate_link_is_returned_as_is(): void {
		update_option( 'babilim_delete_data_on_uninstall', true );
		$links = array( 'settings' => '<a href="x">Settings</a>' );

		$this->assertSame( $links, $this->admin()->confirm_deactivate_link( $links ) );
	}

	// -- "Sync now" while the site is not verified -----------------------------------

	public function test_sync_now_while_verification_is_pending_does_not_claim_sync_complete(): void {
		$this->seed_warning( 'callback_unreachable' );
		$http = new Babilim_Fake_Http( array() );
		$this->post( 'babilim_sync_now', array() );

		$this->admin( $http )->dispatch_babilim_sync_now();

		$this->assertCount( 0, $http->requests, 'the warning is inside its backoff, so nothing is sent' );
		$errors = get_settings_errors( 'babilim' );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'warning', $errors[0]['type'] );
		$this->assertStringNotContainsString( 'Sync complete', $errors[0]['message'] );
		$this->assertStringContainsString( 'could not verify this site', $errors[0]['message'] );
	}
	public function test_a_warning_notice_renders_with_the_warning_class_and_no_other_type_does(): void {
		$this->seed_warning( 'callback_unreachable' );
		$this->post( 'babilim_sync_now', array() );
		$this->admin()->dispatch_babilim_sync_now();
		$_GET = array();
		$html = $this->admin()->render_current_page();

		$this->assertSame( 1, preg_match( '#<div class="notice ([^"]*)"><p>Babilim could not verify this site, so nothing is downloaded or installed\.</p></div>#', $html, $m ), 'the sync-now notice is on the page' );
		$this->assertSame( 'notice-warning', $m[1] );
	}

	public function test_notice_types_map_to_their_own_classes(): void {
		foreach ( array( 'error' => 'notice-error', 'warning' => 'notice-warning', 'info' => 'notice-info', 'success' => 'notice-success' ) as $type => $class ) {
			babilim_test_reset();
			update_option( 'babilim_consent', true );
			add_settings_error( 'babilim', 'code_' . $type, 'Message ' . $type, $type );
			$_GET = array();
			$html = $this->admin()->render_current_page();
			$this->assertStringContainsString( '<div class="notice ' . $class . '"><p>Message ' . $type . '</p></div>', $html, $type );
		}
	}

	public function test_the_js_escape_double_keeps_existing_entities_like_core(): void {
		$this->assertSame( 'a &amp; b', esc_js( 'a &amp; b' ), 'an existing entity is not encoded again' );
		$this->assertSame( 'a &amp; b', esc_js( 'a & b' ) );
		$this->assertSame( '&quot;x&quot;', esc_js( '"x"' ) );
		$this->assertSame( "it\\'s", esc_js( "it's" ) );
		$this->assertSame( "it\\'s", esc_js( 'it&#039;s' ), 'core turns a numeric single-quote entity back into a quote before slashing' );
		$this->assertSame( 'a\\nb', esc_js( "a\r\nb" ) );
	}
}
