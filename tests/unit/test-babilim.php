<?php
/**
 * Tests for the Babilim assembly class -- specifically the hook/cron
 * wiring for Babilim_Default_Index's rebuild triggers and the daily
 * `babilim_maintenance` cron.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Babilim extends TestCase {

	/**
	 * Whether Babilim_Paths::root() already existed on real disk before
	 * this test ran (see tearDown()).
	 *
	 * @var bool
	 */
	private $root_existed = false;

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$this->root_existed = is_dir( Babilim_Paths::root() );
	}

	/**
	 * Tests here that go through the real assembly layer write through
	 * the production Babilim_Wp_Fs to real disk under WP_LANG_DIR, which
	 * is shared by every test in the process -- never leave an
	 * `_incoming` (or a root this test itself created) behind for an
	 * unrelated test to trip over under a different test order.
	 */
	protected function tearDown(): void {
		$this->rimraf( Babilim_Paths::incoming_root() );
		if ( ! $this->root_existed ) {
			$this->rimraf( Babilim_Paths::root() );
		}
		$GLOBALS['wp_filesystem'] = null;
		Babilim::reset_for_tests();
	}

	private function rimraf( string $dir ): void {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			unlink( $dir ); // Never follow a symlink out of the tree.
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$this->rimraf( $dir . '/' . $name );
			}
		}
		rmdir( $dir );
	}

	private function registered_actions( string $tag ): array {
		return $GLOBALS['babilim_test_actions'][ $tag ] ?? array();
	}

	private function registered_filters( string $tag ): array {
		return $GLOBALS['babilim_test_filters'][ $tag ] ?? array();
	}

	public function test_init_registers_every_default_index_rebuild_trigger(): void {
		Babilim::instance()->init();

		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'update_option_active_plugins', 'update_site_option_active_sitewide_plugins', 'switch_theme', 'upgrader_process_complete', 'delete_plugin', 'deleted_theme' ) as $tag ) {
			$this->assertNotEmpty( $this->registered_actions( $tag ), "Expected a callback registered for {$tag}" );
		}
	}

	/**
	 * A plugin/theme/translation update firing `upgrader_process_complete`
	 * once must rebuild the merged `default` domain index exactly once --
	 * not twice, which is what happened when a second component also
	 * registered its own listener on this same action alongside this
	 * class's own on_upgrader_process_complete(). Asserted
	 * structurally: do_action() invokes every registered listener exactly
	 * once per dispatch, and on_upgrader_process_complete() itself calls
	 * rebuild_default_index_all_locales() at most once per call -- so
	 * exactly one listener registered on this tag is exactly one rebuild
	 * per real event.
	 */
	public function test_upgrader_process_complete_has_exactly_one_default_index_rebuild_listener(): void {
		Babilim::instance()->init();
		$this->assertCount(
			1,
			$this->registered_actions( 'upgrader_process_complete' ),
			'A plugin/theme/translation update must rebuild the default-domain index exactly once per event -- a second listener here would rebuild it twice for the same event.'
		);
	}

	/**
	 * The core update-transient and upgrader hooks are gone: language
	 * files are installed by the plugin's own sync cron, never by core's
	 * update machinery. Nothing of this plugin may be attached to any of
	 * those five filters.
	 */
	public function test_init_attaches_nothing_to_core_update_or_upgrader_filters(): void {
		Babilim::instance()->init();

		foreach ( array( 'site_transient_update_plugins', 'site_transient_update_themes', 'upgrader_package_options', 'upgrader_pre_download', 'upgrader_install_package_result', 'upgrader_source_selection' ) as $tag ) {
			$this->assertSame( array(), $this->registered_filters( $tag ), "$tag must have no Babilim listener." );
		}
	}

	/**
	 * Every collaborator that reads or writes `_manifest.json` in one
	 * request must share exactly one Babilim_Manifest: two instances would
	 * each hold their own point-in-time snapshot and could disagree about
	 * what the manifest currently holds. The installer the sync install
	 * uses is built from the same shared instance as the merged default
	 * index and the site collaborator.
	 */
	public function test_the_sync_installer_shares_one_manifest_with_the_other_collaborators(): void {
		$babilim  = Babilim::instance();
		$shared   = $this->call_private( $babilim, 'manifest' );
		$installer = $this->call_private( $babilim, 'build_installer' );
		$index    = $this->call_private( $babilim, 'default_index' );
		$site     = $this->call_private( $babilim, 'site' );

		$this->assertSame( $shared, $this->read_private( $installer, 'manifest' ), 'the installer must use the shared manifest' );
		$this->assertSame( $shared, $this->read_private( $index, 'manifest' ), 'the default index must use the shared manifest' );
		$this->assertSame( $shared, $this->read_private( $site, 'manifest' ), 'the site collaborator must use the shared manifest' );
	}

	/**
	 * Two builds installed in one request, with a default-index rebuild in
	 * between, must both keep their manifest record: the shared instance
	 * is what makes the second save see the first's write.
	 */
	public function test_two_installs_in_one_request_keep_both_manifest_records(): void {
		$babilim   = Babilim::instance();
		$installer = $this->call_private( $babilim, 'build_installer' );
		$fs        = $this->call_private( $babilim, 'fs' );
		Babilim_Paths::ensure_layout( $fs );

		// WP_Translation_File does not exist in this suite, so the
		// production $transform/$parse callables fail closed; swap in
		// readers built on the test MO codec. Everything else about the
		// installer -- above all its shared Babilim_Manifest -- is exactly
		// what production builds.
		$fake_parse     = static function ( string $path, string $format ) use ( $fs ) {
			if ( 'php' === $format ) {
				$data = eval( (string) preg_replace( '/^<\?php\s*/', '', (string) $fs->get( $path ) ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
				return is_array( $data['messages'] ?? null ) ? count( $data['messages'] ) : false;
			}
			$entries = babilim_test_read_mo( (string) $fs->get( $path ) );
			unset( $entries[''] );
			return count( $entries );
		};
		$fake_transform = static function ( string $mo_path ) use ( $fs ) {
			$entries = babilim_test_read_mo( (string) $fs->get( $mo_path ) );
			unset( $entries[''] );
			return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
		};
		foreach ( array( 'parse' => $fake_parse, 'transform' => $fake_transform ) as $name => $callable ) {
			$property = new ReflectionProperty( $installer, $name );
			$property->setAccessible( true );
			$property->setValue( $installer, $callable );
		}

		try {
			foreach ( array( array( 'shared-a', 'pt_PT' ), array( 'shared-b', 'da_DK' ) ) as $pair ) {
				list( $slug, $locale ) = $pair;
				$mo  = Babilim_Mo_Writer::write( array( 'Hello' => 'Hello-' . $locale ), array( 'Language' => $locale ) );
				$zip = $this->write_real_zip(
					array(
						$slug . '-' . $locale . '.mo' => $mo,
						'manifest.json'              => (string) wp_json_encode(
							array(
								'build_id'        => $slug . '-1.0.0-' . $locale . '-20260101T000000-fixture',
								'entries'         => 1,
								'files'           => array( $slug . '-' . $locale . '.mo' => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ) ),
								'locale'          => $locale,
								'status'          => 'published',
								'kind'            => 'plugin',
								'slug'            => $slug,
								'version'         => '1.0.0',
								'text_domain'     => $slug,
								'extra_domains'   => array(),
								'server_build_id' => 'bd_00000000000' . ( 'pt_PT' === $locale ? '1' : '2' ),
								'build_no'        => 1,
								'issued_at'       => '2026-01-01T00:00:00Z',
								'contract'        => '1.3.0',
							)
						),
					)
				);
				$result = $installer->install( 'plugin', $slug, $locale, $zip, array( 'server_build_id' => 'bd_00000000000' . ( 'pt_PT' === $locale ? '1' : '2' ), 'slug' => $slug, 'locale' => $locale ) );
				$this->assertTrue( $result->is_ok(), $result->message() );
				$this->call_private( $babilim, 'default_index' )->rebuild_all();
				unlink( $zip );
			}

			$fresh = new Babilim_Manifest( $fs );
			$this->assertNotNull( $fresh->installed( 'plugin', 'shared-a', 'pt_PT' ), "the first install's record must survive the second install's save" );
			$this->assertNotNull( $fresh->installed( 'plugin', 'shared-b', 'da_DK' ), "the second install's record must be present" );
		} finally {
			$fs->delete( Babilim_Paths::manifest_file() );
		}
	}

	/** @return mixed */
	private function call_private( object $object, string $method ) {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $object );
	}

	/** @return mixed */
	private function read_private( object $object, string $property ) {
		$reflection = new ReflectionProperty( $object, $property );
		$reflection->setAccessible( true );
		return $reflection->getValue( $object );
	}

	public function test_init_registers_the_maintenance_cron_callback_and_schedules_it(): void {
		Babilim::instance()->init();

		$this->assertNotEmpty( $this->registered_actions( 'babilim_maintenance' ) );
		$this->assertNotFalse( wp_next_scheduled( 'babilim_maintenance' ) );
	}

	public function test_init_does_not_reschedule_an_already_scheduled_cron(): void {
		$GLOBALS['babilim_test_cron_scheduled']['babilim_maintenance'] = 12345;

		Babilim::instance()->init();

		$this->assertSame( 12345, wp_next_scheduled( 'babilim_maintenance' ) );
	}

	public function test_active_components_lists_only_active_plugins(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'woocommerce/woocommerce.php' => array( 'Version' => '8.0.0' ),
			'hello.php'                    => array( 'Version' => '1.7.2' ),
		);
		$GLOBALS['babilim_test_is_plugin_active_return'] = array(
			'woocommerce/woocommerce.php' => true,
			'hello.php'                    => false,
		);

		$components = Babilim::instance()->active_components();
		$plugins    = array_values( array_filter( $components, static function ( $c ) {
			return 'plugin' === $c['kind'];
		} ) );

		$this->assertCount( 1, $plugins );
		$this->assertSame( 'woocommerce', $plugins[0]['slug'] );
		$this->assertSame( '8.0.0', $plugins[0]['version'] );
	}

	/**
	 * Regression: WordPress core's own deactivate_plugins() fires
	 * `deactivated_plugin` from INSIDE its own per-plugin loop and only
	 * calls update_option('active_plugins', ...) once, AFTER that whole
	 * loop finishes (confirmed directly against real WordPress core
	 * 6.5/6.8/7.1) -- so is_plugin_active() still reports the plugin
	 * currently being deactivated as active for the entire duration of
	 * on_deactivated_plugin()'s own rebuild. Without the override this
	 * test exercises, active_components() (and so a merged-index rebuild
	 * triggered by deactivation) would keep counting the just-deactivated
	 * plugin as active.
	 */
	public function test_active_components_excludes_a_plugin_forced_inactive_for_an_in_progress_deactivation_rebuild(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'plugin-a/plugin-a.php' => array( 'Version' => '1.0.0' ),
			'plugin-b/plugin-b.php' => array( 'Version' => '2.0.0' ),
		);
		// Both still read active -- real core's own timing, see this
		// test's own docblock above.
		$GLOBALS['babilim_test_is_plugin_active_return'] = array(
			'plugin-a/plugin-a.php' => true,
			'plugin-b/plugin-b.php' => true,
		);

		$property = new ReflectionProperty( Babilim::class, 'forced_inactive_plugins' );
		$property->setAccessible( true );
		$property->setValue( Babilim::instance(), array( 'plugin-b/plugin-b.php' => true ) );

		$components = Babilim::instance()->active_components();
		$slugs      = array_column(
			array_values( array_filter( $components, static function ( $c ) {
				return 'plugin' === $c['kind'];
			} ) ),
			'slug'
		);

		$this->assertContains( 'plugin-a', $slugs );
		$this->assertNotContains( 'plugin-b', $slugs, 'plugin-b must be excluded even though is_plugin_active() still reports it active.' );
	}

	/**
	 * The symmetric activation case (see on_activated_plugin()'s own
	 * docblock: core's own ordering already makes this correct without
	 * an override in production, but the override itself must still work
	 * identically, the same as the deactivation case above).
	 */
	public function test_active_components_includes_a_plugin_forced_active_for_an_in_progress_activation_rebuild(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'plugin-c/plugin-c.php' => array( 'Version' => '1.0.0' ),
		);
		$GLOBALS['babilim_test_is_plugin_active_return'] = array(
			'plugin-c/plugin-c.php' => false, // Not active yet, from is_plugin_active()'s own point of view.
		);

		$property = new ReflectionProperty( Babilim::class, 'forced_active_plugins' );
		$property->setAccessible( true );
		$property->setValue( Babilim::instance(), array( 'plugin-c/plugin-c.php' => true ) );

		$components = Babilim::instance()->active_components();
		$slugs      = array_column(
			array_values( array_filter( $components, static function ( $c ) {
				return 'plugin' === $c['kind'];
			} ) ),
			'slug'
		);

		$this->assertContains( 'plugin-c', $slugs );
	}

	/**
	 * Regression: a real `deactivate_plugins( [P, Q] )` call -- the
	 * ordinary "select several, Bulk actions -> Deactivate" admin
	 * screen, or network-wide deactivation -- fires `deactivated_plugin`
	 * once per plugin, all from inside WordPress core's own single
	 * per-plugin loop, before `active_plugins` is written back even
	 * once. If on_deactivated_plugin() cleared its own override in a
	 * `finally` block the moment each individual call returned, then by
	 * the time Q's own hook fired, P (deactivated moments earlier in the
	 * very same loop) would already have gone back to being read through
	 * plain is_plugin_active(), which still, wrongly, reports it active.
	 * Confirmed directly against a real two-plugin
	 * deactivate_plugins() call: the first plugin's own strings were
	 * still in the merged index after the whole batch finished. Both
	 * plugins must be excluded once both hooks have fired, not just
	 * whichever one fired most recently.
	 */
	public function test_on_deactivated_plugin_accumulates_across_a_batch_deactivation_in_one_request(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'plugin-a/plugin-a.php' => array( 'Version' => '1.0.0' ),
			'plugin-b/plugin-b.php' => array( 'Version' => '2.0.0' ),
		);
		// Both still read active throughout -- real core's own timing,
		// exactly as it is mid-way through its own deactivate_plugins()
		// loop, before active_plugins is written back for either one.
		$GLOBALS['babilim_test_is_plugin_active_return'] = array(
			'plugin-a/plugin-a.php' => true,
			'plugin-b/plugin-b.php' => true,
		);

		// WordPress core's own deactivate_plugins( ['plugin-a/plugin-a.php', 'plugin-b/plugin-b.php'] )
		// fires this hook once per plugin, in order, all before writing
		// active_plugins back even once.
		Babilim::instance()->on_deactivated_plugin( 'plugin-a/plugin-a.php' );
		Babilim::instance()->on_deactivated_plugin( 'plugin-b/plugin-b.php' );

		$components = Babilim::instance()->active_components();
		$slugs      = array_column(
			array_values( array_filter( $components, static function ( $c ) {
				return 'plugin' === $c['kind'];
			} ) ),
			'slug'
		);

		$this->assertNotContains( 'plugin-a', $slugs, "plugin-a's own hook already fired earlier in this same batch -- it must still be excluded, not just plugin-b" );
		$this->assertNotContains( 'plugin-b', $slugs );
	}

	/**
	 * Same batch-deactivation scenario, network-wide: WordPress core's
	 * own deactivate_plugins() fires this identical hook with
	 * $network_wide = true instead of false when deactivating across a
	 * whole multisite network (Network Admin -> Plugins -> Bulk actions
	 * -> Network Deactivate), and only writes
	 * `active_sitewide_plugins` back once, after its own per-plugin loop
	 * finishes -- the exact same timing gap as the single-site case, and
	 * on_deactivated_plugin() never reads $network_wide at all (see its
	 * own docblock), so this must accumulate identically regardless of
	 * which one fired it.
	 */
	public function test_on_deactivated_plugin_accumulates_across_a_network_wide_batch_deactivation(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'plugin-a/plugin-a.php' => array( 'Version' => '1.0.0' ),
			'plugin-b/plugin-b.php' => array( 'Version' => '2.0.0' ),
		);
		$GLOBALS['babilim_test_is_plugin_active_return'] = array(
			'plugin-a/plugin-a.php' => true,
			'plugin-b/plugin-b.php' => true,
		);

		Babilim::instance()->on_deactivated_plugin( 'plugin-a/plugin-a.php', true );
		Babilim::instance()->on_deactivated_plugin( 'plugin-b/plugin-b.php', true );

		$components = Babilim::instance()->active_components();
		$slugs      = array_column(
			array_values( array_filter( $components, static function ( $c ) {
				return 'plugin' === $c['kind'];
			} ) ),
			'slug'
		);

		$this->assertNotContains( 'plugin-a', $slugs );
		$this->assertNotContains( 'plugin-b', $slugs );
	}

	/**
	 * The mirror case: a plugin deactivated earlier in a request, then
	 * reactivated later in the SAME request, must go back to being
	 * treated as active -- on_activated_plugin() removes it from the
	 * still-accumulating $forced_inactive_plugins set, not just adds it
	 * to $forced_active_plugins alongside a stale inactive entry.
	 */
	public function test_on_activated_plugin_reverses_an_earlier_deactivation_in_the_same_request(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'plugin-a/plugin-a.php' => array( 'Version' => '1.0.0' ),
		);
		$GLOBALS['babilim_test_is_plugin_active_return'] = array(
			'plugin-a/plugin-a.php' => true,
		);

		Babilim::instance()->on_deactivated_plugin( 'plugin-a/plugin-a.php' );
		Babilim::instance()->on_activated_plugin( 'plugin-a/plugin-a.php' );

		$components = Babilim::instance()->active_components();
		$slugs      = array_column(
			array_values( array_filter( $components, static function ( $c ) {
				return 'plugin' === $c['kind'];
			} ) ),
			'slug'
		);

		$this->assertContains( 'plugin-a', $slugs, 'a plugin reactivated later in the same request must not stay stuck forced-inactive' );
	}

	/**
	 * Regression: a plugin deactivated via the real hook, then
	 * reactivated *silently* later in the same request (WordPress
	 * core's own activate_plugin($plugin, '', false, true) -- $silent =
	 * true suppresses the activated_plugin action, so
	 * on_activated_plugin() never runs to remove it from
	 * $forced_inactive_plugins) must not stay stuck excluded. Core's own
	 * update_option('active_plugins', ...) still runs either way (silent
	 * only suppresses the higher-level action, not the option write
	 * itself), firing update_option_active_plugins -- confirmed directly
	 * in a real Playground boot that this actually resolves the same
	 * scenario end to end (see docs/playground-results.md).
	 */
	public function test_on_active_plugins_option_updated_clears_a_stale_forced_inactive_entry_from_a_silent_reactivation(): void {
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'plugin-s/plugin-s.php' => array( 'Version' => '1.0.0' ),
		);
		$GLOBALS['babilim_test_is_plugin_active_return'] = array(
			'plugin-s/plugin-s.php' => true,
		);

		Babilim::instance()->on_deactivated_plugin( 'plugin-s/plugin-s.php' );
		// The silent reactivation itself never calls
		// on_activated_plugin() (that's the whole point of $silent) --
		// only the option-update side effect every real
		// activate_plugin() call has, silent or not.
		Babilim::instance()->on_active_plugins_option_updated();

		$components = Babilim::instance()->active_components();
		$slugs      = array_column(
			array_values( array_filter( $components, static function ( $c ) {
				return 'plugin' === $c['kind'];
			} ) ),
			'slug'
		);

		$this->assertContains( 'plugin-s', $slugs, 'a plugin silently reactivated must not stay stuck forced-inactive once active_plugins has actually been rewritten' );
	}

	public function test_active_components_includes_the_current_theme_and_its_parent(): void {
		$GLOBALS['babilim_test_get_plugins_return']      = array();
		$GLOBALS['babilim_test_is_plugin_active_return'] = array();
		$GLOBALS['babilim_test_wp_get_theme_return']     = new Babilim_Test_Fake_Theme( 'child-theme', '2.0.0', new Babilim_Test_Fake_Theme( 'parent-theme', '1.5.0', false ) );

		$components = Babilim::instance()->active_components();
		$themes     = array_values( array_filter( $components, static function ( $c ) {
			return 'theme' === $c['kind'];
		} ) );

		$this->assertCount( 2, $themes );
		$slugs = array_column( $themes, 'slug' );
		sort( $slugs );
		$this->assertSame( array( 'child-theme', 'parent-theme' ), $slugs );
	}

	/**
	 * Runs in its own process: once the real-core tests have run in this
	 * process, their WP_Translation_File fake stays declared for the rest
	 * of it (tests/support/wp-translation-file-fake.php), and whether
	 * that already happened depends on test order.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_parse_entries_is_safe_without_a_real_wordpress_translation_file_class(): void {
		// wordpress-stubs.php only declares this class's *signature* for
		// PHPStan (never loaded at runtime here) -- so it genuinely
		// doesn't exist in this test process, exercising the same guard
		// a broken/older WordPress core would hit.
		$this->assertFalse( class_exists( 'WP_Translation_File', false ) );

		$this->assertSame( array(), Babilim::instance()->parse_entries( '/nonexistent/path.mo' ) );
	}

	public function test_core_has_is_safe_without_a_real_wordpress_translation_controller_class(): void {
		$this->assertFalse( class_exists( 'WP_Translation_Controller', false ) );

		$this->assertFalse( Babilim::instance()->core_has( 'Save', '' ) );
	}

	public function test_core_plural_forms_is_safe_without_a_real_wordpress_translation_controller_class(): void {
		$this->assertFalse( class_exists( 'WP_Translation_Controller', false ) );

		$this->assertNull( Babilim::instance()->core_plural_forms( 'ja' ) );
	}

	// -- core_plural_forms() only ever trusts WP_Translation_Controller's
	// answer for the site's own current locale -- for any other locale
	// it returns null before even asking, rather than risk handing back
	// some other locale's plural rule under a different name. -----------

	public function test_core_plural_forms_checks_the_current_locale_before_anything_else(): void {
		$GLOBALS['babilim_test_current_locale'] = 'ja';
		$before = $GLOBALS['babilim_test_determine_locale_calls'];

		Babilim::instance()->core_plural_forms( 'ru' );

		// determine_locale() was actually consulted (proving the guard
		// runs at all, even though the ultimate answer is null here
		// either way -- WP_Translation_Controller genuinely doesn't
		// exist in this test process; see the sibling test above).
		$this->assertGreaterThan( $before, $GLOBALS['babilim_test_determine_locale_calls'] );
	}

	/**
	 * A real (if minimal) WP_Translation_Controller stand-in, so this one
	 * test can actually observe the locale-match guard making a
	 * difference -- every other core_plural_forms() test above only ever
	 * proves the method is *safe* without that class, which can't tell a
	 * correct `determine_locale() !== $locale` check apart from an
	 * accidentally-reversed one: both return null the same way once
	 * class_exists( 'WP_Translation_Controller' ) is false regardless of
	 * which branch is taken first. Runs in its own process (bootstrap.php
	 * re-runs there) so defining this class can never leak into the
	 * sibling tests above that assert the class does *not* exist.
	 * Global state is not carried into that process (it's rebuilt there
	 * by bootstrap.php): whatever an earlier test left in $GLOBALS -- a
	 * closure, under some test orders -- can't be serialized across.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_core_plural_forms_trusts_the_real_translation_controller_only_for_the_current_locale(): void {
		if ( ! class_exists( 'WP_Translation_Controller', false ) ) {
			eval(
				'class WP_Translation_Controller {
					private static $instance;
					public static function get_instance() {
						if ( null === self::$instance ) {
							self::$instance = new self();
						}
						return self::$instance;
					}
					public function get_headers( $domain ) {
						return array( "Plural-Forms" => "nplurals=2; plural=(n != 1);" );
					}
				}'
			);
		}

		$GLOBALS['babilim_test_current_locale'] = 'ja';

		// The locale being asked about matches determine_locale(): the
		// guard must let this request through to the (now real) controller.
		$this->assertSame( 'nplurals=2; plural=(n != 1);', Babilim::instance()->core_plural_forms( 'ja' ) );

		// Any other locale must still be refused, even though the
		// controller class is now genuinely available -- a reversed guard
		// would instead answer this one and refuse 'ja' above.
		$this->assertNull( Babilim::instance()->core_plural_forms( 'ru' ) );
	}

	public function test_core_plural_forms_integration_skips_plurals_for_a_non_current_locale(): void {
		$fs = new Babilim_Memory_Fs();
		// No header on the fragment at all, so the fragments' own headers
		// give no answer and resolve_plural_forms() must fall back.
		$fs->put(
			Babilim_Paths::file( 'default/_parts', 'a', 'ru', 'mo' ),
			Babilim_Mo_Writer::write( array( "1 item\x004 items" => array( 'x', 'y', 'z' ) ), array() )
		);
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'a',
			'ru',
			array(
				'component_version' => '1.0.0',
				'files'             => array(),
				'default_files'     => array( 'default/_parts/a-ru.mo' => 'sha-placeholder' ),
				'orphaned_at'       => null,
			)
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};
		$parse_entries = static function ( string $path ) use ( $fs ) {
			$content = $fs->get( $path );
			if ( ! is_string( $content ) ) {
				return array();
			}
			if ( 0 === strpos( $content, '<?php' ) ) {
				$code = (string) preg_replace( '/^<\?php\s*/', '', $content );
				$data = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
				return is_array( $data['messages'] ?? null ) ? $data['messages'] : array();
			}
			$parsed = babilim_test_read_mo( $content );
			unset( $parsed[''] );
			return $parsed;
		};
		// The real Babilim::core_plural_forms(): the site's own current
		// locale is 'ja' (the bootstrap default), but the locale being
		// rebuilt here is 'ru' -- a mismatch, so this must contribute
		// nothing, and the plural entry must be dropped rather than
		// risk carrying 'ja''s plural rule (or no rule at all) into 'ru'.
		$index = new Babilim_Default_Index( $fs, $manifest, $active, $parse_entries, static function () {
			return false;
		}, array( Babilim::instance(), 'core_plural_forms' ) );

		$result = $index->rebuild( 'ru' );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$meta = $manifest->default_index( 'ru' );
		$this->assertNull( $meta['plural_forms'] );
		$this->assertSame( 1, $meta['skipped_plurals'] );
	}

	public function test_run_maintenance_completes_without_a_manifest_present(): void {
		// Smoke test: sweep_orphans() + rebuild_all() against a totally
		// fresh Babilim_Wp_Fs-backed manifest must never fatal.
		Babilim::instance()->run_maintenance();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Proves run_maintenance() itself actually calls
	 * sweep_stale_incoming_dirs(), not merely that the method exists and
	 * works in isolation. Drives it through the real assembly layer
	 * (real Babilim_Wp_Fs, real disk under WP_LANG_DIR, exactly like
	 * production) rather than calling Babilim_Sync_Installer directly.
	 */
	public function test_run_maintenance_sweeps_a_stale_incoming_directory(): void {
		$fs        = new Babilim_Wp_Fs();
		$old_token = dechex( time() - 90000 ) . '-' . bin2hex( random_bytes( 6 ) );
		$old_dir   = Babilim_Paths::incoming_dir( $old_token );
		$fs->mkdir( $old_dir );
		$this->assertTrue( $fs->exists( $old_dir ) );

		Babilim::instance()->run_maintenance();

		$this->assertFalse( $fs->exists( $old_dir ), 'run_maintenance() must have swept the stale incoming directory away.' );
	}

	/**
	 * run_maintenance() calls Babilim_Packager::sweep() (default
	 * constructor): a day-old staging
	 * directory is gone afterwards, a fresh one stays.
	 */
	public function test_run_maintenance_sweeps_a_stale_upload_staging_directory(): void {
		$base  = rtrim( get_temp_dir(), '/' ); // WordPress's temp dir; the packager sweeps it as well as the system one.
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0777, true );
		}
		$old   = $base . '/babilim-upload-' . str_repeat( 'a', 32 );
		$fresh = $base . '/babilim-upload-' . str_repeat( 'b', 32 );
		babilim_test_rmtree( $old );
		babilim_test_rmtree( $fresh );
		mkdir( $old, 0700 );
		mkdir( $fresh, 0700 );
		touch( $old, time() - 90000 );

		Babilim::instance()->run_maintenance();

		$this->assertDirectoryDoesNotExist( $old );
		$this->assertDirectoryExists( $fresh );
		babilim_test_rmtree( $fresh );
	}

	// -- active_components() uses the same wp.org-transient-aware
	// slug Babilim_Inventory itself derives, not a directory-name-only
	// re-derivation of its own. -------------------------------------------

	public function test_active_components_uses_the_wporg_transient_slug_when_it_diverges_from_the_directory_name(): void {
		// "Hello Dolly" is the textbook case: installs as hello.php but
		// its wp.org slug is "hello-dolly" -- see Babilim_Inventory's own
		// plugin_slug_from_file() docblock.
		$GLOBALS['babilim_test_get_plugins_return']      = array( 'hello.php' => array( 'Version' => '1.7.2' ) );
		$GLOBALS['babilim_test_is_plugin_active_return'] = array( 'hello.php' => true );
		$GLOBALS['babilim_test_site_transients']['update_plugins'] = (object) array(
			'no_update' => array(
				'hello.php' => (object) array( 'slug' => 'hello-dolly' ),
			),
		);

		$components = Babilim::instance()->active_components();
		$plugins    = array_values( array_filter( $components, static function ( $c ) {
			return 'plugin' === $c['kind'];
		} ) );

		// A union, not a single value (see Babilim_Default_Index::
		// installed_identities()'s own note): both the directory name
		// and the wp.org slug are registered as active, each at the
		// plugin's real version, so a manifest record keyed under either
		// one is recognized.
		$slugs = array_column( $plugins, 'slug' );
		sort( $slugs );
		$this->assertSame( array( 'hello', 'hello-dolly' ), $slugs );
		foreach ( $plugins as $plugin ) {
			$this->assertSame( '1.7.2', $plugin['version'] );
		}
	}

	// -- active_components() must fail-closed (return null, not an
	// empty array) when get_plugins() itself is unavailable -- an empty
	// array would make Babilim_Default_Index::rebuild() wrongly treat
	// every active plugin's default-domain contribution as inactive. ----

	public function test_active_components_returns_null_when_get_plugins_throws(): void {
		$GLOBALS['babilim_test_get_plugins_throws'] = true;

		$this->assertNull( Babilim::instance()->active_components() );
	}

	// -- deactivating the plugin clears the maintenance cron event. --

	public function test_deactivation_hook_is_registered(): void {
		$this->assertNotEmpty( $GLOBALS['babilim_test_deactivation_hooks'], 'Expected babilim.php to register a deactivation hook' );
	}

	public function test_deactivation_clears_the_maintenance_cron_event(): void {
		$GLOBALS['babilim_test_cron_scheduled']['babilim_maintenance'] = time();

		babilim_deactivate();

		$this->assertFalse( wp_next_scheduled( 'babilim_maintenance' ) );
		$this->assertContains( 'babilim_maintenance', $GLOBALS['babilim_test_cron_cleared'] );
	}

	public function test_deactivation_clears_the_sync_cron_event(): void {
		$GLOBALS['babilim_test_cron_scheduled']['babilim_sync'] = time();

		babilim_deactivate();

		$this->assertFalse( wp_next_scheduled( 'babilim_sync' ) );
		$this->assertContains( 'babilim_sync', $GLOBALS['babilim_test_cron_cleared'] );
	}

	/**
	 * Nothing of this plugin is ever injected into core's update
	 * transients any more, so deactivation must leave them exactly as
	 * they are -- including an entry that looks like a translation offer.
	 */
	public function test_deactivation_leaves_core_update_transients_untouched(): void {
		$plugins = (object) array(
			'translations' => array(
				(object) array( 'type' => 'plugin', 'slug' => 'wp-org-plugin', 'language' => 'de_DE' ),
			),
		);
		set_site_transient( 'update_plugins', $plugins );

		babilim_deactivate();

		$this->assertEquals( $plugins, get_site_transient( 'update_plugins' ) );
	}

	// -- admin-only code is loaded only for an admin request. -------------

	public function test_init_does_not_register_the_admin_menu_outside_admin(): void {
		$GLOBALS['babilim_test_is_admin'] = false;

		Babilim::instance()->init();

		$this->assertEmpty( $this->registered_actions( 'admin_menu' ) );
	}

	public function test_init_registers_the_admin_menu_on_an_admin_request(): void {
		$GLOBALS['babilim_test_is_admin'] = true;

		Babilim::instance()->init();

		$this->assertNotEmpty( $this->registered_actions( 'admin_menu' ) );
	}

	// -- twice-daily sync cron + the challenge REST route. ----------------

	public function test_init_registers_the_sync_cron_callback_and_schedules_it_twice_daily(): void {
		Babilim::instance()->init();

		$this->assertNotEmpty( $this->registered_actions( 'babilim_sync' ) );
		$this->assertNotFalse( wp_next_scheduled( 'babilim_sync' ) );
	}

	public function test_init_does_not_reschedule_an_already_scheduled_sync_cron(): void {
		$GLOBALS['babilim_test_cron_scheduled']['babilim_sync'] = 12345;

		Babilim::instance()->init();

		$this->assertSame( 12345, wp_next_scheduled( 'babilim_sync' ) );
	}

	public function test_init_registers_the_challenge_route_on_rest_api_init(): void {
		Babilim::instance()->init();

		do_action( 'rest_api_init' );

		$routes = $GLOBALS['babilim_test_rest_routes'];
		$this->assertCount( 1, $routes );
		$this->assertSame( 'babilim/v1', $routes[0]['namespace'] );
		$this->assertSame( '/challenge', $routes[0]['route'] );
		$this->assertSame( 'GET', $routes[0]['args']['methods'] );
		$this->assertTrue( is_callable( $routes[0]['args']['callback'] ) );
		$this->assertTrue( call_user_func( $routes[0]['args']['permission_callback'] ) );
	}

	public function test_challenge_route_declares_the_nonce_arg_with_type_and_validation(): void {
		Babilim::instance()->init();

		do_action( 'rest_api_init' );

		$nonce_arg = $GLOBALS['babilim_test_rest_routes'][0]['args']['args']['nonce'];
		$this->assertTrue( $nonce_arg['required'] );
		$this->assertSame( 'string', $nonce_arg['type'] );
		$this->assertSame( 'sanitize_text_field', $nonce_arg['sanitize_callback'] );
		$this->assertTrue( is_callable( $nonce_arg['validate_callback'] ) );
		$this->assertTrue( call_user_func( $nonce_arg['validate_callback'], 'abc123' ) );
		$this->assertFalse( call_user_func( $nonce_arg['validate_callback'], array( 'x' ) ) );
	}

	// -- run_maintenance() feeds sweep_orphans()'s own outcome into the
	// client-event ring buffer. ---------------------------------------

	public function test_run_maintenance_records_a_client_event_for_an_orphaned_component(): void {
		$fs = new Babilim_Wp_Fs();
		Babilim_Paths::ensure_layout( $fs );
		$fs->put( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'removed-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'plugins/removed-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		// Some OTHER plugin is still listed, so get_plugins() itself is
		// genuinely available -- removed-plugin is the one that's gone.
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'still-here/still-here.php' => array( 'Name' => 'Still Here' ),
		);

		try {
			Babilim::instance()->run_maintenance();

			$events = ( new Babilim_State() )->drain_client_events();
			$this->assertCount( 1, $events );
			$this->assertSame( 'orphaned', $events[0]['code'] );
			$this->assertSame( 'plugin', $events[0]['kind'] );
			$this->assertSame( 'removed-plugin', $events[0]['slug'] );
			$this->assertSame( 'ja', $events[0]['locale'] );
		} finally {
			$fs->delete( Babilim_Paths::manifest_file() );
		}
	}

	// -- run_sync() verifies every installed item before syncing, and
	// degrades (plus records a client event for) any that fails. --------

	public function test_run_sync_records_the_integrity_event_once_per_degrade(): void {
		$fs = new Babilim_Wp_Fs();
		Babilim_Paths::ensure_layout( $fs );
		$path = Babilim_Paths::file( 'plugins', 'tampered-plugin', 'ja', 'mo' );
		$fs->put( $path, 'actual-bytes' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'tampered-plugin',
			'ja',
			array(
				'build_id'      => 'bd_a1b2c3d4e5f6',
				'installed_at'  => '2026-01-01T00:00:00Z',
				'files'         => array( 'plugins/tampered-plugin-ja.mo' => hash( 'sha256', 'not-the-actual-bytes' ) ),
				'default_files' => array(),
				'orphaned_at'   => null,
			)
		);

		try {
			$state  = new Babilim_State();
			$events = array();
			for ( $i = 0; $i < 3; $i++ ) {
				Babilim::instance()->run_sync();
				$events = array_merge( $events, $state->drain_client_events() );
			}

			$this->assertCount( 1, $events, 'later syncs of a still-degraded record must not report it again' );
			$record = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'tampered-plugin', 'ja' );
			$this->assertFalse( $record['integrity']['ok'], 'the degraded record stays failed across syncs' );
		} finally {
			$fs->delete( Babilim_Paths::manifest_file() );
			$fs->delete( $path );
		}
	}

	public function test_run_sync_degrades_and_records_a_client_event_for_a_record_that_fails_verify(): void {
		$fs = new Babilim_Wp_Fs();
		Babilim_Paths::ensure_layout( $fs );
		$path = Babilim_Paths::file( 'plugins', 'tampered-plugin', 'ja', 'mo' );
		$fs->put( $path, 'actual-bytes' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'tampered-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				// Deliberately the wrong digest -- verify() must find a
				// mismatch against what's actually on disk.
				'files'              => array( 'plugins/tampered-plugin-ja.mo' => hash( 'sha256', 'not-the-actual-bytes' ) ),
				'default_files'      => array(),
				'orphaned_at'        => null,
			)
		);

		try {
			Babilim::instance()->run_sync();

			// A fresh reader -- $manifest's own in-memory cache was
			// already loaded before run_sync() wrote through its own,
			// separately-constructed Babilim_Manifest instance (same
			// pattern production itself uses: every lazily-built
			// collaborator here reads/writes the same underlying file
			// through its own instance, never a shared one).
			$record = ( new Babilim_Manifest( $fs ) )->installed( 'plugin', 'tampered-plugin', 'ja' );
			$this->assertFalse( $fs->exists( $path ), 'degrade() must have removed the served file.' );
			$this->assertSame( array(), $record['files'], 'degrade() must have cleared the served files list.' );

			$events = ( new Babilim_State() )->drain_client_events();
			$this->assertCount( 1, $events );
			$this->assertSame( 'integrity_failed', $events[0]['code'] );
			$this->assertSame( 'plugin', $events[0]['kind'] );
			$this->assertSame( 'tampered-plugin', $events[0]['slug'] );
			$this->assertSame( 'ja', $events[0]['locale'] );
		} finally {
			$fs->delete( Babilim_Paths::manifest_file() );
			$fs->delete( $path );
		}
	}

	/**
	 * The sync cron is the only automatic path that installs anything:
	 * after the inventory report lists an update for an installed locale,
	 * run_sync() hands it to the sync installer. The download answer here
	 * is a 403 verification_required, which the installer turns into the
	 * stored warning -- observable proof the download was attempted from
	 * run_sync() itself.
	 */
	public function test_run_sync_hands_the_listed_updates_to_the_sync_installer(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_wired' );
		update_option( 'babilim_site_token', 'tok_wired' );
		$inventory = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'components' => array(),
					'updates'    => array( array( 'kind' => 'plugin', 'slug' => 'wired', 'locale' => 'ja', 'build_id' => 'bd_000000000009', 'build_no' => 2 ) ),
					'license'    => array(),
					'quota'      => array(),
					'features'   => array(),
					'contract'   => '1.3.0',
				)
			),
			'headers'  => array(),
		);
		$refusal   = array(
			'response' => array( 'code' => 403 ),
			'body'     => (string) wp_json_encode( array( 'error' => array( 'code' => 'verification_required', 'message' => 'm', 'hint' => 'h' ) ) ),
			'headers'  => array(),
		);
		$GLOBALS['babilim_test_wp_remote_responses'] = array( $inventory, $refusal );
		$GLOBALS['babilim_test_wp_remote_requests']  = array();

		Babilim::instance()->run_sync();

		$urls = array_column( $GLOBALS['babilim_test_wp_remote_requests'], 'url' );
		$this->assertCount( 1, array_filter( $urls, static function ( $u ) { return false !== strpos( $u, '/v1/downloads' ); } ), 'run_sync() asks for the listed build exactly once' );
		$this->assertNotFalse( get_option( 'babilim_verification_warning', false ), 'and the refusal is recorded as the verification warning' );
	}

	/**
	 * Primes consent, a token, one managed language and a queue whose first
	 * answer is an inventory response; every later request gets $download.
	 *
	 * @param array[] $updates    updates[] entries.
	 * @param array   $components components[] of the inventory answer.
	 * @param array   $download   The answer to every download request.
	 */
	private function prime_run_sync( array $updates, array $components, array $download ): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_wired' );
		update_option( 'babilim_site_token', 'tok_wired' );
		update_option( 'babilim_manual_locales', array( 'ja' ) );
		$inventory = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'components' => $components,
					'updates'    => $updates,
					'license'    => array( 'kind' => 'paid' ),
					'quota'      => array(),
					'features'   => array(),
					'contract'   => '1.3.0',
				)
			),
			'headers'  => array(),
		);
		$GLOBALS['babilim_test_wp_remote_responses'] = array( $inventory, $download );
		$GLOBALS['babilim_test_wp_remote_requests']  = array();
	}

	private function download_requests(): int {
		$urls = array_column( $GLOBALS['babilim_test_wp_remote_requests'], 'url' );
		return count( array_filter( $urls, static function ( $u ) { return false !== strpos( $u, '/v1/downloads' ); } ) );
	}

	private function available_component( string $slug, string $locale = 'ja' ): array {
		return array( 'kind' => 'plugin', 'slug' => $slug, 'locales' => array( $locale => array( 'status' => 'babilim_available', 'build_id' => 'bd_0000000000a1' ) ) );
	}

	private function failing_download(): array {
		return array( 'response' => array( 'code' => 500 ), 'body' => '', 'headers' => array() );
	}

	public function test_run_sync_installs_an_available_translation_for_a_managed_language(): void {
		$this->prime_run_sync( array(), array( $this->available_component( 'fresh' ) ), $this->failing_download() );

		Babilim::instance()->run_sync();

		$this->assertSame( 1, $this->download_requests(), 'the available build is requested from the sync cron' );
	}

	public function test_run_sync_does_not_install_for_a_stopped_language(): void {
		$this->prime_run_sync( array(), array( $this->available_component( 'fresh' ) ), $this->failing_download() );
		// ja is a detected language (the site locale) and then stopped, so
		// only the disabled exclusion keeps it out of the install set.
		$GLOBALS['babilim_test_site_locale'] = 'ja';
		update_option( 'babilim_manual_locales', array() );
		$this->assertContains( 'ja', ( new Babilim_Inventory( static function () { return array(); }, static function () { return array(); }, static function () { return false; }, static function () { return array(); } ) )->detected_locales() );
		update_option( 'babilim_disabled_locales', array( 'ja' ) );

		Babilim::instance()->run_sync();

		$this->assertSame( 0, $this->download_requests() );
	}

	public function test_run_sync_shares_the_per_run_cap_between_updates_and_available_builds(): void {
		$updates = array();
		for ( $i = 0; $i < Babilim_Sync_Installer::MAX_PER_RUN; $i++ ) {
			$updates[] = array( 'kind' => 'plugin', 'slug' => 'u' . $i, 'locale' => 'ja', 'build_id' => sprintf( 'bd_%012d', 100 + $i ), 'build_no' => 2 );
		}
		$this->prime_run_sync( $updates, array( $this->available_component( 'fresh' ) ), $this->failing_download() );

		Babilim::instance()->run_sync();

		$this->assertSame( Babilim_Sync_Installer::MAX_PER_RUN, $this->download_requests(), 'the available build gets no download once updates used the budget' );
	}

	public function test_run_sync_does_not_try_available_builds_after_updates_hit_a_rate_limit(): void {
		$limited = array(
			'response' => array( 'code' => 429 ),
			'body'     => (string) wp_json_encode( array( 'error' => array( 'code' => 'rate_limited', 'message' => 'm', 'hint' => 'h' ) ) ),
			'headers'  => array( 'Retry-After' => '30' ),
		);
		$updates = array( array( 'kind' => 'plugin', 'slug' => 'u1', 'locale' => 'ja', 'build_id' => 'bd_000000000101', 'build_no' => 2 ) );
		$this->prime_run_sync( $updates, array( $this->available_component( 'fresh' ) ), $limited );

		Babilim::instance()->run_sync();

		$this->assertSame( 1, $this->download_requests(), 'only the update was attempted' );
	}

	public function test_run_sync_leaves_a_record_that_passes_verify_untouched(): void {
		$fs = new Babilim_Wp_Fs();
		Babilim_Paths::ensure_layout( $fs );
		$path  = Babilim_Paths::file( 'plugins', 'intact-plugin', 'ja', 'mo' );
		$bytes = 'actual-bytes';
		$fs->put( $path, $bytes );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'intact-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'              => array( 'plugins/intact-plugin-ja.mo' => hash( 'sha256', $bytes ) ),
				'default_files'      => array(),
				'orphaned_at'        => null,
			)
		);

		try {
			Babilim::instance()->run_sync();

			$this->assertTrue( $fs->exists( $path ) );
			$this->assertSame( array(), ( new Babilim_State() )->drain_client_events() );
		} finally {
			$fs->delete( Babilim_Paths::manifest_file() );
			$fs->delete( $path );
		}
	}

	/**
	 * An orphaned record is sweep_orphans()'s own concern (see its
	 * mark-then-delete lifecycle) -- run_sync()'s own verify pass must
	 * leave it alone rather than degrading it a second time.
	 */
	public function test_run_sync_skips_an_already_orphaned_record(): void {
		$fs = new Babilim_Wp_Fs();
		Babilim_Paths::ensure_layout( $fs );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'orphaned-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'              => array( 'plugins/orphaned-plugin-ja.mo' => hash( 'sha256', 'x' ) ),
				'default_files'      => array(),
				'orphaned_at'        => gmdate( 'c' ),
			)
		);

		try {
			Babilim::instance()->run_sync();

			$this->assertSame( array(), ( new Babilim_State() )->drain_client_events() );
		} finally {
			$fs->delete( Babilim_Paths::manifest_file() );
		}
	}

	// -- unzip_package() -- the real ZipArchive implementation, never
	// stood in for by a fake in any other test file (Babilim_Installer's
	// own tests inject a hand-written $unzip callable instead -- see
	// test-installer.php's own unzip_fake()). Needs a real zip file on
	// real disk, since ZipArchive::open() has no in-memory mode; each
	// test writes one under get_temp_dir()'s own process-scoped scratch
	// area (see tests/bootstrap.php's BABILIM_TEST_TMP_ROOT docblock)
	// and removes it in finally.

	private function write_real_zip( array $entries ): string {
		if ( ! is_dir( get_temp_dir() ) ) {
			mkdir( get_temp_dir(), 0777, true );
		}
		$path = get_temp_dir() . 'babilim-test-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		return $path;
	}

	public function test_unzip_package_returns_every_entry_of_an_ordinary_small_zip(): void {
		$path = $this->write_real_zip(
			array(
				'manifest.json' => '{"x":1}',
				'woocommerce-zh_TW.mo' => 'fake-mo-bytes',
			)
		);
		try {
			$entries = Babilim::instance()->unzip_package( $path );

			$this->assertIsArray( $entries );
			$this->assertSame( '{"x":1}', $entries['manifest.json'] );
			$this->assertSame( 'fake-mo-bytes', $entries['woocommerce-zh_TW.mo'] );
		} finally {
			unlink( $path );
		}
	}

	/**
	 * A zip whose own DECLARED total decompressed size (ZipArchive's own
	 * per-entry stat -- the check must read the zip's own metadata, not
	 * the compressed file's actual size on disk, which stays tiny for a
	 * real zip-bomb-shaped fixture) exceeds MAX_UNZIPPED_TOTAL_BYTES
	 * must be refused -- the same `false` ZipArchive::open() failing
	 * returns, which Babilim_Installer's own install() already maps to
	 * `unzip_failed`.
	 */
	public function test_unzip_package_refuses_a_zip_whose_declared_total_size_exceeds_the_cap(): void {
		$huge = str_repeat( 'A', 51 * 1024 * 1024 ); // 51 MiB, over the 50 MiB cap.
		$path = $this->write_real_zip( array( 'bomb.mo' => $huge ) );
		unset( $huge );
		try {
			$result = Babilim::instance()->unzip_package( $path );

			$this->assertFalse( $result );
		} finally {
			unlink( $path );
		}
	}

	public function test_unzip_package_accepts_a_zip_at_exactly_the_size_cap(): void {
		$path = $this->write_real_zip( array( 'ok.mo' => str_repeat( 'A', 50 * 1024 * 1024 ) ) );
		try {
			$result = Babilim::instance()->unzip_package( $path );

			$this->assertIsArray( $result, 'exactly at the cap must still be accepted -- only strictly OVER it is refused' );
		} finally {
			unlink( $path );
		}
	}

	/**
	 * A zip with more entries than MAX_UNZIPPED_FILE_COUNT must be
	 * refused before this method ever reads a single one of them --
	 * checked against ZipArchive::$numFiles up front, not discovered
	 * partway through the read loop.
	 */
	public function test_unzip_package_refuses_a_zip_with_too_many_entries(): void {
		$entries = array();
		for ( $i = 0; $i <= 500; $i++ ) {
			$entries[ "f{$i}.mo" ] = 'x';
		}
		$path = $this->write_real_zip( $entries );
		try {
			$result = Babilim::instance()->unzip_package( $path );

			$this->assertFalse( $result );
		} finally {
			unlink( $path );
		}
	}

	public function test_unzip_package_accepts_a_zip_at_exactly_the_file_count_cap(): void {
		$entries = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$entries[ "f{$i}.mo" ] = 'x';
		}
		$path = $this->write_real_zip( $entries );
		try {
			$result = Babilim::instance()->unzip_package( $path );

			$this->assertIsArray( $result );
			$this->assertCount( 500, $result );
		} finally {
			unlink( $path );
		}
	}
}

/**
 * A minimal WP_Theme-shaped fake -- see Babilim_Inventory's own theme_field()
 * for the same duck-typed contract this stands in for.
 */
final class Babilim_Test_Fake_Theme {
	private $stylesheet;
	private $version;
	private $parent;

	/**
	 * @param string                          $stylesheet Theme slug.
	 * @param string                          $version    Theme version.
	 * @param Babilim_Test_Fake_Theme|false    $parent     Parent theme, or false (matches WP_Theme::parent()).
	 */
	public function __construct( string $stylesheet, string $version, $parent ) {
		$this->stylesheet = $stylesheet;
		$this->version    = $version;
		$this->parent     = $parent;
	}

	public function get_stylesheet() {
		return $this->stylesheet;
	}

	public function get( $key ) {
		return 'Version' === $key ? $this->version : false;
	}

	public function parent() {
		return $this->parent;
	}
}
