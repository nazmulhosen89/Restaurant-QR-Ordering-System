<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Public online order page (token based, no login).
 *  - Page: a WordPress page with the shortcode [qrrs_online_order], opened as /online-order/?t=TOKEN
 *  - Checks: token, online ON, open hours (Asia/Dhaka), service ON, delivery area + minimum order,
 *    customer name / mobile / address (delivery). Totals are calculated on the server.
 *  - The order is then placed through the normal order handler, so it reaches staff for
 *    verification like any QR order. Payment: cash on delivery.
 */

/** Restaurant for a public token, only if online ordering is on. */
function qrrs_public_restaurant_by_token( $token ) {
    global $wpdb;
    $token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
    if ( strlen( $token ) < 16 ) {
        return null;
    }
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT id, restaurant_name FROM {$wpdb->prefix}qrrs_restaurants
         WHERE online_token = %s AND online_enabled = 1 AND status = 'active'",
        $token
    ) );
}

/** Open now? Uses the restaurant's business hours in Asia/Dhaka time. */
function qrrs_online_open_now( $restaurant_id ) {
    global $wpdb;
    $hours = [];
    foreach ( $wpdb->get_results( $wpdb->prepare(
        "SELECT day_of_week, open_time, close_time, is_closed FROM {$wpdb->prefix}qrrs_business_hours WHERE restaurant_id = %d",
        intval( $restaurant_id )
    ) ) as $h ) {
        $hours[ intval( $h->day_of_week ) ] = $h;
    }

    $now = new DateTime( 'now', wp_timezone() );
    $dow = intval( $now->format( 'w' ) );
    $t   = $now->format( 'H:i:s' );
    $names = qrrs_online_day_names();

    $today = $hours[ $dow ] ?? null;
    if ( $today && ! $today->is_closed && $today->open_time && $today->close_time ) {
        $o = $today->open_time;
        $c = $today->close_time;
        // Overnight hours (e.g. 18:00 to 02:00) cover the late night too
        $inside = ( $c > $o ) ? ( $t >= $o && $t < $c ) : ( $t >= $o || $t < $c );
        if ( $inside ) {
            return [ 'open' => true, 'next' => null ];
        }
        if ( $t < $o ) {
            return [ 'open' => false, 'next' => 'today at ' . substr( $o, 0, 5 ) ];
        }
    }
    for ( $i = 1; $i <= 7; $i++ ) {
        $d = ( $dow + $i ) % 7;
        $r = $hours[ $d ] ?? null;
        if ( $r && ! $r->is_closed && $r->open_time ) {
            $label = ( 1 === $i ) ? 'tomorrow' : $names[ $d ];
            return [ 'open' => false, 'next' => $label . ' at ' . substr( $r->open_time, 0, 5 ) ];
        }
    }
    return [ 'open' => false, 'next' => null ];
}

