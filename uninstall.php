<?php
/**
 * Smart MyFatoorah uninstall handler.
 *
 * Payment/order records and plugin tables are intentionally preserved by default
 * for accounting and support traceability. Remove them manually only after you
 * no longer need transaction history.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'woocommerce_smart_myfatoorah_settings' );
delete_option( 'smf_db_version' );
wp_clear_scheduled_hook( 'smf_reconcile_pending' );
