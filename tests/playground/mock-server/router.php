<?php
/**
 * A local (127.0.0.1-only) stand-in for the Babilim service's two
 * endpoints the update-adoption flow ever calls (`POST /v1/downloads`,
 * then a `GET` of the signed link it returns) -- run with PHP's own
 * built-in server:
 *
 *   php -S 127.0.0.1:<port> tests/playground/mock-server/router.php
 *
 * with the environment variable BABILIM_PKG_DIR pointing at a directory
 * of `{server_build_id}.zip` files built by build-package.php. Never
 * reachable from anywhere but the host loopback interface, and never a
 * stand-in for anything beyond these two endpoints -- this plugin's real
 * service is a separate, private repository.
 *
 * No real signature/expiry is checked here (unlike the real service) --
 * this only proves the plugin's own client-side call shape and the
 * downloaded package's adoption, which is this test's job; contract-
 * level behavior of the service itself is out of scope for a plugin-repo
 * test.
 */

declare(strict_types=1);

$pkg_dir = getenv( 'BABILIM_PKG_DIR' );
if ( false === $pkg_dir || '' === $pkg_dir ) {
	http_response_code( 500 );
	echo 'BABILIM_PKG_DIR not set';
	return;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

// -- Registration and verification, just enough for the identity checks.
// State lives in files next to the packages so it survives across the
// separate PHP requests the built-in server handles.
$state_file = rtrim( $pkg_dir, '/' ) . '/_registrations.json';
$read_state = static function () use ( $state_file ) {
	$decoded = is_file( $state_file ) ? json_decode( (string) file_get_contents( $state_file ), true ) : null;
	return is_array( $decoded ) ? $decoded : array( 'verify_ok' => false, 'registered' => array() );
};
$write_state = static function ( array $state ) use ( $state_file ) {
	file_put_contents( $state_file, json_encode( $state ) );
};
$json_reply = static function ( int $status, array $body ) {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $body );
};

if ( 'POST' === $method && '/v1/sites' === $path ) {
	$body  = json_decode( (string) file_get_contents( 'php://input' ), true );
	$state = $read_state();
	$state['registered'][] = is_array( $body ) ? (string) ( $body['install_pub'] ?? '' ) : '';
	$site_id               = 'st_pg' . str_pad( (string) count( $state['registered'] ), 4, '0', STR_PAD_LEFT );
	$nonce                 = bin2hex( random_bytes( 16 ) );
	// What a real callback needs later (only used when `callback` is on).
	$state['sites'][ $site_id ] = array(
		'pub'      => is_array( $body ) ? (string) ( $body['install_pub'] ?? '' ) : '',
		'home_url' => is_array( $body ) ? (string) ( $body['home_url'] ?? '' ) : '',
		'nonce'    => $nonce,
	);
	$write_state( $state );
	$json_reply(
		201,
		array(
			'site_id'   => $site_id,
			'challenge' => array( 'nonce' => $nonce, 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 900 ) ),
		)
	);
	return;
}

