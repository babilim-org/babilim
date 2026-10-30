<?php
/**
 * The plugin's own bundled zh_TW translation, on a real WordPress. Three
 * results, all reported as "b12-bundled-l10n":
 *
 * 1. Switching to zh_TW makes the bundled file load: a string whose
 *    shipped translation differs from the English source comes back
 *    translated.
 * 2. A site's own language pack in WP_LANG_DIR/plugins/ wins over the
 *    bundled file: core loads that location first and never reaches the
 *    plugin's own languages directory.
 * 3. No PHP warning, notice or deprecation is raised or logged for
 *    "babilim" while doing either.
 */

declare( strict_types=1 );

$babilim_pg_b12_log     = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : '';
$babilim_pg_b12_log_len = ( '' !== $babilim_pg_b12_log && is_readable( $babilim_pg_b12_log ) ) ? (int) filesize( $babilim_pg_b12_log ) : 0;
// Collects every PHP error raised while this check runs. Re-firing 'init'
// (needed to make the plugin load its translation again) also re-runs
// other plugins' init callbacks, which may complain about themselves, so
// the last step only looks at errors that involve this plugin.
$babilim_pg_b12_errors = array();
set_error_handler(
	static function ( int $errno, string $errstr, string $errfile ) use ( &$babilim_pg_b12_errors ) {
		$babilim_pg_b12_errors[] = array( $errno, $errstr, $errfile );
		return true;
	}
);

babilim_pg_run(
	'b12-bundled-l10n',
	'the bundled zh_TW file loads when the locale switches to zh_TW',
	static function () {
		$english = 'Babilim settings';
		unload_textdomain( 'babilim' );
		$switched = switch_to_locale( 'zh_TW' );
		try {
			do_action( 'init' );
			$bundled = __( $english, 'babilim' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- deliberate.
			babilim_pg_assert( $bundled !== $english, "the bundled zh_TW file was not loaded: '{$english}' came back untranslated" );
		} finally {
			if ( $switched ) {
				restore_current_locale();
			}
		}
	}
);

babilim_pg_run(
	'b12-bundled-l10n',
	'a site language pack in WP_LANG_DIR/plugins/ wins over the bundled file',
	static function () {
		$override_dir  = WP_LANG_DIR . '/plugins';
		$override_file = $override_dir . '/babilim-zh_TW.l10n.php';
		babilim_pg_assert( ! file_exists( $override_file ), 'test setup bug: a site language pack for babilim already exists' );
		wp_mkdir_p( $override_dir );
		$php = "<?php\nreturn array( 'domain' => 'babilim', 'language' => 'zh_TW', 'plural-forms' => 'nplurals=1; plural=0;', 'messages' => array( 'Enable Babilim' => 'SITE-OVERRIDE' ) );\n";
		file_put_contents( $override_file, $php );
		$depth = 0;
		try {
			unload_textdomain( 'babilim' );
			// WordPress >= 6.8 loads translations just in time and remembers where
			// it already found this domain (the first result above resolved it to
			// the plugin's own directory), so a site file written afterwards would
			// never be looked for. Start from a state where nothing is remembered,
			// as a fresh request would be, so every version proves the same thing.
			if ( class_exists( 'WP_Textdomain_Registry' ) ) {
				$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry();
			}
			wp_cache_delete( md5( WP_LANG_DIR . '/plugins/' ), 'translation_files' );
			$depth += switch_to_locale( 'en_US' ) ? 1 : 0;
			$depth += switch_to_locale( 'zh_TW' ) ? 1 : 0;
			do_action( 'init' );
			$got = __( 'Enable Babilim', 'babilim' );
			babilim_pg_assert( 'SITE-OVERRIDE' === $got, "the site language pack did not win over the bundled file: got '{$got}'" );
			// The site file has no entry for this string, and the bundled file does: the bundled file must not have been loaded as well.
			$other = __( 'Babilim settings', 'babilim' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- deliberate.
			babilim_pg_assert( 'Babilim settings' === $other, "the bundled file was loaded although a site language pack exists: got '{$other}'" );
		} finally {
			@unlink( $override_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture cleanup.
			for ( $i = 0; $i < $depth; $i++ ) {
				restore_current_locale();
			}
			unload_textdomain( 'babilim' );
		}
	}
);

babilim_pg_run(
	'b12-bundled-l10n',
	'no PHP error and no babilim line in debug.log while loading the bundled translation',
	static function () use ( $babilim_pg_b12_log, $babilim_pg_b12_log_len, &$babilim_pg_b12_errors) {
		restore_error_handler();
		foreach ( $babilim_pg_b12_errors as $babilim_pg_b12_error ) {
			$babilim_pg_b12_mine = false !== stripos( $babilim_pg_b12_error[1], 'babilim' ) || false !== stripos( $babilim_pg_b12_error[2], 'babilim' );
			babilim_pg_assert( ! $babilim_pg_b12_mine, 'a PHP error involving babilim was raised: ' . $babilim_pg_b12_error[1] );
		}
		if ( '' !== $babilim_pg_b12_log && is_readable( $babilim_pg_b12_log ) ) {
			$tail = (string) substr( (string) file_get_contents( $babilim_pg_b12_log ), $babilim_pg_b12_log_len );
			babilim_pg_assert( false === stripos( $tail, 'babilim' ), 'debug.log mentions babilim: ' . substr( $tail, 0, 300 ) );
		}
	}
);
