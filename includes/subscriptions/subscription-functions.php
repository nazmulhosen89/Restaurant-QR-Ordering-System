<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function qrrs_check_system_license() {
    // DEVELOPER SETTINGS: Ekhane expiry date boshao
    $license_expiry = '2026-12-30'; // Udahoron
    
    $today = current_time('Y-m-d');
    $expire_date = date('Y-m-d', strtotime($license_expiry));
    
    $date1 = date_create($today);
    $date2 = date_create($expire_date);
    $diff = date_diff($date1, $date2);
    $days_left = (int)$diff->format("%r%a");

    return [
        'is_expired' => ($days_left <= 0),
        'days_left'  => $days_left,
        'expiry_date'=> $expire_date
    ];
}