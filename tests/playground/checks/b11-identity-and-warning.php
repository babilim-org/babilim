<?php
/**
 * The whole "verification warning" and "site identity survives a default
 * uninstall" story, on a real WordPress:
 *
 * 1. With consent given, registration succeeds but the mock service's
 *    verify answers `403 verification_required` (`callback_unreachable`):
 *    no token is stored, the warning is recorded, and the admin notice
 *    shows the warning to an administrator.
 * 2. The real uninstall.php runs with the delete-all setting off: the
 *    warning and every other option are gone, the key and site id stay.
 * 3. Reinstalling registers again with the SAME install key (the mock saw
 *    the same public key twice), and once the service can verify, a token
 *    is stored.
 * 4. A second default uninstall of that now-verified site keeps the key,
 *    site id and token, and a fresh instance picks them up without any
 *    further registration request.
 * 5. With the delete-all setting on, the same uninstall removes all three.
 *
 * Runs last: uninstall.php also purges the served language files.
 */

declare( strict_types=1 );

/**
 * @param string $path Mock-service control path.
 * @return array<string,mixed>
 */
function babilim_pg_b11_mock( string $path ): array {
	$response = wp_remote_get( BABILIM_API_BASE . $path, array( 'timeout' => 10 ) );
	babilim_pg_assert( ! is_wp_error( $response ), 'mock service unreachable: ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	babilim_pg_assert( is_array( $decoded ), 'mock service returned a non-JSON body' );
	return $decoded;
}

/** A brand-new Babilim_Site, as a fresh request after a reinstall would build one. */
function babilim_pg_b11_site(): Babilim_Site {
	babilim_pg_resync_singleton();
	$method = new ReflectionMethod( Babilim::class, 'site' );
	$method->setAccessible( true );
	return $method->invoke( Babilim::instance() );
}

function babilim_pg_b11_uninstall(): void {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'babilim/babilim.php' );
	}
	require trailingslashit( WP_PLUGIN_DIR ) . 'babilim/uninstall.php';
}

babilim_pg_run(
	'b11-identity-and-warning',
	'verification failure is a warning with no token; a default uninstall keeps the site identity and a reinstall reuses it; delete-all removes it',
	static function () {
		update_option( Babilim_Site::OPTION_CONSENT, 1 );
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		babilim_pg_assert( ! empty( $admin ), 'no administrator account to view the notice as' );
		wp_set_current_user( (int) $admin[0]->ID );

		// The admin screens are only wired for an admin request; a real
		// screen object makes is_admin() true, as it is there.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );

		// 1. Verification fails: warning, no token, notice visible.
		$site   = babilim_pg_b11_site();
		$result = $site->ensure_registered();
		babilim_pg_assert( is_wp_error( $result ) && 'babilim_verification_required' === $result->get_error_code(), 'expected a verification_required error, got ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'success' ) );
		$warning = $site->verification_warning();
		babilim_pg_assert( null !== $warning && 'callback_unreachable' === $warning['reason'], 'the warning was not recorded with its reason' );
		babilim_pg_assert( null === $site->token() && false === get_option( 'babilim_identity', false ), 'a token exists although verification failed' );
		$first_site_id = $site->site_id();
		$first_sk      = get_option( 'babilim_install_sk' );
		babilim_pg_assert( is_string( $first_site_id ) && '' !== $first_site_id && is_string( $first_sk ) && '' !== $first_sk, 'site id or key missing after registration' );

		ob_start();
		do_action( 'admin_notices' );
		$notice = (string) ob_get_clean();
		babilim_pg_assert( false !== strpos( $notice, 'notice-warning' ) && false !== strpos( $notice, 'challenge' ), 'the administrator notice does not show the verification warning' );

		// 2. Default uninstall: identity stays, the rest goes.
		babilim_pg_b11_uninstall();
		babilim_pg_assert( false === get_option( 'babilim_verification_warning', false ), 'the warning survived the uninstall' );
		babilim_pg_assert( false === get_option( Babilim_Site::OPTION_CONSENT, false ), 'consent survived the uninstall' );
		babilim_pg_assert( $first_sk === get_option( 'babilim_install_sk' ), 'the install key did not survive a default uninstall' );
		babilim_pg_assert( null === get_option( 'babilim_identity', null ) || is_array( get_option( 'babilim_identity' ) ), 'the identity option is malformed after a default uninstall' );

		// 3. Reinstall: same key, and a token once the service can verify.
		babilim_pg_b11_mock( '/_control/verify-ok' );
		update_option( Babilim_Site::OPTION_CONSENT, 1 );
		$site   = babilim_pg_b11_site();
		$result = $site->ensure_registered();
		babilim_pg_assert( is_array( $result ), 'the reinstalled site could not register: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		babilim_pg_assert( $first_sk === get_option( 'babilim_install_sk' ), 'the key changed on reinstall' );
		$state = babilim_pg_b11_mock( '/_control/state' );
		$pubs  = (array) $state['registered'];
		babilim_pg_assert( 2 === count( $pubs ) && '' !== $pubs[0] && $pubs[0] === $pubs[1], 'the service did not see the same public key on both registrations: ' . wp_json_encode( $pubs ) );
		$token   = $site->token();
		$site_id = $site->site_id();
		babilim_pg_assert( null !== $token && null !== $site_id, 'no token after a successful verify' );

		// 4. Verified site, default uninstall: everything identity stays; no new registration.
		babilim_pg_b11_uninstall();
		babilim_pg_assert( array( 'site_id' => $site_id, 'token' => $token ) === get_option( 'babilim_identity' ) && $first_sk === get_option( 'babilim_install_sk' ), 'identity did not survive the second default uninstall' );
		update_option( Babilim_Site::OPTION_CONSENT, 1 );
		$site   = babilim_pg_b11_site();
		$result = $site->ensure_registered();
		babilim_pg_assert( is_array( $result ) && ! empty( $result['already_registered'] ), 'a kept identity was not picked up as already registered' );
		$state = babilim_pg_b11_mock( '/_control/state' );
		babilim_pg_assert( 2 === count( (array) $state['registered'] ), 'the service saw an extra registration for a kept identity' );

		// 5. Delete-all setting on: identity goes too.
		$deactivate_tag  = 'plugin_action_links_' . plugin_basename( WP_PLUGIN_DIR . '/babilim/babilim.php' );
		$deactivate_link = array( 'deactivate' => '<a href="plugins.php?action=deactivate" id="deactivate-babilim">Deactivate</a>' );
		$plain           = apply_filters( $deactivate_tag, $deactivate_link );
		babilim_pg_assert( false === strpos( (string) ( $plain['deactivate'] ?? '' ), 'confirm(' ), 'the Deactivate link asks for confirmation while delete-all is off' );
		update_option( 'babilim_delete_data_on_uninstall', true );
		$guarded = apply_filters( $deactivate_tag, $deactivate_link );
		babilim_pg_assert( false !== strpos( (string) ( $guarded['deactivate'] ?? '' ), 'onclick="return confirm(' ), 'the Deactivate link carries no confirmation while delete-all is on' );
		babilim_pg_b11_uninstall();
		babilim_pg_assert( false === get_option( 'babilim_install_sk', false ) && false === get_option( 'babilim_identity', false ), 'the delete-all setting left identity behind' );
	}
);
