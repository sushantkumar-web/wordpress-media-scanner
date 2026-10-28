<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Smscan_Scanner {
	private $core;

	public function __construct( $core ) {
		$this->core = $core;
		// This is the missing piece that makes the Cron work!
		add_action( 'smscan_daily_scan', [ $this, 'run_scan_cron' ] );
	}

	public function run_scan_cron() {
		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		foreach ( $attachments as $attachment_id ) {
			$this->scan_single_attachment( $attachment_id );
		}
	}

	public function get_total_attachments() {
		$count = wp_count_posts( 'attachment' );
		return isset( $count->inherit ) ? (int) $count->inherit : 0;
	}

	public function scan_single_attachment( $attachment_id ) {
		global $wpdb;
		$is_used = 0;
		$used_in_list = [];
		$url = wp_get_attachment_url( $attachment_id );
		$file_path = get_attached_file( $attachment_id );
		$file_name = basename( $file_path );

		// 1. Featured image check
		if ( ! empty( $this->core->options['scan_featured'] ) ) {
			$featured_posts = $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 10",
				$attachment_id
			) );
			foreach ( $featured_posts as $post_id ) {
				$is_used = 1;
				$used_in_list[] = sprintf( 'featured_in_%d', $post_id );
			}
		}

		// 2. Elementor check
		$elementor_posts = $this->find_in_elementor_data( $attachment_id );
		if ( ! empty( $elementor_posts ) ) {
			$is_used = 1;
			foreach ( $elementor_posts as $post_id ) {
				$used_in_list[] = sprintf( 'elementor_in_%d', $post_id );
			}
		}

		// 3. Post content check
		if ( ! empty( $this->core->options['scan_content'] ) && $url ) {
			$like_url = '%' . $wpdb->esc_like( $url ) . '%';
			$content_posts = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish', 'draft', 'private', 'future') AND post_content LIKE %s LIMIT 10",
				$like_url
			) );
			
			if ( empty( $content_posts ) ) {
				$like_name = '%' . $wpdb->esc_like( $file_name ) . '%';
				$content_posts = $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish', 'draft', 'private', 'future') AND post_content LIKE %s LIMIT 10",
					$like_name
				) );
			}
			
			foreach ( $content_posts as $post_id ) {
				$is_used = 1;
				$used_in_list[] = sprintf( 'content_in_%d', $post_id );
			}
		}

		// 4. Meta check
		if ( ! empty( $this->core->options['scan_meta'] ) && ! empty( $this->core->options['meta_keys'] ) ) {
			$meta_keys = array_map( 'trim', explode( "\n", $this->core->options['meta_keys'] ) );
			foreach ( $meta_keys as $meta_key ) {
				if ( empty( $meta_key ) ) continue;
				$meta_posts = $wpdb->get_col( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d LIMIT 10",
					$meta_key, $attachment_id
				) );
				foreach ( $meta_posts as $post_id ) {
					$is_used = 1;
					$used_in_list[] = sprintf( 'meta_%s_in_%d', $meta_key, $post_id );
				}
			}
		}

		$used_in = ! empty( $used_in_list ) ? maybe_serialize( $used_in_list ) : null;

		$wpdb->replace(
			$this->core->table,
			[
				'attachment_id' => $attachment_id,
				'is_used'       => $is_used,
				'used_in'       => $used_in,
				'last_scanned'  => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s' ]
		);
	}

	private function find_in_elementor_data( $attachment_id ) {
		global $wpdb;
		$found_posts = [];
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) return $found_posts;

		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s LIMIT 10",
			'%' . (int)$attachment_id . '%'
		) );

		foreach ( $candidates as $row ) {
			$data = json_decode( $row->meta_value, true );
			if ( is_array( $data ) && $this->elementor_data_contains_id( $data, (int)$attachment_id ) ) {
				$found_posts[] = (int) $row->post_id;
			}
		}
		return $found_posts;
	}

	private function elementor_data_contains_id( $data, $attachment_id ) {
		foreach ( $data as $value ) {
			if ( is_numeric( $value ) && (int) $value === $attachment_id ) return true;
			if ( is_array( $value ) && $this->elementor_data_contains_id( $value, $attachment_id ) ) return true;
		}
		return false;
	}
}