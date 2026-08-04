<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Cron {
    public static function add_schedule( $schedules ) {
        $schedules['smf_every_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 minutes (Smart MyFatoorah)', 'smart-myfatoorah' ),
        );
        return $schedules;
    }

    public static function run() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $settings = get_option( 'woocommerce_smart_myfatoorah_settings', array() );
        if ( 'yes' !== ( isset( $settings['reconciliation_enabled'] ) ? $settings['reconciliation_enabled'] : 'yes' ) ) {
            return;
        }

        $api = new SMF_API_Client( $settings );
        if ( ! $api->has_token() ) {
            return;
        }

        foreach ( SMF_Transactions::pending( 20 ) as $attempt ) {
            $order = wc_get_order( $attempt->order_id );
            if ( ! $order || $order->is_paid() ) {
                continue;
            }

            if ( ! empty( $attempt->payment_id ) ) {
                $details = $api->get_payment_details( $attempt->payment_id );
            } elseif ( ! empty( $attempt->invoice_id ) ) {
                $details = $api->get_payment_by_invoice( $attempt->invoice_id );
            } else {
                continue;
            }

            if ( ! is_wp_error( $details ) ) {
                SMF_Payment_State::apply( $order, $details, 'reconciliation' );
            }
        }
    }
}
