<?php
/**
 * Builds and maintains the merged-per-locale `default` text domain
 * supplement index -- the one file Babilim_Loader::load_default_supplements()
 * ever actually loads for the `default` domain.
 *
 * Every WordPress data source this class needs is injected as a callable
 * (see the constructor), except get_plugins()/wp_get_themes(), called
 * directly (guarded by function_exists()) from sweep_orphans() only --
 * that method needs to tell "a component genuinely removed from disk"
 * apart from "a component merely deactivated" (see that method's own
 * docblock for why this couldn't be derived from the injected
 * $active_components alone, which only ever reports *active*
 * components).
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds, and reconciles orphans out of, the merged `default` domain
 * index.
 */
final class Babilim_Default_Index {

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
	private $active_components;

	/**
	 * @var callable
	 */
	private $parse_entries;

	/**
	 * @var callable
	 */
	private $core_has;

	/**
	 * @var callable|null
	 */
	private $core_plural_forms;

	/**
	 * @param Babilim_Fs       $fs                Filesystem to read/write through.
	 * @param Babilim_Manifest $manifest          The installed-files manifest.
	 * @param callable         $active_components fn(): array<int,array{kind:string,slug:string,version:string,alias?:bool}>|null --
	 *                                             every currently *active* plugin plus the
	 *                                             current theme (parent/child); null when
	 *                                             that list genuinely couldn't be determined
	 *                                             (see rebuild()'s own fail-closed handling --
	 *                                             which is NOT the same
	 *                                             as legitimately returning an empty array.
	 *                                             An entry with `alias` true is a secondary
	 *                                             identity (a text-domain header) that only
	 *                                             counts for a key no entry claims directly.
	 * @param callable         $parse_entries     fn(string $path): array<string,string|array<int,string>> --
	 *                                             reads a `.mo`/`.l10n.php` file's entries,
	 *                                             keyed by `{msgctxt}\x04{msgid}` (or bare
	 *                                             `{msgid}`), excluding the `""` header entry.
	 *                                             Prod: WP_Translation_File::create( $path )->entries().
	 * @param callable         $core_has          fn(string $msgid, string $msgctxt, string $locale): bool --
	 *                                             whether WordPress core's own `default` domain
	 *                                             translation already has this string, for the
	 *                                             locale currently being rebuilt. Prod:
	 *                                             WP_Translation_Controller::get_instance()->translate().
	 * @param callable|null    $core_plural_forms fn(string $locale): string|null -- WordPress
	 *                                             core's own already-loaded `Plural-Forms` header
	 *                                             for the `default` domain at $locale, used as a
	 *                                             fallback when the included fragments' own
	 *                                             headers are missing or disagree. Optional
	 *                                             (defaults to "never available") so existing
	 *                                             5-argument callers keep working unchanged.
	 */
	public function __construct( Babilim_Fs $fs, Babilim_Manifest $manifest, callable $active_components, callable $parse_entries, callable $core_has, ?callable $core_plural_forms = null ) {
		$this->fs                = $fs;
		$this->manifest           = $manifest;
		$this->active_components = $active_components;
		$this->parse_entries      = $parse_entries;
		$this->core_has           = $core_has;
		$this->core_plural_forms  = $core_plural_forms;
	}

	/**
	 * Rebuilds the merged `default` domain index for one locale from
	 * every installed component's default-domain supplement fragment
	 * (see the class docblock).
	 *
	 * @param string $locale Locale code.
	 * @return Babilim_Result
	 */
	public function rebuild( string $locale ): Babilim_Result {
		if ( ! Babilim_Paths::is_safe_locale( $locale ) ) {
			return Babilim_Result::error( 'invalid_locale', sprintf( /* translators: %s: the invalid locale code. */ __( 'Unsafe locale: %s', 'babilim' ), $locale ) );
		}
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}

		try {
			$active = call_user_func( $this->active_components );
		} catch ( \Throwable $e ) {
			$active = null;
		}
		if ( null === $active || array() === $active ) {
			// The fail-closed guarantee: the injected $active_components
			// couldn't tell us what's genuinely active (as opposed to
			// legitimately reporting "nothing active" -- see the
			// constructor docblock). A genuinely empty array is treated
			// the same as null here: in production Babilim::
			// active_components() always includes the current theme (see
			// its own docblock), so a completely empty result is itself
			// a strong "couldn't determine" signal, not a real site
			// state. Acting on either would
			// risk excluding every active component's contribution and,
			// worse, deleting the existing merged index below when
			// nothing has actually changed. Leave whatever index is on
			// disk exactly as it is.
			return Babilim_Result::error( 'active_components_unavailable', __( 'Could not determine active components; the existing merged index was left unchanged.', 'babilim' ) );
		}

		// `kind|slug` => every active version claiming that key. Several
		// active components can share one key (an add-on declaring its
		// parent plugin's text domain, for example), so a single value
		// would let whichever came last overwrite the others. A key that
		// any component claims directly (directory name, wp.org slug,
		// stylesheet) is compared only against those direct claims;
		// text-domain aliases count only for a key nobody claims
		// directly.
		$direct  = array();
		$aliases = array();
		foreach ( $active as $c ) {
			$key = $c['kind'] . '|' . $c['slug'];
			if ( ! empty( $c['alias'] ) ) {
				$aliases[ $key ][] = (string) $c['version'];
			} else {
				$direct[ $key ][] = (string) $c['version'];
			}
		}
		$active_by_key = $direct + $aliases;

		list( $included_records, $excluded ) = $this->classify_records( $locale, $active_by_key );

		list( $merged, $owners, $conflict_keys, $included_meta ) = $this->merge_fragments( $included_records );

		$conflicts = array();
		foreach ( array_keys( $conflict_keys ) as $key ) {
			$slugs = array_values( array_unique( $owners[ $key ] ) );
			sort( $slugs, SORT_STRING );
			list( $ctx, $msgid ) = self::split_key( $key );
			$conflicts[] = array(
				'msgctxt' => $ctx,
				'msgid'   => $msgid,
				'slugs'   => $slugs,
			);
			unset( $merged[ $key ] );
		}

