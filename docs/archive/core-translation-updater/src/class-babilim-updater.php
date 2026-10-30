<?php
/**
 * Exposes the service's pending language-pack updates (Babilim_State::
 * updates(), already computed server-side -- this class never compares
 * versions or build numbers itself) to WordPress core's own translation
 * update machinery, and adopts a downloaded package once core has fetched
 * it.
 *
 * Core's own `update_plugins`/`update_themes` site transients carry a
 * `translations[]` array alongside their plugin/theme `response[]`
 * (see wp-includes/update.php's wp_get_translation_updates(), which
 * reads exactly that property off both transients) -- inject() appends
 * to it rather than replacing it, so a real wp.org language-pack update
 * already present keeps working untouched.
 *
 * Every entry this class injects carries a `babilim` marker property
 * plus a `package` URL under a dedicated marker path on the configured
 * API base -- the only way any hook here ever recognizes "one of ours".
 * Neither the marker nor the identification logic ever inspects slug or
 * locale, so a real wp.org entry (which never carries either) can never
 * be mistaken for one of this plugin's own.
 *
 * The actual package fetch goes through core's own download_url()
 * (wp-admin/includes/file.php) rather than any custom HTTP client --
 * upgrader_pre_download's short-circuit lets this class skip wp.org's
 * package-signature verification (which has no way to verify a
 * signature for a package this plugin's own service signs, not wp.org)
 * while still going through the same HTTP machinery every other core
 * update download uses.
 *
 * `Language_Pack_Upgrader::bulk_upgrade()` -- the entry point every real
 * translation-update path reaches (`upgrade()`, `async_upgrade()`, and
 * WordPress's own background auto-updater, all of which end up calling
 * it) -- removes every filter already registered on
 * `upgrader_post_install` and `upgrader_source_selection` before its
 * per-item loop even starts (its own `remove_all_filters()` block, right
 * before it re-adds only its own `check_package()`, at priority 10, to
 * `upgrader_source_selection`). A callback registered on either of those
 * hooks at `register()` time -- once, at plugin load -- would already be
 * gone by the time any real item is processed. This class therefore
 * never touches `upgrader_post_install`, and only ever adds its
 * `upgrader_source_selection` callback *inside* pre_download() -- called
 * per item, from `WP_Upgrader::download_package()`, strictly after
 * `bulk_upgrade()`'s own removal has already happened -- at priority 20,
 * after `check_package()`'s own 10. The same per-item call to
 * `WP_Upgrader::run()` applies the `upgrader_source_selection` filter
 * shortly afterward, from `install_package()`, so the callback this
 * class just added is guaranteed to still be registered by the time it's
 * needed. It's supposed to remove itself, win or lose, the moment a
 * matching call arrives (see source_selection()) -- but if that same
 * item's own `install_package()` is never reached at all (its package
 * fails to unzip, say), nothing ever consumes it. pre_download() itself
 * is what actually guarantees it's gone by the time it matters: it
 * clears any leftover watch unconditionally, before even checking
 * whether the *current* item is one of this plugin's own, so it's gone
 * by the very next `upgrader_pre_download` call at all -- real wp.org
 * item or not, not only the next one that happens to also be one of
 * this plugin's own (see pre_download()'s own docblock).
 *
 * (Line numbers for every core function named above and below are
 * deliberately omitted -- they drift across WordPress versions, and this
 * class's own tests run against one pinned core tag, not a specific
 * revision of these docblocks.)
 *
 * pre_download() only short-circuits the download itself (still through
 * core's own download_url(), never a custom fetch of the actual zip
 * bytes); source_selection() is what stops the zip's contents -- the
 * `.po`/`.mo`/`.json`, `manifest.json`, and the `default/` subdirectory
 * package-layout.md describes -- from ever being copied into the real,
 * shared `WP_LANG_DIR/plugins`/`themes` directory core would otherwise
 * write to (`WP_Upgrader::install_package()`'s own `move_dir()`/
 * `copy_dir()` decision, applied to whatever `upgrader_source_selection`
 * returned). Instead it reads every file the package's own manifest.json
 * declares directly out of core's already-unzipped working directory
 * (reading there is fine; anything this class itself persists always
 * goes through Babilim_Fs, and only under
 * Babilim_Paths::adopt_staging_dir()), stages them flat under a fresh
 * `_adopt/{token}` directory (default-domain fragments renamed to the
 * `default__{name}` convention that area's own reader expects), and
 * calls Babilim_Installer::adopt() -- which is what actually verifies
 * every file's sha256, entry count, and shape before anything reaches a
 * served directory. On success it then hands core back a freshly created,
 * empty directory instead of the one holding the real zip contents: core
 * copies whatever `upgrader_source_selection` returns (the same
 * `move_dir()`/`copy_dir()` decision above), so an empty directory means
 * zero files reach `WP_LANG_DIR`, and `run()` still sees a normal
 * (non-error) result, so the admin UI reports success. That directory is
 * created one level inside `$remote_source` -- the exact path
 * `install_package()` itself deletes, recursively
 * (`$wp_filesystem->delete( $remote_source, true )`), once
 * `clear_working` is true, unconditional on the copy's own outcome -- so
 * it, and every real file this class read out of the original working
 * directory, is cleaned up by core itself; this class never deletes
 * anything under core's own working directory. On failure it returns a
 * WP_Error before any copy happens (`install_package()`'s own
 * `is_wp_error( $source )` check, immediately after applying that same
 * filter), which is the one case where the admin UI correctly shows a
 * failure.
 *
 * Once `Babilim_Installer::adopt()` has actually succeeded AND core's own
 * `upgrader_install_package_result` filter confirms the rest of
 * `install_package()` didn't itself fail afterward (see
 * install_package_result() below), the just-installed entry is removed
 * from `Babilim_State::updates()` by build id (an identity match, never a
 * version/build-number comparison -- see that method's own docblock) so
 * the next `wp_clean_update_cache()` core itself runs at the end of
 * bulk_upgrade() doesn't see the same build re-offered.
 *
 * source_selection() returning an empty directory only stops core from
 * copying *this plugin's own* zip contents into `WP_LANG_DIR`; it does
 * nothing about `Language_Pack_Upgrader::clear_destination()`, which
 * `WP_Upgrader::install_package()` calls *before* that copy step against
 * whatever `$options['destination']` resolved to -- unredirected, that's
 * the real, shared `WP_LANG_DIR/plugins`/`themes` directory
 * (`Language_Pack_Upgrader::bulk_upgrade()`'s own per-item `$options`
 * array), and `clear_destination()` deletes exactly
 * `{slug}-{language}.po/.mo/.l10n.php` and `{slug}-{language}-*.json`
 * there -- a real wp.org language pack for the very same slug/locale
 * this plugin's own item is updating, gone, every single time this
 * plugin's own item succeeds. `package_options()` prevents this: for one
 * of this plugin's own items only, it redirects `$options['destination']`
 * (applied by `WP_Upgrader::run()`'s own `apply_filters(
 * 'upgrader_package_options', $options )` call -- a filter
 * `bulk_upgrade()`'s own `remove_all_filters()` block never touches) to a
 * fresh, empty `Babilim_Paths::incoming_dir()` inside this plugin's own
 * allow-list. `clear_destination()` then only ever finds nothing to
 * delete there, and the later copy (of this class's own empty
 * `source_selection()` result) writes nothing there either.
 * `install_package_result()` removes that now-still-empty directory once
 * the item is fully done, win or lose.
 *
 * process_complete() no longer exists on this class: every trigger-point
 * it would have reacted to (a plugin/theme body update, a translation
 * batch) is already covered, exactly once, by
 * Babilim::on_upgrader_process_complete() -- adding a second listener
 * here would rebuild the same index twice per event for no benefit.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects the service's pending updates into core's translation-update
 * flow and adopts a downloaded package once core has fetched it.
 */
