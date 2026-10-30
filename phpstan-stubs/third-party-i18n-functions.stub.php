<?php
/**
 * A third-party plugin's own function this plugin's code calls only
 * behind an injected existence check (never unconditionally) -- see
 * Babilim_Inventory::multilingual_plugin_locales(). Not part of
 * WordPress core, so tools/wordpress-stubs.php doesn't know it; this
 * lets PHPStan resolve the call instead of reporting it as an unknown
 * function.
 *
 * Scanned for symbols only (see phpstan.neon's scanFiles), never
 * analysed and never loaded at runtime -- exactly like
 * phpstan-stubs/wp-runtime-constants.stub.php.
 */

if ( ! function_exists( 'pll_languages_list' ) ) {
	/**
	 * Polylang's own documented API for listing active languages.
	 *
	 * @param array $args Options, e.g. array( 'fields' => 'locale' ).
	 * @return array
	 */
	function pll_languages_list( $args = array() ) {
		return array();
	}
}
