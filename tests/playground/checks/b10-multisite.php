<?php
/**
 * Multisite -- two sites on the same network, each viewed under its
 * own locale, each see the correct locale's babilim translation for the
 * same shared plugin slug, with no leakage between switch_to_blog()
 * calls. Run against a dedicated multisite-enabled Playground boot (see
 * run-checks.sh) -- kept separate from every other check's single-site
 * assumptions (is_plugin_active() semantics, etc.) rather than risking
 * cross-contamination by enabling multisite mid-run.
 *
 * Babilim_Paths::root() lives under WP_LANG_DIR, which is network-wide
 * in a real multisite install, not per-site -- there is no separate
 * "site's own babilim data" to isolate. The property under test is
 * locale resolution/switching staying correct as the current site
 * changes, not per-site data isolation (there is none to have).
 */

declare( strict_types=1 );

babilim_pg_run(
	'b10',
	'two multisite sites, each under its own locale, each get the correct babilim translation with no cross-site locale leakage',
	static function () {
		babilim_pg_assert( function_exists( 'is_multisite' ) && is_multisite(), 'this WordPress is not running in multisite mode -- run-checks.sh should have booted b10 with enableMultisite' );

		$slug      = 'b10-plugin';
		$locale_a  = 'fr_FR';
		$locale_b  = 'de_DE';

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename ); // Network-shared plugin files; activation state is per-site by default, but the check only needs the textdomain call to run, not is_plugin_active() specifically.

		$result_a = babilim_pg_install( 'plugin', $slug, $locale_a, '1.0.0', array( 'Multisite String' => 'French Translated' ) );
		babilim_pg_assert( $result_a->is_ok(), "install() for {$locale_a} failed: " . $result_a->message() );
		$result_b = babilim_pg_install( 'plugin', $slug, $locale_b, '1.0.0', array( 'Multisite String' => 'German Translated' ) );
		babilim_pg_assert( $result_b->is_ok(), "install() for {$locale_b} failed: " . $result_b->message() );

		$main_site_id = get_current_blog_id();
		$second_site_id = wp_insert_site(
			array(
				'domain' => parse_url( home_url(), PHP_URL_HOST ),
				'path'   => '/b10-second-site/',
				'title'  => 'Multisite Check Second Site',
			)
		);
		babilim_pg_assert( ! is_wp_error( $second_site_id ) && $second_site_id > 0, 'wp_insert_site() failed: ' . ( is_wp_error( $second_site_id ) ? $second_site_id->get_error_message() : var_export( $second_site_id, true ) ) );

		$read_on_site = static function ( int $site_id, string $locale ) use ( $slug ) {
			switch_to_blog( $site_id );
			try {
				return babilim_pg_with_locale(
					$locale,
					static function () use ( $slug ) {
						// Reloadable: this same domain is read again, for
						// a different site+locale, later in this same
						// check -- see b3-hooks-timing.php's own note on
						// why a non-reloadable unload would block that.
						unload_textdomain( $slug, true );
						load_plugin_textdomain( $slug, false, $slug . '/languages' );
						return __( 'Multisite String', $slug );
					}
				);
			} finally {
				restore_current_blog();
			}
		};

		$on_a       = $read_on_site( $main_site_id, $locale_a );
		$on_b       = $read_on_site( $second_site_id, $locale_b );
		$on_a_again = $read_on_site( $main_site_id, $locale_a ); // Re-check site A after visiting B -- no locale leakage carried across switch_to_blog().

		babilim_pg_assert( 'French Translated' === $on_a, "site A ({$locale_a}) expected 'French Translated', got '{$on_a}'" );
		babilim_pg_assert( 'German Translated' === $on_b, "site B ({$locale_b}) expected 'German Translated', got '{$on_b}'" );
		babilim_pg_assert( 'French Translated' === $on_a_again, "site A regressed to '{$on_a_again}' after visiting site B -- locale leaked across switch_to_blog()" );
	}
);
