<?php
/**
 * The settings page: two tabs, one settings form, language management,
 * the account block and the Advanced tab's maintenance buttons.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-kses.php';

final class Test_Admin_Settings_Tabs extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
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
		$fields['action']   = $action;
		$fields['_wpnonce'] = wp_create_nonce( $action );
		$_POST              = $fields;
		$_REQUEST           = $fields;
	}

	private function page( array $query = array(), ?Babilim_Http $http = null ): string {
		$_GET = array_merge( array( 'page' => 'babilim-settings' ), $query );
		return wp_kses( $this->admin( $http )->render_current_page(), Babilim_Admin::allowed_admin_html() );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	/**
	 * The nth node of a query, as an element.
	 */
	private function el( DOMXPath $x, string $query, int $n = 0 ): DOMElement {
		$node = $x->query( $query )->item( $n );
		$this->assertInstanceOf( DOMElement::class, $node, $query );
		if ( ! $node instanceof DOMElement ) {
			throw new RuntimeException( 'No element for ' . $query );
		}
		return $node;
	}

	/**
	 * The deepest <form> nesting in the markup, counted on the raw tags:
	 * the HTML parser closes an open form when another starts, so a
	 * DOM query can never see a nested form.
	 */
	private function form_depth( string $html ): int {
		preg_match_all( '#<(/?)form\b#i', $html, $m );
		$depth = 0;
		$max   = 0;
		foreach ( $m[1] as $closing ) {
			$depth += '' === $closing ? 1 : -1;
			$max    = max( $max, $depth );
		}
		return $max;
	}

	private function enable(): void {
		update_option( 'babilim_consent', true );
	}

	// -- tabs ---------------------------------------------------------------

	public function test_there_are_two_tabs_and_general_is_the_default(): void {
		$x = $this->xpath( $this->page() );
		$this->assertSame( 1, $x->query( '//h2[contains(@class,"nav-tab-wrapper")]' )->length );
		$this->assertSame( 2, $x->query( '//h2[contains(@class,"nav-tab-wrapper")]/a[contains(@class,"nav-tab")]' )->length );
		$this->assertSame( 1, $x->query( '//a[contains(@class,"nav-tab-active")]' )->length );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-settings-form"]' )->length );
		$this->assertSame( 0, $x->query( '//button[normalize-space()="Rebuild index now"]' )->length );
	}

	public function test_an_invalid_tab_value_is_general(): void {
		$x = $this->xpath( $this->page( array( 'tab' => 'nonsense' ) ) );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-settings-form"]' )->length );
	}

	public function test_the_advanced_tab_shows_the_maintenance_buttons_and_no_settings_form(): void {
		$this->enable();
		$x = $this->xpath( $this->page( array( 'tab' => 'advanced' ) ) );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-settings-form"]' )->length );
		foreach ( array( 'babilim_sync_now', 'babilim_rebuild_index', 'babilim_sweep_now', 'babilim_purge_all' ) as $action ) {
			$this->assertSame( 1, $x->query( '//input[@name="action"][@value="' . $action . '"]' )->length, $action );
		}
		$this->assertSame( 1, $x->query( '//button[normalize-space()="Check again now"]' )->length );
		$this->assertSame( 1, $x->query( '//button[normalize-space()="Rebuild index now"]' )->length );
		$this->assertSame( 1, $x->query( '//button[normalize-space()="Sweep now"]' )->length );
	}

	public function test_the_advanced_tab_first_details_has_the_spacing_class(): void {
		$x     = $this->xpath( $this->page( array( 'tab' => 'advanced' ) ) );
		$nodes = $x->query( '//details[contains(concat(" ", normalize-space(@class), " "), " babilim-advanced-first ")]' );
		$this->assertSame( 1, $nodes->length );
	}

	public function test_the_danger_zone_button_asks_for_confirmation(): void {
		$x     = $this->xpath( $this->page( array( 'tab' => 'advanced' ) ) );
		$nodes = $x->query( '//button[@data-babilim-confirm][contains(normalize-space(),"Remove everything")]' );
		$this->assertSame( 1, $nodes->length );
		$this->assertNotSame( '', $this->el( $x, '//button[@data-babilim-confirm][contains(normalize-space(),"Remove everything")]' )->getAttribute( 'data-babilim-confirm' ) );
	}

	public function test_sync_now_is_a_post_form_and_there_is_no_get_link(): void {
		$this->enable();
		$html = $this->page( array( 'tab' => 'advanced' ) );
		$this->assertStringNotContainsString( 'action=babilim_sync_now&', $html );
		$x     = $this->xpath( $html );
		$forms = $x->query( '//form[@method="post"][.//input[@name="action"][@value="babilim_sync_now"]]' );
		$this->assertSame( 1, $forms->length );
		$this->assertSame( 1, $x->query( './/input[@name="_wpnonce"]', $forms->item( 0 ) )->length );
	}

	public function test_on_multisite_the_automatic_installs_row_says_new_translations_are_not_installed(): void {
		$this->assertStringNotContainsString( 'multisite network', $this->page() );
		$GLOBALS['babilim_test_multisite'] = true;
		$this->assertStringContainsString( 'does not install new translations automatically', $this->page() );
	}

	public function test_on_multisite_without_the_network_capability_the_advanced_tab_is_read_only(): void {
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$html = $this->page( array( 'tab' => 'advanced' ) );
		$this->assertStringNotContainsString( 'value="babilim_rebuild_index"', $html );
		$this->assertStringNotContainsString( 'value="babilim_purge_all"', $html );
		$this->assertStringContainsString( 'Only a network administrator can do this', $html );
	}

	// -- general: one form, one primary button --------------------------------

	public function test_general_has_one_primary_button_and_no_nested_forms(): void {
		$this->enable();
		$x = $this->xpath( $this->page() );
		$this->assertSame( 1, $x->query( '//button[contains(@class,"button-primary")]' )->length );
		$this->assertSame( 'Save changes', trim( $x->query( '//button[contains(@class,"button-primary")]' )->item( 0 )->textContent ) );
		$this->assertSame( 0, $x->query( '//form//form' )->length );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-settings-form"][@method="post"]//input[@name="action"][@value="babilim_save_settings"]' )->length );
		$this->assertGreaterThan( 0, $x->query( '//form[@id="babilim-settings-form"]//table[contains(@class,"form-table")]' )->length );
	}

	public function test_forms_are_never_nested_on_either_tab(): void {
		$this->enable();
		foreach ( array( array(), array( 'tab' => 'advanced' ) ) as $query ) {
			$this->assertSame( 1, $this->form_depth( $this->page( $query ) ), 'no <form> inside another <form>' );
		}
	}

	public function test_the_disable_button_lives_in_its_own_block_after_the_settings_form(): void {
		$this->enable();
		$x      = $this->xpath( $this->page() );
		$button = $x->query( '//button[normalize-space()="Disable Babilim"]' );
		$this->assertSame( 1, $button->length );
		$btn = $this->el( $x, '//button[normalize-space()="Disable Babilim"]' );
		$this->assertSame( 'submit', $btn->getAttribute( 'type' ) );
		$this->assertSame( '', $btn->getAttribute( 'form' ), 'the button sits inside its own form, no form attribute needed' );
		$this->assertStringContainsString( 'button-link-delete', $btn->getAttribute( 'class' ) );
		$this->assertStringContainsString( 'Disable Babilim? Installed translations are kept', $btn->getAttribute( 'data-babilim-confirm' ) );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-settings-form"]//button[normalize-space()="Disable Babilim"]' )->length );
		$this->assertSame( 0, $x->query( '//button[contains(@class,"button-primary")]/parent::p//button[normalize-space()="Disable Babilim"]' )->length, 'not in the Save changes paragraph' );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-settings-form"]/following::h2[normalize-space()="Disable Babilim"]' )->length );
		$this->assertSame( 1, $x->query( '//h2[normalize-space()="Disable Babilim"]/following-sibling::p[contains(@class,"description")][normalize-space()="Installed translations are kept, but no further checks or downloads will run."]' )->length );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-disable-form"]//button[normalize-space()="Disable Babilim"]' )->length );

		$form = $x->query( '//form[@id="babilim-disable-form"]' );
		$this->assertSame( 1, $form->length );
		$this->assertSame( 'post', $this->el( $x, '//form[@id="babilim-disable-form"]' )->getAttribute( 'method' ) );
		$this->assertStringContainsString( 'admin-post.php', $this->el( $x, '//form[@id="babilim-disable-form"]' )->getAttribute( 'action' ) );
		$this->assertSame( 1, $x->query( './/input[@type="hidden"][@name="action"][@value="babilim_disable"]', $form->item( 0 ) )->length );
		$this->assertSame( 1, $x->query( './/input[@name="_wpnonce"]', $form->item( 0 ) )->length );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-settings-form"]//form' )->length );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-settings-form"]//input[@value="babilim_disable"]' )->length );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-settings-form"]/following::form[@id="babilim-disable-form"]' )->length );
	}

	public function test_without_consent_the_account_row_says_so_and_has_no_disable_button(): void {
		$x = $this->xpath( $this->page() );
		$this->assertStringContainsString( 'Babilim is not enabled.', $x->document->textContent );
		$this->assertSame( 0, $x->query( '//button[normalize-space()="Disable Babilim"]' )->length );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-disable-form"]' )->length );
	}

	// -- general: languages -----------------------------------------------------

	public function test_detected_locales_are_checkboxes_marked_detected(): void {
		$x     = $this->xpath( $this->page() );
		$boxes = $x->query( '//input[@type="checkbox"][@name="locales[]"][@value="de_DE"]' );
		$this->assertSame( 1, $boxes->length );
		$this->assertTrue( $this->el( $x, '//input[@name="locales[]"][@value="de_DE"]' )->hasAttribute( 'checked' ) );
		$this->assertStringContainsString( '(detected)', $x->document->textContent );
	}

	public function test_the_automatic_installs_row_is_on_by_default_and_save_stores_the_choice(): void {
		$x = $this->xpath( $this->page() );
		$this->assertStringContainsString( 'Automatic installs', $x->document->textContent );
		$this->assertTrue( $this->el( $x, '//input[@type="checkbox"][@name="auto_install"]' )->hasAttribute( 'checked' ), 'a missing option reads as on' );
		$this->assertStringContainsString( 'no new slot is taken', $x->document->textContent );

		$this->post( 'babilim_save_settings', array( 'locales' => array( 'de_DE' ) ) );
		$this->admin()->dispatch_babilim_save_settings();
		$this->assertSame( 0, (int) get_option( 'babilim_auto_install' ), 'an unchecked box turns it off' );
		$this->assertFalse( $this->el( $this->xpath( $this->page() ), '//input[@name="auto_install"]' )->hasAttribute( 'checked' ) );

		$this->post( 'babilim_save_settings', array( 'locales' => array( 'de_DE' ), 'auto_install' => 'on' ) );
		$this->admin()->dispatch_babilim_save_settings();
		$this->assertSame( 1, (int) get_option( 'babilim_auto_install' ) );
	}

	public function test_unchecking_a_detected_locale_stops_managing_it_and_checking_restores_it(): void {
		$GLOBALS['babilim_test_available_languages'] = array( 'fr_FR' );
		$this->post( 'babilim_save_settings', array( 'locales' => array( 'de_DE' ) ) );
		$this->admin()->dispatch_babilim_save_settings();

		$this->assertSame( array( 'fr_FR' ), get_option( 'babilim_disabled_locales' ) );
		$inventory = new Babilim_Inventory( static function () { return array(); }, static function () { return array(); }, static function () { return false; }, static function () { return array(); } );
		$this->assertSame( array( 'de_DE' ), $inventory->locales() );

		$x = $this->xpath( $this->page() );
		$this->assertFalse( $this->el( $x, '//input[@name="locales[]"][@value="fr_FR"]' )->hasAttribute( 'checked' ), 'a stopped locale stays listed, unchecked' );

		$this->post( 'babilim_save_settings', array( 'locales' => array( 'de_DE', 'fr_FR' ) ) );
		$this->admin()->dispatch_babilim_save_settings();
		$this->assertSame( array(), get_option( 'babilim_disabled_locales' ) );
		$this->assertSame( array( 'de_DE', 'fr_FR' ), $inventory->locales() );
		$this->assertSame( admin_url( 'admin.php?page=babilim-settings&tab=general' ), $GLOBALS['babilim_test_redirects'][1]['location'] );
	}

	public function test_a_manual_locale_that_is_unchecked_is_removed(): void {
		update_option( 'babilim_manual_locales', array( 'pt_BR', 'vi' ) );
		$this->post( 'babilim_save_settings', array( 'locales' => array( 'de_DE', 'pt_BR' ) ) );
		$this->admin()->dispatch_babilim_save_settings();
		$this->assertSame( array( 'pt_BR' ), get_option( 'babilim_manual_locales' ) );
		$this->assertSame( array(), get_option( 'babilim_disabled_locales' ) );
	}

	public function test_the_new_locale_menu_lists_only_locales_that_are_not_managed(): void {
		update_option( 'babilim_manual_locales', array( 'pt_BR' ) );
		set_transient( 'babilim_meta_locales_cache', array( 'de_DE', 'pt_BR', 'ja', 'vi' ), 12 * HOUR_IN_SECONDS );
		$this->enable();
		$x       = $this->xpath( $this->page() );
		$options = $x->query( '//select[@name="new_locale"]/option' );
		$values  = array();
		foreach ( $options as $o ) {
			if ( $o instanceof DOMElement ) {
				$values[] = $o->getAttribute( 'value' );
			}
		}
		$this->assertSame( array( '', 'ja', 'vi' ), $values );
	}

	public function test_managed_language_checkboxes_show_the_name_with_the_code_in_the_title(): void {
		set_site_transient( 'available_translations', array( 'de_DE' => array( 'native_name' => 'Deutsch' ) ) );
		$x    = $this->xpath( $this->page() );
		$span = $this->el( $x, '//label[input[@name="locales[]"][@value="de_DE"]]/span' );
		$this->assertSame( 'Deutsch', trim( $span->textContent ) );
		$this->assertSame( 'de_DE', $span->getAttribute( 'title' ) );
		$this->assertStringContainsString( '(detected)', $x->document->textContent );
	}

	public function test_every_language_list_is_ordered_by_code_whatever_the_names(): void {
		set_site_transient(
			'available_translations',
			array(
				'ja'    => array( 'native_name' => 'Aaa' ),
				'vi'    => array( 'native_name' => 'Zzz' ),
				'fr_FR' => array( 'native_name' => 'Mmm' ),
			)
		);
		update_option( 'babilim_manual_locales', array( 'vi', 'fr_FR' ) );
		set_transient( 'babilim_meta_locales_cache', array( 'vi', 'ja', 'fr_FR', 'zz', 'ar' ), 12 * HOUR_IN_SECONDS );
		$this->enable();
		$x      = $this->xpath( $this->page() );
		$values = array();
		foreach ( $x->query( '//select[@name="new_locale"]/option' ) as $o ) {
			if ( $o instanceof DOMElement ) {
				$values[] = $o->getAttribute( 'value' );
			}
		}
		$this->assertSame( array( '', 'ar', 'ja', 'zz' ), $values, 'the add-language menu is in code order' );
		$boxes = array();
		foreach ( $x->query( '//input[@name="locales[]"]' ) as $o ) {
			if ( $o instanceof DOMElement ) {
				$boxes[] = $o->getAttribute( 'value' );
			}
		}
		$this->assertSame( array( 'de_DE', 'fr_FR', 'vi' ), $boxes, 'the checkboxes are in code order too' );
	}

	public function test_without_consent_the_page_makes_no_request_and_has_no_remote_list(): void {
		$http = new Babilim_Fake_Http( array() );
		$x    = $this->xpath( $this->page( array(), $http ) );
		$this->assertCount( 0, $http->requests );
		$this->assertSame( 0, $x->query( '//select[@name="new_locale"]' )->length, 'no menu is offered before consent' );
	}

	public function test_saving_a_new_locale_from_the_menu_adds_it(): void {
		$this->post( 'babilim_save_settings', array( 'locales' => array( 'de_DE' ), 'new_locale' => 'ja' ) );
		$this->admin()->dispatch_babilim_save_settings();
		$this->assertSame( array( 'ja' ), get_option( 'babilim_manual_locales' ) );
	}

	// -- general: uninstall + account ----------------------------------------------

	public function test_the_uninstall_defaults_and_risk_text(): void {
		$x = $this->xpath( $this->page() );
		$this->assertFalse( $this->el( $x, '//input[@name="delete_data_on_uninstall"]' )->hasAttribute( 'checked' ) );
		$this->assertTrue( $this->el( $x, '//input[@name="keep_custom"]' )->hasAttribute( 'checked' ) );
		$this->assertStringContainsString( 'cannot be restored automatically', $x->document->textContent );
	}

	public function test_general_uses_plain_wording_for_languages_and_custom_overrides(): void {
		$html = $this->page();
		$text = $this->xpath( $html )->document->textContent;
		$this->assertStringContainsString( 'Babilim adds translations for every language checked here.', $text );
		$this->assertStringNotContainsString( 'equal footing', $text );
		$this->assertStringContainsString( 'Keep my own translation overrides when purging everything or uninstalling this plugin.', $text );
		$this->assertStringContainsString( "Translation files you put in ", $text );
		$this->assertStringContainsString( " always take priority over Babilim's. They are kept unless you uncheck this.", $text );
		$this->assertStringNotContainsString( 'always wins over anything Babilim provides', $text );
		$rel = basename( rtrim( WP_LANG_DIR, '/' ) ) . '/babilim/custom/';
		$x   = $this->xpath( $html );
		$this->assertSame( $rel, $this->el( $x, '//input[@name="keep_custom"]/ancestor::td//p[contains(@class,"description")]//code' )->textContent );
		$this->assertSame( 'wp-content/languages/babilim/custom/', Babilim_Admin::custom_dir_label_for( '/var/www/html/wp-content/languages/babilim/custom', '/var/www/html/' ) );
		$this->assertSame( 'languages/babilim/custom/', Babilim_Admin::custom_dir_label_for( '/srv/shared/languages/babilim/custom', '/var/www/html/' ), 'outside the root: last three segments' );
		$this->assertSame( 'lang/other/custom/', Babilim_Admin::custom_dir_label_for( '/x/lang/other/custom', '' ), 'derived from the path, not fixed' );
		$this->assertStringNotContainsString( WP_LANG_DIR, $text, 'only the relative path is shown' );
	}

	public function test_the_account_list_carries_the_babilim_account_class_and_css(): void {
		$this->enable();
		$x = $this->xpath( $this->page() );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-settings-form"]//th[normalize-space()="Account"]/following-sibling::td/ul[contains(@class,"babilim-account")]' )->length );
		$m = new ReflectionMethod( Babilim_Admin::class, 'admin_css' );
		$m->setAccessible( true );
		$this->assertStringContainsString( '.babilim-account{margin:0}', (string) $m->invoke( null ) );
	}

	public function test_no_translatable_string_uses_a_double_hyphen_separator(): void {
		$pot = (string) file_get_contents( dirname( __DIR__, 2 ) . '/languages/babilim.pot' );
		$this->assertSame( 0, preg_match( '/^msgid .* -- /m', $pot ), 'a msgid still contains " -- "' );
		$this->assertStringContainsString( 'msgid "Thank you. Your report was sent."', $pot );
	}

	public function test_the_account_block_has_three_verification_states(): void {
		$this->enable();
		$states = array();

		$states['none'] = $this->xpath( $this->page() )->document->textContent;

		update_option( 'babilim_identity', array( 'site_id' => 'st_x', 'token' => 'tok_secret_value' ), false );
		$states['registered'] = $this->xpath( $this->page() )->document->textContent;

		update_option( Babilim_Site::OPTION_VERIFICATION_WARNING, array( 'reason' => 'callback_unreachable', 'since' => time(), 'retries' => 1, 'next_retry_at' => time() + 60 ) );
		$states['warning'] = $this->xpath( $this->page() )->document->textContent;

		$this->assertStringContainsString( 'Not registered yet', $states['none'] );
		$this->assertStringContainsString( 'Verified', $states['registered'] );
		$this->assertStringContainsString( 'Not verified', $states['warning'] );
		$this->assertCount( 3, array_unique( array( $this->account_line( $states['none'] ), $this->account_line( $states['registered'] ), $this->account_line( $states['warning'] ) ) ) );
		foreach ( $states as $text ) {
			$this->assertStringNotContainsString( 'tok_secret_value', $text );
		}
	}

	private function account_line( string $text ): string {
		return 1 === preg_match( '/Status:\s*(Not registered yet|Not verified|Verified)/', $text, $m ) ? $m[1] : 'unmatched';
	}

	public function test_the_account_block_shows_quota_and_license_kind(): void {
		$this->enable();
		( new Babilim_State() )->set(
			array(
				'quota'   => array( 'free_slots' => array( 'theme' => array( 'used' => 0, 'limit' => 1 ), 'plugin' => array( 'used' => 1, 'limit' => 2 ) ) ),
				'license' => array( 'kind' => 'free', 'status' => 'active' ),
			)
		);
		$text = $this->xpath( $this->page() )->document->textContent;
		$this->assertStringContainsString( 'Theme 0/1', $text );
		$this->assertStringContainsString( 'Plugin 1/2', $text );
		$this->assertStringContainsString( 'License: Free', $text );
	}

	// -- Check again now: the foreground budget ----------------------------------

	public function test_check_again_now_uses_the_20_second_budget_and_keeps_the_follow_up(): void {
		$this->enable();
		update_option( 'babilim_identity', array( 'site_id' => 'st_x', 'token' => 'tok_x' ), false );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0', 'TextDomain' => 'acme' ) );
		// The clock reads 1000 when the deadline is made and 1030
		// afterwards: a 20 second budget is spent by the time the first
		// request would start (nothing is sent), a 45 second one is not.
		$calls = 0;
		add_filter(
			'babilim_now',
			static function () use ( &$calls ) {
				return 0 === $calls++ ? 1000 : 1030;
			}
		);
		$http = new Babilim_Fake_Http( array( new WP_Error( 'http_request_failed', 'cURL error 28' ) ) );
		$this->post( 'babilim_sync_now' );

		$this->admin( $http )->dispatch_babilim_sync_now();

		$this->assertSame( 20, Babilim_Site::RESUME_BUDGET_SECONDS );
		$this->assertCount( 0, $http->requests, 'the deadline was 20 seconds, so it had passed' );
		$events = array_filter(
			$GLOBALS['babilim_test_single_events_scheduled'],
			static function ( $e ) {
				return Babilim::SYNC_ONCE_HOOK === $e['hook'];
			}
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 1, $GLOBALS['babilim_test_spawn_cron_calls'] );
	}

	// -- Redirects go back to the tab that triggered them ------------------

	public function test_advanced_actions_redirect_back_to_the_advanced_tab(): void {
		$this->enable();
		update_option( 'babilim_identity', array( 'site_id' => 'st_x', 'token' => 'tok_x' ), false );
		$advanced = admin_url( 'admin.php?page=babilim-settings&tab=advanced' );
		foreach ( array( 'dispatch_babilim_restart_registration', 'dispatch_babilim_rebuild_index', 'dispatch_babilim_sweep_now', 'dispatch_babilim_purge_all' ) as $method ) {
			$GLOBALS['babilim_test_redirects'] = array();
			$this->post( substr( $method, strlen( 'dispatch_' ) ) );
			$this->admin()->$method();
			$this->assertSame( $advanced, $GLOBALS['babilim_test_redirects'][0]['location'], $method );
		}
	}

	public function test_check_again_now_from_advanced_returns_to_advanced_and_from_elsewhere_to_the_list(): void {
		$this->enable();
		$GLOBALS['babilim_test_redirects'] = array();
		$this->post( 'babilim_sync_now', array( 'tab' => 'advanced' ) );
		$this->admin()->dispatch_babilim_sync_now();
		$this->assertSame( admin_url( 'admin.php?page=babilim-settings&tab=advanced' ), $GLOBALS['babilim_test_redirects'][0]['location'] );

		$GLOBALS['babilim_test_redirects'] = array();
		$this->post( 'babilim_sync_now' );
		$this->admin()->dispatch_babilim_sync_now();
		$this->assertSame( admin_url( 'admin.php?page=babilim' ), $GLOBALS['babilim_test_redirects'][0]['location'] );
	}

	public function test_the_advanced_check_again_form_says_it_came_from_advanced(): void {
		$this->enable();
		$x = $this->xpath( $this->page( array( 'tab' => 'advanced' ) ) );
		$this->assertSame( 'advanced', $this->el( $x, '//form[.//input[@value="babilim_sync_now"]]//input[@name="tab"]' )->getAttribute( 'value' ) );
	}

	// -- The screens read consent from the database ------------------------

	public function test_a_stale_cached_consent_does_not_show_the_enabled_screens(): void {
		$this->enable();
		// Another request revoked consent: the row says no, but this
		// process's cached option still says yes.
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_consent', serialize( false ) );
		$this->assertTrue( ( new Babilim_Consent() )->granted(), 'the stale cache still says yes' );
		$html = $this->page();
		$this->assertStringNotContainsString( 'Disable Babilim', $html );
		$this->assertStringContainsString( 'Babilim is not enabled.', $html );
	}
}
