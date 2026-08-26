<?php
/**
 * AJAX endpoints: run a service and download its file deliverable.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end AJAX controller.
 */
class Ajax {

	const MAX_UPLOAD  = 10485760; // 10 MB.
	const FILE_EXPIRY = HOUR_IN_SECONDS;

	/**
	 * Register handlers (logged-in users only — credits are per account).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_aec_tools_run', array( $this, 'run' ) );
		add_action( 'wp_ajax_aec_tools_download', array( $this, 'download' ) );
	}

	/**
	 * Run a service. Charges credits only on success.
	 *
	 * @return void
	 */
	public function run() {
		check_ajax_referer( 'aec-market', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please sign in to run a tool.', 'aec-market' ) ), 403 );
		}

		$key      = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '';
		$registry = Tools::instance()->services;
		$service  = $registry->get( $key );
		if ( ! $service ) {
			wp_send_json_error( array( 'message' => __( 'Unknown tool.', 'aec-market' ) ), 404 );
		}

		// Rate limiting — protects against runaway loops and API-cost spikes.
		$limited = $this->rate_limit_hit( $user_id );
		if ( '' !== $limited ) {
			wp_send_json_error( array( 'message' => $limited ), 429 );
		}

		$cost = $service->credits();
		if ( ! Credits::has_credits( $user_id, $cost ) ) {
			wp_send_json_error(
				array(
					'message'  => sprintf(
						/* translators: 1: cost, 2: balance */
						__( 'You need %1$d credit(s) to run this — you have %2$d. Grab a pack to keep going.', 'aec-market' ),
						$cost,
						Credits::get_balance( $user_id )
					),
					'need_credits' => true,
				),
				402
			);
		}

		try {
			$form   = $this->collect_form( $service );
			$result = $service->run( $form );
		} catch ( \Throwable $e ) {
			// Not billed on failure.
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error detail */
						__( 'The tool hit an error and you were not charged. Details: %s', 'aec-market' ),
						$e->getMessage()
					),
				),
				200
			);
		}

		// Success — charge exactly the tool's cost. The debit is atomic; if it
		// fails (the balance was drained by a concurrent run between the pre-check
		// and here) do NOT return the deliverable.
		$summary = $service->input_summary( $form );
		if ( ! Credits::spend( $user_id, $cost, sprintf( 'run %s (%s)', $key, $summary ), 'run-' . $key ) ) {
			wp_send_json_error(
				array(
					'message'      => __( 'You ran out of credits before this run could be charged — you were not charged. Grab a pack and try again.', 'aec-market' ),
					'need_credits' => true,
				),
				402
			);
		}

		$response = array(
			'text'    => $result->text,
			'credits' => Credits::get_balance( $user_id ),
		);

		if ( $result->has_file() ) {
			$token = wp_generate_password( 24, false );
			set_transient(
				'aec_tools_file_' . $token,
				array(
					'bytes' => base64_encode( $result->file_bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary transport, not obfuscation.
					'name'  => $result->file_name,
					'mime'  => $result->file_mime,
					'user'  => $user_id,
				),
				self::FILE_EXPIRY
			);
			$response['download_url'] = add_query_arg(
				array(
					'action' => 'aec_tools_download',
					'token'  => $token,
					'nonce'  => wp_create_nonce( 'aec_tools_dl_' . $token ),
				),
				admin_url( 'admin-ajax.php' )
			);
			$response['download_name'] = $result->file_name;
		}

		wp_send_json_success( $response );
	}

	/**
	 * Stream a generated file to its owner.
	 *
	 * @return void
	 */
	public function download() {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( '' === $token || ! wp_verify_nonce( $nonce, 'aec_tools_dl_' . $token ) ) {
			wp_die( esc_html__( 'Invalid or expired download link.', 'aec-market' ), 403 );
		}

		$stored = get_transient( 'aec_tools_file_' . $token );
		if ( ! is_array( $stored ) || (int) $stored['user'] !== get_current_user_id() ) {
			wp_die( esc_html__( 'This file is not available.', 'aec-market' ), 403 );
		}

		$bytes = base64_decode( $stored['bytes'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encode above.
		nocache_headers();
		header( 'Content-Type: ' . $stored['mime'] );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $stored['name'] ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file body.
		exit;
	}

	/**
	 * Enforce per-user rate limits (abuse / API-cost protection).
	 *
	 * @param int $user_id User ID.
	 * @return string Error message if over a limit, or '' to allow the run.
	 */
	private function rate_limit_hit( $user_id ) {
		$per_min = (int) Settings::value( 'rate_per_min', 8 );
		$per_day = (int) Settings::value( 'rate_per_day', 100 );

		if ( $per_min > 0 ) {
			$mkey = 'aec_tools_rl_m_' . (int) $user_id;
			$m    = (int) get_transient( $mkey );
			if ( $m >= $per_min ) {
				return __( 'You are running tools very quickly — please wait a minute and try again.', 'aec-market' );
			}
			set_transient( $mkey, $m + 1, MINUTE_IN_SECONDS );
		}

		if ( $per_day > 0 ) {
			$dkey = 'aec_tools_rl_d_' . (int) $user_id;
			$d    = (int) get_transient( $dkey );
			if ( $d >= $per_day ) {
				return __( 'You have reached the current run limit. It resets within 24 hours — contact us if you need a higher limit.', 'aec-market' );
			}
			set_transient( $dkey, $d + 1, DAY_IN_SECONDS );
		}

		return '';
	}

	/**
	 * Assemble a sanitized form array from POST + uploaded file.
	 *
	 * @param Abstract_Service $service Service.
	 * @return array
	 *
	 * @throws \RuntimeException On bad upload.
	 */
	private function collect_form( $service ) {
		$form = array();
		foreach ( $service->fields() as $field ) {
			$name = $field['name'];
			if ( 'file' === $field['type'] ) {
				continue;
			}
			// Nonce is verified in run() before this method is called.
			if ( isset( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( 'textarea' === $field['type'] ) {
					$form[ $name ] = sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				} else {
					$form[ $name ] = sanitize_text_field( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				}
			}
		}

		// Nonce verified in run(); file bytes are validated by type/size below.
		if ( 'upload' === $service->input_type() && isset( $_FILES['file'] ) && ! empty( $_FILES['file']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
			if ( ! empty( $file['error'] ) ) {
				throw new \RuntimeException( esc_html__( 'The file upload failed. Try again or paste the data instead.', 'aec-market' ) );
			}
			if ( (int) $file['size'] > self::MAX_UPLOAD ) {
				throw new \RuntimeException( esc_html__( 'That file is larger than the 10 MB limit. Trim the export and retry.', 'aec-market' ) );
			}
			$tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
			if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				throw new \RuntimeException( esc_html__( 'Upload could not be read.', 'aec-market' ) );
			}
			$name  = sanitize_file_name( $file['name'] );
			$check = wp_check_filetype( $name, array( 'csv' => 'text/csv', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12' ) );
			if ( empty( $check['ext'] ) ) {
				throw new \RuntimeException( esc_html__( 'Please upload a .csv or .xlsx file.', 'aec-market' ) );
			}
			$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			$form['file_bytes'] = (string) $bytes;
			$form['_filename']  = $name;
			if ( 'csv' === $check['ext'] ) {
				$form['file_text'] = (string) $bytes;
			}
		}

		return $form;
	}
}
