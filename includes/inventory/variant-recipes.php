<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Variant (add-on) recipes.
 * A variant such as "Extra Cheese" or "Spicy" can add ingredients on top of the base recipe.
 * Half / Full sizes should be separate menu items (they have different prices), each with its own recipe.
 */

function qrrs_variant_redirect( $status ) {
    wp_safe_redirect( add_query_arg( [
        'tab'         => 'inventory',
        'inv_section' => 'variant-recipes',
        'status'      => $status,
        'vm'          => intval( $_POST['menu_item_id'] ?? 0 ),
    ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

/** Ingredient lines for one selected variant of one menu item (used by order deduction). */
function qrrs_variant_recipe_lines( $restaurant_id, $menu_item_id, $variant_name ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT vr.inventory_item_id, vr.quantity_required, vr.unit_id, vr.wastage_percent,
                i.unit_id AS item_unit_id, i.cost_per_unit
         FROM {$wpdb->prefix}qrrs_variant_recipes vr
         JOIN {$wpdb->prefix}qrrs_inventory_items i ON i.id = vr.inventory_item_id
         WHERE vr.restaurant_id = %d AND vr.menu_item_id = %d AND vr.variant_name = %s",
        $restaurant_id, intval( $menu_item_id ), $variant_name
    ) );
}

function qrrs_variant_handle_actions( $restaurant_id, $can_manage ) {
    global $wpdb;
    $vr_t   = $wpdb->prefix . 'qrrs_variant_recipes';
    $item_t = $wpdb->prefix . 'qrrs_inventory_items';
    $menu_t = $wpdb->prefix . 'qrrs_items';
    $action = sanitize_key( $_POST['qrrs_inventory_action'] ?? '' );

    if ( ! isset( $_POST['qrrs_inventory_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_inventory_nonce'], 'qrrs_inventory_action' ) ) {
        wp_die( 'Security check failed' );
    }
    if ( ! $can_manage ) {
        qrrs_variant_redirect( 'error' );
    }

    if ( 'variant_add_line' === $action ) {
        $menu_id = intval( $_POST['menu_item_id'] ?? 0 );
        $variant = sanitize_text_field( wp_unslash( $_POST['variant_name'] ?? '' ) );
        $item_id = intval( $_POST['inventory_item_id'] ?? 0 );
        $qty     = floatval( $_POST['quantity'] ?? 0 );
        $waste   = floatval( $_POST['wastage_percent'] ?? 0 );

        $menu = $wpdb->get_row( $wpdb->prepare( "SELECT id, variants_json FROM $menu_t WHERE id = %d AND restaurant_id = %d", $menu_id, $restaurant_id ) );
        $allowed = $menu ? (array) json_decode( (string) $menu->variants_json, true ) : [];
        if ( ! $menu || '' === $variant || ! in_array( $variant, $allowed, true ) ) {
            qrrs_variant_redirect( 'variant_invalid' );
        }
        $inv = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, unit_id FROM $item_t WHERE id = %d AND restaurant_id = %d AND status = 'active'", $item_id, $restaurant_id
        ) );
        if ( ! $inv ) {
            qrrs_variant_redirect( 'variant_item_invalid' );
        }
        if ( $qty <= 0 || $waste < 0 || $waste > 100 ) {
            qrrs_variant_redirect( 'variant_qty_invalid' );
        }
        $wpdb->insert( $vr_t, [
            'restaurant_id'      => $restaurant_id,
            'menu_item_id'       => $menu_id,
            'variant_name'       => $variant,
            'inventory_item_id'  => $item_id,
            'quantity_required'  => round( $qty, 4 ),
            'unit_id'            => intval( $inv->unit_id ),
            'wastage_percent'    => round( $waste, 2 ),
            'created_at'         => current_time( 'mysql' ),
        ] );
        qrrs_variant_redirect( 'variant_saved' );
    }

    if ( 'variant_delete_line' === $action ) {
        $line_id = intval( $_POST['line_id'] ?? 0 );
        $wpdb->delete( $vr_t, [ 'id' => $line_id, 'restaurant_id' => $restaurant_id ] );
        qrrs_variant_redirect( 'variant_deleted' );
    }
}

