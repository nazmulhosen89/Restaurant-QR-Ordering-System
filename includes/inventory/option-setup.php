<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Menu option setup (admin / super admin).
 *  - Size group: pick one value. Each value has a FULL price and a recipe factor
 *    (Quarter 0.25, Half 0.5, Full 1; drinks 250ml 0.5, 500ml 1, 1L 2).
 *  - Add-on group: pick many. Each value adds an extra price.
 *  - One menu item can have several groups.
 */

function qrrs_option_redirect( $status, $menu_id = 0 ) {
    wp_safe_redirect( add_query_arg( [
        'tab'         => 'inventory',
        'inv_section' => 'menu-options',
        'status'      => $status,
        'om'          => intval( $menu_id ),
    ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

/** Active groups with their active values for one menu item (used by Take Order later). */
function qrrs_option_groups_for_item( $restaurant_id, $menu_item_id ) {
    global $wpdb;
    $g_t = $wpdb->prefix . 'qrrs_menu_option_groups';
    $v_t = $wpdb->prefix . 'qrrs_menu_option_values';
    $groups = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $g_t WHERE restaurant_id = %d AND menu_item_id = %d AND status = 'active' ORDER BY sort_order ASC, id ASC",
        $restaurant_id, $menu_item_id
    ) );
    foreach ( $groups as $g ) {
        $g->values = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $v_t WHERE group_id = %d AND status = 'active' ORDER BY sort_order ASC, id ASC", $g->id
        ) );
    }
    return $groups;
}

function qrrs_option_handle_actions( $restaurant_id, $is_admin ) {
    global $wpdb;
    $g_t    = $wpdb->prefix . 'qrrs_menu_option_groups';
    $v_t    = $wpdb->prefix . 'qrrs_menu_option_values';
    $menu_t = $wpdb->prefix . 'qrrs_items';
    $sl_t   = $wpdb->prefix . 'qrrs_order_items';
    $action = sanitize_key( $_POST['qrrs_inventory_action'] ?? '' );

    if ( ! isset( $_POST['qrrs_inventory_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_inventory_nonce'], 'qrrs_inventory_action' ) ) {
        wp_die( 'Security check failed' );
    }
    if ( ! $is_admin ) {
        qrrs_option_redirect( 'error' );
    }
    $menu_id = intval( $_POST['menu_item_id'] ?? 0 );
    $menu = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $menu_t WHERE id = %d AND restaurant_id = %d", $menu_id, $restaurant_id ) );
    if ( ! $menu ) {
        qrrs_option_redirect( 'option_menu_invalid' );
    }

    if ( 'opt_add_group' === $action ) {
        $name = sanitize_text_field( wp_unslash( $_POST['group_name'] ?? '' ) );
        $type = 'addon' === ( $_POST['group_type'] ?? '' ) ? 'addon' : 'size';
        if ( '' === trim( $name ) ) {
            qrrs_option_redirect( 'option_name_required', $menu_id );
        }
        $wpdb->insert( $g_t, [
            'restaurant_id' => $restaurant_id,
            'menu_item_id'  => $menu_id,
            'group_name'    => $name,
            'group_type'    => $type,
            'is_required'   => ( 'size' === $type && ! empty( $_POST['is_required'] ) ) ? 1 : 0,
            'sort_order'    => intval( $_POST['sort_order'] ?? 0 ),
            'status'        => 'active',
        ] );
        qrrs_option_redirect( 'option_group_saved', $menu_id );
    }

    if ( 'opt_add_value' === $action ) {
        $group_id = intval( $_POST['group_id'] ?? 0 );
        $group = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, group_type FROM $g_t WHERE id = %d AND restaurant_id = %d AND menu_item_id = %d", $group_id, $restaurant_id, $menu_id
        ) );
        if ( ! $group ) {
            qrrs_option_redirect( 'option_group_invalid', $menu_id );
        }
        $name  = sanitize_text_field( wp_unslash( $_POST['value_name'] ?? '' ) );
        $price = floatval( $_POST['price'] ?? 0 );
        $factor = floatval( $_POST['recipe_factor'] ?? 1 );
        if ( '' === trim( $name ) ) {
            qrrs_option_redirect( 'option_name_required', $menu_id );
        }
        if ( $price < 0 ) {
            qrrs_option_redirect( 'option_price_invalid', $menu_id );
        }
        if ( 'size' === $group->group_type && ( $factor <= 0 || $factor > 100 ) ) {
            qrrs_option_redirect( 'option_factor_invalid', $menu_id );
        }
        if ( 'addon' === $group->group_type ) {
            $factor = 1;
        }
        $wpdb->insert( $v_t, [
            'restaurant_id' => $restaurant_id,
            'group_id'      => $group_id,
            'menu_item_id'  => $menu_id,
            'value_name'    => $name,
            'price'         => round( $price, 2 ),
            'recipe_factor' => round( $factor, 4 ),
            'sort_order'    => intval( $_POST['sort_order'] ?? 0 ),
            'status'        => 'active',
        ] );
        qrrs_option_redirect( 'option_value_saved', $menu_id );
    }

    if ( 'opt_delete_value' === $action ) {
        $vid = intval( $_POST['value_id'] ?? 0 );
        $used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sl_t WHERE FIND_IN_SET( %d, size_value_ids ) > 0", $vid ) );
        if ( $used > 0 ) {
            // Already used in past orders: hide it instead of deleting, so history stays correct
            $wpdb->update( $v_t, [ 'status' => 'inactive' ], [ 'id' => $vid, 'restaurant_id' => $restaurant_id ] );
            qrrs_option_redirect( 'option_value_hidden', $menu_id );
        }
        $wpdb->delete( $v_t, [ 'id' => $vid, 'restaurant_id' => $restaurant_id ] );
        qrrs_option_redirect( 'option_value_deleted', $menu_id );
    }

    if ( 'opt_delete_group' === $action ) {
        $gid = intval( $_POST['group_id'] ?? 0 );
        $wpdb->update( $g_t, [ 'status' => 'inactive' ], [ 'id' => $gid, 'restaurant_id' => $restaurant_id, 'menu_item_id' => $menu_id ] );
        qrrs_option_redirect( 'option_group_hidden', $menu_id );
    }
}

