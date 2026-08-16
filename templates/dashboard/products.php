<?php
/**
 * Dashboard: products tab.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

$wpaec_paged = isset( $_GET['pg'] ) ? max( 1, absint( wp_unslash( $_GET['pg'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$wpaec_products = new WP_Query(
	array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'pending', 'draft' ),
		'author'         => get_current_user_id(),
		'posts_per_page' => 15,
		'paged'          => $wpaec_paged,
	)
);
?>
<h2><?php esc_html_e( 'My Products & Services', 'aec-market' ); ?></h2>

<table class="wpaec-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Title', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Type', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Price', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Status', 'aec-market' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'aec-market' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! $wpaec_products->have_posts() ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No listings yet. Add your first program or service!', 'aec-market' ); ?></td></tr>
		<?php endif; ?>
		<?php
		while ( $wpaec_products->have_posts() ) :
			$wpaec_products->the_post();
			$wpaec_product = wc_get_product( get_the_ID() );
			?>
			<tr>
				<td><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></td>
				<td><?php echo 'service' === wpaec_get_listing_type( get_the_ID() ) ? esc_html__( 'Service', 'aec-market' ) : esc_html__( 'Program', 'aec-market' ); ?></td>
				<td><?php echo $wpaec_product ? wp_kses_post( $wpaec_product->get_price_html() ) : '—'; ?></td>
				<td><?php echo esc_html( get_post_status_object( get_post_status() )->label ); ?></td>
				<td><a class="button" href="<?php echo esc_url( AEC_Market_Dashboard::tab_url( 'edit', array( 'product' => get_the_ID() ) ) ); ?>"><?php esc_html_e( 'Edit', 'aec-market' ); ?></a></td>
			</tr>
		<?php endwhile; ?>
	</tbody>
</table>

<?php
wp_reset_postdata();

if ( $wpaec_products->max_num_pages > 1 ) {
	echo wp_kses_post(
		paginate_links(
			array(
				'base'    => esc_url_raw( add_query_arg( 'pg', '%#%', AEC_Market_Dashboard::tab_url( 'products' ) ) ),
				'format'  => '',
				'total'   => (int) $wpaec_products->max_num_pages,
				'current' => $wpaec_paged,
			)
		)
	);
}
