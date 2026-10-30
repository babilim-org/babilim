<?php
/**
 * The real Babilim_Http implementation, wrapping wp_remote_request() --
 * every outbound HTTP request this plugin makes goes through the
 * WordPress HTTP API, never a direct transport.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls wp_remote_request(), with a fixed 20-second timeout and, only
 * when the caller opts in via $args['retry'] (see Babilim_Http's own
 * docblock), one retry on a genuine 5xx response (never on a transport-
 * level WP_Error -- that's the caller's/scheduler's own retry cadence to
 * decide, not this class's).
 */
final class Babilim_Wp_Http implements Babilim_Http {

	/**
	 * Request timeout, in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 20;

	public function request( string $method, string $url, array $args ) {
		$retry = (bool) ( $args['retry'] ?? false );
		unset( $args['retry'] );

		$args['method']  = $method;
		$args['timeout'] = $args['timeout'] ?? self::TIMEOUT;

		$response = wp_remote_request( $url, $args );
		if ( $retry && self::is_server_error( $response ) ) {
			$response = wp_remote_request( $url, $args );
		}
		return $response;
	}

	/**
	 * Whether $response is a genuine (non-WP_Error) response carrying a
	 * 5xx status code.
	 *
	 * @param mixed $response wp_remote_request()'s return value.
	 * @return bool
	 */
	private static function is_server_error( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 500 && $code < 600;
	}
}
