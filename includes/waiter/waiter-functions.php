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

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT item_id as id, item_name as name, price, quantity as qty, variants_selected
         FROM {$wpdb->prefix}qrrs_order_items
         WHERE order_id = %d AND item_type != 'additional'",
        $order_id
    ));

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

    /**
     * ✨ FIX: অর্ডার তৈরি করার সময় যেন সম্পূর্ণ সঠিক লোকাল টাইমজোন স্ট্যাম্প পায়
     */
    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);
    $local_mysql_time = $local_now->format('Y-m-d H:i:s');

    if ($order_mode === 'new') {

        if (!$table_name || !$restaurant_id) { wp_send_json_error('Missing info.'); return; }

        /**
         * ✨ ULTIMATE FIX: MySQL বা সার্ভারের নিজস্ব টাইমজোনকে বাইপাস করে
         * সরাসরি আপনার লোকাল ঘড়ির/ডিভাইসের (যেমন: ঢাকা/বাংলাদেশ) কারেন্ট টাইম তৈরি করা।
         */
        $wp_timezone     = wp_timezone(); // ওয়ার্ডপ্রেস সেটিংসের জোন (যেমন: Asia/Dhaka)
        $local_datetime  = new DateTime('now', $wp_timezone);
        $exact_local_time = $local_datetime->format('Y-m-d H:i:s'); // এটা আউটপুট দিবে আপনার লোকাল টাইম (সকাল ০৬:১৯:০৪)

        $inserted = $wpdb->insert($wpdb->prefix.'qrrs_orders', [
            'restaurant_id'  => $restaurant_id,
            'table_name'     => $table_name,
            'total_amount'   => $subtotal,
            'tax_amount'     => $tax_amount,
            'service_charge' => $service_charge,
            'grand_total'    => $grand_total,
            'order_status'   => 'pending',
            'payment_status' => 'unpaid',
            'created_at'     => $exact_local_time, // 👈 এখানে $exact_local_time ভ্যারিয়েবলটি পাস করা হলো
            'waiter_id'      => get_current_user_id(),
        ]);
        if ($inserted === false) { wp_send_json_error('DB error: '.$wpdb->last_error); return; }
        $order_id = $wpdb->insert_id;

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

    } elseif ($order_mode === 'edit' && $order_id > 0) {

        // প্রথমে frontend থেকে আসা নতুন values দিয়ে update করো
        $wpdb->update($wpdb->prefix.'qrrs_orders', [
            'total_amount'   => $subtotal,
            'tax_amount'     => $tax_amount,
            'service_charge' => $service_charge,
            'grand_total'    => $grand_total,
        ], ['id' => $order_id]);

        // শুধু original items মুছব
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

        // Items থেকে নতুন subtotal recalculate করো (additional সহ)
        $new_subtotal = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(price * quantity) FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d",
            $order_id
        )));
        // DB থেকে tax ও sc নাও (এইমাত্র frontend থেকে save হয়েছে)
        $order_charges = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_amount, service_charge FROM {$wpdb->prefix}qrrs_orders WHERE id = %d",
            $order_id
        ));
        $new_grand = $new_subtotal + (float)$order_charges->tax_amount + (float)$order_charges->service_charge;
        $wpdb->update($wpdb->prefix.'qrrs_orders',
            ['total_amount' => $new_subtotal, 'grand_total' => $new_grand],
            ['id' => $order_id]
        );

    } elseif ($order_mode === 'add' && $order_id > 0) {

        // Additional items insert
        foreach ($items as $item) {
            $item_id  = intval($item['id']);
            $qty      = intval($item['qty']);
            $variants = sanitize_text_field($item['variants_selected'] ?? '');

            // একই additional item আগে থেকে থাকলে qty বাড়াও
            $existing_item = $wpdb->get_row($wpdb->prepare(
                "SELECT id, quantity FROM {$wpdb->prefix}qrrs_order_items
                 WHERE order_id = %d AND item_id = %d AND item_type = 'additional' AND variants_selected = %s",
                $order_id, $item_id, $variants
            ));

            if ($existing_item) {
                $wpdb->update(
                    $wpdb->prefix.'qrrs_order_items',
                    ['quantity' => $existing_item->quantity + $qty],
                    ['id' => $existing_item->id]
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
                    'item_type'         => 'additional',
                    'restaurant_id'     => $restaurant_id,
                ]);
            }
        }

        // সব items মিলিয়ে নতুন subtotal বের করো
        $new_subtotal = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(price * quantity) FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d",
            $order_id
        )));

        // DB থেকে আগের tax ও sc আনো
        $order_charges = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_amount, service_charge FROM {$wpdb->prefix}qrrs_orders WHERE id = %d",
            $order_id
        ));

        // পুরনো tax/sc এর সাথে নতুন items এর tax/sc যোগ করো
        $new_tax   = (float)$order_charges->tax_amount   + $tax_amount;   
        $new_sc    = (float)$order_charges->service_charge + $service_charge;
        $new_grand = $new_subtotal + $new_tax + $new_sc;

        $wpdb->update(
            $wpdb->prefix.'qrrs_orders',
            [
                'total_amount'   => $new_subtotal,
                'tax_amount'     => $new_tax,
                'service_charge' => $new_sc,
                'grand_total'    => $new_grand,
            ],
            ['id' => $order_id],
            ['%f', '%f', '%f', '%f'],
            ['%d']
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


// ৬. Notification Polling — waiter dashboard থেকে call হয়
add_action('wp_ajax_qrrs_waiter_poll', 'qrrs_waiter_poll');
function qrrs_waiter_poll() {
    global $wpdb;
    $res_id    = intval($_POST['res_id']    ?? 0);
    $waiter_id = intval($_POST['waiter_id'] ?? 0);
    if (!$res_id) { wp_send_json_error('Missing res_id'); return; }

    /**
     * ✨ FIX: CURDATE() এর পরিবর্তে ডাইনামিক লোকাল টাইমজোন বাউন্ড ব্যবহার করা
     * এর ফলে সার্ভারের টাইম জোনের কারণে সকাল ৬টা পর্যন্ত ডেটা আটকে থাকবে না।
     */
    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);
    $local_start = $local_now->format('Y-m-d 00:00:00');
    $local_end   = $local_now->format('Y-m-d 23:59:59');

    $ready_count = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_orders
         WHERE restaurant_id = %d AND order_status = 'ready'
           AND waiter_id = %d AND created_at BETWEEN %s AND %s",
        $res_id, $waiter_id, $local_start, $local_end
    )));

    $qr_count = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_orders
         WHERE restaurant_id = %d AND waiter_id = 0
           AND order_status NOT IN ('completed','cancelled','billing')
           AND created_at BETWEEN %s AND %s",
        $res_id, $local_start, $local_end
    )));

    wp_send_json_success([
        'ready_count' => $ready_count,
        'qr_count'    => $qr_count,
    ]);
}


