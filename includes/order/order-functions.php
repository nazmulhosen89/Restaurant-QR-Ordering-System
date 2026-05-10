<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ================= FETCH ORDERS =================
add_action('wp_ajax_fetch_all_orders_dashboard', 'handle_fetch_all_orders_dashboard');

function handle_fetch_all_orders_dashboard() {
    check_ajax_referer('qr_order_nonce', 'security');

    global $wpdb;
    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $items_table  = $wpdb->prefix . 'qrrs_order_items';

    $restaurant_id = isset($_POST['restaurant_id']) ? intval($_POST['restaurant_id']) : 0;

    if ( ! $restaurant_id ) {
        wp_send_json_error('No restaurant ID provided');
    }

    $today_start = current_time('Y-m-d 00:00:00');
    $today_end   = current_time('Y-m-d 23:59:59');

    // Stats Query: Updated to match Waiter/POS logic
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

    // Orders Query: Only fetch Active Orders (Important for Table Busy Logic)
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
        // orders লুপের ভেতরে এটি পরিবর্তন করুন
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT item_name, quantity, price, item_type, variants_selected 
            FROM $items_table WHERE order_id = %d",
            $order->id
        ));

        $items_data = [];
        $calculated_subtotal = 0;
        foreach ($items as $item) {
            $line_total = (float)($item->price * $item->quantity);
            $calculated_subtotal += $line_total; // ← প্রতিটা item এর total যোগ করো

            $items_data[] = [
                'name'         => $item->item_name,
                'qty'          => $item->quantity,
                'price'        => (float)$item->price,
                'line_total'   => $line_total,
                'item_type'    => $item->item_type,
                'variant_name' => $item->variants_selected
            ];
        }

        $vat     = (float)$order->tax_amount;
        $service = (float)$order->service_charge;
        $grand   = $calculated_subtotal + $vat + $service;

        $data[] = [
            'id'             => $order->id,
            'table_name'     => $order->table_name,
            'status'         => $order->order_status,
            'subtotal'       => $calculated_subtotal, // ← DB এর বদলে calculated
            'vat_amount'     => $vat,
            'service_charge' => $service,
            'total_amount'   => $grand,               // ← DB এর বদলে calculated
            'time_ago'       => human_time_diff(strtotime($order->created_at), current_time('timestamp')) . ' ago',
            'items'          => $items_data
        ];
    }

    wp_send_json_success([
        'orders' => $data,
        'stats'  => $stats
    ]);
}

// ================= UPDATE STATUS (Uncommented & Enhanced) =================
add_action('wp_ajax_update_dashboard_order_status', 'handle_update_dashboard_order_status');

if ( ! function_exists( 'handle_update_dashboard_order_status' ) ) {
    function handle_update_dashboard_order_status() {
        check_ajax_referer('qr_order_nonce', 'security');

        global $wpdb;
        $order_table = $wpdb->prefix . 'qrrs_orders';

        $order_id = intval($_POST['order_id']);
        $status   = sanitize_text_field($_POST['status']);

        // প্রাথমিক ডাটা সেট
        $update_data = ['order_status' => $status];
        $update_format = ['%s'];

        /**
         * লজিক ১: Close Order (Billing)
         * ওয়েটার বা এডমিন যখন Close Order দিবে, তখন স্ট্যাটাস হবে 'billing'
         */
        if ($status === 'billing') {
            $update_data['order_status'] = 'billing';
        }

        /**
         * লজিক ২: পেমেন্ট কালেকশন (Completed)
         * যখন ক্যাশিয়ার/এডমিন পেমেন্ট কনফার্ম করবে, তখন payment_status ও order_status আপডেট হবে
         */
        if ($status === 'paid' || $status === 'completed') {
            $update_data['payment_status'] = 'paid';
            $update_data['order_status']   = 'completed';
            $update_format[] = '%s'; // payment_status এর জন্য ফরম্যাট
        }

        $updated = $wpdb->update(
            $order_table,
            $update_data,
            ['id' => $order_id],
            $update_format,
            ['%d']
        );

        if ($updated !== false) {
            wp_send_json_success('Order updated to ' . $status);
        } else {
            wp_send_json_error('Database Error: ' . $wpdb->last_error);
        }
    }
}



