<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function handle_fetch_staff_report() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }

    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id      = intval($params['restaurant_id']);
    $date_range  = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';
    $report_type = isset($params['staff_report_type']) ? sanitize_text_field($params['staff_report_type']) : 'sales_performance';

    if ( empty($date_range) ) {
        wp_send_json_success(array('data' => '<p style="text-align:center; padding:20px;">Please select a date range.</p>'));
        return;
    }

    $dates      = explode(" to ", $date_range);
    $start_date = trim($dates[0]);
    $end_date   = isset($dates[1]) ? trim($dates[1]) : $start_date;

    $currency     = get_qrrs_restaurant_currency($res_id);
    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $staff_table  = $wpdb->prefix . 'qrrs_staff';
    $users_table  = $wpdb->prefix . 'users';

<<<<<<< HEAD
    // ===================== SALES PERFORMANCE =====================
    if ( $report_type === 'sales_performance' ) {

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                o.waiter_id,
                COALESCE(u.display_name, CONCAT('User #', o.waiter_id)) AS staff_name,
                COALESCE(s.staff_role, 'Staff') AS staff_role,
                COUNT(o.id) AS total_orders,
                SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                SUM(CASE WHEN o.order_status = 'completed' THEN COALESCE(o.final_total, o.grand_total) ELSE 0 END) AS total_sales,
                AVG(CASE WHEN o.order_status = 'completed' THEN COALESCE(o.final_total, o.grand_total) END) AS avg_order_value
             FROM {$orders_table} o
             LEFT JOIN {$users_table} u ON u.ID = o.waiter_id
             LEFT JOIN {$staff_table} s ON s.user_id = o.waiter_id AND s.restaurant_id = %d
             WHERE o.restaurant_id = %d
               AND DATE(o.created_at) BETWEEN %s AND %s
               AND o.waiter_id > 0
             GROUP BY o.waiter_id, u.display_name, s.staff_role
             ORDER BY total_sales DESC",
            $res_id, $res_id, $start_date, $end_date
        ));

        if ( $wpdb->last_error ) {
            wp_send_json_success(array('data' => '<p style="color:red; padding:20px;">SQL Error: ' . esc_html($wpdb->last_error) . '</p>'));
            return;
        }

        if ( empty($results) ) {
            wp_send_json_success(array('data' => '<p style="text-align:center; padding:30px; color:#64748b;">No sales data found for the selected date range.</p>'));
            return;
        }

=======
    
    // ===================== SALES PERFORMANCE =====================
    if ( $report_type === 'sales_performance' ) {

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT
            o.waiter_id,
            COALESCE(u.display_name, CONCAT('User #', o.waiter_id)) AS staff_name,
            'staff' AS staff_role,
            COUNT(o.id) AS total_orders,
            SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
            SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
            SUM(CASE WHEN o.order_status = 'completed' THEN COALESCE(o.final_total, o.grand_total) ELSE 0 END) AS total_sales,
            AVG(CASE WHEN o.order_status = 'completed' THEN COALESCE(o.final_total, o.grand_total) END) AS avg_order_value,
            MAX(CASE WHEN o.order_status = 'completed' THEN COALESCE(o.final_total, o.grand_total) END) AS max_order_value,
            SUM(CASE WHEN LOWER(o.table_name) NOT IN ('take out','takeout','delivery') THEN 1 ELSE 0 END) AS dinein_orders,
            SUM(CASE WHEN LOWER(o.table_name) IN ('take out','takeout') THEN 1 ELSE 0 END) AS takeaway_orders,
            SUM(CASE WHEN LOWER(o.table_name) = 'delivery' THEN 1 ELSE 0 END) AS delivery_orders
         FROM {$orders_table} o
         LEFT JOIN {$users_table} u ON u.ID = o.waiter_id
         WHERE o.restaurant_id = %d
           AND DATE(o.created_at) BETWEEN %s AND %s
           AND o.waiter_id > 0
         GROUP BY o.waiter_id, u.display_name
         ORDER BY total_sales DESC",
        $res_id, $start_date, $end_date
    ));

    if ( $wpdb->last_error ) {
        wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
        return;
    }

    if ( empty($results) ) {
        wp_send_json_success(array('data' => '<p style="color:orange;">Query ran but empty results.</p>'));
        return;
    }

        // Grand totals
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        $g_sales = $g_orders = $g_completed = $g_cancelled = 0;
        foreach ( $results as $r ) {
            $g_sales     += floatval($r->total_sales);
            $g_orders    += intval($r->total_orders);
            $g_completed += intval($r->completed_orders);
            $g_cancelled += intval($r->cancelled_orders);
        }

        $top_staff = $results[0];
