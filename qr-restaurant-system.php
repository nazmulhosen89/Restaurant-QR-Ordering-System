<?php
/**
 * Plugin Name: DineX Restro Solutions.
 * Tagline: Manage Smarter. Serve Faster. Grow Bigger.
 * Description: Streamline restaurant operations with AI-powered automation. Manage orders, kitchen workflows, inventory, POS, and customer experiences from a single platform.
 * Version: 1.5.8
 * Author: Nazmul Hosen
 * Links: www.nazmulh.com
 * Text Domain: dinex-restro-system
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define Constants
define( 'QRRS_PATH', plugin_dir_path( __FILE__ ) );
define( 'QRRS_URL', plugin_dir_url( __FILE__ ) );

class QR_Restaurant_System {

    public function __construct() {
        $this->includes();
        $this->maybe_upgrade_database();
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        $this->register_shortcodes();
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        $this->register_ajax_handlers();
    }

    private function includes() {
        require_once QRRS_PATH . 'includes/database.php';
        require_once QRRS_PATH . 'includes/auth.php';
        require_once QRRS_PATH . 'includes/helpers.php';

        require_once QRRS_PATH . 'includes/restaurant/restaurant-functions.php';
        require_once QRRS_PATH . 'includes/license/license-functions.php';
        require_once QRRS_PATH . 'includes/inventory/inventory-functions.php';
        require_once QRRS_PATH . 'includes/inventory/wastage.php';
        require_once QRRS_PATH . 'includes/inventory/variant-recipes.php';
require_once QRRS_PATH . 'includes/inventory/option-setup.php';
require_once QRRS_PATH . 'includes/inventory/order-stock.php';
        require_once QRRS_PATH . 'includes/menu/menu-functions.php';
        require_once QRRS_PATH . 'includes/order/order-functions.php';
        require_once QRRS_PATH . 'includes/subscriptions/subscription-functions.php';
        require_once QRRS_PATH . 'includes/waiter/waiter-functions.php';

        require_once QRRS_PATH . 'includes/reports/report-functions.php';

        if ( file_exists( QRRS_PATH . 'includes/kitchen/kitchen-functions.php' ) ) {
            require_once QRRS_PATH . 'includes/kitchen/kitchen-functions.php';
        }

        require_once QRRS_PATH . 'includes/menu/public-menu-shortcode.php';
        require_once QRRS_PATH . 'includes/menu/category-shortcode.php';
        
        // Report AJAX Handlers
        require_once QRRS_PATH . 'includes/reports/handlers/sales-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/item-wise-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/category-wise-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/order-type-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/order-report-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/kitchen-report-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/staff-report-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/payment-tax-handler.php';
    }

    private function maybe_upgrade_database() {
        $schema_version = get_option( 'qrrs_inventory_schema_version' );

        if ( '1.1.0' === $schema_version ) {
            return;
        }

        if ( class_exists( 'QRRS_Database' ) ) {
            QRRS_Database::create_tables();
            update_option( 'qrrs_inventory_schema_version', '1.1.0' );
        }
    }

    public function activate() {
        if ( class_exists( 'QRRS_Database' ) ) {
            QRRS_Database::create_tables();
        }
        $this->create_frontend_pages();

        if ( ! get_option( 'qrrs_plugin_installed_at' ) ) {
            
            $wp_timezone = wp_timezone();
            $local_now   = new DateTime('now', $wp_timezone);
            
            update_option( 'qrrs_plugin_installed_at', $local_now->format('Y-m-d H:i:s') );
        }

        flush_rewrite_rules();
    }

    private function create_frontend_pages() {
        $pages = [
            'restaurant-login'     => [ 'title' => 'Restaurant Login', 'content' => '[qrrs_login]' ],
            'restaurant-dashboard' => [ 'title' => 'Admin Dashboard', 'content' => '[qrrs_admin_dashboard]' ],
            'waiter-dashboard'     => [ 'title' => 'Waiter Dashboard', 'content' => '[qrrs_waiter_dashboard]' ],
            'kitchen-dashboard'    => [ 'title' => 'Kitchen Display', 'content' => '[qrrs_kitchen_dashboard]' ],
            'billing-counter'      => [ 'title' => 'Billing & POS', 'content' => '[qrrs_billing_counter]' ],
            'restaurant-menu'      => [ 'title' => 'Digital Menu', 'content' => '[qrrs_digital_menu]' ],
        ];

        foreach ( $pages as $slug => $data ) {
            if ( ! get_page_by_path( $slug ) ) {
                wp_insert_post( [
                    'post_title'   => $data['title'],
                    'post_content' => $data['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $slug,
                ] );
            }
        }
    }

    private function register_shortcodes() {

        add_shortcode( 'qrrs_login', function() {
            ob_start();
            include QRRS_PATH . 'templates/frontend/login.php';
            return ob_get_clean();
        });

        add_shortcode( 'qrrs_admin_dashboard', function() {
            ob_start();
            include QRRS_PATH . 'templates/dashboard/dashboard.php';
            return ob_get_clean();
        });

        add_shortcode( 'qrrs_waiter_dashboard', function() {
            ob_start();
            include QRRS_PATH . 'templates/dashboard/waiter.php';
            return ob_get_clean();
        });

        add_shortcode( 'qrrs_kitchen_dashboard', function() {
            ob_start();
            include QRRS_PATH . 'templates/dashboard/kitchen.php';
            return ob_get_clean();
        });

        add_shortcode( 'qrrs_digital_menu', function() {
            ob_start();
            include QRRS_PATH . 'templates/frontend/menu.php';
            return ob_get_clean();
        });

        add_shortcode( 'qrrs_billing_counter', function() {
            ob_start();
            if ( file_exists( QRRS_PATH . 'templates/dashboard/take-order.php' ) ) {
                include QRRS_PATH . 'templates/dashboard/take-order.php';
            }
            return ob_get_clean();
        });

        add_shortcode( 'staff_profile', function() {
            if ( is_user_logged_in() ) {
                ob_start();
                include QRRS_PATH . 'includes/user/profile.php';
                return ob_get_clean();
            }
        });
    }

    public function enqueue_assets() {

        wp_enqueue_media();
        wp_enqueue_script('jquery');

        // --- External Styles ---
        wp_enqueue_style( 'bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', array(), '5.3.3' );
        wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );
        wp_enqueue_style( 'flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', array(), '4.6.13' );
        wp_enqueue_style(
            'material-icons-outlined',
            'https://fonts.googleapis.com/css2?family=Material+Icons+Outlined&display=block',
            array(),
            null
        );

        // --- Custom Styles ---
        wp_enqueue_style(
            'qrrs-frontend-style',
            QRRS_URL . 'assets/css/frontend.css',
            ['bootstrap-css'],
            '1.2.0'
        );

        // --- External Scripts ---
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.2', true );
        wp_enqueue_script( 'flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js', [], '4.6.13', true );
        wp_enqueue_script( 'bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', array('jquery'), '5.3.3', true );

        // --- Custom Scripts ---
        wp_enqueue_script(
            'qrrs-app-js',
            QRRS_URL . 'assets/js/app.js',
            ['jquery', 'bootstrap-js'],
            '1.2.0',
            true
        );

        wp_localize_script('qrrs-app-js', 'qrrs_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('qrrs_nonce_action'),
            'qr_nonce' => wp_create_nonce('qr_order_nonce')
        ]);

        $plugin_pages = array('restaurant-login', 'restaurant-dashboard', 'waiter-dashboard', 'kitchen-dashboard', 'billing-counter', 'restaurant-menu');

        if ( is_page($plugin_pages) ) {
            $custom_css = "
                .site-header, .site-footer, .header, .footer, #masthead, #colophon {
                    display: none !important;
                }
                body { padding-top: 0 !important; margin-top: 0 !important; }
            ";
            wp_add_inline_style( 'qrrs-frontend-style', $custom_css );
        }
    }

    private function register_ajax_handlers() {
        add_action('wp_ajax_place_qr_order', 'handle_qr_order_placement');
        add_action('wp_ajax_nopriv_place_qr_order', 'handle_qr_order_placement');

        add_action('wp_ajax_fetch_pos_items', 'fetch_pos_items_handler');
        add_action('wp_ajax_nopriv_fetch_pos_items', 'fetch_pos_items_handler');

        add_action('wp_ajax_update_dashboard_order_status', 'handle_update_dashboard_order_status');

        add_action('wp_ajax_fetch_sales_report_data', 'handle_fetch_sales_report_data');
        add_action('wp_ajax_fetch_item_wise_report', 'handle_fetch_item_wise_report');

        add_action('wp_ajax_qrrs_set_active_restaurant', [$this, 'handle_set_active_restaurant']);
    }

    public function handle_set_active_restaurant() {
        check_ajax_referer('qrrs_nonce_action', 'security');
        if ( current_user_can('manage_options') ) {
            $res_id = intval($_POST['res_id']);
            update_user_meta( get_current_user_id(), 'qrrs_selected_res_id', $res_id );
            wp_send_json_success(['message' => 'Restaurant Updated']);
        }
        wp_send_json_error('Unauthorized');
    }
}

/**
 * AJAX: POS Item Fetching
 */
