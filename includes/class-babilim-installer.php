<?php
/**
 * Installs, verifies, rolls back, removes, degrades, and purges
 * downloaded translation packages. Only translation data (`.mo`/`.po`/
 * `.json`) is ever fetched or written here -- no remote PHP or JS is
 * ever downloaded or executed.
 *
 * Every write goes through the injected Babilim_Fs; a package's zip is
 * never extracted straight to disk outside it -- $unzip returns each
 * accepted entry's raw bytes in memory, and this class alone decides
 * which bytes get written where (see the constructor's $unzip
 * docblock). Everything is staged under a `_versions/.../{build_id}/`
 * directory first; the only writes that ever touch a *served* directory
 * (`plugins/`, `themes/`, `default/_parts/`) are the final rename()
 * calls, so a failure at any earlier step leaves served files exactly
 * as they were (see install()).
 *
 * `.l10n.php` is never taken from a package (wp.org Guideline 8 -- no
 * remotely loaded executable code): only `.mo`/`.po`/`.json`/
 * `manifest.json` entries are ever accepted out of a zip; anything else
 * (a stray `.php`, or a name that doesn't match the expected shape) is
 * silently ignored, never written anywhere. A main file's own
 * `.l10n.php` is produced locally by the injected $transform, from the
 * `.mo` this class itself just verified.
 *
 * Two orchestration notes for whoever calls this class (no such caller
 * exists yet in this codebase -- a future sync/cron task):
 * - After a successful install()/rollback()/remove()/degrade(), the
 *   caller is responsible for also calling
 *   Babilim_Default_Index::rebuild($locale) -- this class has no
 *   Default_Index dependency of its own (its constructor is fixed to
 *   exactly the five arguments below), so it cannot trigger that
 *   itself.
 * - verify() failing does not itself call degrade() or log a
 *   client_event -- that orchestration belongs to a later sync/cron
 *   task, not this class.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and manages translation packages under Babilim_Paths::root().
 */
final class Babilim_Installer {

	/**
	 * How many past builds' staged files version_history() retains per
	 * kind+domain+locale before the oldest is pruned.
	 *
	 * @var int
	 */
	private const KEEP_VERSIONS = 3;

	/**
	 * The reserved, deterministic (not random) build id
	 * place_served_files() nests every previous-build backup copy under
	 * -- see that method's docblock. Matches Babilim_Paths'
	 * `bd_{12 hex}` shape like DEFAULT_INDEX_SCRATCH_BUILD_ID does, and
	 * is likewise fully overwritten (never inspected across calls).
	 *
	 * @var string
	 */
	private const RENAME_BACKUP_BUILD_ID = 'bd_000000000097';

	/**
	 * @var Babilim_Fs
	 */
	private $fs;

	/**
	 * @var Babilim_Manifest
	 */
	private $manifest;

	/**
	 * @var callable
	 */
	private $transform;

	/**
	 * @var callable
	 */
	private $parse;

	/**
	 * @var callable
	 */
	private $unzip;

	/**
	 * @param Babilim_Fs       $fs        Filesystem to read/write through.
	 * @param Babilim_Manifest $manifest  The installed-files manifest.
	 * @param callable         $transform fn(string $mo_path): string -- PHP source
	 *                                    for a `.l10n.php`, from an already-staged
	 *                                    `.mo`. Prod: WP_Translation_File::transform().
	 * @param callable         $parse     fn(string $path, string $format): int|false --
	 *                                    entry count ($format is 'mo' or 'php'),
	 *                                    or false if the file doesn't parse.
	 *                                    Prod: WP_Translation_File::create().
	 * @param callable         $unzip     fn(string $zip_path): array<string,string>|false --
	 *                                    every zip entry's bytes, keyed by its
	 *                                    path relative to the zip root (e.g.
	 *                                    `manifest.json`, `foo-ja.mo`,
	 *                                    `default/foo-ja.mo`), or false if the
	 *                                    zip couldn't be opened. Never writes
	 *                                    anything to disk itself -- this class
	 *                                    alone decides which bytes get written
	 *                                    where, and only ever through $fs.
	 */
	public function __construct( Babilim_Fs $fs, Babilim_Manifest $manifest, callable $transform, callable $parse, callable $unzip ) {
		$this->fs        = $fs;
		$this->manifest   = $manifest;
		$this->transform = $transform;
		$this->parse      = $parse;
		$this->unzip      = $unzip;
	}

	/**
	 * Installs one package zip as the component's current build.
	 *
	 * @param string $kind             'plugin'|'theme'.
	 * @param string $domain           The component's slug or its text domain (either one is accepted; see install_from_entries()).
	 * @param string $locale           Locale code.
	 * @param string $zip_path         Opaque zip identifier, passed to $unzip.
	 * @param array  $expected_manifest The package-manifest.schema.json-shaped
	 *                                  array this install is expected to match
	 *                                  (fetched/verified by the caller).
	 * @return Babilim_Result
	 */
	public function install( string $kind, string $domain, string $locale, string $zip_path, array $expected_manifest ): Babilim_Result {
		$guard = $this->guard_identity( $kind, $domain, $locale );
		if ( null !== $guard ) {
			return $guard;
		}

		$identity_error = self::validate_expected_identity( $expected_manifest );
		if ( null !== $identity_error ) {
			return Babilim_Result::error( 'invalid_manifest_shape', $identity_error );
		}

		$extracted = call_user_func( $this->unzip, $zip_path );
		if ( ! is_array( $extracted ) ) {
			return Babilim_Result::error( 'unzip_failed', sprintf( /* translators: %s: the zip file path that could not be opened. */ __( 'Could not open zip: %s', 'babilim' ), $zip_path ) );
		}
		if ( ! array_key_exists( 'manifest.json', $extracted ) ) {
			return Babilim_Result::error( 'missing_package_manifest', __( 'Zip has no manifest.json.', 'babilim' ) );
		}

		// The zip's own embedded manifest.json must agree with the
		// caller-supplied $expected_manifest on the identity fields that
		// matter -- previously only its *presence* was checked, so a zip
		// genuinely fetched for a different build/component/locale would
		// install without complaint as long as its file names and hashes
		// happened to satisfy verify_against_expected() below.
		$zip_manifest = json_decode( (string) $extracted['manifest.json'], true );
		if ( ! is_array( $zip_manifest ) ) {
			return Babilim_Result::error( 'invalid_package_manifest', __( 'manifest.json in the zip is not valid JSON.', 'babilim' ) );
		}
		foreach ( array( 'server_build_id', 'slug', 'locale' ) as $key ) {
			if ( (string) ( $zip_manifest[ $key ] ?? '' ) !== (string) ( $expected_manifest[ $key ] ?? '' ) ) {
				return Babilim_Result::error( 'package_manifest_mismatch', sprintf( /* translators: %s: the manifest.json field that did not match. */ __( "manifest.json's '%s' does not match the expected value.", 'babilim' ), $key ) );
			}
		}

		// Once the zip's own manifest.json agrees with the caller on
		// identity, IT (not the caller-supplied $expected_manifest,
		// which -- for install()'s only production caller,
		// Babilim_Admin::do_download() -- carries nothing beyond that
		// identity) becomes the expected manifest for everything else:
		// files/sha256, entries, status, version, text_domain,
		// extra_domains, build_no. Validated with the full, strict
		// check -- a package that can't even satisfy its own declared
		// shape is never trusted forward.
		$zip_shape_error = self::validate_package_manifest_shape( $zip_manifest );
		if ( null !== $zip_shape_error ) {
			return Babilim_Result::error( 'invalid_manifest_shape', $zip_shape_error );
		}

		// The caller's view of this site's own copy of the component; the
		// package manifest is what everything else trusts, so carry it over.
		$site_text_domain = (string) ( $expected_manifest['site_text_domain'] ?? '' );
		if ( '' !== $site_text_domain ) {
			$zip_manifest['site_text_domain'] = $site_text_domain;
		}

		return $this->install_from_entries( $kind, $domain, $locale, $extracted, $zip_manifest );
	}

	/**
	 * install()'s body, from an already-in-memory
	 * filename => bytes map onward (see install()'s own docblock for what
	 * happens before this point).
	 *
	 * @param string $kind              'plugin'|'theme'.
	 * @param string $domain            The caller's identity assertion: the manifest's own slug or its text_domain -- anything else is domain_mismatch.
	 * @param string $locale            Locale code.
	 * @param array  $extracted         filename => bytes (zip-root-relative,
	 *                                   `default/` prefix included for
	 *                                   default-domain fragments).
	 * @param array  $expected_manifest Package manifest.
	 * @return Babilim_Result
	 */
	private function install_from_entries( string $kind, string $domain, string $locale, array $extracted, array $expected_manifest ): Babilim_Result {
		return $this->with_install_lock(
			function () use ( $kind, $domain, $locale, $extracted, $expected_manifest ) {
				return $this->install_from_entries_locked( $kind, $domain, $locale, $extracted, $expected_manifest );
			}
		);
	}

