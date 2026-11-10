<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Purchase (Stock In bill) module.
 *  - One purchase = one supplier invoice with many item lines.
 *  - Whole purchase is saved in one DB transaction: header, lines and stock
 *    movements either all succeed or none do.
 *  - Bill attachment (JPG/PNG/PDF, max 5 MB) is stored outside the public
 *    uploads URL and served only to logged-in managers.
 *  - Bill is mandatory when the total is above the restaurant's threshold.
 */

define( 'QRRS_BILL_MAX_BYTES', 5 * 1024 * 1024 );

function qrrs_purchase_default_settings() {
    return [ 'attachment_threshold' => 5000 ];
}

/** Per-restaurant purchase settings (stored in options, keyed by restaurant id). */
function qrrs_purchase_get_settings( $restaurant_id ) {
    $all = get_option( 'qrrs_purchase_settings', [] );
    $own = isset( $all[ $restaurant_id ] ) && is_array( $all[ $restaurant_id ] ) ? $all[ $restaurant_id ] : [];
    return wp_parse_args( $own, qrrs_purchase_default_settings() );
}

function qrrs_purchase_save_settings( $restaurant_id, $threshold ) {
    $all = get_option( 'qrrs_purchase_settings', [] );
    if ( ! is_array( $all ) ) {
        $all = [];
    }
    $all[ $restaurant_id ] = [ 'attachment_threshold' => max( 0, floatval( $threshold ) ) ];
    return update_option( 'qrrs_purchase_settings', $all, false );
}

function qrrs_purchase_bill_dir( $restaurant_id ) {
    $upload = wp_upload_dir();
    $base   = trailingslashit( $upload['basedir'] ) . 'qrrs-private/bills/' . intval( $restaurant_id );
    if ( ! is_dir( $base ) ) {
        wp_mkdir_p( $base );
    }
    // Block direct web access and directory listing
    $root = trailingslashit( $upload['basedir'] ) . 'qrrs-private';
    if ( ! file_exists( $root . '/.htaccess' ) ) {
        file_put_contents( $root . '/.htaccess', "Deny from all\n" );
        file_put_contents( $root . '/index.php', "<?php // Silence is golden.\n" );
    }
    return $base;
}

/**
 * Validates and stores an uploaded bill. Returns [ 'path' => relative, 'name' => original ]
 * or WP_Error-like string code on failure.
 */
function qrrs_purchase_store_bill( $restaurant_id ) {
    if ( empty( $_FILES['bill_file'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['bill_file']['error'] ) {
        return null; // no file given
    }
    $file = $_FILES['bill_file'];
    if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
        return 'bill_upload_failed';
    }
    if ( (int) $file['size'] > QRRS_BILL_MAX_BYTES ) {
        return 'bill_too_large';
    }

    $allowed = [ 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' ];
    $check   = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
    if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
        return 'bill_type_not_allowed';
    }

    $dir      = qrrs_purchase_bill_dir( $restaurant_id );
    $new_name = wp_generate_password( 32, false, false ) . '.' . $check['ext'];
    $dest     = $dir . '/' . $new_name;

    if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
        return 'bill_upload_failed';
    }

    return [
        'path' => 'qrrs-private/bills/' . intval( $restaurant_id ) . '/' . $new_name,
        'name' => sanitize_file_name( $file['name'] ),
    ];
}

