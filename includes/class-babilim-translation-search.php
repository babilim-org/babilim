<?php
/**
 * Local search over the translations this site has installed from Babilim.
 *
 * Only files recorded in the manifest are searched (a site's own
 * `custom/` overrides and non-Babilim files are not in it), and only a
 * record's `.mo` files (its own text domain and any extra domains), never
 * its `default_files` supplement fragments.
 * Nothing here talks to the service.
 *
 * @package Babilim
 */

defined( 'ABSPATH' ) || exit;

/**
 * Case-insensitive substring search over the manifest's installed files.
 */
final class Babilim_Translation_Search {

	private const MIN_QUERY_LENGTH = 2;
	private const MAX_QUERY_LENGTH = 200;
	private const MAX_FIELD_LENGTH = 500;

	/**
	 * @var Babilim_Manifest
	 */
	private $manifest;

	/**
	 * `fn(string $path): array` -- keys `{msgctxt}\x04{msgid}`, values
	 * string or string[].
	 *
	 * @var callable
	 */
	private $parse_entries;

	/**
	 * `fn(string $kind, string $slug): string`.
	 *
	 * @var callable
	 */
	private $component_label;

	/**
	 * @var Babilim_Fs
	 */
	private $fs;

	/**
	 * @param Babilim_Manifest $manifest        The installed-file manifest.
	 * @param callable         $parse_entries   Reads a translation file into entries.
	 * @param callable         $component_label Display name for a component.
	 * @param Babilim_Fs       $fs              Used to check a file against its manifest hash.
	 */
	public function __construct( Babilim_Manifest $manifest, callable $parse_entries, callable $component_label, Babilim_Fs $fs ) {
		$this->manifest        = $manifest;
		$this->parse_entries   = $parse_entries;
		$this->component_label = $component_label;
		$this->fs              = $fs;
	}

	/**
	 * A stable reference to one string: sha256 of `msgctxt EOT msgid`.
	 *
	 * @param string $msgctxt Context ('' for none).
	 * @param string $msgid   Source string.
	 * @return string
	 */
	public static function ref( string $msgctxt, string $msgid ): string {
		return hash( 'sha256', $msgctxt . "\x04" . $msgid );
	}

	/**
	 * Reads one string back from the installed `.mo` files of a component
	 * (main and extra text domains) by its reference. Each file must match
	 * its manifest hash both before and after it is parsed, so the answer
	 * comes from the recorded build.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @param string $ref    Value from ref().
	 * @return array{msgctxt:string,msgid:string,msgstr:string[],build_id:string}|null
	 */
	public function find( string $kind, string $slug, string $locale, string $ref ): ?array {
		try {
			$record = $this->manifest->installed( $kind, $slug, $locale );
		} catch ( RuntimeException $e ) {
			return null;
		}
		if ( null === $record ) {
			return null;
		}
		$files = (array) ( $record['files'] ?? array() );
		foreach ( $this->record_strings( $files, true ) as list( $msgctxt, $msgid, $value ) ) {
			if ( ! hash_equals( self::ref( $msgctxt, $msgid ), $ref ) ) {
				continue;
			}
			return array(
				'msgctxt'  => $msgctxt,
				'msgid'    => $msgid,
				'msgstr'   => $this->split_forms( $value ),
				'build_id' => (string) ( $record['build_id'] ?? '' ),
			);
		}
		return null;
	}

	/**
	 * Walks every `.mo` file a record lists (the component's own text
	 * domain and any extra domains), yielding each entry as
	 * `[ msgctxt, msgid, value ]`. An entry whose msgid or msgctxt is over
	 * the feedback limit is never yielded: the service would refuse it.
	 *
	 * With $verify, a file is only read when its hash matches the record
	 * both before and after it is parsed; a file that does not is skipped.
	 *
	 * @param array<string,mixed> $files  The record's relative path => sha256.
	 * @param bool                $verify Check each file against its recorded hash.
	 * @return \Generator<int,array{0:string,1:string,2:mixed}>
	 */
	private function record_strings( array $files, bool $verify ): \Generator {
		foreach ( array_keys( $files ) as $relative ) {
			$relative = (string) $relative;
			if ( '.mo' !== strtolower( substr( $relative, -3 ) ) ) {
				continue;
			}
			$absolute = Babilim_Paths::root() . '/' . $relative;
			$before   = null;
			if ( $verify ) {
				$before = $this->fs->sha256( $absolute );
				if ( ! is_string( $before ) || $before !== (string) $files[ $relative ] ) {
					continue;
				}
			}
			$entries = (array) call_user_func( $this->parse_entries, $absolute );
			if ( $verify && $this->fs->sha256( $absolute ) !== $before ) {
				continue;
			}
			foreach ( $entries as $key => $value ) {
				$parts   = explode( "\x04", (string) $key, 2 );
				$msgctxt = 2 === count( $parts ) ? $parts[0] : '';
				$msgid   = 2 === count( $parts ) ? $parts[1] : $parts[0];
				// msgid and msgctxt identify the string in a report, so they
				// are never cut; one the service would refuse is left out.
				if ( Babilim_Contract_Limits::length( $msgid ) > Babilim_Contract_Limits::FEEDBACK_TEXT
					|| Babilim_Contract_Limits::length( $msgctxt ) > Babilim_Contract_Limits::FEEDBACK_TEXT ) {
					continue;
				}
				yield array( $msgctxt, $msgid, $value );
			}
		}
	}

