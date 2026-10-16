<?php
/**
 * WordPress administration screens.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

namespace MailBridgeAPI\EmailDelivery;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private const SLUG = 'mailbridgeapi-email-delivery';
	private Api_Client $api;
	private Logger $logger;
	private Health $health;

	public function __construct( Api_Client $api, Logger $logger, Health $health ) {
		$this->api    = $api;
		$this->logger = $logger;
		$this->health = $health;
	}

	public function menus(): void {
		add_menu_page( 'MailBridgeAPI', 'MailBridgeAPI', 'manage_options', self::SLUG, array( $this, 'connection_page' ), 'dashicons-email-alt2', 58 );
		add_submenu_page( self::SLUG, __( 'Connection', 'mailbridgeapi-email-delivery' ), __( 'Connection', 'mailbridgeapi-email-delivery' ), 'manage_options', self::SLUG, array( $this, 'connection_page' ) );
		$error_count = $this->logger->recent_error_count();
		$logs_label  = __( 'Sent emails', 'mailbridgeapi-email-delivery' );
		if ( $error_count > 0 ) {
			$logs_label .= ' <span class="awaiting-mod count-' . (int) $error_count . '"><span class="pending-count">' . esc_html( number_format_i18n( $error_count ) ) . '</span></span>';
		}
		add_submenu_page( self::SLUG, __( 'Sent emails', 'mailbridgeapi-email-delivery' ), $logs_label, 'manage_options', self::SLUG . '-logs', array( $this, 'logs_page' ) );
		add_submenu_page( self::SLUG, __( 'Statistics', 'mailbridgeapi-email-delivery' ), __( 'Statistics', 'mailbridgeapi-email-delivery' ), 'manage_options', self::SLUG . '-stats', array( $this, 'stats_page' ) );
		add_submenu_page( self::SLUG, __( 'Diagnostics', 'mailbridgeapi-email-delivery' ), __( 'Diagnostics', 'mailbridgeapi-email-delivery' ), 'manage_options', self::SLUG . '-diagnostics', array( $this, 'diagnostics_page' ) );
		add_submenu_page( self::SLUG, __( 'Documentation', 'mailbridgeapi-email-delivery' ), __( 'Documentation', 'mailbridgeapi-email-delivery' ), 'manage_options', self::SLUG . '-help', array( $this, 'help_page' ) );
	}

	public function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'mbapi-ed-admin', MBAPI_ED_URL . 'assets/admin.css', array(), MBAPI_ED_VERSION );
		wp_enqueue_script( 'mbapi-ed-admin', MBAPI_ED_URL . 'assets/admin.js', array(), MBAPI_ED_VERSION, true );
	}

	/** @param array<string,string> $links Plugin action links. */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Configure', 'mailbridgeapi-email-delivery' ) . '</a>' );
		return $links;
	}

	public function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . __( 'When MailBridgeAPI Email Delivery is connected, outgoing email recipient addresses, subjects, message bodies, headers and up to two attachments are sent to MailBridgeAPI for SMTP delivery. The plugin stores recipient address, subject, delivery state, provider response, message identifier and timestamp in the WordPress database for the configured retention period. The MailBridgeAPI dashboard email and password are used only during sign-in; the password is not stored. API credentials are encrypted locally using the WordPress authentication salt.', 'mailbridgeapi-email-delivery' ) . '</p>';
		/* translators: %s: MailBridgeAPI privacy policy URL. */
		$content .= '<p>' . sprintf( wp_kses_post( __( 'Review the <a href="%s" target="_blank" rel="noopener noreferrer">MailBridgeAPI privacy policy</a> for service-side processing and retention.', 'mailbridgeapi-email-delivery' ) ), esc_url( 'https://mailbridgeapi.com/privacy-policy/' ) ) . '</p>';
		wp_add_privacy_policy_content( 'MailBridgeAPI Email Delivery', wp_kses_post( $content ) );
	}

	public function connection_page(): void {
		$this->guard();
		$connection = Plugin::connection();
		$pending    = Plugin::pending();
		$settings   = Plugin::settings();
		$this->page_start( __( 'Mailbox connection', 'mailbridgeapi-email-delivery' ), __( 'Route every WordPress email through one controlled MailBridgeAPI mailbox.', 'mailbridgeapi-email-delivery' ) );
		$this->notice();

		if ( $connection ) {
			$this->render_connected( $connection, $settings );
		} elseif ( $pending ) {
			$this->render_mailbox_selection( $pending );
		} else {
			$this->render_login();
		}
		$this->page_end();
	}

	/** @param array<string,mixed> $connection Connection values. @param array<string,mixed> $settings Settings values. */
	private function render_connected( array $connection, array $settings ): void {
		$active = ! empty( $connection['subscription_active'] );
		$conflicts = $this->health->conflicts();
		?>
		<section class="mbapi-status-card <?php echo $active ? 'is-connected' : 'is-expired'; ?>">
			<div class="mbapi-status-icon"><span class="dashicons <?php echo $active ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span></div>
			<div><span class="mbapi-eyebrow"><?php esc_html_e( 'Selected mailbox · locked', 'mailbridgeapi-email-delivery' ); ?></span><h2><?php echo esc_html( (string) $connection['mailbox_name'] ); ?></h2><p><?php echo esc_html( (string) $connection['mailbox_email'] ); ?></p></div>
			<span class="mbapi-badge <?php echo $active ? 'success' : 'error'; ?>"><?php echo esc_html( $active ? __( 'Email delivery active', 'mailbridgeapi-email-delivery' ) : __( 'Subscription expired', 'mailbridgeapi-email-delivery' ) ); ?></span>
		</section>
		<?php if ( ! $active ) : ?><div class="notice notice-error inline"><p><strong><?php esc_html_e( 'MailBridgeAPI delivery is paused.', 'mailbridgeapi-email-delivery' ); ?></strong> <?php esc_html_e( 'Renew the subscription before WordPress email can be sent again.', 'mailbridgeapi-email-delivery' ); ?> <a href="https://dashboard.mailbridgeapi.com/subscription/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage subscription', 'mailbridgeapi-email-delivery' ); ?></a></p></div><?php endif; ?>
		<?php if ( $conflicts ) : ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Potential email transport conflict.', 'mailbridgeapi-email-delivery' ); ?></strong> <?php /* translators: %s: comma-separated list of potentially conflicting plugin slugs. */ echo esc_html( sprintf( __( 'Another delivery plugin is active: %s. Keep only one plugin that replaces wp_mail().', 'mailbridgeapi-email-delivery' ), implode( ', ', $conflicts ) ) ); ?></p></div><?php endif; ?>
		<div class="mbapi-grid mbapi-grid-2">
			<?php $this->protocol_card( 'smtp', (string) ( $connection['smtp_host'] ?? '' ), $connection ); ?>
			<?php $this->protocol_card( 'imap', (string) ( $connection['imap_host'] ?? '' ), $connection ); ?>
		</div>
		<?php $this->delivery_test_panel( $connection, $active ); ?>
		<section class="mbapi-panel mbapi-lock-panel"><span class="dashicons dashicons-lock"></span><div><h3><?php esc_html_e( 'Mailbox selection is protected', 'mailbridgeapi-email-delivery' ); ?></h3><p><?php esc_html_e( 'This site cannot display or switch to another mailbox while connected. Disconnect first to protect agencies and clients sharing one MailBridgeAPI account.', 'mailbridgeapi-email-delivery' ); ?></p></div></section>
		<div class="mbapi-grid mbapi-grid-2">
			<section class="mbapi-panel"><h3><?php esc_html_e( 'Local delivery log', 'mailbridgeapi-email-delivery' ); ?></h3><p><?php esc_html_e( 'Keep site-specific recipient, subject and delivery status metadata. Message bodies and attachments are never stored in WordPress by this plugin.', 'mailbridgeapi-email-delivery' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mbapi_ed_settings"><?php wp_nonce_field( 'mbapi_ed_settings' ); ?><label for="mbapi-retention"><strong><?php esc_html_e( 'Retention', 'mailbridgeapi-email-delivery' ); ?></strong></label><select id="mbapi-retention" name="retention_days"><?php foreach ( array( 30, 90, 180, 365 ) as $days ) : ?><option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( (int) $settings['retention_days'], $days ); ?>><?php /* translators: %d: retention period in days. */ echo esc_html( sprintf( __( '%d days', 'mailbridgeapi-email-delivery' ), $days ) ); ?></option><?php endforeach; ?></select><label class="mbapi-checkbox"><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Delete settings and logs when the plugin is uninstalled', 'mailbridgeapi-email-delivery' ); ?></label><button class="button button-secondary"><?php esc_html_e( 'Save settings', 'mailbridgeapi-email-delivery' ); ?></button></form></section>
			<section class="mbapi-panel mbapi-danger"><h3><?php esc_html_e( 'Disconnect this site', 'mailbridgeapi-email-delivery' ); ?></h3><p><?php esc_html_e( 'Disconnecting removes the locally stored encrypted keys. Native WordPress mail resumes until another mailbox is selected.', 'mailbridgeapi-email-delivery' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mbapi-confirm="<?php esc_attr_e( 'Disconnect MailBridgeAPI from this WordPress site?', 'mailbridgeapi-email-delivery' ); ?>"><input type="hidden" name="action" value="mbapi_ed_disconnect"><?php wp_nonce_field( 'mbapi_ed_disconnect' ); ?><button class="button button-link-delete"><?php esc_html_e( 'Disconnect mailbox', 'mailbridgeapi-email-delivery' ); ?></button></form></section>
		</div>
		<?php
	}

	private function render_login(): void {
		?>
		<section class="mbapi-panel mbapi-login-panel"><div class="mbapi-step">1</div><div class="mbapi-panel-copy"><span class="mbapi-eyebrow"><?php esc_html_e( 'Secure account connection', 'mailbridgeapi-email-delivery' ); ?></span><h2><?php esc_html_e( 'Connect your MailBridgeAPI account', 'mailbridgeapi-email-delivery' ); ?></h2><p><?php esc_html_e( 'Enter the same email address and password used for the MailBridgeAPI dashboard. The password is sent over HTTPS for authentication and is never stored by WordPress.', 'mailbridgeapi-email-delivery' ); ?></p>
		<form class="mbapi-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mbapi_ed_login"><?php wp_nonce_field( 'mbapi_ed_login' ); ?><label for="mbapi-email"><?php esc_html_e( 'Dashboard email address', 'mailbridgeapi-email-delivery' ); ?></label><input id="mbapi-email" class="regular-text" type="email" name="email" autocomplete="username" required maxlength="254"><label for="mbapi-password"><?php esc_html_e( 'Dashboard password', 'mailbridgeapi-email-delivery' ); ?></label><input id="mbapi-password" class="regular-text" type="password" name="password" autocomplete="current-password" required maxlength="200"><button class="button button-primary button-hero"><?php esc_html_e( 'Connect securely', 'mailbridgeapi-email-delivery' ); ?></button></form></div></section>
		<div class="mbapi-security"><span class="dashicons dashicons-shield-alt"></span><p><strong><?php esc_html_e( 'Designed for production stores.', 'mailbridgeapi-email-delivery' ); ?></strong> <?php esc_html_e( 'Credentials are restricted to administrators, protected by WordPress nonces and capabilities, and encrypted at rest with your WordPress authentication salt.', 'mailbridgeapi-email-delivery' ); ?></p></div>
		<?php
	}

	/** @param array<string,mixed> $pending Pending values. */
	private function render_mailbox_selection( array $pending ): void {
		$mailboxes = is_array( $pending['mailboxes'] ?? null ) ? $pending['mailboxes'] : array();
		?>
		<section class="mbapi-selection-head"><div><span class="mbapi-eyebrow"><?php esc_html_e( 'Step 2 · select once', 'mailbridgeapi-email-delivery' ); ?></span><h2><?php esc_html_e( 'Choose the mailbox for this WordPress site', 'mailbridgeapi-email-delivery' ); ?></h2><p><?php esc_html_e( 'After selection, all other account mailboxes disappear from this site. Switching requires a full disconnect and a new authenticated connection.', 'mailbridgeapi-email-delivery' ); ?></p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mbapi_ed_cancel_pending"><?php wp_nonce_field( 'mbapi_ed_cancel_pending' ); ?><button class="button"><?php esc_html_e( 'Cancel', 'mailbridgeapi-email-delivery' ); ?></button></form></section>
		<?php if ( ! $mailboxes ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'No mailbox is available on this account. Add one in the MailBridgeAPI dashboard first.', 'mailbridgeapi-email-delivery' ); ?></p></div><?php endif; ?>
		<div class="mbapi-grid mbapi-mailbox-grid">
		<?php foreach ( $mailboxes as $mailbox ) : ?>
			<article class="mbapi-mailbox-card"><div class="mbapi-mailbox-icon"><span class="dashicons dashicons-email-alt"></span></div><h3><?php echo esc_html( (string) ( $mailbox['name'] ?? '' ) ); ?></h3><p><?php echo esc_html( (string) ( $mailbox['email'] ?? '' ) ); ?></p><div class="mbapi-protocol-pills"><span class="<?php echo empty( $mailbox['smtp_host'] ) ? 'muted' : 'ready'; ?>">SMTP</span><span class="<?php echo empty( $mailbox['imap_host'] ) ? 'muted' : 'ready'; ?>">IMAP</span></div><div class="mbapi-card-actions">
			<?php $this->test_button( 'smtp', (int) $mailbox['id'], empty( $mailbox['smtp_host'] ), 'pending' ); ?><?php $this->test_button( 'imap', (int) $mailbox['id'], empty( $mailbox['imap_host'] ), 'pending' ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mbapi_ed_select"><input type="hidden" name="mailbox_id" value="<?php echo esc_attr( (string) (int) $mailbox['id'] ); ?>"><?php wp_nonce_field( 'mbapi_ed_select' ); ?><button class="button button-primary" <?php disabled( empty( $mailbox['smtp_host'] ) ); ?>><?php esc_html_e( 'Use this mailbox', 'mailbridgeapi-email-delivery' ); ?></button></form></div><?php if ( empty( $mailbox['smtp_host'] ) ) : ?><p class="mbapi-card-note"><?php esc_html_e( 'Configure SMTP in MailBridgeAPI before using this mailbox for WordPress delivery.', 'mailbridgeapi-email-delivery' ); ?></p><?php endif; ?></article>
		<?php endforeach; ?></div>
		<?php
	}

	/** @param array<string,mixed> $connection Connection values. */
	private function protocol_card( string $protocol, string $host, array $connection ): void {
		$label = strtoupper( $protocol );
		?><section class="mbapi-panel mbapi-protocol"><div class="mbapi-protocol-title"><span class="dashicons <?php echo 'smtp' === $protocol ? 'dashicons-upload' : 'dashicons-download'; ?>"></span><div><span class="mbapi-eyebrow"><?php echo esc_html( $label ); ?></span><h3><?php echo esc_html( 'smtp' === $protocol ? __( 'Outgoing email', 'mailbridgeapi-email-delivery' ) : __( 'Incoming email', 'mailbridgeapi-email-delivery' ) ); ?></h3></div><span class="mbapi-badge <?php echo $host ? 'success' : 'neutral'; ?>"><?php echo esc_html( $host ? __( 'Configured', 'mailbridgeapi-email-delivery' ) : __( 'Not configured', 'mailbridgeapi-email-delivery' ) ); ?></span></div><p><?php echo $host ? esc_html( $host ) : esc_html__( 'This protocol is not configured for the selected mailbox.', 'mailbridgeapi-email-delivery' ); ?></p><?php $this->test_button( $protocol, (int) $connection['mailbox_id'], ! $host, 'connected' ); ?></section><?php
	}

	private function test_button( string $protocol, int $mailbox_id, bool $disabled, string $scope ): void {
		$result         = $this->test_result( $scope, $protocol, $mailbox_id );
		$protocol_label = strtoupper( $protocol );
		/* translators: %s: SMTP or IMAP protocol name. */
		$button_label = sprintf( __( 'Test %s', 'mailbridgeapi-email-delivery' ), $protocol_label );
		/* translators: %s: SMTP or IMAP protocol name. */
		$success_label = sprintf( __( '%s connection successful', 'mailbridgeapi-email-delivery' ), $protocol_label );
		/* translators: %s: SMTP or IMAP protocol name. */
		$error_label = sprintf( __( '%s connection failed', 'mailbridgeapi-email-delivery' ), $protocol_label );
		$tested_label = '';
		if ( $result ) {
			/* translators: %s: localized date and time of the connection test. */
			$tested_label = sprintf( __( 'Last tested: %s', 'mailbridgeapi-email-delivery' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $result['tested_at'] ) );
		}
		?><div class="mbapi-test-control"><form class="mbapi-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mbapi-test><input type="hidden" name="action" value="mbapi_ed_test"><input type="hidden" name="protocol" value="<?php echo esc_attr( $protocol ); ?>"><input type="hidden" name="mailbox_id" value="<?php echo esc_attr( (string) $mailbox_id ); ?>"><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>"><?php wp_nonce_field( 'mbapi_ed_test' ); ?><button class="button" data-testing-label="<?php esc_attr_e( 'Testing…', 'mailbridgeapi-email-delivery' ); ?>" <?php disabled( $disabled ); ?>><?php echo esc_html( $button_label ); ?></button></form><?php if ( $result ) : ?><div class="mbapi-test-result <?php echo ! empty( $result['ok'] ) ? 'is-success' : 'is-error'; ?>" role="status"><span class="dashicons <?php echo ! empty( $result['ok'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span><div><strong><?php echo esc_html( ! empty( $result['ok'] ) ? $success_label : $error_label ); ?></strong><p><?php echo esc_html( (string) $result['message'] ); ?></p><small><?php echo esc_html( $tested_label ); ?></small></div></div><?php endif; ?></div><?php
	}

	/** @param array<string,mixed> $connection */
	private function delivery_test_panel( array $connection, bool $active ): void {
		$user   = wp_get_current_user();
		$result = get_user_meta( get_current_user_id(), 'mbapi_ed_delivery_test_result', true );
		$result = is_array( $result ) ? $result : array();
		$ready  = $active && ! empty( $connection['smtp_host'] );
		$message_id_label = '';
		$tested_label     = '';
		if ( $result ) {
			if ( ! empty( $result['remote_id'] ) ) {
				/* translators: %s: MailBridgeAPI remote message identifier. */
				$message_id_label = sprintf( __( 'Message ID: %s', 'mailbridgeapi-email-delivery' ), (string) $result['remote_id'] );
			}
			/* translators: %s: localized date and time of the real test email. */
			$tested_label = sprintf( __( 'Tested at: %s', 'mailbridgeapi-email-delivery' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $result['tested_at'] ?? time() ) ) );
		}
		?>
		<section class="mbapi-panel mbapi-delivery-test">
			<div class="mbapi-panel-heading"><div><span class="mbapi-eyebrow"><?php esc_html_e( 'End-to-end verification', 'mailbridgeapi-email-delivery' ); ?></span><h2><?php esc_html_e( 'Send a real test email', 'mailbridgeapi-email-delivery' ); ?></h2><p><?php esc_html_e( 'This sends a real message through WordPress, MailBridgeAPI and the selected SMTP server. The provider response is stored in the local delivery log.', 'mailbridgeapi-email-delivery' ); ?></p></div><span class="dashicons dashicons-email-alt"></span></div>
			<form class="mbapi-test-email-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mbapi-test><input type="hidden" name="action" value="mbapi_ed_send_test"><?php wp_nonce_field( 'mbapi_ed_send_test' ); ?><label for="mbapi-test-recipient"><strong><?php esc_html_e( 'Destination email address', 'mailbridgeapi-email-delivery' ); ?></strong></label><div><input id="mbapi-test-recipient" type="email" name="recipient" value="<?php echo esc_attr( (string) $user->user_email ); ?>" required maxlength="254"><button class="button button-primary" data-testing-label="<?php esc_attr_e( 'Sending test…', 'mailbridgeapi-email-delivery' ); ?>" <?php disabled( ! $ready ); ?>><?php esc_html_e( 'Send test email', 'mailbridgeapi-email-delivery' ); ?></button></div><?php if ( ! $ready ) : ?><small><?php esc_html_e( 'An active subscription and configured SMTP connection are required.', 'mailbridgeapi-email-delivery' ); ?></small><?php endif; ?></form>
			<?php if ( $result ) : ?><div class="mbapi-test-result <?php echo ! empty( $result['ok'] ) ? 'is-success' : 'is-error'; ?>" role="status"><span class="dashicons <?php echo ! empty( $result['ok'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span><div><strong><?php echo esc_html( ! empty( $result['ok'] ) ? __( 'Test email accepted', 'mailbridgeapi-email-delivery' ) : __( 'Test email failed', 'mailbridgeapi-email-delivery' ) ); ?></strong><p><?php echo esc_html( (string) ( $result['message'] ?? '' ) ); ?></p><?php if ( $message_id_label ) : ?><small><?php echo esc_html( $message_id_label ); ?></small><?php endif; ?><small><?php echo esc_html( $tested_label ); ?></small></div></div><?php endif; ?>
		</section>
		<?php
	}

	public function logs_page(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- These values only filter a read-only administrator table.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$data   = $this->logger->page( $page, $status, $search );
		$this->page_start( __( 'Sent emails', 'mailbridgeapi-email-delivery' ), __( 'Delivery results generated by this WordPress site.', 'mailbridgeapi-email-delivery' ) );
		$this->notice();
		?><section class="mbapi-panel"><div class="mbapi-log-toolbar"><form class="mbapi-filters" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-logs' ); ?>"><select name="status"><option value="all"><?php esc_html_e( 'All statuses', 'mailbridgeapi-email-delivery' ); ?></option><option value="success" <?php selected( $status, 'success' ); ?>><?php esc_html_e( 'Delivered', 'mailbridgeapi-email-delivery' ); ?></option><option value="error" <?php selected( $status, 'error' ); ?>><?php esc_html_e( 'Errors', 'mailbridgeapi-email-delivery' ); ?></option></select><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Recipient, subject or message ID', 'mailbridgeapi-email-delivery' ); ?>"><button class="button"><?php esc_html_e( 'Filter', 'mailbridgeapi-email-delivery' ); ?></button></form><div class="mbapi-log-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mbapi_ed_export_logs"><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"><input type="hidden" name="search" value="<?php echo esc_attr( $search ); ?>"><?php wp_nonce_field( 'mbapi_ed_export_logs' ); ?><button class="button"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Export CSV', 'mailbridgeapi-email-delivery' ); ?></button></form><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mbapi-confirm="<?php esc_attr_e( 'Permanently delete every local MailBridgeAPI delivery log?', 'mailbridgeapi-email-delivery' ); ?>"><input type="hidden" name="action" value="mbapi_ed_clear_logs"><?php wp_nonce_field( 'mbapi_ed_clear_logs' ); ?><button class="button button-link-delete"><?php esc_html_e( 'Clear logs', 'mailbridgeapi-email-delivery' ); ?></button></form></div></div>
		<div class="mbapi-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Recipient', 'mailbridgeapi-email-delivery' ); ?></th><th><?php esc_html_e( 'Subject', 'mailbridgeapi-email-delivery' ); ?></th><th><?php esc_html_e( 'Status', 'mailbridgeapi-email-delivery' ); ?></th><th><?php esc_html_e( 'Provider response', 'mailbridgeapi-email-delivery' ); ?></th><th><?php esc_html_e( 'UTC date', 'mailbridgeapi-email-delivery' ); ?></th></tr></thead><tbody><?php if ( ! $data['items'] ) : ?><tr><td colspan="5"><?php esc_html_e( 'No WordPress email has been recorded yet.', 'mailbridgeapi-email-delivery' ); ?></td></tr><?php else : foreach ( $data['items'] as $row ) : ?><tr><td><strong><?php echo esc_html( (string) $row['recipient'] ); ?></strong></td><td><?php echo esc_html( (string) $row['subject'] ?: __( '(No subject)', 'mailbridgeapi-email-delivery' ) ); ?><?php if ( $row['remote_id'] ) : ?><small><?php echo esc_html( (string) $row['remote_id'] ); ?></small><?php endif; ?></td><td><span class="mbapi-badge <?php echo 'success' === $row['status'] ? 'success' : 'error'; ?>"><?php echo esc_html( 'success' === $row['status'] ? __( 'Delivered', 'mailbridgeapi-email-delivery' ) : __( 'Error', 'mailbridgeapi-email-delivery' ) ); ?></span></td><td><?php echo esc_html( (string) $row['result_message'] ); ?></td><td><?php echo esc_html( (string) $row['created_at'] ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div><?php $this->pagination( $data, $status, $search ); ?></section><?php
		$this->page_end();
	}

	public function stats_page(): void {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This value only selects a read-only reporting period.
		$period = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 30;
		$period = in_array( $period, array( 7, 30, 90, 365 ), true ) ? $period : 30;
		$stats = $this->logger->stats( $period );
		$rate  = $stats['total'] > 0 ? round( ( $stats['successful'] / $stats['total'] ) * 100, 1 ) : null;
		$this->page_start( __( 'Email statistics', 'mailbridgeapi-email-delivery' ), __( 'A site-specific view of WordPress email delivery.', 'mailbridgeapi-email-delivery' ) );
		?><form class="mbapi-period-filter" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-stats' ); ?>"><label for="mbapi-period"><?php esc_html_e( 'Reporting period', 'mailbridgeapi-email-delivery' ); ?></label><select id="mbapi-period" name="period" onchange="this.form.submit()"><?php foreach ( array( 7, 30, 90, 365 ) as $days ) : ?><option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $period, $days ); ?>><?php /* translators: %d: statistics reporting period in days. */ echo esc_html( sprintf( __( 'Last %d days', 'mailbridgeapi-email-delivery' ), $days ) ); ?></option><?php endforeach; ?></select><noscript><button class="button"><?php esc_html_e( 'Apply', 'mailbridgeapi-email-delivery' ); ?></button></noscript></form>
		<div class="mbapi-stats"><article><span><?php esc_html_e( 'Total requests', 'mailbridgeapi-email-delivery' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong></article><article><span><?php esc_html_e( 'Delivered', 'mailbridgeapi-email-delivery' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['successful'] ) ); ?></strong></article><article><span><?php esc_html_e( 'Errors', 'mailbridgeapi-email-delivery' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['errors'] ) ); ?></strong></article><article><span><?php esc_html_e( 'Success rate', 'mailbridgeapi-email-delivery' ); ?></span><strong><?php echo null === $rate ? '—' : esc_html( number_format_i18n( $rate, 1 ) . '%' ); ?></strong></article></div>
		<section class="mbapi-panel"><h2><?php /* translators: %d: statistics reporting period in days. */ echo esc_html( sprintf( __( 'Daily activity · last %d days', 'mailbridgeapi-email-delivery' ), $period ) ); ?></h2><div class="mbapi-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'UTC date', 'mailbridgeapi-email-delivery' ); ?></th><th><?php esc_html_e( 'Requests', 'mailbridgeapi-email-delivery' ); ?></th><th><?php esc_html_e( 'Delivered', 'mailbridgeapi-email-delivery' ); ?></th><th><?php esc_html_e( 'Errors', 'mailbridgeapi-email-delivery' ); ?></th></tr></thead><tbody><?php if ( ! $stats['daily'] ) : ?><tr><td colspan="4"><?php esc_html_e( 'No activity yet.', 'mailbridgeapi-email-delivery' ); ?></td></tr><?php else : foreach ( $stats['daily'] as $row ) : ?><tr><td><?php echo esc_html( (string) $row['day'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['successful'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['errors'] ) ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section><?php
		$this->page_end();
	}

	public function diagnostics_page(): void {
		$this->guard();
		$checks = $this->health->checks();
		$report = $this->health->report( $checks );
		$this->page_start( __( 'Diagnostics', 'mailbridgeapi-email-delivery' ), __( 'Verify the complete WordPress-to-MailBridgeAPI delivery environment.', 'mailbridgeapi-email-delivery' ) );
		?>
		<div class="mbapi-diagnostic-grid"><?php foreach ( $checks as $check ) : ?><article class="mbapi-diagnostic-card is-<?php echo esc_attr( $check['status'] ); ?>"><span class="dashicons <?php echo 'good' === $check['status'] ? 'dashicons-yes-alt' : ( 'critical' === $check['status'] ? 'dashicons-warning' : 'dashicons-info-outline' ); ?>"></span><div><h3><?php echo esc_html( $check['label'] ); ?></h3><p><?php echo esc_html( $check['message'] ); ?></p></div><span class="mbapi-badge <?php echo 'good' === $check['status'] ? 'success' : ( 'critical' === $check['status'] ? 'error' : 'neutral' ); ?>"><?php echo esc_html( 'good' === $check['status'] ? __( 'Ready', 'mailbridgeapi-email-delivery' ) : ( 'critical' === $check['status'] ? __( 'Action required', 'mailbridgeapi-email-delivery' ) : __( 'Recommendation', 'mailbridgeapi-email-delivery' ) ) ); ?></span></article><?php endforeach; ?></div>
		<section class="mbapi-panel mbapi-report"><div class="mbapi-panel-heading"><div><span class="mbapi-eyebrow"><?php esc_html_e( 'Safe to share with support', 'mailbridgeapi-email-delivery' ); ?></span><h2><?php esc_html_e( 'Sanitized diagnostic report', 'mailbridgeapi-email-delivery' ); ?></h2><p><?php esc_html_e( 'The report excludes API keys, passwords, mailbox addresses, recipients and email contents.', 'mailbridgeapi-email-delivery' ); ?></p></div><button class="button button-primary" type="button" data-mbapi-copy="#mbapi-diagnostic-report" data-copied-label="<?php esc_attr_e( 'Copied', 'mailbridgeapi-email-delivery' ); ?>"><?php esc_html_e( 'Copy report', 'mailbridgeapi-email-delivery' ); ?></button></div><textarea id="mbapi-diagnostic-report" readonly rows="16"><?php echo esc_textarea( $report ); ?></textarea></section>
		<?php
		$this->page_end();
	}

	public function help_page(): void {
		$this->guard();
		$this->page_start( __( 'Documentation', 'mailbridgeapi-email-delivery' ), __( 'How this WordPress transport works.', 'mailbridgeapi-email-delivery' ) );
		?><div class="mbapi-grid mbapi-grid-2"><section class="mbapi-panel"><h2><?php esc_html_e( 'What the plugin intercepts', 'mailbridgeapi-email-delivery' ); ?></h2><p><?php esc_html_e( 'After a mailbox is selected, the plugin short-circuits wp_mail() and submits each message to MailBridgeAPI. This includes WordPress password messages, contact forms, WooCommerce transactional messages and compatible plugin notifications.', 'mailbridgeapi-email-delivery' ); ?></p><p><?php esc_html_e( 'If disconnected, WordPress returns to its original mail transport.', 'mailbridgeapi-email-delivery' ); ?></p></section><section class="mbapi-panel"><h2><?php esc_html_e( 'Supported mail data', 'mailbridgeapi-email-delivery' ); ?></h2><ul><li><?php esc_html_e( 'HTML and plain-text messages', 'mailbridgeapi-email-delivery' ); ?></li><li><?php esc_html_e( 'Reply-To and up to three visible CC recipients per API request', 'mailbridgeapi-email-delivery' ); ?></li><li><?php esc_html_e( 'Up to two attachments, maximum 5 MB each and 10 MB total', 'mailbridgeapi-email-delivery' ); ?></li><li><?php esc_html_e( 'Additional recipients are delivered as separate private requests', 'mailbridgeapi-email-delivery' ); ?></li></ul></section><section class="mbapi-panel"><h2><?php esc_html_e( 'Security model', 'mailbridgeapi-email-delivery' ); ?></h2><ul><li><?php esc_html_e( 'Only administrators can connect, test or disconnect.', 'mailbridgeapi-email-delivery' ); ?></li><li><?php esc_html_e( 'The dashboard password is never stored.', 'mailbridgeapi-email-delivery' ); ?></li><li><?php esc_html_e( 'Tokens and API keys are encrypted before database storage.', 'mailbridgeapi-email-delivery' ); ?></li><li><?php esc_html_e( 'The chosen mailbox remains hidden and locked until disconnect.', 'mailbridgeapi-email-delivery' ); ?></li></ul></section><section class="mbapi-panel"><h2><?php esc_html_e( 'Support and service documents', 'mailbridgeapi-email-delivery' ); ?></h2><p><a href="https://mailbridgeapi.com/doc/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'MailBridgeAPI documentation', 'mailbridgeapi-email-delivery' ); ?></a><br><a href="https://mailbridgeapi.com/privacy-policy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'mailbridgeapi-email-delivery' ); ?></a><br><a href="https://mailbridgeapi.com/terms-and-conditions-of-sale/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms and conditions', 'mailbridgeapi-email-delivery' ); ?></a><br><a href="https://mailbridgeapi.com/contact/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Contact support', 'mailbridgeapi-email-delivery' ); ?></a></p></section></div><?php
		$this->page_end();
	}

	public function handle_login(): void {
		$this->guard_post( 'mbapi_ed_login' );
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';
		if ( ! is_email( $email ) || '' === $password || strlen( $password ) > 200 ) {
			$this->redirect_notice( 'error', __( 'Enter a valid dashboard email address and password.', 'mailbridgeapi-email-delivery' ) );
		}
		$auth = $this->api->authenticate( $email, $password );
		$password = '';
		if ( is_wp_error( $auth ) ) {
			$this->redirect_notice( 'error', $auth->get_error_message() );
		}
		if ( empty( $auth['token'] ) || empty( $auth['account']['api_key'] ) ) {
			$this->redirect_notice( 'error', __( 'MailBridgeAPI authenticated the account but did not return the credentials required for email delivery.', 'mailbridgeapi-email-delivery' ) );
		}
		$mailboxes = $this->api->mailboxes( (string) $auth['token'] );
		if ( is_wp_error( $mailboxes ) ) {
			$this->api->logout( (string) $auth['token'] );
			$this->redirect_notice( 'error', $mailboxes->get_error_message() );
		}
		Plugin::save_pending(
			array(
				'token'               => (string) $auth['token'],
				'token_expires_at'    => time() + (int) ( $auth['expires_in'] ?? 2592000 ),
				'account_key'         => (string) ( $auth['account']['api_key'] ?? '' ),
				'account_email'       => (string) ( $auth['account']['email'] ?? $email ),
				'subscription_active' => ! empty( $auth['account']['subscription_active'] ),
				'subscription_end'    => (string) ( $auth['account']['subscription_end'] ?? '' ),
				'mailboxes'           => $mailboxes,
			)
		);
		$this->redirect_notice( 'success', __( 'Account verified. Select and test the mailbox reserved for this WordPress site.', 'mailbridgeapi-email-delivery' ) );
	}

	public function handle_select(): void {
		$this->guard_post( 'mbapi_ed_select' );
		$pending = Plugin::pending();
		$id      = isset( $_POST['mailbox_id'] ) ? absint( $_POST['mailbox_id'] ) : 0;
		if ( ! $pending || ! $id ) {
			$this->redirect_notice( 'error', __( 'The mailbox selection session expired. Sign in again.', 'mailbridgeapi-email-delivery' ) );
		}
		$selected = null;
		foreach ( (array) $pending['mailboxes'] as $mailbox ) {
			if ( (int) ( $mailbox['id'] ?? 0 ) === $id ) {
				$selected = $mailbox;
				break;
			}
		}
		if ( ! is_array( $selected ) || empty( $selected['api_key'] ) ) {
			$this->redirect_notice( 'error', __( 'This mailbox is not available to the authenticated account.', 'mailbridgeapi-email-delivery' ) );
		}
		if ( empty( $selected['smtp_host'] ) ) {
			$this->redirect_notice( 'error', __( 'Configure SMTP for this mailbox before using it as the WordPress email transport.', 'mailbridgeapi-email-delivery' ) );
		}
		Plugin::save_connection(
			array(
				'token'               => (string) $pending['token'],
				'token_expires_at'    => (int) $pending['token_expires_at'],
				'account_key'         => (string) $pending['account_key'],
				'account_email'       => (string) $pending['account_email'],
				'subscription_active' => ! empty( $pending['subscription_active'] ),
				'subscription_end'    => (string) $pending['subscription_end'],
				'mailbox_id'          => $id,
				'mailbox_key'         => (string) $selected['api_key'],
				'mailbox_name'        => sanitize_text_field( (string) $selected['name'] ),
				'mailbox_email'       => sanitize_email( (string) $selected['email'] ),
				'smtp_host'           => sanitize_text_field( (string) ( $selected['smtp_host'] ?? '' ) ),
				'imap_host'           => sanitize_text_field( (string) ( $selected['imap_host'] ?? '' ) ),
				'connected_at'        => time(),
			)
		);
		Plugin::clear_pending();
		$this->redirect_notice( 'success', __( 'Mailbox connected. WordPress email now uses MailBridgeAPI.', 'mailbridgeapi-email-delivery' ) );
	}

	public function handle_test(): void {
		$this->guard_post( 'mbapi_ed_test' );
		$protocol = isset( $_POST['protocol'] ) ? sanitize_key( wp_unslash( $_POST['protocol'] ) ) : '';
		$scope    = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$id       = isset( $_POST['mailbox_id'] ) ? absint( $_POST['mailbox_id'] ) : 0;
		if ( ! in_array( $protocol, array( 'smtp', 'imap' ), true ) || ! $id ) {
			$this->redirect_notice( 'error', __( 'Choose a valid mailbox test.', 'mailbridgeapi-email-delivery' ) );
		}
		if ( ! in_array( $scope, array( 'pending', 'connected' ), true ) ) {
			$this->redirect_notice( 'error', __( 'Choose a valid mailbox test.', 'mailbridgeapi-email-delivery' ) );
		}
		$data = 'pending' === $scope ? Plugin::pending() : Plugin::connection();
		if ( 'connected' === $scope && (int) ( $data['mailbox_id'] ?? 0 ) !== $id ) {
			$this->redirect_notice( 'error', __( 'The requested mailbox is not connected to this WordPress site.', 'mailbridgeapi-email-delivery' ) );
		}
		if ( 'pending' === $scope ) {
			$allowed = false;
			foreach ( (array) ( $data['mailboxes'] ?? array() ) as $mailbox ) {
				if ( (int) ( $mailbox['id'] ?? 0 ) === $id ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				$this->redirect_notice( 'error', __( 'The requested mailbox is not available in this connection session.', 'mailbridgeapi-email-delivery' ) );
			}
		}
		$token = (string) ( $data['token'] ?? '' );
		if ( '' === $token ) {
			$this->redirect_notice( 'error', __( 'The secure dashboard session expired. Disconnect and sign in again.', 'mailbridgeapi-email-delivery' ) );
		}
		$result = $this->api->test_mailbox( $token, $id, $protocol );
		if ( is_wp_error( $result ) ) {
			$message = sanitize_text_field( $result->get_error_message() );
			$this->remember_test_result( $scope, $protocol, $id, false, $message );
			$this->redirect_notice( 'error', $message );
		}
		$test = is_array( $result[ $protocol ] ?? null ) ? $result[ $protocol ] : array();
		$ok      = ! empty( $test['ok'] );
		$message = sanitize_text_field( (string) ( $test['message'] ?? __( 'The connection test did not return a result.', 'mailbridgeapi-email-delivery' ) ) );
		$this->remember_test_result( $scope, $protocol, $id, $ok, $message );
		$this->redirect_notice( $ok ? 'success' : 'error', $message );
	}

	public function handle_disconnect(): void {
		$this->guard_post( 'mbapi_ed_disconnect' );
		$connection = Plugin::connection();
		if ( ! empty( $connection['token'] ) ) {
			$this->api->logout( (string) $connection['token'] );
		}
		Plugin::clear_connection();
		Plugin::clear_pending();
		delete_user_meta( get_current_user_id(), 'mbapi_ed_test_results' );
		delete_user_meta( get_current_user_id(), 'mbapi_ed_delivery_test_result' );
		$this->redirect_notice( 'success', __( 'MailBridgeAPI was disconnected. WordPress has returned to its original mail transport.', 'mailbridgeapi-email-delivery' ) );
	}

	public function handle_settings(): void {
		$this->guard_post( 'mbapi_ed_settings' );
		$days = isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 90;
		if ( ! in_array( $days, array( 30, 90, 180, 365 ), true ) ) {
			$days = 90;
		}
		$delete_on_uninstall = isset( $_POST['delete_on_uninstall'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['delete_on_uninstall'] ) );
		Plugin::save_settings( array( 'retention_days' => $days, 'delete_on_uninstall' => $delete_on_uninstall ) );
		$this->logger->cleanup();
		$this->redirect_notice( 'success', __( 'MailBridgeAPI settings saved.', 'mailbridgeapi-email-delivery' ) );
	}

	public function handle_send_test(): void {
		$this->guard_post( 'mbapi_ed_send_test' );
		$connection = Plugin::connection();
		$recipient  = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		if ( ! is_email( $recipient ) ) {
			$this->redirect_notice( 'error', __( 'Enter a valid destination email address.', 'mailbridgeapi-email-delivery' ) );
		}
		if ( ! $connection || empty( $connection['smtp_host'] ) ) {
			$this->redirect_notice( 'error', __( 'Connect a mailbox with SMTP configured before sending a test email.', 'mailbridgeapi-email-delivery' ) );
		}
		if ( empty( $connection['subscription_active'] ) ) {
			$this->redirect_notice( 'error', __( 'Renew the MailBridgeAPI subscription before sending a test email.', 'mailbridgeapi-email-delivery' ) );
		}
		/* translators: 1: website host name, 2: UTC date and time. */
		$subject = sprintf( __( 'MailBridgeAPI delivery test · %1$s · %2$s UTC', 'mailbridgeapi-email-delivery' ), wp_parse_url( home_url( '/' ), PHP_URL_HOST ), gmdate( 'Y-m-d H:i:s' ) );
		/* translators: %s: website home URL. */
		$body    = sprintf( __( "This is a real MailBridgeAPI delivery test from %s.\n\nWordPress submitted this message to MailBridgeAPI and the selected SMTP server accepted it.\n\nNo action is required.", 'mailbridgeapi-email-delivery' ), home_url( '/' ) );
		$sent    = wp_mail( $recipient, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		$log     = $this->logger->latest_for( $recipient, $subject );
		$ok      = $sent && 'success' === (string) ( $log['status'] ?? '' );
		$message = $log ? (string) $log['result_message'] : __( 'No MailBridgeAPI delivery record was created. Another plugin may have intercepted wp_mail(). Review the Diagnostics page.', 'mailbridgeapi-email-delivery' );
		update_user_meta(
			get_current_user_id(),
			'mbapi_ed_delivery_test_result',
			array(
				'ok'         => $ok,
				'message'    => sanitize_text_field( $message ),
				'remote_id'  => sanitize_text_field( (string) ( $log['remote_id'] ?? '' ) ),
				'tested_at'  => time(),
			)
		);
		$this->redirect_notice( $ok ? 'success' : 'error', $ok ? __( 'The real test email was accepted for delivery.', 'mailbridgeapi-email-delivery' ) : $message );
	}

	public function handle_clear_logs(): void {
		$this->guard_post( 'mbapi_ed_clear_logs' );
		$count = $this->logger->clear();
		/* translators: %d: number of deleted local email delivery log rows. */
		$this->redirect_notice( 'success', sprintf( _n( '%d local delivery log was deleted.', '%d local delivery logs were deleted.', $count, 'mailbridgeapi-email-delivery' ), $count ), self::SLUG . '-logs' );
	}

	public function handle_export_logs(): void {
		$this->guard_post( 'mbapi_ed_export_logs' );
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="mailbridgeapi-email-log-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $output ) {
			wp_die( esc_html__( 'The CSV export could not be created.', 'mailbridgeapi-email-delivery' ) );
		}
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $output, array( 'recipient', 'subject', 'status', 'result_code', 'result_message', 'message_id', 'created_at_utc' ) );
		$offset = 0;
		do {
			$rows = $this->logger->export_rows( $status, $search, 1000, $offset );
			foreach ( $rows as $row ) {
				fputcsv( $output, array_map( array( $this, 'csv_cell' ), array( $row['recipient'], $row['subject'], $row['status'], $row['result_code'], $row['result_message'], $row['remote_id'], $row['created_at'] ) ) );
			}
			$offset += count( $rows );
		} while ( 1000 === count( $rows ) );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private function csv_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	public function handle_cancel_pending(): void {
		$this->guard_post( 'mbapi_ed_cancel_pending' );
		$pending = Plugin::pending();
		if ( ! empty( $pending['token'] ) ) {
			$this->api->logout( (string) $pending['token'] );
		}
		Plugin::clear_pending();
		delete_user_meta( get_current_user_id(), 'mbapi_ed_test_results' );
		$this->redirect_notice( 'success', __( 'Mailbox selection cancelled. No mailbox was connected.', 'mailbridgeapi-email-delivery' ) );
	}

	private function test_result_key( string $scope, string $protocol, int $mailbox_id ): string {
		return sanitize_key( $scope . '_' . $mailbox_id . '_' . $protocol );
	}

	/** @return array<string,mixed> */
	private function test_result( string $scope, string $protocol, int $mailbox_id ): array {
		$results = get_user_meta( get_current_user_id(), 'mbapi_ed_test_results', true );
		$key     = $this->test_result_key( $scope, $protocol, $mailbox_id );
		return is_array( $results ) && is_array( $results[ $key ] ?? null ) ? $results[ $key ] : array();
	}

	private function remember_test_result( string $scope, string $protocol, int $mailbox_id, bool $ok, string $message ): void {
		$results = get_user_meta( get_current_user_id(), 'mbapi_ed_test_results', true );
		$results = is_array( $results ) ? $results : array();
		$results[ $this->test_result_key( $scope, $protocol, $mailbox_id ) ] = array(
			'ok'        => $ok,
			'message'   => $message,
			'tested_at' => time(),
		);
		update_user_meta( get_current_user_id(), 'mbapi_ed_test_results', $results );
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage MailBridgeAPI.', 'mailbridgeapi-email-delivery' ), esc_html__( 'Access denied', 'mailbridgeapi-email-delivery' ), array( 'response' => 403 ) );
		}
	}

	private function guard_post( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage MailBridgeAPI.', 'mailbridgeapi-email-delivery' ), esc_html__( 'Access denied', 'mailbridgeapi-email-delivery' ), array( 'response' => 403 ) );
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_nonce_ays( $action );
		}
	}

	private function redirect_notice( string $type, string $message, string $page = self::SLUG ): void {
		set_transient( 'mbapi_ed_notice_' . get_current_user_id(), array( 'type' => 'success' === $type ? 'success' : 'error', 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	private function notice(): void {
		$key    = 'mbapi_ed_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		if ( is_array( $notice ) ) {
			?><div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( (string) $notice['message'] ); ?></p></div><?php
		}
	}

	private function page_start( string $title, string $subtitle ): void {
		?><div class="wrap mbapi-wrap"><header class="mbapi-header"><div class="mbapi-logo"><span class="dashicons dashicons-email-alt2"></span></div><div><span class="mbapi-eyebrow">MailBridgeAPI for WordPress</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $subtitle ); ?></p></div></header><?php
	}

	private function page_end(): void {
		?></div><?php
	}

	/** @param array<string,mixed> $data Pagination data. */
	private function pagination( array $data, string $status, string $search ): void {
		if ( (int) $data['pages'] <= 1 ) {
			return;
		}
		$base = add_query_arg( array( 'page' => self::SLUG . '-logs', 'status' => $status, 's' => $search, 'paged' => '%#%' ), admin_url( 'admin.php' ) );
		$links = paginate_links( array( 'base' => $base, 'format' => '', 'current' => (int) $data['page'], 'total' => (int) $data['pages'] ) );
		if ( is_string( $links ) ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}
}

