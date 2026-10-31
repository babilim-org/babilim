<?php
/**
 * Central plugin bootstrap, assembled on `plugins_loaded`.
 *
 * Individual subsystems -- inventory sync, the admin screens, the API
 * client -- attach themselves here. This class wires
 * Babilim_Default_Index's rebuild triggers and the daily
 * `babilim_maintenance` cron job.
 *
 * Every trigger this class registers only ever touches local files
 * through Babilim_Default_Index -- none of it makes an outbound
 * request, so none of it needs the "zero outbound requests before
 * consent" gate this plugin enforces elsewhere.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Babilim plugin singleton.
 */
class Babilim {

	/**
	 * The daily maintenance cron hook name: sweep_orphans() then
	 * rebuild_all().
	 *
	 * @var string
	 */
	private const MAINTENANCE_HOOK = 'babilim_maintenance';

	/**
	 * The twice-daily sync cron hook name: Babilim_Site::sync(). Public:
	 * Babilim_Admin::do_download() schedules a one-off run of this same
	 * hook (wp_schedule_single_event(), not a synchronous call) right
	 * after a successful install, so this plugin's own state reflects a
	 * just-installed build without waiting for the next twice-daily
	 * run -- see that method's own docblock.
	 *
	 * @var string
	 */
	public const SYNC_HOOK = 'babilim_sync';

	/**
	 * One-off check scheduled when an enable did not finish within its
	 * request. It only syncs; it installs nothing.
	 *
	 * @var string
	 */
	public const SYNC_ONCE_HOOK = 'babilim_sync_once';

	/**
	 * The REST namespace/route the challenge endpoint is exposed under:
	 * `/wp-json/babilim/v1/challenge`.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'babilim/v1';

	/**
	 * @var string
	 */
	private const REST_CHALLENGE_ROUTE = '/challenge';

	/**
	 * The single instance, once created.
	 *
	 * @var Babilim|null
	 */
	private static $instance = null;

	/**
	 * This request's single, shared Babilim_Fs -- see manifest()'s own
	 * docblock for why this exists at all.
	 *
	 * @var Babilim_Fs|null
	 */
	private $fs;

	/**
	 * This request's single, shared Babilim_Manifest -- see manifest()'s
	 * own docblock.
	 *
	 * @var Babilim_Manifest|null
	 */
	private $manifest;

	/**
	 * @var Babilim_Default_Index|null
	 */
	private $default_index;

	/**
	 * @var Babilim_Site|null
	 */
	private $site;

	/**
	 * @var Babilim_State|null
	 */
	private $state;

	/**
	 * @var Babilim_Admin|null
	 */
	private $admin;

	/**
	 * Per-locale cache for core_has()'s own real-file reads -- see that
	 * method's own docblock. `null` means "checked, core has no
	 * translation for this locale at all" (as distinct from "not checked
	 * yet", i.e. the key being absent).
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private $core_default_entries_cache = array();

	/**
	 * Every plugin basename on_deactivated_plugin() has been told about
	 * this request, treated as inactive regardless of what
	 * is_plugin_active() itself reports -- see that method's own
	 * docblock for why `active_plugins` can't be trusted yet at the
	 * moment any single one of these hooks fires, and why this
	 * accumulates for the rest of the request rather than being cleared
	 * once that one hook returns: a real `deactivate_plugins([P, Q])`
	 * call (the ordinary "select several, Bulk actions -> Deactivate"
	 * admin screen) or network-wide deactivation fires this hook once
	 * per plugin, from inside WordPress core's own per-plugin loop, and
	 * only writes `active_plugins`/`active_sitewide_plugins` once, after
	 * that whole loop finishes -- so at the moment Q's own hook fires, P
	 * (deactivated moments earlier in the very same loop) is STILL
	 * "active" by both is_plugin_active()'s own read and a since-expired
	 * single-slot override that already cleared itself after P's own
	 * hook returned. Keyed by plugin basename => true; a basename here
	 * is removed the moment on_activated_plugin() hears about that same
	 * plugin again (see that method's own docblock) -- otherwise this
	 * plugin correctly stays forced-inactive for the rest of the
	 * request, since core's own active_plugins option will not catch up
	 * to reality until this whole request ends anyway.
	 *
	 * @var array<string,true>
	 */
	private $forced_inactive_plugins = array();

	/**
	 * What other_sites_usage() found, kept for the rest of this request:
	 * a rebuild of every locale asks active_components() once per
	 * locale, and each ask would otherwise walk the whole network
	 * again. Kept per site the request is on (the answer leaves that
	 * site out), dropped whenever an `active_plugins` option is written,
	 * and never kept across requests.
	 *
	 * @var array<int,array{plugins:array<string,true>,stylesheets:string[]}>
	 */
	private $other_sites_usage_memo = array();

	/**
	 * Whether Babilim itself was deactivated earlier in this request, so
	 * nothing may schedule a follow-up event for the rest of it.
	 *
	 * @var bool
	 */
	private $self_deactivated = false;

	/**
	 * The mirror image of $forced_inactive_plugins, for
	 * on_activated_plugin() -- see that property's own docblock.
	 *
	 * @var array<string,true>
	 */
	private $forced_active_plugins = array();

	/**
	 * Gets the singleton instance, creating it on first call.
	 *
	 * @return Babilim
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resets the singleton (and its lazily-built collaborators) between
	 * tests. Never called in production.
	 */
	public static function reset_for_tests(): void {
		self::$instance = null;
	}

	/**
	 * Private -- use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Runs on `plugins_loaded`.
	 */
	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		$this->register_default_index_triggers();
		$this->register_maintenance_cron();
		$this->register_sync_cron();
		add_action( 'rest_api_init', array( $this, 'register_challenge_route' ) );

