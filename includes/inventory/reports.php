<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Inventory reports (managers / admins).
 *  1. Stock valuation: current stock x cost, per category.
 *  2. Usage in a period: purchased, used by sales, wasted, per item.
 *  3. Purchases by supplier in a period.
 *  4. Food cost %: recipe cost vs menu price, per menu item.
 */

function qrrs_reports_fmt_qty( $n ) {
    return rtrim( rtrim( number_format( (float) $n, 3, '.', '' ), '0' ), '.' );
}

function qrrs_reports_money( $n ) {
    return '৳' . number_format( (float) $n, 2 );
}

function qrrs_reports_render( $restaurant_id, $can_manage ) {
    global $wpdb;
    if ( ! $can_manage ) {
        echo '<div class="qrrs-card" style="padding:20px;">Reports are for managers and admins only.</div>';
        return;
    }

    $item_t = $wpdb->prefix . 'qrrs_inventory_items';
    $unit_t = $wpdb->prefix . 'qrrs_inventory_units';
    $cat_t  = $wpdb->prefix . 'qrrs_inventory_categories';
    $led_t  = $wpdb->prefix . 'qrrs_stock_movements';
    $pur_t  = $wpdb->prefix . 'qrrs_purchases';
    $sup_t  = $wpdb->prefix . 'qrrs_suppliers';
    $menu_t = $wpdb->prefix . 'qrrs_items';
    $rec_t  = $wpdb->prefix . 'qrrs_recipes';
    $ri_t   = $wpdb->prefix . 'qrrs_recipe_items';

    // Period filter
    $from = isset( $_GET['rf'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['rf'] ) ? $_GET['rf'] : current_time( 'Y-m' ) . '-01';
    $to   = isset( $_GET['rt'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['rt'] ) ? $_GET['rt'] : current_time( 'Y-m-d' );
    if ( $to < $from ) { $to = $from; }
    $from_dt = $from . ' 00:00:00';
    $to_dt   = $to . ' 23:59:59';
    ?>
    <div class="qrrs-card" style="padding:16px 20px; margin-bottom:20px;">
        <form method="get" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin:0;">
            <input type="hidden" name="tab" value="inventory">
            <input type="hidden" name="inv_section" value="reports">
            <label>From <input type="date" name="rf" value="<?php echo esc_attr( $from ); ?>"></label>
            <label>To <input type="date" name="rt" value="<?php echo esc_attr( $to ); ?>"></label>
            <button type="submit" class="button">Show report</button>
            <span style="color:#64748b; font-size:12px;">Period applies to sections 2, 3 and 4 (valuation is as of today).</span>
        </form>
    </div>

    <?php
    // ---- 1. Stock valuation ----
    $val_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(c.category_name, 'Uncategorized') AS cat,
                COUNT(*) AS items,
                SUM(GREATEST(i.current_stock, 0) * i.cost_per_unit) AS value,
                SUM(CASE WHEN i.current_stock < 0 THEN 1 ELSE 0 END) AS shortage
         FROM $item_t i LEFT JOIN $cat_t c ON c.id = i.category_id
         WHERE i.restaurant_id = %d AND i.status = 'active'
         GROUP BY cat ORDER BY value DESC",
        $restaurant_id
    ) );
    $val_total = 0;
    foreach ( $val_rows as $v ) { $val_total += (float) $v->value; }
    ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">1. Stock Valuation <span style="font-size:14px; color:#64748b;">(today)</span></h3>
        <p style="font-size:18px; margin:6px 0 12px;"><strong>Total stock value: <?php echo esc_html( qrrs_reports_money( $val_total ) ); ?></strong></p>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Category</th><th>Items</th><th>Value</th><th>Shortage items</th></tr></thead>
            <tbody>
            <?php foreach ( $val_rows as $v ) : ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo esc_html( $v->cat ); ?></td>
                    <td><?php echo intval( $v->items ); ?></td>
                    <td><?php echo esc_html( qrrs_reports_money( $v->value ) ); ?></td>
                    <td style="color:<?php echo intval( $v->shortage ) > 0 ? '#dc2626' : 'inherit'; ?>;"><?php echo intval( $v->shortage ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $val_rows ) ) : ?><tr><td colspan="4" style="text-align:center; padding:16px; color:#94a3b8;">No items yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <p style="color:#64748b; font-size:12px; margin-bottom:0;">Shortage (negative) items are counted as zero value.</p>
    </div>

    <?php
    // ---- 2. Usage per item ----
    $usage = $wpdb->get_results( $wpdb->prepare(
        "SELECT i.item_name, i.cost_per_unit, u.unit_code,
                COALESCE(SUM(CASE WHEN m.movement_type = 'purchase' THEN m.quantity_in ELSE 0 END), 0) AS purchased,
                COALESCE(SUM(CASE WHEN m.movement_type = 'sale' THEN m.quantity_out ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN m.movement_type = 'sale_reversal' THEN m.quantity_in ELSE 0 END), 0) AS sold,
                COALESCE(SUM(CASE WHEN m.movement_type = 'wastage' THEN m.quantity_out ELSE 0 END), 0) AS wasted
         FROM $item_t i
         LEFT JOIN $unit_t u ON u.id = i.unit_id
         LEFT JOIN $led_t m ON m.inventory_item_id = i.id AND m.restaurant_id = i.restaurant_id
              AND m.created_at BETWEEN %s AND %s
         WHERE i.restaurant_id = %d AND i.status = 'active'
         GROUP BY i.id, i.item_name, i.cost_per_unit, u.unit_code
         ORDER BY i.item_name ASC",
        $from_dt, $to_dt, $restaurant_id
    ) );
    $waste_total = 0; $sold_total = 0;
    foreach ( $usage as $u ) {
        $waste_total += (float) $u->wasted * (float) $u->cost_per_unit;
        $sold_total  += (float) $u->sold   * (float) $u->cost_per_unit;
    }
    ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">2. Usage <span style="font-size:14px; color:#64748b;">(<?php echo esc_html( $from ); ?> → <?php echo esc_html( $to ); ?>)</span></h3>
        <p style="margin:6px 0 12px;">
            Used by sales: <strong><?php echo esc_html( qrrs_reports_money( $sold_total ) ); ?></strong> &nbsp;|&nbsp;
            Wasted: <strong style="color:#dc2626;"><?php echo esc_html( qrrs_reports_money( $waste_total ) ); ?></strong>
        </p>
        <div style="overflow-x:auto;">
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Item</th><th>Purchased</th><th>Used (sales)</th><th>Wasted</th><th>Used value</th></tr></thead>
            <tbody>
            <?php foreach ( $usage as $u ) :
                if ( 0.0 === (float) $u->purchased && 0.0 === (float) $u->sold && 0.0 === (float) $u->wasted ) continue;
                $unit = ' ' . $u->unit_code;
            ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo esc_html( $u->item_name ); ?></td>
                    <td><?php echo esc_html( qrrs_reports_fmt_qty( $u->purchased ) . $unit ); ?></td>
                    <td><?php echo esc_html( qrrs_reports_fmt_qty( $u->sold ) . $unit ); ?></td>
                    <td><?php echo esc_html( qrrs_reports_fmt_qty( $u->wasted ) . $unit ); ?></td>
                    <td><?php echo esc_html( qrrs_reports_money( (float) $u->sold * (float) $u->cost_per_unit ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $usage ) ) : ?><tr><td colspan="5" style="text-align:center; padding:16px; color:#94a3b8;">No movement in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php
    // ---- 3. Purchases by supplier ----
    $by_supplier = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(s.supplier_name, 'Unknown') AS supplier, COUNT(p.id) AS bills, SUM(p.total_amount) AS total
         FROM $pur_t p LEFT JOIN $sup_t s ON s.id = p.supplier_id
         WHERE p.restaurant_id = %d AND p.invoice_date BETWEEN %s AND %s
         GROUP BY p.supplier_id, supplier ORDER BY total DESC",
        $restaurant_id, $from, $to
    ) );
    $pur_total = 0;
    foreach ( $by_supplier as $b ) { $pur_total += (float) $b->total; }
    ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">3. Purchases by Supplier</h3>
        <p style="font-size:16px; margin:6px 0 12px;"><strong>Total purchased: <?php echo esc_html( qrrs_reports_money( $pur_total ) ); ?></strong></p>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Supplier</th><th>Bills</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ( $by_supplier as $b ) : ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo esc_html( $b->supplier ); ?></td>
                    <td><?php echo intval( $b->bills ); ?></td>
                    <td><?php echo esc_html( qrrs_reports_money( $b->total ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $by_supplier ) ) : ?><tr><td colspan="3" style="text-align:center; padding:16px; color:#94a3b8;">No purchases in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    // ---- 4. Food cost % per menu item (recipe cost vs price) ----
    $recipes = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.id, r.serving_qty, m.item_name, m.price
         FROM $rec_t r JOIN $menu_t m ON m.id = r.menu_item_id AND m.restaurant_id = r.restaurant_id
         WHERE r.restaurant_id = %d AND r.status = 'active'
         ORDER BY m.item_name ASC",
        $restaurant_id
    ) );
    ?>
    <div class="qrrs-card" style="padding:20px;">
        <h3 style="margin-top:0;">4. Food Cost % <span style="font-size:14px; color:#64748b;">(recipe cost ÷ menu price, today's raw material cost)</span></h3>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Menu item</th><th>Recipe cost</th><th>Price</th><th>Food cost %</th></tr></thead>
            <tbody>
            <?php foreach ( $recipes as $r ) :
                $ings = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ri.quantity_required, ri.unit_id, ri.wastage_percent, i.unit_id AS item_unit_id, i.cost_per_unit
                     FROM $ri_t ri JOIN $item_t i ON i.id = ri.inventory_item_id
                     WHERE ri.recipe_id = %d", $r->id
                ) );
                $serving = (float) $r->serving_qty > 0 ? (float) $r->serving_qty : 1.0;
                $cost = 0.0;
                foreach ( $ings as $g ) {
                    list( $fr, $br ) = qrrs_order_stock_unit( $g->unit_id );
                    list( $fi, $bi ) = qrrs_order_stock_unit( $g->item_unit_id );
                    if ( $br !== $bi || $fi <= 0 ) continue;
                    $qty_item_unit = (float) $g->quantity_required / $serving * ( 1 + (float) $g->wastage_percent / 100 ) * $fr / $fi;
                    $cost += $qty_item_unit * (float) $g->cost_per_unit;
                }
                $price = (float) $r->price;
                $pct   = $price > 0 ? ( $cost / $price ) * 100 : null;
                $color = null === $pct ? '#94a3b8' : ( $pct <= 35 ? '#16a34a' : ( $pct <= 50 ? '#b45309' : '#dc2626' ) );
            ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo esc_html( $r->item_name ); ?></td>
                    <td><?php echo esc_html( qrrs_reports_money( $cost ) ); ?></td>
                    <td><?php echo esc_html( qrrs_reports_money( $price ) ); ?></td>
                    <td style="font-weight:bold; color:<?php echo $color; ?>;"><?php echo null === $pct ? '—' : esc_html( number_format( $pct, 1 ) ) . '%'; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $recipes ) ) : ?><tr><td colspan="4" style="text-align:center; padding:16px; color:#94a3b8;">No recipes yet. Add recipes to see food cost.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <p style="color:#64748b; font-size:12px; margin-bottom:0;">Green ≤ 35%, amber ≤ 50%, red above 50%. Uses current cost per unit, so it updates when purchase costs change.</p>
    </div>
    <?php
}