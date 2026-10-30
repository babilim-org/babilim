<?php
/**
 * Whether the site owner has agreed to use the Babilim service -- no
 * request to the service is made before this is true -- and the
 * settings screen's own item-by-item disclosure of exactly what gets
 * sent once they do.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the plugin's consent flag and builds the settings-screen
 * disclosure of what this plugin ever sends.
 */
final class Babilim_Consent {

	/**
	 * Whether the site owner has consented. Same option
	 * Babilim_Site::OPTION_CONSENT reads -- this class never redefines
	 * the name itself.
	 *
	 * @return bool
	 */
	public function granted(): bool {
		return (bool) get_option( Babilim_Site::OPTION_CONSENT, false );
	}

	/**
	 * Whether the site owner has consented, read straight from the
	 * options table. get_option() can answer from this process's object
	 * cache, which a revocation made by another request has not reached;
	 * the check that guards every outbound request must not be fooled by
	 * that. No row, a database error, or no database at all means "not
	 * consented".
	 *
	 * @return bool
	 */
	public function granted_db(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the point is to bypass the object cache.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, Babilim_Site::OPTION_CONSENT ) );
		if ( ! is_string( $raw ) ) {
			return false;
		}
		return in_array( maybe_unserialize( $raw ), array( '1', 1, true ), true );
	}

	/**
	 * Every field this plugin ever sends to the service, across
	 * registration (`POST /v1/sites`) and the twice-daily sync
	 * (`POST /v1/sites/{id}/inventory`) -- for the settings screen to
	 * disclose item by item before consent is given. Field order matches
	 * roughly how a site owner would want to read it: identity fields
	 * first, then what's reported about their installed components, then
	 * the diagnostic events.
	 *
	 * @param Babilim_Inventory $inventory This site's component/locale inventory.
	 * @return array<int,array{field:string,value:mixed,description:string}>
	 */
	public function payload_preview( Babilim_Inventory $inventory ): array {
		return array(
			array(
				'field'       => 'home_url',
				'value'       => function_exists( 'home_url' ) ? (string) home_url() : '',
				'description' => __( 'This site\'s URL, so the service can verify you own it.', 'babilim' ),
			),
			array(
				'field'       => 'wp_version',
				'value'       => (string) ( $GLOBALS['wp_version'] ?? '' ),
				'description' => __( 'Your WordPress version, to serve compatible translation files.', 'babilim' ),
			),
			array(
				'field'       => 'php_version',
				'value'       => PHP_VERSION,
				'description' => __( 'Your PHP version, for compatibility.', 'babilim' ),
			),
			array(
				'field'       => 'plugin_version',
				'value'       => defined( 'BABILIM_VERSION' ) ? BABILIM_VERSION : '0.0.0',
				'description' => __( 'This plugin\'s own version, so the service can tell you to update it if it\'s too old to talk to.', 'babilim' ),
			),
			array(
				'field'       => 'site_locale',
				'value'       => function_exists( 'get_locale' ) ? (string) get_locale() : '',
				'description' => __( 'This site\'s configured locale.', 'babilim' ),
			),
			array(
				'field'       => 'locales',
				'value'       => $inventory->locales(),
				'description' => __( 'Every locale in use on this site (site locale, installed language packs, user locales, and any you add manually). No locale is treated differently from another.', 'babilim' ),
			),
			array(
				'field'       => 'install_pub',
				'value'       => __( '(a public key generated for this site)', 'babilim' ),
				'description' => __( 'This site\'s install public key, used to prove requests genuinely come from this site. Only the public half is ever sent.', 'babilim' ),
			),
			array(
				'field'       => 'components',
				'value'       => $inventory->request_components(),
				'description' => __( 'Every installed plugin/theme: its slug, version, text domain(s), whether it\'s a wp.org listing, its own bundled locales, and which locales you\'ve already installed a Babilim translation for.', 'babilim' ),
			),
			array(
				'field'       => 'source package',
				'value'       => '',
				'description' => __( 'Only when you confirm an upload for a plugin or theme that is not on WordPress.org: that component\'s entire directory, as a zip.', 'babilim' ),
			),
			array(
				'field'       => 'client_events',
				'value'       => __( '(generic codes and coordinates only)', 'babilim' ),
				'description' => __( 'Up to 20 pending diagnostic events (e.g. a file that failed its integrity check), each just a code, component, and locale, and never any string content or translated text.', 'babilim' ),
			),
		);
	}
}