/** Public: menu, services, areas and open status for the order page. */
function qrrs_ajax_public_menu() {
    global $wpdb;
    $r = qrrs_public_restaurant_by_token( $_REQUEST['token'] ?? '' );
    if ( ! $r ) {
        wp_send_json_error( 'This ordering link is not active.' );
    }
    $rid  = intval( $r->id );
    $open = qrrs_online_open_now( $rid );
    $svc  = qrrs_online_services_for( $rid );
    $online_services = array_values( array_filter( [
        $svc['take_out'] ? 'take_out' : null,
        $svc['delivery'] ? 'delivery' : null,
    ] ) );

    $room = null;
    if ( ! empty( $_REQUEST['rt'] ) ) {
        $room = qrrs_room_for_token( $rid, $_REQUEST['rt'] );
        if ( ! $room ) wp_send_json_error( 'This room link is not active.' );
        if ( empty( $svc['room_service'] ) ) wp_send_json_error( 'Room service is not available right now.' );
        $online_services = [ 'room_service' ];
    }

    $cats = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, category_name FROM {$wpdb->prefix}qrrs_categories WHERE restaurant_id = %d ORDER BY category_name ASC",
        $rid
    ) );
    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, category_id, item_name, item_image, description, price, is_tax_free FROM {$wpdb->prefix}qrrs_items
         WHERE restaurant_id = %d AND is_available = 1 ORDER BY item_name ASC",
        $rid
    ) );
    $custom_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT menu_item_id FROM {$wpdb->prefix}qrrs_menu_option_groups WHERE restaurant_id = %d AND status = 'active'",
        $rid
    ) ) );
    $areas = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, area_name, charge, min_order FROM {$wpdb->prefix}qrrs_delivery_areas
         WHERE restaurant_id = %d AND status = 'active' ORDER BY area_name ASC",
        $rid
    ) );

    wp_send_json_success( [
        'restaurant' => $r->restaurant_name,
        'otp'        => qrrs_otp_mode( $rid ),
        'room'       => $room ? [ 'number' => $room->room_number ] : null,
        'rid'        => $rid,
        'open'       => $open['open'],
        'next'       => $open['next'],
        'services'   => $online_services,
        'categories' => $cats,
        'items'      => array_map( fn( $i ) => [
            'id' => (int) $i->id, 'cat' => (int) $i->category_id, 'name' => $i->item_name,
            'image' => (string) $i->item_image, 'desc' => (string) $i->description,
            'price' => (float) $i->price, 'tax_free' => (int) $i->is_tax_free,
            'custom' => in_array( (int) $i->id, $custom_ids, true ),
        ], $items ),
        'areas'      => array_map( fn( $a ) => [
            'id' => (int) $a->id, 'name' => $a->area_name, 'charge' => (float) $a->charge, 'min' => (float) $a->min_order,
        ], $areas ),
    ] );
}
add_action( 'wp_ajax_qrrs_public_menu', 'qrrs_ajax_public_menu' );
add_action( 'wp_ajax_nopriv_qrrs_public_menu', 'qrrs_ajax_public_menu' );

/** Public: place an online order. Every amount is calculated here. */
function qrrs_ajax_public_order() {
    $r = qrrs_public_restaurant_by_token( $_POST['token'] ?? '' );
    if ( ! $r ) {
        wp_send_json_error( 'This ordering link is not active.' );
    }
    $rid  = intval( $r->id );
    $open = qrrs_online_open_now( $rid );
    if ( ! $open['open'] ) {
        wp_send_json_error( 'We are closed right now' . ( $open['next'] ? '. We open ' . $open['next'] . '.' : '.' ) );
    }

    $room = null;
    if ( ! empty( $_POST['rt'] ) ) {
        $room = qrrs_room_for_token( $rid, $_POST['rt'] );
        if ( ! $room ) wp_send_json_error( 'This room link is not active.' );
    }
    $type = $room ? 'room_service' : sanitize_key( $_POST['order_type'] ?? '' );
    $svc  = qrrs_online_services_for( $rid );
    if ( ! in_array( $type, [ 'take_out', 'delivery', 'room_service' ], true ) || empty( $svc[ $type ] ) ) {
        wp_send_json_error( 'This service is not available.' );
    }

    $name  = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
    $phone = preg_replace( '/[\s-]/', '', sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) ) );
    $addr  = sanitize_textarea_field( wp_unslash( $_POST['delivery_address'] ?? '' ) );
    $note  = sanitize_textarea_field( wp_unslash( $_POST['delivery_note'] ?? '' ) );

    if ( mb_strlen( trim( $name ) ) < 2 || mb_strlen( $name ) > 100 ) {
        wp_send_json_error( 'Please enter your name.' );
    }
    if ( ! preg_match( '/^(?:\+?880|0)?1[3-9]\d{8}$/', $phone ) ) {
        wp_send_json_error( 'Please enter a valid mobile number.' );
    }

    if ( ! qrrs_otp_check_and_consume( intval( $r->id ), qrrs_normalize_phone( $phone ) ) ) {
        wp_send_json_error( 'Please verify your mobile number with the code first.' );
    }
    global $wpdb;
    $delivery_charge = 0.0;
    $min_order       = 0.0;
    if ( 'delivery' === $type ) {
        if ( mb_strlen( trim( $addr ) ) < 5 ) {
            wp_send_json_error( 'Please enter your delivery address.' );
        }
        $area = $wpdb->get_row( $wpdb->prepare(
            "SELECT charge, min_order FROM {$wpdb->prefix}qrrs_delivery_areas WHERE id = %d AND restaurant_id = %d AND status = 'active'",
            intval( $_POST['area_id'] ?? 0 ), $rid
        ) );
        if ( ! $area ) {
            wp_send_json_error( 'Please choose your delivery area.' );
        }
        $delivery_charge = (float) $area->charge;
        $min_order       = (float) $area->min_order;
    }

    $cart = json_decode( wp_unslash( $_POST['items'] ?? '' ), true );
    if ( ! is_array( $cart ) || empty( $cart ) ) {
        wp_send_json_error( 'Your cart is empty.' );
    }
    $checked = qrrs_validate_order_items( $rid, $cart );
    if ( is_string( $checked ) ) {
        wp_send_json_error( $checked );
    }
    if ( 'delivery' === $type && $checked['subtotal'] < $min_order ) {
        wp_send_json_error( 'Minimum order for this area is ' . number_format( $min_order, 2 ) . '৳.' );
    }

    $charges = qrrs_compute_charges( $rid, $checked['subtotal'], $checked['taxable'], $type, $delivery_charge );

    // Remember the customer for the CRM
    qrrs_crm_upsert( $rid, $name, $phone, $addr );

    // Hand over to the normal order handler with server-calculated values only.
    $GLOBALS['qrrs_public_delivery_charge'] = $delivery_charge;
    $_POST = [
        'security'         => wp_create_nonce( 'qr_order_nonce' ),
        'restaurant_id'    => $rid,
        'table_id'         => 0,
        'order_type'       => $type,
        'order_source'     => 'qr',
        'items'            => wp_json_encode( $cart ),
        'subtotal'         => $checked['subtotal'],
        'tax_amount'       => $charges['vat'],
        'service_charge'   => $charges['sc'],
        'grand_total'      => $charges['grand'],
        'customer_name'    => $name,
        'customer_phone'   => $phone,
        'delivery_address' => $room ? 'Room ' . $room->room_number : $addr,
        'delivery_note'    => $note,
    ];
    add_filter( 'qrrs_order_placed_response', function ( $msg, $ctx ) {
        return [
            'message'    => $msg,
            'track_code' => $ctx['track_code'],
            'track_url'  => add_query_arg( 'code', $ctx['track_code'], home_url( '/track-order/' ) ),
        ];
    }, 10, 2 );
    handle_qr_order_placement(); // sends the JSON response and exits
}
add_action( 'wp_ajax_qrrs_public_order', 'qrrs_ajax_public_order' );
add_action( 'wp_ajax_nopriv_qrrs_public_order', 'qrrs_ajax_public_order' );

