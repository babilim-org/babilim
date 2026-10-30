<?php
/**
 * PHPUnit bootstrap: minimal WordPress function/constant stand-ins.
 *
 * Plugin unit tests never require a real WordPress install -- this
 * file stubs just enough of
 * WordPress core for the plugin's own code to load and run against.
 * It is a test fixture, not a fake of WordPress itself: hooks really
 * dispatch, options really round-trip in memory, and the recorders
 * below really record what was called, with what arguments.
 */

declare(strict_types=1);

// Every real-disk area this suite uses (ABSPATH, WP_LANG_DIR, the
// get_temp_dir() scratch area, and the test scratch area's
// WP_CONTENT_DIR and fixtures) lives under one directory named after
// this PHPUnit process's own pid, so concurrent runs -- two worktrees
// testing at once, parallel runs of the same checkout, or a
// separate-process test's own child PHP process -- never share a path.
// A process only ever cleans up its own root, never a path another
// process might still be using.
define( 'BABILIM_TEST_TMP_ROOT', sys_get_temp_dir() . '/babilim-tests-run-' . getmypid() );

define( 'ABSPATH', BABILIM_TEST_TMP_ROOT . '/abspath/' );
if ( ! is_dir( ABSPATH ) ) {
	mkdir( ABSPATH, 0777, true );
}

define( 'WP_LANG_DIR', BABILIM_TEST_TMP_ROOT . '/lang/' );
if ( ! is_dir( WP_LANG_DIR ) ) {
	mkdir( WP_LANG_DIR, 0777, true );
}

/**
 * Recursively deletes $path. A symlink is unlinked, never followed, so a
 * test fixture's link pointing outside this process's root can never
 * lead the cleanup anywhere else.
 *
 * @param string $path File, symlink or directory.
 */
function babilim_test_rmtree( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort test cleanup.
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) ?: array() as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		babilim_test_rmtree( $path . '/' . $name );
	}
	@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort test cleanup.
}

// Removes this process's own root when the process exits (the main
// PHPUnit process and every separate-process child alike, since each
// one loads this file with its own pid). Registered only by the process
// that created the root, so a forked child never deletes its parent's.
$babilim_test_root_owner_pid = getmypid();
register_shutdown_function(
	static function () use ( $babilim_test_root_owner_pid ): void {
		if ( getmypid() === $babilim_test_root_owner_pid ) {
			babilim_test_rmtree( BABILIM_TEST_TMP_ROOT );
		}
	}
);

