<?php
/**
 * B3f: a manifest record's `component_version` (the version its
 * default-domain fragment was actually built against) must match the
 * component's real, currently active version (read live via
 * get_plugins(), through Babilim::active_components()) -- not just
 * "is this component active at all". Changing the plugin's own `Version:`
 * header excludes its fragment (reason: version_mismatch); changing it
 * back restores it.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b3f',
	'a real plugin version-header change excludes its default-domain fragment (version_mismatch); reverting restores it',
	static function () {
		$locale = 'nl_NL';
		$slug   = 'b3f-plugin';

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename );
		babilim_pg_seed_default_fragment( 'plugin', $slug, $locale, '1.0.0', array( 'Version Sensitive' => 'Was Translated' ) );
		babilim_pg_rebuild_default_index( $locale );

		$before = babilim_pg_with_locale( $locale, static function () use ( $locale ) {
			babilim_pg_reload_default_domain( $locale );
			return __( 'Version Sensitive' );
		} );
		babilim_pg_assert( 'Was Translated' === $before, "baseline translation wrong before the version bump: '{$before}'" );

		babilim_pg_set_test_plugin_version( $slug, '2.0.0' ); // The manifest record still says component_version=1.0.0.
		$result = babilim_pg_rebuild_default_index( $locale );
		babilim_pg_assert( $result->is_ok(), 'rebuild after version bump failed: ' . $result->message() );

		$manifest = new Babilim_Manifest( new Babilim_Wp_Fs() );
		$meta     = $manifest->default_index( $locale );
		$excluded = (array) ( $meta['excluded'] ?? array() );
		$reasons  = array_column( $excluded, 'reason', 'slug' );
		babilim_pg_assert( ( $reasons[ $slug ] ?? null ) === 'version_mismatch', "expected {$slug} excluded with reason version_mismatch, got: " . var_export( $reasons[ $slug ] ?? null, true ) );

		$after_bump = babilim_pg_with_locale( $locale, static function () use ( $locale ) {
			babilim_pg_reload_default_domain( $locale );
			return __( 'Version Sensitive' );
		} );
		babilim_pg_assert( 'Version Sensitive' === $after_bump, "expected English fallback after the version mismatch, got '{$after_bump}'" );

		babilim_pg_set_test_plugin_version( $slug, '1.0.0' ); // Revert.
		babilim_pg_rebuild_default_index( $locale );

		$restored = babilim_pg_with_locale( $locale, static function () use ( $locale ) {
			babilim_pg_reload_default_domain( $locale );
			return __( 'Version Sensitive' );
		} );
		babilim_pg_assert( 'Was Translated' === $restored, "translation was not restored after reverting the version, got '{$restored}'" );
	}
);
