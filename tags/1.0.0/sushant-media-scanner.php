<?php
/**
 * Plugin Name: Sushant Media Scanner
 * Plugin URI: https://thesushant.in/sushant-media-scanner
 * Description: Scan your Media Library and detect where images are used across posts, pages, featured images, and custom fields. Shows usage status with "where used" links.
 * Version: 1.0.0
 * Author: Sushant
 * Author URI: https://thesushant.in/sushant
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sushant-media-scanner
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access.
}

/**
 * Main class to encapsulate plugin functionality.
 */
class Sushant_Media_Scanner {

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Plugin options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Current database version.
	 *
	 * @var int
	 */
	private $db_version = 2; // Increment when schema changes.

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table   = $wpdb->prefix . 'smscan_media_usage';
		$this->options = get_option( 'smscan_settings', $this->get_default_options() );

		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_uninstall_hook( __FILE__, [ __CLASS__, 'uninstall' ] );

		add_action( 'admin_init', [ $this, 'check_db_version' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_filter( 'manage_upload_columns', [ $this, 'add_media_column' ] );
		add_action( 'manage_media_custom_column', [ $this, 'show_media_column' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'add_media_filter' ] );
		add_filter( 'manage_upload_sortable_columns', [ $this, 'sortable_column' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_media_query' ] );
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_usage_to_attachment_fields' ], 10, 2 );
		add_action( 'wp_ajax_smscan_batch_scan', [ $this, 'ajax_batch_scan' ] );
		add_action( 'admin_action_smscan_delete_unused', [ $this, 'handle_delete_unused' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );

		// Schedule cron if enabled.
		if ( ! empty( $this->options['enable_cron'] ) ) {
			add_action( 'wp', [ $this, 'schedule_cron' ] );
			add_action( 'smscan_daily_scan', [ $this, 'run_scan_cron' ] );
		}
	}

	/**
	 * Default plugin options.
	 *
	 * @return array
	 */
	private function get_default_options() {
		return [
			'enable_cron'        => 0,
			'cron_interval'      => 'daily',
			'scan_featured'      => 1,
			'scan_content'       => 1,
			'scan_meta'          => 0,
			'meta_keys'          => '',
			'delete_confirm'     => 0,
		];
	}

	/**
	 * Activation hook: create table, set default options.
	 */
	public function activate() {
		$this->create_table();
		add_option( 'smscan_settings', $this->get_default_options() );
		add_option( 'smscan_db_version', $this->db_version );
	}

	/**
	 * Uninstall hook: remove table and options.
	 */
	public static function uninstall() {
		global $wpdb;
		$table = $wpdb->prefix . 'smscan_media_usage';
		$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'smscan_settings' );
		delete_option( 'smscan_db_version' );
		
		// Clear cron.
		wp_clear_scheduled_hook( 'smscan_daily_scan' );
	}

	/**
	 * Create or upgrade database table.
	 */
	private function create_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			is_used TINYINT(1) DEFAULT 0,
			used_in TEXT DEFAULT NULL,
			last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY is_used (is_used)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Check if database needs upgrade and run it.
	 */
	public function check_db_version() {
		$current_db_ver = get_option( 'smscan_db_version', 1 );
		if ( $current_db_ver < $this->db_version ) {
			$this->create_table();
			update_option( 'smscan_db_version', $this->db_version );
		}
	}

	/**
	 * Schedule cron event if not already scheduled.
	 */
	public function schedule_cron() {
		if ( ! wp_next_scheduled( 'smscan_daily_scan' ) ) {
			$interval = ! empty( $this->options['cron_interval'] ) ? $this->options['cron_interval'] : 'daily';
			wp_schedule_event( time(), $interval, 'smscan_daily_scan' );
		}
	}

	/**
	 * Run scan via cron.
	 */
	public function run_scan_cron() {
		$this->run_scan( true );
	}

	/**
	 * Add submenu pages.
	 */
	public function admin_menu() {
		// Scan page under Media.
		add_media_page(
			__( 'Scan Media Usage', 'sushant-media-scanner' ),
			__( 'Scan Media Usage', 'sushant-media-scanner' ),
			'manage_options',
			'smscan-scan-media',
			[ $this, 'scan_page' ]
		);

		// Settings page under Settings.
		add_options_page(
			__( 'Media Scanner Settings', 'sushant-media-scanner' ),
			__( 'Media Scanner', 'sushant-media-scanner' ),
			'manage_options',
			'smscan-settings',
			[ $this, 'settings_page' ]
		);
	}

	/**
	 * Render the scan page with AJAX batch processing.
	 */
	public function scan_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'sushant-media-scanner' ) );
		}

