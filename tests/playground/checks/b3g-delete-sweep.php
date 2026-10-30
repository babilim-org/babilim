<?php
/**
 * B3g: a plugin that no longer exists on disk at all -- deleted, not
 * merely deactivated -- gets swept by `babilim_maintenance`
 * (Babilim::run_maintenance() -> Babilim_Default_Index::sweep_orphans())
 * on the real, documented mark-then-delete timeline: the FIRST sweep
 * after deletion only marks the record orphaned (served files stay);
 * only once `orphaned_at` is at least ORPHAN_FILE_DELETE_DELAY (7 days)
 * old does a later sweep actually delete plugins/{slug}-*` and
 * `default/_parts/{slug}-*`. This directly manipulates the manifest's
 * own `orphaned_at` field to simulate that elapsed time (the same
 * standard "freeze/advance the clock" technique a unit test would use,
 * not a shortcut around the real code path) rather than waiting seven
 * real days. Throughout, a site's own `custom/` override for the same
 * slug+locale -- never scanned by the sweep -- must be untouched, and
 * the merged default-domain index must stop carrying the deleted
 * component's strings immediately (via the real `delete_plugin` hook),
 * well before the file-deletion delay elapses.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b3g',
	'deleting a plugin sweeps its served files off the real mark-then-delete timeline; custom/ is untouched; the index drops it immediately',
	static function () {
		$locale = 'sv_SE';
		$slug   = 'b3g-plugin';

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename );

		$main_mo  = Babilim_Paths::file( 'plugins', $slug, $locale, 'mo' );
		$main_php = Babilim_Paths::file( 'plugins', $slug, $locale, 'l10n.php' );
		wp_mkdir_p( dirname( $main_mo ) );
		file_put_contents( $main_mo, Babilim_Mo_Writer::write( array( 'Main Domain String' => 'Main Translated' ), array() ) );
		file_put_contents( $main_php, "<?php\nreturn array( 'messages' => array( 'Main Domain String' => 'Main Translated' ) );\n" );

		babilim_pg_seed_default_fragment( 'plugin', $slug, $locale, '1.0.0', array( 'Default Domain String' => 'Default Translated' ) );

		// A site's own custom/ override -- never managed or swept by babilim.
		$custom_mo = Babilim_Paths::file( 'custom/plugins', $slug, $locale, 'mo' );
		wp_mkdir_p( dirname( $custom_mo ) );
		file_put_contents( $custom_mo, 'CUSTOM-OVERRIDE-BYTES' );

		list( , $manifest ) = babilim_pg_shared_fs_and_manifest(); // The SAME instance Babilim::instance()->run_maintenance() itself reads/writes through below -- see that function's own docblock.
		$record   = $manifest->installed( 'plugin', $slug, $locale );
		babilim_pg_assert( null !== $record, 'test setup bug: babilim_pg_seed_default_fragment() did not create a manifest record' );
		$record['files'] = array(
			"plugins/{$slug}-{$locale}.mo"      => hash( 'sha256', (string) file_get_contents( $main_mo ) ),
			"plugins/{$slug}-{$locale}.l10n.php" => hash( 'sha256', (string) file_get_contents( $main_php ) ),
		);
		$manifest->set( 'plugin', $slug, $locale, $record );

		babilim_pg_rebuild_default_index( $locale );
		$before = babilim_pg_with_locale( $locale, static function () use ( $locale ) {
			babilim_pg_reload_default_domain( $locale );
			return __( 'Default Domain String' );
		} );
		babilim_pg_assert( 'Default Translated' === $before, "baseline: default-domain string wrong before deletion: '{$before}'" );

		// Delete the plugin from disk for real (deactivate first, exactly
		// as WordPress's own delete flow requires an inactive plugin), then
		// fire the same `delete_plugin` action Babilim::
		// register_default_index_triggers() listens on -- WordPress core's
		// own delete_plugins() fires it just before removing the files.
		babilim_pg_deactivate( $basename );
		babilim_pg_rrmdir( WP_PLUGIN_DIR . '/' . $slug );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false ); // get_plugins()'s own cache (see babilim_pg_write_test_plugin()'s note) must not still list a directory just removed from disk -- sweep_orphans()'s own installed_identities() reads get_plugins() directly.
		}
		babilim_pg_assert( ! is_dir( WP_PLUGIN_DIR . '/' . $slug ), 'test setup bug: plugin directory still exists after rrmdir' );
		babilim_pg_fire_delete_plugin( $basename );

		$after_delete_hook = babilim_pg_with_locale( $locale, static function () use ( $locale ) {
			babilim_pg_reload_default_domain( $locale );
			return __( 'Default Domain String' );
		} );
		babilim_pg_assert( 'Default Domain String' === $after_delete_hook, "the merged index should have dropped the deleted plugin's string immediately via delete_plugin, got '{$after_delete_hook}'" );

		// First maintenance sweep after deletion: mark-only, per the
		// documented timeline -- served files must still be exactly there.
		Babilim::instance()->run_maintenance();
		babilim_pg_assert( is_readable( $main_mo ), 'main .mo was deleted on the FIRST sweep -- the 7-day mark-then-delete timeline was not honored' );
		babilim_pg_assert( is_readable( Babilim_Paths::file( 'default/_parts', $slug, $locale, 'mo' ) ), 'default fragment was deleted on the FIRST sweep -- the 7-day mark-then-delete timeline was not honored' );

		$record_after_first_sweep = $manifest->installed( 'plugin', $slug, $locale );
		babilim_pg_assert( null !== $record_after_first_sweep && ! empty( $record_after_first_sweep['orphaned_at'] ), 'record was not marked orphaned_at after the first sweep' );

		// Simulate 8 days having elapsed -- direct manifest manipulation,
		// not a shortcut around sweep_orphans() itself, which is still the
		// thing being exercised on the second call below.
		$record_after_first_sweep['orphaned_at'] = gmdate( 'c', time() - 8 * DAY_IN_SECONDS );
		$manifest->set( 'plugin', $slug, $locale, $record_after_first_sweep );

		Babilim::instance()->run_maintenance();

		babilim_pg_assert( ! file_exists( $main_mo ), "plugins/{$slug}-{$locale}.mo still exists after the file-deletion delay elapsed" );
		babilim_pg_assert( ! file_exists( $main_php ), "plugins/{$slug}-{$locale}.l10n.php still exists after the file-deletion delay elapsed" );
		babilim_pg_assert( ! file_exists( Babilim_Paths::file( 'default/_parts', $slug, $locale, 'mo' ) ), '_parts default fragment still exists after the file-deletion delay elapsed' );

		babilim_pg_assert( is_readable( $custom_mo ), 'the custom/ override was removed by the sweep -- it must never be touched' );
		babilim_pg_assert( 'CUSTOM-OVERRIDE-BYTES' === file_get_contents( $custom_mo ), 'the custom/ override bytes changed' );

		$after_final = babilim_pg_with_locale( $locale, static function () use ( $locale ) {
			babilim_pg_reload_default_domain( $locale );
			return __( 'Default Domain String' );
		} );
		babilim_pg_assert( 'Default Domain String' === $after_final, "the merged index still carried the deleted plugin's string after the sweep, got '{$after_final}'" );
	}
);
