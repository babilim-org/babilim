<?php
/**
 * The site's own view of what the Babilim service last reported (the
 * response to `POST /v1/sites/{id}/inventory`), plus a
 * small ring buffer of local client events waiting to be reported back
 * to the service on the next sync -- never string content, just generic
 * error codes and coordinates (see that endpoint's `client_events[]`).
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the plugin's own sync state and client-event ring buffer.
 */
final class Babilim_State {

	/**
	 * The last inventory response, plus `fetched_at` (see set()/get()).
	 *
	 * @var string
	 */
	private const STATE_OPTION = 'babilim_state';

	/**
	 * A flat list of pending client_events, oldest first.
	 *
	 * @var string
	 */
	private const EVENTS_OPTION = 'babilim_client_events';

	/**
	 * How many events the ring buffer keeps -- once full, the oldest is
	 * dropped to make room for a new one.
	 *
	 * @var int
	 */
	private const MAX_EVENTS = 20;

	/**
	 * The only `kind` values the inventory request's own
	 * `client_events[].kind` schema accepts (the pinned contract
	 * schema's own `enum`) -- record_client_event() refuses anything
	 * else, and purge_invalid_client_events() removes any entry that already
	 * slipped into the buffer before that guard existed. A manifest
	 * write conflict (no real plugin/theme identity) is NOT reported
	 * this way at all -- see Babilim_Manifest's own `write_conflict()`.
	 *
	 * @var string[]
	 */
	private const ALLOWED_EVENT_KINDS = array( 'plugin', 'theme' );

