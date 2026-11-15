<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Order -> raw material deduction (recipe based).
 *  - Stock is reduced when the kitchen accepts an order (status = processing).
 *  - Cancelling an order that was already deducted returns the stock.
 *  - Each order line is deducted only once (order_items.inventory_deducted).
 *  - Stock may go below zero for sales: the order is still served, and the item
 *    shows as low / negative in the Low Stock tab.
 */

add_action( 'qrrs_order_status_changed', 'qrrs_order_stock_on_status', 10, 2 );

function qrrs_order_stock_on_status( $order_id, $status ) {
    $order_id = intval( $order_id );
    if ( 'processing' === $status ) {
        qrrs_order_stock_deduct( $order_id );
    } elseif ( 'cancelled' === $status ) {
        qrrs_order_stock_reverse( $order_id );
    }
}

/** Returns [ factor, base_code ] for a unit. */
function qrrs_order_stock_unit( $unit_id ) {
    global $wpdb;
    $u = $wpdb->get_row( $wpdb->prepare(
        "SELECT unit_code, base_unit_code, conversion_factor FROM {$wpdb->prefix}qrrs_inventory_units WHERE id = %d",
        intval( $unit_id )
    ) );
    if ( ! $u ) {
        return [ 1.0, '' ];
    }
    $factor = (float) $u->conversion_factor > 0 ? (float) $u->conversion_factor : 1.0;
    $base   = $u->base_unit_code ?: $u->unit_code;
    return [ $factor, $base ];
}

/** Variant names chosen on an order line ("Spicy, Extra Cheese" -> ["Spicy", "Extra Cheese"]). */
function qrrs_order_stock_selected_variants( $csv ) {
    $parts = array_map( 'trim', explode( ',', (string) $csv ) );
    return array_values( array_unique( array_filter( $parts, fn( $v ) => '' !== $v ) ) );
}

function qrrs_order_stock_deduct( $order_id ) {
    global $wpdb;
    $orders_t  = $wpdb->prefix . 'qrrs_orders';
    $lines_t   = $wpdb->prefix . 'qrrs_order_items';
    $recipes_t = $wpdb->prefix . 'qrrs_recipes';
    $r_items_t = $wpdb->prefix . 'qrrs_recipe_items';
    $inv_t     = $wpdb->prefix . 'qrrs_inventory_items';

    $order = $wpdb->get_row( $wpdb->prepare( "SELECT id, restaurant_id FROM $orders_t WHERE id = %d", $order_id ) );
    if ( ! $order ) {
        return false;
    }
    $rid = intval( $order->restaurant_id );

    $wpdb->query( 'START TRANSACTION' );

    $lines = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, item_id, item_name, quantity, variants_selected, size_value_ids, size_name FROM $lines_t
         WHERE order_id = %d AND inventory_deducted = 0 FOR UPDATE",
        $order_id
    ) );

    foreach ( $lines as $line ) {
        // Size factor: product of the chosen size values' factors (Half 0.5, Full 1, 500ml 1, ...).
        // Lines without a size use 1.
        $size_factor = 1.0;
        $size_ids = array_filter( array_map( 'intval', explode( ',', (string) $line->size_value_ids ) ) );
        foreach ( $size_ids as $sid ) {
            $f = $wpdb->get_var( $wpdb->prepare(
                "SELECT recipe_factor FROM {$wpdb->prefix}qrrs_menu_option_values WHERE id = %d AND restaurant_id = %d",
                $sid, $rid
            ) );
            if ( null !== $f && (float) $f > 0 ) {
                $size_factor *= (float) $f;
            }
        }

        // 1) Base recipe: quantity per portion = recipe quantity / serving size x size factor
        $ingredients = [];
        $recipe = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, serving_qty FROM $recipes_t
             WHERE restaurant_id = %d AND menu_item_id = %d AND status = 'active'
             ORDER BY id DESC LIMIT 1",
            $rid, intval( $line->item_id )
        ) );
        if ( $recipe ) {
            $serving = (float) $recipe->serving_qty > 0 ? (float) $recipe->serving_qty : 1.0;
            $base = $wpdb->get_results( $wpdb->prepare(
                "SELECT ri.inventory_item_id, ri.quantity_required, ri.unit_id, ri.wastage_percent,
                        i.unit_id AS item_unit_id, i.cost_per_unit
                 FROM $r_items_t ri
                 JOIN $inv_t i ON i.id = ri.inventory_item_id
                 WHERE ri.recipe_id = %d AND i.restaurant_id = %d",
                $recipe->id, $rid
            ) );
            foreach ( $base as $g ) {
                $g->per_portion = (float) $g->quantity_required / $serving * $size_factor;
                $ingredients[]  = $g;
            }
        }

        // 2) Variant add-ons: each selected variant adds its own ingredients per portion
        foreach ( qrrs_order_stock_selected_variants( $line->variants_selected ) as $vname ) {
            foreach ( qrrs_variant_recipe_lines( $rid, intval( $line->item_id ), $vname ) as $vg ) {
                $vg->per_portion = (float) $vg->quantity_required;
                $ingredients[]   = $vg;
            }
        }

        // 3) Deduct every ingredient for the ordered quantity
        foreach ( $ingredients as $ing ) {
            $need_recipe_unit = (float) $ing->per_portion
                * (int) $line->quantity
                * ( 1 + (float) $ing->wastage_percent / 100 );

            list( $f_recipe, $base_recipe ) = qrrs_order_stock_unit( $ing->unit_id );
            list( $f_item,   $base_item )   = qrrs_order_stock_unit( $ing->item_unit_id );

            if ( $base_recipe !== $base_item || $f_item <= 0 ) {
                error_log( "QRRS: unit mismatch for menu item {$line->item_id}, ingredient {$ing->inventory_item_id}" );
                continue;
            }

            $need = round( $need_recipe_unit * $f_recipe / $f_item, 4 );
            if ( $need <= 0 ) {
                continue;
            }

            $mv = qrrs_inventory_add_stock_movement( [
                'restaurant_id'     => $rid,
                'inventory_item_id' => intval( $ing->inventory_item_id ),
                'movement_type'     => 'sale',
                'quantity_out'      => $need,
                'unit_cost'         => (float) $ing->cost_per_unit,
                'reference_type'    => 'order',
                'reference_id'      => $order_id,
                'note'              => 'Sold: ' . $line->item_name . ' x' . intval( $line->quantity )
                                       . ( $line->variants_selected ? ' [' . $line->variants_selected . ']' : '' ),
                'created_by'        => 0,
                'allow_negative'    => true,
                'in_transaction'    => true,
            ] );
            if ( false === $mv ) {
                $wpdb->query( 'ROLLBACK' );
                return false;
            }
        }

        // Mark the line as processed (also when the item has no recipe at all)
        $wpdb->update(
            $lines_t,
            [ 'inventory_deducted' => 1, 'inventory_deducted_at' => current_time( 'mysql' ) ],
            [ 'id' => intval( $line->id ) ]
        );
    }

    $wpdb->query( 'COMMIT' );
    return true;
}

