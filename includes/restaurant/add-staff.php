<?php
if ( ! defined( 'ABSPATH' ) ) exit;
QRRS_Auth::is_admin_only();

global $wpdb;

$edit_mode = false;
$staff_data = null;
$staff_table = $wpdb->prefix . 'qrrs_staff';
$res_table = $wpdb->prefix . 'qrrs_restaurants';

/**
 * 1. Restaurant ID
 */
if ( ! session_id() ) session_start();
$active_res_id = isset($_SESSION['qrrs_active_res_id']) ? intval($_SESSION['qrrs_active_res_id']) : 0;

if (!$active_res_id && !isset($_GET['action'])) {
    echo '<div style="padding:50px; text-align:center;"><h3>❌ Please select a restaurant from the dashboard first.</h3></div>';
    return;
}

/**
 * 2. Delete
 */
if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    $uid_to_delete = intval($_GET['id']);
    $wpdb->delete($staff_table, ['user_id' => $uid_to_delete]);
    require_once(ABSPATH . 'wp-admin/includes/user.php');
    wp_delete_user( $uid_to_delete );
    echo "<div class='qrrs-toast success'>Staff member removed successfully!</div>";
}

/**
 * 3. Edit Mode Detect
 */
if ( isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id']) ) {
    $edit_mode = true;
    $staff_id = intval($_GET['id']);
    $staff_data = get_userdata($staff_id);
}

// 4. Save/Update
if ( isset($_POST['save_staff']) ) {
    $sid = $edit_mode ? intval($_GET['id']) : 0;
    $restaurant_id = intval( $_POST['restaurant_id'] );
    $staff_role = sanitize_text_field( $_POST['staff_role'] );
    $status = sanitize_text_field( $_POST['staff_status'] );
    $staff_name = sanitize_text_field($_POST['staff_name']);

    if ( $edit_mode ) {
        // --- UPDATE ---
        wp_update_user([
            'ID'           => $sid,
            'display_name' => $staff_name
        ]);

        if ( !empty($_POST['staff_pass']) ) {
            wp_set_password( $_POST['staff_pass'], $sid );
        }

        update_user_meta( $sid, 'staff_photo', esc_url_raw( $_POST['staff_photo'] ) );
        update_user_meta( $sid, 'staff_nid_front', esc_url_raw( $_POST['nid_front'] ) );
        update_user_meta( $sid, 'staff_nid_back', esc_url_raw( $_POST['nid_back'] ) );
        update_user_meta( $sid, 'assigned_restaurant', $restaurant_id );
        update_user_meta( $sid, 'staff_status', $status );

        $user = new WP_User( $sid );
        $user->set_role( $staff_role );

        $wpdb->replace($staff_table, [
            'user_id'       => $sid,
            'restaurant_id' => $restaurant_id,
            'staff_role'    => $staff_role,
            'status'        => $status
        ], ['%d', '%d', '%s', '%s']);

        echo "<div class='qrrs-toast success'>Staff updated successfully!</div>";
        echo "<script>setTimeout(function(){ window.location.href='?tab=add-staff'; }, 2000);</script>";
    } else {
        // --- CREATE ---
        $username = sanitize_user($_POST['staff_user']);
        $password = $_POST['staff_pass'];

        if ( function_exists( 'qrrs_free_limit_reached' ) && qrrs_free_limit_reached( $staff_role, $restaurant_id ) ) {
            echo "<div class='qrrs-toast error'>Free version allows only 1 " . esc_html( str_replace( 'qr_', '', $staff_role ) ) . " user per restaurant. Upgrade to Pro to add more staff.</div>";
        } elseif ( username_exists( $username ) ) {
            echo "<div class='qrrs-toast error'>Error: Username already exists!</div>";
        } else {
            $new_user_id = wp_create_user($username, $password, $username . '@restaurant.com');

            if ( !is_wp_error($new_user_id) ) {
                wp_update_user(['ID' => $new_user_id, 'display_name' => $staff_name]);
                
                update_user_meta( $new_user_id, 'staff_photo', esc_url_raw( $_POST['staff_photo'] ) );
                update_user_meta( $new_user_id, 'staff_nid_front', esc_url_raw( $_POST['nid_front'] ) );
                update_user_meta( $new_user_id, 'staff_nid_back', esc_url_raw( $_POST['nid_back'] ) );
                update_user_meta( $new_user_id, 'assigned_restaurant', $restaurant_id );
                update_user_meta( $new_user_id, 'staff_status', $status );

                $user = new WP_User( $new_user_id );
                $user->set_role( $staff_role );

                // --- DATABASE TABLE INSERT ---
                $db_inserted = $wpdb->insert($staff_table, [
                    'user_id'       => $new_user_id,
                    'restaurant_id' => $restaurant_id,
                    'staff_role'    => $staff_role,
                    'status'        => $status
                ], ['%d', '%d', '%s', '%s']);

                if($db_inserted !== false) {
                    echo "<div class='qrrs-toast success'>Staff created successfully!</div>";
                    echo "<script>setTimeout(function(){ window.location.href='?tab=add-staff'; }, 2000);</script>";
                } else {
                    echo "<div class='qrrs-toast error'>WP User created, but DB Table insert failed!</div>";
                }
            } else {
                echo "<div class='qrrs-toast error'>Error: " . $new_user_id->get_error_message() . "</div>";
            }
        }
    }
}

