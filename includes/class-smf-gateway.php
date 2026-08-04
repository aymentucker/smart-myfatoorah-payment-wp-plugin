<?php
if (! defined('ABSPATH')) {
    exit;
}

final class SMF_Gateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id                 = 'smart_myfatoorah';
        $this->method_title       = __('Smart MyFatoorah', 'smart-myfatoorah');
        $this->method_description = __('Smart geographic routing: QPay for Qatar customers and card payment for international customers, with secure MyFatoorah callbacks and webhooks.', 'smart-myfatoorah');
        $this->has_fields         = true;
        $this->supports           = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = SMF_I18n::maybe_translate_default(
            $this->get_option('title', ''),
            'Secure payment'
        );
        // Checkout description is resolved dynamically in payment_fields / Blocks.
        $this->description = '';
        $this->enabled     = $this->get_option('enabled', 'no');

        if ('yes' === $this->get_option('enable_refunds', 'no')) {
            $this->supports[] = 'refunds';
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_assets'));
    }

    /**
     * Persist English defaults for translatable customer-facing options so
     * display can follow the active site locale automatically.
     */
    public function process_admin_options()
    {
        $result = parent::process_admin_options();
        $key      = $this->get_option_key();
        $settings = get_option($key, array());

        if (! is_array($settings)) {
            return $result;
        }

        $changed = false;
        foreach (SMF_I18n::translatable_option_defaults() as $option_key => $english_default) {
            if (! isset($settings[$option_key])) {
                continue;
            }

            $value = trim((string) $settings[$option_key]);
            $translated_default = __($english_default, 'smart-myfatoorah'); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText

            if ('' === $value || $value === $english_default || $value === $translated_default) {
                if (($settings[$option_key] ?? null) !== $english_default) {
                    $settings[$option_key] = $english_default;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            update_option($key, $settings);
            $this->settings = $settings;
            $this->title       = SMF_I18n::maybe_translate_default($settings['title'] ?? '', 'Secure payment');
            $this->description = '';
        }

        // Re-read after possible i18n normalize, then sanitize appearance colors/fonts.
        $settings = get_option($key, array());
        if (is_array($settings)) {
            $style_changed = false;
            foreach (
                array(
                    'style_text_color',
                    'style_muted_color',
                    'style_accent_color',
                    'style_bg_color',
                    'style_bg_soft_color',
                    'style_border_color',
                ) as $color_key
            ) {
                if (! isset($settings[$color_key])) {
                    continue;
                }
                $clean = self::sanitize_style_hex((string) $settings[$color_key]);
                if ($clean !== (string) $settings[$color_key]) {
                    $settings[$color_key] = $clean;
                    $style_changed        = true;
                }
            }
            if (isset($settings['style_font_custom'])) {
                $custom_font = sanitize_text_field((string) $settings['style_font_custom']);
                $custom_font = preg_replace('/[{};<>]|url\s*\(/i', '', $custom_font);
                $custom_font = trim((string) $custom_font);
                if ($custom_font !== (string) $settings['style_font_custom']) {
                    $settings['style_font_custom'] = $custom_font;
                    $style_changed                 = true;
                }
            }
            if (! in_array(($settings['style_mode'] ?? 'theme'), array('theme', 'custom'), true)) {
                $settings['style_mode'] = 'theme';
                $style_changed          = true;
            }
            if ($style_changed) {
                update_option($key, $settings);
                $this->settings = $settings;
            }
        }

        SMF_API_Client::clear_route_flags_cache();

        return $result;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'smart-myfatoorah'),
                'type'    => 'checkbox',
                'label'   => __('Enable Smart MyFatoorah Gateway', 'smart-myfatoorah'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Checkout title', 'smart-myfatoorah'),
                'type'        => 'text',
                'default'     => __('Secure payment', 'smart-myfatoorah'),
                'desc_tip'    => true,
                'description' => __('Title shown to customers at checkout.', 'smart-myfatoorah'),
            ),
            'description_mode' => array(
                'title'       => __('Checkout description mode', 'smart-myfatoorah'),
                'type'        => 'select',
                'default'     => 'auto',
                'options'     => array(
                    'auto'   => __('Automatic (based on enabled methods)', 'smart-myfatoorah'),
                    'custom' => __('Custom text', 'smart-myfatoorah'),
                ),
                'description' => __('Automatic text mentions local methods only when they are enabled on your MyFatoorah account.', 'smart-myfatoorah'),
            ),
            'description' => array(
                'title'       => __('Checkout description', 'smart-myfatoorah'),
                'type'        => 'textarea',
                'default'     => '',
                'description' => __('Used only when description mode is Custom. Leave empty to hide the description.', 'smart-myfatoorah'),
            ),
            'connection_heading' => array(
                'title' => __('MyFatoorah connection', 'smart-myfatoorah'),
                'type'  => 'title',
            ),
            'test_mode' => array(
                'title'   => __('Test mode', 'smart-myfatoorah'),
                'type'    => 'checkbox',
                'label'   => __('Use MyFatoorah sandbox', 'smart-myfatoorah'),
                'default' => 'yes',
            ),
            'merchant_country' => array(
                'title'       => __('Merchant country', 'smart-myfatoorah'),
                'type'        => 'select',
                'default'     => 'QAT',
                'class'       => 'smf-merchant-country',
                'options'     => array(
                    'QAT' => __('Qatar', 'smart-myfatoorah'),
                    'KWT' => __('Kuwait', 'smart-myfatoorah'),
                    'SAU' => __('Saudi Arabia', 'smart-myfatoorah'),
                    'ARE' => __('United Arab Emirates', 'smart-myfatoorah'),
                    'BHR' => __('Bahrain', 'smart-myfatoorah'),
                    'OMN' => __('Oman', 'smart-myfatoorah'),
                    'JOR' => __('Jordan', 'smart-myfatoorah'),
                    'EGY' => __('Egypt', 'smart-myfatoorah'),
                ),
                'description' => __('Choose the country of your MyFatoorah account. QPay checkout options appear only when Qatar is selected.', 'smart-myfatoorah'),
            ),
            'api_token' => array(
                'title'       => __('API token', 'smart-myfatoorah'),
                'type'        => 'password',
                'description' => __('Create an API key in your MyFatoorah portal. Never share this token publicly.', 'smart-myfatoorah'),
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'test_connection' => array(
                'title' => __('Connection test', 'smart-myfatoorah'),
                'type'  => 'test_connection',
            ),
            'routing_heading' => array(
                'title' => __('Smart payment routing', 'smart-myfatoorah'),
                'type'  => 'title',
                'description' => __('Billing country is used first, then shipping, then geolocation. QPay is offered only for Qatar merchant accounts and Qatar customers.', 'smart-myfatoorah'),
            ),
            'allow_manual_override' => array(
                'title'   => __('Customer override', 'smart-myfatoorah'),
                'type'    => 'checkbox',
                'label'   => __('Allow customers to change the recommended method', 'smart-myfatoorah'),
                'default' => 'yes',
            ),
            'remember_preference' => array(
                'title'   => __('Remember preference', 'smart-myfatoorah'),
                'type'    => 'checkbox',
                'label'   => __('Remember the last successful method for logged-in customers', 'smart-myfatoorah'),
                'default' => 'yes',
            ),
            'fallback_to_card' => array(
                'title'             => __('Local method fallback', 'smart-myfatoorah'),
                'type'              => 'checkbox',
                'label'             => __('If a local method (QPay/KNET/Benefit/Mada/…) fails, automatically fall back to card payment', 'smart-myfatoorah'),
                'default'           => 'yes',
                'description'       => __('Recommended. Prevents hard checkout errors when a regional method is temporarily unavailable.', 'smart-myfatoorah'),
            ),
            'show_gcc_locals' => array(
                'title'       => __('Regional local methods', 'smart-myfatoorah'),
                'type'        => 'checkbox',
                'label'       => __('Show KNET, Benefit, Mada, STC Pay, Meeza (and QPay) when enabled on the MyFatoorah account', 'smart-myfatoorah'),
                'default'     => 'yes',
                'description' => __('Each method appears only for matching customer countries and only if InitiatePayment returns it for your account.', 'smart-myfatoorah'),
            ),
            'show_wallet_overrides' => array(
                'title'       => __('Wallet overrides', 'smart-myfatoorah'),
                'type'        => 'checkbox',
                'label'       => __('Also allow Apple Pay and Google Pay as manual choices when enabled on the account', 'smart-myfatoorah'),
                'default'     => 'yes',
                'description' => __('Checkout only shows Apple Pay / Google Pay if MyFatoorah reports them as enabled for your account.', 'smart-myfatoorah'),
            ),
            'route_display_style' => array(
                'title'       => __('Checkout route display', 'smart-myfatoorah'),
                'type'        => 'select',
                'default'     => 'logos',
                'options'     => array(
                    'logos' => __('Logos (payment brand images)', 'smart-myfatoorah'),
                    'text'  => __('Text labels only', 'smart-myfatoorah'),
                ),
                'description' => __('Choose whether payment route options show brand logos or text labels at checkout.', 'smart-myfatoorah'),
            ),
            'route_columns' => array(
                'title'       => __('Methods per row', 'smart-myfatoorah'),
                'type'        => 'select',
                'default'     => '2',
                'options'     => array(
                    '2' => __('2 per row', 'smart-myfatoorah'),
                    '3' => __('3 per row', 'smart-myfatoorah'),
                    '4' => __('4 per row', 'smart-myfatoorah'),
                ),
                'description' => __('Applies to logo layouts and to the text grid layout. On small screens the grid may drop to 2 or 1 columns.', 'smart-myfatoorah'),
            ),
            'logo_layout' => array(
                'title'       => __('Logo layout design', 'smart-myfatoorah'),
                'type'        => 'select',
                'default'     => 'cards',
                'options'     => array(
                    'cards'   => __('Cards — bordered tiles with caption', 'smart-myfatoorah'),
                    'compact' => __('Compact — denser tiles', 'smart-myfatoorah'),
                    'minimal' => __('Minimal — logo-first, subtle chrome', 'smart-myfatoorah'),
                ),
                'description' => __('Visual style when Checkout route display is Logos.', 'smart-myfatoorah'),
            ),
            'text_layout' => array(
                'title'       => __('Text layout design', 'smart-myfatoorah'),
                'type'        => 'select',
                'default'     => 'list',
                'options'     => array(
                    'list'  => __('List — stacked full-width rows', 'smart-myfatoorah'),
                    'pills' => __('Pills — wrapped chip buttons', 'smart-myfatoorah'),
                    'grid'  => __('Grid — multi-column text cards', 'smart-myfatoorah'),
                ),
                'description' => __('Visual style when Checkout route display is Text labels only.', 'smart-myfatoorah'),
            ),
            'qpay_label' => array(
                'title'             => __('QPay label', 'smart-myfatoorah'),
                'type'              => 'text',
                'default'           => __('QPay — Qatar debit cards', 'smart-myfatoorah'),
                'class'             => 'smf-qatar-only',
                'custom_attributes' => array(
                    'data-smf-qatar-only' => '1',
                ),
                'description'       => __('Shown only when merchant country is Qatar.', 'smart-myfatoorah'),
            ),
            'card_label' => array(
                'title'   => __('Card label', 'smart-myfatoorah'),
                'type'    => 'text',
                'default' => __('Visa / Mastercard', 'smart-myfatoorah'),
            ),
            'style_heading' => array(
                'title'       => __('Checkout appearance', 'smart-myfatoorah'),
                'type'        => 'title',
                'description' => __('Customize colors, fonts and corner radius for the Smart MyFatoorah box on the checkout page.', 'smart-myfatoorah'),
            ),
            'style_mode' => array(
                'title'       => __('Appearance mode', 'smart-myfatoorah'),
                'type'        => 'select',
                'default'     => 'theme',
                'class'       => 'smf-style-mode',
                'options'     => array(
                    'theme'  => __('Match theme (automatic)', 'smart-myfatoorah'),
                    'custom' => __('Custom colors & fonts', 'smart-myfatoorah'),
                ),
                'description' => __('Use theme colors automatically, or set your own brand colors and typography.', 'smart-myfatoorah'),
            ),
            'style_text_color' => array(
                'title'             => __('Text color', 'smart-myfatoorah'),
                'type'              => 'smf_color',
                'default'           => '#0f172a',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
            ),
            'style_muted_color' => array(
                'title'             => __('Muted text color', 'smart-myfatoorah'),
                'type'              => 'smf_color',
                'default'           => '#64748b',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
                'description'       => __('Used for description and secondary help text.', 'smart-myfatoorah'),
            ),
            'style_accent_color' => array(
                'title'             => __('Accent color', 'smart-myfatoorah'),
                'type'              => 'smf_color',
                'default'           => '#b45309',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
                'description'       => __('Highlights the recommended method, selected route and focus states.', 'smart-myfatoorah'),
            ),
            'style_bg_color' => array(
                'title'             => __('Background color', 'smart-myfatoorah'),
                'type'              => 'smf_color',
                'default'           => '#ffffff',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
            ),
            'style_bg_soft_color' => array(
                'title'             => __('Soft background', 'smart-myfatoorah'),
                'type'              => 'smf_color',
                'default'           => '#f4f6f8',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
                'description'       => __('Background of unselected payment route cards.', 'smart-myfatoorah'),
            ),
            'style_border_color' => array(
                'title'             => __('Border color', 'smart-myfatoorah'),
                'type'              => 'smf_color',
                'default'           => '#d7dde5',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
            ),
            'style_font_family' => array(
                'title'             => __('Font family', 'smart-myfatoorah'),
                'type'              => 'select',
                'default'           => 'inherit',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
                'options'           => array(
                    'inherit'     => __('Theme default', 'smart-myfatoorah'),
                    'system'      => __('System UI', 'smart-myfatoorah'),
                    'sans'        => __('Modern sans-serif', 'smart-myfatoorah'),
                    'serif'       => __('Classic serif', 'smart-myfatoorah'),
                    'arabic_sans' => __('Arabic-friendly sans', 'smart-myfatoorah'),
                    'cairo'       => __('Cairo', 'smart-myfatoorah'),
                    'tajawal'     => __('Tajawal', 'smart-myfatoorah'),
                    'custom'      => __('Custom CSS font stack', 'smart-myfatoorah'),
                ),
            ),
            'style_font_custom' => array(
                'title'             => __('Custom font stack', 'smart-myfatoorah'),
                'type'              => 'text',
                'default'           => '',
                'placeholder'       => '"Your Font", Tahoma, Arial, sans-serif',
                'class'             => 'smf-style-custom smf-style-font-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
                'description'       => __('Used only when Font family is set to Custom CSS font stack.', 'smart-myfatoorah'),
            ),
            'style_font_size' => array(
                'title'             => __('Base font size', 'smart-myfatoorah'),
                'type'              => 'select',
                'default'           => '14',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
                'options'           => array(
                    '13' => '13px',
                    '14' => '14px',
                    '15' => '15px',
                    '16' => '16px',
                    '17' => '17px',
                ),
            ),
            'style_title_weight' => array(
                'title'             => __('Title font weight', 'smart-myfatoorah'),
                'type'              => 'select',
                'default'           => '700',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
                'options'           => array(
                    '500' => __('Medium (500)', 'smart-myfatoorah'),
                    '600' => __('Semi-bold (600)', 'smart-myfatoorah'),
                    '700' => __('Bold (700)', 'smart-myfatoorah'),
                    '800' => __('Extra bold (800)', 'smart-myfatoorah'),
                ),
                'description'       => __('Applies to route captions and section titles inside the payment box.', 'smart-myfatoorah'),
            ),
            'style_radius' => array(
                'title'             => __('Corner radius', 'smart-myfatoorah'),
                'type'              => 'select',
                'default'           => '16',
                'class'             => 'smf-style-custom',
                'custom_attributes' => array('data-smf-style-custom' => '1'),
                'options'           => array(
                    '8'  => '8px',
                    '12' => '12px',
                    '16' => '16px',
                    '20' => '20px',
                    '24' => '24px',
                ),
            ),
            'embedded_card' => array(
                'title'       => __('Embedded card form', 'smart-myfatoorah'),
                'type'        => 'checkbox',
                'label'       => __('Show MyFatoorah CardView on checkout for Visa / Mastercard (no saved cards)', 'smart-myfatoorah'),
                'default'     => 'yes',
                'description' => __('QPay (Qatar only) and wallet routes still use hosted redirect. After card details are entered, the customer may still complete 3DS on MyFatoorah.', 'smart-myfatoorah'),
            ),
            'webhook_heading' => array(
                'title' => __('Webhook & reliability', 'smart-myfatoorah'),
                'type'  => 'title',
            ),
            'webhook_enabled' => array(
                'title'   => __('Webhook', 'smart-myfatoorah'),
                'type'    => 'checkbox',
                'label'   => __('Process MyFatoorah Webhook V2 payment and refund status events', 'smart-myfatoorah'),
                'default' => 'yes',
            ),
            'webhook_secret' => array(
                'title'       => __('Webhook Secret Key', 'smart-myfatoorah'),
                'type'        => 'password',
                'description' => $this->get_webhook_secret_description(),
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'reconciliation_enabled' => array(
                'title'   => __('Automatic reconciliation', 'smart-myfatoorah'),
                'type'    => 'checkbox',
                'label'   => __('Re-check pending payments every 15 minutes for up to 24 hours', 'smart-myfatoorah'),
                'default' => 'yes',
            ),
            'debug' => array(
                'title'   => __('Debug log', 'smart-myfatoorah'),
                'type'    => 'checkbox',
                'label'   => __('Write sanitized MyFatoorah logs to WooCommerce logs', 'smart-myfatoorah'),
                'default' => 'no',
                'description' => __('API tokens and sensitive card fields are never written by this plugin.', 'smart-myfatoorah'),
            ),
            'invoice_heading' => array(
                'title' => __('Invoice options', 'smart-myfatoorah'),
                'type'  => 'title',
            ),
            'invoice_items' => array(
                'title'       => __('Invoice line items', 'smart-myfatoorah'),
                'type'        => 'checkbox',
                'label'       => __('Send detailed product, shipping, discount, fee and tax lines to MyFatoorah (V2 / QPay)', 'smart-myfatoorah'),
                'default'     => 'yes',
                'description' => __('When disabled, MyFatoorah receives a single total-amount line. V3 card/wallet payments still send the order total only.', 'smart-myfatoorah'),
            ),
            'invoice_expiry_minutes' => array(
                'title'             => __('Invoice expiry (minutes)', 'smart-myfatoorah'),
                'type'              => 'number',
                'default'           => '0',
                'description'       => __('How long the MyFatoorah payment link stays valid. Use 0 to keep the portal default.', 'smart-myfatoorah'),
                'custom_attributes' => array(
                    'min'  => '0',
                    'step' => '1',
                ),
            ),
            'apple_pay_heading' => array(
                'title'       => __('Apple Pay domain', 'smart-myfatoorah'),
                'type'        => 'title',
                'description' => __('Required before Apple Pay works on your live domain. Host the Apple association file at /.well-known/apple-developer-merchantid-domain-association (from MyFatoorah support), then register the domain.', 'smart-myfatoorah'),
            ),
            'apple_pay_domain' => array(
                'title' => __('Register domain', 'smart-myfatoorah'),
                'type'  => 'apple_pay_domain',
            ),
            'refund_heading' => array(
                'title' => __('Refunds', 'smart-myfatoorah'),
                'type'  => 'title',
            ),
            'enable_refunds' => array(
                'title'   => __('WooCommerce refunds', 'smart-myfatoorah'),
                'type'    => 'checkbox',
                'label'   => __('Allow refund requests from WooCommerce orders', 'smart-myfatoorah'),
                'default' => 'no',
                'description' => __('Refunds are submitted to MyFatoorah first. WooCommerce updates the order when the REFUND_STATUS_CHANGED webhook confirms the refund (finance review may apply).', 'smart-myfatoorah'),
            ),
        );
    }

    public function is_available()
    {
        if (! parent::is_available()) {
            return false;
        }
        if ('' === trim((string) $this->get_option('api_token', ''))) {
            return false;
        }

        // Live checkout must be HTTPS (CardView / redirects / Apple Pay requirements).
        if ('yes' !== $this->get_option('test_mode', 'yes') && function_exists('wc_checkout_is_https') && ! wc_checkout_is_https()) {
            return false;
        }

        return true;
    }

    /**
     * Whether this gateway is connected to a Qatar MyFatoorah merchant account.
     */
    public function is_qatar_merchant()
    {
        return 'QAT' === strtoupper((string) $this->get_option('merchant_country', 'QAT'));
    }

    /**
     * QPay is only offered for Qatar merchant accounts.
     */
    public function supports_qpay()
    {
        return $this->is_qatar_merchant();
    }

    /**
     * Routes that may be shown at checkout for this merchant/account.
     *
     * @return array<string,bool>
     */
    public function get_checkout_route_availability()
    {
        $api   = new SMF_API_Client($this->settings);
        $flags = $api->get_enabled_route_flags();

        $wallets_setting = 'yes' === $this->get_option('show_wallet_overrides', 'yes');
        $locals_setting  = 'yes' === $this->get_option('show_gcc_locals', 'yes');

        $out = array(
            'qpay'       => $this->supports_qpay() && ! empty($flags['qpay']),
            'card'       => ! empty($flags['card']),
            'apple_pay'  => $wallets_setting && ! empty($flags['apple_pay']),
            'google_pay' => $wallets_setting && ! empty($flags['google_pay']),
            'images'     => isset($flags['images']) && is_array($flags['images']) ? $flags['images'] : array(),
        );

        foreach (SMF_Method_Catalog::local_route_ids() as $route) {
            if ('qpay' === $route) {
                continue;
            }
            $out[$route] = $locals_setting && ! empty($flags[$route]);
        }

        // When GCC locals disabled, still keep QPay (existing Qatar behavior) via $out['qpay'].
        if (! $locals_setting) {
            foreach (array('knet', 'benefit', 'mada', 'stc_pay', 'meeza') as $route) {
                $out[$route] = false;
            }
        }

        return $out;
    }

    /**
     * Whether embedded CardView is enabled for the card route.
     */
    public function is_embedded_enabled()
    {
        return 'yes' === $this->get_option('embedded_card', 'yes');
    }

    /**
     * Whether checkout routes should render brand logos.
     */
    public function uses_logo_display()
    {
        return 'logos' === $this->get_option('route_display_style', 'logos');
    }

    /**
     * Methods per row (2–4).
     */
    public function get_route_columns()
    {
        $cols = (string) $this->get_option('route_columns', '2');
        return in_array($cols, array('2', '3', '4'), true) ? (int) $cols : 2;
    }

    /**
     * Logo layout design id.
     */
    public function get_logo_layout()
    {
        $layout = (string) $this->get_option('logo_layout', 'cards');
        return in_array($layout, array('cards', 'compact', 'minimal'), true) ? $layout : 'cards';
    }

    /**
     * Text layout design id.
     */
    public function get_text_layout()
    {
        $layout = (string) $this->get_option('text_layout', 'list');
        return in_array($layout, array('list', 'pills', 'grid'), true) ? $layout : 'list';
    }

    /**
     * CSS modifier classes for the checkout box.
     */
    public function get_checkout_display_classes()
    {
        return self::resolve_display_classes($this->settings);
    }

    /**
     * Whether any regional local method is enabled for this checkout.
     *
     * @param array $availability From get_checkout_route_availability().
     */
    public function has_local_methods_available(array $availability)
    {
        foreach (SMF_Method_Catalog::local_route_ids() as $route) {
            if (! empty($availability[$route])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Customer-facing checkout description (auto or custom).
     *
     * @param array|null $availability Optional availability map.
     * @return string Empty when nothing should be shown.
     */
    public function get_checkout_description($availability = null)
    {
        if (null === $availability) {
            $availability = $this->get_checkout_route_availability();
        }
        return self::resolve_checkout_description($this->settings, $availability);
    }

    /**
     * @param array $settings     Gateway settings.
     * @param array $availability Availability flags.
     * @return string
     */
    public static function resolve_checkout_description(array $settings, array $availability)
    {
        $mode = isset($settings['description_mode']) ? (string) $settings['description_mode'] : 'auto';
        if (! in_array($mode, array('auto', 'custom'), true)) {
            $mode = 'auto';
        }

        if ('custom' === $mode) {
            $custom = isset($settings['description']) ? trim((string) $settings['description']) : '';
            $legacy = array(
                'Pay securely through MyFatoorah. We automatically recommend QPay for Qatar and card payment for other countries.',
                'ادفع بأمان عبر ماي فاتورة. نوصي تلقائيًا بـ QPay داخل قطر والدفع بالبطاقة للدول الأخرى.',
            );
            if (in_array($custom, $legacy, true)) {
                $mode = 'auto';
            } else {
                // Empty custom text intentionally hides the description.
                return $custom;
            }
        }

        $has_local = false;
        foreach (SMF_Method_Catalog::local_route_ids() as $route) {
            if (! empty($availability[$route])) {
                $has_local = true;
                break;
            }
        }

        if ($has_local) {
            return __('Pay securely through MyFatoorah. We recommend the best local method for your country when available, otherwise card payment.', 'smart-myfatoorah');
        }

        return __('Pay securely through MyFatoorah using Visa or Mastercard.', 'smart-myfatoorah');
    }

    /**
     * CSS modifier classes from settings.
     *
     * @param array $settings Gateway settings.
     */
    public static function resolve_display_classes(array $settings)
    {
        $style = isset($settings['route_display_style']) ? (string) $settings['route_display_style'] : 'logos';
        $cols  = isset($settings['route_columns']) ? (string) $settings['route_columns'] : '2';
        if (! in_array($cols, array('2', '3', '4'), true)) {
            $cols = '2';
        }

        $classes = array();
        if ('logos' === $style) {
            $layout = isset($settings['logo_layout']) ? (string) $settings['logo_layout'] : 'cards';
            if (! in_array($layout, array('cards', 'compact', 'minimal'), true)) {
                $layout = 'cards';
            }
            $classes[] = 'smf-checkout-box--logos';
            $classes[] = 'smf-logo-layout-' . $layout;
        } else {
            $layout = isset($settings['text_layout']) ? (string) $settings['text_layout'] : 'list';
            if (! in_array($layout, array('list', 'pills', 'grid'), true)) {
                $layout = 'list';
            }
            $classes[] = 'smf-checkout-box--text';
            $classes[] = 'smf-text-layout-' . $layout;
        }
        $classes[] = 'smf-cols-' . $cols;
        return implode(' ', $classes);
    }

    /**
     * Sanitize a #RRGGBB color for checkout styling.
     *
     * @param string $color Raw color.
     * @return string
     */
    public static function sanitize_style_hex($color)
    {
        $color = trim((string) $color);
        if ('' === $color) {
            return '';
        }
        if (function_exists('sanitize_hex_color')) {
            $safe = sanitize_hex_color($color);
            if ($safe) {
                return strtolower($safe);
            }
        }
        if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color)) {
            if (4 === strlen($color)) {
                return strtolower('#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3]);
            }
            return strtolower($color);
        }
        return '';
    }

    /**
     * Whether custom checkout appearance is enabled.
     */
    public function uses_custom_checkout_style()
    {
        return 'custom' === $this->get_option('style_mode', 'theme');
    }

    /**
     * CSS font-family value from settings.
     *
     * @param array|null $settings Optional settings map.
     * @return string
     */
    public static function resolve_font_family($settings = null)
    {
        if (null === $settings) {
            $settings = get_option('woocommerce_smart_myfatoorah_settings', array());
        }
        if (! is_array($settings)) {
            $settings = array();
        }

        $family = isset($settings['style_font_family']) ? sanitize_key((string) $settings['style_font_family']) : 'inherit';
        $custom = isset($settings['style_font_custom']) ? trim((string) $settings['style_font_custom']) : '';

        switch ($family) {
            case 'system':
                return 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
            case 'sans':
                return '"Helvetica Neue", Helvetica, Arial, sans-serif';
            case 'serif':
                return 'Georgia, "Times New Roman", Times, serif';
            case 'arabic_sans':
                return '"Noto Sans Arabic", "Segoe UI", Tahoma, Arial, sans-serif';
            case 'cairo':
                return '"Cairo", "Segoe UI", Tahoma, Arial, sans-serif';
            case 'tajawal':
                return '"Tajawal", "Segoe UI", Tahoma, Arial, sans-serif';
            case 'custom':
                return $custom !== '' ? $custom : 'inherit';
            case 'inherit':
            default:
                return 'inherit';
        }
    }

    /**
     * Checkout CSS custom properties for the payment box.
     *
     * @param array|null $settings Optional settings map.
     * @return array<string,string>
     */
    public static function get_checkout_css_variables($settings = null)
    {
        if (null === $settings) {
            $settings = get_option('woocommerce_smart_myfatoorah_settings', array());
        }
        if (! is_array($settings)) {
            $settings = array();
        }

        if ('custom' !== ($settings['style_mode'] ?? 'theme')) {
            return array();
        }

        $get = static function ($key, $default) use ($settings) {
            $value = isset($settings[$key]) ? self::sanitize_style_hex((string) $settings[$key]) : '';
            return $value !== '' ? $value : $default;
        };

        $font_size = isset($settings['style_font_size']) ? (string) $settings['style_font_size'] : '14';
        if (! in_array($font_size, array('13', '14', '15', '16', '17'), true)) {
            $font_size = '14';
        }

        $weight = isset($settings['style_title_weight']) ? (string) $settings['style_title_weight'] : '700';
        if (! in_array($weight, array('500', '600', '700', '800'), true)) {
            $weight = '700';
        }

        $radius = isset($settings['style_radius']) ? (string) $settings['style_radius'] : '16';
        if (! in_array($radius, array('8', '12', '16', '20', '24'), true)) {
            $radius = '16';
        }
        $radius_sm = (string) max(6, (int) $radius - 4);

        $accent = $get('style_accent_color', '#b45309');
        $bg     = $get('style_bg_color', '#ffffff');
        $soft   = $get('style_bg_soft_color', '#f4f6f8');
        $border = $get('style_border_color', '#d7dde5');

        return array(
            '--smf-text'           => $get('style_text_color', '#0f172a'),
            '--smf-muted'          => $get('style_muted_color', '#64748b'),
            '--smf-accent'         => $accent,
            '--smf-accent-soft'    => 'color-mix(in srgb, ' . $accent . ' 14%, transparent)',
            '--smf-accent-border'  => 'color-mix(in srgb, ' . $accent . ' 45%, transparent)',
            '--smf-focus'          => 'color-mix(in srgb, ' . $accent . ' 40%, transparent)',
            '--smf-bg'             => $bg,
            '--smf-bg-soft'        => $soft,
            '--smf-bg-selected'    => 'color-mix(in srgb, ' . $accent . ' 12%, ' . $bg . ')',
            '--smf-border'         => $border,
            '--smf-border-strong'  => $border,
            '--smf-font'           => self::resolve_font_family($settings),
            '--smf-font-size'      => $font_size . 'px',
            '--smf-title-weight'   => $weight,
            '--smf-radius'         => $radius . 'px',
            '--smf-radius-sm'      => $radius_sm . 'px',
        );
    }

    /**
     * Inline style attribute string for the checkout box.
     *
     * @param array|null $settings Optional settings map.
     * @return string
     */
    public static function get_checkout_style_attribute($settings = null)
    {
        $vars = self::get_checkout_css_variables($settings);
        if (! $vars) {
            return '';
        }

        $parts = array();
        foreach ($vars as $name => $value) {
            $parts[] = $name . ':' . $value;
        }
        return implode(';', $parts);
    }

    /**
     * Inline stylesheet when custom style is enabled.
     *
     * @param array|null $settings Optional settings map.
     * @return string
     */
    public static function get_checkout_inline_css($settings = null)
    {
        $vars = self::get_checkout_css_variables($settings);
        if (! $vars) {
            return '';
        }

        $decl = '';
        foreach ($vars as $name => $value) {
            $decl .= $name . ':' . $value . ' !important;';
        }

        return '.smf-checkout-box.smf-has-custom-style,.payment_box.payment_method_smart_myfatoorah .smf-checkout-box.smf-has-custom-style,.payment_box.payment_method_smart_myfatoorah:has(.smf-has-custom-style){' . $decl . '}'
            . '.smf-checkout-box.smf-has-custom-style .smf-recommendation{background:var(--smf-accent-soft)!important;border-color:var(--smf-accent-border)!important;color:var(--smf-text)!important;}'
            . '.smf-checkout-box.smf-has-custom-style .smf-recommendation::before{background:var(--smf-accent)!important;box-shadow:0 0 0 4px var(--smf-accent-soft)!important;}'
            . '.smf-checkout-box.smf-has-custom-style .smf-route-option{background:var(--smf-bg-soft)!important;border-color:var(--smf-border)!important;color:var(--smf-text)!important;}'
            . '.smf-checkout-box.smf-has-custom-style .smf-route-option.is-selected,.smf-checkout-box.smf-has-custom-style .smf-route-option:has(.smf-radio-input:checked){background:var(--smf-bg-selected)!important;border-color:var(--smf-accent-border)!important;box-shadow:inset 0 0 0 1px var(--smf-accent-border)!important;}'
            . '.smf-checkout-box.smf-has-custom-style .smf-radio{border-color:var(--smf-border-strong)!important;}'
            . '.smf-checkout-box.smf-has-custom-style .smf-route-option:hover .smf-radio,.smf-checkout-box.smf-has-custom-style .smf-route-option.is-selected .smf-radio,.smf-checkout-box.smf-has-custom-style .smf-radio-wrap:has(.smf-radio-input:checked) .smf-radio{border-color:var(--smf-accent)!important;box-shadow:inset 0 0 0 5px var(--smf-accent)!important;}'
            . '.smf-checkout-box.smf-has-custom-style .smf-logo-caption,.smf-checkout-box.smf-has-custom-style .smf-route-option strong{color:var(--smf-text)!important;}'
            . '.smf-checkout-box.smf-has-custom-style .smf-description,.smf-checkout-box.smf-has-custom-style .smf-description p{color:var(--smf-muted)!important;}';
    }

    /**
     * Whether a Google Font should be loaded for the selected family.
     *
     * @param array|null $settings Optional settings map.
     * @return bool
     */
    public static function needs_google_font($settings = null)
    {
        if (null === $settings) {
            $settings = get_option('woocommerce_smart_myfatoorah_settings', array());
        }
        if (! is_array($settings) || 'custom' !== ($settings['style_mode'] ?? 'theme')) {
            return false;
        }
        $family = isset($settings['style_font_family']) ? sanitize_key((string) $settings['style_font_family']) : '';
        return in_array($family, array('arabic_sans', 'cairo', 'tajawal'), true);
    }

    /**
     * Brand logo URLs keyed by route id (local file, else MF ImageUrl).
     *
     * @param array<string,string> $remote_images Optional ImageUrl map from discovery.
     * @return array<string,string>
     */
    public static function get_route_logo_urls($remote_images = array())
    {
        if (! is_array($remote_images)) {
            $remote_images = array();
        }

        $routes = array_merge(
            array('card', 'apple_pay', 'google_pay'),
            SMF_Method_Catalog::local_route_ids()
        );

        $urls = array();
        foreach ($routes as $route) {
            $remote = isset($remote_images[$route]) ? (string) $remote_images[$route] : '';
            $url    = SMF_Method_Catalog::logo_url($route, $remote);
            if ($url) {
                $urls[$route] = $url;
            }
        }

        return $urls;
    }

    /**
     * Short caption under logos.
     *
     * @param string $route       Route id.
     * @param bool   $qatar_pair  True when QPay is shown beside card (QA customer).
     * @return string
     */
    public static function get_route_logo_caption($route, $qatar_pair = false)
    {
        return SMF_Method_Catalog::caption($route, $qatar_pair);
    }

    /**
     * Render one checkout route option (text or logo mode).
     *
     * @param string               $value          Route value.
     * @param string               $label          Accessible / text label.
     * @param string               $help           Optional help text.
     * @param bool                 $selected       Whether selected.
     * @param bool                 $use_logos      Whether to show logos.
     * @param array                $logos          Logo URL map.
     * @param bool                 $is_recommended Whether to show recommended badge.
     * @param bool                 $qatar_pair     QPay+card pair (affects card caption).
     * @param bool                 $hidden         Hide option (kept in DOM for JS country toggle).
     * @param array<int,string>    $countries      ISO2 countries that should show this option.
     * @param string               $caption_override Optional caption override.
     */
    private function render_route_option($value, $label, $help = '', $selected = false, $use_logos = false, $logos = array(), $is_recommended = false, $qatar_pair = false, $hidden = false, $countries = array(), $caption_override = '')
    {
        $classes = 'smf-route-option';
        if ($selected) {
            $classes .= ' is-selected';
        }
        if ($is_recommended) {
            $classes .= ' is-recommended';
        }
        if ($use_logos && isset($logos[$value])) {
            $classes .= ' smf-route-option--logo';
        }
        if ($hidden) {
            $classes .= ' smf-route-option--hidden';
        }

        $country_attr = $countries ? implode(',', array_map('strtoupper', $countries)) : '';

        echo '<label class="' . esc_attr($classes) . '" data-smf-route="' . esc_attr($value) . '"'
            . ($country_attr ? ' data-smf-countries="' . esc_attr($country_attr) . '"' : '')
            . ($hidden ? ' hidden' : '') . '>';
        echo '<span class="smf-radio-wrap">';
        echo '<input class="smf-radio-input" type="radio" name="smf_route" value="' . esc_attr($value) . '"' . checked($selected, true, false) . ($hidden ? ' disabled' : '') . '>';
        echo '<span class="smf-radio" aria-hidden="true"></span>';
        echo '</span>';

        if ($use_logos && isset($logos[$value])) {
            $caption = '' !== $caption_override ? $caption_override : self::get_route_logo_caption($value, $qatar_pair);
            echo '<span class="smf-route-content smf-route-content--logo">';
            echo '<span class="smf-logo-row">';
            echo '<span class="smf-logo-badge smf-logo-badge--' . esc_attr($value) . '">';
            echo '<img class="smf-logo-img" src="' . esc_url($logos[$value]) . '" alt="' . esc_attr($label) . '" loading="eager" decoding="async" width="120" height="40">';
            echo '</span>';
            if ($is_recommended) {
                echo '<span class="smf-pill">' . esc_html__('Recommended', 'smart-myfatoorah') . '</span>';
            }
            echo '</span>';
            if ($caption) {
                echo '<strong class="smf-logo-caption">' . esc_html($caption) . '</strong>';
            }
            if ($help) {
                echo '<small class="smf-logo-help">' . esc_html($help) . '</small>';
            }
            echo '<span class="screen-reader-text">' . esc_html($label) . '</span>';
            echo '</span>';
        } else {
            echo '<span class="smf-route-content">';
            echo '<span class="smf-label-row">';
            echo '<strong>' . esc_html($label) . '</strong>';
            if ($is_recommended) {
                echo '<span class="smf-pill">' . esc_html__('Recommended', 'smart-myfatoorah') . '</span>';
            }
            echo '</span>';
            if ($help) {
                echo '<small>' . esc_html($help) . '</small>';
            }
            echo '</span>';
        }

        echo '</label>';
    }

    public function payment_fields()
    {
        $country = '';
        if (function_exists('WC') && WC()->customer) {
            $country = strtoupper((string) WC()->customer->get_billing_country());
        }
        if (! $country) {
            $country = SMF_Router::geolocated_country();
        }

        $availability = $this->get_checkout_route_availability();
        $remote_imgs  = isset($availability['images']) && is_array($availability['images']) ? $availability['images'] : array();
        $qpay_ok      = ! empty($availability['qpay']);
        $qatar_pair   = ('QA' === $country && $qpay_ok);

        $available_locals = array();
        foreach (SMF_Method_Catalog::local_route_ids() as $route) {
            if (! empty($availability[$route])) {
                $available_locals[$route] = true;
            }
        }
        $recommended = SMF_Method_Catalog::preferred_local_for_country($country, $available_locals);
        if (! $recommended) {
            $recommended = 'card';
        }

        $qpay_label  = SMF_I18n::maybe_translate_default(
            $this->get_option('qpay_label', ''),
            'QPay — Qatar debit cards'
        );
        $card_label  = SMF_I18n::maybe_translate_default(
            $this->get_option('card_label', ''),
            'Visa / Mastercard'
        );
        $use_logos   = $this->uses_logo_display();
        $logos       = self::get_route_logo_urls($remote_imgs);
        $can_override = 'yes' === $this->get_option('allow_manual_override', 'yes');
        $custom_style = $this->uses_custom_checkout_style();
        $style_attr   = $custom_style ? self::get_checkout_style_attribute($this->settings) : '';
        $card_help    = $qatar_pair
            ? __('For credit cards and international bank cards.', 'smart-myfatoorah')
            : __('Visa and Mastercard debit or credit cards.', 'smart-myfatoorah');
        $defs         = SMF_Method_Catalog::local_definitions();
        $checkout_desc = $this->get_checkout_description($availability);

        $box_classes = 'smf-checkout-box ' . $this->get_checkout_display_classes();
        if ($custom_style) {
            $box_classes .= ' smf-has-custom-style';
        }

        echo '<div class="' . esc_attr($box_classes) . '"'
            . ' data-country="' . esc_attr($country) . '"'
            . ' data-recommended="' . esc_attr($recommended) . '"'
            . ' data-display="' . esc_attr($use_logos ? 'logos' : 'text') . '"'
            . ' data-logo-layout="' . esc_attr($this->get_logo_layout()) . '"'
            . ' data-text-layout="' . esc_attr($this->get_text_layout()) . '"'
            . ' data-cols="' . esc_attr((string) $this->get_route_columns()) . '"'
            . ' data-qpay-available="' . ($qpay_ok ? '1' : '0') . '"'
            . ' data-qatar-merchant="' . ($this->is_qatar_merchant() ? '1' : '0') . '"'
            . ($style_attr ? ' style="' . esc_attr($style_attr) . '"' : '')
            . '>';

        if ($checkout_desc) {
            echo '<div class="smf-description">' . wpautop(esc_html($checkout_desc)) . '</div>';
        }

        echo '<div class="smf-recommendation" data-role="hint">';
        if (SMF_Method_Catalog::is_local_route($recommended)) {
            echo esc_html__('Based on your country, a local payment method is pre-selected. You can choose another method below.', 'smart-myfatoorah');
        } else {
            echo esc_html__('Based on your country, card payment is pre-selected. You can choose another method below.', 'smart-myfatoorah');
        }
        echo '</div>';

        if ($can_override) {
            echo '<div class="smf-routes" role="radiogroup" aria-label="' . esc_attr__('Payment method', 'smart-myfatoorah') . '">';

            foreach ($defs as $route => $def) {
                if (empty($availability[$route])) {
                    continue;
                }
                $label = ('qpay' === $route) ? $qpay_label : $def['label'];
                $show  = in_array($country, $def['countries'], true);
                $this->render_route_option(
                    $route,
                    $label,
                    $def['help'],
                    $route === $recommended,
                    $use_logos,
                    $logos,
                    $route === $recommended,
                    $qatar_pair,
                    ! $show,
                    $def['countries']
                );
            }

            $this->render_route_option(
                'card',
                $card_label,
                $card_help,
                'card' === $recommended,
                $use_logos,
                $logos,
                'card' === $recommended,
                $qatar_pair,
                false
            );

            if (! empty($availability['apple_pay'])) {
                $this->render_route_option('apple_pay', __('Apple Pay', 'smart-myfatoorah'), '', false, $use_logos, $logos, false);
            }
            if (! empty($availability['google_pay'])) {
                $this->render_route_option('google_pay', __('Google Pay', 'smart-myfatoorah'), '', false, $use_logos, $logos, false);
            }
            echo '</div>';
        } else {
            // No override: lock to the smart/recommended route.
            echo '<input type="hidden" name="smf_route" value="smart">';
            echo '<p class="smf-auto-copy">' . esc_html__('The best payment method for your country will be used automatically.', 'smart-myfatoorah') . '</p>';
        }

        if ($this->is_embedded_enabled()) {
            $this->render_embedded_card_section($recommended, $can_override);
        }

        echo '</div>';
    }

    /**
     * CardView mount point for classic checkout (card route only, no save-card UI).
     *
     * @param string $recommended Recommended route.
     * @param bool   $can_override Whether customer can change route.
     */
    private function render_embedded_card_section($recommended, $can_override)
    {
        $api     = new SMF_API_Client($this->settings);
        $session = $api->initiate_session();
        $ready   = ! is_wp_error($session);
        $show    = (! $can_override && 'card' === $recommended) || ($can_override && 'card' === $recommended);

        echo '<div id="smf-embedded-wrap" class="smf-embedded-wrap" data-show-for="card"'
            . ' data-ready="' . ($ready ? '1' : '0') . '"'
            . ' style="' . ($show ? '' : 'display:none;') . '">';

        echo '<h4 class="smf-embedded-title">' . esc_html__('Card details', 'smart-myfatoorah') . '</h4>';

        if (! $ready) {
            echo '<p class="smf-embedded-fallback">' . esc_html__(
                'Embedded card form is temporarily unavailable. Card payments will use the secure MyFatoorah hosted page instead.',
                'smart-myfatoorah'
            ) . '</p>';
            if (is_wp_error($session) && 'yes' === $this->get_option('debug', 'no')) {
                echo '<p class="smf-embedded-error"><small>' . esc_html($session->get_error_message()) . '</small></p>';
            }
        } else {
            echo '<div id="smf-cardview" class="smf-cardview"'
                . ' data-session-id="' . esc_attr($session['session_id']) . '"'
                . ' data-country-code="' . esc_attr($session['country_code']) . '"'
                . ' data-currency="' . esc_attr(function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'QAR') . '"'
                . '></div>';
        }

        echo '</div>';
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order) {
            wc_add_notice(__('Unable to create the payment order.', 'smart-myfatoorah'), 'error');
            return array('result' => 'failure');
        }

        $settings = $this->settings;
        $api      = new SMF_API_Client($settings);
        $router   = new SMF_Router($settings);
        $requested = isset($_POST['smf_route']) ? sanitize_key(wp_unslash($_POST['smf_route'])) : 'smart';
        $route    = $router->resolve($order, $requested);
        $session_id = '';
        if (isset($_POST['mfData'])) {
            $session_id = sanitize_text_field(wp_unslash($_POST['mfData']));
        } elseif (isset($_POST['mfdata'])) {
            // Store API sanitize_key lowercases payment_data keys.
            $session_id = sanitize_text_field(wp_unslash($_POST['mfdata']));
        }

        // Defense in depth: never run locals/wallets that are not available for this merchant/country.
        $availability = $this->get_checkout_route_availability();
        $order_country = $router->country_for_order($order);
        if (SMF_Method_Catalog::is_local_route($route)) {
            $countries = SMF_Method_Catalog::countries_for_route($route);
            if (empty($availability[$route]) || ($countries && ! in_array($order_country, $countries, true))) {
                $route = 'card';
            }
        }
        if ('apple_pay' === $route && empty($availability['apple_pay'])) {
            $route = 'card';
        }
        if ('google_pay' === $route && empty($availability['google_pay'])) {
            $route = 'card';
        }

        $callback = add_query_arg(
            array(
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            ),
            WC()->api_request_url('smart_myfatoorah_callback')
        );

        $use_embedded = (
            'card' === $route
            && '' !== $session_id
            && $this->is_embedded_enabled()
        );

        $attempt_id = SMF_Transactions::create_attempt(
            array(
                'order_id' => $order->get_id(),
                'engine'   => (SMF_Method_Catalog::is_local_route($route) || $use_embedded) ? 'v2' : 'v3',
                'route'    => $route,
                'status'   => 'processing',
                'amount'   => $order->get_total(),
                'currency' => $order->get_currency(),
            )
        );

        if (SMF_Method_Catalog::is_local_route($route)) {
            $payment = $api->create_local_method_payment($order, $route, $callback);
            $allow_local_fallback = 'yes' === $this->get_option('fallback_to_card', 'yes');
            if (is_wp_error($payment) && $allow_local_fallback) {
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: route id, 2: error message */
                        __('Local method %1$s was unavailable (%2$s). Falling back to card.', 'smart-myfatoorah'),
                        $route,
                        $payment->get_error_message()
                    )
                );
                $route = 'card';
                if ($session_id && $this->is_embedded_enabled()) {
                    $payment = $api->create_embedded_card_payment($order, $session_id, $callback);
                    if (is_wp_error($payment)) {
                        $order->add_order_note(
                            sprintf(
                                __('Embedded card payment failed (%s). Falling back to hosted card page.', 'smart-myfatoorah'),
                                $payment->get_error_message()
                            )
                        );
                        $payment = $api->create_v3_hosted_payment($order, 'card', $callback);
                        $use_embedded = false;
                    } else {
                        $use_embedded = true;
                    }
                } else {
                    $payment = $api->create_v3_hosted_payment($order, 'card', $callback);
                    $use_embedded = false;
                }
            }
        } elseif ($use_embedded) {
            $payment = $api->create_embedded_card_payment($order, $session_id, $callback);
            if (is_wp_error($payment)) {
                $order->add_order_note(
                    sprintf(
                        __('Embedded card payment failed (%s). Falling back to hosted card page.', 'smart-myfatoorah'),
                        $payment->get_error_message()
                    )
                );
                $payment = $api->create_v3_hosted_payment($order, 'card', $callback);
                $use_embedded = false;
            }
        } else {
            $payment = $api->create_v3_hosted_payment($order, $route, $callback);
        }

        if (is_wp_error($payment)) {
            SMF_Transactions::update_attempt(
                $attempt_id,
                array(
                    'status'        => 'failed',
                    'error_message' => $payment->get_error_message(),
                )
            );
            wc_add_notice(sprintf(__('Payment could not be started: %s', 'smart-myfatoorah'), $payment->get_error_message()), 'error');
            return array('result' => 'failure');
        }

        if (empty($payment['payment_url']) || ! SMF_API_Client::sanitize_payment_url($payment['payment_url'])) {
            SMF_Transactions::update_attempt(
                $attempt_id,
                array(
                    'status'        => 'failed',
                    'error_message' => 'Untrusted payment URL',
                )
            );
            wc_add_notice(__('Payment could not be started: invalid payment URL returned by the provider.', 'smart-myfatoorah'), 'error');
            return array('result' => 'failure');
        }

        $order->update_meta_data('_smf_invoice_id', $payment['invoice_id']);
        $order->update_meta_data('_smf_payment_id', $payment['payment_id']);
        $order->update_meta_data('_smf_route', $route);
        $order->update_meta_data('_smf_engine', $payment['engine']);
        $order->update_meta_data('_smf_status', 'pending');
        $order->update_meta_data('_smf_embedded', $use_embedded ? 'yes' : 'no');

        // Keep every initiated invoice for multi-attempt webhook matching.
        $invoice_ids = $order->get_meta('_smf_invoice_ids', true);
        if (! is_array($invoice_ids)) {
            $invoice_ids = array();
        }
        $new_invoice = (string) $payment['invoice_id'];
        if ($new_invoice && ! in_array($new_invoice, array_map('strval', $invoice_ids), true)) {
            $invoice_ids[] = $new_invoice;
            $order->update_meta_data('_smf_invoice_ids', array_values($invoice_ids));
        }

        $order->save();

        $order->add_order_note(
            sprintf(
                __('MyFatoorah payment initiated. Route: %1$s. Invoice ID: %2$s.%3$s', 'smart-myfatoorah'),
                strtoupper($route),
                $payment['invoice_id'],
                $use_embedded ? ' ' . __('(Embedded CardView)', 'smart-myfatoorah') : ''
            )
        );

        SMF_Transactions::update_attempt(
            $attempt_id,
            array(
                'invoice_id' => $payment['invoice_id'],
                'payment_id' => $payment['payment_id'] ?: null,
                'engine'     => $payment['engine'],
                'route'      => $route,
                'status'     => 'pending',
            )
        );

        return array(
            'result'   => 'success',
            'redirect' => $payment['payment_url'],
        );
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (! $order) {
            return new WP_Error('smf_refund_order_missing', __('Order not found.', 'smart-myfatoorah'));
        }

        $status = $order->get_status();
        if (! in_array($status, array('processing', 'completed'), true)) {
            return new WP_Error(
                'smf_refund_bad_status',
                sprintf(
                    /* translators: %s: order status */
                    __('MyFatoorah refunds are only available for processing/completed orders (current status: %s).', 'smart-myfatoorah'),
                    $status
                )
            );
        }

        $payment_id = (string) $order->get_meta('_smf_payment_id', true);
        if (! $payment_id) {
            return new WP_Error('smf_refund_payment_missing', __('MyFatoorah payment ID is missing from this order.', 'smart-myfatoorah'));
        }

        $api = new SMF_API_Client($this->settings);
        $response = $api->make_refund(
            $payment_id,
            $amount,
            $reason,
            'woocommerce_order_' . $order->get_id() . '_refund_' . gmdate('YmdHis'),
            $order->get_currency()
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $data = isset($response['Data']) && is_array($response['Data']) ? $response['Data'] : array();
        $refund_id = ! empty($data['RefundId']) ? (string) $data['RefundId'] : '';
        $reference = ! empty($data['RefundReference']) ? (string) $data['RefundReference'] : '';

        if ($refund_id) {
            $stored = $order->get_meta('_smf_refund_data', true);
            if (! is_array($stored)) {
                $stored = array();
            }
            $stored[$refund_id] = array(
                'refund_id'        => $refund_id,
                'refund_reference' => $reference,
                'display_amount'   => (float) $amount,
                'display_currency' => $order->get_currency(),
                'reason'           => (string) $reason,
                'requested_at'     => gmdate('c'),
                'raw'              => $data,
            );
            $order->update_meta_data('_smf_refund_data', $stored);
        }

        $order->add_order_note(
            sprintf(
                __('MyFatoorah refund request submitted for %1$s. RefundId: %2$s. Reference: %3$s. Waiting for REFUND_STATUS_CHANGED webhook confirmation.', 'smart-myfatoorah'),
                wc_price($amount, array('currency' => $order->get_currency())),
                $refund_id ?: '—',
                $reference ?: '—'
            )
        );
        $order->save();

        // Do not create a WooCommerce refund yet — MyFatoorah may still be pending finance review.
        return new WP_Error(
            'smf_refund_pending',
            __('Refund request sent to MyFatoorah and is pending confirmation. WooCommerce will update the order when the refund webhook is confirmed.', 'smart-myfatoorah')
        );
    }

    /**
     * Tabbed gateway settings screen.
     */
    public function admin_options()
    {
        $version = defined('SMF_VERSION') ? SMF_VERSION : '';
        $webhook = class_exists('SMF_Webhook_Controller', false)
            ? SMF_Webhook_Controller::get_webhook_url()
            : home_url('/?wc-api=myfatoorah_webhook');
        ?>
        <div class="smf-gateway-settings">
            <h2>
                <?php echo esc_html($this->get_method_title()); ?>
                <?php if ($version) : ?>
                    <span class="smf-gateway-version"><?php echo esc_html('v' . $version); ?></span>
                <?php endif; ?>
            </h2>
            <p><?php echo wp_kses_post($this->get_method_description()); ?></p>

            <nav class="nav-tab-wrapper smf-settings-tabs" role="tablist" aria-label="<?php esc_attr_e('Smart MyFatoorah settings tabs', 'smart-myfatoorah'); ?>">
                <a href="#smf-tab-settings" class="nav-tab nav-tab-active" data-smf-tab="settings" role="tab" aria-selected="true"><?php esc_html_e('Settings', 'smart-myfatoorah'); ?></a>
                <a href="#smf-tab-about" class="nav-tab" data-smf-tab="about" role="tab" aria-selected="false"><?php esc_html_e('About', 'smart-myfatoorah'); ?></a>
                <a href="#smf-tab-guide" class="nav-tab" data-smf-tab="guide" role="tab" aria-selected="false"><?php esc_html_e('How to use', 'smart-myfatoorah'); ?></a>
            </nav>

            <div class="smf-tab-panel is-active" id="smf-tab-settings" data-smf-panel="settings" role="tabpanel">
                <table class="form-table">
                    <?php $this->generate_settings_html(); ?>
                </table>
            </div>

            <div class="smf-tab-panel" id="smf-tab-about" data-smf-panel="about" role="tabpanel" hidden>
                <?php $this->render_about_tab(); ?>
            </div>

            <div class="smf-tab-panel" id="smf-tab-guide" data-smf-panel="guide" role="tabpanel" hidden>
                <?php $this->render_guide_tab($webhook); ?>
            </div>
        </div>
        <?php
    }

    /**
     * About plugin + developer.
     */
    private function render_about_tab()
    {
        $github = 'https://github.com/aymentucker';
        ?>
        <div class="smf-info-card">
            <h3><?php esc_html_e('Smart MyFatoorah Gateway for WooCommerce', 'smart-myfatoorah'); ?></h3>
            <p><?php esc_html_e('A smart WooCommerce payment gateway built on MyFatoorah. It recommends the best local method for the customer country when available (QPay, KNET, Benefit, Mada, STC Pay, Meeza), with Visa/Mastercard and optional Apple Pay / Google Pay.', 'smart-myfatoorah'); ?></p>
            <ul class="smf-info-list">
                <li><?php esc_html_e('Classic Checkout and Checkout Blocks support', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Signed Webhook V2, secure callbacks, and automatic reconciliation', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Embedded card form (CardView) with hosted fallback', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Transaction log, refunds via MyFatoorah, and checkout appearance controls', 'smart-myfatoorah'); ?></li>
            </ul>
        </div>

        <div class="smf-info-card">
            <h3><?php esc_html_e('Developer', 'smart-myfatoorah'); ?></h3>
            <p><strong><?php esc_html_e('Aymen Ali', 'smart-myfatoorah'); ?></strong> · <code>aymentucker</code></p>
            <p><?php esc_html_e('Flutter developer, UI/UX designer, and WordPress / WooCommerce specialist based in Qatar — focused on reliable payments, clear UX, and production-ready integrations.', 'smart-myfatoorah'); ?></p>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url($github); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('GitHub: aymentucker', 'smart-myfatoorah'); ?>
                </a>
            </p>
            <p class="description">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: plugin version */
                        __('Plugin version: %s', 'smart-myfatoorah'),
                        defined('SMF_VERSION') ? SMF_VERSION : '—'
                    )
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Usage guide tab.
     *
     * @param string $webhook Webhook URL.
     */
    private function render_guide_tab($webhook)
    {
        ?>
        <div class="smf-info-card">
            <h3><?php esc_html_e('Quick setup', 'smart-myfatoorah'); ?></h3>
            <ol class="smf-info-steps">
                <li><?php esc_html_e('Deactivate the official MyFatoorah WooCommerce plugin if it is active (both listen on the same webhook URL).', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Enable this gateway, set Merchant country to your MyFatoorah account country, and paste your API token.', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Use Test mode with a Demo token first, then click Test MyFatoorah connection.', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('In MyFatoorah Portal → Integration Settings → Webhook Settings (V2), paste this URL and enable PAYMENT_STATUS_CHANGED (and REFUND_STATUS_CHANGED if you use refunds):', 'smart-myfatoorah'); ?></li>
            </ol>
            <p><code class="smf-copy-code"><?php echo esc_html($webhook); ?></code></p>
            <p><?php esc_html_e('Copy the Webhook Secret Key from MyFatoorah into the Webhook Secret Key field on the Settings tab.', 'smart-myfatoorah'); ?></p>
        </div>

        <div class="smf-info-card">
            <h3><?php esc_html_e('How routing works', 'smart-myfatoorah'); ?></h3>
            <ul class="smf-info-list">
                <li><?php esc_html_e('Local methods appear only if MyFatoorah returns them for your account and the customer billing country matches (e.g. QA → QPay, KW → KNET, BH → Benefit, SA → Mada/STC, EG → Meeza).', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Card (Visa/Mastercard) is always available as the international option.', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Apple Pay / Google Pay show only when enabled on the account and wallet overrides are turned on.', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Use Methods per row and Logo/Text layout designs to style the checkout method picker.', 'smart-myfatoorah'); ?></li>
            </ul>
        </div>

        <div class="smf-info-card">
            <h3><?php esc_html_e('Going live', 'smart-myfatoorah'); ?></h3>
            <ol class="smf-info-steps">
                <li><?php esc_html_e('Replace the Demo token with a Live token and turn Test mode off.', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Confirm the site uses HTTPS.', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Re-test the connection, then place small real payments for a local method and for card.', 'smart-myfatoorah'); ?></li>
                <li><?php esc_html_e('Verify order status, MyFatoorah portal, webhook delivery, and WooCommerce → MyFatoorah Transactions.', 'smart-myfatoorah'); ?></li>
            </ol>
            <p class="description"><?php esc_html_e('Full documentation is available in MYFATOORAH-SETUP.md inside the plugin folder.', 'smart-myfatoorah'); ?></p>
        </div>
        <?php
    }

    public function generate_smf_color_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'smf_color',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
            'default'           => '#000000',
        );
        $data  = wp_parse_args($data, $defaults);
        $value = self::sanitize_style_hex((string) $this->get_option($key, $data['default']));
        if ('' === $value) {
            $value = self::sanitize_style_hex((string) $data['default']);
        }
        if ('' === $value) {
            $value = '#000000';
        }

        $custom_attrs = array();
        if (! empty($data['custom_attributes']) && is_array($data['custom_attributes'])) {
            foreach ($data['custom_attributes'] as $attr_key => $attr_value) {
                $custom_attrs[] = esc_attr($attr_key) . '="' . esc_attr($attr_value) . '"';
            }
        }

        ob_start();
?>
        <tr valign="top" class="<?php echo esc_attr($data['class']); ?>">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp forminp-smf-color">
                <span class="smf-color-field">
                    <input
                        type="color"
                        class="smf-color-picker"
                        value="<?php echo esc_attr($value); ?>"
                        data-smf-color-target="<?php echo esc_attr($field_key); ?>"
                        aria-label="<?php echo esc_attr($data['title']); ?>" />
                    <input
                        type="text"
                        name="<?php echo esc_attr($field_key); ?>"
                        id="<?php echo esc_attr($field_key); ?>"
                        value="<?php echo esc_attr($value); ?>"
                        class="smf-color-text <?php echo esc_attr($data['class']); ?>"
                        style="<?php echo esc_attr($data['css']); ?>"
                        placeholder="<?php echo esc_attr($data['placeholder'] ? $data['placeholder'] : '#000000'); ?>"
                        maxlength="7"
                        pattern="^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$"
                        <?php echo implode(' ', $custom_attrs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                        ?> />
                </span>
                <?php echo $this->get_description_html($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                ?>
            </td>
        </tr>
    <?php
        return ob_get_clean();
    }

    public function generate_apple_pay_domain_html($key, $data)
    {
        $domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        ob_start();
    ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><label><?php echo esc_html($data['title']); ?></label></th>
            <td class="forminp">
                <code style="display:inline-block;padding:8px 10px;background:#fff;border:1px solid #ccd0d4;"><?php echo esc_html($domain ?: '—'); ?></code>
                <p>
                    <button type="button" class="button button-secondary" id="smf-register-apple-pay"><?php esc_html_e('Register this domain with MyFatoorah', 'smart-myfatoorah'); ?></button>
                    <span id="smf-apple-pay-result" style="margin-inline-start:10px;"></span>
                </p>
                <p class="description"><?php esc_html_e('Register separately for sandbox and live using the matching API token/test mode.', 'smart-myfatoorah'); ?></p>
            </td>
        </tr>
    <?php
        return ob_get_clean();
    }

    public function enqueue_checkout_assets()
    {
        if (! function_exists('is_checkout') || ! is_checkout()) {
            return;
        }

        wp_enqueue_style('smf-checkout', SMF_URL . 'assets/css/checkout.css', array(), SMF_VERSION);

        if (self::needs_google_font($this->settings)) {
            wp_enqueue_style(
                'smf-checkout-fonts',
                'https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Noto+Sans+Arabic:wght@400;600;700&family=Tajawal:wght@400;500;700&display=swap',
                array(),
                null
            );
        }

        $inline = self::get_checkout_inline_css($this->settings);
        if ($inline) {
            wp_add_inline_style('smf-checkout', $inline);
        }

        $deps = array('jquery');
        $embedded = $this->is_embedded_enabled();
        if ($embedded) {
            $api    = new SMF_API_Client($this->settings);
            $portal = trailingslashit($api->get_portal_base_url());
            wp_enqueue_script('smf-cardview', $portal . 'cardview/v2/session.js', array(), SMF_VERSION, true);
            $deps[] = 'smf-cardview';
        }

        wp_enqueue_script('smf-checkout', SMF_URL . 'assets/js/checkout.js', $deps, SMF_VERSION, true);
        $availability = $this->get_checkout_route_availability();
        wp_localize_script(
            'smf-checkout',
            'SMFCheckout',
            array(
                'recommendedQatar' => __('Based on your country, QPay is pre-selected. You can choose another method below.', 'smart-myfatoorah'),
                'recommendedLocal' => __('Based on your country, a local payment method is pre-selected. You can choose another method below.', 'smart-myfatoorah'),
                'recommendedCard'  => __('Based on your country, card payment is pre-selected. You can choose another method below.', 'smart-myfatoorah'),
                'embeddedEnabled'  => $embedded,
                'qpayAvailable'    => ! empty($availability['qpay']),
                'qatarMerchant'    => $this->is_qatar_merchant(),
                'logoCaptions'     => array(
                    'qpay'      => self::get_route_logo_caption('qpay'),
                    'cardQatar' => self::get_route_logo_caption('card', true),
                    'cardOnly'  => self::get_route_logo_caption('card', false),
                ),
                'cardHelpQatar'    => __('For credit cards and international bank cards.', 'smart-myfatoorah'),
                'cardHelpOnly'     => __('Visa and Mastercard debit or credit cards.', 'smart-myfatoorah'),
                'direction'        => (function_exists('is_rtl') && is_rtl()) ? 'rtl' : '',
                'placeholders'     => array(
                    'holderName'    => __('Name On Card', 'smart-myfatoorah'),
                    'cardNumber'    => __('Card number', 'smart-myfatoorah'),
                    'expiryDate'    => __('MM / YY', 'smart-myfatoorah'),
                    'securityCode'  => __('CVV', 'smart-myfatoorah'),
                ),
                'cardViewStyle'    => array(
                    'cardHeight'   => 190,
                    'inputHeight'  => '40px',
                    'fontSize'     => '14px',
                    'borderRadius' => '12px',
                    'borderWidth'  => '1px',
                    'inputMargin'  => '4px',
                ),
                'submitError'      => __('Please check your card details and try again.', 'smart-myfatoorah'),
                'ajaxUrl'          => admin_url('admin-ajax.php'),
                'checkoutNonce'    => wp_create_nonce('smf_checkout'),
            )
        );
    }

    /**
     * Description under Webhook Secret Key: live site URL + copy instructions.
     */
    private function get_webhook_secret_description()
    {
        $url = SMF_Webhook_Controller::get_webhook_url();
        return '<code style="display:inline-block;padding:6px 8px;background:#fff;border:1px solid #ccd0d4;word-break:break-all;">'
            . esc_html($url)
            . '</code><br>'
            . esc_html__(
                'Copy this link to your MyFatoorah Account. After that, Copy your Webhook Secret Key from MyFatoorah Account in the above field.',
                'smart-myfatoorah'
            );
    }

    public function generate_test_connection_html($key, $data)
    {
        ob_start();
    ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><label><?php echo esc_html($data['title']); ?></label></th>
            <td class="forminp">
                <button type="button" class="button button-secondary" id="smf-test-connection"><?php esc_html_e('Test MyFatoorah connection', 'smart-myfatoorah'); ?></button>
                <span id="smf-test-result" style="margin-inline-start:10px;"></span>
                <p class="description"><?php esc_html_e('Save settings first. The test uses InitiatePayment only; it does not charge a card.', 'smart-myfatoorah'); ?></p>
            </td>
        </tr>
<?php
        return ob_get_clean();
    }
}
