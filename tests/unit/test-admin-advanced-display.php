<?php
/**
 * The Advanced tab shows component and language names, and times in the
 * site's own timezone and date/time format.
 *
 * @package Babilim
 */

use PHPUnit\Framework\TestCase;

class Test_Admin_Advanced_Display extends TestCase {

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

	private function advanced_html( Babilim_Manifest $manifest, Babilim_Memory_Fs $fs ): string {
		update_option( 'babilim_consent', true );
		$_GET  = array( 'screen' => 'settings', 'tab' => 'advanced' );
		$admin = new Babilim_Admin( $fs, new Babilim_Fake_Http( array() ) );
		return $admin->render_current_page();
	}

	private function seed_index( Babilim_Manifest $manifest, string $slug, string $locale = 'de_DE' ): void {
		$manifest->set_default_index(
			$locale,
			array(
				'built_at'  => '2026-01-01T10:00:00Z',
				'included'  => array( array( 'slug' => $slug, 'component_version' => '1.0.0', 'entries' => 12 ) ),
				'excluded'  => array(),
				'conflicts' => array(),
				'entries'   => 12,
			)
		);
	}

	public function test_component_column_shows_the_name_with_the_slug_as_title(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme Shop', 'Version' => '1.0', 'TextDomain' => 'acme' ) );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->seed_index( $manifest, 'acme' );

		$html = $this->advanced_html( $manifest, $fs );

		$this->assertStringContainsString( '<td><span title="acme">Acme Shop</span></td>', $html );
	}

	public function test_component_column_falls_back_to_the_slug_when_no_name_is_known(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->seed_index( $manifest, 'gone-plugin' );

		$html = $this->advanced_html( $manifest, $fs );

		$this->assertStringContainsString( '<td>gone-plugin</td>', $html );
	}

	public function test_language_column_shows_the_name_with_the_code_as_title_and_header_says_language(): void {
		update_option( Babilim_Locale_Label::OPTION_NAMES, array( 'de_DE' => 'Deutsch' ), false );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->seed_index( $manifest, 'acme' );

		$html = $this->advanced_html( $manifest, $fs );

		$this->assertStringContainsString( '<span title="de_DE">Deutsch</span>', $html );
		$this->assertStringContainsString( '<th>Language</th>', $html );
		$this->assertStringNotContainsString( '<th>Locale</th>', $html );
	}

	public function test_language_column_falls_back_to_the_code_when_no_name_is_known(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->seed_index( $manifest, 'acme', 'xx_YY' );

		$html = $this->advanced_html( $manifest, $fs );

		$this->assertStringContainsString( '<span title="xx_YY">xx_YY</span>', $html );
	}

	public function test_built_and_cleanup_times_use_the_site_timezone_and_formats(): void {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		update_option( 'date_format', 'j/n/Y' );
		update_option( 'time_format', 'H:i' );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$this->seed_index( $manifest, 'acme' );
		$manifest->set_sweep_summary( array( 'swept_at' => '2026-01-01T20:00:00Z', 'orphans_marked' => 0, 'orphans_deleted' => 0, 'records_purged' => 0 ) );

		$html = $this->advanced_html( $manifest, $fs );

		$this->assertStringContainsString( '1/1/2026 19:00', $html, 'built time in the site timezone' );
		$this->assertStringContainsString( '2/1/2026 05:00', $html, 'cleanup time in the site timezone' );
		$this->assertStringNotContainsString( '2026-01-01T10:00:00Z', $html );
	}

	/**
	 * Every stored time on the Advanced tab and in the in-progress notice
	 * goes through the site's timezone and formats.
	 */
	public function test_every_other_stored_time_uses_the_site_timezone_and_formats(): void {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		update_option( 'date_format', 'j/n/Y' );
		update_option( 'time_format', 'H:i' );
		$fs = new Babilim_Memory_Fs();
		$fs->put(
			Babilim_Paths::manifest_file(),
			(string) json_encode(
				array(
					'records'        => array(),
					'default_index'  => array(),
					'sweep_guard'    => array( 'tripped_at' => '2026-01-01T10:00:00Z', 'reason' => 'brake', 'would_orphan' => 2, 'managed_total' => 2 ),
					'sweep_summary'  => null,
					'write_conflict' => array( 'at' => '2026-01-02T10:00:00Z', 'section' => 'records', 'key' => null ),
				)
			)
		);
		update_site_option( Babilim_Manifest::WRITE_FAILURE_OPTION, array( 'at' => '2026-01-03T10:00:00Z', 'section' => 'records', 'key' => null, 'reason' => 'lock_unavailable' ) );

		$html = $this->advanced_html( new Babilim_Manifest( $fs ), $fs );

		$this->assertStringContainsString( 'A cleanup was paused on 1/1/2026 19:00:', $html, 'tripped_at' );
		$this->assertStringContainsString( 'another request on 2/1/2026 19:00 ', $html, 'write conflict at' );
		$this->assertStringContainsString( 'index write on 3/1/2026 19:00 ', $html, 'write failure at' );
		$this->assertStringNotContainsString( '2026-01-0', $html );
	}

	public function test_the_in_progress_last_attempt_uses_the_site_timezone_and_formats(): void {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		update_option( 'date_format', 'j/n/Y' );
		update_option( 'time_format', 'H:i' );
		update_option( 'babilim_consent', true );
		update_option( 'babilim_sync_last_error', array( 'code' => 'in_progress', 'message' => 'm', 'since' => '2026-01-01T10:00:00Z', 'at' => '2026-01-01T10:00:00Z' ), false );
		$_GET = array();

		$html = ( new Babilim_Admin( new Babilim_Memory_Fs(), new Babilim_Fake_Http( array() ) ) )->render_current_page();

		$this->assertStringContainsString( '(last attempt: 1/1/2026 19:00)', $html );
	}

	public function test_an_unparseable_time_is_shown_as_it_was_stored(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_sweep_summary( array( 'swept_at' => 'sometime last week', 'orphans_marked' => 0, 'orphans_deleted' => 0, 'records_purged' => 0 ) );

		$html = $this->advanced_html( $manifest, $fs );

		$this->assertStringContainsString( 'sometime last week', $html );
	}

	public function test_rate_limit_time_uses_the_site_timezone_and_formats(): void {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		update_option( 'date_format', 'j/n/Y' );
		update_option( 'time_format', 'H:i' );
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		$until = gmmktime( 20, 0, 0, 1, 1, 2099 );
		( new Babilim_State() )->set( array( 'rate_limited_until' => $until ) );
		$_GET = array();

		$html = ( new Babilim_Admin( new Babilim_Memory_Fs(), new Babilim_Fake_Http( array() ) ) )->render_current_page();

		$this->assertStringContainsString( '2/1/2099 05:00', $html );
		$this->assertStringNotContainsString( ' UTC.', $html );
	}
}
