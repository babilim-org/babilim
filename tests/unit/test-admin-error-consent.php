<?php
/**
 * The error-report consent UI: the three actions, the notice, the
 * settings checkbox, and the queue being cleared on disable.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Admin_Error_Consent extends TestCase {

	private const EVENT_ID = 'cccccccccccccccccccccccccccccccc';

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		Babilim::reset_for_tests();
	}

	private function admin(): Babilim_Admin {
		return new Babilim_Admin( new Babilim_Memory_Fs(), new Babilim_Fake_Http( array() ) );
	}

	private function post( string $action, array $fields = array() ): void {
		$fields['action']    = $action;
		$fields['_wpnonce'] = wp_create_nonce( $action );
		$_POST               = $fields;
		$_REQUEST            = $fields;
	}

	private function awaiting( array $over = array() ): void {
		update_option(
			Babilim_Error_Reporter::OPTION_AWAITING,
			array( array_merge( array( 'event_id' => self::EVENT_ID, 'error_code' => 'download_failed', 'action' => 'download', 'origin_id' => 'site.sync', 'at' => '2026-10-01T00:00:00+00:00', 'attempts' => 0 ), $over ) ),
			false
		);
	}

	private function notice_html(): string {
		ob_start();
		$this->admin()->render_error_consent_notice();
		return (string) ob_get_clean();
	}

	public function test_init_registers_the_three_actions_and_the_notice(): void {
		$this->admin()->init();
		foreach ( array( 'babilim_error_share_once', 'babilim_error_share_always', 'babilim_error_decline' ) as $action ) {
			$this->assertArrayHasKey( 'admin_post_' . $action, $GLOBALS['babilim_test_actions'], $action );
		}
		$callbacks = array_column( $GLOBALS['babilim_test_actions']['admin_notices'] ?? array(), 'callback' );
		$this->assertContains( 'render_error_consent_notice', array_map( static fn( $c ) => is_array( $c ) ? $c[1] : '', $callbacks ) );
	}

	public function test_share_once_action_requires_nonce_and_capability(): void {
		$this->awaiting();

		$_POST    = array( 'action' => 'babilim_error_share_once', 'event_id' => self::EVENT_ID );
		$_REQUEST = $_POST;
		try {
			$this->admin()->dispatch_babilim_error_share_once();
			$this->fail( 'expected wp_die' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertCount( 1, get_option( Babilim_Error_Reporter::OPTION_AWAITING ), 'no nonce, nothing moves' );
		}

		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->post( 'babilim_error_share_once', array( 'event_id' => self::EVENT_ID ) );
		try {
			$this->admin()->dispatch_babilim_error_share_once();
			$this->fail( 'expected wp_die' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertCount( 1, get_option( Babilim_Error_Reporter::OPTION_AWAITING ), 'no capability, nothing moves' );
			$this->assertEmpty( get_option( Babilim_Error_Reporter::OPTION_QUEUE, array() ) );
		}
	}

	public function test_share_once_moves_only_that_event_to_the_queue(): void {
		$this->awaiting();
		$this->post( 'babilim_error_share_once', array( 'event_id' => self::EVENT_ID ) );

		$this->admin()->dispatch_babilim_error_share_once();

		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_AWAITING ) );
		$this->assertSame( self::EVENT_ID, get_option( Babilim_Error_Reporter::OPTION_QUEUE )[0]['event_id'] );
		$this->assertNotEmpty( $GLOBALS['babilim_test_redirects'] );
		$this->assertNotSame( '1', (string) get_option( Babilim_Error_Reporter::OPTION_SHARE, '0' ) );
	}

	public function test_share_always_and_decline_actions_need_a_nonce_and_do_their_job(): void {
		$this->awaiting();
		$_POST    = array( 'action' => 'babilim_error_share_always' );
		$_REQUEST = $_POST;
		try {
			$this->admin()->dispatch_babilim_error_share_always();
			$this->fail( 'expected wp_die' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertNotSame( '1', (string) get_option( Babilim_Error_Reporter::OPTION_SHARE, '0' ) );
		}

		$this->post( 'babilim_error_share_always' );
		$this->admin()->dispatch_babilim_error_share_always();
		$this->assertSame( '1', (string) get_option( Babilim_Error_Reporter::OPTION_SHARE ) );
		$this->assertCount( 1, get_option( Babilim_Error_Reporter::OPTION_QUEUE ) );

		$this->awaiting();
		$this->post( 'babilim_error_decline' );
		$this->admin()->dispatch_babilim_error_decline();
		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_AWAITING ) );
	}

	public function test_notice_renders_preview_fields_as_text(): void {
		update_option( 'babilim_consent', true );
		$this->awaiting( array( 'origin_id' => '<script>alert(1)</script>' ) );

		$html = $this->notice_html();

		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Babilim hit an error. Share an error report?', $html );
		$this->assertStringContainsString( 'download_failed', $html );
		$this->assertStringContainsString( 'This report will be posted publicly on GitHub and may be kept by third parties.', $html );
		foreach ( array( 'Share this report', 'Always share', 'Don\'t share' ) as $label ) {
			$this->assertStringContainsString( esc_html( $label ), $html );
		}
		$this->assertStringContainsString( 'value="babilim_error_share_once"', $html );
		$this->assertStringContainsString( 'value="babilim_error_share_always"', $html );
		$this->assertStringContainsString( 'value="babilim_error_decline"', $html );
		$this->assertStringContainsString( 'name="event_id" value="' . self::EVENT_ID . '"', $html );
	}

	public function test_notice_is_hidden_without_awaiting_events_or_capability(): void {
		update_option( 'babilim_consent', true );
		$this->assertSame( '', $this->notice_html() );

		$this->awaiting();
		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->assertSame( '', $this->notice_html() );
	}

	public function test_settings_checkbox_is_off_by_default_and_save_stores_the_choice(): void {
		update_option( 'babilim_consent', true );
		$page = new ReflectionMethod( Babilim_Admin::class, 'render_settings_general' );
		$page->setAccessible( true );
		$html = $page->invoke( $this->admin() );
		$this->assertStringContainsString( 'Share error reports', $html );
		$this->assertMatchesRegularExpression( '/name="share_errors"(?![^>]*checked)/', $html );

		$this->post( 'babilim_save_settings', array( 'share_errors' => 'on' ) );
		$this->admin()->dispatch_babilim_save_settings();
		$this->assertSame( '1', (string) get_option( Babilim_Error_Reporter::OPTION_SHARE ) );

		$this->post( 'babilim_save_settings' );
		$this->admin()->dispatch_babilim_save_settings();
		$this->assertSame( '0', (string) get_option( Babilim_Error_Reporter::OPTION_SHARE ) );
	}

	public function test_disable_action_clears_error_queue(): void {
		update_option( 'babilim_consent', true );
		$this->awaiting();
		update_option( Babilim_Error_Reporter::OPTION_QUEUE, array( array( 'event_id' => self::EVENT_ID, 'error_code' => 'download_failed', 'action' => 'download', 'origin_id' => 'site.sync', 'at' => gmdate( 'c' ), 'attempts' => 0 ) ), false );
		$this->post( 'babilim_disable' );

		$this->admin()->dispatch_babilim_disable();

		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_AWAITING ) );
		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_QUEUE ) );
	}

	public function test_notice_is_hidden_when_babilim_is_disabled(): void {
		update_option( 'babilim_consent', false );
		$this->awaiting();

		$this->assertSame( '', $this->notice_html() );
	}

	public function test_notice_says_how_many_reports_the_buttons_apply_to_only_when_several_wait(): void {
		update_option( 'babilim_consent', true );
		$this->awaiting();
		$this->assertStringNotContainsString( 'waiting', $this->notice_html() );

		$two = array();
		foreach ( array( 'cccccccccccccccccccccccccccccccc', 'dddddddddddddddddddddddddddddddd' ) as $id ) {
			$two[] = array( 'event_id' => $id, 'error_code' => 'download_failed', 'action' => 'download', 'origin_id' => 'site.sync', 'at' => '2026-10-01T00:00:00+00:00', 'attempts' => 0 );
		}
		update_option( Babilim_Error_Reporter::OPTION_AWAITING, $two, false );
		$this->assertStringContainsString( '2 reports are waiting.', $this->notice_html() );
	}

	public function test_unticking_share_errors_empties_the_queue_so_the_next_sync_sends_nothing(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		update_option( Babilim_Error_Reporter::OPTION_SHARE, '1', false );
		update_option( Babilim_Error_Reporter::OPTION_QUEUE, array( array( 'event_id' => self::EVENT_ID, 'error_code' => 'download_failed', 'action' => 'download', 'origin_id' => 'site.sync', 'at' => gmdate( 'c' ), 'attempts' => 1 ) ), false );
		$this->awaiting( array( 'event_id' => 'dddddddddddddddddddddddddddddddd' ) );

		$this->post( 'babilim_save_settings' ); // The box is not ticked.
		$this->admin()->dispatch_babilim_save_settings();

		$this->assertSame( '0', (string) get_option( Babilim_Error_Reporter::OPTION_SHARE ) );
		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_QUEUE ) );
		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_AWAITING ) );

		$ok   = array( 'response' => array( 'code' => 200 ), 'body' => (string) wp_json_encode( array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.9.0' ) ), 'headers' => array() );
		$http = new Babilim_Fake_Http( array( $ok ) );
		$inv  = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		( new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inv, new Babilim_Manifest( new Babilim_Memory_Fs() ) ) )->sync();
		foreach ( $http->requests as $r ) {
			$this->assertStringNotContainsString( '/v1/error-reports', $r['url'] );
		}
	}

	public function test_saving_with_sharing_already_off_keeps_the_events_waiting_for_a_decision(): void {
		update_option( 'babilim_consent', true );
		$this->awaiting();

		$this->post( 'babilim_save_settings' );
		$this->admin()->dispatch_babilim_save_settings();

		$this->assertCount( 1, get_option( Babilim_Error_Reporter::OPTION_AWAITING ) );
	}

	public function test_notice_lists_every_field_the_request_sends_from_the_same_source(): void {
		update_option( 'babilim_consent', true );
		$this->awaiting();
		$html = $this->notice_html();

		$reporter = Babilim_Error_Reporter::for_site();
		$body     = $reporter->request_body( $reporter->awaiting() );
		$shown    = array();
		preg_match_all( '/<li>([a-z_]+): /', $html, $m );
		$shown = $m[1];
		$sent  = array_merge( array_diff( array_keys( $body ), array( 'events' ) ), array_keys( $body['events'][0] ) );
		sort( $shown );
		sort( $sent );
		$this->assertSame( $sent, $shown, 'the notice shows exactly the fields the request carries' );
		foreach ( array( 'event_id', 'error_code', 'action', 'origin_id', 'at', 'plugin_version', 'wp_version', 'php_version', 'locale' ) as $field ) {
			$this->assertContains( $field, $shown, $field );
		}
	}

	public function test_sent_request_has_the_same_field_set_the_notice_shows(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		update_option( Babilim_Error_Reporter::OPTION_SHARE, '1', false );
		update_option( Babilim_Error_Reporter::OPTION_QUEUE, array( array( 'event_id' => self::EVENT_ID, 'error_code' => 'download_failed', 'action' => 'download', 'origin_id' => 'site.sync', 'at' => gmdate( 'c' ), 'attempts' => 0 ) ), false );
		$ok   = array( 'response' => array( 'code' => 200 ), 'body' => (string) wp_json_encode( array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.9.0' ) ), 'headers' => array() );
		$acc  = array( 'response' => array( 'code' => 202 ), 'body' => (string) wp_json_encode( array( 'accepted' => 1, 'ignored' => 0 ) ), 'headers' => array() );
		$http = new Babilim_Fake_Http( array( $ok, $acc ) );
		$inv  = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		( new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inv, new Babilim_Manifest( new Babilim_Memory_Fs() ) ) )->sync();
		$sent = json_decode( $http->requests[1]['args']['body'], true );

		$this->awaiting();
		preg_match_all( '/<li>([a-z_]+): /', $this->notice_html(), $m );
		$wire = array_merge( array_diff( array_keys( $sent ), array( 'events' ) ), array_keys( $sent['events'][0] ) );
		sort( $wire );
		$shown = $m[1];
		sort( $shown );
		$this->assertSame( $wire, $shown );
	}

	public function test_disclosure_texts_no_longer_say_no_site_address_is_sent_and_say_ai(): void {
		update_option( 'babilim_consent', true );
		$page = new ReflectionMethod( Babilim_Admin::class, 'render_settings_general' );
		$page->setAccessible( true );
		$html = $page->invoke( $this->admin() );
		$this->assertStringNotContainsString( 'No site address', $html );
		$this->assertStringContainsString( 'the public issue contains no site address', $html );
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
		$this->assertStringNotContainsString( 'Not sent: the site address', $readme );
		$this->assertStringContainsString( 'AI models', $readme );
		$this->assertStringContainsString( '= 1.1.0 =', substr( $readme, (int) strpos( $readme, '== Upgrade Notice ==' ) ) );
	}

	public function test_the_three_actions_return_to_the_admin_page_they_came_from(): void {
		foreach ( array( 'share_once', 'share_always', 'decline' ) as $name ) {
			$this->awaiting();
			$GLOBALS['babilim_test_redirects'] = array();
			$GLOBALS['babilim_test_referer']   = 'http://example.org/wp-admin/plugins.php?x=1';
			$this->post( 'babilim_error_' . $name, array( 'event_id' => self::EVENT_ID ) );
			$this->admin()->{'dispatch_babilim_error_' . $name}();
			$this->assertSame( 'http://example.org/wp-admin/plugins.php?x=1', $GLOBALS['babilim_test_redirects'][0]['location'], $name );
		}
	}

	public function test_a_referer_outside_this_sites_admin_falls_back_to_the_translations_page(): void {
		foreach ( array( 'http://evil.example.net/wp-admin/', 'http://example.org/other', '' ) as $referer ) {
			$this->awaiting();
			$GLOBALS['babilim_test_redirects'] = array();
			$GLOBALS['babilim_test_referer']   = $referer;
			$this->post( 'babilim_error_decline' );
			$this->admin()->dispatch_babilim_error_decline();
			$this->assertStringContainsString( 'page=babilim', $GLOBALS['babilim_test_redirects'][0]['location'], $referer );
			$this->assertStringContainsString( 'admin.php', $GLOBALS['babilim_test_redirects'][0]['location'], $referer );
		}
	}

	public function test_ticking_share_errors_authorizes_the_events_already_waiting(): void {
		update_option( 'babilim_consent', true );
		$this->awaiting();

		$this->post( 'babilim_save_settings', array( 'share_errors' => 'on' ) );
		$this->admin()->dispatch_babilim_save_settings();

		$this->assertSame( '1', (string) get_option( Babilim_Error_Reporter::OPTION_SHARE ) );
		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_AWAITING ) );
		$this->assertSame( self::EVENT_ID, get_option( Babilim_Error_Reporter::OPTION_QUEUE )[0]['event_id'] );
	}
}