		// Get total attachments count.
		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		$total = count( $attachments );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scan Media Usage', 'sushant-media-scanner' ); ?></h1>

			<div id="smscan-scan-progress" style="margin:20px 0;padding:15px;background:#fff;border:1px solid #ccd0d4;">
				<p><strong><?php esc_html_e( 'Scan Progress:', 'sushant-media-scanner' ); ?></strong></p>
				<div style="background:#f1f1f1;height:20px;width:100%;">
					<div id="smscan-progress-bar" style="background:#0073aa;height:20px;width:0%;"></div>
				</div>
				<p id="smscan-status-message"><?php esc_html_e( 'Ready to start.', 'sushant-media-scanner' ); ?></p>
				<button id="smscan-start-scan" class="button button-primary" data-total="<?php echo esc_attr( $total ); ?>">
					<?php esc_html_e( 'Start Scan', 'sushant-media-scanner' ); ?>
				</button>
				<span class="spinner" style="float:none;margin-top:0;"></span>
			</div>

			<p><?php esc_html_e( 'The scan runs in batches to avoid timeouts. You can leave this page – the scan will continue in the background.', 'sushant-media-scanner' ); ?></p>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var total = $('#smscan-start-scan').data('total');
			var processed = 0;
			var nonce = '<?php echo esc_js( wp_create_nonce( 'smscan_batch_scan' ) ); ?>';

			function startBatch(offset) {
				$.post(ajaxurl, {
					action: 'smscan_batch_scan',
					offset: offset,
					nonce: nonce
				}, function(response) {
					if (response.success) {
						processed = response.data.processed;
						var percent = Math.min(100, Math.round((processed / total) * 100));
						$('#smscan-progress-bar').css('width', percent + '%');
						$('#smscan-status-message').text(response.data.message);

						if (response.data.finished) {
							$('#smscan-status-message').text('<?php echo esc_js( __( 'Scan completed!', 'sushant-media-scanner' ) ); ?>');
							$('#smscan-start-scan').prop('disabled', false).text('<?php echo esc_js( __( 'Scan Again', 'sushant-media-scanner' ) ); ?>');
							$('.spinner').removeClass('is-active');
						} else {
							startBatch(response.data.next_offset);
						}
					} else {
						$('#smscan-status-message').text('Error: ' + response.data);
						$('#smscan-start-scan').prop('disabled', false);
						$('.spinner').removeClass('is-active');
					}
				}).fail(function(xhr, status, error) {
					$('#smscan-status-message').text('AJAX error: ' + error);
					$('#smscan-start-scan').prop('disabled', false);
					$('.spinner').removeClass('is-active');
				});
			}

			$('#smscan-start-scan').on('click', function(e) {
				e.preventDefault();
				$(this).prop('disabled', true);
				$('.spinner').addClass('is-active');
				$('#smscan-progress-bar').css('width', '0%');
				$('#smscan-status-message').text('<?php echo esc_js( __( 'Scanning...', 'sushant-media-scanner' ) ); ?>');
				startBatch(0);
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler for batch scanning.
	 */
	public function ajax_batch_scan() {
		check_ajax_referer( 'smscan_batch_scan', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'sushant-media-scanner' ) );
		}

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
			wp_send_json_success( [
				'finished'    => true,
				'processed'   => $offset,
				'message'     => __( 'Scan finished.', 'sushant-media-scanner' ),
				'next_offset' => $offset,
			] );
		}

