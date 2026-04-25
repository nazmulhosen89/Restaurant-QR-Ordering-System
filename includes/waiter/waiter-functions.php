<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ১. মেনু আইটেম + ক্যাটাগরি
add_action( 'wp_ajax_qrrs_get_waiter_menu',        'qrrs_get_waiter_menu' );
add_action( 'wp_ajax_nopriv_qrrs_get_waiter_menu', 'qrrs_get_waiter_menu' );
function qrrs_get_waiter_menu() {
    global $wpdb;
    $res_id  = isset($_POST['restaurant_id']) ? intval($_POST['restaurant_id']) : 1;
    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}qrrs_items");

    $image_col    = "''";
    foreach (['image_url','item_image','image','photo','thumbnail'] as $col)
        if (in_array($col, $columns)) { $image_col = 'i.'.$col; break; }

    $cat_id_col   = in_array('category_id',  $columns) ? 'i.category_id'  : '0';
    $tax_free_col = in_array('is_tax_free',  $columns) ? 'i.is_tax_free'  : '0';
    $name_col     = in_array('item_name',    $columns) ? 'i.item_name'    : 'i.name';
    $variants_col = "''";
    foreach (['variants','variants_json'] as $col)
        if (in_array($col, $columns)) { $variants_col = 'i.'.$col; break; }

    $avail_where = '';
    if (in_array('is_available', $columns))   $avail_where = 'AND i.is_available = 1';
    elseif (in_array('status', $columns))     $avail_where = "AND i.status = 'available'";

    $categories = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_categories WHERE restaurant_id = %d ORDER BY id ASC", $res_id
    ));
    $items = $wpdb->get_results($wpdb->prepare("
        SELECT i.id, {$name_col} AS item_name, i.price,
               {$image_col}    AS image_url,
               {$cat_id_col}   AS category_id,
               {$tax_free_col} AS is_tax_free,
               {$variants_col} AS variants
        FROM {$wpdb->prefix}qrrs_items i
        WHERE i.restaurant_id = %d {$avail_where}
        ORDER BY {$cat_id_col} ASC, {$name_col} ASC
    ", $res_id));

    if ($wpdb->last_error) { wp_send_json_error('DB Error: '.$wpdb->last_error); return; }
    wp_send_json_success(['categories' => $categories ?: [], 'items' => $items ?: []]);
}


// ২. Edit এর জন্য আগের অর্ডার cart format এ আনা
add_action('wp_ajax_qrrs_get_order_for_edit', 'qrrs_get_order_for_edit');
function qrrs_get_order_for_edit() {
    global $wpdb;
    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) { wp_send_json_error('Missing order ID'); return; }

    // শুধু original items (additional নয়)
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT item_id as id, item_name as name, price, quantity as qty, variants_selected
         FROM {$wpdb->prefix}qrrs_order_items
         WHERE order_id = %d AND item_type != 'additional'",
        $order_id
    ));

    // item_type column না থাকলে সব আনা
    if ($wpdb->last_error) {
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT item_id as id, item_name as name, price, quantity as qty, variants_selected
             FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d", $order_id
        ));
    }

    $cart_format = [];
    foreach ($items as $item) {
        $vars = !empty($item->variants_selected)
            ? array_map('trim', explode(',', $item->variants_selected))
            : [];
        $key = $item->id . (!empty($vars) ? '-'.implode('-', $vars) : '');
        $cart_format[] = [
            'key'      => $key,
            'id'       => intval($item->id),
            'name'     => $item->name,
            'price'    => floatval($item->price),
            'qty'      => intval($item->qty),
            'variants' => $vars,
            'tax_free' => 0,
        ];
    }
    wp_send_json_success($cart_format);
}


