<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ===================== PAYMENT REPORT =====================
function handle_fetch_payment_report() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }
    if ( function_exists( 'qrrs_free_report_ajax_guard' ) ) {
        qrrs_free_report_ajax_guard( 'Payment Report' );
    }

    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id      = intval($params['restaurant_id']);
    $date_range  = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';
    $report_type = isset($params['payment_report_type']) ? sanitize_text_field($params['payment_report_type']) : 'method_breakdown';

    if ( empty($date_range) ) {
        wp_send_json_success(array('data' => '<p style="text-align:center; padding:20px;">Please select a date range.</p>'));
        return;
    }

    $dates      = explode(" to ", $date_range);
    $start_date = trim($dates[0]);
    $end_date   = isset($dates[1]) ? trim($dates[1]) : $start_date;

    $currency     = get_qrrs_restaurant_currency($res_id);
    $orders_table = $wpdb->prefix . 'qrrs_orders';

    // ===================== METHOD BREAKDOWN =====================
    if ( $report_type === 'method_breakdown' ) {

        $methods = $wpdb->get_results($wpdb->prepare(
            "SELECT
                COALESCE(NULLIF(TRIM(payment_method), ''), 'unknown') AS method,
                COUNT(id)         AS total_orders,
                SUM(COALESCE(final_total, grand_total)) AS total_amount,
                AVG(COALESCE(final_total, grand_total)) AS avg_amount,
                MIN(COALESCE(final_total, grand_total)) AS min_amount,
                MAX(COALESCE(final_total, grand_total)) AS max_amount
             FROM {$orders_table}
             WHERE restaurant_id = %d
               AND DATE(created_at) BETWEEN %s AND %s
               AND order_status = 'completed'
               AND payment_status = 'paid'
             GROUP BY method
             ORDER BY total_amount DESC",
            $res_id, $start_date, $end_date
        ));

        // Daily trend per method
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at) AS order_date,
                COALESCE(NULLIF(TRIM(payment_method), ''), 'unknown') AS method,
                COUNT(id)         AS orders,
                SUM(COALESCE(final_total, grand_total)) AS amount
             FROM {$orders_table}
             WHERE restaurant_id = %d
               AND DATE(created_at) BETWEEN %s AND %s
               AND order_status = 'completed'
               AND payment_status = 'paid'
             GROUP BY DATE(created_at), method
             ORDER BY order_date ASC",
            $res_id, $start_date, $end_date
        ));

        if ( $wpdb->last_error ) {
            wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
            return;
        }

        if ( empty($methods) ) {
            wp_send_json_success(array('data' => '<div style="text-align:center; padding:50px; color:#94a3b8;"><p>No payment records found for this period.</p></div>'));
            return;
        }

        // Totals
        $g_orders = 0;
        $g_amount = 0;
        foreach ( $methods as $m ) {
            $g_orders += intval($m->total_orders);
            $g_amount += floatval($m->total_amount);
        }

        // Method config (icon + color)
        $method_cfg = array(
            'cash'    => array('icon' => '💵', 'color' => '#10b981', 'label' => 'Cash'),
            'card'    => array('icon' => '💳', 'color' => '#3b82f6', 'label' => 'Card'),
            'bkash'   => array('icon' => '🔴', 'color' => '#e91e8c', 'label' => 'bKash'),
            'nagad'   => array('icon' => '🟠', 'color' => '#f97316', 'label' => 'Nagad'),
            'rocket'  => array('icon' => '🟣', 'color' => '#8b5cf6', 'label' => 'Rocket'),
            'upay'    => array('icon' => '🔵', 'color' => '#06b6d4', 'label' => 'Upay'),
            'unknown' => array('icon' => '❓', 'color' => '#94a3b8', 'label' => 'Unknown'),
        );

        // Collect unique methods for chart
        $all_methods = array();
        foreach ( $methods as $m ) {
            $all_methods[] = strtolower($m->method);
        }

        // Build daily chart data per method
        $dates_list   = array();
        $method_daily = array();
        foreach ( $daily as $d ) {
            $dt  = $d->order_date;
            $mth = strtolower($d->method);
            if ( ! in_array($dt, $dates_list) ) $dates_list[] = $dt;
            if ( ! isset($method_daily[$mth]) ) $method_daily[$mth] = array();
            $method_daily[$mth][$dt] = floatval($d->amount);
        }

        ob_start();
        ?>

        <!-- Method Cards -->
        <div style="display:grid; grid-template-columns:repeat(<?php echo min(count($methods), 4); ?>,1fr); gap:15px; margin-bottom:25px;">
        <?php foreach ( $methods as $m ) :
            $mkey = strtolower($m->method);
            $cfg  = isset($method_cfg[$mkey]) ? $method_cfg[$mkey] : array('icon' => '💰', 'color' => '#64748b', 'label' => ucfirst($m->method));
            $pct  = $g_amount > 0 ? round((floatval($m->total_amount) / $g_amount) * 100, 1) : 0;
        ?>
            <div style="background:#fff; border:2px solid <?php echo $cfg['color']; ?>; border-radius:12px; padding:20px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <span style="font-size:24px;"><?php echo $cfg['icon']; ?></span>
                    <span style="font-size:12px; font-weight:700; color:<?php echo $cfg['color']; ?>; text-transform:uppercase;"><?php echo $cfg['label']; ?></span>
                </div>
                <div style="font-size:20px; font-weight:700; color:#1e293b;"><?php echo $currency . number_format(floatval($m->total_amount), 2); ?></div>
                <div style="font-size:12px; color:#64748b; margin-top:3px;"><?php echo $m->total_orders; ?> orders &bull; <?php echo $pct; ?>%</div>
                <div style="margin-top:10px; height:5px; background:#f1f5f9; border-radius:3px;">
                    <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $cfg['color']; ?>; border-radius:3px;"></div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <!-- Summary -->
        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total Collection</span>
                <h3 style="margin:5px 0 0; color:#2ecc71; font-size:22px;"><?php echo $currency . number_format($g_amount, 2); ?></h3>
                <small style="opacity:0.6;"><?php echo $g_orders; ?> paid orders</small>
            </div>
            <div style="background:#fff; border:1px solid #eee; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase;">Payment Methods</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo count($methods); ?></h3>
                <small style="color:#64748b;">types used</small>
            </div>
            <div style="background:#fff; border:1px solid #eee; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase;">Avg. Per Order</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_orders > 0 ? $currency . number_format($g_amount / $g_orders, 2) : '—'; ?></h3>
            </div>
        </div>

        <!-- Charts -->
        <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:20px;">
            <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px;">
                <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Daily Collection by Method</h4>
                <canvas id="payment-bar-chart" height="100"></canvas>
            </div>
            <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px;">
                <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Payment Share</h4>
                <canvas id="payment-pie-chart" height="100"></canvas>
            </div>
        </div>

        <!-- Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">Payment Method</th>
                        <th style="padding:12px; text-align:center;">Orders</th>
                        <th style="padding:12px; text-align:right;">Total Amount</th>
                        <th style="padding:12px; text-align:right;">Avg. Amount</th>
                        <th style="padding:12px; text-align:right;">Min Order</th>
                        <th style="padding:12px; text-align:right;">Max Order</th>
                        <th style="padding:12px; text-align:right;">% Share</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $methods as $m ) :
                    $mkey = strtolower($m->method);
                    $cfg  = isset($method_cfg[$mkey]) ? $method_cfg[$mkey] : array('icon' => '💰', 'color' => '#64748b', 'label' => ucfirst($m->method));
                    $pct  = $g_amount > 0 ? round((floatval($m->total_amount) / $g_amount) * 100, 1) : 0;
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span style="font-size:20px;"><?php echo $cfg['icon']; ?></span>
                                <span style="font-weight:600; color:#1e293b;"><?php echo esc_html($cfg['label']); ?></span>
                            </div>
                        </td>
                        <td style="padding:12px; text-align:center; font-weight:700;"><?php echo $m->total_orders; ?></td>
                        <td style="padding:12px; text-align:right; font-weight:700; color:#2271b1;"><?php echo $currency . number_format(floatval($m->total_amount), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#64748b;"><?php echo $currency . number_format(floatval($m->avg_amount), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#10b981;"><?php echo $currency . number_format(floatval($m->min_amount), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#f59e0b;"><?php echo $currency . number_format(floatval($m->max_amount), 2); ?></td>
                        <td style="padding:12px; text-align:right;">
                            <div style="display:flex; align-items:center; justify-content:flex-end; gap:8px;">
                                <div style="width:60px; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                    <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $cfg['color']; ?>; border-radius:3px;"></div>
                                </div>
                                <span style="font-size:12px; color:#475569; min-width:38px;"><?php echo $pct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;">TOTAL</td>
                        <td style="padding:12px; text-align:center;"><?php echo $g_orders; ?></td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($g_amount, 2); ?></td>
                        <td style="padding:12px;" colspan="3"></td>
                        <td style="padding:12px; text-align:right;">100%</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var allMethods  = <?php echo json_encode($all_methods); ?>;
            var datesList   = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d)); }, $dates_list)); ?>;
            var methodDaily = <?php echo json_encode($method_daily); ?>;
            var methodCfg   = <?php echo json_encode($method_cfg); ?>;
            var gAmount     = <?php echo $g_amount; ?>;
            var amounts     = <?php echo json_encode(array_map(function($m) { return round(floatval($m->total_amount), 2); }, $methods)); ?>;
            var currency    = "<?php echo esc_js($currency); ?>";

            var barDatasets = allMethods.map(function(mth) {
                var cfg    = methodCfg[mth] || { color: '#94a3b8', label: mth };
                var rawDates = <?php echo json_encode($dates_list); ?>;
                var data   = rawDates.map(function(dt) {
                    return (methodDaily[mth] && methodDaily[mth][dt]) ? methodDaily[mth][dt] : 0;
                });
                return { label: cfg.label || mth, data: data, backgroundColor: cfg.color, borderRadius: 4 };
            });

            var barCtx = document.getElementById('payment-bar-chart');
            if (barCtx) {
                if (window._payBarChart) window._payBarChart.destroy();
                window._payBarChart = new Chart(barCtx, {
                    type: 'bar',
                    data: { labels: datesList, datasets: barDatasets },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'top' } },
                        scales: {
                            x: { stacked: true, grid: { display: false } },
                            y: { stacked: true, beginAtZero: true, grid: { color: '#f1f5f9' } }
                        }
                    }
                });
            }

            var pieColors = allMethods.map(function(m) { return (methodCfg[m] || {}).color || '#94a3b8'; });
            var pieLabels = allMethods.map(function(m) { return (methodCfg[m] || {}).label || m; });

            var pieCtx = document.getElementById('payment-pie-chart');
            if (pieCtx) {
                if (window._payPieChart) window._payPieChart.destroy();
                window._payPieChart = new Chart(pieCtx, {
                    type: 'doughnut',
                    data: {
                        labels: pieLabels,
                        datasets: [{ data: amounts, backgroundColor: pieColors, borderWidth: 0, hoverOffset: 6 }]
                    },
                    options: {
                        responsive: true,
                        cutout: '65%',
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }
        })();
        </script>

        <?php
        $output = ob_get_clean();
        wp_send_json_success(array('data' => $output));
        return;
    }

    // ===================== DUE / UNPAID ORDERS =====================
    if ( $report_type === 'due_unpaid' ) {

        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT
                o.id,
                o.table_name,
                o.order_status,
                o.payment_status,
                o.grand_total,
                COALESCE(o.final_total, o.grand_total) AS payable,
                COALESCE(o.amount_received, 0)          AS amount_received,
                (COALESCE(o.final_total, o.grand_total) - COALESCE(o.amount_received, 0)) AS due_amount,
                o.created_at,
                COALESCE(u.display_name, CONCAT('User #', o.waiter_id)) AS staff_name
             FROM {$orders_table} o
             LEFT JOIN {$wpdb->prefix}users u ON u.ID = o.waiter_id
             WHERE o.restaurant_id = %d
               AND DATE(o.created_at) BETWEEN %s AND %s
               AND (o.payment_status = 'unpaid' OR o.payment_status IS NULL OR o.payment_status = '')
             ORDER BY o.created_at DESC",
            $res_id, $start_date, $end_date
        ));

        if ( $wpdb->last_error ) {
            wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
            return;
        }

        if ( empty($orders) ) {
            wp_send_json_success(array('data' => '
                <div style="text-align:center; padding:60px; color:#10b981;">
                    <div style="font-size:50px; margin-bottom:10px;">✅</div>
                    <h3 style="color:#10b981;">All Clear!</h3>
                    <p style="color:#64748b;">No unpaid or due orders found for this period.</p>
                </div>
            '));
            return;
        }

        $g_due = $g_payable = $g_received = 0;
        $g_completed_unpaid = $g_active_unpaid = 0;
        foreach ( $orders as $o ) {
            $g_payable  += floatval($o->payable);
            $g_received += floatval($o->amount_received);
            $g_due      += floatval($o->due_amount);
            if ( $o->order_status === 'completed' ) $g_completed_unpaid++;
            else                                    $g_active_unpaid++;
        }

        ob_start();
        ?>

        <!-- Alert Banner -->
        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:16px 20px; margin-bottom:20px; display:flex; align-items:center; gap:12px;">
            <span style="font-size:28px;">⚠️</span>
            <div>
                <div style="font-weight:700; color:#dc2626; font-size:15px;"><?php echo count($orders); ?> Unpaid Order(s) Found</div>
                <div style="color:#64748b; font-size:13px; margin-top:2px;">Total due amount: <strong style="color:#dc2626;"><?php echo $currency . number_format($g_due, 2); ?></strong></div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#fef2f2; border:2px solid #ef4444; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#ef4444; text-transform:uppercase;">💸 Total Due</span>
                <h3 style="margin:5px 0 0; color:#1e293b; font-size:22px;"><?php echo $currency . number_format($g_due, 2); ?></h3>
                <small style="color:#64748b;"><?php echo count($orders); ?> orders</small>
            </div>
            <div style="background:#fff; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;">📋 Total Payable</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_payable, 2); ?></h3>
            </div>
            <div style="background:#fff; border:2px solid #10b981; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;">✅ Partially Paid</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_received, 2); ?></h3>
            </div>
            <div style="background:#fff; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;">🔄 Active Unpaid</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_active_unpaid; ?></h3>
                <small style="color:#64748b;"><?php echo $g_completed_unpaid; ?> completed but unpaid</small>
            </div>
        </div>

        <!-- Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">Invoice #</th>
                        <th style="padding:12px;">Table / Type</th>
                        <th style="padding:12px;">Staff</th>
                        <th style="padding:12px;">Order Time</th>
                        <th style="padding:12px; text-align:right;">Payable</th>
                        <th style="padding:12px; text-align:right;">Received</th>
                        <th style="padding:12px; text-align:right;">Due</th>
                        <th style="padding:12px; text-align:center;">Order Status</th>
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

                    $due = floatval($row->due_amount);
                    $inv_no = '#' . date('Ym', strtotime($row->created_at)) . str_pad($row->id, 4, '0', STR_PAD_LEFT);

                    $s = $row->order_status;
                    if ( $s === 'completed' )      { $s_label = 'Completed';  $s_bg = '#d1fae5'; $s_color = '#065f46'; }
                    elseif ( $s === 'pending' )    { $s_label = 'Pending';    $s_bg = '#fef3c7'; $s_color = '#92400e'; }
                    elseif ( $s === 'processing' ) { $s_label = 'Processing'; $s_bg = '#ede9fe'; $s_color = '#5b21b6'; }
                    elseif ( $s === 'ready' )      { $s_label = 'Ready';      $s_bg = '#dbeafe'; $s_color = '#1e40af'; }
                    elseif ( $s === 'served' )     { $s_label = 'Served';     $s_bg = '#dbeafe'; $s_color = '#1e40af'; }
                    else                           { $s_label = ucfirst($s);  $s_bg = '#f1f5f9'; $s_color = '#475569'; }
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9; <?php echo $due > 0 ? 'background:#fffbeb;' : ''; ?>">
                        <td style="padding:12px; font-weight:700; color:#1e293b;"><?php echo $inv_no; ?></td>
                        <td style="padding:12px;">
                            <span style="background:<?php echo $type_bg; ?>; color:<?php echo $type_color; ?>; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                                <?php echo esc_html($type_label); ?>
                            </span>
                        </td>
                        <td style="padding:12px; font-size:12px; color:#475569;"><?php echo esc_html($row->staff_name); ?></td>
                        <td style="padding:12px; font-size:12px; color:#64748b;"><?php echo date('d M, h:i A', strtotime($row->created_at)); ?></td>
                        <td style="padding:12px; text-align:right; font-weight:600;"><?php echo $currency . number_format(floatval($row->payable), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#10b981;"><?php echo $currency . number_format(floatval($row->amount_received), 2); ?></td>
                        <td style="padding:12px; text-align:right; font-weight:700; color:<?php echo $due > 0 ? '#ef4444' : '#10b981'; ?>; font-size:15px;">
                            <?php echo $currency . number_format($due, 2); ?>
                        </td>
                        <td style="padding:12px; text-align:center;">
                            <span style="background:<?php echo $s_bg; ?>; color:<?php echo $s_color; ?>; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                                <?php echo $s_label; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#fef2f2; border-top:2px solid #ef4444; font-weight:700;">
                        <td style="padding:12px;" colspan="4">TOTAL DUE</td>
                        <td style="padding:12px; text-align:right; color:#1e293b;"><?php echo $currency . number_format($g_payable, 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#10b981;"><?php echo $currency . number_format($g_received, 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#ef4444; font-size:15px;"><?php echo $currency . number_format($g_due, 2); ?></td>
                        <td style="padding:12px;"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php
        $output = ob_get_clean();
        wp_send_json_success(array('data' => $output));
        return;
    }

    wp_send_json_error('Invalid report type.');
}
add_action('wp_ajax_fetch_payment_report', 'handle_fetch_payment_report');


