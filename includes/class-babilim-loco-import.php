<?php
/**
 * Migrates local Loco PO data through the editor's checks and writer. Preview
 * shares the import decisions without locking, writing or contacting a service.
 *
 * @package Babilim
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Babilim_Loco_Import {
	private const COUNTS = array( 'files' => 0, 'imported' => 0, 'same' => 0, 'conflicts' => 0, 'orphans' => 0, 'unchecked' => 0, 'skipped' => 0 );

	private Babilim_Overrides $overrides;
	private Babilim_String_Catalog $catalog;
	private Babilim_Override_Writer $writer;
	private Babilim_Override_Remap $remap;
	/** @var callable */
	private $components;
	/** @var callable */
	private $locales;
	/** @var callable */
	private $list_dir;
	/** @var callable */
	private $read_po;
	/** @var callable */
	private $now;
	/** @var callable */
	private $user_id;
	private string $warning = '';

	public function __construct( Babilim_Overrides $overrides, Babilim_String_Catalog $catalog, Babilim_Override_Writer $writer, Babilim_Override_Remap $remap, callable $components, callable $locales, callable $list_dir, callable $read_po, callable $now, callable $user_id ) {
		$this->overrides  = $overrides;
		$this->catalog    = $catalog;
		$this->writer     = $writer;
		$this->remap      = $remap;
		$this->components = $components;
		$this->locales    = $locales;
		$this->list_dir   = $list_dir;
		$this->read_po    = $read_po;
		$this->now        = $now;
		$this->user_id    = $user_id;
	}

	/** @return array<string,array{scope:array,component:array}> Absolute PO paths and their owners. */
	public function files(): array {
		$domains = array();
		foreach ( (array) ( $this->components )() as $component ) {
			$kind = (string) $component['kind'];
			if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! Babilim_Paths::is_safe_name( (string) $component['slug'] ) ) {
				continue;
			}
			foreach ( array_merge( array( $component['text_domain'] ), (array) ( $component['extra_domains'] ?? array() ) ) as $domain ) {
				if ( is_string( $domain ) && Babilim_Paths::is_safe_name( $domain ) ) {
					// Prefer the primary domain owner when a second component also uses it.
					if ( ! isset( $domains[ $kind ][ $domain ] ) || $domain === $component['text_domain'] ) {
						$domains[ $kind ][ $domain ] = $component;
					}
				}
			}
		}
		$locales = array_filter( (array) ( $this->locales )(), array( 'Babilim_Paths', 'is_safe_locale' ) );
		$files   = array();
		foreach ( array( 'plugin', 'theme' ) as $kind ) {
			$dir = rtrim( WP_LANG_DIR, '/\\' ) . '/loco/' . $kind . 's';
			foreach ( (array) ( $this->list_dir )( $dir ) as $name ) {
				foreach ( $locales as $locale ) {
					$suffix = '-' . $locale . '.po';
					if ( ! str_ends_with( $name, $suffix ) ) {
						continue;
					}
					$domain = substr( $name, 0, -strlen( $suffix ) );
					if ( isset( $domains[ $kind ][ $domain ] ) ) {
						$component = $domains[ $kind ][ $domain ];
						$files[ $dir . '/' . $name ] = array(
							'scope'     => array( 'kind' => $kind, 'slug' => $component['slug'], 'domain' => $domain, 'locale' => $locale ),
							'component' => $component,
						);
						break;
					}
				}
			}
		}
		return $files;
	}

	/**
	 * Files the list page may offer: the same number the preview shows. Without the
	 * table nothing can be imported, so it is 0 and warning() says why (only when
	 * there is a file to import).
	 */
	public function offered(): int {
		$files = count( $this->files() );
		$this->warning = '';
		return 0 === $files || $this->ready() ? $files : 0;
	}

	/** @return array<string,int> Preview counts. */
	public function preview(): array {
		return $this->ready() ? $this->process( $this->files(), false ) : self::COUNTS;
	}

	/** @return array<string,int> Actual import counts. */
	public function run(): array {
		if ( ! $this->ready() ) {
			return self::COUNTS;
		}
		$result = $this->writer->locked( fn(): array => $this->process( $this->files(), true ) );
		if ( ! is_array( $result ) ) {
			$this->warning = __( 'The translation could not be saved.', 'babilim' );
			return self::COUNTS;
		}
		return $result;
	}

	public function warning(): string {
		return $this->warning;
	}

	private function ready(): bool {
		$ready = $this->overrides->ready();
		$this->warning = $ready ? '' : __( 'The table that stores edited translations does not exist yet.', 'babilim' );
		return $ready;
	}

	/** @param array<string,array{scope:array,component:array}> $files Discovered files. */
	private function process( array $files, bool $write ): array {
		$counts = self::COUNTS;
		$counts['files'] = count( $files );
		foreach ( $files as $path => $file ) {
			$po        = (array) ( $this->read_po )( $path );
			if ( array() === $po ) {
				if ( $write ) {
					$this->warning = __( 'Some Loco Translate files could not be read.', 'babilim' );
				}
				continue;
			}
			$scope     = $file['scope'];
			$component = $file['component'];
			$cat       = $this->catalog->load( $component, $scope['domain'], $scope['locale'] );
			$nplurals  = Babilim_Plural_Forms::nplurals( $cat['plural_forms'] );
			$saved     = array();
			foreach ( $this->overrides->rows( $scope ) as $row ) {
				$saved[ $row['ref'] ][ (int) $row['plural_index'] ] = $row;
			}
			$drafts = array_fill_keys( array_column( $this->overrides->rows( $scope, true ), 'ref' ), true );
			$pending = $this->writer->pending_texts( $scope );
			$changed = false;
			$queued  = false;
			$queue   = array();
			$queued_rows = array();
			foreach ( $po as $text ) {
				if ( '' === $text['msgid'] ) {
					continue; // PO header, not a translation entry.
				}
				$forms = $text['msgstr'];
				$plural = $text['msgid_plural'];
				if ( array() === $forms || in_array( '', $forms, true ) || in_array( 'fuzzy', $text['flags'] ?? array(), true )
					|| ( '' !== $plural && ( count( $forms ) < $nplurals || ! array_is_list( $forms ) ) ) ) {
					++$counts['skipped'];
					continue;
				}
				$ref   = Babilim_Translation_Search::ref( $text['msgctxt'], $text['msgid'] );
				$entry = $cat['entries'][ $ref ] ?? null;
				$old   = $saved[ $ref ] ?? array();
				$live  = array();
				foreach ( $old as $i => $row ) {
					if ( Babilim_Overrides::STATUS_OFF !== $row['status'] ) {
						$live[ $i ] = $row['msgstr'];
					}
				}
				ksort( $live, SORT_NUMERIC );
				$key = '' === $text['msgctxt'] ? $text['msgid'] : $text['msgctxt'] . "\x04" . $text['msgid'];
				if ( array() === $live && '' !== implode( '', $pending[ $key ] ?? array() ) ) {
					$live = $pending[ $key ];
				}
				if ( $forms === ( $live ?: ( $entry['msgstr'] ?? array() ) ) ) {
					++$counts['same'];
					continue;
				}
				if ( array() !== $live || isset( $drafts[ $ref ] ) ) {
					++$counts['conflicts'];
					continue;
				}
				// Use the current source's plural text for checks when it is known.
				$plural = '' !== ( $entry['msgid_plural'] ?? '' ) ? $entry['msgid_plural'] : $plural;
				$draft  = array() !== Babilim_Override_Check::problems( $text['msgid'], $plural, $forms, $nplurals );
				$orphan = null === $entry && $cat['current'];
				if ( $write && ! $draft && ! $queued ) {
					// Persist the retry before writing, so even a timeout leaves the scope recoverable.
					$queued = $this->writer->mark_dirty( $scope );
					if ( ! $queued ) {
						$this->warning = __( 'The translation could not be saved.', 'babilim' );
						++$counts['skipped'];
						continue;
					}
				}
				if ( ! $write ) {
					$changed = true;
					if ( $draft ) {
						++$counts['unchecked'];
					} else {
						++$counts['imported'];
						$counts['orphans'] += (int) $orphan;
					}
					continue;
				}
				$restore = $draft ? array() : $old;
				$rows              = $this->rows( $component, $ref, $text, $plural, $entry, $forms, $old, $cat['current'], $orphan, $draft );
				$queue[ (int) $draft ][] = array( 'rows' => $rows, 'ref' => $ref, 'restore' => $restore, 'orphan' => $orphan );
				$queued_rows[ (int) $draft ] = ( $queued_rows[ (int) $draft ] ?? 0 ) + count( $rows );
				if ( $queued_rows[ (int) $draft ] >= Babilim_Overrides::BATCH_MAX ) {
					$this->flush( $scope, $draft, $queue, $queued_rows, $counts, $changed );
				}
			}
			foreach ( array( false, true ) as $draft ) {
				if ( ! empty( $queue[ (int) $draft ] ) ) {
					$this->flush( $scope, $draft, $queue, $queued_rows, $counts, $changed );
				}
			}
			if ( $write && $changed ) {
				$this->remap->run( $scope );
				if ( ! $this->writer->regenerate( $scope )->is_ok() ) {
					$this->warning = __( 'The translation file could not be written yet. It is tried again automatically every day.', 'babilim' );
				}
			}
		}
		return $counts;
	}

	/**
	 * Writes the queued strings of one scope and kind (draft or saved) with
	 * batched upserts. A failed batch is retried string by string with save(),
	 * which on a failure removes the string's partial rows and puts back the
	 * rows it had, so one bad string never loses another's text.
	 *
	 * @param array $queue       By draft flag: list of array{rows,ref,restore,orphan}.
	 * @param array $queued_rows By draft flag: rows queued.
	 */
	private function flush( array $scope, bool $draft, array &$queue, array &$queued_rows, array &$counts, bool &$changed ): void {
		$batch = $queue[ (int) $draft ] ?? array();
		unset( $queue[ (int) $draft ], $queued_rows[ (int) $draft ] );
		if ( array() === $batch ) {
			return;
		}
		// A string that lost plural forms is cleared first, right before its batch lands.
		$all    = array();
		$failed = array();
		foreach ( $batch as $n => $item ) {
			if ( count( $item['restore'] ) > count( $item['rows'] ) && ! $this->overrides->delete( $scope, $item['ref'] ) ) {
				$failed[ $n ] = true;
				continue;
			}
			$all = array_merge( $all, $item['rows'] );
		}
		$done = array() === $all || $this->overrides->put_many( $scope, $all, $draft );
		foreach ( $batch as $n => $item ) {
			if ( isset( $failed[ $n ] ) || ( ! $done && ! $this->save( $scope, $item, $draft ) ) ) {
				$this->warning = __( 'The translation could not be saved.', 'babilim' );
				++$counts['skipped'];
				continue;
			}
			$changed = true;
			if ( $draft ) {
				++$counts['unchecked'];
			} else {
				++$counts['imported'];
				$counts['orphans'] += (int) $item['orphan'];
			}
		}
	}

	/** One string, row by row; on failure undo it and return false. */
	private function save( array $scope, array $item, bool $draft ): bool {
		foreach ( $item['rows'] as $row ) {
			if ( ! $this->overrides->put( $scope, $row, $draft ) ) {
				$this->overrides->delete( $scope, $item['ref'], $draft );
				foreach ( $item['restore'] as $previous ) {
					$this->overrides->put( $scope, $previous );
				}
				return false;
			}
		}
		return true;
	}

	/** The rows of one string, one per plural form. */
	private function rows( array $component, string $ref, array $text, string $plural, ?array $entry, array $forms, array $old, bool $current, bool $orphan, bool $draft ): array {
		$now  = (string) ( $this->now )();
		$user = (int) ( $this->user_id )();
		$rows = array();
		foreach ( $forms as $i => $form ) {
			$was    = $old[ $i ] ?? array();
			$rows[] = array(
				'ref'               => $ref,
				'plural_index'      => $i,
				'msgctxt'           => $text['msgctxt'],
				'msgid'             => $text['msgid'],
				'msgid_plural'      => $plural,
				'msgstr'            => $form,
				'status'            => ! $draft && $orphan ? Babilim_Overrides::STATUS_ORPHAN : Babilim_Overrides::STATUS_ACTIVE,
				'origin'            => 'loco',
				'component_version' => Babilim_Editor::row_version( $current && null !== $entry, $component, $was, $old ),
				'history'           => $draft ? array() : ( $was['history'] ?? array() ),
				'feedback_build'    => $draft ? '' : ( $was['feedback_build'] ?? '' ),
				'modified_gmt'      => $now,
				'modified_by'       => $user,
			) + Babilim_Editor::base_fields( $entry, $i );
		}
		return $rows;
	}
}
