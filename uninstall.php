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
    'qrrs_restaurants',
    'qrrs_staff',
    'qrrs_tables',
    'qrrs_categories',
    'qrrs_items',
    'qrrs_orders',
    'qrrs_order_items',
    'qrrs_kitchen_sessions',
    'qrrs_inventory_categories',
    'qrrs_inventory_units',
    'qrrs_inventory_items',
    'qrrs_suppliers',
    'qrrs_stock_movements',
    'qrrs_requisitions',
    'qrrs_requisition_items',
    'qrrs_recipes',
    'qrrs_recipe_items',
    'qrrs_wastage',
    'qrrs_subscriptions'
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
delete_option('qrrs_plugin_installed_at');
delete_option('qrrs_inventory_schema_version');

// ==============================
// DELETE USER META (optional)
// ==============================
$wpdb->query("
    DELETE FROM {$wpdb->usermeta}
    WHERE meta_key LIKE 'qrs_%'
       OR meta_key LIKE 'qrrs_%'
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
