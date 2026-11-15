<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function qrrs_inventory_get_active_restaurant_id() {
    if ( current_user_can( 'administrator' ) ) {
        if ( ! session_id() ) {
            session_start();
        }
        return isset( $_SESSION['qrrs_active_res_id'] ) ? intval( $_SESSION['qrrs_active_res_id'] ) : 0;
    }

    $user_id = get_current_user_id();
    $restaurant_id = get_user_meta( $user_id, 'assigned_restaurant', true );

    if ( ! $restaurant_id ) {
        $restaurant_id = get_user_meta( $user_id, 'qrrs_restaurant_id', true );
    }

    return intval( $restaurant_id );
}

function qrrs_inventory_user_department() {
    $user = wp_get_current_user();
    $roles = (array) $user->roles;

    if ( in_array( 'qr_kitchen', $roles, true ) ) {
        return 'kitchen';
    }

    if ( in_array( 'qr_waiter', $roles, true ) ) {
        return 'waiter';
    }

    if ( in_array( 'administrator', $roles, true ) ) {
        return 'admin';
    }

    return 'manager';
}

function qrrs_inventory_can_manage() {
    return current_user_can( 'administrator' ) || current_user_can( 'qr_manager' );
}

function qrrs_inventory_can_request() {
    return is_user_logged_in();
}

function qrrs_inventory_get_units() {
    global $wpdb;
    return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}qrrs_inventory_units WHERE status = 'active' ORDER BY unit_name ASC" );
}

function qrrs_inventory_get_categories( $restaurant_id ) {
    global $wpdb;
    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}qrrs_inventory_categories WHERE restaurant_id = %d AND status = 'active' ORDER BY category_name ASC",
            $restaurant_id
        )
    );
}

function qrrs_inventory_get_items( $restaurant_id, $active_only = true ) {
    global $wpdb;
    $where_status = $active_only ? "AND i.status = 'active'" : '';

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT i.*, c.category_name, u.unit_name, u.unit_code
             FROM {$wpdb->prefix}qrrs_inventory_items i
             LEFT JOIN {$wpdb->prefix}qrrs_inventory_categories c ON i.category_id = c.id
             LEFT JOIN {$wpdb->prefix}qrrs_inventory_units u ON i.unit_id = u.id
             WHERE i.restaurant_id = %d {$where_status}
             ORDER BY i.item_name ASC",
            $restaurant_id
        )
    );
}

function qrrs_inventory_add_stock_movement( $args ) {
    global $wpdb;

    $defaults = [
        'restaurant_id'      => 0,
        'inventory_item_id'  => 0,
        'movement_type'      => 'adjustment',
        'quantity_in'        => 0,
        'quantity_out'       => 0,
        'unit_cost'          => 0,
        'reference_type'     => '',
        'reference_id'       => 0,
        'note'               => '',
        'created_by'         => get_current_user_id(),
    ];

    $args = wp_parse_args( $args, $defaults );

    $restaurant_id = intval( $args['restaurant_id'] );
    $item_id       = intval( $args['inventory_item_id'] );
    $qty_in        = max( 0, floatval( $args['quantity_in'] ) );
    $qty_out       = max( 0, floatval( $args['quantity_out'] ) );
    $unit_cost     = max( 0, floatval( $args['unit_cost'] ) );

    if ( ! $restaurant_id || ! $item_id || ( $qty_in <= 0 && $qty_out <= 0 ) ) {
        return false;
    }

    if ( $qty_out > 0 ) {
        $current_stock = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT current_stock FROM {$wpdb->prefix}qrrs_inventory_items WHERE id = %d AND restaurant_id = %d",
                $item_id,
                $restaurant_id
            )
        );

        if ( null === $current_stock || floatval( $current_stock ) < $qty_out ) {
            return false;
        }
    }

    $total_cost = $unit_cost * max( $qty_in, $qty_out );

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'qrrs_stock_movements',
        [
            'restaurant_id'     => $restaurant_id,
            'inventory_item_id' => $item_id,
            'movement_type'     => sanitize_key( $args['movement_type'] ),
            'quantity_in'       => $qty_in,
            'quantity_out'      => $qty_out,
            'unit_cost'         => $unit_cost,
            'total_cost'        => $total_cost,
            'reference_type'    => sanitize_key( $args['reference_type'] ),
            'reference_id'      => intval( $args['reference_id'] ),
            'note'              => sanitize_textarea_field( $args['note'] ),
            'created_by'        => intval( $args['created_by'] ),
            'created_at'        => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%s', '%f', '%f', '%f', '%f', '%s', '%d', '%s', '%d', '%s' ]
    );

    if ( ! $inserted ) {
        return false;
    }

    $delta = $qty_in - $qty_out;
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}qrrs_inventory_items
             SET current_stock = current_stock + %f, updated_at = %s
             WHERE id = %d AND restaurant_id = %d",
            $delta,
            current_time( 'mysql' ),
            $item_id,
            $restaurant_id
        )
    );

    if ( $qty_in > 0 && $unit_cost > 0 ) {
        $wpdb->update(
            $wpdb->prefix . 'qrrs_inventory_items',
            [ 'cost_per_unit' => $unit_cost ],
            [ 'id' => $item_id, 'restaurant_id' => $restaurant_id ],
            [ '%f' ],
            [ '%d', '%d' ]
        );
    }

    return $wpdb->insert_id;
}

