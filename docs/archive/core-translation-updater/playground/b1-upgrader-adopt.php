<?php
/**
 * The real WordPress core `Language_Pack_Upgrader::bulk_upgrade()`
 * path, driven end to end -- this plugin's own hooks registered exactly
 * the way production does (plugins_loaded -> Babilim::instance()->init(),
 * already run by the time this check executes), a real HTTP round trip
 * to a local mock service (POST /v1/downloads -> 302 Location, then a
 * real `download_url()` GET of the signed link), and a real zip
 * (mock-server/build-package.php's output) unpacked by core's own
 * unpack_package().
 *
 * Current architecture (per the plugin's own class docblocks, NOT the
 * older "destination redirected to babilim/plugins" design): the update
 * is adopted into Babilim_Paths' own served directories via
 * upgrader_source_selection() -> Babilim_Installer::adopt(); core's own
 * clear_destination() targets a redirected, plugin-owned `_incoming/`
 * directory instead of the real WP_LANG_DIR/plugins, so a pre-existing
 * wp.org language pack for the same slug/locale must survive untouched.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b1',
	'upgrader_pre_download/package_options/source_selection adopt a real Language_Pack_Upgrader::bulk_upgrade() item without touching a pre-existing wp.org pack',
	static function () {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php';

		$slug     = 'b1-plugin';
		$locale   = 'de_DE';
		// Fixed, not babilim_pg_build_id() -- must match the fixture
		// package run-checks.sh pre-builds via mock-server/build-package.php
		// under this exact id (the mock service serves packages by id,
		// looked up as a file on disk; there is no round trip back to the
		// host from inside Playground to register a randomly-chosen one).
		$build_id = 'bd_0000000000b1';

		// A pre-existing wp.org language pack for the exact same
		// slug/locale -- must survive byte-for-byte.
		$lang_plugins = trailingslashit( WP_LANG_DIR ) . 'plugins';
		wp_mkdir_p( $lang_plugins );
		file_put_contents( "{$lang_plugins}/{$slug}-{$locale}.mo", 'WPORG-OLD-MO' );
		file_put_contents( "{$lang_plugins}/{$slug}-{$locale}.po", 'WPORG-OLD-PO' );

		( new Babilim_State() )->set(
			array(
				'updates' => array(
					array( 'kind' => 'plugin', 'slug' => $slug, 'locale' => $locale, 'build_id' => $build_id, 'build_no' => 1 ),
				),
			)
		);

		$item = (object) array(
			'type'       => 'plugin',
			'slug'       => $slug,
			'language'   => $locale,
			'version'    => '',
			'updated'    => '',
			'package'    => rtrim( BABILIM_API_BASE, '/' ) . '/babilim-package/' . $build_id,
			'autoupdate' => 1,
			'babilim'    => true,
			'build_id'   => $build_id,
			'build_no'   => 1,
		);

		$skin    = new WP_Upgrader_Skin();
		$lpu     = new Language_Pack_Upgrader( $skin );
		$results = $lpu->bulk_upgrade( array( $item ) );

		$served_mo  = Babilim_Paths::file( 'plugins', $slug, $locale, 'mo' );
		$served_php = Babilim_Paths::file( 'plugins', $slug, $locale, 'l10n.php' );
		babilim_pg_assert( is_readable( $served_mo ), "served .mo missing: {$served_mo} (bulk_upgrade result: " . wp_json_encode( babilim_pg_describe_upgrade_results( $results ) ) . ') -- see docs/playground-results.md, known limitation: fs_connect()/request_filesystem_credentials() under `wp eval-file` in this environment' );
		babilim_pg_assert( is_readable( $served_php ), "served .l10n.php missing: {$served_php}" );

		babilim_pg_assert(
			'WPORG-OLD-MO' === file_get_contents( "{$lang_plugins}/{$slug}-{$locale}.mo" ),
			'pre-existing wp.org .mo was modified/deleted'
		);
		babilim_pg_assert(
			'WPORG-OLD-PO' === file_get_contents( "{$lang_plugins}/{$slug}-{$locale}.po" ),
			'pre-existing wp.org .po was modified/deleted'
		);

		babilim_pg_assert( class_exists( 'WP_Translation_File' ), 'WP_Translation_File not available to re-parse the served file' );
		$file = \WP_Translation_File::create( $served_mo );
		babilim_pg_assert( false !== $file, 'served .mo failed to parse as a real WP_Translation_File' );
		$entries = (array) $file->entries();
		unset( $entries[''] );
		babilim_pg_assert( 1 === count( $entries ), 'expected exactly 1 real translated entry, got ' . count( $entries ) );

		$incoming_root = Babilim_Paths::incoming_root();
		if ( is_dir( $incoming_root ) ) {
			$left = array_diff( (array) scandir( $incoming_root ), array( '.', '..' ) );
			babilim_pg_assert( array() === $left, '_incoming/ left non-empty after the run: ' . implode( ',', $left ) );
		}

		$state = ( new Babilim_State() )->get();
		babilim_pg_assert(
			! in_array( $build_id, array_column( (array) ( $state['updates'] ?? array() ), 'build_id' ), true ),
			'the adopted build_id is still listed pending in Babilim_State after a successful install'
		);
	}
);
