<?php
/**
 * Babilim_Cell: the (component, locale) display/action model.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Cell extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	/**
	 * @param array<string,mixed> $extra Extra entry keys.
	 * @return array<string,mixed>
	 */
	private static function entry( string $status, array $extra = array() ): array {
		return array_merge( array( 'status' => $status ), $extra );
	}

	private static function record( string $build_id = 'bd_aaaaaaaaaaaa' ): array {
		return array( 'build_id' => $build_id );
	}

	/**
	 * One row per line of the cell table.
	 *
	 * @return array<string,array{0:array,1:bool,2:bool,3:?array,4:array}>
	 */
	public static function table_rows(): array {
		$built = array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_at' => '2026-09-24T10:00:00Z', 'built_for_version' => '2.4.0' );
		$newer = static function ( string $status ) use ( $built ): array {
			return self::entry( 'babilim_installed', $built + array( 'newer_version' => array( 'version' => '2.5.0', 'status' => $status ) ) );
		};

		return array(
			'available'                 => array( self::entry( 'babilim_available', $built ), false, true, null, array( 'babilim', false, false, 'babilim_download', 'Download', null, 'Available · September 24, 2026', 'bd_aaaaaaaaaaaa' ) ),
			'installed current'         => array( self::entry( 'babilim_installed', $built ), false, true, self::record(), array( 'babilim', true, false, null, '', 'downloaded', 'Installed · September 24, 2026', 'bd_aaaaaaaaaaaa' ) ),
			'installed + update'        => array( self::entry( 'babilim_installed', $built + array( 'update_available' => true ) ), true, true, self::record( 'bd_aaaaaaaaaaaa' ), array( 'babilim', true, true, 'babilim_download', 'Update', null, 'Update available', 'bd_aaaaaaaaaaaa' ) ),
			'newer queued'              => array( $newer( 'queued' ), false, true, self::record(), array( 'babilim', true, false, null, '', 'scheduled', 'Installed · built for 2.4.0, new version scheduled', 'bd_aaaaaaaaaaaa' ) ),
			'newer running'             => array( $newer( 'running' ), false, true, self::record(), array( 'babilim', true, false, null, '', 'translating', 'Installed · built for 2.4.0, translating the new version…', 'bd_aaaaaaaaaaaa' ) ),
			'newer requestable'         => array( $newer( 'requestable' ), false, true, self::record(), array( 'babilim', true, false, 'babilim_request', 'Request newer translation', null, 'Installed · built for 2.4.0', 'bd_aaaaaaaaaaaa' ) ),
			'newer failed'              => array( $newer( 'failed' ), false, true, self::record(), array( 'babilim', true, false, 'babilim_request', 'Retry request', null, 'Installed · built for 2.4.0, new version failed', 'bd_aaaaaaaaaaaa' ) ),
			'newer license_required'    => array( $newer( 'license_required' ), false, true, self::record(), array( 'babilim', true, false, null, '', 'license_required', 'Installed · built for 2.4.0, new version needs a paid license', 'bd_aaaaaaaaaaaa' ) ),
			'newer quota_exceeded'      => array( $newer( 'quota_exceeded' ), false, true, self::record(), array( 'babilim', true, false, null, '', 'quota_exceeded', 'Installed · built for 2.4.0, free quota used', 'bd_aaaaaaaaaaaa' ) ),
			'wporg'                     => array( self::entry( 'wporg' ), false, true, null, array( 'wporg', false, false, 'babilim_request', 'Request translation', null, 'WordPress.org', '' ) ),
			'bundled plugin'            => array( self::entry( 'bundled' ), false, true, null, array( 'bundled', false, false, null, '', 'bundled', 'Bundled with the plugin', '' ) ),
			'bundled theme'             => array( self::entry( 'bundled', array( 'kind' => 'theme' ) ), false, true, null, array( 'bundled', false, false, null, '', 'bundled', 'Bundled with the theme', '' ) ),
			'requestable'               => array( self::entry( 'requestable' ), false, true, null, array( 'none', false, false, 'babilim_request', 'Request translation', null, 'No translation', '' ) ),
			'requestable rejected'      => array( self::entry( 'requestable', array( 'reason' => 'source_rejected' ) ), false, true, null, array( 'none', false, false, 'babilim_request', 'Retry request', null, 'Upload was not accepted', '' ) ),
			'requestable expired'       => array( self::entry( 'requestable', array( 'reason' => 'upload_expired' ) ), false, true, null, array( 'none', false, false, 'babilim_request', 'Retry request', null, 'Upload expired before it finished', '' ) ),
			'requestable cancelled'     => array( self::entry( 'requestable', array( 'reason' => 'upload_cancelled' ) ), false, true, null, array( 'none', false, false, 'babilim_request', 'Request translation', null, 'No translation', '' ) ),
			'requestable other reason'  => array( self::entry( 'requestable', array( 'reason' => 'something_else' ) ), false, true, null, array( 'none', false, false, 'babilim_request', 'Request translation', null, 'No translation', '' ) ),
			'queued'                    => array( self::entry( 'queued' ), false, true, null, array( 'none', false, false, null, '', 'scheduled', 'Scheduled, waiting to be processed', '' ) ),
			'running'                   => array( self::entry( 'running' ), false, true, null, array( 'none', false, false, null, '', 'translating', 'Translating…', '' ) ),
			'failed'                    => array( self::entry( 'failed' ), false, true, null, array( 'none', false, false, 'babilim_request', 'Retry request', null, 'Translation failed', '' ) ),
			'quota_exceeded'            => array( self::entry( 'quota_exceeded' ), false, true, null, array( 'none', false, false, null, '', 'quota_exceeded', 'Free quota used', '' ) ),
			'license_required'          => array( self::entry( 'license_required' ), false, true, null, array( 'none', false, false, null, '', 'license_required', 'Needs a paid license', '' ) ),
			'unsupported'               => array( self::entry( 'unsupported' ), false, true, null, array( 'none', false, false, null, '', 'unsupported', 'Not supported', '' ) ),
			'unknown status'            => array( self::entry( 'something_new' ), false, true, null, array( 'unknown', false, false, null, '', 'unknown', 'Status unknown', '' ) ),
			'empty entry'               => array( array(), false, true, null, array( 'unknown', false, false, null, '', 'unknown', 'Status unknown', '' ) ),
			'manifest, state missing'   => array( array(), false, true, self::record(), array( 'babilim', true, false, null, '', 'downloaded', 'Installed', 'bd_aaaaaaaaaaaa' ) ),
			'manifest, state available' => array( self::entry( 'babilim_available', $built ), false, true, self::record(), array( 'babilim', true, false, null, '', 'downloaded', 'Installed', 'bd_aaaaaaaaaaaa' ) ),
			'no write: available'       => array( self::entry( 'babilim_available', $built ), false, false, null, array( 'babilim', false, false, null, '', 'network_capability', 'Available · September 24, 2026', 'bd_aaaaaaaaaaaa' ) ),
			'no write: update'          => array( self::entry( 'babilim_installed', $built + array( 'update_available' => true ) ), true, false, self::record( 'bd_aaaaaaaaaaaa' ), array( 'babilim', true, true, null, '', 'network_capability', 'Update available', 'bd_aaaaaaaaaaaa' ) ),
		);
	}

	/**
	 * @dataProvider table_rows
	 *
	 * @param array      $entry           Locale entry.
	 * @param bool       $has_update      Update signal.
	 * @param bool       $can_write       Write capability.
	 * @param array|null $manifest_record Manifest record.
	 * @param array      $expected        source, installed, is_update, action, label, blocked, text, title.
	 */
	public function test_table_row( array $entry, bool $has_update, bool $can_write, ?array $manifest_record, array $expected ): void {
		$cell = Babilim_Cell::from_entry( $entry, 'de_DE', $has_update, $can_write, $manifest_record );

		$this->assertSame(
			array(
				'source'         => $expected[0],
				'installed'      => $expected[1],
				'is_update'      => $expected[2],
				'action'         => $expected[3],
				'action_label'   => $expected[4],
				'blocked_reason' => $expected[5],
				'text'           => $expected[6],
				'title'          => $expected[7],
			),
			array_intersect_key( $cell, array_flip( array( 'source', 'installed', 'is_update', 'action', 'action_label', 'blocked_reason', 'text', 'title' ) ) )
		);
		$this->assertSame( array( 'source', 'installed', 'is_update', 'action', 'action_label', 'busy_label', 'blocked_reason', 'text', 'title', 'date', 'hardcoded_warning' ), array_keys( $cell ) );
	}

	public function test_installed_comes_only_from_the_manifest_record(): void {
		foreach ( array( 'babilim_installed', 'babilim_available', 'wporg', 'requestable', 'failed', '' ) as $status ) {
			$this->assertFalse( Babilim_Cell::from_entry( self::entry( $status, array( 'build_id' => 'bd_aaaaaaaaaaaa' ) ), 'de_DE', false, true, null )['installed'], $status );
			$this->assertTrue( Babilim_Cell::from_entry( self::entry( $status, array( 'build_id' => 'bd_aaaaaaaaaaaa' ) ), 'de_DE', false, true, self::record() )['installed'], $status );
		}
	}

	public function test_state_says_installed_but_manifest_has_nothing_is_not_installed(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_at' => '2026-09-24T10:00:00Z' ) ), 'de_DE', false, true, null );

		$this->assertFalse( $cell['installed'] );
		$this->assertSame( 'babilim_download', $cell['action'] );
	}

	public function test_a_degraded_record_is_not_installed_and_offers_download(): void {
		$record = self::record() + array( 'degraded_at' => '2026-10-01T00:00:00Z' );
		foreach ( array( 'babilim_installed', 'babilim_available', '' ) as $status ) {
			$cell = Babilim_Cell::from_entry( self::entry( $status, array( 'build_id' => 'bd_aaaaaaaaaaaa' ) ), 'de_DE', false, true, $record );

			$this->assertFalse( $cell['installed'], $status );
			$this->assertSame( 'babilim_download', $cell['action'], $status );
			$this->assertSame( 'Download', $cell['action_label'], $status );
			$this->assertFalse( $cell['is_update'], $status );
			$this->assertSame( 'bd_aaaaaaaaaaaa', $cell['title'], $status );
			$this->assertSame( 'Removed after a failed integrity check', $cell['text'], $status );
			$this->assertStringNotContainsString( 'Downloaded', $cell['action_label'] . $cell['text'], $status );
		}
	}

	public function test_a_degraded_record_without_write_capability_blocks_the_download(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'babilim_installed' ), 'de_DE', false, false, self::record() + array( 'degraded_at' => '2026-10-01T00:00:00Z' ) );

		$this->assertFalse( $cell['installed'] );
		$this->assertNull( $cell['action'] );
		$this->assertSame( 'network_capability', $cell['blocked_reason'] );
	}

	public function test_queued_and_running_use_different_text(): void {
		$queued  = Babilim_Cell::from_entry( self::entry( 'queued' ), 'de_DE', false, true, null );
		$running = Babilim_Cell::from_entry( self::entry( 'running' ), 'de_DE', false, true, null );

		$this->assertNotSame( $queued['text'], $running['text'] );
	}

	public function test_update_wins_over_a_coexisting_newer_version(): void {
		$entry = self::entry(
			'babilim_installed',
			array(
				'build_id'          => 'bd_newnewnewnew',
				'built_for_version' => '2.4.0',
				'newer_version'     => array( 'version' => '2.5.0', 'status' => 'requestable' ),
			)
		);

		$cell = Babilim_Cell::from_entry( $entry, 'de_DE', true, true, self::record( 'bd_newnewnewnew' ) );

		$this->assertTrue( $cell['is_update'] );
		$this->assertSame( 'babilim_download', $cell['action'] );
		$this->assertSame( 'Update', $cell['action_label'] );
		$this->assertSame( 'Update available', $cell['text'] );
	}

	public function test_an_update_for_the_build_already_installed_is_not_an_update(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_at' => '2026-09-24T10:00:00Z', 'update_build_id' => 'bd_aaaaaaaaaaaa' ) );

		$cell = Babilim_Cell::from_entry( $entry, 'de_DE', true, true, self::record( 'bd_aaaaaaaaaaaa' ) );

		$this->assertFalse( $cell['is_update'] );
		$this->assertNull( $cell['action'] );
		$this->assertSame( 'downloaded', $cell['blocked_reason'] );
	}

	public function test_an_update_for_a_different_build_is_an_update(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'update_build_id' => 'bd_bbbbbbbbbbbb' ) );

		$this->assertTrue( Babilim_Cell::from_entry( $entry, 'de_DE', true, true, self::record( 'bd_aaaaaaaaaaaa' ) )['is_update'] );
	}

	public function test_a_missing_built_at_falls_back_to_plain_text(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'babilim_available', array( 'build_id' => 'bd_aaaaaaaaaaaa' ) ), 'de_DE', false, true, null );

		$this->assertSame( 'Available', $cell['text'] );
		$this->assertSame( 'babilim_download', $cell['action'] );
	}

	public function test_an_unparseable_built_at_falls_back_to_plain_text(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'babilim_available', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_at' => 'not a date' ) ), 'de_DE', false, true, null );

		$this->assertSame( 'Available', $cell['text'] );
	}

	public function test_available_without_a_build_id_offers_no_download(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'babilim_available', array( 'built_at' => '2026-09-24T10:00:00Z' ) ), 'de_DE', false, true, null );

		$this->assertNull( $cell['action'] );
	}

	public function test_the_title_carries_the_build_id(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'babilim_available', array( 'build_id' => 'bd_5e4bd19a94a4', 'built_at' => '2026-09-24T10:00:00Z' ) ), 'de_DE', false, true, null );

		$this->assertSame( 'bd_5e4bd19a94a4', $cell['title'] );
	}

	public function test_only_babilim_source_cells_say_babilim(): void {
		foreach ( array( 'wporg', 'bundled', 'requestable', 'queued', 'running', 'failed', 'quota_exceeded', 'license_required', 'unsupported', 'weird' ) as $status ) {
			$cell = Babilim_Cell::from_entry( self::entry( $status ), 'de_DE', false, true, null );
			$this->assertNotSame( 'babilim', $cell['source'], $status );
			$this->assertStringNotContainsString( 'Babilim', $cell['text'], $status );
		}
	}

	public function test_cells_without_a_write_capability_never_offer_download_or_update(): void {
		foreach ( self::table_rows() as $name => $row ) {
			$cell = Babilim_Cell::from_entry( $row[0], 'de_DE', $row[1], false, $row[3] );
			$this->assertNotSame( 'babilim_download', $cell['action'], $name );
		}
	}

	public function test_busy_labels_exist_for_every_action(): void {
		foreach ( self::table_rows() as $name => $row ) {
			$cell = Babilim_Cell::from_entry( $row[0], 'de_DE', $row[1], $row[2], $row[3] );
			if ( null !== $cell['action'] ) {
				$this->assertNotSame( '', $cell['busy_label'], $name );
				$this->assertNotSame( '', $cell['action_label'], $name );
			} else {
				$this->assertNotNull( $cell['blocked_reason'], $name );
			}
		}
	}

	/**
	 * Every newer-version wording is one whole sentence, also when the
	 * build does not say which version it was made for.
	 */
	public function test_newer_wording_without_a_known_version(): void {
		$expected = array(
			'queued'           => 'Installed · built for a different version, new version scheduled',
			'running'          => 'Installed · built for a different version, translating the new version…',
			'requestable'      => 'Installed · built for a different version',
			'failed'           => 'Installed · built for a different version, new version failed',
			'license_required' => 'Installed · built for a different version, new version needs a paid license',
			'quota_exceeded'   => 'Installed · built for a different version, free quota used',
		);
		foreach ( $expected as $status => $text ) {
			$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'newer_version' => array( 'version' => '2.5.0', 'status' => $status ) ) );
			$cell  = Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record() );
			$this->assertSame( $text, $cell['text'], $status );
		}
	}

	public function test_requestable_newer_names_both_versions_when_both_are_known(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_for_version' => '2.4.0', 'newer_version' => array( 'version' => '2.5.0', 'status' => 'requestable' ) ) );
		$cell  = Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record(), '2.5.0' );
		$this->assertSame( 'Installed · built for 2.4.0, this site has 2.5.0', $cell['text'] );
		$this->assertSame( 'babilim_request', $cell['action'] );

		// Same version on both sides: nothing to contrast, keep the short wording.
		$same = Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record(), '2.4.0' );
		$this->assertSame( 'Installed · built for 2.4.0', $same['text'] );
	}

	public function test_requestable_newer_keeps_the_old_wording_when_a_version_is_unknown(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_for_version' => '2.4.0', 'newer_version' => array( 'version' => '2.5.0', 'status' => 'requestable' ) ) );
		$this->assertSame( 'Installed · built for 2.4.0', Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record() )['text'] );
		$this->assertSame( 'Installed · built for 2.4.0', Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record(), '' )['text'] );

		$entry['built_for_version'] = '';
		$this->assertSame( 'Installed · built for a different version', Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record(), '2.5.0' )['text'] );
	}

	public function test_other_newer_states_ignore_the_site_version(): void {
		foreach ( array( 'queued' => 'Installed · built for 2.4.0, new version scheduled', 'failed' => 'Installed · built for 2.4.0, new version failed' ) as $status => $text ) {
			$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_for_version' => '2.4.0', 'newer_version' => array( 'version' => '2.5.0', 'status' => $status ) ) );
			$this->assertSame( $text, Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record(), '2.5.0' )['text'], $status );
		}
	}

	public function test_newer_wording_is_not_built_from_pieces(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../admin/class-babilim-cell.php' );
		$this->assertSame( 0, preg_match( '/\$base\s*\./', $source ), 'no sentence is assembled from a base and a tail' );
	}

	public function test_an_installed_cell_whose_manifest_build_differs_from_the_state_build_shows_no_build_date(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_at' => '2026-09-24T10:00:00Z' ) );

		$cell = Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record( 'bd_bbbbbbbbbbbb' ) );

		$this->assertSame( 'Installed', $cell['text'] );
		$this->assertSame( 'bd_bbbbbbbbbbbb', $cell['title'] );
	}

	public function test_an_installed_cell_whose_manifest_build_is_the_state_build_still_shows_its_date(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_at' => '2026-09-24T10:00:00Z' ) );

		$cell = Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record( 'bd_aaaaaaaaaaaa' ) );

		$this->assertSame( 'Installed · September 24, 2026', $cell['text'] );
	}

	public function test_a_queued_cell_with_an_upload_waiting_offers_the_request_button_with_upload_wording(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'queued', array( 'upload_pending' => true ) ), 'fr_FR', false, true, null );
		$this->assertSame( 'babilim_request', $cell['action'] );
		$this->assertSame( 'Request translation', $cell['action_label'] );
		$this->assertStringContainsString( 'Upload', $cell['text'] );
	}

	public function test_an_installed_cell_with_a_newer_version_upload_waiting_offers_the_same_request_button(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_for_version' => '2.4.0', 'upload_pending' => true, 'newer_version' => array( 'version' => '2.5.0', 'status' => 'queued' ) ) );
		$cell  = Babilim_Cell::from_entry( $entry, 'fr_FR', false, true, self::record() );
		$this->assertSame( 'babilim_request', $cell['action'] );
		$this->assertSame( 'Request translation', $cell['action_label'] );
		$this->assertSame( 'Upload in progress — continue or cancel', $cell['text'] );
		$this->assertTrue( $cell['installed'] );
	}

	public function test_an_installed_cell_with_a_queued_newer_version_but_no_upload_flag_keeps_the_scheduled_wording(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_for_version' => '2.4.0', 'upload_pending' => false, 'newer_version' => array( 'version' => '2.5.0', 'status' => 'queued' ) ) );
		$cell  = Babilim_Cell::from_entry( $entry, 'fr_FR', false, true, self::record() );
		$this->assertNull( $cell['action'] );
		$this->assertSame( 'Installed · built for 2.4.0, new version scheduled', $cell['text'] );
	}

	public function test_upload_pending_without_write_right_is_explained_not_actionable(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'queued', array( 'upload_pending' => true ) ), 'fr_FR', false, false, null );
		$this->assertNull( $cell['action'] );
		$this->assertSame( 'network_capability', $cell['blocked_reason'] );
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_for_version' => '2.4.0', 'upload_pending' => true, 'newer_version' => array( 'version' => '2.5.0', 'status' => 'queued' ) ) );
		$cell  = Babilim_Cell::from_entry( $entry, 'fr_FR', false, false, self::record() );
		$this->assertNull( $cell['action'] );
		$this->assertSame( 'network_capability', $cell['blocked_reason'] );
	}

	public function test_a_queued_cell_without_the_upload_flag_has_no_action(): void {
		$cell = Babilim_Cell::from_entry( self::entry( 'queued', array( 'upload_pending' => false ) ), 'fr_FR', false, true, null );
		$this->assertNull( $cell['action'] );
	}

	// -- hard-coded text warning -------------------------------------------

	private const HARDCODED_WARNING = 'Some of this component\'s text is hard-coded by its author and can\'t be translated by a language pack.';

	public function test_a_downloadable_cell_with_the_flag_carries_the_warning(): void {
		$entry = self::entry( 'babilim_available', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'hardcoded_text_warning' => true ) );
		$this->assertSame( self::HARDCODED_WARNING, Babilim_Cell::from_entry( $entry, 'de_DE', false, true, null )['hardcoded_warning'] );
	}

	public function test_an_installed_cell_with_the_flag_carries_the_warning(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'hardcoded_text_warning' => true ) );
		$this->assertSame( self::HARDCODED_WARNING, Babilim_Cell::from_entry( $entry, 'de_DE', false, true, self::record() )['hardcoded_warning'] );
	}

	public function test_a_downloadable_status_installed_cell_without_a_record_carries_the_warning(): void {
		$entry = self::entry( 'babilim_installed', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'hardcoded_text_warning' => true ) );
		$this->assertSame( self::HARDCODED_WARNING, Babilim_Cell::from_entry( $entry, 'de_DE', false, true, null )['hardcoded_warning'] );
	}

	public function test_a_missing_or_false_flag_gives_no_warning(): void {
		foreach ( array( 'babilim_available', 'babilim_installed' ) as $status ) {
			$base = array( 'build_id' => 'bd_aaaaaaaaaaaa' );
			$this->assertSame( '', Babilim_Cell::from_entry( self::entry( $status, $base ), 'de_DE', false, true, null )['hardcoded_warning'], $status );
			$this->assertSame( '', Babilim_Cell::from_entry( self::entry( $status, $base + array( 'hardcoded_text_warning' => false ) ), 'de_DE', false, true, self::record() )['hardcoded_warning'], $status );
		}
	}

	public function test_a_non_boolean_flag_gives_no_warning(): void {
		foreach ( array( 'true', 1, 'yes', array( true ) ) as $value ) {
			$entry = self::entry( 'babilim_available', array( 'build_id' => 'bd_aaaaaaaaaaaa', 'hardcoded_text_warning' => $value ) );
			$this->assertSame( '', Babilim_Cell::from_entry( $entry, 'de_DE', false, true, null )['hardcoded_warning'] );
		}
	}

	public function test_the_flag_on_any_other_status_gives_no_warning(): void {
		foreach ( array( 'wporg', 'bundled', 'requestable', 'queued', 'running', 'failed', 'quota_exceeded', 'license_required', 'unsupported', 'something_new', '' ) as $status ) {
			$entry = self::entry( $status, array( 'hardcoded_text_warning' => true ) );
			$this->assertSame( '', Babilim_Cell::from_entry( $entry, 'de_DE', false, true, null )['hardcoded_warning'], $status );
		}
	}

	public function test_the_flag_does_not_change_the_rest_of_the_cell(): void {
		$base = array( 'build_id' => 'bd_aaaaaaaaaaaa', 'built_at' => '2026-09-24T10:00:00Z' );
		foreach ( array( 'babilim_available' => null, 'babilim_installed' => self::record() ) as $status => $record ) {
			$plain   = Babilim_Cell::from_entry( self::entry( $status, $base ), 'de_DE', false, true, $record );
			$flagged = Babilim_Cell::from_entry( self::entry( $status, $base + array( 'hardcoded_text_warning' => true ) ), 'de_DE', false, true, $record );
			unset( $flagged['hardcoded_warning'], $plain['hardcoded_warning'] );
			$this->assertSame( $plain, $flagged, $status );
		}
	}
}