<<<<<<< HEAD
=======

        // Colors for chart
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        $colors = array('#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#f97316');

        ob_start();
        ?>

<<<<<<< HEAD
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase; float:left; width:100%; line-height:2.2em;"><i style="color:#ea9a1d;" class="fas fa-trophy"></i> Top Performer</span>
                <h3 style="margin:5px 0 0; font-size:17px; color:#2ecc71; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo esc_html($top_staff->staff_name); ?></h3>
                <small style="opacity:0.7;"><?php echo $currency . number_format(floatval($top_staff->total_sales), 2); ?> sales</small>
            </div>
            <div style="background:#fff; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;float:left;width:100%;"><i class="fas fa-users"></i> Total Staff</span>
=======
        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">🏆 Top Performer</span>
                <h3 style="margin:5px 0 0; font-size:17px; color:#2ecc71;"><?php echo esc_html($top_staff->staff_name); ?></h3>
                <small style="opacity:0.7;"><?php echo $currency . number_format(floatval($top_staff->total_sales), 2); ?> sales</small>
            </div>
            <div style="background:#fff; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;">👥 Total Staff</span>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo count($results); ?></h3>
                <small style="color:#64748b;">active this period</small>
            </div>
            <div style="background:#fff; border:2px solid #10b981; padding:20px; border-radius:12px;">
<<<<<<< HEAD
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;float:left;width:100%;"><i class="fas fa-hand-holding-usd"></i> Total Sales</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_sales, 2); ?></h3>
                <small style="color:#64748b;"><?php echo $g_completed; ?> completed</small>
            </div>
            <div style="background:#fff; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;float:left;width:100%;"><i class="fas fa-receipt"></i> Total Orders</span>
=======
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;">💰 Total Sales</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $currency . number_format($g_sales, 2); ?></h3>
                <small style="color:#64748b;"><?php echo $g_completed; ?> completed orders</small>
            </div>
            <div style="background:#fff; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;">📋 Total Orders</span>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_orders; ?></h3>
                <small style="color:#64748b;"><?php echo $g_cancelled; ?> cancelled</small>
            </div>
        </div>

<<<<<<< HEAD
=======
        <!-- Chart -->
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Sales by Staff Member</h4>
            <canvas id="staff-sales-chart" height="80"></canvas>
        </div>

<<<<<<< HEAD
=======
        <!-- Table -->
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
<<<<<<< HEAD
                        <th style="padding:12px; text-align:center;">#</th>
=======
                        <th style="padding:12px;">#</th>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                        <th style="padding:12px;">Staff Name</th>
                        <th style="padding:12px;">Role</th>
                        <th style="padding:12px; text-align:center;">Total Orders</th>
                        <th style="padding:12px; text-align:center; color:#10b981;">Completed</th>
                        <th style="padding:12px; text-align:center; color:#ef4444;">Cancelled</th>
                        <th style="padding:12px; text-align:right;">Total Sales</th>
                        <th style="padding:12px; text-align:right;">Avg. Order</th>
                        <th style="padding:12px; text-align:right;">% Share</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $i = 1;
                foreach ( $results as $row ) :
                    $pct   = $g_sales > 0 ? round((floatval($row->total_sales) / $g_sales) * 100, 1) : 0;
                    $color = $colors[($i - 1) % count($colors)];
                    $medal = $i === 1 ? '🥇' : ($i === 2 ? '🥈' : ($i === 3 ? '🥉' : $i));
