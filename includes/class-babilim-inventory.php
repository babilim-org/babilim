<?php
/**
 * Detects the plugins/themes installed on a site (and the locales in
 * play, site-wide) and projects that into the shape a request to the
 * Babilim service's inventory sync endpoint expects.
 *
 * Every WordPress data source this class needs is injected as a
 * callable rather than called directly, so it never needs a running
 * WordPress install to test:
 *
 * - $get_plugins()        -- shaped like core's own get_plugins(): an
 *                             array keyed by the plugin's relative file
 *                             path (e.g. "woocommerce/woocommerce.php"),
 *                             each value a header array that may contain
 *                             'Name', 'Version', 'TextDomain', 'DomainPath'.
 * - $get_themes()         -- an array keyed by stylesheet slug, each
 *                             value a WP_Theme-shaped object: anything
 *                             with a ->get($header) method (returning
 *                             false for an absent/empty header, matching
 *                             WP_Theme::get()) and a
 *                             ->get_stylesheet_directory() method.
 *                             ->get_template_directory() is also read
 *                             when present, for the child-theme case.
 * - $get_transient($name)  -- shaped like core's own get_transient(): the
 *                             named site transient's current value. Its
 *                             'response'/'no_update' buckets, and the
 *                             entries inside them, are each accepted as
 *                             either an array or an object: WordPress
 *                             core itself always delivers
 *                             update_plugins entries as stdClass and
 *                             update_themes entries as plain arrays
 *                             (including a theme using its own `Update
 *                             URI` header -- core casts that entry to
 *                             an array too, unlike a plugin's), but
 *                             this class doesn't rely on that split
 *                             holding forever, so it reads either shape
 *                             for either transient.
 * - $get_users_locales()   -- a flat array of every user's own locale
 *                             string (however the caller chooses to
 *                             gather it -- e.g. user meta).
 * - $list_dir($dir)        -- optional; lists a directory's entries (see
 *                             default_list_dir()). Injected so
 *                             components() never touches a real
 *                             filesystem in tests.
 * - $function_exists($name) -- optional; checks whether a function is
 *                             callable (used for a multilingual plugin's
 *                             own function). Defaults to PHP's own
 *                             function_exists(); injectable so a test
 *                             can simulate the function genuinely being
 *                             absent without needing a separate process.
 *
 * This class only ever reads -- it never writes a file.
 *
 * Two WordPress core functions this class calls directly (rather than
 * through an injected callable, since this class's constructor exists
 * to wrap the small set of WordPress data sources named above, not
 * every core function it happens to call) aren't stubbed by WordPress
 * core itself in tests -- get_locale() and get_available_languages().
 * Both are stubbed by a shared test fixture instead, loaded once for
 * every test file, so every test sees them regardless of which one
 * PHPUnit happens to load first.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site inventory: plugin/theme components, in-play locales, and the
 * registry-backed effective-file lookup.
 */
final class Babilim_Inventory {

	/**
	 * The option the settings screen's "Managed locales" control stores
	 * manually-added locales under (a flat array of locale strings) --
	 * see set_manual_locales()/manual_locales().
	 *
	 * @var string
	 */
	private const MANUAL_LOCALES_OPTION = 'babilim_manual_locales';

	/**
	 * Option holding detected locales the site owner stopped managing.
	 *
	 * @var string
	 */
	public const OPTION_DISABLED_LOCALES = 'babilim_disabled_locales';

	/**
	 * The option plugin_slug_from_file() keeps its "plugin main file ->
	 * wp.org slug" map in (main file => slug). The `update_plugins`
	 * transient is where the slug comes from, and WordPress clears that
	 * transient whenever it likes; without this map a plugin such as
	 * hello.php (slug "hello-dolly") would change identity every time the
	 * transient is empty.
	 *
	 * @var string
	 */
	public const PLUGIN_SLUGS_OPTION = 'babilim_plugin_slugs';

	/**
	 * The component keys a request to the inventory sync endpoint
	 * expects, in the order request_components() emits them. Excludes
	 * `installed`, which the request builder adds separately once a
	 * site has actually installed something for that component --
	 * nothing here knows about installed builds.
	 *
	 * @var string[]
	 */
	private const REQUEST_COMPONENT_KEYS = array(
		'kind',
		'slug',
		'version',
		'text_domain',
		'extra_domains',
		'is_wporg',
		'bundled_locales',
	);

	/**
	 * Matches a translation filename's optional domain prefix and its
	 * locale, e.g. "woocommerce-zh_TW.mo" (domain "woocommerce", locale
	 * "zh_TW") or the domainless theme convention "zh_TW.mo" (locale
	 * only). Same shape as the filename regex WordPress core itself
	 * uses to reverse-engineer a domain/locale pair from a bundled
	 * translation file's name.
	 *
	 * @var string
	 */
	private const LOCALE_FILENAME_PATTERN = '/^(?:(.+)-)?([a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?)\.(?:mo|l10n\.php)$/D';

	/**
	 * @var callable
	 */
	private $get_plugins;

	/**
	 * @var callable
	 */
	private $get_themes;

	/**
	 * @var callable
	 */
	private $get_transient;

	/**
	 * @var callable
	 */
	private $get_users_locales;

