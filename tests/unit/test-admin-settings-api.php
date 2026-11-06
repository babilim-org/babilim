<?php
/**
 * The General settings form goes through the Settings API: every field
 * is a registered setting with its own sanitizer, posted to options.php
 * (settings_fields() supplies the group, the action and the nonce),
 * and the side effects of a change run on the option's own
 * update/add hooks.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Admin_Settings_Api extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$GLOBALS['babilim_test_is_admin']     = true;
		$GLOBALS['babilim_test_site_locale']  = 'de_DE';
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

	/** @return array<string,array> The settings register_settings() registers. */
	private function registered(): array {
		$GLOBALS['babilim_test_registered_settings'] = array();
		$this->admin()->register_settings();
		return $GLOBALS['babilim_test_registered_settings'];
	}

	private function sanitize( string $option, $value ) {
		$this->registered();
		return apply_filters( "sanitize_option_{$option}", $value, $option, $value );
	}

	public function test_every_field_of_the_form_is_a_registered_setting_of_one_group(): void {
		$settings = $this->registered();
		$this->assertSame(
			array(
				'babilim_manual_locales',
				'babilim_auto_install',
				'babilim_toolbar_report',
				'babilim_share_errors',
				'babilim_keep_custom_on_purge',
				'babilim_delete_data_on_uninstall',
			),
			array_keys( $settings )
		);
		foreach ( $settings as $name => $args ) {
			$this->assertSame( 'babilim_settings', $args['group'], $name );
			$this->assertTrue( is_callable( $args['sanitize_callback'] ), $name );
			$this->assertFalse( $args['show_in_rest'], $name );
		}
	}

	/**
	 * @return array<string,array{0:string,1:mixed,2:mixed}>
	 */
	public static function checkbox_values(): array {
		$rows = array();
		foreach ( array( 'babilim_auto_install', 'babilim_toolbar_report', 'babilim_keep_custom_on_purge' ) as $option ) {
			$rows[ $option . ' on' ]      = array( $option, 'on', 1 );
			$rows[ $option . ' missing' ] = array( $option, null, 0 );
			$rows[ $option . ' other' ]   = array( $option, 'yes', 0 );
			$rows[ $option . ' array' ]   = array( $option, array( 'on' ), 0 );
		}
		$rows['babilim_share_errors on']      = array( 'babilim_share_errors', 'on', '1' );
		$rows['babilim_share_errors missing'] = array( 'babilim_share_errors', null, '0' );
		$rows['babilim_share_errors array']   = array( 'babilim_share_errors', array( 'on' ), '0' );
		$rows['babilim_delete_data on']       = array( 'babilim_delete_data_on_uninstall', 'on', true );
		$rows['babilim_delete_data missing']  = array( 'babilim_delete_data_on_uninstall', null, false );
		return $rows;
	}

	/**
	 * @dataProvider checkbox_values
	 */
	public function test_each_checkbox_is_stored_in_the_shape_the_plugin_reads( string $option, $posted, $stored ): void {
		$this->assertSame( $stored, $this->sanitize( $option, $posted ) );
	}

	public function test_a_value_already_in_its_stored_shape_is_kept(): void {
		// update_option() from elsewhere (the purge turning automatic
		// installs off, "Always share") passes the stored shape itself.
		$this->assertSame( 0, $this->sanitize( 'babilim_auto_install', 0 ) );
		$this->assertSame( 1, $this->sanitize( 'babilim_auto_install', 1 ) );
		$this->assertSame( '1', $this->sanitize( 'babilim_share_errors', '1' ) );
		$this->assertSame( '0', $this->sanitize( 'babilim_share_errors', '0' ) );
	}

	public function test_the_language_block_becomes_the_manual_list_and_the_stopped_list(): void {
		update_option( 'babilim_manual_locales', array( 'vi' ) );
		$GLOBALS['babilim_test_available_languages'] = array( 'fr_FR' );
		$value = $this->sanitize(
			'babilim_manual_locales',
			array(
				'form'    => '1',
				'checked' => array( 'de_DE', 'pt_BR', '../x', 'a b', array( 'nested' ) ),
				'new'     => 'ja',
			)
		);
		$this->assertSame( array( 'pt_BR', 'ja' ), $value, 'detected languages and unsafe values stay out of the manual list' );
		$this->assertSame( array( 'fr_FR' ), get_option( 'babilim_disabled_locales' ), 'the unchecked detected language is stopped' );
	}

	public function test_a_list_already_in_its_stored_shape_is_kept_as_a_manual_list(): void {
		// Babilim_Inventory::set_manual_locales() stores a plain list.
		$this->assertSame( array( 'pt_BR', 'vi' ), $this->sanitize( 'babilim_manual_locales', array( 'pt_BR', 'vi', '../x' ) ) );
	}

	public function test_an_invalid_new_language_keeps_the_stored_list_and_reports_why(): void {
		update_option( 'babilim_manual_locales', array( 'pt_BR' ) );
		$value = $this->sanitize( 'babilim_manual_locales', array( 'form' => '1', 'checked' => array(), 'new' => 'de/DE' ) );
		$this->assertSame( array( 'pt_BR' ), $value );
		$this->assertSame( array( 'invalid_locale' ), array_column( get_settings_errors( 'babilim' ), 'code' ) );
	}

	public function test_an_invalid_new_language_saves_none_of_the_other_settings(): void {
		update_option( 'babilim_auto_install', 1 );
		update_option( 'babilim_keep_custom_on_purge', 1 );
		update_option( 'babilim_share_errors', '1', false );
		update_option( 'babilim_delete_data_on_uninstall', true );
		update_option( 'babilim_disabled_locales', array( 'fr_FR' ) );
		babilim_test_submit_settings( $this->admin(), babilim_test_settings_fields( array( 'locales' => array(), 'new_locale' => 'de DE' ) ) );
		$this->assertSame( 1, get_option( 'babilim_auto_install' ) );
		$this->assertSame( 1, get_option( 'babilim_keep_custom_on_purge' ) );
		$this->assertSame( '1', get_option( 'babilim_share_errors' ) );
		$this->assertTrue( get_option( 'babilim_delete_data_on_uninstall' ) );
		$this->assertSame( array( 'fr_FR' ), get_option( 'babilim_disabled_locales' ) );
		$this->assertSame( array( 'invalid_locale' ), array_column( get_settings_errors(), 'code' ), 'no "Settings saved." either' );

		// The next save is not refused.
		babilim_test_submit_settings( $this->admin(), babilim_test_settings_fields( array( 'locales' => array( 'de_DE' ) ) ) );
		$this->assertSame( 0, get_option( 'babilim_auto_install' ) );
	}

	public function test_always_share_from_the_notice_queues_each_waiting_report_once(): void {
		$this->registered();
		update_option( 'babilim_share_errors', '0', false );
		update_option( Babilim_Error_Reporter::OPTION_AWAITING, array( array( 'event_id' => str_repeat( 'a', 32 ), 'error_code' => 'download_failed', 'action' => 'download', 'origin_id' => 'site.sync', 'at' => '2026-10-01T00:00:00+00:00', 'attempts' => 0 ) ), false );
		$queue_writes = 0;
		add_filter(
			'pre_update_option_' . Babilim_Error_Reporter::OPTION_QUEUE,
			static function ( $value ) use ( &$queue_writes ) {
				++$queue_writes;
				return $value;
			}
		);
		Babilim_Error_Reporter::for_site()->share_always();
		$this->assertSame( '1', get_option( 'babilim_share_errors' ) );
		$this->assertCount( 1, get_option( Babilim_Error_Reporter::OPTION_QUEUE ) );
		$this->assertSame( array(), get_option( Babilim_Error_Reporter::OPTION_AWAITING ) );
		$this->assertSame( 1, $queue_writes, 'the settings hook does not run share_always() a second time inside the first' );
	}

	public function test_a_first_save_of_the_language_block_survives_the_second_sanitize_of_add_option(): void {
		// No babilim_manual_locales yet: update_option() hands the sanitized
		// list to add_option(), which sanitizes it again (as core does). The
		// second pass gets a plain list and must keep it.
		$this->assertFalse( get_option( 'babilim_manual_locales' ) );
		$calls = 0;
		$this->registered();
		add_filter(
			'sanitize_option_babilim_manual_locales',
			static function ( $value ) use ( &$calls ) {
				++$calls;
				return $value;
			},
			20
		);
		update_option( 'babilim_manual_locales', array( 'form' => '1', 'checked' => array( 'de_DE', 'pt_BR' ), 'new' => 'ja' ) );
		$this->assertSame( 2, $calls, 'sanitized once by update_option() and once by add_option()' );
		$this->assertSame( array( 'pt_BR', 'ja' ), get_option( 'babilim_manual_locales' ) );
	}

	public function test_the_share_errors_hooks_fire_with_the_shared_options_table_too(): void {
		// The shared options mode is chosen when the bootstrap loads, so it
		// runs in a subprocess of its own (tests/support/shared-options-share-hooks.php).
		$dir = BABILIM_TEST_TMP_ROOT . '/shared-options-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/options', 0777, true );
		try {
			$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/support/shared-options-share-hooks.php' ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) ) . ' ' . escapeshellarg( $dir );
			exec( $cmd . ' 2>&1', $out, $rc );
			$this->assertSame( 0, $rc, implode( "\n", $out ) );
			$this->assertSame( array( 'shared=1', 'added: awaiting=0 queue=1', 'updated: queue=0' ), $out );
		} finally {
			babilim_test_rmtree( $dir );
		}
	}

	public function test_saving_the_form_runs_through_options_php_with_one_saved_notice(): void {
		$fields = babilim_test_settings_fields( array( 'locales' => array( 'de_DE' ), 'auto_install' => 'on', 'toolbar_report' => 'on' ) );
		babilim_test_submit_settings( $this->admin(), $fields );
		$this->assertSame( 1, get_option( 'babilim_auto_install' ) );
		$this->assertSame( 1, get_option( 'babilim_toolbar_report' ) );
		$this->assertSame( 0, get_option( 'babilim_keep_custom_on_purge' ) );
		$this->assertSame( '0', get_option( 'babilim_share_errors' ) );
		$this->assertFalse( get_option( 'babilim_delete_data_on_uninstall' ) );
		$this->assertSame( array( 'settings_updated' ), array_column( get_settings_errors(), 'code' ), 'core says Settings saved. once' );
		$this->assertSame( admin_url( 'admin.php?page=babilim-settings&tab=general' ) . '&settings-updated=true', $GLOBALS['babilim_test_redirects'][0]['location'] );
	}

	public function test_the_settings_need_the_capability_and_the_nonce(): void {
		update_option( 'babilim_auto_install', 1 );
		$GLOBALS['babilim_test_current_user_can'] = false;
		try {
			babilim_test_submit_settings( $this->admin(), babilim_test_settings_fields() );
			$this->fail( 'Expected wp_die().' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertSame( 1, get_option( 'babilim_auto_install' ) );
		}

		$GLOBALS['babilim_test_current_user_can'] = true;
		try {
			babilim_test_submit_settings( $this->admin(), babilim_test_settings_fields() + array( '_wpnonce' => 'nonce-babilim_save_settings' ) );
			$this->fail( 'Expected wp_die().' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertSame( 1, get_option( 'babilim_auto_install' ) );
		}
	}

	public function test_the_capability_for_the_group_is_manage_options(): void {
		$this->registered();
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_babilim_settings', 'manage_options' ) );
	}

	public function test_the_old_admin_post_handler_is_gone(): void {
		$this->assertFalse( ( new ReflectionClass( Babilim_Admin::class ) )->hasMethod( 'dispatch_babilim_save_settings' ) );
		$actions = ( new ReflectionClassConstant( Babilim_Admin::class, 'ACTIONS' ) )->getValue();
		$this->assertNotContains( 'babilim_save_settings', $actions );
	}

	public function test_an_options_php_save_never_touches_options_outside_the_group(): void {
		// options.php only writes the options registered in the posted
		// group; a posted field that is not one of them (here `consent`)
		// is ignored.
		update_option( 'babilim_consent', true );
		babilim_test_submit_settings( $this->admin(), babilim_test_settings_fields( array( 'consent' => '' ) ) );
		$this->assertTrue( (bool) get_option( 'babilim_consent' ), 'consent is not a setting of the form' );
	}
}
