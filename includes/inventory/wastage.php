<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wastage module.
 *  - Kitchen / waiter / manager report wastage with a reason from a fixed list.
 *  - Staff reports stay "pending" until a manager approves or rejects them.
 *  - Stock is only reduced when a wastage is approved, inside one transaction:
 *    the status change and the stock movement succeed together or not at all.
 *  - Stock can never go below zero: a wastage larger than current stock is refused.
 *  - Each wastage stores its unit cost and total value for loss reporting.
 */

function qrrs_wastage_reasons() {
    return [
        'expired'         => 'Expired',
        'spoiled'         => 'Spoiled',
        'burnt'           => 'Burnt / cooking error',
        'breakage'        => 'Breakage / dropped',
        'theft'           => 'Theft / missing',
        'customer_return' => 'Customer returned',
        'other'           => 'Other',
    ];
}

function qrrs_wastage_redirect( $status ) {
    if ( ! empty( $GLOBALS['qrrs_wastage_flash_mode'] ) ) {
        // Kitchen/waiter panels are rendered inside a page that already started output,
        // so instead of redirecting we hand the status back to the panel.
        $GLOBALS['qrrs_wastage_flash'] = $status;
        throw new RuntimeException( 'qrrs_wastage_flash' );
    }
    wp_safe_redirect( add_query_arg( [
        'tab'         => 'inventory',
        'inv_section' => 'wastage',
        'status'      => $status,
    ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

function qrrs_wastage_handle_actions( $restaurant_id, $can_request, $can_manage ) {
    global $wpdb;
    $w_table    = $wpdb->prefix . 'qrrs_wastage';
    $item_table = $wpdb->prefix . 'qrrs_inventory_items';
    $action     = sanitize_key( $_POST['qrrs_inventory_action'] ?? '' );

    if ( ! isset( $_POST['qrrs_inventory_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_inventory_nonce'], 'qrrs_inventory_action' ) ) {
        wp_die( 'Security check failed' );
    }

    // ---- Report wastage (staff: pending, manager: approved immediately) ----
    if ( 'report_wastage' === $action && $can_request ) {
        $item_id = intval( $_POST['inventory_item_id'] ?? 0 );
        $qty     = round( floatval( $_POST['quantity'] ?? 0 ), 4 );
        $reason  = sanitize_key( $_POST['reason_code'] ?? '' );
        $note    = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        if ( $qty <= 0 ) {
            qrrs_wastage_redirect( 'wastage_qty_invalid' );
        }
        if ( ! isset( qrrs_wastage_reasons()[ $reason ] ) ) {
            qrrs_wastage_redirect( 'wastage_reason_required' );
        }

        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, unit_id, cost_per_unit, current_stock FROM $item_table
             WHERE id = %d AND restaurant_id = %d AND status = 'active'",
            $item_id, $restaurant_id
        ) );
        if ( ! $item ) {
            qrrs_wastage_redirect( 'wastage_item_invalid' );
        }
        if ( $qty > (float) $item->current_stock ) {
            qrrs_wastage_redirect( 'wastage_stock_low' );
        }

        $unit_cost   = (float) $item->cost_per_unit;
        $total_value = round( $qty * $unit_cost, 2 );
        $now         = current_time( 'mysql' );
        $approve_now = (bool) $can_manage;

        $wpdb->query( 'START TRANSACTION' );
        $ok = $wpdb->insert( $w_table, [
            'restaurant_id'     => $restaurant_id,
            'inventory_item_id' => $item_id,
            'quantity'          => $qty,
            'unit_id'           => intval( $item->unit_id ),
            'reason'            => qrrs_wastage_reasons()[ $reason ],
            'reason_code'       => $reason,
            'note'              => $note,
            'unit_cost'         => $unit_cost,
            'total_value'       => $total_value,
            'status'            => $approve_now ? 'approved' : 'pending',
            'reported_by'       => get_current_user_id(),
            'approved_by'       => $approve_now ? get_current_user_id() : 0,
            'approved_at'       => $approve_now ? $now : null,
            'created_at'        => $now,
        ] );
        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
            qrrs_wastage_redirect( 'error' );
        }
        $wastage_id = (int) $wpdb->insert_id;

        if ( $approve_now ) {
            $mv = qrrs_inventory_add_stock_movement( [
                'restaurant_id'     => $restaurant_id,
                'inventory_item_id' => $item_id,
                'movement_type'     => 'wastage',
                'quantity_out'      => $qty,
                'unit_cost'         => $unit_cost,
                'reference_type'    => 'wastage',
                'reference_id'      => $wastage_id,
                'note'              => qrrs_wastage_reasons()[ $reason ] . ( $note ? ': ' . $note : '' ),
                'created_by'        => get_current_user_id(),
                'in_transaction'    => true,
            ] );
            if ( false === $mv ) {
                $wpdb->query( 'ROLLBACK' );
                qrrs_wastage_redirect( 'wastage_stock_low' );
            }
        }
        $wpdb->query( 'COMMIT' );
        qrrs_wastage_redirect( $approve_now ? 'wastage_approved' : 'wastage_saved' );
    }

    // ---- Approve pending wastage (manager) ----
    if ( 'approve_wastage' === $action && $can_manage ) {
        $wastage_id = intval( $_POST['wastage_id'] ?? 0 );

        $wpdb->query( 'START TRANSACTION' );
        $w = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $w_table WHERE id = %d AND restaurant_id = %d AND status = 'pending' FOR UPDATE",
            $wastage_id, $restaurant_id
        ) );
        if ( ! $w ) {
            $wpdb->query( 'ROLLBACK' );
            qrrs_wastage_redirect( 'error' );
        }

        $mv = qrrs_inventory_add_stock_movement( [
            'restaurant_id'     => $restaurant_id,
            'inventory_item_id' => $w->inventory_item_id,
            'movement_type'     => 'wastage',
            'quantity_out'      => $w->quantity,
            'unit_cost'         => $w->unit_cost,
            'reference_type'    => 'wastage',
            'reference_id'      => $wastage_id,
            'note'              => $w->reason . ( $w->note ? ': ' . $w->note : '' ),
            'created_by'        => get_current_user_id(),
            'in_transaction'    => true,
        ] );
        if ( false === $mv ) {
            $wpdb->query( 'ROLLBACK' );
            qrrs_wastage_redirect( 'wastage_stock_low' );
        }

        $wpdb->update(
            $w_table,
            [ 'status' => 'approved', 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql' ) ],
            [ 'id' => $wastage_id, 'restaurant_id' => $restaurant_id ]
        );
        $wpdb->query( 'COMMIT' );
        qrrs_wastage_redirect( 'wastage_approved' );
    }

    // ---- Reject pending wastage (manager) ----
    if ( 'reject_wastage' === $action && $can_manage ) {
        $wastage_id = intval( $_POST['wastage_id'] ?? 0 );
        $why        = sanitize_text_field( wp_unslash( $_POST['reject_reason'] ?? '' ) );
        if ( '' === trim( $why ) ) {
            qrrs_wastage_redirect( 'wastage_reject_reason_required' );
        }
        $wpdb->update(
            $w_table,
            [ 'status' => 'rejected', 'reject_reason' => $why, 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql' ) ],
            [ 'id' => $wastage_id, 'restaurant_id' => $restaurant_id, 'status' => 'pending' ]
        );
        qrrs_wastage_redirect( 'wastage_rejected' );
    }
}

function qrrs_wastage_render( $restaurant_id, $can_request, $can_manage, $form_action = null, $flash_status = '' ) {
    global $wpdb;
    $w_table    = $wpdb->prefix . 'qrrs_wastage';
    $item_table = $wpdb->prefix . 'qrrs_inventory_items';
    $unit_table = $wpdb->prefix . 'qrrs_inventory_units';
    $reasons    = qrrs_wastage_reasons();

    $msgs = [
        'wastage_saved'                  => [ 'ok',  'Wastage reported and waiting for manager approval.' ],
        'wastage_approved'               => [ 'ok',  'Wastage approved and stock updated.' ],
        'wastage_rejected'               => [ 'ok',  'Wastage rejected. Stock was not changed.' ],
        'wastage_qty_invalid'            => [ 'err', 'Quantity must be greater than 0.' ],
        'wastage_reason_required'        => [ 'err', 'Please choose a reason.' ],
        'wastage_item_invalid'           => [ 'err', 'Please choose an active item.' ],
        'wastage_stock_low'              => [ 'err', 'Not enough stock for this wastage. Adjust the stock first.' ],
        'wastage_reject_reason_required' => [ 'err', 'Write a reason before rejecting.' ],
        'error'                          => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    $st = $flash_status ? sanitize_key( $flash_status ) : ( isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '' );
    if ( isset( $msgs[ $st ] ) ) {
        [ $kind, $text ] = $msgs[ $st ];
        $bg = 'ok' === $kind ? '#dcfce7' : '#fee2e2';
        $fg = 'ok' === $kind ? '#166534' : '#991b1b';
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:10px 14px; border-radius:6px; margin-bottom:12px;">' . esc_html( $text ) . '</div>';
    }

    if ( ! $can_request && ! $can_manage ) {
        echo '<div class="qrrs-card" style="padding:20px;">You do not have access to Wastage.</div>';
        return;
    }

    // Month filter for the summary (YYYY-MM)
    $month = isset( $_GET['wm'] ) && preg_match( '/^\d{4}-\d{2}$/', $_GET['wm'] ) ? $_GET['wm'] : current_time( 'Y-m' );
    $from  = $month . '-01 00:00:00';
    $to    = date( 'Y-m-d 23:59:59', strtotime( $from . ' +1 month -1 day' ) );

    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT i.id, i.item_name, i.current_stock, u.unit_code
         FROM $item_table i LEFT JOIN $unit_table u ON u.id = i.unit_id
         WHERE i.restaurant_id = %d AND i.status = 'active' ORDER BY i.item_name ASC",
        $restaurant_id
    ) );

    $base = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'wastage' ], home_url( '/restaurant-dashboard/' ) );
    $act  = null === $form_action ? $base : $form_action;
    ?>
    <?php if ( $can_request ) : ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">Report Wastage</h3>
        <form method="post" action="<?php echo esc_url( $act ); ?>">
            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
            <input type="hidden" name="qrrs_inventory_action" value="report_wastage">
            <div class="qrrs-form-grid">
                <div class="qrrs-input-group">
                    <label>Item *</label>
                    <select name="inventory_item_id" required>
                        <option value="">Select item</option>
                        <?php foreach ( $items as $it ) : ?>
                            <option value="<?php echo intval( $it->id ); ?>"><?php echo esc_html( $it->item_name . ' — stock: ' . rtrim( rtrim( number_format( $it->current_stock, 3, '.', '' ), '0' ), '.' ) . ' ' . $it->unit_code ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-input-group">
                    <label>Quantity *</label>
                    <input type="number" step="0.0001" min="0.0001" name="quantity" required>
                </div>
                <div class="qrrs-input-group">
                    <label>Reason *</label>
                    <select name="reason_code" required>
                        <option value="">Select reason</option>
                        <?php foreach ( $reasons as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-input-group">
                    <label>Note</label>
                    <input type="text" name="note" maxlength="255" placeholder="Optional">
                </div>
            </div>
            <button type="submit" class="qrrs-btn-save" style="margin-top:12px;">Submit Wastage</button>
        </form>
    </div>
    <?php endif; ?>

    <?php
    $pending = $can_manage ? $wpdb->get_results( $wpdb->prepare(
        "SELECT w.*, i.item_name, u.unit_code, usr.display_name
         FROM $w_table w
         LEFT JOIN $item_table i ON i.id = w.inventory_item_id
         LEFT JOIN $unit_table u ON u.id = w.unit_id
         LEFT JOIN {$wpdb->users} usr ON usr.ID = w.reported_by
         WHERE w.restaurant_id = %d AND w.status = 'pending'
         ORDER BY w.id ASC",
        $restaurant_id
    ) ) : [];
    ?>
    <?php if ( $can_manage ) : ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">Pending Approval (<?php echo count( $pending ); ?>)</h3>
        <?php if ( empty( $pending ) ) : ?>
            <p style="color:#94a3b8;">Nothing waiting for approval.</p>
        <?php else : ?>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Item</th><th>Qty</th><th>Reason</th><th>Value</th><th>Reported by</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ( $pending as $p ) : ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo esc_html( $p->item_name ); ?></td>
                    <td><?php echo esc_html( rtrim( rtrim( number_format( $p->quantity, 3, '.', '' ), '0' ), '.' ) . ' ' . $p->unit_code ); ?></td>
                    <td><?php echo esc_html( $p->reason ); ?><?php if ( $p->note ) echo '<br><small style="color:#64748b;">' . esc_html( $p->note ) . '</small>'; ?></td>
                    <td>৳<?php echo esc_html( number_format( $p->total_value, 2 ) ); ?></td>
                    <td><?php echo esc_html( $p->display_name ?: '—' ); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url( $act ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                            <input type="hidden" name="qrrs_inventory_action" value="approve_wastage">
                            <input type="hidden" name="wastage_id" value="<?php echo intval( $p->id ); ?>">
                            <button type="submit" class="button">Approve</button>
                        </form>
                        <form method="post" action="<?php echo esc_url( $act ); ?>" style="display:inline; margin-left:6px;" onsubmit="var r=prompt('Reason for rejecting:'); if(!r){return false;} this.reject_reason.value=r; return true;">
                            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                            <input type="hidden" name="qrrs_inventory_action" value="reject_wastage">
                            <input type="hidden" name="wastage_id" value="<?php echo intval( $p->id ); ?>">
                            <input type="hidden" name="reject_reason" value="">
                            <button type="submit" class="button" style="color:#dc2626;">Reject</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    $summary = $wpdb->get_results( $wpdb->prepare(
        "SELECT reason, COUNT(*) AS cnt, SUM(quantity) AS qty, SUM(total_value) AS value
         FROM $w_table
         WHERE restaurant_id = %d AND status = 'approved' AND approved_at BETWEEN %s AND %s
         GROUP BY reason ORDER BY value DESC",
        $restaurant_id, $from, $to
    ) );
    $grand_value = 0;
    foreach ( $summary as $s ) { $grand_value += (float) $s->value; }
    ?>
    <?php if ( $can_manage ) : ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h3 style="margin:0;">Wastage Summary</h3>
            <form method="get" style="margin:0;">
                <input type="hidden" name="tab" value="inventory">
                <input type="hidden" name="inv_section" value="wastage">
                <input type="month" name="wm" value="<?php echo esc_attr( $month ); ?>">
                <button type="submit" class="button">Show</button>
            </form>
        </div>
        <p style="margin:12px 0; font-size:18px;"><strong>Total loss: ৳<?php echo esc_html( number_format( $grand_value, 2 ) ); ?></strong> <span style="color:#64748b; font-size:13px;">(approved wastage, <?php echo esc_html( $month ); ?>)</span></p>
        <?php if ( empty( $summary ) ) : ?>
            <p style="color:#94a3b8;">No approved wastage this month.</p>
        <?php else : ?>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Reason</th><th>Entries</th><th>Value (৳)</th></tr></thead>
            <tbody>
            <?php foreach ( $summary as $s ) : ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo esc_html( $s->reason ); ?></td>
                    <td><?php echo intval( $s->cnt ); ?></td>
                    <td>৳<?php echo esc_html( number_format( (float) $s->value, 2 ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php endif; ?>

    <?php
    $recent = $wpdb->get_results( $wpdb->prepare(
        "SELECT w.*, i.item_name, u.unit_code FROM $w_table w
         LEFT JOIN $item_table i ON i.id = w.inventory_item_id
         LEFT JOIN $unit_table u ON u.id = w.unit_id
         WHERE w.restaurant_id = %d ORDER BY w.id DESC LIMIT 30",
        $restaurant_id
    ) );
    ?>
    <div class="qrrs-card" style="padding:20px;">
        <h3 style="margin-top:0;">Recent Wastage</h3>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Date</th><th>Item</th><th>Qty</th><th>Reason</th><?php if ( $can_manage ) : ?><th>Value</th><?php endif; ?><th>Status</th></tr></thead>
            <tbody>
            <?php if ( $recent ) : foreach ( $recent as $r ) :
                $badge = [ 'pending' => '#fef3c7;#92400e', 'approved' => '#dcfce7;#166534', 'rejected' => '#fee2e2;#991b1b' ][ $r->status ] ?? '#e2e8f0;#334155';
                [ $bg, $fg ] = explode( ';', $badge );
            ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo esc_html( substr( $r->created_at, 0, 16 ) ); ?></td>
                    <td><?php echo esc_html( $r->item_name ); ?></td>
                    <td><?php echo esc_html( rtrim( rtrim( number_format( $r->quantity, 3, '.', '' ), '0' ), '.' ) . ' ' . $r->unit_code ); ?></td>
                    <td><?php echo esc_html( $r->reason ); ?></td>
                    <?php if ( $can_manage ) : ?><td>৳<?php echo esc_html( number_format( (float) $r->total_value, 2 ) ); ?></td><?php endif; ?>
                    <td><span style="background:<?php echo $bg; ?>; color:<?php echo $fg; ?>; padding:2px 8px; border-radius:10px; font-size:12px;"><?php echo esc_html( ucfirst( $r->status ) ); ?></span>
                        <?php if ( 'rejected' === $r->status && $r->reject_reason ) echo '<br><small style="color:#64748b;">' . esc_html( $r->reject_reason ) . '</small>'; ?></td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No wastage recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Wastage panel for kitchen and waiter dashboards (no redirect, same as requisition panel).
 * Staff can report; only managers see approvals and money values.
 */
function qrrs_wastage_panel( $department = '' ) {
    global $wpdb;
    if ( ! is_user_logged_in() ) {
        echo '<div style="padding:25px;">Please log in.</div>';
        return;
    }

    $rid = 0;
    foreach ( [ 'assigned_restaurant', 'restaurant_id', 'qrrs_restaurant_id' ] as $key ) {
        $rid = intval( get_user_meta( get_current_user_id(), $key, true ) );
        if ( $rid ) break;
    }
    if ( ! $rid ) {
        echo '<div style="padding:25px; background:#fff; border-radius:10px;">No restaurant assigned for wastage.</div>';
        return;
    }

    $flash = '';
    if ( ! empty( $_POST['qrrs_inventory_action'] ) && 'report_wastage' === $_POST['qrrs_inventory_action'] ) {
        $GLOBALS['qrrs_wastage_flash_mode'] = true;
        try {
            qrrs_wastage_handle_actions( $rid, true, false );
        } catch ( RuntimeException $e ) {
            if ( 'qrrs_wastage_flash' !== $e->getMessage() ) {
                throw $e;
            }
            $flash = $GLOBALS['qrrs_wastage_flash'] ?? '';
        }
        unset( $GLOBALS['qrrs_wastage_flash_mode'] );
    }

    $can_manage = qrrs_inventory_can_manage();
    qrrs_wastage_render( $rid, true, $can_manage, '', $flash );
}