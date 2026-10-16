<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package MailBridgeAPI_Email_Delivery
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mbapi_ed_settings = get_option( 'mbapi_ed_settings', array() );
if ( empty( $mbapi_ed_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$mbapi_ed_table = $wpdb->prefix . 'mbapi_email_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- The administrator explicitly opted into complete data removal on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $mbapi_ed_table ) );
delete_option( 'mbapi_ed_connection' );
delete_option( 'mbapi_ed_settings' );
delete_metadata( 'user', 0, 'mbapi_ed_test_results', '', true );
delete_metadata( 'user', 0, 'mbapi_ed_delivery_test_result', '', true );
