<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Router {
    private $settings;

    public function __construct( $settings = array() ) {
        $this->settings = $settings;
    }

    public function resolve( WC_Order $order, $requested = 'smart' ) {
        $requested = sanitize_key( (string) $requested );
        $allowed   = array_merge(
            array( 'smart', 'card', 'apple_pay', 'google_pay' ),
            SMF_Method_Catalog::local_route_ids()
        );

        if ( ! in_array( $requested, $allowed, true ) ) {
            $requested = 'smart';
        }

        if ( 'qpay' === $requested && ! $this->merchant_supports_qpay() ) {
            $requested = 'smart';
        }

        if ( 'yes' === $this->get( 'allow_manual_override', 'yes' ) && 'smart' !== $requested ) {
            return $this->sanitize_resolved_route( $requested, $order );
        }

        if ( 'yes' === $this->get( 'remember_preference', 'yes' ) && $order->get_customer_id() ) {
            $saved = sanitize_key( (string) get_user_meta( $order->get_customer_id(), '_smf_preferred_route', true ) );
            if ( in_array( $saved, $allowed, true ) && 'smart' !== $saved ) {
                return $this->sanitize_resolved_route( $saved, $order );
            }
        }

        return $this->smart_route_for_order( $order );
    }

    /**
     * Pick best route from billing country + methods available on the MF account.
     */
    public function smart_route_for_order( WC_Order $order ) {
        $country = $this->country_for_order( $order );
        $api     = new SMF_API_Client( $this->settings );
        $flags   = $api->get_enabled_route_flags( $order->get_total(), $order->get_currency() );

        $available_locals = array();
        foreach ( SMF_Method_Catalog::local_route_ids() as $route ) {
            if ( 'qpay' === $route && ! $this->merchant_supports_qpay() ) {
                continue;
            }
            if ( ! empty( $flags[ $route ] ) ) {
                $available_locals[ $route ] = true;
            }
        }

        if ( 'yes' === $this->get( 'show_gcc_locals', 'yes' ) ) {
            $preferred = SMF_Method_Catalog::preferred_local_for_country( $country, $available_locals );
            if ( $preferred ) {
                return $preferred;
            }
        } elseif ( 'QA' === $country && ! empty( $available_locals['qpay'] ) ) {
            return 'qpay';
        }

        return 'card';
    }

    /**
     * Drop routes that are invalid for this merchant account / customer country.
     *
     * @param string        $route Route id.
     * @param WC_Order|null $order Order for country check.
     * @return string
     */
    private function sanitize_resolved_route( $route, $order = null ) {
        if ( 'qpay' === $route && ! $this->merchant_supports_qpay() ) {
            return 'card';
        }

        if ( SMF_Method_Catalog::is_local_route( $route ) && $order instanceof WC_Order ) {
            $country = $this->country_for_order( $order );
            $ok      = SMF_Method_Catalog::countries_for_route( $route );
            if ( $ok && ! in_array( $country, $ok, true ) ) {
                return 'card';
            }
        }

        return $route;
    }

    public function merchant_supports_qpay() {
        return 'QAT' === strtoupper( (string) $this->get( 'merchant_country', 'QAT' ) );
    }

    public function country_for_order( WC_Order $order ) {
        $billing = strtoupper( trim( (string) $order->get_billing_country() ) );
        if ( $billing ) {
            return $billing;
        }

        $shipping = strtoupper( trim( (string) $order->get_shipping_country() ) );
        if ( $shipping ) {
            return $shipping;
        }

        return self::geolocated_country();
    }

    public static function geolocated_country() {
        if ( class_exists( 'WC_Geolocation' ) ) {
            $geo = WC_Geolocation::geolocate_ip( '', true, false );
            if ( ! empty( $geo['country'] ) ) {
                return strtoupper( (string) $geo['country'] );
            }
        }
        return '';
    }

    private function get( $key, $default = '' ) {
        return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
    }
}
