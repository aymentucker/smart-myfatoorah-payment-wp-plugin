<?php

/**
 * Plugin Name: Smart MyFatoorah Gateway for WooCommerce
 * Plugin URI:  https://github.com/aymentucker
 * Description: Smart MyFatoorah payment routing for WooCommerce. Recommends QPay for Qatar customers and card payments for international customers, with secure callbacks, signed webhooks, reconciliation, transaction logs, Classic Checkout and Checkout Block support.
 * Version:     1.0.8
 * Author:      Aymen Ali
 * Author URI:  https://github.com/aymentucker
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-myfatoorah
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SMF_VERSION', '1.0.8');
define('SMF_FILE', __FILE__);
define('SMF_PATH', plugin_dir_path(__FILE__));
define('SMF_URL', plugin_dir_url(__FILE__));

require_once SMF_PATH . 'includes/class-smf-i18n.php';
require_once SMF_PATH . 'includes/class-smf-transactions.php';
require_once SMF_PATH . 'includes/class-smf-method-catalog.php';
require_once SMF_PATH . 'includes/class-smf-api-client.php';
require_once SMF_PATH . 'includes/class-smf-router.php';
require_once SMF_PATH . 'includes/class-smf-payment-state.php';
require_once SMF_PATH . 'includes/class-smf-callback-controller.php';
require_once SMF_PATH . 'includes/class-smf-webhook-controller.php';
require_once SMF_PATH . 'includes/class-smf-cron.php';
require_once SMF_PATH . 'includes/class-smf-blocks.php';
require_once SMF_PATH . 'includes/class-smf-admin.php';
require_once SMF_PATH . 'includes/class-smf-plugin.php';

register_activation_hook(__FILE__, array('SMF_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('SMF_Plugin', 'deactivate'));

add_action('init', array('SMF_Plugin', 'load_textdomain'), 0);

add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', array('SMF_Plugin', 'init'), 20);