		$core_dupes = 0;
		$final      = array();
		foreach ( $merged as $key => $msgstr ) {
			list( $ctx, $msgid ) = self::split_key( $key );
			if ( call_user_func( $this->core_has, $msgid, $ctx, $locale ) ) {
				++$core_dupes;
				continue;
			}
			$final[ $key ] = $msgstr;
		}

		$plural_forms = $this->resolve_plural_forms( $included_records, $locale );
		$skipped_plurals = 0;
		if ( null === $plural_forms ) {
			// We can't tell which plural rule applies, so a plural
			// entry loaded under the wrong one would be actively wrong
			// (not just missing) -- omit every plural entry rather than
			// risk that; English is always a safe fallback for those.
			foreach ( $final as $key => $msgstr ) {
				if ( self::is_plural_entry( (string) $key, $msgstr ) ) {
					unset( $final[ $key ] );
					++$skipped_plurals;
				}
			}
		}

		$meta_base = array(
			'built_at'        => gmdate( 'c' ),
			'included'        => $included_meta,
			'excluded'        => $excluded,
			'conflicts'       => $conflicts,
			'core_dupes'      => $core_dupes,
			'plural_forms'    => $plural_forms,
			'skipped_plurals' => $skipped_plurals,
		);

		if ( empty( $final ) ) {
			return $this->write_empty_index( $locale, $meta_base );
		}

