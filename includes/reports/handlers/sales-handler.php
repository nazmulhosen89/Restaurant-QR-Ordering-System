<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function handle_fetch_sales_report_data() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }
    
    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id      = intval($params['restaurant_id']);
    $date_range  = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';
    
    if ( empty($date_range) ) {
        wp_send_json_success(['data' => ['data' => '<p style="text-align:center; padding:20px;">Please select a date range.</p>']]);
    }

    $dates = explode(" to ", $date_range);
    $start_date = trim($dates[0]);
    $end_date   = isset($dates[1]) ? trim($dates[1]) : $start_date;
    $range_was_limited = false;

    if ( function_exists( 'qrrs_is_pro' ) && ! qrrs_is_pro() ) {
        $timezone = wp_timezone();
        $today = new DateTime( 'today', $timezone );
        $oldest_allowed = ( clone $today )->modify( '-29 days' );

        $requested_start = DateTime::createFromFormat( 'Y-m-d', $start_date, $timezone );
        $requested_end = DateTime::createFromFormat( 'Y-m-d', $end_date, $timezone );

        if ( $requested_start && $requested_start < $oldest_allowed ) {
            $start_date = $oldest_allowed->format( 'Y-m-d' );
            $range_was_limited = true;
        }

        if ( $requested_end && $requested_end > $today ) {
            $end_date = $today->format( 'Y-m-d' );
            $range_was_limited = true;
        }
    }

    $is_multi_day = ($start_date !== $end_date);

    $currency = get_qrrs_restaurant_currency($res_id);
    $order_table = $wpdb->prefix . 'qrrs_orders';

    // DATABASE FIELD MATCHING (Screenshot image_fcc29d.jpg onujayi)
    if ( ! $is_multi_day ) {
        $query = $wpdb->prepare(
            "SELECT * FROM {$order_table} WHERE restaurant_id = %d AND DATE(created_at) = %s AND order_status = 'completed' ORDER BY created_at DESC",
            $res_id, $start_date
        );
    } else {
        $query = $wpdb->prepare(
            "SELECT DATE(created_at) as report_date, COUNT(id) as total_orders,
            SUM(CASE WHEN table_name != 'Take Out' AND table_name != 'Delivery' THEN final_total ELSE 0 END) as dinein_rev,
            SUM(CASE WHEN table_name = 'Take Out' THEN final_total ELSE 0 END) as takeaway_rev,
            SUM(CASE WHEN table_name = 'Delivery' THEN final_total ELSE 0 END) as delivery_rev,
            SUM(discount_amount) as total_discount,
            SUM(final_total) as total_revenue
            FROM {$order_table}
            WHERE restaurant_id = %d AND DATE(created_at) BETWEEN %s AND %s AND order_status = 'completed'
            GROUP BY DATE(created_at) ORDER BY report_date ASC",
            $res_id, $start_date, $end_date
        );
    }

    $results = $wpdb->get_results($query);

    ob_start();
    if ( $range_was_limited ) : ?>
        <div style="background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:12px 14px; border-radius:8px; margin-bottom:15px;">
            Free version shows sales data for the last 30 days only. Upgrade to Pro for full historical reports.
        </div>
    <?php endif;

    if ( ! empty($results) ) :
    ?>

    <?php if ( $is_multi_day ) :
        $total_rev = array_sum(array_column($results, 'total_revenue'));
        $total_ord = array_sum(array_column($results, 'total_orders'));
        $days      = count($results);
    ?>
        <div class="report-summary-cards" style="display:grid; grid-template-columns:repeat(4,1fr); gap:15px; margin-bottom:25px;">
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total Revenue</span>
                <h3 style="margin:5px 0 0;"><?php echo $currency . number_format($total_rev, 2); ?></h3>
            </div>
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Total Orders</span>
                <h3 style="margin:5px 0 0;"><?php echo $total_ord; ?></h3>
            </div>
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Avg Per Day</span>
                <h3 style="margin:5px 0 0;"><?php echo $currency . number_format($days > 0 ? $total_rev / $days : 0, 2); ?></h3>
            </div>
            <div style="background:#1e293b; color:#fff; padding:20px; border-radius:12px;">
                <span style="font-size:10px; opacity:0.7; text-transform:uppercase;">Days</span>
                <h3 style="margin:5px 0 0; color:#2ecc71;"><?php echo $days; ?></h3>
            </div>
        </div>
    <?php endif; ?>

    <div class="report-table-wrapper" style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden;">
        <table class="qrrs-report-table" style="width:100%; border-collapse:collapse; text-align:left;">
            <thead style="background:#f8fafc;">
                <tr>
                    <?php if ( ! $is_multi_day ) : ?>
                        <th style="padding:12px;">Invoice</th>
                        <th style="padding:12px;">Type</th>
                        <th style="padding:12px;">Amount</th>
                        <th style="padding:12px;">VAT</th>
                        <th style="padding:12px;">S.Charge</th>
                        <th style="padding:12px;">Discount</th>
                        <th style="padding:12px;">Final</th>
                        <th style="padding:12px;">Payment</th>
                    <?php else : ?>
                        <th style="padding:12px;">Date</th>
                        <th style="padding:12px;">Orders</th>
                        <th style="padding:12px;">Dine-in</th>
                        <th style="padding:12px;">Takeaway</th>
                        <th style="padding:12px;">Delivery</th>
                        <th style="padding:12px;">Discount</th>
                        <th style="padding:12px;">Revenue</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>

            <?php if ( ! $is_multi_day ) :
                // Single day totals
                $s_amount   = 0;
                $s_tax      = 0;
                $s_service  = 0;
                $s_discount = 0;
                $s_final    = 0;

                foreach ( $results as $row ) :
                    $s_amount   += floatval($row->total_amount);
                    $s_tax      += floatval($row->tax_amount);
                    $s_service  += floatval($row->service_charge);
                    $s_discount += floatval($row->discount_amount ?? 0);
                    $s_final    += floatval($row->grand_total);

                    // Type label fix
                    $table_val = strtolower(trim($row->table_name));
                    if ( $table_val === 'take out' || $table_val === 'takeout' ) {
                        $type_label = 'Takeaway';
                        $type_bg    = '#fef3c7';
                        $type_color = '#92400e';
                    } elseif ( $table_val === 'delivery' ) {
                        $type_label = 'Delivery';
                        $type_bg    = '#dbeafe';
                        $type_color = '#1e40af';
                    } else {
                        $type_label = 'Dine In';
                        $type_bg    = '#d1fae5';
                        $type_color = '#065f46';
                    }
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px;">#<?php echo $row->id; ?></td>
                        <td style="padding:12px;">
                            <span style="background:<?php echo $type_bg; ?>; color:<?php echo $type_color; ?>; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                                <?php echo $type_label; ?>
                            </span>
                        </td>
                        <td style="padding:12px;"><?php echo number_format($row->total_amount, 2); ?></td>
                        <td style="padding:12px;"><?php echo number_format($row->tax_amount, 2); ?></td>
                        <td style="padding:12px;"><?php echo number_format($row->service_charge, 2); ?></td>
                        <td style="padding:12px;"><?php echo number_format($row->discount_amount ?? 0, 2); ?></td>
                        <td style="padding:12px; font-weight:bold;"><?php echo number_format($row->final_total, 2); ?></td>
                        <td style="padding:12px; font-size:11px;"><?php echo strtoupper($row->payment_method ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>

                <!-- Single Day Total Row -->
                <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700; color:#1e293b;">
                    <td style="padding:12px;" colspan="2">TOTAL</td>
                    <td style="padding:12px;"><?php echo $currency . number_format($s_amount, 2); ?></td>
                    <td style="padding:12px;"><?php echo $currency . number_format($s_tax, 2); ?></td>
                    <td style="padding:12px;"><?php echo $currency . number_format($s_service, 2); ?></td>
                    <td style="padding:12px; color:#e74c3c;"><?php echo $currency . number_format($s_discount, 2); ?></td>
                    <td style="padding:12px; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($s_final, 2); ?></td>
                    <td style="padding:12px;"></td>
                </tr>

            <?php else :
                // Multi day totals
                $m_orders   = array_sum(array_column($results, 'total_orders'));
                $m_dinein   = array_sum(array_column($results, 'dinein_rev'));
                $m_takeaway = array_sum(array_column($results, 'takeaway_rev'));
                $m_delivery = array_sum(array_column($results, 'delivery_rev'));
                $m_discount = array_sum(array_column($results, 'total_discount'));
                $m_revenue  = array_sum(array_column($results, 'total_revenue'));

                foreach ( $results as $row ) : ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px;"><?php echo date('M d, Y', strtotime($row->report_date)); ?></td>
                        <td style="padding:12px;"><?php echo $row->total_orders; ?></td>
                        <td style="padding:12px;"><?php echo $currency . number_format($row->dinein_rev, 2); ?></td>
                        <td style="padding:12px;"><?php echo $currency . number_format($row->takeaway_rev, 2); ?></td>
                        <td style="padding:12px;"><?php echo $currency . number_format($row->delivery_rev, 2); ?></td>
                        <td style="padding:12px; color:#e74c3c;">-<?php echo $currency . number_format($row->total_discount, 2); ?></td>
                        <td style="padding:12px; font-weight:bold; color:#2271b1;"><?php echo $currency . number_format($row->total_revenue, 2); ?></td>
                    </tr>
                <?php endforeach; ?>

                <!-- Multi Day Total Row -->
                <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700; color:#1e293b;">
                    <td style="padding:12px;">TOTAL</td>
                    <td style="padding:12px;"><?php echo $m_orders; ?></td>
                    <td style="padding:12px;"><?php echo $currency . number_format($m_dinein, 2); ?></td>
                    <td style="padding:12px;"><?php echo $currency . number_format($m_takeaway, 2); ?></td>
                    <td style="padding:12px;"><?php echo $currency . number_format($m_delivery, 2); ?></td>
                    <td style="padding:12px; color:#e74c3c;">-<?php echo $currency . number_format($m_discount, 2); ?></td>
                    <td style="padding:12px; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($m_revenue, 2); ?></td>
                </tr>

            <?php endif; ?>

            </tbody>
        </table>
    </div>

    <?php else : ?>
        <div style="text-align:center; padding:50px; color:#94a3b8;">
            <p>No completed orders found for this period.</p>
            <small>Table: <?php echo $order_table; ?> | Res ID: <?php echo $res_id; ?> | Range: <?php echo $start_date; ?> to <?php echo $end_date; ?></small>
        </div>
    <?php endif;

    $output = ob_get_clean();
    wp_send_json_success(['data' => $output]);
}
add_action('wp_ajax_fetch_sales_report_data', 'handle_fetch_sales_report_data');