	/**
	 * Runs $fn as the only installer in any request: an exclusive flock
	 * on `.install.lock` in the managed directory (blocking; released by
	 * the system if the process dies). The admin download and the cron
	 * update both end up here, so they can no longer install the same
	 * build at the same time. Where the lock file cannot be opened or
	 * locked (not an error the site owner can act on), the manifest's own
	 * database lock is used instead; it is reentrant, so the manifest
	 * writes inside $fn do not wait on themselves.
	 *
	 * @param callable $fn Returns a Babilim_Result.
	 * @return Babilim_Result
	 */
	private function with_install_lock( callable $fn ): Babilim_Result {
		$root = Babilim_Paths::root();
		$this->fs->mkdir( $root );

		$wouldblock = 0;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a lock file inside the managed directory; no contents are ever written.
		$handle = ( is_dir( $root ) && ! is_dir( $root . '/.install.lock' ) ) ? fopen( $root . '/.install.lock', 'c' ) : false;
		if ( false !== $handle ) {
			if ( flock( $handle, LOCK_EX, $wouldblock ) ) {
				try {
					return $fn();
				} finally {
					flock( $handle, LOCK_UN );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
					fclose( $handle );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			fclose( $handle );
		}
		if ( $wouldblock ) {
			return Babilim_Result::error( 'install_busy', __( 'Another installation is running; try again in a moment.', 'babilim' ) );
		}

		$result = $this->manifest->with_lock( $fn );
		if ( ! $result instanceof Babilim_Result ) {
			return Babilim_Result::error( 'install_busy', __( 'Another installation is running; try again in a moment.', 'babilim' ) );
		}
		return $result;
	}

	/**
	 * Whether a recorded build is really in place: it owns files, its last
	 * integrity check did not fail, it was not degraded, and every file it
	 * owns is on disk with the recorded digest. Read-only. A build that
	 * was degraded (files removed) or whose files were changed is not
	 * "already installed" -- installing it again is how it comes back.
	 *
	 * @param array $record The manifest record.
	 * @return bool
	 */
	private function record_is_intact( array $record ): bool {
		$files = array_merge( (array) ( $record['files'] ?? array() ), (array) ( $record['default_files'] ?? array() ) );
		if ( ! empty( $record['degraded_at'] ) || array() === $files || false === ( $record['integrity']['ok'] ?? true ) ) {
			return false;
		}
		foreach ( $files as $relative => $expected_sha ) {
			$actual = $this->fs->sha256( Babilim_Paths::root() . '/' . $relative );
			if ( ! is_string( $actual ) || $actual !== $expected_sha ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * install_from_entries()'s body, run with the install lock held.
	 *
	 * @param string $kind              See install_from_entries().
	 * @param string $domain            See install_from_entries().
	 * @param string $locale            See install_from_entries().
	 * @param array  $extracted         See install_from_entries().
	 * @param array  $expected_manifest See install_from_entries().
	 * @return Babilim_Result
	 */
	private function install_from_entries_locked( string $kind, string $domain, string $locale, array $extracted, array $expected_manifest ): Babilim_Result {
		$server_build_id = (string) ( $expected_manifest['server_build_id'] ?? '' );
		if ( 1 !== preg_match( '/^bd_[0-9a-f]{12}$/D', $server_build_id ) ) {
			return Babilim_Result::error( 'invalid_build_id', sprintf( /* translators: %s: the invalid server_build_id value. */ __( 'Not a valid server_build_id: %s', 'babilim' ), $server_build_id ) );
		}

		// $kind (the caller's own argument -- 'plugin' or 'theme', which
		// kind_dir() below turns into the served directory) must agree
		// with the kind the package manifest declares (the zip's own
		// manifest.json). Without this, a theme's package installed via a
		// 'plugin' call (or vice versa) would pass every other check here
		// and land under the wrong kind's directory tree.
		$manifest_kind = (string) ( $expected_manifest['kind'] ?? '' );
		if ( $manifest_kind !== $kind ) {
			return Babilim_Result::error(
				'kind_mismatch',
				sprintf(
					/* translators: 1: the package manifest's own kind. 2: the requested kind. */
					__( "The package manifest's kind '%1\$s' does not match the requested kind '%2\$s'.", 'babilim' ),
					$manifest_kind,
					$kind
				)
			);
		}

		// The component's identity is the package manifest's own
		// `slug` -- never the text domain -- so it matches the contract's
		// `default/{slug}-{locale}.*` naming (package-layout.md) and the
		// record can be recognized by active_components()/
		// installed_identities(), which both register every identity a
		// plugin may be keyed under (directory name, wp.org slug, and
		// text-domain header). $text_domain is
		// still what main-file *names* are built from -- that half of
		// the contract genuinely is text-domain-based. A component's
		// slug and text domain are legitimately different strings (e.g.
		// a plugin directory slugged "foo-plugin" whose PHP source
		// declares "Text Domain: foo") -- neither install()'s production
		// callers (Babilim_Admin::do_download() and
		// Babilim_Sync_Installer::install_build(), which only ever know
		// the slug ahead of a download) is a caller bug for asserting
		// whichever one of those two identities it actually has in hand.
		$slug        = (string) ( $expected_manifest['slug'] ?? $domain );
		$text_domain = (string) ( $expected_manifest['text_domain'] ?? $domain );
		// The site's own copy of the component declares a text domain (its
		// main-file header, or its slug when it has none); a package whose
		// text_domain differs would install files WordPress never loads for
		// this component, so it is refused outright.
		$site_text_domain = (string) ( $expected_manifest['site_text_domain'] ?? '' );
		if ( '' !== $site_text_domain && $site_text_domain !== $text_domain ) {
			return Babilim_Result::error(
				'text_domain_mismatch',
				sprintf(
					/* translators: 1: the package's text domain. 2: this site's text domain for the component. */
					__( "The package's text domain '%1\$s' does not match this site's copy of the component ('%2\$s'), so it was not installed.", 'babilim' ),
					$text_domain,
					$site_text_domain
				)
			);
		}
		// $domain (this method's own parameter) is the caller's own
		// identity assertion -- either the slug or the text domain,
		// whichever the caller had ahead of time. A value that matches *neither* what the
		// manifest declares as its slug nor as its text_domain is a
		// caller bug (the wrong package for the wrong component, or a
		// stale argument) worth catching here rather than silently
		// installing under whichever one happened to win.
		if ( $domain !== $slug && $domain !== $text_domain ) {
			return Babilim_Result::error(
				'domain_mismatch',
				sprintf(
					/* translators: 1: the given domain. 2: the package manifest's own slug. 3: the package manifest's own text_domain. */
					__( "The given domain '%1\$s' matches neither the package manifest's own slug '%2\$s' nor its text_domain '%3\$s'.", 'babilim' ),
					$domain,
					$slug,
					$text_domain
				)
			);
		}
		if ( ! Babilim_Paths::is_safe_name( $slug ) ) {
			return Babilim_Result::error( 'invalid_slug', sprintf( /* translators: %s: the invalid slug. */ __( 'Unsafe slug: %s', 'babilim' ), $slug ) );
		}
		if ( ! Babilim_Paths::is_safe_name( $text_domain ) ) {
			return Babilim_Result::error( 'invalid_text_domain', sprintf( /* translators: %s: the invalid text domain. */ __( 'Unsafe text_domain: %s', 'babilim' ), $text_domain ) );
		}

		// With the lock held, look at what is recorded NOW, not at what
		// this request read before it waited: if the same build is
		// already installed (the other request just finished it), there
		// is nothing to do, and nothing -- files, restore point -- is
		// touched.
		$this->manifest->reload_from_disk();
		if ( $this->manifest->is_available() ) {
			$current = $this->manifest->installed( $kind, $slug, $locale );
			if ( null !== $current && (string) ( $current['build_id'] ?? '' ) === $server_build_id && $this->record_is_intact( $current ) ) {
				return Babilim_Result::ok( array( 'already_installed' => true ) );
			}
		}

		$kind_dir = $this->kind_dir( $kind );
		$names    = $this->target_domains( $text_domain, $expected_manifest );

		$conflict = $this->served_files_owned_elsewhere( $kind, $slug, $kind_dir, $names, $locale );
		if ( null !== $conflict ) {
			return $conflict;
		}

		$classified = $this->classify_entries( $extracted, $names, $slug, $locale );

		$verified = $this->verify_against_expected( $classified, $expected_manifest );
		if ( $verified->is_error() ) {
			return $verified;
		}
		list( $main_verified, $default_verified ) = array( $verified->get( 'main' ), $verified->get( 'default' ) );

		// The component's own `{text_domain}-{locale}.mo` is the one file
		// every package must carry (package-layout.md); a package whose
		// only `.mo` belongs to an extra domain -- or that has no `.mo` at
		// all -- is refused here, before anything is staged.
		$text_domain_mo = $text_domain . '-' . $locale . '.mo';
		if ( ! isset( $main_verified[ $text_domain_mo ] ) ) {
			return Babilim_Result::error( 'missing_text_domain_mo', sprintf( /* translators: %s: the expected .mo filename for the package's own text domain. */ __( 'The package has no %s for its own text domain.', 'babilim' ), $text_domain_mo ) );
		}

		$staging = Babilim_Paths::version_dir( $kind_dir, $slug, $locale, $server_build_id );

		$staged = $this->stage_files( $staging, $main_verified, $default_verified );
		if ( $staged->is_error() ) {
			$this->delete_all( $staged->get( 'written', array() ) );
			return $staged;
		}

		$built = $this->build_derived_files( $staging, $names, $text_domain, $slug, $locale, $main_verified, $default_verified, $expected_manifest );
		if ( $built->is_error() ) {
			$this->delete_all( $staged->get( 'written' ) );
			$this->delete_all( $built->get( 'written', array() ) );
			return $built;
		}

		$finalize_plan = array(
			'kind_dir'           => $kind_dir,
			'domain'             => $slug,
			'text_domain'        => $text_domain,
			'locale'             => $locale,
			'staging'            => $staging,
			'build_id'           => $server_build_id,
			'main_served'        => $built->get( 'main_served' ),   // served filename => staged path
			'default_served'     => $built->get( 'default_served' ),
			'component_version'  => (string) ( $expected_manifest['version'] ?? '' ),
			'pipeline_build_id'  => (string) ( $expected_manifest['build_id'] ?? '' ),
			'build_no'           => (int) ( $expected_manifest['build_no'] ?? 0 ),
			'built_at'           => (string) ( $expected_manifest['issued_at'] ?? gmdate( 'c' ) ),
		);

		$result = $this->finalize_build( $finalize_plan );
		if ( $result->is_error() ) {
			$this->delete_all( $staged->get( 'written' ) );
			$this->delete_all( $built->get( 'written', array() ) );
		}
		return $result;
	}

	/**
	 * A minimal required-fields/types check against the package
	 * manifest's own pinned contract schema -- this plugin has no JSON
	 * Schema validator dependency, so this checks exactly the fields
	 * install() itself goes on to read,
	 * rather than validating the schema in full (e.g. it doesn't reject
	 * an unknown additional property the way the schema's own
	 * `additionalProperties: false` would).
	 *
	 * @param array $manifest A package-manifest.schema.json-shaped array.
	 * @return string|null An error message, or null if it passes.
	 */
	private static function validate_package_manifest_shape( array $manifest ): ?string {
		foreach ( array( 'build_id', 'locale', 'status', 'kind', 'slug', 'version', 'text_domain', 'server_build_id', 'issued_at', 'contract' ) as $key ) {
			if ( ! isset( $manifest[ $key ] ) || ! is_string( $manifest[ $key ] ) || '' === $manifest[ $key ] ) {
				/* translators: %s: the manifest.json field that is missing or invalid. */
				return sprintf( __( "manifest.json is missing or has an invalid '%s'.", 'babilim' ), $key );
			}
		}
		if ( ! isset( $manifest['entries'] ) || ! is_int( $manifest['entries'] ) || $manifest['entries'] < 0 ) {
			return __( "manifest.json is missing or has an invalid 'entries'.", 'babilim' );
		}
		if ( ! isset( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			return __( "manifest.json is missing or has an invalid 'files'.", 'babilim' );
		}
		if ( ! isset( $manifest['extra_domains'] ) || ! is_array( $manifest['extra_domains'] ) ) {
			return __( "manifest.json is missing or has an invalid 'extra_domains'.", 'babilim' );
		}
		if ( ! isset( $manifest['build_no'] ) || ! is_int( $manifest['build_no'] ) || $manifest['build_no'] < 1 ) {
			return __( "manifest.json is missing or has an invalid 'build_no'.", 'babilim' );
		}
		if ( 1 !== preg_match( '/^bd_[0-9a-f]{12}$/D', (string) $manifest['server_build_id'] ) ) {
			return __( "manifest.json's 'server_build_id' is not a valid build id.", 'babilim' );
		}
		if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', (string) $manifest['contract'] ) ) {
			return __( "manifest.json's 'contract' is not a valid version string.", 'babilim' );
		}
		if ( 'published' !== $manifest['status'] ) {
			return __( "manifest.json's 'status' must be 'published'.", 'babilim' );
		}
		if ( ! in_array( $manifest['kind'], array( 'plugin', 'theme' ), true ) ) {
			return __( "manifest.json's 'kind' must be 'plugin' or 'theme'.", 'babilim' );
		}
		if ( array_key_exists( 'default_domain_entries', $manifest ) && ( ! is_int( $manifest['default_domain_entries'] ) || $manifest['default_domain_entries'] < 0 ) ) {
			return __( "manifest.json has an invalid 'default_domain_entries'.", 'babilim' );
		}
		if ( array_key_exists( 'default_domain_files', $manifest ) && ! is_array( $manifest['default_domain_files'] ) ) {
			return __( "manifest.json has an invalid 'default_domain_files'.", 'babilim' );
		}
		return null;
	}

	/**
	 * A lightweight check against just the download *identity*
	 * (server_build_id/slug/locale) -- what Babilim_Admin::do_download()
	 * (install()'s only production caller) actually knows ahead of a
	 * download; `POST /v1/downloads` only ever returns a signed Location,
	 * never a manifest. This is NOT the full
	 * package-manifest shape check -- that runs, strictly
	 * (validate_package_manifest_shape() above), against the zip's own
	 * manifest.json once it's actually in hand (see install()), which is
	 * then used as the real expected manifest for everything else.
	 *
	 * @param array $expected_manifest Caller-supplied identity.
	 * @return string|null An error message, or null if it passes.
	 */
	private static function validate_expected_identity( array $expected_manifest ): ?string {
		foreach ( array( 'server_build_id', 'slug', 'locale' ) as $key ) {
			if ( ! isset( $expected_manifest[ $key ] ) || ! is_string( $expected_manifest[ $key ] ) || '' === $expected_manifest[ $key ] ) {
				/* translators: %s: the expected-manifest field that is missing or invalid. */
				return sprintf( __( "expected_manifest is missing or has an invalid '%s'.", 'babilim' ), $key );
			}
		}
		if ( 1 !== preg_match( '/^bd_[0-9a-f]{12}$/D', (string) $expected_manifest['server_build_id'] ) ) {
			return __( "expected_manifest's 'server_build_id' is not a valid build id.", 'babilim' );
		}
		return null;
	}

	/**
	 * Recomputes sha256 for every file the current record claims (main
	 * and default supplement) and compares it against the manifest's
	 * own stored digest, flagging the record's `integrity` field either
	 * way.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $domain Component identity (slug) -- despite the parameter's name, this must be the same slug install() recorded the component under, not necessarily its text domain.
	 * @param string $locale Locale code.
	 * @return Babilim_Result
	 */
	public function verify( string $kind, string $domain, string $locale ): Babilim_Result {
		$guard = $this->guard_identity( $kind, $domain, $locale );
		if ( null !== $guard ) {
			return $guard;
		}
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}

		$record = $this->manifest->installed( $kind, $domain, $locale );
		if ( null === $record ) {
			return Babilim_Result::error( 'not_installed', sprintf( /* translators: %s: the kind/domain/locale identifier that has no installed record. */ __( 'No record for %s', 'babilim' ), "{$kind}/{$domain}/{$locale}" ) );
		}

		// A degraded record owns no files, so there is nothing to compare;
		// checking it would find no mismatch and wrongly mark it ok again.
		// Only installing or rolling back the build makes it normal.
		if ( empty( $record['degraded_at'] ) && array() === (array) ( $record['files'] ?? array() ) && array() === (array) ( $record['default_files'] ?? array() ) ) {
			// A record left by an earlier release's degrade: an install
			// never leaves a record without files, so empty lists mean the
			// served files were removed. Mark it degraded, and failed, so
			// the next inventory reports it. No client event is recorded
			// here: the service notes the first transition to failed itself.
			$checked                = (string) ( $record['integrity']['checked_at'] ?? '' );
			$record['degraded_at']  = '' !== $checked ? $checked : gmdate( 'c' );
			$record['integrity']    = array(
				'checked_at' => '' !== $checked ? $checked : $record['degraded_at'],
				'ok'         => false,
			);
			if ( ! $this->manifest->set( $kind, $domain, $locale, $record ) ) {
				return self::manifest_write_failed( $this->manifest );
			}
		}
		if ( ! empty( $record['degraded_at'] ) ) {
			return Babilim_Result::ok( array( 'mismatches' => array(), 'degraded' => true ) );
		}

		$mismatches = array();
		foreach ( array_merge( $record['files'] ?? array(), $record['default_files'] ?? array() ) as $relative => $expected_sha ) {
			$actual = $this->fs->sha256( Babilim_Paths::root() . '/' . $relative );
			if ( ! is_string( $actual ) || $actual !== $expected_sha ) {
				$mismatches[] = $relative;
			}
		}

		$ok                     = empty( $mismatches );
		$record['integrity']    = array(
			'checked_at' => gmdate( 'c' ),
			'ok'         => $ok,
		);
		$recorded = $this->manifest->set( $kind, $domain, $locale, $record );

		if ( ! $ok ) {
			// Reported (so the caller still degrades the component) even
			// if recording the result itself failed.
			return Babilim_Result::error( 'integrity_failed', __( 'One or more served files no longer match the manifest.', 'babilim' ), array( 'mismatches' => $mismatches ) );
		}
		if ( ! $recorded ) {
			return self::manifest_write_failed( $this->manifest );
		}
		return Babilim_Result::ok( array( 'mismatches' => array() ) );
	}

	/**
	 * Removes this domain's Babilim-layer served files (main + default
	 * supplement) -- letting WordPress fall back to a wp.org language
	 * pack, a bundled translation, or a site's own `custom/` override --
	 * while keeping `_versions` history and the manifest record itself
	 * (with `files`/`default_files` cleared, since nothing is served any
	 * more, and marked failed and degraded).
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $domain Component identity (slug) -- despite the parameter's name, this must be the same slug install() recorded the component under, not necessarily its text domain.
	 * @param string $locale Locale code.
	 * @return Babilim_Result
	 */
	public function degrade( string $kind, string $domain, string $locale ): Babilim_Result {
		$guard = $this->guard_identity( $kind, $domain, $locale );
		if ( null !== $guard ) {
			return $guard;
		}
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}

		$record = $this->manifest->installed( $kind, $domain, $locale );
		if ( null === $record ) {
			return Babilim_Result::error( 'not_installed', sprintf( /* translators: %s: the kind/domain/locale identifier that has no installed record. */ __( 'No record for %s', 'babilim' ), "{$kind}/{$domain}/{$locale}" ) );
		}

		foreach ( array_merge( array_keys( $record['files'] ?? array() ), array_keys( $record['default_files'] ?? array() ) ) as $relative ) {
			$this->fs->delete( Babilim_Paths::root() . '/' . $relative );
		}
		self::invalidate_cache_for_dir( Babilim_Paths::dir( $this->kind_dir( $kind ) ) );
		self::invalidate_cache_for_dir( Babilim_Paths::dir( 'default/_parts' ) );

		// The record stays (build id, history) but is marked failed and
		// degraded: the next inventory reports it as failed, and the
		// translations list offers to download the build again. A fresh
		// install or a rollback replaces the whole record.
		$now                     = gmdate( 'c' );
		$record['files']         = array();
		$record['default_files'] = array();
		$record['degraded_at']   = $now;
		$record['integrity']     = array(
			'checked_at' => $now,
			'ok'         => false,
		);
		$recorded = $this->manifest->set( $kind, $domain, $locale, $record );

		// degrade() removes this domain's default-supplement
		// fragment too, so the merged index for $locale can no longer
		// include it -- the files are gone whether or not the record
		// itself was updated.
		do_action( 'babilim_layer_changed', $locale );

		if ( ! $recorded ) {
			return self::manifest_write_failed( $this->manifest );
		}

		return Babilim_Result::ok( array( 'kind' => $kind, 'domain' => $domain, 'locale' => $locale ) );
	}

	/**
	 * Swaps the currently-served build back to the newest build in the
	 * manifest's version_history that differs from the current one --
	 * fails if the history holds no such build.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $domain Component identity (slug) -- despite the parameter's name, this must be the same slug install() recorded the component under, not necessarily its text domain.
	 * @param string $locale Locale code.
	 * @return Babilim_Result
	 */
	public function rollback( string $kind, string $domain, string $locale ): Babilim_Result {
		$guard = $this->guard_identity( $kind, $domain, $locale );
		if ( null !== $guard ) {
			return $guard;
		}
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}

		$record = $this->manifest->installed( $kind, $domain, $locale );
		if ( null === $record ) {
			return Babilim_Result::error( 'not_installed', sprintf( /* translators: %s: the kind/domain/locale identifier that has no installed record. */ __( 'No record for %s', 'babilim' ), "{$kind}/{$domain}/{$locale}" ) );
		}

		$history = array_values( $record['version_history'] ?? array() );
		$current = $history[0] ?? null;

		// The newest entry that is a different build from the current
		// one (a record written by an older version can carry the
		// current build again behind its head).
		$previous = null;
		foreach ( $history as $index => $entry ) {
			if ( $index > 0 && (string) ( $entry['build_id'] ?? '' ) !== (string) $current['build_id'] ) {
				$previous = $entry;
				break;
			}
		}
		if ( null === $previous ) {
			return Babilim_Result::error( 'no_previous_version', __( 'No previous build to roll back to.', 'babilim' ) );
		}

		$kind_dir = $this->kind_dir( $kind );

		// Mirrors install()'s own served_files_owned_elsewhere() check
		// (see that method's docblock): between $previous being the
		// active build and now, this component's own extra_domains can
		// have changed (dropping a filename from $current's served set
		// without deleting the stale file -- finalize_build() only ever
		// replaces what the new plan actually lists), leaving that exact
		// served path unowned; a different component's later install()
		// is then free to take it over, legitimately. A rollback that
		// blindly renamed $previous's history copy back into place would
		// silently overwrite that other component's now-owned file --
		// checked here, before finalize_build() writes anything, so a
		// conflict leaves every file exactly as it was.
		$conflict = $this->rollback_files_owned_elsewhere( $kind, $domain, $kind_dir, $previous );
		if ( null !== $conflict ) {
			return $conflict;
		}

		$plan     = array(
			'kind_dir'           => $kind_dir,
			'domain'             => $domain,
			'locale'             => $locale,
			'staging'            => $previous['staging'],
			'build_id'           => $previous['build_id'],
			'main_served'        => $previous['main_served'],
			'default_served'     => $previous['default_served'],
			'component_version' => $previous['component_version'],
			'pipeline_build_id' => $previous['pipeline_build_id'],
			'build_no'           => $previous['build_no'],
			'built_at'           => $previous['built_at'],
		);

		// finalize_build() would push a *new* history entry; for a
		// rollback we instead swap the two existing entries so a repeat
		// rollback toggles back and forth, and prune_history() never
		// runs (nothing new was staged).
		$result = $this->finalize_build( $plan, false );
		if ( $result->is_error() ) {
			return $result;
		}

		$record                       = $this->manifest->installed( $kind, $domain, $locale );
		$rest                        = array();
		foreach ( $history as $index => $entry ) {
			$entry_build = (string) ( $entry['build_id'] ?? '' );
			if ( 0 !== $index && $entry_build !== (string) $previous['build_id'] && $entry_build !== (string) $current['build_id'] ) {
				$rest[] = $entry;
			}
		}
		$record['version_history']   = array_merge( array( $previous, $current ), $rest );
		$record['previous_build_id'] = $current['build_id'];
		if ( ! $this->manifest->set( $kind, $domain, $locale, $record ) ) {
			return self::manifest_write_failed( $this->manifest );
		}

		return Babilim_Result::ok( array( 'build_id' => $previous['build_id'] ) );
	}

	/**
	 * Removes a component's served files and its manifest record
	 * entirely. `_versions` history and `custom/` are untouched.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $domain Component identity (slug) -- despite the parameter's name, this must be the same slug install() recorded the component under, not necessarily its text domain.
	 * @param string $locale Locale code.
	 * @return Babilim_Result
	 */
	public function remove( string $kind, string $domain, string $locale ): Babilim_Result {
		$guard = $this->guard_identity( $kind, $domain, $locale );
		if ( null !== $guard ) {
			return $guard;
		}
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}

		$record = $this->manifest->installed( $kind, $domain, $locale );
		if ( null === $record ) {
			return Babilim_Result::error( 'not_installed', sprintf( /* translators: %s: the kind/domain/locale identifier that has no installed record. */ __( 'No record for %s', 'babilim' ), "{$kind}/{$domain}/{$locale}" ) );
		}

		foreach ( array_merge( array_keys( $record['files'] ?? array() ), array_keys( $record['default_files'] ?? array() ) ) as $relative ) {
			$this->fs->delete( Babilim_Paths::root() . '/' . $relative );
		}
		self::invalidate_cache_for_dir( Babilim_Paths::dir( $this->kind_dir( $kind ) ) );
		self::invalidate_cache_for_dir( Babilim_Paths::dir( 'default/_parts' ) );

		$recorded = $this->manifest->remove( $kind, $domain, $locale );

		// Mirrors remove()'s own trigger above.
		do_action( 'babilim_layer_changed', $locale );

		if ( ! $recorded ) {
			return self::manifest_write_failed( $this->manifest );
		}

		return Babilim_Result::ok( array( 'kind' => $kind, 'domain' => $domain, 'locale' => $locale ) );
	}

	/**
	 * Removes every installed record's served files and manifest
	 * records, plus the merged `default` index files. When $keep_custom
	 * is false, also clears every file under `custom/`.
	 *
	 * @param bool $keep_custom Whether to leave `custom/` alone.
	 * @return Babilim_Result
	 */
	public function purge_all( bool $keep_custom ): Babilim_Result {
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}

		$removed = array();
		foreach ( $this->manifest->all() as $record ) {
			$result = $this->remove( $record['kind'], $record['slug'], $record['locale'] );
			if ( $result->is_ok() ) {
				// Only a genuinely successful remove() is reported as
				// removed -- previously every record was unconditionally
				// added here regardless of what remove() actually did.
				$removed[] = array( 'kind' => $record['kind'], 'slug' => $record['slug'], 'locale' => $record['locale'] );
			}
		}

		foreach ( $this->fs->list( Babilim_Paths::dir( 'default' ) ) as $name ) {
			if ( 'index.php' === $name || '.htaccess' === $name ) {
				continue;
			}
			$this->fs->delete( Babilim_Paths::dir( 'default' ) . '/' . $name );
		}

		if ( ! $keep_custom ) {
			foreach ( array( 'custom/plugins', 'custom/themes' ) as $type ) {
				$dir = Babilim_Paths::dir( $type );
				foreach ( $this->fs->list( $dir ) as $name ) {
					if ( 'index.php' === $name || '.htaccess' === $name ) {
						continue;
					}
					$this->fs->delete( $dir . '/' . $name );
				}
			}
		}

		return Babilim_Result::ok( array( 'removed' => $removed, 'kept_custom' => $keep_custom ) );
	}

