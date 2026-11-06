<?php
/**
 * Wording gate for a built plugin zip.
 *
 * Usage: php scripts/wording-check.php <path-to-zip>
 *
 * Exit 0 when the zip is clean, 1 when it is not (one `file:rule:content`
 * line per violation on stdout), 2 on a usage or read error.
 *
 * The gate compares messages one by one instead of whitelisting whole
 * files. Every message of the POT and of each `.mo` / `.l10n.php` is
 * reduced to a common key -- `context . "\x04" . msgid` (just `msgid`
 * when there is no context), using the singular msgid for plural
 * entries -- and then:
 *
 *   1. none of the retired restriction sentences (FORBIDDEN_MESSAGES)
 *      may appear in any of those sets, under any context;
 *   2. every key of a `.mo` / `.l10n.php` must exist in the POT (so a
 *      stale compiled file cannot carry a message the code no longer
 *      has); a `.mo` plural entry must also be plural in the POT;
 *   3. shipped PHP (not `languages/`) must not read `$cell['auto_install']`
 *      (nor pass `'auto_install'` to a key-reading function such as
 *      array_key_exists() or wp_list_pluck(), see
 *      BABILIM_WORDING_KEY_READERS) and must not contain a retired
 *      message as a string literal. The
 *      user's own `auto_install` option and checkbox are fine;
 *   4. readme.txt must not contain the retired restriction phrases.
 *      Plan names such as "free plan" are fine there.
 *
 * Plural note: a `.l10n.php` plural entry keeps only the singular as its
 * key (its forms are joined with NUL in the value), and a language with a
 * single plural form (`nplurals=1`) compiles a plural entry to one plain
 * string, in a `.mo` as well. So for such a `.mo`, and for a `.l10n.php`
 * that does not declare more than one plural form in its `plural-forms`
 * header, only the opposite mismatch (plural in the file, singular in the
 * POT) is reported; any other `.mo` or `.l10n.php` must match the POT's
 * plural flag exactly.
 */

declare(strict_types=1);

const BABILIM_WORDING_FORBIDDEN_MESSAGES = array(
	'The Babilim service decides which available translations may be installed automatically. On the free plan it normally only allows plugins and themes that already have a Babilim translation installed on this site, so that automatic installs do not take up a new free slot.',
	'Available translations can be installed with one click, and missing ones can be requested. After that, new translations and updates are installed automatically. The Babilim service decides which available translations may be installed automatically. On the free plan it normally only allows plugins and themes that already have a Babilim translation installed on this site, so that automatic installs do not take up a new free slot.',
	'The free plan covers a limited number of plugins and themes; your exact quota is shown in Settings after you enable Babilim.',
	'Needs a paid license',
	'Free quota used',
	'Free quota',
	'Installed · built for %s, new version needs a paid license',
	'Installed · built for a different version, new version needs a paid license',
	'Installed · built for %s, free quota used',
	'Installed · built for a different version, free quota used',
	'Requesting a translation uses one of your free slots, unless this component already uses one. A failed translation does not give the slot back.',
	'If this component does not use one of your free slots yet, requesting a translation uses one. A failed translation does not give the slot back. Continue?',
	'No translation',
);

const BABILIM_WORDING_FORBIDDEN_README = array(
	'may be installed automatically',
	'decided by the Babilim service',
	'decides which available translations',
	'do not take up a new free slot',
	'no longer reads the license kind',
	'normally only allows',
);

/**
 * Functions that read an array key or object property named by a string
 * argument; the retired per-cell flag must not be passed to any of them.
 */
const BABILIM_WORDING_KEY_READERS = array(
	'array_key_exists',
	'key_exists',
	'array_column',
	'wp_list_pluck',
	'wp_list_filter',
	'wp_filter_object_list',
	'wp_list_sort',
);

/**
 * Finds a call such as `array_key_exists( 'auto_install', $cell )` or
 * `\wp_list_pluck( $cells, 'auto_install' )`: the literal
 * `auto_install`, in either quote style, anywhere inside the parentheses
 * of a BABILIM_WORDING_KEY_READERS call (a nested `array( ... )` counts).
 * Names may be fully qualified. Returns the function name, or null.
 *
 * @param string $code PHP source.
 */
