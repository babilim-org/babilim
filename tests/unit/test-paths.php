<?php
/**
 * Tests for Babilim_Paths -- path construction and the is_allowed()/
 * is_safe_name()/is_safe_locale() guards.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Paths extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	public function test_root_is_under_wp_lang_dir_with_no_trailing_slash(): void {
		$this->assertSame( rtrim( WP_LANG_DIR, '/' ) . '/babilim', Babilim_Paths::root() );
	}

	/**
	 * @dataProvider provide_dir_types
	 */
	public function test_dir_builds_expected_subdirectory( string $type ): void {
		$this->assertSame( Babilim_Paths::root() . '/' . $type, Babilim_Paths::dir( $type ) );
	}

	public function provide_dir_types(): array {
		return array(
			array( 'plugins' ),
			array( 'themes' ),
			array( 'default' ),
			array( 'default/_parts' ),
			array( 'custom/plugins' ),
			array( 'custom/themes' ),
			array( '_versions' ),
		);
	}

	public function test_dir_rejects_an_unknown_type(): void {
		$this->expectException( InvalidArgumentException::class );
		Babilim_Paths::dir( 'not-a-real-type' );
	}

	public function test_file_builds_the_expected_shape(): void {
		$this->assertSame(
			Babilim_Paths::root() . '/plugins/woocommerce-zh_TW.l10n.php',
			Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' )
		);
	}

	public function test_file_with_default_parts_type_uses_slug_as_name(): void {
		$this->assertSame(
			Babilim_Paths::root() . '/default/_parts/some-plugin-de_DE.po',
			Babilim_Paths::file( 'default/_parts', 'some-plugin', 'de_DE', 'po' )
		);
	}

	public function test_merged_default_shape_for_mo(): void {
		$this->assertSame(
			Babilim_Paths::root() . '/default/_merged-de_DE.mo',
			Babilim_Paths::merged_default( 'de_DE', 'mo' )
		);
	}

	public function test_merged_default_shape_for_l10n_php(): void {
		$this->assertSame(
			Babilim_Paths::root() . '/default/_merged-de_DE.l10n.php',
			Babilim_Paths::merged_default( 'de_DE', 'l10n.php' )
		);
	}

	public function test_version_dir_shape_for_a_plugin(): void {
		$this->assertSame(
			Babilim_Paths::root() . '/_versions/plugins/woocommerce/zh_TW/bd_5e2a9c1f3d70',
			Babilim_Paths::version_dir( 'plugins', 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70' )
		);
	}

	public function test_version_dir_shape_for_default_nests_under_default(): void {
		$this->assertSame(
			Babilim_Paths::root() . '/_versions/default/some-plugin/de_DE/bd_5e2a9c1f3d70',
			Babilim_Paths::version_dir( 'default', 'some-plugin', 'de_DE', 'bd_5e2a9c1f3d70' )
		);
	}

	public function test_default_index_staging_dir_uses_a_fixed_build_id(): void {
		$this->assertSame(
			Babilim_Paths::root() . '/_versions/default/_merge_scratch/zh_TW/bd_000000000000',
			Babilim_Paths::default_index_staging_dir( 'zh_TW' )
		);
	}

	public function test_default_index_staging_dir_is_stable_across_calls(): void {
		$this->assertSame(
			Babilim_Paths::default_index_staging_dir( 'zh_TW' ),
			Babilim_Paths::default_index_staging_dir( 'zh_TW' )
		);
	}

	public function test_default_index_staging_dir_files_are_allowed(): void {
		$dir = Babilim_Paths::default_index_staging_dir( 'zh_TW' );
		$this->assertTrue( Babilim_Paths::is_allowed( $dir ) );
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/_merged-zh_TW.mo' ) );
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/_merged-zh_TW.l10n.php' ) );
	}

	public function test_is_allowed_accepts_ordinary_files_under_root(): void {
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'po' ) ) );
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'json' ) ) );
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' ) ) );
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/.htaccess' ) );
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/index.php' ) );
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::dir( 'plugins' ) ) );
	}

	public function test_is_allowed_accepts_every_known_directory(): void {
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::root() ) );
		foreach ( $this->provide_dir_types() as $case ) {
			$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::dir( $case[0] ) ), "expected dir({$case[0]}) to be allowed" );
		}
		// The implicit parent of the two custom/* dir types, needed for
		// mkdir()'s stepwise walk.
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/custom' ) );
		// Arbitrarily deep, dynamically-named directories are only ever
		// legitimate under _versions/ (see version_dir()).
		$this->assertTrue(
			Babilim_Paths::is_allowed(
				Babilim_Paths::version_dir( 'plugins', 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70' )
			)
		);
	}

	public function test_is_allowed_rejects_an_extensionless_file(): void {
		// A dotless basename is only ever a known directory reference
		// (see test_is_allowed_accepts_every_known_directory) -- an
		// arbitrary extensionless "file" must not slip through as one.
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::dir( 'plugins' ) . '/noext' ) );
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/noext' ) );
	}

	public function test_is_allowed_rejects_an_extensionless_file_below_a_version_dir(): void {
		// version_dir() -- _versions/{kind}/{domain}/{locale}/{build_id} --
		// is the deepest extensionless path the layout ever creates. A
		// file placed one level deeper than that (inside the build_id
		// directory) must still go through the ordinary extension check,
		// never be waved through as "somewhere under _versions/".
		$version_dir = Babilim_Paths::version_dir( 'plugins', 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70' );
		$this->assertFalse( Babilim_Paths::is_allowed( $version_dir . '/noext' ) );
	}

	/**
	 * @dataProvider provide_version_dir_ancestor_depths
	 */
	public function test_is_allowed_accepts_extensionless_ancestors_of_a_version_dir( int $segments ): void {
		// mkdir()'s stepwise walk (ancestors_from_root()) needs every
		// ancestor of a version_dir() -- not just the leaf -- to be a
		// known directory, all the way down from _versions/ itself.
		$parts = array( 'plugins', 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70' );
		$path  = Babilim_Paths::root() . '/_versions/' . implode( '/', array_slice( $parts, 0, $segments ) );
		$this->assertTrue( Babilim_Paths::is_allowed( $path ), "expected {$path} to be an allowed known directory" );
	}

	public function provide_version_dir_ancestor_depths(): array {
		return array(
			'kind'               => array( 1 ),
			'kind/domain'        => array( 2 ),
			'kind/domain/locale' => array( 3 ),
			'full version_dir'   => array( 4 ),
		);
	}

	public function test_is_allowed_accepts_the_default_kind_under_versions(): void {
		$this->assertTrue(
			Babilim_Paths::is_allowed(
				Babilim_Paths::version_dir( 'default', 'some-plugin', 'de_DE', 'bd_5e2a9c1f3d70' )
			)
		);
	}

	/**
	 * is_known_directory()'s _versions/ rule isn't just a depth count --
	 * each present segment is checked against the exact rule
	 * version_dir() itself builds it from. These reject a bad segment at
	 * every position, not only a path that's too deep.
	 */
	public function test_is_allowed_rejects_an_unknown_kind_segment_under_versions(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/_versions/addons' ) );
	}

	/**
	 * `_adopt/` was once a staging namespace for a removed install path;
	 * it must not be writable, neither as a directory nor as a file or
	 * a token directory beneath it.
	 */
	public function test_is_allowed_rejects_the_retired_adopt_staging_area(): void {
		$root = Babilim_Paths::root();
		$this->assertFalse( Babilim_Paths::is_allowed( $root . '/_adopt' ) );
		$this->assertFalse( Babilim_Paths::is_allowed( $root . '/_adopt/token' ) );
		$this->assertFalse( Babilim_Paths::is_allowed( $root . '/_adopt/token/woocommerce-zh_TW.mo' ) );
		$this->assertFalse( Babilim_Paths::is_allowed( $root . '/_adopt/woocommerce-zh_TW.mo' ) );
	}

	public function test_is_allowed_rejects_an_unsafe_domain_segment_under_versions(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/_versions/plugins/has space' ) );
	}

	public function test_is_allowed_rejects_an_unsafe_locale_segment_under_versions(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/_versions/plugins/woocommerce/zh TW' ) );
	}

	public function test_is_allowed_rejects_a_malformed_build_id_segment_under_versions(): void {
		$this->assertFalse(
			Babilim_Paths::is_allowed( Babilim_Paths::root() . '/_versions/plugins/woocommerce/zh_TW/not-a-build-id' )
		);
	}

	public function test_is_allowed_rejects_when_root_itself_is_a_symlink(): void {
		$this->remove_recursive_helper( Babilim_Paths::root() );

		$outside = BABILIM_TEST_TMP_ROOT . '/babilim-root-symlink-outside-' . uniqid( '', true );
		mkdir( $outside, 0777, true );
		symlink( $outside, Babilim_Paths::root() );

		try {
			$this->assertFalse(
				Babilim_Paths::is_allowed( Babilim_Paths::file( 'plugins', 'evil', 'en_US', 'mo' ) )
			);
		} finally {
			unlink( Babilim_Paths::root() );
			$this->remove_recursive_helper( $outside );
		}
	}

	public function test_is_allowed_rejects_a_dot_dot_traversal(): void {
		$this->assertFalse(
			Babilim_Paths::is_allowed( Babilim_Paths::root() . '/plugins/../../../etc/passwd' )
		);
	}

	public function test_is_allowed_rejects_a_path_outside_root(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( '/etc/passwd' ) );
		$this->assertFalse( Babilim_Paths::is_allowed( dirname( Babilim_Paths::root() ) . '/not-babilim/x.mo' ) );
	}

	public function test_is_allowed_rejects_a_sibling_directory_with_a_similar_prefix(): void {
		// A naive `strpos($path, $root) === 0` check would let this
		// through -- the boundary after the root prefix must be a `/`
		// (or nothing at all), not merely a shared string prefix. Uses a
		// bare directory-shaped path (no extension) so this is isolated
		// from the separate "under a layout dir" extension check, which
		// would otherwise also reject a file path here for its own
		// reason and mask a broken boundary check.
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '-evil/plugins' ) );
	}

	public function test_is_allowed_rejects_a_bare_dot_segment(): void {
		// A directory-shaped target (no extension) so this is isolated
		// from the extension/layout-dir checks, which would otherwise
		// also reject a file path here (its literal string no longer
		// matches a layout dir's prefix) and mask a missing '.' check.
		$this->assertFalse(
			Babilim_Paths::is_allowed( Babilim_Paths::root() . '/./plugins' )
		);
	}

	public function test_is_allowed_rejects_a_nul_byte(): void {
		$this->assertFalse(
			Babilim_Paths::is_allowed( Babilim_Paths::root() . "/plugins/a\0b.mo" )
		);
	}

	public function test_is_allowed_rejects_a_disallowed_extension(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/plugins/evil.phtml' ) );
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/plugins/evil.php' ) );
	}

	/**
	 * $for_write = false (reading/removing) must accept ANY extension
	 * under `_incoming/{token}/` -- that area also holds whatever
	 * a foreign process or an interrupted run leaves behind (never through this plugin's own write path), so a sweep
	 * or a cleanup must be able to see/remove it regardless of what it
	 * is. See is_within_incoming()'s own docblock and is_allowed()'s own
	 * `$for_write` parameter.
	 */
	public function test_is_allowed_accepts_an_arbitrary_extension_under_incoming_when_not_writing(): void {
		$dir = Babilim_Paths::incoming_dir( 'bd_5e2a9c1f3d70' );
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/readme.txt', false ) );
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/languages/plugin-zh_TW.mo', false ) );
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/some-file-with-no-recognized-extension', false ) );
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/evil.php', false ), '.php must still be readable/removable, never just refused outright' );
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/nested/deeper/evil.php', false ) );
	}

	/**
	 * $for_write = true (the default) is an allow-list, not a denylist:
	 * only what this plugin's own write path genuinely produces under
	 * `_incoming/{token}/` -- see INCOMING_WRITE_ALLOWED_EXTENSIONS's
	 * own docblock for the two enumerated write call sites this comes
	 * from. Every one of the arbitrary/legitimate-looking extensions
	 * accepted above, and every dangerous shape below (a server-config
	 * file, a double extension, a trailing dot/space, a case variant)
	 * must be refused as a write.
	 *
	 * @dataProvider provide_disallowed_incoming_write_names
	 */
	public function test_is_allowed_rejects_every_disallowed_name_as_a_write_under_incoming( string $basename ): void {
		$dir = Babilim_Paths::incoming_dir( 'bd_5e2a9c1f3d70' );
		$this->assertFalse(
			Babilim_Paths::is_allowed( $dir . '/' . $basename ),
			"expected \"{$basename}\" under _incoming/ to be rejected as a write"
		);
	}

	public function provide_disallowed_incoming_write_names(): array {
		return array(
			'plain .php'                   => array( 'evil.php' ),
			'.php3'                         => array( 'evil.php3' ),
			'.php4'                         => array( 'evil.php4' ),
			'.php5'                         => array( 'evil.php5' ),
			'.php7'                         => array( 'evil.php7' ),
			'.phtml'                        => array( 'evil.phtml' ),
			'.phar'                         => array( 'evil.phar' ),
			'.pht'                          => array( 'evil.pht' ),
			'uppercase .PHP'                => array( 'evil.PHP' ),
			'mixed-case .PhTmL'             => array( 'evil.PhTmL' ),
			'.htaccess'                     => array( '.htaccess' ),
			'.user.ini'                     => array( '.user.ini' ),
			'.phps'                         => array( 'evil.phps' ),
			'.shtml'                        => array( 'evil.shtml' ),
			'.cgi'                          => array( 'evil.cgi' ),
			'double extension x.php.txt'    => array( 'x.php.txt' ),
			'trailing dot x.php.'           => array( 'x.php.' ),
			'trailing space x.php '         => array( 'x.php ' ),
			'readme.txt (not an allowed write extension)' => array( 'readme.txt' ),
			'a .mo file (served extension, not an incoming-write one)' => array( 'plugin-zh_TW.mo' ),
		);
	}

	/**
	 * The exact shapes this plugin's own write path DOES produce under
	 * `_incoming/{token}/` must still succeed as a write --
	 * INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock enumerates both.
	 */
	public function test_is_allowed_accepts_every_legitimate_incoming_write(): void {
		$dir = Babilim_Paths::incoming_dir( 'bd_5e2a9c1f3d70' );
		// Babilim_Sync_Installer::install_build(): mkdir()s the bare token
		// directory itself -- no extension.
		$this->assertTrue( Babilim_Paths::is_allowed( $dir ), 'the bare token directory (mkdir()) must be an allowed write' );
		// Babilim_Api_Client::fetch_package(), called from
		// admin/class-babilim-admin.php's do_download().
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/package.zip' ), 'package.zip must be an allowed write' );
		// Case-insensitivity, the same as every other extension check in
		// this class.
		$this->assertTrue( Babilim_Paths::is_allowed( $dir . '/package.ZIP' ) );
	}

	public function test_is_allowed_accepts_the_manifest_file_and_its_temp_and_quarantine_variants(): void {
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::manifest_file() ) );
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::manifest_temp_file() ) );
		$this->assertTrue( Babilim_Paths::is_allowed( Babilim_Paths::manifest_quarantine_file( '0123456789abcdef' ) ) );
	}

	public function test_is_allowed_rejects_a_manifest_json_php_suffix(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/_manifest.json.php' ) );
	}

	public function test_is_allowed_rejects_a_file_inside_a_manifest_json_x_subdirectory(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/_manifest.json.x/evil.php' ) );
	}

	public function test_is_allowed_rejects_htaccess_inside_a_manifest_json_x_subdirectory(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/_manifest.json.x/.htaccess' ) );
	}

	public function test_is_allowed_rejects_a_manifest_variant_name_reused_outside_root(): void {
		// The basename alone matches MANIFEST_VARIANT_PATTERN -- this is
		// only allowed directly at root(), not reused as a filename
		// elsewhere (e.g. inside a layout directory).
		$this->assertFalse(
			Babilim_Paths::is_allowed( Babilim_Paths::dir( 'plugins' ) . '/_manifest.json.tmp-0123456789abcdef' )
		);
	}

	/**
	 * @dataProvider provide_invalid_manifest_variant_names
	 */
	public function test_is_allowed_rejects_manifest_variant_names_with_wrong_length_or_non_hex( string $basename ): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/' . $basename ) );
	}

	public function provide_invalid_manifest_variant_names(): array {
		return array(
			'too short'        => array( '_manifest.json.tmp-abc123' ),
			'too long'         => array( '_manifest.json.tmp-0123456789abcdef00' ),
			'non-hex'          => array( '_manifest.json.tmp-0123456789abcdeg' ),
			'uppercase hex'     => array( '_manifest.json.tmp-0123456789ABCDEF' ),
			'corrupt too short' => array( '_manifest.json.corrupt-1234567890' ),
		);
	}

	public function test_is_allowed_rejects_an_arbitrary_json_file_directly_at_root(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/other.json' ) );
	}

	public function test_is_allowed_rejects_index_php_and_htaccess_outside_root(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::dir( 'plugins' ) . '/index.php' ) );
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::dir( 'plugins' ) . '/.htaccess' ) );
	}

	public function test_is_allowed_rejects_a_mo_file_not_under_any_layout_directory(): void {
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/loose-zh_TW.mo' ) );
	}

	public function test_is_safe_name_accepts_lowercase_uppercase_digits_underscore_hyphen(): void {
		$this->assertTrue( Babilim_Paths::is_safe_name( 'WooCommerce_theme-2' ) );
	}

	/**
	 * @dataProvider provide_unsafe_names
	 */
	public function test_is_safe_name_rejects_anything_else( string $name ): void {
		$this->assertFalse( Babilim_Paths::is_safe_name( $name ) );
	}

	public function provide_unsafe_names(): array {
		return array(
			array( '../etc' ),
			array( 'has space' ),
			array( 'has.dot' ),
			array( 'has/slash' ),
			array( '' ),
			array( "woocommerce\n" ),
		);
	}

	public function test_is_safe_locale_accepts_locale_shaped_strings(): void {
		$this->assertTrue( Babilim_Paths::is_safe_locale( 'zh_TW' ) );
		$this->assertTrue( Babilim_Paths::is_safe_locale( 'de_DE@informal' ) );
	}

	/**
	 * @dataProvider provide_unsafe_locales
	 */
	public function test_is_safe_locale_rejects_anything_else( string $locale ): void {
		$this->assertFalse( Babilim_Paths::is_safe_locale( $locale ) );
	}

	public function provide_unsafe_locales(): array {
		return array(
			array( '../etc' ),
			array( 'zh TW' ),
			array( 'zh.99' ),
			array( 'zh/TW' ),
			array( '' ),
			array( "ja\n" ),
		);
	}

	public function test_ensure_layout_creates_every_directory_and_the_protective_files(): void {
		$fs = new Babilim_Memory_Fs();
		Babilim_Paths::ensure_layout( $fs );

		foreach ( $this->provide_dir_types() as $case ) {
			$this->assertTrue( $fs->exists( Babilim_Paths::dir( $case[0] ) ), "expected {$case[0]} to exist" );
		}
		$this->assertTrue( $fs->exists( Babilim_Paths::root() . '/index.php' ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::root() . '/.htaccess' ) );
	}

	public function test_ensure_layout_does_not_overwrite_an_existing_index_php(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::root() . '/index.php', 'custom-marker' );

		Babilim_Paths::ensure_layout( $fs );

		$this->assertSame( 'custom-marker', $fs->get( Babilim_Paths::root() . '/index.php' ) );
	}

	public function test_ensure_layout_htaccess_covers_apache_2_2_and_2_4(): void {
		$fs = new Babilim_Memory_Fs();
		Babilim_Paths::ensure_layout( $fs );

		$htaccess = (string) $fs->get( Babilim_Paths::root() . '/.htaccess' );

		$this->assertStringContainsString( 'mod_authz_core.c', $htaccess );
		$this->assertStringContainsString( 'Require all denied', $htaccess );
		$this->assertStringContainsString( 'Order allow,deny', $htaccess );
		$this->assertStringContainsString( 'Deny from all', $htaccess );
	}

	public function test_ancestors_from_root_never_includes_anything_above_root(): void {
		$target    = Babilim_Paths::version_dir( 'plugins', 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70' ) . '/woocommerce-zh_TW.mo';
		$ancestors = Babilim_Paths::ancestors_from_root( $target );

		$this->assertNotEmpty( $ancestors );
		$this->assertSame( Babilim_Paths::root(), $ancestors[0] );
		foreach ( $ancestors as $ancestor ) {
			$this->assertTrue(
				$ancestor === Babilim_Paths::root() || 0 === strpos( $ancestor, Babilim_Paths::root() . '/' ),
				"ancestor {$ancestor} must be at or under root()"
			);
		}
	}

	public function test_is_allowed_rejects_a_path_through_a_symlinked_layout_directory(): void {
		$fs = new Babilim_Wp_Fs();
		$fs->mkdir( Babilim_Paths::root() );

		$outside = BABILIM_TEST_TMP_ROOT . '/babilim-symlink-outside-' . uniqid( '', true );
		mkdir( $outside, 0777, true );
		$link = Babilim_Paths::dir( 'plugins' );
		// root() is a real, shared disk fixture (tests/bootstrap.php's
		// own WP_LANG_DIR) that plenty of other tests write real files
		// under -- symlink() fails outright ("File exists") if $link is
		// already a real directory left over from one of them, which
		// (depending on random test order) it sometimes is. Cleared
		// first so this test never depends on what ran before it.
		$this->remove_recursive_helper( $link );
		symlink( $outside, $link );

		try {
			$this->assertFalse(
				Babilim_Paths::is_allowed( Babilim_Paths::file( 'plugins', 'evil', 'en_US', 'mo' ) )
			);
		} finally {
			unlink( $link );
			$this->remove_recursive_helper( $outside );
			$this->remove_recursive_helper( Babilim_Paths::root() );
		}
	}

	public function test_is_allowed_rejects_a_symlink_even_when_its_target_is_still_under_root(): void {
		// A symlink is refused outright, wherever it points -- not only
		// when it would escape root(). 'plugins' here is a symlink to
		// the sibling 'themes' directory, both still under root(); the
		// realpath()-boundary check alone would let this through (the
		// resolved path is still inside root), so this isolates the
		// separate is_link() ancestor check.
		$fs = new Babilim_Wp_Fs();
		$fs->mkdir( Babilim_Paths::dir( 'themes' ) );

		$link = Babilim_Paths::dir( 'plugins' );
		// See the sibling test above's own comment -- $link may already
		// be a real directory left over from another test.
		$this->remove_recursive_helper( $link );
		symlink( Babilim_Paths::dir( 'themes' ), $link );

		try {
			$this->assertFalse(
				Babilim_Paths::is_allowed( Babilim_Paths::file( 'plugins', 'evil', 'en_US', 'mo' ) )
			);
		} finally {
			unlink( $link );
			$this->remove_recursive_helper( Babilim_Paths::root() );
		}
	}

	private function remove_recursive_helper( string $dir ): void {
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
			if ( is_link( $path ) ) {
				unlink( $path );
				continue;
			}
			is_dir( $path ) ? $this->remove_recursive_helper( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function test_the_install_lock_file_may_be_read_and_removed_but_never_written_through_the_helper(): void {
		$lock = Babilim_Paths::root() . '/.install.lock';

		$this->assertTrue( Babilim_Paths::is_allowed( $lock, false ) );
		$this->assertFalse( Babilim_Paths::is_allowed( $lock, true ) );
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/plugins/.install.lock', false ), 'only at the root' );
		$this->assertFalse( Babilim_Paths::is_allowed( Babilim_Paths::root() . '/.other.lock', false ) );
	}
}