final class Babilim_Updater implements Babilim_Updater_Contract {

	/**
	 * The path segment marking a `package` URL, on the configured API
	 * base, as one of this plugin's own injected entries -- belt and
	 * suspenders alongside the `babilim` marker property itself (see the
	 * class docblock): a real wp.org translation entry's own `package`
	 * is always a downloads.wordpress.org URL, never this plugin's own
	 * API base, so this can never collide with one by accident.
	 *
	 * @var string
	 */
	private const PACKAGE_MARKER_SEGMENT = '/babilim-package/';

	/**
	 * @var Babilim_State
	 */
	private $state;

	/**
	 * @var Babilim_Installer
	 */
	private $installer;

	/**
	 * @var Babilim_Api_Client
	 */
	private $api;

	/**
	 * @var Babilim_Fs
	 */
	private $fs;

	/**
	 * The API base, e.g. BABILIM_API_BASE. No trailing slash required.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * The one item pre_download() is currently watching for on
	 * `upgrader_source_selection` -- null whenever nothing is pending
	 * (see the class docblock for why this is set per item, never at
	 * `register()` time). Keyed by `build_id` (the entry's own identity)
	 * plus the transient entry object itself.
	 *
	 * @var array{build_id: string, translation: object}|null
	 */
	private $pending = null;

	/**
	 * The destination redirect package_options() set up for the one item
	 * currently in flight through `run()` -- null whenever nothing is
	 * redirected. Set by package_options() (the earliest of this class's
	 * own hooks to fire, applied by `WP_Upgrader::run()` itself before
	 * `download_package()`/`install_package()` are ever reached), read
	 * and cleared by install_package_result() (the latest -- fires
	 * unconditionally per item and is never removed by `bulk_upgrade()`);
	 * `adopted` is flipped to true by source_selection() itself, in
	 * between, once Babilim_Installer::adopt() has actually succeeded.
	 *
	 * @var array{build_id: string, dir: string, adopted: bool}|null
	 */
	private $incoming = null;

	/**
	 * @param Babilim_State      $state     Sync state -- source of updates().
	 * @param Babilim_Installer  $installer Verifies and installs a downloaded package.
	 * @param Babilim_Api_Client $api       Bound to this site's token; used for POST /v1/downloads.
	 * @param Babilim_Fs         $fs        Filesystem to stage an adopt() directory through.
	 * @param string             $base_url  The API base.
	 */
	public function __construct( Babilim_State $state, Babilim_Installer $installer, Babilim_Api_Client $api, Babilim_Fs $fs, string $base_url ) {
		$this->state     = $state;
		$this->installer = $installer;
		$this->api       = $api;
		$this->fs        = $fs;
		$this->base_url  = $base_url;
	}

