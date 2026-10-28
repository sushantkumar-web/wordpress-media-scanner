<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Smscan_Telemetry {
	private $core;

	public function __construct( $core ) {
		$this->core = $core;
		
		add_action( 'admin_notices', [ $this, 'telemetry_notice' ] );
		add_action( 'admin_init', [ $this, 'handle_optin' ] );
		add_action( 'admin_init', [ $this, 'handle_manual_test' ] );
		add_action( 'smscan_weekly_telemetry', [ $this, 'send_telemetry' ] );
		
		// Schedule the weekly ping if opted in
		if ( get_option( 'smscan_telemetry_choice' ) === 'yes' && ! wp_next_scheduled( 'smscan_weekly_telemetry' ) ) {
			wp_schedule_event( time(), 'weekly', 'smscan_weekly_telemetry' );
		}
	}

	public function telemetry_notice() {
		if ( get_option( 'smscan_telemetry_choice' ) !== false ) return;
		
		global $wpdb;
		$has_scanned = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table}" );
		if ( ! $has_scanned ) return;

		$yes_url = wp_nonce_url( add_query_arg( 'smscan_telemetry', 'yes' ), 'smscan_telemetry' );
		$no_url  = wp_nonce_url( add_query_arg( 'smscan_telemetry', 'no' ), 'smscan_telemetry' );
		?>
		<div class="smscan-notice">
			<h2>Help us improve Sushant Media Scanner</h2>
			<p>We'd love to learn how the plugin is being used so we can prioritize features. This sends anonymous data only (WP version, active page builders, media count). No personal information, images, or domains are ever sent.</p>
			<div class="smscan-btn-group">
				<a href="<?php echo esc_url( $yes_url ); ?>" class="smscan-btn">Allow anonymous tracking</a>
				<a href="<?php echo esc_url( $no_url ); ?>" class="smscan-btn smscan-btn-secondary">No thanks</a>
			</div>
		</div>
		<?php
	}

	public function handle_optin() {
		if ( ! isset( $_GET['smscan_telemetry'] ) || ! check_admin_referer( 'smscan_telemetry' ) ) return;
		
		$choice = sanitize_key( $_GET['smscan_telemetry'] );
		update_option( 'smscan_telemetry_choice', $choice === 'yes' ? 'yes' : 'no' );
		
		if ( $choice === 'yes' ) {
			$this->send_telemetry(); // Send immediately!
		}
		
		// Redirect back to the scanner dashboard
		wp_safe_redirect( admin_url( 'upload.php?page=smscan-scan-media' ) );
		exit;
	}

	public function handle_manual_test() {
		if ( isset( $_GET['smscan_test_telemetry'] ) && current_user_can( 'manage_options' ) ) {
			update_option( 'smscan_telemetry_choice', 'yes' );
			$response = $this->send_telemetry( true );
			
			if ( is_wp_error( $response ) ) {
				wp_die( 'WP Error: ' . esc_html( $response->get_error_message() ) . '<br><br><a href="' . esc_url( admin_url() ) . '">Go back</a>' );
			} else {
				$code = wp_remote_retrieve_response_code( $response );
				// Redirect back to dashboard with success flag
				wp_safe_redirect( add_query_arg( 'smscan_test_result', $code, admin_url( 'upload.php?page=smscan-scan-media' ) ) );
				exit;
			}
		}
	}

	public function send_telemetry( $debug = false ) {
		if ( get_option( 'smscan_telemetry_choice' ) !== 'yes' && ! $debug ) return;

		global $wpdb;

		$data = [
			'site_hash'       => md5( site_url() ),
			'plugin_version'  => defined( 'SMSCAN_VERSION' ) ? SMSCAN_VERSION : '1.3.0',
			'wp_version'      => get_bloginfo( 'version' ),
			'php_version'     => phpversion(),
			'has_elementor'   => defined( 'ELEMENTOR_VERSION' ) ? 'yes' : 'no',
			'has_divi'        => defined( 'ET_BUILDER_VERSION' ) ? 'yes' : 'no',
			'media_count'     => (int) wp_count_posts( 'attachment' )->inherit,
			'scanned_count'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table}" ),
			'used_count'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->core->table} WHERE is_used = 1" ),
		];

		$endpoint = 'https://smscanner.thesushant.in/telemetry.php'; 

		$response = wp_remote_post( $endpoint, [
			'timeout'   => 15,
			'blocking'  => $debug,
			'sslverify' => false, 
			'headers'   => [ 
				'Content-Type' => 'application/json',
				'X-API-Key'    => 'sms_sec_987654321'
			],
			'body'      => wp_json_encode( $data ),
		] );

		if ( $debug ) {
			return $response;
		}
	}
}