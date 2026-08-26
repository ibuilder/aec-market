<?php
/**
 * Thin Anthropic Messages API client.
 *
 * One place for model choice, auth, and error handling. Services call
 * Anthropic::ask() and never touch HTTP directly.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Anthropic API wrapper.
 */
class Anthropic {

	const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

	/**
	 * Single-shot completion.
	 *
	 * @param string $system     System prompt.
	 * @param string $user       User content.
	 * @param int    $max_tokens Max output tokens.
	 * @param string $model      Optional model override; falls back to the global setting.
	 * @return string Model text.
	 *
	 * @throws \RuntimeException When the key is missing or the request fails.
	 */
	public static function ask( $system, $user, $max_tokens = 1500, $model = '' ) {
		$settings = Settings::get();
		$key      = isset( $settings['anthropic_api_key'] ) ? trim( $settings['anthropic_api_key'] ) : '';
		$model    = '' !== (string) $model
			? (string) $model
			: ( isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-sonnet-5' );

		if ( '' === $key ) {
			throw new \RuntimeException(
				esc_html__( 'No Anthropic API key configured. Add one under AEC Forge Tools → Settings.', 'aec-market' )
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => (int) $max_tokens,
						// Each tool's system prompt is static, so cache it: repeat runs
						// bill the cached prefix at ~0.1x instead of full input price.
						// Prompt caching is GA — no beta header required.
						'system'     => array(
							array(
								'type'          => 'text',
								'text'          => $system,
								'cache_control' => array( 'type' => 'ephemeral' ),
							),
						),
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			throw new \RuntimeException( esc_html( sprintf( 'Anthropic API error: %s', $message ) ) );
		}

		$text = '';
		if ( isset( $body['content'] ) && is_array( $body['content'] ) ) {
			foreach ( $body['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		return trim( $text );
	}
}
