<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$restaurant_id = qrrs_inventory_get_active_restaurant_id();

if ( ! $restaurant_id ) {
    echo '<div style="padding:50px; text-align:center;"><h3>Please select a restaurant first.</h3></div>';
    return;
}

$can_manage = qrrs_inventory_can_manage();
$can_request = qrrs_inventory_can_request();
$section = isset( $_GET['inv_section'] ) ? sanitize_key( $_GET['inv_section'] ) : 'overview';
$base_url = home_url( '/restaurant-dashboard/?tab=inventory' );

$cat_table       = $wpdb->prefix . 'qrrs_inventory_categories';
$unit_table      = $wpdb->prefix . 'qrrs_inventory_units';
$item_table      = $wpdb->prefix . 'qrrs_inventory_items';
$movement_table  = $wpdb->prefix . 'qrrs_stock_movements';
$req_table       = $wpdb->prefix . 'qrrs_requisitions';
$req_item_table  = $wpdb->prefix . 'qrrs_requisition_items';
$wastage_table   = $wpdb->prefix . 'qrrs_wastage';

if ( ! function_exists( 'qrrs_inventory_redirect' ) ) {
    function qrrs_inventory_redirect( $status, $section = 'overview' ) {
        wp_safe_redirect( add_query_arg( [ 'tab' => 'inventory', 'inv_section' => $section, 'status' => $status ], home_url( '/restaurant-dashboard/' ) ) );
        exit;
    }
}

