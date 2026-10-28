<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Smscan_Admin {
	private $core;
	private $scanner;

	public function __construct( $core, $scanner ) {
		$this->core = $core;
		$this->scanner = $scanner;
		
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'manage_upload_columns', [ $this, 'add_media_column' ] );
		add_action( 'manage_media_custom_column', [ $this, 'show_media_column' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'add_media_filter' ] );
		add_filter( 'manage_upload_sortable_columns', [ $this, 'sortable_column' ] );
		add_filter( 'media_row_actions', [ $this, 'add_scan_row_action' ], 10, 2 );
		add_action( 'pre_get_posts', [ $this, 'filter_media_query' ] );
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_usage_to_attachment_fields' ], 10, 2 );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
		add_action( 'admin_action_smscan_delete_unused', [ $this, 'handle_delete_unused' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		add_filter( 'admin_footer_text', [ $this, 'admin_footer_text' ] );
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'smscan' ) !== false || $hook === 'settings_page_smscan-settings' ) {
			wp_enqueue_style( 'smscan-admin', SMSCAN_URL . 'assets/css/admin.css', [], SMSCAN_VERSION );
		}
	}

	public function admin_menu() {
		add_media_page( __( 'Scan Media Usage', 'sushant-media-scanner' ), __( 'Scan Media Usage', 'sushant-media-scanner' ), 'manage_options', 'smscan-scan-media', [ $this, 'scan_page' ] );
		add_options_page( __( 'Media Scanner Settings', 'sushant-media-scanner' ), __( 'Media Scanner', 'sushant-media-scanner' ), 'manage_options', 'smscan-settings', [ $this, 'settings_page' ] );
	}

	public function add_scan_row_action( $actions, $post ) {
		if ( $post->post_type !== 'attachment' || ! current_user_can( 'manage_options' ) ) return $actions;
		$url = wp_nonce_url( admin_url( 'admin-ajax.php?action=smscan_scan_single&id=' . $post->ID ), 'smscan_scan_single_' . $post->ID );
		$actions['smscan_scan'] = '<a href="' . esc_url( $url ) . '" class="smscan-scan-single" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Scan Now', 'sushant-media-scanner' ) . '</a>';
		return $actions;
	}

	public function add_media_column( $columns ) {
		$columns['smscan_usage_status'] = __( 'Usage Status', 'sushant-media-scanner' );
		return $columns;
	}

	public function sortable_column( $columns ) {
		$columns['smscan_usage_status'] = 'smscan_usage_status';
		return $columns;
	}

	public function show_media_column( $column_name, $attachment_id ) {
		if ( $column_name !== 'smscan_usage_status' ) return;
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT is_used, used_in, last_scanned FROM {$this->core->table} WHERE attachment_id = %d", $attachment_id ) );

		if ( ! $row ) {
			echo '<span style="color:gray;">' . esc_html__( 'Not Scanned', 'sushant-media-scanner' ) . '</span>';
			return;
		}

		$status_text = (int) $row->is_used === 1 ? '<span style="color:#00a32a;font-weight:600;">Used</span>' : '<span style="color:#d63638;font-weight:600;">Not Used</span>';
		echo wp_kses_post( $status_text ) . '<br>';

		if ( ! empty( $row->used_in ) ) {
			$used_in = maybe_unserialize( $row->used_in );
			if ( is_array( $used_in ) && count( $used_in ) > 0 ) {
				echo '<div style="margin-top:8px;font-size:12px;line-height:1.5;">';
				$grouped = [ 'featured' => [], 'elementor' => [], 'content' => [], 'meta' => [] ];
				foreach ( $used_in as $item ) {
					$parts = explode( '_in_', $item );
					if ( count( $parts ) === 2 ) {
						$type = $parts[0];
						$post_id = intval( $parts[1] );
						if ( strpos( $type, 'meta_' ) === 0 ) $type = 'meta';
						if ( isset( $grouped[ $type ] ) ) $grouped[ $type ][] = $post_id;
					}
				}

				foreach ( $grouped as $type => $post_ids ) {
					if ( empty( $post_ids ) ) continue;
					$label = 'Content';
					if ( $type === 'featured' ) { $label = 'Featured'; }
					if ( $type === 'elementor' ) { $label = 'Elementor'; }
					if ( $type === 'meta' ) { $label = 'Meta'; }

					echo '<div style="margin-bottom:4px;"><strong style="color:#64748b;">' . esc_html( $label ) . ':</strong> ';
					$links = [];
					foreach ( $post_ids as $post_id ) {
						$title = get_the_title( $post_id ) ?: '(no title)';
						$links[] = '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( $title ) . '</a>';
					}
					echo wp_kses_post( implode( ', ', $links ) ) . '</div>';
				}
				echo '</div>';
			}
		}
		echo '<script>jQuery(document).ready(function($){ $(".smscan-scan-single").on("click", function(e){ var link=$(this); link.text("Scanning..."); $.get(link.attr("href"), function(){ window.location.reload(); }); return false; }); });</script>';
	}

	public function scan_page() {
		global $wpdb;
		$total = $this->scanner->get_total_attachments();
		$used = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table} WHERE is_used = 1" );
		$unused = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table} WHERE is_used = 0" );
		
		$logs_table = $wpdb->prefix . 'smscan_logs';
		$logs = $wpdb->get_results( "SHOW TABLES LIKE '{$logs_table}'" ) ? $wpdb->get_results( "SELECT * FROM {$logs_table} ORDER BY id DESC LIMIT 10" ) : [];
		?>
		<div class="wrap smscan-wrap">
			<div class="smscan-header">
				<h1>Media Scanner Dashboard</h1>
			</div>

			<div class="smscan-grid">
				<div class="smscan-card">
					<h3 class="smscan-card-title">Total Media</h3>
					<p class="smscan-card-value"><?php echo number_format($total); ?></p>
				</div>
				<div class="smscan-card">
					<h3 class="smscan-card-title">Used</h3>
					<p class="smscan-card-value used"><?php echo number_format($used); ?></p>
				</div>
				<div class="smscan-card">
					<h3 class="smscan-card-title">Unused</h3>
					<p class="smscan-card-value unused"><?php echo number_format($unused); ?></p>
				</div>
			</div>

			<div class="smscan-section">
				<h2>Run a New Scan</h2>
				<p style="color:#64748b; font-size:14px;">The scan runs in batches to avoid timeouts. You can leave this page—it will continue in the background.</p>
				
				<div class="smscan-progress-track">
					<div id="smscan-progress-bar" class="smscan-progress-fill"></div>
				</div>
				
				<p id="smscan-status-message" class="smscan-status">Ready to start.</p>
				
				<div class="smscan-btn-group">
					<button id="smscan-start-scan" class="smscan-btn" data-total="<?php echo esc_attr( $total ); ?>">
						Start Scan
					</button>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=smscan_export_csv' ), 'smscan_export_csv' ) ); ?>" class="smscan-btn smscan-btn-secondary">
						Export CSV
					</a>
					<span class="spinner" style="float:none; margin:0;"></span>
				</div>
			</div>

			<div class="smscan-section">
				<h2>Recent Scan Logs (Last 10)</h2>
				<table class="smscan-table">
					<thead>
						<tr>
							<th>Date</th>
							<th>Time Taken</th>
							<th>Items Scanned</th>
							<th>Unused Found</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty($logs) ) : ?>
							<tr><td colspan="5" style="text-align:center; color:#64748b;">No scans run yet.</td></tr>
						<?php else : ?>
							<?php foreach ( $logs as $log ) : ?>
								<tr>
									<td><?php echo esc_html( $log->start_time ); ?></td>
									<td><?php echo esc_html( $log->duration_seconds ); ?> sec</td>
									<td><?php echo esc_html( $log->items_scanned ); ?></td>
									<td><?php echo esc_html( $log->unused_found ); ?></td>
									<td><span class="smscan-badge"><?php echo esc_html( ucfirst($log->status) ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var total = $('#smscan-start-scan').data('total');
			var nonce = '<?php echo esc_js( wp_create_nonce( 'smscan_batch_scan' ) ); ?>';
			var startTime = 0;

			function startBatch(offset) {
				$.post(ajaxurl, { action: 'smscan_batch_scan', offset: offset, nonce: nonce, start_time: startTime }, function(response) {
					if (response.success) {
						var percent = Math.min(100, Math.round((response.data.processed / total) * 100));
						$('#smscan-progress-bar').css('width', percent + '%');
						$('#smscan-status-message').text(response.data.message);
						
						if (response.data.finished) {
							$('#smscan-status-message').text('Scan completed successfully.').css('color', '#00a32a');
							$('#smscan-start-scan').prop('disabled', false).text('Scan Again');
							$('.spinner').removeClass('is-active');
							setTimeout(function(){ window.location.reload(); }, 1500);
						} else { 
							startBatch(response.data.next_offset); 
						}
					} else {
						$('#smscan-status-message').text('Error: ' + response.data).css('color', '#d63638');
						$('#smscan-start-scan').prop('disabled', false);
						$('.spinner').removeClass('is-active');
					}
				});
			}
			
			$('#smscan-start-scan').on('click', function(e) {
				e.preventDefault(); 
				$(this).prop('disabled', true); 
				$('.spinner').addClass('is-active');
				$('#smscan-progress-bar').css('width', '0%'); 
				$('#smscan-status-message').text('Scanning...').css('color', '#3c434a'); 
				startTime = Math.floor(Date.now() / 1000);
				startBatch(0);
			});
		});
		</script>
		<?php
	}

	public function add_media_filter() {
		global $pagenow;
		if ( $pagenow !== 'upload.php' ) return;
		$selected = isset( $_GET['smscan_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['smscan_filter'] ) ) : '';
		?>
		<select name="smscan_filter">
			<option value="">All usage statuses</option>
			<option value="used" <?php selected( $selected, 'used' ); ?>>Used</option>
			<option value="not_used" <?php selected( $selected, 'not_used' ); ?>>Not Used</option>
			<option value="not_scanned" <?php selected( $selected, 'not_scanned' ); ?>>Not Scanned</option>
		</select>
		<?php
	}

	public function filter_media_query( $query ) {
		global $pagenow, $wpdb;
		if ( ! is_admin() || $pagenow !== 'upload.php' || ! $query->is_main_query() ) return;
		
		if ( $query->get( 'orderby' ) === 'smscan_usage_status' ) {
			add_filter( 'posts_clauses', [ $this, 'sort_by_usage_clauses' ], 10, 2 );
		}
		if ( empty( $_GET['smscan_filter'] ) ) return;
		
		$filter = sanitize_text_field( wp_unslash( $_GET['smscan_filter'] ) );
		$table  = $this->core->table;

		if ( $filter === 'used' ) {
			$ids = $wpdb->get_col( "SELECT attachment_id FROM $table WHERE is_used = 1" );
			$query->set( 'post__in', ! empty( $ids ) ? $ids : [ 0 ] );
		} elseif ( $filter === 'not_used' ) {
			$ids = $wpdb->get_col( "SELECT attachment_id FROM $table WHERE is_used = 0" );
			$query->set( 'post__in', ! empty( $ids ) ? $ids : [ 0 ] );
		} elseif ( $filter === 'not_scanned' ) {
			$ids = $wpdb->get_col( "SELECT attachment_id FROM $table" );
			$query->set( 'post__not_in', ! empty( $ids ) ? $ids : [ 0 ] );
		}
	}

	public function sort_by_usage_clauses( $clauses, $query ) {
		global $wpdb;
		$clauses['join'] .= " LEFT JOIN {$this->core->table} smscan_tbl ON {$wpdb->posts}.ID = smscan_tbl.attachment_id";
		$clauses['orderby'] = "IFNULL(smscan_tbl.is_used, -1) " . $query->get( 'order' ) . ", " . $clauses['orderby'];
		remove_filter( 'posts_clauses', [ $this, 'sort_by_usage_clauses' ] );
		return $clauses;
	}

	public function add_usage_to_attachment_fields( $form_fields, $post ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT is_used, used_in, last_scanned FROM {$this->core->table} WHERE attachment_id = %d", $post->ID ) );
		$status = '';
		if ( ! $row ) {
			$status = '<span style="color:gray;">Not Scanned</span>';
		} elseif ( (int) $row->is_used === 1 ) {
			$status = '<span style="color:#00a32a;font-weight:600;">Used</span>';
		} else {
			$status = '<span style="color:#d63638;font-weight:600;">Not Used</span>';
		}
		$form_fields['smscan_usage_status'] = [ 'label' => 'Usage Status', 'input' => 'html', 'html' => $status ];
		return $form_fields;
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		if ( isset( $_POST['submit'] ) && check_admin_referer( 'smscan_save_settings' ) ) {
			$this->core->options = [
				'enable_cron'   => isset( $_POST['enable_cron'] ) ? 1 : 0,
				'cron_interval' => sanitize_text_field( wp_unslash( $_POST['cron_interval'] ) ),
				'scan_featured' => isset( $_POST['scan_featured'] ) ? 1 : 0,
				'scan_content'  => isset( $_POST['scan_content'] ) ? 1 : 0,
				'scan_meta'     => isset( $_POST['scan_meta'] ) ? 1 : 0,
				'meta_keys'     => sanitize_textarea_field( wp_unslash( $_POST['meta_keys'] ) ),
			];
			update_option( 'smscan_settings', $this->core->options );
			
			// Handle Telemetry Toggle
			$telemetry_choice = isset( $_POST['enable_telemetry'] ) ? 'yes' : 'no';
			update_option( 'smscan_telemetry_choice', $telemetry_choice );
			
			if ( $telemetry_choice === 'yes' ) {
				if ( ! wp_next_scheduled( 'smscan_weekly_telemetry' ) ) {
					wp_schedule_event( time(), 'weekly', 'smscan_weekly_telemetry' );
				}
			} else {
				wp_clear_scheduled_hook( 'smscan_weekly_telemetry' );
			}

			if ( $this->core->options['enable_cron'] ) {
				wp_clear_scheduled_hook( 'smscan_daily_scan' );
				wp_schedule_event( time(), $this->core->options['cron_interval'], 'smscan_daily_scan' );
			} else {
				wp_clear_scheduled_hook( 'smscan_daily_scan' );
			}
			
			echo '<div class="updated"><p>Settings saved.</p></div>';
		}
		
		$intervals = [
			'hourly'     => 'Hourly',
			'twicedaily' => 'Twice Daily',
			'daily'      => 'Daily',
		];
		?>
		<div class="wrap smscan-wrap">
			<div class="smscan-header">
				<h1>Media Scanner Settings</h1>
			</div>
			<form method="post">
				<?php wp_nonce_field( 'smscan_save_settings' ); ?>
				<div class="smscan-section">
					<table class="form-table">
						<tr>
							<th scope="row">Automatic Scanning</th>
							<td>
								<label>
									<input type="checkbox" name="enable_cron" value="1" <?php checked( $this->core->options['enable_cron'] ); ?>>
									Enable automatic scan via WP-Cron
								</label>
								<br><br>
								<select name="cron_interval">
									<?php foreach ( $intervals as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->core->options['cron_interval'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">How often should the scan run automatically?</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Scan Options</th>
							<td>
								<label><input type="checkbox" name="scan_featured" value="1" <?php checked( $this->core->options['scan_featured'] ); ?>> Check featured images</label><br>
								<label><input type="checkbox" name="scan_content" value="1" <?php checked( $this->core->options['scan_content'] ); ?>> Check post content (by URL or file name)</label><br>
								<label><input type="checkbox" name="scan_meta" value="1" <?php checked( $this->core->options['scan_meta'] ); ?>> Check post meta (enter meta keys below)</label><br>
								<textarea name="meta_keys" rows="4" cols="50" class="large-text code" placeholder="e.g. _product_image_id" style="margin-top:10px;"><?php echo esc_textarea( $this->core->options['meta_keys'] ); ?></textarea>
								<p class="description">One meta key per line. The plugin will check if the attachment ID appears as the value of these keys.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Privacy & Telemetry</th>
							<td>
								<label>
									<input type="checkbox" name="enable_telemetry" value="yes" <?php checked( get_option( 'smscan_telemetry_choice' ), 'yes' ); ?>>
									Allow anonymous usage tracking
								</label>
								<p class="description">Sends anonymous data (WP version, active page builders, media count) once a week to help us improve the plugin.</p>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
				</div>
			</form>

			<div class="smscan-section" style="margin-top:30px; border-color:#fbc5c5;">
				<h2 style="color:#d63638;">Delete Unused Media</h2>
				<p style="color:#64748b;">This will move all unused media files to the Trash. You can restore them from Media > Trash if needed.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=smscan_delete_unused' ) ); ?>" onsubmit="return confirm('Are you sure you want to trash all unused media?');">
					<?php wp_nonce_field( 'smscan_delete_unused_action', 'smscan_delete_nonce' ); ?>
					<input type="submit" class="button button-secondary" value="Move Unused to Trash" style="border-color:#d63638; color:#d63638;">
				</form>
			</div>
		</div>
		<?php
	}

	public function handle_delete_unused() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		if ( ! isset( $_POST['smscan_delete_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['smscan_delete_nonce'] ) ), 'smscan_delete_unused_action' ) ) {
			wp_die( 'Invalid nonce.' );
		}

		global $wpdb;
		$unused_ids = $wpdb->get_col( "SELECT attachment_id FROM {$this->core->table} WHERE is_used = 0" );

		$deleted = 0;
		foreach ( $unused_ids as $id ) {
			if ( get_post_type( $id ) === 'attachment' ) {
				if ( wp_trash_post( $id ) ) $deleted++;
			}
		}

		$redirect = add_query_arg( [ 'deleted' => $deleted ], admin_url( 'options-general.php?page=smscan-settings' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function admin_notices() {
		// Delete unused notice
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'smscan-settings' && isset( $_GET['deleted'] ) ) {
			$count = intval( $_GET['deleted'] );
			echo '<div class="updated"><p>Moved ' . (int) $count . ' unused media files to Trash.</p></div>';
		}

		// Telemetry test success notice
		if ( isset( $_GET['smscan_test_result'] ) && $_GET['smscan_test_result'] == '200' ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Anonymous tracking is now enabled. Thank you for helping us improve the plugin.</p></div>';
		}
	}

	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		wp_add_dashboard_widget( 'smscan_dashboard_widget', 'Media Usage Overview', [ $this, 'render_dashboard_widget' ] );
	}

	public function render_dashboard_widget() {
		global $wpdb;
		$total = $this->scanner->get_total_attachments();
		$used = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table} WHERE is_used = 1" );
		$unused = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table} WHERE is_used = 0" );
		$last = $wpdb->get_var( "SELECT MAX(last_scanned) FROM {$this->core->table}" );
		?>
		<p style="color:#64748b; margin-top:0;">You have <strong><?php echo (int) $total; ?></strong> media items in your library.</p>
		<table style="width:100%; border-collapse:collapse; margin-bottom:15px;">
			<tr>
				<td><span style="color:#00a32a;font-weight:700;font-size:24px;"><?php echo (int) $used; ?></span><br><span style="font-size:13px;color:#64748b;">Used</span></td>
				<td><span style="color:#d63638;font-weight:700;font-size:24px;"><?php echo (int) $unused; ?></span><br><span style="font-size:13px;color:#64748b;">Unused</span></td>
			</tr>
		</table>
		<p style="margin-bottom:0;">
			<a href="<?php echo esc_url( admin_url( 'upload.php?page=smscan-scan-media' ) ); ?>" class="button button-primary">View Scanner</a>
			<?php if ( $last ) : ?>
				<span style="margin-left:10px;font-size:12px;color:#64748b;">Last scanned: <?php echo esc_html( $last ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	public function admin_footer_text( $text ) {
		$screen = get_current_screen();
		if ( $screen && ( strpos( $screen->id, 'smscan' ) !== false || $screen->id === 'settings_page_smscan-settings' ) ) {
			$text = 'Built by <a href="https://thesushant.in/portfolio" target="_blank" style="font-weight:600; color:#2271b1;">Sushant Kumar</a>. View my <a href="https://github.com/thesushant" target="_blank">GitHub</a> and <a href="https://linkedin.com/in/thesushant" target="_blank">LinkedIn</a>.';
		}
		return $text;
	}
}