// ৭. Add mode এ existing সব items fetch করা (cart preview এর জন্য)
add_action('wp_ajax_qrrs_get_all_order_items', 'qrrs_get_all_order_items');
function qrrs_get_all_order_items() {
    global $wpdb;
    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) { wp_send_json_error('Missing order ID'); return; }

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT item_name as name, price, quantity as qty, item_type, variants_selected
         FROM {$wpdb->prefix}qrrs_order_items
         WHERE order_id = %d ORDER BY id ASC",
        $order_id
    ));
    wp_send_json_success($items ?: []);
}


// ৮. QR অর্ডারের মালিকানা দাবি করা (Claiming Ownership)
add_action('wp_ajax_qrrs_claim_qr_order', 'handle_qrrs_claim_qr_order');
function handle_qrrs_claim_qr_order() {
    check_ajax_referer('qrrs_nonce_action', 'security');

    if (!isset($_POST['order_id'])) {
        wp_send_json_error('Invalid Order ID');
        return;
    }

    global $wpdb;
    $order_id  = intval($_POST['order_id']);
    $waiter_id = get_current_user_id();

    $updated = $wpdb->update(
        $wpdb->prefix . 'qrrs_orders',
        array('waiter_id' => $waiter_id),
        array(
            'id' => $order_id, 
            'waiter_id' => 0 
        )
    );

    if ($updated !== false) {
        wp_send_json_success('Ownership updated.');
    } else {
        wp_send_json_error('Database update failed.');
    }
}