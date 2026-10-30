<?php
/**
 * Restoring a purchase with a restore code, and showing a new restore
 * code, from the Settings screen (plus the readme lines that describe
 * them and the checkout-form follow-ups).
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-kses.php';

final class Test_Admin_Restore extends TestCase {

	private const TOKEN = 'tok_SECRET_restore_token_value';
	private const CODE  = 'rc_0123456789abcdef0123456789abcdef';

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

	private function error_response( int $status, string $code ): array {
		return $this->json_response( $status, array( 'error' => array( 'code' => $code, 'message' => 'x', 'hint' => 'y' ) ) );
	}

	private function seed( string $kind = 'free', bool $price = true ): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'woocommerce/woocommerce.php' => array( 'Name' => 'Woo', 'Version' => '1.0.0', 'TextDomain' => 'woocommerce' ) );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => self::TOKEN ), false );
		$state = array(
			'fetched_at' => gmdate( 'c' ),
			'license'    => array( 'kind' => $kind, 'status' => 'active' ),
			'components' => array(),
		);
		if ( $price ) {
			$state['price'] = array( 'amount' => '8.99', 'currency' => 'USD' );
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

	private function post( string $action, array $extra = array() ): void {
		$fields   = array( 'action' => $action, '_wpnonce' => wp_create_nonce( $action ) ) + $extra;
		$_POST    = $fields;
		$_REQUEST = $fields;
	}

	private function last_notice(): array {
		$all = $GLOBALS['babilim_test_settings_errors'];
		$this->assertNotSame( array(), $all );
		return (array) end( $all );
	}

	private function readme(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
	}

	public function test_free_site_shows_the_restore_input_and_button_outside_the_settings_form(): void {
		$this->seed( 'free' );
		$html = $this->page( 'babilim-settings' );
		$x    = $this->xpath( $html );

		$input = $this->el( $x, '//input[@name="restore_code"]' );
		$this->assertSame( 'babilim-restore-form', $input->getAttribute( 'form' ) );
		$this->assertTrue( $input->hasAttribute( 'required' ) );
		$this->assertSame( 'rc_…', $input->getAttribute( 'placeholder' ) );
		$this->assertSame( 'Restore code', $input->getAttribute( 'aria-label' ) );
		$this->assertSame( 1, $x->query( '//button[normalize-space()="Restore purchase"][@form="babilim-restore-form"][@type="submit"]' )->length );
		$this->assertStringContainsString( 'Bought a license for this domain before? Enter the restore code you received at checkout.', $x->query( '//body' )->item( 0 )->textContent );
		$desc = $x->query( '//button[normalize-space()="Restore purchase"]/following-sibling::p[contains(@class,"description")]' );
		$this->assertSame( 1, $desc->length, 'the explanation is its own paragraph below the button row' );
		$this->assertStringStartsWith( 'Bought a license', $desc->item( 0 )->textContent );
		$this->assertSame( 0, $x->query( '//span[contains(@class,"description")][contains(.,"Bought a license")]' )->length );

		$form = $this->el( $x, '//form[@id="babilim-restore-form"]' );
		$this->assertSame( 'post', $form->getAttribute( 'method' ) );
		$this->assertStringContainsString( 'admin-post.php', $form->getAttribute( 'action' ) );
		$this->assertSame( 1, $x->query( './/input[@name="action"][@value="babilim_restore"]', $form )->length );
		$this->assertSame( 1, $x->query( './/input[@name="_wpnonce"]', $form )->length );
		// The controls sit in the Account row, not inside either form.
		$this->assertSame( 0, $x->query( '//form[@id="babilim-restore-form"]//input[@name="restore_code"]' )->length );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-restore-code-form"]' )->length );
		$this->assertSame( 0, $x->query( '//button[normalize-space()="Show a new restore code"]' )->length );
		$this->assertSame( 1, $this->form_depth( $html ) );
		$this->assertStringNotContainsString( self::TOKEN, $html );
	}

	public function test_paid_site_shows_the_new_code_button_with_a_confirmation(): void {
		$this->seed( 'paid', false );
		$html = $this->page( 'babilim-settings' );
		$x    = $this->xpath( $html );

		$button = $this->el( $x, '//button[normalize-space()="Show a new restore code"]' );
		$this->assertSame( 'babilim-restore-code-form', $button->getAttribute( 'form' ) );
		$this->assertSame( 'submit', $button->getAttribute( 'type' ) );
		$this->assertSame( 'This issues a new restore code and the previous one stops working. Continue?', $button->getAttribute( 'data-babilim-confirm' ) );
		$form = $this->el( $x, '//form[@id="babilim-restore-code-form"]' );
		$this->assertSame( 1, $x->query( './/input[@name="action"][@value="babilim_restore_code"]', $form )->length );
		$this->assertSame( 1, $x->query( './/input[@name="_wpnonce"]', $form )->length );
		$this->assertSame( 0, $x->query( '//input[@name="restore_code"]' )->length );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-restore-form"]' )->length );
		$this->assertSame( 1, $this->form_depth( $html ) );
	}

	/**
	 * @return array<string,array{0:?string,1:bool,2:bool,3:bool}> kind, input, new-code button, free quota
	 */
	public static function license_cases(): array {
		return array(
			'free'    => array( 'free', true, false, true ),
			'paid'    => array( 'paid', false, true, false ),
			'partner' => array( 'partner', false, false, false ),
			'missing' => array( null, true, false, false ),
		);
	}

	/**
	 * @dataProvider license_cases
	 */
	public function test_account_row_follows_the_license_kind( ?string $kind, bool $input, bool $new_code, bool $free_quota ): void {
		$this->seed( $kind ?? 'free', false );
		$state = array(
			'fetched_at' => gmdate( 'c' ),
			'components' => array(),
			'quota'      => array(
				'free_slots' => array( 'theme' => array( 'used' => 1, 'limit' => 3 ), 'plugin' => array( 'used' => 2, 'limit' => 5 ) ),
				'downloads'  => array( 'used' => 4, 'limit' => 500, 'period' => 'daily' ),
			),
		);
		if ( null !== $kind ) {
			$state['license'] = array( 'kind' => $kind, 'status' => 'active' );
		}
		( new Babilim_State() )->set( $state );

		$html = $this->page( 'babilim-settings' );
		$x    = $this->xpath( $html );
		$this->assertSame( $input ? 1 : 0, $x->query( '//input[@name="restore_code"]' )->length );
		$this->assertSame( $input ? 1 : 0, $x->query( '//button[normalize-space()="Restore purchase"]' )->length );
		$this->assertSame( $input ? 1 : 0, $x->query( '//form[@id="babilim-restore-form"]' )->length );
		$this->assertSame( $new_code ? 1 : 0, $x->query( '//button[normalize-space()="Show a new restore code"]' )->length );
		$this->assertSame( $new_code ? 1 : 0, $x->query( '//form[@id="babilim-restore-code-form"]' )->length );
		$this->assertSame( $free_quota, false !== strpos( $x->document->textContent, 'Free quota' ) );
		$this->assertStringContainsString( 'Downloads: 4/500 (daily)', $x->document->textContent );
		$this->assertSame( 1, $this->form_depth( $html ) );
	}

	public function test_no_consent_shows_no_restore_controls(): void {
		$this->seed( 'free' );
		update_option( 'babilim_consent', false );
		$x = $this->xpath( $this->page( 'babilim-settings' ) );
		$this->assertSame( 0, $x->query( '//input[@name="restore_code"]' )->length );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-restore-form"]' )->length );
	}

	public function test_restore_action_rejects_a_malformed_code_without_an_http_call(): void {
		$this->seed();
		foreach ( array( '', 'rc_xyz', 'RC_0123456789abcdef0123456789abcdef', self::CODE . '0', 'rc_0123456789ABCDEF0123456789abcdef' ) as $bad ) {
			$GLOBALS['babilim_test_settings_errors'] = array();
			$GLOBALS['babilim_test_redirects']       = array();
			$http = new Babilim_Fake_Http( array() );
			$this->post( 'babilim_restore', array( 'restore_code' => $bad ) );

			$this->admin( $http )->dispatch_babilim_restore();

			$this->assertSame( array(), $http->requests, $bad );
			$this->assertSame( 'That does not look like a restore code.', $this->last_notice()['message'], $bad );
			$this->assertSame( 'error', $this->last_notice()['type'], $bad );
			$redirects = $GLOBALS['babilim_test_redirects'];
			$this->assertStringContainsString( 'page=babilim-settings', end( $redirects )['location'] );
		}
	}

	public function test_restore_action_without_nonce_or_capability_dies_without_an_http_call(): void {
		$this->seed();
		$http     = new Babilim_Fake_Http( array() );
		$_POST    = array( 'action' => 'babilim_restore', 'restore_code' => self::CODE );
		$_REQUEST = $_POST;
		try {
			$this->admin( $http )->dispatch_babilim_restore();
			$this->fail( 'expected wp_die()' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertSame( array(), $http->requests );
		}
		$GLOBALS['babilim_test_current_user_can'] = false;
		$this->post( 'babilim_restore', array( 'restore_code' => self::CODE ) );
		try {
			$this->admin( $http )->dispatch_babilim_restore();
			$this->fail( 'expected wp_die()' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertSame( array(), $http->requests );
		}
	}

	public function test_restore_action_success_updates_state_syncs_and_notices(): void {
		$this->seed();
		$license = array( 'kind' => 'paid', 'status' => 'active' );
		$inv     = array( 'components' => array(), 'updates' => array(), 'license' => $license, 'quota' => array(), 'contract' => '1.7.0' );
		$http    = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'license' => $license ) ), $this->json_response( 200, $inv ) ) );
		$this->post( 'babilim_restore', array( 'restore_code' => '  ' . self::CODE . ' ' ) );

		$this->admin( $http )->dispatch_babilim_restore();

		$this->assertCount( 2, $http->requests );
		$this->assertSame( 'POST', $http->requests[0]['method'] );
		$this->assertStringContainsString( '/v1/sites/st_test/restore', $http->requests[0]['url'] );
		$this->assertStringContainsString( self::CODE, (string) $http->requests[0]['args']['body'] );
		$this->assertStringContainsString( '/v1/sites/st_test/inventory', $http->requests[1]['url'] );
		$this->assertSame( 'paid', ( new Babilim_State() )->get()['license']['kind'] );
		$notice = $this->last_notice();
		$this->assertSame( 'Purchase restored. This site is now licensed.', $notice['message'] );
		$this->assertSame( 'updated', $notice['type'] );
		$redirects = $GLOBALS['babilim_test_redirects'];
		$this->assertStringContainsString( 'page=babilim-settings', end( $redirects )['location'] );
	}

	public function test_restore_action_maps_not_found_and_rate_limited_to_notices(): void {
		$cases = array(
			array( 404, 'not_found', 'This restore code was not recognised for this site.' ),
			array( 429, 'rate_limited', 'Too many attempts. Try again tomorrow.' ),
			array( 403, 'verification_required', 'This site must be verified first.' ),
			array( 500, 'boom', 'Could not restore the purchase: ' ),
		);
		foreach ( $cases as list( $status, $code, $expected ) ) {
			$this->seed();
			$before                                  = ( new Babilim_State() )->get();
			$GLOBALS['babilim_test_settings_errors'] = array();
			$responses                               = 429 === $status
				? array( $this->error_response( 429, $code ) )
				: array( $this->error_response( $status, $code ) );
			$http = new Babilim_Fake_Http( $responses );
			$this->post( 'babilim_restore', array( 'restore_code' => self::CODE ) );

			$this->admin( $http )->dispatch_babilim_restore();

			$this->assertStringStartsWith( $expected, $this->last_notice()['message'], $code );
			$this->assertSame( 'error', $this->last_notice()['type'], $code );
			$this->assertSame( $before, ( new Babilim_State() )->get(), $code );
		}
	}

	public function test_new_code_action_shows_the_code_once_and_only_to_the_admin_who_asked(): void {
		$this->seed( 'paid', false );
		$http = new Babilim_Fake_Http( array( $this->json_response( 200, array( 'restore_code' => self::CODE ) ) ) );
		$this->post( 'babilim_restore_code' );

		$this->admin( $http )->dispatch_babilim_restore_code();

		$this->assertCount( 1, $http->requests );
		$this->assertStringContainsString( '/v1/sites/st_test/restore-code', $http->requests[0]['url'] );
		// Never in the shared notice store, the state or any option.
		$this->assertFalse( get_transient( 'settings_errors' ) );
		$this->assertStringNotContainsString( self::CODE, (string) wp_json_encode( $GLOBALS['babilim_test_settings_errors'] ) );
		$this->assertStringNotContainsString( self::CODE, (string) wp_json_encode( ( new Babilim_State() )->get() ) );
		$this->assertStringNotContainsString( self::CODE, (string) wp_json_encode( $GLOBALS['babilim_test_options'] ) );
		$this->assertStringContainsString( self::CODE, (string) wp_json_encode( $GLOBALS['babilim_test_transients'] ) );

		// Another admin opening the page inside the 60 seconds does not see it.
		$GLOBALS['babilim_test_user_id'] = 2;
		$this->assertStringNotContainsString( self::CODE, $this->page( 'babilim-settings' ) );
		$GLOBALS['babilim_test_user_id'] = 1;
		$GLOBALS['babilim_test_settings_errors'] = array();

		// The admin who asked sees it once; the transient is gone afterwards.
		$html = $this->page( 'babilim-settings' );
		$this->assertStringContainsString( 'Your new restore code: ' . self::CODE . '. Keep it somewhere safe — the previous code no longer works.', $html );
		$this->assertStringNotContainsString( self::CODE, (string) wp_json_encode( $GLOBALS['babilim_test_transients'] ) );
		$GLOBALS['babilim_test_settings_errors'] = array();
		$this->assertStringNotContainsString( self::CODE, $this->page( 'babilim-settings' ) );
	}

	public function test_new_code_action_without_purchase_is_a_notice(): void {
		$this->seed( 'free' );
		$http = new Babilim_Fake_Http( array( $this->error_response( 409, 'no_purchase' ) ) );
		$this->post( 'babilim_restore_code' );

		$this->admin( $http )->dispatch_babilim_restore_code();

		$this->assertSame( 'This site has no purchase to show a code for.', $this->last_notice()['message'] );
		$this->assertSame( 'error', $this->last_notice()['type'] );
	}

	public function test_new_code_action_other_errors_and_missing_identity(): void {
		$this->seed( 'paid', false );
		$http = new Babilim_Fake_Http( array( $this->error_response( 500, 'boom' ) ) );
		$this->post( 'babilim_restore_code' );
		$this->admin( $http )->dispatch_babilim_restore_code();
		$this->assertStringStartsWith( 'Could not show a new restore code: ', $this->last_notice()['message'] );

		$GLOBALS['babilim_test_settings_errors'] = array();
		delete_option( 'babilim_identity' );
		$http = new Babilim_Fake_Http( array() );
		$this->post( 'babilim_restore_code' );
		$this->admin( $http )->dispatch_babilim_restore_code();
		$this->assertSame( array(), $http->requests );
		$this->assertSame( 'This site is not registered with Babilim yet.', $this->last_notice()['message'] );
	}

	public function test_checkout_form_opens_without_an_opener_and_the_attribute_survives_kses(): void {
		$this->seed( 'free' );
		$x    = $this->xpath( $this->page( 'babilim-settings' ) );
		$form = $this->el( $x, '//form[@id="babilim-checkout-form"]' );
		$this->assertSame( 'noopener', $form->getAttribute( 'rel' ) );
	}

	public function test_the_settings_upgrade_button_is_not_left_disabled_after_submit(): void {
		$this->seed( 'free' );
		$x = $this->xpath( $this->page( 'babilim-settings' ) );
		$this->assertSame( 1, $x->query( '//button[normalize-space()="Upgrade this site"][@form="babilim-checkout-form"]' )->length );
		$this->assertSame( 0, $x->query( '//button[normalize-space()="Upgrade this site" or normalize-space()="Opening checkout…"][@data-babilim-busy-label]' )->length );
	}

	public function test_api_contract_license_required_mentions_the_upgrade_button(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/api-contract.md' );
		$this->assertStringNotContainsString( 'offers no action', $doc );
		$this->assertStringContainsString( 'Upgrade', $doc );
	}

	public function test_readme_mentions_the_one_time_price_the_restore_code_and_the_checkout_page(): void {
		$readme = $this->readme();
		$this->assertStringContainsString( '= How much does a license cost? =', $readme );
		$this->assertStringContainsString( 'A license is a one-time purchase per site domain', $readme );
		$this->assertStringNotContainsString( 'not refundable', $readme );
		$this->assertStringContainsString( '= What is a restore code? =', $readme );
		$this->assertStringContainsString( 'Settings → Restore purchase', $readme );
		$this->assertSame( 1, preg_match( '/^== External services ==\n(.*?)(?=^== |\z)/ms', $readme, $m ) );
		$s = $m[1];
		$this->assertStringContainsString( 'Checkout: when you click "Upgrade this site"', $s );
		$this->assertStringContainsString( 'no payment data passes through this plugin', $s );
		$this->assertStringNotContainsString( 'the only address the plugin ever contacts', $s );
		$this->assertStringContainsString( 'Paddle', $s );
		$this->assertStringContainsString( 'merchant of record', $s );
		$this->assertStringContainsString( 'https://www.paddle.com/legal/buyer-terms', $s );
		$this->assertStringContainsString( 'https://www.paddle.com/legal/privacy', $s );
		$this->assertStringContainsString( 'https://babilim.org/refund/', $readme );
		$this->assertStringContainsString( 'Terms of Service: https://babilim.org/terms/', $s );
		$this->assertStringContainsString( 'Privacy Policy: https://babilim.org/privacy/', $s );
	}
}
