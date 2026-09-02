<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Expects $wpdb, $current_res_id, $inv_cat_table, $base_url to already exist (set in inventory-items.php)

// --- Handle quick category add ---
if ( isset($_POST['save_inv_category_action']) ) {
    if (!isset($_POST['save_inv_category_nonce_field']) || !wp_verify_nonce($_POST['save_inv_category_nonce_field'], 'save_inv_category_nonce')) {
        wp_die('Security check failed');
    }
    $cat_name = sanitize_text_field($_POST['category_name']);
    if ($cat_name !== '') {
        $wpdb->insert($inv_cat_table, [
            'restaurant_id' => intval($_POST['restaurant_id']),
            'category_name' => $cat_name,
        ]);
    }
    wp_safe_redirect($base_url . '&status=category_added');
    exit;
}

if ( isset($_GET['action']) && $_GET['action'] == 'delete_inv_category' ) {
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_inv_category_nonce')) {
        wp_die('Security check failed');
    }
    $wpdb->query($wpdb->prepare("DELETE FROM $inv_cat_table WHERE id = %d AND restaurant_id = %d", intval($_GET['id']), $current_res_id));
    wp_safe_redirect($base_url . '&status=category_deleted');
    exit;
}

$all_inv_cats = $wpdb->get_results($wpdb->prepare("SELECT * FROM $inv_cat_table WHERE restaurant_id = %d ORDER BY category_name ASC", $current_res_id));
?>

<div class="qrrs-card" style="margin-top:20px; padding:15px 20px;">
    <details>
        <summary style="cursor:pointer; font-weight:bold; color:#475569;">🗂️ Manage Inventory Categories</summary>
        <div style="margin-top:15px; display:flex; gap:20px; flex-wrap:wrap;">
            <form method="POST" action="" style="display:flex; gap:10px; align-items:center;">
                <?php wp_nonce_field('save_inv_category_nonce', 'save_inv_category_nonce_field'); ?>
                <input type="hidden" name="restaurant_id" value="<?php echo $current_res_id; ?>">
                <input type="text" name="category_name" placeholder="e.g. Vegetables" required style="padding:6px 10px;">
                <button type="submit" name="save_inv_category_action" class="qrrs-btn-save" style="padding:6px 14px;">+ Add</button>
            </form>
        </div>

        <?php if ($all_inv_cats): ?>
        <div style="margin-top:12px; display:flex; flex-wrap:wrap; gap:8px;">
            <?php foreach($all_inv_cats as $cat): ?>
                <span style="background:#eef2ff; padding:4px 10px; border-radius:12px; font-size:12px; display:inline-flex; align-items:center; gap:6px;">
                    <?php echo esc_html($cat->category_name); ?>
                    <a href="<?php echo wp_nonce_url($base_url . '&action=delete_inv_category&id=' . $cat->id, 'delete_inv_category_nonce'); ?>" onclick="return confirm('Delete this category?');" style="color:#dc2626; text-decoration:none;">✕</a>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </details>
</div>