<?php
/**
 * Database layer: table creation, schema upgrades, CSV seeding.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_DB
 *
 * Owns all custom-table DDL plus the CSV seed/import mechanism.
 * Every query with variables uses $wpdb->prepare().
 */
class WPSD_DB {

	/**
	 * Get the full (prefixed) table name for a short key.
	 *
	 * @param string $key One of: districts, thanas, routes, service_centers, products, problem_types, tickets, replies, attachments, api_clients.
	 * @return string
	 */
	public static function table( $key ) {
		global $wpdb;
		$map = array(
			'districts'       => 'wpsd_districts',
			'thanas'          => 'wpsd_thanas',
			'routes'          => 'wpsd_routes',
			'service_centers' => 'wpsd_service_centers',
			'products'        => 'wpsd_products',
			'problem_types'   => 'wpsd_problem_types',
			'tickets'         => 'wpsd_tickets',
			'replies'         => 'wpsd_replies',
			'attachments'     => 'wpsd_attachments',
			'api_clients'     => 'wpsd_api_clients',
		);
		$name = isset( $map[ $key ] ) ? $map[ $key ] : 'wpsd_unknown';
		return $wpdb->prefix . $name;
	}

	/**
	 * Create or upgrade all tables via dbDelta().
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$districts       = self::table( 'districts' );
		$thanas          = self::table( 'thanas' );
		$routes          = self::table( 'routes' );
		$service_centers = self::table( 'service_centers' );
		$products        = self::table( 'products' );
		$problem_types   = self::table( 'problem_types' );
		$tickets         = self::table( 'tickets' );
		$replies         = self::table( 'replies' );
		$attachments     = self::table( 'attachments' );
		$api_clients     = self::table( 'api_clients' );

		$sql = array();

		$sql[] = "CREATE TABLE {$districts} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(150) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY name (name)
		) {$charset};";

		$sql[] = "CREATE TABLE {$thanas} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			district_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(150) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY district_id (district_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$routes} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			thana_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(150) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY thana_id (thana_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$service_centers} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			route_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			address VARCHAR(255) DEFAULT NULL,
			contact_phone VARCHAR(30) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY route_id (route_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$products} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			brand VARCHAR(120) NOT NULL,
			model_name VARCHAR(190) NOT NULL,
			category VARCHAR(120) NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY brand (brand),
			KEY category (category)
		) {$charset};";

		$sql[] = "CREATE TABLE {$problem_types} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_category VARCHAR(120) DEFAULT NULL,
			label VARCHAR(190) NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY product_category (product_category)
		) {$charset};";

		$sql[] = "CREATE TABLE {$tickets} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_number VARCHAR(40) NOT NULL,
			customer_name VARCHAR(150) NOT NULL,
			mobile VARCHAR(20) NOT NULL,
			alternative_mobile VARCHAR(20) DEFAULT NULL,
			district_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			thana_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			route_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			service_center_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			address TEXT NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			brand_snapshot VARCHAR(120) NOT NULL DEFAULT '',
			product_name_snapshot VARCHAR(190) NOT NULL DEFAULT '',
			warranty_id VARCHAR(120) DEFAULT NULL,
			problem_type_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			problem_description VARCHAR(500) NOT NULL DEFAULT '',
			comments TEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			priority VARCHAR(10) NOT NULL DEFAULT 'med',
			assigned_agent_id BIGINT(20) UNSIGNED DEFAULT NULL,
			source VARCHAR(10) NOT NULL DEFAULT 'web',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY ticket_number (ticket_number),
			KEY status (status),
			KEY assigned_agent_id (assigned_agent_id),
			KEY district_id (district_id),
			KEY service_center_id (service_center_id),
			KEY created_at (created_at),
			KEY mobile (mobile)
		) {$charset};";

		$sql[] = "CREATE TABLE {$replies} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_id BIGINT(20) UNSIGNED NOT NULL,
			author_id BIGINT(20) UNSIGNED DEFAULT NULL,
			author_type VARCHAR(10) NOT NULL DEFAULT 'agent',
			message TEXT NOT NULL,
			is_internal_note TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY ticket_id (ticket_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$attachments} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_id BIGINT(20) UNSIGNED NOT NULL,
			reply_id BIGINT(20) UNSIGNED DEFAULT NULL,
			file_path VARCHAR(255) NOT NULL,
			original_filename VARCHAR(255) NOT NULL,
			mime_type VARCHAR(100) NOT NULL,
			uploaded_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY ticket_id (ticket_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$api_clients} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			client_name VARCHAR(190) NOT NULL,
			api_key VARCHAR(64) NOT NULL,
			api_secret_hash VARCHAR(255) NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			rate_limit_per_minute INT NOT NULL DEFAULT 60,
			scope VARCHAR(20) NOT NULL DEFAULT 'create_only',
			allowed_ips TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY api_key (api_key)
		) {$charset};";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		update_option( 'wpsd_db_version', WPSD_DB_VERSION );
	}

	/**
	 * Re-run table creation when the schema version changes, so column
	 * additions (e.g. problem_description) reach sites that are already
	 * active — not just fresh activations. dbDelta() is additive/idempotent,
	 * safe to call on every version bump.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'wpsd_db_version' ) !== WPSD_DB_VERSION ) {
			self::migrate_rename_barcode_column();
			self::migrate_product_id_default();
			self::create_tables();
		}
	}

	/**
	 * dbDelta() only adds/alters columns it recognizes by name — a genuine
	 * rename (barcode -> warranty_id) needs an explicit ALTER first, or
	 * dbDelta would just add a new warranty_id column and silently orphan
	 * the old barcode one (and its data). Idempotent: no-ops once run.
	 */
	private static function migrate_rename_barcode_column() {
		global $wpdb;
		$table = self::table( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- existence check before the rename below.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return; // Fresh install — create_tables() will create warranty_id directly.
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time column rename, gated by version.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		if ( in_array( 'barcode', $columns, true ) && ! in_array( 'warranty_id', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time column rename, gated by version.
			$wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `barcode` `warranty_id` VARCHAR(120) DEFAULT NULL" );
		}
	}

	/**
	 * dbDelta() does not reliably alter a DEFAULT on an existing column, so
	 * sites created before product_id became optional would keep it
	 * NOT NULL with no default — breaking inserts (which no longer supply
	 * it) under strict SQL mode. Explicit ALTER, idempotent via information_schema check.
	 */
	private static function migrate_product_id_default() {
		global $wpdb;
		$table = self::table( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- existence check before the alter below.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return; // Fresh install — create_tables() already includes the default.
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time default fix, gated by version.
		$column_default = $wpdb->get_var( $wpdb->prepare( 'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, 'product_id' ) );
		if ( null === $column_default ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time default fix, gated by version.
			$wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `product_id` `product_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0" );
		}
	}

	/**
	 * Seed lookup tables from bundled CSVs when they are empty.
	 * Each CSV has a header row. Import is idempotent (skips non-empty tables).
	 */
	public static function maybe_seed() {
		$seeded = get_option( 'wpsd_seeded_v1', false );
		if ( $seeded ) {
			return;
		}

		$dir = WPSD_PLUGIN_DIR . 'includes/data/';
		self::import_csv_if_empty( 'districts', $dir . 'seed-districts.csv', array( 'name' ) );
		self::import_csv_if_empty( 'thanas', $dir . 'seed-thanas.csv', array( 'district_name', 'name' ), 'import_thana_row' );
		self::import_csv_if_empty( 'routes', $dir . 'seed-routes.csv', array( 'thana_name', 'district_name', 'name' ), 'import_route_row' );
		self::import_csv_if_empty( 'service_centers', $dir . 'seed-service-centers.csv', array( 'route_name', 'thana_name', 'name', 'address', 'contact_phone' ), 'import_center_row' );
		self::import_csv_if_empty( 'products', $dir . 'seed-products.csv', array( 'brand', 'model_name', 'category' ) );
		self::import_csv_if_empty( 'problem_types', $dir . 'seed-problem-types.csv', array( 'product_category', 'label' ) );

		update_option( 'wpsd_seeded_v1', time() );
		WPSD_Lookups::flush_cache();
	}

	/**
	 * Import a CSV file into a table when the table is empty.
	 *
	 * @param string   $table_key Short table key.
	 * @param string   $file      Absolute path to CSV.
	 * @param string[] $columns   Expected header columns.
	 * @param string   $mapper    Optional custom row mapper method on this class.
	 * @return int Number of rows imported.
	 */
	public static function import_csv_if_empty( $table_key, $file, $columns, $mapper = '' ) {
		global $wpdb;
		$table = self::table( $table_key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- DDL-adjacent count check, no user input.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		if ( $count > 0 ) {
			return 0;
		}
		return self::import_csv( $table_key, $file );
	}

	/**
	 * Import a CSV file into a table (used by seeder and admin importer).
	 *
	 * @param string $table_key Short table key.
	 * @param string $file      Absolute path to CSV.
	 * @return int|WP_Error Rows imported or error.
	 */
	public static function import_csv( $table_key, $file ) {
		global $wpdb;

		$allowed = array( 'districts', 'thanas', 'routes', 'service_centers', 'products', 'problem_types' );
		if ( ! in_array( $table_key, $allowed, true ) ) {
			return new WP_Error( 'wpsd_bad_table', __( 'Unsupported lookup table.', 'affiniti-wp-support' ) );
		}
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'wpsd_no_file', __( 'CSV file not found or not readable.', 'affiniti-wp-support' ) );
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local CSV seed, read-only.
		if ( ! $handle ) {
			return new WP_Error( 'wpsd_no_file', __( 'Could not open CSV file.', 'affiniti-wp-support' ) );
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- local file.
			return new WP_Error( 'wpsd_bad_csv', __( 'CSV is empty or invalid.', 'affiniti-wp-support' ) );
		}
		$header = array_map(
			function ( $h ) {
				return strtolower( trim( (string) $h ) );
			},
			$header
		);

		$imported = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $row ) === 1 && trim( (string) $row[0] ) === '' ) {
				continue;
			}
			$assoc = array();
			foreach ( $header as $i => $col ) {
				$assoc[ $col ] = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
			}
			$data = self::map_csv_row( $table_key, $assoc );
			if ( is_wp_error( $data ) || empty( $data ) ) {
				continue;
			}
			$formats = array_fill( 0, count( $data ), '%s' );
			// Numeric FK columns use %d.
			foreach ( array( 'district_id', 'thana_id', 'route_id' ) as $int_col ) {
				$keys = array_keys( $data );
				$pos  = array_search( $int_col, $keys, true );
				if ( false !== $pos ) {
					$formats[ $pos ] = '%d';
				}
			}
			$table = self::table( $table_key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- controlled seed/import insert.
			$ok = $wpdb->insert( $table, $data, $formats );
			if ( false !== $ok ) {
				++$imported;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- local file.

		WPSD_Lookups::flush_cache();
		return $imported;
	}

	/**
	 * Map a CSV assoc row to a DB row, resolving name-based FKs.
	 *
	 * @param string $table_key Short table key.
	 * @param array  $row       Assoc row.
	 * @return array|WP_Error
	 */
	private static function map_csv_row( $table_key, $row ) {
		global $wpdb;
		// Column helper: missing columns become '' (CSV data is not slashed).
		$col = function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) ? sanitize_text_field( $row[ $key ] ) : '';
		};
		switch ( $table_key ) {
			case 'districts':
				if ( '' === $col( 'name' ) ) {
					return new WP_Error( 'wpsd_skip', 'skip' );
				}
				return array( 'name' => $col( 'name' ) );
			case 'thanas':
				$district_id = self::lookup_id_by_name( 'districts', $col( 'district_name' ) );
				if ( ! $district_id || '' === $col( 'name' ) ) {
					return new WP_Error( 'wpsd_skip', 'skip' );
				}
				return array(
					'district_id' => $district_id,
					'name'        => $col( 'name' ),
				);
			case 'routes':
				$thana_id = self::lookup_child_id_by_name( 'thanas', $col( 'thana_name' ), $col( 'district_name' ) );
				if ( ! $thana_id || '' === $col( 'name' ) ) {
					return new WP_Error( 'wpsd_skip', 'skip' );
				}
				return array(
					'thana_id' => $thana_id,
					'name'     => $col( 'name' ),
				);
			case 'service_centers':
				$route_id = self::lookup_route_id_by_name( $col( 'route_name' ), $col( 'thana_name' ) );
				if ( ! $route_id || '' === $col( 'name' ) ) {
					return new WP_Error( 'wpsd_skip', 'skip' );
				}
				return array(
					'route_id'      => $route_id,
					'name'          => $col( 'name' ),
					'address'       => $col( 'address' ),
					'contact_phone' => $col( 'contact_phone' ),
				);
			case 'products':
				if ( '' === $col( 'brand' ) || '' === $col( 'model_name' ) || '' === $col( 'category' ) ) {
					return new WP_Error( 'wpsd_skip', 'skip' );
				}
				return array(
					'brand'      => $col( 'brand' ),
					'model_name' => $col( 'model_name' ),
					'category'   => $col( 'category' ),
				);
			case 'problem_types':
				if ( '' === $col( 'label' ) ) {
					return new WP_Error( 'wpsd_skip', 'skip' );
				}
				$cat = $col( 'product_category' );
				return array(
					'product_category' => '' === $cat ? null : $cat,
					'label'            => $col( 'label' ),
				);
		}
		return new WP_Error( 'wpsd_skip', 'skip' );
	}

	/**
	 * Find a lookup row id by exact name.
	 *
	 * @param string $table_key Short key.
	 * @param string $name      Name to match.
	 * @return int
	 */
	private static function lookup_id_by_name( $table_key, $name ) {
		global $wpdb;
		$table = self::table( $table_key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- internal seed resolution.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE name = %s LIMIT 1", $name ) );
		return (int) $id;
	}

	/**
	 * Find a thana id by name, optionally scoped to a district name.
	 *
	 * @param string $table_key     Always 'thanas'.
	 * @param string $thana_name    Thana name.
	 * @param string $district_name District name scope.
	 * @return int
	 */
	private static function lookup_child_id_by_name( $table_key, $thana_name, $district_name ) {
		global $wpdb;
		$thanas    = self::table( 'thanas' );
		$districts = self::table( 'districts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- internal seed resolution.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT t.id FROM `{$thanas}` t INNER JOIN `{$districts}` d ON d.id = t.district_id WHERE t.name = %s AND d.name = %s LIMIT 1",
				$thana_name,
				$district_name
			)
		);
		return (int) $id;
	}

	/**
	 * Find a route id by name, scoped to thana name.
	 *
	 * @param string $route_name Route name.
	 * @param string $thana_name Thana name.
	 * @return int
	 */
	private static function lookup_route_id_by_name( $route_name, $thana_name ) {
		global $wpdb;
		$routes = self::table( 'routes' );
		$thanas = self::table( 'thanas' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- internal seed resolution.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT r.id FROM `{$routes}` r INNER JOIN `{$thanas}` t ON t.id = r.thana_id WHERE r.name = %s AND t.name = %s LIMIT 1",
				$route_name,
				$thana_name
			)
		);
		return (int) $id;
	}

	/**
	 * Drop all plugin tables (used only by uninstall when the user opts in).
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( array( 'attachments', 'replies', 'tickets', 'api_clients', 'problem_types', 'products', 'service_centers', 'routes', 'thanas', 'districts' ) as $key ) {
			$table = self::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		delete_option( 'wpsd_db_version' );
		delete_option( 'wpsd_seeded_v1' );
	}
}