function qrrs_option_render( $restaurant_id, $is_admin ) {
    global $wpdb;
    $menu_t = $wpdb->prefix . 'qrrs_items';
    $cat_t  = $wpdb->prefix . 'qrrs_categories';

    if ( ! $is_admin ) {
        echo '<div class="qrrs-card" style="padding:20px;">Menu options are managed by admins only.</div>';
        return;
    }

    $msgs = [
        'option_group_saved'   => [ 'ok',  'Option group added.' ],
        'option_value_saved'   => [ 'ok',  'Option value added.' ],
        'option_value_deleted' => [ 'ok',  'Option value deleted.' ],
        'option_value_hidden'  => [ 'ok',  'Option value is used in past orders, so it was hidden instead of deleted.' ],
        'option_group_hidden'  => [ 'ok',  'Option group hidden.' ],
        'option_menu_invalid'  => [ 'err', 'Please choose a menu item.' ],
        'option_name_required' => [ 'err', 'Name is required.' ],
        'option_group_invalid' => [ 'err', 'That group does not belong to this menu item.' ],
        'option_price_invalid' => [ 'err', 'Price must be 0 or more.' ],
        'option_factor_invalid'=> [ 'err', 'Recipe factor must be above 0 (Half = 0.5, Full = 1).' ],
        'error'                => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    $st = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
    if ( isset( $msgs[ $st ] ) ) {
        [ $kind, $text ] = $msgs[ $st ];
        $bg = 'ok' === $kind ? '#dcfce7' : '#fee2e2';
        $fg = 'ok' === $kind ? '#166534' : '#991b1b';
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:10px 14px; border-radius:6px; margin-bottom:12px;">' . esc_html( $text ) . '</div>';
    }

    $base  = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'menu-options' ], home_url( '/restaurant-dashboard/' ) );
    $menus = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, item_name, price FROM $menu_t WHERE restaurant_id = %d ORDER BY item_name ASC", $restaurant_id
    ) );
    $sel = intval( $_GET['om'] ?? 0 );
    if ( ! $sel && $menus ) { $sel = (int) $menus[0]->id; }
    $sel_menu = null;
    foreach ( $menus as $m ) { if ( (int) $m->id === $sel ) { $sel_menu = $m; } }
    ?>
    <div class="qrrs-card" style="padding:16px 20px; margin-bottom:20px;">
        <form method="get" style="margin:0;">
            <input type="hidden" name="tab" value="inventory">
            <input type="hidden" name="inv_section" value="menu-options">
            <label>Menu item
                <select name="om" onchange="this.form.submit()" style="padding:6px 10px; min-width:260px;">
                    <?php foreach ( $menus as $m ) : ?>
                        <option value="<?php echo intval( $m->id ); ?>" <?php selected( $sel, (int) $m->id ); ?>><?php echo esc_html( $m->item_name ); ?> (base ৳<?php echo esc_html( number_format( (float) $m->price, 2 ) ); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <p style="margin:10px 0 0; color:#64748b; font-size:13px;">Size groups (pick one): Quarter / Half / Full, or 250ml / 500ml / 1L. Each size has its full price and a recipe factor. Add-on groups (pick many) add an extra price.</p>
    </div>

    <?php if ( $sel_menu ) :
        $groups = qrrs_option_groups_for_item( $restaurant_id, $sel_menu->id );
        ?>
        <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
            <h3 style="margin-top:0;">Add option group</h3>
            <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                <input type="hidden" name="qrrs_inventory_action" value="opt_add_group">
                <input type="hidden" name="menu_item_id" value="<?php echo intval( $sel_menu->id ); ?>">
                <input type="text" name="group_name" placeholder="e.g. Size or Extra" required style="min-width:200px; padding:6px 10px;">
                <select name="group_type" style="padding:6px 10px;">
                    <option value="size">Size (pick one)</option>
                    <option value="addon">Add-on (pick many)</option>
                </select>
                <label style="font-size:13px;"><input type="checkbox" name="is_required" value="1" checked> Required (size)</label>
                <button type="submit" class="qrrs-btn-save">Add group</button>
            </form>
        </div>

        <?php if ( empty( $groups ) ) : ?>
            <div class="qrrs-card" style="padding:20px;"><p style="color:#94a3b8; margin:0;">No option groups yet. The base price of this item is used as it is.</p></div>
        <?php endif; ?>

        <?php foreach ( $groups as $g ) : ?>
        <div class="qrrs-card" style="padding:20px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <h3 style="margin:0;"><?php echo esc_html( $g->group_name ); ?>
                    <span style="font-size:12px; font-weight:normal; color:#64748b;">
                        · <?php echo 'size' === $g->group_type ? 'Size · pick one' . ( $g->is_required ? ' · required' : '' ) : 'Add-on · pick many'; ?>
                    </span>
                </h3>
                <form method="post" action="<?php echo esc_url( $base ); ?>" onsubmit="return confirm('Hide this group? Past orders are not changed.');" style="margin:0;">
                    <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                    <input type="hidden" name="qrrs_inventory_action" value="opt_delete_group">
                    <input type="hidden" name="menu_item_id" value="<?php echo intval( $sel_menu->id ); ?>">
                    <input type="hidden" name="group_id" value="<?php echo intval( $g->id ); ?>">
                    <button type="submit" class="button" style="color:#dc2626;">Hide group</button>
                </form>
            </div>

            <table class="qrrs-table" style="width:100%; border-collapse:collapse; margin-top:12px;">
                <thead><tr style="background:#f1f5f9; text-align:left;">
                    <th>Option</th>
                    <th>Price (৳)<?php echo 'size' === $g->group_type ? ' <small>(full price)</small>' : ' <small>(extra)</small>'; ?></th>
                    <?php if ( 'size' === $g->group_type ) : ?><th>Recipe factor</th><?php endif; ?>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $g->values as $v ) : ?>
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td><?php echo esc_html( $v->value_name ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $v->price, 2 ) ); ?></td>
                        <?php if ( 'size' === $g->group_type ) : ?><td><?php echo esc_html( rtrim( rtrim( number_format( (float) $v->recipe_factor, 4, '.', '' ), '0' ), '.' ) ); ?></td><?php endif; ?>
                        <td>
                            <form method="post" action="<?php echo esc_url( $base ); ?>" onsubmit="return confirm('Remove this option?');" style="margin:0;">
                                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                                <input type="hidden" name="qrrs_inventory_action" value="opt_delete_value">
                                <input type="hidden" name="menu_item_id" value="<?php echo intval( $sel_menu->id ); ?>">
                                <input type="hidden" name="value_id" value="<?php echo intval( $v->id ); ?>">
                                <button type="submit" class="button" style="color:#dc2626;">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $g->values ) ) : ?>
                    <tr><td colspan="4" style="text-align:center; padding:12px; color:#94a3b8;">No options yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:12px;">
                <?php wp_nonce_field( 'qrrs_inventory_action', 'qrrs_inventory_nonce' ); ?>
                <input type="hidden" name="qrrs_inventory_action" value="opt_add_value">
                <input type="hidden" name="menu_item_id" value="<?php echo intval( $sel_menu->id ); ?>">
                <input type="hidden" name="group_id" value="<?php echo intval( $g->id ); ?>">
                <input type="text" name="value_name" placeholder="<?php echo 'size' === $g->group_type ? 'e.g. Half' : 'e.g. Extra Cheese'; ?>" required style="min-width:180px; padding:6px 10px;">
                <input type="number" name="price" step="0.01" min="0" placeholder="<?php echo 'size' === $g->group_type ? 'Full price' : 'Extra price'; ?>" required style="width:150px; padding:6px 10px;">
                <?php if ( 'size' === $g->group_type ) : ?>
                    <input type="number" name="recipe_factor" step="0.0001" min="0.0001" value="1" required style="width:130px; padding:6px 10px;" title="Half = 0.5, Full = 1">
                <?php endif; ?>
                <button type="submit" class="button">+ Add option</button>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif;
}

