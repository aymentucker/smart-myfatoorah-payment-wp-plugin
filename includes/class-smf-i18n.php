<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Internationalization helpers.
 *
 * Source strings in code are English. Runtime display follows the site locale
 * via WordPress gettext + /languages/*.mo files.
 */
final class SMF_I18n {
    /**
     * Customer-facing option keys that ship with English defaults.
     * If the saved value still equals the English default, translate it.
     * Custom merchant text is left unchanged.
     *
     * @return array<string,string> option_key => english_default
     */
    public static function translatable_option_defaults() {
        return array(
            'title'      => 'Secure payment',
            'qpay_label' => 'QPay — Qatar debit cards',
            'card_label' => 'Visa / Mastercard',
        );
    }

    /**
     * Translate a stored option when it still matches the English default.
     *
     * @param string $stored          Saved option value.
     * @param string $english_default English source string (must exist in the catalog).
     * @return string
     */
    public static function maybe_translate_default( $stored, $english_default ) {
        $stored          = is_string( $stored ) ? trim( $stored ) : '';
        $english_default = (string) $english_default;

        if ( '' === $stored || $stored === $english_default ) {
            return __( $english_default, 'smart-myfatoorah' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        }

        return $stored;
    }

    /**
     * Read a settings array value with default translation support.
     *
     * @param array  $settings Settings array.
     * @param string $key      Option key.
     * @param string $english_default English default msgid.
     * @return string
     */
    public static function setting( $settings, $key, $english_default ) {
        $stored = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
        return self::maybe_translate_default( $stored, $english_default );
    }
}
