<?php
/**
 * Tests for Babilim_Installer -- install/verify/rollback/remove/degrade/
 * purge_all.
 *
 * $transform/$parse are faked with simple, self-consistent stand-ins
 * (not real WP_Translation_File): $transform emits a tiny PHP source
 * whose var_export()'d entry count the fake $parse can read back
 * losslessly, so "parse(mo) === parse(transformed php)" is a genuine
 * check of the code under test, not a tautology baked into the fakes.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Installer extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	// -- Fakes ------------------------------------------------------------

	/**
	 * fn(string $mo_path): string -- turns a fake .mo (see mo_bytes())
	 * into a tiny PHP source string whose own entry count matches the
	 * .mo's, readable by parse_fake().
	 */
	private function transform_fake( Babilim_Fs $fs ): callable {
		return static function ( string $mo_path ) use ( $fs ) {
			$content = $fs->get( $mo_path );
			$entries = is_string( $content ) ? babilim_test_read_mo( $content ) : array();
			unset( $entries[''] );
			return "<?php\nreturn array( 'messages' => " . var_export( $entries, true ) . " );\n";
		};
	}

	/**
	 * fn(string $path, string $format): int|false.
	 */
	private function parse_fake( Babilim_Fs $fs ): callable {
		return static function ( string $path, string $format ) use ( $fs ) {
			$content = $fs->get( $path );
			if ( ! is_string( $content ) ) {
				return false;
			}
			if ( 'php' === $format ) {
				$code = (string) preg_replace( '/^<\?php\s*/', '', $content );
				$data = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
				return is_array( $data['messages'] ?? null ) ? count( $data['messages'] ) : false;
			}
			$entries = babilim_test_read_mo( $content );
			unset( $entries[''] );
			return count( $entries );
		};
	}

	/**
	 * fn(string $zip_path): array|false -- $zip_path is itself just a
	 * lookup key into $GLOBALS set by put_fake_zip() below; no real zip
	 * file or ZipArchive is ever touched in these tests.
	 */
	private function unzip_fake(): callable {
		return static function ( string $zip_path ) {
			return $GLOBALS['babilim_test_fake_zips'][ $zip_path ] ?? false;
		};
	}

	private function put_fake_zip( string $zip_path, array $entries ): void {
		$GLOBALS['babilim_test_fake_zips'][ $zip_path ] = $entries;
	}

	private function mo_bytes( array $entries ): string {
		return Babilim_Mo_Writer::write( $entries, array() );
	}

	private function mo_entry_count( string $bytes ): int {
		$parsed = babilim_test_read_mo( $bytes );
		unset( $parsed[''] );
		return count( $parsed );
	}

	private function file_digest( string $bytes ): array {
		return array( 'sha256' => hash( 'sha256', $bytes ), 'size' => strlen( $bytes ) );
	}

	/**
	 * Builds a package-manifest.schema.json-shaped array plus the zip
	 * entry map it describes, for one plugin domain+locale with a main
	 * .mo/.po pair and no default supplement, extra domains, or script
	 * translations. Individual tests layer more entries on top.
	 */
	private function basic_package( string $domain, string $locale, string $server_build_id, array $main_entries ): array {
		$mo = $this->mo_bytes( $main_entries );
		$po = "# fake po\n";

		$zip = array(
			$domain . '-' . $locale . '.mo' => $mo,
			$domain . '-' . $locale . '.po' => $po,
		);

		$manifest_json = array(
			'build_id'        => $domain . '-1.0.0-' . $locale . '-20260101T000000-abc123',
			'entries'         => $this->mo_entry_count( $mo ),
			'files'           => array(
				$domain . '-' . $locale . '.mo' => $this->file_digest( $mo ),
				$domain . '-' . $locale . '.po' => $this->file_digest( $po ),
			),
			'locale'          => $locale,
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => $domain,
			'version'         => '1.0.0',
			'text_domain'     => $domain,
			'extra_domains'   => array(),
			'server_build_id' => $server_build_id,
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.1.0',
		);
		$zip['manifest.json'] = wp_json_encode( $manifest_json );

		return array( $manifest_json, $zip );
	}

	private function new_installer( Babilim_Fs $fs, Babilim_Manifest $manifest ): Babilim_Installer {
		return new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $this->parse_fake( $fs ), $this->unzip_fake() );
	}

	// -- Happy path installs -----------------------------------------------

	public function test_install_writes_mo_and_l10n_php_and_records_the_manifest(): void {
		$fs                        = new Babilim_Memory_Fs();
		$manifest                  = new Babilim_Manifest( $fs );
		list( $expected, $zip )    = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' ) ) );
		// .po never lands in a served directory.
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'po' ) ) );

		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertNotNull( $record );
		$this->assertSame( '1.0.0', $record['component_version'] );
		$this->assertSame( 'bd_5e2a9c1f3d70', $record['build_id'] );
		$this->assertArrayHasKey( 'plugins/woocommerce-zh_TW.mo', $record['files'] );
	}

	/**
	 * Babilim_Admin::do_download() -- install()'s only production caller
	 * -- has no way to know a build's file digests/entry counts/version/
	 * etc. ahead of actually downloading it (`POST /v1/downloads` only
	 * ever returns a signed Location, never a manifest); it can only ever
	 * supply the download identity it already knows: server_build_id,
	 * slug, locale. install() must still succeed with only those three,
	 * because the zip's own manifest.json (once its identity matches)
	 * supplies everything else and is itself validated strictly (see
	 * install()'s own docblock) -- never a loosened check on the
	 * caller's own minimal input.
	 */
	public function test_install_succeeds_with_only_the_download_identity_known_ahead_of_time(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $full, $zip )     = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$minimal_expected = array(
			'server_build_id' => $full['server_build_id'],
			'slug'            => $full['slug'],
			'locale'          => $full['locale'],
		);

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $minimal_expected );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( 'bd_5e2a9c1f3d70', $record['build_id'] );
		// Derived from the zip's own manifest.json, not left empty --
		// component_version must never go missing on a real install.
		$this->assertSame( '1.0.0', $record['component_version'] );
	}

	/**
	 * The one thing a minimal $expected_manifest still can't skip: a
	 * malformed/wrong server_build_id is still rejected before the zip
	 * is even opened.
	 */
	public function test_install_still_rejects_an_invalid_server_build_id_with_a_minimal_expected_manifest(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		$result = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://never-opened', array( 'server_build_id' => 'not-a-real-id', 'slug' => 'woocommerce', 'locale' => 'zh_TW' ) );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'invalid_manifest_shape', $result->code() );
	}

	public function test_install_fails_when_a_files_sha256_does_not_match(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		// Corrupt the digest so it no longer matches the actual bytes --
		// install() verifies against the zip's own manifest.json (see
		// its own docblock), so the zip's embedded copy must carry the
		// corruption, not just the separate $expected identity array.
		$expected['files']['woocommerce-zh_TW.mo']['sha256'] = str_repeat( '0', 64 );
		$zip['manifest.json'] = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	public function test_install_fails_when_entry_count_does_not_match_manifest(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$expected['entries']    = 999;
		// Same reason as above -- the zip's own declared entry count is
		// what install() actually checks against.
		$zip['manifest.json'] = wp_json_encode( $expected );

		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
	}

	public function test_a_php_file_in_the_zip_never_reaches_a_served_directory(): void {
		$fs                      = new Babilim_Memory_Fs();
		$manifest                = new Babilim_Manifest( $fs );
		list( $expected, $zip )  = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		// A malicious/mistaken package trying to smuggle in remote PHP.
		// This payload is a harmless marker, not a real shell
		// command -- parse_fake() eval()'s whatever a staged .l10n.php
		// contains as part of its own re-parse step, so a smuggled file
		// that DID reach staging would previously have had a real
		// `system($_GET['x'])` call executed by the test suite itself.
		$zip['woocommerce-zh_TW.l10n.php'] = "<?php return array( 'messages' => array( 'PWNED' => '1' ) );";
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$served = $fs->get( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' ) );
		$this->assertStringNotContainsString( 'PWNED', (string) $served );
	}

	/**
	 * Guideline 8, the manifest-driven half: even if a malicious/mistaken
	 * package's own `manifest.json` explicitly lists a `.l10n.php` entry
	 * in `files` (not just smuggles the bytes in unlisted, as the sibling
	 * test above covers), install() must still never trust it forward --
	 * classify_entries() never sorts a `.l10n.php` name into `main` in
	 * the first place, so verify_against_expected() finds the "expected"
	 * file simply missing and rejects the whole install.
	 */
	public function test_install_rejects_when_files_explicitly_lists_an_l10n_php_entry(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );

		$payload = "<?php return array( 'messages' => array( 'PWNED' => '1' ) );";
		$zip['woocommerce-zh_TW.l10n.php'] = $payload;
		$expected['files']['woocommerce-zh_TW.l10n.php'] = $this->file_digest( $payload );
		$zip['manifest.json'] = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'missing_expected_file', $result->code() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' ) ) );
	}

	/**
	 * Guideline 8's whitelist, unit-tested directly against the exact
	 * shapes a malicious/mistaken package might use to try to reach a
	 * served directory: `.php`, `.phtml`, and a `../`-traversal name.
	 * classify_entries() is private, so this drives it through install()
	 * and inspects what actually got staged/served -- none of these three
	 * names ever legitimately match the main-file or default-fragment
	 * patterns, so they're silently dropped, same as any other
	 * unrecognized name, and the install still succeeds on its own
	 * legitimate files.
	 *
	 * @dataProvider provide_guideline_8_rejected_filenames
	 */
	public function test_classify_entries_never_accepts_disallowed_filename_shapes( string $malicious_name ): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$zip[ $malicious_name ] = 'whatever bytes -- never read, never staged';
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		// The legitimate files still install fine -- an unrecognized
		// extra name in the zip is simply ignored, not fatal. The served
		// directory holds exactly the legitimate files (2: the .mo and
		// its derived .l10n.php) -- never the malicious name itself
		// (`basename()`-ing it to check for it directly would be
		// meaningless here: a `../`-traversal name's basename collapses
		// to the SAME name as the legitimate served file it's disguised
		// as, so "not present" has to mean "the served set is exactly the
		// legitimate one", not "this one exact name is absent").
		$this->assertTrue( $result->is_ok(), $result->message() );
		$served = $fs->list( Babilim_Paths::dir( 'plugins' ) );
		sort( $served );
		$this->assertSame( array( 'woocommerce-zh_TW.l10n.php', 'woocommerce-zh_TW.mo' ), $served );
		foreach ( $served as $served_name ) {
			$this->assertNotSame( $malicious_name, $served_name );
		}
	}

	public function provide_guideline_8_rejected_filenames(): array {
		return array(
			'.php'                => array( 'woocommerce-zh_TW.php' ),
			'.phtml'               => array( 'woocommerce-zh_TW.phtml' ),
			'a `../` traversal name' => array( '../woocommerce-zh_TW.mo' ),
		);
	}

	public function test_install_with_a_default_supplement_stages_it_under_parts_only(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );

		$default_mo = $this->mo_bytes( array( 'Cancel' => 'y' ) );
		$zip['default/woocommerce-zh_TW.mo'] = $default_mo;
		$zip['default/woocommerce-zh_TW.po'] = "# default po\n";
		$expected['default_domain_entries']  = $this->mo_entry_count( $default_mo );
		$expected['default_domain_files']    = array(
			'woocommerce-zh_TW.mo' => $this->file_digest( $default_mo ),
			'woocommerce-zh_TW.po' => $this->file_digest( "# default po\n" ),
		);
		$zip['manifest.json'] = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'default/_parts', 'woocommerce', 'zh_TW', 'mo' ) ) );
		// Never a .l10n.php for a default fragment -- never loaded directly.
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'default/_parts', 'woocommerce', 'zh_TW', 'l10n.php' ) ) );

		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertArrayHasKey( 'default/_parts/woocommerce-zh_TW.mo', $record['default_files'] );
	}

	// -- JS/script catalog merge ---------------------------------------

	/**
	 * Builds two per-script Jed catalogs to merge, sharing the shared
	 * install() plumbing every variant below needs.
	 *
	 * @param string $top_level_domain The JSON payload's own top-level
	 *                                  `domain` field -- WP-CLI `make-json`
	 *                                  (and wp.org language packs) always
	 *                                  write the literal string "messages"
	 *                                  here; a caller can also
	 *                                  pass some other value to prove the
	 *                                  merge is correct regardless.
	 */
	private function script_catalog_install( string $top_level_domain ): array {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );

		$md5_a  = md5( 'assets/a.js' );
		$md5_b  = md5( 'assets/b.js' );
		$json_a = wp_json_encode(
			array(
				'domain'      => $top_level_domain,
				'locale_data' => array(
					'messages' => array(
						''      => array( 'domain' => $top_level_domain, 'lang' => 'zh_TW' ),
						'Alpha' => array( 'Alpha-TW' ),
					),
				),
			)
		);
		$json_b = wp_json_encode(
			array(
				'domain'      => $top_level_domain,
				'locale_data' => array(
					'messages' => array(
						''     => array( 'domain' => $top_level_domain, 'lang' => 'zh_TW' ),
						'Beta' => array( 'Beta-TW' ),
					),
				),
			)
		);

		$zip[ "woocommerce-zh_TW-{$md5_a}.json" ] = $json_a;
		$zip[ "woocommerce-zh_TW-{$md5_b}.json" ] = $json_b;
		$expected['files'][ "woocommerce-zh_TW-{$md5_a}.json" ] = $this->file_digest( $json_a );
		$expected['files'][ "woocommerce-zh_TW-{$md5_b}.json" ] = $this->file_digest( $json_b );
		$zip['manifest.json'] = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		return array( $result, $fs );
	}

	/**
	 * The real shape WP-CLI `make-json` (and wp.org language packs)
	 * actually produce -- top-level `"domain":"messages"`. The removed
	 * `unset( $out['locale_data'][ $out['domain'] ?? '' ] )` line used to
	 * run right after `locale_data.messages` was written, which against
	 * THIS shape deleted `locale_data.messages` itself: every merged
	 * translation vanished, and the fixture the original implementer
	 * used (`'domain' => 'woocommerce'`, see the "other value" test
	 * below) happened to never exercise it.
	 */
	public function test_multiple_script_json_files_merge_into_one_served_catalog(): void {
		list( $result, $fs ) = $this->script_catalog_install( 'messages' );

		$this->assertTrue( $result->is_ok(), $result->message() );

		$served_path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'json' );
		$this->assertTrue( $fs->exists( $served_path ) );
		// The per-script md5 files never land in the served directory.
		$this->assertFalse( $fs->exists( Babilim_Paths::dir( 'plugins' ) . '/woocommerce-zh_TW-' . md5( 'assets/a.js' ) . '.json' ) );

		$served = json_decode( (string) $fs->get( $served_path ), true );
		// Output is always pinned to domain:"messages", never
		// whatever the first catalog happened to carry.
		$this->assertSame( 'messages', $served['domain'] );
		$this->assertArrayHasKey( 'messages', $served['locale_data'] );
		$messages = $served['locale_data']['messages'];
		$this->assertSame( array( 'Alpha-TW' ), $messages['Alpha'] );
		$this->assertSame( array( 'Beta-TW' ), $messages['Beta'] );
	}

	/**
	 * The merge must also still be correct against the OLD fixture
	 * shape (a non-"messages" top-level `domain`, e.g. the component's
	 * own text domain) -- this is what the original test exercised, and
	 * must keep passing now that the buggy unset() is gone.
	 */
	public function test_multiple_script_json_files_merge_when_the_top_level_domain_is_some_other_value(): void {
		list( $result, $fs ) = $this->script_catalog_install( 'woocommerce' );

		$this->assertTrue( $result->is_ok(), $result->message() );

		$served   = json_decode( (string) $fs->get( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'json' ) ), true );
		$this->assertSame( 'messages', $served['domain'] );
		$messages = $served['locale_data']['messages'];
		$this->assertSame( array( 'Alpha-TW' ), $messages['Alpha'] );
		$this->assertSame( array( 'Beta-TW' ), $messages['Beta'] );
	}

	// -- verify() -----------------------------------------------------------

	public function test_verify_reports_ok_for_an_untampered_install(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$installer = $this->new_installer( $fs, $manifest );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$result = $installer->verify( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_ok() );
		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertTrue( $record['integrity']['ok'] );
	}

	public function test_verify_fails_and_flags_integrity_when_a_served_file_is_tampered_with(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$installer = $this->new_installer( $fs, $manifest );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$fs->put( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ), 'tampered bytes' );

		$result = $installer->verify( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertFalse( $result->is_ok() );
		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertFalse( $record['integrity']['ok'] );
	}

	// -- rollback() ----------------------------------------------------------

	public function test_rollback_restores_the_previous_build(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected1, $zip1 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip1 );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected1 );

		list( $expected2, $zip2 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://2', $zip2 );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://2', $expected2 );

		$before = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( 'bd_000000000002', $before['build_id'] );

		$result = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$after = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( 'bd_000000000001', $after['build_id'] );

		$served_mo = babilim_test_read_mo( (string) $fs->get( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertSame( 'v1', $served_mo['Save'] );
	}

	/**
	 * rollback()'s own manifest_unavailable guard, exercised directly --
	 * a corrupt _manifest.json makes the manifest unavailable from
	 * construction (Babilim_Manifest::load() never runs twice, so this
	 * is fixed for the instance's whole lifetime), and rollback() must
	 * report that and stop before ever trying to read a record, exactly
	 * like install()'s own served_files_owned_elsewhere() guard already
	 * does (see that guard's own regression test).
	 */
	public function test_rollback_returns_manifest_unavailable_when_the_manifest_cannot_be_read(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::manifest_file(), '{not valid json' );
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		$result = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_unavailable', $result->code() );
	}

	public function test_rollback_fails_when_there_is_no_previous_build(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		$installer              = $this->new_installer( $fs, $manifest );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$result = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertFalse( $result->is_ok() );
	}

	/**
	 * Component A (slug/text domain `a-plugin`) ships extra domain
	 * `foo` in its v1 build, then drops it in v2 -- v2's own record no
	 * longer lists `foo-zh_TW.mo` (finalize_build() replaces the files
	 * map wholesale), even though the stale file is still sitting on
	 * disk. That leaves the path genuinely unowned, so component C
	 * (slug `foo-owner`, text domain `foo`) installing afterwards
	 * legitimately takes it over -- served_files_owned_elsewhere() sees
	 * no owner and lets it through, exactly as designed.
	 *
	 * A later rollback() of A back to v1 must not blindly rename v1's
	 * history copy of `foo-zh_TW.mo` back into place: that would
	 * overwrite C's now-active file. It must refuse instead, before
	 * writing anything, the same way install() already refuses a fresh
	 * install onto a path someone else owns.
	 */
	public function test_rollback_rejects_a_served_file_now_owned_by_a_different_component(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );

		list( $expected_a1, $zip_a1 ) = $this->owned_package( 'a-plugin', 'a-plugin', array( 'foo' ), 'bd_0000000000a1', 'A-V1' );
		$this->put_fake_zip( 'zip://a1', $zip_a1 );
		$installer = $this->new_installer( $fs, $manifest );
		$result_a1 = $installer->install( 'plugin', 'a-plugin', 'zh_TW', 'zip://a1', $expected_a1 );
		$this->assertTrue( $result_a1->is_ok(), $result_a1->message() );
		$this->assertSame( 'A-V1', $this->served_word( $fs, 'foo' ) );

		list( $expected_a2, $zip_a2 ) = $this->owned_package( 'a-plugin', 'a-plugin', array(), 'bd_0000000000a2', 'A-V2' );
		$this->put_fake_zip( 'zip://a2', $zip_a2 );
		$result_a2 = $installer->install( 'plugin', 'a-plugin', 'zh_TW', 'zip://a2', $expected_a2 );
		$this->assertTrue( $result_a2->is_ok(), $result_a2->message() );
		// v2 dropped 'foo' -- the file is still physically there (never
		// deleted, only ever replaced by a filename the new plan lists)
		// but genuinely unowned now.
		$this->assertNull( $manifest->owner_of( 'plugins/foo-zh_TW.mo' ) );

		list( $expected_c, $zip_c ) = $this->owned_package( 'foo-owner', 'foo', array(), 'bd_0000000000c1', 'C-OWN' );
		$this->put_fake_zip( 'zip://c', $zip_c );
		$result_c = $installer->install( 'plugin', 'foo-owner', 'zh_TW', 'zip://c', $expected_c );
		$this->assertTrue( $result_c->is_ok(), $result_c->message() );
		$this->assertSame( 'C-OWN', $this->served_word( $fs, 'foo' ), 'C legitimately took over the now-unowned foo-zh_TW.mo' );

		$before = $fs->snapshot();

		$rolled_back = $installer->rollback( 'plugin', 'a-plugin', 'zh_TW' );

		$this->assertTrue( $rolled_back->is_error(), "a rollback that would overwrite another component's served file must be refused" );
		$this->assertSame( 'text_domain_in_use', $rolled_back->code() );
		$this->assertSame( $before, $fs->snapshot(), 'a rejected rollback must not write, change or delete any file' );

		$a_record = $manifest->installed( 'plugin', 'a-plugin', 'zh_TW' );
		$this->assertSame( 'bd_0000000000a2', $a_record['build_id'], 'A must stay on v2 -- the rollback never happened' );

		$this->assertSame( 'C-OWN', $this->served_word( $fs, 'foo' ), "C's served file must be untouched" );
		$c_verify = $installer->verify( 'plugin', 'foo-owner', 'zh_TW' );
		$this->assertTrue( $c_verify->is_ok(), (string) $c_verify->code() );
	}

	/**
	 * Same scenario as the test above, but the conflict is on a
	 * `default/_parts/` fragment, never a `plugins/`/`themes/` main file
	 * -- rollback_files_owned_elsewhere() checks main_served and
	 * default_served as two separate loops (class-babilim-installer.php),
	 * and the test above only ever exercises the main_served one (a
	 * mutation deleting the default_served loop leaves it, and every
	 * other rollback test, green).
	 *
	 * The default-fragment served filename is always exactly
	 * `{slug}-{locale}.mo` (build_derived_files()'s own
	 * `$default_mo_filename = $slug . '-' . $locale . '.mo'`) -- unlike a
	 * main file, it is never namespaced by kind_dir (`plugins/` vs
	 * `themes/`), so a PLUGIN and a THEME sharing the same slug string
	 * (a real possibility -- those are separate wp.org namespaces) are
	 * the one way two different components can genuinely collide on a
	 * `default/_parts/` path. Plugin `foo` ships a default supplement in
	 * v1, drops it in v2; theme `foo` then legitimately takes over the
	 * now-unowned path.
	 */
	public function test_rollback_rejects_a_default_domain_fragment_now_owned_by_a_different_component(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );

		$mo_a1_main    = $this->mo_bytes( array( 'Save' => 'A-MAIN' ) );
		$po_a1_main    = "# po\n";
		$mo_a1_default = $this->mo_bytes( array( 'Cancel' => 'A-DEFAULT-V1' ) );
		$zip_a1        = array(
			'foo-zh_TW.mo'         => $mo_a1_main,
			'foo-zh_TW.po'         => $po_a1_main,
			'default/foo-zh_TW.mo' => $mo_a1_default,
		);
		$expected_a1   = array(
			'build_id'               => 'foo-1.0.0-zh_TW-20260101T000000-abc123',
			'entries'                => $this->mo_entry_count( $mo_a1_main ),
			'files'                  => array(
				'foo-zh_TW.mo' => $this->file_digest( $mo_a1_main ),
				'foo-zh_TW.po' => $this->file_digest( $po_a1_main ),
			),
			'default_domain_entries' => $this->mo_entry_count( $mo_a1_default ),
			'default_domain_files'   => array( 'foo-zh_TW.mo' => $this->file_digest( $mo_a1_default ) ),
			'locale'                 => 'zh_TW',
			'status'                 => 'published',
			'kind'                   => 'plugin',
			'slug'                   => 'foo',
			'version'                => '1.0.0',
			'text_domain'            => 'foo',
			'extra_domains'          => array(),
			'server_build_id'        => 'bd_0000000000e1',
			'build_no'               => 1,
			'issued_at'              => '2026-01-01T00:00:00Z',
			'contract'               => '1.1.0',
		);
		$zip_a1['manifest.json'] = wp_json_encode( $expected_a1 );
		$this->put_fake_zip( 'zip://e1', $zip_a1 );
		$installer = $this->new_installer( $fs, $manifest );
		$result_a1 = $installer->install( 'plugin', 'foo', 'zh_TW', 'zip://e1', $expected_a1 );
		$this->assertTrue( $result_a1->is_ok(), $result_a1->message() );
		$default_path = Babilim_Paths::file( 'default/_parts', 'foo', 'zh_TW', 'mo' );
		$this->assertSame( $mo_a1_default, $fs->get( $default_path ) );

		$mo_a2_main = $this->mo_bytes( array( 'Save' => 'A-MAIN-V2' ) );
		$po_a2_main = "# po\n";
		$zip_a2     = array(
			'foo-zh_TW.mo' => $mo_a2_main,
			'foo-zh_TW.po' => $po_a2_main,
		);
		$expected_a2 = array(
			'build_id'        => 'foo-1.0.0-zh_TW-20260101T000001-abc124',
			'entries'         => $this->mo_entry_count( $mo_a2_main ),
			'files'           => array(
				'foo-zh_TW.mo' => $this->file_digest( $mo_a2_main ),
				'foo-zh_TW.po' => $this->file_digest( $po_a2_main ),
			),
			'locale'          => 'zh_TW',
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => 'foo',
			'version'         => '1.0.0',
			'text_domain'     => 'foo',
			'extra_domains'   => array(),
			'server_build_id' => 'bd_0000000000e2',
			'build_no'        => 2,
			'issued_at'       => '2026-01-01T00:00:01Z',
			'contract'        => '1.1.0',
		);
		$zip_a2['manifest.json'] = wp_json_encode( $expected_a2 );
		$this->put_fake_zip( 'zip://e2', $zip_a2 );
		$result_a2 = $installer->install( 'plugin', 'foo', 'zh_TW', 'zip://e2', $expected_a2 );
		$this->assertTrue( $result_a2->is_ok(), $result_a2->message() );
		// v2 shipped no default supplement at all -- the fragment is
		// still physically there but genuinely unowned now (same
		// reasoning as the main_served test above, applied to
		// default_served).
		$this->assertNull( $manifest->owner_of( 'default/_parts/foo-zh_TW.mo' ) );

		$mo_c_default = $this->mo_bytes( array( 'Cancel' => 'C-DEFAULT-OWN' ) );
		$mo_c_main    = $this->mo_bytes( array( 'Save' => 'C-MAIN' ) );
		$po_c_main    = "# po\n";
		$zip_c        = array(
			'foo-zh_TW.mo'         => $mo_c_main,
			'foo-zh_TW.po'         => $po_c_main,
			'default/foo-zh_TW.mo' => $mo_c_default,
		);
		$expected_c   = array(
			'build_id'               => 'foo-1.0.0-zh_TW-20260101T000002-abc125',
			'entries'                => $this->mo_entry_count( $mo_c_main ),
			'files'                  => array(
				'foo-zh_TW.mo' => $this->file_digest( $mo_c_main ),
				'foo-zh_TW.po' => $this->file_digest( $po_c_main ),
			),
			'default_domain_entries' => $this->mo_entry_count( $mo_c_default ),
			'default_domain_files'   => array( 'foo-zh_TW.mo' => $this->file_digest( $mo_c_default ) ),
			'locale'                 => 'zh_TW',
			'status'                 => 'published',
			'kind'                   => 'theme',
			'slug'                   => 'foo',
			'version'                => '1.0.0',
			'text_domain'            => 'foo',
			'extra_domains'          => array(),
			'server_build_id'        => 'bd_0000000000e3',
			'build_no'               => 1,
			'issued_at'              => '2026-01-01T00:00:02Z',
			'contract'               => '1.1.0',
		);
		$zip_c['manifest.json'] = wp_json_encode( $expected_c );
		$this->put_fake_zip( 'zip://e3', $zip_c );
		$result_c = $installer->install( 'theme', 'foo', 'zh_TW', 'zip://e3', $expected_c );
		$this->assertTrue( $result_c->is_ok(), $result_c->message() );
		$this->assertSame( $mo_c_default, $fs->get( $default_path ), 'the theme legitimately took over the now-unowned default/_parts/foo-zh_TW.mo' );

		$before = $fs->snapshot();

		$rolled_back = $installer->rollback( 'plugin', 'foo', 'zh_TW' );

		$this->assertTrue( $rolled_back->is_error(), "a rollback that would overwrite a different component's default-domain fragment must be refused" );
		$this->assertSame( 'text_domain_in_use', $rolled_back->code() );
		$this->assertSame( $before, $fs->snapshot(), 'a rejected rollback must not write, change or delete any file' );

		$a_record = $manifest->installed( 'plugin', 'foo', 'zh_TW' );
		$this->assertSame( 'bd_0000000000e2', $a_record['build_id'], 'the plugin must stay on v2 -- the rollback never happened' );

		$this->assertSame( $mo_c_default, $fs->get( $default_path ), "the theme's default-domain fragment must be untouched" );
		$c_verify = $installer->verify( 'theme', 'foo', 'zh_TW' );
		$this->assertTrue( $c_verify->is_ok(), (string) $c_verify->code() );
	}

	// -- Retention: keep 3 versions -------------------------------------------

	public function test_a_fourth_install_prunes_the_oldest_staged_version(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		for ( $i = 1; $i <= 4; $i++ ) {
			$build_id           = sprintf( 'bd_%012d', $i );
			list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', $build_id, array( 'Save' => "v{$i}" ) );
			$this->put_fake_zip( "zip://{$i}", $zip );
			$installer->install( 'plugin', 'woocommerce', 'zh_TW', "zip://{$i}", $expected );
		}

		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertCount( 3, $record['version_history'] );
		$build_ids = array_column( $record['version_history'], 'build_id' );
		$this->assertNotContains( sprintf( 'bd_%012d', 1 ), $build_ids );

		// The oldest build's durable history copy was pruned...
		$oldest_history_copy = Babilim_Paths::version_dir( 'plugins', 'woocommerce', 'zh_TW', sprintf( 'bd_%012d', 1 ) ) . '/_history__woocommerce-zh_TW.mo';
		$this->assertFalse( $fs->exists( $oldest_history_copy ) );

		// ...but the retained builds' own history copies are still there.
		foreach ( $record['version_history'] as $entry ) {
			foreach ( $entry['main_served'] as $history_path ) {
				$this->assertTrue( $fs->exists( $history_path ), "Expected {$history_path} to still exist" );
			}
		}
	}

	// -- remove() -------------------------------------------------------------

	public function test_remove_deletes_served_files_and_the_manifest_record_but_not_custom(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$installer = $this->new_installer( $fs, $manifest );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );
		$fs->put( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ), 'site override' );

		$result = $installer->remove( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' ) ) );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
		// custom/ is untouched.
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
	}

	// -- degrade() --------------------------------------------------------------

	public function test_degrade_removes_served_files_but_keeps_the_manifest_record_and_versions(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$installer = $this->new_installer( $fs, $manifest );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$result = $installer->degrade( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );

		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertNotNull( $record );
		$this->assertSame( array(), $record['files'] );

		// The durable history copy in _versions/ is untouched.
		$history_copy = Babilim_Paths::version_dir( 'plugins', 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70' ) . '/_history__woocommerce-zh_TW.mo';
		$this->assertTrue( $fs->exists( $history_copy ) );
	}

	// -- A manifest write that does not land fails the action -------------

	/**
	 * Installs one build (two, when $second is true) with the manifest
	 * lock working, then takes the database away -- every later manifest
	 * write then fails (the lock cannot be taken), the way a write lost to
	 * lock contention does on a real site.
	 */
	private function installed_then_manifest_writes_fail( bool $second = false ): array {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$this->assertTrue( $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected )->is_ok() );
		if ( $second ) {
			list( $expected2, $zip2 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
			$this->put_fake_zip( 'zip://2', $zip2 );
			$this->assertTrue( $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://2', $expected2 )->is_ok() );
		}
		unset( $GLOBALS['wpdb'] );
		return array( $fs, $manifest, $installer );
	}

	public function test_install_reports_failure_when_its_manifest_record_cannot_be_written(): void {
		list( $fs, $manifest, $installer ) = $this->installed_then_manifest_writes_fail();
		list( $expected, $zip )            = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000003', array( 'Save' => 'v3' ) );
		$this->put_fake_zip( 'zip://3', $zip );
		$mo_path         = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );
		$served_before   = $fs->get( $mo_path );
		$manifest_before = $fs->get( Babilim_Paths::manifest_file() );
		$files_before    = $fs->snapshot();
		$this->assertNotNull( $served_before );
		$this->assertNotNull( $manifest_before );

		$result = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://3', $expected );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_write_failed', $result->code() );
		// A build whose record could not be written is never left served:
		// the previous build's own files are put back exactly as they
		// were, and the manifest still describes them.
		$this->assertSame( $served_before, $fs->get( $mo_path ), 'the previously served file must be restored byte for byte' );
		$this->assertSame( $manifest_before, $fs->get( Babilim_Paths::manifest_file() ), 'the manifest must be left unchanged' );
		$this->assertSame( array_keys( $files_before ), array_keys( $fs->snapshot() ), 'no backup, history or staging file may be left behind' );
		$this->assertSame( $files_before, $fs->snapshot() );
	}

	public function test_verify_reports_failure_when_its_integrity_record_cannot_be_written(): void {
		list( $fs, $manifest, $installer ) = $this->installed_then_manifest_writes_fail();

		$result = $installer->verify( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_write_failed', $result->code() );
	}

	public function test_verify_still_reports_integrity_failed_first_when_that_record_cannot_be_written_either(): void {
		list( $fs, $manifest, $installer ) = $this->installed_then_manifest_writes_fail();
		$fs->put( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ), 'tampered bytes' );

		$result = $installer->verify( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertSame( 'integrity_failed', $result->code(), 'a tampered file must still be reported (and so degraded) even when recording it failed' );
	}

	public function test_degrade_reports_failure_when_its_record_cannot_be_written(): void {
		list( $fs, $manifest, $installer ) = $this->installed_then_manifest_writes_fail();

		$result = $installer->degrade( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_write_failed', $result->code() );
	}

	public function test_rollback_reports_failure_when_its_record_cannot_be_written(): void {
		list( $fs, $manifest, $installer ) = $this->installed_then_manifest_writes_fail( true );

		$result = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_write_failed', $result->code() );
	}

	public function test_remove_reports_failure_when_its_record_cannot_be_removed(): void {
		list( $fs, $manifest, $installer ) = $this->installed_then_manifest_writes_fail();

		$result = $installer->remove( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_write_failed', $result->code() );
		$this->assertNotNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ), 'a removal that did not land must not look done' );
	}

	// -- purge_all() --------------------------------------------------------------

	public function test_purge_all_removes_every_record_and_keeps_custom_by_default(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected1, $zip1 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip1 );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected1 );

		$fs->put( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ), 'site override' );

		$result = $installer->purge_all( true );

		$this->assertTrue( $result->is_ok() );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
	}

	public function test_purge_all_with_keep_custom_false_also_clears_custom(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );
		$fs->put( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ), 'site override' );

		$result = $installer->purge_all( false );

		$this->assertTrue( $result->is_ok() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
	}

	// -- remove_managed_tree() (the uninstall wipe) -----------------------------

	/**
	 * Writes one file of every kind Babilim keeps under its root, plus one
	 * site-owned override under custom/.
	 *
	 * @return string[] Every non-custom path written.
	 */
	private function seed_managed_tree( Babilim_Memory_Fs $fs ): array {
		Babilim_Paths::ensure_layout( $fs );
		$paths = array(
			Babilim_Paths::manifest_file(),
			Babilim_Paths::file( 'plugins', 'orphan', 'zh_TW', 'mo' ),
			Babilim_Paths::file( 'themes', 'orphan', 'de_DE', 'json' ),
			Babilim_Paths::merged_default( 'zh_TW', 'mo' ),
			Babilim_Paths::file( 'default/_parts', 'orphan', 'zh_TW', 'mo' ),
			Babilim_Paths::version_dir( 'plugins', 'orphan', 'zh_TW', 'bd_5e2a9c1f3d70' ) . '/orphan-zh_TW.po',
		);
		foreach ( $paths as $path ) {
			$fs->put( $path, 'x' );
		}
		// A .mo directly under _incoming/ is never something this
		// plugin's own write path puts there (see
		// INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock) -- only
		// a foreign process, bypassing Babilim_Fs entirely,
		// ever leaves one here, so plant_disallowed() places it the way
		// it would really turn up, skipping the write-time allow-list
		// this fixture setup isn't meant to exercise.
		$incoming_leftover = Babilim_Paths::incoming_dir( 'tok' ) . '/left-over.mo';
		$fs->plant_disallowed( $incoming_leftover );
		$paths[] = $incoming_leftover;
		$fs->put( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ), 'site override' );
		return $paths;
	}

	public function test_remove_managed_tree_removes_every_managed_file_but_keeps_custom_and_its_guards(): void {
		$fs        = new Babilim_Memory_Fs();
		$installer = $this->new_installer( $fs, new Babilim_Manifest( $fs ) );
		$paths     = $this->seed_managed_tree( $fs );

		$installer->remove_managed_tree( true );

		foreach ( $paths as $path ) {
			$this->assertFalse( $fs->exists( $path ), "{$path} must be removed" );
		}
		$this->assertFalse( $fs->is_dir( Babilim_Paths::dir( 'plugins' ) ) );
		$this->assertFalse( $fs->is_dir( Babilim_Paths::dir( '_versions' ) ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'custom/plugins', 'woocommerce', 'zh_TW', 'mo' ) ), 'custom/ must survive' );
		$this->assertTrue( $fs->exists( Babilim_Paths::root() . '/index.php' ), 'the guard files stay while custom/ does' );
		$this->assertTrue( $fs->exists( Babilim_Paths::root() . '/.htaccess' ) );
	}

	public function test_remove_managed_tree_without_keep_custom_removes_the_whole_root(): void {
		$fs        = new Babilim_Memory_Fs();
		$installer = $this->new_installer( $fs, new Babilim_Manifest( $fs ) );
		$this->seed_managed_tree( $fs );

		$installer->remove_managed_tree( false );

		$this->assertSame( array(), $fs->snapshot(), 'no file may be left' );
		$this->assertFalse( $fs->is_dir( Babilim_Paths::root() ), 'the emptied root directory itself must go' );
	}

	public function test_remove_managed_tree_keeps_going_past_an_entry_it_cannot_remove(): void {
		$fs        = new Babilim_Memory_Fs();
		$installer = $this->new_installer( $fs, new Babilim_Manifest( $fs ) );
		$this->seed_managed_tree( $fs );
		// Outside the path allow-list, so any Babilim_Fs call on it throws.
		$fs->plant_disallowed( Babilim_Paths::dir( 'plugins' ) . '/stray.txt' );

		$installer->remove_managed_tree( true );

		$this->assertFalse( $fs->exists( Babilim_Paths::manifest_file() ), 'entries after the unremovable one are still removed' );
		$this->assertFalse( $fs->exists( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) ) );
	}

	// -- purge_all() only reports a genuinely successful remove(). ------

	public function test_purge_all_does_not_report_a_record_remove_could_not_actually_remove(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		// A record whose own `kind` isn't 'plugin'/'theme' can only ever
		// have gotten into the manifest by writing it directly (bypassing
		// install()'s own guard_identity()) -- exactly the shape needed
		// to make remove() itself fail via guard_identity() when
		// purge_all() calls it, so the fix can be proven without a
		// separately-failing Babilim_Fs.
		$manifest->set( 'bogus-kind', 'ghost', 'ja', array( 'files' => array(), 'default_files' => array() ) );

		$result = $installer->purge_all( true );

		$this->assertTrue( $result->is_ok() );
		$removed_slugs = array_column( $result->get( 'removed' ), 'slug' );
		$this->assertContains( 'woocommerce', $removed_slugs );
		$this->assertNotContains( 'ghost', $removed_slugs );
	}

	// -- install() cross-checks the zip's own manifest.json against
	// the caller-supplied $expected_manifest. -------------------------------

	public function test_install_rejects_a_zip_whose_own_manifest_json_disagrees_on_server_build_id(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		// The zip's own manifest.json claims a different build than the
		// caller-supplied $expected_manifest -- e.g. a zip fetched for
		// the wrong build id.
		$mismatched              = $expected;
		$mismatched['server_build_id'] = 'bd_ffffffffffff';
		$zip['manifest.json']    = wp_json_encode( $mismatched );
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'package_manifest_mismatch', $result->code() );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	/**
	 * install()'s own `$domain` parameter is the caller's identity
	 * assertion: it must equal either the package manifest's own `slug`
	 * or its `text_domain`. A value matching neither is a caller bug
	 * worth rejecting rather than silently installing under whichever
	 * one happened to win.
	 */
	public function test_install_rejects_a_domain_argument_matching_neither_the_manifests_slug_nor_its_text_domain(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		// The manifest's own slug and text_domain are both 'woocommerce'
		// (from basic_package()); passing a $domain argument matching
		// neither is exactly the caller-mismatch this guards against.
		$result = $installer->install( 'plugin', 'wrong-domain', 'zh_TW', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'domain_mismatch', $result->code() );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	/**
	 * A package whose text_domain ('Avada') differs in case from the slug
	 * ('avada'), as a theme's own header may.
	 *
	 * @return array{0:array,1:array} Manifest, zip entries.
	 */
	private function mixed_case_domain_package( string $locale ): array {
		list( $manifest, $zip ) = $this->basic_package( 'Avada', $locale, 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$manifest['slug']       = 'avada';
		$manifest['kind']       = 'theme';
		$zip['manifest.json']   = wp_json_encode( $manifest );
		return array( $manifest, $zip );
	}

	public function test_install_refuses_a_package_whose_text_domain_differs_from_the_sites_component(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->mixed_case_domain_package( 'fr_FR' );
		$this->put_fake_zip( 'zip://1', $zip );

		$expected['site_text_domain'] = 'avada';
		$result                       = $this->new_installer( $fs, $manifest )->install( 'theme', 'avada', 'fr_FR', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'text_domain_mismatch', $result->code() );
		$this->assertNull( $manifest->installed( 'theme', 'avada', 'fr_FR' ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'themes', 'Avada', 'fr_FR', 'mo' ) ) );
	}

	public function test_install_accepts_when_site_text_domain_matches_or_is_absent(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected, $zip ) = $this->mixed_case_domain_package( 'fr_FR' );
		$this->put_fake_zip( 'zip://1', $zip );
		$expected['site_text_domain'] = 'Avada';
		$result                       = $installer->install( 'theme', 'avada', 'fr_FR', 'zip://1', $expected );
		$this->assertTrue( $result->is_ok(), $result->message() );

		list( $expected, $zip ) = $this->mixed_case_domain_package( 'de_DE' );
		$this->put_fake_zip( 'zip://2', $zip );
		$result = $installer->install( 'theme', 'avada', 'de_DE', 'zip://2', $expected );
		$this->assertTrue( $result->is_ok(), $result->message() );
	}

	/**
	 * The zip's own manifest.json declares 'kind' => 'plugin' (see
	 * basic_package()); calling install() with 'theme' must be rejected
	 * rather than silently installed under the wrong kind's directory
	 * tree.
	 */
	public function test_install_rejects_when_the_zip_manifests_kind_does_not_match_the_requested_kind(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );

		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'theme', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'kind_mismatch', $result->code() );
		$this->assertNull( $manifest->installed( 'theme', 'woocommerce', 'zh_TW' ) );
	}

	// -- a served file already owned by a different component ------------

	/**
	 * A package for one component (slug $slug, text domain $text_domain,
	 * plus $extra_domains) whose every main `.mo` translates "Save" as
	 * $word, so a test can tell whose bytes a served file holds.
	 *
	 * @return array{0: array, 1: array} [manifest, zip entries].
	 */
	private function owned_package( string $slug, string $text_domain, array $extra_domains, string $server_build_id, string $word ): array {
		$mo    = $this->mo_bytes( array( 'Save' => $word ) );
		$zip   = array();
		$files = array();
		foreach ( array_merge( array( $text_domain ), $extra_domains ) as $name ) {
			$zip[ $name . '-zh_TW.mo' ]   = $mo;
			$files[ $name . '-zh_TW.mo' ] = $this->file_digest( $mo );
		}
		$manifest_json = array(
			'build_id'        => $slug . '-1.0.0-zh_TW-20260101T000000-abc123',
			'entries'         => $this->mo_entry_count( $mo ),
			'files'           => $files,
			'locale'          => 'zh_TW',
			'status'          => 'published',
			'kind'            => 'plugin',
			'slug'            => $slug,
			'version'         => '1.0.0',
			'text_domain'     => $text_domain,
			'extra_domains'   => $extra_domains,
			'server_build_id' => $server_build_id,
			'build_no'        => 1,
			'issued_at'       => '2026-01-01T00:00:00Z',
			'contract'        => '1.1.0',
		);
		$zip['manifest.json'] = wp_json_encode( $manifest_json );
		return array( $manifest_json, $zip );
	}

	private function served_word( Babilim_Fs $fs, string $name ): ?string {
		$bytes = $fs->get( Babilim_Paths::file( 'plugins', $name, 'zh_TW', 'mo' ) );
		if ( ! is_string( $bytes ) ) {
			return null;
		}
		return babilim_test_read_mo( $bytes )['Save'] ?? null;
	}

	/**
	 * Installs component B (slug `b`, text domain `b`) and returns the
	 * installer plus B's served bytes, so a test can try a second,
	 * conflicting component against it.
	 *
	 * @return array{0: Babilim_Installer, 1: string, 2: string} [installer, B's served .mo bytes, B's served .l10n.php bytes].
	 */
	private function install_component_b( Babilim_Memory_Fs $fs, Babilim_Manifest $manifest ): array {
		list( $expected, $zip ) = $this->owned_package( 'b', 'b', array(), 'bd_0000000000b1', 'B-OWN' );
		$this->put_fake_zip( 'zip://b', $zip );
		$installer = $this->new_installer( $fs, $manifest );
		$result    = $installer->install( 'plugin', 'b', 'zh_TW', 'zip://b', $expected );
		$this->assertTrue( $result->is_ok(), $result->message() );
		return array(
			$installer,
			(string) $fs->get( Babilim_Paths::file( 'plugins', 'b', 'zh_TW', 'mo' ) ),
			(string) $fs->get( Babilim_Paths::file( 'plugins', 'b', 'zh_TW', 'l10n.php' ) ),
		);
	}

	/**
	 * Asserts that component B is exactly as install_component_b() left
	 * it: same served bytes, a clean verify(), its record intact.
	 */
	private function assert_component_b_untouched( Babilim_Memory_Fs $fs, Babilim_Manifest $manifest, Babilim_Installer $installer, string $b_mo, string $b_php ): void {
		$this->assertSame( $b_mo, $fs->get( Babilim_Paths::file( 'plugins', 'b', 'zh_TW', 'mo' ) ) );
		$this->assertSame( $b_php, $fs->get( Babilim_Paths::file( 'plugins', 'b', 'zh_TW', 'l10n.php' ) ) );
		$this->assertSame( 'B-OWN', $this->served_word( $fs, 'b' ) );
		$verify = $installer->verify( 'plugin', 'b', 'zh_TW' );
		$this->assertTrue( $verify->is_ok(), (string) $verify->code() );
		$record = $manifest->installed( 'plugin', 'b', 'zh_TW' );
		$this->assertNotNull( $record );
		$this->assertSame( 'bd_0000000000b1', $record['build_id'] );
	}

	/**
	 * Component A (slug `a-plugin`) declares text domain `b` -- the
	 * same text domain component B (slug `b`) already serves. A's
	 * install fails before writing anything: B's served files keep B's
	 * bytes and still verify, A gets no record, and removing A (never
	 * installed) leaves B alone.
	 */
	public function test_install_rejects_a_text_domain_whose_served_files_belong_to_another_component(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( $installer, $b_mo, $b_php ) = $this->install_component_b( $fs, $manifest );
		$before = $fs->snapshot();

		list( $expected_a, $zip_a ) = $this->owned_package( 'a-plugin', 'b', array(), 'bd_0000000000a1', 'A-OVERWRITE' );
		$this->put_fake_zip( 'zip://a', $zip_a );
		$result = $installer->install( 'plugin', 'a-plugin', 'zh_TW', 'zip://a', $expected_a );

		$this->assertTrue( $result->is_error(), "a package claiming another component's served text domain must never install" );
		$this->assertSame( 'text_domain_in_use', $result->code() );
		$this->assertNull( $manifest->installed( 'plugin', 'a-plugin', 'zh_TW' ) );
		$this->assertSame( $before, $fs->snapshot(), 'a rejected install must not write, change or delete any file' );
		$this->assert_component_b_untouched( $fs, $manifest, $installer, $b_mo, $b_php );

		$removed = $installer->remove( 'plugin', 'a-plugin', 'zh_TW' );
		$this->assertSame( 'not_installed', $removed->code() );
		$this->assert_component_b_untouched( $fs, $manifest, $installer, $b_mo, $b_php );
	}

	/**
	 * Component X (slug and text domain `x-plugin`) lists `b` among its
	 * extra_domains -- a text domain component B already serves. The
	 * whole install fails: not even X's own primary files are written.
	 */
	public function test_install_rejects_an_extra_domain_whose_served_files_belong_to_another_component(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( $installer, $b_mo, $b_php ) = $this->install_component_b( $fs, $manifest );
		$before = $fs->snapshot();

		list( $expected_x, $zip_x ) = $this->owned_package( 'x-plugin', 'x-plugin', array( 'b' ), 'bd_0000000000c1', 'X-EXTRA' );
		$this->put_fake_zip( 'zip://x', $zip_x );
		$result = $installer->install( 'plugin', 'x-plugin', 'zh_TW', 'zip://x', $expected_x );

		$this->assertTrue( $result->is_error(), "a package claiming another component's served extra domain must never install" );
		$this->assertSame( 'text_domain_in_use', $result->code() );
		$this->assertNull( $manifest->installed( 'plugin', 'x-plugin', 'zh_TW' ) );
		$this->assertNull( $this->served_word( $fs, 'x-plugin' ) );
		$this->assertSame( $before, $fs->snapshot(), 'a rejected install must not write, change or delete any file' );
		$this->assert_component_b_untouched( $fs, $manifest, $installer, $b_mo, $b_php );

		$removed = $installer->remove( 'plugin', 'x-plugin', 'zh_TW' );
		$this->assertSame( 'not_installed', $removed->code() );
		$this->assert_component_b_untouched( $fs, $manifest, $installer, $b_mo, $b_php );
	}

	/**
	 * The ownership rule only ever blocks a *different* component: the
	 * same component re-installing a new build over its own served files
	 * still succeeds.
	 */
	public function test_install_over_the_same_components_own_served_files_still_succeeds(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		list( $installer ) = $this->install_component_b( $fs, $manifest );

		list( $expected, $zip ) = $this->owned_package( 'b', 'b', array(), 'bd_0000000000b2', 'B-NEW' );
		$this->put_fake_zip( 'zip://b2', $zip );
		$result = $installer->install( 'plugin', 'b', 'zh_TW', 'zip://b2', $expected );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertSame( 'B-NEW', $this->served_word( $fs, 'b' ) );
	}

	// -- an extra domain's own .mo entry count is verified too (not
	// just the primary domain's), and an unparseable pair can't slip
	// through a bare `!==` comparison of two `false`s. -----------------------

	public function test_install_rejects_an_extra_domain_mo_that_does_not_parse(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );

		// Structurally a valid .mo (the shared parse_fake()/transform_fake()
		// stand-ins aren't equipped to simulate a genuinely-corrupt one
		// without throwing) -- $parse itself is forced to report it as
		// unparseable below, which is what this test actually exercises.
		$extra_mo = $this->mo_bytes( array( 'Extra' => 'y' ) );
		$zip['woocommerce-addon-zh_TW.mo'] = $extra_mo;
		$zip['woocommerce-addon-zh_TW.po'] = "# addon po\n";
		$expected['extra_domains']         = array( 'woocommerce-addon' );
		$expected['files']['woocommerce-addon-zh_TW.mo'] = $this->file_digest( $extra_mo );
		$expected['files']['woocommerce-addon-zh_TW.po'] = $this->file_digest( "# addon po\n" );
		$zip['manifest.json'] = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://1', $zip );

		$real_parse          = $this->parse_fake( $fs );
		$forced_false_parse = static function ( string $path, string $format ) use ( $real_parse ) {
			if ( 'mo' === $format && false !== strpos( $path, 'woocommerce-addon' ) ) {
				return false; // Simulates $parse reporting this file as unparseable.
			}
			return call_user_func( $real_parse, $path, $format );
		};

		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $forced_false_parse, $this->unzip_fake() );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
	}

	/**
	 * Guards against the specific "two `false`s compare unequal as
	 * `!==`" loophole, isolated to where it actually matters: an EXTRA
	 * domain has no concrete expected entry count to compare against
	 * (unlike the primary domain, which is always checked against
	 * $expected_manifest['entries'] first -- a real int, so a `false`
	 * $count is already caught there regardless of an is_int() guard).
	 * $parse is forced to report the extra domain's `.mo` AND its
	 * derived `.l10n.php` as unparseable; a bare `$php_count !== $count`
	 * (`false !== false`) would be `false` -- no mismatch detected, the
	 * unparseable pair waved through -- unless both sides are also
	 * required to be a genuine int.
	 */
	public function test_install_rejects_an_extra_domain_when_both_mo_and_l10n_php_parse_return_false(): void {
		$fs                     = new Babilim_Memory_Fs();
		$manifest               = new Babilim_Manifest( $fs );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_5e2a9c1f3d70', array( 'Save' => 'x' ) );

		$extra_mo = $this->mo_bytes( array( 'Extra' => 'y' ) );
		$zip['woocommerce-addon-zh_TW.mo'] = $extra_mo;
		$zip['woocommerce-addon-zh_TW.po'] = "# addon po\n";
		$expected['extra_domains']         = array( 'woocommerce-addon' );
		$expected['files']['woocommerce-addon-zh_TW.mo'] = $this->file_digest( $extra_mo );
		$expected['files']['woocommerce-addon-zh_TW.po'] = $this->file_digest( "# addon po\n" );
		$zip['manifest.json'] = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://1', $zip );

		$real_parse          = $this->parse_fake( $fs );
		$forced_false_parse = static function ( string $path, string $format ) use ( $real_parse ) {
			if ( false !== strpos( $path, 'woocommerce-addon' ) ) {
				return false; // Both 'mo' and 'php' formats, for the extra domain only.
			}
			return call_user_func( $real_parse, $path, $format );
		};

		$installer = new Babilim_Installer( $fs, $manifest, $this->transform_fake( $fs ), $forced_false_parse, $this->unzip_fake() );
		$result    = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
	}

	// -- component identity is the package manifest's own `slug`,
	// not the text domain -- default fragments are named from it, and
	// every other lifecycle method (verify/degrade/rollback/remove) is
	// looked up by it. --------------------------------------------------------

	public function test_install_uses_slug_not_text_domain_for_default_fragment_naming_and_identity(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		// The wp.org slug ("my-plugin-slug") genuinely diverges from the
		// component's own text domain ("my-plugin-textdomain") -- a
		// directory-name-or-domain-based identity would either fail to
		// find the default fragment (a contract mismatch) or key the
		// manifest record under the wrong identity.
		$domain = 'my-plugin-textdomain';
		$slug   = 'my-plugin-slug';
		$locale = 'de_DE';

		$mo = $this->mo_bytes( array( 'Save' => 'Speichern' ) );
		$po = "# fake po\n";
		$default_mo = $this->mo_bytes( array( 'Cancel' => 'Abbrechen' ) );

		$zip = array(
			$domain . '-' . $locale . '.mo'         => $mo,
			$domain . '-' . $locale . '.po'         => $po,
			'default/' . $slug . '-' . $locale . '.mo' => $default_mo,
		);
		$manifest_json = array(
			'build_id'                => $domain . '-1.0.0-' . $locale . '-20260101T000000-abc123',
			'entries'                 => $this->mo_entry_count( $mo ),
			'files'                   => array(
				$domain . '-' . $locale . '.mo' => $this->file_digest( $mo ),
				$domain . '-' . $locale . '.po' => $this->file_digest( $po ),
			),
			'default_domain_entries'  => $this->mo_entry_count( $default_mo ),
			'default_domain_files'    => array(
				$slug . '-' . $locale . '.mo' => $this->file_digest( $default_mo ),
			),
			'locale'                  => $locale,
			'status'                  => 'published',
			'kind'                    => 'plugin',
			'slug'                    => $slug,
			'version'                 => '1.0.0',
			'text_domain'             => $domain,
			'extra_domains'           => array(),
			'server_build_id'         => 'bd_aaaaaaaaaaaa',
			'build_no'                => 1,
			'issued_at'               => '2026-01-01T00:00:00Z',
			'contract'                => '1.1.0',
		);
		$zip['manifest.json'] = wp_json_encode( $manifest_json );
		$this->put_fake_zip( 'zip://1', $zip );

		$result = $installer->install( 'plugin', $domain, $locale, 'zip://1', $manifest_json );
		$this->assertTrue( $result->is_ok(), $result->message() );

		// Default fragment named/served under the SLUG, per the contract.
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'default/_parts', $slug, $locale, 'mo' ) ) );
		// Main files remain named under the text domain.
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', $domain, $locale, 'mo' ) ) );

		// The manifest record is keyed by slug, not text domain.
		$this->assertNull( $manifest->installed( 'plugin', $domain, $locale ) );
		$record = $manifest->installed( 'plugin', $slug, $locale );
		$this->assertNotNull( $record );
		$this->assertSame( $slug, $record['slug'] );
		$this->assertSame( $domain, $record['text_domain'] );

		// verify()/degrade()/rollback()/remove() are looked up by slug.
		$verify_result = $installer->verify( 'plugin', $slug, $locale );
		$this->assertTrue( $verify_result->is_ok(), $verify_result->message() );

		$degrade_result = $installer->degrade( 'plugin', $slug, $locale );
		$this->assertTrue( $degrade_result->is_ok(), $degrade_result->message() );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', $domain, $locale, 'mo' ) ) );
	}

	// -- install()/rollback()/remove()/degrade() fire
	// `babilim_layer_changed` for the affected locale, so a listener
	// (Babilim's own wiring, in production) can rebuild the merged
	// `default` domain index. ----------------------------------------------

	public function test_install_fires_babilim_layer_changed_for_the_installed_locale(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );
		$seen      = array();
		add_action( 'babilim_layer_changed', function ( $locale ) use ( &$seen ) {
			$seen[] = $locale;
		} );

		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://1', $zip );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected );

		$this->assertContains( 'zh_TW', $seen );
	}

	public function test_remove_triggers_a_real_default_index_rebuild_that_drops_the_removed_component(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$active   = static function () {
			return array(
				array( 'kind' => 'plugin', 'slug' => 'plugin-a', 'version' => '1.0.0' ),
				array( 'kind' => 'plugin', 'slug' => 'plugin-b', 'version' => '1.0.0' ),
			);
		};
		$parse_entries = function ( string $path ) use ( $fs ) {
			$content = $fs->get( $path );
			if ( ! is_string( $content ) ) {
				return array();
			}
			if ( 0 === strpos( $content, '<?php' ) ) {
				$code = (string) preg_replace( '/^<\?php\s*/', '', $content );
				$data = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only fixture reader.
				return is_array( $data['messages'] ?? null ) ? $data['messages'] : array();
			}
			$parsed = babilim_test_read_mo( $content );
			unset( $parsed[''] );
			return $parsed;
		};
		$index = new Babilim_Default_Index( $fs, $manifest, $active, $parse_entries, static function () {
			return false;
		} );
		add_action( 'babilim_layer_changed', array( $index, 'rebuild' ) );

		$installer = $this->new_installer( $fs, $manifest );

		foreach ( array( 'plugin-a' => array( 'Save' => 'Save-A' ), 'plugin-b' => array( 'Cancel' => 'Cancel-B' ) ) as $slug => $entries ) {
			$mo  = $this->mo_bytes( $entries );
			$zip = array(
				$slug . '-ja.mo'             => $mo,
				$slug . '-ja.po'             => "# po\n",
				'default/' . $slug . '-ja.mo' => $mo,
			);
			$manifest_json = array(
				'build_id'               => $slug . '-1.0.0-ja-20260101T000000-abc123',
				'entries'                => $this->mo_entry_count( $mo ),
				'files'                  => array(
					$slug . '-ja.mo' => $this->file_digest( $mo ),
					$slug . '-ja.po' => $this->file_digest( "# po\n" ),
				),
				'default_domain_entries' => $this->mo_entry_count( $mo ),
				'default_domain_files'   => array( $slug . '-ja.mo' => $this->file_digest( $mo ) ),
				'locale'                 => 'ja',
				'status'                 => 'published',
				'kind'                   => 'plugin',
				'slug'                   => $slug,
				'version'                => '1.0.0',
				'text_domain'            => $slug,
				'extra_domains'          => array(),
				'server_build_id'        => 'bd_' . str_pad( (string) crc32( $slug ), 12, '0', STR_PAD_LEFT ),
				'build_no'               => 1,
				'issued_at'              => '2026-01-01T00:00:00Z',
				'contract'               => '1.1.0',
			);
			$zip['manifest.json'] = wp_json_encode( $manifest_json );
			$this->put_fake_zip( "zip://{$slug}", $zip );
			$result = $installer->install( 'plugin', $slug, 'ja', "zip://{$slug}", $manifest_json );
			$this->assertTrue( $result->is_ok(), $result->message() );
		}

		$before = call_user_func( $parse_entries, Babilim_Paths::merged_default( 'ja', 'mo' ) );
		$this->assertArrayHasKey( 'Save', $before );
		$this->assertArrayHasKey( 'Cancel', $before );

		$remove_result = $installer->remove( 'plugin', 'plugin-a', 'ja' );
		$this->assertTrue( $remove_result->is_ok(), $remove_result->message() );

		// The red line this proves: the merged index no longer contains plugin-a's
		// entries, and its fragment is gone from default/_parts.
		$after = call_user_func( $parse_entries, Babilim_Paths::merged_default( 'ja', 'mo' ) );
		$this->assertArrayNotHasKey( 'Save', $after );
		$this->assertArrayHasKey( 'Cancel', $after );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'default/_parts', 'plugin-a', 'ja', 'mo' ) ) );
	}

	// -- rollback() must never destroy its own durable history copy,
	// across repeated toggles. ------------------------------------------------

	public function test_rollback_three_times_in_a_row_keeps_succeeding(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected1, $zip1 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip1 );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected1 );

		list( $expected2, $zip2 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://2', $zip2 );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://2', $expected2 );

		$r1 = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertTrue( $r1->is_ok(), $r1->message() );
		$r2 = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertTrue( $r2->is_ok(), $r2->message() );
		$r3 = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertTrue( $r3->is_ok(), $r3->message() );

		// Odd number of swaps from v2 (the installed build) -> back on v1.
		$served = babilim_test_read_mo( (string) $fs->get( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertSame( 'v1', $served['Save'] );
	}

	public function test_rollback_install_rollback_keeps_succeeding(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected1, $zip1 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip1 );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected1 );

		list( $expected2, $zip2 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://2', $zip2 );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://2', $expected2 );

		$r1 = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' ); // -> v1
		$this->assertTrue( $r1->is_ok(), $r1->message() );

		list( $expected3, $zip3 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000003', array( 'Save' => 'v3' ) );
		$this->put_fake_zip( 'zip://3', $zip3 );
		$install3 = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://3', $expected3 ); // -> v3
		$this->assertTrue( $install3->is_ok(), $install3->message() );

		$r2 = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' ); // -> v1
		$this->assertTrue( $r2->is_ok(), $r2->message() );

		$served = babilim_test_read_mo( (string) $fs->get( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		$this->assertSame( 'v1', $served['Save'] );
	}

	// -- a rename failure partway through a second install() must
	// leave the first version's served files byte-identical, and clean up
	// staging/history remnants. --------------------------------------------

	public function test_a_mid_install_rename_failure_leaves_the_previous_version_untouched(): void {
		$real_fs   = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $real_fs );

		list( $expected1, $zip1 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip1 );
		( new Babilim_Installer( $real_fs, $manifest, $this->transform_fake( $real_fs ), $this->parse_fake( $real_fs ), $this->unzip_fake() ) )
			->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected1 );

		$v1_mo_path   = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );
		$v1_mo_before = $real_fs->get( $v1_mo_path );
		$this->assertNotNull( $v1_mo_before );

		// Fail only the SECOND rename this install attempts (the
		// .l10n.php, after the .mo has already been renamed once): a
		// file renamed earlier in the same call already overwrote the
		// previous version's served file by the time a later one fails,
		// so recovery must still be able to restore it. Matched by its
		// exact destination (not "the Nth rename() call overall") so the
		// recovery renames abort_finalize() itself makes afterward are
		// never also failed.
		$served_l10n_path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' );
		$already_failed    = false;
		$failing_fs        = new Babilim_Test_Installer_Failing_Rename_Fs(
			$real_fs,
			static function ( string $from, string $to ) use ( $served_l10n_path, &$already_failed ): bool {
				if ( ! $already_failed && $to === $served_l10n_path ) {
					$already_failed = true;
					return true;
				}
				return false;
			}
		);
		list( $expected2, $zip2 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://2', $zip2 );
		$installer2 = new Babilim_Installer( $failing_fs, $manifest, $this->transform_fake( $failing_fs ), $this->parse_fake( $failing_fs ), $this->unzip_fake() );
		$result     = $installer2->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://2', $expected2 );

		$this->assertFalse( $result->is_ok() );

		// The previous version's served .mo is exactly as it was.
		$this->assertSame( $v1_mo_before, $real_fs->get( $v1_mo_path ) );
		$manifest_after = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( 'bd_000000000001', $manifest_after['build_id'] );

		// The second install's own staging directory left no remnants.
		$staging_dir = Babilim_Paths::version_dir( 'plugins', 'woocommerce', 'zh_TW', 'bd_000000000002' );
		$this->assertSame( array(), $real_fs->list( $staging_dir ), 'Expected no staging remnants' );
	}

	public function test_a_file_unreadable_right_after_it_is_placed_fails_the_install_cleanly_and_rolls_back(): void {
		$real_fs  = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $real_fs );

		list( $expected1, $zip1 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip1 );
		( new Babilim_Installer( $real_fs, $manifest, $this->transform_fake( $real_fs ), $this->parse_fake( $real_fs ), $this->unzip_fake() ) )
			->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected1 );

		$v1_mo_path   = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );
		$v1_mo_before = $real_fs->get( $v1_mo_path );
		$this->assertNotNull( $v1_mo_before );

		// The .l10n.php is renamed into place fine, but reading it back
		// to hash it then returns a WP_Error.
		$served_l10n_path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'l10n.php' );
		$unreadable_fs    = new Babilim_Test_Installer_Unreadable_After_Rename_Fs( $real_fs, $served_l10n_path );

		list( $expected2, $zip2 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://2', $zip2 );
		$installer2 = new Babilim_Installer( $unreadable_fs, $manifest, $this->transform_fake( $unreadable_fs ), $this->parse_fake( $unreadable_fs ), $this->unzip_fake() );
		$result     = $installer2->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://2', $expected2 );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'hash_failed', $result->code() );

		// Backups restored: the previous version's served .mo is untouched.
		$this->assertSame( $v1_mo_before, $real_fs->get( $v1_mo_path ) );
		$manifest_after = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( 'bd_000000000001', $manifest_after['build_id'] );

		$staging_dir = Babilim_Paths::version_dir( 'plugins', 'woocommerce', 'zh_TW', 'bd_000000000002' );
		$this->assertSame( array(), $real_fs->list( $staging_dir ), 'Expected no staging remnants' );
	}

	/**
	 * A backup WRITE failure (not a rename failure) must abort before the
	 * rename it was meant to protect is even attempted -- otherwise the
	 * rename proceeds with nothing to restore $dest from if a LATER file
	 * in the same call then fails.
	 */
	public function test_a_backup_write_failure_aborts_before_the_rename_it_was_protecting(): void {
		$real_fs  = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $real_fs );

		list( $expected1, $zip1 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$this->put_fake_zip( 'zip://1', $zip1 );
		( new Babilim_Installer( $real_fs, $manifest, $this->transform_fake( $real_fs ), $this->parse_fake( $real_fs ), $this->unzip_fake() ) )
			->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected1 );

		$v1_mo_path   = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' );
		$v1_mo_before = $real_fs->get( $v1_mo_path );
		$this->assertNotNull( $v1_mo_before );

		// Fail every put() into the reserved backup area -- simulates
		// the backup write itself failing (e.g. a full disk), not the
		// forward rename.
		$failing_fs = new Babilim_Test_Installer_Failing_Put_Fs(
			$real_fs,
			static function ( string $path ): bool {
				return false !== strpos( $path, '/bd_000000000097/' );
			}
		);
		list( $expected2, $zip2 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://2', $zip2 );
		$installer2 = new Babilim_Installer( $failing_fs, $manifest, $this->transform_fake( $failing_fs ), $this->parse_fake( $failing_fs ), $this->unzip_fake() );
		$result     = $installer2->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://2', $expected2 );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'backup_failed', $result->code() );
		// $dest was never touched -- the backup failure stopped this
		// call before the rename that would have replaced it.
		$this->assertSame( $v1_mo_before, $real_fs->get( $v1_mo_path ) );
		$manifest_after = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertSame( 'bd_000000000001', $manifest_after['build_id'] );
	}

	// -- Package rejection and history behavior, all through install(). ----

	public function test_install_from_a_zip_with_mo_and_po_serves_the_mo_but_never_the_po_and_records_the_manifest(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected, $zip ) = $this->basic_package( 'legacy-plugin', 'zh_TW', 'bd_a0000d000001', array( 'Save' => 'x' ) );
		$this->put_fake_zip( 'zip://legacy-1', $zip );

		$result = $installer->install( 'plugin', 'legacy-plugin', 'zh_TW', 'zip://legacy-1', $expected );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'legacy-plugin', 'zh_TW', 'mo' ) ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'legacy-plugin', 'zh_TW', 'po' ) ), 'the .po in the package is never served' );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'legacy-plugin', 'zh_TW', 'l10n.php' ) ) );
		$this->assertNotNull( $manifest->installed( 'plugin', 'legacy-plugin', 'zh_TW' ) );
	}

	/**
	 * A draft manifest -- otherwise entirely correct, including its
	 * entries count -- must still fail install()'s strict shape
	 * validation. Every other field is deliberately kept consistent, so
	 * only 'status' being anything other than 'published' can explain a
	 * rejection here.
	 */
	public function test_install_rejects_a_draft_manifest_with_otherwise_correct_entries(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected, $zip ) = $this->basic_package( 'legacy-plugin', 'zh_TW', 'bd_a0000d000002', array( 'Save' => 'x' ) );
		$expected['status']     = 'draft';
		$zip['manifest.json']   = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://legacy-draft', $zip );

		$result = $installer->install( 'plugin', 'legacy-plugin', 'zh_TW', 'zip://legacy-draft', $expected );

		$this->assertSame( 'invalid_manifest_shape', $result->code(), 'a draft manifest must never be installed, even with correct entries' );
		$this->assertNull( $manifest->installed( 'plugin', 'legacy-plugin', 'zh_TW' ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'legacy-plugin', 'zh_TW', 'mo' ) ) );
	}

	/**
	 * A manifest declaring itself a theme's package, installed through a
	 * 'plugin' call, is rejected -- never placed under the other kind's
	 * directory tree.
	 */
	public function test_install_rejects_a_manifest_whose_kind_does_not_match_the_requested_kind(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $manifest_json, $zip ) = $this->owned_package( 'legacy-theme', 'legacy-theme', array(), 'bd_a0000d000003', 'T' );
		$manifest_json['kind']       = 'theme';
		$zip['manifest.json']        = wp_json_encode( $manifest_json );
		$this->put_fake_zip( 'zip://legacy-kind', $zip );
		$before = $fs->snapshot();

		$result = $installer->install( 'plugin', 'legacy-theme', 'zh_TW', 'zip://legacy-kind', $manifest_json );

		$this->assertSame( 'kind_mismatch', $result->code() );
		$this->assertNull( $manifest->installed( 'plugin', 'legacy-theme', 'zh_TW' ) );
		$this->assertNull( $manifest->installed( 'theme', 'legacy-theme', 'zh_TW' ) );
		$this->assertSame( $before, $fs->snapshot(), 'a rejected install must not write, change or delete any file' );
	}

	/**
	 * A package whose only servable `.mo` belongs to an extra domain --
	 * no `{text_domain}-{locale}.mo` at all -- is not a valid package
	 * for its component and never installs.
	 */
	public function test_install_rejects_a_package_without_its_own_text_domains_mo(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );

		list( $expected, $zip ) = $this->owned_package( 'woo', 'woo', array( 'addon' ), 'bd_00000000000e', 'E' );
		unset( $zip['woo-zh_TW.mo'], $expected['files']['woo-zh_TW.mo'] );
		$zip['manifest.json'] = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://extra-only', $zip );
		$before = $fs->snapshot();

		$result = $this->new_installer( $fs, $manifest )->install( 'plugin', 'woo', 'zh_TW', 'zip://extra-only', $expected );

		$this->assertTrue( $result->is_error(), "a package without the component's own text domain .mo must never install" );
		$this->assertSame( 'missing_text_domain_mo', $result->code() );
		$this->assertNull( $manifest->installed( 'plugin', 'woo', 'zh_TW' ) );
		$this->assertSame( $before, $fs->snapshot(), 'a rejected install must not leave any file behind' );
	}


	public function test_install_with_a_sha256_mismatch_leaves_no_file_behind(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected, $zip ) = $this->basic_package( 'legacy-plugin', 'zh_TW', 'bd_a0000d000002', array( 'Save' => 'x' ) );
		$expected['files']['legacy-plugin-zh_TW.mo']['sha256'] = str_repeat( '0', 64 );
		$zip['manifest.json'] = wp_json_encode( $expected );
		$this->put_fake_zip( 'zip://legacy-sha', $zip );
		$before = $fs->snapshot();

		$result = $installer->install( 'plugin', 'legacy-plugin', 'zh_TW', 'zip://legacy-sha', $expected );

		$this->assertFalse( $result->is_ok() );
		$this->assertNull( $manifest->installed( 'plugin', 'legacy-plugin', 'zh_TW' ) );
		$this->assertSame( $before, $fs->snapshot(), 'a rejected install must not leave any file behind' );
	}

	public function test_install_ignores_a_php_entry_sitting_in_the_zip(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $expected, $zip ) = $this->basic_package( 'legacy-plugin', 'zh_TW', 'bd_a0000d000004', array( 'Save' => 'x' ) );
		// A stray .php sitting alongside the legitimate files.
		$zip['legacy-plugin-zh_TW.l10n.php'] = "<?php return array( 'messages' => array( 'PWNED' => '1' ) );";
		$this->put_fake_zip( 'zip://legacy-php', $zip );

		$result = $installer->install( 'plugin', 'legacy-plugin', 'zh_TW', 'zip://legacy-php', $expected );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$served = $fs->get( Babilim_Paths::file( 'plugins', 'legacy-plugin', 'zh_TW', 'l10n.php' ) );
		$this->assertStringNotContainsString( 'PWNED', (string) $served );
		foreach ( $fs->snapshot() as $path => $bytes ) {
			$this->assertStringNotContainsString( 'PWNED', (string) $bytes, "the stray .php entry must never be written anywhere ($path)" );
		}
	}

	/**
	 * install_from_entries()'s internal `_versions/.../{server_build_id}/`
	 * staging+history keeps every installed build's own durable copy, so
	 * installing a second build and then rolling back must return to the
	 * first one intact.
	 */
	public function test_install_then_install_then_rollback_returns_to_the_first_build(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		list( $first_manifest, $first_zip ) = $this->basic_package( 'legacy-plugin', 'zh_TW', 'bd_000000000a01', array( 'Save' => 'first' ) );
		$this->put_fake_zip( 'zip://n1', $first_zip );
		$first_result = $installer->install( 'plugin', 'legacy-plugin', 'zh_TW', 'zip://n1', $first_manifest );
		$this->assertTrue( $first_result->is_ok(), $first_result->message() );

		list( $install_manifest, $zip ) = $this->basic_package( 'legacy-plugin', 'zh_TW', 'bd_000000000a02', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://n2', $zip );
		$install_result = $installer->install( 'plugin', 'legacy-plugin', 'zh_TW', 'zip://n2', $install_manifest );
		$this->assertTrue( $install_result->is_ok(), $install_result->message() );

		$rollback_result = $installer->rollback( 'plugin', 'legacy-plugin', 'zh_TW' );

		$this->assertTrue( $rollback_result->is_ok(), $rollback_result->message() );
		$this->assertSame( 'bd_000000000a01', $rollback_result->get( 'build_id' ) );
		$served = babilim_test_read_mo( (string) $fs->get( Babilim_Paths::file( 'plugins', 'legacy-plugin', 'zh_TW', 'mo' ) ) );
		$this->assertSame( 'first', $served['Save'] );
	}

	// -- one build appears once in version_history ---------------------------

	/** Installs one basic build ($n selects build id and text) and asserts success. */
	private function install_build_n( Babilim_Installer $installer, int $n ): void {
		$id = sprintf( 'bd_%012d', $n );
		list( $expected, $zip ) = $this->basic_package( 'woocommerce', 'zh_TW', $id, array( 'Save' => 'v' . $n ) );
		$this->put_fake_zip( 'zip://dedupe/' . $n, $zip );
		$result = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://dedupe/' . $n, $expected );
		$this->assertTrue( $result->is_ok(), $result->message() );
	}

	private function history_ids( Babilim_Manifest $manifest ): array {
		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		return array_map( static function ( $e ) { return $e['build_id']; }, $record['version_history'] );
	}

	private function served_save( Babilim_Memory_Fs $fs ): string {
		$mo = babilim_test_read_mo( (string) $fs->get( Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'mo' ) ) );
		return (string) $mo['Save'];
	}

	/** Every file of every history entry, and the served files, are on disk. */
	private function assert_history_files_on_disk( Babilim_Memory_Fs $fs, Babilim_Manifest $manifest ): void {
		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		foreach ( $record['version_history'] as $entry ) {
			foreach ( array_merge( array_values( $entry['main_served'] ), array_values( $entry['default_served'] ) ) as $path ) {
				$this->assertTrue( $fs->exists( $path ), 'history file missing: ' . $path );
			}
		}
		foreach ( array_keys( $record['files'] ) as $relative ) {
			$this->assertTrue( $fs->exists( Babilim_Paths::root() . '/' . $relative ), 'served file missing: ' . $relative );
		}
	}

	public function test_reinstalling_the_same_build_after_a_degrade_keeps_one_history_entry(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		$this->install_build_n( $installer, 1 );
		$this->assertTrue( $installer->degrade( 'plugin', 'woocommerce', 'zh_TW' )->is_ok() );
		$this->install_build_n( $installer, 1 );

		$this->assertSame( array( 'bd_000000000001' ), $this->history_ids( $manifest ) );
		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertEmpty( $record['previous_build_id'], 'no different previous build' );
		$this->assertSame( 'v1', $this->served_save( $fs ) );
		$this->assert_history_files_on_disk( $fs, $manifest );
		$this->assertSame( 'no_previous_version', $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' )->code() );
	}

	public function test_install_rollback_install_again_gives_two_entries_and_rollback_keeps_working(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		$this->install_build_n( $installer, 1 );
		$this->install_build_n( $installer, 2 );
		$this->assertTrue( $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' )->is_ok() ); // -> A.
		$this->assertSame( array( 'bd_000000000001', 'bd_000000000002' ), $this->history_ids( $manifest ) );

		// Update to B again (B is already in the history).
		$this->install_build_n( $installer, 2 );
		$this->assertSame( array( 'bd_000000000002', 'bd_000000000001' ), $this->history_ids( $manifest ) );
		$this->assertSame( 'bd_000000000001', $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' )['previous_build_id'] );
		$this->assertSame( 'v2', $this->served_save( $fs ) );
		$this->assert_history_files_on_disk( $fs, $manifest );

		$r = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( 'bd_000000000001', $r->get( 'build_id' ) );
		$this->assertSame( 'v1', $this->served_save( $fs ) );
		$this->assertSame( array( 'bd_000000000001', 'bd_000000000002' ), $this->history_ids( $manifest ) );
		$this->assert_history_files_on_disk( $fs, $manifest );
	}

	public function test_a_record_with_duplicate_history_heals_on_the_next_install(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );
		$this->install_build_n( $installer, 1 );
		$this->install_build_n( $installer, 2 );

		// Older versions wrote [B, A, B].
		$record                    = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$record['version_history'] = array( $record['version_history'][0], $record['version_history'][1], $record['version_history'][0] );
		$this->assertTrue( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $record ) );

		$this->install_build_n( $installer, 3 );
		$this->assertSame( array( 'bd_000000000003', 'bd_000000000002', 'bd_000000000001' ), $this->history_ids( $manifest ) );
		$this->assert_history_files_on_disk( $fs, $manifest );
	}

	public function test_reinstalling_a_build_over_a_legacy_duplicate_record_heals_to_one_entry(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );
		$this->install_build_n( $installer, 1 );
		$this->assertTrue( $installer->degrade( 'plugin', 'woocommerce', 'zh_TW' )->is_ok() );

		// Older versions wrote [A, A] with previous_build_id = A.
		$record                      = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$record['version_history']   = array( $record['version_history'][0], $record['version_history'][0] );
		$record['previous_build_id'] = $record['build_id'];
		$this->assertTrue( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $record ) );

		$this->install_build_n( $installer, 1 );
		$this->assertSame( array( 'bd_000000000001' ), $this->history_ids( $manifest ) );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' )['previous_build_id'] );
		$this->assertSame( 'v1', $this->served_save( $fs ) );
		$this->assert_history_files_on_disk( $fs, $manifest );
	}

	public function test_rollback_on_a_legacy_b_a_b_record_restores_a(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );
		$this->install_build_n( $installer, 1 );
		$this->install_build_n( $installer, 2 );

		$record                    = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$record['version_history'] = array( $record['version_history'][0], $record['version_history'][1], $record['version_history'][0] ); // [B, A, B].
		$this->assertTrue( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $record ) );

		$r = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( 'bd_000000000001', $r->get( 'build_id' ) );
		$this->assertSame( 'v1', $this->served_save( $fs ) );
		$this->assertSame( array( 'bd_000000000001', 'bd_000000000002' ), $this->history_ids( $manifest ) );
		$this->assert_history_files_on_disk( $fs, $manifest );
	}

	public function test_rollback_skips_history_entries_equal_to_the_current_build(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );
		$this->install_build_n( $installer, 1 );
		$this->install_build_n( $installer, 2 );

		$record                    = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$b                         = $record['version_history'][0];
		$a                         = $record['version_history'][1];
		$record['version_history'] = array( $b, $b, $a );
		$this->assertTrue( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $record ) );

		// [B, B, A]: the entry behind the head equals the current build; A must be restored.
		$r = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertTrue( $r->is_ok(), $r->message() );
		$this->assertSame( 'bd_000000000001', $r->get( 'build_id' ) );
		$this->assertSame( 'v1', $this->served_save( $fs ) );
		$this->assertSame( array( 'bd_000000000001', 'bd_000000000002' ), $this->history_ids( $manifest ) );
		$this->assertSame( 'bd_000000000002', $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' )['previous_build_id'] );
		$this->assert_history_files_on_disk( $fs, $manifest );
	}

	public function test_rollback_fails_when_every_other_history_entry_is_the_current_build(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );
		$this->install_build_n( $installer, 1 );

		$record                    = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$record['version_history'] = array( $record['version_history'][0], $record['version_history'][0] ); // [A, A].
		$this->assertTrue( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $record ) );

		$r = $installer->rollback( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertFalse( $r->is_ok() );
		$this->assertSame( 'no_previous_version', $r->code() );
		$this->assertSame( 'v1', $this->served_save( $fs ) );
	}

	// -- reinstalling with a different file set removes the
	// previous build's now-stale served file. -------------------------------

	public function test_reinstalling_with_a_different_file_set_removes_the_stale_file(): void {
		$fs        = new Babilim_Memory_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = $this->new_installer( $fs, $manifest );

		// v1 ships a JS script catalog.
		list( $expected1, $zip1 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000001', array( 'Save' => 'v1' ) );
		$md5    = md5( 'assets/a.js' );
		$json   = wp_json_encode( array( 'domain' => 'messages', 'locale_data' => array( 'messages' => array( '' => array(), 'Alpha' => array( 'Alpha-TW' ) ) ) ) );
		$zip1[ "woocommerce-zh_TW-{$md5}.json" ] = $json;
		$expected1['files'][ "woocommerce-zh_TW-{$md5}.json" ] = $this->file_digest( $json );
		$zip1['manifest.json'] = wp_json_encode( $expected1 );
		$this->put_fake_zip( 'zip://1', $zip1 );
		$installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://1', $expected1 );

		$served_json_path = Babilim_Paths::file( 'plugins', 'woocommerce', 'zh_TW', 'json' );
		$this->assertTrue( $fs->exists( $served_json_path ) );

		// v2 ships no JS catalog at all.
		list( $expected2, $zip2 ) = $this->basic_package( 'woocommerce', 'zh_TW', 'bd_000000000002', array( 'Save' => 'v2' ) );
		$this->put_fake_zip( 'zip://2', $zip2 );
		$result = $installer->install( 'plugin', 'woocommerce', 'zh_TW', 'zip://2', $expected2 );
		$this->assertTrue( $result->is_ok(), $result->message() );

		// The red line this proves: v1's now-orphaned .json is gone.
		$this->assertFalse( $fs->exists( $served_json_path ) );
		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertArrayNotHasKey( 'plugins/woocommerce-zh_TW.json', $record['files'] );
	}
}

