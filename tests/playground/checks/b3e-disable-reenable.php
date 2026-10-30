<?php
/**
 * B3e: deactivating a component must, through the real `deactivated_plugin`
 * hook (Babilim::on_deactivated_plugin()), synchronously drop its
 * default-domain contribution out of the merged index -- its own strings
 * fall back to English while an unrelated, still-active component's
 * strings are unaffected. Reactivating (`activated_plugin`,
 * Babilim::on_activated_plugin()) restores it.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b3e',
	'deactivating a component drops its strings from the merged index via the real deactivated_plugin hook; reactivating restores them',
	static function () {
		$locale = 'it_IT';

		$a = babilim_pg_write_test_plugin( 'b3e-plugin-a', '1.0.0' );
		$b = babilim_pg_write_test_plugin( 'b3e-plugin-b', '1.0.0' );
		babilim_pg_activate( $a );
		babilim_pg_activate( $b );

		babilim_pg_seed_default_fragment( 'plugin', 'b3e-plugin-a', $locale, '1.0.0', array( 'Stays Active A' => 'A Stays Translated' ) );
		babilim_pg_seed_default_fragment( 'plugin', 'b3e-plugin-b', $locale, '1.0.0', array( 'Toggled B' => 'B Toggled Translated' ) );
		babilim_pg_rebuild_default_index( $locale ); // Baseline, matching what activated_plugin already did for each -- rebuild is idempotent.

		$before = babilim_pg_with_locale(
			$locale,
			static function () use ( $locale ) {
				babilim_pg_reload_default_domain( $locale );
				return array( 'a' => __( 'Stays Active A' ), 'b' => __( 'Toggled B' ) );
			}
		);
		babilim_pg_assert( 'A Stays Translated' === $before['a'], "baseline: a's string wrong before toggling: '{$before['a']}'" );
		babilim_pg_assert( 'B Toggled Translated' === $before['b'], "baseline: b's string wrong before toggling: '{$before['b']}'" );

		babilim_pg_deactivate( $b ); // Fires the real `deactivated_plugin` hook -- rebuild happens synchronously, not deferred.

		$after_deactivate = babilim_pg_with_locale(
			$locale,
			static function () use ( $locale ) {
				babilim_pg_reload_default_domain( $locale );
				return array( 'a' => __( 'Stays Active A' ), 'b' => __( 'Toggled B' ) );
			}
		);
		babilim_pg_assert(
			'A Stays Translated' === $after_deactivate['a'],
			"a's string regressed after deactivating b: '{$after_deactivate['a']}'"
		);
		babilim_pg_assert( 'Toggled B' === $after_deactivate['b'], "b's string should have fallen back to English after deactivation, got '{$after_deactivate['b']}'" );

		babilim_pg_activate( $b ); // Fires the real `activated_plugin` hook.

		$after_reactivate = babilim_pg_with_locale(
			$locale,
			static function () use ( $locale ) {
				babilim_pg_reload_default_domain( $locale );
				return array( 'a' => __( 'Stays Active A' ), 'b' => __( 'Toggled B' ) );
			}
		);
		babilim_pg_assert( 'A Stays Translated' === $after_reactivate['a'], "a's string regressed after reactivating b: '{$after_reactivate['a']}'" );
		babilim_pg_assert( 'B Toggled Translated' === $after_reactivate['b'], "b's string was not restored after reactivation, got '{$after_reactivate['b']}'" );
	}
);
