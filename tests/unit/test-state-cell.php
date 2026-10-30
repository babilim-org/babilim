<?php
/**
 * Babilim_State::set_cell_status(): a one-pass merge into one cell.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_State_Cell extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	private function seeded(): Babilim_State {
		$state = new Babilim_State();
		$state->set(
			array(
				'contract'   => '1.4.0',
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'a',
						'locales' => array(
							'de_DE' => array(
								'status'            => 'babilim_installed',
								'build_id'          => 'bd_aaaaaaaaaaaa',
								'built_for_version' => '1.0.0',
								'newer_version'     => array( 'version' => '1.1.0', 'status' => 'requestable' ),
							),
							'fr_FR' => array( 'status' => 'requestable' ),
						),
					),
					array(
						'kind'    => 'plugin',
						'slug'    => 'b',
						'locales' => array( 'de_DE' => array( 'status' => 'requestable' ) ),
					),
				),
			)
		);
		return $state;
	}

	public function test_a_newer_version_patch_changes_only_its_status(): void {
		$state = $this->seeded();

		$this->assertTrue( $state->set_cell_status( 'plugin', 'a', 'de_DE', array( 'newer_version' => array( 'status' => 'queued' ) ) ) );

		$this->assertSame(
			array(
				'status'            => 'babilim_installed',
				'build_id'          => 'bd_aaaaaaaaaaaa',
				'built_for_version' => '1.0.0',
				'newer_version'     => array( 'version' => '1.1.0', 'status' => 'queued' ),
			),
			$state->status( 'plugin', 'a', 'de_DE' )
		);
	}

	public function test_a_status_patch_on_an_uninstalled_cell_changes_the_status(): void {
		$state = $this->seeded();

		$this->assertTrue( $state->set_cell_status( 'plugin', 'a', 'fr_FR', array( 'status' => 'queued' ) ) );

		$this->assertSame( array( 'status' => 'queued' ), $state->status( 'plugin', 'a', 'fr_FR' ) );
	}

	public function test_a_status_patch_keeps_the_cells_other_fields(): void {
		$state = $this->seeded();

		$state->set_cell_status( 'plugin', 'a', 'de_DE', array( 'status' => 'wporg' ) );

		$this->assertSame(
			array(
				'status'            => 'wporg',
				'build_id'          => 'bd_aaaaaaaaaaaa',
				'built_for_version' => '1.0.0',
				'newer_version'     => array( 'version' => '1.1.0', 'status' => 'requestable' ),
			),
			$state->status( 'plugin', 'a', 'de_DE' )
		);
	}

	public function test_other_cells_and_other_state_are_untouched(): void {
		$state  = $this->seeded();
		$before = $state->get();

		$state->set_cell_status( 'plugin', 'a', 'fr_FR', array( 'status' => 'queued' ) );
		$after = $state->get();

		$this->assertSame( $before['contract'], $after['contract'] );
		$this->assertSame( $before['components'][0]['locales']['de_DE'], $after['components'][0]['locales']['de_DE'] );
		$this->assertSame( $before['components'][1], $after['components'][1] );
	}

	public function test_no_override_flag_and_no_timestamp_are_added(): void {
		$state = $this->seeded();

		$state->set_cell_status( 'plugin', 'a', 'fr_FR', array( 'status' => 'running' ) );

		$this->assertSame( array( 'status' ), array_keys( $state->status( 'plugin', 'a', 'fr_FR' ) ) );
		$this->assertArrayNotHasKey( 'fetched_at', $state->get() );
	}

	public function test_a_request_answer_drops_the_stale_reason_of_an_uninstalled_cell(): void {
		$state = new Babilim_State();
		$cell  = array( 'status' => 'requestable', 'reason' => 'source_rejected' );
		foreach ( array( 'queued', 'available' ) as $answer ) {
			$state->set( array( 'components' => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locales' => array( 'fr_FR' => $cell ) ) ) ) );
			$state->record_request_answer( 'plugin', 'a', 'fr_FR', false, Babilim_Result::ok( array( 'status' => $answer, 'build_id' => 'bd_aaaaaaaaaaaa' ) ) );
			$this->assertEmpty( $state->status( 'plugin', 'a', 'fr_FR' )['reason'] ?? null, $answer );
		}
	}

	public function test_a_request_answer_keeps_the_top_level_reason_of_an_installed_cell(): void {
		$state = new Babilim_State();
		$state->set( array( 'components' => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locales' => array( 'fr_FR' => array( 'status' => 'babilim_installed', 'reason' => 'integrity_failed' ) ) ) ) ) );
		$state->record_request_answer( 'plugin', 'a', 'fr_FR', true, Babilim_Result::ok( array( 'status' => 'queued' ) ) );
		$cell = $state->status( 'plugin', 'a', 'fr_FR' );
		$this->assertSame( 'integrity_failed', $cell['reason'] );
		$this->assertSame( 'queued', $cell['newer_version']['status'] );
	}

	public function test_an_unlisted_cell_is_left_alone(): void {
		$state  = $this->seeded();
		$before = $state->get();

		$this->assertFalse( $state->set_cell_status( 'plugin', 'a', 'ja', array( 'status' => 'queued' ) ) );
		$this->assertFalse( $state->set_cell_status( 'theme', 'a', 'de_DE', array( 'status' => 'queued' ) ) );
		$this->assertFalse( $state->set_cell_status( 'plugin', 'zzz', 'de_DE', array( 'status' => 'queued' ) ) );

		$this->assertSame( $before, $state->get() );
	}
}
