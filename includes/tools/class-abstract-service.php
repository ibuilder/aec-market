<?php
/**
 * Base class every AEC Forge Tools service extends.
 *
 * A service is one paid tool. To add a new one, drop a class in
 * includes/services/ and register it on the `aec_tools_register_services`
 * filter — the dashboard, pricing, and run pipeline pick it up automatically.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract service definition.
 */
abstract class Abstract_Service {

	/**
	 * Stable machine key (used in URLs, settings, ledger refs).
	 *
	 * @return string
	 */
	abstract public function key();

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * One-line description.
	 *
	 * @return string
	 */
	abstract public function blurb();

	/**
	 * Default credit cost (admins can override in Settings).
	 *
	 * @return int
	 */
	abstract public function default_credits();

	/**
	 * Input style: 'form' or 'upload'.
	 *
	 * @return string
	 */
	public function input_type() {
		return 'form';
	}

	/**
	 * Field definitions for the generic renderer.
	 *
	 * Each field: array{ name, label, type(text|textarea|file), placeholder?,
	 * required?, sample?, full? }.
	 *
	 * @return array
	 */
	abstract public function fields();

	/**
	 * Sample data filename in assets/samples/ for the "load sample" button,
	 * or '' for form-only tools.
	 *
	 * @return string
	 */
	public function sample_file() {
		return '';
	}

	/**
	 * Run the service.
	 *
	 * @param array $form Sanitized inputs, incl. 'file_text'/'file_bytes'/'_filename' for uploads.
	 * @return Tool_Result
	 */
	abstract public function run( array $form );

	/**
	 * Recommended model for this service when the admin hasn't overridden it.
	 *
	 * Return '' to use the global model setting. Simple narrative tools override
	 * this to a cheaper tier (e.g. Haiku) to cut per-run cost.
	 *
	 * @return string
	 */
	public function default_model() {
		return '';
	}

	/**
	 * Effective model, honoring (in order) the admin per-service override, this
	 * service's recommended default, then the global model setting.
	 *
	 * @return string
	 */
	public function model() {
		$settings  = Settings::get();
		$overrides = isset( $settings['model_overrides'] ) && is_array( $settings['model_overrides'] ) ? $settings['model_overrides'] : array();
		if ( isset( $overrides[ $this->key() ] ) && '' !== $overrides[ $this->key() ] ) {
			return (string) $overrides[ $this->key() ];
		}
		$default = $this->default_model();
		if ( '' !== $default ) {
			return $default;
		}
		return isset( $settings['anthropic_model'] ) ? (string) $settings['anthropic_model'] : 'claude-sonnet-5';
	}

	/**
	 * Effective credit cost, honoring the admin override.
	 *
	 * @return int
	 */
	public function credits() {
		$settings = Settings::get();
		$overrides = isset( $settings['credit_costs'] ) && is_array( $settings['credit_costs'] ) ? $settings['credit_costs'] : array();
		if ( isset( $overrides[ $this->key() ] ) && '' !== $overrides[ $this->key() ] ) {
			return max( 0, (int) $overrides[ $this->key() ] );
		}
		return (int) $this->default_credits();
	}

	/**
	 * Short, confidentiality-safe label for the usage log.
	 *
	 * @param array $form Inputs.
	 * @return string
	 */
	public function input_summary( array $form ) {
		$label = isset( $form['_filename'] ) && '' !== $form['_filename'] ? $form['_filename'] : $this->name();
		return mb_substr( (string) $label, 0, 120 );
	}
}
