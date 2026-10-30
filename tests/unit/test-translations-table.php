<?php
/**
 * Babilim_Translations_Table and the translations screen built on it.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/real-kses.php';

final class Test_Translations_Table extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		Babilim::reset_for_tests();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		Babilim::reset_for_tests();
	}

	private function admin( ?Babilim_Http $http = null, ?Babilim_Fs $fs = null ): Babilim_Admin {
		return new Babilim_Admin( $fs ?? new Babilim_Memory_Fs(), $http ?? new Babilim_Fake_Http( array() ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $locales locale => entry.
	 */
	private static function component( string $slug, array $locales, string $kind = 'plugin' ): array {
		return array( 'kind' => $kind, 'slug' => $slug, 'locales' => $locales );
	}

	private static function inventory( string $slug, string $name, string $version = '1.0.0', string $kind = 'plugin' ): array {
		return array( 'kind' => $kind, 'slug' => $slug, 'name' => $name, 'version' => $version, 'text_domain' => $slug );
	}

	private function seed_site( array $components, array $inventory, array $extra_state = array(), string $license = 'paid' ): void {
		update_option( 'babilim_consent', true );
		$plugins = array();
		foreach ( $inventory as $row ) {
			$plugins[ $row['slug'] . '/' . $row['slug'] . '.php' ] = array( 'Name' => $row['name'], 'Version' => $row['version'], 'TextDomain' => $row['text_domain'] );
		}
		$GLOBALS['babilim_test_get_plugins_return'] = $plugins;
		( new Babilim_State() )->set(
			array_merge(
				array(
					'fetched_at' => '2026-10-01T00:00:00Z',
					'components' => $components,
					'license'    => array( 'kind' => $license, 'status' => 'active' ),
				),
				$extra_state
			)
		);
	}

	private function page( array $get = array(), ?Babilim_Admin $admin = null ): string {
		$_GET = array_merge( array( 'page' => 'babilim' ), $get );
		$_REQUEST = array_merge( $_REQUEST, $_GET ); // As a browser GET fills both.
		return ( $admin ?? $this->admin() )->render_current_page();
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	private function attr( ?DOMNode $node, string $name ): string {
		return $node instanceof DOMElement ? $node->getAttribute( $name ) : '';
	}

	private function sample_state(): array {
		return array(
			self::component(
				'woocommerce',
				array(
					'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_aaaaaaaaaaaa', 'built_at' => '2026-09-24T10:00:00Z' ),
					'fr_FR' => array( 'status' => 'requestable' ),
				)
			),
			self::component( 'acme', array( 'de_DE' => array( 'status' => 'wporg' ) ) ),
			self::component( 'zeta', array( 'de_DE' => array( 'status' => 'queued' ) ) ),
		);
	}

	private function sample_inventory(): array {
		return array(
			self::inventory( 'woocommerce', 'WooCommerce', '9.0.0' ),
			self::inventory( 'acme', 'Acme', '2.0.0' ),
			self::inventory( 'zeta', 'Zeta', '3.0.0' ),
		);
	}

	// -- build_items / views_counts / filter_items -------------------------

	public function test_build_items_sorts_by_name_and_reads_installed_from_the_manifest_record(): void {
		$state = array(
			'components' => $this->sample_state(),
			'updates'    => array(),
		);
		$items = Babilim_Translations_Table::build_items(
			$state,
			$this->sample_inventory(),
			static function ( string $kind, string $slug, string $locale ): ?array {
				return ( 'woocommerce' === $slug && 'de_DE' === $locale ) ? array( 'build_id' => 'bd_aaaaaaaaaaaa' ) : null;
			},
			true
		);

		$this->assertSame( array( 'acme', 'woocommerce', 'zeta' ), array_column( $items, 'slug' ) );
		$woo = $items[1];
		$this->assertSame( 'WooCommerce', $woo['name'] );
		$this->assertSame( '9.0.0', $woo['version'] );
		$this->assertTrue( $woo['cells']['de_DE']['installed'], 'a manifest record makes the cell installed even though the state says available' );
		$this->assertSame( 'downloaded', $woo['cells']['de_DE']['blocked_reason'] );
		$this->assertFalse( $woo['cells']['fr_FR']['installed'] );
	}

	public function test_a_degraded_record_is_downloadable_not_installed_and_flagged(): void {
		$state = array( 'components' => array( self::component( 'woocommerce', array( 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_aaaaaaaaaaaa' ) ) ) ) );
		$items = Babilim_Translations_Table::build_items(
			$state,
			array( self::inventory( 'woocommerce', 'WooCommerce' ) ),
			static function (): array {
				return array( 'build_id' => 'bd_aaaaaaaaaaaa', 'degraded_at' => '2026-10-01T00:00:00Z', 'previous_build_id' => 'bd_bbbbbbbbbbbb' );
			},
			true
		);

		$cell = $items[0]['cells']['de_DE'];
		$this->assertTrue( $cell['degraded'] );
		$this->assertSame( 'babilim_download', $cell['action'] );
		$this->assertSame( 0, Babilim_Translations_Table::views_counts( $items )['installed'] );
		$this->assertSame( array(), Babilim_Translations_Table::filter_items( $items, 'installed', '', '' ) );
		$this->assertCount( 1, Babilim_Translations_Table::filter_items( $items, 'available', '', '' ) );
	}

	public function test_build_items_leaves_out_components_that_are_not_installed_on_the_site(): void {
		$state = array( 'components' => array( self::component( 'gone', array( 'de_DE' => array( 'status' => 'requestable' ) ) ) ) );
		$this->assertSame( array(), Babilim_Translations_Table::build_items( $state, array(), static function () {
			return null;
		}, true ) );
	}

	public function test_build_items_passes_the_update_build_id_and_kind_to_the_cell(): void {
		$state = array(
			'components' => array(
				self::component( 'a', array( 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_oldoldoldold' ) ) ),
			),
			'updates'    => array( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE', 'build_id' => 'bd_newnewnewnew' ) ),
		);
		$items = Babilim_Translations_Table::build_items( $state, array( self::inventory( 'a', 'A' ) ), static function () {
			return array( 'build_id' => 'bd_oldoldoldold' );
		}, true );
		$this->assertTrue( $items[0]['cells']['de_DE']['is_update'] );
		$this->assertSame( 'babilim_download', $items[0]['cells']['de_DE']['action'] );

		// The same update, already installed: no longer an update.
		$items = Babilim_Translations_Table::build_items( $state, array( self::inventory( 'a', 'A' ) ), static function () {
			return array( 'build_id' => 'bd_newnewnewnew' );
		}, true );
		$this->assertFalse( $items[0]['cells']['de_DE']['is_update'] );
	}

	public function test_a_locale_entrys_own_update_available_flag_makes_an_update_cell(): void {
		$state = array(
			'components' => array( self::component( 'a', array( 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_oldoldoldold', 'update_available' => true ) ) ) ),
			'updates'    => array(),
		);
		$items = Babilim_Translations_Table::build_items( $state, array( self::inventory( 'a', 'A' ) ), static function () {
			return array( 'build_id' => 'bd_oldoldoldold' );
		}, true );
		$this->assertTrue( $items[0]['cells']['de_DE']['is_update'] );
		$this->assertSame( 'babilim_download', $items[0]['cells']['de_DE']['action'] );
	}

	private function counted_items(): array {
		$state = array(
			'components' => array(
				self::component( 'a', array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_a' ), 'fr_FR' => array( 'status' => 'requestable' ) ) ),
				self::component( 'b', array( 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_b' ) ) ),
				self::component( 'c', array( 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_c' ) ) ),
				self::component( 'd', array( 'de_DE' => array( 'status' => 'bundled' ) ) ),
			),
			'updates'    => array( array( 'kind' => 'plugin', 'slug' => 'c', 'locale' => 'de_DE', 'build_id' => 'bd_c2' ) ),
		);
		$inv   = array( self::inventory( 'a', 'A' ), self::inventory( 'b', 'B' ), self::inventory( 'c', 'C' ), self::inventory( 'd', 'D' ) );
		return Babilim_Translations_Table::build_items(
			$state,
			$inv,
			static function ( string $kind, string $slug ): ?array {
				return in_array( $slug, array( 'b', 'c' ), true ) ? array( 'build_id' => 'bd_' . $slug ) : null;
			},
			true
		);
	}

	public function test_views_counts_count_components_and_installed_excludes_available(): void {
		$counts = Babilim_Translations_Table::views_counts( $this->counted_items() );
		$this->assertSame( 4, $counts['all'] );
		$this->assertSame( 1, $counts['available'], 'only a: a download that is not an update' );
		$this->assertSame( 1, $counts['updates'] );
		$this->assertSame( 1, $counts['requestable'] );
		$this->assertSame( 2, $counts['installed'], 'b and c have a manifest record; a is only available' );
	}

	public function test_filter_items_by_view_locale_and_search(): void {
		$items = $this->counted_items();

		$this->assertSame( array( 'a' ), array_column( Babilim_Translations_Table::filter_items( $items, 'available', '', '' ), 'slug' ) );
		$this->assertSame( array( 'c' ), array_column( Babilim_Translations_Table::filter_items( $items, 'updates', '', '' ), 'slug' ) );
		$this->assertSame( array( 'b', 'c' ), array_column( Babilim_Translations_Table::filter_items( $items, 'installed', '', '' ), 'slug' ) );

		$fr = Babilim_Translations_Table::filter_items( $items, 'all', 'fr_FR', '' );
		$this->assertSame( array( 'a' ), array_column( $fr, 'slug' ) );
		$this->assertSame( array( 'fr_FR' ), array_keys( $fr[0]['cells'] ), 'only the selected locale stays on the row' );

		$this->assertSame( array( 'b' ), array_column( Babilim_Translations_Table::filter_items( $items, 'all', '', 'B' ), 'slug' ), 'search is case-insensitive on the name' );
		$this->assertSame( array( 'c' ), array_column( Babilim_Translations_Table::filter_items( $items, 'all', '', 'c' ), 'slug' ) );
	}

	// -- the rendered screen ----------------------------------------------

	public function test_welcome_block_is_kept_until_consent(): void {
		$html = $this->page();
		$this->assertStringContainsString( 'Enable Babilim', $html );
		$this->assertStringNotContainsString( 'wp-list-table', $html );
	}

	public function test_filter_form_is_a_get_form_with_page_hidden_and_search_box(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x = $this->xpath( $this->page() );

		$form = $x->query( '//form[@id="babilim-filter"]' );
		$this->assertSame( 1, $form->length );
		$this->assertSame( 'get', strtolower( $this->attr( $form->item( 0 ),  'method' ) ) );
		$this->assertSame( 'babilim', $this->attr( $x->query( '//form[@id="babilim-filter"]//input[@type="hidden"][@name="page"]' )->item( 0 ), 'value' ) );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-filter"]//input[@name="s"]' )->length );
		$this->assertSame( 0, $x->query( '//select[@name="babilim_locale"]' )->length, 'the language select is gone' );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-filter"]//input[@type="submit"][@value="Filter"]' )->length, 'and so is the Filter button' );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-filter"]//input[@name="action"]' )->length );
	}

	public function test_a_single_language_site_gets_no_language_links(): void {
		$this->seed_site( array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'wporg' ) ) ) ), array( self::inventory( 'acme', 'Acme' ) ) );
		$html = $this->page();
		$this->assertStringNotContainsString( 'babilim-locale-links', $html );
		$this->assertStringNotContainsString( 'These are the languages this site uses', $html );
	}

	// -- the language link row ---------------------------------------------

	/** The language link paragraph(s). */
	private function locale_row( DOMXPath $x ): DOMNodeList {
		return $x->query( '//p[contains(concat(" ", normalize-space(@class), " "), " babilim-locale-links ")]' );
	}

	public function test_language_links_row_is_drawn_for_several_languages(): void {
		set_site_transient( 'available_translations', array( 'de_DE' => array( 'native_name' => 'Deutsch' ) ) );
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x   = $this->xpath( $this->page() );
		$row = $this->locale_row( $x );
		$this->assertSame( 1, $row->length );
		$this->assertStringStartsWith( 'Language:', trim( $row->item( 0 )->textContent ) );
		$links = $x->query( './/a', $row->item( 0 ) );
		$this->assertSame( 2, $links->length, 'All languages is the current one (no link); de_DE and fr_FR link' );
		$texts = array();
		foreach ( $x->query( './/a | .//strong', $row->item( 0 ) ) as $n ) {
			$texts[] = trim( preg_replace( '/\(.*$/s', '', $n->textContent ) );
		}
		$this->assertSame( array( 'All languages', 'Deutsch', 'fr_FR' ), $texts, 'names, with the code as fallback' );
		$de = $x->query( './/a[@title="de_DE"]', $row->item( 0 ) )->item( 0 );
		$this->assertSame( 'Deutsch', trim( preg_replace( '/\(.*$/s', '', $de->textContent ) ) );
		$this->assertStringContainsString( ' | ', $row->item( 0 )->textContent );
	}

	public function test_language_row_sits_below_the_views_and_has_the_settings_description(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$html = $this->page();
		$this->assertLessThan( strpos( $html, 'babilim-locale-links' ), strpos( $html, 'subsubsub' ), 'below the views' );
		$x    = $this->xpath( $html );
		$desc = $x->query( '//p[@class="description"][contains(., "These are the languages this site uses. You can change them in Settings.")]' );
		$this->assertSame( 1, $desc->length );
		$a = $x->query( './a', $desc->item( 0 ) );
		$this->assertSame( 1, $a->length );
		$this->assertSame( 'Settings', trim( $a->item( 0 )->textContent ) );
		$this->assertStringContainsString( 'page=babilim-settings', html_entity_decode( $this->attr( $a->item( 0 ), 'href' ) ) );
	}

	public function test_language_links_keep_view_search_and_kind_and_replace_the_language(): void {
		$this->seed_mixed();
		$x   = $this->xpath( $this->page( array( 'view' => 'requestable', 's' => 'A&B #1', 'babilim_kind' => 'plugin', 'babilim_locale' => 'de_DE' ) ) );
		$row = $this->locale_row( $x )->item( 0 );
		$this->assertNotNull( $row );
		$seen = array();
		foreach ( $x->query( './/a', $row ) as $a ) {
			$href = html_entity_decode( $this->attr( $a, 'href' ) );
			parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $q );
			$this->assertSame( 'babilim', $q['page'] ?? null, $href );
			$this->assertSame( 'requestable', $q['view'] ?? null, $href );
			$this->assertSame( 'A&B #1', $q['s'] ?? null, $href );
			$this->assertSame( 'plugin', $q['babilim_kind'] ?? null, $href );
			$this->assertSame( '', (string) wp_parse_url( $href, PHP_URL_FRAGMENT ), $href );
			$seen[] = $q['babilim_locale'] ?? '';
		}
		$this->assertSame( array( '', 'fr_FR' ), $seen, 'All languages (no parameter) and fr_FR; de_DE is current' );
	}

	public function test_current_language_is_bold_with_aria_current_and_no_link(): void {
		$this->seed_mixed();
		$x   = $this->xpath( $this->page( array( 'babilim_locale' => 'fr_FR' ) ) );
		$row = $this->locale_row( $x )->item( 0 );
		$cur = $x->query( './/strong[@aria-current]', $row );
		$this->assertSame( 1, $cur->length );
		$this->assertStringStartsWith( 'fr_FR', trim( $cur->item( 0 )->textContent ) );
		$this->assertSame( 0, $x->query( './/a[@title="fr_FR"]', $row )->length, 'the current language is not a link' );
		$this->assertSame( 1, $x->query( './/a[@title="de_DE"]', $row )->length );

		$x   = $this->xpath( $this->page() );
		$cur = $x->query( './/strong[@aria-current]', $this->locale_row( $x )->item( 0 ) );
		$this->assertSame( 1, $cur->length );
		$this->assertStringStartsWith( 'All languages', trim( $cur->item( 0 )->textContent ) );
	}

	public function test_locale_action_counts_count_only_download_cells_and_follow_kind_and_search(): void {
		$items = $this->counted_items();
		// de_DE: a (download) and c (update, download); fr_FR is only requestable.
		$this->assertSame( array( 'de_DE' => 2, 'fr_FR' => 0 ), Babilim_Translations_Table::locale_action_counts( $items, '', '' ) );
		$this->assertSame( array( 'de_DE' => 1 ), Babilim_Translations_Table::locale_action_counts( $items, 'c', '' ), 'the search narrows' );
		$this->assertSame( array(), Babilim_Translations_Table::locale_action_counts( $items, '', 'theme' ), 'the kind narrows' );
		$this->assertSame( array( 'de_DE' => 2, 'fr_FR' => 0 ), Babilim_Translations_Table::locale_action_counts( $items, '', 'plugin' ) );
	}

	public function test_language_counts_show_to_install_independent_of_the_view_and_hide_zero(): void {
		$this->seed_mixed();
		$counts = function ( array $get ): array {
			$x   = $this->xpath( $this->page( $get ) );
			$row = $this->locale_row( $x )->item( 0 );
			$out = array();
			foreach ( $x->query( './/a[@title] | .//strong[@title]', $row ) as $n ) {
				$span = $x->query( './/span[@class="count"]', $n );
				$out[ $this->attr( $n, 'title' ) ] = $span->length ? trim( $span->item( 0 )->textContent ) : '';
			}
			return $out;
		};
		$all = $counts( array() );
		$this->assertSame( array( 'de_DE' => '(1 to install)', 'fr_FR' => '(1 to install)' ), $all );
		$this->assertSame( $all, $counts( array( 'view' => 'requestable' ) ), 'the view does not change the counts' );
		$this->assertSame( $all, $counts( array( 'view' => 'installed' ) ) );
		$this->assertSame( array( 'de_DE' => '(1 to install)', 'fr_FR' => '' ), $counts( array( 'babilim_kind' => 'plugin' ) ), 'kind narrows; zero shows nothing' );
		$this->assertSame( array( 'de_DE' => '', 'fr_FR' => '(1 to install)' ), $counts( array( 'babilim_kind' => 'theme' ) ) );
		$this->assertSame( array( 'de_DE' => '', 'fr_FR' => '' ), $counts( array( 's' => 'ast' ) ), 'the search narrows' );
		$this->assertSame( array( 'de_DE' => '(1 to install)', 'fr_FR' => '(1 to install)' ), $counts( array( 'babilim_locale' => 'fr_FR' ) ), 'the language itself does not change the counts' );
	}

	public function test_get_conditions_leave_only_matching_rows_and_cells(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x = $this->xpath( $this->page( array( 's' => 'woo' ) ) );
		$this->assertSame( 1, $x->query( '//table//tbody/tr' )->length );
		$this->assertStringContainsString( 'WooCommerce', $x->query( '//table//tbody/tr' )->item( 0 )->textContent );

		$x = $this->xpath( $this->page( array( 'babilim_locale' => 'fr_FR' ) ) );
		$this->assertSame( 1, $x->query( '//table//tbody/tr' )->length );
		$this->assertStringContainsString( 'fr_FR', $x->query( '//table//tbody/tr' )->item( 0 )->textContent );
		$this->assertStringNotContainsString( 'de_DE', $x->query( '//table//tbody/tr' )->item( 0 )->textContent );
	}

	public function test_filters_are_read_from_get_only(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$_POST = array( 's' => 'woo', 'babilim_locale' => 'fr_FR' );
		$x     = $this->xpath( $this->page() );
		$this->assertSame( 3, $x->query( '//table//tbody/tr' )->length, 'a POSTed s is not a filter' );
	}

	public function test_bulk_form_is_post_without_action_attribute_and_without_hidden_action(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x    = $this->xpath( $this->page() );
		$form = $x->query( '//form[@id="babilim-translations"]' );

		$this->assertSame( 1, $form->length );
		$this->assertSame( 'post', strtolower( $this->attr( $form->item( 0 ),  'method' ) ) );
		$this->assertFalse( $this->attr( $form->item( 0 ), 'action' ) !== '', 'no action attribute: the POST goes back to the list page' );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-translations"]//input[@type="hidden"][@name="action"]' )->length );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-translations"]//select[@name="action"]' )->length );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-translations"]//select[@name="action2"]' )->length );
		$this->assertSame( 3, $x->query( '//form[@id="babilim-translations"]//input[@type="checkbox"][@name="components[]"]' )->length );
		$this->assertSame( 'plugin:acme', $this->attr( $x->query( '//form[@id="babilim-translations"]//input[@name="components[]"]' )->item( 0 ), 'value' ) );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-translations"]//input[@name="_wpnonce"]' )->length );
		$this->assertSame( wp_create_nonce( 'bulk-translations' ), $this->attr( $x->query( '//form[@id="babilim-translations"]//input[@name="_wpnonce"]' )->item( 0 ), 'value' ) );
	}

	public function test_no_form_is_nested_and_cell_forms_point_at_admin_post_and_exist(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x = $this->xpath( $this->page() );

		$this->assertSame( 0, $x->query( '//form//form' )->length );
		$cells = $x->query( '//form[starts-with(@id,"babilim-cell-")]' );
		$this->assertGreaterThan( 0, $cells->length );
		foreach ( $cells as $cell ) {
			$this->assertSame( 'admin-post.php', basename( $this->attr( $cell, 'action' ) ) );
			$this->assertSame( 'post', strtolower( $this->attr( $cell, 'method' ) ) );
			foreach ( array( 'action', 'kind', 'slug', 'locale', '_wpnonce' ) as $name ) {
				$this->assertSame( 1, $x->query( './/input[@type="hidden"][@name="' . $name . '"]', $cell )->length, $name );
			}
		}
		foreach ( $x->query( '//button[@form]' ) as $button ) {
			$this->assertSame( 1, $x->query( '//form[@id="' . $this->attr( $button, 'form' ) . '"]' )->length );
		}
	}

	public function test_cell_forms_keep_the_filter_so_the_redirect_returns_to_it(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x = $this->xpath( $this->page( array( 's' => 'woo', 'babilim_locale' => 'fr_FR' ) ) );
		$this->assertSame( 'woo', $this->attr( $x->query( '//form[starts-with(@id,"babilim-cell-")]//input[@name="s"]' )->item( 0 ), 'value' ) );
		$this->assertSame( 'fr_FR', $this->attr( $x->query( '//form[starts-with(@id,"babilim-cell-")]//input[@name="babilim_locale"]' )->item( 0 ), 'value' ) );
	}

	public function test_real_kses_keeps_the_structure(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory(), array(), 'free' );
		$raw      = $this->page();
		$filtered = babilim_real_wp_kses( $raw, Babilim_Admin::allowed_admin_html() );

		$a = $this->xpath( $raw );
		$b = $this->xpath( $filtered );
		foreach ( array( '//form', '//input', '//select', '//option', '//button', '//table', '//tr', '//th', '//td', '//button[@form]', '//button[@data-babilim-confirm]', '//td[@data-colname]', '//input[@aria-label]', '//th[@scope]' ) as $query ) {
			$this->assertSame( $a->query( $query )->length, $b->query( $query )->length, $query );
		}
		$this->assertGreaterThan( 0, $b->query( '//button[@data-babilim-confirm]' )->length );
	}

	public function test_real_kses_keeps_core_table_markup_where_it_belongs(): void {
		$components = array();
		$inventory  = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$slug         = sprintf( 'p%03d', $i );
			$components[] = self::component( $slug, array( 'de_DE' => array( 'status' => 'requestable' ) ) );
			$inventory[]  = self::inventory( $slug, 'Plugin ' . $slug );
		}
		$this->seed_site( $components, $inventory );
		$filtered = babilim_real_wp_kses( $this->page(), Babilim_Admin::allowed_admin_html() );
		$x        = $this->xpath( $filtered );

		$this->assertSame( 1, $x->query( '//table/tfoot' )->length, 'the footer header row stays in a tfoot' );
		$this->assertSame( 1, $x->query( '//table/thead/tr' )->length );
		$this->assertSame( 1, $x->query( '//table/tfoot/tr' )->length );
		$this->assertSame( 0, $x->query( '//table/tr' )->length, 'no row outside thead, tbody and tfoot' );
		$this->assertSame( 0, $x->query( '//table/tbody/tr[th[@scope="col"]]' )->length, 'no header row inside a tbody' );
		$this->assertGreaterThan( 0, $x->query( '//br[@class="clear"]' )->length );
		$this->assertGreaterThan( 0, $x->query( '//span[@aria-hidden="true"]' )->length );
		$this->assertGreaterThan( 0, $x->query( '//input[@aria-describedby]' )->length );
	}

	public function test_search_box_disappears_in_an_empty_view_but_not_when_searching(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x = $this->xpath( $this->page( array( 'view' => 'updates' ) ) );
		$this->assertSame( 0, $x->query( '//tbody[@id="the-list"]/tr[not(@class="no-items")]' )->length );
		$this->assertSame( 0, $x->query( '//input[@name="s"]' )->length, 'core hides the search box when there is nothing to search' );
		$this->assertSame( 0, $x->query( '//select[@name="action"]' )->length, 'and prints no bulk actions without rows' );

		$_REQUEST = array( 's' => 'nothing-matches' );
		$x        = $this->xpath( $this->page( array( 'view' => 'updates', 's' => 'nothing-matches' ) ) );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-filter"]//input[@name="s"]' )->length );
	}

	public function test_a_cell_with_an_unknown_status_has_no_button(): void {
		$this->seed_site( array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'something_new' ) ) ) ), array( self::inventory( 'acme', 'Acme' ) ) );
		$x = $this->xpath( $this->page() );
		$this->assertSame( 0, $x->query( '//table//tbody//button' )->length );
		$this->assertStringContainsString( 'Status unknown', $x->query( '//table//tbody' )->item( 0 )->textContent );
	}

	public function test_without_write_access_there_is_no_download_button(): void {
		$this->seed_site( array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_aaaaaaaaaaaa' ) ) ) ), array( self::inventory( 'acme', 'Acme' ) ) );
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$html = $this->page();
		$this->assertStringNotContainsString( 'value="babilim_download"', $html );
		$this->assertStringContainsString( 'Only a network administrator can do this', $html );
	}

	public function test_the_network_notice_sits_under_the_line_not_in_the_action_cell(): void {
		$this->seed_site( array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_aaaaaaaaaaaa' ) ) ) ), array( self::inventory( 'acme', 'Acme' ) ) );
		$GLOBALS['babilim_test_multisite']        = true;
		$GLOBALS['babilim_test_current_user_can'] = array( 'manage_options' );
		$x = $this->xpath( $this->page() );
		$this->assertSame( 0, $x->query( '//span[contains(@class,"babilim-cell-action")]//*[contains(., "Only a network administrator")]' )->length );
		$this->assertSame( '', trim( $x->query( '//span[contains(@class,"babilim-cell-action")]' )->item( 0 )->textContent ) );
		$this->assertSame( 1, $x->query( '//div[contains(@class,"babilim-cell-row")]/div[contains(@class,"babilim-cell-warning")][contains(., "Only a network administrator")]' )->length, 'same place as the hard-coded warning' );
	}

	public function test_list_links_encode_the_search_and_the_language_so_the_other_parameters_survive(): void {
		$this->seed_mixed();
		$search = 'A&B #1+x';
		$x      = $this->xpath( $this->page( array( 's' => $search, 'babilim_locale' => 'de_DE', 'babilim_kind' => 'plugin' ) ) );
		$hrefs  = array();
		foreach ( $x->query( '//*[contains(@class,"nav-tab-wrapper")]/a | //ul[contains(@class,"subsubsub")]/li/a' ) as $a ) {
			$hrefs[] = html_entity_decode( $this->attr( $a, 'href' ) );
		}
		$this->assertGreaterThanOrEqual( 8, count( $hrefs ), 'three tabs and five views' );
		foreach ( $hrefs as $href ) {
			$this->assertSame( '', (string) wp_parse_url( $href, PHP_URL_FRAGMENT ), "no stray fragment in $href" );
			parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $q );
			$this->assertSame( $search, $q['s'] ?? null, $href );
			$this->assertSame( 'de_DE', $q['babilim_locale'] ?? null, $href );
			$this->assertSame( 'babilim', $q['page'] ?? null, $href );
		}
		$kinds = array();
		foreach ( $x->query( '//*[contains(@class,"nav-tab-wrapper")]/a' ) as $a ) {
			parse_str( (string) wp_parse_url( html_entity_decode( $this->attr( $a, 'href' ) ), PHP_URL_QUERY ), $q );
			$kinds[] = $q['babilim_kind'] ?? '';
		}
		$this->assertSame( array( '', 'plugin', 'theme' ), $kinds );
		foreach ( $x->query( '//ul[contains(@class,"subsubsub")]/li/a' ) as $a ) {
			parse_str( (string) wp_parse_url( html_entity_decode( $this->attr( $a, 'href' ) ), PHP_URL_QUERY ), $q );
			$this->assertSame( 'plugin', $q['babilim_kind'] ?? null, 'a view link keeps the tab' );
		}
	}

	public function test_the_language_links_list_codes_in_code_order_whatever_the_names(): void {
		set_site_transient( 'available_translations', array( 'de_DE' => array( 'native_name' => 'Zzz' ), 'fr_FR' => array( 'native_name' => 'Aaa' ) ) );
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x      = $this->xpath( $this->page() );
		$values = array();
		foreach ( $x->query( '//p[contains(@class,"babilim-locale-links")]//a' ) as $a ) {
			$values[] = $this->attr( $a, 'title' );
		}
		$this->assertSame( array( 'de_DE', 'fr_FR' ), $values );
	}

	public function test_free_site_request_buttons_carry_the_slot_confirmation_and_paid_sites_do_not(): void {
		$sentence = 'If this component does not use one of your free slots yet, requesting a translation uses one. A failed translation does not give the slot back. Continue?';
		$this->seed_site( $this->sample_state(), $this->sample_inventory(), array(), 'free' );
		$html = $this->page();
		$x    = $this->xpath( $html );

		$requests = $x->query( '//form[starts-with(@id,"babilim-cell-")][.//input[@name="action"][@value="babilim_request"]]' );
		$this->assertSame( 2, $requests->length, 'acme (wporg) and woocommerce fr_FR' );
		foreach ( $requests as $form ) {
			$buttons = $x->query( '//button[@form="' . $this->attr( $form, 'id' ) . '"]' );
			$this->assertSame( 1, $buttons->length );
			$this->assertSame( $sentence, $this->attr( $buttons->item( 0 ), 'data-babilim-confirm' ) );
		}
		$this->assertSame( 0, $x->query( '//table//td//span[@class="description"][contains(., "free slots")]' )->length, 'no per-cell sentence: it would stretch the action cell' );
		$this->assertSame( 1, $x->query( '//p[@class="description"][contains(., "free slots")]' )->length, 'the one note above the table keeps the sentence for no-JS users' );
		$this->assertStringContainsString( 'Bulk actions apply to every matching language of each selected component.', $html );

		babilim_test_reset();
		$this->seed_site( $this->sample_state(), $this->sample_inventory(), array(), 'paid' );
		$html = $this->page();
		$this->assertStringNotContainsString( 'data-babilim-confirm', $html );
		$this->assertStringNotContainsString( 'free slots', $html );
	}

	public function test_free_site_note_is_a_statement_without_the_confirmation_question(): void {
		$statement = 'Requesting a translation uses one of your free slots, unless this component already uses one. A failed translation does not give the slot back.';
		$this->seed_site( $this->sample_state(), $this->sample_inventory(), array(), 'free' );
		$x    = $this->xpath( $this->page() );
		$note = $x->query( '//p[@class="description"][contains(., "free slots")]' );
		$this->assertSame( 1, $note->length );
		$text = $note->item( 0 )->textContent;
		$this->assertStringContainsString( 'Bulk actions apply to every matching language of each selected component. ' . $statement, $text );
		$this->assertStringNotContainsString( 'Continue?', $text );
		$this->assertStringNotContainsString( 'If this component does not use', $text );

		babilim_test_reset();
		$this->seed_site( $this->sample_state(), $this->sample_inventory(), array(), 'paid' );
		$this->assertStringNotContainsString( $statement, $this->page() );
	}

	public function test_the_search_form_has_no_filter_button_and_keeps_the_language_as_a_hidden_field(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$html = $this->page( array( 'babilim_locale' => 'fr_FR' ) );
		$x    = $this->xpath( $html );
		$this->assertSame( 0, $x->query( '//input[@type="submit"][@value="Filter"]' )->length );
		$this->assertStringNotContainsString( 'babilim-filter-controls', $html );
		$this->assertSame( 'fr_FR', $this->attr( $x->query( '//form[@id="babilim-filter"]//input[@type="hidden"][@name="babilim_locale"]' )->item( 0 ), 'value' ), 'a search keeps the language' );
		$this->assertSame( 1, $x->query( '//form[@id="babilim-filter"]//input[@name="s"]' )->length );

		babilim_test_reset();
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x = $this->xpath( $this->page() );
		$this->assertSame( 0, $x->query( '//form[@id="babilim-filter"]//input[@type="hidden"][@name="babilim_locale"]' )->length, 'no language chosen: no hidden field' );
	}

	public function test_the_filter_controls_css_is_gone(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$this->assertStringNotContainsString( 'babilim-filter-controls', $this->page() );
	}

	public function test_requestable_newer_cell_gets_the_installed_component_version(): void {
		$entry = array( 'status' => 'babilim_installed', 'build_id' => 'bd_aaaaaaaaaaaa', 'built_for_version' => '8.0.0', 'newer_version' => array( 'version' => '9.0.0', 'status' => 'requestable' ) );
		$items = Babilim_Translations_Table::build_items(
			array( 'components' => array( self::component( 'woocommerce', array( 'de_DE' => $entry ) ) ), 'updates' => array() ),
			array( self::inventory( 'woocommerce', 'WooCommerce', '9.0.0' ) ),
			static function (): array {
				return array( 'build_id' => 'bd_aaaaaaaaaaaa' );
			},
			true
		);
		$this->assertSame( 'Installed · built for 8.0.0, this site has 9.0.0', $items[0]['cells']['de_DE']['text'] );
	}

	public function test_scope_note_changes_when_a_language_is_selected(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$html = $this->page( array( 'babilim_locale' => 'fr_FR' ) );
		$this->assertStringContainsString( 'only to the selected language', $html );
	}

	public function test_prepare_items_sets_the_column_headers_itself(): void {
		$table = new Babilim_Translations_Table( array(), array(), 'all', '', '' );
		$table->prepare_items();
		$property = new ReflectionProperty( WP_List_Table::class, '_column_headers' );
		$property->setAccessible( true );
		$headers = $property->getValue( $table );
		$this->assertSame( array( 'cb', 'component', 'translations' ), array_keys( $headers[0] ) );
		$this->assertSame( 'component', $headers[3], 'the primary column' );
	}

	public function test_page_size_is_fixed_at_fifty(): void {
		$this->assertSame( 50, Babilim_Translations_Table::PER_PAGE );
		$components = array();
		$inventory  = array();
		for ( $i = 1; $i <= 120; $i++ ) {
			$slug         = sprintf( 'p%03d', $i );
			$components[] = self::component( $slug, array( 'de_DE' => array( 'status' => 'requestable' ) ) );
			$inventory[]  = self::inventory( $slug, 'Plugin ' . $slug );
		}
		$this->seed_site( $components, $inventory );
		$this->assertSame( 50, $this->xpath( $this->page() )->query( '//table//tbody/tr' )->length );
		$this->assertSame( 50, $this->xpath( $this->page( array( 'paged' => '2' ) ) )->query( '//table//tbody/tr' )->length );
		$this->assertSame( 20, $this->xpath( $this->page( array( 'paged' => '3' ) ) )->query( '//table//tbody/tr' )->length );
	}

	// -- may_request / request form ---------------------------------------

	public function test_rendered_request_form_for_an_installed_cell_with_a_requestable_newer_version_submits_the_site_version(): void {
		$this->seed_site(
			array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_aaaaaaaaaaaa', 'newer_version' => array( 'version' => '3.5.0', 'status' => 'requestable' ) ) ) ) ),
			array( self::inventory( 'acme', 'Acme', '3.5.0' ) )
		);
		$fs = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set( 'plugin', 'acme', 'de_DE', array( 'build_id' => 'bd_aaaaaaaaaaaa' ) );
		$http = new Babilim_Fake_Http(
			array(
				array(
					'response' => array( 'code' => 202 ),
					'body'     => wp_json_encode( array( 'status' => 'queued' ) ),
					'headers'  => array(),
				),
			)
		);
		$admin = $this->admin( $http, $fs );
		$x     = $this->xpath( $this->page( array(), $admin ) );
		$form  = $x->query( '//form[starts-with(@id,"babilim-cell-")][.//input[@name="action"][@value="babilim_request"]]' );
		$this->assertSame( 1, $form->length );
		$fields = array();
		foreach ( $x->query( './/input', $form->item( 0 ) ) as $input ) {
			$fields[ $this->attr( $input, 'name' ) ] = $this->attr( $input, 'value' );
		}
		$_POST    = $fields;
		$_REQUEST = $fields;
		$admin->dispatch_babilim_request();

		$this->assertCount( 1, $http->requests );
		$this->assertSame( array( 'kind' => 'plugin', 'slug' => 'acme', 'version' => '3.5.0', 'locale' => 'de_DE', 'client_caps' => array( 'upload' ) ), json_decode( $http->requests[0]['args']['body'], true ) );
	}

	public function test_a_wporg_cell_may_be_requested_and_a_bundled_one_may_not(): void {
		update_option( 'babilim_consent', true );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.2.3', 'TextDomain' => 'acme' ) );
		( new Babilim_State() )->set( array( 'components' => array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'wporg' ), 'fr_FR' => array( 'status' => 'bundled' ) ) ) ) ) );
		$http = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array( 'status' => 'queued' ) ), 'headers' => array() ) ) );
		$admin = $this->admin( $http );

		$this->assertTrue( $admin->do_request( 'plugin', 'acme', '1.2.3', 'de_DE' )->is_ok() );
		$denied = $admin->do_request( 'plugin', 'acme', '1.2.3', 'fr_FR' );
		$this->assertFalse( $denied->is_ok() );
		$this->assertSame( 'unknown_locale', $denied->code() );
		$this->assertCount( 1, $http->requests );
	}

	// -- the bulk action parser ---------------------------------------

	/**
	 * @return array<string,array{0:array,1:?string}>
	 */
	public static function bulk_posts(): array {
		return array(
			'action download'              => array( array( 'action' => 'download' ), 'download' ),
			'top unset, bottom request'    => array( array( 'action' => '-1', 'action2' => 'request' ), 'request' ),
			'top empty, bottom update'     => array( array( 'action' => '', 'action2' => 'update' ), 'update' ),
			'top wins over bottom'         => array( array( 'action' => 'download', 'action2' => 'request' ), 'download' ),
			'unknown action'               => array( array( 'action' => 'evil' ), null ),
			'unknown bottom action'        => array( array( 'action' => '-1', 'action2' => 'evil' ), null ),
			'nothing'                      => array( array(), null ),
			'non-string'                   => array( array( 'action' => array( 'download' ) ), null ),
		);
	}

	/**
	 * @dataProvider bulk_posts
	 */
	public function test_bulk_action_from_post( array $post, ?string $expected ): void {
		$_POST = $post;
		$this->assertSame( $expected, Babilim_Admin::bulk_action_from_post() );
	}

	// -- hook wiring -------------------------------------------------------

	public function test_menu_registration_hooks_the_load_action_of_the_translations_page(): void {
		$admin = $this->admin();
		$admin->register_menu();
		$this->assertArrayHasKey( 'load-toplevel_page_babilim', $GLOBALS['babilim_test_actions'] );
	}

	// -- finish_component_action ------------------------------------------

	public function test_finish_component_action_returns_to_the_filtered_list(): void {
		update_option( 'babilim_consent', true );
		( new Babilim_State() )->set( array( 'components' => array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'requestable' ) ) ) ) ) );
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0.0', 'TextDomain' => 'acme' ) );
		$fields    = array( 'action' => 'babilim_request', '_wpnonce' => wp_create_nonce( 'babilim_request' ), 'kind' => 'plugin', 'slug' => 'acme', 'locale' => 'de_DE', 's' => 'ac me', 'babilim_locale' => 'de_DE' );
		$_POST     = $fields;
		$_REQUEST  = $fields;
		$http      = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array( 'status' => 'queued' ) ), 'headers' => array() ) ) );
		$this->admin( $http )->dispatch_babilim_request();

		$location = (string) end( $GLOBALS['babilim_test_redirects'] )['location'];
		$this->assertStringContainsString( 'page=babilim', $location );
		$this->assertStringContainsString( 's=ac%20me', $location );
		$this->assertStringContainsString( 'babilim_locale=de_DE', $location );
		$this->assertStringNotContainsString( 'screen=detail', $location );
		$this->assertStringNotContainsString( 'kind=', $location );
	}

	// -- hard-coded text warning -------------------------------------------

	private const HARDCODED_WARNING = 'Some of this component\'s text is hard-coded by its author and can\'t be translated by a language pack.';

	public function test_the_warning_is_a_second_line_under_each_flagged_cell_and_only_there(): void {
		$this->seed_site(
			array(
				self::component(
					'acme',
					array(
						'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_aaaaaaaaaaaa', 'hardcoded_text_warning' => true ),
						'fr_FR' => array( 'status' => 'babilim_installed', 'build_id' => 'bd_bbbbbbbbbbbb', 'hardcoded_text_warning' => true ),
						'es_ES' => array( 'status' => 'babilim_available', 'build_id' => 'bd_cccccccccccc' ),
						'it_IT' => array( 'status' => 'requestable', 'hardcoded_text_warning' => true ),
					)
				),
			),
			array( self::inventory( 'acme', 'Acme' ) )
		);
		$fs = new Babilim_Memory_Fs();
		( new Babilim_Manifest( $fs ) )->set( 'plugin', 'acme', 'fr_FR', array( 'build_id' => 'bd_bbbbbbbbbbbb' ) );
		$html = $this->page( array(), $this->admin( null, $fs ) );
		$x    = $this->xpath( $html );

		$warnings = $x->query( '//*[contains(@class,"babilim-cell-warning")]' );
		$this->assertSame( 2, $warnings->length );
		foreach ( $warnings as $node ) {
			$this->assertSame( self::HARDCODED_WARNING, trim( $node->textContent ) );
		}
		// Each sits inside the line of a flagged locale, after its button.
		$this->assertSame( 1, $x->query( '//div[contains(@class,"babilim-cell-row")][starts-with(normalize-space(.),"de_DE")][.//*[contains(@class,"babilim-cell-warning")]]' )->length );
		$this->assertSame( 1, $x->query( '//div[contains(@class,"babilim-cell-row")][starts-with(normalize-space(.),"fr_FR")][.//*[contains(@class,"babilim-cell-warning")]]' )->length );
		$this->assertSame( 0, $x->query( '//div[contains(@class,"babilim-cell-row")][starts-with(normalize-space(.),"es_ES")][.//*[contains(@class,"babilim-cell-warning")]]' )->length );
		$this->assertSame( 0, $x->query( '//div[contains(@class,"babilim-cell-row")][starts-with(normalize-space(.),"it_IT")][.//*[contains(@class,"babilim-cell-warning")]]' )->length );
	}

	public function test_the_warning_does_not_remove_the_download_button(): void {
		$this->seed_site(
			array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_aaaaaaaaaaaa', 'hardcoded_text_warning' => true ) ) ) ),
			array( self::inventory( 'acme', 'Acme' ) )
		);
		$x = $this->xpath( $this->page() );
		$this->assertSame( 1, $x->query( '//form[starts-with(@id,"babilim-cell-")][.//input[@name="action"][@value="babilim_download"]]' )->length );
	}

	// -- three-cell lines ---------------------------------------------------

	/** @return DOMNodeList<DOMNode> */
	private function lines( DOMXPath $x ): DOMNodeList {
		return $x->query( '//*[contains(@class,"babilim-cell-line")]' );
	}

	public function test_every_line_has_the_same_three_cells_and_the_action_cell_holds_the_button(): void {
		set_site_transient( 'available_translations', array( 'de_DE' => array( 'native_name' => 'Deutsch' ), 'fr_FR' => array( 'native_name' => 'Français' ) ) );
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x     = $this->xpath( $this->page() );
		$lines = $this->lines( $x );
		$this->assertSame( 4, $lines->length, 'woo de+fr, acme de, zeta de' );
		foreach ( $lines as $line ) {
			// The name and the status share one block, so a cell too narrow for three columns can put them on top of each other
			// left of the button.
			$this->assertSame( 1, $x->query( './span[contains(@class,"babilim-cell-text")]/span[contains(@class,"babilim-cell-lang")]', $line )->length );
			$this->assertSame( 1, $x->query( './span[contains(@class,"babilim-cell-text")]/span[contains(@class,"babilim-cell-status")]', $line )->length );
			$this->assertSame( 2, $x->query( './span[contains(@class,"babilim-cell-text")]/span', $line )->length );
			$this->assertSame( 1, $x->query( './span[contains(@class,"babilim-cell-action")]', $line )->length );
			$this->assertSame( 2, $x->query( './span', $line )->length );
			$this->assertSame( 0, $x->query( './/button[@disabled]', $line )->length );
		}
		$lang = $x->query( '//span[contains(@class,"babilim-cell-lang")][@title="de_DE"]' );
		$this->assertGreaterThan( 0, $lang->length );
		$this->assertSame( 'Deutsch', trim( $lang->item( 0 )->textContent ) );
		$this->assertSame( 1, $x->query( '//span[contains(@class,"babilim-cell-action")]/button[@form][contains(@class,"button")][normalize-space()="Download"]' )->length );
	}

	public function test_a_cell_that_cannot_run_leaves_the_action_cell_empty(): void {
		$this->seed_site( array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'queued' ) ) ) ), array( self::inventory( 'acme', 'Acme' ) ) );
		$x = $this->xpath( $this->page() );
		$this->assertSame( 0, $x->query( '//table//tbody//button' )->length );
		$this->assertSame( 0, $x->query( '//button[@disabled]' )->length );
		$this->assertSame( '', trim( $x->query( '//span[contains(@class,"babilim-cell-action")]' )->item( 0 )->textContent ) );
	}

	public function test_a_license_blocked_cell_keeps_the_upgrade_link_in_the_action_cell_and_no_disabled_button(): void {
		$this->seed_site( array( self::component( 'acme', array( 'de_DE' => array( 'status' => 'license_required' ) ) ) ), array( self::inventory( 'acme', 'Acme' ) ), array( 'price' => array( 'amount' => '8.99', 'currency' => 'USD' ) ), 'free' );
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => str_repeat( 'a', 40 ) ), false );
		$x = $this->xpath( $this->page() );
		$this->assertSame( 0, $x->query( '//button[@disabled]' )->length );
		$link = $x->query( '//span[contains(@class,"babilim-cell-action")]/button[@form="babilim-checkout-form"][normalize-space()="Upgrade this site"]' );
		$this->assertSame( 1, $link->length );
	}

	public function test_the_language_links_show_names_and_keep_the_code_as_title_and_parameter(): void {
		set_site_transient( 'available_translations', array( 'de_DE' => array( 'native_name' => 'Deutsch' ) ) );
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x  = $this->xpath( $this->page() );
		$de = $x->query( '//p[contains(@class,"babilim-locale-links")]//a[@title="de_DE"]' )->item( 0 );
		$fr = $x->query( '//p[contains(@class,"babilim-locale-links")]//a[@title="fr_FR"]' )->item( 0 );
		$this->assertStringStartsWith( 'Deutsch', trim( $de->textContent ) );
		$this->assertStringContainsString( 'babilim_locale=de_DE', $this->attr( $de, 'href' ) );
		$this->assertStringStartsWith( 'fr_FR', trim( $fr->textContent ), 'unknown names fall back to the code' );
	}

	public function test_real_kses_keeps_the_new_title_attributes(): void {
		set_site_transient( 'available_translations', array( 'de_DE' => array( 'native_name' => 'Deutsch' ) ) );
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$filtered = babilim_real_wp_kses( $this->page(), Babilim_Admin::allowed_admin_html() );
		$x        = $this->xpath( $filtered );
		$this->assertGreaterThan( 0, $x->query( '//span[contains(@class,"babilim-cell-lang")][@title]' )->length );
		$this->assertGreaterThan( 0, $x->query( '//p[contains(@class,"babilim-locale-links")]//a[@title]' )->length );
		$this->assertGreaterThan( 0, $x->query( '//p[contains(@class,"babilim-locale-links")]//strong[@aria-current]' )->length, 'kses keeps strong and aria-current' );
		$this->assertGreaterThan( 0, $x->query( '//p[contains(@class,"babilim-locale-links")]//span[@class="count"]' )->length );
	}

	// -- plugin / theme tabs and the title-bar buttons ----------------------

	private static function theme_double( string $name, string $version ): object {
		return new class( $name, $version ) {
			/** @var string */
			private $name;
			/** @var string */
			private $version;
			public function __construct( string $name, string $version ) {
				$this->name    = $name;
				$this->version = $version;
			}
			public function get( $key ) {
				return array( 'Name' => $this->name, 'Version' => $this->version )[ $key ] ?? false;
			}
			public function get_stylesheet_directory() {
				return '/fake-wp-content/themes/' . strtolower( $this->name );
			}
		};
	}

	/** Two plugins and two themes, in two languages. */
	private function seed_mixed(): void {
		$this->seed_site(
			array(
				self::component( 'acme', array( 'de_DE' => array( 'status' => 'wporg' ) ) ),
				self::component( 'woo', array( 'de_DE' => array( 'status' => 'babilim_available', 'build_id' => 'bd_aaaaaaaaaaaa' ), 'fr_FR' => array( 'status' => 'requestable' ) ) ),
				self::component( 'astra', array( 'de_DE' => array( 'status' => 'requestable' ) ), 'theme' ),
				self::component( 'blocksy', array( 'fr_FR' => array( 'status' => 'babilim_available', 'build_id' => 'bd_bbbbbbbbbbbb' ) ), 'theme' ),
			),
			array( self::inventory( 'acme', 'Acme', '2.0.0' ), self::inventory( 'woo', 'Woo', '9.0.0' ) )
		);
		update_option( 'babilim_identity', array( 'site_id' => 'st_test', 'token' => 'tok_test' ), false );
		$GLOBALS['babilim_test_wp_get_themes_return'] = array(
			'astra'   => self::theme_double( 'Astra', '4.1.0' ),
			'blocksy' => self::theme_double( 'Blocksy', '2.0.5' ),
		);
	}

	/** @return string[] the row names of the list, in order. */
	private function row_names( DOMXPath $x ): array {
		$names = array();
		foreach ( $x->query( '//table//tbody/tr/td[contains(@class,"column-component")]/strong' ) as $node ) {
			$names[] = trim( $node->textContent );
		}
		return $names;
	}

	/** @return string[] label (with count) => href of the three tabs. */
	private function tabs( DOMXPath $x ): array {
		$tabs = array();
		foreach ( $x->query( '//*[contains(@class,"nav-tab-wrapper")]/a[contains(@class,"nav-tab")]' ) as $a ) {
			$tabs[ preg_replace( '/\s+/', ' ', trim( $a->textContent ) ) ] = $this->attr( $a, 'href' );
		}
		return $tabs;
	}

	public function test_the_kind_tabs_show_all_plugins_and_themes_with_counts_and_mark_the_current_one(): void {
		$this->seed_mixed();
		$x = $this->xpath( $this->page() );
		$this->assertSame( array( 'All (4)', 'Plugins (2)', 'Themes (2)' ), array_keys( $this->tabs( $x ) ) );
		$this->assertSame( array( 'All (4)' ), array_map( static fn( $n ) => preg_replace( '/\s+/', ' ', trim( $n->textContent ) ), iterator_to_array( $x->query( '//a[contains(@class,"nav-tab-active")]' ) ) ) );
		$this->assertSame( 'page', $this->attr( $x->query( '//a[contains(@class,"nav-tab-active")]' )->item( 0 ), 'aria-current' ) );

		$x = $this->xpath( $this->page( array( 'babilim_kind' => 'theme' ) ) );
		$this->assertSame( 'Themes (2)', preg_replace( '/\s+/', ' ', trim( $x->query( '//a[contains(@class,"nav-tab-active")]' )->item( 0 )->textContent ) ) );
	}

	public function test_a_kind_tab_lists_only_that_kind_and_an_unknown_kind_means_all(): void {
		$this->seed_mixed();
		$this->assertSame( array( 'Acme', 'Astra', 'Blocksy', 'Woo' ), $this->row_names( $this->xpath( $this->page() ) ) );
		$this->assertSame( array( 'Acme', 'Woo' ), $this->row_names( $this->xpath( $this->page( array( 'babilim_kind' => 'plugin' ) ) ) ) );
		$this->assertSame( array( 'Astra', 'Blocksy' ), $this->row_names( $this->xpath( $this->page( array( 'babilim_kind' => 'theme' ) ) ) ) );
		$this->assertSame( 4, count( $this->row_names( $this->xpath( $this->page( array( 'babilim_kind' => 'bogus' ) ) ) ) ) );
	}

	public function test_tab_counts_follow_the_search(): void {
		$this->seed_mixed();
		$this->assertSame( array( 'All (1)', 'Plugins (0)', 'Themes (1)' ), array_keys( $this->tabs( $this->xpath( $this->page( array( 's' => 'ast' ) ) ) ) ) );
	}

	public function test_tab_counts_with_the_language_filter(): void {
		$this->seed_mixed();
		// fr_FR: woo, blocksy. de_DE: acme, woo, astra.
		$this->assertSame( array( 'All (2)', 'Plugins (1)', 'Themes (1)' ), array_keys( $this->tabs( $this->xpath( $this->page( array( 'babilim_locale' => 'fr_FR' ) ) ) ) ) );
		$this->assertSame( array( 'All (3)', 'Plugins (2)', 'Themes (1)' ), array_keys( $this->tabs( $this->xpath( $this->page( array( 'babilim_locale' => 'de_DE' ) ) ) ) ) );
	}

	public function test_view_counts_are_taken_inside_the_current_tab(): void {
		$this->seed_mixed();
		$counts = static function ( DOMXPath $x ): array {
			$out = array();
			foreach ( $x->query( '//ul[contains(@class,"subsubsub")]/li' ) as $li ) {
				if ( $li instanceof DOMElement ) {
					$out[ $li->getAttribute( 'class' ) ] = (int) trim( $li->getElementsByTagName( 'span' )->item( 0 )->textContent ?? '', '()' );
				}
			}
			return $out;
		};
		$all = $counts( $this->xpath( $this->page() ) );
		$this->assertSame( 4, $all['all'] );
		$this->assertSame( 2, $all['available'], 'woo and blocksy' );
		$this->assertSame( 3, $all['requestable'], 'acme (a wp.org cell may be requested), woo and astra' );
		$theme = $counts( $this->xpath( $this->page( array( 'babilim_kind' => 'theme' ) ) ) );
		$this->assertSame( 2, $theme['all'] );
		$this->assertSame( 1, $theme['available'], 'blocksy only' );
		$this->assertSame( 1, $theme['requestable'], 'astra only' );
		$plugin = $counts( $this->xpath( $this->page( array( 'babilim_kind' => 'plugin' ) ) ) );
		$this->assertSame( 1, $plugin['available'] );
		$this->assertSame( 2, $plugin['requestable'], 'acme and woo' );
	}

	public function test_the_kind_is_kept_by_the_view_links_the_search_form_the_bulk_form_and_the_cell_forms(): void {
		$this->seed_mixed();
		$x = $this->xpath( $this->page( array( 'babilim_kind' => 'theme', 'view' => 'requestable', 's' => 'ast', 'babilim_locale' => 'de_DE' ) ) );

		foreach ( $x->query( '//ul[contains(@class,"subsubsub")]//a' ) as $a ) {
			$this->assertStringContainsString( 'babilim_kind=theme', $this->attr( $a, 'href' ) );
		}
		$this->assertSame( 'theme', $this->attr( $x->query( '//form[@id="babilim-filter"]//input[@type="hidden"][@name="babilim_kind"]' )->item( 0 ), 'value' ) );
		$this->assertSame( 'requestable', $this->attr( $x->query( '//form[@id="babilim-filter"]//input[@type="hidden"][@name="view"]' )->item( 0 ), 'value' ) );
		$this->assertSame( 'theme', $this->attr( $x->query( '//form[@id="babilim-translations"]//input[@type="hidden"][@name="babilim_kind"]' )->item( 0 ), 'value' ) );
		$cell_forms = $x->query( '//form[starts-with(@id,"babilim-cell-")]' );
		$this->assertGreaterThan( 0, $cell_forms->length );
		foreach ( $cell_forms as $form ) {
			$this->assertSame( 'theme', $this->attr( $x->query( './/input[@name="babilim_kind"]', $form )->item( 0 ), 'value' ) );
		}

		// The tabs keep view, language and search; only the kind changes.
		$tabs = $this->tabs( $x );
		foreach ( $tabs as $href ) {
			$this->assertStringContainsString( 'view=requestable', $href );
			$this->assertStringContainsString( 'babilim_locale=de_DE', $href );
			$this->assertStringContainsString( 's=ast', $href );
		}
		$this->assertStringNotContainsString( 'babilim_kind', $tabs['All (1)'] );
		$this->assertStringContainsString( 'babilim_kind=plugin', $tabs['Plugins (0)'] );
		$this->assertStringContainsString( 'babilim_kind=theme', $tabs['Themes (1)'] );
	}

	public function test_the_second_line_of_the_component_cell_is_only_the_version_inside_a_kind_tab(): void {
		$this->seed_mixed();
		$cell = static function ( DOMXPath $x, string $name ): string {
			return preg_replace( '/\s+/', ' ', trim( $x->query( '//td[contains(@class,"column-component")][strong[normalize-space()="' . $name . '"]]/span[contains(@class,"description")]' )->item( 0 )->textContent ) );
		};
		$all = $this->xpath( $this->page() );
		$this->assertSame( 'Theme · 4.1.0', $cell( $all, 'Astra' ) );
		$this->assertSame( 'Plugin · 9.0.0', $cell( $all, 'Woo' ) );
		$this->assertSame( 'Version 4.1.0', $cell( $this->xpath( $this->page( array( 'babilim_kind' => 'theme' ) ) ), 'Astra' ) );
		$this->assertSame( 'Version 9.0.0', $cell( $this->xpath( $this->page( array( 'babilim_kind' => 'plugin' ) ) ), 'Woo' ) );
	}

	public function test_a_bulk_run_only_touches_the_rows_of_the_current_tab(): void {
		$this->seed_mixed();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$fields   = array( '_wpnonce' => wp_create_nonce( 'bulk-translations' ), 'action' => 'request', 'components' => array( 'plugin:woo', 'theme:astra' ) );
		$_POST    = $fields;
		$_GET     = array( 'page' => 'babilim', 'babilim_kind' => 'theme' );
		$_REQUEST = array_merge( $_GET, $_POST );
		$http     = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array( 'status' => 'queued' ) ), 'headers' => array() ) ) );
		$admin    = $this->admin( $http );
		$admin->on_translations_load();
		$html = $admin->render_current_page();
		unset( $_SERVER['REQUEST_METHOD'] );

		$this->assertCount( 1, $http->requests, 'woo is a plugin: not in the theme tab, so not requested' );
		$this->assertSame( 'astra', json_decode( $http->requests[0]['args']['body'], true )['slug'] );
		$this->assertStringContainsString( '1 succeeded', $html );
	}

	public function test_an_action_from_a_tab_returns_to_that_tab(): void {
		$this->seed_mixed();
		$fields   = array( 'action' => 'babilim_request', '_wpnonce' => wp_create_nonce( 'babilim_request' ), 'kind' => 'theme', 'slug' => 'astra', 'locale' => 'de_DE', 's' => '', 'babilim_locale' => '', 'babilim_kind' => 'theme' );
		$_POST    = $fields;
		$_REQUEST = $fields;
		$http     = new Babilim_Fake_Http( array( array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array( 'status' => 'queued' ) ), 'headers' => array() ) ) );
		$this->admin( $http )->dispatch_babilim_request();
		$this->assertStringContainsString( 'babilim_kind=theme', (string) end( $GLOBALS['babilim_test_redirects'] )['location'] );
	}

	public function test_the_confirm_step_keeps_the_tab(): void {
		$this->seed_mixed();
		$fields   = array( 'action' => 'babilim_remove', '_wpnonce' => wp_create_nonce( 'babilim_remove' ), 'kind' => 'theme', 'slug' => 'astra', 'locale' => 'de_DE', 'babilim_kind' => 'theme' );
		$_POST    = $fields;
		$_REQUEST = $fields;
		$this->admin()->dispatch_babilim_remove();
		$location = (string) end( $GLOBALS['babilim_test_redirects'] )['location'];
		$this->assertStringContainsString( 'screen=confirm', $location );
		$this->assertStringContainsString( 'babilim_kind=theme', $location );

		$x = $this->xpath( $this->page( array( 'screen' => 'confirm', 'op' => 'remove', 'kind' => 'theme', 'slug' => 'astra', 'locale' => 'de_DE', 'babilim_kind' => 'theme' ) ) );
		$this->assertSame( 'theme', $this->attr( $x->query( '//form//input[@name="babilim_kind"]' )->item( 0 ), 'value' ) );
		$this->assertStringContainsString( 'babilim_kind=theme', $this->attr( $x->query( '//a[normalize-space()="Cancel"]' )->item( 0 ), 'href' ) );
	}

	public function test_the_title_bar_holds_settings_and_check_again_now_as_page_title_actions(): void {
		$this->seed_mixed();
		$x = $this->xpath( $this->page() );

		$h1 = $x->query( '//h1' )->item( 0 );
		$this->assertStringContainsString( 'wp-heading-inline', $this->attr( $h1, 'class' ) );
		$this->assertSame( 1, $x->query( '//hr[contains(@class,"wp-header-end")]' )->length );

		$settings = $x->query( '//h1/following-sibling::a[contains(@class,"page-title-action")][normalize-space()="Settings"]' );
		$this->assertSame( 1, $settings->length );
		$this->assertStringContainsString( 'page=babilim-settings', $this->attr( $settings->item( 0 ), 'href' ) );

		$form = $x->query( '//h1/following-sibling::form[.//input[@name="action"][@value="babilim_sync_now"]]' );
		$this->assertSame( 1, $form->length );
		$this->assertSame( 'post', strtolower( $this->attr( $form->item( 0 ), 'method' ) ) );
		$this->assertSame( 'admin-post.php', basename( $this->attr( $form->item( 0 ), 'action' ) ) );
		$this->assertSame( wp_create_nonce( 'babilim_sync_now' ), $this->attr( $x->query( './/input[@name="_wpnonce"]', $form->item( 0 ) )->item( 0 ), 'value' ) );
		$this->assertSame( 1, $x->query( './/button[@type="submit"][contains(@class,"page-title-action")][normalize-space()="Check again now"]', $form->item( 0 ) )->length );

		$this->assertSame( 0, $x->query( '//p[.//button[normalize-space()="Check again now"]]' )->length, 'the separate line of buttons is gone' );
		$this->assertSame( 0, $x->query( '//p[not(contains(@class,"description"))][.//a[normalize-space()="Settings"]]' )->length );
	}

	public function test_real_kses_keeps_the_tabs_and_the_title_bar(): void {
		$this->seed_mixed();
		$x = $this->xpath( babilim_real_wp_kses( $this->page( array( 'babilim_kind' => 'theme' ) ), Babilim_Admin::allowed_admin_html() ) );
		$this->assertSame( 1, $x->query( '//hr[contains(@class,"wp-header-end")]' )->length );
		$this->assertSame( 1, $x->query( '//h1[contains(@class,"wp-heading-inline")]' )->length );
		$this->assertSame( 1, $x->query( '//a[contains(@class,"page-title-action")]' )->length );
		$this->assertSame( 1, $x->query( '//form//button[contains(@class,"page-title-action")]' )->length );
		$this->assertSame( 3, count( $this->tabs( $x ) ) );
		$this->assertSame( 1, $x->query( '//a[contains(@class,"nav-tab-active")][@aria-current="page"]' )->length );
		$this->assertSame( 'theme', $this->attr( $x->query( '//form[@id="babilim-filter"]//input[@name="babilim_kind"]' )->item( 0 ), 'value' ) );
	}

	public function test_the_cell_line_grid_sizes_the_first_column_to_its_content_and_stacks_when_narrow(): void {
		$admin = $this->admin();
		$admin->register_menu();
		$admin->enqueue_admin_assets( 'toplevel_page_' . Babilim_Admin::MENU_SLUG );
		$css = implode( '', $GLOBALS['babilim_test_inline_styles']['babilim-admin'] );

		$this->assertMatchesRegularExpression( '/\.babilim-cell-line\{[^}]*grid-template-columns:minmax\(min-content,max-content\) minmax\(10em,1fr\) max-content;/', $css, 'a long language name widens the first column instead of running over the status, the status keeps a floor, and the action column is as wide as its button' );
		$this->assertMatchesRegularExpression( '/\.babilim-cell-list\{container-type:inline-size/', $css, 'the layout follows the width of the cell itself' );
		$this->assertMatchesRegularExpression( '/\.babilim-cell-text\{display:contents\}/', $css, 'side by side, the name and the status are two columns of the grid' );
		$this->assertMatchesRegularExpression( '/@container \(max-width:40em\)\{\.babilim-cell-grid,\.babilim-cell-row\{display:block\}\.babilim-cell-line\{display:flex;flex-wrap:wrap;[^}]*\}\.babilim-cell-text\{display:block;flex:1 1 10em\}[^@]*\.babilim-cell-action\{flex:none;margin-left:auto\}/', $css, 'a cell too narrow for three columns keeps the button at the right of the name and status, and wraps it only when even that does not fit' );
		$this->assertMatchesRegularExpression( '/@media \(max-width:782px\)\{\.babilim-cell-grid,\.babilim-cell-row\{display:block\}\.babilim-cell-line\{display:grid;grid-template-columns:minmax\(0,1fr\)[;}][^@]*\.babilim-cell-text\{display:contents\}[^@]*\.babilim-cell-action\{text-align:left;margin-left:0;width:max-content\}/', $css, 'below the WordPress breakpoint the line stacks' );
	}

	public function test_the_language_name_wraps_only_at_spaces_and_has_no_break_anywhere_rule(): void {
		$admin = $this->admin();
		$admin->register_menu();
		$admin->enqueue_admin_assets( 'toplevel_page_' . Babilim_Admin::MENU_SLUG );
		$css = implode( '', $GLOBALS['babilim_test_inline_styles']['babilim-admin'] );
		$this->assertMatchesRegularExpression( '/\.babilim-cell-lang\{[^}]*white-space:normal/', $css, 'a long name wraps at its spaces' );
		$this->assertDoesNotMatchRegularExpression( '/\.babilim-cell-lang\{[^}]*white-space:nowrap/', $css );
		$this->assertDoesNotMatchRegularExpression( '/babilim-cell-lang[^{]*\{[^}]*(overflow-wrap|word-break)/', $css, 'never broken inside a word' );
	}

	public function test_a_dated_status_keeps_the_separator_and_the_date_together(): void {
		$this->seed_site( $this->sample_state(), $this->sample_inventory() );
		$x      = $this->xpath( $this->page() );
		$status = $x->query( '//span[contains(@class,"babilim-cell-status")][starts-with(normalize-space(.),"Available")]' );
		$this->assertGreaterThan( 0, $status->length );
		$tail = $x->query( './span[contains(@class,"babilim-cell-tail")]', $status->item( 0 ) );
		$this->assertSame( 1, $tail->length );
		$this->assertSame( "·\u{00A0}September 24, 2026", $tail->item( 0 )->textContent, 'the separator leads the date' );
		$this->assertSame( 'Available · September 24, 2026', preg_replace( '/[\s\x{00A0}]+/u', ' ', trim( $status->item( 0 )->textContent ) ) );
		$this->assertStringNotContainsString( '·', (string) $status->item( 0 )->firstChild->textContent, 'nothing is left behind the separator' );
		$m = new ReflectionMethod( Babilim_Admin::class, 'admin_css' );
		$m->setAccessible( true );
		$this->assertMatchesRegularExpression( '/\.babilim-cell-tail\{[^}]*display:inline-block;max-width:100%/', (string) $m->invoke( null ) );
	}

	/**
	 * The lines of one cell share one set of columns, so every status
	 * starts at the same x whatever the language names are.
	 */
	public function test_the_lines_of_a_cell_share_one_grid_so_statuses_start_at_the_same_x(): void {
		$admin = $this->admin();
		$admin->register_menu();
		$admin->enqueue_admin_assets( 'toplevel_page_' . Babilim_Admin::MENU_SLUG );
		$css = implode( '', $GLOBALS['babilim_test_inline_styles']['babilim-admin'] );

		$this->assertMatchesRegularExpression( '/@supports \(grid-template-columns:subgrid\)\{\.babilim-cell-grid\{display:grid;grid-template-columns:minmax\(min-content,max-content\) minmax\(10em,1fr\) max-content;/', $css );
		$this->assertMatchesRegularExpression( '/\.babilim-cell-row,\.babilim-cell-line\{display:grid;grid-template-columns:subgrid\}/', $css, 'rows and lines take the list columns' );
		$this->assertMatchesRegularExpression( '/\.babilim-cell-row,\.babilim-cell-line,\.babilim-cell-warning\{grid-column:1\/-1\}/', $css );

		$item = array(
			'kind'      => 'plugin',
			'slug'      => 'acme',
			'name'      => 'Acme',
			'cells'     => array(
				'de_DE' => Babilim_Cell::from_entry( array( 'status' => 'requestable' ), 'de_DE', false, true, null ),
				'fr_FR' => Babilim_Cell::from_entry( array( 'status' => 'requestable' ), 'fr_FR', false, true, null ),
			),
		);
		$m = new ReflectionMethod( Babilim_Translations_Table::class, 'column_translations' );
		$m->setAccessible( true );
		$t    = new Babilim_Translations_Table( array(), array(), 'all', '', '', false, array(), false, '' );
		$html = (string) $m->invoke( $t, $item );
		$x    = $this->xpath( $html );
		$this->assertSame( 1, $x->query( '/html/body/div[contains(@class,"babilim-cell-list")]/div[contains(@class,"babilim-cell-grid")]' )->length, 'one container and one grid per cell' );
		$this->assertSame( 2, $x->query( '//div[contains(@class,"babilim-cell-grid")]/div[contains(@class,"babilim-cell-row")]/div[contains(@class,"babilim-cell-line")]' )->length );
	}

	/**
	 * Pressing a button swaps its label for the busy one and adds a
	 * spinner; neither may change the width of the action column.
	 */
	public function test_the_busy_label_and_spinner_do_not_change_the_action_column_width(): void {
		$admin = $this->admin();
		$admin->register_menu();
		$admin->enqueue_admin_assets( 'toplevel_page_' . Babilim_Admin::MENU_SLUG );
		$css = implode( '', $GLOBALS['babilim_test_inline_styles']['babilim-admin'] );

		$this->assertStringContainsString( '.babilim-cell-action button[data-babilim-busy-label]::after{content:attr(data-babilim-busy-label);display:block;height:0;overflow:hidden;visibility:hidden;padding-left:22px}', $css, 'the busy label and the spinner are reserved from the start' );
		$this->assertStringContainsString( '.babilim-cell-action button[data-babilim-busy-label]{min-width:100%}', $css, 'every button of the cell fills the column' );
		$this->assertMatchesRegularExpression( '/\.babilim-cell-action \.spinner\{position:absolute;left:\d+px;[^}]*float:none/', $css, 'the spinner sits inside the action column, never over the status column' );
		$this->assertStringNotContainsString( 'right:100%', $css );
		$this->assertStringContainsString( '.babilim-cell-action{position:relative;', $css );
	}

	public function test_the_css_has_no_rule_for_a_description_inside_the_action_cell(): void {
		$admin = $this->admin();
		$admin->register_menu();
		$admin->enqueue_admin_assets( 'toplevel_page_' . Babilim_Admin::MENU_SLUG );
		$css = implode( '', $GLOBALS['babilim_test_inline_styles']['babilim-admin'] );

		$this->assertStringNotContainsString( '.babilim-cell-action .description', $css );
	}

	public function test_status_html_never_adds_whitespace_the_translation_did_not_have(): void {
		$m = new ReflectionMethod( Babilim_Translations_Table::class, 'status_html' );
		$m->setAccessible( true );

		$this->assertSame( 'Available <span class="babilim-cell-tail">·&nbsp;1 May</span>', str_replace( "\u{00A0}", '&nbsp;', (string) $m->invoke( null, 'Available · 1 May', '1 May' ) ) );
		$this->assertSame( 'X<span class="babilim-cell-tail">•1 May</span>', $m->invoke( null, 'X•1 May', '1 May' ), 'no space in the translation, none in the output' );
		$this->assertSame( 'X <span class="babilim-cell-tail">•1 May</span>', $m->invoke( null, 'X •1 May', '1 May' ), 'space before only' );
		$this->assertSame( 'Plain 1 May', $m->invoke( null, 'Plain 1 May', '1 May' ), 'no separator: plain text' );
		$this->assertSame( 'A &lt;b&gt; <span class="babilim-cell-tail">·1 May</span>', $m->invoke( null, 'A <b> ·1 May', '1 May' ), 'escaped' );
	}

	/**
	 * A combining mark (Hindi vowel sign, Thai tone mark) at the end of the
	 * head is part of a letter, not a separator: the text stays whole.
	 */
	public function test_status_html_does_not_treat_a_combining_mark_as_a_separator(): void {
		$m = new ReflectionMethod( Babilim_Translations_Table::class, 'status_html' );
		$m->setAccessible( true );

		$this->assertSame( 'स्थापित किया गया 24 सितंबर', $m->invoke( null, 'स्थापित किया गया 24 सितंबर', '24 सितंबर' ), 'Hindi, ends in U+093E' );
		$this->assertSame( 'ติดตั้งแล้ว 24 กันยายน', $m->invoke( null, 'ติดตั้งแล้ว 24 กันยายน', '24 กันยายน' ), 'Thai' );
		$thai_tone = "\u{0E43}\u{0E2B}\u{0E21}\u{0E48}"; // Ends in a tone mark (U+0E48).
		$this->assertSame( $thai_tone . ' 1 May', $m->invoke( null, $thai_tone . ' 1 May', '1 May' ) );
		$this->assertStringNotContainsString( 'babilim-cell-tail', (string) $m->invoke( null, "गया 1 May", '1 May' ) );
		// A real separator is still split off.
		$this->assertStringContainsString( 'babilim-cell-tail', (string) $m->invoke( null, 'गया · 1 May', '1 May' ) );
	}

	private function no_items_html( string $view, string $locale, string $search, string $kind ): string {
		$t = new Babilim_Translations_Table( array(), array(), $view, $locale, $search, false, array(), false, $kind );
		ob_start();
		$t->no_items();
		return (string) ob_get_clean();
	}

	public function test_no_items_without_filters_says_nothing_found(): void {
		$html = $this->no_items_html( 'all', '', '', '' );
		$this->assertStringContainsString( 'No plugins or themes found.', $html );
		$this->assertStringNotContainsString( 'Clear filters', $html );
	}

	public function test_no_items_with_a_filter_offers_clear_filters_that_keeps_only_the_tab(): void {
		foreach ( array( array( 'all', '', 'woo' ), array( 'all', 'fr_FR', '' ), array( 'updates', '', '' ) ) as $f ) {
			$html = $this->no_items_html( $f[0], $f[1], $f[2], 'theme' );
			$this->assertStringContainsString( 'No components match these filters.', $html );
			$a = $this->xpath( $html )->query( '//a[normalize-space(.)="Clear filters"]' );
			$this->assertSame( 1, $a->length );
			$href = html_entity_decode( $this->attr( $a->item( 0 ), 'href' ) );
			$this->assertStringContainsString( 'page=babilim', $href );
			$this->assertStringContainsString( 'babilim_kind=theme', $href );
			$this->assertDoesNotMatchRegularExpression( '/[?&](s|view|babilim_locale)=/', $href );
		}
		$a    = $this->xpath( $this->no_items_html( 'all', '', 'woo', '' ) )->query( '//a' );
		$href = html_entity_decode( $this->attr( $a->item( 0 ), 'href' ) );
		$this->assertStringNotContainsString( 'babilim_kind', $href );
	}

	public function test_the_language_links_list_every_language_in_every_tab_and_keep_the_selection(): void {
		$this->seed_site(
			array(
				self::component( 'woo', array( 'de_DE' => array( 'status' => 'requestable' ), 'fr_FR' => array( 'status' => 'requestable' ) ) ),
				self::component( 'astra', array( 'de_DE' => array( 'status' => 'requestable' ) ), 'theme' ),
			),
			array( self::inventory( 'woo', 'Woo', '9.0.0' ) )
		);
		$GLOBALS['babilim_test_wp_get_themes_return'] = array( 'astra' => self::theme_double( 'Astra', '4.1.0' ) );

		$x      = $this->xpath( $this->page( array( 'babilim_kind' => 'theme', 'babilim_locale' => 'fr_FR' ) ) );
		$row    = $x->query( '//p[contains(@class,"babilim-locale-links")]' );
		$this->assertSame( 1, $row->length, 'the theme tab has one language, but the links must stay visible' );
		$this->assertSame( 1, $x->query( './/a[@title="de_DE"]', $row->item( 0 ) )->length );
		$this->assertSame( 1, $x->query( './/strong[@title="fr_FR"][@aria-current]', $row->item( 0 ) )->length );
	}

	public function test_view_counts_follow_the_search_and_the_language_inside_the_tab(): void {
		$this->seed_mixed();
		$counts = static function ( DOMXPath $x ): array {
			$out = array();
			foreach ( $x->query( '//ul[contains(@class,"subsubsub")]/li' ) as $li ) {
				if ( $li instanceof DOMElement ) {
					$out[ $li->getAttribute( 'class' ) ] = (int) trim( $li->getElementsByTagName( 'span' )->item( 0 )->textContent ?? '', '()' );
				}
			}
			return $out;
		};
		$by_search = $counts( $this->xpath( $this->page( array( 'babilim_kind' => 'theme', 's' => 'ast' ) ) ) );
		$this->assertSame( 1, $by_search['all'], 'matches the Themes tab count for the same search' );
		$this->assertSame( 0, $by_search['available'], 'blocksy is out of the search' );
		$this->assertSame( 1, $by_search['requestable'] );

		// fr_FR in the plugins tab: woo has a build to download and can be requested; acme has no fr_FR.
		$by_locale = $counts( $this->xpath( $this->page( array( 'babilim_kind' => 'plugin', 'babilim_locale' => 'fr_FR' ) ) ) );
		$this->assertSame( 1, $by_locale['all'] );
		$this->assertSame( 0, $by_locale['available'], 'woo has a build only for de_DE' );
		$this->assertSame( 1, $by_locale['requestable'] );
	}
}
