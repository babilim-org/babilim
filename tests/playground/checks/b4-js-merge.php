<?php
/**
 * `load_script_translations` (the block-editor / script-translation
 * JSON merge filter) is called exactly as WordPress core calls it --
 * with the raw JSON string core already resolved from SOME file (a real
 * wp.org-shaped language pack JSON, in this fixture) -- and merges our
 * own babilim/ catalog's messages on top, winning on a colliding msgid,
 * while leaving core's own non-conflicting messages and its own header
 * entry (`""`) intact.
 */

declare( strict_types=1 );

babilim_pg_run(
	'b4',
	'load_script_translations merges the babilim/ JSON catalog onto a real Jed payload, winning on conflict, preserving core\'s header and non-conflicting entries',
	static function () {
		$domain = 'b4-plugin';
		$locale = 'ja';

		$core_json = wp_json_encode(
			array(
				'domain'      => $domain,
				'locale_data' => array(
					$domain => array(
						''                => array( 'domain' => $domain, 'lang' => $locale ),
						'Core Own String' => array( 'Core Own Translated' ),
						'Shared JS Key'   => array( 'From Core JSON' ),
					),
				),
			)
		);

		$babilim_json_path = Babilim_Paths::file( 'plugins', $domain, $locale, 'json' );
		wp_mkdir_p( dirname( $babilim_json_path ) );
		file_put_contents(
			$babilim_json_path,
			wp_json_encode(
				array(
					'domain'      => $domain,
					'locale_data' => array(
						'messages' => array(
							''              => array( 'domain' => 'messages', 'lang' => $locale ),
							'Shared JS Key' => array( 'From Babilim JSON' ),
							'Babilim Only'  => array( 'Babilim Only Translated' ),
						),
					),
				)
			)
		);

		$merged_raw = babilim_pg_with_locale(
			$locale,
			static function () use ( $core_json, $domain ) {
				return Babilim_Loader::load_script_translations( $core_json, 'irrelevant-resolved-path.json', 'irrelevant-handle', $domain );
			}
		);

		babilim_pg_assert( is_string( $merged_raw ), 'load_script_translations() did not return a string for a string input' );
		$decoded  = json_decode( (string) $merged_raw, true );
		$messages = $decoded['locale_data'][ $domain ] ?? null;
		babilim_pg_assert( is_array( $messages ), 'merged JSON has no locale_data.' . $domain );

		babilim_pg_assert( ( $messages['Core Own String'][0] ?? null ) === 'Core Own Translated', 'core\'s own non-conflicting message was lost' );
		babilim_pg_assert( ( $messages['Babilim Only'][0] ?? null ) === 'Babilim Only Translated', 'babilim\'s own non-conflicting message is missing' );
		babilim_pg_assert( ( $messages['Shared JS Key'][0] ?? null ) === 'From Babilim JSON', "babilim's translation should win the conflict, got " . var_export( $messages['Shared JS Key'][0] ?? null, true ) );
		babilim_pg_assert( isset( $messages[''] ) && ( $messages['']['domain'] ?? null ) === $domain, "core's own header entry was overwritten instead of kept" );
	}
);
