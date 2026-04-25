<?php
if ( ! defined( 'ABSPATH' ) ) exit;
QRRS_Auth::is_admin_only();

global $wpdb;


$edit_mode = false;
$staff_data = null;
$staff_table = $wpdb->prefix . 'qrrs_staff';

// 1. Delete Logic (Enhanced)
if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    $uid_to_delete = intval($_GET['id']);
    
    // Custom table row delete first
    $wpdb->delete($staff_table, ['user_id' => $uid_to_delete]);
    
    // WP User delete
    require_once(ABSPATH . 'wp-admin/includes/user.php');
    wp_delete_user( $uid_to_delete );
    
    echo "<div class='success-msg'>Staff member removed from all records!</div>";
}

// 2. Edit Mode Detect
if ( isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id']) ) {
    $edit_mode = true;
    $staff_id = intval($_GET['id']);
    $staff_data = get_userdata($staff_id);
}

// 3. Save/Update Logic (Optimized for rest_qrrs_staff)
if ( isset($_POST['save_staff']) ) {
    $sid = $edit_mode ? intval($_GET['id']) : 0;
    $restaurant_id = intval( $_POST['restaurant_id'] );
    $staff_role = sanitize_text_field( $_POST['staff_role'] );
    $status = sanitize_text_field( $_POST['staff_status'] );

    if ( $edit_mode ) {
        // --- WordPress User Update ---
        wp_update_user([
            'ID'           => $sid,
            'display_name' => sanitize_text_field($_POST['staff_name'])
        ]);

        if ( !empty($_POST['staff_pass']) ) {
            wp_set_password( $_POST['staff_pass'], $sid );
        }

        update_user_meta( $sid, 'staff_photo', esc_url_raw( $_POST['staff_photo'] ) );
        update_user_meta( $sid, 'assigned_restaurant', $restaurant_id );
        update_user_meta( $sid, 'staff_status', $status );

        $user = new WP_User( $sid );
        $user->set_role( $staff_role );

        // --- rest_qrrs_staff Table Update ---
        $wpdb->replace(
            $staff_table,
            [
                'user_id'       => $sid,
                'restaurant_id' => $restaurant_id,
                'staff_role'    => $staff_role,
                'status'        => $status
            ],
            ['%d', '%d', '%s', '%s']
        );

        echo "<div class='success-msg'>Staff updated successfully!</div>";
        echo "<script>window.location.href='?tab=add-staff';</script>";

    } else {
        // --- Create New User ---
        $username = sanitize_user($_POST['staff_user']);
        $password = $_POST['staff_pass'];
        $new_user_id = wp_create_user($username, $password, $username . '@restaurant.com');

        if ( !is_wp_error($new_user_id) ) {
            wp_update_user(['ID' => $new_user_id, 'display_name' => sanitize_text_field($_POST['staff_name'])]);
            
            update_user_meta( $new_user_id, 'staff_photo', esc_url_raw( $_POST['staff_photo'] ) );
            update_user_meta( $new_user_id, 'assigned_restaurant', $restaurant_id );
            update_user_meta( $new_user_id, 'staff_status', $status );

            $user = new WP_User( $new_user_id );
            $user->set_role( $staff_role );

            // --- rest_qrrs_staff Table Insert ---
            $wpdb->insert(
                $staff_table,
                [
                    'user_id'       => $new_user_id,
                    'restaurant_id' => $restaurant_id,
                    'staff_role'    => $staff_role,
                    'status'        => $status
                ],
                ['%d', '%d', '%s', '%s']
            );

            echo "<div class='success-msg'>Staff created and linked to restaurant!</div>";
        } else {
            echo "<div class='error-msg'>Error: " . $new_user_id->get_error_message() . "</div>";
        }
    }
}

$restaurants = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qrrs_restaurants");
?>

