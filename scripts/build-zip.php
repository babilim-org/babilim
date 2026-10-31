<?php
/**
 * Builds the wp.org-style plugin zip (babilim-<version>/...) from git's
 * own tracked-file list, filtered through .distignore, using
 * ZipArchive rather than an external `zip` binary.
 *
 * Usage: php scripts/build-zip.php
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
chdir( $root );

$readme = file_get_contents( $root . '/readme.txt' );
if ( false === $readme || ! preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $matches ) ) {
	fwrite( STDERR, "Could not read \"Stable tag\" from readme.txt\n" );
	exit( 1 );
}
$readme_version = $matches[1];

$plugin_main = file_get_contents( $root . '/babilim.php' );
if ( false === $plugin_main || ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', $plugin_main, $matches ) ) {
	fwrite( STDERR, "Could not read the \"Version:\" header from babilim.php\n" );
	exit( 1 );
}
$header_version = $matches[1];

if ( ! preg_match( '/define\(\s*\'BABILIM_VERSION\',\s*\'([^\']+)\'\s*\)/', $plugin_main, $matches ) ) {
	fwrite( STDERR, "Could not read the BABILIM_VERSION constant from babilim.php\n" );
	exit( 1 );
}
$constant_version = $matches[1];

if ( $readme_version !== $header_version || $readme_version !== $constant_version ) {
	fwrite( STDERR, "Refusing to build: version numbers disagree\n" );
	fwrite( STDERR, "  readme.txt \"Stable tag\":        $readme_version\n" );
	fwrite( STDERR, "  babilim.php \"Version:\" header:  $header_version\n" );
	fwrite( STDERR, "  babilim.php BABILIM_VERSION:    $constant_version\n" );
	exit( 1 );
}
$version = $readme_version;

$tracked = shell_exec( 'git ls-files' );
if ( ! is_string( $tracked ) || '' === trim( $tracked ) ) {
	fwrite( STDERR, "git ls-files failed\n" );
	exit( 1 );
}
$files = array_filter( explode( "\n", trim( $tracked ) ) );

$distignore_lines = file( $root . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
if ( false === $distignore_lines ) {
	fwrite( STDERR, "Could not read .distignore\n" );
	exit( 1 );
}
$patterns = array();
foreach ( $distignore_lines as $line ) {
	$line = trim( $line );
	if ( '' === $line || 0 === strpos( $line, '#' ) ) {
		continue;
	}
	$patterns[] = $line;
}

/**
 * Whether a git-relative path is excluded by one of .distignore's
 * patterns. Patterns are matched the way .distignore's own entries are
 * written: an exact top-level name, a `dir/`-style directory (matches
 * itself and everything under it), or a `*.ext`-style glob against the
 * basename.
 *
 * @param string   $relpath  Path as `git ls-files` prints it.
 * @param string[] $patterns Lines from .distignore.
 */
function babilim_dist_excluded( string $relpath, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		if ( false !== strpos( $pattern, '*' ) ) {
			if ( fnmatch( $pattern, basename( $relpath ) ) ) {
				return true;
			}
			continue;
		}

		$dir = rtrim( $pattern, '/' );
		if ( $relpath === $dir || 0 === strpos( $relpath, $dir . '/' ) ) {
			return true;
		}
	}
	return false;
}

$slug      = 'babilim';
$build_dir = $root . '/build';
if ( ! is_dir( $build_dir ) && ! mkdir( $build_dir, 0777, true ) && ! is_dir( $build_dir ) ) {
	fwrite( STDERR, "Could not create $build_dir\n" );
	exit( 1 );
}

$zip_path = $build_dir . '/' . $slug . '-' . $version . '.zip';
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create $zip_path\n" );
	exit( 1 );
}

$included = 0;
foreach ( $files as $relpath ) {
	if ( babilim_dist_excluded( $relpath, $patterns ) ) {
		continue;
	}
	$full = $root . '/' . $relpath;
	if ( ! is_file( $full ) ) {
		continue;
	}
	$zip->addFile( $full, $slug . '/' . $relpath );
	++$included;
}
$zip->close();

if ( 0 === $included ) {
	fwrite( STDERR, "Refusing to ship an empty zip\n" );
	unlink( $zip_path );
	exit( 1 );
}

echo "Built $zip_path ($included files)\n";