	/**
	 * The last-synced state: `POST /v1/sites/{id}/inventory`'s own
	 * response shape, merged with `fetched_at` -- or whatever a caller
	 * last passed to set().
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Replaces the stored state wholesale.
	 *
	 * @param array<string,mixed> $state New state.
	 */
	public function set( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * The last-synced `updates[]` -- (component, locale) pairs this site
	 * has installed where the service's active build has since moved on.
	 *
	 * @return array[]
	 */
	public function updates(): array {
		$updates = $this->get()['updates'] ?? array();
		return is_array( $updates ) ? $updates : array();
	}

	/**
	 * The last-synced updates() entry for one (kind, slug, locale), or
	 * null if there isn't one -- the authoritative build_id to download
	 * when a locale this site has already installed has a newer build
	 * available: `updates[]` is the one response field that specifically
	 * means "download this exact build". A locale entry's own
	 * `build_id` (see status()) isn't guaranteed to be the same value,
	 * so a caller with an update
	 * available must use this instead, not status()'s own build_id.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @return array|null
	 */
	public function update_for( string $kind, string $slug, string $locale ): ?array {
		foreach ( $this->updates() as $update ) {
			if ( ( $update['kind'] ?? null ) === $kind
				&& ( $update['slug'] ?? null ) === $slug
				&& ( $update['locale'] ?? null ) === $locale
			) {
				return $update;
			}
		}
		return null;
	}

	/**
	 * The last-synced per-locale status for one component, or null if
	 * this component/locale was never part of the last sync.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @return array|null
	 */
	public function status( string $kind, string $slug, string $locale ): ?array {
		foreach ( (array) ( $this->get()['components'] ?? array() ) as $component ) {
			if ( ( $component['kind'] ?? null ) !== $kind || ( $component['slug'] ?? null ) !== $slug ) {
				continue;
			}
			$entry = $component['locales'][ $locale ] ?? null;
			return is_array( $entry ) ? $entry : null;
		}
		return null;
	}

	/**
	 * Merges a patch into one (kind, slug, locale) cell of the stored
	 * state in a single read-modify-write: only the keys in $patch are
	 * touched (`array( 'status' => ... )`, or
	 * `array( 'newer_version' => array( 'status' => ... ) )` for the
	 * newer-version part of an installed cell, or `update_build_id`,
	 * which an install leaves behind), every other field of the
	 * cell is kept as it was, and no other cell changes. Nothing is
	 * added that the patch does not carry (no override flag, no
	 * timestamp). A cell the last sync did not list is left alone.
	 *
	 * @param string              $kind   'plugin'|'theme'.
	 * @param string              $slug   Component slug.
	 * @param string              $locale Locale code.
	 * @param array<string,mixed> $patch  Fields to merge into the cell.
	 * @return bool Whether the cell existed and was updated.
	 */
	public function set_cell_status( string $kind, string $slug, string $locale, array $patch ): bool {
		$state      = $this->get();
		$components = is_array( $state['components'] ?? null ) ? $state['components'] : array();
		foreach ( $components as $index => $component ) {
			if ( ! is_array( $component ) || ( $component['kind'] ?? null ) !== $kind || ( $component['slug'] ?? null ) !== $slug ) {
				continue;
			}
			if ( ! is_array( $component['locales'][ $locale ] ?? null ) ) {
				return false;
			}
			$cell = $component['locales'][ $locale ];
			foreach ( $patch as $key => $value ) {
				if ( is_array( $value ) && is_array( $cell[ $key ] ?? null ) ) {
					$cell[ $key ] = array_merge( $cell[ $key ], $value );
				} else {
					$cell[ $key ] = $value;
				}
			}
			$components[ $index ]['locales'][ $locale ] = $cell;
			$state['components']                        = $components;
			$this->set( $state );
			return true;
		}
		return false;
	}

	/**
	 * Records the service's answer to a successful translation request in
	 * the cell, so the list shows it before the next sync. The one place
	 * both the single-cell and the bulk request paths use.
	 *
	 * An answer of `available` (a build was published after the last sync)
	 * is not a status the contract lists for a cell: it is stored as
	 * `babilim_available` with that build's id, so the cell offers Download
	 * at once. On an installed cell it is not stored at all: the next sync
	 * reports the update itself, and `newer_version` has no such status.
	 *
	 * @param string         $kind      'plugin'|'theme'.
	 * @param string         $slug      Component slug.
	 * @param string         $locale    Locale code.
	 * @param bool           $installed Whether the cell was a `babilim_installed` one when the request was sent.
	 * @param Babilim_Result $result    The successful request result.
	 */
	public function record_request_answer( string $kind, string $slug, string $locale, bool $installed, Babilim_Result $result ): void {
		$status = (string) $result->get( 'status', '' );
		if ( '' === $status ) {
			return;
		}
		if ( 'available' === $status ) {
			$build_id = (string) $result->get( 'build_id', '' );
			if ( $installed || '' === $build_id ) {
				return;
			}
			$this->set_cell_status( $kind, $slug, $locale, array( 'status' => 'babilim_available', 'build_id' => $build_id, 'reason' => null ) );
			return;
		}
		// On an uninstalled cell the service's `reason` belonged to the
		// old status, so it goes with it. An installed cell keeps its
		// top-level reason (for instance `integrity_failed`).
		$this->set_cell_status( $kind, $slug, $locale, $installed ? array( 'newer_version' => array( 'status' => $status ) ) : array( 'status' => $status, 'reason' => null ) );
	}

	/**
	 * Removes every updates() entry with the given build_id -- identity
	 * equality, not a version/build_no comparison (this class never
	 * makes that comparison itself; see updates()'s own docblock), so a
	 * caller that has just finished installing that exact build can stop
	 * it being offered again until the next sync overwrites `updates[]`
	 * wholesale. A no-op if nothing matches (already gone, or an empty
	 * build_id).
	 *
	 * @param string $build_id Build id to remove.
	 */
	public function remove_update( string $build_id ): void {
		if ( '' === $build_id ) {
			return;
		}
		$state            = $this->get();
		$updates          = is_array( $state['updates'] ?? null ) ? $state['updates'] : array();
		$state['updates'] = array_values(
			array_filter(
				$updates,
				static function ( $update ) use ( $build_id ) {
					return ( (string) ( $update['build_id'] ?? '' ) ) !== $build_id;
				}
			)
		);
		$this->set( $state );
	}

	/**
	 * Appends one client event to the ring buffer, dropping the oldest
	 * entry first once already at capacity. Stores exactly the four
	 * fields the inventory request's `client_events[]` schema allows --
	 * never string content (see the class docblock) -- so a caller
	 * passing extra keys can't leak anything wider than this shape into
	 * the buffer.
	 *
	 * A no-op if $kind isn't one the schema actually accepts
	 * (self::ALLOWED_EVENT_KINDS) -- a real, confirmed failure mode: a
	 * manifest write conflict used to be reported here with
	 * `kind: 'manifest'`, which the service's own schema rejects with a
	 * 422 on every sync (the whole request, not just that one event),
	 * and since a rejected sync never drains the buffer, the same
	 * invalid event kept getting resent and rejected on every following
	 * sync too. This guard stops any future call site from
	 * reintroducing that -- see Babilim_Manifest's own `write_conflict()`
	 * for where that specific case is reported instead (locally, never
	 * through this ring buffer), and purge_invalid_client_events() for
	 * cleaning up an entry already stuck in the buffer from before this
	 * guard existed.
	 *
	 * @param string $code   Generic error code, e.g. 'integrity_failed'.
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 */
	public function record_client_event( string $code, string $kind, string $slug, string $locale ): void {
		if ( ! in_array( $kind, self::ALLOWED_EVENT_KINDS, true ) || ! self::event_fits_contract( $code, $slug, $locale ) ) {
			return;
		}
		$events = $this->events();
		foreach ( $events as $existing ) {
			if ( ( $existing['code'] ?? null ) === $code
				&& ( $existing['kind'] ?? null ) === $kind
				&& ( $existing['slug'] ?? null ) === $slug
				&& ( $existing['locale'] ?? null ) === $locale
			) {
				return;
			}
		}
		$events[] = array(
			'at'     => gmdate( 'c' ),
			'code'   => $code,
			'kind'   => $kind,
			'slug'   => $slug,
			'locale' => $locale,
		);
		while ( count( $events ) > self::MAX_EVENTS ) {
			array_shift( $events );
		}
		update_option( self::EVENTS_OPTION, $events, false );
	}

	/**
	 * Returns every pending client event and empties the buffer -- meant
	 * to be called exactly once per sync, right after that sync's
	 * inventory request has actually succeeded (see
	 * Babilim_Site::sync(), which reads the same events via
	 * pending_client_events() to build the request body first, and only
	 * drains them once the service has confirmed it received them --
	 * draining before the request is even sent would lose every event a
	 * failed/rejected sync tried to report).
	 *
	 * @param array[]|null $sent The events the request carried: only these are removed, so an event recorded while the request was in flight stays. Null removes every pending event.
	 * @return array[]
	 */
	public function drain_client_events( ?array $sent = null ): array {
		$events = $this->events();
		if ( null === $sent ) {
			update_option( self::EVENTS_OPTION, array(), false );
			return $events;
		}
		// Only what the caller actually sent is removed: an event recorded
		// while the request was in flight stays for the next sync.
		$fields    = array( 'at', 'code', 'kind', 'slug', 'locale' );
		$remaining = array_values(
			array_udiff(
				$events,
				$sent,
				static function ( $a, $b ) use ( $fields ): int {
					foreach ( $fields as $field ) {
						$cmp = strcmp( (string) ( $a[ $field ] ?? '' ), (string) ( $b[ $field ] ?? '' ) );
						if ( 0 !== $cmp ) {
							return $cmp;
						}
					}
					return 0;
				}
			)
		);
		update_option( self::EVENTS_OPTION, $remaining, false );
		return $events;
	}

	/**
	 * Every pending client event, without draining the buffer -- for a
	 * caller that needs to read them before it knows whether it'll be
	 * able to report them successfully (see drain_client_events()).
	 *
	 * @return array[]
	 */
	public function pending_client_events(): array {
		return $this->events();
	}

	/**
	 * Removes any entry already sitting in the ring buffer whose `kind`
	 * the inventory request's own schema doesn't accept -- migrates a
	 * buffer that already has a `kind: 'manifest'` event stuck in it
	 * from before record_client_event()'s own guard existed (see that
	 * method's own docblock). Babilim_Site::sync() calls this before
	 * building the request body, so an install with a stale buffer entry
	 * self-heals on its very next sync rather than staying permanently
	 * rejected. A no-op (and never writes the option) when nothing needs
	 * removing.
	 *
	 * @return int How many entries were removed.
	 */
	public function purge_invalid_client_events(): int {
		$events   = $this->events();
		$kept     = array_values(
			array_filter(
				$events,
				static function ( $event ) {
					return in_array( $event['kind'] ?? null, self::ALLOWED_EVENT_KINDS, true )
						&& self::event_fits_contract( (string) ( $event['code'] ?? '' ), (string) ( $event['slug'] ?? '' ), (string) ( $event['locale'] ?? '' ) );
				}
			)
		);
		$removed = count( $events ) - count( $kept );
		if ( $removed > 0 ) {
			update_option( self::EVENTS_OPTION, $kept, false );
		}
		return $removed;
	}

	/**
	 * Whether an event's code, slug and locale fit the schema
	 * (`client_events[]`: each 1 character at least, at most 100, 200
	 * and 35). One entry over a limit would make the service refuse the
	 * whole inventory request on every sync, so it is never kept.
	 *
	 * @param string $code   Event code.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @return bool
	 */
	private static function event_fits_contract( string $code, string $slug, string $locale ): bool {
		return '' !== $code && Babilim_Contract_Limits::fits( $code, Babilim_Contract_Limits::EVENT_CODE )
			&& '' !== $slug && Babilim_Contract_Limits::fits( $slug, Babilim_Contract_Limits::SLUG )
			&& '' !== $locale && Babilim_Contract_Limits::fits( $locale, Babilim_Contract_Limits::LOCALE );
	}

	/**
	 * The ring buffer's current contents, without draining it.
	 *
	 * @return array[]
	 */
	private function events(): array {
		$events = get_option( self::EVENTS_OPTION, array() );
		return is_array( $events ) ? array_values( $events ) : array();
	}
}