	/**
	 * Hooks every filter this class needs. Deliberately never touches
	 * `upgrader_post_install` or registers on `upgrader_source_selection`
	 * here -- see the class docblock for why either would be removed
	 * before a real translation update ever reaches it. `upgrader_
	 * package_options` and `upgrader_install_package_result` are safe to
	 * register here (once, at load time): neither is in
	 * `Language_Pack_Upgrader::bulk_upgrade()`'s own `remove_all_filters()`
	 * list.
	 *
	 * Never actually called by the production assembly class
	 * (`Babilim::register_updater_hooks()`, which proxies each of these
	 * same five filters itself, one add_filter() call per hook, so that
	 * building a full Babilim_Updater instance can stay deferred until
	 * one of them really fires -- see that method's own docblock). This
	 * method exists as this class's own directly-testable statement of
	 * every filter it needs; keeping it and register_updater_hooks() in
	 * sync is manual, guarded by a regression test that fails if either
	 * one ever hooks a filter the other doesn't.
	 */
	public function register(): void {
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_plugin_transient' ) );
		add_filter( 'site_transient_update_themes', array( $this, 'inject_theme_transient' ) );
		add_filter( 'upgrader_package_options', array( $this, 'package_options' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 4 );
		add_filter( 'upgrader_install_package_result', array( $this, 'install_package_result' ), 10, 2 );
	}

	/**
	 * Removes every entry this plugin has ever injected (the `babilim`
	 * marker property -- see inject(), above) from the real,
	 * currently-stored `update_plugins`/`update_themes` site transients,
	 * and writes each one back only when something actually changed.
	 *
	 * Deactivation and uninstall are the only two callers (babilim.php's
	 * babilim_deactivate() and uninstall.php): once this plugin's
	 * filters are no longer registered, core's own next
	 * wp_update_plugins()/wp_update_themes() run would otherwise leave a
	 * stale `babilim => true` entry sitting in the transient core just
	 * wrote back (see inject()'s own docblock on why core writes the
	 * filtered value back before this filter can run again) -- WordPress
	 * core's own translation-update UI, and its background auto-updater
	 * (`autoupdate => 1`, on every entry this plugin ever injects), would
	 * then still try to fetch and install it, downloading from this
	 * plugin's own service after the site owner's consent that made that
	 * legitimate is already gone.
	 *
	 * A static, dependency-free method on purpose: unlike every other
	 * method on this class, it needs no Babilim_State/Babilim_Installer/
	 * Babilim_Api_Client/Babilim_Fs collaborator, and deactivation and
	 * uninstall.php both need to call it in contexts where building a
	 * full Babilim_Updater instance would be unnecessary overhead (in
	 * uninstall.php's case, this file isn't even required for anything
	 * else -- only this one method is used).
	 *
	 * @param string[] $transient_names Site-transient names to clean.
	 *                                  Defaults to both core translation
	 *                                  transients; a test can narrow this.
	 * @return void
	 */
	public static function strip_injected_translations( array $transient_names = array( 'update_plugins', 'update_themes' ) ): void {
		foreach ( $transient_names as $transient_name ) {
			$transient = get_site_transient( $transient_name );
			if ( ! is_object( $transient ) || ! isset( $transient->translations ) || ! is_array( $transient->translations ) ) {
				continue;
			}

			$original_count = count( $transient->translations );
			$transient->translations = array_values(
				array_filter(
					$transient->translations,
					static function ( $entry ) {
						return ! ( is_object( $entry ) && ! empty( $entry->babilim ) );
					}
				)
			);

			if ( count( $transient->translations ) !== $original_count ) {
				set_site_transient( $transient_name, $transient );
			}
		}
	}

	/**
	 * `site_transient_update_plugins` callback.
	 *
	 * @param mixed $transient The stored transient value, or false before core has ever populated it.
	 * @return object
	 */
	public function inject_plugin_transient( $transient ): object {
		return $this->inject( is_object( $transient ) ? $transient : new stdClass(), 'plugin' );
	}

	/**
	 * `site_transient_update_themes` callback.
	 *
	 * @param mixed $transient The stored transient value, or false before core has ever populated it.
	 * @return object
	 */
	public function inject_theme_transient( $transient ): object {
		return $this->inject( is_object( $transient ) ? $transient : new stdClass(), 'theme' );
	}

	/**
	 * Appends every $state->updates() entry of the given $type to
	 * $transient->translations, leaving every other property (including
	 * any existing translations[] entries a real wp.org check already
	 * populated) untouched. Injects $state->updates() as-is: the service
	 * has already decided this is the current active build for a
	 * (component, locale) this site has installed, including the case
	 * where that's an older build_no than what's currently installed
	 * (the service rolling its own active build back) -- this method
	 * never compares versions or build numbers to decide whether to
	 * inject an entry.
	 *
	 * @param object $transient The site_transient_update_plugins/_themes value.
	 * @param string $type      'plugin'|'theme'.
	 * @return object The same object, mutated.
	 */
	public function inject( object $transient, string $type ): object {
		if ( ! isset( $transient->translations ) || ! is_array( $transient->translations ) ) {
			$transient->translations = array();
		}

		// Idempotent: strip any of this plugin's own entries a previous
		// call already appended before appending the current set fresh.
		// Core writes the filtered transient back with
		// set_site_transient() before the next read re-applies this same
		// filter (wp-includes/update.php's wp_update_plugins()/
		// wp_update_themes()), so appending blindly every time would
		// duplicate every entry already there.
		$transient->translations = array_values(
			array_filter(
				$transient->translations,
				static function ( $entry ) {
					return ! ( is_object( $entry ) && ! empty( $entry->babilim ) );
				}
			)
		);

		$kind = 'theme' === $type ? 'theme' : 'plugin';
		foreach ( $this->state->updates() as $update ) {
			if ( ( $update['kind'] ?? null ) !== $kind ) {
				continue;
			}
			$slug     = (string) ( $update['slug'] ?? '' );
			$locale   = (string) ( $update['locale'] ?? '' );
			$build_id = (string) ( $update['build_id'] ?? '' );
			if ( '' === $slug || '' === $locale || '' === $build_id ) {
				continue;
			}

			$transient->translations[] = (object) array(
				'type'       => $kind,
				'slug'       => $slug,
				'language'   => $locale,
				'version'    => '',
				'updated'    => '',
				'package'    => $this->package_url( $build_id ),
				'autoupdate' => 1,
				'babilim'    => true,
				'build_id'   => $build_id,
				'build_no'   => (int) ( $update['build_no'] ?? 0 ),
			);
		}

		return $transient;
	}

	/**
	 * `upgrader_package_options` callback: for one of this plugin's own
	 * translation items only, redirects `$options['destination']` away
	 * from the real, shared `WP_LANG_DIR/plugins`/`themes` directory to a
	 * fresh, empty `Babilim_Paths::incoming_dir()` this call itself
	 * creates via `Babilim_Fs` (see the class docblock for exactly why:
	 * `Language_Pack_Upgrader::clear_destination()` runs against this
	 * value before this class's own `source_selection()` result is ever
	 * copied). Every other item's `$options` passes through untouched.
	 * The directory's creation is best-effort (logged, never fatal) --
	 * even if it fails, redirecting `destination` still protects the
	 * real directory, since `clear_destination()` only ever deletes a
	 * file that already `exists()` at the (redirected, so unpopulated)
	 * location.
	 *
	 * @param array $options WP_Upgrader::run()'s own $options.
	 * @return array
	 */
	public function package_options( array $options ): array {
		try {
			$hook_extra  = is_array( $options['hook_extra'] ?? null ) ? $options['hook_extra'] : array();
			$translation = $hook_extra['language_update'] ?? null;
			$package     = (string) ( $options['package'] ?? '' );
			if ( ! $this->is_ours( $translation, $package ) ) {
				return $options;
			}
			$build_id = (string) ( $translation->build_id ?? '' );
			if ( '' === $build_id ) {
				return $options;
			}

			$dir = Babilim_Paths::incoming_dir( self::fresh_incoming_token() );
			if ( ! $this->fs->mkdir( $dir ) ) {
				$this->log_error( new RuntimeException( "Could not pre-create incoming directory: {$dir}" ) );
			}

			$this->incoming          = array(
				'build_id' => $build_id,
				'dir'      => $dir,
				'adopted'  => false,
			);
			$options['destination'] = $dir;
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
		return $options;
	}

	/**
	 * `upgrader_pre_download` callback: for one of this plugin's own
	 * translation items, exchanges its build_id for a fresh signed
	 * package URL (POST /v1/downloads) and fetches it through core's own
	 * download_url() -- never a custom HTTP fetch of the package itself
	 * (see the class docblock). Only once that download has actually
	 * succeeded does this register the per-item `upgrader_source_selection`
	 * watch (see source_selection()) -- a failed exchange/download never
	 * leaves anything registered for install_package() to find. Every
	 * other item's $reply passes through unchanged, exactly as core's own
	 * default (no short-circuit) would.
	 *
	 * A previous item's own watch is cleared here unconditionally, before
	 * even checking whether *this* item is one of this plugin's own --
	 * not only in the "ours" branch. If that previous item's own
	 * `install_package()` was never reached at all (e.g. its package
	 * failed to unzip, between a successful download and
	 * `upgrader_source_selection` ever being applied -- core exposes no
	 * filter at that exact point to hook a narrower cleanup to), its
	 * watch is never consumed by source_selection(), and would otherwise
	 * still be registered here. Clearing it unconditionally means it's
	 * gone by the very next item this method runs for at all, real
	 * wp.org item or not -- not only the next one that happens to also be
	 * one of this plugin's own.
	 *
	 * @param mixed  $reply      False (the default -- "do the normal
	 *                           signature-checked download"), or another
	 *                           filter's already-short-circuited value.
	 * @param string $package    The transient entry's own `package` URL.
	 * @param mixed  $upgrader   The WP_Upgrader instance (unused).
	 * @param array  $hook_extra Extra data; `language_update` is the
	 *                           transient entry this download is for.
	 * @return mixed
	 */
	public function pre_download( $reply, string $package, $upgrader, array $hook_extra ) {
		try {
			if ( null !== $this->pending ) {
				remove_filter( 'upgrader_source_selection', array( $this, 'source_selection' ), 20 );
				$this->pending = null;
			}

			$translation = $hook_extra['language_update'] ?? null;
			if ( ! $this->is_ours( $translation, $package ) ) {
				return $reply;
			}

			if ( ! $this->has_consent() ) {
				return new WP_Error( 'babilim_no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
			}

			$build_id = (string) ( $translation->build_id ?? '' );
			if ( '' === $build_id ) {
				return new WP_Error( 'babilim_invalid_update', __( 'This update is missing a build id.', 'babilim' ) );
			}

			$download = $this->api->download( $build_id );
			if ( is_wp_error( $download ) ) {
				return $download;
			}
			$url = $this->resolve_location( (string) ( $download['location'] ?? '' ) );
			if ( '' === $url ) {
				return new WP_Error( 'babilim_invalid_response', __( 'The download response was missing a location.', 'babilim' ) );
			}
			if ( ! function_exists( 'download_url' ) ) {
				return new WP_Error( 'babilim_download_unavailable', __( 'download_url() is not available.', 'babilim' ) );
			}
			$downloaded = download_url( $url );
			if ( is_wp_error( $downloaded ) ) {
				return $downloaded;
			}

			$this->pending = array(
				'build_id'    => $build_id,
				'translation' => $translation,
			);
			add_filter( 'upgrader_source_selection', array( $this, 'source_selection' ), 20, 4 );

			return $downloaded;
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			// The exception's own message is a developer-facing detail
			// (a file path, a raw HTTP error, ...) that this plugin never
			// wrote as a user-facing string -- it goes to the debug log
			// via log_error() above, never straight into core's own
			// update screen, which shows whatever this returns.
			return new WP_Error( 'babilim_pre_download_failed', __( 'Could not download the translation package.', 'babilim' ) );
		}
	}

	/**
	 * `upgrader_source_selection` callback, added dynamically by
	 * pre_download() for exactly one item at a time (see the class
	 * docblock for why this can never be registered any earlier). Every
	 * call whose $hook_extra doesn't match the one item currently
	 * pending returns $source untouched -- including a real wp.org item,
	 * and including any call after this class's own item has already
	 * been handled once. The pending watch is always cleared, and this
	 * callback always removes itself, the moment a matching call arrives
	 * -- win or lose, and before any work is attempted -- so a Throwable
	 * partway through can never leave it registered for the next item.
	 *
	 * @param string|WP_Error $source        File source location core
	 *                                       computed, or a WP_Error
	 *                                       another filter (or core's own
	 *                                       check_package()) already
	 *                                       produced.
	 * @param string          $remote_source Remote file source location
	 *                                       -- the same directory core
	 *                                       itself deletes, recursively,
	 *                                       once this call returns (see
	 *                                       the class docblock).
	 * @param mixed           $upgrader      The WP_Upgrader instance (unused).
	 * @param array           $hook_extra    Extra data; `language_update`
	 *                                       is the transient entry this
	 *                                       install is for.
	 * @return string|WP_Error
	 */
	public function source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( null === $this->pending || ! $this->matches_pending( $hook_extra ) ) {
			return $source;
		}
		$pending        = $this->pending;
		$this->pending  = null;
		remove_filter( 'upgrader_source_selection', array( $this, 'source_selection' ), 20 );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		try {
			return $this->adopt_from_source( $pending, (string) $remote_source, (string) $source );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			// See pre_download()'s own catch above for why the exception's
			// own message never reaches this return value.
			return new WP_Error( 'babilim_adopt_failed', __( 'Could not install the downloaded translation package.', 'babilim' ) );
		}
	}

	/**
	 * Whether $hook_extra identifies the one item pending() currently
	 * names -- compared by build_id, the entry's own identity, never by
	 * object reference (core may have cloned or rebuilt the hook_extra
	 * array between pre_download() and here).
	 *
	 * @param array $hook_extra Extra data from the firing hook.
	 * @return bool
	 */
	private function matches_pending( array $hook_extra ): bool {
		$translation = $hook_extra['language_update'] ?? null;
		if ( ! is_object( $translation ) || null === $this->pending ) {
			return false;
		}
		return ( (string) ( $translation->build_id ?? '' ) ) === $this->pending['build_id'];
	}

	/**
	 * `upgrader_install_package_result` callback (`WP_Upgrader::run()`'s
	 * own `apply_filters( 'upgrader_install_package_result', ... )` call)
	 * -- fires once per item, unconditionally on whether
	 * `install_package()` itself just succeeded or failed, and is never
	 * removed by `bulk_upgrade()` (see the class docblock). For the one
	 * item package_options() redirected (matched by build_id, the same
	 * pattern matches_pending() uses): removes the now-empty
	 * `incoming_dir()` via Babilim_Fs, and, only if source_selection()
	 * itself already marked this build adopted AND $result is not a
	 * WP_Error (core's own later steps -- clear_destination, the copy,
	 * mkdir -- didn't themselves fail), removes the entry from
	 * Babilim_State::updates() -- waiting for this confirmation, rather
	 * than doing it the moment adopt() itself succeeds, avoids reporting
	 * success and forgetting the update when adopt() succeeded but a
	 * later core step didn't. Every other item's $result passes through
	 * unchanged.
	 *
	 * @param array|WP_Error $result     WP_Upgrader::install_package()'s own result.
	 * @param array          $hook_extra Extra data; `language_update` is
	 *                                   the transient entry this install
	 *                                   was for.
	 * @return array|WP_Error
	 */
	public function install_package_result( $result, array $hook_extra ) {
		try {
			$translation = $hook_extra['language_update'] ?? null;
			if ( ! is_object( $translation ) || null === $this->incoming ) {
				return $result;
			}
			$build_id = (string) ( $translation->build_id ?? '' );
			if ( '' === $build_id || $build_id !== $this->incoming['build_id'] ) {
				return $result;
			}

			$incoming       = $this->incoming;
			$this->incoming = null;

			$this->cleanup_incoming_recursive( $incoming['dir'] );

			if ( $incoming['adopted'] && ! is_wp_error( $result ) ) {
				$this->state->remove_update( $build_id );
			}
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
		}
		return $result;
	}

	/**
	 * Reads the package's manifest.json and every file it declares
	 * directly out of $source (core's own already-unzipped working
	 * directory), stages them under a fresh Babilim_Paths::
	 * adopt_staging_dir(), calls Babilim_Installer::adopt(), and on
	 * success marks the matching incoming() watch adopted (state removal
	 * itself waits for install_package_result(), see the class docblock)
	 * and hands back a fresh, empty directory nested under $remote_source
	 * for core to copy instead (see the class docblock for why that directory,
	 * that location, and why core cleans it up itself). On failure,
	 * cleans up whatever this call itself staged and records a client
	 * event, then returns a WP_Error -- the source's zip contents are
	 * never copied into a served directory either way.
	 *
	 * @param array{build_id: string, translation: object} $pending       The matched pending entry.
	 * @param string                                         $remote_source Remote file source location.
	 * @param string                                         $source        File source location.
	 * @return string|WP_Error
	 */
	private function adopt_from_source( array $pending, string $remote_source, string $source ) {
		$translation = $pending['translation'];
		$kind        = 'theme' === (string) ( $translation->type ?? '' ) ? 'theme' : 'plugin';
		$locale      = (string) ( $translation->language ?? '' );
		$slug        = (string) ( $translation->slug ?? '' );
		$working_dir = rtrim( $source, '/' );

		$outcome = $this->adopt_from_working_dir( $kind, $locale, $working_dir );

		if ( ! $outcome->is_ok() ) {
			$this->state->record_client_event( (string) ( $outcome->code() ?? 'babilim_adopt_failed' ), $kind, $slug, $locale );
			return new WP_Error( 'babilim_adopt_failed', $outcome->message(), array( 'reason' => $outcome->code() ) );
		}

		// Not removed from state yet -- install_package_result() does
		// that once core's own remaining steps (clear_destination, the
		// copy below, etc.) have also confirmed success, not here (see
		// the class docblock).
		if ( null !== $this->incoming && $this->incoming['build_id'] === $pending['build_id'] ) {
			$this->incoming['adopted'] = true;
		}

		$empty_dir = rtrim( $remote_source, '/' ) . '/.babilim-adopted';
		if ( ! $this->raw_fs()->mkdir( $empty_dir ) ) {
			return new WP_Error( 'babilim_empty_source_failed', __( 'Could not prepare an empty source directory.', 'babilim' ) );
		}
		return $empty_dir;
	}

	/**
	 * install_package()/adopt()'s shared body: reads manifest.json plus
	 * every file it declares out of $working_dir (via raw_get() -- never
	 * Babilim_Fs; see the class docblock), writes each one, flat, under a
	 * fresh Babilim_Paths::adopt_staging_dir() (default-domain fragments
	 * renamed to the `default__{name}` convention that area's own reader
	 * expects, matching a real `default/` subdirectory being flattened),
	 * and calls Babilim_Installer::adopt(). If any staged write itself
	 * fails, that's reported directly (rather than being left to surface
	 * indirectly as an entries-count mismatch once adopt() runs) and
	 * adopt() is never even called.
	 *
	 * @param string $kind        'plugin'|'theme'.
	 * @param string $locale      Locale code.
	 * @param string $working_dir Absolute directory holding the package's
	 *                            unzipped contents (no trailing slash).
	 * @return Babilim_Result
	 */
	private function adopt_from_working_dir( string $kind, string $locale, string $working_dir ): Babilim_Result {
		$manifest_raw = $this->raw_get( $working_dir . '/manifest.json' );
		if ( ! is_string( $manifest_raw ) ) {
			return Babilim_Result::error( 'missing_package_manifest', sprintf( /* translators: %s: the working directory manifest.json could not be read from. */ __( 'Could not read manifest.json from %s.', 'babilim' ), $working_dir ) );
		}
		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) ) {
			return Babilim_Result::error( 'invalid_package_manifest', __( 'manifest.json is not valid JSON.', 'babilim' ) );
		}

		$domain      = (string) ( $manifest['text_domain'] ?? '' );
		$staging_dir = Babilim_Paths::adopt_staging_dir( self::fresh_token() );
		$written     = array();
		$write_ok    = true;

		foreach ( array_keys( (array) ( $manifest['files'] ?? array() ) ) as $filename ) {
			$filename = (string) $filename;
			$bytes    = $this->raw_get( $working_dir . '/' . $filename );
			if ( ! is_string( $bytes ) ) {
				continue; // Missing entirely -- adopt()'s own entries-count check reports this.
			}
			if ( $this->fs->put( $staging_dir . '/' . $filename, $bytes ) ) {
				$written[] = $filename;
			} else {
				$write_ok = false;
			}
		}
		foreach ( array_keys( (array) ( $manifest['default_domain_files'] ?? array() ) ) as $filename ) {
			$filename = (string) $filename;
			$bytes    = $this->raw_get( $working_dir . '/default/' . $filename );
			if ( ! is_string( $bytes ) ) {
				continue;
			}
			if ( $this->fs->put( $staging_dir . '/default__' . $filename, $bytes ) ) {
				$written[] = 'default__' . $filename;
			} else {
				$write_ok = false;
			}
		}

		if ( ! $write_ok ) {
			$this->cleanup_staging( $staging_dir, $written );
			return Babilim_Result::error( 'staging_write_failed', sprintf( /* translators: %s: the staging directory that some files could not be written under. */ __( 'Could not write every staged file under %s.', 'babilim' ), $staging_dir ) );
		}

		$result = $this->installer->adopt( $kind, $domain, $locale, $staging_dir, $manifest );
		if ( ! $result->is_ok() ) {
			$this->cleanup_staging( $staging_dir, $written );
		}
		return $result;
	}

