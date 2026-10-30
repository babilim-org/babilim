<?php
/**
 * Downloads and installs language builds the service offers, on behalf of
 * two callers: the twice-daily sync cron (install_pending_updates(), which
 * runs right after a sync has discovered that an installed locale's active
 * build moved on) and the settings screen's manual install button
 * (install_build()).
 *
 * Everything that makes an install safe lives in Babilim_Installer and is
 * reused unchanged: the manifest lock, package and file integrity checks,
 * rollback of a failed install, and the default-supplement index. This
 * class only adds the download step in front of it. It never runs on a
 * page load -- the cron is its only automatic caller.
 *
 * Only builds listed in the last sync's `updates[]` are installed
 * automatically, so a locale the site owner never installed is never
 * installed behind their back; first installs stay a manual action.
 *
 * Nothing is downloaded while the site is in the verification-required
 * state (see Babilim_Site::verification_warning()), and a `403
 * verification_required` from the service itself puts the site into that
 * state and ends the run instead of being retried.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Download-and-install step shared by the sync cron and the settings screen.
 */
final class Babilim_Sync_Installer {

	/**
	 * Option holding `build_id => failed automatic attempts`, so a build
	 * that keeps failing is not retried on every sync forever. Its own
	 * option: the state snapshot is replaced wholesale by each sync.
	 *
	 * @var string
	 */
	public const OPTION_FAILURES = 'babilim_auto_install_failures';

	/**
	 * Option (bool, missing = on): install available translations
	 * automatically from the sync cron.
	 *
	 * @var string
	 */
	public const OPTION_AUTO_INSTALL = 'babilim_auto_install';

	/**
	 * Option holding `"kind:slug:locale" => true` for translations the
	 * owner removed; cron does not put them back.
	 *
	 * @var string
	 */
	public const OPTION_SKIP = 'babilim_auto_install_skip';

	/**
	 * Option (int): translations installed automatically since the owner
	 * last saw the list.
	 *
	 * @var string
	 */
	public const OPTION_AUTO_INSTALLED = 'babilim_auto_installed';

	/**
	 * How many times one build is attempted automatically before the cron
	 * leaves it alone (the manual button still works).
	 *
	 * @var int
	 */
	public const MAX_AUTO_ATTEMPTS = 3;

	/**
	 * Upper bound on installs per cron run, so one run stays short; the
	 * rest are picked up by the next run.
	 *
	 * @var int
	 */
	public const MAX_PER_RUN = 20;

	/**
	 * @var Babilim_State
	 */
	private $state;

	/**
	 * @var Babilim_Site
	 */
	private $site;

	/**
	 * @var Babilim_Installer
	 */
	private $installer;

	/**
	 * @var Babilim_Http
	 */
	private $http;

	/**
	 * @var Babilim_Fs
	 */
	private $fs;

	/**
	 * @var string
	 */
	private $base_url;

	public function __construct( Babilim_State $state, Babilim_Site $site, Babilim_Installer $installer, Babilim_Http $http, Babilim_Fs $fs, string $base_url ) {
		$this->state     = $state;
		$this->site      = $site;
		$this->installer = $installer;
		$this->http      = $http;
		$this->fs        = $fs;
		$this->base_url  = $base_url;
	}

	/**
	 * Installs every build the last sync listed in `updates[]`, oldest
	 * first, stopping early when the service says the site is unverified
	 * or rate limits it. Makes zero requests without consent, without a
	 * token, or in the verification-required state.
	 *
	 * @return array<int,array{kind:string,slug:string,locale:string,build_id:string,result:Babilim_Result}>
	 */
	public function install_pending_updates(): array {
		$done = array();
		if ( ! (bool) get_option( Babilim_Site::OPTION_CONSENT, false ) ) {
			return $done;
		}
		$failures = $this->failures();
		foreach ( $this->state->updates() as $update ) {
			if ( count( $done ) >= self::MAX_PER_RUN ) {
				break;
			}
			$kind     = (string) ( $update['kind'] ?? '' );
			$slug     = (string) ( $update['slug'] ?? '' );
			$locale   = (string) ( $update['locale'] ?? '' );
			$build_id = (string) ( $update['build_id'] ?? '' );
			if ( '' === $kind || '' === $slug || '' === $locale || '' === $build_id ) {
				continue;
			}
			if ( (int) ( $failures[ $build_id ] ?? 0 ) >= self::MAX_AUTO_ATTEMPTS ) {
				continue;
			}

			$result = $this->install_build( $kind, $slug, $locale, $build_id );
			$done[] = array(
				'kind'     => $kind,
				'slug'     => $slug,
				'locale'   => $locale,
				'build_id' => $build_id,
				'result'   => $result,
			);

			$code = (string) $result->code();
			if ( $result->is_ok() ) {
				unset( $failures[ $build_id ] );
			} elseif ( 'verification_required' === $code || 'no_token' === $code || 'babilim_rate_limited' === $code ) {
				// Not this build's fault, and nothing else would fare
				// better right now: stop, and do not count it.
				break;
			} else {
				$failures[ $build_id ] = (int) ( $failures[ $build_id ] ?? 0 ) + 1;
			}
		}
		$this->save_failures( $failures );
		return $done;
	}

