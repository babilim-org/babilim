<?php
/**
 * The admin screens (overview, detail, settings, report) and the
 * admin-post.php action handlers behind every button on them. Loaded
 * only when is_admin() (see Babilim::init()).
 *
 * Every handler here follows the same shape: check_admin_referer() +
 * current_user_can('manage_options') first (guard()), sanitize every
 * input, call the real underlying class, record the outcome via
 * add_settings_error() (persisted across the redirect through the
 * `settings_errors` transient -- the same mechanism WordPress core's own
 * options.php uses), then wp_safe_redirect() back to this screen. No
 * handler here ever calls exit() itself -- admin-post.php's own request
 * lifecycle ends the request right after firing the action, and skipping
 * it here is what keeps every handler directly callable from a test.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Babilim's admin screens and action handlers.
 */
final class Babilim_Admin {

	/**
	 * @var string
	 */
	public const MENU_SLUG = 'babilim';

	/**
	 * Admin menu icon: the Babilim mark (a "B" with a doorway and a star)
	 * as a single-colour SVG data URI, so WordPress recolours it to match
	 * the admin colour scheme.
	 */
	private const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjExMyAxMjUgOTUwIDk1MCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik05MTEuNiw2MTJjLTguMi00LjgtMTYuNy05LjItMjUuNS0xNC4xYzIwLjUtMTYuMSwzNy45LTM0LjEsNTIuOS01NC4zYzY5LjItOTMuMyw2Ni44LTIyNC4xLTYuMS0zMTAuOEM4NzksMTY4LjYsODA4LjUsMTM2LjIsNzI2LDEzNS4zYy0xODMuNy0yLTM2Ny40LTAuNy01NTEuMS0xYy02LDAtNy4yLDEuOC03LjIsNy40YzAuMiwzOC42LDAuMSw3Ny4zLDAuMSwxMTUuOWMtMC4xLDI2Ni42LDAsNTMzLjEtMC4yLDc5OS43YzAsNi4yLDAuOSw4LjUsOCw4LjVjNzcuNS0wLjMsMTU1LjEtMC4zLDIzMi42LTAuM2MwLjMtMTMuNiwwLjYtMjcuMSwwLjktNDAuN2MtMC4yLTQwLjQtMC40LTgwLjYtMC40LTEyMWMwLDAsMCwwLDAsMGMwLDAsMCwwLDAtMC4xYzAtNDcsMC05NCwwLTE0MC45YzAtMTMuNywwLTI3LjMsMC00MWMwLTkuOCwwLTE5LjcsMC0yOS41YzAtMjMuNSwwLTQ3LTAuMS03MC41YzAsMCwwLDAsMCwwYzAsMCwwLDAsMC0wLjFjMCwwLDAsMCwwLDBjMC0xLjMsMC0yLjUsMC4yLTMuNWMwLTE2LjgtMC4xLTMzLjYtMC4xLTUwLjRjMC05NCwwLjEtMTg4LTAuMS0yODJjMC03LjYsMi4xLTguNSw4LjgtNi43YzEwNy41LDI4LjQsMjE1LjEsNTYuNywzMjIuNyw4NC45YzQsMSw2LjYsMiw2LjYsNy4zYy0wLjMsNzcuNS0wLjIsMTU1LTAuMywyMzIuNWgwLjF2MTAwLjdjMC4yLDAuNiwwLjIsMS4zLDAuMywyYzAsMC4xLDAsMC4yLDAsMC4yYzAsMC4xLDAsMC4zLDAsMC40YzAsMCwwLDAuMSwwLDAuMWMwLDAsMCwwLDAsMGMtMC4zLDc3LjUtMC4yLDE1NS0wLjMsMjMyLjVjMCwwLjktMC4zLDEuNy0wLjUsMi42YzAuMiwzNy4zLDAuNCw3NC40LDAuNiwxMTEuN2MwLDMuOCwwLjIsNy42LDAuMywxMS40YzE3LjcsMCwzNS40LDAuMiw1My4xLDBjNjguMi0wLjksMTI1LjctMjYuNSwxNzEuNC03N0MxMDY4LjcsODgxLjEsMTA1MC41LDY5NC4yLDkxMS42LDYxMnoiLz48cGF0aCBmaWxsPSJibGFjayIgZD0iTTc0NS45LDk0Mi4zYzAuMiwzNy4zLDAuNCw3NC41LDAuNiwxMTEuOGMwLDMuOCwwLjIsNy42LDAuMywxMS40Yy0xMTIuOSwwLTIyNS43LDAtMzM4LjYsMGMwLjMtMTMuNSwwLjYtMjcuMSwwLjktNDAuNmMxLjMtMC42LDIuOC0wLjksMy44LTEuOWMyNy42LTI1LjEsNTUuMi01MC4xLDgyLjYtNzUuNGM0LjQtNC4xLDguNy01LjksMTQuOC01LjljNzEuNSwwLjIsMTQzLDAuMSwyMTQuNSwwLjJDNzMxLjgsOTQxLjksNzM4LjksOTQyLjIsNzQ1LjksOTQyLjN6Ii8+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik01NzcuNSw0NTIuNGMtMTYuMSw3OS41LTYwLjUsMTMwLjItMTQyLjgsMTQ2LjlDNTM3LDY0My44LDU0Ny4zLDY1NC42LDU3OSw3NTIuMmMxNS40LTgyLjYsNjAuNC0xMzMuNSwxNDAuNi0xNTEuNkM2MzYuNyw1ODMuNCw1OTQuNCw1MzAuNCw1NzcuNSw0NTIuNHoiLz48L3N2Zz4=';

	/**
	 * Slug of the Settings submenu page.
	 *
	 * @var string
	 */
	public const SETTINGS_SLUG = 'babilim-settings';

	/**
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The capability an action that writes to the network-wide shared
	 * translation tree (`WP_LANG_DIR/babilim/`, `_manifest.json`, the
	 * merged `default` domain index) requires. On a single-site install
	 * this is the same as CAPABILITY; on multisite it's
	 * `manage_network_options`, since a site-level `manage_options`
	 * admin on one site must not be able to overwrite, roll back, or
	 * remove translations every other site on the network is reading
	 * from -- an action that only ever affects this site's own settings
	 * (consent, manual locales, sync) stays at CAPABILITY everywhere.
	 *
	 * @return string
	 */
	private static function network_capability(): string {
		return is_multisite() ? 'manage_network_options' : self::CAPABILITY;
	}

	/**
	 * Whether the current user may perform an action requiring
	 * CAPABILITY ($network_wide false) or network_capability()
	 * ($network_wide true).
	 *
	 * @param bool $network_wide Whether this is a network-wide-write action.
	 * @return bool
	 */
	private static function can( bool $network_wide = false ): bool {
		return current_user_can( $network_wide ? self::network_capability() : self::CAPABILITY );
	}

	/**
	 * Every admin-post.php action this class handles, mapped to the
	 * method that handles it.
	 *
	 * @var string[]
	 */
	private const ACTIONS = array(
		'babilim_download',
		'babilim_rollback',
		'babilim_remove',
		'babilim_request',
		'babilim_feedback',
		'babilim_save_settings',
		'babilim_sync_now',
		'babilim_rollback_report',
		'babilim_rebuild_index',
		'babilim_sweep_now',
		'babilim_restart_registration',
		'babilim_purge_all',
		'babilim_reverify',
		'babilim_enable',
		'babilim_disable',
		'babilim_sync_restart',
		'babilim_upload_confirm',
		'babilim_upload_step',
		'babilim_upload_cancel',
		'babilim_checkout',
		'babilim_restore',
		'babilim_restore_code',
	);

	/**
	 * The address shown wherever the plugin tells a site owner to
	 * contact support. Plain text only; nothing is ever sent to it.
	 *
	 * @var string
	 */
	private const SUPPORT_EMAIL = 'hi@babilim.org';

	/**
	 * Option holding the "Delete all Babilim data on uninstall" choice
	 * (read by uninstall.php). Off by default: deleting the plugin keeps
	 * the site identity so a reinstall can reuse it.
	 *
	 * @var string
	 */
	public const OPTION_DELETE_DATA = 'babilim_delete_data_on_uninstall';

	/**
	 * Whether `custom/` is kept on `babilim_purge_all`/uninstall -- see
	 * Babilim_Installer::purge_all(). Checked (kept) by default: a site-
	 * specific override under `custom/` is the site owner's own work,
	 * never deleted without an explicit opt-out.
	 *
	 * @var string
	 */
	private const OPTION_KEEP_CUSTOM = 'babilim_keep_custom_on_purge';

	/**
	 * Transient key `render_managed_locales()` caches `GET /v1/meta/
	 * locales`'s own parsed catalog under -- this list barely ever
	 * changes, and the settings screen renders (so would otherwise call
	 * out) on every single request.
	 *
	 * @var string
	 */
	private const META_LOCALES_CACHE_TRANSIENT = 'babilim_meta_locales_cache';

	/**
	 * How long the cached locale catalog is trusted before the next
	 * render tries the service again.
	 *
	 * @var int
	 */
	private const META_LOCALES_CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * `POST /v1/feedback`'s own `category` enum, pinned by the service's
	 * own versioned HTTP contract.
	 *
	 * @var string[]
	 */
	public const FEEDBACK_CATEGORIES = array(
		'mistranslation',
		'terminology',
		'placeholder',
		'context',
		'plural',
		'negation',
		'loading',
		'other',
	);

	/**
	 * @var Babilim_Fs|null
	 */
	private $fs;

	/**
	 * @var Babilim_Http|null
	 */
	private $http;

	/**
	 * @var Babilim_Manifest|null
	 */
	private $manifest;

	/**
	 * @var Babilim_State|null
	 */
	private $state;

	/**
	 * @var Babilim_Inventory|null
	 */
	private $inventory;

	/**
	 * @var Babilim_Site|null
	 */
	private $site;

	/**
	 * @var Babilim_Installer|null
	 */
	private $installer;

	/**
	 * @var Babilim_Default_Index|null
	 */
	private $default_index;

	/**
	 * @var Babilim_Consent|null
	 */
	private $consent;

	/**
	 * What the bulk POST of this request did, for the result block.
	 *
	 * @var array|null
	 */
	private $bulk_result;

	/**
	 * The hook suffix add_menu_page() itself returns from register_menu()
	 * -- the only reliable way to scope enqueue_admin_style() (an
	 * admin_enqueue_scripts callback, which fires on every admin screen,
	 * not just this plugin's own) to this plugin's one page. Empty until
	 * register_menu() runs.
	 *
	 * @var string
	 */
	private $menu_hook_suffix = '';

	/**
	 * The hook suffix of the Settings submenu page; empty until
	 * register_menu() runs.
	 *
	 * @var string
	 */
	private $settings_hook_suffix = '';

	/**
	 * @param Babilim_Fs|null        $fs        Overrides the production Babilim_Wp_Fs -- production
	 *                                          passes Babilim::instance()'s own shared one (see
	 *                                          Babilim::manifest()'s own docblock for why); a test
	 *                                          may still override it independently.
	 * @param Babilim_Http|null      $http      Overrides the production Babilim_Wp_Http -- for tests.
	 * @param Babilim_Installer|null $installer Overrides the production installer (normally bound to
	 *                                          Babilim::instance()'s real transform/parse/unzip
	 *                                          callables, which need a real WordPress
	 *                                          install/zip extension) -- for a test driving a real
	 *                                          download-then-install round trip against fake ones.
	 * @param Babilim_Manifest|null  $manifest  Overrides the production Babilim_Manifest -- production
	 *                                          passes Babilim::instance()'s own shared one, the SAME
	 *                                          instance default_index()/installer()/site() share, so a
	 *                                          write an admin action makes is visible to a hook that
	 *                                          fires later in the same request, and vice versa (see
	 *                                          Babilim::manifest()'s own docblock for the data-loss
	 *                                          bug this replaces). A test may still override it
	 *                                          independently.
	 */
	public function __construct( ?Babilim_Fs $fs = null, ?Babilim_Http $http = null, ?Babilim_Installer $installer = null, ?Babilim_Manifest $manifest = null ) {
		$this->fs        = $fs;
		$this->http       = $http;
		$this->installer = $installer;
		$this->manifest  = $manifest;
	}

