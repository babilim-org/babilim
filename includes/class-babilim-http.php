<?php
/**
 * The one seam through which this plugin ever makes an outbound network
 * request, always via the WordPress HTTP API and never a direct
 * transport. Babilim_Wp_Http is the real, production implementation,
 * wrapping wp_remote_request(); a test double implementing the same
 * interface is used by every unit test that needs an Babilim_Api_Client
 * without touching the network.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal HTTP transport contract.
 */
interface Babilim_Http {

	/**
	 * Performs one HTTP request.
	 *
	 * @param string $method HTTP method, e.g. 'GET'/'POST'.
	 * @param string $url    Absolute URL.
	 * @param array  $args   wp_remote_request()-shaped args (headers, body,
	 *                       timeout, redirection, stream, filename, ...),
	 *                       plus an implementation-specific `retry` key
	 *                       (bool, default false): whether a genuine (non-
	 *                       WP_Error) 5xx response may be retried once. The
	 *                       caller opts in explicitly -- see
	 *                       Babilim_Api_Client, which only ever sets this
	 *                       for GET requests and the (safely re-sendable)
	 *                       inventory sync, never for a non-idempotent POST
	 *                       like register()/verify() where a retry after
	 *                       the service already processed the first
	 *                       attempt could create a duplicate site or land
	 *                       on an already-consumed nonce.
	 * @return array|WP_Error A wp_remote_request()-shaped response array
	 *                        (readable via wp_remote_retrieve_body()/
	 *                        wp_remote_retrieve_response_code()/
	 *                        wp_remote_retrieve_header()), or a WP_Error
	 *                        on a transport-level failure.
	 */
	public function request( string $method, string $url, array $args );
}
