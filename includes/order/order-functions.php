<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ================= FETCH ORDERS =================
add_action('wp_ajax_fetch_all_orders_dashboard', 'handle_fetch_all_orders_dashboard');

function handle_fetch_all_orders_dashboard() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Please login again' );
    }

    global $wpdb;
    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $items_table  = $wpdb->prefix . 'qrrs_order_items';

    $restaurant_id = isset($_POST['restaurant_id']) ? intval($_POST['restaurant_id']) : 0;
    if ( ! $restaurant_id ) wp_send_json_error('No restaurant ID');


    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);
    
    $today_start = $local_now->format('Y-m-d 00:00:00');
    $today_end   = $local_now->format('Y-m-d 23:59:59');

    $stats = $wpdb->get_row($wpdb->prepare("
        SELECT 
            COUNT(id) as total,
            SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as preparing,
            SUM(CASE WHEN order_status = 'ready' THEN 1 ELSE 0 END) as served,
            SUM(CASE WHEN order_status IN ('billing', 'settle_bill') THEN 1 ELSE 0 END) as settling,
            SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM $orders_table 
        WHERE restaurant_id = %d 
        AND created_at BETWEEN %s AND %s
    ", $restaurant_id, $today_start, $today_end));

    $orders = $wpdb->get_results($wpdb->prepare("
        SELECT id, table_name, created_at, order_status,
               total_amount, tax_amount, service_charge, grand_total
        FROM $orders_table
        WHERE restaurant_id = %d 
        AND created_at BETWEEN %s AND %s
        AND order_status NOT IN ('completed','cancelled')
        ORDER BY id DESC
    ", $restaurant_id, $today_start, $today_end));

    $data = [];
    foreach ($orders as $order) {
        $items = $wpdb->get_results($wpdb->prepare("SELECT item_name, quantity, price, item_type, variants_selected FROM $items_table WHERE order_id = %d", $order->id));
        $items_data = [];
        $calculated_subtotal = 0;
        foreach ($items as $item) {
            $line_total = (float)($item->price * $item->quantity);
            $calculated_subtotal += $line_total;
            $items_data[] = [
                'name' => $item->item_name, 
                'qty' => $item->quantity, 
                'price' => (float)$item->price, 
                'line_total' => $line_total, 
                'item_type' => $item->item_type, 
                'variant_name' => $item->variants_selected
            ];
        }

        
        $order_timestamp = strtotime($order->created_at);
        $current_local_timestamp = $local_now->getTimestamp();
        $time_diff_text = human_time_diff($order_timestamp, $current_local_timestamp) . ' ago';

        $data[] = [
            'id' => $order->id, 
            'table_name' => $order->table_name, 
            'status' => $order->order_status, 
            'subtotal' => $calculated_subtotal, 
            'vat_amount' => (float)$order->tax_amount, 
            'service_charge' => (float)$order->service_charge, 
            'time_ago' => $time_diff_text, 
            'items' => $items_data
        ];
    }
    wp_send_json_success(['orders' => $data, 'stats' => $stats]);
}

// ================= UPDATE STATUS =================
add_action('wp_ajax_update_dashboard_order_status', 'qrrs_final_order_status_update');

function qrrs_final_order_status_update() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Unauthorized: Not logged in' );
        exit;
    }

    global $wpdb;
    $order_table = $wpdb->prefix . 'qrrs_orders';

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $status   = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

    if ( !$order_id || !$status ) {
        wp_send_json_error('No Data');
        exit;
    }

    $update_data = ['order_status' => $status];
    $update_format = ['%s'];

    if ($status === 'paid' || $status === 'completed') {
        $update_data['payment_status'] = 'paid';
        $update_data['order_status']   = 'completed';
        $update_format[] = '%s';
    }

    $updated = $wpdb->update($order_table, $update_data, ['id' => $order_id], $update_format, ['%d']);

    if ($updated !== false) {
        wp_send_json_success('Updated');
    } else {
        wp_send_json_error('DB Error: ' . $wpdb->last_error);
    }
    exit;
}