	/**
	 * @var callable
	 */
	private $list_dir;

	/**
	 * @var callable
	 */
	private $function_exists;

	/**
	 * Memoized $get_themes() result -- populated on first use by either
	 * theme_components() or is_theme_own_directory(), whichever runs
	 * first, and reused for the rest of this instance's lifetime rather
	 * than re-querying on every effective_file() call (see that method's
	 * docblock).
	 *
	 * @var array|null
	 */
	private $themes_cache;

	/**
	 * @param callable      $get_plugins       See the class docblock.
	 * @param callable      $get_themes        See the class docblock.
	 * @param callable      $get_transient     See the class docblock.
	 * @param callable      $get_users_locales See the class docblock.
	 * @param callable|null $list_dir          See the class docblock. Defaults to default_list_dir().
	 * @param callable|null $function_exists   See the class docblock. Defaults to PHP's function_exists().
	 */
	public function __construct( callable $get_plugins, callable $get_themes, callable $get_transient, callable $get_users_locales, ?callable $list_dir = null, ?callable $function_exists = null ) {
		$this->get_plugins       = $get_plugins;
		$this->get_themes        = $get_themes;
		$this->get_transient     = $get_transient;
		$this->get_users_locales = $get_users_locales;
		$this->list_dir          = $list_dir ?? array( $this, 'default_list_dir' );
		$this->function_exists   = $function_exists ?? 'function_exists';
	}

	/**
	 * Every installed plugin and theme, in this class's own full shape
	 * (more fields than a request needs -- see request_components() for
	 * the projection an actual request sends). A component whose own
	 * slug and text domain are both unusable as path components is left
	 * out entirely (see safe_identity()).
	 *
	 * @return array[]
	 */
	public function components(): array {
		return array_merge( $this->plugin_components(), $this->theme_components() );
	}

	/**
	 * components(), projected down to exactly the keys a request to the
	 * inventory sync endpoint's `components[]` needs (see
	 * REQUEST_COMPONENT_KEYS) -- no more, no fewer -- and held to the
	 * contract's limits (see plan_request_components()).
	 *
	 * @return array[]
	 */
	public function request_components(): array {
		return $this->plan_request_components()['components'];
	}

	/**
	 * request_components() plus what had to be left out or trimmed to
	 * stay inside the contract, for the caller to report.
	 *
	 * - A component with no version is left out silently (the endpoint
	 *   requires a non-empty string, so there is nothing to report it
	 *   against).
	 * - A component whose version is longer than the contract allows is
	 *   left out and noted as `version_too_long`. The version is never
	 *   cut short: a shortened number would name a different release.
	 * - Over-long `extra_domains` / `bundled_locales` entries are dropped
	 *   and lists longer than the limit are cut; the component is noted
	 *   as `list_trimmed`.
	 *
	 * @return array{components: array[], notes: array<int,array{kind:string,slug:string,code:string}>}
	 */
	public function plan_request_components(): array {
		$out   = array();
		$notes = array();

		foreach ( $this->components() as $component ) {
			$version = (string) ( $component['version'] ?? '' );
			if ( '' === $version ) {
				continue;
			}
			if ( ! Babilim_Contract_Limits::fits( $version, Babilim_Contract_Limits::VERSION ) ) {
				$notes[] = array(
					'kind' => (string) $component['kind'],
					'slug' => (string) $component['slug'],
					'code' => 'version_too_long',
				);
				continue;
			}

			$projected = array();
			foreach ( self::REQUEST_COMPONENT_KEYS as $key ) {
				$projected[ $key ] = $component[ $key ];
			}
			$trimmed = false;
			foreach ( array(
				'extra_domains'   => Babilim_Contract_Limits::TEXT_DOMAIN,
				'bundled_locales' => Babilim_Contract_Limits::LOCALE,
			) as $key => $item_max ) {
				$list = array();
				foreach ( (array) $projected[ $key ] as $item ) {
					if ( Babilim_Contract_Limits::fits( (string) $item, $item_max ) ) {
						$list[] = (string) $item;
					} else {
						$trimmed = true;
					}
				}
				if ( count( $list ) > Babilim_Contract_Limits::MAX_DOMAIN_LIST ) {
					$list    = array_slice( $list, 0, Babilim_Contract_Limits::MAX_DOMAIN_LIST );
					$trimmed = true;
				}
				$projected[ $key ] = $list;
			}
			if ( $trimmed ) {
				$notes[] = array(
					'kind' => (string) $component['kind'],
					'slug' => (string) $component['slug'],
					'code' => 'list_trimmed',
				);
			}
			$out[] = $projected;
		}

		return array(
			'components' => $out,
			'notes'      => $notes,
		);
	}

	/**
	 * Every locale in play on this site: the site locale, every
	 * installed language pack, every user's own locale, a best-effort
	 * read of any active multilingual plugin's configured languages,
	 * and anything manually added on the settings screen -- deduped,
	 * filtered to path-safe strings, and sorted by wp_locale string with
	 * no locale favored over another.
	 *
	 * @return string[]
	 */
	public function locales(): array {
		$locales  = self::normalize_locales( array_merge( $this->detected_locales(), $this->manual_locales() ) );
		$disabled = $this->disabled_locales();
		return array_values( array_diff( $locales, $disabled ) );
	}

