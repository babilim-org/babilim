<?php
/**
 * Tests for Babilim_Site -- install keypair, registration/verification
 * against the fake Babilim_Http, the `GET /wp-json/babilim/v1/challenge`
 * REST callback, and sync(). See Babilim_Site's own class docblock for
 * the verification protocol.
 *
 * get_locale() isn't stubbed by WordPress core itself in tests (see
 * Babilim_Inventory's own docblock for why); stubbed by a shared fixture
 * (tests/fixtures/wp-locale-stubs.php, loaded by tests/bootstrap.php) so
 * every test file sees it regardless of load order -- the guarded
 * declaration below is a harmless no-op once that fixture has already
 * defined it, kept only so this file still works standing alone against
 * an older bootstrap.php; both use the same $GLOBALS value.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return $GLOBALS['babilim_test_site_locale'] ?? 'en_US';
	}
}

final class Test_Site extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
	}

	/**
	 * @param array[] $responses Queued fake Babilim_Http responses.
	 */
	private function make_site( array $responses = array(), ?Babilim_Manifest $manifest = null, ?Babilim_Inventory $inventory = null ): array {
		$http      = new Babilim_Fake_Http( $responses );
		$inventory = $inventory ?? new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$manifest = $manifest ?? new Babilim_Manifest( new Babilim_Memory_Fs() );
		$state    = new Babilim_State();
		$site     = new Babilim_Site( $http, 'https://api.babilim.org', $state, $inventory, $manifest );
		return array( $site, $http, $state, $manifest );
	}

	private function json_response( int $code, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}

	private function register_response( string $site_id = 'st_3f9c2a1b7e4d', string $nonce = '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' ): array {
		return $this->json_response( 201, array( 'site_id' => $site_id, 'challenge' => array( 'nonce' => $nonce, 'expires_at' => '2026-09-27T12:15:00Z' ) ) );
	}

	private function verify_ok_response( string $token = 'tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b' ): array {
		return $this->json_response( 200, array( 'site_token' => $token, 'verified' => true, 'license' => array( 'kind' => 'free', 'status' => 'active' ), 'quota' => array() ) );
	}

	// -- consent ------------------------------------------------------

	public function test_ensure_registered_without_consent_makes_zero_requests(): void {
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		$result = $site->ensure_registered();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_no_consent', $result->get_error_code() );
		$this->assertCount( 0, $http->requests );
	}

	public function test_sync_without_consent_makes_zero_requests(): void {
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		$site->sync();

		$this->assertCount( 0, $http->requests );
	}

	public function test_ensure_registered_without_consent_generates_no_keypair(): void {
		list( $site ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		$site->ensure_registered();

		$this->assertFalse( get_option( 'babilim_install_sk', false ), 'the install keypair must never be generated before consent' );
	}

	// -- register + verify ---------------------------------------------

	public function test_ensure_registered_registers_then_verifies_and_stores_token(): void {
		update_option( 'babilim_consent', true );
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		$result = $site->ensure_registered();

		$this->assertSame( 2, count( $http->requests ) );
		$this->assertSame( 'https://api.babilim.org/v1/sites', $http->requests[0]['url'] );
		$this->assertStringContainsString( '/v1/sites/st_3f9c2a1b7e4d/verify', $http->requests[1]['url'] );
		$this->assertSame( 'tok_7a0d3f6b8c1e4a7d0f3b6c9e2a5d8f1b', $site->token() );
		$this->assertSame( 'st_3f9c2a1b7e4d', $site->site_id() );
		$this->assertTrue( $result['verified'] );
	}

	public function test_install_pub_is_base64_of_the_raw_32_byte_public_key(): void {
		update_option( 'babilim_consent', true );
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		$site->ensure_registered();

		$body = json_decode( $http->requests[0]['args']['body'], true );
		$raw  = base64_decode( $body['install_pub'], true );
		$this->assertSame( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen( $raw ) );
	}

	public function test_proof_is_a_valid_signature_of_the_challenge_message(): void {
		update_option( 'babilim_consent', true );
		list( $site, $http ) = $this->make_site( array( $this->register_response( 'st_x', 'abc123abc123abc1' ), $this->verify_ok_response() ) );

		$site->ensure_registered();

		$sk        = base64_decode( get_option( 'babilim_install_sk' ), true );
		$pub       = sodium_crypto_sign_publickey_from_secretkey( $sk );
		$body      = json_decode( $http->requests[1]['args']['body'], true );
		$this->assertStringStartsWith( 'b64:', $body['proof'] );
		$signature = base64_decode( substr( $body['proof'], 4 ), true );
		$message   = "babilim-challenge-v1\nst_x\nabc123abc123abc1";
		$this->assertTrue( sodium_crypto_sign_verify_detached( $signature, $message, $pub ) );
	}

	public function test_keypair_is_stored_with_autoload_disabled(): void {
		update_option( 'babilim_consent', true );
		list( $site ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		$site->ensure_registered();

		$this->assertFalse( $GLOBALS['babilim_test_options_autoload']['babilim_install_sk'] );
		$this->assertFalse( $GLOBALS['babilim_test_options_autoload']['babilim_identity'] );
	}

	public function test_already_registered_site_makes_zero_further_requests(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		$result = $site->ensure_registered();

		$this->assertCount( 0, $http->requests );
		$this->assertTrue( $result['already_registered'] );
	}

	public function test_a_pending_challenge_is_reused_rather_than_re_registering(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_install_sk', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
		update_option( 'babilim_pending_verification', array( 'site_id' => 'st_x', 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a', 'expires_at' => 'e' ) );
		list( $site, $http ) = $this->make_site( array( $this->verify_ok_response() ) );

		$site->ensure_registered();

		$this->assertCount( 1, $http->requests );
		$this->assertStringContainsString( '/verify', $http->requests[0]['url'] );
	}

	// -- verify() error branches ----------------------------------------

	public function test_verify_conflict_sets_rotation_flag_and_clears_pending(): void {
		update_option( 'babilim_consent', true );
		$conflict = $this->json_response( 409, array( 'error' => array( 'code' => 'conflict', 'message' => 'x', 'hint' => 'y' ) ) );
		// The first 409 is answered by one fresh registration; the second
		// one (the recovery attempt itself) is what raises the flag.
		list( $site ) = $this->make_site( array( $this->register_response(), $conflict, $this->register_response( 'st_again' ), $conflict ) );

		$result = $site->ensure_registered();

		$this->assertSame( 'babilim_conflict', $result->get_error_code() );
		$this->assertTrue( $site->needs_token_rotation() );
		$this->assertNull( get_option( 'babilim_pending_verification', null ) );
	}

	public function test_verify_expired_drops_site_id_for_re_registration(): void {
		update_option( 'babilim_consent', true );
		$expired = $this->json_response( 410, array( 'error' => array( 'code' => 'expired', 'message' => 'x', 'hint' => 'y' ) ) );
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $expired, $this->register_response( 'st_new' ), $this->verify_ok_response() ) );

		$first = $site->ensure_registered();
		$this->assertSame( 'babilim_expired', $first->get_error_code() );
		$this->assertNull( $site->site_id() );

		// A second call starts a genuinely fresh registration.
		$site->ensure_registered();
		$this->assertSame( 'st_new', $site->site_id() );
	}

	public function test_verify_invalid_proof_keeps_the_pending_challenge_for_retry(): void {
		update_option( 'babilim_consent', true );
		$invalid = $this->json_response( 401, array( 'error' => array( 'code' => 'invalid_proof', 'message' => 'x', 'hint' => '' ) ) );
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $invalid ) );

		$site->ensure_registered();

		$this->assertNotNull( get_option( 'babilim_pending_verification', null ) );
	}

	/**
	 * Reproduces the stuck scenario a plain `invalid_nonce` (with nothing
	 * bounding it) used to leave this site in forever: each
	 * ensure_registered() call re-registers and gets `invalid_nonce`
	 * again. After MAX_VERIFY_ATTEMPTS consecutive failures this site
	 * must stop retrying automatically, set a visible
	 * verification_stalled() flag, and make zero further requests --
	 * never loop indefinitely and never go silently dead with no signal.
	 */
	public function test_repeated_invalid_nonce_eventually_stalls_with_a_visible_flag_and_zero_further_requests(): void {
		update_option( 'babilim_consent', true );
		$invalid = $this->json_response( 400, array( 'error' => array( 'code' => 'invalid_nonce', 'message' => 'x', 'hint' => 'y' ) ) );
		list( $site, $http ) = $this->make_site(
			array(
				$this->register_response( 'st_1' ),
				$invalid,
				$this->register_response( 'st_2' ),
				$invalid,
				$this->register_response( 'st_3' ),
				$invalid,
			)
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse( $site->verification_stalled(), "must not be stalled before attempt {$i} exhausts the retry cap" );
			$result = $site->ensure_registered();
			$this->assertSame( 'babilim_invalid_nonce', $result->get_error_code() );
		}
		$this->assertTrue( $site->verification_stalled() );
		$this->assertCount( 6, $http->requests );

		$stalled_result = $site->ensure_registered();

		$this->assertTrue( is_wp_error( $stalled_result ) );
		$this->assertCount( 6, $http->requests, 'a stalled site must make zero further requests' );
	}

	public function test_a_successful_verify_resets_the_stalled_flag(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_stalled', true );
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		// A stalled site waits for the owner's restart action.
		$site->restart_registration();

		$this->assertFalse( $site->verification_stalled() );
	}

	/**
	 * A successful verify must also reset the attempts counter itself,
	 * not just the stalled flag -- otherwise a site that failed once or
	 * twice, then genuinely recovered, would still be partway through its
	 * retry budget the next time a challenge legitimately goes wrong,
	 * reaching the stalled state after fewer failures than
	 * MAX_VERIFY_ATTEMPTS actually allows.
	 */
	public function test_a_successful_verify_resets_the_attempts_counter(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_attempts', 2 );
		list( $site, $http ) = $this->make_site( array( $this->register_response(), $this->verify_ok_response() ) );

		$site->ensure_registered();

		$this->assertNull( get_option( 'babilim_verify_attempts', null ) );
	}

	// -- restart_registration() ------------------------------------------

	public function test_restart_registration_clears_the_stalled_flag_on_a_successful_fresh_attempt(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_stalled', true );
		update_option( 'babilim_site_id', 'st_old' );
		list( $site, $http ) = $this->make_site( array( $this->register_response( 'st_new' ), $this->verify_ok_response() ) );

		$result = $site->restart_registration();

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertFalse( $site->verification_stalled() );
		$this->assertSame( 'st_new', $site->site_id() );
		$this->assertSame( 2, count( $http->requests ), 'must make a genuinely fresh register()+verify() attempt' );
	}

	public function test_restart_registration_clears_the_conflict_flag_on_a_successful_fresh_attempt(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_conflict', true );
		update_option( 'babilim_site_id', 'st_old' );
		list( $site ) = $this->make_site( array( $this->register_response( 'st_new' ), $this->verify_ok_response() ) );

		$site->restart_registration();

		$this->assertFalse( $site->needs_token_rotation() );
	}

	/**
	 * The flag is only cleared because the fresh attempt genuinely
	 * succeeded -- if the service still refuses it the same way, the flag
	 * must come right back rather than restart_registration() leaving it
	 * cleared just because it was the caller that reset it.
	 */
	public function test_restart_registration_does_not_leave_the_conflict_flag_cleared_if_it_conflicts_again(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_conflict', true );
		update_option( 'babilim_site_id', 'st_old' );
		$conflict = $this->json_response( 409, array( 'error' => array( 'code' => 'conflict', 'message' => 'x', 'hint' => 'y' ) ) );
		list( $site ) = $this->make_site( array( $this->register_response( 'st_new' ), $conflict ) );

		$result = $site->restart_registration();

		$this->assertSame( 'babilim_conflict', $result->get_error_code() );
		$this->assertTrue( $site->needs_token_rotation() );
	}

	public function test_restart_registration_clears_any_stale_pending_challenge(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_stalled', true );
		update_option( 'babilim_site_id', 'st_old' );
		update_option( 'babilim_pending_verification', array( 'site_id' => 'st_old', 'nonce' => 'deadbeefdeadbeef', 'expires_at' => 'x' ) );
		list( $site, $http ) = $this->make_site( array( $this->register_response( 'st_new', 'fedcba9876543210' ), $this->verify_ok_response() ) );

		$site->restart_registration();

		$this->assertStringContainsString( '/sites/st_new/verify', $http->requests[1]['url'] );
	}

	/**
	 * Without consent, restart_registration() must behave exactly like
	 * ensure_registered() -- zero requests, and (unlike the pre-fix
	 * behavior) zero option changes: a stray click on the recovery button
	 * before consent was ever given must not silently wipe the
	 * stalled/conflict flags or the site id.
	 */
	public function test_restart_registration_without_consent_makes_zero_requests_and_changes_nothing(): void {
		update_option( 'babilim_verify_stalled', true );
		update_option( 'babilim_site_id', 'st_old' );
		list( $site, $http ) = $this->make_site( array( $this->register_response( 'st_new' ), $this->verify_ok_response() ) );

		$result = $site->restart_registration();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_no_consent', $result->get_error_code() );
		$this->assertCount( 0, $http->requests );
		$this->assertTrue( $site->verification_stalled(), 'the stalled flag must not be wiped by a no-consent attempt' );
		$this->assertSame( 'st_old', get_option( 'babilim_site_id' ), 'a kept site id must not be wiped by a no-consent attempt' );
	}

	/**
	 * A stalled/conflicted site that still holds a working site_token
	 * (e.g. restart_registration() is invoked for some other reason while
	 * registration is actually fine) must use the contract's own
	 * documented recovery -- rotate-token -- rather than abandon a
	 * perfectly good registration and create a second one server-side.
	 */
	public function test_restart_registration_rotates_the_token_when_one_is_already_held(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_old' );
		update_option( 'babilim_site_token', 'tok_old' );
		update_option( 'babilim_verify_conflict', true );
		$rotate_response = $this->json_response( 200, array( 'site_token' => 'tok_new', 'rotated_at' => '2026-01-01T00:00:00Z' ) );
		list( $site, $http ) = $this->make_site( array( $rotate_response ) );

		$result = $site->restart_registration();

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertCount( 1, $http->requests, 'must call rotate-token, not register()+verify()' );
		$this->assertStringContainsString( '/sites/st_old/rotate-token', $http->requests[0]['url'] );
		$this->assertSame( 'tok_new', $site->token() );
		$this->assertSame( 'st_old', $site->site_id(), 'rotate-token keeps the same site id' );
		$this->assertFalse( $site->needs_token_rotation() );
	}

	/**
	 * A failed rotate-token attempt (network error here) must leave every
	 * flag and the old token exactly as they were -- the recovery notice
	 * must keep showing so the site owner can retry, not silently
	 * disappear because the attempt itself failed.
	 */
	public function test_restart_registration_rotate_failure_preserves_flags_and_the_old_token(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_old' );
		update_option( 'babilim_site_token', 'tok_old' );
		update_option( 'babilim_verify_conflict', true );
		list( $site, $http ) = $this->make_site( array() ); // No queued response -> exhausted.

		$result = $site->restart_registration();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_fake_http_exhausted', $result->get_error_code() );
		$this->assertTrue( $site->needs_token_rotation(), 'the conflict flag must survive a failed rotate attempt' );
		$this->assertSame( 'tok_old', $site->token(), 'the old token must be left in place on a failed rotate attempt' );
		$this->assertSame( 'st_old', $site->site_id() );
	}

	/**
	 * A failed fresh registration attempt (network error during
	 * register(), before any new token/flags could possibly be obtained)
	 * must not clear the stalled flag -- the recovery notice must keep
	 * showing.
	 */
	public function test_restart_registration_fresh_attempt_network_failure_preserves_the_stalled_flag(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_stalled', true );
		update_option( 'babilim_site_id', 'st_old' );
		list( $site, $http ) = $this->make_site( array() ); // No queued response -> exhausted.

		$result = $site->restart_registration();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_fake_http_exhausted', $result->get_error_code() );
		$this->assertTrue( $site->verification_stalled(), 'the stalled flag must survive a failed fresh-registration attempt' );
		// site_id must only ever be replaced
		// once a NEW registration genuinely succeeds -- a failed attempt
		// (register() itself never returned) must leave it exactly as
		// it was.
		$this->assertSame( 'st_old', get_option( 'babilim_site_id' ), 'a kept site id must survive a failed fresh-registration attempt' );
	}

	/**
	 * Same as above, for the conflict flag -- a stalled/conflicted site
	 * with no token yet (the ordinary case the recovery button exists
	 * for) that fails again on a fresh attempt must keep showing the
	 * recovery notice, not silently clear it just because an attempt was
	 * made.
	 */
	public function test_restart_registration_fresh_attempt_network_failure_preserves_the_conflict_flag(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_conflict', true );
		update_option( 'babilim_site_id', 'st_old' );
		list( $site, $http ) = $this->make_site( array() ); // No queued response -> exhausted.

		$result = $site->restart_registration();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertTrue( $site->needs_token_rotation(), 'the conflict flag must survive a failed fresh-registration attempt' );
		$this->assertSame( 'st_old', get_option( 'babilim_site_id' ), 'a kept site id must survive a failed fresh-registration attempt' );
	}

	/**
	 * The other half of the same rule: a fresh registration that genuinely
	 * succeeds still must end up with the NEW site_id in place (not
	 * stuck on the old one) -- the fix isn't "never touch site_id", it's
	 * "only touch it once success is certain".
	 */
	public function test_restart_registration_fresh_attempt_success_replaces_the_old_site_id(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_verify_stalled', true );
		update_option( 'babilim_site_id', 'st_old' );
		list( $site, $http ) = $this->make_site( array( $this->register_response( 'st_new' ), $this->verify_ok_response() ) );

		$result = $site->restart_registration();

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'st_new', $site->site_id() );
	}

	// -- challenge_response() --------------------------------------------

	private function register_pending( string $site_id, string $nonce ): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', $site_id );
		update_option( 'babilim_install_sk', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
		update_option( 'babilim_pending_verification', array( 'site_id' => $site_id, 'nonce' => $nonce, 'expires_at' => 'x' ) );
	}

	public function test_challenge_response_rejects_non_hex_nonce(): void {
		list( $site ) = $this->make_site();

		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => 'not-hex!!' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_nonce', $response->get_data()['error']['code'] );
	}

	public function test_challenge_response_rejects_a_nonce_that_is_not_pending(): void {
		$this->register_pending( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' );
		list( $site ) = $this->make_site();

		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => str_repeat( 'a', 32 ) ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_challenge_response_returns_a_valid_proof_for_the_pending_nonce(): void {
		$this->register_pending( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' );
		list( $site ) = $this->make_site();

		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a', $data['nonce'] );

		$sk        = base64_decode( get_option( 'babilim_install_sk' ), true );
		$pub       = sodium_crypto_sign_publickey_from_secretkey( $sk );
		$signature = base64_decode( substr( $data['proof'], 4 ), true );
		$this->assertTrue( sodium_crypto_sign_verify_detached( $signature, "babilim-challenge-v1\nst_x\n9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a", $pub ) );
	}

	public function test_challenge_response_never_answers_with_the_secret_key(): void {
		$this->register_pending( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' );
		list( $site ) = $this->make_site();

		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' ) ) );

		$sk = base64_decode( get_option( 'babilim_install_sk' ), true );
		$this->assertStringNotContainsString( base64_encode( $sk ), (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_challenge_response_self_throttles_at_ten_per_minute(): void {
		$this->register_pending( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' );
		list( $site ) = $this->make_site();

		for ( $i = 0; $i < 10; $i++ ) {
			$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' ) ) );
			$this->assertSame( 200, $response->get_status() );
		}

		$eleventh = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' ) ) );
		$this->assertSame( 429, $eleventh->get_status() );
	}

	public function test_challenge_rate_limit_resets_after_its_window_elapses(): void {
		$this->register_pending( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' );
		list( $site ) = $this->make_site();
		// A stale bucket from over a minute ago, already at the cap under
		// the old "refresh the TTL on every hit" design -- a fixed window
		// must treat this as expired and start a fresh one, not still
		// throttle on it.
		set_transient( 'babilim_challenge_rl', array( 'window_start' => time() - 61, 'count' => 10 ), MINUTE_IN_SECONDS );

		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' ) ) );

		$this->assertSame( 200, $response->get_status(), 'a stale rate-limit window from over a minute ago must not still throttle new requests' );
	}

	/**
	 * A malformed nonce must be rejected by the format check alone,
	 * before ever consuming a slot in the self-throttle -- otherwise 10
	 * garbage requests would exhaust the same budget legitimate polling
	 * needs (and, more importantly for catching a regression here: a
	 * broken format check that let malformed input fall through to the
	 * rate limiter would only reveal itself as a 429 after the 10th
	 * request, not as a same-shaped 400 that never changes).
	 */
	public function test_malformed_nonce_is_rejected_before_ever_consuming_the_rate_limit(): void {
		$this->register_pending( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' );
		list( $site ) = $this->make_site();

		for ( $i = 0; $i < 15; $i++ ) {
			$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => 'NOT-HEX!!' ) ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'invalid_nonce', $response->get_data()['error']['code'] );
		}
	}

	/**
	 * A well-formed hex nonce that simply doesn't match the pending one
	 * (or matches nothing, because nothing is pending) must ALSO never
	 * consume a slot in the self-throttle -- unlike the malformed-format
	 * case above, this is exactly the shape a real attacker would send
	 * (any random 32-hex-char string passes is_valid_nonce_format()),
	 * and it's the one the original design got wrong: rate-limiting on
	 * this class of request first would let 10 garbage-but-well-formed
	 * nonces a minute exhaust the same budget the service's own real
	 * callback needs, stalling verification and requiring a manual
	 * restart_registration(). 15 non-matching attempts here must all
	 * still see the same `invalid_nonce` 400 -- never a 429 -- and the
	 * budget must still be fully available for the one real request
	 * right after.
	 */
	public function test_a_non_matching_well_formed_nonce_never_consumes_the_rate_limit_either(): void {
		$this->register_pending( 'st_x', '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' );
		list( $site ) = $this->make_site();

		for ( $i = 0; $i < 15; $i++ ) {
			$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => str_repeat( (string) ( $i % 10 ), 32 ) ) ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'invalid_nonce', $response->get_data()['error']['code'] );
		}

		$real = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' ) ) );
		$this->assertSame( 200, $real->get_status(), 'the real pending nonce must still get through -- the rate-limit budget must not have been spent on the 15 non-matching attempts above' );
	}

	public function test_challenge_response_rejects_a_non_string_nonce_without_a_type_error(): void {
		list( $site ) = $this->make_site();

		// WP_REST_Request hands back an array for `?nonce[]=x`; casting
		// that with `(string)` would emit an "Array to string conversion"
		// warning on PHP 8 instead of failing closed cleanly.
		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => array( 'x' ) ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_nonce', $response->get_data()['error']['code'] );
	}

	public function test_challenge_response_returns_400_without_generating_a_keypair_when_none_exists(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_pending_verification', array( 'site_id' => 'st_x', 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a', 'expires_at' => 'x' ) );
		// Deliberately no babilim_install_sk stored -- the endpoint must
		// only ever READ a keypair that already exists (see
		// stored_secret_key()), never mint one for a nonce that was never
		// legitimately issued to this site.
		list( $site ) = $this->make_site();

		$response = $site->challenge_response( new WP_REST_Request( 'GET', '', array( 'nonce' => '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( get_option( 'babilim_install_sk', false ), 'the challenge endpoint must never generate a keypair on the fly' );
	}

	// -- sync() -----------------------------------------------------------

	public function test_sync_sends_installed_map_and_drains_client_events(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );

		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_a1b2c3d4e5f6', 'files' => array(), 'integrity' => array( 'ok' => false, 'checked_at' => '2026-01-01T00:00:00Z' ) ) );

		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);

		list( $site, $http, $state ) = $this->make_site( array( $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) ) ), $manifest, $inventory );

		$site->sync();

		$this->assertCount( 1, $http->requests );
		$body = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayHasKey( 'client_events', $body );
		$this->assertSame( 'integrity_failed', $body['client_events'][0]['code'] );
		$this->assertSame( array(), $state->drain_client_events(), 'sync() must drain the buffer it already reported, not leave it to be reported twice' );
	}

	public function test_sync_flags_plugin_outdated_on_426(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		$outdated = $this->json_response( 426, array( 'error' => array( 'code' => 'plugin_outdated', 'message' => 'x', 'hint' => 'y' ) ) );
		list( $site, $http, $state ) = $this->make_site( array( $outdated ) );

		$site->sync();

		$this->assertTrue( $state->get()['plugin_outdated'] );
	}

	/**
	 * A 429 from POST /v1/sites/{id}/inventory (Babilim_Api_Client's own
	 * `babilim_rate_limited` WP_Error, carrying `retry_after` seconds from
	 * the response's Retry-After header -- see class-babilim-api-client.php's
	 * error_from_response()) must leave a trace a site owner can actually
	 * see, the same way a 426 already does for plugin_outdated. Before this
	 * fix, sync() silently `return`ed on ANY inventory WP_Error other than
	 * plugin_outdated, so a rate-limited sync looked identical to a
	 * transient network failure -- no state, no admin notice, nothing.
	 */
	public function test_sync_records_when_inventory_is_rate_limited(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );
		$rate_limited = $this->json_response(
			429,
			array( 'error' => array( 'code' => 'rate_limited', 'message' => 'too many requests', 'hint' => '' ) ),
			array( 'retry-after' => '42' )
		);
		list( $site, $http, $state ) = $this->make_site( array( $rate_limited ) );

		$before = time();
		$site->sync();
		$after = time();

		$until = $state->get()['rate_limited_until'] ?? null;
		$this->assertTrue( is_int( $until ), 'sync() must record when a rate-limited inventory sync may be retried' );
		$this->assertGreaterThanOrEqual( $before + 42, $until );
		$this->assertLessThanOrEqual( $after + 42, $until );
		$this->assertSame( $until, $site->rate_limited_until(), 'a timestamp still in the future must be reported as the current rate-limit deadline' );
	}

	/**
	 * rate_limited_until() itself: null before any rate limiting has
	 * happened, the stored timestamp while it's still in the future, and
	 * null again once that timestamp has passed -- mirrors
	 * verification_stalled()/needs_token_rotation()'s own shape.
	 */
	public function test_rate_limited_until_reads_back_a_future_timestamp_and_ignores_a_past_one(): void {
		list( $site, , $state ) = $this->make_site();
		$this->assertNull( $site->rate_limited_until() );

		$future = time() + 100;
		$state->set( array_merge( $state->get(), array( 'rate_limited_until' => $future ) ) );
		$this->assertSame( $future, $site->rate_limited_until() );

		$past = time() - 100;
		$state->set( array_merge( $state->get(), array( 'rate_limited_until' => $past ) ) );
		$this->assertNull( $site->rate_limited_until() );
	}

	/**
	 * The contract's `installed` is a required JSON *object* (keyed by
	 * locale), even when a component has no Babilim-installed locale yet
	 * -- a plain PHP empty array json_encode()s to `[]`, which the
	 * service's pydantic model rejects with `422 validation_error` (see
	 * docs/api-contract.md). json_decode(..., true) can't tell `{}` and
	 * `[]` apart once decoded, so this asserts on the raw JSON string.
	 */
	public function test_sync_encodes_an_empty_installed_map_as_a_json_object_not_an_array(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );

		// No manifest records at all -- installed_map_for() returns an
		// empty PHP array for the only component.
		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);

		list( $site, $http ) = $this->make_site( array( $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) ) ), null, $inventory );

		$site->sync();

		$raw = $http->requests[0]['args']['body'];
		$this->assertStringContainsString( '"installed":{}', $raw, 'an empty installed map must encode as a JSON object, not []' );
	}

	public function test_sync_preserves_client_events_when_the_request_fails(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );

		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_a1b2c3d4e5f6', 'files' => array(), 'integrity' => array( 'ok' => false, 'checked_at' => '2026-01-01T00:00:00Z' ) ) );

		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);

		$failure = $this->json_response( 500, array( 'error' => array( 'code' => 'unexpected', 'message' => 'x', 'hint' => '' ) ) );

		list( $site, $http, $state ) = $this->make_site( array( $failure ), $manifest, $inventory );

		$site->sync();

		$this->assertNotEmpty( $state->drain_client_events(), 'a failed sync must not have discarded the events it tried to report' );
	}

	/**
	 * A degraded record (served files removed after a failed check) is
	 * still reported, with its own build id, as failed -- so the service
	 * sees the failure -- but this sync adds no second client event: the
	 * one for the degrade was recorded when it happened.
	 */
	public function test_sync_reports_a_degraded_record_as_failed_without_a_new_client_event(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );

		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_a1b2c3d4e5f6', 'files' => array(), 'default_files' => array(), 'degraded_at' => '2026-01-02T00:00:00Z', 'integrity' => array( 'ok' => false, 'checked_at' => '2026-01-02T00:00:00Z' ) ) );

		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$ok = $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) );

		list( $site, $http, $state ) = $this->make_site( array( $ok, $ok ), $manifest, $inventory );
		$site->sync();
		$site->sync();

		foreach ( $http->requests as $request ) {
			$body = json_decode( $request['args']['body'], true );
			$this->assertSame( 'failed', $body['components'][0]['installed']['de_DE']['integrity'] );
			$this->assertSame( 'bd_a1b2c3d4e5f6', $body['components'][0]['installed']['de_DE']['build_id'] );
			$this->assertArrayNotHasKey( 'client_events', $body );
		}
		$this->assertSame( array(), $state->drain_client_events() );
	}

	/**
	 * A manifest record with no integrity data at all has never actually
	 * been checked -- reporting it as "ok" would claim a verification
	 * that never happened (see installed_map_for()).
	 */
	public function test_sync_reports_a_record_with_no_integrity_data_as_failed_not_ok(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );

		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		// installed_at IS present (as a real install() call always sets
		// it) -- only the integrity check itself never ran, which is the
		// specific gap this test covers. See the sibling test below for
		// the (rarer) case where even installed_at is missing.
		$manifest->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_a1b2c3d4e5f6', 'files' => array(), 'installed_at' => '2026-01-01T00:00:00Z' ) );

		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);

		list( $site, $http ) = $this->make_site( array( $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) ) ), $manifest, $inventory );

		$site->sync();

		$body      = json_decode( $http->requests[0]['args']['body'], true );
		$installed = $body['components'][0]['installed']['de_DE'];
		$this->assertSame( 'failed', $installed['integrity'], 'a record with no integrity check on file must never be reported as ok' );
		$this->assertSame( '2026-01-01T00:00:00Z', $installed['verified_at'], 'must fall back to the record\'s real installed_at, not fabricate one' );
	}

	/**
	 * A record with neither a genuine integrity check NOR an installed_at
	 * has no real timestamp this plugin could honestly report as
	 * `verified_at` -- rather than claim one happened just now
	 * (`gmdate('c')`), the whole locale entry is left out of the
	 * `installed` map this sync sends, exactly the way a record missing
	 * its locale/build_id already is.
	 */
	public function test_sync_omits_a_record_with_no_timestamp_at_all_from_the_installed_map(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );

		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_a1b2c3d4e5f6', 'files' => array() ) );

		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);

		list( $site, $http ) = $this->make_site( array( $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) ) ), $manifest, $inventory );

		$site->sync();

		$body = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'de_DE', $body['components'][0]['installed'], 'a record with no real timestamp at all must never fabricate one with gmdate()' );
	}

	/**
	 * A record with no timestamp is omitted from the installed map (see
	 * the sibling test above), but if it's also never been integrity-
	 * checked, that failure is still real and still worth reporting --
	 * this must not be silently swallowed along with the map entry.
	 */
	public function test_sync_still_records_integrity_failed_for_a_record_omitted_for_lacking_a_timestamp(): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_x' );
		update_option( 'babilim_site_token', 'tok_x' );

		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set( 'plugin', 'a', 'de_DE', array( 'build_id' => 'bd_a1b2c3d4e5f6', 'files' => array() ) );

		$inventory = new Babilim_Inventory(
			static function () { return array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0', 'TextDomain' => 'a' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);

		list( $site, $http ) = $this->make_site( array( $this->json_response( 200, array( 'components' => array(), 'updates' => array(), 'license' => array(), 'quota' => array(), 'features' => array(), 'contract' => '1.1.1' ) ) ), $manifest, $inventory );

		$site->sync();

		$body = json_decode( $http->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'de_DE', $body['components'][0]['installed'], 'still omitted -- no real timestamp to report (see the sibling test)' );
		$this->assertArrayHasKey( 'client_events', $body, 'the integrity failure must not be swallowed along with the omitted map entry' );
		$this->assertSame( 'integrity_failed', $body['client_events'][0]['code'] );
		$this->assertSame( 'de_DE', $body['client_events'][0]['locale'] );
	}
}
