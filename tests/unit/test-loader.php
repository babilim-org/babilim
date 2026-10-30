<?php
/**
 * Tests for Babilim_Loader -- the translation-loading layer's four
 * filters, its two `default`-domain supplement actions, and its
 * recursive-lock/layering behaviour.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Loader extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		Babilim_Loader::reset_for_tests();
		$this->remove_recursive( Babilim_Paths::root() );
	}

	protected function tearDown(): void {
		$this->remove_recursive( Babilim_Paths::root() );
	}

	private function remove_recursive( string $dir ): void {
		if ( is_link( $dir ) ) {
			unlink( $dir );
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
			is_dir( $path ) && ! is_link( $path ) ? $this->remove_recursive( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * Creates every layout directory this test needs, bypassing
	 * Babilim_Fs (the loading layer never touches it -- it only ever
	 * calls is_readable() against real paths).
	 *
	 * @param string $type One of Babilim_Paths' DIR_TYPES.
	 */
	private function mkdir_type( string $type ): void {
		if ( ! is_dir( Babilim_Paths::dir( $type ) ) ) {
			mkdir( Babilim_Paths::dir( $type ), 0777, true );
		}
	}

	private function write_file( string $type, string $name, string $locale, string $ext, string $contents = 'x' ): void {
		$this->mkdir_type( $type );
		file_put_contents( Babilim_Paths::file( $type, $name, $locale, $ext ), $contents );
	}

	// -- register() ---------------------------------------------------------

	/**
	 * Asserts callback, priority AND accepted_args for all six
	 * registrations -- a wrong accepted_args on load_script_translations
	 * (4 -> 3) is a real ArgumentCountError fatal in WordPress, and a
	 * wrong priority anywhere silently changes load order relative to
	 * other plugins, so both need direct coverage, not just "something
	 * got registered".
	 */
	public function test_register_wires_the_four_filters_and_two_actions(): void {
		$expected_filters = array(
			'lang_dir_for_domain'          => array( 'lang_dir_for_domain', 3 ),
			'load_translation_file'        => array( 'load_translation_file', 3 ),
			'load_script_translation_file' => array( 'load_script_translation_file', 3 ),
			'load_script_translations'     => array( 'load_script_translations', 4 ),
		);
		foreach ( $expected_filters as $tag => $expectation ) {
			list( $method, $accepted_args ) = $expectation;
			$hook                           = $GLOBALS['babilim_test_filters'][ $tag ][0] ?? null;
			$this->assertNotNull( $hook, "missing add_filter for {$tag}" );
			$this->assertSame( array( Babilim_Loader::class, $method ), $hook['callback'], "{$tag} callback" );
			$this->assertSame( 10, $hook['priority'], "{$tag} priority" );
			$this->assertSame( $accepted_args, $hook['accepted_args'], "{$tag} accepted_args" );
		}

		$expected_actions = array(
			'after_setup_theme' => 0,
			'change_locale'     => 1,
		);
		foreach ( $expected_actions as $tag => $accepted_args ) {
			$hook = $GLOBALS['babilim_test_actions'][ $tag ][0] ?? null;
			$this->assertNotNull( $hook, "missing add_action for {$tag}" );
			$this->assertSame( array( Babilim_Loader::class, 'load_default_supplements' ), $hook['callback'], "{$tag} callback" );
			$this->assertSame( 10, $hook['priority'], "{$tag} priority" );
			$this->assertSame( $accepted_args, $hook['accepted_args'], "{$tag} accepted_args" );
		}
	}

	// -- load_translation_file: default domain is never layered -------------

	public function test_load_translation_file_passes_default_domain_through_untouched(): void {
		// A file that would otherwise match the ordinary plugins/custom
		// layering for domain "default" -- proving the domain==='default'
		// guard bypasses the layering loop entirely, not merely that no
		// matching file happens to exist (see load_default_supplements()
		// for the actual default-domain loader).
		$this->write_file( 'custom/plugins', 'default', 'de_DE', 'mo' );
		$this->write_file( 'plugins', 'default', 'de_DE', 'mo' );

		$result = Babilim_Loader::load_translation_file( '/wp/languages/de_DE.mo', 'default', 'de_DE' );

		$this->assertSame( '/wp/languages/de_DE.mo', $result );
		$this->assertCount( 0, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	/**
	 * WordPress core's own load_textdomain() (wp-includes/l10n.php)
	 * applies this filter as `apply_filters( 'load_translation_file',
	 * $file, $domain )` -- only TWO arguments, no $locale -- on WordPress
	 * 6.5.x specifically (confirmed against the real WP 6.5.12 core
	 * source, read directly out of a real WordPress Playground boot:
	 * `l10n.php:840`; WordPress 6.6.0 added a third `$locale` argument,
	 * confirmed the same way against 6.8.10/7.1.2 core, `l10n.php:843` --
	 * see docs/playground-results.md). Babilim_Loader::register()
	 * registers this hook with accepted_args=3, so WP_Hook calls the
	 * callback with only the 2 real arguments 6.5.x itself gives it -- a
	 * $locale parameter with no default value is a PHP ArgumentCountError
	 * fatal on every single request on that version, the moment
	 * WordPress itself boots (load_default_textdomain() is the very
	 * first caller, from wp-settings.php, before any plugin/theme code).
	 */
	public function test_load_translation_file_survives_load_default_textdomains_two_argument_call(): void {
		Babilim_Loader::register();

		$result = apply_filters( 'load_translation_file', '/wp/languages/en_US.mo', 'default' );

		$this->assertSame( '/wp/languages/en_US.mo', $result );
	}

	/**
	 * The non-'default'-domain case of the same real-core call shape as
	 * the test above: with no $locale argument at all, this must still
	 * find and load the layered file, using determine_locale() as the
	 * fallback -- the same one WordPress core's own load_textdomain()
	 * itself falls back to internally when ITS OWN $locale parameter is
	 * empty (see that function's own `if ( ! $locale ) { $locale =
	 * determine_locale(); }`). A bare `$locale = ''` default would only
	 * avoid a fatal, not fix the real problem -- Babilim_Paths::
	 * is_safe_locale('') is false, so every real, non-default-domain
	 * call would then silently no-op forever, never loading the
	 * babilim/ supplement at all. This property is verified by the real
	 * WordPress Playground run in tests/playground/, not just by this
	 * repo's own mocked filter dispatch -- see docs/playground-results.md.
	 */
	public function test_load_translation_file_falls_back_to_determine_locale_with_no_locale_argument(): void {
		Babilim_Loader::register();
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';

		$result = apply_filters( 'load_translation_file', '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini' );

		$this->assertSame( '/wp/plugins/mini/languages/mini-de_DE.mo', $result );
		$this->assertSame(
			array( Babilim_Paths::file( 'plugins', 'mini', 'de_DE', 'mo' ) ),
			array_column( $GLOBALS['babilim_test_load_textdomain_calls'], 'mofile' )
		);
	}

	// -- load_translation_file: layering order -------------------------------

	public function test_load_translation_file_loads_custom_before_babilim_and_returns_file_unchanged(): void {
		$this->write_file( 'custom/plugins', 'mini', 'de_DE', 'mo' );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		$result = Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertSame( '/wp/plugins/mini/languages/mini-de_DE.mo', $result );
		$this->assertSame(
			array(
				Babilim_Paths::file( 'custom/plugins', 'mini', 'de_DE', 'mo' ),
				Babilim_Paths::file( 'plugins', 'mini', 'de_DE', 'mo' ),
			),
			array_column( $GLOBALS['babilim_test_load_textdomain_calls'], 'mofile' )
		);
		foreach ( $GLOBALS['babilim_test_load_textdomain_calls'] as $call ) {
			$this->assertSame( 'mini', $call['domain'] );
			$this->assertSame( 'de_DE', $call['locale'] );
		}
	}

	public function test_load_translation_file_loads_babilim_only_when_no_custom_override_exists(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertSame(
			array( Babilim_Paths::file( 'plugins', 'mini', 'de_DE', 'mo' ) ),
			array_column( $GLOBALS['babilim_test_load_textdomain_calls'], 'mofile' )
		);
	}

	public function test_load_translation_file_loads_the_theme_layer_for_a_theme_domain(): void {
		$this->write_file( 'custom/themes', 'twenty', 'de_DE', 'mo' );

		Babilim_Loader::load_translation_file( '/wp/themes/twenty/languages/twenty-de_DE.mo', 'twenty', 'de_DE' );

		$this->assertSame(
			array( Babilim_Paths::file( 'custom/themes', 'twenty', 'de_DE', 'mo' ) ),
			array_column( $GLOBALS['babilim_test_load_textdomain_calls'], 'mofile' )
		);
	}

	public function test_load_translation_file_prefers_l10n_php_when_mo_is_absent(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'l10n.php' );

		Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertSame(
			array( Babilim_Paths::file( 'plugins', 'mini', 'de_DE', 'l10n.php' ) ),
			array_column( $GLOBALS['babilim_test_load_textdomain_calls'], 'mofile' )
		);
	}

	/**
	 * When both forms exist for the same layer, exactly one load happens
	 * -- the `.mo` -- never both.
	 */
	public function test_load_translation_file_loads_only_the_mo_when_both_mo_and_l10n_php_exist(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'l10n.php' );

		Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertCount( 1, $GLOBALS['babilim_test_load_textdomain_calls'] );
		$this->assertSame(
			Babilim_Paths::file( 'plugins', 'mini', 'de_DE', 'mo' ),
			$GLOBALS['babilim_test_load_textdomain_calls'][0]['mofile']
		);
	}

	public function test_load_translation_file_calls_nothing_when_neither_layer_has_a_file(): void {
		$result = Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertSame( '/wp/plugins/mini/languages/mini-de_DE.mo', $result );
		$this->assertCount( 0, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	/**
	 * $file is WordPress core's own filter value -- already possibly
	 * rewritten by an earlier callback on this same filter before this
	 * one ever runs. A `string` parameter type here would TypeError at
	 * the call boundary, before this method's own body (and its
	 * catch-all try/catch) ever runs, the moment anything upstream
	 * passes a non-string -- an array is the strictest possible proof:
	 * PHP's own scalar coercion can never turn an array into a string,
	 * in strict_types mode or not, so this fails immediately if the
	 * parameter is ever re-typed back to `string`.
	 */
	public function test_load_translation_file_survives_a_non_string_file_value_from_an_earlier_filter(): void {
		$result = Babilim_Loader::load_translation_file( array( 'not', 'a', 'string' ), 'mini', 'de_DE' );

		$this->assertSame( array( 'not', 'a', 'string' ), $result );
	}

	public function test_load_translation_file_noops_when_root_does_not_exist(): void {
		$this->assertFalse( is_dir( Babilim_Paths::root() ) );

		$result = Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertSame( '/wp/plugins/mini/languages/mini-de_DE.mo', $result );
		$this->assertCount( 0, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	/**
	 * @dataProvider provide_unsafe_domain_or_locale
	 *
	 * The fixture file is deliberately named to match exactly what
	 * $domain-$locale.mo would resolve to if the safety guard were
	 * skipped -- Babilim_Paths::file() itself doesn't validate its
	 * $name/$locale arguments, only Babilim_Loader's own guard does, so
	 * a mismatched fixture name would pass even with the guard removed
	 * (is_readable() would simply never find anything either way) and
	 * prove nothing.
	 */
	public function test_load_translation_file_noops_for_an_unsafe_domain_or_locale( string $domain, string $locale ): void {
		$this->mkdir_type( 'plugins' );
		file_put_contents( Babilim_Paths::dir( 'plugins' ) . "/{$domain}-{$locale}.mo", 'x' );

		$result = Babilim_Loader::load_translation_file( '/wp/x.mo', $domain, $locale );

		$this->assertSame( '/wp/x.mo', $result );
		$this->assertCount( 0, $GLOBALS['babilim_test_load_textdomain_calls'] );
		$this->assertSame(
			0,
			Babilim_Loader::fs_probe_count_for_tests(),
			'an unsafe domain/locale must return before making any is_dir()/is_readable() call'
		);
	}

	public function provide_unsafe_domain_or_locale(): array {
		return array(
			'unsafe domain' => array( 'mini.evil', 'de_DE' ),
			'unsafe locale' => array( 'mini', 'zh TW' ),
		);
	}

	public function test_load_translation_file_does_not_crash_when_load_textdomain_throws(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );
		$GLOBALS['babilim_test_load_textdomain_throws'] = true;

		$result = Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertSame( '/wp/plugins/mini/languages/mini-de_DE.mo', $result );
	}

	// -- load_translation_file: recursive lock -------------------------------

	public function test_load_translation_file_recursive_lock_stops_a_reentrant_call_from_looping_again(): void {
		$this->write_file( 'custom/plugins', 'mini', 'de_DE', 'mo' );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );
		$GLOBALS['babilim_test_load_textdomain_reentrant'] = true;

		// A real WordPress load_textdomain() applies the load_translation_file
		// filter itself -- babilim_test_load_textdomain_reentrant (see
		// tests/bootstrap.php) reproduces that. Without the lock this would
		// recurse forever; with it, each of the two layer loads triggers one
		// reentrant (locked, no-op) call and the outer loop still only runs
		// its two iterations once.
		apply_filters( 'load_translation_file', '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertCount( 2, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	/**
	 * The lock must be released once the call that took it out returns
	 * normally -- not just "never deadlocks within one call" (the
	 * reentrant test above), but genuinely available again for the very
	 * next, independent call. A mutation that deleted the `finally`
	 * block's `unset()` would leave the domain permanently locked after
	 * the first call, and this second call would then no-op.
	 */
	public function test_load_translation_file_lock_is_released_after_a_normal_call_so_a_second_call_still_layers(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );
		Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertCount( 2, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	/**
	 * The lock is held in a `try`/`finally` specifically so a thrown
	 * exception mid-layering (a corrupt file, a load_textdomain()
	 * failure) still releases it -- proven here by making the first
	 * call's load_textdomain() throw (via babilim_test_load_textdomain_throws,
	 * see tests/bootstrap.php) and confirming a second, ordinary call
	 * right after still layers instead of finding the domain still
	 * locked.
	 */
	public function test_load_translation_file_lock_is_released_after_a_call_that_throws(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );
		$GLOBALS['babilim_test_load_textdomain_throws'] = true;

		Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$GLOBALS['babilim_test_load_textdomain_throws'] = false;
		Babilim_Loader::load_translation_file( '/wp/plugins/mini/languages/mini-de_DE.mo', 'mini', 'de_DE' );

		$this->assertCount( 2, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	// -- lang_dir_for_domain --------------------------------------------------

	public function test_lang_dir_for_domain_leaves_core_directory_untouched_when_core_already_has_the_file(): void {
		$core_dir = BABILIM_TEST_TMP_ROOT . '/babilim-core-dir-' . uniqid( '', true );
		mkdir( $core_dir, 0777, true );
		file_put_contents( $core_dir . '/mini-de_DE.mo', 'x' );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		try {
			$result = Babilim_Loader::lang_dir_for_domain( $core_dir, 'mini', 'de_DE' );
			$this->assertSame( $core_dir, $result );
		} finally {
			$this->remove_recursive( $core_dir );
		}
	}

	/**
	 * The `.l10n.php` variant of the test above -- core's own registered
	 * directory can just as easily already hold the newer PHP-array
	 * format instead of a `.mo`.
	 */
	public function test_lang_dir_for_domain_leaves_core_directory_untouched_when_core_already_has_an_l10n_php_file(): void {
		$core_dir = BABILIM_TEST_TMP_ROOT . '/babilim-core-dir-' . uniqid( '', true );
		mkdir( $core_dir, 0777, true );
		file_put_contents( $core_dir . '/mini-de_DE.l10n.php', '<?php return array();' );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		try {
			$result = Babilim_Loader::lang_dir_for_domain( $core_dir, 'mini', 'de_DE' );
			$this->assertSame( $core_dir, $result );
		} finally {
			$this->remove_recursive( $core_dir );
		}
	}

	/**
	 * For a theme's own registered language directory, core looks for
	 * `{$path}{$locale}.mo` -- no domain prefix -- not
	 * `{$path}{$domain}-{$locale}.mo`. That shape must be recognized as
	 * "core already has the file" too, or this filter would substitute
	 * our own directory right over a file core can already find.
	 */
	public function test_lang_dir_for_domain_leaves_core_directory_untouched_for_a_theme_dir_with_no_domain_prefix(): void {
		$core_dir = BABILIM_TEST_TMP_ROOT . '/babilim-core-theme-dir-' . uniqid( '', true );
		mkdir( $core_dir, 0777, true );
		file_put_contents( $core_dir . '/de_DE.mo', 'x' );
		$this->write_file( 'themes', 'twenty', 'de_DE', 'mo' );

		try {
			$result = Babilim_Loader::lang_dir_for_domain( $core_dir, 'twenty', 'de_DE' );
			$this->assertSame( $core_dir, $result );
		} finally {
			$this->remove_recursive( $core_dir );
		}
	}

	public function test_lang_dir_for_domain_substitutes_our_plugins_dir_when_path_is_false(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		$result = Babilim_Loader::lang_dir_for_domain( false, 'mini', 'de_DE' );

		$this->assertSame( trailingslashit( Babilim_Paths::dir( 'plugins' ) ), $result );
	}

	public function test_lang_dir_for_domain_substitutes_our_themes_dir_when_path_is_false(): void {
		$this->write_file( 'themes', 'twenty', 'de_DE', 'mo' );

		$result = Babilim_Loader::lang_dir_for_domain( false, 'twenty', 'de_DE' );

		$this->assertSame( trailingslashit( Babilim_Paths::dir( 'themes' ) ), $result );
	}

	public function test_lang_dir_for_domain_substitutes_our_dir_when_cores_registered_location_lacks_the_locale_file(): void {
		// WP 6.7+ lesson learned: load_plugin_textdomain() registers a path
		// but only loads it just-in-time -- an empty/unrelated registered
		// directory must not block our own directory from being offered.
		$core_dir = BABILIM_TEST_TMP_ROOT . '/babilim-core-dir-' . uniqid( '', true );
		mkdir( $core_dir, 0777, true );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		try {
			$result = Babilim_Loader::lang_dir_for_domain( $core_dir, 'mini', 'de_DE' );
			$this->assertSame( trailingslashit( Babilim_Paths::dir( 'plugins' ) ), $result );
		} finally {
			$this->remove_recursive( $core_dir );
		}
	}

	/**
	 * Core's own JIT loader builds the candidate file path by simple
	 * concatenation -- `$ret . "{$domain}-{$locale}.mo"`, no separator
	 * inserted -- so the directory this filter returns must already end
	 * with a trailing slash, or that concatenation glues the domain onto
	 * the directory's last path segment instead of a proper file name.
	 */
	public function test_lang_dir_for_domain_result_is_directly_usable_by_cores_own_concatenation(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		$ret = Babilim_Loader::lang_dir_for_domain( false, 'mini', 'de_DE' );

		$this->assertTrue( is_string( $ret ) );
		$this->assertTrue( is_readable( $ret . 'mini-de_DE.mo' ) );
	}

	/**
	 * A domain with only a site-custom override (no babilim/ supplement
	 * at all) must still trigger core's JIT load -- custom/plugins and
	 * custom/themes are probed before plugins/themes, matching
	 * load_translation_file()'s own layering order.
	 */
	public function test_lang_dir_for_domain_substitutes_our_custom_plugins_dir_when_only_a_site_override_exists(): void {
		$this->write_file( 'custom/plugins', 'mini', 'de_DE', 'mo' );

		$result = Babilim_Loader::lang_dir_for_domain( false, 'mini', 'de_DE' );

		$this->assertSame( trailingslashit( Babilim_Paths::dir( 'custom/plugins' ) ), $result );
	}

	public function test_lang_dir_for_domain_prefers_custom_over_babilim_when_path_is_false(): void {
		$this->write_file( 'custom/plugins', 'mini', 'de_DE', 'mo' );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'mo' );

		$result = Babilim_Loader::lang_dir_for_domain( false, 'mini', 'de_DE' );

		$this->assertSame( trailingslashit( Babilim_Paths::dir( 'custom/plugins' ) ), $result );
	}

	public function test_lang_dir_for_domain_returns_path_unchanged_when_nobody_has_the_file(): void {
		$this->assertFalse( Babilim_Loader::lang_dir_for_domain( false, 'mini', 'de_DE' ) );

		$core_dir = BABILIM_TEST_TMP_ROOT . '/babilim-core-dir-' . uniqid( '', true );
		$this->assertSame( $core_dir, Babilim_Loader::lang_dir_for_domain( $core_dir, 'mini', 'de_DE' ) );
	}

	/**
	 * @dataProvider provide_unsafe_domain_or_locale
	 *
	 * The fixture file is named to match exactly what $domain-$locale.mo
	 * would resolve to if the safety guard were skipped -- see the
	 * docblock on the load_translation_file counterpart of this test for
	 * why a mismatched name would prove nothing.
	 */
	public function test_lang_dir_for_domain_returns_path_unchanged_for_an_unsafe_domain_or_locale_with_zero_filesystem_calls( string $domain, string $locale ): void {
		$this->mkdir_type( 'plugins' );
		file_put_contents( Babilim_Paths::dir( 'plugins' ) . "/{$domain}-{$locale}.mo", 'x' );

		$result = Babilim_Loader::lang_dir_for_domain( false, $domain, $locale );

		$this->assertFalse( $result );
		$this->assertSame(
			0,
			Babilim_Loader::fs_probe_count_for_tests(),
			'an unsafe domain/locale must return before making any is_dir()/is_readable() call'
		);
	}

	public function test_lang_dir_for_domain_noops_when_root_does_not_exist(): void {
		$this->assertFalse( is_dir( Babilim_Paths::root() ) );

		$this->assertFalse( Babilim_Loader::lang_dir_for_domain( false, 'mini', 'de_DE' ) );
	}

	public function test_lang_dir_for_domain_accepts_an_l10n_php_file_too(): void {
		$this->write_file( 'plugins', 'mini', 'de_DE', 'l10n.php' );

		$result = Babilim_Loader::lang_dir_for_domain( false, 'mini', 'de_DE' );

		$this->assertSame( trailingslashit( Babilim_Paths::dir( 'plugins' ) ), $result );
	}

	// -- load_default_supplements ---------------------------------------------

	public function test_load_default_supplements_uses_determine_locale_when_no_locale_given(): void {
		$GLOBALS['babilim_test_current_locale'] = 'ja';
		$this->mkdir_type( 'default' );
		file_put_contents( Babilim_Paths::merged_default( 'ja', 'mo' ), 'x' );

		Babilim_Loader::load_default_supplements();

		$this->assertSame( 1, $GLOBALS['babilim_test_determine_locale_calls'] );
		$this->assertCount( 1, $GLOBALS['babilim_test_load_textdomain_calls'] );
		$this->assertSame( 'ja', $GLOBALS['babilim_test_load_textdomain_calls'][0]['locale'] );
	}

	public function test_load_default_supplements_does_not_call_determine_locale_when_a_locale_is_given(): void {
		$this->mkdir_type( 'default' );
		file_put_contents( Babilim_Paths::merged_default( 'ja', 'mo' ), 'x' );

		Babilim_Loader::load_default_supplements( 'ja' );

		$this->assertSame( 0, $GLOBALS['babilim_test_determine_locale_calls'] );
		$this->assertCount( 1, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	public function test_load_default_supplements_reloads_on_every_call_even_for_the_same_locale(): void {
		// No "locale unchanged, skip" guard -- that shortcut was
		// considered and rejected. change_locale must re-run the load
		// twice even when called with the same locale both times.
		$this->mkdir_type( 'default' );
		file_put_contents( Babilim_Paths::merged_default( 'ja', 'mo' ), 'x' );

		Babilim_Loader::load_default_supplements( 'ja' );
		Babilim_Loader::load_default_supplements( 'ja' );

		$this->assertCount( 2, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	public function test_load_default_supplements_loads_exactly_the_merged_index_file(): void {
		$this->mkdir_type( 'default' );
		file_put_contents( Babilim_Paths::merged_default( 'ja', 'mo' ), 'x' );

		Babilim_Loader::load_default_supplements( 'ja' );

		$this->assertCount( 1, $GLOBALS['babilim_test_load_textdomain_calls'] );
		$call = $GLOBALS['babilim_test_load_textdomain_calls'][0];
		$this->assertSame( 'default', $call['domain'] );
		$this->assertSame( Babilim_Paths::merged_default( 'ja', 'mo' ), $call['mofile'] );
		$this->assertSame( 'ja', $call['locale'] );
	}

	public function test_load_default_supplements_falls_back_to_l10n_php_when_mo_is_absent(): void {
		$this->mkdir_type( 'default' );
		file_put_contents( Babilim_Paths::merged_default( 'ja', 'l10n.php' ), '<?php return array();' );

		Babilim_Loader::load_default_supplements( 'ja' );

		$this->assertSame(
			Babilim_Paths::merged_default( 'ja', 'l10n.php' ),
			$GLOBALS['babilim_test_load_textdomain_calls'][0]['mofile']
		);
	}

	/**
	 * When both forms of the merged index exist, exactly one load
	 * happens -- the `.mo` -- never both.
	 */
	public function test_load_default_supplements_loads_only_the_mo_when_both_mo_and_l10n_php_exist(): void {
		$this->mkdir_type( 'default' );
		file_put_contents( Babilim_Paths::merged_default( 'ja', 'mo' ), 'x' );
		file_put_contents( Babilim_Paths::merged_default( 'ja', 'l10n.php' ), '<?php return array();' );

		Babilim_Loader::load_default_supplements( 'ja' );

		$this->assertCount( 1, $GLOBALS['babilim_test_load_textdomain_calls'] );
		$this->assertSame(
			Babilim_Paths::merged_default( 'ja', 'mo' ),
			$GLOBALS['babilim_test_load_textdomain_calls'][0]['mofile']
		);
	}

	/**
	 * Lifecycle red line: a leftover source fragment under
	 * `default/_parts/` must never be loaded, merged index or not. This
	 * is the transitional module's glob-over-fragments bug -- the fix is
	 * simply that the loading layer never looks at `_parts/` at all.
	 */
	public function test_load_default_supplements_ignores_unmerged_parts_when_no_merged_index_exists(): void {
		$this->mkdir_type( 'default/_parts' );
		file_put_contents( Babilim_Paths::file( 'default/_parts', 'a', 'ja', 'mo' ), 'x' );

		Babilim_Loader::load_default_supplements( 'ja' );

		$this->assertCount( 0, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	public function test_load_default_supplements_does_not_crash_on_a_corrupt_index(): void {
		$this->mkdir_type( 'default' );
		file_put_contents( Babilim_Paths::merged_default( 'ja', 'mo' ), 'corrupt' );
		$GLOBALS['babilim_test_load_textdomain_throws'] = true;

		// Must not throw out of this call.
		Babilim_Loader::load_default_supplements( 'ja' );
		$this->assertTrue( true );
	}

	public function test_load_default_supplements_noops_when_root_does_not_exist(): void {
		$this->assertFalse( is_dir( Babilim_Paths::root() ) );

		Babilim_Loader::load_default_supplements( 'ja' );

		$this->assertCount( 0, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	public function test_load_default_supplements_noops_for_an_unsafe_locale(): void {
		$this->mkdir_type( 'default' );

		Babilim_Loader::load_default_supplements( 'ja/evil' );

		$this->assertCount( 0, $GLOBALS['babilim_test_load_textdomain_calls'] );
	}

	// -- load_script_translation_file / load_script_translations (JS) ---------

	public function test_load_script_translation_file_returns_our_file_when_core_has_none(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file( 'plugins', 'mini', 'de_DE', 'json' );

		$result = Babilim_Loader::load_script_translation_file( false, 'mini-script', 'mini' );

		$this->assertSame( Babilim_Paths::file( 'plugins', 'mini', 'de_DE', 'json' ), $result );
	}

	public function test_load_script_translation_file_prefers_custom_over_babilim(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file( 'custom/plugins', 'mini', 'de_DE', 'json' );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'json' );

		// Core has nothing ($file === false) -- substitution applies.
		$result = Babilim_Loader::load_script_translation_file( false, 'mini-script', 'mini' );

		$this->assertSame( Babilim_Paths::file( 'custom/plugins', 'mini', 'de_DE', 'json' ), $result );
	}

	/**
	 * WordPress core's own load_script_textdomain() tries several
	 * candidate paths in turn -- a plugin-supplied $path first, then
	 * WP_LANG_DIR/plugins/{domain}-{locale}-{md5}.json (where a wp.org
	 * JSON language pack lands) -- and stops at the first one this filter
	 * doesn't return as false. Substituting on an unreadable-but-non-false
	 * candidate would pre-empt every later candidate, including core's own
	 * readable file; only $file === false may be substituted.
	 */
	public function test_load_script_translation_file_returns_an_unreadable_non_false_file_unchanged(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file( 'custom/plugins', 'mini', 'de_DE', 'json' );
		$this->write_file( 'plugins', 'mini', 'de_DE', 'json' );

		$unreadable = BABILIM_TEST_TMP_ROOT . '/babilim-does-not-exist-' . uniqid( '', true ) . '.json';

		$result = Babilim_Loader::load_script_translation_file( $unreadable, 'mini-script', 'mini' );

		$this->assertSame( $unreadable, $result, 'an unreadable non-false candidate must be left alone so core tries its next one' );
	}

	/**
	 * Simulates core's own candidate loop: the first candidate this filter
	 * sees is unreadable (must be returned unchanged so core moves on),
	 * the second is core's own readable wp.org JSON pack (must also be
	 * left unchanged, not replaced by ours) -- and our translations still
	 * end up layered on top of it via load_script_translations(), not by
	 * swapping the file out in this filter.
	 */
	public function test_load_script_translation_file_lets_core_try_its_next_candidate_when_an_earlier_one_is_unreadable(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file(
			'plugins',
			'mini',
			'de_DE',
			'json',
			wp_json_encode( array( 'locale_data' => array( 'messages' => array( 'Hi' => array( 'ours' ) ) ) ) )
		);

		$candidate_1 = BABILIM_TEST_TMP_ROOT . '/babilim-core-candidate-1-' . uniqid( '', true ) . '.json';
		$candidate_2 = BABILIM_TEST_TMP_ROOT . '/babilim-core-candidate-2-' . uniqid( '', true ) . '.json';
		$core_json   = wp_json_encode(
			array(
				'locale_data' => array(
					'messages' => array(
						'Hi'  => array( 'core' ),
						'Bye' => array( 'core-bye' ),
					),
				),
			)
		);
		file_put_contents( $candidate_2, $core_json );

		try {
			$filtered_1 = Babilim_Loader::load_script_translation_file( $candidate_1, 'mini-script', 'mini' );
			$this->assertSame( $candidate_1, $filtered_1 );

			$filtered_2 = Babilim_Loader::load_script_translation_file( $candidate_2, 'mini-script', 'mini' );
			$this->assertSame( $candidate_2, $filtered_2 );

			$result  = Babilim_Loader::load_script_translations( $core_json, $candidate_2, 'mini-script', 'mini' );
			$decoded = json_decode( $result, true );

			$this->assertSame( array( 'ours' ), $decoded['locale_data']['messages']['Hi'], 'our translation wins the merge' );
			$this->assertSame( array( 'core-bye' ), $decoded['locale_data']['messages']['Bye'], "core's own string survives" );
		} finally {
			unlink( $candidate_2 );
		}
	}

	/**
	 * Core's own JIT-loaded file must never be replaced when it's
	 * actually there and readable -- our own layer only fills in when
	 * core has nothing ($file === false or unreadable). Layering our
	 * content over a readable core file happens in
	 * load_script_translations(), not by swapping the file out here.
	 */
	public function test_load_script_translation_file_does_not_replace_a_readable_core_file(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file( 'plugins', 'mini', 'de_DE', 'json' );

		$core_file = BABILIM_TEST_TMP_ROOT . '/babilim-core-script-' . uniqid( '', true ) . '.json';
		file_put_contents( $core_file, '{}' );

		try {
			$result = Babilim_Loader::load_script_translation_file( $core_file, 'mini-script', 'mini' );
			$this->assertSame( $core_file, $result );
		} finally {
			unlink( $core_file );
		}
	}

	public function test_load_script_translation_file_returns_original_when_we_have_nothing(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';

		$result = Babilim_Loader::load_script_translation_file( '/core/mini-de_DE-handle.json', 'mini-script', 'mini' );

		$this->assertSame( '/core/mini-de_DE-handle.json', $result );
	}

	/**
	 * WordPress core's own load_script_translations filter is applied to
	 * the raw JSON *string* it read from the script translation file --
	 * not a decoded array -- and the return value is re-encoded and sent
	 * to the browser exactly as returned. Passing anything else (as
	 * earlier tests here did) can't catch a filter that assumes an
	 * array and returns a no-op for a string.
	 */
	public function test_load_script_translations_merges_our_messages_over_cores_keeping_unrelated_keys(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file(
			'plugins',
			'mini',
			'de_DE',
			'json',
			wp_json_encode(
				array(
					'locale_data' => array(
						'messages' => array( 'Hi' => array( 'ours' ) ),
					),
				)
			)
		);

		$core_translations = wp_json_encode(
			array(
				'locale_data' => array(
					'messages' => array(
						'Hi'  => array( 'core' ),
						'Bye' => array( 'core-bye' ),
					),
				),
			)
		);

		$result = Babilim_Loader::load_script_translations( $core_translations, '/core/mini-de_DE-handle.json', 'mini-script', 'mini' );

		$this->assertTrue( is_string( $result ), 'a string input must come back as a re-encoded string' );
		$decoded = json_decode( $result, true );
		$this->assertSame( array( 'ours' ), $decoded['locale_data']['messages']['Hi'] );
		$this->assertSame( array( 'core-bye' ), $decoded['locale_data']['messages']['Bye'] );
	}

	/**
	 * Some Jed catalogs key their messages by the domain itself
	 * (`locale_data.{$domain}`) instead of the more common `messages`
	 * key -- that shape must be merged into as well, not silently
	 * ignored because it isn't named "messages".
	 */
	public function test_load_script_translations_merges_the_domain_keyed_locale_data_shape(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file(
			'plugins',
			'mini',
			'de_DE',
			'json',
			wp_json_encode(
				array(
					'locale_data' => array(
						'messages' => array( 'Hi' => array( 'ours' ) ),
					),
				)
			)
		);

		$core_translations = wp_json_encode(
			array(
				'locale_data' => array(
					'mini' => array(
						'Hi'  => array( 'core' ),
						'Bye' => array( 'core-bye' ),
					),
				),
			)
		);

		$result  = Babilim_Loader::load_script_translations( $core_translations, '/core/mini-de_DE-handle.json', 'mini-script', 'mini' );
		$decoded = json_decode( $result, true );

		$this->assertSame( array( 'ours' ), $decoded['locale_data']['mini']['Hi'] );
		$this->assertSame( array( 'core-bye' ), $decoded['locale_data']['mini']['Bye'] );
	}

	/**
	 * Per-string precedence inside our own layering (before it's ever
	 * merged onto core) must be custom > babilim -- a site's own custom/
	 * override always wins over our babilim/ supplement. This is
	 * layer_messages()'s own merge
	 * order: swapping it survives every other test in this suite because
	 * none of them write colliding custom+babilim files.
	 */
	public function test_load_script_translations_prefers_custom_over_babilim_on_a_colliding_key(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file(
			'custom/plugins',
			'mini',
			'de_DE',
			'json',
			wp_json_encode( array( 'locale_data' => array( 'messages' => array( 'Hi' => array( 'custom' ) ) ) ) )
		);
		$this->write_file(
			'plugins',
			'mini',
			'de_DE',
			'json',
			wp_json_encode( array( 'locale_data' => array( 'messages' => array( 'Hi' => array( 'babilim' ) ) ) ) )
		);

		$core_translations = wp_json_encode( array( 'locale_data' => array( 'messages' => array( 'Hi' => array( 'core' ) ) ) ) );

		$result  = Babilim_Loader::load_script_translations( $core_translations, '/core/mini-de_DE-handle.json', 'mini-script', 'mini' );
		$decoded = json_decode( $result, true );

		$this->assertSame( array( 'custom' ), $decoded['locale_data']['messages']['Hi'] );
	}

	/**
	 * Core's own `""` header entry (domain + plural-forms) must survive
	 * the merge, not be overwritten by ours -- WP's inline script
	 * only ever reads `localeData[""].domain` from core's own load, and
	 * plural-forms is identical for the same locale regardless of source,
	 * but keeping core's header when present is strictly safer than
	 * letting ours win by accident of merge order.
	 */
	public function test_load_script_translations_keeps_cores_header_entry_over_ours(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file(
			'plugins',
			'mini',
			'de_DE',
			'json',
			wp_json_encode(
				array(
					'locale_data' => array(
						'messages' => array(
							''   => array( 'domain' => 'ours-header' ),
							'Hi' => array( 'ours' ),
						),
					),
				)
			)
		);

		$core_translations = wp_json_encode(
			array(
				'locale_data' => array(
					'messages' => array(
						''    => array(
							'domain'        => 'messages',
							'plural-forms'  => 'nplurals=2; plural=(n != 1);',
						),
						'Bye' => array( 'core-bye' ),
					),
				),
			)
		);

		$result  = Babilim_Loader::load_script_translations( $core_translations, '/core/mini-de_DE-handle.json', 'mini-script', 'mini' );
		$decoded = json_decode( $result, true );

		$this->assertSame(
			array(
				'domain'       => 'messages',
				'plural-forms' => 'nplurals=2; plural=(n != 1);',
			),
			$decoded['locale_data']['messages'][''],
			"core's own header entry must survive, not ours"
		);
		$this->assertSame( array( 'ours' ), $decoded['locale_data']['messages']['Hi'] );
	}

	/**
	 * When core's catalog has no `""` header at all, ours is still the
	 * only one available and must be used -- the rule above only says
	 * core's header wins *when core has one*, not that ours is dropped
	 * unconditionally.
	 */
	public function test_load_script_translations_uses_our_header_when_core_has_none(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file(
			'plugins',
			'mini',
			'de_DE',
			'json',
			wp_json_encode(
				array(
					'locale_data' => array(
						'messages' => array(
							'' => array( 'domain' => 'ours-header' ),
						),
					),
				)
			)
		);

		$core_translations = wp_json_encode( array( 'locale_data' => array( 'messages' => array( 'Bye' => array( 'core-bye' ) ) ) ) );

		$result  = Babilim_Loader::load_script_translations( $core_translations, '/core/mini-de_DE-handle.json', 'mini-script', 'mini' );
		$decoded = json_decode( $result, true );

		$this->assertSame( array( 'domain' => 'ours-header' ), $decoded['locale_data']['messages'][''] );
	}

	public function test_load_script_translations_returns_input_unchanged_when_we_have_nothing(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$core_translations                      = wp_json_encode( array( 'locale_data' => array( 'messages' => array( 'Hi' => array( 'core' ) ) ) ) );

		$result = Babilim_Loader::load_script_translations( $core_translations, '/core/mini-de_DE-handle.json', 'mini-script', 'mini' );

		$this->assertSame( $core_translations, $result );
	}

	public function test_load_script_translations_returns_input_unchanged_for_malformed_json(): void {
		$GLOBALS['babilim_test_current_locale'] = 'de_DE';
		$this->write_file(
			'plugins',
			'mini',
			'de_DE',
			'json',
			wp_json_encode( array( 'locale_data' => array( 'messages' => array( 'Hi' => array( 'ours' ) ) ) ) )
		);
		$malformed = 'not json';

		$result = Babilim_Loader::load_script_translations( $malformed, '/core/mini-de_DE-handle.json', 'mini-script', 'mini' );

		$this->assertSame( $malformed, $result );
	}

	// -- log_error / WP_DEBUG gating -------------------------------------------

	/**
	 * A subprocess probe (like the BABILIM_API_BASE tests in
	 * test-bootstrap.php): WP_DEBUG is a constant, so within a single
	 * PHP process it can only ever be defined once -- proving both the
	 * "on" and "off" behaviour needs two separate processes. Triggers
	 * log_error() by making load_textdomain() throw for a layer file
	 * that genuinely exists, and captures whatever real error_log()
	 * wrote via a redirected `error_log` ini setting.
	 *
	 * @param bool|null $wp_debug true/false to define WP_DEBUG, or null to leave it undefined.
	 * @return string Whatever landed in the redirected error log (possibly empty).
	 */
	private function run_wp_debug_probe( ?bool $wp_debug ): string {
		$paths_file  = dirname( __DIR__, 2 ) . '/includes/class-babilim-paths.php';
		$loader_file = dirname( __DIR__, 2 ) . '/includes/class-babilim-loader.php';
		$lang_dir    = BABILIM_TEST_TMP_ROOT . '/babilim-wp-debug-probe-' . uniqid( '', true ) . '/';
		$log_file    = tempnam( BABILIM_TEST_TMP_ROOT, 'babilim-wp-debug-probe-log-' );
		$this->assertNotFalse( $log_file );

		$wp_debug_line = null === $wp_debug ? '' : "define( 'WP_DEBUG', " . var_export( $wp_debug, true ) . ' );';

		$probe = '<?php'
			. "\ndefine( 'ABSPATH', " . var_export( ABSPATH, true ) . ' );'
			. "\ndefine( 'WP_LANG_DIR', " . var_export( $lang_dir, true ) . ' );'
			. "\nmkdir( WP_LANG_DIR . 'babilim/plugins', 0777, true );"
			. "\nfile_put_contents( WP_LANG_DIR . 'babilim/plugins/mini-de_DE.mo', 'x' );"
			. "\nfunction trailingslashit( \$s ) { return rtrim( (string) \$s, '/\\\\' ) . '/'; }"
			. "\nfunction load_textdomain( \$domain, \$file, \$locale = null ) { throw new RuntimeException( 'boom' ); }"
			. "\n{$wp_debug_line}"
			. "\nrequire " . var_export( $paths_file, true ) . ';'
			. "\nrequire " . var_export( $loader_file, true ) . ';'
			. "\nini_set( 'error_log', " . var_export( $log_file, true ) . ' );'
			. "\nini_set( 'log_errors', '1' );"
			. "\nBabilim_Loader::load_translation_file( '/wp/x.mo', 'mini', 'de_DE' );";

		$probe_file = tempnam( BABILIM_TEST_TMP_ROOT, 'babilim-wp-debug-probe-' );
		$this->assertNotFalse( $probe_file );
		rename( $probe_file, $probe_file . '.php' );
		$probe_file .= '.php';
		file_put_contents( $probe_file, $probe );

		shell_exec(
			escapeshellarg( PHP_BINARY )
			. ' -d display_errors=0 -d log_errors=0'
			. ' ' . escapeshellarg( $probe_file )
			. ' 2>&1'
		);

		$log = file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';

		unlink( $probe_file );
		@unlink( $log_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$this->remove_recursive( rtrim( $lang_dir, '/' ) );

		return $log;
	}

	public function test_log_error_is_silent_when_wp_debug_is_undefined(): void {
		$log = $this->run_wp_debug_probe( null );

		$this->assertSame( '', trim( $log ) );
	}

	public function test_log_error_is_silent_when_wp_debug_is_false(): void {
		$log = $this->run_wp_debug_probe( false );

		$this->assertSame( '', trim( $log ) );
	}

	public function test_log_error_writes_to_the_error_log_when_wp_debug_is_true(): void {
		$log = $this->run_wp_debug_probe( true );

		$this->assertStringContainsString( '[babilim] boom', $log );
	}
}
