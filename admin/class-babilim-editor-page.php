<?php
/**
 * The translation editor screen: with no component chosen, a list of every
 * installed plugin and theme times every language the site uses; with one
 * chosen, the workbench skeleton that admin/js/babilim-editor.js fills in.
 *
 * Why: the list comes from local data only (no request to the service, no
 * consent needed), the editor's work is done by the REST routes of
 * Babilim_Editor_Rest, so this class only turns the request's names into a
 * scope (Babilim_Editor::scope() is the one place that validates them),
 * prints static markup, builds the settings object the script reads
 * (window.babilimEditor) and owns the workbench's stylesheet (css(): an
 * inline string, since the plugin ships no .css file, added by
 * Babilim_Admin only on this screen). The settings carry the REST address,
 * a `wp_rest` nonce, the scope, the language's name and the strings the
 * script shows -- never the site token and never a file path. Everything
 * printed goes through esc_*; the caller runs the result through
 * wp_kses( ..., Babilim_Admin::allowed_admin_html() ).
 *
 * @package Babilim
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup and script settings of the editor page.
 */
final class Babilim_Editor_Page {

	const SLUG = 'babilim-editor';

	/**
	 * Address of the editor, for a component and language and optionally one string.
	 *
	 * @param string $kind   'plugin'|'theme'.
	 * @param string $slug   Component slug.
	 * @param string $locale Locale code.
	 * @param string $ref    Babilim_Translation_Search::ref(), or ''.
	 */
	public static function url( string $kind, string $slug, string $locale, string $ref = '' ): string {
		$query = 'page=' . self::SLUG . '&kind=' . rawurlencode( $kind ) . '&slug=' . rawurlencode( $slug ) . '&locale=' . rawurlencode( $locale );
		if ( '' !== $ref ) {
			$query .= '&ref=' . rawurlencode( $ref );
		}
		return admin_url( 'admin.php?' . $query );
	}

	/**
	 * The page body for the current request.
	 *
	 * @param Babilim_Editor $editor Editor service.
	 * @param callable|null  $loco_factory Local import service factory.
	 * @param callable|null  $notices List notice renderer.
	 */
	public static function render( Babilim_Editor $editor, ?callable $loco_factory = null, ?callable $notices = null ): string {
		$q = self::query();
		if ( '' === $q['kind'] && '' === $q['slug'] && '' === $q['locale'] ) {
			$loco = null === $loco_factory ? null : $loco_factory();
			if ( null !== $loco && 'preview' === $q['loco'] ) {
				$counts = $loco->preview();
				return self::loco_preview( $counts, $loco->warning() );
			}
			$offered = null === $loco ? 0 : $loco->offered();
			$notice  = null === $notices ? '' : $notices();
			if ( null !== $loco && '' !== $loco->warning() ) {
				$notice .= '<div class="notice notice-warning inline"><p>' . esc_html( $loco->warning() ) . '</p></div>';
			}
			return self::render_list( $editor, $notice, $offered );
		}
		$scope = self::resolve( $editor, $q );
		if ( null === $scope ) {
			return self::render_invalid();
		}
		$editor->open( $scope );
		return self::render_skeleton( $editor, $scope );
	}

	/**
	 * What window.babilimEditor holds for the current request, or null when
	 * there is no valid scope (the list needs no script).
	 *
	 * @param Babilim_Editor $editor Editor service.
	 * @return array<string,mixed>|null
	 */
	public static function settings( Babilim_Editor $editor ): ?array {
		$q     = self::query();
		$scope = self::resolve( $editor, $q );
		if ( null === $scope ) {
			return null;
		}
		$out = array(
			'restUrl'  => rest_url( 'babilim/v1/' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'nonceUrl' => admin_url( 'admin-ajax.php?action=rest-nonce' ),
			'translationsUrl' => admin_url( 'admin.php?page=' . Babilim_Admin::MENU_SLUG ),
			'scope'    => $scope,
			'nplurals' => Babilim_Plural_Forms::nplurals( Babilim_Plural_Forms::header( $scope['locale'] ) ),
			'localeName' => Babilim_Locale_Label::display_name( $scope['locale'] ),
			'component'  => array( 'name' => '', 'version' => '' ),
		);
		$out['localeName'] = Babilim_Locale_Label::display_names( self::locales_of( $editor, $scope ) )[ $scope['locale'] ] ?? $out['localeName'];
		foreach ( $editor->components() as $c ) {
			if ( $c['kind'] === $scope['kind'] && $c['slug'] === $scope['slug'] ) {
				$out['component'] = array(
					'name'    => '' !== $c['name'] ? $c['name'] : $c['slug'],
					'version' => $c['version'],
				);
			}
		}
		if ( self::is_ref( $q['ref'] ) ) {
			$out['ref'] = $q['ref'];
		}
		$out['strings'] = self::strings();
		return $out;
	}

	/**
	 * @return array{kind:string,slug:string,domain:string,locale:string,ref:string,loco:string}
	 */
	private static function query(): array {
		$get = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; every write goes through the REST routes with a nonce.
		};
		$kind = $get( 'kind' );
		$slug = $get( 'slug' );
		$pick = explode( ':', $get( 'component' ), 2 );
		if ( 2 === count( $pick ) ) { // the scope form (Open button) sends "kind:slug" in one field.
			list( $kind, $slug ) = $pick;
		}
		return array(
			'kind'   => $kind,
			'slug'   => $slug,
			'domain' => $get( 'domain' ),
			'locale' => $get( 'locale' ),
			'ref'    => $get( 'ref' ),
			'loco'   => $get( 'loco' ),
		);
	}