<<<<<<< HEAD
=======
                    $role_label = ucfirst($row->staff_role);
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; font-weight:700; font-size:16px; text-align:center;"><?php echo $medal; ?></td>
                        <td style="padding:12px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div style="width:36px; height:36px; border-radius:50%; background:<?php echo $color; ?>22; color:<?php echo $color; ?>; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; flex-shrink:0;">
                                    <?php echo strtoupper(substr($row->staff_name, 0, 1)); ?>
                                </div>
                                <span style="font-weight:600; color:#1e293b;"><?php echo esc_html($row->staff_name); ?></span>
                            </div>
                        </td>
                        <td style="padding:12px;">
<<<<<<< HEAD
                            <span style="background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600; text-transform:capitalize;">
                                <?php echo esc_html($row->staff_role); ?>
=======
                            <span style="background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                                <?php echo esc_html($role_label); ?>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                            </span>
                        </td>
                        <td style="padding:12px; text-align:center; font-weight:700;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981; font-weight:600;"><?php echo $row->completed_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $row->cancelled_orders; ?></td>
                        <td style="padding:12px; text-align:right; font-weight:700; color:#2271b1;"><?php echo $currency . number_format(floatval($row->total_sales), 2); ?></td>
                        <td style="padding:12px; text-align:right; color:#64748b;"><?php echo $row->avg_order_value ? $currency . number_format(floatval($row->avg_order_value), 2) : '—'; ?></td>
                        <td style="padding:12px; text-align:right;">
                            <div style="display:flex; align-items:center; justify-content:flex-end; gap:8px;">
                                <div style="width:60px; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                    <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $color; ?>; border-radius:3px;"></div>
                                </div>
                                <span style="font-size:12px; color:#475569; min-width:38px;"><?php echo $pct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php $i++; endforeach; ?>
<<<<<<< HEAD
=======
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;" colspan="3">TOTAL</td>
                        <td style="padding:12px; text-align:center;"><?php echo $g_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981;"><?php echo $g_completed; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $g_cancelled; ?></td>
                        <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($g_sales, 2); ?></td>
                        <td style="padding:12px;"></td>
                        <td style="padding:12px; text-align:right;">100%</td>
                    </tr>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                </tbody>
            </table>
        </div>

        <script>
        (function() {
<<<<<<< HEAD
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js missing. Canvas hidden.');
                var canvas = document.getElementById('staff-sales-chart');
                if(canvas) canvas.parentElement.style.display = 'none';
                return;
            }
=======
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
            var labels = <?php echo json_encode(array_map(function($r) { return $r->staff_name; }, $results)); ?>;
            var sales  = <?php echo json_encode(array_map(function($r) { return round(floatval($r->total_sales), 2); }, $results)); ?>;
            var colors = <?php echo json_encode(array_slice($colors, 0, count($results))); ?>;

            var ctx = document.getElementById('staff-sales-chart');
            if (!ctx) return;
            if (window._staffSalesChart) window._staffSalesChart.destroy();
            window._staffSalesChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Total Sales',
                        data: sales,
                        backgroundColor: colors,
<<<<<<< HEAD
                        borderRadius: 6
=======
                        borderRadius: 6,
                        borderSkipped: false
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                    }]
                },
                options: {
                    responsive: true,
<<<<<<< HEAD
                    plugins: { legend: { display: false } }
=======
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } }
                    }
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                }
            });
        })();
        </script>

        <?php
        $output = ob_get_clean();
        wp_send_json_success(array('data' => $output));
        return;
    }

    // ===================== ORDER HANDLING =====================
    if ( $report_type === 'order_handling' ) {

<<<<<<< HEAD
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                o.waiter_id,
                COALESCE(u.display_name, CONCAT('User #', o.waiter_id)) AS staff_name,
                COALESCE(s.staff_role, 'Staff') AS staff_role,
                COUNT(o.id) AS total_orders,
                SUM(CASE WHEN LOWER(o.table_name) NOT IN ('take out','takeout','delivery') THEN 1 ELSE 0 END) AS dinein_orders,
                SUM(CASE WHEN LOWER(o.table_name) IN ('take out','takeout') THEN 1 ELSE 0 END) AS takeaway_orders,
                SUM(CASE WHEN LOWER(o.table_name) = 'delivery' THEN 1 ELSE 0 END) AS delivery_orders,
                SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
             FROM {$orders_table} o
             LEFT JOIN {$users_table} u ON u.ID = o.waiter_id
             LEFT JOIN {$staff_table} s ON s.user_id = o.waiter_id AND s.restaurant_id = %d
             WHERE o.restaurant_id = %d
               AND DATE(o.created_at) BETWEEN %s AND %s
               AND o.waiter_id > 0
             GROUP BY o.waiter_id, u.display_name, s.staff_role
             ORDER BY total_orders DESC",
            $res_id, $res_id, $start_date, $end_date
        ));

        if ( $wpdb->last_error ) {
            wp_send_json_success(array('data' => '<p style="color:red; padding:20px;">SQL Error: ' . esc_html($wpdb->last_error) . '</p>'));
            return;
        }

        if ( empty($results) ) {
            wp_send_json_success(array('data' => '<p style="text-align:center; padding:30px; color:#64748b;">No order data found for the selected date range.</p>'));
            return;
        }