// ---------------------------------------------------------------------
// Hook registry -- real add/do dispatch, not a mock of one.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_filters'] = array();
$GLOBALS['babilim_test_actions'] = array();

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['babilim_test_filters'][ $tag ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function apply_filters( $tag, $value, ...$args ) {
	if ( empty( $GLOBALS['babilim_test_filters'][ $tag ] ) ) {
		return $value;
	}
	$hooks = $GLOBALS['babilim_test_filters'][ $tag ];
	usort(
		$hooks,
		static function ( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		}
	);
	foreach ( $hooks as $hook ) {
		$call_args = array_slice( array_merge( array( $value ), $args ), 0, max( 1, (int) $hook['accepted_args'] ) );
		$value     = call_user_func_array( $hook['callback'], $call_args );
	}
	return $value;
}

// remove_filter()/remove_all_filters() -- real WordPress core semantics
// (wp-includes/plugin.php): remove_filter() only ever drops the one
// (callback, priority) registration that matches both; remove_all_filters()
// with no $priority drops every registration for $tag regardless of
// priority, matching what Language_Pack_Upgrader::bulk_upgrade() itself
// calls before its per-item loop (see wp-admin/includes/class-language-
// pack-upgrader.php's bulk_upgrade()) -- a test simulating that sequence
// relies on this actually removing what's registered, not just recording
// that it was asked to.
function remove_filter( $tag, $callback, $priority = 10 ) {
	if ( empty( $GLOBALS['babilim_test_filters'][ $tag ] ) ) {
		return false;
	}
	$kept  = array();
	$found = false;
	foreach ( $GLOBALS['babilim_test_filters'][ $tag ] as $hook ) {
		if ( ! $found && $hook['callback'] === $callback && (int) $hook['priority'] === (int) $priority ) {
			$found = true;
			continue;
		}
		$kept[] = $hook;
	}
	$GLOBALS['babilim_test_filters'][ $tag ] = $kept;
	return $found;
}

function remove_all_filters( $tag, $priority = false ) {
	if ( false === $priority ) {
		$GLOBALS['babilim_test_filters'][ $tag ] = array();
		return true;
	}
	$kept = array();
	foreach ( (array) ( $GLOBALS['babilim_test_filters'][ $tag ] ?? array() ) as $hook ) {
		if ( (int) $hook['priority'] !== (int) $priority ) {
			$kept[] = $hook;
		}
	}
	$GLOBALS['babilim_test_filters'][ $tag ] = $kept;
	return true;
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['babilim_test_actions'][ $tag ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

// How many times each action tag has been dispatched -- separate from
// the registry of *who's listening*, and from the recorders below.
// This is what makes the two do_action() calls at the bottom of this
// file (after_setup_theme, change_locale) observable from a test: the
// hook-registry snapshot/restore below preserves this count across
// babilim_test_reset() the same way it preserves the registry itself.
$GLOBALS['babilim_test_action_dispatch_counts'] = array();

function do_action( $tag, ...$args ) {
	$GLOBALS['babilim_test_action_dispatch_counts'][ $tag ] =
		( $GLOBALS['babilim_test_action_dispatch_counts'][ $tag ] ?? 0 ) + 1;

	if ( empty( $GLOBALS['babilim_test_actions'][ $tag ] ) ) {
		return;
	}
	$hooks = $GLOBALS['babilim_test_actions'][ $tag ];
	usort(
		$hooks,
		static function ( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		}
	);
	foreach ( $hooks as $hook ) {
		$call_args = array_slice( $args, 0, max( 0, (int) $hook['accepted_args'] ) );
		call_user_func_array( $hook['callback'], $call_args );
	}
}

// ---------------------------------------------------------------------
// i18n
// ---------------------------------------------------------------------

function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n
	return $text;
}

function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

/**
 * Mirrors core's wp_sprintf() for the one directive the plugin uses:
 * %l, a list joined with ", ", " and " between two and ", and " before
 * the last of three or more.
 */
function wp_sprintf( $pattern, ...$args ) {
	if ( '%l' === $pattern && isset( $args[0] ) && is_array( $args[0] ) ) {
		$items = array_values( $args[0] );
		$n     = count( $items );
		if ( $n < 2 ) {
			return (string) ( $items[0] ?? '' );
		}
		if ( 2 === $n ) {
			return $items[0] . ' and ' . $items[1];
		}
		return implode( ', ', array_slice( $items, 0, -1 ) ) . ', and ' . $items[ $n - 1 ];
	}
	return sprintf( $pattern, ...$args );
}

// ---------------------------------------------------------------------
// Escaping / sanitizing
// ---------------------------------------------------------------------

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * esc_js() (wp-includes/formatting.php), step for step: invalid UTF-8
 * is dropped (wp_check_invalid_utf8()), then _wp_specialchars( $text,
 * ENT_COMPAT ) runs with $double_encode = false -- core first calls
 * wp_kses_normalize_entities(), and that is core's own function here,
 * loaded from the pinned real kses.php -- and the rest is core's.
 */
function esc_js( $text ) {
	require_once __DIR__ . '/support/real-kses.php';
	babilim_test_load_real_kses();

	$safe = (string) $text;
	if ( '' !== $safe && ! preg_match( '//u', $safe ) ) {
		$safe = '';
	}
	if ( '' !== $safe && preg_match( '/[&<>"\']/', $safe ) ) {
		$safe = htmlspecialchars( wp_kses_normalize_entities( $safe, 'html' ), ENT_COMPAT, 'UTF-8', false );
	}
	$safe = preg_replace( '/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes( $safe ) );
	$safe = str_replace( "\r", '', $safe );
	return str_replace( "\n", '\\n', addslashes( $safe ) );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return filter_var( (string) $url, FILTER_SANITIZE_URL );
}

function esc_url_raw( $url ) {
	return filter_var( (string) $url, FILTER_SANITIZE_URL );
}

/** wp_parse_url() (wp-includes/http.php) -- core's own parse_url() wrapper. */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for the core wrapper itself.
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function __return_true() {
	return true;
}

function sanitize_text_field( $str ) {
	$str = preg_replace( '/[\r\n\t ]+/', ' ', (string) $str );
	return trim( (string) $str );
}

/** sanitize_textarea_field() (wp-includes/formatting.php): same idea as
 * sanitize_text_field() but preserves line breaks -- only collapses
 * runs of tabs/spaces, and normalizes CRLF/CR to a bare LF.
 */
function sanitize_textarea_field( $str ) {
	$str = (string) $str;
	$str = preg_replace( '/\r\n|\r/', "\n", $str );
	$str = preg_replace( '/[\t ]+/', ' ', $str );
	return trim( $str );
}

// ---------------------------------------------------------------------
// WP_Error -- a minimal stand-in for WordPress core's error-value class,
// real enough for code that constructs one and reads back its message.
// ---------------------------------------------------------------------

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// ---------------------------------------------------------------------
// A real WordPress install never hands out the same in-process object a
// second time: get_option()/get_transient()/get_site_transient() read
// back a value that was serialized into the DB (or, for a hit on a
// persistent object cache, deserialized off the wire) -- either way, a
// fresh value each call, never a live handle to whatever a previous
// caller happened to store. Returning the exact same stdClass instance
// from these stubs let production code mutate "storage" in place
// without ever calling the matching update_*()/set_*() -- a real
// WordPress install would never have persisted that change at all.
// Used on both write and read, so a caller mutating a value object
// after passing it to update_option()/set_transient()/set_site_transient()
// can't retroactively change what was stored either.
// ---------------------------------------------------------------------

function babilim_test_deep_copy( $value ) {
	if ( ! is_object( $value ) && ! is_array( $value ) ) {
		return $value;
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- test double for real WP get/set-option DB round-trip semantics (maybe_serialize()/maybe_unserialize()), never user input.
	return unserialize( serialize( $value ) );
}

// ---------------------------------------------------------------------
// Options -- in-memory only, EXCEPT when BABILIM_TEST_OPTIONS_DIR is
// set in the environment (only ever by the manifest concurrency test's
// own worker subprocesses -- see tests/unit/test-manifest-concurrency.php
// and tests/support/manifest-concurrency-worker.php). Every other test
// in this suite runs with that variable unset and keeps the simple,
// single-process, in-memory behavior below.
//
// When it IS set, the options functions below deliberately reproduce
// WordPress core's own Options API semantics (wp-includes/option.php,
// 6.6 through 7.1) rather than an idealized mutex, over one options
// table shared by every worker process (Babilim_Test_Wpdb's own shared
// table, below):
//
// - get_option() reads this process's own object cache first
//   ('notoptions' bucket, then the option's own cache entry), and only
//   falls through to the table on a miss -- so a value another process
//   changed or deleted since this process last looked is NOT seen.
// - add_option() is check-then-upsert, NOT an atomic "insert only if
//   absent": it skips its own existence check entirely when
//   'notoptions' says the option doesn't exist, otherwise checks via
//   (cached) get_option(), then runs INSERT ... ON DUPLICATE KEY UPDATE,
//   which overwrites a row another process inserted in between.
// - delete_option() marks the option in 'notoptions' afterwards.
//
// A cross-request lock built on add_option() therefore fails under this
// stub the same way it fails on a real WordPress site (two holders at
// once, a released lock never seen as released) -- which is the point:
// the concurrency test must be able to fail for a lock that is not
// genuinely atomic. The manifest's own lock uses Babilim_Test_Wpdb's
// single-statement primitives instead (see that class).
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_options']          = array();
$GLOBALS['babilim_test_options_shared']   = getenv( 'BABILIM_TEST_OPTIONS_DIR' );
$GLOBALS['babilim_test_options_autoload'] = array();

/**
 * Whether the shared, WordPress-semantics options mode is active (see
 * the section comment above).
 */
function babilim_test_options_are_shared(): bool {
	return (bool) $GLOBALS['babilim_test_options_shared'];
}

/** wp-includes/functions.php: unserializes a value only if it looks serialized. */
function maybe_unserialize( $data ) {
	if ( is_string( $data ) && 1 === preg_match( '/^(?:[bidsaOCN]:|N;)/', $data ) ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- test double for core's maybe_unserialize().
		$value = @unserialize( trim( $data ) );
		return false === $value && 'b:0;' !== trim( $data ) ? $data : $value;
	}
	return $data;
}

function get_option( $name, $default = false ) {
	if ( babilim_test_options_are_shared() ) {
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			return $default;
		}
		$cached = wp_cache_get( $name, 'options' );
		if ( false !== $cached ) {
			return babilim_test_deep_copy( $cached );
		}
		$row = $GLOBALS['wpdb']->babilim_test_row( (string) $name );
		if ( null === $row ) {
			$notoptions            = is_array( $notoptions ) ? $notoptions : array();
			$notoptions[ $name ]   = true;
			wp_cache_set( 'notoptions', $notoptions, 'options' );
			return $default;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- test double for maybe_unserialize() on a value this same stub serialized.
		$value = unserialize( $row );
		wp_cache_set( $name, $value, 'options' );
		return babilim_test_deep_copy( $value );
	}
	return array_key_exists( $name, $GLOBALS['babilim_test_options'] )
		? babilim_test_deep_copy( $GLOBALS['babilim_test_options'][ $name ] )
		: $default;
}

/**
 * See the section comment above: in shared mode, check-then-upsert,
 * exactly like WordPress core's own add_option() -- never an atomic
 * "insert only if absent".
 */
function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( babilim_test_options_are_shared() ) {
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( ! is_array( $notoptions ) || ! isset( $notoptions[ $name ] ) ) {
			if ( false !== get_option( $name ) ) {
				return false;
			}
		}
		do_action( 'add_option', $name, $value );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- test double for maybe_serialize().
		$GLOBALS['wpdb']->babilim_test_upsert_row( (string) $name, serialize( $value ) );
		wp_cache_set( $name, babilim_test_deep_copy( $value ), 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		return true;
	}
	if ( array_key_exists( $name, $GLOBALS['babilim_test_options'] ) ) {
		return false;
	}
	$GLOBALS['babilim_test_options'][ $name ]          = babilim_test_deep_copy( $value );
	$GLOBALS['babilim_test_options_autoload'][ $name ] = $autoload;
	return true;
}

function update_option( $name, $value, $autoload = null ) {
	// Real update_option() runs sanitize_option() first, which applies
	// the `sanitize_option_{$option}` filter register_setting() hooks.
	$value = apply_filters( "sanitize_option_{$name}", $value, $name, $value );
	if ( babilim_test_options_are_shared() ) {
		$old = get_option( $name );
		if ( $old === $value ) {
			return false;
		}
		if ( false === $old ) {
			return add_option( $name, $value, '', $autoload );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- test double for maybe_serialize().
		if ( ! $GLOBALS['wpdb']->babilim_test_update_row( (string) $name, serialize( $value ) ) ) {
			return false;
		}
		wp_cache_set( $name, babilim_test_deep_copy( $value ), 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		return true;
	}
	$GLOBALS['babilim_test_options'][ $name ]          = babilim_test_deep_copy( $value );
	$GLOBALS['babilim_test_options_autoload'][ $name ] = $autoload;
	return true;
}

function delete_option( $name ) {
	if ( babilim_test_options_are_shared() ) {
		if ( null === $GLOBALS['wpdb']->babilim_test_row( (string) $name ) ) {
			return false;
		}
		$GLOBALS['wpdb']->babilim_test_delete_row( (string) $name );
		wp_cache_delete( $name, 'options' );
		$notoptions            = wp_cache_get( 'notoptions', 'options' );
		$notoptions            = is_array( $notoptions ) ? $notoptions : array();
		$notoptions[ $name ]   = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );
		return true;
	}
	unset( $GLOBALS['babilim_test_options'][ $name ] );
	return true;
}

// ---------------------------------------------------------------------
// $wpdb -- a minimal stand-in for exactly the statements this plugin's
// manifest lock issues against the options table (see
// Babilim_Manifest::acquire_manifest_lock()), with a real database's
// per-statement atomicity: each statement runs as one indivisible step
// (in shared mode, under an exclusive flock() on the shared table, so
// two worker processes' statements never interleave inside one
// statement -- exactly the guarantee a real MySQL/SQLite row write
// gives, and no more). rows_affected is set the way a real database
// reports it:
//
// - INSERT IGNORE: 1 if the row was absent and got inserted, 0 if a row
//   with that option_name already existed (left untouched).
// - UPDATE ... WHERE option_name = %s [AND option_value = %s]: 1 if a
//   row matched every condition and its value changed, else 0.
// - DELETE ... WHERE option_name = %s [AND option_value = %s]: 1 if a
//   row matched every condition, else 0.
//
// Anything else throws, so a statement shape this stub doesn't model
// fails a test loudly instead of silently "succeeding".
//
// $before_statement, when set, is called with the statement's own verb
// ('insert'|'select'|'update'|'delete') and the statement itself just
// before it runs -- used by the lock tests to make "another request"
// act at an exact point between two of the lock's own statements.
//
// Tables: every options table of a network is modeled, each with its own
// rows -- $base_prefix . 'options' (the main site's) and, per site, the
// table switch_to_blog() points $options at (wp_2_options, ...), the way
// real WordPress does. A statement naming any other table throws.
// ---------------------------------------------------------------------

final class Babilim_Test_Wpdb {

	/** @var string */
	public $base_prefix = 'wp_';

	/** @var string The current site's own options table (see switch_to_blog()). */
	public $options = 'wp_options';

	/** @var int */
	public $rows_affected = 0;

	/** @var callable|null */
	public $before_statement = null;

	/** @var array<int,array{0:string,1:string}> Every statement run, in order: [verb, sql]. */
	public $statements = array();

	/** @var string|null Shared table directory, or null for in-memory. */
	private $shared_dir;

	/** @var array<string,array<string,string>> In-memory tables: table => option_name => option_value. */
	private $rows = array();

	public function __construct( ?string $shared_dir ) {
		$this->shared_dir = $shared_dir;
		if ( null !== $shared_dir && ! is_dir( $shared_dir ) ) {
			@mkdir( $shared_dir, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- another worker creating the same directory at the same moment is expected.
		}
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sdi]/',
			static function ( $m ) use ( &$i, $args ) {
				$arg = $args[ $i++ ] ?? '';
				if ( '%d' === $m[0] ) {
					return (string) (int) $arg;
				}
				if ( '%i' === $m[0] ) {
					// Real core (wpdb::prepare(), since 6.2) wraps an %i
					// identifier in backticks, doubling any backtick the
					// identifier itself contains -- never single-quoted
					// like %s, since an identifier isn't a string value.
					return '`' . str_replace( '`', '``', (string) $arg ) . '`';
				}
				return "'" . addslashes( (string) $arg ) . "'";
			},
			$query
		);
	}

	public function query( $sql ) {
		$sql  = trim( (string) $sql );
		$verb = strtolower( (string) strtok( $sql, ' ' ) );
		$this->statements[] = array( $verb, $sql );
		if ( null !== $this->before_statement ) {
			call_user_func( $this->before_statement, $verb, $sql );
		}
		$literals = self::literals( $sql );
		// Backticks are REQUIRED, not optional: an identifier
		// substituted via %i (see prepare()) is always backtick-wrapped,
		// matching real core -- a raw {$table} interpolation (no %i, no
		// backticks) must NOT match this pattern, so a regression back
		// to interpolating the table name directly falls through to the
		// "unmodeled statement" branch below and fails loudly instead of
		// silently matching. The backticks themselves are outside the
		// named group so $m['table'] is always the bare name.
		$table = '`(?P<table>[a-z0-9_]+)`';
		$lit   = "'(?:[^'\\\\]|\\\\.)*'";

		if ( preg_match( "/^INSERT IGNORE INTO {$table} \\(option_name, option_value, autoload\\) VALUES \\('/", $sql, $m ) && 3 === count( $literals ) ) {
			list( $name, $value ) = $literals;
			$rows = $this->atomically(
				$this->known_table( $m['table'] ),
				static function ( array &$table_rows ) use ( $name, $value ) {
					if ( array_key_exists( $name, $table_rows ) ) {
						return 0;
					}
					$table_rows[ $name ] = $value;
					return 1;
				}
			);
		} elseif ( preg_match( "/^UPDATE {$table} SET option_value = {$lit} WHERE option_name = {$lit}( AND option_value = {$lit})?$/s", $sql, $m ) ) {
			$conditional = ! empty( $m[2] );
			$value       = $literals[0];
			$name        = $literals[1];
			$expected    = $conditional ? $literals[2] : null;
			$rows = $this->atomically(
				$this->known_table( $m['table'] ),
				static function ( array &$table_rows ) use ( $name, $value, $expected ) {
					if ( ! array_key_exists( $name, $table_rows ) ) {
						return 0;
					}
					if ( null !== $expected && $table_rows[ $name ] !== $expected ) {
						return 0;
					}
					if ( $table_rows[ $name ] === $value ) {
						return 0;
					}
					$table_rows[ $name ] = $value;
					return 1;
				}
			);
		} elseif ( preg_match( "/^DELETE FROM {$table} WHERE option_name = {$lit}( AND option_value = {$lit})?$/s", $sql, $m ) ) {
			$conditional = ! empty( $m[2] );
			$name        = $literals[0];
			$expected    = $conditional ? $literals[1] : null;
			$rows = $this->atomically(
				$this->known_table( $m['table'] ),
				static function ( array &$table_rows ) use ( $name, $expected ) {
					if ( ! array_key_exists( $name, $table_rows ) ) {
						return 0;
					}
					if ( null !== $expected && $table_rows[ $name ] !== $expected ) {
						return 0;
					}
					unset( $table_rows[ $name ] );
					return 1;
				}
			);
		} else {
			throw new RuntimeException( 'Babilim_Test_Wpdb: unmodeled statement: ' . $sql );
		}

		$this->rows_affected = $rows;
		return $rows;
	}

	public function get_var( $sql ) {
		$sql = trim( (string) $sql );
		$this->statements[] = array( 'select', $sql );
		if ( null !== $this->before_statement ) {
			call_user_func( $this->before_statement, 'select', $sql );
		}
		// Backticks required -- see query()'s own $table pattern comment
		// for why.
		if ( ! preg_match( "/^SELECT option_value FROM `(?P<table>[a-z0-9_]+)` WHERE option_name = '.*' LIMIT 1$/s", $sql, $m ) ) {
			throw new RuntimeException( 'Babilim_Test_Wpdb: unmodeled SELECT: ' . $sql );
		}
		$literals = self::literals( $sql );
		$table    = $this->known_table( $m['table'] );
		$row      = $this->babilim_test_row( $literals[0], $table );
		// In the default in-memory mode update_option() never touches a
		// table, so a direct read of the current site's options table
		// falls back to the same value get_option() would return -- a
		// row written straight to the table (babilim_test_upsert_row())
		// is "another request" and wins, exactly as it would in a real
		// database.
		if ( null === $row && ! babilim_test_options_are_shared() && $table === $this->options
			&& array_key_exists( $literals[0], $GLOBALS['babilim_test_options'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- test double for maybe_serialize().
			return serialize( $GLOBALS['babilim_test_options'][ $literals[0] ] );
		}
		return $row;
	}

	/**
	 * $table itself, if it is one of this network's options tables:
	 * the main one, or a site's own ({base_prefix}{blog id}_options).
	 */
	private function known_table( string $table ): string {
		if ( $this->base_prefix . 'options' !== $table && 1 !== preg_match( '/^' . preg_quote( $this->base_prefix, '/' ) . '[1-9][0-9]*_options$/', $table ) ) {
			throw new RuntimeException( 'Babilim_Test_Wpdb: unknown table: ' . $table );
		}
		return $table;
	}

	/** The table a test's own direct access means when it names none: the main options table. */
	private function main_table(): string {
		return $this->base_prefix . 'options';
	}

	// -- Direct table access for tests: "another request" writing to the
	// same table, bypassing this process's own object cache entirely. --

	public function babilim_test_row( string $name, ?string $table = null ): ?string {
		$rows = $this->read_all( $table ?? $this->main_table() );
		return array_key_exists( $name, $rows ) ? $rows[ $name ] : null;
	}

	public function babilim_test_put_row( string $name, string $value, ?string $table = null ): void {
		$this->atomically(
			$table ?? $this->main_table(),
			static function ( array &$table_rows ) use ( $name, $value ) {
				$table_rows[ $name ] = $value;
				return 1;
			}
		);
	}

	/** INSERT ... ON DUPLICATE KEY UPDATE, as WordPress core's own add_option() issues it. */
	public function babilim_test_upsert_row( string $name, string $value, ?string $table = null ): void {
		$this->babilim_test_put_row( $name, $value, $table );
	}

	public function babilim_test_update_row( string $name, string $value, ?string $table = null ): bool {
		return 1 === $this->atomically(
			$table ?? $this->main_table(),
			static function ( array &$table_rows ) use ( $name, $value ) {
				if ( ! array_key_exists( $name, $table_rows ) || $table_rows[ $name ] === $value ) {
					return 0;
				}
				$table_rows[ $name ] = $value;
				return 1;
			}
		);
	}

	public function babilim_test_delete_row( string $name, ?string $table = null ): void {
		$this->atomically(
			$table ?? $this->main_table(),
			static function ( array &$table_rows ) use ( $name ) {
				unset( $table_rows[ $name ] );
				return 1;
			}
		);
	}

	/**
	 * Every single-quoted literal in $sql, in order, unescaped (the
	 * inverse of prepare()'s own addslashes() quoting).
	 *
	 * @return string[]
	 */
	private static function literals( string $sql ): array {
		preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/s", $sql, $m );
		return array_map( 'stripslashes', $m[1] );
	}

	/** @return array<string,string> */
	private function read_all( string $table ): array {
		if ( null === $this->shared_dir ) {
			return $this->rows[ $table ] ?? array();
		}
		$rows = null;
		$this->atomically(
			$table,
			static function ( array &$table_rows ) use ( &$rows ) {
				$rows = $table_rows;
				return 0;
			},
			false
		);
		return (array) $rows;
	}

	/**
	 * Runs $fn against one table as one indivisible step; returns $fn's
	 * own rows-affected count.
	 */
	private function atomically( string $table, callable $fn, bool $write = true ): int {
		if ( null === $this->shared_dir ) {
			if ( ! isset( $this->rows[ $table ] ) ) {
				$this->rows[ $table ] = array();
			}
			return (int) $fn( $this->rows[ $table ] );
		}
		$lock = fopen( $this->shared_dir . '/' . $table . '.lock', 'c' );
		flock( $lock, LOCK_EX );
		try {
			$path = $this->shared_dir . '/' . $table . '.json';
			$raw  = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- absent until the first write.
			$rows = is_string( $raw ) && '' !== $raw ? (array) json_decode( $raw, true ) : array();
			$result = (int) $fn( $rows );
			if ( $write && $result > 0 ) {
				file_put_contents( $path . '.tmp', json_encode( $rows ) );
				rename( $path . '.tmp', $path );
			}
			return $result;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}
}

$GLOBALS['wpdb'] = new Babilim_Test_Wpdb( $GLOBALS['babilim_test_options_shared'] ? rtrim( (string) $GLOBALS['babilim_test_options_shared'], '/' ) . '/table' : null );

// ---------------------------------------------------------------------
// Plugin/theme listings -- in-memory only, used by
// Babilim_Default_Index::sweep_orphans() to tell "genuinely removed from
// disk" apart from "merely deactivated" (see that method's docblock for
// why it consults these two WordPress core functions directly rather
// than through a constructor-injected callable).
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_get_plugins_return']    = array();
$GLOBALS['babilim_test_wp_get_themes_return']  = array();
/**
 * A minimal WP_Theme-shaped default stand-in -- real WordPress's
 * wp_get_theme() always returns a WP_Theme object, never null, so the
 * test default matches that rather than forcing every test that doesn't
 * care about theme specifics to configure one.
 */
class Babilim_Test_Default_Theme {
	public function get_stylesheet() {
		return 'default-test-theme';
	}

	/** A default theme double is "not installed": callers fall back to their own path. */
	public function exists() {
		return false;
	}

	public function get_stylesheet_directory() {
		return '';
	}

	public function get_theme_root() {
		return '';
	}

	public function get( $key ) {
		return 'Version' === $key ? '1.0.0' : false;
	}

	public function parent() {
		return false;
	}
}

$GLOBALS['babilim_test_is_plugin_active_return'] = array();
$GLOBALS['babilim_test_wp_get_theme_return']     = new Babilim_Test_Default_Theme();

function is_plugin_active( $plugin_file ) {
	return ! empty( $GLOBALS['babilim_test_is_plugin_active_return'][ $plugin_file ] );
}

function wp_get_theme() {
	return $GLOBALS['babilim_test_wp_get_theme_return'];
}

// ---------------------------------------------------------------------
// Cron -- in-memory only, real enough for Babilim::init() to schedule
// against without touching wp-cron.php or making any request.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_cron_scheduled']         = array();
$GLOBALS['babilim_test_cron_cleared']            = array();
$GLOBALS['babilim_test_single_events_scheduled'] = array();

function wp_next_scheduled( $hook ) {
	return $GLOBALS['babilim_test_cron_scheduled'][ $hook ] ?? false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['babilim_test_cron_scheduled'][ $hook ] = $timestamp;
	return true;
}

/** wp_schedule_single_event() (wp-includes/cron.php) -- a one-off,
 * non-recurring cron event. Recorded (not deduped -- real core's own
 * dedup is a same-hook/same-args/same-timestamp exact match, which this
 * plugin's own callers don't rely on) so a test can assert one was
 * scheduled, without actually running WP-Cron.
 */
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['babilim_test_single_events_scheduled'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);
	return true;
}

/** _get_cron_array() (wp-includes/cron.php) -- the doubles' view of the
 * queue: recurring events from the schedule map, one-offs from the
 * single-event log.
 */
function _get_cron_array() {
	$cron = array();
	foreach ( $GLOBALS['babilim_test_cron_scheduled'] as $hook => $timestamp ) {
		$cron[ (int) $timestamp ][ $hook ]['k'] = array( 'schedule' => 'twicedaily', 'args' => array() );
	}
	foreach ( $GLOBALS['babilim_test_single_events_scheduled'] as $i => $event ) {
		$cron[ (int) $event['timestamp'] ][ $event['hook'] ][ 's' . $i ] = array( 'schedule' => false, 'args' => $event['args'] );
	}
	return $cron;
}

function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['babilim_test_cron_cleared'][] = $hook;
	unset( $GLOBALS['babilim_test_cron_scheduled'][ $hook ] );
	return true;
}

// ---------------------------------------------------------------------
// Multisite -- switch_to_blog()/restore_current_blog() swap out the
// per-site slice of options/transients/scheduled-cron state above
// (each real WordPress site has its own options table and its own
// 'cron' option) so uninstall.php's multisite branch can be tested the
// same way it runs for real: iterate get_sites(), switch into each
// one, and see only that site's own state through get_option()/
// get_transient()/wp_next_scheduled(). Site transients
// (get_site_transient()/set_site_transient(), defined above) are
// deliberately NOT swapped -- they are genuinely network-wide in real
// multisite (stored once, in `wp_sitemeta`), which is exactly what
// uninstall.php's own network-layer cleanup (outside the per-site
// loop) relies on. babilim_test_cron_cleared/babilim_test_
// single_events_scheduled stay global call logs, not swapped: they
// only exist so a test can assert "this hook name was cleared at some
// point", which is true regardless of which site was active when it
// happened.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_multisite']       = false;
$GLOBALS['babilim_test_sites']           = array( 1 );
$GLOBALS['babilim_test_network_admin']   = false;
$GLOBALS['babilim_test_current_blog_id'] = 1;
$GLOBALS['babilim_test_blog_stack']      = array();
$GLOBALS['babilim_test_blog_state']      = array();

function is_multisite() {
	return $GLOBALS['babilim_test_multisite'];
}

/**
 * Test double for core's wp_date(): formats in the timezone_string option (UTC when unset), which is all the
 * tests need (they pass fixed timestamps and compare the text).
 */
function wp_date( $format, $timestamp = null ) {
	$tz = (string) get_option( 'timezone_string', '' );
	$dt = new DateTime( '@' . ( null === $timestamp ? time() : (int) $timestamp ) );
	$dt->setTimezone( new DateTimeZone( '' === $tz ? 'UTC' : $tz ) );
	return $dt->format( (string) $format );
}

function get_current_blog_id() {
	return $GLOBALS['babilim_test_current_blog_id'];
}

/**
 * True only inside the network admin of a multisite install, like core's
 * own is_network_admin() (which is never true on a single site).
 */
function is_network_admin() {
	return $GLOBALS['babilim_test_multisite'] && $GLOBALS['babilim_test_network_admin'];
}

/**
 * Core's get_blog_option() (wp-includes/ms-blogs.php), step for step: an
 * empty id means the current blog; the current blog (and every blog on a
 * single site) reads plain get_option(); any other blog is read through
 * switch_to_blog()/restore_current_blog() and only that value goes
 * through the `blog_option_{$option}` filter.
 */
function get_blog_option( $blog_id, $option, $default_value = false ) {
	$blog_id = (int) $blog_id;
	if ( empty( $blog_id ) ) {
		$blog_id = get_current_blog_id();
	}
	if ( get_current_blog_id() === $blog_id ) {
		return get_option( $option, $default_value );
	}
	switch_to_blog( $blog_id );
	$value = get_option( $option, $default_value );
	restore_current_blog();
	return apply_filters( "blog_option_{$option}", $value, $blog_id );
}

/**
 * Respects `number` the way real WordPress does (WP_Site_Query's own
 * default is 100; `number => 0` means unlimited) so a caller that drops
 * `'number' => 0` silently falls back to the first 100 sites instead of
 * every one of them -- a stub that always returned every registered
 * site regardless of `number` could never catch that regression.
 *
 * @param array{fields?:string,number?:int} $args
 * @return int[]|object[]
 */
function get_sites( $args = array() ) {
	$ids    = $GLOBALS['babilim_test_sites'];
	$number = array_key_exists( 'number', $args ) ? (int) $args['number'] : 100;
	if ( $number > 0 ) {
		$ids = array_slice( $ids, 0, $number );
	}
	if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
		return $ids;
	}
	return array_map(
		static function ( $id ) {
			return (object) array( 'blog_id' => $id );
		},
		$ids
	);
}

/**
 * @return array{options:array,options_autoload:array,transients:array,cron_scheduled:array}
 */
function babilim_test_blog_snapshot() {
	return array(
		'options'          => $GLOBALS['babilim_test_options'],
		'options_autoload' => $GLOBALS['babilim_test_options_autoload'],
		'transients'       => $GLOBALS['babilim_test_transients'],
		'cron_scheduled'   => $GLOBALS['babilim_test_cron_scheduled'],
	);
}

function babilim_test_blog_restore( array $snapshot ) {
	$GLOBALS['babilim_test_options']          = $snapshot['options'];
	$GLOBALS['babilim_test_options_autoload'] = $snapshot['options_autoload'];
	$GLOBALS['babilim_test_transients']       = $snapshot['transients'];
	$GLOBALS['babilim_test_cron_scheduled']   = $snapshot['cron_scheduled'];
}

function babilim_test_blank_blog_state() {
	return array(
		'options'          => array(),
		'options_autoload' => array(),
		'transients'       => array(),
		'cron_scheduled'   => array(),
	);
}

function switch_to_blog( $new_blog_id ) {
	$new_blog_id = (int) $new_blog_id;
	$current     = $GLOBALS['babilim_test_current_blog_id'];

	$GLOBALS['babilim_test_blog_state'][ $current ] = babilim_test_blog_snapshot();
	$GLOBALS['babilim_test_blog_stack'][]           = $current;
	$GLOBALS['babilim_test_current_blog_id']        = $new_blog_id;

	babilim_test_blog_restore( $GLOBALS['babilim_test_blog_state'][ $new_blog_id ] ?? babilim_test_blank_blog_state() );
	babilim_test_point_wpdb_at_blog( $new_blog_id );
	return true;
}

function restore_current_blog() {
	if ( empty( $GLOBALS['babilim_test_blog_stack'] ) ) {
		return false;
	}
	$current = $GLOBALS['babilim_test_current_blog_id'];

	$GLOBALS['babilim_test_blog_state'][ $current ] = babilim_test_blog_snapshot();
	$previous                                       = array_pop( $GLOBALS['babilim_test_blog_stack'] );
	$GLOBALS['babilim_test_current_blog_id']        = $previous;

	babilim_test_blog_restore( $GLOBALS['babilim_test_blog_state'][ $previous ] ?? babilim_test_blank_blog_state() );
	babilim_test_point_wpdb_at_blog( (int) $previous );
	return true;
}

/**
 * Points $wpdb->options at $blog_id's own options table, the way real
 * switch_to_blog()/restore_current_blog() do (wpdb::set_blog_id()):
 * the main site's is {base_prefix}options, every other site's is
 * {base_prefix}{blog id}_options. $wpdb->base_prefix never changes.
 */
function babilim_test_point_wpdb_at_blog( int $blog_id ) {
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof Babilim_Test_Wpdb ) {
		$GLOBALS['wpdb']->options = $GLOBALS['wpdb']->base_prefix . ( 1 === $blog_id ? '' : $blog_id . '_' ) . 'options';
	}
}

// ---------------------------------------------------------------------
// Activation/deactivation hooks -- recorded only, so a test can assert
// Babilim's own deactivation callback is actually registered and does
// what it should, without a
// real plugin-activation request.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_activation_hooks']   = array();
$GLOBALS['babilim_test_deactivation_hooks'] = array();

function register_activation_hook( $file, $callback ) {
	$GLOBALS['babilim_test_activation_hooks'][] = $callback;
}

function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['babilim_test_deactivation_hooks'][] = $callback;
}

// ---------------------------------------------------------------------
// Transients -- in-memory only, real enough for
// Babilim_Inventory::plugin_slug_from_file()'s and this plugin's own
// active_components()/installed_identities() wp.org-slug lookups to read
// without a real WordPress install.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_transients'] = array();

function get_transient( $name ) {
	return array_key_exists( $name, $GLOBALS['babilim_test_transients'] )
		? babilim_test_deep_copy( $GLOBALS['babilim_test_transients'][ $name ] )
		: false;
}

function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['babilim_test_transients'][ $name ] = babilim_test_deep_copy( $value );
	return true;
}

function delete_transient( $name ) {
	unset( $GLOBALS['babilim_test_transients'][ $name ] );
	return true;
}

/**
 * Site transients -- stored SEPARATELY from the (single-site) transients
 * above, deliberately: `update_plugins`/`update_themes` are genuinely
 * site transients in real WordPress (see wp-admin/includes/update.php's
 * wp_update_plugins()/wp_update_themes(), which only ever call
 * get_site_transient()/set_site_transient() for them, never
 * get_transient()) -- a test that stubbed both through the same storage
 * would pass even if the production code under test called the wrong
 * one (get_transient() always returns false for 'update_plugins' on a
 * real single-site install, since nothing ever writes there under that
 * name).
 */
$GLOBALS['babilim_test_site_transients']       = array();
// One counter per transient name, incremented on every set_site_transient()
// call regardless of whether the value actually changed -- lets a test
// assert "this many writes happened" directly, instead of inferring
// "no write happened" from object identity (a check that can't tell a
// real no-op apart from a write that happens to restore the exact
// object a previous read handed out; see babilim_test_deep_copy()'s own
// comment above for why that's no longer even possible here).
$GLOBALS['babilim_test_site_transient_writes'] = array();

// Network-wide (site) options -- one store for the whole network, never
// swapped by switch_to_blog() (unlike per-site options above), the way
// real get_site_option() reads the network's own sitemeta table.
$GLOBALS['babilim_test_site_options'] = array();

function get_site_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['babilim_test_site_options'] )
		? babilim_test_deep_copy( $GLOBALS['babilim_test_site_options'][ $name ] )
		: $default_value;
}

