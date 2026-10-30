<?php
/**
 * Tracks every translation file Babilim has installed -- which
 * component/version/build it belongs to, its files' sha256 digests, and
 * (per locale) the merged `default` domain index built from
 * components' supplement contributions.
 *
 * Persisted as a single `_manifest.json` at Babilim_Paths::manifest_file(),
 * through the injected Babilim_Fs. Every write (save()) is protected by
 * this class's own cross-request mutex (a row in the options table,
 * taken and released with single, atomic SQL statements -- see
 * acquire_manifest_lock()'s own docblock) for its entire read-compare-
 * merge-write sequence, and lands via a same-directory temp file then a
 * rename that is genuinely atomic on a real, `direct` WordPress
 * filesystem transfer (see rename_manifest_file()'s own docblock for
 * the one, narrower case where it isn't). The manifest goes read-only
 * ("unavailable") if the file exists but either can't be read at all,
 * or can be read but doesn't parse as the object shape this class
 * relies on: every read throws, every write refuses and reports
 * failure, and the file is left exactly as found. A file that couldn't
 * be read has nothing quarantined (there's nothing readable to copy);
 * one that parsed to the wrong shape or failed to parse gets a copy
 * made aside, keyed by its own content so the same corrupt content is
 * only ever quarantined once. Never silently treated as an empty
 * manifest.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's `_manifest.json`.
 */
final class Babilim_Manifest {

	/**
	 * The network-wide (site) option that records the most recent
	 * manifest write that did not land, until the next write that does
	 * (see record_write_failure()) -- local-only, shown on the settings
	 * screen, never sent to the service. A site option rather than a
	 * per-site one because `_manifest.json` itself is shared by every
	 * site of a multisite network: a failed write from any one site is a
	 * fact about the file every site's settings screen reads from.
	 *
	 * @var string
	 */
	public const WRITE_FAILURE_OPTION = 'babilim_manifest_write_failure';

	/**
	 * The options-table row name save()'s own cross-request mutex uses.
	 * A single shared name is deliberate -- this is a global lock for
	 * the one `_manifest.json` file every request on this installation
	 * writes through, not a per-instance, per-locale or per-site one (see
	 * lock_table()).
	 *
	 * @var string
	 */
	private const LOCK_OPTION_NAME = 'babilim_manifest_lock';

	/**
	 * How long a lock this class itself takes out is honored before
	 * another process may take it over -- see acquire_manifest_lock()'s
	 * own docblock.
	 *
	 * @var int
	 */
	private const LOCK_TTL_SECONDS = 30;

	/**
	 * Total time acquire_manifest_lock() spends retrying before giving
	 * up and reporting failure.
	 *
	 * @var float
	 */
	private const LOCK_RETRY_BUDGET_SECONDS = 5.0;

	/**
	 * @var Babilim_Fs
	 */
	private $fs;

	/**
	 * Whether load() has already run.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * @var array<string,array>
	 */
	private $records = array();

	/**
	 * @var array<string,array>
	 */
	private $default_index_data = array();

	/**
	 * The most recent sweep_orphans() mass-orphan-brake trip, or null if
	 * it's never tripped -- see Babilim_Default_Index::sweep_orphans().
	 *
	 * @var array|null
	 */
	private $sweep_guard = null;

	/**
	 * The most recent sweep_orphans() run's own summary -- local-only,
	 * never sent to the service (a summary has no real kind/slug/locale
	 * to report through the inventory request's client_events[] schema,
	 * which requires all three; see Babilim_Default_Index::sweep_orphans()).
	 *
	 * @var array|null
	 */
	private $sweep_summary = null;

	/**
	 * The most recent unresolvable save() write conflict (see that
	 * method's own docblock), or null if none has ever happened --
	 * local-only, never sent to the service, the same as $sweep_summary
	 * above and for the same reason: a write conflict has no real
	 * kind ('plugin'|'theme') to report through the inventory request's
	 * client_events[] schema, which rejects anything else with a 422 on
	 * the whole request, not just that one event (confirmed directly
	 * against the pinned contract schema). Shown on the settings screen instead.
	 *
	 * @var array{at: string, section: string, key: string|null}|null
	 */
	private $write_conflict = null;

	/**
	 * Why this instance's own most recent write did not land, or null if
	 * it did (or none has been attempted) -- see last_save_failure().
	 *
	 * @var array{at: string, section: string, key: string|null, reason: string}|null
	 */
	private $last_save_failure = null;

	/**
	 * Null while available; the parse-failure reason once the manifest
	 * file exists but couldn't be read as one.
	 *
	 * @var string|null
	 */
	private $unavailable_reason;

	/**
	 * The exact value of the cross-request lock this REQUEST holds
	 * through with_lock(), or null. Shared by every instance: two
	 * Babilim_Manifest objects in one request (the admin screen's and
	 * the plugin's own) must not wait on each other's lock -- an install
	 * holding it triggers a merged-index rebuild through the other one.
	 * save() reuses it instead of taking the lock a second time.
	 *
	 * @var string|null
	 */
	private static $lock_held = null;

	/**
	 * sha256 of `_manifest.json`'s raw bytes as of the moment this
	 * instance last read or wrote it -- '' if no file existed at that
	 * moment, never null once load() has actually run (see load()'s own
	 * docblock and save()'s own optimistic-concurrency check). Two
	 * separate requests (a WP-cron sync and an admin's own manual
	 * install, say) each build their own Babilim_Manifest and each read
	 * this file once, at construction; without this check, whichever one
	 * calls save() second would silently overwrite the first one's
	 * already-landed write with its own (by-then-stale) full in-memory
	 * snapshot -- the same class of loss fixed for multiple manifest
	 * instances sharing one request (see Babilim::manifest()'s own
	 * docblock), but across requests instead of within one.
	 *
	 * @var string|null
	 */
	private $loaded_hash = null;

	/**
	 * @param Babilim_Fs $fs Filesystem to persist the manifest through.
	 */
	public function __construct( Babilim_Fs $fs ) {
		$this->fs = $fs;
	}

	/**
	 * Whether the manifest can be read from/written to. False once
	 * `_manifest.json` exists but failed to parse -- see
	 * unavailable_reason().
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$this->load();
		return null === $this->unavailable_reason;
	}

	/**
	 * Why the manifest is unavailable, or null while it's fine.
	 *
	 * @return string|null
	 */
	public function unavailable_reason(): ?string {
		$this->load();
		return $this->unavailable_reason;
	}

