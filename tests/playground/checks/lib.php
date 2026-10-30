<?php
/**
 * Shared harness for this plugin's own WordPress Playground verification
 * checks (see docs/playground-results.md). Loaded once by controller.php, which
 * every check file is `require`d from in turn.
 *
 * Every check runs inside a real, booted WordPress instance (this
 * plugin's own production hooks already registered via the normal
 * `plugins_loaded` -> `Babilim::instance()->init()` path -- nothing here
 * builds a parallel/parallel-lite assembly of the plugin's classes),
 * driven by `wp eval-file` from outside a real WordPress Playground
 * `server` process. Results are appended to a plain text file under a
 * host-mounted directory (BABILIM_PG_RESULTS_FILE, defined in
 * wp-config.php by run-checks.sh) so the host-side orchestrator can read
 * them back after the Playground process is torn down.
 *
 * @package Babilim
 */

declare( strict_types=1 );

/**
 * Appends one `PASS|FAIL <id> <message>` line to the results file.
 */
function babilim_pg_result( string $id, bool $pass, string $message ): void {
	$line = ( $pass ? 'PASS' : 'FAIL' ) . ' ' . $id . ' ' . $message . "\n";
	$path = defined( 'BABILIM_PG_RESULTS_FILE' ) ? BABILIM_PG_RESULTS_FILE : ( sys_get_temp_dir() . '/babilim-pg-results.txt' );
	file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	fwrite( STDOUT, $line );
}

/**
 * Runs one check's body, catching any Throwable so one check's failure
 * (including a fatal-shaped one) never stops the checks after it. $fn
 * either returns nothing (implicit pass -- the check body itself asserts
 * via babilim_pg_assert()), or throws (fail, exception message reported).
 */
