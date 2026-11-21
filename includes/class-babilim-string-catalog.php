<?php
/**
 * The strings of one component's text domain and which source currently
 * supplies each translation -- the data behind the editor's string list.
 *
 * What it does: unions every string found in (1) the Babilim build installed
 * for the locale (`.mo`, merged script `.json`, and the build's `.po` kept in
 * `_versions/`), (2) the WordPress.org language pack in WP_LANG_DIR, and
 * (3) the files the component ships (`*.pot`, `.po`, `.mo`), keyed by
 * Babilim_Translation_Search::ref(). For each string the effective source is
 * the first one, in the loader's order, with a non-empty translation:
 * `babilim`, then `wporg`, then `bundled` -- the bundled file only counts
 * when no language-pack file exists, because core then picks the pack file
 * and never loads the bundled one. Nothing here writes or talks to the
 * service. Paths come only from Babilim_Paths and the inventory's language
 * directories, never from a request.
 *
 * Why callables: the readers are injected so tests need no real files and no
 * WordPress; read_po() is the real `.po`/`.pot` reader (WordPress core's
 * pomo `PO` class, which the caller must be able to load).
 *
 * @package Babilim
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the string catalog of one component + text domain + locale.
 */
final class Babilim_String_Catalog {

	/**
	 * @var Babilim_Manifest
	 */
	private $manifest;

	/**
	 * @var callable
	 */
	private $parse_entries;

	/**
	 * @var callable
	 */
	private $read_po;

	/**
	 * @var callable
	 */
	private $list_dir;

	/**
	 * @var callable
	 */
	private $read_file;

	/**
	 * @var callable
	 */
	private $language_dirs;

	/**
	 * @param Babilim_Manifest $manifest      Installed-build records.
	 * @param callable         $parse_entries fn( string $path ): array -- a `.mo`/`.l10n.php` as key => msgstr (NUL-joined plural forms), as Babilim::parse_entries().
	 * @param callable         $read_po       fn( string $path ): array -- see read_po().
	 * @param callable         $list_dir      fn( string $dir ): string[] -- bare file names; [] when the directory is missing.
	 * @param callable         $read_file     fn( string $path ): string|false -- file bytes.
	 * @param callable         $language_dirs fn( array $component ): string[] -- the component's own translation directories, i.e. Babilim_Inventory::language_dirs().
	 */
	public function __construct( Babilim_Manifest $manifest, callable $parse_entries, callable $read_po, callable $list_dir, callable $read_file, callable $language_dirs ) {
		$this->manifest      = $manifest;
		$this->parse_entries = $parse_entries;
		$this->read_po       = $read_po;
		$this->list_dir      = $list_dir;
		$this->read_file     = $read_file;
		$this->language_dirs = $language_dirs;
	}

