<?php
/**
 * Plugin Name: Restaurant System (Multi-Restaurant SaaS)
 * Description: A full frontend-based Multi-Restaurant Management System with QR Ordering, POS, and Subscriptions.
 * Version: 1.2.0
 * Author: Nazmul Hosen
 * Links: www.nazmulh.com
 * Text Domain: qr-restaurant-system
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define Constants
define( 'QRRS_PATH', plugin_dir_path( __FILE__ ) );
define( 'QRRS_URL', plugin_dir_url( __FILE__ ) );

class QR_Restaurant_System {

    public function __construct() {
        add_action('init', [$this, 'start_session'], 1);
        $this->includes();
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
        require_once QRRS_PATH . 'includes/menu/menu-functions.php';
        require_once QRRS_PATH . 'includes/order/order-functions.php';
        require_once QRRS_PATH . 'includes/subscriptions/subscription-functions.php';
        require_once QRRS_PATH . 'includes/waiter/waiter-functions.php';

        require_once QRRS_PATH . 'includes/reports/report-functions.php';

        if ( file_exists( QRRS_PATH . 'includes/kitchen/kitchen-functions.php' ) ) {
            require_once QRRS_PATH . 'includes/kitchen/kitchen-functions.php';
        }

<<<<<<< HEAD
        require_once QRRS_PATH . 'includes/menu/public-menu-shortcode.php';
        require_once QRRS_PATH . 'includes/menu/category-shortcode.php';
        
=======
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        // Report AJAX Handlers
        require_once QRRS_PATH . 'includes/reports/handlers/sales-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/item-wise-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/category-wise-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/order-type-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/order-report-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/kitchen-report-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/staff-report-handler.php';
        require_once QRRS_PATH . 'includes/reports/handlers/payment-tax-handler.php';
<<<<<<< HEAD
=======

>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
    }

    public function start_session() {
    if ( ! session_id() ) {
        session_start();
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

<<<<<<< HEAD
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
=======
        // --- External Styles (Agey load kora bhalo) ---
        wp_enqueue_style( 'bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', array(), '5.3.3' );
        wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );
        wp_enqueue_style( 'flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', array(), '4.6.13' );
       wp_enqueue_style( 
            'material-icons-outlined', 
            'https://fonts.googleapis.com/css2?family=Material+Icons+Outlined&display=block', 
            array(), 
            null 
        );
        // --- Custom Styles (Sob shesh-e jate override kora jay) ---
        wp_enqueue_style(
            'qrrs-frontend-style',
            QRRS_URL . 'assets/css/frontend.css',
            ['bootstrap-css'], // Bootstrap-er upor nirbhor korle
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
            '1.2.0'
        );

        // --- External Scripts ---
<<<<<<< HEAD
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.2', true );
=======
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.2', true); // Updated version
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        wp_enqueue_script( 'flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js', [], '4.6.13', true );
        wp_enqueue_script( 'bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', array('jquery'), '5.3.3', true );

        // --- Custom Scripts ---
        wp_enqueue_script(
            'qrrs-app-js',
            QRRS_URL . 'assets/js/app.js',
<<<<<<< HEAD
            ['jquery', 'bootstrap-js'],
=======
            ['jquery', 'bootstrap-js'], // Bootstrap ba jQuery dorkar hole
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
            '1.2.0',
            true
        );

        wp_localize_script('qrrs-app-js', 'qrrs_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('qrrs_nonce_action'),
            'qr_nonce' => wp_create_nonce('qr_order_nonce')
        ]);

<<<<<<< HEAD
        $plugin_pages = array('restaurant-login', 'restaurant-dashboard', 'waiter-dashboard', 'kitchen-dashboard', 'billing-counter', 'restaurant-menu');

        if ( is_page($plugin_pages) ) {
            $custom_css = "
                .site-header, .site-footer, .header, .footer, #masthead, #colophon {
                    display: none !important;
                }
=======

        $plugin_pages = array('restaurant-login', 'restaurant-dashboard', 'waiter-dashboard', 'kitchen-dashboard', 'billing-counter', 'restaurant-menu');
    
        if ( is_page($plugin_pages) ) {
            // Sudhu ei page gulor jonno ekta inline CSS add korbe
            $custom_css = "
                .site-header, .site-footer, .header, .footer, #masthead, #colophon { 
                    display: none !important; 
                }
                /* Theme jodi body padding dey seta remove korar jonno */
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
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
<<<<<<< HEAD

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
=======
    
        add_action('wp_ajax_fetch_sales_report_data', 'handle_fetch_sales_report_data');
        add_action('wp_ajax_fetch_item_wise_report', 'handle_fetch_item_wise_report');
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
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
    $items    = json_decode(wp_unslash($_POST['items']), true);

    if ( empty($items) ) { wp_send_json_error('Cart is empty.'); }

    $subtotal       = floatval($_POST['subtotal']);
    $tax_amount     = floatval($_POST['tax_amount']);
    $service_charge = floatval($_POST['service_charge']);
    $grand_total    = floatval($_POST['grand_total']);

    $final_table_name = 'Take Out';
    if ( $table_id > 0 ) {
        $db_table_name = $wpdb->get_var($wpdb->prepare("SELECT table_name FROM $tables_db WHERE id = %d", $table_id));
        if ( $db_table_name ) { $final_table_name = $db_table_name; }
    }

    /**
     * ✨ FIX: কারেন্ট ওর্ডার সেভ টাইমেও সঠিক লোকাল ডিভাইস জোন সেট করা
     */
    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);

    $order_data = [
        'restaurant_id'  => $res_id,
        'table_name'     => $final_table_name,
        'waiter_id'      => get_current_user_id(),
        'total_amount'   => $subtotal,
        'tax_amount'     => $tax_amount,
        'service_charge' => $service_charge,
        'grand_total'    => $grand_total,
        'order_status'   => 'pending',
        'payment_status' => 'unpaid',
        'created_at'     => $local_now->format('Y-m-d H:i:s') // 👈 লোকাল ডিভাইস টাইম পুশ করা হলো
    ];

    $inserted = $wpdb->insert($order_table, $order_data);
    if ( $inserted === false ) { wp_send_json_error('DB Order Error: ' . $wpdb->last_error); }

    $order_id = $wpdb->insert_id;

    foreach ( $items as $item ) {
        $wpdb->insert($items_table, [
            'order_id'          => $order_id,
            'item_id'           => intval($item['id']),
            'restaurant_id'     => $res_id,
            'item_name'         => sanitize_text_field($item['name']),
            'variants_selected' => sanitize_text_field($item['variants_selected']),
            'quantity'          => intval($item['qty']),
            'price'             => floatval($item['price']),
            'item_status'       => 'pending'
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

<<<<<<< HEAD
    $update_data = array('order_status' => $status);

    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);
    $exact_time  = $local_now->format('Y-m-d H:i:s');

    if ( $status === 'ready' || $status === 'served' ) {
        $update_data['ready_at'] = $exact_time;
=======
    // ✅ array() syntax — PHP compatibility
    $update_data = array('order_status' => $status);

    // ✅ ready বা served হলে ready_at সেট করো
    if ( $status === 'ready' || $status === 'served' ) {
        $update_data['ready_at'] = current_time('mysql');
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
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


add_action('wp_ajax_qrrs_set_active_restaurant', function() {
    if (current_user_can('manage_options')) { // Security check
        $res_id = intval($_POST['res_id']);
        if (!session_id()) session_start();
        $_SESSION['qrrs_selected_res_id'] = $res_id;
        
        // Response success pathano
        wp_send_json_success(['message' => 'Restaurant Updated']);
    }
    wp_die();
});





new QR_Restaurant_System();