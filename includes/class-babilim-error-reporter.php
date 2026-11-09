<?php
/**
 * Local queue of error events, gated by the site owner's consent.
 *
 * An event holds only catalog values, a random id and a timestamp. While
 * sharing is off, events wait (a few, newest kept) for the owner to
 * decide; nothing is queued for sending until the owner agrees, either
 * for one event or for all of them from then on. This class never sends
 * anything itself.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture, consent transitions and queue bookkeeping.
 */
final class Babilim_Error_Reporter {

	public const OPTION_SHARE    = 'babilim_share_errors';
	public const OPTION_AWAITING = 'babilim_error_awaiting';
	public const OPTION_QUEUE    = 'babilim_error_queue';

	public const MAX_AWAITING = 10;
	public const MAX_QUEUE    = 50;
	public const MAX_ATTEMPTS = 3;
	public const MAX_AGE      = 2592000; // 30 days.

	/** @var callable */
	private $get_option;

	/** @var callable */
	private $update_option;

	/** @var callable */
	private $now;

	/** @var bool Whether share_always() is running right now (any instance). */
	private static $sharing_always = false;

	/**
	 * @param callable $get_option    fn( string $name, $default ): mixed.
	 * @param callable $update_option fn( string $name, $value ): void.
	 * @param callable $now           fn(): int Unix time.
	 */
	public function __construct( callable $get_option, callable $update_option, callable $now ) {
		$this->get_option    = $get_option;
		$this->update_option = $update_option;
		$this->now           = $now;
	}

	/**
	 * The reporter wired to WordPress options (not autoloaded) and the clock.
	 */
	public static function for_site(): self {
		return new self(
			static function ( string $name, $default ) {
				return get_option( $name, $default );
			},
			static function ( string $name, $value ): void {
				update_option( $name, $value, false );
			},
			static function (): int {
				return time();
			}
		);
	}

	/**
	 * Result codes that are expected outcomes, not errors worth reporting:
	 * quota, authorization, rate limiting, verification, withdrawn consent,
	 * the owner cancelling, and the service's normal answers about a build
	 * (license needed, no free-plan slot, not available, plugin too old, capacity, busy). A `babilim_` prefix is ignored.
	 *
	 * @param string $code A result or WP_Error code.
	 */
	public static function is_excluded_code( string $code ): bool {
		$code = (string) preg_replace( '/^babilim_/', '', $code );
		// The installer's own stop and refusal codes come from one list
		// (Babilim_Sync_Installer); the rest are listed here.
		$own = array( 'unauthorized', 'no_consent', 'cancelled', 'canceled', 'not_available', 'plugin_outdated', 'capacity_exhausted', 'install_busy' );
		return in_array( $code, array_merge( $own, Babilim_Sync_Installer::STOP_CODES, Babilim_Sync_Installer::REFUSAL_CODES ), true );
	}

	/**
	 * Whether the owner has agreed to share all future error reports.
	 */
	public function sharing_enabled(): bool {
		return '1' === (string) $this->read( self::OPTION_SHARE, '0' );
	}

