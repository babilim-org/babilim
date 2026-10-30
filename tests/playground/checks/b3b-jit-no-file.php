<?php
/**
 * B3b: a component whose own directory core registers for JIT loading
 * has no file for the requested locale, AND this plugin's own
 * babilim/plugins/ supplement has nothing for it either. Every callback
 * in Babilim_Loader must no-op cleanly (return core's own value
 * unchanged) rather than erroring -- the string simply stays English,
 * exactly as it would with this plugin not installed at all.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b3b',
	'no babilim supplement and no bundled file -- a JIT-registered domain falls back to English with no error',
	static function () {
		$slug   = 'b3b-plugin';
		$locale = 'de_DE';

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename );

		// Deliberately: no babilim/plugins/ file for this slug+locale at all.
		$served_mo = Babilim_Paths::file( 'plugins', $slug, $locale, 'mo' );
		babilim_pg_assert( ! file_exists( $served_mo ), 'test setup bug: a served file already exists for b3b' );

		$translated = babilim_pg_with_locale(
			$locale,
			static function () use ( $slug ) {
				// load_plugin_textdomain()'s own return value is not
				// asserted here -- WordPress 6.7+ always returns true
				// (it only registers a lookup path and defers to real
				// JIT loading; see docs/playground-results.md), so that
				// return value can never fail this check meaningfully on
				// those versions. The real, version-independent signal is
				// the actual translated string below.
				load_plugin_textdomain( $slug, false, $slug . '/languages' );
				return __( 'Save', $slug );
			}
		);

		babilim_pg_assert( 'Save' === $translated, "expected the untranslated English string 'Save', got '{$translated}'" );
	}
);
