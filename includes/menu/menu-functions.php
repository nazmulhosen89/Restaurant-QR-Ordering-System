<?php
if ( ! defined( 'ABSPATH' ) ) exit;


function qrrs_get_categories($restaurant_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_categories WHERE restaurant_id = %d", $restaurant_id));
}


function qrrs_get_items($restaurant_id, $category_id = null) {
    global $wpdb;
    $query = "SELECT * FROM {$wpdb->prefix}qrrs_items WHERE restaurant_id = %d";
    $params = [$restaurant_id];

    if ($category_id) {
        $query .= " AND category_id = %d";
        $params[] = $category_id;
    }

    return $wpdb->get_results($wpdb->prepare($query, ...$params));
}