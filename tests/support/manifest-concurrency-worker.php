<?php
/**
 * Manifest concurrency probe worker -- run as a real, separate `php`
 * subprocess by tests/unit/test-manifest-concurrency.php, never
 * `require`d directly by anything running inside the main PHPUnit
 * process itself. Two (or more) of these, plus one in `reader` mode,
 * run at the same time against the same shared directory, driving
 * Babilim_Manifest exactly the way two genuinely separate WordPress
 * requests would: each `new Babilim_Manifest(new Babilim_Wp_Fs())`
 * models one fresh request, reading `_manifest.json` once at
 * construction, same as production.
 *
 * Deliberately reproduces this repo's own tests/bootstrap.php's simple,
 * single-operation fake filesystem NOT being what this probe needs:
 * that fake's own move() is a plain rename(), never WordPress core's
 * own real WP_Filesystem_Direct::move() (which deletes the destination
 * BEFORE renaming the replacement into place -- see
 * Babilim_Wp_Fs::atomic_rename()'s own docblock). This script loads the
 * real, pinned core class (tools/wp-core/class-wp-filesystem-direct.php,
 * fetched by `make tools`) instead, so the probe exercises the actual
 * production behavior a real WordPress host running a `direct` transfer
 * has, not a friendlier stand-in that would hide the exact bug this
 * class's own locking exists to fix.
 *
 * argv: <plugin_dir> <shared_dir> <worker_id> <mode> <iterations>
 *   mode: 'mixed' (half same-record writes, half distinct-record
 *         writes) or 'reader' (continuously reads _manifest.json for
 *         <iterations> seconds, counting how often it's missing/invalid/
 *         ok).
 */

[ , $plugin_dir, $shared_dir, $worker_id, $mode, $iterations ] = $argv;

define( 'WP_LANG_DIR', $shared_dir . '/lang/' );
define( 'ABSPATH', $shared_dir . '/abspath/' );
// One options table shared by every worker (see tests/bootstrap.php's
// options section: real per-statement atomicity for $wpdb, WordPress
// core's own caching semantics for the Options API).
putenv( 'BABILIM_TEST_OPTIONS_DIR=' . $shared_dir . '/options' );
@mkdir( WP_LANG_DIR, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- another worker creating the exact same shared directory at the exact same moment is the expected race this whole script exists to probe, not an error.
@mkdir( ABSPATH, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- see above.

define( 'FS_CHMOD_FILE', 0644 );

// tests/bootstrap.php defines ABSPATH/WP_LANG_DIR itself too -- always a
// no-op redefinition (this worker's own values above always run first),
// but PHP still emits a warning for it; suppressed only for this one
// require, matching this repo's own tests/support/real-core.php pattern
// for the same reason. Loaded for every plugin class and WordPress
// function stub this probe needs.
$previous_error_reporting = error_reporting( E_ALL & ~E_WARNING );
require $plugin_dir . '/tests/bootstrap.php';
error_reporting( $previous_error_reporting );

// Real WordPress core's own WP_Filesystem_Direct -- not
// tests/bootstrap.php's own simpler fake -- see this file's own
// docblock for why. Installed into $GLOBALS['wp_filesystem'] BEFORE any
// Babilim_Wp_Fs call: that class's own fs() only calls the bootstrap's
// fake WP_Filesystem() when $GLOBALS['wp_filesystem'] is still empty.
require $plugin_dir . '/tools/wp-core/class-wp-filesystem-base.php';
require $plugin_dir . '/tools/wp-core/class-wp-filesystem-direct.php';
$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct( null );

mt_srand( crc32( $worker_id . microtime() ) );

if ( 'reader' === $mode ) {
	$manifest_file = Babilim_Paths::manifest_file();
	// missing_after_seen counts only a file that EXISTED at least once
	// and then, on a LATER read, didn't -- the real race this probe is
	// measuring (WP_Filesystem_Direct::move()'s own delete-then-rename
	// window). A read before the very first successful write has ever
	// landed also sees "missing", but that's just the manifest
	// genuinely not existing yet -- not a bug, and not counted here.
	$stats      = array( 'n' => 0, 'missing_before_first_write' => 0, 'missing_after_seen' => 0, 'invalid' => 0, 'ok' => 0 );
	$seen_once  = false;
	$end        = microtime( true ) + (float) $iterations;
	while ( microtime( true ) < $end ) {
		++$stats['n'];
		clearstatcache();
		$raw = @file_get_contents( $manifest_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- "doesn't exist right now" is exactly the condition this probe is measuring, not a real error.
		if ( false === $raw ) {
			if ( $seen_once ) {
				++$stats['missing_after_seen'];
			} else {
				++$stats['missing_before_first_write'];
			}
			continue;
		}
		$seen_once = true;
		json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			++$stats['invalid'];
		} else {
			++$stats['ok'];
		}
	}
	echo json_encode( $stats ), "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions -- a plain CLI probe script, not WordPress runtime code.
	exit;
}

/**
 * Why $manifest's own most recent write reported failure -- the
 * manifest's own last_save_failure() reason, or 'unknown' if it
 * reported none at all.
 */
function babilim_worker_failure_reason( Babilim_Manifest $manifest ): string {
	$failure = $manifest->last_save_failure();
	return is_array( $failure ) ? $failure['reason'] : 'unknown';
}

$iterations = (int) $iterations;
$log        = array();
for ( $i = 0; $i < $iterations; $i++ ) {
	$manifest = new Babilim_Manifest( new Babilim_Wp_Fs() );
	if ( ! $manifest->is_available() ) {
		$log[] = array( 'i' => $i, 'op' => 'unavail' );
		continue;
	}
	if ( 0 === $i % 2 ) {
		// The shared record -- every worker's own even-numbered
		// iteration targets this exact same kind+slug+locale, the
		// scenario save()'s own lock and merge logic exist for.
		$before = $manifest->installed( 'plugin', 'shared', 'de_DE' );
		usleep( mt_rand( 0, 1500 ) );
		$value = "w{$worker_id}-{$i}";
		$ok    = $manifest->set( 'plugin', 'shared', 'de_DE', array( 'build_id' => $value ) );
		$log[] = array( 'i' => $i, 'op' => 'same', 'base' => $before['build_id'] ?? null, 'val' => $value, 'ok' => $ok, 'why' => $ok ? null : babilim_worker_failure_reason( $manifest ) );
	} else {
		// A record only this worker's own iteration ever touches --
		// exercises the "two different records written by two separate
		// requests" case, distinct from the shared-key conflict case
		// above.
		usleep( mt_rand( 0, 1500 ) );
		$slug = "w{$worker_id}-{$i}";
		$ok   = $manifest->set( 'plugin', $slug, 'de_DE', array( 'build_id' => $slug ) );
		$log[] = array( 'i' => $i, 'op' => 'distinct', 'slug' => $slug, 'ok' => $ok, 'why' => $ok ? null : babilim_worker_failure_reason( $manifest ) );
	}
}
echo json_encode( $log ), "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions -- see the reader branch's own note.
