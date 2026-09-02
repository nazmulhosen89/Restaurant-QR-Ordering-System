<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
$user_info = get_userdata($user_id);
$success_msg = '';
$error_msg = '';

if ( isset($_POST['update_profile']) ) {
    wp_update_user([
        'ID'           => $user_id,
        'display_name' => sanitize_text_field($_POST['display_name']),
        'user_email'   => sanitize_email($_POST['user_email'])
    ]);

    update_user_meta( $user_id, 'staff_photo', esc_url_raw( $_POST['staff_photo'] ) );
    update_user_meta( $user_id, 'staff_address', sanitize_textarea_field( $_POST['staff_address'] ) );
    
    if(isset($_POST['nid_front'])) update_user_meta( $user_id, 'staff_nid_front', esc_url_raw( $_POST['nid_front'] ) );
    if(isset($_POST['nid_back'])) update_user_meta( $user_id, 'staff_nid_back', esc_url_raw( $_POST['nid_back'] ) );

    // 3. Password Update
    if ( !empty($_POST['new_password']) ) {
        if ( $_POST['new_password'] === $_POST['confirm_password'] ) {
            wp_set_password( $_POST['new_password'], $user_id );
            echo "<script>alert('Password updated! Please login again.'); window.location.href='" . wp_logout_url(home_url('/restaurant-login/')) . "';</script>";
            exit;
        } else {
            $error_msg = "Passwords do not match!";
        }
    } else {
        $success_msg = "Profile updated successfully!";
    }
}

$photo     = get_user_meta($user_id, 'staff_photo', true);
$address   = get_user_meta($user_id, 'staff_address', true);
$nid_f     = get_user_meta($user_id, 'staff_nid_front', true);
$nid_b     = get_user_meta($user_id, 'staff_nid_back', true);
$res_id    = get_user_meta($user_id, 'assigned_restaurant', true);
$restaurant = qrrs_get_restaurant($res_id);
?>

<div class="qrrs-profile-wrapper">
    <form method="POST" class="qrrs-form">
        <div class="qrrs-grid" style="grid-template-columns: 200px 1fr; gap: 40px;">
            
            <div class="profile-sidebar">
                <div class="profile-photo-edit">
                    <img src="<?php echo $photo ? $photo : 'https://via.placeholder.com/150'; ?>" id="profile-preview" style="width:100%; border-radius:15px; border:2px solid #eee;">
                    <input type="hidden" name="staff_photo" id="staff_photo_url" value="<?php echo $photo; ?>">
                    <button type="button" class="upload-media button" data-target="#staff_photo_url" style="width:100%; margin-top:10px;">Change Photo</button>
                </div>
                
                <div class="user-badge-info" style="margin-top:20px; text-align:center;">
                    <span class="role-badge" style="display:inline-block; background:#222; color:#fff; padding:5px 15px; border-radius:20px; font-size:12px;">
                        <?php echo ucfirst($user_info->roles[0]); ?>
                    </span>
                    <?php if($restaurant): ?>
                        <p style="font-size:13px; color:#666; margin-top:10px;">📍 <?php echo esc_html($restaurant->restaurant_name); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="profile-main">
                <div class="card-header" style="margin-bottom: 20px;">
                    <h3>Personal Information</h3>
                </div>

                <?php if ($success_msg) echo "<div class='success-msg'>$success_msg</div>"; ?>
                <?php if ($error_msg) echo "<div class='error-msg'>$error_msg</div>"; ?>

                <div class="form-row">
                    <div class="form-col">
                        <label>Full Name</label>
                        <input type="text" name="display_name" value="<?php echo esc_attr($user_info->display_name); ?>" required>
                    </div>
                    <div class="form-col">
                        <label>Username (Read-only)</label>
                        <input type="text" value="<?php echo esc_attr($user_info->user_login); ?>" readonly style="background:#f9f9f9;">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <label>Email Address</label>
                        <input type="email" name="user_email" value="<?php echo esc_attr($user_info->user_email); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <label>Mailing Address</label>
                        <textarea name="staff_address" rows="3"><?php echo esc_textarea($address); ?></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <label>NID Front Side</label>
                        <input type="hidden" name="nid_front" id="nid_f_url" value="<?php echo $nid_f; ?>">
                        <button type="button" class="upload-media button" data-target="#nid_f_url">Update Front</button>
                        <?php if($nid_f): ?><a href="<?php echo $nid_f; ?>" target="_blank" style="font-size:11px; display:block; margin-top:5px;">View Current</a><?php endif; ?>
                    </div>
                    <div class="form-col">
                        <label>NID Back Side</label>
                        <input type="hidden" name="nid_back" id="nid_b_url" value="<?php echo $nid_b; ?>">
                        <button type="button" class="upload-media button" data-target="#nid_b_url">Update Back</button>
                        <?php if($nid_b): ?><a href="<?php echo $nid_b; ?>" target="_blank" style="font-size:11px; display:block; margin-top:5px;">View Current</a><?php endif; ?>
                    </div>
                </div>

                <hr style="margin: 30px 0; border:0; border-top:1px solid #eee;">
                
                <div class="card-header" style="margin-bottom: 20px;">
                    <h3>Security & Password</h3>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <label>New Password</label>
                        <input type="password" name="new_password" placeholder="Leave blank to keep current">
                    </div>
                    <div class="form-col">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Confirm new password">
                    </div>
                </div>

                <div class="form-footer" style="margin-top: 30px;">
                    <button type="submit" name="update_profile">Save Changes</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($){
    $(document).on('click', '.upload-media', function(e) {
        e.preventDefault();
        var button = $(this);
        var target = button.data('target');
        var custom_uploader = wp.media({
            title: 'Update Profile Media',
            button: { text: 'Use this file' },
            multiple: false
        }).on('select', function() {
            var attachment = custom_uploader.state().get('selection').first().toJSON();
            $(target).val(attachment.url);
            if(target === '#staff_photo_url'){
                $('#profile-preview').attr('src', attachment.url);
            }
        }).open();
    });
});
</script>


<style>
</style>