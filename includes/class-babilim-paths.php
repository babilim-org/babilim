<?php
/**
 * Path helpers for Babilim's translation-file directories under
 * `WP_LANG_DIR/babilim/`.
 *
 * This is the plugin's one hard allow-list for where it may ever read or
 * write on disk -- every path any other class touches is either built
 * here or checked against is_allowed() here.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Path helpers for the plugin's language-file directories.
 */
final class Babilim_Paths {

	/**
	 * The directory types dir()/file() accept. Deliberately a closed
	 * list -- this is a hard allow-list, not a default to be extended.
	 *
	 * @var string[]
	 */
	private const DIR_TYPES = array(
		'plugins',
		'themes',
		'default',
		'default/_parts',
		'custom/plugins',
		'custom/themes',
		'_versions',
	);

	/**
	 * Bare extensions (case-insensitive) allowed under one of
	 * DIR_TYPES, besides the special-cased `.htaccess`/`index.php`
	 * (root only), `_manifest.json` (root only), and `*.l10n.php`.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PLAIN_EXTENSIONS = array( 'mo', 'po', 'json' );

	/**
	 * Extensions (case-insensitive) is_allowed()'s WRITE-time check (see
	 * its own `$for_write` parameter) permits under `_incoming/{token}/`
	 * -- an allow-list, not a denylist: only what this plugin's own
	 * write path there genuinely produces, enumerated by reading every
	 * actual write call site under `_incoming/`, is:
	 *
	 * - Babilim_Api_Client::fetch_package(), called from
	 *   admin/class-babilim-admin.php's do_download() and from
	 *   Babilim_Sync_Installer::install_build(), writes exactly one
	 *   file there per download: `{token}/package.zip`. That write also
	 *   creates the bare `{token}` directory (no extension -- see
	 *   has_allowed_incoming_write_extension()'s own docblock for why an
	 *   extension-less basename is allowed here too, not just the
	 *   extensions below).
	 *
	 * Nothing else this plugin's own code ever writes lands under
	 * `_incoming/` at all -- what DOES also land there, `.php` included,
	 * is whatever a foreign process or an interrupted run leaves behind
	 * -- nothing in this plugin's own control runs before that, so
	 * this allow-list can't prevent it, and isn't trying to: its only
	 * job is to stop THIS plugin's own code from ever writing
	 * something unexpected there itself (defense against a bug in this
	 * plugin). READING or REMOVING whatever ends up there anyway (a
	 * sweep, or Babilim_Sync_Installer::cleanup_incoming_recursive())
	 * is never gated by
	 * this list at all -- see is_allowed()'s own `$for_write` parameter
	 * for why.
	 *
	 * @var string[]
	 */
	private const INCOMING_WRITE_ALLOWED_EXTENSIONS = array( 'zip' );

	/**
	 * The manifest's own fixed filename, at root only.
	 *
	 * @var string
	 */
	private const MANIFEST_FILENAME = '_manifest.json';

	/**
	 * The installer's flock() file, directly under root().
	 *
	 * @var string
	 */
	public const INSTALL_LOCK_FILENAME = '.install.lock';

	/**
	 * The build-id shape version_dir()'s own $build_id segment must
	 * match -- the same `bd_{12 hex chars}` shape used everywhere else
	 * in the API contract for a server build id (the pinned contract
	 * schema's own `server_build_id`). The `D` modifier keeps `$` from
	 * also accepting a trailing newline.
	 *
	 * @var string
	 */
	private const BUILD_ID_PATTERN = '/^bd_[0-9a-f]{12}$/D';

	/**
	 * Exactly the two variant forms Babilim_Manifest ever creates
	 * alongside MANIFEST_FILENAME: an atomic-write temp file and a
	 * quarantine copy of a corrupt one, each named after 16 hex
	 * characters (8 random bytes, or a content hash prefix). Anchored
	 * with `D` so `$` can't be satisfied by a trailing newline.
	 *
	 * @var string
	 */
	private const MANIFEST_VARIANT_PATTERN = '/^_manifest\.json\.(tmp-[0-9a-f]{16}|corrupt-[0-9a-f]{16})$/D';

