<?php
/**
 * Entry point run via `wp eval-file .../controller.php <which>` from
 * run-checks.sh, inside a real, already-booted WordPress Playground
 * `server` instance (this plugin's own production hooks already
 * registered the normal way -- plugins_loaded -> Babilim::instance()->
 * init()). `<which>` is 'main' (everything except b10, which needs a
 * dedicated multisite-enabled boot -- see b10-multisite.php's own
 * docblock) or 'multisite'.
 *
 * @package Babilim
 *
 * No `declare(strict_types=1)` here (unlike every other file in this
 * directory) -- WP-CLI's own `eval-file` command runs this file's
 * contents through a real `eval()`, not a `require`, and PHP refuses a
 * `declare(strict_types=1)` that isn't the literal first statement of
 * the evaluated code once WP-CLI's own wrapping is accounted for. Every
 * file this one `require`s (a normal file include, not eval()'d) keeps
 * its own `declare(strict_types=1)` as usual.
 */

require __DIR__ . '/lib.php';

// `--php=` on the `server` command line (run-checks.sh's own
// PHP_VERSION) is only a request to Playground's own version resolver,
// not a guarantee -- the same class of silent-fallback risk already
// documented for `--wp=` (see docs/playground-results.md's own note on
// WordPress "latest" producing false negatives). This asserts the
// booted runtime's own PHP_VERSION actually starts with what was asked
// for, at the very top of every run, before any real check gets a
// chance to pass or fail for the wrong reason.
if ( defined( 'BABILIM_PG_EXPECTED_PHP_VERSION' ) && '' !== BABILIM_PG_EXPECTED_PHP_VERSION ) {
	$babilim_pg_expected_php = (string) BABILIM_PG_EXPECTED_PHP_VERSION;
	$babilim_pg_actual_php   = PHP_VERSION;
	babilim_pg_result(
		'infra-php-version',
		$babilim_pg_actual_php === $babilim_pg_expected_php || 0 === strpos( $babilim_pg_actual_php, $babilim_pg_expected_php . '.' ),
		"requested PHP {$babilim_pg_expected_php}, Playground actually booted PHP {$babilim_pg_actual_php}"
	);
}

$babilim_pg_checks_main = array(
	'shared-manifest-instance',
	'batch-deactivate-excludes-all',
	'manifest-lock-concurrency',
	'b3-hooks-timing',
	'b3b-jit-no-file',
	'b3c-merged-index-core-dupe',
	'b3d-conflict',
	'b3e-disable-reenable',
	'b3f-version-mismatch',
	'b3g-delete-sweep',
	'b4-js-merge',
	'b5-object-cache',
	// b6 (opcache invalidation) is not run here -- WordPress Playground
	// does not expose a real PHP opcache to boot against; the actual
	// coverage is Test_Default_Index::
	// test_rebuild_invalidates_the_opcache_for_both_written_files() in
	// tests/unit/test-default-index.php (see docs/playground-results.md).
	'b7-rtl-plural',
	'b8-thai',
	'b9-integrity',
	'b12-bundled-l10n',
	// Last: its uninstall.php run also purges the served language files.
	'b11-identity-and-warning',
);

// b1/b2's own real update-download round trip needs consent (never
// granted by any check itself -- see Babilim_Site::OPTION_CONSENT) and
// core's own wp_safe_remote_get()/wp_http_validate_url() to allow the
// local mock service's own port (127.0.0.1:8791 is not 80/443/8080 and
// isn't the site's own port -- see run-checks.sh's own mock-service
// docblock). Granted once here, for the whole run, rather than per
// check -- harmless for every other check, and consent in particular is
// a real site-owner action a real site only ever grants once.
update_option( Babilim_Site::OPTION_CONSENT, 1 );
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports ) {
		$ports[] = 8791;
		return $ports;
	}
);

$babilim_pg_checks_multisite = array(
	'b10-multisite',
	'b10b-multisite-lock',
);

$babilim_pg_which = isset( $args[0] ) ? (string) $args[0] : 'main'; // phpcs:ignore WordPress.NamingConventions -- $args is WP-CLI's own eval-file convention.
$babilim_pg_list  = ( 'multisite' === $babilim_pg_which ) ? $babilim_pg_checks_multisite : $babilim_pg_checks_main;

foreach ( $babilim_pg_list as $babilim_pg_file ) {
	babilim_pg_resync_singleton(); // Each check gets its own fresh "request" -- see that function's own docblock.
	require __DIR__ . '/' . $babilim_pg_file . '.php';
}

fwrite( STDOUT, "controller.php: ran " . count( $babilim_pg_list ) . " check file(s) for '{$babilim_pg_which}'\n" );

// Copies WordPress's own debug.log (WP_DEBUG_LOG, set by run-checks.sh's
// blueprint) alongside the results file, through the same host mount --
// this Playground boot's own process/VFS disappears the moment
// run-checks.sh tears it down, so anything not written through a mount
// is unrecoverable for a post-mortem on a FAIL line above.
$babilim_pg_debug_log = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : '';
if ( '' !== $babilim_pg_debug_log && is_readable( $babilim_pg_debug_log ) && defined( 'BABILIM_PG_RESULTS_FILE' ) ) {
	copy( $babilim_pg_debug_log, dirname( BABILIM_PG_RESULTS_FILE ) . '/debug.log' );
}
