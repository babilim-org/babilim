<?php
/**
 * Row actions, the confirmation flow, and the screens that replaced the
 * per-component detail page.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Row_Actions extends TestCase {

	/** @var Babilim_Memory_Fs */
	private $fs;

	/** @var Babilim_Manifest */
	private $manifest;

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
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

	private function package( string $slug, string $locale, string $server_build_id, string $text ): array {
		$mo  = Babilim_Mo_Writer::write( array( 'Save' => $text ), array() );
		$po  = "# fake po\n";
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
			'server_build_id' => $server_build_id,
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.1.0',
		);
		return array( $man, array( "$slug-$locale.mo" => $mo, "$slug-$locale.po" => $po, 'manifest.json' => wp_json_encode( $man ) ) );
	}

	/** Installs $builds builds (1 or 2) of plugin $slug for $locale; returns the build ids. */
	private function install( string $slug, string $locale, int $builds ): array {
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
			static function ( string $zip_path ) {
				return $GLOBALS['babilim_test_fake_zips'][ $zip_path ] ?? false;
			}
		);
		$ids = array();
		for ( $i = 1; $i <= $builds; $i++ ) {
			$id = sprintf( 'bd_%012d', $i );
			list( $man, $zip ) = $this->package( $slug, $locale, $id, 'v' . $i );
			$GLOBALS['babilim_test_fake_zips'][ "zip://$slug/$i" ] = $zip;
			$this->assertTrue( $installer->install( 'plugin', $slug, $locale, "zip://$slug/$i", $man )->is_ok() );
			$ids[] = $id;
		}
		return $ids;
	}

	private function seed( array $locales ): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'woo/woo.php' => array( 'Name' => 'Woo', 'Version' => '1.0.0', 'TextDomain' => 'woo' ) );
		( new Babilim_State() )->set(
			array(
				'fetched_at' => '2026-10-01T00:00:00Z',
				'components' => array( array( 'kind' => 'plugin', 'slug' => 'woo', 'locales' => $locales ) ),
				'license'    => array( 'kind' => 'paid', 'status' => 'active' ),
			)
		);
	}

	private function admin(): Babilim_Admin {
		return new Babilim_Admin( $this->fs, new Babilim_Fake_Http( array() ) );
	}

	private function page( array $get = array() ): string {
		$_GET = array_merge( array( 'page' => 'babilim' ), $get );
		return $this->admin()->render_current_page();
	}

	private function attr( ?DOMNode $node, string $name ): string {
		return $node instanceof DOMElement ? $node->getAttribute( $name ) : '';
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	private function post( string $action, array $fields ): void {
		$fields['action']   = $action;
		$fields['_wpnonce'] = wp_create_nonce( $action );
		$_POST              = $fields;
		$_REQUEST           = $fields;
	}

	private function ident( string $locale = 'zh_TW' ): array {
		return array( 'kind' => 'plugin', 'slug' => 'woo', 'locale' => $locale );
	}

	/** Row-action forms of a rendered page: action => locale[]. */
	private function row_forms( string $html ): array {
		$x   = $this->xpath( $html );
		$out = array();
		foreach ( $x->query( '//form[starts-with(@id,"babilim-row-")]' ) as $form ) {
			$action = $x->query( './/input[@name="action"]', $form )->item( 0 );
			$locale = $x->query( './/input[@name="locale"]', $form )->item( 0 );
			$out[ $action instanceof DOMElement ? $action->getAttribute( 'value' ) : '' ][] = $locale instanceof DOMElement ? $locale->getAttribute( 'value' ) : '';
		}
		return $out;
	}

	private function installed_build(): ?string {
		$rec = ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woo', 'zh_TW' );
		return null === $rec ? null : (string) $rec['build_id'];
	}

	// -- row actions ------------------------------------------------------

	public function test_only_installed_cells_get_rollback_and_remove(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ), 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000009' ) ) );
		$forms = $this->row_forms( $this->page() );
		$this->assertSame( array( 'zh_TW' ), $forms['babilim_rollback'] ?? array() );
		$this->assertSame( array( 'zh_TW' ), $forms['babilim_remove'] ?? array() );
	}

	public function test_a_degraded_cell_offers_download_remove_and_rollback_but_not_downloaded(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$fs        = $this->fs;
		$installer = new Babilim_Installer( $fs, $this->manifest, static function () { return ''; }, static function () { return false; }, static function () { return false; } );
		$this->assertTrue( $installer->degrade( 'plugin', 'woo', 'zh_TW' )->is_ok() );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );

		$html  = $this->page();
		$forms = $this->row_forms( $html );

		$this->assertSame( array( 'zh_TW' ), $forms['babilim_remove'] ?? array(), 'Remove stays available' );
		$this->assertSame( array( 'zh_TW' ), $forms['babilim_rollback'] ?? array(), 'Roll back stays available' );
		$this->assertStringNotContainsString( 'Downloaded', $html );
		$this->assertStringContainsString( 'Removed after a failed integrity check', $html );
		$x = $this->xpath( $html );
		$this->assertGreaterThan( 0, $x->query( '//input[@name="action"][@value="babilim_download"]' )->length, 'Download is offered' );
	}

	public function test_rollback_needs_a_previous_build(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) );
		$forms = $this->row_forms( $this->page() );
		$this->assertArrayNotHasKey( 'babilim_rollback', $forms );
		$this->assertSame( array( 'zh_TW' ), $forms['babilim_remove'] ?? array() );
	}

	public function test_reinstalling_the_same_build_after_a_degrade_offers_no_rollback(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$fs        = $this->fs;
		$installer = new Babilim_Installer(
			$fs,
			$this->manifest,
			static function ( string $mo_path ) use ( $fs ) {
				$entries = babilim_test_read_mo( (string) $fs->get( $mo_path ) );
				unset( $entries[''] );
				return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
			},
			static function () { return 1; },
			static function ( string $zip_path ) {
				return $GLOBALS['babilim_test_fake_zips'][ $zip_path ] ?? false;
			}
		);
		$this->assertTrue( $installer->degrade( 'plugin', 'woo', 'zh_TW' )->is_ok() );
		list( $man ) = $this->package( 'woo', 'zh_TW', 'bd_000000000001', 'v1' );
		$this->assertTrue( $installer->install( 'plugin', 'woo', 'zh_TW', 'zip://woo/1', $man )->is_ok() );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000001' ) ) );

		$html  = $this->page();
		$forms = $this->row_forms( $html );
		$this->assertArrayNotHasKey( 'babilim_rollback', $forms );
		$this->assertSame( 0, $this->xpath( $html )->query( '//a[contains(@href,"rollback=1")]' )->length );
	}

	public function test_a_legacy_duplicate_history_record_offers_no_rollback_until_it_heals(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$record                      = $this->manifest->installed( 'plugin', 'woo', 'zh_TW' );
		$record['version_history']   = array( $record['version_history'][0], $record['version_history'][0] ); // [A, A].
		$record['previous_build_id'] = $record['build_id']; // What older versions wrote.
		$this->assertTrue( $this->manifest->set( 'plugin', 'woo', 'zh_TW', $record ) );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000001' ) ) );

		$html = $this->page();
		$this->assertArrayNotHasKey( 'babilim_rollback', $this->row_forms( $html ) );
		$this->assertSame( 0, $this->xpath( $html )->query( '//a[contains(@href,"rollback=1")]' )->length );
	}

	public function test_a_record_with_a_different_build_in_its_history_offers_rollback_even_if_previous_build_id_is_stale(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$record                      = $this->manifest->installed( 'plugin', 'woo', 'zh_TW' );
		$record['previous_build_id'] = $record['build_id']; // Stale/legacy value.
		$this->assertTrue( $this->manifest->set( 'plugin', 'woo', 'zh_TW', $record ) );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );

		$this->assertSame( array( 'zh_TW' ), $this->row_forms( $this->page() )['babilim_rollback'] ?? array() );
	}

	public function test_roll_back_and_report_needs_a_previous_build(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000001' ) ) );
		$x = $this->xpath( $this->page() );
		$this->assertSame( 0, $x->query( '//a[contains(@href,"rollback=1")]' )->length );
		$this->assertSame( 1, $x->query( '//a[contains(@href,"screen=report")]' )->length, 'the plain report link stays' );
	}

	public function test_rollback_and_remove_are_post_buttons_that_confirm_and_never_get_links(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) );
		$x = $this->xpath( $this->page() );
		$this->assertSame( 0, $x->query( '//form//form' )->length );
		$this->assertSame( 0, $x->query( '//a[contains(@href,"babilim_remove") or contains(@href,"babilim_rollback")]' )->length );
		$this->assertSame( 2, $x->query( '//form[starts-with(@id,"babilim-row-")]' )->length );
		foreach ( $x->query( '//form[starts-with(@id,"babilim-row-")]' ) as $form ) {
			$this->assertSame( 'post', strtolower( $this->attr( $form, 'method' ) ) );
			$this->assertSame( 'admin-post.php', basename( $this->attr( $form, 'action' ) ) );
			$this->assertSame( 1, $x->query( './/input[@name="_wpnonce"]', $form )->length );
			$button = $x->query( '//button[@form="' . $this->attr( $form, 'id' ) . '"]' )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $button );
			$this->assertNotSame( '', $this->attr( $button, 'data-babilim-confirm' ) );
			$this->assertSame( '1', $this->attr( $button, 'data-babilim-confirmed' ) );
		}
	}

	public function test_report_links_go_to_the_report_page_and_rollback_report_asks_for_rollback(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );
		$x     = $this->xpath( $this->page() );
		$plain = $x->query( '//a[contains(@href,"screen=report") and not(contains(@href,"rollback=1"))]' );
		$roll  = $x->query( '//a[contains(@href,"screen=report") and contains(@href,"rollback=1")]' );
		$this->assertSame( 1, $plain->length );
		$this->assertSame( 1, $roll->length );
	}

	public function test_the_report_links_carry_the_build_id_of_the_installed_build(): void {
		list( , $v2 ) = $this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => $v2 ) ) );
		$x     = $this->xpath( $this->page() );
		$links = $x->query( '//a[contains(@href,"screen=report")]' );
		$this->assertSame( 2, $links->length );
		foreach ( $links as $link ) {
			$this->assertStringContainsString( 'build_id=' . ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woo', 'zh_TW' )['build_id'], $this->attr( $link, 'href' ) );
		}
	}

	public function test_a_subsite_admin_on_multisite_only_gets_the_report_link(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$html  = $this->page();
		$forms = $this->row_forms( $html );
		$this->assertArrayNotHasKey( 'babilim_rollback', $forms );
		$this->assertArrayNotHasKey( 'babilim_remove', $forms );
		$this->assertStringNotContainsString( 'rollback=1', $html );
		$this->assertSame( 1, $this->xpath( $html )->query( '//a[contains(@href,"screen=report")]' )->length );
	}

	// -- one set of row actions, many languages ----------------------------

	/** Two languages installed, each with a previous build. */
	private function seed_two_languages(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->install( 'woo', 'de_DE', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ), 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );
	}

	public function test_one_language_keeps_direct_row_actions_without_a_language_in_the_text(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );
		$x    = $this->xpath( $this->page() );
		$texts = array();
		foreach ( $x->query( '//div[contains(@class,"row-actions")]//button | //div[contains(@class,"row-actions")]//a' ) as $node ) {
			$texts[] = trim( $node->textContent );
		}
		$this->assertSame( array( 'Roll back', 'Remove', 'Roll back and report', 'Report a problem' ), $texts );
		$this->assertSame( 0, $x->query( '//a[contains(@href,"screen=choose")]' )->length );
		$this->assertSame( 2, $x->query( '//form[starts-with(@id,"babilim-row-")]' )->length );
	}

	public function test_several_languages_show_each_row_action_once_and_open_the_picker(): void {
		$this->seed_two_languages();
		$html = $this->page( array( 'babilim_kind' => 'plugin', 's' => 'Wo' ) );
		$x    = $this->xpath( $html );
		$this->assertSame( 0, $x->query( '//form[starts-with(@id,"babilim-row-")]' )->length, 'no per-language POST form in the row' );
		$links = $x->query( '//div[contains(@class,"row-actions")]//a' );
		$this->assertSame( 4, $links->length );
		$texts = array();
		foreach ( $links as $link ) {
			$texts[] = trim( $link->textContent );
			$href    = $this->attr( $link, 'href' );
			$this->assertStringContainsString( 'screen=choose', $href );
			$this->assertStringContainsString( 'kind=plugin', $href );
			$this->assertStringContainsString( 'slug=woo', $href );
			$this->assertStringContainsString( 'babilim_kind=plugin', $href );
			$this->assertStringContainsString( 's=Wo', $href );
		}
		$this->assertSame( array( 'Roll back', 'Remove', 'Roll back and report', 'Report a problem' ), $texts );
		$ops = array();
		foreach ( $links as $link ) {
			preg_match( '/op=([a-z-]+)/', $this->attr( $link, 'href' ), $m );
			$ops[] = $m[1];
		}
		$this->assertSame( array( 'rollback', 'remove', 'rollback-report', 'report' ), $ops );
	}

	public function test_a_language_filter_that_leaves_one_language_in_the_row_acts_on_it_directly(): void {
		$this->seed_two_languages();
		$x = $this->xpath( $this->page( array( 'babilim_locale' => 'zh_TW' ) ) );
		$this->assertSame( 0, $x->query( '//a[contains(@href,"screen=choose")]' )->length );
		$this->assertSame( 2, $x->query( '//form[starts-with(@id,"babilim-row-")]' )->length );
	}

	public function test_row_actions_carry_an_aria_label_with_the_action_and_the_component(): void {
		// Several languages: the picker links name the action and the component.
		$this->seed_two_languages();
		$x      = $this->xpath( $this->page() );
		$labels = array();
		foreach ( $x->query( '//div[contains(@class,"row-actions")]//a' ) as $link ) {
			$labels[] = $this->attr( $link, 'aria-label' );
		}
		$this->assertSame( array( 'Roll back: Woo', 'Remove: Woo', 'Roll back and report: Woo', 'Report a problem: Woo' ), $labels );

		// One language: the label also names the language, and survives the page's HTML filter.
		set_site_transient( 'available_translations', array( 'zh_TW' => array( 'native_name' => 'Chinese Taiwan' ) ) );
		babilim_test_reset();
		Babilim::reset_for_tests();
		$this->fs       = new Babilim_Memory_Fs();
		$this->manifest = new Babilim_Manifest( $this->fs );
		set_site_transient( 'available_translations', array( 'zh_TW' => array( 'native_name' => 'Chinese Taiwan' ) ) );
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );
		$x      = $this->xpath( wp_kses( $this->page(), Babilim_Admin::allowed_admin_html() ) );
		$labels = array();
		foreach ( $x->query( '//div[contains(@class,"row-actions")]//a | //div[contains(@class,"row-actions")]//button' ) as $node ) {
			$labels[] = $this->attr( $node, 'aria-label' );
		}
		$this->assertSame(
			array( 'Roll back: Woo (Chinese Taiwan)', 'Remove: Woo (Chinese Taiwan)', 'Roll back and report: Woo (Chinese Taiwan)', 'Report a problem: Woo (Chinese Taiwan)' ),
			$labels
		);
	}

	public function test_the_report_picker_lists_each_language_with_a_plain_report_link(): void {
		$this->seed_two_languages();
		$x     = $this->xpath( $this->page( array( 'screen' => 'choose', 'op' => 'report', 'kind' => 'plugin', 'slug' => 'woo' ) ) );
		$links = $x->query( '//a[contains(@href,"screen=report")]' );
		$this->assertSame( 2, $links->length );
		$locales = array();
		foreach ( $links as $link ) {
			$href = $this->attr( $link, 'href' );
			$this->assertStringNotContainsString( 'rollback=1', $href );
			$this->assertStringContainsString( 'build_id=', $href );
			preg_match( '/locale=([A-Za-z_]+)/', $href, $m );
			$locales[] = $m[1];
		}
		sort( $locales );
		$this->assertSame( array( 'de_DE', 'zh_TW' ), $locales );
		$this->assertSame( 0, $x->query( '//form//input[@name="action"]' )->length, 'a report is a plain link, not a POST' );
	}

	public function test_the_picker_posts_the_same_form_as_the_single_language_button(): void {
		$this->seed_two_languages();
		$keep = array( 'babilim_kind' => 'plugin', 'babilim_locale' => 'zh_TW', 's' => 'Wo' );
		$x    = $this->xpath( $this->page( array( 'screen' => 'choose', 'op' => 'remove', 'kind' => 'plugin', 'slug' => 'woo' ) + $keep ) );
		$forms = $x->query( '//form[.//input[@name="action"][@value="babilim_remove"]]' );
		$this->assertSame( 2, $forms->length, 'one button per language' );
		$seen = array();
		foreach ( $forms as $form ) {
			$this->assertSame( 'post', strtolower( $this->attr( $form, 'method' ) ) );
			$this->assertSame( 'admin-post.php', basename( $this->attr( $form, 'action' ) ) );
			$this->assertSame( 1, $x->query( './/input[@name="_wpnonce"]', $form )->length );
			$fields = array();
			foreach ( $x->query( './/input', $form ) as $input ) {
				$fields[ $this->attr( $input, 'name' ) ] = $this->attr( $input, 'value' );
			}
			$locale = $fields['locale'];
			$seen[] = $locale;
			unset( $fields['locale'], $fields['_wpnonce'] );
			$this->assertSame( array( 'action' => 'babilim_remove', 'kind' => 'plugin', 'slug' => 'woo', 's' => 'Wo', 'babilim_locale' => 'zh_TW', 'babilim_kind' => 'plugin' ), $fields );
			$button = $x->query( './/button', $form )->item( 0 );
			$this->assertSame( '1', $this->attr( $button, 'data-babilim-confirmed' ) );
			$this->assertSame( $locale, $this->attr( $button, 'title' ), 'the code sits in the title' );
			$this->assertStringContainsString( 'Remove the ' . $locale . ' translation of Woo from this site?', $this->attr( $button, 'data-babilim-confirm' ) );
		}
		sort( $seen );
		$this->assertSame( array( 'de_DE', 'zh_TW' ), $seen );
		$cancel = $x->query( '//a[contains(@class,"button")][normalize-space()="Cancel"]' )->item( 0 );
		foreach ( array( 'page=babilim', 'babilim_kind=plugin', 'babilim_locale=zh_TW', 's=Wo' ) as $needle ) {
			$this->assertStringContainsString( $needle, $this->attr( $cancel, 'href' ) );
		}
		$this->assertStringNotContainsString( 'screen=', $this->attr( $cancel, 'href' ) );
	}

	public function test_the_picker_nonce_and_fields_match_the_row_button_for_the_same_language(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );
		$row = $this->row_form_fields( $this->page(), 'babilim_rollback' );
		$this->seed_two_languages();
		$choose = $this->xpath( $this->page( array( 'screen' => 'choose', 'op' => 'rollback', 'kind' => 'plugin', 'slug' => 'woo' ) ) );
		$form   = $choose->query( '//form[.//input[@name="locale"][@value="zh_TW"]]' )->item( 0 );
		$fields = array();
		foreach ( $choose->query( './/input', $form ) as $input ) {
			$fields[ $this->attr( $input, 'name' ) ] = $this->attr( $input, 'value' );
		}
		$this->assertSame( $row, $fields );
	}

	/** Hidden fields of the first row-action form with the given action. */
	private function row_form_fields( string $html, string $action ): array {
		$x    = $this->xpath( $html );
		$form = $x->query( '//form[starts-with(@id,"babilim-row-")][.//input[@name="action"][@value="' . $action . '"]]' )->item( 0 );
		$out  = array();
		foreach ( $x->query( './/input', $form ) as $input ) {
			$out[ $this->attr( $input, 'name' ) ] = $this->attr( $input, 'value' );
		}
		return $out;
	}

	public function test_the_rollback_picker_lists_only_languages_with_a_previous_build(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->install( 'woo', 'de_DE', 1 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ), 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000001' ) ) );
		$args = array( 'screen' => 'choose', 'kind' => 'plugin', 'slug' => 'woo' );
		$x    = $this->xpath( $this->page( $args + array( 'op' => 'rollback' ) ) );
		$this->assertSame( array( 'zh_TW' ), $this->input_values( $x, 'locale' ) );
		$x = $this->xpath( $this->page( $args + array( 'op' => 'remove' ) ) );
		$this->assertSame( array( 'de_DE', 'zh_TW' ), $this->input_values( $x, 'locale' ) );
	}

	private function input_values( DOMXPath $x, string $name ): array {
		$out = array();
		foreach ( $x->query( '//form//input[@name="' . $name . '"]' ) as $input ) {
			$out[] = $this->attr( $input, 'value' );
		}
		sort( $out );
		return $out;
	}

	public function test_the_report_picker_links_to_the_report_page_with_the_build(): void {
		$this->seed_two_languages();
		$x     = $this->xpath( $this->page( array( 'screen' => 'choose', 'op' => 'rollback-report', 'kind' => 'plugin', 'slug' => 'woo' ) ) );
		$links = $x->query( '//a[contains(@href,"screen=report")]' );
		$this->assertSame( 2, $links->length );
		foreach ( $links as $link ) {
			$this->assertStringContainsString( 'rollback=1', $this->attr( $link, 'href' ) );
			$this->assertStringContainsString( 'build_id=', $this->attr( $link, 'href' ) );
		}
	}

	public function test_the_picker_offers_nothing_for_a_read_only_admin_or_an_unknown_operation(): void {
		$this->seed_two_languages();
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$args = array( 'screen' => 'choose', 'kind' => 'plugin', 'slug' => 'woo' );
		foreach ( array( 'remove', 'rollback', 'explode' ) as $op ) {
			$x = $this->xpath( $this->page( $args + array( 'op' => $op ) ) );
			$this->assertSame( 0, $x->query( '//form//input[@name="action"]' )->length, $op );
		}
		$html = $this->page();
		$this->assertSame( array( 'Report a problem' ), $this->row_action_link_texts( $html ) );
	}

	private function row_action_link_texts( string $html ): array {
		$out = array();
		foreach ( $this->xpath( $html )->query( '//div[contains(@class,"row-actions")]//a | //div[contains(@class,"row-actions")]//button' ) as $node ) {
			$out[] = trim( $node->textContent );
		}
		return $out;
	}

	public function test_confirm_sentences_name_the_language_and_keep_the_code_out_of_the_text(): void {
		set_site_transient( 'available_translations', array( 'zh_TW' => array( 'native_name' => 'Chinese Taiwan' ) ) );
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_000000000002' ) ) );
		$x      = $this->xpath( $this->page() );
		$button = $x->query( '//button[@form][@data-babilim-confirm][contains(@data-babilim-confirm,"Remove the")]' )->item( 0 );
		$this->assertSame( 'Remove the Chinese Taiwan translation of Woo from this site?', $this->attr( $button, 'data-babilim-confirm' ) );
		$html = $this->page( array( 'screen' => 'confirm', 'op' => 'rollback' ) + $this->ident() );
		$this->assertStringContainsString( 'Roll back the Chinese Taiwan translation of Woo to the previous build?', $html );
	}

	// -- notices ------------------------------------------------------------

	public function test_errors_and_warnings_stay_separate_and_first_info_and_success_merge_into_one_notice(): void {
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000009' ) ) );
		( new Babilim_State() )->set(
			array(
				'fetched_at' => '2026-10-01T00:00:00Z',
				'components' => array( array( 'kind' => 'plugin', 'slug' => 'woo', 'locales' => array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000009' ) ) ) ),
				'omitted'    => array( 'components' => 0, 'locales' => 3 ),
			)
		);
		add_settings_error( 'babilim', 'a', 'Info line', 'info' );
		add_settings_error( 'babilim', 'b', 'Error line', 'error' );
		add_settings_error( 'babilim', 'c', 'Success line', 'success' );
		add_settings_error( 'babilim', 'd', 'Warning line', 'warning' );
		$html = $this->page();

		$this->assertSame( 1, preg_match_all( '#<div class="notice notice-error"><p>Error line</p></div>#', $html ) );
		$this->assertSame( 1, preg_match_all( '#<div class="notice notice-warning"><p>Warning line</p></div>#', $html ) );
		$this->assertSame( 1, preg_match( '#<div class="notice notice-success"><p>Info line</p><p>Success line</p></div>#', $html ), 'one merged notice, a line each, in arrival order' );
		$merged = strpos( $html, 'Info line' );
		foreach ( array( 'Error line', 'Warning line', 'left out' ) as $alert ) {
			$this->assertLessThan( $merged, strpos( $html, $alert ), "$alert comes before the merged notice" );
		}
	}

	public function test_only_info_lines_merge_into_an_info_notice(): void {
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000009' ) ) );
		add_settings_error( 'babilim', 'a', 'One', 'info' );
		add_settings_error( 'babilim', 'b', 'Two', 'info' );
		$this->assertStringContainsString( '<div class="notice notice-info"><p>One</p><p>Two</p></div>', $this->page() );
	}

	// -- confirmation flow ------------------------------------------------

	public function test_remove_without_confirmed_goes_to_the_confirm_page_and_keeps_the_files(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$this->post( 'babilim_remove', $this->ident() );
		$this->admin()->dispatch_babilim_remove();
		$this->assertNotNull( $this->installed_build() );
		$location = (string) ( $GLOBALS['babilim_test_redirects'][0]['location'] ?? '' );
		foreach ( array( 'screen=confirm', 'op=remove', 'kind=plugin', 'slug=woo', 'locale=zh_TW' ) as $needle ) {
			$this->assertStringContainsString( $needle, $location );
		}
	}

	public function test_rollback_without_confirmed_goes_to_the_confirm_page_and_keeps_the_build(): void {
		list( , $v2 ) = $this->install( 'woo', 'zh_TW', 2 );
		$this->post( 'babilim_rollback', $this->ident() );
		$this->admin()->dispatch_babilim_rollback();
		$this->assertSame( $v2, $this->installed_build() );
		$this->assertStringContainsString( 'op=rollback', (string) ( $GLOBALS['babilim_test_redirects'][0]['location'] ?? '' ) );
	}

	public function test_confirmed_remove_and_rollback_do_the_work(): void {
		list( $v1 ) = $this->install( 'woo', 'zh_TW', 2 );
		$this->post( 'babilim_rollback', $this->ident() + array( 'confirmed' => '1' ) );
		$this->admin()->dispatch_babilim_rollback();
		$this->assertSame( $v1, $this->installed_build() );
		$this->post( 'babilim_remove', $this->ident() + array( 'confirmed' => '1' ) );
		$this->admin()->dispatch_babilim_remove();
		$this->assertNull( $this->installed_build() );
	}

	public function test_confirmed_remove_adds_the_cell_to_the_auto_install_skip_list(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$this->post( 'babilim_remove', $this->ident() );
		$this->admin()->dispatch_babilim_remove();
		$this->assertFalse( get_option( Babilim_Sync_Installer::OPTION_SKIP, false ), 'the confirm step removes nothing and remembers nothing' );

		$this->post( 'babilim_remove', $this->ident() + array( 'confirmed' => '1' ) );
		$this->admin()->dispatch_babilim_remove();

		$this->assertSame( array( 'plugin:woo:zh_TW' => true ), get_option( Babilim_Sync_Installer::OPTION_SKIP ) );
	}

	public function test_the_automatic_install_notice_shows_once_and_resets_the_counter(): void {
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000009' ) ) );
		update_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALLED, 2, false );

		$first = $this->page();
		$this->assertStringContainsString( 'Babilim installed 2 translations automatically since your last visit.', $first );
		$this->assertSame( 0, (int) get_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALLED, 0 ) );
		$this->assertStringNotContainsString( 'installed 2 translations automatically', $this->page() );

		update_option( Babilim_Sync_Installer::OPTION_AUTO_INSTALLED, 1, false );
		$this->assertStringContainsString( 'Babilim installed 1 translation automatically since your last visit.', $this->page() );
	}

	public function test_confirmed_other_than_one_is_not_a_confirmation(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$this->post( 'babilim_remove', $this->ident() + array( 'confirmed' => 'yes' ) );
		$this->admin()->dispatch_babilim_remove();
		$this->assertNotNull( $this->installed_build() );
	}

	public function test_a_missing_nonce_dies_before_anything_changes(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$_POST = $_REQUEST = $this->ident() + array( 'action' => 'babilim_remove', 'confirmed' => '1' );
		try {
			$this->admin()->dispatch_babilim_remove();
			$this->fail( 'Expected wp_die().' );
		} catch ( Babilim_Test_Wp_Die_Exception $e ) {
			$this->assertNotNull( $this->installed_build() );
		}
	}

	public function test_the_confirm_page_asks_and_posts_confirmed_with_a_nonce_and_offers_cancel(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) );
		$x = $this->xpath( $this->page( array( 'screen' => 'confirm', 'op' => 'remove' ) + $this->ident() ) );
		$form = $x->query( '//form[.//input[@name="action"][@value="babilim_remove"]]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $form );
		$this->assertSame( 'post', strtolower( $this->attr( $form, 'method' ) ) );
		$this->assertSame( 1, $x->query( './/input[@name="confirmed"][@value="1"]', $form )->length );
		$this->assertSame( 1, $x->query( './/input[@name="_wpnonce"]', $form )->length );
		$this->assertSame( 1, $x->query( './/button[contains(@class,"button-primary")]', $form )->length );
		$this->assertGreaterThan( 0, $x->query( '//a[contains(@class,"button")][contains(normalize-space(),"Cancel")]' )->length );
		$rollback = $this->xpath( $this->page( array( 'screen' => 'confirm', 'op' => 'rollback' ) + $this->ident() ) );
		$this->assertSame( 1, $rollback->query( '//input[@name="action"][@value="babilim_rollback"]' )->length );
	}

	public function test_the_confirm_page_names_the_component_not_its_slug(): void {
		$this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) );
		$html = $this->page( array( 'screen' => 'confirm', 'op' => 'remove' ) + $this->ident() );
		$this->assertStringContainsString( 'Remove the zh_TW translation of Woo from this site?', $html );
		$html = $this->page( array( 'screen' => 'confirm', 'op' => 'rollback' ) + $this->ident() );
		$this->assertStringContainsString( 'Roll back the zh_TW translation of Woo to the previous build?', $html );
	}

	public function test_the_confirm_page_refuses_an_unknown_operation(): void {
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) );
		$html = $this->page( array( 'screen' => 'confirm', 'op' => 'explode' ) + $this->ident() );
		$this->assertStringNotContainsString( 'name="confirmed"', $html );
	}

	// -- removed screens and actions --------------------------------------

	public function test_the_detail_screen_shows_the_list_and_verify_is_gone(): void {
		$this->install( 'woo', 'zh_TW', 1 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) );
		$html = $this->page( array( 'screen' => 'detail', 'kind' => 'plugin', 'slug' => 'woo' ) );
		$this->assertStringContainsString( 'id="babilim-translations"', $html );
		$this->assertStringNotContainsString( 'Build date', $html );
		$this->assertFalse( ( new ReflectionClass( Babilim_Admin::class ) )->hasMethod( 'dispatch_babilim_verify' ) );
		$actions = ( new ReflectionClassConstant( Babilim_Admin::class, 'ACTIONS' ) )->getValue();
		$this->assertNotContains( 'babilim_verify', $actions );
		$this->assertStringNotContainsString( 'babilim_verify', $html );
	}

	public function test_the_report_page_posts_rollback_report_when_asked_to_roll_back(): void {
		$get = array( 'screen' => 'report', 'kind' => 'plugin', 'slug' => 'woo', 'locale' => 'zh_TW', 'build_id' => 'bd_000000000002' );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) );
		$plain = $this->page( $get );
		$this->assertSame( 1, $this->xpath( $plain )->query( '//input[@name="action"][@value="babilim_feedback"]' )->length );
		$roll = $this->page( $get + array( 'rollback' => '1' ) );
		$this->assertSame( 1, $this->xpath( $roll )->query( '//input[@name="action"][@value="babilim_rollback_report"]' )->length );
		$this->assertSame( 0, $this->xpath( $roll )->query( '//input[@name="action"][@value="babilim_feedback"]' )->length );
	}

	public function test_rollback_report_returns_to_the_list(): void {
		list( , $v2 ) = $this->install( 'woo', 'zh_TW', 2 );
		$this->post( 'babilim_rollback_report', $this->ident() + array( 'build_id' => 'bd_none', 'category' => 'incorrect' ) );
		$this->admin()->dispatch_babilim_rollback_report();
		$this->assertSame( $v2, $this->installed_build() );
		$location = (string) ( $GLOBALS['babilim_test_redirects'][0]['location'] ?? '' );
		$this->assertStringNotContainsString( 'screen=detail', $location );
		$this->assertStringContainsString( 'page=babilim', $location );
	}

	public function test_advanced_settings_lists_the_supplementary_translations_per_component(): void {
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ) ) );
		$this->manifest->set_default_index(
			'zh_TW',
			array(
				'built_at'  => '2026-01-01T00:00:00Z',
				'included'  => array( array( 'slug' => 'woo', 'component_version' => '1.0.0', 'entries' => 12 ) ),
				'excluded'  => array( array( 'slug' => 'gone', 'reason' => 'inactive' ) ),
				'conflicts' => array(),
				'entries'   => 12,
			)
		);
		$html = $this->page( array( 'screen' => 'settings', 'tab' => 'advanced' ) );
		$this->assertStringContainsString( 'Supplementary translations', $html );
		$this->assertStringContainsString( '<td>12</td>', $html );
		$this->assertStringContainsString( 'No — component inactive', $html );
	}

	// -- multisite sub-site administrators --------------------------------

	public function test_a_subsite_admin_with_a_valid_nonce_cannot_remove_roll_back_or_bulk_download(): void {
		list( , $v2 ) = $this->install( 'woo', 'zh_TW', 2 );
		$this->seed( array( 'zh_TW' => array( 'status' => 'babilim_installed' ), 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_000000000009' ) ) );
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$http  = new Babilim_Fake_Http( array() );
		$admin = new Babilim_Admin( $this->fs, $http );
		$calls = array(
			function () use ( $admin ) {
				$this->post( 'babilim_remove', $this->ident() + array( 'confirmed' => '1' ) );
				$admin->dispatch_babilim_remove();
			},
			function () use ( $admin ) {
				$this->post( 'babilim_rollback', $this->ident() + array( 'confirmed' => '1' ) );
				$admin->dispatch_babilim_rollback();
			},
			function () use ( $admin ) {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				$_POST    = array( '_wpnonce' => wp_create_nonce( 'bulk-translations' ), 'action' => 'download', 'components' => array( 'plugin:woo' ) );
				$_REQUEST = $_POST;
				$admin->on_translations_load();
			},
		);
		foreach ( $calls as $i => $call ) {
			try {
				$call();
				$this->fail( "call $i was not refused" );
			} catch ( Babilim_Test_Wp_Die_Exception $e ) {
				$this->assertSame( $v2, $this->installed_build(), "call $i" );
			}
		}
		$this->assertCount( 0, $http->requests );
		$this->assertNull( ( new Babilim_Manifest( $this->fs ) )->installed( 'plugin', 'woo', 'de_DE' ) );
	}
}