	/**
	 * The plugin's own root directory under WP_LANG_DIR. No trailing
	 * slash.
	 *
	 * @return string
	 */
	public static function root(): string {
		return rtrim( trailingslashit( WP_LANG_DIR ) . 'babilim', '/' );
	}

	/**
	 * One of the plugin's fixed subdirectories. No trailing slash.
	 *
	 * @param string $type One of self::DIR_TYPES.
	 * @return string
	 */
	public static function dir( string $type ): string {
		if ( ! in_array( $type, self::DIR_TYPES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $type is never user input; it's one of this class's own fixed DIR_TYPES constants, checked by the in_array() above. This message is never rendered as page output -- it's a programmer-facing diagnostic for a call this codebase itself controls every call site of.
			throw new InvalidArgumentException( "Unknown Babilim path type: {$type}" );
		}
		return self::root() . '/' . $type;
	}

	/**
	 * A `{name}-{locale}.{ext}` file path inside dir($type). When
	 * $type is 'default/_parts', $name is the contributing component's
	 * slug (these are source fragments, never loaded directly -- see
	 * merged_default()).
	 *
	 * @param string $type   One of self::DIR_TYPES.
	 * @param string $name   Domain (or, for 'default/_parts', slug).
	 * @param string $locale Locale code.
	 * @param string $ext    File extension, without the leading dot.
	 * @return string
	 */
	public static function file( string $type, string $name, string $locale, string $ext ): string {
		return self::dir( $type ) . '/' . $name . '-' . $locale . '.' . $ext;
	}

	/**
	 * The single merged-per-locale `default` domain index -- the only
	 * `default/` file ever actually loaded.
	 *
	 * @param string $locale Locale code.
	 * @param string $ext    File extension, without the leading dot.
	 * @return string
	 */
	public static function merged_default( string $locale, string $ext ): string {
		return self::dir( 'default' ) . '/_merged-' . $locale . '.' . $ext;
	}

	/**
	 * A `_versions/{kind}/{domain}/{locale}/{build_id}` history
	 * directory. Passing $kind = 'default' naturally nests it under a
	 * `default/` subdirectory -- no special-casing needed.
	 *
	 * @param string $kind     'plugins'|'themes'|'default'.
	 * @param string $domain   Domain or slug.
	 * @param string $locale   Locale code.
	 * @param string $build_id Build id.
	 * @return string
	 */
	public static function version_dir( string $kind, string $domain, string $locale, string $build_id ): string {
		return self::dir( '_versions' ) . '/' . $kind . '/' . $domain . '/' . $locale . '/' . $build_id;
	}

	/**
	 * The fixed build id `default_index_staging_dir()` reuses on every
	 * call -- deterministic (not random per rebuild) so a locale's
	 * merged-index scratch space doesn't grow a fresh directory on every
	 * `babilim_maintenance` run; the directory's contents are always
	 * fully overwritten and never inspected across rebuilds.
	 *
	 * @var string
	 */
	private const DEFAULT_INDEX_SCRATCH_BUILD_ID = 'bd_000000000000';

	/**
	 * A `_versions/default/_merge_scratch/{locale}/` staging directory --
	 * where Babilim_Default_Index::rebuild() writes the merged `default`
	 * domain index's `.mo`/`.l10n.php` before the atomic rename into
	 * merged_default(). Shaped exactly like version_dir() (so it needs no
	 * extra is_allowed() rule) with a fixed, non-random build id, one per
	 * locale.
	 *
	 * @param string $locale Locale code.
	 * @return string
	 */
	public static function default_index_staging_dir( string $locale ): string {
		return self::version_dir( 'default', '_merge_scratch', $locale, self::DEFAULT_INDEX_SCRATCH_BUILD_ID );
	}

	/**
	 * The dedicated `_incoming/{token}` directory one of this plugin's
	 * own downloads is unpacked into -- deliberately its own namespace, not one of DIR_TYPES
	 * (see is_known_directory()'s own note): this directory (and
	 * anything nested under it, any depth, any extension when reading or
	 * removing; when writing, only what this plugin's own write path
	 * there genuinely produces -- see is_within_incoming()'s own
	 * docblock, is_allowed()'s own `$for_write` parameter, and
	 * INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock for the two
	 * things that actually are: this bare token directory itself (a
	 * plain mkdir(), no extension), and the one `package.zip` a download
	 * writes here per admin/class-babilim-admin.php's do_download()) is
	 * normally created and removed empty by this plugin's own write
	 * path, so it needs none of the file-extension allowances a served
	 * directory needs. It isn't guaranteed to stay empty, though: an
	 * interrupted install can leave files behind (see
	 * Babilim_Sync_Installer::cleanup_incoming_recursive()), which is
	 * why anything under this path is allow-listed at all, not just
	 * the bare token directory. $token is caller-chosen
	 * and must itself satisfy is_safe_name().
	 *
	 * @param string $token Caller-chosen, path-safe token.
	 * @return string
	 */
	public static function incoming_dir( string $token ): string {
		return self::root() . '/_incoming/' . $token;
	}

	/**
	 * The bare `_incoming` directory itself, with no token -- for a
	 * maintenance sweep to list() and find leftover `{token}` entries
	 * older than they should be (an interrupted install -- see
	 * Babilim_Sync_Installer::sweep_stale_incoming_dirs()).
	 *
	 * @return string
	 */
	public static function incoming_root(): string {
		return self::root() . '/_incoming';
	}

	/**
	 * The single `_manifest.json` path, at root.
	 *
	 * @return string
	 */
	public static function manifest_file(): string {
		return self::root() . '/' . self::MANIFEST_FILENAME;
	}

	/**
	 * A fresh temp-file path for Babilim_Manifest's atomic write:
	 * `_manifest.json.tmp-{16 random hex chars}`.
	 *
	 * @return string
	 */
	public static function manifest_temp_file(): string {
		return self::root() . '/_manifest.json.tmp-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * The quarantine-copy path for one corrupt _manifest.json content:
	 * `_manifest.json.corrupt-{16 hex chars}`. Babilim_Manifest derives
	 * $content_hash_prefix from the corrupt content itself (the first 16
	 * hex characters of its sha1), so quarantining the same corrupt
	 * content twice always names the same file.
	 *
	 * @param string $content_hash_prefix Exactly 16 lowercase hex characters.
	 * @return string
	 */
	public static function manifest_quarantine_file( string $content_hash_prefix ): string {
		return self::root() . '/_manifest.json.corrupt-' . $content_hash_prefix;
	}

	/**
	 * Whether $path is safe to read or write:
	 *
	 * - no NUL byte, no `..`/`.` path segment;
	 * - under root(), and not merely string-prefixed by it (a sibling
	 *   directory like `{root}-evil` is rejected -- the prefix must be
	 *   followed by a `/` or be root itself);
	 * - anywhere under `_incoming/{token}/` (any depth, any extension
	 *   when reading/removing; when writing, only a bare extension-less
	 *   segment or one of INCOMING_WRITE_ALLOWED_EXTENSIONS -- see
	 *   is_within_incoming()'s own docblock for why that area alone
	 *   doesn't need the stricter rule below, and $for_write's own
	 *   docblock for the read/write split), OR one of the file forms
	 *   this plugin ever writes to a *served* location: `_manifest.json`
	 *   at root (plus its `.tmp-*`/`.corrupt-*` variants, matched
	 *   exactly -- see MANIFEST_VARIANT_PATTERN), `index.php`/
	 *   `.htaccess` at root only, `.mo`/`.po`/`.json`/`*.l10n.php`
	 *   under one of DIR_TYPES, or one of the directories the layout
	 *   itself creates (see is_known_directory()) -- never an arbitrary
	 *   extension-less file;
	 * - no existing ancestor (root included) is a symlink -- a layout
	 *   directory replaced with one (e.g. `plugins/`, even one that
	 *   still resolves back under root()) must not be able to redirect
	 *   a write.
	 *
	 * @param string $path      Absolute path.
	 * @param bool   $for_write Whether this check gates a write (put(),
	 *                          mkdir(), or a rename()/atomic_rename()
	 *                          destination -- anything that can leave a
	 *                          NEW file/directory at $path) as opposed to
	 *                          a read or a removal (get(), exists(),
	 *                          list(), is_dir(), delete(), rmdir(), or a
	 *                          rename()/atomic_rename() source). Only
	 *                          affects the `_incoming/{token}/` branch's
	 *                          own rule (see has_allowed_incoming_write_
	 *                          extension()): writing there is refused
	 *                          unless the path is one of the handful of
	 *                          shapes this plugin's own write path
	 *                          genuinely produces, but reading/removing
	 *                          must work on ANY extension regardless --
	 *                          `_incoming/` also, separately, holds
	 *                          whatever a foreign process or an
	 *                          interrupted run leaves behind, which this plugin's own write-
	 *                          time rule can't prevent and was never
	 *                          meant to -- its only job is to stop this
	 *                          plugin's own code from ever writing
	 *                          something unexpected there itself.
	 *                          Cleaning whatever's actually there back
	 *                          out (Babilim_Sync_Installer::
	 *                          cleanup_incoming_recursive(), a sweep)
	 *                          must not be blocked by the write-time rule
	 *                          -- defaults true (the more restrictive
	 *                          setting) so every call site this parameter
	 *                          predates keeps behaving exactly as before
	 *                          unless it deliberately opts into the
	 *                          looser, read/delete-shaped check.
	 * @return bool
	 */
	public static function is_allowed( string $path, bool $for_write = true ): bool {
		if ( false !== strpos( $path, "\0" ) ) {
			return false;
		}

		$normalized = self::normalize( $path );
		$segments   = explode( '/', trim( $normalized, '/' ) );
		if ( in_array( '..', $segments, true ) || in_array( '.', $segments, true ) ) {
			return false;
		}

		$root = rtrim( self::normalize( self::root() ), '/' );
		if ( $normalized !== $root && 0 !== strpos( $normalized, $root . '/' ) ) {
			return false;
		}

		if ( self::is_within_incoming( $normalized, $root ) ) {
			if ( $for_write && ! self::has_allowed_incoming_write_extension( $normalized ) ) {
				return false;
			}
			return self::no_symlink_escape( $normalized, $root );
		}

		if ( $normalized === $root . '/' . self::INSTALL_LOCK_FILENAME ) {
			// The installer's lock file (opened natively, because flock()
			// needs a handle): readable and removable here so uninstall
			// can clean it up, never written through the helper.
			return ! $for_write && self::no_symlink_escape( $normalized, $root );
		}

		if ( ! self::has_allowed_extension( $normalized, $root ) ) {
			return false;
		}

		return self::no_symlink_escape( $normalized, $root );
	}

	/**
	 * Whether $normalized is anywhere under `_incoming/{token}/` -- the
	 * token itself (matching what Babilim_Paths::incoming_dir() itself
	 * returns) or anything nested under it, any depth, any extension when
	 * reading/removing, only what has_allowed_incoming_write_extension()
	 * allows when writing (see that method's own docblock, and
	 * is_allowed()'s own `$for_write` parameter for why that allow-list
	 * is write-only -- is_allowed() checks it separately, before ever
	 * calling this method's own result against no_symlink_escape()).
	 * This area is a transient quarantine for whatever an interrupted install
	 * left there (see Babilim_Sync_Installer): it's
	 * only ever created empty and normally stays that way, but isn't
	 * guaranteed to when an install is interrupted (the
	 * scenario Babilim_Sync_Installer::cleanup_incoming_recursive() exists for
	 * -- and that cleanup must be able to remove ANYTHING left there,
	 * `.php` included, or the very thing left there than shouldn't have
	 * been becomes permanently stuck). Nothing under here is ever read
	 * back as translation content or served to a visitor, so it doesn't
	 * need the stricter, extension-whitelisted rule has_allowed_
	 * extension() enforces for a served directory -- `..`/`.` traversal
	 * and the NUL-byte check above already rule out the only real risk
	 * (escaping this area, or root(), entirely) beyond the write-time
	 * allow-list case.
	 *
	 * The bare `_incoming` directory itself (no token) is deliberately
	 * NOT matched here -- see is_known_directory()'s own bare-root check,
	 * needed only so a maintenance sweep can list() it.
	 *
	 * @param string $normalized An already-normalized path.
	 * @param string $root       The already-normalized root(), no trailing slash.
	 * @return bool
	 */
	private static function is_within_incoming( string $normalized, string $root ): bool {
		$prefix = $root . '/_incoming/';
		return 0 === strpos( $normalized, $prefix ) && strlen( $normalized ) > strlen( $prefix );
	}

	/**
	 * Whether $normalized is one of the shapes this plugin's own write
	 * path genuinely produces under `_incoming/{token}/` (see
	 * INCOMING_WRITE_ALLOWED_EXTENSIONS's own docblock for the enumerated
	 * write call sites this is built from): a bare extension-less
	 * basename (mkdir()'s own token directory, or an ancestor a write
	 * walks through), or a basename whose extension is one of
	 * INCOMING_WRITE_ALLOWED_EXTENSIONS.
	 *
	 * A trailing dot or trailing whitespace on the basename is refused
	 * outright, before even reaching pathinfo() -- `evil.php.` (a
	 * trailing dot) is exactly the shape that would otherwise report an
	 * EMPTY extension to pathinfo() (PHP's own behavior: a name ending in
	 * `.` has nothing after the last `.` to call an extension), which
	 * would incorrectly take this method's own "bare extension-less
	 * basename" allowance meant for a directory segment. Real WordPress
	 * core (`wp_check_filetype()`, `wp-includes/functions.php`) treats a
	 * trailing dot the same way this method does -- refusing it outright
	 * -- for the same reason.
	 *
	 * @param string $normalized An already-normalized path.
	 * @return bool
	 */
	private static function has_allowed_incoming_write_extension( string $normalized ): bool {
		$basename = basename( $normalized );
		if ( '' === $basename ) {
			return false;
		}
		if ( rtrim( $basename ) !== $basename || rtrim( $basename, '.' ) !== $basename ) {
			return false;
		}
		$ext = strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return true;
		}
		return in_array( $ext, self::INCOMING_WRITE_ALLOWED_EXTENSIONS, true );
	}

	/**
	 * Whether $s is safe to use as a domain/slug path component:
	 * `^[a-z0-9_-]+$`, case-insensitive. The `D` modifier keeps `$`
	 * from also accepting a trailing newline.
	 *
	 * @param string $s Candidate name.
	 * @return bool
	 */
	public static function is_safe_name( string $s ): bool {
		return 1 === preg_match( '/^[a-z0-9_\-]+$/iD', $s );
	}

	/**
	 * Whether $s is safe to use as a locale path component:
	 * `^[A-Za-z0-9_@-]+$`. The `D` modifier keeps `$` from also
	 * accepting a trailing newline.
	 *
	 * @param string $s Candidate locale.
	 * @return bool
	 */
	public static function is_safe_locale( string $s ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_@\-]+$/D', $s );
	}

