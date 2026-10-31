<?php
/**
 * The one-time upgrade flow in the admin: the Upgrade buttons and the
 * checkout form, the admin-post handler that opens the checkout page,
 * and the admin-ajax poll that notices the site became licensed.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-kses.php';

final class Test_Admin_Checkout extends TestCase {

	private const TOKEN        = 'tok_SECRET_checkout_token_value';
	private const CHECKOUT_URL = 'https://pay.babilim.org/checkout?s=tCk3Qx7mLp2VdR9aZ0nW5sYb8cHf1JgE4uKoT6iAvBe';

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$GLOBALS['babilim_test_site_locale'] = 'de_DE';
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		Babilim::reset_for_tests();
	}

	private function admin( ?Babilim_Http $http = null ): Babilim_Admin {
		return new Babilim_Admin( new Babilim_Memory_Fs(), $http ?? new Babilim_Fake_Http( array() ) );
	}

	private function json_response( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	/**
	 * A consenting, registered, verified site.
	 *
	 * @param string     $kind  License kind in the stored state.
	 * @param array|null $price Stored price, or null for none.
	 */
	private function seed( string $kind = 'free', ?array $price = array( 'amount' => '8.99', 'currency' => 'USD' ), string $cell = 'license_required' ): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'woocommerce/woocommerce.php' => array( 'Name' => 'Woo', 'Version' => '1.0.0', 'TextDomain' => 'woocommerce' ) );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => self::TOKEN ), false );
		$state = array(
			'fetched_at' => gmdate( 'c' ),
			'license'    => array( 'kind' => $kind, 'status' => 'active' ),
			'components' => array(
				array(
					'kind'    => 'plugin',
					'slug'    => 'woocommerce',
					'name'    => 'Woo',
					'version' => '1.0.0',
					'locales' => array( 'de_DE' => array( 'status' => $cell ) ),
				),
			),
		);
		if ( null !== $price ) {
			$state['price'] = $price;
		}
		( new Babilim_State() )->set( $state );
	}

	private function page( string $page, ?Babilim_Http $http = null ): string {
		$_GET = array( 'page' => $page );
		return wp_kses( $this->admin( $http )->render_current_page(), Babilim_Admin::allowed_admin_html() );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	private function el( DOMXPath $x, string $query ): DOMElement {
		$node = $x->query( $query )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $node, $query );
		if ( ! $node instanceof DOMElement ) {
			throw new RuntimeException( 'No element for ' . $query );
		}
		return $node;
	}

	private function form_depth( string $html ): int {
		preg_match_all( '#<(/?)form\b#i', $html, $m );
		$depth = 0;
		$max   = 0;
		foreach ( $m[1] as $closing ) {
			$depth += '' === $closing ? 1 : -1;
			$max    = max( $max, $depth );
		}
		return $max;
	}

	private function post( string $action ): void {
		$fields   = array( 'action' => $action, '_wpnonce' => wp_create_nonce( $action ) );
		$_POST    = $fields;
		$_REQUEST = $fields;
	}

	private function ajax( bool $with_nonce = true ): void {
		$_REQUEST = $with_nonce ? array( '_ajax_nonce' => wp_create_nonce( 'babilim_license_status' ) ) : array();
	}

	private function ajax_result( Babilim_Admin $admin ): array {
		try {
			$admin->ajax_babilim_license_status();
		} catch ( Babilim_Test_Wp_Send_Json_Exception $e ) {
			return (array) $GLOBALS['babilim_test_json'];
		}
		$this->fail( 'the ajax handler sent nothing' );
		return array();
	}

	public function test_free_verified_site_with_a_price_shows_the_upgrade_button_and_price_on_settings(): void {
		$this->seed();
		$html = $this->page( 'babilim-settings' );
		$x    = $this->xpath( $html );

		$this->assertSame( 1, $x->query( '//button[normalize-space()="Upgrade this site"][@form="babilim-checkout-form"]' )->length );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-checkout-form"]' )->length );
		$form = $this->el( $x, '//form[@id="babilim-checkout-form"]' );
		$this->assertSame( '_blank', $form->getAttribute( 'target' ) );
		$this->assertSame( 'post', $form->getAttribute( 'method' ) );
		$this->assertStringContainsString( 'admin-post.php', $form->getAttribute( 'action' ) );
		$this->assertStringContainsString( 'action=babilim_license_status', $form->getAttribute( 'data-babilim-poll-url' ) );
		$this->assertStringContainsString( '_ajax_nonce=', $form->getAttribute( 'data-babilim-poll-url' ) );
		$this->assertSame( 1, $x->query( './/input[@name="_wpnonce"]', $form )->length );
		$this->assertSame( 1, $x->query( './/input[@name="action"][@value="babilim_checkout"]', $form )->length );
		$this->assertSame( 0, $x->query( './/button', $form )->length );
		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringContainsString( 'USD 8.99', $this->el( $x, '//span[@class="description"][contains(.,"One-time purchase")]' )->textContent );
	}

	public function test_no_price_in_state_means_no_upgrade_button_or_form(): void {
		$this->seed( 'free', null );
		foreach ( array( 'babilim-settings', 'babilim' ) as $slug ) {
			$x = $this->xpath( $this->page( $slug ) );
			$this->assertSame( 0, $x->query( '//button[normalize-space()="Upgrade this site"]' )->length, $slug );
			$this->assertSame( 0, $x->query( '//form[@id="babilim-checkout-form"]' )->length, $slug );
		}
	}

	public function test_a_price_without_amount_and_currency_offers_nothing(): void {
		foreach ( array( array(), array( 'amount' => '8.99' ), array( 'currency' => 'USD' ) ) as $price ) {
			$this->seed( 'free', $price );
			$x = $this->xpath( $this->page( 'babilim-settings' ) );
			$this->assertSame( 0, $x->query( '//form[@id="babilim-checkout-form"]' )->length );
			$this->assertSame( 0, $x->query( '//button[normalize-space()="Upgrade this site"]' )->length );
		}
	}

	public function test_paid_site_shows_licensed_and_no_upgrade_button(): void {
		$this->seed( 'paid' );
		$x = $this->xpath( $this->page( 'babilim-settings' ) );
		$this->assertSame( 1, $x->query( '//li[contains(.,"License: Licensed")]' )->length );
		$this->assertSame( 0, $x->query( '//button[normalize-space()="Upgrade this site"]' )->length );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-checkout-form"]' )->length );
	}

	public function test_partner_and_free_license_kinds_have_their_own_labels(): void {
		$this->seed( 'partner' );
		$this->assertSame( 1, $this->xpath( $this->page( 'babilim-settings' ) )->query( '//li[contains(.,"License: Partner")]' )->length );
		$this->seed( 'free' );
		$this->assertSame( 1, $this->xpath( $this->page( 'babilim-settings' ) )->query( '//li[contains(.,"License: Free")]' )->length );
	}

	public function test_license_required_and_quota_cells_get_an_upgrade_button_on_the_list(): void {
		foreach ( array( 'license_required', 'quota_exceeded' ) as $cell ) {
			$this->seed( 'free', array( 'amount' => '8.99', 'currency' => 'USD' ), $cell );
			$x = $this->xpath( $this->page( 'babilim' ) );
			$this->assertSame( 1, $x->query( '//button[@form="babilim-checkout-form"][contains(@class,"button-link")][normalize-space()="Upgrade this site"]' )->length, $cell );
			$this->assertSame( 1, $x->query( '//form[@id="babilim-checkout-form"]' )->length, $cell );
		}
	}

	public function test_forms_are_never_nested_on_the_settings_page_with_the_checkout_form(): void {
		$this->seed();
		$this->assertSame( 1, $this->form_depth( $this->page( 'babilim-settings' ) ) );
		$this->assertSame( 1, $this->form_depth( $this->page( 'babilim' ) ) );
	}

	public function test_checkout_action_without_nonce_dies_and_makes_no_http_call(): void {
		$this->seed();
		$http     = new Babilim_Fake_Http( array() );
		$_POST    = array( 'action' => 'babilim_checkout' );
		$_REQUEST = $_POST;
		try {
			$this->admin( $http )->dispatch_babilim_checkout();
			$this->fail( 'expected wp_die()' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertCount( 1, $GLOBALS['babilim_test_wp_die_calls'] );
		}
		$this->assertSame( array(), $http->requests );
		$this->assertSame( array(), $GLOBALS['babilim_test_redirects'] );
	}

	public function test_checkout_action_without_capability_dies_and_makes_no_http_call(): void {
		$this->seed();
		$GLOBALS['babilim_test_current_user_can'] = false;
		$http = new Babilim_Fake_Http( array() );
		$this->post( 'babilim_checkout' );
		try {
			$this->admin( $http )->dispatch_babilim_checkout();
			$this->fail( 'expected wp_die()' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertSame( array(), $http->requests );
		}
	}

	public function test_checkout_action_redirects_the_browser_to_the_checkout_url(): void {
		$this->seed();
		$body = array( 'checkout_url' => self::CHECKOUT_URL, 'expires_at' => '2026-10-19T12:30:00Z', 'price' => array( 'amount' => '8.99', 'currency' => 'USD' ) );
		$http = new Babilim_Fake_Http( array( $this->json_response( 201, $body ) ) );
		$this->post( 'babilim_checkout' );

		$this->admin( $http )->dispatch_babilim_checkout();

		$this->assertCount( 1, $http->requests );
		$this->assertSame( 'POST', $http->requests[0]['method'] );
		$this->assertStringContainsString( '/v1/sites/st_test/checkout', $http->requests[0]['url'] );
		$redirects = $GLOBALS['babilim_test_redirects'];
		$this->assertSame( self::CHECKOUT_URL, end( $redirects )['location'] );
	}

	/**
	 * @dataProvider foreign_checkout_urls
	 */
	public function test_checkout_action_refuses_a_url_outside_the_allowed_hosts( string $url ): void {
		$this->seed();
		$body = array( 'checkout_url' => $url, 'expires_at' => '2026-10-19T12:30:00Z', 'price' => array( 'amount' => '8.99', 'currency' => 'USD' ) );
		$http = new Babilim_Fake_Http( array( $this->json_response( 201, $body ) ) );
		$this->post( 'babilim_checkout' );

		$this->admin( $http )->dispatch_babilim_checkout();

		$redirects = $GLOBALS['babilim_test_redirects'];
		$this->assertCount( 1, $redirects );
		$this->assertStringContainsString( 'page=babilim-settings', end( $redirects )['location'] );
		$this->assertSame( 'error', end( $GLOBALS['babilim_test_settings_errors'] )['type'] );
	}

	public static function foreign_checkout_urls(): array {
		return array(
			'other host'        => array( 'https://evil.example/checkout' ),
			'prefix lookalike'  => array( 'https://babilim.org.evil.example/checkout' ),
			'userinfo trick'    => array( 'https://babilim.org@evil.example/checkout' ),
			'plain http'        => array( 'http://babilim.org/checkout' ),
			'scheme-relative'   => array( '//evil.example/checkout' ),
			'javascript'        => array( 'javascript:alert(1)' ),
			'empty'             => array( '' ),
		);
	}

	public function test_checkout_host_match_is_on_whole_labels_not_on_a_text_suffix(): void {
		$this->seed();
		add_filter(
			'babilim_checkout_hosts',
			static function (): array {
				return array( 'shop.example' );
			}
		);
		foreach ( array( 'https://evilshop.example/c', 'https://shop.example.evil.example/c' ) as $url ) {
			$GLOBALS['babilim_test_redirects'] = array();
			$body = array( 'checkout_url' => $url, 'expires_at' => '2026-10-19T12:30:00Z', 'price' => array( 'amount' => '8.99', 'currency' => 'USD' ) );
			$this->post( 'babilim_checkout' );
			$this->admin( new Babilim_Fake_Http( array( $this->json_response( 201, $body ) ) ) )->dispatch_babilim_checkout();
			$this->assertStringContainsString( 'page=babilim-settings', end( $GLOBALS['babilim_test_redirects'] )['location'], $url );
		}
		$GLOBALS['babilim_test_redirects'] = array();
		$body = array( 'checkout_url' => 'https://pay.shop.example/c', 'expires_at' => '2026-10-19T12:30:00Z', 'price' => array( 'amount' => '8.99', 'currency' => 'USD' ) );
		$this->post( 'babilim_checkout' );
		$this->admin( new Babilim_Fake_Http( array( $this->json_response( 201, $body ) ) ) )->dispatch_babilim_checkout();
		$this->assertSame( 'https://pay.shop.example/c', end( $GLOBALS['babilim_test_redirects'] )['location'] );
	}

	public function test_checkout_action_accepts_the_bare_domain_and_a_host_added_by_the_filter(): void {
		$this->seed();
		foreach ( array( 'https://babilim.org/checkout?s=1' => null, 'https://shop.example/c?s=1' => 'shop.example' ) as $url => $extra ) {
			$GLOBALS['babilim_test_redirects'] = array();
			if ( null !== $extra ) {
				add_filter(
					'babilim_checkout_hosts',
					static function ( array $hosts ) use ( $extra ): array {
						$hosts[] = $extra;
						return $hosts;
					}
				);
			}
			$body = array( 'checkout_url' => $url, 'expires_at' => '2026-10-19T12:30:00Z', 'price' => array( 'amount' => '8.99', 'currency' => 'USD' ) );
			$this->post( 'babilim_checkout' );
			$this->admin( new Babilim_Fake_Http( array( $this->json_response( 201, $body ) ) ) )->dispatch_babilim_checkout();
			$this->assertSame( $url, end( $GLOBALS['babilim_test_redirects'] )['location'] );
		}
	}

	public function test_checkout_action_error_becomes_a_notice_and_returns_to_settings(): void {
		$this->seed();
		$err  = array( 'error' => array( 'code' => 'already_licensed', 'message' => 'x', 'hint' => 'y' ) );
		$http = new Babilim_Fake_Http( array( $this->json_response( 409, $err ) ) );
		$this->post( 'babilim_checkout' );

		$this->admin( $http )->dispatch_babilim_checkout();

		$redirects = $GLOBALS['babilim_test_redirects'];
		$this->assertStringContainsString( 'page=babilim-settings', end( $redirects )['location'] );
		$messages = array_column( $GLOBALS['babilim_test_settings_errors'], 'message' );
		$this->assertContains( 'This site is already licensed. Reload the page.', $messages );
	}

	public function test_checkout_unavailable_has_its_own_notice(): void {
		$this->seed();
		$err  = array( 'error' => array( 'code' => 'checkout_unavailable', 'message' => 'x', 'hint' => 'y' ) );
		$http = new Babilim_Fake_Http( array( $this->json_response( 403, $err ) ) );
		$this->post( 'babilim_checkout' );

		$this->admin( $http )->dispatch_babilim_checkout();

		$messages = array_column( $GLOBALS['babilim_test_settings_errors'], 'message' );
		$this->assertContains( 'Checkout is not available for this site right now.', $messages );
	}

	public function test_license_status_ajax_requires_nonce_and_capability(): void {
		$this->seed();
		$http = new Babilim_Fake_Http( array() );

		$this->ajax( false );
		try {
			$this->admin( $http )->ajax_babilim_license_status();
			$this->fail( 'expected wp_die()' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertNull( $GLOBALS['babilim_test_json'] );
		}

		$this->ajax();
		$GLOBALS['babilim_test_current_user_can'] = false;
		$sent = $this->ajax_result( $this->admin( $http ) );
		$this->assertFalse( $sent['success'] );
		$this->assertSame( 'forbidden', $sent['data']['code'] );
		$this->assertSame( 403, $sent['status'] );
		$this->assertSame( array(), $http->requests );
	}

	public function test_license_status_ajax_returns_kind_and_refreshes_state_once_paid(): void {
		$this->seed();
		$site = array(
			'site_id'  => 'st_test',
			'verified' => true,
			'license'  => array( 'kind' => 'paid', 'status' => 'active' ),
			'quota'    => array(),
		);
		$inv  = array( 'components' => array(), 'updates' => array(), 'license' => array( 'kind' => 'paid', 'status' => 'active' ), 'quota' => array(), 'contract' => '1.7.0' );
		$http = new Babilim_Fake_Http( array( $this->json_response( 200, $site ), $this->json_response( 200, $inv ) ) );
		$this->ajax();

		$sent = $this->ajax_result( $this->admin( $http ) );

		$this->assertTrue( $sent['success'] );
		$this->assertSame( 'paid', $sent['data']['kind'] );
		$this->assertSame( 'paid', ( new Babilim_State() )->get()['license']['kind'] );
		$this->assertSame( 5, $http->requests[0]['args']['timeout'], 'the status poll uses the short interactive timeout' );
		$this->assertCount( 2, $http->requests );
		$this->assertStringContainsString( '/v1/sites/st_test/inventory', $http->requests[1]['url'] );
	}

	public function test_license_status_ajax_still_free_changes_nothing(): void {
		$this->seed();
		$site = array( 'site_id' => 'st_test', 'verified' => true, 'license' => array( 'kind' => 'free', 'status' => 'active' ), 'quota' => array() );
		$http = new Babilim_Fake_Http( array( $this->json_response( 200, $site ) ) );
		$this->ajax();
		$before = ( new Babilim_State() )->get();

		$sent = $this->ajax_result( $this->admin( $http ) );

		$this->assertTrue( $sent['success'] );
		$this->assertSame( 'free', $sent['data']['kind'] );
		$this->assertSame( $before, ( new Babilim_State() )->get() );
		$this->assertCount( 1, $http->requests );
	}

	public function test_license_status_ajax_passes_a_service_error_through_without_touching_state(): void {
		$this->seed();
		$before = ( new Babilim_State() )->get();
		$err    = array( 'error' => array( 'code' => 'rate_limited', 'message' => 'x', 'hint' => 'y' ) );
		$http   = new Babilim_Fake_Http( array( $this->json_response( 429, $err ) ) );
		$this->ajax();

		$sent = $this->ajax_result( $this->admin( $http ) );

		$this->assertFalse( $sent['success'] );
		$this->assertNotSame( '', $sent['data']['code'] );
		$this->assertSame( $before, ( new Babilim_State() )->get() );
		$this->assertCount( 1, $http->requests );
	}

	public function test_license_status_ajax_for_an_unregistered_site_says_so(): void {
		update_option( 'babilim_consent', true );
		$http = new Babilim_Fake_Http( array() );
		$this->ajax();

		$sent = $this->ajax_result( $this->admin( $http ) );

		$this->assertFalse( $sent['success'] );
		$this->assertSame( 'not_registered', $sent['data']['code'] );
		$this->assertSame( array(), $http->requests );
	}

	public function test_the_site_token_never_appears_in_the_rendered_markup(): void {
		$this->seed();
		foreach ( array( 'babilim-settings', 'babilim' ) as $slug ) {
			$this->assertStringNotContainsString( self::TOKEN, $this->page( $slug ), $slug );
		}
	}
}
