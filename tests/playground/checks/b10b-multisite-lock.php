<?php
/**
 * Multisite -- Babilim_Manifest's own cross-request write lock excludes
 * every site of the network, not just the site a request is for.
 * `_manifest.json` lives under WP_LANG_DIR, one directory shared by the
 * whole network, so a request serving site 2 must never take the lock
 * while a request serving site 1 holds it -- and must get it as soon as
 * that one releases it. Runs in the same dedicated multisite boot as
 * b10-multisite.php, against real WordPress core's own switch_to_blog()
 * and its real per-site options tables.
 *
 * A single `wp eval-file` process plays both requests: it takes the lock
 * while site 1 is current, switches to site 2, and tries again there.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b10b-multisite-lock',
	'multisite: a manifest lock held while serving site 1 keeps site 2 out until released, and lives in the main site\'s options table',
	static function () {
		global $wpdb;
		babilim_pg_assert( function_exists( 'is_multisite' ) && is_multisite(), 'this WordPress is not running in multisite mode -- run-checks.sh should have booted this check with enableMultisite' );

		$lock     = 'babilim_manifest_lock';
		$manifest = ( new ReflectionClass( 'Babilim_Manifest' ) )->newInstanceWithoutConstructor();
		$acquire  = new ReflectionMethod( 'Babilim_Manifest', 'acquire_manifest_lock' );
		$release  = new ReflectionMethod( 'Babilim_Manifest', 'release_manifest_lock' );
		$acquire->setAccessible( true );
		$release->setAccessible( true );

		$row = static function ( string $table ) use ( $wpdb, $lock ): ?string {
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", $lock ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb itself.
			return null === $v ? null : (string) $v;
		};

		$main_site_id = get_current_blog_id();
		$main_table   = $wpdb->base_prefix . 'options';
		babilim_pg_assert( $main_table === $wpdb->options, "expected to start on the main site ({$main_table}), got {$wpdb->options}" );

		$second_site_id = wp_insert_site(
			array(
				'domain' => parse_url( home_url(), PHP_URL_HOST ),
				'path'   => '/b10b-lock-site/',
				'title'  => 'Multisite Lock Check Site',
			)
		);
		babilim_pg_assert( ! is_wp_error( $second_site_id ) && $second_site_id > 0, 'wp_insert_site() failed: ' . ( is_wp_error( $second_site_id ) ? $second_site_id->get_error_message() : var_export( $second_site_id, true ) ) );

		$held_one = $acquire->invoke( $manifest );
		babilim_pg_assert( is_string( $held_one ), 'site 1 could not take a free lock' );
		babilim_pg_assert( $held_one === $row( $main_table ), 'site 1\'s lock row is not in the main options table' );

		$held_two = null;
		switch_to_blog( (int) $second_site_id );
		try {
			$site_table = $wpdb->options;
			babilim_pg_assert( $site_table !== $main_table, "switch_to_blog() did not switch to a per-site options table (still {$site_table})" );

			$started  = microtime( true );
			$held_two = $acquire->invoke( $manifest );
			$waited   = (int) round( ( microtime( true ) - $started ) * 1000 );
			babilim_pg_assert( null === $held_two, "site 2 took the manifest lock after {$waited} ms while site 1 still held it -- two writers on one shared file" );
			babilim_pg_assert( $held_one === $row( $main_table ), 'site 1\'s lock was changed while site 2 was waiting for it' );
			babilim_pg_assert( null === $row( $site_table ), "a lock row appeared in site 2's own table ({$site_table})" );

			$release->invoke( $manifest, $held_one );
			$held_one = null;

			$started  = microtime( true );
			$held_two = $acquire->invoke( $manifest );
			$waited   = (int) round( ( microtime( true ) - $started ) * 1000 );
			babilim_pg_assert( is_string( $held_two ), 'once site 1\'s lock was released, site 2 could not take it' );
			babilim_pg_assert( $waited < 1000, "site 2 took {$waited} ms to take a lock that was already free" );
			babilim_pg_assert( $held_two === $row( $main_table ), 'site 2\'s lock row is not in the main options table' );
			babilim_pg_assert( null === $row( $site_table ), "site 2's lock landed in its own table ({$site_table})" );

			$release->invoke( $manifest, $held_two );
			$held_two = null;
			babilim_pg_assert( null === $row( $main_table ), 'site 2\'s release left the lock row behind' );
		} finally {
			if ( is_string( $held_two ) ) {
				$release->invoke( $manifest, $held_two );
			}
			restore_current_blog();
			if ( is_string( $held_one ) ) {
				$release->invoke( $manifest, $held_one );
			}
		}
		babilim_pg_assert( get_current_blog_id() === $main_site_id, 'restore_current_blog() did not return to the main site' );
	}
);
