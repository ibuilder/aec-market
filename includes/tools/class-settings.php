<?php
/**
 * Settings model — reads/writes the single options array and sanitizes it.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class Settings {

	const OPTION = 'aec_tools_settings';
	const GROUP  = 'aec_tools_settings_group';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'anthropic_api_key'   => '',
			'anthropic_model'     => 'claude-sonnet-5',
			'free_trial_credits'  => 3,
			'credit_costs'        => array(),
			'model_overrides'     => array(),
			'packs'               => array(
				array( 'id' => 'starter', 'name' => 'Starter', 'credits' => 10, 'price' => 49 ),
				array( 'id' => 'crew', 'name' => 'Crew', 'credits' => 50, 'price' => 199 ),
				array( 'id' => 'firm', 'name' => 'Firm', 'credits' => 200, 'price' => 699 ),
			),
			'product_map'         => array(),
			'tools_page_id'       => 0,
			'disclaimer'          => 'Drafting aid — review before sending. Not contract or legal advice.',
		);
	}

	/**
	 * Selectable Claude models (id => label). Empty string = use the global model.
	 *
	 * @return array<string,string>
	 */
	public static function allowed_models() {
		return array(
			''                  => __( 'Global default', 'aec-market' ),
			'claude-haiku-4-5'  => 'Claude Haiku 4.5 — cheapest',
			'claude-sonnet-5'   => 'Claude Sonnet 5 — balanced',
			'claude-opus-4-8'   => 'Claude Opus 4.8 — most capable',
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * A single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function value( $key, $default = null ) {
		$all = self::get();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist defaults on activation (without overwriting existing).
	 *
	 * @return void
	 */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * Register the setting with the Settings API.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize the settings array on save.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$out      = self::get();
		$input    = is_array( $input ) ? $input : array();

		if ( isset( $input['anthropic_api_key'] ) ) {
			$out['anthropic_api_key'] = trim( sanitize_text_field( $input['anthropic_api_key'] ) );
		}
		if ( isset( $input['anthropic_model'] ) ) {
			$out['anthropic_model'] = sanitize_text_field( $input['anthropic_model'] );
		}
		if ( isset( $input['free_trial_credits'] ) ) {
			$out['free_trial_credits'] = max( 0, absint( $input['free_trial_credits'] ) );
		}
		if ( isset( $input['tools_page_id'] ) ) {
			$out['tools_page_id'] = absint( $input['tools_page_id'] );
		}
		if ( isset( $input['disclaimer'] ) ) {
			$out['disclaimer'] = sanitize_textarea_field( $input['disclaimer'] );
		}

		// Per-service credit cost overrides.
		if ( isset( $input['credit_costs'] ) && is_array( $input['credit_costs'] ) ) {
			$costs = array();
			foreach ( $input['credit_costs'] as $key => $val ) {
				$key = sanitize_key( $key );
				if ( '' === $val && 0 !== $val ) {
					continue;
				}
				$costs[ $key ] = max( 0, absint( $val ) );
			}
			$out['credit_costs'] = $costs;
		}

		// Per-service model overrides (validated against the allowed list).
		if ( isset( $input['model_overrides'] ) && is_array( $input['model_overrides'] ) ) {
			$allowed   = array_keys( self::allowed_models() );
			$overrides = array();
			foreach ( $input['model_overrides'] as $key => $val ) {
				$key = sanitize_key( $key );
				$val = sanitize_text_field( $val );
				if ( '' === $key || ! in_array( $val, $allowed, true ) || '' === $val ) {
					continue; // '' means "use global" — don't store it.
				}
				$overrides[ $key ] = $val;
			}
			$out['model_overrides'] = $overrides;
		}

		// Credit packs.
		if ( isset( $input['packs'] ) && is_array( $input['packs'] ) ) {
			$packs = array();
			foreach ( $input['packs'] as $pack ) {
				if ( empty( $pack['name'] ) && empty( $pack['credits'] ) ) {
					continue;
				}
				$id = ! empty( $pack['id'] ) ? sanitize_key( $pack['id'] ) : sanitize_key( $pack['name'] );
				if ( '' === $id ) {
					continue;
				}
				$packs[] = array(
					'id'      => $id,
					'name'    => sanitize_text_field( $pack['name'] ),
					'credits' => max( 1, absint( $pack['credits'] ) ),
					'price'   => max( 0, (float) $pack['price'] ),
				);
			}
			if ( $packs ) {
				$out['packs'] = $packs;
				// Packs changed — resync WooCommerce products.
				update_option( 'aec_tools_needs_product_sync', 1, false );
			}
		}

		return $out;
	}
}
