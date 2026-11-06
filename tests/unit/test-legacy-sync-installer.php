<?php
/**
 * Proves that the published 1.0.1 sync installer, unchanged except for its
 * class name, makes the same install decisions from a 1.11.0 inventory
 * response (which carries `auto_install` on every `babilim_available`
 * cell) as from the same response without that key, as a 1.9.0 service
 * would send it.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/legacy/class-babilim-sync-installer-1.0.1.php';

/**
 * Answers the download and package endpoints and records every request.
 */
final class Babilim_Test_Legacy_Http implements Babilim_Http {

	/** @var array[] */
	public $requests = array();

	public function request( string $method, string $url, array $args ) {
		$this->requests[] = array( 'method' => $method, 'url' => $url );
		if ( 'POST' === $method && false !== strpos( $url, '/v1/downloads' ) ) {
			$build = json_decode( (string) $args['body'], true )['build_id'];
			return array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/' . $build ) );
		}
		if ( 'GET' === $method && false !== strpos( $url, '/v1/packages/' ) ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => 'zip-bytes', 'headers' => array() );
		}
		return new WP_Error( 'babilim_test_unrouted', $method . ' ' . $url );
	}
}

final class Test_Legacy_Sync_Installer extends TestCase {

	private const FIXTURE = 'inventory-1.11.0-available.json';

	/** @var array<string,string> */
	private $zip = array();

	protected function setUp(): void {
		babilim_test_reset();
	}

	private function fixture_path( string $name ): string {
		return dirname( __DIR__ ) . '/fixtures/' . $name;
	}

	/** @return array The 1.11.0 response body. */
	private function load_new_body(): array {
		return json_decode( (string) file_get_contents( $this->fixture_path( self::FIXTURE ) ), true );
	}

	/** Removes every `auto_install` key, at any depth. */
	private function strip_auto_install( array $value ): array {
		unset( $value['auto_install'] );
		foreach ( $value as $key => $child ) {
			if ( is_array( $child ) ) {
				$value[ $key ] = $this->strip_auto_install( $child );
			}
		}
		return $value;
	}

