<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Webhook_Controller {
    /**
     * Primary webhook endpoint compatible with the official MyFatoorah plugin URL
     * already configured in many merchant portals: /?wc-api=myfatoorah_webhook
     */
    public static function register_wc_api() {
        add_action( 'woocommerce_api_myfatoorah_webhook', array( __CLASS__, 'handle_wc_api' ) );
        // Optional Smart-specific alias (same handler).
        add_action( 'woocommerce_api_smart_myfatoorah_webhook', array( __CLASS__, 'handle_wc_api' ) );
    }

    /**
     * Canonical webhook URL (matches official MyFatoorah WooCommerce endpoint).
     */
    public static function get_webhook_url() {
        $home = home_url( '/' );
        // Prefer HTTPS for portal registration / API WebhookUrl.
        if ( function_exists( 'wc_https_get_url' ) ) {
            $home = wc_https_get_url( $home );
        } elseif ( is_ssl() || ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ) ) {
            $home = set_url_scheme( $home, 'https' );
        }
        return add_query_arg( 'wc-api', 'myfatoorah_webhook', $home );
    }

    /**
     * Webhook secret from Smart gateway settings only.
     */
    public static function get_webhook_secret( $settings = null ) {
        if ( null === $settings ) {
            $settings = get_option( 'woocommerce_smart_myfatoorah_settings', array() );
        }
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return isset( $settings['webhook_secret'] ) ? trim( (string) $settings['webhook_secret'] ) : '';
    }

    /**
     * WooCommerce API entry: /?wc-api=myfatoorah_webhook
     */
    public static function handle_wc_api() {
        $raw = file_get_contents( 'php://input' );
        $payload = json_decode( (string) $raw, true );
        if ( ! is_array( $payload ) ) {
            status_header( 400 );
            echo wp_json_encode( array( 'ok' => false, 'message' => 'Invalid JSON.' ) );
            exit;
        }

        $signature = '';
        if ( ! empty( $_SERVER['HTTP_MYFATOORAH_SIGNATURE'] ) ) {
            $signature = (string) wp_unslash( $_SERVER['HTTP_MYFATOORAH_SIGNATURE'] );
        } elseif ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            if ( is_array( $headers ) ) {
                foreach ( $headers as $name => $value ) {
                    if ( 'myfatoorah-signature' === strtolower( (string) $name ) ) {
                        $signature = (string) $value;
                        break;
                    }
                }
            }
        }

        $result = self::process( $payload, $signature );
        $status = isset( $result['http'] ) ? (int) $result['http'] : 200;
        status_header( $status );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        unset( $result['http'] );
        echo wp_json_encode( $result );
        exit;
    }

    /**
     * Shared webhook processor for WC API.
     *
     * @param array  $payload   Decoded JSON body.
     * @param string $signature MyFatoorah-Signature header.
     * @return array{ok:bool,message?:string,status?:string,http:int}
     */
    public static function process( array $payload, $signature ) {
        $settings = get_option( 'woocommerce_smart_myfatoorah_settings', array() );
        if ( 'yes' !== ( isset( $settings['webhook_enabled'] ) ? $settings['webhook_enabled'] : 'yes' ) ) {
            return array( 'ok' => true, 'message' => 'Webhook disabled.', 'http' => 200 );
        }

        $secret = self::get_webhook_secret( $settings );
        if ( '' === $secret ) {
            return array( 'ok' => false, 'message' => 'Webhook secret not configured.', 'http' => 401 );
        }

        $event_code = (int) self::value( $payload, array( 'Event', 'Code' ) );
        $event_name = strtoupper( (string) self::value( $payload, array( 'Event', 'Name' ) ) );

        $ip = '';
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] );
        }
        $fail_key = 'smf_wh_fail_' . md5( $ip !== '' ? $ip : 'unknown' );
        $fails    = (int) get_transient( $fail_key );
        if ( $fails >= 60 ) {
            return array( 'ok' => false, 'message' => 'Too many invalid webhook attempts.', 'http' => 429 );
        }

        if ( ! self::valid_signature( $payload, $signature, $secret, $event_code ) ) {
            set_transient( $fail_key, $fails + 1, 10 * MINUTE_IN_SECONDS );
            return array( 'ok' => false, 'message' => 'Invalid signature.', 'http' => 401 );
        }

        if ( 2 === $event_code || 'REFUND_STATUS_CHANGED' === $event_name ) {
            $refund = self::handle_refund( $payload );
            if ( $refund instanceof WP_REST_Response ) {
                $data = $refund->get_data();
                if ( ! is_array( $data ) ) {
                    $data = array( 'ok' => true );
                }
                $data['http'] = $refund->get_status();
                return $data;
            }
            return array( 'ok' => true, 'http' => 200 );
        }

        if ( 1 !== $event_code && 'PAYMENT_STATUS_CHANGED' !== $event_name ) {
            return array( 'ok' => true, 'message' => 'Ignored event.', 'http' => 200 );
        }

        $reference  = (string) self::value( $payload, array( 'Event', 'Reference' ) );
        $invoice_id = (string) self::value( $payload, array( 'Data', 'Invoice', 'Id' ) );
        $payment_id = (string) self::value( $payload, array( 'Data', 'Transaction', 'PaymentId' ) );

        if ( '' !== $reference && ! SMF_Transactions::remember_event( $reference, $event_name, $invoice_id, $payment_id ) ) {
            return array( 'ok' => true, 'message' => 'Already processed.', 'http' => 200 );
        }

        $order = SMF_Payment_State::find_order( $invoice_id, $payment_id );
        if ( ! $order ) {
            // Fallback: ExternalIdentifier / CustomerReference is the Woo order id.
            $order_id = absint( self::value( $payload, array( 'Data', 'Invoice', 'ExternalIdentifier' ) ) );
            if ( ! $order_id ) {
                $order_id = absint( self::value( $payload, array( 'Data', 'CustomerReference' ) ) );
            }
            if ( $order_id ) {
                $candidate = wc_get_order( $order_id );
                if ( $candidate && 'smart_myfatoorah' === $candidate->get_payment_method() ) {
                    $order = $candidate;
                }
            }
        }

        if ( ! $order ) {
            return array( 'ok' => true, 'message' => 'Order not found.', 'http' => 200 );
        }

        $api     = new SMF_API_Client( $settings );
        $details = $payment_id ? $api->get_payment_details( $payment_id ) : $api->get_payment_by_invoice( $invoice_id );

        if ( is_wp_error( $details ) ) {
            $order->add_order_note( sprintf( __( 'MyFatoorah webhook received, but verification failed: %s', 'smart-myfatoorah' ), $details->get_error_message() ) );
            return array( 'ok' => false, 'message' => 'Verification unavailable.', 'http' => 503 );
        }

        $state = SMF_Payment_State::apply( $order, $details, 'webhook' );
        if ( is_wp_error( $state ) ) {
            return array( 'ok' => false, 'message' => $state->get_error_message(), 'http' => 422 );
        }

        return array( 'ok' => true, 'status' => $state, 'http' => 200 );
    }

    private static function handle_refund( array $payload ) {
        $invoice_id = (string) self::value( $payload, array( 'Data', 'ReferencedInvoice', 'Id' ) );
        $refund_id  = (string) self::value( $payload, array( 'Data', 'Refund', 'Id' ) );
        $status     = strtoupper( (string) self::value( $payload, array( 'Data', 'Refund', 'Status' ) ) );
        $reference  = (string) self::value( $payload, array( 'Data', 'Refund', 'Reference' ) );
        $comment    = sanitize_text_field( (string) self::value( $payload, array( 'Data', 'Refund', 'Comment' ) ) );
        $base_amount = self::value( $payload, array( 'Data', 'Amount', 'ValueInBaseCurrency' ) );
        $display_amount_raw = self::value( $payload, array( 'Data', 'Amount', 'ValueInDisplayCurrency' ) );

        $order = SMF_Payment_State::find_order( $invoice_id, '' );
        if ( ! $order ) {
            return new WP_REST_Response( array( 'ok' => true, 'message' => 'Order not found.' ), 200 );
        }

        if ( '' === $refund_id ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Missing refund id.' ), 400 );
        }

        $processed_key = '_smf_refund_processed_' . $refund_id;
        if ( $order->get_meta( $processed_key, true ) ) {
            return new WP_REST_Response( array( 'ok' => true, 'message' => 'Refund already processed.' ), 200 );
        }

        $stored = $order->get_meta( '_smf_refund_data', true );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $stored_refund = isset( $stored[ $refund_id ] ) && is_array( $stored[ $refund_id ] ) ? $stored[ $refund_id ] : array();

        $display_amount = null;
        if ( isset( $stored_refund['display_amount'] ) ) {
            $display_amount = (float) $stored_refund['display_amount'];
        } elseif ( '' !== $display_amount_raw && is_numeric( $display_amount_raw ) ) {
            $display_amount = (float) $display_amount_raw;
        }

        $note = sprintf(
            /* translators: 1: refund status, 2: refund id, 3: refund reference */
            __( 'MyFatoorah refund webhook: status %1$s · RefundId %2$s · Reference %3$s', 'smart-myfatoorah' ),
            $status ?: '—',
            $refund_id,
            $reference ?: '—'
        );
        if ( $comment ) {
            $note .= ' · ' . sprintf(
                /* translators: %s: refund comment */
                __( 'Comment: %s', 'smart-myfatoorah' ),
                $comment
            );
        }
        if ( null !== $display_amount ) {
            $note .= ' · ' . sprintf(
                /* translators: %s: formatted amount */
                __( 'Amount: %s', 'smart-myfatoorah' ),
                wc_price( $display_amount, array( 'currency' => $order->get_currency() ) )
            );
        }
        if ( '' !== $base_amount && is_numeric( $base_amount ) ) {
            $note .= ' · ' . sprintf(
                /* translators: %s: base currency amount */
                __( 'Base amount: %s', 'smart-myfatoorah' ),
                $base_amount
            );
        }

        $stored_currency = isset( $stored_refund['display_currency'] ) ? strtoupper( (string) $stored_refund['display_currency'] ) : '';
        $order_currency  = strtoupper( (string) $order->get_currency() );
        if ( $stored_currency && $stored_currency !== $order_currency ) {
            $order->add_order_note(
                $note . ' · ' . sprintf(
                    /* translators: 1: refund currency, 2: order currency */
                    __( 'Refund currency mismatch (%1$s vs %2$s). WooCommerce refund was not created automatically.', 'smart-myfatoorah' ),
                    $stored_currency,
                    $order_currency
                )
            );
            $order->save();
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Currency mismatch.' ), 422 );
        }

        if ( 'CANCELED' === $status ) {
            $order->update_meta_data( $processed_key, 'canceled' );
            $order->add_order_note( $note . ' · ' . __( 'Refund canceled by MyFatoorah.', 'smart-myfatoorah' ) );
            $order->save();
            return new WP_REST_Response( array( 'ok' => true, 'status' => 'canceled' ), 200 );
        }

        if ( 'REFUNDED' !== $status ) {
            $order->add_order_note( $note );
            $order->save();
            return new WP_REST_Response( array( 'ok' => true, 'status' => strtolower( $status ?: 'pending' ) ), 200 );
        }

        if ( null === $display_amount || $display_amount <= 0 ) {
            $order->add_order_note(
                $note . ' · ' . __( 'Refund confirmed by MyFatoorah, but display amount was missing. Create the WooCommerce refund manually.', 'smart-myfatoorah' )
            );
            $order->update_meta_data( $processed_key, 'refunded_manual' );
            $order->save();
            return new WP_REST_Response( array( 'ok' => true, 'status' => 'refunded_manual' ), 200 );
        }

        $order->update_meta_data( $processed_key, 'refunded' );
        $order->save();

        $remaining = (float) $order->get_remaining_refund_amount();

        if ( $remaining > 0 && $display_amount > 0 ) {
            $refund_amount = min( $display_amount, $remaining );
            $refund = wc_create_refund(
                array(
                    'amount'   => $refund_amount,
                    'reason'   => $comment ? $comment : __( 'MyFatoorah refund confirmed', 'smart-myfatoorah' ),
                    'order_id' => $order->get_id(),
                )
            );

            if ( is_wp_error( $refund ) ) {
                $order->delete_meta_data( $processed_key );
                $order->add_order_note( $note . ' · ' . $refund->get_error_message() );
                $order->save();
                return new WP_REST_Response( array( 'ok' => false, 'message' => $refund->get_error_message() ), 500 );
            }
        }

        $order->add_order_note( $note . ' · ' . __( 'Refund confirmed in WooCommerce.', 'smart-myfatoorah' ) );
        $order->save();

        return new WP_REST_Response( array( 'ok' => true, 'status' => 'refunded' ), 200 );
    }

    private static function valid_signature( $payload, $received, $secret, $event_code = 1 ) {
        if ( '' === $received ) {
            return false;
        }

        if ( 2 === (int) $event_code ) {
            $pairs = array(
                'Refund.Id'                  => self::value( $payload, array( 'Data', 'Refund', 'Id' ) ),
                'Refund.Status'              => self::value( $payload, array( 'Data', 'Refund', 'Status' ) ),
                'Amount.ValueInBaseCurrency' => self::value( $payload, array( 'Data', 'Amount', 'ValueInBaseCurrency' ) ),
                'ReferencedInvoice.Id'       => self::value( $payload, array( 'Data', 'ReferencedInvoice', 'Id' ) ),
            );
        } else {
            $pairs = array(
                'Invoice.Id'                 => self::value( $payload, array( 'Data', 'Invoice', 'Id' ) ),
                'Invoice.Status'             => self::value( $payload, array( 'Data', 'Invoice', 'Status' ) ),
                'Transaction.Status'         => self::value( $payload, array( 'Data', 'Transaction', 'Status' ) ),
                'Transaction.PaymentId'      => self::value( $payload, array( 'Data', 'Transaction', 'PaymentId' ) ),
                'Invoice.ExternalIdentifier' => self::value( $payload, array( 'Data', 'Invoice', 'ExternalIdentifier' ) ),
            );
        }

        $parts = array();
        foreach ( $pairs as $key => $value ) {
            $parts[] = $key . '=' . ( null === $value || '' === $value ? '' : (string) $value );
        }

        $calculated = base64_encode( hash_hmac( 'sha256', implode( ',', $parts ), $secret, true ) );
        return hash_equals( $calculated, trim( $received ) );
    }

    private static function value( $array, $path ) {
        $value = $array;
        foreach ( $path as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                return '';
            }
            $value = $value[ $key ];
        }
        return $value;
    }
}