function qrrs_purchase_redirect( $status ) {
    wp_safe_redirect( add_query_arg( [
        'tab'         => 'inventory',
        'inv_section' => 'stock-in',
        'status'      => $status,
    ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

/**
 * Handles the Stock In / Purchase form.
 * Expected POST:
 *   supplier_id, invoice_no, invoice_date, notes,
 *   item_id[], qty[], unit_cost[]   (one entry per line; empty rows are skipped)
 *   bill_file                       (optional file, required above threshold)
 */
function qrrs_purchase_handle_save( $restaurant_id ) {
    global $wpdb;
    $p_table    = $wpdb->prefix . 'qrrs_purchases';
    $pi_table   = $wpdb->prefix . 'qrrs_purchase_items';
    $item_table = $wpdb->prefix . 'qrrs_inventory_items';
    $sup_table  = $wpdb->prefix . 'qrrs_suppliers';

    if ( ! isset( $_POST['qrrs_inventory_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_inventory_nonce'], 'qrrs_inventory_action' ) ) {
        wp_die( 'Security check failed' );
    }

    // 1) Supplier
    $supplier_id = intval( $_POST['supplier_id'] ?? 0 );
    $supplier    = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, status FROM $sup_table WHERE id = %d AND restaurant_id = %d", $supplier_id, $restaurant_id
    ) );
    if ( ! $supplier || 'active' !== $supplier->status ) {
        qrrs_purchase_redirect( 'purchase_supplier_invalid' );
    }

    // 2) Invoice number and date
    $invoice_no = sanitize_text_field( wp_unslash( $_POST['invoice_no'] ?? '' ) );
    if ( '' === trim( $invoice_no ) || strlen( $invoice_no ) > 100 ) {
        qrrs_purchase_redirect( 'purchase_invoice_required' );
    }
    $invoice_date = sanitize_text_field( $_POST['invoice_date'] ?? '' );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $invoice_date ) ) {
        $invoice_date = current_time( 'Y-m-d' );
    }

    $dup = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $p_table WHERE restaurant_id = %d AND supplier_id = %d AND invoice_no = %s",
        $restaurant_id, $supplier_id, $invoice_no
    ) );
    if ( $dup ) {
        qrrs_purchase_redirect( 'purchase_duplicate_invoice' );
    }

    // 3) Item lines
    $item_ids  = isset( $_POST['item_id'] ) && is_array( $_POST['item_id'] ) ? $_POST['item_id'] : [];
    $qtys      = isset( $_POST['qty'] ) && is_array( $_POST['qty'] ) ? $_POST['qty'] : [];
    $costs     = isset( $_POST['unit_cost'] ) && is_array( $_POST['unit_cost'] ) ? $_POST['unit_cost'] : [];

    $lines = [];
    foreach ( $item_ids as $i => $raw_id ) {
        $item_id = intval( $raw_id );
        $qty     = floatval( $qtys[ $i ] ?? 0 );
        $cost    = floatval( $costs[ $i ] ?? 0 );
        if ( 0 === $item_id && $qty <= 0 ) {
            continue; // empty row
        }
        if ( $item_id <= 0 || $qty <= 0 || $cost < 0 ) {
            qrrs_purchase_redirect( 'purchase_line_invalid' );
        }
        $lines[] = [ 'item_id' => $item_id, 'qty' => round( $qty, 4 ), 'cost' => round( $cost, 2 ) ];
    }
    if ( empty( $lines ) ) {
        qrrs_purchase_redirect( 'purchase_no_items' );
    }

    // Every item must belong to this restaurant and be active
    $ids_sql = implode( ',', array_unique( array_map( fn( $l ) => $l['item_id'], $lines ) ) );
    $valid   = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM $item_table WHERE restaurant_id = %d AND status = 'active' AND id IN ($ids_sql)",
        $restaurant_id
    ) );
    $valid = array_map( 'intval', $valid );
    foreach ( $lines as $l ) {
        if ( ! in_array( $l['item_id'], $valid, true ) ) {
            qrrs_purchase_redirect( 'purchase_line_invalid' );
        }
    }

    $total = 0;
    foreach ( $lines as &$l ) {
        $l['total'] = round( $l['qty'] * $l['cost'], 2 );
        $total     += $l['total'];
    }
    unset( $l );
    $total = round( $total, 2 );

    // 4) Bill attachment rules
    $settings  = qrrs_purchase_get_settings( $restaurant_id );
    $threshold = floatval( $settings['attachment_threshold'] );
    $bill      = qrrs_purchase_store_bill( $restaurant_id );

    if ( is_string( $bill ) ) {
        qrrs_purchase_redirect( $bill );
    }
    if ( null === $bill && $total > $threshold ) {
        qrrs_purchase_redirect( 'bill_required' );
    }

    // 5) Save everything in one transaction
    $wpdb->query( 'START TRANSACTION' );
    $fail = function ( $status ) use ( $wpdb, $bill ) {
        $wpdb->query( 'ROLLBACK' );
        if ( is_array( $bill ) ) {
            @unlink( trailingslashit( wp_upload_dir()['basedir'] ) . $bill['path'] );
        }
        qrrs_purchase_redirect( $status );
    };

    $header_ok = $wpdb->insert( $p_table, [
        'restaurant_id'      => $restaurant_id,
        'supplier_id'        => $supplier_id,
        'invoice_no'         => $invoice_no,
        'invoice_date'       => $invoice_date,
        'total_amount'       => $total,
        'bill_file'          => is_array( $bill ) ? $bill['path'] : '',
        'bill_original_name' => is_array( $bill ) ? $bill['name'] : '',
        'notes'              => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
        'created_by'         => get_current_user_id(),
        'created_at'         => current_time( 'mysql' ),
    ] );
    if ( ! $header_ok ) {
        $fail( 'purchase_save_failed' );
    }
    $purchase_id = (int) $wpdb->insert_id;

    foreach ( $lines as $l ) {
        $line_ok = $wpdb->insert( $pi_table, [
            'purchase_id'       => $purchase_id,
            'restaurant_id'     => $restaurant_id,
            'inventory_item_id' => $l['item_id'],
            'quantity'          => $l['qty'],
            'unit_cost'         => $l['cost'],
            'line_total'        => $l['total'],
        ] );
        if ( ! $line_ok ) {
            $fail( 'purchase_save_failed' );
        }

        $stock_ok = qrrs_inventory_add_stock_movement( [
            'restaurant_id'     => $restaurant_id,
            'inventory_item_id' => $l['item_id'],
            'movement_type'     => 'purchase',
            'quantity_in'       => $l['qty'],
            'quantity_out'      => 0,
            'unit_cost'         => $l['cost'],
            'reference_type'    => 'purchase',
            'reference_id'      => $purchase_id,
            'note'              => 'Invoice ' . $invoice_no,
            'created_by'        => get_current_user_id(),
            'in_transaction'    => true,
        ] );
        if ( false === $stock_ok ) {
            $fail( 'purchase_stock_failed' );
        }
    }

    $wpdb->query( 'COMMIT' );
    qrrs_purchase_redirect( 'purchase_saved' );
}

