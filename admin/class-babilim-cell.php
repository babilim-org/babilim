<?php
/**
 * The translation "cell": one (component, locale) pair as the
 * translations list shows it.
 *
 * Whether a Babilim build is installed on this site is decided from the
 * plugin's own manifest record, never from what the service last said;
 * the service's status only says where a translation could come from
 * and what can be done about it. The result keeps the source, the
 * installed flag, the update flag, the runnable action and the reason
 * an action is blocked as separate fields, so lists, row actions and
 * bulk actions read those and never work them out again from wording.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the display/action model of one cell.
 */
final class Babilim_Cell {

	/**
	 * Builds one cell.
	 *
	 * `$entry` is the locale entry from the last sync
	 * (Babilim_State::status()); `{}` or an empty array when the last
	 * sync did not list this locale. Two optional keys may be added by
	 * the caller (or left in the stored entry by an install), neither of
	 * which the service sends on a locale entry:
	 * `kind` ('plugin'|'theme', used for the bundled wording) and
	 * `update_build_id` (the build_id of the matching
	 * Babilim_State::update_for() entry, used to notice that an update
	 * the state still lists was already installed).
	 *
	 * On a not-installed `requestable` entry the service's generic
	 * `reason` code (`source_rejected`, `upload_expired`) turns the cell
	 * into a failure with a retry; any other reason is ignored.
	 *
	 * @param array      $entry           Locale entry from the last sync.
	 * @param string     $locale          Locale code.
	 * @param bool       $has_update      Whether the state lists an update for this cell.
	 * @param bool       $can_write       Whether this user may write translation files here.
	 * @param array|null $manifest_record The manifest record for this cell, or null.
	 * @param string     $site_version    The component version installed on this site, '' when unknown.
	 * @return array{source:string,installed:bool,is_update:bool,action:?string,action_label:string,busy_label:string,blocked_reason:?string,text:string,title:string,date:string,hardcoded_warning:string}
	 */
	public static function from_entry( array $entry, string $locale, bool $has_update, bool $can_write, ?array $manifest_record, string $site_version = '' ): array {
		$cell = self::build( $entry, $locale, $has_update, $can_write, $manifest_record, $site_version );

		// The service's `hardcoded_text_warning` is only ever sent on a
		// babilim_installed / babilim_available entry, and only as true;
		// anything else (absent, false, another type, another status)
		// means no note. Absence is not a claim that everything can be
		// translated.
		$status                    = (string) ( $entry['status'] ?? '' );
		$flagged                   = true === ( $entry['hardcoded_text_warning'] ?? null ) && in_array( $status, array( 'babilim_installed', 'babilim_available' ), true );
		$cell['hardcoded_warning'] = $flagged ? __( 'Some of this component\'s text is hard-coded by its author and can\'t be translated by a language pack.', 'babilim' ) : '';
		return $cell;
	}

