<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function handle_fetch_order_report() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }

    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id      = intval($params['restaurant_id']);
    $date_range  = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';
    $report_type = isset($params['order_report_type']) ? sanitize_text_field($params['order_report_type']) : 'total_orders';

    if ( empty($date_range) ) {
        wp_send_json_success(array('data' => '<p style="text-align:center; padding:20px;">Please select a date range.</p>'));
        return;
    }

    $dates      = explode(" to ", $date_range);
    $start_date = trim($dates[0]);
    $end_date   = isset($dates[1]) ? trim($dates[1]) : $start_date;

    $currency     = get_qrrs_restaurant_currency($res_id);
    $orders_table = $wpdb->prefix . 'qrrs_orders';

    // ===================== TOTAL ORDERS =====================
    if ( $report_type === 'total_orders' ) {

        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at)  AS order_date,
                COUNT(id)         AS total_orders,
                SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END)  AS completed,
                SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END)  AS cancelled,
                SUM(CASE WHEN order_status = 'pending'   THEN 1 ELSE 0 END)  AS pending,
                SUM(CASE WHEN LOWER(table_name) NOT IN ('take out','takeout','delivery') THEN 1 ELSE 0 END) AS dinein,
                SUM(CASE WHEN LOWER(table_name) IN ('take out','takeout') THEN 1 ELSE 0 END)               AS takeaway,
                SUM(CASE WHEN LOWER(table_name) = 'delivery' THEN 1 ELSE 0 END)                            AS delivery
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

        // Totals
        $g_total = $g_completed = $g_cancelled = $g_pending = $g_dinein = $g_takeaway = $g_delivery = 0;
        foreach ( $daily as $d ) {
            $g_total     += intval($d->total_orders);
            $g_completed += intval($d->completed);
            $g_cancelled += intval($d->cancelled);
            $g_pending   += intval($d->pending);
            $g_dinein    += intval($d->dinein);
            $g_takeaway  += intval($d->takeaway);
            $g_delivery  += intval($d->delivery);
        }

        ob_start();
        ?>

        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total Orders</span>
                <h3 style="margin:5px 0 0; font-size:26px;"><?php echo $g_total; ?></h3>
            </div>
            <div style="background:#fff; border:2px solid #10b981; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;">✅ Completed</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_completed; ?></h3>
                <small style="color:#64748b;"><?php echo $g_total > 0 ? round(($g_completed/$g_total)*100,1) : 0; ?>%</small>
            </div>
            <div style="background:#fff; border:2px solid #ef4444; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#ef4444; text-transform:uppercase;">❌ Cancelled</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_cancelled; ?></h3>
                <small style="color:#64748b;"><?php echo $g_total > 0 ? round(($g_cancelled/$g_total)*100,1) : 0; ?>%</small>
            </div>
            <div style="background:#fff; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;">⏳ Pending</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_pending; ?></h3>
                <small style="color:#64748b;"><?php echo $g_total > 0 ? round(($g_pending/$g_total)*100,1) : 0; ?>%</small>
            </div>
        </div>

        <!-- Type breakdown -->
        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:15px; border-radius:10px; display:flex; align-items:center; gap:12px;">
                <span style="font-size:28px;">🍽️</span>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#10b981; text-transform:uppercase;">Dine-in</div>
                    <div style="font-size:20px; font-weight:700; color:#1e293b;"><?php echo $g_dinein; ?> <span style="font-size:12px; color:#64748b;">orders</span></div>
                </div>
            </div>
            <div style="background:#fffbeb; border:1px solid #fde68a; padding:15px; border-radius:10px; display:flex; align-items:center; gap:12px;">
                <span style="font-size:28px;">🥡</span>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#f59e0b; text-transform:uppercase;">Takeaway</div>
                    <div style="font-size:20px; font-weight:700; color:#1e293b;"><?php echo $g_takeaway; ?> <span style="font-size:12px; color:#64748b;">orders</span></div>
                </div>
            </div>
            <div style="background:#eff6ff; border:1px solid #bfdbfe; padding:15px; border-radius:10px; display:flex; align-items:center; gap:12px;">
                <span style="font-size:28px;">🛵</span>
                <div>
                    <div style="font-size:11px; font-weight:700; color:#3b82f6; text-transform:uppercase;">Delivery</div>
                    <div style="font-size:20px; font-weight:700; color:#1e293b;"><?php echo $g_delivery; ?> <span style="font-size:12px; color:#64748b;">orders</span></div>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Daily Order Count</h4>
            <canvas id="total-orders-chart" height="80"></canvas>
        </div>

        <!-- Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">Date</th>
                        <th style="padding:12px; text-align:center;">Total</th>
                        <th style="padding:12px; text-align:center; color:#10b981;">Completed</th>
                        <th style="padding:12px; text-align:center; color:#ef4444;">Cancelled</th>
                        <th style="padding:12px; text-align:center; color:#f59e0b;">Pending</th>
                        <th style="padding:12px; text-align:center;">Dine-in</th>
                        <th style="padding:12px; text-align:center;">Takeaway</th>
                        <th style="padding:12px; text-align:center;">Delivery</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $daily as $row ) : ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; font-weight:500;"><?php echo date('M d, Y', strtotime($row->order_date)); ?></td>
                        <td style="padding:12px; text-align:center; font-weight:700;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981; font-weight:600;"><?php echo $row->completed; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $row->cancelled; ?></td>
                        <td style="padding:12px; text-align:center; color:#f59e0b;"><?php echo $row->pending; ?></td>
                        <td style="padding:12px; text-align:center;"><?php echo $row->dinein; ?></td>
                        <td style="padding:12px; text-align:center;"><?php echo $row->takeaway; ?></td>
                        <td style="padding:12px; text-align:center;"><?php echo $row->delivery; ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;">TOTAL</td>
                        <td style="padding:12px; text-align:center; color:#2271b1; font-size:15px;"><?php echo $g_total; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981;"><?php echo $g_completed; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $g_cancelled; ?></td>
                        <td style="padding:12px; text-align:center; color:#f59e0b;"><?php echo $g_pending; ?></td>
                        <td style="padding:12px; text-align:center;"><?php echo $g_dinein; ?></td>
                        <td style="padding:12px; text-align:center;"><?php echo $g_takeaway; ?></td>
                        <td style="padding:12px; text-align:center;"><?php echo $g_delivery; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var labels    = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d->order_date)); }, $daily)); ?>;
            var completed = <?php echo json_encode(array_map(function($d) { return intval($d->completed); }, $daily)); ?>;
            var cancelled = <?php echo json_encode(array_map(function($d) { return intval($d->cancelled); }, $daily)); ?>;
            var pending   = <?php echo json_encode(array_map(function($d) { return intval($d->pending); }, $daily)); ?>;

            var ctx = document.getElementById('total-orders-chart');
            if (!ctx) return;
            if (window._totalOrdersChart) window._totalOrdersChart.destroy();
            window._totalOrdersChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Completed', data: completed, backgroundColor: '#10b981', borderRadius: 4 },
                        { label: 'Cancelled', data: cancelled, backgroundColor: '#ef4444', borderRadius: 4 },
                        { label: 'Pending',   data: pending,   backgroundColor: '#f59e0b', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, grid: { color: '#f1f5f9' } }
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

    // ===================== AVERAGE ORDER VALUE =====================
    if ( $report_type === 'avg_order_value' ) {

        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at)     AS order_date,
                COUNT(id)            AS total_orders,
                SUM(grand_total)     AS total_revenue,
                AVG(grand_total)     AS avg_value,
                MIN(grand_total)     AS min_value,
                MAX(grand_total)     AS max_value,
                AVG(CASE WHEN LOWER(table_name) NOT IN ('take out','takeout','delivery') THEN grand_total END) AS avg_dinein,
                AVG(CASE WHEN LOWER(table_name) IN ('take out','takeout') THEN grand_total END)               AS avg_takeaway,
                AVG(CASE WHEN LOWER(table_name) = 'delivery' THEN grand_total END)                            AS avg_delivery
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
            wp_send_json_success(array('data' => '<div style="text-align:center; padding:50px; color:#94a3b8;"><p>No completed orders found.</p></div>'));
            return;
        }

        $g_orders  = 0;
        $g_revenue = 0;
        $g_min     = PHP_INT_MAX;
        $g_max     = 0;
        foreach ( $daily as $d ) {
            $g_orders  += intval($d->total_orders);
            $g_revenue += floatval($d->total_revenue);
            if ( floatval($d->min_value) < $g_min ) $g_min = floatval($d->min_value);
            if ( floatval($d->max_value) > $g_max ) $g_max = floatval($d->max_value);
        }
        $g_avg = $g_orders > 0 ? $g_revenue / $g_orders : 0;

        ob_start();
        ?>

        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Overall Avg. Value</span>
                <h3 style="margin:5px 0 0; color:#2ecc71; font-size:22px;"><?php echo $currency . number_format($g_avg, 2); ?></h3>
                <small style="opacity:0.6;"><?php echo $g_orders; ?> completed orders</small>
            </div>
            <div style="background:#fff; border:2px solid #8b5cf6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#8b5cf6; text-transform:uppercase;">Total Revenue</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_revenue, 2); ?></h3>
            </div>
            <div style="background:#fff; border:2px solid #10b981; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;">↑ Highest Order</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_max, 2); ?></h3>
            </div>
            <div style="background:#fff; border:2px solid #ef4444; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#ef4444; text-transform:uppercase;">↓ Lowest Order</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_min, 2); ?></h3>
            </div>
        </div>

        <!-- Chart -->
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Daily Average Order Value</h4>
            <canvas id="avg-order-chart" height="80"></canvas>
        </div>

        <!-- Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">Date</th>
                        <th style="padding:12px; text-align:center;">Orders</th>
                        <th style="padding:12px; text-align:right;">Avg. Value</th>
                        <th style="padding:12px; text-align:right;">Min Order</th>
                        <th style="padding:12px; text-align:right;">Max Order</th>
                        <th style="padding:12px; text-align:right;">Avg Dine-in</th>
                        <th style="padding:12px; text-align:right;">Avg Takeaway</th>
                        <th style="padding:12px; text-align:right;">Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $daily as $row ) : ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; font-weight:500;"><?php echo date('M d, Y', strtotime($row->order_date)); ?></td>
                        <td style="padding:12px; text-align:center; font-weight:600;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px; text-align:right; font-weight:700; color:#8b5cf6;"><?php echo $currency . number_format(floatval($row->avg_value), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#ef4444;"><?php echo $currency . number_format(floatval($row->min_value), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#10b981;"><?php echo $currency . number_format(floatval($row->max_value), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#10b981;"><?php echo $row->avg_dinein ? $currency . number_format(floatval($row->avg_dinein), 2) : '-'; ?></td>
                        <td style="padding:12px; text-align:right; color:#f59e0b;"><?php echo $row->avg_takeaway ? $currency . number_format(floatval($row->avg_takeaway), 2) : '-'; ?></td>
                        <td style="padding:12px; text-align:right; font-weight:600; color:#2271b1;"><?php echo $currency . number_format(floatval($row->total_revenue), 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;">TOTAL / AVG</td>
                        <td style="padding:12px; text-align:center;"><?php echo $g_orders; ?></td>
                        <td style="padding:12px; text-align:right; color:#8b5cf6; font-size:15px;"><?php echo $currency . number_format($g_avg, 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#ef4444;"><?php echo $currency . number_format($g_min, 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#10b981;"><?php echo $currency . number_format($g_max, 2); ?></td>
                        <td style="padding:12px;"></td>
                        <td style="padding:12px;"></td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($g_revenue, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var labels = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d->order_date)); }, $daily)); ?>;
            var avgs   = <?php echo json_encode(array_map(function($d) { return round(floatval($d->avg_value), 2); }, $daily)); ?>;
            var maxs   = <?php echo json_encode(array_map(function($d) { return round(floatval($d->max_value), 2); }, $daily)); ?>;
            var mins   = <?php echo json_encode(array_map(function($d) { return round(floatval($d->min_value), 2); }, $daily)); ?>;

            var ctx = document.getElementById('avg-order-chart');
            if (!ctx) return;
            if (window._avgOrderChart) window._avgOrderChart.destroy();
            window._avgOrderChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Avg Value', data: avgs, borderColor: '#8b5cf6', backgroundColor: 'rgba(139,92,246,0.08)', tension: 0.4, fill: true, pointRadius: 4 },
                        { label: 'Max',       data: maxs, borderColor: '#10b981', backgroundColor: 'transparent', tension: 0.4, borderDash: [5,5], pointRadius: 3 },
                        { label: 'Min',       data: mins, borderColor: '#ef4444', backgroundColor: 'transparent', tension: 0.4, borderDash: [5,5], pointRadius: 3 }
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

    // ===================== PEAK HOURS =====================
    if ( $report_type === 'peak_hours' ) {

        $hourly = $wpdb->get_results($wpdb->prepare(
            "SELECT
                HOUR(created_at)  AS order_hour,
                COUNT(id)         AS total_orders,
                SUM(grand_total)  AS total_revenue,
                AVG(grand_total)  AS avg_value
             FROM {$orders_table}
             WHERE restaurant_id = %d
               AND DATE(created_at) BETWEEN %s AND %s
               AND order_status = 'completed'
             GROUP BY HOUR(created_at)
             ORDER BY order_hour ASC",
            $res_id, $start_date, $end_date
        ));

        if ( $wpdb->last_error ) {
            wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
            return;
        }

        if ( empty($hourly) ) {
            wp_send_json_success(array('data' => '<div style="text-align:center; padding:50px; color:#94a3b8;"><p>No completed orders found.</p></div>'));
            return;
        }

        // Find peak, off-peak
        $max_orders  = 0;
        $peak_hour   = null;
        $g_orders    = 0;
        $g_revenue   = 0;

        foreach ( $hourly as $h ) {
            $g_orders  += intval($h->total_orders);
            $g_revenue += floatval($h->total_revenue);
            if ( intval($h->total_orders) > $max_orders ) {
                $max_orders = intval($h->total_orders);
                $peak_hour  = $h;
            }
        }

        // Build full 24hr array (fill missing hours with 0)
        $hours_map = array();
        foreach ( $hourly as $h ) {
            $hours_map[intval($h->order_hour)] = $h;
        }

        // Format hour label helper
        function format_hour_label($h) {
            if ($h === 0)  return '12 AM';
            if ($h < 12)  return $h . ' AM';
            if ($h === 12) return '12 PM';
            return ($h - 12) . ' PM';
        }

        // Peak period label
        $peak_label = format_hour_label(intval($peak_hour->order_hour)) . ' – ' . format_hour_label(intval($peak_hour->order_hour) + 1);

        // Classify hours
        $morning   = array(6,7,8,9,10,11);
        $afternoon = array(12,13,14,15,16,17);
        $evening   = array(18,19,20,21,22,23);

        $m_orders = $a_orders = $e_orders = 0;
        foreach ( $hourly as $h ) {
            $hr = intval($h->order_hour);
            if ( in_array($hr, $morning) )   $m_orders += intval($h->total_orders);
            if ( in_array($hr, $afternoon) ) $a_orders += intval($h->total_orders);
            if ( in_array($hr, $evening) )   $e_orders += intval($h->total_orders);
        }

        ob_start();
        ?>

        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">🔥 Peak Hour</span>
                <h3 style="margin:5px 0 0; color:#f59e0b; font-size:18px;"><?php echo $peak_label; ?></h3>
                <small style="opacity:0.7;"><?php echo $peak_hour->total_orders; ?> orders in this hour</small>
            </div>
            <div style="background:#fff; border:2px solid #f97316; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#f97316; text-transform:uppercase;">🌅 Morning (6–12)</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $m_orders; ?> <span style="font-size:13px; color:#64748b;">orders</span></h3>
            </div>
            <div style="background:#fff; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;">☀️ Afternoon (12–18)</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $a_orders; ?> <span style="font-size:13px; color:#64748b;">orders</span></h3>
            </div>
            <div style="background:#fff; border:2px solid #8b5cf6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#8b5cf6; text-transform:uppercase;">🌙 Evening (18–24)</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $e_orders; ?> <span style="font-size:13px; color:#64748b;">orders</span></h3>
            </div>
        </div>

        <!-- Chart -->
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Hourly Order Distribution</h4>
            <canvas id="peak-hours-chart" height="90"></canvas>
        </div>

        <!-- Table -->
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="padding:12px;">Hour</th>
                        <th style="padding:12px;">Period</th>
                        <th style="padding:12px; text-align:center;">Orders</th>
                        <th style="padding:12px; text-align:right;">Revenue</th>
                        <th style="padding:12px; text-align:right;">Avg. Order</th>
                        <th style="padding:12px;">Activity</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $all_hours = array_keys($hours_map);
                sort($all_hours);
                foreach ( $all_hours as $hr ) :
                    $row   = $hours_map[$hr];
                    $pct   = $max_orders > 0 ? round((intval($row->total_orders) / $max_orders) * 100) : 0;
                    $is_pk = (intval($row->order_hour) === intval($peak_hour->order_hour));
                    $bar_color = $pct >= 80 ? '#ef4444' : ($pct >= 50 ? '#f59e0b' : '#10b981');
                    $period = in_array($hr, $morning) ? '🌅 Morning' : (in_array($hr, $afternoon) ? '☀️ Afternoon' : (in_array($hr, $evening) ? '🌙 Evening' : '🌙 Night'));
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9; <?php echo $is_pk ? 'background:#fffbeb;' : ''; ?>">
                        <td style="padding:12px; font-weight:600; color:#1e293b;">
                            <?php echo format_hour_label($hr); ?> – <?php echo format_hour_label($hr + 1); ?>
                            <?php if ($is_pk) echo ' <span style="background:#f59e0b; color:#fff; font-size:10px; padding:2px 6px; border-radius:4px; font-weight:700;">PEAK</span>'; ?>
                        </td>
                        <td style="padding:12px; font-size:12px; color:#64748b;"><?php echo $period; ?></td>
                        <td style="padding:12px; text-align:center; font-weight:700; font-size:16px; color:#1e293b;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-weight:600;"><?php echo $currency . number_format(floatval($row->total_revenue), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#64748b;"><?php echo $currency . number_format(floatval($row->avg_value), 2); ?></td>
                        <td style="padding:12px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; height:8px; background:#f1f5f9; border-radius:4px; overflow:hidden;">
                                    <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $bar_color; ?>; border-radius:4px;"></div>
                                </div>
                                <span style="font-size:11px; color:#94a3b8; min-width:30px;"><?php echo $pct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;" colspan="2">TOTAL</td>
                        <td style="padding:12px; text-align:center; color:#2271b1; font-size:15px;"><?php echo $g_orders; ?></td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($g_revenue, 2); ?></td>
                        <td style="padding:12px;"></td>
                        <td style="padding:12px;"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var allHours  = <?php echo json_encode($all_hours); ?>;
            var hoursMap  = <?php echo json_encode(array_map(function($h) {
                return array('orders' => intval($h->total_orders), 'revenue' => round(floatval($h->total_revenue), 2));
            }, $hours_map)); ?>;

            var labels  = allHours.map(function(h) {
                if (h === 0)  return '12 AM';
                if (h < 12)  return h + ' AM';
                if (h === 12) return '12 PM';
                return (h - 12) + ' PM';
            });

            var orders   = allHours.map(function(h) { return hoursMap[h] ? hoursMap[h].orders : 0; });
            var maxOrders = Math.max.apply(null, orders);
            var bgColors = orders.map(function(o) {
                var pct = maxOrders > 0 ? o / maxOrders : 0;
                if (pct >= 0.8) return '#ef4444';
                if (pct >= 0.5) return '#f59e0b';
                return '#10b981';
            });

            var ctx = document.getElementById('peak-hours-chart');
            if (!ctx) return;
            if (window._peakHoursChart) window._peakHoursChart.destroy();
            window._peakHoursChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Orders',
                        data: orders,
                        backgroundColor: bgColors,
                        borderRadius: 5,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } }
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
add_action('wp_ajax_fetch_order_report', 'handle_fetch_order_report');