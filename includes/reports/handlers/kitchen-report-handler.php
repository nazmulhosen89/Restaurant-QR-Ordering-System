<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function handle_fetch_kitchen_report() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }

    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id      = intval($params['restaurant_id']);
    $date_range  = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';
    $report_type = isset($params['kitchen_report_type']) ? sanitize_text_field($params['kitchen_report_type']) : 'kot_history';

    if ( empty($date_range) ) {
        wp_send_json_success(array('data' => '<p style="text-align:center; padding:20px;">Please select a date range.</p>'));
        return;
    }

    $dates      = explode(" to ", $date_range);
    $start_date = trim($dates[0]);
    $end_date   = isset($dates[1]) ? trim($dates[1]) : $start_date;

    $currency     = get_qrrs_restaurant_currency($res_id);
    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $items_table  = $wpdb->prefix . 'qrrs_order_items';

    // ===================== KOT HISTORY =====================
    if ( $report_type === 'kot_history' ) {

        // Single JOIN query — দুটো আলাদা query এর বদলে
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT
                o.id,
                o.table_name,
                o.order_status,
                o.payment_status,
                o.grand_total,
                o.created_at,
                COUNT(oi.id)                                        AS unique_items,
                COALESCE(SUM(oi.quantity), 0)                       AS total_qty,
                COALESCE(GROUP_CONCAT(oi.item_name ORDER BY oi.id SEPARATOR ', '), '—') AS item_names
            FROM {$orders_table} o
            LEFT JOIN {$items_table} oi ON oi.order_id = o.id
            WHERE o.restaurant_id = %d
            AND DATE(o.created_at) BETWEEN %s AND %s
            GROUP BY o.id, o.table_name, o.order_status, o.payment_status, o.grand_total, o.created_at
            ORDER BY o.created_at DESC",
            $res_id, $start_date, $end_date
        ));

        if ( $wpdb->last_error ) {
            wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
            return;
        }

        if ( empty($orders) ) {
            wp_send_json_success(array('data' => '<div style="text-align:center; padding:50px; color:#94a3b8;"><p>No KOT records found.</p></div>'));
            return;
        }

        // Totals
        $g_total = $g_completed = $g_cancelled = $g_pending = $g_processing = 0;
        $g_unique_items = $g_qty = $g_grand = 0;

        foreach ( $orders as $o ) {
            $g_total++;
            $g_unique_items += intval($o->unique_items);
            $g_qty          += intval($o->total_qty);
            $g_grand        += floatval($o->grand_total);
            $s = $o->order_status;
            if ( $s === 'completed' )                           $g_completed++;
            elseif ( $s === 'cancelled' )                       $g_cancelled++;
            elseif ( in_array($s, array('pending','billing')) ) $g_pending++;
            else                                                $g_processing++;
        }

        ob_start();
        ?>

        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total KOTs</span>
                <h3 style="margin:5px 0 0; font-size:26px;"><?php echo $g_total; ?></h3>
                <small style="opacity:0.6;"><?php echo $g_qty; ?> total items ordered</small>
            </div>
            <div style="background:#fff; border:2px solid #10b981; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;float: left; width:100%; line-height: 1.5em;"><i class="far fa-check-circle"></i> Completed</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_completed; ?></h3>
                <small style="color:#64748b;"><?php echo $g_total > 0 ? round(($g_completed/$g_total)*100,1) : 0; ?>%</small>
            </div>
            <div style="background:#fff; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;float: left; width:100%; line-height: 1.5em;"><i class="fas fa-sync"></i> Processing</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_processing; ?></h3>
                <small style="color:#64748b;"><?php echo $g_total > 0 ? round(($g_processing/$g_total)*100,1) : 0; ?>%</small>
            </div>
            <div style="background:#fff; border:2px solid #ef4444; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#ef4444; text-transform:uppercase;float: left; width:100%; line-height: 1.5em;"><i class="far fas fa-times"></i> Cancelled</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_cancelled; ?></h3>
                <small style="color:#64748b;"><?php echo $g_total > 0 ? round(($g_cancelled/$g_total)*100,1) : 0; ?>%</small>
            </div>
        </div>

        <!-- Item summary row -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:25px;">
            <div style="background:#f0f6fb; border:1px solid #bfdbfe; border-radius:10px; padding:16px; display:flex; align-items:center; gap:12px;">
                <span style="font-size:28px;">🍽️</span>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#2271b1; text-transform:uppercase;">Total Unique Item Lines</div>
                    <div style="font-size:22px; font-weight:700; color:#1e293b;"><?php echo $g_unique_items; ?> <span style="font-size:13px; color:#64748b;">lines across all KOTs</span></div>
                </div>
            </div>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:12px;">
                <span style="font-size:28px;">📦</span>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#10b981; text-transform:uppercase;">Total Quantity Ordered</div>
                    <div style="font-size:22px; font-weight:700; color:#1e293b;"><?php echo $g_qty; ?> <span style="font-size:13px; color:#64748b;">items total</span></div>
                </div>
            </div>
        </div>

        <!-- KOT Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="float: left;width:100%; border-collapse:collapse; text-align:left; margin: 0;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">KOT #</th>
                        <th style="padding:12px;">Table / Type</th>
                        <th style="padding:12px;">Items Ordered</th>
                        <th style="padding:12px; text-align:center;">Item Lines</th>
                        <th style="padding:12px; text-align:center;">Total Qty</th>
                        <th style="padding:12px;">Order Time</th>
                        <th style="padding:12px;">Status</th>
                        <th style="padding:12px; text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $orders as $row ) :
                    $table_val = strtolower(trim($row->table_name));
                    if ( $table_val === 'take out' || $table_val === 'takeout' ) {
                        $type_label = 'Takeaway'; $type_bg = '#fef3c7'; $type_color = '#92400e';
                    } elseif ( $table_val === 'delivery' ) {
                        $type_label = 'Delivery'; $type_bg = '#dbeafe'; $type_color = '#1e40af';
                    } else {
                        $type_label = $row->table_name; $type_bg = '#d1fae5'; $type_color = '#065f46';
                    }

                    $s = $row->order_status;
                    if ( $s === 'completed' )                        { $s_label = 'Completed';       $s_bg = '#d1fae5'; $s_color = '#065f46'; }
                    elseif ( $s === 'cancelled' )                    { $s_label = 'Cancelled';       $s_bg = '#fee2e2'; $s_color = '#991b1b'; }
                    elseif ( $s === 'pending' )                      { $s_label = 'Pending';         $s_bg = '#fef3c7'; $s_color = '#92400e'; }
                    elseif ( $s === 'processing' )                   { $s_label = 'Processing';      $s_bg = '#ede9fe'; $s_color = '#5b21b6'; }
                    elseif ( in_array($s, array('ready','served')) ) { $s_label = ' ' . ucfirst($s); $s_bg = '#dbeafe'; $s_color = '#1e40af'; }
                    else                                             { $s_label = ucfirst($s);          $s_bg = '#f1f5f9'; $s_color = '#475569'; }

                    $inv_no    = '#' . date('Ym', strtotime($row->created_at)) . str_pad($row->id, 4, '0', STR_PAD_LEFT);
                    $item_names = esc_html($row->item_names);
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; font-weight:700; color:#1e293b;"><?php echo $inv_no; ?></td>
                        <td style="padding:12px;">
                            <span style="background:<?php echo $type_bg; ?>; color:<?php echo $type_color; ?>; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                                <?php echo esc_html($type_label); ?>
                            </span>
                        </td>
                        <td style="padding:12px; font-size:11px; color:#64748b; max-width:220px;">
                            <div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo $item_names; ?>">
                                <?php echo $item_names; ?>
                            </div>
                        </td>
                        <td style="padding:12px; text-align:center; font-weight:700; font-size:16px; color:#2271b1;"><?php echo intval($row->unique_items); ?></td>
                        <td style="padding:12px; text-align:center; font-weight:600; color:#1e293b;"><?php echo intval($row->total_qty); ?></td>
                        <td style="padding:12px; font-size:12px; color:#64748b;"><?php echo date('d M, h:i A', strtotime($row->created_at)); ?></td>
                        <td style="padding:12px;">
                            <span style="background:<?php echo $s_bg; ?>; color:<?php echo $s_color; ?>; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                                <?php echo $s_label; ?>
                            </span>
                        </td>
                        <td style="padding:12px; text-align:right; font-weight:700; color:#2271b1;"><?php echo $currency . number_format(floatval($row->grand_total), 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;" colspan="2">TOTAL (<?php echo $g_total; ?> KOTs)</td>
                        <td style="padding:12px;"></td>
                        <td style="padding:12px; text-align:center; color:#2271b1;"><?php echo $g_unique_items; ?></td>
                        <td style="padding:12px; text-align:center; color:#1e293b;"><?php echo $g_qty; ?></td>
                        <td style="padding:12px;"></td>
                        <td style="padding:12px;"></td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($g_grand, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php
        $output = ob_get_clean();
        wp_send_json_success(array('data' => $output));
        return;

    }

    // ===================== PENDING VS COMPLETED =====================
    if ( $report_type === 'pending_vs_completed' ) {

        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at) AS order_date,
                SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END)                                        AS completed,
                SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END)                                          AS pending,
                SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END)                                       AS processing,
                SUM(CASE WHEN order_status IN ('ready','served') THEN 1 ELSE 0 END)                                AS ready_served,
                SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END)                                        AS cancelled,
                SUM(CASE WHEN order_status = 'completed' THEN grand_total ELSE 0 END)                              AS completed_revenue,
                SUM(CASE WHEN order_status NOT IN ('completed','cancelled') THEN grand_total ELSE 0 END)           AS pending_revenue,
                COUNT(id) AS total
             FROM {$orders_table}
             WHERE restaurant_id = %d
               AND DATE(created_at) BETWEEN %s AND %s
             GROUP BY DATE(created_at)
             ORDER BY order_date ASC",
            $res_id, $start_date, $end_date
        ));

        if ( $wpdb->last_error ) {
            wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
            return;
        }

        if ( empty($daily) ) {
            wp_send_json_success(array('data' => '<div style="text-align:center; padding:50px; color:#94a3b8;"><p>No orders found for this period.</p></div>'));
            return;
        }

        $g_total = $g_completed = $g_pending = $g_processing = $g_ready = $g_cancelled = $g_comp_rev = $g_pend_rev = 0;
        foreach ( $daily as $d ) {
            $g_total      += intval($d->total);
            $g_completed  += intval($d->completed);
            $g_pending    += intval($d->pending);
            $g_processing += intval($d->processing);
            $g_ready      += intval($d->ready_served);
            $g_cancelled  += intval($d->cancelled);
            $g_comp_rev   += floatval($d->completed_revenue);
            $g_pend_rev   += floatval($d->pending_revenue);
        }

        $comp_pct = $g_total > 0 ? round(($g_completed / $g_total) * 100, 1) : 0;
        $pend_pct = $g_total > 0 ? round((($g_pending + $g_processing + $g_ready) / $g_total) * 100, 1) : 0;

        ob_start();
        ?>

        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:15px; margin-bottom:15px;">
            <div style="background:#f0fdf4; border:2px solid #10b981; padding:20px; border-radius:12px; display:flex; align-items:center; gap:15px;">
                <div style="font-size:36px;">✅</div>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#10b981; text-transform:uppercase;">Completed Orders</div>
                    <div style="font-size:28px; font-weight:700; color:#1e293b;"><?php echo $g_completed; ?></div>
                    <div style="font-size:12px; color:#64748b;"><?php echo $comp_pct; ?>% of total &bull; <?php echo $currency . number_format($g_comp_rev, 2); ?> revenue</div>
                    <div style="margin-top:8px; height:6px; background:#d1fae5; border-radius:3px;">
                        <div style="width:<?php echo $comp_pct; ?>%; height:100%; background:#10b981; border-radius:3px;"></div>
                    </div>
                </div>
            </div>
            <div style="background:#fffbeb; border:2px solid #f59e0b; padding:20px; border-radius:12px; display:flex; align-items:center; gap:15px;">
                <div style="font-size:36px;">⏳</div>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#f59e0b; text-transform:uppercase;">Active / Pending Orders</div>
                    <div style="font-size:28px; font-weight:700; color:#1e293b;"><?php echo ($g_pending + $g_processing + $g_ready); ?></div>
                    <div style="font-size:12px; color:#64748b;"><?php echo $pend_pct; ?>% of total &bull; <?php echo $currency . number_format($g_pend_rev, 2); ?> value</div>
                    <div style="margin-top:8px; height:6px; background:#fde68a; border-radius:3px;">
                        <div style="width:<?php echo $pend_pct; ?>%; height:100%; background:#f59e0b; border-radius:3px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status breakdown row -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:25px;">
            <div style="background:#ede9fe; border-radius:10px; padding:14px; text-align:center;">
                <div style="font-size:10px; font-weight:700; color:#5b21b6; text-transform:uppercase;">🔄 Processing</div>
                <div style="font-size:22px; font-weight:700; color:#1e293b; margin-top:4px;"><?php echo $g_processing; ?></div>
            </div>
            <div style="background:#dbeafe; border-radius:10px; padding:14px; text-align:center;">
                <div style="font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase;">🍽️ Ready/Served</div>
                <div style="font-size:22px; font-weight:700; color:#1e293b; margin-top:4px;"><?php echo $g_ready; ?></div>
            </div>
            <div style="background:#fee2e2; border-radius:10px; padding:14px; text-align:center;">
                <div style="font-size:10px; font-weight:700; color:#991b1b; text-transform:uppercase;">❌ Cancelled</div>
                <div style="font-size:22px; font-weight:700; color:#1e293b; margin-top:4px;"><?php echo $g_cancelled; ?></div>
            </div>
            <div style="background:#f1f5f9; border-radius:10px; padding:14px; text-align:center;">
                <div style="font-size:10px; font-weight:700; color:#475569; text-transform:uppercase;">📋 Total</div>
                <div style="font-size:22px; font-weight:700; color:#1e293b; margin-top:4px;"><?php echo $g_total; ?></div>
            </div>
        </div>

        <!-- Chart -->
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Daily Status Breakdown</h4>
            <canvas id="pvc-chart" height="85"></canvas>
        </div>

        <!-- Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="float: left;width:100%; border-collapse:collapse; text-align:left; margin: 0;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">Date</th>
                        <th style="padding:12px; text-align:center;">Total</th>
                        <th style="padding:12px; text-align:center; color:#10b981;">Completed</th>
                        <th style="padding:12px; text-align:center; color:#5b21b6;">Processing</th>
                        <th style="padding:12px; text-align:center; color:#1e40af;">Ready/Served</th>
                        <th style="padding:12px; text-align:center; color:#f59e0b;">Pending</th>
                        <th style="padding:12px; text-align:center; color:#ef4444;">Cancelled</th>
                        <th style="padding:12px; text-align:right;">Comp. Revenue</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $daily as $row ) : ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; font-weight:500;"><?php echo date('M d, Y', strtotime($row->order_date)); ?></td>
                        <td style="padding:12px; text-align:center; font-weight:700;"><?php echo $row->total; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981; font-weight:600;"><?php echo $row->completed; ?></td>
                        <td style="padding:12px; text-align:center; color:#5b21b6;"><?php echo $row->processing; ?></td>
                        <td style="padding:12px; text-align:center; color:#1e40af;"><?php echo $row->ready_served; ?></td>
                        <td style="padding:12px; text-align:center; color:#f59e0b;"><?php echo $row->pending; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $row->cancelled; ?></td>
                        <td style="padding:12px; text-align:right; font-weight:600; color:#2271b1;"><?php echo $currency . number_format(floatval($row->completed_revenue), 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;">TOTAL</td>
                        <td style="padding:12px; text-align:center; color:#2271b1; font-size:15px;"><?php echo $g_total; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981;"><?php echo $g_completed; ?></td>
                        <td style="padding:12px; text-align:center; color:#5b21b6;"><?php echo $g_processing; ?></td>
                        <td style="padding:12px; text-align:center; color:#1e40af;"><?php echo $g_ready; ?></td>
                        <td style="padding:12px; text-align:center; color:#f59e0b;"><?php echo $g_pending; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $g_cancelled; ?></td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($g_comp_rev, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var labels     = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d->order_date)); }, $daily)); ?>;
            var completed  = <?php echo json_encode(array_map(function($d) { return intval($d->completed); }, $daily)); ?>;
            var processing = <?php echo json_encode(array_map(function($d) { return intval($d->processing); }, $daily)); ?>;
            var pending    = <?php echo json_encode(array_map(function($d) { return intval($d->pending); }, $daily)); ?>;
            var cancelled  = <?php echo json_encode(array_map(function($d) { return intval($d->cancelled); }, $daily)); ?>;

            var ctx = document.getElementById('pvc-chart');
            if (!ctx) return;
            if (window._pvcChart) window._pvcChart.destroy();
            window._pvcChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Completed',  data: completed,  backgroundColor: '#10b981', borderRadius: 4 },
                        { label: 'Processing', data: processing, backgroundColor: '#8b5cf6', borderRadius: 4 },
                        { label: 'Pending',    data: pending,    backgroundColor: '#f59e0b', borderRadius: 4 },
                        { label: 'Cancelled',  data: cancelled,  backgroundColor: '#ef4444', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } }
                    }
                }
            });
        })();
        </script>

        <?php
        $output = ob_get_clean();
        wp_send_json_success(array('data' => $output));
        return;
    }

    // ===================== PREP TIME TRACKING =====================
    if ( $report_type === 'prep_time' ) {

    $orders = $wpdb->get_results($wpdb->prepare(
        "SELECT
            o.id,
            o.table_name,
            o.order_status,
            o.created_at,
            o.ready_at,
            TIMESTAMPDIFF(MINUTE, o.created_at, o.ready_at) AS prep_minutes,
            SUM(oi.quantity) AS total_qty,
            COUNT(oi.id)     AS unique_items,
            o.grand_total
         FROM {$orders_table} o
         LEFT JOIN {$items_table} oi ON oi.order_id = o.id
         WHERE o.restaurant_id = %d
           AND DATE(o.created_at) BETWEEN %s AND %s
           AND o.ready_at IS NOT NULL
           AND o.ready_at > o.created_at
         GROUP BY o.id
         ORDER BY prep_minutes DESC",
        $res_id, $start_date, $end_date
    ));

    if ( $wpdb->last_error ) {
        wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
        return;
    }

    if ( empty($orders) ) {
        wp_send_json_success(array('data' => '
            <div style="text-align:center; padding:50px; color:#94a3b8;">
                <div style="font-size:40px; margin-bottom:10px;">⏱️</div>
                <p>No prep time data found for this period.</p>
                <small>Prep time tracks when an order moves from <strong>Pending → Ready</strong>.<br>
                New orders placed after this update will be tracked automatically.</small>
            </div>
        '));
        return;
    }

    // Stats
    $total    = count($orders);
    $sum_prep = 0;
    $max_prep = 0;
    $min_prep = PHP_INT_MAX;
    $max_order = null;
    $min_order = null;
    $fast = $normal = $slow = 0;

    foreach ( $orders as $o ) {
        $m = intval($o->prep_minutes);
        $sum_prep += $m;
        if ( $m > $max_prep ) { $max_prep = $m; $max_order = $o; }
        if ( $m < $min_prep ) { $min_prep = $m; $min_order = $o; }
        if ( $m < 15 )        $fast++;
        elseif ( $m <= 30 )   $normal++;
        else                  $slow++;
    }

    $avg_prep = $total > 0 ? round($sum_prep / $total, 1) : 0;
    if ( $min_prep === PHP_INT_MAX ) $min_prep = 0;

    ob_start();
    ?>

    <!-- Summary Cards -->
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
        <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
            <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">⏱ Avg. Prep Time</span>
            <h3 style="margin:5px 0 0; color:#2ecc71; font-size:24px;"><?php echo $avg_prep; ?> min</h3>
            <small style="opacity:0.6;"><?php echo $total; ?> orders tracked</small>
        </div>
        <div style="background:#d1fae5; border:2px solid #10b981; padding:20px; border-radius:12px;">
            <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;">🚀 Fast (&lt;15 min)</span>
            <h3 style="margin:5px 0 0; color:#1e293b; font-size:22px;"><?php echo $fast; ?></h3>
            <small style="color:#065f46;"><?php echo $total > 0 ? round(($fast/$total)*100,1) : 0; ?>% of orders</small>
        </div>
        <div style="background:#fef3c7; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
            <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;">🕐 Normal (15–30 min)</span>
            <h3 style="margin:5px 0 0; color:#1e293b; font-size:22px;"><?php echo $normal; ?></h3>
            <small style="color:#92400e;"><?php echo $total > 0 ? round(($normal/$total)*100,1) : 0; ?>% of orders</small>
        </div>
        <div style="background:#fee2e2; border:2px solid #ef4444; padding:20px; border-radius:12px;">
            <span style="font-size:10px; font-weight:700; color:#ef4444; text-transform:uppercase;">🐢 Slow (&gt;30 min)</span>
            <h3 style="margin:5px 0 0; color:#1e293b; font-size:22px;"><?php echo $slow; ?></h3>
            <small style="color:#991b1b;"><?php echo $total > 0 ? round(($slow/$total)*100,1) : 0; ?>% of orders</small>
        </div>
    </div>

    <!-- Min / Max highlight -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:25px;">
        <div style="background:#fff; border:1px solid #eee; border-radius:10px; padding:18px; display:flex; align-items:center; gap:14px;">
            <span style="font-size:32px;">⚡</span>
            <div>
                <div style="font-size:11px; font-weight:700; color:#10b981; text-transform:uppercase;">Fastest Order</div>
                <div style="font-size:22px; font-weight:700; color:#1e293b;"><?php echo $min_prep; ?> <span style="font-size:13px; color:#64748b;">minutes</span></div>
                <?php if ($min_order): ?>
                <div style="font-size:11px; color:#94a3b8; margin-top:3px;">
                    KOT #<?php echo str_pad($min_order->id, 4, '0', STR_PAD_LEFT); ?>
                    &bull; <?php echo date('d M, h:i A', strtotime($min_order->created_at)); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #eee; border-radius:10px; padding:18px; display:flex; align-items:center; gap:14px;">
            <span style="font-size:32px;">🐌</span>
            <div>
                <div style="font-size:11px; font-weight:700; color:#ef4444; text-transform:uppercase;">Slowest Order</div>
                <div style="font-size:22px; font-weight:700; color:#1e293b;"><?php echo $max_prep; ?> <span style="font-size:13px; color:#64748b;">minutes</span></div>
                <?php if ($max_order): ?>
                <div style="font-size:11px; color:#94a3b8; margin-top:3px;">
                    KOT #<?php echo str_pad($max_order->id, 4, '0', STR_PAD_LEFT); ?>
                    &bull; <?php echo date('d M, h:i A', strtotime($max_order->created_at)); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Chart -->
    <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
        <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Prep Time Distribution</h4>
        <canvas id="prep-time-chart" height="80"></canvas>
    </div>

    <!-- Table -->
    <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
        <table style="float: left;width:100%; border-collapse:collapse; text-align:left; margin: 0;">
            <thead style="background:#f8fafc;">
                <tr>
                    <th style="padding:12px;">KOT #</th>
                    <th style="padding:12px;">Table / Type</th>
                    <th style="padding:12px; text-align:center;">Items (Qty)</th>
                    <th style="padding:12px;">Order Placed</th>
                    <th style="padding:12px;">Ready At</th>
                    <th style="padding:12px; text-align:center;">Prep Time</th>
                    <th style="padding:12px;">Speed</th>
                    <th style="padding:12px; text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ( $orders as $row ) :
                $prep = intval($row->prep_minutes);
                if ( $prep < 15 )      { $speed = '🚀 Fast';   $sp_bg = '#d1fae5'; $sp_color = '#065f46'; }
                elseif ( $prep <= 30 ) { $speed = '🕐 Normal'; $sp_bg = '#fef3c7'; $sp_color = '#92400e'; }
                else                   { $speed = '🐢 Slow';   $sp_bg = '#fee2e2'; $sp_color = '#991b1b'; }

                $table_val  = strtolower(trim($row->table_name));
                $type_label = ( $table_val === 'take out' || $table_val === 'takeout' ) ? 'Takeaway'
                            : ( $table_val === 'delivery' ? 'Delivery' : $row->table_name );
                $inv_no     = '#' . date('Ym', strtotime($row->created_at)) . str_pad($row->id, 4, '0', STR_PAD_LEFT);
                $bar_color  = $prep < 15 ? '#10b981' : ($prep <= 30 ? '#f59e0b' : '#ef4444');
                $bar_pct    = $max_prep > 0 ? round(($prep / $max_prep) * 100) : 0;
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:700;"><?php echo $inv_no; ?></td>
                    <td style="padding:12px; font-size:12px; color:#475569;"><?php echo esc_html($type_label); ?></td>
                    <td style="padding:12px; text-align:center;">
                        <span style="font-weight:700;"><?php echo intval($row->unique_items); ?></span>
                        <span style="color:#94a3b8; font-size:11px;"> / <?php echo intval($row->total_qty); ?> qty</span>
                    </td>
                    <td style="padding:12px; font-size:12px; color:#64748b;"><?php echo date('h:i A', strtotime($row->created_at)); ?></td>
                    <td style="padding:12px; font-size:12px; color:#64748b;"><?php echo date('h:i A', strtotime($row->ready_at)); ?></td>
                    <td style="padding:12px; text-align:center;">
                        <span style="font-weight:700; font-size:16px; color:#1e293b;"><?php echo $prep; ?> min</span>
                        <div style="width:100%; height:5px; background:#f1f5f9; border-radius:3px; margin-top:4px;">
                            <div style="width:<?php echo $bar_pct; ?>%; height:100%; background:<?php echo $bar_color; ?>; border-radius:3px;"></div>
                        </div>
                    </td>
                    <td style="padding:12px;">
                        <span style="background:<?php echo $sp_bg; ?>; color:<?php echo $sp_color; ?>; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                            <?php echo $speed; ?>
                        </span>
                    </td>
                    <td style="padding:12px; text-align:right; font-weight:600; color:#2271b1;"><?php echo $currency . number_format(floatval($row->grand_total), 2); ?></td>
                </tr>
            <?php endforeach; ?>
                <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                    <td style="padding:12px;" colspan="5">AVG PREP TIME</td>
                    <td style="padding:12px; text-align:center; color:#2271b1; font-size:15px;"><?php echo $avg_prep; ?> min</td>
                    <td style="padding:12px;"></td>
                    <td style="padding:12px;"></td>
                </tr>
            </tbody>
        </table>
    </div>

    <script>
    (function() {
        var labels = <?php echo json_encode(array_map(function($o) {
            return '#' . str_pad($o->id, 4, '0', STR_PAD_LEFT);
        }, array_reverse($orders))); ?>;
        var times  = <?php echo json_encode(array_map(function($o) {
            return intval($o->prep_minutes);
        }, array_reverse($orders))); ?>;
        var colors = times.map(function(t) {
            return t < 15 ? '#10b981' : (t <= 30 ? '#f59e0b' : '#ef4444');
        });

        var ctx = document.getElementById('prep-time-chart');
        if (!ctx) return;
        if (window._prepTimeChart) window._prepTimeChart.destroy();
        window._prepTimeChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Prep Time (min)',
                    data: times,
                    backgroundColor: colors,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' },
                         title: { display: true, text: 'Minutes' } }
                }
            }
        });
    })();
    </script>

    <?php
    $output = ob_get_clean();
    wp_send_json_success(array('data' => $output));
    return;
}

    wp_send_json_error('Invalid report type.');
}
add_action('wp_ajax_fetch_kitchen_report', 'handle_fetch_kitchen_report');