function babilim_wording_key_reader_call( string $code ): ?string {
	$tokens = token_get_all( $code );
	$fq     = defined( 'T_NAME_FULLY_QUALIFIED' ) ? constant( 'T_NAME_FULLY_QUALIFIED' ) : null;
	$depth  = 0;
	$open   = array(); // Paren depths at which a reader call was opened => its name.
	$name   = null;    // A reader name waiting for its "(".
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}
			$name = null;
			if ( T_STRING === $token[0] || ( null !== $fq && $fq === $token[0] ) ) {
				$candidate = strtolower( ltrim( $token[1], '\\' ) );
				if ( in_array( $candidate, BABILIM_WORDING_KEY_READERS, true ) ) {
					$name = $candidate;
				}
			} elseif ( T_CONSTANT_ENCAPSED_STRING === $token[0] && array() !== $open && 'auto_install' === substr( $token[1], 1, -1 ) ) {
				return (string) end( $open );
			}
			continue;
		}
		if ( '(' === $token ) {
			++$depth;
			if ( null !== $name ) {
				$open[ $depth ] = $name;
			}
		} elseif ( ')' === $token ) {
			unset( $open[ $depth ] );
			--$depth;
		}
		$name = null;
	}
	return null;
}

/**
 * Splits a message key into its context and its msgid.
 *
 * @param string $key Message key.
 * @return array{0:string,1:string}
 */
function babilim_wording_split_key( string $key ): array {
	$pos = strpos( $key, "\x04" );
	return false === $pos ? array( '', $key ) : array( substr( $key, 0, $pos ), substr( $key, $pos + 1 ) );
}

/**
 * Reads the quoted string off a PO line.
 *
 * @param string $line Text after the keyword, e.g. `"abc \"x\""`.
 */
function babilim_wording_po_string( string $line ): string {
	$line = trim( $line );
	if ( strlen( $line ) < 2 || '"' !== $line[0] || '"' !== substr( $line, -1 ) ) {
		return '';
	}
	$inner = substr( $line, 1, -1 );
	return (string) preg_replace_callback(
		'/\\\\(["\\\\nrt])/',
		static function ( array $m ): string {
			$map = array(
				'n' => "\n",
				'r' => "\r",
				't' => "\t",
			);
			return $map[ $m[1] ] ?? $m[1];
		},
		$inner
	);
}

/**
 * Parses a POT/PO text into message keys.
 *
 * @param string $text PO source.
 * @return array<string,bool> key => plural.
 */
function babilim_wording_parse_pot( string $text ): array {
	$keys    = array();
	$cur     = array();
	$field   = '';
	$flush   = static function () use ( &$keys, &$cur ): void {
		if ( isset( $cur['msgid'] ) && '' !== $cur['msgid'] ) {
			$ctx = $cur['msgctxt'] ?? '';
			$key = ( '' === $ctx ? '' : $ctx . "\x04" ) . $cur['msgid'];

			$keys[ $key ] = isset( $cur['msgid_plural'] );
		}
		$cur = array();
	};
	foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
		$line = rtrim( $line );
		if ( '' === $line ) {
			$flush();
			$field = '';
			continue;
		}
		if ( '#' === $line[0] ) {
			continue;
		}
		if ( 1 === preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+(".*")$/', $line, $m ) ) {
			if ( 'msgctxt' === $m[1] || ( 'msgid' === $m[1] && isset( $cur['msgstr'] ) ) ) {
				if ( isset( $cur['msgid'] ) ) {
					$flush();
				}
			}
			$field = 0 === strpos( $m[1], 'msgstr' ) ? 'msgstr' : $m[1];
			if ( 'msgstr' === $field ) {
				$cur['msgstr'] = true;
				continue;
			}
			$cur[ $field ] = babilim_wording_po_string( $m[2] );
			continue;
		}
		if ( '"' === $line[0] && '' !== $field && 'msgstr' !== $field ) {
			$cur[ $field ] .= babilim_wording_po_string( $line );
		}
	}
	$flush();
	return $keys;
}

/**
 * Reads the message keys out of a GNU MO file.
 *
 * @param string $bytes Raw file.
 * @return array<string,bool>|null key => plural, or null when it is not a MO file.
 */
function babilim_wording_parse_mo( string $bytes ): ?array {
	if ( strlen( $bytes ) < 28 ) {
		return null;
	}
	$h = unpack( 'Vmagic/Vrevision/Vn/Vo/Vt/Vhash_size/Vh', substr( $bytes, 0, 28 ) );
	if ( false === $h || 0x950412de !== $h['magic'] ) {
		return null;
	}
	$keys = array();
	for ( $i = 0; $i < $h['n']; $i++ ) {
		$row = unpack( 'Vlen/Voff', substr( $bytes, $h['o'] + $i * 8, 8 ) );
		if ( false === $row ) {
			return null;
		}
		$raw = substr( $bytes, $row['off'], $row['len'] );
		if ( '' === $raw ) {
			continue; // The header entry.
		}
		$parts = explode( "\0", $raw );

		$keys[ $parts[0] ] = count( $parts ) > 1;
	}
	return $keys;
}

/**
 * Whether a MO file's own header declares a single plural form.
 *
 * @param string $bytes Raw file.
 */
