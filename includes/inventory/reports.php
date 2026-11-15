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
    
    <!-- 📌 Date Filter & Action Button on a Single Line -->
    <div class="qrrs-card" style="background:#fff; padding:20px 25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px !important; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <form method="get" style="display:flex; gap:15px; align-items:center; flex-wrap:wrap; justify-content:space-between; margin:0;">
            <input type="hidden" name="tab" value="inventory">
            <input type="hidden" name="inv_section" value="reports">
            
            <div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="font-weight:600; font-size:13px; color:#475569;">From:</label>
                    <input type="date" name="rf" value="<?php echo esc_attr( $from ); ?>" style="height:38px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#f8fafc;">
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="font-weight:600; font-size:13px; color:#475569;">To:</label>
                    <input type="date" name="rt" value="<?php echo esc_attr( $to ); ?>" style="height:38px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#f8fafc;">
                </div>
                <button type="submit" class="qrrs-btn-save" style="height:38px; padding:0 18px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                    <span class="material-icons-outlined" style="font-size:16px;">filter_alt</span> Show report
                </button>
            </div>

            <span style="color:#64748b; font-size:12px; font-style:italic;">Period applies to sections 2, 3 and 4 (valuation is as of today).</span>
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
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px !important; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <h3 style="margin-top:0; font-size:17px; color:#1e293b; font-weight:700;">1. Stock Valuation <span style="font-size:13px; color:#64748b; font-weight:normal;">(today)</span></h3>
        <p style="font-size:18px; margin:8px 0 16px; color:#0f172a;"><strong>Total stock value: <?php echo esc_html( qrrs_reports_money( $val_total ) ); ?></strong></p>
        
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                    <th style="padding:12px; font-size:13px; color:#475569;">Category</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Items</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Value</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Visual Share</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Shortage items</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $val_rows as $v ) : 
                $percentage = $val_total > 0 ? ((float)$v->value / $val_total) * 100 : 0;
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( $v->cat ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo intval( $v->items ); ?></td>
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( qrrs_reports_money( $v->value ) ); ?></td>
                    <!-- 📊 Visual Progress Bar for Stock Share -->
                    <td style="padding:12px; width:200px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="flex:1; background:#f1f5f9; height:8px; border-radius:4px; overflow:hidden; border:1px solid #e2e8f0;">
                                <div style="background:#2563eb; height:100%; width:<?php echo min(100, max(2, $percentage)); ?>%; border-radius:4px;"></div>
                            </div>
                            <span style="font-size:11px; color:#64748b; font-weight:600; width:35px; text-align:right;"><?php echo number_format($percentage, 1); ?>%</span>
                        </div>
                    </td>
                    <td style="padding:12px; color:<?php echo intval( $v->shortage ) > 0 ? '#dc2626' : 'inherit'; ?>; font-weight:<?php echo intval( $v->shortage ) > 0 ? 'bold' : 'normal'; ?>;"><?php echo intval( $v->shortage ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $val_rows ) ) : ?><tr><td colspan="5" style="text-align:center; padding:25px; color:#64748b;">No items yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <p style="color:#64748b; font-size:12px; margin-top:12px; margin-bottom:0;">Shortage (negative) items are counted as zero value.</p>
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
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px !important; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <h3 style="margin-top:0; font-size:17px; color:#1e293b; font-weight:700;">2. Usage <span style="font-size:13px; color:#64748b; font-weight:normal;">(<?php echo esc_html( $from ); ?> → <?php echo esc_html( $to ); ?>)</span></h3>
        <p style="margin:8px 0 16px; font-size:14px; color:#475569;">
            Used by sales: <strong><?php echo esc_html( qrrs_reports_money( $sold_total ) ); ?></strong> &nbsp;|&nbsp;
            Wasted: <strong style="color:#dc2626;"><?php echo esc_html( qrrs_reports_money( $waste_total ) ); ?></strong>
        </p>
        <div style="overflow-x:auto;">
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                    <th style="padding:12px; font-size:13px; color:#475569;">Item</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Purchased</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Used (sales)</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Wasted</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Used value</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $usage as $u ) :
                if ( 0.0 === (float) $u->purchased && 0.0 === (float) $u->sold && 0.0 === (float) $u->wasted ) continue;
                $unit = ' ' . $u->unit_code;
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( $u->item_name ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo esc_html( qrrs_reports_fmt_qty( $u->purchased ) . $unit ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo esc_html( qrrs_reports_fmt_qty( $u->sold ) . $unit ); ?></td>
                    <td style="padding:12px; color:#dc2626; font-weight:600;"><?php echo esc_html( qrrs_reports_fmt_qty( $u->wasted ) . $unit ); ?></td>
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( qrrs_reports_money( (float) $u->sold * (float) $u->cost_per_unit ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $usage ) ) : ?><tr><td colspan="5" style="text-align:center; padding:25px; color:#64748b;">No movement in this period.</td></tr><?php endif; ?>
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
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px !important; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <h3 style="margin-top:0; font-size:17px; color:#1e293b; font-weight:700;">3. Purchases by Supplier</h3>
        <p style="font-size:16px; margin:8px 0 16px; color:#0f172a;"><strong>Total purchased: <?php echo esc_html( qrrs_reports_money( $pur_total ) ); ?></strong></p>
        
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                    <th style="padding:12px; font-size:13px; color:#475569;">Supplier</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Bills</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Total</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Visual Share</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $by_supplier as $b ) : 
                $sup_percentage = $pur_total > 0 ? ((float)$b->total / $pur_total) * 100 : 0;
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( $b->supplier ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo intval( $b->bills ); ?></td>
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( qrrs_reports_money( $b->total ) ); ?></td>
                    <!-- 📊 Visual Progress Bar for Supplier Share -->
                    <td style="padding:12px; width:200px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="flex:1; background:#f1f5f9; height:8px; border-radius:4px; overflow:hidden; border:1px solid #e2e8f0;">
                                <div style="background:#10b981; height:100%; width:<?php echo min(100, max(2, $sup_percentage)); ?>%; border-radius:4px;"></div>
                            </div>
                            <span style="font-size:11px; color:#64748b; font-weight:600; width:35px; text-align:right;"><?php echo number_format($sup_percentage, 1); ?>%</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $by_supplier ) ) : ?><tr><td colspan="4" style="text-align:center; padding:25px; color:#64748b;">No purchases in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    // ---- 4. Food cost % per menu item: base, each size, each add-on ----
    $grp_t = $wpdb->prefix . 'qrrs_menu_option_groups';
    $val_t = $wpdb->prefix . 'qrrs_menu_option_values';
    $vr_t  = $wpdb->prefix . 'qrrs_variant_recipes';

    $ing_cost = function ( $ings, $mult ) {
        $c = 0.0;
        foreach ( $ings as $g ) {
            list( $fr, $br ) = qrrs_order_stock_unit( $g->unit_id );
            list( $fi, $bi ) = qrrs_order_stock_unit( $g->item_unit_id );
            if ( $br !== $bi || $fi <= 0 ) continue;
            $c += (float) $g->quantity_required * $mult * ( 1 + (float) $g->wastage_percent / 100 ) * $fr / $fi * (float) $g->cost_per_unit;
        }
        return $c;
    };

    $menus = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, item_name, price FROM $menu_t WHERE restaurant_id = %d ORDER BY item_name ASC",
        $restaurant_id
    ) );

    $rows4 = [];
    foreach ( $menus as $m ) {
        // Base recipe
        $recipe = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, serving_qty FROM $rec_t WHERE restaurant_id = %d AND menu_item_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
            $restaurant_id, $m->id
        ) );
        $base_cost = 0.0;
        if ( $recipe ) {
            $serving = (float) $recipe->serving_qty > 0 ? (float) $recipe->serving_qty : 1.0;
            $base_ings = $wpdb->get_results( $wpdb->prepare(
                "SELECT ri.quantity_required, ri.unit_id, ri.wastage_percent, i.unit_id AS item_unit_id, i.cost_per_unit
                 FROM $ri_t ri JOIN $item_t i ON i.id = ri.inventory_item_id WHERE ri.recipe_id = %d", $recipe->id
            ) );
            $base_cost = $ing_cost( $base_ings, 1 / $serving );
        }

        // Size groups
        $groups = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, group_name, group_type, is_required FROM $grp_t WHERE restaurant_id = %d AND menu_item_id = %d AND status = 'active' ORDER BY sort_order, id",
            $restaurant_id, $m->id
        ) );
        $has_required_size = false;
        foreach ( $groups as $g ) { if ( 'size' === $g->group_type && $g->is_required ) $has_required_size = true; }

        if ( ! $has_required_size ) {
            $rows4[] = [ 'item' => $m->item_name, 'option' => 'Base', 'cost' => $base_cost, 'price' => (float) $m->price, 'has_recipe' => (bool) $recipe ];
        }

        foreach ( $groups as $g ) {
            $values = $wpdb->get_results( $wpdb->prepare(
                "SELECT value_name, price, recipe_factor FROM $val_t WHERE group_id = %d AND status = 'active' ORDER BY sort_order, id", $g->id
            ) );
            foreach ( $values as $v ) {
                if ( 'size' === $g->group_type ) {
                    $rows4[] = [
                        'item' => $m->item_name, 'option' => $v->value_name,
                        'cost' => $base_cost * (float) $v->recipe_factor, 'price' => (float) $v->price,
                        'has_recipe' => (bool) $recipe,
                    ];
                } else {
                    $vr_ings = $wpdb->get_results( $wpdb->prepare(
                        "SELECT vr.quantity_required, vr.unit_id, vr.wastage_percent, i.unit_id AS item_unit_id, i.cost_per_unit
                         FROM $vr_t vr JOIN $item_t i ON i.id = vr.inventory_item_id
                         WHERE vr.restaurant_id = %d AND vr.menu_item_id = %d AND vr.variant_name = %s",
                        $restaurant_id, $m->id, $v->value_name
                    ) );
                    if ( empty( $vr_ings ) ) continue;
                    $rows4[] = [
                        'item' => $m->item_name, 'option' => '+ ' . $v->value_name . ' (add-on)',
                        'cost' => $ing_cost( $vr_ings, 1 ), 'price' => (float) $v->price, 'has_recipe' => true,
                    ];
                }
            }
        }
    }
    ?>
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <h3 style="margin-top:0; font-size:17px; color:#1e293b; font-weight:700;">4. Food Cost % <span style="font-size:13px; color:#64748b; font-weight:normal;">(today's raw material cost ÷ price)</span></h3>
        <div style="overflow-x:auto;">
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                    <th style="padding:12px; font-size:13px; color:#475569;">Menu item</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Option</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Cost</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Price</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Food Cost Visual %</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows4 as $r ) :
                $pct   = $r['price'] > 0 ? ( $r['cost'] / $r['price'] ) * 100 : null;
                $color = null === $pct ? '#94a3b8' : ( $pct <= 35 ? '#16a34a' : ( $pct <= 50 ? '#b45309' : '#dc2626' ) );
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( $r['item'] ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo esc_html( $r['option'] ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo $r['has_recipe'] || $r['cost'] > 0 ? esc_html( qrrs_reports_money( $r['cost'] ) ) : '<span style="color:#94a3b8;">no recipe</span>'; ?></td>
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( qrrs_reports_money( $r['price'] ) ); ?></td>
                    <!-- 📊 Visual Progress Bar for Food Cost % -->
                    <td style="padding:12px; width:220px;">
                        <?php if ( null !== $pct ) : ?>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; background:#f1f5f9; height:8px; border-radius:4px; overflow:hidden; border:1px solid #e2e8f0;">
                                    <div style="background:<?php echo $color; ?>; height:100%; width:<?php echo min(100, max(2, $pct)); ?>%; border-radius:4px;"></div>
                                </div>
                                <span style="font-size:12px; font-weight:bold; color:<?php echo $color; ?>; width:45px; text-align:right;"><?php echo number_format( $pct, 1 ); ?>%</span>
                            </div>
                        <?php else : ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rows4 ) ) : ?><tr><td colspan="5" style="text-align:center; padding:25px; color:#64748b;">No menu items yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <p style="color:#64748b; font-size:12px; margin-top:12px; margin-bottom:0;">Size rows use the base recipe × the size's factor (250ml = 0.25 etc.) and that size's price. Add-on rows use only the add-on's own ingredients. Green ≤ 35%, amber ≤ 50%, red above 50%.</p>
    </div>
    <?php
}