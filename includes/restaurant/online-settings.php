<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Online & Delivery settings (admin only).
 *  - Services: dine-in, take-away, delivery, room service (on/off)
 *  - Online ordering on/off and a private order token (used by the public order page)
 *  - Business hours per weekday (online orders only open inside these hours)
 *  - Delivery areas with charge and minimum order (admin adds / edits / disables them)
 * Delivery charge is never taxed; VAT applies to food only.
 */

function qrrs_online_services() {
    return [
        'dine_in'      => 'Dine-in',
        'take_out'     => 'Take-away',
        'delivery'     => 'Delivery',
        'room_service' => 'Room service',
    ];
}

function qrrs_online_day_names() {
    return [ 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' ];
}

/** Active services of a restaurant, e.g. [ 'dine_in' => true, ... ]. Defaults: dine-in and take-away on. */
function qrrs_online_services_for( $restaurant_id ) {
    global $wpdb;
    $json = $wpdb->get_var( $wpdb->prepare( "SELECT services_json FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", intval( $restaurant_id ) ) );
    $saved = $json ? (array) json_decode( $json, true ) : [];
    $out = [];
    foreach ( qrrs_online_services() as $key => $label ) {
        $out[ $key ] = array_key_exists( $key, $saved ) ? (bool) $saved[ $key ] : in_array( $key, [ 'dine_in', 'take_out' ], true );
    }
    return $out;
}

/** Saves the form. Returns a message key. */
function qrrs_online_save( $restaurant_id ) {
    global $wpdb;
    $r_t = $wpdb->prefix . 'qrrs_restaurants';
    $h_t = $wpdb->prefix . 'qrrs_business_hours';
    $a_t = $wpdb->prefix . 'qrrs_delivery_areas';
    $rid = intval( $restaurant_id );
    $action = sanitize_key( $_POST['online_action'] ?? '' );

    if ( ! isset( $_POST['online_nonce'] ) || ! wp_verify_nonce( $_POST['online_nonce'], 'qrrs_online_save' ) ) {
        wp_die( 'Security check failed' );
    }

    if ( 'settings' === $action ) {
        $services = [];
        foreach ( qrrs_online_services() as $key => $label ) {
            $services[ $key ] = ! empty( $_POST['service'][ $key ] ) ? 1 : 0;
        }
        $online = ! empty( $_POST['online_enabled'] ) ? 1 : 0;
        qrrs_otp_save_mode( $rid, sanitize_key( $_POST['otp_mode'] ?? 'off' ) );
        $row    = $wpdb->get_row( $wpdb->prepare( "SELECT online_token FROM $r_t WHERE id = %d", $rid ) );
        $token  = $row ? $row->online_token : '';
        if ( $online && '' === $token ) {
            $token = wp_generate_password( 32, false, false );
        }
        $wpdb->update( $r_t, [
            'services_json'  => wp_json_encode( $services ),
            'online_enabled' => $online,
            'online_token'   => $token,
        ], [ 'id' => $rid ] );

        // Business hours (7 rows)
        foreach ( qrrs_online_day_names() as $dow => $name ) {
            $closed = ! empty( $_POST['closed'][ $dow ] ) ? 1 : 0;
            $open   = preg_match( '/^\d{2}:\d{2}$/', $_POST['open'][ $dow ] ?? '' ) ? $_POST['open'][ $dow ] . ':00' : null;
            $close  = preg_match( '/^\d{2}:\d{2}$/', $_POST['close'][ $dow ] ?? '' ) ? $_POST['close'][ $dow ] . ':00' : null;
            if ( ! $closed && ( ! $open || ! $close ) ) {
                return 'hours_required';
            }
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $h_t (restaurant_id, day_of_week, open_time, close_time, is_closed) VALUES (%d, %d, %s, %s, %d)
                 ON DUPLICATE KEY UPDATE open_time = VALUES(open_time), close_time = VALUES(close_time), is_closed = VALUES(is_closed)",
                $rid, $dow, $open, $close, $closed
            ) );
        }
        return 'settings_saved';
    }

    if ( 'add_area' === $action ) {
        $name = sanitize_text_field( wp_unslash( $_POST['area_name'] ?? '' ) );
        $charge = floatval( $_POST['charge'] ?? -1 );
        $min    = floatval( $_POST['min_order'] ?? 0 );
        if ( '' === trim( $name ) ) return 'area_name_required';
        if ( $charge < 0 || $min < 0 ) return 'area_value_invalid';
        $wpdb->insert( $a_t, [ 'restaurant_id' => $rid, 'area_name' => $name, 'charge' => round( $charge, 2 ), 'min_order' => round( $min, 2 ), 'status' => 'active' ] );
        return 'area_saved';
    }

    if ( 'update_area' === $action ) {
        $aid    = intval( $_POST['area_id'] ?? 0 );
        $charge = floatval( $_POST['charge'] ?? -1 );
        $min    = floatval( $_POST['min_order'] ?? 0 );
        $status = ( isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ) ? 'inactive' : 'active';
        if ( $charge < 0 || $min < 0 ) return 'area_value_invalid';
        $wpdb->update( $a_t, [ 'charge' => round( $charge, 2 ), 'min_order' => round( $min, 2 ), 'status' => $status ], [ 'id' => $aid, 'restaurant_id' => $rid ] );
        return 'area_saved';
    }

    if ( 'regen_token' === $action ) {
        // Changing the token breaks old public links and QR codes, so it needs its own button
        $wpdb->update( $r_t, [ 'online_token' => wp_generate_password( 32, false, false ) ], [ 'id' => $rid ] );
        return 'token_changed';
    }

    return 'error';
}

