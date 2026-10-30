<?php
/**
 * An `ar` (Arabic, RTL, real `nplurals=6` CLDR rule) fixture --
 * plural forms and an embedded `%s` placeholder -- survives, byte for
 * byte, through the real install pipeline (Babilim_Installer::install() ->
 * WP_Translation_File::transform() producing a real `.l10n.php`) and
 * through the real WordPress plural-selection machinery (`_n()`), and
 * through the script-translation JSON path the block editor itself reads
 * from (see b4's own note on why that filter, not a full editor page
 * render, is this repo's own proxy for "renders in the block editor").
 *
 * Non-CJK script text is never committed as literal source in this
 * public repo -- every Arabic code point below is a `\u{}` escape, not
 * a literal character.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b7-rtl-plural',
	'a real ar (RTL, nplurals=6) fixture survives install()+transform(), real _n() plural selection, and the JSON catalog path',
	static function () {
		$locale = 'ar';
		$slug   = 'b7-plugin';

		// "\u{0627}\u{0644}\u{0633}\u{0644}\u{0627}\u{0645}" is the common
		// Arabic word "as-salaam" ("the peace") -- used only as a real,
		// valid Arabic-script sample string, not asserted for its meaning.
		$sample = "\u{0627}\u{0644}\u{0633}\u{0644}\u{0627}\u{0645}";
		babilim_pg_assert( 'Arabic' === ( function_exists( 'mb_check_encoding' ) ? ( mb_check_encoding( $sample, 'UTF-8' ) ? 'Arabic' : 'BAD' ) : 'Arabic' ), 'sample fixture string is not valid UTF-8' );

		$plural_forms = array( $sample . ' 0', $sample . ' 1', $sample . ' 2', $sample . ' 3-10', $sample . ' 11-99', $sample . ' 100+' );
		$entries       = array(
			'One Item' => $plural_forms,
			'Total: %s' => $sample . ' %s ' . $sample,
		);
		$headers = array(
			'Content-Type'  => 'text/plain; charset=UTF-8',
			'Plural-Forms'  => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);',
		);

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename );

		$result = babilim_pg_install( 'plugin', $slug, $locale, '1.0.0', $entries, null, $headers );
		babilim_pg_assert( $result->is_ok(), 'install() failed: ' . $result->message() );

		babilim_pg_assert( is_rtl_locale_known( $locale ), 'test sanity: ar is not known as RTL by this check\'s own table' );

		$cases = array(
			0   => 0,
			1   => 1,
			2   => 2,
			3   => 3,
			11  => 4,
			100 => 5,
		);

		babilim_pg_with_locale(
			$locale,
			static function () use ( $slug, $cases, $plural_forms, $sample ) {
				// load_plugin_textdomain()'s own return value is not
				// asserted -- see b3b-jit-no-file.php's own note; the
				// real signal is the actual translated content below.
				load_plugin_textdomain( $slug, false, $slug . '/languages' );

				// is_rtl() reads WP_Locale's own "text direction" entry
				// from core's OWN real "ar" language pack (run-checks.sh's
				// own lang-stub generation seeds this for real) -- never
				// from anything this plugin loads. Confirming it here is
				// a sanity check on the fixture, not on Babilim's own code.
				babilim_pg_assert( is_rtl(), 'is_rtl() is false while the request locale is ar -- check the "ar" lang-stub carries a real "text direction" => "rtl" entry (run-checks.sh)' );

				foreach ( $cases as $n => $expected_index ) {
					$got = _n( 'One Item', 'One Item', $n, $slug ); // gettext plural lookup keys off the singular msgid; the WP mo reader picks the form by n via the loaded Plural-Forms header.
					babilim_pg_assert( $got === $plural_forms[ $expected_index ], "n={$n}: expected plural form index {$expected_index} ('{$plural_forms[$expected_index]}'), got '{$got}'" );
				}

				$total = sprintf( __( 'Total: %s', $slug ), '100' );
				babilim_pg_assert( $total === ( $sample . ' 100 ' . $sample ), "the %s placeholder inside the RTL string was not substituted/preserved correctly, got '{$total}'" );
			}
		);

		// The block editor's own consumption path: a JSON catalog with
		// the same plural/placeholder content must round-trip through the
		// real load_script_translations() merge unmangled.
		$babilim_json_path = Babilim_Paths::file( 'plugins', $slug, $locale, 'json' );
		wp_mkdir_p( dirname( $babilim_json_path ) );
		file_put_contents(
			$babilim_json_path,
			wp_json_encode(
				array(
					'domain'      => $slug,
					'locale_data' => array(
						'messages' => array(
							''          => array( 'domain' => 'messages', 'lang' => $locale, 'plural-forms' => $headers['Plural-Forms'] ),
							'One Item'  => $plural_forms,
						),
					),
				)
			)
		);

		$merged = babilim_pg_with_locale(
			$locale,
			static function () use ( $slug ) {
				$core_json = wp_json_encode( array( 'domain' => $slug, 'locale_data' => array( 'messages' => array( '' => array( 'domain' => 'messages', 'lang' => 'ar' ) ) ) ) );
				return Babilim_Loader::load_script_translations( $core_json, 'irrelevant.json', 'irrelevant-handle', $slug );
			}
		);
		$decoded = json_decode( (string) $merged, true );
		$got_forms = $decoded['locale_data']['messages']['One Item'] ?? null;
		babilim_pg_assert( $got_forms === $plural_forms, 'the RTL plural forms did not survive the JSON catalog merge unmangled: ' . var_export( $got_forms, true ) );
	}
);

/** A tiny, deliberately explicit RTL-locale table -- this check's own sanity guard, independent of WordPress core's own (much larger) is_rtl() list. */
function is_rtl_locale_known( string $locale ): bool {
	return in_array( $locale, array( 'ar', 'he_IL', 'fa_IR', 'ur' ), true );
}
