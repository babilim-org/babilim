<?php
/**
 * Babilim_Packager: packs a component's real directory into a zip in a
 * private staging directory outside the site. Only the component's own
 * files, never a symlink target, never anything outside the plugin/theme
 * root; staging is removed after use and swept daily.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Packager extends TestCase {

	private string $root;

	protected function setUp(): void {
		babilim_test_reset();
		$this->root = BABILIM_TEST_TMP_ROOT . '/packager-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/plugins/acme/inc', 0777, true );
		mkdir( $this->root . '/themes/avada/inc', 0777, true );
		mkdir( $this->root . '/outside', 0777, true );
		file_put_contents( $this->root . '/plugins/acme/acme.php', "<?php\n/**\n * Plugin Name: Acme\n * Version: 1.0\n */\n" );
		file_put_contents( $this->root . '/plugins/acme/inc/a.php', '<?php' );
		file_put_contents( $this->root . '/plugins/single.php', "<?php\n/**\n * Plugin Name: Single\n */\n" );
		file_put_contents( $this->root . '/themes/avada/style.css', "/*\nTheme Name: Avada\n*/" );
		file_put_contents( $this->root . '/themes/avada/inc/t.php', '<?php' );
		file_put_contents( $this->root . '/outside/secret.txt', 'nope' );
	}

	protected function tearDown(): void {
		babilim_test_rmtree( $this->root );
	}

	private function packager( ?string $sys_tmp = null, ?string $wp_tmp = null, ?callable $theme_dir = null, ?callable $zip_factory = null ): Babilim_Packager {
		$sys_tmp = $sys_tmp ?? $this->root . '/systmp';
		$wp_tmp  = $wp_tmp ?? $this->root . '/wptmp';
		@mkdir( $sys_tmp, 0777, true );
		@mkdir( $wp_tmp, 0777, true );
		return new Babilim_Packager(
			static function () use ( $sys_tmp ) { return $sys_tmp; },
			static function () use ( $wp_tmp ) { return trailingslashit( $wp_tmp ); },
			static function () { return str_repeat( 'ab', 16 ); },
			$this->root . '/plugins',
			$this->root . '/themes',
			$theme_dir ?? static function () { return null; },
			$zip_factory
		);
	}

	private function names( string $zip ): array {
		$z = new ZipArchive();
		$this->assertTrue( $z->open( $zip ) );
		$names = array();
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$names[] = $z->getNameIndex( $i );
		}
		$z->close();
		sort( $names );
		return $names;
	}

	public function test_packs_a_plugin_directory_with_its_dir_name_as_prefix(): void {
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( array( 'acme/acme.php', 'acme/inc/a.php' ), $this->names( $r->get( 'zip' ) ) );
		$this->assertSame( filesize( $r->get( 'zip' ) ), $r->get( 'size' ) );
		$this->assertStringStartsWith( $this->root . '/systmp/babilim-upload-abababab', $r->get( 'zip' ) );
		$this->assertFileExists( dirname( $r->get( 'zip' ) ) . '/.htaccess' );
		$this->assertFileExists( dirname( $r->get( 'zip' ) ) . '/index.php' );
	}

	public function test_single_file_plugin_packs_only_that_file(): void {
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'single', 'main_file' => 'single.php' ) );
		$this->assertTrue( $r->is_ok() );
		$this->assertSame( array( 'single.php' ), $this->names( $r->get( 'zip' ) ) );
	}

	public function test_packs_a_theme_by_stylesheet(): void {
		$r = $this->packager()->pack( array( 'kind' => 'theme', 'slug' => 'avada', 'stylesheet' => 'avada' ) );
		$this->assertTrue( $r->is_ok() );
		$this->assertSame( array( 'avada/inc/t.php', 'avada/style.css' ), $this->names( $r->get( 'zip' ) ) );
	}

	public function test_symlinks_are_neither_followed_nor_included(): void {
		symlink( $this->root . '/outside', $this->root . '/plugins/acme/linkdir' );
		symlink( $this->root . '/outside/secret.txt', $this->root . '/plugins/acme/link.txt' );
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$this->assertTrue( $r->is_ok() );
		$this->assertSame( array( 'acme/acme.php', 'acme/inc/a.php' ), $this->names( $r->get( 'zip' ) ) );
		$this->assertStringNotContainsString( 'nope', (string) file_get_contents( $r->get( 'zip' ) ) );
	}

	public function test_main_file_escaping_the_plugin_root_is_refused(): void {
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'x', 'main_file' => '../outside/secret.txt' ) );
		$this->assertSame( 'packager_outside_root', $r->code() );
		$r = $this->packager()->pack( array( 'kind' => 'theme', 'slug' => 'x', 'stylesheet' => '../outside' ) );
		$this->assertSame( 'packager_outside_root', $r->code() );
	}

	public function test_missing_main_file_and_unknown_kind_are_refused(): void {
		$this->assertSame( 'packager_no_main_file', $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'x', 'main_file' => 'nope/nope.php' ) )->code() );
		$this->assertSame( 'packager_unsupported_kind', $this->packager()->pack( array( 'kind' => 'mu', 'slug' => 'x' ) )->code() );
	}

	public function test_unreadable_file_fails_the_whole_pack_and_leaves_no_staging(): void {
		chmod( $this->root . '/plugins/acme/inc/a.php', 0000 );
		if ( is_readable( $this->root . '/plugins/acme/inc/a.php' ) ) {
			chmod( $this->root . '/plugins/acme/inc/a.php', 0644 );
			$this->markTestSkipped( 'running as root; chmod 000 is still readable' );
		}
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		chmod( $this->root . '/plugins/acme/inc/a.php', 0644 );
		$this->assertSame( 'packager_read_failed', $r->code() );
		$this->assertSame( array(), glob( $this->root . '/systmp/babilim-upload-*' ) );
	}

	public function test_system_temp_under_abspath_or_content_dir_falls_back_to_wp_temp(): void {
		$under_abspath = rtrim( ABSPATH, '/' ) . '/tmp-' . bin2hex( random_bytes( 2 ) );
		$r = $this->packager( $under_abspath )->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$this->assertTrue( $r->is_ok() );
		$this->assertStringStartsWith( $this->root . '/wptmp/babilim-upload-', $r->get( 'zip' ) );
		babilim_test_rmtree( $under_abspath );
	}

	public function test_discard_removes_only_an_owned_directory(): void {
		$p = $this->packager();
		$r = $p->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$dir = $r->get( 'dir' );
		$p->discard( $dir );
		$this->assertDirectoryDoesNotExist( $dir );
		$p->discard( $this->root . '/outside' );
		$this->assertFileExists( $this->root . '/outside/secret.txt' );
	}

	public function test_sweep_removes_stale_staging_dirs_only(): void {
		$p = $this->packager();
		$old = $this->root . '/wptmp/babilim-upload-' . str_repeat( 'cd', 16 );
		mkdir( $old );
		touch( $old, time() - 90000 );
		$fresh = $p->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) )->get( 'dir' );
		$this->assertSame( 1, $p->sweep() );
		$this->assertDirectoryDoesNotExist( $old );
		$this->assertDirectoryExists( $fresh );
	}

	public function test_a_file_vanishing_before_the_archive_is_closed_fails_cleanly_and_leaves_no_staging(): void {
		$victim  = $this->root . '/plugins/acme/inc/a.php';
		$factory = static function () use ( $victim ) {
			return new class( $victim ) extends ZipArchive {
				private string $victim;
				public function __construct( string $victim ) {
					$this->victim = $victim;
				}
				#[\ReturnTypeWillChange]
				public function close() {
					unlink( $this->victim );   // the file is gone after add_tree() saw it
					return @parent::close();   // ZipArchive warns about the missing file; the failure return is what matters
				}
			};
		};
		$r = $this->packager( null, null, null, $factory )->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$this->assertTrue( $r->is_error() );
		$this->assertSame( 'packager_zip_failed', $r->code() );
		$this->assertSame( array(), glob( $this->root . '/systmp/babilim-upload-*' ) );
	}

	public function test_missing_zip_extension_is_refused_before_any_directory_is_created(): void {
		$r = $this->packager( null, null, null, static function () { return null; } )->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$this->assertSame( 'packager_zip_failed', $r->code() );
		$this->assertSame( array(), glob( $this->root . '/systmp/babilim-upload-*' ) );
		$this->assertSame( array(), glob( $this->root . '/wptmp/babilim-upload-*' ) );
	}

	public function test_a_theme_is_packed_from_the_directory_wordpress_reports_for_it(): void {
		$extra = $this->root . '/extra-themes';
		mkdir( $extra . '/elsewhere', 0777, true );
		file_put_contents( $extra . '/elsewhere/style.css', "/*\nTheme Name: Elsewhere\n*/" );
		$seen   = array();
		$lookup = static function ( string $stylesheet ) use ( $extra, &$seen ) {
			$seen[] = $stylesheet;
			return array( 'dir' => $extra . '/elsewhere', 'root' => $extra );
		};
		$r = $this->packager( null, null, $lookup )->pack( array( 'kind' => 'theme', 'slug' => 'elsewhere', 'stylesheet' => 'elsewhere' ) );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( array( 'elsewhere' ), $seen );
		$this->assertSame( array( 'elsewhere/style.css' ), $this->names( $r->get( 'zip' ) ) );
	}

	public function test_a_reported_theme_directory_outside_its_own_theme_root_is_refused(): void {
		$lookup = function ( string $stylesheet ) {
			return array( 'dir' => $this->root . '/outside', 'root' => $this->root . '/themes' );
		};
		$r = $this->packager( null, null, $lookup )->pack( array( 'kind' => 'theme', 'slug' => 'x', 'stylesheet' => 'x' ) );
		$this->assertSame( 'packager_outside_root', $r->code() );
	}

	public function test_the_default_theme_lookup_asks_wordpress_for_the_themes_own_directory(): void {
		$extra = $this->root . '/extra-themes';
		mkdir( $extra . '/elsewhere', 0777, true );
		file_put_contents( $extra . '/elsewhere/style.css', "/*\nTheme Name: Elsewhere\n*/" );
		$GLOBALS['babilim_test_wp_get_theme_return'] = new class( $extra ) {
			private string $root;
			public function __construct( string $root ) {
				$this->root = $root;
			}
			public function exists() {
				return true;
			}
			public function get_stylesheet_directory() {
				return $this->root . '/elsewhere';
			}
			public function get_theme_root() {
				return $this->root;
			}
		};
		$tmp = $this->root . '/systmp';
		mkdir( $tmp, 0777, true );
		// No theme lookup and no zip factory injected: the constructor's own defaults.
		$packager = new Babilim_Packager(
			static function () use ( $tmp ) { return $tmp; },
			static function () use ( $tmp ) { return $tmp; },
			static function () { return str_repeat( 'cd', 16 ); },
			$this->root . '/plugins',
			$this->root . '/themes'
		);
		$r = $packager->pack( array( 'kind' => 'theme', 'slug' => 'elsewhere', 'stylesheet' => 'elsewhere' ) );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( array( 'elsewhere/style.css' ), $this->names( $r->get( 'zip' ) ) );
	}

	public function test_a_theme_wordpress_does_not_know_falls_back_to_the_theme_root(): void {
		// The bootstrap's default theme double reports exists() === false.
		$tmp = $this->root . '/systmp';
		mkdir( $tmp, 0777, true );
		$packager = new Babilim_Packager(
			static function () use ( $tmp ) { return $tmp; },
			static function () use ( $tmp ) { return $tmp; },
			static function () { return str_repeat( 'cd', 16 ); },
			$this->root . '/plugins',
			$this->root . '/themes'
		);
		$r = $packager->pack( array( 'kind' => 'theme', 'slug' => 'avada', 'stylesheet' => 'avada' ) );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( array( 'avada/inc/t.php', 'avada/style.css' ), $this->names( $r->get( 'zip' ) ) );
	}

	/**
	 * The constructor's default zip factory must answer null (not fatal)
	 * when ZipArchive does not exist. The class cannot be removed from
	 * this process, so the real packager file runs in a PHP started with
	 * no extensions at all.
	 */
	public function test_the_default_zip_factory_reports_a_missing_zip_extension_without_a_fatal(): void {
		$script = $this->root . '/nozip.php';
		$root   = dirname( __DIR__, 2 );
		file_put_contents(
			$script,
			"<?php\n"
			. "define( 'ABSPATH', '/x/' );\n"
			. "function __( \$s ) { return \$s; }\n"
			. "require " . var_export( $root . '/includes/class-babilim-result.php', true ) . ";\n"
			. "require " . var_export( $root . '/includes/class-babilim-packager.php', true ) . ";\n"
			. "\$p = new Babilim_Packager( null, null, null, " . var_export( $this->root . '/plugins', true ) . ", " . var_export( $this->root . '/themes', true ) . " );\n"
			. "\$r = \$p->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );\n"
			. "echo class_exists( 'ZipArchive' ) ? 'HAS_ZIP' : 'NO_ZIP', ' ', (string) \$r->code();\n"
		);
		$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $script ) . ' 2>&1' );
		$this->assertSame( 'NO_ZIP packager_zip_failed', trim( $out ) );
	}
}
