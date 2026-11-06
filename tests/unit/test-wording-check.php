<?php
/**
 * scripts/wording-check.php: the release gate that compares the messages
 * of a built zip one by one against the POT and the retired sentences.
 * Each test builds a small zip and runs the real checker on it.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/wording-check.php';

final class Test_Wording_Check extends TestCase {

	private const POT = <<<'POT'
msgid ""
msgstr ""
"Project-Id-Version: Babilim 1.2.0\n"

msgid "Update available"
msgstr ""

msgctxt "translation status"
msgid "Installed"
msgstr ""

msgid "Sweep complete: %d file deleted."
msgid_plural "Sweep complete: %d files deleted."
msgstr[0] ""
msgstr[1] ""

POT;

	/** @var string[] */
	private $cleanup = array();

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			@unlink( $path );
		}
		$this->cleanup = array();
	}

	/**
	 * @param array<string,string|array<int,string>> $entries Entries for a .mo / .l10n.php.
	 * @return array<string,string> Files of a clean zip, relative to the plugin folder.
	 */
	private function clean_files( array $entries = array() ): array {
		$entries = array() === $entries ? $this->clean_entries() : $entries;
		return array(
			'babilim.php'                   => "<?php\n// Babilim\n",
			'readme.txt'                    => "=== Babilim ===\n\nThe Babilim service has a free plan; the paid plan is a one-time purchase.\n",
			'languages/babilim.pot'         => self::POT,
			'languages/babilim-de_DE.mo'    => Babilim_Mo_Writer::write( $entries, array( 'Language' => 'de_DE' ) ),
			'languages/babilim-de_DE.l10n.php' => $this->l10n_php( $entries ),
		);
	}

	/**
	 * @return array<string,string|array<int,string>>
	 */
	private function clean_entries(): array {
		return array(
			'Update available'                     => 'Aktualisierung verfuegbar',
			"translation status\x04Installed"      => 'Installiert',
			"Sweep complete: %d file deleted.\0Sweep complete: %d files deleted." => array( 'Bereinigung: %d Datei', 'Bereinigung: %d Dateien' ),
		);
	}

	/**
	 * @param array<string,string|array<int,string>> $entries Entries.
	 */
	private function l10n_php( array $entries, ?string $plural_forms = null ): string {
		$messages = array();
		foreach ( $entries as $key => $value ) {
			// A .l10n.php keeps only the singular msgid as the key of a plural entry.
			$messages[ explode( "\0", (string) $key )[0] ] = is_array( $value ) ? implode( "\0", $value ) : $value;
		}
		$data = array(
			'domain'   => null,
			'language' => 'de_DE',
			'messages' => $messages,
		);
		if ( null !== $plural_forms ) {
			$data = array( 'plural-forms' => $plural_forms ) + $data;
		}
		return "<?php\nreturn " . var_export( $data, true ) . ";\n";
	}

	public function test_a_l10n_php_of_a_language_with_plurals_must_keep_the_pots_plurals(): void {
		$entries = array_merge( array_slice( $this->clean_entries(), 0, 2 ), array( 'Sweep complete: %d file deleted.' => 'Bereinigung' ) );
		$files   = $this->clean_files();

		$files['languages/babilim-de_DE.l10n.php'] = $this->l10n_php( $this->clean_entries(), 'nplurals=2; plural=n != 1;' );
		$this->assertSame( array(), $this->run_check( $files ), 'control: the plural is there' );

		$files['languages/babilim-de_DE.l10n.php'] = $this->l10n_php( $entries, 'nplurals=2; plural=n != 1;' );
		$this->assertSame( array( 'babilim/languages/babilim-de_DE.l10n.php:plural-mismatch:Sweep complete: %d file deleted.' ), $this->run_check( $files ) );

		// One plural form compiles a plural to one plain string; no header says nothing either way.
		$files['languages/babilim-de_DE.l10n.php'] = $this->l10n_php( $entries, 'nplurals=1; plural=0;' );
		$this->assertSame( array(), $this->run_check( $files ) );
		$files['languages/babilim-de_DE.l10n.php'] = $this->l10n_php( $entries );
		$this->assertSame( array(), $this->run_check( $files ) );
	}

	/**
	 * @param array<string,string> $files Files under a babilim/ folder.
	 */
	private function zip( array $files ): string {
		$path = tempnam( BABILIM_TEST_TMP_ROOT, 'wording-test-' ) . '.zip';
		$zip  = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		foreach ( $files as $name => $bytes ) {
			$zip->addFromString( 'babilim/' . $name, $bytes );
		}
		$zip->close();
		$this->cleanup[] = $path;
		return $path;
	}

	/**
	 * @param array<string,string> $files Files.
	 * @return string[]
	 */
	private function run_check( array $files ): array {
		$result = babilim_wording_check_zip( $this->zip( $files ) );
		$this->assertIsArray( $result, 'the zip is readable' );
		return $result;
	}

	public function test_a_clean_fixture_passes(): void {
		$this->assertSame( array(), $this->run_check( $this->clean_files() ) );
	}

	public function test_a_context_entry_is_keyed_with_its_context(): void {
		$entries                = $this->clean_entries();
		unset( $entries["translation status\x04Installed"] );
		$entries['Installed']   = 'Installiert';
		$result                 = $this->run_check( $this->clean_files( $entries ) );
		$this->assertContains( 'babilim/languages/babilim-de_DE.mo:not-in-pot:Installed', $result );
		$this->assertContains( 'babilim/languages/babilim-de_DE.l10n.php:not-in-pot:Installed', $result );
	}

	public function test_a_plural_entry_is_keyed_by_its_singular(): void {
		// The clean fixture already holds a plural in the .mo (msgid NUL msgid_plural).
		$mo = babilim_wording_parse_mo( $this->clean_files()['languages/babilim-de_DE.mo'] );
		$this->assertSame( true, $mo['Sweep complete: %d file deleted.'] );
		$this->assertSame( array(), $this->run_check( $this->clean_files() ) );

		// The .mo carries the same message as a singular only: the POT says plural.
		$files = $this->clean_files();
		$files['languages/babilim-de_DE.mo'] = Babilim_Mo_Writer::write(
			array_merge( array_slice( $this->clean_entries(), 0, 2 ), array( 'Sweep complete: %d file deleted.' => 'Bereinigung' ) ),
			array( 'Language' => 'de_DE' )
		);
		$result = $this->run_check( $files );
		$this->assertSame( array( 'babilim/languages/babilim-de_DE.mo:plural-mismatch:Sweep complete: %d file deleted.' ), $result );
	}

	public function test_a_single_plural_form_language_may_hold_a_plural_as_one_plain_string(): void {
		$files                               = $this->clean_files();
		$files['languages/babilim-de_DE.mo'] = Babilim_Mo_Writer::write(
			array_merge( array_slice( $this->clean_entries(), 0, 2 ), array( 'Sweep complete: %d file deleted.' => 'Bereinigung' ) ),
			array(
				'Language'     => 'de_DE',
				'Plural-Forms' => 'nplurals=1; plural=0;',
			)
		);
		$this->assertSame( array(), $this->run_check( $files ) );
	}

	public function test_the_mo_header_entry_is_ignored(): void {
		$mo = babilim_wording_parse_mo( Babilim_Mo_Writer::write( array(), array( 'Language' => 'de_DE' ) ) );
		$this->assertSame( array(), $mo, 'only the header exists and it has no key' );
		$files                               = $this->clean_files();
		$files['languages/babilim-de_DE.mo'] = Babilim_Mo_Writer::write( array(), array( 'Language' => 'de_DE' ) );
		$this->assertSame( array(), $this->run_check( $files ) );
	}

	public function test_a_forbidden_message_in_a_mo_fails(): void {
		$entries                       = $this->clean_entries();
		$entries['Needs a paid license'] = 'x';
		$files                         = $this->clean_files();
		$files['languages/babilim-de_DE.mo'] = Babilim_Mo_Writer::write( $entries, array( 'Language' => 'de_DE' ) );
		$result = $this->run_check( $files );
		$this->assertContains( 'babilim/languages/babilim-de_DE.mo:forbidden-message:Needs a paid license', $result );
		foreach ( $result as $line ) {
			$this->assertStringStartsWith( 'babilim/languages/babilim-de_DE.mo:', $line, 'only the injected file is listed' );
		}
	}

	public function test_a_forbidden_message_in_a_l10n_php_fails(): void {
		$entries                         = $this->clean_entries();
		$entries['Needs a paid license'] = 'x';
		$files                           = $this->clean_files();
		$files['languages/babilim-de_DE.l10n.php'] = $this->l10n_php( $entries );
		$result = $this->run_check( $files );
		$this->assertContains( 'babilim/languages/babilim-de_DE.l10n.php:forbidden-message:Needs a paid license', $result );
		foreach ( $result as $line ) {
			$this->assertStringStartsWith( 'babilim/languages/babilim-de_DE.l10n.php:', $line );
		}
	}

	public function test_a_forbidden_message_under_a_context_is_still_caught(): void {
		$entries                              = $this->clean_entries();
		$entries["ctx\x04Needs a paid license"] = 'x';
		$files                                = $this->clean_files();
		$files['languages/babilim-de_DE.mo']  = Babilim_Mo_Writer::write( $entries, array( 'Language' => 'de_DE' ) );
		$result = $this->run_check( $files );
		$this->assertContains( 'babilim/languages/babilim-de_DE.mo:forbidden-message:Needs a paid license', $result );
	}

	public function test_a_forbidden_message_in_the_pot_fails(): void {
		$files                          = $this->clean_files();
		$files['languages/babilim.pot'] = self::POT . "\nmsgid \"Free quota used\"\nmsgstr \"\"\n";
		$this->assertContains( 'babilim/languages/babilim.pot:forbidden-message:Free quota used', $this->run_check( $files ) );
	}

	public function test_a_message_outside_the_pot_fails(): void {
		$entries                      = $this->clean_entries();
		$entries['Not in the source'] = 'x';
		$files                        = $this->clean_files();
		$files['languages/babilim-de_DE.l10n.php'] = $this->l10n_php( $entries );
		$this->assertSame( array( 'babilim/languages/babilim-de_DE.l10n.php:not-in-pot:Not in the source' ), $this->run_check( $files ) );
	}

	public function test_the_user_setting_named_auto_install_is_allowed_but_reading_it_from_a_cell_is_not(): void {
		$files                    = $this->clean_files();
		$files['admin/admin.php'] = "<?php\n\$on = get_option( 'babilim_auto_install' );\n\$name = 'auto_install';\n";
		$this->assertSame( array(), $this->run_check( $files ) );

		$files['includes/installer.php'] = "<?php\nif ( empty( \$cell [ 'auto_install' ] ) ) { return; }\n";
		$result                          = $this->run_check( $files );
		$this->assertCount( 1, $result );
		$this->assertStringStartsWith( 'babilim/includes/installer.php:source-reads-cell-auto-install:', $result[0] );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function renamed_auto_install_reads(): array {
		return array(
			'other variable'      => array( "if ( empty( \$entry['auto_install'] ) ) { return; }" ),
			'spaced brackets'     => array( "\$flag = \$locale_entry [ 'auto_install' ];" ),
			'double quotes'       => array( 'if ( isset( $x["auto_install"] ) ) {}' ),
			'array access result' => array( "\$ok = (bool) \$state['components'][0]['locales']['de_DE']['auto_install'];" ),
			'property'            => array( "if ( \$build->auto_install ) {}" ),
			'spaced property'     => array( "if ( \$build -> auto_install ) {}" ),
			'array_key_exists'    => array( "if ( array_key_exists( 'auto_install', \$cell ) ) {}" ),
			'key_exists'          => array( "if ( key_exists( \"auto_install\", \$cell ) ) {}" ),
			'array_column'        => array( "\$flags = array_column( \$cells, 'auto_install', 'build_id' );" ),
			'wp_list_pluck'       => array( "\$flags = wp_list_pluck( \$cells, 'auto_install' );" ),
			'qualified pluck'     => array( "\$flags = \\wp_list_pluck(\n	\$cells,\n	'auto_install'\n);" ),
			'filter object list'  => array( "\$on = wp_filter_object_list( \$cells, array( 'auto_install' => true ) );" ),
		);
	}

	/**
	 * @dataProvider renamed_auto_install_reads
	 */
	public function test_reading_auto_install_under_any_variable_name_fails( string $line ): void {
		$files                       = $this->clean_files();
		$files['includes/other.php'] = "<?php\n" . $line . "\n";
		$result                      = $this->run_check( $files );
		$this->assertCount( 1, $result, $line );
		$this->assertStringStartsWith( 'babilim/includes/other.php:source-reads-cell-auto-install:', $result[0] );
	}

	public function test_the_option_constant_and_its_option_name_are_not_a_cell_read(): void {
		$files                        = $this->clean_files();
		$files['includes/option.php'] = "<?php\nconst OPTION_AUTO_INSTALL = 'babilim_auto_install';\n\$v = get_option( self::OPTION_AUTO_INSTALL, true );\n\$w = self::field( 'auto_install' );\n";
		$this->assertSame( array(), $this->run_check( $files ) );
	}

	public function test_the_no_translation_label_is_forbidden_in_source(): void {
		$files                    = $this->clean_files();
		$files['admin/admin.php'] = "<?php\n__( 'No translation', 'babilim' );\n";
		$this->assertSame( array( 'babilim/admin/admin.php:source-forbidden-message:No translation' ), $this->run_check( $files ) );
	}

	public function test_a_forbidden_message_as_a_source_literal_fails(): void {
		$files                    = $this->clean_files();
		$files['admin/admin.php'] = "<?php\n__( 'Needs a paid license', 'babilim' );\n";
		$this->assertSame( array( 'babilim/admin/admin.php:source-forbidden-message:Needs a paid license' ), $this->run_check( $files ) );
	}

	public function test_free_plan_in_the_readme_is_allowed_but_the_old_restriction_sentence_is_not(): void {
		$files               = $this->clean_files();
		$files['readme.txt'] = "=== Babilim ===\n\nThe free plan and the free service plan, the paid plan, one free-plan slot.\n";
		$this->assertSame( array(), $this->run_check( $files ) );

		$files['readme.txt'] = "=== Babilim ===\n\nThe service decides which available translations are installed.\n";
		$this->assertSame( array( 'babilim/readme.txt:readme-forbidden-phrase:decides which available translations' ), $this->run_check( $files ) );
	}

	public function test_an_unreadable_zip_is_reported_as_null(): void {
		$this->assertNull( babilim_wording_check_zip( BABILIM_TEST_TMP_ROOT . '/no-such-wording-test.zip' ) );
	}
}
