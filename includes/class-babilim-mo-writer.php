<?php
/**
 * A minimal GNU MO (compiled gettext) writer, used only for the merged
 * `default` domain index -- everywhere else, a `.mo` is downloaded from
 * the service, never generated here.
 *
 * Produces the plain GNU MO binary layout: magic number, revision 0,
 * an originals table, a translations table, and no hash table (size 0 --
 * the hash table exists as a lookup optimization for C `gettext()`
 * callers; nothing that reads these files here or in WordPress core
 * needs it, so it's simply omitted rather than computed). This is not a
 * general-purpose MO writer -- no charset conversion, no hash table, no
 * revision-1 "extra plural" support.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes entries + headers to a raw GNU MO byte string.
 */
final class Babilim_Mo_Writer {

	/**
	 * The little-endian GNU MO magic number.
	 *
	 * @var int
	 */
	private const MAGIC = 0x950412de;

	/**
	 * The fixed-size MO header: 7 little-endian uint32 fields (magic,
	 * revision, string count, originals-table offset, translations-table
	 * offset, hash-table size, hash-table offset).
	 *
	 * @var int
	 */
	private const HEADER_SIZE = 28;

	/**
	 * Builds a complete MO file's bytes.
	 *
	 * @param array<string,string|array<int,string>> $entries Keyed by
	 *        `{msgctxt}\x04{msgid}` (or bare `{msgid}` with no context),
	 *        each value either a plain translation string or an array of
	 *        plural forms.
	 * @param array<string,string>                    $headers PO-style
	 *        header fields (e.g. `Content-Type`, `Language`), folded into
	 *        the conventional `msgid ""` entry.
	 * @return string Raw MO file contents.
	 */
	public static function write( array $entries, array $headers ): string {
		$all         = $entries;
		$all['']     = self::build_header_block( $headers );
		$sorted_keys = array_keys( $all );
		sort( $sorted_keys, SORT_STRING );

		$originals    = array();
		$translations = array();
		foreach ( $sorted_keys as $key ) {
			$value          = $all[ $key ];
			$originals[]    = $key;
			$translations[] = is_array( $value ) ? implode( "\0", $value ) : (string) $value;
		}

		$count = count( $originals );

		$originals_table_offset    = self::HEADER_SIZE;
		$translations_table_offset = $originals_table_offset + $count * 8;
		$hash_table_offset         = $translations_table_offset + $count * 8;
		$strings_offset            = $hash_table_offset; // Hash table size is always 0.

		$originals_blob    = '';
		$originals_offsets = array();
		foreach ( $originals as $s ) {
			$originals_offsets[] = $strings_offset + strlen( $originals_blob );
			$originals_blob     .= $s . "\0";
		}

		$translations_blob_start = $strings_offset + strlen( $originals_blob );
		$translations_blob       = '';
		$translations_offsets    = array();
		foreach ( $translations as $s ) {
			$translations_offsets[] = $translations_blob_start + strlen( $translations_blob );
			$translations_blob     .= $s . "\0";
		}

		$header = pack( 'V7', self::MAGIC, 0, $count, $originals_table_offset, $translations_table_offset, 0, $hash_table_offset );

		$originals_table = '';
		foreach ( $originals as $i => $s ) {
			$originals_table .= pack( 'VV', strlen( $s ), $originals_offsets[ $i ] );
		}

		$translations_table = '';
		foreach ( $translations as $i => $s ) {
			$translations_table .= pack( 'VV', strlen( $s ), $translations_offsets[ $i ] );
		}

		return $header . $originals_table . $translations_table . $originals_blob . $translations_blob;
	}

	/**
	 * Reads one header field (e.g. `Plural-Forms`) back out of a raw MO
	 * file's own `""`-keyed header entry (see build_header_block()) --
	 * used by Babilim_Default_Index::resolve_plural_forms() to read
	 * a default-domain supplement fragment's own header without needing
	 * a second injected reader callable, since every fragment this reads
	 * is always a plain `.mo` file this class itself could have written.
	 *
	 * @param string $bytes Raw MO file contents.
	 * @param string $key   Header field name, e.g. 'Plural-Forms'.
	 * @return string|null The header value, or null if the file doesn't
	 *                      parse as MO, has no header entry, or the given
	 *                      key isn't present in it.
	 */
	public static function read_header( string $bytes, string $key ): ?string {
		if ( strlen( $bytes ) < self::HEADER_SIZE ) {
			return null;
		}
		$header = @unpack( 'Vmagic/Vrevision/Vn/Vo/Vt/Vhash_size/Vh', substr( $bytes, 0, self::HEADER_SIZE ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $header ) || self::MAGIC !== $header['magic'] ) {
			return null;
		}

		$count = (int) $header['n'];
		$o     = (int) $header['o'];
		$t     = (int) $header['t'];

		for ( $i = 0; $i < $count; $i++ ) {
			$orow = @unpack( 'Vlen/Voff', substr( $bytes, $o + $i * 8, 8 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! is_array( $orow ) ) {
				return null;
			}
			if ( 0 !== (int) $orow['len'] ) {
				continue; // Only the "" (empty msgid) entry is the header block.
			}
			$trow = @unpack( 'Vlen/Voff', substr( $bytes, $t + $i * 8, 8 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! is_array( $trow ) ) {
				return null;
			}
			$block = substr( $bytes, $trow['off'], $trow['len'] );
			foreach ( explode( "\n", $block ) as $line ) {
				if ( 0 === strpos( $line, $key . ':' ) ) {
					return trim( substr( $line, strlen( $key ) + 1 ) );
				}
			}
			return null;
		}

		return null;
	}

	/**
	 * The conventional PO/MO header block: one `Key: value` line per
	 * header, in the order given.
	 *
	 * @param array<string,string> $headers Header fields.
	 * @return string
	 */
	private static function build_header_block( array $headers ): string {
		$lines = '';
		foreach ( $headers as $key => $value ) {
			$lines .= $key . ': ' . $value . "\n";
		}
		return $lines;
	}
}
