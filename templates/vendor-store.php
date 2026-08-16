<?php
/**
 * Public vendor store template.
 *
 * Override by copying to yourtheme/aec-market/vendor-store.php
 *
 * @package AEC_Market
 *
 * @var array $args { vendor_id: int, query: WP_Query }
 */

defined( 'ABSPATH' ) || exit;

$wpaec_vendor_id = absint( $args['vendor_id'] );
$wpaec_query     = $args['query'];
$wpaec_bio       = get_user_meta( $wpaec_vendor_id, '_wpaec_store_bio', true );
?>
<div class="wpaec-store">
	<header class="wpaec-store__header">
		<?php echo wp_kses_post( (string) get_avatar( $wpaec_vendor_id, 80 ) ); ?>
		<div>
			<h2 class="wpaec-store__name"><?php echo esc_html( wpaec_get_store_name( $wpaec_vendor_id ) ); ?></h2>
			<?php if ( $wpaec_bio ) : ?>
				<p class="wpaec-store__bio"><?php echo esc_html( $wpaec_bio ); ?></p>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( $wpaec_query->have_posts() ) : ?>
		<ul class="products columns-3 wpaec-store__products">
			<?php
			while ( $wpaec_query->have_posts() ) {
				$wpaec_query->the_post();
				wc_get_template_part( 'content', 'product' );
			}
			?>
		</ul>
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'total'   => (int) $wpaec_query->max_num_pages,
					'current' => max( 1, absint( get_query_var( 'paged' ) ? get_query_var( 'paged' ) : get_query_var( 'page' ) ) ),
				)
			)
		);
		?>
	<?php else : ?>
		<p><?php esc_html_e( 'This vendor has no published listings yet.', 'aec-market' ); ?></p>
	<?php endif; ?>
</div>