/** The page for the shortcode [qrrs_online_order]. */
function qrrs_public_order_shortcode() {
    $token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
    if ( ! qrrs_public_restaurant_by_token( $token ) ) {
        return '<div style="padding:30px; text-align:center; font-family:sans-serif;">This ordering link is not active.</div>';
    }
    $base = plugin_dir_url( __FILE__ ) . '../../assets/';
    wp_enqueue_style( 'qrrs-public-order', $base . 'css/public-order.css', [], '2.0' );
    wp_enqueue_script( 'qrrs-public-order', $base . 'js/public-order.js', [], '2.0', true );
    wp_localize_script( 'qrrs-public-order', 'QRRS_PUB', [
        'ajax'  => admin_url( 'admin-ajax.php' ),
        'token' => $token,
    ] );
    ob_start(); ?>
    <div class="qpo" id="qpo">
        <header class="qpo-hero">
            <div class="qpo-hero-inner">
                <div class="qpo-eyebrow">Online order</div>
                <h1 id="qpo-name">Loading menu...</h1>
                <div id="qpo-status"></div>
            </div>
        </header>
        <div class="qpo-wrap">
            <div class="qpo-bar">
                <div class="qpo-services" id="qpo-services"></div>
                <div class="qpo-chips" id="qpo-chips"></div>
            </div>
            <div class="qpo-grid" id="qpo-grid"></div>
        </div>
        <div class="qpo-cartbar" id="qpo-cartbar" hidden>
            <div><span id="qpo-cartcount">0</span> items</div>
            <strong id="qpo-carttotal">৳0</strong>
            <button type="button" id="qpo-view-cart">View cart &amp; checkout</button>
        </div>
        <div class="qpo-modal" id="qpo-modal" hidden>
            <div class="qpo-sheet" id="qpo-sheet" role="dialog" aria-modal="true"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'qrrs_online_order', 'qrrs_public_order_shortcode' );