/**
 * A Babilim_Fs decorator whose rename() fails exactly when a given
 * predicate says so, delegating every other call (including every OTHER
 * rename()) to a real (in-memory) Babilim_Fs -- used to exercise "a
 * rename failure partway through a second install() must not corrupt
 * the previous version's served files" without needing a real failing
 * filesystem, and without also failing the recovery renames
 * abort_finalize() itself makes afterward (a raw "fail after N calls"
 * counter would catch those too).
 */
final class Babilim_Test_Installer_Failing_Rename_Fs implements Babilim_Fs {

	private $inner;
	private $fail_when;

	/**
	 * @param Babilim_Fs $inner     Real filesystem to delegate to.
	 * @param callable   $fail_when fn(string $from, string $to): bool.
	 */
	public function __construct( Babilim_Fs $inner, callable $fail_when ) {
		$this->inner    = $inner;
		$this->fail_when = $fail_when;
	}

	public function put( string $path, string $contents ): bool {
		return $this->inner->put( $path, $contents );
	}

	public function get( string $path ) {
		return $this->inner->get( $path );
	}

	public function exists( string $path ): bool {
		return $this->inner->exists( $path );
	}

	public function delete( string $path ): bool {
		return $this->inner->delete( $path );
	}

	public function rename( string $from, string $to ): bool {
		if ( call_user_func( $this->fail_when, $from, $to ) ) {
			return false;
		}
		return $this->inner->rename( $from, $to );
	}

