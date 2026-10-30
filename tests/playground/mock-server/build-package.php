<?php
/**
 * Host-side CLI helper: builds one downloadable translation package zip
 * (manifest.json + .mo/.po, optionally a default/ supplement), matching
 * `tests/contract/v1/schemas/package-manifest.schema.json`, the same
 * shape the unit suite's sync-install fixtures build.
 *
 * Real .mo bytes are produced with this repo's own Babilim_Mo_Writer
 * (loaded directly -- it has no WordPress dependency beyond the ABSPATH
 * guard, which this script defines itself) so the fixture is exactly
 * what the plugin's own writer would produce, not a hand-rolled stand-in.
 *
 * Used by run-checks.sh (never committed output -- every package zip
 * lands under tests/playground/_generated/, gitignored, and mock-server's
 * own router.php serves it back to a real Playground instance over
 * 127.0.0.1).
 *
 * Usage: php build-package.php <out-zip-path> <json-spec-path>
 * Spec shape: {
 *   "server_build_id": "bd_...", "kind": "plugin", "slug": "...",
 *   "locale": "...", "version": "1.0.0", "text_domain": "...",
 *   "entries": {"Save": "x", ...},           // main-domain msgid=>msgstr
 *   "default_entries": {"Cart": "y", ...},   // optional default-domain fragment
 *   "extra_domains": [], "build_no": 1
 * }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // Satisfies the writer's own guard; never used as a real path.
}

require __DIR__ . '/../../../includes/class-babilim-mo-writer.php';

function babilim_pkg_fail( string $msg ): void {
	fwrite( STDERR, "build-package.php: {$msg}\n" );
	exit( 1 );
}

$out_zip  = $argv[1] ?? '';
$spec_path = $argv[2] ?? '';
if ( '' === $out_zip || '' === $spec_path ) {
	babilim_pkg_fail( 'usage: build-package.php <out-zip-path> <json-spec-path>' );
}

$spec = json_decode( (string) file_get_contents( $spec_path ), true );
if ( ! is_array( $spec ) ) {
	babilim_pkg_fail( "could not parse spec json: {$spec_path}" );
}

$kind            = (string) ( $spec['kind'] ?? 'plugin' );
$slug            = (string) ( $spec['slug'] ?? '' );
$locale          = (string) ( $spec['locale'] ?? '' );
$version         = (string) ( $spec['version'] ?? '1.0.0' );
$text_domain     = (string) ( $spec['text_domain'] ?? $slug );
$server_build_id = (string) ( $spec['server_build_id'] ?? '' );
$build_no        = (int) ( $spec['build_no'] ?? 1 );
$extra_domains   = (array) ( $spec['extra_domains'] ?? array() );
$entries         = (array) ( $spec['entries'] ?? array() );
$default_entries = isset( $spec['default_entries'] ) && is_array( $spec['default_entries'] ) ? $spec['default_entries'] : null;

if ( '' === $slug || '' === $locale || '' === $server_build_id ) {
	babilim_pkg_fail( 'spec must set slug, locale, server_build_id' );
}

$mo = Babilim_Mo_Writer::write( $entries, array( 'Content-Type' => 'text/plain; charset=UTF-8' ) );
$po = "# generated fixture, not a real .po\n";

$files = array(
	"{$slug}-{$locale}.mo" => $mo,
	"{$slug}-{$locale}.po" => $po,
);

$manifest = array(
	'build_id'        => "{$slug}-{$version}-{$locale}-20260101T000000-fixture",
	'entries'         => count( $entries ),
	'files'           => array(
		"{$slug}-{$locale}.mo" => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ),
		"{$slug}-{$locale}.po" => array( 'sha256' => hash( 'sha256', $po ), 'size' => strlen( $po ) ),
	),
	'locale'          => $locale,
	'status'          => 'published',
	'kind'            => $kind,
	'slug'            => $slug,
	'version'         => $version,
	'text_domain'     => $text_domain,
	'extra_domains'   => $extra_domains,
	'server_build_id' => $server_build_id,
	'build_no'        => $build_no,
	'issued_at'       => '2026-01-01T00:00:00Z',
	'contract'        => '1.1.0',
);

if ( null !== $default_entries ) {
	$dmo = Babilim_Mo_Writer::write( $default_entries, array( 'Content-Type' => 'text/plain; charset=UTF-8' ) );
	$files["default/{$slug}-{$locale}.mo"] = $dmo;
	$manifest['default_domain_entries']    = count( $default_entries );
	$manifest['default_domain_files']      = array(
		"{$slug}-{$locale}.mo" => array( 'sha256' => hash( 'sha256', $dmo ), 'size' => strlen( $dmo ) ),
	);
}

if ( ! is_dir( dirname( $out_zip ) ) ) {
	mkdir( dirname( $out_zip ), 0777, true );
}
if ( file_exists( $out_zip ) ) {
	unlink( $out_zip );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $out_zip, ZipArchive::CREATE ) ) {
	babilim_pkg_fail( "could not create zip: {$out_zip}" );
}
$zip->addFromString( 'manifest.json', (string) json_encode( $manifest ) );
foreach ( $files as $relative => $bytes ) {
	$zip->addFromString( $relative, $bytes );
}
$zip->close();

fwrite( STDOUT, "OK {$out_zip} build_id={$server_build_id} entries=" . count( $entries ) . "\n" );
