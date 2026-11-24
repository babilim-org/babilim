<?php
/**
 * The translation-loading layer: four WordPress core filters, the
 * `load_textdomain` action (records each load's original file so the
 * outer fill-in only answers core's just-in-time load), plus the
 * `default` text domain's supplement loader.
 *
 * Every public callback here wraps its body in a `\Throwable` catch-all,
 * validates any domain/locale it's given against Babilim_Paths'
 * allow-list regexes, and no-ops entirely when Babilim_Paths::root()
 * doesn't exist -- a broken or partially-installed layout must never be
 * able to fatal a request for an unrelated string.
 *
 * wp.org Guideline 8 (no remotely loaded executable code) note: every
 * `.l10n.php` path this class ever passes to load_textdomain() is
 * literal PHP that WordPress core `include`s. This loader only ever
 * reads files already sitting under Babilim_Paths::root() -- it never
 * fetches or writes one itself -- so its safety here depends entirely on
 * the writer side never placing anything under that root except a
 * `.l10n.php` produced locally, from an already-downloaded `.po`/`.mo`,
 * via `WP_Translation_File::transform()` -- never downloaded as-is. This
 * class has no way to verify that invariant itself; it's enforced
 * entirely by what the writer side is allowed to put on disk.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements Babilim's translation-loading hooks.
 */
final class Babilim_Loader {

	/**
	 * Whether register() has already run.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * How many times register()'s body has actually run (as opposed to
	 * short-circuited by the guard above). Only ever 0 or 1 in correct
	 * code -- this exists so tests can tell "ran once" apart from "the
	 * guard stopped mattering", which `$registered` alone can't do
	 * (it ends up `true` either way).
	 *
	 * @var int
	 */
	private static $run_count = 0;

	/**
	 * The layers checked by load_translation_file()/lang_dir_for_domain()/
	 * the JS filters, in priority order -- a site's own custom/ override
	 * always wins over our babilim/ supplement, which in turn only ever
	 * fills a gap left by a WordPress.org language pack or the
	 * plugin/theme's own bundled translation. `%s` is 'plugins' or
	 * 'themes'.
	 *
	 * @var string[]
	 */
	private const LAYER_TYPES = array( 'custom/%s', '%s' );

	/**
	 * The two component kinds a plugin/theme domain might belong to --
	 * checked in this order wherever the caller can't already tell us
	 * which one a domain is.
	 *
	 * @var string[]
	 */
	private const KINDS = array( 'plugins', 'themes' );

	/**
	 * Priority of the lang_dir_for_domain and load_translation_file
	 * callbacks: after every ordinary plugin (the default 10 or a small
	 * number), so a directory or file another plugin resolved is seen and
	 * kept. Ten below PHP_INT_MAX leaves room for code a site deliberately
	 * runs last of all.
	 */
	private const LATE = PHP_INT_MAX - 10;

	/**
	 * Recursive-lock flags, keyed by domain -- load_textdomain() itself
	 * applies the `load_translation_file` filter again for the domain
	 * it's loading, so without this a layer load below would recurse
	 * forever.
	 *
	 * @var array<string,bool>
	 */
	private static $translation_lock = array();

	/**
	 * Directory lang_dir_for_domain() last answered, keyed
	 * "{domain}\0{locale}" -- the one core's just-in-time loader builds
	 * its candidate from (`{dir}{domain}-{locale}.mo`, or `{dir}{locale}.mo`
	 * for a theme's own directory).
	 *
	 * @var array<string,string>
	 */
	private static $jit_dirs = array();

	/**
	 * Original file of the current outer load_textdomain() per domain,
	 * from the `load_textdomain` action (see note_load_textdomain()).
	 *
	 * @var array<string,string>
	 */
	private static $originals = array();

	/**
	 * How many times this class's own is_dir()/is_readable() probes have
	 * run since the last reset_for_tests() -- lets a test assert an
	 * early-return path made zero filesystem calls, not just that it
	 * returned the right value (see probe_is_dir()/probe_is_readable()).
	 * Never read in production.
	 *
	 * @var int
	 */
	private static $fs_probe_count = 0;

