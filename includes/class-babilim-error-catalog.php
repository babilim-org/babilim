<?php
/**
 * The closed set of values an error report may carry.
 *
 * The lists mirror the pinned contract's error catalog, and a unit test
 * compares them with the pinned copy so the two cannot drift apart. A
 * report whose code, action or origin is not listed here is never
 * recorded or sent.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants plus one membership check; nothing here talks to WordPress.
 */
final class Babilim_Error_Catalog {

	public const ERROR_CODES = array( 'download_failed', 'verify_failed', 'install_failed', 'upload_failed', 'load_failed' );

	public const ACTIONS = array( 'sync', 'download', 'install', 'rollback', 'upload', 'load', 'rebuild_index' );

	public const ORIGIN_IDS = array(
		'site.sync',
		'site.verify_installed',
		'installer.stage',
		'installer.commit',
		'upload.part',
		'upload.complete',
		'loader.translation_file',
		'loader.script_translations',
		'default_index.rebuild',
	);

	/**
	 * Whether all three values are listed in the catalog.
	 *
	 * @param string $error_code Error code.
	 * @param string $action     Action being performed.
	 * @param string $origin_id  Where in the plugin it happened.
	 * @return bool
	 */
	public static function is_valid( string $error_code, string $action, string $origin_id ): bool {
		return in_array( $error_code, self::ERROR_CODES, true )
			&& in_array( $action, self::ACTIONS, true )
			&& in_array( $origin_id, self::ORIGIN_IDS, true );
	}
}
