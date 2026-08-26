<?php
/**
 * AEC software-compatibility taxonomy, badges and shop filtering.
 *
 * Lets vendors tag which software/versions a listing works with (Revit 2025,
 * Dynamo, Grasshopper, IFC, …) and lets buyers filter the shop by it — the
 * AEC-native equivalent of Envato's "compatible with" facet.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility taxonomy + UI.
 */
class AEC_Market_Compat {

	const TAX = 'aec_compat';

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 20 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_badges_single' ), 6 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_shop' ) );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'render_filter_pills' ), 15 );
	}

	/**
	 * Register the non-public product taxonomy (queryable, manageable in admin).
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAX,
			'product',
			array(
				'label'             => __( 'Compatible with', 'aec-market' ),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Default AEC software/versions to seed.
	 *
	 * @return string[]
	 */
	public static function default_terms() {
		return array(
			'Revit 2026', 'Revit 2025', 'Revit 2024', 'Dynamo', 'Grasshopper',
			'Rhino 8', 'Rhino 7', 'Navisworks', 'Civil 3D', 'AutoCAD',
			'IFC', 'Excel', 'Power BI', 'Python',
		);
	}

	/**
	 * Seed the default terms once.
	 *
	 * @return void
	 */
	public static function maybe_seed() {
		if ( get_option( 'wpaec_compat_seeded' ) || ! taxonomy_exists( self::TAX ) ) {
			return;
		}
		foreach ( self::default_terms() as $name ) {
			if ( ! term_exists( $name, self::TAX ) ) {
				wp_insert_term( $name, self::TAX );
			}
		}
		update_option( 'wpaec_compat_seeded', 1, false );
	}

	/**
	 * All compatibility terms.
	 *
	 * @param bool $hide_empty Only terms in use.
	 * @return WP_Term[]
	 */
	public static function get_terms( $hide_empty = false ) {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => (bool) $hide_empty,
				'orderby'    => 'name',
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Compatibility badges on the single product page.
	 *
	 * @return void
	 */
	public static function render_badges_single() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$terms = get_the_terms( $product->get_id(), self::TAX );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return;
		}
		echo '<div class="wpaec-compat"><span class="wpaec-compat__label">' . esc_html__( 'Compatible with:', 'aec-market' ) . '</span> ';
		foreach ( $terms as $term ) {
			printf(
				'<a class="wpaec-compat__badge" href="%s">%s</a>',
				esc_url( add_query_arg( 'compat', $term->slug, wc_get_page_permalink( 'shop' ) ) ),
				esc_html( $term->name )
			);
		}
		echo '</div>';
	}

	/**
	 * Filter the shop query by ?compat=slug.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public static function filter_shop( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( empty( $_GET['compat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			return;
		}
		if ( ! ( ( function_exists( 'is_shop' ) && is_shop() ) || is_post_type_archive( 'product' ) || is_tax( 'product_cat' ) ) ) {
			return;
		}
		$slug     = sanitize_title( wp_unslash( $_GET['compat'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tax_query = (array) $query->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy' => self::TAX,
			'field'    => 'slug',
			'terms'    => $slug,
		);
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Render filter pills above the shop loop.
	 *
	 * @return void
	 */
	public static function render_filter_pills() {
		if ( ! ( ( function_exists( 'is_shop' ) && is_shop() ) || is_post_type_archive( 'product' ) ) ) {
			return;
		}
		$terms = self::get_terms( true ); // only in-use tags.
		if ( empty( $terms ) ) {
			return;
		}
		$active = isset( $_GET['compat'] ) ? sanitize_title( wp_unslash( $_GET['compat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wpaec-compat-filter"><span class="wpaec-compat-filter__label">' . esc_html__( 'Works with', 'aec-market' ) . '</span>';
		printf(
			'<a class="wpaec-compat-pill%s" href="%s">%s</a>',
			'' === $active ? ' is-active' : '',
			esc_url( remove_query_arg( 'compat' ) ),
			esc_html__( 'All', 'aec-market' )
		);
		foreach ( $terms as $term ) {
			printf(
				'<a class="wpaec-compat-pill%s" href="%s">%s</a>',
				$active === $term->slug ? ' is-active' : '',
				esc_url( add_query_arg( 'compat', $term->slug ) ),
				esc_html( $term->name )
			);
		}
		echo '</div>';
	}
}