=======
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT
            o.waiter_id,
            COALESCE(u.display_name, CONCAT('User #', o.waiter_id)) AS staff_name,
            'staff' AS staff_role,
            COUNT(o.id) AS total_orders,
            SUM(CASE WHEN LOWER(o.table_name) NOT IN ('take out','takeout','delivery') THEN 1 ELSE 0 END) AS dinein_orders,
            SUM(CASE WHEN LOWER(o.table_name) IN ('take out','takeout') THEN 1 ELSE 0 END) AS takeaway_orders,
            SUM(CASE WHEN LOWER(o.table_name) = 'delivery' THEN 1 ELSE 0 END) AS delivery_orders,
            SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN o.order_status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) AS active
         FROM {$orders_table} o
         LEFT JOIN {$users_table} u ON u.ID = o.waiter_id
         WHERE o.restaurant_id = %d
           AND DATE(o.created_at) BETWEEN %s AND %s
           AND o.waiter_id > 0
         GROUP BY o.waiter_id, u.display_name
         ORDER BY total_orders DESC",
        $res_id, $start_date, $end_date
    ));

    if ( $wpdb->last_error ) {
        wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
        return;
    }

    if ( empty($results) ) {
        wp_send_json_success(array('data' => '<p style="color:orange;">Query ran but empty results.</p>'));
        return;
    }
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da

        $g_total = $g_dinein = $g_takeaway = $g_delivery = $g_completed = $g_cancelled = 0;
        foreach ( $results as $r ) {
            $g_total     += intval($r->total_orders);
            $g_dinein    += intval($r->dinein_orders);
            $g_takeaway  += intval($r->takeaway_orders);
            $g_delivery  += intval($r->delivery_orders);
            $g_completed += intval($r->completed);
            $g_cancelled += intval($r->cancelled);
        }

<<<<<<< HEAD
        ob_start();
        ?>

=======
        $colors = array('#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#f97316');

        ob_start();
        ?>

        <!-- Summary Cards -->
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total Orders Handled</span>
                <h3 style="margin:5px 0 0; font-size:26px;"><?php echo $g_total; ?></h3>
<<<<<<< HEAD
            </div>
            <div style="background:#d1fae5; border:2px solid #10b981; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;float:left; width:100%; line-height:2.2em;"><span class="material-icons-outlined">dinner_dining</span> Dine-in</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_dinein; ?></h3>
            </div>
            <div style="background:#fef3c7; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;float:left; width:100%; line-height:2.2em;"><span class="material-icons-outlined">takeout_dining</span> Takeaway</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_takeaway; ?></h3>
            </div>
            <div style="background:#dbeafe; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;float:left; width:100%; line-height:2.2em;"><span class="material-icons-outlined">delivery_dining</span> Delivery</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_delivery; ?></h3>
            </div>
        </div>

