<?php
/**
 * Uninstall QR Restaurant System
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// ==============================
// TABLES LIST
// ==============================
$tables = [
    'qrs_restaurants',
    'qrs_tables',
    'qrs_categories',
    'qrs_items',
    'qrs_orders',
    'qrs_order_items',
    'qrs_subscriptions'
];

// ==============================
// DELETE TABLES
// ==============================
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

// ==============================
// DELETE OPTIONS
// ==============================
delete_option('qrs_version');
delete_option('qrs_settings');

// ==============================
// DELETE USER META (optional)
// ==============================
$wpdb->query("
    DELETE FROM {$wpdb->usermeta}
    WHERE meta_key LIKE 'qrs_%'
");

// ==============================
// DELETE UPLOAD FILES (optional)
// ==============================
$upload_dir = wp_upload_dir();
$qrs_dir = $upload_dir['basedir'] . '/qrs/';

if (is_dir($qrs_dir)) {

    function qrs_delete_folder($dir) {
        if (!file_exists($dir)) return;

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                qrs_delete_folder($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    qrs_delete_folder($qrs_dir);
}