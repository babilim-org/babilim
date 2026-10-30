<?php
/**
 * scripts/l10n.sh: rebuilds languages/*.po, .mo and .l10n.php from a
 * translation run directory. Runs the script against a throwaway repo
 * copy; no network.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_L10n_Scripts extends TestCase {

	/** @var string */
	private $tmp_repo;

	/** @var string */
	private $root;

	/** @var string The fixture translates a handful of the pot's entries only. */
	private $max_untranslated = '100000';

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 );
		if ( ! is_file( $this->root . '/tools/wp-cli.phar' ) ) {
			$this->markTestSkipped( 'tools/wp-cli.phar is not installed (run make tools).' );
		}
		$this->tmp_repo = BABILIM_TEST_TMP_ROOT . '/l10n-repo-' . uniqid( '', true );
		mkdir( $this->tmp_repo . '/languages', 0777, true );
		mkdir( $this->tmp_repo . '/tools', 0777, true );
		copy( $this->root . '/languages/babilim.pot', $this->tmp_repo . '/languages/babilim.pot' );
		symlink( $this->root . '/tools/wp-cli.phar', $this->tmp_repo . '/tools/wp-cli.phar' );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->tmp_repo ) ) {
			babilim_test_rmtree( $this->tmp_repo );
		}
	}

	private function locales_file( string $content ): string {
		$file = $this->tmp_repo . '/LOCALES';
		file_put_contents( $file, $content );
		return $file;
	}

	/**
	 * @return array{stdout:string,rc:int}
	 */
	private function run_script( string $script, string $src, string $locales ): array {
		$cmd  = 'BABILIM_L10N_REPO=' . escapeshellarg( $this->tmp_repo ) . ' L10N_MAX_UNTRANSLATED=' . escapeshellarg( $this->max_untranslated ) . ' bash ' . escapeshellarg( $this->root . '/scripts/' . $script )
			. ' ' . escapeshellarg( $src ) . ' ' . escapeshellarg( $locales ) . ' 2>&1';
		$out  = array();
		$code = 0;
		exec( $cmd, $out, $code );
		return array(
			'stdout' => implode( "\n", $out ),
			'rc'     => $code,
		);
	}

	public function test_l10n_sh_builds_po_mo_and_l10n_php_for_each_listed_locale(): void {
		$src = dirname( __DIR__ ) . '/fixtures/l10n/src';
		$res = $this->run_script( 'l10n.sh', $src, $this->locales_file( "zz_ZZ\n" ) );
		$this->assertSame( 0, $res['rc'], $res['stdout'] );

		$base = $this->tmp_repo . '/languages/babilim-zz_ZZ';
		$this->assertFileExists( $base . '.po' );
		$this->assertFileExists( $base . '.mo' );
		$this->assertFileExists( $base . '.l10n.php' );

		$po = (string) file_get_contents( $base . '.po' );
		$this->assertStringNotContainsString( 'babilim: source=', $po );
		$this->assertDoesNotMatchRegularExpression( '/^#\.? babilim[:-]/m', $po );
		$this->assertStringNotContainsString( 'Obsolete', $po );

		$pot = (string) file_get_contents( $this->tmp_repo . '/languages/babilim.pot' );
		$this->assertSame(
			preg_match_all( '/^msgid "/m', $pot ),
			preg_match_all( '/^msgid "/m', $po ),
			'the .po must carry exactly the pot\'s entries'
		);

		$data = include $base . '.l10n.php';
		$this->assertSame( 'x', $data['messages']['Babilim'] );
		$this->assertSame( 'x', $data['messages']['Translations'] );
	}

	public function test_l10n_sh_skips_a_locale_without_source_and_reports_it(): void {
		$src = dirname( __DIR__ ) . '/fixtures/l10n/src';
		$res = $this->run_script( 'l10n.sh', $src, $this->locales_file( "# comment\nzz_ZZ\nyy_YY\n" ) );
		$this->assertStringContainsString( 'SKIP yy_YY (no source)', $res['stdout'] );
		$this->assertStringContainsString( 'missing: yy_YY', $res['stdout'] );
		$this->assertSame( 0, $res['rc'], $res['stdout'] );
		$this->assertFileDoesNotExist( $this->tmp_repo . '/languages/babilim-yy_YY.po' );
	}

	public function test_l10n_sh_strips_run_annotations_before_handing_the_po_to_update_po(): void {
		// A stand-in wp-cli records what update-po receives, so this holds
		// even if the real update-po would drop the comments itself.
		unlink( $this->tmp_repo . '/tools/wp-cli.phar' );
		file_put_contents(
			$this->tmp_repo . '/tools/wp-cli.phar',
			"<?php\nif ( 'update-po' === \$argv[2] ) { copy( \$argv[4], getenv( 'BABILIM_L10N_REPO' ) . '/languages/seen.txt' ); }\n"
		);
		$src = dirname( __DIR__ ) . '/fixtures/l10n/src';
		$this->run_script( 'l10n.sh', $src, $this->locales_file( "zz_ZZ\n" ) );

		$seen = (string) file_get_contents( $this->tmp_repo . '/languages/seen.txt' );
		$this->assertStringContainsString( 'msgid "Babilim"', $seen );
		$this->assertDoesNotMatchRegularExpression( '/^#\.? babilim[:-]/m', $seen );
		$this->assertStringContainsString( '#. Description of the plugin', $seen );
	}

	public function test_l10n_sh_accepts_a_locale_variant_that_contains_digits(): void {
		$src = BABILIM_TEST_TMP_ROOT . '/l10n-src-' . uniqid( '', true );
		mkdir( $src, 0777, true );
		copy( dirname( __DIR__ ) . '/fixtures/l10n/src/babilim-zz_ZZ.po', $src . '/babilim-pt_PT_ao90.po' );
		$res = $this->run_script( 'l10n.sh', $src, $this->locales_file( "pt_PT_ao90\n" ) );
		$this->assertSame( 0, $res['rc'], $res['stdout'] );
		$this->assertStringContainsString( 'OK pt_PT_ao90', $res['stdout'] );
		$this->assertFileExists( $this->tmp_repo . '/languages/babilim-pt_PT_ao90.l10n.php' );
	}

	public function test_l10n_sh_keeps_going_and_leaves_no_partial_files_when_one_locale_fails(): void {
		// Stand-in wp-cli: update-po fails for yy_YY; make-mo/make-php write
		// a file next to the target, so a half-built locale would be visible.
		unlink( $this->tmp_repo . '/tools/wp-cli.phar' );
		file_put_contents(
			$this->tmp_repo . '/tools/wp-cli.phar',
			"<?php\n\$op = \$argv[2];\n"
			. "if ( 'update-po' === \$op ) { exit( false !== strpos( \$argv[4], 'yy_YY' ) ? 1 : 0 ); }\n"
			. "\$base = basename( \$argv[3], '.po' );\n"
			. "file_put_contents( \$argv[4] . \$base . ( 'make-mo' === \$op ? '.mo' : '.l10n.php' ), 'data' );\n"
		);
		$src = BABILIM_TEST_TMP_ROOT . '/l10n-src-' . uniqid( '', true );
		mkdir( $src, 0777, true );
		foreach ( array( 'yy_YY', 'zz_ZZ' ) as $locale ) {
			copy( dirname( __DIR__ ) . '/fixtures/l10n/src/babilim-zz_ZZ.po', $src . '/babilim-' . $locale . '.po' );
		}
		$res = $this->run_script( 'l10n.sh', $src, $this->locales_file( "yy_YY\nzz_ZZ\n" ) );

		$this->assertNotSame( 0, $res['rc'] );
		$this->assertStringContainsString( 'FAIL yy_YY', $res['stdout'] );
		$this->assertStringContainsString( 'failed: yy_YY', $res['stdout'] );
		$this->assertStringContainsString( 'OK zz_ZZ', $res['stdout'] );
		$this->assertFileExists( $this->tmp_repo . '/languages/babilim-zz_ZZ.l10n.php' );
		$this->assertSame( array(), glob( $this->tmp_repo . '/languages/babilim-yy_YY*' ) ?: array() );
		$this->assertSame( array(), glob( $this->tmp_repo . '/languages/l10n_stage_*' ) ?: array() );
	}

	/**
	 * Builds zz_ZZ with l10n.sh in the throwaway repo.
	 */
	private function build_fixture_locale(): string {
		$locales = $this->locales_file( "zz_ZZ\n" );
		$res     = $this->run_script( 'l10n.sh', dirname( __DIR__ ) . '/fixtures/l10n/src', $locales );
		$this->assertSame( 0, $res['rc'], $res['stdout'] );
		return $locales;
	}

	/**
	 * @return array{stdout:string,rc:int}
	 */
	private function run_check( string $locales ): array {
		$cmd  = 'BABILIM_L10N_REPO=' . escapeshellarg( $this->tmp_repo ) . ' L10N_MAX_UNTRANSLATED=' . escapeshellarg( $this->max_untranslated )
			. ' bash ' . escapeshellarg( $this->root . '/scripts/l10n-check.sh' ) . ' ' . escapeshellarg( $locales ) . ' 2>&1';
		$out  = array();
		$code = 0;
		exec( $cmd, $out, $code );
		return array(
			'stdout' => implode( "\n", $out ),
			'rc'     => $code,
		);
	}

	private function replace_in_file( string $file, string $from, string $to ): void {
		$content = (string) file_get_contents( $file );
		$this->assertStringContainsString( $from, $content, 'test setup: ' . $from . ' not found' );
		file_put_contents( $file, str_replace( $from, $to, $content ) );
	}

	public function test_l10n_check_passes_on_freshly_built_files(): void {
		$res = $this->run_check( $this->build_fixture_locale() );
		$this->assertSame( 0, $res['rc'], $res['stdout'] );
		$this->assertStringContainsString( 'OK: 1 locales', $res['stdout'] );
	}

	public function test_l10n_check_fails_when_the_pot_changed(): void {
		$locales = $this->build_fixture_locale();
		$this->replace_in_file( $this->tmp_repo . '/languages/babilim.pot', 'msgid "Advanced"', 'msgid "Advanced settings"' );
		$res = $this->run_check( $locales );
		$this->assertSame( 1, $res['rc'], $res['stdout'] );
		$this->assertStringContainsString( 'STALE zz_ZZ (+1/-1)', $res['stdout'] );
	}

	public function test_l10n_check_stale_message_says_how_to_recover_and_the_recovery_works(): void {
		$locales = $this->build_fixture_locale();
		$pot     = (string) file_get_contents( $this->tmp_repo . '/languages/babilim.pot' );
		$this->assertSame( 1, preg_match( '/^#: (\S+):(\d+)$/m', $pot, $m ), 'test setup: the pot has no line reference' );
		$this->replace_in_file( $this->tmp_repo . '/languages/babilim.pot', '#: ' . $m[1] . ':' . $m[2], '#: ' . $m[1] . ':' . ( (int) $m[2] + 1 ) );
		$res = $this->run_check( $locales );
		$this->assertSame( 1, $res['rc'], $res['stdout'] );
		$this->assertStringContainsString( 'STALE zz_ZZ (+0/-0)', $res['stdout'] );
		$this->assertStringContainsString( 'make l10n L10N_SRC=languages', $res['stdout'], 'the message must say how to recover' );

		// The recovery named in the message: rebuild from the committed .po files.
		$rebuild = $this->run_script( 'l10n.sh', $this->tmp_repo . '/languages', $locales );
		$this->assertSame( 0, $rebuild['rc'], $rebuild['stdout'] );
		$res = $this->run_check( $locales );
		$this->assertSame( 0, $res['rc'], $res['stdout'] );
		$this->assertStringContainsString( 'OK: 1 locales', $res['stdout'] );
	}

	public function test_l10n_check_reports_a_missing_locale_and_keeps_checking_the_rest(): void {
		$this->build_fixture_locale();
		$locales = $this->locales_file( "yy_YY\nzz_ZZ\nxx_XX\n" );
		$res = $this->run_check( $locales );
		$this->assertSame( 1, $res['rc'], $res['stdout'] );
		$this->assertStringContainsString( 'MISSING yy_YY', $res['stdout'] );
		$this->assertStringContainsString( 'MISSING xx_XX', $res['stdout'], 'a failure must not stop the run' );
		$this->assertStringNotContainsString( 'MISSING zz_ZZ', $res['stdout'] );
		$this->assertStringContainsString( 'FAIL:', $res['stdout'] );
	}

	public function test_l10n_check_lists_every_failing_locale(): void {
		$locales = $this->build_fixture_locale();
		copy( $this->tmp_repo . '/languages/babilim-zz_ZZ.po', $this->tmp_repo . '/languages/babilim-ww_WW.po' );
		copy( $this->tmp_repo . '/languages/babilim-zz_ZZ.mo', $this->tmp_repo . '/languages/babilim-ww_WW.mo' );
		copy( $this->tmp_repo . '/languages/babilim-zz_ZZ.l10n.php', $this->tmp_repo . '/languages/babilim-ww_WW.l10n.php' );
		file_put_contents( $locales, "ww_WW\nzz_ZZ\n" );
		$this->replace_in_file( $this->tmp_repo . '/languages/babilim.pot', 'msgid "Advanced"', 'msgid "Advanced settings"' );
		$res = $this->run_check( $locales );
		$this->assertStringContainsString( 'STALE ww_WW', $res['stdout'] );
		$this->assertStringContainsString( 'STALE zz_ZZ', $res['stdout'] );
	}

	public function test_l10n_check_fails_when_mo_does_not_match_po(): void {
		$locales = $this->build_fixture_locale();
		$this->replace_in_file( $this->tmp_repo . '/languages/babilim-zz_ZZ.po', 'msgid "Translations"' . "\n" . 'msgstr "x"', 'msgid "Translations"' . "\n" . 'msgstr "y"' );
		$res = $this->run_check( $locales );
		$this->assertSame( 1, $res['rc'], $res['stdout'] );
		$this->assertStringContainsString( 'DERIVED zz_ZZ (mo)', $res['stdout'] );
		$this->assertStringContainsString( 'DERIVED zz_ZZ (l10n.php)', $res['stdout'] );
	}

	public function test_l10n_check_fails_on_a_placeholder_mismatch(): void {
		$locales = $this->build_fixture_locale();
		$msgid   = 'Select fewer components: at most %1$d operations per run (you selected %2$d).';
		$this->replace_in_file(
			$this->tmp_repo . '/languages/babilim-zz_ZZ.po',
			'msgid "' . $msgid . '"' . "\n" . 'msgstr ""',
			'msgid "' . $msgid . '"' . "\n" . 'msgstr "at most %1$d operations"'
		);
		// Rebuild the derived files so only the placeholder check can object.
		$po = $this->tmp_repo . '/languages/babilim-zz_ZZ.po';
		exec( 'php ' . escapeshellarg( $this->root . '/tools/wp-cli.phar' ) . ' i18n make-mo ' . escapeshellarg( $po ) . ' ' . escapeshellarg( dirname( $po ) . '/' ) . ' --allow-root 2>&1' );
		exec( 'php ' . escapeshellarg( $this->root . '/tools/wp-cli.phar' ) . ' i18n make-php ' . escapeshellarg( $po ) . ' ' . escapeshellarg( dirname( $po ) . '/' ) . ' --allow-root 2>&1' );
		$res = $this->run_check( $locales );
		$this->assertSame( 1, $res['rc'], $res['stdout'] );
		$this->assertStringContainsString( 'PLACEHOLDER zz_ZZ "' . $msgid . '"', $res['stdout'] );
		$this->assertStringNotContainsString( 'DERIVED', $res['stdout'] );
	}

	public function test_l10n_check_counts_untranslated_entries_against_the_limit(): void {
		$locales               = $this->build_fixture_locale();
		$this->max_untranslated = '0';
		$res                   = $this->run_check( $locales );
		$this->assertSame( 1, $res['rc'], $res['stdout'] );
		$this->assertMatchesRegularExpression( '/^UNTRANSLATED zz_ZZ \d+$/m', $res['stdout'] );
	}

	public function test_the_release_zip_leaves_po_files_out(): void {
		$lines = file( $this->root . '/.distignore', FILE_IGNORE_NEW_LINES ) ?: array();
		$this->assertContains( '*.po', $lines );
	}
}