	/**
	 * Installs builds the last sync lists as `babilim_available` for a
	 * managed language, so a new translation shows up without a click.
	 * Shares MAX_PER_RUN (through $limit), the failure counters and the
	 * early-abort rule with install_pending_updates(). Makes zero
	 * requests without consent, with the option off, or in the
	 * verification-required state.
	 *
	 * Skipped: a cell the site already has a record for, a
	 * (kind, slug, locale) the owner removed (OPTION_SKIP), a build that
	 * failed MAX_AUTO_ATTEMPTS times, and -- on the free plan -- any
	 * component with no record in any language yet, so no new free slot
	 * is taken behind the owner's back.
	 *
	 * On a multisite network nothing is installed here at all.
	 *
	 * @param string[] $locales Managed locale codes.
	 * @param int      $limit   Installs left in this run.
	 * @return array<int,array{kind:string,slug:string,locale:string,build_id:string,result:Babilim_Result}>
	 */
	public function install_available( array $locales, int $limit ): array {
		$done = array();
		// A network shares one manifest and one language folder, but each
		// site keeps its own skip list, switch and slots, so "already has
		// a record" and "the owner removed it" cannot be told apart per
		// site. Updates to installed languages are unaffected.
		if ( is_multisite() ) {
			return $done;
		}
		if ( $limit <= 0 || array() === $locales || ! (bool) get_option( Babilim_Site::OPTION_CONSENT, false ) || ! (bool) get_option( self::OPTION_AUTO_INSTALL, true ) ) {
			return $done;
		}
		$state    = $this->state->get();
		// Only an explicit paid or partner license lifts the slot rule; a
		// missing, empty or unknown kind is treated as free.
		$free     = ! in_array( (string) ( ( (array) ( $state['license'] ?? array() ) )['kind'] ?? '' ), array( 'paid', 'partner' ), true );
		$skip     = get_option( self::OPTION_SKIP, array() );
		$skip     = is_array( $skip ) ? $skip : array();
		$failures = $this->failures();

		foreach ( (array) ( $state['components'] ?? array() ) as $component ) {
			$kind = (string) ( $component['kind'] ?? '' );
			$slug = (string) ( $component['slug'] ?? '' );
			if ( '' === $kind || '' === $slug || ! is_array( $component['locales'] ?? null ) ) {
				continue;
			}
			foreach ( $component['locales'] as $locale => $cell ) {
				$locale   = (string) $locale;
				$build_id = is_array( $cell ) ? (string) ( $cell['build_id'] ?? '' ) : '';
				if ( count( $done ) >= $limit ) {
					break 2;
				}
				if ( ! is_array( $cell ) || 'babilim_available' !== ( $cell['status'] ?? '' ) || '' === $build_id || ! in_array( $locale, $locales, true ) ) {
					continue;
				}
				if ( isset( $skip[ "$kind:$slug:$locale" ] ) || (int) ( $failures[ $build_id ] ?? 0 ) >= self::MAX_AUTO_ATTEMPTS ) {
					continue;
				}
				if ( $this->site->has_manifest_record( $kind, $slug, $locale ) ) {
					continue;
				}
				if ( $free && ! $this->site->has_manifest_record( $kind, $slug ) ) {
					continue;
				}

				$result = $this->install_build( $kind, $slug, $locale, $build_id );
				$done[] = array(
					'kind'     => $kind,
					'slug'     => $slug,
					'locale'   => $locale,
					'build_id' => $build_id,
					'result'   => $result,
				);

				$code = (string) $result->code();
				if ( $result->is_ok() ) {
					unset( $failures[ $build_id ] );
					update_option( self::OPTION_AUTO_INSTALLED, (int) get_option( self::OPTION_AUTO_INSTALLED, 0 ) + 1, false );
				} elseif ( 'verification_required' === $code || 'no_token' === $code || 'babilim_rate_limited' === $code ) {
					break 2;
				} else {
					$failures[ $build_id ] = (int) ( $failures[ $build_id ] ?? 0 ) + 1;
				}
			}
		}
		$this->save_failures( $failures );
		return $done;
	}

