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

	private const ACME_PACKED = array( 'acme/README.md', 'acme/acme.php', 'acme/blocks/foo/block.json', 'acme/inc/a.php', 'acme/js/app.js', 'acme/readme.txt', 'acme/style.css', 'acme/tests.php' );

	private string $root;

	/** @var array{set:bool,value:mixed} */
	private array $saved_document_root = array(
		'set'   => false,
		'value' => null,
	);

	protected function setUp(): void {
		babilim_test_reset();
		$this->saved_document_root = array(
			'set'   => array_key_exists( 'DOCUMENT_ROOT', $_SERVER ),
			'value' => $_SERVER['DOCUMENT_ROOT'] ?? null,
		);
		$this->root = BABILIM_TEST_TMP_ROOT . '/packager-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/public', 0777, true );
		$_SERVER['DOCUMENT_ROOT'] = $this->root . '/public';
		mkdir( $this->root . '/plugins/acme/inc', 0777, true );
		mkdir( $this->root . '/themes/avada/inc', 0777, true );
		mkdir( $this->root . '/outside', 0777, true );
		file_put_contents( $this->root . '/plugins/acme/acme.php', "<?php\n/**\n * Plugin Name: Acme\n * Version: 1.0\n */\n" );
		file_put_contents( $this->root . '/plugins/acme/inc/a.php', '<?php' );
		file_put_contents( $this->root . '/plugins/single.php', "<?php\n/**\n * Plugin Name: Single\n */\n" );
		file_put_contents( $this->root . '/themes/avada/style.css', "/*\nTheme Name: Avada\n*/" );
		file_put_contents( $this->root . '/themes/avada/inc/t.php', '<?php' );
		file_put_contents( $this->root . '/outside/secret.txt', 'nope' );
		$acme_files = array(
			'js/app.js', 'blocks/foo/block.json', 'readme.txt', 'README.md', 'style.css', 'tests.php',
			'uploads/customer.php', 'cache/data.php', 'wp-config.php', 'wp-config-local.php',
			'inc/wp-config-sample.php', 'languages/x.l10n.php', 'inc/x.l10n.php', 'data/export.sql',
			'.env', '.env.local', '.git/config', 'backup/site.bak', 'inc/debug.log', 'sub/readme.txt',
			'sub/style.css', 'assets/logo.png', 'inc/key.pem', 'inc/id_rsa.pub', 'vendor/lib/x.php',
			'node_modules/m/i.js', 'src/app.tsx', 'inc/nested/uploads/x.php',
		);
		foreach ( $acme_files as $rel ) {
			$this->put( $this->root . '/plugins/acme/' . $rel );
		}
		foreach ( array( 'theme.json', 'screenshot.png', 'styles/dark.json', 'sub/style.css' ) as $rel ) {
			$this->put( $this->root . '/themes/avada/' . $rel );
		}
	}

	private function put( string $path ): void {
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, 'x' );
	}

	private function pack_acme(): array {
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$this->assertTrue( $r->is_ok(), $r->message() );
		return $this->names( $r->get( 'zip' ) );
	}

	protected function tearDown(): void {
		if ( $this->saved_document_root['set'] ) {
			$_SERVER['DOCUMENT_ROOT'] = $this->saved_document_root['value'];
		} else {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		}
		babilim_test_rmtree( $this->root );
	}

	/**
	 * @param string|null $host_tmp  The host-named folder (BABILIM_TEMP_DIR); null for none.
	 * @param array|null  $site_roots ABSPATH / WP_CONTENT_DIR stand-ins; null for the constructor default.
	 */
	private function packager( ?string $sys_tmp = null, ?string $host_tmp = null, ?callable $theme_dir = null, ?callable $zip_factory = null, ?array $site_roots = null ): Babilim_Packager {
		$sys_tmp = $sys_tmp ?? $this->root . '/systmp';
		@mkdir( $sys_tmp, 0777, true );
		if ( null !== $host_tmp ) {
			@mkdir( $host_tmp, 0777, true );
		}
		return new Babilim_Packager(
			static function () use ( $sys_tmp ) { return $sys_tmp; },
			static function () use ( $host_tmp ) { return null === $host_tmp ? '' : $host_tmp; },
			static function () { return str_repeat( 'ab', 16 ); },
			$this->root . '/plugins',
			$this->root . '/themes',
			$theme_dir ?? static function () { return null; },
			$zip_factory,
			null === $site_roots ? null : static function () use ( $site_roots ) { return $site_roots; }
		);
	}

	private function acme(): array {
		return array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' );
	}

	/** Every babilim-upload-* directory directly under $dir or one level below it. */
	private function staging_under( string $dir ): array {
		return array_merge( glob( $dir . '/babilim-upload-*' ) ?: array(), glob( $dir . '/*/babilim-upload-*' ) ?: array() );
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
		$this->assertSame( self::ACME_PACKED, $this->names( $r->get( 'zip' ) ) );
		$this->assertSame( filesize( $r->get( 'zip' ) ), $r->get( 'size' ) );
		$this->assertStringStartsWith( $this->root . '/systmp/babilim-upload-abababab', $r->get( 'zip' ) );
	}

	public function test_no_guard_files_are_written_into_the_staging_directory(): void {
		$r = $this->packager()->pack( $this->acme() );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( array( 'package.zip' ), array_values( array_diff( scandir( dirname( $r->get( 'zip' ) ) ), array( '.', '..' ) ) ) );
	}

	public function test_single_file_plugin_packs_only_that_file(): void {
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'single', 'main_file' => 'single.php' ) );
		$this->assertTrue( $r->is_ok() );
		$this->assertSame( array( 'single.php' ), $this->names( $r->get( 'zip' ) ) );
	}

	public function test_packs_a_theme_by_stylesheet(): void {
		$r = $this->packager()->pack( array( 'kind' => 'theme', 'slug' => 'avada', 'stylesheet' => 'avada' ) );
		$this->assertTrue( $r->is_ok() );
		$this->assertSame( array( 'avada/inc/t.php', 'avada/style.css', 'avada/theme.json' ), $this->names( $r->get( 'zip' ) ) );
	}

	public function test_symlinks_are_neither_followed_nor_included(): void {
		symlink( $this->root . '/outside', $this->root . '/plugins/acme/linkdir' );
		symlink( $this->root . '/outside/secret.txt', $this->root . '/plugins/acme/link.txt' );
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$this->assertTrue( $r->is_ok() );
		$this->assertSame( self::ACME_PACKED, $this->names( $r->get( 'zip' ) ) );
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

	public function test_system_temp_under_abspath_falls_back_to_the_host_dir(): void {
		$under_abspath = rtrim( ABSPATH, '/' ) . '/tmp-' . bin2hex( random_bytes( 2 ) );
		$r = $this->packager( $under_abspath, $this->root . '/hosttmp' )->pack( $this->acme() );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertStringStartsWith( $this->root . '/hosttmp/babilim-upload-', $r->get( 'zip' ) );
		babilim_test_rmtree( $under_abspath );
	}

	// -- Where the zip may be staged (system temp with a resolvable,
	// unmodified DOCUMENT_ROOT, or BABILIM_TEMP_DIR; nothing else). -------

	private function www_layout(): array {
		mkdir( $this->root . '/srv/www/wp/wp-content', 0777, true );
		mkdir( $this->root . '/srv/www/tmp', 0777, true );
		mkdir( $this->root . '/srv/private', 0777, true );
		return array( $this->root . '/srv/www/wp', $this->root . '/srv/www/wp/wp-content' );
	}

	public function test_system_temp_is_used_when_document_root_resolves_and_it_lies_outside_all_three_roots(): void {
		mkdir( $this->root . '/public/wp', 0777, true );
		$roots = array( $this->root . '/public/wp', $this->root . '/public/wp/wp-content' );
		$r     = $this->packager( $this->root . '/private/tmp', null, null, null, $roots )->pack( $this->acme() );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertStringStartsWith( $this->root . '/private/tmp/babilim-upload-', $r->get( 'zip' ) );
	}

	public function test_sibling_of_the_site_under_the_public_root_is_refused_when_document_root_is_missing(): void {
		$roots = $this->www_layout();
		unset( $_SERVER['DOCUMENT_ROOT'] );
		$r = $this->packager( $this->root . '/srv/www/tmp', null, null, null, $roots )->pack( $this->acme() );
		$this->assertSame( 'packager_no_temp_dir', $r->code() );
		$this->assertStringContainsString( 'BABILIM_TEMP_DIR', $r->message() );
		$this->assertSame( array(), $this->staging_under( $this->root . '/srv/www' ) );
	}

	public function test_sibling_under_a_resolvable_public_root_is_refused(): void {
		$roots = $this->www_layout();
		$_SERVER['DOCUMENT_ROOT'] = $this->root . '/srv/www';
		$r = $this->packager( $this->root . '/srv/www/tmp', null, null, null, $roots )->pack( $this->acme() );
		$this->assertSame( 'packager_no_temp_dir', $r->code() );
		$this->assertSame( array(), $this->staging_under( $this->root . '/srv/www' ) );
	}

	public function test_host_dir_outside_the_roots_is_used_when_document_root_is_missing(): void {
		$roots = $this->www_layout();
		unset( $_SERVER['DOCUMENT_ROOT'] );
		$r = $this->packager( $this->root . '/srv/www/tmp', $this->root . '/srv/private', null, null, $roots )->pack( $this->acme() );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertStringStartsWith( $this->root . '/srv/private/babilim-upload-', $r->get( 'zip' ) );
	}

	public function test_host_dir_inside_wp_content_is_refused_even_without_document_root(): void {
		$roots = $this->www_layout();
		mkdir( $this->root . '/srv/www/wp/wp-content/tmp', 0777, true );
		unset( $_SERVER['DOCUMENT_ROOT'] );
		$r = $this->packager( $this->root . '/srv/www/tmp', $this->root . '/srv/www/wp/wp-content/tmp', null, null, $roots )->pack( $this->acme() );
		$this->assertSame( 'packager_no_temp_dir', $r->code() );
		$this->assertSame( array(), $this->staging_under( $this->root . '/srv/www/wp/wp-content' ) );
	}

	public function test_unresolvable_document_root_refuses_the_system_temp_even_when_it_is_outside(): void {
		foreach ( array( array( 'x' ), $this->root . '/nonexistent-dir', '' ) as $bad ) {
			$_SERVER['DOCUMENT_ROOT'] = $bad;
			$refused                  = $this->packager( $this->root . '/private/tmp' )->pack( $this->acme() );
			$this->assertSame( 'packager_no_temp_dir', $refused->code() );
			$used = $this->packager( $this->root . '/private/tmp', $this->root . '/hosttmp' )->pack( $this->acme() );
			$this->assertTrue( $used->is_ok(), $used->message() );
			$this->assertStringStartsWith( $this->root . '/hosttmp/babilim-upload-', $used->get( 'zip' ) );
			$this->assertSame( array(), $this->staging_under( $this->root . '/private/tmp' ) );
			( new Babilim_Packager() )->discard( $used->get( 'dir' ) );
		}
	}

	public function test_an_unwritable_system_temp_falls_through_to_the_host_dir(): void {
		mkdir( $this->root . '/ro', 0500, true );
		if ( is_writable( $this->root . '/ro' ) ) {
			chmod( $this->root . '/ro', 0700 );
			$this->markTestSkipped( 'running as root; chmod 0500 is still writable' );
		}
		$r = $this->packager( $this->root . '/ro', $this->root . '/hosttmp' )->pack( $this->acme() );
		chmod( $this->root . '/ro', 0700 );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertStringStartsWith( $this->root . '/hosttmp/babilim-upload-', $r->get( 'zip' ) );
	}

	public function test_wordpress_temp_dir_is_not_consulted(): void {
		$under_abspath = rtrim( ABSPATH, '/' ) . '/tmp-' . bin2hex( random_bytes( 2 ) );
		// The bootstrap's get_temp_dir() points outside the site; it must not matter.
		$r = $this->packager( $under_abspath )->pack( $this->acme() );
		$this->assertSame( 'packager_no_temp_dir', $r->code() );
		$this->assertSame( array(), glob( rtrim( get_temp_dir(), '/' ) . '/babilim-upload-*' ) ?: array() );
		babilim_test_rmtree( $under_abspath );
	}

	public function test_staging_base_is_reported_and_base_still_private_rechecks_without_document_root(): void {
		$packager = $this->packager( $this->root . '/private/tmp' );
		$this->assertSame( realpath( $this->root . '/private/tmp' ), $packager->staging_base() );
		unset( $_SERVER['DOCUMENT_ROOT'] );
		$this->assertTrue( $packager->base_still_private( (string) realpath( $this->root . '/private/tmp' ) ) );
		$this->assertNull( $packager->staging_base(), 'without DOCUMENT_ROOT the system temp is not provable' );
		$under_abspath = rtrim( ABSPATH, '/' ) . '/tmp-' . bin2hex( random_bytes( 2 ) );
		mkdir( $under_abspath, 0777, true );
		$this->assertFalse( $packager->base_still_private( $under_abspath ) );
		babilim_test_rmtree( $under_abspath );
	}

	public function test_a_document_root_that_sanitizing_would_rewrite_is_treated_as_unknown(): void {
		mkdir( $this->root . '/srv/www/site  root/wp/wp-content', 0777, true );
		mkdir( $this->root . '/srv/www/site  root/tmp', 0777, true );
		mkdir( $this->root . '/srv/www/site root', 0777, true );
		mkdir( $this->root . '/srv/private', 0777, true );
		$roots = array( $this->root . '/srv/www/site  root/wp', $this->root . '/srv/www/site  root/wp/wp-content' );
		$_SERVER['DOCUMENT_ROOT'] = $this->root . '/srv/www/site  root';
		$r = $this->packager( $this->root . '/srv/www/site  root/tmp', null, null, null, $roots )->pack( $this->acme() );
		$this->assertSame( 'packager_no_temp_dir', $r->code() );
		$this->assertSame( array(), $this->staging_under( $this->root . '/srv/www/site  root' ) );
		$this->assertSame( array(), $this->staging_under( $this->root . '/srv/www/site root' ) );
		$used = $this->packager( $this->root . '/srv/www/site  root/tmp', $this->root . '/srv/private', null, null, $roots )->pack( $this->acme() );
		$this->assertTrue( $used->is_ok(), $used->message() );
		$this->assertStringStartsWith( $this->root . '/srv/private/babilim-upload-', $used->get( 'zip' ) );
	}

	public function test_a_document_root_with_trailing_whitespace_or_a_tab_is_treated_as_unknown(): void {
		mkdir( $this->root . '/public ', 0777, true );
		mkdir( $this->root . "/pub	lic", 0777, true );
		foreach ( array( $this->root . '/public ', $this->root . "/pub	lic" ) as $odd ) {
			$_SERVER['DOCUMENT_ROOT'] = $odd;
			$refused                  = $this->packager( $this->root . '/private/tmp' )->pack( $this->acme() );
			$this->assertSame( 'packager_no_temp_dir', $refused->code(), $odd );
			$used = $this->packager( $this->root . '/private/tmp', $this->root . '/hosttmp' )->pack( $this->acme() );
			$this->assertTrue( $used->is_ok(), $used->message() );
			( new Babilim_Packager() )->discard( $used->get( 'dir' ) );
		}
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

	/** Makes a babilim-upload-<hex> directory under the scratch base with the given files. */
	private function staging_dir_with( string $hex_unit, array $files ): string {
		$dir = $this->root . '/systmp/babilim-upload-' . str_repeat( $hex_unit, 16 );
		mkdir( $dir, 0700, true );
		foreach ( $files as $name => $bytes ) {
			file_put_contents( $dir . '/' . $name, $bytes );
		}
		return $dir;
	}

	public function test_sweep_legacy_removes_only_this_sites_unsealed_pending_dir(): void {
		$p = $this->packager();
		$a = $this->staging_dir_with( 'aa', array( 'package.zip' => 'A', '.htaccess' => 'h', 'index.php' => '<?php' ) );
		$b = $this->staging_dir_with( 'bb', array( 'package.zip' => 'known bytes of B', '.htaccess' => 'h', 'index.php' => '<?php' ) );
		$c = $this->staging_dir_with( 'cc', array( 'package.zip' => 'C' ) );
		$this->assertSame( 1, $p->sweep_legacy( array( 'dir' => $a ) ) );
		$this->assertDirectoryDoesNotExist( $a );
		$this->assertSame( hash( 'sha256', 'known bytes of B' ), hash_file( 'sha256', $b . '/package.zip' ) );
		$this->assertFileExists( $b . '/.htaccess' );
		$this->assertFileExists( $b . '/index.php' );
		$this->assertDirectoryExists( $c );
	}

	public function test_sweep_legacy_does_nothing_without_a_traceable_unsealed_pending(): void {
		$p    = $this->packager();
		$dir  = $this->staging_dir_with( 'aa', array( 'package.zip' => 'A' ) );
		$link = $this->root . '/systmp/babilim-upload-' . str_repeat( 'dd', 16 );
		symlink( $dir, $link );
		$plain = $this->root . '/outside';
		$cases = array(
			'null pending'       => null,
			'symlinked dir'      => array( 'dir' => $link ),
			'not an owned name'  => array( 'dir' => $plain ),
			'no dir'             => array( 'upload_id' => 'x' ),
			'sealed pending'     => array( 'dir' => $dir, 'package_policy' => 2 ),
		);
		foreach ( $cases as $label => $pending ) {
			$this->assertSame( 0, $p->sweep_legacy( $pending ), $label );
		}
		$this->assertFileExists( $dir . '/package.zip' );
		$this->assertFileExists( $plain . '/secret.txt' );
		unlink( $link );
	}

	public function test_daily_sweep_touches_only_this_sites_pending_and_never_a_live_one(): void {
		$p      = $this->packager();
		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 );
		$past   = gmdate( 'Y-m-d\TH:i:s\Z', time() - 3600 );

		$live = $this->staging_dir_with( 'aa', array( 'package.zip' => 'A' ) );
		$this->assertSame( 0, $p->sweep( array( 'dir' => $live, 'package_policy' => 2, 'expires_at' => $future ) ), 'sealed + unexpired is live' );
		$this->assertDirectoryExists( $live );
		$this->assertSame( 1, $p->sweep( array( 'dir' => $live, 'package_policy' => 2, 'expires_at' => $past ) ), 'sealed + expired' );
		$this->assertDirectoryDoesNotExist( $live );

		$old = $this->staging_dir_with( 'bb', array( 'package.zip' => 'B' ) );
		$this->assertSame( 1, $p->sweep( array( 'dir' => $old, 'expires_at' => $future ) ), 'unsealed' );
		$this->assertDirectoryDoesNotExist( $old );

		$stray_old = $this->staging_dir_with( 'cc', array( 'package.zip' => 'C', '.htaccess' => 'h' ) );
		$stray_new = $this->staging_dir_with( 'dd', array( 'package.zip' => 'D' ) );
		touch( $stray_old, time() - 2 * 86400 );
		touch( $stray_new, time() - 2 * 86400 );
		$mine = $this->staging_dir_with( 'ee', array( 'package.zip' => 'E' ) );
		$p->sweep( array( 'dir' => $mine, 'expires_at' => $past ) );
		$this->assertDirectoryExists( $stray_old );
		$this->assertDirectoryExists( $stray_new );
		$this->assertSame( 0, $p->sweep( null ) );
		$this->assertDirectoryExists( $stray_old );
		$this->assertDirectoryExists( $stray_new );
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
		$tmp  = $this->root . '/systmp';
		$host = $this->root . '/hosttmp';
		mkdir( $tmp, 0777, true );
		mkdir( $host, 0777, true );
		$_SERVER['DOCUMENT_ROOT'] = $this->root . '/public'; // Explicit: the system temp is only usable with a resolvable public root.
		// No theme lookup and no zip factory injected: the constructor's own defaults.
		$packager = new Babilim_Packager(
			static function () use ( $tmp ) { return $tmp; },
			static function () use ( $host ) { return $host; },
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
		$tmp  = $this->root . '/systmp';
		$host = $this->root . '/hosttmp';
		mkdir( $tmp, 0777, true );
		mkdir( $host, 0777, true );
		$_SERVER['DOCUMENT_ROOT'] = $this->root . '/public'; // Explicit: the system temp is only usable with a resolvable public root.
		$packager = new Babilim_Packager(
			static function () use ( $tmp ) { return $tmp; },
			static function () use ( $host ) { return $host; },
			static function () { return str_repeat( 'cd', 16 ); },
			$this->root . '/plugins',
			$this->root . '/themes'
		);
		$r = $packager->pack( array( 'kind' => 'theme', 'slug' => 'avada', 'stylesheet' => 'avada' ) );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( array( 'avada/inc/t.php', 'avada/style.css', 'avada/theme.json' ), $this->names( $r->get( 'zip' ) ) );
	}

	public function test_only_listed_code_and_description_files_are_packed(): void {
		$this->assertSame( self::ACME_PACKED, $this->pack_acme() );
	}

	public function test_counterexamples_are_excluded(): void {
		$names = $this->pack_acme();
		foreach ( array(
			'uploads/customer.php', 'cache/data.php', 'wp-config.php', 'wp-config-local.php', 'inc/wp-config-sample.php',
			'languages/x.l10n.php', 'inc/x.l10n.php', 'data/export.sql', '.env', '.env.local', '.git/config',
			'backup/site.bak', 'inc/debug.log', 'sub/readme.txt', 'sub/style.css', 'assets/logo.png', 'inc/key.pem',
			'inc/id_rsa.pub', 'vendor/lib/x.php', 'node_modules/m/i.js', 'src/app.tsx', 'inc/nested/uploads/x.php',
		) as $p ) {
			$this->assertNotContains( 'acme/' . $p, $names, $p );
		}
	}

	public function test_sensitive_patterns_match_the_service_rule(): void {
		$c = new ReflectionClassConstant( Babilim_Packager::class, 'SENSITIVE_NAME_PATTERNS' );
		$this->assertSame( array( 'wp-config*', '.env*', '*.sql', '*.log', '*.pem', '*.key', '*.p12', 'id_rsa*' ), $c->getValue() );
	}

	public function test_a_theme_packs_root_style_css_theme_json_and_php_only(): void {
		$r = $this->packager()->pack( array( 'kind' => 'theme', 'slug' => 'avada', 'stylesheet' => 'avada' ) );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( array( 'avada/inc/t.php', 'avada/style.css', 'avada/theme.json' ), $this->names( $r->get( 'zip' ) ) );
	}

	public function test_root_only_names_are_not_taken_from_subfolders(): void {
		$r     = $this->packager()->pack( array( 'kind' => 'theme', 'slug' => 'avada', 'stylesheet' => 'avada' ) );
		$names = $this->names( $r->get( 'zip' ) );
		$this->assertNotContains( 'avada/sub/style.css', $names );
		$this->assertNotContains( 'avada/styles/dark.json', $names );
	}

	public function test_single_file_plugin_goes_through_the_same_name_rule(): void {
		file_put_contents( $this->root . '/plugins/wp-config-x.php', "<?php\n/**\n * Plugin Name: Sensitive named\n */\n" );
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'wp-config-x', 'main_file' => 'wp-config-x.php' ) );
		$this->assertSame( 'packager_nothing_to_pack', $r->code() );
		$this->assertSame( array(), glob( $this->root . '/systmp/babilim-upload-*' ) );
	}

	public function test_a_folder_with_no_packable_file_is_refused_without_a_warning(): void {
		mkdir( $this->root . '/plugins/bare', 0777, true );
		file_put_contents( $this->root . '/plugins/bare/notes.txt', 'x' );
		file_put_contents( $this->root . '/plugins/bare/.hidden.php', '<?php' );
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'bare', 'main_file' => 'bare/notes.txt' ) );
		$this->assertSame( 'packager_nothing_to_pack', $r->code() );
		$this->assertSame( array(), glob( $this->root . '/systmp/babilim-upload-*' ) );
	}

	public function test_matching_is_case_insensitive(): void {
		foreach ( array( 'Widget.PHP', 'Readme.TXT', 'Uploads/x.php', 'WP-CONFIG.PHP', 'WP-Config-Prod.php' ) as $rel ) {
			$this->put( $this->root . '/plugins/acme/' . $rel );
		}
		$names = $this->pack_acme();
		$this->assertContains( 'acme/Widget.PHP', $names );
		$this->assertContains( 'acme/Readme.TXT', $names );
		$this->assertNotContains( 'acme/Uploads/x.php', $names );
		$this->assertNotContains( 'acme/WP-CONFIG.PHP', $names );
		$this->assertNotContains( 'acme/WP-Config-Prod.php', $names );
	}

	public function test_pack_result_carries_policy_and_the_zip_sha256(): void {
		$r = $this->packager()->pack( array( 'kind' => 'plugin', 'slug' => 'acme', 'main_file' => 'acme/acme.php' ) );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( 2, $r->get( 'policy' ) );
		$this->assertSame( hash_file( 'sha256', $r->get( 'zip' ) ), $r->get( 'sha256' ) );
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
