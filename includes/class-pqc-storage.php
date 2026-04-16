<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Storage {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . PQC_TABLE;
	}

	public static function activate() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			customer_name VARCHAR(255) NULL,
			customer_email VARCHAR(255) NULL,
			customer_notes TEXT NULL,
			system_prompt LONGTEXT NOT NULL,
			model VARCHAR(100) NOT NULL,
			file_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			files_json LONGTEXT NULL,
			response LONGTEXT NULL,
			usage_json TEXT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'pending',
			error_message TEXT NULL,
			ip_address VARCHAR(64) NULL,
			emailed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY customer_email (customer_email),
			KEY created_at (created_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::ensure_upload_dir();
	}

	public static function ensure_upload_dir() {
		$dir = self::upload_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n" );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	public static function upload_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'pool-quote-compare';
	}

	public static function submission_dir( $submission_id ) {
		$dir = self::upload_dir() . '/' . (int) $submission_id;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function insert( array $data ) {
		global $wpdb;
		$wpdb->insert( self::table_name(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		return $wpdb->update( self::table_name(), $data, [ 'id' => (int) $id ] );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ) );
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT $limit" );
	}
}
