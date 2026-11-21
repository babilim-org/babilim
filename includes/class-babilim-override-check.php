<?php
/**
 * Checks an edited translation before it is saved.
 *
 * A translation the owner types goes into a file WordPress prints on the
 * front end, so it must not break `printf()`-style formatting or bring in
 * markup. Both rules are closed: anything not copied from the original is
 * refused, instead of guessing how a browser would read it.
 *
 * - Placeholders: each one, numbered or not, must be one of the original's
 *   for the same argument, with the same flags, padding, width, precision
 *   and type. The text must still format with the original's arguments.
 * - Markup: a markup start is `<` then a letter, `/`, `!`, `?` or `%` (a
 *   placeholder's value can finish it). The original is cut into pieces,
 *   each from a markup start to the next `>` outside a quoted value (or to
 *   the end of the string), by a plain scan. Each translation form must
 *   hold exactly the pieces of its own original (the first form msgid,
 *   every other form msgid_plural), byte for byte and each exactly as
 *   often, in any order; with them taken out, no markup start and no `<`
 *   at the end may be left. A piece the original leaves unfinished (the
 *   page finishes it) must end the translation. Pieces are compared with
 *   every placeholder written as its argument number, so a value cannot
 *   move into another tag or attribute.
 *
 * - A string whose original holds a comment or an element whose content
 *   a plain scan cannot read (script, style, textarea, title, svg, math,
 *   ...) can only be saved exactly as its original.
 *
 * The cost: text inside a tag (a title or alt value, a link) cannot be
 * translated; only text between tags and the order of whole tags can
 * change.
 *
 * @package Babilim
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save-time checks for one string.
 */
final class Babilim_Override_Check {

	/** Placeholder (or `%%`): 1 = argument number, 2 = flags and padding in any order, width, precision and type. No space flag, so `50% de` is not one. Possessive, so a long run of flags cannot backtrack until PCRE gives up. */
	private const PLACEHOLDER = '/%%|%(?:(\d+)\$)?((?:[+\-0]|\'.)*+\d*(?:\.\d+)?[bcdeEfFgGosuxXl])/s';

	/** A markup start. */
	private const START = '#<[a-zA-Z/!?%]#';

	/** Markup whose content a plain scan cannot read: a string holding one is kept as it is. */
	private const OPAQUE = '#<(?:script|style|textarea|title|xmp|iframe|noembed|noframes|noscript|plaintext|svg|math|!--)#i';

	/** HTML whitespace. */
	private const WS = " \t\n\f\r";

	/**
	 * Problems of a translation; empty when it can be saved.
	 *
	 * @param string   $msgid        Original text.
	 * @param string   $msgid_plural Original plural text; '' when the string is not plural.
	 * @param string[] $msgstr       Translation forms (exactly one when not plural).
	 * @param int      $nplurals     Plural forms the locale needs.
	 * @return array<int,array{form:int,code:string,message:string}>
	 */
	public static function problems( string $msgid, string $msgid_plural, array $msgstr, int $nplurals ): array {
		$msgstr = array_values( $msgstr );
		$plural = '' !== $msgid_plural;
		$want   = $plural ? $nplurals : 1;
		$out    = array();

		if ( count( $msgstr ) !== $want ) {
			$out[] = self::problem( min( count( $msgstr ), $want ), 'empty', __( 'This translation does not have the number of forms the language needs.', 'babilim' ) );
		}

		try {
			return self::check( $msgid, $msgid_plural, $msgstr, $plural, $want, $out );
		} catch ( UnexpectedValueException $e ) {
			// A pattern PCRE gave up on proves nothing: refuse rather than read its failure as "no match".
			$out[] = self::problem( 0, 'tags', __( 'The HTML tags must match the original: same tags, each opened and closed as in the original.', 'babilim' ) );
			return $out;
		}
	}

