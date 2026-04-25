<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ================= FETCH ORDERS =================
add_action('wp_ajax_fetch_all_orders_dashboard', 'handle_fetch_all_orders_dashboard');

function handle_fetch_all_orders_dashboard() {
    check_ajax_referer('qr_order_nonce', 'security');

    global $wpdb;
    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $items_table  = $wpdb->prefix . 'qrrs_order_items';

    $today_start = current_time('Y-m-d 00:00:00');
    $today_end   = current_time('Y-m-d 23:59:59');

    // Stats Query
    $stats = $wpdb->get_row("
        SELECT 
            COUNT(id) as total,
            SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as preparing,
            SUM(CASE WHEN order_status = 'ready' THEN 1 ELSE 0 END) as served,
            SUM(CASE WHEN order_status IN ('ready', 'settle_bill') THEN 1 ELSE 0 END) as settling,
            SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM $orders_table 
        WHERE created_at BETWEEN '$today_start' AND '$today_end'
    ");

    // Orders Query (Updated Column Names)
    $orders = $wpdb->get_results("
        SELECT id, table_name, created_at, order_status,
               total_amount, tax_amount, service_charge, grand_total
        FROM $orders_table
        WHERE created_at BETWEEN '$today_start' AND '$today_end'
        AND order_status NOT IN ('completed','cancelled')
        ORDER BY id DESC
    ");

    $data = [];
    foreach ($orders as $order) {
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT item_name, quantity FROM $items_table WHERE order_id = %d",
            $order->id
        ));

        $items_html = '';
        foreach ($items as $item) {
            $items_html .= "<div><strong>{$item->quantity}x</strong> {$item->item_name}</div>";
        }

        $data[] = [
            'id'             => $order->id,
            'table_name'     => $order->table_name,
            'status'         => $order->order_status,
            'subtotal'       => (float)$order->total_amount,
            'vat_amount'     => (float)$order->tax_amount,
            'service_charge' => (float)$order->service_charge,
            'total_amount'   => (float)$order->grand_total,
            'time_ago'       => human_time_diff(strtotime($order->created_at), current_time('timestamp')) . ' ago',
            'items_html'     => $items_html
        ];
    }

    wp_send_json_success([
        'orders' => $data,
        'stats'  => $stats
    ]);
}

// ================= UPDATE STATUS =================
add_action('wp_ajax_update_dashboard_order_status', 'handle_update_dashboard_order_status');

// function handle_update_dashboard_order_status() {
//     check_ajax_referer('qr_order_nonce', 'security');

//     global $wpdb;
//     $order_table = $wpdb->prefix . 'qrrs_orders';

//     $order_id = intval($_POST['order_id']);
//     $status   = sanitize_text_field($_POST['status']);

//     // নতুন স্ট্যাটাস ফ্লো লজিক: Pending > Processing > Ready > Served > Completed > Paid
//     $update_data = ['order_status' => $status];
//     $update_format = ['%s'];

//     // পেইড হলে পেমেন্ট স্ট্যাটাসও আপডেট হবে
//     if ($status === 'paid') {
//         $update_data['payment_status'] = 'paid';
//         $update_data['order_status']   = 'completed'; // Paid মানেই সাইকেল শেষ
//         array_push($update_format, '%s');
//     }

//     // বিলিং ডাটা আপডেট (যদি থাকে)
//     if (isset($_POST['total'])) {
//         $update_data['total_amount']   = floatval($_POST['subtotal']);
//         $update_data['tax_amount']     = floatval($_POST['vat']);
//         $update_data['service_charge'] = floatval($_POST['service']);
//         $update_data['grand_total']    = floatval($_POST['total']);
//         array_push($update_format, '%f', '%f', '%f', '%f');
//     }

//     $updated = $wpdb->update(
//         $order_table,
//         $update_data,
//         ['id' => $order_id],
//         $update_format,
//         ['%d']
//     );

//     if ($updated !== false) {
//         wp_send_json_success('Order status updated to ' . $status);
//     } else {
//         wp_send_json_error($wpdb->last_error);
//     }
// }