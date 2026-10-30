<?php
/**
 * Loads WordPress core's own real `wp_kses()` (and everything else
 * `wp-includes/kses.php` defines) -- fetched by `make tools` into
 * `tools/wp-core/kses.php`, pinned by an exact tag and SHA256 in the
 * Makefile, and never committed (`tools/` is gitignored; see
 * .gitignore) -- so a test can run `admin/class-babilim-admin.php`'s
 * own rendered HTML through the real sanitizer wp.org's own WordPress
 * install uses, instead of a hand-written stand-in for what it does.
 *
 * A hand-written `wp_kses()` stand-in is only ever as strict as
 * whoever wrote it remembered to make it, and real core's own
 * `safecss_filter_attr()` (reached from inside real `wp_kses()`
 * whenever an element's `style` attribute is allowed) strips a
 * `style` property real core doesn't recognize -- `display` among
 * them -- which a hand-written stand-in has no reason to know unless
 * it happens to also reimplement that allow-list. Running the real
 * file sidesteps needing to keep a second implementation in sync with
 * core's own.
 *
 * `wp-includes/kses.php` has no `require_once` statements of its own
 * (confirmed by grepping the pinned tag's own file) -- unlike the
 * files tests/support/real-core.php loads, there's nothing to strip
 * before requiring it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This process's own scratch directory for the renamed copy of
 * kses.php this loader writes out before requiring it -- a subdirectory
 * of BABILIM_TEST_TMP_ROOT (tests/bootstrap.php), the same root every
 * other scratch path in this suite uses and that already gets torn down
 * at the end of the run. Created on demand.
 *
 * @return string Absolute directory path, no trailing slash.
 */
function babilim_test_real_kses_tmp_dir(): string {
	$dir = BABILIM_TEST_TMP_ROOT . '/real-kses-tmp';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	return $dir;
}

/**
 * Every `tools/wp-core/kses.php` path this loader looks for.
 *
 * @return string[]
 */
function babilim_test_real_kses_files(): array {
	$base = dirname( __DIR__, 2 ) . '/tools/wp-core';
	return array(
		$base . '/kses.php',
	);
}

/**
 * Whether the pinned real kses.php has actually been fetched (`make
 * tools`) -- a test calls this to markTestSkipped() with a clear
 * message rather than fail when it's missing.
 *
 * @return bool
 */
function babilim_test_real_kses_available(): bool {
	foreach ( babilim_test_real_kses_files() as $file ) {
		if ( ! is_file( $file ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Loads the real `wp_kses()` (and its own private helpers) exactly once
 * per process. Only the two names that collide with tests/bootstrap.php's
 * own same-named stand-ins -- `wp_kses` and `wp_kses_post` -- are
 * renamed (to `babilim_real_wp_kses`/`babilim_real_wp_kses_post`) before
 * the source is required; every other function in the file (~40 of
 * them, none colliding -- confirmed by diffing this file's own
 * `^function ` names against tests/bootstrap.php's) loads under its
 * real name, unchanged.
 *
 * A whole-file namespace wrapper was tried first and rejected: several
 * of kses.php's own functions pass their own OTHER function names to
 * preg_replace_callback()/array_map() as bare strings (e.g.
 * `preg_replace_callback( '/.../', 'wp_kses_named_entities', $content )`)
 * -- PHP resolves a string callable from inside a namespace by looking
 * in the GLOBAL namespace only, never the current one, so every such
 * call inside a namespaced copy fails at runtime with "function ... not
 * found" the first time content actually reaches it. Renaming only the
 * two colliding identifiers keeps every internal string-callback
 * reference intact (they call the OTHER, non-renamed helper functions,
 * which were never touched) while still not clobbering
 * tests/bootstrap.php's own `wp_kses()`/`wp_kses_post()` stand-ins that
 * every other test in this suite still relies on.
 *
 * @throws RuntimeException If babilim_test_real_kses_available() is false.
 */
function babilim_test_load_real_kses(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	if ( function_exists( 'babilim_real_wp_kses' ) ) {
		$loaded = true;
		return;
	}
	if ( ! babilim_test_real_kses_available() ) {
		throw new RuntimeException( 'The real wp-includes/kses.php is not present; call babilim_test_real_kses_available() first.' );
	}

	$source = (string) file_get_contents( babilim_test_real_kses_files()[0] );
	$source = (string) preg_replace( '/^<\?php\s*/', '', $source, 1 );
	// Longer name first, so its own occurrences are already gone by the
	// time the shorter pattern runs (see this function's own docblock
	// for why \b on both sides makes this safe against the ~40 other
	// wp_kses_* names sharing the "wp_kses" prefix).
	$source = (string) preg_replace( '/\bwp_kses_post\b/', 'babilim_real_wp_kses_post', $source );
	$source = (string) preg_replace( '/\bwp_kses\b/', 'babilim_real_wp_kses', $source );

	$stem = tempnam( babilim_test_real_kses_tmp_dir(), 'babilim-real-kses-' );
	$tmp  = $stem . '.php';
	file_put_contents( $tmp, "<?php\n" . $source );
	require $tmp;
	unlink( $tmp );
	unlink( $stem );

	$loaded = true;
}

// ---------------------------------------------------------------------
// The minimal set of WordPress core functions the real wp_kses($html,
// $array_of_allowed_tags) call path reaches that tests/bootstrap.php
// doesn't already stub -- confirmed by a byte-level scan of the pinned
// tag's own kses.php for every bareword global function call, cross-
// checked against which of those bootstrap.php already defines.
// _wp_add_global_attributes() (called from kses.php's own top-level
// code, unconditionally, the moment the file is required) needs no stub
// here -- it's defined inside kses.php itself, at its own module-private
// @access private function further down the same file, so it's already
// present by the time top-level code below it runs. wp_kses_allowed_
// html()'s $required_kses_globals branch (which would need
// $GLOBALS['allowedposttags'] etc.) is never reached from this call
// path -- passing an array $context takes wp_kses_allowed_html()'s
// `is_array( $context )` branch instead, confirmed by reading that
// function's own source.
// ---------------------------------------------------------------------

if ( ! function_exists( 'wp_allowed_protocols' ) ) {
	/**
	 * Real core's own static default list (`wp-includes/formatting.php`,
	 * pinned tag) -- the filtering/did_action() machinery around it in
	 * core is irrelevant here (this suite runs no `wp_loaded` action),
	 * so this stub is just the same static list core falls back to
	 * before that action ever fires.
	 *
	 * @return string[]
	 */
	function wp_allowed_protocols(): array {
		return array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' );
	}
}

// Deliberately after every stub above: kses.php's own top-level code
// (not just its functions) runs the moment it's required, and reaches
// _wp_add_global_attributes() immediately -- loading it before these
// stubs exist would fail the same way an un-stubbed run does.
if ( babilim_test_real_kses_available() ) {
	babilim_test_load_real_kses();
}
