<?php
/**
 * Plugin bootstrap and stored configuration.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

namespace MailBridgeAPI\EmailDelivery;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private const CONNECTION_OPTION = 'mbapi_ed_connection';
	private const SETTINGS_OPTION   = 'mbapi_ed_settings';
	private const PENDING_PREFIX    = 'mbapi_ed_pending_';
	private static ?Plugin $instance = null;
	private Api_Client $api;
	private Logger $logger;
	private Health $health;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->api    = new Api_Client();
		$this->logger = new Logger();
		$this->health = new Health( $this->api, $this->logger );
	}

	public function register(): void {
		$mailer = new Mailer( $this->api, $this->logger );
		$admin  = new Admin( $this->api, $this->logger, $this->health );

		add_filter( 'pre_wp_mail', array( $mailer, 'intercept' ), 100, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', array( Privacy::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Privacy::class, 'erasers' ) );
		add_action( 'admin_menu', array( $admin, 'menus' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'assets' ) );
		add_action( 'admin_init', array( $admin, 'privacy_policy' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MBAPI_ED_FILE ), array( $admin, 'action_links' ) );
		add_filter( 'site_status_tests', array( $this->health, 'site_status_tests' ) );
		add_filter( 'debug_information', array( $this->health, 'debug_information' ) );

		add_action( 'admin_post_mbapi_ed_login', array( $admin, 'handle_login' ) );
		add_action( 'admin_post_mbapi_ed_select', array( $admin, 'handle_select' ) );
		add_action( 'admin_post_mbapi_ed_test', array( $admin, 'handle_test' ) );
		add_action( 'admin_post_mbapi_ed_disconnect', array( $admin, 'handle_disconnect' ) );
		add_action( 'admin_post_mbapi_ed_settings', array( $admin, 'handle_settings' ) );
		add_action( 'admin_post_mbapi_ed_cancel_pending', array( $admin, 'handle_cancel_pending' ) );
		add_action( 'admin_post_mbapi_ed_send_test', array( $admin, 'handle_send_test' ) );
		add_action( 'admin_post_mbapi_ed_clear_logs', array( $admin, 'handle_clear_logs' ) );
		add_action( 'admin_post_mbapi_ed_export_logs', array( $admin, 'handle_export_logs' ) );
		add_action( 'mbapi_ed_daily_cleanup', array( $this->logger, 'cleanup' ) );
	}

	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( MBAPI_ED_FILE ) );
			wp_die( esc_html__( 'MailBridgeAPI Email Delivery requires PHP 7.4 or newer.', 'mailbridgeapi-email-delivery' ) );
		}
		if ( ! Crypto::available() ) {
			deactivate_plugins( plugin_basename( MBAPI_ED_FILE ) );
			wp_die( esc_html__( 'MailBridgeAPI Email Delivery requires the OpenSSL PHP extension.', 'mailbridgeapi-email-delivery' ) );
		}
		Logger::install();
		if ( ! wp_next_scheduled( 'mbapi_ed_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mbapi_ed_daily_cleanup' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'mbapi_ed_daily_cleanup' );
	}

	/** @return array<string,mixed> */
	public static function connection(): array {
		$encrypted = get_option( self::CONNECTION_OPTION, '' );
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			return array();
		}
		try {
			$data = json_decode( Crypto::decrypt( $encrypted ), true );
			return is_array( $data ) ? $data : array();
		} catch ( \Throwable $error ) {
			return array();
		}
	}

	/** @param array<string,mixed> $connection Connection values. */
	public static function save_connection( array $connection ): void {
		$value = Crypto::encrypt( wp_json_encode( $connection ) );
		if ( false === get_option( self::CONNECTION_OPTION, false ) ) {
			add_option( self::CONNECTION_OPTION, $value, '', false );
		} else {
			update_option( self::CONNECTION_OPTION, $value, false );
		}
	}

	public static function clear_connection(): void {
		delete_option( self::CONNECTION_OPTION );
	}

	/** @return array<string,mixed> */
	public static function pending( ?int $user_id = null ): array {
		$user_id   = $user_id ?: get_current_user_id();
		$encrypted = get_transient( self::PENDING_PREFIX . $user_id );
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			return array();
		}
		try {
			$data = json_decode( Crypto::decrypt( $encrypted ), true );
			return is_array( $data ) ? $data : array();
		} catch ( \Throwable $error ) {
			self::clear_pending( $user_id );
			return array();
		}
	}

	/** @param array<string,mixed> $pending Pending values. */
	public static function save_pending( array $pending, ?int $user_id = null ): void {
		$user_id = $user_id ?: get_current_user_id();
		set_transient( self::PENDING_PREFIX . $user_id, Crypto::encrypt( wp_json_encode( $pending ) ), 15 * MINUTE_IN_SECONDS );
	}

	public static function clear_pending( ?int $user_id = null ): void {
		delete_transient( self::PENDING_PREFIX . ( $user_id ?: get_current_user_id() ) );
	}

	/** @return array{retention_days:int,delete_on_uninstall:bool} */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'retention_days'      => max( 30, min( 365, (int) ( $saved['retention_days'] ?? 90 ) ) ),
			'delete_on_uninstall' => ! empty( $saved['delete_on_uninstall'] ),
		);
	}

	/** @param array<string,mixed> $settings Settings values. */
	public static function save_settings( array $settings ): void {
		$value = array(
			'retention_days'      => max( 30, min( 365, (int) ( $settings['retention_days'] ?? 90 ) ) ),
			'delete_on_uninstall' => ! empty( $settings['delete_on_uninstall'] ),
		);
		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, $value, '', false );
		} else {
			update_option( self::SETTINGS_OPTION, $value, false );
		}
	}
}

