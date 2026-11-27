<?php
/**
 * The translation editor's service: lists a component's strings with what
 * each currently shows, saves a site owner's edit as an override row,
 * keeps drafts and the last HISTORY_MAX versions, and switches an override
 * off again (publish() does that when a draft puts the catalog text back).
 *
 * Why: the REST routes and the admin screens stay thin; every rule (the
 * checks, the base recorded on a first override, the history, "same text
 * saved again changes nothing") lives here once. A save writes rows through
 * Babilim_Overrides and has Babilim_Override_Writer rebuild the custom/
 * files; the rows are the truth, so a save that could not be written to
 * disk still counts (`live` = false, retried by the daily job). A text that
 * fails Babilim_Override_Check is never written to the files: it is kept
 * as a draft and the checks' problems are returned.
 *
 * A scope is array{kind,slug,domain,locale}; scope() is the only place a
 * request's names are turned into one, so every other method trusts it.
 * Only the explicit text-list action delegates a service request.
 *
 * Drafts: a draft is checked when it is stored (the answer carries the
 * problems; it is kept either way) and publish() turns every draft that
 * passes into a saved row with one rebuild of the files. strings() does one
 * catalog load and two row reads and counts, checks drafts, filters and
 * sorts in that one pass -- no cache, the catalog is read per request.
 *
 * @package Babilim
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor operations over one component + text domain + locale.
 */
final class Babilim_Editor {

	const PER_PAGE = 200;
	const FILTERS  = array( 'all', 'untranslated', 'edited', 'problems', 'review', 'orphan', 'babilim', 'wporg', 'bundled' );
	const SORTS    = array( 'default', 'untranslated', 'recent', 'az' );
	const SOURCES  = array( 'babilim', 'wporg', 'bundled' );

	/** @var Babilim_Overrides */
	private $overrides;

	/** @var Babilim_String_Catalog */
	private $catalog;

	/** @var Babilim_Override_Writer */
	private $writer;

	/** @var callable fn(): array -- Babilim_Inventory::components(). */
	private $components;

	/** @var callable fn(): string[] -- Babilim_Inventory::locales(). */
	private $locales;

	/** @var callable fn(): string -- 'Y-m-d H:i:s' (GMT). */
	private $now;

	/** @var callable fn(): int */
	private $user_id;

	/** @var Babilim_String_List|null */
	private $string_list;

	public function __construct( Babilim_Overrides $overrides, Babilim_String_Catalog $catalog, Babilim_Override_Writer $writer, callable $components, callable $locales, callable $now, callable $user_id, ?Babilim_String_List $string_list = null ) {
		$this->overrides  = $overrides;
		$this->catalog    = $catalog;
		$this->writer     = $writer;
		$this->components = $components;
		$this->locales    = $locales;
		$this->now        = $now;
		$this->user_id    = $user_id;
		$this->string_list = $string_list;
	}

	/**
	 * @return array<int,array{kind:string,slug:string,name:string,version:string,domains:string[],locales:string[]}>
	 */
	public function components(): array {
		$locales = array_values( array_map( 'strval', (array) call_user_func( $this->locales ) ) );
		$out     = array();
		foreach ( $this->all() as $c ) {
			$out[] = array(
				'kind'    => (string) $c['kind'],
				'slug'    => (string) $c['slug'],
				'name'    => (string) ( $c['name'] ?? '' ),
				'version' => (string) ( $c['version'] ?? '' ),
				'domains' => self::domains( $c ),
				'locales' => $locales,
			);
		}
		return $out;
	}

	/**
	 * The scope for a request's names, or null when the component is not
	 * installed, the domain is not one of its own, or the site does not use
	 * the locale.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $domain Text domain; '' for the component's own.
	 * @param string $locale Locale code.
	 * @return array{kind:string,slug:string,domain:string,locale:string}|null
	 */
	public function scope( string $kind, string $slug, string $domain, string $locale ): ?array {
		$c = $this->component( $kind, $slug );
		if ( null === $c ) {
			return null;
		}
		$domains = self::domains( $c );
		$domain  = '' === $domain ? (string) ( $domains[0] ?? '' ) : $domain;
		if ( ! in_array( $domain, $domains, true ) || ! Babilim_Paths::is_safe_name( $domain ) || ! Babilim_Paths::is_safe_locale( $locale )
			|| ! in_array( $locale, array_map( 'strval', (array) call_user_func( $this->locales ) ), true ) ) {
			return null;
		}
		return array(
			'kind'   => $kind,
			'slug'   => $slug,
			'domain' => $domain,
			'locale' => $locale,
		);
	}

	/**
	 * Takes over custom/ files this editor did not write (page load).
	 *
	 * @param array $scope Scope.
	 */
	public function open( array $scope ): void {
		if ( $this->overrides->ready() ) {
			$this->writer->adopt( $scope );
		}
	}

	/**
	 * The text domain of the component that holds a string; the main one
	 * when none does.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @param string $ref    Babilim_Translation_Search::ref().
	 */
	public function domain_of( string $kind, string $slug, string $locale, string $ref ): string {
		$c = $this->component( $kind, $slug );
		if ( null === $c ) {
			return '';
		}
		$domains = self::domains( $c );
		$ready   = $this->overrides->ready(); // No table: no SELECT (its error could leak into the JSON).
		foreach ( $domains as $domain ) {
			$scope = array(
				'kind'   => $kind,
				'slug'   => $slug,
				'domain' => $domain,
				'locale' => $locale,
			);
			if ( isset( $this->catalog->load( $c, $domain, $locale )['entries'][ $ref ] ) || ( $ready && array() !== $this->by_ref( $this->overrides->rows( $scope ), $ref ) ) ) {
				return $domain;
			}
		}
		return (string) ( $domains[0] ?? '' );
	}

