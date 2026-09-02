<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Backward-compatible wrappers for the old subscription system.
 * The product now uses Free/Pro lifetime licensing from includes/license.
 */
function qrrs_check_system_license() {
    return [
        'is_expired'  => false,
        'days_left'   => qrrs_is_pro() ? 99999 : 0,
        'expiry_date' => '',
        'error'       => '',
        'mode'        => qrrs_is_pro() ? 'pro_lifetime' : 'free',
    ];
}

function qrrs_verify_license_key( $license_key ) {
    return qrrs_check_system_license();
}

function qrrs_get_dynamic_plans() {
    return [
        'free' => [ 'name' => 'Free', 'price' => '$0', 'badge' => 'Lite' ],
        'pro'  => [ 'name' => 'Pro Lifetime', 'price' => '$79', 'badge' => 'One Time' ],
    ];
}

function qrrs_render_license_lock_screen() {
    ob_start();
    qrrs_render_license_page();
    return ob_get_clean();
}

function qrrs_ajax_license_check() {
    wp_send_json_success( qrrs_check_system_license() );
}
