<?php
/**
 * Dashboard: orders tab (vendor's sold line items).
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

$wpaec_paged  = isset( $_GET['pg'] ) ? max( 1, absint( wp_unslash( $_GET['pg'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wpaec_result = AEC_Market_Commissions::query(
	array(
		'vendor_id' => get_current_user_id(),
		'page'      => $wpaec_paged,
		'per_page'  => 20,
	)
);
?>
<h2><?php esc_html_e( 'Orders', 'aec-market' ); ?></h2>

<table class="wpaec-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Order', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Product', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Date', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Sale', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Your earning', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Status', 'aec-market' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $wpaec_result['rows'] ) ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No sales yet.', 'aec-market' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $wpaec_result['rows'] as $wpaec_row ) : ?>
			<tr>
				<td>#<?php echo esc_html( $wpaec_row->order_id ); ?></td>
				<td><?php echo esc_html( get_the_title( (int) $wpaec_row->product_id ) ); ?></td>
				<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $wpaec_row->created_at ) ); ?></td>
				<td><?php echo wp_kses_post( wc_price( $wpaec_row->line_total ) ); ?></td>
				<td><?php echo wp_kses_post( wc_price( $wpaec_row->vendor_earning ) ); ?></td>
				<td><?php echo esc_html( $wpaec_row->status ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php
$wpaec_total_pages = (int) ceil( $wpaec_result['total'] / 20 );
if ( $wpaec_total_pages > 1 ) {
	echo wp_kses_post(
		paginate_links(
			array(
				'base'    => esc_url_raw( add_query_arg( 'pg', '%#%', AEC_Market_Dashboard::tab_url( 'orders' ) ) ),
				'format'  => '',
				'total'   => $wpaec_total_pages,
				'current' => $wpaec_paged,
			)
		)
	);
}
