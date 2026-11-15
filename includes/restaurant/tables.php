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

// 📌 টেবিল যুক্ত করা
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

// 📌 টেবিল স্ট্যাটাস টগল (Active / Inactive)
if ( isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id']) ) {
    $table_id = intval($_GET['id']);
    $current_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_db WHERE id = %d AND restaurant_id = %d", $table_id, $active_res_id));
    
    // যদি ইনঅ্যাক্টিভ বা অকুপাইড থাকে তবে available, অন্যথায় inactive করবে
    $new_status = ($current_status == 'inactive') ? 'available' : 'inactive';
    
    $wpdb->update($table_db, ['status' => $new_status], ['id' => $table_id, 'restaurant_id' => $active_res_id]);
    echo "<div class='qrrs-toast success'>Table status updated successfully!</div>";
    echo "<script>setTimeout(function(){ window.location.href='?tab=tables'; }, 1000);</script>";
}

$active_res_name = $wpdb->get_var($wpdb->prepare("SELECT restaurant_name FROM $res_db WHERE id = %d", $active_res_id));
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div class="qrrs-card" style="padding: 20px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 25px;">
    <div class="card-header" style="margin-bottom: 15px;"><h3 style="margin:0; font-size: 18px; color: #1e293b;"><span class="material-icons-outlined" style="vertical-align: middle; font-size: 20px;">add_circle</span> Add New Table for <?php echo esc_html($active_res_name); ?></h3></div>
    
    <!-- 📌 এক লাইনে ফর্ম লেআউট -->
    <form method="POST" class="qrrs-form" style="margin:0;">
        <div style="display: grid; grid-template-columns: 1fr 1fr 120px auto; gap: 15px; align-items: flex-end;">
            
            <div class="form-col">
                <label style="display:block; font-weight:600; font-size: 13px; margin-bottom: 6px; color: #475569;">Current Restaurant</label>
                <input type="text" value="<?php echo esc_html($active_res_name); ?>" disabled style="width:100%; height: 42px; padding: 0 12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; color: #64748b; font-weight: 500;">
                <input type="hidden" name="restaurant_id" value="<?php echo $active_res_id; ?>">
            </div>

            <div class="form-col">
                <label style="display:block; font-weight:600; font-size: 13px; margin-bottom: 6px; color: #475569;">Table Name/Number</label>
                <input type="text" name="table_name" placeholder="e.g. Table 01" required style="width:100%; height: 42px; padding: 0 12px; border:1px solid #cbd5e1; border-radius:6px; font-size: 14px;">
            </div>

            <div class="form-col">
                <label style="display:block; font-weight:600; font-size: 13px; margin-bottom: 6px; color: #475569;">Capacity</label>
                <input type="number" name="capacity" value="4" style="width:100%; height: 42px; padding: 0 12px; border:1px solid #cbd5e1; border-radius:6px; font-size: 14px;">
            </div>

            <div class="form-col">
                <button type="submit" name="add_table" style="height: 42px; padding: 0 24px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s; white-space: nowrap;">
                    Create Table
                </button>
            </div>
        </div>
    </form>
</div>