	/**
	 * Reads one `.po`/`.pot` with core's pomo `PO` class. The header block is
	 * returned as the entry with an empty msgid (as in the file itself).
	 *
	 * @param string $path Absolute path.
	 * Empty when the file is unreadable or pomo is not loaded.
	 *
	 * @return list<array{msgctxt:string,msgid:string,msgid_plural:string,msgstr:string[],comments:string[]}>
	 */
	public static function read_po( string $path ): array {
		try {
			if ( ! class_exists( 'PO', false ) && defined( 'WPINC' ) && is_file( ABSPATH . WPINC . '/pomo/po.php' ) ) {
				require_once ABSPATH . WPINC . '/pomo/po.php';
			}
			if ( ! class_exists( 'PO', false ) ) {
				return array();
			}
			$po = new PO();
			if ( true !== $po->import_from_file( $path ) ) {
				return array();
			}
			/** @var list<array{msgctxt:string,msgid:string,msgid_plural:string,msgstr:string[],comments:string[]}> $rows */
			$rows = array();
			if ( array() !== $po->headers ) {
				$block = '';
				foreach ( $po->headers as $name => $value ) {
					$block .= $name . ': ' . $value . "\n";
				}
				$rows[] = array(
					'msgctxt'      => '',
					'msgid'        => '',
					'msgid_plural' => '',
					'msgstr'       => array( $block ),
					'comments'     => array(),
				);
			}
			foreach ( $po->entries as $entry ) {
				$comments = array();
				foreach ( array( $entry->extracted_comments, $entry->translator_comments ) as $comment ) {
					if ( '' !== trim( (string) $comment ) ) {
						$comments[] = trim( (string) $comment );
					}
				}
				$rows[] = array(
					'msgctxt'      => (string) $entry->context,
					'msgid'        => (string) $entry->singular,
					'msgid_plural' => $entry->is_plural ? (string) $entry->plural : '',
					'msgstr'       => array_map( 'strval', array_values( (array) $entry->translations ) ),
					'comments'     => $comments,
				);
			}
			return $rows;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * @param array  $component Component from Babilim_Inventory::components().
	 * @param string $domain    Text domain (the component's own or one of its extra domains).
	 * @param string $locale    Locale code.
	 * @return array{entries:array<string,array>,plural_forms:string,current:bool,sources:string[]}
	 */
	public function load( array $component, string $domain, string $locale ): array {
		$empty = array(
			'entries'      => array(),
			'plural_forms' => Babilim_Plural_Forms::header( $locale ),
			'current'      => false,
			'sources'      => array(),
		);

		$kind = (string) ( $component['kind'] ?? '' );
		$slug = (string) ( $component['slug'] ?? '' );
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! Babilim_Paths::is_safe_name( $slug )
			|| ! Babilim_Paths::is_safe_name( $domain ) || ! Babilim_Paths::is_safe_locale( $locale ) ) {
			return $empty;
		}

		$dir_type = 'theme' === $kind ? 'themes' : 'plugins';
		$record   = null;
		try {
			$record = $this->manifest->installed( $kind, $slug, $locale );
		} catch ( \Throwable $e ) {
			$record = null;
		}
		$build_id = is_array( $record ) ? (string) ( $record['build_id'] ?? '' ) : '';

		$layers   = array();
		$bundled  = array();
		$has_pack = false;
		$name     = $domain . '-' . $locale;

		// 1. Babilim's own build.
		$babilim_dir = Babilim_Paths::dir( $dir_type );
		$listing     = $this->names( $babilim_dir );
		foreach ( array( 'mo', 'json' ) as $ext ) {
			if ( in_array( $name . '.' . $ext, $listing, true ) ) {
				$layers[] = $this->layer( 'babilim', $babilim_dir . '/' . $name . '.' . $ext, $ext );
			}
		}
		if ( '' !== $build_id ) {
			$history = Babilim_Paths::version_dir( $dir_type, $slug, $locale, $build_id );
			if ( Babilim_Paths::is_allowed( $history, false ) && in_array( $name . '.po', $this->names( $history ), true ) ) {
				$layers[] = $this->layer( 'babilim', $history . '/' . $name . '.po', 'po' );
			}
		}

		// 2. The WordPress.org language pack.
		if ( defined( 'WP_LANG_DIR' ) ) {
			$pack_dir = rtrim( (string) WP_LANG_DIR, '/' ) . '/' . $dir_type;
			$listing  = $this->names( $pack_dir );
			foreach ( array( 'l10n.php', 'mo', 'po' ) as $ext ) {
				if ( in_array( $name . '.' . $ext, $listing, true ) ) {
					// Only .mo/.l10n.php are loaded by core; a .po lists strings only.
					$has_pack = $has_pack || 'po' !== $ext;
					$layers[] = $this->layer( 'wporg', $pack_dir . '/' . $name . '.' . $ext, 'po' === $ext ? 'po' : ( 'mo' === $ext ? 'mo' : 'php' ) );
				}
			}
			$json = '/^' . preg_quote( $name, '/' ) . '-[0-9a-f]{32}\.json$/D';
			foreach ( $listing as $file ) {
				if ( 1 === preg_match( $json, $file ) ) {
					$layers[] = $this->layer( 'wporg', $pack_dir . '/' . $file, 'json' );
				}
			}
		}

		// 3. What the component ships.
		$own    = (string) ( $component['text_domain'] ?? '' ) === $domain;
		$extras = array_map( 'strval', (array) ( $component['extra_domains'] ?? array() ) );
		foreach ( (array) call_user_func( $this->language_dirs, $component ) as $dir ) {
			$dir     = rtrim( (string) $dir, '/' );
			$listing = $this->names( $dir );
			foreach ( $listing as $file ) {
				if ( '.pot' !== substr( $file, -4 ) ) {
					continue;
				}
				$base = substr( $file, 0, -4 );
				if ( $own ? ! in_array( $base, $extras, true ) : $base === $domain ) {
					$bundled[] = $this->layer( 'bundled', $dir . '/' . $file, 'pot' );
				}
			}
			// The .mo first: core loads it, never the .po, so its header is the one in force.
			$files = array( $name . '.mo' => 'mo', $name . '.po' => 'po' );
			if ( 'theme' === $kind && $own ) {
				$files += array( $locale . '.mo' => 'mo', $locale . '.po' => 'po' );
			}
			foreach ( $files as $file => $type ) {
				if ( in_array( $file, $listing, true ) ) {
					$bundled[] = $this->layer( 'bundled', $dir . '/' . $file, $type );
				}
			}
		}
		foreach ( $bundled as $layer ) {
			$layer['counts'] = $layer['counts'] && ! $has_pack;
			$layers[]        = $layer;
		}

		return $this->merge( $layers, $build_id, count( $bundled ) > 0, $record, $component, $empty );
	}

	/**
	 * @param array[]    $layers   In priority order.
	 * @param string     $build_id Installed Babilim build id.
	 * @param bool       $shipped  Whether the component ships any template or translation file.
	 * @param array|null $record   Manifest record.
	 * @param array      $component Component.
	 * @param array      $empty    Result for no sources.
	 * @return array
	 */
	private function merge( array $layers, string $build_id, bool $shipped, ?array $record, array $component, array $empty ): array {
		$meta    = array();
		$chosen  = array();
		$sources = array();
		$plural  = null;

		foreach ( $layers as $layer ) {
			$sources[ $layer['source'] ] = true;
			if ( null === $plural && null !== $layer['header'] ) {
				$plural = $layer['header'];
			}
			foreach ( $layer['rows'] as $row ) {
				$ref = Babilim_Translation_Search::ref( $row['msgctxt'], $row['msgid'] );
				if ( ! isset( $meta[ $ref ] ) ) {
					$meta[ $ref ] = array(
						'ref'          => $ref,
						'msgctxt'      => $row['msgctxt'],
						'msgid'        => $row['msgid'],
						'msgid_plural' => '',
						'comments'     => array(),
						'source'       => 'none',
						'msgstr'       => array(),
						'build_id'     => '',
					);
				}
				if ( '' === $meta[ $ref ]['msgid_plural'] ) {
					$meta[ $ref ]['msgid_plural'] = $row['msgid_plural'];
				}
				if ( array() === $meta[ $ref ]['comments'] ) {
					$meta[ $ref ]['comments'] = $row['comments'];
				}
				if ( $layer['counts'] && ! isset( $chosen[ $ref ] ) && '' !== implode( '', $row['msgstr'] ) ) {
					$chosen[ $ref ]          = true;
					$meta[ $ref ]['source']  = $layer['source'];
					$meta[ $ref ]['msgstr']  = $row['msgstr'];
					$meta[ $ref ]['build_id'] = 'babilim' === $layer['source'] ? $build_id : '';
				}
			}
		}

		if ( array() === $layers ) {
			return $empty;
		}

		$version = (string) ( $component['version'] ?? '' );
		return array(
			'entries'      => $meta,
			'plural_forms' => $plural ?? $empty['plural_forms'],
			'current'      => $shipped || ( null !== $record && '' !== $version && (string) ( $record['component_version'] ?? '' ) === $version ),
			'sources'      => array_values(
				array_filter(
					array( 'babilim', 'wporg', 'bundled' ),
					static function ( string $s ) use ( $sources ): bool {
						return isset( $sources[ $s ] );
					}
				)
			),
		);
	}

	/**
	 * Reads one file into a layer: its rows and its Plural-Forms header.
	 *
	 * @param string $source 'babilim'|'wporg'|'bundled'.
	 * @param string $path   Absolute path.
	 * @param string $type   'mo'|'php'|'json'|'po'|'pot'. A `.po`/`.pot` lists strings, comments and plural source forms but never supplies the effective translation (core loads only `.mo`/`.l10n.php`; a `.po` can be fuzzy or stale), and a `.pot` has no header of its own.
	 * @return array{source:string,counts:bool,rows:array[],header:?string}
	 */
	private function layer( string $source, string $path, string $type ): array {
		$rows   = array();
		$header = null;

		if ( 'mo' === $type || 'php' === $type ) {
			foreach ( (array) call_user_func( $this->parse_entries, $path ) as $key => $value ) {
				$key = (string) $key;
				if ( '' === $key ) {
					continue;
				}
				$at    = strpos( $key, "\x04" );
				$msgid = explode( "\0", false === $at ? $key : substr( $key, $at + 1 ) )[0];
				$rows[] = self::row( false === $at ? '' : substr( $key, 0, $at ), $msgid, '', self::forms( $value ), array() );
			}
			if ( 'mo' === $type ) {
				$bytes  = call_user_func( $this->read_file, $path );
				$header = is_string( $bytes ) ? Babilim_Mo_Writer::read_header( $bytes, 'Plural-Forms' ) : null;
			}
		} elseif ( 'json' === $type ) {
			$bytes   = call_user_func( $this->read_file, $path );
			$decoded = is_string( $bytes ) ? json_decode( $bytes, true ) : null;
			$data    = is_array( $decoded ) && is_array( $decoded['locale_data'] ?? null ) ? $decoded['locale_data'] : array();
			$msgs    = is_array( $data['messages'] ?? null ) ? $data['messages'] : ( is_array( reset( $data ) ) ? reset( $data ) : array() );
			foreach ( $msgs as $key => $forms ) {
				$key = (string) $key;
				if ( '' === $key ) {
					$h      = is_array( $forms ) ? (string) ( $forms['plural-forms'] ?? $forms['plural_forms'] ?? '' ) : '';
					$header = '' === $h ? null : $h;
					continue;
				}
				$at     = strpos( $key, "\x04" );
				$rows[] = self::row( false === $at ? '' : substr( $key, 0, $at ), false === $at ? $key : substr( $key, $at + 1 ), '', self::forms( $forms ), array() );
			}
		} else {
			foreach ( (array) call_user_func( $this->read_po, $path ) as $row ) {
				$msgstr = self::forms( $row['msgstr'] ?? array() );
				if ( '' === (string) ( $row['msgid'] ?? '' ) ) {
					if ( 'pot' !== $type && 1 === preg_match( '/^Plural-Forms:[ \t]*(.+)$/mi', implode( '', $msgstr ), $m ) ) {
						$header = trim( $m[1] );
					}
					continue;
				}
				$rows[] = self::row( (string) ( $row['msgctxt'] ?? '' ), (string) $row['msgid'], (string) ( $row['msgid_plural'] ?? '' ), $msgstr, array_map( 'strval', (array) ( $row['comments'] ?? array() ) ) );
			}
		}

		// Only a rule core can read: the same pattern as WP_Translation_File::get_plural_expression_from_header() (a placeholder such as `nplurals=INTEGER; plural=EXPRESSION;` fails it too).
		if ( null !== $header && 1 !== preg_match( '/^\s*nplurals\s*=\s*(\d+)\s*;\s+plural\s*=\s*(.+)$/', $header ) ) {
			$header = null;
		}

		return array(
			'source' => $source,
			'counts' => 'po' !== $type && 'pot' !== $type,
			'rows'   => $rows,
			'header' => $header,
		);
	}

	/**
	 * @param string   $msgctxt      Context.
	 * @param string   $msgid        Singular.
	 * @param string   $msgid_plural Plural source form.
	 * @param string[] $msgstr       Translated forms.
	 * @param string[] $comments     Comments.
	 * @return array
	 */
	private static function row( string $msgctxt, string $msgid, string $msgid_plural, array $msgstr, array $comments ): array {
		return array(
			'msgctxt'      => $msgctxt,
			'msgid'        => $msgid,
			'msgid_plural' => $msgid_plural,
			'msgstr'       => $msgstr,
			'comments'     => $comments,
		);
	}

	/**
	 * A msgstr value (NUL-joined string, or a list of them) as a list of forms.
	 *
	 * @param mixed $value Value from a parser.
	 * @return string[]
	 */
	private static function forms( $value ): array {
		$forms = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
			foreach ( explode( "\0", (string) $item ) as $form ) {
				$forms[] = $form;
			}
		}
		return $forms;
	}

	/**
	 * @param string $dir Directory.
	 * @return string[] Bare file names.
	 */
	private function names( string $dir ): array {
		return array_map( 'strval', (array) call_user_func( $this->list_dir, $dir ) );
	}
}
