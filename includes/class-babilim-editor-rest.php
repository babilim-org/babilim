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
 * are declared with validate_callback and sanitize_callback; a translation
 * text is deliberately not run through sanitize_text_field (it would strip
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
		$scope = array(
			'kind'   => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => static function ( $v ): bool {
					return 'plugin' === $v || 'theme' === $v;
				},
			),
			'slug'   => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => array( __CLASS__, 'is_name' ),
			),
			'domain' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => static function ( $v ): bool {
					return '' === $v || self::is_name( $v );
				},
			),
			'locale' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && Babilim_Paths::is_safe_locale( $v );
				},
			),
		);
		$ref   = array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'clean' ),
			'validate_callback' => static function ( $v ): bool {
				return is_string( $v ) && 1 === preg_match( '/^[0-9a-f]{64}$/D', $v );
			},
		);
		$msgstr = array(
			'required'          => true,
			'type'              => 'array',
			'items'             => array( 'type' => 'string' ),
			'sanitize_callback' => static function ( $v ): array {
				return array_values( array_map( 'strval', (array) $v ) );
			},
			'validate_callback' => static function ( $v ): bool {
				if ( ! is_array( $v ) || array() === $v || count( $v ) > 6 ) {
					return false;
				}
				foreach ( $v as $form ) {
					if ( ! is_string( $form ) || strlen( $form ) > 65535 ) {
						return false;
					}
				}
				return true;
			},
		);
		$list   = array(
			'filter' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'all',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && in_array( $v, Babilim_Editor::FILTERS, true );
				},
			),
			'source' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && ( '' === $v || in_array( $v, Babilim_Editor::SOURCES, true ) );
				},
			),
			'sort'   => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'default',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && in_array( $v, Babilim_Editor::SORTS, true );
				},
			),
			'search' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && strlen( $v ) <= 200;
				},
			),
			'page'   => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $v ): bool {
					return ( is_int( $v ) || ( is_string( $v ) && 1 === preg_match( '/^\d{1,6}$/D', $v ) ) ) && (int) $v >= 1;
				},
			),
			'ref'    => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && ( '' === $v || 1 === preg_match( '/^[0-9a-f]{64}$/D', $v ) );
				},
			),
		);
		$optional_ref = array(
			'required'          => false,
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => array( __CLASS__, 'clean' ),
			'validate_callback' => $list['ref']['validate_callback'],
		);

		$routes = array(
			array( '/editor/strings', WP_REST_Server::READABLE, 'strings', $scope + $list ),
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
	 * @param mixed $v Value.
	 */
	public static function is_name( $v ): bool {
		return is_string( $v ) && Babilim_Paths::is_safe_name( $v );
	}

	/**
	 * @param string          $action  strings|history|draft|discard|publish|remove.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle( string $action, $request ) {
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
