<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * License Secret Key
 */
define('QRRS_LICENSE_SECRET', 'qrrs_#Na2m1_$ecret_S@lt_2024');

/**
 * License চেক করার main function (With 5 Days Auto-Trial Support)
 */
function qrrs_check_system_license() {
    $license_key = get_option('qrrs_license_key', '');

    // ১. লাইসেন্স কি খালি থাকলে ৫ দিনের অটো-ট্রায়াল লজিক
    if ( empty($license_key) ) {
        $installed_at = get_option('qrrs_plugin_installed_at', '');

        if ( empty($installed_at) ) {
            $wp_timezone = wp_timezone();
            $local_now   = new DateTime('now', $wp_timezone);
            $installed_at = $local_now->format('Y-m-d H:i:s');
            update_option( 'qrrs_plugin_installed_at', $installed_at );
        }

        $wp_timezone = wp_timezone();
        $local_now   = new DateTime('now', $wp_timezone);
        
        $install_date = new DateTime($installed_at, $wp_timezone);
        $trial_expiry = clone $install_date;
        $trial_expiry->modify('+5 days'); 

        if ($local_now < $trial_expiry) {
            $diff = $local_now->diff($trial_expiry);
            $days_left = $diff->days; 
            
            return [
                'is_expired'  => false, 
                'days_left'   => $days_left === 0 ? 1 : $days_left, 
                'expiry_date' => $trial_expiry->format('Y-m-d'),
                'error'       => '',
                'mode'        => 'trial' 
            ];
        }

        return [
            'is_expired'  => true,
            'days_left'   => 0,
            'expiry_date' => $trial_expiry->format('Y-m-d'),
            'error'       => 'Your 5-day free trial has expired. Please enter a valid license key.',
            'mode'        => 'demo'
        ];
    }

    // ২. লাইসেন্স কি থাকলে এক্সটার্নাল জেনারেটরের কি ভেরিফাই হবে
    return qrrs_verify_license_key( $license_key );
}

/**
 * Encrypted license key verify 
 */
function qrrs_verify_license_key( $license_key ) {
    $decoded = base64_decode( $license_key );
    $parts   = explode( '|', $decoded );

    if ( count($parts) !== 3 ) {
        return [
            'is_expired'  => true,
            'days_left'   => 0,
            'expiry_date' => '',
            'error'       => 'Invalid key format',
            'mode'        => 'key'
        ];
    }

    [$domain, $expiry, $hash] = $parts;

    $expected_hash = hash_hmac( 'sha256', $domain . '|' . $expiry, QRRS_LICENSE_SECRET );
    if ( ! hash_equals( $expected_hash, $hash ) ) {
        return [
            'is_expired'  => true,
            'days_left'   => 0,
            'expiry_date' => '',
            'error'       => 'Key integrity check failed',
            'mode'        => 'key'
        ];
    }

    $current_domain = preg_replace( '/^www\./', '', $_SERVER['HTTP_HOST'] ?? '' );
    if ( strcasecmp( $domain, $current_domain ) !== 0 ) {
        return [
            'is_expired'  => true,
            'days_left'   => 0,
            'expiry_date' => '',
            'error'       => 'License key is registered for another domain: ' . esc_html($domain),
            'mode'        => 'key'
        ];
    }

    $wp_timezone = wp_timezone();
    $local_now   = new DateTime('now', $wp_timezone);
    $expiry_date = new DateTime($expiry . ' 23:59:59', $wp_timezone);

    if ( $local_now > $expiry_date ) {
        return [
            'is_expired'  => true,
            'days_left'   => 0,
            'expiry_date' => $expiry,
            'error'       => 'Subscription expired on ' . $expiry,
            'mode'        => 'expired'
        ];
    }

    $diff = $local_now->diff($expiry_date);
    return [
        'is_expired'  => false,
        'days_left'   => $diff->days,
        'expiry_date' => $expiry,
        'error'       => '',
        'mode'        => 'active'
    ];
}

/**
 * Render system block if expired or missing license key
 */
function qrrs_render_license_lock_screen() {
    $license = qrrs_check_system_license();
    
    $badge_color = $license['is_expired'] ? '#ef4444' : '#22c55e';
    $status_text = $license['is_expired'] ? 'Restricted' : 'Active';
    
    if ( isset($license['mode']) && $license['mode'] === 'trial' ) {
        $badge_color = '#3b82f6'; 
        $status_text = 'Free Trial';
    }

    ob_start();
    ?>
    <div style="max-width:850px; margin:60px auto; font-family: 'Segoe UI', system-ui, sans-serif;">
        <div style="background:#ffffff; border-radius:16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); padding:40px; text-align:center; border:1px solid #e2e8f0;">
            
            <div style="display:inline-block; background:<?php echo $badge_color; ?>15; color:<?php echo $badge_color; ?>; padding:6px 14px; border-radius:20px; font-weight:600; font-size:13px; margin-bottom:20px;">
                ● System Status: <?php echo $status_text; ?>
            </div>

            <h2 style="font-size:28px; color:#1e293b; margin:0 0 10px 0; font-weight:700;">
                <?php echo $license['is_expired'] ? 'System Access Restricted' : 'Welcome to Premium POS System'; ?>
            </h2>

            <?php if ( !$license['is_expired'] ) : ?>
                <p style="color:#475569; font-size:15px; margin-bottom:24px;">
                    Your subscription is currently active. <strong><?php echo intval($license['days_left']); ?> days remaining</strong> until renewal.
                </p>
            <?php else : ?>
                <p style="color:#ef4444; font-size:15px; margin-bottom:24px; font-weight: 500;">
                    License Status: <strong><?php echo esc_html($license['error']); ?></strong>
                </p>
            <?php endif; ?>

            <?php if ( !empty($license['expiry_date']) ) : ?>
                <p style="color:#64748b; font-size:14px; margin-bottom:4px;">
                    Expiry Target: <strong><?php echo date_i18n(get_option('date_format'), strtotime($license['expiry_date'])); ?></strong>
                </p>
            <?php endif; ?>

            <p style="color:#94a3b8; font-size:13px; margin-bottom:24px;">
                Please secure or renew your license configuration to continue operations.
            </p>

            <div style="background:#f1f5f9; border-radius:10px; padding:16px; text-align:left;">
                <?php
                $subscription_file = QRRS_PATH . 'includes/subscriptions/subscription.php';
                if ( file_exists( $subscription_file ) ) {
                    include $subscription_file;
                }
                ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * AJAX License Lock enforcement 
 */
function qrrs_ajax_license_check() {
    $license = qrrs_check_system_license();
    if ( $license['is_expired'] ) {
        wp_send_json_error( 'License expired. Please renew to continue.' );
        exit;
    }
}