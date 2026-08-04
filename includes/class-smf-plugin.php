<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Plugin {
    private static $instance;

    public static function load_textdomain() {
        load_plugin_textdomain( 'smart-myfatoorah', false, dirname( plugin_basename( SMF_FILE ) ) . '/languages' );
    }

    public static function init() {
        if ( self::$instance ) {
            return self::$instance;
        }

        self::load_textdomain();

        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
            add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
            return null;
        }

        self::maybe_upgrade();

        require_once SMF_PATH . 'includes/class-smf-gateway.php';

        self::$instance = new self();
        return self::$instance;
    }

    /**
     * Ensure DB tables exist after plugin updates (activation may not re-run).
     */
    public static function maybe_upgrade() {
        $installed = (string) get_option( 'smf_db_version', '' );
        if ( $installed === (string) SMF_VERSION ) {
            return;
        }
        SMF_Transactions::install();
        update_option( 'smf_db_version', SMF_VERSION );
    }

    private function __construct() {
        add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
        add_action( 'woocommerce_api_smart_myfatoorah_callback', array( 'SMF_Callback_Controller', 'handle' ) );
        SMF_Webhook_Controller::register_wc_api();
        add_filter( 'cron_schedules', array( 'SMF_Cron', 'add_schedule' ) );
        add_action( 'smf_reconcile_pending', array( 'SMF_Cron', 'run' ) );

        SMF_Blocks::init();
        SMF_Admin::init();

        add_action( 'admin_notices', array( __CLASS__, 'official_plugin_conflict_notice' ) );
    }

    /**
     * Warn when both Smart and the official MyFatoorah plugin listen on the same webhook URL.
     */
    public static function official_plugin_conflict_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! is_plugin_active( 'myfatoorah-woocommerce/myfatoorah-woocommerce.php' ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__( 'Smart MyFatoorah and the official MyFatoorah plugin are both active. Deactivate the official plugin so webhooks on /?wc-api=myfatoorah_webhook are handled by Smart only.', 'smart-myfatoorah' )
            . '</p></div>';
    }

    public function register_gateway( $gateways ) {
        $gateways[] = 'SMF_Gateway';
        return $gateways;
    }

    public static function activate() {
        SMF_Transactions::install();
        update_option( 'smf_db_version', SMF_VERSION );
        add_filter( 'cron_schedules', array( 'SMF_Cron', 'add_schedule' ) );

        if ( ! wp_next_scheduled( 'smf_reconcile_pending' ) ) {
            wp_schedule_event( time() + 300, 'smf_every_fifteen_minutes', 'smf_reconcile_pending' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'smf_reconcile_pending' );
    }

    public static function woocommerce_missing_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Smart MyFatoorah Gateway requires WooCommerce to be installed and active.', 'smart-myfatoorah' ) . '</p></div>';
    }
}
