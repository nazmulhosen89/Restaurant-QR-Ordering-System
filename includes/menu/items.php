<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$items_table = $wpdb->prefix . 'qrrs_items';
$cat_table   = $wpdb->prefix . 'qrrs_categories';
$res_table   = $wpdb->prefix . 'qrrs_restaurants';

$edit_id   = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
$edit_item = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $items_table WHERE id = %d", $edit_id)) : null;

$base_url = home_url('/restaurant-dashboard/?tab=items');

// --- 1. HANDLE SAVE / UPDATE LOGIC ---
if ( isset($_POST['save_item_action']) ) {

    if (!isset($_POST['save_item_nonce_field']) || !wp_verify_nonce($_POST['save_item_nonce_field'], 'save_item_nonce')) {
        wp_die('Security check failed');
    }

    $edit_id = intval($_POST['edit_id']);
    
    $variant_input = sanitize_text_field($_POST['variants_csv']);
    $variants_array = array_map('trim', explode(',', $variant_input));
    $variants_array = array_filter($variants_array);

    // ✅ Column names updated to match your new DB structure
    $data = [
        'restaurant_id' => intval($_POST['restaurant_id']),
        'category_id'   => intval($_POST['category_id']),
        'item_name'     => sanitize_text_field($_POST['item_name']),
        'price'         => floatval($_POST['price']),
        'item_image'    => esc_url_raw($_POST['image']), // match: item_image
        'description'   => sanitize_textarea_field($_POST['description']),
        'portion_size'  => sanitize_text_field($_POST['portion_size']),
        'prep_time'     => sanitize_text_field($_POST['prep_time']),
        'variants_json' => json_encode($variants_array),
        'is_available'  => isset($_POST['is_available']) ? 1 : 0,
        'is_tax_free'   => isset($_POST['is_tax_free']) ? 1 : 0 
    ];

    if ($edit_id > 0) {
        $result = $wpdb->update($items_table, $data, ['id' => $edit_id]);
        $status = ($result !== false) ? "updated" : "error";
    } else {
        $result = $wpdb->insert($items_table, $data);
        $status = ($result !== false) ? "inserted" : "error";
    }

    wp_safe_redirect($base_url . '&status=' . $status);
    exit;
}

// --- 2. HANDLE DELETE ---
if ( isset($_GET['action']) && $_GET['action'] == 'delete_item' ) {
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_item_nonce')) {
        wp_die('Security check failed');
    }
    $wpdb->delete($items_table, ['id' => intval($_GET['id'])]);
    wp_safe_redirect($base_url . '&status=deleted');
    exit;
}

// Status Messages
if(isset($_GET['status'])) {
    if($_GET['status'] == 'error') {
        echo "<div style='background:#fee2e2; color:#991b1b; padding:12px; border-radius:5px; margin-bottom:20px; border-left:5px solid #991b1b;'>Error: Could not save item. Please check DB columns.</div>";
    } else {
        $msg = ($_GET['status'] == 'updated') ? 'Item updated!' : (($_GET['status'] == 'inserted') ? 'New item added!' : 'Item deleted!');
        echo "<div style='background:#dcfce7; color:#166534; padding:12px; border-radius:5px; margin-bottom:20px; border-left:5px solid #166534;'>$msg</div>";
    }
}

$current_res_id = current_user_can('administrator') ? null : get_user_meta(get_current_user_id(), 'assigned_restaurant', true);
?>

