<?php
/**
 * Tests for uninstall.php: every option/transient the site-identity/
 * HTTP-client task introduced (Babilim_Site/Babilim_State) must be gone
 * once the plugin is uninstalled, including the install keypair's secret
 * key and the site's bearer token -- see wp-plugin-development's
 * references/lifecycle.md ("Uninstall") and uninstall.php's own
 * docblock.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Uninstall extends TestCase {

	/**
	 * Real-disk paths (under the shared tests/bootstrap.php WP_LANG_DIR
	 * fixture area) a test wrote through Babilim_Wp_Fs and must clean up
	 * -- deleted in tearDown() rather than at the end of the test's own
	 * body, so a failed assertion partway through still leaves the
	 * shared area clean for whatever test runs next (a fixture left
	 * behind by a thrown assertion would otherwise pollute the rest of
	 * the suite).
	 *
	 * @var array{fs: Babilim_Wp_Fs, paths: string[]}|null
	 */
	private $realDiskCleanup;

	protected function setUp(): void {
		babilim_test_reset();
		$this->realDiskCleanup = null;
	}

	protected function tearDown(): void {
		babilim_test_rmtree( Babilim_Paths::root() );
		babilim_test_rmtree( rtrim( WP_LANG_DIR, '/' ) . '/plugins' );
		if ( null !== $this->realDiskCleanup ) {
			foreach ( $this->realDiskCleanup['paths'] as $path ) {
				$this->realDiskCleanup['fs']->delete( $path );
			}
		}
	}

	public function test_uninstall_with_delete_all_removes_every_option_and_transient(): void {
		update_option( 'babilim_delete_data_on_uninstall', true );
		update_option( 'babilim_verification_warning', array( 'reason' => 'callback_unreachable' ) );
		update_option( 'babilim_auto_install_failures', array( 'bd_000000000001' => 1 ) );
		update_option( 'babilim_auto_install', 0, false );
		update_option( 'babilim_auto_install_skip', array( 'plugin:a:de_DE' => true ), false );
		update_option( 'babilim_auto_installed', 3, false );
		update_option( 'babilim_install_sk', 'sk', false );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x', false );
		update_option( 'babilim_identity', array( 'site_id' => 'st_x', 'token' => 'tok_x' ), false );
		update_option( 'babilim_pending_verification', array( 'site_id' => 'st_x', 'nonce' => 'n', 'expires_at' => 'e' ) );
		update_option( 'babilim_verify_conflict', true );
		update_option( 'babilim_verify_attempts', 2 );
		update_option( 'babilim_verify_stalled', true );
		set_transient( 'babilim_challenge_rl', array( 'window_start' => time(), 'count' => 5 ), MINUTE_IN_SECONDS );
		update_option( 'babilim_state', array( 'contract' => '1.1.1' ) );
		update_option( 'babilim_client_events', array( array( 'code' => 'integrity_failed' ) ) );
		update_option( 'babilim_plugin_slugs', array( 'hello.php' => 'hello-dolly' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( array(
			'babilim_install_sk',
			'babilim_site_id',
			'babilim_site_token',
			'babilim_identity',
			'babilim_pending_verification',
			'babilim_verify_conflict',
			'babilim_verify_attempts',
			'babilim_verify_stalled',
			'babilim_state',
			'babilim_client_events',
			'babilim_plugin_slugs',
			'babilim_verification_warning',
			'babilim_auto_install_failures',
			'babilim_auto_install',
			'babilim_auto_install_skip',
			'babilim_auto_installed',
			'babilim_delete_data_on_uninstall',
		) as $option ) {
			$this->assertFalse( get_option( $option, false ), "{$option} must be removed by uninstall.php" );
		}
		$this->assertFalse( get_transient( 'babilim_challenge_rl' ), 'babilim_challenge_rl transient must be removed by uninstall.php' );
	}

	/**
	 * The admin screens' own options (consent, manual locales, the
	 * keep-custom-on-purge setting) must also be gone, cleaned up the
	 * same way as the site-identity options.
	 */
	public function test_uninstall_removes_the_admin_screens_own_settings_options(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_manual_locales', array( 'pt_BR' ) );
		update_option( 'babilim_keep_custom_on_purge', 0 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( array( 'babilim_consent', 'babilim_manual_locales', 'babilim_keep_custom_on_purge' ) as $option ) {
			$this->assertFalse( get_option( $option, false ), "{$option} must be removed by uninstall.php" );
		}
	}

	/**
	 * The manifest's own locally-recorded write-failure note (shown on
	 * the settings screen) is a network-wide option this plugin creates,
	 * too.
	 */
	public function test_uninstall_removes_the_manifest_write_failure_record(): void {
		update_site_option( 'babilim_manifest_write_failure', array( 'at' => '2026-01-01T00:00:00+00:00', 'section' => 'records', 'key' => 'k', 'reason' => 'lock_unavailable' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_site_option( 'babilim_manifest_write_failure', false ) );
	}

	/**
	 * The settings screen's own meta-locales catalog cache (see
	 * Babilim_Admin::render_managed_locales()) must not survive an
	 * uninstall/reinstall cycle either.
	 */
	public function test_uninstall_removes_the_meta_locales_cache_transient(): void {
		set_transient( 'babilim_meta_locales_cache', array( 'ja', 'de_DE' ), 12 * HOUR_IN_SECONDS );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_transient( 'babilim_meta_locales_cache' ), 'babilim_meta_locales_cache transient must be removed by uninstall.php' );
	}

	public function test_uninstall_removes_the_meta_locales_failure_transient(): void {
		set_transient( 'babilim_meta_locales_failed', 1, 300 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_transient( 'babilim_meta_locales_failed' ) );
	}

	/**
	 * The language names kept from the service's locale list are
	 * Babilim's own option and go with the rest.
	 */
	public function test_uninstall_removes_the_stored_language_names(): void {
		update_option( 'babilim_locale_names', array( 'ja' => 'Nihongo' ), false );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( 'babilim_locale_names', false ), 'babilim_locale_names must be removed by uninstall.php' );
	}

	/**
	 * uninstall.php must also purge every installed language file itself
	 * (Babilim_Installer::purge_all()), not just the plugin's own
	 * options -- respecting whatever the site owner set
	 * babilim_keep_custom_on_purge to.
	 */
	public function test_uninstall_purges_installed_files_and_respects_keep_custom(): void {
		update_option( 'babilim_keep_custom_on_purge', 1 );

		require_once dirname( __DIR__, 2 ) . '/includes/class-babilim-paths.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-babilim-fs.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-babilim-wp-fs.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-babilim-manifest.php';

		$fs          = new Babilim_Wp_Fs();
		$manifest    = new Babilim_Manifest( $fs );
		$main_path   = Babilim_Paths::file( 'plugins', 'uninstall-test-plugin', 'zh_TW', 'mo' );
		$custom_path = Babilim_Paths::file( 'custom/plugins', 'uninstall-test-plugin', 'zh_TW', 'mo' );
		// Registered for tearDown() before anything is actually written,
		// so a failure partway through this method still leaves nothing
		// behind for later tests to trip over.
		$this->realDiskCleanup = array(
			'fs'    => $fs,
			'paths' => array( $main_path, $custom_path, Babilim_Paths::manifest_file() ),
		);
		$fs->put( $main_path, 'served' );
		$fs->put( $custom_path, 'site override' );
		// 'files' is a relative-path => sha256-STRING map (see
		// Babilim_Manifest's own class docblock) -- not the {sha256,
		// size} shape a package's manifest.json entry uses.
		$saved = $manifest->set(
			'plugin',
			'uninstall-test-plugin',
			'zh_TW',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'plugins/uninstall-test-plugin-zh_TW.mo' => hash( 'sha256', 'served' ) ),
				'default_files'     => array(),
			)
		);
		$this->assertTrue( $saved, 'test fixture setup: manifest record must save successfully' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( $fs->exists( $main_path ), 'the installed language file must be purged' );
		$this->assertTrue( $fs->exists( $custom_path ), 'custom/ must survive -- keep_custom was on' );
		// Cleanup of the shared real-disk fixture area happens in
		// tearDown() (see $realDiskCleanup's own docblock), not here.
	}

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * Writes a set of real files of every kind Babilim keeps, a custom/
	 * override, and a sibling file WordPress core itself owns.
	 *
	 * @return array{managed: string[], custom: string, core: string}
	 */
	private function seed_real_disk(): array {
		$fs = new Babilim_Wp_Fs();
		Babilim_Paths::ensure_layout( $fs );
		$managed = array(
			Babilim_Paths::manifest_file(),
			Babilim_Paths::file( 'plugins', 'orphan-plugin', 'de_DE', 'mo' ),
			Babilim_Paths::file( 'themes', 'orphan-theme', 'de_DE', 'json' ),
			Babilim_Paths::merged_default( 'de_DE', 'mo' ),
			Babilim_Paths::merged_default( 'de_DE', 'l10n.php' ),
			Babilim_Paths::file( 'default/_parts', 'orphan-plugin', 'de_DE', 'mo' ),
			Babilim_Paths::version_dir( 'plugins', 'orphan-plugin', 'de_DE', 'bd_5e2a9c1f3d70' ) . '/orphan-plugin-de_DE.po',
		);
		foreach ( $managed as $path ) {
			$fs->put( $path, 'x' );
		}
		// A .mo directly under _incoming/ is never something this
		// plugin's own write path puts there (see
		// INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock) -- only
		// a foreign process, bypassing Babilim_Fs entirely,
		// ever leaves one here, so this fixture is written by raw PHP,
		// not $fs->put(). Still part of $managed for this method's own
		// caller assertions below.
		$incoming_leftover = Babilim_Paths::incoming_dir( 'tok' ) . '/left-over.mo';
		mkdir( dirname( $incoming_leftover ), 0777, true );
		file_put_contents( $incoming_leftover, 'x' );
		$managed[] = $incoming_leftover;
		// The installer's lock file is opened with fopen() (flock() needs
		// a native handle), so it is created outside Babilim_Fs too.
		$install_lock = Babilim_Paths::root() . '/.install.lock';
		file_put_contents( $install_lock, '' );
		$managed[] = $install_lock;
		$custom = Babilim_Paths::file( 'custom/plugins', 'orphan-plugin', 'de_DE', 'mo' );
		$fs->put( $custom, 'site override' );
		$core = rtrim( WP_LANG_DIR, '/' ) . '/plugins/core-owned-de_DE.mo';
		mkdir( dirname( $core ), 0777, true );
		file_put_contents( $core, 'core' );
		return array( 'managed' => $managed, 'custom' => $custom, 'core' => $core );
	}

	public function test_uninstall_removes_every_managed_file_including_default_supplements_and_history(): void {
		update_option( 'babilim_keep_custom_on_purge', 0 );
		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		foreach ( $seed['managed'] as $path ) {
			$this->assertFileDoesNotExist( $path, "{$path} must be removed by uninstall.php" );
		}
		$this->assertDirectoryDoesNotExist( Babilim_Paths::root(), 'with keep-custom off, the whole babilim/ directory goes' );
	}

	public function test_uninstall_keeps_custom_and_the_root_guard_files_when_keep_custom_is_on(): void {
		update_option( 'babilim_keep_custom_on_purge', 1 );
		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		foreach ( $seed['managed'] as $path ) {
			$this->assertFileDoesNotExist( $path, "{$path} must be removed by uninstall.php" );
		}
		$this->assertFileExists( $seed['custom'], 'custom/ must never be deleted while keep-custom is on' );
		$this->assertFileExists( Babilim_Paths::root() . '/index.php' );
	}

	public function test_uninstall_keeps_custom_when_the_setting_was_never_saved(): void {
		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		$this->assertFileExists( $seed['custom'], 'the default (option absent) is to keep custom/' );
	}

	public function test_uninstall_never_touches_core_owned_language_directories(): void {
		update_option( 'babilim_keep_custom_on_purge', 0 );
		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		$this->assertFileExists( $seed['core'], 'WP_LANG_DIR/plugins belongs to WordPress core' );
	}

	public function test_uninstall_still_removes_files_when_the_manifest_is_corrupt(): void {
		update_option( 'babilim_keep_custom_on_purge', 0 );
		$seed = $this->seed_real_disk();
		file_put_contents( Babilim_Paths::manifest_file(), '{not json' );

		$this->run_uninstall();

		foreach ( $seed['managed'] as $path ) {
			$this->assertFileDoesNotExist( $path, "{$path} must be removed even when the manifest is unreadable" );
		}
	}

	/**
	 * The install keypair's secret key and the site's bearer token must
	 * be deleted using nothing but plain WordPress core functions,
	 * textually before this plugin's own files are ever `require`d --
	 * so a broken class file (a bad release, a half-written disk) can
	 * never stop them from being deleted. A static check on the source,
	 * not a behavioral one: the point is the *unconditional* ordering,
	 * which this proves directly instead of only for whichever specific
	 * failure mode a behavioral test happens to simulate.
	 */
	public function test_secrets_are_deleted_before_any_of_this_plugins_files_are_required(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$delete_install_sk = strpos( $source, "delete_option( 'babilim_install_sk' )" );
		$delete_site_token = strpos( $source, "delete_option( 'babilim_site_token' )" );
		$first_require      = strpos( $source, 'require_once' );

		$this->assertNotFalse( $delete_install_sk, 'uninstall.php must still delete babilim_install_sk' );
		$this->assertNotFalse( $delete_site_token, 'uninstall.php must still delete babilim_site_token' );
		$this->assertNotFalse( $first_require, 'uninstall.php must still require this plugin\'s own files somewhere' );
		$this->assertLessThan( $first_require, $delete_install_sk, 'the install keypair secret must be deleted before any require_once' );
		$this->assertLessThan( $first_require, $delete_site_token, 'the site bearer token must be deleted before any require_once' );
	}

	/**
	 * Behavioral companion to the static check above: when the stage
	 * that needs this plugin's own classes throws (simulated here via
	 * Babilim_Wp_Fs's own real failure mode -- WP_Filesystem() failing
	 * to initialize -- rather than an actually-broken file, which would
	 * mean shipping a broken fixture file in this repo), the secrets
	 * already deleted before that stage ran must stay deleted.
	 */
	public function test_secrets_stay_deleted_even_when_the_file_purge_stage_throws(): void {
		update_option( 'babilim_delete_data_on_uninstall', true );
		update_option( 'babilim_install_sk', 'sk-x', false );
		update_option( 'babilim_site_token', 'tok-x', false );
		$GLOBALS['babilim_test_wp_filesystem_should_fail'] = true;

		$this->run_uninstall();

		$this->assertFalse( get_option( 'babilim_install_sk', false ), 'the install keypair secret must be deleted even though the file-purge stage threw' );
		$this->assertFalse( get_option( 'babilim_site_token', false ), 'the site token must be deleted even though the file-purge stage threw' );
	}

	public function test_uninstall_clears_both_scheduled_events(): void {
		wp_schedule_event( time(), 'twicedaily', 'babilim_sync' );
		wp_schedule_event( time(), 'daily', 'babilim_maintenance' );

		$this->run_uninstall();

		$this->assertFalse( wp_next_scheduled( 'babilim_sync' ), 'the sync cron event must be cleared' );
		$this->assertFalse( wp_next_scheduled( 'babilim_maintenance' ), 'the maintenance cron event must be cleared' );
	}

	public function test_uninstall_leaves_cores_settings_notice_transient_alone(): void {
		set_transient( 'settings_errors', array( 'x' ), MINUTE_IN_SECONDS );

		$this->run_uninstall();

		$this->assertSame( array( 'x' ), get_transient( 'settings_errors' ) );
	}

	/**
	 * Nothing of this plugin is ever injected into core's update
	 * transients, so uninstall must leave them exactly as they are.
	 */
	public function test_uninstall_leaves_core_update_transients_untouched(): void {
		$plugins = (object) array(
			'translations' => array(
				(object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE' ),
			),
		);
		set_site_transient( 'update_plugins', $plugins );

		$this->run_uninstall();

		$this->assertEquals( $plugins, get_site_transient( 'update_plugins' ) );
	}

	/**
	 * On a multisite network, uninstall must switch into every site and
	 * remove each one's own options (including its own install keypair
	 * and bearer token) -- not just the site the deletion happened to
	 * run on.
	 */
	public function test_uninstall_cleans_every_site_on_a_multisite_network(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );

		switch_to_blog( 1 );
		update_option( 'babilim_delete_data_on_uninstall', true );
		update_option( 'babilim_install_sk', 'sk-site-1', false );
		update_option( 'babilim_site_token', 'tok-site-1', false );
		restore_current_blog();

		switch_to_blog( 2 );
		update_option( 'babilim_delete_data_on_uninstall', true );
		update_option( 'babilim_install_sk', 'sk-site-2', false );
		update_option( 'babilim_site_token', 'tok-site-2', false );
		restore_current_blog();

		$this->run_uninstall();

		switch_to_blog( 1 );
		$this->assertFalse( get_option( 'babilim_install_sk', false ), 'site 1 keypair must be removed' );
		$this->assertFalse( get_option( 'babilim_site_token', false ), 'site 1 token must be removed' );
		restore_current_blog();

		switch_to_blog( 2 );
		$this->assertFalse( get_option( 'babilim_install_sk', false ), 'site 2 keypair must be removed' );
		$this->assertFalse( get_option( 'babilim_site_token', false ), 'site 2 token must be removed' );
		restore_current_blog();
	}

	/**
	 * The default: deleting the plugin keeps the site identity (install
	 * keypair secret, site id, site token) and removes everything else, so
	 * a reinstall picks the same registration back up.
	 */
	public function test_uninstall_keeps_the_site_identity_by_default_and_removes_everything_else(): void {
		update_option( 'babilim_install_sk', 'sk-keep', false );
		update_option( 'babilim_site_id', 'st_keep' );
		update_option( 'babilim_site_token', 'tok_keep', false );
		update_option( 'babilim_identity', array( 'site_id' => 'st_keep', 'token' => 'tok_keep' ), false );
		update_option( 'babilim_consent', true );
		update_option( 'babilim_state', array( 'contract' => '1.3.0' ) );
		update_option( 'babilim_verification_warning', array( 'reason' => 'callback_timeout' ) );
		update_option( 'babilim_pending_verification', array( 'site_id' => 'st_keep', 'nonce' => 'n', 'expires_at' => 'e' ) );

		$this->run_uninstall();

		$this->assertSame( 'sk-keep', get_option( 'babilim_install_sk', false ) );
		$this->assertSame( 'st_keep', get_option( 'babilim_site_id', false ) );
		$this->assertSame( 'tok_keep', get_option( 'babilim_site_token', false ) );
		$this->assertSame( array( 'site_id' => 'st_keep', 'token' => 'tok_keep' ), get_option( 'babilim_identity', false ) );
		foreach ( array( 'babilim_consent', 'babilim_state', 'babilim_verification_warning', 'babilim_pending_verification' ) as $option ) {
			$this->assertFalse( get_option( $option, false ), "{$option} must still be removed by a default uninstall" );
		}
	}

	/**
	 * Uninstall -> reactivate: the kept identity is what the site
	 * collaborator reads back, byte for byte, with no new registration
	 * and no new keypair; the public key derived from the kept secret is
	 * the same one the service already knows.
	 */
	public function test_uninstall_then_reactivate_reuses_the_same_site_id_token_and_public_key(): void {
		$make_site = static function ( $http ) {
			return new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), new Babilim_Inventory( static function () { return array(); }, static function () { return array(); }, static function () { return false; }, static function () { return array(); } ), new Babilim_Manifest( new Babilim_Memory_Fs() ) );
		};
		$public_key = static function ( Babilim_Site $site ): string {
			$method = new ReflectionMethod( $site, 'ensure_keypair' );
			$method->setAccessible( true );
			return bin2hex( sodium_crypto_sign_publickey_from_secretkey( $method->invoke( $site ) ) );
		};

		$sk = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		update_option( 'babilim_install_sk', base64_encode( $sk ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture key, not code.
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_reuse' );
		update_option( 'babilim_site_token', 'tok_reuse', false );
		$expected_public = bin2hex( sodium_crypto_sign_publickey_from_secretkey( $sk ) );

		$this->run_uninstall();

		update_option( 'babilim_consent', true ); // The owner re-enables Babilim after reinstalling.
		$http       = new Babilim_Fake_Http( array() );
		$site_after = $make_site( $http );
		$registered = $site_after->ensure_registered();

		$this->assertSame( 'st_reuse', $site_after->site_id() );
		$this->assertSame( 'tok_reuse', $site_after->token() );
		$this->assertSame( $expected_public, $public_key( $site_after ), 'the install keypair must not be regenerated' );
		$this->assertSame( array( 'site_id' => 'st_reuse', 'already_registered' => true ), $registered );
		$this->assertSame( array(), $http->requests, 'reusing the kept identity makes no request at all' );
	}

	/**
	 * On multisite the choice is per site: one site opting in to delete
	 * all data must not delete another site's kept identity.
	 */
	public function test_uninstall_on_multisite_decides_identity_deletion_per_site(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );

		switch_to_blog( 1 );
		update_option( 'babilim_delete_data_on_uninstall', true );
		update_option( 'babilim_site_token', 'tok-site-1', false );
		restore_current_blog();
		switch_to_blog( 2 );
		update_option( 'babilim_site_token', 'tok-site-2', false );
		restore_current_blog();

		$this->run_uninstall();

		switch_to_blog( 1 );
		$this->assertFalse( get_option( 'babilim_site_token', false ), 'the opted-in site loses its identity' );
		restore_current_blog();
		switch_to_blog( 2 );
		$this->assertSame( 'tok-site-2', get_option( 'babilim_site_token', false ), 'the other site keeps its identity' );
		restore_current_blog();
	}

	/**
	 * The shared WP_LANG_DIR/babilim/ language-file tree must still be
	 * purged on a multisite run -- every site here shares the same
	 * physical WP_LANG_DIR (the normal case).
	 */
	public function test_uninstall_purges_shared_language_files_once_on_multisite(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );
		update_option( 'babilim_keep_custom_on_purge', 0 );
		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		foreach ( $seed['managed'] as $path ) {
			$this->assertFileDoesNotExist( $path, "{$path} must be removed by a multisite uninstall run" );
		}
	}

	/**
	 * Structural guard for "exactly once": the language-file purge
	 * (`Babilim_Installer::purge_all()`/`remove_managed_tree()`) must
	 * not be reachable from the per-site cleanup function or from
	 * inside the multisite loop, and each must be called exactly once
	 * in the whole file -- unconditionally, after every site's own
	 * option cleanup has already run. A file-existence check alone
	 * cannot tell "purged once" apart from "purged once per site,
	 * where every purge after the first is a no-op on an already-empty
	 * tree" -- this test targets that distinction directly.
	 */
	public function test_language_file_purge_is_not_reachable_from_the_per_site_cleanup_path(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertSame( 1, substr_count( $source, '->purge_all(' ), 'purge_all() must be called exactly once in uninstall.php' );
		$this->assertSame( 1, substr_count( $source, '->remove_managed_tree(' ), 'remove_managed_tree() must be called exactly once in uninstall.php' );

		$fn_start = strpos( $source, 'function babilim_uninstall_clean_current_site_state(): bool {' );
		$this->assertNotFalse( $fn_start, 'uninstall.php must still define the per-site cleanup function' );
		$fn_body = $this->balanced_brace_block( $source, strpos( $source, '{', $fn_start ) );
		$this->assertStringNotContainsString( 'purge_all(', $fn_body, 'the per-site function must never purge language files itself' );
		$this->assertStringNotContainsString( 'remove_managed_tree(', $fn_body, 'the per-site function must never purge language files itself' );

		$loop_marker = strpos( $source, "get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as" );
		$this->assertNotFalse( $loop_marker, 'uninstall.php must still loop over get_sites() on multisite' );
		$loop_body = $this->balanced_brace_block( $source, strpos( $source, '{', $loop_marker ) );
		$this->assertStringNotContainsString( 'purge_all(', $loop_body, 'the multisite loop must never purge language files per site' );
		$this->assertStringNotContainsString( 'remove_managed_tree(', $loop_body, 'the multisite loop must never purge language files per site' );
	}

	/**
	 * The bracketed source between an opening `{` at $open_brace_pos and
	 * its matching `}`, inclusive -- used to isolate one function or
	 * loop body for a textual containment check.
	 */
	private function balanced_brace_block( string $source, int $open_brace_pos ): string {
		$depth = 0;
		$len   = strlen( $source );
		for ( $i = $open_brace_pos; $i < $len; $i++ ) {
			if ( '{' === $source[ $i ] ) {
				++$depth;
			} elseif ( '}' === $source[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $source, $open_brace_pos, $i - $open_brace_pos + 1 );
				}
			}
		}
		return substr( $source, $open_brace_pos );
	}

	/**
	 * switch_to_blog()/restore_current_blog() must be called in pairs
	 * around every site in the multisite loop -- proven by the current
	 * blog id ending back where it started (the main site) and the
	 * internal blog stack the test bootstrap uses to implement
	 * switch/restore ending empty, not by re-reading options (which
	 * would pass even if a `restore_current_blog()` call went missing,
	 * as long as every site's own options still got cleaned before the
	 * loop moved on regardless).
	 */
	public function test_uninstall_pairs_switch_to_blog_with_restore_current_blog_on_multisite(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2, 3 );
		$starting_blog_id                  = $GLOBALS['babilim_test_current_blog_id'];

		$this->run_uninstall();

		$this->assertSame( $starting_blog_id, $GLOBALS['babilim_test_current_blog_id'], 'the current site must be back where it started once every site has been processed' );
		$this->assertSame( array(), $GLOBALS['babilim_test_blog_stack'], 'every switch_to_blog() must have a matching restore_current_blog()' );
	}

	/**
	 * get_sites() must be asked for every site, not core's own default
	 * page size (100) -- proven with more than 100 registered sites and
	 * checking that the last one (which a `number => 0`-less call would
	 * silently drop) is actually reached: only that site sets its own
	 * `babilim_keep_custom_on_purge` to keep, so custom/ surviving is
	 * only possible if that site was really processed.
	 */
	public function test_uninstall_processes_every_site_when_there_are_more_than_the_default_page_size(): void {
		$site_count                        = 101;
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = range( 1, $site_count );

		for ( $id = 1; $id <= $site_count; $id++ ) {
			switch_to_blog( $id );
			update_option( 'babilim_keep_custom_on_purge', $site_count === $id ? 1 : 0 );
			restore_current_blog();
		}
		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		$this->assertFileExists( $seed['custom'], "site {$site_count} (past the default 100-site page size) must still be reached and its keep-custom setting honored" );
	}

	/**
	 * On multisite, if *any* site asked to keep custom/, the shared tree
	 * keeps it -- regardless of what any other site set, and regardless
	 * of which site is processed first. Site 1 (keep) is processed
	 * before site 2 (purge) here.
	 */
	public function test_multisite_keeps_shared_custom_when_the_first_processed_site_asks_to_keep_it(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );

		switch_to_blog( 1 );
		update_option( 'babilim_keep_custom_on_purge', 1 );
		restore_current_blog();
		switch_to_blog( 2 );
		update_option( 'babilim_keep_custom_on_purge', 0 );
		restore_current_blog();

		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		$this->assertFileExists( $seed['custom'], 'site 1 asked to keep custom/; site 2 (processed after it) asking to purge must not override that' );
	}

	/**
	 * Same as above with the settings and the site processing order
	 * reversed -- proves the outcome depends on "did any site ask to
	 * keep it", never on iteration order.
	 */
	public function test_multisite_keeps_shared_custom_when_the_last_processed_site_asks_to_keep_it(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );

		switch_to_blog( 1 );
		update_option( 'babilim_keep_custom_on_purge', 0 );
		restore_current_blog();
		switch_to_blog( 2 );
		update_option( 'babilim_keep_custom_on_purge', 1 );
		restore_current_blog();

		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		$this->assertFileExists( $seed['custom'], 'site 2 asked to keep custom/; being processed after site 1 (which asked to purge) must not override that' );
	}

	/**
	 * The other side of the same rule: custom/ is only purged when
	 * every site agreed to purge it.
	 */
	public function test_multisite_purges_shared_custom_only_when_every_site_agrees(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );

		switch_to_blog( 1 );
		update_option( 'babilim_keep_custom_on_purge', 0 );
		restore_current_blog();
		switch_to_blog( 2 );
		update_option( 'babilim_keep_custom_on_purge', 0 );
		restore_current_blog();

		$seed = $this->seed_real_disk();

		$this->run_uninstall();

		$this->assertFileDoesNotExist( $seed['custom'], 'every site agreed to purge custom/ -- it must go' );
	}

	public function test_uninstall_makes_no_network_request(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x', false );
		$this->seed_real_disk();

		$this->run_uninstall();

		$this->assertSame( array(), $GLOBALS['babilim_test_wp_remote_requests'], 'uninstall must never call the service' );
		$this->assertSame( array(), $GLOBALS['babilim_test_download_url_calls'] );
	}

	/**
	 * Audit guard: every option, transient and cron hook name this
	 * plugin's code defines must be handled by uninstall.php, and the
	 * plugin must not start writing user or post meta without uninstall
	 * learning about it.
	 */
	public function test_uninstall_covers_every_stored_name_the_code_defines(): void {
		$root    = dirname( __DIR__, 2 );
		$sources = array_merge(
			glob( $root . '/includes/*.php' ) ?: array(),
			glob( $root . '/admin/*.php' ) ?: array(),
			array( $root . '/babilim.php' )
		);
		$uninstall = (string) file_get_contents( $root . '/uninstall.php' );

		$names = array();
		foreach ( $sources as $file ) {
			$code = (string) file_get_contents( $file );
			preg_match_all( "/const\s+(?:[A-Z_]*OPTION[A-Z_]*|[A-Z_]*TRANSIENT[A-Z_]*|[A-Z_]*HOOK)\s*=\s*'(babilim_[a-z_]+)'/", $code, $m );
			$names = array_merge( $names, $m[1] );
			// Named constants above miss a key written as a bare string
			// literal straight into a *_option()/*_transient() call --
			// catch those too, so a future `update_option( 'babilim_x', ... )`
			// added without a constant still trips this guard.
			preg_match_all( "/\\b(?:set_transient|set_site_transient|update_option|add_option)\\(\\s*'(babilim_[a-z_]+)'/", $code, $m2 );
			$names = array_merge( $names, $m2[1] );
			$this->assertSame( 0, preg_match( '/\b(?:update|add|delete)_(?:user|post|term|comment)_meta\b|update_metadata\(|add_metadata\(/', $code ), basename( $file ) . ' writes meta -- uninstall.php must remove it' );
		}

		$this->assertGreaterThanOrEqual( 15, count( $names ), 'the scan itself must find the plugin\'s stored names' );
		foreach ( array_unique( $names ) as $name ) {
			$this->assertStringContainsString( "'{$name}'", $uninstall, "{$name} is stored by the plugin but not removed by uninstall.php" );
		}
	}
}
