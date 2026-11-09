<?php
/**
 * A wall-clock budget for one run of work that makes several requests.
 *
 * The remaining time is recomputed from the clock on every call, never
 * cached, so each request is given only what is genuinely left at the
 * moment it is sent. A null budget means "no overall limit": remaining()
 * is null, every step may start, and step() still applies the caller's
 * own per-step cap.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks how much of a time budget is left.
 */
final class Babilim_Deadline {

	/**
	 * Unix time the budget ends, or null for no limit.
	 *
	 * @var int|null
	 */
	private $ends_at;

	/**
	 * @param int|null $budget Seconds available from now, or null for unlimited.
	 */
	public function __construct( ?int $budget ) {
		$this->ends_at = null === $budget ? null : self::now() + max( 0, $budget );
	}

	/**
	 * Whole seconds left (never negative), or null when unlimited.
	 *
	 * @return int|null
	 */
	public function remaining(): ?int {
		if ( null === $this->ends_at ) {
			return null;
		}
		return max( 0, $this->ends_at - self::now() );
	}

	/**
	 * The timeout to give one request: $cap, cut down to what is left.
	 *
	 * @param int $cap The most this step may take.
	 * @return int
	 */
	public function step( int $cap ): int {
		$remaining = $this->remaining();
		return null === $remaining ? $cap : min( $cap, $remaining );
	}

	/**
	 * Whether a step needing at least $min seconds may still start.
	 *
	 * @param int $min Smallest useful timeout.
	 * @return bool
	 */
	public function can_start( int $min ): bool {
		$remaining = $this->remaining();
		return null === $remaining || $remaining >= $min;
	}

	/**
	 * The current Unix time; the `babilim_now` filter lets a test move it.
	 *
	 * @return int
	 */
	private static function now(): int {
		return (int) apply_filters( 'babilim_now', time() );
	}
}
