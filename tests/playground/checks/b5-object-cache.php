<?php
/**
 * A persistent object cache (Redis/Memcached in production; the
 * always-present, in-process `WP_Object_Cache` here -- see
 * docs/playground-results.md for why that's enough to exercise this
 * specific contract) can hold a stale `translation_files` cache group
 * entry for a directory WordPress core itself would otherwise re-scan.
 * Babilim_Default_Index::rebuild()'s own write path calls
 * `wp_cache_delete(md5(trailingslashit($dir)), 'translation_files')`
 * (invalidate_cache_for_dir()) on every successful write -- this proves
 * that call actually reaches a real WordPress object-cache API and has a
 * real effect, not just that the private method exists.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b5',
	'a stale translation_files cache entry for the default/ directory is invalidated by a real rebuild(), not left stale',
	static function () {
		$locale = 'da_DK';
		$slug   = 'b5-plugin';
		$dir    = Babilim_Paths::dir( 'default' );
		$key    = md5( trailingslashit( $dir ) );

		babilim_pg_assert( function_exists( 'wp_cache_set' ) && function_exists( 'wp_cache_get' ), 'wp_cache_set/get not available in this WordPress' );

		// Simulate a directory listing WordPress core itself (or any other
		// code reading this same cache group/key) had already cached
		// before Babilim's own write below -- if rebuild() did NOT
		// invalidate it, this stale marker would still be readable
		// afterward.
		wp_cache_set( $key, array( 'STALE-MARKER' ), 'translation_files' );
		$still_there = wp_cache_get( $key, 'translation_files' );
		babilim_pg_assert( is_array( $still_there ) && ( $still_there[0] ?? null ) === 'STALE-MARKER', 'test setup bug: the stale marker did not read back before the real write' );

		$basename = babilim_pg_write_test_plugin( $slug, '1.0.0' );
		babilim_pg_activate( $basename );
		babilim_pg_seed_default_fragment( 'plugin', $slug, $locale, '1.0.0', array( 'Cache Sensitive' => 'Cache Sensitive Translated' ) );

		$result = babilim_pg_rebuild_default_index( $locale ); // The real write path -- calls invalidate_cache_for_dir() on success.
		babilim_pg_assert( $result->is_ok(), 'rebuild() failed: ' . $result->message() );

		$after = wp_cache_get( $key, 'translation_files' );
		babilim_pg_assert(
			false === $after,
			'the translation_files cache entry for default/ was still stale after a real rebuild() -- invalidate_cache_for_dir() did not reach the real object cache'
		);
	}
);