	public function mkdir( string $path ): bool {
		return $this->inner->mkdir( $path );
	}

	public function list( string $dir, string $glob = '*' ): array {
		return $this->inner->list( $dir, $glob );
	}

	public function sha256( string $path ) {
		return $this->inner->sha256( $path );
	}

	public function rmdir( string $path ): bool {
		return $this->inner->rmdir( $path );
	}

	public function is_dir( string $path ): bool {
		return $this->inner->is_dir( $path );
	}
}

/**
 * A Babilim_Fs decorator whose sha256() returns a WP_Error for one path
 * once a rename() has moved a file onto it -- a file that is placed
 * fine but cannot be read back to hash.
 */
final class Babilim_Test_Installer_Unreadable_After_Rename_Fs implements Babilim_Fs {

	private $inner;
	private $unreadable_path;
	private $placed = false;

	public function __construct( Babilim_Fs $inner, string $unreadable_path ) {
		$this->inner           = $inner;
		$this->unreadable_path = $unreadable_path;
	}

	public function put( string $path, string $contents ): bool {
		return $this->inner->put( $path, $contents );
	}

	public function get( string $path ) {
		return $this->inner->get( $path );
	}

	public function exists( string $path ): bool {
		return $this->inner->exists( $path );
	}

	public function delete( string $path ): bool {
		return $this->inner->delete( $path );
	}