/**
 * Public AJAX: option groups of one menu item (used by Take Order).
 * Returns only active groups and values. Legacy variants_json add-ons are added by the page itself.
 */
function qrrs_ajax_get_item_options() {
    global $wpdb;
    $rid     = intval( $_REQUEST['restaurant_id'] ?? 0 );
    $item_id = intval( $_REQUEST['item_id'] ?? 0 );
    $menu = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}qrrs_items WHERE id = %d AND restaurant_id = %d", $item_id, $rid
    ) );
    if ( ! $menu ) {
        wp_send_json_error( 'Item not found.' );
    }
    $groups = qrrs_option_groups_for_item( $rid, $item_id );
    $out = [];
    foreach ( $groups as $g ) {
        $out[] = [
            'id'       => (int) $g->id,
            'name'     => $g->group_name,
            'type'     => $g->group_type,
            'required' => (int) $g->is_required,
            'values'   => array_map( fn( $v ) => [
                'id'    => (int) $v->id,
                'name'  => $v->value_name,
                'price' => (float) $v->price,
            ], $g->values ),
        ];
    }
    wp_send_json_success( [ 'groups' => $out ] );
}
add_action( 'wp_ajax_qrrs_get_item_options', 'qrrs_ajax_get_item_options' );
add_action( 'wp_ajax_nopriv_qrrs_get_item_options', 'qrrs_ajax_get_item_options' );

