<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table_db = $wpdb->prefix . 'qrrs_tables';
$res_db   = $wpdb->prefix . 'qrrs_restaurants';

// [Ager PHP logic gulo (Add/Delete) thik thakbe...]
if ( isset($_POST['add_table']) ) {
    $res_id     = intval($_POST['restaurant_id']);
    $t_name     = sanitize_text_field($_POST['table_name']);
    $capacity   = intval($_POST['capacity']);
    $qr_token   = wp_generate_password(12, false); 

    $wpdb->insert($table_db, [
        'restaurant_id' => $res_id,
        'table_name'    => $t_name,
        'capacity'      => $capacity,
        'qr_token'      => $qr_token,
        'status'        => 'available'
    ]);
}

if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    $wpdb->delete($table_db, ['id' => intval($_GET['id'])]);
}

$current_user_id = get_current_user_id();
if ( current_user_can('administrator') ) {
    $restaurants = $wpdb->get_results("SELECT id, restaurant_name FROM $res_db");
} else {
    $assigned_res = get_user_meta($current_user_id, 'assigned_restaurant', true);
    $restaurants = $wpdb->get_results($wpdb->prepare("SELECT id, restaurant_name FROM $res_db WHERE id = %d", $assigned_res));
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div class="qrrs-card">
    <div class="card-header"><h3>Add New Table</h3></div>
    <form method="POST" class="qrrs-form">
        <div class="form-row">
            <div class="form-col">
                <label>Select Restaurant</label>
                <select name="restaurant_id" required>
                    <?php foreach($restaurants as $res): ?>
                        <option value="<?php echo $res->id; ?>"><?php echo esc_html($res->restaurant_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-col">
                <label>Table Name/Number</label>
                <input type="text" name="table_name" placeholder="Table 01" required>
            </div>
            <div class="form-col">
                <label>Capacity</label>
                <input type="number" name="capacity" value="4">
            </div>
        </div>
        <button type="submit" name="add_table" class="save-btn">Create & Generate QR</button>
    </form>
</div>

<hr style="margin: 40px 0;">

<div class="qrrs-card">
    <div class="card-header"><h3>Manage Tables</h3></div>
    <table class="qrrs-table">
        <thead>
            <tr>
                <th>Table Name</th>
                <th>Restaurant</th>
                <th>Capacity</th>
                <th>QR Code</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $query = current_user_can('administrator') 
                     ? "SELECT t.*, r.restaurant_name as res_name FROM $table_db t JOIN $res_db r ON t.restaurant_id = r.id ORDER BY t.id DESC"
                     : $wpdb->prepare("SELECT t.*, r.restaurant_name as res_name FROM $table_db t JOIN $res_db r ON t.restaurant_id = r.id WHERE t.restaurant_id = %d ORDER BY t.id DESC", $assigned_res);
            
            $tables = $wpdb->get_results($query);

            if($tables):
                foreach($tables as $row):
                    $qr_link = home_url('/menu/?token=' . $row->qr_token);
            ?>
            <tr>
                <td><strong><?php echo esc_html($row->table_name); ?></strong></td>
                <td><?php echo esc_html($row->res_name); ?></td>
                <td><?php echo $row->capacity; ?> P</td>
                <td>
                    <button class="print-qr-btn" 
                            data-link="<?php echo esc_url($qr_link); ?>" 
                            data-table="<?php echo esc_attr($row->table_name); ?>"
                            data-res="<?php echo esc_attr($row->res_name); ?>">
                        🖨️ Print QR
                    </button>
                </td>
                <td>
                    <a href="?tab=tables&action=delete&id=<?php echo $row->id; ?>" class="delete-link" onclick="return confirm('Delete?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="qrModal" class="qr-modal">
    <div class="qr-modal-content">
        <span class="close-modal">&times;</span>
        <div id="printable-qr-area" style="text-align: center; padding: 20px;">
            <h2 id="modal-res-name" style="margin-bottom: 5px;"></h2>
            <h3 id="modal-table-name" style="margin-top: 0; color: #555;"></h3>
            <div id="qrcode" style="display: flex; justify-content: center; margin: 20px 0;"></div>
            <p style="font-size: 14px; color: #888;">Scan to View Menu & Order</p>
        </div>
        <button onclick="printQR()" class="save-btn" style="width: 100%;">Print Now</button>
    </div>
</div>

<script>
jQuery(document).ready(function($){
    var qrcode = new QRCode(document.getElementById("qrcode"), {
        width: 250,
        height: 250
    });

    $('.print-qr-btn').on('click', function(){
        var link = $(this).data('link');
        var table = $(this).data('table');
        var res = $(this).data('res');

        $('#modal-res-name').text(res);
        $('#modal-table-name').text(table);
        qrcode.clear();
        qrcode.makeCode(link);
        $('#qrModal').fadeIn();
    });

    $('.close-modal').on('click', function(){
        $('#qrModal').fadeOut();
    });
});

function printQR() {
    var printContents = document.getElementById('printable-qr-area').innerHTML;
    var originalContents = document.body.innerHTML;
    document.body.innerHTML = printContents;
    window.print();
    document.body.innerHTML = originalContents;
    window.location.reload(); // Re-bind JS events
}
</script>

<style>
    .print-qr-btn { background: #edf2f7; border: 1px solid #cbd5e0; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .print-qr-btn:hover { background: #e2e8f0; }
    .delete-link { color: #e53e3e; font-size: 12px; text-decoration: none; }
    
    /* Modal Style */
    .qr-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
    .qr-modal-content { background: #fff; margin: 10% auto; padding: 20px; width: 350px; border-radius: 10px; position: relative; }
    .close-modal { position: absolute; right: 15px; top: 10px; font-size: 24px; cursor: pointer; }
    
    @media print {
        body * { visibility: hidden; }
        #printable-qr-area, #printable-qr-area * { visibility: visible; }
        #printable-qr-area { position: absolute; left: 0; top: 0; width: 100%; }
    }
</style>