function babilim_pg_run( string $id, string $description, callable $fn ): void {
	try {
		$fn();
		babilim_pg_result( $id, true, $description );
	} catch ( Babilim_Pg_Assertion_Failed $e ) {
		babilim_pg_result( $id, false, $description . ' -- ' . $e->getMessage() );
	} catch ( \Throwable $e ) {
		babilim_pg_result( $id, false, $description . ' -- uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
	}
}

/**
 * Thrown by babilim_pg_assert() -- distinguished from an ordinary bug so
 * babilim_pg_run()'s FAIL message doesn't read "uncaught
 * Babilim_Pg_Assertion_Failed" for a deliberate assertion.
 */
final class Babilim_Pg_Assertion_Failed extends \RuntimeException {}

function babilim_pg_assert( bool $cond, string $message ): void {
	if ( ! $cond ) {
		throw new Babilim_Pg_Assertion_Failed( $message );
	}
}

/**
 * Writes a minimal, real plugin main file under wp-content/plugins/ so
 * get_plugins()/is_plugin_active()/activate_plugin() all see it exactly
 * as they would a real installed plugin -- never a fake stand-in read
 * directly by a check. Returns the plugin basename ("{slug}/{slug}.php").
 */
function babilim_pg_write_test_plugin( string $slug, string $version, ?string $text_domain = null ): string {
	$text_domain ??= $slug;
	$dir = WP_PLUGIN_DIR . '/' . $slug;
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	$php = "<?php\n/**\n * Plugin Name: Babilim Test Plugin ({$slug})\n * Version: {$version}\n * Text Domain: {$text_domain}\n */\n";
	file_put_contents( $dir . '/' . $slug . '.php', $php );

	// get_plugins() (WP core, wp-admin/includes/plugin.php) caches its
	// directory scan in the 'plugins' object-cache group, keyed by
	// folder -- populated by the FIRST call any earlier check's own
	// activate_plugin()/validate_plugin() already made. Every check in
	// this run shares one long-lived PHP process (see controller.php),
	// so without invalidating it here, a plugin file written by a LATER
	// check is invisible to that stale cached list and
	// activate_plugin() fails with "The plugin does not have a valid
	// header." even though the file is genuinely on disk and well-formed.
	if ( function_exists( 'wp_clean_plugins_cache' ) ) {
		wp_clean_plugins_cache( false );
	}

	return $slug . '/' . $slug . '.php';
}

/**
 * Rewrites an already-written test plugin's `Version:` header in place --
 * used by b3f to simulate a plugin update WITHOUT going through the real
 * update pipeline (the scenario under test is Babilim's own reconciliation
 * of a version mismatch, not WordPress's update mechanics).
 */
function babilim_pg_set_test_plugin_version( string $slug, string $version ): void {
	$path = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
	$src  = (string) file_get_contents( $path );
	$src  = (string) preg_replace( '/Version:\s*[^\n]*/', 'Version: ' . $version, $src );
	file_put_contents( $path, $src );
	if ( function_exists( 'wp_clean_plugins_cache' ) ) {
		wp_clean_plugins_cache( false );
	}
}

/**
 * Activates a plugin the way wp-admin would (requires wp-admin/includes/
 * plugin.php, which a cron/CLI/front-end context never auto-loads).
 */
function babilim_pg_activate( string $basename ): void {
	if ( ! function_exists( 'activate_plugin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$result = activate_plugin( $basename );
	babilim_pg_assert( ! is_wp_error( $result ), 'activate_plugin(' . $basename . ') failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
}

function babilim_pg_deactivate( string $basename ): void {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( array( $basename ) );
}

/**
 * Deactivates several plugins in ONE real deactivate_plugins() call --
 * the ordinary "select several, Bulk actions -> Deactivate" admin
 * screen. WordPress core fires `deactivated_plugin` once per plugin,
 * all from inside this single call's own per-plugin loop, before
 * `active_plugins` is written back even once -- see
 * Babilim::on_deactivated_plugin()'s own docblock. Deliberately a
 * separate helper from babilim_pg_deactivate(), not a loop calling it
 * once per plugin -- that would fire N separate deactivate_plugins()
 * calls instead of the one real batch call this is meant to reproduce.
 *
 * @param string[] $basenames Plugin basenames to deactivate together.
 */
function babilim_pg_deactivate_batch( array $basenames ): void {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( $basenames );
}

/** do_action( 'delete_plugin', $basename ). */
function babilim_pg_fire_delete_plugin( string $basename ): void {
	do_action( 'delete_plugin', $basename );
}

/**
 * Writes one component's default-domain supplement fragment straight
 * onto disk (babilim/default/_parts/{slug}-{locale}.mo) and registers a
 * matching manifest record -- the same minimal shape
 * tests/unit/test-default-index.php's own default_record() fixture
 * uses (kind/slug/locale/component_version/files/default_files/
 * orphaned_at), so Babilim_Default_Index::rebuild() includes it exactly
 * the way it would a record install() itself produced. `files`
 * is deliberately empty -- these fixtures exist only to exercise the
 * default-domain merge, not a main-file layer.
 */
function babilim_pg_seed_default_fragment( string $kind, string $slug, string $locale, string $version, array $entries ): void {
	$path = Babilim_Paths::file( 'default/_parts', $slug, $locale, 'mo' );
	wp_mkdir_p( dirname( $path ) );
	file_put_contents( $path, Babilim_Mo_Writer::write( $entries, array() ) );

	$relative = str_replace( Babilim_Paths::root() . '/', '', $path );
	list( , $manifest ) = babilim_pg_shared_fs_and_manifest(); // The same instance Babilim::instance() itself reads/writes through -- see that function's own docblock on why a separate ad-hoc instance here would silently lose this record.
	$manifest->set(
		$kind,
		$slug,
		$locale,
		array(
			'kind'               => $kind,
			'slug'               => $slug,
			'locale'             => $locale,
			'component_version'  => $version,
			'files'              => array(),
			'default_files'      => array( $relative => hash( 'sha256', (string) file_get_contents( $path ) ) ),
			'orphaned_at'        => null,
		)
	);
}

/** Rebuilds the merged default-domain index for one locale, through the real production wiring (Babilim::instance()'s own callables and its own shared fs()/manifest() -- see babilim_pg_shared_fs_and_manifest()'s own docblock). */
function babilim_pg_rebuild_default_index( string $locale ): Babilim_Result {
	list( $fs, $manifest ) = babilim_pg_shared_fs_and_manifest();
	$index    = new Babilim_Default_Index(
		$fs,
		$manifest,
		array( Babilim::instance(), 'active_components' ),
		array( Babilim::instance(), 'parse_entries' ),
		array( Babilim::instance(), 'core_has' ),
		array( Babilim::instance(), 'core_plural_forms' )
	);
	return $index->rebuild( $locale );
}

/** Reads back the merged default-domain index's entries (prefers .mo, matching Babilim_Loader::load_default_supplements()'s own preference) for a direct-content assertion, independent of whatever WordPress core itself did with load_textdomain(). */
function babilim_pg_read_merged_default( string $locale ): array {
	$mo = Babilim_Paths::merged_default( $locale, 'mo' );
	if ( is_readable( $mo ) && class_exists( 'WP_Translation_File' ) ) {
		$file = \WP_Translation_File::create( $mo );
		if ( false !== $file ) {
			return (array) $file->entries();
		}
	}
	return array();
}

/**
 * Switches the current request's locale for the duration of $fn --
 * exercises the real `switch_to_locale()`/`restore_current_locale()`
 * core API (which itself fires `change_locale`, the action
 * Babilim_Loader::load_default_supplements() is registered on), not a
 * hand-rolled stand-in.
 */
function babilim_pg_with_locale( string $locale, callable $fn ) {
	// switch_to_locale()'s real return value is NOT a success/failure
	// flag -- WordPress core returns false specifically when $locale
	// already equals the current one (a legitimate no-op, not an error),
	// and only pushes a locale switcher-stack entry (which
	// restore_current_locale() must then pop) when it actually returns
	// true. Popping unconditionally -- this helper's first, buggy version
	// -- pops a stack entry that was never pushed on the no-op path,
	// desyncing every subsequent switch_to_locale()/get_locale() call for
	// the rest of this same long-lived process (every other check in
	// this same controller run).
	$switched = switch_to_locale( $locale );
	try {
		return $fn();
	} finally {
		if ( $switched ) {
			restore_current_locale();
		}
	}
}

/**
 * Forces the NEXT babilim_pg_with_locale( $locale, ... ) call to be a
 * genuine locale transition (switch_to_locale() actually reloads
 * translations) rather than a same-locale no-op that would otherwise
 * silently keep serving whatever was already loaded before a file on
 * disk changed. 'en_GB' is reserved for exactly this (never used as a
 * real check locale) -- see run-checks.sh's own lang-stub list.
 */
function babilim_pg_reset_locale(): void {
	babilim_pg_with_locale( 'en_GB', static function () {} );
}

/**
 * Forces a genuinely fresh re-read of the merged `default` domain
 * supplement for $locale, bypassing whatever WordPress core's own
 * `$l10n['default']` object already holds in this same long-lived
 * controller process.
 *
 * This is NOT how a real inbound request works -- a real request loads
 * `default` exactly once, at boot; the staleness this guards against
 * only exists because this controller intentionally re-reads the SAME
 * locale's `default` domain more than once, WITHIN one continuous PHP
 * process, with a rebuild() (a real file change) in between -- something
 * a single real HTTP request never does. WordPress core's own
 * load_textdomain() merges a newly loaded MO INTO whatever `$l10n[
 * $domain]` already holds rather than replacing it (real core: `if (
 * isset( $l10n[$domain] ) ) { $mo->merge_with( $l10n[$domain] ); }`), so
 * a key present in the OLD load but absent from the NEW one (exactly
 * what "this component's default-domain contribution was just excluded"
 * looks like) can survive the merge. A plain unload_textdomain('default')
 * followed by calling Babilim_Loader::load_default_supplements($locale)
 * directly -- the same method WordPress's own `change_locale` action
 * would have called -- reproduces exactly what a fresh request's own
 * first load looks like, without depending on switch_to_locale()'s own
 * same-locale-no-op detection (which this repeated-read pattern can
 * otherwise defeat).
 */
function babilim_pg_reload_default_domain( string $locale ): void {
	unload_textdomain( 'default' );
	Babilim_Loader::load_default_supplements( $locale );
}

/** Recursive delete of a real directory this check itself created -- fixture teardown only, never how the plugin itself deletes anything (see Babilim_Fs). */
function babilim_pg_rrmdir( string $dir ): void {
	if ( is_link( $dir ) ) {
		unlink( $dir );
		return;
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path = $dir . '/' . $name;
		( is_dir( $path ) && ! is_link( $path ) ) ? babilim_pg_rrmdir( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

/**
 * Simulates a fresh WordPress request's plugin bootstrap: strips every
 * hook Babilim::instance()->init() (babilim.php's own `plugins_loaded`
 * callback) registers, then re-runs it against a brand-new singleton.
 *
 * Babilim::instance() shares exactly one Babilim_Fs/Babilim_Manifest
 * across every one of its own lazily-built collaborators for the whole
 * time it's alive (see class-babilim.php's manifest() docblock), so
 * resyncing before every single activate/deactivate/install call would
 * only mask a real regression there rather than test anything. This is
 * called only ONCE per check (see controller.php's own loop), not per
 * operation within one -- giving each check what amounts to its own
 * fresh `plugins_loaded`, matching a real request's own "build the
 * singleton exactly once" lifecycle, without erasing state a check
 * itself creates partway through its own scenario (see
 * docs/playground-results.md for why that matters).
 */
function babilim_pg_resync_singleton(): void {
	foreach ( array( 'activated_plugin', 'deactivated_plugin', 'switch_theme', 'upgrader_process_complete', 'delete_plugin', 'deleted_theme', 'babilim_layer_changed' ) as $tag ) {
		remove_all_actions( $tag );
	}
	remove_all_actions( 'rest_api_init' );
	Babilim::reset_for_tests();
	Babilim::instance()->init();
}

/** A fresh, unique-enough server_build_id, `bd_{12 hex}` shaped (Babilim_Paths::is_allowed()'s own pattern). */
function babilim_pg_build_id(): string {
	return 'bd_' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
}

/**
 * Babilim::instance()'s own shared, request-scoped Babilim_Fs/
 * Babilim_Manifest (see class-babilim.php's manifest() docblock for why
 * every writer must share exactly this pair, not build its own) --
 * reached via reflection since both accessors are private. Used
 * wherever a check needs to write through the manifest OUTSIDE
 * Babilim::instance()'s own lazily-built collaborators (build_installer(),
 * default_index(), ...) without reintroducing the same multi-instance
 * data-loss bug those collaborators themselves used to have.
 *
 * @return array{0: Babilim_Fs, 1: Babilim_Manifest}
 */
function babilim_pg_shared_fs_and_manifest(): array {
	$fs_method = new ReflectionMethod( Babilim::class, 'fs' );
	$fs_method->setAccessible( true );
	$manifest_method = new ReflectionMethod( Babilim::class, 'manifest' );
	$manifest_method->setAccessible( true );
	$instance = Babilim::instance();
	return array( $fs_method->invoke( $instance ), $manifest_method->invoke( $instance ) );
}

/**
 * Builds a real package zip for one component, the same shape the
 * download path hands to `Babilim_Installer::install()`: a
 * `manifest.json`, the main-domain `{slug}-{locale}.mo`, and (optionally)
 * a default-domain fragment at `default/{slug}-{locale}.mo`. `.l10n.php`
 * is never put in the zip (wp.org Guideline 8) -- install() produces it
 * itself, via the real `Babilim::transform_mo_to_l10n_php()`.
 *
 * @param string     $kind            'plugin'|'theme'.
 * @param string     $slug            Component slug (also its text domain).
 * @param string     $locale          Locale code.
 * @param string     $version         Component version.
 * @param array      $entries         Main-domain msgid => msgstr.
 * @param array|null $default_entries Default-domain fragment entries, or null for none.
 * @param array      $headers         MO headers for both files.
 * @return array{0: string, 1: array} [zip path, package manifest array].
 */
function babilim_pg_build_fixture_package( string $kind, string $slug, string $locale, string $version, array $entries, ?array $default_entries = null, array $headers = array() ): array {
	$mo = Babilim_Mo_Writer::write( $entries, $headers );
	$manifest_json = array(
		'build_id'        => "{$slug}-{$version}-{$locale}-20260101T000000-fixture",
		'entries'         => count( $entries ),
		'files'           => array( "{$slug}-{$locale}.mo" => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ) ),
		'locale'          => $locale,
		'status'          => 'published',
		'kind'            => $kind,
		'slug'            => $slug,
		'version'         => $version,
		'text_domain'     => $slug,
		'extra_domains'   => array(),
		'server_build_id' => babilim_pg_build_id(),
		'build_no'        => 1,
		'issued_at'       => '2026-01-01T00:00:00Z',
		'contract'        => '1.1.0',
	);

	$files = array( "{$slug}-{$locale}.mo" => $mo );
	if ( null !== $default_entries ) {
		$dmo = Babilim_Mo_Writer::write( $default_entries, $headers );
		$files[ "default/{$slug}-{$locale}.mo" ] = $dmo;
		$manifest_json['default_domain_entries'] = count( $default_entries );
		$manifest_json['default_domain_files']   = array( "{$slug}-{$locale}.mo" => array( 'sha256' => hash( 'sha256', $dmo ), 'size' => strlen( $dmo ) ) );
	}

	$zip_path = wp_tempnam( "babilim-fixture-{$slug}-{$locale}.zip" );
	$zip      = new ZipArchive();
	babilim_pg_assert( true === $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'could not create the fixture package zip.' );
	$zip->addFromString( 'manifest.json', wp_json_encode( $manifest_json ) );
	foreach ( $files as $name => $bytes ) {
		$zip->addFromString( $name, $bytes );
	}
	$zip->close();

	return array( $zip_path, $manifest_json );
}

/**
 * Installs one component's main-domain translation (and, optionally, its
 * default-domain fragment) through the REAL `Babilim_Installer::install()`
 * production path, from a real fixture zip (see
 * babilim_pg_build_fixture_package()).
 *
 * Used by checks (b7/b8/b9/b10) whose own point is CONTENT fidelity through
 * the real install pipeline, not the download transport. Writes through Babilim::instance()'s own shared
 * Babilim_Fs/Babilim_Manifest (babilim_pg_shared_fs_and_manifest()) --
 * not a separate instance of its own -- so a rebuild triggered by this
 * same install() (via `babilim_layer_changed`) reads back exactly what it
 * just wrote.
 *
 * @return Babilim_Result install()'s own result.
 */
function babilim_pg_install( string $kind, string $slug, string $locale, string $version, array $entries, ?array $default_entries = null, array $headers = array() ): Babilim_Result {
	list( $fs, $manifest ) = babilim_pg_shared_fs_and_manifest();
	$installer = new Babilim_Installer(
		$fs,
		$manifest,
		array( Babilim::instance(), 'transform_mo_to_l10n_php' ),
		array( Babilim::instance(), 'parse_translation_file_entries' ),
		array( Babilim::instance(), 'unzip_package' )
	);

	list( $zip_path, $manifest_json ) = babilim_pg_build_fixture_package( $kind, $slug, $locale, $version, $entries, $default_entries, $headers );
	$result = $installer->install( $kind, $slug, $locale, $zip_path, $manifest_json );
	wp_delete_file( $zip_path );
	return $result;
}
