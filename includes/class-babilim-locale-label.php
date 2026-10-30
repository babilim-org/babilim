<?php
/**
 * A readable name for a WordPress locale code.
 *
 * The name comes from WordPress core's own list of available
 * translations, but only from its cache, and then from the names the
 * service's locale list gave the last time the settings screen fetched
 * it (kept in an option). Reading either never starts an HTTP request,
 * so drawing an admin page can never wait on the network. With neither
 * source, or a code neither knows, the code itself is the name.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locale code to display name.
 */
final class Babilim_Locale_Label {

	/**
	 * Site transient under which core keeps its list of available
	 * translations (see wp_get_available_translations()).
	 */
	private const CACHE_KEY = 'available_translations';

	/**
	 * Option holding code => native name from the service's locale list.
	 * Not autoloaded and never expires.
	 */
	public const OPTION_NAMES = 'babilim_locale_names';

	/**
	 * Code to native name, built on first use and kept for the rest of
	 * the request; null until then.
	 *
	 * @var array<string,string>|null
	 */
	private static $names = null;

	/**
	 * The display name of a locale: its native name when core's cached
	 * list has it, otherwise the code unchanged.
	 *
	 * @param string $locale Locale code such as de_DE.
	 * @return string
	 */
	public static function name( string $locale ): string {
		if ( 'en_US' === $locale ) {
			return __( 'English (United States)', 'babilim' );
		}
		if ( '' === $locale ) {
			return '';
		}
		if ( null === self::$names ) {
			self::$names = self::read_names();
		}
		return self::$names[ $locale ] ?? $locale;
	}

	/**
	 * Forgets the per-request map. Test-only.
	 */
	public static function reset_for_tests(): void {
		self::$names = null;
	}

	/**
	 * Language codes in one fixed order for every list on the screens:
	 * by code, which belongs to no writing system. The shown names do
	 * not decide the order.
	 *
	 * @param string[] $locales Language codes.
	 * @return string[]
	 */
	public static function sorted( array $locales ): array {
		$locales = array_values( array_unique( array_map( 'strval', $locales ) ) );
		sort( $locales, SORT_STRING );
		return $locales;
	}

	/**
	 * Keeps the native names of the service's locale list for later page
	 * loads. Entries without a code or a name are skipped.
	 *
	 * @param array $entries Entries of GET /v1/meta/locales.
	 */
	public static function remember( array $entries ): void {
		$names = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$code   = (string) ( $entry['wp_locale'] ?? '' );
			$native = $entry['native_name'] ?? '';
			if ( '' !== $code && is_string( $native ) && '' !== trim( $native ) ) {
				$names[ $code ] = $native;
			}
		}
		if ( array() !== $names ) {
			update_option( self::OPTION_NAMES, $names, false );
			self::$names = null;
		}
	}

	/**
	 * Reads core's cached list, then the stored service names for the
	 * codes core lacks, once; keeps only usable names.
	 *
	 * @return array<string,string>
	 */
	private static function read_names(): array {
		$names  = array();
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION_NAMES, array() ) : array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $code => $native ) {
				if ( is_string( $native ) && '' !== trim( $native ) ) {
					$names[ (string) $code ] = $native;
				}
			}
		}
		$list = function_exists( 'get_site_transient' ) ? get_site_transient( self::CACHE_KEY ) : false;
		if ( is_array( $list ) ) {
			foreach ( $list as $code => $entry ) {
				$native = is_array( $entry ) ? ( $entry['native_name'] ?? '' ) : '';
				if ( is_string( $native ) && '' !== trim( $native ) ) {
					$names[ (string) $code ] = $native;
				}
			}
		}
		return $names;
	}
}