// ===================== VAT / SERVICE CHARGE REPORT =====================
function handle_fetch_tax_report() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }
    if ( function_exists( 'qrrs_free_report_ajax_guard' ) ) {
        qrrs_free_report_ajax_guard( 'VAT/Service Report' );
    }

    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id      = intval($params['restaurant_id']);
    $date_range  = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';
    $report_type = isset($params['tax_report_type']) ? sanitize_text_field($params['tax_report_type']) : 'vat';

    if ( empty($date_range) ) {
        wp_send_json_success(array('data' => '<p style="text-align:center; padding:20px;">Please select a date range.</p>'));
        return;
    }

    $dates      = explode(" to ", $date_range);
    $start_date = trim($dates[0]);
    $end_date   = isset($dates[1]) ? trim($dates[1]) : $start_date;

    $currency     = get_qrrs_restaurant_currency($res_id);
    $orders_table = $wpdb->prefix . 'qrrs_orders';

    // Shared daily query for both VAT and Service Charge
    $daily = $wpdb->get_results($wpdb->prepare(
        "SELECT
            DATE(created_at)     AS order_date,
            COUNT(id)            AS total_orders,
            SUM(total_amount)    AS subtotal,
            SUM(COALESCE(tax_amount, 0))      AS total_vat,
            SUM(COALESCE(service_charge, 0))  AS total_service,
            SUM(grand_total)     AS grand_total,
            AVG(CASE WHEN tax_amount > 0 THEN (tax_amount / NULLIF(total_amount, 0)) * 100 END) AS avg_vat_pct,
            AVG(CASE WHEN service_charge > 0 THEN (service_charge / NULLIF(total_amount, 0)) * 100 END) AS avg_svc_pct
         FROM {$orders_table}
         WHERE restaurant_id = %d
           AND DATE(created_at) BETWEEN %s AND %s
           AND order_status = 'completed'
         GROUP BY DATE(created_at)
         ORDER BY order_date ASC",
        $res_id, $start_date, $end_date
    ));

    if ( $wpdb->last_error ) {
        wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
        return;
    }

    if ( empty($daily) ) {
        wp_send_json_success(array('data' => '<div style="text-align:center; padding:50px; color:#94a3b8;"><p>No completed orders found for this period.</p></div>'));
        return;
    }

    // Grand totals
    $g_orders  = $g_subtotal = $g_vat = $g_service = $g_grand = 0;
    foreach ( $daily as $d ) {
        $g_orders  += intval($d->total_orders);
        $g_subtotal += floatval($d->subtotal);
        $g_vat      += floatval($d->total_vat);
        $g_service  += floatval($d->total_service);
        $g_grand    += floatval($d->grand_total);
    }

    $has_vat     = $g_vat > 0;
    $has_service = $g_service > 0;

    if ( $report_type === 'vat' && ! $has_vat ) {
        wp_send_json_success(array('data' => '
            <div style="text-align:center; padding:60px; color:#94a3b8;">
                <div style="font-size:50px; margin-bottom:10px;">📜</div>
                <h3>VAT Not Enabled</h3>
                <p>No VAT charges found for this period.<br>Enable VAT from restaurant settings to track it here.</p>
            </div>
        '));
        return;
    }

    if ( $report_type === 'service_charge' && ! $has_service ) {
        wp_send_json_success(array('data' => '
            <div style="text-align:center; padding:60px; color:#94a3b8;">
                <div style="font-size:50px; margin-bottom:10px;">🧾</div>
                <h3>Service Charge Not Enabled</h3>
                <p>No service charges found for this period.<br>Enable service charge from restaurant settings.</p>
            </div>
        '));
        return;
    }

    // ===== VAT REPORT =====
    if ( $report_type === 'vat' ) {
        $vat_pct_overall = $g_subtotal > 0 ? round(($g_vat / $g_subtotal) * 100, 2) : 0;

        ob_start();
        ?>

        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">📜 Total VAT Collected</span>
                <h3 style="margin:5px 0 0; color:#f59e0b; font-size:22px;"><?php echo $currency . number_format($g_vat, 2); ?></h3>
                <small style="opacity:0.6;"><?php echo $g_orders; ?> completed orders</small>
            </div>
            <div style="background:#fff; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;">VAT Rate (Avg)</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $vat_pct_overall; ?>%</h3>
                <small style="color:#64748b;">of subtotal</small>
            </div>
            <div style="background:#fff; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;">Total Subtotal</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_subtotal, 2); ?></h3>
            </div>
            <div style="background:#fff; border:2px solid #10b981; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;">Grand Total</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_grand, 2); ?></h3>
            </div>
        </div>

        <!-- Chart -->
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Daily VAT Collection</h4>
            <canvas id="vat-chart" height="80"></canvas>
        </div>

        <!-- Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">Date</th>
                        <th style="padding:12px; text-align:center;">Orders</th>
                        <th style="padding:12px; text-align:right;">Subtotal</th>
                        <th style="padding:12px; text-align:right; color:#f59e0b;">VAT Amount</th>
                        <th style="padding:12px; text-align:right;">VAT %</th>
                        <th style="padding:12px; text-align:right;">Grand Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $daily as $row ) :
                    $row_vat_pct = floatval($row->subtotal) > 0 ? round((floatval($row->total_vat) / floatval($row->subtotal)) * 100, 2) : 0;
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; font-weight:500;"><?php echo date('M d, Y', strtotime($row->order_date)); ?></td>
                        <td style="padding:12px; text-align:center;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px; text-align:right;"><?php echo $currency . number_format(floatval($row->subtotal), 2); ?></td>
                        <td style="padding:12px; text-align:right; font-weight:700; color:#f59e0b;"><?php echo $currency . number_format(floatval($row->total_vat), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#64748b;"><?php echo $row_vat_pct; ?>%</td>
                        <td style="padding:12px; text-align:right; font-weight:600; color:#2271b1;"><?php echo $currency . number_format(floatval($row->grand_total), 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;">TOTAL</td>
                        <td style="padding:12px; text-align:center;"><?php echo $g_orders; ?></td>
                        <td style="padding:12px; text-align:right;"><?php echo $currency . number_format($g_subtotal, 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#f59e0b; font-size:15px;"><?php echo $currency . number_format($g_vat, 2); ?></td>
                        <td style="padding:12px; text-align:right;"><?php echo $vat_pct_overall; ?>%</td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($g_grand, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var labels   = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d->order_date)); }, $daily)); ?>;
            var subtotal = <?php echo json_encode(array_map(function($d) { return round(floatval($d->subtotal), 2); }, $daily)); ?>;
            var vat      = <?php echo json_encode(array_map(function($d) { return round(floatval($d->total_vat), 2); }, $daily)); ?>;

            var ctx = document.getElementById('vat-chart');
            if (!ctx) return;
            if (window._vatChart) window._vatChart.destroy();
            window._vatChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Subtotal', data: subtotal, backgroundColor: '#dbeafe', borderRadius: 4 },
                        { label: 'VAT',      data: vat,      backgroundColor: '#f59e0b', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } }
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

    // ===== SERVICE CHARGE REPORT =====
    if ( $report_type === 'service_charge' ) {
        $svc_pct_overall = $g_subtotal > 0 ? round(($g_service / $g_subtotal) * 100, 2) : 0;

        ob_start();
        ?>

        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">🧾 Total Service Charge</span>
                <h3 style="margin:5px 0 0; color:#8b5cf6; font-size:22px;"><?php echo $currency . number_format($g_service, 2); ?></h3>
                <small style="opacity:0.6;"><?php echo $g_orders; ?> completed orders</small>
            </div>
            <div style="background:#fff; border:2px solid #8b5cf6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#8b5cf6; text-transform:uppercase;">Service Rate (Avg)</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $svc_pct_overall; ?>%</h3>
                <small style="color:#64748b;">of subtotal</small>
            </div>
            <div style="background:#fff; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;">Total Subtotal</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_subtotal, 2); ?></h3>
            </div>
            <div style="background:#fff; border:2px solid #10b981; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;">Grand Total</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_grand, 2); ?></h3>
            </div>
        </div>

        <!-- Chart -->
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Daily Service Charge Collection</h4>
            <canvas id="svc-chart" height="80"></canvas>
        </div>

        <!-- Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">Date</th>
                        <th style="padding:12px; text-align:center;">Orders</th>
                        <th style="padding:12px; text-align:right;">Subtotal</th>
                        <th style="padding:12px; text-align:right; color:#8b5cf6;">Service Charge</th>
                        <th style="padding:12px; text-align:right;">Rate %</th>
                        <th style="padding:12px; text-align:right;">Grand Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $daily as $row ) :
                    $row_svc_pct = floatval($row->subtotal) > 0 ? round((floatval($row->total_service) / floatval($row->subtotal)) * 100, 2) : 0;
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; font-weight:500;"><?php echo date('M d, Y', strtotime($row->order_date)); ?></td>
                        <td style="padding:12px; text-align:center;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px; text-align:right;"><?php echo $currency . number_format(floatval($row->subtotal), 2); ?></td>
                        <td style="padding:12px; text-align:right; font-weight:700; color:#8b5cf6;"><?php echo $currency . number_format(floatval($row->total_service), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#64748b;"><?php echo $row_svc_pct; ?>%</td>
                        <td style="padding:12px; text-align:right; font-weight:600; color:#2271b1;"><?php echo $currency . number_format(floatval($row->grand_total), 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;">TOTAL</td>
                        <td style="padding:12px; text-align:center;"><?php echo $g_orders; ?></td>
                        <td style="padding:12px; text-align:right;"><?php echo $currency . number_format($g_subtotal, 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#8b5cf6; font-size:15px;"><?php echo $currency . number_format($g_service, 2); ?></td>
                        <td style="padding:12px; text-align:right;"><?php echo $svc_pct_overall; ?>%</td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($g_grand, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var labels  = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d->order_date)); }, $daily)); ?>;
            var subtotal = <?php echo json_encode(array_map(function($d) { return round(floatval($d->subtotal), 2); }, $daily)); ?>;
            var service = <?php echo json_encode(array_map(function($d) { return round(floatval($d->total_service), 2); }, $daily)); ?>;

            var ctx = document.getElementById('svc-chart');
            if (!ctx) return;
            if (window._svcChart) window._svcChart.destroy();
            window._svcChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Subtotal',       data: subtotal, backgroundColor: '#ede9fe', borderRadius: 4 },
                        { label: 'Service Charge', data: service,  backgroundColor: '#8b5cf6', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } }
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
add_action('wp_ajax_fetch_tax_report', 'handle_fetch_tax_report');
