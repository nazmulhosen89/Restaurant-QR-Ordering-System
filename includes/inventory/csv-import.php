<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bulk import of raw materials from CSV (admin / super admin only).
 *
 * Columns (in this order, header row optional):
 *   item_name, category, unit, item_type, sku, cost_per_unit,
 *   opening_stock, min_stock_level, storage_location
 *
 * Rules:
 *  - All or nothing: if any row has a problem, nothing is imported and every
 *    problem is listed with its row number.
 *  - Unit must exist (kg, g, l, ml, pcs). Common names are accepted too
 *    (liter -> l, piece -> pcs, ...).
 *  - Categories are created when missing.
 *  - An item name that already exists in this restaurant is rejected.
 *  - Opening stock is written to the stock ledger as an 'opening' entry.
 */

define( 'QRRS_CSV_MAX_BYTES', 2 * 1024 * 1024 );
define( 'QRRS_CSV_MAX_ROWS', 500 );

function qrrs_csv_unit_aliases() {
    return [
        'liter' => 'l', 'litre' => 'l', 'liters' => 'l', 'litres' => 'l',
        'piece' => 'pcs', 'pieces' => 'pcs', 'pc' => 'pcs', 'pcs' => 'pcs',
        'kilogram' => 'kg', 'kgs' => 'kg', 'gram' => 'g', 'grams' => 'g',
        'milliliter' => 'ml', 'millilitre' => 'ml',
    ];
}

function qrrs_csv_item_types() {
    return [ 'raw_material', 'consumable', 'packaging' ];
}

