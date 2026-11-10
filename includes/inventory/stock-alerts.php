<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stock alerts for the inventory module.
 *  - Counts of low and negative (shortage) items, shown as a banner.
 *  - Low Stock tab: every item at or below its minimum level. Negative stock
 *    is marked as "Shortage".
 *  - Menu items that have no active recipe (their sales do not reduce stock).
 */

function qrrs_stock_alert_counts( $restaurant_id ) {
    global $wpdb;
    $t = $wpdb->prefix . 'qrrs_inventory_items';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            SUM(CASE WHEN current_stock <= min_stock_level THEN 1 ELSE 0 END) AS low,
            SUM(CASE WHEN current_stock < 0 THEN 1 ELSE 0 END) AS negative
         FROM $t WHERE restaurant_id = %d AND status = 'active'",
        $restaurant_id
    ) );
    return [
        'low'      => intval( $row->low ?? 0 ),
        'negative' => intval( $row->negative ?? 0 ),
    ];
}

/** Banner under the inventory tabs. Hidden on the Low Stock tab itself (it already lists them). */
function qrrs_stock_alert_banner( $restaurant_id, $section ) {
    if ( 'low-stock' === $section ) {
        return;
    }
    $c = qrrs_stock_alert_counts( $restaurant_id );
    if ( $c['low'] <= 0 ) {
        return;
    }
    $url = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'low-stock' ], home_url( '/restaurant-dashboard/' ) );
    $red = $c['negative'] > 0;
    ?>
    <div style="background:<?php echo $red ? '#fee2e2' : '#fef3c7'; ?>; color:<?php echo $red ? '#991b1b' : '#92400e'; ?>; padding:12px 16px; border-radius:8px; margin:12px 0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span>
            ⚠ <strong><?php echo intval( $c['low'] ); ?></strong> item(s) low on stock
            <?php if ( $red ) : ?>, <strong><?php echo intval( $c['negative'] ); ?></strong> in shortage (negative)<?php endif; ?>.
        </span>
        <a href="<?php echo esc_url( $url ); ?>" style="font-weight:bold; color:inherit; text-decoration:underline;">View Low Stock →</a>
    </div>
    <?php
}

/** Menu items with no active recipe. */
function qrrs_items_without_recipe( $restaurant_id ) {
    global $wpdb;
    $menu_t = $wpdb->prefix . 'qrrs_items';
    $rec_t  = $wpdb->prefix . 'qrrs_recipes';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT m.id, m.item_name, m.price
         FROM $menu_t m
         WHERE m.restaurant_id = %d
           AND NOT EXISTS (
               SELECT 1 FROM $rec_t r
               WHERE r.menu_item_id = m.id AND r.restaurant_id = m.restaurant_id AND r.status = 'active'
           )
         ORDER BY m.item_name ASC",
        $restaurant_id
    ) );
}

/** Full Low Stock tab. */
function qrrs_low_stock_render( $restaurant_id ) {
    global $wpdb;
    $item_t = $wpdb->prefix . 'qrrs_inventory_items';
    $unit_t = $wpdb->prefix . 'qrrs_inventory_units';
    $cat_t  = $wpdb->prefix . 'qrrs_inventory_categories';

    $low = $wpdb->get_results( $wpdb->prepare(
        "SELECT i.item_name, i.current_stock, i.min_stock_level, u.unit_code, c.category_name
         FROM $item_t i
         LEFT JOIN $unit_t u ON u.id = i.unit_id
         LEFT JOIN $cat_t c ON c.id = i.category_id
         WHERE i.restaurant_id = %d AND i.status = 'active' AND i.current_stock <= i.min_stock_level
         ORDER BY i.current_stock ASC",
        $restaurant_id
    ) );
    $no_recipe = qrrs_items_without_recipe( $restaurant_id );

    $fmt = function ( $n ) { return rtrim( rtrim( number_format( (float) $n, 3, '.', '' ), '0' ), '.' ); };
    ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">Low Stock Alert</h3>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Item</th><th>Category</th><th>Current</th><th>Min Level</th><th>Need to buy</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ( $low as $it ) :
                $neg = (float) $it->current_stock < 0;
                $need = max( 0, (float) $it->min_stock_level - (float) $it->current_stock );
            ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><strong><?php echo esc_html( $it->item_name ); ?></strong></td>
                    <td><?php echo esc_html( $it->category_name ?: '—' ); ?></td>
                    <td style="color:<?php echo $neg ? '#dc2626' : '#b45309'; ?>; font-weight:bold;"><?php echo esc_html( $fmt( $it->current_stock ) . ' ' . $it->unit_code ); ?></td>
                    <td><?php echo esc_html( $fmt( $it->min_stock_level ) . ' ' . $it->unit_code ); ?></td>
                    <td><?php echo esc_html( $fmt( $need ) . ' ' . $it->unit_code ); ?></td>
                    <td>
                        <?php if ( $neg ) : ?>
                            <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:bold;">Shortage</span>
                        <?php else : ?>
                            <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:10px; font-size:12px;">Low</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $low ) ) : ?>
                <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No low stock items right now.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="qrrs-card" style="padding:20px;">
        <h3 style="margin-top:0;">Menu items without a recipe (<?php echo count( $no_recipe ); ?>)</h3>
        <p style="color:#64748b; font-size:13px; margin-top:-6px;">Selling these does not reduce raw material stock. Add a recipe to track them.</p>
        <?php if ( empty( $no_recipe ) ) : ?>
            <p style="color:#16a34a;">All menu items have a recipe.</p>
        <?php else : ?>
            <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
                <thead><tr style="background:#f1f5f9; text-align:left;"><th>Menu item</th><th>Price (৳)</th></tr></thead>
                <tbody>
                <?php foreach ( $no_recipe as $m ) : ?>
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td><?php echo esc_html( $m->item_name ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $m->price, 2 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}