if ( 'POST' === $method && 1 === preg_match( '#^/v1/sites/([^/]+)/verify$#', (string) $path, $verify_match ) ) {
	$state = $read_state();
	if ( ! empty( $state['callback'] ) ) {
		// A real callback: ask the site for its proof and check the
		// signature, the way the real service does.
		$site     = $state['sites'][ $verify_match[1] ] ?? null;
		$verified = false;
		$reason   = 'callback_unreachable';
		if ( is_array( $site ) && '' !== $site['home_url'] ) {
			$answer = @file_get_contents( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed callback is an expected outcome here.
				rtrim( $site['home_url'], '/' ) . '/wp-json/babilim/v1/challenge?nonce=' . rawurlencode( $site['nonce'] ),
				false,
				stream_context_create( array( 'http' => array( 'timeout' => 10, 'ignore_errors' => true ) ) )
			);
			$data   = is_string( $answer ) ? json_decode( $answer, true ) : null;
			if ( is_array( $data ) && isset( $data['proof'] ) && 0 === strpos( (string) $data['proof'], 'b64:' ) ) {
				$sig      = base64_decode( substr( (string) $data['proof'], 4 ), true );
				$pub      = base64_decode( $site['pub'], true );
				$message  = "babilim-challenge-v1\n{$verify_match[1]}\n{$site['nonce']}";
				$verified = false !== $sig && false !== $pub && sodium_crypto_sign_verify_detached( $sig, $message, $pub );
				$reason   = 'callback_invalid';
			}
		}
		if ( ! $verified ) {
			$json_reply( 403, array( 'error' => array( 'code' => 'verification_required', 'message' => 'The service could not verify this site.', 'hint' => 'Allow the challenge endpoint.', 'reason' => $reason ) ) );
			return;
		}
	} elseif ( empty( $state['verify_ok'] ) ) {
		$json_reply( 403, array( 'error' => array( 'code' => 'verification_required', 'message' => 'The service could not reach this site.', 'hint' => 'Allow the challenge endpoint.', 'reason' => 'callback_unreachable' ) ) );
		return;
	}
	$json_reply( 200, array( 'site_token' => 'tok_pg_' . count( $state['registered'] ), 'verified' => true, 'license' => array( 'kind' => 'free', 'status' => 'active' ), 'quota' => new stdClass() ) );
	return;
}

// -- Inventory: answers every component in the request with an
// installable build per requested locale, after any scripted step.
// `POST /_mock/script` queues steps ({"delay": seconds, "status": http
// status, "code": error code}); each inventory request consumes one.
if ( 'POST' === $method && 1 === preg_match( '#^/v1/sites/[^/]+/inventory$#', (string) $path ) ) {
	$started = microtime( true );
	$state   = $read_state();
	$queue   = is_array( $state['script'] ?? null ) ? $state['script'] : array();
	$step    = array_shift( $queue );
	$state['script'] = $queue;
	$body    = json_decode( (string) file_get_contents( 'php://input' ), true );
	$write_state( $state );
	if ( is_array( $step ) && ! empty( $step['delay'] ) ) {
		usleep( (int) ( (float) $step['delay'] * 1000000 ) );
	}
	if ( is_array( $step ) && ! empty( $step['status'] ) ) {
		$json_reply( (int) $step['status'], array( 'error' => array( 'code' => (string) ( $step['code'] ?? 'unavailable' ), 'message' => 'Scripted failure.', 'hint' => '' ) ) );
		return;
	}
	$locales    = is_array( $body ) && is_array( $body['locales'] ?? null ) ? $body['locales'] : array();
	$components = array();
	$profile    = (string) ( $state['profile'] ?? '' );
	foreach ( is_array( $body ) && is_array( $body['components'] ?? null ) ? $body['components'] : array() as $component ) {
		if ( 'bulk' === $profile && 0 === strpos( (string) ( $component['slug'] ?? '' ), 'bulk-' ) ) {
			// The bulk scenarios: every bulk-* component has a build for de_DE.
			$slug         = (string) $component['slug'];
			$components[] = array(
				'kind'    => (string) ( $component['kind'] ?? 'plugin' ),
				'slug'    => $slug,
				'locales' => (object) array(
					'de_DE' => array(
						'status'   => 'babilim_available',
						'build_id' => 'bd_' . substr( md5( $slug . 'de_DE' ), 0, 12 ),
						'build_no' => 1,
						'built_at' => '2026-09-20T08:00:00Z',
					),
				),
			);
			continue;
		}
		if ( 'list' === $profile ) {
			// The list scenario: a few statuses per component, picked
			// by slug, so every view of the translations list has data.
			$slug      = (string) ( $component['slug'] ?? '' );
			$by_slug   = array(
				'woocommerce'  => array( 'de_DE' => 'babilim_available', 'fr_FR' => 'requestable' ),
				'queued-demo'  => array( 'de_DE' => 'queued' ),
				'long-name-demo' => array( 'de_DE' => 'requestable', 'zz_ZZ' => 'requestable', 'es_DO' => 'requestable' ),
				// Installed here once the flow downloads it; built for an
				// older version, so its line offers the longest request label.
				'newer-demo'   => array( 'de_DE' => 'babilim_installed' ),
			);
			$statuses  = $by_slug[ $slug ] ?? array( 'de_DE' => 'requestable' );
			$list_cell = array();
			foreach ( $statuses as $locale => $status ) {
				$list_cell[ $locale ] = array( 'status' => $status );
				if ( 'babilim_installed' === $status ) {
					$list_cell[ $locale ] += array(
						'build_id'          => 'bd_' . substr( md5( $slug . $locale ), 0, 12 ),
						'build_no'          => 1,
						'built_at'          => '2026-09-20T08:00:00Z',
						'built_for_version' => '0.9',
						'newer_version'     => array( 'version' => '1.0.0', 'status' => 'requestable' ),
					);
				}
				if ( 'babilim_available' === $status ) {
					$list_cell[ $locale ] += array(
						'build_id' => 'bd_' . substr( md5( $slug . $locale ), 0, 12 ),
						'build_no' => 1,
						'built_at' => '2026-09-20T08:00:00Z',
					);
				}
			}
			$components[] = array( 'kind' => (string) ( $component['kind'] ?? 'plugin' ), 'slug' => $slug, 'locales' => (object) $list_cell );
			continue;
		}
		$entries = array();
		foreach ( $locales as $locale ) {
			$entries[ (string) $locale ] = array(
				'status'   => 'babilim_available',
				'build_id' => 'bd_' . substr( md5( ( $component['slug'] ?? '' ) . $locale ), 0, 12 ),
				'build_no' => 1,
				'built_at' => '2026-09-20T08:00:00Z',
			);
		}
		$components[] = array( 'kind' => (string) ( $component['kind'] ?? 'plugin' ), 'slug' => (string) ( $component['slug'] ?? '' ), 'locales' => (object) $entries );
	}
	$state                    = $read_state();
	$state['inventory_log'][] = array( 'components' => count( $components ), 'ms' => (int) round( ( microtime( true ) - $started ) * 1000 ) );
	$write_state( $state );
	$json_reply(
		200,
		array(
			'components' => $components,
			'updates'    => array(),
			'license'    => array( 'kind' => 'free', 'status' => 'active' ),
			'quota'      => new stdClass(),
			'features'   => new stdClass(),
			'contract'   => '1.4.0',
		)
	);
	return;
}