	/**
	 * One page of the string list, with the numbers around it.
	 *
	 * `filter` is a status (all, untranslated, edited, problems, review,
	 * orphan) or, as in 2.0.0, a source (babilim, wporg, bundled); `source`
	 * narrows by a source on top of a status; `search` and `sort` apply too.
	 *
	 * @param array $scope Scope.
	 * @param array $query Keys filter, source, search, sort, page, ref.
	 * @return array{total:int,pages:int,page:int,nplurals:int,live:bool,no_source:bool,list:string,counts:array,progress:string[],pending:array,plural_examples:string[],items:array[]}
	 */
	public function strings( array $scope, array $query ): array {
		$L       = $this->load( $scope );
		$entries = $L['entries'];
		$ref     = (string) ( $query['ref'] ?? '' );
		$filter  = in_array( $query['filter'] ?? '', self::FILTERS, true ) ? (string) $query['filter'] : 'all';
		$source  = in_array( $query['source'] ?? '', self::SOURCES, true ) ? (string) $query['source'] : '';
		$sort    = in_array( $query['sort'] ?? '', self::SORTS, true ) ? (string) $query['sort'] : 'default';
		$needle  = self::lower( trim( (string) ( $query['search'] ?? '' ) ) );

		$counts = array(
			'all'          => 0,
			'untranslated' => 0,
			'edited'       => 0,
			'problems'     => 0,
			'review'       => 0,
			'orphan'       => 0,
		);
		$drafts = 0;
		$listed = array();
		$extra  = array();
		$problems = array();
		foreach ( array_keys( $entries + $L['saved'] ) as $r ) {
			$r = (string) $r;
			if ( isset( $L['folded'][ $r ] ) ) {
				continue; // An old string that waits under the new one it was matched to.
			}
			$entry = $entries[ $r ] ?? null;
			$saved = $L['saved'][ $r ] ?? array();
			$draft = $L['drafts'][ $r ] ?? null;
			$from  = $entry ?? $saved[0];
			$state = self::list_status( $L, $r );
			$origin = $entry['source'] ?? 'none';
			$review = isset( $L['review'][ $r ] );
			$live   = Babilim_Overrides::STATUS_ACTIVE === $state;
			$orphan = Babilim_Overrides::STATUS_ORPHAN === $state;
			$issues = array();
			if ( null !== $draft && ! isset( $L['orphan'][ $r ] ) ) {
				++$drafts;
				$issues = $this->check_draft( (string) $from['msgid'], self::item_plural( $entry, $saved, $draft ), self::forms( $draft ), self::active( $saved ) !== array() || $review, (array) ( $entry['msgstr'] ?? array() ), $L['nplurals'] );
			}
			$untranslated = 'none' === $origin && ! $live && ! $orphan;
			$filters = array( 'untranslated' => $untranslated, 'edited' => $live, 'problems' => array() !== $issues, 'review' => $review, 'orphan' => $orphan );
			++$counts['all'];
			foreach ( $filters as $k => $matches ) {
				if ( $matches ) {
					++$counts[ $k ];
				}
			}
			if ( '' !== $ref ) {
				if ( $r !== $ref ) {
					continue;
				}
			} elseif ( ! ( $filters[ $filter ] ?? ( 'all' === $filter || $filter === $origin ) ) || ( '' !== $source && $source !== $origin ) || ( '' !== $needle && ! self::matches( $needle, $entry, $saved, $draft, isset( $L['orphan'][ $r ] ) ) ) ) {
				continue;
			}
			if ( array() !== $issues ) {
				$problems[ $r ] = $issues;
			}
			$key = null;
			if ( 'untranslated' === $sort ) {
				$key = ! $untranslated;
			} elseif ( 'recent' === $sort ) {
				$at  = isset( $L['orphan'][ $r ] ) ? '' : (string) ( $draft[0]['modified_gmt'] ?? '' );
				$key = '' !== $at ? $at : ( Babilim_Overrides::STATUS_OFF !== $state ? (string) ( $saved[0]['modified_gmt'] ?? '' ) : '' );
			} elseif ( 'az' === $sort ) {
				$key = self::lower( (string) $from['msgid'] );
			}
			if ( null === $entry && '' === $ref ) {
				$extra[ $r ] = $key;
			} else {
				$listed[ $r ] = $key;
			}
		}
		// Strings only in the saved rows come last, by source text.
		uksort(
			$extra,
			static function ( $a, $b ) use ( $L ): int {
				return self::compare_text( $L['saved'][ $a ][0], $L['saved'][ $b ][0] );
			}
		);
		$refs = self::sorted( $listed + $extra, $sort, $L );

		$total = count( $refs );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $pages, max( 1, (int) ( $query['page'] ?? 1 ) ) );
		$items = array();
		foreach ( array_slice( $refs, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $r ) {
			$items[] = $this->item( (string) $r, $L, $problems[ $r ] ?? array() );
		}
		$plural_forms = (string) ( $L['cat']['plural_forms'] ?? Babilim_Plural_Forms::header( (string) $scope['locale'] ) );
		return array(
			'total'           => $total,
			'pages'           => $pages,
			'page'            => $page,
			'nplurals'        => $L['nplurals'],
			'live'            => ! $this->writer->is_dirty( $scope ),
			'no_source'       => array() === ( $L['cat']['sources'] ?? array() ),
			'list'            => $this->list_state( $L['c'], $L['cat'] ),
			'counts'          => $counts,
			'progress'        => array(
				sprintf( /* translators: %d: number of strings in the list. */ _n( '%d string', '%d strings', $counts['all'], 'babilim' ), $counts['all'] ),
				sprintf( /* translators: %d: number of untranslated strings. */ _n( '%d untranslated', '%d untranslated', $counts['untranslated'], 'babilim' ), $counts['untranslated'] ),
				sprintf( /* translators: %d: number of strings the user edited. */ _n( '%d edited by you', '%d edited by you', $counts['edited'], 'babilim' ), $counts['edited'] ),
			),
			'pending'         => self::pending( $drafts, $counts['problems'] ),
			'plural_examples' => Babilim_Plural_Forms::examples( $plural_forms ),
			'items'           => $items,
		);
	}

	/**
	 * Saves a draft: checks it and tells the problems, but keeps it either
	 * way and never writes it to the files. A text equal to what is live
	 * already is not a draft: the stored one is removed.
	 *
	 * @param array    $scope  Scope.
	 * @param string   $ref    String reference.
	 * @param string[] $msgstr Forms.
	 * @return Babilim_Result Ok with `draft`, `at`, `problems`, `pending`.
	 */
	public function save_draft( array $scope, string $ref, array $msgstr ): Babilim_Result {
		// Take a fresh catalog snapshot before locking. Drafts never publish files.
		$this->catalog->forget();
		$catalog = $this->load_catalog( $scope );
		return $this->writer->locked( fn(): Babilim_Result => $this->save_draft_locked( $scope, $ref, $msgstr, $catalog ) ) ?? $this->not_saved();
	}