<style>
    .qrrs-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .qrrs-input-group { margin-bottom: 15px; }
    .qrrs-input-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #444; }
    .qrrs-input-group input, .qrrs-input-group select, .qrrs-input-group textarea { 
        width: 100%; border: 1px solid #ccc; border-radius: 4px; padding: 8px; 
    }
    .qrrs-btn-save { background: #2271b1; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
    .qrrs-btn-save:hover { background: #135e96; }
    .qrrs-table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #fff; }
    .qrrs-table th, .qrrs-table td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; }
    .qrrs-table th { background: #f8f9fa; }
    .pagination { margin-top: 20px; display: flex; gap: 5px; }
    .pagination a { padding: 5px 10px; border: 1px solid #ddd; text-decoration: none; color: #2271b1; border-radius: 3px; }
    .pagination span.current { padding: 5px 10px; background: #2271b1; color: #fff; border-radius: 3px; }
    .status-badge { padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; }
.edit-btn, .delete-btn { padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; }
    .edit-btn { background: #3182ce; color: #fff; }
    .delete-btn { background: #e53e3e; color: #fff; }
</style>

<div class="qrrs-card" style="background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
    <h3 style="margin-top:0;"><?php echo $edit_id ? "📝 Edit Item" : "➕ Add New Menu Item"; ?></h3>

    <form method="POST" action="">
        <?php wp_nonce_field('save_item_nonce', 'save_item_nonce_field'); ?>
        <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">

        <div class="qrrs-form-grid">
            <div class="qrrs-input-group">
                <label>Restaurant</label>
                <select name="restaurant_id" required>
                    <?php
                    // Fixed column: restaurant_name
                    $res_list = $current_res_id ? $wpdb->get_results($wpdb->prepare("SELECT id, restaurant_name FROM $res_table WHERE id=%d", $current_res_id)) : $wpdb->get_results("SELECT id, restaurant_name FROM $res_table");
                    foreach($res_list as $res) echo "<option value='{$res->id}' ".($edit_item && $edit_item->restaurant_id == $res->id ? 'selected':'').">{$res->restaurant_name}</option>";
                    ?>
                </select>
            </div>

            <div class="qrrs-input-group">
                <label>Category</label>
                <select name="category_id" required>
                    <?php
                    // Fixed column: category_name
                    $cats = $current_res_id ? $wpdb->get_results($wpdb->prepare("SELECT id, category_name FROM $cat_table WHERE restaurant_id=%d", $current_res_id)) : $wpdb->get_results("SELECT id, category_name FROM $cat_table");
                    foreach($cats as $cat) echo "<option value='{$cat->id}' ".($edit_item && $edit_item->category_id == $cat->id ? 'selected':'').">{$cat->category_name}</option>";
                    ?>
                </select>
            </div>

            <div class="qrrs-input-group">
                <label>Item Name</label>
                <input type="text" name="item_name" value="<?php echo esc_attr($edit_item->item_name ?? ''); ?>" required>
            </div>

            <div class="qrrs-input-group">
                <label>Price (৳)</label>
                <input type="number" step="0.01" name="price" value="<?php echo esc_attr($edit_item->price ?? ''); ?>" required>
            </div>
        </div>

        <div class="qrrs-input-group">
            <label>Image</label>
            <div style="display:flex; align-items:center; gap:15px;">
                <img id="preview" src="<?php echo esc_url($edit_item->item_image ?? 'https://via.placeholder.com/80'); ?>" style="width:70px; height:70px; object-fit:cover; border-radius:5px; border:1px solid #ddd;">
                <input type="hidden" name="image" id="img_val" value="<?php echo esc_attr($edit_item->item_image ?? ''); ?>">
                <button type="button" class="upload-media button">Upload Photo</button>
            </div>
        </div>

        <div class="qrrs-form-grid">
            <div class="qrrs-input-group">
                <label>Portion Size</label>
                <input type="text" name="portion_size" placeholder="e.g. 1:2 or 250g" value="<?php echo esc_attr($edit_item->portion_size ?? ''); ?>">
            </div>
            <div class="qrrs-input-group">
                <label>Prep Time</label>
                <input type="text" name="prep_time" placeholder="e.g. 15 min" value="<?php echo esc_attr($edit_item->prep_time ?? ''); ?>">
            </div>
        </div>

        <div class="qrrs-input-group">
            <label>Variants (Separate with Comma)</label>
            <?php 
                $saved_json = ($edit_item && $edit_item->variants_json) ? json_decode($edit_item->variants_json, true) : [];
                $csv_variants = is_array($saved_json) ? implode(', ', $saved_json) : '';
            ?>
            <input type="text" name="variants_csv" placeholder="e.g. Spicy, Extra Cheese" value="<?php echo esc_attr($csv_variants); ?>">
        </div>

        <div class="qrrs-input-group">
            <label>Description</label>
            <textarea name="description" rows="2"><?php echo esc_textarea($edit_item->description ?? ''); ?></textarea>
        </div>

        <div style="background: #fffde7; padding: 15px; border-radius: 8px; border: 1px solid #ffe082; margin-bottom: 20px;">
            <label style="margin-right: 20px; cursor:pointer; font-weight:bold;">
                <input type="checkbox" name="is_tax_free" <?php echo (isset($edit_item->is_tax_free) && $edit_item->is_tax_free) ? 'checked' : ''; ?>> 
                🚫 Tax Free (Only SD Apply)
            </label>
            <label style="cursor:pointer; font-weight:bold;">
                <input type="checkbox" name="is_available" <?php echo (!isset($edit_item->is_available) || $edit_item->is_available) ? 'checked' : ''; ?>> 
                ✅ Available in Menu
            </label>
        </div>

        <button type="submit" name="save_item_action" class="qrrs-btn-save">
            <?php echo $edit_id ? 'Update Item' : 'Add Item'; ?>
        </button>
        <?php if($edit_id): ?>
            <a href="<?php echo $base_url; ?>" style="margin-left:10px; text-decoration:none; color:#666;">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<div class="qrrs-card" style="margin-top:30px; background:#fff; padding:20px; border-radius:8px;">
    <h3>📋 Menu Items List</h3>
    <table class="qrrs-table">
        <thead>
            <tr>
                <th>Image</th>
                <th>Name</th>
                <th>Category</th>
                <th>Price</th>
                <th>Tax Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $per_page = 10;
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $offset = ($current_page - 1) * $per_page;

            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $items_table");
            
            // Fixed join column names
            $items = $wpdb->get_results("SELECT i.*, c.category_name as cat_name FROM $items_table i 
                                        JOIN $cat_table c ON i.category_id=c.id 
                                        ORDER BY i.id DESC LIMIT $offset, $per_page");

            if($items): foreach($items as $row):
            ?>
            <tr>
                <td><img src="<?php echo esc_url($row->item_image ?: 'https://via.placeholder.com/50'); ?>" style="width:45px; height:45px; object-fit:cover; border-radius:4px;"></td>
                <td>
                    <strong><?php echo esc_html($row->item_name); ?></strong>
                    <?php if(!$row->is_available): ?> <span style="color:red; font-size:10px;">(Not Available)</span> <?php endif; ?>
                </td>
                <td><span style="background:#eee; padding:2px 8px; border-radius:10px; font-size:12px;"><?php echo esc_html($row->cat_name); ?></span></td>
                <td><?php echo number_format($row->price, 2); ?>৳</td>
                <td>
                    <?php if($row->is_tax_free): ?>
                        <span class="status-badge" style="background:#e0f2fe; color:#0369a1;">Tax Free</span>
                    <?php else: ?>
                        <span class="status-badge" style="background:#f1f5f9; color:#64748b;">Standard</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?php echo $base_url; ?>&edit_id=<?php echo $row->id; ?>" class="edit-btn">Edit</a>
                    <a href="<?php echo wp_nonce_url($base_url . '&action=delete_item&id=' . $row->id, 'delete_item_nonce'); ?>" class="delete-btn" onclick="return confirm('Delete this item?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6">No items found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="pagination">
        <?php
        $total_pages = ceil($total_items / $per_page);
        for ($i = 1; $i <= $total_pages; $i++) {
            $active_class = ($i == $current_page) ? 'current' : '';
            echo "<a href='{$base_url}&paged=$i' class='$active_class'>$i</a>";
        }
        ?>
    </div>
</div>

<script>
jQuery(document).ready(function($){
    $('.upload-media').click(function(e){
        e.preventDefault();
        var uploader = wp.media({
            title: 'Select Item Image',
            button: { text: 'Use this Image' },
            multiple: false
        }).on('select', function(){
            var file = uploader.state().get('selection').first().toJSON();
            $('#img_val').val(file.url);
            $('#preview').attr('src', file.url);
        }).open();
    });
});
</script>