=======
                <small style="opacity:0.6;">by <?php echo count($results); ?> staff members</small>
            </div>
            <div style="background:#d1fae5; border:2px solid #10b981; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase;">🍽️ Dine-in</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_dinein; ?></h3>
                <small style="color:#065f46;"><?php echo $g_total > 0 ? round(($g_dinein/$g_total)*100,1) : 0; ?>% of total</small>
            </div>
            <div style="background:#fef3c7; border:2px solid #f59e0b; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase;">🥡 Takeaway</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_takeaway; ?></h3>
                <small style="color:#92400e;"><?php echo $g_total > 0 ? round(($g_takeaway/$g_total)*100,1) : 0; ?>% of total</small>
            </div>
            <div style="background:#dbeafe; border:2px solid #3b82f6; padding:20px; border-radius:12px;">
                <span style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase;">🛵 Delivery</span>
                <h3 style="margin:5px 0 0; color:#1e293b;"><?php echo $g_delivery; ?></h3>
                <small style="color:#1e40af;"><?php echo $g_total > 0 ? round(($g_delivery/$g_total)*100,1) : 0; ?>% of total</small>
            </div>
        </div>

        <!-- Chart -->
        <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:20px;">
            <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px;">
                <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Orders Handled per Staff</h4>
                <canvas id="staff-orders-chart" height="100"></canvas>
            </div>
            <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px;">
                <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Order Type Share</h4>
                <canvas id="staff-type-pie" height="100"></canvas>
            </div>
        </div>

        <!-- Table -->
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:#f8fafc;">
                    <tr>
<<<<<<< HEAD
                        <th style="padding:12px; text-align:center;">#</th>
=======
                        <th style="padding:12px;">#</th>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                        <th style="padding:12px;">Staff Name</th>
                        <th style="padding:12px;">Role</th>
                        <th style="padding:12px; text-align:center;">Total</th>
                        <th style="padding:12px; text-align:center; color:#10b981;">Dine-in</th>
                        <th style="padding:12px; text-align:center; color:#f59e0b;">Takeaway</th>
                        <th style="padding:12px; text-align:center; color:#3b82f6;">Delivery</th>
                        <th style="padding:12px; text-align:center; color:#10b981;">Completed</th>
                        <th style="padding:12px; text-align:center; color:#ef4444;">Cancelled</th>
<<<<<<< HEAD
=======
                        <th style="padding:12px; text-align:center;">Success Rate</th>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                    </tr>
                </thead>
                <tbody>
                <?php
                $i = 1;
                foreach ( $results as $row ) :
<<<<<<< HEAD
                    $medal = $i === 1 ? '🥇' : ($i === 2 ? '🥈' : ($i === 3 ? '🥉' : $i));
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; text-align:center; font-weight:700;"><?php echo $medal; ?></td>
                        <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html($row->staff_name); ?></td>
                        <td style="padding:12px; text-transform:capitalize;"><?php echo esc_html($row->staff_role); ?></td>
                        <td style="padding:12px; text-align:center; font-weight:700;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981;"><?php echo $row->dinein_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#f59e0b;"><?php echo $row->takeaway_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#3b82f6;"><?php echo $row->delivery_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981;"><?php echo $row->completed; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $row->cancelled; ?></td>
                    </tr>
                <?php $i++; endforeach; ?>
                </tbody>
            </table>
        </div>