		foreach ( $attachments as $attachment_id ) {
			$this->scan_single_attachment( $attachment_id );
			global $wpdb;
			if ( ! empty( $wpdb->last_error ) ) {
				wp_send_json_error( 'Database error: ' . $wpdb->last_error );
			}
		}

		$processed = $offset + count( $attachments );
		wp_send_json_success( [
			'finished'    => false,
			'processed'   => $processed,
			'message'     => sprintf( __( 'Processed %d of %d...', 'sushant-media-scanner' ), $processed, $this->get_total_attachments() ),
			'next_offset' => $processed,
		] );
	}

	/**
	 * Get total number of attachments.
	 *
	 * @return int
	 */
	private function get_total_attachments() {
		$count = wp_count_posts( 'attachment' );
		return isset( $count->inherit ) ? (int) $count->inherit : 0;
	}

	/**
	 * Scan a single attachment and update the database.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function scan_single_attachment( $attachment_id ) {
		global $wpdb;

		$is_used      = 0;
		$used_in_list = [];

		$url       = wp_get_attachment_url( $attachment_id );
		$file_path = get_attached_file( $attachment_id );
		$file_name = basename( $file_path );

		// 1. Featured image check.
		if ( ! empty( $this->options['scan_featured'] ) ) {
			$featured = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1",
				$attachment_id
			) );
			if ( $featured ) {
				$is_used        = 1;
				$used_in_list[] = sprintf( 'featured_in_%d', $featured );
			}
		}

		// 2. Post content check (by URL or file name).
		if ( ! $is_used && ! empty( $this->options['scan_content'] ) && $url ) {
			$like_url     = '%' . $wpdb->esc_like( $url ) . '%';
			$content_used = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				 AND post_content LIKE %s
				 LIMIT 1",
				$like_url
			) );
			if ( ! $content_used ) {
				$like_name    = '%' . $wpdb->esc_like( $file_name ) . '%';
				$content_used = $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_status = 'publish'
					 AND post_content LIKE %s
					 LIMIT 1",
					$like_name
				) );
			}
			if ( $content_used ) {
				$is_used        = 1;
				$used_in_list[] = sprintf( 'content_in_%d', $content_used );
			}
		}

		// 3. Post meta check (if enabled).
		if ( ! $is_used && ! empty( $this->options['scan_meta'] ) && ! empty( $this->options['meta_keys'] ) ) {
			$meta_keys = array_map( 'trim', explode( "\n", $this->options['meta_keys'] ) );
			foreach ( $meta_keys as $meta_key ) {
				if ( empty( $meta_key ) ) {
					continue;
				}
				$meta_used = $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = %s
					 AND meta_value = %d
					 LIMIT 1",
					$meta_key,
					$attachment_id
				) );
				if ( $meta_used ) {
					$is_used        = 1;
					$used_in_list[] = sprintf( 'meta_%s_in_%d', $meta_key, $meta_used );
					break;
				}
			}
		}

		$used_in = ! empty( $used_in_list ) ? maybe_serialize( $used_in_list ) : null;

		// Update database.
		$wpdb->replace(
			$this->table,
			[
				'attachment_id' => $attachment_id,
				'is_used'       => $is_used,
				'used_in'       => $used_in,
				'last_scanned'  => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Main scan function (called by AJAX or manually).
	 *
	 * @param bool $cron_mode If true, suppress output and run full scan.
	 */
	public function run_scan( $cron_mode = false ) {
		if ( $cron_mode ) {
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
	}

	/**
	 * Add "Usage Status" column to media library.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_media_column( $columns ) {
		$columns['smscan_usage_status'] = __( 'Usage Status', 'sushant-media-scanner' );
		return $columns;
	}

	/**
	 * Make the usage column sortable.
	 *
	 * @param array $columns Existing sortable columns.
	 * @return array
	 */
	public function sortable_column( $columns ) {
		$columns['smscan_usage_status'] = 'smscan_usage_status';
		return $columns;
	}

	/**
	 * Display content in custom column.
	 *
	 * @param string $column_name Column name.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function show_media_column( $column_name, $attachment_id ) {
		if ( $column_name !== 'smscan_usage_status' ) {
			return;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT is_used, used_in, last_scanned FROM {$this->table} WHERE attachment_id = %d",
			$attachment_id
		) );

		if ( ! $row ) {
			echo '<span style="color:gray;">' . esc_html__( 'Not Scanned', 'sushant-media-scanner' ) . '</span>';
			return;
		}

		$status_text = (int) $row->is_used === 1
			? '<span style="color:green;font-weight:600;">' . esc_html__( 'Used', 'sushant-media-scanner' ) . '</span>'
			: '<span style="color:red;font-weight:600;">' . esc_html__( 'Not Used', 'sushant-media-scanner' ) . '</span>';

		echo wp_kses_post( $status_text ) . '<br>';

		if ( ! empty( $row->used_in ) ) {
			$used_in = maybe_unserialize( $row->used_in );
			if ( is_array( $used_in ) && count( $used_in ) > 0 ) {
				echo '<div style="margin-top:5px;font-size:0.9em;">';
				echo '<strong>' . esc_html__( 'Used in:', 'sushant-media-scanner' ) . '</strong><br>';
				foreach ( $used_in as $item ) {
					$parts = explode( '_in_', $item );
					if ( count( $parts ) === 2 ) {
						$type    = $parts[0];
						$post_id = intval( $parts[1] );
						$post    = get_post( $post_id );
						if ( $post ) {
							$title = get_the_title( $post_id ) ?: __( '(no title)', 'sushant-media-scanner' );
							echo '• ' . esc_html( ucfirst( $type ) ) . ': <a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( $title ) . '</a><br>';
						}
					}
				}
				echo '</div>';
			}
		}

		echo '<small>' . sprintf( esc_html__( 'Scanned: %s', 'sushant-media-scanner' ), esc_html( $row->last_scanned ) ) . '</small>';
	}

	/**
	 * Add filter dropdown to media list.
	 */
	public function add_media_filter() {
		global $pagenow;
		if ( $pagenow !== 'upload.php' ) {
			return;
		}

		$selected = isset( $_GET['smscan_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['smscan_filter'] ) ) : '';
		?>
		<select name="smscan_filter">
			<option value=""><?php esc_html_e( 'All usage statuses', 'sushant-media-scanner' ); ?></option>
			<option value="used" <?php selected( $selected, 'used' ); ?>><?php esc_html_e( 'Used', 'sushant-media-scanner' ); ?></option>
			<option value="not_used" <?php selected( $selected, 'not_used' ); ?>><?php esc_html_e( 'Not Used', 'sushant-media-scanner' ); ?></option>
			<option value="not_scanned" <?php selected( $selected, 'not_scanned' ); ?>><?php esc_html_e( 'Not Scanned', 'sushant-media-scanner' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Filter media query based on dropdown and sorting.
	 *
	 * @param WP_Query $query Query object.
	 */
	public function filter_media_query( $query ) {
		global $pagenow, $wpdb;

		if ( ! is_admin() || $pagenow !== 'upload.php' || ! $query->is_main_query() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( $orderby === 'smscan_usage_status' ) {
			$query->set( 'meta_key', 'smscan_is_used' );
			$query->set( 'orderby', 'meta_value_num' );
			add_filter( 'posts_clauses', [ $this, 'sort_by_usage_clauses' ], 10, 2 );
		}

		if ( empty( $_GET['smscan_filter'] ) ) {
			return;
		}

		$filter = sanitize_text_field( wp_unslash( $_GET['smscan_filter'] ) );
		$table  = $this->table;

		if ( $filter === 'used' ) {
			$ids = $wpdb->get_col( "SELECT attachment_id FROM $table WHERE is_used = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->set( 'post__in', ! empty( $ids ) ? $ids : [ 0 ] );
		} elseif ( $filter === 'not_used' ) {
			$ids = $wpdb->get_col( "SELECT attachment_id FROM $table WHERE is_used = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->set( 'post__in', ! empty( $ids ) ? $ids : [ 0 ] );
		} elseif ( $filter === 'not_scanned' ) {
			$ids = $wpdb->get_col( "SELECT attachment_id FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query->set( 'post__not_in', ! empty( $ids ) ? $ids : [ 0 ] );
		}
	}

	/**
	 * Modify posts clauses for sorting by usage status.
	 *
	 * @param array    $clauses Query clauses.
	 * @param WP_Query $query Query object.
	 * @return array
	 */
	public function sort_by_usage_clauses( $clauses, $query ) {
		global $wpdb;

		$clauses['join']    .= " LEFT JOIN {$this->table} smscan_tbl ON {$wpdb->posts}.ID = smscan_tbl.attachment_id";
		$clauses['orderby']  = "IFNULL(smscan_tbl.is_used, -1) " . $query->get( 'order' ) . ", " . $clauses['orderby'];

		remove_filter( 'posts_clauses', [ $this, 'sort_by_usage_clauses' ] );

		return $clauses;
	}

	/**
	 * Show usage status in media modal.
	 *
	 * @param array  $form_fields Form fields.
	 * @param object $post Post object.
	 * @return array
	 */
	public function add_usage_to_attachment_fields( $form_fields, $post ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT is_used, used_in, last_scanned FROM {$this->table} WHERE attachment_id = %d",
			$post->ID
		) );

		if ( ! $row ) {
			$status = '<span style="color:gray;">' . esc_html__( 'Not Scanned', 'sushant-media-scanner' ) . '</span>';
		} elseif ( (int) $row->is_used === 1 ) {
			$status = '<span style="color:green;font-weight:600;">' . esc_html__( 'Used', 'sushant-media-scanner' ) . '</span>';
		} else {
			$status = '<span style="color:red;font-weight:600;">' . esc_html__( 'Not Used', 'sushant-media-scanner' ) . '</span>';
		}

		if ( ! empty( $row->used_in ) ) {
			$used_in = maybe_unserialize( $row->used_in );
			if ( is_array( $used_in ) ) {
				$status .= '<br><br><strong>' . esc_html__( 'Used in:', 'sushant-media-scanner' ) . '</strong><br>';
				foreach ( $used_in as $item ) {
					$parts = explode( '_in_', $item );
					if ( count( $parts ) === 2 ) {
						$post_id  = intval( $parts[1] );
						$post_obj = get_post( $post_id );
						if ( $post_obj ) {
							$status .= '• <a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a><br>';
						}
					}
				}
			}
		}

		$form_fields['smscan_usage_status'] = [
			'label' => __( 'Usage Status', 'sushant-media-scanner' ),
			'input' => 'html',
			'html'  => $status,
		];

		return $form_fields;
	}

	/**
	 * Settings page HTML.
	 */
	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'sushant-media-scanner' ) );
		}

		if ( isset( $_POST['submit'] ) && check_admin_referer( 'smscan_save_settings' ) ) {
			$this->options = [
				'enable_cron'   => isset( $_POST['enable_cron'] ) ? 1 : 0,
				'cron_interval' => sanitize_text_field( wp_unslash( $_POST['cron_interval'] ) ),
				'scan_featured' => isset( $_POST['scan_featured'] ) ? 1 : 0,
				'scan_content'  => isset( $_POST['scan_content'] ) ? 1 : 0,
				'scan_meta'     => isset( $_POST['scan_meta'] ) ? 1 : 0,
				'meta_keys'     => sanitize_textarea_field( wp_unslash( $_POST['meta_keys'] ) ),
			];
			update_option( 'smscan_settings', $this->options );

			if ( $this->options['enable_cron'] ) {
				wp_clear_scheduled_hook( 'smscan_daily_scan' );
				wp_schedule_event( time(), $this->options['cron_interval'], 'smscan_daily_scan' );
			} else {
				wp_clear_scheduled_hook( 'smscan_daily_scan' );
			}

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'sushant-media-scanner' ) . '</p></div>';
		}

		$intervals = [
			'hourly'     => __( 'Hourly', 'sushant-media-scanner' ),
			'twicedaily' => __( 'Twice Daily', 'sushant-media-scanner' ),
			'daily'      => __( 'Daily', 'sushant-media-scanner' ),
		];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Media Scanner Settings', 'sushant-media-scanner' ); ?></h1>

			<form method="post">
				<?php wp_nonce_field( 'smscan_save_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic Scanning', 'sushant-media-scanner' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enable_cron" value="1" <?php checked( $this->options['enable_cron'] ); ?>>
								<?php esc_html_e( 'Enable automatic scan via WP-Cron', 'sushant-media-scanner' ); ?>
							</label>
							<br>
							<select name="cron_interval">
								<?php foreach ( $intervals as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->options['cron_interval'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'How often should the scan run automatically?', 'sushant-media-scanner' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Scan Options', 'sushant-media-scanner' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="scan_featured" value="1" <?php checked( $this->options['scan_featured'] ); ?>>
								<?php esc_html_e( 'Check featured images', 'sushant-media-scanner' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="scan_content" value="1" <?php checked( $this->options['scan_content'] ); ?>>
								<?php esc_html_e( 'Check post content (by URL or file name)', 'sushant-media-scanner' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="scan_meta" value="1" <?php checked( $this->options['scan_meta'] ); ?>>
								<?php esc_html_e( 'Check post meta (enter meta keys below)', 'sushant-media-scanner' ); ?>
							</label>
							<br>
							<textarea name="meta_keys" rows="4" cols="50" class="large-text code" placeholder="<?php esc_attr_e( 'e.g. _product_image_id', 'sushant-media-scanner' ); ?>"><?php echo esc_textarea( $this->options['meta_keys'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One meta key per line. The plugin will check if the attachment ID appears as the value of these keys.', 'sushant-media-scanner' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Delete Unused Media', 'sushant-media-scanner' ); ?></h2>
			<p><?php esc_html_e( 'This will move all unused media files to the Trash. You can restore them from Media → Trash if needed.', 'sushant-media-scanner' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=smscan_delete_unused' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to trash all unused media? This can be restored from Media → Trash.', 'sushant-media-scanner' ) ); ?>');">
				<?php wp_nonce_field( 'smscan_delete_unused_action', 'smscan_delete_nonce' ); ?>
				<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Move Unused to Trash', 'sushant-media-scanner' ); ?>">
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the "Delete Unused Media" action.
	 */
	public function handle_delete_unused() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'sushant-media-scanner' ) );
		}

		if ( ! isset( $_POST['smscan_delete_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['smscan_delete_nonce'] ) ), 'smscan_delete_unused_action' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'sushant-media-scanner' ) );
		}

		global $wpdb;
		$unused_ids = $wpdb->get_col( "SELECT attachment_id FROM {$this->table} WHERE is_used = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$deleted = 0;
		foreach ( $unused_ids as $id ) {
			if ( get_post_type( $id ) === 'attachment' ) {
				if ( wp_trash_post( $id ) ) {
					$deleted++;
				}
			}
		}

		$redirect = add_query_arg(
			[ 'deleted' => $deleted ],
			admin_url( 'options-general.php?page=smscan-settings' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show admin notices after delete action.
	 */
	public function admin_notices() {
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'smscan-settings' && isset( $_GET['deleted'] ) ) {
			$count = intval( $_GET['deleted'] );
			echo '<div class="updated"><p>' . sprintf( esc_html__( 'Moved %d unused media files to Trash.', 'sushant-media-scanner' ), $count ) . '</p></div>';
		}
	}
}

// Initialize the plugin.
new Sushant_Media_Scanner();
