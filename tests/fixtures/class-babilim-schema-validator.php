<?php
/**
 * A JSON Schema (draft 2020-12) subset validator covering every keyword
 * that actually appears anywhere under tests/contract/v1/schemas/: $id,
 * $schema, $ref, $defs, description, title, type, required, properties,
 * additionalProperties (bool or nested schema), items, pattern, enum,
 * minLength, maxLength, maxItems, maxProperties, uniqueItems, minItems,
 * minimum, maximum, format (date-time/
 * uri/uri-reference), const, oneOf.
 *
 * assert_known_keywords() is called at the top of every validate() --
 * any schema keyword outside that list throws, rather than being
 * silently treated as a no-op constraint. This is deliberate: a schema
 * this validator doesn't fully understand must fail loud, not quietly
 * report "no errors" for data it never actually checked (see
 * assert_known_keywords()'s own docblock for the incident that made this
 * mandatory).
 *
 * Deliberately operates on data decoded WITHOUT json_decode()'s $assoc
 * flag (i.e. json_decode($raw), not json_decode($raw, true)), so a JSON
 * object survives as a PHP stdClass and a JSON array survives as a PHP
 * array -- the one distinction tests/unit/test-contract.php needs to
 * catch a body that sent `[]` where the schema requires `{}` (see
 * docs/api-contract.md's `installed` field), which assoc-mode decoding
 * would silently erase (both become the same PHP array shape).
 *
 * The schema itself is always the normal assoc-decoded form
 * (json_decode($raw, true)) -- only the *data being validated* needs the
 * object/array distinction preserved.
 *
 * $ref resolution only ever supports a local `#/$defs/<name>` pointer,
 * resolved against the same top-level schema document `validate()` was
 * first called with -- none of this repo's own request schemas
 * (the only ones real data is ever validated against here) use $ref at
 * all; it's recognized and exercised only by the keyword-recognition
 * scan (see the class docblock and the corresponding test). A cross-file
 * $ref (e.g. "common.schema.json#/$defs/quota") throws rather than
 * silently resolving to nothing.
 */

declare(strict_types=1);

final class Babilim_Schema_Validator {

	/**
	 * Every JSON Schema keyword this validator recognizes -- see the
	 * class docblock. assert_known_keywords() throws for anything outside
	 * this list, rather than let validate() silently pass data a schema
	 * keyword this validator doesn't implement was supposed to constrain
	 * (this is exactly how this validator once missed a wrong
	 * `verified_at` key name and a wrong `integrity` enum value:
	 * `installed`'s per-locale entries are constrained entirely through
	 * `additionalProperties` holding a nested schema, which the earlier
	 * version of this file didn't implement at all -- it silently
	 * accepted every entry, `required`/`enum`/`additionalProperties:false`
	 * included, no matter what shape it was).
	 *
	 * @var string[]
	 */
	private const KNOWN_KEYWORDS = array(
		'$id',
		'$schema',
		'$ref',
		'$defs',
		'description',
		'title',
		'type',
		'required',
		'properties',
		'additionalProperties',
		'items',
		'pattern',
		'enum',
		'minLength',
		'maxLength',
		'maxProperties',
		'uniqueItems',
		'maxItems',
		'minItems',
		'minimum',
		'maximum',
		'format',
		'const',
		'oneOf',
	);

	/**
	 * Every JSON Schema primitive `type` name this validator recognizes,
	 * either as a bare string or as an element of the array form (e.g.
	 * `"type": ["integer", "null"]`, used by `eta_minutes` in
	 * requests.response.schema.json). A `type` value outside this set --
	 * including the array form containing one, or `type` itself being
	 * neither a string nor an array -- must fail the same "fail loud" way
	 * an unrecognized keyword does (see KNOWN_KEYWORDS's own docblock).
	 * Casting an array `type` to a string with `(string) $schema['type']`
	 * would be wrong here: PHP turns that into the literal string
	 * `"Array"` with a warning, silently falling through to "any type
	 * accepted" -- `eta_minutes` typed `"soon"` would then pass.
	 *
	 * @var string[]
	 */
	private const KNOWN_TYPES = array(
		'object',
		'array',
		'string',
		'boolean',
		'integer',
		'number',
		'null',
	);

	/**
	 * date-time: a practical RFC 3339 shape (date, 'T', time, fractional
	 * seconds optional, then 'Z' or a +hh:mm/-hh:mm offset) -- not a full
	 * calendar-validity check (e.g. doesn't reject 2026-02-30), which is
	 * more than this validator's callers need.
	 *
	 * @var string
	 */
	private const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

