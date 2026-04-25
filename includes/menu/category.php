<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$cat_table = $wpdb->prefix . 'qrrs_categories';
$res_table = $wpdb->prefix . 'qrrs_restaurants';

$edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
$edit_data = null;

// 1. Fetch data if in Edit Mode
if ( $edit_id ) {
    $edit_data = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $cat_table WHERE id = %d", $edit_id)
    );
}

// 2. Handle Add / Update Action
if ( isset($_POST['save_category']) ) {

    $name    = sanitize_text_field($_POST['cat_name']);
    $res_id  = intval($_POST['restaurant_id']);
    $image   = esc_url_raw($_POST['cat_image']);
    $slug    = sanitize_title($name);

    if ( $edit_id ) {

        $wpdb->update(
            $cat_table,
            [
                'restaurant_id' => $res_id,
                'Category_name' => $name,
                'slug'          => $slug,
                'image'         => $image
            ],
            ['id' => $edit_id]
        );

        echo "<div class='success-msg'>Category updated successfully! <a href='?tab=categories'>Add New</a></div>";

    } else {

        $wpdb->insert(
            $cat_table,
            [
                'restaurant_id' => $res_id,
                'Category_name' => $name,
                'slug'          => $slug,
                'image'         => $image
            ]
        );

        echo "<div class='success-msg'>Category '$name' created successfully!</div>";
    }
}

// 3. Delete
if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    $wpdb->delete($cat_table, ['id' => intval($_GET['id'])]);
    echo "<div class='success-msg'>Category deleted successfully!</div>";
}

// 4. Restaurant list
$current_user_id = get_current_user_id();

if ( current_user_can('administrator') ) {
    $res_list = $wpdb->get_results("SELECT id, restaurant_name FROM $res_table");
} else {
    $assigned_res = get_user_meta($current_user_id, 'assigned_restaurant', true);

    $res_list = $wpdb->get_results(
        $wpdb->prepare("SELECT id, restaurant_name FROM $res_table WHERE id = %d", $assigned_res)
    );
}
?>

<div class="qrrs-card">
    <div class="card-header">
        <h3>
        <?php 
        echo ($edit_id && $edit_data) 
            ? 'Edit Category: ' . esc_html($edit_data->category_name) 
            : 'Add Menu Category'; 
        ?>
        </h3>
    </div>

    <form method="POST" class="qrrs-form">
        <div class="form-row">

            <!-- Restaurant -->
            <div class="form-col">
                <label>Select Restaurant</label>
                <select name="restaurant_id" required>
                    <?php foreach($res_list as $res): ?>
                        <option value="<?php echo $res->id; ?>"
                            <?php if($edit_data) selected($edit_data->restaurant_id, $res->id); ?>>
                            <?php echo esc_html($res->restaurant_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Category Name -->
            <div class="form-col">
                <label>Category Name</label>
                <input type="text" name="cat_name" required
                    value="<?php echo $edit_data ? esc_attr($edit_data->category_name) : ''; ?>"
                    placeholder="e.g. Pizza, Drinks">
            </div>

        </div>

        <!-- Image -->
        <div class="form-row">
            <div class="form-col">
                <label>Category Image</label>

                <div style="display:flex; gap:15px; align-items:center;">
                    
                    <?php 
                    $preview_img = ($edit_data && $edit_data->image) 
                        ? $edit_data->image 
                        : 'https://via.placeholder.com/80'; 
                    ?>

                    <img src="<?php echo esc_url($preview_img); ?>" 
                         id="cat-img-preview"
                         style="width:80px; height:80px; border-radius:10px; object-fit:cover; border:1px solid #ddd;">

                    <input type="hidden" 
                           name="cat_image" 
                           id="cat_image_url"
                           value="<?php echo $edit_data ? esc_attr($edit_data->image) : ''; ?>">

                    <button type="button" class="upload-cat-img-btn button">
                        Change Image
                    </button>
                </div>
            </div>
        </div>

        <div style="margin-top:20px; display:flex; gap:10px;">
            <button type="submit" name="save_category" class="save-btn">
                <?php echo $edit_id ? 'Update Category' : 'Save Category'; ?>
            </button>

            <?php if($edit_id): ?>
                <a href="?tab=categories" class="button" style="padding:10px 15px;">
                    Cancel
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<hr style="margin:40px 0;">

<div class="qrrs-card">
    <div class="card-header"><h3>Active Categories</h3></div>

    <table class="qrrs-table">
        <thead>
            <tr>
                <th>Image</th>
                <th>Category</th>
                <th>Restaurant</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>
            <?php 
            $query = current_user_can('administrator') 
                ? "SELECT c.id, c.Category_name, c.image, r.restaurant_name as res_name
                   FROM $cat_table c 
                   JOIN $res_table r ON c.restaurant_id = r.id 
                   ORDER BY c.id DESC"
                : $wpdb->prepare(
                    "SELECT c.id, c.Category_name, c.image, r.restaurant_name as res_name
                     FROM $cat_table c 
                     JOIN $res_table r ON c.restaurant_id = r.id 
                     WHERE c.restaurant_id = %d 
                     ORDER BY c.id DESC",
                     $assigned_res
                );

            $categories = $wpdb->get_results($query);

            if($categories):
                foreach($categories as $row):
                    $img_url = $row->image ? $row->image : 'https://via.placeholder.com/50';
            ?>
            <tr>
                <td>
                    <img src="<?php echo esc_url($img_url); ?>" 
                         style="width:50px; height:50px; border-radius:8px; object-fit:cover;">
                </td>

                <td><strong><?php echo esc_html($row->Category_name); ?></strong></td>

                <td><?php echo esc_html($row->res_name); ?></td>

                <td>
                    <a href="?tab=categories&edit_id=<?php echo $row->id; ?>" class="edit-btn">Edit</a>
                    <a href="?tab=categories&action=delete&id=<?php echo $row->id; ?>" 
                       onclick="return confirm('Delete this category?')" class="delete-btn">Delete</a>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" style="text-align:center;">No categories found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(document).ready(function($){
    $('.upload-cat-img-btn').on('click', function(e) {
        e.preventDefault();

        var uploader = wp.media({
            title: 'Select Category Image',
            button: { text: 'Use this Image' },
            multiple: false
        });

        uploader.on('select', function() {
            var attachment = uploader.state().get('selection').first().toJSON();
            $('#cat_image_url').val(attachment.url);
            $('#cat-img-preview').attr('src', attachment.url);
        });

        uploader.open();
    });
});
</script>

<style>
.success-msg {
    background:#dcfce7;
    color:#166534;
    padding:10px;
    border-radius:5px;
    margin-bottom:15px;
}


    .edit-btn, .delete-btn { padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; }
    .edit-btn { background: #3182ce; color: #fff; }
    .delete-btn { background: #e53e3e; color: #fff; }
</style>