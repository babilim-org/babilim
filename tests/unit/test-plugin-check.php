<?php
/**
 * Static checks mirroring what the WordPress.org Plugin Check tool
 * flags in PHP: discouraged or forbidden functions, unescaped
 * superglobals and remote-code patterns. Each discouraged call that is
 * genuinely needed must carry a `phpcs:ignore` naming the rule and a
 * `--` reason on its own line or the line above.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Plugin_Check extends TestCase {

	/**
	 * Function names Plugin Check reports (as errors or warnings) when
	 * called directly from a plugin.
	 *
	 * @var string[]
	 */
	private const DISCOURAGED = array(
		'parse_url',
		'file_get_contents',
		'file_put_contents',
		'fopen',
		'fwrite',
		'fclose',
		'unlink',
		'chmod',
		'touch',
		'mkdir',
		'rmdir',
		'rename',
		'copy',
		'readfile',
		'base64_encode',
		'base64_decode',
		'var_export',
		'var_dump',
		'print_r',
		'error_log',
		'serialize',
		'unserialize',
		'eval',
		'exec',
		'shell_exec',
		'system',
		'passthru',
		'proc_open',
		'popen',
		'curl_init',
		'curl_exec',
		'fsockopen',
		'mt_rand',
		'rand',
		'ini_set',
		'set_error_handler',
		'date',
		'json_encode',
		'wp_redirect',
		'move_uploaded_file',
	);

	/**
	 * The plugin's own PHP files.
	 *
	 * @return string[]
	 */
	private function sources(): array {
		$root = dirname( __DIR__, 2 );
		return array_merge(
			array( $root . '/babilim.php', $root . '/uninstall.php' ),
			glob( $root . '/includes/*.php' ) ?: array(),
			glob( $root . '/admin/*.php' ) ?: array()
		);
	}

	public function test_discouraged_functions_are_only_called_with_a_justified_phpcs_ignore(): void {
		$problems = array();
		foreach ( $this->sources() as $file ) {
			$lines  = file( $file ) ?: array();
			$tokens = token_get_all( implode( '', $lines ) );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$t = $tokens[ $i ];
				if ( ! is_array( $t ) || T_STRING !== $t[0] || ! in_array( $t[1], self::DISCOURAGED, true ) ) {
					continue;
				}
				$before = $i - 1;
				while ( $before >= 0 && is_array( $tokens[ $before ] ) && T_WHITESPACE === $tokens[ $before ][0] ) {
					--$before;
				}
				if ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
					continue; // A method call or a declaration.
				}
				$after = $i + 1;
				while ( $after < $count && is_array( $tokens[ $after ] ) && T_WHITESPACE === $tokens[ $after ][0] ) {
					++$after;
				}
				if ( '(' !== ( $tokens[ $after ] ?? '' ) ) {
					continue; // Not a call (e.g. a constant or a string).
				}
				$line = $t[2];
				$here = $lines[ $line - 1 ] . ( $lines[ $line - 2 ] ?? '' );
				if ( 1 !== preg_match( '/phpcs:ignore\s+\S+\s+--\s+\S/', $here ) ) {
					$problems[] = basename( $file ) . ':' . $line . ' ' . $t[1] . '()';
				}
			}
		}
		$this->assertSame( array(), $problems, 'every discouraged call needs "// phpcs:ignore <Rule> -- <reason>" on its own line or the line above' );
	}

	public function test_every_superglobal_read_is_annotated_or_sanitized(): void {
		$problems = array();
		foreach ( $this->sources() as $file ) {
			foreach ( file( $file ) ?: array() as $number => $line ) {
				if ( 1 !== preg_match( '/\$_(GET|POST|REQUEST|SERVER|COOKIE|FILES)\b/', $line ) ) {
					continue;
				}
				if ( 1 === preg_match( '/^\s*(\*|\/\/)/', $line ) ) {
					continue;
				}
				if ( false === strpos( $line, 'phpcs:ignore' ) ) {
					$problems[] = basename( $file ) . ':' . ( $number + 1 );
				}
			}
		}
		$this->assertSame( array(), $problems );
	}

	/**
	 * `wp_register_style( 'babilim-admin', false, array(), BABILIM_VERSION )`
	 * / `wp_enqueue_style( 'babilim-admin' )` (admin/class-babilim-
	 * admin.php's own enqueue_admin_assets()) is the one deliberate
	 * exception: a `false` src registers no file at all -- core's own
	 * established pattern for a handle that exists only to hang
	 * wp_add_inline_style() output off of (see enqueue_admin_assets()'s
	 * own docblock for why this plugin needs one, and admin_css() for
	 * the whole three-rule stylesheet that ends up inline in the page,
	 * never a shipped/loaded file). The explicit $ver (BABILIM_VERSION,
	 * not the parameter's own `false` default) is what real Plugin
	 * Check's own WordPress.WP.EnqueuedResourceParameters sniff requires
	 * (see docs/plugin-check-results.md) -- omitting it flags a missing
	 * cache-busting version, even on a src-less registration. Matched
	 * narrowly enough that any OTHER shape calling these two functions
	 * -- a real path, a variable, no $ver, an extra argument -- still
	 * fails this test. Scripts are checked separately, against
	 * ALLOWED_SCRIPT_ENQUEUE_PATTERN below.
	 *
	 * @var string
	 */
	private const ALLOWED_STYLE_ENQUEUE_PATTERN = '/^\s*wp_register_style\(\s*\'babilim-admin\',\s*false,\s*array\(\s*\),\s*BABILIM_VERSION\s*\);\s*$|^\s*wp_enqueue_style\(\s*\'babilim-admin\'\s*\);\s*$/';

	/**
	 * The one script this plugin ships: its own admin script, enqueued
	 * from its own directory with an explicit version, in the footer,
	 * and with no dependencies; plus the file-less handle that carries the
	 * Deactivate link's confirmation on the plugins list. Matched exactly, so any other
	 * wp_enqueue_script()/wp_register_script() call still fails.
	 *
	 * @var string
	 */
	private const ALLOWED_SCRIPT_ENQUEUE_PATTERN = '/^\s*wp_register_script\(\s*\'babilim-plugins-list\',\s*false,\s*array\(\s*\),\s*BABILIM_VERSION,\s*true\s*\);\s*$|^\s*wp_enqueue_script\(\s*\'babilim-plugins-list\'\s*\);\s*$|^\s*wp_enqueue_script\(\s*\'babilim-admin\',\s*plugins_url\(\s*\'admin\/js\/babilim-admin\.js\',\s*BABILIM_FILE\s*\),\s*array\(\s*\),\s*BABILIM_VERSION,\s*true\s*\);\s*$/';

	public function test_no_remote_code_or_external_asset_patterns(): void {
		$problems = array();
		foreach ( $this->sources() as $file ) {
			foreach ( file( $file ) ?: array() as $number => $line ) {
				if ( 1 === preg_match( '/^\s*(\*|\/\/)/', $line ) ) {
					continue;
				}
				if ( 1 === preg_match( '/\b(?:wp_enqueue_script|wp_register_script)\s*\(/', $line )
					&& 1 !== preg_match( self::ALLOWED_SCRIPT_ENQUEUE_PATTERN, $line ) ) {
					$problems[] = basename( $file ) . ':' . ( $number + 1 );
					continue;
				}
				if ( 1 === preg_match( '/\b(?:wp_enqueue_style|wp_register_style)\s*\(/', $line )
					&& 1 !== preg_match( self::ALLOWED_STYLE_ENQUEUE_PATTERN, $line ) ) {
					$problems[] = basename( $file ) . ':' . ( $number + 1 );
					continue;
				}
				if ( 1 === preg_match( '/<script\b|<link\b[^>]*stylesheet|<iframe\b/i', $line )
					|| 1 === preg_match( '/\b(?:eval|create_function|assert)\s*\(|\b(?:include|require)(?:_once)?\s*\(?\s*[\'"]https?:/', $line ) ) {
					$problems[] = basename( $file ) . ':' . ( $number + 1 );
				}
			}
		}
		$this->assertSame( array(), $problems, 'the plugin ships and loads no scripts, styles or iframes and runs no remote code' );
	}

	public function test_the_plugin_ships_only_its_one_admin_script_and_no_stylesheets(): void {
		$root  = dirname( __DIR__, 2 );
		$found = array();
		foreach ( array( 'js', 'css', 'map' ) as $extension ) {
			foreach ( glob( $root . '/{,*/,*/*/}*.' . $extension, GLOB_BRACE ) ?: array() as $path ) {
				if ( 0 === strpos( $path, $root . '/tests/' ) || 0 === strpos( $path, $root . '/tools/' ) ) {
					continue;
				}
				$found[] = substr( $path, strlen( $root ) + 1 );
			}
		}
		$this->assertSame( array( 'admin/js/babilim-admin.js' ), $found, 'the one admin script, and no other script, stylesheet or source map' );
	}

	public function test_every_global_function_class_and_hook_name_is_prefixed(): void {
		$problems = array();
		foreach ( $this->sources() as $file ) {
			$code   = (string) file_get_contents( $file );
			$tokens = token_get_all( $code );
			$count  = count( $tokens );
			$depth  = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				$t = $tokens[ $i ];
				if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $t ) {
					--$depth;
				}
				if ( 0 !== $depth || ! is_array( $t ) || ! in_array( $t[0], array( T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
					continue;
				}
				$n = $i + 1;
				while ( is_array( $tokens[ $n ] ) && T_WHITESPACE === $tokens[ $n ][0] ) {
					++$n;
				}
				if ( is_array( $tokens[ $n ] ) && T_STRING === $tokens[ $n ][0] && 1 !== preg_match( '/^[Bb]abilim(_|$)/', $tokens[ $n ][1] ) ) {
					$problems[] = basename( $file ) . ':' . $tokens[ $n ][2] . ' ' . $tokens[ $n ][1];
				}
			}
		}
		$this->assertSame( array(), $problems, 'every global function, class and interface carries the babilim prefix' );
	}

	public function test_the_release_zip_leaves_out_development_files(): void {
		$ignored = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.distignore' );
		foreach ( array( 'tests/', 'scripts/', 'docs/', 'phpstan-stubs/', 'phpstan.neon', 'Makefile', 'CLAUDE.md', 'task-report.md', '.claude/', '.githooks/' ) as $entry ) {
			$this->assertMatchesRegularExpression( '/^' . preg_quote( $entry, '/' ) . '$/m', $ignored, "{$entry} must not ship in the release zip" );
		}
	}

	/**
	 * .distignore listing the right entries is necessary but not
	 * sufficient -- this actually builds the real release zip
	 * (`scripts/build-zip.php`, the same script `make zip` runs) and
	 * lists its contents, so a mismatch between .distignore's patterns
	 * and build-zip.php's own matching logic (`babilim_dist_excluded()`)
	 * would be caught here even if it isn't caught by the .distignore
	 * text check above.
	 */
	public function test_the_actual_built_zip_excludes_development_files_and_includes_the_release(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$root = dirname( __DIR__, 2 );
		// A worktree's .git is a *file* (a "gitdir: ..." pointer), not a
		// directory -- file_exists() covers both that and a normal .git/.
		if ( ! file_exists( $root . '/.git' ) ) {
			$this->markTestSkipped( 'build-zip.php requires a git working tree (git ls-files).' );
		}

		$output    = array();
		$exit_code = 0;
		exec( 'cd ' . escapeshellarg( $root ) . ' && php scripts/build-zip.php 2>&1', $output, $exit_code );
		$this->assertSame( 0, $exit_code, "build-zip.php failed:\n" . implode( "\n", $output ) );

		$readme = (string) file_get_contents( $root . '/readme.txt' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $m ) );
		$zip_path = $root . '/build/babilim-' . $m[1] . '.zip';
		$this->assertFileExists( $zip_path );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path ), "could not open {$zip_path}" );
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entries[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		unlink( $zip_path );

		$this->assertNotEmpty( $entries, 'the release zip must not be empty' );
		foreach ( array( 'babilim/task-report.md', 'babilim/CLAUDE.md', 'babilim/Makefile', 'babilim/phpstan.neon' ) as $must_not_ship ) {
			$this->assertNotContains( $must_not_ship, $entries, "{$must_not_ship} must not be in the release zip" );
		}
		foreach ( $entries as $entry ) {
			$this->assertDoesNotMatchRegularExpression( '#^babilim/(tests|scripts|docs|phpstan-stubs|\.claude|\.githooks)/#', $entry, "{$entry}: development directories must not ship" );
		}
		foreach ( array( 'babilim/babilim.php', 'babilim/uninstall.php', 'babilim/readme.txt', 'babilim/LICENSE', 'babilim/languages/babilim.pot' ) as $must_ship ) {
			$this->assertContains( $must_ship, $entries, "{$must_ship} must be in the release zip" );
		}
		// The first bundled locale ships as .mo and .l10n.php, never as .po.
		$locales = (string) file_get_contents( $root . '/languages/LOCALES' );
		$locales = preg_replace( '/#[^\n]*/', '', $locales );
		$first   = preg_split( '/\s+/', trim( (string) $locales ) )[0];
		if ( '' !== $first && is_file( $root . "/languages/babilim-{$first}.po" ) ) {
			$this->assertContains( "babilim/languages/babilim-{$first}.mo", $entries );
			$this->assertContains( "babilim/languages/babilim-{$first}.l10n.php", $entries );
			$this->assertNotContains( "babilim/languages/babilim-{$first}.po", $entries );
		}
	}
}