$active_res_name = $wpdb->get_var($wpdb->prepare("SELECT restaurant_name FROM $res_table WHERE id = %d", $active_res_id));
?>

<div class="items-add">
    <div class="qrrs-card">
        <div class="card-header">
            <h3>
                <?php echo $edit_mode ? '<span class="material-icons-outlined">edit_note</span> Edit Staff Member' : '<span class="material-icons-outlined">add</span> Add Staff for ' . esc_html($active_res_name); ?>
            </h3>
        </div>
        
        <form method="POST" class="qrrs-form">
            <input type="hidden" name="restaurant_id" value="<?php echo $active_res_id; ?>">

            <div class="form-row" style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:15px;">
                <div class="form-col">
                    <label>Full Name</label>
                    <input type="text" name="staff_name" required value="<?php echo $edit_mode ? esc_attr($staff_data->display_name) : ''; ?>">
                </div>
                <div class="form-col">
                    <label>Username</label>
                    <input type="text" name="staff_user" required <?php echo $edit_mode ? 'readonly' : ''; ?> value="<?php echo $edit_mode ? esc_attr($staff_data->user_login) : ''; ?>">
                </div>
                <div class="form-col">
                    <label>Password <?php echo $edit_mode ? '(Blank to keep same)' : ''; ?></label>
                    <input type="password" name="staff_pass" <?php echo $edit_mode ? '' : 'required'; ?>>
                </div>
            </div>

            <div class="form-row" style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:15px;">
                <div class="form-col">
                    <label>Role</label>
                    <?php $current_role = $edit_mode ? $staff_data->roles[0] : ''; ?>
                    <select name="staff_role">
                        <option value="qr_manager" <?php selected($current_role, 'qr_manager'); ?>>Manager</option>
                        <option value="qr_waiter" <?php selected($current_role, 'qr_waiter'); ?>>Waiter</option>
                        <option value="qr_kitchen" <?php selected($current_role, 'qr_kitchen'); ?>>Kitchen Staff</option>
                    </select>
                </div>
                <div class="form-col">
                    <label>Status</label>
                    <?php $status = $edit_mode ? get_user_meta($staff_data->ID, 'staff_status', true) : 'active'; ?>
                    <select name="staff_status">
                        <option value="active" <?php selected($status, 'active'); ?>>Active</option>
                        <option value="inactive" <?php selected($status, 'inactive'); ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-col">
                    <label>Restaurant</label>
                    <input type="text" value="<?php echo esc_html($active_res_name); ?>" disabled style="background:#f9f9f9;">
                </div>
            </div>

            <div class="form-row" style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:20px;">
                <div class="form-col">
                    <label>Staff Photo</label>
                    <?php $photo = $edit_mode ? get_user_meta($staff_data->ID, 'staff_photo', true) : ''; ?>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <img id="staff_photo_preview" src="<?php echo $photo ? $photo : 'https://via.placeholder.com/50'; ?>" style="width:40px; height:40px; border-radius:50%; object-fit:cover; border:1px solid #ddd;">
                        <input type="hidden" name="staff_photo" id="staff_photo_url" value="<?php echo $photo; ?>">
                        <button type="button" class="upload-media-btn button" data-preview="#staff_photo_preview" data-input="#staff_photo_url"><span class="material-icons-outlined">add_photo_alternate</span> Upload</button>
                    </div>
                </div>
                <div class="form-col">
                    <label>NID Front</label>
                    <?php $nid_f = $edit_mode ? get_user_meta($staff_data->ID, 'staff_nid_front', true) : ''; ?>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="hidden" name="nid_front" id="nid_front_url" value="<?php echo $nid_f; ?>">
                        <button type="button" class="upload-media-btn button" data-input="#nid_front_url"><span class="material-icons-outlined">add_photo_alternate</span> Upload Front</button>
                        <span id="nid_front_status" style="font-size:11px; color:green;"><?php echo $nid_f ? '✅ Loaded' : ''; ?></span>
                    </div>
                </div>
                <div class="form-col">
                    <label>NID Back</label>
                    <?php $nid_b = $edit_mode ? get_user_meta($staff_data->ID, 'staff_nid_back', true) : ''; ?>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="hidden" name="nid_back" id="nid_back_url" value="<?php echo $nid_b; ?>">
                        <button type="button" class="upload-media-btn button" data-input="#nid_back_url"><span class="material-icons-outlined">add_photo_alternate</span> Upload Back</button>
                        <span id="nid_back_status" style="font-size:11px; color:green;"><?php echo $nid_b ? '✅ Loaded' : ''; ?></span>
                    </div>
                </div>
            </div>

            <div class="form-footer">
                <button type="submit" name="save_staff">
                    <?php echo $edit_mode ? '<span class="material-icons-outlined">update</span> Update Staff Member' : '<span class="material-icons-outlined">save</span> Save Staff Member'; ?>
                </button>
            
                <?php if($edit_mode): ?>
                    <a href="?tab=add-staff">Cancel</a>
                <?php endif; ?>
            </div>
            <?php if ( ! $edit_mode && function_exists( 'qrrs_is_pro' ) && ! qrrs_is_pro() ) : ?>
                <p style="margin:10px 0 0; color:#92400e; font-size:13px;">Free version allows 1 manager, 1 waiter, and 1 kitchen user per restaurant. Extra staff accounts are available in Pro.</p>
            <?php endif; ?>
        </form>
    </div>

    <hr style="margin:20px 0; border:0; border-top:1px solid #eee;">

    <div class="qrrs-card">
        <div class="card-header">
            <h3>📋 Staff List (<?php echo esc_html($active_res_name); ?>)</h3>
        </div>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse; margin-top:15px;">
            <thead>
                <tr style="background:#f8f9fa; text-align:left; border-bottom:2px solid #eee;">
                    <th style="padding:12px;">Photo</th>
                    <th style="padding:12px;">Name</th>
                    <th style="padding:12px;">Role</th>
                    <th style="padding:12px;">Status</th>
                    <th style="padding:12px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $staff_results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $staff_table WHERE restaurant_id = %d ORDER BY id DESC", $active_res_id));
                if($staff_results):
                    foreach($staff_results as $staff):
                        $user = get_userdata($staff->user_id);
                        if(!$user) continue; 
                        $photo = get_user_meta($user->ID, 'staff_photo', true);
                ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;"><img src="<?php echo $photo ? $photo : 'https://via.placeholder.com/40'; ?>" style="width:35px; height:35px; border-radius:50%; object-fit:cover;"></td>
                    <td style="padding:10px;"><strong><?php echo esc_html($user->display_name); ?></strong><br><small>@<?php echo esc_html($user->user_login); ?></small></td>
                    <td style="padding:10px;"><span style="background:#eee; padding:2px 8px; border-radius:10px; font-size:11px;"><?php echo ucfirst(str_replace('qr_', '', $staff->staff_role)); ?></span></td>
                    <td style="padding:10px;"><span style="color:<?php echo $staff->status == 'active' ? 'green' : 'red'; ?>; font-weight:bold; font-size:12px;"><?php echo ucfirst($staff->status); ?></span></td>
                    <td style="padding:10px;">
                        <a href="?tab=add-staff&action=edit&id=<?php echo $user->ID; ?>" style="color:#2271b1; text-decoration:none; font-size:13px; margin-right:10px;">Edit</a>
                        <a href="?tab=add-staff&action=delete&id=<?php echo $user->ID; ?>" style="color:#e53e3e; text-decoration:none; font-size:13px;" onclick="return confirm('Delete this staff?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="text-align:center; padding:20px; color:#999;">No staff found for this restaurant.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<script>
jQuery(document).ready(function($){
    function hideToast() {
        if ($('.qrrs-toast').length > 0) {
            setTimeout(function() {
                $('.qrrs-toast').addClass('toast-fade-out');
                setTimeout(function() {
                    $('.qrrs-toast').remove();
                }, 500);
            }, 3000);
        }
    }
    
    hideToast(); 
    $('.upload-media-btn').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var targetInput = button.data('input');
        var targetPreview = button.data('preview');

        var uploader = wp.media({
            title: 'Select Media',
            multiple: false
        }).on('select', function() {
            var attachment = uploader.state().get('selection').first().toJSON();
            $(targetInput).val(attachment.url);
            if(targetPreview) $(targetPreview).attr('src', attachment.url);
            else button.next('span').text('✅ Uploaded');
        }).open();
    });
});
</script>