	/**
	 * Registers the admin menu and every admin-post.php action handler.
	 * Only ever called when is_admin() -- see Babilim::init().
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_plugins_list_script' ) );
		add_action( 'admin_notices', array( $this, 'render_verification_admin_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_verification_admin_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BABILIM_FILE ), array( $this, 'confirm_deactivate_link' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( BABILIM_FILE ), array( $this, 'confirm_deactivate_link' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'dispatch_' . $action ) );
		}
		add_action( 'wp_ajax_babilim_license_status', array( $this, 'ajax_babilim_license_status' ) );
	}

	/**
	 * `admin_menu` callback.
	 */
	public function register_menu(): void {
		$hook_suffix = add_menu_page(
			__( 'Babilim', 'babilim' ),
			__( 'Babilim', 'babilim' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			self::MENU_ICON
		);
		// Real WordPress core (wp-admin/includes/plugin.php) types
		// add_menu_page()'s return as `string|false` -- it returns false
		// when the current user actually lacks self::CAPABILITY at the
		// moment this runs, a real (if rare) possibility this defensive
		// check exists for. The pinned tools/wordpress-stubs.php types it
		// narrower, as always `string`, which is what makes phpstan
		// itself call this check already-narrowed and therefore always
		// true -- a stub limitation, not something wrong with this line.
		$this->menu_hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : ''; // @phpstan-ignore function.alreadyNarrowedType
		if ( '' !== $this->menu_hook_suffix ) {
			add_action( 'load-' . $this->menu_hook_suffix, array( $this, 'on_translations_load' ) );
		}
		// The first submenu entry reuses the top-level slug, so the
		// parent item is labelled "Translations" instead of repeating
		// the plugin name.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Translations', 'babilim' ),
			__( 'Translations', 'babilim' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
		$settings_suffix             = add_submenu_page(
			self::MENU_SLUG,
			__( 'Babilim Settings', 'babilim' ),
			__( 'Settings', 'babilim' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( $this, 'render' )
		);
		$this->settings_hook_suffix = is_string( $settings_suffix ) ? $settings_suffix : '';
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * `load-{hook}` callback of the translations page: the place a bulk
	 * POST from the list is handled before anything is printed. One run
	 * handles one batch and does not redirect: the result (and the form
	 * that continues with what was not tried) is rendered by this same
	 * response.
	 */
	public function on_translations_load(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only tells a POST from a GET; the nonce is checked below before anything runs.
		if ( 'POST' !== $method ) {
			return;
		}
		$op = self::bulk_action_from_post();
		if ( null === $op ) {
			return;
		}
		check_admin_referer( 'bulk-translations' );
		if ( ! self::can( 'request' !== $op ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'babilim' ), '', array( 'response' => 403 ) );
		}

		$selected = self::post_list( 'components' );
		$pending  = self::post_list( 'pending' );
		// Only the rows the current tab shows: a bulk run never reaches
		// into the other kind.
		$items    = Babilim_Translations_Table::filter_items( $this->list_items( $this->state()->get() ), 'all', '', '', self::kind_tab( self::field_get( 'babilim_kind' ) ) );
		if ( array() !== $pending ) {
			// Only what is still eligible right now may run, whatever the form says.
			$eligible = array();
			foreach ( Babilim_Bulk::expand( $items, $op, self::item_keys( $items ), '' ) as $triple ) {
				$eligible[] = implode( ':', $triple );
			}
			$ops = array();
			foreach ( array_unique( $pending ) as $key ) {
				$parts = explode( ':', $key );
				if ( 3 === count( $parts ) && in_array( $key, $eligible, true ) ) {
					$ops[] = $parts;
				}
			}
		} else {
			$ops = Babilim_Bulk::expand( $items, $op, $selected, self::field_get( 'babilim_locale' ) );
		}

		$this->bulk_result = array( 'op' => $op, 'components' => $selected );
		if ( count( $ops ) > Babilim_Bulk::MAX_SELECTION_OPS ) {
			$this->bulk_result['error'] = sprintf(
				/* translators: 1: the most operations one run accepts, 2: how many were selected. */
				__( 'Select fewer components: at most %1$d operations per run (you selected %2$d).', 'babilim' ),
				Babilim_Bulk::MAX_SELECTION_OPS,
				count( $ops )
			);
			return;
		}

		$execute = function ( string $kind, string $slug, string $locale, int $timeout ) use ( $op ): Babilim_Result {
			if ( 'request' !== $op ) {
				return $this->do_download( $kind, $slug, $locale, $timeout );
			}
			$component = $this->installed_component( $kind, $slug );
			if ( null === $component ) {
				return Babilim_Result::error( 'unknown_component', __( 'That component is not installed on this site, so a translation cannot be requested for it.', 'babilim' ) );
			}
			return $this->do_request( $kind, $slug, (string) ( $component['version'] ?? '' ), $locale, $timeout );
		};
		$this->bulk_result += Babilim_Bulk::run( $ops, $op, new Babilim_Deadline( Babilim_Bulk::BUDGET_SECONDS ), $execute, $this->state() );
	}

	/**
	 * A list of strings from $_POST (components[], pending[]).
	 *
	 * @param string $key Field name.
	 * @return string[]
	 */
	private static function post_list( string $key ): array {
		$raw = $_POST[ $key ] ?? array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by on_translations_load() before this is read.
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $value ) {
			if ( is_string( $value ) ) {
				$out[] = sanitize_text_field( wp_unslash( $value ) );
			}
		}
		return $out;
	}

	/**
	 * A GET query value as a plain string, or ''.
	 *
	 * @param string $key Query key.
	 * @return string
	 */
	private static function field_get( string $key ): string {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
	}

	/**
	 * The list tab named by a request value: plugin, theme, or '' for
	 * every kind (also for anything else).
	 *
	 * @param string $raw Request value.
	 * @return string
	 */
	private static function kind_tab( string $raw ): string {
		return in_array( $raw, array( 'plugin', 'theme' ), true ) ? $raw : '';
	}

	/**
	 * Every row's `kind:slug`.
	 *
	 * @param array[] $items List rows.
	 * @return string[]
	 */
	private static function item_keys( array $items ): array {
		return array_map(
			static function ( array $item ): string {
				return $item['kind'] . ':' . $item['slug'];
			},
			$items
		);
	}

	/**
	 * The list rows for a stored state, with installed read from the manifest.
	 *
	 * @param array $state Stored state.
	 * @return array[]
	 */
	private function list_items( array $state ): array {
		$manifest = $this->manifest();
		return Babilim_Translations_Table::build_items(
			$state,
			$this->inventory()->components(),
			static function ( string $kind, string $slug, string $loc ) use ( $manifest ): ?array {
				try {
					return $manifest->installed( $kind, $slug, $loc );
				} catch ( \RuntimeException $e ) {
					return null;
				}
			},
			self::can( true )
		);
	}

	/**
	 * The bulk action the list form posted: the top select wins, and
	 * the bottom select counts when the top one is unset. Not the base
	 * class's current_action(), which also reads $_REQUEST.
	 *
	 * @return string|null download|update|request, or null.
	 */
	public static function bulk_action_from_post(): ?string {
		$action = $_POST['action'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; the nonce is checked before anything runs.
		if ( '' === $action || '-1' === $action ) {
			$action = $_POST['action2'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return is_string( $action ) && in_array( $action, array( 'download', 'update', 'request' ), true ) ? $action : null;
	}

	/**
	 * `admin_enqueue_scripts` callback -- this plugin's small layout
	 * stylesheet and its one script, and only on this plugin's own two
	 * admin pages: every other admin screen gets this callback fired
	 * too, so it no-ops immediately whenever $hook is neither of the
	 * hook suffixes register_menu() recorded.
	 *
	 * Registered against a `false` src ("no file to enqueue, just a
	 * handle to hang inline CSS off") rather than shipping a real .css
	 * file -- the same wp_register_style()-with-false-src pattern core
	 * itself uses for inline-only styles (see, e.g., WP_Widget_Block's
	 * own registration). admin_css() is the only source of truth for
	 * what layout this page actually needs; see its own docblock for why
	 * these rules exist at all (real wp_kses()'s safecss_filter_attr()
	 * strips `display` from any inline `style` attribute, admin.php no
	 * longer emits one at all -- see render_managed_locales(), render_maintenance_
	 * buttons()).
	 *
	 * @param string $hook The current admin screen's own hook suffix.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( '' === $this->menu_hook_suffix || ( $hook !== $this->menu_hook_suffix && $hook !== $this->settings_hook_suffix ) ) {
			return;
		}
		// The explicit BABILIM_VERSION -- not the parameter's own `false`
		// default -- matters here even though `false` is also the src
		// (there is no file to cache-bust the URL of): WordPress.WP.
		// EnqueuedResourceParameters flags a missing $ver on ANY
		// wp_register_style() call, inline-only or not, since core's own
		// browser-caching concern is about the version string this
		// stylesheet is served under changing when its contents do, not
		// specifically about a file's URL query string.
		wp_register_style( 'babilim-admin', false, array(), BABILIM_VERSION );
		wp_enqueue_style( 'babilim-admin' );
		wp_add_inline_style( 'babilim-admin', self::admin_css() );
		wp_enqueue_script( 'babilim-admin', plugins_url( 'admin/js/babilim-admin.js', BABILIM_FILE ), array(), BABILIM_VERSION, true );
	}

	/**
	 * `admin_enqueue_scripts` callback for the Plugins screen, site admin
	 * and network admin alike (core passes `plugins.php` in both). Hooked
	 * from init(), not from register_menu(): the network admin never fires
	 * `admin_menu`. Carries the Deactivate link's confirmation (see
	 * confirm_deactivate_link()) on a handle with no file of its own.
	 *
	 * @param string $hook The current admin screen's hook suffix.
	 */
	public function enqueue_plugins_list_script( string $hook ): void {
		if ( 'plugins.php' !== $hook || ! self::can( is_multisite() && is_network_admin() ) ) {
			return;
		}
		wp_register_script( 'babilim-plugins-list', false, array(), BABILIM_VERSION, true );
		wp_enqueue_script( 'babilim-plugins-list' );
		wp_add_inline_script( 'babilim-plugins-list', self::plugins_list_js() );
	}

	/**
	 * The plugins-list script: a link carrying data-babilim-confirm is only
	 * followed once the visitor accepts the question (Cancel leaves the
	 * plugin active).
	 *
	 * @return string
	 */
	private static function plugins_list_js(): string {
		return "document.addEventListener('click',function(e){var a=e.target&&e.target.closest?e.target.closest('a[data-babilim-confirm]'):null;if(a&&!window.confirm(a.getAttribute('data-babilim-confirm'))){e.preventDefault();}});";
	}

	/**
	 * This page's own small layout stylesheet -- exactly the three
	 * `display`/`margin-right` rules render_managed_locales() and render_maintenance_
	 * buttons() used to carry as inline `style="..."` attributes, before
	 * real WordPress core's own wp_kses() (safecss_filter_attr(), which
	 * every allowed `style` attribute's value is run through regardless
	 * of what this file's own allowed_admin_html() declares) turned out
	 * to strip `display` on every core version checked -- `display` was
	 * never on safe_style_css's own allow-list. Class names, not element
	 * selectors: this stylesheet is enqueued on the whole admin page,
	 * where a bare `form`/`label` selector would also reach markup this
	 * plugin doesn't own.
	 *
	 * @return string
	 */
	private static function admin_css(): string {
		return '.babilim-inline{display:inline}.babilim-inline-block{display:inline-block}.babilim-mr-1em{margin-right:1em}'
			. '.babilim-cell-list{container-type:inline-size}.babilim-cell-line{display:grid;grid-template-columns:minmax(min-content,max-content) minmax(10em,1fr) max-content;gap:0 1em;align-items:start;padding:2px 0}'
			. '.babilim-cell-action{position:relative;text-align:right}'
			// The busy label plus room for the spinner is laid out invisibly from the start, so pressing the button never changes the column width; the buttons of a cell fill that width and the spinner is drawn inside the button, left of its text, never over the status column.
			. '.babilim-cell-action button[data-babilim-busy-label]{min-width:100%}'
			. '.babilim-cell-action button[data-babilim-busy-label]::after{content:attr(data-babilim-busy-label);display:block;height:0;overflow:hidden;visibility:hidden;padding-left:22px}'
			. '.babilim-cell-action .spinner{position:absolute;left:5px;top:7px;float:none;width:16px;height:16px;background-size:16px 16px;margin:0}'
			. '.babilim-cell-text{display:contents}.babilim-cell-lang,.babilim-cell-status{display:block}'
			. '.babilim-cell-status{overflow-wrap:anywhere}.babilim-cell-lang{white-space:normal}.babilim-cell-tail{display:inline-block;max-width:100%}'
			. '.babilim-choose{margin:1em 0}.babilim-choose li{margin:0 0 .5em}'
			. '.babilim-locale-links{clear:both;margin:.5em 0 0}.babilim-advanced-first{margin-top:1em}.babilim-account{margin:0}'
			// One grid per cell: the lines take the cell's columns, so the name column is as wide as the longest name and every status starts at the same x.
			. '@supports (grid-template-columns:subgrid){.babilim-cell-grid{display:grid;grid-template-columns:minmax(min-content,max-content) minmax(10em,1fr) max-content;column-gap:1em}.babilim-cell-row,.babilim-cell-line,.babilim-cell-warning{grid-column:1/-1}.babilim-cell-row,.babilim-cell-line{display:grid;grid-template-columns:subgrid}}'
			. '@container (max-width:40em){.babilim-cell-grid,.babilim-cell-row{display:block}.babilim-cell-line{display:flex;flex-wrap:wrap;align-items:flex-start;gap:2px 1em}.babilim-cell-text{display:block;flex:1 1 10em}.babilim-cell-action{flex:none;margin-left:auto}}'
			. '@media (max-width:782px){.babilim-cell-grid,.babilim-cell-row{display:block}.babilim-cell-line{display:grid;grid-template-columns:minmax(0,1fr);gap:2px 0}.babilim-cell-text{display:contents}.babilim-cell-action{text-align:left;margin-left:0;width:max-content}}';
	}

	/**
	 * The site's own override folder, as a short path for display: relative
	 * to the WordPress root when it lies under it, otherwise its last three
	 * segments (languages folder, plugin folder, `custom`). Trailing slash.
	 *
	 * @return string
	 */
	private static function custom_dir_label(): string {
		return self::custom_dir_label_for(
			dirname( Babilim_Paths::dir( 'custom/plugins' ) ),
			defined( 'ABSPATH' ) ? (string) ABSPATH : ''
		);
	}

	/**
	 * The pure part of custom_dir_label().
	 *
	 * @param string $dir  The override folder, no trailing slash.
	 * @param string $root The WordPress root with its trailing slash, or ''.
	 * @return string
	 */
	public static function custom_dir_label_for( string $dir, string $root ): string {
		if ( '' !== $root && 0 === strpos( $dir, $root ) ) {
			return ltrim( substr( $dir, strlen( $root ) ), '/' ) . '/';
		}
		return basename( dirname( $dir, 2 ) ) . '/' . basename( dirname( $dir ) ) . '/' . basename( $dir ) . '/';
	}

	/**
	 * Whether `custom/` is currently kept on purge/uninstall -- defaults
	 * true (kept) until the site owner explicitly opts out on the
	 * settings screen.
	 *
	 * @return bool
	 */
	private static function keep_custom_on_purge(): bool {
		return (bool) get_option( self::OPTION_KEEP_CUSTOM, true );
	}

	// -- lazily-built collaborators, sharing one Babilim_Fs/Babilim_Manifest
	// per request/instance -- Babilim::admin() always hands this class the
	// SAME shared instance its own site()/installer()/default_index() use
	// (see Babilim::manifest()'s own docblock); the fallback new
	// Babilim_Manifest($this->fs()) below only ever runs when this class
	// is constructed directly, outside Babilim::admin(), with no manifest
	// passed in. A single admin request can chain several of these
	// together, e.g. download() then install(), and needs writes from one
	// to be visible to the next without re-reading from disk. -------------

	private function fs(): Babilim_Fs {
		if ( null === $this->fs ) {
			$this->fs = new Babilim_Wp_Fs();
		}
		return $this->fs;
	}

	private function http(): Babilim_Http {
		if ( null === $this->http ) {
			$this->http = new Babilim_Wp_Http();
		}
		return $this->http;
	}

	private function manifest(): Babilim_Manifest {
		if ( null === $this->manifest ) {
			$this->manifest = new Babilim_Manifest( $this->fs() );
		}
		return $this->manifest;
	}

	private function state(): Babilim_State {
		if ( null === $this->state ) {
			$this->state = new Babilim_State();
		}
		return $this->state;
	}

	private function inventory(): Babilim_Inventory {
		if ( null === $this->inventory ) {
			$babilim         = Babilim::instance();
			$this->inventory = new Babilim_Inventory(
				array( $babilim, 'get_plugins_for_inventory' ),
				'wp_get_themes',
				static function ( $name ) {
					return function_exists( 'get_site_transient' ) ? get_site_transient( $name ) : false;
				},
				array( $babilim, 'get_users_locales' )
			);
		}
		return $this->inventory;
	}

	private function site(): Babilim_Site {
		if ( null === $this->site ) {
			$this->site = new Babilim_Site( $this->http(), BABILIM_API_BASE, $this->state(), $this->inventory(), $this->manifest() );
		}
		return $this->site;
	}

	private function installer(): Babilim_Installer {
		if ( null === $this->installer ) {
			$babilim         = Babilim::instance();
			$this->installer = new Babilim_Installer(
				$this->fs(),
				$this->manifest(),
				array( $babilim, 'transform_mo_to_l10n_php' ),
				array( $babilim, 'parse_translation_file_entries' ),
				array( $babilim, 'unzip_package' )
			);
		}
		return $this->installer;
	}

	private function sync_installer(): Babilim_Sync_Installer {
		return new Babilim_Sync_Installer( $this->state(), $this->site(), $this->installer(), $this->http(), $this->fs(), BABILIM_API_BASE );
	}

	private function default_index(): Babilim_Default_Index {
		if ( null === $this->default_index ) {
			$babilim             = Babilim::instance();
			$this->default_index = new Babilim_Default_Index(
				$this->fs(),
				$this->manifest(),
				array( $babilim, 'active_components' ),
				array( $babilim, 'parse_entries' ),
				array( $babilim, 'core_has' ),
				array( $babilim, 'core_plural_forms' )
			);
		}
		return $this->default_index;
	}

	private function consent(): Babilim_Consent {
		if ( null === $this->consent ) {
			$this->consent = new Babilim_Consent();
		}
		return $this->consent;
	}

	/**
	 * A fresh Babilim_Api_Client bound to this site's current token.
	 *
	 * @return Babilim_Api_Client
	 */
	private function api(): Babilim_Api_Client {
		return new Babilim_Api_Client( $this->http(), BABILIM_API_BASE, $this->site()->token() );
	}

	// -- guard: nonce + capability, every state-changing action. ---------

	/**
	 * check_admin_referer() + current_user_can() -- wp_die()s (via
	 * check_admin_referer()/this method itself) on failure, exactly like
	 * every other WordPress admin-post.php action. $network_wide raises
	 * the required capability to network_capability() (manage_options
	 * everywhere except multisite, where it's manage_network_options)
	 * for an action that writes to the network-wide shared translation
	 * tree; every other action stays at CAPABILITY.
	 *
	 * @param string $nonce_action The nonce action name.
	 * @param bool   $network_wide Whether this action writes to the network-wide shared translation tree.
	 */
	private function guard( string $nonce_action, bool $network_wide = false ): void {
		check_admin_referer( $nonce_action );
		if ( ! self::can( $network_wide ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'babilim' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Sanitized string from $_POST/$_GET (whichever the request used).
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function field( string $key ): string {
		return isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce itself is checked by guard() before any field is read.
	}

	/**
	 * Like field(), but through sanitize_textarea_field() -- for a
	 * free-form multi-line field (a <textarea>) where a line break is
	 * meaningful content, not something to collapse away.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function textarea_field( string $key ): string {
		// A browser submits a textarea's line breaks as CRLF while its
		// `maxlength` counts one character per break; normalising to LF
		// keeps a text the browser accepted inside the same limit here.
		$value = isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce itself is checked by guard() before any field is read.
		return str_replace( "\r\n", "\n", $value );
	}

	/**
	 * Like field(), but without any of sanitize_text_field()'s own
	 * tag-stripping/whitespace-collapsing -- for a field (msgid,
	 * msgctxt) that must reach the service exactly as the original
	 * string was. Never shortened: the caller checks the length against
	 * the contract's limit and refuses an over-long value instead.
	 * A non-string value (e.g. an array, from `?msgid[]=x`) is rejected
	 * outright rather than coerced -- PHP's own (string) cast on an
	 * array silently produces the literal string "Array" (plus a
	 * warning), which is not a rejection.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function raw_field( string $key ): string {
		$value = isset( $_REQUEST[ $key ] ) ? wp_unslash( $_REQUEST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce itself is checked by guard() before any field is read; deliberately not run through a sanitizer here -- see this method's own docblock for why the value must reach the service exactly as given.
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Like raw_field(), but reads $_GET specifically -- render_report()'s
	 * own pre-fill of the hidden msgid/msgctxt fields is a read of the
	 * incoming query string, never a $_REQUEST write path.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function raw_query_field( string $key ): string {
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-filling a form field, not a write path; deliberately not run through a sanitizer here -- see raw_field()'s own docblock for why (the same reasoning applies).
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Records the outcome and persists it across the redirect via the
	 * `settings_errors` transient -- the same mechanism WordPress core's
	 * own options.php uses so a notice set on one request still shows on
	 * the next. See reconcile_persisted_notices(), called once at the top
	 * of every render.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @param string $type    'success'|'error'|'warning'.
	 */
	private function notice( string $code, string $message, string $type ): void {
		add_settings_error( 'babilim', $code, $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), MINUTE_IN_SECONDS );
	}

	/**
	 * Transient key of the one-time notice for the current user.
	 *
	 * @return string
	 */
	private static function restore_code_notice_key(): string {
		return 'babilim_restore_code_notice_' . get_current_user_id();
	}

	/**
	 * Merges any settings errors persisted by a previous request's
	 * notice() into this request's own store, then clears the transient
	 * (settings_errors()' own real-core behavior).
	 */
	private static function reconcile_persisted_notices(): void {
		// A notice meant for this user alone (see dispatch_babilim_restore_code()).
		$private = get_transient( self::restore_code_notice_key() );
		if ( is_array( $private ) ) {
			add_settings_error( 'babilim', (string) ( $private['code'] ?? '' ), (string) ( $private['message'] ?? '' ), (string) ( $private['type'] ?? 'updated' ) );
			delete_transient( self::restore_code_notice_key() );
		}
		$persisted = get_transient( 'settings_errors' );
		if ( is_array( $persisted ) ) {
			foreach ( $persisted as $error ) {
				add_settings_error(
					(string) ( $error['setting'] ?? 'babilim' ),
					(string) ( $error['code'] ?? '' ),
					(string) ( $error['message'] ?? '' ),
					(string) ( $error['type'] ?? 'error' )
				);
			}
		}
		delete_transient( 'settings_errors' );
	}

	/**
	 * This plugin's own admin page URL, optionally for a specific screen.
	 *
	 * @param string $screen 'translations'|'report'|'confirm'|'choose'|'upload-confirm'|'upload-progress'|'settings'. The older values 'overview', 'detail' and '' mean 'translations'.
	 * @param array  $extra  Extra query args.
	 * @return string
	 */
	private static function page_url( string $screen = 'translations', array $extra = array() ): string {
		if ( 'settings' === $screen ) {
			$args = array( 'page' => self::SETTINGS_SLUG );
		} else {
			$args = array( 'page' => self::MENU_SLUG );
			if ( in_array( $screen, array( 'report', 'confirm', 'choose', 'upload-confirm', 'upload-progress' ), true ) ) {
				$args['screen'] = $screen;
			}
		}
		$args = array_merge( $args, $extra );
		$query = array();
		foreach ( $args as $key => $value ) {
			$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return admin_url( 'admin.php?' . implode( '&', $query ) );
	}

	// -- babilim_download --------------------------------------------------

	public function dispatch_babilim_download(): void {
		$this->guard( 'babilim_download', true );
		$kind   = sanitize_key( self::field( 'kind' ) );
		$slug   = self::field( 'slug' );
		$locale = self::field( 'locale' );
		$result = $this->do_download( $kind, $slug, $locale );
		// do_download() runs the whole download-then-install synchronously
		// -- by the time this redirect happens, the build is already
		// installed (or the result above is already an error), never
		// merely "started".
		$this->finish_component_action(
			$result,
			$kind,
			$slug,
			$locale,
			(bool) $result->get( 'already_installed' ) ? __( 'Already installed.', 'babilim' ) : __( 'Downloaded and installed.', 'babilim' )
		);
	}

	/**
	 * Downloads and installs the currently-active build for one
	 * (kind, slug, locale) -- babilim_available/update_available's own
	 * action. Makes zero requests without consent. When no update is
	 * listed and the status build is the target, a manifest record for
	 * a different build with a greater build_no is refused without any
	 * request: a stale form must not downgrade, so the result is a
	 * success with `already_installed` set. Every other case goes to
	 * the installer, which decides under its lock (a build already
	 * installed by another request is likewise `already_installed`).
	 *
	 * @param string   $kind    'plugin'|'theme'.
	 * @param string   $slug    Component slug.
	 * @param string   $locale  Locale code.
	 * @param int|null $timeout Seconds for each request, or null for the transport default.
	 * @return Babilim_Result
	 */
	public function do_download( string $kind, string $slug, string $locale, ?int $timeout = null ): Babilim_Result {
		if ( ! $this->consent()->granted() ) {
			return Babilim_Result::error( 'no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
		}
		$status = $this->state()->status( $kind, $slug, $locale );
		// updates[]'s own build_id is the authoritative "download this
		// exact build" signal whenever this locale has one -- the locale
		// entry's own build_id (in
		// components[].locales{}) isn't guaranteed to be the same value,
		// and using it here would risk re-downloading the build already
		// installed instead of the update.
		$update   = $this->state()->update_for( $kind, $slug, $locale );
		// Once an update was installed, updates[] no longer lists it but the
		// cell remembers its build: a local rollback before the next sync
		// brings the Update button back, and it must install that build
		// rather than the one the cell was last synced with.
		$build_id = null !== $update ? (string) ( $update['build_id'] ?? '' ) : (string) ( $status['update_build_id'] ?? '' );
		if ( '' === $build_id && null === $update ) {
			$build_id = (string) ( $status['build_id'] ?? '' );
		}
		if ( '' === $build_id ) {
			return Babilim_Result::error( 'no_build', __( 'No build is available to download for this locale.', 'babilim' ) );
		}

		// Fallback only: a stale form (opened before an update, or
		// resubmitted via Back) names the build the last sync recorded.
		// If the manifest already holds a different, newer build, that
		// download would be a downgrade -- count it as already installed
		// without any request.
		if ( null === $update && $this->installed_build_is_newer( $kind, $slug, $locale, $status ) ) {
			return Babilim_Result::ok( array( 'already_installed' => true ) );
		}

		$result = $this->sync_installer()->install_build( $kind, $slug, $locale, $build_id, $timeout );
		if ( $result->is_ok() ) {
			// A hand-made download puts this cell back under automatic installs.
			Babilim_Sync_Installer::clear_skip( $kind, $slug, $locale );
		}
		return $result;
	}

	/**
	 * Whether the manifest's installed build for a cell is a different,
	 * strictly newer build than the one the stored status names.
	 *
	 * @param string     $kind   'plugin'|'theme'.
	 * @param string     $slug   Component slug.
	 * @param string     $locale Locale code.
	 * @param array|null $status The stored status entry for the cell.
	 * @return bool
	 */
	private function installed_build_is_newer( string $kind, string $slug, string $locale, ?array $status ): bool {
		if ( null === $status || ! $this->manifest()->is_available() ) {
			return false;
		}
		$installed = $this->manifest()->installed( $kind, $slug, $locale );
		// A degraded record serves nothing, so installing is never a downgrade.
		if ( null === $installed || ! empty( $installed['degraded_at'] ) || (string) ( $installed['build_id'] ?? '' ) === (string) ( $status['build_id'] ?? '' ) ) {
			return false;
		}
		$have = $installed['build_no'] ?? null;
		$want = $status['build_no'] ?? null;
		return is_int( $have ) && is_int( $want ) && $have > $want;
	}

	// -- babilim_rollback ---------------------------------------------------

	public function dispatch_babilim_rollback(): void {
		$this->guard( 'babilim_rollback', true );
		$kind   = sanitize_key( self::field( 'kind' ) );
		$slug   = self::field( 'slug' );
		$locale = self::field( 'locale' );
		if ( $this->ask_to_confirm( 'rollback', $kind, $slug, $locale ) ) {
			return;
		}
		$result = $this->installer()->rollback( $kind, $slug, $locale );
		$this->finish_component_action( $result, $kind, $slug, $locale, __( 'Rolled back to the previous build.', 'babilim' ) );
	}

	/**
	 * Sends a destructive action that was not confirmed to the confirm
	 * page instead of running it.
	 *
	 * @param string $op     rollback|remove.
	 * @param string $kind   Component kind.
	 * @param string $slug   Component slug.
	 * @param string $locale Language code.
	 * @return bool True when the request was redirected and nothing may run.
	 */
	private function ask_to_confirm( string $op, string $kind, string $slug, string $locale ): bool {
		if ( '1' === self::field( 'confirmed' ) ) {
			return false;
		}
		$extra = array( 'op' => $op, 'kind' => $kind, 'slug' => $slug, 'locale' => $locale );
		foreach ( array( 's', 'babilim_locale' ) as $key ) {
			if ( '' !== self::field( $key ) ) {
				$extra[ $key ] = self::field( $key );
			}
		}
		$tab = self::kind_tab( self::field( 'babilim_kind' ) );
		if ( '' !== $tab ) {
			$extra['babilim_kind'] = $tab;
		}
		wp_safe_redirect( self::page_url( 'confirm', $extra ) );
		return true;
	}

	// -- babilim_rollback_report -- one-click rollback and file feedback --

	public function dispatch_babilim_rollback_report(): void {
		$this->guard( 'babilim_rollback_report', true );
		$kind   = sanitize_key( self::field( 'kind' ) );
		$slug   = self::field( 'slug' );
		$locale = self::field( 'locale' );

		// Validate everything the report needs (consent, category,
		// build_id) BEFORE ever touching the installed files -- a report
		// that was never going to be sendable must not roll back
		// anything first.
		$validated = $this->validate_feedback_request( $kind, $slug, $locale );
		if ( $validated->is_error() ) {
			$this->notice( (string) $validated->code(), $validated->message(), 'error' );
			wp_safe_redirect( self::page_url( 'translations' ) );
			return;
		}

		$result = $this->installer()->rollback( $kind, $slug, $locale );
		if ( ! $result->is_ok() ) {
			// Nothing to report yet -- the rollback itself never happened.
			$this->finish_component_action( $result, $kind, $slug, $locale, '' );
			return;
		}

		$feedback_result = $this->send_feedback( (array) $validated->get( 'payload' ) );
		if ( $feedback_result->is_ok() ) {
			$this->notice( 'ok', __( 'Rolled back and reported.', 'babilim' ), 'success' );
		} else {
			$this->notice(
				(string) $feedback_result->code(),
				sprintf(
					/* translators: %s: why sending the report failed. */
					__( 'Rolled back, but the report could not be sent: %s', 'babilim' ),
					$feedback_result->message()
				),
				'error'
			);
		}
		wp_safe_redirect( self::page_url( 'translations' ) );
	}

	// -- babilim_remove ------------------------------------------------------

	public function dispatch_babilim_remove(): void {
		$this->guard( 'babilim_remove', true );
		$kind   = sanitize_key( self::field( 'kind' ) );
		$slug   = self::field( 'slug' );
		$locale = self::field( 'locale' );
		if ( $this->ask_to_confirm( 'remove', $kind, $slug, $locale ) ) {
			return;
		}
		$result = $this->installer()->remove( $kind, $slug, $locale );
		if ( $result->is_ok() ) {
			// What the owner removed is not put back by the sync cron.
			Babilim_Sync_Installer::remember_skip( $kind, $slug, $locale );
		}
		$this->finish_component_action( $result, $kind, $slug, $locale, __( 'Removed.', 'babilim' ) );
	}

	// -- babilim_request -- ask the service for a translation ----------------

	public function dispatch_babilim_request(): void {
		$this->guard( 'babilim_request' );
		$kind      = sanitize_key( self::field( 'kind' ) );
		$slug      = self::field( 'slug' );
		$locale    = self::field( 'locale' );
		$component = $this->installed_component( $kind, $slug );
		$version   = null === $component ? '' : (string) ( $component['version'] ?? '' );
		if ( null === $component ) {
			$result = Babilim_Result::error( 'unknown_component', __( 'That component is not installed on this site, so a translation cannot be requested for it.', 'babilim' ) );
		} else {
			// do_request() answers an empty or over-long version itself.
			$entry     = $this->state()->status( $kind, $slug, $locale );
			$installed = 'babilim_installed' === (string) ( $entry['status'] ?? '' );
			$result    = $this->do_request( $kind, $slug, $version, $locale );
			if ( $result->is_ok() ) {
				$this->state()->record_request_answer( $kind, $slug, $locale, $installed, $result );
				$upload = $result->get( 'upload' );
				if ( is_array( $upload ) ) {
					if ( ! self::can( true ) ) {
						// The whole plugin/theme directory is shared by every
						// site on a network: only a network administrator may
						// decide to upload it.
						$this->notice( 'network_capability', self::network_wide_action_text(), 'error' );
						wp_safe_redirect( self::page_url( 'translations' ) );
						return;
					}
					$this->offer_upload( $component, $locale, $upload );
					return;
				}
			}
		}
		$this->finish_component_action( $result, $kind, $slug, $locale, __( 'Translation requested.', 'babilim' ) );
	}

	/**
	 * The service asked for the component's source: remember the offer and
	 * show the confirmation screen. Nothing is packaged or sent until the
	 * owner confirms. An offer already in progress is resumed; a different
	 * one replaces it (one upload at a time).
	 *
	 * @param array $component The installed component's inventory entry.
	 * @param array $upload    The `upload` object of the service's answer.
	 */
	private function offer_upload( array $component, string $locale, array $upload ): void {
		$pending = Babilim_Upload::pending();
		if ( null !== $pending && (string) $pending['upload_id'] === (string) ( $upload['upload_id'] ?? '' ) ) {
			wp_safe_redirect( self::page_url( isset( $pending['zip'] ) ? 'upload-progress' : 'upload-confirm' ) );
			return;
		}
		if ( Babilim_Upload::last_completed() === (string) ( $upload['upload_id'] ?? '' ) ) {
			// Sent in full earlier and now being verified by the service
			// (a sync can bring the "continue" button back): nothing to send.
			$this->state()->set_cell_status( (string) $component['kind'], (string) $component['slug'], $locale, array( 'upload_pending' => false ) );
			$this->notice( 'ok', __( 'This component was already uploaded. Babilim is checking it now.', 'babilim' ), 'info' );
			wp_safe_redirect( self::page_url( 'translations' ) );
			return;
		}
		if ( null !== $pending ) {
			$this->upload()->cancel();
		}
		$begun = $this->upload()->begin( $component, $locale, $upload );
		if ( $begun->is_error() ) {
			$this->notice( (string) $begun->code(), $begun->message(), 'error' );
			wp_safe_redirect( self::page_url( 'translations' ) );
			return;
		}
		wp_safe_redirect( self::page_url( 'upload-confirm' ) );
	}

	private function upload(): Babilim_Upload {
		return new Babilim_Upload( $this->api(), new Babilim_Packager(), $this->state() );
	}

	// -- babilim_upload_* -- confirm, send, give up --------------------------

	public function dispatch_babilim_upload_confirm(): void {
		$this->guard( 'babilim_upload_confirm', true );
		$upload = $this->upload();
		$result = $upload->confirm();
		if ( $result->is_ok() ) {
			$result = $upload->step();
		}
		$this->finish_upload_step( $result );
	}

	public function dispatch_babilim_upload_step(): void {
		$this->guard( 'babilim_upload_step', true );
		$this->finish_upload_step( $this->upload()->step() );
	}

	public function dispatch_babilim_upload_cancel(): void {
		$this->guard( 'babilim_upload_cancel', true );
		$this->upload()->cancel();
		$this->notice( 'ok', __( 'Upload cancelled.', 'babilim' ), 'success' );
		wp_safe_redirect( self::page_url( 'translations' ) );
	}

	private function finish_upload_step( Babilim_Result $result ): void {
		if ( $result->is_error() ) {
			$this->notice( (string) $result->code(), $result->message(), 'error' );
			wp_safe_redirect( self::page_url( 'translations' ) );
			return;
		}
		if ( $result->get( 'done' ) ) {
			$this->notice( 'ok', __( 'Upload complete — translation requested.', 'babilim' ), 'success' );
			wp_safe_redirect( self::page_url( 'translations' ) );
			return;
		}
		wp_safe_redirect( self::page_url( 'upload-progress' ) );
	}

	/**
	 * The installed component identified by $kind and $slug, taken from
	 * this site's own inventory (the same slug rules the inventory sync
	 * uses) -- never from the submitted form, which a browser can alter
	 * and which carries no version anyway.
	 *
	 * @return array|null The inventory entry, or null when no such
	 *                    component is installed. Its `version` may be ''
	 *                    (no Version header) or over the contract's limit;
	 *                    the caller decides what each means.
	 */
	private function installed_component( string $kind, string $slug ): ?array {
		foreach ( $this->inventory()->components() as $component ) {
			if ( (string) ( $component['kind'] ?? '' ) === $kind && (string) ( $component['slug'] ?? '' ) === $slug ) {
				return $component;
			}
		}
		return null;
	}

	/**
	 * Whether a locale row with this status gets a "Request translation"
	 * button. The one rule behind both the button and do_request()'s own
	 * check.
	 *
	 * @param string $status The locale entry's status from the last sync.
	 * @return bool
	 */
	private static function status_allows_request( string $status ): bool {
		return in_array( $status, array( 'requestable', 'failed', 'wporg' ), true );
	}

	/**
	 * Whether this site lists $locale for the component with a request
	 * button: the locale has a row on the component and its last-synced
	 * status allows a request. Reads only the stored state -- no service
	 * call.
	 *
	 * @return bool
	 */
	private function may_request( string $kind, string $slug, string $locale ): bool {
		$entry = $this->state()->status( $kind, $slug, $locale );
		if ( null === $entry ) {
			return false;
		}
		$status = (string) ( $entry['status'] ?? '' );
		if ( 'queued' === $status && ! empty( $entry['upload_pending'] ) ) {
			return true; // An upload the service asked for is waiting: asking again resumes it.
		}
		if ( 'babilim_installed' === $status || 'babilim_available' === $status ) {
			// An installed translation can ask for the newer version.
			$newer = is_array( $entry['newer_version'] ?? null ) ? (string) ( $entry['newer_version']['status'] ?? '' ) : '';
			if ( 'babilim_installed' === $status && 'queued' === $newer && ! empty( $entry['upload_pending'] ) ) {
				return true; // The newer version's upload is waiting: asking again resumes it.
			}
			return 'babilim_installed' === $status && in_array( $newer, array( 'requestable', 'failed' ), true );
		}
		return self::status_allows_request( $status );
	}

	public function do_request( string $kind, string $slug, string $version, string $locale, ?int $timeout = null ): Babilim_Result {
		if ( ! $this->consent()->granted() ) {
			return Babilim_Result::error( 'no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
		}
		// Everything below is checked before anything is sent: the
		// service refuses a request outside its limits, and a version is
		// never shortened to fit (a cut-down number names another release).
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || '' === $slug || ! Babilim_Contract_Limits::fits( $slug, Babilim_Contract_Limits::SLUG ) ) {
			return Babilim_Result::error( 'unknown_component', __( 'That component is not installed on this site, so a translation cannot be requested for it.', 'babilim' ) );
		}
		if ( '' === $version ) {
			return Babilim_Result::error( 'no_version', __( 'This component does not declare a version number, so a translation cannot be requested for it.', 'babilim' ) );
		}
		if ( ! Babilim_Contract_Limits::fits( $version, Babilim_Contract_Limits::VERSION ) ) {
			return Babilim_Result::error(
				'version_too_long',
				sprintf(
					/* translators: %d: the most characters a version number may have. */
					__( 'This component\'s version number is longer than %d characters, which Babilim cannot handle, so a translation cannot be requested for it.', 'babilim' ),
					Babilim_Contract_Limits::VERSION
				)
			);
		}
		if ( ! Babilim_Contract_Limits::fits( $locale, Babilim_Contract_Limits::LOCALE ) || ! $this->may_request( $kind, $slug, $locale ) ) {
			return Babilim_Result::error( 'unknown_locale', __( 'A translation cannot be requested for that locale on this site right now.', 'babilim' ) );
		}
		$response = $this->api()->request_translation(
			array(
				'kind'    => $kind,
				'slug'    => $slug,
				'version' => $version,
				'locale'  => $locale,
			),
			$timeout,
			self::can( true )
		);
		if ( is_wp_error( $response ) ) {
			return Babilim_Result::error( $response->get_error_code(), $response->get_error_message() );
		}
		return Babilim_Result::ok( $response );
	}

	// -- babilim_feedback -- the report page's own form ----------------------

	public function dispatch_babilim_feedback(): void {
		$this->guard( 'babilim_feedback' );
		$kind   = sanitize_key( self::field( 'kind' ) );
		$slug   = self::field( 'slug' );
		$locale = self::field( 'locale' );
		$result = $this->submit_feedback_from_request( $kind, $slug, $locale );
		$this->finish_component_action( $result, $kind, $slug, $locale, __( 'Thank you. Your report was sent.', 'babilim' ) );
	}

	/**
	 * Validates the current request's fields and, if they pass, sends
	 * the resulting `POST /v1/feedback` payload.
	 *
	 * @return Babilim_Result
	 */
	private function submit_feedback_from_request( string $kind, string $slug, string $locale ): Babilim_Result {
		$validated = $this->validate_feedback_request( $kind, $slug, $locale );
		if ( $validated->is_error() ) {
			return $validated;
		}
		return $this->send_feedback( (array) $validated->get( 'payload' ) );
	}

	/**
	 * Validates the current request's fields (consent, category,
	 * build_id) and builds the `POST /v1/feedback` payload -- makes no
	 * HTTP request itself, so a caller like
	 * dispatch_babilim_rollback_report() can check everything is
	 * sendable *before* doing anything destructive.
	 *
	 * @return Babilim_Result ok() with data {payload}, or an error.
	 */
	private function validate_feedback_request( string $kind, string $slug, string $locale ): Babilim_Result {
		if ( ! $this->consent()->granted() ) {
			return Babilim_Result::error( 'no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
		}
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true )
			|| '' === $slug || ! Babilim_Contract_Limits::fits( $slug, Babilim_Contract_Limits::SLUG )
			|| '' === $locale || ! Babilim_Contract_Limits::fits( $locale, Babilim_Contract_Limits::LOCALE ) ) {
			return Babilim_Result::error( 'invalid_component', __( 'This report does not name a valid plugin or theme and locale.', 'babilim' ) );
		}
		$category = self::field( 'category' );
		if ( ! in_array( $category, self::FEEDBACK_CATEGORIES, true ) ) {
			return Babilim_Result::error( 'invalid_category', __( 'Please choose a category for this report.', 'babilim' ) );
		}
		$build_id = self::field( 'build_id' );
		if ( 1 !== preg_match( '/^bd_[0-9a-f]{12}$/D', $build_id ) ) {
			return Babilim_Result::error( 'invalid_build_id', __( 'This report is missing a valid build to report against.', 'babilim' ) );
		}
		if ( ! $this->is_a_known_build( $kind, $slug, $locale, $build_id ) ) {
			return Babilim_Result::error( 'unknown_build', __( 'This build is not one this site currently knows about for this component/locale.', 'babilim' ) );
		}
		$payload = array(
			'kind'     => $kind,
			'slug'     => $slug,
			'locale'   => $locale,
			'build_id' => $build_id,
			'category' => $category,
		);
		// msgid and msgctxt must both reach the service exactly as the
		// original strings were -- sanitize_text_field()/
		// sanitize_textarea_field() strip HTML tags and (the former)
		// collapse internal whitespace/line breaks, which would corrupt
		// either one when it genuinely contains such characters (common
		// in real translation strings -- msgctxt is gettext's own
		// disambiguating-context string, drawn from the same source as
		// msgid, not free-form user text) and make it fail to match the
		// server's own copy; both are kept raw and must be escaped
		// wherever they're later rendered.
		// current_msgstr/suggested/note, by contrast, genuinely are
		// free-form multi-line text a site owner types into a
		// <textarea> -- sanitize_textarea_field() strips tags/scripts
		// the same way sanitize_text_field() does but preserves line
		// breaks.
		//
		// Nothing is ever cut short: a value over the contract's limit
		// (see Babilim_Contract_Limits) is refused with a message that
		// names the field, since silently sending less than the owner
		// wrote would change what they said.
		$texts = array(
			'msgid'          => self::raw_field( 'msgid' ),
			'msgctxt'        => self::raw_field( 'msgctxt' ),
			'current_msgstr' => self::textarea_field( 'current_msgstr' ),
			'suggested'      => self::textarea_field( 'suggested' ),
			'note'           => self::textarea_field( 'note' ),
		);
		foreach ( $texts as $name => $value ) {
			if ( '' !== $value ) {
				$payload[ $name ] = $value;
			}
		}
		$screen_path = self::field( 'screen_path' );
		if ( '' !== $screen_path ) {
			$payload['screen_path'] = $screen_path;
		}
		foreach ( $this->feedback_field_limits() as $name => $limit ) {
			$length = Babilim_Contract_Limits::length( (string) ( $payload[ $name ] ?? '' ) );
			if ( $length > $limit['max'] ) {
				return Babilim_Result::error(
					'field_too_long',
					sprintf(
						/* translators: 1: the name of a form field, 2: how many characters it has, 3: the most characters it may have. */
						__( 'The field "%1$s" is too long: it has %2$d characters and the limit is %3$d. Nothing was sent; please shorten it and try again.', 'babilim' ),
						$limit['label'],
						$length,
						$limit['max']
					)
				);
			}
		}
		$plural_index = self::field( 'plural_index' );
		if ( '' !== $plural_index && ctype_digit( $plural_index ) && strlen( $plural_index ) <= 9 ) {
			$payload['plural_index'] = (int) $plural_index;
		}

		return Babilim_Result::ok( array( 'payload' => $payload ) );
	}

	/**
	 * The feedback fields with a length limit: each one's visible label
	 * and its limit in characters (the contract's own numbers).
	 *
	 * @return array<string,array{label:string,max:int}>
	 */
	private function feedback_field_limits(): array {
		return array(
			'msgid'          => array(
				'label' => __( 'Original string', 'babilim' ),
				'max'   => Babilim_Contract_Limits::FEEDBACK_TEXT,
			),
			'msgctxt'        => array(
				'label' => __( 'String context', 'babilim' ),
				'max'   => Babilim_Contract_Limits::FEEDBACK_TEXT,
			),
			'current_msgstr' => array(
				'label' => __( 'Current translation', 'babilim' ),
				'max'   => Babilim_Contract_Limits::FEEDBACK_TEXT,
			),
			'suggested'      => array(
				'label' => __( 'Suggested translation', 'babilim' ),
				'max'   => Babilim_Contract_Limits::FEEDBACK_TEXT,
			),
			'note'           => array(
				'label' => __( 'Note (optional)', 'babilim' ),
				'max'   => Babilim_Contract_Limits::FEEDBACK_TEXT,
			),
			'screen_path'    => array(
				'label' => __( 'Where you saw this (optional)', 'babilim' ),
				'max'   => Babilim_Contract_Limits::SCREEN_PATH,
			),
		);
	}

	/**
	 * Whether $build_id is one this site actually currently knows about
	 * for (kind, slug, locale) -- either the last-synced status entry's
	 * own build_id, or the build_id of a genuinely installed manifest
	 * record. Reporting a problem is only ever meaningful (and only ever
	 * accepted server-side) for a build this site has downloaded or
	 * installed -- an arbitrary string that merely matches the `bd_...`
	 * shape is never enough on its own.
	 */
	private function is_a_known_build( string $kind, string $slug, string $locale, string $build_id ): bool {
		$status = $this->state()->status( $kind, $slug, $locale );
		if ( null !== $status && (string) ( $status['build_id'] ?? '' ) === $build_id ) {
			return true;
		}
		if ( $this->manifest()->is_available() ) {
			$installed = $this->manifest()->installed( $kind, $slug, $locale );
			if ( null !== $installed && (string) ( $installed['build_id'] ?? '' ) === $build_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The site owner-visible trace of Babilim_Site::sync()'s own
	 * `rate_limited_until` state: a 429 from the service's per-site
	 * inventory limit isn't a fault this plugin can retry around on its
	 * own (the service itself is the one throttling), so this is purely
	 * informational -- no form, no button, nothing to click; the next
	 * scheduled sync after the deadline simply tries again. Empty string
	 * (no notice) when the site isn't currently in that state.
	 *
	 * @return string
	 */
	private function render_rate_limited_notice(): string {
		$until = $this->site()->rate_limited_until();
		if ( null === $until ) {
			return '';
		}
		return '<div class="notice notice-warning"><p>' . sprintf(
			/* translators: %s: the date and time syncing is expected to resume. */
			esc_html__( 'Babilim sync is temporarily delayed by the service (rate limited); it will try again after %s.', 'babilim' ),
			esc_html( self::format_time( $until ) )
		) . '</p></div>';
	}

	/**
	 * Sends an already-validated `POST /v1/feedback` payload (see
	 * validate_feedback_request()) -- never called with anything the
	 * site owner's own request wasn't already checked against.
	 *
	 * @param array $payload See validate_feedback_request().
	 * @return Babilim_Result
	 */
	private function send_feedback( array $payload ): Babilim_Result {
		$response = $this->api()->feedback( $payload );
		if ( is_wp_error( $response ) ) {
			return Babilim_Result::error( $response->get_error_code(), $response->get_error_message() );
		}
		return Babilim_Result::ok( $response );
	}

	// -- babilim_save_settings ------------------------------------------------

	public function dispatch_babilim_save_settings(): void {
		$this->guard( 'babilim_save_settings' );

		// A locale typed by the owner is checked before anything is
		// saved: one that is not a locale code, or is longer than the
		// service accepts, would only be dropped later without a word.
		$new_locale = self::field( 'new_locale' );
		if ( '' !== $new_locale && ( ! Babilim_Paths::is_safe_locale( $new_locale ) || ! Babilim_Contract_Limits::fits( $new_locale, Babilim_Contract_Limits::LOCALE ) ) ) {
			$this->notice(
				'invalid_locale',
				sprintf(
					/* translators: %d: the most characters a locale code may have. */
					__( 'The locale you added is not valid: use a WordPress locale code such as ll_CC (letters, digits, underscore, hyphen or @, at most %d characters). Nothing was saved.', 'babilim' ),
					Babilim_Contract_Limits::LOCALE
				),
				'error'
			);
			wp_safe_redirect( self::page_url( 'settings', array( 'tab' => 'general' ) ) );
			return;
		}

		$raw_locales = isset( $_POST['locales'] ) ? wp_unslash( $_POST['locales'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by guard() above; each element is sanitized individually in the loop below.
		$submitted   = array();
		if ( is_array( $raw_locales ) ) {
			foreach ( $raw_locales as $raw_locale ) {
				$submitted[] = sanitize_text_field( is_string( $raw_locale ) ? $raw_locale : '' );
			}
		}
		if ( '' !== $new_locale ) {
			$submitted[] = $new_locale;
		}
		$submitted = array_values( array_unique( $submitted ) );

		// A detected locale (see Babilim_Inventory::detected_locales())
		// is never written into the manual list: unchecking it puts it
		// on the stopped list instead (its installed files stay), and
		// checking it again takes it off. Only a locale the owner added
		// is kept as manual, so unchecking one removes it.
		$detected = $this->inventory()->detected_locales();
		$this->inventory()->set_disabled_locales( array_values( array_diff( $detected, $submitted ) ) );
		$this->inventory()->set_manual_locales( array_values( array_diff( $submitted, $detected ) ) );

		update_option( self::OPTION_KEEP_CUSTOM, 'on' === self::field( 'keep_custom' ) );
		update_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALL, 'on' === self::field( 'auto_install' ) ? 1 : 0, false );
		// Through the registered Settings API sanitizer (see register_settings()).
		update_option( self::OPTION_DELETE_DATA, 'on' === self::field( 'delete_data_on_uninstall' ) ? 'on' : '' );

		$this->notice( 'settings_saved', __( 'Settings saved.', 'babilim' ), 'success' );
		wp_safe_redirect( self::page_url( 'settings', array( 'tab' => 'general' ) ) );
	}

	/**
	 * `admin_init` callback -- registers the one option this plugin's
	 * settings form stores through the Settings API, with its own
	 * sanitizer, so every write path goes through the same coercion.
	 */
	public function register_settings(): void {
		register_setting(
			'babilim_settings',
			self::OPTION_DELETE_DATA,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( self::class, 'sanitize_delete_data' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitizer for the delete-all option: only an explicit truthy value
	 * turns it on; anything else (missing, empty, an array) is off.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return bool
	 */
	public static function sanitize_delete_data( $value ): bool {
		return is_scalar( $value ) && filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Whether uninstalling also deletes the site identity.
	 *
	 * @return bool
	 */
	private static function delete_data_on_uninstall(): bool {
		return (bool) get_option( self::OPTION_DELETE_DATA, false );
	}

	/**
	 * The support address shown in the license warnings. It comes from
	 * the SUPPORT_EMAIL class constant and is never part of a
	 * translatable string.
	 *
	 * @return string
	 */
	private static function support_email(): string {
		return self::SUPPORT_EMAIL;
	}

	/**
	 * The plain-words consequence of deleting the site identity, shown
	 * next to the setting, in the plugins list and in the readme.
	 *
	 * @return string
	 */
	private static function delete_data_risk_text(): string {
		return sprintf(
			/* translators: %s: Babilim support email address. Do not translate or change it. */
			__( 'If this is on, uninstalling also erases this site\'s identity. The paid license attached to this site cannot be restored automatically afterwards; you would have to contact Babilim support at %s to get it back.', 'babilim' ),
			self::support_email()
		);
	}

	/**
	 * The question asked before this plugin is deactivated while
	 * "Delete all Babilim data on uninstall" is on. Deactivating is the
	 * last moment this plugin's code runs: once it is inactive and the
	 * owner deletes it, nothing of it is loaded to warn anyone.
	 *
	 * @return string
	 */
	private static function deactivate_confirm_text(): string {
		return sprintf(
			/* translators: %s: Babilim support email address. Do not translate or change it. */
			__( 'Babilim is set to erase this site\'s identity when the plugin is deleted. The paid license attached to this site could then not be restored automatically; you would have to contact Babilim support at %s. To keep the identity, press Cancel, untick "Delete all Babilim data on uninstall" in the Babilim settings, and deactivate again. Deactivate anyway?', 'babilim' ),
			self::support_email()
		);
	}

	/**
	 * The most sites the network plugins list will read one by one to
	 * decide whether to ask before deactivating. A network this large
	 * gets the generic question without a count instead.
	 */
	private const NETWORK_SITE_SCAN_LIMIT = 1000;

	/**
	 * The question asked before the plugin is deactivated from the
	 * network plugins list, for $sites sites that have "Delete all
	 * Babilim data on uninstall" on. Uninstalling a network cleans every
	 * site by that site's own setting, so this counts the sites, not the
	 * current one.
	 *
	 * @param int $sites How many sites have the setting on (at least 1).
	 * @return string
	 */
	private static function network_deactivate_confirm_text( int $sites ): string {
		return sprintf(
			/* translators: 1: number of sites on the network that erase their Babilim identity when the plugin is deleted. 2: Babilim support email address. Do not translate or change it. */
			_n(
				'%1$d site on this network is set to erase its Babilim identity when the plugin is deleted. The paid license attached to that site could then not be restored automatically; you would have to contact Babilim support at %2$s. To keep the identity, press Cancel, untick "Delete all Babilim data on uninstall" in that site\'s Babilim settings, and deactivate again. Deactivate anyway?',
				'%1$d sites on this network are set to erase their Babilim identity when the plugin is deleted. The paid license attached to those sites could then not be restored automatically; you would have to contact Babilim support at %2$s. To keep the identities, press Cancel, untick "Delete all Babilim data on uninstall" in each site\'s Babilim settings, and deactivate again. Deactivate anyway?',
				$sites,
				'babilim'
			),
			$sites,
			self::support_email()
		);
	}

	/**
	 * The question for a network too large to check site by site.
	 *
	 * @return string
	 */
	private static function network_deactivate_generic_confirm_text(): string {
		return sprintf(
			/* translators: %s: Babilim support email address. Do not translate or change it. */
			__( 'This network has too many sites to check them one by one, and some of them may be set to erase their Babilim identity when the plugin is deleted. The paid license attached to such a site could then not be restored automatically; you would have to contact Babilim support at %s. To keep the identities, press Cancel, untick "Delete all Babilim data on uninstall" in each site\'s Babilim settings, and deactivate again. Deactivate anyway?', 'babilim' ),
			self::support_email()
		);
	}

	/**
	 * The confirmation question for the network plugins list, or null
	 * when no site is set to erase its data. Reads every site's own
	 * setting the way uninstall.php does when it cleans them.
	 *
	 * @return string|null
	 */
	private static function network_deactivate_question(): ?string {
		$ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => self::NETWORK_SITE_SCAN_LIMIT,
			)
		);
		if ( count( $ids ) >= self::NETWORK_SITE_SCAN_LIMIT ) {
			return self::network_deactivate_generic_confirm_text();
		}
		$erasing = 0;
		foreach ( $ids as $site_id ) {
			if ( (bool) get_blog_option( (int) $site_id, self::OPTION_DELETE_DATA, false ) ) {
				++$erasing;
			}
		}
		return $erasing > 0 ? self::network_deactivate_confirm_text( $erasing ) : null;
	}

	/**
	 * `plugin_action_links_{plugin_file}` callback: while "Delete all
	 * Babilim data on uninstall" is on, the Deactivate link asks for
	 * confirmation first (Cancel leaves the plugin active). With the
	 * option off the links are returned untouched.
	 *
	 * On the network plugins list the answer comes from every site's own
	 * setting instead of the current site's, because deleting the plugin
	 * network-wide cleans each site by its own setting. Only that screen
	 * pays for reading the sites.
	 *
	 * @param mixed $links The plugin's action links.
	 * @return mixed
	 */
	public function confirm_deactivate_link( $links ) {
		if ( ! is_array( $links ) || ! isset( $links['deactivate'] ) || ! is_string( $links['deactivate'] ) ) {
			return $links;
		}
		$network = is_multisite() && is_network_admin();
		if ( ! self::can( $network ) ) {
			return $links;
		}
		if ( $network ) {
			$question = self::network_deactivate_question();
		} else {
			$question = self::delete_data_on_uninstall() ? self::deactivate_confirm_text() : null;
		}
		if ( null === $question ) {
			return $links;
		}
		$links['deactivate'] = preg_replace(
			'/^<a\s/',
			'<a data-babilim-confirm="' . esc_attr( $question ) . '" ',
			$links['deactivate'],
			1
		);
		return $links;
	}

	/**
	 * `admin_notices` callback: the verification warning on the Dashboard
	 * and Plugins screens only (this plugin's own screens already show it
	 * inline), for users who can act on it. It never appears elsewhere.
	 */
	public function render_verification_admin_notice(): void {
		if ( ! self::can() || ! $this->consent()->granted() ) {
			return;
		}
		$current = isset( $GLOBALS['hook_suffix'] ) && is_string( $GLOBALS['hook_suffix'] ) ? $GLOBALS['hook_suffix'] : '';
		if ( ! in_array( $current, array( 'index.php', 'plugins.php' ), true ) ) {
			return;
		}
		$warning = $this->site()->verification_warning();
		if ( null === $warning ) {
			return;
		}
		$html = '<div class="notice notice-warning"><p><strong>' . esc_html( self::verification_headline() ) . '</strong> '
			. esc_html( self::verification_advice( $warning['reason'] ) ) . ' '
			. '<a href="' . esc_url( self::page_url( 'settings' ) ) . '">' . esc_html__( 'Open Babilim settings', 'babilim' ) . '</a></p></div>';
		echo wp_kses( $html, self::allowed_admin_html() );
	}

	private static function verification_headline(): string {
		return __( 'Babilim could not verify this site, so nothing is downloaded or installed.', 'babilim' );
	}

	/**
	 * What to do about one verification failure reason.
	 *
	 * @param string $reason One of Babilim_Site's REASON_* values.
	 * @return string
	 */
	private static function verification_advice( string $reason ): string {
		switch ( $reason ) {
			case Babilim_Site::REASON_UNREACHABLE:
				return __( 'The service could not reach your site. A firewall, security plugin or host rule is probably blocking the request; please allow requests to /wp-json/babilim/v1/challenge.', 'babilim' );
			case Babilim_Site::REASON_TIMEOUT:
				return __( 'Your site took too long to answer. Please try again later, and check that your host is not too slow or overloaded.', 'babilim' );
			case Babilim_Site::REASON_INVALID:
				return __( 'Your site answered, but not with what was expected. The REST API may be rewritten by another plugin, or that endpoint may be cached; please exclude /wp-json/babilim/v1/challenge from caching and rewriting.', 'babilim' );
			default:
				return __( 'Please check that your site can be reached from the internet, then try again.', 'babilim' );
		}
	}

	/**
	 * The verification warning block with the Re-verify button, or ''
	 * when there is nothing to warn about.
	 *
	 * @return string
	 */
	private function render_verification_warning(): string {
		if ( ! $this->consent()->granted() ) {
			return '';
		}
		$warning = $this->site()->verification_warning();
		if ( null === $warning ) {
			return '';
		}
		$html  = '<div class="notice notice-warning"><p><strong>' . esc_html( self::verification_headline() ) . '</strong></p>';
		$html .= '<p>' . esc_html( self::verification_advice( $warning['reason'] ) ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="babilim-inline">';
		$html .= '<input type="hidden" name="action" value="babilim_reverify" />';
		$html .= wp_nonce_field( 'babilim_reverify', '_wpnonce', true, false );
		$html .= '<button type="submit" class="button button-primary">' . esc_html__( 'Re-verify', 'babilim' ) . '</button>';
		$html .= '</form></div>';
		return $html;
	}

	/**
	 * A note when the last sync had to leave something out because of the
	 * service's request limits (a locale list past 256, or a component too
	 * large for any request); empty otherwise.
	 *
	 * @return string
	 */
	private function render_omitted_notice(): string {
		$omitted    = (array) ( $this->state()->get()['omitted'] ?? array() );
		$components = (int) ( $omitted['components'] ?? 0 );
		$locales    = (int) ( $omitted['locales'] ?? 0 );
		if ( $components <= 0 && $locales <= 0 ) {
			return '';
		}
		$message = sprintf(
			/* translators: 1: number of components left out, 2: number of locales left out. */
			__( 'This site is over the size the service accepts in one sync, so some items were left out: %1$d components and %2$d locales.', 'babilim' ),
			max( 0, $components ),
			max( 0, $locales )
		);
		return '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Where an action started from a settings tab sends the browser
	 * back: the tab named in the request (`advanced` or `general`), else
	 * the given default.
	 *
	 * @param string $default The tab to use when the request names none.
	 * @return string
	 */
	private static function settings_tab_url( string $default ): string {
		$tab = self::field( 'tab' );
		$tab = in_array( $tab, array( 'general', 'advanced' ), true ) ? $tab : $default;
		return self::page_url( 'settings', array( 'tab' => $tab ) );
	}

	// -- babilim_reverify -- manual re-verification --------------------------

	public function dispatch_babilim_reverify(): void {
		$this->guard( 'babilim_reverify' );
		$result = $this->site()->reverify();
		if ( is_wp_error( $result ) ) {
			$this->notice( $result->get_error_code(), $result->get_error_message(), 'error' );
		} else {
			$this->notice( 'verified', __( 'This site is verified.', 'babilim' ), 'success' );
		}
		wp_safe_redirect( self::page_url( 'settings' ) );
	}

	// -- babilim_restart_registration -- recovery UI for the stalled/conflict flags --

	public function dispatch_babilim_restart_registration(): void {
		$this->guard( 'babilim_restart_registration' );
		$result = $this->site()->restart_registration();
		if ( is_wp_error( $result ) ) {
			$this->notice( $result->get_error_code(), $result->get_error_message(), 'error' );
		} else {
			$this->notice( 'registration_restarted', __( 'Registration restarted.', 'babilim' ), 'success' );
		}
		wp_safe_redirect( self::settings_tab_url( 'advanced' ) );
	}

	// -- babilim_sync_now -- manual "sync now" trigger --------------------

	public function dispatch_babilim_sync_now(): void {
		$this->guard( 'babilim_sync_now' );
		// The Advanced tab's button returns there; the list's, to the list.
		$back = 'advanced' === self::field( 'tab' ) ? self::page_url( 'settings', array( 'tab' => 'advanced' ) ) : self::page_url( 'translations' );
		if ( ! $this->consent()->granted() ) {
			// sync() itself is a no-op without consent -- a manual
			// "Check again now" click must say so honestly rather than claim
			// "Sync complete." for a sync that never actually ran.
			$this->notice( 'no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ), 'error' );
			wp_safe_redirect( $back );
			return;
		}
		$result = $this->site()->sync( Babilim_Site::RESUME_BUDGET_SECONDS );
		if ( $result->is_ok() ) {
			$this->notice( 'synced', __( 'Sync complete.', 'babilim' ), 'success' );
		} elseif ( null !== $this->site()->verification_warning() ) {
			// The service could not verify this site, so nothing was
			// sent; "Sync complete." would be untrue.
			$this->notice( 'not_verified', self::verification_headline(), 'warning' );
		} else {
			if ( 'in_progress' === $result->code() ) {
				$this->schedule_follow_up_sync();
			}
			$this->notice_sync_failure( $result );
		}
		wp_safe_redirect( $back );
	}

	// -- babilim_enable / babilim_disable / babilim_sync_restart ------------

	/**
	 * Records the notice for a sync that did not finish: `in_progress`
	 * is information (a background check was scheduled by the caller or
	 * will be), anything else is an error carrying the failed step's own
	 * message.
	 *
	 * @param Babilim_Result $result The failed sync result.
	 */
	private function notice_sync_failure( Babilim_Result $result ): void {
		$code = (string) $result->code();
		if ( 'in_progress' === $code ) {
			$this->notice( 'in_progress', __( 'Babilim is enabling and checking this site… Reload this page or wait for the scheduled check to finish.', 'babilim' ), 'info' );
			return;
		}
		$message = sprintf(
			/* translators: 1: short name of the step that failed, 2: the message for that failure. */
			__( 'Babilim could not finish enabling (%1$s): %2$s', 'babilim' ),
			$code,
			$result->message()
		);
		if ( 'verification_required' === $code ) {
			$message .= ' ' . __( 'A host that serves only one PHP request at a time cannot complete this step.', 'babilim' );
		}
		$this->notice( $code, $message, 'error' );
	}

	/**
	 * Runs one sync with the foreground budget and records its outcome
	 * as a notice. A run that ran out of time schedules one background
	 * check and nudges WP-Cron.
	 *
	 * @param bool $enabling Whether this is the first run after "Enable" (worded as such on success).
	 * @return bool Whether the sync completed.
	 */
	private function run_foreground_sync( bool $enabling ): bool {
		$result = $this->site()->sync( Babilim_Site::ENABLE_BUDGET_SECONDS );
		if ( $result->is_ok() ) {
			$this->notice(
				$enabling ? 'enabled' : 'synced',
				$enabling
					? sprintf(
						/* translators: %d: number of components found on this site. */
						_n(
							'Babilim is enabled: this site is verified and %d component was found.',
							'Babilim is enabled: this site is verified and %d components were found.',
							(int) $result->get( 'components', 0 ),
							'babilim'
						),
						(int) $result->get( 'components', 0 )
					)
					: __( 'Sync complete.', 'babilim' ),
				'success'
			);
			return true;
		}
		if ( 'in_progress' === $result->code() ) {
			$this->schedule_follow_up_sync();
		}
		$this->notice_sync_failure( $result );
		return false;
	}

	/**
	 * Schedules one background check (never a second while one is
	 * pending) and nudges WP-Cron, for a sync that ran out of time.
	 */
	private function schedule_follow_up_sync(): void {
		if ( false === wp_next_scheduled( Babilim::SYNC_ONCE_HOOK ) ) {
			wp_schedule_single_event( time(), Babilim::SYNC_ONCE_HOOK );
		}
		spawn_cron();
	}

	public function dispatch_babilim_enable(): void {
		$this->guard( 'babilim_enable' );
		update_option( Babilim_Site::OPTION_CONSENT, true );
		$synced = $this->run_foreground_sync( true );
		// Let available translations install within minutes instead of
		// waiting for the next twice-daily run; never a second one while
		// a full check is already pending, and the recurring schedule
		// is left alone. Same conditions as the automatic installer.
		if ( $synced && ! is_multisite() && (bool) get_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALL, true ) && ! $this->single_sync_pending() ) {
			wp_schedule_single_event( time() + 60, Babilim::SYNC_HOOK );
		}
		wp_safe_redirect( self::page_url( 'translations' ) );
	}

	/**
	 * Whether a one-off (non-recurring) run of the full check is already
	 * queued. wp_next_scheduled() would also match the twice-daily
	 * event that is always registered, so the cron array is read for an
	 * event without a recurrence instead.
	 *
	 * This relies on _get_cron_array(), which core marks as private API:
	 * core offers no public call that lists a hook's events by whether
	 * they recur. It returns an array on every supported WordPress
	 * version (6.6 and later); a change in its shape would only make
	 * this report "none queued", at the cost of one extra check.
	 *
	 * @return bool
	 */
	private function single_sync_pending(): bool {
		$cron = _get_cron_array();
		foreach ( $cron as $events ) {
			foreach ( (array) ( $events[ Babilim::SYNC_HOOK ] ?? array() ) as $event ) {
				if ( empty( $event['schedule'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public function dispatch_babilim_disable(): void {
		$this->guard( 'babilim_disable' );
		update_option( Babilim_Site::OPTION_CONSENT, false );
		wp_clear_scheduled_hook( Babilim::SYNC_ONCE_HOOK );
		delete_option( Babilim_Site::OPTION_LAST_SYNC_ERROR );
		$this->notice( 'disabled', __( 'Babilim is disabled. Nothing more is sent; translations already installed keep working.', 'babilim' ), 'success' );
		wp_safe_redirect( self::page_url( 'translations' ) );
	}

	public function dispatch_babilim_sync_restart(): void {
		$this->guard( 'babilim_sync_restart' );
		delete_option( Babilim_Site::OPTION_LAST_SYNC_ERROR );
		if ( ! $this->consent()->granted() ) {
			$this->notice( 'no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ), 'error' );
		} else {
			$this->run_foreground_sync( false );
		}
		wp_safe_redirect( self::page_url( 'translations' ) );
	}

	// -- babilim_rebuild_index -- manual rebuild trigger -------------------

	public function dispatch_babilim_rebuild_index(): void {
		$this->guard( 'babilim_rebuild_index', true );
		$locale = self::field( 'locale' );
		$result = ( '' === $locale ) ? $this->default_index()->rebuild_all() : $this->default_index()->rebuild( $locale );
		if ( $result->is_ok() ) {
			$this->notice( 'index_rebuilt', __( 'Supplementary translation index rebuilt.', 'babilim' ), 'success' );
		} else {
			$this->notice( (string) $result->code(), $result->message(), 'error' );
		}
		wp_safe_redirect( self::page_url( 'settings', array( 'tab' => 'advanced' ) ) );
	}

	// -- babilim_sweep_now -- manual sweep trigger -------------------------

	public function dispatch_babilim_sweep_now(): void {
		$this->guard( 'babilim_sweep_now', true );
		$result = $this->default_index()->sweep_orphans();
		if ( $result->is_ok() ) {
			$deleted = count( $result->get( 'files_deleted', array() ) );
			$this->notice(
				'swept',
				sprintf(
					/* translators: %d: number of files deleted by this sweep. */
					_n( 'Sweep complete: %d file deleted.', 'Sweep complete: %d files deleted.', $deleted, 'babilim' ),
					$deleted
				),
				'success'
			);
		} else {
			$this->notice( (string) $result->code(), $result->message(), 'error' );
		}
		wp_safe_redirect( self::page_url( 'settings', array( 'tab' => 'advanced' ) ) );
	}

	// -- babilim_purge_all -- the settings screen's own "danger zone" -------

	/**
	 * Removes every installed record's served files, manifest records,
	 * and the merged `default` index files -- keeping (or clearing)
	 * `custom/` per the site owner's own setting (see
	 * OPTION_KEEP_CUSTOM/keep_custom_on_purge()). See
	 * Babilim_Installer::purge_all() for exactly what this does and
	 * doesn't touch.
	 */
	public function dispatch_babilim_purge_all(): void {
		$this->guard( 'babilim_purge_all', true );
		$result = $this->installer()->purge_all( self::keep_custom_on_purge() );
		if ( $result->is_ok() ) {
			// Everything is gone: cron must not put it all back.
			update_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALL, 0, false );
			$removed = count( $result->get( 'removed', array() ) );
			$this->notice(
				'purged',
				sprintf(
					/* translators: %d: number of components removed. */
					_n( 'Removed %d installed component.', 'Removed %d installed components.', $removed, 'babilim' ),
					$removed
				) . ' ' . __( 'Automatic installs are now off; turn them on again in the settings if you want them.', 'babilim' ),
				'success'
			);
		} else {
			$this->notice( (string) $result->code(), $result->message(), 'error' );
		}
		wp_safe_redirect( self::page_url( 'settings', array( 'tab' => 'advanced' ) ) );
	}

	/**
	 * Shared tail for every action that operates on one (kind, slug,
	 * locale): records the outcome and redirects back to that
	 * component's detail page.
	 */
	private function finish_component_action( Babilim_Result $result, string $kind, string $slug, string $locale, string $success_message ): void {
		if ( $result->is_ok() ) {
			$this->notice( 'ok', $success_message, 'success' );
		} else {
			$this->notice( (string) $result->code(), $result->message(), 'error' );
		}
		unset( $kind, $slug, $locale );
		$extra = array();
		$s     = self::field( 's' );
		if ( '' !== $s ) {
			$extra['s'] = $s;
		}
		$filter = self::field( 'babilim_locale' );
		if ( '' !== $filter ) {
			$extra['babilim_locale'] = $filter;
		}
		$tab = self::kind_tab( self::field( 'babilim_kind' ) );
		if ( '' !== $tab ) {
			$extra['babilim_kind'] = $tab;
		}
		wp_safe_redirect( self::page_url( 'translations', $extra ) );
	}

	// -- rendering ----------------------------------------------------------

	/**
	 * The registered `add_menu_page()` callback: echoes the current
	 * screen, late-escaped through wp_kses() against the fixed tag/
	 * attribute allowlist every render_*() method below is built from --
	 * every render_*() method already escapes its own dynamic content
	 * (esc_html()/esc_attr()/esc_url()), so this is a second, structural
	 * layer: even a future render_*() that forgets to escape a value
	 * still can't inject a tag or attribute outside this allowlist.
	 */
	public function render(): void {
		echo wp_kses( $this->render_current_page(), self::allowed_admin_html() );
	}

	/**
	 * The exact set of tags and attributes every admin screen's own
	 * markup uses -- nothing else. Kept as one list, not "whatever each
	 * render_*() method happens to emit", so adding a new tag/attribute
	 * anywhere in this class is a deliberate, visible change here too.
	 *
	 * No `style` attribute: this class no longer emits one anywhere (see
	 * admin_css()/enqueue_admin_style() for where its former `display`
	 * layout rules moved) -- and real wp_kses()'s own safecss_filter_
	 * attr() would strip `display` out of one even if this allow-list
	 * still permitted the attribute, so there was never a reason for a
	 * hand-written test double to disagree with real core here; it's
	 * left out entirely instead of an allow-list entry nothing uses.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_admin_html(): array {
		$common = array(
			'class' => true,
			'id'    => true,
		);
		return array(
			'div'      => $common,
			'span'     => $common + array(
				'title'       => true,
				'aria-label'  => true,
				'aria-hidden' => true,
			),
			'p'        => $common,
			'h1'       => $common,
			'h2'       => $common,
			'hr'       => $common,
			'ul'       => $common,
			'li'       => $common,
			'br'       => array( 'class' => true ),
			'em'       => $common,
			'code'     => $common,
			'strong'   => $common + array(
				'title'        => true,
				'aria-current' => true,
			),
			'a'        => $common + array(
				'href'         => true,
				'rel'          => true,
				'title'        => true,
				'aria-label'   => true,
				'aria-current' => true,
			),
			'form'     => $common + array(
				'method'                   => true,
				'action'                   => true,
				'target'                   => true,
				'rel'                      => true,
				'data-babilim-autosubmit'  => true,
				'data-babilim-poll-url'    => true,
			),
			'label'    => $common + array(
				'for' => true,
			),
			'input'    => $common + array(
				'type'        => true,
				'name'        => true,
				'value'       => true,
				'required'    => true,
				'checked'     => true,
				'disabled'    => true,
				'placeholder' => true,
				'size'        => true,
				'maxlength'   => true,
				'aria-label'  => true,
				'aria-describedby' => true,
				'form'        => true,
			),
			'select'   => $common + array(
				'name'     => true,
				'required' => true,
				'disabled' => true,
			),
			'option'   => $common + array(
				'title'    => true,
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			),
			'textarea' => $common + array(
				'name'        => true,
				'rows'        => true,
				'cols'        => true,
				'maxlength'   => true,
				'required'    => true,
				'placeholder' => true,
			),
			'button'   => $common + array(
				'title'                    => true,
				'aria-label'               => true,
				'type'                     => true,
				'disabled'                 => true,
				'form'                     => true,
				'data-babilim-confirm'     => true,
				'data-babilim-confirmed'   => true,
				'data-babilim-busy-label'  => true,
			),
			'details'  => $common,
			'summary'  => $common,
			'table'    => $common + array(
				'role' => true,
			),
			'thead'    => $common,
			'tfoot'    => $common,
			'tbody'    => $common,
			'tr'       => $common,
			'th'       => $common + array(
				'scope'      => true,
				'colspan'    => true,
				'data-colname' => true,
			),
			'td'       => $common + array(
				'colspan'      => true,
				'data-colname' => true,
			),
		);
	}

	/**
	 * Dispatches to the requested screen (?screen=overview|detail|
	 * settings|report, default overview) and returns its markup.
	 *
	 * @return string
	 */
	public function render_current_page(): string {
		self::reconcile_persisted_notices();
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, not a state change.
		$screen = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, not a state change.
		if ( self::SETTINGS_SLUG === $page || 'settings' === $screen ) {
			return $this->render_settings();
		}
		if ( 'report' === $screen ) {
			return $this->render_report();
		}
		if ( 'confirm' === $screen ) {
			return $this->render_confirm();
		}
		if ( 'choose' === $screen ) {
			return $this->render_choose();
		}
		if ( 'upload-confirm' === $screen ) {
			return $this->render_upload_confirm();
		}
		if ( 'upload-progress' === $screen ) {
			return $this->render_upload_progress();
		}
		// The former per-component page ('detail') is the list now.
		return $this->render_translations();
	}

	/**
	 * The current settings-errors store as a list of `<div class="notice">`
	 * blocks (settings_errors()'s own real-core markup shape).
	 *
	 * @return string
	 */
	private function render_notices(): string {
		$parts = $this->notice_parts();
		return $parts['alerts'] . self::merged_notice( $parts['lines'] );
	}

	/**
	 * The settings-errors store split by weight: errors and warnings as
	 * ready boxes, info and success as lines for one merged notice.
	 *
	 * @return array{alerts:string,lines:array[]}
	 */
	private function notice_parts(): array {
		$classes = array(
			'error'   => 'notice-error',
			'warning' => 'notice-warning',
			'info'    => 'notice-info',
		);
		$alerts  = '';
		$lines   = array();
		foreach ( get_settings_errors( 'babilim' ) as $error ) {
			$class = $classes[ $error['type'] ] ?? 'notice-success';
			if ( 'notice-error' === $class || 'notice-warning' === $class ) {
				$alerts .= '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $error['message'] ) . '</p></div>';
			} else {
				$lines[] = array( $class, $error['message'] );
			}
		}
		return array( 'alerts' => $alerts, 'lines' => $lines );
	}

	/**
	 * Info and success messages as one notice, a line each. The box
	 * takes the success style unless every line is info.
	 *
	 * @param array[] $lines Pairs of notice class and message.
	 * @return string
	 */
	private static function merged_notice( array $lines ): string {
		if ( array() === $lines ) {
			return '';
		}
		$class = 'notice-info';
		$inner = '';
		foreach ( $lines as $line ) {
			if ( 'notice-success' === $line[0] ) {
				$class = 'notice-success';
			}
			$inner .= '<p>' . esc_html( $line[1] ) . '</p>';
		}
		return '<div class="notice ' . esc_attr( $class ) . '">' . $inner . '</div>';
	}

	/**
	 * The consent disclosure: every field this plugin sends, item by item.
	 *
	 * @return string
	 */
	private function render_payload_list(): string {
		$html = '<ul>';
		foreach ( $this->consent()->payload_preview( $this->inventory() ) as $row ) {
			$html .= '<li><strong>' . esc_html( $row['field'] ) . '</strong>: ' . esc_html( $row['description'] ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * A one-button POST form to an admin-post.php action.
	 *
	 * @param string $action Action name (also the nonce action).
	 * @param string $label  Button label.
	 * @param bool   $primary Whether the button is the primary one.
	 * @param string $tab     A settings tab to return to after the action; empty for none.
	 * @return string
	 */
	private function action_form( string $action, string $label, bool $primary, string $tab = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="babilim-inline">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. ( '' !== $tab ? '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />' : '' )
			. wp_nonce_field( $action, '_wpnonce', true, false )
			. '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * A one-button admin-post form whose button looks like the link
	 * next to the page heading.
	 *
	 * @param string $action admin-post action.
	 * @param string $label  Button text.
	 * @return string
	 */
	private function title_action_form( string $action, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="babilim-inline">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. wp_nonce_field( $action, '_wpnonce', true, false )
			. '<button type="submit" class="page-title-action">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Before the site owner has agreed: what Babilim does, exactly what
	 * is sent, and the one button that agrees and starts.
	 *
	 * @return string
	 */
	private function render_welcome(): string {
		$html  = '<div class="wrap"><h1>' . esc_html__( 'Babilim', 'babilim' ) . '</h1>';
		$html .= $this->render_notices();
		$html .= '<p>' . esc_html__( 'Babilim finds the plugins and themes on this site that have no translation for your language and installs one for each.', 'babilim' ) . '</p>';
		// Local data only (installed languages, user and site locales):
		// nothing is requested before the owner has agreed.
		$names = array_map( array( 'Babilim_Locale_Label', 'name' ), $this->inventory()->detected_locales() );
		if ( array() !== $names ) {
			$html .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: a list of language names, joined the way the language joins lists. */
					__( 'Languages found on this site: %s.', 'babilim' ),
					wp_sprintf( '%l', $names )
				)
			) . '</p>';
		}
		$html .= '<p>' . esc_html__( 'Available translations can be installed with one click, and missing ones can be requested. After that, new translations and updates are installed automatically (on the free plan, only for plugins and themes that already use a free slot).', 'babilim' ) . '</p>';
		$html .= '<p>' . esc_html__( 'The free plan covers a limited number of plugins and themes; your exact quota is shown in Settings after you enable Babilim.', 'babilim' ) . '</p>';
		$html .= '<details><summary>' . esc_html__( 'What is sent when you enable Babilim', 'babilim' ) . '</summary>' . $this->render_payload_list() . '</details>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="babilim_enable" />';
		$html .= wp_nonce_field( 'babilim_enable', '_wpnonce', true, false );
		$html .= '<button type="submit" class="button button-primary" data-babilim-busy-label="' . esc_attr__( 'Enabling…', 'babilim' ) . '">' . esc_html__( 'Enable Babilim', 'babilim' ) . '</button>';
		$html .= '<p class="description">' . esc_html__( 'By clicking Enable, you agree to send the information listed above to Babilim.', 'babilim' ) . '</p></form></div>';
		return $html;
	}

	/**
	 * The translations screen. Which of its forms is shown follows from
	 * what is stored (consent, the first completed check, the last
	 * sync error), never from the request that got here: with consent
	 * but no completed check yet, this load runs the check itself.
	 *
	 * @return string
	 */
	private function render_translations(): string {
		if ( ! $this->consent()->granted() ) {
			return $this->render_welcome();
		}

		// A site with no completed check and no recorded failure has not
		// been checked yet; one with a recorded failure waits for the
		// button or the scheduled run instead of retrying on every load.
		// A completed check with no components is recorded too (see
		// Babilim_Site::sync()), so such a site is not checked again here.
		$fetched = $this->state()->get()['fetched_at'] ?? null;
		if ( empty( $fetched ) && ! is_array( get_option( Babilim_Site::OPTION_LAST_SYNC_ERROR, false ) ) ) {
			$this->site()->sync( Babilim_Site::RESUME_BUDGET_SECONDS );
		}
		$state      = $this->state()->get();
		$components = (array) ( $state['components'] ?? array() );
		$error      = get_option( Babilim_Site::OPTION_LAST_SYNC_ERROR, false );

		// The title bar: Check again now and Settings sit next to the
		// heading once there is something to check; notices go below it.
		$html  = '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Babilim', 'babilim' ) . '</h1>';
		if ( ! empty( $fetched ) || ! empty( $components ) ) {
			$html .= $this->title_action_form( 'babilim_sync_now', __( 'Check again now', 'babilim' ) ) . ' '
				. '<a class="page-title-action" href="' . esc_url( self::page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'babilim' ) . '</a>';
		}
		$html .= '<hr class="wp-header-end" />';
		// Errors and warnings keep their own boxes and come first; info
		// and success messages follow as one notice.
		$parts  = $this->notice_parts();
		$bulk   = $this->render_bulk_result();
		$lines  = array_merge( $parts['lines'], $bulk['lines'] );
		$auto   = (int) get_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALLED, 0 );
		if ( $auto > 0 ) {
			$lines[] = array(
				'notice-info',
				sprintf(
					/* translators: %d: how many translations were installed automatically. */
					_n( 'Babilim installed %d translation automatically since your last visit.', 'Babilim installed %d translations automatically since your last visit.', $auto, 'babilim' ),
					$auto
				),
			);
			update_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALLED, 0, false );
		}
		$html  .= $parts['alerts'];
		$html  .= $this->render_verification_warning();
		$html  .= $this->render_omitted_notice();
		$html  .= $this->render_rate_limited_notice();
		$html  .= $bulk['alerts'];

		$progress = '';
		if ( is_array( $error ) ) {
			$code    = (string) ( $error['code'] ?? '' );
			$message = (string) ( $error['message'] ?? '' );
			if ( 'in_progress' === $code ) {
				$progress = '<div class="notice notice-info"><p>' . esc_html(
					sprintf(
						/* translators: %s: when the last attempt happened. */
						__( 'Babilim is enabling and checking this site… (last attempt: %s)', 'babilim' ),
						self::format_time( (string) ( $error['at'] ?? '' ) )
					)
				) . '</p><p>' . esc_html( $message ) . '</p>'
					. '<p>' . $this->action_form( 'babilim_sync_now', __( 'Check again', 'babilim' ), true ) . '</p></div>';
			} else {
				$html .= '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p>'
					. '<p>' . $this->action_form( 'babilim_sync_restart', __( 'Check again now', 'babilim' ), true ) . '</p></div>';
			}
		}
		$html .= self::merged_notice( $lines ) . $progress . $bulk['after'];

		if ( empty( $fetched ) && empty( $components ) ) {
			return $html . '</div>';
		}

		if ( empty( $components ) ) {
			$html .= '<p>' . esc_html__( 'No components synced yet.', 'babilim' ) . '</p></div>';
			return $html;
		}

		return $html . $this->render_translations_table( $state ) . '</div>';
	}

	/**
	 * The one-line bulk result, listing only the parts that are not zero.
	 *
	 * @param string $op        The bulk action (download, update or request).
	 * @param int    $ok        How many succeeded.
	 * @param int    $skipped   How many were skipped.
	 * @param int    $failed    How many failed.
	 * @param int    $untried   How many were not tried yet.
	 * @return string
	 */
	private static function bulk_summary( string $op, int $ok, int $skipped, int $failed, int $untried ): string {
		$parts = array();
		if ( $ok > 0 ) {
			/* translators: %d: how many items succeeded. */
			$parts[] = sprintf( _n( '%d succeeded', '%d succeeded', $ok, 'babilim' ), $ok );
		}
		if ( $skipped > 0 ) {
			/* translators: %d: how many items were skipped. */
			$parts[] = sprintf( _n( '%d skipped', '%d skipped', $skipped, 'babilim' ), $skipped );
		}
		if ( $failed > 0 ) {
			/* translators: %d: how many items failed. */
			$parts[] = sprintf( _n( '%d failed', '%d failed', $failed, 'babilim' ), $failed );
		}
		if ( $untried > 0 ) {
			/* translators: %d: how many items were not tried yet. */
			$parts[] = sprintf( _n( '%d not yet tried', '%d not yet tried', $untried, 'babilim' ), $untried );
		}
		/* translators: %s: the bulk action (download, update or request). */
		$head = sprintf( __( 'Bulk %s:', 'babilim' ), $op );
		if ( array() === $parts ) {
			/* translators: 1: the "Bulk <action>:" lead-in. */
			return sprintf( __( '%1$s nothing to do.', 'babilim' ), $head );
		}
		/* translators: 1: the "Bulk <action>:" lead-in, 2: the counts, already joined into a list. */
		return sprintf( __( '%1$s %2$s.', 'babilim' ), $head, wp_sprintf( '%l', $parts ) );
	}

	/**
	 * The result of this request's bulk POST: one notice, the failures
	 * (at most ten), and a form that continues with what was not tried.
	 *
	 * @return array{alerts:string,lines:array[],after:string} Error and warning boxes, lines for the merged notice, and a box that carries a form.
	 */
	private function render_bulk_result(): array {
		$none   = array( 'alerts' => '', 'lines' => array(), 'after' => '' );
		$result = $this->bulk_result;
		if ( ! is_array( $result ) ) {
			return $none;
		}
		if ( isset( $result['error'] ) ) {
			return array( 'alerts' => '<div class="notice notice-error"><p>' . esc_html( (string) $result['error'] ) . '</p></div>' ) + $none;
		}
		$failed  = (array) $result['failed'];
		$untried = (array) $result['untried'];
		$summary = self::bulk_summary( (string) $result['op'], (int) $result['ok'], (int) $result['skipped'], count( $failed ), count( $untried ) );
		if ( array() === $failed && array() === $untried ) {
			// Nothing to act on: the line joins the merged notice.
			return array( 'lines' => array( array( 'notice-success', $summary ) ) ) + $none;
		}
		$html = '<div class="notice ' . ( array() === $failed ? 'notice-success' : 'notice-warning' ) . '"><p>' . esc_html( $summary ) . '</p>';
		if ( array() !== $failed ) {
			$html .= '<ul>';
			foreach ( array_slice( $failed, 0, 10 ) as $row ) {
				$parts     = explode( ':', (string) $row[0], 3 );
				$component = 3 === count( $parts ) ? $this->installed_component( $parts[0], $parts[1] ) : null;
				$name      = null !== $component && '' !== (string) ( $component['name'] ?? '' ) ? (string) $component['name'] : (string) ( $parts[1] ?? $row[0] );
				$html     .= '<li>' . esc_html(
					sprintf(
						/* translators: 1: component name, 2: language name, 3: why the action failed. */
						__( '%1$s (%2$s): %3$s', 'babilim' ),
						$name,
						Babilim_Locale_Label::name( (string) ( $parts[2] ?? '' ) ),
						(string) $row[1]
					)
				) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( array() !== $untried ) {
			$extra = array();
			if ( '' !== self::field_get( 's' ) ) {
				$extra['s'] = self::field_get( 's' );
			}
			if ( '' !== self::field_get( 'babilim_locale' ) ) {
				$extra['babilim_locale'] = self::field_get( 'babilim_locale' );
			}
			$tab = self::kind_tab( self::field_get( 'babilim_kind' ) );
			if ( '' !== $tab ) {
				$extra['babilim_kind'] = $tab;
			}
			// A clean batch that made progress continues by itself (the
			// script submits after half a second); a failure or a batch
			// that tried nothing waits for the button, so it cannot loop.
			$progress = (int) $result['ok'] + (int) $result['skipped'] > 0;
			$auto     = array() === $failed && $progress ? ' data-babilim-autosubmit="1"' : '';
			$html .= '<form method="post"' . $auto . ' action="' . esc_url( self::page_url( 'translations', $extra ) ) . '">'
				. wp_nonce_field( 'bulk-translations', '_wpnonce', false, false )
				. '<input type="hidden" name="action" value="' . esc_attr( (string) $result['op'] ) . '" />';
			foreach ( (array) $result['components'] as $component ) {
				$html .= '<input type="hidden" name="components[]" value="' . esc_attr( (string) $component ) . '" />';
			}
			foreach ( $untried as $key ) {
				$html .= '<input type="hidden" name="pending[]" value="' . esc_attr( (string) $key ) . '" />';
			}
			$html .= '<p><button type="submit" class="button button-primary" data-babilim-busy-label="' . esc_attr__( 'Continuing…', 'babilim' ) . '">' . esc_html(
				sprintf(
					/* translators: %d: how many operations are left. */
					__( 'Continue next batch (%d left)', 'babilim' ),
					count( $untried )
				)
			) . '</button></p></form>';
		}
		$html .= '</div>';
		// Failures belong with the other warnings; a box that only
		// carries the continue form comes after the merged notice.
		return array( array() === $failed ? 'after' : 'alerts' => $html ) + $none;
	}

	/**
	 * The filter form, the bulk form with the table, and the single-cell
	 * forms. Filters come from the query string only.
	 *
	 * @param array $state Stored state.
	 * @return string
	 */
	private function render_translations_table( array $state ): string {
		$view   = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$view   = in_array( $view, array( 'available', 'updates', 'requestable', 'installed' ), true ) ? $view : 'all';
		$locale = isset( $_GET['babilim_locale'] ) && is_string( $_GET['babilim_locale'] ) ? sanitize_text_field( wp_unslash( $_GET['babilim_locale'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$kind   = self::kind_tab( self::field_get( 'babilim_kind' ) );

		$all_items   = $this->list_items( $state );
		$kind_counts = Babilim_Translations_Table::kind_counts( $all_items, $locale, $search );
		// The view counts are taken inside the current tab, after the
		// language filter and the search -- like the tab counts.
		$items    = Babilim_Translations_Table::filter_items( $all_items, 'all', $locale, $search, $kind );
		$counts   = Babilim_Translations_Table::views_counts( $items );
		// The language links list every language, whatever the tab.
		$locales  = array();
		foreach ( $all_items as $item ) {
			$locales = array_merge( $locales, array_keys( $item['cells'] ) );
		}
		$locales = Babilim_Locale_Label::sorted( $locales );

		$free  = 'free' === (string) ( ( (array) ( $state['license'] ?? array() ) )['kind'] ?? '' );
		$table = new Babilim_Translations_Table( Babilim_Translations_Table::filter_items( $items, $view, $locale, $search ), $counts, $view, $locale, $search, $free, $locales, $this->checkout_offered(), $kind, $kind_counts, Babilim_Translations_Table::locale_action_counts( $all_items, $search, $kind ) );
		$table->prepare_items();

		ob_start();
		echo $table->kind_tabs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping helpers.
		echo '<form method="get" id="babilim-filter"><input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		if ( 'all' !== $view ) {
			echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '" />';
		}
		echo $table->kind_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping helpers.
		$table->views();
		$table->search_box( __( 'Search components', 'babilim' ), 'babilim' );
		if ( '' !== $locale ) {
			echo '<input type="hidden" name="babilim_locale" value="' . esc_attr( $locale ) . '" />';
		}
		$locale_links = $table->locale_links();
		if ( '' !== $locale_links ) {
			// translators: %s: link to the Settings page, with the text "Settings".
			$languages_note = esc_html__( 'These are the languages this site uses. You can change them in %s.', 'babilim' );
			echo $locale_links . '<p class="description">' . sprintf( $languages_note, '<a href="' . esc_url( self::page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'babilim' ) . '</a>' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping helpers.
		}
		echo '</form>';
		echo '<form method="post" id="babilim-translations">' . $table->kind_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping helpers.
		// display() prints the bulk nonce (bulk-translations) itself.
		$table->display();
		echo '</form>' . $table->cell_forms() . $table->row_forms() . $this->render_checkout_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping helpers.
		return (string) ob_get_clean();
	}

	/**
	 * What uploading a component's source means, point by point, and the
	 * two buttons. Shown before anything is packaged or sent.
	 *
	 * @return string
	 */
	private function render_upload_confirm(): string {
		$pending = Babilim_Upload::pending();
		$html    = '<div class="wrap"><h1>' . esc_html__( 'Upload this component?', 'babilim' ) . '</h1>' . $this->render_notices();
		if ( null === $pending ) {
			return $html . '<p>' . esc_html__( 'There is no upload waiting for confirmation.', 'babilim' ) . '</p><p><a class="button" href="' . esc_url( self::page_url( 'translations' ) ) . '">' . esc_html__( 'Back', 'babilim' ) . '</a></p></div>';
		}
		$name    = '' !== (string) ( $pending['name'] ?? '' ) ? (string) $pending['name'] : (string) ( $pending['slug'] ?? '' );
		$version = (string) ( $pending['version'] ?? '' );
		$html   .= '<p>' . esc_html__( 'To translate this component, Babilim needs its source.', 'babilim' ) . '</p><ul>';
		/* translators: 1: plugin or theme name, 2: its version number. */
		$html .= '<li>' . esc_html( sprintf( __( 'The entire plugin or theme directory of %1$s (version %2$s) will be uploaded to Babilim.', 'babilim' ), $name, $version ) ) . '</li>';
		$html .= '<li>' . esc_html__( 'If that directory contains keys, logs or customer data, they will be uploaded too. The service removes common sensitive files (configuration, environment, database dumps, logs, keys) but cannot guarantee it finds everything.', 'babilim' ) . '</li>';
		$html .= '<li>' . esc_html__( 'The upload is kept until you ask Babilim to delete it.', 'babilim' ) . '</li>';
		$html .= '<li>' . esc_html__( 'It is stored by the service\'s hosting provider and processed by AI models to produce the translation.', 'babilim' ) . '</li>';
		$html .= '<li>' . esc_html__( 'The original text and the translations made from it will be provided to other sites that install the same version.', 'babilim' ) . '</li>';
		$html .= '<li>' . esc_html__( 'By continuing you confirm that you have the right to upload this component.', 'babilim' ) . '</li></ul>';
		$html .= $this->upload_form( 'babilim_upload_confirm', __( 'Upload and request', 'babilim' ), true, __( 'Uploading…', 'babilim' ) );
		$html .= $this->upload_form( 'babilim_upload_cancel', __( 'Cancel', 'babilim' ), false );
		return $html . '</div>';
	}

	/**
	 * Progress of the running upload. The Continue form is submitted by
	 * the admin script; without JavaScript the button is pressed by hand.
	 *
	 * @return string
	 */
	private function render_upload_progress(): string {
		$pending = Babilim_Upload::pending();
		$html    = '<div class="wrap"><h1>' . esc_html__( 'Uploading', 'babilim' ) . '</h1>' . $this->render_notices();
		if ( null === $pending || ! isset( $pending['zip'] ) ) {
			return $html . '<p>' . esc_html__( 'There is no upload in progress.', 'babilim' ) . '</p><p><a class="button" href="' . esc_url( self::page_url( 'translations' ) ) . '">' . esc_html__( 'Back', 'babilim' ) . '</a></p></div>';
		}
		/* translators: 1: number of the part being sent, 2: total number of parts. */
		$html .= '<p>' . esc_html( sprintf( __( 'Uploading part %1$d of %2$d…', 'babilim' ), min( (int) $pending['next'], (int) $pending['parts'] ), (int) $pending['parts'] ) ) . '</p>';
		$html .= $this->upload_form( 'babilim_upload_step', __( 'Continue', 'babilim' ), true, '', true );
		$html .= $this->upload_form( 'babilim_upload_cancel', __( 'Cancel', 'babilim' ), false );
		return $html . '</div>';
	}

	/** A one-button admin-post form for the upload screens. */
	private function upload_form( string $action, string $label, bool $primary, string $busy_label = '', bool $autosubmit = false ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="babilim-inline"' . ( $autosubmit ? ' data-babilim-autosubmit="1"' : '' ) . '>'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. wp_nonce_field( $action, '_wpnonce', true, false )
			. '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '"' . ( '' !== $busy_label ? ' data-babilim-busy-label="' . esc_attr( $busy_label ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	/**
	 * The confirmation page a destructive action lands on when it was
	 * sent without confirmed=1 (no JavaScript): the question, a POST
	 * form that sends confirmed=1, and Cancel.
	 *
	 * @return string
	 */
	private function render_confirm(): string {
		$op     = isset( $_GET['op'] ) && is_string( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; the POST it builds carries its own nonce.
		$kind   = isset( $_GET['kind'] ) && is_string( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug   = isset( $_GET['slug'] ) && is_string( $_GET['slug'] ) ? sanitize_text_field( wp_unslash( $_GET['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$locale = isset( $_GET['locale'] ) && is_string( $_GET['locale'] ) ? sanitize_text_field( wp_unslash( $_GET['locale'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$back   = array();
		foreach ( array( 's', 'babilim_locale' ) as $key ) {
			$value = self::field_get( $key );
			if ( '' !== $value ) {
				$back[ $key ] = $value;
			}
		}
		$tab = self::kind_tab( self::field_get( 'babilim_kind' ) );
		if ( '' !== $tab ) {
			$back['babilim_kind'] = $tab;
		}
		$component = $this->installed_component( $kind, $slug );
		$name      = null !== $component && '' !== (string) ( $component['name'] ?? '' ) ? (string) $component['name'] : $slug;
		$cancel    = '<a class="button" href="' . esc_url( self::page_url( 'translations', $back ) ) . '">' . esc_html__( 'Cancel', 'babilim' ) . '</a>';

		$html = '<div class="wrap"><h1>' . esc_html__( 'Confirm', 'babilim' ) . '</h1>' . $this->render_notices();
		if ( 'remove' === $op ) {
			$question = Babilim_Translations_Table::confirm_question( 'babilim_remove', $name, $locale );
			$button   = __( 'Remove', 'babilim' );
			$action   = 'babilim_remove';
		} elseif ( 'rollback' === $op ) {
			$question = Babilim_Translations_Table::confirm_question( 'babilim_rollback', $name, $locale );
			$button   = __( 'Roll back', 'babilim' );
			$action   = 'babilim_rollback';
		} else {
			return $html . '<p>' . esc_html__( 'Nothing to confirm.', 'babilim' ) . '</p><p>' . $cancel . '</p></div>';
		}

		$html .= '<p>' . esc_html( $question ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="confirmed" value="1" />'
			. '<input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />'
			. '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '" />'
			. '<input type="hidden" name="locale" value="' . esc_attr( $locale ) . '" />'
			. '<input type="hidden" name="s" value="' . esc_attr( $back['s'] ?? '' ) . '" />'
			. '<input type="hidden" name="babilim_locale" value="' . esc_attr( $back['babilim_locale'] ?? '' ) . '" />'
			. '<input type="hidden" name="babilim_kind" value="' . esc_attr( $back['babilim_kind'] ?? '' ) . '" />'
			. wp_nonce_field( $action, '_wpnonce', true, false )
			. '<button type="submit" class="button button-primary">' . esc_html( $button ) . '</button> ' . $cancel
			. '</form></div>';
		return $html;
	}

	/**
	 * The language picker a multi-language row action opens: one button
	 * per language that can take the action, each sending the same POST
	 * (or opening the same report page) the single-language row button
	 * does, and Cancel back to the list.
	 *
	 * @return string
	 */
	private function render_choose(): string {
		$op   = self::field_get( 'op' );
		$kind = sanitize_key( self::field_get( 'kind' ) );
		$slug = self::field_get( 'slug' );
		$back = array();
		foreach ( array( 's', 'babilim_locale' ) as $key ) {
			if ( '' !== self::field_get( $key ) ) {
				$back[ $key ] = self::field_get( $key );
			}
		}
		$tab = self::kind_tab( self::field_get( 'babilim_kind' ) );
		if ( '' !== $tab ) {
			$back['babilim_kind'] = $tab;
		}
		$view = self::field_get( 'view' );
		if ( in_array( $view, array( 'available', 'updates', 'requestable', 'installed' ), true ) ) {
			$back['view'] = $view;
		}
		$cancel = '<a class="button" href="' . esc_url( self::page_url( 'translations', $back ) ) . '">' . esc_html__( 'Cancel', 'babilim' ) . '</a>';
		$html   = '<div class="wrap"><h1>' . esc_html__( 'Choose a language', 'babilim' ) . '</h1>' . $this->render_notices();

		$found = null;
		foreach ( $this->list_items( $this->state()->get() ) as $item ) {
			if ( $item['kind'] === $kind && $item['slug'] === $slug ) {
				$found = $item;
				break;
			}
		}
		$labels = array(
			'rollback'        => __( 'Roll back', 'babilim' ),
			'remove'          => __( 'Remove', 'babilim' ),
			'rollback-report' => __( 'Roll back and report', 'babilim' ),
			'report'          => __( 'Report a problem', 'babilim' ),
		);
		$cells  = null === $found || ! isset( $labels[ $op ] ) ? array() : Babilim_Translations_Table::row_targets( $found )[ $op ];
		if ( null === $found || array() === $cells ) {
			return $html . '<p>' . esc_html__( 'Nothing to choose.', 'babilim' ) . '</p><p>' . $cancel . '</p></div>';
		}

		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: action name such as Remove, 2: component name. */
				__( '%1$s: %2$s. Choose the language.', 'babilim' ),
				$labels[ $op ],
				(string) $found['name']
			)
		) . '</p><ul class="babilim-choose">';
		foreach ( $cells as $locale => $cell ) {
			$locale   = (string) $locale;
			$name     = Babilim_Locale_Label::name( $locale );
			$identity = array( 'kind' => $kind, 'slug' => $slug, 'locale' => $locale );
			if ( 'rollback' === $op || 'remove' === $op ) {
				$action = 'rollback' === $op ? 'babilim_rollback' : 'babilim_remove';
				$html  .= '<li><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
					. Babilim_Translations_Table::post_fields( $action, $identity, $back )
					. '<button type="submit" class="button" title="' . esc_attr( $locale ) . '" data-babilim-confirm="' . esc_attr( Babilim_Translations_Table::confirm_question( $action, (string) $found['name'], $locale ) ) . '" data-babilim-confirmed="1">' . esc_html( $name ) . '</button></form></li>';
				continue;
			}
			$args  = array( 'page' => self::MENU_SLUG, 'screen' => 'report' ) + $identity + array( 'build_id' => (string) $cell['title'] );
			$args += 'rollback-report' === $op ? array( 'rollback' => '1' ) : array();
			$html .= '<li><a class="button" href="' . esc_url( admin_url( 'admin.php?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) ) ) . '" title="' . esc_attr( $locale ) . '">' . esc_html( $name ) . '</a></li>';
		}
		return $html . '</ul><p>' . $cancel . '</p></div>';
	}

	/**
	 * One row per (component, language) that contributes to, or was
	 * left out of, a language's merged `default` supplement.
	 *
	 * @return array[] Rows {slug, locale, included, entries, reason, conflict_count}.
	 */
	private function supplementary_rows(): array {
		$rows = array();
		if ( ! $this->manifest()->is_available() ) {
			return $rows;
		}
		$locales = $this->manifest()->default_index_locales();
		sort( $locales, SORT_STRING );
		foreach ( $locales as $locale ) {
			$meta = $this->manifest()->default_index( (string) $locale );
			if ( null === $meta ) {
				continue;
			}
			$seen = array();
			foreach ( array( 'included', 'excluded' ) as $list ) {
				foreach ( (array) ( $meta[ $list ] ?? array() ) as $entry ) {
					$slug = (string) ( $entry['slug'] ?? '' );
					if ( '' === $slug || isset( $seen[ $slug ] ) ) {
						continue;
					}
					$seen[ $slug ] = true;
					$conflicts     = 0;
					foreach ( (array) ( $meta['conflicts'] ?? array() ) as $conflict ) {
						if ( in_array( $slug, (array) ( $conflict['slugs'] ?? array() ), true ) ) {
							++$conflicts;
						}
					}
					$rows[] = array(
						'slug'           => $slug,
						'locale'         => (string) $locale,
						'included'       => 'included' === $list,
						'entries'        => (int) ( $entry['entries'] ?? 0 ),
						'reason'         => 'included' === $list ? '' : (string) ( $entry['reason'] ?? '' ),
						'conflict_count' => $conflicts,
					);
				}
			}
		}
		return $rows;
	}

	/**
	 * A stored time (ISO 8601 string or Unix timestamp) in the site's own
	 * timezone and date/time format. A value that can't be read as a time
	 * is returned unchanged.
	 *
	 * @param string|int $value ISO 8601 string or Unix timestamp.
	 * @return string
	 */
	private static function format_time( $value ): string {
		$raw = (string) $value;
		if ( is_int( $value ) ) {
			$ts = $value;
		} else {
			$ts = '' === trim( $raw ) ? false : strtotime( $raw );
		}
		if ( false === $ts ) {
			return $raw;
		}
		$format = trim( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
		$out    = wp_date( $format, $ts );
		return is_string( $out ) && '' !== $out ? $out : $raw;
	}

	/**
	 * Component slug => display name, from the local inventory only.
	 *
	 * @return array<string,string>
	 */
	private function component_names(): array {
		$names = array();
		foreach ( $this->inventory()->components() as $component ) {
			$slug = (string) ( $component['slug'] ?? '' );
			$name = trim( (string) ( $component['name'] ?? '' ) );
			if ( '' !== $slug && '' !== $name && ! isset( $names[ $slug ] ) ) {
				$names[ $slug ] = $name;
			}
		}
		return $names;
	}

	/**
	 * The escaped component cell content: the name with the slug as its
	 * title, or just the slug when no name is known.
	 *
	 * @param string               $slug  Component slug.
	 * @param array<string,string> $names From component_names().
	 * @return string
	 */
	private function component_name_html( string $slug, array $names ): string {
		if ( ! isset( $names[ $slug ] ) ) {
			return esc_html( $slug );
		}
		return '<span title="' . esc_attr( $slug ) . '">' . esc_html( $names[ $slug ] ) . '</span>';
	}

	/**
	 * The escaped language cell content: the language name with the code
	 * as its title.
	 *
	 * @param string $locale Language code.
	 * @return string
	 */
	private function locale_name_html( string $locale ): string {
		return '<span title="' . esc_attr( $locale ) . '">' . esc_html( Babilim_Locale_Label::name( $locale ) ) . '</span>';
	}

	/**
	 * The "Supplementary translations" table of the Advanced tab.
	 *
	 * @return string
	 */
	private function render_supplementary_rows_table(): string {
		$rows = $this->supplementary_rows();
		if ( array() === $rows ) {
			return '';
		}
		$names = $this->component_names();
		$html  = '<h2>' . esc_html__( 'Supplementary translations', 'babilim' ) . '</h2>';
		$html .= '<p class="description">' . esc_html__( "Each component's own contribution to each locale's shared default-domain supplement.", 'babilim' ) . '</p>';
		$html .= '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>' . esc_html__( 'Component', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Language', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Included in index', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Entries', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Conflicting keys', 'babilim' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$included = $row['included'] ? esc_html__( 'Yes', 'babilim' ) : esc_html( self::exclusion_reason_label( $row['reason'] ) );
			$html    .= '<tr><td>' . $this->component_name_html( $row['slug'], $names ) . '</td><td>' . $this->locale_name_html( $row['locale'] ) . '</td>'
				. '<td>' . $included . '</td>'
				. '<td>' . ( $row['included'] ? (int) $row['entries'] : '—' ) . '</td>'
				. '<td>' . (int) $row['conflict_count'] . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * A translatable label for one classify_records() exclusion reason
	 * (`inactive`/`version_mismatch`/`orphaned`) -- never a raw internal
	 * code shown verbatim.
	 */
	private static function exclusion_reason_label( string $reason ): string {
		switch ( $reason ) {
			case 'inactive':
				return __( 'No — component inactive', 'babilim' );
			case 'version_mismatch':
				return __( 'No — installed version differs from the active one', 'babilim' );
			case 'orphaned':
				return __( 'No — component removed', 'babilim' );
			default:
				return __( 'No', 'babilim' );
		}
	}

	/**
	 * The explanation shown in place of a hidden network-wide-write
	 * button (download/rollback/remove/rebuild/sweep/purge) -- shown
	 * only on multisite, only to a site-level admin who lacks
	 * `manage_network_options`.
	 *
	 * @return string
	 */
	public static function network_wide_action_notice(): string {
		return '<span class="description">' . esc_html( self::network_wide_action_text() ) . '</span>';
	}

	/**
	 * The plain text of network_wide_action_notice().
	 *
	 * @return string
	 */
	public static function network_wide_action_text(): string {
		return __( 'Only a network administrator can do this, because it changes translation files shared by every site on this network.', 'babilim' );
	}

	/**
	 * The verification-stalled/token-conflict recovery notice: a clear
	 * message and one button that restarts registration (see
	 * Babilim_Site::restart_registration()) -- shown wherever it's
	 * relevant, not just on the settings screen.
	 */
	private function render_recovery_notice(): string {
		$stalled  = $this->site()->verification_stalled();
		$conflict = $this->site()->needs_token_rotation();
		if ( ! $stalled && ! $conflict ) {
			return '';
		}
		$message = $stalled
			? __( 'Babilim could not finish verifying this site (the service could not reach it back). Registration is paused.', 'babilim' )
			: __( 'This site already has an active registration that can no longer be recovered automatically.', 'babilim' );

		$html  = '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="babilim_restart_registration" />';
		$html .= '<input type="hidden" name="tab" value="' . esc_attr( self::current_settings_tab() ) . '" />';
		$html .= wp_nonce_field( 'babilim_restart_registration', '_wpnonce', true, false );
		$html .= '<button type="submit" class="button button-primary">' . esc_html__( 'Restart registration', 'babilim' ) . '</button>';
		$html .= '</form></div>';
		return $html;
	}

	/**
	 * The settings tab the request asks for: `advanced`, or `general`
	 * for anything else.
	 *
	 * @return string
	 */
	private static function current_settings_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, not a state change.
		return 'advanced' === $tab ? 'advanced' : 'general';
	}

	/**
	 * The settings screen: two tabs. "General" is one form holding the
	 * managed languages, the uninstall choices and the account block;
	 * "Advanced" holds the maintenance buttons. A value other than
	 * `advanced` is the General tab.
	 *
	 * @return string
	 */
	private function render_settings(): string {
		$tab = self::current_settings_tab();

		$html  = '<div class="wrap"><h1>' . esc_html__( 'Babilim settings', 'babilim' ) . '</h1>';
		$html .= $this->render_notices();
		$html .= $this->render_verification_warning();
		$html .= $this->render_recovery_notice();

		$html .= '<h2 class="nav-tab-wrapper">';
		foreach ( array(
			'general'  => __( 'General', 'babilim' ),
			'advanced' => __( 'Advanced', 'babilim' ),
		) as $key => $label ) {
			$html .= '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::page_url( 'settings', array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</h2>';

		$html .= 'advanced' === $tab ? $this->render_settings_advanced() : $this->render_settings_general();
		$html .= '</div>';
		return $html;
	}

	/**
	 * The General tab: one settings form, then the Disable block with
	 * its own form (forms cannot be nested).
	 *
	 * @return string
	 */
	private function render_settings_general(): string {
		$granted = $this->consent()->granted_db();

		$html  = '<form id="babilim-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="babilim_save_settings" />';
		$html .= wp_nonce_field( 'babilim_save_settings', '_wpnonce', true, false );
		$html .= '<table class="form-table" role="presentation"><tbody>';

		$html .= '<tr><th scope="row">' . esc_html__( 'Languages', 'babilim' ) . '</th><td>' . $this->render_managed_locales() . '</td></tr>';

		$html .= '<tr><th scope="row">' . esc_html__( 'Automatic installs', 'babilim' ) . '</th><td>'
			. '<label><input type="checkbox" name="auto_install" ' . checked( (bool) get_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALL, true ), true, false ) . ' /> '
			. esc_html__( 'Install available translations automatically', 'babilim' ) . '</label>'
			. '<p class="description">' . esc_html__( 'On the free plan, only components that already use one of your free slots are installed automatically, so no new slot is taken.', 'babilim' ) . '</p>'
			. ( is_multisite() ? '<p class="description">' . esc_html__( 'This site is part of a multisite network, which does not install new translations automatically. Translations that are already installed are still updated.', 'babilim' ) . '</p>' : '' )
			. '</td></tr>';

		$html .= '<tr><th scope="row">' . esc_html__( 'Custom overrides', 'babilim' ) . '</th><td>'
			. '<label><input type="checkbox" name="keep_custom" ' . checked( self::keep_custom_on_purge(), true, false ) . ' /> '
			. esc_html__( 'Keep my own translation overrides when purging everything or uninstalling this plugin.', 'babilim' ) . '</label>'
			. '<p class="description">' . sprintf(
				/* translators: %s: folder path, shown as code. */
				esc_html__( "Translation files you put in %s always take priority over Babilim's. They are kept unless you uncheck this.", 'babilim' ),
				'<code>' . esc_html( self::custom_dir_label() ) . '</code>'
			) . '</p></td></tr>';

		$html .= '<tr><th scope="row">' . esc_html__( 'Uninstall', 'babilim' ) . '</th><td>'
			. '<label><input type="checkbox" name="delete_data_on_uninstall" ' . checked( self::delete_data_on_uninstall(), true, false ) . ' /> '
			. esc_html__( 'Delete all Babilim data on uninstall', 'babilim' ) . '</label>'
			. '<p class="description">' . esc_html__( 'Off by default: deleting the plugin keeps this site\'s identity, so a reinstall can reuse it.', 'babilim' ) . '</p>'
			. '<p class="description">' . esc_html( self::delete_data_risk_text() ) . '</p></td></tr>';

		$html .= '<tr><th scope="row">' . esc_html__( 'Account', 'babilim' ) . '</th><td>' . $this->render_account() . '</td></tr>';
		$html .= '</tbody></table>';

		$html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'babilim' ) . '</button></p></form>';

		if ( $granted ) {
			$html .= '<h2>' . esc_html__( 'Disable Babilim', 'babilim' ) . '</h2>'
				. '<p class="description">' . esc_html__( 'Installed translations are kept, but no further checks or downloads will run.', 'babilim' ) . '</p>'
				. '<form id="babilim-disable-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="babilim_disable" />'
				. wp_nonce_field( 'babilim_disable', '_wpnonce', true, false )
				. '<p><button type="submit" class="button button-link-delete" data-babilim-confirm="' . esc_attr__( 'Disable Babilim? Installed translations are kept, but no further checks or downloads will run.', 'babilim' ) . '">' . esc_html__( 'Disable Babilim', 'babilim' ) . '</button></p>'
				. '</form>';
		}
		return $html . $this->render_checkout_form() . $this->render_restore_forms();
	}

	/**
	 * Whether the one-time upgrade can be offered right now: consent
	 * given, a registered and verified site, still on the free license,
	 * and a price the service reported on its last answer. Whether to
	 * offer is the service's call (it sends the price only when it
	 * offers checkout); nothing is decided here.
	 *
	 * @return bool
	 */
	private function checkout_offered(): bool {
		if ( ! $this->consent()->granted_db() || null === $this->site()->identity() || null !== $this->site()->verification_warning() ) {
			return false;
		}
		$state = $this->state()->get();
		if ( 'free' !== (string) ( ( (array) ( $state['license'] ?? array() ) )['kind'] ?? '' ) ) {
			return false;
		}
		$price = $state['price'] ?? null;
		return is_array( $price ) && isset( $price['amount'], $price['currency'] );
	}

	/**
	 * The checkout form, without a button: the Upgrade buttons elsewhere
	 * on the page point at it with their `form` attribute, so it can
	 * sit after the other forms instead of inside one. It opens in a
	 * new tab; the poll URL (a nonce-protected admin-ajax address) lets
	 * the script notice when the purchase went through. No token is
	 * ever printed.
	 *
	 * @return string
	 */
	private function render_checkout_form(): string {
		if ( ! $this->checkout_offered() ) {
			return '';
		}
		$poll_url = admin_url( 'admin-ajax.php?action=babilim_license_status&_ajax_nonce=' . wp_create_nonce( 'babilim_license_status' ) );
		return '<form id="babilim-checkout-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" target="_blank" rel="noopener" data-babilim-poll-url="' . esc_url( $poll_url ) . '">'
			. '<input type="hidden" name="action" value="babilim_checkout" />'
			. wp_nonce_field( 'babilim_checkout', '_wpnonce', true, false )
			. '</form>';
	}

	/**
	 * `admin_post_babilim_checkout`: asks the service for a checkout
	 * page and sends the browser there (the form opens a new tab).
	 */
	public function dispatch_babilim_checkout(): void {
		$this->guard( 'babilim_checkout' );
		$identity = $this->site()->identity();
		if ( null === $identity ) {
			$this->notice( 'not_registered', __( 'This site is not registered with Babilim yet.', 'babilim' ), 'error' );
			wp_safe_redirect( self::page_url( 'settings' ) );
			return;
		}
		$result = $this->api()->start_checkout( $identity['site_id'] );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'babilim_already_licensed' === $code ) {
				$message = __( 'This site is already licensed. Reload the page.', 'babilim' );
			} elseif ( 'babilim_checkout_unavailable' === $code ) {
				$message = __( 'Checkout is not available for this site right now.', 'babilim' );
			} else {
				/* translators: %s: the reason the service or the connection gave. */
				$message = sprintf( __( 'Could not start checkout: %s', 'babilim' ), $result->get_error_message() );
			}
			$this->notice( (string) $code, $message, 'error' );
			wp_safe_redirect( self::page_url( 'settings' ) );
			return;
		}
		$url = esc_url_raw( (string) ( $result['checkout_url'] ?? '' ) );
		if ( ! self::is_allowed_checkout_url( $url ) ) {
			$this->notice( 'checkout_unavailable', __( 'Checkout is not available for this site right now.', 'babilim' ), 'error' );
			wp_safe_redirect( self::page_url( 'settings' ) );
			return;
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the host was checked against the checkout allow-list above; it is the product website, not an allowed_redirect_hosts entry.
		// No exit(): admin-post.php ends the request once the action returns, like every other handler here.
	}

	/**
	 * Whether a checkout address from the service may be opened: https,
	 * no credentials, and a host that is babilim.org or a subdomain of
	 * it (or one a site owner added with the `babilim_checkout_hosts`
	 * filter, which lists bare domains and also covers their subdomains).
	 *
	 * @param string $url The address.
	 * @return bool
	 */
	private static function is_allowed_checkout_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		if ( '' === $host ) {
			return false;
		}
		$allowed = apply_filters( 'babilim_checkout_hosts', array( 'babilim.org' ) );
		foreach ( is_array( $allowed ) ? $allowed : array() as $domain ) {
			$domain = strtolower( trim( (string) $domain, '. ' ) );
			if ( '' !== $domain && ( $host === $domain || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * `wp_ajax_babilim_license_status`: what license kind the service
	 * reports for this site right now. Once it says `paid` the stored
	 * license is replaced and one sync refreshes the cells. A service
	 * error is passed on and nothing stored is touched.
	 */
	public function ajax_babilim_license_status(): void {
		check_ajax_referer( 'babilim_license_status' );
		if ( ! self::can() ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
		$identity = $this->site()->identity();
		if ( null === $identity ) {
			wp_send_json_error( array( 'code' => 'not_registered' ) );
		}
		$result = $this->api()->get_site( $identity['site_id'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'code' => $result->get_error_code() ) );
		}
		$license = is_array( $result['license'] ?? null ) ? $result['license'] : array();
		$kind    = (string) ( $license['kind'] ?? '' );
		$state   = $this->state()->get();
		$stored  = (string) ( ( (array) ( $state['license'] ?? array() ) )['kind'] ?? '' );
		if ( 'paid' === $kind && $kind !== $stored ) {
			$this->state()->set( array_merge( $state, array( 'license' => $license ) ) );
			$this->site()->sync( Babilim_Site::RESUME_BUDGET_SECONDS );
		}
		wp_send_json_success( array( 'kind' => $kind ) );
	}

	/**
	 * The Account cell: whether the site is verified (three states read
	 * from the stored identity and verification warning), the free quota
	 * and the license kind. Nothing here is decided client-side.
	 *
	 * @return string
	 */
	private function render_account(): string {
		if ( ! $this->consent()->granted_db() ) {
			return '<p>' . esc_html__( 'Babilim is not enabled.', 'babilim' ) . '</p>';
		}
		if ( null === $this->site()->identity() ) {
			$status = __( 'Not registered yet', 'babilim' );
		} elseif ( null !== $this->site()->verification_warning() || $this->site()->verification_stalled() || $this->site()->needs_token_rotation() ) {
			$status = __( 'Not verified', 'babilim' );
		} else {
			$status = __( 'Verified', 'babilim' );
		}

		$state   = $this->state()->get();
		$quota   = (array) ( $state['quota'] ?? array() );
		$license = (array) ( $state['license'] ?? array() );

		$html  = '<ul class="babilim-account">';
		$html .= '<li>' . esc_html__( 'Status', 'babilim' ) . ': ' . esc_html( $status ) . '</li>';
		$html .= $this->render_quota( $quota, 'free' === (string) ( $license['kind'] ?? '' ) );
		if ( isset( $license['kind'] ) ) {
			$kind = (string) $license['kind'];
			if ( 'paid' === $kind ) {
				$kind_label = __( 'Licensed', 'babilim' );
			} elseif ( 'partner' === $kind ) {
				$kind_label = __( 'Partner', 'babilim' );
			} elseif ( 'free' === $kind ) {
				$kind_label = __( 'Free', 'babilim' );
			} else {
				$kind_label = $kind;
			}
			$html .= '<li>' . esc_html__( 'License', 'babilim' ) . ': ' . esc_html( $kind_label );
			if ( $this->checkout_offered() ) {
				$price = (array) $state['price'];
				$html .= ' <button type="submit" form="babilim-checkout-form" class="button button-primary">' . esc_html__( 'Upgrade this site', 'babilim' ) . '</button>'
					. ' <span class="description">' . esc_html(
						sprintf(
							/* translators: 1: currency code, 2: price amount. */
							__( 'One-time purchase for this site: %1$s %2$s. Includes all future translation updates. Payment is handled by Paddle in a new tab.', 'babilim' ),
							(string) $price['currency'],
							(string) $price['amount']
						)
					) . '</span>';
			}
			$html .= '</li>';
		}
		$restore = $this->restore_mode();
		if ( '' !== $restore ) {
			$html .= '<li>' . $this->render_restore_controls( 'paid' === $restore ) . '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * Which restore control the Account row shows: 'free' (code input
	 * plus Restore purchase; also before the first sync, when no license
	 * is known), 'paid' (Show a new restore code), or '' (nothing: other
	 * kinds such as partner, or no consent / no registered site).
	 *
	 * @return string 'free', 'paid' or ''.
	 */
	private function restore_mode(): string {
		if ( ! $this->restore_offered() ) {
			return '';
		}
		$kind = (string) ( ( (array) ( $this->state()->get()['license'] ?? array() ) )['kind'] ?? '' );
		if ( 'paid' === $kind ) {
			return 'paid';
		}
		return ( 'free' === $kind || '' === $kind ) ? 'free' : '';
	}

	/**
	 * Whether the restore controls are shown: consent given and a
	 * registered site. The controls sit in the Account row; their forms
	 * come after the settings form (see render_restore_forms()).
	 *
	 * @return bool
	 */
	private function restore_offered(): bool {
		return $this->consent()->granted_db() && null !== $this->site()->identity();
	}

	/**
	 * The restore controls for the Account row. A site that is not
	 * licensed gets the restore-code input and button; a licensed one
	 * gets the "Show a new restore code" button. All of them point at
	 * their form with the `form` attribute, so nothing nests.
	 *
	 * @param bool $paid Whether the site is licensed.
	 * @return string
	 */
	private function render_restore_controls( bool $paid ): string {
		if ( $paid ) {
			return '<button type="submit" form="babilim-restore-code-form" class="button" data-babilim-confirm="' . esc_attr__( 'This issues a new restore code and the previous one stops working. Continue?', 'babilim' ) . '">' . esc_html__( 'Show a new restore code', 'babilim' ) . '</button>';
		}
		return '<input type="text" name="restore_code" form="babilim-restore-form" required placeholder="' . esc_attr__( 'rc_…', 'babilim' ) . '" aria-label="' . esc_attr__( 'Restore code', 'babilim' ) . '" />'
			. ' <button type="submit" form="babilim-restore-form" class="button">' . esc_html__( 'Restore purchase', 'babilim' ) . '</button>'
			. '<p class="description">' . esc_html__( 'Bought a license for this domain before? Enter the restore code you received at checkout.', 'babilim' ) . '</p>';
	}

	/**
	 * The empty form the restore controls submit through (their inputs
	 * point at it with a `form` attribute), placed after the settings
	 * form: one restore-code form for a paid license, one restore form
	 * for a free one, none for a partner.
	 *
	 * @return string
	 */
	private function render_restore_forms(): string {
		$mode = $this->restore_mode();
		if ( '' === $mode ) {
			return '';
		}
		$forms = 'paid' === $mode ? array( 'babilim_restore_code' => 'babilim-restore-code-form' ) : array( 'babilim_restore' => 'babilim-restore-form' );
		$html  = '';
		foreach ( $forms as $action => $id ) {
			$html .= '<form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
				. wp_nonce_field( $action, '_wpnonce', true, false )
				. '</form>';
		}
		return $html;
	}

	/**
	 * `admin_post_babilim_restore`: attaches an earlier purchase to this
	 * site with the restore code the owner types in. The code is only
	 * checked for its shape, sent, and forgotten.
	 */
	public function dispatch_babilim_restore(): void {
		$this->guard( 'babilim_restore' );
		$code = trim( self::field( 'restore_code' ) );
		if ( 1 !== preg_match( '/^rc_[0-9a-f]{32}$/D', $code ) ) {
			$this->notice( 'invalid_restore_code', __( 'That does not look like a restore code.', 'babilim' ), 'error' );
			wp_safe_redirect( self::page_url( 'settings' ) );
			return;
		}
		$identity = $this->site()->identity();
		if ( null === $identity ) {
			$this->notice( 'not_registered', __( 'This site is not registered with Babilim yet.', 'babilim' ), 'error' );
			wp_safe_redirect( self::page_url( 'settings' ) );
			return;
		}
		$result = $this->api()->restore_purchase( $identity['site_id'], $code );
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_code();
			if ( 'babilim_not_found' === $error ) {
				$message = __( 'This restore code was not recognised for this site.', 'babilim' );
			} elseif ( 'babilim_rate_limited' === $error ) {
				$message = __( 'Too many attempts. Try again tomorrow.', 'babilim' );
			} elseif ( 'babilim_verification_required' === $error ) {
				$message = __( 'This site must be verified first.', 'babilim' );
			} else {
				/* translators: %s: the reason the service or the connection gave. */
				$message = sprintf( __( 'Could not restore the purchase: %s', 'babilim' ), $result->get_error_message() );
			}
			$this->notice( (string) $error, $message, 'error' );
			wp_safe_redirect( self::page_url( 'settings' ) );
			return;
		}
		$license = is_array( $result['license'] ?? null ) ? $result['license'] : array();
		$state   = $this->state()->get();
		$this->state()->set( array_merge( $state, array( 'license' => $license ) ) );
		$this->site()->sync( Babilim_Site::RESUME_BUDGET_SECONDS );
		$this->notice( 'restored', __( 'Purchase restored. This site is now licensed.', 'babilim' ), 'updated' );
		wp_safe_redirect( self::page_url( 'settings' ) );
	}

	/**
	 * `admin_post_babilim_restore_code`: issues a fresh restore code and
	 * shows it in one notice that only the admin who asked will see, once.
	 * It is never logged and never kept in an option; for the redirect it
	 * is parked for at most a minute in a transient keyed by user id, so
	 * no other admin opening a Babilim page meanwhile can read it.
	 */
	public function dispatch_babilim_restore_code(): void {
		$this->guard( 'babilim_restore_code' );
		$identity = $this->site()->identity();
		if ( null === $identity ) {
			$this->notice( 'not_registered', __( 'This site is not registered with Babilim yet.', 'babilim' ), 'error' );
			wp_safe_redirect( self::page_url( 'settings' ) );
			return;
		}
		$result = $this->api()->new_restore_code( $identity['site_id'] );
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_code();
			if ( 'babilim_no_purchase' === $error ) {
				$message = __( 'This site has no purchase to show a code for.', 'babilim' );
			} else {
				/* translators: %s: the reason the service or the connection gave. */
				$message = sprintf( __( 'Could not show a new restore code: %s', 'babilim' ), $result->get_error_message() );
			}
			$this->notice( (string) $error, $message, 'error' );
			wp_safe_redirect( self::page_url( 'settings' ) );
			return;
		}
		set_transient(
			self::restore_code_notice_key(),
			array(
				'code'    => 'new_restore_code',
				/* translators: %s: the restore code. */
				'message' => sprintf( __( 'Your new restore code: %s. Keep it somewhere safe — the previous code no longer works.', 'babilim' ), (string) ( $result['restore_code'] ?? '' ) ),
				'type'    => 'updated',
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::page_url( 'settings' ) );
	}

	/**
	 * The Advanced tab: the supplementary-index status, "Check again
	 * now", the index and sweep buttons, and the danger zone.
	 *
	 * @return string
	 */
	private function render_settings_advanced(): string {
		$html  = '<details class="babilim-advanced-first"><summary>' . esc_html__( 'What is sent when Babilim checks this site', 'babilim' ) . '</summary>' . $this->render_payload_list() . '</details>';
		$html .= $this->render_supplementary_rows_table();
		$html .= $this->render_default_index_table();
		$html .= $this->render_sweep_status();
		$html .= $this->render_maintenance_buttons();
		$html .= $this->render_danger_zone();
		return $html;
	}

	/**
	 * The managed-languages control: a checkbox per language the site
	 * manages (detected ones marked, unchecked when stopped) and a menu
	 * to add another. The menu is the service's locale list; nothing is
	 * requested before consent, so before it the menu is left out.
	 *
	 * @return string
	 */
	private function render_managed_locales(): string {
		$inventory = $this->inventory();
		$detected  = $inventory->detected_locales();
		$manual    = $inventory->manual_locales();
		$disabled  = $inventory->disabled_locales();
		$rows      = Babilim_Locale_Label::sorted( array_merge( $detected, $manual ) );

		$html = '';
		foreach ( $rows as $locale ) {
			$is_detected = in_array( $locale, $detected, true );
			$checked     = ! ( $is_detected && in_array( $locale, $disabled, true ) );
			$html       .= '<label class="babilim-inline-block babilim-mr-1em"><input type="checkbox" name="locales[]" value="' . esc_attr( $locale ) . '" ' . checked( $checked, true, false ) . ' /> '
				. '<span title="' . esc_attr( $locale ) . '">' . esc_html( Babilim_Locale_Label::name( $locale ) ) . '</span>' . ( $is_detected ? ' ' . esc_html__( '(detected)', 'babilim' ) : '' ) . '</label>';
		}
		if ( '' === $html ) {
			$html = '<p class="description">' . esc_html__( 'No language is managed yet.', 'babilim' ) . '</p>';
		}
		$html .= '<p class="description">' . esc_html__( 'Babilim adds translations for every language checked here.', 'babilim' ) . '</p>';

		$catalog = $this->locale_catalog();
		if ( null !== $catalog ) {
			$options = Babilim_Locale_Label::sorted( array_diff( $catalog, $rows ) );
			$html .= '<p><label for="babilim-new-locale">' . esc_html__( 'Add another language', 'babilim' ) . '</label> '
				. '<select id="babilim-new-locale" name="new_locale"><option value="">' . esc_html__( 'Choose a language…', 'babilim' ) . '</option>';
			foreach ( $options as $locale ) {
				$html .= '<option value="' . esc_attr( $locale ) . '" title="' . esc_attr( $locale ) . '">' . esc_html( Babilim_Locale_Label::name( $locale ) ) . '</option>';
			}
			$html .= '</select></p>';
		} elseif ( $this->consent()->granted_db() ) {
			$html .= '<p class="description">' . esc_html__( 'Could not load the full WordPress.org locale list from the service, so no other language can be added right now.', 'babilim' ) . '</p>';
		}
		return $html;
	}

	/**
	 * The service's locale list (`GET /v1/meta/locales`), cached for
	 * a while; null before consent (nothing is requested then) or when
	 * it cannot be read. A failed call is not cached.
	 *
	 * @return string[]|null
	 */
	private function locale_catalog(): ?array {
		if ( ! $this->consent()->granted_db() ) {
			return null;
		}
		$cached = get_transient( self::META_LOCALES_CACHE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return array_values( array_map( 'strval', $cached ) );
		}
		$response = $this->api()->meta_locales();
		if ( is_wp_error( $response ) ) {
			return null;
		}
		Babilim_Locale_Label::remember( $response );
		$catalog = array();
		foreach ( $response as $entry ) {
			$wp_locale = (string) ( is_array( $entry ) ? ( $entry['wp_locale'] ?? '' ) : '' );
			if ( '' !== $wp_locale ) {
				$catalog[] = $wp_locale;
			}
		}
		set_transient( self::META_LOCALES_CACHE_TRANSIENT, $catalog, self::META_LOCALES_CACHE_TTL );
		return $catalog;
	}

	/**
	 * `$quota` (common.schema.json's `#/$defs/quota` shape:
	 * `{free_slots: {theme, plugin}, downloads}`, each a `{used, limit}`
	 * pair, `downloads` also carrying `period`) rendered in plain words --
	 * "theme x/limit · plugin y/limit" -- never a raw JSON dump of
	 * whatever the response happened to contain.
	 *
	 * @param array $quota     Last-synced quota, or empty before the first sync.
	 * @param bool  $show_free Whether to show the Free quota row (free licenses only).
	 * @return string `<li>` rows.
	 */
	private function render_quota( array $quota, bool $show_free ): string {
		$html       = '';
		$free_slots = (array) ( $quota['free_slots'] ?? array() );
		if ( $show_free && ( isset( $free_slots['theme'] ) || isset( $free_slots['plugin'] ) ) ) {
			$parts = array();
			foreach ( array( 'theme' => __( 'Theme', 'babilim' ), 'plugin' => __( 'Plugin', 'babilim' ) ) as $key => $label ) {
				$slot = (array) ( $free_slots[ $key ] ?? array() );
				if ( isset( $slot['used'] ) && isset( $slot['limit'] ) ) {
					$parts[] = $label . ' ' . (int) $slot['used'] . '/' . (int) $slot['limit'];
				}
			}
			if ( ! empty( $parts ) ) {
				$html .= '<li>' . esc_html__( 'Free quota', 'babilim' ) . ': ' . esc_html( implode( ' · ', $parts ) ) . '</li>';
			}
		}
		$downloads = (array) ( $quota['downloads'] ?? array() );
		if ( isset( $downloads['used'] ) && isset( $downloads['limit'] ) ) {
			$html .= '<li>' . sprintf(
				/* translators: 1: downloads used, 2: downloads limit, 3: the period they reset over, e.g. "daily". */
				esc_html__( 'Downloads: %1$d/%2$d (%3$s)', 'babilim' ),
				(int) $downloads['used'],
				(int) $downloads['limit'],
				esc_html( (string) ( $downloads['period'] ?? '' ) )
			) . '</li>';
		}
		return $html;
	}

	/**
	 * The sweep guard (mass-orphan brake trip) and the last sweep's own
	 * summary, in plain words -- see Babilim_Manifest::sweep_guard()/
	 * sweep_summary().
	 */
	/**
	 * The "Supplementary translation index" table: one
	 * row per locale this site has a merged `default` domain index (or a
	 * record of one) for -- when it was last built, how many components
	 * were included vs. excluded, how many conflicting keys it dropped,
	 * and how many entries WordPress core's own `default` translation
	 * already covered.
	 */
	private function render_default_index_table(): string {
		if ( ! $this->manifest()->is_available() ) {
			return '';
		}
		$locales = $this->manifest()->default_index_locales();
		if ( empty( $locales ) ) {
			return '';
		}
		sort( $locales, SORT_STRING );

		$html  = '<h2>' . esc_html__( 'Supplementary translation index', 'babilim' ) . '</h2>';
		$html .= '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>' . esc_html__( 'Language', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Built', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Included', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Excluded', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Conflicting keys', 'babilim' ) . '</th>'
			. '<th>' . esc_html__( 'Already in WordPress core', 'babilim' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $locales as $locale ) {
			$meta = $this->manifest()->default_index( (string) $locale );
			if ( null === $meta ) {
				continue;
			}
			$html .= '<tr><td>' . $this->locale_name_html( (string) $locale ) . '</td>'
				. '<td>' . esc_html( isset( $meta['built_at'] ) ? self::format_time( (string) $meta['built_at'] ) : '—' ) . '</td>'
				. '<td>' . (int) count( (array) ( $meta['included'] ?? array() ) ) . '</td>'
				. '<td>' . (int) count( (array) ( $meta['excluded'] ?? array() ) ) . '</td>'
				. '<td>' . (int) count( (array) ( $meta['conflicts'] ?? array() ) ) . '</td>'
				. '<td>' . (int) ( $meta['core_dupes'] ?? 0 ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}

	private function render_sweep_status(): string {
		$html = '<h2>' . esc_html__( 'Supplementary translation maintenance', 'babilim' ) . '</h2>';

		if ( ! $this->manifest()->is_available() ) {
			$html .= '<p>' . esc_html__( 'Maintenance status is not available right now.', 'babilim' ) . '</p>';
			return $html;
		}

		$guard = $this->manifest()->sweep_guard();
		if ( null !== $guard ) {
			$html .= '<p>' . sprintf(
				/* translators: 1: when the guard tripped, 2: why. */
				esc_html__( 'A cleanup was paused on %1$s: %2$s. Nothing was deleted.', 'babilim' ),
				esc_html( self::format_time( (string) ( $guard['tripped_at'] ?? '' ) ) ),
				esc_html( (string) ( $guard['reason'] ?? '' ) )
			) . '</p>';
		}

		$summary = $this->manifest()->sweep_summary();
		if ( null !== $summary ) {
			$html .= '<p>' . sprintf(
				/* translators: 1: when the last sweep ran, 2: files marked, 3: files deleted, 4: records purged. */
				esc_html__( 'Last cleanup on %1$s: %2$d newly marked, %3$d file(s) deleted, %4$d record(s) removed.', 'babilim' ),
				esc_html( self::format_time( (string) ( $summary['swept_at'] ?? '' ) ) ),
				(int) ( $summary['orphans_marked'] ?? 0 ),
				(int) ( $summary['orphans_deleted'] ?? 0 ),
				(int) ( $summary['records_purged'] ?? 0 )
			) . '</p>';
		} else {
			$html .= '<p>' . esc_html__( 'No cleanup has run yet.', 'babilim' ) . '</p>';
		}

		// A manifest write conflict is local-only -- never reported to
		// the service (see Babilim_Manifest::write_conflict()'s own
		// docblock for why) -- so the settings screen is the only place
		// a site owner ever sees one happened.
		$conflict = $this->manifest()->write_conflict();
		if ( null !== $conflict ) {
			$html .= '<p>' . sprintf(
				/* translators: 1: when the conflicting write happened, 2: which part of the index it was for. */
				esc_html__( 'A translation-index write conflicted with another request on %1$s (%2$s) and was not applied; the most recent version was kept.', 'babilim' ),
				esc_html( self::format_time( $conflict['at'] ) ),
				esc_html( $conflict['section'] . ( null !== $conflict['key'] && '' !== $conflict['key'] ? ': ' . $conflict['key'] : '' ) )
			) . '</p>';
		}

		// Likewise local-only: the most recent translation-index write that
		// did not land at all (see
		// Babilim_Manifest::last_recorded_write_failure()).
		$failure = $this->manifest()->last_recorded_write_failure();
		if ( null !== $failure ) {
			$html .= '<p>' . sprintf(
				/* translators: 1: when the write failed, 2: which part of the index it was for, 3: a short technical reason code. */
				esc_html__( 'A translation-index write on %1$s (%2$s) could not be completed and was not applied (%3$s).', 'babilim' ),
				esc_html( self::format_time( $failure['at'] ) ),
				esc_html( $failure['section'] . ( null !== $failure['key'] && '' !== $failure['key'] ? ': ' . $failure['key'] : '' ) ),
				esc_html( $failure['reason'] )
			) . '</p>';
		}

		return $html;
	}

	/**
	 * Manual "rebuild index"/"sweep now" trigger buttons -- both write
	 * to the network-wide shared `default` domain index, so on
	 * multisite they require network_capability() (see guard()).
	 */
	private function render_maintenance_buttons(): string {
		$html = '<h2>' . esc_html__( 'Maintenance', 'babilim' ) . '</h2>';
		if ( $this->consent()->granted_db() ) {
			$html .= $this->action_form( 'babilim_sync_now', __( 'Check again now', 'babilim' ), false, 'advanced' ) . ' ';
		}
		if ( ! self::can( true ) ) {
			return $html . self::network_wide_action_notice();
		}

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="babilim-inline-block babilim-mr-1em">';
		$html .= '<input type="hidden" name="action" value="babilim_rebuild_index" />';
		$html .= wp_nonce_field( 'babilim_rebuild_index', '_wpnonce', true, false );
		$html .= '<button type="submit" class="button">' . esc_html__( 'Rebuild index now', 'babilim' ) . '</button>';
		$html .= '</form>';

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="babilim-inline-block">';
		$html .= '<input type="hidden" name="action" value="babilim_sweep_now" />';
		$html .= wp_nonce_field( 'babilim_sweep_now', '_wpnonce', true, false );
		$html .= '<button type="submit" class="button">' . esc_html__( 'Sweep now', 'babilim' ) . '</button>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * "Remove everything Babilim installed" -- purge_all(), respecting
	 * the "Keep my own translation overrides" setting above. A separate button, not
	 * folded into the maintenance ones: this deletes every installed
	 * component's files/manifest records outright, not a routine
	 * reconciliation. Writes to the network-wide shared translation
	 * tree, so on multisite it requires network_capability() (see
	 * guard()).
	 */
	private function render_danger_zone(): string {
		$html = '<h2>' . esc_html__( 'Danger zone', 'babilim' ) . '</h2>';
		if ( ! self::can( true ) ) {
			$html .= '<p>' . self::network_wide_action_notice() . '</p>';
			return $html;
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="babilim_purge_all" />';
		$html .= wp_nonce_field( 'babilim_purge_all', '_wpnonce', true, false );
		$html .= '<p class="description">' . esc_html__( 'Removes every language file Babilim has installed for every plugin and theme on this site.', 'babilim' ) . '</p>';
		$html .= '<button type="submit" class="button" data-babilim-confirm="' . esc_attr__( 'Remove everything Babilim installed? This deletes every language file it installed for every plugin and theme on this site.', 'babilim' ) . '">' . esc_html__( 'Remove everything Babilim installed', 'babilim' ) . '</button>';
		$html .= '</form>';
		return $html;
	}

	/**
	 * The report (feedback) screen: a form pre-filled from the detail
	 * page's own string context (kind/slug/locale/build_id/msgid/
	 * msgctxt/plural_index, when given), the 8-value category dropdown,
	 * and an optional screen_path.
	 *
	 * @return string
	 */
	private function render_report(): string {
		$kind         = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug         = isset( $_GET['slug'] ) ? sanitize_text_field( wp_unslash( $_GET['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$locale       = isset( $_GET['locale'] ) ? sanitize_text_field( wp_unslash( $_GET['locale'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$build_id     = isset( $_GET['build_id'] ) ? sanitize_text_field( wp_unslash( $_GET['build_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Same rule as validate_feedback_request()'s own send side: msgid
		// and msgctxt must both survive exactly as the original strings
		// were (kept raw, never shortened -- sanitize_text_field()/
		// sanitize_textarea_field() would strip HTML and (the former)
		// collapse line breaks a real translation string can genuinely
		// contain), escaped only where actually rendered (the hidden-
		// field loop below, via esc_attr()). A non-string value (e.g.
		// an array, from `?msgid[]=x`) is rejected outright, never
		// coerced by a bare (string) cast (which would silently produce
		// the literal string "Array"). A value over the contract's limit
		// cannot be sent at all and cannot be cut (a cut string no longer
		// matches the original), so no form is rendered for it (below).
		$msgid        = self::raw_query_field( 'msgid' );
		$msgctxt      = self::raw_query_field( 'msgctxt' );
		$rollback     = '1' === ( isset( $_GET['rollback'] ) && is_string( $_GET['rollback'] ) ? sanitize_text_field( wp_unslash( $_GET['rollback'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plural_index = isset( $_GET['plural_index'] ) ? sanitize_text_field( wp_unslash( $_GET['plural_index'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html  = '<div class="wrap"><h1>' . esc_html__( 'Report a problem', 'babilim' ) . '</h1>';
		$html .= $this->render_notices();

		if ( ! Babilim_Contract_Limits::fits( $msgid, Babilim_Contract_Limits::FEEDBACK_TEXT ) || ! Babilim_Contract_Limits::fits( $msgctxt, Babilim_Contract_Limits::FEEDBACK_TEXT ) ) {
			$html .= '<p>' . esc_html__( 'This string is too long to be sent as a report, so no report form is offered for it.', 'babilim' ) . '</p></div>';
			return $html;
		}

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$submit_action = $rollback ? 'babilim_rollback_report' : 'babilim_feedback';
		$html         .= '<input type="hidden" name="action" value="' . esc_attr( $submit_action ) . '" />';
		$html         .= wp_nonce_field( $submit_action, '_wpnonce', true, false );
		// kind/slug/locale/build_id are every other required field of
		// `POST /v1/feedback` per the service's own contract -- marked
		// `required` here too so a form somehow reached without one (e.g.
		// navigated to directly, with no query string) fails browser-side
		// validation instead of silently 422ing the service.
		$required_fields = array( 'kind', 'slug', 'locale', 'build_id' );
		foreach ( array(
			'kind'         => $kind,
			'slug'         => $slug,
			'locale'       => $locale,
			'build_id'     => $build_id,
			'msgid'        => $msgid,
			'msgctxt'      => $msgctxt,
			'plural_index' => $plural_index,
		) as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"'
				. ( in_array( $name, $required_fields, true ) ? ' required="required"' : '' ) . ' />';
		}

		$html .= '<p><label for="babilim-category">' . esc_html__( 'Category', 'babilim' ) . '</label><br />';
		$html .= '<select id="babilim-category" name="category" required>';
		$html .= '<option value="" disabled selected>' . esc_html__( 'Choose a category…', 'babilim' ) . '</option>';
		foreach ( self::FEEDBACK_CATEGORIES as $category ) {
			$html .= '<option value="' . esc_attr( $category ) . '">' . esc_html( $category ) . '</option>';
		}
		$html .= '</select></p>';

		$html .= '<p><label for="babilim-current">' . esc_html__( 'Current translation', 'babilim' ) . '</label><br />'
			. '<textarea id="babilim-current" name="current_msgstr" rows="2" cols="60" maxlength="' . (int) Babilim_Contract_Limits::FEEDBACK_TEXT . '"></textarea></p>';
		$html .= '<p><label for="babilim-suggested">' . esc_html__( 'Suggested translation', 'babilim' ) . '</label><br />'
			. '<textarea id="babilim-suggested" name="suggested" rows="2" cols="60" maxlength="' . (int) Babilim_Contract_Limits::FEEDBACK_TEXT . '"></textarea></p>';
		$html .= '<p><label for="babilim-note">' . esc_html__( 'Note (optional)', 'babilim' ) . '</label><br />'
			. '<textarea id="babilim-note" name="note" rows="3" cols="60" maxlength="' . (int) Babilim_Contract_Limits::FEEDBACK_TEXT . '"></textarea></p>';
		$html .= '<p><label for="babilim-screen-path">' . esc_html__( 'Where you saw this (optional)', 'babilim' ) . '</label><br />'
			. '<input type="text" id="babilim-screen-path" name="screen_path" size="60" maxlength="' . (int) Babilim_Contract_Limits::SCREEN_PATH . '" /></p>';

		$html .= '<p><button type="submit" class="button button-primary">' . esc_html( $rollback ? __( 'Roll back and send report', 'babilim' ) : __( 'Send report', 'babilim' ) ) . '</button></p>';
		$html .= '</form></div>';

		return $html;
	}
}
