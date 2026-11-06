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

	/** Matches any link whose host is not on the allow-list (used by the real assertion and its counter-examples). */
	private const LINK_RULE = '/https?:\/\/(?!(?:www\.gnu\.org|wordpress\.org|api\.babilim\.org|babilim\.org|www\.paddle\.com)\b|github\.com\/babilim-org\/babilim(?:\/|$|[\s)]))/im';

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

	private function faq_answer( string $question ): string {
		$this->assertSame( 1, preg_match( '/^= ' . preg_quote( $question, '/' ) . ' =\n(.*?)(?=^= |\z)/ms', $this->section( 'Frequently Asked Questions' ), $m ), "FAQ '{$question}' is missing" );
		return $m[1];
	}

	/**
	 * The directory's readme parser (Plugin Check 2.1.0,
	 * includes/Lib/Readme/Parser.php) folds every section (an "==" or "##"
	 * heading) that is not in its expected_sections list into other_notes
	 * (:396-416), appends other_notes to the Description (:437-440), then
	 * trims each section to maximum_field_lengths['section'] = 2500 words
	 * (:159, :450-459, :568-576; only faq and changelog get 5000). Rebuild
	 * that merged Description the same way (directory_sections(),
	 * directory_word_count()) and keep it under 2400 words, so the
	 * directory page never cuts it.
	 */
	public function test_the_description_and_the_other_sections_fit_the_directory_word_limit(): void {
		$sections = self::directory_sections( $this->readme );
		$words    = self::directory_word_count( $sections['description'] ?? '' );
		$this->assertGreaterThan( 300, $words, 'the Description must be found and counted' );
		$this->assertLessThanOrEqual( 2400, $words, 'the Description plus every non-standard section is cut by the directory at 2500 words; move a section into the FAQ' );
	}

	/**
	 * The readme's sections the way the Parser reads them (:375-440): after
	 * the short description, a line whose trimmed text starts with "==" or
	 * with "##" (not "###") opens a section titled by the line trimmed of
	 * "#= \t"; its name is the lowercased title with spaces as "_", then
	 * aliased; an unknown name goes to other_notes behind "<h3>title</h3>";
	 * blank lines add "\n", other lines add themselves and "\n"; each
	 * section is trimmed when the next one opens; other_notes is appended
	 * to the Description after "\n".
	 *
	 * @return array<string,string>
	 */
	private static function directory_sections( string $readme ): array {
		$expected = array( 'description', 'installation', 'faq', 'screenshots', 'changelog', 'upgrade_notice', 'other_notes' );
		$aliases  = array(
			'frequently_asked_questions' => 'faq',
			'change_log'                 => 'changelog',
			'screenshot'                 => 'screenshots',
		);
		$is_section = static function ( string $trimmed ): bool {
			return ( '=' === ( $trimmed[0] ?? '' ) && '=' === ( $trimmed[1] ?? '' ) )
				|| ( '#' === ( $trimmed[0] ?? '' ) && '#' === ( $trimmed[1] ?? '' ) && '#' !== ( $trimmed[2] ?? '' ) );
		};
		$lines = preg_split( '/\R/u', $readme );
		// The Parser takes the first non-blank line as the name, then the
		// headers and the short description up to the first section line.
		while ( array() !== $lines && '' === trim( (string) $lines[0] ) ) {
			array_shift( $lines );
		}
		array_shift( $lines );
		while ( array() !== $lines && ! $is_section( trim( (string) $lines[0] ) ) ) {
			array_shift( $lines );
		}
		$sections = array_fill_keys( $expected, '' );
		$current  = '';
		$name     = '';
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				$current .= "\n";
				continue;
			}
			if ( $is_section( $trimmed ) ) {
				if ( '' !== $name ) {
					$sections[ $name ] .= trim( $current );
				}
				$current = '';
				$title   = trim( $line, "#= \t" );
				$name    = strtolower( str_replace( ' ', '_', $title ) );
				$name    = $aliases[ $name ] ?? $name;
				if ( ! in_array( $name, $expected, true ) ) {
					$current .= '<h3>' . $title . '</h3>';
					$name     = 'other_notes';
				}
				continue;
			}
			$current .= $line . "\n";
		}
		if ( '' !== $name ) {
			$sections[ $name ] .= trim( $current );
		}
		$sections = array_filter( $sections );
		if ( isset( $sections['other_notes'] ) ) {
			$sections['description'] = ( $sections['description'] ?? '' ) . "\n" . $sections['other_notes'];
			unset( $sections['other_notes'] );
		}
		return $sections;
	}

	/**
	 * The Parser's own count (:568-576): pieces of a split on whitespace
	 * that keeps the whitespace, so `pieces < 2 * limit` means "not cut".
	 * Returned as words: ceil(pieces / 2).
	 */
	private static function directory_word_count( string $text ): int {
		return (int) ceil( count( preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE ) ) / 2 );
	}

	public function test_the_section_reader_follows_the_parser_on_odd_headings(): void {
		$readme = "=== X ===\nStable tag: 1.0\n\nShort.\n\n== Description ==\nOne two.\n\n## Odd ##  \nThree four five.\n\n### Not a section\nSix.\n == Spaced == \nSeven.\n== FAQ ==\nEight.\n";
		$sections = self::directory_sections( $readme );
		$this->assertSame( "One two.\n<h3>Odd</h3>Three four five.\n\n### Not a section\nSix.<h3>Spaced</h3>Seven.", $sections['description'] );
		$this->assertSame( 'Eight.', $sections['faq'] );
		$this->assertSame( 2, self::directory_word_count( 'One two.' ) );
		$this->assertSame( 3, self::directory_word_count( ' a b' ), 'a leading space is a piece of its own, as in the Parser' );
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
		$s = $this->faq_answer( 'How are translations loaded?' );
		$this->assertMatchesRegularExpression( '/override in `custom\/`.*beats a translation Babilim provides.*beats an official WordPress\.org language pack or the plugin\'s or theme\'s own bundled translation/s', $s );
		$this->assertMatchesRegularExpression( '/`default` text domain: WordPress core\'s official translation beats any supplementary strings Babilim provides/', $s );
		$this->assertStringNotContainsString( '"How translations are loaded"', $this->readme, 'point at the FAQ question, not the old section' );
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

	public function test_contributors_and_tested_up_to_are_real_values(): void {
		$h = $this->headers();
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+(, ?[a-z0-9_-]+)*$/', $h['Contributors'], 'wp.org usernames' );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $h['Tested up to'], 'major.minor only' );
		$this->assertTrue( version_compare( $h['Tested up to'], $h['Requires at least'], '>=' ) );
		$this->assertStringNotContainsString( 'TODO-OWNER', $this->readme );
	}

	public function test_screenshot_captions_match_the_screenshot_files_one_to_one(): void {
		$s = $this->section( 'Screenshots' );
		preg_match_all( '/^(\d+)\. \S/m', $s, $m );
		$files = glob( dirname( __DIR__, 2 ) . '/.wordpress-org/screenshot-*.png' );
		$this->assertSame( range( 1, count( $files ) ), array_map( 'intval', $m[1] ) );
	}

	public function test_external_services_links_the_legal_pages(): void {
		$s = $this->section( 'External services' );
		$this->assertStringContainsString( 'Terms of Service: https://babilim.org/terms/', $s );
		$this->assertStringContainsString( 'Privacy Policy: https://babilim.org/privacy/', $s );
		$this->assertStringContainsString( 'Refund Policy: https://babilim.org/refund/', $s );
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

	public function test_faq_states_the_limit_wording_precisely_and_that_a_request_takes_the_slot(): void {
		$s = $this->section( 'Frequently Asked Questions' );
		$this->assertStringContainsString( 'enforces no limit of its own', $s );
		$this->assertStringNotContainsString( 'no limit logic', $s );
		$this->assertStringContainsString( 'takes a slot when you request it', $s );
		$this->assertStringContainsString( 'even if the service already has a translation', $s );
	}

	public function test_the_changelog_describes_the_refusal_handling_and_the_label_as_they_behave(): void {
		$s = $this->section( 'Changelog' );
		$this->assertStringContainsString( 'is not asked for again while the service reports the same thing', $s );
		$this->assertStringContainsString( 'tried again once a week', $s );
		$this->assertStringNotContainsString( 'until the next sync shows a new status', $s );
		$this->assertStringContainsString( 'A cell with nothing installed is labelled "Not installed"', $s );
	}

	public function test_faq_discloses_the_free_allowance_and_the_paid_conditions(): void {
		$s = $this->section( 'Frequently Asked Questions' );
		$this->assertStringContainsString( 'one theme and two plugins', $s );
		$this->assertStringContainsString( 'not listed on WordPress.org', $s );
		$this->assertStringContainsString( 'may change', $s );
	}

	public function test_no_quality_grading_or_verification_claims(): void {
		// Registering and verifying the site itself is not a claim about translation quality.
		$text = str_replace( array( 'after the site is verified', 'registration and verification' ), '', $this->readme );
		$this->assertDoesNotMatchRegularExpression( '/\b(?:grade[sd]?|grading|verified|verification|quality|accura(?:te|cy)|reviewed|certified|score[sd]?)\b/i', $text );
	}

	public function test_readme_is_english_only_and_names_nothing_internal(): void {
		$this->assertSame( 0, preg_match( '/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{3040}-\x{30FF}]/u', $this->readme ), 'no CJK text' );
		$this->assertDoesNotMatchRegularExpression( self::LINK_RULE, $this->readme, 'only allow-listed domains may be linked' );
	}

	public function test_the_github_readme_links_both_support_channels_and_its_images_exist(): void {
		$root   = dirname( __DIR__, 2 );
		$readme = (string) file_get_contents( $root . '/README.md' );
		$this->assertNotSame( '', $readme );
		$this->assertStringContainsString( 'https://github.com/babilim-org/babilim/issues', $readme );
		$this->assertStringContainsString( 'https://wordpress.org/support/plugin/babilim/', $readme );
		$this->assertSame( 0, preg_match( '/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{3040}-\x{30FF}]/u', $readme ), 'no CJK text' );
		$this->assertDoesNotMatchRegularExpression( '/\b(?:grade[sd]?|grading|verified|verification|quality|accura(?:te|cy)|reviewed|certified|score[sd]?)\b/i', $readme );
		preg_match_all( '/\]\((\.wordpress-org\/[^)\s]+)\)/', $readme, $m );
		$this->assertGreaterThanOrEqual( 5, count( $m[1] ), 'banner, icon and three screenshots' );
		foreach ( $m[1] as $path ) {
			$this->assertFileExists( $root . '/' . $path );
		}
	}

	public function test_readme_txt_links_the_github_issues_page_next_to_the_other_channels(): void {
		$this->assertStringContainsString( 'https://github.com/babilim-org/babilim/issues', $this->readme );
		$this->assertStringContainsString( 'https://wordpress.org/support/plugin/babilim/', $this->readme );
	}

	public function test_the_description_opens_with_a_short_bullet_list(): void {
		$blocks = explode( "\n\n", trim( $this->section( 'Description' ) ) );
		$lines  = explode( "\n", $blocks[0] );
		$this->assertGreaterThanOrEqual( 3, count( $lines ) );
		$this->assertLessThanOrEqual( 5, count( $lines ) );
		foreach ( $lines as $line ) {
			$this->assertStringStartsWith( '* ', $line, 'the Description opens with a bullet list' );
			$this->assertLessThanOrEqual( 150, strlen( $line ), 'each opening bullet is one short sentence: ' . $line );
		}
	}

	public function test_readme_paragraphs_are_short(): void {
		// The directory page renders Markdown, where single newlines join
		// into one paragraph. Measure rendered paragraphs: blank lines
		// separate them and every list item counts as its own.
		$body       = (string) strstr( $this->readme, "\n== " );
		$paragraphs = array();
		$current    = '';
		foreach ( explode( "\n", $body ) as $line ) {
			if ( '' === trim( $line ) ) {
				$paragraphs[] = $current;
				$current      = '';
			} elseif ( 1 === preg_match( '/^(?:\* |\d+\. )/', $line ) ) {
				$paragraphs[] = $current;
				$current      = $line;
			} else {
				$current .= ( '' === $current ? '' : ' ' ) . $line;
			}
		}
		$paragraphs[] = $current;
		foreach ( $paragraphs as $paragraph ) {
			$this->assertLessThanOrEqual( 400, strlen( $paragraph ), 'readme.txt paragraph is longer than 400 characters: split it with a blank line or make it a list: ' . substr( $paragraph, 0, 60 ) );
		}
	}

	public function test_the_github_allowance_covers_only_this_repository(): void {
		$re = self::LINK_RULE;
		$this->assertSame( 0, preg_match( $re, 'see https://github.com/babilim-org/babilim/issues now' ) );
		$this->assertSame( 0, preg_match( $re, 'https://github.com/babilim-org/babilim' ) );
		$this->assertSame( 1, preg_match( $re, 'https://github.com/babilim-org/babilim-other/issues' ) );
		$this->assertSame( 1, preg_match( $re, 'https://github.com/other/babilim' ) );
	}

	public function test_the_submission_document_asks_the_directory_team_about_each_disclosed_behavior(): void {
		$doc = file_get_contents( dirname( __DIR__, 2 ) . '/docs/wporg-submission.md' );
		foreach ( array( 'plugins@wordpress.org', 'WP_Translation_File::transform', 'Guideline 8', 'Guideline 6', 'Guideline 5', 'merchant of record', 'every .php and .js file', 'Delay auto-updates for 24 hours', 'Release Confirmation', 'Test Preview' ) as $needle ) {
			$this->assertStringContainsString( $needle, $doc, $needle );
		}
	}

	public function test_readme_describes_uninstall_report_and_privacy_exactly(): void {
		$root = dirname( __DIR__, 2 );
		$desc = $this->section( 'Description' );
		foreach ( array( 'Two separate settings', 'registration number', 'custom/' ) as $needle ) {
			$this->assertStringContainsString( $needle, $desc, $needle );
		}
		$faq = $this->section( 'Frequently Asked Questions' );
		$this->assertStringNotContainsString( 'runs no plugin code', $faq );
		$this->assertStringContainsString( 'has no screen of its own', $faq );
		$this->assertStringContainsString( 'does not carry the original string', $this->section( 'External services' ) );
		$privacy = $this->faq_answer( 'What does the plugin do with my data?' );
		foreach ( array( 'plugin itself never', 'free-text fields' ) as $needle ) {
			$this->assertStringContainsString( $needle, $privacy, $needle );
		}
		$changelog = $this->section( 'Changelog' );
		$this->assertSame( 1, preg_match( '/^= 1\.1\.0 =\n(.*?)(?=^= |\z)/ms', $changelog, $m ), 'a 1.1.0 changelog entry exists' );
		foreach ( array( 'code and description files', 'outside the website' ) as $needle ) {
			$this->assertStringContainsString( $needle, $m[1], $needle );
		}
		$this->assertStringNotContainsString( 'decided by the Babilim service', $m[1], 'the 1.1.0 entry no longer describes a service-side install rule' );
		$this->assertStringContainsString( 'and a smaller source upload', $this->section( 'Upgrade Notice' ) );
		$files = array_merge( array( $root . '/readme.txt', $root . '/docs/development.md' ), (array) glob( $root . '/*.md' ) );
		foreach ( $files as $file ) {
			$text = (string) file_get_contents( $file );
			foreach ( array( 'removed after 24 hours', 'older than 24 hours' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $text, basename( $file ) . " must not promise a removal time: {$needle}" );
			}
		}
		$dev = (string) file_get_contents( $root . '/docs/development.md' );
		$this->assertStringContainsString( 'interrupted upload', $dev );
		$this->assertStringNotContainsString( 'interrupted site', $dev );
	}

	public function test_the_readme_never_says_the_service_decides_which_translations_are_installed(): void {
		$this->assertStringNotContainsString( 'The Babilim service decides which available translations', $this->readme );
		$this->assertStringNotContainsString( 'may be installed automatically', $this->readme );
		$this->assertStringNotContainsString( 'normally only allows', $this->readme );
	}

	public function test_the_1_2_0_changelog_describes_automatic_installs_without_a_plan_rule(): void {
		$changelog = $this->section( 'Changelog' );
		$this->assertSame( 1, preg_match( '/^= 1\.2\.0 =\n(.*?)(?=^= |\z)/ms', $changelog, $m ), 'a 1.2.0 changelog entry exists' );
		$this->assertStringContainsString( 'same eligibility rules on every service plan', $m[1] );
		$this->assertStringContainsString( 'no longer counted as a failed install', $m[1] );
		$this->assertStringContainsString( 'lists every request the plugin makes', $m[1] );
		$this->assertSame( 1, preg_match( '/^= 1\.2\.0 =\n(.*?)(?=^= |\z)/ms', $this->section( 'Upgrade Notice' ), $u ), 'a 1.2.0 upgrade notice exists' );
		$this->assertStringContainsString( 'same rules on every service plan', $u[1] );
		$this->assertDoesNotMatchRegularExpression( '/On the free plan only components that already use a free slot/', $changelog );
	}

	public function test_automatic_installs_are_described_as_one_rule_in_external_services(): void {
		$s = $this->section( 'External services' );
		$this->assertStringContainsString( 'applies the same eligibility rules on every service plan', $s );
		$this->assertStringNotContainsString( 'The Babilim service decides which available translations', $s );
		$this->assertSame( 1, preg_match( '/^\*\*Automatic downloads during the sync\.\*\*\n(.*?)(?=^\*\*)/ms', $s, $m ), 'the Automatic downloads block exists' );
		$this->assertStringContainsString( 'Automatic and one-click installs use the same rule', $m[1] );
		$this->assertStringNotContainsString( 'on the free plan', strtolower( $m[1] ) );
		$this->assertStringNotContainsString( 'free-plan slot', $m[1] );
	}

	/**
	 * The path literals of every send()/transport() line of $client:
	 * each quoted string that starts with "/" (a /v1/ prefix or a
	 * suffix after an id), with any characters a path may have.
	 *
	 * @return string[]
	 */
	private static function client_request_paths( string $client ): array {
		$paths = array();
		foreach ( explode( "\n", $client ) as $line ) {
			if ( 1 !== preg_match( '/->(send|transport)\(/', $line ) ) {
				continue;
			}
			preg_match_all( "~'(/[A-Za-z0-9_./-]+)'~", $line, $m );
			foreach ( $m[1] as $path ) {
				$paths[] = $path;
			}
		}
		return array_values( array_unique( $paths ) );
	}

	public function test_the_path_scan_finds_suffixes_and_hyphens(): void {
		$client = "\t\treturn \$this->send( 'POST', '/v1/sites/' . rawurlencode( \$id ) . '/rotate-token', array(), true );\n"
			. "\t\t\$url = '/not/a/request';\n";
		$this->assertSame( array( '/v1/sites/', '/rotate-token' ), self::client_request_paths( $client ) );
	}

	public function test_external_services_lists_every_endpoint_the_client_calls(): void {
		$s = $this->section( 'External services' );
		foreach ( array( '/v1/sites', '/verify', '/rotate-token', 'GET /v1/sites', '/checkout', '/restore', '/restore-code', '/inventory', '/v1/downloads', '/v1/packages/', '/v1/requests', '/v1/uploads/', '/parts/', '/complete', 'DELETE', '/v1/meta/locales', '/v1/feedback', '/v1/error-reports' ) as $needle ) {
			$this->assertStringContainsString( $needle, $s, "External services must list {$needle}" );
		}
		// Every path piece the client source puts in a request must be
		// covered too: the /v1/ prefixes and the suffixes after an id.
		$paths = self::client_request_paths( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-babilim-api-client.php' ) );
		foreach ( array( '/v1/sites', '/verify', '/rotate-token', '/restore-code', '/parts/', '/complete', '/v1/meta/locales' ) as $expected ) {
			$this->assertContains( $expected, $paths, 'the scan finds ' . $expected );
		}
		foreach ( $paths as $path ) {
			$this->assertStringContainsString( rtrim( $path, '/' ), $s, "the client calls {$path}" );
		}
		$this->assertStringContainsString( 'right after registration; while the service handles it, it calls your site\'s challenge endpoint', $s );
	}

	public function test_the_sync_and_registration_timings_are_complete(): void {
		$s = $this->section( 'External services' );
		$this->assertSame( 1, preg_match( '/^A sync runs[^\n]*$/m', $s, $m ), 'the sync timing sentence exists' );
		foreach ( array( 'about 30 seconds after a plugin or theme is activated, deactivated, updated or switched', 'in the background when a check runs out of time' ) as $needle ) {
			$this->assertStringContainsString( $needle, $m[0], $needle );
		}
		$this->assertSame( 1, preg_match( '/^\* `POST \/v1\/sites`:[^\n]*$/m', $s, $r ), 'the POST /v1/sites entry exists' );
		$this->assertStringContainsString( 'retry finds the earlier challenge expired and registers the site anew', $r[0] );
	}

	public function test_the_site_poll_is_described_as_the_client_runs_it(): void {
		$s = $this->section( 'External services' );
		$this->assertSame( 1, preg_match( '/^\* `GET \/v1\/sites\/\{id\}`:[^\n]*$/m', $s, $m ), 'the GET /v1/sites/{id} entry exists' );
		foreach ( array( 'Upgrade this site', 'every 5 seconds', '10 minutes', 'paid plan', 'license kind' ) as $needle ) {
			$this->assertStringContainsString( $needle, $m[0], $needle );
		}
		$this->assertStringNotContainsString( 'on the Settings screen and while a checkout is open', $s );
		$this->assertStringNotContainsString( 'Opening Settings sends nothing', $s );
		$this->assertStringContainsString( 'Opening Settings does not send it', $m[0] );
		$this->assertStringNotContainsString( 'The request body is empty', $s, 'the client sends an empty JSON object' );
		$this->assertStringNotContainsString( 'Like the other requests made once', $this->readme );
	}

	public function test_the_faq_and_the_write_locations_agree(): void {
		$this->assertSame( 1, preg_match( '/^= Does this plugin modify my site\'s files or code\? =\n(.*?)(?=^= |\z)/ms', $this->section( 'Frequently Asked Questions' ), $m ) );
		foreach ( array( 'wp-content/languages/babilim/', 'temporary zip', 'BABILIM_TEMP_DIR', 'outside the website', 'External services' ) as $needle ) {
			$this->assertStringContainsString( $needle, $m[1], $needle );
		}
		$this->assertStringNotContainsString( 'It only writes translation data files into its own', $m[1] );
	}

	public function test_the_authorization_sentence_names_the_unauthenticated_requests(): void {
		$s = $this->section( 'External services' );
		$this->assertSame( 1, preg_match( '/^Requests made after the site is verified carry.*?(?=^Every request carries)/ms', $s, $m ), 'the Authorization sentence exists' );
		foreach ( array( 'Authorization', 'registration', 'verification', 'locale list', 'package' ) as $needle ) {
			$this->assertStringContainsString( $needle, $m[0], $needle );
		}
		$this->assertStringNotContainsString( 'Every request also carries', $s );
	}

	public function test_the_faq_names_both_report_entries(): void {
		$faq = $this->section( 'Frequently Asked Questions' );
		$this->assertStringContainsString( '**Report translation** entry in the admin toolbar, or **Report a problem** on the Translations screen', $faq );
		$this->assertSame( 2, substr_count( $faq, '**Report a problem** on the Translations screen' ) );
	}

	public function test_the_submission_document_leaves_auto_install_to_the_service(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/wporg-submission.md' );
		$this->assertStringContainsString( 'same eligibility rules on every service plan', $doc );
		$this->assertStringNotContainsString( 'acts on the license kind without a click', $doc );
	}

	public function test_external_services_discloses_a_source_upload(): void {
		$s = $this->section( 'External services' );
		$this->assertStringContainsString( 'Uploading a component', $s );
		foreach ( array( '.php and .js', 'block.json', 'readme.txt, readme.md, style.css and theme.json', 'main folder', 'common secrets', 'Code files can still contain', 'until you ask', 'AI', 'other sites', 'right to do so', 'Upload and request' ) as $needle ) {
			$this->assertStringContainsString( $needle, $s, $needle );
		}
		foreach ( array( 'entire', 'never sent', 'configuration files are not' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $s, $needle );
		}
		$this->assertStringContainsString( 'explicitly confirm', $this->faq_answer( 'What does the plugin do with my data?' ) );
		$this->assertStringNotContainsString( 'web root', $s, 'the temporary location is described as it is, not as a guarantee' );
		foreach ( array( 'system temporary directory', 'BABILIM_TEMP_DIR', 'nothing is packaged or uploaded', 'host' ) as $needle ) {
			$this->assertStringContainsString( $needle, $s, $needle );
		}
		foreach ( array( '.htaccess', 'Apache', '24 hours', 'daily job', 'blocks direct', 'WordPress\'s temporary directory' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $s, $needle );
		}
	}
}
