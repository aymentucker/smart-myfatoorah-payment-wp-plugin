<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Callback_Controller {
    public static function handle() {
        $order_id    = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
        $order_key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
        $payment_id  = isset( $_GET['paymentId'] ) ? sanitize_text_field( wp_unslash( $_GET['paymentId'] ) ) : '';
        $result_hint = isset( $_GET['smf_result'] ) ? sanitize_key( wp_unslash( $_GET['smf_result'] ) ) : '';

        // Restrict payment id to expected MyFatoorah identifier characters.
        $payment_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $payment_id );

        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
            wp_die( esc_html__( 'Invalid payment callback.', 'smart-myfatoorah' ), esc_html__( 'Payment Error', 'smart-myfatoorah' ), array( 'response' => 400 ) );
        }

        if ( $order->get_payment_method() && 'smart_myfatoorah' !== $order->get_payment_method() ) {
            wp_die( esc_html__( 'Invalid payment callback.', 'smart-myfatoorah' ), esc_html__( 'Payment Error', 'smart-myfatoorah' ), array( 'response' => 400 ) );
        }

        if ( $order->is_paid() ) {
            wp_safe_redirect( $order->get_checkout_order_received_url() );
            exit;
        }

        $settings = get_option( 'woocommerce_smart_myfatoorah_settings', array() );
        $api      = new SMF_API_Client( $settings );

        // Never trust smf_result alone. Always resolve status from MyFatoorah when possible.
        $details = null;
        if ( $payment_id ) {
            $details = $api->get_payment_details( $payment_id );
        } else {
            $invoice_id = (string) $order->get_meta( '_smf_invoice_id', true );
            if ( $invoice_id ) {
                $details = $api->get_payment_by_invoice( $invoice_id );
            }
        }

        if ( null === $details ) {
            $order->add_order_note(
                'error' === $result_hint
                    ? __( 'Customer returned from MyFatoorah without a payment ID. Order left pending until webhook/reconciliation confirms the result.', 'smart-myfatoorah' )
                    : __( 'Customer returned from MyFatoorah without a payment ID. Waiting for confirmation.', 'smart-myfatoorah' )
            );
            wc_add_notice( __( 'We could not confirm the payment yet. Please wait a moment or try again.', 'smart-myfatoorah' ), 'notice' );
            wp_safe_redirect( $order->get_checkout_payment_url( false ) );
            exit;
        }

        if ( is_wp_error( $details ) ) {
            $order->add_order_note( sprintf( __( 'Could not confirm MyFatoorah payment: %s', 'smart-myfatoorah' ), $details->get_error_message() ) );
            wc_add_notice( __( 'We received your payment response, but confirmation is still pending. Please check your order again shortly.', 'smart-myfatoorah' ), 'notice' );
            wp_safe_redirect( $order->get_view_order_url() ? $order->get_view_order_url() : wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        $state = SMF_Payment_State::apply( $order, $details, 'callback' );
        if ( is_wp_error( $state ) ) {
            wc_add_notice( __( 'We could not verify this payment safely. Please contact support and provide your order number.', 'smart-myfatoorah' ), 'error' );
            wp_safe_redirect( $order->get_checkout_payment_url( false ) );
            exit;
        }

        if ( 'paid' === $state ) {
            if ( function_exists( 'WC' ) && WC()->cart ) {
                WC()->cart->empty_cart();
            }
            wp_safe_redirect( $order->get_checkout_order_received_url() );
            exit;
        }

        if ( 'failed' === $state ) {
            wc_add_notice( self::friendly_failure_message( $order, $details ), 'error' );
            wp_safe_redirect( $order->get_checkout_payment_url( false ) );
            exit;
        }

        wc_add_notice( __( 'Your payment is being confirmed. The order will update automatically.', 'smart-myfatoorah' ), 'notice' );
        wp_safe_redirect( $order->get_view_order_url() ? $order->get_view_order_url() : $order->get_checkout_order_received_url() );
        exit;
    }

    private static function friendly_failure_message( WC_Order $order, $details = array() ) {
        $route = sanitize_key( (string) $order->get_meta( '_smf_route', true ) );
        $code  = is_array( $details ) && ! empty( $details['error_code'] ) ? (string) $details['error_code'] : '';

        if ( 'card' === $route && in_array( $code, array( 'MF002', 'MF003' ), true ) ) {
            return __( 'The bank declined this card. If you are using a Qatar-issued debit card, try QPay; otherwise try another Visa/Mastercard.', 'smart-myfatoorah' );
        }

        if ( 'card' === $route ) {
            return __( 'Card payment was not completed. If this is a Qatar-issued debit card, try QPay. Otherwise try another card.', 'smart-myfatoorah' );
        }

        return __( 'The payment was not completed. Please try again or choose another payment method.', 'smart-myfatoorah' );
    }
}