function update_site_option( $name, $value ) {
	$GLOBALS['babilim_test_site_options'][ $name ] = babilim_test_deep_copy( $value );
	return true;
}

function delete_site_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['babilim_test_site_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['babilim_test_site_options'][ $name ] );
	return true;
}

function get_site_transient( $name ) {
	return array_key_exists( $name, $GLOBALS['babilim_test_site_transients'] )
		? babilim_test_deep_copy( $GLOBALS['babilim_test_site_transients'][ $name ] )
		: false;
}

function set_site_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['babilim_test_site_transients'][ $name ]       = babilim_test_deep_copy( $value );
	$GLOBALS['babilim_test_site_transient_writes'][ $name ] = ( $GLOBALS['babilim_test_site_transient_writes'][ $name ] ?? 0 ) + 1;
	return true;
}

function delete_site_transient( $name ) {
	unset( $GLOBALS['babilim_test_site_transients'][ $name ] );
	return true;
}

/**
 * When true, get_plugins() throws instead of returning its configured
 * value -- simulates wp-admin/includes/plugin.php genuinely being
 * unavailable (e.g. require_once itself failing) in a cron/non-admin
 * request, for the fail-closed safety net below. Distinct from
 * babilim_test_get_plugins_return staying at its default empty array:
 * that case is ambiguous on its own (a genuinely plugin-free site, or
 * the same "unavailable" state above without a thrown exception), so
 * the safety net's actual rule is to trip only when the manifest also
 * has an existing plugin record to lose -- see
 * tests/unit/test-default-index.php's own empty-get_plugins() tests for
 * both outcomes.
 */
