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
 * 2. Handle Actions (Add/Update/Delete)
 */
if ( isset($_POST['save_category']) ) {
    $name    = sanitize_text_field($_POST['cat_name']);
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

if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    $wpdb->delete($cat_table, ['id' => intval($_GET['id']), 'restaurant_id' => $active_res_id], ['%d', '%d']);
    echo "<div class='qrrs-toast success'>Category deleted successfully!</div>";
    echo "<script>setTimeout(function(){ window.location.href='?tab=categories'; }, 2000);</script>";
}
?>



<div class="qrrs-card">
    <div class="card-header">
        <h3 style="margin-top:0;">
        <?php echo ($edit_id && $edit_data) ? 'Edit Category' : 'Add Menu Category'; ?>
        </h3>
    </div>

    <form method="POST" class="qrrs-form">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
            <div class="form-col">
                <label style="display:block; font-weight:bold; margin-bottom:5px;">Target Restaurant</label>
                <?php $active_res_name = $wpdb->get_var($wpdb->prepare("SELECT restaurant_name FROM $res_table WHERE id = %d", $active_res_id)); ?>
                <input type="text" value="<?php echo esc_html($active_res_name); ?>" disabled style="width:100%; padding:8px; background:#f5f5f5; border:1px solid #ddd;">
                <input type="hidden" name="restaurant_id" value="<?php echo $active_res_id; ?>">
            </div>

            <div class="form-col">
                <label style="display:block; font-weight:bold; margin-bottom:5px;">Category Name</label>
                <input type="text" name="cat_name" required value="<?php echo $edit_data ? esc_attr($edit_data->category_name) : ''; ?>" placeholder="e.g. Pizza" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            </div>

            <div class="form-col">
                <label style="display:block; font-weight:bold; margin-bottom:5px;">Category Image</label>
                <div style="display:flex; gap:15px; align-items:center;">
                    <?php $preview = ($edit_data && $edit_data->image) ? $edit_data->image : 'https://via.placeholder.com/80'; ?>
                    <img src="<?php echo esc_url($preview); ?>" id="cat-img-preview" style="width:60px; height:60px; border-radius:10px; object-fit:cover; border:1px solid #ddd;">
                    <input type="hidden" name="cat_image" id="cat_image_url" value="<?php echo $edit_data ? esc_attr($edit_data->image) : ''; ?>">
                    <button type="button" class="upload-cat-img-btn button">Upload</button>
                </div>
            </div>
        </div>

        <div class="form-footer" style="margin-top:20px;">
            <button type="submit" name="save_category" style="background:#2271b1; color:#fff; border:none; padding:10px 20px; border-radius:4px; cursor:pointer;">
                <?php echo $edit_id ? 'Update Category' : 'Save Category'; ?>
            </button>
        </div>
    </form>
</div>

<hr style="margin:20px 0; border:0; border-top:1px solid #eee;">

<div class="qrrs-cat-card">
    <div class="card-header"><h3>📂 Categories for <?php echo esc_html($active_res_name); ?></h3></div>

    <table class="qrrs-table" style="float:left;width:100%; border-collapse: collapse; margin:15px 0 0 0;">
        <thead>
            <tr style="background:#f8f9fa; border-bottom:2px solid #eee;">
                <th style="padding:12px; text-align:left;">Image</th>
                <th style="padding:12px; text-align:left;">Category</th>
                <th style="padding:12px; text-align:left;">Action</th>
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
            ?>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:12px;">
                    <img src="<?php echo esc_url($img_url); ?>" style="width:50px; height:50px; border-radius:8px; object-fit:cover;">
                </td>
                <td style="padding:12px;"><strong><?php echo esc_html($row->category_name); ?></strong></td>
                <td style="padding:12px;">
                    <a href="?tab=categories&edit_id=<?php echo $row->id; ?>" class="edit-btn">Edit</a>
                    <a href="?tab=categories&action=delete&id=<?php echo $row->id; ?>" 
                       onclick="return confirm('Delete this category?')" class="delete-btn">Delete</a>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="3" style="text-align:center; padding:20px;">No categories found for this restaurant.</td></tr>
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

    // --- Media Uploader ---
    $('.upload-cat-img-btn').on('click', function(e) {
        e.preventDefault();
        var uploader = wp.media({
            title: 'Select Image',
            multiple: false
        }).on('select', function() {
            var attachment = uploader.state().get('selection').first().toJSON();
            $('#cat_image_url').val(attachment.url);
            $('#cat-img-preview').attr('src', attachment.url);
        }).open();
    });
});
</script>