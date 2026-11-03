<?php
/**
 * Babilim_Error_Catalog must mirror the pinned contract's catalog.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Error_Catalog extends TestCase {

	private function pinned(): array {
		$raw = file_get_contents( __DIR__ . '/../contract/v1/error-catalog.json' );
		$this->assertIsString( $raw );
		$data = json_decode( $raw, true );
		$this->assertIsArray( $data );
		return $data;
	}

	public function test_catalog_matches_pinned_contract_json(): void {
		$pinned = $this->pinned();
		$this->assertSame( $pinned['error_code'], Babilim_Error_Catalog::ERROR_CODES );
		$this->assertSame( $pinned['action'], Babilim_Error_Catalog::ACTIONS );
		$this->assertSame( $pinned['origin_id'], Babilim_Error_Catalog::ORIGIN_IDS );
	}

	public function test_is_valid_accepts_only_catalog_values(): void {
		$this->assertTrue( Babilim_Error_Catalog::is_valid( 'verify_failed', 'sync', 'site.verify_installed' ) );
		$this->assertFalse( Babilim_Error_Catalog::is_valid( 'nope', 'sync', 'site.sync' ) );
		$this->assertFalse( Babilim_Error_Catalog::is_valid( 'verify_failed', 'nope', 'site.sync' ) );
		$this->assertFalse( Babilim_Error_Catalog::is_valid( 'verify_failed', 'sync', 'nope' ) );
	}

	public function test_length_limits_match_the_pinned_request_schema(): void {
		$raw    = file_get_contents( __DIR__ . '/../contract/v1/schemas/error-reports.request.schema.json' );
		$schema = json_decode( (string) $raw, true );
		$item   = $schema['properties']['events']['items']['properties'];
		$this->assertSame( Babilim_Contract_Limits::ERROR_CODE, $item['error_code']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::ERROR_ACTION, $item['action']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::ERROR_ORIGIN, $item['origin_id']['maxLength'] );
		$this->assertSame( Babilim_Contract_Limits::ERROR_EVENTS, $schema['properties']['events']['maxItems'] );
		$this->assertSame( Babilim_Contract_Limits::ERROR_ENV_VERSION, $schema['properties']['plugin_version']['maxLength'] );
	}
}