function babilim_wording_mo_has_one_plural_form( string $bytes ): bool {
	$h = unpack( 'Vmagic/Vrevision/Vn/Vo/Vt/Vhash_size/Vh', substr( $bytes, 0, 28 ) );
	if ( false === $h || 0x950412de !== $h['magic'] ) {
		return false;
	}
	for ( $i = 0; $i < $h['n']; $i++ ) {
		$row = unpack( 'Vlen/Voff', substr( $bytes, $h['o'] + $i * 8, 8 ) );
		if ( false !== $row && 0 === $row['len'] ) {
			$t   = unpack( 'Vlen/Voff', substr( $bytes, $h['t'] + $i * 8, 8 ) );
			$hdr = false === $t ? '' : substr( $bytes, $t['off'], $t['len'] );
			return 1 === preg_match( '/^Plural-Forms:\s*nplurals\s*=\s*1\b/mi', $hdr );
		}
	}
	return false;
}

/**
 * Whether a `.l10n.php` file's `plural-forms` header declares more than
 * one plural form. A file without the header says nothing either way.
 *
 * @param string $path File path.
 */
function babilim_wording_l10n_has_several_plural_forms( string $path ): bool {
	try {
		$data = ( static function ( string $file ) {
			return include $file;
		} )( $path );
	} catch ( \Throwable $e ) {
		return false;
	}
	$forms = is_array( $data ) && is_string( $data['plural-forms'] ?? null ) ? $data['plural-forms'] : '';
	return 1 === preg_match( '/nplurals\s*=\s*(\d+)/i', $forms, $m ) && (int) $m[1] > 1;
}

/**
 * Reads the message keys out of a WordPress `.l10n.php` file.
 *
 * @param string $path File path.
 * @return array<string,bool>|null key => plural, or null when the file has no messages array.
 */
function babilim_wording_parse_l10n_php( string $path ): ?array {
	try {
		$data = ( static function ( string $file ) {
			return include $file;
		} )( $path );
	} catch ( \Throwable $e ) {
		return null;
	}
	if ( ! is_array( $data ) || ! isset( $data['messages'] ) || ! is_array( $data['messages'] ) ) {
		return null;
	}
	$keys = array();
	foreach ( $data['messages'] as $key => $value ) {
		$key = (string) $key;
		if ( '' === $key ) {
			continue;
		}
		$keys[ $key ] = is_string( $value ) && false !== strpos( $value, "\0" );
	}
	return $keys;
}

/**
 * Removes a directory tree.
 *
 * @param string $dir Directory.
 */
function babilim_wording_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() && ! $f->isLink() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
}

/**
 * Checks one zip.
 *
 * @param string $zip_path Path to the plugin zip.
 * @return string[]|null One `file:rule:content` line per violation (empty when clean), or null when the zip cannot be read.
 */
function babilim_wording_check_zip( string $zip_path ): ?array {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		return null;
	}
	$dir = rtrim( sys_get_temp_dir(), '/' ) . '/babilim-wording-' . bin2hex( random_bytes( 6 ) );
	if ( ! mkdir( $dir, 0700 ) || ! $zip->extractTo( $dir ) ) {
		$zip->close();
		babilim_wording_rrmdir( $dir );
		return null;
	}
	$zip->close();

	try {
		return babilim_wording_check_dir( $dir );
	} finally {
		babilim_wording_rrmdir( $dir );
	}
}

/**
 * Checks an extracted zip.
 *
 * @param string $dir Directory holding the extracted zip.
 * @return string[] Violations.
 */
