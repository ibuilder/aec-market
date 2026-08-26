<?php
/**
 * AEC Forge Tools admin — settings + usage, nested under the AEC Market menu.
 *
 * @package AEC_Forge_Tools\Admin
 */

namespace AEC_Forge_Tools\Admin;

use AEC_Forge_Tools\Settings;
use AEC_Forge_Tools\Tools;
use AEC_Forge_Tools\Credits;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the wp-admin experience for the AI tools.
 */
class Admin {

	/**
	 * Parent menu slug (AEC Market's top-level menu).
	 */
	const PARENT = 'wpaec-vendors';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_init', array( Settings::class, 'register' ) );
	}

	/**
	 * Add the submenus under AEC Market.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			self::PARENT,
			__( 'Forge Tools', 'aec-market' ),
			__( 'Forge Tools', 'aec-market' ),
			'manage_options',
			'aec-forge-tools',
			array( $this, 'render_settings' )
		);
		add_submenu_page(
			self::PARENT,
			__( 'Forge Tools Usage', 'aec-market' ),
			__( 'Forge Tools Usage', 'aec-market' ),
			'manage_options',
			'aec-forge-tools-usage',
			array( $this, 'render_usage' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = Settings::get();
		$services = Tools::instance()->services->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AEC Forge Tools', 'aec-market' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Pay-per-use AI tools for the tedious GC paperwork — sold as credits through WooCommerce.', 'aec-market' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( Settings::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'AI', 'aec-market' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mp-key"><?php esc_html_e( 'Anthropic API key', 'aec-market' ); ?></label></th>
						<td>
							<input name="aec_tools_settings[anthropic_api_key]" id="mp-key" type="password" class="regular-text"
								value="<?php echo esc_attr( $s['anthropic_api_key'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Stored in your database; required for the tools to run. Get one at console.anthropic.com.', 'aec-market' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-model"><?php esc_html_e( 'Default model', 'aec-market' ); ?></label></th>
						<td><input name="aec_tools_settings[anthropic_model]" id="mp-model" type="text" class="regular-text"
							value="<?php echo esc_attr( $s['anthropic_model'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-trial"><?php esc_html_e( 'Free trial credits', 'aec-market' ); ?></label></th>
						<td><input name="aec_tools_settings[free_trial_credits]" id="mp-trial" type="number" min="0" class="small-text"
							value="<?php echo esc_attr( $s['free_trial_credits'] ); ?>" />
							<span class="description"><?php esc_html_e( 'Granted once to each new account.', 'aec-market' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-rlmin"><?php esc_html_e( 'Rate limit', 'aec-market' ); ?></label></th>
						<td>
							<input name="aec_tools_settings[rate_per_min]" id="mp-rlmin" type="number" min="0" class="small-text"
								value="<?php echo esc_attr( $s['rate_per_min'] ); ?>" />
							<span class="description"><?php esc_html_e( 'runs per minute', 'aec-market' ); ?></span>
							&nbsp;
							<input name="aec_tools_settings[rate_per_day]" type="number" min="0" class="small-text"
								value="<?php echo esc_attr( $s['rate_per_day'] ); ?>" />
							<span class="description"><?php esc_html_e( 'runs per day, per user. 0 disables a limit. Protects against runaway loops and API-cost spikes.', 'aec-market' ); ?></span>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Per-tool credit cost & model', 'aec-market' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Right-size the model per tool to control cost: cheaper tiers handle narrative-only tools; keep richer tools on a stronger model.', 'aec-market' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$mp_models = Settings::allowed_models();
					foreach ( $services as $service ) :
						$mp_key      = $service->key();
						$mp_selected = isset( $s['model_overrides'][ $mp_key ] ) ? $s['model_overrides'][ $mp_key ] : '';
						$mp_rec      = $service->default_model();
						$mp_rec_lbl  = ( '' !== $mp_rec && isset( $mp_models[ $mp_rec ] ) ) ? $mp_models[ $mp_rec ] : __( 'global model', 'aec-market' );
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $service->name() ); ?></th>
							<td>
								<input type="number" min="0" class="small-text"
									name="aec_tools_settings[credit_costs][<?php echo esc_attr( $mp_key ); ?>]"
									value="<?php echo esc_attr( isset( $s['credit_costs'][ $mp_key ] ) ? $s['credit_costs'][ $mp_key ] : '' ); ?>"
									placeholder="<?php echo esc_attr( $service->default_credits() ); ?>" />
								<span class="description"><?php echo esc_html( sprintf( /* translators: %d default credits */ __( 'default %d credit(s)', 'aec-market' ), $service->default_credits() ) ); ?></span>
								&nbsp;
								<select name="aec_tools_settings[model_overrides][<?php echo esc_attr( $mp_key ); ?>]">
									<?php foreach ( $mp_models as $mp_id => $mp_label ) : ?>
										<option value="<?php echo esc_attr( $mp_id ); ?>" <?php selected( $mp_selected, $mp_id ); ?>>
											<?php echo esc_html( $mp_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<span class="description"><?php echo esc_html( sprintf( /* translators: %s recommended model label */ __( 'recommended: %s', 'aec-market' ), $mp_rec_lbl ) ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Credit packs', 'aec-market' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Sold via WooCommerce. Saving re-syncs the matching products.', 'aec-market' ); ?></p>
				<table class="widefat striped" style="max-width:720px">
					<thead><tr>
						<th><?php esc_html_e( 'ID', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Name', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Credits', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Price (USD)', 'aec-market' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					$packs = $s['packs'];
					// Provide a spare blank row for adding one more.
					$packs[] = array( 'id' => '', 'name' => '', 'credits' => '', 'price' => '' );
					foreach ( $packs as $i => $pack ) :
						?>
						<tr>
							<td><input type="text" name="aec_tools_settings[packs][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $pack['id'] ); ?>" /></td>
							<td><input type="text" name="aec_tools_settings[packs][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $pack['name'] ); ?>" /></td>
							<td><input type="number" min="1" name="aec_tools_settings[packs][<?php echo (int) $i; ?>][credits]" value="<?php echo esc_attr( $pack['credits'] ); ?>" /></td>
							<td><input type="number" min="0" step="0.01" name="aec_tools_settings[packs][<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( $pack['price'] ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2 class="title"><?php esc_html_e( 'Placement', 'aec-market' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mp-page"><?php esc_html_e( 'Tools page', 'aec-market' ); ?></label></th>
						<td>
							<?php
							// wp_dropdown_pages() is captured (echo=0) and escaped via wp_kses() below.
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
							$mp_dropdown = wp_dropdown_pages(
								array(
									'name'              => 'aec_tools_settings[tools_page_id]',
									'id'                => 'mp-page',
									'selected'          => $s['tools_page_id'],
									'show_option_none'  => __( '— none —', 'aec-market' ),
									'option_none_value' => 0,
									'echo'              => 0,
								)
							);
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							echo wp_kses(
								$mp_dropdown,
								array(
									'select' => array( 'name' => true, 'id' => true, 'class' => true ),
									'option' => array( 'value' => true, 'selected' => true, 'class' => true ),
								)
							);
							?>
							<p class="description"><?php echo wp_kses_post( __( 'The page containing the <code>[aec_forge_tools]</code> shortcode.', 'aec-market' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-disc"><?php esc_html_e( 'Result disclaimer', 'aec-market' ); ?></label></th>
						<td><textarea name="aec_tools_settings[disclaimer]" id="mp-disc" class="large-text" rows="2"><?php echo esc_textarea( $s['disclaimer'] ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Installed tools', 'aec-market' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr>
					<th><?php esc_html_e( 'Tool', 'aec-market' ); ?></th>
					<th><?php esc_html_e( 'Shortcode key', 'aec-market' ); ?></th>
					<th><?php esc_html_e( 'Credits', 'aec-market' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $services as $service ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $service->name() ); ?></strong><br><span class="description"><?php echo esc_html( $service->blurb() ); ?></span></td>
							<td><code><?php echo esc_html( $service->key() ); ?></code></td>
							<td><?php echo esc_html( $service->credits() ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the usage page (recent ledger across all users).
	 *
	 * @return void
	 */
	public function render_usage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = Credits::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is internal and cannot be parameterized.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", 100 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AEC Forge Tools Usage', 'aec-market' ); ?></h1>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'aec-market' ); ?></th>
					<th><?php esc_html_e( 'User', 'aec-market' ); ?></th>
					<th><?php esc_html_e( 'Delta', 'aec-market' ); ?></th>
					<th><?php esc_html_e( 'Balance', 'aec-market' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'aec-market' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No activity yet.', 'aec-market' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $u = get_userdata( $row->user_id ); ?>
							<tr>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><?php echo esc_html( $u ? $u->user_email : '#' . $row->user_id ); ?></td>
								<td><?php echo esc_html( ( $row->delta > 0 ? '+' : '' ) . $row->delta ); ?></td>
								<td><?php echo esc_html( $row->balance_after ); ?></td>
								<td><?php echo esc_html( $row->reason ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
