<?php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'QRRS_LICENSE_OPTION_TYPE', 'qrrs_license_type' );
define( 'QRRS_LICENSE_OPTION_KEY', 'qrrs_license_key' );
define( 'QRRS_LICENSE_OPTION_STATUS', 'qrrs_license_status' );

function qrrs_get_license_type() {
    $type = get_option( QRRS_LICENSE_OPTION_TYPE, 'free' );
    return in_array( $type, [ 'free', 'pro' ], true ) ? $type : 'free';
}

function qrrs_is_pro() {
    return qrrs_get_license_type() === 'pro' && get_option( QRRS_LICENSE_OPTION_STATUS, 'inactive' ) === 'active';
}

function qrrs_get_plan_limits() {
    if ( qrrs_is_pro() ) {
        return [
            'restaurants' => -1,
            'qr_manager'  => -1,
            'qr_waiter'   => -1,
            'qr_kitchen'  => -1,
            'inventory'   => true,
            'recipe'      => true,
            'delivery'    => true,
            'reports'     => 'full',
        ];
    }

    return [
        'restaurants' => 1,
        'qr_manager'  => 1,
        'qr_waiter'   => 1,
        'qr_kitchen'  => 1,
        'inventory'   => false,
        'recipe'      => false,
        'delivery'    => false,
        'reports'     => 'sales_30_days',
    ];
}

function qrrs_can_use_feature( $feature ) {
    $limits = qrrs_get_plan_limits();

    if ( qrrs_is_pro() ) {
        return true;
    }

    switch ( $feature ) {
        case 'inventory':
        case 'recipe':
        case 'delivery':
        case 'full_reports':
            return ! empty( $limits[ $feature ] );
        case 'sales_report':
        case 'tables':
        case 'orders':
        case 'kot':
        case 'billing':
            return true;
    }

    return false;
}

function qrrs_count_restaurants() {
    global $wpdb;
    return intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_restaurants" ) );
}

function qrrs_count_staff_by_role( $restaurant_id, $role ) {
    global $wpdb;
    return intval(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_staff WHERE restaurant_id = %d AND staff_role = %s AND status = 'active'",
                intval( $restaurant_id ),
                sanitize_key( $role )
            )
        )
    );
}

function qrrs_free_limit_reached( $limit_key, $restaurant_id = 0 ) {
    if ( qrrs_is_pro() ) {
        return false;
    }

    $limits = qrrs_get_plan_limits();
    $limit = isset( $limits[ $limit_key ] ) ? intval( $limits[ $limit_key ] ) : -1;

    if ( $limit < 0 ) {
        return false;
    }

    if ( 'restaurants' === $limit_key ) {
        return qrrs_count_restaurants() >= $limit;
    }

    if ( in_array( $limit_key, [ 'qr_manager', 'qr_waiter', 'qr_kitchen' ], true ) ) {
        return qrrs_count_staff_by_role( $restaurant_id, $limit_key ) >= $limit;
    }

    return false;
}

function qrrs_render_upgrade_notice( $feature_label = 'This feature' ) {
    ?>
    <div class="qrrs-upgrade-lock" style="background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:18px; border-radius:10px; margin:15px 0;">
        <h3 style="margin:0 0 8px; color:#9a3412;"><?php echo esc_html( $feature_label ); ?> is available in Pro</h3>
        <p style="margin:0 0 12px;">Free version keeps this option visible so restaurants can see what is available. Upgrade once to unlock the full system.</p>
        <a href="<?php echo esc_url( home_url( '/restaurant-dashboard/?tab=license' ) ); ?>" class="qrrs-btn-save" style="display:inline-block; text-decoration:none; background:#f97316; color:#fff; padding:10px 14px; border-radius:7px; font-weight:700;">Upgrade to Pro</a>
    </div>
    <?php
}

function qrrs_free_report_ajax_guard( $report_label ) {
    if ( qrrs_is_pro() ) {
        return false;
    }

    ob_start();
    qrrs_render_upgrade_notice( $report_label );
    $html = ob_get_clean();

    wp_send_json_success( [ 'data' => $html ] );
    return true;
}

function qrrs_generate_local_pro_key( $email = '' ) {
    $domain = preg_replace( '/^www\./', '', sanitize_text_field( $_SERVER['HTTP_HOST'] ?? home_url() ) );
    $payload = $domain . '|' . sanitize_email( $email ) . '|lifetime';
    $hash = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    return base64_encode( $payload . '|' . $hash );
}

