<?php
if ( ! defined( 'ABSPATH' ) ) exit;
QRRS_Auth::is_admin_only();

global $wpdb;
$edit_mode = false;
$res_data = null;

// 1. Delete Logic
if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    qrrs_delete_restaurant( intval($_GET['id']) );
    // success-msg replace kore qrrs-toast success kora hoyeche
    echo "<div class='qrrs-toast success'>Restaurant deleted successfully!</div>";
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
            echo "<div class='qrrs-toast success'>Restaurant updated successfully!</div>";
            
            $edit_mode = false;
            $res_data = null;
            
            // Redirect with a delay to let the toast be visible for a moment
            echo "<script>
                setTimeout(function(){
                    window.location.href='?tab=restaurants';
                }, 2000); 
            </script>";
        }
    } else {
        // Create new
        $res_id = qrrs_create_restaurant($_POST);
        if($res_id) {
            echo "<div class='qrrs-toast success'>Restaurant created successfully!</div>";
        }
    }
}
?>

<div class="items-add">
    <div class="qrrs-card">
        <div class="card-header">
            <h3><?php echo $edit_mode ? '<span class="material-icons-outlined">edit_note</span> Edit Restaurant: ' . esc_html($res_data->restaurant_name) : '<span class="material-icons-outlined">add</span> Add New Restaurant'; ?></h3>
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
                <button type="submit" name="save_restaurant">
                    <?php echo $edit_mode ? '<span class="material-icons-outlined">update</span> Update Restaurant' : '<span class="material-icons-outlined">add_task</span> Create Restaurant'; ?>
                </button>
            </div>
        </form>
    </div>
<<<<<<< HEAD


    <hr style="margin: 20px 0; border: 1px solid #eee;">

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
    </div>

</div>


=======


    <hr style="margin: 20px 0; border: 1px solid #eee;">

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
    </div>

</div>

>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da

<script>
jQuery(document).ready(function($){
    // Existing Logo Upload Logic...
    $('#upload_logo_btn').click(function(e) {
        e.preventDefault();
        var image = wp.media({ title: 'Select Logo', multiple: false }).open()
        .on('select', function(){
            var url = image.state().get('selection').first().toJSON().url;
            $('#qrrs_res_logo_url').val(url);
            $('#logo-preview img').attr('src', url).show();
        });
    });

<<<<<<< HEAD
    // --- Toast Notification Auto-Hide Logic ---
    if ($('.qrrs-toast').length > 0) {
        setTimeout(function() {
            $('.qrrs-toast').addClass('toast-fade-out');
            setTimeout(function() {
                $('.qrrs-toast').remove();
            }, 500); // Animation shesh hole DOM theke remove korbe
        }, 3000); // 3 second por fade out shuru hobe
    }
});
</script>
=======
<style>
    
</style>
>>>>>>> 72c4cdaffa1d6d95cf252b9e8385522e120f65da
