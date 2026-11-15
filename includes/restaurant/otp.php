<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OTP for online orders.
 *  Modes (per restaurant, set in Online & Delivery):
 *    off   - no verification
 *    test  - code is generated and shown on the order page (for testing only, no SMS sent)
 *  Later: sms / whatsapp providers plug into qrrs_otp_deliver().
 *  Safety: 6-digit code, expires in 5 minutes, 5 wrong tries, 60 seconds between sends,
 *  max 5 sends per phone per 30 minutes. Verified phone is valid for 30 minutes and used once per order.
 */

function qrrs_otp_mode( $restaurant_id ) {
    $all = get_option( 'qrrs_otp_settings', [] );
    $mode = is_array( $all ) && isset( $all[ intval( $restaurant_id ) ] ) ? $all[ intval( $restaurant_id ) ] : 'off';
    return in_array( $mode, [ 'off', 'test' ], true ) ? $mode : 'off';
}

function qrrs_otp_save_mode( $restaurant_id, $mode ) {
    $all = get_option( 'qrrs_otp_settings', [] );
    if ( ! is_array( $all ) ) $all = [];
    $all[ intval( $restaurant_id ) ] = in_array( $mode, [ 'off', 'test' ], true ) ? $mode : 'off';
    update_option( 'qrrs_otp_settings', $all, false );
}

function qrrs_otp_key( $rid, $phone ) {
    return 'qrrs_otp_' . intval( $rid ) . '_' . md5( $phone );
}

/** Real delivery goes here later (SMS / WhatsApp). Returns true when sent. */
function qrrs_otp_deliver( $rid, $phone, $code ) {
    return true; // test mode: nothing is sent, the code is shown on the page
}

function qrrs_ajax_otp_send() {
    $r = qrrs_public_restaurant_by_token( $_POST['token'] ?? '' );
    if ( ! $r ) wp_send_json_error( 'This ordering link is not active.' );
    $rid   = intval( $r->id );
    $phone = qrrs_normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
    if ( ! preg_match( '/^01[3-9]\d{8}$/', $phone ) ) wp_send_json_error( 'Please enter a valid mobile number first.' );
    if ( 'off' === qrrs_otp_mode( $rid ) ) wp_send_json_success( [ 'mode' => 'off' ] );

    $key  = qrrs_otp_key( $rid, $phone );
    $data = get_transient( $key ) ?: [ 'sends' => [], 'hash' => '', 'tries' => 0, 'expires' => 0 ];
    $now  = time();
    $data['sends'] = array_values( array_filter( $data['sends'], fn( $t ) => $t > $now - 30 * MINUTE_IN_SECONDS ) );
    if ( $data['sends'] && $now - end( $data['sends'] ) < 60 ) wp_send_json_error( 'Please wait a minute before requesting another code.' );
    if ( count( $data['sends'] ) >= 5 ) wp_send_json_error( 'Too many codes requested. Please try again later.' );

    $code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
    $data['hash']    = wp_hash( $code );
    $data['tries']   = 0;
    $data['expires'] = $now + 5 * MINUTE_IN_SECONDS;
    $data['sends'][] = $now;
    set_transient( $key, $data, 30 * MINUTE_IN_SECONDS );

    if ( ! qrrs_otp_deliver( $rid, $phone, $code ) ) wp_send_json_error( 'Could not send the code. Please try again.' );
    wp_send_json_success( [ 'mode' => 'test', 'code' => $code, 'expires_in' => 300 ] );
}
add_action( 'wp_ajax_qrrs_otp_send', 'qrrs_ajax_otp_send' );
add_action( 'wp_ajax_nopriv_qrrs_otp_send', 'qrrs_ajax_otp_send' );

function qrrs_ajax_otp_verify() {
    $r = qrrs_public_restaurant_by_token( $_POST['token'] ?? '' );
    if ( ! $r ) wp_send_json_error( 'This ordering link is not active.' );
    $rid   = intval( $r->id );
    $phone = qrrs_normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
    $code  = preg_replace( '/\D/', '', (string) ( $_POST['code'] ?? '' ) );
    $key   = qrrs_otp_key( $rid, $phone );
    $data  = get_transient( $key );
    if ( ! $data || empty( $data['hash'] ) ) wp_send_json_error( 'Please request a new code.' );
    if ( time() > $data['expires'] ) wp_send_json_error( 'This code has expired. Please request a new one.' );
    if ( $data['tries'] >= 5 ) wp_send_json_error( 'Too many wrong attempts. Please request a new code.' );
    if ( ! hash_equals( $data['hash'], wp_hash( $code ) ) ) {
        $data['tries']++;
        set_transient( $key, $data, 30 * MINUTE_IN_SECONDS );
        wp_send_json_error( 'The code is not correct.' );
    }
    set_transient( 'qrrs_verified_' . $rid . '_' . md5( $phone ), 1, 30 * MINUTE_IN_SECONDS );
    delete_transient( $key );
    wp_send_json_success( [ 'verified' => true ] );
}
add_action( 'wp_ajax_qrrs_otp_verify', 'qrrs_ajax_otp_verify' );
add_action( 'wp_ajax_nopriv_qrrs_otp_verify', 'qrrs_ajax_otp_verify' );

/** True when the phone was verified recently (or OTP is off). Consumes the verification. */
function qrrs_otp_check_and_consume( $rid, $phone ) {
    if ( 'off' === qrrs_otp_mode( $rid ) ) return true;
    $k = 'qrrs_verified_' . intval( $rid ) . '_' . md5( $phone );
    if ( get_transient( $k ) ) {
        delete_transient( $k );
        return true;
    }
    return false;
}