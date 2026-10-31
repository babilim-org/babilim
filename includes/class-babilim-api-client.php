<?php
/**
 * Talks to the Babilim service's versioned `/v1/` HTTP contract through
 * an injected Babilim_Http, so no test here ever makes a real network
 * call.
 *
 * Every method returns either the response body decoded as an
 * associative array, or a WP_Error built from the contract's
 * `{error:{code,message,hint}}` envelope. A WP_Error's code is always
 * `babilim_` followed by the wire error code verbatim (e.g. `rate_limited`
 * becomes `babilim_rate_limited`, `not_available` becomes
 * `babilim_not_available`) -- one mapping rule that already produces every
 * specific code the plugin needs to branch on. Its data always carries
 * `status` (the HTTP status code), `hint` and `reason` (the optional
 * machine-readable sub-code, '' when the response has none); a `429` additionally carries
 * `retry_after` (seconds, from the `Retry-After` header) when present.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A thin client for the Babilim service's `/v1/` endpoints.
 */
final class Babilim_Api_Client {

	/**
	 * fetch_package()'s own `limit_response_size` cap: a single locale's
	 * package is a handful of `.po`/`.mo`/`.json` files, so this is
	 * generous headroom, not a tight fit -- just a defensive ceiling
	 * against buffering an unbounded response fully in memory (see
	 * fetch_package()'s own docblock: the whole body is buffered before
	 * being written through Babilim_Fs).
	 *
	 * @var int
	 */
	private const MAX_PACKAGE_RESPONSE_BYTES = 20 * 1024 * 1024;

	/** Seconds allowed for one upload part PUT. */
	private const UPLOAD_PART_TIMEOUT = 60;

	/**
	 * @var Babilim_Http
	 */
	private $http;

	/**
	 * No trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * @var string|null
	 */
	private $token;

	/**
	 * @param Babilim_Http $http     Transport to send requests through.
	 * @param string       $base_url The API base, e.g. BABILIM_API_BASE. No trailing slash.
	 * @param string|null  $token    The site's Bearer token, or null before one exists
	 *                               (register()/verify() need none; every other method does).
	 */
	public function __construct( Babilim_Http $http, string $base_url, ?string $token ) {
		$this->http     = $http;
		$this->base_url = rtrim( $base_url, '/' );
		$this->token    = $token;
	}

	/**
	 * `POST /v1/sites` -- registers the site. No auth.
	 *
	 * @param array $payload {home_url, wp_version, php_version, plugin_version, site_locale, locales[], install_pub}.
	 * @return array|WP_Error {site_id, challenge: {nonce, expires_at}}.
	 */
	public function register( array $payload, ?int $timeout = null ) {
		return $this->send( 'POST', '/v1/sites', $payload, false, false, $timeout );
	}

	/**
	 * `POST /v1/sites/{id}/verify` -- submits proof of the install
	 * keypair. No auth (the proof itself is the credential).
	 *
	 * @param string $site_id Site id, e.g. `st_...`.
	 * @param string $nonce   The challenge nonce being answered.
	 * @param string $proof   `"b64:" + base64(...)`, see Babilim_Site.
	 * @return array|WP_Error {site_token, verified, license, quota} on success;
	 *                         WP_Error('babilim_invalid_proof'|'babilim_invalid_nonce'|
	 *                         'babilim_expired'|'babilim_conflict', ...) on the
	 *                         documented failure branches.
	 */
	public function verify( string $site_id, string $nonce, string $proof, ?int $timeout = null ) {
		// The contract's own shapes (nonce pattern, proof length): a value
		// outside them is not sent. `invalid_nonce` is the same code the
		// service uses for a nonce it will not accept, so the callers'
		// existing handling (drop the challenge, register afresh) applies.
		if ( 1 !== preg_match( '/^[0-9a-f]{16,64}$/D', $nonce ) || '' === $proof || ! Babilim_Contract_Limits::fits( $proof, Babilim_Contract_Limits::PROOF ) ) {
			return new WP_Error( 'babilim_invalid_nonce', __( 'The registration challenge is not in the expected form.', 'babilim' ), array( 'status' => 0, 'hint' => '', 'reason' => '' ) );
		}
		return $this->send( 'POST', '/v1/sites/' . rawurlencode( $site_id ) . '/verify', array( 'nonce' => $nonce, 'proof' => $proof ), false, false, $timeout );
	}