	/**
	 * The cell without the hard-coded text note.
	 *
	 * @param array      $entry           Locale entry.
	 * @param string     $locale          Locale code.
	 * @param bool       $has_update      Whether the state lists an update.
	 * @param bool       $can_write       Whether files may be written.
	 * @param array|null $manifest_record The manifest record, or null.
	 * @param string     $site_version    The installed component version, '' when unknown.
	 * @return array
	 */
	private static function build( array $entry, string $locale, bool $has_update, bool $can_write, ?array $manifest_record, string $site_version ): array {
		unset( $locale ); // Part of the cell's identity; the wording does not depend on it.

		$status    = (string) ( $entry['status'] ?? '' );
		$installed = null !== $manifest_record;
		$build_id  = (string) ( $manifest_record['build_id'] ?? $entry['build_id'] ?? '' );

		if ( $installed && ! empty( $manifest_record['degraded_at'] ) ) {
			// Its served files were removed after a failed integrity check:
			// nothing is installed, and downloading the build fixes it.
			return self::degraded_cell( $can_write, $build_id );
		}

		if ( $installed ) {
			return self::installed_cell( $entry, $status, $has_update, $can_write, $manifest_record, $build_id, $site_version );
		}

		switch ( $status ) {
			case 'babilim_available':
			case 'babilim_installed':
				// A build exists on the service but this site has no
				// record of installing it (for instance its files were
				// removed): it can be downloaded again.
				return self::downloadable_cell( $entry, $can_write, $build_id );
			case 'wporg':
				return self::cell( 'wporg', false, false, 'babilim_request', null, __( 'WordPress.org', 'babilim' ), '' );
			case 'bundled':
				$text = 'theme' === ( $entry['kind'] ?? '' )
					? __( 'Bundled with the theme', 'babilim' )
					: __( 'Bundled with the plugin', 'babilim' );
				return self::cell( 'bundled', false, false, null, 'bundled', $text, '' );
			case 'requestable':
				// The service marks a rejected or expired source upload with
				// a generic reason code; show it as a failure with a retry.
				$reason = (string) ( $entry['reason'] ?? '' );
				if ( 'source_rejected' === $reason ) {
					return self::cell( 'none', false, false, 'babilim_request', null, __( 'Upload was not accepted', 'babilim' ), '', true );
				}
				if ( 'upload_expired' === $reason ) {
					return self::cell( 'none', false, false, 'babilim_request', null, __( 'Upload expired before it finished', 'babilim' ), '', true );
				}
				return self::cell( 'none', false, false, 'babilim_request', null, __( 'No translation', 'babilim' ), '' );
			case 'queued':
				if ( ! empty( $entry['upload_pending'] ) ) {
					// The service asked for this component's source and the
					// upload is not finished: the request button resumes it.
					if ( ! $can_write ) {
						return self::cell( 'none', false, false, null, 'network_capability', __( 'Upload in progress — continue or cancel', 'babilim' ), '' );
					}
					return self::cell( 'none', false, false, 'babilim_request', null, __( 'Upload in progress — continue or cancel', 'babilim' ), '' );
				}
				return self::cell( 'none', false, false, null, 'scheduled', __( 'Scheduled, waiting to be processed', 'babilim' ), '' );
			case 'running':
				return self::cell( 'none', false, false, null, 'translating', __( 'Translating…', 'babilim' ), '' );
			case 'failed':
				return self::cell( 'none', false, false, 'babilim_request', null, __( 'Translation failed', 'babilim' ), '', true );
			case 'quota_exceeded':
				return self::cell( 'none', false, false, null, 'quota_exceeded', __( 'Free quota used', 'babilim' ), '' );
			case 'license_required':
				return self::cell( 'none', false, false, null, 'license_required', __( 'Needs a paid license', 'babilim' ), '' );
			case 'unsupported':
				return self::cell( 'none', false, false, null, 'unsupported', __( 'Not supported', 'babilim' ), '' );
			default:
				return self::cell( 'unknown', false, false, null, 'unknown', __( 'Status unknown', 'babilim' ), '' );
		}
	}

	/**
	 * A cell whose Babilim build is installed on this site.
	 *
	 * @param array $entry           Locale entry.
	 * @param string $status         Its status.
	 * @param bool  $has_update      Whether the state lists an update.
	 * @param bool  $can_write       Whether files may be written.
	 * @param array $manifest_record The manifest record.
	 * @param string $build_id       The installed build id.
	 * @param string $site_version   The installed component version, empty when unknown.
	 * @return array
	 */
	private static function installed_cell( array $entry, string $status, bool $has_update, bool $can_write, array $manifest_record, string $build_id, string $site_version ): array {
		if ( 'babilim_installed' !== $status ) {
			// The manifest says it is installed; the service says
			// something else (or nothing). The local record wins.
			return self::cell( 'babilim', true, false, null, 'downloaded', _x( 'Installed', 'translation status', 'babilim' ), $build_id );
		}

		// An update is only pending while the local build is still the one
		// the service last saw; once the install has moved the manifest on,
		// stale state must not offer (or perform) a downgrade.
		$update_build   = (string) ( $entry['update_build_id'] ?? '' );
		$manifest_build = (string) ( $manifest_record['build_id'] ?? '' );
		$is_update      = $has_update && ( '' !== $update_build
			? $update_build !== $manifest_build
			: $manifest_build === (string) ( $entry['build_id'] ?? '' ) );
		if ( $is_update ) {
			if ( ! $can_write ) {
				return self::cell( 'babilim', true, true, null, 'network_capability', __( 'Update available', 'babilim' ), $build_id );
			}
			return self::cell( 'babilim', true, true, 'babilim_download', null, __( 'Update available', 'babilim' ), $build_id );
		}

		$newer = is_array( $entry['newer_version'] ?? null ) ? $entry['newer_version'] : null;
		if ( null !== $newer ) {
			return self::newer_cell( $entry, (string) ( $newer['status'] ?? '' ), $build_id, $site_version, $can_write );
		}

		// Between an update and the next sync the entry still describes the
		// previous build: its date does not belong to the installed one.
		$entry_build = (string) ( $entry['build_id'] ?? '' );
		$stale       = '' !== $entry_build && $manifest_build !== $entry_build;
		$text        = $stale ? _x( 'Installed', 'translation status', 'babilim' ) : self::built_text( $entry, true );
		return self::cell( 'babilim', true, false, null, 'downloaded', $text, $build_id, false, false, $stale ? '' : self::built_date( $entry ) );
	}

