<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$cat_table = $wpdb->prefix . 'qrrs_categories';
$res_table = $wpdb->prefix . 'qrrs_restaurants';

/**
 * 1. Restaurant ID Logic
 */
if ( current_user_can('administrator') ) {
    if ( ! session_id() ) session_start();
    $active_res_id = isset($_SESSION['qrrs_active_res_id']) ? intval($_SESSION['qrrs_active_res_id']) : 0;
} else {
    $active_res_id = get_user_meta(get_current_user_id(), 'assigned_restaurant', true);
}

if (!$active_res_id && !isset($_GET['edit_id'])) {
    echo '<div style="padding:50px; text-align:center;"><h3>❌ Please select a restaurant from the dashboard first.</h3></div>';
    return;
}

$edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
$edit_data = null;

if ( $edit_id ) {
    $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cat_table WHERE id = %d", $edit_id));
}

/**
 * 2. Handle Actions (Add/Update/Status Toggle)
 */
if ( isset($_POST['save_category']) ) {
    $name   = sanitize_text_field($_POST['cat_name']);
    $res_id  = intval($_POST['restaurant_id']); 
    $image   = esc_url_raw($_POST['cat_image']);
    $slug    = sanitize_title($name);

    $data_array = [
        'restaurant_id' => $res_id,
        'category_name' => $name, 
        'slug'          => $slug,
        'image'         => $image
    ];

    $format = ['%d', '%s', '%s', '%s']; 

    if ( $edit_id ) {
        $updated = $wpdb->update($cat_table, $data_array, ['id' => $edit_id], $format, ['%d']);
        if ($updated !== false) {
            echo "<div class='qrrs-toast success'>Category updated successfully!</div>";
            echo "<script>setTimeout(function(){ window.location.href='?tab=categories'; }, 2000);</script>";
        }
    } else {
        $inserted = $wpdb->insert($cat_table, $data_array, $format);
        if($inserted) {
            echo "<div class='qrrs-toast success'>Category '$name' created successfully!</div>";
            echo "<script>setTimeout(function(){ window.location.href='?tab=categories'; }, 2000);</script>";
        } else {
            $db_error = $wpdb->last_error;
            echo "<div class='qrrs-toast error'>DB Error: $db_error</div>";
        }
    }
}

// 📌 ডিলিটের পরিবর্তে Active/Inactive টগল লজিক
if ( isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id']) ) {
    $cat_id = intval($_GET['id']);
    // ডাটাবেজে status কলাম না থাকলে অ্যাড করে নিতে পারেন, অথবা ডিফল্টভবে available/inactive হ্যান্ডেল করা
    $current_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $cat_table WHERE id = %d AND restaurant_id = %d", $cat_id, $active_res_id));
    $new_status = ($current_status == 'inactive') ? 'available' : 'inactive';
    
    // যদি আপনার টেবিলে status কলাম না থাকে, তবে এই আপডেট কুয়েরি কাজ করার জন্য কলাম থাকা লাগবে
    $wpdb->update($cat_table, ['status' => $new_status], ['id' => $cat_id, 'restaurant_id' => $active_res_id]);
    echo "<div class='qrrs-toast success'>Category status updated successfully!</div>";
    echo "<script>setTimeout(function(){ window.location.href='?tab=categories'; }, 1000);</script>";
}
?>

