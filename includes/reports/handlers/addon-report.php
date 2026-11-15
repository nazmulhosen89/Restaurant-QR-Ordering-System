<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Add-on card for the Item-wise sales report.
 * Counts how often each add-on was sold (completed orders in the date range) and
 * the extra revenue it brought (add-on price x quantity).
 * Old add-ons with no price (price 0) still count, with 0 revenue.
 */
function qrrs_addon_report_html( $res_id, $start_date, $end_date, $currency ) {
    global $wpdb;
    $items_table  = $wpdb->prefix . 'qrrs_order_items';
    $orders_table = $wpdb->prefix . 'qrrs_orders';
    $menu_table   = $wpdb->prefix . 'qrrs_items';
    $g_table      = $wpdb->prefix . 'qrrs_menu_option_groups';
    $v_table      = $wpdb->prefix . 'qrrs_menu_option_values';

    $lines = $wpdb->get_results( $wpdb->prepare(
        "SELECT oi.item_id, oi.variants_selected, oi.quantity
         FROM {$items_table} oi
         INNER JOIN {$orders_table} o ON oi.order_id = o.id
            AND o.restaurant_id = %d AND DATE(o.created_at) BETWEEN %s AND %s AND o.order_status = 'completed'
         WHERE oi.variants_selected IS NOT NULL AND oi.variants_selected <> ''",
        $res_id, $start_date, $end_date
    ) );

    $price_cache = [];
    $stats = [];   // key => [ name, item, qty, revenue ]
    foreach ( $lines as $ln ) {
        $names = array_unique( array_filter( array_map( 'trim', explode( ',', (string) $ln->variants_selected ) ) ) );
        foreach ( $names as $name ) {
            $ck = $ln->item_id . '|' . $name;
            if ( ! isset( $price_cache[ $ck ] ) ) {
                $p = $wpdb->get_var( $wpdb->prepare(
                    "SELECT v.price FROM {$v_table} v JOIN {$g_table} g ON g.id = v.group_id
                     WHERE v.menu_item_id = %d AND v.value_name = %s AND g.group_type = 'addon' LIMIT 1",
                    intval( $ln->item_id ), $name
                ) );
                $price_cache[ $ck ] = null === $p ? 0.0 : (float) $p;
            }
            $key = $ln->item_id . '|' . $name;
            if ( ! isset( $stats[ $key ] ) ) {
                $item_name = $wpdb->get_var( $wpdb->prepare( "SELECT item_name FROM {$menu_table} WHERE id = %d", intval( $ln->item_id ) ) );
                $stats[ $key ] = [ 'name' => $name, 'item' => $item_name ?: '—', 'qty' => 0, 'revenue' => 0.0 ];
            }
            $stats[ $key ]['qty']     += intval( $ln->quantity );
            $stats[ $key ]['revenue'] += $price_cache[ $ck ] * intval( $ln->quantity );
        }
    }

    if ( empty( $stats ) ) {
        return '';
    }
    uasort( $stats, fn( $a, $b ) => $b['qty'] <=> $a['qty'] );

    $total_qty = 0; $total_rev = 0.0;
    foreach ( $stats as $s ) { $total_qty += $s['qty']; $total_rev += $s['revenue']; }

    ob_start(); ?>
    <div style="margin-top:24px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:18px;">
        <h3 style="margin:0 0 4px 0; font-size:16px;">Add-on Sales</h3>
        <p style="margin:0 0 12px 0; color:#64748b; font-size:13px;">Extra items sold with menu items in this period.</p>
        <table style="width:100%; border-collapse:collapse; font-size:14px;">
            <thead>
                <tr style="background:#f8fafc; text-align:left;">
                    <th style="padding:10px;">Add-on</th>
                    <th style="padding:10px;">With item</th>
                    <th style="padding:10px; text-align:center;">Times sold</th>
                    <th style="padding:10px; text-align:right;">Extra revenue</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $stats as $s ) : ?>
                <tr style="border-top:1px solid #e2e8f0;">
                    <td style="padding:10px; font-weight:600;"><?php echo esc_html( $s['name'] ); ?></td>
                    <td style="padding:10px; color:#475569;"><?php echo esc_html( $s['item'] ); ?></td>
                    <td style="padding:10px; text-align:center;"><?php echo intval( $s['qty'] ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo $currency . number_format( $s['revenue'], 2 ); ?></td>
                </tr>
            <?php endforeach; ?>
                <tr style="background:#f0f6fb; border-top:2px solid #2271b1; font-weight:700;">
                    <td style="padding:10px;" colspan="2">TOTAL</td>
                    <td style="padding:10px; text-align:center;"><?php echo intval( $total_qty ); ?></td>
                    <td style="padding:10px; text-align:right; color:#2271b1;"><?php echo $currency . number_format( $total_rev, 2 ); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}