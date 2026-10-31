<?php
/**
 * Tests for Babilim_Fs's two implementations: Babilim_Memory_Fs (the
 * test double) and Babilim_Wp_Fs (the real, WP_Filesystem-backed one --
 * exercised here against tests/bootstrap.php's real-disk stand-in, see
 * that file's "WP_Filesystem" section).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Fs extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
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
			if ( is_link( $path ) ) {
				unlink( $path );
				continue;
			}
			is_dir( $path ) ? $this->remove_recursive( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	// -- Babilim_Memory_Fs ------------------------------------------------

	public function test_memory_fs_put_get_exists_round_trip(): void {
		$fs   = new Babilim_Memory_Fs();
		$path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );

		$this->assertFalse( $fs->exists( $path ) );
		$this->assertNull( $fs->get( $path ) );

		$this->assertTrue( $fs->put( $path, 'binary-mo-contents' ) );

		$this->assertTrue( $fs->exists( $path ) );
		$this->assertSame( 'binary-mo-contents', $fs->get( $path ) );
	}

	public function test_memory_fs_delete_is_idempotent(): void {
		$fs   = new Babilim_Memory_Fs();
		$path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );
		$fs->put( $path, 'x' );

		$this->assertTrue( $fs->delete( $path ) );
		$this->assertFalse( $fs->exists( $path ) );
		$this->assertTrue( $fs->delete( $path ) );
	}

	public function test_memory_fs_rename_moves_contents(): void {
		$fs   = new Babilim_Memory_Fs();
		$from = Babilim_Paths::file( 'plugins', 'old-slug', 'zh_TW', 'mo' );
		$to   = Babilim_Paths::file( 'plugins', 'new-slug', 'zh_TW', 'mo' );
		$fs->put( $from, 'contents' );

		$this->assertTrue( $fs->rename( $from, $to ) );

		$this->assertFalse( $fs->exists( $from ) );
		$this->assertSame( 'contents', $fs->get( $to ) );
	}

	public function test_memory_fs_list_filters_by_glob_within_one_directory(): void {
		$fs  = new Babilim_Memory_Fs();
		$dir = Babilim_Paths::dir( 'plugins' );
		$fs->put( $dir . '/a-zh_TW.mo', '1' );
		$fs->put( $dir . '/a-zh_TW.po', '2' );
		$fs->put( $dir . '/b-de_DE.mo', '3' );
		$fs->put( Babilim_Paths::dir( 'themes' ) . '/c-zh_TW.mo', '4' );

		$this->assertSame(
			array( 'a-zh_TW.mo', 'b-de_DE.mo' ),
			$fs->list( $dir, '*.mo' )
		);
	}

	public function test_memory_fs_sha256_matches_contents_and_is_null_when_missing(): void {
		$fs   = new Babilim_Memory_Fs();
		$path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );

		$this->assertNull( $fs->sha256( $path ) );

		$fs->put( $path, 'hash-me' );
		$this->assertSame( hash( 'sha256', 'hash-me' ), $fs->sha256( $path ) );
	}

	public function test_memory_fs_rejects_a_disallowed_path(): void {
		$fs = new Babilim_Memory_Fs();

		$this->expectException( InvalidArgumentException::class );
		$fs->put( '/etc/passwd', 'nope' );
	}

	/**
	 * @dataProvider provide_memory_fs_guarded_methods
	 */
	public function test_memory_fs_guards_every_method( string $method, array $args ): void {
		$fs = new Babilim_Memory_Fs();

		$this->expectException( InvalidArgumentException::class );
		$fs->{$method}( ...$args );
	}

	public function provide_memory_fs_guarded_methods(): array {
		$outside = '/etc/passwd';
		return array(
			'get'    => array( 'get', array( $outside ) ),
			'exists' => array( 'exists', array( $outside ) ),
			'delete' => array( 'delete', array( $outside ) ),
			'mkdir'  => array( 'mkdir', array( $outside ) ),
			'list'   => array( 'list', array( $outside ) ),
			'sha256' => array( 'sha256', array( $outside ) ),
			'is_dir' => array( 'is_dir', array( $outside ) ),
		);
	}

	/**
	 * Same contract as WordPress core's own WP_Filesystem_Direct::dirlist()
	 * (what Babilim_Wp_Fs::list() reads): listing a *file* path lists that
	 * one file, by its own basename -- never an empty array. A double that
	 * returned [] here once hid a recursive cleanup that only worked
	 * against the double.
	 */
	public function test_memory_fs_list_of_a_file_path_lists_that_file_like_real_dirlist(): void {
		$fs   = new Babilim_Memory_Fs();
		$file = Babilim_Paths::incoming_dir( 'tok' ) . '/a-zh_TW.mo';
		// A .mo directly under _incoming/ is never something this
		// plugin's own write path puts there (see
		// INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock) -- only
		// a foreign process, bypassing Babilim_Fs entirely,
		// ever leaves one here.
		$fs->plant_disallowed( $file );

		$this->assertSame( array( 'a-zh_TW.mo' ), $fs->list( $file ) );
		$this->assertSame( array(), $fs->list( $file, '*.po' ) );
	}

	public function test_memory_fs_is_dir_tells_a_directory_from_a_file_and_an_absent_path(): void {
		$fs  = new Babilim_Memory_Fs();
		$dir = Babilim_Paths::incoming_dir( 'tok' ) . '/default';
		$fs->plant_disallowed( $dir . '/a-zh_TW.mo' );

		$this->assertTrue( $fs->is_dir( $dir ) );
		$this->assertTrue( $fs->is_dir( Babilim_Paths::incoming_dir( 'tok' ) ) );
		$this->assertFalse( $fs->is_dir( $dir . '/a-zh_TW.mo' ) );
		$this->assertFalse( $fs->is_dir( $dir . '/absent' ) );
	}

	public function test_memory_fs_rename_rejects_a_disallowed_from_path(): void {
		$fs = new Babilim_Memory_Fs();
		$to = Babilim_Paths::file( 'plugins', 'new-slug', 'zh_TW', 'mo' );

		$this->expectException( InvalidArgumentException::class );
		$fs->rename( '/etc/passwd', $to );
	}

	public function test_memory_fs_rename_rejects_a_disallowed_to_path(): void {
		$fs   = new Babilim_Memory_Fs();
		$from = Babilim_Paths::file( 'plugins', 'old-slug', 'zh_TW', 'mo' );
		$fs->put( $from, 'contents' );

		// A bad extension in an otherwise-valid layout directory --
		// picked so dirname($to) is itself allowed, isolating this
		// guard from rename()'s internal mkdir(dirname($to)) call
		// (which would otherwise also reject a $to like '/etc/passwd'
		// for its own, unrelated reason and mask a missing $to check).
		$this->expectException( InvalidArgumentException::class );
		$fs->rename( $from, Babilim_Paths::dir( 'plugins' ) . '/evil.phtml' );
	}

	// -- Babilim_Wp_Fs ------------------------------------------------------

	public function test_wp_fs_rejects_a_disallowed_path_before_touching_the_filesystem(): void {
		$fs = new Babilim_Wp_Fs();

		$this->expectException( InvalidArgumentException::class );
		$fs->put( '/etc/passwd', 'nope' );
	}

	/**
	 * @dataProvider provide_wp_fs_guarded_methods
	 */
	public function test_wp_fs_guards_every_method( string $method, array $args ): void {
		$fs = new Babilim_Wp_Fs();

		$this->expectException( InvalidArgumentException::class );
		$fs->{$method}( ...$args );
	}

	public function provide_wp_fs_guarded_methods(): array {
		$outside = '/etc/passwd';
		return array(
			'get'    => array( 'get', array( $outside ) ),
			'exists' => array( 'exists', array( $outside ) ),
			'delete' => array( 'delete', array( $outside ) ),
			'mkdir'  => array( 'mkdir', array( $outside ) ),
			'list'   => array( 'list', array( $outside ) ),
			'sha256' => array( 'sha256', array( $outside ) ),
			'is_dir' => array( 'is_dir', array( $outside ) ),
		);
	}

	public function test_wp_fs_put_get_exists_delete_round_trip_on_real_disk(): void {
		$fs   = new Babilim_Wp_Fs();
		$path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );

		$this->assertFalse( $fs->exists( $path ) );
		$this->assertTrue( $fs->put( $path, 'real-disk-contents' ) );

		$this->assertTrue( $fs->exists( $path ) );
		$this->assertSame( 'real-disk-contents', $fs->get( $path ) );
		$this->assertSame( hash( 'sha256', 'real-disk-contents' ), $fs->sha256( $path ) );

		$this->assertTrue( $fs->delete( $path ) );
		$this->assertFalse( $fs->exists( $path ) );
	}

	public function test_wp_fs_get_returns_a_wp_error_when_the_file_exists_but_cannot_be_read(): void {
		// Simulates the real read-failure shape WP_Filesystem_Direct
		// itself produces (exists() true, get_contents() false) -- a
		// real chmod 000 doesn't reproduce this in this sandbox, since
		// this process runs as root and root bypasses file permissions.
		$fs   = new Babilim_Wp_Fs();
		$path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );
		$fs->put( $path, 'secret' );
		$GLOBALS['babilim_test_unreadable_paths'][ $path ] = true;

		$result = $fs->get( $path );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_wp_fs_get_returns_null_when_the_file_is_genuinely_absent(): void {
		$fs   = new Babilim_Wp_Fs();
		$path = Babilim_Paths::file( 'plugins', 'never-written', 'en_US', 'mo' );

		$this->assertNull( $fs->get( $path ) );
	}

	public function test_wp_fs_sha256_propagates_a_wp_error_instead_of_fataling(): void {
		$fs   = new Babilim_Wp_Fs();
		$path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );
		$fs->put( $path, 'secret' );
		$GLOBALS['babilim_test_unreadable_paths'][ $path ] = true;

		$result = $fs->sha256( $path );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_wp_fs_mkdir_creates_missing_parent_directories(): void {
		$fs  = new Babilim_Wp_Fs();
		$dir = Babilim_Paths::dir( 'custom/plugins' );

		$this->assertTrue( $fs->mkdir( $dir ) );
		$this->assertTrue( is_dir( $dir ) );
	}

	public function test_wp_fs_mkdir_passes_fs_chmod_dir(): void {
		$fs = new Babilim_Wp_Fs();
		$fs->mkdir( Babilim_Paths::dir( 'custom/plugins' ) );

		$this->assertNotEmpty( $GLOBALS['babilim_test_mkdir_chmod_calls'] );
		foreach ( $GLOBALS['babilim_test_mkdir_chmod_calls'] as $chmod ) {
			$this->assertSame( FS_CHMOD_DIR, $chmod );
		}
	}

	public function test_wp_fs_rename_moves_a_real_file(): void {
		$fs   = new Babilim_Wp_Fs();
		$from = Babilim_Paths::file( 'plugins', 'old-slug', 'zh_TW', 'mo' );
		$to   = Babilim_Paths::file( 'plugins', 'new-slug', 'zh_TW', 'mo' );
		$fs->put( $from, 'contents' );

		$this->assertTrue( $fs->rename( $from, $to ) );

		$this->assertFalse( $fs->exists( $from ) );
		$this->assertSame( 'contents', $fs->get( $to ) );
	}

	public function test_wp_fs_rename_rejects_a_disallowed_from_path(): void {
		$fs = new Babilim_Wp_Fs();
		$to = Babilim_Paths::file( 'plugins', 'new-slug', 'zh_TW', 'mo' );

		$this->expectException( InvalidArgumentException::class );
		$fs->rename( '/etc/passwd', $to );
	}

	public function test_wp_fs_rename_rejects_a_disallowed_to_path(): void {
		$fs   = new Babilim_Wp_Fs();
		$from = Babilim_Paths::file( 'plugins', 'old-slug', 'zh_TW', 'mo' );
		$fs->put( $from, 'contents' );

		$this->expectException( InvalidArgumentException::class );
		$fs->rename( $from, '/etc/passwd' );
	}

	public function test_wp_fs_mkdir_creates_wp_lang_dir_itself_but_nothing_above_it(): void {
		$lang_dir = rtrim( WP_LANG_DIR, '/' );
		$this->remove_recursive( Babilim_Paths::root() );
		if ( is_dir( $lang_dir ) ) {
			rmdir( $lang_dir );
		}

		try {
			$fs = new Babilim_Wp_Fs();
			$fs->mkdir( Babilim_Paths::dir( 'custom/plugins' ) );

			$this->assertTrue( is_dir( $lang_dir ), 'a missing WP_LANG_DIR is the one directory outside the root that may be created' );
			$this->assertNotContains( dirname( $lang_dir ), $GLOBALS['babilim_test_mkdir_calls'], 'nothing above WP_LANG_DIR may ever be created' );
		} finally {
			if ( ! is_dir( $lang_dir ) ) {
				mkdir( $lang_dir, 0777, true );
			}
		}
	}

	public function test_wp_fs_throws_when_wp_filesystem_cannot_initialize(): void {
		$GLOBALS['babilim_test_wp_filesystem_should_fail'] = true;
		$fs = new Babilim_Wp_Fs();

		$this->expectException( RuntimeException::class );
		$fs->exists( Babilim_Paths::root() );
	}

	public function test_wp_fs_rejects_a_path_through_a_symlinked_layout_directory(): void {
		$fs = new Babilim_Wp_Fs();
		$fs->mkdir( Babilim_Paths::root() );

		$outside = BABILIM_TEST_TMP_ROOT . '/symlink-outside';
		mkdir( $outside, 0777, true );
		$link = Babilim_Paths::dir( 'plugins' );
		symlink( $outside, $link );

		try {
			$this->expectException( InvalidArgumentException::class );
			$fs->put( Babilim_Paths::file( 'plugins', 'evil', 'en_US', 'mo' ), 'escaped' );
		} finally {
			unlink( $link );
			$this->remove_recursive( $outside );
		}
	}

	public function test_wp_fs_list_filters_by_glob_within_one_directory(): void {
		$fs  = new Babilim_Wp_Fs();
		$dir = Babilim_Paths::dir( 'plugins' );
		$fs->put( $dir . '/a-zh_TW.mo', '1' );
		$fs->put( $dir . '/a-zh_TW.po', '2' );
		$fs->put( $dir . '/b-de_DE.mo', '3' );

		$this->assertSame( array( 'a-zh_TW.mo', 'b-de_DE.mo' ), $fs->list( $dir, '*.mo' ) );
	}

	/**
	 * The real-disk stand-in for $wp_filesystem (tests/bootstrap.php)
	 * must list a file path the way WordPress core's own
	 * WP_Filesystem_Direct::dirlist() does -- that one file, by its own
	 * basename -- so Babilim_Wp_Fs is exercised against core's own
	 * semantics rather than a friendlier double's.
	 */
	public function test_wp_fs_list_of_a_file_path_lists_that_file_like_real_dirlist(): void {
		$fs   = new Babilim_Wp_Fs();
		$file = Babilim_Paths::incoming_dir( 'tok' ) . '/a-zh_TW.mo';
		// Raw PHP, not $fs->put(): a .mo directly under _incoming/ is
		// never something this plugin's own write path puts there (see
		// INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock) -- only
		// a foreign process, bypassing Babilim_Fs entirely,
		// ever leaves one here.
		mkdir( dirname( $file ), 0777, true );
		file_put_contents( $file, '1' );

		$this->assertSame( array( 'a-zh_TW.mo' ), $fs->list( $file ) );
		$this->assertSame( array(), $fs->list( $file, '*.po' ) );
	}

	public function test_wp_fs_is_dir_tells_a_directory_from_a_file_and_an_absent_path(): void {
		$fs  = new Babilim_Wp_Fs();
		$dir = Babilim_Paths::incoming_dir( 'tok' ) . '/default';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/a-zh_TW.mo', '1' );

		$this->assertTrue( $fs->is_dir( $dir ) );
		$this->assertTrue( $fs->is_dir( Babilim_Paths::incoming_dir( 'tok' ) ) );
		$this->assertFalse( $fs->is_dir( $dir . '/a-zh_TW.mo' ) );
		$this->assertFalse( $fs->is_dir( $dir . '/absent' ) );
	}
}