$GLOBALS['babilim_test_get_plugins_throws'] = false;

// Babilim_Installer test fixtures store their fake "zip" contents here,
// keyed by an arbitrary string the test passes as $zip_path -- see
// tests/unit/test-installer.php's unzip_fake().
$GLOBALS['babilim_test_fake_zips'] = array();

function get_plugins() {
	if ( ! empty( $GLOBALS['babilim_test_get_plugins_throws'] ) ) {
		throw new RuntimeException( 'babilim_test_get_plugins_throws: simulated get_plugins() failure' );
	}
	return $GLOBALS['babilim_test_get_plugins_return'];
}

function wp_get_themes() {
	return $GLOBALS['babilim_test_wp_get_themes_return'];
}

// ---------------------------------------------------------------------
// Cache/opcache invalidation -- recorded only, so a test can assert
// Babilim_Installer/Babilim_Default_Index invalidate the right
// directories/files after a write, without a real object cache or
// opcache present.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_wp_cache_delete_calls']       = array();
$GLOBALS['babilim_test_wp_opcache_invalidate_calls'] = array();

// A per-process, in-memory object cache -- the same scope WordPress's
// own non-persistent runtime cache has (one request).
$GLOBALS['babilim_test_object_cache'] = array();

function wp_cache_get( $key, $group = '' ) {
	return array_key_exists( $key, $GLOBALS['babilim_test_object_cache'][ $group ] ?? array() )
		? babilim_test_deep_copy( $GLOBALS['babilim_test_object_cache'][ $group ][ $key ] )
		: false;
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	$GLOBALS['babilim_test_object_cache'][ $group ][ $key ] = babilim_test_deep_copy( $data );
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['babilim_test_wp_cache_delete_calls'][] = array( $key, $group );
	$existed = isset( $GLOBALS['babilim_test_object_cache'][ $group ] ) && array_key_exists( $key, $GLOBALS['babilim_test_object_cache'][ $group ] );
	unset( $GLOBALS['babilim_test_object_cache'][ $group ][ $key ] );
	return $existed;
}

function wp_opcache_invalidate( $filepath, $force = false ) {
	$GLOBALS['babilim_test_wp_opcache_invalidate_calls'][] = array( $filepath, $force );
	return true;
}

// ---------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------

function wp_normalize_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );
	$path = preg_replace( '#(?<=.)/+#', '/', $path );
	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}
	return $path;
}

function trailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' ) . '/';
}

function untrailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' );
}

// Real WordPress core's own no-ops when mbstring isn't loaded; harmless
// stand-ins here regardless -- only WP_Filesystem_Direct::get_contents_array()
// (not exercised by this suite) calls these.
if ( ! function_exists( 'mbstring_binary_safe_encoding' ) ) {
	function mbstring_binary_safe_encoding( $reset = false ) {}
}
if ( ! function_exists( 'reset_mbstring_encoding' ) ) {
	function reset_mbstring_encoding() {}
}

