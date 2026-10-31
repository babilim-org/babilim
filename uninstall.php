<?php
/**
 * Uninstall handler.
 *
 * WordPress core requires this file directly when the plugin is deleted
 * through the admin ("Delete" on the Plugins screen) -- it never loads
 * babilim.php first, so nothing from there (constants, the autoloader,
 * hooks) can be relied on here.
 *
 * By default the site's identity is KEPT: the install keypair's secret
 * key (`babilim_install_sk`), the site id (`babilim_site_id`) and the
 * site token (`babilim_site_token`) stay in the database, so deleting
 * and reinstalling the plugin picks the same registration back up and
 * the paid license keeps working without any action. The site owner can
 * opt out with the "Delete all Babilim data on uninstall" setting
 * (`babilim_delete_data_on_uninstall`, off by default); when it is on,
 * the identity is deleted too, and the license can then only be
 * restored by contacting Babilim. Everything else is removed either way:
 * every other option and transient, both scheduled events, and every
 * Babilim-managed language file under `WP_LANG_DIR/babilim/` -- served
 * files, `default/` supplements, history and the manifest. The site
 * owner's "Keep my own translation overrides" setting decides whether `custom/`
 * (and the root's guard files) stay. The plugin stores no user or post
 * meta. Nothing outside `WP_LANG_DIR/babilim/` is touched (core's own
 * `WP_LANG_DIR/plugins` and `themes` included), and no request is made
 * to the service.
 *
 * On multisite, the option/transient/cron cleanup is a per-site concern
 * (each site has its own options table and its own scheduled-cron
 * option), so it runs once per site via `get_sites()`/`switch_to_blog()`
 * -- otherwise deleting the plugin network-wide would only ever clean
 * the site the deletion happened to run on, leaving every other site's
 * own options behind. The language-file
 * cleanup is different: `WP_LANG_DIR` is one physical directory shared
 * by the whole network, so it is purged exactly once, *after* every
 * site's own option cleanup has run -- and if any one site had "Keep my own
 * translation overrides" on, `custom/` is kept for the whole shared tree,
 * regardless of what any other site (or whichever site happens to be
 * processed last) had set. A site's own override files living under a
 * folder every other site can also read from is exactly the kind of
 * data a single site's admin asking to keep it should be able to
 * protect on its own -- one site turning it off must never be able to
 * delete what another site's admin asked to keep. This also makes the
 * outcome independent of site-ID iteration order, which a "whichever
 * site's setting the loop happens to see first/last wins" rule would
 * not have been.
 *
 * All of the option/transient/cron deletion above -- for every site --
 * happens using nothing but plain WordPress core functions, before this
 * plugin's own class files are ever `require`d: a broken class file
 * (this plugin's own release, a bad update, a half-written disk) can
 * therefore never stop the deletion. Loading those classes and using
 * them (the language-file purge below) happens afterwards, in one
 * best-effort block.
 *
 * @package Babilim
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'babilim_uninstall_clean_current_site_state' ) ) {
	/**
	 * Deletes every option, transient and scheduled cron event this
	 * plugin owns for the *current* site -- the one `switch_to_blog()`
	 * has already pointed at, or the only site there is when this isn't
	 * multisite. Uses only plain WordPress core functions (no `require`
	 * of any of this plugin's own files), so it can never fail because
	 * one of those is broken.
	 *
	 * Wrapped in `function_exists()` because uninstall.php itself has
	 * no include guard of its own (WordPress core `require`s it
	 * directly, and this suite's own tests `require` it once per test
	 * method) -- a plain function declaration here would fatal with
	 * "Cannot redeclare" the second time either happens in the same
	 * process.
	 *
	 * The site identity (keypair, site id, token) is deleted only when this
	 * site's "Delete all Babilim data on uninstall" setting is on.
	 *
	 * @return bool Whether this site's own "Keep my own translation overrides"
	 *              setting was on (the default when the option was
	 *              never saved) -- read before the option itself is
	 *              deleted, so the caller can fold it into the decision
	 *              for the language-file purge that runs once, after
	 *              every site has been processed (see the file
	 *              docblock above).
	 */
	function babilim_uninstall_clean_current_site_state(): bool {
		// -- Babilim_Site: site identity -- kept unless the owner opted in
		// to deleting everything. Read before the setting itself goes.
		if ( get_option( 'babilim_delete_data_on_uninstall', false ) ) {
			delete_option( 'babilim_install_sk' );
			delete_option( 'babilim_site_id' );
			delete_option( 'babilim_site_token' );
			delete_option( 'babilim_identity' );
		}
		delete_option( 'babilim_delete_data_on_uninstall' );

		// -- Babilim_Site: registration and verification progress ----------
		delete_option( 'babilim_pending_verification' );
		delete_option( 'babilim_verify_conflict' );
		delete_option( 'babilim_verify_attempts' );
		delete_option( 'babilim_verify_stalled' );
		delete_option( 'babilim_verification_warning' );
		delete_option( 'babilim_sync_last_error' );
		delete_option( 'babilim_auto_install_failures' );
		delete_option( 'babilim_auto_install' );
		delete_option( 'babilim_auto_install_skip' );
		delete_option( 'babilim_auto_installed' );
		delete_transient( 'babilim_challenge_rl' );

		// -- Babilim_State (last-synced state + client-event ring buffer) --
		delete_option( 'babilim_state' );
		delete_option( 'babilim_client_events' );
		// The remembered plugin main file -> wp.org slug map: derived data, always removed.
		delete_option( 'babilim_plugin_slugs' );

		// -- Babilim_Upload: an unfinished source upload. Its staging
		// directory is removed first (only a directory the packager
		// itself created is ever removed), then the option.
		$babilim_upload_pending = get_option( 'babilim_upload_pending', null );
		if ( is_array( $babilim_upload_pending ) && is_string( $babilim_upload_pending['dir'] ?? null ) ) {
			try {
				require_once __DIR__ . '/includes/class-babilim-packager.php';
				( new Babilim_Packager() )->discard( $babilim_upload_pending['dir'] );
			} catch ( \Throwable $babilim_upload_error ) {
				unset( $babilim_upload_error ); // Best effort: the daily sweep is gone with the plugin, nothing more to do.
			}
		}
		delete_option( 'babilim_upload_pending' );
		delete_option( 'babilim_upload_done' );

		// -- Babilim_Manifest (cross-request write lock) -- short-TTL, but
		// still an option this plugin creates, so it is cleaned up the same
		// as everything else here rather than left to expire on its own.
		// It only ever lives in the main site's options table (see
		// Babilim_Manifest::lock_table()), which this runs against too.
		delete_option( 'babilim_manifest_lock' );

		// -- Babilim_Consent / Babilim_Inventory / Babilim_Admin settings --
		$babilim_keep_custom = (bool) get_option( 'babilim_keep_custom_on_purge', true );
		delete_option( 'babilim_consent' );
		delete_option( 'babilim_manual_locales' );
		delete_option( 'babilim_disabled_locales' );
		delete_option( 'babilim_keep_custom_on_purge' );
		delete_option( 'babilim_locale_names' );
		delete_transient( 'babilim_meta_locales_cache' );
		delete_transient( 'babilim_meta_locales_failed' );
		// A restore code notice lives at most a minute (this clears the current user's).
		delete_transient( 'babilim_restore_code_notice_' . get_current_user_id() );

		// -- Scheduled events ------------------------------------------------
		wp_clear_scheduled_hook( 'babilim_sync' );
		wp_clear_scheduled_hook( 'babilim_sync_once' );
		wp_clear_scheduled_hook( 'babilim_maintenance' );

		return $babilim_keep_custom;
	}
}

