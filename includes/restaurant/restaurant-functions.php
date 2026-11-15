<?php
if ( ! defined( 'ABSPATH' ) ) exit;



function qrrs_create_restaurant( $data ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qrrs_restaurants';

    $inserted = $wpdb->insert(
        $table_name,
        [
            'owner_id'               => get_current_user_id(),
            'restaurant_name'        => sanitize_text_field( $data['res_name'] ),
            'restaurant_logo'        => esc_url_raw( $data['res_logo'] ),
            'phone'                  => sanitize_text_field( $data['res_phone'] ),
            'bin_number'             => sanitize_text_field( $data['res_bin'] ),  
            'address'                => sanitize_textarea_field( $data['res_address'] ),
            'currency_symbol'        => sanitize_text_field( $data['currency'] ),
            'tax_percent'            => floatval( $data['tax'] ),
            'service_charge_percent' => floatval( $data['service_charge'] ),
            'pos_printer_settings'   => sanitize_text_field( $data['pos_printer'] ),
            'status'                 => 'active',
            'created_at'             => current_time( 'mysql' )
        ]
    );
    return $inserted ? $wpdb->insert_id : false;
}


function qrrs_update_restaurant_settings( $restaurant_id, $data ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qrrs_restaurants';

    $updated = $wpdb->update(
        $table_name,
        [
            'restaurant_name'        => sanitize_text_field( $data['res_name'] ),
            'restaurant_logo'        => esc_url_raw( $data['res_logo'] ),
            'phone'                  => sanitize_text_field( $data['res_phone'] ), 
            'bin_number'             => sanitize_text_field( $data['res_bin'] ),  
            'address'                => sanitize_textarea_field( $data['res_address'] ),
            'currency_symbol'        => sanitize_text_field( $data['currency'] ),
            'tax_percent'            => floatval( $data['tax'] ),
            'service_charge_percent' => floatval( $data['service_charge'] ),
            'pos_printer_settings'   => sanitize_text_field( $data['pos_printer'] )
        ],
        [ 'id' => $restaurant_id ]
    );
    return $updated !== false;
}


function qrrs_get_restaurant( $id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qrrs_restaurants';
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );
}


function qrrs_get_all_restaurants( $owner_id = null ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qrrs_restaurants';
    
    if ( $owner_id ) {
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE owner_id = %d ORDER BY id DESC", $owner_id ) );
    }
    
    return $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC" );
}


function qrrs_delete_restaurant( $id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'qrrs_restaurants';
    
    return $wpdb->delete( $table_name, [ 'id' => $id ] );
}


function qrrs_get_current_active_restaurant_id() {
    $user_id = get_current_user_id();
    return get_user_meta( $user_id, 'active_restaurant_id', true );
}



function qrrs_create_staff($data) {
    $user_id = wp_create_user( 
        sanitize_title($data['staff_name']) . '_' . rand(10, 99), 
        '123456', 
        sanitize_title($data['staff_name']) . '@restaurant.com' 
    );

    if ( is_wp_error( $user_id ) ) return false;

    $user = new WP_User( $user_id );
    $user->set_role( $data['staff_role'] );

    update_user_meta( $user_id, 'staff_photo', esc_url_raw( $data['staff_photo'] ) );
    update_user_meta( $user_id, 'staff_nid_front', esc_url_raw( $data['nid_front'] ) );
    update_user_meta( $user_id, 'staff_nid_back', esc_url_raw( $data['nid_back'] ) );
    update_user_meta( $user_id, 'staff_address', sanitize_textarea_field( $data['staff_address'] ) );
    update_user_meta( $user_id, 'assigned_restaurant', intval( $data['restaurant_id'] ) );
    update_user_meta( $user_id, 'staff_status', sanitize_text_field( $data['staff_status'] ) );
    update_user_meta( $user_id, 'full_name', sanitize_text_field( $data['staff_name'] ) );

    return $user_id;
}