<?php
/**
 * In-memory Babilim_Fs test double -- never shipped in the release zip
 * (it lives under tests/, which .distignore excludes wholesale), used
 * so plugin unit tests never need real disk access to exercise the
 * paths/manifest logic.
 *
 * Enforces Babilim_Paths::is_allowed() exactly like the real
 * Babilim_Wp_Fs, so a test exercising that guard doesn't need to touch
 * disk to prove it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A plain in-memory Babilim_Fs, backed by arrays instead of disk.
 */
final class Babilim_Memory_Fs implements Babilim_Fs {

	/**
	 * Path => contents.
	 *
	 * @var array<string,string>
	 */
	private $files = array();

	/**
	 * Every directory path ever created, including implied ancestors.
	 *
	 * @var array<string,bool>
	 */
	private $dirs = array();

	/**
	 * Throws if $path isn't under Babilim_Paths' allow-list. Mirrors
	 * Babilim_Wp_Fs::assert_allowed()'s own $for_write parameter exactly
	 * -- see that method's own docblock for what it changes and why
	 * every call site below states its own operation's shape.
	 *
	 * @param string $path      Path to check.
	 * @param bool   $for_write Passed straight through to
	 *                          Babilim_Paths::is_allowed().
	 * @throws InvalidArgumentException If the path isn't allowed.
	 */
	private function assert_allowed( string $path, bool $for_write ): void {
		if ( ! Babilim_Paths::is_allowed( $path, $for_write ) ) {
			throw new InvalidArgumentException( "Path not allowed: {$path}" );
		}
	}

	public function put( string $path, string $contents ): bool {
		$this->assert_allowed( $path, true );
		$this->files[ $path ] = $contents;
		$this->mkdir( dirname( $path ) );
		return true;
	}

	/**
	 * Places a file without the allow-list check, the way a stray file
	 * of an unknown type can turn up on a real disk: list() shows it,
	 * but every Babilim_Fs call naming it is refused. Registers every
	 * ancestor directory from root() down to the file's own parent, not
	 * just the immediate one -- on a real disk, list() on ANY ancestor
	 * would already show the next directory down regardless of how deep
	 * the stray file itself is nested; this in-memory double needs that
	 * spelled out explicitly instead.
	 *
	 * @param string $path Absolute path.
	 */
	public function plant_disallowed( string $path ): void {
		$this->files[ $path ] = 'x';
		foreach ( Babilim_Paths::ancestors_from_root( dirname( $path ) ) as $ancestor ) {
			$this->dirs[ $ancestor ] = true;
		}
	}

	public function get( string $path ): ?string {
		$this->assert_allowed( $path, false );
		return $this->files[ $path ] ?? null;
	}

	public function exists( string $path ): bool {
		$this->assert_allowed( $path, false );
		return array_key_exists( $path, $this->files ) || array_key_exists( $path, $this->dirs );
	}

	public function delete( string $path ): bool {
		$this->assert_allowed( $path, false );
		unset( $this->files[ $path ] );
		return true;
	}

	public function rename( string $from, string $to ): bool {
		$this->assert_allowed( $from, false );
		$this->assert_allowed( $to, true );
		if ( ! array_key_exists( $from, $this->files ) ) {
			return false;
		}
		$this->files[ $to ] = $this->files[ $from ];
		unset( $this->files[ $from ] );
		$this->mkdir( dirname( $to ) );
		return true;
	}

	public function mkdir( string $path ): bool {
		$this->assert_allowed( $path, true );
		foreach ( Babilim_Paths::ancestors_from_root( $path ) as $ancestor ) {
			$this->dirs[ $ancestor ] = true;
		}
		return true;
	}

	public function list( string $dir, string $glob = '*' ): array {
		$this->assert_allowed( $dir, false );
		$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
		// A file path lists just that one file, by its own basename --
		// the same contract as WordPress core's own
		// WP_Filesystem_Direct::dirlist(), which Babilim_Wp_Fs::list()
		// reads. Never [] for an existing file.
		if ( array_key_exists( $dir, $this->files ) ) {
			return fnmatch( $glob, basename( $dir ) ) ? array( basename( $dir ) ) : array();
		}
		$matches = array();
		foreach ( array_keys( $this->files ) as $path ) {
			if ( dirname( $path ) !== $dir ) {
				continue;
			}
			$basename = basename( $path );
			if ( fnmatch( $glob, $basename ) ) {
				$matches[] = $basename;
			}
		}
		// Immediate child directories too -- a real dirlist() (what
		// Babilim_Wp_Fs::list() itself reads) shows a subdirectory as an
		// entry regardless of whether it holds a file directly, and
		// mkdir()/put() already track every ancestor directory in
		// $this->dirs (see mkdir()'s own use of
		// Babilim_Paths::ancestors_from_root()), so this needs no
		// separate bookkeeping of its own.
		$prefix = $dir . '/';
		foreach ( array_keys( $this->dirs ) as $known_dir ) {
			if ( 0 !== strpos( $known_dir, $prefix ) ) {
				continue;
			}
			$relative = substr( $known_dir, strlen( $prefix ) );
			if ( '' === $relative || false !== strpos( $relative, '/' ) ) {
				continue; // Not an immediate child.
			}
			if ( fnmatch( $glob, $relative ) ) {
				$matches[] = $relative;
			}
		}
		$matches = array_unique( $matches );
		sort( $matches );
		return $matches;
	}

	public function sha256( string $path ): ?string {
		$this->assert_allowed( $path, false );
		if ( ! array_key_exists( $path, $this->files ) ) {
			return null;
		}
		return hash( 'sha256', $this->files[ $path ] );
	}

	/**
	 * Every file currently held, path => contents, sorted by path -- lets
	 * a test assert that an operation wrote, changed or deleted nothing.
	 *
	 * @return array<string,string>
	 */
	public function snapshot(): array {
		$files = $this->files;
		ksort( $files, SORT_STRING );
		return $files;
	}

	public function is_dir( string $path ): bool {
		$this->assert_allowed( $path, false );
		return array_key_exists( rtrim( $path, '/' ), $this->dirs );
	}

	public function rmdir( string $path ): bool {
		$this->assert_allowed( $path, false );
		if ( ! array_key_exists( $path, $this->dirs ) ) {
			return true; // Already absent.
		}
		$prefix = rtrim( $path, '/' ) . '/';
		foreach ( array_keys( $this->files ) as $file_path ) {
			if ( 0 === strpos( $file_path, $prefix ) ) {
				return false; // Still has a file in it.
			}
		}
		foreach ( array_keys( $this->dirs ) as $dir_path ) {
			if ( $dir_path !== $path && 0 === strpos( $dir_path, $prefix ) ) {
				return false; // Still has a subdirectory in it.
			}
		}
		unset( $this->dirs[ $path ] );
		return true;
	}
}
