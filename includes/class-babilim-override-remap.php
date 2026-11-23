<?php
/**
 * Carries saved overrides over to a new component version. After a plugin or
 * theme update each saved override is looked up in the string list of the
 * installed version: still listed -> `active`; gone -> `review` when a similar
 * string of the same context exists (match_ref points at it), else `orphan`.
 * Only status, match_ref and component_version change: the text, its history
 * and the generated custom/ files stay as they are (all three statuses stay in
 * the files). Drafts and switched-off rows are never touched. When the string
 * list of the installed version cannot be read, nothing changes (an override
 * stays in force rather than being judged against a list that may be missing).
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Babilim_Override_Remap {

	/** Minimum similarity (Dice coefficient of character trigrams) for a `review` match. */
	const THRESHOLD = 0.7;

	/** Cron event that runs run_all(). */
	const HOOK = 'babilim_remap_overrides';

	/**
	 * Dice = 2m / (a + b) with m <= min(a, b) cannot reach THRESHOLD when
	 * min / max < THRESHOLD / (2 - THRESHOLD) = 7/13.
	 */
	const SIZE_RATIO = 7 / 13;

	/** @var Babilim_Overrides */
	private $overrides;

	/** @var Babilim_String_Catalog */
	private $catalog;

	/** @var callable fn(): array -- Babilim_Inventory::components(). */
	private $components;

	/**
	 * @param Babilim_Overrides      $overrides  Override store.
	 * @param Babilim_String_Catalog $catalog    String lists.
	 * @param callable               $components fn(): array -- Babilim_Inventory::components().
	 */
	public function __construct( Babilim_Overrides $overrides, Babilim_String_Catalog $catalog, callable $components ) {
		$this->overrides  = $overrides;
		$this->catalog    = $catalog;
		$this->components = $components;
	}

	/**
	 * Remaps every scope that has saved rows.
	 *
	 * @return int Scopes processed (not skipped).
	 */
	public function run_all(): int {
		if ( ! $this->overrides->ready() ) {
			return 0;
		}
		$done = 0;
		foreach ( $this->overrides->scopes() as $scope ) {
			if ( '' === $this->run( $scope )['skipped'] ) {
				++$done;
			}
		}
		return $done;
	}

	/**
	 * Remaps one scope.
	 *
	 * @param array $scope Scope.
	 * @return array{changed:int,skipped:string} Strings changed; why nothing was done ('', 'not_installed', 'no_current_list', 'up_to_date').
	 */
	public function run( array $scope ): array {
		$component = null;
		foreach ( (array) call_user_func( $this->components ) as $c ) {
			if ( ( $c['kind'] ?? null ) === ( $scope['kind'] ?? '' ) && ( $c['slug'] ?? null ) === ( $scope['slug'] ?? '' ) ) {
				$component = $c;
				break;
			}
		}
		if ( null === $component ) {
			return array( 'changed' => 0, 'skipped' => 'not_installed' );
		}
		$version = (string) ( $component['version'] ?? '' );

		// Strings by ref, plural forms together; switched-off rows are left alone.
		$groups = array();
		$stale  = false;
		foreach ( $this->overrides->rows( $scope ) as $row ) {
			if ( Babilim_Overrides::STATUS_OFF === $row['status'] ) {
				continue;
			}
			$groups[ $row['ref'] ][] = $row;
			$stale                   = $stale || $row['component_version'] !== $version;
		}
		if ( ! $stale ) {
			return array( 'changed' => 0, 'skipped' => 'up_to_date' );
		}

		$cat = $this->catalog->load( $component, (string) $scope['domain'], (string) $scope['locale'] );
		if ( ! $cat['current'] || array() === $cat['entries'] ) {
			return array( 'changed' => 0, 'skipped' => 'no_current_list' );
		}
		$entries = $cat['entries'];

		// Decide first (slow), by ref: only strings with a row not yet on this version are looked at.
		$pool = null; // Candidates by context, built when the first string is gone.
		$plan = array();
		foreach ( $groups as $ref => $rows ) {
			if ( array() === array_filter( $rows, static function ( array $r ) use ( $version ): bool {
				return $r['component_version'] !== $version;
			} ) ) {
				continue;
			}
			$status = Babilim_Overrides::STATUS_ACTIVE;
			$match  = '';
			if ( ! isset( $entries[ $ref ] ) ) {
				$pool = $pool ?? self::pool( $entries, $groups );
				$best = self::best( $pool[ (string) $rows[0]['msgctxt'] ] ?? array(), (string) $rows[0]['msgid'] );
				if ( '' !== $best ) {
					$status = Babilim_Overrides::STATUS_REVIEW;
					$match  = $best;
				} else {
					$status = Babilim_Overrides::STATUS_ORPHAN;
				}
			}
			$plan[ $ref ] = array( $status, $match );
		}

		// Write against the rows as they are now: the decision took a while, and the
		// editor may have saved or removed a row meanwhile. Only these three columns
		// are written (mark()), so a Save landing after this read keeps its text.
		$changed = array();
		foreach ( $this->overrides->rows( $scope ) as $row ) {
			if ( Babilim_Overrides::STATUS_OFF === $row['status'] || ! isset( $plan[ $row['ref'] ] ) ) {
				continue;
			}
			list( $status, $match ) = $plan[ $row['ref'] ];
			if ( $row['status'] === $status && $row['match_ref'] === $match && $row['component_version'] === $version ) {
				continue;
			}
			$row['status']            = $status;
			$row['match_ref']         = $match;
			$row['component_version'] = $version;
			if ( $this->overrides->mark( $scope, $row ) ) {
				$changed[ $row['ref'] ] = true;
			}
		}
		return array( 'changed' => count( $changed ), 'skipped' => '' );
	}

	/**
	 * Similarity of two source strings, 0..1: Dice coefficient of their
	 * character trigrams, after NFC, lower-casing and whitespace folding.
	 *
	 * @param string $a One string.
	 * @param string $b Another.
	 */
	public static function similarity( string $a, string $b ): float {
		$ga = self::grams( $a );
		$gb = self::grams( $b );
		return self::dice( $ga, $gb );
	}

	/**
	 * @param array{0:array<string,int>,1:int} $a Grams of one string.
	 * @param array{0:array<string,int>,1:int} $b Grams of another.
	 */
	private static function dice( array $a, array $b ): float {
		if ( 0 === $a[1] || 0 === $b[1] ) {
			return 0.0;
		}
		if ( min( $a[1], $b[1] ) < self::SIZE_RATIO * max( $a[1], $b[1] ) ) {
			return 0.0;
		}
		list( $small, $large ) = count( $a[0] ) <= count( $b[0] ) ? array( $a[0], $b[0] ) : array( $b[0], $a[0] );
		$common                = 0;
		foreach ( $small as $g => $n ) {
			if ( isset( $large[ $g ] ) ) {
				$common += min( $n, $large[ $g ] );
			}
		}
		return 2 * $common / ( $a[1] + $b[1] );
	}

	/**
	 * Character trigrams of the padded, normalised string, as a multiset.
	 *
	 * @param string $s String.
	 * @return array{0:array<string,int>,1:int} Gram => count, and the total number of grams (0 for an empty string).
	 */
	private static function grams( string $s ): array {
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_C );
			$s = false === $n ? $s : $n;
		}
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = preg_replace( '/\s+/u', ' ', $s ) ?? $s;
		$s = trim( $s );
		if ( '' === $s ) {
			return array( array(), 0 );
		}
		$chars = preg_split( '//u', ' ' . $s . ' ', -1, PREG_SPLIT_NO_EMPTY );
		$chars = false === $chars ? str_split( ' ' . $s . ' ' ) : $chars;
		$map   = array();
		$total = count( $chars ) - 2;
		for ( $i = 0; $i < $total; $i++ ) {
			$g         = $chars[ $i ] . $chars[ $i + 1 ] . $chars[ $i + 2 ];
			$map[ $g ] = ( $map[ $g ] ?? 0 ) + 1;
		}
		return array( $map, $total );
	}

	/**
	 * The listed strings that may be offered as a match, by msgctxt: those
	 * without a saved override of their own.
	 *
	 * @param array $entries Catalog entries by ref.
	 * @param array $groups  Saved rows by ref.
	 * @return array<string,array<int,array{0:string,1:array}>> msgctxt => list of [ ref, grams of the msgid ].
	 */
	private static function pool( array $entries, array $groups ): array {
		$pool = array();
		foreach ( $entries as $ref => $e ) {
			if ( ! isset( $groups[ $ref ] ) ) {
				$pool[ (string) $e['msgctxt'] ][] = array( (string) $ref, self::grams( (string) $e['msgid'] ) );
			}
		}
		return $pool;
	}

	/**
	 * The ref of the most similar candidate at or above THRESHOLD ('' when none); the first wins a tie.
	 *
	 * @param array  $candidates [ ref, grams ] pairs of one context.
	 * @param string $msgid      The vanished string.
	 */
	private static function best( array $candidates, string $msgid ): string {
		$from  = self::grams( $msgid );
		$best  = self::THRESHOLD;
		$found = '';
		foreach ( $candidates as $c ) {
			$score = self::dice( $from, $c[1] );
			if ( $score >= $best && ( '' === $found || $score > $best ) ) {
				$best  = $score;
				$found = $c[0];
			}
		}
		return $found;
	}
}
