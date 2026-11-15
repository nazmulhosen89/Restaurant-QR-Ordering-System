<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CRM (admin / super admin only).
 *  - Customers are saved automatically from online orders (name, mobile, address).
 *  - Admin can search, view (with order history), add, edit and delete customers.
 *  - Deleting a customer does not delete their orders.
 *  - Public page: typing a known mobile number fills ONLY the name. Address is never shown.
 */

function qrrs_normalize_phone( $raw ) {
    $p = preg_replace( '/[\s\-]/', '', (string) $raw );
    if ( 0 === strpos( $p, '+880' ) ) $p = '0' . substr( $p, 4 );
    elseif ( 0 === strpos( $p, '880' ) ) $p = '0' . substr( $p, 3 );
    return $p;
}

/** Called when an online order is placed: create or update the customer. */
function qrrs_crm_upsert( $restaurant_id, $name, $phone, $address ) {
    global $wpdb;
    $t = $wpdb->prefix . 'qrrs_customers';
    $phone = qrrs_normalize_phone( $phone );
    if ( '' === $phone ) return;
    $now = current_time( 'mysql' );
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $t WHERE restaurant_id = %d AND phone = %s", intval( $restaurant_id ), $phone ) );
    if ( $existing ) {
        $data = [ 'customer_name' => $name, 'last_order_at' => $now, 'updated_at' => $now ];
        if ( '' !== trim( (string) $address ) ) $data['address'] = $address; // keep the newest address
        $wpdb->update( $t, $data, [ 'id' => $existing->id ] );
    } else {
        $wpdb->insert( $t, [
            'restaurant_id' => intval( $restaurant_id ), 'customer_name' => $name, 'phone' => $phone,
            'address' => $address, 'last_order_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ] );
    }
}

/** Public: customer name for a known mobile number (name only). */
function qrrs_ajax_public_customer_name() {
    global $wpdb;
    $r = qrrs_public_restaurant_by_token( $_POST['token'] ?? '' );
    if ( ! $r ) wp_send_json_error( 'Not allowed.' );
    $phone = qrrs_normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
    if ( ! preg_match( '/^01[3-9]\d{8}$/', $phone ) ) wp_send_json_success( [ 'name' => '' ] );
    $name = $wpdb->get_var( $wpdb->prepare(
        "SELECT customer_name FROM {$wpdb->prefix}qrrs_customers WHERE restaurant_id = %d AND phone = %s",
        intval( $r->id ), $phone
    ) );
    wp_send_json_success( [ 'name' => (string) $name ] );
}
add_action( 'wp_ajax_qrrs_public_customer_name', 'qrrs_ajax_public_customer_name' );
add_action( 'wp_ajax_nopriv_qrrs_public_customer_name', 'qrrs_ajax_public_customer_name' );

function qrrs_crm_redirect( $status, $id = 0 ) {
    $args = [ 'tab' => 'crm', 'crm_status' => $status ];
    if ( $id ) $args['crm_id'] = $id;
    wp_safe_redirect( add_query_arg( $args, home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

function qrrs_crm_handle_actions( $restaurant_id, $is_admin ) {
    global $wpdb;
    $t = $wpdb->prefix . 'qrrs_customers';
    $action = sanitize_key( $_POST['crm_action'] ?? '' );
    if ( ! isset( $_POST['crm_nonce'] ) || ! wp_verify_nonce( $_POST['crm_nonce'], 'qrrs_crm' ) ) wp_die( 'Security check failed' );
    if ( ! $is_admin ) qrrs_crm_redirect( 'error' );
    $rid = intval( $restaurant_id );

    if ( 'save' === $action ) {
        $id    = intval( $_POST['customer_id'] ?? 0 );
        $name  = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
        $phone = qrrs_normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
        $addr  = sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) );
        $notes = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        if ( mb_strlen( trim( $name ) ) < 2 ) qrrs_crm_redirect( 'name_required', $id );
        if ( ! preg_match( '/^01[3-9]\d{8}$/', $phone ) ) qrrs_crm_redirect( 'phone_invalid', $id );
        $dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE restaurant_id = %d AND phone = %s AND id <> %d", $rid, $phone, $id ) );
        if ( $dup ) qrrs_crm_redirect( 'phone_exists', $id );
        $now = current_time( 'mysql' );
        if ( $id ) {
            $wpdb->update( $t, [ 'customer_name' => $name, 'phone' => $phone, 'address' => $addr, 'notes' => $notes, 'updated_at' => $now ], [ 'id' => $id, 'restaurant_id' => $rid ] );
            qrrs_crm_redirect( 'saved', $id );
        }
        $wpdb->insert( $t, [ 'restaurant_id' => $rid, 'customer_name' => $name, 'phone' => $phone, 'address' => $addr, 'notes' => $notes, 'created_at' => $now, 'updated_at' => $now ] );
        qrrs_crm_redirect( 'saved', (int) $wpdb->insert_id );
    }

    if ( 'delete' === $action ) {
        $id = intval( $_POST['customer_id'] ?? 0 );
        $wpdb->delete( $t, [ 'id' => $id, 'restaurant_id' => $rid ] );
        qrrs_crm_redirect( 'deleted' );
    }
    qrrs_crm_redirect( 'error' );
}

/** Most ordered items of one customer (all order types, cancelled excluded). */
function qrrs_customer_favourites( $restaurant_id, $phone, $limit = 5 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT oi.item_name, SUM(oi.quantity) AS qty
         FROM {$wpdb->prefix}qrrs_order_items oi
         JOIN {$wpdb->prefix}qrrs_order_customers oc ON oc.order_id = oi.order_id
         JOIN {$wpdb->prefix}qrrs_orders o ON o.id = oi.order_id
         WHERE o.restaurant_id = %d AND oc.customer_phone = %s AND o.order_status <> 'cancelled'
         GROUP BY oi.item_name ORDER BY qty DESC LIMIT %d",
        intval( $restaurant_id ), $phone, intval( $limit )
    ) );
}

/** Admin: full CRM. Manager: read-only full view. */
function qrrs_crm_render( $restaurant_id, $is_admin, $can_view = false ) {
    global $wpdb;
    if ( ! $is_admin && ! $can_view ) {
        echo '<div class="qrrs-card" style="padding:20px;">CRM is for admins and managers only.</div>';
        return;
    }
    $rid = intval( $restaurant_id );
    $t   = $wpdb->prefix . 'qrrs_customers';
    $oc  = $wpdb->prefix . 'qrrs_order_customers';
    $o   = $wpdb->prefix . 'qrrs_orders';
    $base = add_query_arg( [ 'tab' => 'crm' ], home_url( '/restaurant-dashboard/' ) );

    $msgs = [
        'saved'         => [ 'ok',  'Customer saved.' ],
        'deleted'       => [ 'ok',  'Customer deleted. Their past orders are kept.' ],
        'name_required' => [ 'err', 'Name must be at least 2 characters.' ],
        'phone_invalid' => [ 'err', 'Enter a valid mobile number (01XXXXXXXXX).' ],
        'phone_exists'  => [ 'err', 'Another customer already uses this mobile number.' ],
        'error'         => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    $st = sanitize_key( $_GET['crm_status'] ?? '' );
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:6px; margin-bottom:12px;">' . esc_html( $txt ) . '</div>';
    }

    $view_id = intval( $_GET['crm_id'] ?? 0 );
    $editing = isset( $_GET['crm_edit'] );
    $cust    = $view_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND restaurant_id = %d", $view_id, $rid ) ) : null;

    // ---- single customer: details + history ----
    if ( $cust && ! $editing ) {
        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(o.grand_total),0) AS total, MAX(o.created_at) AS last
             FROM $oc oc JOIN $o o ON o.id = oc.order_id
             WHERE o.restaurant_id = %d AND oc.customer_phone = %s", $rid, $cust->phone ) );
        $history = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.id, o.created_at, o.order_status, o.order_type, o.grand_total
             FROM $oc oc JOIN $o o ON o.id = oc.order_id
             WHERE o.restaurant_id = %d AND oc.customer_phone = %s ORDER BY o.id DESC LIMIT 15", $rid, $cust->phone ) );
        ?>
        <div class="qrrs-card" style="padding:20px; margin-bottom:20px !important;">
            <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; align-items:flex-start;">
                <div>
                    <h3 style="margin:0;"><?php echo esc_html( $cust->customer_name ); ?></h3>
                    <div style="color:#64748b;">📞 <?php echo esc_html( $cust->phone ); ?></div>
                    <?php if ( $cust->address ) : ?><div style="color:#64748b; margin-top:4px;">📍 <?php echo esc_html( $cust->address ); ?></div><?php endif; ?>
                    <?php if ( $cust->notes ) : ?><div style="margin-top:6px; font-size:13px;">📝 <?php echo esc_html( $cust->notes ); ?></div><?php endif; ?>
                </div>
                <div style="display:flex; gap:8px;">
                    <?php if ( $is_admin ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( [ 'crm_id' => $view_id, 'crm_edit' => 1 ], $base ) ); ?>">Edit</a><?php endif; ?>
                    <a class="button" href="<?php echo esc_url( $base ); ?>">Back to list</a>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-top:16px;">
                <div style="background:#f8fafc; padding:12px; border-radius:10px;"><small>Orders</small><br><strong><?php echo intval( $stats->n ); ?></strong></div>
                <div style="background:#f8fafc; padding:12px; border-radius:10px;"><small>Total spent</small><br><strong>৳<?php echo esc_html( number_format( (float) $stats->total, 2 ) ); ?></strong></div>
                <div style="background:#f8fafc; padding:12px; border-radius:10px;"><small>Last order</small><br><strong><?php echo $stats->last ? esc_html( substr( $stats->last, 0, 10 ) ) : '—'; ?></strong></div>
            </div>
        </div>
        <?php $favs = qrrs_customer_favourites( $rid, $cust->phone, 5 ); ?>
        <div class="qrrs-card" style="padding:20px; margin-bottom:20px !important;">
            <h3 style="margin-top:0;">Most ordered</h3>
            <?php if ( $favs ) : ?>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                <?php foreach ( $favs as $f ) : ?>
                    <span style="background:#fff7ed; color:#c2410c; padding:6px 12px; border-radius:999px; font-size:13px; font-weight:600;"><?php echo esc_html( $f->item_name ); ?> × <?php echo intval( $f->qty ); ?></span>
                <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p style="color:#94a3b8; margin:0;">No orders yet.</p>
            <?php endif; ?>
        </div>
        <div class="qrrs-card" style="padding:20px;">
            <h3 style="margin-top:0;">Order history</h3>
            <table style="width:100%; border-collapse:collapse;">
                <thead><tr style="background:#f1f5f9; text-align:left;"><th style="padding:8px;">Order</th><th style="padding:8px;">Date</th><th style="padding:8px;">Type</th><th style="padding:8px;">Status</th><th style="padding:8px; text-align:right;">Total</th></tr></thead>
                <tbody>
                <?php foreach ( $history as $h ) : ?>
                    <tr style="border-top:1px solid #e2e8f0;">
                        <td style="padding:8px;">#<?php echo intval( $h->id ); ?></td>
                        <td style="padding:8px;"><?php echo esc_html( substr( $h->created_at, 0, 16 ) ); ?></td>
                        <td style="padding:8px;"><?php echo esc_html( str_replace( '_', ' ', $h->order_type ) ); ?></td>
                        <td style="padding:8px;"><?php echo esc_html( str_replace( '_', ' ', $h->order_status ) ); ?></td>
                        <td style="padding:8px; text-align:right;">৳<?php echo esc_html( number_format( (float) $h->grand_total, 2 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $history ) ) : ?><tr><td colspan="5" style="padding:14px; text-align:center; color:#94a3b8;">No orders yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return;
    }

    // ---- add / edit form ----
    if ( $is_admin && ( $editing || ( $view_id && ! $cust ) ) ) :
        ?>
        <div class="qrrs-card" style="padding:20px; margin-bottom:20px !important;">
            <h3 style="margin-top:0;"><?php echo $cust ? 'Edit customer' : 'Add customer'; ?></h3>
            <form method="post" action="<?php echo esc_url( $base ); ?>">
                <?php wp_nonce_field( 'qrrs_crm', 'crm_nonce' ); ?>
                <input type="hidden" name="crm_action" value="save">
                <input type="hidden" name="customer_id" value="<?php echo $cust ? intval( $cust->id ) : 0; ?>">
                <div class="qrrs-form-grid">
                    <div class="qrrs-input-group"><label>Name *</label><input type="text" name="customer_name" required value="<?php echo esc_attr( $cust->customer_name ?? '' ); ?>"></div>
                    <div class="qrrs-input-group"><label>Mobile *</label><input type="text" name="phone" required placeholder="01XXXXXXXXX" value="<?php echo esc_attr( $cust->phone ?? '' ); ?>"></div>
                </div>
                <div class="qrrs-input-group"><label>Address</label><textarea name="address" rows="2"><?php echo esc_textarea( $cust->address ?? '' ); ?></textarea></div>
                <div class="qrrs-input-group"><label>Notes</label><textarea name="notes" rows="2"><?php echo esc_textarea( $cust->notes ?? '' ); ?></textarea></div>
                <div style="margin-top:12px; display:flex; gap:10px; align-items:center;">
                    <button type="submit" class="qrrs-btn-save"><?php echo $cust ? 'Update' : 'Save customer'; ?></button>
                    <?php if ( $cust ) : ?><a href="<?php echo esc_url( add_query_arg( 'crm_id', $cust->id, $base ) ); ?>" style="color:#64748b;">Cancel</a><?php endif; ?>
                </div>
            </form>
            <?php if ( $cust ) : ?>
            <form method="post" action="<?php echo esc_url( $base ); ?>" onsubmit="return confirm('Delete this customer? Their past orders will be kept.');" style="margin-top:14px;">
                <?php wp_nonce_field( 'qrrs_crm', 'crm_nonce' ); ?>
                <input type="hidden" name="crm_action" value="delete">
                <input type="hidden" name="customer_id" value="<?php echo intval( $cust->id ); ?>">
                <button type="submit" class="button" style="color:#dc2626;">Delete customer</button>
            </form>
            <?php endif; ?>
        </div>
        <?php
        return;
    endif;

    // ---- list ----
    $q    = sanitize_text_field( wp_unslash( $_GET['crm_q'] ?? '' ) );
    $like = '%' . $wpdb->esc_like( $q ) . '%';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT c.id, c.customer_name, c.phone, c.last_order_at,
                (SELECT COUNT(*) FROM $oc oc JOIN $o o ON o.id = oc.order_id WHERE o.restaurant_id = c.restaurant_id AND oc.customer_phone = c.phone) AS orders_n
         FROM $t c WHERE c.restaurant_id = %d AND (c.customer_name LIKE %s OR c.phone LIKE %s)
         ORDER BY c.last_order_at DESC, c.id DESC LIMIT 200",
        $rid, $like, $like
    ) );
    ?>
    <div class="qrrs-card" style="padding:20px;">
        <div style="display:flex; flex-direction:row; justify-content:space-between; align-items:center; flex-wrap:nowrap; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
            <h3 style="margin:0; font-size:18px; font-weight:700; color:#1e293b; white-space:nowrap;">Customers <span style="color:#64748b; font-weight:600;">(<?php echo count( $rows ); ?>)</span></h3>
            <div style="display:flex; flex-direction:row; align-items:center; gap:10px; flex:0 0 auto;">
                <form method="get" role="search" style="margin:0; display:flex; flex-direction:row; align-items:center; position:relative;">
                    <input type="hidden" name="tab" value="crm">
                    <span class="material-icons-outlined" style="position:absolute; left:11px; top:50%; transform:translateY(-50%); font-size:18px; line-height:1; color:#94a3b8; pointer-events:none;">search</span>
                    <input type="search" name="crm_q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search name or mobile" style="height:40px; width:260px; padding:0 12px 0 36px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box; margin:0;">
                </form>
                <?php if ( $is_admin ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'crm_edit', 1, $base ) ); ?>" style="height:40px; display:inline-flex; align-items:center; gap:6px; padding:0 16px; background:#2563eb; color:#fff; border-radius:8px; font-size:14px; font-weight:600; text-decoration:none; white-space:nowrap;">
                    <span class="material-icons-outlined" style="font-size:18px;">person_add</span> Add customer
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th style="padding:8px;">Name</th><th style="padding:8px;">Mobile</th><th style="padding:8px;">Orders</th><th style="padding:8px;">Last order</th><th style="padding:8px;"></th></tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) : ?>
                <tr style="border-top:1px solid #e2e8f0;">
                    <td style="padding:8px; font-weight:600;"><?php echo esc_html( $r->customer_name ); ?></td>
                    <td style="padding:8px;"><?php echo esc_html( $r->phone ); ?></td>
                    <td style="padding:8px;"><?php echo intval( $r->orders_n ); ?></td>
                    <td style="padding:8px;"><?php echo $r->last_order_at ? esc_html( substr( $r->last_order_at, 0, 10 ) ) : '—'; ?></td>
                    <td style="padding:8px; text-align:right;"><a href="<?php echo esc_url( add_query_arg( 'crm_id', intval( $r->id ), $base ) ); ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rows ) ) : ?><tr><td colspan="5" style="padding:20px; text-align:center; color:#94a3b8;">No customers yet. They appear automatically after an online order.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
}