function qrrs_verify_local_pro_key( $key ) {
    $decoded = base64_decode( sanitize_text_field( $key ), true );
    if ( ! $decoded ) {
        return false;
    }

    $parts = explode( '|', $decoded );
    if ( count( $parts ) !== 4 ) {
        return false;
    }

    [ $domain, $email, $license_type, $hash ] = $parts;
    $expected = hash_hmac( 'sha256', $domain . '|' . $email . '|' . $license_type, wp_salt( 'auth' ) );
    $current_domain = preg_replace( '/^www\./', '', sanitize_text_field( $_SERVER['HTTP_HOST'] ?? '' ) );

    return $license_type === 'lifetime' && hash_equals( $expected, $hash ) && strcasecmp( $domain, $current_domain ) === 0;
}

function qrrs_handle_license_post() {
    if ( ! current_user_can( 'administrator' ) ) {
        return;
    }

    if ( isset( $_POST['qrrs_activate_pro_license'] ) ) {
        check_admin_referer( 'qrrs_pro_license_action', 'qrrs_pro_license_nonce' );
        $key = sanitize_text_field( $_POST['qrrs_pro_license_key'] ?? '' );

        if ( qrrs_verify_local_pro_key( $key ) ) {
            update_option( QRRS_LICENSE_OPTION_TYPE, 'pro' );
            update_option( QRRS_LICENSE_OPTION_STATUS, 'active' );
            update_option( QRRS_LICENSE_OPTION_KEY, $key );
            update_option( 'qrrs_license_activated_at', current_time( 'mysql' ) );
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-success"><p>NeXt Restro Pro activated.</p></div>';
            } );
        } else {
            update_option( QRRS_LICENSE_OPTION_STATUS, 'inactive' );
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Invalid Pro license key for this domain.</p></div>';
            } );
        }
    }

    if ( isset( $_POST['qrrs_switch_free_license'] ) ) {
        check_admin_referer( 'qrrs_pro_license_action', 'qrrs_pro_license_nonce' );
        update_option( QRRS_LICENSE_OPTION_TYPE, 'free' );
        update_option( QRRS_LICENSE_OPTION_STATUS, 'inactive' );
    }
}

function qrrs_render_license_page() {
    qrrs_handle_license_post();
    $is_pro = qrrs_is_pro();
    $demo_key = qrrs_generate_local_pro_key( wp_get_current_user()->user_email );
    ?>
    <div class="qrrs-card" style="padding:24px;">
        <h2 style="margin-top:0;">DineX Restro License</h2>
        <p style="color:#64748b;">Free is limited but usable. Pro is a one-time lifetime unlock for the full restaurant automation system.</p>

        <div style="display:grid; grid-template-columns:repeat(2,minmax(220px,1fr)); gap:16px; margin:20px 0;">
            <div style="border:1px solid #e2e8f0; border-radius:10px; padding:18px; background:#fff;">
                <h3>Free</h3>
                <ul>
                    <li>1 restaurant</li>
                    <li>1 manager, 1 waiter, 1 kitchen user</li>
                    <li>Sales report only, last 30 days</li>
                    <li>No inventory, recipe, delivery</li>
                </ul>
            </div>
            <div style="border:1px solid #fed7aa; border-radius:10px; padding:18px; background:#fff7ed;">
                <h3>Pro Lifetime</h3>
                <ul>
                    <li>Multi restaurant</li>
                    <li>Unlimited staff</li>
                    <li>Inventory, recipe, wastage, full reports</li>
                    <li>One-time payment</li>
                </ul>
            </div>
        </div>

        <p><strong>Current status:</strong> <?php echo $is_pro ? 'Pro Active' : 'Free'; ?></p>

        <form method="post" style="max-width:680px;">
            <?php wp_nonce_field( 'qrrs_pro_license_action', 'qrrs_pro_license_nonce' ); ?>
            <label style="font-weight:700; display:block; margin-bottom:8px;">Pro License Key</label>
            <input type="text" name="qrrs_pro_license_key" value="<?php echo esc_attr( get_option( QRRS_LICENSE_OPTION_KEY, '' ) ); ?>" style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px;">
            <div style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">
                <button type="submit" name="qrrs_activate_pro_license" class="qrrs-btn-save">Activate Pro</button>
                <button type="submit" name="qrrs_switch_free_license" class="button">Switch to Free</button>
            </div>
        </form>

        <div style="margin-top:20px; padding:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
            <strong>Local test key:</strong>
            <code style="display:block; white-space:normal; margin-top:8px;"><?php echo esc_html( $demo_key ); ?></code>
            <small>For production, this should be generated by your purchase/license server after payment.</small>
        </div>
    </div>
    <?php
}
