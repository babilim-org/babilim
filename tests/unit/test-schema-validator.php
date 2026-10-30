<?php
/**
 * Self-tests for Babilim_Schema_Validator itself, independent of
 * whatever this plugin's own code happens to send -- test-contract.php
 * proves the validator catches a regression in the plugin's own request
 * bodies; this file proves the validator's own keyword implementations
 * (required, additionalProperties as a nested schema, minItems, minimum,
 * format, const, oneOf, local $ref, array-form type, and the "unknown
 * keyword/type/format throws" rule) each have a real red/green case, so
 * none of them can quietly regress to a no-op without a test noticing.
 *
 * Wherever a real pinned contract schema/example pair naturally exercises
 * a keyword, that's what's used (packages.manifest.json against
 * package-manifest.schema.json for $ref/const/minimum/format/nested
 * additionalProperties; requests.202/available/get.failed.json against
 * requests.response.schema.json for oneOf/const/array-type). Two cases
 * have no natural home in the pinned contract and use a minimal inline
 * schema instead: oneOf matching more than one alternative (every oneOf
 * branch in the real schemas is already mutually exclusive by its own
 * additionalProperties:false, so no real example can trigger this) and
 * minItems (no response/request schema this validator is ever handed
 * real data against uses it on a schema this file isn't already
 * otherwise covering -- inventory.request.schema.json's own `locales`
 * is used instead, a real schema, just with a hand-built body).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Schema_Validator extends TestCase {

	private const SCHEMAS_DIR  = __DIR__ . '/../contract/v1/schemas';
	private const EXAMPLES_DIR = __DIR__ . '/../contract/v1/examples';

	/**
	 * @return array Decoded JSON Schema.
	 */
	private function schema( string $name ): array {
		$raw = file_get_contents( self::SCHEMAS_DIR . '/' . $name );
		$this->assertIsString( $raw, "Missing contract schema: {$name}" );
		$decoded = json_decode( $raw, true );
		$this->assertIsArray( $decoded, "Schema did not decode: {$name}" );
		return $decoded;
	}

	/**
	 * @return mixed Decoded (no $assoc) `instance` field of an example fixture.
	 */
	private function exampleInstance( string $name ) {
		$raw = file_get_contents( self::EXAMPLES_DIR . '/' . $name );
		$this->assertIsString( $raw, "Missing contract example: {$name}" );
		$decoded = json_decode( $raw );
		$this->assertNotNull( $decoded, "Example did not decode: {$name}" );
		return $decoded->instance;
	}

	/**
	 * @return mixed Decoded (no $assoc) `response.body` field of an example fixture.
	 */
	private function exampleResponseBody( string $name ) {
		$raw = file_get_contents( self::EXAMPLES_DIR . '/' . $name );
		$this->assertIsString( $raw, "Missing contract example: {$name}" );
		$decoded = json_decode( $raw );
		$this->assertNotNull( $decoded, "Example did not decode: {$name}" );
		return $decoded->response->body;
	}

	// -- positive: real pinned examples validate cleanly -------------------

	public function test_packages_manifest_example_matches_its_schema(): void {
		$errors = Babilim_Schema_Validator::validate( $this->exampleInstance( 'packages.manifest.json' ), $this->schema( 'package-manifest.schema.json' ) );
		$this->assertSame( array(), $errors );
	}

	public function test_requests_queued_example_matches_its_schema(): void {
		$errors = Babilim_Schema_Validator::validate( $this->exampleResponseBody( 'requests.202.json' ), $this->schema( 'requests.response.schema.json' ) );
		$this->assertSame( array(), $errors );
	}

	public function test_requests_available_example_matches_its_schema(): void {
		$errors = Babilim_Schema_Validator::validate( $this->exampleResponseBody( 'requests.available.json' ), $this->schema( 'requests.response.schema.json' ) );
		$this->assertSame( array(), $errors );
	}

	public function test_requests_failed_example_matches_its_schema(): void {
		$errors = Babilim_Schema_Validator::validate( $this->exampleResponseBody( 'requests.get.failed.json' ), $this->schema( 'requests.response.schema.json' ) );
		$this->assertSame( array(), $errors );
	}

	// -- negative: one minimal break per keyword, each must be rejected ----

	public function test_packages_manifest_missing_a_required_key_is_rejected(): void {
		$data = $this->exampleInstance( 'packages.manifest.json' );
		unset( $data->build_id );
		$errors = Babilim_Schema_Validator::validate( $data, $this->schema( 'package-manifest.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	public function test_packages_manifest_an_extra_key_is_rejected(): void {
		$data     = $this->exampleInstance( 'packages.manifest.json' );
		$data->zz = 1;
		$errors   = Babilim_Schema_Validator::validate( $data, $this->schema( 'package-manifest.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	public function test_packages_manifest_a_wrong_const_value_is_rejected(): void {
		$data         = $this->exampleInstance( 'packages.manifest.json' );
		$data->status = 'draft';
		$errors       = Babilim_Schema_Validator::validate( $data, $this->schema( 'package-manifest.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	public function test_packages_manifest_a_negative_number_below_minimum_is_rejected(): void {
		$data          = $this->exampleInstance( 'packages.manifest.json' );
		$data->entries = -1;
		$errors        = Babilim_Schema_Validator::validate( $data, $this->schema( 'package-manifest.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	public function test_packages_manifest_a_malformed_date_time_is_rejected(): void {
		$data            = $this->exampleInstance( 'packages.manifest.json' );
		$data->issued_at = 'not-a-date';
		$errors          = Babilim_Schema_Validator::validate( $data, $this->schema( 'package-manifest.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * `files.*` is constrained entirely through `additionalProperties`
	 * holding a local `$ref` to `#/$defs/file_digest`, which is itself
	 * `additionalProperties: false` -- an extra key on one file entry
	 * must be caught by that nested, $ref-resolved sub-schema.
	 */
	public function test_packages_manifest_an_extra_key_inside_a_nested_additional_properties_entry_is_rejected(): void {
		$data = $this->exampleInstance( 'packages.manifest.json' );
		foreach ( $data->files as $digest ) {
			$digest->zz = 1;
			break;
		}
		$errors = Babilim_Schema_Validator::validate( $data, $this->schema( 'package-manifest.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	public function test_requests_status_matching_none_of_the_oneof_branches_is_rejected(): void {
		$data         = $this->exampleResponseBody( 'requests.202.json' );
		$data->status = 'cancelled';
		$errors       = Babilim_Schema_Validator::validate( $data, $this->schema( 'requests.response.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * The three real `oneOf` branches in requests.response.schema.json are
	 * mutually exclusive by their own `additionalProperties: false`, so no
	 * real contract example can make data match two of them at once -- a
	 * minimal, self-contained schema demonstrates the validator's own
	 * "matched more than one" rejection instead (see the class docblock).
	 */
	public function test_data_matching_more_than_one_oneof_alternative_is_rejected(): void {
		$schema = array(
			'oneOf' => array(
				array( 'type' => 'string' ),
				array( 'type' => 'string', 'minLength' => 0 ),
			),
		);
		$errors = Babilim_Schema_Validator::validate( json_decode( '"x"' ), $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'matched 2', $errors[0] );
	}

	/**
	 * No response/request schema this validator's real data-checking path
	 * is ever called against uses minItems on a field not already covered
	 * above -- inventory.request.schema.json's own `locales` (minItems: 1)
	 * is a real, pinned schema; the body is hand-built rather than drawn
	 * from an examples/ fixture (every pinned inventory example already
	 * has a non-empty `locales`).
	 */
	public function test_an_array_below_min_items_is_rejected(): void {
		$data   = json_decode( '{"components":[],"locales":[]}' );
		$errors = Babilim_Schema_Validator::validate( $data, $this->schema( 'inventory.request.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	// -- length/size caps the 1.3.0 contract relies on -----------------------

	public function test_a_string_longer_than_max_length_is_rejected_and_one_at_the_limit_passes(): void {
		$schema = array( 'type' => 'string', 'maxLength' => 3 );
		$this->assertSame( array(), Babilim_Schema_Validator::validate( json_decode( '"abc"' ), $schema ) );
		$errors = Babilim_Schema_Validator::validate( json_decode( '"abcd"' ), $schema );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'maxLength 3', $errors[0] );
	}

	public function test_an_object_with_more_than_max_properties_is_rejected(): void {
		$schema = array( 'type' => 'object', 'maxProperties' => 2 );
		$this->assertSame( array(), Babilim_Schema_Validator::validate( json_decode( '{"a":1,"b":2}' ), $schema ) );
		$errors = Babilim_Schema_Validator::validate( json_decode( '{"a":1,"b":2,"c":3}' ), $schema );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'maxProperties 2', $errors[0] );
	}

	public function test_an_array_with_a_repeated_item_is_rejected_when_unique_items_is_true(): void {
		$schema = array( 'type' => 'array', 'uniqueItems' => true );
		$this->assertSame( array(), Babilim_Schema_Validator::validate( json_decode( '["a","b"]' ), $schema ) );
		$errors = Babilim_Schema_Validator::validate( json_decode( '["a","b","a"]' ), $schema );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'uniqueItems', $errors[0] );
		// A repeated element is a repeated element whatever its JSON type:
		// 1 and "1" are different values and must not count as one.
		$this->assertSame( array(), Babilim_Schema_Validator::validate( json_decode( '[1,"1"]' ), $schema ) );
	}

		// -- cross-file $ref always throws --------------------------------------

	/**
	 * sites.verify.response.schema.json's `license`/`quota` properties are
	 * both a cross-file `$ref` ("common.schema.json#/$defs/..."), which
	 * this validator deliberately never resolves (see the class docblock)
	 * -- validating a real response example against it must throw, not
	 * silently skip those two properties.
	 */
	public function test_a_cross_file_ref_throws(): void {
		$this->expectException( \RuntimeException::class );
		Babilim_Schema_Validator::validate( $this->exampleResponseBody( 'sites.verify.ok.json' ), $this->schema( 'sites.verify.response.schema.json' ) );
	}

	// -- New-Important-A: array-form `type`, and unknown type/keyword/format --

	public function test_an_array_form_type_accepts_any_of_the_listed_types(): void {
		$schema = array( 'type' => array( 'integer', 'null' ) );
		$this->assertSame( array(), Babilim_Schema_Validator::validate( json_decode( '3' ), $schema ) );
		$this->assertSame( array(), Babilim_Schema_Validator::validate( json_decode( 'null' ), $schema ) );
	}

	public function test_an_array_form_type_rejects_a_value_matching_none_of_the_listed_types(): void {
		$schema = array( 'type' => array( 'integer', 'null' ) );
		$errors = Babilim_Schema_Validator::validate( json_decode( '"x"' ), $schema );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * `eta_minutes` is typed `["integer", "null"]` in the pinned schema.
	 * A PHP `(string)` cast of an array `type` would silently become the
	 * literal string "Array", which matches nothing in the type switch
	 * and returns true by default -- the array form of `type` must not
	 * fall through to "any type accepted" that way, or a string value
	 * here would pass undetected.
	 */
	public function test_requests_202_eta_minutes_as_a_string_is_rejected(): void {
		$data              = $this->exampleResponseBody( 'requests.202.json' );
		$data->eta_minutes = 'soon';
		$errors            = Babilim_Schema_Validator::validate( $data, $this->schema( 'requests.response.schema.json' ) );
		$this->assertNotEmpty( $errors );
	}

	public function test_an_unrecognized_type_value_throws(): void {
		$this->expectException( \RuntimeException::class );
		Babilim_Schema_Validator::validate( json_decode( '"x"' ), array( 'type' => 'strng' ) );
	}

	public function test_an_unrecognized_type_inside_an_array_form_throws(): void {
		$this->expectException( \RuntimeException::class );
		Babilim_Schema_Validator::validate( json_decode( '"x"' ), array( 'type' => array( 'string', 'strng' ) ) );
	}

	/**
	 * MV1: without this, an unknown schema keyword is silently treated as
	 * satisfied rather than failing the schema (and every test above that
	 * relies on `assert_known_keywords()` throwing for a genuinely broken
	 * schema) -- this is the one direct test that a keyword outside
	 * KNOWN_KEYWORDS actually throws, rather than only proving (as the
	 * scan test in test-contract.php does) that every keyword the pinned
	 * contract already uses is recognized.
	 */
	public function test_an_unrecognized_schema_keyword_throws(): void {
		$this->expectException( \RuntimeException::class );
		Babilim_Schema_Validator::assert_known_keywords( array( 'type' => 'string', 'foo' => 1 ) );
	}

	public function test_an_unrecognized_format_value_throws(): void {
		$this->expectException( \RuntimeException::class );
		Babilim_Schema_Validator::validate( json_decode( '"x"' ), array( 'type' => 'string', 'format' => 'email' ) );
	}
}
