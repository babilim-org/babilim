<?php
/**
 * Test infrastructure for the browser flow's "notices" scenario only; both
 * runners (run-ux-flow.sh and run-neutral-ux-flow.sh) install it as a
 * must-use plugin for that scenario and never ship it.
 *
 * Administrator-only URLs that put the plugin's own state where the
 * scenario needs it (a verification warning, waiting error reports, a pending
 * upload) and read it back. They call the plugin's classes and options,
 * nothing else.
 */

add_action( 'admin_init', static function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_GET['e2e_warning'] ) ) {
		list( $reason, $since ) = array_pad( explode( ':', (string) $_GET['e2e_warning'] ), 2, '0' );
		if ( 'none' === $reason ) {
			delete_option( Babilim_Site::OPTION_VERIFICATION_WARNING );
		} else {
			update_option( Babilim_Site::OPTION_VERIFICATION_WARNING, array( 'reason' => $reason, 'since' => (int) $since, 'retries' => 1, 'next_retry_at' => time() + 3600 ), false );
		}
		echo 'warning=' . $reason;
		exit;
	}
	if ( isset( $_GET['e2e_report'] ) ) {
		list( $code, $action, $origin ) = array_pad( explode( ':', (string) $_GET['e2e_report'] ), 3, '' );
		Babilim_Error_Reporter::for_site()->capture( $code, $action, $origin );
		echo 'captured=' . $code;
		exit;
	}
	if ( isset( $_GET['e2e_awaiting'] ) ) {
		if ( 'clear' === $_GET['e2e_awaiting'] ) {
			delete_option( Babilim_Error_Reporter::OPTION_AWAITING );
		}
		echo 'awaiting=' . count( Babilim_Error_Reporter::for_site()->awaiting() );
		exit;
	}
	if ( isset( $_GET['e2e_pending'] ) ) {
		$id = (string) $_GET['e2e_pending'];
		if ( 'none' === $id ) {
			delete_option( Babilim_Upload::OPTION );
		} else {
			update_option( Babilim_Upload::OPTION, array( 'upload_id' => $id, 'kind' => 'plugin', 'slug' => 'errors-demo', 'name' => 'Errors Demo', 'version' => '1.0.0', 'locale' => 'de_DE', 'part_bytes' => 1000, 'max_parts' => 5, 'max_bytes' => 5000, 'expires_at' => '2031-01-01T00:00:00Z', 'started' => time() ), false );
		}
		echo 'pending=' . $id;
		exit;
	}
	if ( isset( $_GET['e2e_pending_state'] ) ) {
		$p = Babilim_Upload::pending();
		echo null === $p ? 'pending=none' : 'pending=' . $p['upload_id'] . ' zip=' . ( isset( $p['zip'] ) ? 'yes' : 'no' );
		exit;
	}
} );
