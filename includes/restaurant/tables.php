<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table_db = $wpdb->prefix . 'qrrs_tables';
$res_db   = $wpdb->prefix . 'qrrs_restaurants';

if ( current_user_can('administrator') ) {
    if ( ! session_id() ) session_start();
    $active_res_id = isset($_SESSION['qrrs_active_res_id']) ? intval($_SESSION['qrrs_active_res_id']) : 0;
} else {
    $active_res_id = get_user_meta(get_current_user_id(), 'assigned_restaurant', true);
}

if (!$active_res_id) {
    echo '<div style="padding:50px; text-align:center;"><h3>❌ Please select a restaurant from the dashboard first.</h3></div>';
    return;
}

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
    echo "<div class='qrrs-toast success'>Table '$t_name' added successfully!</div>";
}

if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    $wpdb->delete($table_db, [
        'id'            => intval($_GET['id']),
        'restaurant_id' => $active_res_id
    ]);
    echo "<div class='qrrs-toast success'>Table deleted successfully!</div>";
    
    echo "<script>setTimeout(function(){ window.location.href='?tab=tables'; }, 2000);</script>";
}

$active_res_name = $wpdb->get_var($wpdb->prepare("SELECT restaurant_name FROM $res_db WHERE id = %d", $active_res_id));
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div class="qrrs-card">
    <div class="card-header"><h3 style="margin-top:0;"><span class="material-icons-outlined">add</span> Add New Table for <?php echo esc_html($active_res_name); ?></h3></div>
    <form method="POST" class="qrrs-form">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
            
            <div class="form-row">
                <div class="form-col">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Current Restaurant</label>
                    <input type="text" value="<?php echo esc_html($active_res_name); ?>" disabled style="width:100%; padding:8px; background:#f5f5f5; border:1px solid #ddd;">
                    <input type="hidden" name="restaurant_id" value="<?php echo $active_res_id; ?>">
                </div>
            </div>
            <div class="form-row">            
                <div class="form-col">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Table Name/Number</label>
                    <input type="text" name="table_name" placeholder="e.g. Table 01" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>
            <div class="form-row">
                <div class="form-col">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Capacity</label>
                    <input type="number" name="capacity" value="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>
        </div>

        <div class="form-footer">
            <button type="submit" name="add_table">Create & Generate QR</button>
        </div>
    </form>
</div>

<hr style="margin: 20px 0; border:0; border-top:1px solid #eee;">

<div class="qrrs-card-table">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3><span class="material-icons-outlined">manage_history</span> Manage Tables (<?php echo esc_html($active_res_name); ?>)</h3>
        <button id="print-all-qr" class="save-btn" style="background:#059669; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">
            🖨️ Print All QR Codes
        </button>
    </div>
    <table class="qrrs-table" style="float:left;width:100%; border-collapse: collapse; margin:15px 0 0 0;">
        <thead>
            <tr style="background:#f8f9fa; border-bottom:2px solid #eee;">
                <th style="padding:12px; text-align:left;">Table Name</th>
                <th style="padding:12px; text-align:left;">Capacity</th>
                <th style="padding:12px; text-align:left;">QR Code</th>
                <th style="padding:12px; text-align:left;">QR Token</th>
                <th style="padding:12px; text-align:left;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $tables = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_db WHERE restaurant_id = %d ORDER BY id DESC",
                $active_res_id
            ));

            if($tables):
                foreach($tables as $row):
                    $qr_link = home_url('/menu/?token=' . $row->qr_token);
            ?>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:12px;"><strong><?php echo esc_html($row->table_name); ?></strong></td>
                <td style="padding:12px;"><?php echo $row->capacity; ?> Persons</td>
                <td style="padding:12px;">
                    <button class="print-qr-btn" 
                            data-link="<?php echo esc_url($qr_link); ?>" 
                            data-table="<?php echo esc_attr($row->table_name); ?>"
                            data-res="<?php echo esc_attr($active_res_name); ?>">
                        🖨️ Print QR
                    </button>
                </td>
                <td style="padding:12px;">
                <code style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; color: #475569; font-size: 12px;">
                    <?php echo esc_html($row->qr_token); ?>
                </code>
            </td>
                <td style="padding:12px;">
                    <a href="?tab=tables&action=delete&id=<?php echo $row->id; ?>" class="delete-link" onclick="return confirm('Delete this table?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" style="text-align:center; padding:20px;">No tables created yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="print-all-container" style="display:none;"></div>

<div id="qrModal" class="qr-modal">
    <div class="qr-modal-content">
        <span class="close-modal">&times;</span>
        <div id="printable-qr-area" style="text-align: center; padding: 20px;">
            <h2 id="modal-res-name" style="margin-bottom: 5px; font-family: sans-serif;"></h2>
            <h3 id="modal-table-name" style="margin-top: 0; color: #555; font-family: sans-serif;"></h3>
            <div id="qrcode" style="display: flex; justify-content: center; margin: 20px 0;"></div>
            <p style="font-size: 14px; color: #888;">Scan to View Menu & Order</p>
        </div>
        <button onclick="printQR()" class="save-btn" style="width: 100%; background:#2271b1; color:#fff; border:none; padding:12px; border-radius:5px; cursor:pointer; font-weight:bold;">Print Now</button>
    </div>
</div>

<script>
    jQuery(document).ready(function($){
    
    
    function handleToast() {
            if ($('.qrrs-toast').length > 0) {
                setTimeout(function() {
                    $('.qrrs-toast').addClass('toast-fade-out');
                    setTimeout(function() {
                        $('.qrrs-toast').remove();
                    }, 500);
                }, 3000);
            }
        }
        handleToast();



    $('#print-all-qr').on('click', function(){
        var printContainer = $('#print-all-container');
        printContainer.empty(); 
        
        var tables = [];
        $('.print-qr-btn').each(function(){
            tables.push({
                link: $(this).data('link'),
                table: $(this).data('table'),
                res: $(this).data('res')
            });
        });

        if(tables.length === 0) {
            alert('No tables found to print!');
            return;
        }

        var printWindow = window.open('', '_blank');
        printWindow.document.write('<html><head><title>Print All QR</title>');
        printWindow.document.write('<style>@media print { .qr-item { page-break-inside: avoid; margin-bottom: 50px; text-align: center; font-family: sans-serif; float: left; width: 33.33%; padding: 20px; box-sizing: border-box; } img { max-width: 100%; height: auto; display: block; margin: 10px auto; } }</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write('<div id="all-qr-wrapper"></div>');
        printWindow.document.write('</body></html>');

        var wrapper = printWindow.document.getElementById('all-qr-wrapper');

        tables.forEach(function(item, index) {
            var itemDiv = printWindow.document.createElement('div');
            itemDiv.className = 'qr-item';
            itemDiv.innerHTML = `
                <h2 style="font-size:16px; margin:0;">${item.res}</h2>
                <h3 style="font-size:20px; margin:5px 0;">${item.table}</h3>
                <div id="qr-all-${index}"></div>
                <p style="font-size:12px; color:#666;">Scan to Order</p>
            `;
            wrapper.appendChild(itemDiv);

            new QRCode(printWindow.document.getElementById('qr-all-' + index), {
                text: item.link,
                width: 250,
                height: 250
            });
        });

        setTimeout(function(){
            printWindow.print();
            printWindow.close();
        }, 1000);
    });
});

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
    window.location.reload(); 
}
</script>

