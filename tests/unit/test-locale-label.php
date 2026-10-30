<?php
/**
 * Babilim_Locale_Label and the three-cell translations column.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Locale_Label extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_REQUEST = array();
		Babilim::reset_for_tests();
	}

	private function cache_list(): void {
		set_site_transient(
			'available_translations',
			array(
				'de_DE' => array( 'native_name' => 'Deutsch', 'language' => 'de_DE' ),
				'ja'    => array( 'native_name' => '', 'language' => 'ja' ),
			)
		);
	}

	public function test_a_cached_list_gives_the_native_name(): void {
		$this->cache_list();
		$this->assertSame( 'Deutsch', Babilim_Locale_Label::name( 'de_DE' ) );
	}

	public function test_an_unknown_code_or_empty_name_falls_back_to_the_code(): void {
		$this->cache_list();
		$this->assertSame( 'xx_YY', Babilim_Locale_Label::name( 'xx_YY' ) );
		$this->assertSame( 'ja', Babilim_Locale_Label::name( 'ja' ) );
	}

	public function test_en_us_is_english_united_states_with_or_without_the_list(): void {
		$this->assertSame( 'English (United States)', Babilim_Locale_Label::name( 'en_US' ) );
		$this->cache_list();
		$this->assertSame( 'English (United States)', Babilim_Locale_Label::name( 'en_US' ) );
	}

	public function test_without_any_source_it_returns_the_code_and_makes_no_http_request(): void {
		// The test double for wp_remote_request() records every call it
		// gets and does not run the pre_http_request filter, so this
		// record is the check that counts.
		$GLOBALS['babilim_test_wp_remote_requests'] = array();
		$this->assertSame( 'de_DE', Babilim_Locale_Label::name( 'de_DE' ) );
		$this->assertSame( array(), $GLOBALS['babilim_test_wp_remote_requests'] );
	}

	public function test_the_stored_service_names_are_used_when_core_has_no_list(): void {
		update_option( Babilim_Locale_Label::OPTION_NAMES, array( 'de_DE' => 'Deutsch (service)', 'ja' => '', 'x' => 5 ), false );
		$GLOBALS['babilim_test_wp_remote_requests'] = array();
		$this->assertSame( 'Deutsch (service)', Babilim_Locale_Label::name( 'de_DE' ) );
		$this->assertSame( 'ja', Babilim_Locale_Label::name( 'ja' ), 'an empty stored name falls back to the code' );
		$this->assertSame( 'x', Babilim_Locale_Label::name( 'x' ), 'a non-string stored name is ignored' );
		$this->assertSame( 'fr_FR', Babilim_Locale_Label::name( 'fr_FR' ), 'both sources lack it: the code' );
		$this->assertSame( array(), $GLOBALS['babilim_test_wp_remote_requests'] );
	}

	public function test_core_list_wins_over_the_stored_service_names(): void {
		update_option( Babilim_Locale_Label::OPTION_NAMES, array( 'de_DE' => 'From service', 'fr_FR' => 'Francais (service)' ), false );
		$this->cache_list();
		$this->assertSame( 'Deutsch', Babilim_Locale_Label::name( 'de_DE' ) );
		$this->assertSame( 'Francais (service)', Babilim_Locale_Label::name( 'fr_FR' ), 'the code core lacks is filled from the stored names' );
	}

	public function test_sorted_orders_by_code_and_drops_duplicates(): void {
		$this->cache_list();
		$this->assertSame( array( 'de_DE', 'ja', 'zz' ), Babilim_Locale_Label::sorted( array( 'zz', 'ja', 'de_DE', 'ja' ) ) );
	}

	public function test_a_malformed_stored_option_falls_back_to_the_code(): void {
		update_option( Babilim_Locale_Label::OPTION_NAMES, 'garbage', false );
		$this->assertSame( 'de_DE', Babilim_Locale_Label::name( 'de_DE' ) );
	}

	public function test_remember_stores_the_names_without_autoload_and_skips_empty_ones(): void {
		Babilim_Locale_Label::remember(
			array(
				array( 'wp_locale' => 'de_DE', 'native_name' => 'Deutsch' ),
				array( 'wp_locale' => 'ja', 'native_name' => '  ' ),
				array( 'wp_locale' => '', 'native_name' => 'Nameless' ),
				'junk',
			)
		);
		$this->assertSame( array( 'de_DE' => 'Deutsch' ), get_option( Babilim_Locale_Label::OPTION_NAMES ) );
		$this->assertFalse( $GLOBALS['babilim_test_options_autoload'][ Babilim_Locale_Label::OPTION_NAMES ] );
		$this->assertSame( 'Deutsch', Babilim_Locale_Label::name( 'de_DE' ) );
	}

	public function test_a_successful_catalog_fetch_on_the_settings_page_stores_the_names_and_a_cached_one_adds_no_request(): void {
		update_option( 'babilim_consent', true );
		$ok   = array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( array( 'wp_locale' => 'ja', 'english_name' => 'Japanese', 'native_name' => 'Nihongo' ) ) ), 'headers' => array() );
		$http = new Babilim_Fake_Http( array( $ok ) );
		$_GET = array( 'screen' => 'settings' );
		( new Babilim_Admin( new Babilim_Memory_Fs(), $http ) )->render_current_page();
		$this->assertSame( array( 'ja' => 'Nihongo' ), get_option( Babilim_Locale_Label::OPTION_NAMES ) );
		$this->assertCount( 1, $http->requests );

		// The transient now holds the catalog: no second request.
		( new Babilim_Admin( new Babilim_Memory_Fs(), $http ) )->render_current_page();
		$this->assertCount( 1, $http->requests );

		// And the translations page never asks the service for names.
		( new Babilim_State() )->set( array( 'fetched_at' => '2026-10-01T00:00:00Z', 'components' => array() ) );
		$list_http = new Babilim_Fake_Http( array() );
		$_GET      = array( 'page' => 'babilim' );
		( new Babilim_Admin( new Babilim_Memory_Fs(), $list_http ) )->render_current_page();
		$this->assertCount( 0, $list_http->requests );
	}

	public function test_a_failed_catalog_fetch_keeps_the_names_already_stored(): void {
		update_option( Babilim_Locale_Label::OPTION_NAMES, array( 'ja' => 'Nihongo' ), false );
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http( array() );
		$_GET = array( 'screen' => 'settings' );
		( new Babilim_Admin( new Babilim_Memory_Fs(), $http ) )->render_current_page();
		$this->assertSame( array( 'ja' => 'Nihongo' ), get_option( Babilim_Locale_Label::OPTION_NAMES ) );
	}

	public function test_a_malformed_cache_value_falls_back_to_the_code(): void {
		set_site_transient( 'available_translations', 'garbage' );
		$this->assertSame( 'de_DE', Babilim_Locale_Label::name( 'de_DE' ) );
		Babilim_Locale_Label::reset_for_tests();
		set_site_transient( 'available_translations', array( 'de_DE' => 'x' ) );
		$this->assertSame( 'de_DE', Babilim_Locale_Label::name( 'de_DE' ) );
	}

	public function test_the_name_map_is_built_once_per_request(): void {
		$this->cache_list();
		$this->assertSame( 'Deutsch', Babilim_Locale_Label::name( 'de_DE' ) );

		// The list changes underneath; this request keeps the map it built.
		set_site_transient( 'available_translations', array( 'de_DE' => array( 'native_name' => 'Changed' ) ) );
		$this->assertSame( 'Deutsch', Babilim_Locale_Label::name( 'de_DE' ) );
		$this->assertSame( 'ja', Babilim_Locale_Label::name( 'ja' ) );

		Babilim_Locale_Label::reset_for_tests();
		$this->assertSame( 'Changed', Babilim_Locale_Label::name( 'de_DE' ) );
	}
}
