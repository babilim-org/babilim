<?php
/**
 * The translations list: one row per installed component, one line per
 * language inside the row.
 *
 * Filtering (view, language, search) is a GET form handled by the page
 * itself; the bulk form posts back to the list page and is handled in
 * the page's load action. This class only builds rows and markup.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The translations list table.
 */
final class Babilim_Translations_Table extends WP_List_Table {

	/**
	 * Rows per page. Fixed: there is no screen option.
	 */
	public const PER_PAGE = 50;

	/**
	 * Statement about free slots, shown above the list (not a question).
	 */
	public static function slot_note(): string {
		return __( 'Requesting a translation uses one of your free slots, unless this component already uses one. A failed translation does not give the slot back.', 'babilim' );
	}

	/**
	 * Sentence shown before a request that may use a free slot.
	 */
	public static function slot_sentence(): string {
		return __( 'If this component does not use one of your free slots yet, requesting a translation uses one. A failed translation does not give the slot back. Continue?', 'babilim' );
	}

	/**
	 * Builds the rows from the stored state and the installed components.
	 *
	 * @param array    $state                Stored state (Babilim_State::get()).
	 * @param array[]  $inventory_components Babilim_Inventory::components().
	 * @param callable $manifest_record      fn( kind, slug, locale ): ?array.
	 * @param bool     $can_write            Whether this user may write translation files.
	 * @return array[] Rows {kind, slug, name, version, text_domain, cells}, by name.
	 */
	public static function build_items( array $state, array $inventory_components, callable $manifest_record, bool $can_write ): array {
		$installed = array();
		foreach ( $inventory_components as $component ) {
			$installed[ (string) ( $component['kind'] ?? '' ) . ':' . (string) ( $component['slug'] ?? '' ) ] = $component;
		}
		$updates = array();
		foreach ( (array) ( $state['updates'] ?? array() ) as $update ) {
			if ( is_array( $update ) ) {
				$updates[ ( $update['kind'] ?? '' ) . ':' . ( $update['slug'] ?? '' ) . ':' . ( $update['locale'] ?? '' ) ] = (string) ( $update['build_id'] ?? '' );
			}
		}

		$items = array();
		foreach ( (array) ( $state['components'] ?? array() ) as $component ) {
			$kind = (string) ( $component['kind'] ?? '' );
			$slug = (string) ( $component['slug'] ?? '' );
			if ( ! isset( $installed[ $kind . ':' . $slug ] ) ) {
				continue;
			}
			$info  = $installed[ $kind . ':' . $slug ];
			$cells = array();
			foreach ( (array) ( $component['locales'] ?? array() ) as $locale => $entry ) {
				$locale = (string) $locale;
				$entry  = is_array( $entry ) ? $entry : array();
				$key    = $kind . ':' . $slug . ':' . $locale;
				$entry['kind'] = $kind;
				if ( isset( $updates[ $key ] ) ) {
					$entry['update_build_id'] = $updates[ $key ];
				}
				$has_update      = isset( $updates[ $key ] ) || ! empty( $entry['update_available'] );
				$record           = $manifest_record( $kind, $slug, $locale );
				$cells[ $locale ] = Babilim_Cell::from_entry( $entry, $locale, $has_update, $can_write, $record, (string) ( $info['version'] ?? '' ) );
				// What the row actions need beyond the cell itself.
				$cells[ $locale ]['previous_build_id'] = (string) ( $record['previous_build_id'] ?? '' );
				$cells[ $locale ]['can_write']         = $can_write;
				// Roll back needs a build different from the current one in
				// the history -- the rule Babilim_Installer::rollback() uses.
				$cells[ $locale ]['can_rollback'] = self::history_has_other_build( $record );
				$cells[ $locale ]['degraded']          = ! empty( $record['degraded_at'] );
			}
			$items[] = array(
				'kind'        => $kind,
				'slug'        => $slug,
				'name'        => '' !== (string) ( $info['name'] ?? '' ) ? (string) $info['name'] : $slug,
				'version'     => (string) ( $info['version'] ?? '' ),
				'text_domain' => (string) ( $info['text_domain'] ?? '' ),
				'cells'       => $cells,
			);
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['name'], $b['name'] ) ?: strcmp( $a['slug'], $b['slug'] );
			}
		);
		return $items;
	}

	/**
	 * Whether a manifest record's version_history holds a build different
	 * from the current build_id (the rule rollback() uses to pick its target).
	 *
	 * @param array|null $record Manifest record, if any.
	 * @return bool
	 */
	private static function history_has_other_build( ?array $record ): bool {
		if ( null === $record ) {
			return false;
		}
		foreach ( (array) ( $record['version_history'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && (string) ( $entry['build_id'] ?? '' ) !== (string) ( $record['build_id'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any cell of a row satisfies a view.
	 *
	 * @param array  $item Row.
	 * @param string $view all|available|updates|requestable|installed.
	 * @return bool
	 */
	private static function in_view( array $item, string $view ): bool {
		if ( 'all' === $view ) {
			return true;
		}
		foreach ( $item['cells'] as $cell ) {
			if ( 'available' === $view && 'babilim_download' === $cell['action'] && ! $cell['is_update'] ) {
				return true;
			}
			if ( 'updates' === $view && $cell['is_update'] ) {
				return true;
			}
			if ( 'requestable' === $view && 'babilim_request' === $cell['action'] ) {
				return true;
			}
			if ( 'installed' === $view && $cell['installed'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Row counts for the five views.
	 *
	 * @param array[] $items Rows from build_items().
	 * @return array<string,int>
	 */
	public static function views_counts( array $items ): array {
		$counts = array( 'all' => count( $items ) );
		foreach ( array( 'available', 'updates', 'requestable', 'installed' ) as $view ) {
			$counts[ $view ] = 0;
			foreach ( $items as $item ) {
				if ( self::in_view( $item, $view ) ) {
					++$counts[ $view ];
				}
			}
		}
		return $counts;
	}

	/**
	 * Row counts for the kind tabs (all, plugins, themes), after the
	 * language filter and the search -- the same narrowing the list
	 * itself gets -- but before the view and the tab.
	 *
	 * @param array[] $items         Rows from build_items().
	 * @param string  $locale_filter A locale code, or '' for every language.
	 * @param string  $search        Search text.
	 * @return array{all:int,plugin:int,theme:int}
	 */
	public static function kind_counts( array $items, string $locale_filter, string $search ): array {
		$counts = array( 'all' => 0, 'plugin' => 0, 'theme' => 0 );
		foreach ( self::filter_items( $items, 'all', $locale_filter, $search ) as $item ) {
			++$counts['all'];
			if ( isset( $counts[ $item['kind'] ] ) ) {
				++$counts[ $item['kind'] ];
			}
		}
		return $counts;
	}

	/**
	 * Per language: how many lines the current tab and search could
	 * download or update right now, whatever the view and the language
	 * filter (the "to install" figure next to each language link).
	 *
	 * @param array[] $items  Rows from build_items().
	 * @param string  $search Search text.
	 * @param string  $kind   plugin, theme, or '' for both.
	 * @return array<string,int>
	 */
	public static function locale_action_counts( array $items, string $search, string $kind ): array {
		$counts = array();
		foreach ( self::filter_items( $items, 'all', '', $search, $kind ) as $item ) {
			foreach ( $item['cells'] as $locale => $cell ) {
				$locale = (string) $locale;
				if ( ! isset( $counts[ $locale ] ) ) {
					$counts[ $locale ] = 0;
				}
				if ( 'babilim_download' === $cell['action'] ) {
					++$counts[ $locale ];
				}
			}
		}
		return $counts;
	}

	/**
	 * Narrows rows by view, language, search text and kind.
	 *
	 * @param array[] $items         Rows.
	 * @param string  $view          View.
	 * @param string  $locale_filter A locale code, or '' for every language.
	 * @param string  $search        Text matched against name and slug.
	 * @param string  $kind          plugin, theme, or '' for both.
	 * @return array[]
	 */
	public static function filter_items( array $items, string $view, string $locale_filter, string $search, string $kind = '' ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( '' !== $kind && $item['kind'] !== $kind ) {
				continue;
			}
			if ( '' !== $locale_filter ) {
				$item['cells'] = array_intersect_key( $item['cells'], array( $locale_filter => true ) );
				if ( array() === $item['cells'] ) {
					continue;
				}
			}
			if ( ! self::in_view( $item, $view ) ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $item['name'] . ' ' . $item['slug'], $search ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Currently selected view.
	 *
	 * @var string
	 */
	private $view;

	/**
	 * Selected language filter, '' for all.
	 *
	 * @var string
	 */
	private $locale_filter;

	/**
	 * Search text.
	 *
	 * @var string
	 */
	private $search;

	/**
	 * Selected tab: plugin, theme, or '' for every kind.
	 *
	 * @var string
	 */
	private $kind = '';

	/**
	 * Counts per tab (Babilim_Translations_Table::kind_counts()).
	 *
	 * @var array<string,int>
	 */
	private $kind_counts = array();

	/**
	 * Counts per view (computed inside the current tab, after the language filter and the search).
	 *
	 * @var array<string,int>
	 */
	private $counts;

	/**
	 * Whether this site is on the free plan (requests may use a slot).
	 *
	 * @var bool
	 */
	private $free;

	/**
	 * Whether the one-time upgrade is on offer, so a cell the license
	 * blocks gets an Upgrade button.
	 *
	 * @var bool
	 */
	private $upgrade = false;

	/**
	 * Every locale any row lists, for the language links.
	 *
	 * @var string[]
	 */
	private $all_locales = array();

	/**
	 * Lines to download or update per language (locale_action_counts()).
	 *
	 * @var array<string,int>
	 */
	private $locale_counts = array();

	/**
	 * Single-cell forms collected while the rows render.
	 *
	 * @var string[]
	 */
	private $cell_forms = array();

	/**
	 * Row-action forms (roll back, remove) collected while rendering rows.
	 *
	 * @var string[]
	 */
	private $row_forms = array();

	/**
	 * Constructor.
	 *
	 * @param array[]             $items         Filtered rows.
	 * @param array<string,int>   $counts        views_counts() of the rows of the current tab, narrowed by language and search.
	 * @param string              $view          Current view.
	 * @param string              $locale_filter Selected language or ''.
	 * @param string              $search        Search text.
	 * @param bool                $free          Whether the site is on the free plan.
	 * @param string[]            $all_locales   Every language of the rows of every tab.
	 * @param bool                $upgrade       Whether the one-time upgrade is on offer (adds an Upgrade button to cells the license blocks).
	 * @param string              $kind          Selected tab: plugin, theme or '' for every kind.
	 * @param array<string,int>   $kind_counts   kind_counts() for the tabs.
	 * @param array<string,int>   $locale_counts locale_action_counts() for the language links.
	 */
	public function __construct( array $items, array $counts, string $view, string $locale_filter, string $search, bool $free = false, array $all_locales = array(), bool $upgrade = false, string $kind = '', array $kind_counts = array(), array $locale_counts = array() ) {
		parent::__construct( array( 'plural' => 'translations', 'singular' => 'translation', 'ajax' => false ) );
		$this->locale_counts = $locale_counts;
		$this->upgrade       = $upgrade;
		$this->items         = $items;
		$this->counts        = $counts;
		$this->view          = $view;
		$this->locale_filter = $locale_filter;
		$this->search        = $search;
		$this->free          = $free;
		$this->all_locales   = $all_locales;
		$this->kind          = $kind;
		$this->kind_counts   = $kind_counts;
	}

	/**
	 * The empty-list message: with a filter, say so and offer a way
	 * out that keeps only the current tab; without one, say nothing
	 * was found.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( '' === $this->search && '' === $this->locale_filter && ( '' === $this->view || 'all' === $this->view ) ) {
			esc_html_e( 'No plugins or themes found.', 'babilim' );
			return;
		}
		$link = '<a href="' . esc_url( add_query_arg( array( 'page' => 'babilim', 'babilim_kind' => '' === $this->kind ? false : rawurlencode( $this->kind ) ), admin_url( 'admin.php' ) ) ) . '">'
			. esc_html__( 'Clear filters', 'babilim' ) . '</a>';
		/* translators: %s: a "Clear filters" link. */
		echo wp_kses_post( sprintf( esc_html__( 'No components match these filters. %s', 'babilim' ), $link ) );
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" aria-label="' . esc_attr__( 'Select all', 'babilim' ) . '" />',
			'component'    => __( 'Component', 'babilim' ),
			'translations' => __( 'Translations', 'babilim' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	public function get_bulk_actions() {
		return array(
			'download' => __( 'Download', 'babilim' ),
			'update'   => __( 'Update', 'babilim' ),
			'request'  => __( 'Request translation', 'babilim' ),
		);
	}

	/**
	 * The five views as links that keep the search and language.
	 *
	 * @return array<string,string>
	 */
	public function get_views() {
		$labels = array(
			'all'         => __( 'All', 'babilim' ),
			'available'   => __( 'Available', 'babilim' ),
			'updates'     => __( 'Updates', 'babilim' ),
			'requestable' => __( 'Requestable', 'babilim' ),
			'installed'   => __( 'Installed', 'babilim' ),
		);
		$views  = array();
		foreach ( $labels as $key => $label ) {
			$url = $this->list_url( 'all' === $key ? '' : $key, $this->kind );
			$views[ $key ] = '<a href="' . esc_url( $url ) . '"' . ( $key === $this->view ? ' class="current" aria-current="page"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . (int) ( $this->counts[ $key ] ?? 0 ) . ')</span></a>';
		}
		return $views;
	}

	/**
	 * The list's address for a view and a tab, keeping the search and
	 * the language.
	 *
	 * @param string $view View key, or '' for All.
	 * @param string $kind plugin, theme, or '' for every kind.
	 * @param string|null $locale Language to link to; null keeps the current one.
	 * @return string
	 */
	private function list_url( string $view, string $kind, ?string $locale = null ): string {
		$locale = $locale ?? $this->locale_filter;
		// add_query_arg() does not encode values; an unencoded & or #
		// in the search would cut the address short.
		return add_query_arg(
			array(
				'page'           => 'babilim',
				'view'           => '' === $view ? false : rawurlencode( $view ),
				's'              => '' === $this->search ? false : rawurlencode( $this->search ),
				'babilim_locale' => '' === $locale ? false : rawurlencode( $locale ),
				'babilim_kind'   => '' === $kind ? false : rawurlencode( $kind ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The All / Plugins / Themes tabs. Switching keeps the view, the
	 * language and the search.
	 *
	 * @return string
	 */
	public function kind_tabs(): string {
		$tabs = array(
			''       => __( 'All', 'babilim' ),
			'plugin' => __( 'Plugins', 'babilim' ),
			'theme'  => __( 'Themes', 'babilim' ),
		);
		$html = '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $kind => $label ) {
			$count = (int) ( $this->kind_counts[ '' === $kind ? 'all' : $kind ] ?? 0 );
			$html .= '<a class="nav-tab' . ( $kind === $this->kind ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->list_url( 'all' === $this->view ? '' : $this->view, $kind ) ) . '"'
				. ( $kind === $this->kind ? ' aria-current="page"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . $count . ')</span></a>';
		}
		return $html . '</h2>';
	}

	/**
	 * The language links under the views; '' with fewer than two languages.
	 * Each link keeps the view, the search and the tab and swaps the
	 * language; the current choice is bold text, not a link.
	 *
	 * @return string
	 */
	public function locale_links(): string {
		if ( count( $this->all_locales ) < 2 ) {
			return '';
		}
		$parts = array();
		foreach ( array_merge( array( '' ), $this->all_locales ) as $locale ) {
			$name    = '' === $locale ? __( 'All languages', 'babilim' ) : Babilim_Locale_Label::name( $locale );
			$n       = (int) ( $this->locale_counts[ $locale ] ?? 0 );
			/* translators: %d: number of translations ready to download or update for a language. */
			$pending = $n > 0 ? sprintf( _n( '%d to install', '%d to install', $n, 'babilim' ), $n ) : '';
			$label   = esc_html( $name ) . ( '' === $pending ? '' : ' <span class="count">(' . esc_html( $pending ) . ')</span>' );
			$title   = '' === $locale ? '' : ' title="' . esc_attr( $locale ) . '"';
			if ( $locale === $this->locale_filter ) {
				$parts[] = '<strong aria-current="true"' . $title . '>' . $label . '</strong>';
			} else {
				$parts[] = '<a href="' . esc_url( $this->list_url( 'all' === $this->view ? '' : $this->view, $this->kind, $locale ) ) . '"' . $title . '>' . $label . '</a>';
			}
		}
		$intro = esc_html__( 'Language:', 'babilim' );
		return '<p class="babilim-locale-links">' . $intro . ' ' . implode( ' | ', $parts ) . '</p>';
	}

	/**
	 * Sets paging and the page's rows.
	 */
	public function prepare_items() {
		// Core reads the columns from a per-screen cache that the admin
		// header fills before this page is drawn, so a table built while
		// drawing would find no columns there. Set them directly.
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'component' );
		$total  = count( $this->items );
		$paged  = $this->get_pagenum();
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = min( $paged, $pages );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'total_pages' => $pages,
				'per_page'    => self::PER_PAGE,
			)
		);
		$this->items = array_slice( $this->items, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
	}

	/**
	 * Scope note above and below the list.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$note = '' === $this->locale_filter
			? __( 'Bulk actions apply to every matching language of each selected component.', 'babilim' )
			: __( 'Bulk actions apply only to the selected language of each selected component.', 'babilim' );
		echo '<p class="description">' . esc_html( $note ) . ( $this->free ? ' ' . esc_html( self::slot_note() ) : '' ) . '</p>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="components[]" value="' . esc_attr( $item['kind'] . ':' . $item['slug'] ) . '" aria-label="' . esc_attr( $item['name'] ) . '" />';
	}

	/**
	 * Component column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_component( $item ) {
		if ( '' !== $this->kind ) {
			// Inside a Plugins or Themes tab the kind is already known.
			/* translators: %s: the component's version number. */
			$label = sprintf( __( 'Version %s', 'babilim' ), $item['version'] );
		} elseif ( 'theme' === $item['kind'] ) {
			/* translators: %s: the theme's version number. */
			$label = sprintf( __( 'Theme · %s', 'babilim' ), $item['version'] );
		} else {
			/* translators: %s: the plugin's version number. */
			$label = sprintf( __( 'Plugin · %s', 'babilim' ), $item['version'] );
		}
		return '<strong>' . esc_html( $item['name'] ) . '</strong><br /><span class="description">' . esc_html( $label ) . '</span>' . $this->component_row_actions( $item );
	}

	/**
	 * What each row action can do for one component, per language.
	 *
	 * @param array $item Row.
	 * @return array{installed:int,rollback:array,remove:array,rollback-report:array,report:array} `installed` counts the languages
	 *         that have a file or a record on this site; each other key maps a language code to its cell.
	 */
	public static function row_targets( array $item ): array {
		$out = array(
			'installed'       => 0,
			'rollback'        => array(),
			'remove'          => array(),
			'rollback-report' => array(),
			'report'          => array(),
		);
		foreach ( $item['cells'] as $locale => $cell ) {
			$locale = (string) $locale;
			// A degraded cell is not installed, but its record can still be
			// removed or rolled back.
			if ( ! $cell['installed'] && empty( $cell['degraded'] ) ) {
				continue;
			}
			++$out['installed'];
			$writable = ! empty( $cell['can_write'] );
			if ( $writable ) {
				if ( ! empty( $cell['can_rollback'] ) ) {
					$out['rollback'][ $locale ] = $cell;
				}
				$out['remove'][ $locale ] = $cell;
			}
			if ( '' !== $cell['title'] ) {
				if ( $writable && ! empty( $cell['can_rollback'] ) ) {
					$out['rollback-report'][ $locale ] = $cell;
				}
				$out['report'][ $locale ] = $cell;
			}
		}
		return $out;
	}

	/**
	 * The question asked before a roll back or a removal.
	 *
	 * @param string $action    babilim_remove or babilim_rollback.
	 * @param string $component Component name.
	 * @param string $locale    Language code.
	 * @return string
	 */
	public static function confirm_question( string $action, string $component, string $locale ): string {
		$name = Babilim_Locale_Label::name( $locale );
		if ( 'babilim_remove' === $action ) {
			/* translators: 1: component name, 2: language name. */
			return sprintf( __( 'Remove the %2$s translation of %1$s from this site?', 'babilim' ), $component, $name );
		}
		/* translators: 1: component name, 2: language name. */
		return sprintf( __( 'Roll back the %2$s translation of %1$s to the previous build?', 'babilim' ), $component, $name );
	}

	/**
	 * The hidden fields and nonce of one roll back or remove POST. A row
	 * button and the language picker send exactly these.
	 *
	 * @param string $action   admin-post action.
	 * @param array  $identity kind, slug, locale.
	 * @param array  $back     Where the list goes back to: s, babilim_locale, babilim_kind.
	 * @return string
	 */
	public static function post_fields( string $action, array $identity, array $back ): string {
		$html = '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="kind" value="' . esc_attr( $identity['kind'] ) . '" />'
			. '<input type="hidden" name="slug" value="' . esc_attr( $identity['slug'] ) . '" />'
			. '<input type="hidden" name="locale" value="' . esc_attr( $identity['locale'] ) . '" />'
			. '<input type="hidden" name="s" value="' . esc_attr( (string) ( $back['s'] ?? '' ) ) . '" />'
			. '<input type="hidden" name="babilim_locale" value="' . esc_attr( (string) ( $back['babilim_locale'] ?? '' ) ) . '" />';
		if ( '' !== (string) ( $back['babilim_kind'] ?? '' ) ) {
			$html .= '<input type="hidden" name="babilim_kind" value="' . esc_attr( (string) $back['babilim_kind'] ) . '" />';
		}
		return $html . wp_nonce_field( $action, '_wpnonce', false, false );
	}

	/**
	 * The list's state that every row link and form carries along.
	 *
	 * @return array<string,string>
	 */
	private function back_args(): array {
		return array(
			's'              => $this->search,
			'babilim_locale' => $this->locale_filter,
			'babilim_kind'   => $this->kind,
			'view'           => 'all' === $this->view ? '' : $this->view,
		);
	}

	/**
	 * Row actions of one component: roll back (only with a previous
	 * build), remove, and report. With one language installed each acts
	 * on it directly; with several, each shows once and opens the
	 * language picker. Without the network-wide write capability only
	 * the report links stay.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	private function component_row_actions( array $item ): string {
		$targets = self::row_targets( $item );
		$labels  = array(
			'rollback'        => __( 'Roll back', 'babilim' ),
			'remove'          => __( 'Remove', 'babilim' ),
			'rollback-report' => __( 'Roll back and report', 'babilim' ),
			'report'          => __( 'Report a problem', 'babilim' ),
		);
		$actions = array();
		foreach ( $labels as $op => $label ) {
			if ( array() === $targets[ $op ] ) {
				continue;
			}
			if ( $targets['installed'] > 1 ) {
				$actions[ $op ] = $this->choose_link( $op, $item, $label );
				continue;
			}
			$locale   = (string) array_key_first( $targets[ $op ] );
			$cell     = $targets[ $op ][ $locale ];
			$identity = array( 'kind' => $item['kind'], 'slug' => $item['slug'], 'locale' => $locale );
			if ( 'rollback' === $op ) {
				$actions[ $op ] = $this->row_button( 'babilim_rollback', $identity, $label, $locale, $item['name'] );
			} elseif ( 'remove' === $op ) {
				$actions[ $op ] = $this->row_button( 'babilim_remove', $identity, $label, $locale, $item['name'] );
			} else {
				$report = $identity + array( 'build_id' => $cell['title'] );
				$actions[ $op ] = $this->row_link( 'rollback-report' === $op ? $report + array( 'rollback' => '1' ) : $report, $label, $locale, $item['name'] );
			}
		}
		return array() === $actions ? '' : $this->row_actions( $actions );
	}

	/**
	 * The accessible name of a row action: what it does and to which
	 * component, plus the language when the action targets just one.
	 *
	 * @param string $label     Visible text.
	 * @param string $component Component name.
	 * @param string $locale    Language code, or '' when the action asks for the language.
	 * @return string
	 */
	private static function action_aria_label( string $label, string $component, string $locale = '' ): string {
		if ( '' === $locale ) {
			/* translators: 1: action name such as Remove, 2: component name. */
			return sprintf( __( '%1$s: %2$s', 'babilim' ), $label, $component );
		}
		/* translators: 1: action name such as Remove, 2: component name, 3: language name. */
		return sprintf( __( '%1$s: %2$s (%3$s)', 'babilim' ), $label, $component, Babilim_Locale_Label::name( $locale ) );
	}

	/**
	 * A link to the language picker for one action of one component.
	 *
	 * @param string $op    rollback, remove, report or rollback-report.
	 * @param array  $item  Row.
	 * @param string $label Link text.
	 * @return string
	 */
	private function choose_link( string $op, array $item, string $label ): string {
		$args = array( 'page' => 'babilim', 'screen' => 'choose', 'op' => $op, 'kind' => $item['kind'], 'slug' => $item['slug'] );
		foreach ( $this->back_args() as $key => $value ) {
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		return '<a href="' . esc_url( admin_url( 'admin.php?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) ) ) . '" aria-label="' . esc_attr( self::action_aria_label( $label, (string) $item['name'] ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * A link to the report page for one language.
	 *
	 * @param array  $args   Query args.
	 * @param string $label  Link text.
	 * @param string $locale Language code.
	 * @param string $component Component name.
	 * @return string
	 */
	private function row_link( array $args, string $label, string $locale, string $component ): string {
		$url = admin_url( 'admin.php?' . http_build_query( array( 'page' => 'babilim', 'screen' => 'report' ) + $args, '', '&', PHP_QUERY_RFC3986 ) );
		return '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $locale ) . '" aria-label="' . esc_attr( self::action_aria_label( $label, $component, $locale ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * A POST button for one language. Its form is printed outside the
	 * table (forms cannot nest); with JavaScript it asks and then sends
	 * confirmed=1, without it the action shows a confirmation page.
	 *
	 * @param string $action    admin-post action.
	 * @param array  $identity  kind, slug, locale.
	 * @param string $label     Button text.
	 * @param string $locale    Language code.
	 * @param string $component Component name.
	 * @return string
	 */
	private function row_button( string $action, array $identity, string $label, string $locale, string $component ): string {
		$id = 'babilim-row-' . ( count( $this->row_forms ) + 1 );
		$this->row_forms[] = '<form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. self::post_fields( $action, $identity, $this->back_args() )
			. '</form>';
		return '<button type="submit" form="' . esc_attr( $id ) . '" class="button-link" title="' . esc_attr( $locale ) . '" aria-label="' . esc_attr( self::action_aria_label( $label, $component, $locale ) ) . '" data-babilim-confirm="' . esc_attr( self::confirm_question( $action, $component, $locale ) ) . '" data-babilim-confirmed="1">' . esc_html( $label ) . '</button>';
	}

	/**
	 * The status text as HTML. A text that ends with a date keeps the date
	 * and the separator before it in one piece that moves to the next line
	 * as a whole when the line is too narrow (and wraps inside itself only
	 * when it is wider than the column), so the separator is never left at
	 * the end of a line.
	 *
	 * @param string $text Status text.
	 * @param string $date The date the text ends with, '' when none.
	 * @return string Escaped HTML.
	 */
	private static function status_html( string $text, string $date ): string {
		$len = strlen( $date );
		if ( '' === $date || strlen( $text ) <= $len || substr( $text, -$len ) !== $date ) {
			return esc_html( $text );
		}
		// Split "head, optional space, separator, optional space, date" as
		// the translation wrote it; whitespace is only put back where it
		// already was.
		if ( 1 !== preg_match( '/^(.*?)(\s*)([^\p{L}\p{M}\p{N}\s])(\s*)$/su', substr( $text, 0, -$len ), $m ) ) {
			return esc_html( $text );
		}
		// A no-break space keeps the separator with the first word of the date.
		$tail = $m[3] . ( '' === $m[4] ? '' : "\u{00A0}" ) . $date;
		return esc_html( $m[1] . $m[2] ) . '<span class="babilim-cell-tail">' . esc_html( $tail ) . '</span>';
	}

	/**
	 * Translations column: one line per language -- the language name and
	 * the status text (together in one block) and the action cell. Wide
	 * cells show three columns that all the lines of the cell share, so
	 * every status starts at the same x; a cell too narrow for that puts the name
	 * above the status with the button still at the right; below the
	 * WordPress breakpoint, or in a cell too narrow even for two columns,
	 * everything stacks. The button always sits at the right edge
	 * whenever it is beside the text, so every line's button lines up.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_translations( $item ) {
		$html = '<div class="babilim-cell-list"><div class="babilim-cell-grid">';
		foreach ( $item['cells'] as $locale => $cell ) {
			$locale = (string) $locale;
			$html  .= '<div class="babilim-cell-row"><div class="babilim-cell-line">'
				. '<span class="babilim-cell-text">'
				. '<span class="babilim-cell-lang" title="' . esc_attr( $locale ) . '">' . esc_html( Babilim_Locale_Label::name( $locale ) ) . '</span>'
				. '<span class="babilim-cell-status" title="' . esc_attr( $cell['title'] ) . '">' . self::status_html( $cell['text'], (string) ( $cell['date'] ?? '' ) ) . '</span>'
				. '</span>'
				. '<span class="babilim-cell-action">' . $this->cell_button( $item, $locale, $cell ) . '</span>'
				. '</div>'
				. ( '' === ( $cell['hardcoded_warning'] ?? '' ) ? '' : '<div class="description babilim-cell-warning">' . esc_html( $cell['hardcoded_warning'] ) . '</div>' )
				. ( 'network_capability' === $cell['blocked_reason'] ? '<div class="description babilim-cell-warning">' . esc_html( Babilim_Admin::network_wide_action_text() ) . '</div>' : '' )
				. '</div>';
		}
		return $html . '</div></div>';
	}

	/**
	 * The content of one cell's action cell. A runnable action gets its
	 * own form, collected for cell_forms(); a cell that cannot run
	 * anything gets nothing (no disabled button), except the upgrade
	 * link when the license is what blocks it.
	 *
	 * @param array  $item   Row.
	 * @param string $locale Locale.
	 * @param array  $cell   Cell from Babilim_Cell::from_entry().
	 * @return string
	 */
	private function cell_button( array $item, string $locale, array $cell ): string {
		if ( 'network_capability' === $cell['blocked_reason'] ) {
			// Explained under the line (see column_translations()).
			return '';
		}
		if ( null === $cell['action'] ) {
			if ( $this->upgrade && in_array( $cell['blocked_reason'], array( 'license_required', 'quota_exceeded' ), true ) ) {
				return '<button type="submit" form="babilim-checkout-form" class="button-link">' . esc_html__( 'Upgrade this site', 'babilim' ) . '</button>';
			}
			return '';
		}

		$id = 'babilim-cell-' . ( count( $this->cell_forms ) + 1 );
		$this->cell_forms[] = '<form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $cell['action'] ) . '" />'
			. '<input type="hidden" name="kind" value="' . esc_attr( $item['kind'] ) . '" />'
			. '<input type="hidden" name="slug" value="' . esc_attr( $item['slug'] ) . '" />'
			. '<input type="hidden" name="locale" value="' . esc_attr( $locale ) . '" />'
			. '<input type="hidden" name="s" value="' . esc_attr( $this->search ) . '" />'
			. '<input type="hidden" name="babilim_locale" value="' . esc_attr( $this->locale_filter ) . '" />'
			. $this->kind_field()
			. wp_nonce_field( $cell['action'], '_wpnonce', false, false )
			. '</form>';

		$confirm = $this->free && 'babilim_request' === $cell['action'];
		$html    = '<button type="submit" form="' . esc_attr( $id ) . '" class="button" data-babilim-idle-label="' . esc_attr( $cell['action_label'] ) . '" data-babilim-busy-label="' . esc_attr( $cell['busy_label'] ) . '"'
			. ( $confirm ? ' data-babilim-confirm="' . esc_attr( self::slot_sentence() ) . '"' : '' ) . '>'
			. esc_html( $cell['action_label'] ) . '</button>';
		return $html;
	}

	/**
	 * The hidden field that carries the tab through a form, or ''.
	 *
	 * @return string
	 */
	public function kind_field(): string {
		return '' === $this->kind ? '' : '<input type="hidden" name="babilim_kind" value="' . esc_attr( $this->kind ) . '" />';
	}

	/**
	 * The single-cell forms, to print after the bulk form (forms cannot nest).
	 *
	 * @return string
	 */
	public function cell_forms(): string {
		return implode( '', $this->cell_forms );
	}

	/**
	 * The row-action forms (roll back, remove), printed outside the table.
	 *
	 * @return string
	 */
	public function row_forms(): string {
		return implode( '', $this->row_forms );
	}
}
