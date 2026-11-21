<?php
/**
 * The translation editor screen: with no component chosen, a list of every
 * installed plugin and theme times every language the site uses; with one
 * chosen, the skeleton that admin/js/babilim-editor.js fills in.
 *
 * Why: the list comes from local data only (no request to the service, no
 * consent needed), the editor's work is done by the REST routes of
 * Babilim_Editor_Rest, so this class only turns the request's names into a
 * scope (Babilim_Editor::scope() is the one place that validates them),
 * prints static markup and builds the settings object the script reads
 * (window.babilimEditor). The settings carry the REST address, a `wp_rest`
 * nonce, the scope and the strings the script shows -- never the site token
 * and never a file path. Everything printed goes through esc_*; the caller
 * runs the result through wp_kses( ..., Babilim_Admin::allowed_admin_html() ).
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
	 */
	public static function render( Babilim_Editor $editor ): string {
		$q = self::query();
		if ( '' === $q['kind'] && '' === $q['slug'] && '' === $q['locale'] ) {
			return self::render_list( $editor );
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
			'scope'    => $scope,
			'nplurals' => Babilim_Plural_Forms::nplurals( Babilim_Plural_Forms::header( $scope['locale'] ) ),
		);
		if ( self::is_ref( $q['ref'] ) ) {
			$out['ref'] = $q['ref'];
		}
		$out['strings'] = self::strings();
		return $out;
	}

	/**
	 * @return array{kind:string,slug:string,domain:string,locale:string,ref:string}
	 */
	private static function query(): array {
		$get = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; every write goes through the REST routes with a nonce.
		};
		return array(
			'kind'   => $get( 'kind' ),
			'slug'   => $get( 'slug' ),
			'domain' => $get( 'domain' ),
			'locale' => $get( 'locale' ),
			'ref'    => $get( 'ref' ),
		);
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

	private static function render_list( Babilim_Editor $editor ): string {
		$html = '<div class="wrap"><h1>' . esc_html__( 'Edit translations', 'babilim' ) . '</h1><hr class="wp-header-end" />';
		$rows = '';
		foreach ( $editor->components() as $c ) {
			$kind = 'theme' === $c['kind'] ? __( 'Theme', 'babilim' ) : __( 'Plugin', 'babilim' );
			$name = '' !== $c['name'] ? $c['name'] : $c['slug'];
			foreach ( $c['locales'] as $locale ) {
				$label = Babilim_Locale_Label::name( $locale );
				$rows .= '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $kind ) . '</td><td>' . esc_html( $label ) . '</td><td>'
					. '<a href="' . esc_url( self::url( $c['kind'], $c['slug'], $locale ) ) . '" aria-label="' . esc_attr(
						/* translators: 1: plugin or theme name, 2: language name */
						sprintf( __( 'Edit translations: %1$s, %2$s', 'babilim' ), $name, $label )
					) . '">' . esc_html__( 'Edit translations', 'babilim' ) . '</a></td></tr>';
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
	 * @param Babilim_Editor $editor Editor service.
	 * @param array          $scope  Scope.
	 */
	private static function render_skeleton( Babilim_Editor $editor, array $scope ): string {
		$name    = $scope['slug'];
		$domains = array();
		foreach ( $editor->components() as $c ) {
			if ( $c['kind'] === $scope['kind'] && $c['slug'] === $scope['slug'] ) {
				$name    = '' !== $c['name'] ? $c['name'] : $c['slug'];
				$domains = $c['domains'];
			}
		}
		$html = '<div class="wrap"><h1>' . esc_html__( 'Edit translations', 'babilim' ) . '</h1><hr class="wp-header-end" />'
			. '<h2>' . esc_html(
				/* translators: 1: plugin or theme name, 2: language name */
				sprintf( __( '%1$s: %2$s', 'babilim' ), $name, Babilim_Locale_Label::name( $scope['locale'] ) )
			) . '</h2>' . self::back_link();

		if ( count( $domains ) > 1 ) {
			$html .= '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">'
				. '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />'
				. '<input type="hidden" name="kind" value="' . esc_attr( $scope['kind'] ) . '" />'
				. '<input type="hidden" name="slug" value="' . esc_attr( $scope['slug'] ) . '" />'
				. '<input type="hidden" name="locale" value="' . esc_attr( $scope['locale'] ) . '" />'
				. '<p><label for="babilim-ed-domain">' . esc_html__( 'Text domain', 'babilim' ) . '</label> <select id="babilim-ed-domain" name="domain">';
			foreach ( $domains as $domain ) {
				$html .= '<option value="' . esc_attr( $domain ) . '"' . ( $domain === $scope['domain'] ? ' selected' : '' ) . '>' . esc_html( $domain ) . '</option>';
			}
			$html .= '</select> <button type="submit" class="button">' . esc_html__( 'Show', 'babilim' ) . '</button></p></form>';
		}

		$labels = self::filter_labels();
		$html  .= '<noscript><p>' . esc_html__( 'The editor needs JavaScript. Everything else in Babilim works without it.', 'babilim' ) . '</p></noscript>'
			// aria-live="off": wp.a11y.speak() does the announcing, so the line is not read twice; it stays a status region for anyone who browses to it.
			. '<p id="babilim-ed-status" class="babilim-ed-status" role="status" aria-live="off"></p>'
			. '<div class="babilim-ed-layout"><div class="babilim-ed-side">'
			. '<p><label for="babilim-ed-filter">' . esc_html__( 'Filter strings', 'babilim' ) . '</label> <select id="babilim-ed-filter">';
		foreach ( Babilim_Editor::FILTERS as $filter ) {
			$html .= '<option value="' . esc_attr( $filter ) . '">' . esc_html( $labels[ $filter ] ) . '</option>';
		}
		return $html . '</select></p>'
			. '<p><label for="babilim-ed-search">' . esc_html__( 'Search strings', 'babilim' ) . '</label> <input type="search" id="babilim-ed-search" autocomplete="off" maxlength="200" /></p>'
			. '<ul id="babilim-ed-list" class="babilim-ed-list"></ul><div id="babilim-ed-pager" class="babilim-ed-pager"></div></div>'
			. '<div id="babilim-ed-edit" class="babilim-ed-edit"></div></div></div>';
	}

	/**
	 * @return array<string,string> Filter => label, in Babilim_Editor::FILTERS order.
	 */
	private static function filter_labels(): array {
		return array(
			'all'          => __( 'All', 'babilim' ),
			'untranslated' => __( 'Untranslated', 'babilim' ),
			'edited'       => __( 'Edited', 'babilim' ),
			'review'       => __( 'Needs review', 'babilim' ),
			'orphan'       => __( 'No longer used', 'babilim' ),
			'babilim'      => __( 'From Babilim', 'babilim' ),
			'wporg'        => __( 'From WordPress.org', 'babilim' ),
			'bundled'      => __( 'From the plugin or theme', 'babilim' ),
		);
	}

	/**
	 * The strings the script shows or speaks.
	 *
	 * @return array<string,string>
	 */
	private static function strings(): array {
		$f = self::filter_labels();
		return array(
			'save'            => __( 'Save', 'babilim' ),
			'saved'           => __( 'Saved.', 'babilim' ),
			/* translators: %s: why the saved text is not in effect yet */
			'notLive'         => __( 'Saved. It is not live yet: %s', 'babilim' ),
			/* translators: %s: why the reverted text is not in effect yet */
			'revertedNotLive' => __( 'Reverted. It is not live yet: %s', 'babilim' ),
			'loggedOut'       => __( 'You are logged out. Log in again in another tab, then press Save.', 'babilim' ),
			'unsaved'         => __( 'This text is not saved yet. It stays in the field.', 'babilim' ),
			/* translators: %d: number of the plural form, starting at 1 */
			'form'            => __( 'Form %d', 'babilim' ),
			'translation'     => __( 'Translation', 'babilim' ),
			'source'          => __( 'Original text', 'babilim' ),
			'sourcePlural'    => __( 'Original text (plural)', 'babilim' ),
			'context'         => __( 'Context', 'babilim' ),
			'note'            => __( 'Note for translators', 'babilim' ),
			'revert'          => __( 'Revert', 'babilim' ),
			'chooseFirst'     => __( 'Choose a version to revert to first.', 'babilim' ),
			'revertTo'        => __( 'Revert to', 'babilim' ),
			'chooseVersion'   => __( 'Choose a version…', 'babilim' ),
			'revertOriginal'  => __( "Babilim's or the original translation", 'babilim' ),
			'reverted'        => __( 'Reverted.', 'babilim' ),
			'edited'          => $f['edited'],
			'untranslated'    => $f['untranslated'],
			'review'          => $f['review'],
			'orphan'          => $f['orphan'],
			'babilim'         => $f['babilim'],
			'wporg'           => $f['wporg'],
			'bundled'         => $f['bundled'],
			'prev'            => __( 'Previous page', 'babilim' ),
			'next'            => __( 'Next page', 'babilim' ),
			/* translators: 1: current page number, 2: number of pages */
			'pageOf'          => __( 'Page %1$d of %2$d', 'babilim' ),
			'noMore'          => __( 'No more untranslated strings on this page.', 'babilim' ),
			'empty'           => __( 'No strings match.', 'babilim' ),
			'noSource'        => __( 'No strings found. This plugin or theme does not ship a translation template.', 'babilim' ),
		);
	}
}