	/**
	 * locales() minus manual_locales() -- every locale this site would
	 * report even with nothing manually added: the site locale, every
	 * installed language pack, every user's own locale, and a
	 * best-effort read of any active multilingual plugin's configured
	 * languages. The settings screen's managed-locales list uses this
	 * (rather than locales() itself) to tell "this locale is already
	 * managed automatically" apart from "this locale is only managed
	 * because it was manually added" -- see set_manual_locales()'s own
	 * caller in the admin screen, which must never write a detected
	 * locale into the manual list.
	 *
	 * @return string[]
	 */
	public function detected_locales(): array {
		$locales   = array();
		$locales[] = get_locale();

		foreach ( (array) get_available_languages() as $locale ) {
			$locales[] = $locale;
		}
		foreach ( (array) call_user_func( $this->get_users_locales ) as $locale ) {
			$locales[] = $locale;
		}
		foreach ( $this->multilingual_plugin_locales() as $locale ) {
			$locales[] = $locale;
		}

		return self::normalize_locales( $locales );
	}

	/**
	 * Dedupes, filters to path-safe strings, and sorts a list of locale
	 * codes -- the shared tail of locales()/detected_locales().
	 *
	 * @param array $locales Raw locale codes.
	 * @return string[]
	 */
	private static function normalize_locales( array $locales ): array {
		$locales = array_map( 'strval', $locales );
		$locales = array_values(
			array_unique(
				array_filter(
					$locales,
					static function ( string $locale ): bool {
						return Babilim_Paths::is_safe_locale( $locale ) && Babilim_Contract_Limits::fits( $locale, Babilim_Contract_Limits::LOCALE );
					}
				)
			)
		);
		sort( $locales, SORT_STRING );
		return $locales;
	}

