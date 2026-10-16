<?php
/**
 * MailBridgeAPI HTTP client.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

namespace MailBridgeAPI\EmailDelivery;

defined( 'ABSPATH' ) || exit;

final class Api_Client {
	private const DASHBOARD_API = 'https://dashboard.mailbridgeapi.com/api/mobile/v1/';
	private const SEND_API      = 'https://api.mailbridgeapi.com/sendemail/';

	/** @return array<string,mixed>|\WP_Error */
	public function authenticate( string $email, string $password ) {
		return $this->dashboard_request(
			'auth/login/',
			'POST',
			array(
				'login_method'         => 'email',
				'email'                => $email,
				'password'             => $password,
				'device_name'          => 'WordPress · ' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'app_version'          => MBAPI_ED_VERSION,
				'locale'               => determine_locale(),
				'notifications_enabled' => false,
			),
			''
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function mailboxes( string $token ) {
		return $this->dashboard_request( 'mailboxes/', 'GET', null, $token );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function test_mailbox( string $token, int $mailbox_id, string $protocol ) {
		return $this->dashboard_request(
			'mailboxes/' . $mailbox_id . '/test/',
			'POST',
			array( 'protocol' => $protocol ),
			$token
		);
	}

	public function logout( string $token ): void {
		$this->dashboard_request( 'auth/logout/', 'POST', array(), $token );
	}

	/** @return array{ok:bool,message:string,response_ms:int} */
	public function connectivity(): array {
		$started  = microtime( true );
		$response = wp_safe_remote_get(
			self::SEND_API,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'User-Agent' => $this->user_agent() ),
			)
		);
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => __( 'The API is unreachable. Check outbound HTTPS access, DNS and the hosting firewall.', 'mailbridgeapi-email-delivery' ), 'response_ms' => $elapsed );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		/* translators: %d: HTTP response status code. */
		$error_message = sprintf( __( 'The API returned HTTP %d. Try again or contact MailBridgeAPI support.', 'mailbridgeapi-email-delivery' ), $status );
		return array(
			'ok'          => $status >= 200 && $status < 500,
			'message'     => $status >= 200 && $status < 500 ? __( 'The MailBridgeAPI endpoint is reachable over HTTPS.', 'mailbridgeapi-email-delivery' ) : $error_message,
			'response_ms' => $elapsed,
		);
	}

	/**
	 * Send one email request.
	 *
	 * @param array<string,string> $payload Form payload.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function send( array $payload ) {
		$response = wp_safe_remote_post(
			self::SEND_API,
			array(
				'timeout'     => 45,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => $this->user_agent(),
				),
				'body'        => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'mbapi_network_error', __( 'MailBridgeAPI could not be reached. Check outbound HTTPS access, DNS and the hosting firewall, then try again.', 'mailbridgeapi-email-delivery' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return new \WP_Error( 'mbapi_invalid_response', __( 'MailBridgeAPI returned an unexpected response.', 'mailbridgeapi-email-delivery' ) );
		}

		$error  = (string) ( $data['result']['error'] ?? '1' );
		$reason = sanitize_text_field( (string) ( $data['result']['sent'] ?? __( 'Email delivery failed.', 'mailbridgeapi-email-delivery' ) ) );
		if ( '0' !== $error ) {
			return new \WP_Error( 'mbapi_delivery_failed', $this->delivery_error_message( $reason ), array( 'remote_code' => $error, 'remote_reason' => $reason ) );
		}

		return array(
			'id'      => sanitize_text_field( (string) ( $data['result']['id'] ?? '' ) ),
			'code'    => $error,
			'message' => $reason,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function dashboard_request( string $path, string $method, ?array $body, string $token ) {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => $this->user_agent(),
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$response = wp_safe_remote_request(
			self::DASHBOARD_API . ltrim( $path, '/' ),
			array(
				'method'      => $method,
				'timeout'     => 30,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => $headers,
				'body'        => null === $body ? null : wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'mbapi_network_error', __( 'The secure MailBridgeAPI dashboard could not be reached.', 'mailbridgeapi-email-delivery' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'mbapi_invalid_response', __( 'MailBridgeAPI returned an unreadable response.', 'mailbridgeapi-email-delivery' ) );
		}
		if ( $status < 200 || $status >= 300 || empty( $data['ok'] ) ) {
			$message = sanitize_text_field( (string) ( $data['error']['message'] ?? __( 'The MailBridgeAPI request failed.', 'mailbridgeapi-email-delivery' ) ) );
			$code    = sanitize_key( (string) ( $data['error']['code'] ?? 'request_failed' ) );
			return new \WP_Error( 'mbapi_' . $code, $message, array( 'status' => $status ) );
		}

		return is_array( $data['data'] ?? null ) ? $data['data'] : array();
	}

	private function user_agent(): string {
		return 'MailBridgeAPI-WordPress/' . MBAPI_ED_VERSION . '; ' . home_url( '/' );
	}

	private function delivery_error_message( string $reason ): string {
		$key = strtolower( trim( $reason ) );
		$messages = array(
			'api_missing'          => __( 'The API credentials are missing or invalid. Disconnect and reconnect the mailbox.', 'mailbridgeapi-email-delivery' ),
			'apikey_missing'       => __( 'The account API key is missing. Disconnect and reconnect the mailbox.', 'mailbridgeapi-email-delivery' ),
			'apikeybox_missing'    => __( 'The mailbox API key is missing. Disconnect and reconnect the mailbox.', 'mailbridgeapi-email-delivery' ),
			'subscription_expired' => __( 'The MailBridgeAPI subscription has expired. Renew it before sending email.', 'mailbridgeapi-email-delivery' ),
			'smtp_missing'         => __( 'SMTP is not configured for this mailbox. Complete the SMTP settings in the MailBridgeAPI dashboard.', 'mailbridgeapi-email-delivery' ),
			'smtp_error'           => __( 'The SMTP server rejected the message. Test SMTP and verify the host, port, security mode and credentials.', 'mailbridgeapi-email-delivery' ),
			'recipient_invalid'    => __( 'The recipient address was rejected. Verify the destination email address.', 'mailbridgeapi-email-delivery' ),
		);
		return $messages[ $key ] ?? ( $reason . ' ' . __( 'Review the mailbox configuration and the MailBridgeAPI activity log for details.', 'mailbridgeapi-email-delivery' ) );
	}
}

