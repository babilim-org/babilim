<?php
/**
 * Tests for the `_incoming/` cleanup owned by Babilim_Sync_Installer: the
 * daily sweep of download directories a killed request left behind, and
 * the recursive cleanup behind it. Every path guard the sweep relied on
 * before the core update hooks were removed is exercised here unchanged.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Incoming_Sweep extends TestCase {

	/**
	 * Whether Babilim_Paths::root() already existed on real disk before
	 * the test: tearDown() removes the root only when the test created it,
	 * but always removes any `_incoming` a real-disk test left behind
	 * (WP_LANG_DIR is process-wide, so a leftover would leak into an
	 * unrelated test's clean-slate assumptions).
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

	private function new_sweeper( Babilim_Fs $fs ): Babilim_Sync_Installer {
		$state = new Babilim_State();
		$http  = new Babilim_Fake_Http( array() );
		$site  = new Babilim_Site( $http, 'https://api.babilim.org', $state, new Babilim_Inventory( static function () { return array(); }, static function () { return array(); }, static function () { return false; }, static function () { return array(); } ), new Babilim_Manifest( $fs ) );
		$inst  = new Babilim_Installer( $fs, new Babilim_Manifest( $fs ), static function () { return ''; }, static function () { return 0; }, static function () { return array(); } );
		return new Babilim_Sync_Installer( $state, $site, $inst, $http, $fs, 'https://api.babilim.org' );
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
		$sweeper = $this->new_sweeper( $fs );

		$victim_dir = Babilim_Paths::dir( 'custom/plugins' );
		$fs->put( $victim_dir . '/woocommerce-zh_TW.mo', 'must not be deleted' );

		$method = new ReflectionMethod( Babilim_Sync_Installer::class, 'cleanup_incoming_recursive' );
		$method->setAccessible( true );
		$result = $method->invoke( $sweeper, $victim_dir );

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
		$sweeper = $this->new_sweeper( $fs );

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
		// a foreign process, bypassing Babilim_Fs entirely,
		// ever leaves one here. plant_disallowed() places it the same
		// way, skipping the write-time allow-list this fixture setup
		// isn't meant to exercise.
		$fs->plant_disallowed( $old_nonempty_dir . '/woocommerce-zh_TW.mo' );
		$fs->mkdir( $new_dir );
		$fs->mkdir( $unparseable_dir );

		$removed = $sweeper->sweep_stale_incoming_dirs();

		$this->assertSame( 2, $removed );
		$this->assertFalse( $fs->exists( $old_empty_dir ), 'An empty entry older than a day must be removed.' );
		$this->assertFalse( $fs->exists( $old_nonempty_dir ), 'A non-empty entry older than a day must also be removed.' );
		$this->assertTrue( $fs->exists( $new_dir ), 'An entry not yet a day old must be kept.' );
		$this->assertTrue( $fs->exists( $unparseable_dir ), "An entry whose name doesn't parse as a token must be left alone." );
	}

	/**
	 * `_incoming/{token}/` is only ever actually populated by a foreign
	 * process or an interrupted run (see this class's own docblock) -- never through this plugin's own
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
		$sweeper = $this->new_sweeper( $fs );

		$stale_token = dechex( time() - 90000 ) . '-eeeeeeeeeeee'; // ~25 hours old.
		$stale_dir   = Babilim_Paths::incoming_dir( $stale_token );
		$fs->plant_disallowed( $stale_dir . '/evil.php' );

		$removed = $sweeper->sweep_stale_incoming_dirs();

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
		$sweeper = $this->new_sweeper( $fs );

		$removed = $sweeper->sweep_stale_incoming_dirs();

		$this->assertSame( 0, $removed );
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
		$sweeper = $this->new_sweeper( $fs );

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
		// docblock), only a foreign process, which writes
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

		$this->assertSame( 2, $sweeper->sweep_stale_incoming_dirs() );

		foreach ( $files as $file ) {
			$this->assertFalse( file_exists( $file ), "$file must really be gone from disk." );
		}
		$this->assertFalse( file_exists( $flat_dir ) );
		$this->assertFalse( file_exists( $nested_dir ) );
		$this->assertTrue( is_dir( Babilim_Paths::incoming_root() ), '_incoming itself is never removed.' );
	}

	/**
	 * A `.php` file left under a stale _incoming/{token}/ by a
	 * foreign process must be removable: the write-time extension
	 * allow-list must never apply to deletion. Runs through the
	 * production Babilim_Wp_Fs on real disk (the in-memory double has its
	 * own separate copy of this rule), asserted with PHP's own
	 * file_exists().
	 */
	public function test_sweep_removes_a_php_file_left_behind_on_real_disk(): void {
		$fs      = new Babilim_Wp_Fs();
		$sweeper = $this->new_sweeper( $fs );

		$dir  = Babilim_Paths::incoming_dir( dechex( time() - 90000 ) . '-cccccccccccc' );
		$file = $dir . '/leftover.php';
		mkdir( $dir, 0777, true );
		$this->assertNotFalse( file_put_contents( $file, '<?php // left by core' ) );

		$this->assertSame( 1, $sweeper->sweep_stale_incoming_dirs() );

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
		$sweeper = $this->new_sweeper( $fs );

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

			$this->assertSame( 1, $sweeper->sweep_stale_incoming_dirs() );

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
		$fs      = new Babilim_Test_Extra_Entry_Fs( $inner, Babilim_Paths::incoming_root(), $stale . '-a/../../plugins' );
		$sweeper = $this->new_sweeper( $fs );

		$victim  = Babilim_Paths::dir( 'plugins' ) . '/woocommerce-zh_TW.mo';
		$old_dir = Babilim_Paths::incoming_dir( $stale . '-ffffffffffff' );
		$inner->put( $victim, 'served' );
		// plant_disallowed(), not put() -- a .mo directly under
		// _incoming/ is never something this plugin's own write path
		// puts there (see INCOMING_WRITE_ALLOWED_EXTENSIONS's own
		// docblock).
		$inner->plant_disallowed( $old_dir . '/woocommerce-zh_TW.mo' );

		$this->assertSame( 1, $sweeper->sweep_stale_incoming_dirs() );

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
		$sweeper = $this->new_sweeper( $fs );

		$fresh_file = Babilim_Paths::incoming_dir( dechex( time() ) . '-aaaaaaaaaaaa' ) . '/woocommerce-zh_TW.mo';
		// Raw PHP, not $fs->put() -- see
		// test_sweep_removes_flat_and_nested_non_empty_entries_on_real_disk()
		// for why a .mo directly under _incoming/ is written this way.
		mkdir( dirname( $fresh_file ), 0777, true );
		file_put_contents( $fresh_file, 'in progress' );

		$method = new ReflectionMethod( Babilim_Sync_Installer::class, 'cleanup_incoming_recursive' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $sweeper, Babilim_Paths::incoming_root() . '/' ) );
		$this->assertTrue( is_file( $fresh_file ), 'A fresh token under _incoming must survive.' );
	}

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

/**
 * A Babilim_Fs whose list() of one directory also reports one extra,
 * caller-chosen name that doesn't exist -- used to feed the sweep a name
 * the path guard rejects (e.g. a `..` traversal), which no real
 * directory listing could itself produce here.
 */
final class Babilim_Test_Extra_Entry_Fs implements Babilim_Fs {

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
