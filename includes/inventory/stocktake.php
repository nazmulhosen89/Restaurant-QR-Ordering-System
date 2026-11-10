<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stocktake (physical count).
 *  1. Start a count: one open session per restaurant. Every active item is listed.
 *  2. Staff type the counted quantity (blank = not counted yet). Progress can be saved any time.
 *  3. Finalize: for each counted item the system stock is adjusted to the counted value.
 *     Each change is an 'adjustment' ledger entry, all in one transaction.
 *  Items left blank are not changed.
 */

function qrrs_stocktake_redirect( $status ) {
    wp_safe_redirect( add_query_arg( [
        'tab'         => 'inventory',
        'inv_section' => 'stocktake',
        'status'      => $status,
    ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

/** Allowed count types and their labels. */
function qrrs_stocktake_types() {
    return [
        'opening'   => 'Opening',
        'month_end' => 'Month-end',
        'recheck'   => 'Recheck',
    ];
}

/** Auto name: "Type – 10 Nov 2026", with " (#2)" from the second count on the same day. */
function qrrs_stocktake_auto_name( $restaurant_id, $type ) {
    global $wpdb;
    $types = qrrs_stocktake_types();
    $label = $types[ $type ] ?? 'Stocktake';
    $day   = current_time( 'Y-m-d' );
    $same_day = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_stocktakes WHERE restaurant_id = %d AND created_at LIKE %s",
        $restaurant_id, $day . '%'
    ) );
    $name = $label . ' – ' . date_i18n( 'd M Y', strtotime( $day ) );
    if ( $same_day >= 1 ) {
        $name .= ' (#' . ( $same_day + 1 ) . ')';
    }
    return $name;
}

function qrrs_stocktake_open( $restaurant_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_stocktakes WHERE restaurant_id = %d AND status = 'open' ORDER BY id DESC LIMIT 1",
        $restaurant_id
    ) );
}

