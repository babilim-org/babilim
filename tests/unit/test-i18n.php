<?php
/**
 * Static checks on the plugin's own translatable strings: every gettext
 * call uses the `babilim` text domain and a literal string, no
 * translatable fragment is concatenated to another, a string with
 * placeholders carries a translators comment, and no wording favours
 * one locale.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_I18n extends TestCase {

	/**
	 * Gettext functions => index of the text-domain argument.
	 *
	 * @var array<string,int>
	 */
	private const FUNCTIONS = array(
		'__'            => 1,
		'_e'            => 1,
		'esc_html__'    => 1,
		'esc_html_e'    => 1,
		'esc_attr__'    => 1,
		'esc_attr_e'    => 1,
		'_x'            => 2,
		'esc_html_x'    => 2,
		'esc_attr_x'    => 2,
		'_n'            => 3,
		'_nx'           => 4,
	);

	/**
	 * Every gettext call in the plugin's own PHP files.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function calls(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge(
			array( $root . '/babilim.php', $root . '/uninstall.php' ),
			glob( $root . '/includes/*.php' ) ?: array(),
			glob( $root . '/admin/*.php' ) ?: array()
		);
		$calls = array();
		foreach ( $files as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			$count  = count( $tokens );
			$last_comment = '';
			for ( $i = 0; $i < $count; $i++ ) {
				$t = $tokens[ $i ];
				if ( is_array( $t ) && T_COMMENT === $t[0] ) {
					$last_comment = $t[1];
					continue;
				}
				if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( self::FUNCTIONS[ $t[1] ] ) ) {
					continue;
				}
				$before = $i - 1;
				while ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_WHITESPACE ), true ) ) {
					--$before;
				}
				if ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
					continue; // A method or a declaration, not a call.
				}
				$j = $i + 1;
				while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				if ( '(' !== $tokens[ $j ] ) {
					continue;
				}
				// Collect the top-level arguments as [type-or-char, text] pairs.
				$depth = 0;
				$args  = array( array() );
				for ( $k = $j; $k < $count; $k++ ) {
					$tok  = $tokens[ $k ];
					$char = is_array( $tok ) ? '' : $tok;
					if ( '(' === $char ) {
						++$depth;
						if ( 1 === $depth ) {
							continue;
						}
					} elseif ( ')' === $char ) {
						--$depth;
						if ( 0 === $depth ) {
							break;
						}
					} elseif ( ',' === $char && 1 === $depth ) {
						$args[] = array();
						continue;
					}
					if ( is_array( $tok ) && T_WHITESPACE === $tok[0] ) {
						continue;
					}
					$args[ count( $args ) - 1 ][] = is_array( $tok ) ? array( token_name( $tok[0] ), $tok[1] ) : array( $tok, $tok );
				}
				// The next few non-whitespace tokens after the call, to
				// spot concatenation.
				$after = array();
				for ( $n = $k + 1; $n < $count && count( $after ) < 3; $n++ ) {
					$tok = $tokens[ $n ];
					if ( is_array( $tok ) && T_WHITESPACE === $tok[0] ) {
						continue;
					}
					$after[] = is_array( $tok ) ? array( token_name( $tok[0] ), $tok[1] ) : array( $tok, $tok );
				}
				$calls[] = array(
					'file'    => basename( $file ),
					'line'    => $t[2],
					'func'    => $t[1],
					'args'    => $args,
					'comment' => $last_comment,
					'after'   => $after,
				);
				$last_comment = '';
			}
		}
		return $calls;
	}

	/**
	 * The literal text of one argument, or null if it isn't one plain
	 * string literal.
	 *
	 * @param array<int,array<int,string>> $arg Argument tokens.
	 */
	private function literal( array $arg ): ?string {
		if ( 1 !== count( $arg ) || 'T_CONSTANT_ENCAPSED_STRING' !== $arg[0][0] ) {
			return null;
		}
		$raw = $arg[0][1];
		return substr( $raw, 1, -1 );
	}

	public function test_the_scan_finds_the_plugins_strings(): void {
		$this->assertGreaterThan( 100, count( $this->calls() ) );
	}

	public function test_every_gettext_call_uses_literal_strings_and_the_babilim_text_domain(): void {
		$problems = array();
		foreach ( $this->calls() as $call ) {
			$where = "{$call['file']}:{$call['line']} {$call['func']}()";
			if ( null === $this->literal( $call['args'][0] ) ) {
				$problems[] = "{$where}: the text must be one string literal";
			}
			$domain = $this->literal( $call['args'][ self::FUNCTIONS[ $call['func'] ] ] ?? array() );
			if ( 'babilim' !== $domain ) {
				$problems[] = "{$where}: the text domain must be the literal 'babilim'";
			}
		}
		$this->assertSame( array(), $problems );
	}

	public function test_no_translatable_string_is_concatenated_to_another(): void {
		$problems = array();
		foreach ( $this->calls() as $call ) {
			$after = $call['after'];
			if ( '.' !== ( $after[0][1] ?? '' ) ) {
				continue;
			}
			$operand = $after[1] ?? array( '', '' );
			$text    = 'T_CONSTANT_ENCAPSED_STRING' === $operand[0] ? substr( $operand[1], 1, -1 ) : null;
			// Another gettext call directly after ".", or a literal that
			// starts with a space (a sentence continued by more text or a
			// link) -- either way the sentence is built from fragments.
			if ( ( 'T_STRING' === $operand[0] && isset( self::FUNCTIONS[ $operand[1] ] ) )
				|| ( null !== $text && 1 === preg_match( '/^\s/', $text ) ) ) {
				$problems[] = "{$call['file']}:{$call['line']}";
			}
		}
		$this->assertSame( array(), $problems, 'a sentence must be one string with placeholders, not fragments joined with "."' );
	}

	public function test_every_placeholder_string_has_a_translators_comment(): void {
		$problems = array();
		foreach ( $this->calls() as $call ) {
			$text = $this->literal( $call['args'][0] );
			if ( null === $text || 1 !== preg_match( '/%(?:\d+\$)?[sdfF]/', $text ) ) {
				continue;
			}
			if ( false === strpos( $call['comment'], 'translators:' ) ) {
				$problems[] = "{$call['file']}:{$call['line']}: {$text}";
			}
		}
		$this->assertSame( array(), $problems, 'a string with placeholders needs a /* translators: ... */ comment' );
	}

	public function test_no_wording_names_one_locale_as_the_example(): void {
		$problems = array();
		foreach ( $this->calls() as $call ) {
			$text = $this->literal( $call['args'][0] );
			// "ll_CC" is the format placeholder, not a real locale.
			if ( null !== $text && 1 === preg_match( '/\b(?!ll_CC\b)[a-z]{2,3}_[A-Z]{2}\b/', $text ) ) {
				$problems[] = "{$call['file']}:{$call['line']}: {$text}";
			}
		}
		$this->assertSame( array(), $problems, 'a UI string must not use a real locale code as its example' );
	}

	// -- Every Babilim_Result::error()/new WP_Error() message must be
	// translated (or forwarded from something that already is), never a
	// bare string literal. --------------------------------------------------

	/**
	 * Codes allowed to carry a bare, untranslated message: none. Every
	 * message this plugin writes reaches an admin screen or WordPress
	 * core's own translation-update UI sooner or later, so this
	 * plugin's own policy is to translate all of them rather than
	 * maintain a list of which ones are supposedly safe to leave in
	 * English -- see test_every_result_error_and_wp_error_message_is_
	 * translated_or_forwarded() below, which enforces this with no
	 * exceptions.
	 */

	/**
	 * Every `Babilim_Result::error( $code, $message, ... )` and
	 * `new WP_Error( $code, $message, ... )` call in the plugin's own
	 * PHP files, with the raw token list for the $message argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function error_calls(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge(
			array( $root . '/babilim.php', $root . '/uninstall.php' ),
			glob( $root . '/includes/*.php' ) ?: array(),
			glob( $root . '/admin/*.php' ) ?: array()
		);
		$calls = array();
		foreach ( $files as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$t = $tokens[ $i ];
				$is_result_error = is_array( $t ) && T_STRING === $t[0] && 'error' === $t[1]
					&& is_array( $tokens[ $i - 1 ] ?? null ) && T_DOUBLE_COLON === $tokens[ $i - 1 ][0]
					&& is_array( $tokens[ $i - 2 ] ?? null ) && T_STRING === $tokens[ $i - 2 ][0] && 'Babilim_Result' === $tokens[ $i - 2 ][1];
				$is_wp_error = is_array( $t ) && T_STRING === $t[0] && 'WP_Error' === $t[1]
					&& is_array( $tokens[ $i - 1 ] ?? null ) && T_WHITESPACE === $tokens[ $i - 1 ][0]
					&& is_array( $tokens[ $i - 2 ] ?? null ) && T_NEW === $tokens[ $i - 2 ][0];
				if ( ! $is_result_error && ! $is_wp_error ) {
					continue;
				}
				$j = $i + 1;
				while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				if ( '(' !== $tokens[ $j ] ) {
					continue;
				}
				$depth = 0;
				$args  = array( array() );
				for ( $k = $j; $k < $count; $k++ ) {
					$tok  = $tokens[ $k ];
					$char = is_array( $tok ) ? '' : $tok;
					if ( '(' === $char ) {
						++$depth;
						if ( 1 === $depth ) {
							continue;
						}
					} elseif ( ')' === $char ) {
						--$depth;
						if ( 0 === $depth ) {
							break;
						}
					} elseif ( ',' === $char && 1 === $depth ) {
						$args[] = array();
						continue;
					}
					if ( is_array( $tok ) && in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
						continue; // Comments (e.g. a translators note) carry no meaning for this structural check.
					}
					$args[ count( $args ) - 1 ][] = is_array( $tok ) ? array( token_name( $tok[0] ), $tok[1] ) : array( $tok, $tok );
				}
				$calls[] = array(
					'file' => basename( $file ),
					'line' => $t[2],
					'kind' => $is_result_error ? 'Babilim_Result::error' : 'new WP_Error',
					'code' => $this->literal( $args[0] ),
					'args' => $args,
				);
			}
		}
		return $calls;
	}

	public function test_the_error_call_scan_finds_calls(): void {
		$this->assertGreaterThan( 60, count( $this->error_calls() ) );
	}

	public function test_every_result_error_and_wp_error_message_is_translated_or_forwarded(): void {
		$problems = array();
		foreach ( $this->error_calls() as $call ) {
			$message_arg = $call['args'][1] ?? array();
			if ( array() === $message_arg ) {
				continue; // No message argument at all.
			}
			$first                    = $message_arg[0] ?? array( '', '' );
			$gettext_value_functions  = array( '__', '_x', '_n', '_nx' );
			// Wrapped: a bare __( ... )/_x( ... )/_n( ... )/_nx( ... ) call.
			$wrapped = 'T_STRING' === $first[0] && in_array( $first[1], $gettext_value_functions, true );
			if ( ! $wrapped && 'T_STRING' === $first[0] && 'sprintf' === $first[1] ) {
				// sprintf( <this> , ... ) -- its own format-string argument
				// must itself be a gettext call, or this is just sprintf()
				// interpolating a bare, never-translated literal (message_arg
				// is the flat token list [sprintf, (, <first-arg-token>, ...],
				// so index 2 is the first token of sprintf's own first arg).
				$inner   = $message_arg[2] ?? array( '', '' );
				$wrapped = 'T_STRING' === $inner[0] && in_array( $inner[1], $gettext_value_functions, true );
			}
			if ( $wrapped ) {
				continue;
			}
			// Forwarded: no raw string-literal token anywhere in the
			// argument (a variable, a method call chain, etc. -- already
			// either translated at its own source or genuinely not this
			// plugin's own literal to translate, e.g. $e->getMessage()).
			$has_literal = false;
			foreach ( $message_arg as $tok ) {
				if ( in_array( $tok[0], array( 'T_CONSTANT_ENCAPSED_STRING', 'T_ENCAPSED_AND_WHITESPACE' ), true ) ) {
					$has_literal = true;
					break;
				}
			}
			if ( ! $has_literal ) {
				continue;
			}
			$problems[] = "{$call['file']}:{$call['line']} {$call['kind']}( '{$call['code']}', ... ): message must be translated with __()/sprintf( __() )";
		}
		$this->assertSame( array(), $problems );
	}

	/**
	 * error_calls() above only inspects the literal argument tokens at
	 * a Babilim_Result::error()/new WP_Error() call site -- it can't see
	 * back into how a variable it's handed (e.g. `$message`) was itself
	 * built earlier. class-babilim-api-client.php's HTTP-status fallback
	 * builds its message that way (`$message = ... ? $message : sprintf(
	 * __( ... ) )`), so a regression there (reverting to a bare string
	 * literal) would be invisible to error_calls(). This scans every
	 * "...message..."-named variable's ternary assignment directly for
	 * a bare string-literal fallback, across the same files
	 * error_calls() covers.
	 */
	public function test_message_variable_ternary_fallbacks_are_translated(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge(
			array( $root . '/babilim.php', $root . '/uninstall.php' ),
			glob( $root . '/includes/*.php' ) ?: array(),
			glob( $root . '/admin/*.php' ) ?: array()
		);
		$problems = array();
		foreach ( $files as $file ) {
			foreach ( file( $file ) ?: array() as $number => $line ) {
				// The lookahead excludes a wrapped fallback (sprintf( __(
				// .../ __( ...); the trailing ['"][^'"] requires the
				// literal to hold at least one character, so an empty-
				// string default ('' -- a "no value yet" sentinel, never
				// itself displayed as a message) isn't a false positive.
				if ( 1 !== preg_match( '/\$\w*[Mm]essage\w*\s*=\s*.*\?\s*.*:\s*(?!sprintf\(\s*__\(|__\()[\'"][^\'"]/', $line ) ) {
					continue;
				}
				$problems[] = basename( $file ) . ':' . ( $number + 1 );
			}
		}
		$this->assertSame( array(), $problems, 'a "...message..." variable\'s ternary fallback must be translated with __()/sprintf( __() ), not a bare string literal' );
	}
}
