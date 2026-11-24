<?php
/**
 * Babilim_Overrides on the `{base_prefix}babilim_overrides` table: one
 * table for the whole network, created with dbDelta() and removed on
 * uninstall only when the owner does not keep `custom/`. Every statement
 * is built with $wpdb->prepare() (identifiers via %i, WordPress 6.2+; the
 * plugin requires 6.6), called on a local `$wpdb` because the
 * WordPress.DB.PreparedSQL sniff only recognises a variable of that name
 * and reports every token of a prepare() reached through the property.
 * `scope` is md5 of "kind/slug/domain/locale" and exists only here, as
 * an index key; callers pass the scope as an array.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Babilim_Wpdb_Overrides implements Babilim_Overrides {

	/** Site option holding the schema version installed. */
	const OPTION_DB_VERSION = 'babilim_db_version';

	/** Schema version this code creates. */
	const DB_VERSION = '2';

	/** @var wpdb */
	private $wpdb;

	/** @var bool Set once the table was found; a missing table is probed again. */
	private $found = false;

	/**
	 * @param wpdb $wpdb Database object.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function table(): string {
		return $this->wpdb->base_prefix . 'babilim_overrides';
	}

	/**
	 * Records the schema version once the table and its indexes are ready.
	 * dbDelta() needs one field per line, two spaces after PRIMARY KEY, and
	 * KEY rather than INDEX.
	 */
	public function install(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$table   = $this->table();
		$charset = $this->wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope char(32) NOT NULL,
  ref char(64) NOT NULL,
  plural_index smallint(5) unsigned NOT NULL DEFAULT 0,
  is_draft tinyint(1) NOT NULL DEFAULT 0,
  kind varchar(10) NOT NULL,
  slug varchar(200) NOT NULL,
  domain varchar(200) NOT NULL,
  locale varchar(35) NOT NULL,
  msgctxt longtext NOT NULL,
  msgid longtext NOT NULL,
  msgid_plural longtext NOT NULL,
  msgstr longtext NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'active',
  origin varchar(10) NOT NULL DEFAULT 'editor',
  component_version varchar(64) NOT NULL DEFAULT '',
  base_source varchar(10) NOT NULL DEFAULT '',
  base_build varchar(15) NOT NULL DEFAULT '',
  base_msgstr longtext NOT NULL,
  match_ref char(64) NOT NULL DEFAULT '',
  history longtext NOT NULL,
  feedback_build varchar(15) NOT NULL DEFAULT '',
  modified_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  modified_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY entry (scope,ref,plural_index,is_draft),
  KEY draft_status (is_draft,status)
) {$charset};";
		dbDelta( $sql );
		if ( $this->ready() && $this->upgrade_indexes() ) {
			update_site_option( self::OPTION_DB_VERSION, self::DB_VERSION );
		}
	}

	/** dbDelta adds the new index but leaves the redundant one behind. */
	private function upgrade_indexes(): bool {
		$wpdb        = $this->wpdb;
		$indexes_sql = $wpdb->prepare( 'SHOW INDEX FROM %i', $this->table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared schema probe. Column 2 is Key_name.
		$indexes = $wpdb->get_col( $indexes_sql, 2 );
		if ( ! in_array( 'draft_status', $indexes, true ) ) {
			return false;
		}
		if ( ! in_array( 'status', $indexes, true ) ) {
			return true;
		}
		// Another upgrader can drop the same key between SHOW INDEX and ALTER TABLE.
		$suppressed = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin schema migration.
			$dropped = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $this->table(), 'status' ) );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
		if ( false !== $dropped ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared schema probe.
		$indexes = $wpdb->get_col( $indexes_sql, 2 );
		return in_array( 'draft_status', $indexes, true ) && ! in_array( 'status', $indexes, true );
	}

	/** Runs install() unless this schema version is already recorded. */
	public function upgrade(): void {
		if ( self::DB_VERSION !== get_site_option( self::OPTION_DB_VERSION ) ) {
			$this->install();
		}
	}

	public function ready(): bool {
		if ( $this->found ) {
			return true;
		}
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe.
		return $this->found = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
	}

	public function rows( array $scope, bool $drafts = false ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$found = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE scope = %s AND is_draft = %d ORDER BY id', $this->table(), self::scope_key( $scope ), (int) $drafts ),
			'ARRAY_A'
		);
		$out   = array();
		foreach ( (array) $found as $r ) {
			$history = json_decode( (string) $r['history'], true );
			$out[]   = array(
				'ref'               => (string) $r['ref'],
				'plural_index'      => (int) $r['plural_index'],
				'msgctxt'           => (string) $r['msgctxt'],
				'msgid'             => (string) $r['msgid'],
				'msgid_plural'      => (string) $r['msgid_plural'],
				'msgstr'            => (string) $r['msgstr'],
				'status'            => (string) $r['status'],
				'origin'            => (string) $r['origin'],
				'component_version' => (string) $r['component_version'],
				'base_source'       => (string) $r['base_source'],
				'base_build'        => (string) $r['base_build'],
				'base_msgstr'       => (string) $r['base_msgstr'],
				'match_ref'         => (string) $r['match_ref'],
				'history'           => is_array( $history ) ? array_values( $history ) : array(),
				'feedback_build'    => (string) $r['feedback_build'],
				'modified_gmt'      => (string) $r['modified_gmt'],
				'modified_by'       => (int) $r['modified_by'],
			);
		}
		return $out;
	}

	const COLUMNS = 'scope, ref, plural_index, is_draft, kind, slug, domain, locale, msgctxt, msgid, msgid_plural, msgstr, status, origin, component_version, base_source, base_build, base_msgstr, match_ref, history, feedback_build, modified_gmt, modified_by';

	const PLACEHOLDERS = '(%s, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d)';

	const ON_DUPLICATE = ' ON DUPLICATE KEY UPDATE kind = VALUES(kind), slug = VALUES(slug), domain = VALUES(domain), locale = VALUES(locale), msgctxt = VALUES(msgctxt), msgid = VALUES(msgid), msgid_plural = VALUES(msgid_plural), msgstr = VALUES(msgstr), status = VALUES(status), origin = VALUES(origin), component_version = VALUES(component_version), base_source = VALUES(base_source), base_build = VALUES(base_build), base_msgstr = VALUES(base_msgstr), match_ref = VALUES(match_ref), history = VALUES(history), feedback_build = VALUES(feedback_build), modified_gmt = VALUES(modified_gmt), modified_by = VALUES(modified_by)';

	/** The values of one row, in COLUMNS order, for prepare(). */
	private static function values( array $scope, array $row, bool $draft ): array {
		$modified = (string) ( $row['modified_gmt'] ?? '' );
		$history  = wp_json_encode( is_array( $row['history'] ?? null ) ? array_values( $row['history'] ) : array() );
		return array(
			self::scope_key( $scope ),
			(string) ( $row['ref'] ?? '' ),
			(int) ( $row['plural_index'] ?? 0 ),
			(int) $draft,
			(string) $scope['kind'],
			(string) $scope['slug'],
			(string) $scope['domain'],
			(string) $scope['locale'],
			(string) ( $row['msgctxt'] ?? '' ),
			(string) ( $row['msgid'] ?? '' ),
			(string) ( $row['msgid_plural'] ?? '' ),
			(string) ( $row['msgstr'] ?? '' ),
			(string) ( $row['status'] ?? self::STATUS_ACTIVE ),
			(string) ( $row['origin'] ?? 'editor' ),
			(string) ( $row['component_version'] ?? '' ),
			(string) ( $row['base_source'] ?? '' ),
			(string) ( $row['base_build'] ?? '' ),
			(string) ( $row['base_msgstr'] ?? '' ),
			(string) ( $row['match_ref'] ?? '' ),
			false === $history ? '[]' : $history,
			(string) ( $row['feedback_build'] ?? '' ),
			'' === $modified ? gmdate( 'Y-m-d H:i:s' ) : $modified,
			(int) ( $row['modified_by'] ?? 0 ),
		);
	}

	public function put( array $scope, array $row, bool $draft = false ): bool {
		return $this->put_many( $scope, array( $row ), $draft );
	}

	public function put_many( array $scope, array $rows, bool $draft = false ): bool {
		$wpdb = $this->wpdb;
		foreach ( array_chunk( array_values( $rows ), self::BATCH_MAX ) as $chunk ) {
			$args = array( $this->table() );
			foreach ( $chunk as $row ) {
				$args = array_merge( $args, self::values( $scope, $row, $draft ) );
			}
			// One atomic multi-row upsert on the `entry` unique key.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table; COLUMNS, PLACEHOLDERS and ON_DUPLICATE are SQL literals (class constants the sniff cannot follow) and every value goes through prepare() as %s/%d.
			$done = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (' . self::COLUMNS . ') VALUES ' . implode( ', ', array_fill( 0, count( $chunk ), self::PLACEHOLDERS ) ) . self::ON_DUPLICATE, $args ) );
			if ( false === $done ) {
				return false;
			}
		}
		return true;
	}

	public function mark( array $scope, array $row ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$done = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, match_ref = %s, component_version = %s WHERE scope = %s AND ref = %s AND plural_index = %d AND is_draft = 0 AND status <> %s',
				$this->table(),
				(string) ( $row['status'] ?? self::STATUS_ACTIVE ),
				(string) ( $row['match_ref'] ?? '' ),
				(string) ( $row['component_version'] ?? '' ),
				self::scope_key( $scope ),
				(string) ( $row['ref'] ?? '' ),
				(int) ( $row['plural_index'] ?? 0 ),
				self::STATUS_OFF
			)
		);
		return false !== $done;
	}

	public function mark_feedback( array $scope, string $ref, string $build ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		return false !== $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET feedback_build = %s WHERE scope = %s AND ref = %s AND base_build = %s AND is_draft = 0',
			$this->table(),
			$build,
			self::scope_key( $scope ),
			$ref,
			$build
		) );
	}

	public function delete( array $scope, string $ref, bool $draft = false ): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$done = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE scope = %s AND ref = %s AND is_draft = %d', $this->table(), self::scope_key( $scope ), $ref, (int) $draft )
		);
		return false !== $done;
	}

	public function clear(): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		return false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );
	}

	public function scopes( string $status = '' ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$found = '' === $status ? $wpdb->get_results(
			$wpdb->prepare( 'SELECT DISTINCT kind, slug, domain, locale FROM %i WHERE is_draft = %d ORDER BY kind, slug, domain, locale', $this->table(), 0 ),
			'ARRAY_A'
		) : $wpdb->get_results(
			$wpdb->prepare( 'SELECT DISTINCT kind, slug, domain, locale FROM %i WHERE is_draft = %d AND status = %s ORDER BY kind, slug, domain, locale', $this->table(), 0, $status ),
			'ARRAY_A'
		);
		return self::scope_list( $found );
	}

	public function scope_versions(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$found = $wpdb->get_results(
			$wpdb->prepare( 'SELECT DISTINCT kind, slug, domain, locale, component_version FROM %i WHERE is_draft = %d AND status <> %s ORDER BY kind, slug, domain, locale', $this->table(), 0, self::STATUS_OFF ),
			'ARRAY_A'
		);
		$out   = array();
		foreach ( (array) $found as $r ) {
			$out[] = array(
				'kind'              => (string) $r['kind'],
				'slug'              => (string) $r['slug'],
				'domain'            => (string) $r['domain'],
				'locale'            => (string) $r['locale'],
				'component_version' => (string) $r['component_version'],
			);
		}
		return $out;
	}

	public function feedback_scopes( string $since ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT kind, slug, domain, locale FROM %i WHERE is_draft = %d AND origin = 'editor' AND base_source = 'babilim' AND base_build <> '' AND status = %s AND modified_gmt >= %s AND feedback_build <> base_build ORDER BY kind, slug, domain, locale",
				$this->table(),
				0,
				self::STATUS_ACTIVE,
				$since
			),
			'ARRAY_A'
		);
		return self::scope_list( $found );
	}

	/**
	 * @param mixed $found get_results() output.
	 * @return array<int,array{kind:string,slug:string,domain:string,locale:string}>
	 */
	private static function scope_list( $found ): array {
		$out = array();
		foreach ( (array) $found as $r ) {
			$out[] = array(
				'kind'   => (string) $r['kind'],
				'slug'   => (string) $r['slug'],
				'domain' => (string) $r['domain'],
				'locale' => (string) $r['locale'],
			);
		}
		return $out;
	}

	private static function scope_key( array $scope ): string {
		return md5( $scope['kind'] . '/' . $scope['slug'] . '/' . $scope['domain'] . '/' . $scope['locale'] );
	}
}
