<?php
/**
 * Two of this plugin's own items, installed back to back in ONE request
 * through the real production installer `Babilim::build_installer()`
 * hands out (reached via reflection, the same instance the sync run
 * drives item by item) --
 * both manifest records, both served files, and both default-domain
 * fragments must survive, not just the first (or the last) one.
 *
 * Regression coverage for a real data-loss defect this verification
 * suite found: Babilim::instance()'s
 * lazily-built collaborators (default_index(), build_installer(), site(),
 * verify_installed_items(), and admin()) each used to build their OWN
 * separate Babilim_Manifest, and Babilim_Manifest::save() overwrites
 * `_manifest.json` wholesale on every write -- so the SECOND item's own
 * install(), and any `babilim_layer_changed`-triggered rebuild in between,
 * would silently discard whatever an earlier, separately-snapshotted
 * instance had already written. Fixed by sharing exactly one
 * Babilim_Fs/Babilim_Manifest across every one of those collaborators
 * for the whole request -- see class-babilim.php's manifest() docblock.
 * The equivalent unit-level regression test (with mutation evidence)
 * is Test_Babilim::test_two_installs_in_one_request_keep_both_manifest_records;
 * this is the same property, proven in a real WordPress boot.
 */

declare( strict_types=1 );

babilim_pg_run(
	'shared-manifest-instance',
	'two of this plugin\'s own items installed back to back in one request, through the real production installer, both keep their manifest record',
	static function () {
		babilim_pg_activate( babilim_pg_write_test_plugin( 'shared-manifest-plugin-a', '1.0.0' ) );
		babilim_pg_activate( babilim_pg_write_test_plugin( 'shared-manifest-plugin-b', '1.0.0' ) );

		$installer_method = new ReflectionMethod( Babilim::class, 'build_installer' );
		$installer_method->setAccessible( true );
		$installer = $installer_method->invoke( Babilim::instance() );

		$install_one = static function ( string $slug, string $locale ) use ( $installer ) {
			list( $zip_path, $manifest_json ) = babilim_pg_build_fixture_package(
				'plugin',
				$slug,
				$locale,
				'1.0.0',
				array( 'Hello' => "Hello-{$locale}" ),
				array( "Shared-manifest default {$slug}" => "SMD-{$locale}" )
			);
			$result = $installer->install( 'plugin', $slug, $locale, $zip_path, $manifest_json );
			wp_delete_file( $zip_path );
			return $result;
		};

		$result_a = $install_one( 'shared-manifest-plugin-a', 'pt_PT' );
		$result_b = $install_one( 'shared-manifest-plugin-b', 'da_DK' );
		babilim_pg_assert( $result_a->is_ok(), "install() for shared-manifest-plugin-a failed: {$result_a->message()}" );
		babilim_pg_assert( $result_b->is_ok(), "install() for shared-manifest-plugin-b failed: {$result_b->message()}" );

		$fresh_manifest = new Babilim_Manifest( new Babilim_Wp_Fs() );
		babilim_pg_assert( null !== $fresh_manifest->installed( 'plugin', 'shared-manifest-plugin-a', 'pt_PT' ), "the FIRST item's manifest record must survive the second item's own save()." );
		babilim_pg_assert( null !== $fresh_manifest->installed( 'plugin', 'shared-manifest-plugin-b', 'da_DK' ), "the second item's manifest record must be present." );

		babilim_pg_assert( is_readable( Babilim_Paths::file( 'plugins', 'shared-manifest-plugin-a', 'pt_PT', 'mo' ) ), "shared-manifest-plugin-a's served .mo must exist." );
		babilim_pg_assert( is_readable( Babilim_Paths::file( 'plugins', 'shared-manifest-plugin-b', 'da_DK', 'mo' ) ), "shared-manifest-plugin-b's served .mo must exist." );

		$merged_a = babilim_pg_read_merged_default( 'pt_PT' );
		$merged_b = babilim_pg_read_merged_default( 'da_DK' );
		babilim_pg_assert( isset( $merged_a['Shared-manifest default shared-manifest-plugin-a'] ), "shared-manifest-plugin-a's default-domain fragment must be in the pt_PT merged index." );
		babilim_pg_assert( isset( $merged_b['Shared-manifest default shared-manifest-plugin-b'] ), "shared-manifest-plugin-b's default-domain fragment must be in the da_DK merged index." );
	}
);
