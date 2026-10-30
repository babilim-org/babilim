<?php
/**
 * The size limits of the service's v1 request schemas, in one place.
 *
 * The service rejects a request that goes over any of these with a 422,
 * so every value the plugin sends is checked against the matching
 * constant first. The numbers mirror the service's published request
 * schemas, and a unit test compares them with the pinned copies so the
 * two cannot drift apart unnoticed.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants plus one length helper; nothing here talks to WordPress.
 */
final class Babilim_Contract_Limits {

	/** `slug` (requests, feedback, inventory components and events). */
	public const SLUG = 200;

	/** `locale`, `site_locale`, and every entry of a locale list. */
	public const LOCALE = 35;

	/** `version` of a request or an inventory component. */
	public const VERSION = 64;

	/** `text_domain` and every `extra_domains` entry. */
	public const TEXT_DOMAIN = 200;

	/** Longest `extra_domains` / `bundled_locales` list of one component. */
	public const MAX_DOMAIN_LIST = 512;

	/** Most locale keys in one component's `installed` object. */
	public const MAX_INSTALLED = 256;

	/** `client_events[].code`. */
	public const EVENT_CODE = 100;

	/** `msgid`, `msgctxt`, `current_msgstr`, `suggested` and `note` of a feedback report. */
	public const FEEDBACK_TEXT = 4000;

	/** `screen_path` of a feedback report. */
	public const SCREEN_PATH = 500;

	/** `proof` of a verification. */
	public const PROOF = 200;

	/** Most entries of a registration's or inventory's `locales`. */
	public const MAX_LOCALES = 256;

	/** `home_url` of a registration. */
	public const HOME_URL = 2048;

	/** `wp_version` and `php_version` of a registration. */
	public const ENV_VERSION = 32;

	/**
	 * A value's length the way the schemas count it: in characters, not
	 * bytes.
	 *
	 * @param string $value Value to measure.
	 * @return int
	 */
	public static function length( string $value ): int {
		return mb_strlen( $value, 'UTF-8' );
	}

	/**
	 * Whether $value is at most $max characters long.
	 *
	 * @param string $value Value to measure.
	 * @param int    $max   Inclusive limit.
	 * @return bool
	 */
	public static function fits( string $value, int $max ): bool {
		return self::length( $value ) <= $max;
	}
}
