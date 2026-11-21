<?php
/**
 * Rebuilds a site's `custom/` translation files (.mo, .l10n.php, .json)
 * from the saved override rows, and takes over files that were put there by
 * something else (adopt()), so the rows become the single source of truth.
 *
 * Why: the editor saves to the overrides table; the loading layer only reads
 * files. Every save regenerates the three files of one scope together, under
 * the manifest lock, staging each beside its final name first so a failure
 * never leaves a half-written set. A scope that could not be written is
 * remembered in DIRTY_OPTION and retried by the daily job.
 *
 * Core reads a plural entry through its singular key (WP_Translation_File_MO
 * keeps `explode( "\0", $original )[0]` => the NUL-joined forms, and
 * WP_Translation_Controller::translate_plural() looks up `msgid\0plural` and
 * falls back to `msgid`), so `{msgid}\0{msgid_plural}` in the .mo is read back
 * the same as a bare `{msgid}`; the .l10n.php is produced from the .mo by core.
 *
 * `custom/` is never recorded in `_manifest.json`.
 *
 * @package Babilim
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and adopts a scope's `custom/` files.
 */
final class Babilim_Override_Writer {

	const GENERATOR    = 'Babilim Editor';
	const DIRTY_OPTION = 'babilim_overrides_dirty';

	/** Extensions of the three files, in the order they are put in place. */
	private const EXTS = array( 'mo', 'l10n.php', 'json' );

	/** @var Babilim_Fs */
	private $fs;

	/** @var Babilim_Overrides */
	private $overrides;

	/** @var Babilim_Manifest */
	private $manifest;

	/** @var callable fn( string $mo_path ): string */
	private $transform;

	/** @var callable fn( string $path ): array */
	private $parse_entries;

	/** @var callable fn( array $scope ): string */
	private $plural_header;

	public function __construct( Babilim_Fs $fs, Babilim_Overrides $overrides, Babilim_Manifest $manifest, callable $transform, callable $parse_entries, callable $plural_header ) {
		$this->fs            = $fs;
		$this->overrides     = $overrides;
		$this->manifest      = $manifest;
		$this->transform     = $transform;
		$this->parse_entries = $parse_entries;
		$this->plural_header = $plural_header;
	}

	/**
	 * Rewrites the three files of one scope from its saved rows.
	 *
	 * @param array $scope Scope.
	 */
	public function regenerate( array $scope ): Babilim_Result {
		if ( ! self::valid( $scope ) ) {
			return Babilim_Result::error( 'invalid_scope', __( 'Unsafe override scope.', 'babilim' ) );
		}
		try {
			$result = $this->manifest->with_lock(
				function () use ( $scope ) {
					return $this->write( $scope );
				}
			);
		} catch ( \Throwable $e ) {
			$result = null;
		}
		if ( $result instanceof Babilim_Result && $result->is_ok() ) {
			$this->mark( $scope, false );
			return $result;
		}
		$this->mark( $scope, true );
		return Babilim_Result::error( 'not_written', __( 'The override files could not be written.', 'babilim' ) );
	}

