<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Access control: Permission check
 */
QRRS_Auth::has_permission('qr_manager'); 

// 1. License & Subscription Check
$license = qrrs_check_system_license(); 

if ( $license['is_expired'] ) {
    wp_die('
        <div style="text-align:center; margin-top:50px; font-family:sans-serif; padding:20px;">
            <div style="font-size:50px;">❌</div>
            <h1 style="color:#dc3545; margin-top:10px;">System Deactivated!</h1>
            <p style="font-size:18px; color:#555;">Your subscription expired on <strong>' . date('d M, Y', strtotime($license['expiry_date'])) . '</strong>.</p>
            <p>Please contact the developer for renewal.</p>
            <div style="margin-top:30px; padding:15px; background:#f8f9fa; display:inline-block; border-radius:8px;">
                <strong>Developer Support:</strong><br>Nazmul Hosen
            </div>
        </div>
    ');
}

global $wpdb;
$user_id = get_current_user_id();

// URL theke current tab neya
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : (current_user_can('administrator') ? 'restaurants' : 'orders');
?>

<div class="qrrs-dashboard-container">
    
    <?php if ( $license['days_left'] <= 15 && $license['days_left'] > 0 ) : ?>
        <div class="qrrs-license-alert">
            <div class="alert-content">
                <strong>⚠️ Renewal Required:</strong> The system will deactivate in <strong><?php echo $license['days_left']; ?> days</strong>. 
                Please renew before <?php echo date('d M, Y', strtotime($license['expiry_date'])); ?>.
            </div>
        </div>
    <?php endif; ?>

    <header class="qrrs-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom: 2px solid #f1f1f1; padding-bottom:15px; margin-bottom:25px;">
    <h1>Restaurant Management</h1>
    
    <div class="user-actions" style="display:flex; align-items:center; gap:20px;">
        <div class="kitchen-user-nav" style="position:relative; display:inline-block;">
            <div style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:5px 12px; border-radius:30px; background:#f8f9fa; border:1px solid #eee;" onclick="jQuery('#user-dropdown').toggle();">
                <div style="width:32px; height:32px; background:#222; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:13px;">
                    <?php echo strtoupper(substr(wp_get_current_user()->display_name, 0, 1)); ?>
                </div>
                <div style="text-align:left; line-height:1.2;">
                    <span style="display:block; font-size:13px; font-weight:700; color:#2c3e50;"><?php echo wp_get_current_user()->display_name; ?></span>
                    <small style="color:#95a5a6; font-size:10px; text-transform: capitalize;">
                        <?php echo wp_get_current_user()->roles[0]; ?>
                    </small>
                </div>
                <!-- <span style="font-size:10px; color:#999;">▼</span> -->
            </div>

            <div id="user-dropdown" style="display:none; position:absolute; right:0; top:45px; background:#fff; min-width:180px; box-shadow:0 10px 25px rgba(0,0,0,0.1); border-radius:8px; z-index:1000; border:1px solid #eee; overflow:hidden;">
                <a href="?tab=profile" style="display:flex; align-items:center; gap:10px; padding:12px 15px; color:#2c3e50; text-decoration:none; font-size:13px; border-bottom:1px solid #f1f1f1; transition:0.2s;" onmouseover="this.style.background='#f8f9fa';" onmouseout="this.style.background='#fff';">
                    <span style="font-size:16px;">👤</span> My Profile
                </a>
                
                <?php if ( current_user_can( 'administrator' ) ) : ?>
                <a href="?tab=subscriptions" style="display:flex; align-items:center; gap:10px; padding:12px 15px; color:#2c3e50; text-decoration:none; font-size:13px; border-bottom:1px solid #f1f1f1; transition:0.2s;" onmouseover="this.style.background='#f8f9fa';" onmouseout="this.style.background='#fff';">
                    <span style="font-size:16px;">🔑</span> License Info
                </a>
                <?php endif; ?>

                <a href="<?php echo wp_logout_url(home_url('/restaurant-login/')); ?>" style="display:flex; align-items:center; gap:10px; padding:12px 15px; color:#e74c3c; text-decoration:none; font-size:13px; font-weight:600; transition:0.2s;" onmouseover="this.style.background='#fff5f5';" onmouseout="this.style.background='#fff';">
                    <span style="font-size:16px;">👋</span> Logout
                </a>
            </div>
        </div>
    </div>
</header>

    <div class="qrrs-grid">
        <aside class="qrrs-sidebar">
            <ul>
                <li><a href="?tab=take-order" class="<?php echo $current_tab == 'take-order' ? 'active' : ''; ?>">Take an Order</a></li>
                <li><a href="?tab=orders" class="<?php echo $current_tab == 'orders' ? 'active' : ''; ?>">Order Management</a></li>
                <li><a href="?tab=tables" class="<?php echo $current_tab == 'tables' ? 'active' : ''; ?>">Table Management</a></li>
                <li><a href="?tab=billing" class="<?php echo $current_tab == 'billing' ? 'active' : ''; ?>">Billing & POS</a></li>
                <li><a href="?tab=reports" class="<?php echo $current_tab == 'reports' ? 'active' : ''; ?>">Sales Reports</a></li>
                <li><a href="?tab=categories" class="<?php echo $current_tab == 'categories' ? 'active' : ''; ?>">Categories</a></li>
                <li><a href="?tab=items" class="<?php echo $current_tab == 'items' ? 'active' : ''; ?>">Items</a></li>
                
                <?php if ( current_user_can( 'administrator' ) ) : ?>
                    <li class="admin-menu-item"><a href="?tab=restaurants" class="<?php echo $current_tab == 'restaurants' ? 'active' : ''; ?>">Manage Restaurants</a></li>
                    <li class="admin-menu-item"><a href="?tab=add-staff" class="<?php echo $current_tab == 'add-staff' ? 'active' : ''; ?>">Create Staff/User</a></li>
                    <li class="admin-menu-item"><a href="?tab=subscriptions" class="<?php echo $current_tab == 'subscriptions' ? 'active' : ''; ?>">System License</a></li>
                <?php endif; ?>
            </ul>
        </aside>

        <main class="qrrs-main-content">
            <?php 
            switch ($current_tab) {
                case 'restaurants':
                    QRRS_Auth::is_admin_only();
                    include QRRS_PATH . 'includes/restaurant/restaurant-create.php';
                    break;

                case 'add-staff':
                    QRRS_Auth::is_admin_only();
                    include QRRS_PATH . 'includes/restaurant/add-staff.php';
                    break;

                case 'tables':
                    include QRRS_PATH . 'includes/restaurant/tables.php';
                    break;

                case 'categories':
                    include QRRS_PATH . 'includes/menu/category.php';
                    break;

                case 'take-order':
                    include QRRS_PATH . 'includes/order/take-order.php';
                    break;

                case 'orders':
                    include QRRS_PATH . 'templates/dashboard/orders.php';
                    break;

                // Billing & POS Tab Added
                case 'billing':
                    include QRRS_PATH . 'includes/billing/payment.php';
                    break;

                case 'reports':
                    include QRRS_PATH . 'includes/reports/reports.php';
                    break;

                case 'profile':
                    include QRRS_PATH . 'includes/user/profile.php';
                    break;

                case 'items':
                    include QRRS_PATH . 'includes/menu/items.php';
                    break;

                case 'subscriptions':
                    QRRS_Auth::is_admin_only();
                    include QRRS_PATH . 'includes/subscriptions/subscription.php';
                    break;

                default:
                    echo "<h3>Welcome to the Dashboard. Please select a module.</h3>";
                    break;
            }
            ?>
        </main>
    </div>
</div>


<script>
jQuery(document).ready(function($){
    // Close dropdown when clicking outside
    $(window).on('click', function(event) {
        if (!$(event.target).closest('.kitchen-user-nav').length) {
            $('#user-dropdown').hide();
        }
    });
});
</script>
<style>
    .qrrs-dashboard-container { padding: 20px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fdfdfd; }
    .qrrs-license-alert { background: #fff5f5; border-left: 5px solid #e53e3e; padding: 15px; margin-bottom: 20px; border-radius: 4px; color: #c53030; }
    .qrrs-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f1f1; padding-bottom: 15px; margin-bottom: 25px; }
    .qrrs-grid { display: grid; grid-template-columns: 260px 1fr; gap: 30px; }
    .qrrs-sidebar ul { list-style: none; padding: 0; margin: 0; background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; }
    .qrrs-sidebar ul li a { display: block; padding: 14px 20px; text-decoration: none; color: #444; border-bottom: 1px solid #f0f0f0; transition: 0.2s; font-weight: 500; }
    .qrrs-sidebar ul li a:hover { background: #f8f9fa; padding-left: 25px; }
    .qrrs-sidebar ul li a.active { background: #222; color: #fff; }
    .admin-menu-item { background: #fff9e6; }
    .logout-btn { background: #dc3545; color: #fff; padding: 8px 18px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: bold; }
    .qrrs-main-content { background: #fff; padding: 30px; border-radius: 10px; border: 1px solid #e0e0e0; min-height: 500px; }
    .active-link { color: #000 !important; border-bottom: 2px solid #222; }
</style>