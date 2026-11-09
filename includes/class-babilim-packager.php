<?php
/**
 * Packs an installed plugin's or theme's own directory into a zip, in a
 * private staging directory outside the site, so it can be uploaded to
 * the Babilim service for translation (components not listed on
 * WordPress.org). It packs only the listed code and description files
 * (see wanted()), applying the same sensitive-name rule as the service
 * before anything leaves the site. Whether an upload is needed is
 * decided by the service.
 *
 * This class is the one place in the plugin that writes outside
 * `WP_LANG_DIR/babilim/` (its single, narrow exception): only the
 * staging directory it creates itself, only a zip plus its two guard
 * files, removed as soon as the upload ends and swept daily.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Babilim_Packager {

	private const PREFIX = 'babilim-upload-';

	/** Packaging policy version; a pending upload built under another one is not reused. */
	public const POLICY = 2;

	private const ALLOWED_EXTENSIONS      = array( 'php', 'js' );
	private const ALLOWED_NAMES_ANY_DEPTH = array( 'block.json' );
	private const ALLOWED_NAMES_ROOT_ONLY = array( 'readme.txt', 'readme.md', 'style.css', 'theme.json' );
	private const EXCLUDED_DIRS           = array( 'uploads', 'cache', 'caches', 'logs', 'log', 'backup', 'backups', 'tmp', 'temp', 'data', 'vendor', 'node_modules', 'languages' );
	// Identical to the service's sensitive-file rule; a test pins the list.
	private const SENSITIVE_NAME_PATTERNS = array( 'wp-config*', '.env*', '*.sql', '*.log', '*.pem', '*.key', '*.p12', 'id_rsa*' );
	private const EXCLUDED_NAME_PATTERNS  = array( '*.l10n.php', '*.bak' );

	/** @var callable */
	private $sys_temp_dir;
	/** @var callable */
	private $host_temp_dir;
	/** @var callable */
	private $random_hex;
	private string $plugin_root;
	private string $theme_root;
	/** @var callable */
	private $theme_dir;
	/** @var callable */
	private $zip_factory;
	/** @var callable */
	private $site_roots;

	/**
	 * @param callable|null $sys_temp_dir  fn(): string -- the system temporary directory.
	 * @param callable|null $host_temp_dir fn(): string -- the folder the host names in BABILIM_TEMP_DIR; '' when none.
	 * @param callable|null $theme_dir     fn( string $stylesheet ): ?array{dir:string,root:string} -- where WordPress says the theme lives and which theme root it belongs to; null when WordPress does not know it.
	 * @param callable|null $zip_factory   fn(): ?ZipArchive -- null when the zip extension is missing.
	 * @param callable|null $site_roots    fn(): array -- the ABSPATH and WP_CONTENT_DIR paths a staging folder must lie outside of (test-only; defaults to the two constants).
	 */
	public function __construct( ?callable $sys_temp_dir = null, ?callable $host_temp_dir = null, ?callable $random_hex = null, ?string $plugin_root = null, ?string $theme_root = null, ?callable $theme_dir = null, ?callable $zip_factory = null, ?callable $site_roots = null ) {
		$this->sys_temp_dir  = $sys_temp_dir ?? 'sys_get_temp_dir';
		$this->host_temp_dir = $host_temp_dir ?? static function (): string {
			return defined( 'BABILIM_TEMP_DIR' ) ? (string) BABILIM_TEMP_DIR : '';
		};
		$this->site_roots    = $site_roots ?? static function (): array {
			return array( defined( 'ABSPATH' ) ? ABSPATH : '', defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );
		};
		$this->random_hex    = $random_hex ?? static function (): string {
			return bin2hex( random_bytes( 16 ) );
		};
		$this->plugin_root = rtrim( $plugin_root ?? ( defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '' ), '/' );
		$this->theme_root  = rtrim( $theme_root ?? ( function_exists( 'get_theme_root' ) ? (string) get_theme_root() : '' ), '/' );
		$this->theme_dir   = $theme_dir ?? static function ( string $stylesheet ): ?array {
			if ( ! function_exists( 'wp_get_theme' ) ) {
				return null;
			}
			$theme = wp_get_theme( $stylesheet );
			if ( ! $theme->exists() ) {
				return null;
			}
			return array(
				'dir'  => (string) $theme->get_stylesheet_directory(),
				'root' => (string) $theme->get_theme_root(),
			);
		};
		$this->zip_factory = $zip_factory ?? static function (): ?\ZipArchive {
			return class_exists( 'ZipArchive' ) ? new \ZipArchive() : null;
		};
	}

	/**
	 * @param array $component An inventory entry (kind, slug, main_file|stylesheet).
	 * @return Babilim_Result ok({zip, size, dir}) or an error.
	 */
	public function pack( array $component ): Babilim_Result {
		$located = $this->locate( $component );
		if ( $located->is_error() ) {
			return $located;
		}
		$source = (string) $located->get( 'path' );
		$prefix = (string) $located->get( 'prefix' );
		// Before any directory is created: without the zip extension there
		// is nothing to clean up.
		$zip = call_user_func( $this->zip_factory );
		if ( null === $zip ) {
			return Babilim_Result::error( 'packager_zip_failed', __( 'Could not create the package archive.', 'babilim' ) );
		}
		$dir = $this->staging_dir();
		if ( null === $dir ) {
			return Babilim_Result::error( 'packager_no_temp_dir', __( 'Babilim could not confirm a temporary folder outside the website, so the component was not packaged and nothing was uploaded. Define BABILIM_TEMP_DIR in wp-config.php as a writable folder outside the web root (for example a folder next to, not inside, your site\'s public directory), or ask your host for one.', 'babilim' ) );
		}
		$zip_path = $dir . '/package.zip';
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			$this->discard( $dir );
			return Babilim_Result::error( 'packager_zip_failed', __( 'Could not create the package archive.', 'babilim' ) );
		}
		if ( is_dir( $source ) ) {
			$added = $this->add_tree( $zip, $source, $prefix );
		} else {
			$added = ! self::wanted( basename( $source ), false, true ) || $this->add_file( $zip, $source, $prefix );
		}
		if ( ! $added ) {
			$zip->close();
			$this->discard( $dir );
			return Babilim_Result::error( 'packager_read_failed', __( 'A file in the component could not be read, so it was not packaged.', 'babilim' ) );
		}
		// An empty archive is never written to disk by close(), so there
		// would be nothing to hash or upload.
		if ( 0 === $zip->numFiles ) {
			$zip->close();
			$this->discard( $dir );
			return Babilim_Result::error( 'packager_nothing_to_pack', __( 'The component has no files that can be uploaded.', 'babilim' ) );
		}
		// ZipArchive reads the files only now, so a file that vanished or a
		// full disk shows up here. close() is called exactly once.
		if ( ! $zip->close() ) {
			$this->discard( $dir );
			return Babilim_Result::error( 'packager_zip_failed', __( 'Could not create the package archive.', 'babilim' ) );
		}
		return Babilim_Result::ok(
			array(
				'zip'    => $zip_path,
				'size'   => (int) filesize( $zip_path ),
				'dir'    => $dir,
				'policy' => self::POLICY,
				'sha256' => (string) hash_file( 'sha256', $zip_path ),
			)
		);
	}

	private function locate( array $component ): Babilim_Result {
		$kind = (string) ( $component['kind'] ?? '' );
		if ( 'plugin' === $kind ) {
			$main = (string) ( $component['main_file'] ?? '' );
			$root = $this->plugin_root;
			$path = $root . '/' . $main;
			$dir  = dirname( $main );
			if ( '.' === $dir || '' === $dir ) {
				$target = $path;                  // single-file plugin: only this file
				$prefix = basename( $main );
			} else {
				$target = $root . '/' . $dir;
				$prefix = basename( $dir );
			}
		} elseif ( 'theme' === $kind ) {
			$root       = $this->theme_root;
			$stylesheet = (string) ( $component['stylesheet'] ?? $component['slug'] ?? '' );
			$target     = $root . '/' . $stylesheet;
			// The theme's own directory as WordPress reports it, checked
			// against the theme root that same theme belongs to (a theme
			// may live under a root added with register_theme_directory()).
			$known = '' !== $stylesheet ? call_user_func( $this->theme_dir, $stylesheet ) : null;
			if ( is_array( $known ) && '' !== (string) ( $known['dir'] ?? '' ) && '' !== (string) ( $known['root'] ?? '' ) ) {
				$root   = rtrim( (string) $known['root'], '/' );
				$target = rtrim( (string) $known['dir'], '/' );
			}
			$path   = $target . '/style.css';
			$prefix = basename( $stylesheet );
		} else {
			return Babilim_Result::error( 'packager_unsupported_kind', __( 'Only plugins and themes can be packaged.', 'babilim' ) );
		}
		$real_root   = realpath( $root );
		$real_target = realpath( $target );
		if ( false === $real_root || false === $real_target ) {
			return Babilim_Result::error( 'packager_no_main_file', __( 'The component\'s main file was not found.', 'babilim' ) );
		}
		// Containment is judged before the main file's existence, so a
		// path that climbs out of the root is always reported as such.
		if ( 0 !== strpos( $real_target, $real_root . '/' ) ) {
			return Babilim_Result::error( 'packager_outside_root', __( 'The component is not inside the plugins or themes directory.', 'babilim' ) );
		}
		$real_path = realpath( $path );
		if ( false === $real_path || ( $real_path !== $real_target && 0 !== strpos( $real_path, $real_target . '/' ) ) ) {
			return Babilim_Result::error( 'packager_no_main_file', __( 'The component\'s main file was not found.', 'babilim' ) );
		}
		return Babilim_Result::ok( array( 'path' => $real_target, 'prefix' => $prefix ) );
	}

	/**
	 * Whether an entry is packed. Judged on the entry's own name only, in
	 * lower case, like the service's sensitive-file rule.
	 */
	private static function wanted( string $name, bool $is_dir, bool $at_root ): bool {
		$lower = strtolower( $name );
		if ( '' === $lower || '.' === $lower[0] ) {
			return false;
		}
		if ( $is_dir ) {
			return ! in_array( $lower, self::EXCLUDED_DIRS, true );
		}
		foreach ( array_merge( self::SENSITIVE_NAME_PATTERNS, self::EXCLUDED_NAME_PATTERNS ) as $pattern ) {
			if ( fnmatch( $pattern, $lower, FNM_CASEFOLD ) ) {
				return false;
			}
		}
		if ( in_array( pathinfo( $lower, PATHINFO_EXTENSION ), self::ALLOWED_EXTENSIONS, true ) ) {
			return true;
		}
		if ( in_array( $lower, self::ALLOWED_NAMES_ANY_DEPTH, true ) ) {
			return true;
		}
		return $at_root && in_array( $lower, self::ALLOWED_NAMES_ROOT_ONLY, true );
	}

	private function add_tree( \ZipArchive $zip, string $dir, string $prefix, bool $at_root = true ): bool {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return false;
		}
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_link( $path ) ) {
				continue;                          // never followed, never included
			}
			if ( is_dir( $path ) ) {
				if ( self::wanted( $name, true, $at_root ) && ! $this->add_tree( $zip, $path, $prefix . '/' . $name, false ) ) {
					return false;
				}
			} elseif ( self::wanted( $name, false, $at_root ) && ! $this->add_file( $zip, $path, $prefix . '/' . $name ) ) {
				return false;
			}
		}
		return true;
	}

	private function add_file( \ZipArchive $zip, string $path, string $name ): bool {
		return is_readable( $path ) && $zip->addFile( $path, $name );
	}

	/**
	 * The base a new staging directory would be created in right now, or
	 * null when none can be proven to lie outside the website.
	 */
	public function staging_base(): ?string {
		$base = $this->private_base( (string) call_user_func( $this->sys_temp_dir ), true );
		return $base ?? $this->private_base( (string) call_user_func( $this->host_temp_dir ), false );
	}

	/** Whether a base chosen earlier still lies outside ABSPATH, WP_CONTENT_DIR and (when it resolves) DOCUMENT_ROOT. */
	public function base_still_private( string $base ): bool {
		return null !== $this->private_base( $base, false );
	}

	private function staging_dir(): ?string {
		$base = $this->staging_base();
		if ( null === $base ) {
			return null;
		}
		$dir = $base . '/' . self::PREFIX . call_user_func( $this->random_hex );
		if ( ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- source packaging staging dir, the one write outside the language directory
			return null;
		}
		return $dir;
	}

	/**
	 * The real path of $base when it is a writable directory outside
	 * ABSPATH and WP_CONTENT_DIR and, when DOCUMENT_ROOT is known, outside
	 * it too. With $require_document_root, an unknown DOCUMENT_ROOT means
	 * "cannot prove it" and the base is refused.
	 */
	private function private_base( ?string $base, bool $require_document_root ): ?string {
		$base = rtrim( (string) $base, '/' );
		if ( '' === $base ) {
			return null;
		}
		$real = realpath( $base );
		if ( false === $real || ! is_dir( $real ) || ! wp_is_writable( $real ) ) {
			return null;
		}
		$doc    = $this->document_root();
		$guards = (array) call_user_func( $this->site_roots );
		if ( null !== $doc ) {
			$guards[] = $doc;
		} elseif ( $require_document_root ) {
			return null;
		}
		foreach ( $guards as $guard ) {
			$guard_real = '' !== (string) $guard ? realpath( (string) $guard ) : false;
			if ( false !== $guard_real && ( $real === $guard_real || 0 === strpos( $real, rtrim( $guard_real, '/' ) . '/' ) ) ) {
				return null;
			}
		}
		return $real;
	}

	/**
	 * DOCUMENT_ROOT as a real path, or null when it is missing, not a
	 * string, empty, does not resolve, or sanitizing would change it (a
	 * rewritten value can name a different directory).
	 */
	private function document_root(): ?string {
		if ( ! isset( $_SERVER['DOCUMENT_ROOT'] ) || ! is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			return null;
		}
		$clean = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
		if ( (string) filter_var( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ), FILTER_UNSAFE_RAW ) !== $clean || '' === $clean ) {
			return null;
		}
		$real = realpath( $clean );
		return false !== $real && is_dir( $real ) ? $real : null;
	}

	public static function owns( string $path ): bool {
		return 1 === preg_match( '#/' . self::PREFIX . '[0-9a-f]{32}$#D', rtrim( $path, '/' ) );
	}

	public function discard( string $dir ): void {
		if ( ! self::owns( $dir ) || ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				@unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- source packaging staging dir, the one write outside the language directory
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- source packaging staging dir, the one write outside the language directory
	}

	/**
	 * The real path of the staging directory this site's own pending
	 * upload record points at, or null when the record does not name one
	 * this plugin created (no directory, a symlink, not a babilim-upload-
	 * folder, or gone).
	 *
	 * @param array<string,mixed>|null $pending
	 */
	private function traceable_dir( ?array $pending ): ?string {
		$dir = $pending['dir'] ?? null;
		if ( ! is_string( $dir ) || '' === $dir || is_link( $dir ) ) {
			return null;
		}
		$real = realpath( $dir );
		return false !== $real && self::owns( $real ) && is_dir( $real ) ? $real : null;
	}

	/**
	 * One-time clean-up after an upgrade: removes this site's own pending
	 * upload directory when that upload was packaged by an older version
	 * (the record carries no package_policy). Nothing is scanned: a
	 * directory the record does not point at may belong to another site
	 * still running an older version, so it is never touched.
	 *
	 * @param array<string,mixed>|null $pending The pending upload record.
	 * @return int Directories removed (0 or 1).
	 */
	public function sweep_legacy( ?array $pending ): int {
		if ( null === $pending || isset( $pending['package_policy'] ) ) {
			return 0;
		}
		$real = $this->traceable_dir( $pending );
		if ( null === $real ) {
			return 0;
		}
		$this->discard( $real );
		return is_dir( $real ) ? 0 : 1;
	}

	/**
	 * Daily clean-up, limited to this site's own pending upload directory:
	 * removed when the upload was packaged by an older version or the
	 * service's offer has expired. A sealed, unexpired upload is live and
	 * left alone, as is every other directory in a temp base.
	 *
	 * @param array<string,mixed>|null $pending The pending upload record.
	 * @return int Directories removed (0 or 1).
	 */
	public function sweep( ?array $pending ): int {
		if ( null === $pending ) {
			return 0;
		}
		if ( isset( $pending['package_policy'] ) ) {
			$expires = strtotime( (string) ( $pending['expires_at'] ?? '' ) );
			if ( false === $expires || $expires >= time() ) {
				return 0; // Live (or not provably dead): keep.
			}
		}
		$real = $this->traceable_dir( $pending );
		if ( null === $real ) {
			return 0;
		}
		$this->discard( $real );
		return is_dir( $real ) ? 0 : 1;
	}
}