function babilim_wording_check_dir( string $dir ): array {
	$violations = array();
	$rel        = static function ( string $path ) use ( $dir ): string {
		return ltrim( substr( $path, strlen( $dir ) ), '/' );
	};
	$all        = array();
	$it         = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() ) {
			$all[] = $f->getPathname();
		}
	}
	sort( $all, SORT_STRING );
	$forbidden = array_flip( BABILIM_WORDING_FORBIDDEN_MESSAGES );

	// The POT: the set every compiled file must stay inside.
	$sets = array();
	$pot  = null;
	foreach ( $all as $path ) {
		if ( 1 === preg_match( '#/languages/[^/]+\.pot$#', $path ) ) {
			$pot = babilim_wording_parse_pot( (string) file_get_contents( $path ) );
			$sets[ $rel( $path ) ] = array( 'pot', $pot );
		}
	}
	if ( null === $pot ) {
		$violations[] = 'languages:no-pot:the zip carries no languages/*.pot to compare against';
		$pot          = array();
	}

	foreach ( $all as $path ) {
		if ( 1 !== preg_match( '#/languages/[^/]+\.(mo|l10n\.php)$#', $path, $m ) ) {
			continue;
		}
		$keys = 'mo' === $m[1] ? babilim_wording_parse_mo( (string) file_get_contents( $path ) ) : babilim_wording_parse_l10n_php( $path );
		if ( null === $keys ) {
			$violations[] = $rel( $path ) . ':unreadable:not a valid ' . $m[1] . ' file';
			continue;
		}
		$sets[ $rel( $path ) ] = array( $m[1], $keys );
		if ( 'mo' === $m[1] && babilim_wording_mo_has_one_plural_form( (string) file_get_contents( $path ) ) ) {
			// A language with one plural form compiles a plural entry to a plain message.
			$sets[ $rel( $path ) ][0] = 'mo1';
		} elseif ( 'l10n.php' === $m[1] && babilim_wording_l10n_has_several_plural_forms( $path ) ) {
			// Its plurals keep every form, so the flag must match the POT exactly.
			$sets[ $rel( $path ) ][0] = 'l10n-plural';
		}
	}

	foreach ( $sets as $file => $set ) {
		list( $type, $keys ) = $set;
		foreach ( $keys as $key => $plural ) {
			list( , $msgid ) = babilim_wording_split_key( (string) $key );
			if ( isset( $forbidden[ $msgid ] ) ) {
				$violations[] = $file . ':forbidden-message:' . $msgid;
			}
			if ( 'pot' === $type ) {
				continue;
			}
			if ( ! array_key_exists( (string) $key, $pot ) ) {
				$violations[] = $file . ':not-in-pot:' . str_replace( "\x04", ' / ', (string) $key );
				continue;
			}
			$pot_plural = $pot[ (string) $key ];
			if ( ( 'mo' === $type && $plural !== $pot_plural ) || ( 'mo1' === $type && $plural && ! $pot_plural ) || ( 'l10n-plural' === $type && $plural !== $pot_plural ) || ( 'l10n.php' === $type && $plural && ! $pot_plural ) ) {
				$violations[] = $file . ':plural-mismatch:' . str_replace( "\x04", ' / ', (string) $key );
			}
		}
	}

	// Shipped PHP: behavior and string literals.
	foreach ( $all as $path ) {
		if ( '.php' !== substr( $path, -4 ) || false !== strpos( $path, '/languages/' ) ) {
			continue;
		}
		$code = (string) file_get_contents( $path );
		// Any read of the retired per-cell flag, whatever the variable is
		// called: ['auto_install'], [ "auto_install" ] or ->auto_install.
		// The user's own setting (OPTION_AUTO_INSTALL, 'babilim_auto_install',
		// a field name passed to a function) is none of these shapes.
		if ( 1 === preg_match( '/\[\s*[\'"]auto_install[\'"]\s*\]|->\s*auto_install\b/', $code, $m ) ) {
			$violations[] = $rel( $path ) . ':source-reads-cell-auto-install:' . $m[0];
		}
		// The same read through a function that takes the key as an
		// argument: array_key_exists( 'auto_install', ... ) and the like.
		$reader = babilim_wording_key_reader_call( $code );
		if ( null !== $reader ) {
			$violations[] = $rel( $path ) . ':source-reads-cell-auto-install:' . $reader . "( 'auto_install' )";
		}
		foreach ( BABILIM_WORDING_FORBIDDEN_MESSAGES as $msg ) {
			foreach ( array( "'" . addcslashes( $msg, "'\\" ) . "'", '"' . addcslashes( $msg, '"\\$' ) . '"' ) as $literal ) {
				if ( false !== strpos( $code, $literal ) ) {
					$violations[] = $rel( $path ) . ':source-forbidden-message:' . $msg;
					break;
				}
			}
		}
	}

	// readme.txt: the retired restriction phrases only.
	foreach ( $all as $path ) {
		if ( 'readme.txt' !== basename( $path ) || substr_count( $rel( $path ), '/' ) > 1 ) {
			continue;
		}
		$text = (string) file_get_contents( $path );
		foreach ( BABILIM_WORDING_FORBIDDEN_README as $phrase ) {
			if ( false !== stripos( $text, $phrase ) ) {
				$violations[] = $rel( $path ) . ':readme-forbidden-phrase:' . $phrase;
			}
		}
	}

	return array_values( array_unique( $violations ) );
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	if ( 2 !== $argc ) {
		fwrite( STDERR, "Usage: php scripts/wording-check.php <zip>\n" );
		exit( 2 );
	}
	$result = babilim_wording_check_zip( $argv[1] );
	if ( null === $result ) {
		fwrite( STDERR, 'Cannot read ' . $argv[1] . "\n" );
		exit( 2 );
	}
	foreach ( $result as $line ) {
		echo $line, "\n";
	}
	if ( array() === $result ) {
		echo "OK: wording check passed\n";
		exit( 0 );
	}
	echo 'FAIL: ' . count( $result ) . " wording violation(s)\n";
	exit( 1 );
}
