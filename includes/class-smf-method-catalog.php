<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Known MyFatoorah method codes → Smart routes, country affinity, logos.
 *
 * Local files in assets/images/ are preferred; MyFatoorah ImageUrl is the fallback.
 */
final class SMF_Method_Catalog {

    /**
     * GCC / regional local methods (V2 ExecutePayment by PaymentMethodId).
     *
     * @return array<string,array{
     *   codes:array<int,string>,
     *   names:array<int,string>,
     *   countries:array<int,string>,
     *   logo:string,
     *   label:string,
     *   help:string,
     *   caption:string
     * }>
     */
    public static function local_definitions() {
        return array(
            'qpay'     => array(
                'codes'     => array( 'np' ),
                'names'     => array( 'qpay' ),
                'countries' => array( 'QA' ),
                'logo'      => 'qpay.png',
                'label'     => __( 'QPay — Qatar debit cards', 'smart-myfatoorah' ),
                'help'      => __( 'Best for Qatar-issued debit cards.', 'smart-myfatoorah' ),
                'caption'   => __( 'Local Qatar · Prepaid - Debit Card', 'smart-myfatoorah' ),
            ),
            'knet'     => array(
                'codes'     => array( 'kn' ),
                'names'     => array( 'knet' ),
                'countries' => array( 'KW' ),
                'logo'      => 'knet.png',
                'label'     => __( 'KNET — Kuwait debit cards', 'smart-myfatoorah' ),
                'help'      => __( 'Best for Kuwait-issued debit cards.', 'smart-myfatoorah' ),
                'caption'   => __( 'Kuwait · KNET', 'smart-myfatoorah' ),
            ),
            'benefit'  => array(
                'codes'     => array( 'bn' ),
                'names'     => array( 'benefit' ),
                'countries' => array( 'BH' ),
                'logo'      => 'benefit.png',
                'label'     => __( 'Benefit — Bahrain debit cards', 'smart-myfatoorah' ),
                'help'      => __( 'Best for Bahrain-issued debit cards.', 'smart-myfatoorah' ),
                'caption'   => __( 'Bahrain · Benefit', 'smart-myfatoorah' ),
            ),
            'mada'     => array(
                'codes'     => array( 'md' ),
                'names'     => array( 'mada' ),
                'countries' => array( 'SA' ),
                'logo'      => 'mada.png',
                'label'     => __( 'Mada — Saudi debit cards', 'smart-myfatoorah' ),
                'help'      => __( 'Best for Saudi Mada cards.', 'smart-myfatoorah' ),
                'caption'   => __( 'Saudi · Mada', 'smart-myfatoorah' ),
            ),
            'stc_pay'  => array(
                'codes'     => array( 'stc' ),
                'names'     => array( 'stc pay', 'stcpay' ),
                'countries' => array( 'SA' ),
                'logo'      => 'stc-pay.png',
                'label'     => __( 'STC Pay', 'smart-myfatoorah' ),
                'help'      => __( 'STC Pay wallet for Saudi customers.', 'smart-myfatoorah' ),
                'caption'   => __( 'Saudi · STC Pay', 'smart-myfatoorah' ),
            ),
            'meeza'    => array(
                'codes'     => array( 'mz', 'me' ),
                'names'     => array( 'meeza' ),
                'countries' => array( 'EG' ),
                'logo'      => 'meeza.png',
                'label'     => __( 'Meeza — Egypt cards', 'smart-myfatoorah' ),
                'help'      => __( 'Best for Egypt Meeza cards.', 'smart-myfatoorah' ),
                'caption'   => __( 'Egypt · Meeza', 'smart-myfatoorah' ),
            ),
        );
    }

    /**
     * @return array<int,string>
     */
    public static function local_route_ids() {
        return array_keys( self::local_definitions() );
    }

    /**
     * @param string $route Route id.
     */
    public static function is_local_route( $route ) {
        return isset( self::local_definitions()[ sanitize_key( (string) $route ) ] );
    }

    /**
     * Match a MyFatoorah method row to a Smart local route id.
     *
     * @param array $method InitiatePayment method row.
     * @return string Empty when not a known local method.
     */
    public static function match_local_route( array $method ) {
        $name = isset( $method['PaymentMethodEn'] ) ? strtolower( trim( (string) $method['PaymentMethodEn'] ) ) : '';
        $code = isset( $method['PaymentMethodCode'] ) ? strtolower( trim( (string) $method['PaymentMethodCode'] ) ) : '';

        foreach ( self::local_definitions() as $route => $def ) {
            if ( $code && in_array( $code, $def['codes'], true ) ) {
                return $route;
            }
            foreach ( $def['names'] as $candidate ) {
                if ( $name === $candidate || false !== strpos( $name, $candidate ) ) {
                    return $route;
                }
            }
        }

        return '';
    }

