<?php
/**
 * Filesystem abstraction for everything Babilim writes under
 * `WP_LANG_DIR/babilim/`.
 *
 * `Babilim_Wp_Fs` is the real, production implementation (wraps
 * WordPress's own `WP_Filesystem` abstraction). `Babilim_Memory_Fs` is
 * an in-memory test double with the same contract, used by unit tests
 * that need a filesystem without touching disk.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal filesystem contract, implemented by both the real
 * WP_Filesystem-backed writer and the in-memory test double.
 */
interface Babilim_Fs {

	/**
	 * Writes $contents to $path, creating or overwriting the file.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 * @return bool True on success.
	 */
	public function put( string $path, string $contents ): bool;

	/**
	 * Reads a file's contents.
	 *
	 * @param string $path Absolute path.
	 * @return string|WP_Error|null The contents; null if the file
	 *                               doesn't exist; a WP_Error if it
	 *                               exists but couldn't be read.
	 */
	public function get( string $path );

	/**
	 * Whether a file or directory exists at $path.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function exists( string $path ): bool;

	/**
	 * Deletes a file. A no-op (returns true) if it doesn't exist.
	 *
	 * @param string $path Absolute path.
	 * @return bool True on success.
	 */
	public function delete( string $path ): bool;

	/**
	 * Renames/moves a file from one path to another.
	 *
	 * @param string $from Absolute source path.
	 * @param string $to   Absolute destination path.
	 * @return bool True on success.
	 */
	public function rename( string $from, string $to ): bool;

	/**
	 * Creates a directory, including any missing parent directories.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool True on success (including when it already exists).
	 */
	public function mkdir( string $path ): bool;

	/**
	 * Removes a directory -- but ONLY if it's already empty; never
	 * recursive, so this can never wipe out anything it didn't itself
	 * just empty via delete(). A no-op (returns true) if the directory
	 * doesn't exist; returns false (without throwing) if it exists but
	 * still has something in it. Used to clean up a
	 * directory this plugin has just emptied itself -- never on a
	 * directory that might still hold something this class doesn't own,
	 * and always treated as best-effort (a false return never fails the
	 * caller's own operation).
	 *
	 * @param string $path Absolute directory path.
	 * @return bool True if removed or already absent; false if it still
	 *              has contents.
	 */
	public function rmdir( string $path ): bool;

	/**
	 * Whether $path is an existing directory. False for a file or an
	 * absent path.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function is_dir( string $path ): bool;

	/**
	 * Lists the bare filenames directly inside $dir matching $glob.
	 * Follows WordPress core's own WP_Filesystem_Direct::dirlist()
	 * contract: when $dir is a *file*, the result is that file's own
	 * basename (if it matches $glob), not an empty array -- so an empty
	 * result never by itself means "not a directory"; use is_dir().
	 *
	 * @param string $dir  Absolute directory path.
	 * @param string $glob A glob pattern (fnmatch() syntax).
	 * @return string[] Matching bare filenames, sorted.
	 */
	public function list( string $dir, string $glob = '*' ): array;

	/**
	 * The sha256 digest of a file's contents.
	 *
	 * @param string $path Absolute path.
	 * @return string|WP_Error|null Hex digest; null if the file doesn't
	 *                               exist; a WP_Error if it exists but
	 *                               couldn't be read.
	 */
	public function sha256( string $path );
}
