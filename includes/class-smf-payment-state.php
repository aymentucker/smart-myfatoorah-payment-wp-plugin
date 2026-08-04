<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Payment_State {
    public static function apply( WC_Order $order, $details, $context = 'callback' ) {
        if ( is_wp_error( $details ) || ! is_array( $details ) ) {
            return is_wp_error( $details ) ? $details : new WP_Error( 'smf_invalid_details', __( 'Invalid payment details.', 'smart-myfatoorah' ) );
        }

        $verification = self::verify_order_match( $order, $details );
        if ( is_wp_error( $verification ) ) {
            $order->add_order_note( sprintf( __( 'MyFatoorah verification failed: %s', 'smart-myfatoorah' ), $verification->get_error_message() ) );
            return $verification;
        }

        $invoice_id = isset( $details['invoice_id'] ) ? (string) $details['invoice_id'] : '';
        $payment_id = isset( $details['payment_id'] ) ? (string) $details['payment_id'] : '';
        $tx_id      = isset( $details['transaction_id'] ) ? (string) $details['transaction_id'] : '';
        $method     = isset( $details['payment_method'] ) ? (string) $details['payment_method'] : '';
        $error_code = isset( $details['error_code'] ) ? (string) $details['error_code'] : '';
        $error_msg  = isset( $details['error_message'] ) ? (string) $details['error_message'] : '';
        $tx_status  = isset( $details['transaction_status'] ) ? strtoupper( (string) $details['transaction_status'] ) : '';

        $is_current_attempt = self::is_current_attempt( $order, $invoice_id, $payment_id );

        // Always keep historical invoice IDs discoverable.
        if ( $invoice_id ) {
            self::remember_invoice_id( $order, $invoice_id );
        }

        // Only the latest attempt may rewrite "current" order payment meta,
        // except a successful paid result which always wins for an unpaid order.
        $may_update_current_meta = $is_current_attempt || ! empty( $details['is_paid'] );

        if ( $may_update_current_meta ) {
            if ( $invoice_id ) {
                $order->update_meta_data( '_smf_invoice_id', $invoice_id );
            }
            if ( $payment_id ) {
                $order->update_meta_data( '_smf_payment_id', $payment_id );
            }
            if ( $tx_id ) {
                $order->update_meta_data( '_smf_transaction_id', $tx_id );
            }
            if ( $method ) {
                $order->update_meta_data( '_smf_payment_method', $method );
            }
        }

        if ( $error_code && $is_current_attempt ) {
            $order->update_meta_data( '_smf_error_code', $error_code );
        }
        if ( $error_msg && $is_current_attempt ) {
            $order->update_meta_data( '_smf_error_message', $error_msg );
        }

        if ( ! empty( $details['is_paid'] ) ) {
            if ( ! $order->is_paid() ) {
                $order->payment_complete( $payment_id ?: $tx_id );
                $order->add_order_note(
                    sprintf(
                        __( 'MyFatoorah payment confirmed via %1$s. Payment ID: %2$s', 'smart-myfatoorah' ),
                        $method ?: __( 'gateway', 'smart-myfatoorah' ),
                        $payment_id ?: '—'
                    )
                );
            }

            $order->update_meta_data( '_smf_status', 'paid' );
            self::remember_customer_route( $order );
            $order->save();

            SMF_Transactions::update_for_order_identifiers(
                $order->get_id(),
                $invoice_id,
                $payment_id,
                array(
                    'invoice_id'    => $invoice_id ?: null,
                    'payment_id'    => $payment_id ?: null,
                    'status'        => 'paid',
                    'error_code'    => null,
                    'error_message' => null,
                )
            );

            return 'paid';
        }

        if ( in_array( $tx_status, array( 'FAILED', 'CANCELED', 'CANCELLED' ), true ) ) {
            SMF_Transactions::update_for_order_identifiers(
                $order->get_id(),
                $invoice_id,
                $payment_id,
                array(
                    'invoice_id'    => $invoice_id ?: null,
                    'payment_id'    => $payment_id ?: null,
                    'status'        => 'failed',
                    'error_code'    => $error_code ?: null,
                    'error_message' => $error_msg ?: null,
                )
            );

            // Stale attempt (customer already started a newer invoice): never fail the live order.
            if ( ! $is_current_attempt ) {
                $order->add_order_note(
                    self::failure_note( $details, $context ) . ' ' .
                    __( 'Ignored for order status because a newer payment attempt exists.', 'smart-myfatoorah' )
                );
                $order->save();
                return 'failed_stale';
            }

            if ( ! $order->is_paid() && ! $order->has_status( 'failed' ) ) {
                $order->update_status( 'failed', self::failure_note( $details, $context ) );
            } else {
                $order->add_order_note( self::failure_note( $details, $context ) );
            }
            $order->update_meta_data( '_smf_status', 'failed' );
            $order->save();

            return 'failed';
        }

        if ( $is_current_attempt ) {
            $order->update_meta_data( '_smf_status', 'pending' );
        }
        $order->save();

        SMF_Transactions::update_for_order_identifiers(
            $order->get_id(),
            $invoice_id,
            $payment_id,
            array(
                'invoice_id' => $invoice_id ?: null,
                'payment_id' => $payment_id ?: null,
                'status'     => 'pending',
            )
        );

        return 'pending';
    }

    /**
     * Whether the provider invoice/payment belongs to the order's latest attempt.
     */
    private static function is_current_attempt( WC_Order $order, $invoice_id, $payment_id ) {
        $invoice_id = trim( (string) $invoice_id );
        $payment_id = trim( (string) $payment_id );

        $current_invoice = trim( (string) $order->get_meta( '_smf_invoice_id', true ) );
        $current_payment = trim( (string) $order->get_meta( '_smf_payment_id', true ) );

        if ( $invoice_id && $current_invoice && $invoice_id === $current_invoice ) {
            return true;
        }
        if ( $payment_id && $current_payment && $payment_id === $current_payment ) {
            return true;
        }

        // First callback before meta was written, or meta cleared: treat as current.
        if ( '' === $current_invoice && '' === $current_payment ) {
            return true;
        }

        return false;
    }

    public static function find_order( $invoice_id = '', $payment_id = '' ) {
        // Prefer historical attempt table so older retries remain webhook-linkable
        // even after the latest order meta IDs were overwritten by a new attempt.
        $order_id = SMF_Transactions::find_order_id_by_provider_ids( $invoice_id, $payment_id );
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                return $order;
            }
        }

        if ( $payment_id ) {
            $orders = wc_get_orders(
                array(
                    'limit'      => 1,
                    'return'     => 'objects',
                    'meta_query' => array(
                        array(
                            'key'   => '_smf_payment_id',
                            'value' => (string) $payment_id,
                        ),
                    ),
                )
            );
            if ( $orders ) {
                return $orders[0];
            }
        }

        if ( $invoice_id ) {
            $orders = wc_get_orders(
                array(
                    'limit'      => 1,
                    'return'     => 'objects',
                    'meta_query' => array(
                        array(
                            'key'   => '_smf_invoice_id',
                            'value' => (string) $invoice_id,
                        ),
                    ),
                )
            );
            if ( $orders ) {
                return $orders[0];
            }

            // Historical attempts stored in _smf_invoice_ids (serialized array).
            $orders = wc_get_orders(
                array(
                    'limit'      => 20,
                    'return'     => 'objects',
                    'orderby'    => 'date',
                    'order'      => 'DESC',
                    'meta_query' => array(
                        array(
                            'key'     => '_smf_invoice_ids',
                            'value'   => (string) $invoice_id,
                            'compare' => 'LIKE',
                        ),
                    ),
                )
            );
            foreach ( $orders as $candidate ) {
                $ids = $candidate->get_meta( '_smf_invoice_ids', true );
                if ( is_array( $ids ) && in_array( (string) $invoice_id, array_map( 'strval', $ids ), true ) ) {
                    return $candidate;
                }
            }
        }

        return false;
    }

    private static function verify_order_match( WC_Order $order, $details ) {
        $invoice_id = isset( $details['invoice_id'] ) ? trim( (string) $details['invoice_id'] ) : '';
        if ( '' === $invoice_id ) {
            return new WP_Error( 'smf_missing_invoice', __( 'Payment verification requires an invoice ID from MyFatoorah.', 'smart-myfatoorah' ) );
        }

        $reference = isset( $details['customer_reference'] ) ? trim( (string) $details['customer_reference'] ) : '';
        if ( '' === $reference ) {
            return new WP_Error( 'smf_missing_reference', __( 'Payment verification requires a customer reference from MyFatoorah.', 'smart-myfatoorah' ) );
        }
        if ( (string) $order->get_id() !== $reference ) {
            return new WP_Error( 'smf_reference_mismatch', __( 'Customer reference does not match the WooCommerce order.', 'smart-myfatoorah' ) );
        }

        $known_ids = array();
        $saved_invoice = (string) $order->get_meta( '_smf_invoice_id', true );
        if ( $saved_invoice ) {
            $known_ids[] = $saved_invoice;
        }
        $stored_ids = $order->get_meta( '_smf_invoice_ids', true );
        if ( is_array( $stored_ids ) ) {
            foreach ( $stored_ids as $stored_id ) {
                $known_ids[] = (string) $stored_id;
            }
        }
        $known_ids = array_values( array_unique( array_filter( $known_ids ) ) );

        $payment_id      = isset( $details['payment_id'] ) ? (string) $details['payment_id'] : '';
        $linked_order_id = SMF_Transactions::find_order_id_by_provider_ids( $invoice_id, $payment_id );
        $belongs = in_array( $invoice_id, $known_ids, true ) || ( (int) $linked_order_id === (int) $order->get_id() );

        // Reject foreign invoices once this order already has known attempts.
        // If none are known yet (rare race before meta save), customer reference already matched.
        if ( ! $belongs && ( $known_ids || $linked_order_id ) ) {
            return new WP_Error( 'smf_invoice_mismatch', __( 'Invoice ID does not match the WooCommerce order.', 'smart-myfatoorah' ) );
        }

        if ( ! isset( $details['amount'] ) || null === $details['amount'] || '' === $details['amount'] ) {
            return new WP_Error( 'smf_missing_amount', __( 'Payment verification requires a paid amount from MyFatoorah.', 'smart-myfatoorah' ) );
        }

        $expected = (float) $order->get_total();
        $actual   = (float) $details['amount'];
        if ( abs( $expected - $actual ) > 0.01 ) {
            return new WP_Error(
                'smf_amount_mismatch',
                sprintf( __( 'Paid amount mismatch. Expected %1$s, received %2$s.', 'smart-myfatoorah' ), $expected, $actual )
            );
        }

        $currency = isset( $details['currency'] ) ? strtoupper( trim( (string) $details['currency'] ) ) : '';
        if ( '' === $currency ) {
            return new WP_Error( 'smf_missing_currency', __( 'Payment verification requires a currency from MyFatoorah.', 'smart-myfatoorah' ) );
        }
        if ( strtoupper( (string) $order->get_currency() ) !== $currency ) {
            return new WP_Error(
                'smf_currency_mismatch',
                sprintf( __( 'Payment currency mismatch. Expected %1$s, received %2$s.', 'smart-myfatoorah' ), $order->get_currency(), $currency )
            );
        }

        return true;
    }

    private static function remember_invoice_id( WC_Order $order, $invoice_id ) {
        $ids = $order->get_meta( '_smf_invoice_ids', true );
        if ( ! is_array( $ids ) ) {
            $ids = array();
        }
        $invoice_id = (string) $invoice_id;
        if ( $invoice_id && ! in_array( $invoice_id, array_map( 'strval', $ids ), true ) ) {
            $ids[] = $invoice_id;
            $order->update_meta_data( '_smf_invoice_ids', array_values( $ids ) );
        }
    }

    private static function failure_note( $details, $context ) {
        $code    = isset( $details['error_code'] ) ? trim( (string) $details['error_code'] ) : '';
        $message = isset( $details['error_message'] ) ? trim( (string) $details['error_message'] ) : '';
        $parts   = array( sprintf( __( 'MyFatoorah payment failed (%s).', 'smart-myfatoorah' ), sanitize_text_field( $context ) ) );

        if ( $code ) {
            $parts[] = 'Code: ' . $code;
        }
        if ( $message ) {
            $parts[] = 'Message: ' . $message;
        }

        return implode( ' ', $parts );
    }

    private static function remember_customer_route( WC_Order $order ) {
        if ( ! $order->get_customer_id() ) {
            return;
        }

        $settings = get_option( 'woocommerce_smart_myfatoorah_settings', array() );
        if ( 'yes' !== ( isset( $settings['remember_preference'] ) ? $settings['remember_preference'] : 'yes' ) ) {
            return;
        }

        $route = sanitize_key( (string) $order->get_meta( '_smf_route', true ) );
        $allowed = array_merge(
            array( 'card', 'apple_pay', 'google_pay' ),
            SMF_Method_Catalog::local_route_ids()
        );
        if ( in_array( $route, $allowed, true ) ) {
            update_user_meta( $order->get_customer_id(), '_smf_preferred_route', $route );
        }
    }
}
