<?php
/**
 * Tests for Babilim_Updater -- injecting the service's pending updates
 * into WordPress core's own translation-update transients, and adopting
 * a downloaded package once core has fetched it.
 *
 * The site_transient_update_plugins/_themes shape (a `translations[]`
 * array alongside `response`/`no_update`/`checked`) and the
 * upgrader_pre_download/upgrader_source_selection filter argument shapes
 * are modeled on WordPress core's own wp-includes/update.php
 * (wp_get_translation_updates()) and wp-admin/includes/class-language-
 * pack-upgrader.php (Language_Pack_Upgrader::bulk_upgrade(), which
 * removes any pre-existing `upgrader_post_install`/`upgrader_source_
 * selection` callback before its own per-item loop, then re-adds its own
 * check_package() on `upgrader_source_selection` at priority 10) and
 * wp-admin/includes/class-wp-upgrader.php (WP_Upgrader::download_package()
 * applying `upgrader_pre_download`, WP_Upgrader::install_package()
 * applying `upgrader_source_selection` and then, once `clear_working` is
 * true, deleting `$remote_source` recursively regardless of the copy's
 * own outcome).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Updater extends TestCase {

	/**
	 * Whether Babilim_Paths::root() already existed on real disk before
	 * this test ran -- tearDown() only removes the root itself when this
	 * test created it, but always removes any `_incoming` a real-disk
	 * test in this class left behind (WP_LANG_DIR is process-wide, so a
	 * leftover here leaks into an unrelated test's clean-slate
	 * assumptions under a different test order).
	 *
	 * @var bool
	 */
	private $root_existed = false;

	protected function setUp(): void {
		babilim_test_reset();
		$this->root_existed = is_dir( Babilim_Paths::root() );
	}

	protected function tearDown(): void {
		$this->rimraf( Babilim_Paths::incoming_root() );
		if ( ! $this->root_existed ) {
			$this->rimraf( Babilim_Paths::root() );
		}
		$GLOBALS['wp_filesystem'] = null;
	}

	// -- Fakes / fixtures --------------------------------------------------

	private function transform_fake( Babilim_Fs $fs ): callable {
		return static function ( string $mo_path ) use ( $fs ) {
			$content = $fs->get( $mo_path );
			$entries = is_string( $content ) ? babilim_test_read_mo( $content ) : array();
			unset( $entries[''] );
			return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
		};
	}

	private function parse_fake( Babilim_Fs $fs ): callable {
		return static function ( string $path, string $format ) use ( $fs ) {
			$content = $fs->get( $path );
			if ( ! is_string( $content ) ) {
				return false;
			}
			if ( 'php' === $format ) {
				$code = (string) preg_replace( '/^<\?php\s*/', '', $content );
				$data = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
				return is_array( $data['messages'] ?? null ) ? count( $data['messages'] ) : false;
			}
			$entries = babilim_test_read_mo( $content );
			unset( $entries[''] );
			return count( $entries );
		};
	}

	private function unzip_never_called(): callable {
		return static function ( string $zip_path ) {
			throw new RuntimeException( 'unzip should never be called by Babilim_Updater -- adopt() never uses it.' );
		};
	}

	private function mo_bytes( array $entries ): string {
		return Babilim_Mo_Writer::write( $entries, array() );
	}

	private function mo_entry_count( string $bytes ): int {
		$parsed = babilim_test_read_mo( $bytes );
		unset( $parsed[''] );
		return count( $parsed );
	}

	private function file_digest( string $bytes ): array {
		return array( 'sha256' => hash( 'sha256', $bytes ), 'size' => strlen( $bytes ) );
	}

	private function new_installer( Babilim_Fs $fs, Babilim_Manifest $manifest ): Babilim_Installer {
		return new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $this->unzip_never_called() );
	}

	/**
	 * @return array{0: string, 1: array<string,string>} [manifest.json JSON,
	 *         relative-path => bytes for every file the manifest declares].
	 */
	private function package_fixture( string $domain, string $locale, string $server_build_id, bool $with_default = false ): array {
		$mo = $this->mo_bytes( array( 'Save' => 'x' ) );
		$po = "# fake po\n";

		$files = array(
			$domain . '-' . $locale . '.mo' => $mo,
			$domain . '-' . $locale . '.po' => $po,
		);

		$manifest = array(
			'build_id'        => $domain . '-1.0.0-' . $locale . '-20260101T000000-abc123',
			'entries'         => $this->mo_entry_count( $mo ),
			'files'           => array(
				$domain . '-' . $locale . '.mo' => $this->file_digest( $mo ),
				$domain . '-' . $locale . '.po' => $this->file_digest( $po ),
			),
			'locale'          => $locale,
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => $domain,
			'version'         => '1.0.0',
			'text_domain'     => $domain,
			'extra_domains'   => array(),
			'server_build_id' => $server_build_id,
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.1.0',
		);

		if ( $with_default ) {
			$default_mo = $this->mo_bytes( array( 'Cart' => 'y' ) );
			$files['default/' . $domain . '-' . $locale . '.mo']    = $default_mo;
			$manifest['default_domain_entries']                     = $this->mo_entry_count( $default_mo );
			$manifest['default_domain_files']                       = array(
				$domain . '-' . $locale . '.mo' => $this->file_digest( $default_mo ),
			);
		}

		return array( (string) wp_json_encode( $manifest ), $files );
	}

	/**
	 * Writes $files (relative path => bytes) plus manifest.json for real,
	 * onto real disk under a fresh directory -- standing in for what
	 * WordPress core's own unpack_package() would have left as its
	 * per-item working directory (wp-admin/includes/class-wp-upgrader.php's
	 * unpack_package(): `WP_CONTENT_DIR/upgrade/{basename}`) before
	 * install_package() applies `upgrader_source_selection` (see the
	 * class docblock for why source_selection() reads this via raw
	 * WP_Filesystem, never Babilim_Fs).
	 *
	 * @param array<string,string> $files Relative path (zip-root-relative,
	 *                                    `default/` prefix included) => bytes.
	 * @return string The working directory's absolute path.
	 */
	private function write_working_dir( string $manifest_json, array $files ): string {
		$dir = get_temp_dir() . 'babilim-update-' . bin2hex( random_bytes( 8 ) ) . '/pkg';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/manifest.json', $manifest_json );
		foreach ( $files as $relative => $bytes ) {
			$path = $dir . '/' . $relative;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $bytes );
		}
		return $dir;
	}

	/**
	 * Best-effort recursive delete of a directory this test itself
	 * created on real disk -- test-only cleanup, never production code
	 * (see the class docblock for why Babilim_Updater itself never
	 * deletes anything under core's own working directory).
	 */
	private function rimraf( string $dir ): void {
		if ( is_link( $dir ) ) {
			unlink( $dir ); // Never follow a symlink out of the tree.
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			( is_dir( $path ) && ! is_link( $path ) ) ? $this->rimraf( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function new_updater( Babilim_Api_Client $api, Babilim_Fs $fs, Babilim_Manifest $manifest ): Babilim_Updater {
		$state     = new Babilim_State();
		$installer = $this->new_installer( $fs, $manifest );
		return new Babilim_Updater( $state, $installer, $api, $fs, 'https://api.babilim.org' );
	}

	private function set_updates( array $updates ): void {
		( new Babilim_State() )->set( array( 'updates' => $updates ) );
	}

	private function json_response( int $code, array $body = array(), array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}

	private function registered_filters( string $tag ): array {
		return $GLOBALS['babilim_test_filters'][ $tag ] ?? array();
	}

	/**
	 * Stands in for Language_Pack_Upgrader::check_package(), which core
	 * itself re-adds to `upgrader_source_selection` at priority 10,
	 * right after removing everything else on that hook -- always passes
	 * $source through untouched (this plugin's own packages always carry
	 * both `.po` and `.mo`, which is all check_package() itself checks
	 * for).
	 */
	public function noop_check_package( $source, $remote_source ) {
		return $source;
	}

	// -- register() ----------------------------------------------------

	public function test_register_hooks_every_filter_it_needs(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$api      = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater  = $this->new_updater( $api, $fs, $manifest );

		$updater->register();

		foreach ( array( 'site_transient_update_plugins', 'site_transient_update_themes', 'upgrader_package_options', 'upgrader_pre_download', 'upgrader_install_package_result' ) as $tag ) {
			$this->assertNotEmpty( $this->registered_filters( $tag ), "Expected a filter registered for {$tag}" );
		}

		// register() itself must never touch either hook
		// Language_Pack_Upgrader::bulk_upgrade() clears before its
		// per-item loop starts (see the class docblock) -- only
		// pre_download(), called per item, may ever add to
		// upgrader_source_selection.
		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ) );
		$this->assertSame( array(), $this->registered_filters( 'upgrader_post_install' ) );
	}

	// -- inject() --------------------------------------------------------

	public function test_inject_appends_our_updates_and_keeps_existing_translations(): void {
		$this->set_updates(
			array(
				array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_5e2a9c1f3d70', 'build_no' => 3 ),
			)
		);
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$transient               = new stdClass();
		$transient->translations = array( (object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE', 'package' => 'https://downloads.wordpress.org/translation/plugin/akismet-de_DE.zip' ) );

		$result = $updater->inject( $transient, 'plugin' );

		$this->assertCount( 2, $result->translations );
		$this->assertSame( 'akismet', $result->translations[0]->slug );

		$ours = $result->translations[1];
		$this->assertSame( 'woocommerce', $ours->slug );
		$this->assertSame( 'zh_TW', $ours->language );
		$this->assertSame( 'bd_5e2a9c1f3d70', $ours->build_id );
		$this->assertSame( 3, $ours->build_no );
		$this->assertTrue( $ours->babilim );
		$this->assertStringStartsWith( 'https://api.babilim.org/babilim-package/', $ours->package );
	}

	public function test_inject_handles_a_transient_that_was_never_populated(): void {
		$this->set_updates( array( array( 'kind' => 'theme', 'slug' => 'twentytwenty', 'locale' => 'ja', 'build_id' => 'bd_a0000d000001', 'build_no' => 1 ) ) );
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$result = $updater->inject_theme_transient( false );

		$this->assertCount( 1, $result->translations );
		$this->assertSame( 'twentytwenty', $result->translations[0]->slug );
	}

	/**
	 * This plugin never compares versions/build numbers itself -- an
	 * update whose build_no is a rollback to an older build is injected
	 * exactly like any other.
	 */
	public function test_inject_injects_a_rollback_to_a_smaller_build_no(): void {
		$this->set_updates( array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000001', 'build_no' => 2 ) ) );
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$result = $updater->inject_plugin_transient( new stdClass() );

		$this->assertCount( 1, $result->translations );
		$this->assertSame( 2, $result->translations[0]->build_no );
	}

	public function test_inject_only_injects_the_matching_kind(): void {
		$this->set_updates(
			array(
				array( 'kind' => 'theme', 'slug' => 'twentytwenty', 'locale' => 'ja', 'build_id' => 'bd_a0000d000002', 'build_no' => 1 ),
			)
		);
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$result = $updater->inject_plugin_transient( new stdClass() );

		$this->assertSame( array(), $result->translations );
	}

	/**
	 * Core writes the filtered transient back with set_site_transient()
	 * before the next read re-applies this same filter -- a second
	 * inject() call against the already-filtered value must not
	 * duplicate the entries the first call appended.
	 */
	public function test_inject_is_idempotent_across_a_filter_then_write_back_then_filter_again(): void {
		$this->set_updates( array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000003', 'build_no' => 1 ) ) );
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$transient = $updater->inject( new stdClass(), 'plugin' );
		// Simulate core persisting the filtered value, then re-reading it
		// (re-triggering this same filter on what it just wrote).
		$transient = $updater->inject( $transient, 'plugin' );

		$this->assertCount( 1, $transient->translations );
		$this->assertSame( 'woocommerce', $transient->translations[0]->slug );
	}

	// -- strip_injected_translations() ---------------------------------

	/**
	 * strip_injected_translations() must remove every babilim-marked
	 * entry from both update_plugins and update_themes, leaving a real
	 * wp.org entry (no `babilim` marker) untouched, and actually write
	 * the filtered transient back -- proven by the write-count stub
	 * (`$GLOBALS['babilim_test_site_transient_writes']`, incremented by
	 * every real set_site_transient() call), not merely by reading the
	 * result back through get_site_transient(): the test-bootstrap
	 * getters/setters round-trip every value through serialize()/
	 * unserialize() (babilim_test_deep_copy(), tests/bootstrap.php),
	 * matching a real WordPress install's DB round-trip, so a
	 * strip_injected_translations() that mutated the transient object
	 * in place without ever calling set_site_transient() would leave
	 * `$GLOBALS['babilim_test_site_transients']` itself unchanged and
	 * only fool a check that re-reads through get_site_transient().
	 */
	public function test_strip_injected_translations_removes_only_babilim_marked_entries(): void {
		set_site_transient(
			'update_plugins',
			(object) array(
				'translations' => array(
					(object) array( 'type' => 'plugin', 'slug' => 'orphan-plugin', 'language' => 'de_DE', 'babilim' => true ),
					(object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE' ),
				),
			)
		);
		set_site_transient(
			'update_themes',
			(object) array(
				'translations' => array(
					(object) array( 'type' => 'theme', 'slug' => 'orphan-theme', 'language' => 'de_DE', 'babilim' => true ),
				),
			)
		);
		$writes_before = $GLOBALS['babilim_test_site_transient_writes'];

		Babilim_Updater::strip_injected_translations();

		$this->assertSame( ( $writes_before['update_plugins'] ?? 0 ) + 1, $GLOBALS['babilim_test_site_transient_writes']['update_plugins'], 'update_plugins must actually be written back, not just mutated in place' );
		$this->assertSame( ( $writes_before['update_themes'] ?? 0 ) + 1, $GLOBALS['babilim_test_site_transient_writes']['update_themes'], 'update_themes must actually be written back, not just mutated in place' );

		// The real DB round-trip (see this test's own docblock): reading
		// through the raw store, not get_site_transient(), so a filter
		// that mutated the live object without a real write-back could
		// not accidentally satisfy this by returning that same handle.
		$plugins = $GLOBALS['babilim_test_site_transients']['update_plugins'];
		$this->assertCount( 1, $plugins->translations );
		$this->assertSame( 'akismet', $plugins->translations[0]->slug );
		$this->assertFalse( property_exists( $plugins->translations[0], 'babilim' ), 'the surviving entry must be the untouched wp.org one' );

		$themes = $GLOBALS['babilim_test_site_transients']['update_themes'];
		$this->assertSame( array(), $themes->translations );
	}

	/**
	 * A transient that was never populated (still `false`, core's own
	 * default before its first check) must not make this throw or
	 * fabricate one.
	 */
	public function test_strip_injected_translations_handles_a_transient_that_was_never_populated(): void {
		Babilim_Updater::strip_injected_translations();

		$this->assertFalse( get_site_transient( 'update_plugins' ) );
		$this->assertFalse( get_site_transient( 'update_themes' ) );
	}

	/**
	 * When nothing changes (no babilim-marked entry present), this must
	 * not write the transient back at all -- proven by a write-count
	 * assertion against the stub's own call counter
	 * (`$GLOBALS['babilim_test_site_transient_writes']`), not by
	 * comparing object identity: with the test bootstrap's real DB
	 * round-trip semantics (babilim_test_deep_copy(), tests/
	 * bootstrap.php), every actual write already produces a fresh
	 * object, so an identity comparison alone would already catch a
	 * spurious write -- but it says nothing about *how many* writes
	 * happened, and a mutation that made this method always write back
	 * (even when nothing changed) is exactly what this test exists to
	 * catch. The count makes that assertion explicit instead of relying
	 * on an implementation detail of the stub.
	 */
	public function test_strip_injected_translations_does_not_write_back_when_nothing_changed(): void {
		set_site_transient(
			'update_plugins',
			(object) array(
				'translations' => array(
					(object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE' ),
				),
			)
		);
		$writes_before = $GLOBALS['babilim_test_site_transient_writes']['update_plugins'] ?? 0;

		Babilim_Updater::strip_injected_translations();

		$this->assertSame( $writes_before, $GLOBALS['babilim_test_site_transient_writes']['update_plugins'] ?? 0, 'an unchanged transient must not be re-written' );
	}

	// -- package_options() -------------------------------------------------

	/**
	 * For one of this plugin's own items, the destination must never
	 * be the real, shared `WP_LANG_DIR/plugins`/`themes` directory --
	 * Language_Pack_Upgrader::clear_destination() runs against exactly
	 * this value before this class's own source_selection() result is
	 * ever copied (see the class docblock).
	 */
	public function test_package_options_redirects_destination_for_our_own_item(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$options = array(
			'destination' => WP_LANG_DIR . 'plugins',
			'package'     => 'https://api.babilim.org/babilim-package/bd_000000000001',
			'hook_extra'  => array(
				'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'woocommerce', 'language' => 'zh_TW', 'babilim' => true, 'build_id' => 'bd_000000000001' ),
			),
		);

		$result = $updater->package_options( $options );

		$this->assertNotSame( WP_LANG_DIR . 'plugins', $result['destination'] );
		$this->assertStringContainsString( '/babilim/_incoming/', $result['destination'] );
		$this->assertTrue( $fs->exists( $result['destination'] ), 'The incoming directory should be pre-created.' );
	}

	public function test_package_options_leaves_a_real_wporg_item_untouched(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$options = array(
			'destination' => WP_LANG_DIR . 'plugins',
			'package'     => 'https://downloads.wordpress.org/translation/plugin/akismet-de_DE.zip',
			'hook_extra'  => array(
				'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE' ),
			),
		);

		$result = $updater->package_options( $options );

		$this->assertSame( WP_LANG_DIR . 'plugins', $result['destination'] );
	}

	/**
	 * The belt-and-suspenders package-URL-prefix check applies here too
	 * (see is_ours()) -- a `babilim` marker alone isn't enough.
	 */
	public function test_package_options_requires_the_package_url_prefix_too(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$options = array(
			'destination' => WP_LANG_DIR . 'plugins',
			'package'     => 'https://downloads.wordpress.org/translation/plugin/x-de_DE.zip',
			'hook_extra'  => array(
				'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'x', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_000000000001' ),
			),
		);

		$result = $updater->package_options( $options );

		$this->assertSame( WP_LANG_DIR . 'plugins', $result['destination'] );
	}

	// -- install_package_result() -------------------------------------------

	public function test_install_package_result_passes_through_an_unmatched_item(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$result     = array( 'destination' => '/tmp/whatever' );
		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE' ) );

		$this->assertSame( $result, $updater->install_package_result( $result, $hook_extra ) );
	}

	public function test_install_package_result_does_not_remove_state_when_core_itself_reports_a_wp_error(): void {
		$this->set_updates( array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000004', 'build_no' => 1 ) ) );
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$translation = (object) array( 'type' => 'plugin', 'slug' => 'woocommerce', 'language' => 'zh_TW', 'babilim' => true, 'build_id' => 'bd_000000000004' );
		$options     = $updater->package_options(
			array(
				'destination' => WP_LANG_DIR . 'plugins',
				'package'     => 'https://api.babilim.org/babilim-package/bd_000000000004',
				'hook_extra'  => array( 'language_update' => $translation ),
			)
		);
		$incoming_dir = $options['destination'];

		// Simulate adopt() having succeeded (source_selection() would
		// normally flip this), but core's own later step -- e.g.
		// clear_destination()'s files_not_writable -- failing anyway.
		$reflection = new ReflectionProperty( Babilim_Updater::class, 'incoming' );
		$reflection->setAccessible( true );
		$incoming            = $reflection->getValue( $updater );
		$incoming['adopted'] = true;
		$reflection->setValue( $updater, $incoming );

		$error = new WP_Error( 'files_not_writable', 'nope' );
		$final = $updater->install_package_result( $error, array( 'language_update' => $translation ) );

		$this->assertSame( $error, $final );
		$this->assertCount( 1, ( new Babilim_State() )->updates(), 'A core-reported failure must not remove the entry, even though adopt() itself succeeded.' );
		$this->assertFalse( $fs->exists( $incoming_dir ), 'The incoming directory is still cleaned up either way.' );
	}

	/**
	 * The `adopted` guard itself, isolated from the `is_wp_error()`
	 * check the test above already covers. If source_selection() never
	 * actually ran for the matching item (so `adopted` is still its
	 * default false) but core nonetheless reports a non-error result --
	 * core's own copy landed a real zip's contents in `_incoming`,
	 * without this plugin ever having verified or served them -- the
	 * entry must NOT be treated as installed and removed from state.
	 */
	public function test_install_package_result_does_not_remove_state_when_adopted_was_never_set(): void {
		$this->set_updates( array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000005', 'build_no' => 1 ) ) );
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$translation = (object) array( 'type' => 'plugin', 'slug' => 'woocommerce', 'language' => 'zh_TW', 'babilim' => true, 'build_id' => 'bd_000000000005' );
		$options     = $updater->package_options(
			array(
				'destination' => WP_LANG_DIR . 'plugins',
				'package'     => 'https://api.babilim.org/babilim-package/bd_000000000005',
				'hook_extra'  => array( 'language_update' => $translation ),
			)
		);
		$incoming_dir = $options['destination'];

		// source_selection() deliberately never called here -- `adopted`
		// stays at its default false.
		$success = array( 'destination' => $incoming_dir );
		$final   = $updater->install_package_result( $success, array( 'language_update' => $translation ) );

		$this->assertSame( $success, $final );
		$this->assertCount( 1, ( new Babilim_State() )->updates(), 'A core-reported success must not remove the entry unless this class itself actually adopted it.' );
		$this->assertFalse( $fs->exists( $incoming_dir ), 'The incoming directory is still cleaned up either way.' );
	}

	/**
	 * `_incoming/{token}` isn't guaranteed empty (see the class
	 * docblock -- if source_selection() never fires, core's own copy
	 * lands there for real). install_package_result()'s cleanup must
	 * remove it -- and everything in it -- not merely attempt a plain
	 * (non-recursive) rmdir() that would silently leave it behind.
	 */
	public function test_install_package_result_removes_a_non_empty_incoming_directory(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$translation = (object) array( 'type' => 'plugin', 'slug' => 'woocommerce', 'language' => 'zh_TW', 'babilim' => true, 'build_id' => 'bd_000000000006' );
		$options     = $updater->package_options(
			array(
				'destination' => WP_LANG_DIR . 'plugins',
				'package'     => 'https://api.babilim.org/babilim-package/bd_000000000006',
				'hook_extra'  => array( 'language_update' => $translation ),
			)
		);
		$incoming_dir = $options['destination'];

		// Simulate core's own copy having landed real zip contents here
		// (the scenario source_selection() never firing leads to) --
		// plant_disallowed(), not put(): none of these is something this
		// plugin's own write path puts under _incoming/ (see
		// INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock), only core's
		// own upgrader, bypassing Babilim_Fs entirely, ever does.
		$fs->plant_disallowed( $incoming_dir . '/woocommerce-zh_TW.mo' );
		$fs->plant_disallowed( $incoming_dir . '/woocommerce-zh_TW.po' );
		$fs->plant_disallowed( $incoming_dir . '/manifest.json' );

		$updater->install_package_result( array( 'destination' => $incoming_dir ), array( 'language_update' => $translation ) );

		$this->assertFalse( $fs->exists( $incoming_dir . '/woocommerce-zh_TW.mo' ) );
		$this->assertFalse( $fs->exists( $incoming_dir . '/woocommerce-zh_TW.po' ) );
		$this->assertFalse( $fs->exists( $incoming_dir . '/manifest.json' ) );
		$this->assertFalse( $fs->exists( $incoming_dir ) );
	}

	/**
	 * The recursive cleanup must refuse anything outside
	 * `_incoming/` -- checked directly against the private method via
	 * reflection, since nothing on this class's own public surface can
	 * ever be made to call it with an out-of-bounds path (package_options()
	 * always builds $incoming['dir'] itself, from Babilim_Paths::
	 * incoming_dir()).
	 */
	public function test_cleanup_incoming_recursive_refuses_a_path_outside_incoming(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$victim_dir = Babilim_Paths::adopt_staging_dir( 'victim-token' );
		$fs->put( $victim_dir . '/woocommerce-zh_TW.mo', 'must not be deleted' );

		$method = new ReflectionMethod( Babilim_Updater::class, 'cleanup_incoming_recursive' );
		$method->setAccessible( true );
		$result = $method->invoke( $updater, $victim_dir );

		$this->assertFalse( $result );
		$this->assertTrue( $fs->exists( $victim_dir . '/woocommerce-zh_TW.mo' ), 'A path outside _incoming/ must never be touched.' );
	}

	// -- sweep_stale_incoming_dirs() ---------------------------------------

	/**
	 * sweep_stale_incoming_dirs() itself: never calling it, never
	 * actually removing anything, and ignoring age entirely are each
	 * distinct ways this could silently regress. Covers, in one pass: an
	 * entry older than a day is removed (empty and non-empty); an entry
	 * not yet a day old is kept regardless
	 * of emptiness; an entry whose own name doesn't parse as
	 * fresh_incoming_token()'s shape is left alone; nothing outside
	 * `_incoming/` is ever touched (only entries this test itself put
	 * under Babilim_Paths::incoming_root() exist at all here).
	 */
	public function test_sweep_stale_incoming_dirs_removes_only_entries_older_than_a_day(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$old_empty_token     = dechex( time() - 90000 ) . '-aaaaaaaaaaaa'; // ~25 hours old.
		$old_nonempty_token  = dechex( time() - 90000 ) . '-dddddddddddd';
		$new_token           = dechex( time() - 60 ) . '-bbbbbbbbbbbb'; // 1 minute old.
		$unparseable_token   = 'zzzz-cccccccccccc'; // Not a valid hex prefix.

		$old_empty_dir    = Babilim_Paths::incoming_dir( $old_empty_token );
		$old_nonempty_dir = Babilim_Paths::incoming_dir( $old_nonempty_token );
		$new_dir          = Babilim_Paths::incoming_dir( $new_token );
		$unparseable_dir  = Babilim_Paths::incoming_dir( $unparseable_token );

		$fs->mkdir( $old_empty_dir );
		// A .mo directly under _incoming/ is never something this
		// plugin's own write path puts there (see
		// INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock) -- only
		// WordPress core's own upgrader, bypassing Babilim_Fs entirely,
		// ever leaves one here. plant_disallowed() places it the same
		// way, skipping the write-time allow-list this fixture setup
		// isn't meant to exercise.
		$fs->plant_disallowed( $old_nonempty_dir . '/woocommerce-zh_TW.mo' );
		$fs->mkdir( $new_dir );
		$fs->mkdir( $unparseable_dir );

		$removed = $updater->sweep_stale_incoming_dirs();

		$this->assertSame( 2, $removed );
		$this->assertFalse( $fs->exists( $old_empty_dir ), 'An empty entry older than a day must be removed.' );
		$this->assertFalse( $fs->exists( $old_nonempty_dir ), 'A non-empty entry older than a day must also be removed.' );
		$this->assertTrue( $fs->exists( $new_dir ), 'An entry not yet a day old must be kept.' );
		$this->assertTrue( $fs->exists( $unparseable_dir ), "An entry whose name doesn't parse as a token must be left alone." );
	}

	/**
	 * `_incoming/{token}/` is only ever actually populated by WordPress
	 * core's own upgrader (a destination redirect during an update, see
	 * this class's own docblock) -- never through this plugin's own
	 * Babilim_Fs write path, so Babilim_Paths::is_allowed()'s write-time
	 * server-executable-extension denylist (see its own $for_write
	 * parameter) can't stop a `.php` from landing there in the first
	 * place. What it must never do is stop a sweep from cleaning one back
	 * OUT: list()/is_dir()/delete()/rmdir() are all read/removal
	 * operations, not writes, so they must be able to see and remove a
	 * `.php` entry the same as any other extension. Babilim_Memory_Fs::
	 * plant_disallowed() places the file the way a stray one really would
	 * turn up (bypassing the write-time guard entirely, exactly as core's
	 * own upgrader does) -- list() sees it, but a Babilim_Fs call naming
	 * it directly would still be refused if it went through put()/get().
	 */
	public function test_sweep_stale_incoming_dirs_removes_a_php_file_left_behind(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$stale_token = dechex( time() - 90000 ) . '-eeeeeeeeeeee'; // ~25 hours old.
		$stale_dir   = Babilim_Paths::incoming_dir( $stale_token );
		$fs->plant_disallowed( $stale_dir . '/evil.php' );

		$removed = $updater->sweep_stale_incoming_dirs();

		$this->assertSame( 1, $removed );
		$this->assertFalse( $fs->exists( $stale_dir . '/evil.php' ), 'The stray .php file must be removed.' );
		$this->assertFalse( $fs->exists( $stale_dir ), 'The now-empty token directory must be removed too, not left behind.' );
	}

	/**
	 * sweep_stale_incoming_dirs()'s own outermost list() call is wrapped
	 * in a try/catch (a Throwable there must never escape into the daily
	 * maintenance cron) -- this proves that catch branch actually works:
	 * a Babilim_Fs whose list() throws must make this return 0, not
	 * propagate the exception.
	 */
	public function test_sweep_stale_incoming_dirs_returns_zero_when_fs_list_throws(): void {
		$fs      = new Babilim_Test_Throwing_List_Fs( new Babilim_Memory_Fs() );
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$removed = $updater->sweep_stale_incoming_dirs();

		$this->assertSame( 0, $removed );
	}

	/**
	 * A package's `default/` subdirectory (see package-layout.md) lands
	 * in `_incoming/{token}` for real whenever core's own copy ran there
	 * -- a nested tree, at least two levels deep, holding files at every
	 * level. The cleanup must recurse through it, not stop at the first
	 * level and leave the token directory behind.
	 */
	public function test_install_package_result_removes_a_nested_incoming_tree(): void {
		$fs      = new Babilim_Memory_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$translation = (object) array( 'type' => 'plugin', 'slug' => 'woocommerce', 'language' => 'zh_TW', 'babilim' => true, 'build_id' => 'bd_000000000016' );
		$options     = $updater->package_options(
			array(
				'destination' => WP_LANG_DIR . 'plugins',
				'package'     => 'https://api.babilim.org/babilim-package/bd_000000000016',
				'hook_extra'  => array( 'language_update' => $translation ),
			)
		);
		$incoming_dir = $options['destination'];

		$files = array(
			$incoming_dir . '/woocommerce-zh_TW.mo',
			$incoming_dir . '/default/woocommerce-zh_TW.mo',
			$incoming_dir . '/default/nested/woocommerce-zh_TW.po',
		);
		// This whole nested tree simulates exactly what this test's own
		// docblock describes: a real core copy left behind under
		// _incoming/ when source_selection() never fires -- never
		// something this plugin's own write path puts there (see
		// INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock), so
		// plant_disallowed() places it the way it would really turn up,
		// skipping the write-time allow-list this fixture setup isn't
		// meant to exercise.
		foreach ( $files as $file ) {
			$fs->plant_disallowed( $file );
		}

		$updater->install_package_result( array( 'destination' => $incoming_dir ), array( 'language_update' => $translation ) );

		foreach ( $files as $file ) {
			$this->assertFalse( $fs->exists( $file ), "$file must be removed." );
		}
		$this->assertFalse( $fs->exists( $incoming_dir . '/default/nested' ) );
		$this->assertFalse( $fs->exists( $incoming_dir . '/default' ) );
		$this->assertFalse( $fs->exists( $incoming_dir ) );
	}

	/**
	 * The same sweep, on real disk, through the production Babilim_Wp_Fs
	 * and a $wp_filesystem whose dirlist() follows WordPress core's own
	 * WP_Filesystem_Direct::dirlist() contract (a file path lists that
	 * file itself -- see tests/bootstrap.php). Asserted with PHP's own
	 * is_file()/is_dir(), not through Babilim_Fs, so a cleanup that only
	 * *reports* success can't pass.
	 */
	public function test_sweep_removes_flat_and_nested_non_empty_entries_on_real_disk(): void {
		$fs      = new Babilim_Wp_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$flat_dir   = Babilim_Paths::incoming_dir( dechex( time() - 90000 ) . '-aaaaaaaaaaaa' );
		$nested_dir = Babilim_Paths::incoming_dir( dechex( time() - 90000 ) . '-bbbbbbbbbbbb' );
		$files      = array(
			$flat_dir . '/manifest.json',
			$flat_dir . '/woocommerce-zh_TW.mo',
			$nested_dir . '/woocommerce-zh_TW.mo',
			$nested_dir . '/default/woocommerce-zh_TW.mo',
			$nested_dir . '/default/nested/woocommerce-zh_TW.po',
		);
		// Written by raw PHP, not through $fs->put() -- none of these
		// extensions is something this plugin's own write path ever puts
		// under _incoming/ (see INCOMING_WRITE_ALLOWED_EXTENSIONS's own
		// docblock), only WordPress core's own upgrader, which writes
		// there directly too, bypassing Babilim_Fs entirely. Matches the
		// same raw-PHP fixture-setup pattern this file's own symlink test
		// below already uses for the same reason.
		foreach ( $files as $file ) {
			if ( ! is_dir( dirname( $file ) ) ) {
				mkdir( dirname( $file ), 0777, true );
			}
			$this->assertNotFalse( file_put_contents( $file, 'zip' ) );
			$this->assertTrue( is_file( $file ) );
		}

		$this->assertSame( 2, $updater->sweep_stale_incoming_dirs() );

		foreach ( $files as $file ) {
			$this->assertFalse( file_exists( $file ), "$file must really be gone from disk." );
		}
		$this->assertFalse( file_exists( $flat_dir ) );
		$this->assertFalse( file_exists( $nested_dir ) );
		$this->assertTrue( is_dir( Babilim_Paths::incoming_root() ), '_incoming itself is never removed.' );
	}

	/**
	 * A `.php` file left under a stale _incoming/{token}/ by WordPress
	 * core's own upgrader must be removable: the write-time extension
	 * allow-list must never apply to deletion. Runs through the
	 * production Babilim_Wp_Fs on real disk (the in-memory double has its
	 * own separate copy of this rule), asserted with PHP's own
	 * file_exists().
	 */
	public function test_sweep_removes_a_php_file_left_behind_on_real_disk(): void {
		$fs      = new Babilim_Wp_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$dir  = Babilim_Paths::incoming_dir( dechex( time() - 90000 ) . '-cccccccccccc' );
		$file = $dir . '/leftover.php';
		mkdir( $dir, 0777, true );
		$this->assertNotFalse( file_put_contents( $file, '<?php // left by core' ) );

		$this->assertSame( 1, $updater->sweep_stale_incoming_dirs() );

		$this->assertFalse( file_exists( $file ), 'The .php file must really be gone from disk.' );
		$this->assertFalse( file_exists( $dir ), 'The token directory must be gone too.' );
	}

	/**
	 * One stale entry whose own cleanup is refused by Babilim_Fs's path
	 * guard (a symlink pointing outside the tree) must be skipped, not
	 * abort the whole sweep: every other stale entry is still removed,
	 * and nothing the symlink points at is touched.
	 */
	public function test_sweep_skips_a_symlinked_entry_and_still_removes_the_others(): void {
		$fs      = new Babilim_Wp_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$victim = BABILIM_TEST_TMP_ROOT . '/babilim-sweep-victim-' . bin2hex( random_bytes( 4 ) );
		mkdir( $victim );
		file_put_contents( $victim . '/keep.mo', 'victim' );

		try {
			// Sorted first, so an abort on it would skip everything after.
			$link_dir = Babilim_Paths::incoming_dir( dechex( time() - 90000 ) . '-000000000000' );
			$old_dir  = Babilim_Paths::incoming_dir( dechex( time() - 90000 ) . '-ffffffffffff' );
			// Raw PHP, not $fs->put() -- see this file's own
			// test_sweep_removes_flat_and_nested_non_empty_entries_on_real_disk()
			// for why a .mo directly under _incoming/ is written this way.
			mkdir( $old_dir, 0777, true );
			file_put_contents( $old_dir . '/woocommerce-zh_TW.mo', 'zip' );
			symlink( $victim, $link_dir );

			$this->assertSame( 1, $updater->sweep_stale_incoming_dirs() );

			$this->assertFalse( file_exists( $old_dir ), 'The ordinary stale entry after the symlink must still be removed.' );
			$this->assertTrue( is_file( $victim . '/keep.mo' ), 'Nothing a symlinked entry points at may be touched.' );
			$this->assertTrue( is_link( $link_dir ), 'The symlinked entry itself is skipped, not followed or removed.' );
		} finally {
			$this->rimraf( Babilim_Paths::incoming_root() );
			$this->rimraf( $victim );
		}
	}

	/**
	 * Same guarantee for a name the path guard rejects outright (a `..`
	 * traversal): that one entry is skipped, the rest of the sweep runs.
	 */
	public function test_sweep_skips_a_traversal_name_and_still_removes_the_others(): void {
		$inner   = new Babilim_Memory_Fs();
		$stale   = dechex( time() - 90000 );
		$fs      = new Babilim_Test_Updater_Extra_Entry_Fs( $inner, Babilim_Paths::incoming_root(), $stale . '-a/../../plugins' );
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$victim  = Babilim_Paths::dir( 'plugins' ) . '/woocommerce-zh_TW.mo';
		$old_dir = Babilim_Paths::incoming_dir( $stale . '-ffffffffffff' );
		$inner->put( $victim, 'served' );
		// plant_disallowed(), not put() -- a .mo directly under
		// _incoming/ is never something this plugin's own write path
		// puts there (see INCOMING_WRITE_ALLOWED_EXTENSIONS's own
		// docblock).
		$inner->plant_disallowed( $old_dir . '/woocommerce-zh_TW.mo' );

		$this->assertSame( 1, $updater->sweep_stale_incoming_dirs() );

		$this->assertFalse( $inner->exists( $old_dir ), 'The ordinary stale entry must still be removed.' );
		$this->assertSame( 'served', $inner->get( $victim ) );
	}

	/**
	 * cleanup_incoming_recursive() pointed at `_incoming/` itself (with
	 * its trailing slash -- the one value that passes the `_incoming/`
	 * prefix check while naming no token at all) must refuse outright,
	 * never recurse into every token, fresh ones included. Real disk, so
	 * a refusal that's missing actually deletes something.
	 */
	public function test_cleanup_incoming_recursive_refuses_the_bare_incoming_root(): void {
		$fs      = new Babilim_Wp_Fs();
		$api     = new Babilim_Api_Client( new Babilim_Fake_Http( array() ), 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$fresh_file = Babilim_Paths::incoming_dir( dechex( time() ) . '-aaaaaaaaaaaa' ) . '/woocommerce-zh_TW.mo';
		// Raw PHP, not $fs->put() -- see
		// test_sweep_removes_flat_and_nested_non_empty_entries_on_real_disk()
		// for why a .mo directly under _incoming/ is written this way.
		mkdir( dirname( $fresh_file ), 0777, true );
		file_put_contents( $fresh_file, 'in progress' );

		$method = new ReflectionMethod( Babilim_Updater::class, 'cleanup_incoming_recursive' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $updater, Babilim_Paths::incoming_root() . '/' ) );
		$this->assertTrue( is_file( $fresh_file ), 'A fresh token under _incoming must survive.' );
	}

	// -- pre_download() ----------------------------------------------------

	public function test_pre_download_passes_through_a_non_babilim_package_untouched(): void {
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array() );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE' ) );
		$result     = $updater->pre_download( false, 'https://downloads.wordpress.org/translation/plugin/akismet-de_DE.zip', null, $hook_extra );

		$this->assertFalse( $result );
		$this->assertSame( array(), $http->requests );
		$this->assertSame( array(), $GLOBALS['babilim_test_download_url_calls'] );
		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ) );
	}

	/**
	 * A translation object with the `babilim` marker but whose `package`
	 * URL doesn't carry this plugin's own marker prefix must still be
	 * treated as not-ours -- the belt-and-suspenders half of the
	 * identification guard (see the class docblock).
	 */
	public function test_pre_download_requires_the_package_url_prefix_too(): void {
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array() );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'x', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_000000000001' ) );
		$result     = $updater->pre_download( false, 'https://downloads.wordpress.org/translation/plugin/x-de_DE.zip', null, $hook_extra );

		$this->assertFalse( $result );
		$this->assertSame( array(), $http->requests );
		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ) );
	}

	public function test_pre_download_refuses_without_consent(): void {
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array() );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'x', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_000000000001' ) );
		$result     = $updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_000000000001', null, $hook_extra );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_no_consent', $result->get_error_code() );
		$this->assertSame( array(), $http->requests );
		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ) );
	}

	public function test_pre_download_fetches_a_fresh_signed_url_via_core_download_url(): void {
		update_option( 'babilim_consent', true );
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_000000000001?sig=abc' ) ) ) );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$GLOBALS['babilim_test_download_url_return'] = '/tmp/fake-downloaded.zip';

		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'x', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_000000000001' ) );
		$result     = $updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_000000000001', null, $hook_extra );

		$this->assertSame( '/tmp/fake-downloaded.zip', $result );
		$this->assertCount( 1, $http->requests );
		$this->assertSame( 'https://api.babilim.org/v1/downloads', $http->requests[0]['url'] );
		$this->assertCount( 1, $GLOBALS['babilim_test_download_url_calls'] );
		$this->assertSame( 'https://api.babilim.org/v1/packages/bd_000000000001?sig=abc', $GLOBALS['babilim_test_download_url_calls'][0]['url'] );

		// Only now, with a real download in hand, does this class start
		// watching for the matching upgrader_source_selection call --
		// exactly which priority it registers at is behavior the
		// bulk_upgrade-shaped integration test below exercises for real
		// (against a real check_package() stand-in), not asserted here as
		// a literal value.
		$this->assertCount( 1, $this->registered_filters( 'upgrader_source_selection' ) );
	}

	/**
	 * A `POST /v1/downloads` response whose own Location points at a
	 * DIFFERENT origin than the API base must never reach core's own
	 * download_url() -- resolve_location() (Babilim_Api_Client's shared
	 * implementation, see its own docblock) resolves it to '' instead of
	 * a fetchable URL, and pre_download() must report that as the same
	 * "missing a location" error an empty Location already gets, never
	 * calling download_url() at all.
	 */
	public function test_pre_download_refuses_a_location_pointing_at_a_different_origin(): void {
		update_option( 'babilim_consent', true );
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => 'https://evil.example/v1/packages/bd_000000000001?sig=abc' ) ) ) );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'x', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_000000000001' ) );
		$result     = $updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_000000000001', null, $hook_extra );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'babilim_invalid_response', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['babilim_test_download_url_calls'], 'download_url() must never be called for an off-origin Location' );
	}

	/**
	 * An unexpected exception (not one of the ordinary WP_Error-returning
	 * failure paths above) must never leak its own raw message into the
	 * WP_Error this hands back to core's own update screen -- only a
	 * translated, generic one. Babilim_Fake_Http's own throw mode
	 * (tests/fixtures/class-babilim-fake-http.php) stands in for a
	 * genuinely unexpected failure inside the try block.
	 */
	public function test_pre_download_hides_an_unexpected_exceptions_raw_message_behind_a_translated_one(): void {
		update_option( 'babilim_consent', true );
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array(), new RuntimeException( 'a raw, never-user-facing exception detail' ) );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'x', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_000000000001' ) );
		$result     = $updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_000000000001', null, $hook_extra );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_pre_download_failed', $result->get_error_code() );
		$this->assertSame( 'Could not download the translation package.', $result->get_error_message() );
		$this->assertStringNotContainsString( 'never-user-facing', $result->get_error_message() );
	}

	public function test_pre_download_propagates_a_download_exchange_error(): void {
		update_option( 'babilim_consent', true );
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array( $this->json_response( 403, array( 'error' => array( 'code' => 'quota_exceeded', 'message' => 'no slot', 'hint' => '' ) ) ) ) );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'x', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_000000000001' ) );
		$result     = $updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_000000000001', null, $hook_extra );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_quota_exceeded', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['babilim_test_download_url_calls'] );
		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ) );
	}

	/**
	 * A failed download_url() (a real network hiccup fetching the signed
	 * URL, distinct from the /v1/downloads exchange itself failing) must
	 * not leave a stale upgrader_source_selection watch behind either.
	 */
	public function test_pre_download_propagates_a_download_url_failure_without_registering_a_watch(): void {
		update_option( 'babilim_consent', true );
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_000000000001?sig=abc' ) ) ) );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$GLOBALS['babilim_test_download_url_return'] = new WP_Error( 'http_request_failed', 'timed out' );

		$hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'x', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_000000000001' ) );
		$result     = $updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_000000000001', null, $hook_extra );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ) );
	}

	/**
	 * If an earlier item's own install_package() was never reached at
	 * all (its download or unzip failed before upgrader_source_selection
	 * was ever applied), its watch is never consumed. The next item's
	 * pre_download() must still start from a clean slate rather than
	 * leave two watches (or the stale one) registered.
	 */
	public function test_pre_download_clears_a_stale_watch_left_by_an_earlier_unconsumed_item(): void {
		update_option( 'babilim_consent', true );
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http(
			array(
				$this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_aaaaaaaaaaaa?sig=a' ) ),
				$this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_bbbbbbbbbbbb?sig=b' ) ),
			)
		);
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$GLOBALS['babilim_test_download_url_return'] = '/tmp/fake-downloaded.zip';

		$first  = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'a', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_aaaaaaaaaaaa' ) );
		$second = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'b', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_bbbbbbbbbbbb' ) );

		$updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_aaaaaaaaaaaa', null, $first );
		$this->assertCount( 1, $this->registered_filters( 'upgrader_source_selection' ) );

		// Simulate item 1's own install_package() never being reached --
		// its watch is still registered when item 2's pre_download() runs.
		$updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_bbbbbbbbbbbb', null, $second );

		$watching = $this->registered_filters( 'upgrader_source_selection' );
		$this->assertCount( 1, $watching, 'Exactly one watch should remain, not two.' );

		// And that one watch must be for item 2, not the stale item 1.
		$result = call_user_func( $watching[0]['callback'], '/tmp/whatever', '/tmp/whatever', null, $first );
		$this->assertSame( '/tmp/whatever', $result, "The stale item 1 watch must not still match item 1's own hook_extra." );
	}

	/**
	 * The stale-watch cleanup must not be conditional on the next
	 * item also being one of this plugin's own -- a real wp.org item
	 * right after an unconsumed one of ours must clear it too, not leave
	 * it registered until this plugin's own next item eventually comes
	 * along (which, in a batch with no more of this plugin's own items
	 * at all, might be never).
	 */
	public function test_pre_download_clears_a_stale_watch_even_when_the_next_item_is_real_wporg(): void {
		update_option( 'babilim_consent', true );
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_cccccccccccc?sig=a' ) ) ) );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$GLOBALS['babilim_test_download_url_return'] = '/tmp/fake-downloaded.zip';

		$ours = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'c', 'language' => 'de_DE', 'babilim' => true, 'build_id' => 'bd_cccccccccccc' ) );

		$updater->pre_download( false, 'https://api.babilim.org/babilim-package/bd_cccccccccccc', null, $ours );
		$this->assertCount( 1, $this->registered_filters( 'upgrader_source_selection' ) );

		// A real wp.org item, not one of this plugin's own, right after
		// -- the "ours" item's own install_package() was never reached.
		$wporg = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE' ) );
		$result = $updater->pre_download( false, 'https://downloads.wordpress.org/translation/plugin/akismet-de_DE.zip', null, $wporg );

		$this->assertFalse( $result, 'A real wp.org item must still pass through untouched.' );
		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ), 'The stale watch must be cleared even though this item is not one of this plugin\'s own.' );
	}

	// -- source_selection() ----------------------------------------------

	private function pending_translation( string $build_id, string $slug, string $locale = 'zh_TW', string $type = 'plugin' ): object {
		return (object) array( 'type' => $type, 'slug' => $slug, 'language' => $locale, 'babilim' => true, 'build_id' => $build_id );
	}

	/**
	 * Drives package_options() then pre_download() far enough to arm both
	 * the destination redirect and the upgrader_source_selection watch
	 * for $translation -- the same order WP_Upgrader::run() itself
	 * applies them in (its own upgrader_package_options application comes
	 * before download_package()) -- without needing a real HTTP round
	 * trip for every source_selection() test.
	 * Returns the `_incoming/{token}` directory package_options() picked,
	 * so a test can drive install_package_result() with it afterward.
	 */
	private function arm_watch( Babilim_Updater $updater, Babilim_Api_Client $api, object $translation ): string {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_download_url_return'] = '/tmp/fake-downloaded.zip';
		$hook_extra                                   = array( 'language_update' => $translation );
		$package                                       = 'https://api.babilim.org/babilim-package/' . $translation->build_id;

		$options = $updater->package_options( array( 'destination' => '/should/be/redirected', 'hook_extra' => $hook_extra, 'package' => $package ) );
		$updater->pre_download( false, $package, null, $hook_extra );

		return (string) $options['destination'];
	}

	public function test_source_selection_passes_through_an_unmatched_item_untouched(): void {
		$fs       = new Babilim_Memory_Fs();
		$http     = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_x?sig=a' ) ) ) );
		$api      = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater  = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );
		$this->arm_watch( $updater, $api, $this->pending_translation( 'bd_x', 'woocommerce' ) );

		$other_hook_extra = array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'de_DE' ) );
		$result           = $updater->source_selection( '/tmp/other-source', '/tmp/other-remote', null, $other_hook_extra );

		$this->assertSame( '/tmp/other-source', $result );
		// The real watch is untouched -- still armed for its own item.
		$this->assertCount( 1, $this->registered_filters( 'upgrader_source_selection' ) );
	}

	public function test_source_selection_passes_through_a_wp_error_source(): void {
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_x?sig=a' ) ) ) );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );
		$translation = $this->pending_translation( 'bd_x', 'woocommerce' );
		$this->arm_watch( $updater, $api, $translation );

		$error      = new WP_Error( 'incompatible_archive_pomo', 'missing files' );
		$hook_extra = array( 'language_update' => $translation );
		$result     = $updater->source_selection( $error, '/tmp/remote', null, $hook_extra );

		$this->assertSame( $error, $result );
		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ), 'The watch must still be removed even when $source is already an error.' );
	}

	public function test_source_selection_adopts_the_package_and_returns_an_empty_directory_with_zero_new_lang_dir_files(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$http     = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_5e2a9c1f3d70?sig=a' ) ) ) );
		$api      = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater  = $this->new_updater( $api, $fs, $manifest );

		$this->set_updates( array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_5e2a9c1f3d70', 'build_no' => 1 ) ) );

		list( $manifest_json, $files ) = $this->package_fixture( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', true );
		$working_dir                   = $this->write_working_dir( $manifest_json, $files );
		$remote_source                 = dirname( $working_dir );

		$translation = $this->pending_translation( 'bd_5e2a9c1f3d70', 'woocommerce' );
		$incoming_dir = $this->arm_watch( $updater, $api, $translation );
		$this->assertTrue( $fs->exists( $incoming_dir ), 'package_options() should have pre-created the incoming directory.' );

		$hook_extra = array( 'language_update' => $translation );
		$result     = $updater->source_selection( $working_dir, $remote_source, null, $hook_extra );

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertIsString( $result );
		$this->assertNotSame( $working_dir, $result );
		$this->assertTrue( is_dir( $result ), "Expected {$result} to be a real, empty directory." );
		$this->assertSame( array(), array_values( array_diff( scandir( $result ), array( '.', '..' ) ) ), 'Core must copy zero files from the returned directory.' );

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' ) ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'default/_parts', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertNotNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );

		// adopt() succeeding alone must not yet remove the entry from
		// state -- that waits for install_package_result() to confirm
		// core's own later steps didn't themselves fail.
		$this->assertCount( 1, ( new Babilim_State() )->updates() );

		$final = $updater->install_package_result( array( 'destination' => $result ), $hook_extra );

		$this->assertSame( array( 'destination' => $result ), $final, 'A non-our, non-error result must pass through unchanged.' );
		// Only now, once core's own result confirms no later failure,
		// is the entry removed from state so it isn't re-offered.
		$this->assertSame( array(), ( new Babilim_State() )->updates() );
		// The incoming directory is removed once the item is fully done.
		$this->assertFalse( $fs->exists( $incoming_dir ) );

		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ) );

		$this->rimraf( $remote_source );
		$this->rimraf( $result );
	}

	public function test_source_selection_adopts_a_theme_package(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$http     = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_222222222222?sig=a' ) ) ) );
		$api      = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater  = $this->new_updater( $api, $fs, $manifest );

		$this->set_updates( array( array( 'kind' => 'theme', 'slug' => 'twentytwenty', 'locale' => 'ja', 'build_id' => 'bd_222222222222', 'build_no' => 1 ) ) );

		list( $manifest_json, $files ) = $this->package_fixture( 'twentytwenty', 'ja', 'bd_222222222222', false );
		$decoded                        = json_decode( $manifest_json, true );
		$decoded['kind']                = 'theme';
		$manifest_json                  = (string) wp_json_encode( $decoded );
		$working_dir                    = $this->write_working_dir( $manifest_json, $files );
		$remote_source                  = dirname( $working_dir );

		$translation  = $this->pending_translation( 'bd_222222222222', 'twentytwenty', 'ja', 'theme' );
		$incoming_dir = $this->arm_watch( $updater, $api, $translation );

		$hook_extra = array( 'language_update' => $translation );
		$result     = $updater->source_selection( $working_dir, $remote_source, null, $hook_extra );

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'themes', 'twentytwenty', 'ja', 'mo' ) ) );
		$this->assertNotNull( $manifest->installed( 'theme', 'twentytwenty', 'ja' ) );

		$updater->install_package_result( array( 'destination' => $result ), $hook_extra );
		$this->assertSame( array(), ( new Babilim_State() )->updates() );
		$this->assertFalse( $fs->exists( $incoming_dir ) );

		$this->rimraf( $remote_source );
		$this->rimraf( $result );
	}

	public function test_source_selection_reports_adopt_failure_and_cleans_staging(): void {
		$fs       = new Babilim_Test_Updater_Recording_Fs( new Babilim_Memory_Fs() );
		$manifest = new Babilim_Manifest( $fs );
		$http     = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_5e2a9c1f3d71?sig=a' ) ) ) );
		$api      = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater  = $this->new_updater( $api, $fs, $manifest );

		$this->set_updates( array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_5e2a9c1f3d71', 'build_no' => 1 ) ) );

		list( $manifest_json, $files ) = $this->package_fixture( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d71', false );
		$decoded                                            = json_decode( $manifest_json, true );
		$decoded['files']['woocommerce-zh_TW.mo']['sha256'] = str_repeat( '0', 64 );
		$manifest_json                                      = (string) wp_json_encode( $decoded );
		$working_dir                                        = $this->write_working_dir( $manifest_json, $files );
		$remote_source                                      = dirname( $working_dir );

		$translation  = $this->pending_translation( 'bd_5e2a9c1f3d71', 'woocommerce' );
		$incoming_dir = $this->arm_watch( $updater, $api, $translation );

		$hook_extra = array( 'language_update' => $translation );
		$result     = $updater->source_selection( $working_dir, $remote_source, null, $hook_extra );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_adopt_failed', $result->get_error_code() );
		// This is Babilim_Installer::adopt()'s own translated Result
		// message forwarded as-is (a specific, already-i18n'd business
		// error) -- not the generic \Throwable catch-all message; see
		// test_source_selection_hides_an_unexpected_exceptions_raw_
		// message_behind_a_translated_one() below for that path.
		$this->assertSame( 'sha256 mismatch for woocommerce-zh_TW.mo', $result->get_error_message() );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );

		// Staging is cleaned up on failure -- every file this call
		// itself staged is gone, and so is the staging directory itself.
		$this->assertNotEmpty( $fs->put_paths, 'The test setup itself should have staged at least one file.' );
		foreach ( $fs->put_paths as $staged_path ) {
			$this->assertFalse( $fs->exists( $staged_path ), "Expected {$staged_path} to have been cleaned up." );
		}
		$staging_dir = dirname( $fs->put_paths[0] );
		$this->assertSame( array(), $fs->list( $staging_dir ), 'The staging directory should be empty.' );
		$this->assertFalse( $fs->exists( $staging_dir ), 'The now-empty staging directory should have been removed.' );

		// A client event was recorded.
		$events = ( new Babilim_State() )->pending_client_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'woocommerce', $events[0]['slug'] );
		$this->assertSame( 'zh_TW', $events[0]['locale'] );

		// A failed adopt must NOT remove the entry from state, even
		// once install_package_result() runs.
		$updater->install_package_result( $result, $hook_extra );
		$this->assertCount( 1, ( new Babilim_State() )->updates() );
		// The incoming directory is still removed on failure -- it's
		// only ever the entry-removal that's conditional on success.
		$this->assertFalse( $fs->exists( $incoming_dir ) );

		$this->assertSame( array(), $this->registered_filters( 'upgrader_source_selection' ) );

		$this->rimraf( $remote_source );
	}

	/**
	 * An unexpected exception (not one of the ordinary Result::error()-
	 * returning validation failures above) must never leak its own raw
	 * message into the WP_Error source_selection() hands back -- only a
	 * translated, generic one, the same as pre_download()'s own
	 * equivalent catch. Babilim_Test_Updater_Throwing_Fs's put() throws
	 * once the package has already validated successfully and adopt
	 * reaches the staging write, standing in for a genuinely unexpected
	 * failure (a disk error, a permissions problem, ...) rather than a
	 * package the plugin's own validation correctly rejected.
	 */
	public function test_source_selection_hides_an_unexpected_exceptions_raw_message_behind_a_translated_one(): void {
		$fs       = new Babilim_Test_Updater_Throwing_Fs( new Babilim_Memory_Fs(), new RuntimeException( 'a raw, never-user-facing disk-write detail' ) );
		$manifest = new Babilim_Manifest( $fs );
		$http     = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_5e2a9c1f3d73?sig=a' ) ) ) );
		$api      = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater  = $this->new_updater( $api, $fs, $manifest );

		$this->set_updates( array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_5e2a9c1f3d73', 'build_no' => 1 ) ) );

		list( $manifest_json, $files ) = $this->package_fixture( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d73', true );
		$working_dir                   = $this->write_working_dir( $manifest_json, $files );
		$remote_source                 = dirname( $working_dir );

		$translation = $this->pending_translation( 'bd_5e2a9c1f3d73', 'woocommerce' );
		$this->arm_watch( $updater, $api, $translation );

		$hook_extra = array( 'language_update' => $translation );
		$result     = $updater->source_selection( $working_dir, $remote_source, null, $hook_extra );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_adopt_failed', $result->get_error_code() );
		$this->assertSame( 'Could not install the downloaded translation package.', $result->get_error_message() );
		$this->assertStringNotContainsString( 'never-user-facing', $result->get_error_message() );

		$this->rimraf( $remote_source );
	}

	public function test_source_selection_reports_a_missing_manifest_without_crashing(): void {
		$fs      = new Babilim_Memory_Fs();
		$http    = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_000000000009?sig=a' ) ) ) );
		$api     = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater = $this->new_updater( $api, $fs, new Babilim_Manifest( $fs ) );

		$working_dir = get_temp_dir() . 'babilim-update-' . bin2hex( random_bytes( 8 ) ) . '/pkg';
		mkdir( $working_dir, 0777, true );
		$remote_source = dirname( $working_dir );

		$translation = $this->pending_translation( 'bd_000000000009', 'woocommerce' );
		$this->arm_watch( $updater, $api, $translation );

		$hook_extra = array( 'language_update' => $translation );
		$result     = $updater->source_selection( $working_dir, $remote_source, null, $hook_extra );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_adopt_failed', $result->get_error_code() );

		$this->rimraf( $remote_source );
	}

	/**
	 * A Babilim_Fs::put() failure while staging is reported directly
	 * (`staging_write_failed`), not left to surface indirectly as an
	 * entries-count mismatch once Babilim_Installer::adopt() runs.
	 */
	public function test_source_selection_reports_a_staging_write_failure_distinctly(): void {
		$inner   = new Babilim_Memory_Fs();
		$fs      = new Babilim_Test_Installer_Failing_Put_Fs(
			$inner,
			static function ( string $path ) {
				return false !== strpos( $path, 'woocommerce-zh_TW.mo' );
			}
		);
		$manifest = new Babilim_Manifest( $fs );
		$http     = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_5e2a9c1f3d72?sig=a' ) ) ) );
		$api      = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater  = $this->new_updater( $api, $fs, $manifest );

		list( $manifest_json, $files ) = $this->package_fixture( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d72', false );
		$working_dir                   = $this->write_working_dir( $manifest_json, $files );
		$remote_source                 = dirname( $working_dir );

		$translation = $this->pending_translation( 'bd_5e2a9c1f3d72', 'woocommerce' );
		$this->arm_watch( $updater, $api, $translation );

		$hook_extra = array( 'language_update' => $translation );
		$result     = $updater->source_selection( $working_dir, $remote_source, null, $hook_extra );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_adopt_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 'staging_write_failed', $data['reason'] ?? null );

		$this->rimraf( $remote_source );
	}

	// -- bulk_upgrade()-shaped integration test --------------------------

	/**
	 * Reproduces Language_Pack_Upgrader::bulk_upgrade()'s own call
	 * sequence closely enough to prove this class survives it: register()
	 * once, then -- exactly like bulk_upgrade() itself, before its
	 * per-item loop -- remove_all_filters() on upgrader_post_install and
	 * upgrader_source_selection, re-add core's own check_package() stand-
	 * in at priority 10, then drive one item through
	 * upgrader_pre_download -> upgrader_source_selection via
	 * apply_filters() (not direct method calls). Proves the whole
	 * sequence still adopts successfully and copies zero new files into
	 * the returned source.
	 *
	 * Mutation: moving the dynamic `add_filter( 'upgrader_source_selection',
	 * ..., 20, 4 )` out of pre_download() and into register() (i.e.
	 * registering it once, at plugin load, the way every other filter
	 * here is registered) makes this test fail red -- remove_all_filters()
	 * below would strip it before the per-item loop ever starts, so
	 * apply_filters( 'upgrader_source_selection', ... ) would never reach
	 * this class's own callback and $source would come back unchanged.
	 */
	public function test_bulk_upgrade_sequence_survives_remove_all_filters_and_adopts_with_zero_new_files(): void {
		update_option( 'babilim_consent', true );
		$this->set_updates( array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => 'bd_5e2a9c1f3d73', 'build_no' => 1 ) ) );
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$http     = new Babilim_Fake_Http( array( $this->json_response( 302, array(), array( 'location' => '/v1/packages/bd_5e2a9c1f3d73?sig=a' ) ) ) );
		$api      = new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' );
		$updater  = $this->new_updater( $api, $fs, $manifest );

		$updater->register();

		// Language_Pack_Upgrader::bulk_upgrade() itself, before its
		// per-item loop starts.
		remove_all_filters( 'upgrader_pre_install' );
		remove_all_filters( 'upgrader_clear_destination' );
		remove_all_filters( 'upgrader_post_install' );
		remove_all_filters( 'upgrader_source_selection' );
		add_filter( 'upgrader_source_selection', array( $this, 'noop_check_package' ), 10, 2 );

		list( $manifest_json, $files ) = $this->package_fixture( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d73', true );
		$working_dir                   = $this->write_working_dir( $manifest_json, $files );
		$remote_source                 = dirname( $working_dir );

		$translation = $this->pending_translation( 'bd_5e2a9c1f3d73', 'woocommerce' );
		$hook_extra  = array(
			'language_update_type' => 'plugin',
			'language_update'      => $translation,
		);

		// WP_Upgrader::run() itself applies upgrader_package_options
		// before it ever calls download_package() -- reproduced here in
		// the same order. This test only exercises this class's own side
		// of the redirect
		// (Babilim_Fs's incoming_dir() bookkeeping); the real-core test
		// below is what proves Language_Pack_Upgrader::clear_destination()
		// actually leaves a pre-existing wp.org language pack alone
		// because of it.
		$options = apply_filters(
			'upgrader_package_options',
			array(
				'destination' => WP_LANG_DIR . 'plugins',
				'package'     => 'https://api.babilim.org/babilim-package/bd_5e2a9c1f3d73',
				'hook_extra'  => $hook_extra,
			)
		);
		$this->assertNotSame( WP_LANG_DIR . 'plugins', $options['destination'], 'This own item must have its destination redirected.' );
		$incoming_dir = $options['destination'];
		$this->assertTrue( $fs->exists( $incoming_dir ) );

		$GLOBALS['babilim_test_download_url_return'] = $working_dir;

		$downloaded = apply_filters( 'upgrader_pre_download', false, 'https://api.babilim.org/babilim-package/bd_5e2a9c1f3d73', null, $hook_extra );
		$this->assertFalse( is_wp_error( $downloaded ) );
		$this->assertNotFalse( $downloaded );

		$source = apply_filters( 'upgrader_source_selection', $working_dir, $remote_source, null, $hook_extra );

		$this->assertFalse( is_wp_error( $source ), is_wp_error( $source ) ? $source->get_error_message() : '' );
		$this->assertTrue( is_dir( $source ) );
		$this->assertSame( array(), array_values( array_diff( scandir( $source ), array( '.', '..' ) ) ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertNotNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );

		// Only this class's own priority-20 watch removes itself; the
		// priority-10 check_package() stand-in is core's own to remove
		// (bulk_upgrade() does that itself, after its whole loop, which
		// this test doesn't need to simulate to prove the point here).
		$still_watching = array_filter(
			$this->registered_filters( 'upgrader_source_selection' ),
			static function ( $hook ) {
				return 20 === (int) $hook['priority'];
			}
		);
		$this->assertSame( array(), $still_watching );

		$result = apply_filters( 'upgrader_install_package_result', array( 'destination' => $source ), $hook_extra );
		$this->assertSame( array( 'destination' => $source ), $result );
		$this->assertSame( array(), ( new Babilim_State() )->updates() );
		$this->assertFalse( $fs->exists( $incoming_dir ) );

		$this->rimraf( $remote_source );
		$this->rimraf( $source );
	}
}

/**
 * A Babilim_Fs that records every path put() is asked to write --
 * used to find the randomly-tokened `_adopt/{token}` staging directory
 * Babilim_Updater::adopt_from_working_dir() itself picks, which a test
 * has no other way to predict in advance.
 */
final class Babilim_Test_Updater_Recording_Fs implements Babilim_Fs {

	private $inner;

	/**
	 * Every path put() was called with, in call order.
	 *
	 * @var string[]
	 */
	public $put_paths = array();

	public function __construct( Babilim_Fs $inner ) {
		$this->inner = $inner;
	}

	public function put( string $path, string $contents ): bool {
		$this->put_paths[] = $path;
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

/**
 * A Babilim_Fs whose put() throws instead of writing -- stands in for a
 * genuinely unexpected failure (as opposed to the ordinary Result::
 * error()-returning validation failures every other adopt test covers)
 * inside source_selection()'s try block, the same way Babilim_Fake_Http's
 * own throw mode stands in for one inside pre_download()'s.
 */
final class Babilim_Test_Updater_Throwing_Fs implements Babilim_Fs {

	private $inner;
	private $throws;

	public function __construct( Babilim_Fs $inner, \Throwable $throws ) {
		$this->inner  = $inner;
		$this->throws = $throws;
	}

	public function put( string $path, string $contents ): bool {
		throw $this->throws;
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

/**
 * A Babilim_Fs whose list() of one directory also reports one extra,
 * caller-chosen name that doesn't exist -- used to feed the sweep a name
 * the path guard rejects (e.g. a `..` traversal), which no real
 * directory listing could itself produce here.
 */
final class Babilim_Test_Updater_Extra_Entry_Fs implements Babilim_Fs {

	private $inner;
	private $dir;
	private $extra;

	public function __construct( Babilim_Fs $inner, string $dir, string $extra ) {
		$this->inner = $inner;
		$this->dir   = $dir;
		$this->extra = $extra;
	}

	public function put( string $path, string $contents ): bool {
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
		$names = $this->inner->list( $dir, $glob );
		if ( $dir === $this->dir ) {
			$names[] = $this->extra;
			sort( $names );
		}
		return $names;
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

/**
 * A Babilim_Fs whose list() always throws -- used to prove
 * sweep_stale_incoming_dirs()'s own outermost try/catch around its
 * list() call actually stops a Throwable there from escaping.
 */
final class Babilim_Test_Throwing_List_Fs implements Babilim_Fs {

	private $inner;

	public function __construct( Babilim_Fs $inner ) {
		$this->inner = $inner;
	}

	public function put( string $path, string $contents ): bool {
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
		throw new RuntimeException( 'babilim_test_throwing_list_fs: simulated list() failure' );
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