	/** @param array{c:?array,cat:array,entries:array,nplurals:int} $catalog Catalog snapshot. */
	private function save_draft_locked( array $scope, string $ref, array $msgstr, array $catalog ): Babilim_Result {
		$L   = $this->load_rows( $scope, $catalog );
		$ctx = $this->context( $scope, $ref, $L );
		if ( $ctx instanceof Babilim_Result ) {
			return $ctx;
		}
		$msgstr = array_values( array_map( 'strval', $msgstr ) );
		$n      = '' !== $ctx['msgid_plural'] ? $ctx['nplurals'] : 1;
		$on     = self::active( $ctx['saved'] );
		$live   = array() !== $on ? self::forms( $on ) : (array) ( $ctx['entry']['msgstr'] ?? array() );
		// While an old text waits for a decision, the live text as a draft is the answer "use the new one": kept, not checked.
		if ( array_pad( $msgstr, $n, '' ) === array_pad( $live, $n, '' ) && ! $ctx['review'] ) {
			if ( ! $this->overrides->delete( $scope, $ref, true ) ) {
				return $this->not_saved();
			}
			$problems = array();
			$draft    = false;
			$at       = '';
		} else {
			if ( ! $this->put_draft( $scope, $ref, $ctx, $msgstr, $L['drafts'][ $ref ] ?? array() ) ) {
				return $this->not_saved();
			}
			$draft    = true;
			$problems = $this->check_draft( $ctx['msgid'], $ctx['msgid_plural'], $msgstr, array() !== $on || $ctx['review'], (array) ( $ctx['entry']['msgstr'] ?? array() ), $ctx['nplurals'] );
		}
		$L = $this->refreshed( $scope, $L, false );
		return Babilim_Result::ok(
			array(
				'draft'    => $draft,
				'at'       => $draft ? (string) ( $L['drafts'][ $ref ][0]['modified_gmt'] ?? '' ) : $at,
				'problems' => $problems,
				'pending'  => $this->pending_of( $L ),
			)
		);
	}

	/**
	 * Throws away one draft, or every draft of the scope (including those of
	 * strings that are no longer listed). Saved rows and files stay.
	 *
	 * @param array  $scope Scope.
	 * @param string $ref   String reference; '' for all.
	 * @return Babilim_Result Ok with `discarded` (strings) and `pending`.
	 */
	public function discard( array $scope, string $ref = '' ): Babilim_Result {
		return $this->writer->locked( fn(): Babilim_Result => $this->discard_locked( $scope, $ref ) ) ?? $this->not_saved();
	}

	private function discard_locked( array $scope, string $ref ): Babilim_Result {
		if ( ! $this->overrides->ready() ) {
			return $this->no_table();
		}
		$L         = $this->load( $scope );
		$discarded = 0;
		foreach ( array_keys( $L['drafts'] ) as $r ) {
			if ( ( '' === $ref || (string) $r === $ref ) && $this->overrides->delete( $scope, (string) $r, true ) ) {
				++$discarded;
			}
		}
		$L = $this->refreshed( $scope, $L, false );
		return Babilim_Result::ok(
			array(
				'discarded' => $discarded,
				'pending'   => $this->pending_of( $L ),
			)
		);
	}

	/**
	 * Turns the scope's drafts into saved rows in one go: every draft that
	 * passes the checks, one rebuild of the files at the end. A draft that
	 * fails stays a draft; one whose string is no longer listed is left alone;
	 * a partial batch is still a success -- the lists say what happened.
	 * The whole read-decide-write runs under the manifest lock, so two tabs
	 * saving the same string cannot write a stale text over a newer one.
	 *
	 * @param array $scope Scope.
	 * @return Babilim_Result Ok with published, kept, failed, live, reason, message, pending; or error `not_saved` when the lock stays taken.
	 */
	public function publish( array $scope ): Babilim_Result {
		if ( ! $this->overrides->ready() ) {
			return $this->no_table();
		}
		$result = $this->writer->locked(
			function () use ( $scope ): Babilim_Result {
				return $this->publish_locked( $scope );
			}
		);
		return $result instanceof Babilim_Result ? $result : $this->not_saved();
	}

	/**
	 * publish() with the lock held.
	 *
	 * @param array $scope Scope.
	 */
	private function publish_locked( array $scope ): Babilim_Result {
		$L         = $this->load( $scope );
		$published = array();
		$kept      = array();
		$failed    = array();
		foreach ( $L['drafts'] as $ref => $rows ) {
			$ref = (string) $ref;
			if ( self::hidden( $L, $ref ) ) {
				continue; // Not listed (the component changed, or waiting under another string): keep the user's words.
			}
			$ctx    = $this->ctx_from( $L, $ref );
			$msgstr = self::forms( $rows );
			$n      = '' !== $ctx['msgid_plural'] ? $ctx['nplurals'] : 1;
			$on     = self::active( $ctx['saved'] );
			$cat    = (array) ( $ctx['entry']['msgstr'] ?? array() );
			$live   = array() !== $on ? self::forms( $on ) : $cat;
			$padded = array_pad( $msgstr, $n, '' );
			$old    = $ctx['review'] ? $L['review'][ $ref ]['ref'] : '';
			if ( $padded === array_pad( $live, $n, '' ) ) {
				if ( '' === $old ) {
					$this->overrides->delete( $scope, $ref, true );
				} elseif ( $this->overrides->delete( $scope, $old ) ) { // Use the new text: the old row only goes.
					$this->overrides->delete( $scope, $old, true ); // And any draft it had: nothing would ever show it again.
					$this->overrides->delete( $scope, $ref, true );
					$published[] = $ref;
				} else {
					$failed[] = $ref;
				}
				continue;
			}
			if ( array() !== $on && $padded === array_pad( $cat, $n, '' ) ) {
				if ( $this->store( $scope, $ref, $ctx['saved'], $this->switched_off( $ctx['saved'] ) ) && ( '' === $old || $this->overrides->delete( $scope, $old ) ) ) {
					'' === $old || $this->overrides->delete( $scope, $old, true );
					$this->overrides->delete( $scope, $ref, true );
					$published[] = $ref;
				} else {
					$failed[] = $ref;
				}
				continue;
			}
			$problems = Babilim_Override_Check::problems( $ctx['msgid'], $ctx['msgid_plural'], $msgstr, $ctx['nplurals'] );
			if ( array() !== $problems ) {
				$kept[] = array(
					'ref'      => $ref,
					'problems' => $problems,
				);
				continue;
			}
			if ( $this->commit( $scope, $ref, $ctx, $msgstr ) && ( '' === $old || $this->overrides->delete( $scope, $old ) ) ) {
				'' === $old || $this->overrides->delete( $scope, $old, true );
				$this->overrides->delete( $scope, $ref, true );
				$published[] = $ref;
			} else {
				$failed[] = $ref;
			}
		}

		$w     = $this->written( $scope, array() === $published )->data();
		$live  = (bool) $w['live'];
		$parts = array();
		if ( array() !== $published ) {
			$n       = count( $published );
			$parts[] = $live
				? sprintf( /* translators: %d: number of changes. */ _n( '%d change is live.', '%d changes are live.', $n, 'babilim' ), $n )
				: sprintf( /* translators: %d: number of changes. */ _n( '%d change is saved.', '%d changes are saved.', $n, 'babilim' ), $n ) . ' ' . $w['reason'];
		}
		if ( array() !== $kept ) {
			$n       = count( $kept );
			$parts[] = sprintf( /* translators: %d: number of drafts. */ _n( '%d draft needs fixing and was not saved.', '%d drafts need fixing and were not saved.', $n, 'babilim' ), $n );
		}
		if ( array() !== $failed ) {
			$n       = count( $failed );
			$parts[] = sprintf( /* translators: %d: number of changes. */ _n( '%d change could not be saved.', '%d changes could not be saved.', $n, 'babilim' ), $n );
		}
		$L = $this->refreshed( $scope, $L );
		return Babilim_Result::ok(
			array(
				'published' => $published,
				'kept'      => $kept,
				'failed'    => $failed,
				'live'      => $live,
				'reason'    => (string) $w['reason'],
				'message'   => array() === $parts ? __( 'Nothing to save.', 'babilim' ) : implode( ' ', $parts ),
				'pending'   => $this->pending_of( $L ),
			)
		);
	}