/** Handles CRM form posts early, before the dashboard prints anything (so redirects work). */
add_action( 'init', function () {
    if ( empty( $_POST['crm_action'] ) || ! is_user_logged_in() ) {
        return;
    }
    $is_admin = current_user_can( 'administrator' ) || ( function_exists( 'is_super_admin' ) && is_super_admin() );
    $rid = function_exists( 'qrrs_inventory_get_active_restaurant_id' ) ? qrrs_inventory_get_active_restaurant_id() : 0;
    qrrs_crm_handle_actions( $rid, $is_admin );
} );

/**
 * Waiter view: name, masked mobile (last 4 digits), visit count and most ordered items only.
 * Search by name or by the last 4 digits. No address, no notes, no full number.
 */
function qrrs_crm_waiter_render( $restaurant_id ) {
    global $wpdb;
    $rid  = intval( $restaurant_id );
    $t    = $wpdb->prefix . 'qrrs_customers';
    $oc   = $wpdb->prefix . 'qrrs_order_customers';
    $o    = $wpdb->prefix . 'qrrs_orders';
    $q    = sanitize_text_field( wp_unslash( $_GET['crm_q'] ?? '' ) );
    $like = '%' . $wpdb->esc_like( $q ) . '%';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT c.id, c.customer_name, c.phone,
                (SELECT COUNT(*) FROM $oc oc JOIN $o o ON o.id = oc.order_id WHERE o.restaurant_id = c.restaurant_id AND oc.customer_phone = c.phone) AS visits
         FROM $t c
         WHERE c.restaurant_id = %d AND (c.customer_name LIKE %s OR RIGHT(c.phone, 4) = %s)
         ORDER BY c.last_order_at DESC, c.id DESC LIMIT 100",
        $rid, $like, $q
    ) );
    $base = add_query_arg( [ 'tab' => 'customers' ], home_url( '/restaurant-dashboard/' ) );
    ?>
    <div class="qrrs-card" style="padding:20px;">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px;">
            <h3 style="margin:0;">Customers</h3>
            <form method="get" style="margin:0; display:flex; gap:6px;">
                <input type="hidden" name="tab" value="customers">
                <input type="search" name="crm_q" value="<?php echo esc_attr( $q ); ?>" placeholder="Name or last 4 digits" style="padding:6px 10px;">
                <button type="submit" class="button">Search</button>
            </form>
        </div>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th style="padding:8px;">Name</th><th style="padding:8px;">Mobile</th><th style="padding:8px;">Visits</th><th style="padding:8px;">Most ordered</th></tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $favs = qrrs_customer_favourites( $rid, $r->phone, 3 );
                $names = array_map( fn( $f ) => $f->item_name . ' × ' . intval( $f->qty ), $favs );
            ?>
                <tr style="border-top:1px solid #e2e8f0;">
                    <td style="padding:8px; font-weight:600;"><?php echo esc_html( $r->customer_name ); ?></td>
                    <td style="padding:8px;">•••• <?php echo esc_html( substr( $r->phone, -4 ) ); ?></td>
                    <td style="padding:8px;"><?php echo intval( $r->visits ); ?></td>
                    <td style="padding:8px; font-size:13px;"><?php echo $names ? esc_html( implode( ', ', $names ) ) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rows ) ) : ?><tr><td colspan="4" style="padding:20px; text-align:center; color:#94a3b8;">No matching customer.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
}