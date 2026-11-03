<?php
/**
 * Tests for Babilim_Translation_Search -- the local search over the
 * translations recorded in the manifest.
 *
 * $parse_entries is faked as a lookup of canned entries keyed by the
 * absolute path of the main `.mo` file, so no `.mo` encoding is involved.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Translation_Search extends TestCase {

	/**
	 * Paths the fake parse_entries was asked to read.
	 *
	 * @var stdClass
	 */
	private $parsed;

	/**
	 * @var Babilim_Memory_Fs
	 */
	private $fs;

	protected function setUp(): void {
		babilim_test_reset();
		$this->parsed        = new stdClass();
		$this->parsed->paths = array();
	}

	private function relative( string $slug, string $locale, string $ext = 'mo' ): string {
		return str_replace( Babilim_Paths::root() . '/', '', Babilim_Paths::file( 'plugins', $slug, $locale, $ext ) );
	}

	/**
	 * @param array<string,array> $by_slug slug => entries for that slug's main .mo.
	 * @return Babilim_Translation_Search
	 */
	private function build( array $by_slug, string $locale = 'fr_FR', array $extra_records = array(), array $extra_mos = array() ): Babilim_Translation_Search {
		$fs       = new Babilim_Memory_Fs();
		$this->fs = $fs;
		$manifest = new Babilim_Manifest( $fs );
		$canned   = array();
		foreach ( $by_slug as $slug => $entries ) {
			$mo                = $this->relative( $slug, $locale );
			$json              = $this->relative( $slug, $locale, 'json' );
			$canned[ Babilim_Paths::root() . '/' . $mo ] = $entries;
			$fs->put( Babilim_Paths::root() . '/' . $mo, 'mo bytes ' . $slug );
			$files = array(
				$mo   => hash( 'sha256', 'mo bytes ' . $slug ),
				$json => 'sha-b',
			);
			// $extra_mos: slug => (relative path => entries), an extra text
			// domain's .mo recorded in the same manifest record.
			foreach ( (array) ( $extra_mos[ $slug ] ?? array() ) as $relative => $extra_entries ) {
				$canned[ Babilim_Paths::root() . '/' . $relative ] = $extra_entries;
				$fs->put( Babilim_Paths::root() . '/' . $relative, 'mo bytes ' . $relative );
				$files[ $relative ] = hash( 'sha256', 'mo bytes ' . $relative );
			}
			$manifest->set(
				'plugin',
				$slug,
				$locale,
				array(
					'component_version' => '1.0.0',
					'build_id'          => 'build-' . $slug,
					'files'             => $files,
					'default_files'     => array( 'default/_parts/' . $slug . '-' . $locale . '.mo' => 'sha-c' ),
				)
			);
		}
		foreach ( $extra_records as $r ) {
			$manifest->set( $r['kind'], $r['slug'], $r['locale'], $r );
		}
		$log   = $this->parsed;
		$parse = static function ( string $path ) use ( $canned, $log ): array {
			$log->paths[] = $path;
			if ( isset( $log->on_parse ) ) {
				call_user_func( $log->on_parse, $path );
			}
			return $canned[ $path ] ?? array();
		};
		$label = static function ( string $kind, string $slug ): string {
			return strtoupper( $slug );
		};
		return new Babilim_Translation_Search( $manifest, $parse, $label, $fs );
	}

	public function test_search_matches_msgid_and_msgstr_case_insensitively(): void {
		$search = $this->build(
			array(
				'alpha' => array(
					"\x04Save"    => 'Enregistrer',
					"\x04Cancel"  => 'Annuler',
					"menu\x04Open" => 'Ouvrir',
				),
			)
		);
		$by_id = $search->search( 'sAvE', 'fr_FR' );
		$this->assertCount( 1, $by_id );
		$this->assertSame( 'Save', $by_id[0]['msgid'] );
		$this->assertSame( array( 'Enregistrer' ), $by_id[0]['msgstr'] );
		$this->assertSame( '', $by_id[0]['msgctxt'] );

		$by_str = $search->search( 'OUVRIR', 'fr_FR' );
		$this->assertCount( 1, $by_str );
		$this->assertSame( 'Open', $by_str[0]['msgid'] );
		$this->assertSame( 'menu', $by_str[0]['msgctxt'] );
		$this->assertSame( 'plugin', $by_str[0]['kind'] );
		$this->assertSame( 'alpha', $by_str[0]['slug'] );
		$this->assertSame( 'ALPHA', $by_str[0]['label'] );
		$this->assertSame( 'fr_FR', $by_str[0]['locale'] );
	}

	public function test_search_returns_plural_forms_together(): void {
		// The production parser (WP_Translation_File::entries()) joins the
		// plural forms into one NUL-separated string; a plain array is
		// also accepted. Both must come back as separate forms.
		$search = $this->build(
			array(
				'alpha' => array(
					"\x04%d item" => "%d article\0%d articles",
					"\x04%d file" => array( '%d fichier', '%d fichiers' ),
				),
			)
		);
		$hits = $search->search( 'articles', 'fr_FR' );
		$this->assertCount( 1, $hits );
		$this->assertSame( array( '%d article', '%d articles' ), $hits[0]['msgstr'] );

		$hits = $search->search( 'fichiers', 'fr_FR' );
		$this->assertCount( 1, $hits );
		$this->assertSame( array( '%d fichier', '%d fichiers' ), $hits[0]['msgstr'] );
	}

	public function test_search_truncates_each_plural_form_separately(): void {
		$search = $this->build(
			array( 'alpha' => array( "\x04%d item" => str_repeat( 'a', 800 ) . "\0" . str_repeat( 'b', 800 ) ) )
		);
		$hits = $search->search( 'bbbb', 'fr_FR' );
		$this->assertCount( 1, $hits );
		$this->assertCount( 2, $hits[0]['msgstr'] );
		$this->assertSame( 500, strlen( $hits[0]['msgstr'][0] ) );
		$this->assertSame( 500, strlen( $hits[0]['msgstr'][1] ) );
	}

	public function test_search_only_current_locale_and_only_manifest_records(): void {
		$search = $this->build(
			array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ),
			'fr_FR',
			array(
				array(
					'kind'          => 'plugin',
					'slug'          => 'beta',
					'locale'        => 'de_DE',
					'build_id'      => 'b2',
					'files'         => array( $this->relative( 'beta', 'de_DE' ) => 'x' ),
					'default_files' => array(),
				),
			)
		);
		$this->assertTrue( $search->has_locale( 'fr_FR' ) );
		$this->assertTrue( $search->has_locale( 'de_DE' ) );
		$this->assertFalse( $search->has_locale( 'ja' ) );
		$this->assertSame( array(), $search->search( 'Save', 'de_DE' ) );
		$this->assertSame( array(), $search->search( 'Save', 'ja' ) );
		$this->parsed->paths = array();
		$this->assertCount( 1, $search->search( 'Save', 'fr_FR' ) );
		// Only the main .mo of the fr_FR record is parsed: not .json, not default_files, not de_DE.
		$this->assertSame(
			array( Babilim_Paths::root() . '/' . $this->relative( 'alpha', 'fr_FR' ) ),
			$this->parsed->paths
		);
	}

	public function test_search_caps_count_and_truncates_long_msgstr_but_not_msgid(): void {
		$entries = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$entries[ sprintf( "\x04Word %02d", $i ) ] = 'x';
		}
		$entries["\x04Long word"] = str_repeat( 'a', 800 );
		$search = $this->build( array( 'alpha' => $entries ) );

		$this->assertCount( 20, $search->search( 'word', 'fr_FR' ) );
		$this->assertCount( 5, $search->search( 'word', 'fr_FR', 5 ) );

		$hits = $search->search( 'aaaa', 'fr_FR' );
		$this->assertCount( 1, $hits );
		$this->assertSame( 500, strlen( $hits[0]['msgstr'][0] ) );

		$long_id = str_repeat( 'q', 700 );
		$s2 = $this->build( array( 'alpha' => array( "\x04" . $long_id => 'ok' ) ) );
		$h2         = $s2->search( 'qq', 'fr_FR' );
		$this->assertSame( $long_id, $h2[0]['msgid'], 'msgid identifies the string and is never cut' );

		// Multibyte: cut by character, not by byte.
		$s3 = $this->build( array( 'alpha' => array( "\x04wide" => str_repeat( "\xC3\xA9", 800 ) ) ) );
		$h3 = $s3->search( 'wide', 'fr_FR' );
		$this->assertSame( str_repeat( "\xC3\xA9", 500 ), $h3[0]['msgstr'][0] );
	}

	public function test_search_keeps_a_600_character_msgid_and_msgctxt_whole(): void {
		$id  = str_repeat( 'k', 600 );
		$ctx = str_repeat( 'c', 700 );
		$s   = $this->build( array( 'alpha' => array( $ctx . "\x04" . $id => 'ok' ) ) );
		$hit = $s->search( 'kkkk', 'fr_FR' );
		$this->assertCount( 1, $hit );
		$this->assertSame( $id, $hit[0]['msgid'] );
		$this->assertSame( $ctx, $hit[0]['msgctxt'] );
	}

	public function test_search_leaves_out_an_entry_the_service_would_refuse(): void {
		$s = $this->build(
			array(
				'alpha' => array(
					"\x04" . str_repeat( 'z', 4001 ) => 'too long',
					"\x04" . str_repeat( 'z', 4000 ) => 'at the limit',
					str_repeat( 'c', 4001 ) . "\x04zzshort" => 'long context',
				),
			)
		);
		$hits = $s->search( 'zz', 'fr_FR' );
		$this->assertCount( 1, $hits );
		$this->assertSame( 4000, strlen( $hits[0]['msgid'] ) );
	}

	public function test_search_skips_records_that_are_not_a_plugin_or_a_theme(): void {
		$s = $this->build(
			array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ),
			'fr_FR',
			array(
				array(
					'kind'   => 'default',
					'slug'   => 'beta',
					'locale' => 'fr_FR',
					'files'  => array( $this->relative( 'alpha', 'fr_FR' ) => 'x' ),
				),
			)
		);
		$kinds = array_unique( array_column( $s->search( 'save', 'fr_FR' ), 'kind' ) );
		$this->assertSame( array( 'plugin' ), array_values( $kinds ) );
	}

	public function test_search_is_case_insensitive_for_multibyte_text(): void {
		$search = $this->build( array( 'alpha' => array( "\x04Title" => "\xC3\x89t\xC3\xA9" ) ) );
		$this->assertCount( 1, $search->search( "\xC3\xA9T\xC3\xA9", 'fr_FR' ) );
	}

	public function test_search_sorts_by_label_then_msgid(): void {
		$search = $this->build(
			array(
				'zeta'  => array( "\x04b key" => 'v' ),
				'alpha' => array( "\x04z key" => 'v', "\x04a key" => 'v' ),
			)
		);
		$ids = array_map(
			static function ( array $h ): string {
				return $h['label'] . ':' . $h['msgid'];
			},
			$search->search( 'key', 'fr_FR' )
		);
		$this->assertSame( array( 'ALPHA:a key', 'ALPHA:z key', 'ZETA:b key' ), $ids );
	}

	public function test_search_rejects_short_query(): void {
		$search = $this->build( array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ) );
		$this->assertSame( array(), $search->search( 'S', 'fr_FR' ) );
		$this->assertSame( array(), $search->search( '  S ', 'fr_FR' ) );
		$this->assertSame( array(), $search->search( '', 'fr_FR' ) );
		$this->assertSame( array(), $search->search( str_repeat( 'a', 201 ), 'fr_FR' ) );
		$this->assertCount( 1, $search->search( ' Sa ', 'fr_FR' ) );
	}

	public function test_search_result_carries_build_id_from_manifest(): void {
		$search = $this->build( array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ) );
		$hits           = $search->search( 'Save', 'fr_FR' );
		$this->assertSame( 'build-alpha', $hits[0]['build_id'] );
	}

	public function test_search_sorts_without_regard_to_case(): void {
		$search = $this->build(
			array(
				'alpha' => array( "\x04banana key" => 'v', "\x04Cherry key" => 'v', "\x04apple key" => 'v', "\x04Zebra key" => 'v' ),
			)
		);
		$ids = array_column( $search->search( 'key', 'fr_FR' ), 'msgid' );
		$this->assertSame( array( 'apple key', 'banana key', 'Cherry key', 'Zebra key' ), $ids );
	}

	private function alpha_path(): string {
		return Babilim_Paths::root() . '/' . $this->relative( 'alpha', 'fr_FR' );
	}

	public function test_search_results_carry_a_stable_reference(): void {
		$this->assertSame( hash( 'sha256', "ctx\x04Cart" ), Babilim_Translation_Search::ref( 'ctx', 'Cart' ) );
		$this->assertNotSame( Babilim_Translation_Search::ref( '', 'Cart' ), Babilim_Translation_Search::ref( 'ctx', 'Cart' ) );
		$search = $this->build( array( 'alpha' => array( "ctx\x04Cart" => 'Panier' ) ) );
		$hits   = $search->search( 'Cart', 'fr_FR' );
		$this->assertSame( Babilim_Translation_Search::ref( 'ctx', 'Cart' ), $hits[0]['ref'] );
	}

	public function test_find_returns_the_string_behind_a_reference_from_the_installed_file(): void {
		$search = $this->build( array( 'alpha' => array( "ctx\x04%d item" => "%d article\0%d articles", "\x04Other" => 'Autre' ) ) );
		$found  = $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( 'ctx', '%d item' ) );
		$this->assertSame(
			array(
				'msgctxt'  => 'ctx',
				'msgid'    => '%d item',
				'msgstr'   => array( '%d article', '%d articles' ),
				'build_id' => 'build-alpha',
			),
			$found
		);
	}

	public function test_find_is_null_for_an_unknown_reference_component_or_locale(): void {
		$search = $this->build( array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ) );
		$ref    = Babilim_Translation_Search::ref( '', 'Save' );
		$this->assertNotNull( $search->find( 'plugin', 'alpha', 'fr_FR', $ref ) );
		$this->assertNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', 'Nope' ) ) );
		$this->assertNull( $search->find( 'plugin', 'beta', 'fr_FR', $ref ) );
		$this->assertNull( $search->find( 'theme', 'alpha', 'fr_FR', $ref ) );
		$this->assertNull( $search->find( 'plugin', 'alpha', 'de_DE', $ref ) );
	}

	public function test_find_is_null_when_the_original_is_over_the_feedback_limit(): void {
		$long   = str_repeat( 'z', 4001 );
		$search = $this->build( array( 'alpha' => array( "\x04" . $long => 'x' ) ) );
		$this->assertNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', $long ) ) );
	}

	public function test_find_reads_only_the_named_records_mo_file(): void {
		$search = $this->build(
			array(
				'alpha' => array( "\x04Save" => 'Enregistrer' ),
				'beta'  => array( "\x04Save" => 'Sauver' ),
			)
		);
		$this->parsed->paths = array();
		$this->assertNotNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', 'Save' ) ) );
		$this->assertSame( array( $this->alpha_path() ), $this->parsed->paths );
	}

	public function test_find_is_null_when_the_file_hash_differs_from_the_manifest_record(): void {
		$search = $this->build( array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ) );
		$this->fs->put( $this->alpha_path(), 'other bytes' );
		$this->assertNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', 'Save' ) ) );
		$this->assertSame( array(), $this->parsed->paths, 'a file that does not match its record is not parsed' );
	}

	public function test_find_is_null_when_the_file_changes_during_the_read(): void {
		$search = $this->build( array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ) );
		$fs     = $this->fs;
		$path   = $this->alpha_path();

		$this->parsed->on_parse = static function () use ( $fs, $path ): void {
			$fs->put( $path, 'replaced mid read' );
		};
		$this->assertNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', 'Save' ) ) );
	}

	public function test_split_forms_of_null_is_an_empty_list(): void {
		$search = $this->build( array() );
		$m      = new ReflectionMethod( Babilim_Translation_Search::class, 'split_forms' );
		$m->setAccessible( true );
		$this->assertSame( array(), $m->invoke( $search, null ) );
		$this->assertSame( array( 'a', 'b' ), $m->invoke( $search, "a\0b" ) );
	}

	public function test_search_finds_text_with_percent_octets_and_tags(): void {
		$search = $this->build( array( 'alpha' => array( "\x04Use %de for x" => 'Utiliser %de', "\x04Bold <b>text</b>" => 'Gras <b>texte</b>' ) ) );
		$this->assertCount( 1, $search->search( '%de', 'fr_FR' ) );
		$this->assertCount( 1, $search->search( '<b>', 'fr_FR' ) );
	}

	public function test_search_result_does_not_carry_a_current_translation_key(): void {
		$whole  = str_repeat( 'a', 800 );
		$search = $this->build( array( 'alpha' => array( "\x04Long" => $whole . "\0" . 'tail' ) ) );
		$hit    = $search->search( 'Long', 'fr_FR' )[0];
		$this->assertArrayNotHasKey( 'current_msgstr', $hit );
		$this->assertSame( 500, strlen( $hit['msgstr'][0] ) );
	}

	public function test_find_reads_a_string_from_an_extra_domain_mo_in_the_same_record(): void {
		$extra  = $this->relative( 'alpha', 'fr_FR', 'mo' ) . '.extra.mo';
		$search = $this->build(
			array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ),
			'fr_FR',
			array(),
			array( 'alpha' => array( $extra => array( "\x04Library word" => 'Mot' ) ) )
		);
		$hits = $search->search( 'Library', 'fr_FR' );
		$this->assertCount( 1, $hits );
		$found = $search->find( 'plugin', 'alpha', 'fr_FR', $hits[0]['ref'] );
		$this->assertNotNull( $found );
		$this->assertSame( 'Library word', $found['msgid'] );
		$this->assertSame( array( 'Mot' ), $found['msgstr'] );
		$this->assertSame( 'build-alpha', $found['build_id'] );
		$this->assertNotNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', 'Save' ) ), 'main domain still found' );
	}

	public function test_find_checks_the_hash_of_an_extra_domain_mo(): void {
		$extra  = $this->relative( 'alpha', 'fr_FR', 'mo' ) . '.extra.mo';
		$search = $this->build(
			array( 'alpha' => array( "\x04Save" => 'Enregistrer' ) ),
			'fr_FR',
			array(),
			array( 'alpha' => array( $extra => array( "\x04Library word" => 'Mot' ) ) )
		);
		$this->fs->put( Babilim_Paths::root() . '/' . $extra, 'tampered' );
		$this->assertNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', 'Library word' ) ) );
	}

	public function test_find_is_null_when_the_record_has_no_mo_file(): void {
		$search = $this->build(
			array(),
			'fr_FR',
			array(
				array(
					'kind'   => 'plugin',
					'slug'   => 'alpha',
					'locale' => 'fr_FR',
					'files'  => array( 'plugins/alpha-fr_FR.json' => 'sha-b' ),
				),
			)
		);
		$this->assertNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', 'Save' ) ) );
	}

	public function test_find_is_null_when_the_manifest_cannot_be_read(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::manifest_file(), 'not json at all' );
		$manifest = new Babilim_Manifest( $fs );
		try {
			$manifest->installed( 'plugin', 'alpha', 'fr_FR' );
			$this->fail( 'installed() should throw for a corrupt manifest' );
		} catch ( RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		$search = new Babilim_Translation_Search(
			$manifest,
			static function (): array {
				return array();
			},
			static function (): string {
				return '';
			},
			$fs
		);
		$this->assertNull( $search->find( 'plugin', 'alpha', 'fr_FR', Babilim_Translation_Search::ref( '', 'Save' ) ) );
	}
}
