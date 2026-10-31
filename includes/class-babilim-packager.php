<?php
/**
 * Packs an installed plugin's or theme's own directory into a zip, in a
 * private staging directory outside the site, so it can be uploaded to
 * the Babilim service for translation (components not listed on
 * WordPress.org). The plugin only moves bytes: nothing here filters
 * files, hashes anything or decides whether an upload is needed -- the
 * service does all of that.
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

	/** @var callable */
	private $sys_temp_dir;
	/** @var callable */
	private $wp_temp_dir;
	/** @var callable */
	private $random_hex;
	private string $plugin_root;
	private string $theme_root;
	/** @var callable */
	private $theme_dir;
	/** @var callable */
	private $zip_factory;

	/**
	 * @param callable|null $theme_dir   fn( string $stylesheet ): ?array{dir:string,root:string} -- where WordPress says the theme lives and which theme root it belongs to; null when WordPress does not know it.
	 * @param callable|null $zip_factory fn(): ?ZipArchive -- null when the zip extension is missing.
	 */
	public function __construct( ?callable $sys_temp_dir = null, ?callable $wp_temp_dir = null, ?callable $random_hex = null, ?string $plugin_root = null, ?string $theme_root = null, ?callable $theme_dir = null, ?callable $zip_factory = null ) {
		$this->sys_temp_dir = $sys_temp_dir ?? 'sys_get_temp_dir';
		$this->wp_temp_dir  = $wp_temp_dir ?? 'get_temp_dir';
		$this->random_hex   = $random_hex ?? static function (): string {
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
			return Babilim_Result::error( 'packager_no_temp_dir', __( 'No writable temporary directory is available for packaging.', 'babilim' ) );
		}
		$zip_path = $dir . '/package.zip';
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			$this->discard( $dir );
			return Babilim_Result::error( 'packager_zip_failed', __( 'Could not create the package archive.', 'babilim' ) );
		}
		$added = is_dir( $source ) ? $this->add_tree( $zip, $source, $prefix ) : $this->add_file( $zip, $source, $prefix );
		if ( ! $added ) {
			$zip->close();
			$this->discard( $dir );
			return Babilim_Result::error( 'packager_read_failed', __( 'A file in the component could not be read, so it was not packaged.', 'babilim' ) );
		}
		// ZipArchive reads the files only now, so a file that vanished or a
		// full disk shows up here. close() is called exactly once.
		if ( ! $zip->close() ) {
			$this->discard( $dir );
			return Babilim_Result::error( 'packager_zip_failed', __( 'Could not create the package archive.', 'babilim' ) );
		}
		return Babilim_Result::ok( array( 'zip' => $zip_path, 'size' => (int) filesize( $zip_path ), 'dir' => $dir ) );
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

	private function add_tree( \ZipArchive $zip, string $dir, string $prefix ): bool {
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
				if ( ! $this->add_tree( $zip, $path, $prefix . '/' . $name ) ) {
					return false;
				}
			} elseif ( ! $this->add_file( $zip, $path, $prefix . '/' . $name ) ) {
				return false;
			}
		}
		return true;
	}

	private function add_file( \ZipArchive $zip, string $path, string $name ): bool {
		return is_readable( $path ) && $zip->addFile( $path, $name );
	}

	private function staging_dir(): ?string {
		foreach ( array( $this->candidate( (string) call_user_func( $this->sys_temp_dir ), true ), $this->candidate( (string) call_user_func( $this->wp_temp_dir ), false ) ) as $base ) {
			if ( null === $base ) {
				continue;
			}
			$dir = $base . '/' . self::PREFIX . call_user_func( $this->random_hex );
			if ( ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- source packaging staging dir, the one write outside the language directory
				continue;
			}
			file_put_contents( $dir . '/.htaccess', "Deny from all\nRequire all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- source packaging staging dir, the one write outside the language directory
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- source packaging staging dir, the one write outside the language directory
			return $dir;
		}
		return null;
	}

	/** A usable temp base: writable, and (for the system temp dir) not under ABSPATH or WP_CONTENT_DIR. */
	private function candidate( string $base, bool $must_be_outside_site ): ?string {
		$base = rtrim( $base, '/' );
		$real = realpath( $base );
		if ( false === $real || ! is_dir( $real ) || ! wp_is_writable( $real ) ) {
			return null;
		}
		if ( $must_be_outside_site ) {
			foreach ( array( defined( 'ABSPATH' ) ? ABSPATH : '', defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) as $site ) {
				$site_real = '' !== $site ? realpath( $site ) : false;
				if ( false !== $site_real && ( $real === $site_real || 0 === strpos( $real, rtrim( $site_real, '/' ) . '/' ) ) ) {
					return null;
				}
			}
		}
		return $real;
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

	/** Removes staging directories older than $max_age_seconds under both temp bases. Returns how many. */
	public function sweep( int $max_age_seconds = 86400 ): int {
		$removed = 0;
		foreach ( array( $this->candidate( (string) call_user_func( $this->sys_temp_dir ), true ), $this->candidate( (string) call_user_func( $this->wp_temp_dir ), false ) ) as $base ) {
			if ( null === $base ) {
				continue;
			}
			foreach ( glob( $base . '/' . self::PREFIX . '*', GLOB_ONLYDIR ) ?: array() as $dir ) {
				if ( self::owns( $dir ) && ! is_link( $dir ) && filemtime( $dir ) < time() - $max_age_seconds ) {
					$this->discard( $dir );
					++$removed;
				}
			}
		}
		return $removed;
	}
}