/** Returns stock for an order that was deducted and is now cancelled. Net-based, so it never double-returns. */
function qrrs_order_stock_reverse( $order_id ) {
    global $wpdb;
    $orders_t = $wpdb->prefix . 'qrrs_orders';
    $lines_t  = $wpdb->prefix . 'qrrs_order_items';
    $ledger_t = $wpdb->prefix . 'qrrs_stock_movements';

    $rid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT restaurant_id FROM $orders_t WHERE id = %d", $order_id ) );
    if ( ! $rid ) {
        return false;
    }

    $wpdb->query( 'START TRANSACTION' );

    $nets = $wpdb->get_results( $wpdb->prepare(
        "SELECT inventory_item_id,
                SUM(quantity_out) - SUM(quantity_in) AS net,
                MAX(unit_cost) AS unit_cost
         FROM $ledger_t
         WHERE restaurant_id = %d AND reference_type = 'order' AND reference_id = %d
           AND movement_type IN ('sale', 'sale_reversal')
         GROUP BY inventory_item_id",
        $rid, $order_id
    ) );

    foreach ( $nets as $n ) {
        $net = round( (float) $n->net, 4 );
        if ( $net <= 0 ) {
            continue;
        }
        $mv = qrrs_inventory_add_stock_movement( [
            'restaurant_id'     => $rid,
            'inventory_item_id' => intval( $n->inventory_item_id ),
            'movement_type'     => 'sale_reversal',
            'quantity_in'       => $net,
            'unit_cost'         => 0,
            'reference_type'    => 'order',
            'reference_id'      => $order_id,
            'note'              => 'Order #' . $order_id . ' cancelled',
            'created_by'        => get_current_user_id(),
            'in_transaction'    => true,
        ] );
        if ( false === $mv ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
    }

    $wpdb->update(
        $lines_t,
        [ 'inventory_deducted' => 0, 'inventory_deducted_at' => null ],
        [ 'order_id' => $order_id ]
    );
    $wpdb->query( 'COMMIT' );
    return true;
}