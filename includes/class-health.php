<?php
/**
 * Diagnostics and WordPress Site Health integration.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

namespace MailBridgeAPI\EmailDelivery;

defined( 'ABSPATH' ) || exit;

final class Health {
	private Api_Client $api;
	private Logger $logger;

	public function __construct( Api_Client $api, Logger $logger ) {
		$this->api    = $api;
		$this->logger = $logger;
	}

	/** @return array<int,array<string,string>> */
	public function checks(): array {
		global $wp_version;
		$connection = Plugin::connection();
		/* translators: %s: current PHP version. */
		$php_message = sprintf( __( 'PHP %s is active. Version 7.4 or newer is required.', 'mailbridgeapi-email-delivery' ), PHP_VERSION );
		/* translators: %s: current WordPress version. */
		$wp_message = sprintf( __( 'WordPress %s is active. Version 6.5 or newer is recommended.', 'mailbridgeapi-email-delivery' ), (string) $wp_version );
		$checks     = array(
			$this->check( 'php', __( 'PHP version', 'mailbridgeapi-email-delivery' ), version_compare( PHP_VERSION, '7.4', '>=' ) ? 'good' : 'critical', $php_message ),
			$this->check( 'wordpress', __( 'WordPress version', 'mailbridgeapi-email-delivery' ), version_compare( (string) $wp_version, '6.5', '>=' ) ? 'good' : 'recommended', $wp_message ),
			$this->check( 'openssl', __( 'Credential encryption', 'mailbridgeapi-email-delivery' ), extension_loaded( 'openssl' ) ? 'good' : 'critical', extension_loaded( 'openssl' ) ? __( 'OpenSSL is available for encrypted local credentials.', 'mailbridgeapi-email-delivery' ) : __( 'OpenSSL is missing. The plugin cannot protect stored API credentials.', 'mailbridgeapi-email-delivery' ) ),
			$this->check( 'curl', __( 'HTTP transport', 'mailbridgeapi-email-delivery' ), function_exists( 'curl_init' ) ? 'good' : 'recommended', function_exists( 'curl_init' ) ? __( 'cURL is available for HTTPS requests.', 'mailbridgeapi-email-delivery' ) : __( 'cURL is unavailable. WordPress may use another HTTP transport, but cURL is recommended.', 'mailbridgeapi-email-delivery' ) ),
		);

		$connectivity = $this->api->connectivity();
		/* translators: %d: MailBridgeAPI HTTP response time in milliseconds. */
		$response_time = sprintf( __( 'Response time: %d ms.', 'mailbridgeapi-email-delivery' ), $connectivity['response_ms'] );
		$checks[]     = $this->check( 'api', __( 'MailBridgeAPI availability', 'mailbridgeapi-email-delivery' ), $connectivity['ok'] ? 'good' : 'critical', $connectivity['message'] . ' ' . $response_time );

		if ( ! $connection ) {
			$checks[] = $this->check( 'connection', __( 'Mailbox connection', 'mailbridgeapi-email-delivery' ), 'critical', __( 'No mailbox is connected. Open MailBridgeAPI → Connection to sign in and select one.', 'mailbridgeapi-email-delivery' ) );
		} else {
			$checks[] = $this->check( 'connection', __( 'Mailbox connection', 'mailbridgeapi-email-delivery' ), 'good', __( 'A mailbox is securely connected to this site.', 'mailbridgeapi-email-delivery' ) );
			$checks[] = $this->check( 'subscription', __( 'Subscription', 'mailbridgeapi-email-delivery' ), ! empty( $connection['subscription_active'] ) ? 'good' : 'critical', ! empty( $connection['subscription_active'] ) ? __( 'The stored MailBridgeAPI subscription status is active.', 'mailbridgeapi-email-delivery' ) : __( 'The subscription is expired. Renew it before sending email.', 'mailbridgeapi-email-delivery' ) );
			$checks[] = $this->mailbox_check( $connection );
		}

		$conflicts = $this->conflicts();
		/* translators: %s: comma-separated list of potentially conflicting plugin slugs. */
		$conflict_message = sprintf( __( 'Potential conflict detected: %s. Keep only one wp_mail transport active.', 'mailbridgeapi-email-delivery' ), implode( ', ', $conflicts ) );
		$checks[]  = $this->check( 'conflicts', __( 'Other email delivery plugins', 'mailbridgeapi-email-delivery' ), $conflicts ? 'recommended' : 'good', $conflicts ? $conflict_message : __( 'No known competing SMTP or email delivery plugin is active.', 'mailbridgeapi-email-delivery' ) );

		$last = $this->logger->last_success();
		/* translators: %s: UTC date and time of the latest successful delivery. */
		$last_message = $last ? sprintf( __( 'The latest successful delivery was recorded at %s UTC.', 'mailbridgeapi-email-delivery' ), (string) $last['created_at'] ) : '';
		$checks[] = $this->check( 'last_delivery', __( 'Last successful delivery', 'mailbridgeapi-email-delivery' ), $last ? 'good' : 'recommended', $last ? $last_message : __( 'No successful delivery has been recorded yet. Send a real test email from the Connection page.', 'mailbridgeapi-email-delivery' ) );
		return $checks;
	}

	/** @return array<int,string> */
	public function conflicts(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$patterns = array( 'wp-mail-smtp', 'post-smtp', 'postman-smtp', 'fluent-smtp', 'easy-wp-smtp', 'smtp-mailer', 'wp-offload-ses', 'wp-ses', 'mailgun', 'sendgrid', 'brevo', 'sparkpost', 'mailjet' );
		$found = array();
		foreach ( array_unique( $active ) as $plugin ) {
			$slug = strtolower( (string) $plugin );
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $slug, $pattern ) && false === strpos( $slug, 'mailbridgeapi-email-delivery' ) ) {
					$found[] = dirname( (string) $plugin );
					break;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/** @param array<int,array<string,string>>|null $checks */
	public function report( ?array $checks = null ): string {
		global $wp_version;
		$checks = null === $checks ? $this->checks() : $checks;
		$lines  = array(
			'MailBridgeAPI Email Delivery diagnostic report',
			'Generated (UTC): ' . gmdate( 'Y-m-d H:i:s' ),
			'Site host: ' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'Plugin version: ' . MBAPI_ED_VERSION,
			'WordPress version: ' . (string) $wp_version,
			'PHP version: ' . PHP_VERSION,
			'',
		);
		foreach ( $checks as $check ) {
			$lines[] = '[' . strtoupper( $check['status'] ) . '] ' . $check['label'] . ': ' . $check['message'];
		}
		$lines[] = '';
		$lines[] = 'Secrets, API keys, passwords, recipient addresses and email contents are excluded.';
		return implode( "\n", $lines );
	}

	/** @param array<string,mixed> $tests @return array<string,mixed> */
	public function site_status_tests( array $tests ): array {
		$tests['direct']['mailbridgeapi_email_delivery'] = array(
			'label' => __( 'MailBridgeAPI email delivery', 'mailbridgeapi-email-delivery' ),
			'test'  => array( $this, 'site_health_test' ),
		);
		return $tests;
	}

	/** @return array<string,mixed> */
	public function site_health_test(): array {
		$checks   = $this->checks();
		$critical = array_filter( $checks, static fn( array $check ): bool => 'critical' === $check['status'] );
		$warning  = array_filter( $checks, static fn( array $check ): bool => 'recommended' === $check['status'] );
		$status   = $critical ? 'critical' : ( $warning ? 'recommended' : 'good' );
		$label    = $critical ? __( 'MailBridgeAPI requires attention', 'mailbridgeapi-email-delivery' ) : ( $warning ? __( 'MailBridgeAPI has recommendations', 'mailbridgeapi-email-delivery' ) : __( 'MailBridgeAPI is ready to deliver email', 'mailbridgeapi-email-delivery' ) );
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => 'MailBridgeAPI', 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $critical ? reset( $critical )['message'] : ( $warning ? reset( $warning )['message'] : __( 'The connection, runtime and API checks completed successfully.', 'mailbridgeapi-email-delivery' ) ) ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=mailbridgeapi-email-delivery-diagnostics' ) ) . '">' . esc_html__( 'Open MailBridgeAPI diagnostics', 'mailbridgeapi-email-delivery' ) . '</a></p>',
			'test'        => 'mailbridgeapi_email_delivery',
		);
	}

	/** @param array<string,mixed> $info @return array<string,mixed> */
	public function debug_information( array $info ): array {
		$connection = Plugin::connection();
		$info['mailbridgeapi-email-delivery'] = array(
			'label'  => 'MailBridgeAPI Email Delivery',
			'fields' => array(
				'version'      => array( 'label' => __( 'Plugin version', 'mailbridgeapi-email-delivery' ), 'value' => MBAPI_ED_VERSION ),
				'connected'    => array( 'label' => __( 'Mailbox connected', 'mailbridgeapi-email-delivery' ), 'value' => $connection ? __( 'Yes', 'mailbridgeapi-email-delivery' ) : __( 'No', 'mailbridgeapi-email-delivery' ) ),
				'subscription' => array( 'label' => __( 'Subscription active', 'mailbridgeapi-email-delivery' ), 'value' => ! empty( $connection['subscription_active'] ) ? __( 'Yes', 'mailbridgeapi-email-delivery' ) : __( 'No', 'mailbridgeapi-email-delivery' ) ),
				'openssl'      => array( 'label' => 'OpenSSL', 'value' => extension_loaded( 'openssl' ) ? __( 'Available', 'mailbridgeapi-email-delivery' ) : __( 'Missing', 'mailbridgeapi-email-delivery' ) ),
				'curl'         => array( 'label' => 'cURL', 'value' => function_exists( 'curl_init' ) ? __( 'Available', 'mailbridgeapi-email-delivery' ) : __( 'Missing', 'mailbridgeapi-email-delivery' ) ),
			),
		);
		return $info;
	}

	/** @param array<string,mixed> $connection @return array<string,string> */
	private function mailbox_check( array $connection ): array {
		$token = (string) ( $connection['token'] ?? '' );
		if ( '' === $token ) {
			return $this->check( 'mailbox', __( 'Selected mailbox availability', 'mailbridgeapi-email-delivery' ), 'critical', __( 'The secure session is missing. Disconnect and reconnect the mailbox.', 'mailbridgeapi-email-delivery' ) );
		}
		$mailboxes = $this->api->mailboxes( $token );
		if ( is_wp_error( $mailboxes ) ) {
			return $this->check( 'mailbox', __( 'Selected mailbox availability', 'mailbridgeapi-email-delivery' ), 'critical', $mailboxes->get_error_message() . ' ' . __( 'Reconnect the mailbox if the secure session has expired.', 'mailbridgeapi-email-delivery' ) );
		}
		foreach ( $mailboxes as $mailbox ) {
			if ( (int) ( $mailbox['id'] ?? 0 ) === (int) ( $connection['mailbox_id'] ?? 0 ) ) {
				return $this->check( 'mailbox', __( 'Selected mailbox availability', 'mailbridgeapi-email-delivery' ), ! empty( $mailbox['smtp_host'] ) ? 'good' : 'critical', ! empty( $mailbox['smtp_host'] ) ? __( 'The selected mailbox is available and has SMTP configured.', 'mailbridgeapi-email-delivery' ) : __( 'The selected mailbox exists but SMTP is not configured.', 'mailbridgeapi-email-delivery' ) );
			}
		}
		return $this->check( 'mailbox', __( 'Selected mailbox availability', 'mailbridgeapi-email-delivery' ), 'critical', __( 'The selected mailbox is no longer available to this account. Disconnect and select another mailbox.', 'mailbridgeapi-email-delivery' ) );
	}

	/** @return array<string,string> */
	private function check( string $id, string $label, string $status, string $message ): array {
		return array( 'id' => $id, 'label' => $label, 'status' => $status, 'message' => $message );
	}
}

