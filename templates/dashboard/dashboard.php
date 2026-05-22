<?php
/**
 * Dashboard Template for QR Restaurant System
 * This file handles License Protection, Restaurant Selection, and Tab Navigation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! session_id() ) {
    session_start();
}

QRRS_Auth::has_permission('qr_manager'); 

// Licence check
$license = qrrs_check_system_license(); 

/**
 * license expired check
 */
if ( $license['is_expired'] ) : ?>
    <div class="license-lock-overlay">
        <div class="lock-message-box">
            <span class="material-icons-outlined" style="font-size: 80px; color: #e53e3e; display: block; margin-bottom: 15px;">lock_person</span>
            <h2 style="color: #2d3748; margin-top: 0; font-family: sans-serif;">System License Expired</h2>
            <p style="font-size: 16px; color: #4a5568; font-family: sans-serif;">আপনার সিস্টেমের লাইসেন্সের মেয়াদ শেষ হয়ে গেছে। দয়া করে রিনিউ করতে নিচের প্যাকেজটি বেছে নিন।</p>
            <p style="background: #fff5f5; color: #c53030; display: inline-block; padding: 5px 15px; border-radius: 5px; font-weight: bold; font-family: sans-serif;">
                Expiry Date: <?php echo date('d M, Y', strtotime($license['expiry_date'])); ?>
            </p>
            
            <div class="lock-renewal-form-container" style="margin-top: 30px; border-top: 1px solid #edf2f7; padding-top: 30px; text-align: left;">
                <?php 
                include QRRS_PATH . 'includes/subscriptions/subscription.php'; 
                ?>
            </div>
            
            <div style="margin-top: 30px;">
                 <a href="<?php echo wp_logout_url(home_url('/restaurant-login/')); ?>" style="text-decoration: none; color: #718096; font-size: 14px; font-family: sans-serif; display: flex; align-items: center; justify-content: center; gap: 5px;">
                    <span class="material-icons-outlined" style="font-size: 18px;">logout</span> Logout from System
                 </a>
            </div>
        </div>
    </div>
