<?php
/**
 * Lint for readme.txt (WordPress.org format): required headers and
 * sections, agreement with the main plugin file, the contractual loading
 * priority, a complete External services disclosure, and wording that
 * the plugin's public documentation must never contain.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Readme extends TestCase {

	/** @var string */
	private $readme;

	/** @var string */
	private $plugin_file;

	protected function setUp(): void {
		$root              = dirname( __DIR__, 2 );
		$this->readme      = (string) file_get_contents( $root . '/readme.txt' );
		$this->plugin_file = (string) file_get_contents( $root . '/babilim.php' );
	}

	/**
	 * @return array<string,string> Header name => value.
	 */
	private function headers(): array {
		$headers = array();
		foreach ( explode( "\n", (string) strstr( $this->readme, "\n== ", true ) ) as $line ) {
			if ( 1 === preg_match( '/^([A-Za-z ]+):\s*(.+)$/', $line, $m ) ) {
				$headers[ $m[1] ] = trim( $m[2] );
			}
		}
		return $headers;
	}

	private function section( string $title ): string {
		$this->assertSame( 1, preg_match( '/^== ' . preg_quote( $title, '/' ) . ' ==\n(.*?)(?=^== |\z)/ms', $this->readme, $m ), "section '{$title}' is missing" );
		return $m[1];
	}

	public function test_required_headers_are_present_and_agree_with_the_plugin_file(): void {
		$h = $this->headers();
		foreach ( array( 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ) as $name ) {
			$this->assertArrayHasKey( $name, $h, "{$name} header" );
		}
		$this->assertSame( 'GPL-2.0-or-later', $h['License'] );
		$this->assertSame( '6.6', $h['Requires at least'] );
		$this->assertSame( '7.4', $h['Requires PHP'] );
		$this->assertLessThanOrEqual( 5, count( explode( ',', $h['Tags'] ) ), 'wp.org allows at most five tags' );
		$this->assertSame( 1, preg_match( '/^ \* Version:\s*(\S+)/m', $this->plugin_file, $v ) );
		$this->assertSame( $v[1], $h['Stable tag'], 'Stable tag must equal the plugin header Version' );
		$this->assertStringContainsString( "define( 'BABILIM_VERSION', '{$v[1]}' )", $this->plugin_file );
		$this->assertStringNotContainsString( 'Update URI', $this->plugin_file );
		$this->assertMatchesRegularExpression( '/^===\s+Babilim\s+===/', $this->readme );
	}

	public function test_short_description_fits_the_wporg_limit(): void {
		$this->assertSame( 1, preg_match( '/^License URI:[^\n]*\n\n([^\n]+)\n\n== /m', $this->readme, $m ) );
		$this->assertLessThanOrEqual( 150, strlen( trim( $m[1] ) ) );
	}

	public function test_required_sections_exist_and_the_changelog_covers_the_current_version(): void {
		foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'External services', 'Changelog' ) as $title ) {
			$this->assertNotSame( '', trim( $this->section( $title ) ), "{$title} must not be empty" );
		}
		$this->assertMatchesRegularExpression( '/^= ' . preg_quote( $this->headers()['Stable tag'], '/' ) . ' =$/m', $this->section( 'Changelog' ) );
		$this->assertDoesNotMatchRegularExpression( '/lands with|coming soon|\bTBD\b/i', $this->readme, 'no placeholder wording' );
	}

	public function test_the_loading_priority_is_stated_plainly(): void {
		$s = $this->section( 'How translations are loaded' );
		$this->assertMatchesRegularExpression( '/override in `custom\/`.*beats a translation Babilim provides.*beats an official WordPress\.org language pack or the plugin\'s or theme\'s own bundled translation/s', $s );
		$this->assertMatchesRegularExpression( '/`default` text domain: WordPress core\'s official translation beats any supplementary strings Babilim provides/', $s );
	}

	public function test_external_services_names_the_constant_and_the_domain_the_plugin_defines(): void {
		$s = $this->section( 'External services' );
		$this->assertSame( 1, preg_match( "/define\\( 'BABILIM_API_BASE', '([^']+)' \\)/", $this->plugin_file, $m ) );
		$this->assertStringContainsString( '`BABILIM_API_BASE`', $s );
		$this->assertStringContainsString( '`' . $m[1] . '`', $s, 'the readme must name the same address as the constant' );
		$this->assertStringContainsString( 'Terms of Service', $s );
		$this->assertStringContainsString( 'Privacy Policy', $s );
	}

	public function test_external_services_says_nothing_is_sent_before_consent(): void {
		$s = $this->section( 'External services' );
		$this->assertMatchesRegularExpression( '/Nothing is sent before you consent/', $s );
		$this->assertMatchesRegularExpression( '/No request is made when the plugin is activated/', $s );
	}

	public function test_external_services_lists_every_field_the_consent_screen_shows(): void {
		$s      = $this->section( 'External services' );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-babilim-consent.php' );
		preg_match_all( "/'field'\\s*=>\\s*'([a-z_]+)'/", $source, $m );
		$this->assertGreaterThanOrEqual( 9, count( $m[1] ), 'the scan must find the consent screen fields' );
		foreach ( $m[1] as $field ) {
			$this->assertStringContainsString( "`{$field}`", $s, "the consent screen sends {$field}; External services must list it" );
		}
		$this->assertStringContainsString( 'up to 20', $s );
	}

	public function test_external_services_describes_the_user_initiated_requests(): void {
		$s = $this->section( 'External services' );
		foreach ( array( 'Requesting a translation', 'Downloading a translation', 'Reporting a problem', 'What is received' ) as $needle ) {
			$this->assertStringContainsString( $needle, $s );
		}
	}

	public function test_readme_gives_the_support_address_and_no_contact_placeholder_remains(): void {
		$faq = $this->section( 'Frequently Asked Questions' );
		$this->assertStringContainsString( 'you must contact us at hi@babilim.org to get it back', $faq );
		$this->assertSame( 1, preg_match( '/^= How do I contact you\? =\n(.*?)(?=^= |\z)/ms', $faq, $m ), 'the contact FAQ is missing' );
		$this->assertStringContainsString( 'hi@babilim.org', $m[1], 'the contact FAQ answer gives the address' );
		$this->assertStringNotContainsString( 'contact: TODO-OWNER', $this->readme );
	}

	/**
	 * Every field the owner must fill in by hand before release
	 * (Contributors, Tested up to, Terms of Service, Privacy Policy)
	 * carries an explicit, greppable TODO-OWNER placeholder rather than
	 * a value that merely looks plausible -- see `make release-check`,
	 * which refuses to let a release ship with any of these still
	 * present.
	 */
	public function test_owner_placeholders_are_explicit_and_greppable(): void {
		$headers = $this->headers();
		$this->assertSame( 'TODO-OWNER', $headers['Contributors'] );
		$this->assertSame( 'TODO-OWNER', $headers['Tested up to'] );
	}

	public function test_external_services_links_the_legal_pages(): void {
		$s = $this->section( 'External services' );
		$this->assertStringContainsString( 'Terms of Service: https://babilim.org/terms/', $s );
		$this->assertStringContainsString( 'Privacy Policy: https://babilim.org/privacy/', $s );
		$this->assertStringContainsString( 'Refund Policy: https://babilim.org/refund/', $s );
	}

	/**
	 * `make release-check` must actually refuse to ship while any
	 * TODO-OWNER placeholder remains -- proven against the real,
	 * current readme.txt (which does, during normal development) rather
	 * than a scratch copy, so this also proves `make test` itself is
	 * unaffected by the gate (this test runs as part of that same
	 * suite and does not fail).
	 */
	public function test_release_check_refuses_to_ship_with_todo_owner_placeholders(): void {
		$root = dirname( __DIR__, 2 );
		if ( false === strpos( $this->readme, 'TODO-OWNER' ) ) {
			$this->markTestSkipped( 'readme.txt has no TODO-OWNER placeholders left to gate on.' );
		}
		$output    = array();
		$exit_code = 0;
		// An empty locale list keeps the translation gate out of the way, so
		// this exercises the placeholder gate on its own.
		$locales = BABILIM_TEST_TMP_ROOT . '/no-locales-' . uniqid( '', true );
		file_put_contents( $locales, "# none\n" );
		exec( 'cd ' . escapeshellarg( $root ) . ' && make release-check L10N_LOCALES=' . escapeshellarg( $locales ) . ' 2>&1', $output, $exit_code );
		unlink( $locales );
		$text = implode( "\n", $output );
		$this->assertNotSame( 0, $exit_code, "make release-check must fail while placeholders remain:\n{$text}" );
		$this->assertStringContainsString( 'TODO-OWNER', $text );
	}

	/**
	 * readme must document the multisite uninstall behavior -- every
	 * site's own settings/credentials are cleaned, and the shared
	 * language-file folder is only ever purged once.
	 */
	public function test_faq_documents_multisite_uninstall_behavior(): void {
		$s = $this->section( 'Frequently Asked Questions' );
		$this->assertStringContainsString( 'multisite', $s );
		$this->assertMatchesRegularExpression( '/every site/i', $s );
		$this->assertMatchesRegularExpression( '/only ever purged once|purged once/i', $s );
	}

	public function test_no_quality_grading_or_verification_claims(): void {
		$this->assertDoesNotMatchRegularExpression( '/\b(?:grade[sd]?|grading|verified|verification|quality|accura(?:te|cy)|reviewed|certified|score[sd]?)\b/i', $this->readme );
	}

	public function test_readme_is_english_only_and_names_nothing_internal(): void {
		$this->assertSame( 0, preg_match( '/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{3040}-\x{30FF}]/u', $this->readme ), 'no CJK text' );
		$this->assertDoesNotMatchRegularExpression( '/https?:\/\/(?!(?:www\.gnu\.org|wordpress\.org|api\.babilim\.org|babilim\.org|www\.paddle\.com)\b)/i', $this->readme, 'only allow-listed domains may be linked' );
	}

	public function test_external_services_discloses_a_source_upload(): void {
		$s = $this->section( 'External services' );
		$this->assertStringContainsString( 'Uploading a component', $s );
		foreach ( array( 'entire', 'keys, logs or customer data', 'until you ask', 'AI', 'other sites', 'right to do so', 'Upload and request' ) as $needle ) {
			$this->assertStringContainsString( $needle, $s, $needle );
		}
		$this->assertStringContainsString( 'explicitly confirm', $this->section( 'Privacy' ) );
		$this->assertStringNotContainsString( 'web root', $s, 'the temporary location is described as it is, not as a guarantee' );
		$this->assertStringNotContainsString( 'blocks direct', $s, 'a .htaccess file is not a guarantee on every server' );
		$this->assertStringContainsString( 'Apache-compatible servers only', $s );
		foreach ( array( 'system temporary directory', 'WordPress\'s temporary directory', '.htaccess', '24 hours' ) as $needle ) {
			$this->assertStringContainsString( $needle, $s, $needle );
		}
	}
}
