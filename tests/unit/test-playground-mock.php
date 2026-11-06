<?php
/**
 * The local mock service the browser flow talks to
 * (tests/playground/mock-server/) answers with bodies shaped the way the
 * pinned contract says: every profile's inventory answer, the
 * registration answer and the verify answer are validated here against
 * tests/contract/v1/schemas/, with the shared definitions from
 * common.schema.json inlined (the validator resolves local refs only).
 *
 * The one deliberate exception is the `noquota` profile, whose `quota` is
 * an empty object: it is how the browser flow sees the slot sentences the
 * plugin shows when the service reports no numbers. Its answer is checked
 * to differ from a valid one in `quota` only.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/playground/mock-server/responses.php';

final class Test_Playground_Mock extends TestCase {

	private const SCHEMAS_DIR = __DIR__ . '/../contract/v1/schemas';

	/** Components and locales the runners' fixtures use. */
	private const REQUEST = array(
		'components' => array(
			array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'version' => '1.0.0' ),
			array( 'kind' => 'plugin', 'slug' => 'queued-demo', 'version' => '1.0.0' ),
			array( 'kind' => 'plugin', 'slug' => 'newer-demo', 'version' => '1.0.0' ),
			array( 'kind' => 'plugin', 'slug' => 'long-name-demo', 'version' => '1.0.0' ),
			array( 'kind' => 'plugin', 'slug' => 'bulk-a', 'version' => '1.0.0' ),
			array( 'kind' => 'plugin', 'slug' => 'errors-demo', 'version' => '1.0.0' ),
			array( 'kind' => 'theme', 'slug' => 'twentytwentyfour', 'version' => '1.2' ),
		),
		'locales'    => array( 'de_DE', 'fr_FR' ),
	);

	/**
	 * A response schema with common.schema.json's definitions inlined.
	 *
	 * @param string $name Schema file name.
	 * @return array
	 */
	private function schema( string $name ): array {
		$schema = json_decode( (string) file_get_contents( self::SCHEMAS_DIR . '/' . $name ), true );
		$common = json_decode( (string) file_get_contents( self::SCHEMAS_DIR . '/common.schema.json' ), true );
		$this->assertIsArray( $schema );
		$this->assertIsArray( $common );
		$schema['$defs'] = array_merge( $schema['$defs'] ?? array(), $common['$defs'] );
		array_walk_recursive(
			$schema,
			static function ( &$value, $key ) {
				if ( '$ref' === $key && is_string( $value ) ) {
					$value = str_replace( 'common.schema.json#', '#', $value );
				}
			}
		);
		return $schema;
	}

	/**
	 * Validates a body the way it goes over the wire (JSON, objects kept).
	 *
	 * @param array  $body   Response body.
	 * @param string $schema Schema file name.
	 * @return string[] Violations.
	 */
	private function violations( array $body, string $schema ): array {
		return Babilim_Schema_Validator::validate( json_decode( (string) json_encode( $body ) ), $this->schema( $schema ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function contract_profiles(): array {
		return array(
			'default' => array( '' ),
			'list'    => array( 'list' ),
			'bulk'    => array( 'bulk' ),
			'errors'  => array( 'errors' ),
		);
	}

	/**
	 * @dataProvider contract_profiles
	 */
	public function test_every_contract_profile_answers_inventory_as_the_contract_shapes_it( string $profile ): void {
		$body = babilim_mock_inventory_body( self::REQUEST, $profile );
		$this->assertSame( array(), $this->violations( $body, 'inventory.response.schema.json' ), "profile '{$profile}'" );
	}

	public function test_the_inventory_answer_names_the_pinned_contract_version(): void {
		$pinned = trim( (string) file_get_contents( __DIR__ . '/../contract/v1/VERSION' ) );
		$this->assertSame( $pinned, babilim_mock_inventory_body( self::REQUEST, '' )['contract'] );
	}

	public function test_registration_and_verify_answer_as_the_contract_shapes_them(): void {
		$this->assertSame( array(), $this->violations( babilim_mock_register_body( 1, str_repeat( 'ab', 16 ) ), 'sites.register.response.schema.json' ) );
		$this->assertSame( array(), $this->violations( babilim_mock_verify_body( babilim_mock_token( 1 ) ), 'sites.verify.response.schema.json' ) );
	}

	public function test_each_registration_gets_its_own_site_id_and_token(): void {
		$this->assertNotSame( babilim_mock_site_id( 1 ), babilim_mock_site_id( 2 ) );
		$this->assertNotSame( babilim_mock_token( 1 ), babilim_mock_token( 2 ) );
	}

	public function test_the_list_profile_reports_its_own_limits_and_no_used_slot(): void {
		$slots = babilim_mock_inventory_body( self::REQUEST, 'list' )['quota']['free_slots'];
		$this->assertSame( array( 'used' => 0, 'limit' => 1 ), $slots['theme'] );
		$this->assertSame( array( 'used' => 0, 'limit' => 3 ), $slots['plugin'] );
	}

	public function test_the_noquota_profile_is_the_list_profile_without_the_numbers(): void {
		$list    = babilim_mock_inventory_body( self::REQUEST, 'list' );
		$noquota = babilim_mock_inventory_body( self::REQUEST, 'noquota' );
		$this->assertSame( '{}', json_encode( $noquota['quota'] ) );
		unset( $list['quota'], $noquota['quota'] );
		$this->assertSame( json_encode( $list ), json_encode( $noquota ) );
	}
}
