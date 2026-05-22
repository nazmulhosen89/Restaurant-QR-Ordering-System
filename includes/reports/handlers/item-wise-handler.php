<?php
if ( ! defined( 'ABSPATH' ) ) exit;




/**
 * AJAX: Item-wise Sales Report
 * এই function টা qrrs-restaurant-system.php এ add করো
 * add_action এর উপরে paste করো
 */
function handle_fetch_item_wise_report() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'qrrs_nonce_action') ) {
        wp_send_json_error('Security check failed.');
    }

    parse_str($_POST['formData'], $params);
    global $wpdb;

    $res_id      = intval($params['restaurant_id']);
    $date_range  = isset($params['date_range']) ? sanitize_text_field($params['date_range']) : '';
    $category_id = isset($params['category_id']) ? sanitize_text_field($params['category_id']) : 'all';

    if ( empty($date_range) ) {
        wp_send_json_success(array('data' => '<p>Please select a date range.</p>'));
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

    $sql = "SELECT
                oi.item_id,
                oi.item_name,
                SUM(oi.quantity)             AS total_qty,
                SUM(oi.price * oi.quantity)  AS total_amount,
                AVG(oi.price)                AS avg_price,
                COALESCE(mi.item_image, '') AS item_img,
                COALESCE(c.category_name, 'Uncategorized') AS category_name
            FROM {$items_table} oi
            INNER JOIN {$orders_table} o
                ON oi.order_id = o.id
                AND o.restaurant_id = %d
                AND DATE(o.created_at) BETWEEN %s AND %s
                AND o.order_status = 'completed'
            LEFT JOIN {$menu_table} mi ON oi.item_id = mi.id
            LEFT JOIN {$cat_table}  c  ON mi.category_id = c.id
            GROUP BY oi.item_id, oi.item_name
            ORDER BY total_qty DESC";

    $results = $wpdb->get_results($wpdb->prepare($sql, $res_id, $start_date, $end_date));

    // ✅ DB Error + actual table names debug
    if ( $wpdb->last_error ) {
        wp_send_json_success(array('data' => '<p style="color:red;">DB Error: ' . $wpdb->last_error . '</p><p>Tables checked: ' . $orders_table . ', ' . $items_table . ', ' . $menu_table . '</p>'));
        return;
    }

    if ( empty($results) ) {
        // order_items table এ data আছে কিনা দেখো
        $test = $wpdb->get_var("SELECT COUNT(*) FROM {$items_table}");
        $test2 = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$orders_table} WHERE restaurant_id = %d AND order_status = 'completed'", $res_id));
        wp_send_json_success(array('data' => '<p style="color:orange;">No results. order_items total rows: ' . $test . ' | completed orders for res_id ' . $res_id . ': ' . $test2 . '</p>'));
        return;
    }

    // ---- Totals ----
    $total_count  = count($results);
    $grand_qty    = 0;
    $grand_amount = 0;
    foreach ( $results as $r ) {
        $grand_qty    += intval($r->total_qty);
        $grand_amount += floatval($r->total_amount);
    }

    $highest_item = $results[0];
    $lowest_item  = $results[$total_count - 1];
    $avg_item     = $results[(int) floor($total_count / 2)];

    ob_start();
    ?>

    <div class="item-rank-cards">
        <div class="item-rank-card rank-highest">
            <?php if ( ! empty($highest_item->item_img) ) : ?>
                <img src="<?php echo esc_url($highest_item->item_img); ?>" alt="" style="width:52px; height:52px; border-radius:8px; object-fit:cover;">
            <?php else : ?>
                <div style="width:52px; height:52px; border-radius:8px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:22px;">🍽️</div>
            <?php endif; ?>
            <div class="rank-info">
<<<<<<< HEAD
                <div class="rank-label"><i class="fas fa-trophy"></i> Highest Selling</div>
=======
                <div class="rank-label">🏆 Highest Selling</div>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                <div class="rank-name"><?php echo esc_html($highest_item->item_name); ?></div>
                <div class="rank-qty"><?php echo intval($highest_item->total_qty); ?> sold &bull; <?php echo $currency . number_format($highest_item->total_amount, 2); ?></div>
            </div>
        </div>
        <div class="item-rank-card rank-avg">
            <?php if ( ! empty($avg_item->item_img) ) : ?>
                <img src="<?php echo esc_url($avg_item->item_img); ?>" alt="" style="width:52px; height:52px; border-radius:8px; object-fit:cover;">
            <?php else : ?>
                <div style="width:52px; height:52px; border-radius:8px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:22px;">🍽️</div>
            <?php endif; ?>
            <div class="rank-info">