	/**
	 * Records one failure. Values outside the catalog are dropped. Never throws.
	 */
	public function capture( string $error_code, string $action, string $origin_id ): void {
		try {
			if ( ! Babilim_Error_Catalog::is_valid( $error_code, $action, $origin_id ) ) {
				return;
			}
			// The same failure repeating (a corrupt file hit on every page
			// load) is recorded once until that event is sent or dropped.
			// An entry that can no longer be sent does not count.
			$oldest = (int) call_user_func( $this->now ) - self::MAX_AGE;
			foreach ( array( self::OPTION_AWAITING, self::OPTION_QUEUE ) as $name ) {
				foreach ( $this->events( $name ) as $known ) {
					if ( $known['attempts'] >= self::MAX_ATTEMPTS || (int) strtotime( $known['at'] ) < $oldest ) {
						continue;
					}
					if ( ( $known['error_code'] ?? '' ) === $error_code && ( $known['action'] ?? '' ) === $action && ( $known['origin_id'] ?? '' ) === $origin_id ) {
						return;
					}
				}
			}
			$event = array(
				'event_id'   => self::new_id(),
				'error_code' => $error_code,
				'action'     => $action,
				'origin_id'  => $origin_id,
				'at'         => gmdate( 'c', (int) call_user_func( $this->now ) ),
				'attempts'   => 0,
			);
			if ( $this->sharing_enabled() ) {
				$this->write( self::OPTION_QUEUE, $this->push( $this->events( self::OPTION_QUEUE ), $event, self::MAX_QUEUE ) );
			} else {
				$this->write( self::OPTION_AWAITING, $this->push( $this->events( self::OPTION_AWAITING ), $event, self::MAX_AWAITING ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // A failed capture must never cause another failure.
		}
	}

	/**
	 * Events waiting for the owner's decision.
	 *
	 * @return array[]
	 */
	public function awaiting(): array {
		return $this->safe_events( self::OPTION_AWAITING );
	}

	/**
	 * Authorizes one waiting event.
	 */
	public function share_once( string $event_id ): bool {
		try {
			$waiting = $this->events( self::OPTION_AWAITING );
			$keep    = array();
			$moved   = null;
			foreach ( $waiting as $event ) {
				if ( null === $moved && $event['event_id'] === $event_id ) {
					$moved = $event;
				} else {
					$keep[] = $event;
				}
			}
			if ( null === $moved ) {
				return false;
			}
			$this->write( self::OPTION_QUEUE, $this->push( $this->events( self::OPTION_QUEUE ), $moved, self::MAX_QUEUE ) );
			$this->write( self::OPTION_AWAITING, $keep );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Turns sharing on and authorizes every event now waiting.
	 */
	public function share_always(): void {
		self::$sharing_always = true;
		try {
			$queue = $this->events( self::OPTION_QUEUE );
			foreach ( $this->events( self::OPTION_AWAITING ) as $event ) {
				$queue = $this->push( $queue, $event, self::MAX_QUEUE );
			}
			$this->write( self::OPTION_SHARE, '1' );
			$this->write( self::OPTION_QUEUE, $queue );
			$this->write( self::OPTION_AWAITING, array() );
		} catch ( \Throwable $e ) {
			unset( $e );
		} finally {
			self::$sharing_always = false;
		}
	}

	/**
	 * Whether share_always() is in progress -- so a hook on the share
	 * option it writes (see Babilim_Admin::on_share_errors_updated())
	 * does not run it again inside itself.
	 */
	public static function in_share_always(): bool {
		return self::$sharing_always;
	}

	/**
	 * The owner declined: forget the waiting events they were shown.
	 * Events that came in after the notice was drawn stay waiting.
	 *
	 * @param string[] $event_ids The events listed on the notice that was answered.
	 */
	public function decline( array $event_ids ): void {
		try {
			if ( array() === $event_ids ) {
				return;
			}
			$this->write(
				self::OPTION_AWAITING,
				array_values(
					array_filter(
						$this->events( self::OPTION_AWAITING ),
						static function ( array $event ) use ( $event_ids ): bool {
							return ! in_array( $event['event_id'], $event_ids, true );
						}
					)
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Authorized events that are still worth sending, oldest first.
	 *
	 * @return array[]
	 */
	public function pending(): array {
		$limit = (int) call_user_func( $this->now ) - self::MAX_AGE;
		$out   = array();
		foreach ( $this->safe_events( self::OPTION_QUEUE ) as $event ) {
			$at = strtotime( $event['at'] );
			if ( $event['attempts'] >= self::MAX_ATTEMPTS || false === $at || $at < $limit ) {
				continue;
			}
			$out[] = $event;
			if ( count( $out ) >= Babilim_Contract_Limits::ERROR_EVENTS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param string[] $event_ids Events the service accepted.
	 */
	public function mark_sent( array $event_ids ): void {
		try {
			$this->write(
				self::OPTION_QUEUE,
				array_values(
					array_filter(
						$this->events( self::OPTION_QUEUE ),
						static function ( array $event ) use ( $event_ids ): bool {
							return ! in_array( $event['event_id'], $event_ids, true );
						}
					)
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Counts a failed send; an event that reaches the attempt limit is dropped.
	 *
	 * @param string[] $event_ids Events that were not delivered.
	 */
	public function mark_failed( array $event_ids ): void {
		try {
			$out = array();
			foreach ( $this->events( self::OPTION_QUEUE ) as $event ) {
				if ( in_array( $event['event_id'], $event_ids, true ) ) {
					++$event['attempts'];
					if ( $event['attempts'] >= self::MAX_ATTEMPTS ) {
						continue;
					}
				}
				$out[] = $event;
			}
			$this->write( self::OPTION_QUEUE, $out );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Forgets every waiting and authorized event (consent withdrawn, plugin deactivated).
	 */
	public function clear_all(): void {
		try {
			$this->write( self::OPTION_AWAITING, array() );
			$this->write( self::OPTION_QUEUE, array() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Exactly the fields that would be sent for an event.
	 *
	 * @param array $event An event from awaiting() or pending().
	 * @return array<string,string>
	 */
	public function preview( array $event ): array {
		return array(
			'event_id'   => (string) ( $event['event_id'] ?? '' ),
			'error_code' => (string) ( $event['error_code'] ?? '' ),
			'action'     => (string) ( $event['action'] ?? '' ),
			'origin_id'  => (string) ( $event['origin_id'] ?? '' ),
			'at'         => (string) ( $event['at'] ?? '' ),
		);
	}

	/**
	 * Everything one error report request carries: the environment fields
	 * plus the events, each reduced to preview(). The consent notice and
	 * the sender both build from this, so a field can never be sent
	 * without being shown.
	 *
	 * @param array[] $events Events from pending() or awaiting().
	 * @return array<string,mixed>
	 */
	public function request_body( array $events ): array {
		$out = $this->environment();
		$list = array();
		foreach ( $events as $event ) {
			$list[] = $this->preview( $event );
		}
		$out['events'] = $list;
		return $out;
	}

	/**
	 * The fields sent once per request, besides the events.
	 *
	 * @return array<string,string>
	 */
	public function environment(): array {
		return array(
			'plugin_version' => self::clip( defined( 'BABILIM_VERSION' ) ? (string) BABILIM_VERSION : '0.0.0', Babilim_Contract_Limits::ERROR_ENV_VERSION ),
			'wp_version'     => self::clip( (string) ( $GLOBALS['wp_version'] ?? '' ), Babilim_Contract_Limits::ERROR_ENV_VERSION ),
			'php_version'    => self::clip( PHP_VERSION, Babilim_Contract_Limits::ERROR_ENV_VERSION ),
			'locale'         => self::clip( (string) get_locale(), Babilim_Contract_Limits::LOCALE ),
		);
	}

	/**
	 * @param string $value Value to cut.
	 * @param int    $max   Inclusive limit in characters.
	 */
	private static function clip( string $value, int $max ): string {
		return mb_substr( $value, 0, $max, 'UTF-8' );
	}

	private static function new_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return str_replace( '-', '', (string) wp_generate_uuid4() );
		}
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Appends $event, dropping the oldest entries beyond $max.
	 *
	 * @param array[] $list  Current list.
	 * @param array   $event New event.
	 * @return array[]
	 */
	private function push( array $list, array $event, int $max ): array {
		$list[] = $event;
		return array_slice( $list, -$max );
	}

	/**
	 * @param mixed $default Value when unset.
	 * @return mixed
	 */
	private function read( string $name, $default ) {
		return call_user_func( $this->get_option, $name, $default );
	}

	/**
	 * @param mixed $value New value.
	 */
	private function write( string $name, $value ): void {
		call_user_func( $this->update_option, $name, $value );
	}

	/**
	 * A stored list reduced to well-formed events.
	 *
	 * @return array[]
	 */
	private function events( string $name ): array {
		$raw = $this->read( $name, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $event ) {
			if ( is_array( $event ) && is_string( $event['event_id'] ?? null ) ) {
				$event['attempts'] = (int) ( $event['attempts'] ?? 0 );
				$event['at']       = (string) ( $event['at'] ?? '' );
				$out[]             = $event;
			}
		}
		return $out;
	}

	/**
	 * @return array[]
	 */
	private function safe_events( string $name ): array {
		try {
			return $this->events( $name );
		} catch ( \Throwable $e ) {
			unset( $e );
			return array();
		}
	}
}