	/**
	 * The checks of problems(), every PCRE call through ok().
	 *
	 * @param string                                                  $msgid        Original text.
	 * @param string                                                  $msgid_plural Original plural text.
	 * @param string[]                                                $msgstr       Translation forms.
	 * @param bool                                                    $plural       Whether the string is plural.
	 * @param int                                                     $want         Forms needed.
	 * @param array<int,array{form:int,code:string,message:string}> $out          Problems so far.
	 * @return array<int,array{form:int,code:string,message:string}>
	 */
	private static function check( string $msgid, string $msgid_plural, array $msgstr, bool $plural, int $want, array $out ): array {
		$allowed = array();
		$is_fmt  = false;
		$pieces  = array(); // Per original (msgid, then msgid_plural): finished piece => times it is used, longest first.
		$tails   = array(); // Unfinished tail of each original, '' when none.
		foreach ( $plural ? array( $msgid, $msgid_plural ) : array( $msgid ) as $source ) {
			list( $norm, $found ) = self::placeholders( $source );
			$allowed             += $found;
			// A string with a placeholder or `%%` is a format string: a lone `%` in it breaks on PHP 8.
			$is_fmt              = $is_fmt || (bool) self::ok( preg_match( self::PLACEHOLDER, $source ) );
			list( $done, $tail ) = self::pieces( $norm );
			$tails[]             = $tail;
			$count               = array_count_values( $done );
			// Longest first, so a piece inside another is not taken out of it.
			uksort(
				$count,
				static function ( $a, $b ): int {
					return strlen( (string) $b ) - strlen( (string) $a );
				}
			);
			$pieces[] = $count;
		}
		$frozen = (bool) self::ok( preg_match( self::OPAQUE, $msgid . "\0" . $msgid_plural ) );
		$needed = $allowed ? max( array_map( 'intval', array_keys( $allowed ) ) ) : 0;

		foreach ( $msgstr as $i => $text ) {
			$text = (string) $text;
			if ( '' === $text ) {
				if ( count( $msgstr ) === $want ) {
					$out[] = self::problem( $i, 'empty', __( 'This translation is empty.', 'babilim' ) );
				}
				continue;
			}
			if ( ! preg_match( '//u', $text ) || self::ok( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text ) ) ) {
				$out[] = self::problem( $i, 'text', __( 'The text has invalid characters or control characters.', 'babilim' ) );
				continue;
			}
			$source = $plural && $i > 0 ? $msgid_plural : $msgid;
			if ( $frozen && $text !== $source ) {
				$out[] = self::problem( $i, 'tags', __( 'The HTML tags must match the original: same tags, each opened and closed as in the original.', 'babilim' ) );
				continue;
			}
			list( $norm, $found ) = self::placeholders( $text );
			$bad                  = self::placeholder_problem( $text, $found, $allowed, $needed, $is_fmt, $i );
			if ( null !== $bad ) {
				$out[] = $bad;
			}
			// The first form stands for msgid, every other form for msgid_plural.
			$k = $plural && $i > 0 ? 1 : 0;
			if ( ! self::markup_copied( $norm, $pieces[ $k ], $tails[ $k ] ) ) {
				$out[] = self::problem( $i, 'tags', __( 'The HTML tags must match the original: same tags, each opened and closed as in the original.', 'babilim' ) );
			}
		}
		return $out;
	}

	/**
	 * A PCRE result, or an exception when PCRE failed (null or false).
	 *
	 * @param mixed $result What a preg_* call returned.
	 * @return mixed The same result.
	 * @throws UnexpectedValueException When the call failed.
	 */
	private static function ok( $result ) {
		if ( null === $result || false === $result ) {
			throw new UnexpectedValueException( 'PCRE error ' . (int) preg_last_error() );
		}
		return $result;
	}

	/**
	 * @param int    $form    Form index.
	 * @param string $code    Problem code.
	 * @param string $message Translated message.
	 * @return array{form:int,code:string,message:string}
	 */
	private static function problem( int $form, string $code, string $message ): array {
		return array(
			'form'    => $form,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * The string with every placeholder written as `%N$spec`, and its
	 * placeholders as "N$spec" => the text as written. An unnumbered one
	 * takes the next unnumbered position, as in PHP.
	 *
	 * @param string $s Text.
	 * @return array{0:string,1:array<string,string>}
	 */
	private static function placeholders( string $s ): array {
		$found = array();
		$next  = 1;
		$norm  = (string) self::ok( preg_replace_callback(
			self::PLACEHOLDER,
			static function ( array $m ) use ( &$found, &$next ): string {
				if ( '%%' === $m[0] ) {
					return '%%';
				}
				$key           = ( (int) $m[1] ?: $next++ ) . '$' . $m[2]; // Argument numbers start at 1; an unmatched group is ''.
				$found[ $key ] = $m[0];
				return '%' . $key;
			},
			$s
		) );
		return array( $norm, $found );
	}

	/**
	 * @param string               $text    One translation form.
	 * @param array<string,string> $found   Its placeholders.
	 * @param array<string,string> $allowed Placeholders of the original.
	 * @param int                  $needed  Arguments the original takes.
	 * @param bool                 $is_fmt  Whether the original is a format string.
	 * @param int                  $form    Form index.
	 * @return array{form:int,code:string,message:string}|null
	 */
	private static function placeholder_problem( string $text, array $found, array $allowed, int $needed, bool $is_fmt, int $form ): ?array {
		$extra = array_diff_key( $found, $allowed );
		if ( $extra ) {
			return self::problem(
				$form,
				'placeholder',
				sprintf(
					/* translators: %s: the offending text, such as %d or %s */
					__( 'The translation has %s, which the original does not. Keep the placeholders of the original; to write a plain percent sign next to a letter, add a space or write %%%%.', 'babilim' ),
					implode( ' ', array_unique( $extra ) )
				)
			);
		}
		// Engine-independent: PHP 7.4 ignores a stray `%` that PHP 8 refuses, so drop every placeholder and `%%` and see whether a `%` is left.
		if ( $is_fmt && ( false !== strpos( (string) self::ok( preg_replace( self::PLACEHOLDER, '', $text ) ), '%' ) || ! self::formattable( $text, $needed ) ) ) {
			return self::problem( $form, 'placeholder', __( 'The translation has a lone percent sign that would break the text. Write 100%% for a literal percent sign.', 'babilim' ) );
		}
		return null;
	}

	/**
	 * Whether the text formats with the arguments the original takes. PHP 8
	 * throws (ArgumentCountError, ValueError); PHP 7.4 warns and returns
	 * false. Both mean no.
	 *
	 * @param string $text  Format string.
	 * @param int    $count Arguments to pass.
	 * @return bool
	 */
	private static function formattable( string $text, int $count ): bool {
		$failed = false;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- keeps the PHP 7.4 warning from leaking.
		set_error_handler(
			static function () use ( &$failed ) {
				$failed = true;
				return true;
			}
		);
		try {
			$formatted = vsprintf( $text, array_fill( 0, $count, 1 ) ); // PHP 7.4: the warning (and a false result) lands in the handler above.
			unset( $formatted );
		} catch ( \Throwable $e ) {
			$failed = true;
		} finally {
			restore_error_handler();
		}
		return ! $failed;
	}

	/**
	 * Whether a translation form holds exactly its original's markup: the
	 * original's tail at the end, each finished piece exactly as often as in
	 * the original, and, with those taken out, no markup start and no `<` at
	 * the end.
	 *
	 * @param string            $text   Translation form, placeholders numbered.
	 * @param array<string,int> $pieces Finished pieces of its original, longest first => times used.
	 * @param string            $tail   Unfinished tail of the form's original; '' when none.
	 */
	private static function markup_copied( string $text, array $pieces, string $tail ): bool {
		if ( '' !== $tail ) {
			if ( substr( $text, -strlen( $tail ) ) !== $tail ) {
				return false;
			}
			$text = substr( $text, 0, -strlen( $tail ) );
		}
		foreach ( $pieces as $piece => $uses ) {
			// A saved text has no NUL, so the text on both sides of a piece cannot join into a markup start.
			$text = (string) self::ok( preg_replace( '/' . preg_quote( (string) $piece, '/' ) . '/', "\0", $text, $uses, $taken ) );
			if ( $taken !== $uses ) {
				return false; // A piece of the original is missing; one used more often leaves a markup start below.
			}
		}
		return ! self::ok( preg_match( self::START, $text ) ) && '<' !== substr( $text, -1 );
	}

	/**
	 * The markup pieces of a string, in order: each runs from a markup start
	 * to where HTML ends it, or to the end of the string.
	 *
	 * @param string $s Text, placeholders numbered.
	 * @return array{0:string[],1:string} Finished pieces, and the unfinished last one ('' when none).
	 */
	private static function pieces( string $s ): array {
		$done = array();
		for ( $at = 0; self::ok( preg_match( self::START, $s, $m, PREG_OFFSET_CAPTURE, $at ) ); $at = $end ) {
			$start = $m[0][1];
			$end   = self::piece_end( $s, $start );
			if ( null === $end ) {
				return array( $done, substr( $s, $start ) );
			}
			$done[] = substr( $s, $start, $end - $start );
		}
		return array( $done, '' );
	}

	/**
	 * Where the markup starting at `$at` ends (the offset after its `>`):
	 * the first `>` not inside a quoted attribute value; null when the
	 * string ends first. A quote opens a value only right after `=`.
	 *
	 * @param string $s  Text.
	 * @param int    $at Offset of the `<`.
	 */
	private static function piece_end( string $s, int $at ): ?int {
		for ( $i = $at + 1, $n = strlen( $s ); $i < $n; ++$i ) {
			if ( '>' === $s[ $i ] ) {
				return $i + 1;
			}
			if ( ( '"' === $s[ $i ] || "'" === $s[ $i ] ) && '=' === substr( rtrim( substr( $s, $at, $i - $at ), self::WS ), -1 ) ) {
				$q = strpos( $s, $s[ $i ], $i + 1 );
				if ( false === $q ) {
					return null;
				}
				$i = $q;
			}
		}
		return null;
	}
}