function fetch_pos_items_handler() {
    check_ajax_referer('qrrs_nonce_action', 'security');
    global $wpdb;

    $restaurant_id = isset($_POST['restaurant_id']) ? intval($_POST['restaurant_id']) : 0;
    $category_id   = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

    if ( ! $restaurant_id ) { wp_send_json_error('Invalid Restaurant ID'); }

    $query  = "SELECT * FROM {$wpdb->prefix}qrrs_items WHERE restaurant_id = %d AND is_available = 1";
    $params = [$restaurant_id];

    if ( $category_id > 0 ) {
        $query   .= " AND category_id = %d";
        $params[] = $category_id;
    }

    $items = $wpdb->get_results($wpdb->prepare($query, $params));
    if ( $wpdb->last_error ) { wp_send_json_error($wpdb->last_error); }

    $data = [];
    if ( $items ) {
        foreach ( $items as $item ) {
            $img = '';
            if ( ! empty($item->image_url) )   { $img = $item->image_url; }
            elseif ( ! empty($item->item_image) ) { $img = $item->item_image; }
            elseif ( ! empty($item->image) )   { $img = $item->image; }

            $data[] = [
                'id'       => (int) $item->id,
                'name'     => ! empty($item->item_name) ? $item->item_name : ( $item->name ?? 'Unnamed' ),
                'price'    => (float) $item->price,
                'image'    => $img,
                'variants' => $item->variants ?? $item->variants_json ?? '[]'
            ];
        }
    }
    wp_send_json_success($data);
}