	/**
	 * Saves a translation at once: checks it, writes one row per plural form
	 * and rebuilds the files. A text that fails the checks is kept as a draft.
	 * No route or screen calls this any more (the editor drafts and publish()
	 * makes them live); it stays as the single-string entry the rule tests use.
	 *
	 * @internal Kept for the rule tests; not part of the editor's public surface.
	 *
	 * @param array    $scope  Scope.
	 * @param string   $ref    String reference.
	 * @param string[] $msgstr Forms.
	 * @return Babilim_Result Ok with `live` and `reason`; or error `check_failed` with `problems`.
	 */
	public function save( array $scope, string $ref, array $msgstr ): Babilim_Result {
		$ctx = $this->context( $scope, $ref );
		if ( $ctx instanceof Babilim_Result ) {
			return $ctx;
		}
		$msgstr = array_values( array_map( 'strval', $msgstr ) );
		$old    = $ctx['saved'];

		if ( array() !== $old && Babilim_Overrides::STATUS_OFF !== $old[0]['status'] && self::forms( $old ) === $msgstr ) {
			$this->overrides->delete( $scope, $ref, true );
			return $this->written( $scope, true );
		}

		$problems = Babilim_Override_Check::problems( $ctx['msgid'], $ctx['msgid_plural'], $msgstr, $ctx['nplurals'] );
		if ( array() !== $problems ) {
			$this->put_draft( $scope, $ref, $ctx, $msgstr, $this->by_ref( $this->overrides->rows( $scope, true ), $ref ) );
			return Babilim_Result::error( 'check_failed', __( 'The translation did not pass the checks, so it was not saved. Your text is kept as a draft.', 'babilim' ), array( 'problems' => $problems ) );
		}

		if ( ! $this->commit( $scope, $ref, $ctx, $msgstr ) ) {
			return $this->not_saved();
		}
		$this->overrides->delete( $scope, $ref, true );
		return $this->written( $scope, false );
	}