	/**
	 * Per-request memo of layer_messages(), keyed "{locale}\0{domain}".
	 * Files do not change within a request, so no invalidation hook.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $messages_cache = array();

	/**
	 * Registers the loading-layer hooks. Safe to call more than once --
	 * only the first call does anything.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		++self::$run_count;

		add_filter( 'lang_dir_for_domain', array( __CLASS__, 'lang_dir_for_domain' ), self::LATE, 3 );
		add_filter( 'load_translation_file', array( __CLASS__, 'load_translation_file' ), self::LATE, 3 );
		add_filter( 'load_script_translation_file', array( __CLASS__, 'load_script_translation_file' ), 10, 3 );
		add_filter( 'load_script_translations', array( __CLASS__, 'load_script_translations' ), 10, 4 );

		add_action( 'after_setup_theme', array( __CLASS__, 'load_default_supplements' ), 10, 0 );
		add_action( 'change_locale', array( __CLASS__, 'load_default_supplements' ), 10, 1 );
		add_action( 'load_textdomain', array( __CLASS__, 'note_load_textdomain' ), 10, 2 );
	}

	/**
	 * `load_textdomain` action: remembers the file core is about to load
	 * for $domain, as given before any filter rewrites it. Babilim's own
	 * nested layer loads (domain locked) are not recorded, so the value
	 * stays the outer load's. load_translation_file() compares its
	 * directory with the just-in-time directory (see $jit_dirs). Records
	 * only; same guards as the other callbacks.
	 *
	 * @param mixed $domain Text domain.
	 * @param mixed $mofile Original `.mo` path.
	 */
	public static function note_load_textdomain( $domain, $mofile ): void {
		try {
			if ( is_string( $domain ) && is_string( $mofile ) && empty( self::$translation_lock[ $domain ] )
				&& Babilim_Paths::is_safe_name( $domain ) && self::root_exists() ) {
				self::$originals[ $domain ] = $mofile;
			}
		} catch ( \Throwable $e ) {
			self::log_error( $e );
		}
	}

	/**
	 * Resets this class's own static state between tests. Never called
	 * in production.
	 */
	public static function reset_for_tests(): void {
		self::$translation_lock = array();
		self::$jit_dirs         = array();
		self::$originals        = array();
		self::$fs_probe_count   = 0;
		self::reset_messages_cache_for_tests();
	}

	/**
	 * Empties the per-request script-translation messages memo.
	 * Test-only.
	 */
	public static function reset_messages_cache_for_tests(): void {
		self::$messages_cache = array();
	}

	/**
	 * How many is_dir()/is_readable() probes this class has made since
	 * the last reset_for_tests(). Test-only.
	 *
	 * @return int
	 */
	public static function fs_probe_count_for_tests(): int {
		return self::$fs_probe_count;
	}

	/**
	 * `lang_dir_for_domain` filter, run late (LATE): a directory core or
	 * another plugin already resolved is returned unchanged, whether or
	 * not it holds a file for this locale -- including core's fallback to
	 * the directory load_plugin_textdomain()/load_theme_textdomain()
	 * registered. Only when nobody resolved one ($path === false) is our
	 * own directory offered: custom/ first, then babilim/, whichever has a
	 * file. When a kept directory has no file, load_translation_file()
	 * answers core's outer load with our file instead, so the domain is
	 * still served (see there). The answer is remembered per
	 * domain+locale: it is the directory core's just-in-time loader builds
	 * its candidate from, which is what limits that fill-in to JIT loads.
	 * Only the last answer is kept (last answer wins): a later lookup for
	 * the same domain+locale replaces it, and a false answer clears it.
	 *
	 * The returned directory always ends with a trailing slash: core's
	 * own JIT loader builds the candidate file path by simple
	 * concatenation, `"{$path}{$domain}-{$locale}.mo"`, exactly the way
	 * its own registered paths already do -- returning a bare directory
	 * here would silently glue the domain onto the last path segment.
	 *
	 * @param string|false $path   The directory resolved so far, or false.
	 * @param string       $domain Text domain.
	 * @param string       $locale Locale code.
	 * @return string|false
	 */
	public static function lang_dir_for_domain( $path, string $domain, string $locale ) {
		$result = $path;
		try {
			$result = self::resolve_lang_dir( $path, $domain, $locale );
			if ( is_string( $result ) && '' !== $result ) {
				self::$jit_dirs[ "{$domain}\0{$locale}" ] = trailingslashit( $result );
			} else {
				unset( self::$jit_dirs[ "{$domain}\0{$locale}" ] );
			}
		} catch ( \Throwable $e ) {
			self::log_error( $e );
		}
		return $result;
	}

