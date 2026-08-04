<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Blocks_Payment_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name = 'smart_myfatoorah';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_smart_myfatoorah_settings', array() );
    }

    public function is_active() {
        return 'yes' === $this->get_setting( 'enabled', 'no' ) && '' !== trim( (string) $this->get_setting( 'api_token', '' ) );
    }

    public function get_payment_method_script_handles() {
        $deps = array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' );

        if ( $this->is_embedded_enabled() ) {
            $api    = new SMF_API_Client( $this->settings );
            $portal = trailingslashit( $api->get_portal_base_url() );
            wp_register_script( 'smf-cardview', $portal . 'cardview/v2/session.js', array(), SMF_VERSION, true );
            $deps[] = 'smf-cardview';
        }

        wp_register_script(
            'smf-blocks',
            SMF_URL . 'assets/js/blocks.js',
            $deps,
            SMF_VERSION,
            true
        );
        wp_enqueue_style( 'smf-checkout', SMF_URL . 'assets/css/checkout.css', array(), SMF_VERSION );

        if ( SMF_Gateway::needs_google_font( $this->settings ) ) {
            wp_enqueue_style(
                'smf-checkout-fonts',
                'https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Noto+Sans+Arabic:wght@400;600;700&family=Tajawal:wght@400;500;700&display=swap',
                array(),
                null
            );
        }

        $inline = SMF_Gateway::get_checkout_inline_css( $this->settings );
        if ( $inline ) {
            wp_add_inline_style( 'smf-checkout', $inline );
        }

        return array( 'smf-blocks' );
    }

    public function get_payment_method_data() {
        $display = isset( $this->settings['route_display_style'] ) ? $this->settings['route_display_style'] : 'logos';
        if ( ! in_array( $display, array( 'logos', 'text' ), true ) ) {
            $display = 'logos';
        }

        $embedded = $this->is_embedded_enabled();
        $session  = null;
        $api      = new SMF_API_Client( $this->settings );
        if ( $embedded ) {
            $created = $api->initiate_session();
            if ( ! is_wp_error( $created ) ) {
                $session = $created;
            }
        }

        $flags           = $api->get_enabled_route_flags();
        $qatar_merchant  = 'QAT' === strtoupper( (string) $this->get_setting( 'merchant_country', 'QAT' ) );
        $wallets_setting = 'yes' === $this->get_setting( 'show_wallet_overrides', 'yes' );
        $locals_setting  = 'yes' === $this->get_setting( 'show_gcc_locals', 'yes' );
        $qpay_available  = $qatar_merchant && ! empty( $flags['qpay'] );
        $apple_available = $wallets_setting && ! empty( $flags['apple_pay'] );
        $google_available = $wallets_setting && ! empty( $flags['google_pay'] );
        $remote_images   = isset( $flags['images'] ) && is_array( $flags['images'] ) ? $flags['images'] : array();

        $local_methods = array();
        foreach ( SMF_Method_Catalog::local_definitions() as $route => $def ) {
            $enabled = ! empty( $flags[ $route ] );
            if ( 'qpay' === $route ) {
                $enabled = $qpay_available;
            } elseif ( ! $locals_setting ) {
                $enabled = false;
            }
            if ( ! $enabled ) {
                continue;
            }
            $local_methods[] = array(
                'route'     => $route,
                'countries' => $def['countries'],
                'label'     => $def['label'],
                'help'      => $def['help'],
                'caption'   => $def['caption'],
                'logo'      => SMF_Method_Catalog::logo_url( $route, isset( $remote_images[ $route ] ) ? $remote_images[ $route ] : '' ),
            );
        }

        $logo_captions = array(
            'qpay'       => SMF_Gateway::get_route_logo_caption( 'qpay' ),
            'cardQatar'  => SMF_Gateway::get_route_logo_caption( 'card', true ),
            'cardOnly'   => SMF_Gateway::get_route_logo_caption( 'card', false ),
            'card'       => SMF_Gateway::get_route_logo_caption( 'card', $qpay_available ),
            'apple_pay'  => SMF_Gateway::get_route_logo_caption( 'apple_pay' ),
            'google_pay' => SMF_Gateway::get_route_logo_caption( 'google_pay' ),
        );
        foreach ( $local_methods as $row ) {
            $logo_captions[ $row['route'] ] = $row['caption'];
        }

        $availability = array(
            'qpay'       => $qpay_available,
            'card'       => ! empty( $flags['card'] ),
            'apple_pay'  => $apple_available,
            'google_pay' => $google_available,
        );
        foreach ( SMF_Method_Catalog::local_route_ids() as $route ) {
            if ( 'qpay' === $route ) {
                continue;
            }
            $availability[ $route ] = $locals_setting && ! empty( $flags[ $route ] );
        }

        $display_classes = SMF_Gateway::resolve_display_classes( is_array( $this->settings ) ? $this->settings : array() );
        $checkout_desc   = SMF_Gateway::resolve_checkout_description(
            is_array( $this->settings ) ? $this->settings : array(),
            $availability
        );

        return array(
            'title'                 => SMF_I18n::setting( $this->settings, 'title', 'Secure payment' ),
            'description'           => $checkout_desc,
            'allowManualOverride'   => 'yes' === $this->get_setting( 'allow_manual_override', 'yes' ),
            'showWalletOverrides'   => $wallets_setting,
            'qpayAvailable'         => $qpay_available,
            'applePayAvailable'     => $apple_available,
            'googlePayAvailable'    => $google_available,
            'qatarMerchant'         => $qatar_merchant,
            'displayStyle'          => $display,
            'displayClasses'        => $display_classes,
            'routeColumns'          => isset( $this->settings['route_columns'] ) ? (string) $this->settings['route_columns'] : '2',
            'logoLayout'            => isset( $this->settings['logo_layout'] ) ? (string) $this->settings['logo_layout'] : 'cards',
            'textLayout'            => isset( $this->settings['text_layout'] ) ? (string) $this->settings['text_layout'] : 'list',
            'logos'                 => SMF_Gateway::get_route_logo_urls( $remote_images ),
            'localMethods'          => $local_methods,
            'qpayLabel'             => SMF_I18n::setting( $this->settings, 'qpay_label', 'QPay — Qatar debit cards' ),
            'cardLabel'             => SMF_I18n::setting( $this->settings, 'card_label', 'Visa / Mastercard' ),
            'recommendedQatar'      => __( 'Based on your country, QPay is pre-selected. You can choose another method below.', 'smart-myfatoorah' ),
            'recommendedLocal'      => __( 'Based on your country, a local payment method is pre-selected. You can choose another method below.', 'smart-myfatoorah' ),
            'recommendedCard'       => __( 'Based on your country, card payment is pre-selected. You can choose another method below.', 'smart-myfatoorah' ),
            'recommendedPill'       => __( 'Recommended', 'smart-myfatoorah' ),
            'routesAriaLabel'       => __( 'Payment method', 'smart-myfatoorah' ),
            'qpayHelp'              => __( 'Best for Qatar-issued debit cards.', 'smart-myfatoorah' ),
            'cardHelp'              => __( 'For credit cards and international bank cards.', 'smart-myfatoorah' ),
            'cardHelpQatar'         => __( 'For credit cards and international bank cards.', 'smart-myfatoorah' ),
            'cardHelpOnly'          => __( 'Visa and Mastercard debit or credit cards.', 'smart-myfatoorah' ),
            'applePayLabel'         => __( 'Apple Pay', 'smart-myfatoorah' ),
            'googlePayLabel'        => __( 'Google Pay', 'smart-myfatoorah' ),
            'logoCaptions'          => $logo_captions,
            'supports'              => array( 'products' ),
            'embeddedEnabled'       => $embedded,
            'embeddedSession'       => $session,
            'currency'              => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'QAR',
            'direction'             => ( function_exists( 'is_rtl' ) && is_rtl() ) ? 'rtl' : '',
            'embeddedHint'          => __( 'Card details', 'smart-myfatoorah' ),
            'embeddedUnavailable'   => __( 'Embedded card form is temporarily unavailable. Card payments will use the secure MyFatoorah hosted page instead.', 'smart-myfatoorah' ),
            'submitError'           => __( 'Please check your card details and try again.', 'smart-myfatoorah' ),
            'placeholders'          => array(
                'holderName'   => __( 'Name On Card', 'smart-myfatoorah' ),
                'cardNumber'   => __( 'Card number', 'smart-myfatoorah' ),
                'expiryDate'   => __( 'MM / YY', 'smart-myfatoorah' ),
                'securityCode' => __( 'CVV', 'smart-myfatoorah' ),
            ),
            'cardViewStyle'         => array(
                'cardHeight'   => 190,
                'inputHeight'  => '40px',
                'fontSize'     => '14px',
                'borderRadius' => '12px',
                'borderWidth'  => '1px',
                'inputMargin'  => '4px',
            ),
            'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
            'checkoutNonce'         => wp_create_nonce( 'smf_checkout' ),
            'customStyle'           => 'custom' === $this->get_setting( 'style_mode', 'theme' ),
            'styleVars'             => SMF_Gateway::get_checkout_css_variables( $this->settings ),
        );
    }

    private function is_embedded_enabled() {
        return 'yes' === $this->get_setting( 'embedded_card', 'yes' );
    }
}