	/**
	 * Removes everything Babilim keeps under its root directory --
	 * served files, the `default/` supplements and their merged index,
	 * the `_versions/` history, staging directories and the manifest --
	 * whether or not the manifest still lists it. When $keep_custom is
	 * true, `custom/` and the root's own guard files are left in place;
	 * otherwise the emptied root directory is removed too. Best-effort:
	 * an entry that can't be removed never stops the rest.
	 *
	 * Used by uninstall; unlike purge_all() it leaves nothing behind.
	 *
	 * @param bool $keep_custom Whether to leave `custom/` alone.
	 */
	public function remove_managed_tree( bool $keep_custom ): void {
		$root = Babilim_Paths::root();
		try {
			$names = $this->fs->list( $root );
		} catch ( \Throwable $e ) {
			return;
		}

		foreach ( $names as $name ) {
			if ( $keep_custom && in_array( $name, array( 'custom', 'index.php', '.htaccess' ), true ) ) {
				continue;
			}
			$this->remove_tree_entry( $root . '/' . $name );
		}

		if ( ! $keep_custom ) {
			try {
				$this->fs->rmdir( $root );
			} catch ( \Throwable $e ) {
				// Best-effort -- see the method docblock.
			}
		}
	}

	/**
	 * Removes one file, or one directory and everything below it.
	 * Best-effort: never throws, and a failure on one entry doesn't stop
	 * its siblings.
	 *
	 * @param string $path Absolute path under Babilim_Paths::root().
	 */
	private function remove_tree_entry( string $path ): void {
		try {
			if ( ! $this->fs->is_dir( $path ) ) {
				$this->fs->delete( $path );
				return;
			}
			foreach ( $this->fs->list( $path ) as $name ) {
				$this->remove_tree_entry( $path . '/' . $name );
			}
			$this->fs->rmdir( $path );
		} catch ( \Throwable $e ) {
			// Best-effort -- see remove_managed_tree().
		}
	}