if ( isset( $_POST['qrrs_inventory_action'] ) ) {
    if ( ! isset( $_POST['qrrs_inventory_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_inventory_nonce'], 'qrrs_inventory_action' ) ) {
        wp_die( 'Security check failed' );
    }

    $action = sanitize_key( $_POST['qrrs_inventory_action'] );

    if ( 'save_category' === $action && $can_manage ) {
        $wpdb->insert(
            $cat_table,
            [
                'restaurant_id' => $restaurant_id,
                'category_name' => sanitize_text_field( $_POST['category_name'] ),
                'category_type' => sanitize_key( $_POST['category_type'] ),
                'status'        => 'active',
                'created_by'    => get_current_user_id(),
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s' ]
        );
        qrrs_inventory_redirect( $wpdb->insert_id ? 'category_saved' : 'error', 'items' );
    }

    if ( 'save_item' === $action && $can_manage ) {
        $edit_id = isset( $_POST['edit_id'] ) ? intval( $_POST['edit_id'] ) : 0;
        $data = [
            'restaurant_id'     => $restaurant_id,
            'category_id'       => intval( $_POST['category_id'] ),
            'unit_id'           => intval( $_POST['unit_id'] ),
            'item_name'         => sanitize_text_field( $_POST['item_name'] ),
            'item_type'         => sanitize_key( $_POST['item_type'] ),
            'sku'               => sanitize_text_field( $_POST['sku'] ),
            'min_stock_level'   => floatval( $_POST['min_stock_level'] ),
            'cost_per_unit'     => floatval( $_POST['cost_per_unit'] ),
            'storage_location'  => sanitize_text_field( $_POST['storage_location'] ),
            'status'            => isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active',
            'updated_at'        => current_time( 'mysql' ),
        ];

        if ( $edit_id ) {
            $saved = $wpdb->update(
                $item_table,
                $data,
                [ 'id' => $edit_id, 'restaurant_id' => $restaurant_id ],
                [ '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s' ],
                [ '%d', '%d' ]
            );
            qrrs_inventory_redirect( false !== $saved ? 'item_updated' : 'error', 'items' );
        }

        $data['current_stock'] = 0;
        $data['created_by'] = get_current_user_id();
        $data['created_at'] = current_time( 'mysql' );
        $saved = $wpdb->insert(
            $item_table,
            $data,
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%f', '%d', '%s' ]
        );

        $new_item_id = $wpdb->insert_id;
        $opening_stock = isset( $_POST['opening_stock'] ) ? floatval( $_POST['opening_stock'] ) : 0;

        if ( $saved && $opening_stock > 0 ) {
            qrrs_inventory_add_stock_movement( [
                'restaurant_id'     => $restaurant_id,
                'inventory_item_id' => $new_item_id,
                'movement_type'     => 'opening',
                'quantity_in'       => $opening_stock,
                'unit_cost'         => floatval( $_POST['cost_per_unit'] ),
                'reference_type'    => 'inventory_item',
                'reference_id'      => $new_item_id,
                'note'              => 'Opening stock',
            ] );
        }

        qrrs_inventory_redirect( $saved ? 'item_saved' : 'error', 'items' );
    }

    if ( 'stock_movement' === $action && $can_manage ) {
        $movement_type = sanitize_key( $_POST['movement_type'] );
        $qty = max( 0, floatval( $_POST['quantity'] ) );
        $is_in = in_array( $movement_type, [ 'purchase', 'return', 'opening', 'adjustment_in' ], true );

        $saved = qrrs_inventory_add_stock_movement( [
            'restaurant_id'     => $restaurant_id,
            'inventory_item_id' => intval( $_POST['inventory_item_id'] ),
            'movement_type'     => $movement_type,
            'quantity_in'       => $is_in ? $qty : 0,
            'quantity_out'      => $is_in ? 0 : $qty,
            'unit_cost'         => floatval( $_POST['unit_cost'] ),
            'reference_type'    => 'manual',
            'note'              => sanitize_textarea_field( $_POST['note'] ),
        ] );

        qrrs_inventory_redirect( $saved ? 'stock_saved' : 'error', $is_in ? 'stock-in' : 'stock-out' );
    }

    if ( 'create_requisition' === $action && $can_request ) {
        $user = wp_get_current_user();
        $roles = (array) $user->roles;

        $wpdb->insert(
            $req_table,
            [
                'restaurant_id'  => $restaurant_id,
                'requested_by'   => get_current_user_id(),
                'requested_role' => implode( ',', array_map( 'sanitize_key', $roles ) ),
                'department'     => sanitize_key( $_POST['department'] ),
                'request_type'   => sanitize_key( $_POST['request_type'] ),
                'priority'       => sanitize_key( $_POST['priority'] ),
                'status'         => 'pending',
                'note'           => sanitize_textarea_field( $_POST['note'] ),
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        $req_id = $wpdb->insert_id;
        $item_ids = isset( $_POST['req_item_id'] ) ? (array) $_POST['req_item_id'] : [];
        $qtys = isset( $_POST['req_qty'] ) ? (array) $_POST['req_qty'] : [];
        $notes = isset( $_POST['req_note'] ) ? (array) $_POST['req_note'] : [];

        foreach ( $item_ids as $index => $item_id ) {
            $item_id = intval( $item_id );
            $qty = isset( $qtys[ $index ] ) ? floatval( $qtys[ $index ] ) : 0;

            if ( ! $req_id || ! $item_id || $qty <= 0 ) {
                continue;
            }

            $unit_id = $wpdb->get_var(
                $wpdb->prepare( "SELECT unit_id FROM $item_table WHERE id = %d AND restaurant_id = %d", $item_id, $restaurant_id )
            );

            $wpdb->insert(
                $req_item_table,
                [
                    'requisition_id'    => $req_id,
                    'inventory_item_id' => $item_id,
                    'requested_qty'     => $qty,
                    'unit_id'           => intval( $unit_id ),
                    'note'              => isset( $notes[ $index ] ) ? sanitize_text_field( $notes[ $index ] ) : '',
                ],
                [ '%d', '%d', '%f', '%d', '%s' ]
            );
        }

        qrrs_inventory_redirect( $req_id ? 'requisition_saved' : 'error', 'requisitions' );
    }

    if ( 'requisition_action' === $action && $can_manage ) {
        $req_id = intval( $_POST['requisition_id'] );
        $req_action = sanitize_key( $_POST['req_action'] );

        if ( 'reject' === $req_action ) {
            $wpdb->update( $req_table, [ 'status' => 'rejected', 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql' ) ], [ 'id' => $req_id, 'restaurant_id' => $restaurant_id ] );
            qrrs_inventory_redirect( 'requisition_rejected', 'requisitions' );
        }

        if ( 'approve' === $req_action ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $req_item_table ri
                     INNER JOIN $req_table r ON ri.requisition_id = r.id
                     SET ri.approved_qty = ri.requested_qty
                     WHERE ri.requisition_id = %d AND r.restaurant_id = %d",
                    $req_id,
                    $restaurant_id
                )
            );
            $wpdb->update( $req_table, [ 'status' => 'approved', 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql' ) ], [ 'id' => $req_id, 'restaurant_id' => $restaurant_id ] );
            qrrs_inventory_redirect( 'requisition_approved', 'requisitions' );
        }

        if ( 'issue' === $req_action ) {
            $req_status = $wpdb->get_var(
                $wpdb->prepare( "SELECT status FROM $req_table WHERE id = %d AND restaurant_id = %d", $req_id, $restaurant_id )
            );

            if ( 'approved' !== $req_status ) {
                qrrs_inventory_redirect( 'approve_before_issue', 'requisitions' );
            }

            $req_items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ri.* FROM $req_item_table ri
                     INNER JOIN $req_table r ON ri.requisition_id = r.id
                     WHERE ri.requisition_id = %d AND r.restaurant_id = %d",
                    $req_id,
                    $restaurant_id
                )
            );

            foreach ( $req_items as $req_item ) {
                $qty = $req_item->approved_qty > 0 ? $req_item->approved_qty : $req_item->requested_qty;
                $movement_id = qrrs_inventory_add_stock_movement( [
                    'restaurant_id'     => $restaurant_id,
                    'inventory_item_id' => $req_item->inventory_item_id,
                    'movement_type'     => 'requisition_issue',
                    'quantity_out'      => $qty,
                    'reference_type'    => 'requisition',
                    'reference_id'      => $req_id,
                    'note'              => 'Issued against requisition #' . $req_id,
                ] );

                if ( ! $movement_id ) {
                    qrrs_inventory_redirect( 'insufficient_stock', 'requisitions' );
                }

                $wpdb->update( $req_item_table, [ 'issued_qty' => $qty ], [ 'id' => $req_item->id ], [ '%f' ], [ '%d' ] );
            }

            $wpdb->update( $req_table, [ 'status' => 'issued', 'issued_by' => get_current_user_id(), 'issued_at' => current_time( 'mysql' ) ], [ 'id' => $req_id, 'restaurant_id' => $restaurant_id ] );
            qrrs_inventory_redirect( 'requisition_issued', 'requisitions' );
        }
    }

    if ( 'report_wastage' === $action && $can_request ) {
        $status = $can_manage ? 'approved' : 'pending';
        $wpdb->insert(
            $wastage_table,
            [
                'restaurant_id'      => $restaurant_id,
                'inventory_item_id'  => intval( $_POST['inventory_item_id'] ),
                'quantity'           => floatval( $_POST['quantity'] ),
                'unit_id'            => intval( $_POST['unit_id'] ),
                'reason'             => sanitize_text_field( $_POST['reason'] ),
                'note'               => sanitize_textarea_field( $_POST['note'] ),
                'status'             => $status,
                'reported_by'        => get_current_user_id(),
                'approved_by'        => $can_manage ? get_current_user_id() : 0,
                'approved_at'        => $can_manage ? current_time( 'mysql' ) : null,
                'created_at'         => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        $wastage_id = $wpdb->insert_id;

        if ( $wastage_id && $can_manage ) {
            qrrs_inventory_add_stock_movement( [
                'restaurant_id'     => $restaurant_id,
                'inventory_item_id' => intval( $_POST['inventory_item_id'] ),
                'movement_type'     => 'wastage',
                'quantity_out'      => floatval( $_POST['quantity'] ),
                'reference_type'    => 'wastage',
                'reference_id'      => $wastage_id,
                'note'              => sanitize_textarea_field( $_POST['reason'] ),
            ] );
        }

        qrrs_inventory_redirect( $wastage_id ? 'wastage_saved' : 'error', 'wastage' );
    }

    if ( 'approve_wastage' === $action && $can_manage ) {
        $wastage_id = intval( $_POST['wastage_id'] );
        $wastage = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $wastage_table WHERE id = %d AND restaurant_id = %d AND status = 'pending'", $wastage_id, $restaurant_id )
        );

        if ( $wastage ) {
            $wpdb->update(
                $wastage_table,
                [ 'status' => 'approved', 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql' ) ],
                [ 'id' => $wastage_id ],
                [ '%s', '%d', '%s' ],
                [ '%d' ]
            );
            qrrs_inventory_add_stock_movement( [
                'restaurant_id'     => $restaurant_id,
                'inventory_item_id' => $wastage->inventory_item_id,
                'movement_type'     => 'wastage',
                'quantity_out'      => $wastage->quantity,
                'reference_type'    => 'wastage',
                'reference_id'      => $wastage_id,
                'note'              => $wastage->reason,
            ] );
        }

        qrrs_inventory_redirect( $wastage ? 'wastage_approved' : 'error', 'wastage' );
    }
}

$units = qrrs_inventory_get_units();
$categories = qrrs_inventory_get_categories( $restaurant_id );
$items = qrrs_inventory_get_items( $restaurant_id, false );
$active_items = qrrs_inventory_get_items( $restaurant_id, true );

$edit_item_id = isset( $_GET['edit_inventory_item'] ) ? intval( $_GET['edit_inventory_item'] ) : 0;
$edit_item = $edit_item_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $item_table WHERE id = %d AND restaurant_id = %d", $edit_item_id, $restaurant_id ) ) : null;

$stats = [
    'items' => intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $item_table WHERE restaurant_id = %d", $restaurant_id ) ) ),
    'low'   => intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $item_table WHERE restaurant_id = %d AND status = 'active' AND current_stock <= min_stock_level", $restaurant_id ) ) ),
    'req'   => intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $req_table WHERE restaurant_id = %d AND status = 'pending'", $restaurant_id ) ) ),
    'waste' => intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wastage_table WHERE restaurant_id = %d AND status = 'pending'", $restaurant_id ) ) ),
];




// --- Handle CSV Import ---
if ( isset($_POST['import_inventory_csv_action']) ) {
    if (!isset($_POST['import_csv_nonce_field']) || !wp_verify_nonce($_POST['import_csv_nonce_field'], 'import_csv_nonce')) {
        wp_die('Security check failed');
    }

    if ( ! empty( $_FILES['inventory_csv']['tmp_name'] ) ) {
        global $wpdb;

        $restaurant_id = qrrs_inventory_get_active_restaurant_id();
        $current_user_id = get_current_user_id();

        // টেবিল ভ্যারিয়েবল সেটআপ (আপনার ডাটাবেজ অনুযায়ী ফিক্সড)
        $cat_table  = $wpdb->prefix . 'rest_qrrs_inventory_categories';
        $unit_table = $wpdb->prefix . 'qrrs_inventory_units';
        $item_table = $wpdb->prefix . 'rest_qrrs_inventory_items';

        $file = $_FILES['inventory_csv']['tmp_name'];
        $handle = fopen($file, "r");

        if ( $handle !== FALSE ) {
            // প্রথম হেডার লাইন স্কিপ করা
            $first_line = fgets($handle);
            $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
            rewind($handle);
            fgetcsv($handle, 1000, $delimiter);

            $imported_count = 0;

            while ( ($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE ) {
                if (empty($data) || (count($data) == 1 && empty($data[0]))) {
                    continue;
                }
                if ( count($data) < 7 ) {
                    continue; 
                }

                $item_name     = isset($data[0]) ? sanitize_text_field(trim($data[0])) : '';
                $cat_name      = isset($data[1]) ? sanitize_text_field(trim($data[1])) : '';
                $unit_code     = isset($data[2]) ? sanitize_text_field(trim($data[2])) : '';
                $item_type     = isset($data[3]) ? sanitize_text_field(trim($data[3])) : 'raw_material';
                $sku           = isset($data[4]) ? sanitize_text_field(trim($data[4])) : '';
                $cost          = isset($data[5]) ? floatval($data[5]) : 0;
                $opening_stock = isset($data[6]) ? floatval($data[6]) : 0;
                $low_stock     = isset($data[7]) ? floatval($data[7]) : 0;
                $location      = isset($data[8]) ? sanitize_text_field(trim($data[8])) : '';

                if ( empty($item_name) ) {
                    continue;
                }

                // ১. ক্যাটাগরি আইডি খোঁজা বা নতুন তৈরি করা
                $cat_id = 0;
                if ( ! empty($cat_name) ) {
                    $cat_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $cat_table WHERE category_name = %s AND restaurant_id = %d", 
                        $cat_name, $restaurant_id
                    ));

                    if ( ! $cat_id ) {
                        $wpdb->insert($cat_table, [
                            'restaurant_id' => $restaurant_id,
                            'category_name' => $cat_name,
                            'category_type' => 'raw_material',
                            'status'        => 'active',
                            'created_by'    => $current_user_id
                        ]);
                        $cat_id = $wpdb->insert_id;
                    }
                }

                // ২. ইউনিট আইডি সেটআপ
                $unit_id = 0;
                if ( ! empty($unit_code) ) {
                    $unit_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $unit_table WHERE unit_code = %s", 
                        $unit_code
                    ));
                }
                if ( ! $unit_id ) { $unit_id = 1; }

                // ৩. আইটেম ইনসার্ট লজিক (inv_category_id কলাম নেম দিয়ে)
                $insert_item = $wpdb->insert($item_table, [
                    'restaurant_id'   => $restaurant_id,
                    'inv_category_id' => intval($cat_id),      // আপনার নতুন রিকোয়ারমেন্ট কলাম নেম
                    'unit_id'         => intval($unit_id),
                    'item_name'       => $item_name,
                    'item_type'       => $item_type,
                    'sku'             => $sku,
                    'current_stock'   => floatval($opening_stock),
                    'min_stock_level' => floatval($low_stock),
                    'cost_per_unit'   => floatval($cost),
                    'storage_location'=> $location,
                    'status'          => 'active',
                    'created_by'      => $current_user_id
                ]);

                if ( $insert_item !== false ) {
                    $new_item_id = $wpdb->insert_id;

                    // ৪. স্টক মুভমেন্ট লেজার এন্ট্রি
                    if ( $new_item_id && $opening_stock > 0 ) {
                        $wpdb->insert($wpdb->prefix . 'qrrs_stock_movements', [
                            'restaurant_id'  => $restaurant_id,
                            'item_id'        => $new_item_id,
                            'movement_type'  => 'in',
                            'quantity'       => floatval($opening_stock),
                            'unit_cost'      => floatval($cost),
                            'total_amount'   => floatval($opening_stock * $cost),
                            'reference_type' => 'Opening Stock',
                            'reference_id'   => 0,
                            'note'           => 'Imported via CSV Sheet',
                            'created_by'     => $current_user_id
                        ]);
                    }
                    $imported_count++;
                }
            }
            fclose($handle);
            
            if ($imported_count > 0) {
                wp_safe_redirect($base_url . '&status=csv_imported&count=' . $imported_count);
                exit;
            } else {
                echo "<div style='background:#fef3c7; color:#b45309; padding:15px; border-radius:5px; margin-top:20px; font-weight:bold;'>No items were imported.</div>";
            }
        }
    }
}
?>

<style>
    .qrrs-inv-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
    .qrrs-inv-tabs a { background:#fff; border:1px solid #ddd; color:#1e293b; padding:9px 13px; border-radius:8px; text-decoration:none; font-weight:600; font-size:13px; }
    .qrrs-inv-tabs a.active { background:#1e293b; color:#fff; border-color:#1e293b; }
    .qrrs-inv-stats { display:grid; grid-template-columns:repeat(4, minmax(140px, 1fr)); gap:12px; margin-bottom:20px; }
    .qrrs-inv-stat { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; }
    .qrrs-inv-stat strong { display:block; font-size:24px; color:#0f172a; }
    .qrrs-inv-stat span { color:#64748b; font-size:12px; text-transform:uppercase; font-weight:700; }
    .qrrs-inv-form-grid { display:grid; grid-template-columns:repeat(3, minmax(180px, 1fr)); gap:14px; }
    .qrrs-inv-form-grid .full { grid-column:1 / -1; }
    .qrrs-inv-field label { display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:#334155; }
    .qrrs-inv-field input, .qrrs-inv-field select, .qrrs-inv-field textarea { width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; }
    .qrrs-inv-actions { display:flex; gap:8px; align-items:center; }
    .qrrs-inv-btn { background:#f97316; color:#fff; border:none; border-radius:7px; padding:10px 14px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
    .qrrs-inv-btn.secondary { background:#334155; }
    .qrrs-inv-btn.danger { background:#dc2626; }
    .qrrs-inv-muted { color:#64748b; font-size:12px; }
    .qrrs-low { color:#dc2626; font-weight:800; }
    .qrrs-ok { color:#15803d; font-weight:800; }
    @media (max-width: 900px) { .qrrs-inv-stats, .qrrs-inv-form-grid { grid-template-columns:1fr; } }
</style>

<div class="qrrs-card" style="padding:20px; margin-bottom:18px;">
    <div style="display:flex; justify-content:space-between; gap:15px; align-items:flex-start; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;">Inventory Management</h2>
            <p class="qrrs-inv-muted" style="margin:6px 0 0;">Raw materials, stock ledger, requisitions, wastage and low stock control.</p>
        </div>
        <div class="qrrs-inv-muted">Restaurant ID: <?php echo esc_html( $restaurant_id ); ?></div>
    </div>
</div>

<?php if ( isset( $_GET['status'] ) ) : ?>
    <div class="qrrs-toast success"><?php echo esc_html( ucwords( str_replace( '_', ' ', sanitize_text_field( $_GET['status'] ) ) ) ); ?></div>
<?php endif; ?>

<div class="qrrs-inv-tabs">
    <?php
    $tabs = [
        'overview'     => 'Overview',
        'items'        => 'Raw Materials',
        'stock-in'     => 'Stock In',
        'stock-out'    => 'Stock Out',
        'requisitions' => 'Requisitions',
        'wastage'      => 'Wastage',
        'low-stock'    => 'Low Stock',
        'ledger'       => 'Stock Ledger',
    ];

    foreach ( $tabs as $tab_key => $tab_label ) {
        printf(
            '<a href="%s" class="%s">%s</a>',
            esc_url( add_query_arg( [ 'tab' => 'inventory', 'inv_section' => $tab_key ], home_url( '/restaurant-dashboard/' ) ) ),
            $section === $tab_key ? 'active' : '',
            esc_html( $tab_label )
        );
    }




if ( isset($_POST['import_inventory_csv_action']) ) {
    if (!isset($_POST['import_csv_nonce_field']) || !wp_verify_nonce($_POST['import_csv_nonce_field'], 'import_csv_nonce')) {
        wp_die('Security check failed');
    }

    if ( ! empty( $_FILES['inventory_csv']['tmp_name'] ) ) {
        global $wpdb;

        $restaurant_id = qrrs_inventory_get_active_restaurant_id();
        $current_user_id = get_current_user_id();

        $file = $_FILES['inventory_csv']['tmp_name'];
        $handle = fopen($file, "r");

        if ( $handle !== FALSE ) {
            // First header line bad deya
            $first_line = fgets($handle);
            $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
            rewind($handle);
            fgetcsv($handle, 1000, $delimiter);

            $imported_count = 0;

            while ( ($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE ) {
                if (empty($data) || (count($data) == 1 && empty($data[0]))) {
                    continue;
                }
                if ( count($data) < 7 ) {
                    continue; 
                }

                $item_name     = isset($data[0]) ? sanitize_text_field(trim($data[0])) : '';
                $cat_name      = isset($data[1]) ? sanitize_text_field(trim($data[1])) : '';
                $unit_code     = isset($data[2]) ? sanitize_text_field(trim($data[2])) : '';
                $item_type     = isset($data[3]) ? sanitize_text_field(trim($data[3])) : 'raw';
                $sku           = isset($data[4]) ? sanitize_text_field(trim($data[4])) : '';
                $cost          = isset($data[5]) ? floatval($data[5]) : 0;
                $opening_stock = isset($data[6]) ? floatval($data[6]) : 0;
                $low_stock     = isset($data[7]) ? floatval($data[7]) : 0;
                $location      = isset($data[8]) ? sanitize_text_field(trim($data[8])) : '';

                if ( empty($item_name) ) {
                    continue;
                }

                // 1. Inventory Category Management (Apnar database exact schema 'category_name' onusare)
                $cat_id = 0;
                if ( ! empty($cat_name) ) {
                    $cat_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}qrrs_inventory_categories WHERE category_name = %s AND restaurant_id = %d", 
                        $cat_name, $restaurant_id
                    ));

                    // Jodi category na thake, tobe new inventory category create hobe
                    if ( ! $cat_id ) {
                        $wpdb->insert($wpdb->prefix . 'qrrs_inventory_categories', [
                            'restaurant_id' => $restaurant_id,
                            'category_name' => $cat_name
                        ]);
                        $cat_id = $wpdb->insert_id;
                    }
                }

                // 2. Unit Management
                $unit_id = 0;
                if ( ! empty($unit_code) ) {
                    $unit_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}qrrs_inventory_units WHERE unit_code = %s", 
                        $unit_code
                    ));
                }
                if ( ! $unit_id ) { $unit_id = 1; } // Default fall-back unit id

                // 3. Inventory Items table-e perfect float casting shoho data insert করা
                $insert_item = $wpdb->insert($wpdb->prefix . 'qrrs_inventory_items', [
                    'restaurant_id'   => $restaurant_id,
                    'inv_category_id' => intval($cat_id),
                    'item_name'       => $item_name,
                    'unit_id'         => intval($unit_id),
                    'current_stock'   => floatval($opening_stock),
                    'cost_per_unit'   => floatval($cost),
                    'item_type'       => $item_type,
                    'sku'             => $sku,
                    'reorder_level'   => floatval($low_stock),
                    'storage_location'=> $location,
                    'created_by'      => $current_user_id,
                    'status'          => 'active'
                ]);

                if ( $insert_item !== false ) {
                    $new_item_id = $wpdb->insert_id;

                    // 4. Stock Movement Ledger Ledger handling
                    if ( $new_item_id && $opening_stock > 0 ) {
                        $wpdb->insert($wpdb->prefix . 'qrrs_stock_movements', [
                            'restaurant_id'  => $restaurant_id,
                            'item_id'        => $new_item_id,
                            'movement_type'  => 'in',
                            'quantity'       => floatval($opening_stock),
                            'unit_cost'      => floatval($cost),
                            'total_amount'   => floatval($opening_stock * $cost),
                            'reference_type' => 'Opening Stock',
                            'reference_id'   => 0,
                            'note'           => 'Imported via CSV Sheet',
                            'created_by'     => $current_user_id
                        ]);
                    }
                    $imported_count++;
                }
            }
            fclose($handle);
            
            if ($imported_count > 0) {
                wp_safe_redirect($base_url . '&status=csv_imported&count=' . $imported_count);
                exit;
            } else {
                echo "<div style='background:#fef3c7; color:#b45309; padding:15px; border-radius:5px; margin-top:20px; font-weight:bold;'>No items were imported. Please check your CSV file column names match.</div>";
            }
        }
    }
}

    ?>
</div>

<div class="qrrs-inv-stats">
    <div class="qrrs-inv-stat"><strong><?php echo esc_html( $stats['items'] ); ?></strong><span>Total Items</span></div>
    <div class="qrrs-inv-stat"><strong><?php echo esc_html( $stats['low'] ); ?></strong><span>Low Stock</span></div>
    <div class="qrrs-inv-stat"><strong><?php echo esc_html( $stats['req'] ); ?></strong><span>Pending Requisitions</span></div>
    <div class="qrrs-inv-stat"><strong><?php echo esc_html( $stats['waste'] ); ?></strong><span>Pending Wastage</span></div>
</div>

<?php if ( 'overview' === $section ) : ?>
    <div class="qrrs-card" style="padding:20px;">
        <h3>Inventory Overview</h3>
        <p>Start with categories and raw materials, then record stock in/out. Kitchen and waiter staff can submit requisitions from this module.</p>
        <div class="qrrs-inv-actions">
            <a class="qrrs-inv-btn" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'items' ], home_url( '/restaurant-dashboard/' ) ) ); ?>">Manage Items</a>
            <a class="qrrs-inv-btn secondary" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'requisitions' ], home_url( '/restaurant-dashboard/' ) ) ); ?>">Requisitions</a>
        </div>
    </div>
<?php endif; ?>

<?php if ( 'items' === $section ) : ?>
    <?php if ( $can_manage ) : ?>
        <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
            <h3><?php echo $edit_item ? 'Edit Inventory Item' : 'Add Inventory Item'; ?></h3>
            <form method="post">
                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                <input type="hidden" name="qrrs_inventory_action" value="save_item">
                <input type="hidden" name="edit_id" value="<?php echo esc_attr( $edit_item->id ?? 0 ); ?>">
                <div class="qrrs-inv-form-grid">
                    <div class="qrrs-inv-field">
                        <label>Item Name</label>
                        <input type="text" name="item_name" value="<?php echo esc_attr( $edit_item->item_name ?? '' ); ?>" required>
                    </div>
                    <div class="qrrs-inv-field">
                        <label>Category</label>
                        <select name="category_id">
                            <option value="0">Uncategorized</option>
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat->id ); ?>" <?php selected( $edit_item->category_id ?? 0, $cat->id ); ?>><?php echo esc_html( $cat->category_name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="qrrs-inv-field">
                        <label>Unit</label>
                        <select name="unit_id" required>
                            <?php foreach ( $units as $unit ) : ?>
                                <option value="<?php echo esc_attr( $unit->id ); ?>" <?php selected( $edit_item->unit_id ?? 0, $unit->id ); ?>><?php echo esc_html( $unit->unit_name . ' (' . $unit->unit_code . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="qrrs-inv-field">
                        <label>Type</label>
                        <select name="item_type">
                            <?php foreach ( [ 'raw_material', 'consumable', 'cutlery', 'packaging', 'asset_supply', 'other' ] as $type ) : ?>
                                <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $edit_item->item_type ?? 'raw_material', $type ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="qrrs-inv-field">
                        <label>SKU</label>
                        <input type="text" name="sku" value="<?php echo esc_attr( $edit_item->sku ?? '' ); ?>">
                    </div>
                    <div class="qrrs-inv-field">
                        <label>Cost Per Unit</label>
                        <input type="number" step="0.0001" name="cost_per_unit" value="<?php echo esc_attr( $edit_item->cost_per_unit ?? 0 ); ?>">
                    </div>
                    <?php if ( ! $edit_item ) : ?>
                    <div class="qrrs-inv-field">
                        <label>Opening Stock</label>
                        <input type="number" step="0.0001" name="opening_stock" value="0">
                    </div>
                    <?php endif; ?>
                    <div class="qrrs-inv-field">
                        <label>Low Stock Level</label>
                        <input type="number" step="0.0001" name="min_stock_level" value="<?php echo esc_attr( $edit_item->min_stock_level ?? 0 ); ?>">
                    </div>
                    <div class="qrrs-inv-field">
                        <label>Storage Location</label>
                        <input type="text" name="storage_location" value="<?php echo esc_attr( $edit_item->storage_location ?? '' ); ?>">
                    </div>
                    <div class="qrrs-inv-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="active" <?php selected( $edit_item->status ?? 'active', 'active' ); ?>>Active</option>
                            <option value="inactive" <?php selected( $edit_item->status ?? 'active', 'inactive' ); ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <p><button class="qrrs-inv-btn" type="submit">Save Item</button></p>
            </form>
        </div>

<div style="background:#fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
    <h3 style="margin-top:0; color:#1e293b;">📥 Bulk Import Items via Excel/CSV</h3>
    <p style="font-size:13px; color:#64748b; margin-bottom:15px;">
        Excel sheet-e nicher kram onujayi data likhe setake <strong>.csv</strong> format-e save kore ekhane upload korun. <br>
        <code>Format: Item Name, Category, Unit, Type, SKU, Cost Per Unit, Opening Stock, Low Stock, Location</code>
    </p>

    <form method="POST" action="" enctype="multipart/form-data" style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
        <?php wp_nonce_field('import_csv_nonce', 'import_csv_nonce_field'); ?>
        
        <div style="flex: 1; min-width: 250px;">
            <input type="file" name="inventory_csv" accept=".csv" required style="width:100%; padding:8px; border:1px solid #cbd5e1; background:#f8fafc; border-radius:4px;">
        </div>
        
        <button type="submit" name="import_inventory_csv_action" class="button button-primary" style="padding:8px 20px; font-weight:bold; background:#2563eb; color:#fff; border:none; border-radius:4px; cursor:pointer;">
            🚀 Start Bulk Import
        </button>
    </form>
</div>

        <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
            <h3>Add Category</h3>
            <form method="post" class="qrrs-inv-actions">
                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                <input type="hidden" name="qrrs_inventory_action" value="save_category">
                <input type="text" name="category_name" placeholder="Category name" required>
                <select name="category_type">
                    <option value="raw_material">Raw Material</option>
                    <option value="consumable">Consumable</option>
                    <option value="cutlery">Cutlery</option>
                    <option value="packaging">Packaging</option>
                </select>
                <button class="qrrs-inv-btn secondary" type="submit">Add Category</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="qrrs-card" style="padding:20px;">
        <h3>Inventory Items</h3>
        <?php
// টেবিল ভ্যারিয়েবল সেটআপ (আপনার ডাটাবেজ অনুযায়ী ফিক্সড)
$cat_table  = $wpdb->prefix . 'qrrs_inventory_categories';
$unit_table = $wpdb->prefix . 'qrrs_inventory_units';
$item_table = $wpdb->prefix . 'qrrs_inventory_items';

// --- সঠিক জয়েন কুয়েরি (inv_category_id কলাম দিয়ে) ---
$items = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT i.*, c.category_name, u.unit_code 
         FROM $item_table i
         LEFT JOIN $cat_table c ON i.inv_category_id = c.id
         LEFT JOIN $unit_table u ON i.unit_id = u.id
         WHERE i.restaurant_id = %d 
         ORDER BY i.id DESC",
        $restaurant_id
    )
);
?>
        <table class="qrrs-table" style="width:100%; border-collapse: collapse; margin-top:15px;">
    <thead>
        <tr style="background:#f1f5f9; text-align:left;">
            <th>Item Name</th>
            <th>Category</th>
            <th>Type</th>
            <th>SKU</th>
            <th>Current Stock</th>
            <th>Cost/Unit</th>
            <th>Status</th>
            <?php if ( $can_manage ) : ?><th>Actions</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $items as $item ) : 
        // দশমিক ডেটা ফিক্সিং
        $current_stock   = floatval($item->current_stock);
        $min_stock_level = floatval($item->min_stock_level); 
        $cost_per_unit   = floatval($item->cost_per_unit);

        // স্টক স্ট্যাটাস এলার্ট
        $stock_status_html = '';
        if ( $current_stock <= 0 ) {
            $stock_status_html = '<span style="background:#fee2e2; color:#dc2626; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold;">Out of Stock</span>';
        } elseif ( $current_stock <= $min_stock_level ) {
            $stock_status_html = '<span style="background:#fef3c7; color:#d97706; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold;">Low Level (' . $min_stock_level . ')</span>';
        } else {
            $stock_status_html = '<span style="background:#dcfce7; color:#16a34a; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold;">Good</span>';
        }
    ?>
        <tr style="border-bottom: 1px solid #e2e8f0;">
            <td style="font-weight:600; color:#1e293b;"><?php echo esc_html( $item->item_name ); ?></td>
            
            <td style="color:#475569;">
                <?php echo !empty($item->category_name) ? esc_html($item->category_name) : '<em style="color:#94a3b8;">Uncategorized</em>'; ?>
            </td>
            
            <td><span class="qrrs-badge-type" style="text-transform: capitalize;"><?php echo esc_html( str_replace('_', ' ', $item->item_type) ); ?></span></td>
            <td style="font-family:monospace; color:#64748b;"><?php echo esc_html( $item->sku ?: '-' ); ?></td>
            
            <td style="font-weight:bold; color:#0f172a;">
                <?php echo esc_html( $current_stock . ' ' . ($item->unit_code ?: '') ); ?>
            </td>
            
            <td><?php echo esc_html( number_format($cost_per_unit, 2) ); ?></td>
            <td><?php echo $stock_status_html; ?></td>
            
            <?php if ( $can_manage ) : ?>
            <td>
                <a href="<?php echo esc_url( add_query_arg(['action' => 'edit_item', 'id' => $item->id], $base_url) ); ?>" class="button" style="padding:2px 8px; font-size:12px;">Edit</a>
                <a href="<?php echo wp_nonce_url( add_query_arg(['action' => 'delete_item', 'id' => $item->id], $base_url), 'delete_item_nonce' ); ?>" class="button button-link-delete" onclick="return confirm('Are you sure?');" style="padding:2px 8px; font-size:12px; color:#dc2626;">Delete</a>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    
    <?php if ( empty( $items ) ) : ?>
        <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">No inventory items found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
    </div>