/**
 * Server-side check of every cart line. Prices are recalculated here, never taken from the browser.
 *  - size values: must be active values of an active size group of this item; one per group;
 *    every required group must have one. Unit price = sum of the chosen size values' prices.
 *  - add-ons: names must be active add-on values of this item (or legacy variants). Extra price added.
 *  - no required size group: the menu item's price is the base; optional sizes are added to it.
 * Returns [ 'lines' => [...], 'subtotal' => float ] or a WP_Error-like string message.
 */
function qrrs_validate_order_items( $restaurant_id, array $items ) {
    global $wpdb;
    $menu_t = $wpdb->prefix . 'qrrs_items';
    $g_t    = $wpdb->prefix . 'qrrs_menu_option_groups';
    $v_t    = $wpdb->prefix . 'qrrs_menu_option_values';

    $lines    = [];
    $subtotal = 0.0;

    foreach ( $items as $it ) {
        $item_id = intval( $it['id'] ?? 0 );
        $qty     = intval( $it['qty'] ?? 0 );
        if ( $item_id <= 0 || $qty <= 0 || $qty > 999 ) {
            return 'Invalid item quantity.';
        }
        $menu = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, item_name, price, variants_json FROM $menu_t WHERE id = %d AND restaurant_id = %d",
            $item_id, $restaurant_id
        ) );
        if ( ! $menu ) {
            return 'An item in your cart is no longer available.';
        }

        // ---- size values ----
        $size_ids_raw = array_filter( array_map( 'intval', explode( ',', (string) ( $it['size_value_ids'] ?? '' ) ) ) );
        $size_ids = [];
        $size_price = 0.0;
        $size_names = [];
        $seen_groups = [];
        foreach ( $size_ids_raw as $vid ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT v.id, v.value_name, v.price, v.group_id
                 FROM $v_t v JOIN $g_t g ON g.id = v.group_id
                 WHERE v.id = %d AND v.menu_item_id = %d AND v.restaurant_id = %d
                   AND v.status = 'active' AND g.status = 'active' AND g.group_type = 'size'",
                $vid, $item_id, $restaurant_id
            ) );
            if ( ! $row ) {
                return 'A size option is no longer available. Please refresh the page.';
            }
            if ( isset( $seen_groups[ $row->group_id ] ) ) {
                return 'Choose only one option per size group.';
            }
            $seen_groups[ $row->group_id ] = true;
            $size_ids[]   = (int) $row->id;
            $size_names[] = $row->value_name;
            $size_price  += (float) $row->price;
        }
        $required_groups = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $g_t WHERE menu_item_id = %d AND restaurant_id = %d AND status = 'active' AND group_type = 'size' AND is_required = 1",
            $item_id, $restaurant_id
        ) );
        foreach ( $required_groups as $gid ) {
            if ( ! isset( $seen_groups[ (int) $gid ] ) ) {
                return 'Please choose a size for ' . $menu->item_name . '.';
            }
        }
        $has_size_groups = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $g_t WHERE menu_item_id = %d AND restaurant_id = %d AND status = 'active' AND group_type = 'size'",
            $item_id, $restaurant_id
        ) ) > 0;

        // ---- add-ons ----
        $legacy = (array) json_decode( (string) $menu->variants_json, true );
        $addon_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT v.value_name, v.price FROM $v_t v JOIN $g_t g ON g.id = v.group_id
             WHERE v.menu_item_id = %d AND v.restaurant_id = %d AND v.status = 'active'
               AND g.status = 'active' AND g.group_type = 'addon'",
            $item_id, $restaurant_id
        ) );
        $addon_price_map = [];
        foreach ( $addon_rows as $ar ) { $addon_price_map[ $ar->value_name ] = (float) $ar->price; }
        foreach ( $legacy as $ln ) { if ( ! isset( $addon_price_map[ $ln ] ) ) { $addon_price_map[ $ln ] = 0.0; } }

        $chosen_addons = [];
        $addon_price   = 0.0;
        $addon_text    = (string) ( $it['variants_selected'] ?? '' );
        foreach ( array_unique( array_filter( array_map( 'trim', explode( ',', $addon_text ) ) ) ) as $an ) {
            if ( ! array_key_exists( $an, $addon_price_map ) ) {
                return 'An add-on for ' . $menu->item_name . ' is no longer available.';
            }
            $chosen_addons[] = $an;
            $addon_price    += $addon_price_map[ $an ];
        }

        // A required size group replaces the base price. Optional sizes are added on top of it.
        $base = ! empty( $required_groups ) ? 0.0 : (float) $menu->price;
        $unit = round( $base + $size_price + $addon_price, 2 );

        $label = implode( ' + ', $size_names );
        $name  = $menu->item_name . ( $label ? ' (' . $label . ')' : '' );

        $lines[] = [
            'id'               => $item_id,
            'item_id'          => $item_id,
            'name'             => mb_substr( $name, 0, 100 ),
            'qty'              => $qty,
            'price'            => $unit,
            'variants_selected'=> implode( ', ', $chosen_addons ),
            'size_value_ids'   => implode( ',', $size_ids ),
            'size_name'        => mb_substr( $label, 0, 255 ),
        ];
        $subtotal += $unit * $qty;
    }

    return [ 'lines' => $lines, 'subtotal' => round( $subtotal, 2 ) ];
}