	/**
	 * Stops cron from installing a (kind, slug, locale) again -- called
	 * when the owner removes it.
	 *
	 * @param string $kind   Component kind.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 */
	public static function remember_skip( string $kind, string $slug, string $locale ): void {
		$skip = get_option( self::OPTION_SKIP, array() );
		$skip = is_array( $skip ) ? $skip : array();
		$skip[ "$kind:$slug:$locale" ] = true;
		update_option( self::OPTION_SKIP, $skip, false );
	}

	/**
	 * Lets cron install a (kind, slug, locale) again -- called after the
	 * owner downloads it by hand.
	 *
	 * @param string $kind   Component kind.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 */
	public static function clear_skip( string $kind, string $slug, string $locale ): void {
		$skip = get_option( self::OPTION_SKIP, array() );
		if ( ! is_array( $skip ) || ! isset( $skip[ "$kind:$slug:$locale" ] ) ) {
			return;
		}
		unset( $skip[ "$kind:$slug:$locale" ] );
		if ( array() === $skip ) {
			delete_option( self::OPTION_SKIP );
			return;
		}
		update_option( self::OPTION_SKIP, $skip, false );
	}

	/**
	 * Downloads and installs one build. Does not check consent -- the
	 * caller (a consent-gated admin action, or install_pending_updates())
	 * has.
	 *
	 * @param string   $kind     'plugin'|'theme'.
	 * @param string   $slug     Component slug.
	 * @param string   $locale   Locale code.
	 * @param string   $build_id Server build id.
	 * @param int|null $timeout  Seconds for each of the two requests, or null for the transport default.
	 * @return Babilim_Result
	 */
	public function install_build( string $kind, string $slug, string $locale, string $build_id, ?int $timeout = null ): Babilim_Result {
		if ( null !== $this->site->verification_warning() ) {
			return Babilim_Result::error( 'verification_required', __( 'Babilim could not verify this site, so nothing is downloaded or installed until verification succeeds.', 'babilim' ) );
		}
		$token = $this->site->token();
		if ( null === $token ) {
			return Babilim_Result::error( 'no_token', __( 'This site is not registered with Babilim yet.', 'babilim' ) );
		}
		$api = new Babilim_Api_Client( $this->http, $this->base_url, $token );

		$download = $api->download( $build_id, $timeout );
		if ( is_wp_error( $download ) ) {
			return $this->from_wp_error( $download );
		}
		$url = Babilim_Api_Client::resolve_location( (string) ( $download['location'] ?? '' ), $this->base_url );
		if ( '' === $url ) {
			return Babilim_Result::error( 'invalid_response', __( 'The download response was missing a usable location.', 'babilim' ) );
		}

		$dir   = Babilim_Paths::incoming_dir( self::fresh_incoming_token() );
		$dest  = $dir . '/package.zip';
		$fetch = $api->fetch_package( $url, $dest, $this->fs, $timeout );
		if ( is_wp_error( $fetch ) ) {
			$this->cleanup_incoming_recursive( $dir );
			return $this->from_wp_error( $fetch );
		}

		// The slug is the only identity available ahead of the download
		// (POST /v1/downloads never returns a manifest); install() accepts
		// it as a valid assertion even when the package's own text domain,
		// known only once the zip is open, differs.
		$expected = array(
			'server_build_id' => $build_id,
			'slug'            => $slug,
			'locale'          => $locale,
		);
		// The text domain this site's own copy of the component uses, so
		// a package declaring another one is refused.
		$site_component = $this->site->component( $kind, $slug );
		$site_domain    = null === $site_component ? '' : (string) ( $site_component['text_domain'] ?? '' );
		if ( '' !== $site_domain ) {
			$expected['site_text_domain'] = $site_domain;
		}
		$result = $this->installer->install( $kind, $slug, $locale, $dest, $expected );

		$this->cleanup_incoming_recursive( $dir );

		if ( $result->is_ok() ) {
			$update = $this->state->update_for( $kind, $slug, $locale );
			if ( null !== $update && (string) ( $update['build_id'] ?? '' ) === $build_id ) {
				// Keep the build in the cell, so the list can tell this update
				// is done and a later rollback can offer it again.
				$this->state->set_cell_status( $kind, $slug, $locale, array( 'update_build_id' => $build_id ) );
			}
			$this->state->remove_update( $build_id );
			// A one-off sync, never a synchronous call: it lets the state
			// (and the integrity the service is told about) reflect the
			// build just installed without waiting for the next cron run.
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time(), Babilim::SYNC_HOOK );
			}
		}
		return $result;
	}

	/**
	 * Maps a failed service call to a result. `verification_required`
	 * (whatever the reason, including none) also puts the site into the
	 * verification-required state, so nothing retries it blindly.
	 */
	private function from_wp_error( WP_Error $error ): Babilim_Result {
		if ( 'babilim_verification_required' === $error->get_error_code() ) {
			$data   = $error->get_error_data();
			$reason = is_array( $data ) ? (string) ( $data['reason'] ?? '' ) : '';
			$this->site->note_server_unverified( $reason );
			return Babilim_Result::error( 'verification_required', $error->get_error_message() );
		}
		return Babilim_Result::error( $error->get_error_code(), $error->get_error_message() );
	}

	/**
	 * @return array<string,int>
	 */
	private function failures(): array {
		$stored = get_option( self::OPTION_FAILURES, array() );
		return is_array( $stored ) ? array_map( 'intval', $stored ) : array();
	}

	/**
	 * Persists the failure counters, dropping any build that is no longer
	 * offered, so the option cannot grow without bound.
	 *
	 * @param array<string,int> $failures Counters.
	 */
	private function save_failures( array $failures ): void {
		$offered = array();
		foreach ( $this->state->updates() as $update ) {
			$offered[ (string) ( $update['build_id'] ?? '' ) ] = true;
		}
		// Builds offered as first installs count too.
		foreach ( (array) ( $this->state->get()['components'] ?? array() ) as $component ) {
			foreach ( (array) ( is_array( $component ) ? ( $component['locales'] ?? array() ) : array() ) as $cell ) {
				if ( is_array( $cell ) && 'babilim_available' === ( $cell['status'] ?? '' ) ) {
					$offered[ (string) ( $cell['build_id'] ?? '' ) ] = true;
				}
			}
		}
		$failures = array_intersect_key( array_filter( $failures ), $offered );
		if ( array() === $failures ) {
			delete_option( self::OPTION_FAILURES );
			return;
		}
		update_option( self::OPTION_FAILURES, $failures, false );
	}

	/**
	 * A fresh `_incoming/{token}` token that encodes its own creation
	 * time as a leading hex-timestamp segment
	 * (`{hex unix timestamp}-{12 random hex chars}`); both segments are
	 * `is_safe_name()`-safe. sweep_stale_incoming_dirs() parses the
	 * leading segment back out to find entries older than any single
	 * install should take, without needing a filesystem mtime the
	 * Babilim_Fs interface does not expose.
	 *
	 * @return string
	 */
	private static function fresh_incoming_token(): string {
		return dechex( time() ) . '-' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Removes every `_incoming/{token}` entry older than $max_age_seconds
	 * (default one day) -- a backstop for a download directory that
	 * install_build() created but could not clean up (a crash or a killed
	 * request between the fetch and the cleanup). Called from the daily
	 * maintenance sweep. Best-effort: an entry
	 * whose own name doesn't parse as fresh_incoming_token()'s shape
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
	 * is_within_incoming()'s own docblock). Normally holds just the
	 * downloaded zip, but a killed request can leave anything here. Walks the
	 * tree with `Babilim_Fs::list()` and decides file vs. directory with
	 * `Babilim_Fs::is_dir()` (never from whether list() came back empty
	 * -- list() of a file path lists that file itself, per core's own
	 * dirlist() contract). Returns false, without touching anything, for
	 * any $dir that isn't strictly below `Babilim_Paths::incoming_root()`
	 * (including `_incoming/` itself) -- a deliberate, narrower statement
	 * of intent than Babilim_Fs's own allow-list already enforces (that
	 * would also accept, say, a served directory, which this method
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
	 * a request; only writes when WP_DEBUG is on.
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
