<?php
/**
 * Tests for the single-option site identity: {site_id, token} is one
 * option written in one call, migrated once from the two legacy options,
 * and a 409 on verify is resolved against the identity read straight
 * from the database.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Identity extends TestCase {

	private const NONCE = '9c2a1b7e4d3f6b8c1e4a7d0f3b6c9e2a';

	protected function setUp(): void {
		babilim_test_reset();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		update_option( 'babilim_consent', true );
	}

	private function json( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	private function registered( string $site_id = 'st_new' ): array {
		return $this->json( 201, array( 'site_id' => $site_id, 'challenge' => array( 'nonce' => self::NONCE, 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 900 ) ) ) );
	}

	private function verified( string $token = 'tok_fresh' ): array {
		return $this->json( 200, array( 'site_token' => $token, 'verified' => true, 'license' => array( 'kind' => 'free', 'status' => 'active' ), 'quota' => array() ) );
	}

	private function conflict(): array {
		return $this->json( 409, array( 'error' => array( 'code' => 'conflict', 'message' => 'token already issued', 'hint' => '' ) ) );
	}

	private function make_site( array $responses ): array {
		$http      = new Babilim_Fake_Http( $responses );
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$site = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );
		return array( $site, $http );
	}

	public function test_finish_verify_writes_the_identity_in_one_option_write(): void {
		$writes = array();
		add_filter(
			'sanitize_option_babilim_identity',
			static function ( $value ) use ( &$writes ) {
				$writes[] = $value;
				return $value;
			}
		);
		$legacy = array();
		foreach ( array( 'babilim_site_id', 'babilim_site_token' ) as $name ) {
			add_filter(
				"sanitize_option_{$name}",
				static function ( $value ) use ( &$legacy, $name ) {
					$legacy[] = $name;
					return $value;
				}
			);
		}
		list( $site ) = $this->make_site( array( $this->registered(), $this->verified( 'tok_one' ) ) );

		$result = $site->ensure_registered();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $writes, 'site id and token are written together, in one call' );
		$this->assertSame( array( 'site_id' => 'st_new', 'token' => 'tok_one' ), $writes[0] );
		$this->assertSame( array(), $legacy, 'the legacy options are not written any more' );
		$this->assertSame( 'st_new', $site->site_id() );
		$this->assertSame( 'tok_one', $site->token() );
	}

	public function test_the_identity_option_is_not_autoloaded(): void {
		list( $site ) = $this->make_site( array( $this->registered(), $this->verified() ) );
		$site->ensure_registered();

		$this->assertFalse( $GLOBALS['babilim_test_options_autoload']['babilim_identity'] );
	}

	public function test_registration_alone_stores_only_the_pending_challenge_not_an_identity(): void {
		list( $site ) = $this->make_site( array( $this->registered(), new WP_Error( 'http_request_failed', 'boom' ) ) );

		$site->ensure_registered();

		$this->assertFalse( get_option( 'babilim_identity', false ), 'no identity before verify succeeds' );
		$this->assertFalse( get_option( 'babilim_site_id', false ) );
		$pending = get_option( 'babilim_pending_verification' );
		$this->assertSame( 'st_new', $pending['site_id'] );
		$this->assertNull( $site->site_id(), 'a pending site id is not an identity' );
	}

	public function test_the_two_legacy_options_are_migrated_once_and_removed(): void {
		update_option( 'babilim_site_id', 'st_legacy' );
		update_option( 'babilim_site_token', 'tok_legacy', false );
		list( $site ) = $this->make_site( array() );

		$this->assertSame( 'st_legacy', $site->site_id() );
		$this->assertSame( 'tok_legacy', $site->token() );
		$this->assertSame( array( 'site_id' => 'st_legacy', 'token' => 'tok_legacy' ), get_option( 'babilim_identity' ) );
		$this->assertFalse( get_option( 'babilim_site_id', false ) );
		$this->assertFalse( get_option( 'babilim_site_token', false ) );
	}

	public function test_a_legacy_site_id_without_a_token_is_no_identity_and_is_left_alone(): void {
		update_option( 'babilim_site_id', 'st_half' );
		list( $site ) = $this->make_site( array() );

		$this->assertNull( $site->site_id() );
		$this->assertNull( $site->token() );
		$this->assertFalse( get_option( 'babilim_identity', false ) );
		$this->assertSame( 'st_half', get_option( 'babilim_site_id' ), 'kept so a fresh registration can reuse it as before' );
	}

	public function test_the_new_option_wins_over_the_legacy_ones(): void {
		update_option( 'babilim_identity', array( 'site_id' => 'st_new', 'token' => 'tok_new' ), false );
		update_option( 'babilim_site_id', 'st_old' );
		update_option( 'babilim_site_token', 'tok_old', false );
		list( $site ) = $this->make_site( array() );

		$this->assertSame( 'st_new', $site->site_id() );
		$this->assertSame( 'tok_new', $site->token() );
	}

	public function test_rotate_writes_the_whole_identity_in_one_call(): void {
		update_option( 'babilim_identity', array( 'site_id' => 'st_old', 'token' => 'tok_old' ), false );
		update_option( 'babilim_verify_conflict', true );
		$writes = array();
		add_filter(
			'sanitize_option_babilim_identity',
			static function ( $value ) use ( &$writes ) {
				$writes[] = $value;
				return $value;
			}
		);
		list( $site ) = $this->make_site( array( $this->json( 200, array( 'site_token' => 'tok_rotated', 'rotated_at' => '2026-01-01T00:00:00Z' ) ) ) );

		$result = $site->restart_registration();

		$this->assertSame( array( 'site_id' => 'st_old', 'rotated' => true ), $result );
		$this->assertSame( array( array( 'site_id' => 'st_old', 'token' => 'tok_rotated' ) ), $writes );
		$this->assertSame( 'tok_rotated', $site->token() );
		$this->assertFalse( get_option( 'babilim_verify_conflict', false ) );
	}

	// -- 409 on verify --------------------------------------------------

	public function test_a_409_when_the_database_already_holds_this_site_id_and_a_token_is_success(): void {
		// Another request finished the same registration between this
		// request's register() and its verify().
		list( $site, $http ) = $this->make_site( array( $this->registered( 'st_same' ), $this->conflict() ) );
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_identity', serialize( array( 'site_id' => 'st_same', 'token' => 'tok_other_request' ) ) );

		$result = $site->ensure_registered();

		$this->assertIsArray( $result );
		$this->assertSame( 'st_same', $result['site_id'] );
		$this->assertTrue( $result['already_registered'] );
		$this->assertFalse( get_option( 'babilim_pending_verification', false ), 'the resolved challenge is cleared' );
		$this->assertCount( 2, $http->requests, 'no further request after a 409 that another request resolved' );
		$this->assertFalse( get_option( 'babilim_verify_conflict', false ) );
	}

	public function test_a_409_with_a_token_for_a_different_site_id_registers_again(): void {
		list( $site, $http ) = $this->make_site( array( $this->registered( 'st_first' ), $this->conflict(), $this->registered( 'st_second' ), $this->verified( 'tok_second' ) ) );
		$GLOBALS['wpdb']->babilim_test_upsert_row( 'babilim_identity', serialize( array( 'site_id' => 'st_unrelated', 'token' => 'tok_unrelated' ) ) );

		$result = $site->ensure_registered();

		$this->assertIsArray( $result );
		$this->assertSame( 'st_second', $result['site_id'] );
		$this->assertCount( 4, $http->requests );
		$this->assertSame( array( 'site_id' => 'st_second', 'token' => 'tok_second' ), get_option( 'babilim_identity' ) );
	}

	public function test_a_409_with_no_token_registers_again_and_resets_the_attempt_counter(): void {
		update_option( 'babilim_verify_attempts', 2 );
		list( $site, $http ) = $this->make_site( array( $this->registered( 'st_first' ), $this->conflict(), $this->registered( 'st_second' ), $this->verified( 'tok_second' ) ) );

		$result = $site->ensure_registered();

		$this->assertIsArray( $result );
		$this->assertSame( 'st_second', $result['site_id'] );
		$this->assertSame( 'tok_second', $site->token() );
		$this->assertFalse( get_option( 'babilim_verify_attempts', false ) );
		$this->assertFalse( get_option( 'babilim_verify_conflict', false ) );
	}

	public function test_each_request_gets_the_time_left_at_the_moment_it_is_sent(): void {
		$now = 1000;
		add_filter(
			'babilim_now',
			static function () use ( &$now ) {
				return $now;
			}
		);
		$http = new Babilim_Test_Clock_Http(
			array( $this->registered( 'st_one' ), $this->verified( 'tok_one' ) ),
			function () use ( &$now ) {
				$now += 9;
			}
		);
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$site = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );

		$result = $site->ensure_registered( new Babilim_Deadline( 20 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $http->requests[0]['args']['timeout'], 'register: its own cap of 10 is below the 20 left' );
		$this->assertSame( 11, $http->requests[1]['args']['timeout'], 'verify: only 11 seconds were left after the 9 register took' );
	}

	public function test_a_409_with_no_time_left_for_another_registration_is_in_progress(): void {
		list( $site, $http ) = $this->make_site( array( $this->registered( 'st_first' ), $this->conflict(), $this->registered( 'st_second' ), $this->verified( 'tok_second' ) ) );
		$deadline = new Babilim_Deadline( 0 );

		$result = $site->ensure_registered( $deadline );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_in_progress', $result->get_error_code() );
		$this->assertCount( 0, $http->requests, 'a zero budget sends nothing at all' );
	}

	public function test_a_409_that_leaves_less_than_a_step_is_in_progress_and_sends_no_further_request(): void {
		$now = 1000;
		add_filter(
			'babilim_now',
			static function () use ( &$now ) {
				return $now;
			}
		);
		// Each answered request moves the clock on by 9 seconds: register
		// (0-9) and verify (9-18) leave 2 seconds of a 20 second budget,
		// below the 3 second step minimum.
		$http = new Babilim_Test_Clock_Http(
			array( $this->registered( 'st_first' ), $this->conflict(), $this->registered( 'st_second' ), $this->verified( 'tok_second' ) ),
			function () use ( &$now ) {
				$now += 9;
			}
		);
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); }
		);
		$site     = new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );
		$deadline = new Babilim_Deadline( 20 );

		$result = $site->ensure_registered( $deadline );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'babilim_in_progress', $result->get_error_code() );
		$this->assertCount( 2, $http->requests, 'register and verify only; no third request with 2 seconds left' );
		$this->assertFalse( get_option( 'babilim_identity', false ), 'nothing is stored as an identity' );
	}
}

/**
 * Answers from a queue and runs a callback after every request, so a test
 * can move the injected clock on.
 */
final class Babilim_Test_Clock_Http implements Babilim_Http {

	/** @var array[] */
	public $requests = array();

	/** @var array */
	private $responses;

	/** @var callable */
	private $after;

	public function __construct( array $responses, callable $after ) {
		$this->responses = $responses;
		$this->after     = $after;
	}

	public function request( string $method, string $url, array $args ) {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		call_user_func( $this->after );
		return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
	}
}