<div class="qrrs-card-table" style="background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin:0; font-size: 18px; color: #1e293b;"><span class="material-icons-outlined" style="vertical-align: middle; font-size: 20px;">manage_history</span> Manage Tables (<?php echo esc_html($active_res_name); ?>)</h3>
        <button id="print-all-qr" style="background:#059669; color:#fff; border:none; padding:10px 18px; border-radius:6px; cursor:pointer; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
            🖨️ Print All QR Codes
        </button>
    </div>
    
    <table class="qrrs-table" style="width:100%; border-collapse: collapse;">
        <thead>
            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align: left;">
                <th style="padding: 14px; color: #475569; font-size: 13px;">Table Name</th>
                <th style="padding: 14px; color: #475569; font-size: 13px;">Capacity</th>
                <th style="padding: 14px; color: #475569; font-size: 13px;">Status</th>
                <th style="padding: 14px; color: #475569; font-size: 13px;">QR Code</th>
                <th style="padding: 14px; color: #475569; font-size: 13px;">QR Token</th>
                <th style="padding: 14px; color: #475569; font-size: 13px; text-align: right;">Action</th>
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
                    $is_inactive = ($row->status === 'inactive');
            ?>
            <tr style="border-bottom:1px solid #f1f5f9; transition: background 0.1s;">
                <td style="padding: 14px;"><strong style="color: #1e293b; font-size: 14px;"><?php echo esc_html($row->table_name); ?></strong></td>
                <td style="padding: 14px; color: #64748b; font-size: 13px;"><?php echo $row->capacity; ?> Persons</td>
                
                <!-- 📌 স্ট্যাটাস ব্যাজ -->
                <td style="padding: 14px;">
                    <?php if($is_inactive): ?>
                        <span style="background: #fee2e2; color: #dc2626; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase;">Inactive</span>
                    <?php else: ?>
                        <span style="background: #dcfce7; color: #16a34a; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase;">Active</span>
                    <?php endif; ?>
                </td>

                <td style="padding: 14px;">
                    <button class="print-qr-btn" 
                            data-link="<?php echo esc_url($qr_link); ?>" 
                            data-table="<?php echo esc_attr($row->table_name); ?>"
                            data-res="<?php echo esc_attr($active_res_name); ?>"
                            style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px;">
                        🖨️ Print QR
                    </button>
                </td>
                <td style="padding: 14px;">
                    <code style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; color: #475569; font-size: 12px;">
                        <?php echo esc_html($row->qr_token); ?>
                    </code>
                </td>
                
                <!-- 📌 ডিলিটের পরিবর্তে প্রফেশনাল Active/Inactive টগল বাটন -->
                <td style="padding: 14px; text-align: right;">
                    <a href="?tab=tables&action=toggle_status&id=<?php echo $row->id; ?>" 
                       style="display: inline-block; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; 
                       <?php echo $is_inactive ? 'background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0;' : 'background: #fee2e2; color: #dc2626; border: 1px solid #fecaca;'; ?>">
                        <?php echo $is_inactive ? 'Set Active' : 'Set Inactive'; ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" style="text-align:center; padding:30px; color: #64748b;">No tables created yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="print-all-container" style="display:none;"></div>

<div id="qrModal" class="qr-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div class="qr-modal-content" style="background:#fff; padding:25px; border-radius:12px; width:350px; text-align:center; position:relative;">
        <span class="close-modal" style="position:absolute; top:10px; right:15px; font-size:22px; cursor:pointer; color:#64748b;">&times;</span>
        <div id="printable-qr-area" style="text-align: center; padding: 10px 0;">
            <h2 id="modal-res-name" style="margin:0 0 5px 0; font-size: 18px; color: #1e293b;"></h2>
            <h3 id="modal-table-name" style="margin:0 0 15px 0; font-size: 15px; color: #64748b;"></h3>
            <div id="qrcode" style="display: flex; justify-content: center; margin: 15px 0;"></div>
            <p style="font-size: 13px; color: #94a3b8; margin: 10px 0 0 0;">Scan to View Menu & Order</p>
        </div>
        <button onclick="printQR()" class="save-btn" style="width: 100%; background:#2563eb; color:#fff; border:none; padding:10px; border-radius:6px; cursor:pointer; font-weight:600; margin-top: 15px;">Print Now</button>
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
        printWindow.document.write('<style>@media print { .qr-item { page-break-inside: avoid; margin-bottom: 40px; text-align: center; font-family: sans-serif; float: left; width: 33.33%; padding: 15px; box-sizing: border-box; } img { max-width: 100%; height: auto; display: block; margin: 10px auto; } }</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write('<div id="all-qr-wrapper"></div>');
        printWindow.document.write('</body></html>');

        var wrapper = printWindow.document.getElementById('all-qr-wrapper');

        tables.forEach(function(item, index) {
            var itemDiv = printWindow.document.createElement('div');
            itemDiv.className = 'qr-item';
            itemDiv.innerHTML = `
                <h2 style="font-size:16px; margin:0; color:#000;">${item.res}</h2>
                <h3 style="font-size:18px; margin:5px 0; color:#333;">${item.table}</h3>
                <div id="qr-all-${index}"></div>
                <p style="font-size:12px; color:#666; margin:5px 0 0 0;">Scan to Order</p>
            `;
            wrapper.appendChild(itemDiv);

            new QRCode(printWindow.document.getElementById('qr-all-' + index), {
                text: item.link,
                width: 220,
                height: 220
            });
        });

        setTimeout(function(){
            printWindow.print();
            printWindow.close();
        }, 1000);
    });

    var qrcode = new QRCode(document.getElementById("qrcode"), {
        width: 200,
        height: 200
    });

    $('.print-qr-btn').on('click', function(){
        var link = $(this).data('link');
        var table = $(this).data('table');
        var res = $(this).data('res');

        $('#modal-res-name').text(res);
        $('#modal-table-name').text(table);
        qrcode.clear();
        qrcode.makeCode(link);
        $('#qrModal').css('display', 'flex').hide().fadeIn();
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