function qrrs_stocktake_handle_actions( $restaurant_id, $can_manage ) {
    global $wpdb;
    $st_t   = $wpdb->prefix . 'qrrs_stocktakes';
    $ln_t   = $wpdb->prefix . 'qrrs_stocktake_lines';
    $item_t = $wpdb->prefix . 'qrrs_inventory_items';
    $action = sanitize_key( $_POST['qrrs_inventory_action'] ?? '' );

    if ( ! isset( $_POST['qrrs_inventory_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_inventory_nonce'], 'qrrs_inventory_action' ) ) {
        wp_die( 'Security check failed' );
    }
    if ( ! $can_manage ) {
        qrrs_stocktake_redirect( 'error' );
    }

    // ---- Start a new count ----
    if ( 'stocktake_start' === $action ) {
        if ( qrrs_stocktake_open( $restaurant_id ) ) {
            qrrs_stocktake_redirect( 'stocktake_already_open' );
        }
        $types = qrrs_stocktake_types();
        $type  = sanitize_key( $_POST['count_type'] ?? '' );
        if ( ! isset( $types[ $type ] ) ) {
            qrrs_stocktake_redirect( 'stocktake_type_required' );
        }

        $wpdb->query( 'START TRANSACTION' );
        $ok = $wpdb->insert( $st_t, [
            'restaurant_id' => $restaurant_id,
            'status'        => 'open',
            'count_type'    => $type,
            'note'          => qrrs_stocktake_auto_name( $restaurant_id, $type ),
            'created_by'    => get_current_user_id(),
            'created_at'    => current_time( 'mysql' ),
        ] );
        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
            qrrs_stocktake_redirect( 'error' );
        }
        $sid = (int) $wpdb->insert_id;
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, current_stock FROM $item_t WHERE restaurant_id = %d AND status = 'active'", $restaurant_id
        ) );
        foreach ( $items as $it ) {
            $ok = $wpdb->insert( $ln_t, [
                'stocktake_id'      => $sid,
                'restaurant_id'     => $restaurant_id,
                'inventory_item_id' => (int) $it->id,
                'system_qty'        => (float) $it->current_stock,
                'counted_qty'       => null,
                'adjusted_qty'      => 0,
            ] );
            if ( ! $ok ) {
                $wpdb->query( 'ROLLBACK' );
                qrrs_stocktake_redirect( 'error' );
            }
        }
        $wpdb->query( 'COMMIT' );
        qrrs_stocktake_redirect( 'stocktake_started' );
    }

    $sid = intval( $_POST['stocktake_id'] ?? 0 );
    $open = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $st_t WHERE id = %d AND restaurant_id = %d AND status = 'open'", $sid, $restaurant_id
    ) );
    if ( ! $open ) {
        qrrs_stocktake_redirect( 'stocktake_not_open' );
    }

    // ---- Save progress (counted values) ----
    if ( 'stocktake_save' === $action ) {
        $counts = isset( $_POST['counted'] ) && is_array( $_POST['counted'] ) ? $_POST['counted'] : [];
        $wpdb->query( 'START TRANSACTION' );
        foreach ( $counts as $item_id => $raw ) {
            $item_id = intval( $item_id );
            $raw = is_string( $raw ) ? trim( $raw ) : '';
            if ( '' === $raw ) {
                $val = null;
            } elseif ( is_numeric( $raw ) && (float) $raw >= 0 ) {
                $val = round( (float) $raw, 4 );
            } else {
                $wpdb->query( 'ROLLBACK' );
                qrrs_stocktake_redirect( 'stocktake_value_invalid' );
            }
            $wpdb->update(
                $ln_t,
                [ 'counted_qty' => $val ],
                [ 'stocktake_id' => $sid, 'inventory_item_id' => $item_id ]
            );
        }
        $wpdb->query( 'COMMIT' );
        qrrs_stocktake_redirect( 'stocktake_saved' );
    }

    // ---- Cancel (no stock change) ----
    if ( 'stocktake_cancel' === $action ) {
        $wpdb->update( $st_t, [ 'status' => 'cancelled', 'finalized_by' => get_current_user_id(), 'finalized_at' => current_time( 'mysql' ) ], [ 'id' => $sid ] );
        qrrs_stocktake_redirect( 'stocktake_cancelled' );
    }

    // ---- Finalize: adjust stock to counted values ----
    if ( 'stocktake_finalize' === $action ) {
        $wpdb->query( 'START TRANSACTION' );
        // Lock the session so it cannot be finalized twice at the same time
        $locked = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $st_t WHERE id = %d FOR UPDATE", $sid ) );
        if ( 'open' !== $locked ) {
            $wpdb->query( 'ROLLBACK' );
            qrrs_stocktake_redirect( 'stocktake_not_open' );
        }

        $lines = $wpdb->get_results( $wpdb->prepare(
            "SELECT inventory_item_id, counted_qty FROM $ln_t WHERE stocktake_id = %d AND counted_qty IS NOT NULL",
            $sid
        ) );

        $changed = 0;
        foreach ( $lines as $ln ) {
            $item = $wpdb->get_row( $wpdb->prepare(
                "SELECT current_stock, cost_per_unit FROM $item_t WHERE id = %d AND restaurant_id = %d FOR UPDATE",
                intval( $ln->inventory_item_id ), $restaurant_id
            ) );
            if ( ! $item ) {
                continue;
            }
            $current = (float) $item->current_stock;
            $counted = (float) $ln->counted_qty;
            $diff    = round( $counted - $current, 4 );

            if ( abs( $diff ) >= 0.0001 ) {
                $mv = qrrs_inventory_add_stock_movement( [
                    'restaurant_id'     => $restaurant_id,
                    'inventory_item_id' => intval( $ln->inventory_item_id ),
                    'movement_type'     => 'adjustment',
                    'quantity_in'       => $diff > 0 ? $diff : 0,
                    'quantity_out'      => $diff < 0 ? -$diff : 0,
                    'unit_cost'         => (float) $item->cost_per_unit,
                    'reference_type'    => 'stocktake',
                    'reference_id'      => $sid,
                    'note'              => 'Stocktake #' . $sid . ' (system ' . $current . ', counted ' . $counted . ')',
                    'created_by'        => get_current_user_id(),
                    'in_transaction'    => true,
                ] );
                if ( false === $mv ) {
                    $wpdb->query( 'ROLLBACK' );
                    qrrs_stocktake_redirect( 'stocktake_adjust_failed' );
                }
                $changed++;
            }

            $wpdb->update(
                $ln_t,
                [ 'adjusted_qty' => $diff ],
                [ 'stocktake_id' => $sid, 'inventory_item_id' => intval( $ln->inventory_item_id ) ]
            );
        }

        $wpdb->update(
            $st_t,
            [ 'status' => 'finalized', 'finalized_by' => get_current_user_id(), 'finalized_at' => current_time( 'mysql' ) ],
            [ 'id' => $sid ]
        );
        $wpdb->query( 'COMMIT' );
        qrrs_stocktake_redirect( 'stocktake_finalized_' . intval( $changed ) );
    }
}