// ---------------------------------------------------------------------
// WP_Filesystem -- a real, local-disk-backed stand-in for the direct
// transport WordPress core itself uses on ordinary hosting. Babilim_Wp_Fs
// is tested against this, not a fake: put_contents() really writes,
// get_contents() really reads back.
// ---------------------------------------------------------------------

class Babilim_Test_Wp_Filesystem_Direct {
	/**
	 * Matches real WP_Filesystem_Base's own public $method property --
	 * a real WP_Filesystem_Direct instance sets this to 'direct'.
	 * Babilim_Wp_Fs::atomic_rename() reads this to decide whether to use
	 * PHP's own native rename() or this class's own move().
	 *
	 * @var string
	 */
	public $method = 'direct';

	public function exists( $path ) {
		return file_exists( $path );
	}

	public function is_dir( $path ) {
		return is_dir( $path );
	}

	public function get_contents( $path ) {
		if ( ! empty( $GLOBALS['babilim_test_unreadable_paths'][ $path ] ) ) {
			return false;
		}
		return file_exists( $path ) ? file_get_contents( $path ) : false;
	}

	public function put_contents( $path, $contents, $mode = false ) {
		return false !== file_put_contents( $path, $contents );
	}

	public function delete( $path, $recursive = false, $type = false ) {
		if ( is_dir( $path ) ) {
			if ( $recursive ) {
				foreach ( scandir( $path ) as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$this->delete( $path . '/' . $name, true );
				}
			}
			return @rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- fails (returns false) for a non-empty dir when $recursive is false, matching WP_Filesystem_Direct's own contract.
		}
		return file_exists( $path ) ? unlink( $path ) : true;
	}

	public function mkdir( $path, $chmod = false, $chown = false, $chgrp = false ) {
		$GLOBALS['babilim_test_mkdir_calls'][]       = $path;
		$GLOBALS['babilim_test_mkdir_chmod_calls'][] = $chmod;
		return is_dir( $path ) || mkdir( $path, 0777 );
	}

	public function move( $from, $to, $overwrite = false ) {
		$GLOBALS['babilim_test_wp_filesystem_move_calls'][] = array( $from, $to );
		return rename( $from, $to );
	}

	/**
	 * Same contract as WordPress core's own WP_Filesystem_Direct::dirlist():
	 * $path may be a directory *or a file* -- a file path lists just that
	 * one file (keyed by its own basename, read from its parent
	 * directory), never false or an empty array -- and every entry
	 * carries core's own `type` field ('d' for a directory, else 'f').
	 * Kept faithful on purpose: a friendlier stand-in once hid a cleanup
	 * that only worked against the stand-in.
	 */
	public function dirlist( $path, $include_hidden = true, $recursive = false ) {
		$limit_file = false;
		if ( is_file( $path ) ) {
			$limit_file = basename( $path );
			$path       = dirname( $path );
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}
		$path    = rtrim( $path, '/' ) . '/';
		$entries = array();
		foreach ( scandir( $path ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( ! $include_hidden && '.' === $name[0] ) {
				continue;
			}
			if ( false !== $limit_file && $name !== $limit_file ) {
				continue;
			}
			$entry = array(
				'name' => $name,
				'type' => is_dir( $path . $name ) ? 'd' : 'f',
			);
			if ( 'd' === $entry['type'] ) {
				$entry['files'] = $recursive ? $this->dirlist( $path . $name, $include_hidden, $recursive ) : array();
			}
			$entries[ $name ] = $entry;
		}
		return $entries;
	}
}

$GLOBALS['wp_filesystem']                       = null;
$GLOBALS['babilim_test_mkdir_calls']             = array();
$GLOBALS['babilim_test_mkdir_chmod_calls']       = array();
$GLOBALS['babilim_test_wp_filesystem_should_fail'] = false;
$GLOBALS['babilim_test_unreadable_paths']        = array();
$GLOBALS['babilim_test_wp_filesystem_move_calls'] = array();

if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0751 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

function WP_Filesystem( $args = false, $context = false, $force = false ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
	if ( ! empty( $GLOBALS['babilim_test_wp_filesystem_should_fail'] ) ) {
		return false;
	}
	$GLOBALS['wp_filesystem'] = new Babilim_Test_Wp_Filesystem_Direct();
	return true;
}

// ---------------------------------------------------------------------
// Core download helpers -- real enough for the download code to be
// tested against without a real WordPress install or network call. Real
// WordPress core's own wp-admin/includes/file.php `download_url()`
// fetches a URL to a local temp file and returns that path (or a
// WP_Error); real core's wp-includes/functions.php `get_temp_dir()`
// returns a writable scratch directory. Both are recorded so a test can
// assert the code called them with the right arguments, and both
// are configurable so a test can simulate either outcome without ever
// making a real network call.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_download_url_calls']  = array();
$GLOBALS['babilim_test_download_url_return'] = null;

/**
 * $GLOBALS['babilim_test_download_url_return'] is either a single value
 * (a string path or a WP_Error), reused for every call -- the existing,
 * single-item convention every other test uses -- or an array, treated
 * as a per-call QUEUE (one value shifted off per call, left in place
 * once exhausted so a stray extra call still gets the last value rather
 * than a fatal array-index error). A real batch update of two or more of
 * this plugin's own items downloads each one separately, in the same
 * request, and each needs its own distinct fixture -- see
 * test-real-core-bulk-upgrade.php's own two-own-item regression test.
 */
function download_url( $url, $timeout = 300, $signature_verification = false ) {
	$GLOBALS['babilim_test_download_url_calls'][] = array(
		'url'                    => $url,
		'timeout'                => $timeout,
		'signature_verification' => $signature_verification,
	);
	$configured = $GLOBALS['babilim_test_download_url_return'];
	if ( is_array( $configured ) ) {
		$value = count( $configured ) > 1 ? array_shift( $GLOBALS['babilim_test_download_url_return'] ) : ( $configured[0] ?? null );
	} else {
		$value = $configured;
	}
	if ( $value instanceof WP_Error ) {
		return $value;
	}
	return is_string( $value )
		? $value
		: new WP_Error( 'babilim_test_no_download_url_response', 'No stubbed download_url() response configured.' );
}

function get_temp_dir() {
	return trailingslashit( BABILIM_TEST_TMP_ROOT . '/scratch' );
}

// ---------------------------------------------------------------------
// Locale recorders. These record every call so tests can
// assert the translation-loading layer calls these at the right time,
// without needing a real WordPress install.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_load_textdomain_calls']     = array();
$GLOBALS['babilim_test_determine_locale_calls']    = 0;
$GLOBALS['babilim_test_switch_to_locale_calls']    = array();
$GLOBALS['babilim_test_current_locale']            = 'en_US';
$GLOBALS['babilim_test_load_textdomain_reentrant'] = false;
$GLOBALS['babilim_test_load_textdomain_throws']    = false;

/**
 * Real WordPress core applies the `load_translation_file` filter *from
 * within* load_textdomain() itself -- which is exactly why the loading
 * layer needs a recursive lock. Setting
 * babilim_test_load_textdomain_reentrant reproduces that here, so a
 * test can prove the lock actually stops the layering loop from running
 * twice, instead of only being able to assert it in a real WordPress
 * install.
 */
function load_textdomain( $domain, $mofile, $locale = null ) {
	$GLOBALS['babilim_test_load_textdomain_calls'][] = array(
		'domain' => $domain,
		'mofile' => $mofile,
		'locale' => $locale,
	);

	if ( ! empty( $GLOBALS['babilim_test_load_textdomain_throws'] ) ) {
		throw new RuntimeException( 'babilim_test_load_textdomain_throws: simulated failure' );
	}

	if ( ! empty( $GLOBALS['babilim_test_load_textdomain_reentrant'] ) ) {
		apply_filters( 'load_translation_file', $mofile, $domain, $locale );
	}

	return file_exists( $mofile );
}

function determine_locale() {
	++$GLOBALS['babilim_test_determine_locale_calls'];
	return $GLOBALS['babilim_test_current_locale'];
}

function switch_to_locale( $locale ) {
	$GLOBALS['babilim_test_switch_to_locale_calls'][] = $locale;
	$previous                               = $GLOBALS['babilim_test_current_locale'];
	$GLOBALS['babilim_test_current_locale'] = $locale;
	return $previous !== $locale;
}

/**
 * Resets everything a single test can dirty -- options, the locale
 * recorders, and any hooks/dispatch counts added *after* the snapshot
 * below was taken -- back to that snapshot.
 *
 * Deliberately does NOT wipe the hook registry to empty: babilim.php
 * calls add_action() at load time (currently just `plugins_loaded`;
 * the loading layer's four filters and two actions land with a later
 * task), and a real WordPress request never "un-registers" a plugin's
 * hooks between one action and the next -- neither should a test.
 * Restoring the post-load snapshot instead keeps that load-time
 * registration visible in every test while still discarding whatever
 * hooks an individual test added for its own purposes, so tests still
 * can't leak hooks into each other.
 *
 * Call from a test's setUp().
 */
function babilim_test_reset() {
	Babilim_Locale_Label::reset_for_tests();
	$GLOBALS['babilim_test_filters']                = $GLOBALS['babilim_test_loaded_filters'];
	$GLOBALS['babilim_test_actions']                = $GLOBALS['babilim_test_loaded_actions'];
	$GLOBALS['babilim_test_action_dispatch_counts'] = $GLOBALS['babilim_test_loaded_action_dispatch_counts'];
	$GLOBALS['babilim_test_options']                = array();
	$GLOBALS['babilim_test_load_textdomain_calls']     = array();
	$GLOBALS['babilim_test_determine_locale_calls']    = 0;
	$GLOBALS['babilim_test_switch_to_locale_calls']    = array();
	$GLOBALS['babilim_test_current_locale']            = 'en_US';
	$GLOBALS['babilim_test_load_textdomain_reentrant'] = false;
	$GLOBALS['babilim_test_load_textdomain_throws']    = false;
	$GLOBALS['babilim_test_mkdir_calls']            = array();
	$GLOBALS['babilim_test_mkdir_chmod_calls']      = array();
	$GLOBALS['babilim_test_wp_filesystem_should_fail'] = false;
	$GLOBALS['wp_filesystem']                       = null;
	$GLOBALS['babilim_test_unreadable_paths']       = array();
	$GLOBALS['babilim_test_wp_filesystem_move_calls'] = array();
	$GLOBALS['babilim_test_get_plugins_return']     = array();
	$GLOBALS['babilim_test_wp_get_themes_return']   = array();
	$GLOBALS['babilim_test_wp_cache_delete_calls']       = array();
	$GLOBALS['babilim_test_object_cache']                = array();
	$GLOBALS['wpdb']                                     = new Babilim_Test_Wpdb( null );
	$GLOBALS['babilim_test_wp_opcache_invalidate_calls'] = array();
	$GLOBALS['babilim_test_fake_zips']              = array();
	$GLOBALS['babilim_test_is_plugin_active_return'] = array();
	$GLOBALS['babilim_test_wp_get_theme_return']     = new Babilim_Test_Default_Theme();
	$GLOBALS['babilim_test_cron_scheduled']          = array();
	$GLOBALS['babilim_test_cron_cleared']            = array();
	$GLOBALS['babilim_test_single_events_scheduled'] = array();
	$GLOBALS['babilim_test_transients']              = array();
	$GLOBALS['babilim_test_site_transients']         = array();
	$GLOBALS['babilim_test_site_options']            = array();
	$GLOBALS['babilim_test_site_transient_writes']   = array();
	$GLOBALS['babilim_test_get_plugins_throws']      = false;
	$GLOBALS['babilim_test_options_autoload']        = array();
	$GLOBALS['babilim_test_home_url']                = 'https://example.org';
	$GLOBALS['wp_version']                           = '6.7.1';
	$GLOBALS['babilim_test_wp_remote_requests']      = array();
	$GLOBALS['babilim_test_wp_remote_responses']     = array();
	$GLOBALS['babilim_test_rest_routes']             = array();
	$GLOBALS['babilim_test_download_url_calls']      = array();
	$GLOBALS['babilim_test_download_url_return']     = null;
	$GLOBALS['babilim_test_current_user_can']        = true;
	$GLOBALS['babilim_test_wp_die_calls']            = array();
	$GLOBALS['babilim_test_json']                    = null;
	$GLOBALS['babilim_test_wp_kses_calls']           = array();
	$GLOBALS['babilim_test_settings_errors']         = array();
	$GLOBALS['babilim_test_redirects']               = array();
	$GLOBALS['babilim_test_is_admin']                = false;
	$GLOBALS['babilim_test_registered_settings']     = array();
	$GLOBALS['babilim_test_menu_pages']              = array();
	$GLOBALS['babilim_test_submenu_pages']           = array();
	$GLOBALS['babilim_test_available_languages']     = array();
	$GLOBALS['babilim_test_pll_locales']             = array();
	$GLOBALS['babilim_test_multisite']               = false;
	$GLOBALS['babilim_test_inline_scripts']          = array();
	$GLOBALS['babilim_test_user_id']                 = 1;
	$GLOBALS['babilim_test_network_admin']           = false;
	$GLOBALS['babilim_test_sites']                   = array( 1 );
	$GLOBALS['babilim_test_current_blog_id']         = 1;
	$GLOBALS['babilim_test_blog_stack']              = array();
	$GLOBALS['babilim_test_blog_state']              = array();
	$GLOBALS['babilim_test_registered_styles']       = array();
	$GLOBALS['babilim_test_enqueued_styles']         = array();
	$GLOBALS['babilim_test_inline_styles']           = array();
	$GLOBALS['babilim_test_registered_scripts']      = array();
	$GLOBALS['babilim_test_enqueued_scripts']        = array();
	$GLOBALS['babilim_test_spawn_cron_calls']        = 0;
	$_REQUEST                                        = array();
}

// ---------------------------------------------------------------------
// Site URL. Real enough for Babilim_Api_Client's User-Agent header and
// Babilim_Site's registration payload without a real WordPress install.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_home_url'] = 'https://example.org';
$GLOBALS['wp_version']            = '6.7.1'; // WordPress core's own global, read directly by Babilim_Site (see Babilim_Inventory::effective_file()'s $wp_textdomain_registry for the same pattern).

function home_url( $path = '' ) {
	return rtrim( (string) $GLOBALS['babilim_test_home_url'], '/' ) . (string) $path;
}

// ---------------------------------------------------------------------
// HTTP API -- wp_remote_request() and its retrieve_*() helpers, real
// enough for Babilim_Wp_Http to be tested against without a real network
// call: zero network calls, zero real client-site calls. Every call is
// recorded; the response returned is
// popped off a caller-configured queue (the last entry repeats once the
// queue is down to one, so a test doesn't have to pad it out).
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_wp_remote_requests']  = array();
$GLOBALS['babilim_test_wp_remote_responses'] = array();

function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['babilim_test_wp_remote_requests'][] = array(
		'method' => $args['method'] ?? 'GET',
		'url'    => $url,
		'args'   => $args,
	);

