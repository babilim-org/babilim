<?php
/**
 * Site identity and lifecycle against the Babilim service: the per-
 * install Ed25519 keypair, registration, proof-of-ownership
 * verification, the plugin-exposed challenge endpoint the service calls
 * back to, and the twice-daily inventory sync.
 *
 * Verification protocol:
 * - The install keypair is Ed25519 (libsodium/`sodium_*`), generated on
 *   first actual use -- never on the activation hook, and never before
 *   the site owner has consented (see has_consent()) -- and its secret
 *   half is stored in an autoload=false option, never exposed to JS,
 *   REST output, logs, or events.
 * - `install_pub` sent to `POST /v1/sites` is base64 of the raw 32-byte
 *   public key.
 * - The bytes signed, both when this plugin submits its own proof and
 *   when it answers the service's callback, are always the ASCII string
 *   "babilim-challenge-v1\n{site_id}\n{nonce}"; `proof` is
 *   `"b64:" + base64(...)` of the raw 64-byte detached signature.
 * - `GET /wp-json/babilim/v1/challenge?nonce=` only ever answers for the
 *   nonce this site is currently waiting to verify, compared in constant
 *   time, and never signs anything else.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site identity, registration, and sync against the Babilim service.
 */
final class Babilim_Site {

	/**
	 * Whether the site owner has consented to using the service -- no
	 * request to the service is ever made before this is true. Written by
	 * a future settings screen; read-only here. Public: this is the one
	 * place the option name itself is defined -- every other reader
	 * references this same constant rather than the literal string, so
	 * the name is only ever written once.
	 *
	 * @var string
	 */
	public const OPTION_CONSENT = 'babilim_consent';

	/**
	 * Base64 of the install keypair's raw 64-byte Ed25519 secret key.
	 * autoload=false -- never needed on every request, and never exposed
	 * to JS, REST output, or logs.
	 *
	 * @var string
	 */
	private const OPTION_KEYPAIR_SK = 'babilim_install_sk';

	/**
	 * The site's identity, `{site_id, token}`, as ONE option written in
	 * one call: a request that dies between two writes can never leave a
	 * token without its site id (or the reverse). autoload=false -- the
	 * token is a bearer credential, kept out of the autoloaded options
	 * blob the same way the keypair secret is.
	 *
	 * @var string
	 */
	public const OPTION_IDENTITY = 'babilim_identity';

	/**
	 * Legacy two-option identity: read only, to migrate an install made
	 * before OPTION_IDENTITY existed (see identity()).
	 *
	 * @var string
	 */
	private const LEGACY_OPTION_SITE_ID = 'babilim_site_id';

	/**
	 * @var string
	 */
	private const LEGACY_OPTION_SITE_TOKEN = 'babilim_site_token';

	/**
	 * Per-request timeout caps, in seconds, and the smallest time left
	 * that is still worth starting a request in.
	 */
	public const STEP_TIMEOUT_REGISTER  = 10;
	public const STEP_TIMEOUT_VERIFY    = 15;
	public const STEP_TIMEOUT_INVENTORY = 15;
	public const STEP_MIN_SECONDS       = 3;

	/**
	 * Time budgets, in seconds, for one sync() run by caller: the Enable
	 * button, a page load that resumes an unfinished enable, and the
	 * background check.
	 */
	public const ENABLE_BUDGET_SECONDS = 45;
	public const RESUME_BUDGET_SECONDS = 20;
	public const CRON_BUDGET_SECONDS   = 120;

	/**
	 * The most inventory requests one sync() run will send. A site that
	 * needs more is told so instead of being half-synced forever.
	 */
	public const MAX_SYNC_BATCHES = 7;

	/**
	 * The last sync() outcome that was not a success: `{code, message,
	 * since, at}`. `since` is when the current run of unfinished attempts
	 * began and is only set when the option does not exist yet; later
	 * attempts update `code`, `message` and `at` alone. A success, turning
	 * Babilim off, or the owner's "start over" removes the option.
	 *
	 * @var string
	 */
	public const OPTION_LAST_SYNC_ERROR = 'babilim_sync_last_error';

	/**
	 * {site_id, nonce, expires_at} for the registration challenge this
	 * site is currently waiting to answer, or absent once resolved.
	 *
	 * @var string
	 */
	private const OPTION_PENDING = 'babilim_pending_verification';

	/**
	 * Set when verify() comes back `409 conflict` (a token was already
	 * issued) -- surfaced on the settings screen so the site owner can
	 * choose rotate-token instead of silently retrying forever.
	 *
	 * @var string
	 */
	private const OPTION_VERIFY_CONFLICT = 'babilim_verify_conflict';

	/**
	 * How many consecutive `invalid_nonce`/`expired` verify() failures
	 * have happened since the last successful verify (see
	 * handle_verify_error()). Reset to 0 on a successful verify.
	 *
	 * @var string
	 */
	private const OPTION_VERIFY_ATTEMPTS = 'babilim_verify_attempts';

	/**
	 * Set once OPTION_VERIFY_ATTEMPTS reaches MAX_VERIFY_ATTEMPTS without
	 * a successful verify -- surfaced on the settings screen to show the
	 * site owner that verification is stuck (e.g. the service can never
	 * reach this site's challenge endpoint) instead of this plugin
	 * silently re-registering forever. While set,
	 * ensure_registered() makes zero further requests (see
	 * handle_verify_error()).
	 *
	 * This is a terminal state with no automatic recovery -- nothing in
	 * this class ever clears it again except a genuinely successful
	 * verify (see ensure_registered()'s own success branch), which can't
	 * happen on its own once stalled, since the pending challenge is deliberately
	 * left in place and there's no pending challenge left to answer. The
	 * only way out is a human action a future settings-screen task must
	 * provide: an explicit "retry"/"start over" control that clears both
	 * this flag and the pending challenge (mirroring what handle_verify_error()
	 * itself does on each of the bounded attempts before this one),
	 * letting the very next ensure_registered() call start a genuinely
	 * fresh registration. That UI lives elsewhere; this class only owns
	 * detecting and reporting the stuck state.
	 *
	 * @var string
	 */
	private const OPTION_VERIFY_STALLED = 'babilim_verify_stalled';

	/**
	 * How many consecutive `invalid_nonce`/`expired` failures
	 * ensure_registered() will answer by starting a fresh registration
	 * before giving up and setting OPTION_VERIFY_STALLED instead -- the
	 * "upper bound" half of the retry-with-a-cap this plugin owes a
	 * challenge the service can seemingly never resolve (see
	 * handle_verify_error()). Each attempt only happens once per
	 * ensure_registered() call, and this plugin's only caller is the
	 * twice-daily sync cron, so the cap is already naturally spaced out
	 * over days, not hammered in a tight loop.
	 *
	 * @var int
	 */
	private const MAX_VERIFY_ATTEMPTS = 3;

	/**
	 * The "verification required" warning: {reason, since, retries,
	 * next_retry_at}, present while the service could not verify this
	 * site (it could not reach the challenge endpoint) and absent once a
	 * verify succeeds. Its own option, never part of the Babilim_State
	 * snapshot, because that snapshot is replaced wholesale on every sync.
	 *
	 * Deliberately separate from OPTION_VERIFY_ATTEMPTS/
	 * OPTION_VERIFY_STALLED: those count `expired`/`invalid_nonce`
	 * failures, where the challenge itself is spent and a fresh one must
	 * be registered. A `verification_required` failure leaves the nonce
	 * unconsumed, so it is retried on its own backoff and is never
	 * counted toward the stalled cap or answered by deleting the site id.
	 *
	 * @var string
	 */
	public const OPTION_VERIFICATION_WARNING = 'babilim_verification_warning';

	/**
	 * The callback failure reasons the service reports, plus the one
	 * generic value everything else (an unknown or missing reason) is
	 * stored as.
	 */
	public const REASON_UNREACHABLE = 'callback_unreachable';
	public const REASON_TIMEOUT     = 'callback_timeout';
	public const REASON_INVALID     = 'callback_invalid';
	public const REASON_UNKNOWN     = 'unknown';