	/**
	 * The directory lang_dir_for_domain() answers (see there).
	 *
	 * @param string|false $path   The directory resolved so far, or false.
	 * @param string       $domain Text domain.
	 * @param string       $locale Locale code.
	 * @return string|false
	 */
	private static function resolve_lang_dir( $path, string $domain, string $locale ) {
		if ( false !== $path || ! Babilim_Paths::is_safe_name( $domain ) || ! Babilim_Paths::is_safe_locale( $locale ) ) {
			return $path;
		}
		if ( ! self::root_exists() ) {
			return $path;
		}

		foreach ( self::LAYER_TYPES as $layer_template ) {
			foreach ( self::KINDS as $kind ) {
				$dir = Babilim_Paths::dir( sprintf( $layer_template, $kind ) );
				if ( self::has_mo_or_l10n( "{$dir}/{$domain}-{$locale}" ) ) {
					return trailingslashit( $dir );
				}
			}
		}
		return $path;
	}

	/**
	 * Whether a `.mo` or `.l10n.php` file exists at `{$base}.ext`.
	 *
	 * @param string $base Path without its extension.
	 * @return bool
	 */
	private static function has_mo_or_l10n( string $base ): bool {
		return self::probe_is_readable( "{$base}.mo" ) || self::probe_is_readable( "{$base}.l10n.php" );
	}