	$queue = $GLOBALS['babilim_test_wp_remote_responses'];
	if ( empty( $queue ) ) {
		return new WP_Error( 'babilim_test_no_response', 'No stubbed wp_remote_request() response configured.' );
	}
	return count( $queue ) > 1 ? array_shift( $GLOBALS['babilim_test_wp_remote_responses'] ) : $queue[0];
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
}

function wp_remote_retrieve_header( $response, $header ) {
	if ( ! is_array( $response ) || ! is_array( $response['headers'] ?? null ) ) {
		return '';
	}
	foreach ( $response['headers'] as $name => $value ) {
		if ( 0 === strcasecmp( (string) $name, $header ) ) {
			return (string) $value;
		}
	}
	return '';
}

// ---------------------------------------------------------------------
// REST API -- just enough of register_rest_route()/WP_REST_Request/
// WP_REST_Response for Babilim_Site's challenge endpoint to be tested
// without a real WordPress install.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_rest_routes'] = array();

/**
 * Just enough of the real WP_REST_Server for register_challenge_route()
 * to reference WP_REST_Server::READABLE the way wp-rest-api's own
 * guidance recommends, rather than the bare 'GET' string -- the value is
 * identical either way, so every existing test asserting the literal
 * 'GET' string still holds.
 */
class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
}

function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
	$GLOBALS['babilim_test_rest_routes'][] = array(
		'namespace' => $namespace,
		'route'     => $route,
		'args'      => $args,
	);
	return true;
}

class WP_REST_Request {
	private $method;
	private $route;
	private $params;

	public function __construct( $method = 'GET', $route = '', $params = array() ) {
		$this->method = $method;
		$this->route  = $route;
		$this->params = $params;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function set_param( $key, $value ) {
		$this->params[ $key ] = $value;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}
}

class WP_REST_Response {
	private $data;
	private $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function set_data( $data ) {
		$this->data = $data;
	}

	public function get_status() {
		return $this->status;
	}

	public function set_status( $status ) {
		$this->status = $status;
	}
}

function rest_ensure_response( $response ) {
	return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
}

// ---------------------------------------------------------------------
// Admin: capabilities, nonces, wp_die(), the Settings API's error store,
// redirects, and a couple of small markup helpers -- real enough for the
// admin action handlers/views to be tested against without a real
// WordPress install.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_current_user_can'] = true;

/**
 * current_user_can() (wp-includes/capabilities.php). Test-configurable
 * via babilim_test_current_user_can: a bool (blanket grant/deny) or an
 * array of granted capability names.
 */
function current_user_can( $capability ) {
	$granted = $GLOBALS['babilim_test_current_user_can'];
	return is_array( $granted ) ? in_array( $capability, $granted, true ) : (bool) $granted;
}

/**
 * A stand-in for the exception WordPress core's own PHPUnit test suite
 * throws from its wp_die() override (WP_UnitTestCase_Base's
 * die_handler()), instead of a real exit() -- lets a test assert wp_die()
 * was reached without ending the PHPUnit process itself.
 */
class Babilim_Test_Wp_Die_Exception extends RuntimeException {}

$GLOBALS['babilim_test_wp_die_calls'] = array();

/**
 * wp_die() (wp-includes/functions.php). Records the call and throws
 * rather than exiting -- see Babilim_Test_Wp_Die_Exception.
 */
function wp_die( $message = '', $title = '', $args = array() ) {
	$GLOBALS['babilim_test_wp_die_calls'][] = array(
		'message' => $message,
		'title'   => $title,
		'args'    => $args,
	);
	throw new Babilim_Test_Wp_Die_Exception( is_string( $message ) ? $message : 'wp_die' );
}

/**
 * wp_create_nonce()/wp_verify_nonce() (wp-includes/pluggable.php). Not
 * real WordPress's tick-windowed HMAC -- a deterministic
 * action=>token mapping is enough to prove a caller's own nonce
 * round-trips (and a wrong/missing one doesn't), without reproducing
 * core's own crypto.
 */
function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . $action;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return is_string( $nonce ) && hash_equals( 'nonce-' . $action, $nonce ) ? 1 : false;
}

/**
 * check_admin_referer() (wp-admin/includes/template.php via
 * wp-includes/pluggable.php's check_ajax_referer() sibling): reads
 * $_REQUEST[$query_arg], verifies it against $action, and wp_die()s on
 * failure -- exactly core's own control flow.
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	$nonce  = $_REQUEST[ $query_arg ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this *is* the nonce check.
	$result = is_string( $nonce ) ? wp_verify_nonce( $nonce, $action ) : false;
	if ( ! $result ) {
		wp_die( 'The link you followed has expired.', 'Are you sure you want to do this?', array( 'response' => 403 ) );
	}
	return $result;
}

/**
 * Thrown by the wp_send_json_*() stand-ins instead of die(), the way
 * core's own PHPUnit suite does it, so a test can read what was sent.
 */
class Babilim_Test_Wp_Send_Json_Exception extends RuntimeException {}

$GLOBALS['babilim_test_json'] = null;

/**
 * check_ajax_referer() (wp-includes/pluggable.php): reads the nonce from
 * $_REQUEST[$query_arg] (default `_ajax_nonce`), wp_die()s on failure.
 */
function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	$field  = false === $query_arg ? '_ajax_nonce' : $query_arg;
	$nonce  = $_REQUEST[ $field ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this *is* the nonce check.
	$result = is_string( $nonce ) ? wp_verify_nonce( $nonce, $action ) : false;
	if ( ! $result && $stop ) {
		wp_die( '-1', '', array( 'response' => 403 ) );
	}
	return $result;
}

/** wp_send_json_success() (wp-includes/functions.php). Records, then throws instead of dying. */
function wp_send_json_success( $data = null, $status_code = null ) {
	$GLOBALS['babilim_test_json'] = array( 'success' => true, 'data' => $data, 'status' => $status_code );
	throw new Babilim_Test_Wp_Send_Json_Exception( 'success' );
}

/** wp_send_json_error() (wp-includes/functions.php). Records, then throws instead of dying. */
function wp_send_json_error( $data = null, $status_code = null ) {
	$GLOBALS['babilim_test_json'] = array( 'success' => false, 'data' => $data, 'status' => $status_code );
	throw new Babilim_Test_Wp_Send_Json_Exception( 'error' );
}

/**
 * wp_nonce_field() (wp-includes/functions.php). Returns/echoes a hidden
 * input carrying wp_create_nonce($action).
 */
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	// Same attribute order as core's wp_nonce_field(): `id` first, then
	// `name`, then `value` (the nonce itself is not attribute-escaped by
	// core -- it is alphanumeric). The referer field is simplified: core
	// prints the current URI with its own referer argument removed, this
	// stand-in prints the URI as is.
	$name  = esc_attr( $name );
	$field = '<input type="hidden" id="' . $name . '" name="' . $name . '" value="' . wp_create_nonce( $action ) . '" />';
	if ( $referer ) {
		$field .= '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) . '" />';
	}
	if ( $echo ) {
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped markup, same as core's own wp_nonce_field().
	}
	return $field;
}

$GLOBALS['babilim_test_admin_url'] = 'http://example.org/wp-admin/';

/** admin_url() (wp-includes/link-template.php). */
function admin_url( $path = '' ) {
	return rtrim( (string) $GLOBALS['babilim_test_admin_url'], '/' ) . '/' . ltrim( (string) $path, '/' );
}

