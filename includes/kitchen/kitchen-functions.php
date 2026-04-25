<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_ajax_fetch_kitchen_orders', 'handle_fetch_kitchen_orders');

function handle_fetch_kitchen_orders() {

    check_ajax_referer('qr_order_nonce', 'security');

    global $wpdb;

    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $items_table  = $wpdb->prefix . 'qrrs_order_items';

    $user_id = get_current_user_id();

    if (!$user_id) {
        wp_send_json_error('User not logged in');
    }

    // restaurant id
    $staff = $wpdb->get_row($wpdb->prepare(
        "SELECT restaurant_id FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d",
        $user_id
    ));

    $restaurant_id = $staff ? intval($staff->restaurant_id) : 0;

    if (!$restaurant_id) {
        wp_send_json_error('No restaurant assigned');
    }

    // date range
    $today_start = current_time('Y-m-d 00:00:00');
    $today_end   = current_time('Y-m-d 23:59:59');

    // stats
    $stats_raw = $wpdb->get_row($wpdb->prepare("
        SELECT
            COUNT(id) as total,
            SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN order_status = 'ready' THEN 1 ELSE 0 END) as complete,
            SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancel,
            SUM(CASE WHEN table_name = 'Take Out' THEN 1 ELSE 0 END) as take_away,
            SUM(CASE WHEN table_name != 'Take Out' THEN 1 ELSE 0 END) as table_order
        FROM $orders_table
        WHERE restaurant_id = %d
        AND created_at BETWEEN %s AND %s
    ", $restaurant_id, $today_start, $today_end));

    // orders কুয়েরি পরিবর্তন করুন (kitchen-function.php এর ভেতর)
    $orders = $wpdb->get_results($wpdb->prepare("
        SELECT id, table_name, created_at, order_status as raw_status
        FROM $orders_table
        WHERE restaurant_id = %d
        AND created_at BETWEEN %s AND %s
        AND order_status IN ('pending','processing') -- এখানে নিশ্চিত করুন এই দুটি স্ট্যাটাস আছে
        ORDER BY created_at ASC
    ", $restaurant_id, $today_start, $today_end));

    $formatted = [];

    foreach ($orders as $order) {

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT item_name, quantity FROM $items_table WHERE order_id = %d",
            $order->id
        ));

        $items_html = '';
        foreach ($items as $item) {
            $items_html .= "<div>{$item->quantity}x {$item->item_name}</div>";
        }

        $formatted[] = [
            'id' => $order->id,
            'table_name' => esc_html($order->table_name),
            'raw_status' => $order->raw_status,
            'time_ago' => human_time_diff(strtotime($order->created_at), current_time('timestamp')) . ' ago',
            'items_html' => $items_html
        ];
    }

    wp_send_json_success([
        'stats' => [
            'total' => (int)($stats_raw->total ?? 0),
            'confirmed' => (int)($stats_raw->confirmed ?? 0),
            'table_order' => (int)($stats_raw->table_order ?? 0),
            'take_away' => (int)($stats_raw->take_away ?? 0),
            'complete' => (int)($stats_raw->complete ?? 0),
            'cancel' => (int)($stats_raw->cancel ?? 0),
        ],
        'orders' => $formatted
    ]);
}

add_action('wp_ajax_update_qr_order_status', 'handle_kitchen_status_update');

function handle_kitchen_status_update() {

    check_ajax_referer('qr_order_nonce', 'security');

    global $wpdb;

    $wpdb->update(
        $wpdb->prefix . 'qrrs_orders',
        ['order_status' => sanitize_text_field($_POST['status'])],
        ['id' => intval($_POST['order_id'])]
    );

    wp_send_json_success('updated');
}