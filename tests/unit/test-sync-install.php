<?php
/**
 * End-to-end tests for the self-run sync install: a sync discovers that an
 * installed locale's build moved on, the cron step downloads and installs
 * it through the real Babilim_Installer, and the next sync reports the
 * installed build with integrity "ok".
 *
 * The service is a routing HTTP double that follows the real endpoints'
 * shapes (inventory, downloads, packages). Files come from a package whose
 * .mo carries the per-site X-Babilim-Site / X-Babilim-Build headers the
 * service now stamps into every file it sends, built with the real MO
 * writer; its manifest digests are those of the bytes this site received.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Routes by endpoint and records every request.
 */
final class Babilim_Test_Routing_Http implements Babilim_Http {

	/** @var array[] */
	public $requests = array();

	/** @var array[] Inventory responses; the last one repeats. */
	public $inventory_responses = array();

	/** @var array|null Overrides the POST /v1/downloads answer. */
	public $download_response = null;

	/** @var array<string,array> Per-build overrides of the POST /v1/downloads answer. */
	public $download_by_build = array();

	/** @var string */
	public $package_bytes = 'zip-bytes';

	public function request( string $method, string $url, array $args ) {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( 'POST' === $method && false !== strpos( $url, '/inventory' ) ) {
			return count( $this->inventory_responses ) > 1 ? array_shift( $this->inventory_responses ) : $this->inventory_responses[0];
		}
		if ( 'POST' === $method && false !== strpos( $url, '/v1/downloads' ) ) {
			if ( null !== $this->download_response ) {
				return $this->download_response;
			}
			$build = json_decode( (string) $args['body'], true )['build_id'];
			if ( isset( $this->download_by_build[ $build ] ) ) {
				return $this->download_by_build[ $build ];
			}
			return array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/' . $build ) );
		}
		if ( 'GET' === $method && false !== strpos( $url, '/v1/packages/' ) ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => $this->package_bytes, 'headers' => array() );
		}
		return new WP_Error( 'babilim_test_unrouted', $method . ' ' . $url );
	}

	/** @return array[] Requests whose URL contains $needle. */
	public function to( string $needle ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $needle ) {
					return false !== strpos( $request['url'], $needle );
				}
			)
		);
	}
}

final class Test_Sync_Install extends TestCase {

	private const OLD = 'bd_000000000001';
	private const NEW = 'bd_000000000002';

	/** @var Babilim_Memory_Fs */
	private $fs;

	/** @var Babilim_Manifest */
	private $manifest;

	/** @var Babilim_Test_Routing_Http */
	private $http;

	/** @var Babilim_State */
	private $state;

	/** @var Babilim_Site */
	private $site;

	/** @var Babilim_Sync_Installer */
	private $sync_installer;

	/** @var Babilim_Installer */
	private $installer;

	/** @var array<string,string> */
	private $zip = array();

	protected function setUp(): void {
		babilim_test_reset();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		update_option( 'babilim_consent', true );
		update_option( 'babilim_site_id', 'st_e2e' );
		update_option( 'babilim_site_token', 'tok_e2e' );

		$this->fs       = new Babilim_Memory_Fs();
		$this->manifest = new Babilim_Manifest( $this->fs );
		$this->state    = new Babilim_State();
		$this->http     = new Babilim_Test_Routing_Http();

		$inventory = new Babilim_Inventory(
			static function () { return array( 'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce', 'Version' => '9.0', 'TextDomain' => 'woocommerce' ) ); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array( 'zh_TW' ); }
		);
		$this->site = new Babilim_Site( $this->http, 'https://api.babilim.org', $this->state, $inventory, $this->manifest );

		$fs     = $this->fs;
		$zip    = &$this->zip;
		$parse  = static function ( string $path, string $format ) use ( $fs ) {
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
		$installer       = new Babilim_Installer( $this->fs, $this->manifest, $transform, $parse, $unzip );
		$this->installer = $installer;

		$this->sync_installer = new Babilim_Sync_Installer( $this->state, $this->site, $installer, $this->http, $this->fs, 'https://api.babilim.org' );
	}

