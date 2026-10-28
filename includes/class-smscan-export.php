<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Smscan_Export {
	private $core;

	public function __construct( $core ) {
		$this->core = $core;
		add_action( 'admin_action_smscan_export_csv', [ $this, 'handle_export_csv' ] );
	}

	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		check_admin_referer( 'smscan_export_csv' );

		if ( ob_get_level() > 0 ) ob_end_clean();
		if ( headers_sent() ) wp_die( 'Headers already sent. Cannot export CSV.' );

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT attachment_id, is_used, used_in, last_scanned FROM {$this->core->table} ORDER BY is_used ASC, attachment_id ASC" );

		$filename = 'media-usage-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv( $output, [ 'Attachment ID', 'File Name', 'URL', 'Status', 'Used In', 'Last Scanned' ], ',', '"', '' );

		foreach ( $rows as $row ) {
			$attachment_id = (int) $row->attachment_id;
			$file_path     = get_attached_file( $attachment_id );
			$file_name     = $file_path ? basename( $file_path ) : '';
			$url           = wp_get_attachment_url( $attachment_id );
			$status        = (int) $row->is_used === 1 ? 'Used' : 'Not Used';

			$used_in_str = '';
			if ( ! empty( $row->used_in ) ) {
				$used_in = maybe_unserialize( $row->used_in );
				if ( is_array( $used_in ) ) {
					$labels = [];
					foreach ( $used_in as $item ) {
						$parts = explode( '_in_', $item );
						if ( count( $parts ) === 2 ) {
							$title = get_the_title( (int)$parts[1] );
							if ( $title ) $labels[] = $title;
						}
					}
					$used_in_str = implode( ' | ', $labels );
				}
			}

			fputcsv( $output, [ $attachment_id, $file_name, $url, $status, $used_in_str, $row->last_scanned ], ',', '"', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
fclose( $output );
		exit;
	}
}