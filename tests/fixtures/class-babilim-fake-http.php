<?php
/**
 * A fake Babilim_Http that records every request it's given and returns
 * responses from a queue (the last one repeats once exhausted) -- no
 * real network call is ever made.
 *
 * Shared across test files (Babilim_Api_Client, Babilim_Site,
 * Babilim_Admin, ...) via tests/bootstrap.php, rather than declared
 * inside any one test file -- a class declared only inside one test
 * file is undefined when a different test file is run in isolation
 */

if ( ! class_exists( 'Babilim_Fake_Http' ) ) {
	final class Babilim_Fake_Http implements Babilim_Http {
		/** @var array[] */
		public $requests = array();

		/** @var array */
		private $responses;

		/**
		 * Optional: when set, request() throws this instead of returning a
		 * response -- for testing a caller's \Throwable catch-all (e.g. a
		 * genuinely unexpected failure, as opposed to the ordinary
		 * WP_Error-returning failure paths every other test here uses).
		 * Defaults to null so every existing caller (none of which pass a
		 * third constructor argument) is unaffected.
		 *
		 * @var \Throwable|null
		 */
		private $throws;

		public function __construct( array $responses, ?\Throwable $throws = null ) {
			$this->responses = $responses;
			$this->throws    = $throws;
		}

		public function request( string $method, string $url, array $args ) {
			$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
			if ( null !== $this->throws ) {
				throw $this->throws;
			}
			if ( empty( $this->responses ) ) {
				return new WP_Error( 'babilim_fake_http_exhausted', 'No fake response configured.' );
			}
			return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
		}
	}
}