    /**
     * Whether a method row is Visa/Mastercard (not Mada).
     *
     * @param array $method Method row.
     */
    public static function is_card_method( array $method ) {
        $name = isset( $method['PaymentMethodEn'] ) ? strtolower( trim( (string) $method['PaymentMethodEn'] ) ) : '';
        $code = isset( $method['PaymentMethodCode'] ) ? strtolower( trim( (string) $method['PaymentMethodCode'] ) ) : '';

        if ( 'vm' === $code ) {
            return true;
        }
        if ( 'md' === $code || false !== strpos( $name, 'mada' ) ) {
            return false;
        }
        return ( false !== strpos( $name, 'visa' ) || false !== strpos( $name, 'master' ) );
    }

    /**
     * Preferred local route for a customer billing country (first match wins for SA: mada then stc).
     *
     * @param string             $country ISO2.
     * @param array<string,bool> $available Map route => enabled.
     * @return string Route id or empty.
     */
    public static function preferred_local_for_country( $country, array $available ) {
        $country = strtoupper( (string) $country );
        if ( '' === $country ) {
            return '';
        }

        // Stable priority within the same country.
        $priority = array( 'qpay', 'knet', 'benefit', 'mada', 'stc_pay', 'meeza' );
        foreach ( $priority as $route ) {
            $def = self::local_definitions()[ $route ];
            if ( ! in_array( $country, $def['countries'], true ) ) {
                continue;
            }
            if ( ! empty( $available[ $route ] ) ) {
                return $route;
            }
        }

        return '';
    }

    /**
     * Countries that should show this local route at checkout.
     *
     * @param string $route Route id.
     * @return array<int,string>
     */
    public static function countries_for_route( $route ) {
        $def = self::local_definitions();
        $route = sanitize_key( (string) $route );
        return isset( $def[ $route ] ) ? $def[ $route ]['countries'] : array();
    }

    /**
     * Logo URL: local asset if file exists, else remote MF image, else empty.
     *
     * @param string $route          Route id.
     * @param string $remote_fallback MyFatoorah ImageUrl.
     * @return string
     */
    public static function logo_url( $route, $remote_fallback = '' ) {
        $route = sanitize_key( (string) $route );
        $base  = trailingslashit( plugins_url( 'assets/images', SMF_FILE ) );
        $ver   = defined( 'SMF_VERSION' ) ? SMF_VERSION : '1';

        $builtins = array(
            'card'       => 'visa-mastercard.jpeg',
            'apple_pay'  => 'apple-pay.jpg',
            'google_pay' => 'google-pay.png',
        );

        $file = '';
        if ( isset( $builtins[ $route ] ) ) {
            $file = $builtins[ $route ];
        } elseif ( isset( self::local_definitions()[ $route ] ) ) {
            $file = self::local_definitions()[ $route ]['logo'];
        }

        if ( $file ) {
            $path = SMF_PATH . 'assets/images/' . $file;
            if ( is_readable( $path ) ) {
                return add_query_arg( 'ver', $ver, $base . $file );
            }
        }

        $remote = esc_url_raw( (string) $remote_fallback );
        if ( $remote && 0 === stripos( $remote, 'https://' ) ) {
            return $remote;
        }

        // Last resort: declare path even if missing (broken image is obvious for merchants adding logos).
        if ( $file ) {
            return add_query_arg( 'ver', $ver, $base . $file );
        }

        return '';
    }

    /**
     * Caption for a route.
     *
     * @param string $route      Route id.
     * @param bool   $qatar_pair QPay+card pair context for card caption.
     */
    public static function caption( $route, $qatar_pair = false ) {
        $route = sanitize_key( (string) $route );
        if ( 'card' === $route ) {
            if ( $qatar_pair ) {
                return __( 'International · Credit Card', 'smart-myfatoorah' );
            }
            return __( 'Debit Card - Credit Card', 'smart-myfatoorah' );
        }
        if ( 'apple_pay' === $route ) {
            return __( 'Apple Pay', 'smart-myfatoorah' );
        }
        if ( 'google_pay' === $route ) {
            return __( 'Google Pay', 'smart-myfatoorah' );
        }
        $def = self::local_definitions();
        return isset( $def[ $route ]['caption'] ) ? $def[ $route ]['caption'] : '';
    }

    /**
     * Filenames merchants should place in assets/images/.
     *
     * @return array<string,string> route => filename
     */
    public static function required_logo_files() {
        $files = array(
            'qpay'       => 'qpay.png',
            'card'       => 'visa-mastercard.jpeg',
            'apple_pay'  => 'apple-pay.jpg',
            'google_pay' => 'google-pay.png',
        );
        foreach ( self::local_definitions() as $route => $def ) {
            $files[ $route ] = $def['logo'];
        }
        return $files;
    }
}
