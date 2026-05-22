<?php
// includes/reports/reports.php

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$user_id = get_current_user_id();
$active_res_id = 0;

// ১. Restaurant ID logic (Admin vs Staff)
if ( current_user_can('administrator') ) {
    // Admin hole dashboard logic onujayi session theke active ID nibo
    $active_res_id = isset( $_SESSION['qrrs_active_res_id'] ) ? intval( $_SESSION['qrrs_active_res_id'] ) : 0;
} else {
    // Staff-er jonno apnar custom table 'qrrs_staff' theke assigned ID nibo
    $staff_table = $wpdb->prefix . 'qrrs_staff'; 
    $active_res_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT restaurant_id FROM $staff_table WHERE user_id = %d AND status = 'active' LIMIT 1", 
        $user_id
    ) );
}

// ২. Access Control
if ( ! $active_res_id ) {
    echo '<div style="padding: 20px; background: #fff5f5; border-left: 5px solid #ff4d4d; border-radius: 5px; margin-top: 20px;">
            <h3 style="color:#d32f2f; margin-top:0;">⚠️ Access Denied</h3>
            <p>No restaurant is currently assigned to your account. Please contact the administrator.</p>
          </div>';
    return;
}

// Active name for header display
$active_res_name = get_qrrs_active_restaurant_name();
?>

<div class="qrrs-reports-wrapper">
    <div class="reports-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <h2 style="margin:0;">Reports Dashboard</h2>
        <!-- <div class="active-res-label" style="background: #e8f5e9; color: #2e7d32; padding: 6px 15px; border-radius: 50px; font-weight: 600; border: 1px solid #c8e6c9; font-size: 14px;">
            📍 Active: <?php echo esc_html($active_res_name); ?>
        </div> -->
    </div>

    <!-- Navigation Cards -->
    <!-- <div class="report-nav-grid">
        <div class="report-card active" data-target="overview">
            <div class="card-icon">📊</div>
            <div class="card-info"><h3>Overview</h3><p>Today's Summary</p></div>
        </div>
        <div class="report-card" data-target="sales">
            <div class="card-icon">💰</div>
            <div class="card-info"><h3>Sales Report</h3><p>Revenue & Growth</p></div>
        </div>
        <div class="report-card" data-target="orders">
            <div class="card-icon">🛒</div>
            <div class="card-info"><h3>Order Report</h3><p>Statistics</p></div>
        </div>
        <div class="report-card" data-target="items">
            <div class="card-icon">🍔</div>
            <div class="card-info"><h3>Item Performance</h3><p>Best Sellers</p></div>
        </div>
        <div class="report-card" data-target="staff">
            <div class="card-icon">👥</div>
            <div class="card-info"><h3>Staff Report</h3><p>Employee Activity</p></div>
        </div>
        <div class="report-card" data-target="payment">
            <div class="card-icon">💳</div>
            <div class="card-info"><h3>Payment Report</h3><p>Method Types</p></div>
        </div>
    </div> -->

    <div class="report-nav-grid">
        <div class="report-card active" data-target="overview">
            <div class="card-icon">📊</div>
            <div class="card-info"><h3>Overview</h3><p>Summary of all</p></div>
        </div>
        <div class="report-card" data-target="sales">
            <div class="card-icon">💰</div>
            <div class="card-info"><h3>Sales Report</h3><p>Income & Revenue</p></div>
        </div>
        <div class="report-card" data-target="orders">
            <div class="card-icon">🛒</div>
            <div class="card-info"><h3>Order Report</h3><p>Order Statistics</p></div>
        </div>
        <!-- <div class="report-card" data-target="items">
            <div class="card-icon">🍔</div>
            <div class="card-info"><h3>Item Performance</h3><p>Best Sellers</p></div>
        </div> -->
        <div class="report-card" data-target="kitchen">
            <div class="card-icon">🍳</div>
            <div class="card-info"><h3>Kitchen/KOT</h3><p>Prep Time & Efficiency</p></div>
        </div>
        <div class="report-card" data-target="staff">
            <div class="card-icon">👥</div>
            <div class="card-info"><h3>Staff Report</h3><p>Performance tracking</p></div>
        </div>
        <div class="report-card" data-target="payment">
            <div class="card-icon">💳</div>
            <div class="card-info"><h3>Payment Report</h3><p>Method Breakdown</p></div>
        </div>
        <div class="report-card" data-target="tax">
            <div class="card-icon">📜</div>
            <div class="card-info"><h3>VAT/Service</h3><p>Tax & Charges</p></div>
        </div>
    </div>

    <hr class="section-divider" style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">

    <!-- Dynamic Content Area -->
    <div class="report-content-area">
        <?php 
        $sections = ['overview', 'sales', 'orders', 'items', 'kitchen', 'staff', 'payment', 'tax'];

        foreach ($sections as $section) {
            $class = ($section === 'overview') ? 'active' : '';
            echo '<div id="section-' . $section . '" class="report-tab-content ' . $class . '">';
            
            // Section files include korchi (Ekhane $active_res_id accessible thakbe)
            $file_path = QRRS_PATH . 'includes/reports/sections/' . $section . '.php';
            if ( file_exists($file_path) ) {
                include $file_path; 
            } else {
                echo "<p style='color:#999;'>Coming Soon: " . ucfirst($section) . " Module</p>";
            }
            
            echo '</div>';
        }
        ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('.report-card').on('click', function() {
        var target = $(this).data('target');
        
        // Tab styling change
        $('.report-card').removeClass('active');
        $(this).addClass('active');
        
        // Content switch
        $('.report-tab-content').removeClass('active');
        $('#section-' + target).addClass('active');
    });
});
</script>

<style>
    
</style>