<?php
/**
 * Tests for Babilim_Default_Index -- the merged-per-locale `default`
 * domain supplement index.
 *
 * $parse_entries is faked here as a real (if minimal) reader: it reads
 * whatever Babilim_Mo_Writer wrote (via the independent
 * babilim_test_read_mo() reader from test-mo-writer.php) for `.mo`
 * fixtures, and `include`-equivalent evaluates a `.l10n.php` file's
 * `return array(...)` for the class's own re-parse-after-write step --
 * so these tests genuinely exercise "the code's own emitted file reads
 * back with the entry count it claims", not just a canned stand-in.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Default_Index extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	/**
	 * A parse_entries fake good enough to read back both a
	 * Babilim_Mo_Writer `.mo` and this class's own var_export()'d
	 * `.l10n.php`, from a given Babilim_Fs.
	 */
	private function parse_entries_fake( Babilim_Fs $fs ): callable {
		return static function ( string $path ) use ( $fs ) {
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
	}

	private function put_fragment( Babilim_Fs $fs, string $slug, string $locale, array $entries ): string {
		$path = Babilim_Paths::file( 'default/_parts', $slug, $locale, 'mo' );
		$fs->put( $path, Babilim_Mo_Writer::write( $entries, array() ) );
		return $path;
	}

	private function manifest_with_records( Babilim_Fs $fs, array $records ): Babilim_Manifest {
		$manifest = new Babilim_Manifest( $fs );
		foreach ( $records as $r ) {
			$manifest->set( $r['kind'], $r['slug'], $r['locale'], $r );
		}
		return $manifest;
	}

	private function default_record( string $kind, string $slug, string $locale, string $version, ?string $orphaned_at = null ): array {
		$relative = str_replace( Babilim_Paths::root() . '/', '', Babilim_Paths::file( 'default/_parts', $slug, $locale, 'mo' ) );
		return array(
			'component_version' => $version,
			'files'             => array(),
			'default_files'     => array( $relative => 'sha-placeholder' ),
			'orphaned_at'       => $orphaned_at,
		);
	}

	private function never_core_has(): callable {
		return static function ( string $msgid, string $ctx ): bool {
			return false;
		};
	}

	// -- Happy path: two matching, active components -----------------

	public function test_two_active_matching_components_are_both_included(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$this->put_fragment( $fs, 'b', 'zh_TW', array( 'Cancel' => 'Cancel-B' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ),
				array_merge( array( 'kind' => 'plugin', 'slug' => 'b', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'b', 'zh_TW', '2.0.0' ) ),
			)
		);
		$active = static function () {
			return array(
				array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ),
				array( 'kind' => 'plugin', 'slug' => 'b', 'version' => '2.0.0' ),
			);
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_ok() );
		$meta = $manifest->default_index( 'zh_TW' );
		$this->assertCount( 2, $meta['included'] );
		$this->assertCount( 0, $meta['excluded'] );

		$mo_entries = call_user_func( $this->parse_entries_fake( $fs ), Babilim_Paths::merged_default( 'zh_TW', 'mo' ) );
		$this->assertSame( 'Save-A', $mo_entries['Save'] );
		$this->assertSame( 'Cancel-B', $mo_entries['Cancel'] );
	}

	/**
	 * A successful rebuild() must invalidate PHP's own opcache for both
	 * files it just wrote (the merged .mo and the merged .l10n.php) --
	 * under opcache.validate_timestamps=0 (common on managed hosts), a
	 * stale compiled .l10n.php would otherwise keep being served after a
	 * real rebuild until the next full-page opcache reset. WordPress
	 * Playground does not expose a real opcache to boot against (see
	 * docs/playground-results.md); this unit test is the actual coverage
	 * for the call itself.
	 */
	public function test_rebuild_invalidates_the_opcache_for_both_written_files(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ),
			)
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );
		$this->assertTrue( $result->is_ok() );

		$calls = $GLOBALS['babilim_test_wp_opcache_invalidate_calls'];
		$paths = array_column( $calls, 0 );
		$this->assertContains( Babilim_Paths::merged_default( 'zh_TW', 'mo' ), $paths, 'rebuild() did not call wp_opcache_invalidate() for the merged .mo file' );
		$this->assertContains( Babilim_Paths::merged_default( 'zh_TW', 'l10n.php' ), $paths, 'rebuild() did not call wp_opcache_invalidate() for the merged .l10n.php file' );
		foreach ( $calls as $call ) {
			$this->assertTrue( $call[1], "wp_opcache_invalidate() was called for '{$call[0]}' without force=true" );
		}
	}

	// -- Deactivating a component excludes it -------------------------

	public function test_deactivating_one_component_excludes_it_from_the_index(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$this->put_fragment( $fs, 'b', 'zh_TW', array( 'Cancel' => 'Cancel-B' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ),
				array_merge( array( 'kind' => 'plugin', 'slug' => 'b', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'b', 'zh_TW', '2.0.0' ) ),
			)
		);
		// b is not active.
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_ok() );
		$meta = $manifest->default_index( 'zh_TW' );
		$this->assertCount( 1, $meta['included'] );
		$this->assertSame( 'a', $meta['included'][0]['slug'] );

		$excluded_b = array_values( array_filter( $meta['excluded'], static function ( $e ) {
			return 'b' === $e['slug'];
		} ) );
		$this->assertCount( 1, $excluded_b );
		$this->assertSame( 'inactive', $excluded_b[0]['reason'] );

		$mo_entries = call_user_func( $this->parse_entries_fake( $fs ), Babilim_Paths::merged_default( 'zh_TW', 'mo' ) );
		$this->assertArrayNotHasKey( 'Cancel', $mo_entries );
		$this->assertSame( 'Save-A', $mo_entries['Save'] );
	}

	// -- Version mismatch excludes -------------------------------------

	public function test_version_mismatch_excludes_the_component(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'b', 'zh_TW', array( 'Cancel' => 'Cancel-B' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'b', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'b', 'zh_TW', '2.4.0' ) ),
			)
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'b', 'version' => '2.5.0' ) );
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_ok() );
		$meta       = $manifest->default_index( 'zh_TW' );
		$excluded_b = $meta['excluded'][0];
		$this->assertSame( 'version_mismatch', $excluded_b['reason'] );
	}

	// -- Conflicting msgstr across components ---------------------------

	public function test_conflicting_translations_are_dropped_and_recorded(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A-version' ) );
		$this->put_fragment( $fs, 'b', 'zh_TW', array( 'Save' => 'Save-B-version' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ),
				array_merge( array( 'kind' => 'plugin', 'slug' => 'b', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'b', 'zh_TW', '2.0.0' ) ),
			)
		);
		$active = static function () {
			return array(
				array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ),
				array( 'kind' => 'plugin', 'slug' => 'b', 'version' => '2.0.0' ),
			);
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_ok() );
		$meta = $manifest->default_index( 'zh_TW' );
		$this->assertCount( 1, $meta['conflicts'] );
		$this->assertSame( 'Save', $meta['conflicts'][0]['msgid'] );
		$slugs = $meta['conflicts'][0]['slugs'];
		sort( $slugs );
		$this->assertSame( array( 'a', 'b' ), $slugs );

		// A merged index with only a conflicting key ends up empty ->
		// deleted (see the "all excluded" test below for the direct
		// case; here just confirm the key itself never made it in).
		$this->assertFalse( $fs->exists( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) ) );
	}

	public function test_same_msgstr_from_two_components_is_not_a_conflict(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-same' ) );
		$this->put_fragment( $fs, 'b', 'zh_TW', array( 'Save' => 'Save-same' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ),
				array_merge( array( 'kind' => 'plugin', 'slug' => 'b', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'b', 'zh_TW', '2.0.0' ) ),
			)
		);
		$active = static function () {
			return array(
				array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ),
				array( 'kind' => 'plugin', 'slug' => 'b', 'version' => '2.0.0' ),
			);
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$meta = $manifest->default_index( 'zh_TW' );
		$this->assertCount( 0, $meta['conflicts'] );

		$mo_entries = call_user_func( $this->parse_entries_fake( $fs ), Babilim_Paths::merged_default( 'zh_TW', 'mo' ) );
		$this->assertSame( 'Save-same', $mo_entries['Save'] );
	}

	// -- core_has excludes -----------------------------------------------

	public function test_core_has_excludes_the_key_and_counts_it(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A', 'Cancel' => 'Cancel-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};
		$core_has = static function ( string $msgid, string $ctx ): bool {
			return 'Save' === $msgid;
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $core_has );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_ok() );
		$meta = $manifest->default_index( 'zh_TW' );
		$this->assertSame( 1, $meta['core_dupes'] );

		$mo_entries = call_user_func( $this->parse_entries_fake( $fs ), Babilim_Paths::merged_default( 'zh_TW', 'mo' ) );
		$this->assertArrayNotHasKey( 'Save', $mo_entries );
		$this->assertSame( 'Cancel-A', $mo_entries['Cancel'] );
	}

	// -- Everything excluded -> merged files deleted ----------------------

	public function test_when_everything_is_excluded_the_merged_files_are_deleted(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ) )
		);
		// First build the index so there's something to delete.
		$active_yes = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};
		$index = new Babilim_Default_Index( $fs, $manifest, $active_yes, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index->rebuild( 'zh_TW' );
		$this->assertTrue( $fs->exists( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) ) );

		// Now deactivate it and rebuild -- $active is non-empty (an
		// unrelated component) so this exercises the "everything excluded"
		// path, not the "active list itself is unavailable" fail-closed
		// guard a truly empty array now trips.
		$active_no = static function () {
			return array( array( 'kind' => 'theme', 'slug' => 'unrelated-theme', 'version' => '1.0.0' ) );
		};
		$index2 = new Babilim_Default_Index( $fs, $manifest, $active_no, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index2->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_ok() );
		$this->assertFalse( $fs->exists( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::merged_default( 'zh_TW', 'l10n.php' ) ) );
	}

	// -- l10n.php entry count matches the .mo -----------------------------

	public function test_l10n_php_entry_count_matches_the_mo(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A', 'Cancel' => 'Cancel-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};

		$index = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index->rebuild( 'zh_TW' );

		$mo_entries  = call_user_func( $this->parse_entries_fake( $fs ), Babilim_Paths::merged_default( 'zh_TW', 'mo' ) );
		$php_entries = call_user_func( $this->parse_entries_fake( $fs ), Babilim_Paths::merged_default( 'zh_TW', 'l10n.php' ) );

		$this->assertCount( count( $mo_entries ), $php_entries );
		$this->assertSame( $mo_entries, $php_entries );
	}

	// -- A rename failure while placing the merged index gives a clear
	// message and is recorded in default_index() meta (not just a bare
	// generic error). ------------------------------------------------------

	public function test_write_merged_index_rename_failure_has_a_clear_message_and_is_recorded_in_meta(): void {
		$real_fs  = new Babilim_Memory_Fs();
		$this->put_fragment( $real_fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$real_fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};

		$mo_path    = Babilim_Paths::merged_default( 'zh_TW', 'mo' );
		$failing_fs = new Babilim_Test_Installer_Failing_Rename_Fs(
			$real_fs,
			static function ( string $from, string $to ) use ( $mo_path ): bool {
				return $to === $mo_path;
			}
		);

		$index  = new Babilim_Default_Index( $failing_fs, $manifest, $active, $this->parse_entries_fake( $real_fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'rename_failed', $result->code() );
		// The message is specific, not a bare generic string -- it names
		// the locale and explains what actually happened (the previous
		// served index, if any, was removed rather than left
		// half-updated).
		$this->assertStringContainsString( 'zh_TW', $result->message() );
		$this->assertStringContainsString( 'removed', $result->message() );

		$meta = $manifest->default_index( 'zh_TW' );
		$this->assertNotNull( $meta );
		$this->assertArrayHasKey( 'error', $meta );
		$this->assertStringContainsString( 'rename_failed', $meta['error'] );
	}

	// -- Orphaned record is excluded --------------------------------------

	public function test_an_orphaned_record_is_excluded(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0', '2026-01-01T00:00:00Z' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$meta = $manifest->default_index( 'zh_TW' );
		$this->assertCount( 0, $meta['included'] );
		$this->assertSame( 'orphaned', $meta['excluded'][0]['reason'] );
	}

	// -- rebuild_all ----------------------------------------------------

	public function test_rebuild_all_rebuilds_every_locale_with_a_record(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$this->put_fragment( $fs, 'a', 'de_DE', array( 'Save' => 'Save-A-de' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ),
				array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE' ), $this->default_record( 'plugin', 'a', 'de_DE', '1.0.0' ) ),
			)
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild_all();

		$this->assertTrue( $result->is_ok() );
		$this->assertTrue( $fs->exists( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::merged_default( 'de_DE', 'mo' ) ) );
	}

	// -- sweep_orphans ----------------------------------------------------

	public function test_sweep_orphans_deletes_an_unregistered_file(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'ghost', 'ja', 'mo' ), 'stray' );
		$manifest = new Babilim_Manifest( $fs );
		$active   = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertContains( 'plugins/ghost-ja.mo', $result->get( 'deleted' ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'ghost', 'ja', 'mo' ) ) );
	}

	public function test_sweep_orphans_never_scans_custom(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'custom/plugins', 'anything', 'ja', 'mo' ), 'site override' );
		$manifest = new Babilim_Manifest( $fs );
		$active   = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index->sweep_orphans();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'custom/plugins', 'anything', 'ja', 'mo' ) ) );
	}

	public function test_sweep_orphans_cleans_up_a_component_removed_from_disk(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ), 'main' );
		$fs->put( Babilim_Paths::file( 'default/_parts', 'removed-plugin', 'ja', 'mo' ), 'fragment' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'removed-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'plugins/removed-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array( 'default/_parts/removed-plugin-ja.mo' => hash( 'sha256', 'fragment' ) ),
				'orphaned_at'       => null,
			)
		);
		// get_plugins() no longer lists it -- genuinely removed. Some
		// OTHER plugin is still listed, so the list itself is genuinely
		// available (not empty) -- an empty list is its own, separate
		// fail-closed signal (see the mass-orphan-brake tests below) and
		// must not be conflated with "this one specific plugin is gone".
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'still-here/still-here.php' => array( 'Name' => 'Still Here' ),
		);
		$active                                     = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		// First sweep only MARKS a newly-orphaned record -- its files are
		// deliberately left in place until a later sweep, at least 7 days
		// after this one, finds it still unmatched (see
		// test_sweep_orphans_deletes_files_of_a_record_orphaned_more_than_
		// a_week_ago() below).
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ) ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'default/_parts', 'removed-plugin', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'removed-plugin', 'ja' );
		$this->assertNotNull( $record['orphaned_at'] );
		$orphaned = $result->get( 'orphaned' );
		$this->assertCount( 1, $orphaned );
		$this->assertSame( array(), $result->get( 'files_deleted' ), 'nothing is actually deleted on the marking sweep' );
	}

	public function test_rebuild_reports_failure_when_the_index_meta_cannot_be_recorded(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};
		unset( $GLOBALS['wpdb'] );

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_write_failed', $result->code() );
	}

	public function test_rebuild_of_an_empty_index_reports_failure_when_its_meta_cannot_be_recorded(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'other', 'version' => '1.0.0' ) );
		};
		unset( $GLOBALS['wpdb'] );

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_write_failed', $result->code() );
	}

	public function test_sweep_orphans_reports_failure_and_does_not_count_a_mark_that_was_not_recorded(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'removed-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'plugins/removed-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'still-here/still-here.php' => array( 'Name' => 'Still Here' ),
		);
		$active = static function () {
			return array();
		};
		unset( $GLOBALS['wpdb'] );

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'manifest_write_failed', $result->code() );
		$this->assertSame( array(), $result->get( 'orphaned' ), 'a record whose orphan mark was never recorded must not be reported as orphaned' );
		$this->assertCount( 1, (array) $result->get( 'write_failed' ) );
	}

	/**
	 * A record marked orphaned less than 7 days ago must survive a later
	 * sweep untouched -- the delay is measured from orphaned_at, not from
	 * "any sweep after the first".
	 */
	public function test_sweep_orphans_does_not_delete_a_recently_marked_record_files(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'removed-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'              => array( 'plugins/removed-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'      => array(),
				'orphaned_at'        => gmdate( 'c', time() - 2 * DAY_IN_SECONDS ),
			)
		);
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'still-here/still-here.php' => array( 'Name' => 'Still Here' ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ) ) );
		$this->assertSame( array(), $result->get( 'files_deleted' ) );
		$record = $manifest->installed( 'plugin', 'removed-plugin', 'ja' );
		$this->assertNotNull( $record, 'the manifest record itself must also survive (30-day delay, not 7)' );
	}

	/**
	 * Once a marked record's orphaned_at is at least 7 days old and it's
	 * still unmatched, its served files are finally deleted -- but the
	 * manifest record itself is kept (only removed at the separate 30-day
	 * mark, see the next test).
	 */
	public function test_sweep_orphans_deletes_files_of_a_record_orphaned_more_than_a_week_ago(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'removed-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'              => array( 'plugins/removed-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'      => array(),
				'orphaned_at'        => gmdate( 'c', time() - 8 * DAY_IN_SECONDS ),
			)
		);
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'still-here/still-here.php' => array( 'Name' => 'Still Here' ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ) ) );
		$this->assertContains( array( 'kind' => 'plugin', 'slug' => 'removed-plugin', 'locale' => 'ja' ), $result->get( 'files_deleted' ) );
		$this->assertNotNull( $manifest->installed( 'plugin', 'removed-plugin', 'ja' ), 'the manifest record is kept until the separate 30-day mark' );
	}

	/**
	 * Once a marked record's orphaned_at is at least 30 days old, the
	 * manifest record itself is removed entirely -- not just its files.
	 */
	public function test_sweep_orphans_purges_the_manifest_record_after_thirty_days(): void {
		$fs = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'removed-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'              => array(),
				'default_files'      => array(),
				'orphaned_at'        => gmdate( 'c', time() - 31 * DAY_IN_SECONDS ),
			)
		);
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'still-here/still-here.php' => array( 'Name' => 'Still Here' ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertNull( $manifest->installed( 'plugin', 'removed-plugin', 'ja' ) );
		$this->assertContains( array( 'kind' => 'plugin', 'slug' => 'removed-plugin', 'locale' => 'ja' ), $result->get( 'purged' ) );
	}

	/**
	 * A record already marked orphaned that reappears in the installed
	 * list (the component came back, or was only ever misdetected) is
	 * rescued: orphaned_at is cleared rather than left counting down to
	 * deletion.
	 */
	public function test_sweep_orphans_rescues_a_marked_record_that_reappears_as_installed(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'came-back', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'came-back',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'              => array( 'plugins/came-back-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'      => array(),
				'orphaned_at'        => gmdate( 'c', time() - 2 * DAY_IN_SECONDS ),
			)
		);
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'came-back/came-back.php' => array( 'Name' => 'Came Back' ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index->sweep_orphans();

		$record = $manifest->installed( 'plugin', 'came-back', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
	}

	public function test_sweep_orphans_keeps_a_deactivated_but_still_installed_component(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'inactive-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'inactive-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'plugins/inactive-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		// Still installed (present in get_plugins()), just not active.
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'inactive-plugin/inactive-plugin.php' => array( 'Name' => 'Inactive Plugin' ),
		);
		$active = static function () {
			return array(); // Nothing active.
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'inactive-plugin', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'inactive-plugin', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
		$this->assertCount( 0, $result->get( 'orphaned' ) );
		$this->assertCount( 1, $result->get( 'kept' ) );
	}

	/**
	 * A record whose own registered file is missing from disk (but whose
	 * component is still genuinely installed) gets its integrity
	 * persisted false in the manifest -- not just reported in the
	 * result -- so the next sync's verify() step actually degrades it.
	 */
	public function test_sweep_orphans_persists_integrity_ok_false_for_a_record_with_missing_files(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		// Deliberately never written to $fs -- the record claims it, but
		// it doesn't actually exist on disk.
		$manifest->set(
			'plugin',
			'missing-file-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'plugins/missing-file-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'missing-file-plugin/missing-file-plugin.php' => array( 'Name' => 'Missing File Plugin' ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$identity = array( 'kind' => 'plugin', 'slug' => 'missing-file-plugin', 'locale' => 'ja' );
		$this->assertContains( $identity, $result->get( 'integrity_failed' ) );
		$record = $manifest->installed( 'plugin', 'missing-file-plugin', 'ja' );
		$this->assertFalse( $record['integrity']['ok'] );
	}

	// -- sweep_orphans()/rebuild() must fail closed (delete/change
	// nothing) when the component list genuinely can't be determined,
	// rather than mistaking that for "nothing installed/active". --------

	public function test_sweep_orphans_deletes_nothing_when_get_plugins_throws(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'active-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'active-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'plugins/active-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		$GLOBALS['babilim_test_get_plugins_throws'] = true;
		$active                                      = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( array(), $result->get( 'deleted' ) );
		$this->assertSame( array(), $result->get( 'orphaned' ) );
		// The genuinely-active plugin's served file survives untouched.
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'active-plugin', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'active-plugin', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
	}

	// -- A genuinely EMPTY component list (not just an exception) must
	// also be treated as "couldn't determine", not "nothing installed/
	// active" -- both for sweep_orphans() and rebuild(). -------------------

	public function test_sweep_orphans_deletes_nothing_when_get_plugins_returns_empty(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'active-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'active-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'plugins/active-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		// get_plugins() returns successfully, but completely empty --
		// while the manifest expects at least one plugin record to still
		// exist. Not an exception, not a missing function: a real "the
		// list came back wrong" case.
		$GLOBALS['babilim_test_get_plugins_return'] = array();
		$active                                     = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( array(), $result->get( 'deleted' ) );
		$this->assertSame( array(), $result->get( 'orphaned' ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'active-plugin', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'active-plugin', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
	}

	/**
	 * The same empty-list fail-closed rule applies to themes, not just
	 * plugins: `wp_get_themes()` genuinely returning nothing while the
	 * manifest expects at least one theme record is itself the anomaly
	 * (a real site always has at least its own active theme) -- treated
	 * as "couldn't determine", never "every theme was removed".
	 */
	public function test_sweep_orphans_deletes_nothing_when_wp_get_themes_returns_empty(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'themes', 'active-theme', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'theme',
			'active-theme',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'             => array( 'themes/active-theme-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		// get_plugins() is fine (genuinely empty, no plugin records to
		// worry about); wp_get_themes() is the one that's wrong here.
		$GLOBALS['babilim_test_get_plugins_return']   = array();
		$GLOBALS['babilim_test_wp_get_themes_return'] = array();
		$active                                        = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( array(), $result->get( 'deleted' ) );
		$this->assertSame( array(), $result->get( 'orphaned' ) );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'themes', 'active-theme', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'theme', 'active-theme', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
	}

	public function test_rebuild_leaves_the_existing_index_unchanged_when_active_components_returns_empty(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ) )
		);
		$active_ok = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};
		$index_ok = new Babilim_Default_Index( $fs, $manifest, $active_ok, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index_ok->rebuild( 'zh_TW' );
		$before = $fs->get( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) );
		$this->assertNotNull( $before );

		// A completely empty active list -- not an exception -- must also
		// be treated as "couldn't determine" (see class docblock:
		// production Babilim::active_components() always includes the
		// current theme, so a truly empty result is itself anomalous).
		$active_empty = static function () {
			return array();
		};
		$index_empty = new Babilim_Default_Index( $fs, $manifest, $active_empty, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result       = $index_empty->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( $before, $fs->get( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) ) );
	}

	public function test_rebuild_leaves_the_existing_index_unchanged_when_active_components_throws(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'zh_TW', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'a', 'zh_TW', '1.0.0' ) ) )
		);
		$active_ok = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};
		$index_ok = new Babilim_Default_Index( $fs, $manifest, $active_ok, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index_ok->rebuild( 'zh_TW' );
		$before = $fs->get( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) );
		$this->assertNotNull( $before );

		$active_throws = static function () {
			throw new RuntimeException( 'active_components unavailable' );
		};
		$index_throws = new Babilim_Default_Index( $fs, $manifest, $active_throws, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result       = $index_throws->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( $before, $fs->get( Babilim_Paths::merged_default( 'zh_TW', 'mo' ) ) );
	}

	// -- Mass-orphan brake: a single sweep never orphans more than half
	// of everything it manages, or every last record of some kind, no
	// matter what the (possibly-wrong) installed list says. --------------

	private function plugin_record_for_sweep( string $slug ): array {
		return array(
			'kind'               => 'plugin',
			'slug'               => $slug,
			'locale'             => 'ja',
			'component_version'  => '1.0.0',
			'files'              => array( "plugins/{$slug}-ja.mo" => hash( 'sha256', $slug ) ),
			'default_files'      => array(),
			'orphaned_at'        => null,
		);
	}

	public function test_sweep_orphans_aborts_when_it_would_orphan_more_than_half_of_managed_records(): void {
		$fs = new Babilim_Memory_Fs();
		foreach ( array( 'a', 'b', 'c' ) as $slug ) {
			$fs->put( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ), $slug );
		}
		$manifest = new Babilim_Manifest( $fs );
		foreach ( array( 'a', 'b', 'c' ) as $slug ) {
			$record = $this->plugin_record_for_sweep( $slug );
			$manifest->set( 'plugin', $slug, 'ja', $record );
		}
		// Only 'a' is still genuinely installed -- 'b' and 'c' (2 of 3,
		// two thirds) would be orphaned in one sweep.
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'a/a.php' => array( 'Name' => 'A' ) );
		$active                                     = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'mass_orphan_brake', $result->code() );
		$this->assertSame( array(), $result->get( 'orphaned' ) );
		foreach ( array( 'a', 'b', 'c' ) as $slug ) {
			$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ) ), "Expected {$slug}'s file to survive" );
			$record = $manifest->installed( 'plugin', $slug, 'ja' );
			$this->assertNull( $record['orphaned_at'] );
		}
		$guard = $manifest->sweep_guard();
		$this->assertNotNull( $guard );
		$this->assertSame( 2, $guard['would_orphan'] );
		$this->assertSame( 3, $guard['managed_total'] );
	}

	public function test_sweep_orphans_aborts_when_it_would_orphan_every_record_of_one_kind(): void {
		$fs = new Babilim_Memory_Fs();
		foreach ( array( 'a', 'b' ) as $slug ) {
			$fs->put( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ), $slug );
		}
		$fs->put( Babilim_Paths::file( 'themes', 'theme-x', 'ja', 'mo' ), 'x' );
		$fs->put( Babilim_Paths::file( 'themes', 'theme-y', 'ja', 'mo' ), 'y' );
		$manifest = new Babilim_Manifest( $fs );
		foreach ( array( 'a', 'b' ) as $slug ) {
			$manifest->set( 'plugin', $slug, 'ja', $this->plugin_record_for_sweep( $slug ) );
		}
		foreach ( array( 'theme-x', 'theme-y' ) as $slug ) {
			$record           = $this->plugin_record_for_sweep( $slug );
			$record['kind']   = 'theme';
			$record['files']  = array( "themes/{$slug}-ja.mo" => hash( 'sha256', $slug ) );
			$manifest->set( 'theme', $slug, 'ja', $record );
		}
		// Neither plugin is listed any more (both of the only 2 managed
		// plugin records would be orphaned -- exactly half of the 4
		// managed records overall, so the ">50%" check alone wouldn't
		// catch this; the per-kind wipeout check must). Some OTHER,
		// unrelated plugin is listed so the list itself is genuinely
		// non-empty/available -- this isn't the "list unavailable" case.
		$GLOBALS['babilim_test_get_plugins_return']   = array(
			'unrelated/unrelated.php' => array( 'Name' => 'Unrelated' ),
		);
		// installed_identities() only ever reads wp_get_themes()'s array
		// KEYS as stylesheets -- the values themselves are never touched,
		// so a bare truthy placeholder is enough here.
		$GLOBALS['babilim_test_wp_get_themes_return'] = array(
			'theme-x' => true,
			'theme-y' => true,
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'mass_orphan_brake', $result->code() );
		foreach ( array( 'a', 'b' ) as $slug ) {
			$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ) ) );
		}
	}

	/**
	 * The brake counts distinct COMPONENTS, not records: the site's only
	 * managed plugin (which shipped 3 locale records) being genuinely
	 * removed is exactly one component gone out of one component managed
	 * -- never "mass" -- so all 3 of its records must still be cleaned up
	 * normally, not blocked just because 3 *records* disappeared at once.
	 */
	public function test_sweep_orphans_cleans_up_the_only_managed_component_across_all_its_locales(): void {
		$fs      = new Babilim_Memory_Fs();
		$locales = array( 'ja', 'ru', 'zh_TW' );
		foreach ( $locales as $locale ) {
			$fs->put( Babilim_Paths::file( 'plugins', 'solo', $locale, 'mo' ), $locale );
		}
		$manifest = new Babilim_Manifest( $fs );
		foreach ( $locales as $locale ) {
			$manifest->set(
				'plugin',
				'solo',
				$locale,
				array(
					'component_version' => '1.0.0',
					'files'              => array( "plugins/solo-{$locale}.mo" => hash( 'sha256', $locale ) ),
					'default_files'      => array(),
					'orphaned_at'        => null,
				)
			);
		}
		// Genuinely removed -- the list itself is still available (some
		// other, unrelated plugin is listed), it just no longer contains
		// 'solo'. An entirely empty list would instead trip the separate
		// "list unavailable" fail-closed guard, which isn't what this
		// test is about.
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'unrelated/unrelated.php' => array( 'Name' => 'Unrelated' ),
		);
		$active                                      = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertCount( 3, $result->get( 'orphaned' ) );
		foreach ( $locales as $locale ) {
			// Marked, not deleted yet -- see the mark-then-delete tests above.
			$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'solo', $locale, 'mo' ) ) );
			$record = $manifest->installed( 'plugin', 'solo', $locale );
			$this->assertNotNull( $record['orphaned_at'] );
		}
	}

	/**
	 * The other direction: 4 distinct managed components, 3 of them
	 * "look" removed in one sweep (75%, and more than half) -- the brake
	 * must still trip, same as the record-counting version did, now
	 * measured in components.
	 */
	public function test_sweep_orphans_aborts_when_three_of_four_managed_components_look_removed(): void {
		$fs = new Babilim_Memory_Fs();
		foreach ( array( 'a', 'b', 'c', 'd' ) as $slug ) {
			$fs->put( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ), $slug );
		}
		$manifest = new Babilim_Manifest( $fs );
		foreach ( array( 'a', 'b', 'c', 'd' ) as $slug ) {
			$manifest->set( 'plugin', $slug, 'ja', $this->plugin_record_for_sweep( $slug ) );
		}
		// Only 'a' is still genuinely installed -- 'b', 'c', 'd' (3 of 4
		// managed components, 75%) would be orphaned in one sweep.
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'a/a.php' => array( 'Name' => 'A' ) );
		$active                                      = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'mass_orphan_brake', $result->code() );
		foreach ( array( 'a', 'b', 'c', 'd' ) as $slug ) {
			$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ) ) );
		}
		$guard = $manifest->sweep_guard();
		$this->assertSame( 3, $guard['would_orphan'] );
		$this->assertSame( 4, $guard['managed_total'] );
	}

	/**
	 * When the brake trips, the whole premise is that this run's
	 * installed-component list can't be trusted -- so a sweep that trips
	 * it must delete NOTHING at all, not just refrain from marking new
	 * orphans: an already-orphaned record past its own 7-day file-delete
	 * or 30-day record-purge delay must also survive untouched this run
	 * (a later sweep, once the list is trustworthy again, still gets to
	 * finish the job).
	 */
	public function test_sweep_orphans_brake_deletes_nothing_even_for_an_already_orphaned_record_past_its_delays(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'a', 'ja', 'mo' ), 'a' );
		foreach ( array( 'b', 'c' ) as $slug ) {
			$fs->put( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ), $slug );
		}
		$manifest                  = new Babilim_Manifest( $fs );
		$old_orphan                = $this->plugin_record_for_sweep( 'a' );
		$old_orphan['orphaned_at'] = gmdate( 'c', time() - 40 * DAY_IN_SECONDS );
		$manifest->set( 'plugin', 'a', 'ja', $old_orphan );
		foreach ( array( 'b', 'c' ) as $slug ) {
			$manifest->set( 'plugin', $slug, 'ja', $this->plugin_record_for_sweep( $slug ) );
		}
		// 'b' and 'c' (2 of the 2 non-orphaned managed components) would
		// both be newly orphaned, tripping the brake. 'a' is already
		// orphaned from a previous sweep and, at 40 days, is well past
		// both delays. Some unrelated plugin is listed so the list
		// itself is genuinely available (not the separate "unavailable"
		// fail-closed case).
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'unrelated/unrelated.php' => array( 'Name' => 'Unrelated' ) );
		$active                                      = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'mass_orphan_brake', $result->code() );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'a', 'ja', 'mo' ) ), "'a' must survive -- zero deletion when the brake trips" );
		$this->assertNotNull( $manifest->installed( 'plugin', 'a', 'ja' ), "'a''s manifest record must survive -- zero deletion when the brake trips" );
		$this->assertSame( array(), $result->get( 'files_deleted' ) );
		$this->assertSame( array(), $result->get( 'purged' ) );
	}

	/**
	 * An unregistered file (delete_unregistered_files()) is also a
	 * deletion -- it too must not happen on the same run the brake trips,
	 * even though it doesn't itself depend on the (untrustworthy)
	 * installed list.
	 */
	public function test_sweep_orphans_brake_deletes_no_unregistered_files_either(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'ghost', 'ja', 'mo' ), 'stray' );
		foreach ( array( 'b', 'c' ) as $slug ) {
			$fs->put( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ), $slug );
		}
		$manifest = new Babilim_Manifest( $fs );
		foreach ( array( 'b', 'c' ) as $slug ) {
			$manifest->set( 'plugin', $slug, 'ja', $this->plugin_record_for_sweep( $slug ) );
		}
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'unrelated/unrelated.php' => array( 'Name' => 'Unrelated' ) );
		$active                                      = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'mass_orphan_brake', $result->code() );
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'ghost', 'ja', 'mo' ) ) );
		$this->assertSame( array(), $result->get( 'deleted' ) );
	}

	/**
	 * Both abort paths (the mass-orphan brake, and the separate
	 * "installed components unavailable" guard) must still record a
	 * sweep summary -- "every cleanup writes one summary" (see
	 * render_sweep_status()) must hold even for an aborted run, and the
	 * settings screen's own wording must match: zero marked/deleted/
	 * purged, since that's genuinely what happened.
	 */
	public function test_sweep_orphans_writes_a_summary_when_the_brake_trips(): void {
		$fs = new Babilim_Memory_Fs();
		foreach ( array( 'b', 'c' ) as $slug ) {
			$fs->put( Babilim_Paths::file( 'plugins', $slug, 'ja', 'mo' ), $slug );
		}
		$manifest = new Babilim_Manifest( $fs );
		foreach ( array( 'b', 'c' ) as $slug ) {
			$manifest->set( 'plugin', $slug, 'ja', $this->plugin_record_for_sweep( $slug ) );
		}
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'unrelated/unrelated.php' => array( 'Name' => 'Unrelated' ) );
		$active                                      = static function () {
			return array();
		};

		$index = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index->sweep_orphans();

		$summary = $manifest->sweep_summary();
		$this->assertNotNull( $summary, 'an aborted sweep must still write a summary' );
		$this->assertSame( 0, $summary['orphans_marked'] );
		$this->assertSame( 0, $summary['orphans_deleted'] );
		$this->assertSame( 0, $summary['records_purged'] );
		$this->assertTrue( $summary['aborted'] );
	}

	public function test_sweep_orphans_writes_a_summary_when_components_are_unavailable(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'active-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'active-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'              => array( 'plugins/active-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'      => array(),
				'orphaned_at'        => null,
			)
		);
		$GLOBALS['babilim_test_get_plugins_throws'] = true;
		$active                                      = static function () {
			return array();
		};

		$index = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index->sweep_orphans();

		$summary = $manifest->sweep_summary();
		$this->assertNotNull( $summary, 'an aborted sweep must still write a summary' );
		$this->assertSame( 0, $summary['orphans_marked'] );
		$this->assertTrue( $summary['aborted'] );
	}

	/**
	 * A stale sweep_guard from a past brake trip must stop being shown
	 * once a later sweep genuinely completes without tripping it --
	 * otherwise the settings screen's "A cleanup was paused on ..." notice
	 * would show forever, even after everything since has gone fine.
	 */
	public function test_sweep_orphans_clears_a_stale_sweep_guard_after_a_later_successful_sweep(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set_sweep_guard( array( 'tripped_at' => '2020-01-01T00:00:00Z', 'reason' => 'stale', 'would_orphan' => 2, 'managed_total' => 2 ) );
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_ok(), $result->message() );
		$this->assertNull( $manifest->sweep_guard() );
	}

	/**
	 * A corrupt/unparseable orphaned_at must be treated as "just marked"
	 * (age 0), not as an age of "now minus epoch zero" (billions of
	 * seconds) -- the latter would immediately clear BOTH delays on the
	 * very next sweep, deleting files (and eventually the record) for a
	 * timestamp that was simply malformed, not genuinely old.
	 */
	public function test_sweep_orphans_treats_an_unparseable_orphaned_at_as_freshly_marked(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'removed-plugin',
			'ja',
			array(
				'component_version' => '1.0.0',
				'files'              => array( 'plugins/removed-plugin-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'      => array(),
				'orphaned_at'        => 'not-a-real-timestamp',
			)
		);
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'still-here/still-here.php' => array( 'Name' => 'Still Here' ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'removed-plugin', 'ja', 'mo' ) ) );
		$this->assertSame( array(), $result->get( 'files_deleted' ) );
		$this->assertSame( array(), $result->get( 'purged' ) );
		$this->assertNotNull( $manifest->installed( 'plugin', 'removed-plugin', 'ja' ) );
	}

	// -- core_has() is called with the locale actually being
	// rebuilt, not a request-global locale. -------------------------------

	public function test_core_has_is_called_with_the_locale_being_rebuilt(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'de_DE', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'de_DE' ), $this->default_record( 'plugin', 'a', 'de_DE', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};
		$seen_locales = array();
		$core_has     = static function ( string $msgid, string $ctx, string $locale = '' ) use ( &$seen_locales ): bool {
			$seen_locales[] = $locale;
			return false;
		};

		$index = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $core_has );
		$index->rebuild( 'de_DE' );

		$this->assertNotEmpty( $seen_locales );
		foreach ( $seen_locales as $locale ) {
			$this->assertSame( 'de_DE', $locale );
		}
	}

	// -- Plural-Forms is written when the included fragments' own
	// headers agree; falls back to core when they don't/are missing; is
	// omitted (and plural entries dropped) when neither is available. ---

	private function put_fragment_with_headers( Babilim_Fs $fs, string $slug, string $locale, array $entries, array $headers ): string {
		$path = Babilim_Paths::file( 'default/_parts', $slug, $locale, 'mo' );
		$fs->put( $path, Babilim_Mo_Writer::write( $entries, $headers ) );
		return $path;
	}

	public function test_plural_forms_is_written_from_the_fragments_own_agreeing_headers(): void {
		$fs = new Babilim_Memory_Fs();
		// ja: nplurals=1 (no plural distinction).
		$this->put_fragment_with_headers(
			$fs, 'a', 'ja',
			array( "1 item\x004 items" => array( 'item(s)' ) ),
			array( 'Plural-Forms' => 'nplurals=1; plural=0;' )
		);
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'ja' ), $this->default_record( 'plugin', 'a', 'ja', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'ja' );

		$this->assertTrue( $result->is_ok() );
		$meta = $manifest->default_index( 'ja' );
		$this->assertSame( 'nplurals=1; plural=0;', $meta['plural_forms'] );

		$mo_bytes = $fs->get( Babilim_Paths::merged_default( 'ja', 'mo' ) );
		$this->assertStringContainsString( 'Plural-Forms: nplurals=1; plural=0;', (string) $mo_bytes );
	}

	public function test_plural_forms_falls_back_to_core_when_fragments_disagree(): void {
		$fs = new Babilim_Memory_Fs();
		// ar: 6 plural forms -- two components disagree on their own
		// fragment's header (a bug/stale build on one of them), so the
		// merge must not trust either and instead falls back to core's
		// own already-loaded header for the `default` domain.
		$this->put_fragment_with_headers(
			$fs, 'a', 'ar',
			array( 'Save' => 'Save-A' ),
			array( 'Plural-Forms' => 'nplurals=6; plural=0;' )
		);
		$this->put_fragment_with_headers(
			$fs, 'b', 'ar',
			array( 'Cancel' => 'Cancel-B' ),
			array( 'Plural-Forms' => 'nplurals=2; plural=(n!=1);' )
		);
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'ar' ), $this->default_record( 'plugin', 'a', 'ar', '1.0.0' ) ),
				array_merge( array( 'kind' => 'plugin', 'slug' => 'b', 'locale' => 'ar' ), $this->default_record( 'plugin', 'b', 'ar', '1.0.0' ) ),
			)
		);
		$active = static function () {
			return array(
				array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ),
				array( 'kind' => 'plugin', 'slug' => 'b', 'version' => '1.0.0' ),
			);
		};
		$core_plural_forms = static function ( string $locale ): ?string {
			return 'ar' === $locale ? 'nplurals=6; plural=n==0?0:n==1?1:n==2?2:n%100>=3&&n%100<=10?3:n%100>=11?4:5;' : null;
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has(), $core_plural_forms );
		$result = $index->rebuild( 'ar' );

		$this->assertTrue( $result->is_ok() );
		$meta = $manifest->default_index( 'ar' );
		$this->assertSame( 'nplurals=6; plural=n==0?0:n==1?1:n==2?2:n%100>=3&&n%100<=10?3:n%100>=11?4:5;', $meta['plural_forms'] );
	}

	public function test_plural_entries_are_dropped_and_no_plural_forms_header_written_when_unavailable(): void {
		$fs = new Babilim_Memory_Fs();
		// No header on the fragment at all, and no core fallback given.
		$this->put_fragment_with_headers(
			$fs, 'a', 'ru',
			array(
				'Save'                => 'Save-A',
				"1 item\x004 items"   => array( 'form0', 'form1', 'form2' ),
			),
			array()
		);
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'ru' ), $this->default_record( 'plugin', 'a', 'ru', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'ru' );

		$this->assertTrue( $result->is_ok() );
		$meta = $manifest->default_index( 'ru' );
		$this->assertNull( $meta['plural_forms'] );
		$this->assertSame( 1, $meta['skipped_plurals'] );

		$mo_entries = call_user_func( $this->parse_entries_fake( $fs ), Babilim_Paths::merged_default( 'ru', 'mo' ) );
		$this->assertArrayNotHasKey( "1 item\x004 items", $mo_entries );
		$this->assertSame( 'Save-A', $mo_entries['Save'] );

		$mo_bytes = $fs->get( Babilim_Paths::merged_default( 'ru', 'mo' ) );
		$this->assertStringNotContainsString( 'Plural-Forms:', (string) $mo_bytes );
	}

	// -- rebuild_all() reconciles a locale whose last contributing
	// record was already removed -- not just locales with a manifest
	// record. --------------------------------------------------------------

	public function test_rebuild_all_reconciles_a_locale_whose_last_record_was_removed(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'a', 'ja', array( 'Save' => 'Save-A' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array( array_merge( array( 'kind' => 'plugin', 'slug' => 'a', 'locale' => 'ja' ), $this->default_record( 'plugin', 'a', 'ja', '1.0.0' ) ) )
		);
		$active = static function () {
			return array( array( 'kind' => 'plugin', 'slug' => 'a', 'version' => '1.0.0' ) );
		};
		$index = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$index->rebuild( 'ja' );
		$this->assertTrue( $fs->exists( Babilim_Paths::merged_default( 'ja', 'mo' ) ) );

		// The component's last record for 'ja' is now gone (e.g.
		// remove()'d), but the stale merged file and its default_index()
		// meta are both still on disk/in the manifest.
		$manifest->remove( 'plugin', 'a', 'ja' );
		$this->assertSame( array(), $manifest->all() );

		$result = $index->rebuild_all();

		$this->assertTrue( $result->is_ok() );
		$this->assertArrayHasKey( 'ja', $result->get( 'locales' ) );
		$this->assertFalse( $fs->exists( Babilim_Paths::merged_default( 'ja', 'mo' ) ) );
	}

	/**
	 * The disk-scan locale source covers BOTH served formats -- a locale
	 * whose `.mo` is already gone (or never existed) but still has a
	 * stray `.l10n.php` sitting in `default/` must still be picked up by
	 * rebuild_all(), not just the `.mo`-only case the sibling test above
	 * covers.
	 */
	public function test_rebuild_all_reconciles_a_locale_with_only_an_l10n_php_file_on_disk(): void {
		$fs = new Babilim_Memory_Fs();
		// No manifest record, no default_index() meta -- purely a stray
		// `.l10n.php` on disk (e.g. left behind by a partially-failed
		// previous rename).
		$fs->put( Babilim_Paths::merged_default( 'ru', 'l10n.php' ), "<?php\nreturn array( 'messages' => array() );\n" );
		$manifest = new Babilim_Manifest( $fs );
		$active   = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild_all();

		$this->assertTrue( $result->is_ok() );
		$this->assertArrayHasKey( 'ru', $result->get( 'locales' ) );
	}

	// -- installed_identities() (sweep_orphans()'s own "genuinely
	// removed from disk" check) uses the same wp.org-transient-aware slug
	// Babilim_Inventory/active_components() use, not a directory-name-only
	// re-derivation of its own. --------------------------------------------

	public function test_installed_identities_uses_the_wporg_transient_slug(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'hello-dolly', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'hello-dolly',
			'ja',
			array(
				'component_version' => '1.7.2',
				'files'             => array( 'plugins/hello-dolly-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		// Installed as hello.php (directory name "hello"), but its
		// wp.org slug -- what the manifest record and install() itself
		// use as identity -- is "hello-dolly".
		$GLOBALS['babilim_test_get_plugins_return']           = array( 'hello.php' => array( 'Name' => 'Hello Dolly' ) );
		$GLOBALS['babilim_test_site_transients']['update_plugins'] = (object) array(
			'no_update' => array( 'hello.php' => (object) array( 'slug' => 'hello-dolly' ) ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		// Recognized as still-installed (matched by slug, not directory
		// name) -- kept, not wrongly orphaned/deleted.
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'hello-dolly', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'hello-dolly', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
		$this->assertCount( 0, $result->get( 'orphaned' ) );
	}

	// -- installed_identities() registers a UNION of identities per
	// installed plugin (directory name, wp.org slug, text-domain header)
	// -- not a single value -- so a record survives regardless of which
	// one it happens to be keyed under, and regardless of which of those
	// signals is available at sweep time. Both directions below, plus
	// the harder case where the wp.org slug transient is genuinely
	// absent at sweep time. ------------------------------------------

	/**
	 * Direction 1 (directory name diverges from slug): the record is
	 * keyed under the plugin's own directory name (installed before any
	 * wp.org slug was ever known), and the site transient now reports a
	 * *different* wp.org slug for the same file. The directory name is
	 * always derived straight from get_plugins()'s own file key (never
	 * depends on the transient), so this must be kept regardless of
	 * what the transient says.
	 */
	public function test_installed_identities_keeps_a_record_installed_under_the_directory_name(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'hello', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'hello',
			'ja',
			array(
				'component_version' => '1.7.2',
				'files'             => array( 'plugins/hello-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		$GLOBALS['babilim_test_get_plugins_return']                 = array( 'hello.php' => array( 'Name' => 'Hello Dolly' ) );
		$GLOBALS['babilim_test_site_transients']['update_plugins']  = (object) array(
			'no_update' => array( 'hello.php' => (object) array( 'slug' => 'hello-dolly' ) ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'hello', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'hello', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
		$this->assertCount( 0, $result->get( 'orphaned' ) );
	}

	/**
	 * Direction 2 (slug diverges from directory name), the harder case:
	 * the record is keyed under the wp.org slug (established when the
	 * transient was available at install time), and the site transient
	 * has SINCE GONE MISSING entirely -- WordPress core clears
	 * `update_plugins` on every plugin install/update/delete
	 * (`wp_clean_plugins_cache()`), so this is a normal, recurring
	 * window, not a pathological one. With the transient gone, neither
	 * the directory name nor a (nonexistent) transient-reported slug can
	 * recover "hello-dolly" -- only the plugin's own text-domain header
	 * (read straight from get_plugins(), never dependent on any
	 * transient) can. A simple two-way union of directory name and
	 * transient slug alone cannot close this direction.
	 */
	public function test_installed_identities_keeps_a_record_installed_under_the_wporg_slug_when_the_transient_is_gone(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'hello-dolly', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'hello-dolly',
			'ja',
			array(
				'component_version' => '1.7.2',
				'files'             => array( 'plugins/hello-dolly-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		// The transient is gone entirely (not just missing this one
		// entry) -- exactly what wp_clean_plugins_cache() leaves behind.
		// The plugin's own header still declares its real text domain,
		// unconditionally available from get_plugins() itself.
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'hello.php' => array( 'Name' => 'Hello Dolly', 'TextDomain' => 'hello-dolly' ),
		);
		unset( $GLOBALS['babilim_test_site_transients']['update_plugins'] );
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'hello-dolly', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'hello-dolly', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
		$this->assertCount( 0, $result->get( 'orphaned' ) );
	}

	/**
	 * The directory-name identity on its own: the text-domain header is
	 * present but differs from both the directory name and the wp.org
	 * slug the site transient reports, and the record is keyed under
	 * the directory name. Neither the slug nor the text domain can
	 * recover it here -- only the directory-name identity can. (With the
	 * transient gone, the slug would itself fall back to the directory
	 * name, so the transient must be present for this case to isolate
	 * the directory-name identity.)
	 */
	public function test_installed_identities_keeps_a_record_under_the_directory_name_when_slug_and_text_domain_both_differ(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'acme-renamed', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'acme-renamed',
			'ja',
			array(
				'component_version' => '3.1.0',
				'files'             => array( 'plugins/acme-renamed-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		$GLOBALS['babilim_test_get_plugins_return']                = array(
			'acme-renamed/acme.php' => array( 'Name' => 'Acme', 'TextDomain' => 'acme-legacy' ),
		);
		$GLOBALS['babilim_test_site_transients']['update_plugins'] = (object) array(
			'no_update' => array( 'acme-renamed/acme.php' => (object) array( 'slug' => 'acme' ) ),
		);
		$active = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'acme-renamed', 'ja', 'mo' ) ) );
		$record = $manifest->installed( 'plugin', 'acme-renamed', 'ja' );
		$this->assertNull( $record['orphaned_at'] );
		$this->assertCount( 0, $result->get( 'orphaned' ) );
	}

	/**
	 * The one direction the identity union genuinely cannot close: the
	 * record is keyed under the plugin's own wp.org slug (which differs
	 * from its directory name), it has no TextDomain header (so the
	 * text-domain identity falls back to the directory name, same as the
	 * slug would with the transient gone), and the update_plugins
	 * transient is missing entirely (a normal, recurring window -- see
	 * wp_clean_plugins_cache()). installed_identities() can only ever
	 * register the directory name here, so this record is wrongly
	 * flagged as a sweep candidate. The mark-then-delete delay is what
	 * actually protects it in practice: it's marked, not deleted, on
	 * this first sweep, and is only deleted once still unmatched after
	 * the full delay -- by which point the transient has very likely
	 * come back (core repopulates it on the next plugin update check).
	 */
	public function test_sweep_orphans_mark_then_delete_delay_protects_a_slug_mismatched_record_with_no_recovery_signal(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( Babilim_Paths::file( 'plugins', 'acme-pro', 'ja', 'mo' ), 'main' );
		$manifest = new Babilim_Manifest( $fs );
		$manifest->set(
			'plugin',
			'acme-pro',
			'ja',
			array(
				'component_version' => '3.1.0',
				'files'             => array( 'plugins/acme-pro-ja.mo' => hash( 'sha256', 'main' ) ),
				'default_files'     => array(),
				'orphaned_at'       => null,
			)
		);
		// No TextDomain header at all, and the update_plugins transient
		// is gone -- every identity installed_identities() can derive
		// (directory name, transient-less slug fallback, header-less
		// text-domain fallback) collapses to the same "acme", never the
		// manifest's own "acme-pro".
		$GLOBALS['babilim_test_get_plugins_return'] = array(
			'acme/acme.php' => array( 'Name' => 'Acme Pro' ),
		);
		unset( $GLOBALS['babilim_test_site_transients']['update_plugins'] );
		$active = static function () {
			return array();
		};
		$index = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );

		// First sweep: wrongly identified as a candidate, but only marked.
		$first = $index->sweep_orphans();

		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'acme-pro', 'ja', 'mo' ) ), 'the mark-then-delete delay must protect this record on its first sweep' );
		$record = $manifest->installed( 'plugin', 'acme-pro', 'ja' );
		$this->assertNotNull( $record['orphaned_at'] );
		$this->assertContains( array( 'kind' => 'plugin', 'slug' => 'acme-pro', 'locale' => 'ja' ), $first->get( 'orphaned' ) );

		// A second sweep, still within the delay: still not deleted.
		$second = $index->sweep_orphans();
		$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', 'acme-pro', 'ja', 'mo' ) ) );
		$this->assertSame( array(), $second->get( 'files_deleted' ) );

		// Once the mark is old enough and it's STILL unmatched, it's
		// finally deleted -- simulated by backdating orphaned_at rather
		// than waiting for real time to pass.
		$record                 = $manifest->installed( 'plugin', 'acme-pro', 'ja' );
		$record['orphaned_at']  = gmdate( 'c', time() - 8 * DAY_IN_SECONDS );
		$manifest->set( 'plugin', 'acme-pro', 'ja', $record );

		$third = $index->sweep_orphans();
		$this->assertFalse( $fs->exists( Babilim_Paths::file( 'plugins', 'acme-pro', 'ja', 'mo' ) ) );
		$this->assertContains( array( 'kind' => 'plugin', 'slug' => 'acme-pro', 'locale' => 'ja' ), $third->get( 'files_deleted' ) );
	}

	/**
	 * The other half of the same edge-case list: several active
	 * components can legitimately share one text-domain identity (an
	 * add-on reusing its parent plugin's text domain). When the parent
	 * itself is inactive/removed but the add-on -- at the exact same
	 * component_version the fragment was built for -- is active, the
	 * shared text domain still counts as an active identity (contributed
	 * by the add-on), so the parent's own record still gets its default-
	 * domain supplement included in rebuild()'s merge, not excluded as
	 * "inactive".
	 */
	public function test_rebuild_includes_an_inactive_parents_supplement_when_its_active_addon_shares_the_text_domain(): void {
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'foo', 'zh_TW', array( 'Save' => 'Save-Foo' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'foo', 'locale' => 'zh_TW' ), $this->default_record( 'plugin', 'foo', 'zh_TW', '2.0' ) ),
			)
		);
		// 'foo' (the parent) is NOT active; only 'foo-addon' is, and it
		// declares the same text domain ('foo') at the same version the
		// fragment above was built for -- installed_identities()-style
		// aliasing (see Babilim::active_components()'s own alias entries).
		$active = static function () {
			return array(
				array( 'kind' => 'plugin', 'slug' => 'foo', 'version' => '2.0', 'alias' => true ),
			);
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'zh_TW' );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$meta = $manifest->default_index( 'zh_TW' );
		$this->assertCount( 1, $meta['included'] );
		$mo_entries = call_user_func( $this->parse_entries_fake( $fs ), Babilim_Paths::merged_default( 'zh_TW', 'mo' ) );
		$this->assertSame( 'Save-Foo', $mo_entries['Save'] );
	}

	/**
	 * The brake's denominator is the number of distinct managed
	 * components, not the number of records: 4 components with 3 locales
	 * each (12 records), 3 of them looking removed, is 3 of 4 components
	 * (75%) and must trip the brake -- even though 3 is not more than
	 * half of the 12 records.
	 */
	public function test_sweep_orphans_aborts_when_three_of_four_multi_locale_components_look_removed(): void {
		$fs       = new Babilim_Memory_Fs();
		$manifest = new Babilim_Manifest( $fs );
		$locales  = array( 'ja', 'ru', 'zh_TW' );
		foreach ( array( 'a', 'b', 'c', 'd' ) as $slug ) {
			foreach ( $locales as $locale ) {
				$fs->put( Babilim_Paths::file( 'plugins', $slug, $locale, 'mo' ), $slug . $locale );
				$manifest->set(
					'plugin',
					$slug,
					$locale,
					array(
						'component_version' => '1.0.0',
						'files'             => array( "plugins/{$slug}-{$locale}.mo" => hash( 'sha256', $slug . $locale ) ),
						'default_files'     => array(),
						'orphaned_at'       => null,
					)
				);
			}
		}
		$GLOBALS['babilim_test_get_plugins_return'] = array( 'a/a.php' => array( 'Name' => 'A' ) );
		$active                                      = static function () {
			return array();
		};

		$index  = new Babilim_Default_Index( $fs, $manifest, $active, $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->sweep_orphans();

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'mass_orphan_brake', $result->code() );
		foreach ( array( 'a', 'b', 'c', 'd' ) as $slug ) {
			foreach ( $locales as $locale ) {
				$this->assertTrue( $fs->exists( Babilim_Paths::file( 'plugins', $slug, $locale, 'mo' ) ) );
			}
		}
		$guard = $manifest->sweep_guard();
		$this->assertSame( 3, $guard['would_orphan'] );
		$this->assertSame( 4, $guard['managed_total'] );
	}

	// -- Several active components can share one identity key (an
	// add-on reusing its parent plugin's text domain, for example). The
	// version check must not let one of them overwrite another's, and a
	// component's own directory name / wp.org slug takes precedence over
	// another component's text-domain alias. ------------------------------

	/**
	 * @return array<string,array<string,string>>
	 */
	private function parent_and_addon_sharing_a_text_domain(): array {
		return array(
			'foo/foo.php'             => array( 'Name' => 'Foo', 'Version' => '2.0', 'TextDomain' => 'foo' ),
			'foo-addon/foo-addon.php' => array( 'Name' => 'Foo Add-on', 'Version' => '1.0', 'TextDomain' => 'foo' ),
		);
	}

	private function rebuild_with_real_active_components( string $record_version ): array {
		Babilim::reset_for_tests();
		$fs = new Babilim_Memory_Fs();
		$this->put_fragment( $fs, 'foo', 'ja', array( 'Save' => 'Save-FOO' ) );
		$manifest = $this->manifest_with_records(
			$fs,
			array(
				array_merge( array( 'kind' => 'plugin', 'slug' => 'foo', 'locale' => 'ja' ), $this->default_record( 'plugin', 'foo', 'ja', $record_version ) ),
			)
		);
		$GLOBALS['babilim_test_get_plugins_return']      = $this->parent_and_addon_sharing_a_text_domain();
		$GLOBALS['babilim_test_is_plugin_active_return'] = array(
			'foo/foo.php'             => true,
			'foo-addon/foo-addon.php' => true,
		);

		$index  = new Babilim_Default_Index( $fs, $manifest, array( Babilim::instance(), 'active_components' ), $this->parse_entries_fake( $fs ), $this->never_core_has() );
		$result = $index->rebuild( 'ja' );

		$this->assertTrue( $result->is_ok(), (string) $result->code() );
		return (array) $manifest->default_index( 'ja' );
	}

	public function test_an_active_addon_sharing_the_parents_text_domain_does_not_exclude_the_parents_fragment(): void {
		$meta = $this->rebuild_with_real_active_components( '2.0' );

		$this->assertSame( array(), $meta['excluded'] );
		$this->assertSame( array( 'foo' ), array_column( $meta['included'], 'slug' ) );
	}

	public function test_a_direct_identity_match_is_compared_only_against_that_components_own_version(): void {
		// The record's version (1.0) matches the add-on's, not the
		// parent's (2.0) -- but "foo" is the parent's own directory
		// name / slug, so only the parent's version counts.
		$meta = $this->rebuild_with_real_active_components( '1.0' );

		$this->assertSame( array( array( 'slug' => 'foo', 'reason' => 'version_mismatch' ) ), $meta['excluded'] );
		$this->assertSame( array(), $meta['included'] );
	}
}
