<?php
/**
 * Plugin Name:       MailBridgeAPI Email Delivery
 * Plugin URI:        https://mailbridgeapi.com/wordpress-plugin/
 * Description:       Sends WordPress email through a locked MailBridgeAPI mailbox without storing SMTP credentials, with delivery logs and diagnostics.
 * Version:           1.0.6
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            MailBridgeAPI
 * Author URI:        https://mailbridgeapi.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mailbridgeapi-email-delivery
 * Domain Path:       /languages
 *
 * @package MailBridgeAPI_Email_Delivery
 */

defined( 'ABSPATH' ) || exit;

define( 'MBAPI_ED_VERSION', '1.0.6' );
define( 'MBAPI_ED_FILE', __FILE__ );
define( 'MBAPI_ED_DIR', plugin_dir_path( __FILE__ ) );
define( 'MBAPI_ED_URL', plugin_dir_url( __FILE__ ) );

require_once MBAPI_ED_DIR . 'includes/class-crypto.php';
require_once MBAPI_ED_DIR . 'includes/class-api-client.php';
require_once MBAPI_ED_DIR . 'includes/class-logger.php';
require_once MBAPI_ED_DIR . 'includes/class-privacy.php';
require_once MBAPI_ED_DIR . 'includes/class-mailer.php';
require_once MBAPI_ED_DIR . 'includes/class-health.php';
require_once MBAPI_ED_DIR . 'includes/class-admin.php';
require_once MBAPI_ED_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'MailBridgeAPI\\EmailDelivery\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MailBridgeAPI\\EmailDelivery\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		MailBridgeAPI\EmailDelivery\Plugin::instance()->register();
	}
);