	/**
	 * The languages the open component is offered in (at least the open one).
	 *
	 * @param Babilim_Editor $editor Editor service.
	 * @param array          $scope  Scope.
	 * @return string[]
	 */
	private static function locales_of( Babilim_Editor $editor, array $scope ): array {
		$locales = array( $scope['locale'] );
		foreach ( $editor->components() as $c ) {
			if ( $c['kind'] === $scope['kind'] && $c['slug'] === $scope['slug'] ) {
				$locales = Babilim_Locale_Label::sorted( $c['locales'] );
			}
		}
		return $locales;
	}

	private static function is_ref( string $ref ): bool {
		return 1 === preg_match( '/^[0-9a-f]{64}$/D', $ref );
	}

	/**
	 * @param Babilim_Editor $editor Editor service.
	 * @param array          $q      query().
	 * @return array{kind:string,slug:string,domain:string,locale:string}|null
	 */
	private static function resolve( Babilim_Editor $editor, array $q ): ?array {
		$domain = $q['domain'];
		if ( '' === $domain && self::is_ref( $q['ref'] ) ) {
			$domain = $editor->domain_of( $q['kind'], $q['slug'], $q['locale'], $q['ref'] );
		}
		return $editor->scope( $q['kind'], $q['slug'], $domain, $q['locale'] );
	}

	/**
	 * @param array<string,int> $counts Import counters.
	 * @return string[]
	 */
	public static function loco_counts( array $counts ): array {
		return array(
			sprintf( /* translators: %s: Number of files. */ __( 'Files: %s.', 'babilim' ), number_format_i18n( $counts['files'] ) ),
			sprintf( /* translators: %s: Number of strings imported. */ __( 'Imported: %s.', 'babilim' ), number_format_i18n( $counts['imported'] ) ),
			sprintf( /* translators: %s: Number of strings with identical translations. */ __( 'Already identical: %s.', 'babilim' ), number_format_i18n( $counts['same'] ) ),
			sprintf( /* translators: %s: Number of strings whose existing translation or draft was kept. */ __( 'Kept your existing translation: %s.', 'babilim' ), number_format_i18n( $counts['conflicts'] ) ),
			sprintf( /* translators: %s: Number of imported strings missing from the current list. */ __( 'Imported, but no longer used in this version: %s.', 'babilim' ), number_format_i18n( $counts['orphans'] ) ),
			sprintf( /* translators: %s: Number of strings kept as drafts. */ __( 'Kept as drafts to fix: %s.', 'babilim' ), number_format_i18n( $counts['unchecked'] ) ),
			sprintf( /* translators: %s: Number of strings skipped. */ __( 'Skipped: %s.', 'babilim' ), number_format_i18n( $counts['skipped'] ) ),
		);
	}

	private static function loco_preview( array $counts, string $warning ): string {
		$html = '<div class="wrap" data-babilim-dialog="1"><h1>' . esc_html__( 'Review the import', 'babilim' ) . '</h1>';
		if ( '' !== $warning ) {
			$html .= '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
		}
		$html .= '<ul>';
		foreach ( self::loco_counts( $counts ) as $line ) {
			$html .= '<li>' . esc_html( $line ) . '</li>';
		}
		$html .= '</ul><p>' . esc_html__( "Loco Translate's files are not changed. Deactivate Loco Translate yourself when you are ready.", 'babilim' ) . '</p>';
		if ( $counts['files'] > 0 ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="babilim_loco_import" />'
				. wp_nonce_field( 'babilim_loco_import', '_wpnonce', true, false )
				. '<button type="submit" class="button button-primary">' . esc_html__( 'Import from Loco Translate', 'babilim' ) . '</button></form>';
		}
		return $html . '</div>';
	}

