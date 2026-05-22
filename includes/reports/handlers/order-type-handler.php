<?php
if ( ! defined( 'ABSPATH' ) ) exit;



function handle_fetch_order_type_report() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }

    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id     = intval($params['restaurant_id']);
    $date_range = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';

    if ( empty($date_range) ) {
        wp_send_json_success(array('data' => '<p style="text-align:center; padding:20px;">Please select a date range.</p>'));
        return;
    }

    $dates      = explode(" to ", $date_range);
    $start_date = trim($dates[0]);
    $end_date   = isset($dates[1]) ? trim($dates[1]) : $start_date;

    $currency     = get_qrrs_restaurant_currency($res_id);
    $orders_table = $wpdb->prefix . 'qrrs_orders';

    // Daily breakdown with type split
    $daily = $wpdb->get_results($wpdb->prepare(
        "SELECT
            DATE(created_at) AS order_date,
            SUM(CASE WHEN LOWER(table_name) NOT IN ('take out','takeout','delivery') THEN grand_total ELSE 0 END) AS dinein_amount,
            SUM(CASE WHEN LOWER(table_name) IN ('take out','takeout') THEN grand_total ELSE 0 END)              AS takeaway_amount,
            SUM(CASE WHEN LOWER(table_name) = 'delivery' THEN grand_total ELSE 0 END)                          AS delivery_amount,
            COUNT(CASE WHEN LOWER(table_name) NOT IN ('take out','takeout','delivery') THEN 1 END)              AS dinein_orders,
            COUNT(CASE WHEN LOWER(table_name) IN ('take out','takeout') THEN 1 END)                            AS takeaway_orders,
            COUNT(CASE WHEN LOWER(table_name) = 'delivery' THEN 1 END)                                         AS delivery_orders,
            SUM(grand_total) AS day_total,
            COUNT(id)        AS day_orders
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
    $grand_total      = 0;
    $dinein_total     = 0;
    $takeaway_total   = 0;
    $delivery_total   = 0;
    $dinein_orders    = 0;
    $takeaway_orders  = 0;
    $delivery_orders  = 0;
    $total_orders     = 0;

    foreach ( $daily as $d ) {
        $grand_total     += floatval($d->day_total);
        $dinein_total    += floatval($d->dinein_amount);
        $takeaway_total  += floatval($d->takeaway_amount);
        $delivery_total  += floatval($d->delivery_amount);
        $dinein_orders   += intval($d->dinein_orders);
        $takeaway_orders += intval($d->takeaway_orders);
        $delivery_orders += intval($d->delivery_orders);
        $total_orders    += intval($d->day_orders);
    }

    $dinein_pct   = $grand_total > 0 ? round(($dinein_total   / $grand_total) * 100, 1) : 0;
    $takeaway_pct = $grand_total > 0 ? round(($takeaway_total / $grand_total) * 100, 1) : 0;
    $delivery_pct = $grand_total > 0 ? round(($delivery_total / $grand_total) * 100, 1) : 0;

    ob_start();
    ?>

    <!-- Summary Cards -->
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
        <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
            <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total Revenue</span>
            <h3 style="margin:5px 0 0; color:#2ecc71;"><?php echo $currency . number_format($grand_total, 2); ?></h3>
            <small style="opacity:0.6;"><?php echo $total_orders; ?> orders</small>
        </div>
        <div style="background:#fff; border:2px solid #10b981; padding:20px; border-radius:12px;">
            <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;float:left; width:100%; line-height:2.2em;"><span class="material-icons-outlined">ramen_dining</span> Dine-in</span>
            <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($dinein_total, 2); ?></h3>
            <small style="color:#64748b;"><?php echo $dinein_orders; ?> orders &bull; <?php echo $dinein_pct; ?>%</small>
            <div style="margin-top:8px; height:5px; background:#f1f5f9; border-radius:3px;">
                <div style="width:<?php echo $dinein_pct; ?>%; height:100%; background:#10b981; border-radius:3px;"></div>
            </div>
        </div>
        <div style="background:#fff; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
            <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;float:left; width:100%; line-height:2.2em;"><span class="material-icons-outlined">takeout_dining</span> Takeaway</span>
            <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($takeaway_total, 2); ?></h3>
            <small style="color:#64748b;"><?php echo $takeaway_orders; ?> orders &bull; <?php echo $takeaway_pct; ?>%</small>
            <div style="margin-top:8px; height:5px; background:#f1f5f9; border-radius:3px;">
                <div style="width:<?php echo $takeaway_pct; ?>%; height:100%; background:#f59e0b; border-radius:3px;"></div>
            </div>
        </div>
        <div style="background:#fff; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
            <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;float:left; width:100%; line-height:2.2em;"><span class="material-icons-outlined">delivery_dining</span> Delivery</span>
            <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($delivery_total, 2); ?></h3>
            <small style="color:#64748b;"><?php echo $delivery_orders; ?> orders &bull; <?php echo $delivery_pct; ?>%</small>
            <div style="margin-top:8px; height:5px; background:#f1f5f9; border-radius:3px;">
                <div style="width:<?php echo $delivery_pct; ?>%; height:100%; background:#3b82f6; border-radius:3px;"></div>
            </div>
        </div>
    </div>

    <!-- Chart -->
    <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:20px;">
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Daily Revenue Breakdown</h4>
            <canvas id="order-type-bar-chart" height="100"></canvas>
        </div>
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Revenue Share</h4>
            <canvas id="order-type-pie-chart" height="100"></canvas>
        </div>
    </div>

    <!-- Daily Breakdown Table -->
    <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <thead style="background:#f8fafc;">
                <tr>
                    <th style="padding:12px;">Date</th>
                    <th style="padding:12px; text-align:right; color:#10b981;">🍽️ Dine-in</th>
                    <th style="padding:12px; text-align:right; color:#f59e0b;">🥡 Takeaway</th>
                    <th style="padding:12px; text-align:right; color:#3b82f6;">🛵 Delivery</th>
                    <th style="padding:12px; text-align:center;">Orders</th>
                    <th style="padding:12px; text-align:right;">Day Total</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $daily as $row ) : ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:500;"><?php echo date('M d, Y', strtotime($row->order_date)); ?></td>
                    <td style="padding:12px; text-align:right; color:#10b981;"><?php echo $currency . number_format(floatval($row->dinein_amount), 2); ?><br><small style="color:#94a3b8;"><?php echo $row->dinein_orders; ?> orders</small></td>
                    <td style="padding:12px; text-align:right; color:#f59e0b;"><?php echo $currency . number_format(floatval($row->takeaway_amount), 2); ?><br><small style="color:#94a3b8;"><?php echo $row->takeaway_orders; ?> orders</small></td>
                    <td style="padding:12px; text-align:right; color:#3b82f6;"><?php echo $currency . number_format(floatval($row->delivery_amount), 2); ?><br><small style="color:#94a3b8;"><?php echo $row->delivery_orders; ?> orders</small></td>
                    <td style="padding:12px; text-align:center; font-weight:600;"><?php echo $row->day_orders; ?></td>
                    <td style="padding:12px; text-align:right; font-weight:700; color:#1e293b;"><?php echo $currency . number_format(floatval($row->day_total), 2); ?></td>
                </tr>
            <?php endforeach; ?>
                <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                    <td style="padding:12px;">TOTAL</td>
                    <td style="padding:12px; text-align:right; color:#10b981;"><?php echo $currency . number_format($dinein_total, 2); ?></td>
                    <td style="padding:12px; text-align:right; color:#f59e0b;"><?php echo $currency . number_format($takeaway_total, 2); ?></td>
                    <td style="padding:12px; text-align:right; color:#3b82f6;"><?php echo $currency . number_format($delivery_total, 2); ?></td>
                    <td style="padding:12px; text-align:center;"><?php echo $total_orders; ?></td>
                    <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($grand_total, 2); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <script>
    (function() {
        var labels   = <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d->order_date)); }, $daily)); ?>;
        var dinein   = <?php echo json_encode(array_map(function($d) { return floatval($d->dinein_amount); }, $daily)); ?>;
        var takeaway = <?php echo json_encode(array_map(function($d) { return floatval($d->takeaway_amount); }, $daily)); ?>;
        var delivery = <?php echo json_encode(array_map(function($d) { return floatval($d->delivery_amount); }, $daily)); ?>;

        // Bar Chart
        var barCtx = document.getElementById('order-type-bar-chart');
        if (barCtx) {
            if (window.orderTypeBarChart) window.orderTypeBarChart.destroy();
            window.orderTypeBarChart = new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Dine-in',   data: dinein,   backgroundColor: '#10b981', borderRadius: 4 },
                        { label: 'Takeaway',  data: takeaway, backgroundColor: '#f59e0b', borderRadius: 4 },
                        { label: 'Delivery',  data: delivery, backgroundColor: '#3b82f6', borderRadius: 4 }
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
        }

        // Pie Chart
        var pieCtx = document.getElementById('order-type-pie-chart');
        if (pieCtx) {
            if (window.orderTypePieChart) window.orderTypePieChart.destroy();
            window.orderTypePieChart = new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Dine-in', 'Takeaway', 'Delivery'],
                    datasets: [{
                        data: [<?php echo $dinein_total; ?>, <?php echo $takeaway_total; ?>, <?php echo $delivery_total; ?>],
                        backgroundColor: ['#10b981', '#f59e0b', '#3b82f6'],
                        borderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
    })();
    </script>

    <?php
    $output = ob_get_clean();
    wp_send_json_success(array('data' => $output));
}
add_action('wp_ajax_fetch_order_type_report', 'handle_fetch_order_type_report');