/**
 * AJAX: QR Order Placement
 */
function handle_qr_order_placement() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qr_order_nonce') ) {
        wp_send_json_error('Security check failed.');
    }

    global $wpdb;
    $order_table = $wpdb->prefix . 'qrrs_orders';
    $items_table = $wpdb->prefix . 'qrrs_order_items';
    $tables_db   = $wpdb->prefix . 'qrrs_tables';

    $res_id   = intval($_POST['restaurant_id']);
    $table_id = intval($_POST['table_id']);
    $order_type = sanitize_key( $_POST['order_type'] ?? 'take_out' );
    $requested_source = sanitize_key( $_POST['order_source'] ?? 'qr' );
    $items    = json_decode(wp_unslash($_POST['items']), true);

    if ( empty($items) ) { wp_send_json_error('Cart is empty.'); }

    if ( ! $res_id || ! in_array( $order_type, [ 'dine_in', 'take_out', 'delivery' ], true ) ) {
        wp_send_json_error( 'Invalid order details.' );
    }

    // Server recalculates every line (price, size, add-ons). The browser's prices are not trusted.
    $checked = qrrs_validate_order_items( $res_id, $items );
    if ( is_string( $checked ) ) {
        wp_send_json_error( $checked );
    }
    $items            = $checked['lines'];
    $checked_subtotal = $checked['subtotal'];

    $is_pos_order = $requested_source === 'pos'
        && is_user_logged_in()
        && function_exists( 'qrrs_current_user_can_operate_restaurant' )
        && qrrs_current_user_can_operate_restaurant( $res_id );
    $order_source = $is_pos_order ? 'pos' : 'qr';

    if ( 'delivery' === $order_type && function_exists( 'qrrs_can_use_feature' ) && ! qrrs_can_use_feature( 'delivery' ) ) {
        wp_send_json_error( 'Delivery orders are available in Pro only.' );
    }

    $delivery_customer = [];
    if ( 'delivery' === $order_type ) {
        $delivery_customer = [
            'customer_name'    => sanitize_text_field( $_POST['customer_name'] ?? '' ),
            'customer_phone'   => sanitize_text_field( $_POST['customer_phone'] ?? '' ),
            'delivery_address' => sanitize_textarea_field( $_POST['delivery_address'] ?? '' ),
            'delivery_note'    => sanitize_textarea_field( $_POST['delivery_note'] ?? '' ),
        ];

        if ( ! $delivery_customer['customer_name'] || ! $delivery_customer['customer_phone'] || ! $delivery_customer['delivery_address'] ) {
            wp_send_json_error( 'Customer name, phone number, and delivery address are required.' );
        }
    }

    $subtotal       = floatval($_POST['subtotal']);
    if ( abs( $subtotal - $checked_subtotal ) > 0.01 ) {
        wp_send_json_error( 'Prices have changed. Please refresh the page and try again.' );
    }
    $tax_amount     = floatval($_POST['tax_amount']);
    $service_charge = floatval($_POST['service_charge']);
    $grand_total    = floatval($_POST['grand_total']);

    $final_table_name = 'Take Out';
    if ( $table_id > 0 ) {
        $db_table_name = $wpdb->get_var($wpdb->prepare("SELECT table_name FROM $tables_db WHERE id = %d", $table_id));
        if ( $db_table_name ) { $final_table_name = $db_table_name; }
    } elseif ( 'delivery' === $order_type ) {
        $final_table_name = 'Delivery';
    }

    
    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);
    $kitchen_settings = function_exists( 'qrrs_get_restaurant_kitchen_settings' )
        ? qrrs_get_restaurant_kitchen_settings( $res_id )
        : [ 'mode' => 'active_kitchen', 'capacity' => 1 ];
    $order_status = $order_source === 'qr'
        ? 'awaiting_verification'
        : ( $kitchen_settings['mode'] === 'ready_food' ? 'ready' : 'pending' );
    $verification_status = $order_source === 'qr' ? 'pending' : 'approved';

    $order_data = [
        'restaurant_id'  => $res_id,
        'table_name'     => $final_table_name,
        'order_type'     => $order_type,
        'order_source'   => $order_source,
        'verification_status' => $verification_status,
        'waiter_id'      => get_current_user_id(),
        'total_amount'   => $subtotal,
        'tax_amount'     => $tax_amount,
        'service_charge' => $service_charge,
        'grand_total'    => $grand_total,
        'order_status'   => $order_status,
        'ready_at'       => $order_status === 'ready' ? $local_now->format('Y-m-d H:i:s') : null,
        'payment_status' => 'unpaid',
        'created_at'     => $local_now->format('Y-m-d H:i:s') // 👈 লোকাল ডিভাইস টাইম পুশ করা হলো
    ];

    $inserted = $wpdb->insert($order_table, $order_data);
    if ( $inserted === false ) { wp_send_json_error('DB Order Error: ' . $wpdb->last_error); }

    $order_id = $wpdb->insert_id;

    if ( $delivery_customer ) {
        $customer_inserted = $wpdb->insert(
            $wpdb->prefix . 'qrrs_order_customers',
            array_merge( [
                'order_id'      => $order_id,
                'restaurant_id' => $res_id,
                'created_at'    => $local_now->format('Y-m-d H:i:s'),
            ], $delivery_customer ),
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $customer_inserted ) {
            $wpdb->delete( $order_table, [ 'id' => $order_id ], [ '%d' ] );
            wp_send_json_error( 'Could not save delivery customer details.' );
        }
    }

    foreach ( $items as $item ) {
        $wpdb->insert($items_table, [
            'order_id'          => $order_id,
            'item_id'           => intval($item['id']),
            'restaurant_id'     => $res_id,
            'item_name'         => sanitize_text_field($item['name']),
            'variants_selected' => sanitize_text_field($item['variants_selected']),
            'size_value_ids'    => sanitize_text_field($item['size_value_ids'] ?? ''),
            'size_name'         => sanitize_text_field($item['size_name'] ?? ''),
            'quantity'          => intval($item['qty']),
            'price'             => floatval($item['price']),
            'item_status'       => $order_status === 'ready' ? 'ready' : 'pending'
        ]);
    }
    wp_send_json_success('Order placed successfully!');
}

