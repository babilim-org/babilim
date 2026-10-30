<?php
/**
 * A component change (activate, deactivate, upgrade, theme switch)
 * schedules one background check that only syncs.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Sync_On_Component_Change extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
	}

	protected function tearDown(): void {
		Babilim::reset_for_tests();
	}

	private function once_events(): array {
		return array_values(
			array_filter(
				$GLOBALS['babilim_test_single_events_scheduled'],
				static function ( $e ) {
					return Babilim::SYNC_ONCE_HOOK === $e['hook'];
				}
			)
		);
	}

	private function fire_all_triggers(): void {
		$b = Babilim::instance();
		$b->on_activated_plugin( 'acme/acme.php' );
		$b->on_deactivated_plugin( 'acme/acme.php' );
		$b->on_upgrader_process_complete( null, array( 'type' => 'plugin' ) );
		$b->rebuild_default_index_all_locales();
	}

	public function test_nothing_is_scheduled_without_consent(): void {
		Babilim::instance()->init();
		$this->fire_all_triggers();
		$this->assertSame( array(), $this->once_events() );
	}

	public function test_a_component_change_with_consent_schedules_one_check_in_30_seconds(): void {
		update_option( 'babilim_consent', true );
		$before = time();
		Babilim::instance()->on_activated_plugin( 'acme/acme.php' );

		$events = $this->once_events();
		$this->assertCount( 1, $events );
		$this->assertGreaterThanOrEqual( $before + 30, $events[0]['timestamp'] );
		$this->assertLessThanOrEqual( time() + 30, $events[0]['timestamp'] );
	}

	public function test_a_check_that_is_already_scheduled_is_not_scheduled_again(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_cron_scheduled'][ Babilim::SYNC_ONCE_HOOK ] = time() + 10;
		Babilim::instance()->on_deactivated_plugin( 'acme/acme.php' );
		$this->assertSame( array(), $this->once_events() );
	}

	public function test_each_trigger_schedules_a_check(): void {
		update_option( 'babilim_consent', true );
		$b = Babilim::instance();
		$calls = array(
			static function () use ( $b ) { $b->on_activated_plugin( 'a/a.php' ); },
			static function () use ( $b ) { $b->on_deactivated_plugin( 'a/a.php' ); },
			static function () use ( $b ) { $b->on_upgrader_process_complete( null, array( 'type' => 'plugin' ) ); },
			static function () use ( $b ) { $b->on_upgrader_process_complete( null, array( 'type' => 'theme' ) ); },
			static function () use ( $b ) { $b->schedule_sync_once(); },
		);
		foreach ( $calls as $i => $call ) {
			babilim_test_reset();
			update_option( 'babilim_consent', true );
			$call();
			$this->assertCount( 1, $this->once_events(), 'trigger #' . $i );
		}
	}

	public function test_a_core_update_does_not_schedule_a_check(): void {
		update_option( 'babilim_consent', true );
		Babilim::instance()->on_upgrader_process_complete( null, array( 'type' => 'core' ) );
		$this->assertSame( array(), $this->once_events() );
	}

	public function test_switch_theme_also_schedules_a_check(): void {
		update_option( 'babilim_consent', true );
		Babilim::instance()->init();
		$callbacks = array_filter(
			$GLOBALS['babilim_test_actions']['switch_theme'] ?? array(),
			static function ( $h ) {
				return is_array( $h['callback'] ) && 'schedule_sync_once' === $h['callback'][1];
			}
		);
		$this->assertCount( 1, $callbacks );
	}

	public function test_upgrader_process_complete_still_has_one_listener(): void {
		Babilim::instance()->init();
		$this->assertCount( 1, $GLOBALS['babilim_test_actions']['upgrader_process_complete'] ?? array() );
	}

	public function test_the_scheduled_check_only_syncs_and_downloads_nothing(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_x', 'token' => 'tok_x' ), false );
		$inventory = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'components' => array(),
					'updates'    => array( array( 'kind' => 'plugin', 'slug' => 'wired', 'locale' => 'de_DE', 'build_id' => 'bd_000000000009', 'build_no' => 2 ) ),
					'license'    => array(),
					'quota'      => array(),
					'features'   => array(),
					'contract'   => '1.3.0',
				)
			),
			'headers'  => array(),
		);
		$GLOBALS['babilim_test_wp_remote_responses'] = array( $inventory );
		$GLOBALS['babilim_test_wp_remote_requests']  = array();
		$GLOBALS['babilim_test_get_plugins_return']  = array( 'wired/wired.php' => array( 'Name' => 'Wired', 'Version' => '1.0', 'TextDomain' => 'wired' ) );

		Babilim::instance()->init();
		$handlers = array_filter(
			$GLOBALS['babilim_test_actions'][ Babilim::SYNC_ONCE_HOOK ] ?? array(),
			static function ( $h ) {
				return is_array( $h['callback'] ) && 'run_sync_once' === $h['callback'][1];
			}
		);
		$this->assertCount( 1, $handlers, 'the one-off hook runs run_sync_once, not run_sync' );
		$this->assertCount( 0, array_filter( $GLOBALS['babilim_test_actions'][ Babilim::SYNC_ONCE_HOOK ], static function ( $h ) {
			return is_array( $h['callback'] ) && 'run_sync' === $h['callback'][1];
		} ) );

		Babilim::instance()->run_sync_once();

		$urls = array_column( $GLOBALS['babilim_test_wp_remote_requests'], 'url' );
		$this->assertNotEmpty( $urls );
		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( '/v1/downloads', $url );
		}
	}

	public function test_after_disabling_the_regular_sync_makes_no_request(): void {
		update_option( 'babilim_consent', false );
		$GLOBALS['babilim_test_wp_remote_requests'] = array();
		Babilim::instance()->run_sync();
		$this->assertCount( 0, $GLOBALS['babilim_test_wp_remote_requests'] );
	}

	public function test_deactivating_babilim_itself_schedules_nothing_and_clears_a_pending_check(): void {
		update_option( 'babilim_consent', true );
		Babilim::instance()->on_activated_plugin( 'acme/acme.php' );
		$this->assertCount( 1, $this->once_events() );
		$GLOBALS['babilim_test_cron_scheduled'][ Babilim::SYNC_ONCE_HOOK ] = time() + 30;

		babilim_deactivate();
		$this->assertContains( Babilim::SYNC_ONCE_HOOK, $GLOBALS['babilim_test_cron_cleared'] );
		$this->assertFalse( wp_next_scheduled( Babilim::SYNC_ONCE_HOOK ) );

		$GLOBALS['babilim_test_single_events_scheduled'] = array();
		Babilim::instance()->on_deactivated_plugin( plugin_basename( BABILIM_FILE ) );
		$this->assertSame( array(), $this->once_events(), 'core fires deactivated_plugin after deactivate_{plugin}' );
	}
}