function qrrs_stocktake_render( $restaurant_id, $can_manage ) {
    global $wpdb;
    $st_t   = $wpdb->prefix . 'qrrs_stocktakes';
    $ln_t   = $wpdb->prefix . 'qrrs_stocktake_lines';
    $item_t = $wpdb->prefix . 'qrrs_inventory_items';
    $unit_t = $wpdb->prefix . 'qrrs_inventory_units';

    if ( ! $can_manage ) {
        echo '<div class="qrrs-card" style="padding:20px;">Stocktake is for managers and admins only.</div>';
        return;
    }

    $st = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
    $msgs = [
        'stocktake_started'       => [ 'ok',  'Stocktake started. Enter the counted quantities below.' ],
        'stocktake_type_required' => [ 'err', 'Please choose the count type.' ],
        'stocktake_already_open'  => [ 'err', 'A stocktake is already open. Finish or cancel it first.' ],
        'stocktake_saved'         => [ 'ok',  'Progress saved.' ],
        'stocktake_value_invalid' => [ 'err', 'Counted quantity must be a number, 0 or more. Nothing was saved.' ],
        'stocktake_not_open'      => [ 'err', 'This stocktake is no longer open.' ],
        'stocktake_cancelled'     => [ 'ok',  'Stocktake cancelled. No stock was changed.' ],
        'stocktake_adjust_failed' => [ 'err', 'Could not adjust stock. Nothing was finalized.' ],
        'error'                   => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    if ( preg_match( '/^stocktake_finalized_(\d+)$/', $st, $m ) ) {
        echo '<div style="background:#dcfce7; color:#166534; padding:10px 14px; border-radius:6px; margin-bottom:12px;">Stocktake finalized. ' . intval( $m[1] ) . ' item(s) adjusted.</div>';
    } elseif ( isset( $msgs[ $st ] ) ) {
        [ $kind, $text ] = $msgs[ $st ];
        $bg = 'ok' === $kind ? '#dcfce7' : '#fee2e2';
        $fg = 'ok' === $kind ? '#166534' : '#991b1b';
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:10px 14px; border-radius:6px; margin-bottom:12px;">' . esc_html( $text ) . '</div>';
    }

    $base = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'stocktake' ], home_url( '/restaurant-dashboard/' ) );
    $open = qrrs_stocktake_open( $restaurant_id );

    if ( ! $open ) : ?>
        <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
            <h3 style="margin-top:0;">Start a Stocktake</h3>
            <p style="color:#64748b; font-size:13px;">Choose the type. The name is created automatically from the type and today's date.<br>Opening: first count. Month-end: at the end of the month. Recheck: count again to confirm a previous result.</p>
            <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                <input type="hidden" name="qrrs_inventory_action" value="stocktake_start">
                <select name="count_type" required style="min-width:200px; padding:6px 10px;">
                    <option value="">Choose count type</option>
                    <?php foreach ( qrrs_stocktake_types() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="qrrs-btn-save">Start Stocktake</button>
            </form>
        </div>
    <?php else :
        $lines = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.inventory_item_id, l.counted_qty, l.system_qty, i.item_name, i.current_stock, i.cost_per_unit, u.unit_code
             FROM $ln_t l
             JOIN $item_t i ON i.id = l.inventory_item_id
             LEFT JOIN $unit_t u ON u.id = i.unit_id
             WHERE l.stocktake_id = %d
             ORDER BY i.item_name ASC",
            $open->id
        ) );
        $fmt = function ( $n ) { return rtrim( rtrim( number_format( (float) $n, 3, '.', '' ), '0' ), '.' ); };
        ?>
        <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <h3 style="margin:0;"><?php echo esc_html( $open->note ?: 'Stocktake #' . $open->id ); ?> <span style="font-size:13px; color:#64748b; font-weight:normal;">· <?php echo esc_html( qrrs_stocktake_types()[ $open->count_type ] ?? '' ); ?> · open</span></h3>
                    <small style="color:#64748b;">Started <?php echo esc_html( $open->created_at ); ?></small>
                </div>
                <form method="post" action="<?php echo esc_url( $base ); ?>" onsubmit="return confirm('Cancel this stocktake? No stock will change.');" style="margin:0;">
                    <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                    <input type="hidden" name="qrrs_inventory_action" value="stocktake_cancel">
                    <input type="hidden" name="stocktake_id" value="<?php echo intval( $open->id ); ?>">
                    <button type="submit" class="button" style="color:#dc2626;">Cancel stocktake</button>
                </form>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url( $base ); ?>" id="qrrs-stocktake-form">
            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
            <input type="hidden" name="stocktake_id" value="<?php echo intval( $open->id ); ?>">
            <div class="qrrs-card" style="padding:20px;">
                <div style="overflow-x:auto;">
                <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
                    <thead><tr style="background:#f1f5f9; text-align:left;">
                        <th>Item</th><th>System (now)</th><th style="width:160px;">Counted</th><th>Difference</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $lines as $ln ) :
                        $sys = (float) $ln->current_stock;
                        $cnt = null === $ln->counted_qty ? '' : $fmt( $ln->counted_qty );
                    ?>
                        <tr style="border-bottom:1px solid #e2e8f0;" data-system="<?php echo esc_attr( $sys ); ?>">
                            <td><strong><?php echo esc_html( $ln->item_name ); ?></strong></td>
                            <td><?php echo esc_html( $fmt( $sys ) . ' ' . $ln->unit_code ); ?></td>
                            <td>
                                <input type="number" step="0.0001" min="0" name="counted[<?php echo intval( $ln->inventory_item_id ); ?>]"
                                       value="<?php echo esc_attr( $cnt ); ?>" class="qrrs-count-input" style="width:140px;" placeholder="not counted">
                                <span style="color:#64748b; font-size:12px;"><?php echo esc_html( $ln->unit_code ); ?></span>
                            </td>
                            <td class="qrrs-diff" style="font-weight:bold;">—</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" name="qrrs_inventory_action" value="stocktake_save" class="button">Save progress</button>
                    <button type="submit" name="qrrs_inventory_action" value="stocktake_finalize" class="qrrs-btn-save"
                            onclick="return confirm('Finalize? Counted items will be adjusted to the counted value. Blank items are not changed. This cannot be undone.');">Finalize &amp; adjust stock</button>
                </div>
                <p style="color:#64748b; font-size:12px; margin-bottom:0;">Blank = not counted, left unchanged. Stock sold during the count is included in the difference at the moment you finalize.</p>
            </div>
        </form>
        <script>
        (function () {
            function refresh() {
                document.querySelectorAll('#qrrs-stocktake-form tbody tr').forEach(function (tr) {
                    var inp = tr.querySelector('.qrrs-count-input');
                    var cell = tr.querySelector('.qrrs-diff');
                    if (!inp || !cell) return;
                    if (inp.value === '') { cell.textContent = '—'; cell.style.color = '#94a3b8'; return; }
                    var d = Math.round((parseFloat(inp.value) - parseFloat(tr.dataset.system)) * 10000) / 10000;
                    cell.textContent = (d > 0 ? '+' : '') + d;
                    cell.style.color = d === 0 ? '#16a34a' : (d < 0 ? '#dc2626' : '#b45309');
                });
            }
            document.querySelectorAll('.qrrs-count-input').forEach(function (el) { el.addEventListener('input', refresh); });
            refresh();
        })();
        </script>
    <?php endif;

    // History
    $history = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.id, s.status, s.count_type, s.note, s.created_at, s.finalized_at,
                (SELECT COUNT(*) FROM $ln_t l WHERE l.stocktake_id = s.id AND l.adjusted_qty <> 0) AS adjusted
         FROM $st_t s WHERE s.restaurant_id = %d AND s.status <> 'open' ORDER BY s.id DESC LIMIT 10",
        $restaurant_id
    ) );
    ?>
    <div class="qrrs-card" style="padding:20px; margin-top:20px;">
        <h3 style="margin-top:0;">Previous stocktakes</h3>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>#</th><th>Started</th><th>Count</th><th>Status</th><th>Items adjusted</th></tr></thead>
            <tbody>
            <?php foreach ( $history as $h ) : ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo intval( $h->id ); ?></td>
                    <td><?php echo esc_html( $h->created_at ); ?></td>
                    <td><?php echo esc_html( $h->note ?: '—' ); ?> <small style="color:#64748b;">(<?php echo esc_html( qrrs_stocktake_types()[ $h->count_type ] ?? '' ); ?>)</small></td>
                    <td><?php echo esc_html( ucfirst( $h->status ) ); ?></td>
                    <td><?php echo 'finalized' === $h->status ? intval( $h->adjusted ) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $history ) ) : ?><tr><td colspan="5" style="text-align:center; padding:16px; color:#94a3b8;">No stocktakes yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}