<<<<<<< HEAD
                <div class="rank-label"><i class="fas fa-chart-bar"></i> Average Selling</div>
=======
                <div class="rank-label">📊 Average Selling</div>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                <div class="rank-name"><?php echo esc_html($avg_item->item_name); ?></div>
                <div class="rank-qty"><?php echo intval($avg_item->total_qty); ?> sold &bull; <?php echo $currency . number_format($avg_item->total_amount, 2); ?></div>
            </div>
        </div>
        <div class="item-rank-card rank-lowest">
            <?php if ( ! empty($lowest_item->item_img) ) : ?>
                <img src="<?php echo esc_url($lowest_item->item_img); ?>" alt="" style="width:52px; height:52px; border-radius:8px; object-fit:cover;">
            <?php else : ?>
                <div style="width:52px; height:52px; border-radius:8px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:22px;">🍽️</div>
            <?php endif; ?>
            <div class="rank-info">
<<<<<<< HEAD
                <div class="rank-label"><i class="fas fa-chart-line"></i> Lowest Selling</div>
=======
                <div class="rank-label">📉 Lowest Selling</div>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
                <div class="rank-name"><?php echo esc_html($lowest_item->item_name); ?></div>
                <div class="rank-qty"><?php echo intval($lowest_item->total_qty); ?> sold &bull; <?php echo $currency . number_format($lowest_item->total_amount, 2); ?></div>
            </div>
        </div>
    </div>

    <div style="background:#fff; border-radius:12px; border:1px solid #eee; overflow:hidden; margin-top:20px;">
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <thead style="background:#f8fafc;">
                <tr>
                    <th style="padding:12px;">#</th>
                    <th style="padding:12px;">Item</th>
                    <th style="padding:12px;">Category</th>
                    <th style="padding:12px; text-align:center;">Qty Sold</th>
                    <th style="padding:12px; text-align:right;">Total Amount</th>
                    <th style="padding:12px; text-align:right;">Avg. Unit Price</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $i = 1;
            foreach ( $results as $row ) :
                if ( ! empty($row->item_img) ) {
                    $img_html = '<img src="' . esc_url($row->item_img) . '" style="width:38px; height:38px; border-radius:6px; object-fit:cover;">';
                } else {
                    $img_html = '<div style="width:38px; height:38px; border-radius:6px; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; font-size:16px;">🍽️</div>';
                }
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; color:#94a3b8; font-size:12px;"><?php echo $i++; ?></td>
                    <td style="padding:12px;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <?php echo $img_html; ?>
                            <span style="font-weight:500; color:#1e293b;"><?php echo esc_html($row->item_name); ?></span>
                        </div>
                    </td>
                    <td style="padding:12px;">
                        <span style="background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;"><?php echo esc_html($row->category_name); ?></span>
                    </td>
                    <td style="padding:12px; text-align:center; font-weight:600;"><?php echo intval($row->total_qty); ?></td>
                    <td style="padding:12px; text-align:right; font-weight:600; color:#2271b1;"><?php echo $currency . number_format(floatval($row->total_amount), 2); ?></td>
                    <td style="padding:12px; text-align:right; color:#475569;"><?php echo $currency . number_format(floatval($row->avg_price), 2); ?></td>
                </tr>
            <?php endforeach; ?>
                <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                    <td style="padding:12px;" colspan="3">TOTAL (<?php echo $total_count; ?> items)</td>
                    <td style="padding:12px; text-align:center;"><?php echo $grand_qty; ?></td>
                    <td style="padding:12px; text-align:right; color:#2271b1; font-size:15px;"><?php echo $currency . number_format($grand_amount, 2); ?></td>
                    <td style="padding:12px;"></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php
    $output = ob_get_clean();
    wp_send_json_success(array('data' => $output));
}
add_action('wp_ajax_fetch_item_wise_report', 'handle_fetch_item_wise_report');

