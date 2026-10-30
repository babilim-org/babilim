<?php
/**
 * The upload half of "Request translation" for a component the service
 * does not have source for: keeps the one in-progress upload in an option,
 * packs the component on confirmation, sends parts one request at a time
 * (each admin-post step sends what fits in its deadline), completes, and
 * tears the staging directory down on every outcome. No decision about
 * whether or what to upload is made here -- the service's `upload` answer
 * is the only trigger, and its limits are the only limits.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Babilim_Upload {

	const OPTION       = 'babilim_upload_pending';
	const DONE_OPTION  = 'babilim_upload_done';
	const MAX_ATTEMPTS = 3;
	const PART_TIMEOUT = 60;

	private Babilim_Api_Client $api;
	private Babilim_Packager $packager;
	private Babilim_State $state;

	public function __construct( Babilim_Api_Client $api, Babilim_Packager $packager, Babilim_State $state ) {
		$this->api      = $api;
		$this->packager = $packager;
		$this->state    = $state;
	}

	/**
	 * The one in-progress upload, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function pending(): ?array {
		$v = get_option( self::OPTION, null );
		return is_array( $v ) && '' !== (string) ( $v['upload_id'] ?? '' ) ? $v : null;
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/** @param array<string,mixed> $p */
	private static function save( array $p ): void {
		update_option( self::OPTION, $p, false );
	}

	/**
	 * Remembers the service's upload offer. Nothing is packaged or sent:
	 * until confirm() nothing leaves the site.
	 *
	 * @param array $component Inventory entry (kind, slug, version, main_file|stylesheet).
	 * @param array $upload    The `upload` object of the service's answer.
	 */
	public function begin( array $component, string $locale, array $upload ): Babilim_Result {
		foreach ( array( 'upload_id', 'part_bytes', 'max_parts', 'max_bytes', 'expires_at' ) as $k ) {
			if ( ! isset( $upload[ $k ] ) ) {
				return Babilim_Result::error( 'invalid_response', __( 'The service\'s upload offer was incomplete.', 'babilim' ) );
			}
		}
		$p = array(
			'upload_id'  => (string) $upload['upload_id'],
			'kind'       => (string) $component['kind'],
			'slug'       => (string) $component['slug'],
			'version'    => (string) ( $component['version'] ?? '' ),
			'main_file'  => (string) ( $component['main_file'] ?? '' ),
			'stylesheet' => (string) ( $component['stylesheet'] ?? '' ),
			'name'       => (string) ( $component['name'] ?? '' ),
			'locale'     => $locale,
			'part_bytes' => (int) $upload['part_bytes'],
			'max_parts'  => (int) $upload['max_parts'],
			'max_bytes'  => (int) $upload['max_bytes'],
			'expires_at' => (string) $upload['expires_at'],
			'started'    => time(),
		);
		self::save( $p );
		$this->mark( $p, null, true );
		return Babilim_Result::ok();
	}

	/** Packs the component and checks the package against the offer's limits. */
	public function confirm(): Babilim_Result {
		$p = self::pending();
		if ( null === $p ) {
			return Babilim_Result::error( 'no_upload', __( 'There is no upload waiting for confirmation.', 'babilim' ) );
		}
		if ( isset( $p['zip'] ) ) {
			return Babilim_Result::ok( array( 'parts' => $p['parts'] ) ); // Already packed (a double submit).
		}
		$packed = $this->packager->pack( $p );
		if ( $packed->is_error() ) {
			return $this->fail( $p, (string) $packed->code(), $packed->message() );
		}
		$size  = (int) $packed->get( 'size' );
		$parts = (int) ceil( $size / max( 1, (int) $p['part_bytes'] ) );
		if ( 0 === $size || $size > (int) $p['max_bytes'] || $parts > (int) $p['max_parts'] ) {
			$this->packager->discard( (string) $packed->get( 'dir' ) );
			return $this->fail( $p, 'upload_too_large', __( 'This component is larger than the service accepts, so it cannot be uploaded.', 'babilim' ) );
		}
		$p = array_merge(
			$p,
			array(
				'zip'        => (string) $packed->get( 'zip' ),
				'dir'        => (string) $packed->get( 'dir' ),
				'size'       => $size,
				'parts'      => $parts,
				'next'       => 1,
				'attempts'   => 0,
				'not_before' => 0,
			)
		);
		self::save( $p );
		return Babilim_Result::ok( array( 'parts' => $parts ) );
	}

	/**
	 * One request's worth of work: sends the next part, or -- once every
	 * part is sent -- completes the upload. Each part gets the full
	 * PART_TIMEOUT; the upload advances by repeated calls (one admin-post
	 * request each), never by chaining requests inside one.
	 *
	 * @return Babilim_Result ok({done, next, parts}); an error ends the upload.
	 */
	public function step(): Babilim_Result {
		$p = self::pending();
		if ( null === $p || ! isset( $p['zip'] ) ) {
			return Babilim_Result::error( 'no_upload', __( 'There is no upload in progress.', 'babilim' ) );
		}
		if ( time() < (int) $p['not_before'] ) {
			return $this->progress( $p, false );
		}
		// Like WordPress core's own long upgrade requests: ask for more
		// execution time when the host allows it, carry on when it does not.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::PART_TIMEOUT + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- a part can take a full request timeout; hosts may forbid this call.
		}
		if ( (int) $p['next'] > (int) $p['parts'] ) {
			return $this->complete( $p );
		}
		$bytes = $this->read_part( (string) $p['zip'], (int) $p['next'], (int) $p['part_bytes'] );
		if ( null === $bytes ) {
			return $this->fail( $p, 'packager_read_failed', __( 'The packaged component could not be read.', 'babilim' ) );
		}
		$out = $this->api->upload_part( (string) $p['upload_id'], (int) $p['next'], $bytes, self::PART_TIMEOUT );
		if ( is_wp_error( $out ) ) {
			return $this->part_failed( $p, $out );
		}
		++$p['next'];
		$p['attempts'] = 0;
		self::save( $p );
		return $this->progress( $p, false );
	}

	/** @param array<string,mixed> $p */
	private function complete( array $p ): Babilim_Result {
		$done = $this->api->complete_upload( (string) $p['upload_id'], (int) $p['parts'], (int) $p['size'], self::PART_TIMEOUT );
		if ( is_wp_error( $done ) ) {
			if ( 'babilim_upload_incomplete' === $done->get_error_code() ) {
				// The service lacks a part: send everything again, but only
				// a few times. `restarts` is never reset by a part succeeding.
				$p['restarts'] = (int) ( $p['restarts'] ?? 0 ) + 1;
				if ( $p['restarts'] > self::MAX_ATTEMPTS ) {
					return $this->fail( $p, 'upload_incomplete', $done->get_error_message() );
				}
				$p['next'] = 1;
				self::save( $p );
				return $this->progress( $p, false );
			}
			if ( 'babilim_upload_sealed' === $done->get_error_code() ) {
				return $this->finish( $p ); // Already sealed: the service has it.
			}
			if ( $this->is_transient( $done ) ) {
				// A lost or timed-out answer says nothing about the upload:
				// the service may well have sealed it. complete is
				// idempotent, so keep the pending state and send it again
				// on the next step (cancelling would throw away a finished
				// upload); only give up when the retries run out.
				++$p['attempts'];
				if ( (int) $p['attempts'] > self::MAX_ATTEMPTS ) {
					return $this->fail( $p, $this->short_code( $done ), $done->get_error_message() );
				}
				self::save( $p );
				return $this->progress( $p, false );
			}
			return $this->fail( $p, $this->short_code( $done ), $done->get_error_message() );
		}
		return $this->finish( $p );
	}

	/** Whether the error is a transport failure (no HTTP answer at all) or a 5xx answer. */
	private function is_transient( WP_Error $error ): bool {
		if ( 'babilim_no_consent' === $error->get_error_code() ) {
			return false; // Consent withdrawn: nothing will be sent again.
		}
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			return true;
		}
		return (int) $data['status'] >= 500;
	}

	/**
	 * The service has the whole package: remember its id (so a list that
	 * still offers "continue" while the service verifies it does not
	 * upload again), remove the staging directory, mark the cell queued.
	 *
	 * @param array<string,mixed> $p
	 */
	private function finish( array $p ): Babilim_Result {
		$this->packager->discard( (string) ( $p['dir'] ?? '' ) );
		self::clear();
		update_option( self::DONE_OPTION, (string) $p['upload_id'], false );
		$this->mark( $p, 'queued', false );
		return $this->progress( $p, true );
	}

	/** The id of the upload most recently handed to the service in full, or ''. */
	public static function last_completed(): string {
		return (string) get_option( self::DONE_OPTION, '' );
	}

	/** Gives the upload up: tells the service, removes the staging directory, clears the pending state. */
	public function cancel(): Babilim_Result {
		$p = self::pending();
		if ( null === $p ) {
			return Babilim_Result::ok();
		}
		$this->api->cancel_upload( (string) $p['upload_id'] );   // Best effort; the service expires it anyway.
		$this->teardown( $p );
		return Babilim_Result::ok();
	}

	/**
	 * What to do with one failed part: end the upload for an answer that
	 * will not change, otherwise count the attempt (and honour Retry-After).
	 *
	 * @param array<string,mixed> $p
	 */
	private function part_failed( array $p, WP_Error $error ): Babilim_Result {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'babilim_upload_expired', 'babilim_not_found', 'babilim_payload_too_large', 'babilim_no_consent' ), true ) ) {
			return $this->fail( $p, $this->short_code( $error ), $error->get_error_message() );
		}
		if ( 'babilim_upload_sealed' === $code ) {
			return $this->finish( $p ); // Sealed by an earlier complete: the service has every part.
		}
		++$p['attempts'];
		if ( 'babilim_rate_limited' === $code ) {
			$data            = (array) $error->get_error_data();
			$p['not_before'] = time() + max( 1, (int) ( $data['retry_after'] ?? 60 ) );
		}
		if ( (int) $p['attempts'] > self::MAX_ATTEMPTS ) {
			return $this->fail( $p, 'upload_part_failed', __( 'A part of the upload kept failing, so the upload was cancelled.', 'babilim' ) );
		}
		self::save( $p );
		return $this->progress( $p, false );
	}

	private function short_code( WP_Error $error ): string {
		return (string) preg_replace( '/^babilim_/', '', (string) $error->get_error_code() );
	}

	/** @param array<string,mixed> $p */
	private function progress( array $p, bool $done ): Babilim_Result {
		return Babilim_Result::ok(
			array(
				'done'  => $done,
				'next'  => $done ? (int) $p['parts'] + 1 : (int) $p['next'],
				'parts' => (int) $p['parts'],
			)
		);
	}

	/** @param array<string,mixed> $p */
	private function fail( array $p, string $code, string $message ): Babilim_Result {
		$this->api->cancel_upload( (string) $p['upload_id'] );
		$this->teardown( $p );
		return Babilim_Result::error( $code, $message );
	}

	/** @param array<string,mixed> $p */
	private function teardown( array $p ): void {
		if ( isset( $p['dir'] ) ) {
			$this->packager->discard( (string) $p['dir'] );
		}
		self::clear();
		$this->mark( $p, 'requestable', false );
	}

	/**
	 * Updates the list cell the upload belongs to: the `upload_pending`
	 * flag, and (when $status is given) its request status -- on an
	 * installed translation that is the newer-version status, like
	 * Babilim_State::record_request_answer().
	 *
	 * Rewriting an uninstalled cell's status also clears the service's
	 * `reason`: that reason described the old status, and left behind it
	 * would show a cancelled upload as a rejected one. An installed
	 * cell's top-level reason (`integrity_failed`) is kept.
	 *
	 * @param array<string,mixed> $p
	 */
	private function mark( array $p, ?string $status, bool $pending ): void {
		$kind   = (string) $p['kind'];
		$slug   = (string) $p['slug'];
		$locale = (string) $p['locale'];
		$entry  = $this->state->status( $kind, $slug, $locale );
		if ( null === $entry ) {
			return;
		}
		$patch = array( 'upload_pending' => $pending );
		if ( null !== $status ) {
			if ( 'babilim_installed' === (string) ( $entry['status'] ?? '' ) ) {
				$patch['newer_version'] = array( 'status' => $status );
			} else {
				$patch['status'] = $status;
				$patch['reason'] = null;
			}
		}
		$this->state->set_cell_status( $kind, $slug, $locale, $patch );
	}

	/** Reads one part of the staging zip (read only). */
	private function read_part( string $zip, int $n, int $part_bytes ): ?string {
		if ( ! Babilim_Packager::owns( dirname( $zip ) ) ) {
			return null;
		}
		$fh = fopen( $zip, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only slice of the staging zip; the packaging staging directory is a documented exception.
		if ( false === $fh ) {
			return null;
		}
		fseek( $fh, ( $n - 1 ) * $part_bytes );
		$data = fread( $fh, $part_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- read-only slice of the staging zip; the packaging staging directory is a documented exception.
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- read-only slice of the staging zip; the packaging staging directory is a documented exception.
		return false === $data ? null : $data;
	}
}
