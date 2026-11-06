<?php
/**
 * Run as a separate `php` process by
 * Test_Admin_Settings_Api::test_the_share_errors_hooks_fire_with_the_shared_options_table_too():
 * loads tests/bootstrap.php with the shared options table (the mode is
 * picked when the bootstrap loads), saves the error-report choice for
 * the first time (add_option_ hook) and then turns it off
 * (update_option_ hook), and prints what the hooks did.
 *
 * argv: <plugin_dir> <shared_dir>
 */

[ , $plugin_dir, $shared_dir ] = $argv;

putenv( 'BABILIM_TEST_OPTIONS_DIR=' . $shared_dir . '/options' );
require $plugin_dir . '/tests/bootstrap.php';

echo 'shared=' . ( babilim_test_options_are_shared() ? '1' : '0' ) . "\n";
( new Babilim_Admin( new Babilim_Memory_Fs(), new Babilim_Fake_Http( array() ) ) )->register_settings();
update_option( Babilim_Error_Reporter::OPTION_AWAITING, array( array( 'event_id' => str_repeat( 'b', 32 ), 'error_code' => 'download_failed', 'action' => 'download', 'origin_id' => 'site.sync', 'at' => '2026-10-01T00:00:00+00:00', 'attempts' => 0 ) ), false );
update_option( Babilim_Error_Reporter::OPTION_SHARE, 'on' );
echo 'added: awaiting=' . count( (array) get_option( Babilim_Error_Reporter::OPTION_AWAITING ) ) . ' queue=' . count( (array) get_option( Babilim_Error_Reporter::OPTION_QUEUE ) ) . "\n";
update_option( Babilim_Error_Reporter::OPTION_SHARE, null );
echo 'updated: queue=' . count( (array) get_option( Babilim_Error_Reporter::OPTION_QUEUE ) ) . "\n";
