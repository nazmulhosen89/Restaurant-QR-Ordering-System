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
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600; font-size:14px;">' . esc_html( $text ) . '</div>';
    }

    if ( ! $can_request && ! $can_manage ) {
        echo '<div class="qrrs-card" style="padding:20px;">You do not have access to Wastage.</div>';
        return;
    }

    // Month filter for the summary (YYYY-MM)
    if ( isset( $_GET['wm_y'], $_GET['wm_m'] ) && preg_match( '/^\d{4}$/', $_GET['wm_y'] ) && preg_match( '/^(0[1-9]|1[0-2])$/', $_GET['wm_m'] ) ) {
        $_GET['wm'] = $_GET['wm_y'] . '-' . $_GET['wm_m'];
    }
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
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px !important; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <span class="material-icons-outlined" style="color:#2563eb; font-size:22px;">report_problem</span>
            <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Report Wastage</h3>
        </div>
        <form method="post" action="<?php echo esc_url( $act ); ?>" style="margin:0;">
            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
            <input type="hidden" name="qrrs_inventory_action" value="report_wastage">
            <div class="qrrs-form-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:18px;">
                <div class="qrrs-input-group" style="margin:0;">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Item *</label>
                    <select name="inventory_item_id" required style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                        <option value="">Select item</option>
                        <?php foreach ( $items as $it ) : ?>
                            <option value="<?php echo intval( $it->id ); ?>"><?php echo esc_html( $it->item_name . ' — stock: ' . rtrim( rtrim( number_format( $it->current_stock, 3, '.', '' ), '0' ), '.' ) . ' ' . $it->unit_code ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-input-group" style="margin:0;">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Quantity *</label>
                    <input type="number" step="0.0001" min="0.0001" name="quantity" required style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                </div>
                <div class="qrrs-input-group" style="margin:0;">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Reason *</label>
                    <select name="reason_code" required style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                        <option value="">Select reason</option>
                        <?php foreach ( $reasons as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-input-group" style="margin:0;">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Note</label>
                    <input type="text" name="note" maxlength="255" placeholder="Optional" style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                </div>
            </div>
            <button type="submit" class="qrrs-btn-save" style="height:42px; padding:0 24px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                <span class="material-icons-outlined" style="font-size:18px;">send</span> Submit Wastage
            </button>
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
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px !important; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <span class="material-icons-outlined" style="color:#d97706; font-size:22px;">hourglass_top</span>
            <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Pending Approval (<?php echo count( $pending ); ?>)</h3>
        </div>
        <?php if ( empty( $pending ) ) : ?>
            <p style="color:#64748b; margin:0;">Nothing waiting for approval.</p>
        <?php else : ?>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                    <th style="padding:12px; font-size:13px; color:#475569;">Item</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Qty</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Reason</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Value</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Reported by</th>
                    <th style="padding:12px; font-size:13px; color:#475569; text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $pending as $p ) : ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( $p->item_name ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo esc_html( rtrim( rtrim( number_format( $p->quantity, 3, '.', '' ), '0' ), '.' ) . ' ' . $p->unit_code ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo esc_html( $p->reason ); ?><?php if ( $p->note ) echo '<br><small style="color:#64748b;">' . esc_html( $p->note ) . '</small>'; ?></td>
                    <td style="padding:12px; font-weight:600; color:#1e293b;">৳<?php echo esc_html( number_format( $p->total_value, 2 ) ); ?></td>
                    <td style="padding:12px; color:#64748b;"><?php echo esc_html( $p->display_name ?: '—' ); ?></td>
                    <td style="padding:12px; text-align:right;">
                        <form method="post" action="<?php echo esc_url( $act ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                            <input type="hidden" name="qrrs_inventory_action" value="approve_wastage">
                            <input type="hidden" name="wastage_id" value="<?php echo intval( $p->id ); ?>">
                            <button type="submit" class="button" style="padding:6px 14px; background:#dcfce7; color:#166534; border:1px solid #bbf7d0; border-radius:6px; font-weight:600; cursor:pointer;">Approve</button>
                        </form>
                        <form method="post" action="<?php echo esc_url( $act ); ?>" style="display:inline; margin-left:6px;" onsubmit="var r=prompt('Reason for rejecting:'); if(!r){return false;} this.reject_reason.value=r; return true;">
                            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                            <input type="hidden" name="qrrs_inventory_action" value="reject_wastage">
                            <input type="hidden" name="wastage_id" value="<?php echo intval( $p->id ); ?>">
                            <input type="hidden" name="reject_reason" value="">
                            <button type="submit" class="button" style="padding:6px 14px; background:#fee2e2; color:#dc2626; border:1px solid #fecaca; border-radius:6px; font-weight:600; cursor:pointer;">Reject</button>
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
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px !important; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        
        <!-- 📌 Wastage Summary Header with Month Picker & Icon Search Button -->
        <div style="display:flex; flex-direction:row; justify-content:space-between; align-items:center; flex-wrap:nowrap; gap:15px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <div style="display:flex; flex-direction:row; align-items:center; gap:10px; flex:1 1 auto; min-width:0;">
                <span class="material-icons-outlined" style="color:#2563eb; font-size:22px;">assessment</span>
                <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Wastage Summary</h3>
            </div>
            
            <?php
            $cur_ts    = strtotime( $month . '-01' );
            $wm_label  = date_i18n( 'F Y', $cur_ts );
            $today_iso = current_time( 'Y-m-d' );
            ?>
            <div style="position:relative; flex:0 0 auto;">
                <form method="get" id="wm-form" style="margin:0;">
                    <input type="hidden" name="tab" value="inventory">
                    <input type="hidden" name="inv_section" value="wastage">
                    <input type="hidden" name="wm" id="wm-value" value="<?php echo esc_attr( $month ); ?>">
                    <button type="button" id="wm-open" style="height:40px; padding:0 14px; display:inline-flex; align-items:center; gap:8px; background:#fff; border:1px solid #cbd5e1; border-radius:8px; cursor:pointer; font-weight:700; color:#1e293b; font-size:14px; white-space:nowrap;">
                        <span class="material-icons-outlined" style="font-size:18px; color:#2563eb;">calendar_month</span>
                        <span><?php echo esc_html( $wm_label ); ?></span>
                        <span class="material-icons-outlined" style="font-size:18px; color:#64748b;">expand_more</span>
                    </button>
                </form>
                <div id="wm-cal" hidden style="position:absolute; right:0; top:46px; z-index:50; width:300px; box-sizing:border-box; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.12); padding:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <button type="button" id="wm-prev" aria-label="Previous month" style="width:32px; height:32px; border:1px solid #e2e8f0; background:#fff; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center;"><span class="material-icons-outlined" style="font-size:18px;">chevron_left</span></button>
                        <strong id="wm-cal-title" style="font-size:14px; color:#1e293b;"></strong>
                        <button type="button" id="wm-next" aria-label="Next month" style="width:32px; height:32px; border:1px solid #e2e8f0; background:#fff; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center;"><span class="material-icons-outlined" style="font-size:18px;">chevron_right</span></button>
                    </div>
                    <div id="wm-grid" style="display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:4px; text-align:center;"></div>
                    <div style="margin-top:8px; font-size:11px; color:#94a3b8; text-align:center;">Pick any day. The whole month of that day is shown.</div>
                </div>
            </div>
            <script>
            (function () {
                var TODAY = '<?php echo esc_js( $today_iso ); ?>';
                var CUR   = '<?php echo esc_js( $month ); ?>';
                var NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                var WD    = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                var openBtn = document.getElementById('wm-open'), cal = document.getElementById('wm-cal');
                var grid = document.getElementById('wm-grid'), title = document.getElementById('wm-cal-title');
                var form = document.getElementById('wm-form'), val = document.getElementById('wm-value');
                var prev = document.getElementById('wm-prev'), next = document.getElementById('wm-next');
                var view = [0, 0];
                var todayY = Number(TODAY.slice(0, 4)), todayM = Number(TODAY.slice(5, 7));
                function pad(n) { return (n < 10 ? '0' : '') + n; }
                function render() {
                    title.textContent = NAMES[view[1] - 1] + ' ' + view[0];
                    var first = new Date(view[0], view[1] - 1, 1).getDay();
                    var days = new Date(view[0], view[1], 0).getDate();
                    var html = WD.map(function (d) { return '<div style="font-size:11px; color:#94a3b8; font-weight:700; padding:4px 0;">' + d + '</div>'; }).join('');
                    for (var i = 0; i < first; i++) html += '<div></div>';
                    for (var d = 1; d <= days; d++) {
                        var iso = view[0] + '-' + pad(view[1]) + '-' + pad(d);
                        var future = iso > TODAY;
                        var isToday = iso === TODAY;
                        html += '<button type="button" data-iso="' + iso + '"' + (future ? ' disabled' : '') + ' style="width:100%; min-width:0; box-sizing:border-box; height:34px; border-radius:8px; border:' + (isToday ? '2px solid #2563eb' : '1px solid transparent') + '; background:#f8fafc; color:' + (future ? '#cbd5e1' : '#1e293b') + '; cursor:' + (future ? 'not-allowed' : 'pointer') + '; font-size:13px; font-weight:600;">' + d + '</button>';
                    }
                    grid.innerHTML = html;
                    next.disabled = (view[0] > todayY) || (view[0] === todayY && view[1] >= todayM);
                    next.style.opacity = next.disabled ? '.35' : '1';
                }
                function shift(n) {
                    var t = new Date(view[0], view[1] - 1 + n, 1);
                    view = [t.getFullYear(), t.getMonth() + 1];
                    render();
                }
                // Show the calendar above the page content so no card can clip it
                document.body.appendChild(cal);
                openBtn.addEventListener('click', function () {
                    view = CUR.split('-').map(Number);
                    render();
                    if (cal.hidden) {
                        var r = openBtn.getBoundingClientRect();
                        cal.style.position = 'fixed';
                        cal.style.right = 'auto';
                        cal.style.top = (r.bottom + 8) + 'px';
                        cal.style.left = Math.max(8, r.right - 300) + 'px';
                        cal.hidden = false;
                    } else {
                        cal.hidden = true;
                    }
                });
                prev.addEventListener('click', function () { shift(-1); });
                next.addEventListener('click', function () { if (!next.disabled) shift(1); });
                grid.addEventListener('click', function (e) {
                    var b = e.target.closest('button[data-iso]');
                    if (!b || b.disabled) return;
                    val.value = b.getAttribute('data-iso').slice(0, 7);
                    form.submit();
                });
                document.addEventListener('click', function (e) {
                    if (!cal.hidden && !cal.contains(e.target) && !openBtn.contains(e.target)) cal.hidden = true;
                });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cal.hidden = true; });
            })();
            </script>
        </div>


        

        <p style="margin:0 0 15px; font-size:17px; color:#0f172a;"><strong>Total loss: ৳<?php echo esc_html( number_format( $grand_value, 2 ) ); ?></strong> <span style="color:#64748b; font-size:13px; font-weight:normal;">(approved wastage, <?php echo esc_html( $month ); ?>)</span></p>
        
        <?php if ( empty( $summary ) ) : ?>
            <p style="color:#64748b; margin:0;">No approved wastage this month.</p>
        <?php else : ?>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                    <th style="padding:12px; font-size:13px; color:#475569;">Reason</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Entries</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Value (৳)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $summary as $s ) : ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( $s->reason ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo intval( $s->cnt ); ?></td>
                    <td style="padding:12px; font-weight:600; color:#1e293b;">৳<?php echo esc_html( number_format( (float) $s->value, 2 ) ); ?></td>
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
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <span class="material-icons-outlined" style="color:#2563eb; font-size:22px;">history</span>
            <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Recent Wastage</h3>
        </div>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                    <th style="padding:12px; font-size:13px; color:#475569;">Date</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Item</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Qty</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Reason</th>
                    <?php if ( $can_manage ) : ?><th style="padding:12px; font-size:13px; color:#475569;">Value</th><?php endif; ?>
                    <th style="padding:12px; font-size:13px; color:#475569;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( $recent ) : foreach ( $recent as $r ) :
                $badge = [ 'pending' => '#fef3c7;#92400e', 'approved' => '#dcfce7;#166534', 'rejected' => '#fee2e2;#991b1b' ][ $r->status ] ?? '#e2e8f0;#334155';
                [ $bg, $fg ] = explode( ';', $badge );
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; color:#64748b;"><?php echo esc_html( substr( $r->created_at, 0, 16 ) ); ?></td>
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( $r->item_name ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo esc_html( rtrim( rtrim( number_format( $r->quantity, 3, '.', '' ), '0' ), '.' ) . ' ' . $r->unit_code ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo esc_html( $r->reason ); ?></td>
                    <?php if ( $can_manage ) : ?><td style="padding:12px; font-weight:600; color:#1e293b;">৳<?php echo esc_html( number_format( (float) $r->total_value, 2 ) ); ?></td><?php endif; ?>
                    <td style="padding:12px;">
                        <span style="background:<?php echo $bg; ?>; color:<?php echo $fg; ?>; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase;"><?php echo esc_html( ucfirst( $r->status ) ); ?></span>
                        <?php if ( 'rejected' === $r->status && $r->reject_reason ) echo '<br><small style="color:#64748b;">' . esc_html( $r->reject_reason ) . '</small>'; ?>
                    </td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="6" style="text-align:center; padding:25px; color:#64748b;">No wastage recorded yet.</td></tr>
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