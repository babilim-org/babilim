<?php
/**
 * Sends a bounded batch of opted-in editor changes after sync. Saved
 * overrides are the queue, so editing stays local and needs no extra table.
 *
 * @package Babilim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Babilim_Edit_Feedback {
	public const OPTION_SHARE = 'babilim_share_edits';
	public const OPTION_SINCE = 'babilim_share_edits_since';
	public const OPTION_WAIT  = 'babilim_edit_feedback_wait';
	public const BATCH        = 10;
	public const NOTE         = 'Changed in the translation editor.';

	private Babilim_Overrides $overrides;
	/** @var callable(array): (array|WP_Error) */
	private $send;
	/** @var callable(): bool */
	private $granted;
	/** @var callable(string, mixed): mixed */
	private $get_option;
	/** @var callable(string, int): bool */
	private $update_option;
	/** @var callable(): int Unix epoch seconds. */
	private $now;

	public function __construct( Babilim_Overrides $overrides, callable $send, callable $granted, callable $get_option, callable $update_option, callable $now ) {
		$this->overrides     = $overrides;
		$this->send          = $send;
		$this->granted       = $granted;
		$this->get_option    = $get_option;
		$this->update_option = $update_option;
		$this->now           = $now;
	}

	/** @return array{sent:int,left:int} */
	public function flush(): array {
		$out = array( 'sent' => 0, 'left' => 0 );
		if ( ! ( $this->granted )() || '1' !== ( $this->get_option )( self::OPTION_SHARE, '0' ) ) {
			return $out;
		}
		$since = ( $this->get_option )( self::OPTION_SINCE, '' );
		if ( ! is_string( $since ) || '' === $since
			|| ( $this->now )() < (int) ( $this->get_option )( self::OPTION_WAIT, 0 )
			|| ! $this->overrides->ready() ) {
			return $out;
		}

		$attempts = 0;
		$stopped  = false;
		foreach ( $this->overrides->feedback_scopes( $since ) as $scope ) {
			foreach ( $this->candidates( $scope, $since ) as $row ) {
				if ( $stopped || $attempts >= self::BATCH ) {
					++$out['left'];
					continue;
				}
				$payload = self::payload( $scope, $row );
				if ( null !== $payload ) {
					++$attempts;
					$result = ( $this->send )( $payload );
					if ( ! is_wp_error( $result ) ) {
						++$out['sent'];
					} elseif ( 'babilim_unprocessable' !== $result->get_error_code() ) {
						if ( 'babilim_rate_limited' === $result->get_error_code() ) {
							$now = ( $this->now )();
							( $this->update_option )( self::OPTION_WAIT, ( intdiv( $now, DAY_IN_SECONDS ) + 1 ) * DAY_IN_SECONDS );
						}
						$stopped = true;
						++$out['left'];
						continue;
					}
				}
				if ( ! $this->overrides->mark_feedback( $scope, $row['ref'], $row['base_build'] ) ) {
					$stopped = true;
					++$out['left'];
				}
			}
		}
		return $out;
	}

	/** First changed eligible form per string and build, in queue order. */
	private function candidates( array $scope, string $since ): array {
		$selected = array();
		$handled  = array();
		foreach ( $this->overrides->rows( $scope ) as $row ) {
			if ( 'editor' !== $row['origin'] || 'babilim' !== $row['base_source'] || '' === $row['base_build'] ) {
				continue;
			}
			$key = $row['ref'] . ':' . $row['base_build'];
			if ( $row['feedback_build'] === $row['base_build'] ) {
				$handled[ $key ] = true;
			}
			if ( Babilim_Overrides::STATUS_ACTIVE === $row['status'] && $row['modified_gmt'] >= $since
				&& $row['msgstr'] !== $row['base_msgstr']
				&& ( ! isset( $selected[ $key ] ) || $row['plural_index'] < $selected[ $key ]['plural_index'] ) ) {
				$selected[ $key ] = $row;
			}
		}
		return array_diff_key( $selected, $handled );
	}

	private static function payload( array $scope, array $row ): ?array {
		$payload = array(
			'kind'           => $scope['kind'],
			'slug'           => $scope['slug'],
			'locale'         => $scope['locale'],
			'build_id'       => $row['base_build'],
			'category'       => 'other',
			'msgid'          => $row['msgid'],
			'current_msgstr' => $row['base_msgstr'],
			'suggested'      => $row['msgstr'],
			'note'           => self::NOTE,
		);
		if ( '' !== $row['msgctxt'] ) {
			$payload['msgctxt'] = $row['msgctxt'];
		}
		foreach ( $payload as $text ) {
			if ( ! Babilim_Contract_Limits::fits( $text, Babilim_Contract_Limits::FEEDBACK_TEXT ) ) {
				return null;
			}
		}
		if ( '' !== $row['msgid_plural'] ) {
			$payload['plural_index'] = $row['plural_index'];
		}
		return $payload;
	}
}
