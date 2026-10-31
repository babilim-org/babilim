<?php
/**
 * Tests for Babilim_Inventory -- plugin/theme component detection,
 * bundled-locale/extra-domain reverse-engineering, site-wide locale
 * discovery, the request_components() projection, and the
 * effective_file() registry lookup.
 *
 * This class is fed exclusively through its injected callables (never
 * WordPress core's own get_plugins()/wp_get_themes() directly), so
 * these tests never touch a real plugin/theme directory -- WP_PLUGIN_DIR
 * below is a bare string used only to build lookup keys for the fake
 * $list_dir callable, never a real directory on disk. It's defined once
 * at file scope, so it's visible to every test file in the same PHPUnit
 * process from this point on; nothing here has needed a different value
 * so far.
 *
 * Two WordPress symbols this class calls aren't stubbed by WordPress
 * core itself in tests: get_locale() and get_available_languages(). Both
 * are stubbed by a shared fixture (tests/fixtures/wp-locale-stubs.php,
 * loaded by tests/bootstrap.php) so every test file sees them regardless
 * of load order; the guarded declaration below is a harmless no-op once
 * that fixture has already defined them, kept only so this file still
 * works standing alone against an older bootstrap.php. A third,
 * pll_languages_list(), is stubbed the same way purely so Polylang's
 * own documented API shape exists somewhere in the process for the
 * "guard says yes" tests to call through to; the tests that need the
 * guard to genuinely fail inject their own $function_exists callable
 * instead of relying on this function's absence.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/fake-wp-content/plugins' );
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return $GLOBALS['babilim_test_site_locale'] ?? 'en_US';
	}
}

if ( ! function_exists( 'get_available_languages' ) ) {
	function get_available_languages( $dir = null ) {
		return $GLOBALS['babilim_test_available_languages'] ?? array();
	}
}

if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( $args = array() ) {
		return $GLOBALS['babilim_test_pll_locales'] ?? array();
	}
}

/**
 * A minimal WP_Theme-shaped test double: a ->get($header) method that
 * returns false for an absent/empty header (matching WP_Theme::get()'s
 * real behaviour) and a ->get_stylesheet_directory() method, plus
 * ->get_template_directory() and ->parent() for the child-theme case.
 */
final class Babilim_Test_Theme {

	/** @var string */
	private $stylesheet;

	/** @var array */
	private $headers;

	/** @var string */
	private $stylesheet_directory;

	/** @var Babilim_Test_Theme|null */
	private $parent;

	public function __construct( string $stylesheet, array $headers = array(), string $stylesheet_directory = '', ?Babilim_Test_Theme $parent = null ) {
		$this->stylesheet           = $stylesheet;
		$this->headers               = $headers;
		$this->stylesheet_directory = $stylesheet_directory;
		$this->parent                = $parent;
	}

	public function get_stylesheet() {
		return $this->stylesheet;
	}

	public function get( $header ) {
		return $this->headers[ $header ] ?? false;
	}

	public function get_stylesheet_directory() {
		return $this->stylesheet_directory;
	}

	public function get_template_directory() {
		return $this->parent ? $this->parent->get_stylesheet_directory() : $this->stylesheet_directory;
	}

	public function parent() {
		return $this->parent ?: false;
	}
}

