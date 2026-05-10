<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * লাইসেন্স চেক ফাংশন
 */
function qrrs_check_system_license() {
    $license_expiry = '2026-12-30'; // ডেভেলপার সেটিংস: এখানে ডেট বসান
    
    $today = current_time('Y-m-d');
    $expire_date = date('Y-m-d', strtotime($license_expiry));
    
    $date1 = date_create($today);
    $date2 = date_create($expire_date);
    $diff = date_diff($date1, $date2);
    $days_left = (int)$diff->format("%r%a");

    return [
        'is_expired' => ($days_left <= 0),
        'days_left'  => $days_left,
        'expiry_date'=> $expire_date
    ];
}

/**
 * লাইসেন্স শেষ হলে কন্টেন্ট লক করার হ্যান্ডলার
 */
function qrrs_handle_license_restriction() {
    $license = qrrs_check_system_license();

    // যদি লাইসেন্স শেষ হয়ে যায়
    if ( $license['is_expired'] ) {
        
        // আপনার প্লাগইনের শর্টকোডগুলো চিহ্নিত করা
        $shortcodes = [
            'qrrs_admin_dashboard', 
            'qrrs_waiter_dashboard', 
            'qrrs_kitchen_dashboard', 
            'qrrs_billing_counter', 
            'qrrs_digital_menu'
        ];

        foreach ( $shortcodes as $shortcode ) {
            // আসল শর্টকোডটি সরিয়ে আমাদের লক স্ক্রিন শর্টকোড বসিয়ে দিচ্ছি
            remove_shortcode( $shortcode );
            add_shortcode( $shortcode, 'qrrs_show_license_lock_screen' );
        }
    }
}
add_action( 'init', 'qrrs_handle_license_restriction' );

/**
 * লক স্ক্রিন UI জেনারেটর
 */
function qrrs_show_license_lock_screen() {
    $license = qrrs_check_system_license();
    ob_start();
    ?>
    <div class="qrrs-license-lock-container">
        <div class="qrrs-lock-overlay"></div>
        <div class="qrrs-lock-card">
            <div style="text-align: center; margin-bottom: 20px;">
                <span class="material-icons-outlined" style="font-size: 64px; color: #e53e3e;">lock_clock</span>
                <h2 style="color: #2d3748; margin: 10px 0;">License Expired!</h2>
                <p style="color: #718096;">Your license ended on <strong><?php echo date('d M, Y', strtotime($license['expiry_date'])); ?></strong></p>
                <p style="color: #a0aec0; font-size: 14px;">please choose a renewal package from the options below.</p>
            </div>

            <hr style="border: 0; border-top: 1px solid #edf2f7; margin: 25px 0;">

            <!-- Subscription Form Load -->
            <div class="qrrs-renewal-section">
                <?php 
                // subscription.php ফাইলটি লোড করা হচ্ছে যাতে ইউজার এখান থেকেই রিকোয়েস্ট পাঠাতে পারে
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