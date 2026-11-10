<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function qrrs_get_restaurant_kitchen_settings( $restaurant_id ) {
    global $wpdb;

    $restaurant = $wpdb->get_row( $wpdb->prepare(
        "SELECT kitchen_mode, kitchen_capacity FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d",
        $restaurant_id
    ) );

    return [
        'mode'     => ( $restaurant && $restaurant->kitchen_mode === 'ready_food' ) ? 'ready_food' : 'active_kitchen',
        'capacity' => max( 1, intval( $restaurant->kitchen_capacity ?? 1 ) ),
    ];
}

function qrrs_current_user_can_operate_restaurant( $restaurant_id ) {
    if ( current_user_can( 'administrator' ) ) {
        return true;
    }

    global $wpdb;
    $role = $wpdb->get_var( $wpdb->prepare(
        "SELECT staff_role FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d AND restaurant_id = %d AND status = 'active' LIMIT 1",
        get_current_user_id(),
        $restaurant_id
    ) );

    return in_array( $role, [ 'qr_manager', 'qr_waiter' ], true );
}

function qrrs_current_user_can_view_delivery_customer( $restaurant_id ) {
    if ( current_user_can( 'administrator' ) ) {
        return true;
    }

    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d AND restaurant_id = %d AND staff_role = 'qr_manager' AND status = 'active' LIMIT 1",
        get_current_user_id(),
        $restaurant_id
    ) );
}

function qrrs_current_user_can_access_kitchen( $restaurant_id ) {
    if ( current_user_can( 'administrator' ) ) {
        return true;
    }

    global $wpdb;
    $role = $wpdb->get_var( $wpdb->prepare(
        "SELECT staff_role FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d AND restaurant_id = %d AND status = 'active' LIMIT 1",
        get_current_user_id(),
        $restaurant_id
    ) );

    return in_array( $role, [ 'qr_kitchen', 'qr_manager' ], true );
}


// ============================================================
// FETCH ORDERS
// ============================================================

add_action('wp_ajax_fetch_all_orders_dashboard', 'handle_fetch_all_orders_dashboard');
add_action('wp_ajax_nopriv_fetch_all_orders_dashboard', 'handle_fetch_all_orders_dashboard');

function handle_fetch_all_orders_dashboard() {

    // --------------------------------------------------------
    // NONCE VERIFICATION
    // Frontend sends: security: qr_nonce
    // Nonce action: qr_order_nonce
    // --------------------------------------------------------
    if ( ! check_ajax_referer( 'qr_order_nonce', 'security', false ) ) {
        wp_send_json_error( 'Security check failed. Please refresh the page and try again.' );
    }

    // --------------------------------------------------------
    // LOGIN CHECK
    // --------------------------------------------------------
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Please login again' );
    }

    global $wpdb;

    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $items_table  = $wpdb->prefix . 'qrrs_order_items';

    // --------------------------------------------------------
    // RESTAURANT ID
    // --------------------------------------------------------
    $restaurant_id = isset($_POST['restaurant_id'])
        ? intval($_POST['restaurant_id'])
        : 0;

    if ( ! $restaurant_id ) {
        wp_send_json_error('No restaurant ID');
    }
    if ( ! qrrs_current_user_can_operate_restaurant( $restaurant_id ) ) {
        wp_send_json_error( 'You do not have access to this restaurant.' );
    }

    $kitchen_settings = qrrs_get_restaurant_kitchen_settings( $restaurant_id );
    $can_view_delivery_customer = qrrs_current_user_can_view_delivery_customer( $restaurant_id );

    // --------------------------------------------------------
    // WORDPRESS TIMEZONE
    // --------------------------------------------------------
    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);

    $today_start = $local_now->format('Y-m-d 00:00:00');
    $today_end   = $local_now->format('Y-m-d 23:59:59');

    // --------------------------------------------------------
    // TODAY'S ORDER STATISTICS
    // --------------------------------------------------------
    $stats = $wpdb->get_row(
        $wpdb->prepare(
            "
            SELECT 
                COUNT(id) as total,
                SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN order_status = 'awaiting_verification' THEN 1 ELSE 0 END) as awaiting_verification,
                SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as preparing,
                SUM(CASE WHEN order_status = 'ready' THEN 1 ELSE 0 END) as served,
                SUM(CASE WHEN order_status IN ('billing', 'settle_bill') THEN 1 ELSE 0 END) as settling,
                SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            FROM $orders_table 
            WHERE restaurant_id = %d 
            AND created_at BETWEEN %s AND %s
            ",
            $restaurant_id,
            $today_start,
            $today_end
        )
    );

    // --------------------------------------------------------
    // ACTIVE ORDERS
    // --------------------------------------------------------
    $orders = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT 
                id,
                table_name,
                created_at,
                order_status,
                order_source,
                verification_status,
                total_amount,
                tax_amount,
                service_charge,
                grand_total
            FROM $orders_table
            WHERE restaurant_id = %d 
            AND created_at BETWEEN %s AND %s
            AND order_status NOT IN ('completed','cancelled')
            ORDER BY id DESC
            ",
            $restaurant_id,
            $today_start,
            $today_end
        )
    );

    $data = [];

    // --------------------------------------------------------
    // PROCESS EACH ORDER
    // --------------------------------------------------------
    foreach ($orders as $order) {

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT 
                    item_name,
                    quantity,
                    price,
                    item_type,
                    variants_selected
                FROM $items_table
                WHERE order_id = %d
                ",
                $order->id
            )
        );

        $items_data = [];
        $calculated_subtotal = 0;

        foreach ($items as $item) {

            $line_total = (float) ($item->price * $item->quantity);

            $calculated_subtotal += $line_total;

            $items_data[] = [
                'name'        => $item->item_name,
                'qty'         => $item->quantity,
                'price'       => (float) $item->price,
                'line_total'  => $line_total,
                'item_type'   => $item->item_type,
                'variant_name'=> $item->variants_selected
            ];
        }

        // ----------------------------------------------------
        // ORDER TIME
        // ----------------------------------------------------
        $wp_timezone = wp_timezone();

