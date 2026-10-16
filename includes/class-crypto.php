<?php
/**
 * Local secret encryption.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

namespace MailBridgeAPI\EmailDelivery;

defined( 'ABSPATH' ) || exit;

final class Crypto {
	private const CIPHER = 'aes-256-gcm';

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Encrypt a value using this WordPress installation's authentication salt.
	 *
	 * @throws \RuntimeException When encryption is unavailable or fails.
	 */
	public static function encrypt( string $plain_text ): string {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'The OpenSSL PHP extension is required to protect MailBridgeAPI credentials.' );
		}

		$iv  = random_bytes( 12 );
		$tag = '';
		$key = hash( 'sha256', wp_salt( 'auth' ) . '|mailbridgeapi-email-delivery', true );
		$raw = openssl_encrypt( $plain_text, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		if ( false === $raw ) {
			throw new \RuntimeException( 'MailBridgeAPI credentials could not be encrypted.' );
		}

		return 'v1:' . base64_encode( $iv . $tag . $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a locally protected value.
	 *
	 * @throws \RuntimeException When the value cannot be decrypted.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( 0 !== strpos( $encrypted, 'v1:' ) || ! self::available() ) {
			throw new \RuntimeException( 'The stored MailBridgeAPI connection is unreadable. Reconnect the mailbox.' );
		}

		$packed = base64_decode( substr( $encrypted, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $packed || strlen( $packed ) < 29 ) {
			throw new \RuntimeException( 'The stored MailBridgeAPI connection is invalid. Reconnect the mailbox.' );
		}

		$iv     = substr( $packed, 0, 12 );
		$tag    = substr( $packed, 12, 16 );
		$cipher = substr( $packed, 28 );
		$key    = hash( 'sha256', wp_salt( 'auth' ) . '|mailbridgeapi-email-delivery', true );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $plain ) {
			throw new \RuntimeException( 'The stored MailBridgeAPI connection cannot be decrypted. Reconnect the mailbox.' );
		}

		return $plain;
	}
}