function qrrs_csv_store_result( array $result ) {
    set_transient( 'qrrs_csv_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
}

function qrrs_csv_redirect( $status ) {
    wp_safe_redirect( add_query_arg( [
        'tab'         => 'inventory',
        'inv_section' => 'items',
        'status'      => $status,
    ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

function qrrs_csv_handle_import( $restaurant_id ) {
    global $wpdb;
    $uid        = get_current_user_id();
    $restaurant_id = intval( $restaurant_id );

    if ( ! isset( $_POST['import_csv_nonce_field'] ) || ! wp_verify_nonce( $_POST['import_csv_nonce_field'], 'import_csv_nonce' ) ) {
        wp_die( 'Security check failed' );
    }

    $file = $_FILES['inventory_csv'] ?? null;
    if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] ) {
        qrrs_csv_store_result( [ 'errors' => [ 'File upload failed. Please choose a CSV file and try again.' ] ] );
        qrrs_csv_redirect( 'csv_failed' );
    }
    if ( 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
        qrrs_csv_store_result( [ 'errors' => [ 'Only .csv files are allowed.' ] ] );
        qrrs_csv_redirect( 'csv_failed' );
    }
    if ( (int) $file['size'] > QRRS_CSV_MAX_BYTES ) {
        qrrs_csv_store_result( [ 'errors' => [ 'File is too large. Maximum size is 2 MB.' ] ] );
        qrrs_csv_redirect( 'csv_failed' );
    }

    // ---- Read the file ----
    $handle = fopen( $file['tmp_name'], 'r' );
    if ( ! $handle ) {
        qrrs_csv_store_result( [ 'errors' => [ 'Could not read the file.' ] ] );
        qrrs_csv_redirect( 'csv_failed' );
    }
    $first = fgets( $handle );
    $delim = ( false !== strpos( (string) $first, ';' ) ) ? ';' : ',';
    rewind( $handle );

    $raw_rows = [];
    $line_no  = 0;
    while ( ( $cols = fgetcsv( $handle, 4000, $delim ) ) !== false ) {
        $line_no++;
        if ( 1 === $line_no ) {
            // Strip UTF-8 BOM that Excel adds
            $cols[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cols[0] );
        }
        if ( 1 === count( $cols ) && '' === trim( (string) $cols[0] ) ) {
            continue; // blank line
        }
        $raw_rows[] = [ 'line' => $line_no, 'cols' => $cols ];
    }
    fclose( $handle );

    // Skip header row if present
    if ( ! empty( $raw_rows ) && in_array( strtolower( trim( (string) $raw_rows[0]['cols'][0] ) ), [ 'item_name', 'name', 'item' ], true ) ) {
        array_shift( $raw_rows );
    }
    if ( empty( $raw_rows ) ) {
        qrrs_csv_store_result( [ 'errors' => [ 'The file has no data rows.' ] ] );
        qrrs_csv_redirect( 'csv_failed' );
    }
    if ( count( $raw_rows ) > QRRS_CSV_MAX_ROWS ) {
        qrrs_csv_store_result( [ 'errors' => [ 'Too many rows. Import at most ' . QRRS_CSV_MAX_ROWS . ' items at a time.' ] ] );
        qrrs_csv_redirect( 'csv_failed' );
    }

    // ---- Lookups ----
    $cat_t  = $wpdb->prefix . 'qrrs_inventory_categories';
    $unit_t = $wpdb->prefix . 'qrrs_inventory_units';
    $item_t = $wpdb->prefix . 'qrrs_inventory_items';
    $led_t  = $wpdb->prefix . 'qrrs_stock_movements';

    $units = [];
    foreach ( $wpdb->get_results( "SELECT id, unit_code FROM $unit_t WHERE status = 'active'" ) as $u ) {
        $units[ strtolower( $u->unit_code ) ] = (int) $u->id;
    }
    $cats = [];
    foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, category_name FROM $cat_t WHERE restaurant_id = %d", $restaurant_id ) ) as $c ) {
        $cats[ strtolower( $c->category_name ) ] = (int) $c->id;
    }
    $existing = [];
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT item_name FROM $item_t WHERE restaurant_id = %d", $restaurant_id ) ) as $n ) {
        $existing[ strtolower( trim( $n ) ) ] = true;
    }

    // ---- Validate every row ----
    $errors  = [];
    $clean   = [];
    $seen    = [];
    $aliases = qrrs_csv_unit_aliases();
    $types   = qrrs_csv_item_types();

    foreach ( $raw_rows as $r ) {
        $c = array_map( fn( $v ) => trim( (string) $v ), $r['cols'] );
        $ln = $r['line'];

        $name = sanitize_text_field( $c[0] ?? '' );
        $cat  = sanitize_text_field( $c[1] ?? '' );
        $unit_raw = strtolower( $c[2] ?? '' );
        $unit_key = $aliases[ $unit_raw ] ?? $unit_raw;
        $type_raw = $c[3] ?? '';
        $type = strtolower( str_replace( ' ', '_', $type_raw ) ) ?: 'raw_material';
        $sku  = sanitize_text_field( $c[4] ?? '' );
        $cost = $c[5] ?? '0';
        $open = $c[6] ?? '0';
        $min  = $c[7] ?? '0';
        $loc  = sanitize_text_field( $c[8] ?? '' );

        if ( '' === $name ) {
            $errors[] = "Row $ln: item name is missing.";
            continue;
        }
        if ( mb_strlen( $name ) > 255 ) {
            $errors[] = "Row $ln: item name is too long.";
            continue;
        }
        $key = strtolower( $name );
        if ( isset( $existing[ $key ] ) ) {
            $errors[] = "Row $ln: \"$name\" already exists in this restaurant.";
            continue;
        }
        if ( isset( $seen[ $key ] ) ) {
            $errors[] = "Row $ln: \"$name\" appears more than once in this file.";
            continue;
        }
        if ( ! isset( $units[ $unit_key ] ) ) {
            $errors[] = "Row $ln: unit \"$unit_raw\" not found. Use kg, g, l, ml or pcs.";
            continue;
        }
        if ( ! in_array( $type, $types, true ) ) {
            $errors[] = "Row $ln: item type \"$type_raw\" is not valid. Use raw_material, consumable or packaging.";
            continue;
        }
        foreach ( [ 'cost' => $cost, 'opening stock' => $open, 'min stock' => $min ] as $label => $val ) {
            if ( '' !== $val && ( ! is_numeric( $val ) || (float) $val < 0 ) ) {
                $errors[] = "Row $ln: $label must be a number, 0 or more.";
                continue 2;
            }
        }

        $seen[ $key ] = true;
        $clean[] = [
            'name' => $name, 'cat' => $cat, 'cat_key' => strtolower( $cat ),
            'unit_id' => $units[ $unit_key ], 'type' => $type, 'sku' => $sku,
            'cost' => (float) ( $cost ?: 0 ), 'open' => (float) ( $open ?: 0 ),
            'min' => (float) ( $min ?: 0 ), 'loc' => $loc,
        ];
    }

    if ( ! empty( $errors ) ) {
        qrrs_csv_store_result( [ 'errors' => $errors ] );
        qrrs_csv_redirect( 'csv_failed' );
    }

    // ---- Import everything in one transaction ----
    $now = current_time( 'mysql' );
    $wpdb->query( 'START TRANSACTION' );
    $ok = true;

    foreach ( $clean as $row ) {
        $cat_id = 0;
        if ( '' !== $row['cat'] ) {
            if ( ! isset( $cats[ $row['cat_key'] ] ) ) {
                $inserted = $wpdb->insert( $cat_t, [
                    'restaurant_id' => $restaurant_id,
                    'category_name' => $row['cat'],
                    'category_type' => 'raw_material',
                    'status'        => 'active',
                    'created_by'    => get_current_user_id(),
                    'created_at'    => $now,
                ] );
                if ( ! $inserted ) { $ok = false; break; }
                $cats[ $row['cat_key'] ] = (int) $wpdb->insert_id;
            }
            $cat_id = $cats[ $row['cat_key'] ];
        }

        $inserted = $wpdb->insert( $item_t, [
            'restaurant_id'    => $restaurant_id,
            'category_id'      => $cat_id,
            'unit_id'          => $row['unit_id'],
            'item_name'        => $row['name'],
            'item_type'        => $row['type'],
            'sku'              => $row['sku'],
            'current_stock'    => $row['open'],
            'min_stock_level'  => $row['min'],
            'cost_per_unit'    => $row['cost'],
            'storage_location' => $row['loc'],
            'status'           => 'active',
            'created_by'       => get_current_user_id(),
            'created_at'       => $now,
        ] );
        if ( ! $inserted ) { $ok = false; break; }
        $item_id = (int) $wpdb->insert_id;

        if ( $row['open'] > 0 ) {
            $led = $wpdb->insert( $led_t, [
                'restaurant_id'     => $restaurant_id,
                'inventory_item_id' => $item_id,
                'movement_type'     => 'opening',
                'quantity_in'       => $row['open'],
                'quantity_out'      => 0,
                'unit_cost'         => $row['cost'],
                'total_cost'        => round( $row['open'] * $row['cost'], 2 ),
                'reference_type'    => 'opening_stock',
                'reference_id'      => 0,
                'note'              => 'Imported via CSV',
                'created_by'        => get_current_user_id(),
                'created_at'        => $now,
            ] );
            if ( ! $led ) { $ok = false; break; }
        }
    }

    if ( ! $ok ) {
        $wpdb->query( 'ROLLBACK' );
        qrrs_csv_store_result( [ 'errors' => [ 'Database error while saving. Nothing was imported. ' . $wpdb->last_error ] ] );
        qrrs_csv_redirect( 'csv_failed' );
    }

    $wpdb->query( 'COMMIT' );
    qrrs_csv_store_result( [ 'count' => count( $clean ) ] );
    qrrs_csv_redirect( 'csv_imported' );
}

/** Upload card (admin only): sample download, upload form, last result. */
function qrrs_csv_render_panel() {
    $sample = "item_name,category,unit,item_type,sku,cost_per_unit,opening_stock,min_stock_level,storage_location\n"
            . "Maida (All Purpose Flour),Bakery,kg,raw_material,NAAN-001,70,10,5,Dry Store\n"
            . "Milk,Dairy,l,raw_material,NAAN-003,130,8,3,Chiller\n";
    $sample_href = 'data:text/csv;charset=utf-8,' . rawurlencode( $sample );

    $result = get_transient( 'qrrs_csv_result_' . get_current_user_id() );
    if ( $result ) {
        delete_transient( 'qrrs_csv_result_' . get_current_user_id() );
    }
    ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:25px;">
        <h3 style="margin-top:0;">📥 Bulk Import Items via CSV</h3>
        <?php if ( $result && ! empty( $result['count'] ) ) : ?>
            <div style="background:#dcfce7; color:#166534; padding:10px 14px; border-radius:6px; margin-bottom:12px;">
                <?php echo intval( $result['count'] ); ?> item(s) imported successfully.
            </div>
        <?php elseif ( $result && ! empty( $result['errors'] ) ) : ?>
            <div style="background:#fee2e2; color:#991b1b; padding:10px 14px; border-radius:6px; margin-bottom:12px;">
                <strong>Nothing was imported.</strong> Fix these rows and upload again:
                <ul style="margin:8px 0 0 18px; max-height:220px; overflow:auto;">
                    <?php foreach ( array_slice( $result['errors'], 0, 100 ) as $e ) : ?>
                        <li><?php echo esc_html( $e ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p style="color:#64748b; font-size:13px; margin-top:0;">
            Columns (in order): item_name, category, unit, item_type, sku, cost_per_unit, opening_stock, min_stock_level, storage_location.<br>
            Units: kg, g, l, ml, pcs. Item type: raw_material, consumable, packaging. Save as .csv (max 2 MB, 500 rows).
        </p>
        <a href="<?php echo esc_attr( $sample_href ); ?>" download="inventory-sample.csv" style="font-size:13px;">⬇ Download sample CSV</a>

        <form method="post" enctype="multipart/form-data" style="margin-top:14px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <?php wp_nonce_field( 'import_csv_nonce', 'import_csv_nonce_field' ); ?>
            <input type="file" name="inventory_csv" accept=".csv" required>
            <button type="submit" name="import_inventory_csv_action" value="1" class="button button-primary" style="padding:8px 20px; font-weight:bold;">🚀 Start Bulk Import</button>
        </form>
    </div>
    <?php
}