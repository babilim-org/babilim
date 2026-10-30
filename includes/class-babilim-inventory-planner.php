<?php
/**
 * Splits one site's inventory into requests the service will accept.
 *
 * The service limits an inventory request to 500 components, 256 unique
 * locales, 20000 components x locales cells and a 2 MB body. Every
 * request is judged on its own and a component missing from a request is
 * not treated as removed, so the component list can be sent in several
 * batches. The locale list cannot: each request replaces the site's
 * stored locale list, so every batch repeats the same locales, capped at
 * 256 with the owner's own choices kept first.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure planner: no WordPress calls, no requests.
 */
final class Babilim_Inventory_Planner {

	public const MAX_COMPONENTS = 500;
	public const MAX_LOCALES    = 256;
	public const MAX_CELLS      = 20000;

	/**
	 * Body budget per request, a little under the service's 2 MB limit so
	 * the envelope around the components (and any client events on the
	 * first batch) never tips a full batch over it.
	 */
	public const BODY_BUDGET = 1900000;

	/**
	 * Plans the requests for one inventory.
	 *
	 * @param array[]  $components  Request components, in send order.
	 * @param string[] $locales     Every locale in play.
	 * @param string[] $priority    Locales to keep first when the list must be cut.
	 * @param int      $body_budget Largest body, in bytes, one request may have.
	 * @return array{locales:string[],batches:array[],omitted_components:int,omitted_locales:int}
	 */
	public static function plan( array $components, array $locales, array $priority = array(), int $body_budget = self::BODY_BUDGET ): array {
		$locales = array_values( array_unique( array_map( 'strval', $locales ) ) );
		$kept    = array();
		foreach ( array_unique( array_map( 'strval', $priority ) ) as $wanted ) {
			if ( in_array( $wanted, $locales, true ) ) {
				$kept[] = $wanted;
			}
		}
		$kept = array_slice( $kept, 0, self::MAX_LOCALES );
		foreach ( $locales as $locale ) {
			if ( count( $kept ) >= self::MAX_LOCALES ) {
				break;
			}
			if ( ! in_array( $locale, $kept, true ) ) {
				$kept[] = $locale;
			}
		}
		// Keep the incoming (sorted) order rather than priority-first
		// order, so the request is stable from one sync to the next.
		$kept_lookup    = array_flip( $kept );
		$send_locales   = array();
		foreach ( $locales as $locale ) {
			if ( isset( $kept_lookup[ $locale ] ) ) {
				$send_locales[] = $locale;
			}
		}
		$omitted_locales = count( $locales ) - count( $send_locales );

		$result = array(
			'locales'            => $send_locales,
			'batches'            => array(),
			'omitted_components' => 0,
			'omitted_locales'    => $omitted_locales,
		);
		if ( array() === $send_locales ) {
			return $result;
		}

		$max_by_cells = max( 1, intdiv( self::MAX_CELLS, count( $send_locales ) ) );
		$max_count    = min( self::MAX_COMPONENTS, $max_by_cells );
		$envelope     = strlen( (string) wp_json_encode( array( 'components' => array(), 'locales' => $send_locales ) ) );

		if ( array() === $components ) {
			// A site with nothing to report still reports (the answer
			// carries its license and quota): one request, no components.
			$result['batches'][] = array();
			return $result;
		}

		$batch = array();
		$bytes = $envelope;
		foreach ( $components as $component ) {
			$size = strlen( (string) wp_json_encode( $component ) ) + 1;
			if ( $envelope + $size > $body_budget ) {
				// Too big for any request, alone.
				++$result['omitted_components'];
				continue;
			}
			if ( array() !== $batch && ( count( $batch ) >= $max_count || $bytes + $size > $body_budget ) ) {
				$result['batches'][] = $batch;
				$batch               = array();
				$bytes               = $envelope;
			}
			$batch[] = $component;
			$bytes  += $size;
		}
		if ( array() !== $batch ) {
			$result['batches'][] = $batch;
		}
		return $result;
	}
}