	/**
	 * First retry delay after a verification failure, doubled per failed
	 * retry up to VERIFICATION_BACKOFF_MAX seconds.
	 *
	 * @var int
	 */
	private const VERIFICATION_BACKOFF_BASE = 900;

	/**
	 * @var int
	 */
	private const VERIFICATION_BACKOFF_MAX = 43200;

	/**
	 * The ASCII string prefix of every signed challenge message: the
	 * bytes actually signed are "{PREFIX}\n{site_id}\n{nonce}".
	 *
	 * @var string
	 */
	private const SIGN_PREFIX = 'babilim-challenge-v1';

	/**
	 * The plugin's own challenge endpoint's self-throttle transient key.
	 *
	 * @var string
	 */
	private const CHALLENGE_RATE_LIMIT_TRANSIENT = 'babilim_challenge_rl';

	/**
	 * Requests per minute the challenge endpoint answers before
	 * self-throttling.
	 *
	 * @var int
	 */
	private const CHALLENGE_RATE_LIMIT_MAX = 10;

	/**
	 * @var Babilim_Http
	 */
	private $http;

	/**
	 * @var string
	 */
	private $base_url;

	/**
	 * @var Babilim_State
	 */
	private $state;

	/**
	 * @var Babilim_Inventory
	 */
	private $inventory;

	/**
	 * @var Babilim_Manifest
	 */
	private $manifest;

	/**
	 * @param Babilim_Http      $http      Transport for outbound API calls.
	 * @param string            $base_url  The API base, e.g. BABILIM_API_BASE.
	 * @param Babilim_State     $state     Sync state / client-event ring buffer.
	 * @param Babilim_Inventory $inventory This site's component/locale inventory.
	 * @param Babilim_Manifest  $manifest  The installed-files manifest (read-only
	 *                                     here: sync() reports each record's
	 *                                     already-computed `integrity` field to
	 *                                     the service and to the client-event
	 *                                     ring buffer; it never writes to it --
	 *                                     that stays Babilim_Installer's job).
	 */
	public function __construct( Babilim_Http $http, string $base_url, Babilim_State $state, Babilim_Inventory $inventory, Babilim_Manifest $manifest ) {
		$this->http      = $http;
		$this->base_url  = $base_url;
		$this->state     = $state;
		$this->inventory = $inventory;
		$this->manifest  = $manifest;
	}