<div class="qrrs-card" style="background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
    <div class="card-header" style="margin-bottom: 15px;">
        <h3 style="margin:0; font-size: 18px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="font-size: 20px; color: #2563eb;">
                <?php echo ($edit_id && $edit_data) ? 'edit_note' : 'create_new_folder'; ?>
            </span> 
            <?php echo ($edit_id && $edit_data) ? 'Edit Category' : 'Add Menu Category'; ?>
        </h3>
    </div>

    <form method="POST" class="qrrs-form" style="margin:0;">
        <!-- 📌 এক লাইনে ফর্ম লেআউট (Restaurant, Category Name, Image, Button) -->
        <div style="display: grid; grid-template-columns: 1fr 1fr 140px auto; gap: 15px; align-items: flex-end;">
            
            <div class="form-col">
                <label style="display:block; font-weight:600; font-size: 13px; margin-bottom: 6px; color: #475569;">Target Restaurant</label>
                <?php $active_res_name = $wpdb->get_var($wpdb->prepare("SELECT restaurant_name FROM $res_table WHERE id = %d", $active_res_id)); ?>
                <input type="text" value="<?php echo esc_html($active_res_name); ?>" disabled style="width:100%; height: 42px; padding: 0 12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; color: #64748b; font-weight: 500;">
                <input type="hidden" name="restaurant_id" value="<?php echo $active_res_id; ?>">
            </div>

            <div class="form-col">
                <label style="display:block; font-weight:600; font-size: 13px; margin-bottom: 6px; color: #475569;">Category Name</label>
                <input type="text" name="cat_name" required value="<?php echo $edit_data ? esc_attr($edit_data->category_name) : ''; ?>" placeholder="e.g. Pizza" style="width:100%; height: 42px; padding: 0 12px; border:1px solid #cbd5e1; border-radius:6px; font-size: 14px;">
            </div>

            <!-- 📌 ইমেজ ফিল্ড (সরাসরি ছবিতে ক্লিক করলে আপলোড হবে) -->
            <div class="form-col">
                <label style="display:block; font-weight:600; font-size: 13px; margin-bottom: 6px; color: #475569;">Category Image</label>
                <div style="display:flex; align-items:center;">
                    <?php $preview = ($edit_data && $edit_data->image) ? $edit_data->image : 'https://via.placeholder.com/80?text=Upload'; ?>
                    <div class="upload-cat-img-btn" title="Click to upload image" style="width: 42px; height: 42px; border-radius: 6px; overflow: hidden; border: 2px dashed #cbd5e1; cursor: pointer; display: flex; align-items: center; justify-content: center; background: #f8fafc; transition: border-color 0.2s;">
                        <img src="<?php echo esc_url($preview); ?>" id="cat-img-preview" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                    <input type="hidden" name="cat_image" id="cat_image_url" value="<?php echo $edit_data ? esc_attr($edit_data->image) : ''; ?>">
                    <span style="font-size: 11px; color: #64748b; margin-left: 8px; line-height: 1.2;">Click box to change</span>
                </div>
            </div>

            <div class="form-col">
                <button type="submit" name="save_category" style="height: 42px; padding: 0 24px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s; white-space: nowrap;">
                    <?php echo $edit_id ? 'Update Category' : 'Save Category'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<div class="qrrs-cat-card" style="background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0;">
    <div class="card-header" style="margin-bottom: 15px;">
        <h3 style="margin:0; font-size: 18px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
            <span class="material-icons-outlined" style="font-size: 20px; color: #059669;">folder</span> 
            Categories for <?php echo esc_html($active_res_name); ?>
        </h3>
    </div>

    <table class="qrrs-table" style="width:100%; border-collapse: collapse;">
        <thead>
            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align: left;">
                <th style="padding:12px; color: #475569; font-size: 13px; width: 70px;">Image</th>
                <th style="padding:12px; color: #475569; font-size: 13px;">Category Name</th>
                <th style="padding:12px; color: #475569; font-size: 13px;">Status</th>
                <th style="padding:12px; color: #475569; font-size: 13px; text-align: right;">Action</th>
            </tr>
        </thead>

        <tbody>
            <?php 
            $categories = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $cat_table WHERE restaurant_id = %d ORDER BY id DESC",
                $active_res_id
            ));

            if($categories):
                foreach($categories as $row):
                    $img_url = $row->image ? $row->image : 'https://via.placeholder.com/50';
                    $is_inactive = (isset($row->status) && $row->status === 'inactive');
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:12px;">
                    <img src="<?php echo esc_url($img_url); ?>" style="width:40px; height:40px; border-radius:6px; object-fit:cover; border: 1px solid #e2e8f0;">
                </td>
                <td style="padding:12px;"><strong style="color: #1e293b; font-size: 14px;"><?php echo esc_html($row->category_name); ?></strong></td>
                
                <!-- 📌 স্ট্যাটাস ব্যাজ -->
                <td style="padding:12px;">
                    <?php if($is_inactive): ?>
                        <span style="background: #fee2e2; color: #dc2626; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase;">Inactive</span>
                    <?php else: ?>
                        <span style="background: #dcfce7; color: #16a34a; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase;">Active</span>
                    <?php endif; ?>
                </td>

                <!-- 📌 এডিট এবং Active/Inactive টগল বাটন (ডিলিট বাদ দেওয়া হয়েছে) -->
                <td style="padding:12px; text-align: right;">
                    <a href="?tab=categories&edit_id=<?php echo $row->id; ?>" style="display: inline-block; padding: 6px 14px; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; margin-right: 6px;">Edit</a>
                    
                    <a href="?tab=categories&action=toggle_status&id=<?php echo $row->id; ?>" 
                       style="display: inline-block; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; 
                       <?php echo $is_inactive ? 'background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0;' : 'background: #fee2e2; color: #dc2626; border: 1px solid #fecaca;'; ?>">
                        <?php echo $is_inactive ? 'Set Active' : 'Set Inactive'; ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" style="text-align:center; padding:30px; color: #64748b;">No categories found for this restaurant.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(document).ready(function($){
    // --- Toast Hide Logic ---
    if ($('.qrrs-toast').length > 0) {
        setTimeout(function() {
            $('.qrrs-toast').addClass('toast-fade-out');
            setTimeout(function() { $('.qrrs-toast').remove(); }, 500);
        }, 3000);
    }

    // --- Direct Media Uploader on Image Box Click ---
    $('.upload-cat-img-btn').on('click', function(e) {
        e.preventDefault();
        var uploader = wp.media({
            title: 'Select Category Image',
            multiple: false
        }).on('select', function() {
            var attachment = uploader.state().get('selection').first().toJSON();
            $('#cat_image_url').val(attachment.url);
            $('#cat-img-preview').attr('src', attachment.url);
        }).open();
    });
});
</script>