// ৩. অর্ডার সেভ / এডিট / অ্যাড
add_action('wp_ajax_qrrs_submit_waiter_order', 'qrrs_submit_waiter_order');
function qrrs_submit_waiter_order() {
    global $wpdb;

    $order_mode     = sanitize_text_field($_POST['order_mode']     ?? 'new');
    $order_id       = intval($_POST['order_id']                    ?? 0);
    $table_name     = sanitize_text_field($_POST['table_name']     ?? '');
    $restaurant_id  = intval($_POST['restaurant_id']               ?? 0);
    $subtotal       = floatval($_POST['subtotal']                  ?? 0);
    $tax_amount     = floatval($_POST['tax_amount']                ?? 0);
    $service_charge = floatval($_POST['service_charge']            ?? 0);
    $grand_total    = floatval($_POST['grand_total']               ?? 0);
    $items          = json_decode(wp_unslash($_POST['items'] ?? '[]'), true);

    if (empty($items) || !is_array($items)) { wp_send_json_error('Cart is empty!'); return; }

    if ($order_mode === 'new') {
        // নতুন অর্ডার — সরাসরি processing (kitchen এ যাবে)
        if (!$table_name || !$restaurant_id) { wp_send_json_error('Missing info.'); return; }

        $inserted = $wpdb->insert($wpdb->prefix.'qrrs_orders', [
            'restaurant_id'  => $restaurant_id,
            'table_name'     => $table_name,
            'total_amount'   => $subtotal,
            'tax_amount'     => $tax_amount,
            'service_charge' => $service_charge,
            'grand_total'    => $grand_total,
            'order_status'   => 'pending', // সরাসরি kitchen এ
            'payment_status' => 'unpaid',
            'created_at'     => current_time('mysql'),
            'waiter_id'      => get_current_user_id(),
        ]);
        if ($inserted === false) { wp_send_json_error('DB error: '.$wpdb->last_error); return; }
        $order_id = $wpdb->insert_id;

        // Items insert
        foreach ($items as $item) {
            $wpdb->insert($wpdb->prefix.'qrrs_order_items', [
                'order_id'          => $order_id,
                'item_id'           => intval($item['id']),
                'item_name'         => sanitize_text_field($item['name']),
                'price'             => floatval($item['price']),
                'quantity'          => intval($item['qty']),
                'variants_selected' => sanitize_text_field($item['variants_selected'] ?? ''),
                'item_status'       => 'pending',
                'item_type'         => 'original', // original flag
                'restaurant_id'     => $restaurant_id,
            ]);
        }

    } elseif ($order_mode === 'edit' && $order_id > 0) {
        // এডিট — শুধু original items মুছে নতুন করে ইনসার্ট
        $wpdb->update($wpdb->prefix.'qrrs_orders', [
            'total_amount'   => $subtotal,
            'tax_amount'     => $tax_amount,
            'service_charge' => $service_charge,
            'grand_total'    => $grand_total,
        ], ['id' => $order_id]);

        // item_type column থাকলে শুধু original মুছব
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d AND (item_type = 'original' OR item_type IS NULL OR item_type = '')",
            $order_id
        ));

        foreach ($items as $item) {
            $wpdb->insert($wpdb->prefix.'qrrs_order_items', [
                'order_id'          => $order_id,
                'item_id'           => intval($item['id']),
                'item_name'         => sanitize_text_field($item['name']),
                'price'             => floatval($item['price']),
                'quantity'          => intval($item['qty']),
                'variants_selected' => sanitize_text_field($item['variants_selected'] ?? ''),
                'item_status'       => 'pending',
                'item_type'         => 'original',
                'restaurant_id'     => $restaurant_id,
            ]);
        }

        // Grand total recalculate
        $new_total = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(price * quantity) FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d", $order_id
        )));
        $wpdb->update($wpdb->prefix.'qrrs_orders',
            ['total_amount' => $new_total, 'grand_total' => $new_total],
            ['id' => $order_id]
        );

    } elseif ($order_mode === 'add' && $order_id > 0) {
        // Additional items — আলাদা type দিয়ে ইনসার্ট
        foreach ($items as $item) {
            $item_id  = intval($item['id']);
            $qty      = intval($item['qty']);
            $variants = sanitize_text_field($item['variants_selected'] ?? '');

            // একই additional item আগে থেকে থাকলে qty বাড়াও
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, quantity FROM {$wpdb->prefix}qrrs_order_items
                 WHERE order_id = %d AND item_id = %d AND item_type = 'additional' AND variants_selected = %s",
                $order_id, $item_id, $variants
            ));

            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix.'qrrs_order_items',
                    ['quantity' => $existing->quantity + $qty],
                    ['id' => $existing->id]
                );
            } else {
                $wpdb->insert($wpdb->prefix.'qrrs_order_items', [
                    'order_id'          => $order_id,
                    'item_id'           => $item_id,
                    'item_name'         => sanitize_text_field($item['name']),
                    'price'             => floatval($item['price']),
                    'quantity'          => $qty,
                    'variants_selected' => $variants,
                    'item_status'       => 'pending',
                    'item_type'         => 'additional', // additional flag
                    'restaurant_id'     => $restaurant_id,
                ]);
            }
        }

        // Grand total আপডেট
        $new_total = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(price * quantity) FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d", $order_id
        )));
        $wpdb->update($wpdb->prefix.'qrrs_orders',
            ['total_amount' => $new_total, 'grand_total' => $new_total],
            ['id' => $order_id]
        );
    }

    wp_send_json_success(['order_id' => $order_id, 'message' => 'Success!']);
}


// ৪. অর্ডার স্ট্যাটাস আপডেট
add_action('wp_ajax_qrrs_update_order_status', 'handle_waiter_status_update');
function handle_waiter_status_update() {
    global $wpdb;

    $order_id = intval($_POST['order_id'] ?? 0);
    $status   = sanitize_text_field($_POST['status'] ?? '');
    if (!$order_id || !$status) { wp_send_json_error('Missing data.'); return; }

    $update = ['order_status' => $status];

    // billing এ গেলে payment_status আপডেট করা হবে না — billing কাউন্টার করবে
    // completed বা paid হলে payment done
    if ($status === 'paid' || $status === 'completed') {
        $update['payment_status'] = 'paid';
        $update['order_status']   = 'completed';
    }

    $updated = $wpdb->update($wpdb->prefix.'qrrs_orders', $update, ['id' => $order_id]);
    if ($updated !== false) wp_send_json_success('Status updated to '.$status);
    else wp_send_json_error('Update failed: '.$wpdb->last_error);
}


// ৫. item_type column exist না করলে automatically add করা (safe migration)
add_action('wp_loaded', 'qrrs_maybe_add_item_type_column');
function qrrs_maybe_add_item_type_column() {
    global $wpdb;
    $table   = $wpdb->prefix.'qrrs_order_items';
    $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");
    if (!in_array('item_type', $columns)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN item_type VARCHAR(20) DEFAULT 'original' AFTER item_status");
    }
}