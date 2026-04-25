<?php
if ( ! defined( 'ABSPATH' ) ) exit;
QRRS_Auth::is_admin_only();

global $wpdb;
$edit_mode = false;
$res_data = null;

// 1. Delete Logic
if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    qrrs_delete_restaurant( intval($_GET['id']) );
    echo "<div class='success-msg'>Restaurant deleted successfully!</div>";
}

// 2. Edit Mode Detect & Data Fetch
if ( isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id']) ) {
    $edit_mode = true;
    $res_data = qrrs_get_restaurant( intval($_GET['id']) );
}

// 3. Save/Update Logic
if ( isset($_POST['save_restaurant']) ) {
    if ( $edit_mode && isset($_GET['id']) ) {
        // Update existing
        $updated = qrrs_update_restaurant_settings( intval($_GET['id']), $_POST );
        if($updated) {
            echo "<div class='success-msg'>Restaurant updated successfully!</div>";
            
            // FORM CLEAR KORAR JONNO:
            $edit_mode = false;
            $res_data = null;
            
            // Ichche korle redirect-o kora jay jate URL theke action=edit chole jay
            echo "<script>window.location.href='?tab=restaurants';</script>";
        }
    } else {
        // Create new
        $res_id = qrrs_create_restaurant($_POST);
        if($res_id) {
            echo "<div class='success-msg'>Restaurant created successfully!</div>";
            // New create holeo automatic field faka thakbe
        }
    }
}
?>

<div class="qrrs-card">
    <div class="card-header">
        <h3><?php echo $edit_mode ? 'Edit Restaurant: ' . esc_html($res_data->restaurant_name) : 'Add New Restaurant'; ?></h3>
        <?php if($edit_mode): ?>
            <a href="?tab=restaurants" class="button button-secondary" style="float: right; margin-top: -30px;">Add New Instead</a>
        <?php endif; ?>
    </div>
    
    <form method="POST" class="qrrs-form">
        <div class="form-row">
            <div class="form-col">
                <label>Restaurant Name</label>
                <input type="text" name="res_name" required value="<?php echo $edit_mode ? esc_attr($res_data->restaurant_name) : ''; ?>">
            </div>
            
            <div class="form-col">
                <label>Restaurant Logo</label>
                <div class="qrrs-media-uploader">
                    <input type="hidden" name="res_logo" id="qrrs_res_logo_url" value="<?php echo $edit_mode ? esc_attr($res_data->restaurant_logo) : ''; ?>">
                    <div id="logo-preview">
                        <img src="<?php echo $edit_mode ? esc_url($res_data->restaurant_logo) : ''; ?>" 
                             style="max-width: 80px; <?php echo ($edit_mode && $res_data->restaurant_logo) ? '' : 'display: none;'; ?> margin-bottom: 5px; border-radius: 4px;">
                    </div>
                    <button type="button" class="button" id="upload_logo_btn">Select Logo</button>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-col">
                <label>Restaurant Address</label>
                <textarea name="res_address" rows="2"><?php echo $edit_mode ? esc_textarea($res_data->address) : ''; ?></textarea>
            </div>
        </div>

        <div class="form-row">
    <div class="form-col">
        <label>Contact Number</label>
        <input type="text" name="res_phone" placeholder="e.g. 017XXXXXXXX" value="<?php echo $edit_mode ? esc_attr($res_data->phone) : ''; ?>">
    </div>
    <div class="form-col">
        <label>BIN Number</label>
        <input type="text" name="res_bin" placeholder="VAT Registration Number" value="<?php echo $edit_mode ? esc_attr($res_data->bin_number) : ''; ?>">
    </div>
</div>

        <div class="form-row">
            <div class="form-col">
                <label>Currency Symbol</label>
                <input type="text" name="currency" value="<?php echo $edit_mode ? esc_attr($res_data->currency_symbol) : '৳'; ?>">
            </div>
            <div class="form-col">
                <label>VAT / Tax (%)</label>
                <input type="number" step="0.01" name="tax" value="<?php echo $edit_mode ? esc_attr($res_data->tax_percent) : '0'; ?>">
            </div>
            <div class="form-col">
                <label>Service Charge (%)</label>
                <input type="number" step="0.01" name="service_charge" value="<?php echo $edit_mode ? esc_attr($res_data->service_charge_percent) : '0'; ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-col">
                <label>POS Printer</label>
                <select name="pos_printer">
                    <option value="thermal_80mm" <?php echo ($edit_mode && $res_data->pos_printer_settings == 'thermal_80mm') ? 'selected' : ''; ?>>Thermal 80mm</option>
                    <option value="thermal_58mm" <?php echo ($edit_mode && $res_data->pos_printer_settings == 'thermal_58mm') ? 'selected' : ''; ?>>Thermal 58mm</option>
                </select>
            </div>
        </div>

        <div class="form-footer">
            <button type="submit" name="save_restaurant" class="save-btn">
                <?php echo $edit_mode ? 'Update Restaurant' : 'Create Restaurant'; ?>
            </button>
        </div>
    </form>
