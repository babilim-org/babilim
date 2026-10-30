<?php
/**
 * A mixed batch -- one real wp.org language-pack item and one of
 * this plugin's own items for a DIFFERENT slug -- both processed by one
 * real `Language_Pack_Upgrader::bulk_upgrade()` call. Asserts our own
 * item is served from Babilim_Paths (never WP_LANG_DIR/plugins) and the
 * wp.org item lands in the real WP_LANG_DIR/plugins exactly as it would
 * with this plugin not installed at all -- this plugin's hooks must
 * never interfere with an unrelated wp.org item in the same batch.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b2',
	'a real wp.org item and one of this plugin\'s own items both install correctly from the same bulk_upgrade() batch',
	static function () {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php';

		$our_slug   = 'b2-ours';
		$wporg_slug = 'b2-wporg';
		$locale     = 'fr_FR';
		// Fixed, not babilim_pg_build_id() -- see b1-upgrader-adopt.php's
		// own note; must match run-checks.sh's pre-built fixture package.
		$build_id   = 'bd_0000000000b2';

		( new Babilim_State() )->set(
			array(
				'updates' => array(
					array( 'kind' => 'plugin', 'slug' => $our_slug, 'locale' => $locale, 'build_id' => $build_id, 'build_no' => 1 ),
				),
			)
		);

		$ours = (object) array(
			'type'       => 'plugin',
			'slug'       => $our_slug,
			'language'   => $locale,
			'version'    => '',
			'updated'    => '',
			'package'    => rtrim( BABILIM_API_BASE, '/' ) . '/babilim-package/' . $build_id,
			'autoupdate' => 1,
			'babilim'    => true,
			'build_id'   => $build_id,
			'build_no'   => 1,
		);
		$wporg = (object) array(
			'type'       => 'plugin',
			'slug'       => $wporg_slug,
			'language'   => $locale,
			'version'    => '1.0',
			'updated'    => '',
			'package'    => rtrim( BABILIM_API_BASE, '/' ) . '/wporg-package/' . $wporg_slug . '-' . $locale . '.zip',
			'autoupdate' => 1,
		);

		$skin = new WP_Upgrader_Skin();
		$lpu  = new Language_Pack_Upgrader( $skin );
		$lpu->bulk_upgrade( array( $wporg, $ours ) );

		$served_mo = Babilim_Paths::file( 'plugins', $our_slug, $locale, 'mo' );
		babilim_pg_assert( is_readable( $served_mo ), "our item's served .mo missing: {$served_mo}" );

		$our_in_lang_dir = trailingslashit( WP_LANG_DIR ) . "plugins/{$our_slug}-{$locale}.mo";
		babilim_pg_assert( ! file_exists( $our_in_lang_dir ), "our own item's package leaked into WP_LANG_DIR/plugins: {$our_in_lang_dir}" );

		$wporg_mo = trailingslashit( WP_LANG_DIR ) . "plugins/{$wporg_slug}-{$locale}.mo";
		$wporg_po = trailingslashit( WP_LANG_DIR ) . "plugins/{$wporg_slug}-{$locale}.po";
		babilim_pg_assert( is_readable( $wporg_mo ), "wp.org item's .mo did not land in WP_LANG_DIR/plugins: {$wporg_mo}" );
		babilim_pg_assert( 'WPORG-NEW-MO' === file_get_contents( $wporg_mo ), 'wp.org item .mo content unexpected' );
		babilim_pg_assert( 'WPORG-NEW-PO' === file_get_contents( $wporg_po ), 'wp.org item .po content unexpected' );
	}
);