	// -- install() helpers ---------------------------------------------------

	/**
	 * The error every action returns when its own manifest write did not
	 * land (see Babilim_Manifest::last_save_failure()) -- the action is
	 * reported as failed, never as done.
	 *
	 * @param Babilim_Manifest $manifest The manifest whose write failed.
	 * @return Babilim_Result
	 */
	private static function manifest_write_failed( Babilim_Manifest $manifest ): Babilim_Result {
		$failure = $manifest->last_save_failure();
		return Babilim_Result::error(
			'manifest_write_failed',
			__( 'The change could not be recorded in the translation index; please try again.', 'babilim' ),
			array( 'reason' => is_array( $failure ) ? $failure['reason'] : null )
		);
	}

	/**
	 * Common kind/domain/locale validation shared by every public
	 * method.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $domain For install(), the component's slug or its text domain; for verify()/degrade()/rollback()/remove(), the component's slug -- either way, just the identity string being validated here.
	 * @param string $locale Locale code.
	 * @return Babilim_Result|null An error Result, or null if valid.
	 */
	private function guard_identity( string $kind, string $domain, string $locale ): ?Babilim_Result {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			return Babilim_Result::error( 'invalid_kind', sprintf( /* translators: %s: the unrecognized kind value. */ __( 'Unknown kind: %s', 'babilim' ), $kind ) );
		}
		if ( ! Babilim_Paths::is_safe_name( $domain ) ) {
			return Babilim_Result::error( 'invalid_domain', sprintf( /* translators: %s: the invalid domain. */ __( 'Unsafe domain: %s', 'babilim' ), $domain ) );
		}
		if ( ! Babilim_Paths::is_safe_locale( $locale ) ) {
			return Babilim_Result::error( 'invalid_locale', sprintf( /* translators: %s: the invalid locale code. */ __( 'Unsafe locale: %s', 'babilim' ), $locale ) );
		}
		return null;
	}

	/**
	 * The DIR_TYPES directory name for a singular `kind`.
	 *
	 * @param string $kind 'plugin'|'theme'.
	 * @return string 'plugins'|'themes'.
	 */
	private function kind_dir( string $kind ): string {
		return 'plugin' === $kind ? 'plugins' : 'themes';
	}

	/**
	 * Every text domain a main-file zip entry may legitimately belong
	 * to: the component's own $text_domain plus each of its declared
	 * extra_domains[] (see package-layout.md).
	 *
	 * @param string $text_domain        The package manifest's own text_domain.
	 * @param array  $expected_manifest  Package manifest.
	 * @return string[]
	 */
	private function target_domains( string $text_domain, array $expected_manifest ): array {
		$names = array( $text_domain );
		foreach ( (array) ( $expected_manifest['extra_domains'] ?? array() ) as $extra ) {
			$extra = (string) $extra;
			if ( Babilim_Paths::is_safe_name( $extra ) && ! in_array( $extra, $names, true ) ) {
				$names[] = $extra;
			}
		}
		return $names;
	}

	/**
	 * Refuses an install whose served main files would land on a path a
	 * different component (another kind+slug) already owns. Two
	 * components can legitimately declare the same text domain (an
	 * add-on reusing its parent plugin's, say), or one can list another's
	 * text domain among its extra_domains; installing the second would
	 * otherwise overwrite the first one's served files, make its verify()
	 * fail, and let removing the second delete the first one's files.
	 * Checked before anything is written, for every name in $names and
	 * every served extension, so a conflict leaves disk and manifest
	 * exactly as they were. The same component re-installing over its
	 * own files is never a conflict.
	 *
	 * @param string   $kind     'plugin'|'theme'.
	 * @param string   $slug     The installing component's slug.
	 * @param string   $kind_dir 'plugins'|'themes'.
	 * @param string[] $names    Every text domain this install serves.
	 * @param string   $locale   Locale code.
	 * @return Babilim_Result|null An error Result, or null if no conflict.
	 */
	private function served_files_owned_elsewhere( string $kind, string $slug, string $kind_dir, array $names, string $locale ): ?Babilim_Result {
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}
		foreach ( $names as $name ) {
			foreach ( array( 'mo', 'l10n.php', 'json' ) as $extension ) {
				$relative = $kind_dir . '/' . $name . '-' . $locale . '.' . $extension;
				$owner    = $this->manifest->owner_of( $relative );
				if ( null === $owner ) {
					continue;
				}
				if ( (string) ( $owner['kind'] ?? '' ) === $kind && (string) ( $owner['slug'] ?? '' ) === $slug ) {
					continue;
				}
				return Babilim_Result::error(
					'text_domain_in_use',
					sprintf(
						/* translators: 1: the text domain in conflict. 2: the relative file path. 3: the owning component's kind/slug. */
						__( "The text domain '%1\$s' (%2\$s) is already served for another installed component (%3\$s); nothing was installed.", 'babilim' ),
						$name,
						$relative,
						"{$owner['kind']}/{$owner['slug']}"
					)
				);
			}
		}
		return null;
	}

	/**
	 * rollback()'s own ownership check (see rollback()'s call site for
	 * why this can legitimately trigger). Unlike
	 * served_files_owned_elsewhere() above -- which has to enumerate every
	 * {name}-{locale}.{extension} combination a *fresh* install() could
	 * serve -- a rollback already knows the exact filenames $previous
	 * served (its own 'main_served'/'default_served' maps), so this
	 * checks those concrete paths directly instead of recomputing
	 * name/extension combinations. Same failure shape: the first path any
	 * other component (kind+slug) now owns aborts the whole rollback
	 * before anything is written; a path nobody currently owns, or that
	 * this same component still owns, is never a conflict.
	 *
	 * @param string $kind     'plugin'|'theme'.
	 * @param string $slug     The rolling-back component's own slug.
	 * @param string $kind_dir 'plugins'|'themes'.
	 * @param array  $previous The version_history entry (see finalize_build()) rollback() is about to restore.
	 * @return Babilim_Result|null An error Result, or null if no conflict.
	 */
	private function rollback_files_owned_elsewhere( string $kind, string $slug, string $kind_dir, array $previous ): ?Babilim_Result {
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}
		$targets = array();
		foreach ( array_keys( (array) ( $previous['main_served'] ?? array() ) ) as $filename ) {
			$targets[ $kind_dir . '/' . $filename ] = $filename;
		}
		foreach ( array_keys( (array) ( $previous['default_served'] ?? array() ) ) as $filename ) {
			$targets[ 'default/_parts/' . $filename ] = $filename;
		}
		foreach ( $targets as $relative => $filename ) {
			$owner = $this->manifest->owner_of( $relative );
			if ( null === $owner ) {
				continue;
			}
			if ( (string) ( $owner['kind'] ?? '' ) === $kind && (string) ( $owner['slug'] ?? '' ) === $slug ) {
				continue;
			}
			return Babilim_Result::error(
				'text_domain_in_use',
				sprintf(
					/* translators: 1: the served filename in conflict. 2: the relative file path. 3: the owning component's kind/slug. */
					__( "Rolling back would overwrite '%1\$s' (%2\$s), which is now served for another installed component (%3\$s); nothing was changed.", 'babilim' ),
					$filename,
					$relative,
					"{$owner['kind']}/{$owner['slug']}"
				)
			);
		}
		return null;
	}

	/**
	 * Sorts every zip entry (except `manifest.json`) into "main" (keyed
	 * by its bare zip-root filename) and "default" (keyed by its
	 * filename with the `default/` prefix stripped) buckets, dropping
	 * anything that doesn't match the expected shape or whose
	 * name/locale isn't one this install call is for.
	 *
	 * Each candidate name/locale pair is matched explicitly (rather than
	 * with one generic capturing regex) because a domain name and a
	 * locale code share the same allowed character set: a single regex
	 * like `([\w-]+)-([\w-]+)(?:-([0-9a-f]{32}))?\.ext` can't tell
	 * `woocommerce` + `zh_TW` apart from `woocommerce-zh_TW` + (nothing)
	 * when a script's own 32-hex-char md5 also happens to satisfy the
	 * "locale" group's own character class -- greedy backtracking picks
	 * whichever split matches at all, not necessarily the intended one.
	 * Trying each already-known name against the exact filename it would
	 * produce has no such ambiguity.
	 *
	 * @param array<string,string> $extracted Every zip entry's bytes.
	 * @param string[]              $names     Text domains this install accepts main files for.
	 * @param string                $slug      The component's own package-manifest `slug` --
	 *                                          default fragments are named `default/{slug}-{locale}.*`
	 *                                          per the contract (package-layout.md), which is
	 *                                          NOT necessarily the same string as any entry in
	 *                                          $names.
	 * @param string                $locale    Locale code this install is for.
	 * @return array{main: array<string,string>, default: array<string,string>}
	 */
	private function classify_entries( array $extracted, array $names, string $slug, string $locale ): array {
		$main    = array();
		$default = array();

		$locale_q         = preg_quote( $locale, '/' );
		$default_pattern  = '/^default\/' . preg_quote( $slug, '/' ) . '-' . $locale_q . '\.(po|mo)$/D';
		$main_patterns    = array();
		foreach ( $names as $name ) {
			$main_patterns[ $name ] = '/^' . preg_quote( $name, '/' ) . '-' . $locale_q . '(?:-[0-9a-f]{32})?\.(po|mo|json)$/D';
		}

		foreach ( $extracted as $relative => $bytes ) {
			$relative = (string) $relative;
			if ( 'manifest.json' === $relative ) {
				continue;
			}
			if ( 1 === preg_match( $default_pattern, $relative ) ) {
				$default[ substr( $relative, strlen( 'default/' ) ) ] = $bytes;
				continue;
			}
			foreach ( $main_patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $relative ) ) {
					$main[ $relative ] = $bytes;
					continue 2;
				}
			}
			// Anything else -- including a stray `.l10n.php` -- is
			// silently dropped: never staged, never served.
		}

		return array( 'main' => $main, 'default' => $default );
	}

	/**
	 * Cross-checks every file $expected_manifest declares (in `files`
	 * and `default_domain_files`) against the classified, extracted
	 * bytes: it must be present and its sha256 must match. Only files
	 * covered by the expected digest map are trusted forward -- an
	 * accepted-shape file with no corresponding digest entry is dropped,
	 * same as an unrecognized one. $expected_manifest is always either
	 * the zip's own manifest.json (see install()'s own docblock for
	 * why that's the trustworthy source, not whatever the caller
	 * supplied ahead of time), already run through the full, strict
	 * validate_package_manifest_shape() before reaching here.
	 *
	 * @param array $classified        classify_entries()'s result.
	 * @param array $expected_manifest Package manifest.
	 * @return Babilim_Result Error, or ok() with data {main, default} --
	 *         each filename => bytes, restricted to verified entries.
	 */
	private function verify_against_expected( array $classified, array $expected_manifest ): Babilim_Result {
		$main_verified = array();
		foreach ( (array) ( $expected_manifest['files'] ?? array() ) as $filename => $digest ) {
			if ( ! isset( $classified['main'][ $filename ] ) ) {
				return Babilim_Result::error( 'missing_expected_file', sprintf( /* translators: %s: the filename missing from the zip. */ __( 'Zip is missing expected file: %s', 'babilim' ), $filename ) );
			}
			$actual = hash( 'sha256', $classified['main'][ $filename ] );
			if ( $actual !== (string) ( $digest['sha256'] ?? '' ) ) {
				return Babilim_Result::error( 'sha256_mismatch', sprintf( /* translators: %s: the filename whose checksum did not match. */ __( 'sha256 mismatch for %s', 'babilim' ), $filename ) );
			}
			$main_verified[ $filename ] = $classified['main'][ $filename ];
		}
		if ( empty( $main_verified ) ) {
			// A package with zero main files is never a valid install,
			// no matter how it got that way (an empty `files` map, or
			// one whose declared files just didn't classify as `main`)
			// -- never silently reported as a success that installed
			// nothing.
			return Babilim_Result::error( 'no_main_files', __( 'The package declares no main translation file.', 'babilim' ) );
		}

		$default_verified = array();
		foreach ( (array) ( $expected_manifest['default_domain_files'] ?? array() ) as $filename => $digest ) {
			if ( ! isset( $classified['default'][ $filename ] ) ) {
				return Babilim_Result::error( 'missing_expected_file', sprintf( /* translators: %s: the filename missing from the zip's default-domain supplement. */ __( 'Zip is missing expected default file: %s', 'babilim' ), $filename ) );
			}
			$actual = hash( 'sha256', $classified['default'][ $filename ] );
			if ( $actual !== (string) ( $digest['sha256'] ?? '' ) ) {
				return Babilim_Result::error( 'sha256_mismatch', sprintf( /* translators: %s: the filename whose checksum did not match. */ __( 'sha256 mismatch for default/%s', 'babilim' ), $filename ) );
			}
			$default_verified[ $filename ] = $classified['default'][ $filename ];
		}

		return Babilim_Result::ok( array( 'main' => $main_verified, 'default' => $default_verified ) );
	}

	/**
	 * Writes every verified file's raw bytes into the staging directory
	 * (main files flat; default fragments prefixed to avoid ever
	 * colliding with a same-named main file -- see the class docblock).
	 *
	 * @param string $staging Staging directory.
	 * @param array  $main    filename => bytes.
	 * @param array  $default filename => bytes.
	 * @return Babilim_Result Error, or ok() with data {written: string[]} (paths actually written).
	 */
	private function stage_files( string $staging, array $main, array $default ): Babilim_Result {
		$written = array();
		foreach ( $main as $filename => $bytes ) {
			$path = $staging . '/' . $filename;
			if ( ! $this->fs->put( $path, $bytes ) ) {
				return Babilim_Result::error( 'staging_write_failed', sprintf( /* translators: %s: the filename that failed to stage. */ __( 'Could not stage %s', 'babilim' ), $filename ), array( 'written' => $written ) );
			}
			$written[] = $path;
		}
		foreach ( $default as $filename => $bytes ) {
			$path = $staging . '/_default__' . $filename;
			if ( ! $this->fs->put( $path, $bytes ) ) {
				return Babilim_Result::error( 'staging_write_failed', sprintf( /* translators: %s: the filename that failed to stage. */ __( 'Could not stage default/%s', 'babilim' ), $filename ), array( 'written' => $written ) );
			}
			$written[] = $path;
		}
		return Babilim_Result::ok( array( 'written' => $written ) );
	}

	/**
	 * For every target domain's own `.mo`: checks its parsed entry
	 * count (the primary domain's count is additionally compared
	 * against $expected_manifest['entries']), transforms it to
	 * `.l10n.php` and re-parses that, checking the two counts match.
	 * For the default fragment's own `.mo` (if any): checks its entry
	 * count against $expected_manifest['default_domain_entries']. Also
	 * merges every `{name}-{locale}-{md5}.json` script catalog for a
	 * given name into one staged `{name}-{locale}.json`.
	 *
	 * @param string $staging     Staging directory.
	 * @param array  $names       Every target text domain name.
	 * @param string $text_domain The primary (component's own) text domain.
	 * @param string $slug        The component's own package-manifest `slug` --
	 *                             the default fragment is named from this, not $text_domain.
	 * @param string $locale      Locale code.
	 * @param array  $main        Verified main filename => bytes.
	 * @param array  $default     Verified default filename => bytes.
	 * @param array  $expected_manifest Package manifest.
	 * @return Babilim_Result Error, or ok() with data
	 *         {written, main_served: [served_filename => staged_path],
	 *          default_served: [served_filename => staged_path]}.
	 */
	private function build_derived_files( string $staging, array $names, string $text_domain, string $slug, string $locale, array $main, array $default, array $expected_manifest ): Babilim_Result {
		$written        = array();
		$main_served    = array();
		$default_served = array();

		foreach ( $names as $name ) {
			$mo_filename = $name . '-' . $locale . '.mo';
			if ( isset( $main[ $mo_filename ] ) ) {
				$mo_path = $staging . '/' . $mo_filename;
				$count   = call_user_func( $this->parse, $mo_path, 'mo' );
				if ( $name === $text_domain ) {
					if ( ! is_int( $count ) || $count !== (int) ( $expected_manifest['entries'] ?? -1 ) ) {
						return Babilim_Result::error( 'entries_mismatch', sprintf( /* translators: %s: the .mo filename whose entry count did not match. */ __( 'Entry count mismatch for %s', 'babilim' ), $mo_filename ), array( 'written' => $written ) );
					}
				} elseif ( ! is_int( $count ) || $count <= 0 ) {
					// An extra domain's own entry count isn't checked
					// against a specific expected value (the manifest
					// doesn't carry one per extra domain), but an
					// unparseable file ($parse returning false) must
					// still be rejected rather than silently waved
					// through.
					return Babilim_Result::error( 'entries_mismatch', sprintf( /* translators: %s: the .mo filename whose entry count could not be verified. */ __( 'Could not verify entry count for %s', 'babilim' ), $mo_filename ), array( 'written' => $written ) );
				}

				$php_src  = (string) call_user_func( $this->transform, $mo_path );
				$php_filename = $name . '-' . $locale . '.l10n.php';
				$php_path = $staging . '/' . $php_filename;
				if ( ! $this->fs->put( $php_path, $php_src ) ) {
					return Babilim_Result::error( 'staging_write_failed', sprintf( /* translators: %s: the filename that failed to stage. */ __( 'Could not stage %s', 'babilim' ), $php_filename ), array( 'written' => $written ) );
				}
				$written[] = $php_path;

				$php_count = call_user_func( $this->parse, $php_path, 'php' );
				// When $parse can't read the .l10n.php it returns
				// false, and a bare `!==` comparison against $count
				// (already proven a genuine int above) would still
				// correctly catch that -- but $php_count must itself be
				// checked as a genuine int too, not merely "not equal to
				// $count", so a future change to $count's own guards
				// above can't silently reopen the "both false" loophole
				// this replaced.
				if ( ! is_int( $php_count ) || $php_count !== $count ) {
					return Babilim_Result::error( 'l10n_entries_mismatch', sprintf( /* translators: %s: the .l10n.php filename whose entry count did not match. */ __( 'l10n.php entry count mismatch for %s', 'babilim' ), $php_filename ), array( 'written' => $written ) );
				}

				$main_served[ $mo_filename ]  = $mo_path;
				$main_served[ $php_filename ] = $php_path;
			}

			$json_group = array();
			foreach ( $main as $filename => $bytes ) {
				if ( 1 === preg_match( '/^' . preg_quote( $name . '-' . $locale, '/' ) . '-[0-9a-f]{32}\.json$/D', $filename ) ) {
					$json_group[ $filename ] = $bytes;
				}
			}
			if ( ! empty( $json_group ) ) {
				ksort( $json_group, SORT_STRING );
				$merged_bytes   = self::merge_script_catalogs( $json_group );
				$served_filename = $name . '-' . $locale . '.json';
				$staged_path      = $staging . '/_scriptmerge__' . $served_filename;
				if ( ! $this->fs->put( $staged_path, $merged_bytes ) ) {
					return Babilim_Result::error( 'staging_write_failed', sprintf( /* translators: %s: the filename that failed to stage. */ __( 'Could not stage %s', 'babilim' ), $served_filename ), array( 'written' => $written ) );
				}
				$written[]                         = $staged_path;
				$main_served[ $served_filename ]   = $staged_path;
			}
		}

		$default_mo_filename = $slug . '-' . $locale . '.mo';
		if ( isset( $default[ $default_mo_filename ] ) ) {
			$mo_path = $staging . '/_default__' . $default_mo_filename;
			$count   = call_user_func( $this->parse, $mo_path, 'mo' );
			if ( ! is_int( $count ) || $count !== (int) ( $expected_manifest['default_domain_entries'] ?? -1 ) ) {
				return Babilim_Result::error( 'default_entries_mismatch', __( 'Default supplement entry count mismatch.', 'babilim' ), array( 'written' => $written ) );
			}
			$default_served[ $default_mo_filename ] = $mo_path;
		}

		if ( empty( $main_served ) ) {
			// A backstop: install_from_entries() already refuses a
			// package without its own `{text_domain}-{locale}.mo`
			// (missing_text_domain_mo) before calling this, so a package
			// declaring nothing but a `.po` never reaches here today. Kept
			// so this method never reports a successful build of nothing
			// even if it is ever called without that check.
			return Babilim_Result::error( 'no_servable_main_files', __( 'The package produced no servable main translation file.', 'babilim' ), array( 'written' => $written ) );
		}

		return Babilim_Result::ok(
			array(
				'written'        => $written,
				'main_served'    => $main_served,
				'default_served' => $default_served,
			)
		);
	}

	/**
	 * Merges several Jed-format script catalogs (each already verified
	 * bytes) into one: `locale_data.messages` is the union of every
	 * catalog's own messages, keys merged in the given (sorted-filename)
	 * order with the first occurrence winning; the `""` header entry
	 * comes from the first catalog that has one.
	 *
	 * @param array<string,string> $sorted_json_group filename => raw JSON bytes, sorted by filename.
	 * @return string Merged JSON bytes.
	 */
	private static function merge_script_catalogs( array $sorted_json_group ): string {
		$merged_messages = array();
		$header          = null;
		$first           = null;

		foreach ( $sorted_json_group as $bytes ) {
			$decoded = json_decode( $bytes, true );
			if ( null === $first ) {
				$first = $decoded;
			}
			$messages = $decoded['locale_data']['messages'] ?? array();
			if ( null === $header && array_key_exists( '', $messages ) ) {
				$header = $messages[''];
			}
			unset( $messages[''] );
			$merged_messages += $messages;
		}

		if ( null !== $header ) {
			$merged_messages = array( '' => $header ) + $merged_messages;
		}

		// WP-CLI `make-json` (and wp.org language packs) always write
		// the literal string "messages" as the top-level `domain` --
		// never the component's actual text domain -- and
		// `locale_data.messages` is the one key the loader
		// ever reads. The removed `unset( $out['locale_data'][ $out['domain']
		// ?? '' ] )` line used to run *after* `messages` was written
		// above and, against that real shape, deleted `locale_data.messages`
		// itself -- every merged translation silently vanished. Fix:
		// never touch `locale_data` beyond setting `messages`, and pin
		// `domain` to the one value the loader (and everyone else) can
		// rely on rather than trusting whatever the first catalog
		// happened to carry.
		$out                            = is_array( $first ) ? $first : array();
		$out['domain']                  = 'messages';
		$out['locale_data']['messages'] = $merged_messages;

		return (string) wp_json_encode( $out );
	}

	/**
	 * Renames every served file from its staged/history source into its
	 * final serving directory, records the manifest, pushes a new
	 * version_history() entry (unless $push_history is false, for
	 * rollback()'s own swap), prunes anything falling off the retention
	 * window, deletes any now-stale served file a *different* previous
	 * build owned but this one doesn't, and -- on success --
	 * fires `babilim_layer_changed` so the merged `default` domain index
	 * gets rebuilt for this locale (see Babilim::
	 * register_default_index_triggers()).
	 *
	 * Every actual file placement (backing up whatever a rename is about
	 * to replace, and -- for rollback()'s own $push_history=false case --
	 * never consuming the durable `_history__` copy directly)
	 * is delegated to place_served_files(), called once for the main set
	 * and once for the default-supplement set, sharing one set of
	 * accumulators so a failure in either set unwinds *both*.
	 *
	 * @param array $plan          See callers for its keys.
	 * @param bool  $push_history  False for rollback() (which manages
	 *                             history itself); true otherwise.
	 * @return Babilim_Result
	 */
	private function finalize_build( array $plan, bool $push_history = true ): Babilim_Result {
		$kind_dir = $plan['kind_dir'];
		$kind     = 'plugins' === $kind_dir ? 'plugin' : 'theme';

		$renamed               = array(); // [dest, rename_source] pairs, in placement order.
		$backed_up             = array(); // [backup_path, dest] pairs -- a served file this call is about to replace.
		$created_history_paths = array(); // New durable _history__ copies made this call ($push_history only).
		$created_scratch_paths = array(); // New disposable rollback copies made this call (!$push_history only).

		$history_main_served = array();
		$files                = array();
		$error                = $this->place_served_files(
			$kind_dir, $plan['domain'], $plan['locale'], $plan['main_served'], Babilim_Paths::dir( $kind_dir ), $kind_dir, $push_history,
			$renamed, $backed_up, $created_history_paths, $created_scratch_paths, $history_main_served, $files
		);
		if ( null !== $error ) {
			$this->abort_finalize( $renamed, $backed_up, $created_history_paths, $created_scratch_paths );
			return $error;
		}

		$history_default_served = array();
		$default_files            = array();
		$error                     = $this->place_served_files(
			'default', $plan['domain'], $plan['locale'], $plan['default_served'], Babilim_Paths::dir( 'default/_parts' ), 'default/_parts', $push_history,
			$renamed, $backed_up, $created_history_paths, $created_scratch_paths, $history_default_served, $default_files
		);
		if ( null !== $error ) {
			$this->abort_finalize( $renamed, $backed_up, $created_history_paths, $created_scratch_paths );
			return $error;
		}

		self::invalidate_cache_for_dir( Babilim_Paths::dir( $kind_dir ) );
		if ( ! empty( $default_files ) ) {
			self::invalidate_cache_for_dir( Babilim_Paths::dir( 'default/_parts' ) );
		}

		$existing = $this->manifest->installed( $kind, $plan['domain'], $plan['locale'] );
		$existing = $existing ?? array();
		$history  = $existing['version_history'] ?? array();

		$history_entry = array(
			'build_id'           => $plan['build_id'],
			'component_version' => $plan['component_version'],
			'pipeline_build_id' => $plan['pipeline_build_id'],
			'build_no'           => $plan['build_no'],
			'built_at'           => $plan['built_at'],
			'staging'            => $plan['staging'],
			'main_served'        => $history_main_served,
			'default_served'     => $history_default_served,
		);

		$pruned = array();
		if ( $push_history ) {
			// One build appears once: an older entry for the same build
			// (and, for a record written by an older version, any
			// repeated entry) is dropped, not pruned -- its files are
			// the staged files of a build that stays in the history, so
			// they must not reach $pruned.
			$seen    = array( (string) $plan['build_id'] => true );
			$deduped = array();
			foreach ( $history as $entry ) {
				$entry_build = (string) ( $entry['build_id'] ?? '' );
				if ( isset( $seen[ $entry_build ] ) ) {
					continue;
				}
				$seen[ $entry_build ] = true;
				$deduped[]            = $entry;
			}
			$history = $deduped;
			array_unshift( $history, $history_entry );
			while ( count( $history ) > self::KEEP_VERSIONS ) {
				$dropped  = array_pop( $history );
				$pruned = array_merge( $pruned, array_values( $dropped['main_served'] ?? array() ), array_values( $dropped['default_served'] ?? array() ) );
			}
		}

		// The build served before this one -- for a reinstall of the same
		// build, the newest different build behind it (if any).
		$previous_build_id = $existing['build_id'] ?? null;
		if ( $push_history && $previous_build_id === $plan['build_id'] ) {
			$previous_build_id = $history[1]['build_id'] ?? null;
		}

		$record = array(
			'component_version' => $plan['component_version'],
			'text_domain'        => (string) ( $plan['text_domain'] ?? $plan['domain'] ),
			'build_id'           => $plan['build_id'],
			'pipeline_build_id' => $plan['pipeline_build_id'],
			'build_no'           => $plan['build_no'],
			'built_at'           => $plan['built_at'],
			'files'              => $files,
			'default_files'      => $default_files,
			'installed_at'       => gmdate( 'c' ),
			'previous_build_id'  => $previous_build_id,
			'integrity'          => array(
				'checked_at' => gmdate( 'c' ),
				'ok'         => true,
			),
			'orphaned_at'        => null,
			'version_history'    => $history,
		);

		if ( ! $this->manifest->set( $kind, $plan['domain'], $plan['locale'], $record ) ) {
			// The manifest still describes the previous build: put the
			// previous served files back too, so what is served and what
			// is recorded never disagree, and report the failure.
			$this->abort_finalize( $renamed, $backed_up, $created_history_paths, $created_scratch_paths );
			return self::manifest_write_failed( $this->manifest );
		}

		// Every file placed and recorded -- the backups taken above are
		// no longer needed (see place_served_files()'s own docblock note).
		foreach ( $backed_up as list( $backup_path, $unused_dest ) ) {
			$this->fs->delete( $backup_path );
		}
		foreach ( $pruned as $stale_path ) {
			$this->fs->delete( $stale_path );
		}

		// A served file the previous record owned but this build's
		// new file set doesn't (e.g. last build shipped a JS catalog,
		// this one doesn't) is never touched by the rename loops above
		// (they only ever place files that ARE in this build) and so
		// would otherwise linger, still readable by the loader, until
		// the next sweep_orphans() happened to notice it's unregistered.
		$old_relative = array_merge( array_keys( $existing['files'] ?? array() ), array_keys( $existing['default_files'] ?? array() ) );
		$new_relative = array_merge( array_keys( $files ), array_keys( $default_files ) );
		foreach ( array_diff( $old_relative, $new_relative ) as $stale_relative ) {
			$this->fs->delete( Babilim_Paths::root() . '/' . $stale_relative );
		}

		// install()/rollback() (both funnel through here) count as a
		// layer change -- see Babilim::register_default_index_triggers().
		// remove()/degrade() fire the same action themselves, since
		// neither one calls finalize_build().
		do_action( 'babilim_layer_changed', $plan['locale'] );

		return Babilim_Result::ok(
			array(
				'build_id' => $plan['build_id'],
				'kind'     => $kind,
				'domain'   => $plan['domain'],
				'locale'   => $plan['locale'],
			)
		);
	}

	/**
	 * Places one served-file set (main, or default-supplement) for
	 * finalize_build(): for each (served_filename => source path) pair --
	 *
	 * - ($push_history) the source is a fresh staged file: a durable
	 *   `_history__` copy of it is made first (bytes read, then written
	 *   to history_copy_path()), and the ORIGINAL staged path is what
	 *   gets renamed into place (the "install" case).
	 * - (!$push_history, rollback()'s case) the source IS ALREADY a
	 *   durable `_history__` copy (rollback() passes a previous
	 *   version_history() entry's own `main_served`/`default_served`
	 *   paths straight through) -- consuming it directly with rename()
	 *   would destroy that archival copy the moment this rollback (or a
	 *   later one) succeeds, so its bytes are copied out to a disposable
	 *   scratch path instead, and THAT is what gets renamed; the
	 *   original `_history__` file is only ever read, never renamed.
	 *
	 * Either way, before the rename, whatever currently occupies $dest
	 * (an older build's served file) is backed up (bytes copied to a
	 * `_versions/{$backup_kind_dir}/{$domain}/{$locale}/{reserved build
	 * id}/{served_filename}` path) so a rename failure -- on THIS file or
	 * a later one in the same finalize_build() call -- can restore every
	 * file this call touched back to its exact previous bytes via
	 * abort_finalize(); a successful finalize_build() deletes the
	 * backups once every file in both sets has placed cleanly.
	 *
	 * @param string $backup_kind_dir 'plugins'|'themes'|'default' -- the version_dir() kind segment for this set's backups/scratch copies.
	 * @param string $domain          Component identity (slug).
	 * @param string $locale          Locale code.
	 * @param array  $served          served_filename => source path.
	 * @param string $target_dir      Absolute serving directory.
	 * @param string $relative_prefix Prefix for the $files_out keys, e.g. 'plugins' or 'default/_parts'.
	 * @param bool   $push_history    See finalize_build().
	 * @param array  &$renamed                [dest, rename_source] pairs so far (shared across both sets).
	 * @param array  &$backed_up              [backup_path, dest] pairs so far.
	 * @param array  &$created_history_paths  New durable history copies made this call.
	 * @param array  &$created_scratch_paths  New disposable rollback copies made this call.
	 * @param array  &$history_served_out     served_filename => durable history path (output).
	 * @param array  &$files_out              relative_path => sha256 (output).
	 * @return Babilim_Result|null Error, or null on success.
	 */
	private function place_served_files(
		string $backup_kind_dir, string $domain, string $locale, array $served, string $target_dir, string $relative_prefix, bool $push_history,
		array &$renamed, array &$backed_up, array &$created_history_paths, array &$created_scratch_paths,
		array &$history_served_out, array &$files_out
	): ?Babilim_Result {
		foreach ( $served as $served_filename => $source_path ) {
			if ( $push_history ) {
				$history_path = self::history_copy_path( $source_path );
				$bytes        = $this->fs->get( $source_path );
				if ( is_string( $bytes ) && $this->fs->put( $history_path, $bytes ) ) {
					$created_history_paths[] = $history_path;
				}
				$rename_source = $source_path;
			} else {
				$history_path  = $source_path; // Already the durable copy -- never renamed away, see this method's docblock.
				$rename_source = self::rollback_scratch_path( $source_path );
				$bytes         = $this->fs->get( $source_path );
				if ( ! is_string( $bytes ) || ! $this->fs->put( $rename_source, $bytes ) ) {
					return Babilim_Result::error( 'rollback_copy_failed', sprintf( /* translators: %s: the filename that could not be prepared for rollback. */ __( 'Could not prepare %s for rollback.', 'babilim' ), $served_filename ) );
				}
				$created_scratch_paths[] = $rename_source;
			}

			$dest = $target_dir . '/' . $served_filename;
			if ( $this->fs->exists( $dest ) ) {
				$backup_path = Babilim_Paths::version_dir( $backup_kind_dir, $domain, $locale, self::RENAME_BACKUP_BUILD_ID ) . '/' . $served_filename;
				$old_bytes   = $this->fs->get( $dest );
				if ( ! is_string( $old_bytes ) || ! $this->fs->put( $backup_path, $old_bytes ) ) {
					// A backup that can't actually be trusted must stop
					// this call before it ever touches $dest -- previously
					// a failed backup write was silently swallowed (just
					// one fewer entry in $backed_up) and the rename
					// proceeded anyway, so a LATER failure in this same
					// call would have nothing to restore $dest from.
					return Babilim_Result::error( 'backup_failed', sprintf( /* translators: %s: the filename that could not be backed up. */ __( 'Could not back up %s before replacing it.', 'babilim' ), $served_filename ) );
				}
				$backed_up[] = array( $backup_path, $dest );
			}

			if ( ! $this->fs->rename( $rename_source, $dest ) ) {
				return Babilim_Result::error( 'rename_failed', sprintf( /* translators: %s: the filename that could not be moved into place. */ __( 'Could not move %s into place.', 'babilim' ), $served_filename ) );
			}
			$renamed[]                                     = array( $dest, $rename_source );
			$history_served_out[ $served_filename ]        = $history_path;
			$sha = $this->fs->sha256( $dest );
			if ( ! is_string( $sha ) ) {
				return Babilim_Result::error( 'hash_failed', sprintf( /* translators: %s: the filename that could not be read back after it was moved into place. */ __( 'Could not read %s back after moving it into place.', 'babilim' ), $served_filename ) );
			}
			$files_out[ $relative_prefix . '/' . $served_filename ] = $sha;
		}
		return null;
	}

	/**
	 * Undoes everything place_served_files() did across both sets after a
	 * failure anywhere in finalize_build(): reverses every completed
	 * rename, restores every backed-up previous-build file, and deletes
	 * every new (never-consumed-by-a-successful-rename) history/scratch
	 * copy this call created -- so a failed install()/rollback() leaves
	 * every previously-served file exactly byte-identical to before, with
	 * no half-built leftovers.
	 *
	 * @param array $renamed               See place_served_files().
	 * @param array $backed_up             See place_served_files().
	 * @param array $created_history_paths See place_served_files().
	 * @param array $created_scratch_paths See place_served_files().
	 */
	private function abort_finalize( array $renamed, array $backed_up, array $created_history_paths, array $created_scratch_paths ): void {
		$this->rollback_renames( $renamed );
		foreach ( array_reverse( $backed_up ) as list( $backup_path, $dest ) ) {
			$this->fs->rename( $backup_path, $dest );
		}
		foreach ( $created_history_paths as $path ) {
			$this->fs->delete( $path );
		}
		foreach ( $created_scratch_paths as $path ) {
			$this->fs->delete( $path );
		}
	}

	/**
	 * The disposable copy path place_served_files() renames into place
	 * instead of the durable `_history__` file itself, for rollback()'s
	 * `$push_history = false` case -- same directory, distinct
	 * prefix, so it can never collide with (or be mistaken for) the
	 * archival copy it was read from.
	 *
	 * @param string $history_path The durable `_history__` source path.
	 * @return string
	 */
	private static function rollback_scratch_path( string $history_path ): string {
		return dirname( $history_path ) . '/_rbscratch__' . basename( $history_path );
	}

	/**
	 * The durable "history" duplicate path for a staged file about to be
	 * consumed by an atomic rename into a serving directory -- stripping
	 * any existing `_history__` prefix first so repeated rollback()
	 * toggles (which finalize_build() the previous entry's own history
	 * path right back through here) never grow the prefix without bound.
	 *
	 * @param string $staged_path A staging-directory path.
	 * @return string
	 */
	private static function history_copy_path( string $staged_path ): string {
		$dir      = dirname( $staged_path );
		$basename = preg_replace( '/^_history__/', '', basename( $staged_path ) );
		return $dir . '/_history__' . $basename;
	}

	/**
	 * Undoes a partial finalize_build() rename sequence: moves every
	 * already-renamed served file back to its staged path, so a later
	 * failure never leaves a served directory half-updated.
	 *
	 * @param array<int,array{0:string,1:string}> $renamed [dest, staged_path] pairs, in rename order.
	 */
	private function rollback_renames( array $renamed ): void {
		foreach ( array_reverse( $renamed ) as list( $dest, $staged_path ) ) {
			$this->fs->rename( $dest, $staged_path );
		}
	}

	/**
	 * Deletes every path in $paths (best-effort cleanup after a failed
	 * install(), never leaving half-staged files behind).
	 *
	 * @param string[] $paths Absolute paths.
	 */
	private function delete_all( array $paths ): void {
		foreach ( $paths as $path ) {
			$this->fs->delete( $path );
		}
	}

	/**
	 * wp_cache_delete() for a directory's `translation_files` cache
	 * entry, when the function exists.
	 *
	 * @param string $dir Directory whose cached lookup to invalidate.
	 */
	private static function invalidate_cache_for_dir( string $dir ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( md5( trailingslashit( $dir ) ), 'translation_files' );
		}
	}
}
