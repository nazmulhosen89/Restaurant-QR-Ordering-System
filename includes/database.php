<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QRRS_Database {

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        // Table Prefix
        $prefix = $wpdb->prefix . 'qrrs_';

        /**
         * 1. Restaurants Table (Updated with your fields)
         */
        $sql_restaurants = "CREATE TABLE {$prefix}restaurants (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            owner_id bigint(20) NOT NULL,
            restaurant_name varchar(255) NOT NULL,
            restaurant_logo varchar(255),
            phone varchar(20),
            bin_number varchar(100), -- Business Identification Number
            address text,
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
         * 2. Staff Table
         */
        $sql_staff = "CREATE TABLE {$prefix}staff (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            restaurant_id bigint(20) NOT NULL,
            staff_role varchar(50) NOT NULL,
            assigned_by bigint(20) NOT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY restaurant_id (restaurant_id)
        ) $charset_collate;";

        /**
         * 3. Tables (Dining Tables)
         */
        $sql_tables = "CREATE TABLE {$prefix}tables (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            table_name varchar(100) NOT NULL,
            capacity int(11) DEFAULT 0,
            qr_token varchar(100),
            status varchar(20) DEFAULT 'available',
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id)
        ) $charset_collate;";

        /**
         * 4. Categories
         */
        $sql_categories = "CREATE TABLE {$prefix}categories (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            category_name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            image varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id)
        ) $charset_collate;";

        /**
         * 5. Menu Items
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
            is_tax_free tinyint(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (id),
            KEY category_id (category_id)
        ) $charset_collate;";

        /**
         * 6. Orders
         */
        $sql_orders = "CREATE TABLE {$prefix}orders (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            table_name varchar(100) NOT NULL,   
            table_id bigint(20) NOT NULL,
            waiter_id bigint(20),
            order_type varchar(20) DEFAULT 'dine_in',
            total_amount decimal(10,2) DEFAULT 0.00,
            tax_amount decimal(10,2) DEFAULT 0.00,
            service_charge decimal(10,2) DEFAULT 0.00,
            grand_total decimal(10,2) DEFAULT 0.00,
            discount_amount decimal(10,2) DEFAULT 0.00,  
            final_total decimal(10,2) DEFAULT 0.00,       
            payment_method varchar(50) DEFAULT 'cash',    
            amount_received decimal(10,2) DEFAULT 0.00,  
            cash_returned decimal(10,2) DEFAULT 0.00,    
            order_status varchar(20) DEFAULT 'pending',
            ready_at datetime DEFAULT NULL,              
            payment_status varchar(20) DEFAULT 'unpaid',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id)
        ) $charset_collate;";

        /**
         * 7. Order Items
         */
        $sql_order_items = "CREATE TABLE {$prefix}order_items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            item_id bigint(20) NOT NULL,
            restaurant_id int(11) NOT NULL,       
            item_name varchar(100) NOT NULL,      
            quantity int(11) NOT NULL,
            price decimal(10,2) NOT NULL,
            variants_selected text,
            item_status varchar(20) DEFAULT 'pending',
            item_type varchar(20) DEFAULT 'original', 
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) $charset_collate;";

        /**
         * 8. Kitchen Sessions
         */
        $sql_kitchen_sessions = "CREATE TABLE {$prefix}kitchen_sessions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            opened_by bigint(20),
            closed_by bigint(20) DEFAULT NULL,
            opened_at datetime NOT NULL,
            closed_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'open',
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id)
        ) $charset_collate;";

        // Execute all queries
        dbDelta( $sql_restaurants );
        dbDelta( $sql_staff );
        dbDelta( $sql_tables );
        dbDelta( $sql_categories );
        dbDelta( $sql_items );
        dbDelta( $sql_orders );
        dbDelta( $sql_order_items );
        dbDelta( $sql_kitchen_sessions );
    }
}