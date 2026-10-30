<?php
/**
 * A real plugin's own translation-loading timing, using real WooCommerce
 * (a plugin) and Astra (a theme) -- both installed from wordpress.org via
 * run-checks.sh's own blueprint, exactly as the original plan specified --
 * rather than a lightweight stand-in: with WooCommerce active and Astra
 * the current theme, a domain ('woocommerce') whose own bundled
 * translation is missing for the current locale must (a) pick up this
 * plugin's babilim/ supplement instead of falling back to English, and
 * (b) never trigger a `_doing_it_wrong()` notice.
 *
 * The fixture locale ('zz_ZZ') is deliberately not a real WordPress
 * locale -- guaranteed to have no bundled/wp.org WooCommerce translation
 * of its own, so a correct read can only have come from babilim's own
 * supplement, never a coincidence of WooCommerce already shipping that
 * locale.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b3',
	'real WooCommerce (wordpress.org) picks up the babilim/ supplement for a locale it has no bundled translation for, with no _doing_it_wrong',
	static function () {
		$locale = 'zz_ZZ';
		$domain = 'woocommerce';

		babilim_pg_assert( is_plugin_active( 'woocommerce/woocommerce.php' ), 'WooCommerce is not active -- run-checks.sh\'s own blueprint should have installed and activated it.' );

		$served_mo = Babilim_Paths::file( 'plugins', $domain, $locale, 'mo' );
		wp_mkdir_p( dirname( $served_mo ) );
		file_put_contents( $served_mo, Babilim_Mo_Writer::write( array( 'Add to cart' => 'Fixture Add To Cart' ), array() ) );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && is_string( ini_get( 'error_log' ) ) ) {
			$log_before = @filesize( ini_get( 'error_log' ) ) ?: 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors, Generic.PHP.NoSilencedErrors -- best-effort log-size probe.
		} else {
			$log_before = null;
		}

		$translated = babilim_pg_with_locale(
			$locale,
			static function () use ( $domain ) {
				// Reloadable: WooCommerce's own bootstrap (during
				// run-checks.sh's blueprint activation step, under the
				// site's default locale) already loaded this domain once;
				// a non-reloadable unload would set WordPress core's own
				// $l10n_unloaded flag and block the real JIT mechanism
				// (`_load_textdomain_just_in_time()`) this check relies
				// on from ever firing again for the new locale.
				unload_textdomain( $domain, true );
				return __( 'Add to cart', $domain );
			}
		);

		babilim_pg_assert( 'Fixture Add To Cart' === $translated, "expected the babilim/ supplement translation 'Fixture Add To Cart', got '{$translated}'" );

		if ( null !== $log_before ) {
			$log_path  = (string) ini_get( 'error_log' ); // Only reached when $log_before was set from this same, already-confirmed-string value above.
			$log_after = @filesize( $log_path ) ?: 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors, Generic.PHP.NoSilencedErrors
			if ( $log_after > $log_before ) {
				$new_bytes = @file_get_contents( $log_path, false, null, $log_before ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Generic.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				babilim_pg_assert(
					! is_string( $new_bytes ) || false === stripos( $new_bytes, '_doing_it_wrong' ) || false === stripos( $new_bytes, $domain ),
					"a _doing_it_wrong notice mentioning {$domain} was logged while loading its textdomain: " . substr( (string) $new_bytes, 0, 500 )
				);
			}
		}
	}
);
