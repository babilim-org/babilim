<?php
/**
 * Tests for the "verification required" state of Babilim_Site: the
 * service could not reach this site's challenge endpoint, so no token
 * exists and nothing may be downloaded or installed. Covers what the
 * state stores, how it retries (with backoff, never on every page load),
 * and how it stays clear of the expired/invalid_nonce re-registration
 * loop and its stalled cap.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Site_Verification extends TestCase {

	private const NONCE = '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a';

	protected function setUp(): void {
		babilim_test_reset();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		update_option( 'babilim_consent', true );
	}

	private function make_site( array $responses ): array {
		$http      = new Babilim_Fake_Http( $responses );
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$state = new Babilim_State();
		$site  = new Babilim_Site( $http, 'https://api.babilim.org', $state, $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );
		return array( $site, $http, $state );
	}

	private function json( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	private function registered( string $site_id = 'st_new', ?string $expires = null ): array {
		$expires = $expires ?? gmdate( 'Y-m-d\TH:i:s\Z', time() + 900 );
		return $this->json( 201, array( 'site_id' => $site_id, 'challenge' => array( 'nonce' => self::NONCE, 'expires_at' => $expires ) ) );
	}

	private function verification_required( ?string $reason ): array {
		$error = array( 'code' => 'verification_required', 'message' => 'callback failed', 'hint' => 'allow the challenge endpoint' );
		if ( null !== $reason ) {
			$error['reason'] = $reason;
		}
		return $this->json( 403, array( 'error' => $error ) );
	}

	/** A schema-valid site token derived from a readable name. */
	private function tok( string $name ): string {
		return 'tok_' . substr( md5( $name ), 0, 32 );
	}

	/**
	 * A 200 verify answer shaped exactly like the contract's
	 * (schemas/sites.verify.response.schema.json), checked against it.
	 */
	private function verified( string $token = 'tok_fresh' ): array {
		$body   = array(
			'site_token' => $this->tok( $token ),
			'verified'   => true,
			'license'    => array( 'kind' => 'free', 'status' => 'active' ),
			'quota'      => array(
				'free_slots' => array( 'theme' => array( 'used' => 0, 'limit' => 1 ), 'plugin' => array( 'used' => 0, 'limit' => 2 ) ),
				'downloads'  => array( 'used' => 0, 'limit' => 500, 'period' => 'daily' ),
			),
		);
		$schema = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/contract/v1/schemas/sites.verify.response.schema.json' ), true );
		$common = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/contract/v1/schemas/common.schema.json' ), true );
		$this->assertIsArray( $schema );
		$this->assertIsArray( $common );
		// The validator resolves only local refs, so the two shared
		// definitions are inlined next to their siblings.
		$schema['$defs']                   = $common['$defs'];
		$schema['properties']['license']   = array( '$ref' => '#/$defs/license' );
		$schema['properties']['quota']     = array( '$ref' => '#/$defs/quota' );
		$errors = Babilim_Schema_Validator::validate( json_decode( (string) wp_json_encode( $body ) ), $schema );
		$this->assertSame( array(), $errors, 'the verify double must match the contract' );
		return $this->json( 200, $body );
	}

	/** Puts the site into the warning state as if a verify already failed. */
	private function seed_warning( string $reason, int $next_retry_at, string $pending_expires, string $site_id = 'st_old' ): void {
		update_option( 'babilim_install_sk', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
		update_option( 'babilim_site_id', $site_id );
		update_option( 'babilim_pending_verification', array( 'site_id' => $site_id, 'nonce' => self::NONCE, 'expires_at' => $pending_expires ) );
		update_option(
			Babilim_Site::OPTION_VERIFICATION_WARNING,
			array( 'reason' => $reason, 'since' => time() - 3600, 'retries' => 1, 'next_retry_at' => $next_retry_at )
		);
	}

	// -- what a failed verify stores ------------------------------------

	public function test_each_known_reason_is_stored_and_no_token_is_kept(): void {
		foreach ( array( 'callback_unreachable', 'callback_timeout', 'callback_invalid' ) as $reason ) {
			babilim_test_reset();
			update_option( 'babilim_consent', true );
			list( $site ) = $this->make_site( array( $this->registered(), $this->verification_required( $reason ) ) );

			$result = $site->ensure_registered();

			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'babilim_verification_required', $result->get_error_code() );
			$warning = $site->verification_warning();
			$this->assertNotNull( $warning, $reason );
			$this->assertSame( $reason, $warning['reason'] );
			$this->assertNull( $site->token(), 'a token must never exist in this state (' . $reason . ')' );
			$this->assertFalse( get_option( 'babilim_site_token', false ) );
		}
	}

	public function test_an_unknown_or_missing_reason_is_stored_as_the_generic_value(): void {
		foreach ( array( 'something_new', '<script>', null ) as $reason ) {
			babilim_test_reset();
			update_option( 'babilim_consent', true );
			list( $site ) = $this->make_site( array( $this->registered(), $this->verification_required( $reason ) ) );

			$site->ensure_registered();

			$this->assertSame( Babilim_Site::REASON_UNKNOWN, $site->verification_warning()['reason'] );
		}
	}

	public function test_a_200_that_is_not_verified_true_is_a_warning_and_never_a_token(): void {
		$unverified = $this->json( 200, array( 'site_token' => 'tok_should_not_be_kept', 'verified' => false, 'license' => null, 'quota' => array() ) );
		list( $site ) = $this->make_site( array( $this->registered(), $unverified ) );

		$result = $site->ensure_registered();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertNull( $site->token() );
		$this->assertFalse( get_option( 'babilim_site_token', false ) );
		$this->assertSame( Babilim_Site::REASON_UNKNOWN, $site->verification_warning()['reason'] );
	}

	// -- interaction with the expired/invalid_nonce machinery -------------

	public function test_a_reason_failure_keeps_site_id_and_pending_and_never_counts_an_attempt(): void {
		list( $site ) = $this->make_site( array( $this->registered(), $this->verification_required( 'callback_unreachable' ) ) );

		$site->ensure_registered();

		$pending = get_option( 'babilim_pending_verification' );
		$this->assertSame( 'st_new', $pending['site_id'], 'the challenge must not be dropped (that is the expired/invalid_nonce loop)' );
		$this->assertSame( self::NONCE, $pending['nonce'], 'the unconsumed nonce must be kept for a retry' );
		$this->assertSame( 0, (int) get_option( 'babilim_verify_attempts', 0 ) );
		$this->assertFalse( $site->verification_stalled() );
	}

	public function test_repeated_reason_failures_never_stall_and_never_delete_the_site_id(): void {
		list( $site, $http ) = $this->make_site( array( $this->registered(), $this->verification_required( 'callback_unreachable' ) ) );
		$site->ensure_registered();

		for ( $i = 0; $i < 6; $i++ ) {
			$warning                  = get_option( Babilim_Site::OPTION_VERIFICATION_WARNING );
			$warning['next_retry_at'] = 1; // Backoff elapsed.
			update_option( Babilim_Site::OPTION_VERIFICATION_WARNING, $warning );
			$site->ensure_registered();
		}

		$this->assertFalse( $site->verification_stalled(), 'reason failures are not counted toward the stalled cap' );
		$this->assertSame( 0, (int) get_option( 'babilim_verify_attempts', 0 ) );
		$this->assertNotFalse( get_option( 'babilim_pending_verification', false ) );
		$this->assertNotNull( $site->verification_warning() );
		$this->assertGreaterThanOrEqual( 7, count( $http->requests ) );
	}

	// -- retries: backoff, same nonce, fresh registration after expiry ---

	public function test_inside_the_backoff_window_ensure_registered_makes_zero_requests(): void {
		$this->seed_warning( 'callback_unreachable', time() + 600, gmdate( 'Y-m-d\TH:i:s\Z', time() + 600 ) );
		list( $site, $http ) = $this->make_site( array( $this->verified() ) );

		$result = $site->ensure_registered();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_verification_required', $result->get_error_code() );
		$this->assertCount( 0, $http->requests );
	}

	public function test_after_the_backoff_the_same_unexpired_nonce_is_retried_without_re_registering(): void {
		$this->seed_warning( 'callback_unreachable', time() - 1, gmdate( 'Y-m-d\TH:i:s\Z', time() + 600 ) );
		list( $site, $http ) = $this->make_site( array( $this->verified( 'tok_ok' ) ) );

		$result = $site->ensure_registered();

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 1, $http->requests );
		$this->assertStringContainsString( '/v1/sites/st_old/verify', $http->requests[0]['url'] );
		$this->assertStringContainsString( self::NONCE, (string) $http->requests[0]['args']['body'] );
		$this->assertSame( $this->tok( 'tok_ok' ), $site->token() );
		$this->assertNull( $site->verification_warning(), 'a successful verify clears the warning' );
	}

	public function test_an_expired_pending_challenge_is_replaced_by_a_fresh_registration_without_counting(): void {
		$this->seed_warning( 'callback_timeout', time() - 1, gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ) );
		list( $site, $http ) = $this->make_site( array( $this->registered( 'st_fresh' ), $this->verified( 'tok_ok' ) ) );

		$result = $site->ensure_registered();

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertStringContainsString( '/v1/sites', $http->requests[0]['url'] );
		$this->assertStringNotContainsString( '/verify', $http->requests[0]['url'], 'an expired nonce is never sent' );
		$this->assertSame( 'st_fresh', $site->site_id() );
		$this->assertSame( 0, (int) get_option( 'babilim_verify_attempts', 0 ) );
	}

	public function test_a_failed_retry_pushes_the_next_retry_out_with_growing_backoff_capped_at_twelve_hours(): void {
		list( $site ) = $this->make_site( array( $this->registered(), $this->verification_required( 'callback_unreachable' ) ) );
		$site->ensure_registered();
		$gaps = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$warning                  = get_option( Babilim_Site::OPTION_VERIFICATION_WARNING );
			$gaps[]                   = $warning['next_retry_at'] - time();
			$warning['next_retry_at'] = 1;
			update_option( Babilim_Site::OPTION_VERIFICATION_WARNING, $warning );
			$site->ensure_registered();
		}

		$this->assertLessThan( $gaps[2], $gaps[0], 'backoff grows' );
		$this->assertLessThanOrEqual( 12 * 3600, max( $gaps ) );
		$this->assertGreaterThan( 11 * 3600, max( $gaps ), 'and reaches the cap' );
		$this->assertGreaterThan( 0, min( $gaps ) );
	}

	public function test_sync_in_the_warning_state_makes_zero_requests_and_installs_nothing(): void {
		$this->seed_warning( 'callback_invalid', time() + 600, gmdate( 'Y-m-d\TH:i:s\Z', time() + 600 ) );
		list( $site, $http ) = $this->make_site( array( $this->json( 200, array() ) ) );

		$site->sync();

		$this->assertCount( 0, $http->requests );
	}

	// -- manual re-verify -------------------------------------------------

	public function test_reverify_ignores_the_backoff_window(): void {
		$this->seed_warning( 'callback_unreachable', time() + 3600, gmdate( 'Y-m-d\TH:i:s\Z', time() + 600 ) );
		list( $site, $http ) = $this->make_site( array( $this->verified( 'tok_manual' ) ) );

		$result = $site->reverify();

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 1, $http->requests );
		$this->assertSame( $this->tok( 'tok_manual' ), $site->token() );
		$this->assertNull( $site->verification_warning() );
	}

	public function test_reverify_without_consent_makes_zero_requests(): void {
		delete_option( 'babilim_consent' );
		list( $site, $http ) = $this->make_site( array( $this->verified() ) );

		$result = $site->reverify();

		$this->assertSame( 'babilim_no_consent', $result->get_error_code() );
		$this->assertCount( 0, $http->requests );
	}

	public function test_reverify_on_a_healthy_site_makes_zero_requests(): void {
		update_option( 'babilim_site_id', 'st_ok' );
		update_option( 'babilim_site_token', 'tok_ok' );
		list( $site, $http ) = $this->make_site( array( $this->verified() ) );

		$result = $site->reverify();

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 0, $http->requests );
	}

	// -- defensive handling: the contract lists inventory only as 200 ----

	/**
	 * Defensive, not contract behavior: the contract answers an unverified
	 * site's inventory with a 200 and the free tier, never a 403. If a
	 * service ever did answer 403 verification_required there, the plugin
	 * must still stop asking instead of hammering it.
	 */
	public function test_defensive_an_unexpected_inventory_403_verification_required_records_the_warning_and_is_not_retried_inside_backoff(): void {
		update_option( 'babilim_site_id', 'st_ok' );
		update_option( 'babilim_site_token', 'tok_ok' );
		list( $site, $http ) = $this->make_site( array( $this->verification_required( null ) ) );

		$site->sync();
		$this->assertCount( 1, $http->requests );
		$this->assertSame( Babilim_Site::REASON_UNKNOWN, $site->verification_warning()['reason'] );

		$site->sync();
		$site->sync();
		$this->assertCount( 1, $http->requests, 'no request while the warning is inside its backoff window' );
	}

	public function test_a_kept_site_id_without_token_or_pending_challenge_registers_again_with_the_kept_key(): void {
		// What a default uninstall of a never-verified site leaves behind:
		// the key and site id survive, the pending challenge does not.
		$sk = base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		update_option( 'babilim_install_sk', $sk );
		update_option( 'babilim_site_id', 'st_kept' );
		list( $site, $http ) = $this->make_site( array( $this->registered( 'st_again' ), $this->verified( 'tok_again' ) ) );

		$result = $site->ensure_registered();

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertCount( 2, $http->requests, 'one registration and one verify' );
		$this->assertSame( $this->tok( 'tok_again' ), $site->token() );
		$this->assertSame( $sk, get_option( 'babilim_install_sk' ), 'the kept key is reused, never regenerated' );
	}

	public function test_a_held_token_is_replaced_only_when_the_candidate_verify_succeeds(): void {
		update_option( 'babilim_install_sk', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
		update_option( 'babilim_site_id', 'st_old' );
		update_option( 'babilim_site_token', 'tok_old' );
		update_option( Babilim_Site::OPTION_VERIFICATION_WARNING, array( 'reason' => 'unknown', 'since' => 1, 'retries' => 1, 'next_retry_at' => 1 ) );
		list( $site, $http ) = $this->make_site( array( $this->registered( 'st_candidate' ), $this->verification_required( 'callback_unreachable' ) ) );

		$failed = $site->reverify();

		$this->assertTrue( is_wp_error( $failed ) );
		$this->assertSame( 'tok_old', $site->token(), 'a failed candidate verify must not touch the held token' );
		$this->assertSame( 'st_old', $site->site_id(), 'nor the held site_id' );
		$this->assertSame( 'callback_unreachable', $site->verification_warning()['reason'] );

		list( $site2, $http2 ) = $this->make_site( array( $this->verified( 'tok_new' ) ) );
		$ok                    = $site2->reverify();

		$this->assertFalse( is_wp_error( $ok ) );
		$this->assertCount( 1, $http2->requests, 'the still-valid candidate nonce is reused' );
		$this->assertSame( $this->tok( 'tok_new' ), $site2->token() );
		$this->assertSame( 'st_candidate', $site2->site_id() );
		$this->assertNull( $site2->verification_warning() );
	}

	// -- the download / packages 403 ---------------------------------------

	public function test_note_server_unverified_records_a_generic_warning_once(): void {
		update_option( 'babilim_site_id', 'st_ok' );
		update_option( 'babilim_site_token', 'tok_ok' );
		list( $site ) = $this->make_site( array() );

		$site->note_server_unverified( '' );
		$first = $site->verification_warning();
		$site->note_server_unverified( 'callback_timeout' );

		$this->assertSame( Babilim_Site::REASON_UNKNOWN, $first['reason'] );
		$this->assertSame( 'callback_timeout', $site->verification_warning()['reason'], 'a later, more specific reason refines the stored one' );
		$this->assertSame( $first['since'], $site->verification_warning()['since'] );
	}
}