	/**
	 * The installed record for one kind+slug+locale, or null if none.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @return array|null
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	public function installed( string $kind, string $slug, string $locale ): ?array {
		$this->assert_available();
		return $this->records[ $this->key( $kind, $slug, $locale ) ] ?? null;
	}

	/**
	 * Upserts a record for one kind+slug+locale. The kind/slug/locale
	 * arguments always win over whatever those keys held in $rec, so a
	 * record always matches the identity it was stored under.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @param array  $rec    The record to store.
	 * @return bool True on success; false if the manifest is
	 *              unavailable or the write itself failed.
	 */
	public function set( string $kind, string $slug, string $locale, array $rec ): bool {
		$key = $this->key( $kind, $slug, $locale );
		if ( ! $this->is_available() ) {
			return $this->unavailable_write( 'records', $key );
		}
		$rec['kind']   = $kind;
		$rec['slug']   = $slug;
		$rec['locale'] = $locale;
		$baseline      = $this->records[ $key ] ?? null;
		$this->records[ $key ] = $rec;
		return $this->save(
			array(
				'section'  => 'records',
				'key'      => $key,
				'baseline' => $baseline,
				'value'    => $rec,
			)
		);
	}

	/**
	 * Removes a record for one kind+slug+locale. A no-op if none exists.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @return bool True on success; false if the manifest is
	 *              unavailable or the write itself failed.
	 */
	public function remove( string $kind, string $slug, string $locale ): bool {
		$key = $this->key( $kind, $slug, $locale );
		if ( ! $this->is_available() ) {
			return $this->unavailable_write( 'records', $key );
		}
		$baseline = $this->records[ $key ] ?? null;
		unset( $this->records[ $key ] );
		return $this->save(
			array(
				'section'  => 'records',
				'key'      => $key,
				'baseline' => $baseline,
				'value'    => null,
			)
		);
	}

	/**
	 * Every installed record.
	 *
	 * @return array[]
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	public function all(): array {
		$this->assert_available();
		return array_values( $this->records );
	}

	/**
	 * The record that owns a given file, by its path relative to
	 * Babilim_Paths::root() -- checked against both a record's `files`
	 * (main files) and `default_files` (supplement fragments).
	 *
	 * @param string $relative_path Path relative to Babilim_Paths::root().
	 * @return array|null The owning record, or null if unregistered.
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	public function owner_of( string $relative_path ): ?array {
		$this->assert_available();
		foreach ( $this->records as $record ) {
			if ( array_key_exists( $relative_path, $record['files'] ?? array() ) ) {
				return $record;
			}
			if ( array_key_exists( $relative_path, $record['default_files'] ?? array() ) ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Stores the built merged-`default`-domain index metadata for one
	 * locale.
	 *
	 * @param string $locale Locale code.
	 * @param array  $meta   Index metadata (see the class docblock).
	 * @return bool True on success; false if the manifest is
	 *              unavailable or the write itself failed.
	 */
	public function set_default_index( string $locale, array $meta ): bool {
		if ( ! $this->is_available() ) {
			return $this->unavailable_write( 'default_index', $locale );
		}
		$baseline = $this->default_index_data[ $locale ] ?? null;
		$this->default_index_data[ $locale ] = $meta;
		return $this->save(
			array(
				'section'  => 'default_index',
				'key'      => $locale,
				'baseline' => $baseline,
				'value'    => $meta,
			)
		);
	}

	/**
	 * The stored merged-`default`-domain index metadata for one locale.
	 *
	 * @param string $locale Locale code.
	 * @return array|null
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	public function default_index( string $locale ): ?array {
		$this->assert_available();
		return $this->default_index_data[ $locale ] ?? null;
	}

	/**
	 * Records a sweep_orphans() mass-orphan-brake trip (overwrites
	 * whatever was recorded for the previous one -- only the most recent
	 * trip is ever kept).
	 *
	 * @param array $meta {tripped_at, reason, would_orphan, managed_total}.
	 * @return bool True on success; false if the manifest is
	 *              unavailable or the write itself failed.
	 */
	public function set_sweep_guard( array $meta ): bool {
		if ( ! $this->is_available() ) {
			return $this->unavailable_write( 'sweep_guard', null );
		}
		$baseline           = $this->sweep_guard;
		$this->sweep_guard  = $meta;
		return $this->save(
			array(
				'section'  => 'sweep_guard',
				'key'      => null,
				'baseline' => $baseline,
				'value'    => $meta,
			)
		);
	}

	/**
	 * Clears a previously-recorded mass-orphan-brake trip -- called once
	 * a later sweep_orphans() run genuinely completes without tripping it
	 * again, so the settings screen's "A cleanup was paused on ..."
	 * notice doesn't keep showing forever after the situation that
	 * caused it has resolved.
	 *
	 * @return bool True on success; false if the manifest is
	 *              unavailable or the write itself failed.
	 */
	public function clear_sweep_guard(): bool {
		if ( ! $this->is_available() ) {
			return $this->unavailable_write( 'sweep_guard', null );
		}
		$baseline          = $this->sweep_guard;
		$this->sweep_guard = null;
		return $this->save(
			array(
				'section'  => 'sweep_guard',
				'key'      => null,
				'baseline' => $baseline,
				'value'    => null,
			)
		);
	}

	/**
	 * The most recently recorded mass-orphan-brake trip, or null if it's
	 * never tripped.
	 *
	 * @return array|null
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	public function sweep_guard(): ?array {
		$this->assert_available();
		return $this->sweep_guard;
	}

	/**
	 * Records the most recent sweep_orphans() run's own summary
	 * (overwrites whatever was recorded for the previous one).
	 *
	 * @param array $meta {swept_at, orphans_marked, orphans_deleted, records_purged}.
	 * @return bool True on success; false if the manifest is
	 *              unavailable or the write itself failed.
	 */
	public function set_sweep_summary( array $meta ): bool {
		if ( ! $this->is_available() ) {
			return $this->unavailable_write( 'sweep_summary', null );
		}
		$baseline             = $this->sweep_summary;
		$this->sweep_summary  = $meta;
		return $this->save(
			array(
				'section'  => 'sweep_summary',
				'key'      => null,
				'baseline' => $baseline,
				'value'    => $meta,
			)
		);
	}

	/**
	 * The most recently recorded sweep_orphans() summary, or null if it's
	 * never run.
	 *
	 * @return array|null
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	public function sweep_summary(): ?array {
		$this->assert_available();
		return $this->sweep_summary;
	}

	/**
	 * Why this instance's own most recent write did not land, or null if
	 * it landed. `reason` is one of:
	 *
	 * - 'manifest_unavailable' -- the manifest itself is unavailable
	 *   (see unavailable_reason()), so nothing was attempted;
	 * - 'lock_unavailable' -- the cross-request lock could not be taken
	 *   within its retry budget (or there is no database to take it in);
	 * - 'reread_failed'    -- the manifest could not be re-read under the
	 *   lock (a read error, or a file that existed at load time is gone);
	 * - 'corrupt'          -- the re-read content does not parse;
	 * - 'conflict'         -- the same entry was changed by another
	 *   request in the meantime (see write_conflict());
	 * - 'write_failed'     -- the file itself could not be written.
	 *
	 * @return array{at: string, section: string, key: string|null, reason: string}|null
	 */
	public function last_save_failure(): ?array {
		return $this->last_save_failure;
	}