	/**
	 * `load_translation_file` filter, run late (LATE) so any rewrite by
	 * another plugin has happened: side-loads a site's `custom/`
	 * override and our own `babilim/` supplement, in that order (each
	 * only when it's actually readable), then returns $file unchanged so
	 * WordPress core still loads its own original file afterwards to
	 * fill whatever gaps remain. The translation controller answers from
	 * the file loaded first, so custom/ wins over babilim/, which wins
	 * over $file.
	 *
	 * Outer fill-in (just-in-time loads only): when core's load of $file
	 * cannot succeed (see has_file_or_sibling()), core's outer
	 * load would fail and core would put NOOP_Translations in place of
	 * everything loaded nested. Then the last readable layer is not
	 * loaded nested but returned as $file, so the outer load succeeds
	 * with it; the layers before it are still loaded nested first. This
	 * applies only when the load's original file lies in the directory
	 * lang_dir_for_domain() answered (is_jit_load()), so an immediate
	 * load that core follows with another attempt (6.6's
	 * load_plugin_textdomain()) is left to fail and move on. The sibling
	 * check keeps a plugin's own `.mo` from being taken over on core's
	 * first, `.l10n.php` pass. The `default` domain never goes through
	 * this layering -- it has its own supplement loader, see
	 * load_default_supplements().
	 *
	 * A recursive lock guards against load_textdomain() itself
	 * re-applying this same filter for the domain it's loading.
	 *
	 * Note: WordPress core applies this filter up to twice per
	 * `load_textdomain()` call -- once for the `.l10n.php` candidate and,
	 * only when that candidate fails to load, again for the `.mo`
	 * candidate. Each call re-runs the full layering below; that's
	 * harmless here, since every individual layer load is independently
	 * guarded by is_readable() and the lock only needs to stop
	 * *reentrant* recursion, not repeat calls.
	 *
	 * $locale defaults to '' -- WordPress core's own load_textdomain()
	 * (wp-includes/l10n.php) applies this filter as `apply_filters(
	 * 'load_translation_file', $file, $domain )`: only TWO arguments, no
	 * $locale, on WordPress 6.5.x (confirmed directly against the real
	 * WP 6.5.12 core source, read out of a real WordPress Playground
	 * boot: `l10n.php:840`). WordPress 6.6.0 added a third argument
	 * (that file's own `@since 6.6.0 Added the $locale parameter`) --
	 * confirmed the same way against real 6.8.10/7.1.2 core
	 * (`l10n.php:843`), which passes all three. Since register()
	 * registers this hook with accepted_args=3, WP_Hook calls this
	 * method with exactly however many real arguments the WordPress
	 * version currently running gives it -- 2 on 6.5.x, 3 on 6.6+. Two
	 * consequences, both needing a default regardless of which version
	 * happens to be running (PHP checks the call actually made, not
	 * what a different version would have made):
	 * - Without a default at all, that's a PHP ArgumentCountError fatal
	 *   on every single request on WordPress 6.5.x, the moment WordPress
	 *   itself boots (load_default_textdomain() is the very first
	 *   caller, from wp-settings.php, before any plugin/theme code).
	 * - A bare `$locale = ''` default alone (this method's first,
	 *   incomplete fix for that fatal) merely avoids the crash --
	 *   Babilim_Paths::is_safe_locale('') is false, so the layering loop
	 *   below would then silently no-op on EVERY real, non-default-domain
	 *   call on 6.5.x, never loading the babilim/ supplement at all. An
	 *   empty $locale therefore falls back to determine_locale() -- the
	 *   same fallback WordPress core's own load_textdomain() uses
	 *   internally for its OWN $locale parameter (`if ( ! $locale ) {
	 *   $locale = determine_locale(); }`), immediately before it applies
	 *   this exact filter. On 6.6+, where core already passes the real
	 *   $locale, this fallback is simply never reached.
	 *
	 * A caller on 6.5.x that explicitly loads a DIFFERENT locale than
	 * the current request's own (`load_textdomain( $domain, $file, 'xx'
	 * )` where `determine_locale() !== 'xx'`) would have been a known
	 * limitation on that version only: 6.5's own two-argument call gives
	 * this method no way to learn that explicit locale, so it would fall
	 * back to determine_locale() instead. Moot now that this plugin's
	 * own `Requires at least` is 6.6 (see readme.txt) -- kept here only
	 * as part of the historical record of why the fallback exists at
	 * all, not as a currently-reachable limitation.
	 *
	 * This fallback is what makes every plugin-domain load actually
	 * resolve a locale instead of silently staying untranslated when
	 * $locale is empty -- verified against a real WordPress Playground
	 * run, not just this repo's own mocked filter dispatch.
	 *
	 * $file is deliberately untyped, not `string` -- it's WordPress
	 * core's own filter value, already possibly rewritten by an earlier
	 * callback on this same filter before this one ever runs (core's own
	 * initial value is `false` when it hasn't found a file itself, and
	 * another plugin's callback could return anything). A `string`
	 * declaration here would throw a TypeError at the call boundary --
	 * before this method's own body, and its try/catch, ever run -- the
	 * moment anything upstream passes a non-string, which this class's
	 * own catch-all-per-callback design (every public method here) is
	 * built to never let happen.
	 *
	 * @param mixed  $file   The file WordPress core (or an earlier
	 *                       callback on this same filter) resolved.
	 * @param string $domain Text domain.
	 * @param string $locale Locale code. Unused when $domain === 'default'; falls back to determine_locale() when empty.
	 * @return mixed
	 */
	public static function load_translation_file( $file, string $domain, string $locale = '' ) {
		if ( 'default' === $domain ) {
			return $file;
		}

		$fill = null;
		try {
			if ( '' === $locale ) {
				$locale = determine_locale();
			}
			if ( ! Babilim_Paths::is_safe_name( $domain ) || ! Babilim_Paths::is_safe_locale( $locale ) ) {
				return $file;
			}
			if ( ! self::root_exists() ) {
				return $file;
			}
			if ( ! empty( self::$translation_lock[ $domain ] ) ) {
				return $file;
			}

			$layers = array();
			foreach ( self::LAYER_TYPES as $layer_template ) {
				foreach ( self::KINDS as $kind ) {
					$layer = self::readable_layer_file( sprintf( $layer_template, $kind ), $domain, $locale );
					if ( null !== $layer ) {
						$layers[] = $layer;
					}
				}
			}
			if ( ! $layers ) {
				return $file;
			}
			if ( is_string( $file ) && self::is_jit_load( $domain, $locale ) && ! self::has_file_or_sibling( $file ) ) {
				$fill = array_pop( $layers );
			}

			self::$translation_lock[ $domain ] = true;
			try {
				foreach ( $layers as $layer ) {
					load_textdomain( $domain, $layer, $locale );
				}
			} finally {
				unset( self::$translation_lock[ $domain ] );
			}
		} catch ( \Throwable $e ) {
			self::log_error( $e );
			self::report_failure( 'loader.translation_file' );
		}
		return $fill ?? $file;
	}

