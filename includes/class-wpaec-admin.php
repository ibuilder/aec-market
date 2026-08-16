<?php
/**
 * WP-admin screens: vendors, commissions, licenses, settings.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the marketplace admin menu and its pages.
 */
class AEC_Market_Admin {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_vendor_actions' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_commission_actions' ) );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'AEC Market', 'aec-market' ),
			__( 'AEC Market', 'aec-market' ),
			'wpaec_manage_marketplace',
			'wpaec-vendors',
			array( __CLASS__, 'render_vendors_page' ),
			'dashicons-store',
			56
		);

		add_submenu_page( 'wpaec-vendors', __( 'Vendors', 'aec-market' ), __( 'Vendors', 'aec-market' ), 'wpaec_manage_marketplace', 'wpaec-vendors', array( __CLASS__, 'render_vendors_page' ) );
		add_submenu_page( 'wpaec-vendors', __( 'Commissions', 'aec-market' ), __( 'Commissions', 'aec-market' ), 'wpaec_manage_marketplace', 'wpaec-commissions', array( __CLASS__, 'render_commissions_page' ) );
		add_submenu_page( 'wpaec-vendors', __( 'Licenses', 'aec-market' ), __( 'Licenses', 'aec-market' ), 'wpaec_manage_marketplace', 'wpaec-licenses', array( __CLASS__, 'render_licenses_page' ) );
		add_submenu_page( 'wpaec-vendors', __( 'Settings', 'aec-market' ), __( 'Settings', 'aec-market' ), 'wpaec_manage_marketplace', 'wpaec-settings', array( __CLASS__, 'render_settings_page' ) );
	}

	/**
	 * Register plugin settings via the Settings API.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'wpaecmarket_settings_group',
			'wpaecmarket_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		add_settings_section( 'wpaec_main', __( 'Marketplace Settings', 'aec-market' ), '__return_false', 'wpaec-settings' );

		$fields = array(
			'commission_rate'          => __( 'Platform commission (%)', 'aec-market' ),
			'vendor_approval'          => __( 'Vendor approval', 'aec-market' ),
			'product_status'           => __( 'New product status', 'aec-market' ),
			'license_activation_limit' => __( 'Default activation limit', 'aec-market' ),
			'allowed_upload_types'     => __( 'Allowed upload file types', 'aec-market' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field( $key, $label, array( __CLASS__, 'render_field' ), 'wpaec-settings', 'wpaec_main', array( 'key' => $key ) );
		}
	}

	/**
	 * Sanitize the settings array, preserving keys not managed by the form.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$current = get_option( 'wpaecmarket_settings', array() );
		$input   = is_array( $input ) ? $input : array();

		$current['commission_rate']          = isset( $input['commission_rate'] ) ? max( 0, min( 100, (float) $input['commission_rate'] ) ) : 10;
		$current['vendor_approval']          = isset( $input['vendor_approval'] ) && 'auto' === $input['vendor_approval'] ? 'auto' : 'manual';
		$current['product_status']           = isset( $input['product_status'] ) && 'publish' === $input['product_status'] ? 'publish' : 'pending';
		$current['license_activation_limit'] = isset( $input['license_activation_limit'] ) ? max( 1, absint( $input['license_activation_limit'] ) ) : 1;

		if ( isset( $input['allowed_upload_types'] ) ) {
			$types = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $input['allowed_upload_types'] ) ) ) );
			// Never allow executable/script types to be whitelisted.
			$types                           = array_diff( $types, array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'exe', 'js', 'html', 'htm', 'svg', 'sh', 'bat', 'cmd', 'msi' ) );
			$current['allowed_upload_types'] = implode( ',', $types );
		}

		return $current;
	}

	/**
	 * Render a settings field.
	 *
	 * @param array $args Field args with 'key'.
	 * @return void
	 */
	public static function render_field( $args ) {
		$key   = $args['key'];
		$value = wpaec_get_setting( $key );

		switch ( $key ) {
			case 'vendor_approval':
				?>
				<select name="wpaecmarket_settings[vendor_approval]">
					<option value="manual" <?php selected( $value, 'manual' ); ?>>
						<?php esc_html_e( 'Manual review (recommended)', 'aec-market' ); ?>
					</option>
					<option value="auto" <?php selected( $value, 'auto' ); ?>>
						<?php esc_html_e( 'Automatic', 'aec-market' ); ?>
					</option>
				</select>
				<?php
				break;

			case 'product_status':
				?>
				<select name="wpaecmarket_settings[product_status]">
					<option value="pending" <?php selected( $value, 'pending' ); ?>>
						<?php esc_html_e( 'Pending review (recommended)', 'aec-market' ); ?>
					</option>
					<option value="publish" <?php selected( $value, 'publish' ); ?>>
						<?php esc_html_e( 'Publish immediately', 'aec-market' ); ?>
					</option>
				</select>
				<?php
				break;

			case 'commission_rate':
			case 'license_activation_limit':
				printf(
					'<input type="number" step="%s" min="%s" name="wpaecmarket_settings[%s]" value="%s" class="small-text" />',
					'commission_rate' === $key ? '0.5' : '1',
					'commission_rate' === $key ? '0' : '1',
					esc_attr( $key ),
					esc_attr( $value )
				);
				break;

			default:
				printf(
					'<input type="text" name="wpaecmarket_settings[%s]" value="%s" class="regular-text" /><p class="description">%s</p>',
					esc_attr( $key ),
					esc_attr( $value ),
					esc_html__( 'Comma-separated file extensions vendors may upload.', 'aec-market' )
				);
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AEC Market Settings', 'aec-market' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wpaecmarket_settings_group' );
				do_settings_sections( 'wpaec-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Approve / reject / suspend vendors via nonce-protected action links.
	 *
	 * @return void
	 */
	public static function handle_vendor_actions() {
		if ( ! isset( $_GET['page'], $_GET['wpaec_action'], $_GET['user_id'] ) || 'wpaec-vendors' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			return;
		}

		if ( ! current_user_can( 'wpaec_manage_marketplace' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'aec-market' ) );
		}

		$action  = sanitize_key( wp_unslash( $_GET['wpaec_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = absint( wp_unslash( $_GET['user_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'wpaec_vendor_' . $action . '_' . $user_id );

		switch ( $action ) {
			case 'approve':
				AEC_Market_Vendor::approve( $user_id );
				break;
			case 'reject':
				AEC_Market_Vendor::deactivate_vendor( $user_id, 'rejected' );
				break;
			case 'suspend':
				AEC_Market_Vendor::deactivate_vendor( $user_id, 'suspended' );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpaec-vendors&updated=1' ) );
		exit;
	}

	/**
	 * Mark commissions paid via nonce-protected bulk form.
	 *
	 * @return void
	 */
	public static function handle_commission_actions() {
		if ( ! isset( $_POST['wpaec_mark_paid'] ) ) {
			return;
		}

		if ( ! current_user_can( 'wpaec_manage_marketplace' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'aec-market' ) );
		}

		check_admin_referer( 'wpaec_mark_paid' );

		$ids = isset( $_POST['commission_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['commission_ids'] ) ) : array();
		AEC_Market_Commissions::mark_paid( $ids );

		wp_safe_redirect( admin_url( 'admin.php?page=wpaec-commissions&updated=1' ) );
		exit;
	}

	/**
	 * HPOS-aware admin edit URL for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function order_edit_url( $order_id ) {
		$order = wc_get_order( $order_id );
		return $order ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' );
	}

	/**
	 * Vendors admin page.
	 *
	 * @return void
	 */
	public static function render_vendors_page() {
		$users = get_users(
			array(
				'meta_key'     => '_wpaec_vendor_status', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'number'       => 200,
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Marketplace Vendors', 'aec-market' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Vendor updated.', 'aec-market' ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Store', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'User', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Status', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Commission %', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'aec-market' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $users ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No vendor applications yet.', 'aec-market' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $users as $user ) : ?>
						<?php $status = wpaec_get_vendor_status( $user->ID ); ?>
						<tr>
							<td><strong><?php echo esc_html( wpaec_get_store_name( $user->ID ) ); ?></strong></td>
							<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->user_login ); ?></a> &mdash; <?php echo esc_html( $user->user_email ); ?></td>
							<td><?php echo esc_html( $status ); ?></td>
							<td><?php echo esc_html( wpaec_get_commission_rate( $user->ID ) ); ?></td>
							<td>
								<?php if ( 'approved' !== $status ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wpaec-vendors&wpaec_action=approve&user_id=' . $user->ID ), 'wpaec_vendor_approve_' . $user->ID ) ); ?>"><?php esc_html_e( 'Approve', 'aec-market' ); ?></a>
								<?php endif; ?>
								<?php if ( 'pending' === $status ) : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wpaec-vendors&wpaec_action=reject&user_id=' . $user->ID ), 'wpaec_vendor_reject_' . $user->ID ) ); ?>"><?php esc_html_e( 'Reject', 'aec-market' ); ?></a>
								<?php endif; ?>
								<?php if ( 'approved' === $status ) : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wpaec-vendors&wpaec_action=suspend&user_id=' . $user->ID ), 'wpaec_vendor_suspend_' . $user->ID ) ); ?>"><?php esc_html_e( 'Suspend', 'aec-market' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Commissions admin page.
	 *
	 * @return void
	 */
	public static function render_commissions_page() {
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = AEC_Market_Commissions::query(
			array(
				'status'   => $status,
				'page'     => $page,
				'per_page' => 20,
			)
		);

		$total_pages = (int) ceil( $result['total'] / 20 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Commissions', 'aec-market' ); ?></h1>

			<ul class="subsubsub">
				<?php
				foreach ( array(
					''         => __( 'All', 'aec-market' ),
					'pending'  => __( 'Pending', 'aec-market' ),
					'paid'     => __( 'Paid', 'aec-market' ),
					'refunded' => __( 'Refunded', 'aec-market' ),
				) as $slug => $label ) :
					?>
					<li>
						<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'   => 'wpaec-commissions',
									'status' => $slug,
								),
								admin_url( 'admin.php' )
							)
						);
						?>
									" <?php echo $status === $slug ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a> |
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="post">
				<?php wp_nonce_field( 'wpaec_mark_paid' ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.wpaec-cb').forEach(c=>c.checked=this.checked)" /></th>
							<th><?php esc_html_e( 'Order', 'aec-market' ); ?></th>
							<th><?php esc_html_e( 'Product', 'aec-market' ); ?></th>
							<th><?php esc_html_e( 'Vendor', 'aec-market' ); ?></th>
							<th><?php esc_html_e( 'Sale', 'aec-market' ); ?></th>
							<th><?php esc_html_e( 'Platform fee', 'aec-market' ); ?></th>
							<th><?php esc_html_e( 'Vendor earning', 'aec-market' ); ?></th>
							<th><?php esc_html_e( 'Status', 'aec-market' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $result['rows'] ) ) : ?>
							<tr><td colspan="8"><?php esc_html_e( 'No commissions found.', 'aec-market' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $result['rows'] as $row ) : ?>
							<tr>
								<td><input type="checkbox" class="wpaec-cb" name="commission_ids[]" value="<?php echo esc_attr( $row->id ); ?>" <?php disabled( 'pending' !== $row->status ); ?> /></td>
								<td><a href="<?php echo esc_url( self::order_edit_url( (int) $row->order_id ) ); ?>">#<?php echo esc_html( $row->order_id ); ?></a></td>
								<td><?php echo esc_html( get_the_title( (int) $row->product_id ) ); ?></td>
								<td><?php echo esc_html( wpaec_get_store_name( (int) $row->vendor_id ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $row->line_total ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $row->commission_amount ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $row->vendor_earning ) ); ?></td>
								<td><?php echo esc_html( $row->status ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="submit" name="wpaec_mark_paid" value="1" class="button button-primary"><?php esc_html_e( 'Mark selected as paid', 'aec-market' ); ?></button>
				</p>
			</form>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'total'   => $total_pages,
								'current' => $page,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Licenses admin page.
	 *
	 * @return void
	 */
	public static function render_licenses_page() {
		global $wpdb;

		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset = ( $page - 1 ) * 20;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', AEC_Market_Licenses::table() ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', AEC_Market_Licenses::table(), 20, $offset ) );
		// phpcs:enable

		$total_pages = (int) ceil( $total / 20 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Licenses', 'aec-market' ); ?></h1>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Key', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Product', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Order', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Activations', 'aec-market' ); ?></th>
						<th><?php esc_html_e( 'Status', 'aec-market' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No licenses generated yet.', 'aec-market' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $customer = get_userdata( (int) $row->user_id ); ?>
						<tr>
							<td><code><?php echo esc_html( $row->license_key ); ?></code></td>
							<td><?php echo esc_html( get_the_title( (int) $row->product_id ) ); ?></td>
							<td><?php echo esc_html( $customer ? $customer->user_email : '—' ); ?></td>
							<td><a href="<?php echo esc_url( self::order_edit_url( (int) $row->order_id ) ); ?>">#<?php echo esc_html( $row->order_id ); ?></a></td>
							<td><?php echo esc_html( AEC_Market_Licenses::count_activations( $row->id ) . ' / ' . $row->activation_limit ); ?></td>
							<td><?php echo esc_html( $row->status ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'total'   => $total_pages,
								'current' => $page,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