/** sanitize_key() (wp-includes/formatting.php): lowercase, [a-z0-9_-] only. */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * wp_kses_post() (wp-includes/kses.php). Not core's full allow-listed
 * HTML sanitizer -- this plugin's own views only ever pass it their own
 * already-built, already-escaped markup (never raw user input; every
 * dynamic value is run through esc_html()/esc_attr()/esc_url() first),
 * so a simple denylist of the genuinely dangerous elements (script/
 * style/iframe/object and any `on*=`/`javascript:` attribute) is
 * "real enough" for what this plugin's own tests need to prove -- that
 * dangerous content submitted through it doesn't survive, not that this
 * reproduces core's full allow-listed tag/attribute table.
 */
function wp_kses_post( $content ) {
	$content = (string) $content;
	$content = preg_replace( '#<(script|style|iframe|object)\b[^>]*>.*?</\\1>#is', '', $content );
	$content = preg_replace( '/\son\w+\s*=\s*(["\']).*?\1/i', '', $content );
	$content = preg_replace( '/javascript\s*:/i', '', $content );
	return $content;
}

$GLOBALS['babilim_test_wp_kses_calls'] = array();

/**
 * Every distinct lowercase tag name literally present in $html --
 * shared by wp_kses() and babilim_test_wp_kses_strip_disallowed()
 * below.
 */
function babilim_test_wp_kses_tag_names( string $html ): array {
	preg_match_all( '/<\/?([a-zA-Z][a-zA-Z0-9]*)\b/', $html, $m );
	return array_fill_keys( array_map( 'strtolower', $m[1] ), true );
}

/**
 * Strips every tag NOT a key of $allowed, opening/closing markup AND
 * its own inner content -- matching real core's handling of an
 * unrecognized element (a disallowed tag is never in an allowlist
 * because its content is meant to survive as markup; stripping the
 * content along with it is the safe, conservative reading for a test
 * double -- see wp_kses()'s own docblock below).
 */
function babilim_test_wp_kses_strip_disallowed( string $html, array $allowed ): string {
	foreach ( array_keys( babilim_test_wp_kses_tag_names( $html ) ) as $tag ) {
		if ( isset( $allowed[ $tag ] ) ) {
			continue;
		}
		// Paired tag with content: <tag ...>...</tag>, non-greedy, no
		// same-named nesting assumed (true of every tag this plugin's
		// own admin screens ever emit).
		$html = preg_replace( '#<' . preg_quote( $tag, '#' ) . '\b[^>]*>.*?</' . preg_quote( $tag, '#' ) . '>#is', '', $html );
		// Any remaining self-closed or unpaired occurrence of the tag.
		$html = preg_replace( '#</?' . preg_quote( $tag, '#' ) . '\b[^>]*/?>#i', '', $html );
	}
	return $html;
}

/**
 * wp_kses() (wp-includes/kses.php) -- a real, allowlist-driven filter,
 * not the denylist wp_kses_post() above stands in for: any tag not a
 * key of $allowed_html is stripped entirely (see
 * babilim_test_wp_kses_strip_disallowed()); any attribute not a key of
 * that tag's own allowed_html sub-array is dropped from an otherwise-
 * kept tag. Every call is recorded (with the $allowed_html it was
 * given) so a test can assert a caller actually routes through this,
 * not a bypassed raw echo. Not core's full allow-listed sanitizer (no
 * URL-protocol filtering, no malformed-HTML recovery) -- "real enough"
 * for what this plugin's own render() needs proven: a tag or attribute
 * outside the given allowlist does not survive.
 */
function wp_kses( $string, $allowed_html, $allowed_protocols = array() ) {
	$GLOBALS['babilim_test_wp_kses_calls'][] = array(
		'string'       => $string,
		'allowed_html' => $allowed_html,
	);
	$allowed = is_array( $allowed_html ) ? $allowed_html : array();
	$string  = (string) $string;
	// HTML comments never survive real wp_kses() either.
	$string = preg_replace( '/<!--.*?-->/s', '', $string );
	$string = babilim_test_wp_kses_strip_disallowed( $string, $allowed );

	// Every surviving (allowed) tag: drop any attribute not in that
	// tag's own allowlist.
	return preg_replace_callback(
		'/<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^<>]*)?)(\/?)>/',
		static function ( $m ) use ( $allowed ) {
			$tag = strtolower( $m[1] );
			if ( ! isset( $allowed[ $tag ] ) ) {
				return ''; // Already handled by the strip pass above, but stay safe.
			}
			$allowed_attrs = is_array( $allowed[ $tag ] ) ? $allowed[ $tag ] : array();
			$kept          = '';
			if ( preg_match_all( '/([a-zA-Z0-9_-]+)(?:\s*=\s*(".*?"|\'.*?\'|[^\s"\'>]+))?/', $m[2], $am, PREG_SET_ORDER ) ) {
				foreach ( $am as $a ) {
					$name = strtolower( $a[1] );
					if ( ! isset( $allowed_attrs[ $name ] ) ) {
						continue;
					}
					if ( isset( $a[2] ) ) {
						$kept .= ' ' . $name . '=' . $a[2];
					} else {
						$kept .= ' ' . $name;
					}
				}
			}
			return '<' . $tag . $kept . ( '/' === $m[3] ? ' /' : '' ) . '>';
		},
		$string
	);
}

$GLOBALS['babilim_test_settings_errors'] = array();

/** add_settings_error() (wp-admin/includes/template.php). */
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['babilim_test_settings_errors'][] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

/** get_settings_errors() (wp-admin/includes/template.php). */
function get_settings_errors( $setting = '', $sanitize = false ) {
	if ( '' === $setting ) {
		return $GLOBALS['babilim_test_settings_errors'];
	}
	return array_values(
		array_filter(
			$GLOBALS['babilim_test_settings_errors'],
			static function ( $error ) use ( $setting ) {
				return $error['setting'] === $setting;
			}
		)
	);
}

$GLOBALS['babilim_test_redirects'] = array();

/** wp_safe_redirect()/wp_redirect() (wp-includes/pluggable.php). Recorded only -- never actually redirects a PHPUnit process. */
function wp_safe_redirect( $location, $status = 302 ) {
	$GLOBALS['babilim_test_redirects'][] = array( 'location' => $location, 'status' => $status );
	return true;
}

function wp_redirect( $location, $status = 302 ) {
	return wp_safe_redirect( $location, $status );
}

$GLOBALS['babilim_test_is_admin'] = false;

/** is_admin() (wp-includes/load.php). */
function is_admin() {
	return (bool) $GLOBALS['babilim_test_is_admin'];
}

$GLOBALS['babilim_test_menu_pages']    = array();
$GLOBALS['babilim_test_submenu_pages'] = array();

/**
 * The `$admin_page_hooks` global core keeps in wp-admin/includes/plugin.php:
 * top-level menu slug => sanitize_title( menu title ). get_plugin_page_hookname()
 * reads it to build a submenu page's hook suffix.
 */
$GLOBALS['babilim_test_admin_page_hooks'] = array();

/**
 * sanitize_title()'s slug shape for the plain ASCII titles these doubles
 * are handed: lowercase, every run of other characters becomes one dash,
 * no leading/trailing dash.
 */
function babilim_test_sanitize_title( $title ) {
	return trim( (string) preg_replace( '/[^a-z0-9_]+/', '-', strtolower( (string) $title ) ), '-' );
}

/**
 * add_menu_page() (wp-admin/includes/plugin.php). Returns the page's hook
 * suffix -- `toplevel_page_{slug}`, exactly what core's
 * get_plugin_page_hookname() builds for a top-level page -- which is NOT
 * the menu slug itself, and is what `admin_enqueue_scripts` callbacks are
 * handed as their $hook on that page.
 */
function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['babilim_test_menu_pages'][]                = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'icon_url', 'position' );
	$GLOBALS['babilim_test_admin_page_hooks'][ $menu_slug ] = babilim_test_sanitize_title( $menu_title );
	return 'toplevel_page_' . preg_replace( '!\.php!', '', $menu_slug );
}

/**
 * add_submenu_page() (wp-admin/includes/plugin.php). Returns
 * `{sanitized parent menu title}_page_{slug}` when the parent is a
 * registered top-level page, `admin_page_{slug}` otherwise -- core's
 * get_plugin_page_hookname() rule.
 */
function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
	$GLOBALS['babilim_test_submenu_pages'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'position' );
	$page_type                               = $GLOBALS['babilim_test_admin_page_hooks'][ $parent_slug ] ?? 'admin';
	return $page_type . '_page_' . preg_replace( '!\.php!', '', $menu_slug );
}

$GLOBALS['babilim_test_registered_styles'] = array();
$GLOBALS['babilim_test_enqueued_styles']   = array();
$GLOBALS['babilim_test_inline_styles']     = array();

/** wp_register_style() (wp-includes/functions.wp-styles.php). */
function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['babilim_test_registered_styles'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
	return true;
}

/** wp_enqueue_style() (wp-includes/functions.wp-styles.php). */
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	if ( '' !== $src && ! isset( $GLOBALS['babilim_test_registered_styles'][ $handle ] ) ) {
		wp_register_style( $handle, $src, $deps, $ver, $media );
	}
	$GLOBALS['babilim_test_enqueued_styles'][ $handle ] = true;
}

/**
 * wp_add_inline_style() (wp-includes/functions.wp-styles.php) -- real
 * core silently no-ops when $handle was never registered; this stand-in
 * matches that instead of recording an inline style no page will ever
 * actually receive.
 */
function wp_add_inline_style( $handle, $data ) {
	if ( ! isset( $GLOBALS['babilim_test_registered_styles'][ $handle ] ) ) {
		return false;
	}
	$GLOBALS['babilim_test_inline_styles'][ $handle ][] = (string) $data;
	return true;
}

$GLOBALS['babilim_test_registered_scripts'] = array();
$GLOBALS['babilim_test_enqueued_scripts']   = array();
$GLOBALS['babilim_test_spawn_cron_calls']   = 0;

/** wp_register_script() (wp-includes/functions.wp-scripts.php). */
function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
	$GLOBALS['babilim_test_registered_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'args' );
	return true;
}

/** wp_enqueue_script() (wp-includes/functions.wp-scripts.php). */
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
	if ( '' !== $src && ! isset( $GLOBALS['babilim_test_registered_scripts'][ $handle ] ) ) {
		wp_register_script( $handle, $src, $deps, $ver, $args );
	}
	$GLOBALS['babilim_test_enqueued_scripts'][ $handle ] = true;
}

/** wp_add_inline_script() (wp-includes/functions.wp-scripts.php) -- records the code per handle. */
function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['babilim_test_inline_scripts'][ $handle ][] = (string) $data;
	return true;
}

/** plugins_url() (wp-includes/link-template.php). */
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.org/wp-content/plugins/babilim/' . ltrim( (string) $path, '/' );
}

/** spawn_cron() (wp-includes/cron.php) -- counted, never spawns anything. */
function spawn_cron( $gmt_time = 0 ) {
	++$GLOBALS['babilim_test_spawn_cron_calls'];
	return true;
}

/** get_current_user_id() (wp-includes/user.php). */
function get_current_user_id() {
	return $GLOBALS['babilim_test_user_id'] ?? 1;
}

/** selected() (wp-includes/general-template.php). */
// Real core (wp-includes/general-template.php's __checked_selected_helper())
// emits single quotes, e.g. `selected='selected'`/`checked='checked'` --
// double quotes here would make a test asserting on the exact attribute
// string more lenient than real WordPress.
function selected( $value1, $value2 = true, $echo = true ) {
	$result = ( (string) $value1 === (string) $value2 ) ? " selected='selected'" : '';
	if ( $echo ) {
		echo $result;
	}
	return $result;
}