	/**
	 * @param mixed  $data   Decoded JSON value -- json_decode($raw), no $assoc.
	 * @param array  $schema Decoded JSON Schema -- json_decode($raw, true).
	 * @param string $path   Human-readable location, for error messages.
	 * @return string[] Validation error messages; empty means valid.
	 */
	public static function validate( $data, array $schema, string $path = '$' ): array {
		self::assert_known_keywords( $schema );
		return self::validate_node( $data, $schema, $schema, $path );
	}

	/**
	 * Walks $schema (and everywhere a nested schema can appear inside it
	 * -- `properties.*`, `additionalProperties` when it's a schema,
	 * `items`, `$defs.*`, `oneOf[*]`) and throws the moment it finds a key
	 * this validator doesn't recognize. Purely structural -- no data
	 * involved -- so it can run against every schema file in
	 * tests/contract/v1/schemas/, including ones no test here ever
	 * validates real data against (see
	 * test_schema_validator_recognizes_every_keyword_used_across_the_pinned_contract_schemas
	 * in tests/unit/test-contract.php).
	 *
	 * @param array $schema Decoded JSON Schema (or schema fragment).
	 * @param string $path Human-readable location, for the exception message.
	 * @throws \RuntimeException If an unrecognized keyword is found.
	 */
	public static function assert_known_keywords( array $schema, string $path = '$' ): void {
		foreach ( array_keys( $schema ) as $key ) {
			if ( ! in_array( $key, self::KNOWN_KEYWORDS, true ) ) {
				throw new \RuntimeException( "{$path}: unrecognized JSON Schema keyword '{$key}' -- Babilim_Schema_Validator must be taught it before this schema can be trusted; it must never silently skip a keyword it doesn't implement." );
			}
		}
		if ( array_key_exists( 'type', $schema ) ) {
			self::assert_known_type( $schema['type'], "{$path}.type" );
		}
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $sub_schema ) {
				self::assert_known_keywords( (array) $sub_schema, "{$path}.properties.{$name}" );
			}
		}
		if ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) ) {
			self::assert_known_keywords( $schema['additionalProperties'], "{$path}.additionalProperties" );
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			self::assert_known_keywords( $schema['items'], "{$path}.items" );
		}
		if ( isset( $schema['$defs'] ) && is_array( $schema['$defs'] ) ) {
			foreach ( $schema['$defs'] as $name => $sub_schema ) {
				self::assert_known_keywords( (array) $sub_schema, "{$path}.\$defs.{$name}" );
			}
		}
		if ( isset( $schema['oneOf'] ) && is_array( $schema['oneOf'] ) ) {
			foreach ( $schema['oneOf'] as $index => $sub_schema ) {
				self::assert_known_keywords( (array) $sub_schema, "{$path}.oneOf[{$index}]" );
			}
		}
	}

	/**
	 * Throws unless $type_spec is either a single recognized type name,
	 * or an array of them (the `"type": ["integer", "null"]` form) -- see
	 * KNOWN_TYPES's own docblock.
	 *
	 * @param mixed  $type_spec A schema's own `type` value, whatever shape it is.
	 * @param string $path      Human-readable location, for the exception message.
	 * @throws \RuntimeException If $type_spec (or one of its elements) isn't recognized.
	 */
	private static function assert_known_type( $type_spec, string $path ): void {
		$types = is_array( $type_spec ) ? $type_spec : array( $type_spec );
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) || ! in_array( $type, self::KNOWN_TYPES, true ) ) {
				throw new \RuntimeException( "{$path}: unrecognized JSON Schema type " . self::describe_value( $type ) . ' -- Babilim_Schema_Validator must be taught it before a schema using it can be trusted.' );
			}
		}
	}

	/**
	 * The actual data-checking recursion, once assert_known_keywords()
	 * has already vetted the whole schema tree once at the top-level
	 * validate() call -- recursive calls go through this, not validate(),
	 * so the same schema tree isn't re-vetted on every nested property.
	 *
	 * @param mixed  $data   Decoded JSON value for this node.
	 * @param array  $schema Decoded JSON Schema for this node.
	 * @param array  $root   The top-level schema document $ref resolves against.
	 * @param string $path   Human-readable location, for error messages.
	 * @return string[]
	 */
	private static function validate_node( $data, array $schema, array $root, string $path ): array {
		if ( isset( $schema['$ref'] ) ) {
			$schema = self::resolve_ref( (string) $schema['$ref'], $root );
		}

		if ( isset( $schema['oneOf'] ) && is_array( $schema['oneOf'] ) ) {
			return self::validate_one_of( $data, $schema['oneOf'], $root, $path );
		}

		$errors = array();

		if ( isset( $schema['type'] ) && ! self::matches_type( $data, $schema['type'] ) ) {
			return array( "{$path}: expected type " . self::describe_type_spec( $schema['type'] ) . ", got '" . self::describe_type( $data ) . "'" );
		}

		if ( array_key_exists( 'const', $schema ) && $data !== $schema['const'] ) {
			$errors[] = "{$path}: expected the exact value " . self::describe_value( $schema['const'] );
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
			$errors[] = "{$path}: value is not one of the allowed enum values";
		}

		if ( is_string( $data ) ) {
			if ( isset( $schema['pattern'] ) && 1 !== preg_match( '#' . $schema['pattern'] . '#', $data ) ) {
				$errors[] = "{$path}: '{$data}' does not match pattern '{$schema['pattern']}'";
			}
			if ( isset( $schema['minLength'] ) && mb_strlen( $data, 'UTF-8' ) < (int) $schema['minLength'] ) {
				$errors[] = "{$path}: shorter than minLength {$schema['minLength']}";
			}
			if ( isset( $schema['maxLength'] ) && mb_strlen( $data, 'UTF-8' ) > (int) $schema['maxLength'] ) {
				$errors[] = "{$path}: longer than maxLength {$schema['maxLength']}";
			}
			if ( isset( $schema['format'] ) && ! self::matches_format( $data, (string) $schema['format'] ) ) {
				$errors[] = "{$path}: '{$data}' does not match format '{$schema['format']}'";
			}
		}

		if ( is_int( $data ) || is_float( $data ) ) {
			if ( isset( $schema['minimum'] ) && $data < $schema['minimum'] ) {
				$errors[] = "{$path}: {$data} is below minimum {$schema['minimum']}";
			}
			if ( isset( $schema['maximum'] ) && $data > $schema['maximum'] ) {
				$errors[] = "{$path}: {$data} is above maximum {$schema['maximum']}";
			}
		}

		if ( is_object( $data ) ) {
			$props = get_object_vars( $data );
			if ( isset( $schema['maxProperties'] ) && count( $props ) > (int) $schema['maxProperties'] ) {
				$errors[] = "{$path}: has more than maxProperties {$schema['maxProperties']} properties";
			}
			foreach ( (array) ( $schema['required'] ?? array() ) as $required_key ) {
				if ( ! array_key_exists( $required_key, $props ) ) {
					$errors[] = "{$path}: missing required property '{$required_key}'";
				}
			}
			$known_props           = array_keys( (array) ( $schema['properties'] ?? array() ) );
			$additional_properties = array_key_exists( 'additionalProperties', $schema ) ? $schema['additionalProperties'] : true;
			foreach ( $props as $key => $value ) {
				if ( in_array( $key, $known_props, true ) ) {
					continue;
				}
				if ( false === $additional_properties ) {
					$errors[] = "{$path}: unexpected property '{$key}' (additionalProperties is false)";
				} elseif ( is_array( $additional_properties ) ) {
					$errors = array_merge( $errors, self::validate_node( $value, $additional_properties, $root, "{$path}.{$key}" ) );
				}
			}
			foreach ( (array) ( $schema['properties'] ?? array() ) as $key => $sub_schema ) {
				if ( array_key_exists( $key, $props ) ) {
					$errors = array_merge( $errors, self::validate_node( $props[ $key ], (array) $sub_schema, $root, "{$path}.{$key}" ) );
				}
			}
		}

		if ( is_array( $data ) ) {
			if ( isset( $schema['maxItems'] ) && count( $data ) > (int) $schema['maxItems'] ) {
				$errors[] = "{$path}: has more than maxItems {$schema['maxItems']} items";
			}
			if ( isset( $schema['minItems'] ) && count( $data ) < (int) $schema['minItems'] ) {
				$errors[] = "{$path}: has fewer than minItems {$schema['minItems']} items";
			}
			if ( ! empty( $schema['uniqueItems'] ) && count( array_unique( array_map( 'serialize', $data ) ) ) !== count( $data ) ) {
				$errors[] = "{$path}: has a repeated item (uniqueItems is true)";
			}
			if ( isset( $schema['items'] ) ) {
				foreach ( $data as $index => $item ) {
					$errors = array_merge( $errors, self::validate_node( $item, (array) $schema['items'], $root, "{$path}[{$index}]" ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * True `oneOf` semantics: valid only when $data matches exactly one
	 * of the alternatives (zero or more-than-one is an error) -- not "at
	 * least one", which would let data satisfying two overlapping
	 * branches through undetected.
	 *
	 * @param mixed $data
	 * @param array $alternatives
	 * @param array $root
	 * @return string[]
	 */
	private static function validate_one_of( $data, array $alternatives, array $root, string $path ): array {
		$matches = 0;
		foreach ( $alternatives as $alternative ) {
			if ( empty( self::validate_node( $data, (array) $alternative, $root, $path ) ) ) {
				++$matches;
			}
		}
		if ( 1 === $matches ) {
			return array();
		}
		return array( "{$path}: matched {$matches} of oneOf's alternatives (expected exactly 1)" );
	}

	/**
	 * Resolves a local `#/$defs/<name>` $ref against $root. Anything else
	 * (a cross-file reference, or a `#/$defs/<name>` this document
	 * doesn't actually define) throws -- see the class docblock.
	 *
	 * @param string $ref
	 * @param array  $root
	 * @return array
	 */
	private static function resolve_ref( string $ref, array $root ): array {
		if ( 0 === strpos( $ref, '#/$defs/' ) ) {
			$name = substr( $ref, strlen( '#/$defs/' ) );
			if ( ! isset( $root['$defs'][ $name ] ) || ! is_array( $root['$defs'][ $name ] ) ) {
				throw new \RuntimeException( "Unresolvable \$ref '{$ref}': no \$defs entry '{$name}' in this schema document." );
			}
			return $root['$defs'][ $name ];
		}
		throw new \RuntimeException( "Unsupported \$ref '{$ref}': only a local '#/\$defs/...' reference is implemented; a cross-file reference is out of this validator's scope (see the class docblock)." );
	}

	/**
	 * Supports both the bare-string and the array (`["integer", "null"]`)
	 * forms of `type` -- the array form matches when $data satisfies ANY
	 * one of the listed types. assert_known_keywords() already vets every
	 * type name in $type_spec before validate_node() ever reaches here
	 * (see its own call to assert_known_type()), so the `default` branch
	 * below is unreachable in practice through validate() -- it still
	 * throws rather than silently returning true, so a caller that
	 * exercises this method directly (as this class's own tests do) gets
	 * the same "fail loud" guarantee without depending on that ordering.
	 *
	 * @param mixed        $data
	 * @param string|array $type_spec
	 */
	private static function matches_type( $data, $type_spec ): bool {
		if ( is_array( $type_spec ) ) {
			foreach ( $type_spec as $type ) {
				if ( self::matches_type( $data, $type ) ) {
					return true;
				}
			}
			return false;
		}
		switch ( $type_spec ) {
			case 'object':
				return is_object( $data );
			case 'array':
				return is_array( $data );
			case 'string':
				return is_string( $data );
			case 'boolean':
				return is_bool( $data );
			case 'integer':
				return is_int( $data );
			case 'number':
				return is_int( $data ) || is_float( $data );
			case 'null':
				return null === $data;
			default:
				throw new \RuntimeException( "Unrecognized JSON Schema type " . self::describe_value( $type_spec ) . ' -- Babilim_Schema_Validator must be taught it before a schema using it can be trusted.' );
		}
	}

	/**
	 * @param string|array $type_spec
	 */
	private static function describe_type_spec( $type_spec ): string {
		if ( is_array( $type_spec ) ) {
			return "['" . implode( "', '", $type_spec ) . "']";
		}
		return "'{$type_spec}'";
	}

	/**
	 * Formats actually used anywhere under tests/contract/v1/schemas/:
	 * `date-time`, `uri`, `uri-reference`. Any other format value throws
	 * -- see the class docblock's "fail loud" rule; a fourth format value
	 * showing up in a future contract change must be taught here, not
	 * silently pass every string.
	 *
	 * @param string $value
	 * @param string $format
	 */
	private static function matches_format( string $value, string $format ): bool {
		switch ( $format ) {
			case 'date-time':
				return 1 === preg_match( self::DATE_TIME_PATTERN, $value );
			case 'uri':
				// A real URI needs an absolute scheme (RFC 3986 3.1);
				// "://" alone would also accept a scheme-relative string,
				// which `uri` (unlike `uri-reference`) must not.
				return 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value );
			case 'uri-reference':
				// A uri-reference may be relative, so almost anything
				// non-empty is legal; still rejects whitespace-only noise.
				return '' !== trim( $value );
			default:
				throw new \RuntimeException( "Unrecognized format '{$format}' -- Babilim_Schema_Validator must be taught it before a schema using it can be trusted." );
		}
	}

	/**
	 * @param mixed $data
	 */
	private static function describe_type( $data ): string {
		if ( is_object( $data ) ) {
			return 'object';
		}
		if ( is_array( $data ) ) {
			return 'array';
		}
		if ( is_string( $data ) ) {
			return 'string';
		}
		if ( is_bool( $data ) ) {
			return 'boolean';
		}
		if ( is_int( $data ) ) {
			return 'integer';
		}
		if ( is_float( $data ) ) {
			return 'number';
		}
		if ( null === $data ) {
			return 'null';
		}
		return gettype( $data );
	}

	/**
	 * @param mixed $value
	 */
	private static function describe_value( $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return var_export( $value, true );
		}
		return self::describe_type( $value );
	}
}