	/**
	 * A cell whose Babilim build was removed after a failed integrity
	 * check: not installed, but the same build can be downloaded again.
	 *
	 * @param bool   $can_write Whether files may be written.
	 * @param string $build_id  The build id.
	 * @return array
	 */
	private static function degraded_cell( bool $can_write, string $build_id ): array {
		$text = __( 'Removed after a failed integrity check', 'babilim' );
		if ( '' === $build_id ) {
			return self::cell( 'babilim', false, false, null, 'unknown', $text, '' );
		}
		if ( ! $can_write ) {
			return self::cell( 'babilim', false, false, null, 'network_capability', $text, $build_id );
		}
		return self::cell( 'babilim', false, false, 'babilim_download', null, $text, $build_id );
	}

	/**
	 * A cell for a Babilim build that exists but is not installed here.
	 *
	 * @param array  $entry     Locale entry.
	 * @param bool   $can_write Whether files may be written.
	 * @param string $build_id  The build id.
	 * @return array
	 */
	private static function downloadable_cell( array $entry, bool $can_write, string $build_id ): array {
		$text = self::built_text( $entry, false );
		$date = self::built_date( $entry );
		if ( '' === $build_id ) {
			return self::cell( 'babilim', false, false, null, 'unknown', $text, '', false, false, $date );
		}
		if ( ! $can_write ) {
			return self::cell( 'babilim', false, false, null, 'network_capability', $text, $build_id, false, false, $date );
		}
		return self::cell( 'babilim', false, false, 'babilim_download', null, $text, $build_id, false, false, $date );
	}

	/**
	 * An installed cell whose component has a newer version the service
	 * reported on.
	 *
	 * @param array  $entry        Locale entry.
	 * @param string $newer_status newer_version.status.
	 * @param string $build_id     The installed build id.
	 * @param string $site_version The installed component version, '' when unknown.
	 * @param bool   $can_write    Whether files may be written (an upload needs the same right).
	 * @return array
	 */
	private static function newer_cell( array $entry, string $newer_status, string $build_id, string $site_version, bool $can_write ): array {
		$version = (string) ( $entry['built_for_version'] ?? '' );
		$known   = '' !== $version;

		// One whole sentence per state (and one without the version for a
		// build that does not say which version it was made for), so a
		// translator can order the words freely.
		switch ( $newer_status ) {
			case 'queued':
				if ( ! empty( $entry['upload_pending'] ) ) {
					// Same entry point as an uninstalled cell: the service
					// asked for this version's source and the upload is not
					// finished, so the request button resumes (or cancels) it.
					if ( ! $can_write ) {
						return self::cell( 'babilim', true, false, null, 'network_capability', __( 'Upload in progress — continue or cancel', 'babilim' ), $build_id );
					}
					return self::cell( 'babilim', true, false, 'babilim_request', null, __( 'Upload in progress — continue or cancel', 'babilim' ), $build_id );
				}
				/* translators: %s: the component version a Babilim translation was built for. */
				$text = $known ? sprintf( __( 'Installed · built for %s, new version scheduled', 'babilim' ), $version ) : __( 'Installed · built for a different version, new version scheduled', 'babilim' );
				return self::cell( 'babilim', true, false, null, 'scheduled', $text, $build_id );
			case 'running':
				/* translators: %s: the component version a Babilim translation was built for. */
				$text = $known ? sprintf( __( 'Installed · built for %s, translating the new version…', 'babilim' ), $version ) : __( 'Installed · built for a different version, translating the new version…', 'babilim' );
				return self::cell( 'babilim', true, false, null, 'translating', $text, $build_id );
			case 'requestable':
				if ( $known && '' !== $site_version && $site_version !== $version ) {
					/* translators: 1: the component version a Babilim translation was built for, 2: the component version installed on this site. */
					$text = sprintf( __( 'Installed · built for %1$s, this site has %2$s', 'babilim' ), $version, $site_version );
					return self::cell( 'babilim', true, false, 'babilim_request', null, $text, $build_id, false, true );
				}
				/* translators: %s: the component version a Babilim translation was built for. */
				$text = $known ? sprintf( __( 'Installed · built for %s', 'babilim' ), $version ) : __( 'Installed · built for a different version', 'babilim' );
				return self::cell( 'babilim', true, false, 'babilim_request', null, $text, $build_id, false, true );
			case 'failed':
				/* translators: %s: the component version a Babilim translation was built for. */
				$text = $known ? sprintf( __( 'Installed · built for %s, new version failed', 'babilim' ), $version ) : __( 'Installed · built for a different version, new version failed', 'babilim' );
				return self::cell( 'babilim', true, false, 'babilim_request', null, $text, $build_id, true, true );
			case 'license_required':
				/* translators: %s: the component version a Babilim translation was built for. */
				$text = $known ? sprintf( __( 'Installed · built for %s, new version needs a paid license', 'babilim' ), $version ) : __( 'Installed · built for a different version, new version needs a paid license', 'babilim' );
				return self::cell( 'babilim', true, false, null, 'license_required', $text, $build_id );
			case 'quota_exceeded':
				/* translators: %s: the component version a Babilim translation was built for. */
				$text = $known ? sprintf( __( 'Installed · built for %s, free quota used', 'babilim' ), $version ) : __( 'Installed · built for a different version, free quota used', 'babilim' );
				return self::cell( 'babilim', true, false, null, 'quota_exceeded', $text, $build_id );
			default:
				/* translators: %s: the component version a Babilim translation was built for. */
				$text = $known ? sprintf( __( 'Installed · built for %s', 'babilim' ), $version ) : __( 'Installed · built for a different version', 'babilim' );
				return self::cell( 'babilim', true, false, null, 'unknown', $text, $build_id );
		}
	}