	/**
	 * The file WordPress core would actually load for $domain at
	 * $locale right now, by asking core's own text-domain registry
	 * rather than recomputing its directory-priority rules itself.
	 * `.l10n.php` is preferred over `.mo` when both exist, matching
	 * core's own preference (since WordPress 6.5). When the registry's
	 * directory turns out to be a theme's own stylesheet/template
	 * directory, the filename core actually loads there has no domain
	 * prefix at all (`{locale}.mo`/`.l10n.php`, not
	 * `{domain}-{locale}.mo`/`.l10n.php`) -- see is_theme_own_directory().
	 *
	 * @param string $domain Text domain.
	 * @param string $locale Locale code.
	 * @return string|null Absolute path, or null if nothing's resolvable
	 *                      (including when either argument isn't safe to
	 *                      use as a path component, or the registry
	 *                      itself isn't available, e.g. too early in the
	 *                      request).
	 */
	public function effective_file( string $domain, string $locale ): ?string {
		if ( ! Babilim_Paths::is_safe_name( $domain ) || ! Babilim_Paths::is_safe_locale( $locale ) ) {
			return null;
		}

		$registry = $GLOBALS['wp_textdomain_registry'] ?? null;
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'get' ) ) {
			return null;
		}

		$dir = $registry->get( $domain, $locale );
		if ( ! is_string( $dir ) || '' === $dir ) {
			return null;
		}
		$dir = rtrim( $dir, '/' );

		$basename = $this->is_theme_own_directory( $dir ) ? $locale : ( $domain . '-' . $locale );

		$php = $dir . '/' . $basename . '.l10n.php';
		if ( is_readable( $php ) ) {
			return $php;
		}

		$mo = $dir . '/' . $basename . '.mo';
		if ( is_readable( $mo ) ) {
			return $mo;
		}

		return null;
	}

	/**
	 * Whether $dir is (at or under) some installed theme's own
	 * stylesheet or template directory -- the one case where core loads
	 * a theme's translation file without a domain prefix in its name.
	 * Queries the injected $get_themes() rather than recomputing theme
	 * directories some other way, so it always agrees with what
	 * theme_components() itself used.
	 *
	 * @param string $dir Already-normalized (no trailing slash) absolute directory.
	 * @return bool
	 */
	private function is_theme_own_directory( string $dir ): bool {
		$dir = $dir . '/';

		foreach ( $this->themes() as $theme ) {
			if ( ! is_object( $theme ) ) {
				continue;
			}
			foreach ( array( 'get_stylesheet_directory', 'get_template_directory' ) as $method ) {
				if ( ! method_exists( $theme, $method ) ) {
					continue;
				}
				$theme_dir = rtrim( (string) $theme->$method(), '/' );
				if ( '' !== $theme_dir && 0 === strpos( $dir, $theme_dir . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The injected $get_themes() result, called at most once per
	 * instance (see $themes_cache's docblock).
	 *
	 * @return array
	 */
	private function themes(): array {
		if ( null === $this->themes_cache ) {
			$this->themes_cache = (array) call_user_func( $this->get_themes );
		}
		return $this->themes_cache;
	}

	/**
	 * Lists a directory's entries (bare filenames, no './..'). The
	 * default $list_dir implementation -- wraps scandir() so a missing
	 * or unreadable directory never throws or warns, just returns
	 * nothing to reverse-engineer.
	 *
	 * @param string $dir Absolute directory path.
	 * @return string[]
	 */
	private function default_list_dir( string $dir ): array {
		try {
			if ( '' === $dir || ! is_dir( $dir ) ) {
				return array();
			}
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $entries ) {
				return array();
			}
			return array_values( array_diff( $entries, array( '.', '..' ) ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * The wp.org-transient-aware plugin slug for one get_plugins() entry:
	 * the transient's own 'slug' field (when present and path-safe) beats
	 * the plugin directory's own name -- they aren't guaranteed to match
	 * (e.g. "Hello Dolly" installs as hello.php but its wp.org slug is
	 * "hello-dolly"). Shared (not re-derived a second, possibly-diverging
	 * way) by Babilim::active_components() and
	 * Babilim_Default_Index::installed_identities(), which each register
	 * this slug alongside the directory name and the text-domain header
	 * as the identities a plugin may be keyed under.
	 *
	 * Resolution order: the transient's slug (also remembered in
	 * PLUGIN_SLUGS_OPTION), then that remembered slug, then the directory
	 * or file name. Every consumer (the inventory, the request form, the
	 * default index) goes through this one function, so a cleared
	 * transient can no longer make them disagree about a plugin's slug.
	 *
	 * @param string $file             The get_plugins() array key (a plugin file, e.g. "woocommerce/woocommerce.php").
	 * @param mixed  $update_transient The 'update_plugins' site transient's current value (see the constructor's $get_transient docblock).
	 * @return string
	 */
	public static function plugin_slug_from_file( string $file, $update_transient ): string {
		$file     = (string) $file;
		$dir_name = self::plugin_dir_name_from_file( $file );

		$transient_entry = self::transient_entry( $update_transient, $file );
		$slug            = (string) ( self::field( $transient_entry, 'slug' ) ?? '' );
		$stored          = self::stored_plugin_slugs();
		if ( '' !== $slug && Babilim_Paths::is_safe_name( $slug ) ) {
			// The transient knows the slug: remember it (only when it
			// changed, so a steady state writes nothing).
			if ( ( $stored[ $file ] ?? null ) !== $slug ) {
				$stored[ $file ] = $slug;
				update_option( self::PLUGIN_SLUGS_OPTION, $stored, false );
			}
			return $slug;
		}
		$remembered = (string) ( $stored[ $file ] ?? '' );
		if ( '' !== $remembered && Babilim_Paths::is_safe_name( $remembered ) ) {
			return $remembered;
		}
		return $dir_name;
	}

	/**
	 * The persisted "main file -> slug" map, with anything that is not a
	 * string-to-string pair dropped.
	 *
	 * @return array<string,string>
	 */
	private static function stored_plugin_slugs(): array {
		$raw = get_option( self::PLUGIN_SLUGS_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $file => $slug ) {
			if ( is_string( $slug ) ) {
				$out[ (string) $file ] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Forgets remembered slugs of plugin files that are no longer
	 * installed, so a different plugin later installed at the same path
	 * cannot inherit the old one's slug once an inventory has run in
	 * between. An empty plugin list is treated as "could not be
	 * determined" and prunes nothing.
	 *
	 * @param string[] $installed_files The current get_plugins() keys.
	 */
	private static function prune_plugin_slugs( array $installed_files ): void {
		if ( array() === $installed_files ) {
			return;
		}
		$stored = self::stored_plugin_slugs();
		$kept   = array_intersect_key( $stored, array_flip( $installed_files ) );
		if ( count( $kept ) !== count( $stored ) ) {
			update_option( self::PLUGIN_SLUGS_OPTION, $kept, false );
		}
	}

	/**
	 * The directory name a get_plugins() file key resolves to -- the
	 * fallback identity plugin_slug_from_file() itself falls back to, and
	 * what a plugin's own text domain defaults to when it declares none.
	 * Shared for the same reason as plugin_slug_from_file() above.
	 *
	 * @param string $file The get_plugins() array key.
	 * @return string
	 */
	public static function plugin_dir_name_from_file( string $file ): string {
		$file        = (string) $file;
		$plugin_root = '.' === dirname( $file ) || '' === dirname( $file );
		return $plugin_root ? (string) preg_replace( '/\.php$/', '', basename( $file ) ) : dirname( $file );
	}

	/**
	 * The text domain a get_plugins() entry's own header data resolves
	 * to: its declared `Text Domain` header when non-empty, otherwise
	 * $dir_name (matching plugin_slug_from_file()'s own fallback).
	 * Unlike the wp.org slug, this never depends on any transient --
	 * it's read straight from the plugin's own file on disk, so it's
	 * available even when `update_plugins` is empty or stale. Shared for
	 * the same reason as plugin_slug_from_file()/plugin_dir_name_from_file()
	 * above.
	 *
	 * @param array  $data     One get_plugins() entry's header array.
	 * @param string $dir_name plugin_dir_name_from_file()'s result for the same entry.
	 * @return string
	 */
	public static function plugin_text_domain_from_data( array $data, string $dir_name ): string {
		return '' !== (string) ( $data['TextDomain'] ?? '' ) ? (string) $data['TextDomain'] : $dir_name;
	}

	/**
	 * One component per entry from $get_plugins().
	 *
	 * @return array[]
	 */
	private function plugin_components(): array {
		$plugins = (array) call_user_func( $this->get_plugins );
		$update  = call_user_func( $this->get_transient, 'update_plugins' );
		$out     = array();

		self::prune_plugin_slugs( array_map( 'strval', array_keys( $plugins ) ) );

		foreach ( $plugins as $file => $data ) {
			$file = (string) $file;
			$data = is_array( $data ) ? $data : array();

			$plugin_root = '.' === dirname( $file ) || '' === dirname( $file );
			$dir_name    = self::plugin_dir_name_from_file( $file );
			$slug        = self::plugin_slug_from_file( $file, $update );

			$text_domain = self::plugin_text_domain_from_data( $data, $dir_name );
			$domain_path = self::normalize_domain_path( (string) ( $data['DomainPath'] ?? '' ) );

			$identity = $this->safe_identity( $slug, $text_domain );
			if ( null === $identity ) {
				continue;
			}
			list( $slug, $text_domain ) = $identity;

			$scan_dir = $plugin_root ? ( self::plugin_dir() . $domain_path ) : ( self::plugin_dir() . '/' . $dir_name . $domain_path );
			$scan     = $this->scan_domain( $scan_dir, $text_domain, false );

			$is_wporg = self::has_third_party_update_uri( $data )
				? false
				: $this->is_wporg_from_transient( $update, $file, 'id', 'w.org/plugins/', false );

			$out[] = array(
				'kind'            => 'plugin',
				'slug'            => $slug,
				'name'            => (string) ( $data['Name'] ?? '' ),
				'version'         => (string) ( $data['Version'] ?? '' ),
				'text_domain'     => $text_domain,
				'domain_path'     => $domain_path,
				'is_wporg'        => $is_wporg,
				'main_file'       => $file,
				'extra_domains'   => $scan['extra_domains'],
				'bundled_locales' => $scan['bundled_locales'],
			);
		}

		return $out;
	}

	/**
	 * One component per entry from $get_themes().
	 *
	 * @return array[]
	 */
	private function theme_components(): array {
		$themes = $this->themes();
		$update = call_user_func( $this->get_transient, 'update_themes' );
		$out    = array();

		foreach ( $themes as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;

			$text_domain = (string) $this->theme_field( $theme, 'TextDomain', '' );
			if ( '' === $text_domain ) {
				$text_domain = $stylesheet;
			}

			$identity = $this->safe_identity( $stylesheet, $text_domain );
			if ( null === $identity ) {
				continue;
			}
			list( $stylesheet, $text_domain ) = $identity;

			$domain_path = self::normalize_domain_path( (string) $this->theme_field( $theme, 'DomainPath', '' ) );
			if ( '' === $domain_path ) {
				// WordPress core's own default when a theme doesn't
				// declare its own Domain Path header.
				$domain_path = '/languages';
			}

			$dir  = $this->theme_dir( $theme );
			$scan = $this->scan_domain( $dir . $domain_path, $text_domain, true );

			$out[] = array(
				'kind'            => 'theme',
				'slug'            => $stylesheet,
				'name'            => (string) $this->theme_field( $theme, 'Name', '' ),
				'version'         => (string) $this->theme_field( $theme, 'Version', '' ),
				'text_domain'     => $text_domain,
				'domain_path'     => $domain_path,
				'is_wporg'        => $this->is_wporg_from_transient( $update, $stylesheet, 'url', 'wordpress.org/themes/', true ),
				'stylesheet'      => $stylesheet,
				'extra_domains'   => $scan['extra_domains'],
				'bundled_locales' => $scan['bundled_locales'],
			);
		}

		return $out;
	}

	/**
	 * Falls a component's slug/text_domain back to each other when one
	 * fails Babilim_Paths::is_safe_name(), and drops the component
	 * entirely (returns null) when neither is usable -- a header value
	 * is free-form author-supplied text, and nothing downstream may
	 * ever use an unsafe string as a path component.
	 *
	 * @param string $slug        Component slug.
	 * @param string $text_domain Component text domain.
	 * @return array{0: string, 1: string}|null [slug, text_domain], or null if both are unusable.
	 */
	private function safe_identity( string $slug, string $text_domain ): ?array {
		if ( ! Babilim_Paths::is_safe_name( $text_domain ) || ! Babilim_Contract_Limits::fits( $text_domain, Babilim_Contract_Limits::TEXT_DOMAIN ) ) {
			$text_domain = $slug;
		}
		// A slug or text domain longer than the contract allows cannot be
		// reported at all (and a client event about it would need the
		// same over-long slug), so the component is left out.
		if ( ! Babilim_Paths::is_safe_name( $slug ) || ! Babilim_Paths::is_safe_name( $text_domain )
			|| ! Babilim_Contract_Limits::fits( $slug, Babilim_Contract_Limits::SLUG )
			|| ! Babilim_Contract_Limits::fits( $text_domain, Babilim_Contract_Limits::TEXT_DOMAIN ) ) {
			return null;
		}
		return array( $slug, $text_domain );
	}

	/**
	 * Normalizes a `Domain Path`/`DomainPath` header value to always
	 * either be empty, or start with exactly one `/` and never end with
	 * one -- WordPress core string-concatenates this value onto a
	 * directory path as-is, so a header written without its leading
	 * slash (a common mistake -- core doesn't validate it either) would
	 * otherwise silently glue onto the preceding path segment.
	 *
	 * @param string $raw Raw header value.
	 * @return string
	 */
	private static function normalize_domain_path( string $raw ): string {
		$trimmed = trim( $raw, '/' );
		return '' === $trimmed ? '' : ( '/' . $trimmed );
	}

	/**
	 * Reads one header field off a theme descriptor: a WP_Theme-shaped
	 * object (duck-typed -- anything with a ->get($key) method,
	 * matching wp_get_themes()'s real return value and returning false
	 * for an absent/empty header, exactly like WP_Theme::get()).
	 *
	 * @param mixed  $theme   The theme descriptor.
	 * @param string $key     Header key, e.g. 'TextDomain'.
	 * @param mixed  $default Value to use when absent/empty.
	 * @return mixed
	 */
	private function theme_field( $theme, string $key, $default ) {
		if ( ! is_object( $theme ) || ! method_exists( $theme, 'get' ) ) {
			return $default;
		}
		$value = $theme->get( $key );
		return ( false === $value || null === $value || '' === $value ) ? $default : $value;
	}

	/**
	 * The theme's own stylesheet directory, absolute, no trailing
	 * slash, via a WP_Theme-shaped object's get_stylesheet_directory()
	 * method.
	 *
	 * @param mixed $theme The theme descriptor.
	 * @return string
	 */
	private function theme_dir( $theme ): string {
		if ( is_object( $theme ) && method_exists( $theme, 'get_stylesheet_directory' ) ) {
			return rtrim( (string) $theme->get_stylesheet_directory(), '/' );
		}
		return '';
	}

	/**
	 * WP_PLUGIN_DIR, no trailing slash -- '' if somehow undefined (this
	 * class never fatals over it; a plugin dir path that resolves to
	 * "/{slug}" just won't list anything real, same as any other
	 * unreadable directory).
	 *
	 * @return string
	 */
	private static function plugin_dir(): string {
		return defined( 'WP_PLUGIN_DIR' ) ? rtrim( (string) WP_PLUGIN_DIR, '/' ) : '';
	}

	/**
	 * Whether $data (a plugin's own header array) declares its own
	 * `Update URI` pointing somewhere other than wp.org. WordPress core
	 * only ever dispatches a plugin's update check to a third-party
	 * server via the `update_plugins_{hostname}` filter when the plugin
	 * itself declared that header -- a genuinely wp.org-hosted plugin
	 * never has one. A plugin that opted out of wp.org this way can
	 * never actually be wp.org-hosted, no matter what its own
	 * transient entry happens to claim (that entry's `id`/`slug` came
	 * from the third-party server the header points at, not from
	 * wp.org).
	 *
	 * @param array $data Plugin header array.
	 * @return bool
	 */
	private static function has_third_party_update_uri( array $data ): bool {
		$update_uri = (string) ( $data['UpdateURI'] ?? '' );
		if ( '' === $update_uri ) {
			return false;
		}
		$host = self::update_uri_host( $update_uri );
		return '' !== $host && ! in_array( $host, array( 'w.org', 'wordpress.org' ), true );
	}

	/**
	 * The host component of a plugin's own `Update URI` header value, the
	 * same way WordPress core resolves one for its
	 * `update_plugins_{hostname}` filter dispatch in
	 * wp-includes/update.php's `wp_update_plugins()`
	 * (`wp_parse_url( sanitize_url( $uri ), PHP_URL_HOST )`).
	 *
	 * A bare PHP `parse_url()` can only ever find a host in a string that
	 * already has a scheme (`https://host/path`) or is protocol-relative
	 * (`//host/path`) -- a scheme-less value with neither, e.g. the
	 * documented `w.org/plugins/<slug>` (or `wordpress.org/...`) shorthand
	 * a plugin can declare to explicitly opt back into wp.org's own
	 * update checks despite also having an `Update URI` header, parses
	 * with the whole string landing in `path` and no `host` at all (this
	 * plugin's own `is_wporg_from_transient()`, just below, matches that
	 * exact same `w.org/plugins/` string as a literal prefix on the
	 * update transient's own `id` field -- not a URL to parse -- for the
	 * same underlying convention).
	 *
	 * `sanitize_url()` (an `esc_url_raw()` alias) DOES add a missing
	 * scheme itself, in wp-includes/formatting.php's `esc_url()`: once the
	 * string survives its character allow-list, if it contains no `:` at
	 * all, and doesn't start with `/`, `#`, or `?`, and doesn't look like
	 * a bare `something.php` path, core prepends `http://` before ever
	 * calling `wp_parse_url()`. That's why `w.org/plugins/<slug>` resolves
	 * to host `w.org` in core despite having no scheme -- not because
	 * `wp_parse_url()` recovers a protocol-relative host on its own, but
	 * because `esc_url()` already turned it into `http://w.org/plugins/
	 * <slug>` first. Reproducing core's own three conditions here (rather
	 * than the previous, looser "always try `//host/path`" shortcut) also
	 * matters at the boundary: a value starting with `/` (e.g. a stray
	 * `/example.com/x`) must NOT resolve to a host at all, matching core
	 * -- the previous `ltrim( $update_uri, '/' )` step stripped that
	 * leading slash before prefixing `//`, which wrongly recovered
	 * `example.com` as a host core itself never would.
	 *
	 * @param string $update_uri Raw `Update URI` header value.
	 * @return string Lowercase-as-given host, or '' if none could be resolved.
	 */
	private static function update_uri_host( string $update_uri ): string {
		$update_uri = trim( $update_uri );
		if ( '' === $update_uri ) {
			return '';
		}
		if ( false === strpos( $update_uri, ':' )
			&& ! in_array( $update_uri[0], array( '/', '#', '?' ), true )
			&& 1 !== preg_match( '/^[a-z0-9-]+?\.php/i', $update_uri )
		) {
			$update_uri = 'http://' . $update_uri;
		}
		return (string) ( wp_parse_url( $update_uri, PHP_URL_HOST ) ?? '' );
	}

	/**
	 * The transient entry (array or object) for $lookup_key, checking
	 * the 'response' bucket then 'no_update', or null if neither has
	 * one.
	 *
	 * @param mixed  $transient  The transient's current value.
	 * @param string $lookup_key Plugin file or theme stylesheet.
	 * @return mixed|null
	 */
	private static function transient_entry( $transient, string $lookup_key ) {
		if ( ! is_array( $transient ) && ! is_object( $transient ) ) {
			return null;
		}
		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			$entries = self::as_array( self::field( $transient, $bucket ) );
			if ( array_key_exists( $lookup_key, $entries ) ) {
				return $entries[ $lookup_key ];
			}
		}
		return null;
	}

	/**
	 * Whether a component (keyed by $lookup_key in the given site
	 * transient's 'response'/'no_update' buckets) is wp.org-hosted: for
	 * plugins, the entry's 'id' starts with "w.org/plugins/"; for
	 * themes, its 'url' contains "wordpress.org/themes/" (WordPress
	 * core's update_themes transient carries the wp.org-supplied
	 * 'theme'/'new_version'/'url'/'package' shape for a wp.org theme,
	 * not a 'theme_uri' field). Never errors on a missing/false
	 * transient, a missing bucket, or an entry in either array or
	 * object form -- WordPress core itself always delivers a plugin
	 * entry as stdClass and a theme entry as a plain array (whether
	 * wp.org-sourced or from a component's own `Update URI` header;
	 * see the constructor's $get_transient docblock), but this method
	 * doesn't depend on that split holding, so it reads either shape
	 * either way.
	 *
	 * @param mixed  $transient    The transient's current value.
	 * @param string $lookup_key   Plugin file or theme stylesheet.
	 * @param string $field        'id' (plugins) or 'url' (themes).
	 * @param string $needle       String to match $field against.
	 * @param bool   $as_substring True to match anywhere in $field (themes); false to match only as a prefix (plugins).
	 * @return bool
	 */
	private function is_wporg_from_transient( $transient, string $lookup_key, string $field, string $needle, bool $as_substring ): bool {
		$entry = self::transient_entry( $transient, $lookup_key );
		if ( null === $entry ) {
			return false;
		}

		$value = (string) ( self::field( $entry, $field ) ?? '' );
		if ( '' === $value ) {
			return false;
		}

		return $as_substring ? ( false !== strpos( $value, $needle ) ) : ( 0 === strpos( $value, $needle ) );
	}

	/**
	 * Reads $key off $item, whether it's an array or an object.
	 *
	 * @param mixed  $item Array or object.
	 * @param string $key  Key/property name.
	 * @return mixed|null
	 */
	private static function field( $item, string $key ) {
		if ( is_object( $item ) ) {
			return $item->$key ?? null;
		}
		if ( is_array( $item ) ) {
			return $item[ $key ] ?? null;
		}
		return null;
	}

	/**
	 * $value as an array: itself if already one, get_object_vars() of
	 * it if an object, or an empty array for anything else.
	 *
	 * @param mixed $value Array, object, or anything else.
	 * @return array
	 */
	private static function as_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}
		return array();
	}

	/**
	 * Lists $dir and reverse-engineers, from filenames alone, every
	 * extra text domain and every locale this component's own
	 * ($own_domain) bundled translation files cover. When
	 * $allow_domainless is true (themes only -- WordPress core never
	 * loads a plugin's translation file this way), a domainless
	 * filename (e.g. "zh_TW.mo") counts as $own_domain's own file;
	 * otherwise it's ignored entirely (neither a bundled locale nor an
	 * extra domain -- there's no domain to attribute it to).
	 * Every locale is checked against Babilim_Paths::is_safe_locale()
	 * and every extra domain against Babilim_Paths::is_safe_name() --
	 * anything that fails is dropped rather than surfaced. Both results
	 * are deduped and string-sorted for a deterministic, comparable
	 * output.
	 *
	 * @param string $dir              Absolute directory to list.
	 * @param string $own_domain       This component's own text domain.
	 * @param bool   $allow_domainless Whether a domainless filename counts as $own_domain's own file.
	 * @return array{extra_domains: string[], bundled_locales: string[]}
	 */
	private function scan_domain( string $dir, string $own_domain, bool $allow_domainless ): array {
		$extra_domains   = array();
		$bundled_locales = array();

		foreach ( call_user_func( $this->list_dir, $dir ) as $filename ) {
			if ( 1 !== preg_match( self::LOCALE_FILENAME_PATTERN, (string) $filename, $matches ) ) {
				continue;
			}

			// A successful match always fills all three offsets -- the
			// locale group (2) can never be empty (it requires at least
			// two letters); only the optional domain group (1) can be.
			$domain = $matches[1];
			$locale = $matches[2];

			if ( ! Babilim_Paths::is_safe_locale( $locale ) ) {
				continue;
			}

			if ( '' === $domain ) {
				if ( $allow_domainless ) {
					$bundled_locales[] = $locale;
				}
				continue;
			}

			if ( $domain === $own_domain ) {
				$bundled_locales[] = $locale;
				continue;
			}

			if ( Babilim_Paths::is_safe_name( $domain ) ) {
				$extra_domains[] = $domain;
			}
		}

		$extra_domains   = array_values( array_unique( $extra_domains ) );
		$bundled_locales = array_values( array_unique( $bundled_locales ) );
		sort( $extra_domains, SORT_STRING );
		sort( $bundled_locales, SORT_STRING );

		return array(
			'extra_domains'   => $extra_domains,
			'bundled_locales' => $bundled_locales,
		);
	}

	/**
	 * Best-effort locales from whichever multilingual plugin (if any)
	 * is active. Never errors or warns when none is: WPML is read
	 * through its own documented `wpml_active_languages` filter (a
	 * plain apply_filters() call, harmless whether or not anything
	 * hooks it); Polylang's `pll_languages_list()` is only called when
	 * the injected $function_exists says it exists; TranslatePress is
	 * read from its own `trp_settings` option via the always-defined
	 * get_option(), never a TranslatePress-specific function -- this
	 * hasn't been verified against a real TranslatePress installation,
	 * only against its own documented settings shape.
	 *
	 * @return string[]
	 */
	private function multilingual_plugin_locales(): array {
		$locales = array();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- this is WPML's own public filter (its documented API for reading its active-languages list), not a hook this plugin defines; the name is fixed by WPML itself and can't be prefixed.
		$wpml_languages = apply_filters( 'wpml_active_languages', null );
		if ( is_array( $wpml_languages ) ) {
			foreach ( $wpml_languages as $language ) {
				$locale = is_array( $language ) ? (string) ( $language['default_locale'] ?? '' ) : '';
				if ( '' !== $locale ) {
					$locales[] = $locale;
				}
			}
		}

		if ( call_user_func( $this->function_exists, 'pll_languages_list' ) ) {
			foreach ( pll_languages_list( array( 'fields' => 'locale' ) ) as $locale ) {
				$locales[] = (string) $locale;
			}
		}

		$trp_settings = get_option( 'trp_settings' );
		if ( is_array( $trp_settings ) && is_array( $trp_settings['translation-languages'] ?? null ) ) {
			foreach ( $trp_settings['translation-languages'] as $locale ) {
				$locales[] = (string) $locale;
			}
		}

		return $locales;
	}

	/**
	 * Locales manually added on the settings screen -- the raw stored
	 * list, unsorted/undeduped-with-detected (see locales()/
	 * detected_locales() for the merged, normalized views). Public: the
	 * settings screen itself needs this to tell which checkboxes reflect
	 * a manual addition (see the class docblock's set_manual_locales()
	 * note).
	 *
	 * @return string[]
	 */
	public function manual_locales(): array {
		$manual = get_option( self::MANUAL_LOCALES_OPTION, array() );
		return is_array( $manual ) ? array_map( 'strval', $manual ) : array();
	}

	/**
	 * Replaces the manually-added locale list wholesale -- the settings
	 * screen's own "manage locales" control. Every WordPress-supported
	 * locale is treated equally here: not validated against any
	 * hardcoded list of "known" locales, only filtered to strings that
	 * are safe to use as a path component.
	 *
	 * @param string[] $locales Locale codes.
	 */
	public function set_manual_locales( array $locales ): void {
		$locales = array_values( array_unique( array_filter( array_map( 'strval', $locales ), array( 'Babilim_Paths', 'is_safe_locale' ) ) ) );
		update_option( self::MANUAL_LOCALES_OPTION, $locales );
	}

	/**
	 * Locales the site owner stopped managing. Files already installed
	 * for them are left alone.
	 *
	 * @return string[]
	 */
	public function disabled_locales(): array {
		$stored = get_option( self::OPTION_DISABLED_LOCALES, array() );
		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}

	/**
	 * Replaces the stopped-locale list.
	 *
	 * @param string[] $locales Locale codes.
	 */
	public function set_disabled_locales( array $locales ): void {
		$locales = array_values( array_unique( array_filter( array_map( 'strval', $locales ), array( 'Babilim_Paths', 'is_safe_locale' ) ) ) );
		update_option( self::OPTION_DISABLED_LOCALES, $locales );
	}
}