$order_datetime = DateTime::createFromFormat(
    'Y-m-d H:i:s',
    $order->created_at,
    $wp_timezone
);

if ( $order_datetime ) {

    // Display exact order placement time
    $placement_time = $order_datetime->format('h:i A');

    // Current time in the SAME WordPress timezone
    $now_datetime = new DateTime('now', $wp_timezone);

    // Calculate difference using the same timezone
    $time_diff_seconds = $now_datetime->getTimestamp() - $order_datetime->getTimestamp();

    if ( $time_diff_seconds < 0 ) {
        $time_diff_seconds = 0;
    }

    // Human readable "X minutes ago"
    if ( $time_diff_seconds < 60 ) {

        $time_diff_text = 'just now';

    } elseif ( $time_diff_seconds < 3600 ) {

        $minutes = floor($time_diff_seconds / 60);
        $time_diff_text = $minutes . ' minute' . ($minutes != 1 ? 's' : '') . ' ago';

    } elseif ( $time_diff_seconds < 86400 ) {

        $hours = floor($time_diff_seconds / 3600);
        $time_diff_text = $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';

    } else {

        $days = floor($time_diff_seconds / 86400);
        $time_diff_text = $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
    }

} else {

    // Fallback if database date cannot be parsed
    $placement_time = 'Unknown';
    $time_diff_text = 'Unknown';
}

        // ----------------------------------------------------
        // ORDER DATA
        // ----------------------------------------------------
        $data[] = [
            'id'             => $order->id,
            'table_name'     => $order->table_name,
            'status'         => $order->order_status,
            'order_source'   => $order->order_source,
            'subtotal'       => $calculated_subtotal,
            'vat_amount'     => (float) $order->tax_amount,
            'service_charge' => (float) $order->service_charge,
            'placement_time' => $placement_time,
            'time_ago'       => $time_diff_text,
            'items'          => $items_data
        ];

        if ( $can_view_delivery_customer && $order->table_name === 'Delivery' ) {
            $customer = $wpdb->get_row( $wpdb->prepare(
                "SELECT customer_name, customer_phone, delivery_address, delivery_note FROM {$wpdb->prefix}qrrs_order_customers WHERE order_id = %d",
                $order->id
            ) );

            if ( $customer ) {
                $data[ count( $data ) - 1 ]['delivery_customer'] = [
                    'name'    => $customer->customer_name,
                    'phone'   => $customer->customer_phone,
                    'address' => $customer->delivery_address,
                    'note'    => $customer->delivery_note,
                ];
            }
        }
    }

    // --------------------------------------------------------
    // RESPONSE
    // --------------------------------------------------------
    wp_send_json_success([
        'orders' => $data,
        'stats'  => $stats,
        'kitchen_mode' => $kitchen_settings['mode'],
        'can_view_delivery_customer' => $can_view_delivery_customer,
    ]);
}

