<?php
/**
 * Cross-version flows: the 1.0.1, 1.1.0 and current sync installers are
 * run against the same situations, so a difference between versions is
 * visible in the test names and assertions instead of being implicit.
 *
 * Zero network: the service is a fake HTTP double.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/legacy/class-babilim-sync-installer-1.0.1.php';
require_once dirname( __DIR__ ) . '/fixtures/legacy/class-babilim-sync-installer-1.1.0.php';

final class Test_Upgrade_Flow extends TestCase {

	private const V101    = 'Babilim_Legacy_Sync_Installer';
	private const V110    = 'Babilim_Legacy_1_1_0_Sync_Installer';
	private const CURRENT = 'Babilim_Sync_Installer';
	private const BUILD   = 'bd_0000000000a1';

	/** @var Babilim_Fs */
	private $fs;

	/** @var Babilim_Manifest */
	private $manifest;

	/** @var Babilim_State */
	private $state;

	/** @var array<string,string> */
	private $zip = array();

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$GLOBALS['babilim_test_fake_zips'] = array();
	}

	protected function tearDown(): void {
		$this->restore_test_dir();
		Babilim::reset_for_tests();
	}

	// -- helpers ------------------------------------------------------------

	private function zip_answer( string $locale = 'ja' ): void {
		$mo         = Babilim_Mo_Writer::write(
			array( 'Save' => 'Save-' . $locale, 'Cancel' => 'Cancel' ),
			array( 'Language' => $locale, 'X-Babilim-Site' => 'st_flow', 'X-Babilim-Build' => self::BUILD )
		);
		$po         = "msgid \"\"\nmsgstr \"\"\n\"X-Babilim-Site: st_flow\\n\"\n\"X-Babilim-Build: " . self::BUILD . "\\n\"\n";
		$this->zip = array(
			"fresh-{$locale}.mo" => $mo,
			"fresh-{$locale}.po" => $po,
			'manifest.json'      => (string) wp_json_encode(
				array(
					'build_id'        => "fresh-1.0-{$locale}-20260101T000000-abc123",
					'entries'         => 2,
					'files'           => array(
						"fresh-{$locale}.mo" => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ),
						"fresh-{$locale}.po" => array( 'sha256' => hash( 'sha256', $po ), 'size' => strlen( $po ) ),
					),
					'locale'          => $locale,
					'status'          => 'published',
					'kind'            => 'plugin',
					'slug'            => 'fresh',
					'version'         => '1.0',
					'text_domain'     => 'fresh',
					'extra_domains'   => array(),
					'server_build_id' => self::BUILD,
					'build_no'        => 1,
					'issued_at'       => '2026-01-01T00:00:00Z',
					'contract'        => '1.3.0',
				)
			),
		);
	}

	/** A 302 for the download and the package bytes, as the service sends them. */
	private function granting_http(): Babilim_Fake_Http {
		return new Babilim_Fake_Http(
			array(
				array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/' . self::BUILD ) ),
				array( 'response' => array( 'code' => 200 ), 'body' => 'zip-bytes', 'headers' => array() ),
			)
		);
	}

	private function refusing_http( string $code ): Babilim_Fake_Http {
		return new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 403 ),
					'body'     => (string) wp_json_encode( array( 'error' => array( 'code' => $code, 'message' => 'm', 'hint' => 'h' ) ) ),
					'headers'  => array(),
				),
			)
		);
	}

	/** The installer's entry counter, reading files from $fs (.l10n.php or .mo). */
	private static function parse_from( Babilim_Fs $fs ): callable {
		return static function ( string $path, string $format ) use ( $fs ) {
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
	}

	/** The installer's .mo to .l10n.php transform, reading from $fs. */
	private static function transform_from( Babilim_Fs $fs ): callable {
		return static function ( string $mo_path ) use ( $fs ) {
			$entries = babilim_test_read_mo( (string) $fs->get( $mo_path ) );
			unset( $entries[''] );
			return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
		};
	}

	private function installer_of( string $class, Babilim_Fake_Http $http, array $state_body, bool $with_record ) {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_flow' );
		update_option( 'babilim_site_token', 'tok_flow' );
		$this->fs       = new Babilim_Memory_Fs();
		$this->manifest = new Babilim_Manifest( $this->fs );
		$this->state    = new Babilim_State();
		$this->state->set( $state_body );
		if ( $with_record ) {
			$this->manifest->set(
				'plugin',
				'fresh',
				'ja',
				array(
					'build_id'     => 'bd_0000000000a0',
					'files'        => array(),
					'integrity'    => array( 'ok' => true, 'checked_at' => '2026-01-01T00:00:00Z' ),
					'installed_at' => '2026-01-01T00:00:00Z',
				)
			);
		}
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array( 'ja' ); }
		);
		$site      = new Babilim_Site( $http, 'https://api.babilim.org', $this->state, $inventory, $this->manifest );

		$fs        = $this->fs;
		$zip       = &$this->zip;
		$parse     = self::parse_from( $fs );
		$transform = self::transform_from( $fs );
		$unzip     = static function () use ( &$zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $this->fs, $this->manifest, $transform, $parse, $unzip );
		return new $class( $this->state, $site, $installer, $http, $this->fs, 'https://api.babilim.org' );
	}

	/** A snapshot with one `ja` cell. */
	private function snapshot( string $status, bool $flag ): array {
		$cell = array( 'status' => $status, 'build_id' => self::BUILD );
		if ( $flag ) {
			$cell['auto_install'] = true;
		}
		return array(
			'components' => array( array( 'kind' => 'plugin', 'slug' => 'fresh', 'locales' => array( 'ja' => $cell ) ) ),
			'updates'    => array(),
			'license'    => array( 'kind' => 'free', 'status' => 'active' ),
		);
	}

	private function downloads( Babilim_Fake_Http $http ): int {
		return count(
			array_filter(
				$http->requests,
				static function ( $request ) {
					return false !== strpos( $request['url'], '/v1/downloads' );
				}
			)
		);
	}

	/** @return array{0:int,1:array} Download requests made, install outcomes. */
	private function run_available( string $class, array $state_body, bool $with_record, ?Babilim_Fake_Http $http = null ): array {
		$http = $http ?? $this->granting_http();
		$this->zip_answer();
		$sync = $this->installer_of( $class, $http, $state_body, $with_record );
		$done = $sync->install_available( array( 'ja' ), 20 );
		return array( $this->downloads( $http ), $done );
	}

	// -- (a) -----------------------------------------------------------------

	public function test_a_free_site_with_an_unbound_requestable_cell_downloads_nothing_in_any_version(): void {
		$body = $this->snapshot( 'requestable', true );
		foreach ( array( self::V101, self::V110, self::CURRENT ) as $class ) {
			list( $downloads ) = $this->run_available( $class, $body, false );
			$this->assertSame( 0, $downloads, $class );
		}
	}

	// -- (b) -----------------------------------------------------------------

	public function test_a_free_site_with_a_bound_available_cell_and_no_local_record_differs_by_version_1_0_1_waits_while_1_1_0_and_current_download(): void {
		$body = $this->snapshot( 'babilim_available', true );

		list( $downloads_101 ) = $this->run_available( self::V101, $body, false );
		$this->assertSame( 0, $downloads_101, '1.0.1 needs a local record first, so it makes no download' );

		list( $downloads_110, $done_110 ) = $this->run_available( self::V110, $body, false );
		$this->assertSame( 1, $downloads_110, '1.1.0 downloads a flagged available cell' );
		$this->assertTrue( $done_110[0]['result']->is_ok(), $done_110[0]['result']->message() );

		list( $downloads_new, $done_new ) = $this->run_available( self::CURRENT, $body, false );
		$this->assertSame( 1, $downloads_new, 'the current version downloads an available cell' );
		$this->assertTrue( $done_new[0]['result']->is_ok(), $done_new[0]['result']->message() );
	}

	// -- (c) -----------------------------------------------------------------

	public function test_an_old_1_10_0_snapshot_with_an_unflagged_available_cell_downloads_nothing_in_1_1_0(): void {
		list( $downloads ) = $this->run_available( self::V110, $this->snapshot( 'babilim_available', false ), false );
		$this->assertSame( 0, $downloads );
	}

	public function test_a_download_the_service_refuses_with_slot_required_is_not_a_failure_and_installs_nothing(): void {
		$http = $this->refusing_http( 'slot_required' );
		list( $downloads, $done ) = $this->run_available( self::CURRENT, $this->snapshot( 'babilim_available', false ), false, $http );

		$this->assertSame( 1, $downloads );
		$this->assertSame( array(), $done, 'a refusal is not an outcome of the run' );
		$this->assertFalse( get_option( Babilim_Sync_Installer::OPTION_FAILURES, false ), 'a refusal by the service is not counted' );
		$this->assertNull( $this->manifest->installed( 'plugin', 'fresh', 'ja' ), 'nothing is installed' );
	}

	// -- (d) is covered by Test_Babilim: a failed or unfinished sync installs nothing.

	// -- (e) -----------------------------------------------------------------

	public function test_after_a_request_answer_turns_the_cell_available_the_next_cron_run_downloads_and_installs_it(): void {
		$http = $this->granting_http();
		$this->zip_answer();
		$sync = $this->installer_of( self::CURRENT, $http, $this->snapshot( 'requestable', false ), false );

		$this->assertSame( array(), $sync->install_available( array( 'ja' ), 20 ), 'a requestable cell is left alone' );
		$this->assertSame( 0, $this->downloads( $http ) );

		$this->state->record_request_answer( 'plugin', 'fresh', 'ja', false, Babilim_Result::ok( array( 'status' => 'available', 'build_id' => self::BUILD ) ) );
		$done = $sync->install_available( array( 'ja' ), 20 );

		$this->assertCount( 1, $done );
		$this->assertTrue( $done[0]['result']->is_ok(), $done[0]['result']->message() );
		$this->assertSame( 1, $this->downloads( $http ) );
		$this->assertSame( self::BUILD, ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'fresh', 'ja' )['build_id'] );
	}

	// -- (f) what is already on the site keeps working on a free service plan ----

	private function free_state(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'woo/woo.php' => array( 'Name' => 'Woo', 'Version' => '1.0.0', 'TextDomain' => 'woo' ) );
		( new Babilim_State() )->set(
			array(
				'fetched_at' => '2026-10-01T00:00:00Z',
				'components' => array( array( 'kind' => 'plugin', 'slug' => 'woo', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) ) ),
				'license'    => array( 'kind' => 'free', 'status' => 'active' ),
			)
		);
	}

	/** @var Babilim_Installer */
	private $local_installer;

	/** Installs two builds of plugin woo / zh_TW into $this->fs (history under _versions/). */
	private function install_two_builds(): void {
		$fs        = $this->fs;
		$installer = new Babilim_Installer(
			$fs,
			$this->manifest,
			self::transform_from( $fs ),
			self::parse_from( $fs ),
			static function ( string $zip_path ) {
				return $GLOBALS['babilim_test_fake_zips'][ $zip_path ] ?? false;
			}
		);
		$this->local_installer = $installer;
		for ( $i = 1; $i <= 2; $i++ ) {
			$id  = sprintf( 'bd_%012d', $i );
			$mo  = Babilim_Mo_Writer::write( array( 'Save' => 'v' . $i ), array() );
			$po  = "# fake po\n";
			$man = array(
				'build_id'        => "woo-1.0.0-zh_TW-20260101T000000-abc123",
				'entries'         => 1,
				'files'           => array(
					'woo-zh_TW.mo' => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ),
					'woo-zh_TW.po' => array( 'sha256' => hash( 'sha256', $po ), 'size' => strlen( $po ) ),
				),
				'locale'          => 'zh_TW',
				'status'          => 'published',
				'kind'            => 'plugin',
				'slug'            => 'woo',
				'version'         => '1.0.0',
				'text_domain'     => 'woo',
				'extra_domains'   => array(),
				'server_build_id' => $id,
				'build_no'        => $i,
				'issued_at'       => '2026-01-01T00:00:00Z',
				'contract'        => '1.1.0',
			);
			$GLOBALS['babilim_test_fake_zips'][ "zip://woo/{$i}" ] = array( 'woo-zh_TW.mo' => $mo, 'woo-zh_TW.po' => $po, 'manifest.json' => (string) wp_json_encode( $man ) );
			$this->assertTrue( $installer->install( 'plugin', 'woo', 'zh_TW', "zip://woo/{$i}", $man )->is_ok() );
		}
	}

	/** Posts a confirmed row action the way the translations screen does. */
	private function post_row_action( string $action ): void {
		$fields             = array( 'kind' => 'plugin', 'slug' => 'woo', 'locale' => 'zh_TW', 'confirmed' => '1', 'action' => $action );
		$fields['_wpnonce'] = wp_create_nonce( $action );
		$_POST              = $fields;
		$_REQUEST           = $fields;
		$GLOBALS['babilim_test_settings_errors']    = array();
		$GLOBALS['babilim_test_wp_remote_requests'] = array();
	}

	private function last_notice(): array {
		$notices = $GLOBALS['babilim_test_settings_errors'];
		$this->assertCount( 1, $notices );
		return $notices[0];
	}

	/** @var string|null Where use_test_dir_fs() moved an existing root, if it did. */
	private $saved_root = null;

	/** @var bool Whether this test made the root it is using (use_test_dir_fs()). */
	private $own_root = false;

	/**
	 * Real files, but in a root of this test's own under the per-run
	 * test directory (WP_LANG_DIR): a root another test left is moved
	 * aside, not deleted, and put back by restore_test_dir() (tearDown()).
	 */
	private function use_test_dir_fs(): void {
		$root = Babilim_Paths::root();
		if ( file_exists( $root ) ) {
			$this->saved_root = $root . '.saved-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
			$this->assertTrue( rename( $root, $this->saved_root ), 'an existing root is moved aside' );
		}
		$this->own_root = true;
		$this->fs       = new Babilim_Wp_Fs();
		Babilim_Paths::ensure_layout( $this->fs );
		$this->manifest = new Babilim_Manifest( $this->fs );
	}

	/**
	 * Removes this test's root and puts back whatever was there before --
	 * only when use_test_dir_fs() made one; otherwise the root (another
	 * test's, or none) is left alone.
	 */
	private function restore_test_dir(): void {
		if ( ! $this->own_root ) {
			return;
		}
		$this->own_root = false;
		babilim_test_rmtree( Babilim_Paths::root() );
		if ( null !== $this->saved_root ) {
			rename( $this->saved_root, Babilim_Paths::root() );
			$this->saved_root = null;
		}
	}

	public function test_a_test_without_real_files_leaves_an_existing_root_alone_in_teardown(): void {
		$root = Babilim_Paths::root();
		babilim_test_rmtree( $root );
		mkdir( $root, 0777, true );
		file_put_contents( $root . '/marker', 'other test' );
		try {
			// What tearDown() runs after a test that never called use_test_dir_fs().
			$this->tearDown();
			$this->assertSame( 'other test', (string) file_get_contents( $root . '/marker' ), 'the root is untouched' );
		} finally {
			babilim_test_rmtree( $root );
		}
	}

	public function test_a_real_disk_test_leaves_a_root_it_did_not_create_as_it_was(): void {
		$root = Babilim_Paths::root();
		babilim_test_rmtree( $root );
		mkdir( $root, 0777, true );
		file_put_contents( $root . '/marker', 'other test' );
		try {
			$this->use_test_dir_fs();
			$this->assertFileDoesNotExist( $root . '/marker', 'this test starts from a root of its own' );
			file_put_contents( Babilim_Paths::dir( 'plugins' ) . '/mine', 'x' );
			$this->restore_test_dir();
			$this->assertSame( 'other test', (string) file_get_contents( $root . '/marker' ), 'the earlier root is back' );
			$this->assertFileDoesNotExist( Babilim_Paths::dir( 'plugins' ) . '/mine', 'nothing of this test is left' );
		} finally {
			babilim_test_rmtree( $root );
		}
	}

	protected function assertNoServiceRequests( string $message ): void {
		$this->assertSame( array(), $GLOBALS['babilim_test_wp_remote_requests'], $message );
	}

	public function test_a_free_service_plan_site_still_loads_what_is_installed(): void {
		$this->use_test_dir_fs();
		try {
			$this->install_two_builds();
			$this->free_state();
			$record = $this->manifest->installed( 'plugin', 'woo', 'zh_TW' );
			$this->assertNotNull( $record );
			$this->assertCount( 2, $record['version_history'], 'the history of both builds is kept' );
			Babilim_Loader::reset_for_tests();
			$mo = Babilim_Paths::file( 'plugins', 'woo', 'zh_TW', 'mo' );
			$this->assertFileExists( $mo );

			$GLOBALS['babilim_test_load_textdomain_calls'] = array();
			Babilim_Loader::load_translation_file( '/wp/plugins/woo/languages/woo-zh_TW.mo', 'woo', 'zh_TW' );

			$this->assertSame( array( $mo ), array_column( $GLOBALS['babilim_test_load_textdomain_calls'], 'mofile' ), 'our .mo is the one loaded' );
		} finally {
			$this->restore_test_dir();
		}
		$this->assertDirectoryDoesNotExist( Babilim_Paths::dir( 'plugins' ), 'nothing is left behind' );
	}

	private function admin(): Babilim_Admin {
		return new Babilim_Admin( $this->fs, new Babilim_Fake_Http( array() ), $this->local_installer, $this->manifest );
	}

	private function seed_two_builds_on_memory_fs(): void {
		$this->fs       = new Babilim_Memory_Fs();
		$this->manifest = new Babilim_Manifest( $this->fs );
		$this->install_two_builds();
		$this->free_state();
	}

	public function test_a_free_service_plan_site_can_roll_back_from_the_admin_screen_without_asking_the_service(): void {
		$this->seed_two_builds_on_memory_fs();
		$this->post_row_action( 'babilim_rollback' );

		$this->admin()->dispatch_babilim_rollback();

		$notice = $this->last_notice();
		$this->assertSame( 'ok', $notice['code'], $notice['message'] );
		$this->assertSame( 'success', $notice['type'] );
		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woo', 'zh_TW' );
		$this->assertSame( 'bd_000000000001', $record['build_id'], 'the record points at the previous build' );
		$this->assertNoServiceRequests( 'the service is not asked' );
	}

	public function test_a_free_service_plan_site_can_remove_from_the_admin_screen_without_asking_the_service(): void {
		$this->seed_two_builds_on_memory_fs();
		$file = Babilim_Paths::file( 'plugins', 'woo', 'zh_TW', 'mo' );
		$this->assertTrue( $this->fs->exists( $file ) );
		$this->post_row_action( 'babilim_remove' );

		$this->admin()->dispatch_babilim_remove();

		$notice = $this->last_notice();
		$this->assertSame( 'ok', $notice['code'], $notice['message'] );
		$this->assertSame( 'success', $notice['type'] );
		$this->assertNull( ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woo', 'zh_TW' ), 'no record is left' );
		$this->assertFalse( $this->fs->exists( $file ), 'the served file is gone' );
		$this->assertNoServiceRequests( 'the service is not asked' );
	}

	public function test_the_installer_alone_also_rolls_back_and_removes_on_a_free_service_plan_without_asking_the_service(): void {
		$this->seed_two_builds_on_memory_fs();
		$GLOBALS['babilim_test_wp_remote_requests'] = array();

		$rolled = $this->local_installer->rollback( 'plugin', 'woo', 'zh_TW' );
		$this->assertTrue( $rolled->is_ok(), $rolled->message() );
		$this->assertSame( 'bd_000000000001', ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woo', 'zh_TW' )['build_id'] );

		$removed = $this->local_installer->remove( 'plugin', 'woo', 'zh_TW' );
		$this->assertTrue( $removed->is_ok(), $removed->message() );
		$this->assertNull( ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woo', 'zh_TW' ) );
		$this->assertNoServiceRequests( 'the service is not asked' );
	}
}