function qrrs_inventory_save_menu_item_recipe( $restaurant_id, $menu_item_id, $ingredient_ids, $quantities, $unit_ids, $wastage_percents ) {
    global $wpdb;

    $restaurant_id = intval( $restaurant_id );
    $menu_item_id  = intval( $menu_item_id );

    if ( ! $restaurant_id || ! $menu_item_id || ! qrrs_inventory_can_manage() ) {
        return false;
    }

    $recipe_table = $wpdb->prefix . 'qrrs_recipes';
    $items_table  = $wpdb->prefix . 'qrrs_recipe_items';

    $recipe_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $recipe_table WHERE restaurant_id = %d AND menu_item_id = %d",
            $restaurant_id,
            $menu_item_id
        )
    );

    if ( $recipe_id ) {
        $wpdb->update(
            $recipe_table,
            [ 'updated_at' => current_time( 'mysql' ), 'status' => 'active' ],
            [ 'id' => $recipe_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        $wpdb->delete( $items_table, [ 'recipe_id' => $recipe_id ], [ '%d' ] );
    } else {
        $wpdb->insert(
            $recipe_table,
            [
                'restaurant_id' => $restaurant_id,
                'menu_item_id'  => $menu_item_id,
                'recipe_name'   => '',
                'serving_qty'   => 1,
                'status'        => 'active',
                'created_by'    => get_current_user_id(),
                'created_at'    => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%f', '%s', '%d', '%s', '%s' ]
        );
        $recipe_id = $wpdb->insert_id;
    }

    if ( ! $recipe_id || ! is_array( $ingredient_ids ) ) {
        return false;
    }

    foreach ( $ingredient_ids as $index => $ingredient_id ) {
        $ingredient_id = intval( $ingredient_id );
        $quantity      = isset( $quantities[ $index ] ) ? floatval( $quantities[ $index ] ) : 0;
        $unit_id       = isset( $unit_ids[ $index ] ) ? intval( $unit_ids[ $index ] ) : 0;
        $wastage       = isset( $wastage_percents[ $index ] ) ? floatval( $wastage_percents[ $index ] ) : 0;

        if ( ! $ingredient_id || $quantity <= 0 || ! $unit_id ) {
            continue;
        }

        $cost = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT cost_per_unit FROM {$wpdb->prefix}qrrs_inventory_items WHERE id = %d AND restaurant_id = %d",
                $ingredient_id,
                $restaurant_id
            )
        );

        $wpdb->insert(
            $items_table,
            [
                'recipe_id'            => $recipe_id,
                'inventory_item_id'    => $ingredient_id,
                'quantity_required'    => $quantity,
                'unit_id'              => $unit_id,
                'wastage_percent'      => max( 0, $wastage ),
                'cost_snapshot'        => floatval( $cost ),
            ],
            [ '%d', '%d', '%f', '%d', '%f', '%f' ]
        );
    }

    return true;
}