// OR'd across every site processed below: any one site asking to keep
// custom/ makes the shared tree keep it -- see the file docblock above
// for why, and why this must not depend on iteration order.
$babilim_uninstall_keep_custom = false;

if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $babilim_uninstall_site_id ) {
		switch_to_blog( (int) $babilim_uninstall_site_id );
		$babilim_uninstall_keep_custom = babilim_uninstall_clean_current_site_state() || $babilim_uninstall_keep_custom;
		restore_current_blog();
	}
} else {
	$babilim_uninstall_keep_custom = babilim_uninstall_clean_current_site_state();
}

// -- Babilim_Manifest (locally-recorded last write failure) -- one
// network-wide option, like the shared `_manifest.json` it is about (see
// Babilim_Manifest::WRITE_FAILURE_OPTION), so deleted once, not per site.
// On a single site, delete_site_option() deletes the ordinary option.
if ( function_exists( 'delete_site_option' ) ) {
	delete_site_option( 'babilim_manifest_write_failure' );
}

// -- Everything below needs this plugin's own class files: the shared
// WP_LANG_DIR/babilim/ language-file tree, purged exactly once, now that
// every site's own setting has already been folded into
// $babilim_uninstall_keep_custom above. Every option/transient/cron
// deletion above has already happened by the time this runs, so nothing
// here is allowed to -- or can -- undo it.

// -- Installed language files themselves ----------------------------------
try {
	require_once __DIR__ . '/includes/class-babilim-paths.php';
	require_once __DIR__ . '/includes/class-babilim-fs.php';
	require_once __DIR__ . '/includes/class-babilim-wp-fs.php';
	require_once __DIR__ . '/includes/class-babilim-manifest.php';
	require_once __DIR__ . '/includes/class-babilim-result.php';
	require_once __DIR__ . '/includes/class-babilim-installer.php';

	$babilim_uninstall_fs       = new Babilim_Wp_Fs();
	$babilim_uninstall_manifest = new Babilim_Manifest( $babilim_uninstall_fs );
	// The transform/parse/unzip callables are only ever used by
	// install() -- neither purge_all() nor remove_managed_tree()
	// touches them (see Babilim_Installer's own docblock).
	$babilim_uninstall_noop = static function () {
		return false;
	};
	$babilim_uninstall_installer = new Babilim_Installer(
		$babilim_uninstall_fs,
		$babilim_uninstall_manifest,
		$babilim_uninstall_noop,
		$babilim_uninstall_noop,
		$babilim_uninstall_noop
	);
	// Records first (a no-op error result if the manifest is unreadable),
	// then whatever else is left under the root, listed or not.
	$babilim_uninstall_installer->purge_all( $babilim_uninstall_keep_custom );
	$babilim_uninstall_installer->remove_managed_tree( $babilim_uninstall_keep_custom );
} catch ( \Throwable $babilim_uninstall_error ) {
	// Best-effort -- see this section's own comment above.
}
