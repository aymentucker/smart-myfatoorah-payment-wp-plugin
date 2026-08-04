<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SMF_Transactions {
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'smf_transactions';
    }

    public static function events_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'smf_events';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table   = self::table_name();
        $events  = self::events_table_name();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            invoice_id varchar(80) NULL,
            payment_id varchar(120) NULL,
            engine varchar(20) NOT NULL DEFAULT 'v3',
            route varchar(30) NOT NULL DEFAULT 'smart',
            status varchar(30) NOT NULL DEFAULT 'pending',
            amount decimal(18,6) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT '',
            error_code varchar(50) NULL,
            error_message text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY invoice_id (invoice_id),
            KEY payment_id (payment_id),
            KEY status (status)
        ) {$charset};";

        $sql_events = "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_reference varchar(120) NOT NULL,
            event_name varchar(80) NOT NULL DEFAULT '',
            invoice_id varchar(80) NULL,
            payment_id varchar(120) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_reference (event_reference)
        ) {$charset};";

        dbDelta( $sql );
        dbDelta( $sql_events );
    }

    public static function create_attempt( $data ) {
        global $wpdb;
        $now = current_time( 'mysql', true );

        $row = wp_parse_args(
            $data,
            array(
                'order_id'      => 0,
                'invoice_id'    => null,
                'payment_id'    => null,
                'engine'        => 'v3',
                'route'         => 'smart',
                'status'        => 'pending',
                'amount'        => 0,
                'currency'      => '',
                'error_code'    => null,
                'error_message' => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            )
        );

        $wpdb->insert( self::table_name(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function update_attempt( $attempt_id, $data ) {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql', true );
        return false !== $wpdb->update( self::table_name(), $data, array( 'id' => absint( $attempt_id ) ) );
    }

    public static function update_by_order( $order_id, $data ) {
        return self::update_for_order_identifiers( $order_id, '', '', $data );
    }

    /**
     * Update the matching attempt row for an order.
     * Prefers invoice_id / payment_id match so older attempts stay linkable.
     */
    public static function update_for_order_identifiers( $order_id, $invoice_id, $payment_id, $data ) {
        global $wpdb;
        $table    = self::table_name();
        $order_id = absint( $order_id );
        $id       = 0;

        if ( $payment_id ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE order_id = %d AND payment_id = %s ORDER BY id DESC LIMIT 1",
                    $order_id,
                    (string) $payment_id
                )
            );
        }

        if ( ! $id && $invoice_id ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE order_id = %d AND invoice_id = %s ORDER BY id DESC LIMIT 1",
                    $order_id,
                    (string) $invoice_id
                )
            );
        }

        if ( ! $id ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                    $order_id
                )
            );
        }

        if ( ! $id ) {
            return false;
        }

        $data['updated_at'] = current_time( 'mysql', true );
        return false !== $wpdb->update( $table, $data, array( 'id' => $id ) );
    }

    /**
     * Resolve WooCommerce order ID from any historical attempt identifiers.
     *
     * @return int
     */
    public static function find_order_id_by_provider_ids( $invoice_id = '', $payment_id = '' ) {
        global $wpdb;
        $table = self::table_name();

        if ( $payment_id ) {
            $order_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT order_id FROM {$table} WHERE payment_id = %s ORDER BY id DESC LIMIT 1",
                    (string) $payment_id
                )
            );
            if ( $order_id ) {
                return $order_id;
            }
        }

        if ( $invoice_id ) {
            $order_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT order_id FROM {$table} WHERE invoice_id = %s ORDER BY id DESC LIMIT 1",
                    (string) $invoice_id
                )
            );
            if ( $order_id ) {
                return $order_id;
            }
        }

        return 0;
    }

    public static function update_by_invoice( $invoice_id, $data ) {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql', true );
        return false !== $wpdb->update( self::table_name(), $data, array( 'invoice_id' => (string) $invoice_id ) );
    }

    public static function latest( $limit = 100, $offset = 0 ) {
        global $wpdb;
        $table = self::table_name();
        $limit = max( 1, min( 200, absint( $limit ) ) );
        $offset = max( 0, absint( $offset ) );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
    }

    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() );
    }

    public static function pending( $limit = 20 ) {
        global $wpdb;
        $table = self::table_name();
        $limit = max( 1, min( 50, absint( $limit ) ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status IN ('pending','processing') AND created_at >= %s ORDER BY id ASC LIMIT %d",
                $cutoff,
                $limit
            )
        );
    }

    public static function remember_event( $reference, $event_name, $invoice_id, $payment_id ) {
        global $wpdb;

        if ( empty( $reference ) ) {
            return true;
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . self::events_table_name() . ' (event_reference, event_name, invoice_id, payment_id, created_at) VALUES (%s,%s,%s,%s,%s)',
                $reference,
                $event_name,
                $invoice_id,
                $payment_id,
                current_time( 'mysql', true )
            )
        );

        return 1 === (int) $result;
    }
}