	/**
	 * "Installed · {date}" or "Available · {date}" from the entry's
	 * built_at, or the bare word when it has none.
	 *
	 * @param array $entry     Locale entry.
	 * @param bool  $installed Whether the build is installed here.
	 * @return string
	 */
	private static function built_text( array $entry, bool $installed ): string {
		$date = self::built_date( $entry );
		if ( '' === $date ) {
			return $installed ? _x( 'Installed', 'translation status', 'babilim' ) : _x( 'Available', 'translation status', 'babilim' );
		}
		if ( $installed ) {
			/* translators: %s: the date a translation installed on this site was built. */
			return sprintf( __( 'Installed · %s', 'babilim' ), $date );
		}
		/* translators: %s: the date an available translation was built. */
		return sprintf( __( 'Available · %s', 'babilim' ), $date );
	}

	/**
	 * The entry's built_at as a date in the site's own format, or '' when
	 * it has none.
	 *
	 * @param array $entry Locale entry.
	 * @return string
	 */
	private static function built_date( array $entry ): string {
		$built_at = (string) ( $entry['built_at'] ?? '' );
		$time     = '' === $built_at ? false : strtotime( $built_at );
		if ( false === $time ) {
			return '';
		}
		$format = (string) get_option( 'date_format', 'F j, Y' );
		return (string) wp_date( '' === $format ? 'F j, Y' : $format, $time );
	}

	/**
	 * Assembles the result array.
	 *
	 * @param string      $source         Source.
	 * @param bool        $installed      Installed.
	 * @param bool        $is_update      Update flag.
	 * @param string|null $action         Runnable action.
	 * @param string|null $blocked_reason Why nothing can run.
	 * @param string      $text           Source and state text.
	 * @param string      $title          Build id for the tooltip.
	 * @param bool        $retry          Whether a request action is a retry.
	 * @param bool        $newer          Whether a request action is for a newer version.
	 * @param string      $date           The date the text ends with, '' when it has none.
	 * @return array
	 */
	private static function cell( string $source, bool $installed, bool $is_update, ?string $action, ?string $blocked_reason, string $text, string $title, bool $retry = false, bool $newer = false, string $date = '' ): array {
		$action_label = '';
		$busy_label   = '';
		if ( 'babilim_download' === $action ) {
			$action_label = $is_update ? __( 'Update', 'babilim' ) : __( 'Download', 'babilim' );
			$busy_label   = $is_update ? __( 'Updating…', 'babilim' ) : __( 'Downloading…', 'babilim' );
		} elseif ( 'babilim_request' === $action ) {
			if ( $retry ) {
				$action_label = __( 'Retry request', 'babilim' );
			} else {
				$action_label = $newer ? __( 'Request newer translation', 'babilim' ) : __( 'Request translation', 'babilim' );
			}
			$busy_label = __( 'Requesting…', 'babilim' );
		}

		return array(
			'source'         => $source,
			'installed'      => $installed,
			'is_update'      => $is_update,
			'action'         => $action,
			'action_label'   => $action_label,
			'busy_label'     => $busy_label,
			'blocked_reason' => $blocked_reason,
			'text'           => $text,
			'title'          => $title,
			'date'           => $date,
		);
	}
}