add_action( 'wp_ajax_qrrs_review_qr_order', 'qrrs_review_qr_order' );
function qrrs_review_qr_order() {
    if ( ! check_ajax_referer( 'qr_order_nonce', 'security', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Please login again.' );
    }

    global $wpdb;
    $order_id = absint( $_POST['order_id'] ?? 0 );
    $decision = sanitize_key( $_POST['decision'] ?? '' );
    if ( ! $order_id || ! in_array( $decision, [ 'approve', 'cancel' ], true ) ) {
        wp_send_json_error( 'Invalid review request.' );
    }

    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $order = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, restaurant_id, order_source, order_status FROM $orders_table WHERE id = %d",
        $order_id
    ) );
    if ( ! $order || $order->order_source !== 'qr' || $order->order_status !== 'awaiting_verification' ) {
        wp_send_json_error( 'This QR order is no longer awaiting review.' );
    }
    if ( ! qrrs_current_user_can_operate_restaurant( $order->restaurant_id ) ) {
        wp_send_json_error( 'You do not have permission to review this order.' );
    }

    $kitchen_settings = qrrs_get_restaurant_kitchen_settings( $order->restaurant_id );
    $approved_status = $kitchen_settings['mode'] === 'ready_food' ? 'ready' : 'pending';
    $new_status = $decision === 'approve' ? $approved_status : 'cancelled';
    $verification_status = $decision === 'approve' ? 'approved' : 'rejected';

    $updated = $wpdb->update(
        $orders_table,
        [
            'order_status' => $new_status,
            'verification_status' => $verification_status,
            'verified_by' => get_current_user_id(),
            'verified_at' => current_time( 'mysql' ),
            'ready_at' => $new_status === 'ready' ? current_time( 'mysql' ) : null,
        ],
        [ 'id' => $order_id ],
        [ '%s', '%s', '%d', '%s', '%s' ],
        [ '%d' ]
    );

    if ( false === $updated ) {
        wp_send_json_error( 'Could not update the QR order.' );
    }

    if ( $new_status === 'ready' ) {
        $wpdb->update(
            $wpdb->prefix . 'qrrs_order_items',
            [ 'item_status' => 'ready' ],
            [ 'order_id' => $order_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    wp_send_json_success( [ 'status' => $new_status ] );
}


// ============================================================
// UPDATE STATUS
// ============================================================

add_action(
    'wp_ajax_update_dashboard_order_status',
    'qrrs_final_order_status_update'
);

function qrrs_final_order_status_update() {

    // --------------------------------------------------------
    // NONCE VERIFICATION
    // --------------------------------------------------------
    if ( ! check_ajax_referer( 'qr_order_nonce', 'security', false ) ) {
        wp_send_json_error(
            'Security check failed. Please refresh the page and try again.'
        );
        exit;
    }

    // --------------------------------------------------------
    // LOGIN CHECK
    // --------------------------------------------------------
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Unauthorized: Not logged in' );
        exit;
    }

    global $wpdb;

    $order_table = $wpdb->prefix . 'qrrs_orders';

    // --------------------------------------------------------
    // REQUEST DATA
    // --------------------------------------------------------
    $order_id = isset($_POST['order_id'])
        ? intval($_POST['order_id'])
        : 0;

    $status = isset($_POST['status'])
        ? sanitize_text_field($_POST['status'])
        : '';

    // --------------------------------------------------------
    // VALIDATION
    // --------------------------------------------------------
    if ( ! $order_id || ! $status ) {
        wp_send_json_error('No Data');
        exit;
    }

    $order = $wpdb->get_row( $wpdb->prepare( "SELECT restaurant_id, order_status FROM $order_table WHERE id = %d", $order_id ) );
    if ( ! $order || ! qrrs_current_user_can_operate_restaurant( $order->restaurant_id ) ) {
        wp_send_json_error( 'You do not have permission to update this order.' );
    }
    if ( $order->order_status === 'awaiting_verification' ) {
        wp_send_json_error( 'Approve or cancel QR orders before changing their status.' );
    }

    // --------------------------------------------------------
    // UPDATE DATA
    // --------------------------------------------------------
    $update_data = [
        'order_status' => $status
    ];

    $update_format = [
        '%s'
    ];

    // --------------------------------------------------------
    // PAID / COMPLETED
    // Existing logic preserved
    // --------------------------------------------------------
    if ($status === 'paid' || $status === 'completed') {

        $update_data['payment_status'] = 'paid';

        $update_data['order_status'] = 'completed';

        $update_format[] = '%s';
    }

    // --------------------------------------------------------
    // DATABASE UPDATE
    // --------------------------------------------------------
    $updated = $wpdb->update(
        $order_table,
        $update_data,
        [
            'id' => $order_id
        ],
        $update_format,
        [
            '%d'
        ]
    );

    // --------------------------------------------------------
    // RESPONSE
    // --------------------------------------------------------
    if ($updated !== false) {
        do_action( 'qrrs_order_status_changed', $order_id, $status );

        $items_table = $wpdb->prefix . 'qrrs_order_items';
        if ( $status === 'processing' ) {
            $wpdb->query( $wpdb->prepare( "UPDATE $items_table SET item_status = 'processing' WHERE order_id = %d AND item_status = 'pending'", $order_id ) );
        } elseif ( $status === 'ready' ) {
            $wpdb->query( $wpdb->prepare( "UPDATE $items_table SET item_status = 'ready' WHERE order_id = %d AND item_status IN ('pending', 'processing')", $order_id ) );
        }

        wp_send_json_success('Updated');

    } else {

        wp_send_json_error(
            'DB Error: ' . $wpdb->last_error
        );
    }

    exit;
}