/** checked() (wp-includes/general-template.php). */
function checked( $value1, $value2 = true, $echo = true ) {
	$result = ( (string) $value1 === (string) $value2 ) ? " checked='checked'" : '';
	if ( $echo ) {
		echo $result;
	}
	return $result;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( __( $text, $domain ) );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( __( $text, $domain ) );
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( __( $text, $domain ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function esc_attr_e( $text, $domain = 'default' ) {
	echo esc_attr( __( $text, $domain ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

// ---------------------------------------------------------------------
// Load the plugin itself, then fire the two actions the translation-
// loading layer hooks, the same way a real WordPress request would
// after core's own setup -- so every test can rely on that
// having already happened once by the time they run. The snapshot
// taken right after is what babilim_test_reset() restores, instead of
// wiping the registry -- see that function's docblock.
// ---------------------------------------------------------------------

require dirname( __DIR__ ) . '/babilim.php';
require __DIR__ . '/fixtures/class-babilim-memory-fs.php';
require __DIR__ . '/fixtures/class-babilim-schema-validator.php';
// Shared test doubles/stand-ins several test files need regardless of
// which one PHPUnit happens to load first -- loaded once here rather
// than left to whichever individual test file's own declaration runs
// first, so any single test file also passes in isolation.
require __DIR__ . '/fixtures/class-babilim-fake-http.php';
require __DIR__ . '/fixtures/wp-locale-stubs.php';
require __DIR__ . '/fixtures/mo-codec.php';

do_action( 'after_setup_theme' );
do_action( 'change_locale', $GLOBALS['babilim_test_current_locale'] );

$GLOBALS['babilim_test_loaded_filters']                = $GLOBALS['babilim_test_filters'];
$GLOBALS['babilim_test_loaded_actions']                = $GLOBALS['babilim_test_actions'];
$GLOBALS['babilim_test_loaded_action_dispatch_counts'] = $GLOBALS['babilim_test_action_dispatch_counts'];


// ---------------------------------------------------------------------
// Settings API and plugin-path helpers. register_setting() is recorded
// and hooks its sanitize_callback onto `sanitize_option_{$option}` the
// way real core does, so update_option() above really sanitizes.
// ---------------------------------------------------------------------

$GLOBALS['babilim_test_registered_settings'] = array();

function register_setting( $option_group, $option_name, $args = array() ) {
	$defaults = array(
		'type'              => 'string',
		'default'           => null,
		'sanitize_callback' => null,
	);
	$args     = array_merge( $defaults, (array) $args );
	$GLOBALS['babilim_test_registered_settings'][ $option_name ] = array( 'group' => $option_group ) + $args;
	if ( is_callable( $args['sanitize_callback'] ) ) {
		add_filter( "sanitize_option_{$option_name}", $args['sanitize_callback'], 10, 2 );
	}
}

/** plugin_basename() (wp-includes/plugin.php): `{plugin-dir}/{file}` relative to the plugins directory. */
function plugin_basename( $file ) {
	$file = str_replace( '\\', '/', $file );
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

// ---------------------------------------------------------------------
// WP_List_Table stand-in (wp-admin/includes/class-wp-list-table.php),
// add_query_arg() and remove_query_arg(). Only the parts this plugin's
// own table uses; the markup shape follows core's.
// ---------------------------------------------------------------------

/** add_query_arg() (wp-includes/functions.php), array form with an explicit URL. */
function add_query_arg( ...$args ) {
	$params = is_array( $args[0] ) ? $args[0] : array( $args[0] => $args[1] );
	$url    = is_array( $args[0] ) ? ( $args[1] ?? '' ) : ( $args[2] ?? '' );
	$url    = (string) $url;
	$base   = $url;
	$query  = '';
	$pos    = strpos( $url, '?' );
	if ( false !== $pos ) {
		$base  = substr( $url, 0, $pos );
		$query = substr( $url, $pos + 1 );
	}
	parse_str( $query, $existing );
	$merged = array_merge( $existing, $params );
	foreach ( $merged as $key => $value ) {
		if ( false === $value || null === $value ) {
			unset( $merged[ $key ] );
		}
	}
	// Like core's build_query(): the values are NOT encoded here, callers
	// must pass them encoded (see add_query_arg()'s own docblock).
	$pairs = array();
	foreach ( $merged as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}
	return $base . ( array() === $pairs ? '' : '?' . implode( '&', $pairs ) );
}

/** remove_query_arg() (wp-includes/functions.php). */
function remove_query_arg( $key, $query = '' ) {
	$keys = (array) $key;
	$map  = array();
	foreach ( $keys as $k ) {
		$map[ $k ] = false;
	}
	return add_query_arg( $map, (string) $query );
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	/** A small stand-in for core's WP_List_Table. */
	class WP_List_Table { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		public $items = array();
		protected $_args = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
		protected $_pagination_args = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
		protected $_column_headers; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

		public function __construct( $args = array() ) {
			$this->_args = array_merge( array( 'plural' => '', 'singular' => '', 'ajax' => false ), (array) $args );
		}

		public function get_items_per_page( $option, $default_value = 20 ) {
			return (int) $default_value;
		}

		public function get_pagenum() {
			$n = isset( $_REQUEST['paged'] ) ? absint_for_tests( $_REQUEST['paged'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return max( 1, $n );
		}

		public function set_pagination_args( $args ) {
			$this->_pagination_args = $args;
		}

		public function get_columns() {
			return array();
		}

		public function get_bulk_actions() {
			return array();
		}

		public function get_views() {
			return array();
		}

		protected function get_views_links() {
			return $this->get_views();
		}

		public function has_items() {
			return ! empty( $this->items );
		}

		public function no_items() {
			echo 'No items found.';
		}

		protected function column_default( $item, $column_name ) {
			return '';
		}

		protected function row_actions( $actions, $always_visible = false ) {
			if ( array() === $actions ) {
				return '';
			}
			return '<div class="' . ( $always_visible ? 'row-actions visible' : 'row-actions' ) . '"><span>' . implode( ' | </span><span>', $actions ) . '</span></div>';
		}

		public function views() {
			$views = $this->get_views();
			if ( array() === $views ) {
				return;
			}
			echo '<ul class="subsubsub">';
			foreach ( $views as $class => $view ) {
				echo '<li class="' . esc_attr( (string) $class ) . '">' . $view . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul>';
		}

		public function search_box( $text, $input_id ) {
			// Core: nothing to search and nothing searched for -> no box.
			if ( empty( $_REQUEST['s'] ) && ! $this->has_items() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			$id = $input_id . '-search-input';
			echo '<p class="search-box"><label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $text ) . ':</label>'
				. '<input type="search" id="' . esc_attr( $id ) . '" name="s" value="' . esc_attr( (string) ( $_GET['s'] ?? '' ) ) . '" />' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				. '<input type="submit" id="search-submit" class="button" value="' . esc_attr( $text ) . '" /></p>';
		}

		protected function extra_tablenav( $which ) {}

		protected function bulk_actions( $which = '' ) {
			$name    = 'top' === $which ? 'action' : 'action2';
			$actions = $this->get_bulk_actions();
			if ( array() === $actions ) {
				return;
			}
			echo '<select name="' . esc_attr( $name ) . '" id="bulk-action-selector-' . esc_attr( $which ) . '"><option value="-1">Bulk actions</option>';
			foreach ( $actions as $key => $label ) {
				echo '<option value="' . esc_attr( (string) $key ) . '">' . esc_html( (string) $label ) . '</option>';
			}
			echo '</select><input type="submit" class="button action" value="Apply" />';
		}

		protected function pagination( $which ) {
			$total = (int) ( $this->_pagination_args['total_items'] ?? 0 );
			$pages = (int) ( $this->_pagination_args['total_pages'] ?? 1 );
			if ( $total < 1 ) {
				return;
			}
			echo '<div class="tablenav-pages"><span class="displaying-num">' . (int) $total . ' items</span>';
			if ( $pages > 1 ) {
				$base = (string) ( $GLOBALS['babilim_test_request_uri'] ?? 'http://example.org/wp-admin/admin.php?page=babilim' );
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
				for ( $i = 1; $i <= $pages; $i++ ) {
					echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $i, $base ) ) . '"><span class="screen-reader-text">Page ' . (int) $i . '</span><span aria-hidden="true">' . (int) $i . '</span></a>';
				}
				echo '<span id="table-paging" class="paging-input"></span>'
					. '<input class="current-page" id="current-page-selector" type="text" name="paged" value="' . (int) $this->get_pagenum() . '" size="2" aria-describedby="table-paging" />';
			}
			echo '</div>';
		}

		protected function display_tablenav( $which ) {
			if ( 'top' === $which ) {
				wp_nonce_field( 'bulk-' . $this->_args['plural'] );
			}
			echo '<div class="tablenav ' . esc_attr( $which ) . '">';
			if ( $this->has_items() ) {
				echo '<div class="alignleft actions bulkactions">';
				$this->bulk_actions( $which );
				echo '</div>';
			}
			$this->extra_tablenav( $which );
			$this->pagination( $which );
			echo '<br class="clear" /></div>';
		}

		public function display() {
			$this->display_tablenav( 'top' );
			echo '<table class="wp-list-table widefat fixed striped ' . esc_attr( $this->_args['plural'] ) . '"><thead><tr>';
			foreach ( $this->get_columns() as $key => $label ) {
				if ( 'cb' === $key ) {
					echo '<td id="cb" class="manage-column column-cb check-column"><input type="checkbox" aria-label="Select all" /></td>';
				} else {
					echo '<th scope="col" id="' . esc_attr( (string) $key ) . '" class="manage-column column-' . esc_attr( (string) $key ) . '">' . esc_html( (string) $label ) . '</th>';
				}
			}
			$header_row = '';
			foreach ( $this->get_columns() as $key => $label ) {
				$header_row .= 'cb' === $key
					? '<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="Select all" /></td>'
					: '<th scope="col" class="manage-column column-' . esc_attr( (string) $key ) . '">' . esc_html( (string) $label ) . '</th>';
			}
			echo '</tr></thead><tbody id="the-list">';
			if ( $this->has_items() ) {
				foreach ( $this->items as $item ) {
					echo '<tr>';
					foreach ( $this->get_columns() as $key => $label ) {
						$method = 'column_' . $key;
						if ( 'cb' === $key ) {
							echo '<th scope="row" class="check-column">' . $this->$method( $item ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} elseif ( method_exists( $this, $method ) ) {
							echo '<td class="' . esc_attr( (string) $key ) . ' column-' . esc_attr( (string) $key ) . '" data-colname="' . esc_attr( (string) $label ) . '">' . $this->$method( $item ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo '<td>' . $this->column_default( $item, $key ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
					}
					echo '</tr>';
				}
			} else {
				echo '<tr class="no-items"><td class="colspanchange" colspan="' . (int) count( $this->get_columns() ) . '">';
				$this->no_items();
				echo '</td></tr>';
			}
			echo '</tbody><tfoot><tr>' . $header_row . '</tr></tfoot></table>';
			$this->display_tablenav( 'bottom' );
		}
	}

	function absint_for_tests( $value ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO
		return abs( (int) $value );
	}
}
