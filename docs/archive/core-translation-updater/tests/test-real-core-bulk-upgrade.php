<?php
/**
 * Runs this plugin's own hooks -- registered exactly the way production
 * does, through `Babilim::instance()->init()` (not by constructing a
 * `Babilim_Updater` directly and calling its own `register()`; see
 * arm_assembly_layer()) -- through WordPress core's own real
 * Language_Pack_Upgrader::bulk_upgrade() -> WP_Upgrader::run() ->
 * download_package() -> unpack_package() -> install_package() ->
 * clear_destination() call sequence (see tests/support/real-core.php),
 * rather than a hand-written stand-in for what that sequence does.
 *
 * This is the permanent form of the real-core probe that first found
 * this: without a destination redirect, core's own clear_destination()
 * deletes a pre-existing wp.org language pack for the same slug/locale
 * this plugin's own item is updating, every single time that item
 * succeeds. Going through the real assembly layer means a
 * `register_updater_hooks()` proxy that stopped forwarding to
 * `Babilim_Updater` would turn this test red too -- that exact gap
 * existed, undetected, until this test itself started going through the
 * real assembly layer (see class-babilim.php's own docblock on
 * `$updater`). Skipped (with a clear message) when
 * `make tools` hasn't fetched the pinned core files into
 * tools/wp-core/ yet.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-core.php';

final class Test_Real_Core_Bulk_Upgrade extends TestCase {

	private $lang_plugins;

	protected function setUp(): void {
		if ( ! babilim_test_real_core_available() ) {
			$this->markTestSkipped(
				'Real WordPress core files are not present under tools/wp-core/ -- run `make tools` to fetch them (pinned by tag+SHA256 in the Makefile) before this test can run for real.'
			);
		}
		// Only declared once this test is confirmed to actually run --
		// see that file's own docblock for why loading it merely by
		// discovering this test file (regardless of whether it runs)
		// would break an unrelated test elsewhere in the suite.
		babilim_test_ensure_wp_translation_file_fake();

		babilim_test_reset();
		Babilim::reset_for_tests();
		$GLOBALS['babilim_test_real_core_clean_cache_calls'] = 0;
		$GLOBALS['babilim_test_real_core_zip_map']           = array();
		$GLOBALS['babilim_test_real_core_copied']            = array();
		$GLOBALS['babilim_test_real_core_deleted']           = array();
		$GLOBALS['babilim_test_real_core_move_dir_calls']    = array();

		$this->rimraf( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR . '/upgrade', 0777, true );
		$this->lang_plugins = rtrim( WP_LANG_DIR, '/' ) . '/plugins';
		$this->rimraf( $this->lang_plugins );
		$this->rimraf( rtrim( WP_LANG_DIR, '/' ) . '/babilim' );

		// The one real $wp_filesystem instance both core's own upgrader
		// code AND this plugin's production Babilim_Wp_Fs (built inside
		// Babilim::updater(), see arm_assembly_layer() below) will use --
		// everything in this test happens on real disk under
		// this process's own temp root, never Memory_Fs, so a redirected
		// destination behaves identically to production.
		$GLOBALS['wp_filesystem'] = new Babilim_Test_Real_Core_Wp_Fs();

		update_option( 'babilim_consent', true );
	}

	/**
	 * Everything this test class touches is real disk, shared with every
	 * other test file in the same PHPUnit process (WP_LANG_DIR/
	 * WP_CONTENT_DIR are process-wide constants) -- leaving
	 * `WP_LANG_DIR/plugins`/`WP_LANG_DIR/babilim` populated here would
	 * leak into an unrelated test's own assumptions about a clean slate.
	 */
	protected function tearDown(): void {
		if ( is_string( $this->lang_plugins ) ) {
			$this->rimraf( $this->lang_plugins );
		}
		$this->rimraf( rtrim( WP_LANG_DIR, '/' ) . '/babilim' );
		$this->rimraf( WP_CONTENT_DIR );
		$GLOBALS['wp_filesystem'] = null;
		Babilim::reset_for_tests();

		// This class's own fixture helpers (our_fixture(), wporg_fixture(),
		// wporg(), arm_download(), and the plugin-update fixture built
		// directly inside a test method) each write to a fresh,
		// uniquely-named path under this process's own
		// BABILIM_TEST_REAL_CORE_TMP -- outside WP_LANG_DIR/WP_CONTENT_DIR,
		// so the cleanup above never reaches them. Only that one
		// directory is removed: it belongs to this process alone, so
		// another PHPUnit process's fixtures (under its own pid-named
		// root) are never touched.
		$this->rimraf( BABILIM_TEST_REAL_CORE_TMP );
	}

	private function rimraf( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			if ( is_file( $dir ) ) {
				unlink( $dir );
			}
			return;
		}
		foreach ( scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$this->rimraf( "$dir/$name" );
		}
		rmdir( $dir );
	}

	private function mo( array $entries ): string {
		return Babilim_Mo_Writer::write( $entries, array() );
	}

	private function digest( string $bytes ): array {
		return array( 'sha256' => hash( 'sha256', $bytes ), 'size' => strlen( $bytes ) );
	}

	/**
	 * Writes one of this plugin's own package fixtures (flat zip root,
	 * per package-layout.md) to a fresh directory.
	 *
	 * @return string Absolute directory path.
	 */
	private function our_fixture( string $domain, string $locale, string $build_id ): string {
		$dir = babilim_test_real_core_tmp_dir() . '/babilim-real-core-fixture-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/default', 0777, true );
		$mo  = $this->mo( array( 'Save' => 'x' ) );
		$po  = "# po\n";
		$dmo = $this->mo( array( 'Cart' => 'y' ) );
		file_put_contents( "$dir/$domain-$locale.mo", $mo );
		file_put_contents( "$dir/$domain-$locale.po", $po );
		file_put_contents( "$dir/default/$domain-$locale.mo", $dmo );
		$parsed = babilim_test_read_mo( $mo );
		unset( $parsed[''] );
		$default_parsed = babilim_test_read_mo( $dmo );
		unset( $default_parsed[''] );
		$manifest = array(
			'build_id'               => "$domain-1.0.0-$locale-20260101T000000-abc123",
			'entries'                => count( $parsed ),
			'files'                  => array(
				"$domain-$locale.mo" => $this->digest( $mo ),
				"$domain-$locale.po" => $this->digest( $po ),
			),
			'locale'                 => $locale,
			'status'                 => 'published',
			'kind'                   => 'plugin',
			'slug'                   => $domain,
			'version'                => '1.0.0',
			'text_domain'            => $domain,
			'extra_domains'          => array(),
			'server_build_id'        => $build_id,
			'build_no'               => 1,
			'issued_at'              => '2026-01-01T00:00:00Z',
			'contract'               => '1.1.0',
			'default_domain_entries' => count( $default_parsed ),
			'default_domain_files'   => array( "$domain-$locale.mo" => $this->digest( $dmo ) ),
		);
		file_put_contents( "$dir/manifest.json", (string) wp_json_encode( $manifest ) );
		return $dir;
	}

	/** A real wp.org-shaped package fixture: flat .mo/.po at the zip root. */
	private function wporg_fixture( string $slug, string $locale ): string {
		$dir = babilim_test_real_core_tmp_dir() . '/babilim-real-core-wporg-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		file_put_contents( "$dir/$slug-$locale.mo", 'WPORG-NEW-MO' );
		file_put_contents( "$dir/$slug-$locale.po", 'WPORG-NEW-PO' );
		return $dir;
	}

	/**
	 * Seeds a pre-existing wp.org language pack for woocommerce/zh_TW --
	 * the exact slug/locale this test's own "ours" item also updates --
	 * including the `.l10n.php`/`.json` files a real wp.org language pack
	 * carries alongside `.po`/`.mo` (fix2's own requirement).
	 */
	private function seed_wporg_existing(): void {
		mkdir( $this->lang_plugins, 0777, true );
		file_put_contents( $this->lang_plugins . '/woocommerce-zh_TW.mo', 'WPORG-OLD-MO' );
		file_put_contents( $this->lang_plugins . '/woocommerce-zh_TW.po', 'WPORG-OLD-PO' );
		file_put_contents( $this->lang_plugins . '/woocommerce-zh_TW.l10n.php', 'WPORG-OLD-L10N-PHP' );
		file_put_contents( $this->lang_plugins . '/woocommerce-zh_TW-0123456789abcdef.json', '{"WPORG":"OLD-JSON"}' );
	}

	private function snapshot( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$out[ $name ] = is_file( "$dir/$name" ) ? file_get_contents( "$dir/$name" ) : 'DIR';
			}
		}
		ksort( $out );
		return $out;
	}

	private function ours( string $build_id, string $slug, string $locale = 'zh_TW' ): object {
		return (object) array(
			'type'       => 'plugin',
			'slug'       => $slug,
			'language'   => $locale,
			'version'    => '',
			'updated'    => '',
			'package'    => 'https://api.babilim.org/babilim-package/' . $build_id,
			'autoupdate' => 1,
			'babilim'    => true,
			'build_id'   => $build_id,
			'build_no'   => 1,
		);
	}

	private function wporg( string $slug, string $locale, string $fixture_dir ): object {
		$package = babilim_test_real_core_tmp_dir() . '/babilim-real-core-wporg-pkg-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		file_put_contents( $package, 'zip' );
		$GLOBALS['babilim_test_real_core_zip_map'][ $package ] = $fixture_dir;
		return (object) array(
			'type'       => 'plugin',
			'slug'       => $slug,
			'language'   => $locale,
			'version'    => '1.0',
			'updated'    => '',
			'package'    => $package,
			'autoupdate' => 1,
		);
	}

	private function arm_download( string $fixture_dir ): void {
		$tmp = babilim_test_real_core_tmp_dir() . '/babilim-real-core-dl-' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		file_put_contents( $tmp, 'zip' );
		$GLOBALS['babilim_test_real_core_zip_map'][ $tmp ]     = $fixture_dir;
		$GLOBALS['babilim_test_download_url_return']            = $tmp;
	}

	/**
	 * Same as arm_download(), for a batch of two or more of this plugin's
	 * own items in one run -- each gets its own distinct fixture,
	 * downloaded in turn (see the download_url() stub's own docblock,
	 * tests/bootstrap.php, for the queue this relies on).
	 *
	 * @param string[] $fixture_dirs One directory per item, in item order.
	 */
	private function arm_downloads( array $fixture_dirs ): void {
		$tmps = array();
		foreach ( $fixture_dirs as $fixture_dir ) {
			$tmp = babilim_test_real_core_tmp_dir() . '/babilim-real-core-dl-' . bin2hex( random_bytes( 4 ) ) . '.tmp';
			file_put_contents( $tmp, 'zip' );
			$GLOBALS['babilim_test_real_core_zip_map'][ $tmp ] = $fixture_dir;
			$tmps[]                                             = $tmp;
		}
		$GLOBALS['babilim_test_download_url_return'] = $tmps;
	}

	/**
	 * Registers this plugin's real production hooks through the real
	 * assembly layer -- `Babilim::instance()->init()`, exactly what
	 * `babilim.php` itself does on `plugins_loaded` -- rather than
	 * constructing a `Babilim_Updater` directly and calling its own
	 * `register()`. This is deliberate: if any of `Babilim::
	 * register_updater_hooks()`'s own proxy methods stopped forwarding to
	 * `Babilim_Updater` (returning their own input unchanged instead),
	 * this test's own destination-redirect assertions below would go red, because the real
	 * `Babilim_Updater::package_options()`/etc. would simply never run.
	 *
	 * $http_responses are queued onto the global `wp_remote_request()`
	 * stub (tests/bootstrap.php) -- `Babilim::updater()` wires a real
	 * `Babilim_Api_Client(new Babilim_Wp_Http(), ...)`, and
	 * `Babilim_Wp_Http` calls that same stubbed function, so this needs
	 * no Babilim_Fake_Http of its own.
	 *
	 * @return array{0: Babilim_Manifest, 1: Babilim_Fs} A separate
	 *         Babilim_Manifest/Babilim_Fs pair, reading the same real
	 *         disk Babilim::updater() itself just wrote to, for this
	 *         test's own assertions.
	 */
	private function arm_assembly_layer( array $http_responses ): array {
		$GLOBALS['babilim_test_wp_remote_responses'] = $http_responses;
		Babilim::instance()->init();

		$fs = new Babilim_Wp_Fs();
		return array( new Babilim_Manifest( $fs ), $fs );
	}

	private function json_response( int $code, array $body = array(), array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}

	/**
	 * The permanent form of the real-core probe described above: a mixed
	 * batch (one wp.org item, one of this plugin's own), with a
	 * pre-existing wp.org language pack already on disk for the exact
	 * slug/locale this plugin's own item also updates. Asserts:
	 * - this plugin's own item adopts successfully;
	 * - the run reports success (no error, a normal result) for every
	 *   item, not "Handled by Babilim." or any error;
	 * - every one of the pre-existing wp.org files for that same
	 *   slug/locale -- `.po`, `.mo`, `.l10n.php`, and the `*.json` --
	 *   is still there, byte-for-byte unchanged;
	 * - the wp.org item in the same batch still installs its own new
	 *   files normally (this plugin's hooks never interfere with it);
	 * - `WP_LANG_DIR/plugins` gains no file this plugin's own item
	 *   didn't put there itself (it should put none at all);
	 * - the `_incoming/{token}` directory package_options() created is
	 *   removed once the item is done.
	 */
	public function test_mixed_batch_preserves_existing_wporg_pack_and_adopts_our_own_item(): void {
		$this->seed_wporg_existing();
		$before = $this->snapshot( $this->lang_plugins );

		( new Babilim_State() )->set(
			array(
				'updates' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_ea1c0de00001', 'build_no' => 1 ),
				),
			)
		);

		list( $manifest, $fs ) = $this->arm_assembly_layer(
			array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_ea1c0de00001?sig=a' ) ) )
		);
		$this->arm_download( $this->our_fixture( 'woocommerce', 'zh_TW', 'bd_ea1c0de00001' ) );

		$skin = new Babilim_Test_Real_Core_Skin();
		$lpu  = new Babilim_Test_Real_Core_Lpu( $skin );

		$items = array(
			$this->wporg( 'akismet', 'de_DE', $this->wporg_fixture( 'akismet', 'de_DE' ) ),
			$this->ours( 'bd_ea1c0de00001', 'woocommerce' ),
		);

		$lpu->bulk_upgrade( $items );

		// The run reported success for both items -- no "Handled by
		// Babilim.", no process_failed, no error() call at all.
		$this->assertNotContains( 'error:babilim_handled', $skin->log );
		$this->assertNotContains( 'feedback:process_failed', $skin->log );
		$this->assertSame( 2, count( array_filter( $skin->log, static function ( $line ) {
			return 'feedback:process_success' === $line;
		} ) ), 'Both items should report success: ' . implode( ',', $skin->log ) );

		// This plugin's own item actually adopted.
		$this->assertNotNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
		$this->assertSame( array(), ( new Babilim_State() )->updates(), 'The adopted build must be removed from state.' );

		$after = $this->snapshot( $this->lang_plugins );

		// Every pre-existing wp.org file for the exact slug/locale
		// this plugin's own item also updates is untouched, byte-for-byte.
		$this->assertSame( 'WPORG-OLD-MO', $after['woocommerce-zh_TW.mo'] ?? null );
		$this->assertSame( 'WPORG-OLD-PO', $after['woocommerce-zh_TW.po'] ?? null );
		$this->assertSame( 'WPORG-OLD-L10N-PHP', $after['woocommerce-zh_TW.l10n.php'] ?? null );
		$this->assertSame( '{"WPORG":"OLD-JSON"}', $after['woocommerce-zh_TW-0123456789abcdef.json'] ?? null );

		// The wp.org item in the same batch still installs normally --
		// this plugin's hooks never interfered with it.
		$this->assertSame( 'WPORG-NEW-MO', $after['akismet-de_DE.mo'] ?? null );
		$this->assertSame( 'WPORG-NEW-PO', $after['akismet-de_DE.po'] ?? null );

		// WP_LANG_DIR/plugins gained no file this plugin's own item put
		// there -- only the wp.org item's own two new files, nothing else.
		$new_files = array_diff( array_keys( $after ), array_keys( $before ) );
		sort( $new_files );
		$this->assertSame( array( 'akismet-de_DE.mo', 'akismet-de_DE.po' ), $new_files );

		// The `_incoming/{token}` directory is cleaned up.
		$this->assertSame( array(), $fs->list( Babilim_Paths::incoming_root() ), '_incoming must be empty once the run is done.' );

		$this->rimraf( WP_CONTENT_DIR . '/upgrade' );
	}

	/**
	 * Manifest-instance-sharing regression: Babilim::instance()'s own lazily-built collaborators
	 * (default_index(), updater(), site(), verify_installed_items(), and
	 * admin()) must all share exactly ONE Babilim_Manifest for the
	 * request -- see class-babilim.php's manifest() docblock. Before that
	 * fix, updater()'s own installer wrote each item's record through its
	 * own Babilim_Manifest snapshot, and Babilim_Manifest::save()
	 * overwrites `_manifest.json` wholesale on every write -- so the
	 * FIRST item's own record, still sitting only in updater()'s
	 * snapshot, was silently discarded the moment install_package_result()
	 * fired the second time. A real `Language_Pack_Upgrader::
	 * bulk_upgrade()` batch with two or more of this plugin's own items
	 * is exactly what a real translation-update admin screen (or the
	 * background auto-updater) sends in ONE request -- this is not a
	 * contrived scenario. Found by a real WordPress Playground run, not
	 * by this repo's own unit tests, none of which previously batched
	 * more than one of this plugin's own items.
	 */
	public function test_batch_with_two_of_our_own_items_keeps_both_manifest_records(): void {
		( new Babilim_State() )->set(
			array(
				'updates' => array(
					array( 'kind' => 'plugin', 'slug' => 'c1-plugin-a', 'locale' => 'ja', 'build_id' => 'bd_c1000000000a', 'build_no' => 1 ),
					array( 'kind' => 'plugin', 'slug' => 'c1-plugin-b', 'locale' => 'en_GB', 'build_id' => 'bd_c1000000000b', 'build_no' => 1 ),
				),
			)
		);

		list( $manifest, $fs ) = $this->arm_assembly_layer(
			array(
				$this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_c1000000000a?sig=a' ) ),
				$this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_c1000000000b?sig=b' ) ),
			)
		);
		$this->arm_downloads(
			array(
				$this->our_fixture( 'c1-plugin-a', 'ja', 'bd_c1000000000a' ),
				$this->our_fixture( 'c1-plugin-b', 'en_GB', 'bd_c1000000000b' ),
			)
		);

		$skin = new Babilim_Test_Real_Core_Skin();
		$lpu  = new Babilim_Test_Real_Core_Lpu( $skin );

		$items = array(
			$this->ours( 'bd_c1000000000a', 'c1-plugin-a', 'ja' ),
			$this->ours( 'bd_c1000000000b', 'c1-plugin-b', 'en_GB' ),
		);

		$lpu->bulk_upgrade( $items );

		$this->assertNotContains( 'error:babilim_handled', $skin->log );
		$this->assertNotContains( 'feedback:process_failed', $skin->log );

		// Both records survive -- neither overwritten by the other's
		// save() -- and both served files are actually on disk.
		$this->assertNotNull( $manifest->installed( 'plugin', 'c1-plugin-a', 'ja' ), 'The first item\'s manifest record must survive the second item\'s own save().' );
		$this->assertNotNull( $manifest->installed( 'plugin', 'c1-plugin-b', 'en_GB' ), 'The second item\'s manifest record must be present.' );
		$this->assertTrue( is_readable( Babilim_Paths::file( 'plugins', 'c1-plugin-a', 'ja', 'mo' ) ), 'The first item\'s served .mo must still exist.' );
		$this->assertTrue( is_readable( Babilim_Paths::file( 'plugins', 'c1-plugin-b', 'en_GB', 'mo' ) ), 'The second item\'s served .mo must still exist.' );
		$this->assertSame( array(), ( new Babilim_State() )->updates(), 'Both adopted builds must be removed from state.' );

		$this->rimraf( WP_CONTENT_DIR . '/upgrade' );
	}

	/**
	 * Manifest-instance-sharing regression, the other trigger order: if
	 * something in the SAME request already forced Babilim::
	 * default_index() to be built (and it read `_manifest.json` into its
	 * own Babilim_Manifest snapshot) BEFORE a translation update runs,
	 * every item's own newly-adopted record -- not just the second one
	 * onward -- was lost the moment updater()'s own (separately built,
	 * pre-fix) Babilim_Manifest snapshot's save() overwrote the file
	 * again afterward. A real single request can do exactly this: e.g.
	 * WP_Automatic_Updater updating a plugin's body first (firing
	 * `upgrader_process_complete` -> rebuild_default_index_all_locales())
	 * and only then processing queued translation updates.
	 */
	public function test_installing_after_an_earlier_rebuild_in_the_same_request_keeps_the_manifest_record(): void {
		( new Babilim_State() )->set(
			array(
				'updates' => array(
					array( 'kind' => 'plugin', 'slug' => 'c1-plugin-c', 'locale' => 'sv_SE', 'build_id' => 'bd_c1000000000c', 'build_no' => 1 ),
				),
			)
		);

		list( $manifest, $fs ) = $this->arm_assembly_layer(
			array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_c1000000000c?sig=c' ) ) )
		);

		// Force default_index() to be built -- and its own Babilim_Manifest
		// snapshot loaded -- BEFORE the translation update below ever runs,
		// exactly as an earlier plugin-body update in the same request
		// would via `upgrader_process_complete`.
		Babilim::instance()->rebuild_default_index_all_locales();

		$this->arm_download( $this->our_fixture( 'c1-plugin-c', 'sv_SE', 'bd_c1000000000c' ) );

		$skin = new Babilim_Test_Real_Core_Skin();
		$lpu  = new Babilim_Test_Real_Core_Lpu( $skin );
		$lpu->bulk_upgrade( array( $this->ours( 'bd_c1000000000c', 'c1-plugin-c', 'sv_SE' ) ) );

		$this->assertNotContains( 'feedback:process_failed', $skin->log );
		$this->assertNotNull( $manifest->installed( 'plugin', 'c1-plugin-c', 'sv_SE' ), 'The record must survive an earlier, same-request rebuild_default_index_all_locales() call.' );
		$this->assertTrue( is_readable( Babilim_Paths::file( 'plugins', 'c1-plugin-c', 'sv_SE', 'mo' ) ) );

		$this->rimraf( WP_CONTENT_DIR . '/upgrade' );
	}

	/**
	 * Regression: Babilim::core_has() must answer from WordPress core's
	 * own real language-pack files on disk (WP_LANG_DIR/{locale}.*,
	 * admin-{locale}.*, etc.) -- never from WP_Translation_Controller's
	 * live `default`-domain state, which also holds whatever THIS
	 * PLUGIN's own load_default_supplements() already loaded for the
	 * current request into that exact same domain+locale slot. Asking
	 * the controller would make a rebuild() see its own, previously
	 * merged entries reflected back as "core already has this" and
	 * exclude them -- see class-babilim.php's core_has() own docblock.
	 *
	 * This only checks the real-file half directly (a real .mo on disk,
	 * found; an unrelated string, not invented). The self-contamination
	 * scenario itself -- rebuild() emptying its own already-loaded merged
	 * index -- is covered by the Playground check that reproduces it end
	 * to end in a real WordPress boot (b3e/the R-shaped check).
	 */
	public function test_core_has_reads_real_core_language_files_directly(): void {
		$mo = $this->mo( array( 'Core Official String' => 'x' ) );
		if ( ! is_dir( WP_LANG_DIR ) ) {
			mkdir( WP_LANG_DIR, 0777, true );
		}
		file_put_contents( trailingslashit( WP_LANG_DIR ) . 'es_ES.mo', $mo );

		$this->assertTrue( Babilim::instance()->core_has( 'Core Official String', '', 'es_ES' ), 'A string a real core language file has must be found.' );
		$this->assertFalse( Babilim::instance()->core_has( 'Not In Any Core File', '', 'es_ES' ), 'A string no core file has must not be invented.' );
		// A different locale core has no file for at all -- distinguished
		// internally from "checked, found nothing" (see
		// core_default_domain_entries()'s own docblock), but core_has()
		// itself must still just answer false either way.
		$this->assertFalse( Babilim::instance()->core_has( 'Core Official String', '', 'xx_XX' ) );
	}

	/**
	 * A 'translation' upgrader_process_complete (a core language pack
	 * install/update, among other things) must clear core_has()'s own
	 * per-locale cache (core_default_entries_cache) -- otherwise a
	 * core_has() call earlier in the same request, before the new core
	 * language file existed, would keep returning its own stale cached
	 * answer for the rest of the request, even after the file this same
	 * event just installed would change that answer.
	 */
	public function test_translation_upgrader_process_complete_clears_the_core_has_cache(): void {
		if ( ! is_dir( WP_LANG_DIR ) ) {
			mkdir( WP_LANG_DIR, 0777, true );
		}

		// Nothing installed yet for fr_FR -- cached as "core has
		// nothing" the moment this is first checked.
		$this->assertFalse( Babilim::instance()->core_has( 'Freshly Installed String', '', 'fr_FR' ) );

		// A real core language pack lands mid-request (what a real
		// Language_Pack_Upgrader run for this locale would produce).
		file_put_contents( trailingslashit( WP_LANG_DIR ) . 'fr_FR.mo', $this->mo( array( 'Freshly Installed String' => 'x' ) ) );

		Babilim::instance()->on_upgrader_process_complete( new stdClass(), array( 'type' => 'translation' ) );

		$this->assertTrue(
			Babilim::instance()->core_has( 'Freshly Installed String', '', 'fr_FR' ),
			'a translation-type upgrader_process_complete must clear the stale per-locale cache so the just-installed file is actually read'
		);
	}

	/**
	 * Every file under WP_LANG_DIR, outside this plugin's own `babilim/`
	 * subtree, as relative path => contents.
	 */
	private function lang_dir_files(): array {
		$root = rtrim( WP_LANG_DIR, '/' );
		$out  = array();
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			if ( 0 === strpos( $relative, 'babilim/' ) || ! $file->isFile() ) {
				continue;
			}
			$out[ $relative ] = (string) file_get_contents( $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	private function break_updater_construction(): void {
		$property = new ReflectionProperty( Babilim::class, 'site' );
		$property->setAccessible( true );
		$property->setValue( Babilim::instance(), new stdClass() );
	}

	/**
	 * Replaces Babilim's own updater with $updater (see the `$updater`
	 * property's own docblock).
	 */
	private function inject_updater( Babilim_Updater_Contract $updater ): void {
		$property = new ReflectionProperty( Babilim::class, 'updater' );
		$property->setAccessible( true );
		$property->setValue( Babilim::instance(), $updater );
	}

	/** The real production Babilim_Updater, built by Babilim::updater() itself. */
	private function real_updater(): Babilim_Updater {
		$method = new ReflectionMethod( Babilim::class, 'updater' );
		$method->setAccessible( true );
		$updater = $method->invoke( Babilim::instance() );
		$this->assertInstanceOf( Babilim_Updater::class, $updater );
		return $updater;
	}

	/**
	 * r3-2 / P4: a Throwable while building Babilim_Updater must never
	 * escape `upgrader_package_options` (or any other of its filters) into
	 * an ordinary wp.org plugin update -- run through core's own real
	 * WP_Upgrader::run(), which applies that filter first.
	 */
	public function test_wporg_plugin_update_completes_when_the_updater_cannot_be_built(): void {
		$this->arm_assembly_layer( array() );
		$this->break_updater_construction();

		$fixture = babilim_test_real_core_tmp_dir() . '/babilim-real-core-plugin-' . bin2hex( random_bytes( 4 ) );
		mkdir( $fixture . '/akismet', 0777, true );
		file_put_contents( $fixture . '/akismet/akismet.php', 'NEW' );
		$package = babilim_test_real_core_tmp_dir() . '/babilim-real-core-plugin-pkg-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		file_put_contents( $package, 'zip' );
		$GLOBALS['babilim_test_real_core_zip_map'][ $package ] = $fixture;
		// WP_PLUGIN_DIR itself may already be defined by another test file
		// as a path that doesn't exist on disk, so this installs into a
		// real directory under WP_CONTENT_DIR instead -- the hook_extra is
		// what makes it a plugin update for every filter involved.
		$plugin_dir = WP_CONTENT_DIR . '/plugins/akismet';
		mkdir( $plugin_dir, 0777, true );
		file_put_contents( $plugin_dir . '/akismet.php', 'OLD' );

		$skin = new Babilim_Test_Real_Core_Skin();
		$lpu  = new Babilim_Test_Real_Core_Lpu( $skin );

		$result = $lpu->run(
			array(
				'package'                     => $package,
				'destination'                 => $plugin_dir,
				'clear_destination'           => false,
				'abort_if_destination_exists' => false,
				'clear_working'               => true,
				'hook_extra'                  => array(
					'plugin' => 'akismet/akismet.php',
					'type'   => 'plugin',
					'action' => 'update',
				),
			)
		);

		$this->assertIsArray( $result, 'The update must succeed: ' . implode( ',', $skin->log ) );
		$this->assertSame( 'NEW', file_get_contents( $plugin_dir . '/akismet.php' ) );

		// A wp.org language pack in a bulk run is equally unaffected.
		$lpu->bulk_upgrade( array( $this->wporg( 'akismet', 'de_DE', $this->wporg_fixture( 'akismet', 'de_DE' ) ) ) );
		$this->assertSame( 'WPORG-NEW-MO', file_get_contents( $this->lang_plugins . '/akismet-de_DE.mo' ) );
	}

	public function provide_own_item_failure_points(): array {
		return array(
			'updater construction'   => array( 'construction' ),
			'package_options'        => array( 'package_options' ),
			'pre_download'           => array( 'pre_download' ),
			'source_selection'       => array( 'source_selection' ),
			'install_package_result' => array( 'install_package_result' ),
		);
	}

	/**
	 * r3-2, fail closed: whichever hook throws for one of this plugin's
	 * own items, that item fails, is kept in state for a later retry,
	 * and core never copies anything into (or clears anything from)
	 * WordPress core's own language directory for it -- the pre-existing
	 * wp.org pack for the same slug/locale stays byte-for-byte intact.
	 *
	 * @dataProvider provide_own_item_failure_points
	 */
	public function test_own_item_fails_closed_when_a_hook_throws( string $failure_point ): void {
		$this->seed_wporg_existing();
		( new Babilim_State() )->set(
			array(
				'updates' => array(
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_ea1c0de00002', 'build_no' => 1 ),
				),
			)
		);
		list( $manifest ) = $this->arm_assembly_layer(
			array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_ea1c0de00002?sig=a' ) ) )
		);
		$this->arm_download( $this->our_fixture( 'woocommerce', 'zh_TW', 'bd_ea1c0de00002' ) );

		if ( 'construction' === $failure_point ) {
			$this->break_updater_construction();
		} elseif ( 'source_selection' === $failure_point ) {
			$real = $this->real_updater();
			$args = array();
			foreach ( array( 'state', 'installer', 'api' ) as $name ) {
				$property = new ReflectionProperty( Babilim_Updater::class, $name );
				$property->setAccessible( true );
				$args[] = $property->getValue( $real );
			}
			$this->inject_updater( new Babilim_Updater( $args[0], $args[1], $args[2], new Babilim_Test_Real_Core_Throwing_Adopt_Fs( new Babilim_Wp_Fs() ), BABILIM_API_BASE ) );
		} else {
			$this->inject_updater( new Babilim_Test_Real_Core_Throwing_Updater( $this->real_updater(), $failure_point ) );
		}

		$before = $this->lang_dir_files();
		$skin   = new Babilim_Test_Real_Core_Skin();
		$lpu    = new Babilim_Test_Real_Core_Lpu( $skin );

		$lpu->bulk_upgrade( array( $this->ours( 'bd_ea1c0de00002', 'woocommerce' ) ) );

		$this->assertNotContains( 'feedback:process_success', $skin->log, 'The item must fail: ' . implode( ',', $skin->log ) );
		// A refused download is reported through skin->error() alone (run()
		// returns before set_result()); a failed install sets a WP_Error result.
		$this->assertTrue(
			is_wp_error( $skin->result ) || array() !== preg_grep( '/^error:/', $skin->log ),
			'The item must fail: ' . implode( ',', $skin->log )
		);
		$this->assertSame( $before, $this->lang_dir_files(), 'Nothing outside babilim/ may be added, changed, or removed under WP_LANG_DIR.' );
		$this->assertCount( 1, ( new Babilim_State() )->updates(), 'The failed build stays pending for a later retry.' );
		if ( 'install_package_result' !== $failure_point ) {
			$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
		}

		$this->rimraf( WP_CONTENT_DIR . '/upgrade' );
	}
}

/**
 * Delegates to a real Babilim_Updater, except that one named method
 * throws instead -- simulating an unexpected Throwable inside that one
 * hook, with every other hook behaving exactly as in production.
 */
final class Babilim_Test_Real_Core_Throwing_Updater implements Babilim_Updater_Contract {

	private $inner;
	private $throw_in;

	public function __construct( Babilim_Updater $inner, string $throw_in ) {
		$this->inner    = $inner;
		$this->throw_in = $throw_in;
	}

	private function maybe_throw( string $method ): void {
		if ( $method === $this->throw_in ) {
			throw new RuntimeException( "{$method}() failed." );
		}
	}

	public function inject_plugin_transient( $transient ): object {
		$this->maybe_throw( 'inject_plugin_transient' );
		return $this->inner->inject_plugin_transient( $transient );
	}

	public function inject_theme_transient( $transient ): object {
		$this->maybe_throw( 'inject_theme_transient' );
		return $this->inner->inject_theme_transient( $transient );
	}

	public function package_options( array $options ): array {
		$this->maybe_throw( 'package_options' );
		return $this->inner->package_options( $options );
	}

	public function pre_download( $reply, string $package, $upgrader, array $hook_extra ) {
		$this->maybe_throw( 'pre_download' );
		return $this->inner->pre_download( $reply, $package, $upgrader, $hook_extra );
	}

	public function install_package_result( $result, array $hook_extra ) {
		$this->maybe_throw( 'install_package_result' );
		return $this->inner->install_package_result( $result, $hook_extra );
	}

	public function sweep_stale_incoming_dirs( int $max_age_seconds = 86400 ): int {
		return $this->inner->sweep_stale_incoming_dirs( $max_age_seconds );
	}
}

/**
 * A Babilim_Fs whose put() throws for anything under `_adopt/` -- makes
 * the `upgrader_source_selection` callback (which stages the package
 * there) throw partway through, on real disk otherwise.
 */
final class Babilim_Test_Real_Core_Throwing_Adopt_Fs implements Babilim_Fs {

	private $inner;

	public function __construct( Babilim_Fs $inner ) {
		$this->inner = $inner;
	}

	public function put( string $path, string $contents ): bool {
		if ( false !== strpos( $path, '/_adopt/' ) ) {
			throw new RuntimeException( 'Staging write failed.' );
		}
		return $this->inner->put( $path, $contents );
	}

	public function get( string $path ) {
		return $this->inner->get( $path );
	}

	public function exists( string $path ): bool {
		return $this->inner->exists( $path );
	}

	public function delete( string $path ): bool {
		return $this->inner->delete( $path );
	}

	public function rename( string $from, string $to ): bool {
		return $this->inner->rename( $from, $to );
	}

	public function mkdir( string $path ): bool {
		return $this->inner->mkdir( $path );
	}

	public function list( string $dir, string $glob = '*' ): array {
		return $this->inner->list( $dir, $glob );
	}

	public function sha256( string $path ) {
		return $this->inner->sha256( $path );
	}

	public function rmdir( string $path ): bool {
		return $this->inner->rmdir( $path );
	}

	public function is_dir( string $path ): bool {
		return $this->inner->is_dir( $path );
	}
}