	/**
	 * The file a layer serves for $type/$domain/$locale -- `.mo`
	 * preferred, falling back to `.l10n.php` -- or null when neither is
	 * readable. One file per layer, never both.
	 *
	 * @param string $type   One of Babilim_Paths' DIR_TYPES.
	 * @param string $domain Text domain.
	 * @param string $locale Locale code.
	 * @return string|null
	 */
	private static function readable_layer_file( string $type, string $domain, string $locale ): ?string {
		foreach ( array( 'mo', 'l10n.php' ) as $ext ) {
			$path = Babilim_Paths::file( $type, $domain, $locale, $ext );
			if ( self::probe_is_readable( $path ) ) {
				return $path;
			}
		}
		return null;
	}

	/**
	 * Whether the current outer load is core's just-in-time load: its
	 * original file (from the `load_textdomain` action, before any filter
	 * rewrote it) sits in the directory lang_dir_for_domain() last
	 * answered for this domain+locale. WordPress 6.6's immediate
	 * load_plugin_textdomain()/load_theme_textdomain() first try
	 * WP_LANG_DIR/{plugins,themes}/, which is not that directory, so core
	 * still goes on to the component's own file.
	 *
	 * @param string $domain Text domain.
	 * @param string $locale Locale code.
	 * @return bool
	 */
	private static function is_jit_load( string $domain, string $locale ): bool {
		$dir = self::$jit_dirs[ "{$domain}\0{$locale}" ] ?? null;
		$mo  = self::$originals[ $domain ] ?? null;
		return null !== $dir && null !== $mo && trailingslashit( dirname( $mo ) ) === $dir;
	}

	/**
	 * Whether core's load of $file can succeed without the fill-in. Core
	 * tries `.l10n.php` first, then `.mo`; with translation_file_format
	 * `mo` it tries only `.mo`. So a `.l10n.php` candidate counts as
	 * present when it or its `.mo` sibling is readable (the `.mo` pass
	 * still follows), while a `.mo` candidate is core's last try and
	 * counts only itself.
	 *
	 * @param string $file Candidate path core or an earlier filter gave.
	 * @return bool
	 */
	private static function has_file_or_sibling( string $file ): bool {
		if ( self::probe_is_readable( $file ) ) {
			return true;
		}
		return str_ends_with( $file, '.l10n.php' ) && self::probe_is_readable( substr( $file, 0, -9 ) . '.mo' );
	}