=======
                    $color       = $colors[($i - 1) % count($colors)];
                    $medal       = $i === 1 ? '🥇' : ($i === 2 ? '🥈' : ($i === 3 ? '🥉' : $i));
                    $success_pct = intval($row->total_orders) > 0
                        ? round((intval($row->completed) / intval($row->total_orders)) * 100, 1)
                        : 0;
                    $bar_color   = $success_pct >= 80 ? '#10b981' : ($success_pct >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px; font-weight:700; font-size:16px; text-align:center;"><?php echo $medal; ?></td>
                        <td style="padding:12px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div style="width:36px; height:36px; border-radius:50%; background:<?php echo $color; ?>22; color:<?php echo $color; ?>; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; flex-shrink:0;">
                                    <?php echo strtoupper(substr($row->staff_name, 0, 1)); ?>
                                </div>
                                <span style="font-weight:600; color:#1e293b;"><?php echo esc_html($row->staff_name); ?></span>
                            </div>
                        </td>
                        <td style="padding:12px;">
                            <span style="background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                                <?php echo esc_html(ucfirst($row->staff_role)); ?>
                            </span>
                        </td>
                        <td style="padding:12px; text-align:center; font-weight:700; font-size:16px;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981; font-weight:600;"><?php echo $row->dinein_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#f59e0b; font-weight:600;"><?php echo $row->takeaway_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#3b82f6; font-weight:600;"><?php echo $row->delivery_orders; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981;"><?php echo $row->completed; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $row->cancelled; ?></td>
                        <td style="padding:12px; text-align:center;">
                            <div style="display:flex; align-items:center; gap:6px; justify-content:center;">
                                <div style="width:55px; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                    <div style="width:<?php echo $success_pct; ?>%; height:100%; background:<?php echo $bar_color; ?>; border-radius:3px;"></div>
                                </div>
                                <span style="font-size:12px; color:<?php echo $bar_color; ?>; font-weight:700;"><?php echo $success_pct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php $i++; endforeach; ?>
                    <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                        <td style="padding:12px;" colspan="3">TOTAL</td>
                        <td style="padding:12px; text-align:center; color:#2271b1; font-size:15px;"><?php echo $g_total; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981;"><?php echo $g_dinein; ?></td>
                        <td style="padding:12px; text-align:center; color:#f59e0b;"><?php echo $g_takeaway; ?></td>
                        <td style="padding:12px; text-align:center; color:#3b82f6;"><?php echo $g_delivery; ?></td>
                        <td style="padding:12px; text-align:center; color:#10b981;"><?php echo $g_completed; ?></td>
                        <td style="padding:12px; text-align:center; color:#ef4444;"><?php echo $g_cancelled; ?></td>
                        <td style="padding:12px;"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var labels   = <?php echo json_encode(array_map(function($r) { return $r->staff_name; }, $results)); ?>;
            var dinein   = <?php echo json_encode(array_map(function($r) { return intval($r->dinein_orders); }, $results)); ?>;
            var takeaway = <?php echo json_encode(array_map(function($r) { return intval($r->takeaway_orders); }, $results)); ?>;
            var delivery = <?php echo json_encode(array_map(function($r) { return intval($r->delivery_orders); }, $results)); ?>;

            // Stacked bar
            var barCtx = document.getElementById('staff-orders-chart');
            if (barCtx) {
                if (window._staffOrdersChart) window._staffOrdersChart.destroy();
                window._staffOrdersChart = new Chart(barCtx, {
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
                            y: { stacked: true, beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } }
                        }
                    }
                });
            }

            // Pie
            var pieCtx = document.getElementById('staff-type-pie');
            if (pieCtx) {
                if (window._staffTypePie) window._staffTypePie.destroy();
                window._staffTypePie = new Chart(pieCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Dine-in', 'Takeaway', 'Delivery'],
                        datasets: [{
                            data: [<?php echo $g_dinein; ?>, <?php echo $g_takeaway; ?>, <?php echo $g_delivery; ?>],
                            backgroundColor: ['#10b981', '#f59e0b', '#3b82f6'],
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
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

>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
        <?php
        $output = ob_get_clean();
        wp_send_json_success(array('data' => $output));
        return;
    }

    wp_send_json_error('Invalid report type.');
}
<<<<<<< HEAD
add_action('wp_ajax_fetch_staff_report', 'handle_fetch_staff_report');
add_action('wp_ajax_nopriv_fetch_staff_report', 'handle_fetch_staff_report');
=======
add_action('wp_ajax_fetch_staff_report', 'handle_fetch_staff_report');
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