if ( 'POST' === $method && '/v1/requests' === $path ) {
	$json_reply( 202, array( 'request_id' => 'rq_' . bin2hex( random_bytes( 6 ) ), 'status' => 'queued', 'eta_minutes' => null ) );
	return;
}

if ( 'GET' === $method && '/v1/meta/locales' === $path ) {
	$json_reply(
		200,
		array(
			array( 'wp_locale' => 'de_DE', 'english_name' => 'German', 'native_name' => 'Deutsch' ),
			array( 'wp_locale' => 'fr_FR', 'english_name' => 'French (France)', 'native_name' => 'Français' ),
			array( 'wp_locale' => 'es_DO', 'english_name' => 'Spanish (Dominican Republic)', 'native_name' => 'Español de República Dominicana' ),
		)
	);
	return;
}

// Test control (loopback only): `/_mock/reset` clears everything
// (registrations, scripted steps, logs) and may set the mode;
// `/_mock/script` queues inventory steps.
if ( 'POST' === $method && '/_mock/reset' === $path ) {
	$opts = json_decode( (string) file_get_contents( 'php://input' ), true );
	$opts = is_array( $opts ) ? $opts : array();
	$write_state(
		array(
			'verify_ok'     => ! empty( $opts['verify_ok'] ),
			'profile'       => (string) ( $opts['profile'] ?? '' ),
			'callback'      => ! empty( $opts['callback'] ),
			'download_delay' => (float) ( $opts['download_delay'] ?? 0 ),
			'fail_builds'   => is_array( $opts['fail_builds'] ?? null ) ? array_values( $opts['fail_builds'] ) : array(),
			'registered'    => array(),
			'sites'         => array(),
			'script'        => array(),
			'inventory_log' => array(),
		)
	);
	$json_reply( 200, array( 'ok' => true ) );
	return;
}
if ( 'POST' === $method && '/_mock/script' === $path ) {
	$steps = json_decode( (string) file_get_contents( 'php://input' ), true );
	$state = $read_state();
	$state['script'] = array_merge( $state['script'] ?? array(), is_array( $steps ) ? array_values( $steps ) : array() );
	$write_state( $state );
	$json_reply( 200, array( 'queued' => count( $state['script'] ) ) );
	return;
}

