<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Smscan_Ajax {
	private $core;
	private $scanner;

	public function __construct( $core, $scanner ) {
		$this->core = $core;
		$this->scanner = $scanner;
		add_action( 'wp_ajax_smscan_batch_scan', [ $this, 'ajax_batch_scan' ] );
		add_action( 'wp_ajax_smscan_scan_single', [ $this, 'ajax_scan_single' ] );
	}

	public function ajax_batch_scan() {
		check_ajax_referer( 'smscan_batch_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Insufficient permissions.', 'sushant-media-scanner' ) );

		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$batch  = apply_filters( 'smscan_batch_size', 50 );

		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $batch,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

				if ( empty( $attachments ) ) {
			// Save the log!
			global $wpdb;
			$logs_table = $wpdb->prefix . 'smscan_logs';
			
			$start_time = isset( $_POST['start_time'] ) ? intval( $_POST['start_time'] ) : time();
			$duration = time() - $start_time;
			
			$items_scanned = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table}" );
			$unused_found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table} WHERE is_used = 0" );

			$wpdb->insert(
				$logs_table,
				[
					'duration_seconds' => $duration,
					'items_scanned'    => $items_scanned,
					'unused_found'     => $unused_found,
					'status'           => 'completed'
				],
				[ '%d', '%d', '%d', '%s' ]
			);

			// Keep only last 10 logs
			$wpdb->query( "DELETE FROM {$logs_table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$logs_table} ORDER BY id DESC LIMIT 10) tmp)" );

			wp_send_json_success( [
				'finished'    => true,
				'processed'   => $offset,
				'message'     => __( 'Scan finished.', 'sushant-media-scanner' ),
				'next_offset' => $offset,
			] );
		}

		foreach ( $attachments as $attachment_id ) {
			$this->scanner->scan_single_attachment( $attachment_id );
		}

		$processed = $offset + count( $attachments );
		wp_send_json_success( [
			'finished'    => false,
			'processed'   => $processed,
			'message'     => sprintf( __( 'Processed %1$d of %2$d...', 'sushant-media-scanner' ), $processed, $this->scanner->get_total_attachments() ),
			'next_offset' => $processed,
		] );
	}

	public function ajax_scan_single() {
		$id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
		check_admin_referer( 'smscan_scan_single_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$this->scanner->scan_single_attachment( $id );
		
		wp_safe_redirect( wp_get_referer() );
		exit;
	}
}