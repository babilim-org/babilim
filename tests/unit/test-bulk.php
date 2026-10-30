<?php
/**
 * Babilim_Bulk and the bulk POST handled by the translations page.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-kses.php';

final class Test_Bulk extends TestCase {

	/** @var Babilim_Memory_Fs */
	private $fs;

	/** @var Babilim_Manifest */
	private $manifest;

	/** @var array[] Zips handed out, in order, by the fake unzip. */
	private $zips = array();

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$this->zips     = array();
		$this->fs       = new Babilim_Memory_Fs();
		$this->manifest = new Babilim_Manifest( $this->fs );
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		Babilim::reset_for_tests();
	}

	// -- helpers ----------------------------------------------------------

	private static function bid( int $i ): string {
		return sprintf( 'bd_%012x', $i );
	}

	/**
	 * Seeds $count plugins c1..cN, each with the given locale entries.
	 *
	 * @param array<string,array> $locales locale => entry; 'build_id' is filled in per plugin.
	 */
	private function seed( int $count, array $locales, array $extra = array() ): void {
		update_option( 'babilim_consent', true );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => 'tok_test' ), false );
		$components = array();
		$plugins    = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$entries = array();
			foreach ( $locales as $locale => $entry ) {
				$entries[ $locale ] = array_merge( array( 'build_id' => self::bid( $i ) ), $entry );
			}
			$components[]                = array( 'kind' => 'plugin', 'slug' => 'c' . $i, 'locales' => $entries );
			$plugins[ "c$i/c$i.php" ] = array( 'Name' => 'Comp ' . $i, 'Version' => '1.0.0', 'TextDomain' => 'c' . $i );
		}
		$GLOBALS['babilim_test_get_plugins_return'] = $plugins;
		( new Babilim_State() )->set( array_merge( array( 'fetched_at' => '2026-10-01T00:00:00Z', 'components' => $components, 'license' => array( 'kind' => 'paid', 'status' => 'active' ) ), $extra ) );
	}

	private function package( string $slug, string $locale, string $build_id ): array {
		$mo  = Babilim_Mo_Writer::write( array( 'Save' => 'v1' ), array() );
		$po  = "# fake po\n";
		$zip = array( "$slug-$locale.mo" => $mo, "$slug-$locale.po" => $po );
		$man = array(
			'build_id'        => "$slug-1.0.0-$locale-20260101T000000-abc123",
			'entries'         => 1,
			'files'           => array(
				"$slug-$locale.mo" => array( 'sha256' => hash( 'sha256', $mo ), 'size' => strlen( $mo ) ),
				"$slug-$locale.po" => array( 'sha256' => hash( 'sha256', $po ), 'size' => strlen( $po ) ),
			),
			'locale'          => $locale,
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => $slug,
			'version'         => '1.0.0',
			'text_domain'     => $slug,
			'extra_domains'   => array(),
			'server_build_id' => $build_id,
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.1.0',
		);
		$zip['manifest.json'] = wp_json_encode( $man );
		return $zip;
	}

	/** Two responses (redirect, package) per download of components 1..$count. */
	private function download_http( int $count ): Babilim_Fake_Http {
		$responses = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$responses[] = array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array( 'location' => '/v1/packages/' . self::bid( $i ) ) );
			$responses[] = array( 'response' => array( 'code' => 200 ), 'body' => 'zip', 'headers' => array() );
			$this->zips[] = $this->package( 'c' . $i, 'de_DE', self::bid( $i ) );
		}
		return new Babilim_Fake_Http( $responses );
	}

	private function admin( Babilim_Http $http ): Babilim_Admin {
		$zips      = &$this->zips;
		$fs        = $this->fs;
		$installer = new Babilim_Installer(
			$fs,
			$this->manifest,
			static function ( string $mo_path ) use ( $fs ) {
				$entries = babilim_test_read_mo( (string) $fs->get( $mo_path ) );
				unset( $entries[''] );
				return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
			},
			static function ( string $path, string $format ) use ( $fs ) {
				$content = (string) $fs->get( $path );
				if ( 'php' === $format ) {
					$data = eval( (string) preg_replace( '/^<\?php\s*/', '', $content ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
					return is_array( $data['messages'] ?? null ) ? count( $data['messages'] ) : false;
				}
				$entries = babilim_test_read_mo( $content );
				unset( $entries[''] );
				return count( $entries );
			},
			static function () use ( &$zips ) {
				return array_shift( $zips );
			}
		);
		return new Babilim_Admin( $fs, $http, $installer, $this->manifest );
	}

	/** Runs the bulk POST handler and returns the rendered page. */
	private function post( Babilim_Admin $admin, array $fields, array $get = array() ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$fields   = array_merge( array( '_wpnonce' => wp_create_nonce( 'bulk-translations' ) ), $fields );
		$_POST    = $fields;
		$_GET     = array_merge( array( 'page' => 'babilim' ), $get );
		$_REQUEST = array_merge( $_GET, $_POST );
		$admin->on_translations_load();
		return $admin->render_current_page();
	}

	private function keys( int $count ): array {
		$keys = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$keys[] = 'plugin:c' . $i;
		}
		return $keys;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	private function pending_values( DOMXPath $x ): array {
		$out = array();
		foreach ( $x->query( '//form[.//input[@name="pending[]"]]//input[@name="pending[]"]' ) as $node ) {
			$out[] = $node instanceof DOMElement ? $node->getAttribute( 'value' ) : '';
		}
		return $out;
	}

	private const AVAILABLE = array( 'de_DE' => array( 'status' => 'babilim_available' ) );

	// -- parser, nonce, capability ----------------------------------------

	public function test_top_and_bottom_action_both_trigger_the_run(): void {
		foreach ( array( array( 'action' => 'download' ), array( 'action' => '-1', 'action2' => 'download' ) ) as $i => $actions ) {
			babilim_test_reset();
			$this->setUp();
			$this->seed( 1, self::AVAILABLE );
			$http = $this->download_http( 1 );
			$html = $this->post( $this->admin( $http ), $actions + array( 'components' => $this->keys( 1 ) ) );
			$this->assertStringContainsString( '1 succeeded', $html, "variant $i" );
			$this->assertNotNull( $this->manifest->installed( 'plugin', 'c1', 'de_DE' ) );
		}
	}

	public function test_a_wrong_nonce_dies_and_nothing_is_requested(): void {
		$this->seed( 1, self::AVAILABLE );
		$http = $this->download_http( 1 );
		$this->expectException( Babilim_Test_Wp_Die_Exception::class );
		try {
			$this->post( $this->admin( $http ), array( '_wpnonce' => 'bad', 'action' => 'download', 'components' => $this->keys( 1 ) ) );
		} finally {
			$this->assertCount( 0, $http->requests );
		}
	}

	public function test_a_user_without_the_capability_dies_and_nothing_is_requested(): void {
		$this->seed( 1, self::AVAILABLE );
		$GLOBALS['babilim_test_current_user_can'] = false;
		$http = $this->download_http( 1 );
		$this->expectException( Babilim_Test_Wp_Die_Exception::class );
		try {
			$this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => $this->keys( 1 ) ) );
		} finally {
			$this->assertCount( 0, $http->requests );
		}
	}

	public function test_a_get_or_an_unknown_action_does_nothing(): void {
		$this->seed( 1, self::AVAILABLE );
		$http  = $this->download_http( 1 );
		$admin = $this->admin( $http );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST = array( 'action' => 'download', 'components' => $this->keys( 1 ) );
		$admin->on_translations_load();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'action' => 'evil', 'components' => $this->keys( 1 ) );
		$admin->on_translations_load();
		$this->assertCount( 0, $http->requests );
	}

	// -- which cells each action touches ----------------------------------

	public function test_each_action_only_touches_cells_it_applies_to(): void {
		$this->seed(
			1,
			array(
				'de_DE' => array( 'status' => 'babilim_available' ),
				'fr_FR' => array( 'status' => 'requestable' ),
				'ja'    => array( 'status' => 'bundled' ),
			)
		);
		$items = ( new ReflectionMethod( Babilim_Admin::class, 'list_items' ) );
		$items->setAccessible( true );
		$rows = $items->invoke( $this->admin( new Babilim_Fake_Http( array() ) ), ( new Babilim_State() )->get() );
		$this->assertSame( array( array( 'plugin', 'c1', 'de_DE' ) ), Babilim_Bulk::expand( $rows, 'download', array( 'plugin:c1' ), '' ) );
		$this->assertSame( array( array( 'plugin', 'c1', 'fr_FR' ) ), Babilim_Bulk::expand( $rows, 'request', array( 'plugin:c1' ), '' ) );
		$this->assertSame( array(), Babilim_Bulk::expand( $rows, 'update', array( 'plugin:c1' ), '' ) );
		$this->assertSame( array(), Babilim_Bulk::expand( $rows, 'download', array( 'plugin:c1' ), 'fr_FR' ), 'the language filter narrows the selection' );
		$this->assertSame( array(), Babilim_Bulk::expand( $rows, 'download', array( 'plugin:other' ), '' ) );
	}

	public function test_update_runs_only_on_update_cells_and_download_never_takes_them(): void {
		$state = array(
			'components' => array(
				array(
					'kind'    => 'plugin',
					'slug'    => 'c1',
					'locales' => array(
						'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_oldoldoldold', 'update_available' => true ),
						'fr_FR' => array( 'status' => 'babilim_available', 'build_id' => 'bd_aaaaaaaaaaaa' ),
						'ja'    => array( 'status' => 'babilim_installed', 'build_id' => 'bd_bbbbbbbbbbbb' ),
					),
				),
			),
			'updates'    => array(),
		);
		$rows = Babilim_Translations_Table::build_items(
			$state,
			array( array( 'kind' => 'plugin', 'slug' => 'c1', 'name' => 'Cee', 'version' => '1.0.0', 'text_domain' => 'c1' ) ),
			static function ( string $kind, string $slug, string $locale ): ?array {
				return 'fr_FR' === $locale ? null : array( 'build_id' => 'ja' === $locale ? 'bd_bbbbbbbbbbbb' : 'bd_oldoldoldold' );
			},
			true
		);
		$this->assertSame( array( array( 'plugin', 'c1', 'de_DE' ) ), Babilim_Bulk::expand( $rows, 'update', array( 'plugin:c1' ), '' ) );
		$this->assertSame( array( array( 'plugin', 'c1', 'fr_FR' ) ), Babilim_Bulk::expand( $rows, 'download', array( 'plugin:c1' ), '' ), 'download must not take the update cell' );
	}

	// -- limits ------------------------------------------------------------

	public function test_more_than_fifty_operations_are_refused_before_any_http(): void {
		$this->seed( 51, self::AVAILABLE );
		$http = $this->download_http( 1 );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => $this->keys( 51 ) ) );
		$this->assertStringContainsString( 'Select fewer components: at most 50 operations per run (you selected 51).', $html );
		$this->assertCount( 0, $http->requests );
		$this->assertNull( $this->manifest->installed( 'plugin', 'c1', 'de_DE' ) );
	}

	public function test_six_downloads_run_two_and_leave_four_untried_with_short_timeouts(): void {
		$this->seed( 6, self::AVAILABLE );
		$http = $this->download_http( 6 );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => $this->keys( 6 ) ) );
		$this->assertStringContainsString( 'Bulk download: 2 succeeded and 4 not yet tried.', $html );
		$this->assertCount( 4, $http->requests );
		foreach ( $http->requests as $request ) {
			$this->assertLessThanOrEqual( 8, $request['args']['timeout'] );
		}
		$this->assertSame(
			array( 'plugin:c3:de_DE', 'plugin:c4:de_DE', 'plugin:c5:de_DE', 'plugin:c6:de_DE' ),
			$this->pending_values( $this->xpath( $html ) )
		);
		$this->assertStringContainsString( 'Continue next batch (4 left)', $html );
		$this->assertSame( 1, $this->xpath( $html )->query( '//form[.//input[@name="pending[]"] and @data-babilim-autosubmit="1"]' )->length, 'a clean batch with progress continues by itself' );
		$this->assertSame( 1, $this->xpath( $html )->query( '//form[@data-babilim-autosubmit]//button[@data-babilim-busy-label]' )->length, 'the button stays for the no-JS case' );
	}

	public function test_a_batch_with_failures_does_not_autosubmit_the_continue_form(): void {
		$this->seed( 6, self::AVAILABLE );
		$fail = array( 'response' => array( 'code' => 404 ), 'body' => wp_json_encode( array( 'error' => array( 'code' => 'not_found', 'message' => 'Gone.', 'hint' => '' ) ) ), 'headers' => array() );
		$html = $this->post( $this->admin( new Babilim_Fake_Http( array( $fail ) ) ), array( 'action' => 'download', 'components' => $this->keys( 6 ) ) );
		$this->assertStringContainsString( 'Continue next batch (4 left)', $html );
		$this->assertStringNotContainsString( 'data-babilim-autosubmit', $html );
	}

	public function test_a_batch_that_tried_nothing_does_not_autosubmit_the_continue_form(): void {
		$this->seed( 3, self::AVAILABLE );
		$calls = 0;
		add_filter(
			'babilim_now',
			static function () use ( &$calls ) {
				return 1 === ++$calls ? 1000 : 9000;
			}
		);
		$http = $this->download_http( 3 );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => $this->keys( 3 ) ) );
		$this->assertStringContainsString( 'Bulk download: 3 not yet tried.', $html );
		$this->assertCount( 0, $http->requests );
		$this->assertStringContainsString( 'Continue next batch (3 left)', $html );
		$this->assertStringNotContainsString( 'data-babilim-autosubmit', $html );
	}

	public function test_twelve_requests_send_five(): void {
		$this->seed( 12, array( 'de_DE' => array( 'status' => 'requestable' ) ) );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array( 'status' => 'queued' ) ), 'headers' => array() ) ) );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'request', 'components' => $this->keys( 12 ) ) );
		$this->assertCount( 5, $http->requests );
		$this->assertStringContainsString( 'Bulk request: 5 succeeded and 7 not yet tried.', $html );
	}

	public function test_the_deadline_constrains_the_next_item(): void {
		$now = 1000;
		add_filter(
			'babilim_now',
			static function () use ( &$now ) {
				return $now;
			}
		);
		$this->seed( 2, self::AVAILABLE );
		$deadline = new Babilim_Deadline( Babilim_Bulk::BUDGET_SECONDS );
		$calls    = 0;
		$execute  = static function () use ( &$now, &$calls ): Babilim_Result {
			++$calls;
			$now += 30;
			return Babilim_Result::ok( array() );
		};
		$out = Babilim_Bulk::run( array( array( 'plugin', 'c1', 'de_DE' ), array( 'plugin', 'c2', 'de_DE' ) ), 'download', $deadline, $execute, new Babilim_State() );
		$this->assertSame( 1, $calls, 'only 15 seconds are left, less than one download may need' );
		$this->assertSame( array( 'plugin:c2:de_DE' ), $out['untried'] );
	}

	// -- failures, continuing ---------------------------------------------

	public function test_failed_items_are_listed_and_never_put_back_into_untried(): void {
		$this->seed( 6, self::AVAILABLE );
		$fail = array( 'response' => array( 'code' => 404 ), 'body' => wp_json_encode( array( 'error' => array( 'code' => 'not_found', 'message' => 'Gone.', 'hint' => '' ) ) ), 'headers' => array() );
		$http = new Babilim_Fake_Http( array( $fail ) );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => $this->keys( 6 ) ) );
		$this->assertStringContainsString( 'Bulk download: 2 failed and 4 not yet tried.', $html );
		$this->assertSame(
			array( 'plugin:c3:de_DE', 'plugin:c4:de_DE', 'plugin:c5:de_DE', 'plugin:c6:de_DE' ),
			$this->pending_values( $this->xpath( $html ) )
		);
		$this->assertStringContainsString( 'Comp 1 (de_DE): ', $html );
		$this->assertStringContainsString( 'Comp 2 (de_DE): ', $html );
		babilim_test_reset();
		set_site_transient( 'available_translations', array( 'de_DE' => array( 'native_name' => 'Deutsch' ) ) );
		$this->zips = array();
		$this->seed( 2, self::AVAILABLE );
		$html = $this->post( $this->admin( new Babilim_Fake_Http( array( $fail ) ) ), array( 'action' => 'download', 'components' => $this->keys( 2 ) ) );
		$this->assertStringContainsString( 'Comp 1 (Deutsch): ', $html, 'the failure list names the language' );
		$this->assertStringNotContainsString( '(de_DE)', $html );
		$this->assertStringNotContainsString( 'plugin:c1:de_DE: ', $html, 'the failure list shows the component name, not the raw key' );
	}

	public function test_a_clean_bulk_result_joins_the_merged_notice_and_failures_come_before_it(): void {
		// All done, nothing left: the summary is a line of the one merged notice.
		$this->seed( 2, self::AVAILABLE );
		$_GET = array( 'page' => 'babilim' );
		add_settings_error( 'babilim', 'a', 'Earlier info line', 'info' );
		$html = $this->post( $this->admin( $this->download_http( 2 ) ), array( 'action' => 'download', 'components' => $this->keys( 2 ) ) );
		$this->assertSame( 1, preg_match( '#<div class="notice notice-success"><p>Earlier info line</p><p>Bulk download: 2 succeeded\.</p></div>#', $html ), 'the success summary is a line of the merged notice' );
		$this->assertSame( 1, preg_match_all( '#class="notice notice-#', $html ), 'and it is the only notice' );

		// Failures: their own warning box, before the merged notice.
		babilim_test_reset();
		Babilim::reset_for_tests();
		$this->fs       = new Babilim_Memory_Fs();
		$this->manifest = new Babilim_Manifest( $this->fs );
		$this->zips     = array();
		$this->seed( 2, self::AVAILABLE );
		add_settings_error( 'babilim', 'a', 'Earlier info line', 'info' );
		$fail = array( 'response' => array( 'code' => 404 ), 'body' => wp_json_encode( array( 'error' => array( 'code' => 'not_found', 'message' => 'Gone.', 'hint' => '' ) ) ), 'headers' => array() );
		$html = $this->post( $this->admin( new Babilim_Fake_Http( array( $fail ) ) ), array( 'action' => 'download', 'components' => $this->keys( 2 ) ) );
		$box    = strpos( $html, 'class="notice notice-warning"' );
		$merged = strpos( $html, 'Earlier info line' );
		$this->assertNotFalse( $box );
		$this->assertNotFalse( $merged );
		$this->assertLessThan( $merged, $box, 'the failure box comes before the merged notice' );
		$this->assertStringContainsString( 'Comp 1 (de_DE): ', $html );
		$this->assertStringNotContainsString( 'Bulk download:</p><p>', $html );
	}

	public function test_posting_pending_processes_the_next_batch(): void {
		$this->seed( 6, self::AVAILABLE );
		$http = $this->download_http( 6 );
		$admin = $this->admin( $http );
		$html  = $this->post( $admin, array( 'action' => 'download', 'components' => $this->keys( 6 ) ) );
		$this->assertCount( 1, $this->xpath( $html )->query( '//form[.//input[@name="pending[]"]]//input[@name="_wpnonce"]' ) );

		$next   = $this->admin( $http );
		$html2  = $this->post( $next, array( 'action' => 'download', 'components' => $this->keys( 6 ), 'pending' => $this->pending_values( $this->xpath( $html ) ) ) );
		$this->assertStringContainsString( 'Bulk download: 2 succeeded and 2 not yet tried.', $html2 );
		$this->assertSame( array( 'plugin:c5:de_DE', 'plugin:c6:de_DE' ), $this->pending_values( $this->xpath( $html2 ) ) );
	}

	public function test_an_installed_item_is_not_selected_again_for_download(): void {
		$this->seed( 3, self::AVAILABLE );
		$http = $this->download_http( 3 );
		$this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => $this->keys( 3 ) ) );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => $this->keys( 3 ) ) );
		$this->assertStringContainsString( 'Bulk download: 1 succeeded.', $html );
		$this->assertCount( 0, $this->xpath( $html )->query( '//input[@name="pending[]"]' ) );
	}

	public function test_a_pending_key_that_is_no_longer_eligible_is_ignored(): void {
		$this->seed( 1, self::AVAILABLE );
		$http = $this->download_http( 1 );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => array(), 'pending' => array( 'plugin:nope:de_DE', 'plugin:c1:xx_XX', 'junk' ) ) );
		$this->assertCount( 0, $http->requests );
		$this->assertStringContainsString( 'Bulk download: nothing to do.', $html );
	}

	public function test_the_summary_lists_only_the_non_zero_parts(): void {
		$this->seed( 2, self::AVAILABLE );
		$fail = array( 'response' => array( 'code' => 404 ), 'body' => wp_json_encode( array( 'error' => array( 'code' => 'not_found', 'message' => 'Gone.', 'hint' => '' ) ) ), 'headers' => array() );
		$html = $this->post( $this->admin( new Babilim_Fake_Http( array( $fail ) ) ), array( 'action' => 'download', 'components' => $this->keys( 2 ) ) );
		$this->assertStringContainsString( 'Bulk download: 2 failed.', $html, 'only the failed part' );
		$this->assertStringNotContainsString( '0 succeeded', $html );
		$this->assertStringNotContainsString( '0 skipped', $html );
		$this->assertStringNotContainsString( '0 not yet tried', $html );
	}

	public function test_the_summary_sentence_for_every_mix_of_parts(): void {
		$m = new ReflectionMethod( Babilim_Admin::class, 'bulk_summary' );
		$m->setAccessible( true );
		$this->assertSame( 'Bulk download: 1 succeeded, 1 skipped, 1 failed, and 1 not yet tried.', $m->invoke( null, 'download', 1, 1, 1, 1 ) );
		$this->assertSame( 'Bulk update: 2 skipped.', $m->invoke( null, 'update', 0, 2, 0, 0 ) );
		$this->assertSame( 'Bulk request: nothing to do.', $m->invoke( null, 'request', 0, 0, 0, 0 ) );
	}

	// -- newer-version request ---------------------------------------------

	public function test_a_newer_version_request_keeps_the_cell_installed(): void {
		$this->seed(
			1,
			array(
				'de_DE' => array(
					'status'            => 'babilim_installed',
					'built_for_version' => '0.9.0',
					'newer_version'     => array( 'status' => 'requestable' ),
				),
			)
		);
		$this->manifest->set( 'plugin', 'c1', 'de_DE', array( 'build_id' => self::bid( 1 ), 'component_version' => '0.9.0', 'files' => array() ) );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array( 'status' => 'queued' ) ), 'headers' => array() ) ) );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'request', 'components' => $this->keys( 1 ) ) );
		$this->assertStringContainsString( '1 succeeded', $html );
		$cell = ( new Babilim_State() )->status( 'plugin', 'c1', 'de_DE' );
		$this->assertSame( 'babilim_installed', $cell['status'] );
		$this->assertSame( '0.9.0', $cell['built_for_version'] );
		$this->assertSame( 'queued', $cell['newer_version']['status'] );
	}

	// -- same-response rendering -------------------------------------------

	public function test_the_result_is_rendered_by_the_same_response_without_a_redirect(): void {
		$this->seed( 3, self::AVAILABLE );
		$http   = $this->download_http( 3 );
		$before = count( $GLOBALS['babilim_test_redirects'] ?? array() );
		$html   = $this->post( $this->admin( $http ), array( 'action' => 'download', 'components' => $this->keys( 3 ) ) );
		$this->assertCount( $before, $GLOBALS['babilim_test_redirects'] ?? array() );
		$x    = $this->xpath( $html );
		$form = $x->query( '//form[.//input[@name="pending[]"]]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $form );
		$this->assertSame( 'post', strtolower( $form instanceof DOMElement ? $form->getAttribute( 'method' ) : '' ) );
		$this->assertCount( 3, $x->query( '//form[.//input[@name="pending[]"]]//input[@name="components[]"]' ) );
		$this->assertSame( 'Continuing…', $x->query( '//form[.//input[@name="pending[]"]]//button' )->item( 0 )->attributes->getNamedItem( 'data-babilim-busy-label' )->nodeValue );
		$this->assertSame( 'download', $x->query( '//form[.//input[@name="pending[]"]]//input[@name="action"]' )->item( 0 )->attributes->getNamedItem( 'value' )->nodeValue );
	}

	/** Seeds one installed plugin whose service-side update is build 1. */
	private function seed_update(): void {
		$this->seed(
			1,
			array( 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_0000000000aa', 'update_available' => true ) ),
			array( 'updates' => array( array( 'kind' => 'plugin', 'slug' => 'c1', 'locale' => 'de_DE', 'build_id' => self::bid( 1 ) ) ) )
		);
		$this->manifest->set( 'plugin', 'c1', 'de_DE', array( 'build_id' => 'bd_0000000000aa', 'component_version' => '1.0.0', 'files' => array() ) );
	}

	public function test_bulk_update_dispatches_a_download_of_the_update_build(): void {
		$this->seed_update();
		$http = $this->download_http( 1 );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'update', 'components' => $this->keys( 1 ) ) );
		$this->assertStringContainsString( 'Bulk update: 1 succeeded', $html );
		$this->assertCount( 2, $http->requests );
		$this->assertSame( self::bid( 1 ), $this->manifest->installed( 'plugin', 'c1', 'de_DE' )['build_id'] );
	}

	/**
	 * Seeds the state right after an update succeeded: the stored status
	 * still names the old build (build_no $status_no), updates[] is empty,
	 * and the manifest holds a different installed build (build_no
	 * $manifest_no) -- or none when $manifest_no is null.
	 */
	private function seed_stale( int $status_no, ?int $manifest_no ): void {
		$this->seed(
			1,
			array( 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => self::bid( 1 ), 'build_no' => $status_no ) ),
			array( 'updates' => array() )
		);
		if ( null !== $manifest_no ) {
			$this->manifest->set( 'plugin', 'c1', 'de_DE', array( 'build_id' => self::bid( 2 ), 'build_no' => $manifest_no, 'component_version' => '1.0.0', 'files' => array() ) );
		}
	}

	public function test_a_stale_download_does_not_reinstall_an_older_build_than_the_installed_one(): void {
		$this->seed_stale( 1, 2 );
		$http   = new Babilim_Fake_Http( array() );
		$result = $this->admin( $http )->do_download( 'plugin', 'c1', 'de_DE' );
		$this->assertFalse( $result->is_error() );
		$this->assertTrue( (bool) $result->get( 'already_installed' ) );
		$this->assertCount( 0, $http->requests );
		$this->assertSame( self::bid( 2 ), $this->manifest->installed( 'plugin', 'c1', 'de_DE' )['build_id'] );
	}

	public function test_a_download_still_upgrades_when_the_installed_build_is_older_than_the_status_build(): void {
		$this->seed_stale( 2, 1 );
		$http   = $this->download_http( 1 );
		$result = $this->admin( $http )->do_download( 'plugin', 'c1', 'de_DE' );
		$this->assertFalse( $result->is_error() );
		$this->assertCount( 2, $http->requests );
		$this->assertSame( self::bid( 1 ), $this->manifest->installed( 'plugin', 'c1', 'de_DE' )['build_id'] );
	}

	public function test_a_download_still_runs_when_the_build_numbers_are_equal(): void {
		$this->seed_stale( 3, 3 );
		$http = $this->download_http( 1 );
		$this->admin( $http )->do_download( 'plugin', 'c1', 'de_DE' );
		$this->assertCount( 2, $http->requests );
	}

	public function test_a_download_without_a_manifest_record_downloads_the_status_build(): void {
		$this->seed_stale( 1, null );
		$http   = $this->download_http( 1 );
		$result = $this->admin( $http )->do_download( 'plugin', 'c1', 'de_DE' );
		$this->assertFalse( $result->is_error() );
		$this->assertFalse( (bool) $result->get( 'already_installed' ) );
		$this->assertCount( 2, $http->requests );
	}

	public function test_a_download_of_the_same_build_still_goes_to_the_installer(): void {
		$this->seed_stale( 1, 5 );
		$this->manifest->set( 'plugin', 'c1', 'de_DE', array( 'build_id' => self::bid( 1 ), 'build_no' => 5, 'component_version' => '1.0.0', 'files' => array() ) );
		$http = $this->download_http( 1 );
		$this->admin( $http )->do_download( 'plugin', 'c1', 'de_DE' );
		$this->assertCount( 2, $http->requests );
	}

	public function test_bulk_over_a_stale_cell_counts_it_as_skipped_and_sends_nothing(): void {
		$this->seed_stale( 1, 2 );
		$http   = new Babilim_Fake_Http( array() );
		$admin  = $this->admin( $http );
		$ops    = array( array( 'plugin', 'c1', 'de_DE' ) );
		$result = Babilim_Bulk::run(
			$ops,
			'download',
			new Babilim_Deadline( 45 ),
			static function ( string $kind, string $slug, string $locale, int $timeout ) use ( $admin ): Babilim_Result {
				return $admin->do_download( $kind, $slug, $locale, $timeout );
			},
			new Babilim_State()
		);
		$this->assertSame( 0, $result['ok'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertCount( 0, $http->requests );
	}

	public function test_after_a_bulk_update_the_list_no_longer_offers_an_update(): void {
		$this->seed_update();
		$html = $this->post( $this->admin( $this->download_http( 1 ) ), array( 'action' => 'update', 'components' => $this->keys( 1 ) ) );
		$this->assertStringContainsString( 'Bulk update: 1 succeeded', $html );
		$this->assertStringNotContainsString( 'Update available', $html );
		$this->assertDoesNotMatchRegularExpression( '/Updates\s*<span class="count">\(1\)/', $html );
	}

	// -- request answers, filter wiring, update after rollback --------------

	private function request_http( array $body, int $code = 200 ): Babilim_Fake_Http {
		return new Babilim_Fake_Http( array( array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $body ), 'headers' => array() ) ) );
	}

	public function test_a_request_answered_available_makes_the_cell_downloadable(): void {
		$this->seed( 1, array( 'de_DE' => array( 'status' => 'requestable' ) ) );
		$http = $this->request_http( array( 'status' => 'available', 'build_id' => self::bid( 7 ) ) );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'request', 'components' => $this->keys( 1 ) ) );
		$this->assertStringContainsString( '1 succeeded', $html );
		$cell = ( new Babilim_State() )->status( 'plugin', 'c1', 'de_DE' );
		$this->assertSame( 'babilim_available', $cell['status'] );
		$this->assertSame( self::bid( 7 ), $cell['build_id'] );
	}

	public function test_an_available_answer_leaves_an_installed_cell_with_a_newer_version_installed(): void {
		$this->seed( 1, array( 'de_DE' => array( 'status' => 'babilim_installed', 'built_for_version' => '0.9.0', 'newer_version' => array( 'status' => 'requestable' ) ) ) );
		$this->manifest->set( 'plugin', 'c1', 'de_DE', array( 'build_id' => self::bid( 1 ), 'component_version' => '0.9.0', 'files' => array() ) );
		$http = $this->request_http( array( 'status' => 'available', 'build_id' => self::bid( 7 ) ) );
		$html = $this->post( $this->admin( $http ), array( 'action' => 'request', 'components' => $this->keys( 1 ) ) );
		$this->assertStringContainsString( '1 succeeded', $html );
		$cell = ( new Babilim_State() )->status( 'plugin', 'c1', 'de_DE' );
		$this->assertSame( 'babilim_installed', $cell['status'] );
		$this->assertSame( self::bid( 1 ), $cell['build_id'] );
		$this->assertSame( 'requestable', $cell['newer_version']['status'], 'available is not a newer_version status' );
	}

	public function test_the_language_filter_of_the_list_limits_a_bulk_run_to_that_language(): void {
		$this->seed( 1, array( 'de_DE' => array( 'status' => 'requestable' ), 'fr_FR' => array( 'status' => 'requestable' ) ) );
		$http = $this->request_http( array( 'status' => 'queued' ), 202 );
		$this->post( $this->admin( $http ), array( 'action' => 'request', 'components' => $this->keys( 1 ) ), array( 'babilim_locale' => 'fr_FR' ) );
		$this->assertCount( 1, $http->requests );
		$this->assertSame( 'fr_FR', json_decode( $http->requests[0]['args']['body'], true )['locale'] );
		$this->assertSame( 'requestable', ( new Babilim_State() )->status( 'plugin', 'c1', 'de_DE' )['status'] );
	}

	public function test_pressing_update_after_an_update_and_a_local_rollback_installs_the_update_build(): void {
		$this->seed_update();
		$this->admin( $this->download_http( 1 ) )->do_download( 'plugin', 'c1', 'de_DE' );
		$this->assertSame( self::bid( 1 ), $this->manifest->installed( 'plugin', 'c1', 'de_DE' )['build_id'] );

		// A local rollback, before the next sync: the manifest is back on the old build.
		$this->manifest->set( 'plugin', 'c1', 'de_DE', array( 'build_id' => 'bd_0000000000aa', 'component_version' => '1.0.0', 'files' => array() ) );
		$_GET = array( 'page' => 'babilim' );
		$http = $this->download_http( 1 );
		$this->assertStringContainsString( 'Update available', $this->admin( $http )->render_current_page() );

		$result = $this->admin( $http )->do_download( 'plugin', 'c1', 'de_DE' );
		$this->assertTrue( $result->is_ok() );
		$this->assertNull( $result->get( 'already_installed' ) );
		$this->assertSame( self::bid( 1 ), json_decode( $http->requests[0]['args']['body'], true )['build_id'], 'the build the cell advertises is the one requested' );
		$this->assertSame( self::bid( 1 ), $this->manifest->installed( 'plugin', 'c1', 'de_DE' )['build_id'] );
	}

	public function test_a_bulk_download_clears_the_auto_install_skip_for_what_it_installed(): void {
		$this->seed( 1, self::AVAILABLE );
		Babilim_Sync_Installer::remember_skip( 'plugin', 'c1', 'de_DE' );
		Babilim_Sync_Installer::remember_skip( 'plugin', 'other', 'de_DE' );

		$this->post( $this->admin( $this->download_http( 1 ) ), array( 'action' => 'download', 'components' => $this->keys( 1 ) ) );

		$this->assertSame( array( 'plugin:other:de_DE' => true ), get_option( Babilim_Sync_Installer::OPTION_SKIP ) );
	}

	public function test_a_failed_download_keeps_the_auto_install_skip(): void {
		$this->seed( 1, self::AVAILABLE );
		Babilim_Sync_Installer::remember_skip( 'plugin', 'c1', 'de_DE' );

		$this->admin( new Babilim_Fake_Http( array( new WP_Error( 'x', 'down' ) ) ) )->do_download( 'plugin', 'c1', 'de_DE' );

		$this->assertArrayHasKey( 'plugin:c1:de_DE', (array) get_option( Babilim_Sync_Installer::OPTION_SKIP ) );
	}
}
