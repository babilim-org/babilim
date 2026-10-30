<?php
/**
 * Tests for the inventory request limits: at most 500 components, at
 * most 256 unique locales, at most 20000 components x locales cells and
 * a 2 MB body. Each request the service receives is independent (a
 * component absent from one request is not removed), so the plugin
 * splits the component list into batches that each fit; locales cannot
 * be split (each request replaces the site's locale list), so they are
 * capped at 256 and the overflow is reported.
 *
 * The HTTP double below applies the service's own rules in the service's
 * own order and answers with the same status codes (413 payload_too_large,
 * 422 validation_error) -- no laxer than the service.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/fake-wp-content/plugins' );
}

/**
 * The service's inventory endpoint as far as its request limits go.
 */
final class Babilim_Limit_Enforcing_Http implements Babilim_Http {

	public const MAX_BODY_BYTES = 2 * 1024 * 1024;

	/** @var array[] Decoded bodies of every request received. */
	public $bodies = array();

	/** @var int[] Byte length of every request body received. */
	public $sizes = array();

	/** @var int[] Status returned for every request. */
	public $statuses = array();

	/** @var int|null 1-based request number that answers 500 instead. */
	public $fail_on_request = null;

	/**
	 * @param string $method HTTP method.
	 * @param string $url    Request URL.
	 * @param array  $args   Request arguments.
	 * @return array
	 */
	public function request( string $method, string $url, array $args ) {
		$body = (string) ( $args['body'] ?? '' );
		$n    = count( $this->sizes ) + 1;

		$this->sizes[] = strlen( $body );
		$status        = $this->status_for( $body, $n );
		$this->statuses[] = $status;
		$decoded          = json_decode( $body, true );
		$this->bodies[]   = is_array( $decoded ) ? $decoded : array();

		if ( 413 === $status ) {
			return $this->reply( 413, array( 'error' => array( 'code' => 'payload_too_large', 'message' => 'too large' ) ) );
		}
		if ( 422 === $status ) {
			return $this->reply( 422, array( 'error' => array( 'code' => 'validation_error', 'message' => 'invalid' ) ) );
		}
		if ( 500 === $status ) {
			return $this->reply( 500, array( 'error' => array( 'code' => 'internal_error', 'message' => 'boom' ) ) );
		}

		$components = array();
		foreach ( $decoded['components'] as $component ) {
			$locales = array();
			foreach ( $decoded['locales'] as $locale ) {
				$locales[ $locale ] = array( 'status' => 'requestable' );
			}
			$components[] = array( 'kind' => $component['kind'], 'slug' => $component['slug'], 'locales' => $locales );
		}
		return $this->reply(
			200,
			array(
				'components' => $components,
				'updates'    => array(),
				'license'    => array( 'kind' => 'free', 'status' => 'active' ),
				'quota'      => array(),
				'features'   => array(),
				'contract'   => array( 'version' => '1.3.0' ),
			)
		);
	}

	/**
	 * The status the service gives this body: size first (before any
	 * parsing), then the schema limits, then the components x locales
	 * product -- the same order the service checks them in.
	 */
	private function status_for( string $body, int $n ): int {
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return 413;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['components'], $data['locales'] ) || ! is_array( $data['components'] ) || ! is_array( $data['locales'] ) ) {
			return 422;
		}
		$components = $data['components'];
		$locales    = $data['locales'];
		if ( count( $components ) > 500 || count( $locales ) < 1 || count( $locales ) > 256 || count( array_unique( $locales ) ) !== count( $locales ) ) {
			return 422;
		}
		foreach ( $components as $component ) {
			foreach ( array( 'kind', 'slug', 'version', 'text_domain', 'extra_domains', 'is_wporg', 'bundled_locales', 'installed' ) as $key ) {
				if ( ! is_array( $component ) || ! array_key_exists( $key, $component ) ) {
					return 422;
				}
			}
			if ( count( $component['extra_domains'] ) > 512 || count( $component['bundled_locales'] ) > 512 ) {
				return 422;
			}
		}
		if ( count( $components ) * count( $locales ) > 20000 ) {
			return 422;
		}
		if ( $this->fail_on_request === $n ) {
			return 500;
		}
		return 200;
	}

	private function reply( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}
}

