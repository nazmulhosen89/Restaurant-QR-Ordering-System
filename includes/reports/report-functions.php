<?php
// includes/reports/report-functions.php

function get_qrrs_active_restaurant_name() {
    global $wpdb;

    if ( ! session_id() ) {
        session_start();
    }

    $user_id = get_current_user_id();
    $restaurant_id = 0;

    if ( current_user_can('administrator') ) {
        $restaurant_id = isset( $_SESSION['qrrs_active_res_id'] ) ? intval( $_SESSION['qrrs_active_res_id'] ) : 0;
    } else {
        $staff_table = $wpdb->prefix . 'qrrs_staff'; 
        
        $restaurant_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT restaurant_id FROM $staff_table WHERE user_id = %d AND status = 'active' LIMIT 1", 
            $user_id
        ) );
    }

    if ( $restaurant_id > 0 ) {
        $res_table = $wpdb->prefix . 'qrrs_restaurants';
        
        $restaurant_name = $wpdb->get_var( $wpdb->prepare(
            "SELECT restaurant_name FROM $res_table WHERE id = %d", 
            $restaurant_id
        ) );

        return $restaurant_name ? $restaurant_name : 'Unknown Restaurant';
    }

    return 'No Restaurant Assigned';
}


function get_qrrs_daily_stats($res_id) {
    global $wpdb;
    $today = current_time('Y-m-d');
    
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            SUM(grand_total) as sales, 
            COUNT(id) as orders 
         FROM {$wpdb->prefix}qrrs_orders 
         WHERE restaurant_id = %d AND DATE(created_at) = %s AND order_status != 'cancelled'", 
        $res_id, $today
    ));

    return $stats;
}


function get_qrrs_restaurant_currency($restaurant_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qrrs_restaurants';
    
    $symbol = $wpdb->get_var($wpdb->prepare(
        "SELECT currency_symbol FROM $table_name WHERE id = %d", 
        $restaurant_id
    ));

    return $symbol ? $symbol : '$'; 
}