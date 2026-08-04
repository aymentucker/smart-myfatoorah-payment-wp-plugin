<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Blocks {
    /**
     * Register Checkout Block support.
     *
     * WooCommerce fires `woocommerce_blocks_loaded` during its own `plugins_loaded`
     * bootstrap. This plugin boots later (priority 20), so we must catch the case
     * where that action already ran — otherwise the payment method never registers
     * and Checkout Blocks shows "no payment methods available".
     */
    public static function init() {
        if ( did_action( 'woocommerce_blocks_loaded' ) ) {
            self::on_blocks_loaded();
            return;
        }

        add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'on_blocks_loaded' ) );
    }

    public static function on_blocks_loaded() {
        if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }

        require_once SMF_PATH . 'includes/class-smf-blocks-payment-method.php';

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            static function ( $registry ) {
                $registry->register( new SMF_Blocks_Payment_Method() );
            }
        );
    }
}
