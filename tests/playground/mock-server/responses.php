<?php
/**
 * The bodies the local mock service (router.php) answers with, as plain
 * functions with no I/O, so tests/unit/test-playground-mock.php can check
 * every one of them against the pinned contract schemas without starting
 * a server.
 *
 * Profiles (set through `POST /_mock/reset` or `/_mock/set`):
 *   ''       every component gets an installable build per requested locale
 *   bulk     every bulk-* component has a build for de_DE
 *   list     a few statuses per component, picked by slug
 *   errors   like '', plus one pending update for errors-demo
 *   noquota  like list, but with the free-plan numbers left out (see
 *            babilim_mock_quota())
 */

declare(strict_types=1);

/**
 * `quota` for an inventory answer. Every profile but one reports the
 * free-plan slots and downloads the way the contract shapes them; the
 * default limits are the free plan's (one theme, two plugins), and the
 * list profile uses limits of its own so its browser checks can tell its
 * numbers apart. `used` stays 0: this mock does not model slot binding
 * (the plugin only reads `limit`), so it never claims a slot is taken.
 *
 * `noquota` sends an empty object instead. That is deliberately off the
 * contract: it is the one way to see the slot sentences the plugin shows
 * when the service has reported no numbers.
 *
 * @param string $profile Mock profile.
 * @return array|stdClass
 */
function babilim_mock_quota( string $profile ) {
	if ( 'noquota' === $profile ) {
		return new stdClass();
	}
	return array(
		'free_slots' => array(
			'theme'  => array( 'used' => 0, 'limit' => 1 ),
			'plugin' => array( 'used' => 0, 'limit' => 'list' === $profile ? 3 : 2 ),
		),
		'downloads'  => array( 'used' => 0, 'limit' => 500, 'period' => 'daily' ),
	);
}

/**
 * `POST /v1/sites` 201 body.
 *
 * @param int    $number How many sites registered so far, this one included.
 * @param string $nonce  The challenge nonce.
 * @return array
 */
function babilim_mock_register_body( int $number, string $nonce ): array {
	return array(
		'site_id'   => babilim_mock_site_id( $number ),
		'challenge' => array( 'nonce' => $nonce, 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 900 ) ),
	);
}

/**
 * The site id issued to the n-th registration, in the contract's shape
 * (`st_` and 12 hex digits).
 *
 * @param int $number Registration number.
 * @return string
 */
function babilim_mock_site_id( int $number ): string {
	return 'st_' . sprintf( '%012x', $number );
}

/**
 * The site token issued to the n-th verified registration, in the
 * contract's shape (`tok_` and 32 hex digits).
 *
 * @param int $number Registration number.
 * @return string
 */
function babilim_mock_token( int $number ): string {
	return 'tok_' . md5( 'babilim-mock-token-' . $number );
}

/**
 * `POST /v1/sites/{id}/verify` 200 body.
 *
 * @param string $token The site token issued.
 * @return array
 */
function babilim_mock_verify_body( string $token ): array {
	return array(
		'site_token' => $token,
		'verified'   => true,
		'license'    => array( 'kind' => 'free', 'status' => 'active' ),
		'quota'      => babilim_mock_quota( '' ),
	);
}

/**
 * `POST /v1/sites/{id}/inventory` 200 body.
 *
 * @param mixed  $body         The decoded request body.
 * @param string $profile      Mock profile.
 * @param string $update_build The build id the errors profile lists as an update.
 * @return array
 */
function babilim_mock_inventory_body( $body, string $profile, string $update_build = 'bd_0badc0de0bad' ): array {
	$locales    = is_array( $body ) && is_array( $body['locales'] ?? null ) ? $body['locales'] : array();
	$components = array();
	$updates    = array();
	foreach ( is_array( $body ) && is_array( $body['components'] ?? null ) ? $body['components'] : array() as $component ) {
		if ( 'bulk' === $profile && 0 === strpos( (string) ( $component['slug'] ?? '' ), 'bulk-' ) ) {
			// The bulk scenarios: every bulk-* component has a build for de_DE.
			$slug         = (string) $component['slug'];
			$components[] = array(
				'kind'    => (string) ( $component['kind'] ?? 'plugin' ),
				'slug'    => $slug,
				'locales' => (object) array(
					'de_DE' => array(
						'status'       => 'babilim_available',
						'auto_install' => true,
						'build_id' => 'bd_' . substr( md5( $slug . 'de_DE' ), 0, 12 ),
						'build_no' => 1,
						'built_at' => '2026-09-20T08:00:00Z',
					),
				),
			);
			continue;
		}
		if ( 'list' === $profile || 'noquota' === $profile ) {
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
		if ( 'errors' === $profile && 'errors-demo' === (string) ( $component['slug'] ?? '' ) && array() !== $locales ) {
			// The errors profile: errors-demo always lists one pending update
			// whose build the download endpoint refuses (see `fail_builds`), so
			// the plugin's install step fails on every run.
			$updates[] = array(
				'kind'     => 'plugin',
				'slug'     => 'errors-demo',
				'locale'   => (string) reset( $locales ),
				'build_id' => $update_build,
				'build_no' => 2,
			);
		}
	}
	return array(
		'components' => $components,
		'updates'    => $updates,
		'license'    => array( 'kind' => 'free', 'status' => 'active' ),
		'quota'      => babilim_mock_quota( $profile ),
		// Neither checkout nor source uploads are offered by this mock.
		'features'   => array( 'checkout' => false, 'upload' => false ),
		'contract'   => '1.11.0',
	);
}
