<?php
/**
 * Exactly the methods `Babilim`'s own assembly layer calls on whatever
 * `updater()` returns -- narrow on purpose (Babilim_Updater's own
 * `register()`/`source_selection()` aren't here: neither is ever called
 * from `Babilim` itself; see class-babilim-updater.php's own docblock
 * for why). `Babilim_Updater` implements this; a test double can too,
 * without needing to subclass a `final` class or fake its real
 * dependencies (state/installer/api client/filesystem), to prove
 * `Babilim`'s own proxy methods actually forward to whatever this
 * interface resolves to, rather than short-circuiting and returning
 * their own input unchanged.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The subset of Babilim_Updater's public surface the assembly class depends on.
 */
interface Babilim_Updater_Contract {

	/**
	 * The callback bound to WordPress core's own plugin-update transient
	 * filter -- see class-babilim-updater.php's register() for exactly
	 * which one.
	 *
	 * @param mixed $transient The stored transient value, or false before core has ever populated it.
	 * @return object
	 */
	public function inject_plugin_transient( $transient ): object;

	/**
	 * The callback bound to WordPress core's own theme-update transient
	 * filter -- see class-babilim-updater.php's register() for exactly
	 * which one.
	 *
	 * @param mixed $transient The stored transient value, or false before core has ever populated it.
	 * @return object
	 */
	public function inject_theme_transient( $transient ): object;

	/**
	 * `upgrader_package_options` callback.
	 *
	 * @param array $options WP_Upgrader::run()'s own $options.
	 * @return array
	 */
	public function package_options( array $options ): array;

	/**
	 * `upgrader_pre_download` callback.
	 *
	 * @param mixed  $reply      False (the default), or another filter's
	 *                           already-short-circuited value.
	 * @param string $package    The transient entry's own `package` URL.
	 * @param mixed  $upgrader   The WP_Upgrader instance.
	 * @param array  $hook_extra Extra data passed to hooked filters.
	 * @return mixed
	 */
	public function pre_download( $reply, string $package, $upgrader, array $hook_extra );

	/**
	 * `upgrader_install_package_result` callback.
	 *
	 * @param array|WP_Error $result     WP_Upgrader::install_package()'s own result.
	 * @param array          $hook_extra Extra data passed to hooked filters.
	 * @return array|WP_Error
	 */
	public function install_package_result( $result, array $hook_extra );

	/**
	 * Removes every stale `_incoming/{token}` entry older than
	 * $max_age_seconds -- called from the daily maintenance cron.
	 *
	 * @param int $max_age_seconds Default 86400 (one day).
	 * @return int How many stale entries were removed.
	 */
	public function sweep_stale_incoming_dirs( int $max_age_seconds = 86400 ): int;
}
