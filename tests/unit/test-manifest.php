<?php
/**
 * Tests for Babilim_Manifest -- the `_manifest.json` reader/writer.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Manifest extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	private function sample_record(): array {
		return array(
			'domain'             => 'woocommerce',
			'component_version'  => '2.4.0',
			'build_id'           => 'bd_5e2a9c1f3d70',
			'pipeline_build_id'  => 'woocommerce-2.4.0-zh_TW-20260901T120000-a1b2c3',
			'build_no'           => 1,
			'built_at'           => '2026-09-01T12:00:00Z',
			'files'              => array(
				'plugins/woocommerce-zh_TW.mo' => hash( 'sha256', 'main-mo' ),
			),
			'default_files'      => array(
				'default/_parts/woocommerce-zh_TW.mo' => hash( 'sha256', 'supplement-mo' ),
			),
			'installed_at'       => '2026-09-01T12:05:00Z',
			'previous_build_id'  => null,
			'integrity'          => array(
				'checked_at' => '2026-09-01T12:05:00Z',
				'ok'         => true,
			),
			'orphaned_at'        => null,
		);
	}

	public function test_installed_returns_null_when_nothing_is_set(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	public function test_set_then_installed_round_trips_the_record(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$this->assertTrue( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() ) );

		$record = $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );

		$this->assertNotNull( $record );
		$this->assertSame( 'plugin', $record['kind'] );
		$this->assertSame( 'woocommerce', $record['slug'] );
		$this->assertSame( 'zh_TW', $record['locale'] );
		$this->assertSame( '2.4.0', $record['component_version'] );
		$this->assertNull( $record['orphaned_at'] );
		$this->assertSame(
			array( 'default/_parts/woocommerce-zh_TW.mo' => hash( 'sha256', 'supplement-mo' ) ),
			$record['default_files']
		);
	}

	public function test_set_persists_across_separate_manifest_instances_sharing_the_same_fs(): void {
		$fs = new Babilim_Memory_Fs();
		( new Babilim_Manifest( $fs ) )->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		$reloaded = new Babilim_Manifest( $fs );
		$this->assertNotNull( $reloaded->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	public function test_remove_is_idempotent(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		$manifest->remove( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );

		// Removing again must not error.
		$manifest->remove( 'plugin', 'woocommerce', 'zh_TW' );
		$this->assertNull( $manifest->installed( 'plugin', 'woocommerce', 'zh_TW' ) );
	}

	public function test_remove_of_an_unset_record_is_a_silent_no_op(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$manifest->remove( 'plugin', 'never-installed', 'zh_TW' );
		$this->assertNull( $manifest->installed( 'plugin', 'never-installed', 'zh_TW' ) );
	}

	public function test_all_returns_every_installed_record(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );
		$manifest->set( 'theme', 'twentytwentyfive', 'de_DE', $this->sample_record() );

		$all = $manifest->all();

		$this->assertCount( 2, $all );
	}

	public function test_owner_of_finds_a_record_by_its_main_file(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		$owner = $manifest->owner_of( 'plugins/woocommerce-zh_TW.mo' );

		$this->assertNotNull( $owner );
		$this->assertSame( 'woocommerce', $owner['slug'] );
	}

	public function test_owner_of_finds_a_record_by_its_default_supplement_file(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		$owner = $manifest->owner_of( 'default/_parts/woocommerce-zh_TW.mo' );

		$this->assertNotNull( $owner );
		$this->assertSame( 'woocommerce', $owner['slug'] );
	}

	public function test_owner_of_returns_null_for_an_unregistered_file(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		$this->assertNull( $manifest->owner_of( 'plugins/never-registered-zh_TW.mo' ) );
	}

	public function test_default_index_returns_null_when_unset(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$this->assertNull( $manifest->default_index( 'zh_TW' ) );
	}

	public function test_set_default_index_then_default_index_round_trips(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$meta     = array(
			'built_at'    => '2026-09-01T12:00:00Z',
			'included'    => array(
				array( 'slug' => 'woocommerce', 'component_version' => '2.4.0', 'entries' => 10 ),
			),
			'excluded'    => array(
				array( 'slug' => 'old-plugin', 'reason' => 'inactive' ),
			),
			'conflicts'   => array(
				array( 'msgctxt' => '', 'msgid' => 'Save', 'slugs' => array( 'a', 'b' ) ),
			),
			'core_dupes'  => 3,
			'sha256'      => hash( 'sha256', 'merged-index' ),
		);

		$manifest->set_default_index( 'zh_TW', $meta );

		$this->assertSame( $meta, $manifest->default_index( 'zh_TW' ) );
		$this->assertNull( $manifest->default_index( 'de_DE' ) );
	}

	public function test_set_default_index_persists_across_separate_manifest_instances(): void {
		$fs = new Babilim_Memory_Fs();
		( new Babilim_Manifest( $fs ) )->set_default_index( 'zh_TW', array( 'built_at' => 'now' ) );

		$reloaded = new Babilim_Manifest( $fs );
		$this->assertSame( array( 'built_at' => 'now' ), $reloaded->default_index( 'zh_TW' ) );
	}

	// -- Corruption handling ----------------------------------------------

	private function manifest_path(): string {
		return Babilim_Paths::manifest_file();
	}

	public function test_is_available_is_true_when_no_manifest_file_exists_yet(): void {
		$manifest = new Babilim_Manifest( new Babilim_Memory_Fs() );
		$this->assertTrue( $manifest->is_available() );
		$this->assertNull( $manifest->unavailable_reason() );
	}

	public function test_is_available_is_false_when_the_manifest_file_is_corrupt(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( $this->manifest_path(), '{not valid json' );

		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->is_available() );
		$this->assertNotNull( $manifest->unavailable_reason() );
	}

	public function test_reads_throw_when_the_manifest_file_is_corrupt(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( $this->manifest_path(), 'not json at all' );

		$manifest = new Babilim_Manifest( $fs );

		$this->expectException( RuntimeException::class );
		$manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
	}

	public function test_set_refuses_and_leaves_the_corrupt_file_untouched(): void {
		$fs          = new Babilim_Memory_Fs();
		$corrupt_raw = '{not valid json';
		$fs->put( $this->manifest_path(), $corrupt_raw );

		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() ) );

		// The original bytes are exactly as they were -- nothing overwrote it.
		$this->assertSame( $corrupt_raw, $fs->get( $this->manifest_path() ) );
	}

	public function test_set_on_a_corrupt_manifest_makes_one_quarantine_copy(): void {
		$fs          = new Babilim_Memory_Fs();
		$corrupt_raw = '{not valid json';
		$fs->put( $this->manifest_path(), $corrupt_raw );

		$manifest = new Babilim_Manifest( $fs );
		$manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );
		// A second write attempt must not make a second copy.
		$manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		$copies = $fs->list( Babilim_Paths::root(), '_manifest.json.corrupt-*' );
		$this->assertCount( 1, $copies );
		$this->assertSame( $corrupt_raw, $fs->get( Babilim_Paths::root() . '/' . $copies[0] ) );
	}

	public function test_remove_and_set_default_index_also_refuse_when_corrupt(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( $this->manifest_path(), '{not valid json' );

		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->remove( 'plugin', 'woocommerce', 'zh_TW' ) );
		$this->assertFalse( $manifest->set_default_index( 'zh_TW', array( 'built_at' => 'now' ) ) );
	}

	public function test_two_instances_quarantining_the_same_corrupt_content_leave_one_copy(): void {
		$fs          = new Babilim_Test_Recording_Fs( new Babilim_Memory_Fs() );
		$corrupt_raw = '{not valid json';
		$fs->put( $this->manifest_path(), $corrupt_raw );

		// Two entirely separate Manifest instances, each independently
		// detecting the same corrupt content.
		( new Babilim_Manifest( $fs ) )->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );
		( new Babilim_Manifest( $fs ) )->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		$copies = $fs->list( Babilim_Paths::root(), '_manifest.json.corrupt-*' );
		$this->assertCount( 1, $copies );
		$quarantine_path = Babilim_Paths::manifest_quarantine_file( substr( sha1( $corrupt_raw ), 0, 16 ) );
		$this->assertSame( $quarantine_path, Babilim_Paths::root() . '/' . $copies[0] );

		// Not just "still one file" (the same content-derived filename
		// would collapse two writes into one entry either way) -- the
		// quarantine write itself must only happen once.
		$quarantine_puts = array_filter(
			$fs->put_paths,
			static function ( $path ) use ( $quarantine_path ) {
				return $path === $quarantine_path;
			}
		);
		$this->assertCount( 1, $quarantine_puts );
	}

	public function test_differently_corrupt_content_gets_its_own_quarantine_copy(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( $this->manifest_path(), 'first corrupt content' );
		( new Babilim_Manifest( $fs ) )->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		// A different Manifest instance sees different corrupt bytes
		// (simulating the file having changed between two processes).
		$fs->delete( $this->manifest_path() );
		$fs->put( $this->manifest_path(), 'second, different corrupt content' );
		( new Babilim_Manifest( $fs ) )->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() );

		$copies = $fs->list( Babilim_Paths::root(), '_manifest.json.corrupt-*' );
		$this->assertCount( 2, $copies );
	}

	/**
	 * @dataProvider provide_wrong_shape_manifest_contents
	 */
	public function test_valid_json_with_the_wrong_shape_is_corrupt( string $json ): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put( $this->manifest_path(), $json );

		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->is_available() );
		$this->assertFalse( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() ) );
		$this->assertNotEmpty( $fs->list( Babilim_Paths::root(), '_manifest.json.corrupt-*' ) );
	}

	public function provide_wrong_shape_manifest_contents(): array {
		return array(
			'bare array'                => array( '[]' ),
			'a string'                  => array( '"just a string"' ),
			'a number'                  => array( '42' ),
			'missing default_index'     => array( '{"records":{}}' ),
			'records is not an array'   => array( '{"records":"nope","default_index":{}}' ),
			'a record missing kind'     => array( '{"records":{"a":{"slug":"x","locale":"zh_TW"}},"default_index":{}}' ),
			'a record with non-string slug' => array( '{"records":{"a":{"kind":"plugin","slug":42,"locale":"zh_TW"}},"default_index":{}}' ),
			'a record whose files is not an array' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","files":"nope"}},"default_index":{}}' ),
			'a record whose orphaned_at is not a string or null' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","orphaned_at":42}},"default_index":{}}' ),
			// Per-record field shapes.
			'a record missing locale'   => array( '{"records":{"a":{"kind":"plugin","slug":"x"}},"default_index":{}}' ),
			'a record whose files value is not a string' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","files":{"plugins\/x-zh_TW.mo":42}}},"default_index":{}}' ),
			'a record whose component_version is not a string' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","component_version":42}},"default_index":{}}' ),
			'a record whose integrity.ok is not a bool' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","integrity":{"ok":"yes"}}},"default_index":{}}' ),
			'a record whose integrity is not an array' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","integrity":"ok"}},"default_index":{}}' ),
			'a record whose version_history is not an array' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","version_history":"nope"}},"default_index":{}}' ),
			'a record with a version_history entry missing build_id' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","version_history":[{"staging":"s"}]}},"default_index":{}}' ),
			'a record with a version_history entry whose main_served value is not a string' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","version_history":[{"build_id":"bd_1","staging":"s","main_served":{"x-zh_TW.mo":42}}]}},"default_index":{}}' ),
			'a record with a version_history entry whose build_no is not an int' => array( '{"records":{"a":{"kind":"plugin","slug":"x","locale":"zh_TW","version_history":[{"build_id":"bd_1","staging":"s","build_no":"1"}]}},"default_index":{}}' ),
		);
	}

	// -- Distinct reasons for a parse failure vs a structure
	// mismatch, and per-record shape validation. -------------------------

	public function test_invalid_json_syntax_and_wrong_shape_report_different_reasons(): void {
		$fs_syntax = new Babilim_Memory_Fs();
		$fs_syntax->put( $this->manifest_path(), '{not valid json' );
		$syntax_reason = ( new Babilim_Manifest( $fs_syntax ) )->unavailable_reason();

		$fs_shape = new Babilim_Memory_Fs();
		$fs_shape->put( $this->manifest_path(), '[]' );
		$shape_reason = ( new Babilim_Manifest( $fs_shape ) )->unavailable_reason();

		$this->assertNotSame( $syntax_reason, $shape_reason );
		// The old bug: a valid-JSON-wrong-shape manifest reported
		// json_last_error_msg() ("No error"), which made no sense for
		// content that parsed just fine.
		$this->assertStringNotContainsString( 'No error', (string) $shape_reason );
	}

	public function test_a_manifest_with_one_malformed_record_is_treated_as_corrupt(): void {
		$fs = new Babilim_Memory_Fs();
		$fs->put(
			$this->manifest_path(),
			wp_json_encode(
				array(
					'records'       => array(
						'plugin|woocommerce|zh_TW' => $this->sample_record(),
						'plugin|broken|zh_TW'      => array( 'slug' => 'broken', 'locale' => 'zh_TW' ), // missing 'kind'.
					),
					'default_index' => array(),
				)
			)
		);

		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->is_available() );
		$this->assertNotEmpty( $fs->list( Babilim_Paths::root(), '_manifest.json.corrupt-*' ) );
	}

	public function test_is_available_is_false_when_the_manifest_file_exists_but_cannot_be_read(): void {
		// Simulates a real permission-denied read failure the way
		// WP_Filesystem_Direct::get_contents() itself does (exists()
		// still true, get_contents() returns false). A real chmod 000
		// can't reproduce this when the tests run as root, which
		// bypasses file permissions.
		$fs = new Babilim_Test_Unreadable_Get_Fs( new Babilim_Memory_Fs(), $this->manifest_path() );

		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->is_available() );
		$this->assertNotNull( $manifest->unavailable_reason() );
	}

	public function test_reads_throw_and_writes_refuse_when_the_manifest_file_cannot_be_read(): void {
		$fs = new Babilim_Test_Unreadable_Get_Fs( new Babilim_Memory_Fs(), $this->manifest_path() );

		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() ) );
		$this->expectException( RuntimeException::class );
		$manifest->installed( 'plugin', 'woocommerce', 'zh_TW' );
	}

	public function test_an_unreadable_manifest_is_never_quarantined(): void {
		$fs = new Babilim_Test_Unreadable_Get_Fs( new Babilim_Memory_Fs(), $this->manifest_path() );

		( new Babilim_Manifest( $fs ) )->is_available();

		$this->assertEmpty( $fs->list( Babilim_Paths::root(), '_manifest.json.corrupt-*' ) );
	}

	public function test_set_reports_failure_when_the_underlying_put_fails(): void {
		$fs       = new Babilim_Test_Failing_Put_Fs( new Babilim_Memory_Fs() );
		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() ) );
	}

	public function test_save_writes_a_temp_file_then_renames_it_into_place(): void {
		$fs       = new Babilim_Test_Recording_Fs( new Babilim_Memory_Fs() );
		$manifest = new Babilim_Manifest( $fs );

		$this->assertTrue( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() ) );

		$this->assertNotEmpty( $fs->put_paths );
		foreach ( $fs->put_paths as $put_path ) {
			$this->assertNotSame( $this->manifest_path(), $put_path, 'save() must write to a temp path, never the real one directly' );
		}
		$this->assertSame( array( array( $fs->put_paths[0], $this->manifest_path() ) ), $fs->rename_calls );
	}

	public function test_set_reports_failure_when_the_final_rename_fails(): void {
		$fs       = new Babilim_Test_Failing_Rename_Fs( new Babilim_Memory_Fs() );
		$manifest = new Babilim_Manifest( $fs );

		$this->assertFalse( $manifest->set( 'plugin', 'woocommerce', 'zh_TW', $this->sample_record() ) );
	}

	// -- Optimistic concurrency across two separate instances sharing --
	// -- the same underlying disk (two separate requests, not two    --
	// -- collaborators sharing one request -- see Babilim::manifest()'s --
	// -- own docblock for the within-one-request case, fixed separately --
	// -- by sharing a single instance). Both instances here are built --
	// -- and read from BEFORE either one writes, mirroring two real   --
	// -- requests that each construct their own Babilim_Manifest near  --
	// -- the start of handling, independently of each other.          --

	public function test_two_instances_writing_different_records_both_survive(): void {
		$fs = new Babilim_Memory_Fs();
		$m1 = new Babilim_Manifest( $fs );
		$m2 = new Babilim_Manifest( $fs );

		// Both instances load the same (empty) starting state before
		// either one writes anything.
		$this->assertNull( $m1->installed( 'plugin', 'a', 'zh_TW' ) );
		$this->assertNull( $m2->installed( 'plugin', 'b', 'zh_TW' ) );

		$this->assertTrue( $m1->set( 'plugin', 'a', 'zh_TW', $this->sample_record() ) );
		// m2's own in-memory snapshot is now stale (it doesn't know about
		// a's record) -- its own write must still land without losing a's.
		$this->assertTrue( $m2->set( 'plugin', 'b', 'zh_TW', $this->sample_record() ) );

		// A third, freshly-constructed instance -- reading the real
		// on-disk result, independent of either m1's or m2's own
		// in-memory state -- must see both records.
		$reader = new Babilim_Manifest( $fs );
		$this->assertNotNull( $reader->installed( 'plugin', 'a', 'zh_TW' ), "a's record was lost" );
		$this->assertNotNull( $reader->installed( 'plugin', 'b', 'zh_TW' ), "b's record was lost" );
	}

	public function test_two_instances_writing_the_same_record_the_second_does_not_silently_overwrite(): void {
		$fs = new Babilim_Memory_Fs();
		$m1 = new Babilim_Manifest( $fs );
		$m2 = new Babilim_Manifest( $fs );

		// Both instances load the same (empty) starting state before
		// either one writes -- same as two real concurrent requests that
		// both read the manifest before either one has written.
		$this->assertNull( $m1->installed( 'plugin', 'a', 'zh_TW' ) );
		$this->assertNull( $m2->installed( 'plugin', 'a', 'zh_TW' ) );

		$record_v1 = $this->sample_record();
		$record_v1['component_version'] = '1.0.0';
		$this->assertTrue( $m1->set( 'plugin', 'a', 'zh_TW', $record_v1 ) );

		// m2 still thinks 'a' doesn't exist (its own baseline, from its
		// own earlier load()) -- but the real disk now disagrees. This is
		// the genuine conflict save() cannot silently resolve: the same
		// record was changed on both sides.
		$record_v2 = $this->sample_record();
		$record_v2['component_version'] = '2.0.0';
		$this->assertFalse( $m2->set( 'plugin', 'a', 'zh_TW', $record_v2 ), 'm2 must not silently win a genuine same-record conflict' );

		// Disk still holds m1's own, already-landed write -- not m2's,
		// and not neither (the whole file was not corrupted/emptied).
		$reader = new Babilim_Manifest( $fs );
		$on_disk = $reader->installed( 'plugin', 'a', 'zh_TW' );
		$this->assertNotNull( $on_disk );
		$this->assertSame( '1.0.0', $on_disk['component_version'], 'the latest disk content must win an unresolvable conflict' );

		// The conflict is recorded locally, never through client_events[]
		// -- that schema has no real 'kind' a manifest write conflict
		// could honestly claim, and the service rejects anything outside
		// plugin/theme with a 422 on the whole request (see
		// Babilim_State::record_client_event()'s own guard).
		$this->assertSame( array(), ( new Babilim_State() )->pending_client_events(), 'a manifest write conflict must never reach client_events[]' );

		$conflict = $reader->write_conflict();
		$this->assertNotNull( $conflict, 'the conflict must be recorded locally' );
		$this->assertSame( 'records', $conflict['section'] );
		$this->assertSame( 'plugin|a|zh_TW', $conflict['key'] );

		// m2's OWN in-memory snapshot -- not just a freshly-constructed
		// reader's -- must also reflect the disk version after losing an
		// unresolvable conflict: set() already optimistically wrote
		// $record_v2 into $m2's own $records before save() ever ran, and
		// that must be reverted, not left dangling as a value that never
		// actually landed anywhere.
		$m2_own_view = $m2->installed( 'plugin', 'a', 'zh_TW' );
		$this->assertNotNull( $m2_own_view );
		$this->assertSame( '1.0.0', $m2_own_view['component_version'], "m2's own in-memory snapshot must revert to the disk version after losing a conflict, not keep serving its own rejected value" );
	}

	public function test_two_instances_one_removing_one_setting_a_different_record_both_land(): void {
		$fs = new Babilim_Memory_Fs();
		$this->assertTrue( ( new Babilim_Manifest( $fs ) )->set( 'plugin', 'a', 'zh_TW', $this->sample_record() ) );

		$m1 = new Babilim_Manifest( $fs );
		$m2 = new Babilim_Manifest( $fs );
		// Both load the state with only 'a' installed, before either
		// writes.
		$this->assertNotNull( $m1->installed( 'plugin', 'a', 'zh_TW' ) );
		$this->assertNull( $m2->installed( 'plugin', 'b', 'zh_TW' ) );

		$this->assertTrue( $m1->remove( 'plugin', 'a', 'zh_TW' ) );
		$this->assertTrue( $m2->set( 'plugin', 'b', 'zh_TW', $this->sample_record() ) );

		$reader = new Babilim_Manifest( $fs );
		$this->assertNull( $reader->installed( 'plugin', 'a', 'zh_TW' ), "a's removal was lost" );
		$this->assertNotNull( $reader->installed( 'plugin', 'b', 'zh_TW' ), "b's record was lost" );
	}

	/**
	 * A conflict record another instance wrote must survive a later,
	 * unrelated merge write by an instance that loaded before the
	 * conflict happened -- the merge takes the conflict record from disk,
	 * not from that instance's own stale memory.
	 */
	public function test_a_recorded_write_conflict_survives_a_later_unrelated_merge_by_a_stale_instance(): void {
		$fs = new Babilim_Memory_Fs();
		$m1 = new Babilim_Manifest( $fs );
		$m2 = new Babilim_Manifest( $fs );
		$m1->installed( 'plugin', 'a', 'zh_TW' );
		$m2->installed( 'plugin', 'a', 'zh_TW' );

		$this->assertTrue( $m1->set( 'plugin', 'a', 'zh_TW', $this->sample_record() ) );
		$this->assertFalse( $m2->set( 'plugin', 'a', 'zh_TW', $this->sample_record() ) );
		$recorded = ( new Babilim_Manifest( $fs ) )->write_conflict();
		$this->assertNotNull( $recorded );

		// m1 loaded before the conflict; its own write to an unrelated
		// record merges onto the latest disk content.
		$this->assertTrue( $m1->set( 'plugin', 'b', 'zh_TW', $this->sample_record() ) );

		$reader = new Babilim_Manifest( $fs );
		$this->assertSame( $recorded, $reader->write_conflict(), 'the conflict record must survive an unrelated merge write' );
		$this->assertNotNull( $reader->installed( 'plugin', 'b', 'zh_TW' ) );
	}

	/**
	 * The re-read under the lock fails outright: nothing may be written
	 * -- least of all this instance's own, possibly stale, full snapshot
	 * -- and the write reports failure.
	 */
	public function test_a_failed_reread_under_the_lock_writes_nothing_and_reports_failure(): void {
		$inner = new Babilim_Memory_Fs();
		( new Babilim_Manifest( $inner ) )->set( 'plugin', 'a', 'zh_TW', $this->sample_record() );
		$fs = new Babilim_Test_Scripted_Get_Fs( $inner, $this->manifest_path() );
		$m  = new Babilim_Manifest( $fs );
		$this->assertNotNull( $m->installed( 'plugin', 'a', 'zh_TW' ) );
		$before = $inner->get( $this->manifest_path() );

		$fs->next_gets = array( new WP_Error( 'test_unreadable', 'simulated read failure' ) );
		$this->assertFalse( $m->set( 'plugin', 'b', 'zh_TW', $this->sample_record() ) );

		$this->assertSame( $before, $inner->get( $this->manifest_path() ), 'nothing may be written after a failed re-read' );
		$this->assertSame( 'reread_failed', $m->last_save_failure()['reason'] );
		$this->assertNull( $m->installed( 'plugin', 'b', 'zh_TW' ), 'a write that did not land must not stay visible in memory' );
	}

	/**
	 * The manifest existed when this instance loaded it, but the re-read
	 * under the lock finds nothing (twice): that is not "no manifest yet,
	 * safe to start fresh" -- this instance must not write its own full
	 * snapshot over whatever is really there.
	 */
	public function test_a_manifest_missing_on_reread_is_never_replaced_by_this_instances_own_snapshot(): void {
		$inner = new Babilim_Memory_Fs();
		( new Babilim_Manifest( $inner ) )->set( 'plugin', 'a', 'zh_TW', $this->sample_record() );
		$fs = new Babilim_Test_Scripted_Get_Fs( $inner, $this->manifest_path() );
		$m  = new Babilim_Manifest( $fs );
		$m->installed( 'plugin', 'a', 'zh_TW' );
		$before = $inner->get( $this->manifest_path() );

		$fs->next_gets = array( null, null );
		$this->assertFalse( $m->set( 'plugin', 'b', 'zh_TW', $this->sample_record() ) );

		$this->assertSame( $before, $inner->get( $this->manifest_path() ) );
		$this->assertSame( 'reread_failed', $m->last_save_failure()['reason'] );
	}

	/**
	 * A write that could not be applied at all (here: the lock could not
	 * be taken) reports failure, leaves this instance's memory matching
	 * disk, and is recorded locally for the settings screen.
	 */
	public function test_a_write_that_cannot_take_the_lock_is_reverted_in_memory_and_recorded_locally(): void {
		$fs = new Babilim_Memory_Fs();
		$m  = new Babilim_Manifest( $fs );
		$this->assertTrue( $m->set( 'plugin', 'a', 'zh_TW', $this->sample_record() ) );

		unset( $GLOBALS['wpdb'] );
		$changed             = $this->sample_record();
		$changed['build_id'] = 'bd_ffffffffffff';
		$this->assertFalse( $m->set( 'plugin', 'a', 'zh_TW', $changed ) );
		$this->assertFalse( $m->remove( 'plugin', 'a', 'zh_TW' ) );
		$this->assertFalse( $m->set( 'plugin', 'c', 'zh_TW', $this->sample_record() ) );

		$this->assertSame( 'bd_5e2a9c1f3d70', $m->installed( 'plugin', 'a', 'zh_TW' )['build_id'], 'the failed change must not stay visible in memory' );
		$this->assertNull( $m->installed( 'plugin', 'c', 'zh_TW' ) );

		$stored = get_site_option( Babilim_Manifest::WRITE_FAILURE_OPTION );
		$this->assertIsArray( $stored );
		$this->assertSame( 'lock_unavailable', $stored['reason'] );
		$this->assertSame( 'records', $stored['section'] );
		$this->assertSame( $m->last_save_failure(), $stored );
		$this->assertSame( $stored, $m->last_recorded_write_failure() );

		// The next write that does land clears both: the instance's own
		// last failure and the recorded one the settings screen shows.
		$GLOBALS['wpdb'] = new Babilim_Test_Wpdb( null );
		$this->assertTrue( $m->set( 'plugin', 'd', 'zh_TW', $this->sample_record() ) );
		$this->assertNull( $m->last_save_failure(), 'a successful write must not leave the previous failure behind' );
		$this->assertFalse( get_site_option( Babilim_Manifest::WRITE_FAILURE_OPTION, false ), 'the recorded failure must be cleared once a write lands again' );
		$this->assertNull( $m->last_recorded_write_failure() );
	}

	/**
	 * The recorded failure is one network-wide record, like the shared
	 * `_manifest.json` it is about: a failure while serving one site of
	 * a multisite network is what every other site's settings screen
	 * shows too, and a later successful write from any site clears it.
	 */
	public function test_a_recorded_write_failure_is_network_wide_on_multisite(): void {
		$GLOBALS['babilim_test_multisite'] = true;
		$GLOBALS['babilim_test_sites']     = array( 1, 2 );
		$fs = new Babilim_Memory_Fs();

		switch_to_blog( 2 );
		$on_two = new Babilim_Manifest( $fs );
		$db     = $GLOBALS['wpdb'];
		unset( $GLOBALS['wpdb'] );
		$this->assertFalse( $on_two->set( 'plugin', 'a', 'zh_TW', $this->sample_record() ) );
		$GLOBALS['wpdb'] = $db;
		restore_current_blog();

		$on_one = new Babilim_Manifest( $fs );
		$seen   = $on_one->last_recorded_write_failure();
		$this->assertIsArray( $seen, 'the main site must see a failure recorded while serving another site' );
		$this->assertSame( 'lock_unavailable', $seen['reason'] );
		$this->assertSame( 'plugin|a|zh_TW', $seen['key'] );

		$this->assertTrue( $on_one->set( 'plugin', 'b', 'zh_TW', $this->sample_record() ) );
		switch_to_blog( 2 );
		$this->assertNull( ( new Babilim_Manifest( $fs ) )->last_recorded_write_failure(), 'a write landing from any site clears the network-wide record' );
		restore_current_blog();
	}

	/**
	 * While the manifest is unavailable, every writer reports failure
	 * with that reason -- never a previous call's leftover reason.
	 */
	public function test_a_writer_on_an_unavailable_manifest_reports_that_reason_not_a_stale_one(): void {
		$fs = new Babilim_Memory_Fs();
		$m  = new Babilim_Manifest( $fs );
		unset( $GLOBALS['wpdb'] );
		$this->assertFalse( $m->set( 'plugin', 'a', 'zh_TW', $this->sample_record() ) );
		$this->assertSame( 'lock_unavailable', $m->last_save_failure()['reason'] );
		$GLOBALS['wpdb'] = new Babilim_Test_Wpdb( null );

		$unavailable = new ReflectionProperty( Babilim_Manifest::class, 'unavailable_reason' );
		$unavailable->setAccessible( true );
		$unavailable->setValue( $m, 'unreadable' );

		$writers = array(
			'set'               => array( 'records', 'plugin|a|zh_TW', static function ( Babilim_Manifest $x ) {
				return $x->set( 'plugin', 'a', 'zh_TW', array() );
			} ),
			'remove'            => array( 'records', 'plugin|a|zh_TW', static function ( Babilim_Manifest $x ) {
				return $x->remove( 'plugin', 'a', 'zh_TW' );
			} ),
			'set_default_index' => array( 'default_index', 'zh_TW', static function ( Babilim_Manifest $x ) {
				return $x->set_default_index( 'zh_TW', array() );
			} ),
			'set_sweep_guard'   => array( 'sweep_guard', null, static function ( Babilim_Manifest $x ) {
				return $x->set_sweep_guard( array() );
			} ),
			'clear_sweep_guard' => array( 'sweep_guard', null, static function ( Babilim_Manifest $x ) {
				return $x->clear_sweep_guard();
			} ),
			'set_sweep_summary' => array( 'sweep_summary', null, static function ( Babilim_Manifest $x ) {
				return $x->set_sweep_summary( array() );
			} ),
		);
		foreach ( $writers as $name => list( $section, $key, $call ) ) {
			$this->assertFalse( $call( $m ), "{$name}() must fail on an unavailable manifest" );
			$failure = $m->last_save_failure();
			$this->assertIsArray( $failure, "{$name}()" );
			$this->assertSame( 'manifest_unavailable', $failure['reason'], "{$name}() must not report a previous call's reason" );
			$this->assertSame( $section, $failure['section'], "{$name}()" );
			$this->assertSame( $key, $failure['key'], "{$name}()" );
		}
	}
}