	/**
	 * Best-effort removal of exactly the files this call itself staged,
	 * plus the now-empty staging directory -- always through Babilim_Fs
	 * (this directory is inside its own allow-listed area, unlike
	 * core's own working directory; see the class docblock), never a
	 * blind listing of $staging_dir.
	 *
	 * @param string   $staging_dir Absolute `_adopt/{token}` directory.
	 * @param string[] $written     Bare filenames this call itself wrote there.
	 */
	private function cleanup_staging( string $staging_dir, array $written ): void {
		foreach ( $written as $name ) {
			$this->fs->delete( $staging_dir . '/' . $name );
		}
		$this->fs->rmdir( $staging_dir );
	}

	/**
	 * Reads one file directly through $wp_filesystem, bypassing
	 * Babilim_Fs's own allow-list -- this is always a file under core's
	 * own working directory, never anywhere this plugin itself persists
	 * data (see the class docblock).
	 *
	 * @param string $path Absolute path.
	 * @return string|null
	 */
	private function raw_get( string $path ): ?string {
		try {
			$fs = $this->raw_fs();
			if ( ! $fs->exists( $path ) ) {
				return null;
			}
			$contents = $fs->get_contents( $path );
			return is_string( $contents ) ? $contents : null;
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return null;
		}
	}

