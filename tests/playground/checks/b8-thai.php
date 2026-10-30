<?php
/**
 * A `th` (Thai -- no inter-word spaces, so a translation can be a
 * long unbroken run of multi-byte characters with no ASCII space
 * anywhere in it) fixture survives, byte for byte, through the real
 * install pipeline and the script-translation JSON catalog merge path --
 * proving nothing in this plugin's own code (mo writer, `.l10n.php`
 * transform, JSON merge/decode/encode) assumes a word boundary exists to
 * split, truncate, or re-chunk on.
 *
 * Every Thai code point below is a `\u{}` escape -- never a literal
 * character in this public repo.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b8-thai',
	'a real th fixture with no word-boundary spaces survives install()+transform() and the JSON catalog merge unmangled',
	static function () {
		$locale = 'th';
		$slug   = 'b8-plugin';

		// "\u{0E2A}\u{0E27}\u{0E31}\u{0E2A}\u{0E14}\u{0E35}" is the common
		// Thai greeting "sawatdee" ("hello") -- used only as a real, valid
		// Thai-script sample, not asserted for its meaning. Doubled with
		// no separator to build a longer run with zero ASCII spaces.
		$word          = "\u{0E2A}\u{0E27}\u{0E31}\u{0E2A}\u{0E14}\u{0E35}";
		$no_boundary   = $word . $word . $word; // 18 characters, zero spaces anywhere.
		babilim_pg_assert( false === strpos( $no_boundary, ' ' ), 'test setup bug: the no-boundary fixture unexpectedly contains a space' );
		$expected_len = function_exists( 'mb_strlen' ) ? mb_strlen( $no_boundary, 'UTF-8' ) : null;

		$entries = array( 'Thai Greeting Run' => $no_boundary );

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename );

		$result = babilim_pg_install( 'plugin', $slug, $locale, '1.0.0', $entries, null, array( 'Content-Type' => 'text/plain; charset=UTF-8' ) );
		babilim_pg_assert( $result->is_ok(), 'install() failed: ' . $result->message() );

		babilim_pg_with_locale(
			$locale,
			static function () use ( $slug, $no_boundary, $expected_len ) {
				// load_plugin_textdomain()'s own return value is not
				// asserted -- see b3b-jit-no-file.php's own note; the
				// real signal is the actual translated content below.
				load_plugin_textdomain( $slug, false, $slug . '/languages' );

				$got = __( 'Thai Greeting Run', $slug );
				babilim_pg_assert( $got === $no_boundary, "the no-word-boundary Thai string was altered through the real .mo/.l10n.php pipeline -- expected '{$no_boundary}', got '{$got}'" );
				if ( null !== $expected_len ) {
					babilim_pg_assert( mb_strlen( $got, 'UTF-8' ) === $expected_len, 'character count changed -- likely byte-level corruption, not just a content mismatch' );
				}
			}
		);

		// The block editor's own JSON catalog path (see b4/b7's own
		// note): the same no-boundary string must round-trip unmangled.
		$babilim_json_path = Babilim_Paths::file( 'plugins', $slug, $locale, 'json' );
		wp_mkdir_p( dirname( $babilim_json_path ) );
		file_put_contents(
			$babilim_json_path,
			wp_json_encode(
				array(
					'domain'      => $slug,
					'locale_data' => array(
						'messages' => array(
							''                   => array( 'domain' => 'messages', 'lang' => $locale ),
							'Thai Greeting Run'  => array( $no_boundary ),
						),
					),
				)
			)
		);

		$merged = babilim_pg_with_locale(
			$locale,
			static function () use ( $slug ) {
				$core_json = wp_json_encode( array( 'domain' => $slug, 'locale_data' => array( 'messages' => array( '' => array( 'domain' => 'messages', 'lang' => 'th' ) ) ) ) );
				return Babilim_Loader::load_script_translations( $core_json, 'irrelevant.json', 'irrelevant-handle', $slug );
			}
		);
		$decoded = json_decode( (string) $merged, true );
		$got_js  = $decoded['locale_data']['messages']['Thai Greeting Run'][0] ?? null;
		babilim_pg_assert( $got_js === $no_boundary, 'the no-word-boundary Thai string did not survive the JSON catalog merge unmangled: ' . var_export( $got_js, true ) );
	}
);
