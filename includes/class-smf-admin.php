<?php
if (! defined('ABSPATH')) {
    exit;
}

final class SMF_Admin
{
    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'menu'), 60);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_smf_test_connection', array(__CLASS__, 'test_connection'));
        add_action('wp_ajax_smf_register_apple_pay', array(__CLASS__, 'register_apple_pay'));
        add_action('wp_ajax_smf_initiate_session', array(__CLASS__, 'initiate_session'));
        add_action('wp_ajax_nopriv_smf_initiate_session', array(__CLASS__, 'initiate_session'));
        add_filter('plugin_action_links_' . plugin_basename(SMF_FILE), array(__CLASS__, 'action_links'));
    }

    public static function menu()
    {
        add_submenu_page(
            'woocommerce',
            __('MyFatoorah Transactions', 'smart-myfatoorah'),
            __('MyFatoorah Transactions', 'smart-myfatoorah'),
            'manage_woocommerce',
            'smf-transactions',
            array(__CLASS__, 'transactions_page')
        );
    }

    public static function assets($hook)
    {
        if (false === strpos((string) $hook, 'woocommerce') && 'woocommerce_page_smf-transactions' !== $hook) {
            return;
        }

        wp_enqueue_style('smf-admin', SMF_URL . 'assets/css/admin.css', array(), SMF_VERSION);
        wp_enqueue_script('smf-admin', SMF_URL . 'assets/js/admin.js', array('jquery'), SMF_VERSION, true);
        wp_localize_script(
            'smf-admin',
            'SMFAdmin',
            array(
                'ajaxUrl'            => admin_url('admin-ajax.php'),
                'nonce'              => wp_create_nonce('smf_admin'),
                'testing'            => __('Testing…', 'smart-myfatoorah'),
                'registeringApplePay' => __('Registering…', 'smart-myfatoorah'),
            )
        );
    }

    public static function test_connection()
    {
        check_ajax_referer('smf_admin', 'nonce');
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'smart-myfatoorah')), 403);
        }

        $settings = get_option('woocommerce_smart_myfatoorah_settings', array());
        $api      = new SMF_API_Client($settings);
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'QAR';
        $methods  = $api->discover_v2_methods(1, $currency);

        if (is_wp_error($methods)) {
            wp_send_json_error(array('message' => $methods->get_error_message()), 400);
        }

        SMF_API_Client::clear_route_flags_cache();
        $flags = $api->get_enabled_route_flags(1, $currency);
        $qatar = 'QAT' === strtoupper((string) (isset($settings['merchant_country']) ? $settings['merchant_country'] : 'QAT'));

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: 1: locals summary, 2: Yes/No card, 3: Yes/No Apple Pay, 4: Yes/No Google Pay, 5: method list */
                    __('Connected. Locals: %1$s · Card: %2$s · Apple Pay: %3$s · Google Pay: %4$s · Methods: %5$s', 'smart-myfatoorah'),
                    self::format_local_flags($flags, $qatar),
                    ! empty($flags['card']) ? __('Yes', 'smart-myfatoorah') : __('No', 'smart-myfatoorah'),
                    ! empty($flags['apple_pay']) ? __('Yes', 'smart-myfatoorah') : __('No', 'smart-myfatoorah'),
                    ! empty($flags['google_pay']) ? __('Yes', 'smart-myfatoorah') : __('No', 'smart-myfatoorah'),
                    ! empty($flags['names']) ? implode(', ', $flags['names']) : '—'
                ),
                'qpay'       => $qatar && ! empty($flags['qpay']),
                'card'       => ! empty($flags['card']),
                'apple_pay'  => ! empty($flags['apple_pay']),
                'google_pay' => ! empty($flags['google_pay']),
                'locals'     => array(
                    'qpay'    => $qatar && ! empty($flags['qpay']),
                    'knet'    => ! empty($flags['knet']),
                    'benefit' => ! empty($flags['benefit']),
                    'mada'    => ! empty($flags['mada']),
                    'stc_pay' => ! empty($flags['stc_pay']),
                    'meeza'   => ! empty($flags['meeza']),
                ),
            )
        );
    }

    /**
     * @param array $flags Discovery flags.
     * @param bool  $qatar Qatar merchant.
     */
    private static function format_local_flags($flags, $qatar)
    {
        $bits = array();
        if ($qatar && ! empty($flags['qpay'])) {
            $bits[] = 'QPay';
        }
        foreach (array('knet' => 'KNET', 'benefit' => 'Benefit', 'mada' => 'Mada', 'stc_pay' => 'STC', 'meeza' => 'Meeza') as $key => $label) {
            if (! empty($flags[$key])) {
                $bits[] = $label;
            }
        }
        return $bits ? implode(', ', $bits) : __('none', 'smart-myfatoorah');
    }

    public static function initiate_session()
    {
        check_ajax_referer('smf_checkout', 'nonce');

        $settings = get_option('woocommerce_smart_myfatoorah_settings', array());
        if ('yes' !== (isset($settings['enabled']) ? $settings['enabled'] : 'no')) {
            wp_send_json_error(array('message' => __('Payment gateway is disabled.', 'smart-myfatoorah')), 403);
        }
        if ('yes' !== (isset($settings['embedded_card']) ? $settings['embedded_card'] : 'yes')) {
            wp_send_json_error(array('message' => __('Embedded card form is disabled.', 'smart-myfatoorah')), 400);
        }

        // Rate-limit public session creation to reduce API-token abuse.
        $ip = '';
        if (class_exists('WC_Geolocation') && method_exists('WC_Geolocation', 'get_ip_address')) {
            $ip = (string) WC_Geolocation::get_ip_address();
        } elseif (! empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string) wp_unslash($_SERVER['REMOTE_ADDR']);
        }
        $bucket = 'smf_sess_' . md5($ip !== '' ? $ip : 'unknown');
        $hits   = (int) get_transient($bucket);
        if ($hits >= 30) {
            wp_send_json_error(array('message' => __('Too many payment session requests. Please wait a minute and try again.', 'smart-myfatoorah')), 429);
        }
        set_transient($bucket, $hits + 1, MINUTE_IN_SECONDS);

        $api     = new SMF_API_Client($settings);
        $session = $api->initiate_session();
        if (is_wp_error($session)) {
            wp_send_json_error(array('message' => $session->get_error_message()), 400);
        }

        wp_send_json_success($session);
    }

    public static function register_apple_pay()
    {
        check_ajax_referer('smf_admin', 'nonce');
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'smart-myfatoorah')), 403);
        }

        $settings = get_option('woocommerce_smart_myfatoorah_settings', array());
        $api      = new SMF_API_Client($settings);
        $result   = $api->register_apple_pay_domain();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        $message = ! empty($result['Message'])
            ? (string) $result['Message']
            : __('Apple Pay domain registered successfully with MyFatoorah.', 'smart-myfatoorah');

        wp_send_json_success(array('message' => $message));
    }

    public static function action_links($links)
    {
        $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=smart_myfatoorah');
        array_unshift($links, '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'smart-myfatoorah') . '</a>');
        return $links;
    }

    public static function transactions_page()
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $per_page = 50;
        $page     = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $rows     = SMF_Transactions::latest($per_page, ($page - 1) * $per_page);
        $count    = SMF_Transactions::count();
        $pages    = max(1, (int) ceil($count / $per_page));
?>
        <div class="wrap smf-admin-wrap">
            <h1><?php esc_html_e('MyFatoorah Transactions', 'smart-myfatoorah'); ?></h1>
            <p><?php esc_html_e('A local operational log of payment attempts. No full card numbers, CVV values or OTP codes are stored.', 'smart-myfatoorah'); ?></p>
            <div class="smf-admin-actions">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=smart_myfatoorah')); ?>"><?php esc_html_e('Gateway settings', 'smart-myfatoorah'); ?></a>
                <code><?php echo esc_html(SMF_Webhook_Controller::get_webhook_url()); ?></code>
            </div>
            <table class="widefat striped smf-transactions-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Order', 'smart-myfatoorah'); ?></th>
                        <th><?php esc_html_e('Route', 'smart-myfatoorah'); ?></th>
                        <th><?php esc_html_e('Amount', 'smart-myfatoorah'); ?></th>
                        <th><?php esc_html_e('Status', 'smart-myfatoorah'); ?></th>
                        <th><?php esc_html_e('Invoice', 'smart-myfatoorah'); ?></th>
                        <th><?php esc_html_e('Payment ID', 'smart-myfatoorah'); ?></th>
                        <th><?php esc_html_e('Error', 'smart-myfatoorah'); ?></th>
                        <th><?php esc_html_e('Updated', 'smart-myfatoorah'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! $rows) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e('No payment attempts recorded yet.', 'smart-myfatoorah'); ?></td>
                        </tr>
                        <?php else : foreach ($rows as $row) :
                            $order_url = admin_url('post.php?post=' . absint($row->order_id) . '&action=edit');
                            if (function_exists('wc_get_order')) {
                                $order = wc_get_order($row->order_id);
                                if ($order && method_exists($order, 'get_edit_order_url')) {
                                    $order_url = $order->get_edit_order_url();
                                }
                            }
                        ?>
                            <tr>
                                <td><a href="<?php echo esc_url($order_url); ?>">#<?php echo esc_html($row->order_id); ?></a></td>
                                <td><?php echo esc_html(strtoupper($row->route)); ?><br><small><?php echo esc_html(strtoupper($row->engine)); ?></small></td>
                                <td><?php echo esc_html($row->amount . ' ' . $row->currency); ?></td>
                                <td><span class="smf-status smf-status-<?php echo esc_attr(sanitize_html_class($row->status)); ?>"><?php echo esc_html(ucfirst($row->status)); ?></span></td>
                                <td><code><?php echo esc_html($row->invoice_id ?: '—'); ?></code></td>
                                <td><code><?php echo esc_html($row->payment_id ?: '—'); ?></code></td>
                                <td><?php echo esc_html(trim((string) $row->error_code . ' ' . (string) $row->error_message) ?: '—'); ?></td>
                                <td><?php echo esc_html($row->updated_at); ?> UTC</td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
            <?php if ($pages > 1) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php echo wp_kses_post(paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages))); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
<?php
    }
}
