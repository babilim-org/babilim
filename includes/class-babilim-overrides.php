<?php
/**
 * Access interface for the translation-override draft store: the strings a
 * site owner edited, with their base text, status and history. The store
 * is one custom table (see Babilim_Wpdb_Overrides); callers depend on this
 * interface so tests can swap in an in-memory double.
 *
 * A scope is array{kind:string,slug:string,domain:string,locale:string}
 * (kind is 'plugin' or 'theme'). A row has the keys listed on rows().
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Babilim_Overrides {

	const STATUS_ACTIVE = 'active';
	const STATUS_REVIEW = 'review';
	const STATUS_ORPHAN = 'orphan';
	const STATUS_OFF    = 'off';

	/** Entries kept in a row's `history`, newest first. */
	const HISTORY_MAX = 10;

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
	public function ready(): bool;

	/**
	 * The rows of one scope: saved rows, or draft rows when $drafts. Each row:
	 * ref, plural_index(int), msgctxt, msgid, msgid_plural, msgstr, status,
	 * origin ('editor'|'file'|'loco'), component_version, base_source
	 * ('babilim'|'wporg'|'bundled'|''), base_build, base_msgstr, match_ref,
	 * history (array<int,array{msgstr:string,at:string,by:int}>),
	 * feedback_build, modified_gmt, modified_by(int).
	 *
	 * @param array $scope  Scope.
	 * @param bool  $drafts Draft rows instead of saved rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function rows( array $scope, bool $drafts = false ): array;

	/**
	 * Inserts or updates one row, keyed by (scope, ref, plural_index, draft).
	 *
	 * @param array $scope Scope.
	 * @param array $row   Row.
	 * @param bool  $draft Store as a draft row.
	 * @return bool
	 */
	public function put( array $scope, array $row, bool $draft = false ): bool;

	/**
	 * Sets only status, match_ref and component_version of one saved row,
	 * keyed by (scope, ref, plural_index); every other column, the text and
	 * its history above all, stays as it is now. A row that is gone or was
	 * switched off meanwhile is left alone.
	 *
	 * @param array $scope Scope.
	 * @param array $row   Row: ref, plural_index, status, match_ref, component_version.
	 * @return bool False only when the store failed.
	 */
	public function mark( array $scope, array $row ): bool;

	/**
	 * Deletes every plural form of one string.
	 *
	 * @param array  $scope Scope.
	 * @param string $ref   Babilim_Translation_Search::ref() of the string.
	 * @param bool   $draft Delete the draft rows instead of the saved ones.
	 * @return bool
	 */
	public function delete( array $scope, string $ref, bool $draft = false ): bool;

	/**
	 * Every scope that has at least one saved row.
	 *
	 * @return array<int,array{kind:string,slug:string,domain:string,locale:string}>
	 */
	public function scopes(): array;

	/**
	 * Deletes every row, saved and draft, of every scope.
	 *
	 * @return bool
	 */
	public function clear(): bool;
}