<?php endif; ?>

<?php if ( in_array( $section, [ 'stock-in', 'stock-out' ], true ) ) : ?>
    <div class="qrrs-card" style="padding:20px;">
        <h3><?php echo 'stock-in' === $section ? 'Stock In / Purchase' : 'Stock Out / Manual Issue'; ?></h3>
        <?php if ( $can_manage ) : ?>
            <form method="post">
                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                <input type="hidden" name="qrrs_inventory_action" value="stock_movement">
                <input type="hidden" name="movement_type" value="<?php echo 'stock-in' === $section ? 'purchase' : 'manual_out'; ?>">
                <div class="qrrs-inv-form-grid">
                    <div class="qrrs-inv-field">
                        <label>Item</label>
                        <select name="inventory_item_id" required>
                            <?php foreach ( $active_items as $item ) : ?>
                                <option value="<?php echo esc_attr( $item->id ); ?>"><?php echo esc_html( $item->item_name . ' - Stock: ' . $item->current_stock . ' ' . $item->unit_code ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="qrrs-inv-field"><label>Quantity</label><input type="number" step="0.0001" name="quantity" required></div>
                    <div class="qrrs-inv-field"><label>Unit Cost</label><input type="number" step="0.0001" name="unit_cost" value="0"></div>
                    <div class="qrrs-inv-field full"><label>Note</label><textarea name="note" rows="2"></textarea></div>
                </div>
                <p><button class="qrrs-inv-btn" type="submit">Save Movement</button></p>
            </form>
        <?php else : ?>
            <p>You can submit a requisition instead of direct stock changes.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ( 'requisitions' === $section ) : ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3>Create Requisition</h3>
        <form method="post">
            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
            <input type="hidden" name="qrrs_inventory_action" value="create_requisition">
            <div class="qrrs-inv-form-grid">
                <div class="qrrs-inv-field">
                    <label>Department</label>
                    <select name="department">
                        <?php foreach ( [ 'kitchen', 'waiter', 'manager', 'admin' ] as $dept ) : ?>
                            <option value="<?php echo esc_attr( $dept ); ?>" <?php selected( qrrs_inventory_user_department(), $dept ); ?>><?php echo esc_html( ucfirst( $dept ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-inv-field">
                    <label>Request Type</label>
                    <select name="request_type">
                        <option value="raw_material">Raw Material</option>
                        <option value="cutlery">Cutlery</option>
                        <option value="consumable">Consumable</option>
                        <option value="asset_supply">Asset Supply</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="qrrs-inv-field">
                    <label>Priority</label>
                    <select name="priority"><option value="normal">Normal</option><option value="urgent">Urgent</option><option value="low">Low</option></select>
                </div>
                <div class="qrrs-inv-field">
                    <label>Item</label>
                    <select name="req_item_id[]" required>
                        <?php foreach ( $active_items as $item ) : ?>
                            <option value="<?php echo esc_attr( $item->id ); ?>"><?php echo esc_html( $item->item_name . ' (' . $item->unit_code . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-inv-field"><label>Quantity</label><input type="number" step="0.0001" name="req_qty[]" required></div>
                <div class="qrrs-inv-field"><label>Item Note</label><input type="text" name="req_note[]"></div>
                <div class="qrrs-inv-field full"><label>Request Note</label><textarea name="note" rows="2"></textarea></div>
            </div>
            <p><button class="qrrs-inv-btn" type="submit">Submit Requisition</button></p>
        </form>
    </div>

    <div class="qrrs-card" style="padding:20px;">
        <h3>Requisition List</h3>
        <?php
        $requisitions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, u.display_name
                 FROM $req_table r
                 LEFT JOIN {$wpdb->users} u ON r.requested_by = u.ID
                 WHERE r.restaurant_id = %d
                 ORDER BY r.id DESC LIMIT 50",
                $restaurant_id
            )
        );
        ?>
        <table class="qrrs-table">
            <thead><tr><th>ID</th><th>By</th><th>Dept</th><th>Priority</th><th>Status</th><th>Items</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ( $requisitions as $req ) : ?>
                <?php
                $req_items = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT ri.*, i.item_name, u.unit_code
                         FROM $req_item_table ri
                         LEFT JOIN $item_table i ON ri.inventory_item_id = i.id
                         LEFT JOIN $unit_table u ON ri.unit_id = u.id
                         WHERE ri.requisition_id = %d",
                        $req->id
                    )
                );
                ?>
                <tr>
                    <td>#<?php echo esc_html( $req->id ); ?></td>
                    <td><?php echo esc_html( $req->display_name ?: $req->requested_by ); ?></td>
                    <td><?php echo esc_html( $req->department ); ?></td>
                    <td><?php echo esc_html( $req->priority ); ?></td>
                    <td><strong><?php echo esc_html( $req->status ); ?></strong></td>
                    <td>
                        <?php foreach ( $req_items as $ri ) : ?>
                            <div><?php echo esc_html( $ri->item_name . ' - ' . $ri->requested_qty . ' ' . $ri->unit_code ); ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ( $can_manage && in_array( $req->status, [ 'pending', 'approved' ], true ) ) : ?>
                            <form method="post" class="qrrs-inv-actions">
                                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                                <input type="hidden" name="qrrs_inventory_action" value="requisition_action">
                                <input type="hidden" name="requisition_id" value="<?php echo esc_attr( $req->id ); ?>">
                                <?php if ( 'pending' === $req->status ) : ?>
                                    <button class="qrrs-inv-btn secondary" name="req_action" value="approve">Approve</button>
                                    <button class="qrrs-inv-btn danger" name="req_action" value="reject">Reject</button>
                                <?php endif; ?>
                                <?php if ( 'approved' === $req->status ) : ?>
                                    <button class="qrrs-inv-btn" name="req_action" value="issue">Issue</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $requisitions ) ) : ?><tr><td colspan="7" style="text-align:center;">No requisitions found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ( 'wastage' === $section ) : ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3>Report Wastage</h3>
        <form method="post">
            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
            <input type="hidden" name="qrrs_inventory_action" value="report_wastage">
            <div class="qrrs-inv-form-grid">
                <div class="qrrs-inv-field">
                    <label>Item</label>
                    <select name="inventory_item_id" id="qrrs-wastage-item" required>
                        <?php foreach ( $active_items as $item ) : ?>
                            <option value="<?php echo esc_attr( $item->id ); ?>" data-unit="<?php echo esc_attr( $item->unit_id ); ?>"><?php echo esc_html( $item->item_name . ' - Stock: ' . $item->current_stock . ' ' . $item->unit_code ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-inv-field"><label>Quantity</label><input type="number" step="0.0001" name="quantity" required></div>
                <div class="qrrs-inv-field">
                    <label>Unit</label>
                    <select name="unit_id">
                        <?php foreach ( $units as $unit ) : ?><option value="<?php echo esc_attr( $unit->id ); ?>"><?php echo esc_html( $unit->unit_name ); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-inv-field"><label>Reason</label><input type="text" name="reason" required></div>
                <div class="qrrs-inv-field full"><label>Note</label><textarea name="note" rows="2"></textarea></div>
            </div>
            <p><button class="qrrs-inv-btn" type="submit">Save Wastage</button></p>
        </form>
    </div>

    <div class="qrrs-card" style="padding:20px;">
        <h3>Wastage List</h3>
        <?php
        $wastage_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT w.*, i.item_name, u.unit_code, usr.display_name
                 FROM $wastage_table w
                 LEFT JOIN $item_table i ON w.inventory_item_id = i.id
                 LEFT JOIN $unit_table u ON w.unit_id = u.id
                 LEFT JOIN {$wpdb->users} usr ON w.reported_by = usr.ID
                 WHERE w.restaurant_id = %d
                 ORDER BY w.id DESC LIMIT 50",
                $restaurant_id
            )
        );
        ?>
        <table class="qrrs-table">
            <thead><tr><th>Item</th><th>Qty</th><th>Reason</th><th>By</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ( $wastage_rows as $waste ) : ?>
                <tr>
                    <td><?php echo esc_html( $waste->item_name ); ?></td>
                    <td><?php echo esc_html( $waste->quantity . ' ' . $waste->unit_code ); ?></td>
                    <td><?php echo esc_html( $waste->reason ); ?></td>
                    <td><?php echo esc_html( $waste->display_name ?: $waste->reported_by ); ?></td>
                    <td><strong><?php echo esc_html( $waste->status ); ?></strong></td>
                    <td>
                        <?php if ( $can_manage && 'pending' === $waste->status ) : ?>
                            <form method="post">
                                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                                <input type="hidden" name="qrrs_inventory_action" value="approve_wastage">
                                <input type="hidden" name="wastage_id" value="<?php echo esc_attr( $waste->id ); ?>">
                                <button class="qrrs-inv-btn secondary" type="submit">Approve</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $wastage_rows ) ) : ?><tr><td colspan="6" style="text-align:center;">No wastage records found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ( 'low-stock' === $section ) : ?>
    <div class="qrrs-card" style="padding:20px;">
        <h3>Low Stock Alert</h3>
        <?php
        $low_items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*, u.unit_code, c.category_name
                 FROM $item_table i
                 LEFT JOIN $unit_table u ON i.unit_id = u.id
                 LEFT JOIN $cat_table c ON i.category_id = c.id
                 WHERE i.restaurant_id = %d AND i.status = 'active' AND i.current_stock <= i.min_stock_level
                 ORDER BY i.current_stock ASC",
                $restaurant_id
            )
        );
        ?>
        <table class="qrrs-table">
            <thead><tr><th>Item</th><th>Category</th><th>Current</th><th>Min Level</th><th>Need</th></tr></thead>
            <tbody>
            <?php foreach ( $low_items as $item ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $item->item_name ); ?></strong></td>
                    <td><?php echo esc_html( $item->category_name ?: '-' ); ?></td>
                    <td class="qrrs-low"><?php echo esc_html( $item->current_stock . ' ' . $item->unit_code ); ?></td>
                    <td><?php echo esc_html( $item->min_stock_level . ' ' . $item->unit_code ); ?></td>
                    <td><?php echo esc_html( max( 0, $item->min_stock_level - $item->current_stock ) . ' ' . $item->unit_code ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $low_items ) ) : ?><tr><td colspan="5" style="text-align:center;">No low stock items right now.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ( 'ledger' === $section ) : ?>
    <div class="qrrs-card" style="padding:20px;">
        <h3>Stock Ledger</h3>
        <?php
        $ledger_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.*, i.item_name, u.unit_code, usr.display_name
                 FROM $movement_table m
                 LEFT JOIN $item_table i ON m.inventory_item_id = i.id
                 LEFT JOIN $unit_table u ON i.unit_id = u.id
                 LEFT JOIN {$wpdb->users} usr ON m.created_by = usr.ID
                 WHERE m.restaurant_id = %d
                 ORDER BY m.id DESC LIMIT 100",
                $restaurant_id
            )
        );
        ?>
        <table class="qrrs-table">
            <thead><tr><th>Date</th><th>Item</th><th>Type</th><th>In</th><th>Out</th><th>Cost</th><th>Reference</th><th>By</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ( $ledger_rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row->created_at ); ?></td>
                    <td><?php echo esc_html( $row->item_name ); ?></td>
                    <td><?php echo esc_html( $row->movement_type ); ?></td>
                    <td class="qrrs-ok"><?php echo esc_html( $row->quantity_in > 0 ? $row->quantity_in . ' ' . $row->unit_code : '-' ); ?></td>
                    <td class="qrrs-low"><?php echo esc_html( $row->quantity_out > 0 ? $row->quantity_out . ' ' . $row->unit_code : '-' ); ?></td>
                    <td><?php echo esc_html( number_format( (float) $row->total_cost, 4 ) ); ?></td>
                    <td><?php echo esc_html( trim( $row->reference_type . ' #' . $row->reference_id ) ); ?></td>
                    <td><?php echo esc_html( $row->display_name ?: $row->created_by ); ?></td>
                    <td><?php echo esc_html( $row->note ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $ledger_rows ) ) : ?><tr><td colspan="9" style="text-align:center;">No stock movements yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