function qrrs_inventory_get_recipe_items( $restaurant_id, $menu_item_id ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ri.*, inv.item_name, u.unit_name, u.unit_code
             FROM {$wpdb->prefix}qrrs_recipes r
             INNER JOIN {$wpdb->prefix}qrrs_recipe_items ri ON r.id = ri.recipe_id
             LEFT JOIN {$wpdb->prefix}qrrs_inventory_items inv ON ri.inventory_item_id = inv.id
             LEFT JOIN {$wpdb->prefix}qrrs_inventory_units u ON ri.unit_id = u.id
             WHERE r.restaurant_id = %d AND r.menu_item_id = %d
             ORDER BY ri.id ASC",
            $restaurant_id,
            $menu_item_id
        )
    );
}

function qrrs_inventory_render_requisition_panel( $forced_department = '' ) {
    if ( ! is_user_logged_in() ) {
        return;
    }

    global $wpdb;

    $restaurant_id = qrrs_inventory_get_active_restaurant_id();
    if ( ! $restaurant_id ) {
        $restaurant_id = get_user_meta( get_current_user_id(), 'assigned_restaurant', true );
    }
    if ( ! $restaurant_id ) {
        $restaurant_id = get_user_meta( get_current_user_id(), 'restaurant_id', true );
    }
    if ( ! $restaurant_id ) {
        $restaurant_id = get_user_meta( get_current_user_id(), 'qrrs_restaurant_id', true );
    }

    $restaurant_id = intval( $restaurant_id );
    if ( ! $restaurant_id ) {
        echo '<div style="padding:25px; background:#fff; border-radius:10px;">No restaurant assigned for requisition.</div>';
        return;
    }

    $req_table      = $wpdb->prefix . 'qrrs_requisitions';
    $req_item_table = $wpdb->prefix . 'qrrs_requisition_items';
    $item_table     = $wpdb->prefix . 'qrrs_inventory_items';
    $unit_table     = $wpdb->prefix . 'qrrs_inventory_units';

    if ( isset( $_POST['qrrs_staff_requisition_action'] ) ) {
        if ( ! isset( $_POST['qrrs_staff_requisition_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_staff_requisition_nonce'], 'qrrs_staff_requisition_action' ) ) {
            wp_die( 'Security check failed' );
        }

        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        $department = $forced_department ? sanitize_key( $forced_department ) : qrrs_inventory_user_department();

        $wpdb->insert(
            $req_table,
            [
                'restaurant_id'  => $restaurant_id,
                'requested_by'   => get_current_user_id(),
                'requested_role' => implode( ',', array_map( 'sanitize_key', $roles ) ),
                'department'     => $department,
                'request_type'   => sanitize_key( $_POST['request_type'] ),
                'priority'       => sanitize_key( $_POST['priority'] ),
                'status'         => 'pending',
                'note'           => sanitize_textarea_field( $_POST['note'] ),
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        $req_id = $wpdb->insert_id;
        $item_id = intval( $_POST['inventory_item_id'] );
        $qty = floatval( $_POST['quantity'] );

        if ( $req_id && $item_id && $qty > 0 ) {
            $unit_id = $wpdb->get_var(
                $wpdb->prepare( "SELECT unit_id FROM $item_table WHERE id = %d AND restaurant_id = %d", $item_id, $restaurant_id )
            );

            $wpdb->insert(
                $req_item_table,
                [
                    'requisition_id'    => $req_id,
                    'inventory_item_id' => $item_id,
                    'requested_qty'     => $qty,
                    'unit_id'           => intval( $unit_id ),
                    'note'              => sanitize_text_field( $_POST['item_note'] ),
                ],
                [ '%d', '%d', '%f', '%d', '%s' ]
            );
        }

        echo '<div style="background:#dcfce7; color:#166534; padding:12px 15px; border-radius:8px; margin-bottom:15px; font-weight:700;">Requisition submitted successfully.</div>';
    }

    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT i.*, u.unit_code
             FROM $item_table i
             LEFT JOIN $unit_table u ON i.unit_id = u.id
             WHERE i.restaurant_id = %d AND i.status = 'active'
             ORDER BY i.item_name ASC",
            $restaurant_id
        )
    );

    $my_requests = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT r.*, i.item_name, ri.requested_qty, ri.approved_qty, ri.issued_qty, u.unit_code
             FROM $req_table r
             LEFT JOIN $req_item_table ri ON r.id = ri.requisition_id
             LEFT JOIN $item_table i ON ri.inventory_item_id = i.id
             LEFT JOIN $unit_table u ON ri.unit_id = u.id
             WHERE r.restaurant_id = %d AND r.requested_by = %d
             ORDER BY r.id DESC LIMIT 20",
            $restaurant_id,
            get_current_user_id()
        )
    );
    ?>
    <div class="qrrs-staff-req-wrap" style="background:#fff; color:#1f2937; padding:22px; border-radius:12px; margin:20px auto; max-width:980px;">
        <h2 style="margin-top:0;">Inventory Requisition</h2>
        <form method="post" style="display:grid; grid-template-columns:repeat(2,minmax(180px,1fr)); gap:14px;">
            <?php wp_nonce_field( 'qrrs_staff_requisition_action', 'qrrs_staff_requisition_nonce' ); ?>
            <input type="hidden" name="qrrs_staff_requisition_action" value="1">
            <label style="font-weight:700;">Item
                <select name="inventory_item_id" required style="width:100%; padding:10px; margin-top:6px;">
                    <option value="">Select item</option>
                    <?php foreach ( $items as $item ) : ?>
                        <option value="<?php echo esc_attr( $item->id ); ?>"><?php echo esc_html( $item->item_name . ' (' . $item->unit_code . ')' ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="font-weight:700;">Quantity
                <input type="number" step="0.0001" name="quantity" required style="width:100%; padding:10px; margin-top:6px;">
            </label>
            <label style="font-weight:700;">Request Type
                <select name="request_type" style="width:100%; padding:10px; margin-top:6px;">
                    <option value="raw_material">Raw Material</option>
                    <option value="cutlery">Cutlery</option>
                    <option value="consumable">Consumable</option>
                    <option value="asset_supply">Asset Supply</option>
                    <option value="other">Other</option>
                </select>
            </label>
            <label style="font-weight:700;">Priority
                <select name="priority" style="width:100%; padding:10px; margin-top:6px;">
                    <option value="normal">Normal</option>
                    <option value="urgent">Urgent</option>
                    <option value="low">Low</option>
                </select>
            </label>
            <label style="font-weight:700;">Item Note
                <input type="text" name="item_note" style="width:100%; padding:10px; margin-top:6px;">
            </label>
            <label style="font-weight:700;">Request Note
                <textarea name="note" rows="2" style="width:100%; padding:10px; margin-top:6px;"></textarea>
            </label>
            <div style="grid-column:1/-1;">
                <button type="submit" style="background:#f97316; color:#fff; border:none; border-radius:8px; padding:12px 18px; font-weight:800; cursor:pointer;">Submit Requisition</button>
            </div>
        </form>

        <h3>My Recent Requests</h3>
        <table class="qrrs-table" style="width:100%; background:#fff;">
            <thead><tr><th>ID</th><th>Item</th><th>Requested</th><th>Approved</th><th>Issued</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ( $my_requests as $request ) : ?>
                <tr>
                    <td>#<?php echo esc_html( $request->id ); ?></td>
                    <td><?php echo esc_html( $request->item_name ); ?></td>
                    <td><?php echo esc_html( $request->requested_qty . ' ' . $request->unit_code ); ?></td>
                    <td><?php echo esc_html( $request->approved_qty . ' ' . $request->unit_code ); ?></td>
                    <td><?php echo esc_html( $request->issued_qty . ' ' . $request->unit_code ); ?></td>
                    <td><strong><?php echo esc_html( $request->status ); ?></strong></td>
                    <td><?php echo esc_html( $request->created_at ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $my_requests ) ) : ?>
                <tr><td colspan="7" style="text-align:center;">No requisitions submitted yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