	/**
	 * The saved versions of one string, newest first.
	 *
	 * @param array  $scope Scope.
	 * @param string $ref   String reference.
	 * @return array<int,array{index:int,msgstr:string[],at:string}>
	 */
	public function history( array $scope, string $ref ): array {
		if ( ! $this->overrides->ready() ) {
			return array();
		}
		$rows = $this->by_ref( $this->overrides->rows( $scope ), $ref );
		$out  = array();
		foreach ( (array) ( $rows[0]['history'] ?? array() ) as $k => $first ) {
			$forms = array();
			foreach ( $rows as $row ) {
				if ( isset( $row['history'][ $k ] ) ) {
					$forms[] = (string) $row['history'][ $k ]['msgstr'];
				}
			}
			$out[] = array(
				'index'  => (int) $k,
				'msgstr' => $forms,
				'at'     => (string) ( $first['at'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Deletes the saved and draft rows of a text that is no longer used and
	 * rebuilds the files. The one write that skips Save: the original is gone,
	 * the site never shows the text, so nobody sees a change.
	 *
	 * @param array  $scope Scope.
	 * @param string $ref   String reference.
	 * @return Babilim_Result Ok with `live`, `reason`, `pending`; or error `not_removable`.
	 */
	public function remove( array $scope, string $ref ): Babilim_Result {
		return $this->writer->locked( fn(): Babilim_Result => $this->remove_locked( $scope, $ref ) ) ?? $this->not_saved();
	}

	private function remove_locked( array $scope, string $ref ): Babilim_Result {
		if ( ! $this->overrides->ready() ) {
			return $this->no_table();
		}
		$L = $this->load( $scope );
		if ( ! isset( $L['orphan'][ $ref ] ) ) {
			return Babilim_Result::error( 'not_removable', __( 'That text is still in use, so it cannot be removed here.', 'babilim' ) );
		}
		if ( ! $this->overrides->delete( $scope, $ref ) || ! $this->overrides->delete( $scope, $ref, true ) ) {
			return $this->not_saved();
		}
		$w = $this->written( $scope, false )->data();
		$L = $this->refreshed( $scope, $L );
		return Babilim_Result::ok(
			array(
				'live'    => (bool) $w['live'],
				'reason'  => (string) $w['reason'],
				'pending' => $this->pending_of( $L ),
			)
		);
	}

	/**
	 * Strings that wait for a decision after an update, per component and
	 * language (text domains added up). Only scopes with a review row are loaded.
	 *
	 * @return array<string,int> `{kind}/{slug}/{locale}` => count.
	 */
	public function review_counts(): array {
		$out = array();
		if ( ! $this->overrides->ready() ) {
			return $out;
		}
		foreach ( $this->overrides->scopes( Babilim_Overrides::STATUS_REVIEW ) as $scope ) {
			if ( null === $this->component( (string) $scope['kind'], (string) $scope['slug'] ) ) {
				continue;
			}
			$n = count( $this->load( $scope )['review'] );
			if ( $n > 0 ) {
				$key         = $scope['kind'] . '/' . $scope['slug'] . '/' . $scope['locale'];
				$out[ $key ] = ( $out[ $key ] ?? 0 ) + $n;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Forgets every override and every pending retry, after the custom/
	 * files were removed (settings "Remove everything", custom/ not kept).
	 * custom/_backup/ is left alone: it holds the owner's own files.
	 */
	public function forget_all(): void {
		if ( $this->overrides->ready() ) {
			$this->overrides->clear();
		}
		delete_site_option( Babilim_Override_Writer::DIRTY_OPTION );
	}

	// -- internals ---------------------------------------------------------

	/**
	 * One catalog load and the two row reads (saved, drafts).
	 *
	 * @param array $scope Scope.
	 * @return array{c:?array,cat:array,entries:array,saved:array,drafts:array,nplurals:int,review:array,folded:array,orphan:array}
	 */
	private function load( array $scope ): array {
		return $this->load_rows( $scope, $this->load_catalog( $scope ) );
	}

	/** @return array{c:?array,cat:array,entries:array,nplurals:int} */
	private function load_catalog( array $scope ): array {
		$c     = $this->component( (string) $scope['kind'], (string) $scope['slug'] );
		$cat   = null === $c ? array() : $this->catalog->load( $c, (string) $scope['domain'], (string) $scope['locale'] );
		return array(
			'c'        => $c,
			'cat'      => $cat,
			'entries'  => (array) ( $cat['entries'] ?? array() ),
			'nplurals' => Babilim_Plural_Forms::nplurals( (string) ( $cat['plural_forms'] ?? Babilim_Plural_Forms::header( (string) $scope['locale'] ) ) ),
		);
	}

	/**
	 * @param array{c:?array,cat:array,entries:array,nplurals:int} $catalog Catalog snapshot.
	 * @return array{c:?array,cat:array,entries:array,saved:array,drafts:array,nplurals:int,review:array,folded:array,orphan:array}
	 */
	private function load_rows( array $scope, array $catalog ): array {
		$ready = $this->overrides->ready(); // No table: the catalog alone, no SELECT.
		return $this->marks(
			$catalog + array(
				'saved'    => $ready ? $this->group( $this->overrides->rows( $scope ) ) : array(),
				'drafts'   => $ready ? $this->group( $this->overrides->rows( $scope, true ) ) : array(),
			)
		);
	}

	public function fetch_string_list( array $scope ): Babilim_Result {
		$c = $this->component( (string) $scope['kind'], (string) $scope['slug'] );
		if ( null === $c || null === $this->string_list ) {
			return Babilim_Result::error( 'unknown_component', __( 'That plugin or theme is not available here.', 'babilim' ) );
		}
		$cat   = $this->catalog->load( $c, (string) $scope['domain'], (string) $scope['locale'] );
		$state = $this->list_state( $c, $cat );
		if ( 'local' === $state || 'unavailable' === $state ) {
			return Babilim_Result::ok( array( 'status' => 'local' === $state ? 'ready' : 'unavailable' ) );
		}
		$result = $this->string_list->fetch( $c );
		if ( $result->is_ok() ) {
			$this->catalog->forget(); // The text list is one of its layers.
			$key = self::list_pending_key( $c );
			if ( 'pending' === $result->get( 'status' ) ) {
				set_site_transient( $key, true, HOUR_IN_SECONDS );
			} else {
				delete_site_transient( $key );
			}
		}
		return $result;
	}

	private function list_state( ?array $component, array $catalog ): string {
		if ( ! empty( $catalog['current'] ) ) {
			return 'local';
		}
		if ( ! empty( $catalog['list_unavailable'] ) ) {
			return 'unavailable';
		}
		if ( empty( $component['is_wporg'] ) ) {
			return 'not_wporg';
		}
		if ( null === $this->string_list || ! $this->string_list->granted() ) {
			return 'needs_consent';
		}
		return get_site_transient( self::list_pending_key( $component ) ) ? 'pending' : 'can_fetch';
	}

	private static function list_pending_key( array $component ): string {
		return 'babilim_list_' . hash( 'sha256', $component['kind'] . '/' . $component['slug'] . '/' . $component['version'] );
	}

	/**
	 * Adds what the marked rows mean to $L: `review` (new ref => the first
	 * old string pointing at it, in row order), `folded` (every old ref that
	 * waits under a new one) and `orphan` (no longer used: marked so, or
	 * marked for review while the string it pointed at is not listed).
	 *
	 * @param array $L load() without the marks.
	 */
	private function marks( array $L ): array {
		$L['review'] = array();
		$L['folded'] = array();
		$L['orphan'] = array();
		foreach ( $L['saved'] as $ref => $rows ) {
			$ref    = (string) $ref;
			$status = $rows[0]['status'];
			$to     = (string) $rows[0]['match_ref'];
			if ( isset( $L['entries'][ $ref ] ) ) {
				continue;
			}
			if ( Babilim_Overrides::STATUS_REVIEW === $status && '' !== $to && isset( $L['entries'][ $to ] ) ) {
				$L['folded'][ $ref ] = true;
				if ( ! isset( $L['review'][ $to ] ) ) {
					$L['review'][ $to ] = array(
						'ref'          => $ref,
						'msgid'        => (string) $rows[0]['msgid'],
						'msgid_plural' => (string) $rows[0]['msgid_plural'],
						'msgstr'       => self::forms( $rows ),
					);
				}
			} elseif ( Babilim_Overrides::STATUS_REVIEW === $status || Babilim_Overrides::STATUS_ORPHAN === $status ) {
				$L['orphan'][ $ref ] = true;
			}
		}
		return $L;
	}

	/**
	 * $L with the rows read again after a write (the catalog is not).
	 *
	 * @param array $scope Scope.
	 * @param array $L     load().
	 * @param bool  $saved Read the saved rows again (the write touched them).
	 */
	private function refreshed( array $scope, array $L, bool $saved = true ): array {
		if ( $saved ) {
			$L['saved'] = $this->group( $this->overrides->rows( $scope ) );
		}
		$L['drafts'] = $this->group( $this->overrides->rows( $scope, true ) );
		return $this->marks( $L );
	}

	/**
	 * Everything a write needs, or the refusal.
	 *
	 * @param array      $scope Scope.
	 * @param string     $ref   String reference.
	 * @param array|null $L     load() when the caller has it.
	 * @return Babilim_Result|array{entry:?array,saved:array[],component:array,ref:string,review:bool,current:bool,msgctxt:string,msgid:string,msgid_plural:string,nplurals:int}
	 */
	private function context( array $scope, string $ref, ?array $L = null ) {
		if ( ! $this->overrides->ready() ) {
			return $this->no_table();
		}
		$L = $L ?? $this->load( $scope );
		if ( null === $L['c'] ) {
			return Babilim_Result::error( 'unknown_component', __( 'That plugin or theme is not installed.', 'babilim' ) );
		}
		$ctx = $this->ctx_from( $L, $ref );
		if ( null === $ctx ) {
			return Babilim_Result::error( 'unknown_string', __( 'That string is not in this translation.', 'babilim' ) );
		}
		return $ctx;
	}

	/**
	 * The facts about one string, or null when no catalog entry, saved row or draft has it.
	 *
	 * @param array  $L   load().
	 * @param string $ref String reference.
	 */
	private function ctx_from( array $L, string $ref ): ?array {
		$entry = $L['entries'][ $ref ] ?? null;
		$saved = $L['saved'][ $ref ] ?? array();
		$any   = $saved[0] ?? ( $L['drafts'][ $ref ][0] ?? null );
		if ( null === $entry && null === $any ) {
			return null;
		}
		$from = $entry ?? $any;
		return array(
			'entry'        => $entry,
			'saved'        => $saved,
			'component'    => $L['c'],
			'ref'          => $ref,
			'review'       => isset( $L['review'][ $ref ] ),
			'current'      => null !== $entry && ! empty( $L['cat']['current'] ),
			'msgctxt'      => (string) $from['msgctxt'],
			'msgid'        => (string) $from['msgid'],
			'msgid_plural' => '' !== (string) ( $entry['msgid_plural'] ?? '' ) ? (string) $entry['msgid_plural'] : (string) ( $any['msgid_plural'] ?? '' ),
			'nplurals'     => $L['nplurals'],
		);
	}

	private function no_table(): Babilim_Result {
		return Babilim_Result::error( 'no_table', __( 'The table that stores edited translations does not exist yet.', 'babilim' ) );
	}

	/**
	 * Replaces a string's draft rows; puts the old ones back when one cannot be saved.
	 *
	 * @param array    $scope  Scope.
	 * @param string   $ref    String reference.
	 * @param array    $ctx    ctx_from().
	 * @param string[] $msgstr Forms.
	 * @param array[]  $old    The string's draft rows as they are now, put back on failure.
	 */
	private function put_draft( array $scope, string $ref, array $ctx, array $msgstr, array $old ): bool {
		$rows = array();
		foreach ( $msgstr as $i => $form ) {
			$rows[] = $this->row( $ctx, $i, $form, array() ) + array( 'status' => Babilim_Overrides::STATUS_ACTIVE );
		}
		if ( ! $this->overrides->delete( $scope, $ref, true ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( ! $this->overrides->put( $scope, $row, true ) ) {
				$this->overrides->delete( $scope, $ref, true );
				foreach ( $old as $o ) {
					$this->overrides->put( $scope, $o, true );
				}
				return false;
			}
		}
		return true;
	}

	/**
	 * Writes a checked text as the string's saved rows (base, history,
	 * origin, version) without rebuilding the files; save() and publish()
	 * share it.
	 *
	 * @param array    $scope  Scope.
	 * @param string   $ref    String reference.
	 * @param array    $ctx    ctx_from().
	 * @param string[] $msgstr Forms.
	 */
	private function commit( array $scope, string $ref, array $ctx, array $msgstr ): bool {
		$old   = $ctx['saved'];
		$entry = $ctx['entry'];
		$build = null !== $entry && 'babilim' === $entry['source'] ? (string) $entry['build_id'] : '';
		$base  = array() === $old || 'editor' !== $old[0]['origin'] || $build !== $old[0]['base_build'];
		$rows  = array();
		foreach ( $msgstr as $i => $form ) {
			$was = $old[ $i ] ?? null;
			$row = $this->row( $ctx, $i, $form, $was ?? array() ) + array( 'status' => Babilim_Overrides::STATUS_ACTIVE );
			if ( $base ) {
				$row += self::base_fields( $entry, $i );
			} elseif ( null !== $was ) {
				$row['base_source'] = $was['base_source'];
				$row['base_build']  = $was['base_build'];
				$row['base_msgstr'] = $was['base_msgstr'];
			}
			// A switched-off override already has its text in the history.
			$row['history'] = null === $was ? array() : ( Babilim_Overrides::STATUS_OFF === $was['status'] ? $was['history'] : $this->pushed( $was ) );
			$rows[]         = $row;
		}
		return $this->store( $scope, $ref, $old, $rows );
	}

	/**
	 * The rows that switch an override off, its text pushed into the history.
	 *
	 * @param array[] $old Saved rows of one string.
	 * @return array[]
	 */
	private function switched_off( array $old ): array {
		$rows = array();
		foreach ( $old as $was ) {
			$row                 = $was;
			$row['history']      = Babilim_Overrides::STATUS_OFF === $was['status'] ? $was['history'] : $this->pushed( $was );
			$row['status']       = Babilim_Overrides::STATUS_OFF;
			$row['modified_gmt'] = (string) call_user_func( $this->now );
			$row['modified_by']  = (int) call_user_func( $this->user_id );
			$rows[]              = $row;
		}
		return $rows;
	}

	/**
	 * The problems of a draft; none when it puts the catalog text back over
	 * an override (it is published as "switch the override off", not as text).
	 *
	 * @param string   $msgid     Original.
	 * @param string   $plural    Original plural.
	 * @param string[] $forms     Draft forms.
	 * @param bool     $override  Whether the string has a switched-on override.
	 * @param string[] $catalog   The catalog's translation.
	 * @param int      $nplurals  Forms the locale needs.
	 * @return array<int,array{form:int,code:string,message:string}>
	 */
	private function check_draft( string $msgid, string $plural, array $forms, bool $override, array $catalog, int $nplurals ): array {
		$n = '' !== $plural ? $nplurals : 1;
		if ( $override && array_pad( $forms, $n, '' ) === array_pad( $catalog, $n, '' ) ) {
			return array();
		}
		return Babilim_Override_Check::problems( $msgid, $plural, $forms, $nplurals );
	}

	/**
	 * Drafts and problems among the strings the list shows.
	 *
	 * @param array $L load() with fresh rows.
	 */
	private function pending_of( array $L ): array {
		$drafts   = 0;
		$problems = 0;
		foreach ( $L['drafts'] as $ref => $rows ) {
			if ( self::hidden( $L, (string) $ref ) ) {
				continue;
			}
			$ctx = $this->ctx_from( $L, (string) $ref );
			++$drafts;
			if ( array() !== $this->check_draft( $ctx['msgid'], $ctx['msgid_plural'], self::forms( $rows ), array() !== self::active( $ctx['saved'] ) || $ctx['review'], (array) ( $ctx['entry']['msgstr'] ?? array() ), $ctx['nplurals'] ) ) {
				++$problems;
			}
		}
		return self::pending( $drafts, $problems );
	}

	/**
	 * The ready-made sentences of the Save button and the draft note.
	 *
	 * @return array{drafts:int,problems:int,save:string,note:string,fix:string,discard:string}
	 */
	private static function pending( int $drafts, int $problems ): array {
		return array(
			'drafts'   => $drafts,
			'problems' => $problems,
			'save'     => 0 === $drafts ? __( 'Save', 'babilim' ) : sprintf( /* translators: %d: number of unsaved changes. */ _n( 'Save %d change', 'Save %d changes', $drafts, 'babilim' ), $drafts ),
			'note'     => 0 === $drafts ? '' : sprintf( /* translators: %d: number of drafts. */ _n( '%d draft saved, not live yet', '%d drafts saved, not live yet', $drafts, 'babilim' ), $drafts ),
			'fix'      => 0 === $problems ? '' : sprintf( /* translators: %d: number of drafts that fail the checks. */ _n( '%d needs fixing and stays a draft', '%d need fixing and stay drafts', $problems, 'babilim' ), $problems ),
			'discard'  => 0 === $drafts ? '' : sprintf( /* translators: %d: number of drafts. */ _n( 'Discard %d draft? Your live translations stay as they are.', 'Discard %d drafts? Your live translations stay as they are.', $drafts, 'babilim' ), $drafts ),
		);
	}

	/**
	 * The rows that are switched on (not `off`).
	 *
	 * @param array[] $rows Saved rows of one string.
	 * @return array[]
	 */
	private static function active( array $rows ): array {
		return array_values(
			array_filter(
				$rows,
				static function ( array $r ): bool {
					return Babilim_Overrides::STATUS_OFF !== $r['status'];
				}
			)
		);
	}

	/**
	 * Sort only references. The keys arrive in default order, which breaks recent ties.
	 *
	 * @param array   $keys Sort keys by reference.
	 * @param string  $sort  One of SORTS.
	 * @param array   $L Catalog and saved rows.
	 * @return array<int,int|string>
	 */
	private static function sorted( array $keys, string $sort, array $L ): array {
		if ( 'untranslated' === $sort ) {
			$first = array();
			$rest  = array();
			foreach ( $keys as $ref => $translated ) {
				if ( $translated ) {
					$rest[] = $ref;
				} else {
					$first[] = $ref;
				}
			}
			return array_merge( $first, $rest );
		}
		$refs = array_keys( $keys );
		if ( 'recent' === $sort ) {
			$stamped = array();
			$rest    = array();
			foreach ( $refs as $i => $ref ) {
				$at = $keys[ $ref ];
				if ( '' === $at ) {
					$rest[] = $ref;
				} else {
					$stamped[] = array( $at, $i, $ref );
				}
			}
			// usort() is not stable before PHP 8: the original position breaks ties.
			usort(
				$stamped,
				static function ( array $a, array $b ): int {
					return strcmp( $b[0], $a[0] ) ?: $a[1] <=> $b[1];
				}
			);
			return array_merge( array_column( $stamped, 2 ), $rest );
		}
		if ( 'az' === $sort ) {
			usort(
				$refs,
				static function ( $a, $b ) use ( $keys, $L ): int {
					return strcmp( $keys[ $a ], $keys[ $b ] ) ?: self::compare_text( $L['entries'][ $a ] ?? $L['saved'][ $a ][0], $L['entries'][ $b ] ?? $L['saved'][ $b ][0] );
				}
			);
		}
		return $refs;
	}

	private static function compare_text( array $a, array $b ): int {
		return strcmp( (string) $a['msgid'], (string) $b['msgid'] ) ?: strcmp( (string) $a['msgctxt'], (string) $b['msgctxt'] );
	}

	/**
	 * A row of one string for plural index $i; keeps what an existing row
	 * carries that the editor does not change. Only a string the list of
	 * the installed version has takes that version: one that is gone, or
	 * listed only by a list for an older version, keeps the version of its
	 * saved row ('' when it has none), so the remap still judges it.
	 *
	 * @param array  $ctx  context().
	 * @param int    $i    Plural index.
	 * @param string $form Translation form.
	 * @param array  $was  Existing row, or array().
	 */
	private function row( array $ctx, int $i, string $form, array $was ): array {
		return array(
			'ref'               => $ctx['ref'],
			'plural_index'      => $i,
			'msgctxt'           => $ctx['msgctxt'],
			'msgid'             => $ctx['msgid'],
			'msgid_plural'      => $ctx['msgid_plural'],
			'msgstr'            => $form,
			'origin'            => 'editor',
			'component_version' => self::row_version( $ctx['current'], $ctx['component'], $was, $ctx['saved'] ),
			'match_ref'         => (string) ( $was['match_ref'] ?? '' ),
			'feedback_build'    => (string) ( $was['feedback_build'] ?? '' ),
			'modified_gmt'      => (string) call_user_func( $this->now ),
			'modified_by'       => (int) call_user_func( $this->user_id ),
		);
	}

	/** The catalog version belongs only to strings in the current list. */
	public static function row_version( bool $current, array $component, array $was, array $saved ): string {
		return $current ? (string) ( $component['version'] ?? '' ) : (string) ( $was['component_version'] ?? $saved[0]['component_version'] ?? '' );
	}

	/** The translation underneath an override, captured at the time of saving. */
	public static function base_fields( ?array $entry, int $i ): array {
		$source = (string) ( $entry['source'] ?? 'none' );
		return array(
			'base_source' => 'none' === $source ? '' : $source,
			'base_build'  => 'babilim' === $source ? (string) $entry['build_id'] : '',
			'base_msgstr' => (string) ( $entry['msgstr'][ $i ] ?? '' ),
		);
	}

	/**
	 * A row's history with its current text put in front, cut to HISTORY_MAX.
	 *
	 * @param array $was Saved row.
	 * @return array<int,array{msgstr:string,at:string,by:int}>
	 */
	private function pushed( array $was ): array {
		$list = $was['history'];
		array_unshift(
			$list,
			array(
				'msgstr' => (string) $was['msgstr'],
				'at'     => (string) $was['modified_gmt'],
				'by'     => (int) $was['modified_by'],
			)
		);
		return array_slice( $list, 0, Babilim_Overrides::HISTORY_MAX );
	}

	/**
	 * Replaces a string's rows; puts the old ones back when one cannot be saved.
	 *
	 * @param array   $scope Scope.
	 * @param string  $ref   String reference.
	 * @param array[] $old   Rows now saved.
	 * @param array[] $new   Rows to save.
	 */
	private function store( array $scope, string $ref, array $old, array $new ): bool {
		// delete() removes every form, so only a shrinking string needs it.
		if ( count( $old ) > count( $new ) && ! $this->overrides->delete( $scope, $ref ) ) {
			return false;
		}
		foreach ( $new as $row ) {
			if ( ! $this->overrides->put( $scope, $row ) ) {
				$this->overrides->delete( $scope, $ref );
				foreach ( $old as $o ) {
					$this->overrides->put( $scope, $o );
				}
				return false;
			}
		}
		return true;
	}

	/**
	 * Rebuilds the files and reports whether the change is live.
	 *
	 * @param array $scope Scope.
	 * @param bool  $skip  Nothing changed: do not rebuild.
	 */
	private function written( array $scope, bool $skip ): Babilim_Result {
		// Nothing changed: no rebuild, but a write that failed earlier is still waiting.
		$live = $skip ? ! $this->writer->is_dirty( $scope ) : $this->writer->regenerate( $scope )->is_ok();
		return Babilim_Result::ok(
			array(
				'live'   => $live,
				'reason' => $live ? '' : __( 'The translation file could not be written yet. It is tried again automatically every day.', 'babilim' ),
			)
		);
	}

	private function not_saved(): Babilim_Result {
		return Babilim_Result::error( 'not_saved', __( 'The translation could not be saved.', 'babilim' ) );
	}

	/**
	 * One list item.
	 *
	 * @param string $ref String reference.
	 * @param array  $L Catalog and override rows.
	 * @param array  $problems Draft checks from the counting pass.
	 */
	private function item( string $ref, array $L, array $problems ): array {
		$entry = $L['entries'][ $ref ] ?? null;
		$saved = $L['saved'][ $ref ] ?? array();
		$draft = $L['drafts'][ $ref ] ?? null;
		$plural = self::item_plural( $entry, $saved, $draft );
		if ( isset( $L['orphan'][ $ref ] ) ) {
			$draft = null; // The stored draft is hidden while the string is read-only.
		}
		$from = $entry ?? $saved[0];
		$item = array(
			'ref'          => $ref,
			'msgctxt'      => (string) $from['msgctxt'],
			'msgid'        => (string) $from['msgid'],
			'msgid_plural' => $plural,
			'comments'     => array_values( (array) ( $entry['comments'] ?? array() ) ),
			'references'   => array_values( (array) ( $entry['references'] ?? array() ) ),
			'source'       => (string) ( $entry['source'] ?? 'none' ),
			'current'      => array_values( (array) ( $entry['msgstr'] ?? array() ) ),
			'override'     => array() === $saved ? null : array(
				'msgstr' => self::forms( $saved ),
				'status' => self::list_status( $L, $ref ),
				'at'     => (string) $saved[0]['modified_gmt'],
			),
			'draft'        => null === $draft ? null : self::forms( $draft ),
			'draft_at'     => null === $draft ? '' : (string) $draft[0]['modified_gmt'],
			'problems'     => $problems,
			'history'      => array() === $saved ? 0 : count( $saved[0]['history'] ),
		);
		if ( isset( $L['review'][ $ref ] ) ) {
			$item['review'] = $L['review'][ $ref ];
		}
		return $item;
	}

	/**
	 * Whether the list does not show a string, so its draft is neither counted nor published.
	 *
	 * @param array  $L   load().
	 * @param string $ref String reference.
	 */
	private static function hidden( array $L, string $ref ): bool {
		return ( ! isset( $L['entries'][ $ref ] ) && ! isset( $L['saved'][ $ref ] ) ) || isset( $L['folded'][ $ref ] ) || isset( $L['orphan'][ $ref ] );
	}

	private static function list_status( array $L, string $ref ): string {
		$status = (string) ( $L['saved'][ $ref ][0]['status'] ?? '' );
		if ( isset( $L['entries'][ $ref ] ) && in_array( $status, array( Babilim_Overrides::STATUS_REVIEW, Babilim_Overrides::STATUS_ORPHAN ), true ) ) {
			// A marked string listed again by a reinstalled build is live.
			return Babilim_Overrides::STATUS_ACTIVE;
		}
		return isset( $L['orphan'][ $ref ] ) ? Babilim_Overrides::STATUS_ORPHAN : $status;
	}

	private static function item_plural( ?array $entry, array $saved, ?array $draft ): string {
		$plural = (string) ( $entry['msgid_plural'] ?? '' );
		return '' !== $plural ? $plural : (string) ( $saved[0]['msgid_plural'] ?? $draft[0]['msgid_plural'] ?? '' );
	}

	private static function matches( string $needle, ?array $entry, array $saved, ?array $draft, bool $orphan ): bool {
		$from = $entry ?? $saved[0];
		$fields = array(
			array( $from['msgid'], self::item_plural( $entry, $saved, $draft ), $from['msgctxt'] ),
			$entry['comments'] ?? array(),
			$entry['references'] ?? array(),
			$entry['msgstr'] ?? array(),
			self::forms( $saved ),
			$orphan || null === $draft ? array() : self::forms( $draft )
		);
		foreach ( $fields as $field ) {
			foreach ( $field as $text ) {
				if ( false !== strpos( self::lower( (string) $text ), $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Same folding as Babilim_Translation_Search::lower(). */
	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * @param array[] $rows Rows of one string.
	 * @return string[]
	 */
	private static function forms( array $rows ): array {
		return array_values(
			array_map(
				static function ( array $r ): string {
					return (string) $r['msgstr'];
				},
				$rows
			)
		);
	}

	/**
	 * Rows grouped per string, each group ordered by plural index.
	 *
	 * @param array[] $rows Rows.
	 * @return array<string,array[]>
	 */
	private function group( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row['ref'] ][ (int) $row['plural_index'] ] = $row;
		}
		foreach ( $out as $ref => $by_index ) {
			ksort( $by_index );
			$out[ $ref ] = array_values( $by_index );
		}
		return $out;
	}

	/**
	 * @param array[] $rows Rows.
	 * @param string  $ref  String reference.
	 * @return array[]
	 */
	private function by_ref( array $rows, string $ref ): array {
		return $this->group( $rows )[ $ref ] ?? array();
	}

	/**
	 * @return array[] Components of kind plugin or theme.
	 */
	private function all(): array {
		return array_values(
			array_filter(
				(array) call_user_func( $this->components ),
				static function ( $c ): bool {
					return is_array( $c ) && in_array( $c['kind'] ?? null, array( 'plugin', 'theme' ), true ) && is_string( $c['slug'] ?? null );
				}
			)
		);
	}

	private function component( string $kind, string $slug ): ?array {
		foreach ( $this->all() as $c ) {
			if ( $c['kind'] === $kind && $c['slug'] === $slug ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * @param array $c Component.
	 * @return string[] Its own text domain first, then the extra ones.
	 */
	private static function domains( array $c ): array {
		return array_values( array_unique( array_filter( array_merge( array( (string) ( $c['text_domain'] ?? '' ) ), array_map( 'strval', (array) ( $c['extra_domains'] ?? array() ) ) ), static function ( string $d ): bool {
			return '' !== $d;
		} ) ) );
	}
}