/**
 * AJAX: Order Status Update
 */
function handle_update_dashboard_order_status() {
    check_ajax_referer('qr_order_nonce', 'security');

    if ( ! current_user_can('manage_options') && ! current_user_can('qrrs_waiter') ) {
        wp_send_json_error('Unauthorized');
    }

    if ( ! isset($_POST['order_id']) || ! isset($_POST['status']) ) {
        wp_send_json_error('Missing data.');
    }

    global $wpdb;
    $order_id    = intval($_POST['order_id']);
    $status      = sanitize_text_field($_POST['status']);
    $order_table = $wpdb->prefix . 'qrrs_orders';

    $update_data = array('order_status' => $status);

    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);
    $exact_time  = $local_now->format('Y-m-d H:i:s');

    if ( $status === 'ready' || $status === 'served' ) {
        $update_data['ready_at'] = $exact_time;
    }

    if ( $status === 'paid' ) {
        $update_data['payment_status'] = 'paid';
        $update_data['order_status']   = 'completed';
    }

    $updated = $wpdb->update(
        $order_table,
        $update_data,
        array('id' => $order_id)
    );

    if ( $updated !== false ) {
        do_action( 'qrrs_order_status_changed', $order_id, $status );
        $wpdb->update(
            $wpdb->prefix . 'qrrs_order_items',
            array('item_status' => $status),
            array('order_id' => $order_id)
        );
        wp_send_json_success('Order status updated to ' . $status);
    } else {
        wp_send_json_error('Database update failed: ' . $wpdb->last_error);
    }
}

new QR_Restaurant_System();