final class Test_Inventory extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
		$GLOBALS['babilim_test_site_locale']         = 'en_US';
		$GLOBALS['babilim_test_available_languages'] = array();
		$GLOBALS['babilim_test_pll_locales']         = array();
		unset( $GLOBALS['wp_textdomain_registry'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_textdomain_registry'] );
	}

	// -----------------------------------------------------------------
	// components() -- plugins
	// -----------------------------------------------------------------

	public function test_components_uses_declared_text_domain_when_present(): void {
		$inventory = $this->make_inventory(
			array(
				'woocommerce/woocommerce.php' => array(
					'Name'       => 'WooCommerce',
					'Version'    => '11.1.1',
					'TextDomain' => 'woocommerce',
					'DomainPath' => '/i18n/languages',
				),
			)
		);

		$components = $inventory->components();

		$this->assertCount( 1, $components );
		$this->assertSame( 'plugin', $components[0]['kind'] );
		$this->assertSame( 'woocommerce', $components[0]['slug'] );
		$this->assertSame( 'woocommerce', $components[0]['text_domain'] );
		$this->assertSame( '/i18n/languages', $components[0]['domain_path'] );
		$this->assertSame( '11.1.1', $components[0]['version'] );
		$this->assertSame( 'woocommerce/woocommerce.php', $components[0]['main_file'] );
	}

	public function test_components_falls_back_to_directory_name_when_text_domain_missing(): void {
		$inventory = $this->make_inventory(
			array(
				'contact-form-7/wp-contact-form-7.php' => array(
					'Name'    => 'Contact Form 7',
					'Version' => '6.1.7',
				),
			)
		);

		$components = $inventory->components();

		$this->assertSame( 'contact-form-7', $components[0]['text_domain'] );
		$this->assertSame( 'contact-form-7', $components[0]['slug'] );
		$this->assertSame( '', $components[0]['domain_path'] );
	}

	public function test_components_reads_domain_path_when_declared(): void {
		$seen_dirs = array();
		$inventory = $this->make_inventory(
			array(
				'yoast-seo/wp-seo.php' => array(
					'Name'       => 'Yoast SEO',
					'Version'    => '28.6',
					'TextDomain' => 'wordpress-seo',
					'DomainPath' => '/languages',
				),
			),
			array(),
			false,
			array(),
			function ( $dir ) use ( &$seen_dirs ) {
				$seen_dirs[] = $dir;
				return array();
			}
		);

		$inventory->components();

		$this->assertContains( '/fake-wp-content/plugins/yoast-seo/languages', $seen_dirs );
	}

	public function test_domain_path_without_a_leading_slash_is_normalized(): void {
		$seen_dirs = array();
		$inventory = $this->make_inventory(
			array(
				'yoast-seo/wp-seo.php' => array(
					'Name'       => 'Yoast SEO',
					'Version'    => '28.6',
					'TextDomain' => 'wordpress-seo',
					'DomainPath' => 'languages', // No leading slash -- a common author mistake.
				),
			),
			array(),
			false,
			array(),
			function ( $dir ) use ( &$seen_dirs ) {
				$seen_dirs[] = $dir;
				return array();
			}
		);

		$components = $inventory->components();

		$this->assertSame( '/languages', $components[0]['domain_path'] );
		$this->assertContains( '/fake-wp-content/plugins/yoast-seo/languages', $seen_dirs );
		$this->assertNotContains( '/fake-wp-content/plugins/yoast-seolanguages', $seen_dirs );
	}

	public function test_single_file_plugin_scans_the_plugin_roots_domain_path(): void {
		$seen_dirs = array();
		$inventory = $this->make_inventory(
			array(
				'hello.php' => array(
					'Name'       => 'Hello Dolly',
					'Version'    => '1.7.2',
					'DomainPath' => '/languages',
				),
			),
			array(),
			false,
			array(),
			function ( $dir ) use ( &$seen_dirs ) {
				$seen_dirs[] = $dir;
				return array();
			}
		);

		$inventory->components();

		// Not "/fake-wp-content/plugins/hello/languages" -- dirname('hello.php')
		// is '.', so there's no subdirectory to append.
		$this->assertContains( '/fake-wp-content/plugins/languages', $seen_dirs );
	}

	public function test_single_file_plugin_prefers_the_transient_slug_over_the_filename(): void {
		$inventory = $this->make_inventory(
			array(
				'hello.php' => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ),
			),
			array(),
			array(
				'update_plugins' => (object) array(
					'response'  => array(),
					'no_update' => array(
						'hello.php' => array( 'id' => 'w.org/plugins/hello-dolly', 'slug' => 'hello-dolly' ),
					),
				),
			)
		);

		$components = $inventory->components();

		$this->assertSame( 'hello-dolly', $components[0]['slug'] );
	}

	public function test_falls_back_to_the_directory_name_when_the_transient_slug_is_unsafe(): void {
		// An unsafe transient-supplied slug must never end up in the
		// output as-is -- it's dropped in favour of the (already
		// filesystem-safe) directory name, the same fallback that
		// applies when the transient has no slug at all.
		$inventory = $this->make_inventory(
			array(
				'hello-dolly/hello.php' => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2', 'TextDomain' => 'hello-dolly' ),
			),
			array(),
			array(
				'update_plugins' => (object) array(
					'response'  => array(),
					'no_update' => array(
						'hello-dolly/hello.php' => array( 'id' => 'w.org/plugins/hello-dolly', 'slug' => 'bad slug' ),
					),
				),
			)
		);

		$components = $inventory->components();

		$this->assertCount( 1, $components );
		$this->assertSame( 'hello-dolly', $components[0]['slug'] );
	}

	// -----------------------------------------------------------------
	// components() -- is_wporg (plugins): real WordPress mixes array-
	// and object-shaped transient entries depending on where they came
	// from, so both shapes are tested for both plugins and themes.
	// -----------------------------------------------------------------

	public function test_plugin_is_wporg_true_with_an_object_shaped_transient_entry(): void {
		// This is the shape WordPress core itself actually delivers --
		// wp_update_plugins() casts every response/no_update entry to
		// an object before storing the transient.
		$transient = (object) array(
			'response'  => array(
				'hello-dolly/hello.php' => (object) array( 'id' => 'w.org/plugins/hello-dolly' ),
			),
			'no_update' => array(),
		);
		$inventory = $this->make_inventory(
			array( 'hello-dolly/hello.php' => array( 'Name' => 'Hello Dolly', 'Version' => '1.0' ) ),
			array(),
			array( 'update_plugins' => $transient )
		);

		$components = $inventory->components();

		$this->assertTrue( $components[0]['is_wporg'] );
	}

	public function test_plugin_is_wporg_true_with_an_array_shaped_transient_entry(): void {
		$transient = (object) array(
			'response'  => array(
				'hello-dolly/hello.php' => array( 'id' => 'w.org/plugins/hello-dolly' ),
			),
			'no_update' => array(),
		);
		$inventory = $this->make_inventory(
			array( 'hello-dolly/hello.php' => array( 'Name' => 'Hello Dolly', 'Version' => '1.0' ) ),
			array(),
			array( 'update_plugins' => $transient )
		);

		$components = $inventory->components();

		$this->assertTrue( $components[0]['is_wporg'] );
	}

	public function test_plugin_is_wporg_true_when_the_response_bucket_itself_is_an_object(): void {
		// The buckets themselves ('response'/'no_update'), not just the
		// entries inside them, may be objects rather than arrays.
		$transient = (object) array(
			'response'  => (object) array(
				'hello-dolly/hello.php' => array( 'id' => 'w.org/plugins/hello-dolly' ),
			),
			'no_update' => (object) array(),
		);
		$inventory = $this->make_inventory(
			array( 'hello-dolly/hello.php' => array( 'Name' => 'Hello Dolly', 'Version' => '1.0' ) ),
			array(),
			array( 'update_plugins' => $transient )
		);

		$components = $inventory->components();

		$this->assertTrue( $components[0]['is_wporg'] );
	}

	public function test_plugin_is_wporg_false_when_id_does_not_match(): void {
		$transient = array(
			'response'  => array(),
			'no_update' => array(
				'acme-plugin/acme.php' => array( 'id' => 'some-other-market/acme-plugin' ),
			),
		);
		$inventory = $this->make_inventory(
			array( 'acme-plugin/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0' ) ),
			array(),
			array( 'update_plugins' => $transient )
		);

		$components = $inventory->components();

		$this->assertFalse( $components[0]['is_wporg'] );
	}

	public function test_plugin_is_wporg_false_when_transient_does_not_exist(): void {
		$inventory = $this->make_inventory(
			array( 'acme-plugin/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0' ) ),
			array(),
			array() // get_transient() returns false for every key below.
		);

		$components = $inventory->components();

		$this->assertFalse( $components[0]['is_wporg'] );
	}

	public function test_plugin_is_wporg_false_when_it_declares_a_third_party_update_uri(): void {
		// The plugin opted out of wp.org's own update service by
		// declaring its own `Update URI` header -- its transient entry
		// (crafted by whatever server that header points at, not by
		// wp.org) still gets checked for a matching `id`, and here it's
		// made to look exactly like a genuine wp.org entry, to prove
		// the UpdateURI override wins regardless of what the transient
		// itself claims.
		$transient = (object) array(
			'response'  => array(),
			'no_update' => array(
				'acme-plugin/acme.php' => array( 'id' => 'w.org/plugins/acme-plugin' ),
			),
		);
		$inventory = $this->make_inventory(
			array(
				'acme-plugin/acme.php' => array(
					'Name'      => 'Acme',
					'Version'   => '1.0',
					'UpdateURI' => 'https://updates.example.test/acme-plugin/',
				),
			),
			array(),
			array( 'update_plugins' => $transient )
		);

		$components = $inventory->components();

		$this->assertFalse( $components[0]['is_wporg'] );
	}

	public function test_plugin_is_wporg_not_overridden_when_update_uri_host_is_wordpress_org(): void {
		// The override only fires for a host other than w.org/wordpress.org
		// -- an (unusual, but not forbidden) UpdateURI that itself
		// points at wordpress.org doesn't suppress the normal
		// transient-based check.
		$transient = (object) array(
			'response'  => array(
				'acme-plugin/acme.php' => array( 'id' => 'w.org/plugins/acme-plugin' ),
			),
			'no_update' => array(),
		);
		$inventory = $this->make_inventory(
			array(
				'acme-plugin/acme.php' => array(
					'Name'      => 'Acme',
					'Version'   => '1.0',
					'UpdateURI' => 'https://wordpress.org/plugins/acme-plugin/',
				),
			),
			array(),
			array( 'update_plugins' => $transient )
		);

		$components = $inventory->components();

		$this->assertTrue( $components[0]['is_wporg'] );
	}

	public function test_plugin_is_wporg_false_for_a_scheme_less_third_party_update_uri(): void {
		// The same scheme-less pre-processing must still recognize a
		// genuinely third-party scheme-less host as third-party -- this
		// isn't a blanket "scheme-less always means wp.org" shortcut.
		$transient = (object) array(
			'response'  => array(),
			'no_update' => array(
				'acme-plugin/acme.php' => array( 'id' => 'w.org/plugins/acme-plugin' ),
			),
		);
		$inventory = $this->make_inventory(
			array(
				'acme-plugin/acme.php' => array(
					'Name'      => 'Acme',
					'Version'   => '1.0',
					'UpdateURI' => 'updates.example.test/acme-plugin',
				),
			),
			array(),
			array( 'update_plugins' => $transient )
		);

		$components = $inventory->components();

		$this->assertFalse( $components[0]['is_wporg'] );
	}

	public function test_plugin_is_wporg_not_overridden_by_an_update_uri_with_no_resolvable_host(): void {
		// A value starting with '/' has no host to resolve in WordPress
		// core's own esc_url()-based algorithm (core never prepends a
		// scheme to something that already looks like an absolute path) --
		// so this must be treated the same as "no third-party host found"
		// and fall through to the normal transient-based check, not be
		// wrongly recovered as host "example.com".
		$transient = (object) array(
			'response'  => array(
				'acme-plugin/acme.php' => array( 'id' => 'w.org/plugins/acme-plugin' ),
			),
			'no_update' => array(),
		);
		$inventory = $this->make_inventory(
			array(
				'acme-plugin/acme.php' => array(
					'Name'      => 'Acme',
					'Version'   => '1.0',
					'UpdateURI' => '/example.com/acme-plugin',
				),
			),
			array(),
			array( 'update_plugins' => $transient )
		);

		$components = $inventory->components();

		$this->assertTrue( $components[0]['is_wporg'] );
	}

	// -----------------------------------------------------------------
	// components() -- themes
	// -----------------------------------------------------------------

	public function test_components_includes_a_parent_and_a_child_theme_as_separate_entries(): void {
		$parent = new Babilim_Test_Theme(
			'parent-theme',
			array( 'Name' => 'Parent Theme', 'Version' => '2.0', 'TextDomain' => 'parent-theme' ),
			'/fake-wp-content/themes/parent-theme'
		);
		$child  = new Babilim_Test_Theme(
			'child-theme',
			array( 'Name' => 'Child Theme', 'Version' => '1.0', 'TextDomain' => 'child-theme' ),
			'/fake-wp-content/themes/child-theme',
			$parent
		);

		$inventory  = $this->make_inventory( array(), array( 'parent-theme' => $parent, 'child-theme' => $child ) );
		$components = $inventory->components();
		$slugs      = array_column( $components, 'slug' );

		$this->assertContains( 'parent-theme', $slugs );
		$this->assertContains( 'child-theme', $slugs );
		foreach ( $components as $component ) {
			$this->assertSame( 'theme', $component['kind'] );
			$this->assertSame( $component['slug'], $component['stylesheet'] );
		}
	}

	public function test_theme_field_reads_declared_headers_via_the_wp_theme_shaped_objects_get_method(): void {
		// Every value here is deliberately declared and deliberately
		// different from any fallback default, so a theme_field() that
		// stopped actually calling ->get() (and just returned the
		// fallback every time) would still be caught.
		$theme     = new Babilim_Test_Theme(
			'plain-theme',
			array( 'Name' => 'Plain Theme', 'Version' => '4.2', 'TextDomain' => 'plain-theme-domain', 'DomainPath' => '/lang' ),
			'/fake-wp-content/themes/plain-theme'
		);
		$inventory = $this->make_inventory( array(), array( 'plain-theme' => $theme ) );

		$components = $inventory->components();

		$this->assertSame( 'Plain Theme', $components[0]['name'] );
		$this->assertSame( '4.2', $components[0]['version'] );
		$this->assertSame( 'plain-theme-domain', $components[0]['text_domain'] );
		$this->assertSame( '/lang', $components[0]['domain_path'] );
	}

	public function test_theme_field_falls_back_when_a_header_is_absent(): void {
		// TextDomain absent falls back to the stylesheet slug,
		// DomainPath absent falls back to '/languages' -- both read via
		// ->get(), which returns false (not '' or null) for an
		// absent header, same as the real WP_Theme::get().
		$theme     = new Babilim_Test_Theme( 'plain-theme', array( 'Name' => 'Plain Theme', 'Version' => '1.0' ), '/fake-wp-content/themes/plain-theme' );
		$inventory = $this->make_inventory( array(), array( 'plain-theme' => $theme ) );

		$components = $inventory->components();

		$this->assertSame( 'plain-theme', $components[0]['text_domain'] );
		$this->assertSame( '/languages', $components[0]['domain_path'] );
	}

	public function test_theme_is_wporg_true_with_an_array_shaped_wporg_entry(): void {
		// This is the real shape: wp_update_themes() stores wp.org's
		// JSON response for a theme as a plain array -- 'theme',
		// 'new_version', 'url', 'package' -- never a 'theme_uri' key.
		$transient = (object) array(
			'response' => array(
				'twentytwentyfive' => array(
					'theme'       => 'twentytwentyfive',
					'new_version' => '1.3',
					'url'         => 'https://wordpress.org/themes/twentytwentyfive/',
					'package'     => 'https://downloads.wordpress.org/theme/twentytwentyfive.1.3.zip',
				),
			),
			'no_update' => array(),
		);
		$theme     = new Babilim_Test_Theme( 'twentytwentyfive', array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.2' ), '/fake-wp-content/themes/twentytwentyfive' );
		$inventory = $this->make_inventory( array(), array( 'twentytwentyfive' => $theme ), array( 'update_themes' => $transient ) );

		$components = $inventory->components();

		$this->assertTrue( $components[0]['is_wporg'] );
	}

	public function test_theme_is_wporg_true_with_an_object_shaped_entry(): void {
		$transient = (object) array(
			'response'  => array(),
			'no_update' => array(
				'twentytwentyfive' => (object) array( 'url' => 'https://wordpress.org/themes/twentytwentyfive/' ),
			),
		);
		$theme     = new Babilim_Test_Theme( 'twentytwentyfive', array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.2' ), '/fake-wp-content/themes/twentytwentyfive' );
		$inventory = $this->make_inventory( array(), array( 'twentytwentyfive' => $theme ), array( 'update_themes' => $transient ) );

		$components = $inventory->components();

		$this->assertTrue( $components[0]['is_wporg'] );
	}

	public function test_theme_is_wporg_false_for_a_non_wporg_url(): void {
		$transient = (object) array(
			'response'  => array(),
			'no_update' => array(
				'acme-theme' => array( 'theme' => 'acme-theme', 'new_version' => '1.0', 'url' => 'https://example.test/themes/acme/', 'package' => '' ),
			),
		);
		$theme     = new Babilim_Test_Theme( 'acme-theme', array( 'Name' => 'Acme Theme', 'Version' => '1.0' ), '/fake-wp-content/themes/acme-theme' );
		$inventory = $this->make_inventory( array(), array( 'acme-theme' => $theme ), array( 'update_themes' => $transient ) );

		$components = $inventory->components();

		$this->assertFalse( $components[0]['is_wporg'] );
	}

	// -----------------------------------------------------------------
	// components() -- unsafe slug/text_domain
	// -----------------------------------------------------------------

	public function test_falls_back_text_domain_to_slug_when_the_declared_domain_is_unsafe(): void {
		$inventory = $this->make_inventory(
			array(
				'my-plugin/my-plugin.php' => array(
					'Name'       => 'My Plugin',
					'Version'    => '1.0',
					'TextDomain' => 'my plugin domain', // Contains spaces -- not path-safe.
				),
			)
		);

		$components = $inventory->components();

		$this->assertCount( 1, $components );
		$this->assertSame( 'my-plugin', $components[0]['text_domain'] );
		$this->assertSame( 'my-plugin', $components[0]['slug'] );
	}

	public function test_drops_a_component_when_neither_slug_nor_text_domain_is_safe(): void {
		$inventory = $this->make_inventory(
			array(
				'bad dir/bad-plugin.php' => array(
					'Name'       => 'Bad Plugin',
					'Version'    => '1.0',
					'TextDomain' => 'also bad',
				),
			)
		);

		$this->assertSame( array(), $inventory->components() );
	}

	public function test_drops_a_component_when_only_the_slug_is_unsafe(): void {
		// TextDomain is declared and path-safe on its own -- slug's own
		// safety check is what has to catch the unsafe directory name
		// here, not the text_domain-falls-back-to-slug path (which
		// would otherwise mask this: if the fallback ran, it would try
		// to copy the *unsafe* slug into text_domain, not the other way
		// around).
		$inventory = $this->make_inventory(
			array(
				'bad dir/bad-plugin.php' => array(
					'Name'       => 'Bad Plugin',
					'Version'    => '1.0',
					'TextDomain' => 'good-domain',
				),
			)
		);

		$this->assertSame( array(), $inventory->components() );
	}

	// -----------------------------------------------------------------
	// extra_domains / bundled_locales reverse-engineering
	// -----------------------------------------------------------------

	public function test_extra_domains_are_reversed_from_filenames_under_the_domain_path(): void {
		$inventory = $this->make_inventory(
			array(
				'fusion-builder/fusion-builder.php' => array(
					'Name'       => 'Fusion Builder',
					'Version'    => '3.11',
					'TextDomain' => 'fusion-builder',
					'DomainPath' => '/languages',
				),
			),
			array(),
			false,
			array(),
			function ( $dir ) {
				return array(
					'.',
					'..',
					'fusion-builder-de_DE.mo',
					'fusion-core-de_DE.mo',
					'fusion-core-zh_TW.l10n.php',
					'fusion-admin-fr_FR.mo',
				);
			}
		);

		$components = $inventory->components();

		$this->assertSame( array( 'fusion-admin', 'fusion-core' ), $components[0]['extra_domains'] );
	}

	public function test_extra_domains_excludes_a_domain_that_fails_is_safe_name(): void {
		$inventory = $this->make_inventory(
			array(
				'sample-plugin/sample-plugin.php' => array(
					'Name'       => 'Sample Plugin',
					'Version'    => '1.0',
					'TextDomain' => 'sample-plugin',
				),
			),
			array(),
			false,
			array(),
			function ( $dir ) {
				return array(
					'bad domain-de_DE.mo', // Domain has a space -- unsafe.
					'fusion-core-fr_FR.mo',
				);
			}
		);

		$components = $inventory->components();

		$this->assertSame( array( 'fusion-core' ), $components[0]['extra_domains'] );
	}

	public function test_scan_domain_ignores_a_filename_the_anchored_regex_only_partially_matches(): void {
		$inventory = $this->make_inventory(
			array(
				'sample-plugin/sample-plugin.php' => array(
					'Name'       => 'Sample Plugin',
					'Version'    => '1.0',
					'TextDomain' => 'sample-plugin',
				),
			),
			array(),
			false,
			array(),
			function ( $dir ) {
				// Without the pattern's trailing `$` anchor, this would
				// still match a leading "x-de_DE.mo" substring.
				return array( 'x-de_DE.mo.bak', 'y-fr_FR.mo' );
			}
		);

		$components = $inventory->components();

		$this->assertSame( array( 'y' ), $components[0]['extra_domains'] );
	}

	public function test_bundled_locales_are_deduped_and_string_sorted(): void {
		$inventory = $this->make_inventory(
			array(
				'sample-plugin/sample-plugin.php' => array(
					'Name'       => 'Sample Plugin',
					'Version'    => '1.0',
					'TextDomain' => 'sample-plugin',
				),
			),
			array(),
			false,
			array(),
			function ( $dir ) {
				return array(
					'sample-plugin-zh_TW.mo',
					'sample-plugin-de_DE.mo',
					'sample-plugin-de_DE.l10n.php',
					'sample-plugin-not_a_locale!.mo',
					'unrelated-file.txt',
				);
			}
		);

		$components = $inventory->components();

		$this->assertSame( array( 'de_DE', 'zh_TW' ), $components[0]['bundled_locales'] );
	}

	public function test_bundled_locales_matches_domainless_filenames_as_own_domain_for_themes(): void {
		// The theme filename special case: `{locale}.mo` with no domain
		// prefix, still counted as this component's own bundled locale.
		$theme     = new Babilim_Test_Theme( 'classic-theme', array( 'Name' => 'Classic Theme', 'Version' => '3.0', 'TextDomain' => 'classic-theme' ), '/fake-wp-content/themes/classic-theme' );
		$inventory = $this->make_inventory(
			array(),
			array( 'classic-theme' => $theme ),
			false,
			array(),
			function ( $dir ) {
				return array( 'de_DE.mo', 'zh_TW.l10n.php' );
			}
		);

		$components = $inventory->components();

		$this->assertSame( array( 'de_DE', 'zh_TW' ), $components[0]['bundled_locales'] );
	}

	public function test_bundled_locales_excludes_domainless_filenames_for_plugins(): void {
		// The reverse of the theme case above: WordPress core never
		// loads a plugin's translation file without a domain prefix, so
		// a domainless filename in a plugin's own directory must be
		// ignored entirely -- neither a bundled locale nor an extra
		// domain.
		$inventory = $this->make_inventory(
			array(
				'sample-plugin/sample-plugin.php' => array(
					'Name'       => 'Sample Plugin',
					'Version'    => '1.0',
					'TextDomain' => 'sample-plugin',
				),
			),
			array(),
			false,
			array(),
			function ( $dir ) {
				return array( 'de_DE.mo', 'sample-plugin-fr_FR.mo' );
			}
		);

		$components = $inventory->components();

		$this->assertSame( array( 'fr_FR' ), $components[0]['bundled_locales'] );
		$this->assertSame( array(), $components[0]['extra_domains'] );
	}

	// -----------------------------------------------------------------
	// request_components()
	// -----------------------------------------------------------------

	public function test_request_components_projects_exactly_the_contract_keys(): void {
		$inventory = $this->make_inventory(
			array(
				'woocommerce/woocommerce.php' => array(
					'Name'       => 'WooCommerce',
					'Version'    => '11.1.1',
					'TextDomain' => 'woocommerce',
					'DomainPath' => '/i18n/languages',
				),
			),
			array( 'twentytwentyfive' => new Babilim_Test_Theme( 'twentytwentyfive', array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.2', 'TextDomain' => 'twentytwentyfive' ), '/fake-wp-content/themes/twentytwentyfive' ) )
		);

		$expected_keys = array( 'kind', 'slug', 'version', 'text_domain', 'extra_domains', 'is_wporg', 'bundled_locales' );

		$request_components = $inventory->request_components();
		$this->assertCount( 2, $request_components );

		foreach ( $request_components as $component ) {
			$actual_keys = array_keys( $component );
			sort( $actual_keys );
			$expected_sorted = $expected_keys;
			sort( $expected_sorted );
			$this->assertSame( $expected_sorted, $actual_keys );

			$this->assertIsString( $component['kind'] );
			$this->assertContains( $component['kind'], array( 'plugin', 'theme' ) );
			$this->assertIsString( $component['slug'] );
			$this->assertNotSame( '', $component['slug'] );
			$this->assertIsString( $component['version'] );
			$this->assertNotSame( '', $component['version'] );
			$this->assertIsString( $component['text_domain'] );
			$this->assertNotSame( '', $component['text_domain'] );
			$this->assertIsArray( $component['extra_domains'] );
			$this->assertIsBool( $component['is_wporg'] );
			$this->assertIsArray( $component['bundled_locales'] );
		}

		// components() itself keeps the richer shape -- request_components()
		// is a projection of it, not a replacement.
		$this->assertArrayHasKey( 'main_file', $inventory->components()[0] );
	}

	public function test_request_components_excludes_a_component_with_no_version(): void {
		$inventory = $this->make_inventory(
			array(
				'has-version/has-version.php' => array( 'Name' => 'Has Version', 'Version' => '1.0', 'TextDomain' => 'has-version' ),
				'no-version/no-version.php'   => array( 'Name' => 'No Version', 'TextDomain' => 'no-version' ),
			)
		);

		$this->assertCount( 2, $inventory->components() );

		$request_slugs = array_column( $inventory->request_components(), 'slug' );
		$this->assertSame( array( 'has-version' ), $request_slugs );
	}

	// -----------------------------------------------------------------
	// locales()
	// -----------------------------------------------------------------

	public function test_locales_dedupes_and_sorts_by_wp_locale_string_without_favoring_site_locale(): void {
		$GLOBALS['babilim_test_site_locale']         = 'zh_TW';
		$GLOBALS['babilim_test_available_languages'] = array( 'de_DE', 'fr_FR', 'zh_TW' );

		$inventory = $this->make_inventory(
			array(),
			array(),
			false,
			array( 'fr_FR', 'es_ES', 'es_ES' )
		);

		$locales = $inventory->locales();

		$this->assertSame( array( 'de_DE', 'es_ES', 'fr_FR', 'zh_TW' ), $locales );
		// Not first just because it's the site locale -- 'de_DE' sorts
		// before it string-wise.
		$this->assertNotSame( 'zh_TW', $locales[0] );
	}

	public function test_locales_drops_unsafe_strings_without_erroring(): void {
		$GLOBALS['babilim_test_site_locale']         = 'en_US';
		$GLOBALS['babilim_test_available_languages'] = array( '../etc', 'zh TW', '', 'de_DE' );

		$inventory = $this->make_inventory( array(), array(), false, array( "j\na", 'fr_FR' ) );

		$locales = $inventory->locales();

		$this->assertSame( array( 'de_DE', 'en_US', 'fr_FR' ), $locales );
	}

	public function test_locales_includes_manually_added_locales_from_the_settings_option(): void {
		update_option( 'babilim_manual_locales', array( 'pt_BR' ) );

		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertSame( array( 'en_US', 'pt_BR' ), $inventory->locales() );
	}

	// -----------------------------------------------------------------
	// detected_locales() / manual_locales() -- the split locales() is
	// built from, that the settings screen needs to tell "this locale is
	// already managed automatically" apart from "this locale is only
	// managed because it was manually added" (see the class docblock).
	// -----------------------------------------------------------------

	public function test_detected_locales_excludes_manually_added_ones(): void {
		$GLOBALS['babilim_test_site_locale'] = 'en_US';
		update_option( 'babilim_manual_locales', array( 'pt_BR' ) );
		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertSame( array( 'en_US' ), $inventory->detected_locales() );
		$this->assertSame( array( 'en_US', 'pt_BR' ), $inventory->locales(), 'locales() itself must still include the manual one' );
	}

	public function test_manual_locales_returns_the_raw_stored_list(): void {
		update_option( 'babilim_manual_locales', array( 'pt_BR', 'vi' ) );
		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertSame( array( 'pt_BR', 'vi' ), $inventory->manual_locales() );
	}

	public function test_set_manual_locales_is_reflected_by_a_later_locales_call(): void {
		$inventory = $this->make_inventory( array(), array(), false, array() );

		$inventory->set_manual_locales( array( 'pt_BR', 'vi' ) );

		$this->assertSame( array( 'en_US', 'pt_BR', 'vi' ), $inventory->locales() );
	}

	public function test_set_manual_locales_replaces_the_previous_list_wholesale(): void {
		update_option( 'babilim_manual_locales', array( 'pt_BR' ) );
		$inventory = $this->make_inventory( array(), array(), false, array() );

		$inventory->set_manual_locales( array( 'vi' ) );

		$this->assertSame( array( 'en_US', 'vi' ), $inventory->locales() );
	}

	public function test_set_manual_locales_drops_unsafe_strings(): void {
		$inventory = $this->make_inventory( array(), array(), false, array() );

		$inventory->set_manual_locales( array( 'pt_BR', "evil'; drop table" ) );

		$this->assertSame( array( 'en_US', 'pt_BR' ), $inventory->locales() );
	}

	public function test_locales_includes_wpml_active_languages_via_its_documented_filter(): void {
		add_filter(
			'wpml_active_languages',
			static function ( $value ) {
				return array(
					'de' => array( 'default_locale' => 'de_DE' ),
					'fr' => array( 'default_locale' => 'fr_FR' ),
				);
			}
		);

		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertSame( array( 'de_DE', 'en_US', 'fr_FR' ), $inventory->locales() );
	}

	public function test_multilingual_plugin_locales_are_canonicalized_and_deduped(): void {
		add_filter(
			'wpml_active_languages',
			static function ( $value ) {
				return array(
					'tw'  => array( 'default_locale' => 'zh-tw' ),
					'zh'  => array( 'default_locale' => 'zh_TW' ),
					'bad' => array( 'default_locale' => 'zh TW!' ),
				);
			}
		);

		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertSame( array( 'en_US', 'zh_TW' ), $inventory->locales() );
	}

	/**
	 * @dataProvider canonical_cases
	 */
	public function test_canonical_locale( string $raw, ?string $expected ): void {
		$this->assertSame( $expected, Babilim_Inventory::canonical_locale( $raw ) );
	}

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function canonical_cases(): array {
		return array(
			'dash, lowercase'      => array( 'zh-tw', 'zh_TW' ),
			'mixed case region'    => array( 'PT_br', 'pt_BR' ),
			'variant kept'         => array( 'de_DE_formal', 'de_DE_formal' ),
			'variant lowercased'   => array( 'de-ch-INFORMAL', 'de_CH_informal' ),
			'three letter'         => array( 'rhg', 'rhg' ),
			'bare language'        => array( 'en', 'en' ),
			'space'                => array( 'zh TW', null ),
			'region not two chars' => array( 'zh_Hant_TW', null ),
			'empty'                => array( '', null ),
		);
	}

	public function test_locales_includes_polylang_languages_when_the_function_exists_check_says_yes(): void {
		$GLOBALS['babilim_test_pll_locales'] = array( 'nl_NL' );

		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertSame( array( 'en_US', 'nl_NL' ), $inventory->locales() );
	}

	public function test_locales_excludes_polylang_languages_when_the_injected_function_exists_check_says_no(): void {
		// pll_languages_list() itself is still defined (and would
		// return 'nl_NL' if called) -- this proves the guard itself is
		// what's keeping it out, by making the check that decides
		// whether to call it always say "absent".
		$GLOBALS['babilim_test_pll_locales'] = array( 'nl_NL' );

		$inventory = $this->make_inventory(
			array(),
			array(),
			false,
			array(),
			null,
			static function ( $name ) {
				return false;
			}
		);

		$this->assertSame( array( 'en_US' ), $inventory->locales() );
	}

	public function test_locales_includes_translatepress_languages_from_its_option(): void {
		update_option(
			'trp_settings',
			array( 'translation-languages' => array( 'it_IT', 'en_US' ) )
		);

		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertSame( array( 'en_US', 'it_IT' ), $inventory->locales() );
	}

	public function test_locales_never_warns_when_no_multilingual_plugin_is_present(): void {
		$inventory = $this->make_inventory( array(), array(), false, array() );
		$warnings  = array();
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$warnings ) {
				$warnings[] = $errstr;
				return true;
			}
		);

		try {
			$inventory->locales();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );
	}

	// -----------------------------------------------------------------
	// effective_file()
	// -----------------------------------------------------------------

	public function test_effective_file_returns_null_when_the_registry_is_not_present(): void {
		unset( $GLOBALS['wp_textdomain_registry'] );

		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertNull( $inventory->effective_file( 'woocommerce', 'zh_TW' ) );
	}

	public function test_effective_file_rejects_an_unsafe_domain_without_consulting_the_registry(): void {
		$calls                              = 0;
		$GLOBALS['wp_textdomain_registry'] = new class( $calls ) {
			private $calls;
			public function __construct( &$calls ) {
				$this->calls = &$calls;
			}
			public function get( $domain, $locale ) {
				++$this->calls;
				return '/some/real/dir';
			}
		};

		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertNull( $inventory->effective_file( '../etc', 'zh_TW' ) );
		$this->assertSame( 0, $calls );
	}

	public function test_effective_file_rejects_an_unsafe_locale_without_consulting_the_registry(): void {
		$calls                              = 0;
		$GLOBALS['wp_textdomain_registry'] = new class( $calls ) {
			private $calls;
			public function __construct( &$calls ) {
				$this->calls = &$calls;
			}
			public function get( $domain, $locale ) {
				++$this->calls;
				return '/some/real/dir';
			}
		};

		$inventory = $this->make_inventory( array(), array(), false, array() );

		$this->assertNull( $inventory->effective_file( 'woocommerce', "zh\nTW" ) );
		$this->assertSame( 0, $calls );
	}

	public function test_effective_file_prefers_l10n_php_over_mo(): void {
		$dir = BABILIM_TEST_TMP_ROOT . '/babilim-inventory-test-' . uniqid( '', true );
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/woocommerce-zh_TW.mo', 'mo-bytes' );
		file_put_contents( $dir . '/woocommerce-zh_TW.l10n.php', '<?php return array();' );

		$GLOBALS['wp_textdomain_registry'] = new class( $dir ) {
			private $dir;
			public function __construct( $dir ) {
				$this->dir = $dir;
			}
			public function get( $domain, $locale ) {
				return $this->dir;
			}
		};

		$inventory = $this->make_inventory( array(), array(), false, array() );

		try {
			$this->assertSame(
				$dir . '/woocommerce-zh_TW.l10n.php',
				$inventory->effective_file( 'woocommerce', 'zh_TW' )
			);
		} finally {
			unlink( $dir . '/woocommerce-zh_TW.mo' );
			unlink( $dir . '/woocommerce-zh_TW.l10n.php' );
			rmdir( $dir );
		}
	}

	public function test_effective_file_falls_back_to_mo_when_no_l10n_php_exists(): void {
		$dir = BABILIM_TEST_TMP_ROOT . '/babilim-inventory-test-' . uniqid( '', true );
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/woocommerce-zh_TW.mo', 'mo-bytes' );

		$GLOBALS['wp_textdomain_registry'] = new class( $dir ) {
			private $dir;
			public function __construct( $dir ) {
				$this->dir = $dir;
			}
			public function get( $domain, $locale ) {
				return $this->dir;
			}
		};

		$inventory = $this->make_inventory( array(), array(), false, array() );

		try {
			$this->assertSame(
				$dir . '/woocommerce-zh_TW.mo',
				$inventory->effective_file( 'woocommerce', 'zh_TW' )
			);
		} finally {
			unlink( $dir . '/woocommerce-zh_TW.mo' );
			rmdir( $dir );
		}
	}

	public function test_effective_file_uses_the_domainless_filename_inside_a_theme_directory(): void {
		$dir = BABILIM_TEST_TMP_ROOT . '/babilim-inventory-theme-test-' . uniqid( '', true );
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/de_DE.mo', 'mo-bytes' );

		$GLOBALS['wp_textdomain_registry'] = new class( $dir ) {
			private $dir;
			public function __construct( $dir ) {
				$this->dir = $dir;
			}
			public function get( $domain, $locale ) {
				return $this->dir;
			}
		};

		$theme     = new Babilim_Test_Theme( 'classic-theme', array( 'Name' => 'Classic Theme', 'Version' => '3.0', 'TextDomain' => 'classic-theme' ), $dir );
		$inventory = $this->make_inventory( array(), array( 'classic-theme' => $theme ) );

		try {
			$this->assertSame(
				$dir . '/de_DE.mo',
				$inventory->effective_file( 'classic-theme', 'de_DE' )
			);
		} finally {
			unlink( $dir . '/de_DE.mo' );
			rmdir( $dir );
		}
	}

	public function test_effective_file_caches_get_themes_across_repeated_calls(): void {
		// is_theme_own_directory() (called from effective_file()) used to
		// re-invoke the injected $get_themes callable on every single
		// call -- re-querying every installed theme's directory just to
		// resolve one file path, over and over, for the same request.
		$dir = BABILIM_TEST_TMP_ROOT . '/babilim-inventory-theme-test-' . uniqid( '', true );
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/de_DE.mo', 'mo-bytes' );

		$GLOBALS['wp_textdomain_registry'] = new class( $dir ) {
			private $dir;
			public function __construct( $dir ) {
				$this->dir = $dir;
			}
			public function get( $domain, $locale ) {
				return $this->dir;
			}
		};

		$theme       = new Babilim_Test_Theme( 'classic-theme', array( 'Name' => 'Classic Theme', 'Version' => '3.0', 'TextDomain' => 'classic-theme' ), $dir );
		$call_count  = 0;
		$get_themes  = static function () use ( $theme, &$call_count ) {
			++$call_count;
			return array( 'classic-theme' => $theme );
		};
		$inventory = new Babilim_Inventory(
			static function () { return array(); },
			$get_themes,
			static function () { return false; },
			static function () { return array(); }
		);

		try {
			$inventory->effective_file( 'classic-theme', 'de_DE' );
			$inventory->effective_file( 'classic-theme', 'de_DE' );
			$inventory->effective_file( 'classic-theme', 'de_DE' );

			$this->assertSame( 1, $call_count, 'get_themes() must be queried at most once per Babilim_Inventory instance' );
		} finally {
			unlink( $dir . '/de_DE.mo' );
			rmdir( $dir );
		}
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Builds a Babilim_Inventory wired to fake data.
	 *
	 * @param array         $plugins         get_plugins()-shaped array.
	 * @param array         $themes          Array of Babilim_Test_Theme (or other WP_Theme-shaped) descriptors keyed by stylesheet.
	 * @param array|false   $transients      Map of transient name => value; false/absent keys resolve to false.
	 * @param array         $users_locales   Flat array of user locale strings.
	 * @param callable|null $list_dir        Fake directory lister.
	 * @param callable|null $function_exists Fake function-existence check.
	 */
	private function make_inventory( array $plugins = array(), array $themes = array(), $transients = false, array $users_locales = array(), ?callable $list_dir = null, ?callable $function_exists = null ): Babilim_Inventory {
		return new Babilim_Inventory(
			static function () use ( $plugins ) {
				return $plugins;
			},
			static function () use ( $themes ) {
				return $themes;
			},
			static function ( $name ) use ( $transients ) {
				if ( false === $transients ) {
					return false;
				}
				return $transients[ $name ] ?? false;
			},
			static function () use ( $users_locales ) {
				return $users_locales;
			},
			$list_dir,
			$function_exists
		);
	}
}
