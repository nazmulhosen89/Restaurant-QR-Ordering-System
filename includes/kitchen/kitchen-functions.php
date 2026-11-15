<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_ajax_fetch_kitchen_orders', 'handle_fetch_kitchen_orders');
function handle_fetch_kitchen_orders() {
    check_ajax_referer('qr_order_nonce', 'security');
    global $wpdb;

    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $items_table  = $wpdb->prefix . 'qrrs_order_items';
    $menu_table   = $wpdb->prefix . 'qrrs_items';
    $cat_table    = $wpdb->prefix . 'qrrs_categories';

    $user_id = get_current_user_id();
    if (!$user_id) { wp_send_json_error('User not logged in'); }

    $staff = $wpdb->get_row($wpdb->prepare(
        "SELECT restaurant_id FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d", $user_id
    ));
    $restaurant_id = $staff ? intval($staff->restaurant_id) : 0;
    if (!$restaurant_id) { wp_send_json_error('No restaurant assigned'); }

    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);
    
    $today_start = $local_now->format('Y-m-d 00:00:00');
    $today_end   = $local_now->format('Y-m-d 23:59:59');

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

    $orders = $wpdb->get_results($wpdb->prepare("
        SELECT DISTINCT o.id, o.table_name, o.created_at, o.order_status as raw_status
        FROM $orders_table o
        INNER JOIN $items_table i ON o.id = i.order_id
        WHERE o.restaurant_id = %d
        AND o.created_at BETWEEN %s AND %s
        AND o.order_status NOT IN ('completed', 'cancelled', 'billing')
        AND i.item_status IN ('pending', 'processing')
        ORDER BY o.created_at ASC
    ", $restaurant_id, $today_start, $today_end));

    $formatted = [];
    foreach ($orders as $order) {

        $items = $wpdb->get_results($wpdb->prepare("
            SELECT
                oi.item_name,
                oi.quantity,
                oi.item_id,
                oi.variants_selected,
                COALESCE(oi.item_type,   'original') AS item_type,
                COALESCE(oi.item_status, 'pending')  AS item_status,
                COALESCE(c.category_name, '')         AS category_name
            FROM $items_table oi
            LEFT JOIN $menu_table mi ON oi.item_id = mi.id
            LEFT JOIN $cat_table  c  ON mi.category_id = c.id
            WHERE oi.order_id = %d
            ORDER BY oi.id ASC
        ", $order->id));

        $has_pending               = false;
        $has_processing            = false;
        $all_pending_are_beverages = true;

        foreach ($items as $item) {
            if ($item->item_status === 'pending') {
                $has_pending = true;
                if (stripos(trim($item->category_name), 'beverage') === false) {
                    $all_pending_are_beverages = false;
                }
            }
            if ($item->item_status === 'processing') {
                $has_processing = true;
            }
        }

        if ($has_pending && $all_pending_are_beverages) {
             $next_status = 'ready';
            $btn_label   = '✅ Mark as Ready';
            $btn_class   = 'btn-done';
        } elseif ($has_pending) {
            $next_status = 'processing';
            $btn_label   = '🔥 Start Cooking';
            $btn_class   = 'btn-start';
        } else {
            $next_status = 'ready';
            $btn_label   = '✅ Mark as Ready';
            $btn_class   = 'btn-done';
        }

        $items_data = [];
        foreach ($items as $item) {
            $items_data[] = [
                'name'          => $item->item_name,
                'qty'           => intval($item->quantity),
                'item_type'     => $item->item_type,
                'item_status'   => $item->item_status,
                'variant'       => $item->variants_selected ?? '',
                'category_name' => $item->category_name,
            ];
        }

       
        $order_timestamp = strtotime($order->created_at);
        $current_local_timestamp = $local_now->getTimestamp();
        $time_diff_text = human_time_diff($order_timestamp, $current_local_timestamp) . ' ago';

        $formatted[] = [
            'id'          => $order->id,
            'table_name'  => esc_html($order->table_name),
            'raw_status'  => $order->raw_status,
            'time_ago'    => $time_diff_text, 
            'next_status' => $next_status,
            'btn_label'   => $btn_label,
            'btn_class'   => $btn_class,
            'items'       => $items_data,
        ];
    }

    wp_send_json_success([
        'stats' => [
            'total'       => (int)($stats_raw->total       ?? 0),
            'confirmed'   => (int)($stats_raw->confirmed   ?? 0),
            'table_order' => (int)($stats_raw->table_order ?? 0),
            'take_away'   => (int)($stats_raw->take_away   ?? 0),
            'complete'    => (int)($stats_raw->complete    ?? 0),
            'cancel'      => (int)($stats_raw->cancel      ?? 0),
        ],
        'orders' => $formatted
    ]);
}


add_action('wp_ajax_update_qr_order_status', 'handle_kitchen_status_update');
function handle_kitchen_status_update() {
    check_ajax_referer('qr_order_nonce', 'security');
    global $wpdb;

    $order_id     = intval($_POST['order_id']);
    $status       = sanitize_text_field($_POST['status']);
    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $items_table  = $wpdb->prefix . 'qrrs_order_items';

    $wpdb->update($orders_table, ['order_status' => $status], ['id' => $order_id]);

    if ($status === 'processing') {
        $wpdb->query($wpdb->prepare(
            "UPDATE $items_table SET item_status = 'processing'
             WHERE order_id = %d AND item_status = 'pending'",
            $order_id
        ));
    } elseif ($status === 'ready') {
        $wpdb->query($wpdb->prepare(
            "UPDATE $items_table SET item_status = 'ready'
             WHERE order_id = %d AND item_status IN ('pending', 'processing')",
            $order_id
        ));
    }

    wp_send_json_success('updated');
}