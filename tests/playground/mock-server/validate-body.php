<?php
/**
 * Host-side CLI helper: validates a JSON document read from standard input
 * against one pinned contract schema, with the same validator the unit suite
 * uses (tests/fixtures/class-babilim-schema-validator.php).
 *
 * Usage: php validate-body.php <schema-file-name>   (a file under tests/contract/v1/schemas/)
 * Prints one line per violation and exits 1; prints nothing and exits 0 when valid.
 */

declare(strict_types=1);

require __DIR__ . '/../../fixtures/class-babilim-schema-validator.php';

$name = $argv[1] ?? '';
$path = __DIR__ . '/../../contract/v1/schemas/' . basename( $name );
if ( '' === $name || ! is_file( $path ) ) {
	fwrite( STDERR, "validate-body.php: unknown schema {$name}\n" );
	exit( 2 );
}
$schema = json_decode( (string) file_get_contents( $path ), true );
$data   = json_decode( (string) stream_get_contents( STDIN ) );
if ( ! is_array( $schema ) || null === $data ) {
	fwrite( STDERR, "validate-body.php: schema or body is not valid JSON\n" );
	exit( 2 );
}
$errors = Babilim_Schema_Validator::validate( $data, $schema );
foreach ( $errors as $error ) {
	echo $error . "\n";
}
exit( array() === $errors ? 0 : 1 );