		// Admin-only code (consent/settings/overview/detail/report screens,
		// the action handlers behind them) is loaded only for an admin
		// request.
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			$this->admin()->init();
		}
	}

	/**
	 * `init` callback: loads this plugin's own bundled translations from
	 * the plugin's `languages/` directory.
	 *
	 * WordPress looks in `WP_LANG_DIR/plugins/` first, so a language pack
	 * installed on the site always takes priority over the bundled file.
	 * There is deliberately no locale check and no `is_admin()` guard:
	 * the standard loader already does nothing when no file matches.
	 */
	public function load_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- loads the bundled languages/ fallback; a language pack under WP_LANG_DIR/plugins still takes priority (core looks there first).
		load_plugin_textdomain( 'babilim', false, dirname( plugin_basename( BABILIM_FILE ) ) . '/languages' );
	}

	/**
	 * `rest_api_init` callback: exposes the challenge endpoint the
	 * service calls back to during `POST /v1/sites/{id}/verify`.
	 * `permission_callback` returning true is correct here -- this
	 * endpoint answers only for the one
	 * nonce this site is currently waiting to verify (see
	 * Babilim_Site::challenge_response()), so there is no capability to
	 * check.
	 */
	public function register_challenge_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_CHALLENGE_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->site(), 'challenge_response' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'nonce' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Registers the `babilim_sync` callback and schedules the twice-daily
	 * cron event if it isn't already scheduled. Babilim_Site::sync()
	 * itself makes zero requests before the site owner has consented, so
	 * scheduling this unconditionally is safe.
	 */
	private function register_sync_cron(): void {
		add_action( self::SYNC_HOOK, array( $this, 'run_sync' ) );
		add_action( self::SYNC_ONCE_HOOK, array( $this, 'run_sync_once' ) );

		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' )
			&& ! wp_next_scheduled( self::SYNC_HOOK )
		) {
			wp_schedule_event( time(), 'twicedaily', self::SYNC_HOOK );
		}
	}

	/**
	 * `babilim_sync` cron callback: verifies every already-installed
	 * item's file integrity first (degrading, and recording a client
	 * event for, any that fails), then syncs.
	 */
	public function run_sync(): void {
		$this->verify_installed_items();
		$this->site()->sync( Babilim_Site::CRON_BUDGET_SECONDS );
		$this->install_translations();
	}

	/**
	 * `babilim_sync_once` callback: a single sync with the background
	 * budget, nothing else -- no integrity pass and no downloads.
	 */
	public function run_sync_once(): void {
		$this->site()->sync( Babilim_Site::CRON_BUDGET_SECONDS );
	}

	/**
	 * Installs the builds the sync above just listed as updates for
	 * already-installed locales, then the available ones for managed
	 * languages with whatever is left of the per-run budget. Runs only
	 * from the sync cron (never on a page load); the manual sync in
	 * Settings reaches the same installer through Babilim_Admin. Never
	 * throws.
	 */
	private function install_translations(): void {
		try {
			$installer = $this->sync_installer();
			$outcomes  = $installer->install_pending_updates();
			$this->record_install_failures( $outcomes );
			$left = Babilim_Sync_Installer::MAX_PER_RUN - count( $outcomes );
			$last = end( $outcomes );
			if ( is_array( $last ) && 'babilim_rate_limited' === (string) $last['result']->code() ) {
				// The service just said slow down; do not ask again this run.
				return;
			}
			$this->record_install_failures( $installer->install_available( $this->site()->managed_locales(), $left ) );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
	}

	/**
	 * @param array[] $outcomes Sync installer outcomes.
	 */
	private function record_install_failures( array $outcomes ): void {
		foreach ( $outcomes as $outcome ) {
			if ( $outcome['result']->is_error() ) {
				$this->state()->record_client_event( 'install_failed', $outcome['kind'], $outcome['slug'], $outcome['locale'] );
			}
		}
	}

	private function sync_installer(): Babilim_Sync_Installer {
		return new Babilim_Sync_Installer( $this->state(), $this->site(), $this->build_installer(), new Babilim_Wp_Http(), $this->fs(), BABILIM_API_BASE );
	}

	private function build_installer(): Babilim_Installer {
		return new Babilim_Installer(
			$this->fs(),
			$this->manifest(),
			array( $this, 'transform_mo_to_l10n_php' ),
			array( $this, 'parse_translation_file_entries' ),
			array( $this, 'unzip_package' )
		);
	}

	/**
	 * Runs Babilim_Installer::verify() against every non-orphaned manifest
	 * record, ahead of the sync cron's own inventory report -- an
	 * orphaned record is sweep_orphans()'s own concern (see its
	 * mark-then-delete lifecycle) and is left alone here. A record whose
	 * verify() fails is immediately degraded (removes its served files,
	 * falling back to whatever a wp.org language pack/bundled translation/
	 * `custom/` override would otherwise serve) and reported as an
	 * `integrity_failed` client event, the same code sweep_orphans()
	 * itself uses for a record with files missing outright.
	 *
	 * Never throws -- a missing/corrupt manifest is simply skipped rather
	 * than blocking the sync that follows.
	 */
	private function verify_installed_items(): void {
		try {
			$manifest  = $this->manifest();
			if ( ! $manifest->is_available() ) {
				return;
			}
			$installer = $this->build_installer();

			foreach ( $manifest->all() as $record ) {
				if ( ! empty( $record['orphaned_at'] ) ) {
					continue;
				}
				$kind   = (string) ( $record['kind'] ?? '' );
				$slug   = (string) ( $record['slug'] ?? '' );
				$locale = (string) ( $record['locale'] ?? '' );
				if ( '' === $kind || '' === $slug || '' === $locale ) {
					continue;
				}

				$result = $installer->verify( $kind, $slug, $locale );
				if ( $result->is_error() && 'integrity_failed' === $result->code() ) {
					$installer->degrade( $kind, $slug, $locale );
					$this->state()->record_client_event( 'integrity_failed', $kind, $slug, $locale );
				}
			}
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
	}

	/**
	 * Hooks every WordPress core event that can change which language
	 * packs, plugins, or themes are installed -- each one is a point
	 * where the merged `default` domain index needs rebuilding: a plugin
	 * activated/deactivated, the theme switched, an update
	 * completing (plugin/theme/translation), or a plugin/theme deleted.
	 * Each callback rebuilds every locale rather than trying to work out
	 * which locale(s) the specific event affects -- rebuild_all() is
	 * cheap when nothing actually changed for a given locale (see
	 * Babilim_Default_Index::rebuild()), and correctness here matters
	 * more than shaving one rebuild.
	 */
	private function register_default_index_triggers(): void {
		// Not the generic rebuild_default_index_all_locales() handler --
		// see on_activated_plugin()/on_deactivated_plugin()'s own
		// docblocks for why these two need the hook's own $plugin
		// argument. accepted_args=2 matches WordPress core's own real
		// `do_action( 'activated_plugin'|'deactivated_plugin', $plugin,
		// $network_wide )` call.
		add_action( 'activated_plugin', array( $this, 'on_activated_plugin' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivated_plugin' ), 10, 2 );
		// $forced_inactive_plugins/$forced_active_plugins are only a
		// correction for the window between a hook firing and core's own
		// active_plugins/active_sitewide_plugins option actually being
		// written back (see on_deactivated_plugin()'s own docblock) --
		// the moment that option write really happens, the correction is
		// no longer needed (is_plugin_active() is accurate again) AND
		// must not keep applying: a plugin deactivated via the hook, then
		// reactivated *silently* later in the same request (activate_
		// plugin()'s own $silent=true -- no activated_plugin hook fires
		// at all) would otherwise stay stuck forced-inactive for the rest
		// of the request, since nothing would ever call
		// on_activated_plugin() to remove it. Both of these fire on every
		// write to that option, hook-driven or silent alike (WordPress
		// core's own update_option()/update_site_option() always fire
		// update_option_{$name}/update_site_option_{$name}, independent
		// of whichever higher-level action a caller chose to suppress) --
		// see on_active_plugins_option_updated()'s own docblock.
		add_action( 'update_option_active_plugins', array( $this, 'on_active_plugins_option_updated' ) );
		add_action( 'update_site_option_active_sitewide_plugins', array( $this, 'on_active_plugins_option_updated' ) );
		add_action( 'switch_theme', array( $this, 'rebuild_default_index_all_locales' ) );
		add_action( 'switch_theme', array( $this, 'schedule_sync_once' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_process_complete' ), 10, 2 );
		add_action( 'delete_plugin', array( $this, 'rebuild_default_index_all_locales' ) );
		add_action( 'deleted_theme', array( $this, 'rebuild_default_index_all_locales' ) );
		// Fired by Babilim_Installer itself after a successful
		// install()/rollback()/remove()/degrade(). Only the affected
		// locale needs rebuilding here, so this uses rebuild() rather
		// than the all-locales sweep the other triggers above use.
		add_action( 'babilim_layer_changed', array( $this, 'rebuild_default_index_one_locale' ) );
	}

	/**
	 * `switch_theme`/`delete_plugin`/`deleted_theme` callback: rebuilds
	 * the merged `default` domain index for every locale this site has
	 * any record for. Accepts and ignores whatever arguments the firing
	 * hook passes -- each of these hooks fires with a different argument
	 * shape, and none of them is needed here. Not used for
	 * `activated_plugin`/`deactivated_plugin` -- see
	 * on_activated_plugin()/on_deactivated_plugin()'s own docblocks.
	 *
	 * @param mixed ...$args Ignored.
	 */
	public function rebuild_default_index_all_locales( ...$args ): void {
		try {
			$this->default_index()->rebuild_all();
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
	}

	/**
	 * `activated_plugin` callback. WordPress core's own activate_plugin()
	 * updates the `active_plugins` option BEFORE firing this action, so
	 * $plugin already reads as active through the ordinary
	 * is_plugin_active() check active_components() uses for a single
	 * plugin activated on its own -- but network-wide activation runs
	 * this same hook, once per plugin, from inside its own per-plugin
	 * loop, writing `active_sitewide_plugins` only once after that whole
	 * loop finishes (the exact timing gap on_deactivated_plugin()'s own
	 * docblock describes, mirrored here for activation) -- so this
	 * always rebuilds using the hook's own $plugin argument, forcing it
	 * active regardless of what is_plugin_active() currently reads.
	 * Recorded into $forced_active_plugins for the rest of this request,
	 * not cleared once this one call returns -- see that property's own
	 * docblock for why a real multi-plugin activation needs every one of
	 * them to stay forced-active through every later plugin's own hook
	 * in the same batch, not just its own.
	 *
	 * @param mixed $plugin       Plugin basename ("dir/file.php") -- WordPress core's own real
	 *                            call always passes a string; the type is left loose to match
	 *                            the real `do_action()` signature exactly (no core guarantee),
	 *                            same as every other hook callback in this class, and is_string()
	 *                            below actually checks it rather than assuming it.
	 * @param mixed $network_wide Unused; accepted so this can be hooked with accepted_args=2.
	 */
	public function on_activated_plugin( $plugin, $network_wide = false ): void {
		if ( is_string( $plugin ) && '' !== $plugin ) {
			unset( $this->forced_inactive_plugins[ $plugin ] );
			$this->forced_active_plugins[ $plugin ] = true;
		}
		try {
			$this->default_index()->rebuild_all();
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
		$this->schedule_sync_once();
	}

	/**
	 * `deactivated_plugin` callback. WordPress core's own
	 * deactivate_plugins() fires this action from INSIDE its own
	 * per-plugin loop, and only calls update_option('active_plugins',
	 * ...) once, AFTER that whole loop finishes -- confirmed directly
	 * against real WordPress core (6.5/6.8/7.1 alike): is_plugin_active()
	 * still reports the plugin currently being deactivated as active for
	 * the entire duration of this callback. active_components() (via
	 * is_plugin_active()) would therefore still count $plugin as active,
	 * and a rebuild triggered here would keep serving its default-domain
	 * contribution as if nothing had changed. Rebuilds using the hook's
	 * own $plugin argument, forcing it inactive.
	 *
	 * Recorded into $forced_inactive_plugins for the rest of this
	 * request, deliberately NOT cleared once this one call returns:
	 * clearing it in a `finally` block would break a real
	 * `deactivate_plugins( [P, Q] )` call -- the ordinary "select
	 * several, Bulk actions -> Deactivate" admin screen -- fires this
	 * hook once per plugin, still from inside that same per-plugin loop.
	 * Clearing the override the moment P's own hook returned meant that,
	 * by the time Q's hook fired moments later (still inside the same
	 * loop, `active_plugins` still not written back), P had gone back to
	 * being read through plain is_plugin_active() -- which still, wrongly,
	 * reported it active, so Q's own rebuild kept serving P's
	 * default-domain contribution too, even though P had already been
	 * deactivated. Confirmed directly: a real two-plugin
	 * `deactivate_plugins()` call left the first plugin's own strings in
	 * the merged index after the batch finished. Leaving the override in
	 * place for the rest of the request is correct, not just convenient:
	 * a plugin this hook has already fired for really is deactivated for
	 * the remainder of this request, and core's own `active_plugins`
	 * option will not catch up to that reality until the request ends
	 * anyway -- see on_activated_plugin()'s own docblock for the
	 * matching case where the same plugin is reactivated again later in
	 * the same request.
	 *
	 * @param mixed $plugin       Plugin basename ("dir/file.php") -- see on_activated_plugin()'s
	 *                            own docblock for why this is `mixed`, not `string`.
	 * @param mixed $network_wide Unused; accepted so this can be hooked with accepted_args=2.
	 */
	public function on_deactivated_plugin( $plugin, $network_wide = false ): void {
		if ( is_string( $plugin ) && '' !== $plugin ) {
			unset( $this->forced_active_plugins[ $plugin ] );
			$this->forced_inactive_plugins[ $plugin ] = true;
		}
		try {
			$this->default_index()->rebuild_all();
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
		// Deactivating Babilim itself must leave nothing scheduled:
		// core fires this hook right after its own deactivation hook,
		// and again for every later plugin in the same batch -- so
		// remember it for the rest of the request.
		if ( defined( 'BABILIM_FILE' ) && is_string( $plugin ) && plugin_basename( BABILIM_FILE ) === $plugin ) {
			$this->self_deactivated = true;
			return;
		}
		$this->schedule_sync_once();
	}

	/**
	 * `update_option_active_plugins`/`update_site_option_active_sitewide_plugins`
	 * callback: clears both override sets. See
	 * register_default_index_triggers()'s own comment on why -- by the
	 * time either of these fires, core's own option is the authoritative
	 * truth again, so is_plugin_active() needs no more correcting, and
	 * an entry that outlived its own reason for existing (a plugin
	 * reactivated *silently*, with no activated_plugin hook to remove it
	 * from $forced_inactive_plugins) must not keep excluding/including a
	 * plugin the option itself no longer agrees with.
	 *
	 * Accepts and ignores whatever arguments the firing hook passes
	 * (`update_option_{$name}` and `update_site_option_{$name}` each
	 * have their own different shape) -- neither is needed here, only
	 * the fact that the option was just written.
	 *
	 * @param mixed ...$args Ignored.
	 */
	public function on_active_plugins_option_updated( ...$args ): void {
		$this->forced_inactive_plugins = array();
		$this->forced_active_plugins   = array();
		// The option of any site may have changed: what was read from the other sites is out of date.
		$this->forget_other_sites_usage();
	}

	/**
	 * `babilim_layer_changed` callback (see register_default_index_triggers()):
	 * rebuilds the merged `default` domain index for exactly the locale
	 * Babilim_Installer's own successful install()/rollback()/remove()/
	 * degrade() just fired the action for.
	 *
	 * @param string $locale Locale code.
	 */
	public function rebuild_default_index_one_locale( string $locale ): void {
		try {
			$this->default_index()->rebuild( $locale );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
	}

	/**
	 * `upgrader_process_complete` callback: only rebuilds for a
	 * plugin/theme/translation update -- WP_Upgrader also fires this
	 * for e.g. a core update, which this class has nothing to do with.
	 *
	 * A `type === 'translation'` completion can mean a core language
	 * pack was just installed/updated -- core_has()'s own per-locale
	 * cache (core_default_entries_cache, see core_default_domain_entries()'s
	 * own docblock) is cleared first so a rebuild triggered by this same
	 * event reads the just-installed core file, not whatever it cached
	 * from an earlier core_has() call in this same request, before that
	 * file existed or held different entries. Without this, a rebuild
	 * would self-correct anyway on the very next trigger (the cache is
	 * request-scoped, not persisted), just not on this one.
	 *
	 * @param mixed $upgrader   The WP_Upgrader instance (unused).
	 * @param array $hook_extra Extra data; `type` is one of
	 *                          'plugin'|'theme'|'translation'|'core'.
	 */
	public function on_upgrader_process_complete( $upgrader, array $hook_extra ): void {
		$type = (string) ( $hook_extra['type'] ?? '' );
		if ( in_array( $type, array( 'plugin', 'theme', 'translation' ), true ) ) {
			if ( 'translation' === $type ) {
				$this->core_default_entries_cache = array();
			}
			$this->rebuild_default_index_all_locales();
			if ( 'translation' !== $type ) {
				$this->schedule_sync_once();
			}
		}
	}

	/**
	 * Schedules one background check about 30 seconds from now, so a
	 * plugin or theme that was just activated, deactivated, updated or
	 * switched is reported soon. Does nothing without consent (read
	 * from the database) and never queues a second one while one is
	 * pending. The event only syncs; see run_sync_once().
	 */
	public function schedule_sync_once( ...$args ): void {
		if ( $this->self_deactivated ) {
			return;
		}
		try {
			if ( ! ( new Babilim_Consent() )->granted_db() ) {
				return;
			}
			if ( false !== wp_next_scheduled( self::SYNC_ONCE_HOOK ) ) {
				return;
			}
			wp_schedule_single_event( time() + 30, self::SYNC_ONCE_HOOK );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
	}

	/**
	 * Registers the `babilim_maintenance` callback and schedules the
	 * daily cron event if it isn't already scheduled. Never makes an
	 * outbound request itself -- see the class docblock.
	 */
	private function register_maintenance_cron(): void {
		add_action( self::MAINTENANCE_HOOK, array( $this, 'run_maintenance' ) );

		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' )
			&& ! wp_next_scheduled( self::MAINTENANCE_HOOK )
		) {
			wp_schedule_event( time(), 'daily', self::MAINTENANCE_HOOK );
		}
	}

	/**
	 * `babilim_maintenance` cron callback: reconciles `_manifest.json`
	 * against disk (sweep_orphans() runs first, then rebuild_all()
	 * rebuilds every locale's merged `default` index), then removes any
	 * `Babilim_Paths::incoming_dir()` a killed install left behind (see
	 * Babilim_Sync_Installer::sweep_stale_incoming_dirs()) and any source
	 * packaging directory older than a day (Babilim_Packager::sweep()).
	 */
	public function run_maintenance(): void {
		try {
			$this->record_sweep_events( $this->default_index()->sweep_orphans() );
			$this->default_index()->rebuild_all();
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
		try {
			$this->sync_installer()->sweep_stale_incoming_dirs();
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
		try {
			( new Babilim_Packager() )->sweep();
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
	}

	/**
	 * Feeds one sweep_orphans() outcome into the client-event ring
	 * buffer: every {kind,slug,locale} identity sweep_orphans() reports as
	 * `integrity_failed` (missing served files) or `orphaned` (removed --
	 * no longer an active component) already carries exactly the fields a
	 * client_event needs, so each becomes one event. A mass_orphan_brake
	 * trip reports no per-record data to log this way (see that method's
	 * own docblock) -- it's recorded separately in the manifest's own
	 * sweep_guard(), not the client-event buffer.
	 *
	 * @param Babilim_Result $sweep sweep_orphans()'s own result.
	 */
	private function record_sweep_events( Babilim_Result $sweep ): void {
		foreach ( array(
			'integrity_failed' => (array) $sweep->get( 'integrity_failed', array() ),
			'orphaned'         => (array) $sweep->get( 'orphaned', array() ),
		) as $code => $identities ) {
			foreach ( $identities as $identity ) {
				$kind   = (string) ( $identity['kind'] ?? '' );
				$slug   = (string) ( $identity['slug'] ?? '' );
				$locale = (string) ( $identity['locale'] ?? '' );
				// The inventory request's client_events[] schema requires
				// kind to be 'plugin'/'theme' and slug/locale to be
				// non-empty -- one malformed identity here would otherwise
				// 422 the whole sync's next request (see
				// Babilim_Site::sync()), so a record missing a field is
				// dropped rather than reported with a blank one.
				if ( '' === $slug || '' === $locale || ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
					continue;
				}
				$this->state()->record_client_event( $code, $kind, $slug, $locale );
			}
		}
	}

	/**
	 * Every currently active plugin, plus the current theme (and its
	 * parent, for a child theme) -- the real
	 * Babilim_Default_Index `$active_components` callable. On a
	 * multisite network the merged index is shared by every site, so
	 * this also includes each other site's active plugins and active
	 * theme (with its parent); the forced active/inactive overrides
	 * only apply to the current site's half of a plugin's status.
	 *
	 * `get_plugins()`/`is_plugin_active()` live in
	 * `wp-admin/includes/plugin.php`, which a cron or front-end request
	 * never loads on its own -- loaded here on demand so this never
	 * silently sees "no plugins" and wrongly excludes every active
	 * plugin's default-domain contribution. If the list still can't be
	 * obtained (the require itself fails, or get_plugins() throws), this
	 * returns null instead of an empty array -- Babilim_Default_Index's
	 * own rebuild() must be able to tell "genuinely nothing active" apart
	 * from "couldn't find out" and leave the existing index alone for the
	 * latter (see that class's rebuild()).
	 *
	 * @return array<int,array{kind:string,slug:string,version:string,alias?:bool}>|null
	 */
	public function active_components(): ?array {
		$out = array();

		try {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
				return null;
			}
			// 'update_plugins' is a SITE transient in real WordPress
			// (wp-admin/includes/update.php's wp_update_plugins() only
			// ever calls get_site_transient()/set_site_transient() for
			// it) -- get_transient() always returns false for it, which
			// would silently fall this back to a directory-name-only
			// identity for every plugin, every time.
			$update = function_exists( 'get_site_transient' ) ? get_site_transient( 'update_plugins' ) : false;
			// On a network the merged index is shared by every site, so
			// a plugin active on any other site counts as active too.
			// The current site is judged below (overrides first).
			$other = $this->other_sites_usage();
			foreach ( (array) get_plugins() as $file => $data ) {
				$file = (string) $file;
				// on_deactivated_plugin()/on_activated_plugin() override
				// is_plugin_active() for every plugin either hook has
				// heard about so far this request -- see those methods'
				// own docblocks for why active_plugins can't be trusted
				// yet at the moment either hook fires, and why this
				// accumulates for the rest of the request rather than
				// resetting after each individual hook. The overrides
				// only correct this site's half: another site that still
				// runs the plugin keeps it active in the shared index.
				$here      = isset( $this->forced_inactive_plugins[ $file ] ) ? false
					: ( isset( $this->forced_active_plugins[ $file ] ) ? true : is_plugin_active( $file ) );
				$is_active = $here || isset( $other['plugins'][ $file ] );
				if ( ! $is_active ) {
					continue;
				}
				$version     = (string) ( $data['Version'] ?? '' );
				$dir_name    = Babilim_Inventory::plugin_dir_name_from_file( $file );
				$slug        = Babilim_Inventory::plugin_slug_from_file( $file, $update );
				$text_domain = Babilim_Inventory::plugin_text_domain_from_data( $data, $dir_name );
				// One entry per identity, same version -- so a manifest
				// record installed under any of these (the directory
				// name, the wp.org slug, or the text-domain header) is
				// recognized as active regardless of which one it
				// happens to be keyed under (see
				// Babilim_Default_Index::installed_identities()'s own
				// note on why a single value isn't enough). The text
				// domain is flagged as an alias when it differs from
				// both direct identities: another plugin (an add-on
				// reusing its parent's text domain, say) can declare
				// the same one, so rebuild() only falls back to alias
				// entries for a key no component claims directly.
				foreach ( array_unique( array( $dir_name, $slug ) ) as $identity ) {
					$out[] = array(
						'kind'    => 'plugin',
						'slug'    => $identity,
						'version' => $version,
					);
				}
				if ( $text_domain !== $dir_name && $text_domain !== $slug ) {
					$out[] = array(
						'kind'    => 'plugin',
						'slug'    => $text_domain,
						'version' => $version,
						'alias'   => true,
					);
				}
			}
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return null;
		}

		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			$out[] = array(
				'kind'    => 'theme',
				'slug'    => (string) $theme->get_stylesheet(),
				'version' => (string) $theme->get( 'Version' ),
			);
			$parent = $theme->parent();
			if ( false !== $parent ) {
				$out[] = array(
					'kind'    => 'theme',
					'slug'    => (string) $parent->get_stylesheet(),
					'version' => (string) $parent->get( 'Version' ),
				);
			}
			// Every other site's theme (and its parent) on a network.
			try {
				foreach ( $other['stylesheets'] as $stylesheet ) {
					$site_theme = wp_get_theme( $stylesheet );
					if ( ! $site_theme->exists() ) {
						// The site points at a theme that is no longer installed.
						continue;
					}
					$chain      = array( $site_theme, $site_theme->parent() );
					foreach ( $chain as $t ) {
						if ( false === $t ) {
							continue;
						}
						$slug = (string) $t->get_stylesheet();
						$dupe = false;
						foreach ( $out as $seen ) {
							if ( 'theme' === $seen['kind'] && $slug === $seen['slug'] ) {
								$dupe = true;
								break;
							}
						}
						if ( ! $dupe ) {
							$out[] = array(
								'kind'    => 'theme',
								'slug'    => $slug,
								'version' => (string) $t->get( 'Version' ),
							);
						}
					}
				}
			} catch ( \Throwable $e ) {
				$this->log_error( $e );
				return null;
			}
		}

		return $out;
	}

	/**
	 * What the other sites of a network use: the plugin files in their
	 * `active_plugins` and their active themes' stylesheets. The current
	 * site is skipped -- active_components() reads it through
	 * is_plugin_active() (with the forced overrides) and wp_get_theme(),
	 * and during a deactivation its `active_plugins` option is still
	 * stale. Each other site is entered once for both options. Empty on
	 * a single site, without calling any multisite API. The result is
	 * memoised for the rest of the request (see $other_sites_usage_memo).
	 *
	 * @return array{plugins:array<string,true>,stylesheets:string[]}
	 */
	private function other_sites_usage(): array {
		$current = is_multisite() ? get_current_blog_id() : 0;
		if ( isset( $this->other_sites_usage_memo[ $current ] ) ) {
			return $this->other_sites_usage_memo[ $current ];
		}
		$plugins     = array();
		$stylesheets = array();
		if ( ! is_multisite() ) {
			return array(
				'plugins'     => $plugins,
				'stylesheets' => $stylesheets,
			);
		}
		foreach ( (array) get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
			$site_id = (int) $site_id;
			if ( $site_id === $current ) {
				continue;
			}
			switch_to_blog( $site_id );
			try {
				$active     = get_option( 'active_plugins', array() );
				$stylesheet = get_option( 'stylesheet', '' );
			} finally {
				restore_current_blog();
			}
			foreach ( (array) $active as $file ) {
				if ( is_string( $file ) && '' !== $file ) {
					$plugins[ $file ] = true;
				}
			}
			if ( is_string( $stylesheet ) && '' !== $stylesheet ) {
				$stylesheets[ $stylesheet ] = $stylesheet;
			}
		}
		$this->other_sites_usage_memo[ $current ] = array(
			'plugins'     => $plugins,
			'stylesheets' => array_values( $stylesheets ),
		);
		return $this->other_sites_usage_memo[ $current ];
	}

	/**
	 * Drops the per-request memo of what the other sites use, so the
	 * next active_components() walks the network again.
	 */
	public function forget_other_sites_usage(): void {
		$this->other_sites_usage_memo = array();
	}

	/**
	 * Reads one `.mo`/`.l10n.php` file's entries -- the real
	 * Babilim_Default_Index `$parse_entries` callable. Safe (returns
	 * empty) when WP_Translation_File isn't available (an older
	 * WordPress core, or -- as in this plugin's own test suite --
	 * simply not loaded).
	 *
	 * @param string $path Absolute file path.
	 * @return array<string,mixed>
	 */
	public function parse_entries( string $path ): array {
		try {
			if ( ! class_exists( 'WP_Translation_File' ) ) {
				return array();
			}
			$file = \WP_Translation_File::create( $path );
			if ( false === $file ) {
				return array();
			}
			return (array) $file->entries();
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return array();
		}
	}

	/**
	 * Whether WordPress core's own `default` domain translation already
	 * has this string -- the real Babilim_Default_Index `$core_has`
	 * callable.
	 *
	 * Reads core's own real language-pack files directly off disk
	 * (WP_LANG_DIR/{locale}.*, admin-{locale}.*, admin-network-{locale}.*,
	 * continents-cities-{locale}.*, each core's own real domain-less
	 * "default"-family files -- see the four files a real wp.org core
	 * language pack installs) via WP_Translation_File::create(), rather
	 * than asking WP_Translation_Controller::translate(). The controller
	 * is NOT a safe proxy for "core's own official translation": its
	 * `default` domain also holds whatever THIS PLUGIN's own
	 * load_default_supplements() already loaded for the current request
	 * (Babilim_Loader::load_default_supplements() loads the merged
	 * `_merged-{locale}` index into that exact same domain+locale slot) --
	 * so a rebuild() running in a request that had already loaded its own
	 * merged index for that locale would see its own, previously-written
	 * entries reflected back as "core already has this", exclude them as
	 * core_dupes, and save a merged index with those entries newly
	 * missing. Because that in turn strips the very entries the next
	 * read expects, one real request that both loads the merged index
	 * for locale X (e.g. a page view) and triggers a rebuild for that
	 * same locale (e.g. activating an unrelated plugin) permanently empties
	 * locale X's own merged index. Confirmed directly, reproducibly, by a
	 * real WordPress Playground run, not by this repo's own unit tests --
	 * none of which built both a real WP_Translation_Controller AND
	 * loaded a real merged index into it before asserting core_has().
	 *
	 * Per-locale cached on this instance ($core_default_entries_cache) --
	 * this runs once per candidate key during a rebuild, potentially
	 * hundreds of times per locale.
	 *
	 * @param string $msgid   Source string.
	 * @param string $msgctxt Context, or ''.
	 * @param string $locale  The locale being rebuilt.
	 * @return bool
	 */
	public function core_has( string $msgid, string $msgctxt, string $locale = '' ): bool {
		try {
			$use_locale = '' !== $locale ? $locale : determine_locale();
			$entries    = $this->core_default_domain_entries( $use_locale );
			if ( null === $entries ) {
				return false;
			}
			$key = '' !== $msgctxt ? $msgctxt . "\x04" . $msgid : $msgid;
			return array_key_exists( $key, $entries );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return false;
		}
	}

	/**
	 * Every entry core's own real language-pack files declare for
	 * $locale, merged across all four of core's own "default"-family
	 * files -- see core_has()'s own docblock for which ones and why.
	 * `null` when none of the four files exist at all (core has no
	 * language pack installed for $locale, or $locale isn't safe/
	 * WP_Translation_File isn't available) -- distinct from an empty
	 * array, which core_has() would otherwise still treat as "checked,
	 * found nothing" identically, but which a caller inspecting the cache
	 * directly (as this method's own test double does) needs to tell
	 * apart from "never checked".
	 *
	 * @param string $locale Locale code.
	 * @return array<string,mixed>|null
	 */
	private function core_default_domain_entries( string $locale ): ?array {
		if ( array_key_exists( $locale, $this->core_default_entries_cache ) ) {
			return $this->core_default_entries_cache[ $locale ];
		}

		$entries = null;
		if ( Babilim_Paths::is_safe_locale( $locale ) && class_exists( 'WP_Translation_File' ) && defined( 'WP_LANG_DIR' ) ) {
			$found = false;
			$merged = array();
			foreach ( array( '', 'admin-', 'admin-network-', 'continents-cities-' ) as $prefix ) {
				$base = trailingslashit( WP_LANG_DIR ) . $prefix . $locale;
				foreach ( array( 'l10n.php', 'mo' ) as $ext ) {
					$path = "{$base}.{$ext}";
					if ( ! is_readable( $path ) ) {
						continue;
					}
					$found = true;
					$file  = \WP_Translation_File::create( $path );
					if ( false !== $file ) {
						foreach ( (array) $file->entries() as $key => $value ) {
							if ( '' !== $key ) { // The "" header entry is never a real msgid.
								$merged[ $key ] = $value;
							}
						}
					}
					break; // Never both formats for the same file -- one file, either representation.
				}
			}
			if ( $found ) {
				$entries = $merged;
			}
		}

		$this->core_default_entries_cache[ $locale ] = $entries;
		return $entries;
	}

	/**
	 * WordPress core's own already-loaded `Plural-Forms` header for the
	 * `default` domain -- the real Babilim_Default_Index
	 * `$core_plural_forms` callable (a core fallback, used only when
	 * the included default-domain fragments' own headers are missing or
	 * disagree; see that class's resolve_plural_forms()). Safe (returns
	 * null) when WP_Translation_Controller isn't available.
	 *
	 * WP_Translation_Controller::get_headers() takes only a text domain,
	 * not a locale -- it returns whatever headers are loaded for that
	 * domain right now, for WP_Translation_Controller's own *current*
	 * locale, with no way from this call alone to ask for a different
	 * one. So this only ever trusts that answer when $locale (the locale
	 * actually being rebuilt) is the site's own current request locale
	 * (determine_locale()); for any other locale -- e.g. every locale
	 * `rebuild_all()` processes other than the request's own -- this
	 * returns null rather than risk handing back some other locale's
	 * plural rule under this one's name. resolve_plural_forms() only
	 * reaches this as a last resort after the fragments' own headers were
	 * already tried, and a wrong answer here would be actively wrong (a
	 * mis-pluralized string), not just a missed optimization -- so "we
	 * can't be sure" always means null, matching the same "skip every
	 * plural entry rather than guess" rule resolve_plural_forms() itself
	 * follows when nothing else answers either.
	 *
	 * @param string $locale The locale being rebuilt.
	 * @return string|null
	 */
	public function core_plural_forms( string $locale ): ?string {
		try {
			// Checked before even looking for WP_Translation_Controller:
			// there's no point asking it anything unless $locale is the
			// one locale it could possibly answer for correctly.
			if ( ! function_exists( 'determine_locale' ) || determine_locale() !== $locale ) {
				return null;
			}
			if ( ! class_exists( 'WP_Translation_Controller' ) ) {
				return null;
			}
			$controller = \WP_Translation_Controller::get_instance();
			$headers    = $controller->get_headers( 'default' );
			$value      = $headers['Plural-Forms'] ?? null;
			return is_string( $value ) && '' !== $value ? $value : null;
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return null;
		}
	}

	/**
	 * Lazily builds this request's Babilim_Default_Index, wired to the
	 * real, production Babilim_Wp_Fs/Babilim_Manifest and this class's
	 * own active_components()/parse_entries()/core_has()/core_plural_forms().
	 *
	 * @return Babilim_Default_Index
	 */
	private function default_index(): Babilim_Default_Index {
		if ( null === $this->default_index ) {
			$this->default_index = new Babilim_Default_Index(
				$this->fs(),
				$this->manifest(),
				array( $this, 'active_components' ),
				array( $this, 'parse_entries' ),
				array( $this, 'core_has' ),
				array( $this, 'core_plural_forms' )
			);
		}
		return $this->default_index;
	}

	/**
	 * This request's single, shared Babilim_Fs -- see manifest()'s own
	 * docblock for why sharing this (not just the Manifest) matters too:
	 * every Babilim_Manifest a request ever builds must read and write
	 * through the exact same filesystem access, or a write staged through
	 * one Babilim_Fs instance could be invisible to a manifest check
	 * reading through a different one before the underlying WP_Filesystem
	 * itself settles.
	 *
	 * @return Babilim_Fs
	 */
	private function fs(): Babilim_Fs {
		if ( null === $this->fs ) {
			$this->fs = new Babilim_Wp_Fs();
		}
		return $this->fs;
	}

	/**
	 * This request's single, shared Babilim_Manifest.
	 *
	 * Every one of this class's own lazily-built collaborators that reads
	 * or writes `_manifest.json` (default_index(), build_installer(),
	 * site(), and -- via fs()/manifest() handed through its own
	 * constructor -- admin()) must share exactly this one instance, not
	 * build its own.
	 *
	 * Babilim_Manifest::load() reads `_manifest.json` into
	 * `$this->records` exactly once, at construction -- an instance never
	 * re-reads from disk on its own. save() guards against a stale write
	 * with an optimistic-concurrency check (re-reads the file and merges
	 * just that call's delta onto the latest content), but that only
	 * protects each individual write: two instances built earlier and
	 * later in the SAME request would still each hold their own
	 * point-in-time snapshot and could disagree about what the manifest
	 * currently holds. Two installs in one request with a default-index
	 * rebuild in between are the shape that exposed this; see
	 * Test_Babilim::test_two_installs_in_one_request_keep_both_manifest_records().
	 *
	 * @return Babilim_Manifest
	 */
	private function manifest(): Babilim_Manifest {
		if ( null === $this->manifest ) {
			$this->manifest = new Babilim_Manifest( $this->fs() );
		}
		return $this->manifest;
	}

	/**
	 * Lazily builds this request's Babilim_State.
	 *
	 * @return Babilim_State
	 */
	private function state(): Babilim_State {
		if ( null === $this->state ) {
			$this->state = new Babilim_State();
		}
		return $this->state;
	}

	/**
	 * The real Babilim_Installer `$transform` callable: PHP source for a
	 * `.l10n.php`, from an already-staged `.mo`. Safe (returns '') when
	 * WP_Translation_File isn't available, matching parse_entries()'s own
	 * guard below.
	 *
	 * @param string $mo_path Absolute path to an already-staged `.mo`.
	 * @return string
	 */
	public function transform_mo_to_l10n_php( string $mo_path ): string {
		try {
			if ( ! class_exists( 'WP_Translation_File' ) ) {
				return '';
			}
			return (string) \WP_Translation_File::transform( $mo_path, 'php' );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return '';
		}
	}

	/**
	 * The real Babilim_Installer `$parse` callable: an already-staged
	 * `.mo`/`.l10n.php`'s entry count. $format is unused -- like
	 * parse_entries() below, WP_Translation_File::create() dispatches on
	 * $path's own extension.
	 *
	 * @param string $path   Absolute path.
	 * @param string $format 'mo'|'php' (unused -- see above).
	 * @return int|false
	 */
	public function parse_translation_file_entries( string $path, string $format ) {
		try {
			if ( ! class_exists( 'WP_Translation_File' ) ) {
				return false;
			}
			$file = \WP_Translation_File::create( $path );
			if ( false === $file ) {
				return false;
			}
			return count( (array) $file->entries() );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return false;
		}
	}

	/**
	 * A signed package link's own response body is already capped in
	 * transit (Babilim_Api_Client::MAX_PACKAGE_RESPONSE_BYTES, the
	 * COMPRESSED size) -- this caps the DECOMPRESSED total instead, the
	 * one dimension that limit does nothing for: a zip bomb (a tiny
	 * compressed file that decompresses to gigabytes) would sail through
	 * the compressed-size check and then exhaust memory the moment
	 * unzip_package() below actually reads it out. 50 MiB is generous
	 * for what a real package ever contains (a handful of .po/.mo/.json
	 * files per locale -- see Babilim_Api_Client's own fetch_package()
	 * docblock) while still bounding worst-case memory use to something
	 * a normal PHP request can survive.
	 *
	 * @var int
	 */
	private const MAX_UNZIPPED_TOTAL_BYTES = 50 * 1024 * 1024;

	/**
	 * A second, independent cap alongside MAX_UNZIPPED_TOTAL_BYTES: a
	 * zip bomb built from many small, highly-repetitive entries (rather
	 * than one huge one) could stay under the byte total while still
	 * costing one getFromIndex() decompression call and one array entry
	 * each -- checked against ZipArchive::$numFiles before this method
	 * ever reads a single entry, not accumulated during the loop like
	 * the byte total is (there's nothing to accumulate towards: the
	 * count is already known the moment the zip is open). 500 is
	 * generous for the same reason 50 MiB is: a real package's own file
	 * count is in the single digits.
	 *
	 * @var int
	 */
	private const MAX_UNZIPPED_FILE_COUNT = 500;

	/**
	 * The real Babilim_Installer `$unzip` callable: every entry of a
	 * local zip file, in memory -- never writes anything to disk itself
	 * (see Babilim_Installer's own constructor docblock for why that
	 * matters). Returns `false` (mapped to installer's own
	 * `unzip_failed` result, the same as a zip ZipArchive itself
	 * couldn't open) if the zip's own file count or total decompressed
	 * size exceeds this method's caps -- see MAX_UNZIPPED_FILE_COUNT/
	 * MAX_UNZIPPED_TOTAL_BYTES for why either matters and why their
	 * specific values are generous.
	 *
	 * @param string $zip_path Absolute local path to a downloaded package zip.
	 * @return array<string,string>|false
	 */
	public function unzip_package( string $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return false;
		}
		if ( $zip->numFiles > self::MAX_UNZIPPED_FILE_COUNT ) {
			$zip->close();
			return false;
		}
		$entries    = array();
		$total_size = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( ! is_string( $name ) || '/' === substr( $name, -1 ) ) {
				continue; // A directory entry, not a file.
			}
			// Checked against the zip's own DECLARED size (statIndex(),
			// metadata only) before ever calling getFromIndex() below --
			// the call that actually decompresses this entry into memory
			// -- so a single oversized entry is caught before it's ever
			// decompressed at all, not after.
			$stat = $zip->statIndex( $i );
			$size = is_array( $stat ) ? (int) $stat['size'] : 0;
			$total_size += $size;
			if ( $total_size > self::MAX_UNZIPPED_TOTAL_BYTES ) {
				$zip->close();
				return false;
			}
			$contents = $zip->getFromIndex( $i );
			if ( is_string( $contents ) ) {
				$entries[ $name ] = $contents;
			}
		}
		$zip->close();
		return $entries;
	}

	/**
	 * Lazily builds this request's Babilim_Site, wired to the real,
	 * production Babilim_Wp_Http/Babilim_Inventory/Babilim_Manifest.
	 *
	 * @return Babilim_Site
	 */
	private function site(): Babilim_Site {
		if ( null === $this->site ) {
			$inventory = new Babilim_Inventory(
				array( $this, 'get_plugins_for_inventory' ),
				'wp_get_themes',
				static function ( $name ) {
					// update_plugins/update_themes are SITE transients in
					// real WordPress -- see Babilim_Inventory's own
					// constructor docblock and Babilim::active_components()'s
					// matching note.
					return function_exists( 'get_site_transient' ) ? get_site_transient( $name ) : false;
				},
				array( $this, 'get_users_locales' )
			);
			$this->site = new Babilim_Site( new Babilim_Wp_Http(), BABILIM_API_BASE, $this->state(), $inventory, $this->manifest() );
		}
		return $this->site;
	}

	/**
	 * Lazily builds this request's Babilim_Admin -- only ever reached for
	 * an admin request (see init()).
	 *
	 * @return Babilim_Admin
	 */
	private function admin(): Babilim_Admin {
		if ( null === $this->admin ) {
			$this->admin = new Babilim_Admin( $this->fs(), null, null, $this->manifest() );
		}
		return $this->admin;
	}

	/**
	 * The real Babilim_Inventory `$get_plugins` callable -- lazily loads
	 * `wp-admin/includes/plugin.php` the same way active_components()
	 * does, for the same reason (see that method's docblock).
	 *
	 * @return array
	 */
	public function get_plugins_for_inventory(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
	}

	/**
	 * The real Babilim_Inventory `$get_users_locales` callable: every
	 * distinct non-empty `locale` user-meta value site-wide, read
	 * directly via $wpdb (matching how WordPress core itself queries
	 * user meta in bulk, rather than loading every WP_User object just to
	 * read one meta field each). No core API returns this in one query
	 * -- WP_User_Query has no "distinct meta value" mode, so the only
	 * core-API alternative would be paging through every user on the
	 * site just to collect a handful of distinct locale strings, which
	 * is strictly worse (more queries, more memory, and still ends at
	 * the same `$wpdb` layer underneath WP_User_Query itself).
	 *
	 * Not object-cached: `locale` user-meta changes only when a person
	 * changes their own profile language, an infrequent, low-stakes
	 * write with no real staleness cost either way; this callable's own
	 * callers (Babilim_Inventory::detected_locales(), reached from at
	 * most one or two admin-screen renders per request) already query
	 * this only a handful of times per request at most, so the
	 * invalidation this would need -- hooking every possible write to a
	 * `locale` meta key across every user on the site -- would add more
	 * surface than the query it replaces ever costs.
	 *
	 * @return string[]
	 */
	public function get_users_locales(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see this method's own docblock: no core API returns a distinct-value list across all users' meta in one query, and the write this value depends on is rare enough that object-cache invalidation would cost more than it saves.
		return (array) $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'locale' AND meta_value != ''" );
	}

	/**
	 * Logs a caught Throwable without ever letting logging itself fatal
	 * a request -- same WP_DEBUG-gated pattern as Babilim_Loader's own
	 * log_error().
	 *
	 * @param \Throwable $e The caught exception/error.
	 */
	private function log_error( \Throwable $e ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( function_exists( 'error_log' ) ) {
			error_log( '[babilim] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- only reached when WP_DEBUG is on.
		}
	}
}