		return $this->write_merged_index( $locale, $final, $meta_base, $plural_forms );
	}

	/**
	 * The `Plural-Forms` value to write into the merged index: the
	 * included fragments' own headers when they all agree (a fragment
	 * with no header, or whose default-domain files can't be read, is
	 * simply skipped rather than counted as a disagreement); otherwise
	 * WordPress core's own already-loaded `default`-domain header for
	 * $locale (the injected $core_plural_forms, when given one); null if
	 * neither source yields an answer -- the caller then omits the header
	 * entirely and drops every plural entry (see rebuild()).
	 *
	 * @param array[] $included_records Records classify_records() included.
	 * @param string  $locale           Locale code.
	 * @return string|null
	 */
	private function resolve_plural_forms( array $included_records, string $locale ): ?string {
		$seen = array();
		foreach ( $included_records as $record ) {
			foreach ( array_keys( $record['default_files'] ?? array() ) as $relative ) {
				$bytes = $this->fs->get( Babilim_Paths::root() . '/' . $relative );
				if ( ! is_string( $bytes ) ) {
					continue;
				}
				$pf = Babilim_Mo_Writer::read_header( $bytes, 'Plural-Forms' );
				if ( null !== $pf && '' !== $pf ) {
					$seen[ $pf ] = true;
				}
			}
		}
		if ( 1 === count( $seen ) ) {
			return array_key_first( $seen );
		}

		if ( null !== $this->core_plural_forms ) {
			$core_pf = call_user_func( $this->core_plural_forms, $locale );
			if ( is_string( $core_pf ) && '' !== $core_pf ) {
				return $core_pf;
			}
		}

		return null;
	}

	/**
	 * rebuild() for every locale this site has at least one manifest
	 * record for, OR a stored default_index() meta entry for, OR a
	 * `default/_merged-{locale}.*` file for -- not just the first of
	 * those. A locale whose *last* contributing record was just
	 * remove()'d has none of its manifest records left, but the stale
	 * `_merged-{locale}` files (and the default_index() meta describing
	 * them) are still sitting there, and remain so forever unless this
	 * method notices; the disk scan is the final backstop even for that
	 * -- see rebuild()'s "empty -> delete" path, which is what actually
	 * cleans a locale up once this method includes it.
	 *
	 * @return Babilim_Result
	 */
	public function rebuild_all(): Babilim_Result {
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}

		$locales = array();
		foreach ( $this->manifest->all() as $record ) {
			$locale = (string) ( $record['locale'] ?? '' );
			if ( Babilim_Paths::is_safe_locale( $locale ) ) {
				$locales[ $locale ] = true;
			}
		}
		foreach ( $this->manifest->default_index_locales() as $locale ) {
			$locale = (string) $locale;
			if ( Babilim_Paths::is_safe_locale( $locale ) ) {
				$locales[ $locale ] = true;
			}
		}
		// Both served formats -- a locale whose `.mo` was already cleaned
		// up (or never wrote successfully) but still has a `.l10n.php`
		// sitting there, or vice versa, must be reconciled too, not just
		// the `.mo`-only case.
		foreach ( array( 'mo', 'l10n.php' ) as $ext ) {
			foreach ( $this->fs->list( Babilim_Paths::dir( 'default' ), '_merged-*.' . $ext ) as $name ) {
				if ( 1 === preg_match( '/^_merged-(.+)\.' . preg_quote( $ext, '/' ) . '$/D', $name, $m ) && Babilim_Paths::is_safe_locale( $m[1] ) ) {
					$locales[ $m[1] ] = true;
				}
			}
		}

		$results = array();
		foreach ( array_keys( $locales ) as $locale ) {
			$results[ $locale ] = $this->rebuild( $locale )->data();
		}

		return Babilim_Result::ok( array( 'locales' => $results ) );
	}

	/**
	 * How long a newly-orphaned record's served files are left in place
	 * before a later sweep actually deletes them, once still unmatched --
	 * see sweep_orphans()'s own docblock.
	 *
	 * @var int
	 */
	private const ORPHAN_FILE_DELETE_DELAY = 7 * DAY_IN_SECONDS;

	/**
	 * How long after `orphaned_at` an orphaned record's manifest entry
	 * itself is removed (not just its files) -- see sweep_orphans()'s own
	 * docblock.
	 *
	 * @var int
	 */
	private const ORPHAN_RECORD_PURGE_DELAY = 30 * DAY_IN_SECONDS;

	/**
	 * Reconciles `_manifest.json` against what's actually on disk:
	 *
	 * (a) a served file (under plugins/, themes/, default/_parts/, or
	 *     default/) with no owning manifest record is deleted --
	 *     `custom/` is never scanned, and the merged index files
	 *     (`_merged-*`) and the layout's own `index.php`/`.htaccess` are
	 *     never treated as orphans;
	 * (b) a manifest record for a component no longer installed at all
	 *     (checked against get_plugins()/wp_get_themes(), not just
	 *     $active_components -- see the class docblock) gets
	 *     `orphaned_at` set, but its served files are left in place --
	 *     see (e);
	 * (c) a record for a component that's merely deactivated (still
	 *     installed) is left alone entirely -- its main file was never
	 *     going to be loaded by WordPress anyway, and its default
	 *     fragment is already excluded by rebuild()'s own "inactive"
	 *     check;
	 * (d) a record whose files are missing from disk gets its own
	 *     `integrity.ok` persisted false (so the next sync's verify()
	 *     step degrades it -- see Babilim's own cron wiring) and is
	 *     reported as an integrity failure;
	 * (e) an already-orphaned record (from a previous sweep) is handled
	 *     on its own timeline, independent of (b)'s candidate-selection
	 *     pass above: if the component it belongs to is installed again
	 *     (a false positive, or it came back), `orphaned_at` is cleared
	 *     and it's kept (never destructive, so this rescue always
	 *     happens, brake or no brake -- see below); otherwise its served
	 *     files are deleted once `orphaned_at` is at least
	 *     ORPHAN_FILE_DELETE_DELAY old, and the manifest record itself is
	 *     removed once it's at least ORPHAN_RECORD_PURGE_DELAY old.
	 *     Neither delay is skipped even when the other has already
	 *     passed -- a record past 30 days whose files were somehow never
	 *     deleted still gets them deleted as part of being purged. A
	 *     corrupt/unparseable `orphaned_at` is treated as age zero (just
	 *     marked), never as an enormous age that would clear both delays
	 *     instantly.
	 *
	 * Every classification above (candidates for (b), (d), (e)'s
	 * age-based delete/purge) is computed BEFORE anything is actually
	 * deleted, so the mass-orphan brake (see mass_orphan_brake_reason())
	 * can abort the whole run -- deleting NOTHING at all, not even (a)'s
	 * unregistered-file cleanup or (e)'s own age-based deletions for
	 * already-orphaned records -- when this run's installed-component
	 * list looks wrong, rather than partially acting on a list it's
	 * about to distrust.
	 *
	 * @param bool $force True for a run the site owner asked for by hand: the mass-orphan brake is skipped (nothing else about the run changes).
	 * @return Babilim_Result data: {deleted: string[], orphaned: array[],
	 *         kept: array[], integrity_failed: array[], files_deleted:
	 *         array[], purged: array[]}.
	 */
	public function sweep_orphans( bool $force = false ): Babilim_Result {
		if ( ! $this->manifest->is_available() ) {
			return Babilim_Result::error( 'manifest_unavailable', sprintf( /* translators: %s: the technical reason the manifest is unavailable. */ __( 'Manifest unavailable: %s', 'babilim' ), (string) $this->manifest->unavailable_reason() ) );
		}

		$installed = $this->installed_identities();
		if ( null === $installed ) {
			// The fail-closed guarantee: get_plugins()/wp_get_themes()
			// genuinely couldn't be determined this run (as opposed to
			// legitimately reporting "nothing installed" -- see this
			// method's own docblock and installed_identities()). Every
			// currently-served file, registered or not, is safer left
			// alone than risk deleting an active component's files
			// because we mistook "couldn't check" for "not installed".
			// Still writes a summary (see the mass-orphan-brake path
			// below for why every aborted run does).
			$this->write_aborted_sweep_summary();
			return Babilim_Result::error(
				'components_unavailable',
				__( 'Could not determine installed plugins/themes; sweep skipped, nothing was deleted.', 'babilim' ),
				array(
					'deleted'          => array(),
					'orphaned'         => array(),
					'kept'             => array(),
					'integrity_failed' => array(),
					'files_deleted'    => array(),
					'purged'           => array(),
				)
			);
		}

		// Pass 1: classify every record WITHOUT deleting or marking
		// anything yet -- the mass-orphan brake (below) must be able to
		// abort a run that looks wrong before a single byte is deleted,
		// not just before new orphans are marked (see the class
		// docblock's (e) and this method's own "zero deletion when the
		// brake trips" requirement). "Rescuing" an already-orphaned
		// record whose component is installed again is the one exception
		// -- it never deletes anything (only clears a flag), so it's
		// always safe to do even on a run the brake goes on to abort.
		$kept                = array();
		$integrity_failed    = array();
		$candidates          = array(); // Records that WOULD be newly orphaned this run.
		$already_orphaned    = array(); // Already-orphaned records the age-based delete/purge pass below still needs to consider.
		$write_failed        = array(); // Records whose own manifest update this run did not land.

		// The brake counts DISTINCT COMPONENTS (kind|slug), not records:
		// a single plugin with several locale records is one
		// component, not several, so genuinely removing it -- however
		// many locales it shipped -- is never itself "mass", and a
		// multi-locale site's smallest real removal can't get
		// permanently stuck behind a per-record count that happens to
		// exceed the threshold or exhaust a kind on its own.
		$managed_components           = array(); // kind|slug => true.
		$managed_components_by_kind   = array(); // kind => [slug => true].
		$candidate_components         = array(); // kind|slug => true.
		$candidate_components_by_kind = array(); // kind => [slug => true].

		foreach ( $this->manifest->all() as $record ) {
			$kind          = (string) ( $record['kind'] ?? '' );
			$slug          = (string) ( $record['slug'] ?? '' );
			$component_key = $kind . '|' . $slug;
			$identity       = array(
				'kind'   => $record['kind'],
				'slug'   => $record['slug'],
				'locale' => $record['locale'],
			);

			if ( ! empty( $record['orphaned_at'] ) ) {
				if ( array_key_exists( $component_key, $installed ) ) {
					// Rescued: the component is (again, or was always)
					// genuinely installed -- never let a false positive,
					// or a component that legitimately came back, keep
					// counting down to deletion. Never destructive, so
					// safe to do regardless of what the brake below
					// decides.
					$record['orphaned_at'] = null;
					if ( ! $this->manifest->set( $record['kind'], $record['slug'], $record['locale'], $record ) ) {
						$write_failed[] = $identity;
					}
					$kept[] = $identity;
					continue;
				}
				$already_orphaned[] = $record;
				continue;
			}

			$managed_components[ $component_key ]         = true;
			$managed_components_by_kind[ $kind ][ $slug ] = true;

			if ( $this->record_has_missing_files( $record ) ) {
				// A read of what's already on disk, not a deletion --
				// independent of $installed, so also safe regardless of
				// the brake.
				$record['integrity'] = array(
					'checked_at' => gmdate( 'c' ),
					'ok'         => false,
				);
				if ( ! $this->manifest->set( $record['kind'], $record['slug'], $record['locale'], $record ) ) {
					$write_failed[] = $identity;
				}
				$integrity_failed[] = $identity;
				continue;
			}

			if ( array_key_exists( $component_key, $installed ) ) {
				$kept[] = $identity;
				continue;
			}

			$candidates[]                                  = $record;
			$candidate_components[ $component_key ]         = true;
			$candidate_components_by_kind[ $kind ][ $slug ] = true;
		}

		$managed_component_count   = count( $managed_components );
		$candidate_component_count = count( $candidate_components );
		$managed_by_kind_count     = array_map( 'count', $managed_components_by_kind );
		$candidate_by_kind_count   = array_map( 'count', $candidate_components_by_kind );

		// Mass-orphan brake: a single sweep is never trusted to orphan
		// more than half of the distinct components it manages, or every
		// last component of a given kind, in one run -- either shape is
		// far more likely to mean the installed-component list this
		// sweep worked from was itself wrong (see installed_identities()'s
		// own fail-closed guard for the "completely empty/unavailable"
		// case; this catches the "available, but badly wrong" case that
		// guard can't) than that a site genuinely uninstalled half its
		// plugins between two daily runs.
		$brake_reason = $force ? null : $this->mass_orphan_brake_reason( $candidate_component_count, $managed_component_count, $candidate_by_kind_count, $managed_by_kind_count );
		if ( null !== $brake_reason ) {
			// Zero deletion when the brake trips -- not even
			// delete_unregistered_files() (an unregistered file is still
			// a deletion, even though it doesn't itself depend on
			// $installed) or the age-based delete/purge pass below for
			// already-orphaned records: the whole premise of tripping is
			// that this run's installed-component list can't be trusted,
			// so nothing this run concluded from it is acted on.
			$guard_recorded   = $this->manifest->set_sweep_guard(
				array(
					'tripped_at'    => gmdate( 'c' ),
					'brake'         => $brake_reason,
					'would_orphan'  => $candidate_component_count,
					'managed_total' => $managed_component_count,
				)
			);
			$summary_recorded = $this->write_aborted_sweep_summary();
			return Babilim_Result::error(
				'mass_orphan_brake',
				sprintf(
					/* translators: %s: why the sweep was aborted, as a full sentence fragment from brake_message(). */
					__( 'Sweep aborted without orphaning anything: %s.', 'babilim' ),
					self::brake_message( $brake_reason )
				),
				array(
					'brake'            => $brake_reason,
					'deleted'          => array(),
					'orphaned'         => array(),
					'kept'             => $kept,
					'integrity_failed' => $integrity_failed,
					'files_deleted'    => array(),
					'purged'           => array(),
					'would_orphan'     => $candidate_component_count,
					'managed_total'    => $managed_component_count,
					'write_failed'     => $write_failed,
					'status_recorded'  => $guard_recorded && $summary_recorded,
				)
			);
		}

		// The brake didn't trip -- $installed is trusted for this run, so
		// it's now safe to actually delete anything.
		$deleted = $this->delete_unregistered_files();

		$files_deleted = array(); // Already-orphaned records whose files were deleted this run (7-day mark).
		$purged        = array(); // Already-orphaned records whose manifest entry was removed this run (30-day mark).
		foreach ( $already_orphaned as $record ) {
			$identity = array( 'kind' => $record['kind'], 'slug' => $record['slug'], 'locale' => $record['locale'] );
			$age      = self::orphan_age_seconds( (string) $record['orphaned_at'] );
			if ( $age >= self::ORPHAN_RECORD_PURGE_DELAY ) {
				$this->delete_record_files( $record );
				if ( $this->manifest->remove( $record['kind'], $record['slug'], $record['locale'] ) ) {
					$purged[] = $identity;
				} else {
					$write_failed[] = $identity;
				}
				continue;
			}
			if ( $age >= self::ORPHAN_FILE_DELETE_DELAY && ! $this->record_has_missing_files( $record ) ) {
				$this->delete_record_files( $record );
				$files_deleted[] = $identity;
			}
		}

		$orphaned = array();
		foreach ( $candidates as $record ) {
			// Marked only -- the served files are deliberately left in
			// place until a later sweep, at least ORPHAN_FILE_DELETE_DELAY
			// after this one, finds this record still unmatched (see the
			// class docblock's (e) above).
			$record['orphaned_at'] = gmdate( 'c' );
			$identity              = array(
				'kind'   => $record['kind'],
				'slug'   => $record['slug'],
				'locale' => $record['locale'],
			);
			if ( ! $this->manifest->set( $record['kind'], $record['slug'], $record['locale'], $record ) ) {
				// Not marked -- a later sweep starts this record's
				// countdown instead; never reported as orphaned now.
				$write_failed[] = $identity;
				continue;
			}
			$orphaned[] = $identity;
		}

		$summary_recorded = $this->manifest->set_sweep_summary(
			array(
				'swept_at'        => gmdate( 'c' ),
				'orphans_marked'  => count( $orphaned ),
				'orphans_deleted' => count( $files_deleted ),
				'records_purged'  => count( $purged ),
				'aborted'         => false,
			)
		);
		// A sweep that reaches this point genuinely completed without
		// tripping the brake -- any guard recorded by a PAST trip no
		// longer describes the current situation: the settings screen's
		// "A cleanup was paused on ..." notice must not keep showing
		// forever once things are fine again.
		$guard_cleared = $this->manifest->clear_sweep_guard();

		$data = array(
			'deleted'          => $deleted,
			'orphaned'         => $orphaned,
			'kept'             => $kept,
			'integrity_failed' => $integrity_failed,
			'files_deleted'    => $files_deleted,
			'purged'           => $purged,
			'write_failed'     => $write_failed,
		);
		if ( ! empty( $write_failed ) || ! $summary_recorded || ! $guard_cleared ) {
			return Babilim_Result::error(
				'manifest_write_failed',
				__( 'The cleanup ran, but some of its changes could not be recorded in the translation index; the next cleanup will retry them.', 'babilim' ),
				$data
			);
		}
		return Babilim_Result::ok( $data );
	}

	/**
	 * How long ago (seconds) $orphaned_at was, or 0 if it can't be parsed
	 * at all -- an unparseable/corrupt timestamp must never be treated as
	 * "now minus epoch zero" (billions of seconds, past both delays
	 * instantly): that would delete a record's files, and eventually the
	 * record itself, on the very next sweep for a timestamp that was
	 * simply malformed, not genuinely old. Treated the same as a record
	 * that was just marked instead.
	 *
	 * @param string $orphaned_at Stored `orphaned_at` value.
	 * @return int
	 */
	private static function orphan_age_seconds( string $orphaned_at ): int {
		$parsed = strtotime( $orphaned_at );
		return ( false === $parsed ) ? 0 : max( 0, time() - $parsed );
	}

	/**
	 * Records a zero-marked/deleted/purged, aborted sweep summary --
	 * shared by both abort paths (components_unavailable and
	 * mass_orphan_brake), so "every cleanup run writes one summary" (see
	 * render_sweep_status()) holds even when nothing was actually swept.
	 * Both abort paths already return an error; whether this write
	 * itself landed is passed back so the mass-orphan-brake path can say
	 * so in its result (a write that did not land is also recorded by the
	 * manifest itself -- see Babilim_Manifest::last_recorded_write_failure()).
	 *
	 * @return bool Whether the summary was recorded.
	 */
	private function write_aborted_sweep_summary(): bool {
		return $this->manifest->set_sweep_summary(
			array(
				'swept_at'        => gmdate( 'c' ),
				'orphans_marked'  => 0,
				'orphans_deleted' => 0,
				'records_purged'  => 0,
				'aborted'         => true,
			)
		);
	}

	/**
	 * Whether this sweep's candidate-for-orphaning set trips the mass-
	 * orphan brake: more than half of the distinct COMPONENTS (kind|slug,
	 * not records -- a component with several locale records is one
	 * component, not several) currently managed, or every last component
	 * of some kind -- see sweep_orphans()'s own docblock note on why.
	 *
	 * @param int $candidate_component_count Distinct kind|slug components with >=1 candidate record.
	 * @param int $managed_component_count   Distinct kind|slug components with >=1 non-orphaned record.
	 * @param array<string,int> $candidate_by_kind_count Same numerator, broken down by `kind`.
	 * @param array<string,int> $managed_by_kind_count   Same denominator, broken down by `kind`.
	 * @return array{code:string,count:int,total:int,kind?:string}|null Structured reason, or null if the brake doesn't trip.
	 */
	private function mass_orphan_brake_reason( int $candidate_component_count, int $managed_component_count, array $candidate_by_kind_count, array $managed_by_kind_count ): ?array {
		if ( $candidate_component_count < 2 || 0 === $managed_component_count ) {
			// Orphaning a single component is never "mass" -- a site
			// with exactly one plugin installed (however many locales it
			// shipped) must still be able to have it genuinely removed
			// and swept. The brake exists to catch a sweep suddenly
			// orphaning a large slice of everything it manages, not to
			// block the smallest possible real removal.
			return null;
		}
		if ( $candidate_component_count > 0.5 * $managed_component_count ) {
			return array(
				'code'  => 'more_than_half',
				'count' => $candidate_component_count,
				'total' => $managed_component_count,
			);
		}
		foreach ( $candidate_by_kind_count as $kind => $count ) {
			$kind_total = $managed_by_kind_count[ $kind ] ?? 0;
			// Same floor as above: a kind that only ever had one managed
			// component isn't "wiped out" in any meaningful sense by
			// removing that one component.
			if ( $kind_total >= 2 && $count === $kind_total ) {
				return array(
					'code'  => 'whole_kind',
					'count' => $count,
					'total' => $count,
					'kind'  => (string) $kind,
				);
			}
		}
		return null;
	}

	/**
	 * Whether a parsed entry is a plural one. The key form
	 * `msgid\0msgid_plural` is what both a `.mo` and
	 * WP_Translation_File::entries() use, and that parser returns the
	 * forms as one NUL-joined string rather than an array.
	 *
	 * @param string $key    Entry key.
	 * @param mixed  $msgstr Entry value.
	 */
	private static function is_plural_entry( string $key, $msgstr ): bool {
		return is_array( $msgstr )
			|| false !== strpos( $key, "\0" )
			|| ( is_string( $msgstr ) && false !== strpos( $msgstr, "\0" ) );
	}

	/**
	 * Turns a structured brake reason into a translated sentence.
	 *
	 * @param array $brake {code, count, total, kind?}.
	 */
	public static function brake_message( array $brake ): string {
		$count = (int) ( $brake['count'] ?? 0 );
		$total = (int) ( $brake['total'] ?? 0 );
		if ( 'whole_kind' === ( $brake['code'] ?? '' ) ) {
			$kind = (string) ( $brake['kind'] ?? '' );
			if ( 'theme' === $kind ) {
				/* translators: a kind of component, used in the sentence "it would orphan every managed theme component"; keep it a bare noun. */
				$kind_label = _x( 'theme', 'component kind in the sweep brake message', 'babilim' );
			} elseif ( 'plugin' === $kind ) {
				/* translators: a kind of component, used in the sentence "it would orphan every managed plugin component"; keep it a bare noun. */
				$kind_label = _x( 'plugin', 'component kind in the sweep brake message', 'babilim' );
			} else {
				$kind_label = $kind;
			}
			return sprintf(
				/* translators: 1: the kind of component (plugin or theme), already translated, 2: how many components of that kind. */
				__( 'it would orphan every managed %1$s component (%2$d of %2$d)', 'babilim' ),
				$kind_label,
				$count
			);
		}
		return sprintf(
			/* translators: 1: components that would be orphaned, 2: components managed in total. */
			__( 'it would orphan %1$d of %2$d managed components (more than half)', 'babilim' ),
			$count,
			$total
		);
	}

	/**
	 * Splits manifest records into "included" (still active, at the
	 * matching version, not orphaned) and "excluded" (with a reason),
	 * for one locale.
	 *
	 * @param string                $locale        Locale code.
	 * @param array<string,string[]> $active_by_key `kind|slug` => active versions claiming it.
	 * @return array{0: array[], 1: array[]} [included records, excluded entries].
	 */
	private function classify_records( string $locale, array $active_by_key ): array {
		$included = array();
		$excluded = array();

		foreach ( $this->manifest->all() as $record ) {
			if ( (string) ( $record['locale'] ?? '' ) !== $locale ) {
				continue;
			}
			if ( empty( $record['default_files'] ) ) {
				continue;
			}

			$slug = (string) $record['slug'];
			$key  = $record['kind'] . '|' . $slug;

			if ( ! empty( $record['orphaned_at'] ) ) {
				$excluded[] = array( 'slug' => $slug, 'reason' => 'orphaned' );
				continue;
			}
			if ( ! array_key_exists( $key, $active_by_key ) ) {
				$excluded[] = array( 'slug' => $slug, 'reason' => 'inactive' );
				continue;
			}
			if ( ! in_array( (string) ( $record['component_version'] ?? '' ), $active_by_key[ $key ], true ) ) {
				$excluded[] = array( 'slug' => $slug, 'reason' => 'version_mismatch' );
				continue;
			}

			$included[] = $record;
		}

		return array( $included, $excluded );
	}

	/**
	 * Reads every included record's default-domain fragment(s) and
	 * merges them: a key first seen from one component is kept; the
	 * same key with the *same* msgstr from another component just adds
	 * an extra owner (not a conflict); a *different* msgstr marks the
	 * key as conflicting (removed by the caller).
	 *
	 * @param array[] $included_records Records classify_records() included.
	 * @return array{0: array<string,mixed>, 1: array<string,string[]>, 2: array<string,bool>, 3: array[]}
	 *         [merged key=>msgstr, key=>owning slugs, conflicting keys, included-meta entries].
	 */
	private function merge_fragments( array $included_records ): array {
		$merged        = array();
		$owners        = array();
		$conflict_keys = array();
		$included_meta = array();

		foreach ( $included_records as $record ) {
			$slug           = (string) $record['slug'];
			$component_entries = 0;

			foreach ( array_keys( $record['default_files'] ) as $relative ) {
				$abs            = Babilim_Paths::root() . '/' . $relative;
				$file_entries   = (array) call_user_func( $this->parse_entries, $abs );
				$component_entries += count( $file_entries );

				foreach ( $file_entries as $key => $msgstr ) {
					if ( ! array_key_exists( $key, $merged ) ) {
						$merged[ $key ] = $msgstr;
						$owners[ $key ] = array( $slug );
						continue;
					}
					if ( self::same_msgstr( $merged[ $key ], $msgstr ) ) {
						$owners[ $key ][] = $slug;
						continue;
					}
					$conflict_keys[ $key ] = true;
					$owners[ $key ][]      = $slug;
				}
			}

			$included_meta[] = array(
				'slug'               => $slug,
				'component_version'  => (string) ( $record['component_version'] ?? '' ),
				'entries'            => $component_entries,
			);
		}

		return array( $merged, $owners, $conflict_keys, $included_meta );
	}

	/**
	 * The error rebuild() returns when the merged index files were written
	 * but their meta could not be recorded in the manifest.
	 *
	 * @param string $locale Locale code.
	 * @return Babilim_Result
	 */
	private static function index_meta_write_failed( string $locale ): Babilim_Result {
		return Babilim_Result::error(
			'manifest_write_failed',
			sprintf(
				/* translators: %s: the locale code the merged index was built for. */
				__( "The merged index for '%s' was rebuilt, but the translation index could not record it; the next rebuild will retry.", 'babilim' ),
				$locale
			),
			array( 'locale' => $locale )
		);
	}

	/**
	 * Deletes the merged index files for $locale (a no-op if absent --
	 * an empty result means "no supplement", matching not having one at
	 * all) and records the (empty) meta.
	 *
	 * @param string               $locale    Locale code.
	 * @param array<string,mixed>  $meta_base Meta fields common to both outcomes.
	 * @return Babilim_Result
	 */
	private function write_empty_index( string $locale, array $meta_base ): Babilim_Result {
		$mo_path  = Babilim_Paths::merged_default( $locale, 'mo' );
		$php_path = Babilim_Paths::merged_default( $locale, 'l10n.php' );

		$this->fs->delete( $mo_path );
		$this->fs->delete( $php_path );
		self::invalidate_cache_for_dir( Babilim_Paths::dir( 'default' ) );

		$meta            = $meta_base;
		$meta['entries'] = 0;
		$meta['sha256']  = null;
		if ( ! $this->manifest->set_default_index( $locale, $meta ) ) {
			return self::index_meta_write_failed( $locale );
		}

		return Babilim_Result::ok(
			array(
				'locale'  => $locale,
				'entries' => 0,
				'deleted' => true,
			)
		);
	}

	/**
	 * Writes the merged `.mo` (via Babilim_Mo_Writer) and `.l10n.php`
	 * (via var_export()) to a per-locale staging directory, re-parses
	 * both and checks their entry counts against $final before the
	 * atomic rename into merged_default() (a `.l10n.php` must never
	 * be trusted onto disk without being read back first).
	 *
	 * @param string                $locale       Locale code.
	 * @param array<string,mixed>   $final        Final key => msgstr map.
	 * @param array<string,mixed>   $meta_base    Meta fields common to both outcomes.
	 * @param string|null           $plural_forms resolve_plural_forms()'s result -- omitted
	 *                                             from the written headers entirely when null.
	 * @return Babilim_Result
	 */
	private function write_merged_index( string $locale, array $final, array $meta_base, ?string $plural_forms = null ): Babilim_Result {
		$headers = array(
			'Project-Id-Version'        => 'Babilim',
			'MIME-Version'               => '1.0',
			'Content-Type'               => 'text/plain; charset=UTF-8',
			'Content-Transfer-Encoding' => '8bit',
			'Language'                   => $locale,
		);
		if ( null !== $plural_forms ) {
			$headers['Plural-Forms'] = $plural_forms;
		}

		$mo_bytes = Babilim_Mo_Writer::write( $final, $headers );
		$php_src  = self::build_l10n_php( $final, $locale, $headers );

		$staging = Babilim_Paths::default_index_staging_dir( $locale );
		$staging_mo  = $staging . '/_merged-' . $locale . '.mo';
		$staging_php = $staging . '/_merged-' . $locale . '.l10n.php';

		if ( ! $this->fs->put( $staging_mo, $mo_bytes ) || ! $this->fs->put( $staging_php, $php_src ) ) {
			$this->fs->delete( $staging_mo );
			$this->fs->delete( $staging_php );
			return Babilim_Result::error( 'write_failed', __( 'Could not write the staged merged index.', 'babilim' ) );
		}

		$reparsed_mo  = (array) call_user_func( $this->parse_entries, $staging_mo );
		$reparsed_php = (array) call_user_func( $this->parse_entries, $staging_php );
		if ( count( $reparsed_mo ) !== count( $final ) || count( $reparsed_php ) !== count( $final ) ) {
			$this->fs->delete( $staging_mo );
			$this->fs->delete( $staging_php );
			return Babilim_Result::error( 'entry_count_mismatch', __( 'Re-parsed entry count did not match the built index.', 'babilim' ) );
		}

		$mo_path  = Babilim_Paths::merged_default( $locale, 'mo' );
		$php_path = Babilim_Paths::merged_default( $locale, 'l10n.php' );

		if ( ! $this->fs->rename( $staging_mo, $mo_path ) || ! $this->fs->rename( $staging_php, $php_path ) ) {
			$this->fs->delete( $staging_mo );
			$this->fs->delete( $staging_php );
			$this->fs->delete( $mo_path );
			$this->fs->delete( $php_path );
			// The previous (still-good) served index is gone too, not
			// just the new one that failed to land -- deleting it here
			// is deliberate (a half-renamed pair, e.g. a new `.mo` next
			// to a stale `.l10n.php`, would be worse than neither), but
			// the result is "no supplement served for $locale until the
			// next successful rebuild", same as write_empty_index()'s own
			// outcome, not a harmless no-op. Both the message and the
			// recorded meta say so explicitly, rather than leaving a
			// caller to infer it from the generic error code alone.
			$failure_meta            = $meta_base;
			$failure_meta['entries'] = 0;
			$failure_meta['sha256']  = null;
			$failure_meta['error']   = 'rename_failed: the previous merged index (if any) was removed and not replaced';
			// Already an error either way; if even this meta could not be
			// recorded, the manifest's own write-failure record says so.
			$this->manifest->set_default_index( $locale, $failure_meta );
			return Babilim_Result::error(
				'rename_failed',
				sprintf(
					/* translators: %s: the locale code the merged index was built for. */
					__( "Could not move the merged index for '%s' into place; the previous served index (if any) was removed rather than left half-updated. No default-domain supplement is served for this locale until the next successful rebuild.", 'babilim' ),
					$locale
				),
				array( 'locale' => $locale )
			);
		}

		self::invalidate_cache_for_dir( Babilim_Paths::dir( 'default' ) );
		self::invalidate_cache_for_file( $mo_path );
		self::invalidate_cache_for_file( $php_path );

		$meta            = $meta_base;
		$meta['entries'] = count( $final );
		$meta['sha256']  = hash( 'sha256', $mo_bytes );
		if ( ! $this->manifest->set_default_index( $locale, $meta ) ) {
			return self::index_meta_write_failed( $locale );
		}

		return Babilim_Result::ok(
			array(
				'locale'  => $locale,
				'entries' => count( $final ),
				'deleted' => false,
			)
		);
	}

	/**
	 * Renders the merged index as a `.l10n.php` source string: a plain
	 * `var_export()`'d array whose `messages` key is exactly the same
	 * key=>msgstr shape $parse_entries() itself returns -- so re-parsing
	 * it (see write_merged_index()) is symmetric with parsing any other
	 * entries file. This mirrors the general shape WordPress core's own
	 * `.l10n.php` format (WP_Translation_File_PHP, introduced in WP 6.5)
	 * is documented to use (`domain`/`plural-forms`/`language`/`messages`),
	 * but -- unlike a main file's `.l10n.php`, which Babilim_Installer
	 * produces via the real `WP_Translation_File::transform()` -- this
	 * class has no such callable injected (see the class docblock) and
	 * so builds the array by hand. This has NOT been verified against a
	 * real WordPress install.
	 *
	 * @param array<string,mixed>  $entries Final key => msgstr map.
	 * @param string                $locale  Locale code.
	 * @param array<string,string>  $headers PO-style headers.
	 * @return string
	 */
	private static function build_l10n_php( array $entries, string $locale, array $headers ): string {
		$data = array(
			'domain'       => 'messages',
			'plural-forms' => $headers['Plural-Forms'] ?? null,
			'language'     => $locale,
			'messages'     => $entries,
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- serialises plain translation data into the same array-literal shape WordPress core's own .l10n.php files use.
		return "<?php\nreturn " . var_export( $data, true ) . ";\n";
	}

	/**
	 * Whether two msgstr values (each either a plain string or an array
	 * of plural forms) are the same translation.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	private static function same_msgstr( $a, $b ): bool {
		return $a === $b;
	}

	/**
	 * Splits a `{msgctxt}\x04{msgid}` (or bare `{msgid}`) merge key back
	 * into its two parts.
	 *
	 * @param string $key Merge key.
	 * @return array{0: string, 1: string} [msgctxt, msgid].
	 */
	private static function split_key( string $key ): array {
		$pos = strpos( $key, "\x04" );
		if ( false === $pos ) {
			return array( '', $key );
		}
		return array( substr( $key, 0, $pos ), substr( $key, $pos + 1 ) );
	}

	/**
	 * Deletes every served file under plugins/, themes/, default/_parts/
	 * and default/ that no manifest record owns -- never custom/, never
	 * the merged index files, never the layout's own index.php/.htaccess.
	 *
	 * @return string[] Relative paths (to Babilim_Paths::root()) deleted.
	 */
	private function delete_unregistered_files(): array {
		$deleted = array();

		foreach ( array( 'plugins', 'themes', 'default/_parts', 'default' ) as $type ) {
			$dir = Babilim_Paths::dir( $type );
			foreach ( $this->fs->list( $dir ) as $name ) {
				if ( 'index.php' === $name || '.htaccess' === $name ) {
					continue;
				}
				if ( 'default' === $type && 0 === strpos( $name, '_merged-' ) ) {
					continue;
				}
				$relative = $type . '/' . $name;
				if ( null !== $this->manifest->owner_of( $relative ) ) {
					continue;
				}
				if ( $this->fs->delete( $dir . '/' . $name ) ) {
					$deleted[] = $relative;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Whether any of $record's own registered files (main or default
	 * supplement) is missing from disk.
	 *
	 * @param array $record Manifest record.
	 * @return bool
	 */
	private function record_has_missing_files( array $record ): bool {
		$relatives = array_merge(
			array_keys( $record['files'] ?? array() ),
			array_keys( $record['default_files'] ?? array() )
		);
		foreach ( $relatives as $relative ) {
			if ( ! $this->fs->exists( Babilim_Paths::root() . '/' . $relative ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deletes every one of $record's own registered files (main and
	 * default supplement).
	 *
	 * @param array $record Manifest record.
	 */
	private function delete_record_files( array $record ): void {
		foreach ( array_keys( $record['files'] ?? array() ) as $relative ) {
			$this->fs->delete( Babilim_Paths::root() . '/' . $relative );
		}
		foreach ( array_keys( $record['default_files'] ?? array() ) as $relative ) {
			$this->fs->delete( Babilim_Paths::root() . '/' . $relative );
		}
	}

	/**
	 * Every currently-installed (kind|slug) identity, active or not --
	 * see the class docblock for why this reads get_plugins()/
	 * wp_get_themes() directly rather than via a constructor-injected
	 * callable.
	 *
	 * Uses Babilim_Inventory::plugin_slug_from_file() for a plugin's
	 * identity -- the same wp.org-transient-aware slug derivation
	 * active_components() (Babilim::active_components()) and
	 * classify_records() (via manifest records' own `slug`) already use
	 * -- rather than a second, directory-name-only re-derivation of its
	 * own, so a plugin whose wp.org slug diverges from its directory name
	 * is recognized consistently everywhere.
	 *
	 * `get_plugins()` lives in `wp-admin/includes/plugin.php`, which a
	 * cron/non-admin request never loads on its own -- loaded here on
	 * demand, same as Babilim::active_components(). Returns null
	 * (rather than an empty array) if that list still can't be obtained
	 * after trying: sweep_orphans() must be able to tell "genuinely
	 * nothing installed" apart from "couldn't check" and refuse to act at
	 * all for the latter (see that method's own fail-closed handling).
	 *
	 * @return array<string,bool>|null `kind|slug` => true; null if unavailable.
	 */
	private function installed_identities(): ?array {
		$identities = array();

		try {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				return null;
			}
			// 'update_plugins' is a SITE transient in real WordPress --
			// see Babilim::active_components()'s own note on this; using
			// get_transient() here would always return false, silently
			// falling every plugin back to a directory-name-only identity.
			$update  = function_exists( 'get_site_transient' ) ? get_site_transient( 'update_plugins' ) : false;
			$plugins = (array) get_plugins();
			if ( empty( $plugins ) && $this->manifest_has_any_record_of_kind( 'plugin' ) ) {
				// A completely empty plugin list, while the manifest
				// expects at least one plugin record to exist, is itself
				// a "couldn't determine" signal: a real site that
				// genuinely installed a plugin (which is the only way a
				// plugin record gets into the manifest at all) doesn't
				// later show zero plugins unless something's wrong with
				// how this list was obtained. Treating this as available-
				// but-empty would orphan (and delete the files of) every
				// single plugin record on the next line of defense below.
				return null;
			}
			foreach ( $plugins as $file => $data ) {
				$file        = (string) $file;
				$dir_name    = Babilim_Inventory::plugin_dir_name_from_file( $file );
				$slug        = Babilim_Inventory::plugin_slug_from_file( $file, $update );
				$text_domain = Babilim_Inventory::plugin_text_domain_from_data( $data, $dir_name );
				// A union, not a single value: a record
				// installed under the directory name (no wp.org slug
				// known yet) must still be recognized once the transient
				// starts reporting a different slug for the same file,
				// and a record installed under the wp.org slug
				// (transient present at install time) must still be
				// recognized later even if `update_plugins` has since
				// gone missing -- WordPress core clears that transient
				// on every plugin install/update/delete
				// (wp_clean_plugins_cache()), but the text-domain header
				// never depends on it at all, so it survives as a
				// fallback identity for exactly that window. Erring
				// toward *more* recognized identities is the safe
				// direction: it can only make sweep_orphans()/rebuild()
				// keep something, never wrongly delete/exclude it.
				$identities[ 'plugin|' . $dir_name ]    = true;
				$identities[ 'plugin|' . $slug ]        = true;
				$identities[ 'plugin|' . $text_domain ] = true;
			}

			if ( ! function_exists( 'wp_get_themes' ) ) {
				return null;
			}
			$themes = (array) wp_get_themes();
			if ( empty( $themes ) && $this->manifest_has_any_record_of_kind( 'theme' ) ) {
				// Same fail-closed rule as the plugin list above -- a
				// real site always has at least its own active theme, so
				// a genuinely empty theme list while the manifest expects
				// one is itself the anomaly, not a real state.
				return null;
			}
			foreach ( array_keys( $themes ) as $stylesheet ) {
				$identities[ 'theme|' . (string) $stylesheet ] = true;
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return $identities;
	}

	/**
	 * Whether the manifest has at least one record whose `kind` matches
	 * $kind -- used by installed_identities() to decide whether an empty
	 * get_plugins()/wp_get_themes() list is a real site state or a
	 * "couldn't determine" signal (see that method).
	 *
	 * @param string $kind 'plugin'|'theme'.
	 * @return bool
	 */
	private function manifest_has_any_record_of_kind( string $kind ): bool {
		foreach ( $this->manifest->all() as $record ) {
			if ( $kind === (string) ( $record['kind'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * wp_cache_delete() for a directory's `translation_files` cache
	 * entry, when the function exists (never fatal without an object
	 * cache present).
	 *
	 * @param string $dir Directory whose cached lookup to invalidate.
	 */
	private static function invalidate_cache_for_dir( string $dir ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( md5( trailingslashit( $dir ) ), 'translation_files' );
		}
	}

	/**
	 * wp_opcache_invalidate() for one file, when the function exists.
	 *
	 * @param string $file File to invalidate.
	 */
	private static function invalidate_cache_for_file( string $file ): void {
		if ( function_exists( 'wp_opcache_invalidate' ) ) {
			wp_opcache_invalidate( $file, true );
		}
	}
}
