<?php
/**
 * A real, single `deactivate_plugins( [P, Q] ) call -- the ordinary
 * "select several, Bulk actions -> Deactivate" admin screen -- must
 * exclude BOTH plugins' own default-domain contributions from the
 * merged index, not just whichever one's hook fired last.
 *
 * Regression coverage for a real defect this verification suite
 * found: WordPress core's own
 * deactivate_plugins() fires `deactivated_plugin` once per plugin, all
 * from inside its own single per-plugin loop, and only writes
 * `active_plugins` back once, after that whole loop finishes -- so
 * is_plugin_active() still reports EVERY plugin in the batch as active
 * for the entire duration of the loop, not just the one currently being
 * processed. An earlier fix forced only the ONE plugin the
 * currently-firing hook is about inactive, and cleared that override
 * the moment its own hook returned -- so by the time the second
 * plugin's hook fired (still inside the very same deactivate_plugins()
 * call), the first plugin had already gone back to reading as active
 * through plain is_plugin_active(), and stayed in the merged index.
 * Fixed by accumulating every plugin either hook has heard about for
 * the rest of the request, rather than clearing after each individual
 * call -- see Babilim::on_deactivated_plugin()'s own docblock. The
 * equivalent unit-level regression tests (with mutation evidence) are
 * in tests/unit/test-babilim.php; this is the same property, proven
 * against a real WordPress core deactivate_plugins() call rather than
 * two separate simulated hook firings.
 */

declare( strict_types=1 );

babilim_pg_run(
	'batch-deactivate-excludes-all',
	'a real deactivate_plugins([P, Q]) call excludes BOTH plugins from the merged index, not just whichever one fired last',
	static function () {
		$locale = 'nb_NO';

		$p = babilim_pg_write_test_plugin( 'batch-deactivate-plugin-p', '1.0.0' );
		$q = babilim_pg_write_test_plugin( 'batch-deactivate-plugin-q', '1.0.0' );
		babilim_pg_activate( $p );
		babilim_pg_activate( $q );

		babilim_pg_seed_default_fragment( 'plugin', 'batch-deactivate-plugin-p', $locale, '1.0.0', array( 'Batch P String' => 'P Translated' ) );
		babilim_pg_seed_default_fragment( 'plugin', 'batch-deactivate-plugin-q', $locale, '1.0.0', array( 'Batch Q String' => 'Q Translated' ) );
		babilim_pg_rebuild_default_index( $locale );

		$before = babilim_pg_with_locale( $locale, static function () use ( $locale ) {
			babilim_pg_reload_default_domain( $locale );
			return array( 'p' => __( 'Batch P String' ), 'q' => __( 'Batch Q String' ) );
		} );
		babilim_pg_assert( 'P Translated' === $before['p'], "baseline: p's string wrong before deactivation: '{$before['p']}'" );
		babilim_pg_assert( 'Q Translated' === $before['q'], "baseline: q's string wrong before deactivation: '{$before['q']}'" );

		// The real batch call -- ONE deactivate_plugins() invocation for
		// both plugins together, not two separate ones.
		babilim_pg_deactivate_batch( array( $p, $q ) );

		$after = babilim_pg_with_locale( $locale, static function () use ( $locale ) {
			babilim_pg_reload_default_domain( $locale );
			return array( 'p' => __( 'Batch P String' ), 'q' => __( 'Batch Q String' ) );
		} );
		babilim_pg_assert( 'Batch P String' === $after['p'], "p's string should have fallen back to English after the batch deactivation, got '{$after['p']}' -- p's own hook fired earlier in the same deactivate_plugins() call and must still be excluded" );
		babilim_pg_assert( 'Batch Q String' === $after['q'], "q's string should have fallen back to English after the batch deactivation, got '{$after['q']}'" );
	}
);
