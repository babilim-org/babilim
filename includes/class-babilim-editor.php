<?php
/**
 * The translation editor's service: lists a component's strings with what
 * each currently shows, saves a site owner's edit as an override row,
 * keeps drafts and the last HISTORY_MAX versions, and switches an override
 * off again.
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
 * Nothing here talks to the service or makes an HTTP request.
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

	const PER_PAGE = 50;
	const FILTERS  = array( 'all', 'untranslated', 'edited', 'review', 'orphan', 'babilim', 'wporg', 'bundled' );

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

	public function __construct( Babilim_Overrides $overrides, Babilim_String_Catalog $catalog, Babilim_Override_Writer $writer, callable $components, callable $locales, callable $now, callable $user_id ) {
		$this->overrides  = $overrides;
		$this->catalog    = $catalog;
		$this->writer     = $writer;
		$this->components = $components;
		$this->locales    = $locales;
		$this->now        = $now;
		$this->user_id    = $user_id;
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
	 * One page of the string list.
	 *
	 * @param array $scope Scope.
	 * @param array $query Keys filter, search, page, ref.
	 * @return array{total:int,pages:int,page:int,nplurals:int,live:bool,no_source:bool,items:array[]}
	 */
	public function strings( array $scope, array $query ): array {
		$c       = $this->component( (string) $scope['kind'], (string) $scope['slug'] );
		$cat     = null === $c ? array() : $this->catalog->load( $c, (string) $scope['domain'], (string) $scope['locale'] );
		$entries = (array) ( $cat['entries'] ?? array() );
		$ready   = $this->overrides->ready(); // No table: the catalog alone, no SELECT.
		$saved   = $ready ? $this->group( $this->overrides->rows( $scope ) ) : array();
		$drafts  = $ready ? $this->group( $this->overrides->rows( $scope, true ) ) : array();
		$ref     = (string) ( $query['ref'] ?? '' );
		$filter  = in_array( $query['filter'] ?? '', self::FILTERS, true ) ? (string) $query['filter'] : 'all';
		$needle  = self::lower( trim( (string) ( $query['search'] ?? '' ) ) );

		$items = array();
		foreach ( array_keys( $entries + $saved ) as $r ) {
			if ( '' !== $ref && $r !== $ref ) {
				continue;
			}
			$item = $this->item( (string) $r, $entries[ $r ] ?? null, $saved[ $r ] ?? array(), $drafts[ $r ] ?? null );
			if ( '' === $ref && ( ! self::in_filter( $filter, $item ) || ( '' !== $needle && ! self::matches( $needle, $item ) ) ) ) {
				continue;
			}
			$items[] = $item;
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return strcmp( $a['msgid'], $b['msgid'] ) ?: strcmp( $a['msgctxt'], $b['msgctxt'] );
			}
		);

		$total = count( $items );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $pages, max( 1, (int) ( $query['page'] ?? 1 ) ) );
		return array(
			'total'     => $total,
			'pages'     => $pages,
			'page'      => $page,
			'nplurals'  => Babilim_Plural_Forms::nplurals( (string) ( $cat['plural_forms'] ?? Babilim_Plural_Forms::header( (string) $scope['locale'] ) ) ),
			'live'      => ! $this->writer->is_dirty( $scope ),
			'no_source' => array() === ( $cat['sources'] ?? array() ),
			'items'     => array_slice( $items, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ),
		);
	}

	/**
	 * Saves a draft: no checks, never written to the files.
	 *
	 * @param array    $scope  Scope.
	 * @param string   $ref    String reference.
	 * @param string[] $msgstr Forms.
	 */
	public function save_draft( array $scope, string $ref, array $msgstr ): Babilim_Result {
		$ctx = $this->context( $scope, $ref );
		if ( $ctx instanceof Babilim_Result ) {
			return $ctx;
		}
		$rows = array();
		foreach ( array_values( array_map( 'strval', $msgstr ) ) as $i => $form ) {
			$rows[] = $this->row( $ctx, $i, $form, array() ) + array( 'status' => Babilim_Overrides::STATUS_ACTIVE );
		}
		$old = $this->overrides->rows( $scope, true );
		if ( ! $this->overrides->delete( $scope, $ref, true ) ) {
			return $this->not_saved();
		}
		foreach ( $rows as $row ) {
			if ( ! $this->overrides->put( $scope, $row, true ) ) {
				$this->overrides->delete( $scope, $ref, true );
				foreach ( $this->by_ref( $old, $ref ) as $o ) {
					$this->overrides->put( $scope, $o, true );
				}
				return $this->not_saved();
			}
		}
		return Babilim_Result::ok();
	}

	/**
	 * Saves a translation: checks it, writes one row per plural form and
	 * rebuilds the files. A text that fails the checks is kept as a draft.
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
			$this->save_draft( $scope, $ref, $msgstr );
			return Babilim_Result::error( 'check_failed', __( 'The translation did not pass the checks, so it was not saved. Your text is kept as a draft.', 'babilim' ), array( 'problems' => $problems ) );
		}

		$entry = $ctx['entry'];
		$build = null !== $entry && 'babilim' === $entry['source'] ? (string) $entry['build_id'] : '';
		$base  = array() === $old || 'editor' !== $old[0]['origin'] || $build !== $old[0]['base_build'];
		$rows  = array();
		foreach ( $msgstr as $i => $form ) {
			$was = $old[ $i ] ?? null;
			$row = $this->row( $ctx, $i, $form, $was ?? array() ) + array( 'status' => Babilim_Overrides::STATUS_ACTIVE );
			if ( $base ) {
				$row['base_source'] = null === $entry || 'none' === $entry['source'] ? '' : (string) $entry['source'];
				$row['base_build']  = $build;
				$row['base_msgstr'] = null === $entry ? '' : (string) ( $entry['msgstr'][ $i ] ?? '' );
			} elseif ( null !== $was ) {
				$row['base_source'] = $was['base_source'];
				$row['base_build']  = $was['base_build'];
				$row['base_msgstr'] = $was['base_msgstr'];
			}
			// A switched-off override already has its text in the history.
			$row['history'] = null === $was ? array() : ( Babilim_Overrides::STATUS_OFF === $was['status'] ? $was['history'] : $this->pushed( $was ) );
			$rows[]         = $row;
		}
		if ( ! $this->store( $scope, $ref, $old, $rows ) ) {
			return $this->not_saved();
		}
		$this->overrides->delete( $scope, $ref, true );
		return $this->written( $scope, false );
	}

	/**
	 * Goes back to the original text (the override is switched off) or to
	 * a saved version through the same checks as a save.
	 *
	 * @param array  $scope Scope.
	 * @param string $ref   String reference.
	 * @param string $to    'original' or an index of history().
	 */
	public function revert( array $scope, string $ref, string $to ): Babilim_Result {
		$ctx = $this->context( $scope, $ref );
		if ( $ctx instanceof Babilim_Result ) {
			return $ctx;
		}
		if ( 'original' !== $to ) {
			$history = $this->history( $scope, $ref );
			if ( 1 !== preg_match( '/^\d{1,3}$/D', $to ) || ! isset( $history[ (int) $to ] ) ) {
				return Babilim_Result::error( 'unknown_version', __( 'That version no longer exists.', 'babilim' ) );
			}
			return $this->save( $scope, $ref, $history[ (int) $to ]['msgstr'] );
		}
		$old = $ctx['saved'];
		if ( array() === $old || array() === array_filter(
			$old,
			static function ( array $r ): bool {
				return Babilim_Overrides::STATUS_OFF !== $r['status'];
			}
		) ) {
			$this->overrides->delete( $scope, $ref, true );
			return $this->written( $scope, true );
		}
		$rows = array();
		foreach ( $old as $was ) {
			$row                 = $was;
			$row['history']      = Babilim_Overrides::STATUS_OFF === $was['status'] ? $was['history'] : $this->pushed( $was );
			$row['status']       = Babilim_Overrides::STATUS_OFF;
			$row['modified_gmt'] = (string) call_user_func( $this->now );
			$row['modified_by']  = (int) call_user_func( $this->user_id );
			$rows[]              = $row;
		}
		if ( ! $this->store( $scope, $ref, $old, $rows ) ) {
			return $this->not_saved();
		}
		// As after a save: a stored draft must not come back over the original text.
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
	 * Everything a write needs, or the refusal.
	 *
	 * @param array  $scope Scope.
	 * @param string $ref   String reference.
	 * @return Babilim_Result|array{entry:?array,saved:array[],component:array,msgctxt:string,msgid:string,msgid_plural:string,nplurals:int}
	 */
	private function context( array $scope, string $ref ) {
		if ( ! $this->overrides->ready() ) {
			return Babilim_Result::error( 'no_table', __( 'The table that stores edited translations does not exist yet.', 'babilim' ) );
		}
		$c = $this->component( (string) $scope['kind'], (string) $scope['slug'] );
		if ( null === $c ) {
			return Babilim_Result::error( 'unknown_component', __( 'That plugin or theme is not installed.', 'babilim' ) );
		}
		$cat   = $this->catalog->load( $c, (string) $scope['domain'], (string) $scope['locale'] );
		$entry = $cat['entries'][ $ref ] ?? null;
		$saved = $this->by_ref( $this->overrides->rows( $scope ), $ref );
		$any   = $saved[0] ?? ( $this->by_ref( $this->overrides->rows( $scope, true ), $ref )[0] ?? null );
		if ( null === $entry && null === $any ) {
			return Babilim_Result::error( 'unknown_string', __( 'That string is not in this translation.', 'babilim' ) );
		}
		$from = $entry ?? $any;
		return array(
			'entry'        => $entry,
			'saved'        => $saved,
			'component'    => $c,
			'ref'          => $ref,
			'msgctxt'      => (string) $from['msgctxt'],
			'msgid'        => (string) $from['msgid'],
			'msgid_plural' => '' !== (string) ( $entry['msgid_plural'] ?? '' ) ? (string) $entry['msgid_plural'] : (string) ( $any['msgid_plural'] ?? '' ),
			'nplurals'     => Babilim_Plural_Forms::nplurals( (string) $cat['plural_forms'] ),
		);
	}

	/**
	 * A row of one string for plural index $i; keeps what an existing row
	 * carries that the editor does not change.
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
			'component_version' => (string) ( $ctx['component']['version'] ?? '' ),
			'match_ref'         => (string) ( $was['match_ref'] ?? '' ),
			'feedback_build'    => (string) ( $was['feedback_build'] ?? '' ),
			'modified_gmt'      => (string) call_user_func( $this->now ),
			'modified_by'       => (int) call_user_func( $this->user_id ),
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
	 * @param string     $ref   String reference.
	 * @param array|null $entry Catalog entry.
	 * @param array[]    $saved Saved rows of the string.
	 * @param array[]|null $draft Draft rows of the string.
	 */
	private function item( string $ref, ?array $entry, array $saved, ?array $draft ): array {
		$from = $entry ?? $saved[0];
		return array(
			'ref'          => $ref,
			'msgctxt'      => (string) $from['msgctxt'],
			'msgid'        => (string) $from['msgid'],
			'msgid_plural' => '' !== (string) ( $entry['msgid_plural'] ?? '' ) ? (string) $entry['msgid_plural'] : (string) ( $saved[0]['msgid_plural'] ?? '' ),
			'comments'     => array_values( (array) ( $entry['comments'] ?? array() ) ),
			'source'       => (string) ( $entry['source'] ?? 'none' ),
			'current'      => array_values( (array) ( $entry['msgstr'] ?? array() ) ),
			'override'     => array() === $saved ? null : array(
				'msgstr' => self::forms( $saved ),
				'status' => (string) $saved[0]['status'],
			),
			'draft'        => null === $draft ? null : self::forms( $draft ),
			'history'      => array() === $saved ? 0 : count( $saved[0]['history'] ),
		);
	}

	private static function in_filter( string $filter, array $item ): bool {
		$live = null !== $item['override'] && in_array( $item['override']['status'], array( Babilim_Overrides::STATUS_ACTIVE, Babilim_Overrides::STATUS_REVIEW, Babilim_Overrides::STATUS_ORPHAN ), true );
		switch ( $filter ) {
			case 'untranslated':
				return 'none' === $item['source'] && ! $live;
			case 'edited':
				return $live;
			case 'babilim':
			case 'wporg':
			case 'bundled':
				return $filter === $item['source'];
			case 'review':
			case 'orphan':
				return false; // Filled by the review flow.
		}
		return true;
	}

	private static function matches( string $needle, array $item ): bool {
		$hay = array_merge(
			array( $item['msgid'], $item['msgid_plural'] ),
			$item['current'],
			null === $item['override'] ? array() : $item['override']['msgstr'],
			(array) $item['draft']
		);
		foreach ( $hay as $text ) {
			if ( false !== strpos( self::lower( (string) $text ), $needle ) ) {
				return true;
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