	/**
	 * Whether the manifest has any record for a locale.
	 *
	 * @param string $locale Locale code.
	 * @return bool
	 */
	public function has_locale( string $locale ): bool {
		foreach ( $this->manifest->all() as $record ) {
			if ( ( $record['locale'] ?? null ) === $locale ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Search installed translations.
	 *
	 * @param string $query  Text to look for (trimmed; 2-200 characters).
	 * @param string $locale Locale code.
	 * @param int    $limit  Maximum number of results.
	 * @return array<int,array{kind:string,slug:string,label:string,locale:string,build_id:string,msgctxt:string,msgid:string,ref:string,msgstr:string[]}>
	 */
	public function search( string $query, string $locale, int $limit = 20 ): array {
		$query = trim( $query );
		$len   = mb_strlen( $query, 'UTF-8' );
		if ( $len < self::MIN_QUERY_LENGTH || $len > self::MAX_QUERY_LENGTH || $limit < 1 ) {
			return array();
		}
		$needle  = self::lower( $query );
		$results = array();

		foreach ( $this->manifest->all() as $record ) {
			if ( ( $record['locale'] ?? null ) !== $locale ) {
				continue;
			}
			$kind  = (string) ( $record['kind'] ?? '' );
			if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
				continue;
			}
			$slug  = (string) ( $record['slug'] ?? '' );
			$label = (string) call_user_func( $this->component_label, $kind, $slug );

			foreach ( $this->record_strings( (array) ( $record['files'] ?? array() ), false ) as list( $msgctxt, $msgid, $value ) ) {
				$msgstr = $this->split_forms( $value );
				if ( ! $this->matches( $needle, $msgid, $msgstr ) ) {
					continue;
				}
				$results[] = array(
					'kind'     => $kind,
					'slug'     => $slug,
					'label'    => $this->clip( $label ),
					'locale'   => $locale,
					'build_id' => (string) ( $record['build_id'] ?? '' ),
					'msgctxt'  => $msgctxt,
					'msgid'    => $msgid,
					'ref'      => self::ref( $msgctxt, $msgid ),
					'msgstr'   => array_map( array( $this, 'clip' ), $msgstr ),
				);
			}
		}

		usort(
			$results,
			static function ( array $a, array $b ): int {
				return array( self::lower( $a['label'] ), self::lower( $a['msgid'] ), $a['label'], $a['msgid'] ) <=> array( self::lower( $b['label'] ), self::lower( $b['msgid'] ), $b['label'], $b['msgid'] );
			}
		);
		return array_slice( $results, 0, $limit );
	}

	/**
	 * @param string   $needle Lower-cased query.
	 * @param string   $msgid  Source string.
	 * @param string[] $msgstr Translations.
	 * @return bool
	 */
	private function matches( string $needle, string $msgid, array $msgstr ): bool {
		if ( false !== strpos( self::lower( $msgid ), $needle ) ) {
			return true;
		}
		foreach ( $msgstr as $s ) {
			if ( false !== strpos( self::lower( $s ), $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Splits an entry value into its plural forms. The production parser
	 * (WP_Translation_File::entries()) returns the forms as one
	 * NUL-joined string; a plain array is accepted too.
	 *
	 * @param mixed $value Entry value.
	 * @return string[]
	 */
	private function split_forms( $value ): array {
		if ( null === $value ) {
			return array();
		}
		$forms = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
			foreach ( explode( "\0", (string) $item ) as $form ) {
				$forms[] = $form;
			}
		}
		return $forms;
	}

	/**
	 * Lower-cases for case-insensitive matching. mbstring is only a
	 * recommended extension, so without it this falls back to a
	 * byte-wise (ASCII) lower-casing instead of a fatal error.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * @param string $s String to cut.
	 * @return string
	 */
	private function clip( string $s ): string {
		return mb_substr( $s, 0, self::MAX_FIELD_LENGTH, 'UTF-8' );
	}
}
