# MailBridgeAPI Email Delivery for WordPress

Send WordPress and WooCommerce email through a mailbox managed by MailBridgeAPI without storing SMTP credentials in WordPress.

[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759B?logo=wordpress)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE.txt)

## What the plugin does

- Intercepts WordPress `wp_mail()` calls.
- Sends through the mailbox selected in MailBridgeAPI.
- Never stores the mailbox SMTP or IMAP password on the WordPress server.
- Locks the site to one selected mailbox until an administrator disconnects.
- Shows delivery results, message identifiers, statistics, and diagnostics.
- Tests SMTP and IMAP connectivity from the WordPress administration area.
- Marks every message with the `wordpress` source for dashboard and mobile tracking.
- Supports WordPress core, WooCommerce, and plugins that use `wp_mail()`.
- Stores delivery metadata without storing message bodies or attachments locally.

## Installation

1. Download the plugin ZIP from the [official plugin page](https://mailbridgeapi.com/wordpress-plugin/).
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload and activate the ZIP.
4. Open **MailBridgeAPI → Connection**.
5. Sign in with the email address and password used by your MailBridgeAPI dashboard.
6. Test and select the mailbox reserved for this website.
7. Send a test message and review **Diagnostics**.

## Security model

The dashboard password is used only for the HTTPS authentication request and is never stored. The returned session token and selected API keys are encrypted with AES-256-GCM using a key derived from the WordPress authentication salt.

After mailbox selection, the plugin removes the other account mailboxes from its local state. Selecting another mailbox requires a complete disconnect and a new sign-in.

## Compatibility

- WordPress 6.5 or newer
- PHP 7.4 or newer
- HTTPS access to `dashboard.mailbridgeapi.com` and `api.mailbridgeapi.com`

## Documentation

- [Full WordPress.org readme](readme.txt)
- [MailBridgeAPI plugin page](https://mailbridgeapi.com/wordpress-plugin/)
- [MailBridgeAPI developer documentation](https://github.com/mailbridgeapi/mailbridgeapi)
- [Support center](https://dashboard.mailbridgeapi.com/support/)

## Privacy

The plugin connects to MailBridgeAPI external services. Review the exact endpoints and transmitted fields in [readme.txt](readme.txt). Never include credentials or customer email content in public GitHub issues.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).