function qrrs_variant_render( $restaurant_id, $can_manage ) {
    global $wpdb;
    $vr_t   = $wpdb->prefix . 'qrrs_variant_recipes';
    $item_t = $wpdb->prefix . 'qrrs_inventory_items';
    $unit_t = $wpdb->prefix . 'qrrs_inventory_units';
    $menu_t = $wpdb->prefix . 'qrrs_items';

    if ( ! $can_manage ) {
        echo '<div class="qrrs-card" style="padding:20px;">Variant recipes are for managers and admins only.</div>';
        return;
    }

    $msgs = [
        'variant_saved'        => [ 'ok',  'Variant ingredient added.' ],
        'variant_deleted'      => [ 'ok',  'Ingredient removed.' ],
        'variant_invalid'      => [ 'err', 'Choose a menu item and one of its variants.' ],
        'variant_item_invalid' => [ 'err', 'Choose an active raw material.' ],
        'variant_qty_invalid'  => [ 'err', 'Quantity must be above 0 and wastage between 0 and 100.' ],
        'error'                => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    $st = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
    if ( isset( $msgs[ $st ] ) ) {
        [ $kind, $text ] = $msgs[ $st ];
        $bg = 'ok' === $kind ? '#dcfce7' : '#fee2e2';
        $fg = 'ok' === $kind ? '#166534' : '#991b1b';
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:10px 14px; border-radius:6px; margin-bottom:12px;">' . esc_html( $text ) . '</div>';
    }

    $base  = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'variant-recipes' ], home_url( '/restaurant-dashboard/' ) );
    $menus = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, item_name, variants_json FROM $menu_t WHERE restaurant_id = %d ORDER BY item_name ASC", $restaurant_id
    ) );
    $with_variants = array_filter( $menus, fn( $m ) => ! empty( json_decode( (string) $m->variants_json, true ) ) );

    $sel = intval( $_GET['vm'] ?? 0 );
    if ( ! $sel && $with_variants ) {
        $sel = (int) reset( $with_variants )->id;
    }
    $sel_menu = null;
    foreach ( $with_variants as $m ) { if ( (int) $m->id === $sel ) { $sel_menu = $m; } }
    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT i.id, i.item_name, u.unit_code FROM $item_t i LEFT JOIN $unit_t u ON u.id = i.unit_id
         WHERE i.restaurant_id = %d AND i.status = 'active' ORDER BY i.item_name ASC", $restaurant_id
    ) );
    ?>
    <div class="qrrs-card" style="padding:16px 20px; margin-bottom:20px;">
        <p style="margin:0 0 12px; color:#64748b; font-size:13px;">Add-on ingredients for variants (like Extra Cheese or Spicy). They are added on top of the base recipe when the customer picks that variant. Half and Full sizes should be separate menu items.</p>
        <?php if ( empty( $with_variants ) ) : ?>
            <p style="color:#94a3b8;">No menu item has variants yet. Add variants in the Menu → Items form first.</p>
        <?php else : ?>
        <form method="get" style="margin:0;">
            <input type="hidden" name="tab" value="inventory">
            <input type="hidden" name="inv_section" value="variant-recipes">
            <label>Menu item
                <select name="vm" onchange="this.form.submit()" style="padding:6px 10px; min-width:240px;">
                    <?php foreach ( $with_variants as $m ) : ?>
                        <option value="<?php echo intval( $m->id ); ?>" <?php selected( $sel, (int) $m->id ); ?>><?php echo esc_html( $m->item_name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php endif; ?>
    </div>

    <?php if ( $sel_menu ) :
        $variants = (array) json_decode( (string) $sel_menu->variants_json, true );
        $lines = $wpdb->get_results( $wpdb->prepare(
            "SELECT vr.*, i.item_name, u.unit_code FROM $vr_t vr
             JOIN $item_t i ON i.id = vr.inventory_item_id
             LEFT JOIN $unit_t u ON u.id = vr.unit_id
             WHERE vr.restaurant_id = %d AND vr.menu_item_id = %d
             ORDER BY vr.variant_name ASC, vr.id ASC",
            $restaurant_id, $sel_menu->id
        ) );
        $fmt = function ( $n ) { return rtrim( rtrim( number_format( (float) $n, 3, '.', '' ), '0' ), '.' ); };
    ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">Add ingredient to a variant</h3>
        <form method="post" action="<?php echo esc_url( $base ); ?>">
            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
            <input type="hidden" name="qrrs_inventory_action" value="variant_add_line">
            <input type="hidden" name="menu_item_id" value="<?php echo intval( $sel_menu->id ); ?>">
            <div class="qrrs-form-grid">
                <div class="qrrs-input-group">
                    <label>Variant *</label>
                    <select name="variant_name" required>
                        <?php foreach ( $variants as $v ) : ?>
                            <option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-input-group">
                    <label>Raw material *</label>
                    <select name="inventory_item_id" required>
                        <option value="">Select item</option>
                        <?php foreach ( $items as $it ) : ?>
                            <option value="<?php echo intval( $it->id ); ?>"><?php echo esc_html( $it->item_name . ' (' . $it->unit_code . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-input-group">
                    <label>Quantity per portion *</label>
                    <input type="number" step="0.0001" min="0.0001" name="quantity" required>
                </div>
                <div class="qrrs-input-group">
                    <label>Wastage %</label>
                    <input type="number" step="0.01" min="0" max="100" name="wastage_percent" value="0">
                </div>
            </div>
            <button type="submit" class="qrrs-btn-save" style="margin-top:10px;">Add ingredient</button>
        </form>
    </div>

    <div class="qrrs-card" style="padding:20px;">
        <h3 style="margin-top:0;">Variant ingredients: <?php echo esc_html( $sel_menu->item_name ); ?></h3>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th>Variant</th><th>Ingredient</th><th>Quantity</th><th>Wastage</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $lines as $ln ) : ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td><?php echo esc_html( $ln->variant_name ); ?></td>
                    <td><?php echo esc_html( $ln->item_name ); ?></td>
                    <td><?php echo esc_html( $fmt( $ln->quantity_required ) . ' ' . $ln->unit_code ); ?></td>
                    <td><?php echo esc_html( $fmt( $ln->wastage_percent ) ); ?>%</td>
                    <td>
                        <form method="post" action="<?php echo esc_url( $base ); ?>" onsubmit="return confirm('Remove this ingredient?');" style="margin:0;">
                            <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                            <input type="hidden" name="qrrs_inventory_action" value="variant_delete_line">
                            <input type="hidden" name="menu_item_id" value="<?php echo intval( $sel_menu->id ); ?>">
                            <input type="hidden" name="line_id" value="<?php echo intval( $ln->id ); ?>">
                            <button type="submit" class="button" style="color:#dc2626;">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $lines ) ) : ?><tr><td colspan="5" style="text-align:center; padding:16px; color:#94a3b8;">No variant ingredients yet. The base recipe is used for every order.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif;
}