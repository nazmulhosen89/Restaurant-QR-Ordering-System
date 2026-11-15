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
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        
        <!-- Header with Status Badge -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="material-icons-outlined" style="color:#2563eb; font-size:24px;">verified</span>
                <h2 style="margin:0; font-size:18px; color:#1e293b; font-weight:700;">DineX Restro License</h2>
            </div>
            <div>
                <span style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:20px; font-size:12px; font-weight:700; background:<?php echo $is_pro ? '#dcfce7; color:#166534; border:1px solid #bbf7d0;' : '#f1f5f9; color:#475569; border:1px solid #cbd5e1;'; ?>">
                    <span style="width:7px; height:7px; border-radius:50%; background:<?php echo $is_pro ? '#22c55e' : '#64748b'; ?>;"></span>
                    Status: <?php echo $is_pro ? 'Pro Active' : 'Free Version'; ?>
                </span>
            </div>
        </div>

        <p style="color:#64748b; font-size:13px; margin:0 0 20px; line-height:1.5;">
            Free is limited but usable. Pro is a one-time lifetime unlock for the full restaurant automation system.
        </p>

        <!-- Plan Comparison Cards -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:15px; margin-bottom:20px;">
            <!-- Free Plan Card -->
            <div style="border:1px solid #e2e8f0; border-radius:10px; padding:18px; background:#f8fafc; display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <h3 style="margin:0 0 10px; font-size:15px; color:#334155; font-weight:700;">Free Plan</h3>
                    <ul style="margin:0; padding-left:16px; color:#64748b; font-size:13px; line-height:1.6;">
                        <li>1 restaurant</li>
                        <li>1 manager, 1 waiter, 1 kitchen user</li>
                        <li>Sales report only, last 30 days</li>
                        <li>No inventory, recipe, delivery</li>
                    </ul>
                </div>
            </div>

            <!-- Pro Plan Card -->
            <div style="border:1px solid #fed7aa; border-radius:10px; padding:18px; background:linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%); display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <h3 style="margin:0 0 10px; font-size:15px; color:#c2410c; font-weight:700;">🚀 Pro Lifetime</h3>
                    <ul style="margin:0; padding-left:16px; color:#9a3412; font-size:13px; line-height:1.6;">
                        <li>Multi restaurant support</li>
                        <li>Unlimited staff members</li>
                        <li>Inventory, recipe, wastage & full reports</li>
                        <li>One-time payment, lifetime access</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- License Activation Form -->
        <form method="post" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:20px;">
            <?php wp_nonce_field( 'qrrs_pro_license_action', 'qrrs_pro_license_nonce' ); ?>
            <label style="font-weight:600; font-size:13px; color:#475569; display:block; margin-bottom:8px;">Pro License Key</label>
            <input type="text" name="qrrs_pro_license_key" value="<?php echo esc_attr( get_option( QRRS_LICENSE_OPTION_KEY, '' ) ); ?>" placeholder="Enter your license key here..." style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
            
            <div style="display:flex; gap:10px; margin-top:14px; flex-wrap:wrap; align-items:center;">
                <button type="submit" name="qrrs_activate_pro_license" class="qrrs-btn-save" style="height:40px; padding:0 20px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Activate Pro</button>
                <button type="submit" name="qrrs_switch_free_license" class="button" style="height:40px; padding:0 16px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; border-radius:8px; font-weight:600; cursor:pointer;">Switch to Free</button>
            </div>
        </form>

        <!-- Local Test Key Box -->
        <div style="margin-top:15px; padding:15px; background:#fff; border:1px dashed #cbd5e1; border-radius:8px;">
            <span style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Local test key (for trial/development):</span>
            <code style="display:block; white-space:nowrap; overflow-x:auto; padding:6px 10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:11px; color:#0f172a;"><?php echo esc_html( $demo_key ); ?></code>
            <small style="color:#64748b; font-size:11px; display:block; margin-top:6px;">For production, this should be generated by your purchase/license server after payment.</small>
        </div>
    </div>
    <?php
}