<style>
        .license-lock-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: #f7fafc; z-index: 9999999; display: flex; align-items: center;
            justify-content: center; overflow-y: auto; padding: 40px 20px;
        }
        .lock-message-box {
            background: #fff; padding: 40px; border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); text-align: center;
            max-width: 850px; width: 100%; border: 1px solid #feb2b2;
        }
        /* ইউজার যাতে অন্য কিছু দেখতে না পারে */
        #wpadminbar, .qrrs-sidebar, .qrrs-header { display: none !important; }
        body { overflow: hidden; background: #f7fafc !important; }
    </style>
<?php 
    return; // লক স্ক্রিন দেখালে নিচের কোডগুলো আর রান হবে না (Security)
endif; 


/**
 * license active
 */
global $wpdb;
$user_id = get_current_user_id();

if ( current_user_can('administrator') ) {
    if ( isset($_GET['set_res']) ) {
        $_SESSION['qrrs_active_res_id'] = intval($_GET['set_res']);
    }
    $active_res_id = isset($_SESSION['qrrs_active_res_id']) ? $_SESSION['qrrs_active_res_id'] : 0;
} else {
    $active_res_id = get_user_meta($user_id, 'qrrs_restaurant_id', true);
}

$GLOBALS['qrrs_active_res_id'] = $active_res_id;

$active_res_name = '';
if ( $active_res_id ) {
    $active_res_name = $wpdb->get_var($wpdb->prepare("SELECT restaurant_name FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $active_res_id));
}

$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : (current_user_can('administrator') ? 'restaurants' : 'orders');
?>

<div class="qrrs-dashboard-container">
    
    <?php if ( $license['days_left'] <= 15 && $license['days_left'] > 0 ) : ?>
        <div class="qrrs-license-popup-fixed">
            <div class="qrrs-popup-inner">
                <div class="qrrs-popup-icon">
                    <span class="material-icons-outlined">timer</span>
                </div>
                <div class="qrrs-popup-body">
                    <strong style="color: #c53030; display: block; font-size: 14px;">License expiring soon!</strong>
                    <p style="margin: 0; font-size: 13px; color: #4a5568;">System will lock in <b><?php echo $license['days_left']; ?> days</b>. <a href="?tab=subscriptions" style="color: #2b6cb0; font-weight: bold;">Renew Now</a></p>
                </div>
                <button type="button" class="qrrs-popup-close" onclick="this.closest('.qrrs-license-popup-fixed').remove();">×</button>
            </div>
        </div>
    <?php endif; ?>


    <style>

</style>


    <div class="qrrs-header">
        <div class="header-left">
           <h1 style="margin:0;">Restaurant Management</h1>
            <?php if ( $active_res_name ) : ?>
                <div style="background: #e8f5e9; color: #2e7d32; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; display: inline-block; margin-top: 5px; border: 1px solid #c8e6c9;">
                    📍 Active: <?php echo esc_html($active_res_name); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="site-branding">
            <?php the_custom_logo(); ?>
        </div>
    
        <div class="user-actions" style="display:flex; align-items:center; gap:15px;">
            <a href="<?php echo home_url(); ?>" class="home-btn" style="text-decoration:none; color:#333;"><span class="material-icons-outlined">home</span></a>
            
            <?php if ( current_user_can('administrator') ) : ?>
                <select onchange="location.href='?tab=<?php echo $current_tab; ?>&set_res=' + this.value" style="padding: 8px; border-radius: 6px; border: 1px solid #ddd; font-size: 13px; background: #fff; cursor: pointer;">
                    <option value="">-- Switch Restaurant --</option>
                    <?php 
                    $all_res = $wpdb->get_results("SELECT id, restaurant_name FROM {$wpdb->prefix}qrrs_restaurants");
                    foreach ( $all_res as $res ) {
                        echo '<option value="'. $res->id .'" '. selected($active_res_id, $res->id, false) .'>'. esc_html($res->restaurant_name) .'</option>';
                    }
                    ?>
                </select>
            <?php endif; ?>

            <div class="kitchen-user-nav" style="position:relative; display:inline-block;">
                <div class="user-profile-trigger" onclick="jQuery('#user-dropdown').toggle();" style="cursor:pointer; display:flex; align-items:center; gap:10px;">
                    <div class="user-avatar" style="width:35px; height:35px; background:#222; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold;">
                        <?php echo strtoupper(substr(wp_get_current_user()->display_name, 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <span class="user-name" style="display:block; font-size:14px; font-weight:600;"><?php echo wp_get_current_user()->display_name; ?></span>
                        <small class="user-role" style="color:#666;"><?php echo wp_get_current_user()->roles[0]; ?></small>
                    </div>
                </div>

                <div id="user-dropdown" class="qrrs-dropdown-menu" style="display:none; position:absolute; right:0; top:100%; background:#fff; border:1px solid #ddd; border-radius:8px; box-shadow:0 10px 15px rgba(0,0,0,0.1); width:200px; z-index:9999;">
                    <a href="?tab=profile" class="dropdown-item" style="display:block; padding:10px 15px; text-decoration:none; color:#333; border-bottom:1px solid #eee;">👤 My Profile</a>
                    <?php if ( current_user_can( 'administrator' ) ) : ?>
                        <a href="?tab=subscriptions" class="dropdown-item" style="display:block; padding:10px 15px; text-decoration:none; color:#333; border-bottom:1px solid #eee;">🔑 License Info</a>
                    <?php endif; ?>
                    <a href="<?php echo wp_logout_url(home_url('/restaurant-login/')); ?>" class="dropdown-item logout" style="display:block; padding:10px 15px; text-decoration:none; color:#d9534f;">👋 Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div class="qrrs-grid">
        <aside class="qrrs-sidebar">
            <ul>
                <li><a href="?tab=take-order" class="<?php echo $current_tab == 'take-order' ? 'active' : ''; ?>">Take an Order</a></li>
                <li><a href="?tab=orders" class="<?php echo $current_tab == 'orders' ? 'active' : ''; ?>">Order Management</a></li>
                <li><a href="?tab=tables" class="<?php echo $current_tab == 'tables' ? 'active' : ''; ?>">Table Management</a></li>
                <li><a href="?tab=billing" class="<?php echo $current_tab == 'billing' ? 'active' : ''; ?>">Billing & POS</a></li>
                <li><a href="?tab=reports" class="<?php echo $current_tab == 'reports' ? 'active' : ''; ?>">Reports</a></li>
                <li><a href="?tab=categories" class="<?php echo $current_tab == 'categories' ? 'active' : ''; ?>">Categories</a></li>
                <li><a href="?tab=items" class="<?php echo $current_tab == 'items' ? 'active' : ''; ?>">Items</a></li>
                
                <?php if ( current_user_can( 'administrator' ) ) : ?>
                    <li class="admin-menu-item-separator" style="border-top:0px solid #eee; margin:0;"></li>
                    <li class="admin-menu-item"><a href="?tab=restaurants" class="<?php echo $current_tab == 'restaurants' ? 'active' : ''; ?>">Manage Restaurants</a></li>
                    <li class="admin-menu-item"><a href="?tab=add-staff" class="<?php echo $current_tab == 'add-staff' ? 'active' : ''; ?>">Create Staff/User</a></li>
                    <li class="admin-menu-item"><a href="?tab=subscriptions" class="<?php echo $current_tab == 'subscriptions' ? 'active' : ''; ?>">System License</a></li>
                <?php endif; ?>
            </ul>
        </aside>

        <main class="qrrs-main-content">
            <?php 
           if ( current_user_can('administrator') && !$active_res_id && !in_array($current_tab, ['restaurants', 'subscriptions', 'profile']) ) {
   
           $default_img_url = plugins_url('assets/images/restaurant.png', dirname(dirname(__FILE__)));

                echo '<div class="no-res-selected" style="text-align:center; padding:50px;  align-content: center;
  height: 100%;">
                        <div style="margin-bottom:20px;">
                            <img width="100" height="163" src="' . esc_url($default_img_url) . '" alt="Restaurant" style="display:inline-block; vertical-align:middle;">
                        </div>
                        <h2>No Restaurant Selected</h2>
                        <p>Please select a restaurant to manage its data.</p>
                        <div class="res-selection-grid" style="display: flex; flex-wrap: wrap; justify-content: center; gap: 20px; margin-top: 30px; width: 100%;">';
                        
                        // ডেটাবেজ থেকে id, name এবং logo একসাথে সিলেক্ট করা হলো
                        $restaurants = $wpdb->get_results("SELECT id, restaurant_name, restaurant_logo FROM {$wpdb->prefix}qrrs_restaurants");
                        
                        foreach ($restaurants as $res) {
                            // যদি ডেটাবেজে লোগো থাকে তবে সেটি দেখাবে, না থাকলে প্লাগইনের ডিফল্ট লোগো দেখাবে
                            $logo_src = !empty($res->restaurant_logo) ? $res->restaurant_logo : $default_img_url;

                            echo '<a href="?tab='.$current_tab.'&set_res='.$res->id.'" style="min-width: 180px; background:#fff; border:1px solid #ddd; padding:20px; border-radius:12px; text-decoration:none; color:#333; font-weight:600; box-shadow:0 4px 10px rgba(0,0,0,0.05); text-align:center; display: flex; flex-direction: column; align-items: center; gap: 10px; transition: transform 0.2s;">
                                    <img src="' . esc_url($logo_src) . '" alt="'.esc_attr($res->restaurant_name).'" style="width: 60px; height: 60px; object-fit: cover; border-radius: 50%; border: 2px solid #eee;">
                                    <span>'.esc_html($res->restaurant_name).'</span>
                                </a>';
                        }
                echo '</div></div>';
            } else {
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
                        echo "<div style='padding:40px; text-align:center;'><h3>Welcome to the Dashboard. Please select a module from the sidebar.</h3></div>";
                        break;
                }
            }
            ?>
        </main>
    </div>
</div>

<script>
jQuery(document).ready(function($){
    $(window).on('click', function(event) {
        if (!$(event.target).closest('.kitchen-user-nav').length) {
            $('#user-dropdown').hide();
        }
    });
});
</script>