function qrrs_online_render( $restaurant_id, $is_admin ) {
    global $wpdb;
    if ( ! $is_admin ) {
        echo '<div class="qrrs-card" style="padding:20px;">Only admins can change online and delivery settings.</div>';
        return;
    }
    $rid = intval( $restaurant_id );
    if ( ! $rid ) {
        echo '<div class="qrrs-card" style="padding:20px;">Select a restaurant first.</div>';
        return;
    }

    $msg = '';
    if ( isset( $_POST['online_action'] ) ) {
        $msg = qrrs_online_save( $rid );
    }
    $texts = [
        'settings_saved'     => [ 'ok',  'Settings saved.' ],
        'hours_required'     => [ 'err', 'Set opening and closing time for every open day, or tick "Closed".' ],
        'area_saved'         => [ 'ok',  'Delivery area saved.' ],
        'area_name_required' => [ 'err', 'Area name is required.' ],
        'area_value_invalid' => [ 'err', 'Charge and minimum order must be 0 or more.' ],
        'token_changed'      => [ 'ok',  'New link created. The old link and QR code will stop working.' ],
        'room_saved'         => [ 'ok',  'Room saved.' ],
        'room_regenerated'   => [ 'ok',  'New QR created for this room. The old QR will stop working.' ],
        'room_exists'        => [ 'err', 'This room number already exists.' ],
        'room_number_required' => [ 'err', 'Enter a room number.' ],
        'error'              => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    if ( $msg && isset( $texts[ $msg ] ) ) {
        [ $k, $t ] = $texts[ $msg ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600; font-size:14px;">' . esc_html( $t ) . '</div>';
    }

    $r = $wpdb->get_row( $wpdb->prepare( "SELECT online_enabled, online_token FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $rid ) );
    $services = qrrs_online_services_for( $rid );
    $hours = [];
    foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_business_hours WHERE restaurant_id = %d", $rid ) ) as $h ) {
        $hours[ intval( $h->day_of_week ) ] = $h;
    }
    $areas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_delivery_areas WHERE restaurant_id = %d ORDER BY area_name ASC", $rid ) );
    $base  = add_query_arg( [ 'tab' => 'online-settings' ], home_url( '/restaurant-dashboard/' ) );
    ?>
    <form method="post" action="<?php echo esc_url( $base ); ?>">
        <?php wp_nonce_field( 'qrrs_online_save', 'online_nonce' ); ?>
        <input type="hidden" name="online_action" value="settings">

        <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:25px; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
                <span class="material-icons-outlined" style="color:#2563eb; font-size:22px;">room_service</span>
                <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Services</h3>
            </div>
            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                <?php foreach ( qrrs_online_services() as $key => $label ) : ?>
                    <label style="display:inline-flex; align-items:center; gap:8px; font-weight:500; color:#334155; cursor:pointer;">
                        <input type="checkbox" name="service[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $services[ $key ] ); ?> style="width:16px; height:16px; accent-color:#2563eb;">
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p style="color:#64748b; font-size:12px; margin:12px 0 0;">Room service needs room QR codes, set up in a later step.</p>
        </div>

        <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:25px; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
                <span class="material-icons-outlined" style="color:#2563eb; font-size:22px;">shopping_bag</span>
                <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Online ordering</h3>
            </div>
            <label style="display:flex; align-items:center; gap:8px; font-weight:600; color:#1e293b; cursor:pointer;">
                <input type="checkbox" name="online_enabled" value="1" <?php checked( (int) $r->online_enabled, 1 ); ?> style="width:16px; height:16px; accent-color:#2563eb;">
                Accept orders from the public order page
            </label>
            <div style="margin-top:16px;">
                <label style="font-weight:600; font-size:13px; color:#475569;">Mobile verification (OTP)</label><br>
                <select name="otp_mode" style="width:100%; max-width:320px; height:38px; padding:0 10px; margin-top:6px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;">
                    <option value="off" <?php selected( qrrs_otp_mode( $rid ), 'off' ); ?>>Off</option>
                    <option value="test" <?php selected( qrrs_otp_mode( $rid ), 'test' ); ?>>Test mode (code shown on the page, no SMS)</option>
                </select>
                <p style="color:#64748b; font-size:12px; margin:6px 0 0;">SMS and WhatsApp providers can be added later. Test mode is for trying the flow only.</p>
            </div>
            <?php if ( $r && $r->online_token ) : ?>
                <p style="font-size:13px; margin:14px 0 6px; color:#1e293b;"><strong>Public order link:</strong></p>
                <code style="display:block; padding:8px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; word-break:break-all; font-size:12px; color:#475569;"><?php echo esc_html( add_query_arg( 't', $r->online_token, home_url( '/online-order/' ) ) ); ?></code>
                <p style="font-size:12px; color:#64748b; margin:6px 0 0;">Create a WordPress page named <strong>online-order</strong> with the shortcode <code>[qrrs_online_order]</code>.</p>
                <?php
                $pub_url = add_query_arg( 't', $r->online_token, home_url( '/online-order/' ) );
                $qr_src  = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&margin=10&data=' . rawurlencode( $pub_url );
                ?>
                <div style="display:flex; gap:18px; align-items:center; flex-wrap:wrap; margin-top:16px;">
                    <img src="<?php echo esc_url( $qr_src ); ?>" alt="Order QR code" width="160" height="160" style="border:1px solid #e2e8f0; border-radius:8px; padding:6px; background:#fff;">
                    <div>
                        <p style="margin:0 0 10px; font-size:13px; color:#475569;">Print this QR and place it on tables or at the counter. Customers scan it to order online.</p>
                        <a href="<?php echo esc_url( $qr_src ); ?>" target="_blank" rel="noopener" download class="button" style="padding:6px 14px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; border-radius:6px; font-weight:600; text-decoration:none; display:inline-block;">⬇ Download / open QR</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:25px; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
                <span class="material-icons-outlined" style="color:#2563eb; font-size:22px;">schedule</span>
                <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Business hours (online orders)</h3>
            </div>
            <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                        <th style="padding:10px; font-size:13px; color:#475569;">Day</th>
                        <th style="padding:10px; font-size:13px; color:#475569;">Open</th>
                        <th style="padding:10px; font-size:13px; color:#475569;">Close</th>
                        <th style="padding:10px; font-size:13px; color:#475569;">Closed</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( qrrs_online_day_names() as $dow => $name ) :
                    $h = $hours[ $dow ] ?? null;
                    $is_closed = $h ? (int) $h->is_closed : 0;
                ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px; font-weight:600; color:#1e293b;"><?php echo esc_html( $name ); ?></td>
                        <td style="padding:10px;"><input type="time" name="open[<?php echo $dow; ?>]" value="<?php echo $h && $h->open_time ? esc_attr( substr( $h->open_time, 0, 5 ) ) : '10:00'; ?>" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;"></td>
                        <td style="padding:10px;"><input type="time" name="close[<?php echo $dow; ?>]" value="<?php echo $h && $h->close_time ? esc_attr( substr( $h->close_time, 0, 5 ) ) : '22:00'; ?>" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;"></td>
                        <td style="padding:10px;"><input type="checkbox" name="closed[<?php echo $dow; ?>]" value="1" <?php checked( $is_closed, 1 ); ?> style="width:16px; height:16px; accent-color:#2563eb;"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="color:#64748b; font-size:12px; margin:12px 0 0;">Time is Bangladesh time (Asia/Dhaka). Outside these hours the public page shows "Closed now" with the next opening time.</p>
        </div>

        <button type="submit" class="qrrs-btn-save" style="height:42px; padding:0 24px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; margin-bottom:25px; display:inline-flex; align-items:center; gap:6px;">
            <span class="material-icons-outlined" style="font-size:18px;">save</span> Save online & services
        </button>
    </form>

    <!-- 📌 Delivery Areas Section with Modern Card Layout -->
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:25px; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <span class="material-icons-outlined" style="color:#2563eb; font-size:22px;">local_shipping</span>
            <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Delivery areas</h3>
        </div>
        
        <!-- Add Area Form -->
        <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:20px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0;">
            <?php wp_nonce_field( 'qrrs_online_save', 'online_nonce' ); ?>
            <input type="hidden" name="online_action" value="add_area">
            <input type="text" name="area_name" placeholder="Area name e.g. Dhanmondi" required style="flex:1; min-width:200px; height:38px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; box-sizing:border-box;">
            <input type="number" name="charge" step="0.01" min="0" placeholder="Charge ৳" required style="width:130px; height:38px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; box-sizing:border-box;">
            <input type="number" name="min_order" step="0.01" min="0" placeholder="Min order ৳ (0 = none)" value="0" style="width:160px; height:38px; padding:0 10px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; box-sizing:border-box;">
            <button type="submit" class="button" style="height:38px; padding:0 16px; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; border-radius:6px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                <span class="material-icons-outlined" style="font-size:16px;">add</span> Add area
            </button>
        </form>

        <!-- Modern Cards Grid for Delivery Areas -->
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:15px;">
            <?php foreach ( $areas as $a ) : ?>
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:15px; box-shadow:0 1px 3px rgba(0,0,0,0.02); display:flex; flex-direction:column; justify-content:space-between;">
                    <form method="post" action="<?php echo esc_url( $base ); ?>" style="margin:0;">
                        <?php wp_nonce_field( 'qrrs_online_save', 'online_nonce' ); ?>
                        <input type="hidden" name="online_action" value="update_area">
                        <input type="hidden" name="area_id" value="<?php echo intval( $a->id ); ?>">
                        
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                            <span style="font-weight:700; font-size:15px; color:#1e293b;"><?php echo esc_html( $a->area_name ); ?></span>
                            <select name="status" style="padding:2px 6px; font-size:12px; border:1px solid #cbd5e1; border-radius:4px; background:<?php echo $a->status == 'active' ? '#dcfce7; color:#166534;' : '#fee2e2; color:#991b1b;'; ?>">
                                <option value="active" <?php selected( $a->status, 'active' ); ?>>Active</option>
                                <option value="inactive" <?php selected( $a->status, 'inactive' ); ?>>Inactive</option>
                            </select>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:12px;">
                            <div>
                                <label style="display:block; font-size:11px; color:#64748b; margin-bottom:2px;">Charge (৳)</label>
                                <input type="number" name="charge" step="0.01" min="0" value="<?php echo esc_attr( $a->charge ); ?>" style="width:100%; height:32px; padding:0 6px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block; font-size:11px; color:#64748b; margin-bottom:2px;">Min Order (৳)</label>
                                <input type="number" name="min_order" step="0.01" min="0" value="<?php echo esc_attr( $a->min_order ); ?>" style="width:100%; height:32px; padding:0 6px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; box-sizing:border-box;">
                            </div>
                        </div>

                        <button type="submit" class="button" style="width:100%; height:32px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; border-radius:4px; font-weight:600; cursor:pointer; font-size:12px;">Update Area</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( empty( $areas ) ) : ?>
            <div style="text-align:center; padding:25px; color:#64748b; background:#f8fafc; border-radius:8px; border:1px dashed #cbd5e1;">No delivery area yet. Add the areas you deliver to above.</div>
        <?php endif; ?>
    </div>

    <div class="qrrs-card" style="background:#fff; padding:16px 20px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:25px; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05); font-size:14px; color:#334155;">
        <strong>Rooms</strong> are managed in the <em>Rooms</em> menu on the left.
    </div>

    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
            <span class="material-icons-outlined" style="color:#dc2626; font-size:22px;">link_off</span>
            <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;">Private link</h3>
        </div>
        <p style="color:#64748b; font-size:13px; margin:0 0 14px;">Regenerate only if the link was shared by mistake. Old QR codes will stop working.</p>
        <form method="post" action="<?php echo esc_url( $base ); ?>" onsubmit="return confirm('Create a new link? Old QR codes will stop working.');">
            <?php wp_nonce_field( 'qrrs_online_save', 'online_nonce' ); ?>
            <input type="hidden" name="online_action" value="regen_token">
            <button type="submit" class="button" style="height:38px; padding:0 16px; background:#fee2e2; color:#dc2626; border:1px solid #fecaca; border-radius:6px; font-weight:600; cursor:pointer;">Create new link</button>
        </form>
    </div>
    <?php
}