	private static function render_list( Babilim_Editor $editor, string $notices, int $loco_files ): string {
		$html = '<div class="wrap"><h1>' . esc_html__( 'Edit translations', 'babilim' ) . '</h1><hr class="wp-header-end" />' . $notices;
		if ( $loco_files > 0 ) {
			/* translators: %s: Number of Loco Translate files. */
			$message = sprintf( _n( 'Found %s Loco Translate file you can import.', 'Found %s Loco Translate files you can import.', $loco_files, 'babilim' ), number_format_i18n( $loco_files ) );
			$html .= '<div class="notice notice-info inline"><p>' . esc_html( $message ) . ' <a data-babilim-dialog-link="1" href="'
				. esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&loco=preview' ) ) . '">' . esc_html__( 'Review the import', 'babilim' ) . '</a></p></div>';
		}
		$rows   = '';
		$review = $editor->review_counts();
		foreach ( $editor->components() as $c ) {
			$kind = 'theme' === $c['kind'] ? __( 'Theme', 'babilim' ) : __( 'Plugin', 'babilim' );
			$name = '' !== $c['name'] ? $c['name'] : $c['slug'];
			$names = Babilim_Locale_Label::display_names( $c['locales'] );
			foreach ( $c['locales'] as $locale ) {
				$label = $names[ $locale ];
				$wait  = (int) ( $review[ $c['kind'] . '/' . $c['slug'] . '/' . $locale ] ?? 0 );
				$rows .= '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $kind ) . '</td><td>' . esc_html( $label ) . '</td><td>'
					. '<a href="' . esc_url( self::url( $c['kind'], $c['slug'], $locale ) ) . '" aria-label="' . esc_attr(
						/* translators: 1: plugin or theme name, 2: language name */
						sprintf( __( 'Edit translations: %1$s, %2$s', 'babilim' ), $name, $label )
					) . '">' . esc_html__( 'Edit translations', 'babilim' ) . '</a>'
					. ( $wait > 0 ? ' <span class="description">' . esc_html( sprintf( /* translators: %d: number of strings whose original text changed. */ _n( '%d change to review', '%d changes to review', $wait, 'babilim' ), $wait ) ) . '</span>' : '' ) . '</td></tr>';
			}
		}
		if ( '' === $rows ) {
			return $html . '<p>' . esc_html__( 'There is nothing to edit yet. Install a plugin or theme, or add a language, and it shows up here.', 'babilim' ) . '</p></div>';
		}
		return $html . '<p>' . esc_html__( 'Choose a plugin or theme and a language to edit its translation.', 'babilim' ) . '</p>'
			. '<div class="babilim-scroll"><table class="widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Name', 'babilim' ) . '</th><th scope="col">' . esc_html__( 'Type', 'babilim' ) . '</th><th scope="col">' . esc_html__( 'Language', 'babilim' ) . '</th><th scope="col">' . esc_html__( 'Edit', 'babilim' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
	}

	private static function render_invalid(): string {
		return '<div class="wrap"><h1>' . esc_html__( 'Edit translations', 'babilim' ) . '</h1><hr class="wp-header-end" />'
			. '<p>' . esc_html__( 'That plugin, theme, text domain or language is not available here.', 'babilim' ) . '</p>'
			. self::back_link() . '</div>';
	}

