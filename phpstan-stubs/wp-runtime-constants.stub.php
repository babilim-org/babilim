<?php
/**
 * WordPress core runtime constants this plugin's own code references
 * directly (as opposed to only inside a `defined( 'X' ) ?: exit;` guard,
 * which PHPStan already resolves on its own). tools/wordpress-stubs.php
 * (see the Makefile) stubs WordPress core's functions and classes but
 * not its runtime constants -- those are only ever actually defined by
 * a running wp-load.php, never by the stubs package.
 *
 * Scanned for symbols only (see phpstan.neon's scanFiles), never
 * analysed and never loaded at runtime -- exactly like
 * tests/phpstan-stubs/PHPUnitTestCase.stub.php.
 */

define( 'WP_LANG_DIR', '' );

// Not a literal `false`: WP_DEBUG's whole point is that a host may define
// it either way, and this plugin's own code branches on that. A literal
// scalar here would let PHPStan constant-fold every `WP_DEBUG` check in
// the real source as always-true/always-false and flag the other branch
// as dead code -- getenv() keeps the type `bool` without a known value,
// the same way a real host's own definition is unknown at analysis time.
define( 'WP_DEBUG', (bool) getenv( 'WP_DEBUG' ) );
define( 'WP_PLUGIN_DIR', '' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
