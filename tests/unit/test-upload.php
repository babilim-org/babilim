<?php
/**
 * Babilim_Upload: the upload half of "Request translation" for a
 * component the service has no source for.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Upload extends TestCase {

	private string $root;

	protected function setUp(): void {
		babilim_test_reset();
		update_option( 'babilim_consent', true );
		$this->root = BABILIM_TEST_TMP_ROOT . '/upload-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/plugins/acme', 0777, true );
		mkdir( $this->root . '/tmp', 0777, true );
		// Incompressible content, so the zip is a few parts long.
		file_put_contents( $this->root . '/plugins/acme/acme.php', "<?php\n/**\n * Plugin Name: Acme\n * Version: 1.0\n */\n" );
		file_put_contents( $this->root . '/plugins/acme/data.bin', random_bytes( 3000 ) );
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'acme',
						'locales' => array( 'fr_FR' => array( 'status' => 'queued' ) ),
					),
				),
			)
		);
	}

	protected function tearDown(): void {
		babilim_test_rmtree( $this->root );
	}

	private function json( int $code, array $body, array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => (string) wp_json_encode( $body ), 'headers' => $headers );
	}

	private function upload( Babilim_Fake_Http $http ): Babilim_Upload {
		$tmp      = $this->root . '/tmp';
		$packager = new Babilim_Packager(
			static function () use ( $tmp ) { return $tmp; },
			static function () use ( $tmp ) { return trailingslashit( $tmp ); },
			static function () { return str_repeat( 'ef', 16 ); },
			$this->root . '/plugins',
			$this->root . '/themes'
		);
		return new Babilim_Upload( new Babilim_Api_Client( $http, 'https://api.babilim.org', 'tok_x' ), $packager, new Babilim_State() );
	}

	private function offer( int $part_bytes = 1024 ): array {
		return array( 'upload_id' => 'up_9a1b2c3d4e5f', 'part_bytes' => $part_bytes, 'max_parts' => 32, 'max_bytes' => 500 * 1024 * 1024, 'expires_at' => '2099-01-01T00:00:00Z' );
	}

	private function component(): array {
		return array( 'kind' => 'plugin', 'slug' => 'acme', 'version' => '1.0', 'main_file' => 'acme/acme.php' );
	}

	private function cell(): array {
		return (array) ( new Babilim_State() )->status( 'plugin', 'acme', 'fr_FR' );
	}

	private function err( int $status, string $code ): array {
		return $this->json( $status, array( 'error' => array( 'code' => $code, 'message' => 'x', 'hint' => '' ) ) );
	}

	public function test_begin_stores_pending_without_any_request_or_packaging(): void {
		$http = new Babilim_Fake_Http( array() );
		$u    = $this->upload( $http );
		$this->assertTrue( $u->begin( $this->component(), 'fr_FR', $this->offer() )->is_ok() );
		$this->assertSame( 'up_9a1b2c3d4e5f', Babilim_Upload::pending()['upload_id'] );
		$this->assertCount( 0, $http->requests );
		$this->assertSame( array(), glob( $this->root . '/tmp/babilim-upload-*' ) );
		$this->assertTrue( $this->cell()['upload_pending'] );
	}

	public function test_confirm_packs_and_steps_send_parts_in_order_then_complete(): void {
		$http = new Babilim_Fake_Http(
			array(
				$this->json( 200, array( 'n' => 1, 'size' => 1024 ) ),
				$this->json( 200, array( 'n' => 2, 'size' => 1024 ) ),
				$this->json( 200, array( 'n' => 3, 'size' => 1024 ) ),
				$this->json( 200, array( 'n' => 4, 'size' => 100 ) ),
				$this->json( 202, array( 'state' => 'verifying' ) ),
			)
		);
		$u = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$r = $u->confirm();
		$this->assertTrue( $r->is_ok(), $r->message() );
		$pending = Babilim_Upload::pending();
		$zip     = (string) $pending['zip'];
		$this->assertFileExists( $zip );
		$bytes = (string) file_get_contents( $zip );
		$size  = strlen( $bytes );
		$parts = (int) ceil( $size / 1024 );
		$this->assertSame( 4, $parts );
		$this->assertSame( $parts, $pending['parts'] );

		for ( $i = 1; $i <= $parts; $i++ ) {
			$r = $u->step();
			$this->assertTrue( $r->is_ok(), $r->message() );
			$this->assertFalse( $r->get( 'done' ), 'one part per step' );
			$this->assertCount( $i, $http->requests );
		}
		$r = $u->step();
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertTrue( $r->get( 'done' ) );
		$this->assertNull( Babilim_Upload::pending() );
		$this->assertFileDoesNotExist( $zip );
		$this->assertDirectoryDoesNotExist( dirname( $zip ) );

		for ( $i = 0; $i < $parts; $i++ ) {
			$this->assertSame( 'PUT', $http->requests[ $i ]['method'] );
			$this->assertMatchesRegularExpression( '#/parts/' . ( $i + 1 ) . '$#', $http->requests[ $i ]['url'] );
			$this->assertSame( substr( $bytes, $i * 1024, 1024 ), $http->requests[ $i ]['args']['body'], 'part ' . ( $i + 1 ) );
			$this->assertSame( 60, $http->requests[ $i ]['args']['timeout'], 'every part gets the full 60 seconds' );
		}
		$last = end( $http->requests );
		$this->assertSame( 'https://api.babilim.org/v1/uploads/up_9a1b2c3d4e5f/complete', $last['url'] );
		$this->assertSame( array( 'parts' => $parts, 'size' => $size ), json_decode( $last['args']['body'], true ) );
		$this->assertSame( 'queued', $this->cell()['status'] );
		$this->assertFalse( $this->cell()['upload_pending'] );
	}

	public function test_failed_part_is_retried_up_to_three_times_then_cancelled(): void {
		$http = new Babilim_Fake_Http(
			array(
				$this->json( 500, array() ),
				$this->json( 500, array() ),
				$this->json( 500, array() ),
				$this->json( 500, array() ),
				$this->json( 200, array( 'state' => 'cancelled' ) ),
			)
		);
		$u = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$dir = Babilim_Upload::pending()['dir'];
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $u->step()->is_ok() );   // failed, but a retry is left
		}
		$r = $u->step();
		$this->assertSame( 'upload_part_failed', $r->code() );
		$this->assertSame( 'DELETE', end( $http->requests )['method'] );
		$this->assertCount( 5, $http->requests );
		$this->assertNull( Babilim_Upload::pending() );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertSame( 'requestable', $this->cell()['status'] );
		$this->assertFalse( $this->cell()['upload_pending'] );
	}

	public function test_a_500_internal_error_part_is_retried_then_succeeds(): void {
		$http = new Babilim_Fake_Http(
			array(
				$this->err( 500, 'internal_error' ),
				$this->err( 500, 'internal_error' ),
				$this->json( 200, array( 'n' => 1, 'size' => 1024 ) ),
				$this->json( 200, array( 'n' => 2, 'size' => 1024 ) ),
				$this->json( 200, array( 'n' => 3, 'size' => 1024 ) ),
				$this->json( 200, array( 'n' => 4, 'size' => 100 ) ),
				$this->json( 202, array( 'state' => 'verifying' ) ),
			)
		);
		$u = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$r = $u->step();
		$this->assertTrue( $r->is_ok() && ! $r->get( 'done' ) );
		$this->assertSame( 1, Babilim_Upload::pending()['attempts'] );
		$r = $u->step();
		$this->assertSame( 2, Babilim_Upload::pending()['attempts'] );
		$r = $u->step();
		$this->assertFalse( $r->get( 'done' ) );
		$this->assertSame( 2, Babilim_Upload::pending()['next'], 'the same part was re-sent and went through' );
		$this->assertMatchesRegularExpression( '#/parts/1$#', $http->requests[0]['url'] );
		$this->assertMatchesRegularExpression( '#/parts/1$#', $http->requests[1]['url'] );
		$this->assertMatchesRegularExpression( '#/parts/1$#', $http->requests[2]['url'] );
	}

	public function test_attempts_reset_after_a_part_succeeds(): void {
		$http = new Babilim_Fake_Http(
			array(
				$this->json( 500, array() ),
				$this->json( 200, array( 'n' => 1, 'size' => 1024 ) ),
				$this->json( 500, array() ),
			)
		);
		$u = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$u->step();
		$this->assertSame( 1, Babilim_Upload::pending()['attempts'] );
		// Part 1 now succeeds, part 2 fails: the failure count starts over.
		$u->step();
		$this->assertSame( 2, Babilim_Upload::pending()['next'] );
		$this->assertSame( 0, Babilim_Upload::pending()['attempts'] );
		$u->step();
		$this->assertSame( 1, Babilim_Upload::pending()['attempts'] );
	}

	public function test_rate_limited_part_waits_for_retry_after(): void {
		$http = new Babilim_Fake_Http(
			array(
				$this->json( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'x', 'hint' => '' ) ), array( 'retry-after' => '30' ) ),
			)
		);
		$u = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$r = $u->step();
		$this->assertTrue( $r->is_ok() && ! $r->get( 'done' ) );
		$this->assertGreaterThanOrEqual( time() + 29, Babilim_Upload::pending()['not_before'] );
		$this->assertCount( 1, $http->requests );
		$u->step();
		$this->assertCount( 1, $http->requests );          // not_before has not passed: nothing is sent
	}

	public function test_a_rate_limited_part_reports_how_long_to_wait(): void {
		$http = new Babilim_Fake_Http(
			array(
				$this->json( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'x', 'hint' => '' ) ), array( 'retry-after' => '60' ) ),
			)
		);
		$u = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$result = $u->step();
		$this->assertGreaterThanOrEqual( 59, $result->data()['wait'] );
		$this->assertLessThanOrEqual( 60, $result->data()['wait'] );
	}

	public function test_begin_refuses_an_offer_with_a_non_positive_part_size(): void {
		$u = $this->upload( new Babilim_Fake_Http( array() ) );
		foreach ( array( 'part_bytes', 'max_parts', 'max_bytes' ) as $field ) {
			foreach ( array( 0, -5, 'abc' ) as $bad ) {
				$offer           = $this->offer();
				$offer[ $field ] = $bad;
				$result          = $u->begin( $this->component(), 'fr_FR', $offer );
				$this->assertSame( 'invalid_response', $result->code(), $field . '=' . $bad );
			}
		}
		$this->assertNull( Babilim_Upload::pending() );
	}

	public function test_complete_answered_upload_incomplete_restarts_only_three_times_then_fails(): void {
		$responses = array();
		for ( $round = 0; $round < 4; $round++ ) {
			for ( $n = 1; $n <= 4; $n++ ) {
				$responses[] = $this->json( 200, array( 'n' => $n, 'size' => 1 ) );
			}
			$responses[] = $this->err( 422, 'upload_incomplete' );
		}
		$responses[] = $this->json( 200, array( 'state' => 'cancelled' ) );
		$http = new Babilim_Fake_Http( $responses );
		$u    = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$dir = Babilim_Upload::pending()['dir'];
		$r   = null;
		for ( $i = 0; $i < 40 && null !== Babilim_Upload::pending(); $i++ ) {
			$r = $u->step();
		}
		$this->assertTrue( $r->is_error() );
		$this->assertSame( 'upload_incomplete', $r->code() );
		$this->assertLessThan( 40, $i, 'it must end, not loop' );
		$this->assertSame( 'DELETE', end( $http->requests )['method'] );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertSame( 'requestable', $this->cell()['status'] );
	}

	/** @return array<int,mixed> The four part responses a 4-part package gets. */
	private function four_parts_ok(): array {
		return array(
			$this->json( 200, array( 'n' => 1, 'size' => 1024 ) ),
			$this->json( 200, array( 'n' => 2, 'size' => 1024 ) ),
			$this->json( 200, array( 'n' => 3, 'size' => 1024 ) ),
			$this->json( 200, array( 'n' => 4, 'size' => 100 ) ),
		);
	}

	public function test_complete_that_times_out_or_gets_5xx_is_resent_not_deleted(): void {
		$http = new Babilim_Fake_Http(
			array_merge(
				$this->four_parts_ok(),
				array(
					new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
					$this->err( 502, 'unexpected_response' ),
					$this->json( 202, array( 'state' => 'verifying' ) ),
				)
			)
		);
		$u = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		for ( $i = 0; $i < 4; $i++ ) {
			$u->step();
		}
		$r = $u->step();                      // complete: timeout
		$this->assertTrue( $r->is_ok() && ! $r->get( 'done' ), 'the pending upload is kept' );
		$this->assertNotNull( Babilim_Upload::pending() );
		$r = $u->step();                      // complete: 502
		$this->assertTrue( $r->is_ok() && ! $r->get( 'done' ) );
		$this->assertSame( 2, Babilim_Upload::pending()['attempts'] );
		$r = $u->step();                      // complete: accepted
		$this->assertTrue( $r->is_ok() && $r->get( 'done' ) );
		$this->assertNull( Babilim_Upload::pending() );
		$this->assertSame( 'up_9a1b2c3d4e5f', Babilim_Upload::last_completed() );
		$this->assertSame( 'queued', $this->cell()['status'] );
		$methods = array_column( $http->requests, 'method' );
		$this->assertNotContains( 'DELETE', $methods );
	}

	public function test_complete_that_keeps_failing_is_cancelled_after_the_retry_limit(): void {
		$responses   = $this->four_parts_ok();
		$responses[] = $this->err( 502, 'unexpected_response' );
		$responses[] = $this->err( 502, 'unexpected_response' );
		$responses[] = $this->err( 502, 'unexpected_response' );
		$responses[] = $this->err( 502, 'unexpected_response' );
		$responses[] = $this->json( 200, array( 'state' => 'cancelled' ) );
		$http        = new Babilim_Fake_Http( $responses );
		$u           = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$dir = Babilim_Upload::pending()['dir'];
		$r   = null;
		for ( $i = 0; $i < 20 && null !== Babilim_Upload::pending(); $i++ ) {
			$r = $u->step();
		}
		$this->assertLessThan( 20, $i, 'it must end, not loop' );
		$this->assertTrue( $r->is_error() );
		$this->assertSame( 'DELETE', end( $http->requests )['method'] );
		$this->assertCount( 4 + 4 + 1, $http->requests, 'one first try + three resends, then the DELETE' );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertSame( 'requestable', $this->cell()['status'] );
	}

	public function test_complete_answered_with_a_client_error_is_still_cancelled(): void {
		$responses   = $this->four_parts_ok();
		$responses[] = $this->err( 404, 'not_found' );
		$responses[] = $this->json( 200, array( 'state' => 'cancelled' ) );
		$http        = new Babilim_Fake_Http( $responses );
		$u           = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		for ( $i = 0; $i < 4; $i++ ) {
			$u->step();
		}
		$r = $u->step();
		$this->assertTrue( $r->is_error() );
		$this->assertSame( 'DELETE', end( $http->requests )['method'] );
		$this->assertNull( Babilim_Upload::pending() );
	}

	public function test_a_sealed_answer_to_a_resend_counts_as_uploaded(): void {
		$http = new Babilim_Fake_Http( array( $this->err( 409, 'upload_sealed' ) ) );
		$u    = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$dir = Babilim_Upload::pending()['dir'];
		$r   = $u->step();
		$this->assertTrue( $r->is_ok() && $r->get( 'done' ) );
		$this->assertCount( 1, $http->requests, 'no DELETE: a sealed upload is not cancelled' );
		$this->assertSame( 'up_9a1b2c3d4e5f', Babilim_Upload::last_completed() );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertSame( 'queued', $this->cell()['status'] );
	}

	public function test_confirm_refuses_a_package_over_the_service_limits(): void {
		$http = new Babilim_Fake_Http( array( $this->json( 200, array( 'state' => 'cancelled' ) ) ) );
		$u    = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', array_merge( $this->offer( 1024 ), array( 'max_parts' => 1 ) ) );
		$r = $u->confirm();
		$this->assertSame( 'upload_too_large', $r->code() );
		$this->assertNull( Babilim_Upload::pending() );
		$this->assertSame( array(), glob( $this->root . '/tmp/babilim-upload-*' ) );
		$this->assertSame( 'DELETE', $http->requests[0]['method'] );
		$this->assertSame( 'requestable', $this->cell()['status'] );
	}

	public function test_cancel_calls_delete_and_removes_staging(): void {
		$http = new Babilim_Fake_Http( array( $this->json( 200, array( 'state' => 'cancelled' ) ) ) );
		$u    = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$dir = Babilim_Upload::pending()['dir'];
		$this->assertTrue( $u->cancel()->is_ok() );
		$this->assertSame( 'DELETE', $http->requests[0]['method'] );
		$this->assertNull( Babilim_Upload::pending() );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertSame( 'requestable', $this->cell()['status'] );
	}

	public function test_cancel_before_confirm_leaves_nothing_and_cancel_without_pending_is_a_no_op(): void {
		$http = new Babilim_Fake_Http( array( $this->json( 200, array( 'state' => 'cancelled' ) ) ) );
		$u    = $this->upload( $http );
		$this->assertTrue( $u->cancel()->is_ok() );
		$this->assertCount( 0, $http->requests );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$this->assertTrue( $u->cancel()->is_ok() );
		$this->assertNull( Babilim_Upload::pending() );
	}

	public function test_an_installed_cell_gets_its_newer_version_status_restored_not_its_own(): void {
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'acme',
						'locales' => array( 'fr_FR' => array( 'status' => 'babilim_installed', 'newer_version' => array( 'version' => '2.0', 'status' => 'queued' ) ) ),
					),
				),
			)
		);
		$http = new Babilim_Fake_Http( array( $this->json( 200, array( 'state' => 'cancelled' ) ) ) );
		$u    = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->cancel();
		$cell = $this->cell();
		$this->assertSame( 'babilim_installed', $cell['status'] );
		$this->assertSame( 'requestable', $cell['newer_version']['status'] );
	}

	public function test_cancel_drops_the_stale_service_reason_from_an_uninstalled_cell(): void {
		$this->seed_cell( array( 'status' => 'requestable', 'reason' => 'source_rejected' ) );
		$http = new Babilim_Fake_Http( array( $this->json( 200, array( 'state' => 'cancelled' ) ) ) );
		$u    = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->confirm();
		$u->cancel();
		$cell = $this->cell();
		$this->assertSame( 'requestable', $cell['status'] );
		$this->assertEmpty( $cell['reason'] ?? null );
		$this->assertSame( 'No translation', Babilim_Cell::from_entry( $cell, 'fr_FR', false, true, null )['text'] );
	}

	public function test_cancel_keeps_the_top_level_reason_of_an_installed_cell(): void {
		$this->seed_cell( array( 'status' => 'babilim_installed', 'reason' => 'integrity_failed', 'newer_version' => array( 'version' => '2.0', 'status' => 'queued' ) ) );
		$http = new Babilim_Fake_Http( array( $this->json( 200, array( 'state' => 'cancelled' ) ) ) );
		$u    = $this->upload( $http );
		$u->begin( $this->component(), 'fr_FR', $this->offer() );
		$u->cancel();
		$cell = $this->cell();
		$this->assertSame( 'integrity_failed', $cell['reason'] );
		$this->assertSame( 'requestable', $cell['newer_version']['status'] );
	}

	/** @param array<string,mixed> $cell */
	private function seed_cell( array $cell ): void {
		( new Babilim_State() )->set(
			array(
				'components' => array(
					array(
						'kind'    => 'plugin',
						'slug'    => 'acme',
						'locales' => array( 'fr_FR' => $cell ),
					),
				),
			)
		);
	}

	public function test_a_missing_offer_field_is_refused(): void {
		$u = $this->upload( new Babilim_Fake_Http( array() ) );
		$r = $u->begin( $this->component(), 'fr_FR', array( 'upload_id' => 'up_x' ) );
		$this->assertSame( 'invalid_response', $r->code() );
		$this->assertNull( Babilim_Upload::pending() );
	}
}
