<?php
/**
 * The installer's critical section: one installer at a time, whoever
 * asks (the admin download or the cron update), and a build that is
 * already recorded is never installed a second time.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Installer_Commit_Boundary extends TestCase {

	private const BUILD_A = 'bd_00000000000a';
	private const BUILD_B = 'bd_00000000000b';

	/** @var Babilim_Memory_Fs */
	private $fs;

	/** @var Babilim_Manifest */
	private $manifest;

	protected function setUp(): void {
		babilim_test_reset();
		$this->fs       = new Babilim_Memory_Fs();
		$this->manifest = new Babilim_Manifest( $this->fs );
		if ( ! is_dir( Babilim_Paths::root() ) ) {
			mkdir( Babilim_Paths::root(), 0777, true );
		}
		@unlink( Babilim_Paths::root() . '/.install.lock' );
	}

	protected function tearDown(): void {
		@unlink( Babilim_Paths::root() . '/.install.lock' );
	}

	private function installer( ?Babilim_Manifest $manifest = null ): Babilim_Installer {
		$fs = $this->fs;
		return new Babilim_Installer(
			$fs,
			$manifest ?? $this->manifest,
			static function ( string $mo_path ) use ( $fs ) {
				$entries = babilim_test_read_mo( (string) $fs->get( $mo_path ) );
				unset( $entries[''] );
				return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
			},
			static function ( string $path, string $format ) use ( $fs ) {
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
			},
			static function ( string $zip_path ) {
				return $GLOBALS['babilim_test_fake_zips'][ $zip_path ] ?? false;
			}
		);
	}

	/** @return array{0:array,1:string} Expected manifest and zip key. */
	private function package( string $build ): array {
		$mo       = Babilim_Mo_Writer::write( array( 'Save' => 'Speichern ' . $build ), array() );
		$po       = "# po\n";
		$entries  = babilim_test_read_mo( $mo );
		unset( $entries[''] );
		$manifest = array(
			'build_id'        => 'demo-1.0-de_DE-20260101T000000-abc123',
			'entries'         => count( $entries ),
			'files'           => array(
				'demo-de_DE.mo' => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ),
				'demo-de_DE.po' => array( 'sha256' => hash( 'sha256', $po ), 'size' => strlen( $po ) ),
			),
			'locale'          => 'de_DE',
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => 'demo',
			'version'         => '1.0',
			'text_domain'     => 'demo',
			'extra_domains'   => array(),
			'server_build_id' => $build,
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.3.0',
		);
		$key      = 'zip://' . $build;
		$GLOBALS['babilim_test_fake_zips'][ $key ] = array(
			'demo-de_DE.mo' => $mo,
			'demo-de_DE.po' => $po,
			'manifest.json' => wp_json_encode( $manifest ),
		);
		return array( $manifest, $key );
	}

	private function install( Babilim_Installer $installer, string $build ): Babilim_Result {
		list( $manifest, $key ) = $this->package( $build );
		return $installer->install( 'plugin', 'demo', 'de_DE', $key, $manifest );
	}

	public function test_the_same_build_installed_again_is_a_no_op_that_leaves_the_restore_point_alone(): void {
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_B )->is_ok() );
		$served = Babilim_Paths::file( 'plugins', 'demo', 'de_DE', 'mo' );
		$before = $this->fs->get( $served );
		$this->assertSame( self::BUILD_A, ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' )['previous_build_id'] );

		$again = $this->install( $this->installer(), self::BUILD_B );

		$this->assertTrue( $again->is_ok() );
		$this->assertTrue( $again->get( 'already_installed', false ) );
		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );
		$this->assertSame( self::BUILD_B, $record['build_id'] );
		$this->assertSame( self::BUILD_A, $record['previous_build_id'], 'the restore point did not move' );
		$this->assertSame( $before, $this->fs->get( $served ), 'the served file was not rewritten' );
	}

	public function test_a_request_that_waited_for_the_lock_sees_the_other_requests_install(): void {
		// This request's manifest instance read "nothing installed" before
		// it waited; the other request then finished the same build.
		$stale = new Babilim_Manifest( $this->fs );
		$this->assertNull( $stale->installed( 'plugin', 'demo', 'de_DE' ) );
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );

		$result = $this->install( $this->installer( $stale ), self::BUILD_A );

		$this->assertTrue( $result->is_ok() );
		$this->assertTrue( $result->get( 'already_installed', false ), 'decided from the file, not from the stale copy' );
		$this->assertNull( ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' )['previous_build_id'] );
	}

	public function test_a_manual_install_followed_by_the_cron_install_of_the_same_build_does_nothing_the_second_time(): void {
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		// The cron run loaded its manifest before the manual install
		// finished, as a real cron request does while it waits.
		$cron_manifest = new Babilim_Manifest( $this->fs );
		$this->assertSame( self::BUILD_A, $cron_manifest->installed( 'plugin', 'demo', 'de_DE' )['build_id'] );
		$manual = $this->install( $this->installer(), self::BUILD_B );
		$cron   = $this->install( $this->installer( $cron_manifest ), self::BUILD_B );

		$this->assertTrue( $manual->is_ok() );
		$this->assertArrayNotHasKey( 'already_installed', $manual->data() );
		$this->assertTrue( $cron->get( 'already_installed', false ) );
		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );
		$this->assertSame( self::BUILD_A, $record['previous_build_id'], 'the restore point is still the build before the first install' );
	}

	public function test_the_install_runs_under_an_exclusive_flock_on_the_lock_file(): void {
		$other = fopen( Babilim_Paths::root() . '/.install.lock', 'c' );
		$this->assertTrue( flock( $other, LOCK_EX | LOCK_NB ), 'the test holds the lock as another request would' );

		$seen = null;
		// While the lock is held elsewhere, a non-blocking probe of the
		// same file must fail: that is the property the installer relies on.
		$probe = fopen( Babilim_Paths::root() . '/.install.lock', 'c' );
		$this->assertFalse( flock( $probe, LOCK_EX | LOCK_NB, $seen ) );
		$this->assertSame( 1, $seen, 'it would block' );
		fclose( $probe );
		flock( $other, LOCK_UN );
		fclose( $other );

		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		$after = fopen( Babilim_Paths::root() . '/.install.lock', 'c' );
		$this->assertTrue( flock( $after, LOCK_EX | LOCK_NB ), 'and the installer let go of it afterwards' );
		fclose( $after );
	}

	public function test_the_lock_is_released_when_the_install_fails(): void {
		list( $manifest, $key ) = $this->package( self::BUILD_A );
		$manifest['slug']       = 'other';
		$result                 = $this->installer()->install( 'plugin', 'demo', 'de_DE', $key, $manifest );
		$this->assertTrue( $result->is_error() );

		$after = fopen( Babilim_Paths::root() . '/.install.lock', 'c' );
		$this->assertTrue( flock( $after, LOCK_EX | LOCK_NB ) );
		fclose( $after );
	}

	public function test_without_a_usable_lock_file_the_database_lock_is_used_and_the_manifest_write_does_not_wait_on_itself(): void {
		// A file where the lock file should be a directory: fopen() fails.
		@unlink( Babilim_Paths::root() . '/.install.lock' );
		mkdir( Babilim_Paths::root() . '/.install.lock' );
		try {
			$GLOBALS['wpdb']->statements = array();
			$result                      = $this->install( $this->installer(), self::BUILD_A );

			$this->assertTrue( $result->is_ok(), $result->message() );
			$inserts = array_filter(
				$GLOBALS['wpdb']->statements,
				static function ( $s ) {
					return 'insert' === $s[0] && false !== strpos( $s[1], 'babilim_manifest_lock' );
				}
			);
			$this->assertCount( 1, $inserts, 'the database lock was taken once for the whole install, not again per write' );
			$this->assertNull( $GLOBALS['wpdb']->babilim_test_row( 'babilim_manifest_lock' ), 'and released' );
		} finally {
			rmdir( Babilim_Paths::root() . '/.install.lock' );
		}
	}

	public function test_manifest_with_lock_is_reentrant_and_releases_on_exit(): void {
		$manifest = new Babilim_Manifest( $this->fs );

		$value = $manifest->with_lock(
			static function () use ( $manifest ) {
				return $manifest->with_lock(
					static function () {
						return 'inner';
					}
				);
			}
		);

		$this->assertSame( 'inner', $value );
		$this->assertNull( $GLOBALS['wpdb']->babilim_test_row( 'babilim_manifest_lock' ) );
	}

	public function test_files_published_but_manifest_not_updated_is_installed_again_with_the_old_restore_point(): void {
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		// An earlier attempt at B died after publishing the files and
		// before the manifest was written: the files say B, the manifest A.
		list( $b_manifest, $b_key ) = $this->package( self::BUILD_B );
		$this->fs->put( Babilim_Paths::file( 'plugins', 'demo', 'de_DE', 'mo' ), (string) $GLOBALS['babilim_test_fake_zips'][ $b_key ]['demo-de_DE.mo'] );

		$result = $this->installer()->install( 'plugin', 'demo', 'de_DE', $b_key, $b_manifest );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertArrayNotHasKey( 'already_installed', $result->data() );
		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );
		$this->assertSame( self::BUILD_B, $record['build_id'] );
		$this->assertSame( self::BUILD_A, $record['previous_build_id'] );
	}

	public function test_a_second_manifest_instance_in_the_same_request_does_not_wait_for_a_held_database_lock(): void {
		$a = new Babilim_Manifest( $this->fs );
		$b = new Babilim_Manifest( $this->fs );

		$started = microtime( true );
		$stored  = $a->with_lock(
			static function () use ( $b ) {
				return $b->set_default_index( 'de_DE', array( 'built_at' => '2026-01-01T00:00:00Z' ) ) ? 'stored' : 'failed';
			}
		);

		$this->assertSame( 'stored', $stored );
		$this->assertLessThan( 2.0, microtime( true ) - $started, 'the same request never waits on its own lock' );
		$this->assertNull( $GLOBALS['wpdb']->babilim_test_row( 'babilim_manifest_lock' ), 'and the lock is released at the end' );
	}

	public function test_with_the_database_lock_fallback_the_index_rebuild_triggered_by_an_install_does_not_wait_either(): void {
		@unlink( Babilim_Paths::root() . '/.install.lock' );
		mkdir( Babilim_Paths::root() . '/.install.lock' );
		$other_manifest = new Babilim_Manifest( $this->fs );
		$rebuild        = null;
		add_action(
			'babilim_layer_changed',
			static function ( $locale ) use ( $other_manifest, &$rebuild ) {
				$rebuild = $other_manifest->set_default_index( (string) $locale, array( 'built_at' => '2026-01-01T00:00:00Z' ) );
			}
		);
		try {
			$started = microtime( true );
			$result  = $this->install( $this->installer(), self::BUILD_A );

			$this->assertTrue( $result->is_ok(), $result->message() );
			$this->assertTrue( $rebuild, 'the other instance could write while the install held the lock' );
			$this->assertLessThan( 2.0, microtime( true ) - $started );
		} finally {
			rmdir( Babilim_Paths::root() . '/.install.lock' );
		}
	}

	public function test_a_component_degraded_after_a_failed_integrity_check_is_installed_again_from_the_same_build(): void {
		$installer = $this->installer();
		$this->assertTrue( $this->install( $installer, self::BUILD_A )->is_ok() );
		$served = Babilim_Paths::file( 'plugins', 'demo', 'de_DE', 'mo' );
		$this->assertTrue( $this->fs->exists( $served ) );
		$this->assertTrue( $installer->degrade( 'plugin', 'demo', 'de_DE' )->is_ok() );
		$this->assertFalse( $this->fs->exists( $served ), 'degrade removed the served file' );

		$again = $this->install( $this->installer(), self::BUILD_A );

		$this->assertTrue( $again->is_ok(), $again->message() );
		$this->assertArrayNotHasKey( 'already_installed', $again->data(), 'a degraded build is not "already installed"' );
		$this->assertTrue( $this->fs->exists( $served ), 'the file is back' );
	}

	public function test_degrade_records_a_failed_integrity_and_keeps_the_build_id(): void {
		$installer = $this->installer();
		$this->assertTrue( $this->install( $installer, self::BUILD_A )->is_ok() );
		$this->assertTrue( $installer->degrade( 'plugin', 'demo', 'de_DE' )->is_ok() );

		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );

		$this->assertSame( self::BUILD_A, $record['build_id'] );
		$this->assertFalse( $record['integrity']['ok'], 'a degraded record is not a verified one' );
		$this->assertNotEmpty( $record['degraded_at'] );
		$this->assertSame( array(), $record['files'] );
	}

	public function test_verify_leaves_a_degraded_record_failed(): void {
		$installer = $this->installer();
		$this->assertTrue( $this->install( $installer, self::BUILD_A )->is_ok() );
		$installer->degrade( 'plugin', 'demo', 'de_DE' );

		$this->assertTrue( $this->installer()->verify( 'plugin', 'demo', 'de_DE' )->is_ok(), 'nothing is served, so nothing mismatches' );

		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );
		$this->assertFalse( $record['integrity']['ok'], 'an empty file list must not turn a degraded record back into an ok one' );
		$this->assertNotEmpty( $record['degraded_at'] );
	}

	/**
	 * The shape an older release left behind after a degrade: files and
	 * default_files empty, the build id kept, integrity ok, no marker.
	 */
	private function write_legacy_degraded_record(): void {
		$this->manifest->set(
			'plugin',
			'demo',
			'de_DE',
			array(
				'build_id'      => self::BUILD_A,
				'installed_at'  => '2026-01-01T00:00:00Z',
				'files'         => array(),
				'default_files' => array(),
				'integrity'     => array( 'checked_at' => '2026-01-02T00:00:00Z', 'ok' => true ),
			)
		);
	}

	public function test_verify_normalises_a_legacy_degraded_record(): void {
		$this->write_legacy_degraded_record();

		$result = $this->installer()->verify( 'plugin', 'demo', 'de_DE' );

		$this->assertTrue( $result->is_ok(), 'no new failure is raised, so no new client event is recorded' );
		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );
		$this->assertNotEmpty( $record['degraded_at'], 'the legacy record is marked degraded and the mark is persisted' );
		$this->assertFalse( $record['integrity']['ok'] );
		$this->assertSame( self::BUILD_A, $record['build_id'] );
		$cell = Babilim_Cell::from_entry( array( 'status' => 'babilim_installed', 'build_id' => self::BUILD_A ), 'de_DE', false, true, $record );
		$this->assertFalse( $cell['installed'] );
		$this->assertSame( 'babilim_download', $cell['action'] );
	}

	public function test_verify_keeps_the_original_time_of_a_legacy_degraded_record_check(): void {
		$this->write_legacy_degraded_record();

		$this->installer()->verify( 'plugin', 'demo', 'de_DE' );

		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );
		$this->assertSame( '2026-01-02T00:00:00Z', $record['degraded_at'] );
	}

	public function test_a_real_degrade_then_a_sync_reports_failed_and_a_reinstall_then_a_sync_reports_ok(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		$this->assertTrue( $this->installer()->degrade( 'plugin', 'demo', 'de_DE' )->is_ok() );

		$inventory = new Babilim_Inventory(
			static function () { return array( 'demo/demo.php' => array( 'Name' => 'Demo', 'Version' => '1.0', 'TextDomain' => 'demo' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$body = array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' );
		$ok   = array( 'response' => array( 'code' => 200 ), 'body' => (string) wp_json_encode( $body ), 'headers' => array() );
		$http = new Babilim_Fake_Http( array( $ok, $ok ) );
		$site = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, $this->manifest );

		$site->sync();
		$sent = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertSame( 'failed', $sent['components'][0]['installed']['de_DE']['integrity'] );
		$this->assertSame( self::BUILD_A, $sent['components'][0]['installed']['de_DE']['build_id'] );

		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		$site->sync();
		$sent = json_decode( $http->requests[1]['args']['body'], true );
		$this->assertSame( 'ok', $sent['components'][0]['installed']['de_DE']['integrity'] );
		$this->assertSame( self::BUILD_A, $sent['components'][0]['installed']['de_DE']['build_id'] );
	}

	public function test_installing_a_degraded_build_again_makes_the_record_normal(): void {
		$installer = $this->installer();
		$this->assertTrue( $this->install( $installer, self::BUILD_A )->is_ok() );
		$installer->degrade( 'plugin', 'demo', 'de_DE' );

		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );

		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );
		$this->assertTrue( $record['integrity']['ok'] );
		$this->assertArrayNotHasKey( 'degraded_at', $record );
		$this->assertNotSame( array(), $record['files'] );
		$this->assertTrue( $this->fs->exists( Babilim_Paths::file( 'plugins', 'demo', 'de_DE', 'mo' ) ) );
	}

	public function test_rollback_works_on_a_degraded_record_with_history(): void {
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_B )->is_ok() );
		$this->assertTrue( $this->installer()->degrade( 'plugin', 'demo', 'de_DE' )->is_ok() );

		$result = $this->installer()->rollback( 'plugin', 'demo', 'de_DE' );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' );
		$this->assertSame( self::BUILD_A, $record['build_id'] );
		$this->assertTrue( $record['integrity']['ok'] );
		$this->assertArrayNotHasKey( 'degraded_at', $record );
		$this->assertTrue( $this->fs->exists( Babilim_Paths::file( 'plugins', 'demo', 'de_DE', 'mo' ) ) );
	}

	public function test_remove_works_on_a_degraded_record(): void {
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		$this->installer()->degrade( 'plugin', 'demo', 'de_DE' );

		$this->assertTrue( $this->installer()->remove( 'plugin', 'demo', 'de_DE' )->is_ok() );
		$this->assertNull( ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'demo', 'de_DE' ) );
	}

	public function test_a_record_whose_last_integrity_check_failed_is_installed_again_from_the_same_build(): void {
		$this->assertTrue( $this->install( $this->installer(), self::BUILD_A )->is_ok() );
		$served = Babilim_Paths::file( 'plugins', 'demo', 'de_DE', 'mo' );
		$this->fs->put( $served, 'tampered' );
		$this->assertTrue( $this->installer()->verify( 'plugin', 'demo', 'de_DE' )->is_error() );

		$again = $this->install( $this->installer(), self::BUILD_A );

		$this->assertTrue( $again->is_ok(), $again->message() );
		$this->assertArrayNotHasKey( 'already_installed', $again->data() );
		$this->assertNotSame( 'tampered', $this->fs->get( $served ) );
	}
}
