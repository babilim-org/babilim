<?php
/**
 * Tests for Babilim_Wp_Fs's own atomic_rename() -- the routing that
 * exists specifically for Babilim_Manifest's own cross-request-safe
 * write (see that class's own rename_manifest_file() docblock).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Wp_Fs extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	private function cleanup( string ...$paths ): void {
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}

	/**
	 * The common, real-world case: a `direct` WP_Filesystem transfer (PHP
	 * itself has ordinary write permission to disk) uses PHP's own native
	 * rename(), never $wp_filesystem->move() -- confirmed here by the
	 * move() call never being recorded, not merely by the file landing
	 * correctly (which a delete-then-rename move() would also achieve in
	 * this test double, since it never actually deletes first -- see
	 * Babilim_Test_Wp_Filesystem_Direct::move()'s own real-core-matching
	 * caveat in this file's own report).
	 */
	public function test_atomic_rename_uses_native_rename_on_a_direct_transfer(): void {
		if ( ! is_dir( Babilim_Paths::root() ) ) {
			mkdir( Babilim_Paths::root(), 0777, true );
		}
		$from = Babilim_Paths::manifest_temp_file();
		$to   = Babilim_Paths::manifest_quarantine_file( 'aaaaaaaaaaaaaaa1' );
		$this->cleanup( $from, $to );
		file_put_contents( $from, 'direct-content' );

		$fs = new Babilim_Wp_Fs();
		$this->assertTrue( $fs->atomic_rename( $from, $to ) );

		$this->assertFalse( file_exists( $from ), 'the source file must be gone after a successful rename' );
		$this->assertSame( 'direct-content', file_get_contents( $to ) );
		$this->assertSame( array(), $GLOBALS['babilim_test_wp_filesystem_move_calls'], 'a direct transfer must use native rename(), never $wp_filesystem->move()' );

		$this->cleanup( $to );
	}

	/**
	 * A non-`direct` transfer (FTP/SSH2/...) has no local filesystem path
	 * for PHP's own rename() to operate on -- atomic_rename() must fall
	 * back to $wp_filesystem->move(), the only option there (made safe by
	 * Babilim_Manifest's own cross-request lock, not by this call being
	 * atomic -- see that class's own docblock).
	 */
	public function test_atomic_rename_uses_move_on_a_non_direct_transfer(): void {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		$GLOBALS['wp_filesystem']->method = 'ftpext';

		if ( ! is_dir( Babilim_Paths::root() ) ) {
			mkdir( Babilim_Paths::root(), 0777, true );
		}
		$from = Babilim_Paths::manifest_temp_file();
		$to   = Babilim_Paths::manifest_quarantine_file( 'aaaaaaaaaaaaaaa2' );
		$this->cleanup( $from, $to );
		file_put_contents( $from, 'ftp-content' );

		$fs = new Babilim_Wp_Fs();
		$this->assertTrue( $fs->atomic_rename( $from, $to ) );

		$this->assertSame( 'ftp-content', file_get_contents( $to ) );
		$this->assertCount( 1, $GLOBALS['babilim_test_wp_filesystem_move_calls'], 'a non-direct transfer must use $wp_filesystem->move()' );
		$this->assertSame( array( $from, $to ), $GLOBALS['babilim_test_wp_filesystem_move_calls'][0] );

		$this->cleanup( $to );
	}
}
