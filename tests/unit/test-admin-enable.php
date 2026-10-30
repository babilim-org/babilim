<?php
/**
 * Tests for the one-click enable flow: the menu, the welcome block, the
 * enable / disable / start-over actions, and the screens derived from
 * the stored state alone.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-kses.php';

final class Test_Admin_Enable extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['hook_suffix'] );
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
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

	private function registered(): array {
		return $this->json( 201, array( 'site_id' => 'st_new', 'challenge' => array( 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a', 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 900 ) ) ) );
	}

	private function verified(): array {
		return $this->json( 200, array( 'site_token' => 'tok_fresh', 'verified' => true, 'license' => array( 'kind' => 'free', 'status' => 'active' ), 'quota' => array() ) );
	}

	private function inventory_ok(): array {
		return $this->json(
			200,
			array(
				'components' => array( array( 'kind' => 'plugin', 'slug' => 'acme', 'locales' => array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000001' ) ) ) ),
				'updates'    => array(),
				'license'    => array(),
				'quota'      => array(),
				'features'   => array(),
				'contract'   => '1.3.0',
			)
		);
	}

	private function one_plugin(): void {
		$GLOBALS['babilim_test_site_locale']        = 'de_DE';
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0', 'TextDomain' => 'acme' ),
		);
	}

	private function notice_messages(): array {
		return array_map(
			static function ( $e ) {
				return $e['message'];
			},
			get_settings_errors( 'babilim' )
		);
	}

	private function seed_identity(): void {
		update_option( 'babilim_identity', array( 'site_id' => 'st_x', 'token' => 'tok_x' ), false );
	}

	// -- menu, page_url, assets -------------------------------------------

	public function test_register_menu_adds_translations_and_settings_submenus(): void {
		$admin = $this->admin();
		$admin->register_menu();

		$this->assertSame( 'babilim-settings', Babilim_Admin::SETTINGS_SLUG );
		$subs = $GLOBALS['babilim_test_submenu_pages'];
		$this->assertCount( 2, $subs );
		$this->assertSame( array( 'babilim', 'Translations', 'manage_options', 'babilim' ), array( $subs[0]['parent_slug'], $subs[0]['page_title'], $subs[0]['capability'], $subs[0]['menu_slug'] ) );
		$this->assertSame( array( 'babilim', 'Babilim Settings', 'Settings', 'manage_options', 'babilim-settings' ), array( $subs[1]['parent_slug'], $subs[1]['page_title'], $subs[1]['menu_title'], $subs[1]['capability'], $subs[1]['menu_slug'] ) );
	}

	public function test_page_url_maps_screens_and_legacy_values(): void {
		$m = new ReflectionMethod( Babilim_Admin::class, 'page_url' );
		$m->setAccessible( true );
		$url = static function ( ...$args ) use ( $m ) {
			return $m->invoke( null, ...$args );
		};

		$this->assertSame( admin_url( 'admin.php?page=babilim' ), $url( 'translations' ) );
		foreach ( array( '', 'overview', 'detail' ) as $legacy ) {
			$this->assertSame( admin_url( 'admin.php?page=babilim' ), $url( $legacy ), $legacy );
		}
		$this->assertSame( admin_url( 'admin.php?page=babilim&screen=report' ), $url( 'report' ) );
		$this->assertSame( admin_url( 'admin.php?page=babilim&screen=confirm' ), $url( 'confirm' ) );
		$this->assertSame( admin_url( 'admin.php?page=babilim-settings' ), $url( 'settings' ) );
		$this->assertSame( admin_url( 'admin.php?page=babilim-settings&tab=x' ), $url( 'settings', array( 'tab' => 'x' ) ) );
	}

	public function test_the_script_loads_only_on_the_two_plugin_pages(): void {
		$admin = $this->admin();
		$admin->register_menu();

		$admin->enqueue_admin_assets( 'edit.php' );
		$this->assertSame( array(), $GLOBALS['babilim_test_enqueued_scripts'] );

		$admin->enqueue_admin_assets( 'toplevel_page_babilim' );
		$this->assertArrayHasKey( 'babilim-admin', $GLOBALS['babilim_test_enqueued_scripts'] );
		$this->assertSame( BABILIM_VERSION, $GLOBALS['babilim_test_registered_scripts']['babilim-admin']['ver'] );
		$this->assertSame( 'admin/js/babilim-admin.js', substr( $GLOBALS['babilim_test_registered_scripts']['babilim-admin']['src'], -strlen( 'admin/js/babilim-admin.js' ) ) );

		$GLOBALS['babilim_test_enqueued_scripts'] = array();
		$admin->enqueue_admin_assets( 'babilim_page_babilim-settings' );
		$this->assertArrayHasKey( 'babilim-admin', $GLOBALS['babilim_test_enqueued_scripts'] );
	}

	public function test_the_plugins_row_notice_is_gone_but_the_deactivate_confirm_stays(): void {
		$admin = $this->admin();
		$admin->init();

		$this->assertArrayNotHasKey( 'after_plugin_row_' . plugin_basename( BABILIM_FILE ), $GLOBALS['babilim_test_actions'] );
		$this->assertSame( array(), get_class_methods( $admin ) === array() ? array( 1 ) : array_intersect( array( 'render_plugin_row_notice' ), get_class_methods( $admin ) ) );
		$this->assertArrayHasKey( 'plugin_action_links_' . plugin_basename( BABILIM_FILE ), $GLOBALS['babilim_test_filters'] );
	}

	public function test_init_registers_the_three_new_actions(): void {
		$admin = $this->admin();
		$admin->init();
		foreach ( array( 'enable', 'disable', 'sync_restart' ) as $name ) {
			$this->assertArrayHasKey( 'admin_post_babilim_' . $name, $GLOBALS['babilim_test_actions'] );
		}
	}

	public function test_the_admin_script_ships_and_follows_the_spec(): void {
		$path = dirname( __DIR__, 2 ) . '/admin/js/babilim-admin.js';
		$this->assertFileExists( $path );
		$js = (string) file_get_contents( $path );

		$this->assertStringContainsString( "document.addEventListener( 'submit'", $js );
		$this->assertMatchesRegularExpression( '/,\s*true\s*\);/', $js, 'registered in the capture phase' );
		$this->assertStringContainsString( 'event.submitter', $js );
		$this->assertStringContainsString( "querySelector( 'button[type=submit]' )", $js );
		foreach ( array( 'data-babilim-confirm', 'data-babilim-confirmed', 'data-babilim-busy-label', 'spinner is-active', 'setTimeout' ) as $needle ) {
			$this->assertStringContainsString( $needle, $js, $needle );
		}
		$this->assertDoesNotMatchRegularExpression( '/jQuery|\$\(/', $js, 'no jQuery' );
		$this->assertDoesNotMatchRegularExpression( '/site_token|token/i', $js, 'the site token never reaches the browser' );
	}

	// -- welcome block -------------------------------------------------------

	public function test_the_welcome_block_is_shown_before_consent_and_sends_nothing(): void {
		$http = new Babilim_Fake_Http( array() );

		$html = $this->admin( $http )->render_current_page();

		$this->assertCount( 0, $http->requests );
		$this->assertStringStartsWith( '<div class="wrap"><h1>Babilim</h1>', $html );
		$this->assertStringContainsString( '<details><summary>What is sent when you enable Babilim</summary>', $html );
		$this->assertStringContainsString( '<form method="post" action="', $html );
		$this->assertStringContainsString( 'name="action" value="babilim_enable"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce-babilim_enable"', $html );
		$this->assertStringContainsString( '<button type="submit" class="button button-primary" data-babilim-busy-label="Enabling…">Enable Babilim</button>', $html );
		$this->assertStringContainsString( '<p class="description">By clicking Enable, you agree to send the information listed above to Babilim.</p></form>', $html );
		$this->assertStringContainsString( 'home_url', $html, 'the payload preview is inside the details block' );
	}

	public function test_the_welcome_block_explains_languages_automatic_installs_and_the_free_plan(): void {
		$GLOBALS['babilim_test_site_locale']         = 'de_DE';
		$GLOBALS['babilim_test_available_languages'] = array( 'fr_FR' );
		$http                                        = new Babilim_Fake_Http( array() );

		$html = $this->admin( $http )->render_current_page();

		$this->assertCount( 0, $http->requests );
		$this->assertStringContainsString( '<p>Languages found on this site: de_DE and fr_FR.</p>', $html );
		$this->assertStringContainsString( '<p>Available translations can be installed with one click, and missing ones can be requested. After that, new translations and updates are installed automatically (on the free plan, only for plugins and themes that already use a free slot).</p>', $html );
		$this->assertStringNotContainsString( 'prepared in the background', $html );
		$this->assertStringContainsString( '<p>The free plan covers a limited number of plugins and themes; your exact quota is shown in Settings after you enable Babilim.</p>', $html );
		$this->assertLessThan( strpos( $html, '<details>' ), strpos( $html, 'Languages found on this site' ), 'the explanation comes before the payload details' );
	}

	public function test_the_welcome_block_omits_the_languages_sentence_when_none_are_detected(): void {
		$GLOBALS['babilim_test_site_locale'] = '';

		$html = $this->admin()->render_current_page();

		$this->assertStringNotContainsString( 'Languages found on this site', $html );
		$this->assertStringContainsString( 'Available translations can be installed with one click, and missing ones can be requested.', $html );
		$this->assertStringContainsString( 'The free plan covers a limited number', $html );
	}

	public function test_the_welcome_block_escapes_language_names(): void {
		$GLOBALS['babilim_test_site_locale']         = 'de_DE';
		$GLOBALS['babilim_test_available_languages'] = array( 'fr_FR' );
		update_option( Babilim_Locale_Label::OPTION_NAMES, array( 'de_DE' => 'Evil <b>Name</b>', 'fr_FR' => 'R&D' ), false );

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( '<p>Languages found on this site: Evil &lt;b&gt;Name&lt;/b&gt; and R&amp;D.</p>', $html );
		$this->assertStringNotContainsString( 'Evil <b>', $html );
	}

	public function test_the_welcome_block_survives_the_real_kses(): void {
		$html = wp_kses( $this->admin()->render_current_page(), Babilim_Admin::allowed_admin_html() );

		$this->assertStringContainsString( '<details>', $html );
		$this->assertStringContainsString( 'data-babilim-busy-label="Enabling…"', $html );
		$this->assertStringContainsString( 'name="action" value="babilim_enable"', $html );
	}

	public function test_allowed_html_has_the_button_data_attributes_details_and_summary(): void {
		$allowed = Babilim_Admin::allowed_admin_html();
		foreach ( array( 'form', 'data-babilim-confirm', 'data-babilim-confirmed', 'data-babilim-busy-label' ) as $attr ) {
			$this->assertArrayHasKey( $attr, $allowed['button'] + array( 'form' => true ), $attr );
		}
		$this->assertArrayHasKey( 'details', $allowed );
		$this->assertArrayHasKey( 'summary', $allowed );
	}

	public function test_the_settings_screen_has_no_consent_checkbox(): void {
		$_GET = array( 'screen' => 'settings' );
		$html = $this->admin()->render_current_page();

		$this->assertStringNotContainsString( 'name="consent"', $html );
		$this->assertStringNotContainsString( 'I agree to send this information to Babilim.', $html );
	}

	public function test_settings_before_consent_makes_no_request_for_the_locale_list(): void {
		$http = new Babilim_Fake_Http( array() );
		$_GET = array( 'screen' => 'settings' );

		$this->admin( $http )->render_current_page();

		$this->assertCount( 0, $http->requests );
	}

	// -- enable --------------------------------------------------------------

	public function test_enable_without_a_nonce_dies_and_changes_nothing(): void {
		$_POST    = array( 'action' => 'babilim_enable' );
		$_REQUEST = $_POST;
		$http     = new Babilim_Fake_Http( array() );
		try {
			$this->admin( $http )->dispatch_babilim_enable();
			$this->fail( 'expected wp_die' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertFalse( get_option( 'babilim_consent', false ) );
		}
		$this->assertCount( 0, $http->requests );
	}

	public function test_enable_without_the_capability_is_forbidden_with_zero_requests(): void {
		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->post( 'babilim_enable' );
		$http = new Babilim_Fake_Http( array() );
		try {
			$this->admin( $http )->dispatch_babilim_enable();
			$this->fail( 'expected wp_die' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertSame( 403, $GLOBALS['babilim_test_wp_die_calls'][0]['args']['response'] );
		}
		$this->assertCount( 0, $http->requests );
		$this->assertFalse( get_option( 'babilim_consent', false ) );
	}

	public function test_enable_succeeds_and_reports_the_component_count(): void {
		$this->one_plugin();
		$http = new Babilim_Fake_Http( array( $this->registered(), $this->verified(), $this->inventory_ok() ) );
		$this->post( 'babilim_enable' );

		$this->admin( $http )->dispatch_babilim_enable();

		$this->assertTrue( (bool) get_option( 'babilim_consent' ) );
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'success', $errors[0]['type'] );
		$this->assertSame( 'Babilim is enabled: this site is verified and 1 component was found.', $errors[0]['message'] );
		$this->assertSame( admin_url( 'admin.php?page=babilim' ), $GLOBALS['babilim_test_redirects'][0]['location'] );
		$this->assertCount( 1, $this->sync_events(), 'enable queues only the early full check' );
		$this->assertCount( 0, array_filter( $GLOBALS['babilim_test_single_events_scheduled'], static function ( $e ) { return Babilim::SYNC_ONCE_HOOK === $e['hook']; } ), 'no follow-up when the check finished' );
	}

	private function sync_events(): array {
		return array_values(
			array_filter(
				$GLOBALS['babilim_test_single_events_scheduled'],
				static function ( $e ) {
					return Babilim::SYNC_HOOK === $e['hook'];
				}
			)
		);
	}

	public function test_enable_schedules_one_early_full_sync_when_auto_install_is_on(): void {
		$this->one_plugin();
		$recurring = time() + 3600;
		$GLOBALS['babilim_test_cron_scheduled'][ Babilim::SYNC_HOOK ] = $recurring;
		$http = new Babilim_Fake_Http( array( $this->registered(), $this->verified(), $this->inventory_ok() ) );
		$this->post( 'babilim_enable' );
		$before = time();

		$this->admin( $http )->dispatch_babilim_enable();

		$events = $this->sync_events();
		$this->assertCount( 1, $events );
		$this->assertGreaterThanOrEqual( $before + 60, $events[0]['timestamp'] );
		$this->assertLessThanOrEqual( time() + 60, $events[0]['timestamp'] );
		$this->assertSame( $recurring, $GLOBALS['babilim_test_cron_scheduled'][ Babilim::SYNC_HOOK ], 'the recurring schedule is untouched' );
		$this->assertSame( array(), $GLOBALS['babilim_test_cron_cleared'] );
	}

	public function test_enable_does_not_schedule_a_second_early_sync(): void {
		$this->one_plugin();
		$GLOBALS['babilim_test_cron_scheduled'][ Babilim::SYNC_HOOK ] = time() + 3600;
		$GLOBALS['babilim_test_single_events_scheduled'][]            = array(
			'timestamp' => time() + 30,
			'hook'      => Babilim::SYNC_HOOK,
			'args'      => array(),
		);
		$http = new Babilim_Fake_Http( array( $this->registered(), $this->verified(), $this->inventory_ok() ) );
		$this->post( 'babilim_enable' );

		$this->admin( $http )->dispatch_babilim_enable();

		$this->assertCount( 1, $this->sync_events(), 'only the one that was already pending' );
	}

	public function test_enable_schedules_no_early_sync_when_auto_install_is_off(): void {
		$this->one_plugin();
		update_option( 'babilim_auto_install', 0 );
		$http = new Babilim_Fake_Http( array( $this->registered(), $this->verified(), $this->inventory_ok() ) );
		$this->post( 'babilim_enable' );

		$this->admin( $http )->dispatch_babilim_enable();

		$this->assertCount( 0, $this->sync_events() );
	}

	public function test_enable_schedules_no_early_sync_on_multisite(): void {
		$this->one_plugin();
		$GLOBALS['babilim_test_multisite'] = true;
		$http = new Babilim_Fake_Http( array( $this->registered(), $this->verified(), $this->inventory_ok() ) );
		$this->post( 'babilim_enable' );

		$this->admin( $http )->dispatch_babilim_enable();

		$this->assertCount( 0, $this->sync_events() );
	}

	public function test_enable_that_runs_out_of_time_schedules_exactly_one_follow_up(): void {
		$this->one_plugin();
		$http = new Babilim_Fake_Http( array( new WP_Error( 'http_request_failed', 'cURL error 28' ) ) );
		$this->post( 'babilim_enable' );

		$this->admin( $http )->dispatch_babilim_enable();

		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'info', $errors[0]['type'] );
		$this->assertSame( 'Babilim is enabling and checking this site… Reload this page or wait for the scheduled check to finish.', $errors[0]['message'] );
		$events = array_values(
			array_filter(
				$GLOBALS['babilim_test_single_events_scheduled'],
				static function ( $e ) {
					return Babilim::SYNC_ONCE_HOOK === $e['hook'];
				}
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 1, $GLOBALS['babilim_test_spawn_cron_calls'] );
	}

	public function test_enable_with_an_explicit_refusal_shows_an_error_and_schedules_nothing(): void {
		$this->one_plugin();
		$http = new Babilim_Fake_Http( array( $this->json( 403, array( 'error' => array( 'code' => 'forbidden', 'message' => 'Not allowed.', 'hint' => '' ) ) ) ) );
		$this->post( 'babilim_enable' );

		$this->admin( $http )->dispatch_babilim_enable();

		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'error', $errors[0]['type'] );
		$this->assertStringContainsString( 'Not allowed.', $errors[0]['message'] );
		$this->assertSame( array(), $GLOBALS['babilim_test_single_events_scheduled'] );
	}

	public function test_enable_with_verification_required_names_the_single_request_host_limit(): void {
		$this->one_plugin();
		$http = new Babilim_Fake_Http(
			array(
				$this->registered(),
				$this->json( 403, array( 'error' => array( 'code' => 'verification_required', 'message' => 'callback failed', 'reason' => 'callback_timeout' ) ) ),
			)
		);
		$this->post( 'babilim_enable' );

		$this->admin( $http )->dispatch_babilim_enable();

		$message = $this->notice_messages()[0];
		$this->assertStringContainsString( 'A host that serves only one PHP request at a time cannot complete this step.', $message );
	}

	// -- translations screen derived from stored state --------------------------

	public function test_a_consented_site_without_a_first_check_resumes_with_the_resume_budget(): void {
		$this->one_plugin();
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http( array( $this->registered(), $this->verified(), $this->inventory_ok() ) );

		$html = $this->admin( $http )->render_current_page();

		$this->assertCount( 3, $http->requests );
		$this->assertStringContainsString( 'acme', $html );
		$this->assertStringNotContainsString( 'Babilim is enabling', $html );
	}

	public function test_a_site_with_no_components_does_not_sync_on_every_page_load(): void {
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		$http = new Babilim_Fake_Http( array( $this->inventory_ok() ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array();

		$this->admin( $http )->render_current_page();
		$first = count( $http->requests );
		$this->admin( $http )->render_current_page();

		$this->assertSame( $first, count( $http->requests ), 'the second load sends nothing' );
	}

	public function test_an_in_progress_state_shows_the_info_notice_and_check_again(): void {
		$this->one_plugin();
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http( array( new WP_Error( 'http_request_failed', 'cURL error 28' ) ) );

		$html = $this->admin( $http )->render_current_page();

		$this->assertStringContainsString( '<div class="notice notice-info">', $html );
		$this->assertStringContainsString( 'Babilim is enabling', $html );
		$this->assertStringContainsString( 'last attempt', $html );
		$this->assertStringContainsString( 'Check again', $html );
	}

	public function test_too_many_components_shows_an_error_with_retry_and_start_over_clears_it(): void {
		update_option( 'babilim_consent', true );
		update_option(
			'babilim_sync_last_error',
			array( 'code' => 'too_many_components', 'message' => 'This site has too many.', 'since' => '2026-10-01T00:00:00+00:00', 'at' => '2026-10-01T00:00:00+00:00' ),
			false
		);
		$http = new Babilim_Fake_Http( array() );

		$html = $this->admin( $http )->render_current_page();

		$this->assertCount( 0, $http->requests, 'a recorded error does not trigger another run on load' );
		$this->assertStringContainsString( '<div class="notice notice-error"><p>This site has too many.</p>', $html );
		$this->assertStringContainsString( 'value="babilim_sync_restart"', $html );
	}

	public function test_sync_restart_clears_the_error_record_and_syncs(): void {
		$this->one_plugin();
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		update_option( 'babilim_sync_last_error', array( 'code' => 'in_progress', 'message' => 'm', 'since' => 'a', 'at' => 'a' ), false );
		$http = new Babilim_Fake_Http( array( $this->inventory_ok() ) );
		$this->post( 'babilim_sync_restart' );

		$this->admin( $http )->dispatch_babilim_sync_restart();

		$this->assertFalse( get_option( 'babilim_sync_last_error', false ) );
		$this->assertCount( 1, $http->requests );
	}

	public function test_sync_restart_needs_a_nonce_and_the_capability(): void {
		$_POST    = array( 'action' => 'babilim_sync_restart' );
		$_REQUEST = $_POST;
		$this->expectException( Babilim_Test_Wp_Die_Exception::class );
		$this->admin()->dispatch_babilim_sync_restart();
	}

	// -- disable ----------------------------------------------------------------

	public function test_disable_withdraws_consent_clears_the_schedule_and_keeps_files(): void {
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		update_option( 'babilim_sync_last_error', array( 'code' => 'in_progress', 'message' => 'm', 'since' => 'a', 'at' => 'a' ), false );
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::root() . '/plugins/acme-de_DE.mo', 'x' );
		$this->post( 'babilim_disable' );

		( new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) ) )->dispatch_babilim_disable();

		$this->assertFalse( (bool) get_option( 'babilim_consent' ) );
		$this->assertContains( Babilim::SYNC_ONCE_HOOK, $GLOBALS['babilim_test_cron_cleared'] );
		$this->assertFalse( get_option( 'babilim_sync_last_error', false ) );
		$this->assertSame( 'x', $fs->get( Babilim_Paths::root() . '/plugins/acme-de_DE.mo' ), 'installed files stay' );
	}

	public function test_disable_without_a_nonce_changes_nothing(): void {
		update_option( 'babilim_consent', true );
		$_POST    = array( 'action' => 'babilim_disable' );
		$_REQUEST = $_POST;
		try {
			$this->admin()->dispatch_babilim_disable();
			$this->fail( 'expected wp_die' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertTrue( (bool) get_option( 'babilim_consent' ) );
		}
	}

	public function test_after_disable_a_background_sync_and_the_once_hook_send_nothing(): void {
		$this->one_plugin();
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		$http = new Babilim_Fake_Http( array( $this->inventory_ok() ) );
		$this->post( 'babilim_disable' );
		$this->admin( $http )->dispatch_babilim_disable();

		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( false ) );
		$site = new Babilim_Site(
			$http,
			'https://api.babilim.org',
			new Babilim_State(),
			new Babilim_Inventory( static function () { return array(); }, static function () { return array(); }, static function () { return false; }, static function () { return array(); } ),
			new Babilim_Manifest( new Babilim_Memory_Fs() )
		);
		$result = $site->sync( Babilim_Site::CRON_BUDGET_SECONDS );

		$this->assertSame( 'no_consent', $result->code() );
		$this->assertCount( 0, $http->requests );
	}

	public function test_the_once_hook_runs_only_a_sync_and_downloads_nothing(): void {
		$this->one_plugin();
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		$GLOBALS['babilim_test_wp_remote_responses'] = array( $this->inventory_ok() );
		Babilim::reset_for_tests();

		Babilim::instance()->run_sync_once();

		$urls = array_column( $GLOBALS['babilim_test_wp_remote_requests'], 'url' );
		$this->assertCount( 1, $urls, 'one inventory request' );
		$this->assertStringContainsString( '/inventory', $urls[0] );
	}

	// -- save settings / sync now ------------------------------------------------

	public function test_saving_settings_does_not_touch_consent(): void {
		update_option( 'babilim_consent', true );
		$this->post( 'babilim_save_settings', array( 'consent' => '' ) );

		$this->admin()->dispatch_babilim_save_settings();

		$this->assertTrue( (bool) get_option( 'babilim_consent' ), 'a missing consent field no longer withdraws consent' );

		update_option( 'babilim_consent', false );
		$this->post( 'babilim_save_settings', array( 'consent' => 'on' ) );
		$this->admin()->dispatch_babilim_save_settings();
		$this->assertFalse( (bool) get_option( 'babilim_consent' ), 'and a consent field does not grant it' );
	}

	public function test_sync_now_reports_the_real_outcome(): void {
		$this->one_plugin();
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		$http = new Babilim_Fake_Http( array( new WP_Error( 'http_request_failed', 'cURL error 28' ) ) );
		$this->post( 'babilim_sync_now' );

		$this->admin( $http )->dispatch_babilim_sync_now();

		$errors = get_settings_errors( 'babilim' );
		$this->assertNotSame( 'Sync complete.', $errors[0]['message'], 'a sync that did not finish is not reported as complete' );
		$this->assertNotSame( 'success', $errors[0]['type'] );
		$this->assertSame( admin_url( 'admin.php?page=babilim' ), $GLOBALS['babilim_test_redirects'][0]['location'] );
	}

	public function test_sync_now_that_runs_out_of_time_schedules_one_follow_up(): void {
		$this->one_plugin();
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		$http = new Babilim_Fake_Http( array( new WP_Error( 'http_request_failed', 'cURL error 28' ) ) );
		$this->post( 'babilim_sync_now' );

		$this->admin( $http )->dispatch_babilim_sync_now();

		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'info', $errors[0]['type'] );
		$events = array_filter(
			$GLOBALS['babilim_test_single_events_scheduled'],
			static function ( $e ) {
				return Babilim::SYNC_ONCE_HOOK === $e['hook'];
			}
		);
		$this->assertCount( 1, $events, 'the follow-up the notice promises is really scheduled' );
		$this->assertSame( 1, $GLOBALS['babilim_test_spawn_cron_calls'] );
	}

	public function test_enable_reports_one_component_in_the_singular(): void {
		$this->one_plugin();
		$http = new Babilim_Fake_Http( array( $this->registered(), $this->verified(), $this->inventory_ok() ) );
		$this->post( 'babilim_enable' );

		$this->admin( $http )->dispatch_babilim_enable();

		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'Babilim is enabled: this site is verified and 1 component was found.', $errors[0]['message'] );
	}

	public function test_the_too_many_components_message_names_the_button_the_screen_shows(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_sync_last_error', array( 'code' => 'too_many_components', 'message' => 'm', 'since' => 'a', 'at' => 'a' ), false );
		$_GET = array();

		$html = $this->admin()->render_current_page();

		$this->assertStringContainsString( '>Check again now<', $html );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-babilim-site.php' );
		$this->assertStringContainsString( 'then press Check again now.', $source );
	}

	public function test_the_verification_notice_is_hidden_on_both_plugin_pages(): void {
		update_option( 'babilim_consent', true );
		update_option( Babilim_Site::OPTION_VERIFICATION_WARNING, array( 'reason' => 'callback_timeout', 'since' => time(), 'retries' => 1, 'next_retry_at' => time() + 3600 ) );
		$admin = $this->admin();
		$admin->register_menu();

		foreach ( array( 'toplevel_page_babilim', 'babilim_page_babilim-settings' ) as $suffix ) {
			$GLOBALS['hook_suffix'] = $suffix;
			ob_start();
			$admin->render_verification_admin_notice();
			$this->assertSame( '', (string) ob_get_clean(), $suffix );
		}
	}

	// -- download ------------------------------------------------------------------

	public function test_do_download_treats_already_installed_as_ok(): void {
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array( array( 'kind' => 'plugin', 'slug' => 'acme', 'locales' => array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000001' ) ) ) ),
			)
		);
		$mo  = Babilim_Mo_Writer::write( array( 'Save' => 'Speichern' ), array() );
		$po  = "# fake po\n";
		$man = array(
			'build_id'        => 'acme-1.0.0-de_DE-20260101T000000-abc123',
			'entries'         => 1,
			'files'           => array(
				'acme-de_DE.mo' => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ),
				'acme-de_DE.po' => array( 'sha256' => hash( 'sha256', $po ), 'size' => strlen( $po ) ),
			),
			'locale'          => 'de_DE',
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => 'acme',
			'version'         => '1.0.0',
			'text_domain'     => 'acme',
			'extra_domains'   => array(),
			'server_build_id' => 'bd_000000000001',
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.1.0',
		);
		$zip = array( 'acme-de_DE.mo' => $mo, 'acme-de_DE.po' => $po, 'manifest.json' => wp_json_encode( $man ) );
		$transform = static function ( string $mo_path ) use ( $fs ) {
			$content = $fs->get( $mo_path );
			$entries = is_string( $content ) ? babilim_test_read_mo( $content ) : array();
			unset( $entries[''] );
			return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
		};
		$parse = static function ( string $path, string $format ) use ( $fs ) {
			$content = $fs->get( $path );
			if ( ! is_string( $content ) ) {
				return false;
			}
			if ( 'php' === $format ) {
				$data = eval( (string) preg_replace( '/^<\?php\s*/', '', $content ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
				return is_array( $data['messages'] ?? null ) ? count( $data['messages'] ) : false;
			}
			$entries = babilim_test_read_mo( $content );
			unset( $entries[''] );
			return count( $entries );
		};
		$installer = new Babilim_Installer( $fs, $manifest, $transform, $parse, static function () use ( $zip ) { return $zip; } );
		$download  = array(
			array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/bd_000000000001' ) ),
			array( 'response' => array( 'code' => 200 ), 'body' => 'zip', 'headers' => array() ),
		);
		$http  = new Babilim_Fake_Http( array_merge( $download, $download, $download ) );
		$admin = new Babilim_Admin( $fs, $http, $installer, $manifest );

		$first = $admin->do_download( 'plugin', 'acme', 'de_DE', 30 );
		$this->assertTrue( $first->is_ok(), $first->message() );
		$this->assertSame( 30, $http->requests[0]['args']['timeout'], 'the timeout is passed through' );

		$second = $admin->do_download( 'plugin', 'acme', 'de_DE' );

		$this->assertTrue( $second->is_ok(), $second->message() );
		$this->assertTrue( (bool) $second->get( 'already_installed' ) );

		$this->post( 'babilim_download', array( 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE' ) );
		$admin->dispatch_babilim_download();
		$errors = get_settings_errors( 'babilim' );
		$this->assertSame( 'success', $errors[0]['type'] );
		$this->assertSame( 'Already installed.', $errors[0]['message'] );
	}

	public function test_a_degraded_record_is_downloaded_even_when_it_carries_a_newer_build_number(): void {
		update_option( 'babilim_consent', true );
		$this->seed_identity();
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		( new Babilim_State() )->set(
			array(
				'components' => array( array( 'kind' => 'plugin', 'slug' => 'acme', 'locales' => array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000001', 'build_no' => 1 ) ) ) ),
			)
		);
		$record = array( 'build_id' => 'bd_000000000002', 'build_no' => 2, 'files' => array( 'plugins/acme-de_DE.mo' => 'x' ), 'integrity' => array( 'ok' => true, 'checked_at' => '2026-01-01T00:00:00Z' ) );
		$manifest->set( 'plugin', 'acme', 'de_DE', $record );
		$http  = new Babilim_Fake_Http( array() );
		$admin = new Babilim_Admin( $fs, $http, null, $manifest );

		$intact = $admin->do_download( 'plugin', 'acme', 'de_DE' );
		$this->assertTrue( (bool) $intact->get( 'already_installed' ), 'control: an intact newer build is not downgraded' );

		$manifest->set( 'plugin', 'acme', 'de_DE', array( 'files' => array(), 'degraded_at' => '2026-01-02T00:00:00Z' ) + $record );
		$degraded = $admin->do_download( 'plugin', 'acme', 'de_DE' );
		$this->assertFalse( (bool) $degraded->get( 'already_installed' ), 'a degraded record installs nothing, so it must not count as already installed' );
	}

	// -- multisite --------------------------------------------------------------------

	public function test_disabling_one_site_leaves_the_other_sites_schedule_alone(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );
		update_option( 'babilim_consent', true );
		wp_schedule_event( 100, 'twicedaily', Babilim::SYNC_HOOK );
		switch_to_blog( 2 );
		update_option( 'babilim_consent', true );
		wp_schedule_event( 200, 'twicedaily', Babilim::SYNC_HOOK );
		restore_current_blog();

		$this->post( 'babilim_disable' );
		$this->admin()->dispatch_babilim_disable();

		switch_to_blog( 2 );
		$this->assertTrue( (bool) get_option( 'babilim_consent' ) );
		$this->assertNotFalse( wp_next_scheduled( Babilim::SYNC_HOOK ) );
		restore_current_blog();
	}
}