	/**
	 * Imports the strings of custom/ files this editor did not write into
	 * the rows (after backing the files up). Nothing is replaced here.
	 *
	 * @param array $scope Scope.
	 * @return int Strings imported.
	 */
	public function adopt( array $scope ): int {
		if ( ! self::valid( $scope ) ) {
			return 0;
		}
		// Read-only first: an ordinary page load takes no lock and writes nothing.
		if ( array() === $this->foreign( $scope ) ) {
			return 0;
		}
		try {
			$n = $this->manifest->with_lock(
				function () use ( $scope ) {
					return $this->take_over( $scope );
				}
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
		return is_int( $n ) ? $n : 0;
	}

	/**
	 * Runs regenerate() again for every scope that failed before.
	 *
	 * @return int Scopes still failing.
	 */
	public function retry_dirty(): int {
		$left = 0;
		foreach ( $this->dirty() as $scope ) {
			if ( ! $this->regenerate( $scope )->is_ok() ) {
				++$left;
			}
		}
		return $left;
	}

	/**
	 * Whether the scope's last regenerate() failed and is still waiting.
	 *
	 * @param array $scope Scope.
	 */
	public function is_dirty( array $scope ): bool {
		return isset( $this->dirty()[ self::key( $scope ) ] );
	}

	// -- generation -----------------------------------------------------

	private function write( array $scope ): Babilim_Result {
		if ( null === $this->take_over( $scope ) ) {
			return Babilim_Result::error( 'not_written' );
		}
		$entries = $this->entries( $this->overrides->rows( $scope ) );
		$final   = $this->targets( $scope );

		if ( array() === $entries ) {
			foreach ( $final as $path ) {
				if ( $this->fs->exists( $path ) && self::GENERATOR === $this->generator( $path ) && ! $this->fs->delete( $path ) ) {
					return Babilim_Result::error( 'not_written' );
				}
			}
			$this->invalidate( $final );
			return Babilim_Result::ok();
		}

		$tag    = bin2hex( random_bytes( 4 ) );
		$staged = array();
		foreach ( $final as $ext => $path ) {
			$staged[ $ext ] = dirname( $path ) . '/' . $scope['domain'] . '-' . $scope['locale'] . '.tmp-' . $tag . '.' . $ext;
		}

		$ok = $this->stage( $scope, $entries, $staged );
		if ( $ok ) {
			$ok = $this->publish( $staged, $final );
		}
		foreach ( $staged as $path ) {
			$this->fs->delete( $path );
		}
		if ( ! $ok ) {
			return Babilim_Result::error( 'not_written' );
		}
		$this->invalidate( $final );
		return Babilim_Result::ok();
	}

	/**
	 * @param array                                                                         $scope   Scope.
	 * @param array<int,array{mo_key:string,json_key:string,plural:string,forms:string[]}> $entries entries().
	 * @param array<string,string>                                                          $staged  ext => staging path.
	 */
	private function stage( array $scope, array $entries, array $staged ): bool {
		$mo_entries = array();
		$messages   = array();
		foreach ( $entries as $e ) {
			$mo_entries[ $e['mo_key'] ] = count( $e['forms'] ) > 1 || '' !== $e['plural'] ? $e['forms'] : $e['forms'][0];
			$messages[ $e['json_key'] ] = $e['forms'];
		}
		$plural = (string) call_user_func( $this->plural_header, $scope ); // One call: the .mo and the .json header must agree.
		$mo     = Babilim_Mo_Writer::write(
			$mo_entries,
			array(
				'Content-Type' => 'text/plain; charset=UTF-8',
				'Language'     => $scope['locale'],
				'Plural-Forms' => $plural,
				'X-Generator'  => self::GENERATOR,
			)
		);
		$json = wp_json_encode(
			array(
				'generator'   => self::GENERATOR,
				'domain'      => 'messages',
				'locale_data' => array( 'messages' => array( '' => array( 'domain' => 'messages', 'lang' => $scope['locale'], 'plural-forms' => $plural ) ) + $messages ),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( ! is_string( $json ) || ! $this->fs->put( $staged['mo'], $mo ) ) {
			return false;
		}
		$php = (string) call_user_func( $this->transform, $staged['mo'] );
		return '' !== $php && $this->fs->put( $staged['l10n.php'], $php ) && $this->fs->put( $staged['json'], $json );
	}

	/**
	 * Moves the staged files over the final names; when one fails, the ones
	 * already moved are put back as they were.
	 *
	 * @param array<string,string> $staged ext => staging path.
	 * @param array<string,string> $final  ext => final path.
	 */
	private function publish( array $staged, array $final ): bool {
		$old = array();
		foreach ( $final as $ext => $path ) {
			$bytes = $this->fs->exists( $path ) ? $this->fs->get( $path ) : null;
			if ( null !== $bytes && ! is_string( $bytes ) ) {
				return false;
			}
			$old[ $ext ] = $bytes;
		}
		$moved = array();
		foreach ( self::EXTS as $ext ) {
			if ( ! $this->fs->rename( $staged[ $ext ], $final[ $ext ] ) ) {
				foreach ( $moved as $done ) {
					null === $old[ $done ] ? $this->fs->delete( $final[ $done ] ) : $this->fs->put( $final[ $done ], $old[ $done ] );
				}
				return false;
			}
			$moved[] = $ext;
		}
		return true;
	}

	/**
	 * Rows that go into the files, grouped per string.
	 *
	 * @param array $rows Saved rows.
	 * @return array<int,array{mo_key:string,json_key:string,plural:string,forms:string[]}>
	 */
	private function entries( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['status'], array( Babilim_Overrides::STATUS_ACTIVE, Babilim_Overrides::STATUS_REVIEW, Babilim_Overrides::STATUS_ORPHAN ), true ) ) {
				continue;
			}
			$groups[ $row['ref'] ][ (int) $row['plural_index'] ] = $row;
		}
		$out = array();
		foreach ( $groups as $by_index ) {
			ksort( $by_index );
			$first = reset( $by_index );
			$forms = array();
			foreach ( $by_index as $row ) {
				$forms[] = (string) $row['msgstr'];
			}
			if ( '' === implode( '', $forms ) ) {
				continue;
			}
			$ctx      = (string) $first['msgctxt'];
			$plural   = (string) $first['msgid_plural'];
			$prefix   = '' === $ctx ? '' : $ctx . "\x04";
			$out[]    = array(
				'mo_key'   => $prefix . $first['msgid'] . ( '' === $plural ? '' : "\0" . $plural ),
				'json_key' => $prefix . $first['msgid'],
				'plural'   => $plural,
				'forms'    => $forms,
			);
		}
		return $out;
	}

	// -- adoption -------------------------------------------------------

	/**
	 * @param array $scope Scope.
	 * @return int|null Strings imported; null when a file could not be backed up or read.
	 */
	private function take_over( array $scope ): ?int {
		$foreign = $this->foreign( $scope );
		if ( array() === $foreign ) {
			return 0;
		}

		$strings = array(); // key => forms (key is `ctx EOT msgid`).
		$source  = isset( $foreign['mo'] ) ? $foreign['mo'] : ( $foreign['l10n.php'] ?? null );
		if ( null !== $source ) {
			foreach ( (array) call_user_func( $this->parse_entries, $source ) as $key => $value ) {
				$forms = is_array( $value ) ? array_values( $value ) : explode( "\0", (string) $value );
				if ( '' !== (string) $key ) {
					$strings[ self::first_part( (string) $key ) ] = $forms;
				}
			}
		}
		if ( isset( $foreign['json'] ) ) {
			$bytes   = $this->fs->get( $foreign['json'] );
			$decoded = is_string( $bytes ) ? json_decode( $bytes, true ) : null;
			$msgs    = is_array( $decoded ) && is_array( $decoded['locale_data']['messages'] ?? null ) ? $decoded['locale_data']['messages'] : array();
			foreach ( $msgs as $key => $forms ) {
				$key = (string) $key;
				if ( '' !== $key && ! isset( $strings[ $key ] ) && is_array( $forms ) && array() !== $forms && array_filter( $forms, 'is_string' ) === $forms ) {
					$strings[ $key ] = array_values( $forms );
				}
			}
		}

		foreach ( $foreign as $ext => $path ) {
			$bytes = $this->fs->get( $path );
			if ( ! is_string( $bytes ) ) {
				return null;
			}
			$backup = $this->backup_path( $scope, $ext, $bytes );
			if ( null === $backup || ( '' !== $backup && ! $this->fs->put( $backup, $bytes ) ) ) {
				return null;
			}
		}

		$have = array();
		foreach ( $this->overrides->rows( $scope ) as $row ) {
			$have[ $row['ref'] ] = true;
		}
		$count = 0;
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( $strings as $key => $forms ) {
			$at    = strpos( $key, "\x04" );
			$ctx   = false === $at ? '' : substr( $key, 0, $at );
			$msgid = false === $at ? $key : substr( $key, $at + 1 );
			$ref   = Babilim_Translation_Search::ref( $ctx, $msgid );
			if ( isset( $have[ $ref ] ) || '' === implode( '', $forms ) ) {
				continue;
			}
			foreach ( $forms as $i => $form ) {
				$saved = $this->overrides->put(
					$scope,
					array(
						'ref'          => $ref,
						'plural_index' => $i,
						'msgctxt'      => $ctx,
						'msgid'        => $msgid,
						'msgstr'       => $form,
						'status'       => Babilim_Overrides::STATUS_ACTIVE,
						'origin'       => 'file',
						'modified_gmt' => $now,
					)
				);
				if ( ! $saved ) {
					// Drop the forms already saved so a retry imports the whole string again (a ref that is present is skipped).
					$this->overrides->delete( $scope, $ref );
					return null;
				}
			}
			++$count;
		}
		return $count;
	}

	/**
	 * ext => path of the scope's custom/ files this editor did not write.
	 *
	 * @param array $scope Scope.
	 * @return array<string,string>
	 */
	private function foreign( array $scope ): array {
		$out = array();
		foreach ( $this->targets( $scope ) as $ext => $path ) {
			if ( $this->fs->exists( $path ) && self::GENERATOR !== $this->generator( $path ) ) {
				$out[ $ext ] = $path;
			}
		}
		return $out;
	}

	/**
	 * Where to back up a foreign file: `{domain}-{locale}.{ext}` the first
	 * time, then `{domain}-{locale}.{YmdHis}[-n].{ext}` (UTC), so no backup
	 * is ever overwritten. '' when a backup with these bytes already exists;
	 * null when no free name was found.
	 *
	 * @param array  $scope Scope.
	 * @param string $ext   Extension of the file.
	 * @param string $bytes Its contents.
	 */
	private function backup_path( array $scope, string $ext, string $bytes ): ?string {
		$dir   = Babilim_Paths::dir( 'custom/_backup/' . self::dir_type( $scope ) );
		$base  = $scope['domain'] . '-' . $scope['locale'];
		$first = $dir . '/' . $base . '.' . $ext;
		if ( ! $this->fs->exists( $first ) ) {
			return $first;
		}
		foreach ( $this->fs->list( $dir, $base . '.*' ) as $name ) {
			if ( substr( $name, -strlen( $ext ) - 1 ) === '.' . $ext && $bytes === $this->fs->get( $dir . '/' . $name ) ) {
				return '';
			}
		}
		$stamp = gmdate( 'YmdHis' );
		for ( $n = 1; $n <= 100; ++$n ) {
			$path = $dir . '/' . $base . '.' . $stamp . ( 1 === $n ? '' : '-' . $n ) . '.' . $ext;
			if ( ! $this->fs->exists( $path ) ) {
				return $path;
			}
		}
		return null;
	}

	/**
	 * The part of a core entry key before a NUL (core keeps only the singular).
	 *
	 * @param string $key Key.
	 */
	private static function first_part( string $key ): string {
		return explode( "\0", $key )[0];
	}

	// -- helpers --------------------------------------------------------

	/**
	 * The X-Generator of one file; '' when it has none or cannot be read.
	 *
	 * @param string $path File.
	 */
	private function generator( string $path ): string {
		$bytes = $this->fs->get( $path );
		if ( ! is_string( $bytes ) ) {
			return '';
		}
		if ( '.json' === substr( $path, -5 ) ) {
			$decoded = json_decode( $bytes, true );
			return is_array( $decoded ) && is_string( $decoded['generator'] ?? null ) ? trim( $decoded['generator'] ) : '';
		}
		return Babilim_Translation_Header::generator(
			$path,
			static function ( string $p, int $offset, int $length ) use ( $bytes ): string {
				return substr( $bytes, $offset, $length );
			}
		);
	}

	/**
	 * ext => final path of a scope's three files.
	 *
	 * @param array $scope Scope.
	 * @return array<string,string>
	 */
	private function targets( array $scope ): array {
		$out = array();
		foreach ( self::EXTS as $ext ) {
			$out[ $ext ] = Babilim_Paths::file( 'custom/' . self::dir_type( $scope ), $scope['domain'], $scope['locale'], $ext );
		}
		return $out;
	}

	private static function dir_type( array $scope ): string {
		return 'theme' === $scope['kind'] ? 'themes' : 'plugins';
	}

	private static function valid( array $scope ): bool {
		return in_array( $scope['kind'] ?? null, array( 'plugin', 'theme' ), true )
			&& is_string( $scope['domain'] ?? null ) && Babilim_Paths::is_safe_name( $scope['domain'] )
			&& is_string( $scope['locale'] ?? null ) && Babilim_Paths::is_safe_locale( $scope['locale'] )
			&& is_string( $scope['slug'] ?? null );
	}

	private static function key( array $scope ): string {
		return $scope['kind'] . '/' . $scope['slug'] . '/' . $scope['domain'] . '/' . $scope['locale'];
	}

	/**
	 * Cache invalidation after files were put in place or removed.
	 *
	 * @param array<string,string> $final ext => final path.
	 */
	private function invalidate( array $final ): void {
		Babilim_Installer::invalidate_cache_for_dir( dirname( $final['mo'] ) );
		foreach ( $final as $path ) {
			Babilim_Installer::invalidate_cache_for_file( $path );
		}
	}

	/**
	 * @return array<string,array> key => scope.
	 */
	private function dirty(): array {
		$stored = get_site_option( self::DIRTY_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	private function mark( array $scope, bool $dirty ): void {
		$all = $this->dirty();
		$key = self::key( $scope );
		if ( $dirty ) {
			$all[ $key ] = array(
				'kind'   => $scope['kind'],
				'slug'   => $scope['slug'],
				'domain' => $scope['domain'],
				'locale' => $scope['locale'],
			);
		} elseif ( isset( $all[ $key ] ) ) {
			unset( $all[ $key ] );
		} else {
			return;
		}
		if ( array() === $all ) {
			delete_site_option( self::DIRTY_OPTION );
		} else {
			update_site_option( self::DIRTY_OPTION, $all );
		}
	}
}
