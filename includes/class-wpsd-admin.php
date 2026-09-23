<?php
/**
 * Admin area: menus, pages, asset enqueue, settings, CSV import.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Admin
 */
class WPSD_Admin {

	/**
	 * Register the top-level menu + submenus.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'Support Desk', 'affiniti-wp-support' ),
			__( 'Support Desk', 'affiniti-wp-support' ),
			'wpsd_view_tickets',
			'wpsd-support',
			array( $this, 'render_app_page' ),
			'dashicons-sos',
			26
		);
		add_submenu_page(
			'wpsd-support',
			__( 'All Tickets', 'affiniti-wp-support' ),
			__( 'All Tickets', 'affiniti-wp-support' ),
			'wpsd_view_tickets',
			'wpsd-support',
			array( $this, 'render_app_page' )
		);
		add_submenu_page(
			'wpsd-support',
			__( 'Import / Settings', 'affiniti-wp-support' ),
			__( 'Import / Settings', 'affiniti-wp-support' ),
			'manage_options',
			'wpsd-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue the compiled admin SPA bundle (no Node needed on production).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_wpsd-support' !== $hook ) {
			return;
		}
		$js  = WPSD_PLUGIN_URL . 'assets/admin-dist/wpsd-admin.js';
		$css = WPSD_PLUGIN_URL . 'assets/admin-dist/wpsd-admin.css';

		if ( file_exists( WPSD_PLUGIN_DIR . 'assets/admin-dist/wpsd-admin.css' ) ) {
			wp_enqueue_style( 'wpsd-admin', $css, array(), WPSD_VERSION );
		}
		wp_enqueue_script( 'wpsd-admin', $js, array( 'wp-api-fetch', 'wp-i18n' ), WPSD_VERSION, true );
		wp_set_script_translations( 'wpsd-admin', 'affiniti-wp-support', WPSD_PLUGIN_DIR . 'languages' );

		$config = array(
			'restUrl'   => esc_url_raw( rest_url( WPSD_REST_NAMESPACE . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'canManage' => current_user_can( 'wpsd_manage_tickets' ),
			'canAssign' => current_user_can( 'wpsd_assign_tickets' ),
			'canDelete' => current_user_can( 'wpsd_delete_tickets' ),
			'userId'    => get_current_user_id(),
			'i18n'      => array(
				'tickets' => __( 'Tickets', 'affiniti-wp-support' ),
			),
		);
		wp_add_inline_script(
			'wpsd-admin',
			'window.WPSD_ADMIN_CONFIG = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Render the admin SPA root. The React bundle hydrates #wpsd-admin-root;
	 * a server-rendered summary table is included for no-JS environments.
	 */
	public function render_app_page() {
		if ( ! current_user_can( 'wpsd_view_tickets' ) ) {
			wp_die( esc_html__( 'You do not have permission to view tickets.', 'affiniti-wp-support' ) );
		}
		$stats = WPSD_Tickets::stats();
		?>
		<div class="wrap wpsd-admin-wrap">
			<h1><?php echo esc_html__( 'Support Desk', 'affiniti-wp-support' ); ?></h1>
			<div id="wpsd-admin-root"
				data-rest-url="<?php echo esc_attr( rest_url( WPSD_REST_NAMESPACE . '/' ) ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
				<noscript>
					<p><?php echo esc_html__( 'The ticket dashboard needs JavaScript. Summary (cached 5 minutes):', 'affiniti-wp-support' ); ?></p>
				</noscript>
				<div class="wpsd-noscript-summary">
					<p><strong><?php echo esc_html__( 'Total tickets:', 'affiniti-wp-support' ); ?></strong>
						<?php echo esc_html( (string) $stats['total'] ); ?></p>
					<ul>
						<?php foreach ( $stats['by_status'] as $status => $count ) : ?>
							<li><?php echo esc_html( $status . ': ' . $count ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings + CSV import page (manage_options only).
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'affiniti-wp-support' ) );
		}

		// Handle settings save.
		if ( isset( $_POST['wpsd_settings_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpsd_settings_nonce'] ) ), 'wpsd_save_settings' ) ) {
			update_option( 'wpsd_public_rate_per_ip', max( 1, absint( wp_unslash( $_POST['wpsd_public_rate_per_ip'] ?? 10 ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			update_option( 'wpsd_public_rate_per_mobile', max( 1, absint( wp_unslash( $_POST['wpsd_public_rate_per_mobile'] ?? 5 ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			update_option( 'wpsd_uninstall_cleanup', ! empty( $_POST['wpsd_uninstall_cleanup'] ) ? 1 : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'affiniti-wp-support' ) . '</p></div>';
		}

		// Handle CSV import.
		if ( isset( $_POST['wpsd_import_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpsd_import_nonce'] ) ), 'wpsd_csv_import' ) ) {
			$this->handle_csv_import();
		}

		$rate_ip     = (int) get_option( 'wpsd_public_rate_per_ip', 10 );
		$rate_mobile = (int) get_option( 'wpsd_public_rate_per_mobile', 5 );
		$cleanup     = (int) get_option( 'wpsd_uninstall_cleanup', 0 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Support Desk — Import / Settings', 'affiniti-wp-support' ); ?></h1>

			<h2><?php echo esc_html__( 'Rate limits', 'affiniti-wp-support' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'wpsd_save_settings', 'wpsd_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpsd_public_rate_per_ip"><?php echo esc_html__( 'Public submissions per IP / hour', 'affiniti-wp-support' ); ?></label></th>
						<td><input id="wpsd_public_rate_per_ip" name="wpsd_public_rate_per_ip" type="number" min="1" max="1000" value="<?php echo esc_attr( (string) $rate_ip ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsd_public_rate_per_mobile"><?php echo esc_html__( 'Public submissions per mobile / hour', 'affiniti-wp-support' ); ?></label></th>
						<td><input id="wpsd_public_rate_per_mobile" name="wpsd_public_rate_per_mobile" type="number" min="1" max="100" value="<?php echo esc_attr( (string) $rate_mobile ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'On uninstall', 'affiniti-wp-support' ); ?></th>
						<td><label><input name="wpsd_uninstall_cleanup" type="checkbox" value="1" <?php checked( $cleanup, 1 ); ?> />
							<?php echo esc_html__( 'Delete all ticket data and tables (requires confirmation on the uninstall screen).', 'affiniti-wp-support' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'affiniti-wp-support' ) ); ?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'CSV import (lookup tables)', 'affiniti-wp-support' ); ?></h2>
			<p><?php echo esc_html__( 'Upload a CSV with a header row matching the sample files in includes/data/. Existing rows are kept; the importer only adds new rows.', 'affiniti-wp-support' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'wpsd_csv_import', 'wpsd_import_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpsd_import_table"><?php echo esc_html__( 'Table', 'affiniti-wp-support' ); ?></label></th>
						<td>
							<select id="wpsd_import_table" name="wpsd_import_table">
								<option value="districts">districts (name)</option>
								<option value="thanas">thanas (district_name, name)</option>
								<option value="routes">routes (thana_name, district_name, name)</option>
								<option value="service_centers">service_centers (route_name, thana_name, name, address, contact_phone)</option>
								<option value="products">products (brand, model_name, category)</option>
								<option value="problem_types">problem_types (product_category, label)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsd_import_file"><?php echo esc_html__( 'CSV file', 'affiniti-wp-support' ); ?></label></th>
						<td><input id="wpsd_import_file" name="wpsd_import_file" type="file" accept=".csv,text/csv" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Import CSV', 'affiniti-wp-support' ), 'secondary' ); ?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'API clients', 'affiniti-wp-support' ); ?></h2>
			<p><?php echo esc_html__( 'Create, rotate, and revoke keys from the Support Desk app (API Clients tab). Secrets are stored hashed and shown once.', 'affiniti-wp-support' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Process an uploaded CSV file.
	 */
	private function handle_csv_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import data.', 'affiniti-wp-support' ) );
		}
		$table = isset( $_POST['wpsd_import_table'] ) ? sanitize_key( wp_unslash( $_POST['wpsd_import_table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in caller.
		$allowed = array( 'districts', 'thanas', 'routes', 'service_centers', 'products', 'problem_types' );
		if ( ! in_array( $table, $allowed, true ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid table selected.', 'affiniti-wp-support' ) . '</p></div>';
			return;
		}
		if ( empty( $_FILES['wpsd_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in caller.
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Please choose a CSV file.', 'affiniti-wp-support' ) . '</p></div>';
			return;
		}
		$file = $_FILES['wpsd_import_file']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified; file validated below.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $check['ext'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Only .csv files are accepted.', 'affiniti-wp-support' ) . '</p></div>';
			return;
		}
		$result = WPSD_DB::import_csv( $table, $file['tmp_name'] );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Imported %d rows into %s.', 'affiniti-wp-support' ), (int) $result, $table ) ) . '</p></div>';
	}

	/**
	 * Dashboard widget: ticket counts + avg resolution (5-min cached).
	 */
	public function dashboard_widget() {
		wp_add_dashboard_widget(
			'wpsd_dashboard',
			__( 'Support Desk', 'affiniti-wp-support' ),
			function () {
				if ( ! current_user_can( 'wpsd_view_tickets' ) ) {
					echo esc_html__( 'No permission.', 'affiniti-wp-support' );
					return;
				}
				$stats = WPSD_Tickets::stats();
				echo '<ul>';
				echo '<li><strong>' . esc_html__( 'Total:', 'affiniti-wp-support' ) . '</strong> ' . esc_html( (string) $stats['total'] ) . '</li>';
				foreach ( $stats['by_status'] as $status => $count ) {
					echo '<li>' . esc_html( $status ) . ': ' . esc_html( (string) $count ) . '</li>';
				}
				if ( null !== $stats['avg_resolution_hours'] ) {
					echo '<li>' . esc_html__( 'Avg resolution (hrs):', 'affiniti-wp-support' ) . ' ' . esc_html( (string) $stats['avg_resolution_hours'] ) . '</li>';
				}
				echo '</ul>';
				echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpsd-support' ) ) . '">' . esc_html__( 'Open Support Desk', 'affiniti-wp-support' ) . '</a></p>';
			}
		);
	}
}