</div>


<hr style="margin: 40px 0; border: 1px solid #eee;">

<div class="qrrs-card">
    <div class="card-header">
        <h3>Manage Restaurants</h3>
    </div>
    <div class="qrrs-table-container">
        <table class="qrrs-table">
            <thead>
                <tr>
                    <th>restaurant_logo</th>
                    <th>Restaurant Name</th>
                    <th>Address</th>
                    <th>Contact Number</th>
                    <th>BIN Number</th>
                    <th>Tax/Service</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $all_res = qrrs_get_all_restaurants(); // Amader banano function
                if ( !empty($all_res) ) :
                    foreach ( $all_res as $res ) :
                ?>
                <tr>
                    <td>
                        <?php if($res->restaurant_logo): ?>
                            <img src="<?php echo esc_url($res->restaurant_logo); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                        <?php else: ?>
                            <span class="no-img">No Logo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html($res->restaurant_name); ?></strong><br>
                        <small>Currency: <?php echo esc_html($res->currency_symbol); ?></small>
                    </td>
                    <td><?php echo esc_html($res->address); ?></td>
                    <td>
                        <?php echo esc_html($res->phone); ?><br>
                    </td>
                    <td>
                        <?php echo esc_html($res->bin_number ?: 'N/A'); ?>
                    </td>
                    <td>
                        VAT: <?php echo $res->tax_percent; ?>%<br>
                        SC: <?php echo $res->service_charge_percent; ?>%
                    </td>
                    <td>
                        <span class="status-badge <?php echo $res->status == 'active' ? 'active' : 'inactive'; ?>">
                            <?php echo ucfirst($res->status); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <a href="?tab=restaurants&action=edit&id=<?php echo $res->id; ?>" class="edit-btn">Edit</a>
                            <a href="?tab=restaurants&action=delete&id=<?php echo $res->id; ?>" 
                               class="delete-btn" 
                               onclick="return confirm('Are you sure you want to delete this restaurant?')">Delete</a>
                        </div>
                    </td>
                </tr>
                <?php 
                    endforeach;
                else: 
                ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 30px;">No restaurants found. Please add one.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .qrrs-table-container { padding: 0; overflow-x: auto; }
    .qrrs-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .qrrs-table th, .qrrs-table td { padding: 12px 15px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
    .qrrs-table th { background: #f9f9f9; font-weight: 600; color: #555; }
    
    .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
    .status-badge.active { background: #e6fffa; color: #047481; }
    .status-badge.inactive { background: #fff5f5; color: #c53030; }

    .action-btns { display: flex; gap: 8px; }
    .edit-btn { background: #4a5568; color: #fff; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; }
    .delete-btn { background: #e53e3e; color: #fff; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; }
    
    .no-img { font-size: 10px; color: #999; border: 1px dashed #ccc; padding: 10px; border-radius: 4px; }
</style>

<script>
jQuery(document).ready(function($){
    $('#upload_logo_btn').click(function(e) {
        e.preventDefault();
        var image = wp.media({ title: 'Select Logo', multiple: false }).open()
        .on('select', function(){
            var url = image.state().get('selection').first().toJSON().url;
            $('#qrrs_res_logo_url').val(url);
            $('#logo-preview img').attr('src', url).show();
        });
    });
});
</script>

<style>
    .qrrs-card { border: 1px solid #ddd; border-radius: 8px; background: #fff; }
    .card-header { padding: 15px 20px; border-bottom: 1px solid #eee; background: #fcfcfc; }
    .card-header h3 { margin: 0; font-size: 18px; color: #333; }
    .qrrs-form { padding: 20px; }
    .form-row { display: flex; gap: 20px; margin-bottom: 20px; }
    .form-col { flex: 1; display: flex; flex-direction: column; }
    .form-col label { font-weight: 600; margin-bottom: 8px; font-size: 14px; color: #555; }
    .form-col input, .form-col textarea, .form-col select { padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
    .save-btn { background: #000; color: #fff; padding: 12px 25px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
    .save-btn:hover { background: #333; }
    .success-msg { background: #d1fae5; color: #065f46; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #a7f3d0; }
</style>