/**
 * Serves a stored bill to a logged-in manager of the same restaurant.
 * URL: admin-post.php?action=qrrs_download_bill&id=PURCHASE_ID&_wpnonce=...
 */
add_action( 'admin_post_qrrs_download_bill', function () {
    global $wpdb;
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) {
        wp_die( 'Not allowed' );
    }
    $id = intval( $_GET['id'] ?? 0 );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'qrrs_download_bill_' . $id ) ) {
        wp_die( 'Security check failed' );
    }
    $restaurant_id = qrrs_inventory_get_active_restaurant_id();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT bill_file, bill_original_name FROM {$wpdb->prefix}qrrs_purchases WHERE id = %d AND restaurant_id = %d",
        $id, $restaurant_id
    ) );
    if ( ! $row || '' === $row->bill_file ) {
        wp_die( 'Bill not found' );
    }
    $path = trailingslashit( wp_upload_dir()['basedir'] ) . $row->bill_file;
    if ( ! is_file( $path ) ) {
        wp_die( 'Bill file missing' );
    }
    $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
    $mime = 'pdf' === $ext ? 'application/pdf' : ( 'png' === $ext ? 'image/png' : 'image/jpeg' );
    nocache_headers();
    header( 'Content-Type: ' . $mime );
    header( 'Content-Disposition: inline; filename="' . basename( $row->bill_original_name ?: $path ) . '"' );
    header( 'Content-Length: ' . filesize( $path ) );
    readfile( $path );
    exit;
} );

