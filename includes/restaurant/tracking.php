<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Customer order tracking.
 *  - Every online / QR order gets a 6-character code (shown after ordering).
 *  - Customer opens the tracking page, types the code AND the mobile number used for the order.
 *  - The page shows only the progress steps. No name, address or amounts.
 *  - Wrong attempts are limited per visitor.
 */

function qrrs_make_track_code( $order_table ) {
    global $wpdb;
    for ( $i = 0; $i < 10; $i++ ) {
        $code = strtoupper( wp_generate_password( 6, false, false ) );
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $order_table WHERE tracking_code = %s", $code ) );
        if ( ! $exists ) return $code;
    }
    return '';
}

/** Progress steps the customer sees. Delivery and dine-in/take-away differ at the end. */
function qrrs_track_steps( $is_delivery ) {
    return $is_delivery
        ? [ 'Received', 'Confirmed', 'Cooking', 'Ready', 'Out for delivery', 'Delivered' ]
        : [ 'Received', 'Confirmed', 'Cooking', 'Ready', 'Served' ];
}

/** Maps the internal order status to a step index. */
function qrrs_track_index( $status, $is_delivery ) {
    $map = [
        'awaiting_verification' => 0,
        'pending'               => 1,
        'processing'            => 2,
        'ready'                 => 3,
        'out_for_delivery'      => 4,
        'delivered'             => 5,
        'served'                => $is_delivery ? 5 : 4,
        'billing'               => $is_delivery ? 5 : 4,
        'settle_bill'           => $is_delivery ? 5 : 4,
        'completed'             => $is_delivery ? 5 : 4,
    ];
    return $map[ $status ] ?? 0;
}

function qrrs_ajax_track_order() {
    global $wpdb;
    $code  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) ) );
    $phone = qrrs_normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );

    // Limit wrong attempts per visitor (20 lookups per 15 minutes)
    $key = 'qrrs_track_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'x' ) );
    $tries = (int) get_transient( $key );
    if ( $tries >= 20 ) {
        wp_send_json_error( 'Too many attempts. Please try again after 15 minutes.' );
    }
    set_transient( $key, $tries + 1, 15 * MINUTE_IN_SECONDS );

    if ( 6 !== strlen( $code ) || ! preg_match( '/^01[3-9]\d{8}$/', $phone ) ) {
        wp_send_json_error( 'Enter the 6-character order code and the mobile number used for the order.' );
    }

    $o  = $wpdb->prefix . 'qrrs_orders';
    $oc = $wpdb->prefix . 'qrrs_order_customers';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT o.id, o.order_status, o.table_name, o.created_at
         FROM $o o JOIN $oc oc ON oc.order_id = o.id
         WHERE o.tracking_code = %s AND oc.customer_phone = %s
         ORDER BY o.id DESC LIMIT 1",
        $code, $phone
    ) );
    if ( ! $row ) {
        wp_send_json_error( 'No order found with this code and mobile number.' );
    }

    $is_delivery = ( 'Delivery' === $row->table_name );
    $steps       = qrrs_track_steps( $is_delivery );
    $cancelled   = 'cancelled' === $row->order_status;
    $done        = in_array( $row->order_status, [ 'completed' ], true );
    $index       = qrrs_track_index( $row->order_status, $is_delivery );

    wp_send_json_success( [
        'code'      => $code,
        'steps'     => $steps,
        'current'   => $index,
        'cancelled' => $cancelled,
        'done'      => $done,
        'placed'    => mysql2date( 'd M, H:i', $row->created_at ),
        'delivery'  => $is_delivery,
    ] );
}
add_action( 'wp_ajax_qrrs_track_order', 'qrrs_ajax_track_order' );
add_action( 'wp_ajax_nopriv_qrrs_track_order', 'qrrs_ajax_track_order' );

/** Page with the shortcode [qrrs_track_order]. Reads ?code= from the link. */
function qrrs_track_order_shortcode() {
    $base = plugin_dir_url( __FILE__ ) . '../../assets/';
    wp_enqueue_style( 'qrrs-public-order', $base . 'css/public-order.css', [], '2.0' );
    wp_enqueue_script( 'qrrs-track-order', $base . 'js/track-order.js', [], '1.0', true );
    wp_localize_script( 'qrrs-track-order', 'QRRS_TRACK', [
        'ajax' => admin_url( 'admin-ajax.php' ),
        'code' => isset( $_GET['code'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) ) : '',
    ] );
    ob_start(); ?>
    <div class="qpo" id="qtr">
        <!-- <header class="qpo-hero">
            <div class="qpo-hero-inner">
                <div class="qpo-eyebrow">Track your order</div>
                <h1>Where is my food?</h1>
            </div>
        </header> -->
        <div class="qpo-wrap" style="max-width:560px;">
            <div class="qpo-sec" style="background:#1f1f1f; border:1px solid #2c2c2c; border-radius:18px; padding:20px;">
                <div class="qpo-input-row" style="display:grid; gap:10px;">
                    <input id="qtr-code" class="qpo-input" placeholder="Order code (6 characters)" maxlength="6" style="text-transform:uppercase;">
                    <input id="qtr-phone" class="qpo-input" placeholder="Mobile number used for the order" inputmode="tel" maxlength="20">
                    <button type="button" id="qtr-go" class="qpo-submit">Track order</button>
                    <div id="qtr-err" class="qpo-err"></div>
                </div>
            </div>
            <div id="qtr-result" style="margin-top:18px;"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'qrrs_track_order', 'qrrs_track_order_shortcode' );