	/**
	 * Creates every directory in DIR_TYPES (plus root()) and drops a
	 * protective `index.php` and `.htaccess` at root() if not already
	 * present. Safe to call more than once.
	 *
	 * @param Babilim_Fs $fs Filesystem to create the layout on.
	 */
	public static function ensure_layout( Babilim_Fs $fs ): void {
		$fs->mkdir( self::root() );
		foreach ( self::DIR_TYPES as $type ) {
			$fs->mkdir( self::dir( $type ) );
		}

		$index = self::root() . '/index.php';
		if ( ! $fs->exists( $index ) ) {
			$fs->put( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = self::root() . '/.htaccess';
		if ( ! $fs->exists( $htaccess ) ) {
			$fs->put(
				$htaccess,
				"<IfModule mod_authz_core.c>\n"
				. "\tRequire all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "\tOrder allow,deny\n"
				. "\tDeny from all\n"
				. "</IfModule>\n"
			);
		}
	}

	/**
	 * Every ancestor of $path, from the top down, root-first --
	 * `/a/b/c` becomes `['/a', '/a/b', '/a/b/c']`. Preserves a leading
	 * slash rather than dropping it the way a naive
	 * `explode('/', ltrim(...))` would. Shared by both Babilim_Fs
	 * implementations' mkdir().
	 *
	 * @param string $path Absolute path.
	 * @return string[]
	 */
	public static function ancestor_paths( string $path ): array {
		$normalized = rtrim( str_replace( '\\', '/', $path ), '/' );
		$segments   = explode( '/', $normalized );
		$current    = array_shift( $segments );
		$ancestors  = array();
		if ( '' !== $current ) {
			$ancestors[] = $current;
		}
		foreach ( $segments as $segment ) {
			$current     = ( '' === $current ) ? '/' . $segment : $current . '/' . $segment;
			$ancestors[] = $current;
		}
		return $ancestors;
	}

	/**
	 * ancestor_paths(), clipped to root() and below -- so a Babilim_Fs's
	 * mkdir() never creates (or, for the in-memory test double, never
	 * even records as existing) anything above the plugin's own root,
	 * such as WP_LANG_DIR itself.
	 *
	 * @param string $path Absolute path, expected to be under root().
	 * @return string[]
	 */
	public static function ancestors_from_root( string $path ): array {
		$root = self::root();
		return array_values(
			array_filter(
				self::ancestor_paths( $path ),
				static function ( $ancestor ) use ( $root ) {
					return $ancestor === $root || 0 === strpos( $ancestor, $root . '/' );
				}
			)
		);
	}

	/**
	 * Normalizes slashes without resolving `.`/`..` -- is_allowed()
	 * detects those as a violation rather than silently resolving them.
	 *
	 * @param string $path Path to normalize.
	 * @return string
	 */
	private static function normalize( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return (string) preg_replace( '#/+#', '/', $path );
	}

	/**
	 * The extension half of is_allowed(). See that method's docblock
	 * for the full set of forms this allows.
	 *
	 * @param string $normalized An already-normalized path.
	 * @param string $root       The already-normalized root(), no trailing slash.
	 * @return bool
	 */
	private static function has_allowed_extension( string $normalized, string $root ): bool {
		$basename = basename( $normalized );
		if ( '' === $basename ) {
			return false;
		}

		if ( $root === dirname( $normalized ) && self::is_manifest_variant( $basename ) ) {
			return true;
		}

		if ( $normalized === $root . '/index.php' || $normalized === $root . '/.htaccess' ) {
			return true;
		}
		if ( 'index.php' === $basename || '.htaccess' === $basename ) {
			// Anywhere other than root -- not the files the layout creates.
			return false;
		}

		if ( false === strpos( $basename, '.' ) ) {
			// No extension: only the directories the layout actually
			// creates are allowed here, never an arbitrary extension-less
			// file (see is_known_directory()).
			return self::is_known_directory( $normalized, $root );
		}

		if ( ! self::under_a_layout_dir( $normalized, $root ) ) {
			return false;
		}

		if ( strlen( $basename ) > 9 && '.l10n.php' === substr( $basename, -9 ) ) {
			return true;
		}

		$ext = strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::ALLOWED_PLAIN_EXTENSIONS, true );
	}

	/**
	 * Whether $basename is exactly `_manifest.json` or one of its two
	 * variant forms (see MANIFEST_VARIANT_PATTERN) -- the caller must
	 * separately confirm its parent directory is root().
	 *
	 * @param string $basename A bare filename (no directory part).
	 * @return bool
	 */
	private static function is_manifest_variant( string $basename ): bool {
		return self::MANIFEST_FILENAME === $basename
			|| 1 === preg_match( self::MANIFEST_VARIANT_PATTERN, $basename );
	}

	/**
	 * Whether $normalized is one of the directories the layout actually
	 * creates: root() itself, one of DIR_TYPES's paths, an ancestor of
	 * one (needed for mkdir()'s stepwise walk, e.g. `custom/` on the
	 * way to `custom/plugins`), or an ancestor of a version_dir() --
	 * `_versions/{kind}/{domain}/{locale}/{build_id}` -- up to and
	 * including that exact depth, with each present segment checked
	 * against the exact rule version_dir() itself builds it from (see
	 * is_known_version_dir_segments()). Nothing deeper is a directory the
	 * layout ever creates: files version_dir() holds always have an
	 * extension and go through the ordinary extension check instead, so
	 * an extensionless path one level below it (e.g.
	 * `_versions/plugins/woo/ja/bd_.../noext`) is rejected rather than
	 * waved through as "somewhere under `_versions/`".
	 *
	 * @param string $normalized An already-normalized path.
	 * @param string $root       The already-normalized root(), no trailing slash.
	 * @return bool
	 */
	private static function is_known_directory( string $normalized, string $root ): bool {
		if ( $normalized === $root ) {
			return true;
		}
		$versions_prefix = $root . '/_versions/';
		if ( 0 === strpos( $normalized, $versions_prefix ) ) {
			$relative = substr( $normalized, strlen( $versions_prefix ) );
			return self::is_known_version_dir_segments( explode( '/', $relative ) );
		}
		if ( $normalized === $root . '/_incoming' ) {
			// The bare parent itself -- needed so a maintenance sweep can
			// list() it to find leftover `_incoming/{token}` entries.
			// Anything *under* `_incoming/` is handled earlier, directly
			// in is_allowed() -- see is_within_incoming()'s own docblock.
			return true;
		}
		foreach ( self::DIR_TYPES as $type ) {
			$type_path = $root . '/' . $type;
			if ( $normalized === $type_path || 0 === strpos( $type_path, $normalized . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether $segments -- a path already known to sit under
	 * `_versions/`, split on `/` -- is exactly version_dir()'s own shape
	 * at whatever depth it reaches: `kind/domain/locale/build_id`, each
	 * segment checked against the specific rule that segment has to
	 * satisfy, up to and including the full 4-segment depth. Anything
	 * deeper, or any present segment that fails its own check, is
	 * rejected -- so `_versions/addons` (an unknown kind) and
	 * `_versions/plugins/woo/ja/not-a-build-id` are both rejected, not
	 * just a path five segments deep.
	 *
	 * @param string[] $segments Path segments relative to `_versions/`.
	 * @return bool
	 */
	private static function is_known_version_dir_segments( array $segments ): bool {
		if ( count( $segments ) > 4 ) {
			return false;
		}
		if ( isset( $segments[0] ) && ! in_array( $segments[0], array( 'plugins', 'themes', 'default' ), true ) ) {
			return false;
		}
		if ( isset( $segments[1] ) && ! self::is_safe_name( $segments[1] ) ) {
			return false;
		}
		if ( isset( $segments[2] ) && ! self::is_safe_locale( $segments[2] ) ) {
			return false;
		}
		if ( isset( $segments[3] ) && 1 !== preg_match( self::BUILD_ID_PATTERN, $segments[3] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether $normalized sits under one of DIR_TYPES's directories
	 * (at any depth -- version_dir() nests further under `_versions/`).
	 *
	 * @param string $normalized An already-normalized path.
	 * @param string $root       The already-normalized root(), no trailing slash.
	 * @return bool
	 */
	private static function under_a_layout_dir( string $normalized, string $root ): bool {
		foreach ( self::DIR_TYPES as $type ) {
			if ( 0 === strpos( $normalized, $root . '/' . $type . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The symlink half of is_allowed(): rejects a path if root() itself,
	 * or any existing ancestor on the way down to the target, is a
	 * symlink -- wherever it points. (A separate realpath()-boundary
	 * check is deliberately absent: given every
	 * ancestor here is already proven not to be a symlink, and `..`/`.`
	 * segments are already rejected earlier in is_allowed(), realpath()
	 * of the resulting path can never actually diverge from the literal
	 * path under root() -- the check could never fire, so this is the
	 * only defense, not one of two.)
	 *
	 * @param string $normalized An already-normalized, already extension-checked path.
	 * @param string $root       The already-normalized root(), no trailing slash.
	 * @return bool
	 */
	private static function no_symlink_escape( string $normalized, string $root ): bool {
		if ( is_link( $root ) ) {
			return false;
		}

		$relative = ltrim( substr( $normalized, strlen( $root ) ), '/' );
		if ( '' === $relative ) {
			return true;
		}

		$current = $root;
		foreach ( explode( '/', $relative ) as $part ) {
			$current = $current . '/' . $part;
			if ( is_link( $current ) ) {
				return false;
			}
			if ( ! file_exists( $current ) ) {
				break;
			}
		}

		return true;
	}
}
