<?php
/**
 * WordPress personal data export and erase integration.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

namespace MailBridgeAPI\EmailDelivery;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	/** @param array<string,array<string,mixed>> $exporters Registered exporters. */
	public static function exporters( array $exporters ): array {
		$exporters['mailbridgeapi-email-delivery'] = array(
			'exporter_friendly_name' => __( 'MailBridgeAPI delivery log', 'mailbridgeapi-email-delivery' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/** @param array<string,array<string,mixed>> $erasers Registered erasers. */
	public static function erasers( array $erasers ): array {
		$erasers['mailbridgeapi-email-delivery'] = array(
			'eraser_friendly_name' => __( 'MailBridgeAPI delivery log', 'mailbridgeapi-email-delivery' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/** @return array{data:array<int,array<string,mixed>>,done:bool} */
	public static function export( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$page     = max( 1, $page );
		$per_page = 50;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy exports must read current rows from the dedicated delivery log.
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE recipient = %s ORDER BY id ASC LIMIT %d OFFSET %d',
				Logger::table(),
				sanitize_email( $email_address ),
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'mailbridgeapi-email-delivery',
				'group_label' => __( 'MailBridgeAPI sent email delivery log', 'mailbridgeapi-email-delivery' ),
				'item_id'     => 'mailbridgeapi-log-' . (int) $row['id'],
				'data'        => array(
					array( 'name' => __( 'Recipient', 'mailbridgeapi-email-delivery' ), 'value' => (string) $row['recipient'] ),
					array( 'name' => __( 'Subject', 'mailbridgeapi-email-delivery' ), 'value' => (string) $row['subject'] ),
					array( 'name' => __( 'Delivery status', 'mailbridgeapi-email-delivery' ), 'value' => (string) $row['status'] ),
					array( 'name' => __( 'Provider response', 'mailbridgeapi-email-delivery' ), 'value' => (string) $row['result_message'] ),
					array( 'name' => __( 'Remote message ID', 'mailbridgeapi-email-delivery' ), 'value' => (string) $row['remote_id'] ),
					array( 'name' => __( 'UTC date', 'mailbridgeapi-email-delivery' ), 'value' => (string) $row['created_at'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => count( $rows ) < $per_page );
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool} */
	public static function erase( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$removed = false;
		if ( 1 === max( 1, $page ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress privacy erasure must delete matching dedicated log rows immediately.
			$result  = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE recipient = %s', Logger::table(), sanitize_email( $email_address ) ) );
			$removed = is_int( $result ) && $result > 0;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
