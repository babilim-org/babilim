<?php
/**
 * Bulk actions of the translations list: which (component, language)
 * operations a selection expands to, and running one batch of them.
 *
 * Nothing here stores a record or uses a transient: the operations a
 * run did not get to are handed back to the caller, which puts them in
 * the continue form of the result.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers for the bulk download / update / request actions.
 */
final class Babilim_Bulk {

	/** Seconds one run may take in total. */
	const BUDGET_SECONDS = 45;

	/** Longest any single HTTP request of a run may take. */
	const HTTP_TIMEOUT = 8;

	/** Most downloads or updates one run starts. */
	const CHUNK_DOWNLOAD = 2;

	/** Most requests one run sends. */
	const CHUNK_REQUEST = 5;

	/** Seconds a download may need in the worst case: two requests plus the install. */
	const OP_WORST_DOWNLOAD = 18;

	/** Seconds a request may need in the worst case: one request. */
	const OP_WORST_REQUEST = 8;

	/** Most operations a single selection (or continue form) may hold. */
	const MAX_SELECTION_OPS = 50;

	/**
	 * Expands a selection into operations, from the current list rows.
	 *
	 * @param array[]  $items         Rows from Babilim_Translations_Table::build_items().
	 * @param string   $op            download|update|request.
	 * @param string[] $selected      Selected components, each `kind:slug`.
	 * @param string   $locale_filter A locale code, or '' for every language.
	 * @return array<int,array{0:string,1:string,2:string}> [kind, slug, locale] triples.
	 */
	public static function expand( array $items, string $op, array $selected, string $locale_filter ): array {
		$ops = array();
		foreach ( $items as $item ) {
			if ( ! in_array( $item['kind'] . ':' . $item['slug'], $selected, true ) ) {
				continue;
			}
			foreach ( $item['cells'] as $locale => $cell ) {
				$locale = (string) $locale;
				if ( '' !== $locale_filter && $locale !== $locale_filter ) {
					continue;
				}
				if ( self::cell_matches( $cell, $op ) ) {
					$ops[] = array( (string) $item['kind'], (string) $item['slug'], $locale );
				}
			}
		}
		return $ops;
	}

	/**
	 * Whether a cell is one that an operation applies to.
	 *
	 * @param array  $cell Cell from Babilim_Cell::from_entry().
	 * @param string $op   download|update|request.
	 * @return bool
	 */
	private static function cell_matches( array $cell, string $op ): bool {
		switch ( $op ) {
			case 'download':
				return 'babilim_download' === $cell['action'] && ! $cell['is_update'];
			case 'update':
				return (bool) $cell['is_update'];
			case 'request':
				return 'babilim_request' === $cell['action'];
		}
		return false;
	}

	/**
	 * Runs one batch.
	 *
	 * An operation starts only when the budget still holds its worst
	 * case, and no more than one chunk is attempted. What was not
	 * attempted comes back in `untried` (never a failed one: a failure
	 * is retried from its own button in the list).
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $ops      Operations to run.
	 * @param string                                       $op       download|update|request.
	 * @param Babilim_Deadline                             $deadline The run's time budget.
	 * @param callable                                     $execute  fn( kind, slug, locale, timeout ): Babilim_Result.
	 * @param Babilim_State                                $state    Where a request's new status is recorded.
	 * @return array{ok:int,skipped:int,failed:array<int,array{0:string,1:string}>,untried:string[]}
	 */
	public static function run( array $ops, string $op, Babilim_Deadline $deadline, callable $execute, Babilim_State $state ): array {
		$is_request = 'request' === $op;
		$chunk      = $is_request ? self::CHUNK_REQUEST : self::CHUNK_DOWNLOAD;
		$worst      = $is_request ? self::OP_WORST_REQUEST : self::OP_WORST_DOWNLOAD;
		$out        = array(
			'ok'      => 0,
			'skipped' => 0,
			'failed'  => array(),
			'untried' => array(),
		);
		$attempted  = 0;
		$stopped    = false;
		foreach ( $ops as $triple ) {
			list( $kind, $slug, $locale ) = $triple;
			$key                          = $kind . ':' . $slug . ':' . $locale;
			if ( $stopped || $attempted >= $chunk || ! $deadline->can_start( $worst ) ) {
				$stopped            = true;
				$out['untried'][]   = $key;
				continue;
			}
			++$attempted;
			$entry     = $state->status( $kind, $slug, $locale );
			$installed = 'babilim_installed' === (string) ( $entry['status'] ?? '' );
			$result    = $execute( $kind, $slug, $locale, $deadline->step( self::HTTP_TIMEOUT ) );
			if ( $result->is_error() ) {
				$out['failed'][] = array( $key, $result->message() );
				continue;
			}
			if ( $result->get( 'already_installed' ) ) {
				++$out['skipped'];
				continue;
			}
			++$out['ok'];
			if ( $is_request ) {
				$state->record_request_answer( $kind, $slug, $locale, $installed, $result );
			}
		}
		return $out;
	}
}
