<?php
/**
 * Plugin Name:       Babilim
 * Description:       Fills in missing translations for the plugins and themes already installed on your site.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Babilim
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       babilim
 * Domain Path:       /languages
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

if ( ! defined( 'BABILIM_VERSION' ) ) {
	define( 'BABILIM_VERSION', '0.1.0' );
}

if ( ! defined( 'BABILIM_FILE' ) ) {
	define( 'BABILIM_FILE', __FILE__ );
}

if ( ! defined( 'BABILIM_DIR' ) ) {
	define( 'BABILIM_DIR', __DIR__ . '/' );
}

/**
 * The Babilim service's API base. This line and readme.txt's External
 * services section are the only two places a literal service URL
 * appears in this plugin, and they must always name the same one. The
 * support email address (Babilim_Admin::SUPPORT_EMAIL and readme.txt)
 * also carries the product domain, as plain text only.
 * Changing the service's domain means changing this line and
 * readme.txt, nothing else.
 */
if ( ! defined( 'BABILIM_API_BASE' ) ) {
	define( 'BABILIM_API_BASE', 'https://api.babilim.org' );
}

/**
 * The candidate include-file paths for a class name, in the order the
 * autoloader checks them: `includes/class-babilim-foo-bar.php` first,
 * then the same filename under `admin/`. A class outside the
 * `Babilim_` prefix always gets an empty array -- this is the guard
 * that keeps the autoloader from ever being asked about an unrelated
 * class, so other autoloaders (WordPress core's, another plugin's) get
 * their turn undisturbed. It's a pure function (no filesystem access,
 * no side effects) specifically so the guard can be asserted on
 * directly in tests, not only observed indirectly through whether a
 * file happens to exist.
 *
 * @param string $class Fully-qualified class name.
 * @return string[] Candidate file paths, or an empty array.
 */
function babilim_autoload_candidates( $class ) {
	if ( 0 !== strpos( $class, 'Babilim_' ) ) {
		return array();
	}

	$filename = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

	return array(
		BABILIM_DIR . 'includes/' . $filename,
		BABILIM_DIR . 'admin/' . $filename,
	);
}

/**
 * Autoloads a `Babilim_*` class from the first candidate path that
 * exists. File-existence is checked before every `require` so a class
 * that genuinely doesn't exist yet fails autoloading cleanly
 * (`class_exists()` returns false) instead of a fatal error.
 *
 * @param string $class Fully-qualified class name being autoloaded.
 */
function babilim_autoload( $class ) {
	foreach ( babilim_autoload_candidates( $class ) as $path ) {
		if ( file_exists( $path ) ) {
			require $path;
			return;
		}
	}
}
spl_autoload_register( 'babilim_autoload' );

// The `Babilim` class itself doesn't fit the Babilim_Foo_Bar -> file
// convention above, so it's loaded directly rather than autoloaded.
require_once BABILIM_DIR . 'includes/class-babilim.php';

/*
 * Registered at the top level, before this file (or anything it loads
 * synchronously below) makes any `__()` call -- required so the
 * translation-loading layer's filters can be in place before core or
 * any other plugin's translated strings are requested.
 */
Babilim_Loader::register();

add_action( 'plugins_loaded', 'babilim_bootstrap' );

/**
 * Assembles the rest of the plugin once every plugin has had its main
 * file loaded. Nothing to assemble yet -- see Babilim::init().
 */
function babilim_bootstrap() {
	Babilim::instance()->init();
}

if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook( BABILIM_FILE, 'babilim_deactivate' );
}

/**
 * Clears the `babilim_maintenance` daily cron event and the
 * `babilim_sync` twice-daily cron event this plugin schedules (see
 * Babilim::register_maintenance_cron()/register_sync_cron()) so a
 * deactivated Babilim never leaves a stray wp-cron event behind --
 * standard WordPress plugin hygiene (see the wp-plugin-development
 * skill's references/data-and-cron.md). The same jobs can be run by hand
 * from the admin screens (Check again now, Rebuild index now, Sweep now);
 * there is no WP-CLI command.
 */
function babilim_deactivate() {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'babilim_maintenance' );
		wp_clear_scheduled_hook( 'babilim_sync' );
		wp_clear_scheduled_hook( 'babilim_sync_once' );
	}
}