<div class="qrrs-card">
    <div class="card-header">
        <h3><?php echo $edit_mode ? 'Edit Staff: ' . esc_html($staff_data->display_name) : 'Add New Staff Member'; ?></h3>
    </div>
    <form method="POST" class="qrrs-form">
        <div class="form-row">
            <div class="form-col">
                <label>Full Name</label>
                <input type="text" name="staff_name" required value="<?php echo $edit_mode ? esc_attr($staff_data->display_name) : ''; ?>">
            </div>
            <div class="form-col">
                <label>Username</label>
                <input type="text" name="staff_user" required <?php echo $edit_mode ? 'readonly' : ''; ?> value="<?php echo $edit_mode ? esc_attr($staff_data->user_login) : ''; ?>">
            </div>
            <div class="form-col">
                <label>Password <?php echo $edit_mode ? '(Leave blank to keep same)' : ''; ?></label>
                <input type="password" name="staff_pass" <?php echo $edit_mode ? '' : 'required'; ?>>
            </div>
        </div>

        <div class="form-row">
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
                <label>Assigned Restaurant</label>
                <?php $assigned_res = $edit_mode ? get_user_meta($staff_data->ID, 'assigned_restaurant', true) : ''; ?>
                <select name="restaurant_id" required>
                    <?php foreach($restaurants as $res): ?>
                        <option value="<?php echo $res->id; ?>" <?php selected($assigned_res, $res->id); ?>><?php echo esc_html($res->restaurant_name); ?></option>
                    <?php endforeach; ?>
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
        </div>

        <div class="form-row">
            <div class="form-col">
                <label>Photo</label>
                <?php $photo = $edit_mode ? get_user_meta($staff_data->ID, 'staff_photo', true) : ''; ?>
                <input type="hidden" name="staff_photo" id="staff_photo_url" value="<?php echo $photo; ?>">
                <button type="button" class="upload-media button" data-target="#staff_photo_url">Upload Photo</button>
                <div class="preview-box"><img src="<?php echo $photo; ?>" style="<?php echo $photo ? '' : 'display:none;'; ?> width: 50px; margin-top:5px; border-radius:4px;"></div>
            </div>
            <div class="form-col">
                <label>NID Front</label>
                <input type="hidden" name="nid_front" id="nid_front_url" value="<?php echo $edit_mode ? get_user_meta($staff_data->ID, 'staff_nid_front', true) : ''; ?>">
                <button type="button" class="upload-media button" data-target="#nid_front_url">Upload Front</button>
            </div>
            <div class="form-col">
                <label>NID Back</label>
                <input type="hidden" name="nid_back" id="nid_back_url" value="<?php echo $edit_mode ? get_user_meta($staff_data->ID, 'staff_nid_back', true) : ''; ?>">
                <button type="button" class="upload-media button" data-target="#nid_back_url">Upload Back</button>
            </div>
        </div>

        <button type="submit" name="save_staff" class="save-btn"><?php echo $edit_mode ? 'Update Staff Member' : 'Save Staff Member'; ?></button>
    </form>
</div>

<hr style="margin: 40px 0; border: 1px solid #eee;">

<div class="qrrs-card">
    <div class="card-header">
        <h3>Manage Staff (Database Synced)</h3>
    </div>
    <table class="qrrs-table">
        <thead>
            <tr>
                <th>Photo</th>
                <th>User Details</th>
                <th>Role</th>
                <th>Restaurant</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            // Querying from rest_qrrs_staff directly for better performance
            $staff_results = $wpdb->get_results("SELECT * FROM $staff_table ORDER BY id DESC");

            if($staff_results):
                foreach($staff_results as $staff):
                    $user = get_userdata($staff->user_id);
                    if(!$user) continue; 

                    $photo = get_user_meta($user->ID, 'staff_photo', true);
                    $display_photo = $photo ? $photo : 'https://via.placeholder.com/50';
                    $restaurant = qrrs_get_restaurant($staff->restaurant_id);
            ?>
            <tr>
                <td><img src="<?php echo esc_url($display_photo); ?>" style="width:45px; height:45px; border-radius:6px; object-fit:cover;"></td>
                <td>
                    <strong><?php echo esc_html($user->display_name); ?></strong><br>
                    <small>@<?php echo esc_html($user->user_login); ?></small>
                </td>
                <td><span class="role-badge"><?php echo ucfirst(str_replace('qr_', '', $staff->staff_role)); ?></span></td>
                <td><?php echo $restaurant ? esc_html($restaurant->restaurant_name) : 'Not Linked'; ?></td>
                <td><span class="status-badge <?php echo esc_attr($staff->status); ?>"><?php echo ucfirst($staff->status); ?></span></td>
                <td>
                    <div class="action-btns">
                        <a href="?tab=add-staff&action=edit&id=<?php echo $user->ID; ?>" class="edit-btn">Edit</a>
                        <?php if ( get_current_user_id() !== $user->ID ): ?>
                            <a href="?tab=add-staff&action=delete&id=<?php echo $user->ID; ?>" class="delete-btn" onclick="return confirm('Delete this staff?')">Delete</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" style="text-align:center;">No staff members found in database.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>