<?php
/**
 * Front-end shortcodes and asset loading.
 *
 * [aec_tools_tools]            Dashboard grid of every service (+ single-tool view).
 * [aec_tools_tool key="rfi"]  A single tool's form.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the customer-facing UI.
 */
class Shortcodes {

	/**
	 * Register shortcodes and assets.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'aec_forge_tools', array( $this, 'tools' ) );
		add_shortcode( 'aec_forge_tool', array( $this, 'single' ) );
		add_shortcode( 'aec_forge_pricing', array( $this, 'pricing' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) assets; the shortcodes enqueue on demand.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'aec-forge-tools', AEC_FORGE_TOOLS_URL . 'assets/tools/css/aec-forge-tools.css', array(), AEC_FORGE_TOOLS_VERSION );
		wp_register_script( 'aec-forge-tools', AEC_FORGE_TOOLS_URL . 'assets/tools/js/aec-forge-tools.js', array(), AEC_FORGE_TOOLS_VERSION, true );
		wp_localize_script(
			'aec-market',
			'AECForgeToolsData',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'aec-market' ),
				'samplesUrl' => AEC_FORGE_TOOLS_URL . 'assets/tools/samples/',
				'i18n'       => array(
					'running'  => __( 'Running…', 'aec-market' ),
					'run'      => __( 'Run', 'aec-market' ),
					'error'    => __( 'Something went wrong.', 'aec-market' ),
					'loaded'   => __( 'Sample loaded ✓', 'aec-market' ),
					'loadingS' => __( 'Loading…', 'aec-market' ),
					'sample'   => __( 'Try it with sample data', 'aec-market' ),
				),
			)
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @return void
	 */
	private function enqueue() {
		wp_enqueue_style( 'aec-forge-tools' );
		wp_enqueue_script( 'aec-forge-tools' );
	}