	public function rename( string $from, string $to ): bool {
		$ok = $this->inner->rename( $from, $to );
		if ( $ok && $to === $this->unreadable_path ) {
			$this->placed = true;
		}
		return $ok;
	}

	public function mkdir( string $path ): bool {
		return $this->inner->mkdir( $path );
	}

	public function list( string $dir, string $glob = '*' ): array {
		return $this->inner->list( $dir, $glob );
	}

	public function sha256( string $path ) {
		if ( $this->placed && $path === $this->unreadable_path ) {
			return new WP_Error( 'babilim_unreadable', 'Cannot read the file back.' );
		}
		return $this->inner->sha256( $path );
	}

	public function rmdir( string $path ): bool {
		return $this->inner->rmdir( $path );
	}

	public function is_dir( string $path ): bool {
		return $this->inner->is_dir( $path );
	}
}

/**
 * A Babilim_Fs decorator whose put() fails exactly when a given
 * predicate says so -- used to exercise a backup-write failure (as
 * opposed to a rename failure) in place_served_files().
 */
final class Babilim_Test_Installer_Failing_Put_Fs implements Babilim_Fs {

	private $inner;
	private $fail_when;

	/**
	 * @param Babilim_Fs $inner     Real filesystem to delegate to.
	 * @param callable   $fail_when fn(string $path): bool.
	 */
	public function __construct( Babilim_Fs $inner, callable $fail_when ) {
		$this->inner     = $inner;
		$this->fail_when = $fail_when;
	}

	public function put( string $path, string $contents ): bool {
		if ( call_user_func( $this->fail_when, $path ) ) {
			return false;
		}
		return $this->inner->put( $path, $contents );
	}

	public function get( string $path ) {
		return $this->inner->get( $path );
	}

	public function exists( string $path ): bool {
		return $this->inner->exists( $path );
	}

	public function delete( string $path ): bool {
		return $this->inner->delete( $path );
	}

	public function rename( string $from, string $to ): bool {
		return $this->inner->rename( $from, $to );
	}

	public function mkdir( string $path ): bool {
		return $this->inner->mkdir( $path );
	}

	public function list( string $dir, string $glob = '*' ): array {
		return $this->inner->list( $dir, $glob );
	}

	public function sha256( string $path ) {
		return $this->inner->sha256( $path );
	}

	public function rmdir( string $path ): bool {
		return $this->inner->rmdir( $path );
	}

	public function is_dir( string $path ): bool {
		return $this->inner->is_dir( $path );
	}
}
