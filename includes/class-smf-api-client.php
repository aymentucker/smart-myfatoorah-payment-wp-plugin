<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_API_Client {
    private $token;
    private $test_mode;
    private $merchant_country;
    private $debug;
    private $invoice_items;
    private $expiry_minutes;

    public function __construct( $settings = array() ) {
        $this->token            = isset( $settings['api_token'] ) ? trim( (string) $settings['api_token'] ) : '';
        $this->test_mode        = isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'];
        $this->merchant_country = isset( $settings['merchant_country'] ) ? strtoupper( (string) $settings['merchant_country'] ) : 'QAT';
        $this->debug            = isset( $settings['debug'] ) && 'yes' === $settings['debug'];
        $this->invoice_items    = ! isset( $settings['invoice_items'] ) || 'yes' === $settings['invoice_items'];
        $this->expiry_minutes   = isset( $settings['invoice_expiry_minutes'] ) ? absint( $settings['invoice_expiry_minutes'] ) : 0;
    }

    public function has_token() {
        return '' !== $this->token;
    }

    public function get_base_url() {
        if ( $this->test_mode ) {
            return 'https://apitest.myfatoorah.com';
        }

        switch ( $this->merchant_country ) {
            case 'ARE':
                return 'https://api-ae.myfatoorah.com';
            case 'SAU':
                return 'https://api-sa.myfatoorah.com';
            case 'QAT':
                return 'https://api-qa.myfatoorah.com';
            case 'EGY':
                return 'https://api-eg.myfatoorah.com';
            case 'KWT':
            case 'BHR':
            case 'JOR':
            case 'OMN':
            default:
                return 'https://api.myfatoorah.com';
        }
    }

    /**
     * Portal host used by CardView / Apple Pay / Google Pay browser scripts.
     */
    public function get_portal_base_url() {
        if ( $this->test_mode ) {
            return 'https://demo.myfatoorah.com';
        }

        switch ( $this->merchant_country ) {
            case 'ARE':
                return 'https://ae.myfatoorah.com';
            case 'SAU':
                return 'https://sa.myfatoorah.com';
            case 'QAT':
                return 'https://qa.myfatoorah.com';
            case 'EGY':
                return 'https://eg.myfatoorah.com';
            case 'KWT':
            case 'BHR':
            case 'JOR':
            case 'OMN':
            default:
                return 'https://portal.myfatoorah.com';
        }
    }

    /**
     * Start an embedded CardView session (never request saved cards).
     *
     * @return array|WP_Error{session_id:string,country_code:string}
     */
    public function initiate_session() {
        $response = $this->request(
            'POST',
            '/v2/InitiateSession',
            array(
                'CustomerIdentifier' => '',
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['Data']['SessionId'] ) ) {
            return new WP_Error( 'smf_invalid_session', __( 'MyFatoorah returned an incomplete embedded session.', 'smart-myfatoorah' ) );
        }

        return array(
            'session_id'   => (string) $response['Data']['SessionId'],
            'country_code' => isset( $response['Data']['CountryCode'] ) ? (string) $response['Data']['CountryCode'] : '',
        );
    }

    /**
     * Execute V2 payment using a CardView session id (no PaymentMethodId).
     *
     * @param string $session_id Session id returned after myFatoorah.submit().
     * @return array|WP_Error
     */
    public function create_embedded_card_payment( WC_Order $order, $session_id, $callback_url ) {
        $session_id = trim( (string) $session_id );
        if ( '' === $session_id ) {
            return new WP_Error( 'smf_missing_session', __( 'Embedded card session is missing. Please try again.', 'smart-myfatoorah' ) );
        }

        $invoice = $this->build_invoice_amount_and_items( $order );

        $payload = array(
            'InvoiceValue'       => $invoice['amount'],
            'SessionId'          => $session_id,
            'CustomerName'       => $this->order_customer_name( $order ),
            'CustomerEmail'      => $order->get_billing_email(),
            'CallBackUrl'        => $callback_url,
            'ErrorUrl'           => add_query_arg( 'smf_result', 'error', $callback_url ),
            'DisplayCurrencyIso' => strtoupper( $order->get_currency() ),
            'Language'           => $this->language(),
            'CustomerReference'  => (string) $order->get_id(),
            'UserDefinedField'   => 'woocommerce_order_' . $order->get_id(),
            'WebhookUrl'         => SMF_Webhook_Controller::get_webhook_url(),
        );

        if ( ! empty( $invoice['items'] ) ) {
            $payload['InvoiceItems'] = $invoice['items'];
        }

        $expiry = $this->expiry_date_v2();
        if ( $expiry ) {
            $payload['ExpiryDate'] = $expiry;
        }

        $response = $this->request( 'POST', '/v2/ExecutePayment', $payload );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['Data']['PaymentURL'] ) || empty( $response['Data']['InvoiceId'] ) ) {
            return new WP_Error( 'smf_invalid_embedded_response', __( 'MyFatoorah returned an incomplete embedded card response.', 'smart-myfatoorah' ) );
        }

        $payment_url = self::sanitize_payment_url( $response['Data']['PaymentURL'] );
        if ( '' === $payment_url ) {
            return new WP_Error( 'smf_untrusted_payment_url', __( 'MyFatoorah returned an untrusted payment URL.', 'smart-myfatoorah' ) );
        }

        return array(
            'engine'      => 'v2',
            'route'       => 'card',
            'invoice_id'  => (string) $response['Data']['InvoiceId'],
            'payment_id'  => '',
            'payment_url' => $payment_url,
            'raw'         => $response,
        );
    }

    public function discover_v2_methods( $amount, $currency ) {
        $response = $this->request(
            'POST',
            '/v2/InitiatePayment',
            array(
                'InvoiceAmount' => (float) $amount,
                'CurrencyIso'   => strtoupper( (string) $currency ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['Data']['PaymentMethods'] ) || ! is_array( $response['Data']['PaymentMethods'] ) ) {
            return new WP_Error( 'smf_no_methods', __( 'MyFatoorah returned no enabled payment methods.', 'smart-myfatoorah' ) );
        }

        return $response['Data']['PaymentMethods'];
    }

    /**
     * Cached summary of which checkout routes are enabled on the MF account.
     *
     * @return array{
     *   qpay:bool,card:bool,apple_pay:bool,google_pay:bool,
     *   knet:bool,benefit:bool,mada:bool,stc_pay:bool,meeza:bool,
     *   names:array<int,string>,images:array<string,string>,discovery_failed?:bool
     * }
     */
    public function get_enabled_route_flags( $amount = 1, $currency = '' ) {
        if ( '' === $currency ) {
            $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'QAR';
        }

        $cache_key = 'smf_route_flags_' . md5(
            $this->token . '|' . $this->merchant_country . '|' . ( $this->test_mode ? '1' : '0' ) . '|' . strtoupper( (string) $currency ) . '|v2locals'
        );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['card'], $cached['knet'] ) ) {
            return $cached;
        }

        $flags = array(
            'qpay'             => false,
            'card'             => false,
            'apple_pay'        => false,
            'google_pay'       => false,
            'knet'             => false,
            'benefit'          => false,
            'mada'             => false,
            'stc_pay'          => false,
            'meeza'            => false,
            'names'            => array(),
            'images'           => array(),
            'discovery_failed' => false,
        );

        $methods = $this->discover_v2_methods( $amount, $currency );
        if ( is_wp_error( $methods ) ) {
            // Fail soft: keep card only. Do not invent QPay/locals when discovery failed.
            $flags['card']             = true;
            $flags['discovery_failed'] = true;
            return $flags;
        }

        foreach ( $methods as $method ) {
            if ( ! is_array( $method ) ) {
                continue;
            }
            $name = isset( $method['PaymentMethodEn'] ) ? strtolower( trim( (string) $method['PaymentMethodEn'] ) ) : '';
            $code = isset( $method['PaymentMethodCode'] ) ? strtolower( trim( (string) $method['PaymentMethodCode'] ) ) : '';
            $img  = isset( $method['ImageUrl'] ) ? (string) $method['ImageUrl'] : '';
            if ( $name ) {
                $flags['names'][] = isset( $method['PaymentMethodEn'] ) ? (string) $method['PaymentMethodEn'] : $name;
            }

            $local = SMF_Method_Catalog::match_local_route( $method );
            if ( $local ) {
                $flags[ $local ] = true;
                if ( $img ) {
                    $flags['images'][ $local ] = $img;
                }
                continue;
            }

            if ( SMF_Method_Catalog::is_card_method( $method ) ) {
                $flags['card'] = true;
                if ( $img && empty( $flags['images']['card'] ) ) {
                    $flags['images']['card'] = $img;
                }
            }
            if ( 'ap' === $code || false !== strpos( $name, 'apple' ) ) {
                $flags['apple_pay'] = true;
                if ( $img ) {
                    $flags['images']['apple_pay'] = $img;
                }
            }
            if ( 'gp' === $code || false !== strpos( $name, 'google' ) ) {
                $flags['google_pay'] = true;
                if ( $img ) {
                    $flags['images']['google_pay'] = $img;
                }
            }
        }

        if ( ! $flags['card'] ) {
            $flags['card'] = true;
        }

        $flags['names'] = array_values( array_unique( $flags['names'] ) );
        set_transient( $cache_key, $flags, 15 * MINUTE_IN_SECONDS );
        return $flags;
    }

    public static function clear_route_flags_cache() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_smf_route_flags_%' OR option_name LIKE '_transient_timeout_smf_route_flags_%'" );
    }

    /**
     * Create a V2 ExecutePayment for a known local route (QPay, KNET, Benefit, …).
     *
     * @param WC_Order $order Order.
     * @param string   $route Route id from SMF_Method_Catalog.
     * @param string   $callback_url Callback URL.
     * @return array|WP_Error
     */
    public function create_local_method_payment( WC_Order $order, $route, $callback_url ) {
        $route = sanitize_key( (string) $route );
        $defs  = SMF_Method_Catalog::local_definitions();
        if ( ! isset( $defs[ $route ] ) ) {
            return new WP_Error( 'smf_unknown_local_route', __( 'Unknown local payment method.', 'smart-myfatoorah' ) );
        }

        $methods = $this->discover_v2_methods( $order->get_total(), $order->get_currency() );
        if ( is_wp_error( $methods ) ) {
            return $methods;
        }

        $match = $this->find_method( $methods, $defs[ $route ]['names'], $defs[ $route ]['codes'] );
        if ( ! $match ) {
            return new WP_Error(
                'smf_local_unavailable',
                sprintf(
                    /* translators: %s: payment method label */
                    __( '%s is not enabled on this MyFatoorah account.', 'smart-myfatoorah' ),
                    $defs[ $route ]['label']
                )
            );
        }

        $invoice = $this->build_invoice_amount_and_items( $order );

        $payload = array(
            'InvoiceValue'       => $invoice['amount'],
            'PaymentMethodId'    => (int) $match['PaymentMethodId'],
            'CustomerName'       => $this->order_customer_name( $order ),
            'CustomerEmail'      => $order->get_billing_email(),
            'CallBackUrl'        => $callback_url,
            'ErrorUrl'           => add_query_arg( 'smf_result', 'error', $callback_url ),
            'DisplayCurrencyIso' => strtoupper( $order->get_currency() ),
            'Language'           => $this->language(),
            'CustomerReference'  => (string) $order->get_id(),
            'UserDefinedField'   => 'woocommerce_order_' . $order->get_id(),
            'WebhookUrl'         => SMF_Webhook_Controller::get_webhook_url(),
        );

        if ( ! empty( $invoice['items'] ) ) {
            $payload['InvoiceItems'] = $invoice['items'];
        }

        $expiry = $this->expiry_date_v2();
        if ( $expiry ) {
            $payload['ExpiryDate'] = $expiry;
        }

        $response = $this->request( 'POST', '/v2/ExecutePayment', $payload );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['Data']['PaymentURL'] ) || empty( $response['Data']['InvoiceId'] ) ) {
            return new WP_Error( 'smf_invalid_local_response', __( 'MyFatoorah returned an incomplete payment response.', 'smart-myfatoorah' ) );
        }

        $payment_url = self::sanitize_payment_url( $response['Data']['PaymentURL'] );
        if ( '' === $payment_url ) {
            return new WP_Error( 'smf_untrusted_payment_url', __( 'MyFatoorah returned an untrusted payment URL.', 'smart-myfatoorah' ) );
        }

        return array(
            'engine'      => 'v2',
            'route'       => $route,
            'invoice_id'  => (string) $response['Data']['InvoiceId'],
            'payment_id'  => '',
            'payment_url' => $payment_url,
            'raw'         => $response,
        );
    }

    public function create_qpay_payment( WC_Order $order, $callback_url ) {
        return $this->create_local_method_payment( $order, 'qpay', $callback_url );
    }

    public function create_v3_hosted_payment( WC_Order $order, $route, $callback_url ) {
        $map = array(
            'card'       => 'CARD',
            'apple_pay'  => 'APPLE_PAY',
            'google_pay' => 'GOOGLE_PAY',
        );
        $method = isset( $map[ $route ] ) ? $map[ $route ] : 'CARD';

        $payload = array(
            'PaymentMethod' => $method,
            'Order'         => array(
                'Amount'             => (float) $order->get_total(),
                'Currency'           => strtoupper( $order->get_currency() ),
                'ExternalIdentifier' => (string) $order->get_id(),
            ),
            'Customer'      => array(
                'Reference' => (string) $order->get_id(),
                'Name'      => $this->order_customer_name( $order ),
            ),
            'IntegrationUrls' => array(
                'Redirection' => $callback_url,
                'Webhook'     => SMF_Webhook_Controller::get_webhook_url(),
            ),
            'Language'      => $this->language(),
        );

        $email = $order->get_billing_email();
        if ( $email ) {
            $payload['Customer']['Email'] = $email;
        }

        $expiry = $this->expiry_date_v3();
        if ( $expiry ) {
            $payload['PaymentExpiry'] = $expiry;
        }

        $ip = $this->customer_ip();
        if ( $ip ) {
            $payload['IpAddress'] = $ip;
        }

        $response = $this->request( 'POST', '/v3/payments', $payload );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['Data']['PaymentURL'] ) || empty( $response['Data']['InvoiceId'] ) ) {
            return new WP_Error( 'smf_invalid_v3_response', __( 'MyFatoorah returned an incomplete payment response.', 'smart-myfatoorah' ) );
        }

        $payment_url = self::sanitize_payment_url( $response['Data']['PaymentURL'] );
        if ( '' === $payment_url ) {
            return new WP_Error( 'smf_untrusted_payment_url', __( 'MyFatoorah returned an untrusted payment URL.', 'smart-myfatoorah' ) );
        }

        return array(
            'engine'      => 'v3',
            'route'       => $route,
            'invoice_id'  => (string) $response['Data']['InvoiceId'],
            'payment_id'  => ! empty( $response['Data']['PaymentId'] ) ? (string) $response['Data']['PaymentId'] : '',
            'payment_url' => $payment_url,
            'raw'         => $response,
        );
    }

    public function get_payment_details( $payment_id ) {
        $payment_id = trim( (string) $payment_id );
        if ( '' === $payment_id ) {
            return new WP_Error( 'smf_missing_payment_id', __( 'Missing MyFatoorah payment ID.', 'smart-myfatoorah' ) );
        }

        $response = $this->request( 'GET', '/v3/payments/' . rawurlencode( $payment_id ) );
        if ( ! is_wp_error( $response ) ) {
            return $this->normalize_v3_payment( $response );
        }

        $fallback = $this->get_v2_payment_status( $payment_id, 'PaymentId' );
        if ( is_wp_error( $fallback ) ) {
            return $response;
        }

        return $fallback;
    }

    public function get_payment_by_invoice( $invoice_id ) {
        return $this->get_v2_payment_status( $invoice_id, 'InvoiceId' );
    }

    public function get_v2_payment_status( $key, $key_type ) {
        $response = $this->request(
            'POST',
            '/v2/GetPaymentStatus',
            array(
                'Key'     => (string) $key,
                'KeyType' => (string) $key_type,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->normalize_v2_payment( $response );
    }

    public function make_refund( $payment_id, $amount, $reason = '', $external_id = '', $currency = '' ) {
        if ( '' === $external_id ) {
            $external_id = 'woocommerce_refund_' . gmdate( 'YmdHis' );
        }

        $payload = array(
            'Key'                     => (string) $payment_id,
            'KeyType'                 => 'PaymentId',
            'ServiceChargeOnCustomer' => false,
            'Amount'                  => (float) $amount,
            'Comment'                 => (string) $reason,
            'ExternalIdentifier'      => (string) $external_id,
        );

        $currency = strtoupper( trim( (string) $currency ) );
        if ( $currency ) {
            $payload['CurrencyIso'] = $currency;
        }

        return $this->request(
            'POST',
            '/v2/MakeRefund',
            $payload
        );
    }

    public function register_apple_pay_domain( $domain = '' ) {
        if ( '' === $domain ) {
            $domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        }

        $domain = preg_replace( '#^www\.#i', '', strtolower( trim( (string) $domain ) ) );
        if ( '' === $domain ) {
            return new WP_Error( 'smf_apple_domain_missing', __( 'Could not determine the site domain for Apple Pay registration.', 'smart-myfatoorah' ) );
        }

        return $this->request(
            'POST',
            '/v2/RegisterApplePayDomain',
            array(
                'DomainName' => $domain,
            )
        );
    }

    /**
     * Build InvoiceValue and optional detailed InvoiceItems for V2 ExecutePayment.
     *
     * @return array{amount:float,items:array}
     */
    public function build_invoice_amount_and_items( WC_Order $order ) {
        $order_total = (float) $order->get_total();
        $decimals    = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

        if ( ! $this->invoice_items ) {
            return array(
                'amount' => round( $order_total, $decimals ),
                'items'  => array(
                    array(
                        'ItemName'  => sprintf(
                            /* translators: %s: order number */
                            __( 'Total amount for order #%s', 'smart-myfatoorah' ),
                            $order->get_order_number()
                        ),
                        'Quantity'  => 1,
                        'UnitPrice' => round( $order_total, $decimals ),
                    ),
                ),
            );
        }

        $items  = array();
        $amount = 0.0;

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $qty = (int) $item->get_quantity();
            if ( $qty < 1 ) {
                continue;
            }

            $line_subtotal = (float) $order->get_line_subtotal( $item, false, false );
            $unit_price    = round( $line_subtotal / $qty, $decimals );
            $amount       += $line_subtotal;

            $items[] = array(
                'ItemName'  => wp_strip_all_tags( (string) $item->get_name() ),
                'Quantity'  => $qty,
                'UnitPrice' => $unit_price,
            );
        }

        $shipping = round( (float) $order->get_shipping_total(), $decimals );
        if ( $shipping > 0 ) {
            $label   = $order->get_shipping_method();
            $amount += $shipping;
            $items[] = array(
                'ItemName'  => $label ? wp_strip_all_tags( $label ) : __( 'Shipping', 'smart-myfatoorah' ),
                'Quantity'  => 1,
                'UnitPrice' => $shipping,
            );
        }

        $discount = round( (float) $order->get_discount_total(), $decimals );
        if ( $discount > 0 ) {
            $amount -= $discount;
            $items[] = array(
                'ItemName'  => __( 'Discount', 'smart-myfatoorah' ),
                'Quantity'  => 1,
                'UnitPrice' => -1 * $discount,
            );
        }

        foreach ( $order->get_items( 'fee' ) as $fee_item ) {
            if ( ! $fee_item instanceof WC_Order_Item_Fee ) {
                continue;
            }
            $fee_total = round( (float) $fee_item->get_total(), $decimals );
            if ( 0.0 === $fee_total ) {
                continue;
            }
            $amount += $fee_total;
            $items[] = array(
                'ItemName'  => wp_strip_all_tags( (string) $fee_item->get_name() ),
                'Quantity'  => 1,
                'UnitPrice' => $fee_total,
            );
        }

        // pw-woocommerce-gift-cards and similar credit lines.
        foreach ( $order->get_items( 'pw_gift_card' ) as $gift_item ) {
            $gift_amount = 0.0;
            if ( is_object( $gift_item ) && method_exists( $gift_item, 'get_amount' ) ) {
                $gift_amount = round( (float) $gift_item->get_amount(), $decimals );
            }
            if ( $gift_amount <= 0 ) {
                continue;
            }
            $amount -= $gift_amount;
            $items[] = array(
                'ItemName'  => __( 'Gift Card', 'smart-myfatoorah' ),
                'Quantity'  => 1,
                'UnitPrice' => -1 * $gift_amount,
            );
        }

        $tax = round( (float) $order->get_total_tax(), $decimals );
        if ( $tax > 0 ) {
            // Align item sum with WooCommerce order total (tax-inclusive vs exclusive edge cases).
            $tax_adjust = round( $order_total - $amount, $decimals );
            if ( abs( $tax_adjust ) > 0 ) {
                $amount += $tax_adjust;
                $items[] = array(
                    'ItemName'  => __( 'Taxes', 'smart-myfatoorah' ),
                    'Quantity'  => 1,
                    'UnitPrice' => $tax_adjust,
                );
            }
        } else {
            $diff = round( $order_total - $amount, $decimals );
            if ( abs( $diff ) > 0 ) {
                $amount += $diff;
                $items[] = array(
                    'ItemName'  => __( 'Adjustment', 'smart-myfatoorah' ),
                    'Quantity'  => 1,
                    'UnitPrice' => $diff,
                );
            }
        }

        if ( empty( $items ) ) {
            $items[] = array(
                'ItemName'  => sprintf(
                    /* translators: %s: order number */
                    __( 'Total amount for order #%s', 'smart-myfatoorah' ),
                    $order->get_order_number()
                ),
                'Quantity'  => 1,
                'UnitPrice' => round( $order_total, $decimals ),
            );
            $amount = $order_total;
        }

        return array(
            'amount' => round( $amount, $decimals ),
            'items'  => $items,
        );
    }

    private function expiry_date_v2() {
        if ( $this->expiry_minutes < 1 ) {
            return '';
        }

        try {
            $now = new DateTime( 'now', new DateTimeZone( 'Asia/Kuwait' ) );
            $now->modify( '+' . $this->expiry_minutes . ' minutes' );
            return $now->format( 'Y-m-d\TH:i:s' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    private function expiry_date_v3() {
        if ( $this->expiry_minutes < 1 ) {
            return '';
        }

        return gmdate( 'Y-m-d\TH:i:s\Z', time() + ( $this->expiry_minutes * MINUTE_IN_SECONDS ) );
    }

    private function normalize_v3_payment( $response ) {
        $data        = isset( $response['Data'] ) && is_array( $response['Data'] ) ? $response['Data'] : array();
        $invoice     = isset( $data['Invoice'] ) && is_array( $data['Invoice'] ) ? $data['Invoice'] : array();
        $transaction = isset( $data['Transaction'] ) && is_array( $data['Transaction'] ) ? $data['Transaction'] : array();
        $customer    = isset( $data['Customer'] ) && is_array( $data['Customer'] ) ? $data['Customer'] : array();
        $amount      = isset( $data['Amount'] ) && is_array( $data['Amount'] ) ? $data['Amount'] : array();
        $error       = isset( $transaction['Error'] ) && is_array( $transaction['Error'] ) ? $transaction['Error'] : array();

        $customer_reference = isset( $customer['Reference'] ) ? trim( (string) $customer['Reference'] ) : '';
        if ( '' === $customer_reference && isset( $invoice['ExternalIdentifier'] ) ) {
            // V3 often puts the Woo order id on Invoice.ExternalIdentifier instead.
            $customer_reference = trim( (string) $invoice['ExternalIdentifier'] );
        }

        return array(
            'source'             => 'v3',
            'invoice_id'         => isset( $invoice['Id'] ) ? (string) $invoice['Id'] : '',
            'invoice_status'     => isset( $invoice['Status'] ) ? strtoupper( (string) $invoice['Status'] ) : '',
            'transaction_status' => isset( $transaction['Status'] ) ? strtoupper( (string) $transaction['Status'] ) : '',
            'payment_id'         => isset( $transaction['PaymentId'] ) ? (string) $transaction['PaymentId'] : '',
            'transaction_id'     => isset( $transaction['Id'] ) ? (string) $transaction['Id'] : '',
            'payment_method'     => isset( $transaction['PaymentMethod'] ) ? (string) $transaction['PaymentMethod'] : '',
            'customer_reference' => $customer_reference,
            'amount'             => isset( $amount['ValueInDisplayCurrency'] ) ? (float) $amount['ValueInDisplayCurrency'] : null,
            'currency'           => isset( $amount['DisplayCurrency'] ) ? strtoupper( (string) $amount['DisplayCurrency'] ) : '',
            'error_code'         => isset( $error['Code'] ) ? (string) $error['Code'] : '',
            'error_message'      => isset( $error['Message'] ) ? (string) $error['Message'] : '',
            'is_paid'            => isset( $invoice['Status'], $transaction['Status'] ) && 'PAID' === strtoupper( (string) $invoice['Status'] ) && 'SUCCESS' === strtoupper( (string) $transaction['Status'] ),
            'raw'                => $response,
        );
    }

    private function normalize_v2_payment( $response ) {
        $data = isset( $response['Data'] ) && is_array( $response['Data'] ) ? $response['Data'] : array();
        $transactions = isset( $data['InvoiceTransactions'] ) && is_array( $data['InvoiceTransactions'] ) ? $data['InvoiceTransactions'] : array();
        $tx = array();

        foreach ( $transactions as $transaction ) {
            if ( ! is_array( $transaction ) ) {
                continue;
            }
            $status = isset( $transaction['TransactionStatus'] ) ? strtoupper( (string) $transaction['TransactionStatus'] ) : '';
            $tx = $transaction;
            if ( in_array( $status, array( 'SUCCSS', 'SUCCESS' ), true ) ) {
                break;
            }
        }

        $invoice_status = isset( $data['InvoiceStatus'] ) ? strtoupper( (string) $data['InvoiceStatus'] ) : '';
        $tx_status      = isset( $tx['TransactionStatus'] ) ? strtoupper( (string) $tx['TransactionStatus'] ) : '';

        $error_code = '';
        $error_message = '';
        if ( isset( $tx['Error'] ) ) {
            $error_message = (string) $tx['Error'];
        } elseif ( isset( $tx['ErrorMessage'] ) ) {
            $error_message = (string) $tx['ErrorMessage'];
        }
        if ( isset( $tx['ErrorCode'] ) ) {
            $error_code = (string) $tx['ErrorCode'];
        }

        $display_amount = null;
        $currency       = '';

        // Prefer explicit transaction display/paid fields before parsing a free-text display value.
        if ( isset( $tx['PaidCurrency'] ) && '' !== trim( (string) $tx['PaidCurrency'] ) ) {
            $currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $tx['PaidCurrency'] ) );
        } elseif ( isset( $tx['Currency'] ) && '' !== trim( (string) $tx['Currency'] ) ) {
            $currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $tx['Currency'] ) );
        } elseif ( ! empty( $data['InvoiceTransactions'][0]['PaidCurrency'] ) ) {
            $currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $data['InvoiceTransactions'][0]['PaidCurrency'] ) );
        }

        if ( isset( $tx['PaidCurrencyValue'] ) && is_numeric( $tx['PaidCurrencyValue'] ) ) {
            $display_amount = (float) $tx['PaidCurrencyValue'];
        } elseif ( isset( $tx['TransationValue'] ) && is_numeric( $tx['TransationValue'] ) ) {
            // MyFatoorah V2 typo field name is intentional in their API.
            $display_amount = (float) $tx['TransationValue'];
        }

        if ( isset( $data['InvoiceDisplayValue'] ) && is_string( $data['InvoiceDisplayValue'] ) && '' !== trim( $data['InvoiceDisplayValue'] ) ) {
            $display_raw = trim( (string) $data['InvoiceDisplayValue'] );
            if ( null === $display_amount && preg_match( '/([0-9]+(?:[.,][0-9]+)?)/', $display_raw, $amount_match ) ) {
                $display_amount = (float) str_replace( ',', '', $amount_match[1] );
            }
            if ( ! $currency && preg_match( '/\b([A-Za-z]{3})\b/', $display_raw, $currency_match ) ) {
                $currency = strtoupper( $currency_match[1] );
            }
        }

        // Only fall back to InvoiceValue (often base currency) when we already know currency
        // or have no better amount — caller verification will still enforce order currency.
        if ( null === $display_amount && isset( $data['InvoiceValue'] ) && is_numeric( $data['InvoiceValue'] ) ) {
            $display_amount = (float) $data['InvoiceValue'];
        }

        if ( ! $currency && isset( $data['InvoiceTransactions'][0]['Currency'] ) ) {
            $currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $data['InvoiceTransactions'][0]['Currency'] ) );
        }

        // Prefer a coherent paid state: invoice paid, and success transaction when present.
        $invoice_paid = in_array( $invoice_status, array( 'PAID', 'SUCCESS' ), true );
        $tx_success   = in_array( $tx_status, array( 'SUCCSS', 'SUCCESS' ), true );
        $paid         = $invoice_paid && ( '' === $tx_status || $tx_success );

        return array(
            'source'             => 'v2',
            'invoice_id'         => isset( $data['InvoiceId'] ) ? (string) $data['InvoiceId'] : '',
            'invoice_status'     => $invoice_status,
            'transaction_status' => $tx_status,
            'payment_id'         => isset( $tx['PaymentId'] ) ? (string) $tx['PaymentId'] : '',
            'transaction_id'     => isset( $tx['TransactionId'] ) ? (string) $tx['TransactionId'] : '',
            'payment_method'     => isset( $tx['PaymentGateway'] ) ? (string) $tx['PaymentGateway'] : '',
            'customer_reference' => isset( $data['CustomerReference'] ) ? (string) $data['CustomerReference'] : '',
            'amount'             => $display_amount,
            'currency'           => $currency,
            'error_code'         => $error_code,
            'error_message'      => $error_message,
            'is_paid'            => $paid,
            'raw'                => $response,
        );
    }

    private function find_method( $methods, $names, $codes ) {
        foreach ( $methods as $method ) {
            if ( ! is_array( $method ) ) {
                continue;
            }

            $name = isset( $method['PaymentMethodEn'] ) ? strtolower( trim( (string) $method['PaymentMethodEn'] ) ) : '';
            $code = isset( $method['PaymentMethodCode'] ) ? strtolower( trim( (string) $method['PaymentMethodCode'] ) ) : '';

            foreach ( $names as $candidate ) {
                if ( strtolower( $candidate ) === $name ) {
                    return $method;
                }
            }
            foreach ( $codes as $candidate ) {
                if ( strtolower( $candidate ) === $code ) {
                    return $method;
                }
            }
        }
        return null;
    }

    /**
     * Allow only HTTPS MyFatoorah-hosted payment URLs (blocks open redirects).
     *
     * @param string $url Raw PaymentURL from the API.
     * @return string Sanitized URL or empty string when untrusted.
     */
    public static function sanitize_payment_url( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( '' === $url || 0 !== stripos( $url, 'https://' ) ) {
            return '';
        }

        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host ) {
            return '';
        }

        if ( 'myfatoorah.com' === $host || substr( $host, -15 ) === '.myfatoorah.com' ) {
            return $url;
        }

        return '';
    }

    private function request( $method, $path, $body = null ) {
        if ( ! $this->has_token() ) {
            return new WP_Error( 'smf_missing_token', __( 'MyFatoorah API token is not configured.', 'smart-myfatoorah' ) );
        }

        $url = trailingslashit( $this->get_base_url() ) . ltrim( $path, '/' );
        $args = array(
            'method'    => strtoupper( $method ),
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
        );

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $this->log( 'Request ' . strtoupper( $method ) . ' ' . $path, is_array( $body ) ? $this->sanitize_log_response( $body ) : null );
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( 'HTTP error: ' . $response->get_error_message() );
            return new WP_Error( 'smf_http_error', $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );
        $json   = json_decode( $raw, true );

        if ( ! is_array( $json ) ) {
            $this->log( 'Invalid JSON response', array( 'status' => $status ) );
            return new WP_Error( 'smf_invalid_json', __( 'MyFatoorah returned an invalid response.', 'smart-myfatoorah' ) );
        }

        $this->log( 'Response ' . $status, $this->sanitize_log_response( $json ) );

        $success = isset( $json['IsSuccess'] ) ? (bool) $json['IsSuccess'] : ( $status >= 200 && $status < 300 );
        if ( $status < 200 || $status >= 300 || ! $success ) {
            $message = $this->response_message( $json, $status );
            return new WP_Error( 'smf_api_error', $message, array( 'status' => $status, 'response' => $json ) );
        }

        return $json;
    }

    private function response_message( $json, $status ) {
        if ( ! empty( $json['ValidationErrors'] ) && is_array( $json['ValidationErrors'] ) ) {
            $messages = array();
            foreach ( $json['ValidationErrors'] as $error ) {
                if ( is_array( $error ) && ! empty( $error['Error'] ) ) {
                    $messages[] = (string) $error['Error'];
                } elseif ( is_string( $error ) ) {
                    $messages[] = $error;
                }
            }
            if ( $messages ) {
                return implode( ' | ', $messages );
            }
        }

        if ( ! empty( $json['Message'] ) ) {
            return (string) $json['Message'];
        }

        return sprintf( __( 'MyFatoorah API request failed (HTTP %d).', 'smart-myfatoorah' ), (int) $status );
    }

    private function sanitize_log_response( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }

        $sensitive = array(
            'Token',
            'EncryptionKey',
            'SecurityCode',
            'CardNumber',
            'Number',
            'CustomerEmail',
            'Email',
            'CustomerMobile',
            'Mobile',
            'CustomerName',
            'Name',
            'Authorization',
            'api_token',
            'webhook_secret',
            'SessionId',
            'session_id',
        );
        foreach ( $data as $key => $value ) {
            if ( in_array( (string) $key, $sensitive, true ) ) {
                $data[ $key ] = '[redacted]';
            } elseif ( is_array( $value ) ) {
                $data[ $key ] = $this->sanitize_log_response( $value );
            }
        }
        return $data;
    }

    private function log( $message, $context = null ) {
        if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
            return;
        }
        $logger = wc_get_logger();
        $text = (string) $message;
        if ( null !== $context ) {
            $text .= ' ' . wp_json_encode( $context );
        }
        $logger->debug( $text, array( 'source' => 'smart-myfatoorah' ) );
    }

    private function order_customer_name( WC_Order $order ) {
        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        return '' !== $name ? $name : __( 'Customer', 'smart-myfatoorah' );
    }

    private function language() {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        return 0 === strpos( strtolower( (string) $locale ), 'ar' ) ? 'AR' : 'EN';
    }

    private function customer_ip() {
        if ( class_exists( 'WC_Geolocation' ) && method_exists( 'WC_Geolocation', 'get_ip_address' ) ) {
            return WC_Geolocation::get_ip_address();
        }
        return '';
    }

    private function extract_currency( $display_value ) {
        if ( preg_match( '/\b([A-Z]{3})\b/', strtoupper( (string) $display_value ), $matches ) ) {
            return $matches[1];
        }
        return '';
    }
}
