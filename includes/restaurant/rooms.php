<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rooms (hotel room service), managed from its own left menu like Table Management.
 *  - Create a room with room number, category and floor.
 *  - Each room has a private token and QR code. Print one QR or all QR codes.
 *  - Scanning a room QR opens the menu with the room already known.
 *  Link: /online-order/?t=RESTAURANT_TOKEN&rt=ROOM_TOKEN
 */

function qrrs_room_for_token( $restaurant_id, $room_token ) {
    global $wpdb;
    $rt = preg_replace( '/[^A-Za-z0-9]/', '', (string) $room_token );
    if ( strlen( $rt ) < 16 ) return null;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT id, room_number FROM {$wpdb->prefix}qrrs_rooms WHERE restaurant_id = %d AND room_token = %s AND status = 'active'",
        intval( $restaurant_id ), $rt
    ) );
}

function qrrs_rooms_categories() {
    return [ 'Standard', 'Deluxe', 'Suite', 'Executive' ];
}

function qrrs_rooms_redirect( $status ) {
    wp_safe_redirect( add_query_arg( [ 'tab' => 'rooms', 'rooms_status' => $status ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

/** Create, activate, regenerate QR, delete. Runs early (before page output). */
function qrrs_rooms_handle_post() {
    global $wpdb;
    if ( empty( $_POST['room_action'] ) || ! is_user_logged_in() ) return;
    if ( ! isset( $_POST['rooms_nonce'] ) || ! wp_verify_nonce( $_POST['rooms_nonce'], 'qrrs_rooms' ) ) wp_die( 'Security check failed' );
    if ( ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) qrrs_rooms_redirect( 'error' );

    $rid    = intval( qrrs_inventory_get_active_restaurant_id() );
    $t      = $wpdb->prefix . 'qrrs_rooms';
    $action = sanitize_key( $_POST['room_action'] );
    if ( ! $rid ) qrrs_rooms_redirect( 'no_restaurant' );

    if ( 'add' === $action ) {
        $num = preg_replace( '/[^A-Za-z0-9\-]/', '', sanitize_text_field( wp_unslash( $_POST['room_number'] ?? '' ) ) );
        $cat = sanitize_text_field( wp_unslash( $_POST['room_category'] ?? '' ) );
        $floor = sanitize_text_field( wp_unslash( $_POST['floor'] ?? '' ) );
        if ( '' === $num ) qrrs_rooms_redirect( 'room_number_required' );
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE restaurant_id = %d AND room_number = %s", $rid, $num ) );
        if ( $exists ) qrrs_rooms_redirect( 'room_exists' );
        $wpdb->insert( $t, [
            'restaurant_id' => $rid, 'room_number' => $num, 'room_category' => $cat, 'floor' => $floor,
            'room_token' => wp_generate_password( 32, false, false ), 'status' => 'active',
            'created_at' => current_time( 'mysql' ),
        ] );
        qrrs_rooms_redirect( 'room_created' );
    }

    $id = intval( $_POST['room_id'] ?? 0 );
    if ( 'toggle' === $action ) {
        $cur = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $t WHERE id = %d AND restaurant_id = %d", $id, $rid ) );
        if ( $cur ) $wpdb->update( $t, [ 'status' => 'active' === $cur ? 'inactive' : 'active' ], [ 'id' => $id, 'restaurant_id' => $rid ] );
        qrrs_rooms_redirect( 'room_updated' );
    }
    if ( 'regen' === $action ) {
        $wpdb->update( $t, [ 'room_token' => wp_generate_password( 32, false, false ) ], [ 'id' => $id, 'restaurant_id' => $rid ] );
        qrrs_rooms_redirect( 'qr_regenerated' );
    }
    if ( 'delete' === $action ) {
        $wpdb->delete( $t, [ 'id' => $id, 'restaurant_id' => $rid ] );
        qrrs_rooms_redirect( 'room_deleted' );
    }
    qrrs_rooms_redirect( 'error' );
}
add_action( 'init', 'qrrs_rooms_handle_post', 1 );

/** True when this restaurant has Room service switched on in Online & Delivery. */
function qrrs_room_service_enabled( $restaurant_id ) {
    if ( ! function_exists( 'qrrs_online_services_for' ) || ! intval( $restaurant_id ) ) return false;
    $svc = qrrs_online_services_for( intval( $restaurant_id ) );
    return ! empty( $svc['room_service'] );
}

/** Rooms page (left menu "Rooms"), styled like Table Management. */
function qrrs_rooms_page( $restaurant_id, $can_manage ) {
    global $wpdb;
    $rid = intval( $restaurant_id );
    if ( ! $can_manage ) {
        echo '<div class="qrrs-card" style="padding:20px;">Rooms are for managers and admins only.</div>';
        return;
    }
    $t    = $wpdb->prefix . 'qrrs_rooms';
    $rest = $wpdb->get_row( $wpdb->prepare( "SELECT restaurant_name, online_token FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $rid ) );
    $rooms = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE restaurant_id = %d ORDER BY room_number ASC", $rid ) );
    $base  = add_query_arg( [ 'tab' => 'rooms' ], home_url( '/restaurant-dashboard/' ) );
    $token = $rest ? $rest->online_token : '';
    $hotel = $rest ? $rest->restaurant_name : '';

    $msgs = [
        'room_created'         => [ 'ok',  'Room created. QR code is ready.' ],
        'room_updated'         => [ 'ok',  'Room updated.' ],
        'room_deleted'         => [ 'ok',  'Room deleted.' ],
        'qr_regenerated'       => [ 'ok',  'New QR created. The old QR will stop working.' ],
        'room_exists'          => [ 'err', 'This room number already exists.' ],
        'room_number_required' => [ 'err', 'Room number is required.' ],
        'no_restaurant'        => [ 'err', 'Select a restaurant first.' ],
        'error'                => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    $st = sanitize_key( $_GET['rooms_status'] ?? '' );
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        $bg = 'ok' === $k ? '#ecfdf5' : '#fef2f2';
        $fg = 'ok' === $k ? '#166534' : '#991b1b';
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px;">' . esc_html( $txt ) . '</div>';
    }
    if ( ! $token ) {
        echo '<div style="background:#fffbeb; color:#92400e; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; border:1px solid #fde68a;">Turn on <strong>Online ordering</strong> in Online &amp; Delivery first. Room QR codes need it.</div>';
    }

    $lbl = 'display:block; font-weight:700; font-size:13px; color:#1e293b; margin-bottom:6px;';
    $inp = 'width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:0; margin-bottom:28px; overflow:hidden;">
        <div style="background:#f8fafc; padding:18px 24px; border-bottom:1px solid #e2e8f0;">
            <h3 style="margin:0; font-size:20px; font-weight:600; color:#1e293b;">+ Add New Room for <?php echo esc_html( $hotel ); ?></h3>
        </div>
        <form method="post" action="<?php echo esc_url( $base ); ?>" style="padding:24px;">
            <?php wp_nonce_field( 'qrrs_rooms', 'rooms_nonce' ); ?>
            <input type="hidden" name="room_action" value="add">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px;">
                <div>
                    <label style="<?php echo $lbl; ?>">Current Hotel</label>
                    <input type="text" value="<?php echo esc_attr( $hotel ); ?>" disabled style="<?php echo $inp; ?> background:#f1f5f9; color:#64748b;">
                </div>
                <div>
                    <label style="<?php echo $lbl; ?>">Room Number *</label>
                    <input type="text" name="room_number" required placeholder="e.g. 203" style="<?php echo $inp; ?>">
                </div>
                <div>
                    <label style="<?php echo $lbl; ?>">Category</label>
                    <input type="text" name="room_category" list="room-cats" placeholder="e.g. Deluxe" style="<?php echo $inp; ?>">
                    <datalist id="room-cats"><?php foreach ( qrrs_rooms_categories() as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"><?php endforeach; ?></datalist>
                </div>
                <div>
                    <label style="<?php echo $lbl; ?>">Floor</label>
                    <input type="text" name="floor" placeholder="e.g. 2" style="<?php echo $inp; ?>">
                </div>
            </div>
            <button type="submit" style="margin-top:22px; background:#2271b1; color:#fff; border:none; padding:13px 26px; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer;">Create Room &amp; Generate QR</button>
        </form>
    </div>

    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; padding:18px 24px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
            <h3 style="margin:0; font-size:20px; font-weight:600; color:#1e293b;">Manage Rooms (<?php echo esc_html( $hotel ); ?>)</h3>
            <button type="button" id="rooms-print-all" style="background:#059669; color:#fff; border:none; padding:10px 18px; border-radius:8px; font-weight:600; cursor:pointer;">🖨️ Print All QR Codes</button>
        </div>
        <?php if ( empty( $rooms ) ) : ?>
            <p style="padding:30px; text-align:center; color:#94a3b8; margin:0;">No rooms yet. Add your first room above.</p>
        <?php else : ?>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse;">
            <thead><tr style="text-align:left; font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:#94a3b8;">
                <th style="padding:14px 24px;">Room</th><th style="padding:14px 12px;">Category</th><th style="padding:14px 12px;">Floor</th>
                <th style="padding:14px 12px;">QR</th><th style="padding:14px 12px;">Token</th><th style="padding:14px 12px;">Status</th><th style="padding:14px 24px;">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rooms as $room ) :
                $active = 'active' === $room->status;
                $url = $token ? add_query_arg( [ 't' => $token, 'rt' => $room->room_token ], home_url( '/online-order/' ) ) : '';
                $qr  = $url ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data=' . rawurlencode( $url ) : '';
            ?>
                <tr class="room-row" data-room="<?php echo esc_attr( $room->room_number ); ?>" data-qr="<?php echo esc_attr( $qr ); ?>" data-cat="<?php echo esc_attr( $room->room_category ); ?>" data-floor="<?php echo esc_attr( $room->floor ); ?>" style="border-top:1px solid #f1f5f9; font-size:14px;">
                    <td style="padding:16px 24px; font-weight:700; color:#1e293b;">Room <?php echo esc_html( $room->room_number ); ?></td>
                    <td style="padding:16px 12px; color:#334155;"><?php echo esc_html( $room->room_category ?: '—' ); ?></td>
                    <td style="padding:16px 12px; color:#334155;"><?php echo esc_html( $room->floor ?: '—' ); ?></td>
                    <td style="padding:16px 12px;">
                        <?php if ( $qr && $active ) : ?>
                            <button type="button" class="rooms-print-one" style="background:#f8fafc; border:1px solid #cbd5e1; padding:7px 14px; border-radius:20px; font-size:13px; cursor:pointer; color:#1e293b;">🖨️ Print QR</button>
                        <?php else : ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                    </td>
                    <td style="padding:16px 12px;"><code style="background:#f1f5f9; padding:4px 8px; border-radius:6px; font-size:12px; color:#475569;"><?php echo esc_html( substr( $room->room_token, 0, 12 ) ); ?>…</code></td>
                    <td style="padding:16px 12px;"><?php echo $active ? '<span style="background:#dcfce7; color:#166534; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600;">Active</span>' : '<span style="background:#fee2e2; color:#991b1b; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600;">Inactive</span>'; ?></td>
                    <td style="padding:16px 24px; white-space:nowrap;">
                        <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'qrrs_rooms', 'rooms_nonce' ); ?>
                            <input type="hidden" name="room_action" value="toggle"><input type="hidden" name="room_id" value="<?php echo intval( $room->id ); ?>">
                            <button type="submit" style="background:none; border:none; color:#2271b1; cursor:pointer; font-size:13px; font-weight:600; padding:0 8px 0 0;"><?php echo $active ? 'Deactivate' : 'Activate'; ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:inline;" onsubmit="return confirm('Create a new QR for this room? The old QR will stop working.');">
                            <?php wp_nonce_field( 'qrrs_rooms', 'rooms_nonce' ); ?>
                            <input type="hidden" name="room_action" value="regen"><input type="hidden" name="room_id" value="<?php echo intval( $room->id ); ?>">
                            <button type="submit" style="background:none; border:none; color:#2271b1; cursor:pointer; font-size:13px; font-weight:600; padding:0 8px 0 0;">New QR</button>
                        </form>
                        <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:inline;" onsubmit="return confirm('Delete this room?');">
                            <?php wp_nonce_field( 'qrrs_rooms', 'rooms_nonce' ); ?>
                            <input type="hidden" name="room_action" value="delete"><input type="hidden" name="room_id" value="<?php echo intval( $room->id ); ?>">
                            <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:13px; font-weight:600; padding:0;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <script>
    (function () {
        var hotel = <?php echo wp_json_encode( $hotel ); ?>;
        function sheet(rows) {
            var w = window.open('', '_blank');
            var html = '<html><head><title>Room QR</title><style>body{font-family:sans-serif;margin:0;padding:16px} .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px} .card{border:2px solid #111;border-radius:12px;padding:14px;text-align:center;page-break-inside:avoid} .card img{width:100%;max-width:220px} .h{font-size:22px;font-weight:800;margin-top:6px} .s{font-size:13px;color:#444} @media print{.grid{grid-template-columns:repeat(2,1fr)}}</style></head><body><div class="grid">';
            rows.forEach(function (r) {
                html += '<div class="card"><div class="s">' + String(hotel).replace(/</g,'&lt;') + '</div><img src="' + r.qr + '"><div class="h">Room ' + r.room + '</div><div class="s">' + (r.cat ? r.cat + ' · ' : '') + (r.floor ? 'Floor ' + r.floor : '') + '</div><div class="s">Scan to order from your room</div></div>';
            });
            html += '</div><script>window.onload=function(){setTimeout(function(){window.print();},300);};<\/script></body></html>';
            w.document.write(html); w.document.close();
        }
        function rowData(tr) { return { room: tr.dataset.room, qr: tr.dataset.qr, cat: tr.dataset.cat, floor: tr.dataset.floor }; }
        document.querySelectorAll('.rooms-print-one').forEach(function (b) {
            b.addEventListener('click', function () { sheet([rowData(b.closest('tr'))]); });
        });
        var all = document.getElementById('rooms-print-all');
        if (all) all.addEventListener('click', function () {
            var rows = [].slice.call(document.querySelectorAll('.room-row')).filter(function (tr) { return tr.dataset.qr; }).map(rowData);
            if (!rows.length) { alert('No active rooms with QR codes to print.'); return; }
            sheet(rows);
        });
    })();
    </script>
    <?php
}

/** Save actions used by the Online & Delivery page (kept for compatibility, now unused there). */
function qrrs_rooms_save( $restaurant_id, $action ) {
    return 'error';
}