	/** Stages the package bytes the fake service sends for $slug / $locale. */
	private function stage_package( string $slug, string $locale, string $build ): void {
		$mo = Babilim_Mo_Writer::write(
			array( 'Save' => 'Save ' . $build, 'Cancel' => 'Cancel' ),
			array( 'Language' => $locale, 'X-Babilim-Site' => 'st_legacy', 'X-Babilim-Build' => $build )
		);
		$po = "msgid \"\"\nmsgstr \"\"\n\"X-Babilim-Site: st_legacy\\n\"\n\"X-Babilim-Build: {$build}\\n\"\n";

		$entries = babilim_test_read_mo( $mo );
		unset( $entries[''] );
		$this->zip = array(
			"{$slug}-{$locale}.mo" => $mo,
			"{$slug}-{$locale}.po" => $po,
		);
		$this->zip['manifest.json'] = wp_json_encode(
			array(
				'build_id'        => "{$slug}-1.0-{$locale}-20260101T000000-abc123",
				'entries'         => count( $entries ),
				'files'           => array(
					"{$slug}-{$locale}.mo" => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ),
					"{$slug}-{$locale}.po" => array( 'sha256' => hash( 'sha256', $po ), 'size' => strlen( $po ) ),
				),
				'locale'          => $locale,
				'status'          => 'published',
				'kind'            => 'plugin',
				'slug'            => $slug,
				'version'         => '2.4.0',
				'text_domain'     => $slug,
				'extra_domains'   => array(),
				'server_build_id' => $build,
				'build_no'        => 1,
				'issued_at'       => '2026-01-01T00:00:00Z',
				'contract'        => '1.3.0',
			)
		);
	}

	/**
	 * Runs install_available() of $class against $body and returns the
	 * install decisions plus the request URLs it made.
	 *
	 * @return array{0:array,1:string[]}
	 */
	private function decisions( string $class, array $body, bool $with_record ): array {
		babilim_test_reset();
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_legacy' );
		update_option( 'babilim_site_token', 'tok_legacy' );

		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$state    = new Babilim_State();
		$http     = new Babilim_Test_Legacy_Http();
		$inv      = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array( 'fr_FR', 'de_DE' ); }
		);
		$site     = new Babilim_Site( $http, 'https://api.babilim.org', $state, $inv, $manifest );

		$state->set( $body );
		if ( $with_record ) {
			$manifest->set(
				'plugin',
				'component-a',
				'de_DE',
				array(
					'build_id'     => 'bd_9f3c1a7e5d02',
					'files'        => array(),
					'integrity'    => array( 'ok' => true, 'checked_at' => '2026-01-01T00:00:00Z' ),
					'installed_at' => '2026-01-01T00:00:00Z',
				)
			);
		}
		$this->stage_package( 'component-a', 'fr_FR', 'bd_b3c4d5e6f701' );

		$zip       = &$this->zip;
		$parse     = static function ( string $path, string $format ) use ( $fs ) {
			$content = $fs->get( $path );
			if ( ! is_string( $content ) ) {
				return false;
			}
			if ( 'php' === $format ) {
				$data = eval( (string) preg_replace( '/^<\?php\s*/', '', $content ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
				return is_array( $data['messages'] ?? null ) ? count( $data['messages'] ) : false;
			}
			$entries = babilim_test_read_mo( $content );
			unset( $entries[''] );
			return count( $entries );
		};
		$transform = static function ( string $mo_path ) use ( $fs ) {
			$entries = babilim_test_read_mo( (string) $fs->get( $mo_path ) );
			unset( $entries[''] );
			return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
		};
		$unzip     = static function () use ( &$zip ) {
			return $zip;
		};
		$installer = new Babilim_Installer( $fs, $manifest, $transform, $parse, $unzip );

		$sync = new $class( $state, $site, $installer, $http, $fs, 'https://api.babilim.org' );
		$done = $sync->install_available( array( 'fr_FR', 'de_DE' ), 20 );

		$map = array();
		foreach ( $done as $item ) {
			$map[] = array( $item['kind'], $item['slug'], $item['locale'], $item['build_id'] );
		}
		$urls = array();
		foreach ( $http->requests as $request ) {
			$urls[] = $request['method'] . ' ' . $request['url'];
		}
		return array( $map, $urls );
	}

	public function test_a_1_0_1_installer_makes_the_same_install_decisions_from_a_1_11_0_response_as_from_a_1_9_0_one(): void {
		foreach ( array( false, true ) as $with_record ) {
			$new = $this->load_new_body();
			$old = $this->strip_auto_install( $new );
			$this->assertNotSame( $old, $new, 'the 1.11.0 fixture carries auto_install' );

			$from_old = $this->decisions( 'Babilim_Legacy_Sync_Installer', $old, $with_record );
			$from_new = $this->decisions( 'Babilim_Legacy_Sync_Installer', $new, $with_record );
			$this->assertSame( $from_old, $from_new, 'with_record=' . var_export( $with_record, true ) );

			if ( $with_record ) {
				$this->assertSame( array( array( 'plugin', 'component-a', 'fr_FR', 'bd_b3c4d5e6f701' ) ), $from_new[0] );
			} else {
				$this->assertSame( array(), $from_new[0] );
				$this->assertSame( array(), $from_new[1], 'no request is made' );
			}
		}
	}

	public function test_the_legacy_fixture_is_the_published_1_0_1_file(): void {
		$src = (string) file_get_contents( $this->fixture_path( 'legacy/class-babilim-sync-installer-1.0.1.php' ) );
		$this->assertSame( 1, substr_count( $src, 'class Babilim_Legacy_Sync_Installer' ) );

		$notes = (string) file_get_contents( $this->fixture_path( 'legacy/SOURCE.md' ) );
		$this->assertSame( 1, preg_match( '/sha256 of the original blob bytes: `([0-9a-f]{64})`/', $notes, $m ) );
		$this->assertSame( $m[1], hash( 'sha256', str_replace( 'class Babilim_Legacy_Sync_Installer', 'class Babilim_Sync_Installer', $src ) ) );
	}
}
