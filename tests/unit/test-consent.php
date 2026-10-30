<?php
/**
 * Tests for Babilim_Consent -- whether the site owner has agreed to use
 * the service, and the settings screen's own item-by-item disclosure of
 * what gets sent once they do.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Consent extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	private function inventory(): Babilim_Inventory {
		return new Babilim_Inventory(
			static function () {
				return array();
			},
			static function () {
				return array();
			},
			static function () {
				return false;
			},
			static function () {
				return array();
			}
		);
	}

	public function test_granted_defaults_to_false(): void {
		$this->assertFalse( ( new Babilim_Consent() )->granted() );
	}

	public function test_granted_reflects_the_stored_option(): void {
		update_option( 'babilim_consent', true );

		$this->assertTrue( ( new Babilim_Consent() )->granted() );
	}

	public function test_granted_uses_the_same_option_babilim_site_does(): void {
		update_option( Babilim_Site::OPTION_CONSENT, true );

		$this->assertTrue( ( new Babilim_Consent() )->granted() );
	}

	public function test_payload_preview_field_set_is_fixed(): void {
		$preview = ( new Babilim_Consent() )->payload_preview( $this->inventory() );

		$fields = array_column( $preview, 'field' );
		$this->assertSame(
			array(
				'home_url',
				'wp_version',
				'php_version',
				'plugin_version',
				'site_locale',
				'locales',
				'install_pub',
				'components',
				'source package',
				'client_events',
			),
			$fields
		);
	}

	public function test_payload_preview_every_row_has_a_field_value_and_description(): void {
		$preview = ( new Babilim_Consent() )->payload_preview( $this->inventory() );

		foreach ( $preview as $row ) {
			$this->assertArrayHasKey( 'field', $row );
			$this->assertArrayHasKey( 'value', $row );
			$this->assertArrayHasKey( 'description', $row );
			$this->assertNotSame( '', $row['description'], "row '{$row['field']}' must explain what it discloses" );
		}
	}

	public function test_payload_preview_client_events_row_discloses_it_never_carries_string_content(): void {
		$preview = ( new Babilim_Consent() )->payload_preview( $this->inventory() );

		$row = null;
		foreach ( $preview as $candidate ) {
			if ( 'client_events' === $candidate['field'] ) {
				$row = $candidate;
			}
		}
		$this->assertNotNull( $row, 'client_events must be disclosed' );
		$this->assertStringContainsString( 'never', strtolower( $row['description'] ) );
	}

	public function test_payload_preview_locales_row_reflects_the_inventorys_own_locales(): void {
		$inventory = new Babilim_Inventory(
			static function () {
				return array();
			},
			static function () {
				return array();
			},
			static function () {
				return false;
			},
			static function () {
				return array( 'de_DE' );
			}
		);

		$preview = ( new Babilim_Consent() )->payload_preview( $inventory );

		$row = null;
		foreach ( $preview as $candidate ) {
			if ( 'locales' === $candidate['field'] ) {
				$row = $candidate;
			}
		}
		$this->assertContains( 'de_DE', $row['value'] );
	}

	public function test_payload_preview_install_pub_never_exposes_a_secret(): void {
		$preview = ( new Babilim_Consent() )->payload_preview( $this->inventory() );

		foreach ( $preview as $row ) {
			$value = is_string( $row['value'] ) ? $row['value'] : (string) wp_json_encode( $row['value'] );
			$value = strtolower( $value );
			$this->assertStringNotContainsString( 'secret', $value );
			$this->assertStringNotContainsString( 'site_token', $value );
		}
	}

	public function test_payload_preview_has_a_source_package_row_that_says_it_is_conditional(): void {
		$rows = array_values( array_filter( ( new Babilim_Consent() )->payload_preview( $this->inventory() ), static function ( $row ) { return 'source package' === $row['field']; } ) );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'Only when you confirm', $rows[0]['description'] );
	}
}
