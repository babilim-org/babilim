<?php
/**
 * Fetches source text on demand and caches it for the installed version.
 * The translation loader never reads this data.
 *
 * @package Babilim
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

final class Babilim_String_List {
	/** @var Babilim_Fs */
	private $fs;
	/** @var callable */
	private $api_strings;
	/** @var callable */
	private $granted;

	public function __construct( Babilim_Fs $fs, callable $api_strings, callable $granted ) {
		$this->fs          = $fs;
		$this->api_strings = $api_strings;
		$this->granted     = $granted;
	}

	public function granted(): bool {
		return (bool) call_user_func( $this->granted );
	}

	/** @return array{text_domain:string,strings:array[],unavailable:bool}|null */
	public function get( array $component ): ?array {
		$path = self::path( $component );
		try {
			$raw = null === $path ? null : $this->fs->get( $path );
		} catch ( RuntimeException $e ) {
			// Missing filesystem credentials must not hide local templates.
			return null;
		}
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ( $data['version'] ?? null ) !== ( $component['version'] ?? '' )
			|| ! is_string( $data['text_domain'] ?? null ) || ! is_array( $data['strings'] ?? null ) || ! is_bool( $data['unavailable'] ?? null ) ) {
			return null;
		}
		return array(
			'text_domain' => $data['text_domain'],
			'strings'     => self::entries( $data['strings'] ),
			'unavailable' => $data['unavailable'],
		);
	}

	public function fetch( array $component ): Babilim_Result {
		if ( empty( $component['is_wporg'] ) ) {
			return Babilim_Result::error( 'not_wporg', __( 'This plugin or theme is not on WordPress.org.', 'babilim' ) );
		}
		if ( ! $this->granted() ) {
			return Babilim_Result::error( 'needs_consent', __( 'Enable Babilim to get the text list for this plugin or theme.', 'babilim' ) );
		}
		$path = self::path( $component );
		if ( null === $path ) {
			return Babilim_Result::error( 'invalid_component', __( 'That plugin or theme is not available here.', 'babilim' ) );
		}
		$cached = $this->get( $component );
		if ( null !== $cached ) {
			return Babilim_Result::ok( array( 'status' => $cached['unavailable'] ? 'unavailable' : 'ready' ) );
		}

		$answer = call_user_func( $this->api_strings, array_intersect_key( $component, array_flip( array( 'kind', 'slug', 'version' ) ) ) );
		if ( is_wp_error( $answer ) ) {
			return Babilim_Result::error( (string) $answer->get_error_code(), $answer->get_error_message() );
		}
		$status = $answer['status'] ?? '';
		if ( 'pending' === $status ) {
			return Babilim_Result::ok( array( 'status' => $status ) );
		}
		$unavailable = 'unavailable' === $status;
		if ( ! $unavailable && ( 'ready' !== $status || ! is_string( $answer['text_domain'] ?? null ) || '' === $answer['text_domain'] || ! is_array( $answer['strings'] ?? null ) ) ) {
			return Babilim_Result::error( 'invalid_list', __( 'Babilim returned an invalid text list.', 'babilim' ) );
		}
		$json = wp_json_encode(
			array(
				'version'     => $component['version'],
				'text_domain' => $unavailable ? '' : $answer['text_domain'],
				'strings'     => $unavailable ? array() : self::entries( $answer['strings'] ),
				'unavailable' => $unavailable,
			)
		);
		$tmp = substr( $path, 0, -5 ) . '.tmp-' . bin2hex( random_bytes( 8 ) ) . '.json';
		if ( ! is_string( $json ) || ! $this->fs->mkdir( dirname( $path ) ) || ! $this->fs->put( $tmp, $json ) || ! $this->fs->rename( $tmp, $path ) ) {
			$this->fs->delete( $tmp );
			return Babilim_Result::error( 'write_failed', __( 'The text list could not be saved.', 'babilim' ) );
		}
		return Babilim_Result::ok( array( 'status' => $status ) );
	}

	private static function path( array $component ): ?string {
		$kind    = $component['kind'] ?? '';
		$slug    = $component['slug'] ?? '';
		$version = $component['version'] ?? '';
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! is_string( $slug ) || ! Babilim_Paths::is_safe_name( $slug ) || ! is_string( $version ) || '' === $version ) {
			return null;
		}
		$path = Babilim_Paths::strings_file( $kind, $slug );
		return Babilim_Paths::is_allowed( $path ) ? $path : null;
	}

	private static function entries( array $rows ): array {
		$entries = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['msgid'] ?? null ) || '' === $row['msgid'] ) {
				continue;
			}
			$entry = array( 'msgid' => $row['msgid'] );
			foreach ( array( 'msgctxt', 'msgid_plural' ) as $field ) {
				if ( is_string( $row[ $field ] ?? null ) ) {
					$entry[ $field ] = $row[ $field ];
				}
			}
			if ( is_array( $row['comments'] ?? null ) ) {
				$entry['comments'] = array_values( array_filter( $row['comments'], 'is_string' ) );
			}
			$entries[] = $entry;
			if ( count( $entries ) >= 20000 ) {
				break;
			}
		}
		return $entries;
	}
}
