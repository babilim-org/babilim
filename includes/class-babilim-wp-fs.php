<?php
/**
 * The real Babilim_Fs implementation, wrapping WordPress's own
 * WP_Filesystem abstraction -- every filesystem write this plugin makes
 * goes through it.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Babilim_Fs that delegates to $wp_filesystem, refusing any path
 * Babilim_Paths::is_allowed() doesn't clear.
 */
final class Babilim_Wp_Fs implements Babilim_Fs {

	/**
	 * Boots WP_Filesystem() if needed and returns the global instance.
	 *
	 * @return object The $wp_filesystem instance.
	 * @throws RuntimeException If WP_Filesystem() couldn't initialize
	 *                          (e.g. a host that needs FTP credentials).
	 */
	private function fs() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			throw new RuntimeException( 'Babilim_Wp_Fs: WP_Filesystem() could not be initialized.' );
		}
		return $wp_filesystem;
	}

	/**
	 * Throws if $path isn't under Babilim_Paths' allow-list.
	 *
	 * @param string $path      Path to check.
	 * @param bool   $for_write Passed straight through to
	 *                          Babilim_Paths::is_allowed() -- see that
	 *                          method's own docblock for what it changes
	 *                          (only the `_incoming/{token}/` server-
	 *                          executable-extension denylist). Every
	 *                          caller below states, at its own call site,
	 *                          which this operation is: `true` for
	 *                          anything that can leave a NEW file/
	 *                          directory at $path (put(), mkdir(), a
	 *                          rename()/atomic_rename() destination),
	 *                          `false` for a read or a removal (get(),
	 *                          exists(), list(), is_dir(), delete(),
	 *                          rmdir(), a rename()/atomic_rename()
	 *                          source) -- delete()/rmdir() in particular
	 *                          must be able to remove ANY extension
	 *                          `_incoming/` ends up holding, `.php`
	 *                          included, or a sweep can never clean one
	 *                          back out (see Babilim_Sync_Installer::
	 *                          cleanup_incoming_recursive()).
	 * @throws InvalidArgumentException If the path isn't allowed.
	 */
	private function assert_allowed( string $path, bool $for_write ): void {
		if ( ! Babilim_Paths::is_allowed( $path, $for_write ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $path is never raw user input here; every caller builds it from Babilim_Paths' own allow-listed construction. This message is a programmer-facing diagnostic for what this guard treats as an internal-bug safety net, never rendered as page output.
			throw new InvalidArgumentException( "Path not allowed: {$path}" );
		}
	}

	public function put( string $path, string $contents ): bool {
		$this->assert_allowed( $path, true );
		$this->mkdir( dirname( $path ) );
		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : false;
		return (bool) $this->fs()->put_contents( $path, $contents, $mode );
	}

	/**
	 * @param string $path Absolute path.
	 * @return string|WP_Error|null
	 */
	public function get( string $path ) {
		$this->assert_allowed( $path, false );
		if ( ! $this->fs()->exists( $path ) ) {
			return null;
		}
		$contents = $this->fs()->get_contents( $path );
		if ( false === $contents ) {
			return new WP_Error( 'babilim_fs_read_failed', sprintf( /* translators: %s: the file path that could not be read. */ __( 'Could not read: %s', 'babilim' ), $path ) );
		}
		return $contents;
	}

	public function exists( string $path ): bool {
		$this->assert_allowed( $path, false );
		return (bool) $this->fs()->exists( $path );
	}

	public function delete( string $path ): bool {
		$this->assert_allowed( $path, false );
		if ( ! $this->fs()->exists( $path ) ) {
			return true;
		}
		return (bool) $this->fs()->delete( $path );
	}

	public function rename( string $from, string $to ): bool {
		$this->assert_allowed( $from, false );
		$this->assert_allowed( $to, true );
		// $to's own directory may not exist yet -- the very first file
		// ever served under a given kind/domain/locale (e.g. the first
		// plugin translation this site has ever installed) renames into
		// a `plugins/`/`themes/` directory nothing has written into
		// before. put() already creates its own target directory before
		// writing; rename() must do the same before moving, rather than
		// assuming some earlier put() under the same directory already
		// created it.
		$this->mkdir( dirname( $to ) );
		return (bool) $this->fs()->move( $from, $to, true );
	}

	/**
	 * The same operation as rename(), except when the real, underlying
	 * WP_Filesystem transfer is `direct` (the common case -- PHP itself
	 * has ordinary write permission to disk; FTP/SSH2 transports exist
	 * for when it doesn't): PHP's own native rename() is used instead of
	 * $wp_filesystem->move(). WordPress core's own
	 * WP_Filesystem_Direct::move() DELETES $to before renaming $from
	 * into place -- confirmed byte-identical across WordPress 6.6.9/
	 * 6.8.10/7.1.2's own core source -- a real window where $to doesn't
	 * exist at all, which Babilim_Manifest's own cross-request-safe
	 * write cannot tolerate (see that class's own save() docblock).
	 * PHP's native rename(), on the same filesystem (guaranteed here:
	 * both paths always live under Babilim_Paths::root()), overwrites
	 * $to in one atomic operation with no such window.
	 *
	 * A non-`direct` transfer has no local filesystem path for PHP's own
	 * rename() to operate on at all -- the file genuinely only exists on
	 * a remote FTP/SSH2 host -- so move() is the only option there;
	 * Babilim_Manifest's own cross-request lock is what makes that safe
	 * in that case, not this method.
	 *
	 * Not part of the Babilim_Fs interface -- deliberately specific to
	 * this one real implementation and its own single caller
	 * (Babilim_Manifest). Every Babilim_Fs test double in this repo has
	 * no delete-then-rename behavior to begin with (a single in-memory
	 * operation, or an intentionally-scripted fake for one specific
	 * test), so there would be nothing for a parallel method to improve
	 * on there.
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 * @return bool True on success.
	 */
	public function atomic_rename( string $from, string $to ): bool {
		$this->assert_allowed( $from, false );
		$this->assert_allowed( $to, true );
		$this->mkdir( dirname( $to ) );
		$fs = $this->fs();
		if ( 'direct' === ( $fs->method ?? '' ) ) {
			return @rename( $from, $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.rename_rename -- a real failure (e.g. a cross-device move, which can't happen here since both paths share Babilim_Paths::root()) still returns false, checked by the caller the same as every other write in this class; WP_Filesystem::move() is deliberately not used here -- see this method's own docblock for the real delete-then-rename race in WP_Filesystem_Direct::move() that native rename() avoids.
		}
		return (bool) $fs->move( $from, $to, true );
	}

	public function mkdir( string $path ): bool {
		$this->assert_allowed( $path, true );
		$mode = defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : false;
		$ok   = true;

		$lang_dir = rtrim( dirname( Babilim_Paths::root() ), '/' );
		if ( ! $this->fs()->is_dir( $lang_dir ) ) {
			// The one directory outside the allow-list this plugin may create: WP_LANG_DIR itself, on a site that has never installed a language.
			$ok = (bool) $this->fs()->mkdir( $lang_dir, $mode );
		}
		foreach ( Babilim_Paths::ancestors_from_root( $path ) as $ancestor ) {
			if ( $this->fs()->is_dir( $ancestor ) ) {
				continue;
			}
			$ok = $this->fs()->mkdir( $ancestor, $mode ) && $ok;
		}
		return $ok;
	}

	public function is_dir( string $path ): bool {
		$this->assert_allowed( $path, false );
		return (bool) $this->fs()->is_dir( $path );
	}

	public function list( string $dir, string $glob = '*' ): array {
		$this->assert_allowed( $dir, false );
		$entries = $this->fs()->dirlist( $dir );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		$matches = array();
		foreach ( array_keys( $entries ) as $name ) {
			if ( fnmatch( $glob, $name ) ) {
				$matches[] = $name;
			}
		}
		sort( $matches );
		return $matches;
	}

	/**
	 * @param string $path Absolute path.
	 * @return string|WP_Error|null
	 */
	public function sha256( string $path ) {
		$contents = $this->get( $path );
		if ( ! is_string( $contents ) ) {
			return $contents;
		}
		return hash( 'sha256', $contents );
	}

	public function rmdir( string $path ): bool {
		$this->assert_allowed( $path, false );
		if ( ! $this->fs()->is_dir( $path ) ) {
			return true; // Already absent (or never a directory).
		}
		// $recursive = false: WP_Filesystem's own contract for this is a
		// plain (non-recursive) directory removal, which fails rather
		// than deletes anything when the directory still has contents --
		// exactly the "only if already empty" guarantee this method's
		// own interface docblock promises. Not verified against a real
		// WordPress install.
		return (bool) $this->fs()->delete( $path, false, 'd' );
	}
}