// Test control (loopback only): flip whether verify succeeds, read back what was registered.
if ( 'GET' === $method && '/_control/verify-ok' === $path ) {
	$state = $read_state();
	$state['verify_ok'] = true;
	$write_state( $state );
	$json_reply( 200, array( 'ok' => true ) );
	return;
}
if ( 'GET' === $method && '/_control/state' === $path ) {
	$json_reply( 200, $read_state() );
	return;
}

if ( 'POST' === $method && '/v1/downloads' === $path ) {
	$raw  = file_get_contents( 'php://input' );
	$body = json_decode( (string) $raw, true );
	$build_id = is_array( $body ) ? (string) ( $body['build_id'] ?? '' ) : '';
	$state    = $read_state();
	$state['download_log'][] = $build_id;
	$write_state( $state );
	if ( ! empty( $state['download_delay'] ) ) {
		usleep( (int) ( (float) $state['download_delay'] * 1000000 ) );
	}
	if ( in_array( $build_id, (array) ( $state['fail_builds'] ?? array() ), true ) ) {
		$json_reply( 500, array( 'error' => array( 'code' => 'internal_error', 'message' => 'Scripted failure.', 'hint' => '' ) ) );
		return;
	}
	$zip_path = rtrim( $pkg_dir, '/' ) . '/' . $build_id . '.zip';
	if ( '' === $build_id || ! is_file( $zip_path ) ) {
		http_response_code( 404 );
		header( 'Content-Type: application/json' );
		echo json_encode( array( 'error' => array( 'code' => 'not_found', 'message' => 'no such build' ) ) );
		return;
	}
	http_response_code( 302 );
	header( 'Location: /v1/packages/' . rawurlencode( $build_id ) );
	return;
}

if ( 'GET' === $method && 0 === strpos( (string) $path, '/v1/packages/' ) ) {
	$build_id = rawurldecode( substr( (string) $path, strlen( '/v1/packages/' ) ) );
	$zip_path = rtrim( $pkg_dir, '/' ) . '/' . $build_id . '.zip';
	if ( ! is_file( $zip_path ) ) {
		http_response_code( 404 );
		echo 'no such package';
		return;
	}
	header( 'Content-Type: application/zip' );
	header( 'Content-Length: ' . filesize( $zip_path ) );
	readfile( $zip_path );
	return;
}

if ( 'GET' === $method && 1 === preg_match( '#^/wporg-package/([a-z0-9-]+)-([a-zA-Z_]+)\.zip$#', (string) $path, $m ) ) {
	// A real wp.org-shaped language-pack zip (flat .po/.mo at the zip
	// root, no manifest.json) -- built on demand, cached under
	// $pkg_dir/_wporg/ so a repeated request during one run is cheap.
	$slug     = $m[1];
	$locale   = $m[2];
	$cache_dir = rtrim( $pkg_dir, '/' ) . '/_wporg';
	if ( ! is_dir( $cache_dir ) ) {
		mkdir( $cache_dir, 0777, true );
	}
	$zip_path = "{$cache_dir}/{$slug}-{$locale}.zip";
	if ( ! is_file( $zip_path ) ) {
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( "{$slug}-{$locale}.mo", 'WPORG-NEW-MO' );
		$zip->addFromString( "{$slug}-{$locale}.po", 'WPORG-NEW-PO' );
		$zip->close();
	}
	header( 'Content-Type: application/zip' );
	header( 'Content-Length: ' . filesize( $zip_path ) );
	readfile( $zip_path );
	return;
}

http_response_code( 404 );
echo 'not found';
