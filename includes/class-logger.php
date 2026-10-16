<?php
/**
 * Local delivery log storage.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

namespace MailBridgeAPI\EmailDelivery;

defined( 'ABSPATH' ) || exit;

final class Logger {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mbapi_email_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			recipient varchar(254) NOT NULL,
			subject text NOT NULL,
			status varchar(20) NOT NULL,
			result_code varchar(60) NOT NULL DEFAULT '',
			result_message text NOT NULL,
			remote_id varchar(145) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status,created_at),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );
	}

	public function add( string $recipient, string $subject, string $status, string $code, string $message, string $remote_id = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Delivery events belong in the plugin's dedicated log table.
		$wpdb->insert(
			self::table(),
			array(
				'recipient'     => self::cut( sanitize_email( $recipient ), 254 ),
				'subject'       => self::cut( sanitize_text_field( $subject ), 1000 ),
				'status'        => 'success' === $status ? 'success' : 'error',
				'result_code'   => self::cut( sanitize_key( $code ), 60 ),
				'result_message' => self::cut( sanitize_text_field( $message ), 2000 ),
				'remote_id'     => self::cut( sanitize_text_field( $remote_id ), 145 ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function cut( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int} */
	public function page( int $page, string $status, string $search ): array {
		global $wpdb;
		$page     = max( 1, $page );
		$per_page = 25;
		$table    = self::table();
		$where    = array( '1=1' );
		$params   = array();
		if ( in_array( $status, array( 'success', 'error' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(recipient LIKE %s OR subject LIKE %s OR result_message LIKE %s OR remote_id LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );
		$count_query = "SELECT COUNT(*) FROM %i WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE fragments are fixed strings selected above.
		$count_args  = array_merge( array( $table ), $params );
		$count_sql   = $wpdb->prepare( $count_query, $count_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder count is assembled with the fixed WHERE fragments.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above and reads the live delivery log.
		$total = (int) $wpdb->get_var( $count_sql );
		$query = "SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE fragments are fixed strings selected above.
		$args  = array_merge( array( $table ), $params, array( $per_page, ( $page - 1 ) * $per_page ) );
		$sql   = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder count is assembled with the fixed WHERE fragments.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above and reads the live delivery log.
		$items = $wpdb->get_results( $sql, ARRAY_A );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
			'pages' => max( 1, (int) ceil( $total / $per_page ) ),
			'page'  => $page,
		);
	}

	/** @return array<string,mixed> */
	public function stats( int $days = 30 ): array {
		global $wpdb;
		$allowed = array( 7, 30, 90, 365 );
		$days    = in_array( $days, $allowed, true ) ? $days : 30;
		$table   = self::table();
		$before  = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics must reflect the current dedicated delivery log.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, SUM(status = 'success') AS successful, SUM(status = 'error') AS errors
				FROM %i WHERE created_at >= %s",
				$table,
				$before
			),
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics must reflect the current dedicated delivery log.
		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS total, SUM(status = 'success') AS successful, SUM(status = 'error') AS errors
				FROM %i WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day DESC",
				$table,
				$before
			),
			ARRAY_A
		);

		return array(
			'total'        => (int) ( $totals['total'] ?? 0 ),
			'successful'   => (int) ( $totals['successful'] ?? 0 ),
			'errors'       => (int) ( $totals['errors'] ?? 0 ),
			'days'         => $days,
			'daily'        => is_array( $daily ) ? $daily : array(),
		);
	}

	public function recent_error_count( int $days = 7 ): int {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * max( 1, $days ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Menu badge must reflect current failures.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND created_at >= %s', self::table(), 'error', $before ) );
	}

	/** @return array<string,mixed> */
	public function last_success(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics require the latest delivery result.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT remote_id, created_at FROM %i WHERE status = %s ORDER BY id DESC LIMIT 1', self::table(), 'success' ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** @return array<string,mixed> */
	public function latest_for( string $recipient, string $subject ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- End-to-end tests require the newly written delivery result.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE recipient = %s AND subject = %s ORDER BY id DESC LIMIT 1', self::table(), $recipient, $subject ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}

	/** @return array<int,array<string,mixed>> */
	public function export_rows( string $status, string $search, int $limit = 1000, int $offset = 0 ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( in_array( $status, array( 'success', 'error' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(recipient LIKE %s OR subject LIKE %s OR result_message LIKE %s OR remote_id LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		$limit    = max( 1, min( 2000, $limit ) );
		$offset   = max( 0, $offset );
		$table     = self::table();
		$where_sql = implode( ' AND ', $where );
		$query     = "SELECT recipient, subject, status, result_code, result_message, remote_id, created_at FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE fragments are fixed strings selected above.
		$params   = array_merge( array( $table ), $params );
		$params[] = $limit;
		$params[] = $offset;
		$sql      = $wpdb->prepare( $query, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder count is assembled with the fixed WHERE fragments.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above and exports the requested live log rows.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function clear(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator action clears the dedicated log table.
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
		return false === $result ? 0 : (int) $result;
	}

	public function cleanup(): void {
		global $wpdb;
		$settings = Plugin::settings();
		$days     = max( 30, min( 365, (int) ( $settings['retention_days'] ?? 90 ) ) );
		$before   = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled retention cleanup targets the dedicated log table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), $before ) );
	}
}

