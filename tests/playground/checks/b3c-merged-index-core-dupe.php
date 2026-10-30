<?php
/**
 * B3c: the merged `default` domain index picks up a real component's
 * supplement fragment, is actually loaded by two consecutive
 * switch_to_locale() calls plus restore_current_locale() (exercising
 * `change_locale`, not just a direct rebuild() call), and a string
 * WordPress core's own `default` translation already has for that locale
 * is excluded from the merge rather than overwritten.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b3c',
	'merged default-domain index loads via switch_to_locale()x2 + restore_current_locale(); a core-known string is not overwritten',
	static function () {
		$locale = 'es_ES';
		$slug   = 'b3c-plugin';

		// Simulate "WordPress core already officially translates this
		// string for es_ES" -- Babilim::core_has() reads core's own
		// real language-pack file for the locale directly
		// (WP_LANG_DIR/{locale}.mo, never the WP_Translation_Controller's
		// runtime tracking -- see core_default_domain_entries()'s own
		// docblock), so the fixture must be written to that exact real
		// path: run-checks.sh's own lang-stub step already put a real,
		// valid, empty es_ES.mo there before boot (so switch_to_locale()
		// below accepts es_ES as an available locale at all); overwritten
		// here, mid-request, with real content before it's read.
		$core_mo = trailingslashit( WP_LANG_DIR ) . $locale . '.mo';
		file_put_contents( $core_mo, Babilim_Mo_Writer::write( array( 'Dupe String' => 'Core Already Has This' ), array() ) );
		babilim_pg_assert(
			Babilim::instance()->core_has( 'Dupe String', '', $locale ),
			'core_has() does not see the simulated core translation -- test setup is not exercising the real check'
		);

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename );
		babilim_pg_seed_default_fragment(
			'plugin',
			$slug,
			$locale,
			'1.0.0',
			array(
				'Dupe String' => 'Should Be Excluded',
				'Unique B3c'  => 'B3c Translated',
			)
		);

		$result = babilim_pg_rebuild_default_index( $locale );
		babilim_pg_assert( $result->is_ok(), 'rebuild(' . $locale . ') failed: ' . $result->message() );

		$manifest = new Babilim_Manifest( new Babilim_Wp_Fs() );
		$meta     = $manifest->default_index( $locale );
		babilim_pg_assert( is_array( $meta ) && ( $meta['core_dupes'] ?? 0 ) >= 1, 'expected core_dupes >= 1 in the rebuild meta' );

		$merged = babilim_pg_read_merged_default( $locale );
		babilim_pg_assert( isset( $merged['Unique B3c'] ) && 'B3c Translated' === $merged['Unique B3c'], 'the non-conflicting fragment entry is missing from the merged .mo on disk' );
		babilim_pg_assert( ! array_key_exists( 'Dupe String', $merged ), "core-known 'Dupe String' leaked into the merged .mo instead of being excluded" );

		// Now load it for real through TWO separate, sequential
		// switch_to_locale()/restore_current_locale() round trips --
		// exercises `change_locale` (Babilim_Loader::
		// load_default_supplements()'s own trigger) twice over, not a
		// direct rebuild()/read call, and proves the second real request
		// sees it just as correctly as the first (not merely once by
		// accident).
		$read_once = static function () {
			return array(
				'ours' => __( 'Unique B3c' ),
				'core' => __( 'Dupe String' ),
			);
		};
		babilim_pg_with_locale( $locale, $read_once ); // switch_to_locale() #1.
		$seen = babilim_pg_with_locale( $locale, $read_once ); // switch_to_locale() #2.

		babilim_pg_assert( 'B3c Translated' === $seen['ours'], "expected the merged supplement's own translation for 'Unique B3c', got '{$seen['ours']}'" );
		babilim_pg_assert( 'Core Already Has This' === $seen['core'], "expected core's own translation for 'Dupe String' to win, got '{$seen['core']}'" );
	}
);