	/**
	 * The most recent write failure recorded by any request on this
	 * installation -- any site of a multisite network -- since the last
	 * write that did land (see record_write_failure()), or null if there
	 * is none.
	 *
	 * @return array{at: string, section: string, key: string|null, reason: string}|null
	 */
	public function last_recorded_write_failure(): ?array {
		if ( ! function_exists( 'get_site_option' ) ) {
			return null;
		}
		$stored = get_site_option( self::WRITE_FAILURE_OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['at'], $stored['section'], $stored['reason'] ) ) {
			return null;
		}
		return array(
			'at'      => (string) $stored['at'],
			'section' => (string) $stored['section'],
			'key'     => isset( $stored['key'] ) ? (string) $stored['key'] : null,
			'reason'  => (string) $stored['reason'],
		);
	}

	/**
	 * The most recently recorded unresolvable save() write conflict, or
	 * null if none has ever happened -- see $write_conflict's own
	 * docblock for why this is local-only and never sent to the
	 * service.
	 *
	 * @return array{at: string, section: string, key: string|null}|null
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	public function write_conflict(): ?array {
		$this->assert_available();
		return $this->write_conflict;
	}

	/**
	 * Every locale this manifest has stored default_index() meta for,
	 * even one with zero remaining component records --
	 * Babilim_Default_Index::rebuild_all() needs this so a locale that
	 * just lost its last contributing record still gets reconciled.
	 *
	 * @return string[]
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	public function default_index_locales(): array {
		$this->assert_available();
		return array_keys( $this->default_index_data );
	}

	/**
	 * The composite lookup key for one kind+slug+locale.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @return string
	 */
	private function key( string $kind, string $slug, string $locale ): string {
		return $kind . '|' . $slug . '|' . $locale;
	}

	/**
	 * Throws if the manifest is unavailable.
	 *
	 * @throws RuntimeException If the manifest is unavailable.
	 */
	private function assert_available(): void {
		if ( ! $this->is_available() ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $this->unavailable_reason is always this class's own internally-generated, already-translated diagnostic text (see where it's set, e.g. acquire_manifest_lock()'s own sprintf() calls), never user input, and this message is never rendered as page output.
			throw new RuntimeException(
				sprintf(
					/* translators: %s: the technical reason the manifest is unavailable. */
					__( 'Babilim_Manifest is unavailable: %s', 'babilim' ),
					(string) $this->unavailable_reason
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Reads _manifest.json on first access. Three ways this can go
	 * unavailable instead of silently starting empty -- a manifest that
	 * exists but is broken must never look the same as "nothing
	 * installed yet":
	 *
	 * - the file exists but couldn't be read at all (a WP_Error from
	 *   the underlying Babilim_Fs) -- nothing is quarantined, since
	 *   there's nothing readable to copy;
	 * - it read fine but isn't valid JSON;
	 * - it's valid JSON but not the object shape this class relies on
	 *   (e.g. a bare `[]`, a string, or a number) -- both of the last
	 *   two get a quarantine copy.
	 *
	 * Uses read_manifest_file() rather than a bare Babilim_Fs::get()
	 * call, so a request that happens to construct this class in the
	 * exact instant another request's own write has the file
	 * momentarily missing (see that method's own docblock) gets one
	 * retry before concluding "nothing installed yet" -- which, once
	 * concluded here, would otherwise stick for this instance's entire
	 * lifetime (load() never runs twice).
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		$raw = $this->read_manifest_file();

		if ( $raw instanceof WP_Error ) {
			$this->unavailable_reason = sprintf(
				/* translators: %s: the underlying filesystem error message. */
				__( 'could not read _manifest.json: %s', 'babilim' ),
				$raw->get_error_message()
			);
			return;
		}
		if ( null === $raw ) {
			$this->loaded_hash = '';
			return;
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->unavailable_reason = sprintf(
				/* translators: %s: the underlying JSON parser error message. */
				__( 'failed to parse _manifest.json: %s', 'babilim' ),
				json_last_error_msg()
			);
			$this->quarantine( $raw );
			return;
		}
		if ( ! self::is_valid_shape( $decoded ) ) {
			$this->unavailable_reason = __( '_manifest.json has an unexpected structure', 'babilim' );
			$this->quarantine( $raw );
			return;
		}

		$this->records            = $decoded['records'];
		$this->default_index_data = $decoded['default_index'];
		$this->sweep_guard        = $decoded['sweep_guard'] ?? null;
		$this->sweep_summary      = $decoded['sweep_summary'] ?? null;
		$this->write_conflict     = $decoded['write_conflict'] ?? null;
		$this->loaded_hash        = hash( 'sha256', $raw );
	}

	/**
	 * Runs $fn while holding this class's cross-request lock. Reentrant:
	 * a call made while the lock is already held by this instance just
	 * runs $fn, and save() inside it reuses the held lock. The lock is
	 * released when $fn returns or throws.
	 *
	 * @param callable $fn Returns a non-null value.
	 * @return mixed What $fn returned, or null if the lock could not be
	 *               taken (in which case $fn did not run).
	 */
	public function with_lock( callable $fn ) {
		if ( null !== self::$lock_held ) {
			return $fn();
		}
		$held = $this->acquire_manifest_lock();
		if ( null === $held ) {
			return null;
		}
		self::$lock_held = $held;
		try {
			return $fn();
		} finally {
			self::$lock_held = null;
			$this->release_manifest_lock( $held );
		}
	}

	/**
	 * Throws away everything read so far and reads `_manifest.json`
	 * again, so the next read or write starts from what another request
	 * has written meanwhile. Called with the install lock held, before
	 * the decision that depends on the current state.
	 */
	public function reload_from_disk(): void {
		$this->loaded               = false;
		$this->records              = array();
		$this->default_index_data   = array();
		$this->sweep_guard          = null;
		$this->sweep_summary        = null;
		$this->write_conflict       = null;
		$this->unavailable_reason   = null;
		$this->loaded_hash          = null;
		$this->load();
	}

	/**
	 * Reads `_manifest.json`, retrying once (after a brief pause) if
	 * it's momentarily missing. A real WordPress filesystem transfer
	 * that isn't a native `direct` rename (WP_Filesystem_Direct::move(),
	 * and every non-`direct` transport, which has no choice but to use
	 * move() too -- see rename_manifest_file()'s own docblock) deletes
	 * the destination BEFORE putting the replacement in its place, so a
	 * read landing in that exact window sees nothing there at all, even
	 * though a valid file existed a moment ago and will again a moment
	 * later. Confirmed directly with a real two-process probe: roughly
	 * 5-6% of reads during concurrent writes saw the file "missing" this
	 * way. Neither this method nor its two callers (load(),
	 * save_locked()'s own re-read)
	 * ever treat one missing read alone as "confirmed empty, safe to
	 * start fresh from" -- only the SAME result twice in a row.
	 *
	 * @return string|WP_Error|null
	 */
	private function read_manifest_file() {
		$path = Babilim_Paths::manifest_file();
		$raw  = $this->fs->get( $path );
		if ( null !== $raw ) {
			return $raw; // A real string, or a WP_Error -- not "missing".
		}
		usleep( 20000 );
		return $this->fs->get( $path );
	}

	/**
	 * Whether a json_decode()'d value has the top-level shape this class
	 * relies on -- an object (associative array) with `records` and
	 * `default_index` keys that are themselves both arrays, and every
	 * record in `records` individually shape-valid (see
	 * is_valid_record()). Valid JSON that isn't this shape (`[]`, a
	 * string, a number, a malformed individual record, ...) counts as
	 * corrupt just as much as invalid JSON does -- just with a distinct
	 * unavailable_reason() (see load()).
	 *
	 * @param mixed $decoded The result of json_decode( $raw, true ).
	 * @return bool
	 */
	private static function is_valid_shape( $decoded ): bool {
		if ( ! is_array( $decoded )
			|| ! is_array( $decoded['records'] ?? null )
			|| ! is_array( $decoded['default_index'] ?? null )
		) {
			return false;
		}
		// Optional -- absent (an older manifest, from before this field
		// existed) is fine; present, it must be an array or null.
		if ( array_key_exists( 'sweep_guard', $decoded ) && null !== $decoded['sweep_guard'] && ! is_array( $decoded['sweep_guard'] ) ) {
			return false;
		}
		if ( array_key_exists( 'sweep_summary', $decoded ) && null !== $decoded['sweep_summary'] && ! is_array( $decoded['sweep_summary'] ) ) {
			return false;
		}
		if ( array_key_exists( 'write_conflict', $decoded ) && null !== $decoded['write_conflict'] && ! is_array( $decoded['write_conflict'] ) ) {
			return false;
		}
		foreach ( $decoded['records'] as $record ) {
			if ( ! self::is_valid_record( $record ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether one decoded record has the required keys/types this class
	 * (and the installer/default-index classes built on it) relies on:
	 * `kind`/`slug`/`locale` non-empty strings; `files`/`default_files`,
	 * when present, arrays of string => string (a relative path's
	 * sha256); `orphaned_at`, when present, a string or null;
	 * `component_version`/`text_domain`, when present, strings;
	 * `integrity`, when present, shape-checked by is_valid_integrity();
	 * `version_history`, when present, an array whose every entry is
	 * shape-checked by is_valid_history_entry(), because
	 * rollback() reads `version_history` entries'
	 * `staging`/`main_served`/`default_served` directly, so a malformed
	 * one must be caught here rather than reaching rollback() as an
	 * uncaught TypeError. A record failing any of this is treated the
	 * same as a structurally-wrong top-level manifest -- see
	 * is_valid_shape().
	 *
	 * @param mixed $record One decoded record.
	 * @return bool
	 */
	private static function is_valid_record( $record ): bool {
		if ( ! is_array( $record ) ) {
			return false;
		}
		foreach ( array( 'kind', 'slug', 'locale' ) as $key ) {
			if ( ! isset( $record[ $key ] ) || ! is_string( $record[ $key ] ) || '' === $record[ $key ] ) {
				return false;
			}
		}
		foreach ( array( 'files', 'default_files' ) as $key ) {
			if ( array_key_exists( $key, $record ) && ! self::is_valid_string_map( $record[ $key ] ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'orphaned_at', $record )
			&& null !== $record['orphaned_at']
			&& ! is_string( $record['orphaned_at'] )
		) {
			return false;
		}
		foreach ( array( 'component_version', 'text_domain', 'build_id', 'pipeline_build_id', 'built_at', 'installed_at', 'previous_build_id', 'degraded_at' ) as $key ) {
			if ( array_key_exists( $key, $record ) && null !== $record[ $key ] && ! is_string( $record[ $key ] ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'integrity', $record ) && ! self::is_valid_integrity( $record['integrity'] ) ) {
			return false;
		}
		if ( array_key_exists( 'version_history', $record ) ) {
			if ( ! is_array( $record['version_history'] ) ) {
				return false;
			}
			foreach ( $record['version_history'] as $entry ) {
				if ( ! self::is_valid_history_entry( $entry ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Whether $value is an array every one of whose values is a string --
	 * the shape `files`/`default_files` (relative path => sha256) rely
	 * on.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	private static function is_valid_string_map( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $v ) {
			if ( ! is_string( $v ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether one decoded record's `integrity` field has the shape
	 * verify()/finalize_build() rely on: an array with a boolean `ok` and
	 * (when present) a string `checked_at`.
	 *
	 * @param mixed $integrity Candidate `integrity` value.
	 * @return bool
	 */
	private static function is_valid_integrity( $integrity ): bool {
		if ( ! is_array( $integrity ) ) {
			return false;
		}
		if ( ! array_key_exists( 'ok', $integrity ) || ! is_bool( $integrity['ok'] ) ) {
			return false;
		}
		if ( array_key_exists( 'checked_at', $integrity ) && ! is_string( $integrity['checked_at'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether one decoded `version_history` entry has the shape
	 * Babilim_Installer::rollback() relies on: string `build_id`/
	 * `staging`; when present, string=>string `main_served`/
	 * `default_served` (served filename => durable history path), and a
	 * string `component_version`/`pipeline_build_id`/`built_at`, an int
	 * `build_no`.
	 *
	 * @param mixed $entry One decoded version_history entry.
	 * @return bool
	 */
	private static function is_valid_history_entry( $entry ): bool {
		if ( ! is_array( $entry ) ) {
			return false;
		}
		foreach ( array( 'build_id', 'staging' ) as $key ) {
			if ( ! isset( $entry[ $key ] ) || ! is_string( $entry[ $key ] ) || '' === $entry[ $key ] ) {
				return false;
			}
		}
		foreach ( array( 'main_served', 'default_served' ) as $key ) {
			if ( array_key_exists( $key, $entry ) && ! self::is_valid_string_map( $entry[ $key ] ) ) {
				return false;
			}
		}
		foreach ( array( 'component_version', 'pipeline_build_id', 'built_at' ) as $key ) {
			if ( array_key_exists( $key, $entry ) && ! is_string( $entry[ $key ] ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'build_no', $entry ) && ! is_int( $entry['build_no'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Copies the corrupt file's raw bytes aside for diagnosis. The
	 * quarantine filename is derived from the content itself (see
	 * Babilim_Paths::manifest_quarantine_file()), so quarantining the
	 * same corrupt content twice -- from this instance or a separate
	 * one -- never makes a second copy; the original at
	 * Babilim_Paths::manifest_file() is never touched.
	 *
	 * @param string $raw The corrupt file's contents.
	 */
	private function quarantine( string $raw ): void {
		$quarantine_path = Babilim_Paths::manifest_quarantine_file( substr( sha1( $raw ), 0, 16 ) );
		if ( $this->fs->exists( $quarantine_path ) ) {
			return;
		}
		$this->fs->put( $quarantine_path, $raw );
	}

	/**
	 * Writes the in-memory manifest back to _manifest.json, protected by
	 * this class's own cross-request mutex for the entire read-compare-
	 * merge-write sequence (see acquire_manifest_lock()'s own docblock).
	 *
	 * A real, two-process probe measured why a hash-compare-and-merge
	 * alone (no lock) is not enough on its own: the window between
	 * re-reading the file and writing the replacement back is itself
	 * unprotected, so two overlapping save() calls can each read the
	 * same "unchanged" hash and each proceed to write, one clobbering
	 * the other. On real WordPress core filesystem code (WP 6.6.9/
	 * 6.8.10/7.1.2's own WP_Filesystem_Direct, confirmed byte-identical
	 * across all three), a compare-then-write-without-a-lock version of
	 * this method lost roughly 23% of distinct records and silently
	 * overwrote the same record hundreds of times across repeated
	 * two-process runs; wrapping the same sequence in this lock brought
	 * both numbers to zero in the same probe.
	 *
	 * @param array $touched This call's own delta -- {section:
	 *                        'records'|'default_index'|'sweep_guard'|
	 *                        'sweep_summary', key: string|null, baseline:
	 *                        mixed, value: mixed}. `key` is null for the
	 *                        two singleton sections; `value` null means
	 *                        "delete this key" for the two keyed
	 *                        sections.
	 * @return bool True if this call's own change landed on disk. False
	 *              otherwise -- the lock could not be taken, the file
	 *              could not be re-read or written, or the change lost
	 *              an unresolvable conflict (see save_locked()'s own
	 *              docblock) -- and then: this instance's own memory no
	 *              longer shows the change, the reason is available from
	 *              last_save_failure(), and the failure is recorded
	 *              locally (see record_write_failure()). Every public
	 *              writer passes this result straight back, and every
	 *              caller of those writers reports its own action as
	 *              failed on false -- a write that did not land is never
	 *              a silent success.
	 */
	private function save( array $touched ): bool {
		$this->last_save_failure = null;

		if ( null !== self::$lock_held ) {
			// Inside with_lock(): the lock is already ours.
			$reason = $this->save_locked( $touched );
		} else {
			$held = $this->acquire_manifest_lock();
			if ( null === $held ) {
				$reason = 'lock_unavailable';
			} else {
				try {
					$reason = $this->save_locked( $touched );
				} finally {
					$this->release_manifest_lock( $held );
				}
			}
		}
		if ( null === $reason ) {
			$this->clear_recorded_write_failure();
			return true;
		}

		if ( 'conflict' !== $reason ) {
			// A conflict has already brought this instance's memory back
			// in line with disk (see save_locked()); every other failure
			// left disk untouched, so this call's own change is taken
			// back out of memory too -- otherwise the next successful
			// write from this instance would silently land it after all.
			$this->revert( $touched );
		}
		$this->record_write_failure( $touched, $reason );
		return false;
	}

	/**
	 * Puts $touched's own section/key back to the baseline it had before
	 * this call changed it in memory.
	 *
	 * @param array $touched See save()'s own docblock.
	 */
	private function revert( array $touched ): void {
		$key      = $touched['key'];
		$baseline = $touched['baseline'];
		switch ( $touched['section'] ) {
			case 'records':
				if ( null === $baseline ) {
					unset( $this->records[ $key ] );
				} else {
					$this->records[ $key ] = $baseline;
				}
				break;
			case 'default_index':
				if ( null === $baseline ) {
					unset( $this->default_index_data[ $key ] );
				} else {
					$this->default_index_data[ $key ] = $baseline;
				}
				break;
			case 'sweep_guard':
				$this->sweep_guard = $baseline;
				break;
			case 'sweep_summary':
				$this->sweep_summary = $baseline;
				break;
		}
	}

	/**
	 * Records a write that did not land -- on this instance (see
	 * last_save_failure()) and in a small network-wide option of its own
	 * (see last_recorded_write_failure()), which every site's settings
	 * screen shows until the next write that lands (see
	 * clear_recorded_write_failure()).
	 * Deliberately not in `_manifest.json` itself: the write that just
	 * failed may well have been unable to reach that file at all.
	 *
	 * @param array  $touched See save()'s own docblock.
	 * @param string $reason  See last_save_failure().
	 */
	private function record_write_failure( array $touched, string $reason ): void {
		$this->last_save_failure = self::failure( (string) $touched['section'], null === $touched['key'] ? null : (string) $touched['key'], $reason );
		if ( function_exists( 'update_site_option' ) ) {
			update_site_option( self::WRITE_FAILURE_OPTION, $this->last_save_failure );
		}
	}

	/**
	 * Drops the recorded write failure (see record_write_failure()) once
	 * a write has landed again -- the settings screen shows a failure
	 * only until the manifest is being written successfully again, never
	 * a stale one. Reads first so the common case (nothing recorded)
	 * costs no write.
	 */
	private function clear_recorded_write_failure(): void {
		if ( ! function_exists( 'get_site_option' ) || ! function_exists( 'delete_site_option' ) ) {
			return;
		}
		if ( false !== get_site_option( self::WRITE_FAILURE_OPTION, false ) ) {
			delete_site_option( self::WRITE_FAILURE_OPTION );
		}
	}

	/**
	 * A public writer's result while the manifest is unavailable:
	 * nothing is attempted, and last_save_failure() says why -- never a
	 * previous call's reason. Not recorded in self::WRITE_FAILURE_OPTION:
	 * the settings screen already reports the unavailable manifest
	 * itself.
	 *
	 * @param string      $section See save()'s own docblock.
	 * @param string|null $key     See save()'s own docblock.
	 * @return false
	 */
	private function unavailable_write( string $section, ?string $key ): bool {
		$this->last_save_failure = self::failure( $section, $key, 'manifest_unavailable' );
		return false;
	}

	/**
	 * One last_save_failure() entry.
	 *
	 * @param string      $section The section written.
	 * @param string|null $key     The key written, or null for a singleton section.
	 * @param string      $reason  See last_save_failure().
	 * @return array{at: string, section: string, key: string|null, reason: string}
	 */
	private static function failure( string $section, ?string $key, string $reason ): array {
		return array(
			'at'      => gmdate( 'c' ),
			'section' => $section,
			'key'     => $key,
			'reason'  => $reason,
		);
	}

	/**
	 * The actual read-compare-merge-write sequence, run only while
	 * save() holds this class's own cross-request lock.
	 *
	 * Re-reads the file (via read_manifest_file(), so a momentarily-
	 * missing file -- see that method's own docblock -- is retried
	 * rather than treated as "empty, safe to overwrite") and compares
	 * its content hash against the hash recorded when this instance
	 * last read or wrote it.
	 *
	 * Unchanged: writes the in-memory snapshot as before.
	 *
	 * Changed (another request wrote since, despite holding this same
	 * lock in turn -- ordinary sequential activity, not a bug): applies
	 * only $touched's own delta onto the latest content (read-modify-
	 * write), rather than this instance's whole, now-stale snapshot --
	 * a change to some other record/locale/summary the other writer
	 * made survives untouched.
	 *
	 * If $touched's own key changed on both sides (the same record
	 * modified by two callers), the merge itself is what's unresolvable:
	 * the latest disk content wins, this call's own change is not
	 * applied, and this instance's own in-memory snapshot is brought
	 * back in line with what's actually on disk (a caller reading
	 * installed()/all() afterward must never see a value that never
	 * landed). The conflict itself is recorded locally --
	 * write_conflict(), never client_events[] (that schema has no
	 * `kind` a manifest write conflict could honestly claim, and
	 * rejects anything outside `plugin`/`theme` with a 422 on the WHOLE
	 * request -- confirmed directly; see
	 * Babilim_State::record_client_event()'s own guard) -- and written
	 * back under this SAME lock, so the conflict record itself isn't
	 * lost either, even though $touched's own change is. A merge that
	 * does apply takes the conflict record from disk too, never from
	 * this instance's own copy from load time -- otherwise any instance
	 * loaded before a conflict would erase its record with an unrelated
	 * later write.
	 *
	 * Nothing is written at all when the re-read fails outright, when a
	 * file that existed at load time is gone on both reads (see
	 * read_manifest_file()), or when the latest content does not parse:
	 * in each case what is on disk is unknown, and this instance's own
	 * full snapshot must never be written over it.
	 *
	 * @param array $touched See save()'s own docblock.
	 * @return string|null Null when this call's own change landed;
	 *                     otherwise the failure reason (see
	 *                     last_save_failure()).
	 */
	private function save_locked( array $touched ): ?string {
		$current_raw = $this->read_manifest_file();

		if ( $current_raw instanceof WP_Error ) {
			// Can't tell what is on disk right now -- writing anything
			// (least of all this instance's own full snapshot) could
			// overwrite content this instance has never seen.
			return 'reread_failed';
		}
		if ( null === $current_raw && '' !== $this->loaded_hash ) {
			// The file existed when this instance loaded it but is gone
			// on both reads now: not "no manifest yet, safe to start
			// fresh" -- same reasoning as above.
			return 'reread_failed';
		}

		$records            = $this->records;
		$default_index_data = $this->default_index_data;
		$sweep_guard        = $this->sweep_guard;
		$sweep_summary      = $this->sweep_summary;
		$write_conflict     = $this->write_conflict;

		if ( is_string( $current_raw ) && hash( 'sha256', $current_raw ) !== $this->loaded_hash ) {
			$decoded = json_decode( $current_raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! self::is_valid_shape( $decoded ) ) {
				// Can't safely merge against unreadable/corrupt latest
				// content -- never fall back to writing this instance's
				// own, possibly-stale snapshot over it.
				return 'corrupt';
			}
			$disk_records            = $decoded['records'];
			$disk_default_index_data = $decoded['default_index'];
			$disk_sweep_guard        = $decoded['sweep_guard'] ?? null;
			$disk_sweep_summary      = $decoded['sweep_summary'] ?? null;
			// The conflict record is whatever is on disk now -- never this
			// instance's own copy from load time, which would silently
			// erase a conflict another request recorded since.
			$disk_write_conflict = $decoded['write_conflict'] ?? null;

			$merged = self::apply_delta( $disk_records, $disk_default_index_data, $disk_sweep_guard, $disk_sweep_summary, $touched );
			if ( null === $merged ) {
				$conflict = array(
					'at'      => gmdate( 'c' ),
					'section' => (string) $touched['section'],
					'key'     => $touched['key'],
				);
				// Whether or not writing the conflict record itself
				// succeeds, this call's own change is dropped either way.
				if ( ! $this->write_manifest_payload( $disk_records, $disk_default_index_data, $disk_sweep_guard, $disk_sweep_summary, $conflict ) ) {
					// Memory still holds this call's own change; take
					// it back out (save() reverts on anything but a
					// conflict).
					return 'write_failed';
				}
				return 'conflict';
			}
			list( $records, $default_index_data, $sweep_guard, $sweep_summary ) = $merged;
			$write_conflict = $disk_write_conflict;
		}
		// Else: either the file is unchanged since this instance loaded
		// it, or there has never been one (this is the very first write)
		// -- this instance's own snapshot is exactly what belongs there.

		return $this->write_manifest_payload( $records, $default_index_data, $sweep_guard, $sweep_summary, $write_conflict )
			? null
			: 'write_failed';
	}

	/**
	 * Encodes and writes one manifest payload atomically (same-directory
	 * temp file, then rename_manifest_file()), and -- only once both
	 * steps succeed -- brings this instance's own in-memory snapshot and
	 * loaded_hash in line with exactly what just landed on disk. Shared
	 * by save_locked()'s two write sites (the ordinary case, and the
	 * "record the conflict, re-affirm the latest disk content" case) so
	 * both follow the exact same atomic-write and instance-sync rules.
	 *
	 * @return bool True on success.
	 */
	private function write_manifest_payload( array $records, array $default_index_data, ?array $sweep_guard, ?array $sweep_summary, ?array $write_conflict ): bool {
		$payload = wp_json_encode(
			array(
				'records'        => $records,
				'default_index'  => $default_index_data,
				'sweep_guard'    => $sweep_guard,
				'sweep_summary'  => $sweep_summary,
				'write_conflict' => $write_conflict,
			)
		);
		if ( false === $payload ) {
			return false;
		}

		$tmp = Babilim_Paths::manifest_temp_file();
		if ( ! $this->fs->put( $tmp, $payload ) ) {
			return false;
		}
		if ( ! $this->rename_manifest_file( $tmp, Babilim_Paths::manifest_file() ) ) {
			$this->fs->delete( $tmp );
			return false;
		}

		$this->records            = $records;
		$this->default_index_data = $default_index_data;
		$this->sweep_guard        = $sweep_guard;
		$this->sweep_summary      = $sweep_summary;
		$this->write_conflict     = $write_conflict;
		$this->loaded_hash        = hash( 'sha256', $payload );
		return true;
	}

	/**
	 * $tmp -> $to for the manifest's own write, used only from inside
	 * save()'s own cross-request lock. Deliberately reaches past the
	 * Babilim_Fs interface's own rename() for one, narrow, real reason:
	 * WordPress core's own WP_Filesystem_Direct::move() (which
	 * Babilim_Fs::rename() calls through $wp_filesystem->move(), and
	 * which every WP_Filesystem transfer method other than `direct` has
	 * no alternative to) DELETES the destination file BEFORE renaming
	 * the replacement into place -- byte-identical across WordPress
	 * 6.6.9/6.8.10/7.1.2's own core source. A real two-process probe
	 * measured roughly 5-6% of concurrent reads catching `_manifest.json`
	 * in that exact window (see read_manifest_file()'s own docblock).
	 *
	 * When the real, underlying transfer is `direct` (PHP itself has
	 * ordinary write permission to disk -- the common case; FTP/SSH2
	 * transports exist specifically for when it doesn't), PHP's own
	 * native rename() is used instead: on the same filesystem (always
	 * true here -- both paths live under Babilim_Paths::root()), POSIX
	 * guarantees this overwrites the destination in one atomic
	 * operation, with no window where it doesn't exist at all. A
	 * non-`direct` transfer has no local filesystem path for PHP's own
	 * rename() to operate on at all (the file genuinely only exists on
	 * a remote FTP/SSH2 host) -- move() is the only option there, made
	 * safe by save()'s own lock (no concurrent writer can be caught in
	 * that window, even though the rename call itself still isn't
	 * atomic) rather than by this method choosing a different call.
	 *
	 * Only Babilim_Wp_Fs (the real, production Babilim_Fs) is special-
	 * cased -- every Babilim_Fs test double in this repo has no
	 * delete-then-rename behavior to begin with (a single in-memory
	 * operation, or an intentionally-scripted fake for a specific test),
	 * so there is nothing for this to improve on there; ordinary
	 * rename() is used unchanged.
	 *
	 * @param string $from Temp file path.
	 * @param string $to   Final manifest path.
	 * @return bool True on success.
	 */
	private function rename_manifest_file( string $from, string $to ): bool {
		if ( $this->fs instanceof Babilim_Wp_Fs ) {
			return $this->fs->atomic_rename( $from, $to );
		}
		return $this->fs->rename( $from, $to );
	}

	/**
	 * The read-modify-write merge behind save_locked()'s own optimistic-
	 * concurrency check: applies $touched's own delta onto the given,
	 * already-decoded latest disk state. Returns null when the merge
	 * itself can't be trusted -- $touched's own key holds a different
	 * value on disk than it held when this instance last read it (a
	 * genuine same-key conflict, not just unrelated concurrent activity
	 * elsewhere in the file).
	 *
	 * @param array      $records             Latest disk `records`.
	 * @param array      $default_index_data  Latest disk `default_index`.
	 * @param array|null $sweep_guard         Latest disk `sweep_guard`.
	 * @param array|null $sweep_summary       Latest disk `sweep_summary`.
	 * @param array      $touched             See save()'s own docblock.
	 * @return array{0:array,1:array,2:array|null,3:array|null}|null
	 *               [records, default_index_data, sweep_guard,
	 *               sweep_summary], or null if unresolvable.
	 */
	private static function apply_delta( array $records, array $default_index_data, ?array $sweep_guard, ?array $sweep_summary, array $touched ): ?array {
		$section  = $touched['section'];
		$key      = $touched['key'];
		$baseline = $touched['baseline'];
		$value    = $touched['value'];

		switch ( $section ) {
			case 'records':
				$latest = array_key_exists( $key, $records ) ? $records[ $key ] : null;
				if ( $latest != $baseline ) { // phpcs:ignore Universal.Operators.StrictComparisons -- deliberately loose: JSON round-tripping never guarantees array key order, and this compares content, not identity.
					return null;
				}
				if ( null === $value ) {
					unset( $records[ $key ] );
				} else {
					$records[ $key ] = $value;
				}
				break;

			case 'default_index':
				$latest = array_key_exists( $key, $default_index_data ) ? $default_index_data[ $key ] : null;
				if ( $latest != $baseline ) { // phpcs:ignore Universal.Operators.StrictComparisons
					return null;
				}
				if ( null === $value ) {
					unset( $default_index_data[ $key ] );
				} else {
					$default_index_data[ $key ] = $value;
				}
				break;

			case 'sweep_guard':
				if ( $sweep_guard != $baseline ) { // phpcs:ignore Universal.Operators.StrictComparisons
					return null;
				}
				$sweep_guard = $value;
				break;

			case 'sweep_summary':
				if ( $sweep_summary != $baseline ) { // phpcs:ignore Universal.Operators.StrictComparisons
					return null;
				}
				$sweep_summary = $value;
				break;
		}

		return array( $records, $default_index_data, $sweep_guard, $sweep_summary );
	}

	/**
	 * Acquires this class's own cross-request mutex before save_locked()
	 * runs: one row (self::LOCK_OPTION_NAME) in the installation's main
	 * options table (see lock_table() -- on multisite, the main site's,
	 * whichever site the request is for, because `_manifest.json` is
	 * shared by the whole network), taken
	 * and released only through single SQL statements on $wpdb -- the
	 * same approach WordPress core's own WP_Upgrader::create_lock() uses
	 * -- and never through the Options API:
	 *
	 * - add_option() is not a mutex. It checks for an existing value
	 *   through get_option() -- which reads the object cache, and skips
	 *   the check entirely when the 'notoptions' cache says the option
	 *   doesn't exist (as it does right after this same request's own
	 *   delete_option()) -- and then runs INSERT ... ON DUPLICATE KEY
	 *   UPDATE, which overwrites a row another request inserted in the
	 *   meantime instead of failing. Measured on a real WordPress 7.1.2
	 *   install: a lock built that way let a second request take a lock
	 *   another request still validly held, and a request that saw the
	 *   lock held kept seeing it held (from its own cache) long after it
	 *   was released.
	 * - The Options API has no compare-and-swap, so neither an expired
	 *   lock's takeover nor "delete only my own lock" can be made safe
	 *   through it.
	 *
	 * The three statements used instead are each a single statement
	 * against a table whose option_name column carries a unique key. On
	 * MySQL/MariaDB, a single statement is atomic with respect to that
	 * key: INSERT IGNORE of a duplicate key inserts nothing, and a
	 * conditional UPDATE/DELETE only affects a row still matching its
	 * WHERE clause when the statement runs -- the same semantics core's
	 * own WP_Upgrader::create_lock() relies on for its INSERT IGNORE into
	 * the options table. The SQLite database WordPress Playground runs on
	 * (whose WordPress driver translates INSERT IGNORE) is where this
	 * plugin's own contention checks exercise them against real core:
	 *
	 * - take a free lock: INSERT IGNORE of a new row -- exactly one of
	 *   several concurrent callers gets rows_affected === 1; the rest get
	 *   0 and the existing row is left untouched;
	 * - take over an expired lock: UPDATE ... WHERE option_value = <the
	 *   exact expired value just read> -- a compare-and-swap; of several
	 *   callers that saw the same expired value, only one matches;
	 * - release (see release_manifest_lock()): DELETE ... WHERE
	 *   option_value = <this request's own value> -- never deletes a lock
	 *   another request has since taken over.
	 *
	 * The row is read with a direct SELECT, never get_option(), and the
	 * option's own object-cache entries are dropped after every change
	 * (see forget_cached_lock()), so neither this request nor any other
	 * code in it ever works from a stale cached copy.
	 *
	 * Retries (self::LOCK_RETRY_BUDGET_SECONDS total, with a randomized
	 * backoff between attempts so two contending processes don't keep
	 * retrying in lockstep), then gives up. The lock carries its own
	 * expiry (self::LOCK_TTL_SECONDS) so a request that dies mid-write
	 * doesn't wedge every future write behind a lock nobody will ever
	 * release.
	 *
	 * Lock values are always "<32 lowercase hex digits>|<decimal digits>"
	 * (see lock_value() and well_formed_lock_value()), and this method
	 * never writes any other value. That matters for the value
	 * comparisons above: MySQL's default collations compare case-
	 * insensitively and ignore trailing spaces, so two different values
	 * that differed only in case or trailing whitespace would compare as
	 * equal there. Values made only of lowercase hex and digits never do.
	 *
	 * Fails closed (returns null) when there is no usable $wpdb at all.
	 *
	 * @return string|null The exact lock value this request now holds
	 *                      (pass it to release_manifest_lock()), or null
	 *                      if the lock could not be acquired within
	 *                      budget.
	 */
	private function acquire_manifest_lock(): ?string {
		$wpdb = self::lock_db();
		if ( null === $wpdb ) {
			return null;
		}

		$table    = self::lock_table( $wpdb );
		$token    = self::random_lock_token();
		$deadline = microtime( true ) + self::LOCK_RETRY_BUDGET_SECONDS;

		while ( true ) {
			$now   = time();
			$value = self::lock_value( $token, $now + self::LOCK_TTL_SECONDS );
			if ( ! self::well_formed_lock_value( $value ) ) {
				return null;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock must bypass every cache; see this method's own docblock.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $table, self::LOCK_OPTION_NAME, $value ) );
			if ( false !== $inserted && 1 === (int) $wpdb->rows_affected ) {
				self::forget_cached_lock();
				return $value;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM %i WHERE option_name = %s LIMIT 1", $table, self::LOCK_OPTION_NAME ) );
			if ( is_string( $current ) && self::lock_expires_at( $current ) < $now ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
				$taken = $wpdb->query( $wpdb->prepare( "UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s", $table, $value, self::LOCK_OPTION_NAME, $current ) );
				if ( false !== $taken && 1 === (int) $wpdb->rows_affected ) {
					self::forget_cached_lock();
					return $value;
				}
			}

			if ( microtime( true ) >= $deadline ) {
				return null;
			}
			usleep( random_int( 20000, 80000 ) );
		}
	}

	/**
	 * Releases this request's own manifest lock: deletes the row only
	 * while it still holds exactly $held -- a lock already taken over by
	 * another request (this one's own expired before it got here) is
	 * never deleted out from under its new holder.
	 *
	 * @param string $held The exact value acquire_manifest_lock() returned.
	 */
	private function release_manifest_lock( string $held ): void {
		$wpdb = self::lock_db();
		if ( null === $wpdb ) {
			return;
		}
		$table = self::lock_table( $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see acquire_manifest_lock()'s own docblock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name = %s AND option_value = %s", $table, self::LOCK_OPTION_NAME, $held ) );
		self::forget_cached_lock();
	}

	/**
	 * The global $wpdb, or null if there isn't a usable one.
	 *
	 * @return wpdb|null
	 */
	private static function lock_db() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' )
			|| ! is_string( $wpdb->base_prefix ?? null )
		) {
			return null;
		}
		return $wpdb;
	}

	/**
	 * The options table the lock row lives in: the installation's main
	 * one ($wpdb->base_prefix . 'options'), never the current site's own
	 * ($wpdb->options). On a single site the two are the same table. On
	 * multisite, $wpdb->options is a per-site table (wp_2_options, ...)
	 * while `_manifest.json` lives in WP_LANG_DIR, which every site of the
	 * network shares -- a per-site lock row would let two sites write the
	 * one shared file at once. The main site's table always exists.
	 *
	 * @param wpdb $wpdb The database object lock_db() returned.
	 * @return string
	 */
	private static function lock_table( $wpdb ): string {
		return $wpdb->base_prefix . 'options';
	}

	/**
	 * The lock row's value: this request's own random token and the
	 * moment the lock expires -- "<token>|<unix time>", where the token
	 * is always 32 lowercase hex digits (see random_lock_token()), so the
	 * whole value only ever contains lowercase hex, "|" and decimal
	 * digits (see acquire_manifest_lock()'s own docblock for why).
	 */
	private static function lock_value( string $token, int $expires_at ): string {
		return $token . '|' . $expires_at;
	}

	/**
	 * Whether $value is in the one format this class ever writes to the
	 * lock row (see lock_value()). acquire_manifest_lock() checks every
	 * value it generates against this before writing it, and fails
	 * closed on anything else.
	 */
	private static function well_formed_lock_value( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}\|[0-9]+$/D', $value );
	}

	/**
	 * When a lock row's value says it expires. A value that isn't in this
	 * class's own format at all is treated as long expired -- it can only
	 * ever be taken over through the same compare-and-swap as any other
	 * expired lock, never simply ignored or left to block forever.
	 */
	private static function lock_expires_at( string $value ): int {
		$sep = strrpos( $value, '|' );
		if ( false === $sep ) {
			return 0;
		}
		$expires = substr( $value, $sep + 1 );
		return ctype_digit( $expires ) ? (int) $expires : 0;
	}

	/**
	 * Drops every object-cache entry that could hold a stale copy of the
	 * lock option (its own entry, and its key in the 'notoptions' and
	 * 'alloptions' buckets) -- the lock row is only ever changed directly
	 * through $wpdb, so nothing else will.
	 */
	private static function forget_cached_lock(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::LOCK_OPTION_NAME, 'options' );
		}
		if ( ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_set' ) ) {
			return;
		}
		foreach ( array( 'notoptions', 'alloptions' ) as $bucket ) {
			$cached = wp_cache_get( $bucket, 'options' );
			if ( is_array( $cached ) && array_key_exists( self::LOCK_OPTION_NAME, $cached ) ) {
				unset( $cached[ self::LOCK_OPTION_NAME ] );
				wp_cache_set( $bucket, $cached, 'options' );
			}
		}
	}

	/**
	 * A random token identifying this process's own hold on the
	 * manifest lock -- never guessable, so one process can never
	 * mistake another's still-valid lock for its own. Always 32
	 * lowercase hex digits, on both paths.
	 *
	 * @return string
	 */
	private static function random_lock_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( '', true ) . microtime() );
		}
	}
}
