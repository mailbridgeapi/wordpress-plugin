<?php
/**
 * wp_mail() transport replacement.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

namespace MailBridgeAPI\EmailDelivery;

defined( 'ABSPATH' ) || exit;

final class Mailer {
	private Api_Client $api;
	private Logger $logger;

	public function __construct( Api_Client $api, Logger $logger ) {
		$this->api    = $api;
		$this->logger = $logger;
	}

	/**
	 * Short-circuit wp_mail when a MailBridgeAPI mailbox is selected.
	 *
	 * @param null|bool          $return Existing short-circuit result.
	 * @param array<string,mixed> $atts Mail arguments.
	 * @return null|bool
	 */
	public function intercept( $return, array $atts ) {
		if ( null !== $return ) {
			return $return;
		}

		$connection = Plugin::connection();
		if ( empty( $connection['mailbox_key'] ) || empty( $connection['account_key'] ) ) {
			return null;
		}

		$subject = sanitize_text_field( (string) ( $atts['subject'] ?? '' ) );
		$message = (string) ( $atts['message'] ?? '' );
		$headers = $this->parse_headers( $atts['headers'] ?? array() );
		$to      = $this->addresses( $atts['to'] ?? array() );
		$cc      = $this->addresses( $headers['cc'] ?? array() );
		$bcc     = $this->addresses( $headers['bcc'] ?? array() );

		if ( ! $to ) {
			return $this->fail( $atts, '', $subject, 'invalid_recipient', __( 'No valid recipient address was supplied.', 'mailbridgeapi-email-delivery' ) );
		}

		$embeds = is_array( $atts['embeds'] ?? null ) ? $atts['embeds'] : array();
		if ( $embeds ) {
			return $this->fail( $atts, $to[0], $subject, 'unsupported_embeds', __( 'Embedded wp_mail images are not supported by this transport. Use hosted image URLs or regular attachments.', 'mailbridgeapi-email-delivery' ) );
		}

		$attachments = $this->attachments( $atts['attachments'] ?? array() );
		if ( is_wp_error( $attachments ) ) {
			return $this->fail( $atts, $to[0], $subject, $attachments->get_error_code(), $attachments->get_error_message() );
		}

		$visible = array_values( array_unique( array_merge( array_slice( $to, 1 ), $cc ) ) );
		$copies  = array_values( array_unique( array_merge( array_slice( $visible, 3 ), $bcc ) ) );
		$primary = $to[0];
		$batch   = array(
			array(
				'recipient' => $primary,
				'cc'        => array_slice( $visible, 0, 3 ),
			),
		);
		foreach ( $copies as $recipient ) {
			if ( $recipient !== $primary && ! in_array( $recipient, $batch[0]['cc'], true ) ) {
				$batch[] = array( 'recipient' => $recipient, 'cc' => array() );
			}
		}
		if ( count( $batch ) > 100 ) {
			return $this->fail( $atts, $primary, $subject, 'too_many_recipients', __( 'A maximum of 100 recipients is allowed per wp_mail call.', 'mailbridgeapi-email-delivery' ) );
		}

		$format      = false !== strpos( strtolower( (string) ( $headers['content_type'] ?? '' ) ), 'text/html' ) ? 'html' : 'text';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is the canonical WordPress wp_mail sender-name filter.
		$default_sender_name = apply_filters( 'wp_mail_from_name', get_bloginfo( 'name' ) );
		$sender_name         = sanitize_text_field( (string) ( $headers['from_name'] ?? $default_sender_name ) );
		$reply_email = sanitize_email( (string) ( $headers['reply_email'] ?? '' ) );
		$reply_name  = sanitize_text_field( (string) ( $headers['reply_name'] ?? '' ) );
		$source      = sanitize_key( (string) apply_filters( 'mailbridgeapi_email_delivery_source', 'wordpress', $atts ) );
		$source      = '' !== $source ? $source : 'wordpress';
		$successful  = true;

		foreach ( $batch as $delivery ) {
			$payload = array(
				'apikey'          => (string) $connection['account_key'],
				'apikeybox'       => (string) $connection['mailbox_key'],
				'recipient_email' => $delivery['recipient'],
				'sender_name'     => $sender_name ?: (string) $connection['mailbox_name'],
				'sender_email'    => (string) $connection['mailbox_email'],
				'mail_subject'    => $subject,
				'mail_body'       => $message,
				'format'          => $format,
				'charset'         => 'UTF-8',
				'from_source'     => $source,
			);
			if ( '' !== $reply_email ) {
				$payload['reply_email'] = $reply_email;
				$payload['reply_name']  = $reply_name ?: $reply_email;
			}
			foreach ( $delivery['cc'] as $index => $cc_email ) {
				$payload[ 'email_cc' . ( $index + 1 ) ] = $cc_email;
			}
			foreach ( $attachments as $index => $attachment ) {
				$number = $index + 1;
				$payload[ 'attachmentBase64_' . $number ]      = $attachment['data'];
				$payload[ 'attachmentBase64_name_' . $number ] = $attachment['name'];
			}

			$result = $this->api->send( $payload );
			if ( is_wp_error( $result ) ) {
				$successful = false;
				$this->logger->add( $delivery['recipient'], $subject, 'error', $result->get_error_code(), $result->get_error_message() );
			} else {
				$this->logger->add( $delivery['recipient'], $subject, 'success', (string) $result['code'], (string) $result['message'], (string) $result['id'] );
			}
		}

		if ( ! $successful ) {
			$error = new \WP_Error( 'mbapi_partial_or_failed', __( 'MailBridgeAPI could not deliver one or more recipients. Review MailBridgeAPI → Sent emails.', 'mailbridgeapi-email-delivery' ) );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core wp_mail compatibility action.
			do_action( 'wp_mail_failed', $error );
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core wp_mail compatibility action.
		do_action( 'wp_mail_succeeded', $atts );
		return true;
	}

	/** @return array<string,mixed> */
	private function parse_headers( $raw_headers ): array {
		$lines  = is_array( $raw_headers ) ? $raw_headers : preg_split( '/\r?\n/', (string) $raw_headers );
		$parsed = array( 'cc' => array(), 'bcc' => array() );
		foreach ( (array) $lines as $line ) {
			if ( ! is_string( $line ) || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			switch ( strtolower( $name ) ) {
				case 'content-type':
					$parsed['content_type'] = sanitize_text_field( $value );
					break;
				case 'cc':
				case 'bcc':
					$parsed[ strtolower( $name ) ] = array_merge( $parsed[ strtolower( $name ) ], $this->addresses( $value ) );
					break;
				case 'reply-to':
					$reply                  = $this->address_with_name( $value );
					$parsed['reply_email'] = $reply['email'];
					$parsed['reply_name']  = $reply['name'];
					break;
				case 'from':
					$from                = $this->address_with_name( $value );
					$parsed['from_name'] = $from['name'];
					break;
			}
		}
		return $parsed;
	}

	/** @return array<int,string> */
	private function addresses( $value ): array {
		$parts = is_array( $value ) ? $value : preg_split( '/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', (string) $value );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$address = $this->address_with_name( (string) $part );
			if ( is_email( $address['email'] ) ) {
				$out[] = strtolower( $address['email'] );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return array{email:string,name:string} */
	private function address_with_name( string $value ): array {
		$value = trim( $value );
		if ( preg_match( '/^(.*?)<([^>]+)>$/', $value, $match ) ) {
			return array( 'email' => sanitize_email( trim( $match[2] ) ), 'name' => sanitize_text_field( trim( $match[1], " \t\n\r\0\x0B\"'" ) ) );
		}
		return array( 'email' => sanitize_email( $value ), 'name' => '' );
	}

	/** @return array<int,array{name:string,data:string}>|\WP_Error */
	private function attachments( $value ) {
		$files = is_array( $value ) ? $value : preg_split( '/\r?\n/', (string) $value );
		$files = array_values( array_filter( array_map( 'trim', (array) $files ) ) );
		if ( count( $files ) > 2 ) {
			return new \WP_Error( 'too_many_attachments', __( 'MailBridgeAPI currently supports up to two attachments per email.', 'mailbridgeapi-email-delivery' ) );
		}
		$out = array();
		$total = 0;
		foreach ( $files as $path ) {
			$real = realpath( $path );
			if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
				return new \WP_Error( 'attachment_unreadable', __( 'An email attachment could not be read.', 'mailbridgeapi-email-delivery' ) );
			}
			$size = (int) filesize( $real );
			$total += $size;
			if ( $size > 5 * MB_IN_BYTES || $total > 10 * MB_IN_BYTES ) {
				return new \WP_Error( 'attachment_too_large', __( 'Attachments must be 5 MB or smaller each and 10 MB or smaller in total.', 'mailbridgeapi-email-delivery' ) );
			}
			$contents = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $contents ) {
				return new \WP_Error( 'attachment_unreadable', __( 'An email attachment could not be read.', 'mailbridgeapi-email-delivery' ) );
			}
			$out[] = array( 'name' => sanitize_file_name( wp_basename( $real ) ), 'data' => base64_encode( $contents ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return $out;
	}

	private function fail( array $atts, string $recipient, string $subject, string $code, string $message ): bool {
		$this->logger->add( $recipient, $subject, 'error', $code, $message );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core wp_mail compatibility action.
		do_action( 'wp_mail_failed', new \WP_Error( $code, $message, $atts ) );
		return false;
	}
}