	private static function back_link(): string {
		return '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Back to all plugins and themes', 'babilim' ) . '</a></p>';
	}

	/**
	 * @param string   $id      Select id.
	 * @param string   $label   Screen-reader label.
	 * @param string[] $options Option html.
	 */
	private static function scope_select( string $id, string $name, string $label, array $options ): string {
		return '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . implode( '', $options ) . '</select>';
	}

	/**
	 * The workbench: header (scope, progress, Save), toolbar, list above and
	 * editing area below. The script draws every row, field and number.
	 *
	 * @param Babilim_Editor $editor Editor service.
	 * @param array          $scope  Scope.
	 */
	private static function render_skeleton( Babilim_Editor $editor, array $scope ): string {
		$domains    = array();
		$locales    = array( $scope['locale'] );
		$components = array();
		foreach ( $editor->components() as $c ) {
			$name = '' !== $c['name'] ? $c['name'] : $c['slug'];
			if ( 'theme' === $c['kind'] ) {
				/* translators: %s: theme name */
				$name = sprintf( __( '%s (theme)', 'babilim' ), $name );
			}
			$here         = $c['kind'] === $scope['kind'] && $c['slug'] === $scope['slug'];
			$components[] = '<option value="' . esc_attr( $c['kind'] . ':' . $c['slug'] ) . '"' . ( $here ? ' selected' : '' ) . '>' . esc_html( $name ) . '</option>';
			if ( $here ) {
				$domains = $c['domains'];
				$locales = $c['locales'];
			}
		}
		$languages = array();
		$sorted = Babilim_Locale_Label::sorted( $locales );
		$names  = Babilim_Locale_Label::display_names( $sorted );
		foreach ( $sorted as $locale ) {
			$languages[] = '<option value="' . esc_attr( $locale ) . '" lang="' . esc_attr( str_replace( '_', '-', $locale ) ) . '"' . ( $locale === $scope['locale'] ? ' selected' : '' ) . '>' . esc_html( $names[ $locale ] ) . '</option>';
		}

		$html = '<div class="wrap babilim-ed"><h1>' . esc_html__( 'Edit translations', 'babilim' ) . '</h1><hr class="wp-header-end" />'
			. '<noscript><p>' . esc_html__( 'The editor needs JavaScript. Everything else in Babilim works without it.', 'babilim' ) . '</p></noscript>'
			. '<div class="babilim-ed-head"><div class="babilim-ed-scope">'
			// A plain GET form: choosing does nothing until Open is pressed (a select that navigates on change fails keyboard users); the script adds the draft flush before it goes.
			. '<form id="babilim-ed-scope-form" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />'
			. self::scope_select( 'babilim-ed-component', 'component', __( 'Plugin or theme', 'babilim' ), $components )
			// The arrow and the language menu wrap as one unit: a lone arrow never starts a line.
			. '<span class="babilim-ed-pair"><span class="babilim-ed-sep" aria-hidden="true">›</span>'
			. self::scope_select( 'babilim-ed-locale', 'locale', __( 'Language', 'babilim' ), $languages ) . '</span>'
			. ' <button type="submit" id="babilim-ed-open" class="button">' . esc_html__( 'Open', 'babilim' ) . '</button></form>';

		if ( count( $domains ) > 1 ) {
			$html .= '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">'
				. '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />'
				. '<input type="hidden" name="kind" value="' . esc_attr( $scope['kind'] ) . '" />'
				. '<input type="hidden" name="slug" value="' . esc_attr( $scope['slug'] ) . '" />'
				. '<input type="hidden" name="locale" value="' . esc_attr( $scope['locale'] ) . '" />'
				. '<label for="babilim-ed-domain">' . esc_html__( 'Text domain', 'babilim' ) . '</label> <select id="babilim-ed-domain" name="domain">';
			foreach ( $domains as $domain ) {
				$html .= '<option value="' . esc_attr( $domain ) . '"' . ( $domain === $scope['domain'] ? ' selected' : '' ) . '>' . esc_html( $domain ) . '</option>';
			}
			$html .= '</select> <button type="submit" class="button">' . esc_html__( 'Show', 'babilim' ) . '</button></form>';
		}

		$html .= '</div><p id="babilim-ed-progress" class="babilim-ed-progress"></p>'
			. '<div id="babilim-ed-save" class="babilim-ed-save"><p id="babilim-ed-pending" class="babilim-ed-pending"></p>'
			. '<button type="button" id="babilim-ed-discard-all" class="button" aria-disabled="true">' . esc_html__( 'Discard drafts…', 'babilim' ) . '</button>'
			. '<button type="button" id="babilim-ed-publish" class="button button-primary" aria-disabled="true" aria-describedby="babilim-ed-pending">' . esc_html__( 'Save', 'babilim' ) . '</button></div></div>'
			// aria-live="off": wp.a11y.speak() does the announcing, so the line is not read twice; it stays a status region for anyone who browses to it.
			. '<p id="babilim-ed-status" class="babilim-ed-status" role="status" aria-live="off"></p>'
			. '<div class="babilim-ed-tools"><fieldset id="babilim-ed-filter" class="babilim-ed-filter"><legend class="screen-reader-text">' . esc_html__( 'Show strings', 'babilim' ) . '</legend>';
		foreach ( self::filter_labels() as $value => $label ) {
			$html .= '<label><input type="radio" name="babilim-ed-filter" id="babilim-ed-filter-' . esc_attr( $value ) . '" value="' . esc_attr( $value ) . '"' . ( 'all' === $value ? ' checked' : '' ) . ' />'
				. esc_html( $label ) . ' <span id="babilim-ed-n-' . esc_attr( $value ) . '" class="babilim-ed-n"></span></label>';
		}
		$html .= '</fieldset>'
			. '<div class="babilim-ed-field"><label for="babilim-ed-search">' . esc_html__( 'Search', 'babilim' ) . '</label>'
			. '<input type="search" id="babilim-ed-search" autocomplete="off" maxlength="200" placeholder="' . esc_attr__( 'Text, context, notes or file name', 'babilim' ) . '" aria-describedby="babilim-ed-search-hint" />'
			. '<span id="babilim-ed-search-hint" class="screen-reader-text">' . esc_html__( 'Searches the original text, the translation, the context, notes for translators and the file the string comes from.', 'babilim' ) . '</span></div>'
			. '<div class="babilim-ed-field"><label for="babilim-ed-sort">' . esc_html__( 'Sort', 'babilim' ) . '</label><select id="babilim-ed-sort">'
			. '<option value="default">' . esc_html__( 'Default', 'babilim' ) . '</option><option value="untranslated">' . esc_html__( 'Untranslated first', 'babilim' ) . '</option>'
			. '<option value="recent">' . esc_html__( 'Recently edited', 'babilim' ) . '</option><option value="az">' . esc_html__( 'A–Z', 'babilim' ) . '</option></select></div>'
			. '<div class="babilim-ed-field"><label for="babilim-ed-source">' . esc_html__( 'Source', 'babilim' ) . '</label><select id="babilim-ed-source">'
			. '<option value="">' . esc_html__( 'All sources', 'babilim' ) . '</option>';
		foreach ( Babilim_Editor::SOURCES as $source ) {
			$html .= '<option value="' . esc_attr( $source ) . '">' . esc_html( self::source_labels()[ $source ] ) . '</option>';
		}
		return $html . '</select></div></div>'
			. '<div class="babilim-ed-bench"><div class="babilim-ed-listbox"><div class="babilim-ed-cols" aria-hidden="true"><span>' . esc_html__( 'Status', 'babilim' ) . '</span><span>' . esc_html__( 'Original', 'babilim' ) . '</span><span>' . esc_html__( 'Translation', 'babilim' ) . '</span><span>' . esc_html__( 'Source', 'babilim' ) . '</span></div>'
			. '<ul id="babilim-ed-list" class="babilim-ed-list" role="list"></ul><div id="babilim-ed-pager" class="babilim-ed-pager"></div></div>'
			. '<div class="babilim-ed-work"><section class="babilim-ed-main" aria-label="' . esc_attr__( 'Edit the selected string', 'babilim' ) . '"><div id="babilim-ed-review" class="babilim-ed-review"></div><div id="babilim-ed-edit" class="babilim-ed-edit"></div></section>'
			. '<aside id="babilim-ed-aside" class="babilim-ed-aside" aria-label="' . esc_attr__( 'Details and history', 'babilim' ) . '"></aside></div></div></div>';
	}

	/**
	 * @return array<string,string> Status filter => label, in the order of the radio buttons.
	 */
	private static function filter_labels(): array {
		return array(
			'all'          => __( 'All', 'babilim' ),
			'untranslated' => __( 'Untranslated', 'babilim' ),
			'edited'       => __( 'Edited by you', 'babilim' ),
			'problems'     => __( 'Has problems', 'babilim' ),
			'review'       => __( 'Needs review', 'babilim' ),
		);
	}

	/**
	 * @return array<string,string> Source => label of the Source menu, in Babilim_Editor::SOURCES order.
	 */
	private static function source_labels(): array {
		return array(
			'babilim' => __( 'From Babilim', 'babilim' ),
			'wporg'   => __( 'From WordPress.org', 'babilim' ),
			'bundled' => __( 'From the plugin or theme', 'babilim' ),
		);
	}

	/**
	 * The strings the script shows or speaks.
	 *
	 * @return array<string,string>
	 */
	private static function strings(): array {
		return array(
			'save'           => __( 'Save', 'babilim' ),
			'loggedOut'      => __( 'You are logged out. Log in again in another tab, then press Save.', 'babilim' ),
			'unsaved'        => __( 'This text is not saved yet. It stays in the field.', 'babilim' ),
			'noMore'         => __( 'No more untranslated strings on this page.', 'babilim' ),
			/* translators: %d: number of the plural form, starting at 1 */
			'form'           => __( 'Form %d', 'babilim' ),
			/* translators: 1: number of the plural form, starting at 1, 2: example numbers that use this form */
			'formEx'         => __( 'Form %1$d: %2$s', 'babilim' ),
			/* translators: %s: language name */
			'translation'    => __( 'Translation (%s)', 'babilim' ),
			'oneForm'        => __( 'This language uses one form for every number.', 'babilim' ),
			'pluralForms'    => __( 'Plural forms', 'babilim' ),
			'tabEmpty'       => __( 'empty', 'babilim' ),
			'tabProblem'     => __( 'problem', 'babilim' ),
			'original'       => __( 'Original text', 'babilim' ),
			'sourceSingular' => __( 'Original (singular)', 'babilim' ),
			'sourcePlural'   => __( 'Original (plural)', 'babilim' ),
			'copy'           => __( 'Copy original', 'babilim' ),
			'details'        => __( 'Details', 'babilim' ),
			'history'        => __( 'History', 'babilim' ),
			'srcLabel'       => __( 'Source', 'babilim' ),
			'srcBabilim'     => __( 'Babilim', 'babilim' ),
			'srcWporg'       => __( 'WordPress.org language pack', 'babilim' ),
			'srcBundled'     => __( 'Bundled with the plugin or theme', 'babilim' ),
			'srcYours'       => __( 'Yours (edited by you)', 'babilim' ),
			'srcNone'        => __( 'No translation yet', 'babilim' ),
			'context'        => __( 'Context', 'babilim' ),
			'note'           => __( 'Note for translators', 'babilim' ),
			'found'          => __( 'Found in', 'babilim' ),
			'restore'        => __( 'Restore', 'babilim' ),
			'tagLive'        => __( 'Live', 'babilim' ),
			'tagDraft'       => __( 'Draft', 'babilim' ),
			'stDraft'        => __( 'Draft', 'babilim' ),
			'stDraftProblem' => __( 'Draft · Problem', 'babilim' ),
			'review'         => __( 'Needs review', 'babilim' ),
			'orphan'         => __( 'No longer used', 'babilim' ),
			'untranslated'   => __( 'Untranslated', 'babilim' ),
			'noTranslation'  => __( 'No translation yet', 'babilim' ),
			'chipBabilim'    => __( 'Babilim', 'babilim' ),
			'chipWporg'      => __( 'WordPress.org', 'babilim' ),
			'chipBundled'    => __( 'Bundled', 'babilim' ),
			'chipYours'      => __( 'Yours', 'babilim' ),
			'pluralTag'      => __( '(plural)', 'babilim' ),
			'discardOne'     => __( 'Discard this draft', 'babilim' ),
			'discardedAll'   => __( 'Drafts discarded.', 'babilim' ),
			'discardedOne'   => __( 'Draft discarded.', 'babilim' ),
			'keptNote'       => __( 'This draft is kept, but it does not go live with Save until it is fixed. Your other changes still go live.', 'babilim' ),
			/* translators: %s: time of day */
			'draftSaved'     => __( 'Draft saved at %s. Not live yet.', 'babilim' ),
			'draftVisitors'  => __( 'Visitors still see the live text until you press Save.', 'babilim' ),
			/* translators: 1: the text that is live now, 2: where it comes from */
			'liveNow'        => __( 'Live now: %1$s (%2$s)', 'babilim' ),
			'keyCtrl'        => __( 'Ctrl', 'babilim' ),
			'keyEnter'       => __( 'Enter', 'babilim' ),
			'keyNext'        => __( 'next string', 'babilim' ),
			'keyMove'        => __( 'previous / next', 'babilim' ),
			'keyCopy'        => __( 'copy original', 'babilim' ),
			'keyClear'       => __( 'clear', 'babilim' ),
			'prev'           => __( 'Previous page', 'babilim' ),
			'next'           => __( 'Next page', 'babilim' ),
			/* translators: 1: current page number, 2: number of pages */
			'pageOf'         => __( 'Page %1$d of %2$d', 'babilim' ),
			'empty'          => __( 'No strings match.', 'babilim' ),
			/* translators: 1: plugin or theme name, 2: its version */
			'reviewChanged'  => __( 'The original text changed in %1$s %2$s.', 'babilim' ),
			'reviewWhy'      => __( 'Your translation was written for the old text, so the site no longer shows it. Choose what to use for the new text.', 'babilim' ),
			'oldOriginal'    => __( 'Old original', 'babilim' ),
			'newOriginal'    => __( 'New original', 'babilim' ),
			/* translators: Read out by screen readers right before the part of the old original text that the update removed; the script adds the space after it. */
			'removed'        => __( 'Removed text:', 'babilim' ),
			/* translators: Read out by screen readers right before the part of the new original text that the update added; the script adds the space after it. */
			'added'          => __( 'Added text:', 'babilim' ),
			'mine'           => __( 'Your translation (for the old text)', 'babilim' ),
			'yourNow'        => __( 'Your current translation', 'babilim' ),
			'useMine'        => __( 'Use my text', 'babilim' ),
			/* translators: %s: where the new translation comes from, such as Babilim */
			'newFrom'        => __( 'New translation from %s', 'babilim' ),
			'useNew'         => __( 'Use the new translation', 'babilim' ),
			'keepCurrent'    => __( 'Keep my current translation', 'babilim' ),
			'noNew'          => __( 'No translation yet', 'babilim' ),
			'discardMine'    => __( 'Discard my text', 'babilim' ),
			'reviewNote'     => __( 'Whichever you choose becomes a draft and goes live with Save, like any other change.', 'babilim' ),
			'editYourself'   => __( 'Or edit it yourself', 'babilim' ),
			/* translators: 1: plugin or theme name, 2: its version */
			'orphanNote'     => __( 'This text is no longer used: %1$s %2$s does not have this string any more.', 'babilim' ),
			'deleteBtn'      => __( 'Delete…', 'babilim' ),
			'deleteAsk'      => __( 'Delete your translation of this text? It is not shown anywhere on the site.', 'babilim' ),
			'deleted'        => __( 'Translation deleted.', 'babilim' ),
			'noSource'       => __( 'No strings found. This plugin or theme does not ship a translation template.', 'babilim' ),
			'getTextList'    => __( 'Get the text list from Babilim', 'babilim' ),
			'textListDisclosure' => __( "Sends the plugin's or theme's name and version to Babilim.", 'babilim' ),
			'checkTextList'  => __( 'Check again', 'babilim' ),
			'textListPending' => __( 'Babilim is preparing the list. This takes a few minutes.', 'babilim' ),
			'textListConsent' => __( 'Enable Babilim to get the text list for this plugin or theme.', 'babilim' ),
			'textListNotWporg' => __( 'Babilim cannot list the text of this plugin or theme: it is not on WordPress.org and it ships no translation template.', 'babilim' ),
			'textListUnavailable' => __( 'Babilim found no translatable text in this version.', 'babilim' ),
		);
	}

	/**
	 * The workbench's stylesheet. Inline (the plugin ships no .css file) and
	 * added only on the editor screen. Logical properties only, so a
	 * right-to-left language mirrors itself; the colour of the admin scheme
	 * with its default as the fallback; the draft colour is darker than the
	 * usual amber so it keeps 4.5:1 on the selected row.
	 */
	public static function css(): string {
		$accent = 'var(--wp-admin-theme-color,#2271b1)';
		$sel    = 'rgba(var(--wp-admin-theme-color--rgb,34,113,177),.08)';
		$cols   = 'grid-template-columns:8.5rem minmax(0,1fr) minmax(0,1fr) 7.5rem;column-gap:12px;align-items:center';
		return '.babilim-ed{display:flex;flex-direction:column;height:calc(100dvh - var(--wp-admin--admin-bar--height,32px) - 75px);min-height:30rem}'
			. '.babilim-ed .dashicons{width:16px;height:16px;font-size:16px;vertical-align:text-bottom}'
			. '.babilim-ed-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px}.babilim-ed-scope{display:flex;flex-wrap:wrap;align-items:center;gap:6px}.babilim-ed-scope form{display:flex;flex-wrap:wrap;align-items:center;gap:6px}.babilim-ed-scope,.babilim-ed-scope form,.babilim-ed-pair{max-width:100%;min-width:0}.babilim-ed-scope select{font-weight:600;min-width:0}.babilim-ed-pair{display:inline-flex;flex-wrap:nowrap;align-items:center;gap:6px}.babilim-ed-sep{color:#646970}'
			. '.babilim-ed-progress{margin:0}.babilim-ed-progress span{color:#646970}'
			. '.babilim-ed-save{margin-inline-start:auto;display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px}.babilim-ed-save .spinner{float:none;margin:0}'
			. '.babilim-ed-pending{margin:0;display:flex;flex-direction:column;gap:2px;font-size:12px}.babilim-ed-pending .d{color:#8a5c00}.babilim-ed-pending .p{color:#cc1818}.babilim-ed-pending .button-link{color:inherit;text-decoration:underline}'
			. '.babilim-ed-status{margin:0;min-height:0}.babilim-ed-status:not(:empty){margin-block:6px}'
			. '.babilim-ed-tools{display:flex;flex-wrap:wrap;align-items:end;gap:8px 16px;margin-block:10px}'
			. '.babilim-ed-filter{display:flex;flex-wrap:wrap;gap:4px;margin:0;padding:0;border:0}.babilim-ed-filter label{position:relative;display:inline-flex;align-items:center;gap:5px;min-height:32px;padding-inline:10px;border:1px solid #c3c4c7;background:#fff;cursor:pointer}'
			. '.babilim-ed-filter input{position:absolute;opacity:0;width:1px;height:1px}.babilim-ed-filter label:has(:checked){border-color:' . $accent . ';box-shadow:inset 0 -3px 0 ' . $accent . ';font-weight:600}.babilim-ed-filter label:has(:focus-visible){outline:2px solid ' . $accent . ';outline-offset:1px}.babilim-ed-n{color:#646970;font-weight:400}'
			. '.babilim-ed-field{display:flex;flex-direction:column;gap:3px}.babilim-ed-field label{font-weight:600;font-size:12px}.babilim-ed-field input[type=search]{min-width:18rem}'
			. '.babilim-ed-bench{flex:1;min-height:0;display:grid;grid-template-rows:minmax(9rem,2fr) minmax(0,3fr);background:#fff;border:1px solid #c3c4c7}'
			. '.babilim-ed-listbox,.babilim-ed-main,.babilim-ed-aside{overflow:auto;overscroll-behavior:contain}.babilim-ed-listbox{border-block-end:4px double #c3c4c7}'
			. '.babilim-ed-cols{position:sticky;top:0;z-index:1;display:grid;' . $cols . ';padding-inline:12px;min-height:30px;background:#f6f7f7;border-block-end:1px solid #c3c4c7;font-weight:600}'
			. '.babilim-ed-list{margin:0;padding:0;list-style:none}.babilim-ed-list li{margin:0}'
			. '.babilim-ed-list button{display:grid;' . $cols . ';width:100%;min-height:30px;padding-block:0;padding-inline:9px 12px;text-align:start;border:0;border-block-end:1px solid #f0f0f1;border-inline-start:3px solid transparent;background:none;color:inherit;font:inherit;cursor:pointer}'
			. '.babilim-ed-list button:hover{background:#f6f7f7}.babilim-ed-list button[aria-current=true]{border-inline-start-color:' . $accent . ';background:' . $sel . '}'
			. '.babilim-ed-list button>span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.babilim-ed-list .muted{color:#646970;font-style:italic}.babilim-ed-list .pl{color:#646970;font-size:11px}'
			. '.babilim-ed-st{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600}.babilim-ed-st-draft{color:#8a5c00}.babilim-ed-st-problem{color:#cc1818}.babilim-ed-st-review{color:#6c3fc4}'
			. '.babilim-ed-chip{justify-self:start;padding:1px 6px;border:1px solid #c3c4c7;border-radius:9px;font-size:11px;color:#50575e;background:#fff}.babilim-ed-chip-yours{border-color:' . $accent . '}'
			. '.babilim-ed-pager{display:flex;align-items:center;gap:.5em;padding:6px 12px}'
			. '.babilim-ed-work{display:grid;grid-template-columns:minmax(0,1fr) 19rem;min-height:0}.babilim-ed-main,.babilim-ed-aside{padding:14px 18px}.babilim-ed-aside{border-inline-start:1px solid #c3c4c7;background:#fbfbfc}'
			. '.babilim-ed-edit h2,.babilim-ed-aside h2{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970}'
			. '.babilim-ed-source{margin:0 0 12px;padding:8px 10px;background:#f6f7f7;border:1px solid #e0e0e0;white-space:pre-wrap;overflow-wrap:anywhere}'
			. '.babilim-ed-labelrow{display:flex;align-items:baseline;gap:10px;margin-block-end:4px}.babilim-ed-labelrow label{font-weight:600}.babilim-ed-labelrow .button-link{margin-inline-start:auto;font-size:12px}'
			. '.babilim-ed-edit textarea{display:block;width:100%;box-sizing:border-box;min-height:4.5em;margin-block-end:8px;resize:vertical}.babilim-ed-edit [aria-invalid=true]{border-color:#cc1818;box-shadow:0 0 0 1px #cc1818}'
			. '.babilim-ed-problem{margin:.25em 0 1em;color:#cc1818}.babilim-ed-note{margin:6px 0 0;color:#646970;font-size:12px}'
			. '.babilim-ed-state{margin:6px 0 0;padding:6px 10px;border-inline-start:4px solid #f0b849;background:#fef8ee}.babilim-ed-state[hidden]{display:none}.babilim-ed-state p{margin:0}.babilim-ed-state p+p{margin-block-start:3px}.babilim-ed-state.is-problem{border-inline-start-color:#cc1818;background:#fcf0f0}'
			. '.babilim-ed-actions .button[hidden]{display:none}.babilim-ed-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px;margin-block-start:10px}.babilim-ed-keys{display:flex;flex-wrap:wrap;gap:4px 12px;color:#646970;font-size:12px}.babilim-ed-keys kbd{padding:2px 4px;border:1px solid #c3c4c7;border-radius:3px;background:#f6f7f7;font-size:11px}'
			. '.babilim-ed-tabs{display:flex;overflow-x:auto;margin-block-end:8px;border-block-end:1px solid #c3c4c7}.babilim-ed-tabs [role=tab]{position:relative;flex:none;display:inline-flex;align-items:center;gap:5px;padding:8px 12px;border:0;background:none;font:inherit;color:inherit;cursor:pointer}'
			. '.babilim-ed-tabs [role=tab][aria-selected=true]::after{content:"";position:absolute;inset:auto 0 0;border-block-end:3px solid ' . $accent . '}.babilim-ed-tabs .is-problem{color:#cc1818}'
			. '.babilim-ed-dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:4px 10px;margin:0 0 16px}.babilim-ed-dl dt{color:#646970}.babilim-ed-dl dd{margin:0;overflow-wrap:anywhere}'
			. '.babilim-ed-hist{margin:0;padding:0;list-style:none}.babilim-ed-hist li{padding:8px 0;border-block-start:1px solid #dcdcde}.babilim-ed-hist .when{display:flex;justify-content:space-between;gap:8px;color:#646970;font-size:12px}.babilim-ed-hist p{margin:3px 0 0}'
			. '.babilim-ed-tag{padding:0 5px;border:1px solid #c3c4c7;font-size:11px;font-weight:600}.babilim-ed-tag-draft{color:#8a5c00;border-color:#f0b849;background:#fef8ee}'
			. '.babilim-ed-review:not(:empty){margin-block-end:14px}.babilim-ed-review .notice{margin:0 0 12px}.babilim-ed-review h2{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970}'
			. '.babilim-ed-diff,.babilim-ed-choice{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;margin:0 0 12px}.babilim-ed-diff .babilim-ed-source{margin:0}'
			. '.babilim-ed-review del{background:#fcf0f0;color:#8a1f11;text-decoration:line-through}.babilim-ed-review ins{background:#eff9f1;color:#1a6b34;text-decoration:underline;text-decoration-thickness:2px}'
			. '.babilim-ed-choice section{display:flex;flex-direction:column;gap:8px;padding:10px 12px;border:1px solid #c3c4c7}.babilim-ed-choice h2{color:#1d2327;text-transform:none;letter-spacing:0;font-size:13px}.babilim-ed-choice p{flex:1;margin:0;overflow-wrap:anywhere}.babilim-ed-choice .button{align-self:start}'
			. '.babilim-ed-orphan-note{margin:0 0 10px}'
			. '@media (max-width:782px){'
			. '.babilim-ed-diff,.babilim-ed-choice{grid-template-columns:minmax(0,1fr)}'
			. '.babilim-ed{height:auto;min-height:0;padding-block-end:7rem}'
			. '.babilim-ed-save{position:fixed;inset-inline:0;bottom:0;z-index:5;margin:0;padding:8px 10px calc(8px + env(safe-area-inset-bottom));background:#fff;border-block-start:1px solid #c3c4c7;box-shadow:0 -2px 6px rgba(0,0,0,.06)}'
			. 'html:has(.babilim-ed){scroll-padding-block-end:9rem}.babilim-ed-save .button-primary{margin-inline-start:auto}'
			. '.babilim-ed-field,.babilim-ed-field input[type=search],.babilim-ed-field select{width:100%;min-width:0}.babilim-ed-filter{flex-wrap:nowrap;overflow-x:auto;width:100%;min-width:0}.babilim-ed-filter label{flex:none}'
			. '.babilim-ed-bench{display:block}.babilim-ed-listbox{max-height:45dvh;border-block-end-width:1px}.babilim-ed-cols{display:none}'
			. '.babilim-ed-list button{grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"o o" "t t" "s c";row-gap:2px;padding-block:8px}.babilim-ed-list button>span{white-space:normal}'
			. '.babilim-ed-list button>span:nth-child(1){grid-area:s}.babilim-ed-list button>span:nth-child(2){grid-area:o}.babilim-ed-list button>span:nth-child(3){grid-area:t}.babilim-ed-list button>span:nth-child(4){grid-area:c;justify-self:end}'
			. '.babilim-ed-work{display:block}.babilim-ed-main,.babilim-ed-aside{overflow:visible;padding:14px 12px}.babilim-ed-aside{border-inline-start:0;border-block-start:1px solid #c3c4c7}'
			. '}'
			. '@media (forced-colors:active){.babilim-ed-list button[aria-current=true],.babilim-ed-filter label:has(:checked){outline:2px solid Highlight}}';
	}
}
