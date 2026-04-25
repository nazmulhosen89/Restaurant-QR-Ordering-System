<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QRRS_Database {

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $prefix = $wpdb->prefix . 'qrrs_';

        /**
         * 1. Restaurants Table
         * Protiti restaurant-er settings ekhane thakbe.
         */
        $sql_restaurants = "CREATE TABLE {$prefix}restaurants (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            owner_id bigint(20) NOT NULL,
            restaurant_name varchar(255) NOT NULL,
            restaurant_logo varchar(255),
            address text, -- Missing field added
            currency_symbol varchar(10) DEFAULT '$',
            tax_percent decimal(5,2) DEFAULT 0.00,
            service_charge_percent decimal(5,2) DEFAULT 0.00,
            pos_printer_settings text,
            report_printer_settings text,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        /**
         * 2. Tables (Dining Tables)
         */
        $sql_tables = "CREATE TABLE {$prefix}tables (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            table_name varchar(100) NOT NULL,
            capacity int(11) DEFAULT 0,
            qr_token varchar(100),
            status varchar(20) DEFAULT 'available',
            PRIMARY KEY (id)
        ) $charset_collate;";

        /**
         * 3. Categories
         */
        $sql_categories = "CREATE TABLE {$prefix}categories (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            Category_name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        /**
         * 4. Menu Items
         */
        $sql_items = "CREATE TABLE {$prefix}items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            category_id bigint(20) NOT NULL,
            item_name varchar(255) NOT NULL,
            item_image varchar(255),
            description text,
            portion_size varchar(100),
            variants_json text, 
            prep_time varchar(50),
            price decimal(10,2) NOT NULL,
            is_available tinyint(1) DEFAULT 1,
            PRIMARY KEY (id)
        ) $charset_collate;";

        /**
         * 5. Orders (Main Header)
         */
        $sql_orders = "CREATE TABLE {$prefix}orders (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            table_name bigint(20) NOT NULL,
            waiter_id bigint(20),
            total_amount decimal(10,2) DEFAULT 0.00,
            tax_amount decimal(10,2) DEFAULT 0.00,
            service_charge decimal(10,2) DEFAULT 0.00,
            grand_total decimal(10,2) DEFAULT 0.00,
            order_status varchar(20) DEFAULT 'pending',
            payment_status varchar(20) DEFAULT 'unpaid',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        /**
         * 6. Order Items (Details)
         */
        $sql_order_items = "CREATE TABLE {$prefix}order_items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            item_name bigint(20) NOT NULL,
            quantity int(11) NOT NULL,
            price decimal(10,2) NOT NULL,
            variants_selected text,
            item_status varchar(20) DEFAULT 'pending',
            PRIMARY KEY (id)
        ) $charset_collate;";

        
        $table_staff = $wpdb->prefix . 'qrrs_staff';
        $sql_staff = "CREATE TABLE $table_staff (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            restaurant_id bigint(20) NOT NULL,
            staff_role varchar(50) NOT NULL, -- manager, waiter, kitchen
            assigned_by bigint(20) NOT NULL, -- ke ei staff-ke add korlo
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY restaurant_id (restaurant_id)
        ) $charset_collate;";




$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}qrrs_kitchen_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    opened_by INT,
    closed_by INT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    status VARCHAR(20) DEFAULT 'open'
)");

        // Execute queries
        dbDelta( $sql_restaurants );
        dbDelta( $sql_tables );
        dbDelta( $sql_categories );
        dbDelta( $sql_items );
        dbDelta( $sql_orders );
        dbDelta( $sql_order_items );
        dbDelta( $sql_staff );
    }
}