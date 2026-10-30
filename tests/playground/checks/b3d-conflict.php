<?php
/**
 * B3d: two active components each contribute a default-domain fragment;
 * both translate the SAME msgid to a DIFFERENT msgstr. That key is
 * excluded from the merge entirely (English wins for it -- falling back
 * to English beats silently picking a winner); every other,
 * non-conflicting key each component contributes is still merged and
 * translated correctly.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b3d',
	'two components disagreeing on the same msgid are both excluded for that key; their own other entries still merge correctly',
	static function () {
		$locale = 'pt_PT';

		$a = babilim_pg_write_test_plugin( 'b3d-plugin-a', '1.0.0' );
		$b = babilim_pg_write_test_plugin( 'b3d-plugin-b', '1.0.0' );
		babilim_pg_activate( $a );
		babilim_pg_activate( $b );

		babilim_pg_seed_default_fragment(
			'plugin',
			'b3d-plugin-a',
			$locale,
			'1.0.0',
			array(
				'Shared Conflicting String' => 'Translation From A',
				'Only In A'                 => 'A Only Translated',
			)
		);
		babilim_pg_seed_default_fragment(
			'plugin',
			'b3d-plugin-b',
			$locale,
			'1.0.0',
			array(
				'Shared Conflicting String' => 'Translation From B',
				'Only In B'                 => 'B Only Translated',
			)
		);

		$result = babilim_pg_rebuild_default_index( $locale );
		babilim_pg_assert( $result->is_ok(), 'rebuild(' . $locale . ') failed: ' . $result->message() );

		$manifest = new Babilim_Manifest( new Babilim_Wp_Fs() );
		$meta     = $manifest->default_index( $locale );
		babilim_pg_assert( is_array( $meta ) && count( (array) ( $meta['conflicts'] ?? array() ) ) >= 1, 'expected at least one recorded conflict' );

		$merged = babilim_pg_read_merged_default( $locale );
		babilim_pg_assert( ! array_key_exists( 'Shared Conflicting String', $merged ), 'the conflicting key leaked into the merged index instead of being excluded' );
		babilim_pg_assert( isset( $merged['Only In A'] ) && 'A Only Translated' === $merged['Only In A'], "component a's own non-conflicting entry is missing/wrong" );
		babilim_pg_assert( isset( $merged['Only In B'] ) && 'B Only Translated' === $merged['Only In B'], "component b's own non-conflicting entry is missing/wrong" );

		// Real front-end read: the conflicting string falls back to
		// English; each component's own unique string is still correct.
		$seen = babilim_pg_with_locale(
			$locale,
			static function () {
				return array(
					'shared' => __( 'Shared Conflicting String' ),
					'a'      => __( 'Only In A' ),
					'b'      => __( 'Only In B' ),
				);
			}
		);
		babilim_pg_assert( 'Shared Conflicting String' === $seen['shared'], "expected the conflicting string to stay untranslated (English), got '{$seen['shared']}'" );
		babilim_pg_assert( 'A Only Translated' === $seen['a'], "component a's own string is wrong at the front end: '{$seen['a']}'" );
		babilim_pg_assert( 'B Only Translated' === $seen['b'], "component b's own string is wrong at the front end: '{$seen['b']}'" );
	}
);
