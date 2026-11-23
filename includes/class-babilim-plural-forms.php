<?php
/**
 * Plural rules of the WordPress locales.
 *
 * Reads includes/plural-forms.php (generated from the GlotPress locale
 * registry) so the editor can write a correct `Plural-Forms` header for
 * any locale and know how many forms a plural string needs. A locale that
 * is not in the table gets the common two-form rule.
 *
 * @package Babilim
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locale to plural rule.
 */
final class Babilim_Plural_Forms {

	private const DEFAULT_HEADER = 'nplurals=2; plural=n != 1;';

	/**
	 * Locale to array( nplurals, expression ); null until first use.
	 *
	 * @var array<string,array{0:int,1:string}>|null
	 */
	private static $rules = null;

	/**
	 * The `Plural-Forms` header value for a locale.
	 *
	 * @param string $locale WordPress locale such as fr_FR.
	 * @return string
	 */
	public static function header( string $locale ): string {
		if ( null === self::$rules ) {
			self::$rules = require BABILIM_DIR . 'includes/plural-forms.php';
		}
		$rule = self::$rules[ $locale ] ?? null;
		return null === $rule ? self::DEFAULT_HEADER : 'nplurals=' . $rule[0] . '; plural=' . $rule[1] . ';';
	}

	/**
	 * The number of plural forms a `Plural-Forms` header declares.
	 *
	 * @param string $header Header value.
	 * @return int 1 to 6; 2 when the header has no usable count.
	 */
	public static function nplurals( string $header ): int {
		if ( preg_match( '/nplurals\s*=\s*(\d+)/', $header, $m ) ) {
			$n = (int) $m[1];
			if ( $n >= 1 && $n <= 6 ) {
				return $n;
			}
		}
		return 2;
	}

	/**
	 * What each plural form is for: the first three numbers (0 to 299) the
	 * rule maps to it, `, `-joined, with `…` when there are more. The editor's
	 * plural tabs use it instead of a language-specific name such as "few".
	 * 299 because Arabic's last form has only 100, 101 and 102 below 200.
	 *
	 * @param string $header A `Plural-Forms` header value.
	 * @return string[] One entry per form; array() when the rule cannot be read or core's evaluator is not loaded.
	 */
	public static function examples( string $header ): array {
		// Same pattern as WP_Translation_File::get_plural_expression_from_header(), the trailing `;` removed as core does.
		if ( ! class_exists( 'Plural_Forms' ) || 1 !== preg_match( '/^\s*nplurals\s*=\s*(\d+)\s*;\s+plural\s*=\s*(.+)$/', $header, $m ) ) {
			return array();
		}
		$n = (int) $m[1];
		if ( $n < 1 || $n > 6 ) {
			return array();
		}
		try {
			$rule = new Plural_Forms( rtrim( trim( $m[2] ), ';' ) );
			$seen = array_fill( 0, $n, array() );
			for ( $i = 0; $i < 300; $i++ ) {
				$form = (int) $rule->get( $i );
				if ( $form >= 0 && $form < $n ) {
					$seen[ $form ][] = $i;
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		$out = array();
		foreach ( $seen as $numbers ) {
			$out[] = implode( ', ', array_slice( $numbers, 0, 3 ) ) . ( count( $numbers ) > 3 ? '…' : '' );
		}
		return $out;
	}
}