/**
 * A Babilim_Fs decorator whose get() of one path returns scripted results
 * (queued in $next_gets, consumed one per call) before falling through
 * to the real content again.
 */
final class Babilim_Test_Scripted_Get_Fs implements Babilim_Fs {
	/** @var array */
	public $next_gets = array();
	private $inner;
	private $path;

	public function __construct( Babilim_Fs $inner, string $path ) {
		$this->inner = $inner;
		$this->path  = $path;
	}

	public function put( string $path, string $contents ): bool {
		return $this->inner->put( $path, $contents );
	}

	public function get( string $path ) {
		if ( $path === $this->path && ! empty( $this->next_gets ) ) {
			return array_shift( $this->next_gets );
		}
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

/**
 * A Babilim_Fs decorator whose rename() always fails -- proves
 * Babilim_Manifest::save() checks rename()'s result too, not just
 * put()'s.
 */
final class Babilim_Test_Failing_Rename_Fs implements Babilim_Fs {
	private $inner;

	public function __construct( Babilim_Fs $inner ) {
		$this->inner = $inner;
	}

	public function put( string $path, string $contents ): bool {
		return $this->inner->put( $path, $contents );
	}

	public function get( string $path ): ?string {
		return $this->inner->get( $path );
	}

	public function exists( string $path ): bool {
		return $this->inner->exists( $path );
	}

	public function delete( string $path ): bool {
		return $this->inner->delete( $path );
	}

	public function rename( string $from, string $to ): bool {
		return false;
	}

	public function mkdir( string $path ): bool {
		return $this->inner->mkdir( $path );
	}

	public function list( string $dir, string $glob = '*' ): array {
		return $this->inner->list( $dir, $glob );
	}

	public function sha256( string $path ): ?string {
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
 * A Babilim_Fs decorator whose put() always fails -- proves
 * Babilim_Manifest::save() checks put()'s result instead of assuming
 * success.
 */
final class Babilim_Test_Failing_Put_Fs implements Babilim_Fs {
	private $inner;

	public function __construct( Babilim_Fs $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Writes through to $inner (so a caller that wrongly ignores this
	 * return value would still find the write "worked" via any other
	 * check, e.g. a later rename()) but always *reports* failure --
	 * isolating whether save() actually checks put()'s own result.
	 */
	public function put( string $path, string $contents ): bool {
		$this->inner->put( $path, $contents );
		return false;
	}

	public function get( string $path ): ?string {
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

	public function sha256( string $path ): ?string {
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
 * A Babilim_Fs decorator that records every put()/rename() call --
 * proves Babilim_Manifest::save() writes a temp file first and only
 * renames it over the real path afterward.
 */
final class Babilim_Test_Recording_Fs implements Babilim_Fs {
	private $inner;

	/** @var string[] */
	public $put_paths = array();

	/** @var array<int,array{0:string,1:string}> */
	public $rename_calls = array();

	public function __construct( Babilim_Fs $inner ) {
		$this->inner = $inner;
	}

	public function put( string $path, string $contents ): bool {
		$this->put_paths[] = $path;
		return $this->inner->put( $path, $contents );
	}

	public function get( string $path ): ?string {
		return $this->inner->get( $path );
	}

	public function exists( string $path ): bool {
		return $this->inner->exists( $path );
	}

	public function delete( string $path ): bool {
		return $this->inner->delete( $path );
	}

	public function rename( string $from, string $to ): bool {
		$this->rename_calls[] = array( $from, $to );
		return $this->inner->rename( $from, $to );
	}

	public function mkdir( string $path ): bool {
		return $this->inner->mkdir( $path );
	}

	public function list( string $dir, string $glob = '*' ): array {
		return $this->inner->list( $dir, $glob );
	}

	public function sha256( string $path ): ?string {
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
 * A Babilim_Fs decorator where one specific path exists but can never
 * be read -- get() returns a WP_Error for it, the way a real read
 * failure does, while exists() still reports it present. Proves
 * Babilim_Manifest tells "absent" apart from "exists but unreadable".
 */
final class Babilim_Test_Unreadable_Get_Fs implements Babilim_Fs {
	private $inner;
	private $unreadable_path;

	public function __construct( Babilim_Fs $inner, string $unreadable_path ) {
		$this->inner           = $inner;
		$this->unreadable_path = $unreadable_path;
	}

	public function put( string $path, string $contents ): bool {
		return $this->inner->put( $path, $contents );
	}

	public function get( string $path ) {
		if ( $path === $this->unreadable_path ) {
			return new WP_Error( 'test_unreadable', 'simulated read failure' );
		}
		return $this->inner->get( $path );
	}

	public function exists( string $path ): bool {
		if ( $path === $this->unreadable_path ) {
			return true;
		}
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
		if ( $path === $this->unreadable_path ) {
			return new WP_Error( 'test_unreadable', 'simulated read failure' );
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