final class Test_Inventory_Limits extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
		update_option( 'babilim_consent', true );
		update_option( 'babilim_install_sk', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
		update_option( 'babilim_site_id', 'st_limits' );
		update_option( 'babilim_site_token', 'tok_limits' );
	}

	/**
	 * @param int      $plugins       Number of installed plugins.
	 * @param string[] $manual_locales Locales added by hand.
	 * @param int      $extra_domains Extra text domains per plugin (bulks up the body).
	 */
	private function site( int $plugins, array $manual_locales, Babilim_Limit_Enforcing_Http $http, int $extra_domains = 0 ): Babilim_Site {
		$list = array();
		for ( $i = 1; $i <= $plugins; $i++ ) {
			$list[ "plugin-$i/plugin-$i.php" ] = array( 'Name' => "Plugin $i", 'Version' => '1.0.0', 'TextDomain' => "plugin-$i" );
		}
		$dirs      = array();
		if ( $extra_domains > 0 ) {
			// A directory listing full of files under other text domains
			// is how a plugin ends up with a large extra_domains list.
			$files = array();
			for ( $d = 1; $d <= $extra_domains; $d++ ) {
				$files[] = 'other-domain-' . str_repeat( 'x', 120 ) . "-$d-fr_FR.mo";
			}
			$dirs = $files;
		}
		$inventory = new Babilim_Inventory(
			static function () use ( $list ) { return $list; },
			static function () { return array(); },
			static function () { return false; },
			static function () { return array(); },
			static function () use ( $dirs ) { return $dirs; }
		);
		$inventory->set_manual_locales( $manual_locales );
		return new Babilim_Site( $http, 'https://api.babilim.org', new Babilim_State(), $inventory, new Babilim_Manifest( new Babilim_Memory_Fs() ) );
	}

	/** @return string[] */
	private static function locales( int $count ): array {
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = 'x' . chr( 97 + intdiv( $i, 26 ) % 26 ) . chr( 97 + $i % 26 ) . '_' . chr( 65 + intdiv( $i, 676 ) ) . 'Z';
		}
		return array_values( array_unique( $out ) );
	}

	// -- the double is no laxer than the service --------------------------

	private function raw_post( Babilim_Limit_Enforcing_Http $http, array $components, array $locales ): int {
		$http->request( 'POST', 'https://api.babilim.org/v1/sites/st/inventory', array( 'body' => (string) wp_json_encode( array( 'components' => $components, 'locales' => $locales ) ) ) );
		return $http->statuses[ count( $http->statuses ) - 1 ];
	}

	private static function component( int $i, int $extra = 0 ): array {
		return array(
			'kind'            => 'plugin',
			'slug'            => "p$i",
			'version'         => '1',
			'text_domain'     => "p$i",
			'extra_domains'   => array_fill( 0, $extra, str_repeat( 'd', 190 ) ),
			'is_wporg'        => false,
			'bundled_locales' => array(),
			'installed'       => new stdClass(),
		);
	}

	public function test_the_double_rejects_every_over_limit_shape_a_naive_single_request_would_send(): void {
		$http = new Babilim_Limit_Enforcing_Http();
		$cs   = static function ( int $n ) {
			return array_map( array( self::class, 'component' ), range( 1, $n ) );
		};

		$this->assertSame( 422, $this->raw_post( $http, $cs( 600 ), array( 'de_DE' ) ), '600 components' );
		$this->assertSame( 422, $this->raw_post( $http, $cs( 1 ), self::locales( 300 ) ), '300 locales' );
		$this->assertSame( 422, $this->raw_post( $http, $cs( 300 ), self::locales( 70 ) ), '300 x 70 = 21000 cells' );
		$big = array_map(
			static function ( int $i ) {
				return self::component( $i, 512 );
			},
			range( 1, 30 )
		);
		$this->assertSame( 413, $this->raw_post( $http, $big, array( 'de_DE' ) ), 'body over 2 MB' );
		$this->assertSame( 422, $this->raw_post( $http, $cs( 1 ), array( 'de_DE', 'de_DE' ) ), 'duplicate locale' );
		$this->assertSame( 200, $this->raw_post( $http, $cs( 500 ), array( 'de_DE' ) ), 'exactly at the component cap is fine' );
		$this->assertSame( 200, $this->raw_post( $http, $cs( 285 ), self::locales( 70 ) ), '285 x 70 = 19950 cells is fine' );
	}

	// -- the three over-limit shapes, through the real sync ---------------

	public function test_600_components_are_sent_in_batches_of_at_most_500_and_all_are_stored(): void {
		$http = new Babilim_Limit_Enforcing_Http();
		$this->site( 600, array(), $http )->sync();

		$this->assertGreaterThanOrEqual( 2, count( $http->bodies ) );
		$this->assertSame( array_fill( 0, count( $http->statuses ), 200 ), $http->statuses, 'no request may be refused' );
		$slugs = array();
		foreach ( $http->bodies as $body ) {
			$this->assertLessThanOrEqual( 500, count( $body['components'] ) );
			foreach ( $body['components'] as $component ) {
				$slugs[] = $component['slug'];
			}
		}
		$this->assertCount( 600, array_unique( $slugs ), 'every component is sent exactly once' );
		$state = ( new Babilim_State() )->get();
		$this->assertCount( 600, $state['components'], 'the merged answer covers every component' );
		$this->assertSame( 0, (int) ( $state['omitted']['components'] ?? 0 ) );
	}

	public function test_300_locales_are_capped_at_256_keeping_the_owners_own_choices_and_the_overflow_is_reported(): void {
		$http    = new Babilim_Limit_Enforcing_Http();
		// 299 locales detected on the site, plus one added by hand that
		// sorts last -- the first one a plain cut would drop.
		$GLOBALS['babilim_test_available_languages'] = self::locales( 299 );
		$this->site( 3, array( 'zz_ZZ' ), $http )->sync();

		$this->assertSame( array( 200 ), array_values( array_unique( $http->statuses ) ) );
		$sent = $http->bodies[0]['locales'];
		$this->assertCount( 256, $sent );
		$this->assertSame( $sent, array_values( array_unique( $sent ) ) );
		$this->assertContains( 'zz_ZZ', $sent, 'a locale the owner added by hand survives the cap' );
		$this->assertContains( 'de_DE', $sent, 'so does the site locale' );
		$state = ( new Babilim_State() )->get();
		$this->assertSame( 301 - 256, (int) $state['omitted']['locales'], '299 detected + de_DE + zz_ZZ = 301 unique locales, 45 over the cap' );
		foreach ( $http->bodies as $body ) {
			$this->assertSame( $sent, $body['locales'], 'every batch carries the same locale list, since each request replaces it' );
		}
	}

	public function test_300_components_by_70_locales_are_split_to_stay_under_20000_cells(): void {
		$http = new Babilim_Limit_Enforcing_Http();
		$this->site( 300, array_slice( self::locales( 80 ), 0, 69 ), $http )->sync();

		$this->assertSame( array( 200 ), array_values( array_unique( $http->statuses ) ) );
		$this->assertGreaterThanOrEqual( 2, count( $http->bodies ) );
		$total = 0;
		foreach ( $http->bodies as $body ) {
			$this->assertSame( 70, count( $body['locales'] ), 'de_DE + 69 manual' );
			$this->assertLessThanOrEqual( 20000, count( $body['components'] ) * count( $body['locales'] ) );
			$total += count( $body['components'] );
		}
		$this->assertSame( 300, $total );
		$this->assertCount( 300, ( new Babilim_State() )->get()['components'] );
	}

	public function test_a_body_over_2_mb_is_split_by_size(): void {
		$http = new Babilim_Limit_Enforcing_Http();
		$this->site( 40, array(), $http, 500 )->sync();

		$this->assertSame( array( 200 ), array_values( array_unique( $http->statuses ) ), 'no 413' );
		$this->assertGreaterThanOrEqual( 2, count( $http->bodies ) );
		foreach ( $http->sizes as $size ) {
			$this->assertLessThanOrEqual( Babilim_Limit_Enforcing_Http::MAX_BODY_BYTES, $size );
		}
		$this->assertGreaterThan( Babilim_Limit_Enforcing_Http::MAX_BODY_BYTES, array_sum( $http->sizes ), 'the whole inventory really is larger than one request may be' );
		$this->assertCount( 40, ( new Babilim_State() )->get()['components'] );
	}

	// -- batches share one snapshot's worth of state ------------------------

	public function test_client_events_ride_only_on_the_first_batch_and_are_drained_after_all_succeed(): void {
		$state = new Babilim_State();
		$state->record_client_event( 'integrity_failed', 'plugin', 'plugin-1', 'de_DE' );
		$http = new Babilim_Limit_Enforcing_Http();
		$this->site( 600, array(), $http )->sync();

		$this->assertGreaterThanOrEqual( 2, count( $http->bodies ) );
		$this->assertCount( 1, $http->bodies[0]['client_events'] ?? array() );
		for ( $i = 1; $i < count( $http->bodies ); $i++ ) {
			$this->assertArrayNotHasKey( 'client_events', $http->bodies[ $i ] );
		}
		$this->assertSame( array(), $state->pending_client_events(), 'drained once every batch went through' );
	}

	public function test_a_failed_later_batch_keeps_the_previous_state_and_the_pending_events(): void {
		$state = new Babilim_State();
		$state->set( array( 'components' => array( array( 'kind' => 'plugin', 'slug' => 'old', 'locales' => array() ) ), 'updates' => array(), 'marker' => 'previous' ) );
		$state->record_client_event( 'integrity_failed', 'plugin', 'plugin-1', 'de_DE' );
		$http                  = new Babilim_Limit_Enforcing_Http();
		$http->fail_on_request = 2;
		$this->site( 600, array(), $http )->sync();

		$this->assertSame( 'previous', ( new Babilim_State() )->get()['marker'], 'a half-answered snapshot is never stored' );
		$this->assertCount( 1, $state->pending_client_events(), 'events are kept for the next sync' );
	}

	public function test_a_normal_site_still_makes_exactly_one_request_with_no_omission(): void {
		$http = new Babilim_Limit_Enforcing_Http();
		$this->site( 5, array(), $http )->sync();

		$this->assertCount( 1, $http->bodies );
		$this->assertCount( 5, $http->bodies[0]['components'] );
		$this->assertSame( array( 'de_DE' ), $http->bodies[0]['locales'] );
		$this->assertSame( array( 'components' => 0, 'locales' => 0 ), ( new Babilim_State() )->get()['omitted'] );
	}

	// -- the planner on its own -------------------------------------------

	public function test_the_planner_never_returns_a_batch_over_a_limit_and_never_loses_a_component(): void {
		$components = array_map( array( self::class, 'component' ), range( 1, 777 ) );
		foreach ( array( 1, 10, 40, 100, 256 ) as $locale_count ) {
			$plan = Babilim_Inventory_Planner::plan( $components, self::locales( $locale_count ), array() );
			$seen = 0;
			foreach ( $plan['batches'] as $batch ) {
				$this->assertNotEmpty( $batch );
				$this->assertLessThanOrEqual( 500, count( $batch ) );
				$this->assertLessThanOrEqual( 20000, count( $batch ) * count( $plan['locales'] ), "locales=$locale_count" );
				$seen += count( $batch );
			}
			$this->assertSame( 777, $seen );
			$this->assertSame( 0, $plan['omitted_components'] );
		}
	}

	public function test_a_single_component_too_big_for_any_request_is_omitted_and_counted(): void {
		$monster = self::component( 1, 512 );
		$plan = Babilim_Inventory_Planner::plan( array( $monster ), array( 'de_DE' ), array(), 1000 );
		$this->assertSame( array(), $plan['batches'], 'nothing fits a 1000-byte budget' );
		$this->assertSame( 1, $plan['omitted_components'] );
	}

	public function test_no_locales_means_nothing_can_be_sent(): void {
		$plan = Babilim_Inventory_Planner::plan( array( self::component( 1 ) ), array(), array() );
		$this->assertSame( array(), $plan['batches'] );
	}

	// -- the owner is told when something was left out -------------------

	public function test_the_overview_says_how_many_items_were_left_out_and_is_silent_otherwise(): void {
		$admin = new Babilim_Admin( new Babilim_Memory_Fs(), new Babilim_Limit_Enforcing_Http() );
		$_GET  = array();
		$this->assertStringNotContainsString( 'left out', $admin->render_current_page() );

		( new Babilim_State() )->set( array( 'fetched_at' => gmdate( 'c' ), 'components' => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locales' => array() ) ), 'omitted' => array( 'components' => 0, 'locales' => 45 ) ) );
		$html = $admin->render_current_page();
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( '0 components and 45 locales', $html );

		( new Babilim_State() )->set( array( 'fetched_at' => gmdate( 'c' ), 'components' => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locales' => array() ) ), 'omitted' => array( 'components' => 0, 'locales' => 0 ) ) );
		$this->assertStringNotContainsString( 'left out', $admin->render_current_page() );
	}
}
