<?php
/**
 * The translation editor's REST routes (`babilim/v1/editor/...`): thin
 * handlers over Babilim_Editor.
 *
 * Why: the editor page talks to the server through these routes with
 * cookie authentication and the `X-WP-Nonce` header (action `wp_rest`),
 * which core checks before any callback runs, so a request without a valid
 * nonce is an anonymous one and fails the capability check. Every route
 * checks the capability itself (permission_callback), never just "logged
 * in": `manage_options`, or `manage_network_options` on multisite. Arguments
 * are declared with JSON Schema keywords (enum, pattern, maxLength, ...) that
 * core validates; a translation text is deliberately not run through sanitize_text_field (it would strip
 * the markup a translation must keep), it is only cast to strings and left
 * to Babilim_Override_Check. Errors are WP_Error with an explicit status;
 * nothing returned carries a file path or the site token.
 *
 * @package Babilim
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and answers the editor routes.
 */
final class Babilim_Editor_Rest {

	const NAMESPACE_ = 'babilim/v1';

	/** @var callable fn(): Babilim_Editor */
	private $editor;

	/**
	 * @param callable $editor fn(): Babilim_Editor -- built on first use, so registering the routes costs nothing.
	 */
	public function __construct( callable $editor ) {
		$this->editor = $editor;
	}

	/**
	 * `rest_api_init` callback.
	 */
	public function register(): void {
		// Checks are core JSON Schema keywords (enum, pattern, min/max): core validates them before any
		// callback runs and OPTIONS publishes them. `\z`, not `$`: a trailing newline must not pass.
		$scope        = array(
			'kind'   => array(
				'required' => true,
				'type'     => 'string',
				'enum'     => array( 'plugin', 'theme' ),
			),
			'slug'   => array(
				'required' => true,
				'type'     => 'string',
				'pattern'  => '^[A-Za-z0-9_-]+\z',
			),
			'domain' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => '',
				'pattern'  => '^[A-Za-z0-9_-]*\z',
			),
			'locale' => array(
				'required' => true,
				'type'     => 'string',
				'pattern'  => '^[A-Za-z0-9_@-]+\z',
			),
		);
		$ref          = array(
			'required' => true,
			'type'     => 'string',
			'pattern'  => '^[0-9a-f]{64}\z',
		);
		$msgstr       = array(
			'required'          => true,
			'type'              => 'array',
			'minItems'          => 1,
			'maxItems'          => 6,
			'items'             => array(
				'type'      => 'string',
				'maxLength' => 65535,
			),
			// A sanitize_callback replaces core's default parse (which would validate the schema), so validation is asked for explicitly.
			// Core's array check turns a plain string into a list (wp_parse_list) and passes it, while the sanitizer below would store the whole string as one form: only a real array may reach the schema check.
			'validate_callback' => static function ( $v, $request, $key ) {
				return is_array( $v ) ? rest_validate_request_arg( $v, $request, $key ) : false;
			},
			// Keys dropped: the forms are a list.
			'sanitize_callback' => static function ( $v ): array {
				return array_values( array_map( 'strval', (array) $v ) );
			},
		);
		$list         = array(
			'filter' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'all',
				'enum'     => Babilim_Editor::FILTERS,
			),
			'source' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => '',
				'enum'     => array_merge( array( '' ), Babilim_Editor::SOURCES ),
			),
			'sort'   => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'default',
				'enum'     => Babilim_Editor::SORTS,
			),
			'search' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 200,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
			),
			'page'   => array(
				'required' => false,
				'type'     => 'integer',
				'default'  => 1,
				'minimum'  => 1,
				'maximum'  => 999999,
			),
			'ref'    => array(
				'required' => false,
				'type'     => 'string',
				'default'  => '',
				'pattern'  => '^([0-9a-f]{64})?\z',
			),
		);
		$optional_ref = $list['ref'];

		$routes = array(
			array( '/editor/strings', WP_REST_Server::READABLE, 'strings', $scope + $list ),
			array( '/editor/string-list', WP_REST_Server::CREATABLE, 'string-list', $scope ),
			array( '/editor/history', WP_REST_Server::READABLE, 'history', $scope + array( 'ref' => $ref ) ),
			array( '/editor/draft', WP_REST_Server::CREATABLE, 'draft', $scope + array( 'ref' => $ref, 'msgstr' => $msgstr ) ),
			array( '/editor/discard', WP_REST_Server::CREATABLE, 'discard', $scope + array( 'ref' => $optional_ref ) ),
			array( '/editor/publish', WP_REST_Server::CREATABLE, 'publish', $scope ),
			array( '/editor/remove', WP_REST_Server::CREATABLE, 'remove', $scope + array( 'ref' => $ref ) ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NAMESPACE_,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => function ( $request ) use ( $r ) {
						return $this->handle( $r[2], $request );
					},
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'args'                => $r[3],
				)
			);
		}
	}

	/**
	 * permission_callback: the capability, not just a login.
	 */
	public static function allowed(): bool {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * @param mixed $v Value.
	 */
	public static function clean( $v ): string {
		return is_string( $v ) ? trim( $v ) : '';
	}

	/**
	 * @param string          $action  strings|string-list|history|draft|discard|publish|remove.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle( string $action, $request ) {
		if ( 'string-list' === $action && ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'The editor session has expired.', 'babilim' ), array( 'status' => 403 ) );
		}
		try {
			$editor = call_user_func( $this->editor );
			$scope  = $editor->scope( (string) $request->get_param( 'kind' ), (string) $request->get_param( 'slug' ), (string) $request->get_param( 'domain' ), (string) $request->get_param( 'locale' ) );
			if ( null === $scope ) {
				return new WP_Error( 'babilim_unknown_component', __( 'That plugin, theme, text domain or language is not available here.', 'babilim' ), array( 'status' => 404 ) );
			}
			$ref = (string) $request->get_param( 'ref' );
			switch ( $action ) {
				case 'strings':
					return rest_ensure_response(
						$editor->strings(
							$scope,
							array(
								'filter' => (string) $request->get_param( 'filter' ),
								'source' => (string) $request->get_param( 'source' ),
								'sort'   => (string) $request->get_param( 'sort' ),
								'search' => (string) $request->get_param( 'search' ),
								'page'   => (int) $request->get_param( 'page' ),
								'ref'    => $ref,
							)
						)
					);
				case 'string-list':
					$result = $editor->fetch_string_list( $scope );
					break;
				case 'history':
					return rest_ensure_response( array( 'history' => $editor->history( $scope, $ref ) ) );
				case 'draft':
					$result = $editor->save_draft( $scope, $ref, (array) $request->get_param( 'msgstr' ) );
					break;
				case 'discard':
					$result = $editor->discard( $scope, $ref );
					break;
				case 'remove':
					$result = $editor->remove( $scope, $ref );
					break;
				default:
					$result = $editor->publish( $scope );
			}
			if ( $result->is_ok() ) {
				return rest_ensure_response( $result->data() );
			}
			return new WP_Error( 'babilim_' . $result->code(), $result->message(), array( 'status' => 400 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'babilim_failed', __( 'The editor could not complete that request.', 'babilim' ), array( 'status' => 500 ) );
		}
	}
}
