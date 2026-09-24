<?php
/**
 * Public-facing: shortcodes + asset enqueue.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Public
 */
class WPSD_Public {

	/**
	 * Register shortcodes.
	 */
	public function register_shortcodes() {
		add_shortcode( 'wpsd_ticket_form', array( $this, 'render_form_shortcode' ) );
		add_shortcode( 'wpsd_ticket_lookup', array( $this, 'render_lookup_shortcode' ) );
	}

	/**
	 * Enqueue the compiled public SPA bundle only when a shortcode is present.
	 */
	public function enqueue_assets() {
		global $post;
		if ( ! is_singular() || ! ( $post instanceof WP_Post ) ) {
			return;
		}
		if ( ! has_shortcode( $post->post_content, 'wpsd_ticket_form' ) && ! has_shortcode( $post->post_content, 'wpsd_ticket_lookup' ) ) {
			return;
		}

		$js  = WPSD_PLUGIN_URL . 'assets/public-dist/wpsd-public.js';
		$css = WPSD_PLUGIN_URL . 'assets/public-dist/wpsd-public.css';
		$ver = WPSD_Admin::asset_version( 'public' );

		if ( file_exists( WPSD_PLUGIN_DIR . 'assets/public-dist/wpsd-public.css' ) ) {
			wp_enqueue_style( 'wpsd-public', $css, array(), $ver );
		}
		wp_enqueue_script( 'wpsd-public', $js, array(), $ver, true );

		wp_add_inline_script(
			'wpsd-public',
			'window.WPSD_PUBLIC_CONFIG = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( WPSD_REST_NAMESPACE . '/' ) ),
					'nonce'   => wp_create_nonce( 'wpsd_public_form' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * [wpsd_ticket_form] — guest ticket submission + status lookup tabs.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_form_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'class' => '' ), $atts, 'wpsd_ticket_form' );
		ob_start();
		?>
		<div id="wpsd-public-root" class="wpsd-public <?php echo esc_attr( $atts['class'] ); ?>"
			data-rest-url="<?php echo esc_attr( rest_url( WPSD_REST_NAMESPACE . '/' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpsd_public_form' ) ); ?>">
			<noscript>
				<div class="wpsd-card">
					<p><?php echo esc_html__( 'The service request form needs JavaScript. Please enable JavaScript, or contact support by phone.', 'affiniti-wp-support' ); ?></p>
					<p><a href="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"><?php echo esc_html__( 'Contact support', 'affiniti-wp-support' ); ?></a></p>
				</div>
			</noscript>
			<div class="wpsd-loading" aria-live="polite"><?php echo esc_html__( 'Loading service request form…', 'affiniti-wp-support' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [wpsd_ticket_lookup] — SPEC DECISION (Open Q5): guest status lookup
	 * (ticket number + mobile ownership check) as a standalone shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_lookup_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'class' => '' ), $atts, 'wpsd_ticket_lookup' );
		ob_start();
		?>
		<div id="wpsd-lookup-root" class="wpsd-public <?php echo esc_attr( $atts['class'] ); ?>"
			data-rest-url="<?php echo esc_attr( rest_url( WPSD_REST_NAMESPACE . '/' ) ); ?>">
			<noscript><p><?php echo esc_html__( 'Ticket lookup needs JavaScript.', 'affiniti-wp-support' ); ?></p></noscript>
			<div class="wpsd-loading" aria-live="polite"><?php echo esc_html__( 'Loading ticket lookup…', 'affiniti-wp-support' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}
}