	/**
	 * Builds the package this site is sent for $build: .mo and .po carrying
	 * the per-site header identifiers, digests over exactly those bytes.
	 */
	private function stage_package( string $build, string $site_tag ): string {
		$mo = Babilim_Mo_Writer::write(
			array( 'Save' => 'Speichern ' . $build, 'Cancel' => 'Abbrechen' ),
			array( 'Language' => 'zh_TW', 'X-Babilim-Site' => $site_tag, 'X-Babilim-Build' => $build )
		);
		$po = "msgid \"\"\nmsgstr \"\"\n\"X-Babilim-Site: {$site_tag}\\n\"\n\"X-Babilim-Build: {$build}\\n\"\n";

		$this->zip = array(
			'woocommerce-zh_TW.mo' => $mo,
			'woocommerce-zh_TW.po' => $po,
		);
		$entries               = babilim_test_read_mo( $mo );
		unset( $entries[''] );
		$this->zip['manifest.json'] = wp_json_encode(
			array(
				'build_id'        => 'woocommerce-9.0-zh_TW-20260101T000000-abc123',
				'entries'         => count( $entries ),
				'files'           => array(
					'woocommerce-zh_TW.mo' => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ),
					'woocommerce-zh_TW.po' => array( 'sha256' => hash( 'sha256', $po ), 'size' => strlen( $po ) ),
				),
				'locale'          => 'zh_TW',
				'status'          => 'published',
				'kind'            => 'plugin',
				'slug'            => 'woocommerce',
				'version'         => '9.0',
				'text_domain'     => 'woocommerce',
				'extra_domains'   => array(),
				'server_build_id' => $build,
				'build_no'        => 2,
				'issued_at'       => '2026-01-01T00:00:00Z',
				'contract'        => '1.3.0',
			)
		);
		return $mo;
	}

	private function inventory_answer( array $updates ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'components' => array(
						array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => self::OLD, 'update_available' => array() !== $updates ) ) ),
					),
					'updates'    => $updates,
					'license'    => array(),
					'quota'      => array(),
					'features'   => array(),
					'contract'   => '1.3.0',
				)
			),
			'headers'  => array(),
		);
	}

	private function update_entry( string $build = self::NEW ): array {
		return array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locale' => 'zh_TW', 'build_id' => $build, 'build_no' => 2 );
	}

	private function seed_installed_old_build(): void {
		$this->manifest->set(
			'plugin',
			'woocommerce',
			'zh_TW',
			array(
				'build_id'    => self::OLD,
				'files'       => array(),
				'integrity'   => array( 'ok' => true, 'checked_at' => '2026-01-01T00:00:00Z' ),
				'installed_at' => '2026-01-01T00:00:00Z',
			)
		);
	}

	/** The `installed` map the LAST inventory request reported. */
	private function last_reported_installed(): array {
		$inventory = $this->http->to( '/inventory' );
		$body      = json_decode( (string) end( $inventory )['args']['body'], true );
		foreach ( $body['components'] as $component ) {
			if ( 'woocommerce' === $component['slug'] ) {
				return (array) $component['installed'];
			}
		}
		return array();
	}

	// -- the main line -------------------------------------------------

	public function test_sync_discovers_a_new_build_then_installs_it_and_the_next_sync_reports_integrity_ok(): void {
		$this->seed_installed_old_build();
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->http->inventory_responses = array( $this->inventory_answer( array( $this->update_entry() ) ), $this->inventory_answer( array() ) );

		$this->site->sync();
		$this->assertSame( self::OLD, $this->last_reported_installed()['zh_TW']['build_id'], 'the first sync still reports the old build' );
		$this->assertNotNull( $this->state->update_for( 'plugin', 'woocommerce', 'zh_TW' ), 'and discovers the update' );

		$done = $this->sync_installer->install_pending_updates();

		$this->assertCount( 1, $done );
		$this->assertTrue( $done[0]['result']->is_ok(), $done[0]['result']->message() );
		$this->assertSame( self::NEW, ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' )['build_id'] );
		$this->assertNull( $this->state->update_for( 'plugin', 'woocommerce', 'zh_TW' ), 'the offered update is consumed' );

		$this->site->sync();
		$reported = $this->last_reported_installed()['zh_TW'];
		$this->assertSame( self::NEW, $reported['build_id'] );
		$this->assertSame( 'ok', $reported['integrity'], 'the next sync reports the installed build with integrity ok' );
	}

	public function test_the_installed_bytes_are_exactly_what_this_site_received_including_its_header_identifiers(): void {
		$this->seed_installed_old_build();
		$mo = $this->stage_package( self::NEW, 'st_e2e' );
		$this->http->inventory_responses = array( $this->inventory_answer( array( $this->update_entry() ) ) );
		$this->site->sync();

		$this->sync_installer->install_pending_updates();

		$served = $this->fs->get( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) );
		$this->assertSame( $mo, $served, 'the served .mo is byte-identical to the package file' );
		$this->assertSame( 'st_e2e', Babilim_Mo_Writer::read_header( (string) $served, 'X-Babilim-Site' ) );
		$this->assertSame( self::NEW, Babilim_Mo_Writer::read_header( (string) $served, 'X-Babilim-Build' ) );
		$record = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertTrue( $record['integrity']['ok'] );
		$this->assertSame( hash( 'sha256', $mo ), $record['files']['plugins/woocommerce-zh_TW.mo'] ?? null );
	}

	public function test_the_same_build_stamped_for_two_sites_has_different_digests_and_both_install_with_integrity_ok(): void {
		$digests = array();
		foreach ( array( 'st_site_a', 'st_site_b' ) as $tag ) {
			$this->setUp();
			$this->seed_installed_old_build();
			$mo = $this->stage_package( self::NEW, $tag );
			$this->http->inventory_responses = array( $this->inventory_answer( array( $this->update_entry() ) ), $this->inventory_answer( array() ) );
			$this->site->sync();
			$done = $this->sync_installer->install_pending_updates();
			$this->assertTrue( $done[0]['result']->is_ok(), $tag . ': ' . $done[0]['result']->message() );
			$this->site->sync();
			$this->assertSame( 'ok', $this->last_reported_installed()['zh_TW']['integrity'], $tag );
			$digests[ $tag ] = hash( 'sha256', $mo );
		}
		$this->assertNotSame( $digests['st_site_a'], $digests['st_site_b'] );
	}

	// -- what is and is not installed automatically ---------------------

	public function test_a_locale_that_was_never_installed_is_not_installed_automatically(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		// Available, but not in updates[]: a first install stays manual.
		$this->state->set( array( 'components' => array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => self::NEW ) ) ) ), 'updates' => array() ) );

		$done = $this->sync_installer->install_pending_updates();

		$this->assertSame( array(), $done );
		$this->assertCount( 0, $this->http->requests );
		$this->assertNull( $this->manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	public function test_no_consent_or_no_token_means_zero_requests(): void {
		$this->state->set( array( 'updates' => array( $this->update_entry() ) ) );

		delete_option( 'babilim_consent' );
		$this->assertSame( array(), $this->sync_installer->install_pending_updates() );

		update_option( 'babilim_consent', true );
		delete_option( 'babilim_site_token' );
		$done = $this->sync_installer->install_pending_updates();
		$this->assertSame( 'no_token', $done[0]['result']->code() );
		$this->assertCount( 0, $this->http->requests );
	}

	// -- the verification-required state ---------------------------------

	public function test_in_the_verification_warning_state_nothing_is_downloaded(): void {
		$this->state->set( array( 'updates' => array( $this->update_entry() ) ) );
		$this->site->note_server_unverified( 'callback_unreachable' );

		$done = $this->sync_installer->install_pending_updates();

		$this->assertSame( 'verification_required', $done[0]['result']->code() );
		$this->assertCount( 0, $this->http->requests, 'no download request is made while the site is unverified' );
	}

	public function test_a_403_verification_required_from_downloads_sets_the_warning_and_ends_the_run_without_retrying(): void {
		$this->state->set( array( 'updates' => array( $this->update_entry(), array_merge( $this->update_entry( 'bd_000000000003' ), array( 'slug' => 'other' ) ) ) ) );
		$this->http->download_response = array(
			'response' => array( 'code' => 403 ),
			'body'     => (string) wp_json_encode( array( 'error' => array( 'code' => 'verification_required', 'message' => 'x', 'hint' => 'y' ) ) ),
			'headers'  => array(),
		);

		$done = $this->sync_installer->install_pending_updates();

		$this->assertCount( 1, $done, 'the run stops at the first verification_required' );
		$this->assertSame( 'verification_required', $done[0]['result']->code() );
		$this->assertNotNull( $this->site->verification_warning() );
		$this->assertCount( 1, $this->http->to( '/v1/downloads' ) );

		$this->sync_installer->install_pending_updates();
		$this->assertCount( 1, $this->http->to( '/v1/downloads' ), 'and later runs make no download request at all' );
		$this->assertSame( array(), (array) get_option( Babilim_Sync_Installer::OPTION_FAILURES, array() ), 'it is not counted as the build failing' );
	}

	// -- failure handling --------------------------------------------------

	public function test_a_build_that_keeps_failing_is_given_up_on_after_three_automatic_attempts(): void {
		$this->seed_installed_old_build();
		$this->stage_package( self::NEW, 'st_e2e' );
		// A digest that never matches: every attempt fails the same way.
		$manifest                        = json_decode( $this->zip['manifest.json'], true );
		$manifest['files']['woocommerce-zh_TW.mo']['sha256'] = str_repeat( '0', 64 );
		$this->zip['manifest.json']      = wp_json_encode( $manifest );
		$this->state->set( array( 'updates' => array( $this->update_entry() ) ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$done = $this->sync_installer->install_pending_updates();
			$this->assertCount( 1, $done, 'attempt ' . ( $i + 1 ) );
			$this->assertTrue( $done[0]['result']->is_error() );
		}
		$downloads = count( $this->http->to( '/v1/downloads' ) );

		$this->assertSame( array(), $this->sync_installer->install_pending_updates() );
		$this->assertCount( $downloads, $this->http->to( '/v1/downloads' ), 'the fourth run makes no request' );
		$this->assertSame( self::OLD, $this->manifest->installed( 'plugin', 'woocommerce', 'zh_TW' )['build_id'], 'the failed installs rolled back to the previous build' );
	}

	/** Renames the staged package's text domain, files included. */
	private function restage_under_text_domain( string $domain ): void {
		$manifest = json_decode( $this->zip['manifest.json'], true );
		$zip      = array();
		foreach ( $this->zip as $name => $bytes ) {
			$zip[ str_replace( 'woocommerce-', $domain . '-', $name ) ] = $bytes;
		}
		$files = array();
		foreach ( $manifest['files'] as $name => $digest ) {
			$files[ str_replace( 'woocommerce-', $domain . '-', $name ) ] = $digest;
		}
		$manifest['files']       = $files;
		$manifest['text_domain'] = $domain;
		$zip['manifest.json']    = wp_json_encode( $manifest );
		$this->zip               = $zip;
	}

	public function test_install_build_passes_the_sites_own_text_domain_so_a_package_with_another_is_refused(): void {
		$this->seed_installed_old_build();
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->restage_under_text_domain( 'other' );

		$result = $this->sync_installer->install_build( 'plugin', 'woocommerce', 'zh_TW', self::NEW );

		$this->assertSame( 'text_domain_mismatch', $result->code() );
		$this->assertSame( self::OLD, $this->manifest->installed( 'plugin', 'woocommerce', 'zh_TW' )['build_id'], 'the existing record is untouched' );
	}

	public function test_install_build_sends_no_site_text_domain_for_a_component_the_inventory_lacks(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->restage_under_text_domain( 'other' );
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array( 'zh_TW' ); }
		);
		$site      = new Babilim_Site( $this->http, 'https://api.babilim.org', $this->state, $inventory, $this->manifest );
		$sync      = new Babilim_Sync_Installer( $this->state, $site, $this->installer, $this->http, $this->fs, 'https://api.babilim.org' );

		$result = $sync->install_build( 'plugin', 'woocommerce', 'zh_TW', self::NEW );

		$this->assertTrue( $result->is_ok(), $result->message() );
	}

	public function test_a_successful_install_clears_the_failure_counter(): void {
		$this->seed_installed_old_build();
		$this->stage_package( self::NEW, 'st_e2e' );
		update_option( Babilim_Sync_Installer::OPTION_FAILURES, array( self::NEW => 2 ) );
		$this->state->set( array( 'updates' => array( $this->update_entry() ) ) );

		$done = $this->sync_installer->install_pending_updates();

		$this->assertTrue( $done[0]['result']->is_ok(), $done[0]['result']->message() );
		$this->assertFalse( get_option( Babilim_Sync_Installer::OPTION_FAILURES, false ) );
	}

	// -- installing what is available (first installs) ---------------------

	/** @param array[] $extra_components More state components. */
	private function seed_available( array $cell_extra = array(), array $extra_components = array(), array $updates = array() ): void {
		$this->state->set(
			array(
				'components' => array_merge(
					array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => self::NEW ) + $cell_extra ) ) ),
					$extra_components
				),
				'updates'    => $updates,
			)
		);
	}

	/** A second component whose single locale is available. */
	private function other_available( string $slug, string $build ): array {
		return array( 'kind' => 'plugin', 'slug' => $slug, 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => $build ) ) );
	}

	public function test_an_available_cell_without_any_flag_is_installed(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->seed_available();

		$done = $this->sync_installer->install_available( array( 'zh_TW' ), 20 );

		$this->assertCount( 1, $done );
		$this->assertTrue( $done[0]['result']->is_ok(), $done[0]['result']->message() );
		$this->assertSame( self::NEW, ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' )['build_id'] );
		$this->assertSame( 1, (int) get_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALLED, 0 ) );
	}

	public function test_on_multisite_nothing_is_installed_automatically_even_with_the_flag(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->seed_available( array( 'auto_install' => true ) );

		$done = $this->sync_installer->install_available( array( 'zh_TW' ), 20 );

		$this->assertSame( array(), $done );
		$this->assertCount( 0, $this->http->requests );
		$this->assertNull( $this->manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	public function test_the_same_available_response_installs_identically_for_free_paid_and_partner(): void {
		$runs = array();
		foreach ( array( 'free', 'paid', 'partner' ) as $kind ) {
			$this->setUp();
			$this->stage_package( self::NEW, 'st_e2e' );
			$this->seed_available();
			$state            = $this->state->get();
			$state['license'] = array( 'kind' => $kind );
			$this->state->set( $state );

			$done = $this->sync_installer->install_available( array( 'zh_TW' ), 20 );

			$this->assertCount( 1, $done, $kind );
			$this->assertTrue( $done[0]['result']->is_ok(), $kind );
			$runs[ $kind ] = array(
				'done'     => array( $done[0]['kind'], $done[0]['slug'], $done[0]['locale'], $done[0]['build_id'] ),
				'requests' => array_map(
					static function ( $request ) {
						return $request['method'] . ' ' . $request['url'];
					},
					$this->http->requests
				),
			);
		}
		$this->assertSame( $runs['free'], $runs['paid'] );
		$this->assertSame( $runs['free'], $runs['partner'] );
	}

	public function test_a_stale_auto_install_key_of_any_value_changes_nothing(): void {
		foreach ( array( true, false, 1, '1', 'true', null ) as $i => $flag ) {
			$this->setUp();
			$this->stage_package( self::NEW, 'st_e2e' );
			$this->seed_available( array( 'auto_install' => $flag ) );

			$this->assertCount( 1, $this->sync_installer->install_available( array( 'zh_TW' ), 20 ), "variant $i" );
			$this->assertNotNull( $this->manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ), "variant $i" );
		}
	}

	public function test_the_flag_does_not_bypass_the_skip_list_or_an_existing_record(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->seed_available( array( 'auto_install' => true ) );
		update_option( Babilim_Sync_Installer::OPTION_SKIP, array( 'plugin:woocommerce:zh_TW' => true ), false );
		$this->assertSame( array(), $this->sync_installer->install_available( array( 'zh_TW' ), 20 ), 'skip list' );
		$this->assertCount( 0, $this->http->requests );

		delete_option( Babilim_Sync_Installer::OPTION_SKIP );
		$this->seed_installed_old_build();
		$this->assertSame( array(), $this->sync_installer->install_available( array( 'zh_TW' ), 20 ), 'existing record' );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_updates_and_unflagged_available_cells_are_both_installed(): void {
		$this->seed_installed_old_build();
		$this->stage_package( self::NEW, 'st_e2e' );
		$unflagged = array( 'kind' => 'plugin', 'slug' => 'second', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000003' ) ) );
		$this->seed_available( array(), array( $unflagged ), array( $this->update_entry() ) );

		$done = $this->sync_installer->install_pending_updates();
		$this->assertCount( 1, $done );
		$this->assertTrue( $done[0]['result']->is_ok(), $done[0]['result']->message() );
		$this->assertSame( self::NEW, ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' )['build_id'] );

		$available = $this->sync_installer->install_available( array( 'zh_TW' ), 20 );
		$this->assertCount( 1, $available, 'the unflagged available cell is installed too' );
		$this->assertSame( 'second', $available[0]['slug'] );
	}

	/**
	 * Every string literal in the body of install_available(), in order.
	 *
	 * @return string[]
	 */
	private static function install_available_string_literals(): array {
		$tokens  = token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-babilim-sync-installer.php' ) );
		$count   = count( $tokens );
		$strings = array();
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $count && ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) ) {
				++$j;
			}
			if ( ! isset( $tokens[ $j ] ) || 'install_available' !== $tokens[ $j ][1] ) {
				continue;
			}
			while ( $j < $count && '{' !== $tokens[ $j ] ) {
				++$j;
			}
			$depth = 0;
			for ( ; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $t ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
					$strings[] = trim( $t[1], '\'"' );
				}
			}
			return $strings;
		}
		return $strings;
	}

	public function test_the_literal_walker_reads_the_body_of_install_available(): void {
		$strings = self::install_available_string_literals();
		$this->assertContains( 'babilim_available', $strings, 'the method body was walked' );
		$this->assertContains( 'build_id', $strings );
	}

	public function test_install_available_does_not_read_the_license(): void {
		$this->assertNotContains( 'license', self::install_available_string_literals(), 'install_available() must not read the license' );
	}

	public function test_install_available_does_not_read_a_cell_key_named_auto_install(): void {
		$this->assertNotContains( 'auto_install', self::install_available_string_literals(), 'install_available() must not read a cell key named auto_install' );
	}

	public function test_a_locale_that_is_not_managed_is_not_installed(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->seed_available( array( 'auto_install' => true ) );

		$this->assertSame( array(), $this->sync_installer->install_available( array( 'de_DE' ), 20 ) );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_a_skipped_triple_is_not_installed_until_a_download_clears_it(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->seed_available( array( 'auto_install' => true ) );
		update_option( Babilim_Sync_Installer::OPTION_SKIP, array( 'plugin:woocommerce:zh_TW' => true ), false );

		$this->assertSame( array(), $this->sync_installer->install_available( array( 'zh_TW' ), 20 ) );
		$this->assertCount( 0, $this->http->requests );

		Babilim_Sync_Installer::clear_skip( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertCount( 1, $this->sync_installer->install_available( array( 'zh_TW' ), 20 ) );
	}

	public function test_remember_skip_and_clear_skip_round_trip(): void {
		Babilim_Sync_Installer::remember_skip( 'plugin', 'a', 'zh_TW' );
		Babilim_Sync_Installer::remember_skip( 'theme', 'b', 'de_DE' );
		$this->assertSame( array( 'plugin:a:zh_TW' => true, 'theme:b:de_DE' => true ), get_option( Babilim_Sync_Installer::OPTION_SKIP ) );
		Babilim_Sync_Installer::clear_skip( 'plugin', 'a', 'zh_TW' );
		Babilim_Sync_Installer::clear_skip( 'theme', 'b', 'de_DE' );
		$this->assertFalse( get_option( Babilim_Sync_Installer::OPTION_SKIP, false ) );
	}

	public function test_option_off_or_no_consent_makes_zero_requests(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->seed_available( array( 'auto_install' => true ) );

		update_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALL, 0, false );
		$this->assertSame( array(), $this->sync_installer->install_available( array( 'zh_TW' ), 20 ) );

		update_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALL, 1, false );
		delete_option( 'babilim_consent' );
		$this->assertSame( array(), $this->sync_installer->install_available( array( 'zh_TW' ), 20 ) );

		$this->assertCount( 0, $this->http->requests );
	}

	public function test_a_missing_option_counts_as_on(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->seed_available( array( 'auto_install' => true ) );
		$this->assertFalse( get_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALL, false ) );

		$this->assertCount( 1, $this->sync_installer->install_available( array( 'zh_TW' ), 20 ) );
	}

	public function test_the_limit_is_honoured_and_shared_with_updates(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->seed_available( array( 'auto_install' => true ), array( $this->other_available( 'second', 'bd_000000000003' ) ) );

		$this->assertSame( array(), $this->sync_installer->install_available( array( 'zh_TW' ), 0 ) );
		$this->assertCount( 0, $this->http->requests );

		$done = $this->sync_installer->install_available( array( 'zh_TW' ), 1 );
		$this->assertCount( 1, $done, 'only the remaining budget is used' );
		$this->assertSame( 'woocommerce', $done[0]['slug'] );
	}

	public function test_updates_and_available_together_stay_within_max_per_run(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$updates = array();
		for ( $i = 0; $i < Babilim_Sync_Installer::MAX_PER_RUN; $i++ ) {
			$updates[] = array( 'kind' => 'plugin', 'slug' => 'u' . $i, 'locale' => 'zh_TW', 'build_id' => sprintf( 'bd_%012d', 100 + $i ) );
		}
		$this->state->set( array_merge( $this->state_with_available(), array( 'updates' => $updates ) ) );
		$this->http->download_response = array( 'response' => array( 'code' => 500 ), 'body' => '', 'headers' => array() );

		$updated   = $this->sync_installer->install_pending_updates();
		$available = $this->sync_installer->install_available( array( 'zh_TW' ), Babilim_Sync_Installer::MAX_PER_RUN - count( $updated ) );

		$this->assertCount( Babilim_Sync_Installer::MAX_PER_RUN, $updated );
		$this->assertSame( array(), $available );
	}

	/** @return array<string,mixed> */
	private function state_with_available(): array {
		return array(
			'components' => array( array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => self::NEW, 'auto_install' => true ) ) ) ),
		);
	}

	public function test_a_failing_available_build_is_given_up_on_after_three_attempts(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$manifest                                            = json_decode( $this->zip['manifest.json'], true );
		$manifest['files']['woocommerce-zh_TW.mo']['sha256'] = str_repeat( '0', 64 );
		$this->zip['manifest.json']                          = wp_json_encode( $manifest );
		$this->seed_available( array( 'auto_install' => true ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertCount( 1, $this->sync_installer->install_available( array( 'zh_TW' ), 20 ), 'attempt ' . ( $i + 1 ) );
		}
		$downloads = count( $this->http->to( '/v1/downloads' ) );

		$this->assertSame( array(), $this->sync_installer->install_available( array( 'zh_TW' ), 20 ) );
		$this->assertCount( $downloads, $this->http->to( '/v1/downloads' ) );
		$this->assertSame( 0, (int) get_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALLED, 0 ), 'failures are not counted as installed' );
	}

	// -- the service's refusals are not this build's failures ------------

	private function refusal( string $code ): array {
		return array(
			'response' => array( 'code' => 403 ),
			'body'     => (string) wp_json_encode( array( 'error' => array( 'code' => $code, 'message' => 'x', 'hint' => 'y' ) ) ),
			'headers'  => array(),
		);
	}

	public function test_a_service_refusal_is_not_counted_as_a_failed_attempt_and_the_run_goes_on(): void {
		foreach ( array( 'quota_exceeded', 'license_required', 'slot_required' ) as $code ) {
			$this->setUp();
			$this->seed_available( array(), array( $this->other_available( 'second', 'bd_000000000003' ) ) );
			$this->http->download_response = $this->refusal( $code );

			$done = $this->sync_installer->install_available( array( 'zh_TW' ), 20 );

			$this->assertSame( array(), $done, $code . ': a refusal is not an outcome of the run' );
			$this->assertSame( array(), (array) get_option( Babilim_Sync_Installer::OPTION_FAILURES, array() ), $code );
			$this->assertCount( 2, $this->http->to( '/v1/downloads' ), $code );
		}
	}

	public function test_a_bad_package_is_still_counted(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$manifest                                            = json_decode( $this->zip['manifest.json'], true );
		$manifest['files']['woocommerce-zh_TW.mo']['sha256'] = str_repeat( '0', 64 );
		$this->zip['manifest.json']                          = wp_json_encode( $manifest );
		$this->seed_available();

		$this->sync_installer->install_available( array( 'zh_TW' ), 20 );

		$this->assertSame( array( self::NEW => 1 ), get_option( Babilim_Sync_Installer::OPTION_FAILURES ) );
	}

	public function test_a_service_refusal_on_an_update_is_not_counted(): void {
		foreach ( array( 'quota_exceeded', 'license_required', 'slot_required' ) as $code ) {
			$this->setUp();
			$this->state->set( array( 'updates' => array( $this->update_entry(), array_merge( $this->update_entry( 'bd_000000000003' ), array( 'slug' => 'other' ) ) ) ) );
			$this->http->download_response = $this->refusal( $code );

			$done = $this->sync_installer->install_pending_updates();

			$this->assertSame( array(), $done, $code );
			$this->assertSame( array(), (array) get_option( Babilim_Sync_Installer::OPTION_FAILURES, array() ), $code );
			$this->assertCount( 2, $this->http->to( '/v1/downloads' ), $code . ': each build was asked for once' );
		}
	}

	public function test_a_refused_first_cell_does_not_stop_the_second_cell_from_installing(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$this->state->set(
			array(
				'components' => array(
					$this->other_available( 'refused', 'bd_000000000003' ),
					array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => self::NEW ) ) ),
				),
			)
		);
		$this->http->download_by_build['bd_000000000003'] = $this->refusal( 'slot_required' );

		$done = $this->sync_installer->install_available( array( 'zh_TW' ), 20 );

		$this->assertCount( 1, $done );
		$this->assertTrue( $done[0]['result']->is_ok(), $done[0]['result']->message() );
		$this->assertSame( self::NEW, ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woocommerce', 'zh_TW' )['build_id'] );
		$this->assertSame( array(), (array) get_option( Babilim_Sync_Installer::OPTION_FAILURES, array() ) );
	}

	// -- a refused build is left alone until the service's state changes --

	/** @return array<string,array{string}> */
	public static function refusal_codes(): array {
		return array(
			'quota_exceeded'   => array( 'quota_exceeded' ),
			'license_required' => array( 'license_required' ),
			'slot_required'    => array( 'slot_required' ),
		);
	}

	/**
	 * @dataProvider refusal_codes
	 */
	public function test_refused_cells_do_not_use_up_the_run_and_the_next_cell_is_installed( string $code ): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$components = array();
		for ( $i = 0; $i < Babilim_Sync_Installer::MAX_REFUSALS_PER_RUN - 1; $i++ ) {
			$build                                   = sprintf( 'bd_%012d', 500 + $i );
			$components[]                            = $this->other_available( 'refused' . $i, $build );
			$this->http->download_by_build[ $build ] = $this->refusal( $code );
		}
		$components[] = array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => self::NEW ) ) );
		$this->state->set( array( 'components' => $components ) );

		// One install left in the run: the refusals before it must not use it up.
		$done = $this->sync_installer->install_available( array( 'zh_TW' ), 1 );

		$this->assertCount( 1, $done, 'only the install is an outcome of the run' );
		$this->assertTrue( $done[0]['result']->is_ok(), $done[0]['result']->message() );
		$this->assertSame( 'woocommerce', $done[0]['slug'] );
		$this->assertSame( array(), (array) get_option( Babilim_Sync_Installer::OPTION_FAILURES, array() ) );
	}

	public function test_a_run_stops_asking_once_it_has_been_refused_max_refusals_times(): void {
		$this->stage_package( self::NEW, 'st_e2e' );
		$components = array();
		for ( $i = 0; $i < Babilim_Sync_Installer::MAX_REFUSALS_PER_RUN + 3; $i++ ) {
			$build                                   = sprintf( 'bd_%012d', 500 + $i );
			$components[]                            = $this->other_available( 'refused' . $i, $build );
			$this->http->download_by_build[ $build ] = $this->refusal( 'slot_required' );
		}
		$components[] = array( 'kind' => 'plugin', 'slug' => 'woocommerce', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => self::NEW ) ) );
		$this->state->set( array( 'components' => $components ) );

		$done = $this->sync_installer->install_available( array( 'zh_TW' ), Babilim_Sync_Installer::MAX_PER_RUN );

		$this->assertSame( array(), $done, 'the cell after the cap is not tried in this run' );
		$this->assertCount( Babilim_Sync_Installer::MAX_REFUSALS_PER_RUN, $this->http->to( '/v1/downloads' ) );
	}

	public function test_refusals_of_updates_count_towards_the_same_cap_as_available_cells(): void {
		$updates = array();
		for ( $i = 0; $i < Babilim_Sync_Installer::MAX_REFUSALS_PER_RUN; $i++ ) {
			$updates[] = array_merge( $this->update_entry( sprintf( 'bd_%012d', 500 + $i ) ), array( 'slug' => 'upd' . $i ) );
		}
		$this->seed_available( array(), array(), $updates );
		$this->http->download_response = $this->refusal( 'quota_exceeded' );

		$this->assertSame( array(), $this->sync_installer->install_pending_updates() );
		$this->assertCount( Babilim_Sync_Installer::MAX_REFUSALS_PER_RUN, $this->http->to( '/v1/downloads' ) );

		$this->assertSame( array(), $this->sync_installer->install_available( array( 'zh_TW' ), Babilim_Sync_Installer::MAX_PER_RUN ) );
		$this->assertCount( Babilim_Sync_Installer::MAX_REFUSALS_PER_RUN, $this->http->to( '/v1/downloads' ), 'the run already reached the cap' );
	}

	/**
	 * @dataProvider refusal_codes
	 */
	public function test_a_refused_build_is_not_asked_for_again_until_the_state_changes( string $code ): void {
		$this->seed_available();
		$this->http->download_response = $this->refusal( $code );

		$this->sync_installer->install_available( array( 'zh_TW' ), 20 );
		$this->assertCount( 1, $this->http->to( '/v1/downloads' ) );

		$again = $this->sync_installer->install_available( array( 'zh_TW' ), 20 );
		$this->assertSame( array(), $again );
		$this->assertCount( 1, $this->http->to( '/v1/downloads' ), 'a repeat run sends nothing for the same build' );

		// A new sync answer with the same content (a different fetched_at) changes nothing.
		$state               = $this->state->get();
		$state['fetched_at'] = '2031-01-01T00:00:00+00:00';
		$this->state->set( $state );
		$this->sync_installer->install_available( array( 'zh_TW' ), 20 );
		$this->assertCount( 1, $this->http->to( '/v1/downloads' ), 'the same report under a newer answer is still left alone' );

		// The service reports something else for the cell: it is tried once more.
		$state['components'][0]['locales']['zh_TW']['build_no'] = 9;
		$this->state->set( $state );
		$this->sync_installer->install_available( array( 'zh_TW' ), 20 );
		$this->assertCount( 2, $this->http->to( '/v1/downloads' ) );
	}

	/**
	 * @dataProvider refusal_codes
	 */
	public function test_a_refused_update_is_not_asked_for_again_and_uses_no_budget( string $code ): void {
		$this->state->set( array( 'updates' => array( $this->update_entry() ) ) );
		$this->http->download_response = $this->refusal( $code );

		$this->assertSame( array(), $this->sync_installer->install_pending_updates() );
		$this->assertSame( array(), $this->sync_installer->install_pending_updates() );
		$this->assertCount( 1, $this->http->to( '/v1/downloads' ) );
	}

	public function test_outcomes_are_classified_in_one_place(): void {
		foreach ( array( 'verification_required', 'no_token', 'babilim_rate_limited' ) as $code ) {
			$this->assertSame( Babilim_Sync_Installer::OUTCOME_STOP, Babilim_Sync_Installer::classify_outcome( $code ), $code );
		}
		foreach ( array( 'babilim_quota_exceeded', 'babilim_license_required', 'babilim_slot_required' ) as $code ) {
			$this->assertSame( Babilim_Sync_Installer::OUTCOME_REFUSED, Babilim_Sync_Installer::classify_outcome( $code ), $code );
			$this->assertTrue( Babilim_Error_Reporter::is_excluded_code( $code ), $code );
		}
		foreach ( array( 'integrity_failed', 'babilim_write_failed', '' ) as $code ) {
			$this->assertSame( Babilim_Sync_Installer::OUTCOME_FAILED, Babilim_Sync_Installer::classify_outcome( $code ), $code );
		}
	}
}