	/**
	 * [aec_tools_tools] — grid, or a single tool when ?ff_tool is set.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function tools( $atts ) {
		$this->enqueue();
		$active = isset( $_GET['ff_tool'] ) ? sanitize_key( wp_unslash( $_GET['ff_tool'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( '' !== $active && Tools::instance()->services->get( $active ) ) {
			return $this->render_tool( $active, true );
		}
		return $this->render_grid();
	}

	/**
	 * [aec_tools_tool key="rfi"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function single( $atts ) {
		$this->enqueue();
		$atts = shortcode_atts( array( 'key' => '' ), $atts, 'aec_forge_tool' );
		$key  = sanitize_key( $atts['key'] );
		if ( '' === $key || ! Tools::instance()->services->get( $key ) ) {
			return '<div class="mp-notice">' . esc_html__( 'Unknown AEC Forge tool.', 'aec-market' ) . '</div>';
		}
		return $this->render_tool( $key, false );
	}

	/**
	 * Grid of all services + credit balance + buy links.
	 *
	 * @return string
	 */
	private function render_grid() {
		$services = Tools::instance()->services->all();
		$logged   = is_user_logged_in();
		$page_url = get_permalink();

		ob_start();
		?>
		<div class="mp-wrap">
			<?php echo $this->account_bar( $logged ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping. ?>

			<div class="mp-hero" style="--mp-hero: url('<?php echo esc_url( AEC_FORGE_TOOLS_URL . 'assets/tools/img/hero.svg' ); ?>');">
				<div class="mp-hero-copy">
					<p class="mp-hero-eyebrow"><?php esc_html_e( 'AEC Forge Tools', 'aec-market' ); ?></p>
					<h2 class="mp-hero-title"><?php echo wp_kses_post( __( 'The tedious GC paperwork, <span>done in a click.</span>', 'aec-market' ) ); ?></h2>
					<p class="mp-hero-sub"><?php esc_html_e( 'RFIs, submittal-log reviews, G702/G703 pay-apps and cost-exposure reports — drafted by AI and delivered as clean .docx / .xlsx. Pay only for the runs you use.', 'aec-market' ); ?></p>
				</div>
			</div>

			<div class="mp-grid">
				<?php foreach ( $services as $service ) : ?>
					<?php $url = add_query_arg( 'ff_tool', $service->key(), $page_url ); ?>
					<a class="mp-card mp-card-link" href="<?php echo esc_url( $url ); ?>">
						<span class="mp-card-ic"><img src="<?php echo esc_url( $this->icon_url( $service->key() ) ); ?>" alt="" width="56" height="56" loading="lazy" decoding="async" /></span>
						<h3><?php echo esc_html( $service->name() ); ?></h3>
						<p><?php echo esc_html( $service->blurb() ); ?></p>
						<span class="mp-card-foot">
							<span class="mp-cost"><?php echo esc_html( sprintf( /* translators: %d: number of credits */ _n( '%d credit', '%d credits', $service->credits(), 'aec-market' ), $service->credits() ) ); ?></span>
							<span class="mp-btn mp-btn-ghost"><?php esc_html_e( 'Open', 'aec-market' ); ?> &rarr;</span>
						</span>
					</a>
				<?php endforeach; ?>
			</div>

			<?php echo $this->buy_section(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping. ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Icon URL for a service, falling back to a generic tile.
	 *
	 * @param string $key Service key.
	 * @return string
	 */
	private function icon_url( $key ) {
		$known = array( 'rfi', 'submittals', 'payapp', 'costexposure', 'dailyreport', 'minutes' );
		$slug  = in_array( $key, $known, true ) ? $key : 'default';
		return AEC_FORGE_TOOLS_URL . 'assets/tools/img/tool-' . $slug . '.svg';
	}

	/**
	 * [aec_forge_pricing] — a dedicated credit-pricing page.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function pricing( $atts ) {
		$this->enqueue();
		return $this->render_pricing();
	}

	/**
	 * Pricing cards + per-tool cost table.
	 *
	 * @return string
	 */
	private function render_pricing() {
		$packs    = Settings::value( 'packs', array() );
		$map      = Settings::value( 'product_map', array() );
		$services = Tools::instance()->services->all();
		$trial    = (int) Settings::value( 'free_trial_credits', 0 );
		$tp       = (int) Settings::value( 'tools_page_id', 0 );
		$tools_url = $tp ? get_permalink( $tp ) : home_url( '/forge-tools/' );
		$count    = count( $packs );
		$has_woo  = Tools::woocommerce_active();

		ob_start();
		?>
		<div class="mp-wrap mp-pricing">
			<div class="mp-pricing-head">
				<p class="mp-eyebrow"><?php esc_html_e( 'AEC Forge Tools', 'aec-market' ); ?></p>
				<h2><?php esc_html_e( 'Simple credit pricing', 'aec-market' ); ?></h2>
				<p class="mp-muted">
					<?php
					esc_html_e( 'Buy credits once, then spend them on any tool — credits never expire.', 'aec-market' );
					if ( $trial > 0 ) {
						echo ' ';
						echo esc_html( sprintf( /* translators: %d: trial credits */ _n( 'New accounts start with %d free credit.', 'New accounts start with %d free credits.', $trial, 'aec-market' ), $trial ) );
					}
					?>
				</p>
			</div>

			<div class="mp-price-grid">
				<?php
				$i = 0;
				foreach ( $packs as $pack ) :
					++$i;
					$featured = ( $count >= 3 && 2 === $i );
					$pid      = isset( $map[ $pack['id'] ] ) ? (int) $map[ $pack['id'] ] : 0;
					$link     = $pid ? get_permalink( $pid ) : '';
					$per      = ! empty( $pack['credits'] ) ? $pack['price'] / $pack['credits'] : 0;
					?>
					<div class="mp-price-card<?php echo $featured ? ' mp-price-featured' : ''; ?>">
						<?php if ( $featured ) : ?>
							<span class="mp-price-badge"><?php esc_html_e( 'Most popular', 'aec-market' ); ?></span>
						<?php endif; ?>
						<h3><?php echo esc_html( $pack['name'] ); ?></h3>
						<p class="mp-price-amt">$<?php echo esc_html( number_format( (float) $pack['price'], 0 ) ); ?></p>
						<p class="mp-price-meta">
							<?php echo esc_html( sprintf( /* translators: %d: credits */ _n( '%d credit', '%d credits', (int) $pack['credits'], 'aec-market' ), (int) $pack['credits'] ) ); ?>
							· <?php echo esc_html( sprintf( /* translators: %s: dollars per run */ __( '≈ $%s / run', 'aec-market' ), number_format( $per, 2 ) ) ); ?>
						</p>
						<?php if ( $has_woo && $link ) : ?>
							<a class="mp-btn mp-btn-solid" href="<?php echo esc_url( add_query_arg( 'add-to-cart', $pid, $link ) ); ?>">
								<?php echo esc_html( sprintf( /* translators: %s: pack name */ __( 'Buy %s', 'aec-market' ), $pack['name'] ) ); ?>
							</a>
						<?php else : ?>
							<span class="mp-muted"><?php esc_html_e( 'product syncing…', 'aec-market' ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mp-costs">
				<h3><?php esc_html_e( 'What a run costs', 'aec-market' ); ?></h3>
				<table class="mp-costs-table">
					<tbody>
						<?php foreach ( $services as $service ) : ?>
							<tr>
								<td><?php echo esc_html( $service->name() ); ?></td>
								<td><?php echo esc_html( sprintf( /* translators: %d: credits */ _n( '%d credit', '%d credits', $service->credits(), 'aec-market' ), $service->credits() ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="mp-muted mp-disclaimer"><?php echo esc_html( Settings::value( 'disclaimer' ) ); ?></p>
			</div>

			<p class="mp-pricing-cta">
				<a class="mp-btn mp-btn-solid" href="<?php echo esc_url( $tools_url ); ?>"><?php esc_html_e( 'Open the tools', 'aec-market' ); ?> &rarr;</a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * A single tool's form + result area.
	 *
	 * @param string $key       Service key.
	 * @param bool   $with_back Show a back link to the grid.
	 * @return string
	 */
	private function render_tool( $key, $with_back ) {
		$service = Tools::instance()->services->get( $key );
		$logged  = is_user_logged_in();

		ob_start();
		?>
		<div class="mp-wrap mp-tool" data-service="<?php echo esc_attr( $key ); ?>"
			data-sample-file="<?php echo esc_attr( $service->sample_file() ); ?>">

			<?php echo $this->account_bar( $logged ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( $with_back ) : ?>
				<p><a class="mp-back" href="<?php echo esc_url( remove_query_arg( 'ff_tool' ) ); ?>">&larr; <?php esc_html_e( 'All tools', 'aec-market' ); ?></a></p>
			<?php endif; ?>

			<div class="mp-tool-head">
				<span class="mp-card-ic mp-tool-ic"><img src="<?php echo esc_url( $this->icon_url( $key ) ); ?>" alt="" width="64" height="64" decoding="async" /></span>
				<div>
					<p class="mp-eyebrow"><?php echo esc_html( sprintf( /* translators: %d: number of credits */ _n( '%d credit per run', '%d credits per run', $service->credits(), 'aec-market' ), $service->credits() ) ); ?></p>
					<h2><?php echo esc_html( $service->name() ); ?></h2>
					<p class="mp-muted"><?php echo esc_html( $service->blurb() ); ?></p>
				</div>
			</div>

			<?php if ( ! $logged ) : ?>
				<div class="mp-notice"><?php
					printf(
						/* translators: %s login URL */
						wp_kses_post( __( 'Please <a href="%s">sign in</a> to run this tool.', 'aec-market' ) ),
						esc_url( wp_login_url( get_permalink() ) )
					);
				?></div>
			<?php else : ?>
				<form class="mp-form" novalidate>
					<div class="mp-sample-bar">
						<button type="button" class="mp-btn mp-load-sample"><?php esc_html_e( 'Try it with sample data', 'aec-market' ); ?></button>
						<span class="mp-muted"><?php esc_html_e( 'Fills the form with a realistic example — then hit Run.', 'aec-market' ); ?></span>
					</div>

					<?php foreach ( $service->fields() as $field ) : ?>
						<?php echo $this->render_field( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping. ?>
					<?php endforeach; ?>

					<button type="submit" class="mp-btn mp-btn-solid mp-run">
						<?php echo esc_html( sprintf( /* translators: %d: number of credits */ _n( 'Run · %d credit', 'Run · %d credits', $service->credits(), 'aec-market' ), $service->credits() ) ); ?>
					</button>
				</form>

				<div class="mp-result-wrap" hidden>
					<div class="mp-download" hidden>
						<div>
							<p class="mp-eyebrow"><?php esc_html_e( 'Your deliverable is ready', 'aec-market' ); ?></p>
							<strong class="mp-download-name"></strong>
						</div>
						<a class="mp-btn mp-btn-solid mp-download-link" href="#"><?php esc_html_e( 'Download file', 'aec-market' ); ?></a>
					</div>
					<h3><?php esc_html_e( 'Result', 'aec-market' ); ?></h3>
					<pre class="mp-result"></pre>
					<p class="mp-muted mp-disclaimer"><?php echo esc_html( Settings::value( 'disclaimer' ) ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render one form field.
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	private function render_field( $field ) {
		$name        = esc_attr( $field['name'] );
		$label       = isset( $field['label'] ) ? esc_html( $field['label'] ) : '';
		$type        = isset( $field['type'] ) ? $field['type'] : 'text';
		$placeholder = isset( $field['placeholder'] ) ? esc_attr( $field['placeholder'] ) : '';
		$required    = ! empty( $field['required'] ) ? ' required' : '';
		$full        = ! empty( $field['full'] ) ? ' mp-field-full' : '';
		$sample      = isset( $field['sample'] ) ? ' data-sample="' . esc_attr( $field['sample'] ) . '"' : '';
		$is_paste    = ! empty( $field['is_paste'] ) ? ' data-paste="1"' : '';

		ob_start();
		echo '<div class="mp-field' . esc_attr( $full ) . '">';
		if ( '' !== $label ) {
			echo '<label for="mp-' . $name . '">' . $label . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		if ( 'textarea' === $type ) {
			echo '<textarea id="mp-' . $name . '" name="' . $name . '" placeholder="' . $placeholder . '"' . $required . $is_paste . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts escaped.
		} elseif ( 'file' === $type ) {
			$accept = isset( $field['accept'] ) ? ' accept="' . esc_attr( $field['accept'] ) . '"' : '';
			echo '<input type="file" id="mp-' . $name . '" name="' . $name . '"' . $accept . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts escaped.
		} else {
			echo '<input type="text" id="mp-' . $name . '" name="' . $name . '" placeholder="' . $placeholder . '"' . $required . $sample . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts escaped.
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Account/credits bar.
	 *
	 * @param bool $logged Logged in.
	 * @return string
	 */
	private function account_bar( $logged ) {
		if ( ! $logged ) {
			return '<div class="mp-account"><a class="mp-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'aec-market' ) . '</a></div>';
		}
		$balance = Credits::get_balance( get_current_user_id() );
		return '<div class="mp-account"><span class="mp-credit-chip">'
			. esc_html( sprintf( /* translators: %d: number of credits */ _n( '%d credit', '%d credits', $balance, 'aec-market' ), $balance ) )
			. '</span></div>';
	}

	/**
	 * Buy-credits section (WooCommerce products).
	 *
	 * @return string
	 */
	private function buy_section() {
		$packs = Settings::value( 'packs', array() );
		if ( empty( $packs ) || ! Tools::woocommerce_active() ) {
			return '';
		}
		$map = Settings::value( 'product_map', array() );

		ob_start();
		echo '<h3 class="mp-buy-title">' . esc_html__( 'Buy credits', 'aec-market' ) . '</h3>';
		echo '<div class="mp-grid">';
		foreach ( $packs as $pack ) {
			$product_id = isset( $map[ $pack['id'] ] ) ? (int) $map[ $pack['id'] ] : 0;
			$link       = $product_id ? get_permalink( $product_id ) : '';
			$per        = $pack['credits'] ? $pack['price'] / $pack['credits'] : 0;
			echo '<div class="mp-card"><h3>' . esc_html( $pack['name'] ) . '</h3>';
			echo '<p class="mp-price">$' . esc_html( number_format( (float) $pack['price'], 2 ) ) . ' <span class="mp-muted">/ '
				. esc_html( sprintf( /* translators: %d: number of credits */ _n( '%d credit', '%d credits', $pack['credits'], 'aec-market' ), $pack['credits'] ) ) . '</span></p>';
			echo '<p class="mp-muted">≈ $' . esc_html( number_format( $per, 2 ) ) . ' ' . esc_html__( 'per run', 'aec-market' ) . '</p>';
			echo '<div class="mp-card-foot"><span></span>';
			if ( $link ) {
				echo '<a class="mp-btn mp-btn-solid" href="' . esc_url( add_query_arg( 'add-to-cart', $product_id, $link ) ) . '">' . esc_html__( 'Buy', 'aec-market' ) . '</a>';
			} else {
				echo '<span class="mp-muted">' . esc_html__( 'product syncing…', 'aec-market' ) . '</span>';
			}
			echo '</div></div>';
		}
		echo '</div>';
		return ob_get_clean();
	}
}