/** Saves the purchase settings card (bill attachment threshold). */
function qrrs_purchase_handle_settings( $restaurant_id ) {
    if ( ! isset( $_POST['qrrs_inventory_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_inventory_nonce'], 'qrrs_inventory_action' ) ) {
        wp_die( 'Security check failed' );
    }
    $threshold = floatval( $_POST['attachment_threshold'] ?? 0 );
    qrrs_purchase_save_settings( $restaurant_id, $threshold );
    wp_safe_redirect( add_query_arg( [
        'tab'         => 'inventory',
        'inv_section' => 'overview',
        'status'      => 'purchase_settings_saved',
    ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

/** Status messages for the Stock In / purchase page. */
function qrrs_purchase_status_messages() {
    return [
        'purchase_saved'             => [ 'ok',  'Purchase saved and stock updated.' ],
        'purchase_supplier_invalid'  => [ 'err', 'Please select an active supplier.' ],
        'purchase_invoice_required'  => [ 'err', 'Invoice number is required.' ],
        'purchase_duplicate_invoice' => [ 'err', 'This invoice number already exists for this supplier.' ],
        'purchase_no_items'          => [ 'err', 'Add at least one item to the purchase.' ],
        'purchase_line_invalid'      => [ 'err', 'Check item lines: each needs an item, a quantity above 0 and a valid cost.' ],
        'bill_required'              => [ 'err', 'Bill attachment is required for purchases above the set limit.' ],
        'bill_too_large'             => [ 'err', 'Bill file must be 5 MB or smaller.' ],
        'bill_type_not_allowed'      => [ 'err', 'Bill must be a JPG, PNG or PDF file.' ],
        'bill_upload_failed'         => [ 'err', 'Bill upload failed. Please try again.' ],
        'purchase_save_failed'       => [ 'err', 'Purchase could not be saved. Nothing was changed.' ],
        'purchase_stock_failed'      => [ 'err', 'Stock could not be updated. Nothing was saved.' ],
    ];
}

/** Renders the Stock In / Purchase page: supplier, invoice, bill, and many item lines. */
function qrrs_purchase_render_form( $restaurant_id, $can_manage ) {
    global $wpdb;

    $msgs = qrrs_purchase_status_messages();
    $pst  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
    if ( isset( $msgs[ $pst ] ) ) {
        [ $kind, $text ] = $msgs[ $pst ];
        $bg = 'ok' === $kind ? '#dcfce7' : '#fee2e2';
        $fg = 'ok' === $kind ? '#166534' : '#991b1b';
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:10px 14px; border-radius:6px; margin-bottom:12px;">' . esc_html( $text ) . '</div>';
    }

    if ( ! $can_manage ) {
        echo '<div class="qrrs-card" style="padding:20px;">You do not have access to Stock In.</div>';
        return;
    }

    $suppliers = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, supplier_name FROM {$wpdb->prefix}qrrs_suppliers WHERE restaurant_id = %d AND status = 'active' ORDER BY supplier_name ASC",
        $restaurant_id
    ) );
    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT i.id, i.item_name, i.cost_per_unit, u.unit_code
         FROM {$wpdb->prefix}qrrs_inventory_items i
         LEFT JOIN {$wpdb->prefix}qrrs_inventory_units u ON u.id = i.unit_id
         WHERE i.restaurant_id = %d AND i.status = 'active'
         ORDER BY i.item_name ASC",
        $restaurant_id
    ) );

    $threshold  = floatval( qrrs_purchase_get_settings( $restaurant_id )['attachment_threshold'] );
    $action_url = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'stock-in' ], home_url( '/restaurant-dashboard/' ) );

    // Item options (with default cost and unit) reused for every line
    $item_options = '<option value="">Select item</option>';
    foreach ( $items as $it ) {
        $label = $it->item_name . ( $it->unit_code ? ' (' . $it->unit_code . ')' : '' );
        $item_options .= '<option value="' . intval( $it->id ) . '" data-cost="' . esc_attr( $it->cost_per_unit ) . '">' . esc_html( $label ) . '</option>';
    }
    ?>
    <div class="qrrs-card" style="padding:20px;">
        <h3 style="margin-top:0;">Stock In / Purchase</h3>
        <p style="color:#64748b; font-size:13px; margin-top:-6px;">One bill can contain many items. Add all lines, then save once.</p>

        <?php if ( empty( $suppliers ) ) : ?>
            <div style="background:#fef3c7; color:#92400e; padding:10px 14px; border-radius:6px;">No active supplier yet. Add one in the Suppliers tab first.</div>
        <?php elseif ( empty( $items ) ) : ?>
            <div style="background:#fef3c7; color:#92400e; padding:10px 14px; border-radius:6px;">No active raw material yet. Add items in the Raw Materials tab first.</div>
        <?php else : ?>
        <form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" id="qrrs-purchase-form">
            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
            <input type="hidden" name="qrrs_inventory_action" value="save_purchase">

            <div class="qrrs-form-grid">
                <div class="qrrs-input-group">
                    <label>Supplier *</label>
                    <select name="supplier_id" required>
                        <option value="">Select supplier</option>
                        <?php foreach ( $suppliers as $sp ) : ?>
                            <option value="<?php echo intval( $sp->id ); ?>"><?php echo esc_html( $sp->supplier_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-input-group">
                    <label>Invoice / Bill No *</label>
                    <input type="text" name="invoice_no" maxlength="100" required placeholder="e.g. INV-2026-0041">
                </div>
                <div class="qrrs-input-group">
                    <label>Invoice Date</label>
                    <input type="date" name="invoice_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
                </div>
                <div class="qrrs-input-group">
                    <label>Bill Attachment</label>
                    <input type="file" name="bill_file" accept=".jpg,.jpeg,.png,.pdf">
                    <small style="color:#64748b;">
                        JPG, PNG or PDF, max 5 MB. <?php echo $threshold > 0 ? 'Required when total is above ৳' . esc_html( number_format( $threshold, 2 ) ) . '.' : ''; ?>
                    </small>
                </div>
            </div>

            <div class="qrrs-input-group" style="margin-top:10px;">
                <label>Notes</label>
                <input type="text" name="notes" maxlength="255" placeholder="Optional">
            </div>

            <h4 style="margin:22px 0 8px;">Items in this bill</h4>
            <table class="qrrs-table" style="width:100%; border-collapse:collapse;" id="qrrs-purchase-lines">
                <thead>
                    <tr style="background:#f1f5f9; text-align:left;">
                        <th style="padding:8px;">Item</th>
                        <th style="padding:8px; width:130px;">Quantity</th>
                        <th style="padding:8px; width:150px;">Unit Cost (৳)</th>
                        <th style="padding:8px; width:140px;">Line Total</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right; padding:10px; font-weight:bold;">Bill Total</td>
                        <td style="padding:10px; font-weight:bold;" id="qrrs-purchase-total">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <div style="margin-top:10px; display:flex; gap:10px;">
                <button type="button" class="button" id="qrrs-add-line">+ Add item line</button>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" class="qrrs-btn-save">Save Purchase</button>
            </div>
        </form>

        <template id="qrrs-line-template">
            <tr>
                <td style="padding:6px;"><select name="item_id[]" class="qrrs-line-item" style="width:100%;"><?php echo $item_options; ?></select></td>
                <td style="padding:6px;"><input type="number" name="qty[]" step="0.0001" min="0" class="qrrs-line-qty" style="width:100%;"></td>
                <td style="padding:6px;"><input type="number" name="unit_cost[]" step="0.01" min="0" class="qrrs-line-cost" style="width:100%;"></td>
                <td style="padding:6px;" class="qrrs-line-total">0.00</td>
                <td style="padding:6px;"><button type="button" class="qrrs-line-remove" title="Remove line" style="color:#dc2626; background:none; border:none; cursor:pointer; font-size:16px;">✕</button></td>
            </tr>
        </template>

        <script>
        (function () {
            var form   = document.getElementById('qrrs-purchase-form');
            if (!form) return;
            var tbody  = document.querySelector('#qrrs-purchase-lines tbody');
            var tpl    = document.getElementById('qrrs-line-template');
            var totalE = document.getElementById('qrrs-purchase-total');

            function recalc() {
                var grand = 0;
                tbody.querySelectorAll('tr').forEach(function (tr) {
                    var q = parseFloat(tr.querySelector('.qrrs-line-qty').value) || 0;
                    var c = parseFloat(tr.querySelector('.qrrs-line-cost').value) || 0;
                    var t = Math.round(q * c * 100) / 100;
                    tr.querySelector('.qrrs-line-total').textContent = t.toFixed(2);
                    grand += t;
                });
                totalE.textContent = (Math.round(grand * 100) / 100).toFixed(2);
            }

            function addLine() {
                var node = tpl.content.cloneNode(true);
                tbody.appendChild(node);
                var tr = tbody.lastElementChild;
                tr.querySelector('.qrrs-line-item').addEventListener('change', function (e) {
                    var opt  = e.target.options[e.target.selectedIndex];
                    var cost = tr.querySelector('.qrrs-line-cost');
                    if (opt && opt.dataset.cost && !cost.value) { cost.value = opt.dataset.cost; }
                    recalc();
                });
                tr.querySelectorAll('.qrrs-line-qty, .qrrs-line-cost').forEach(function (el) {
                    el.addEventListener('input', recalc);
                });
                tr.querySelector('.qrrs-line-remove').addEventListener('click', function () {
                    if (tbody.rows.length > 1) { tr.remove(); } else { tr.querySelector('.qrrs-line-item').value = ''; tr.querySelector('.qrrs-line-qty').value = ''; tr.querySelector('.qrrs-line-cost').value = ''; }
                    recalc();
                });
                recalc();
            }

            document.getElementById('qrrs-add-line').addEventListener('click', addLine);
            for (var i = 0; i < 3; i++) { addLine(); }
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php
}