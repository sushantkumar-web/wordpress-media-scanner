<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Smscan_Core {
	public $table;
	public $options;
	public $db_version = 3;

	public function __construct() {
		global $wpdb;
		$this->table   = $wpdb->prefix . 'smscan_media_usage';
		$this->options = get_option( 'smscan_settings', $this->get_default_options() );
		
		add_action( 'admin_init', [ $this, 'check_db_version' ] );
	}

	public function get_default_options() {
		return [
			'enable_cron'   => 0,
			'cron_interval' => 'daily',
			'scan_featured' => 1,
			'scan_content'  => 1,
			'scan_meta'     => 0,
			'meta_keys'     => '',
			'delete_confirm'=> 0,
		];
	}

	public function activate() {
		$this->create_table();
		add_option( 'smscan_settings', $this->get_default_options() );
		add_option( 'smscan_db_version', $this->db_version );
	}

		public function create_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$logs_table = $wpdb->prefix . 'smscan_logs';

		$sql1 = "CREATE TABLE {$this->table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			is_used TINYINT(1) DEFAULT 0,
			used_in TEXT DEFAULT NULL,
			last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY is_used (is_used)
		) $charset_collate;";

		$sql2 = "CREATE TABLE {$logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
			duration_seconds INT UNSIGNED DEFAULT 0,
			items_scanned INT UNSIGNED DEFAULT 0,
			unused_found INT UNSIGNED DEFAULT 0,
			status VARCHAR(20) DEFAULT 'completed',
			PRIMARY KEY (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 );
		dbDelta( $sql2 );
	}

	public function check_db_version() {
		$current_db_ver = get_option( 'smscan_db_version', 1 );
		if ( $current_db_ver < $this->db_version ) {
			$this->create_table();
			update_option( 'smscan_db_version', $this->db_version );
		}
	}
}