	/**
	 * `POST /v1/sites/{id}/rotate-token` -- issues a fresh site_token for
	 * an already-registered site (the old one stops working immediately).
	 * Auth required. The documented recovery for a `409 conflict` from
	 * verify() when this site still holds a working token -- see
	 * Babilim_Site::restart_registration().
	 *
	 * @param string $site_id Site id.
	 * @return array|WP_Error {site_token, rotated_at}.
	 */
	public function rotate_token( string $site_id ) {
		// Never retryable: retrying after the service already processed
		// this call would issue a second fresh token, invalidating the
		// first one this same request might have already received (same
		// rationale as register()/verify() -- see Babilim_Http's own
		// docblock).
		return $this->send( 'POST', '/v1/sites/' . rawurlencode( $site_id ) . '/rotate-token', array(), true );
	}

	/**
	 * `GET /v1/sites/{id}` -- the site's current license, quota and (when
	 * the service offers checkout) one-time price. Auth required.
	 *
	 * @param string   $site_id Site id.
	 * @param int|null $timeout Seconds for this request, or null for the transport default.
	 * @return array|WP_Error {site_id, host, verified, license, quota, last_seen_at, price?}.
	 */
	public function get_site( string $site_id, ?int $timeout = null ) {
		$args = array(
			'headers' => $this->headers( true ),
			// A GET with no side effect, safe to retry (same rationale as
			// meta_locales()).
			'retry'   => true,
		);
		return $this->decode( $this->transport( 'GET', $this->base_url . '/v1/sites/' . rawurlencode( $site_id ), $args, $timeout ) );
	}

	/**
	 * `POST /v1/sites/{id}/checkout` -- asks the service for a checkout
	 * page for this site. Auth required. Never retried: a resend after
	 * the service already processed the first call could open a second
	 * checkout.
	 *
	 * @param string $site_id Site id.
	 * @return array|WP_Error {checkout_url, expires_at, price}.
	 */
	public function start_checkout( string $site_id ) {
		return $this->send( 'POST', '/v1/sites/' . rawurlencode( $site_id ) . '/checkout', array(), true );
	}

	/**
	 * `POST /v1/sites/{id}/restore` -- moves a purchase to this install
	 * using the restore code shown on the original one. Auth required.
	 * Never retried: the service counts attempts.
	 *
	 * @param string $site_id      Site id.
	 * @param string $restore_code The `rc_...` restore code.
	 * @return array|WP_Error {license}.
	 */
	public function restore_purchase( string $site_id, string $restore_code ) {
		return $this->send( 'POST', '/v1/sites/' . rawurlencode( $site_id ) . '/restore', array( 'restore_code' => $restore_code ), true );
	}

	/**
	 * `POST /v1/sites/{id}/restore-code` -- issues a fresh restore code
	 * (the previous one stops working). Auth required. Never retried:
	 * a resend would replace the code the first call already returned.
	 *
	 * @param string $site_id Site id.
	 * @return array|WP_Error {restore_code}.
	 */
	public function new_restore_code( string $site_id ) {
		return $this->send( 'POST', '/v1/sites/' . rawurlencode( $site_id ) . '/restore-code', array(), true );
	}

	/**
	 * `POST /v1/sites/{id}/inventory` -- syncs installed components/
	 * locales, gets back per-(component, locale) status. Auth required.
	 *
	 * @param string $site_id Site id.
	 * @param array  $payload {components[], locales[], client_events[]?}.
	 * @return array|WP_Error See the class docblock above.
	 */
	public function inventory( string $site_id, array $payload, ?int $timeout = null ) {
		// Retryable (unlike register()/verify()): reporting the same
		// inventory snapshot twice is harmless -- the service just
		// recomputes the same per-(component, locale) status from the
		// same input, not a new side effect.
		return $this->send( 'POST', '/v1/sites/' . rawurlencode( $site_id ) . '/inventory', $payload, true, true, $timeout );
	}

