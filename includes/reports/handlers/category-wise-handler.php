<?php
if ( ! defined( 'ABSPATH' ) ) exit;


function handle_fetch_category_wise_report() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }
    if ( function_exists( 'qrrs_free_report_ajax_guard' ) ) {
        qrrs_free_report_ajax_guard( 'Category Wise Report' );
    }

    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id      = intval($params['restaurant_id']);
    $date_range  = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';

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
    $menu_table   = $wpdb->prefix . 'qrrs_items';
    $cat_table    = $wpdb->prefix . 'qrrs_categories';

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT
            COALESCE(c.id, 0)                        AS category_id,
            COALESCE(c.category_name, 'Uncategorized') AS category_name,
            COUNT(DISTINCT oi.item_id)               AS total_items,
            SUM(oi.quantity)                          AS total_qty,
            SUM(oi.price * oi.quantity)               AS total_amount
         FROM {$items_table} oi
         INNER JOIN {$orders_table} o
             ON oi.order_id = o.id
             AND o.restaurant_id = %d
             AND DATE(o.created_at) BETWEEN %s AND %s
             AND o.order_status = 'completed'
         LEFT JOIN {$menu_table} mi ON oi.item_id = mi.id
         LEFT JOIN {$cat_table}  c  ON mi.category_id = c.id
         GROUP BY c.id, c.category_name
         ORDER BY total_amount DESC",
        $res_id, $start_date, $end_date
    ));

    if ( $wpdb->last_error ) {
        wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p>'));
        return;
    }

    if ( empty($results) ) {
        wp_send_json_success(array('data' => '<div style="text-align:center; padding:50px; color:#94a3b8;"><p>No completed orders found for this period.</p></div>'));
        return;
    }

    // Totals
    $total_qty    = 0;
    $total_amount = 0;
    $total_items  = 0;
    foreach ( $results as $r ) {
        $total_qty    += intval($r->total_qty);
        $total_amount += floatval($r->total_amount);
        $total_items  += intval($r->total_items);
    }

    $top_cat = $results[0];

    
    $colors = array('#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1');

    ob_start();
    ?>

    <!-- Top Category Card -->
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:15px; margin-bottom:25px;">
        <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
            <span style="font-size:10px; opacity:0.7; text-transform:uppercase;;float: left;">Top Category</span>
            <h3 style="margin:5px 0 0; font-size:18px;float: left;width: 100%;line-height: 2.2em;"><i style="color:#ea9a1d;" class="fas fa-trophy"></i> <?php echo esc_html($top_cat->category_name); ?></h3>
            <small style="opacity:0.7;"><?php echo $currency . number_format(floatval($top_cat->total_amount), 2); ?> revenue</small>
        </div>
        <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
            <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total Categories</span>
            <h3 style="margin:5px 0 0;"><?php echo count($results); ?></h3>
        </div>
        <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
            <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total Revenue</span>
            <h3 style="margin:5px 0 0; color:#2ecc71;"><?php echo $currency . number_format($total_amount, 2); ?></h3>
        </div>
    </div>

    <!-- Chart -->
    <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:20px; margin-bottom:20px;">
        <h4 style="margin:0 0 15px; color:#1e293b; font-size:14px;">Revenue by Category</h4>
        <canvas id="cat-wise-chart" height="80"></canvas>
    </div>

    <!-- Table -->
    <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <thead style="background:#f8fafc;">
                <tr>
                    <th style="padding:12px;">#</th>
                    <th style="padding:12px;">Category</th>
                    <th style="padding:12px; text-align:center;">Menu Items</th>
                    <th style="padding:12px; text-align:center;">Qty Sold</th>
                    <th style="padding:12px; text-align:right;">Total Revenue</th>
                    <th style="padding:12px; text-align:right;">% of Total</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $i = 1;
            foreach ( $results as $row ) :
                $pct   = $total_amount > 0 ? round((floatval($row->total_amount) / $total_amount) * 100, 1) : 0;
                $color = $colors[($i - 1) % count($colors)];
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; color:#94a3b8; font-size:12px;"><?php echo $i++; ?></td>
                    <td style="padding:12px;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span style="width:12px; height:12px; border-radius:50%; background:<?php echo $color; ?>; display:inline-block; flex-shrink:0;"></span>
                            <span style="font-weight:600; color:#1e293b;"><?php echo esc_html($row->category_name); ?></span>
                        </div>
                    </td>
                    <td style="padding:12px; text-align:center;"><?php echo intval($row->total_items); ?></td>
                    <td style="padding:12px; text-align:center; font-weight:600;"><?php echo intval($row->total_qty); ?></td>
                    <td style="padding:12px; text-align:right; font-weight:600; color:#2271b1;"><?php echo $currency . number_format(floatval($row->total_amount), 2); ?></td>
                    <td style="padding:12px; text-align:right;">
                        <div style="display:flex; align-items:center; justify-content:flex-end; gap:8px;">
                            <div style="width:80px; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $color; ?>; border-radius:3px;"></div>
                            </div>
                            <span style="font-size:12px; color:#475569; min-width:35px;"><?php echo $pct; ?>%</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                    <td style="padding:12px;" colspan="2">TOTAL</td>
                    <td style="padding:12px; text-align:center;"><?php echo $total_items; ?></td>
                    <td style="padding:12px; text-align:center;"><?php echo $total_qty; ?></td>
                    <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($total_amount, 2); ?></td>
                    <td style="padding:12px; text-align:right;">100%</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Chart JS -->
    <script>
    (function() {
        var labels  = <?php echo json_encode(array_map(function($r) { return $r->category_name; }, $results)); ?>;
        var amounts = <?php echo json_encode(array_map(function($r) { return floatval($r->total_amount); }, $results)); ?>;
        var colors  = <?php echo json_encode(array_slice($colors, 0, count($results))); ?>;

        var ctx = document.getElementById('cat-wise-chart');
        if (!ctx) return;

        if (window.catWiseChartInstance) {
            window.catWiseChartInstance.destroy();
        }

        window.catWiseChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Revenue',
                    data: amounts,
                    backgroundColor: colors,
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    })();
    </script>

    <?php
    $output = ob_get_clean();
    wp_send_json_success(array('data' => $output));
}
add_action('wp_ajax_fetch_category_wise_report', 'handle_fetch_category_wise_report');