	/**
	 * Boots WP_Filesystem() if needed and returns the global instance --
	 * same pattern as Babilim_Wp_Fs::fs(), duplicated rather than shared
	 * because Babilim_Wp_Fs's own version is private and always paired
	 * with its class's own assert_allowed() call, which must NOT apply
	 * here (see the class docblock).
	 *
	 * @return object The $wp_filesystem instance.
	 * @throws RuntimeException If WP_Filesystem() couldn't initialize.
	 */
	private function raw_fs() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			throw new RuntimeException( 'Babilim_Updater: WP_Filesystem() could not be initialized.' );
		}
		return $wp_filesystem;
	}

	/**
	 * Whether the site owner has consented to using the service -- the
	 * same option Babilim_Site itself gates every outbound request on.
	 * Reads the option by Babilim_Site::OPTION_CONSENT (the option name
	 * is defined exactly once, there) rather than through a Babilim_Site
	 * instance (not one of this class's own dependencies) -- referencing
	 * the shared constant, not a new cross-class object dependency.
	 *
	 * @return bool
	 */
	private function has_consent(): bool {
		return (bool) get_option( Babilim_Site::OPTION_CONSENT, false );
	}

	/**
	 * Whether $translation is one of this plugin's own injected entries --
	 * checks only the `babilim` marker property, never slug or locale
	 * (see the class docblock).
	 *
	 * @param mixed $translation Candidate translation entry.
	 * @return bool
	 */
	private function marked_as_ours( $translation ): bool {
		return is_object( $translation ) && ! empty( $translation->babilim );
	}

	/**
	 * marked_as_ours(), plus confirming $package itself carries this
	 * plugin's own marker URL prefix -- belt and suspenders (see the
	 * class docblock), needed here (and only here) because $package is
	 * the one place upgrader_pre_download hands this class the URL a
	 * real wp.org check would otherwise expect signature verification
	 * against.
	 *
	 * @param mixed  $translation Candidate translation entry.
	 * @param string $package     The transient entry's own `package` URL.
	 * @return bool
	 */
	private function is_ours( $translation, string $package ): bool {
		return $this->marked_as_ours( $translation ) && 0 === strpos( $package, $this->package_url_prefix() );
	}

	/**
	 * The synthetic `package` URL marker injected for one build --
	 * never fetched directly (pre_download() always re-resolves a fresh
	 * signed link via POST /v1/downloads instead); it only exists to be
	 * recognized later by is_ours().
	 *
	 * @param string $build_id Build id.
	 * @return string
	 */
	private function package_url( string $build_id ): string {
		return $this->package_url_prefix() . rawurlencode( $build_id );
	}

	/**
	 * @return string
	 */
	private function package_url_prefix(): string {
		return rtrim( $this->base_url, '/' ) . self::PACKAGE_MARKER_SEGMENT;
	}

	/**
	 * Resolves a POST /v1/downloads Location header against the API
	 * base -- see Babilim_Api_Client::resolve_location() (the one
	 * shared implementation both this class and Babilim_Admin's own
	 * do_download() call, rather than each keeping its own copy).
	 *
	 * @param string $location Location header value.
	 * @return string Absolute same-origin URL, or '' if $location was
	 *                empty or pointed at a different origin.
	 */
	private function resolve_location( string $location ): string {
		return Babilim_Api_Client::resolve_location( $location, $this->base_url );
	}

	/**
	 * @return string 16 random hex characters.
	 */
	private static function fresh_token(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * A fresh `_incoming/{token}` token that encodes its own creation
	 * time as a leading hex-timestamp segment
	 * (`{hex unix timestamp}-{12 random hex chars}`) -- both segments are
	 * `is_safe_name()`-safe (alphanumeric plus `-`). sweep_stale_incoming_
	 * dirs() parses the leading segment back out to find entries older
	 * than it should ever take one item to finish, without needing any
	 * filesystem mtime the Babilim_Fs interface doesn't expose.
	 *
	 * @return string
	 */
	private static function fresh_incoming_token(): string {
		return dechex( time() ) . '-' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Removes every `_incoming/{token}` entry older than $max_age_seconds
	 * (default one day) -- a backstop for a token package_options()
	 * created but whose matching install_package_result() call was never
	 * reached at all (e.g. the download or unzip step that follows it
	 * failed before install_package() was ever called -- see the class
	 * docblock's note on why install_package_result() can't help with
	 * that specific case, unlike the pre_download()-registered watch).
	 * Called from the daily maintenance sweep. Best-effort: an entry
	 * whose own name doesn't parse as fresh_incoming_token()'s own shape
	 * is left alone rather than guessed at, and an entry whose cleanup
	 * throws (e.g. Babilim_Fs's path guard refusing a symlink or a `..`
	 * name) is logged and skipped -- every other entry is still swept.
	 *
	 * @param int $max_age_seconds Default 86400 (one day).
	 * @return int How many stale entries were removed.
	 */
	public function sweep_stale_incoming_dirs( int $max_age_seconds = 86400 ): int {
		$removed = 0;
		try {
			$names = $this->fs->list( Babilim_Paths::incoming_root() );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return 0;
		}
		$now = time();
		foreach ( $names as $name ) {
			// Per entry: one entry the path guard refuses (a symlink, a
			// `..` name) is skipped and logged; the rest are still swept.
			try {
				$dash = strpos( $name, '-' );
				if ( false === $dash ) {
					continue;
				}
				$created = @hexdec( substr( $name, 0, $dash ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- hexdec() never errors, just returns 0 for an unparsable string; the age check below then simply never matches.
				if ( $created <= 0 || ( $now - (int) $created ) < $max_age_seconds ) {
					continue;
				}
				if ( $this->cleanup_incoming_recursive( Babilim_Paths::incoming_dir( $name ) ) ) {
					++$removed;
				}
			} catch ( \Throwable $e ) {
				$this->log_error( $e );
			}
		}
		return $removed;
	}

	/**
	 * Removes $dir -- an `Babilim_Paths::incoming_dir()` value -- and
	 * everything under it, through Babilim_Fs only (never raw
	 * $wp_filesystem access; unlike core's own working directory, this
	 * one is inside this plugin's own allow-list, see is_within_
	 * incoming()'s own docblock). Normally a single, already-empty
	 * directory (`Babilim_Fs::rmdir()` alone would already remove it),
	 * but not guaranteed to be: if `source_selection()` itself never
	 * fired for the matching item, core's own copy (the package's real
	 * `.po`/`.mo`/`.json`/`manifest.json`, and its `default/`
	 * subdirectory -- see package-layout.md) still lands here. Walks the
	 * tree with `Babilim_Fs::list()` and decides file vs. directory with
	 * `Babilim_Fs::is_dir()` (never from whether list() came back empty
	 * -- list() of a file path lists that file itself, per core's own
	 * dirlist() contract). Returns false, without touching anything, for
	 * any $dir that isn't strictly below `Babilim_Paths::incoming_root()`
	 * (including `_incoming/` itself) -- a deliberate, narrower statement
	 * of intent than Babilim_Fs's own allow-list already enforces (that
	 * would also accept, say, an `_adopt/{token}` path, which this method
	 * must never be pointed at). A path Babilim_Fs's own guard rejects
	 * (a `..` segment, a symlink anywhere along it) throws
	 * InvalidArgumentException from the first Babilim_Fs call on it --
	 * callers catch that per entry, and nothing is ever followed or
	 * deleted through it.
	 *
	 * @param string $dir Absolute directory, must be under `_incoming/`.
	 * @return bool Whether $dir itself ended up removed.
	 */
	private function cleanup_incoming_recursive( string $dir ): bool {
		$prefix = Babilim_Paths::incoming_root() . '/';
		if ( 0 !== strpos( $dir, $prefix ) || strlen( $dir ) === strlen( $prefix ) ) {
			return false;
		}
		foreach ( $this->fs->list( $dir ) as $name ) {
			$path = $dir . '/' . $name;
			if ( $this->fs->is_dir( $path ) ) {
				$this->cleanup_incoming_recursive( $path );
				continue;
			}
			$this->fs->delete( $path );
		}
		return $this->fs->rmdir( $dir );
	}

	/**
	 * Logs a caught Throwable without ever letting logging itself fatal
	 * a request -- same WP_DEBUG-gated pattern as Babilim/Babilim_Site's
	 * own log_error().
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
