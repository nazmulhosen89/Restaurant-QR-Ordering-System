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
            inventory_deducted tinyint(1) DEFAULT 0,
            inventory_deducted_at datetime DEFAULT NULL,
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

        /**
         * 9. Inventory Categories
         */
        $sql_inventory_categories = "CREATE TABLE {$prefix}inventory_categories (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            category_name varchar(255) NOT NULL,
            category_type varchar(50) DEFAULT 'raw_material',
            status varchar(20) DEFAULT 'active',
            created_by bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id)
        ) $charset_collate;";

        /**
         * 10. Inventory Units
         */
        $sql_inventory_units = "CREATE TABLE {$prefix}inventory_units (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            unit_name varchar(100) NOT NULL,
            unit_code varchar(30) NOT NULL,
            unit_type varchar(50) DEFAULT 'count',
            base_unit_code varchar(30) DEFAULT '',
            conversion_factor decimal(14,4) DEFAULT 1.0000,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY unit_code (unit_code)
        ) $charset_collate;";

        /**
         * 11. Inventory Items
         */
        $sql_inventory_items = "CREATE TABLE {$prefix}inventory_items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            category_id bigint(20) DEFAULT 0,
            unit_id bigint(20) NOT NULL,
            item_name varchar(255) NOT NULL,
            item_type varchar(50) DEFAULT 'raw_material',
            sku varchar(100) DEFAULT '',
            current_stock decimal(14,4) DEFAULT 0.0000,
            min_stock_level decimal(14,4) DEFAULT 0.0000,
            cost_per_unit decimal(14,4) DEFAULT 0.0000,
            storage_location varchar(255) DEFAULT '',
            status varchar(20) DEFAULT 'active',
            created_by bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id),
            KEY category_id (category_id),
            KEY unit_id (unit_id)
        ) $charset_collate;";

        /**
         * 12. Suppliers
         */
        $sql_suppliers = "CREATE TABLE {$prefix}suppliers (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            supplier_name varchar(255) NOT NULL,
            phone varchar(50) DEFAULT '',
            email varchar(120) DEFAULT '',
            address text,
            status varchar(20) DEFAULT 'active',
            created_by bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id)
        ) $charset_collate;";

        /**
         * 13. Stock Movements / Ledger
         */
        $sql_stock_movements = "CREATE TABLE {$prefix}stock_movements (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            inventory_item_id bigint(20) NOT NULL,
            movement_type varchar(50) NOT NULL,
            quantity_in decimal(14,4) DEFAULT 0.0000,
            quantity_out decimal(14,4) DEFAULT 0.0000,
            unit_cost decimal(14,4) DEFAULT 0.0000,
            total_cost decimal(14,4) DEFAULT 0.0000,
            reference_type varchar(50) DEFAULT '',
            reference_id bigint(20) DEFAULT 0,
            note text,
            created_by bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id),
            KEY inventory_item_id (inventory_item_id),
            KEY movement_type (movement_type),
            KEY reference_id (reference_id)
        ) $charset_collate;";

        /**
         * 14. Requisitions
         */
        $sql_requisitions = "CREATE TABLE {$prefix}requisitions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            requested_by bigint(20) NOT NULL,
            requested_role varchar(80) DEFAULT '',
            department varchar(50) DEFAULT 'manager',
            request_type varchar(50) DEFAULT 'raw_material',
            priority varchar(20) DEFAULT 'normal',
            status varchar(30) DEFAULT 'pending',
            note text,
            approved_by bigint(20) DEFAULT 0,
            approved_at datetime DEFAULT NULL,
            issued_by bigint(20) DEFAULT 0,
            issued_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id),
            KEY requested_by (requested_by),
            KEY status (status)
        ) $charset_collate;";

        /**
         * 15. Requisition Items
         */
        $sql_requisition_items = "CREATE TABLE {$prefix}requisition_items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            requisition_id bigint(20) NOT NULL,
            inventory_item_id bigint(20) NOT NULL,
            requested_qty decimal(14,4) DEFAULT 0.0000,
            approved_qty decimal(14,4) DEFAULT 0.0000,
            issued_qty decimal(14,4) DEFAULT 0.0000,
            unit_id bigint(20) DEFAULT 0,
            note text,
            PRIMARY KEY (id),
            KEY requisition_id (requisition_id),
            KEY inventory_item_id (inventory_item_id)
        ) $charset_collate;";

        /**
         * 16. Recipes
         */
        $sql_recipes = "CREATE TABLE {$prefix}recipes (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            menu_item_id bigint(20) NOT NULL,
            recipe_name varchar(255) DEFAULT '',
            serving_qty decimal(14,4) DEFAULT 1.0000,
            status varchar(20) DEFAULT 'active',
            created_by bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id),
            KEY menu_item_id (menu_item_id)
        ) $charset_collate;";

        /**
         * 17. Recipe Items
         */
        $sql_recipe_items = "CREATE TABLE {$prefix}recipe_items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            recipe_id bigint(20) NOT NULL,
            inventory_item_id bigint(20) NOT NULL,
            quantity_required decimal(14,4) DEFAULT 0.0000,
            unit_id bigint(20) NOT NULL,
            wastage_percent decimal(7,2) DEFAULT 0.00,
            cost_snapshot decimal(14,4) DEFAULT 0.0000,
            PRIMARY KEY (id),
            KEY recipe_id (recipe_id),
            KEY inventory_item_id (inventory_item_id)
        ) $charset_collate;";

        /**
         * 18. Wastage
         */
        $sql_wastage = "CREATE TABLE {$prefix}wastage (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restaurant_id bigint(20) NOT NULL,
            inventory_item_id bigint(20) NOT NULL,
            quantity decimal(14,4) DEFAULT 0.0000,
            unit_id bigint(20) DEFAULT 0,
            reason varchar(255) DEFAULT '',
            note text,
            status varchar(30) DEFAULT 'pending',
            reported_by bigint(20) DEFAULT 0,
            approved_by bigint(20) DEFAULT 0,
            approved_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY restaurant_id (restaurant_id),
            KEY inventory_item_id (inventory_item_id),
            KEY status (status)
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
        dbDelta( $sql_inventory_categories );
        dbDelta( $sql_inventory_units );
        dbDelta( $sql_inventory_items );
        dbDelta( $sql_suppliers );
        dbDelta( $sql_stock_movements );
        dbDelta( $sql_requisitions );
        dbDelta( $sql_requisition_items );
        dbDelta( $sql_recipes );
        dbDelta( $sql_recipe_items );
        dbDelta( $sql_wastage );

        self::seed_inventory_units();
    }

    private static function seed_inventory_units() {
        global $wpdb;

        $table = $wpdb->prefix . 'qrrs_inventory_units';
        $defaults = [
            [ 'Kilogram', 'kg', 'weight', 'g', 1000 ],
            [ 'Gram', 'g', 'weight', 'g', 1 ],
            [ 'Liter', 'l', 'volume', 'ml', 1000 ],
            [ 'Milliliter', 'ml', 'volume', 'ml', 1 ],
            [ 'Piece', 'pcs', 'count', 'pcs', 1 ],
            [ 'Packet', 'pkt', 'count', 'pkt', 1 ],
            [ 'Box', 'box', 'count', 'box', 1 ],
        ];

        foreach ( $defaults as $unit ) {
            $exists = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM $table WHERE unit_code = %s", $unit[1] )
            );

            if ( $exists ) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'unit_name'         => $unit[0],
                    'unit_code'         => $unit[1],
                    'unit_type'         => $unit[2],
                    'base_unit_code'    => $unit[3],
                    'conversion_factor' => $unit[4],
                    'status'            => 'active',
                    'created_at'        => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s', '%s', '%f', '%s', '%s' ]
            );
        }
    }
}
