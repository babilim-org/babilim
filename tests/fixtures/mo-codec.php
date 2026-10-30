<?php
/**
 * An independent, minimal GNU MO reader for tests -- deliberately not
 * WordPress core's own MO class, so a test asserting on written bytes
 * never trusts the same code that (might) have written them. Shared
 * across test files via tests/bootstrap.php, rather than declared
 * inside one test file only -- see wp-locale-stubs.php's own docblock
 * for why that matters for running a single test file in isolation.
 *
 * @param string $bytes Raw MO file contents.
 * @return array<string,string|array<int,string>> key => msgstr (a plain
 *         string, or an array of plural forms when the msgstr contained
 *         embedded NUL bytes).
 */

if ( ! function_exists( 'babilim_test_read_mo' ) ) {
	function babilim_test_read_mo( string $bytes ): array {
		$header = unpack( 'Vmagic/Vrevision/Vn/Vo/Vt/Vhash_size/Vh', substr( $bytes, 0, 28 ) );
		if ( 0x950412de !== $header['magic'] ) {
			throw new RuntimeException( 'babilim_test_read_mo: bad magic number' );
		}

		$n = $header['n'];
		$o = $header['o'];
		$t = $header['t'];

		$result = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$orow = unpack( 'Vlen/Voff', substr( $bytes, $o + $i * 8, 8 ) );
			$key  = substr( $bytes, $orow['off'], $orow['len'] );

			$trow = unpack( 'Vlen/Voff', substr( $bytes, $t + $i * 8, 8 ) );
			$val  = substr( $bytes, $trow['off'], $trow['len'] );

			$result[ $key ] = ( '' !== $key && false !== strpos( $val, "\0" ) ) ? explode( "\0", $val ) : $val;
		}

		return $result;
	}
}