	/**
	 * `load_script_translation_file` filter: only ever substitutes our own
	 * custom/ or babilim/ JSON file (custom winning over babilim) when
	 * $file === false, i.e. core has exhausted every candidate of its own.
	 *
	 * WordPress core's load_script_textdomain() tries several candidate
	 * paths in turn -- a plugin-supplied $path first, then
	 * WP_LANG_DIR/plugins/{domain}-{locale}-{md5}.json (where a wp.org
	 * JSON language pack lands) -- applying this filter to each one and
	 * stopping at the first candidate that survives as non-false and
	 * readable. Substituting on an unreadable-but-non-false candidate
	 * would pre-empt every later candidate, including one of core's own
	 * that actually is readable -- so any $file that isn't false, readable
	 * or not, is returned unchanged and core moves on to try its next
	 * candidate itself. Layering our own content over whatever core
	 * eventually reads happens separately, in load_script_translations().
	 *
	 * @param string|false $file   The file WordPress core resolved, or false.
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public static function load_script_translation_file( $file, string $handle, string $domain ) {
		try {
			if ( false !== $file ) {
				return $file;
			}
			$path = self::find_script_layer_file( $domain );
			if ( null !== $path ) {
				return $path;
			}
		} catch ( \Throwable $e ) {
			self::log_error( $e );
		}
		return $file;
	}

	/**
	 * `load_script_translations` filter: merges our own custom/ and
	 * babilim/ JSON catalogs' messages on top of whatever $translations
	 * already carries (ours winning on a colliding msgid). The one
	 * exception is the `""` header entry (domain + plural-forms): core's
	 * own header is kept whenever core has one, and ours is only used as
	 * a fallback when core's catalog carries none at all -- unlike an
	 * ordinary msgid, the header isn't really "our" string to win.
	 *
	 * WordPress core passes this filter the *raw JSON string* it read
	 * from the script translation file, not a decoded array -- it's
	 * re-encoded and handed to the browser exactly as returned here. A
	 * string input is decoded, merged, and re-encoded with
	 * wp_json_encode(); a caller that already passes a decoded array
	 * (as our own tests using $ours directly do) gets an array back the
	 * same way, so this never forces a shape change the caller didn't
	 * already use.
	 *
	 * The Jed catalog's messages live under `locale_data.messages` in the
	 * common case, but some catalogs key them by the text domain itself
	 * (`locale_data.{$domain}`) instead -- both shapes are merged into,
	 * matching whichever one $translations already uses.
	 *
	 * @param mixed|false  $translations Raw JSON string (as WordPress core
	 *                                   passes) or already-decoded array, or false.
	 * @param string|false $file        The file that was loaded, or false.
	 * @param string       $handle      Script handle.
	 * @param string       $domain      Text domain.
	 * @return mixed|false
	 */
	public static function load_script_translations( $translations, $file, string $handle, string $domain ) {
		try {
			$ours = self::layer_messages( $domain );
			if ( empty( $ours ) ) {
				return $translations;
			}

			$decoded = is_string( $translations ) ? json_decode( $translations, true ) : $translations;
			if ( ! is_array( $decoded ) ) {
				return $translations;
			}

			$locale_data = is_array( $decoded['locale_data'] ?? null ) ? $decoded['locale_data'] : array();
			$key         = self::messages_key( $locale_data, $domain );
			$core        = is_array( $locale_data[ $key ] ?? null ) ? $locale_data[ $key ] : array();

			$merged = $ours + $core;
			if ( array_key_exists( '', $core ) ) {
				$merged[''] = $core[''];
			}

			$locale_data[ $key ]     = $merged;
			$decoded['locale_data'] = $locale_data;

			return is_string( $translations ) ? wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) : $decoded;
		} catch ( \Throwable $e ) {
			self::log_error( $e );
			self::report_failure( 'loader.script_translations' );
		}
		return $translations;
	}

	/**
	 * Which key under `locale_data` a Jed catalog keeps its messages in:
	 * `messages` when present, else the domain name itself (see
	 * load_script_translations()'s docblock), defaulting to `messages`
	 * when the catalog has neither yet (a fresh key to merge ours into).
	 *
	 * @param array<string,mixed> $locale_data Decoded `locale_data` object.
	 * @param string              $domain      Text domain.
	 * @return string
	 */
	private static function messages_key( array $locale_data, string $domain ): string {
		if ( isset( $locale_data['messages'] ) ) {
			return 'messages';
		}
		if ( isset( $locale_data[ $domain ] ) ) {
			return $domain;
		}
		return 'messages';
	}

	/**
	 * The first readable custom/babilim JSON file for $domain at the
	 * current locale, in priority order, or null if we have none.
	 *
	 * @param string $domain Text domain.
	 * @return string|null
	 */
	private static function find_script_layer_file( string $domain ): ?string {
		if ( ! Babilim_Paths::is_safe_name( $domain ) || ! self::root_exists() ) {
			return null;
		}
		$locale = determine_locale();
		if ( ! Babilim_Paths::is_safe_locale( $locale ) ) {
			return null;
		}

		foreach ( self::LAYER_TYPES as $layer_template ) {
			foreach ( self::KINDS as $kind ) {
				$candidate = Babilim_Paths::file( sprintf( $layer_template, $kind ), $domain, $locale, 'json' );
				if ( self::probe_is_readable( $candidate ) ) {
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * Every custom/babilim JSON layer's `locale_data.messages` for
	 * $domain at the current locale, merged with custom winning over
	 * babilim. Empty if we have neither. (Our own catalogs always use
	 * the `messages` key -- only the *core* catalog we merge onto, in
	 * load_script_translations(), might use the domain-keyed shape.)
	 *
	 * @param string $domain Text domain.
	 * @return array<string,mixed>
	 */
	private static function layer_messages( string $domain ): array {
		if ( ! Babilim_Paths::is_safe_name( $domain ) ) {
			return array();
		}
		$locale = determine_locale();
		if ( ! Babilim_Paths::is_safe_locale( $locale ) ) {
			return array();
		}

		// No root directory: nothing at all, remembered or not.
		if ( ! self::root_exists() ) {
			return array();
		}
		$cache_key = $locale . "\0" . $domain;
		if ( isset( self::$messages_cache[ $cache_key ] ) ) {
			return self::$messages_cache[ $cache_key ];
		}

		$messages = array();
		foreach ( self::LAYER_TYPES as $layer_template ) {
			foreach ( self::KINDS as $kind ) {
				$candidate = Babilim_Paths::file( sprintf( $layer_template, $kind ), $domain, $locale, 'json' );
				if ( ! self::probe_is_readable( $candidate ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a plain read of a local translation file on a hot loading path, as WordPress core's own JSON translation loader does; no WP_Filesystem set-up is possible here.
				$decoded        = json_decode( (string) file_get_contents( $candidate ), true );
				$layer_messages = $decoded['locale_data']['messages'] ?? null;
				if ( is_array( $layer_messages ) ) {
					$messages = $messages + $layer_messages;
				}
			}
		}
		self::$messages_cache[ $cache_key ] = $messages;
		return $messages;
	}

	/**
	 * `after_setup_theme`/`change_locale` action: loads the `default`
	 * text domain's supplement, always *after* WordPress core's own
	 * load_default_textdomain() has already run. Deliberately has no
	 * "locale unchanged, skip" guard -- every call re-runs the load; that
	 * shortcut was considered and rejected, since a `change_locale` call
	 * always means the supplement for the new locale still needs loading.
	 *
	 * Loads at most one file: the single merged-per-locale index at
	 * Babilim_Paths::merged_default() -- never a glob over individual
	 * `default/_parts/` fragments, and never reads `_manifest.json`.
	 * Prefers `.mo`, falling back to `.l10n.php`; the `return` after the
	 * `.mo` branch is what keeps this to exactly one load when both
	 * exist.
	 *
	 * @param string|null $locale Locale code, or null to use the current one.
	 */
	public static function load_default_supplements( $locale = null ): void {
		try {
			$locale ??= determine_locale();
			if ( ! Babilim_Paths::is_safe_locale( $locale ) ) {
				return;
			}
			if ( ! self::root_exists() ) {
				return;
			}

			$mo = Babilim_Paths::merged_default( $locale, 'mo' );
			if ( self::probe_is_readable( $mo ) ) {
				load_textdomain( 'default', $mo, $locale );
				return;
			}
			$php = Babilim_Paths::merged_default( $locale, 'l10n.php' );
			if ( self::probe_is_readable( $php ) ) {
				load_textdomain( 'default', $php, $locale );
			}
		} catch ( \Throwable $e ) {
			self::log_error( $e );
		}
	}

	/**
	 * Whether Babilim_Paths::root() currently exists -- every callback
	 * in this class no-ops entirely when it doesn't.
	 *
	 * @return bool
	 */
	private static function root_exists(): bool {
		return self::probe_is_dir( Babilim_Paths::root() );
	}

	/**
	 * is_dir(), counted -- see $fs_probe_count.
	 *
	 * @param string $path Path to check.
	 * @return bool
	 */
	private static function probe_is_dir( string $path ): bool {
		++self::$fs_probe_count;
		return is_dir( $path );
	}

	/**
	 * is_readable(), counted -- see $fs_probe_count.
	 *
	 * @param string $path Path to check.
	 * @return bool
	 */
	private static function probe_is_readable( string $path ): bool {
		++self::$fs_probe_count;
		return is_readable( $path );
	}

	/**
	 * Records a load failure for the error report queue; never throws.
	 *
	 * @param string $origin_id Catalog origin id.
	 */
	private static function report_failure( string $origin_id ): void {
		try {
			Babilim_Error_Reporter::for_site()->capture( 'load_failed', 'load', $origin_id );
		} catch ( \Throwable $e ) {
			unset( $e ); // Reporting must never break a page load.
		}
	}

	/**
	 * Logs a caught Throwable without ever letting logging itself fatal
	 * a request. Gated on WP_DEBUG so a persistently corrupt file (e.g. a
	 * hand-tampered supplement) can't spam the log on every request --
	 * matches how WordPress core itself expects noisy diagnostics to be
	 * opt-in (see the wp-plugin-development skill's debugging guidance:
	 * check WP_DEBUG_LOG-routed error logs, not unconditional output).
	 *
	 * @param \Throwable $e The caught exception/error.
	 */
	private static function log_error( \Throwable $e ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( function_exists( 'error_log' ) ) {
			error_log( '[babilim] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- only reached when WP_DEBUG is on.
		}
	}
}