	/**
	 * `POST /v1/downloads` -- exchanges a build id for a short-lived
	 * signed download link. Auth required. The `302` is not followed
	 * (`redirection => 0`) -- the caller decides what, if anything, to
	 * fetch from the returned Location.
	 *
	 * @param string $build_id Build id, e.g. `bd_...`.
	 * @return array|WP_Error {location: string} on the `302`;
	 *                         WP_Error('babilim_quota_exceeded'|'babilim_not_available', ...) otherwise.
	 */
	public function download( string $build_id, ?int $timeout = null ) {
		// The contract's own pattern: anything else would only be
		// refused by the service, so it is never sent.
		if ( 1 !== preg_match( '/^bd_[0-9a-f]{12}$/D', $build_id ) ) {
			return new WP_Error( 'babilim_invalid_build_id', __( 'That is not a valid build identifier.', 'babilim' ) );
		}
		$args = array(
			'headers'     => $this->headers( true ),
			'body'        => wp_json_encode( array( 'build_id' => $build_id ) ),
			'redirection' => 0,
		);
		$response = $this->transport( 'POST', $this->base_url . '/v1/downloads', $args, $timeout );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 302 === $status ) {
			return array( 'location' => (string) wp_remote_retrieve_header( $response, 'location' ) );
		}
		return $this->error_from_response( $status, wp_remote_retrieve_body( $response ), $response );
	}

	/**
	 * Resolves a POST /v1/downloads Location header against $base_url
	 * when it's relative -- the service's own contract defines Location
	 * as relative to the API base URL. An absolute URL is only ever
	 * accepted when its own scheme+host exactly match $base_url's --
	 * every real package link this plugin's own service issues is
	 * relative, so a different-origin absolute URL is treated as
	 * untrusted (never fetched, same as an empty Location) rather than
	 * blindly followed. This is the one place both do_download()
	 * (Babilim_Admin) and Babilim_Sync_Installer resolve a
	 * Location -- kept here, not duplicated in either caller.
	 *
	 * @param string $location Location header value.
	 * @param string $base_url The API base (e.g. BABILIM_API_BASE), with
	 *                          or without a trailing slash.
	 * @return string Absolute same-origin URL, or '' if $location was
	 *                empty or pointed at a different origin.
	 */
	public static function resolve_location( string $location, string $base_url ): string {
		if ( '' === $location ) {
			return '';
		}
		$base_url = rtrim( $base_url, '/' );
		if ( 0 !== strpos( $location, 'http://' ) && 0 !== strpos( $location, 'https://' ) ) {
			return $base_url . '/' . ltrim( $location, '/' );
		}
		$location_origin = self::url_origin( $location );
		$base_origin      = self::url_origin( $base_url );
		if ( '' === $location_origin || $location_origin !== $base_origin ) {
			return '';
		}
		return $location;
	}

	/**
	 * `{scheme}://{host}[:{port}]`, lowercased, or '' if $url can't be
	 * parsed into at least a scheme and host -- resolve_location()'s own
	 * way to compare origins without needing a full URL-equality check
	 * (path/query/fragment never matter for "is this the same host").
	 *
	 * @param string $url
	 * @return string
	 */
	private static function url_origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		return $origin;
	}

	/**
	 * `GET {url}` (a signed package link) -- buffers the response body in
	 * memory (a single locale's package is small -- a handful of `.po`/
	 * `.mo`/`.json` files) and writes it to $dest_path through the
	 * injected Babilim_Fs, never through the WP HTTP API's own `stream`/
	 * `filename` side channel -- that mode writes straight to disk
	 * itself, bypassing WP_Filesystem entirely, and every write this
	 * plugin makes must go through WP_Filesystem.
	 *
	 * @param string     $url       Absolute URL (typically resolved against
	 *                              BABILIM_API_BASE from a download() Location).
	 * @param string     $dest_path Absolute local file path to write to --
	 *                              must satisfy Babilim_Paths::is_allowed()
	 *                              (typically a Babilim_Paths::incoming_dir()
	 *                              path -- the one allow-listed area that
	 *                              accepts an arbitrary extension like `.zip`).
	 * @param Babilim_Fs $fs        Filesystem to write the downloaded bytes
	 *                              through.
	 * @return true|WP_Error True once a `200` response has been written to
	 *                        $dest_path; WP_Error('babilim_expired', ...)
	 *                        for a `410`, or another mapped error otherwise.
	 */
	public function fetch_package( string $url, string $dest_path, Babilim_Fs $fs, ?int $timeout = null ) {
		if ( ! self::is_allowed_dest_path( $dest_path ) ) {
			return new WP_Error( 'babilim_invalid_destination', __( 'Refusing to write outside the Babilim language-file directory.', 'babilim' ) );
		}
		$args = array(
			'headers'              => $this->headers( false ),
			// Safe to retry: a GET has no side effect on the service, so
			// re-sending it after a 5xx can't double-charge a quota or
			// duplicate anything the way retrying register()/verify()
			// could (see Babilim_Http's own docblock).
			'retry'                => true,
			'limit_response_size'  => self::MAX_PACKAGE_RESPONSE_BYTES,
			// The endpoint answers with the zip itself; a redirect would
			// only ever lead somewhere else (same rule as download()).
			'redirection'          => 0,
		);
		$response = $this->transport( 'GET', $url, $args, $timeout );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 200 === $status ) {
			if ( ! $fs->put( $dest_path, $body ) ) {
				return new WP_Error( 'babilim_write_failed', __( 'Could not write the downloaded package to disk.', 'babilim' ) );
			}
			return true;
		}
		return $this->error_from_response( $status, $body, $response );
	}

	/**
	 * Whether $dest_path falls inside `WP_LANG_DIR/babilim/`
	 * (Babilim_Paths::root()), the exact same shape Babilim_Fs itself
	 * enforces -- checked here too so a disallowed destination comes back
	 * as a graceful WP_Error instead of Babilim_Fs::put()'s own thrown
	 * InvalidArgumentException.
	 *
	 * @param string $dest_path Absolute local file path.
	 * @return bool
	 */
	private static function is_allowed_dest_path( string $dest_path ): bool {
		// $for_write explicit (matches is_allowed()'s own default, but
		// this call site's own docblock above -- "the exact same shape
		// Babilim_Fs::put() itself enforces" -- is a write-shaped claim,
		// so it says so here rather than relying on the default staying
		// true forever).
		return Babilim_Paths::is_allowed( $dest_path, true );
	}

	/**
	 * `POST /v1/requests` -- asks for a translation to be produced (or
	 * points at an already-available build). Auth required.
	 *
	 * @param array    $payload    {kind, slug, version, locale}.
	 * @param int|null $timeout    Optional timeout in seconds.
	 * @param bool     $can_upload Whether the current user may send a source package; when false the capability is not declared, so the service never asks for one.
	 * @return array|WP_Error {request_id, status, eta_minutes} or {status: "available", build_id}.
	 */
	public function request_translation( array $payload, ?int $timeout = null, bool $can_upload = true ) {
		// Capability declaration: this plugin can upload a component's
		// source package when the service asks (components not listed on
		// WordPress.org). The service decides whether to ask; nothing here
		// decides anything about uploading.
		if ( $can_upload ) {
			$payload['client_caps'] = array( 'upload' );
		}
		return $this->send( 'POST', '/v1/requests', $payload, true, false, $timeout );
	}

	/**
	 * `PUT /v1/uploads/{id}/parts/{n}` -- one raw part of a source package.
	 * Never retried here: a part is re-sent by the caller's own step loop
	 * (the service overwrites a part re-sent before complete).
	 *
	 * @param string   $upload_id Upload id from the request response.
	 * @param int      $n         Part number.
	 * @param string   $bytes     Raw part bytes.
	 * @param int|null $timeout   Seconds, or null for the default part timeout.
	 * @return array|WP_Error {n, size}.
	 */
	public function upload_part( string $upload_id, int $n, string $bytes, ?int $timeout = null ) {
		$args     = array(
			'headers' => array_merge( $this->headers( true ), array( 'Content-Type' => 'application/octet-stream' ) ),
			'body'    => $bytes,
			'retry'   => false,
		);
		$response = $this->transport( 'PUT', $this->base_url . '/v1/uploads/' . rawurlencode( $upload_id ) . '/parts/' . $n, $args, null === $timeout ? self::UPLOAD_PART_TIMEOUT : $timeout );
		return $this->decode( $response );
	}

	/**
	 * `POST /v1/uploads/{id}/complete` -- idempotent, so a 5xx may be retried once.
	 *
	 * @return array|WP_Error {state}.
	 */
	public function complete_upload( string $upload_id, int $parts, int $size, ?int $timeout = null ) {
		return $this->send( 'POST', '/v1/uploads/' . rawurlencode( $upload_id ) . '/complete', array( 'parts' => $parts, 'size' => $size ), true, true, $timeout );
	}

	/**
	 * `DELETE /v1/uploads/{id}` -- gives the upload up; idempotent on the service side.
	 *
	 * @return array|WP_Error {state}.
	 */
	public function cancel_upload( string $upload_id ) {
		return $this->send( 'DELETE', '/v1/uploads/' . rawurlencode( $upload_id ), array(), true, true );
	}

	/**
	 * `GET /v1/meta/locales` -- the full WordPress.org locale registry,
	 * unranked; this is the source of truth the settings screen's
	 * managed-locales list is built from. No auth required.
	 *
	 * @param int|null $timeout Seconds for this request, or null for the transport default.
	 * @return array|WP_Error {wp_locale, english_name, native_name}[] on success.
	 */
	public function meta_locales( ?int $timeout = null ) {
		$args = array(
			'headers' => $this->headers( false ),
			// Safe to retry: a GET with no side effect, same rationale as
			// fetch_package()'s own 'retry' => true.
			'retry'   => true,
		);
		$response = $this->transport( 'GET', $this->base_url . '/v1/meta/locales', $args, $timeout );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status >= 200 && $status < 300 ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return $this->error_from_response( $status, $raw, $response );
	}

	/**
	 * `POST /v1/feedback` -- reports a problem with an installed/
	 * downloaded build. Auth required.
	 *
	 * @param array $payload {kind, slug, locale, build_id, category, msgid?, msgctxt?, current_msgstr?, suggested?, note?, screen_path?, plural_index?} -- see the class docblock above for the response envelope shape.
	 * @return array|WP_Error {feedback_id} on success; WP_Error('babilim_unprocessable', ...)
	 *                         when build_id doesn't belong to this site.
	 */
	public function feedback( array $payload ) {
		return $this->send( 'POST', '/v1/feedback', $payload, true );
	}

	/**
	 * Sends a JSON POST and decodes its response.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      Path under the API base, leading slash included.
	 * @param array  $body      Request body, JSON-encoded.
	 * @param bool   $authed    Whether to send the Bearer Authorization header.
	 * @param bool   $retryable Whether Babilim_Http may retry this call once
	 *                          on a 5xx -- default false (never for a
	 *                          non-idempotent call like register()/verify());
	 *                          see Babilim_Http's own docblock.
	 * @return array|WP_Error
	 */
	private function send( string $method, string $path, array $body, bool $authed, bool $retryable = false, ?int $timeout = null ) {
		// wp_json_encode( array() ) produces the JSON array literal
		// `[]`, never the empty object `{}` -- but every one of this
		// plugin's request bodies (per the contract's own request
		// schemas) is a JSON object, and a genuinely empty one (e.g.
		// rotate_token()'s `{}`) must still encode as an object, not an
		// array. `(object) array()` -- an empty stdClass -- is what
		// wp_json_encode()/json_encode() render as `{}`.
		$args = array(
			'headers' => $this->headers( $authed ),
			'body'    => wp_json_encode( empty( $body ) ? (object) array() : $body ),
			'retry'   => $retryable,
		);
		return $this->decode( $this->transport( $method, $this->base_url . $path, $args, $timeout ) );
	}

	/**
	 * Turns a transport() result into the decoded body or a WP_Error.
	 *
	 * @param array|WP_Error $response transport() result.
	 * @return array|WP_Error
	 */
	private function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status >= 200 && $status < 300 ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return $this->error_from_response( $status, $raw, $response );
	}

	/**
	 * The one place a request leaves this class. Consent is read straight
	 * from the database here, immediately before sending, so a revocation
	 * that lands while a run is in flight stops the very next request --
	 * including the package GET that follows a download -- whatever any
	 * earlier check in the run saw.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Absolute URL.
	 * @param array  $args    Request args for Babilim_Http.
	 * @param int|null $timeout Seconds for this request, or null for the transport default.
	 * @return array|WP_Error
	 */
	private function transport( string $method, string $url, array $args, ?int $timeout ) {
		if ( ! ( new Babilim_Consent() )->granted_db() ) {
			return new WP_Error( 'babilim_no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
		}
		if ( null !== $timeout ) {
			// A caller that gave this request a time limit is working to a
			// deadline: resending after a 5xx would spend the same limit
			// again, past what the caller has left.
			$args['timeout'] = $timeout;
			$args['retry']   = false;
		}
		return $this->http->request( $method, $url, $args );
	}

	/**
	 * The headers every request sends, per the service's own contract:
	 * `Content-Type`, `User-Agent: Babilim/{plugin_version};
	 * {home_url}`, and -- when $authed -- `Authorization: Bearer <token>`.
	 *
	 * @param bool $authed Whether to include the Authorization header.
	 * @return array<string,string>
	 */
	private function headers( bool $authed ): array {
		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => self::user_agent(),
		);
		if ( $authed && null !== $this->token && '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}

	/**
	 * `Babilim/{plugin_version}; {home_url}`, the User-Agent shape the
	 * service's own contract requires. BABILIM_VERSION/home_url() are
	 * read directly rather than injected -- both are plain,
	 * already-available WordPress/plugin values, not data sources this
	 * class needs to fake a shape for (same rationale as
	 * Babilim_Inventory::locales() calling get_locale() directly).
	 *
	 * @return string
	 */
	private static function user_agent(): string {
		$version = defined( 'BABILIM_VERSION' ) ? BABILIM_VERSION : '0.0.0';
		$home    = function_exists( 'home_url' ) ? (string) home_url() : '';
		return "Babilim/{$version}; {$home}";
	}

	/**
	 * Builds the WP_Error for a non-2xx response, from the contract's
	 * `{error:{code,message,hint}}` envelope (see the class docblock for
	 * the resulting code/data shape). A body that isn't valid JSON, or
	 * doesn't carry that envelope, still produces a WP_Error rather than
	 * silently returning something success-shaped.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $raw    Raw response body.
	 * @param mixed  $response The wp_remote_request()-shaped response (for Retry-After).
	 * @return WP_Error
	 */
	private function error_from_response( int $status, string $raw, $response ): WP_Error {
		$decoded = json_decode( $raw, true );
		$error   = is_array( $decoded ) ? ( $decoded['error'] ?? null ) : null;
		$code    = is_array( $error ) ? (string) ( $error['code'] ?? '' ) : '';
		$message = is_array( $error ) ? (string) ( $error['message'] ?? '' ) : '';
		$hint    = is_array( $error ) ? (string) ( $error['hint'] ?? '' ) : '';
		// `reason` is the contract's optional machine-readable sub-code
		// (1.3.0: verification_required's callback_* values). Passed on as
		// received -- the caller that branches on it is the one that
		// decides which values it accepts.
		$reason  = ( is_array( $error ) && is_string( $error['reason'] ?? null ) ) ? $error['reason'] : '';
		if ( '' === $code ) {
			$code = 'unexpected_response';
			/* translators: %d: the unexpected HTTP status code. */
			$message = '' !== $message ? $message : sprintf( __( 'Unexpected HTTP %d response.', 'babilim' ), $status );
		}

		$data = array(
			'status' => $status,
			'hint'   => $hint,
			'reason' => $reason,
		);
		if ( 429 === $status ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			if ( '' !== (string) $retry_after ) {
				$data['retry_after'] = (int) $retry_after;
			}
		}

		return new WP_Error( 'babilim_' . $code, $message, $data );
	}
}