	/**
	 * Ensures this site is registered and verified, making the fewest
	 * requests needed to get there: nothing at all once a token already
	 * exists; otherwise register() then verify() (reusing a still-pending
	 * challenge across calls rather than re-registering every time).
	 * Makes zero requests, and returns immediately, when the site owner
	 * hasn't consented yet.
	 *
	 * @return array|WP_Error {site_id, already_registered: true} or
	 *                         {site_id, verified, license, quota} on success;
	 *                         WP_Error('babilim_no_consent', ...) with zero
	 *                         requests made when consent is missing;
	 *                         otherwise whatever register()/verify() themselves
	 *                         returned (see their own docblocks for the
	 *                         mapped error codes).
	 */
	public function ensure_registered( ?Babilim_Deadline $deadline = null ) {
		$deadline = $deadline ?? new Babilim_Deadline( null );
		if ( ! $this->has_consent() ) {
			return new WP_Error( 'babilim_no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
		}

		// While the service reports it cannot verify this site, nothing
		// else may run: no download, no inventory, and no retry before
		// the backoff has elapsed (see retry_verification()).
		$warning = $this->verification_warning();
		if ( null !== $warning ) {
			if ( time() < $warning['next_retry_at'] ) {
				return self::verification_required_error( $warning['reason'] );
			}
			return $this->retry_verification( $deadline );
		}

		$identity = $this->identity();
		if ( null !== $identity ) {
			return array(
				'site_id'            => $identity['site_id'],
				'already_registered' => true,
			);
		}

		return $this->attempt_register_and_verify( false, $deadline );
	}

	/**
	 * The timeout for the next request, or a `babilim_in_progress` error
	 * when too little of the budget is left to start one. Called at the
	 * moment of each request, never ahead of time, so the answer always
	 * reflects what is left now.
	 *
	 * @param Babilim_Deadline $deadline The run's budget.
	 * @param int              $cap      The step's own timeout cap.
	 * @return int|WP_Error
	 */
	private function step_timeout( Babilim_Deadline $deadline, int $cap ) {
		if ( ! $deadline->can_start( self::STEP_MIN_SECONDS ) ) {
			return new WP_Error( 'babilim_in_progress', __( 'Babilim ran out of time for this step and will continue on the next check.', 'babilim' ) );
		}
		return $deadline->step( $cap );
	}

	/**
	 * The manual "Re-verify" action: tries to verify right now, ignoring
	 * the backoff window. Only meaningful while a verification warning
	 * is recorded; otherwise it is just ensure_registered().
	 *
	 * @return array|WP_Error See ensure_registered().
	 */
	public function reverify() {
		if ( ! $this->has_consent() ) {
			return new WP_Error( 'babilim_no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
		}
		if ( null === $this->verification_warning() ) {
			return $this->ensure_registered();
		}
		return $this->retry_verification( new Babilim_Deadline( null ) );
	}

	/**
	 * The recorded verification warning, or null when the site is not in
	 * that state.
	 *
	 * @return array{reason:string,since:int,retries:int,next_retry_at:int}|null
	 */
	public function verification_warning(): ?array {
		$stored = get_option( self::OPTION_VERIFICATION_WARNING );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		return array(
			'reason'        => self::normalize_reason( $stored['reason'] ?? '' ),
			'since'         => (int) ( $stored['since'] ?? 0 ),
			'retries'       => (int) ( $stored['retries'] ?? 0 ),
			'next_retry_at' => (int) ( $stored['next_retry_at'] ?? 0 ),
		);
	}

	/**
	 * Records that the service treats this site as unverified even though
	 * a request was made with its token (inventory, downloads and
	 * packages answer `403 verification_required` for that), so nothing
	 * further is downloaded or installed until a verify succeeds. Keeps
	 * the original `since` and backoff when a warning already exists,
	 * only refining the reason.
	 *
	 * @param string $reason The service's reason, or '' when it gave none.
	 */
	public function note_server_unverified( string $reason ): void {
		$reason  = self::normalize_reason( $reason );
		$current = $this->verification_warning();
		if ( null !== $current ) {
			if ( self::REASON_UNKNOWN !== $reason && $reason !== $current['reason'] ) {
				update_option( self::OPTION_VERIFICATION_WARNING, array_merge( $current, array( 'reason' => $reason ) ), false );
			}
			return;
		}
		update_option(
			self::OPTION_VERIFICATION_WARNING,
			array(
				'reason'        => $reason,
				'since'         => time(),
				'retries'       => 1,
				'next_retry_at' => time() + self::VERIFICATION_BACKOFF_BASE,
			),
			false
		);
	}

	/**
	 * Accepts only the three reasons the service documents; anything else
	 * (including a non-string) is the generic value.
	 *
	 * @param mixed $reason Candidate reason.
	 */
	private static function normalize_reason( $reason ): string {
		$known = array( self::REASON_UNREACHABLE, self::REASON_TIMEOUT, self::REASON_INVALID );
		return ( is_string( $reason ) && in_array( $reason, $known, true ) ) ? $reason : self::REASON_UNKNOWN;
	}

	private static function verification_required_error( string $reason ): WP_Error {
		return new WP_Error(
			'babilim_verification_required',
			__( 'Babilim could not verify this site, so nothing is downloaded or installed until verification succeeds.', 'babilim' ),
			array( 'reason' => $reason )
		);
	}

	/**
	 * Notes one failed verification in the warning option: bumps the retry
	 * count and pushes the next retry out (doubling from
	 * VERIFICATION_BACKOFF_BASE, capped at VERIFICATION_BACKOFF_MAX).
	 * Never touches the site id, the pending nonce, the attempts counter
	 * or the stalled flag.
	 *
	 * @param WP_Error $error The mapped verify error.
	 */
	private function record_verification_failure( WP_Error $error ): WP_Error {
		$data   = $error->get_error_data();
		$reason = self::normalize_reason( is_array( $data ) ? ( $data['reason'] ?? '' ) : '' );
		return $this->store_verification_failure( $reason );
	}

	private function store_verification_failure( string $reason ): WP_Error {
		$current = $this->verification_warning();
		$retries = ( null === $current ? 0 : $current['retries'] ) + 1;
		$delay   = (int) min( self::VERIFICATION_BACKOFF_MAX, self::VERIFICATION_BACKOFF_BASE * ( 2 ** min( 10, $retries - 1 ) ) );
		update_option(
			self::OPTION_VERIFICATION_WARNING,
			array(
				'reason'        => $reason,
				'since'         => null === $current || 0 === $current['since'] ? time() : $current['since'],
				'retries'       => $retries,
				'next_retry_at' => time() + $delay,
			),
			false
		);
		return self::verification_required_error( $reason );
	}

	/**
	 * One verification retry from the warning state. With no token held,
	 * an unexpired pending nonce is simply verified again (the service did
	 * not consume it); an expired or missing one is replaced by a fresh
	 * registration -- outside the counted expired/invalid_nonce path, so
	 * this can neither stall nor delete the site id. With a token held (the
	 * service called the site unverified anyway) the retry runs as a
	 * candidate, replacing the held token and site id only on success.
	 *
	 * @return array|WP_Error
	 */
	private function retry_verification( Babilim_Deadline $deadline ) {
		$pending = $this->pending_verification();
		$expired = null === $pending || self::pending_expired( $pending );

		if ( null !== $this->token() ) {
			return $this->attempt_candidate_verify( $expired, $deadline );
		}
		if ( $expired ) {
			$this->clear_pending();
			return $this->attempt_register_and_verify( true, $deadline );
		}
		return $this->attempt_register_and_verify( false, $deadline );
	}

	/**
	 * @param array $pending The pending challenge option value.
	 */
	private static function pending_expired( array $pending ): bool {
		$expires = strtotime( (string) ( $pending['expires_at'] ?? '' ) );
		return false !== $expires && $expires <= time();
	}

	/**
	 * Candidate registration for a site that already holds a token: the new
	 * site id lives only in the pending challenge until verify succeeds, so
	 * a failed attempt leaves the held token and site id exactly as they
	 * were.
	 *
	 * @return array|WP_Error
	 */
	private function attempt_candidate_verify( bool $need_registration, Babilim_Deadline $deadline ) {
		$sk = $this->ensure_keypair();
		if ( $need_registration ) {
			$registration = $this->registration_payload( $sk );
			if ( is_wp_error( $registration ) ) {
				return $registration;
			}
			$timeout = $this->step_timeout( $deadline, self::STEP_TIMEOUT_REGISTER );
			if ( is_wp_error( $timeout ) ) {
				return $timeout;
			}
			$registered = $this->client( null )->register( $registration, $timeout );
			if ( is_wp_error( $registered ) ) {
				return $registered;
			}
			$site_id = (string) ( $registered['site_id'] ?? '' );
			$nonce   = (string) ( $registered['challenge']['nonce'] ?? '' );
			if ( '' === $site_id || '' === $nonce ) {
				return new WP_Error( 'babilim_invalid_response', __( 'Registration response was missing required fields.', 'babilim' ) );
			}
			$this->set_pending( $site_id, $nonce, (string) ( $registered['challenge']['expires_at'] ?? '' ) );
		}
		$pending = $this->pending_verification();
		if ( null === $pending ) {
			return new WP_Error( 'babilim_no_pending_challenge', __( 'No pending challenge to verify.', 'babilim' ) );
		}

		$timeout = $this->step_timeout( $deadline, self::STEP_TIMEOUT_VERIFY );
		if ( is_wp_error( $timeout ) ) {
			return $timeout;
		}
		$result = $this->client( null )->verify( (string) $pending['site_id'], (string) $pending['nonce'], self::sign( $sk, (string) $pending['site_id'], (string) $pending['nonce'] ), $timeout );
		if ( is_wp_error( $result ) ) {
			if ( 'babilim_verification_required' === $result->get_error_code() ) {
				return $this->record_verification_failure( $result );
			}
			// A spent challenge only costs the candidate: the next retry
			// registers another. The held credentials are never counted
			// against or removed.
			if ( in_array( $result->get_error_code(), array( 'babilim_expired', 'babilim_invalid_nonce', 'babilim_conflict' ), true ) ) {
				$this->clear_pending();
			}
			return $result;
		}
		return $this->finish_verify( $result, (string) $pending['site_id'] );
	}

	/**
	 * Stores the outcome of a successful verify() -- or, when the service
	 * did not say `verified: true` or gave no token, records a warning and
	 * stores nothing.
	 *
	 * @param array  $result  verify()'s decoded 200 body.
	 * @param string $site_id The site id that was verified.
	 * @return array|WP_Error
	 */
	private function finish_verify( array $result, string $site_id ) {
		if ( true !== ( $result['verified'] ?? null ) ) {
			return $this->store_verification_failure( self::REASON_UNKNOWN );
		}
		$new_token = (string) ( $result['site_token'] ?? '' );
		if ( '' === $new_token ) {
			return new WP_Error( 'babilim_invalid_response', __( 'Verify response was missing site_token.', 'babilim' ) );
		}
		// One write: site id and token land together or not at all.
		update_option(
			self::OPTION_IDENTITY,
			array(
				'site_id' => $site_id,
				'token'   => $new_token,
			),
			false
		);
		delete_option( self::LEGACY_OPTION_SITE_ID );
		delete_option( self::LEGACY_OPTION_SITE_TOKEN );
		delete_option( self::OPTION_VERIFY_CONFLICT );
		delete_option( self::OPTION_VERIFY_ATTEMPTS );
		delete_option( self::OPTION_VERIFY_STALLED );
		delete_option( self::OPTION_VERIFICATION_WARNING );
		$this->clear_pending();

		return array(
			'site_id'  => $site_id,
			'verified' => true,
			'license'  => $result['license'] ?? null,
			'quota'    => $result['quota'] ?? null,
		);
	}

	/**
	 * The register()+verify() attempt shared by ensure_registered() (once
	 * it's decided a fresh attempt is actually needed) and
	 * restart_registration()'s own "no token to rotate" branch. Callers
	 * are responsible for the consent check and the "already registered"
	 * shortcut -- this method always makes at least one request.
	 *
	 * @return array|WP_Error See ensure_registered().
	 */
	private function attempt_register_and_verify( bool $force_fresh_registration, Babilim_Deadline $deadline, bool $recovering = false ) {
		// $force_fresh_registration (restart_registration()'s own "no
		// token to rotate" branch) registers again even though a pending
		// challenge exists. Nothing identity-shaped is stored until
		// verify succeeds -- the new site id lives only in the pending
		// challenge -- so a failed attempt leaves any held identity
		// exactly as it was.
		//
		// A stalled or conflicted site with no pending challenge stays
		// put until restart_registration() (the owner's action): it
		// falls through to the zero-request "no pending challenge"
		// answer below.
		$blocked  = $this->verification_stalled() || (bool) get_option( self::OPTION_VERIFY_CONFLICT, false );
		$register = $force_fresh_registration || ( ! $blocked && null === $this->pending_verification() );
		$sk       = $this->ensure_keypair();

		if ( $register ) {
			$registration = $this->registration_payload( $sk );
			if ( is_wp_error( $registration ) ) {
				return $registration;
			}
			$timeout = $this->step_timeout( $deadline, self::STEP_TIMEOUT_REGISTER );
			if ( is_wp_error( $timeout ) ) {
				return $timeout;
			}
			$registered = $this->client( null )->register( $registration, $timeout );
			if ( is_wp_error( $registered ) ) {
				return $registered;
			}
			$site_id = (string) ( $registered['site_id'] ?? '' );
			$nonce   = (string) ( $registered['challenge']['nonce'] ?? '' );
			$expires = (string) ( $registered['challenge']['expires_at'] ?? '' );
			if ( '' === $site_id || '' === $nonce ) {
				return new WP_Error( 'babilim_invalid_response', __( 'Registration response was missing required fields.', 'babilim' ) );
			}
			$this->set_pending( $site_id, $nonce, $expires );
		}

		$pending = $this->pending_verification();
		if ( null === $pending ) {
			return new WP_Error( 'babilim_no_pending_challenge', __( 'No pending challenge to verify.', 'babilim' ) );
		}

		$proof   = self::sign( $sk, $pending['site_id'], $pending['nonce'] );
		$timeout = $this->step_timeout( $deadline, self::STEP_TIMEOUT_VERIFY );
		if ( is_wp_error( $timeout ) ) {
			return $timeout;
		}
		$result = $this->client( null )->verify( $pending['site_id'], $pending['nonce'], $proof, $timeout );
		if ( is_wp_error( $result ) ) {
			// The nonce is unconsumed: keep everything, retry later.
			if ( 'babilim_verification_required' === $result->get_error_code() ) {
				return $this->record_verification_failure( $result );
			}
			return $this->handle_verify_error( $result, $pending, $deadline, $recovering );
		}

		return $this->finish_verify( $result, (string) $pending['site_id'] );
	}

	/**
	 * The human recovery action OPTION_VERIFY_STALLED's own docblock says
	 * this class is missing. Makes zero requests and changes nothing
	 * without consent (same as ensure_registered()). Otherwise:
	 *
	 * - If this site still holds a working site_token, uses the
	 *   contract's own documented recovery for a `409 conflict` --
	 *   `POST /v1/sites/{id}/rotate-token` -- rather than abandoning a
	 *   registration that (as far as this site can tell) is still good
	 *   and creating a second one server-side.
	 * - Otherwise (the ordinary stalled/conflicted case: no local token
	 *   to authenticate a rotate with), drops the attempts counter and
	 *   any stale pending challenge, and forces a genuinely fresh
	 *   register()+verify() attempt (see attempt_register_and_verify()'s
	 *   own $force_fresh_registration) -- site_id itself is NEVER
	 *   deleted up front; it's only ever overwritten once that fresh
	 *   registration actually succeeds, so a failed attempt (a network
	 *   error before register() even returns) leaves the site
	 *   identifiable by its old site_id, exactly as it was.
	 *
	 * Neither branch clears OPTION_VERIFY_STALLED/OPTION_VERIFY_CONFLICT
	 * pre-emptively -- they're only ever cleared by the branch that
	 * actually succeeded (rotate-token's own success below; a successful
	 * verify's own existing clean-up in attempt_register_and_verify()).
	 * A failed attempt (no consent, a network error, or the service
	 * refusing again) leaves them exactly as they were, so the recovery
	 * notice a settings screen shows for either flag keeps showing until
	 * something has genuinely succeeded -- never because restart_
	 * registration() itself was merely *called*.
	 *
	 * @return array|WP_Error {site_id, rotated: true} on a successful
	 *                         rotate; otherwise see ensure_registered().
	 */
	public function restart_registration() {
		if ( ! $this->has_consent() ) {
			return new WP_Error( 'babilim_no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
		}

		$token = $this->token();
		if ( null !== $token ) {
			return $this->rotate_current_token( $token );
		}

		delete_option( self::OPTION_VERIFY_ATTEMPTS );
		$this->clear_pending();
		return $this->attempt_register_and_verify( true, new Babilim_Deadline( null ) );
	}

	/**
	 * restart_registration()'s "still holds a token" branch: rotates it
	 * for a fresh one, bound to the same site_id, without touching
	 * site_id or making a new registration.
	 *
	 * @param string $token The currently-held site_token.
	 * @return array|WP_Error {site_id, rotated: true} on success; the
	 *                         WP_Error rotate_token() itself returned
	 *                         otherwise -- the old token and every flag
	 *                         are left untouched on failure.
	 */
	private function rotate_current_token( string $token ) {
		$site_id = $this->site_id();
		if ( null === $site_id ) {
			// A token without a site_id on file should never happen in
			// practice (nothing in this class ever stores one without the
			// other), but there is genuinely nothing to rotate against.
			return new WP_Error( 'babilim_invalid_state', __( 'No site id is on file to rotate a token for.', 'babilim' ) );
		}

		$result = $this->client( $token )->rotate_token( $site_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$new_token = (string) ( $result['site_token'] ?? '' );
		if ( '' === $new_token ) {
			return new WP_Error( 'babilim_invalid_response', __( 'Rotate-token response was missing site_token.', 'babilim' ) );
		}

		update_option(
			self::OPTION_IDENTITY,
			array(
				'site_id' => $site_id,
				'token'   => $new_token,
			),
			false
		);
		delete_option( self::OPTION_VERIFY_CONFLICT );
		delete_option( self::OPTION_VERIFY_ATTEMPTS );
		delete_option( self::OPTION_VERIFY_STALLED );

		return array(
			'site_id' => $site_id,
			'rotated' => true,
		);
	}

	/**
	 * `GET /wp-json/babilim/v1/challenge?nonce=` REST callback. Public
	 * (no auth) by design -- the service calls this back during its own
	 * `POST /v1/sites/{id}/verify` processing.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function challenge_response( WP_REST_Request $request ): WP_REST_Response {
		$nonce_param = $request->get_param( 'nonce' );
		// A malformed request (e.g. `?nonce[]=x`, which WP_REST_Request
		// hands back as an array) must fail closed as the same
		// `invalid_nonce` response a bad string gets -- casting it with
		// `(string)` instead would coerce it into "Array" and, on PHP 8,
		// emit an `Array to string conversion` warning on every such hit.
		if ( ! is_string( $nonce_param ) ) {
			return self::error_response( 400, 'invalid_nonce', __( 'nonce must be a hexadecimal string.', 'babilim' ) );
		}
		$nonce = $nonce_param;

		if ( ! self::is_valid_nonce_format( $nonce ) ) {
			return self::error_response( 400, 'invalid_nonce', __( 'nonce must be a hexadecimal string.', 'babilim' ) );
		}

		$pending = $this->pending_verification();
		$sk      = $this->stored_secret_key();
		if ( null === $pending || null === $sk || ! hash_equals( (string) $pending['nonce'], $nonce ) ) {
			// Deliberately checked -- and failed closed -- BEFORE the
			// rate limit below, and never counts against it: a nonce
			// that was never going to match anything costs nothing to
			// reject (a format check plus one hash_equals() call, no
			// signing), and counting it anyway would let anyone flood
			// the rate-limit bucket with well-formed-but-random hex
			// nonces -- 10 a minute, this class's own
			// CHALLENGE_RATE_LIMIT_MAX -- and starve the service's own
			// real callback (the one request in the window that would
			// actually have matched) out of the same budget. The nonce
			// itself is 16-64 hex characters (64-256 bits), astronomically
			// infeasible to guess at any request rate, so nothing here
			// depends on rate-limiting to resist brute force either.
			return self::error_response( 400, 'invalid_nonce', __( 'No pending challenge for this nonce.', 'babilim' ) );
		}
		if ( $this->challenge_rate_limited() ) {
			return self::error_response( 429, 'rate_limited', __( 'Too many requests.', 'babilim' ) );
		}

		$proof = self::sign( $sk, (string) $pending['site_id'], $nonce );
		return rest_ensure_response(
			array(
				'nonce' => $nonce,
				'proof' => $proof,
			)
		);
	}

	/**
	 * Ensures registration, reports installed builds' state (and any
	 * pending client_events) to the service in as many requests as the
	 * inventory needs, and stores the merged answer. Zero requests
	 * without consent. Best-effort -- never throws.
	 *
	 * Nothing from a run that did not finish is kept: the stored answer is
	 * replaced only when every request succeeded, and the next run sends
	 * everything again.
	 *
	 * @param int|null $budget Seconds this run may take in total, or null for no limit.
	 * @return Babilim_Result Ok (`components` = how many were reported), or an error whose code is `no_consent`, `in_progress` (ran out of time, or a failure that a later run is likely to get past), `too_many_components`, or the service's own refusal.
	 */
	public function sync( ?int $budget = null ): Babilim_Result {
		try {
			if ( ! ( new Babilim_Consent() )->granted_db() ) {
				// Not recorded as a sync error: turning Babilim off removes
				// the record, and a later background run must not bring it back.
				return Babilim_Result::error( 'no_consent', __( 'Babilim has not been enabled for this site yet.', 'babilim' ) );
			}
			$deadline = new Babilim_Deadline( $budget );

			$registered = $this->ensure_registered( $deadline );
			if ( is_wp_error( $registered ) ) {
				return $this->sync_failed( $registered );
			}
			$identity = $this->identity();
			if ( null === $identity ) {
				return $this->record_sync_error( 'not_registered', __( 'This site is not registered with Babilim yet.', 'babilim' ) );
			}
			$token   = $identity['token'];
			$site_id = $identity['site_id'];

			$planned    = $this->inventory->plan_request_components();
			$components = $planned['components'];
			// What the contract's limits made the inventory leave out or
			// trim (see Babilim_Inventory::plan_request_components()) is
			// reported the same way an integrity failure is: a generic
			// client event, never any string content.
			foreach ( $planned['notes'] as $note ) {
				$this->state->record_client_event( $note['code'], $note['kind'], $note['slug'], (string) get_locale() );
			}
			foreach ( $components as &$component ) {
				// Cast even when empty: an empty PHP array json_encode()s to
				// `[]`, but the contract's `installed` is a required JSON
				// *object* (keyed by locale) -- a component with no
				// Babilim-installed locale yet must still send `{}`, or the
				// service rejects the whole request with `422
				// validation_error`.
				$component['installed'] = (object) $this->installed_map_for( (string) $component['kind'], (string) $component['slug'] );
			}
			unset( $component );

			// The service caps one request (components, locales, cells,
			// body size) and refuses one over any cap outright, so the
			// inventory is planned into requests that each fit.
			$plan = Babilim_Inventory_Planner::plan(
				$components,
				$this->inventory->locales(),
				array_merge( array( (string) get_locale() ), $this->inventory->manual_locales() )
			);
			if ( array() === $plan['batches'] ) {
				// Nothing to report, but the check did finish: recording
				// when keeps a site without components from being checked
				// again on every page load.
				$this->state->set(
					array(
						'fetched_at' => gmdate( 'c' ),
						'omitted'    => array(
							'components' => $plan['omitted_components'],
							'locales'    => $plan['omitted_locales'],
						),
					)
				);
				delete_option( self::OPTION_LAST_SYNC_ERROR );
				return Babilim_Result::ok( array( 'components' => 0 ) );
			}
			if ( count( $plan['batches'] ) > self::MAX_SYNC_BATCHES ) {
				return $this->record_sync_error(
					'too_many_components',
					sprintf(
						/* translators: 1: number of components, 2: number of languages, 3: number of check batches needed, 4: the most batches one run can send. */
						__( 'This site has %1$d components across %2$d languages, which needs %3$d check batches; Babilim can handle at most %4$d per run. Reduce the managed languages in Settings or deactivate unused plugins and themes, then press Check again now.', 'babilim' ),
						count( $components ),
						count( $plan['locales'] ),
						count( $plan['batches'] ),
						self::MAX_SYNC_BATCHES
					)
				);
			}

			// Peeked, not drained, before the requests are sent: drain()
			// would empty the ring buffer even if the run never finishes,
			// silently losing events this sync already reported. Only
			// drained once every response confirms the service received them.
			//
			// Purged first: an entry with a kind the schema doesn't
			// accept (see Babilim_State::record_client_event()'s own
			// guard) would otherwise 422 this whole request every single
			// sync, forever, since a rejected sync never reaches
			// drain_client_events() below -- self-heals an install that
			// already has one stuck from before that guard existed.
			$this->state->purge_invalid_client_events();
			$events = $this->state->pending_client_events();

			$merged = null;
			foreach ( $plan['batches'] as $index => $batch ) {
				// Decided now, for this request: what is left of the
				// budget at this moment, not at the start of the run.
				if ( ! $deadline->can_start( self::STEP_MIN_SECONDS ) ) {
					return $this->record_sync_error( 'in_progress', __( 'Babilim ran out of time for this step and will continue on the next check.', 'babilim' ) );
				}
				$payload = array(
					'components' => $batch,
					'locales'    => $plan['locales'],
				);
				// The service keeps only the latest events a request
				// carries, so they ride the first batch alone.
				if ( 0 === $index && ! empty( $events ) ) {
					$payload['client_events'] = $events;
				}

				$response = $this->client( $token )->inventory( $site_id, $payload, $deadline->step( self::STEP_TIMEOUT_INVENTORY ) );
				if ( is_wp_error( $response ) ) {
					$error_code = $response->get_error_code();
					if ( 'babilim_plugin_outdated' === $error_code ) {
						$this->state->set( array_merge( $this->state->get(), array( 'plugin_outdated' => true ) ) );
					} elseif ( 'babilim_verification_required' === $error_code ) {
						$data = $response->get_error_data();
						$this->note_server_unverified( is_array( $data ) ? (string) ( $data['reason'] ?? '' ) : '' );
					} elseif ( 'babilim_rate_limited' === $error_code ) {
						// error_from_response() (class-babilim-api-client.php)
						// only ever sets retry_after from a real Retry-After
						// header; a 429 without one still means "come back
						// later", so this falls back to a conservative 60s
						// rather than treating a missing header as "not really
						// rate-limited" and reporting nothing at all.
						$data        = $response->get_error_data();
						$retry_after = max( 1, (int) ( is_array( $data ) ? ( $data['retry_after'] ?? 60 ) : 60 ) );
						$this->state->set( array_merge( $this->state->get(), array( 'rate_limited_until' => time() + $retry_after ) ) );
					}
					return $this->sync_failed( $response );
				}
				$merged = self::merge_inventory_responses( $merged, $response );
			}
			$this->state->drain_client_events();

			$merged['fetched_at'] = gmdate( 'c' );
			$merged['omitted']    = array(
				'components' => $plan['omitted_components'],
				'locales'    => $plan['omitted_locales'],
			);
			$this->state->set( $merged );
			delete_option( self::OPTION_LAST_SYNC_ERROR );
			return Babilim_Result::ok( array( 'components' => count( $components ) ) );
		} catch ( \Throwable $e ) {
			$this->log_error( $e );
			return $this->record_sync_error( 'internal_error', __( 'Babilim could not finish checking this site.', 'babilim' ) );
		}
	}

	/**
	 * Turns a failed request into sync()'s result and records it. A
	 * failure a later run can be expected to get past -- the request
	 * itself failed (a code that is not one of this plugin's own), HTTP
	 * 429 or 5xx, or the budget ran out -- is `in_progress`; anything
	 * else is the service's explicit answer and keeps its own code.
	 *
	 * @param WP_Error $error The failed step's error.
	 * @return Babilim_Result
	 */
	private function sync_failed( WP_Error $error ): Babilim_Result {
		$code   = (string) $error->get_error_code();
		if ( 'babilim_no_consent' === $code ) {
			// Turned off while the run was in flight: nothing to record.
			return Babilim_Result::error( 'no_consent', $error->get_error_message() );
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		if ( 'babilim_in_progress' === $code || 0 !== strpos( $code, 'babilim_' ) || 429 === $status || $status >= 500 ) {
			return $this->record_sync_error( 'in_progress', $error->get_error_message() );
		}
		return $this->record_sync_error( substr( $code, strlen( 'babilim_' ) ), $error->get_error_message() );
	}

	/**
	 * Writes OPTION_LAST_SYNC_ERROR and returns the error result. `since`
	 * is kept from the existing record and only set when there is none.
	 *
	 * @param string $code    Result code.
	 * @param string $message Result message.
	 * @return Babilim_Result
	 */
	private function record_sync_error( string $code, string $message ): Babilim_Result {
		$existing = get_option( self::OPTION_LAST_SYNC_ERROR, false );
		$now      = gmdate( 'c' );
		$since    = ( is_array( $existing ) && is_string( $existing['since'] ?? null ) && '' !== $existing['since'] ) ? $existing['since'] : $now;
		update_option(
			self::OPTION_LAST_SYNC_ERROR,
			array(
				'code'    => $code,
				'message' => $message,
				'since'   => $since,
				'at'      => $now,
			),
			false
		);
		return Babilim_Result::error( $code, $message );
	}

	/**
	 * Folds one batch's answer into the running answer. Each batch is
	 * answered about its own components only, so components and updates
	 * are concatenated; the site-level fields (license, quota, features,
	 * contract) are the same in every answer and come from the first.
	 *
	 * @param array|null $merged   The answer so far, or null before the first batch.
	 * @param array      $response One batch's answer.
	 * @return array
	 */
	private static function merge_inventory_responses( ?array $merged, array $response ): array {
		if ( null === $merged ) {
			return $response;
		}
		foreach ( array( 'components', 'updates' ) as $key ) {
			$merged[ $key ] = array_merge(
				isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ? $merged[ $key ] : array(),
				isset( $response[ $key ] ) && is_array( $response[ $key ] ) ? $response[ $key ] : array()
			);
		}
		return $merged;
	}

	/**
	 * This site's own inventory entry for one installed component, or null
	 * when the site has no such component.
	 *
	 * @param string $kind 'plugin'|'theme'.
	 * @param string $slug Component slug.
	 * @return array|null
	 */
	public function component( string $kind, string $slug ): ?array {
		foreach ( $this->inventory->components() as $component ) {
			if ( ( $component['kind'] ?? '' ) === $kind && ( $component['slug'] ?? '' ) === $slug ) {
				return $component;
			}
		}
		return null;
	}

	/**
	 * The stored site id, or null before registration.
	 *
	 * @return string|null
	 */
	public function site_id(): ?string {
		$identity = $this->identity();
		return null === $identity ? null : $identity['site_id'];
	}

	/**
	 * The stored site token, or null before one has been issued.
	 *
	 * @return string|null
	 */
	public function token(): ?string {
		$identity = $this->identity();
		return null === $identity ? null : $identity['token'];
	}

	/**
	 * The site's identity, or null when it has none. Only a complete
	 * `{site_id, token}` pair is an identity: a site id on its own (the
	 * legacy option left by a registration that never verified) is not.
	 *
	 * An install from before OPTION_IDENTITY existed holds the pair in
	 * two options; the first read that finds both moves them into the new
	 * option and deletes the old ones, once.
	 *
	 * @return array{site_id:string,token:string}|null
	 */
	public function identity(): ?array {
		$stored = self::normalize_identity( get_option( self::OPTION_IDENTITY, false ) );
		if ( null !== $stored ) {
			return $stored;
		}
		$legacy = self::normalize_identity(
			array(
				'site_id' => get_option( self::LEGACY_OPTION_SITE_ID, '' ),
				'token'   => get_option( self::LEGACY_OPTION_SITE_TOKEN, '' ),
			)
		);
		if ( null === $legacy ) {
			return null;
		}
		update_option( self::OPTION_IDENTITY, $legacy, false );
		delete_option( self::LEGACY_OPTION_SITE_ID );
		delete_option( self::LEGACY_OPTION_SITE_TOKEN );
		return $legacy;
	}

	/**
	 * The identity as it is in the database right now, bypassing the
	 * object cache -- for deciding whether another request has already
	 * finished a registration this one is still working on.
	 *
	 * @return array{site_id:string,token:string}|null
	 */
	public function identity_db(): ?array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the point is to bypass the object cache.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::OPTION_IDENTITY ) );
		return is_string( $raw ) ? self::normalize_identity( maybe_unserialize( $raw ) ) : null;
	}

	/**
	 * @param mixed $value A stored identity candidate.
	 * @return array{site_id:string,token:string}|null
	 */
	private static function normalize_identity( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$site_id = $value['site_id'] ?? null;
		$token   = $value['token'] ?? null;
		if ( ! is_string( $site_id ) || '' === $site_id || ! is_string( $token ) || '' === $token ) {
			return null;
		}
		return array(
			'site_id' => $site_id,
			'token'   => $token,
		);
	}

	/**
	 * Whether the last verify() attempt came back `409 conflict` (a token
	 * already exists server-side that this site can no longer recover) --
	 * the settings screen uses this to offer rotate-token instead of
	 * silently retrying registration forever.
	 *
	 * @return bool
	 */
	public function needs_token_rotation(): bool {
		return (bool) get_option( self::OPTION_VERIFY_CONFLICT, false );
	}

	/**
	 * Whether this site has given up retrying `invalid_nonce`/`expired`
	 * verify() failures after MAX_VERIFY_ATTEMPTS consecutive attempts
	 * (see handle_verify_error()) -- the settings screen uses this to
	 * tell the site owner verification is stuck, the same way
	 * needs_token_rotation() does for a 409 conflict. While this is true,
	 * ensure_registered() makes zero further requests.
	 *
	 * @return bool
	 */
	public function verification_stalled(): bool {
		return (bool) get_option( self::OPTION_VERIFY_STALLED, false );
	}

	/**
	 * The Unix timestamp until which the most recent sync()'s own
	 * `POST /v1/sites/{id}/inventory` call was rate-limited (429; see
	 * sync()'s own `babilim_rate_limited` branch), or null if it isn't
	 * currently in that state -- either no sync has ever been rate-limited,
	 * a later sync() replaced the whole state (state->set() is a wholesale
	 * replace, not a merge, so a genuinely successful sync clears this the
	 * same way it clears `plugin_outdated`), or the stored timestamp has
	 * simply already passed.
	 *
	 * @return int|null
	 */
	public function rate_limited_until(): ?int {
		$until = $this->state->get()['rate_limited_until'] ?? null;
		if ( ! is_int( $until ) || $until <= time() ) {
			return null;
		}
		return $until;
	}

	/**
	 * verify()'s error branches:
	 * `invalid_proof` is retryable with the same nonce (kept pending);
	 * `invalid_nonce`/`expired` drop the now-unusable pending challenge
	 * and start a fresh registration on the next ensure_registered() call
	 * (by dropping site_id too) -- bounded to MAX_VERIFY_ATTEMPTS
	 * consecutive attempts, after which this site stops retrying
	 * automatically (keeping site_id, so the next call falls straight
	 * into the zero-request "no pending challenge" branch) and sets
	 * OPTION_VERIFY_STALLED instead, so a stuck challenge (e.g. the
	 * service can never reach this site's own challenge endpoint) can't
	 * loop registration attempts forever; `conflict` drops the pending
	 * challenge and is flagged for rotate-token rather than retried.
	 *
	 * @param WP_Error         $error     verify()'s own WP_Error.
	 * @param array            $pending   The pending challenge that was just answered.
	 * @param Babilim_Deadline $deadline  The run's budget.
	 * @param bool             $recovering Whether this verify already is the recovery attempt of a 409.
	 * @return array|WP_Error The same error, unchanged -- except for a 409
	 *                         that is resolved (the already-registered answer)
	 *                         or recovered from (the new attempt's own answer).
	 */
	private function handle_verify_error( WP_Error $error, array $pending, Babilim_Deadline $deadline, bool $recovering ) {
		switch ( $error->get_error_code() ) {
			case 'babilim_expired':
			case 'babilim_invalid_nonce':
				$this->clear_pending();
				$attempts = (int) get_option( self::OPTION_VERIFY_ATTEMPTS, 0 ) + 1;
				if ( $attempts >= self::MAX_VERIFY_ATTEMPTS ) {
					update_option( self::OPTION_VERIFY_ATTEMPTS, 0 );
					update_option( self::OPTION_VERIFY_STALLED, true );
					break;
				}
				// Nothing identity-shaped was stored for the spent
				// challenge, so there is no site id to drop: the next
				// call finds no pending challenge and registers afresh.
				update_option( self::OPTION_VERIFY_ATTEMPTS, $attempts );
				break;
			case 'babilim_conflict':
				$this->clear_pending();
				// A 409 means the service already issued a token for this
				// site id. If another request of ours finished this same
				// registration meanwhile, the identity is already in the
				// database and there is nothing left to do. The database
				// is read directly: this request's own cache cannot know.
				$stored = $this->identity_db();
				if ( null !== $stored && $stored['site_id'] === (string) ( $pending['site_id'] ?? '' ) ) {
					return array(
						'site_id'            => $stored['site_id'],
						'already_registered' => true,
					);
				}
				// Otherwise the token went to a registration this install
				// cannot recover (an earlier request died before storing
				// it): register again, once, if there is time for it.
				if ( ! $recovering ) {
					delete_option( self::OPTION_VERIFY_ATTEMPTS );
					if ( ! $deadline->can_start( self::STEP_MIN_SECONDS ) ) {
						return new WP_Error( 'babilim_in_progress', __( 'Babilim ran out of time for this step and will continue on the next check.', 'babilim' ) );
					}
					return $this->attempt_register_and_verify( true, $deadline, true );
				}
				update_option( self::OPTION_VERIFY_CONFLICT, true );
				break;
		}
		return $error;
	}

	/**
	 * The locales this site currently manages (stopped ones left out).
	 *
	 * @return string[]
	 */
	public function managed_locales(): array {
		return $this->inventory->locales();
	}

	/**
	 * Whether the manifest holds a live (not orphaned) record for $kind/
	 * $slug -- in $locale when one is given, in any locale when it is ''.
	 * Read-only; false when the manifest is unavailable.
	 *
	 * @param string $kind   Component kind.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code, or '' for any.
	 * @return bool
	 */
	public function has_manifest_record( string $kind, string $slug, string $locale = '' ): bool {
		if ( ! $this->manifest->is_available() ) {
			return false;
		}
		foreach ( $this->manifest->all() as $record ) {
			if ( ( $record['kind'] ?? null ) !== $kind || ( $record['slug'] ?? null ) !== $slug || ! empty( $record['orphaned_at'] ) ) {
				continue;
			}
			if ( '' === $locale || ( $record['locale'] ?? null ) === $locale ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every locale this site currently has a manifest record for $kind/
	 * $slug installed under, in the `installed{}` shape the inventory
	 * request expects. Reports (never re-checks) each record's own
	 * already-computed `integrity` field -- refreshing it is
	 * Babilim_Installer::verify()'s job, run elsewhere -- and, for any
	 * record whose last check failed, logs an `integrity_failed`
	 * client_event so this sync's own request carries it forward.
	 *
	 * @param string $kind Component kind.
	 * @param string $slug Component slug.
	 * @return array<string,array{build_id:string,verified_at:string,integrity:string}>
	 */
	private function installed_map_for( string $kind, string $slug ): array {
		$map = array();
		if ( ! $this->manifest->is_available() ) {
			return $map;
		}

		foreach ( $this->manifest->all() as $record ) {
			if ( ( $record['kind'] ?? null ) !== $kind || ( $record['slug'] ?? null ) !== $slug ) {
				continue;
			}
			if ( ! empty( $record['orphaned_at'] ) ) {
				continue;
			}
			$locale   = (string) ( $record['locale'] ?? '' );
			$build_id = (string) ( $record['build_id'] ?? '' );
			if ( '' === $locale || '' === $build_id ) {
				continue;
			}
			if ( 1 !== preg_match( '/^bd_[0-9a-f]{12}$/D', $build_id ) ) {
				// A record the service would refuse: left out of the
				// report, and said so.
				$this->state->record_client_event( 'build_id_invalid', $kind, $slug, (string) get_locale() );
				continue;
			}

			// A record with no integrity data at all has never actually
			// been checked -- defaulting that to "ok" would report a
			// verification to the service that never happened. Treating
			// it as failed instead is the fail-closed answer, and also
			// surfaces it as an integrity_failed client_event the same
			// way a genuine failed check would. Recorded BEFORE the
			// verified_at/continue guard just below: a record that ends
			// up omitted from the installed map for lacking a real
			// timestamp is still a genuine integrity failure worth
			// reporting -- the event's own `at` is when this sync noticed
			// it, never a stand-in for the record's own (missing)
			// verification time, so it isn't subject to the same
			// "never fabricate a timestamp" rule as verified_at is.
			$ok = is_array( $record['integrity'] ?? null ) ? (bool) ( $record['integrity']['ok'] ?? false ) : false;
			// A degraded record is still reported as failed, but its event
			// was recorded once, when it was degraded.
			if ( ! $ok && empty( $record['degraded_at'] ) ) {
				$this->state->record_client_event( 'integrity_failed', $kind, $slug, $locale );
			}

			// `verified_at` is a required field of every entry this
			// plugin reports, so it can't just be omitted when there's no
			// real timestamp on file -- but it also must never be
			// gmdate('c') (the current instant), which would claim a
			// verification/install that happened *right now*, when
			// nothing of the kind occurred. checked_at (an actual
			// integrity check) is preferred; installed_at (when the file
			// was genuinely written) is the fallback. If a record somehow
			// has neither, this plugin has no real timestamp to report at
			// all for it -- the whole locale entry is left out of the map
			// this sync reports, the same way a record missing its
			// locale/build_id already is above, rather than fabricate one.
			$checked_at   = is_array( $record['integrity'] ?? null ) ? (string) ( $record['integrity']['checked_at'] ?? '' ) : '';
			$installed_at = (string) ( $record['installed_at'] ?? '' );
			$verified_at  = '' !== $checked_at ? $checked_at : $installed_at;
			if ( '' === $verified_at ) {
				continue;
			}

			$map[ $locale ] = array(
				'build_id'    => $build_id,
				'verified_at' => $verified_at,
				'integrity'   => $ok ? 'ok' : 'failed',
			);
		}
		if ( count( $map ) > Babilim_Contract_Limits::MAX_INSTALLED ) {
			// Over the contract's cap: keep a stable prefix (by locale)
			// and say so, rather than have the whole request refused.
			ksort( $map, SORT_STRING );
			$map = array_slice( $map, 0, Babilim_Contract_Limits::MAX_INSTALLED, true );
			$this->state->record_client_event( 'installed_trimmed', $kind, $slug, (string) get_locale() );
		}
		return $map;
	}

	/**
	 * Whether the site owner has consented to using the service.
	 *
	 * @return bool
	 */
	private function has_consent(): bool {
		return ( new Babilim_Consent() )->granted_db();
	}

	/**
	 * A fresh Babilim_Api_Client bound to this site's http/base_url.
	 *
	 * @param string|null $token Bearer token, or null for an unauthenticated call.
	 * @return Babilim_Api_Client
	 */
	private function client( ?string $token ): Babilim_Api_Client {
		return new Babilim_Api_Client( $this->http, $this->base_url, $token );
	}

	/**
	 * The install keypair's secret key, generating and storing a fresh
	 * one on first call. Never called from anywhere that runs before
	 * has_consent() has already been checked (see ensure_registered()) --
	 * this is what makes key generation "activation-free first use",
	 * never the activation hook itself.
	 *
	 * @return string Raw 64-byte Ed25519 secret key.
	 */
	private function ensure_keypair(): string {
		$sk = $this->stored_secret_key();
		if ( null !== $sk ) {
			return $sk;
		}
		$keypair = sodium_crypto_sign_keypair();
		$sk      = sodium_crypto_sign_secretkey( $keypair );
		update_option( self::OPTION_KEYPAIR_SK, base64_encode( $sk ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of a key or signature, not code.
		return $sk;
	}

	/**
	 * The install keypair's secret key if one has already been
	 * generated, or null -- read-only, never generates one (see
	 * challenge_response(), which must never mint a keypair for a nonce
	 * that was never legitimately issued to this site).
	 *
	 * @return string|null
	 */
	private function stored_secret_key(): ?string {
		$stored = get_option( self::OPTION_KEYPAIR_SK );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$sk = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the stored key, not code.
		return ( is_string( $sk ) && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $sk ) ) ? $sk : null;
	}

	/**
	 * The registration payload for `POST /v1/sites` -- see the array
	 * shape this method returns below for exactly which fields.
	 *
	 * The contract's limits are applied here: an address or locale that
	 * cannot be expressed is an error (nothing is sent); the two
	 * informational version strings are cut to length, since they only
	 * describe the environment and identify nothing; the locale list is
	 * capped with the site locale kept.
	 *
	 * @param string $sk The install keypair's raw secret key.
	 * @return array|WP_Error
	 */
	private function registration_payload( string $sk ) {
		$pub         = sodium_crypto_sign_publickey_from_secretkey( $sk );
		$home_url    = (string) home_url();
		$site_locale = (string) get_locale();
		if ( '' === $home_url || ! Babilim_Contract_Limits::fits( $home_url, Babilim_Contract_Limits::HOME_URL ) ) {
			return new WP_Error( 'babilim_home_url_invalid', __( 'This site\'s address is too long for Babilim to register.', 'babilim' ) );
		}
		if ( '' === $site_locale || ! Babilim_Contract_Limits::fits( $site_locale, Babilim_Contract_Limits::LOCALE ) ) {
			return new WP_Error( 'babilim_site_locale_invalid', __( 'This site\'s locale code is not one Babilim can register.', 'babilim' ) );
		}
		$locales = array_slice( $this->inventory->locales(), 0, Babilim_Contract_Limits::MAX_LOCALES );
		if ( array() === $locales ) {
			$locales = array( $site_locale );
		} elseif ( ! in_array( $site_locale, $locales, true ) ) {
			$locales[ count( $locales ) - 1 ] = $site_locale;
			$locales                          = array_values( array_unique( $locales ) );
		}
		return array(
			'home_url'       => $home_url,
			'wp_version'     => self::clip( (string) ( $GLOBALS['wp_version'] ?? '' ), Babilim_Contract_Limits::ENV_VERSION ),
			'php_version'    => self::clip( PHP_VERSION, Babilim_Contract_Limits::ENV_VERSION ),
			'plugin_version' => defined( 'BABILIM_VERSION' ) ? BABILIM_VERSION : '0.0.0',
			'site_locale'    => $site_locale,
			'locales'        => $locales,
			'install_pub'    => base64_encode( $pub ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of a key or signature, not code.
		);
	}

	/**
	 * $value cut to at most $max characters.
	 *
	 * @param string $value Value to cut.
	 * @param int    $max   Inclusive limit.
	 * @return string
	 */
	private static function clip( string $value, int $max ): string {
		return mb_substr( $value, 0, $max, 'UTF-8' );
	}

	/**
	 * The ASCII bytes signed for one challenge/proof, signed with the
	 * install keypair's secret key.
	 *
	 * @param string $sk      Raw secret key.
	 * @param string $site_id Site id.
	 * @param string $nonce   Nonce.
	 * @return string `"b64:" + base64(...)` of the raw 64-byte signature.
	 */
	private static function sign( string $sk, string $site_id, string $nonce ): string {
		$message   = self::SIGN_PREFIX . "\n{$site_id}\n{$nonce}";
		$signature = sodium_crypto_sign_detached( $message, $sk );
		return 'b64:' . base64_encode( $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of a key or signature, not code.
	}

	/**
	 * Whether $nonce is a well-formed nonce: hex only, 16-64 characters
	 * (matches the contract's own nonce pattern).
	 *
	 * @param string $nonce Candidate nonce.
	 * @return bool
	 */
	private static function is_valid_nonce_format( string $nonce ): bool {
		return 1 === preg_match( '/^[0-9a-f]{16,64}$/D', $nonce );
	}

	/**
	 * @return array{site_id:string,nonce:string,expires_at:string}|null
	 */
	private function pending_verification(): ?array {
		$pending = get_option( self::OPTION_PENDING );
		if ( ! is_array( $pending ) || empty( $pending['site_id'] ) || empty( $pending['nonce'] ) ) {
			return null;
		}
		return $pending;
	}

	/**
	 * @param string $site_id    Site id.
	 * @param string $nonce      Challenge nonce.
	 * @param string $expires_at Challenge expiry, ISO-8601.
	 */
	private function set_pending( string $site_id, string $nonce, string $expires_at ): void {
		update_option(
			self::OPTION_PENDING,
			array(
				'site_id'    => $site_id,
				'nonce'      => $nonce,
				'expires_at' => $expires_at,
			)
		);
	}

	private function clear_pending(): void {
		delete_option( self::OPTION_PENDING );
	}

	/**
	 * The challenge endpoint's own self-throttle: at most
	 * CHALLENGE_RATE_LIMIT_MAX answered requests per fixed one-minute
	 * window, independent of the service's own per-site limit. Uses a
	 * fixed window anchored at the first request that starts it, not a
	 * rolling one refreshed on every hit -- refreshing the transient's
	 * own TTL on every request means any request arriving less than a
	 * minute after the previous one keeps pushing the reset out, so a
	 * site making a request more often than once a minute would never
	 * actually get a fresh window and would stay throttled far more
	 * strictly than "10 per minute" once it ever hit the cap.
	 *
	 * @return bool True if this request should be refused.
	 */
	private function challenge_rate_limited(): bool {
		$now    = time();
		$bucket = get_transient( self::CHALLENGE_RATE_LIMIT_TRANSIENT );
		if ( ! is_array( $bucket ) || ! isset( $bucket['window_start'] ) || ( $now - (int) $bucket['window_start'] ) >= MINUTE_IN_SECONDS ) {
			$bucket = array(
				'window_start' => $now,
				'count'        => 0,
			);
		}
		if ( (int) $bucket['count'] >= self::CHALLENGE_RATE_LIMIT_MAX ) {
			return true;
		}
		++$bucket['count'];
		set_transient( self::CHALLENGE_RATE_LIMIT_TRANSIENT, $bucket, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Builds a `{error:{code,message,hint}}`-shaped WP_REST_Response at
	 * $status -- the service's own contract error envelope shape.
	 *
	 * @param int    $status  HTTP status code.
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 * @return WP_REST_Response
	 */
	private static function error_response( int $status, string $code, string $message ): WP_REST_Response {
		$response = rest_ensure_response(
			array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
					'hint'    => '',
				),
			)
		);
		$response->set_status( $status );
		return $response;
	}

	/**
	 * Logs a caught Throwable without ever letting logging itself fatal
	 * a request -- same WP_DEBUG-gated pattern as Babilim/Babilim_Loader's
	 * own log_error().
	 *
	 * @param \Throwable $e The caught exception/error.
	 */
	private function log_error( \Throwable $e ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( function_exists( 'error_log' ) ) {
			error_log( '[babilim] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- only reached when WP_DEBUG is on.
		}
	}
}
