<?php
/**
 * get_locale()/get_available_languages() stand-ins -- two WordPress core
 * symbols tests/bootstrap.php deliberately doesn't stub (see
 * Babilim_Inventory's own class docblock), but that several test files
 * (Babilim_Inventory, Babilim_Site, Babilim_Consent, Babilim_Admin, ...)
 * all need available regardless of which one PHPUnit happens to load
 * first. Shared here, loaded once by tests/bootstrap.php, rather than
 * left to whichever individual test file's own guarded declaration
 * happens to run first -- a test file that never loads is undefined
 * when a different test file is run in isolation.
 *
 * Both guarded by function_exists() the same way the individual test
 * files' own (now-redundant, harmless) declarations already are.
 */

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return $GLOBALS['babilim_test_site_locale'] ?? 'en_US';
	}
}

if ( ! function_exists( 'get_available_languages' ) ) {
	function get_available_languages( $dir = null ) {
		return $GLOBALS['babilim_test_available_languages'] ?? array();
	}
}
