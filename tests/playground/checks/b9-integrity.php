<?php
/**
 * Tampering with an already-installed `.l10n.php` on disk (a corrupted
 * download, a compromised host, hand-editing) is caught by a real
 * `Babilim_Installer::verify()` sha256 mismatch, `degrade()` then removes
 * the served files (never the manifest history), and the very next
 * request's real translation-loading layer falls back cleanly -- no
 * fatal, no stale/garbled string served -- to whatever ranks next (here:
 * plain English, since this fixture has no wp.org pack or `custom/`
 * override).
 */

declare( strict_types=1 );

babilim_pg_run(
	'b9-integrity',
	'a real verify() catches a tampered .l10n.php; degrade() removes it; the front end falls back cleanly',
	static function () {
		$locale = 'ko_KR';
		$slug   = 'b9-plugin';

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename );

		$result = babilim_pg_install( 'plugin', $slug, $locale, '1.0.0', array( 'Integrity Check' => 'Integrity Translated' ) );
		babilim_pg_assert( $result->is_ok(), 'install() failed: ' . $result->message() );

		$before = babilim_pg_with_locale( $locale, static function () use ( $slug ) {
			// Reloadable: this domain is read again, after degrade(),
			// later in this same check -- a non-reloadable unload would
			// set WordPress core's own $l10n_unloaded flag and block
			// that second, real JIT reload from ever firing (see
			// b3-hooks-timing.php's own note on the same mechanism).
			unload_textdomain( $slug, true );
			load_plugin_textdomain( $slug, false, $slug . '/languages' );
			return __( 'Integrity Check', $slug );
		} );
		babilim_pg_assert( 'Integrity Translated' === $before, "baseline translation wrong before tampering: '{$before}'" );

		$fs        = new Babilim_Wp_Fs();
		$manifest  = new Babilim_Manifest( $fs );
		$installer = new Babilim_Installer(
			$fs,
			$manifest,
			array( Babilim::instance(), 'transform_mo_to_l10n_php' ),
			array( Babilim::instance(), 'parse_translation_file_entries' ),
			array( Babilim::instance(), 'unzip_package' )
		);

		$clean = $installer->verify( 'plugin', $slug, $locale );
		babilim_pg_assert( $clean->is_ok(), 'verify() reported a failure before any tampering: ' . $clean->message() );

		$served_php = Babilim_Paths::file( 'plugins', $slug, $locale, 'l10n.php' );
		babilim_pg_assert( is_readable( $served_php ), "served .l10n.php missing before tampering: {$served_php}" );
		file_put_contents( $served_php, "<?php\nreturn array( 'messages' => array( 'Integrity Check' => 'TAMPERED-VALUE' ) );\n" ); // Same shape, different bytes -- a real sha256 mismatch, not a parse failure.

		$tampered = $installer->verify( 'plugin', $slug, $locale );
		babilim_pg_assert( $tampered->is_error() && 'integrity_failed' === $tampered->code(), 'verify() did not detect the tampered file: ' . var_export( $tampered->code(), true ) );
		$mismatches = (array) $tampered->get( 'mismatches', array() );
		babilim_pg_assert( in_array( "plugins/{$slug}-{$locale}.l10n.php", $mismatches, true ), 'the tampered relative path is not listed in mismatches: ' . implode( ',', $mismatches ) );

		$degraded = $installer->degrade( 'plugin', $slug, $locale );
		babilim_pg_assert( $degraded->is_ok(), 'degrade() failed: ' . $degraded->message() );
		babilim_pg_assert( ! file_exists( $served_php ), 'degrade() left the tampered .l10n.php on disk' );
		babilim_pg_assert( ! file_exists( Babilim_Paths::file( 'plugins', $slug, $locale, 'mo' ) ), 'degrade() left the served .mo on disk' );

		$after = babilim_pg_with_locale( $locale, static function () use ( $slug ) {
			// Reloadable -- see the first occurrence's own note above.
			// load_plugin_textdomain()'s own return value is not asserted
			// -- see b3b-jit-no-file.php's own note; the real signal is
			// the actual translated content below.
			unload_textdomain( $slug, true );
			load_plugin_textdomain( $slug, false, $slug . '/languages' );
			return __( 'Integrity Check', $slug );
		} );
		babilim_pg_assert( 'Integrity Check' === $after, "expected a clean English fallback after degrade(), got '{$after}' (a stale/garbled value would be worse than a fatal)" );
	}
);
