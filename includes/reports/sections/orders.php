<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$active_res_id = isset($active_res_id) ? $active_res_id : (isset($_SESSION['qrrs_active_res_id']) ? $_SESSION['qrrs_active_res_id'] : 0);
if ( ! $active_res_id ) return;

// Restaurant info
$restaurant  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $active_res_id));
$res_name    = !empty($restaurant->restaurant_name) ? $restaurant->restaurant_name : get_bloginfo('name');
$res_address = !empty($restaurant->address)         ? $restaurant->address         : '';
$res_phone   = !empty($restaurant->phone)           ? $restaurant->phone           : '';
$res_logo    = !empty($restaurant->restaurant_logo) ? $restaurant->restaurant_logo : '';
$res_bin     = !empty($restaurant->bin_number)      ? $restaurant->bin_number      : '';
?>

<div class="report-layout">

    <!-- Sidebar -->
    <aside style="width:280px; background:#fff; border-radius:12px; border:1px solid #eee; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.02); flex-shrink:0;">
        <h4 style="margin-top:0; color:#1e293b; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Filter Orders</h4>

        <form id="order-report-form" style="display:flex; flex-direction:column; gap:15px; margin-top:15px; padding: 0;">
            <input type="hidden" name="restaurant_id" value="<?php echo $active_res_id; ?>">

            <div class="filter-group">
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Report Type</label>
                <select name="order_report_type" id="order-report-type" class="filter-input">
                    <option value="total_orders">Total Orders</option>
                    <option value="avg_order_value">Average Order Value</option>
                    <option value="peak_hours">Peak Hours</option>
                </select>
            </div>

            <div class="filter-group">
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Date Range</label>
                <div style="position:relative; margin-top:5px;">
                    <input type="text" id="order-date-range" name="date_range" class="filter-input" placeholder="Select dates..." readonly style="cursor:pointer; background:#fff; padding-right:30px;">
                    <span style="position:absolute; right:10px; top:10px; opacity:0.5;">📅</span>
                </div>
            </div>

            <button type="button" id="generate-order-report" class="qrrs-btn-primary">
                Generate Report
            </button>
        </form>
    </aside>

    <!-- Main Content -->
    <main style="flex:1; background:#fff; border-radius:12px; border:1px solid #eee; padding:25px; box-shadow:0 2px 10px rgba(0,0,0,0.02); min-width:0;">

        <!-- Action Bar -->
        <div id="order-report-action-bar" style="display:none; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #f1f5f9;">
            <h4 id="order-report-title" style="margin:0; color:#1e293b; font-size:15px;">Order Report</h4>
            <div style="display:flex; gap:8px;">
                <button onclick="printOrderReport()" style="background:#f8fafc; color:#1e293b; border:1px solid #e2e8f0; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print
                </button>
                <button onclick="exportOrderExcel()" style="background:#1a7431; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Excel
                </button>
                <button onclick="exportOrderPDF()" style="background:#c0392b; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    PDF
                </button>
            </div>
        </div>

        <div id="order-report-result">
            <div style="text-align:center; color:#94a3b8; margin-top:120px;">
                <div style="font-size:50px; opacity:0.2;">🛒</div>
                <h4>Ready to Generate Report</h4>
                <p>Choose your parameters and click the button.</p>
            </div>
        </div>
    </main>
</div>

<script>
var qrrsOrderResInfo = {
    name:    "<?php echo esc_js($res_name); ?>",
    address: "<?php echo esc_js($res_address); ?>",
    phone:   "<?php echo esc_js($res_phone); ?>",
    logo:    "<?php echo esc_js($res_logo); ?>",
    bin:     "<?php echo esc_js($res_bin); ?>"
};

var orderReportTitles = {
    'total_orders'    : 'Total Orders Report',
    'avg_order_value' : 'Average Order Value Report',
    'peak_hours'      : 'Peak Hours Report'
};

jQuery(document).ready(function($) {

    $("#order-date-range").flatpickr({
        mode: "range",
        dateFormat: "Y-m-d",
        defaultDate: ["<?php echo date('Y-m-01'); ?>", "<?php echo date('Y-m-d'); ?>"]
    });

    $('#generate-order-report').on('click', function() {
        var $btn       = $(this);
        var reportType = $('#order-report-type').val();
        $btn.prop('disabled', true).text('Generating...');

        $('#order-report-title').text(orderReportTitles[reportType] || 'Order Report');

        $.post(qrrs_vars.ajax_url, {
            action:   'fetch_order_report',
            formData: $('#order-report-form').serialize(),
            security: qrrs_vars.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Generate Report');
            if (response.success && response.data && response.data.data) {
                $('#order-report-result').html(response.data.data);
                $('#order-report-action-bar').css('display', 'flex');
            } else {
                $('#order-report-result').html('<p style="text-align:center; color:#e74c3c; padding:40px;">No data found for the selected period.</p>');
            }
        });
    });
});

function buildOrderHeader(dateRange) {
    var logoHtml = qrrsOrderResInfo.logo ? '<img src="' + qrrsOrderResInfo.logo + '" style="max-width:80px; height:auto; margin-bottom:6px;"><br>' : '';
    var binHtml  = qrrsOrderResInfo.bin  ? '<p style="margin:2px 0; font-size:11px;">BIN: ' + qrrsOrderResInfo.bin + '</p>' : '';
    var title    = document.getElementById('order-report-title') ? document.getElementById('order-report-title').textContent : 'Order Report';
    return '<div style="text-align:center; font-family:Courier New,monospace; margin-bottom:20px; padding-bottom:12px; border-bottom:2px double #000;">'
        + logoHtml
        + '<h2 style="margin:0; font-size:20px; text-transform:uppercase;">' + qrrsOrderResInfo.name + '</h2>'
        + '<p style="margin:2px 0; font-size:13px;">' + qrrsOrderResInfo.address + '</p>'
        + '<p style="margin:2px 0; font-size:13px;">Phone: ' + qrrsOrderResInfo.phone + '</p>'
        + binHtml
        + '<p style="margin:8px 0 0; font-size:13px; font-weight:bold;">' + title.toUpperCase() + ' &mdash; ' + dateRange + '</p>'
        + '</div>';
}

function buildOrderFooter() {
    return '<div style="text-align:center; margin-top:25px; padding-top:10px; border-top:1px dashed #000; font-size:12px;">'
        + '<p style="margin:2px 0;">Thank You for Visiting!</p>'
        + '<p style="margin:2px 0; font-weight:bold;">Software by: Nazmul Hosen</p>'
        + '</div>';
}

function printOrderReport() {
    var dateRange = document.getElementById('order-date-range').value || '';
    var resultEl = document.getElementById('order-report-result');
    if (!resultEl) return;

    var clone = resultEl.cloneNode(true);
    
    clone.querySelectorAll('button, .action-buttons').forEach(function(el) { 
        el.remove(); 
    });

    var win = window.open('', '_blank', 'width=1000,height=800');
    
    win.document.write('<html><head><title>Order Report</title>');
    
    win.document.write('<style>'
        + 'body { font-family: "Segoe UI", system-ui, sans-serif; padding: 40px; color: #1e293b; background: #fff; }'
        + 'table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 13px; }'
        + 'th { background: #f8fafc; padding: 12px 10px; text-align: left; border-bottom: 2px solid #cbd5e1; font-weight: 600; color: #334155; }'
        + 'td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; color: #475569; vertical-align: middle; }'
        + 'tr:last-child td { border-top: 2px solid #2271b1; font-weight: bold; background: #f0f6fb; color: #1e293b; }'
        
        + '.report-layout main > div:first-of-type, div[style*="display: flex"], div[style*="grid"] {'
        + '    display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 20px !important; margin-bottom: 30px !important;'
        + '}'
        
        + 'div[style*="border-radius"] { border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; position: relative; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }'
        + 'div[style*="font-size: 50px"], div[style*="font-size:50px"] { font-size: 40px !important; margin-bottom: 10px; }'
        
        + '@media print {'
        + '  body { padding: 0; }'
        + '  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }'
        + '}'
        + '</style></head><body>');
    
    win.document.write(buildOrderHeader(dateRange));
    
    win.document.write('<div class="report-print-body">' + clone.innerHTML + '</div>');
    
    win.document.write(buildOrderFooter());
    
    win.document.write('</body></html>');
    win.document.close();
    win.focus();
    
    setTimeout(function() { 
        win.print(); 
        win.close(); 
    }, 500);
}

function exportOrderExcel() {
    var table = document.querySelector('#order-report-result table');
    if (!table) { alert('Please generate a report first.'); return; }
    if (typeof XLSX === 'undefined') {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
        s.onload = function() { doOrderExcel(table); };
        document.head.appendChild(s);
    } else { doOrderExcel(table); }
}

function doOrderExcel(table) {
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.table_to_sheet(table);
    XLSX.utils.book_append_sheet(wb, ws, 'Order Report');
    XLSX.writeFile(wb, 'order-report-' + new Date().toISOString().slice(0,10) + '.xlsx');
}

function exportOrderPDF() {
    var table = document.querySelector('#order-report-result table');
    if (!table) { alert('Please generate a report first.'); return; }
    if (typeof window.jspdf === 'undefined') {
        var s1 = document.createElement('script');
        s1.src = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js';
        s1.onload = function() {
            var s2 = document.createElement('script');
            s2.src = 'https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js';
            s2.onload = function() { doOrderPDF(table); };
            document.head.appendChild(s2);
        };
        document.head.appendChild(s1);
    } else { doOrderPDF(table); }
}

function doOrderPDF(table) {
    var dateRange = document.getElementById('order-date-range').value || '';
    var title     = document.getElementById('order-report-title') ? document.getElementById('order-report-title').textContent : 'Order Report';
    var info      = qrrsOrderResInfo;

    var { jsPDF } = window.jspdf;
    var doc   = new jsPDF({ orientation: 'landscape' });
    var pageW = doc.internal.pageSize.getWidth();
    var y     = 12;

    if (info.logo) {
        try { doc.addImage(info.logo, 'PNG', (pageW/2)-12, y, 24, 14); y += 18; } catch(e) { y += 2; }
    }
    doc.setFontSize(16); doc.setFont(undefined, 'bold');
    doc.text(info.name.toUpperCase(), pageW/2, y, { align: 'center' }); y += 6;
    doc.setFontSize(9); doc.setFont(undefined, 'normal');
    if (info.address) { doc.text(info.address, pageW/2, y, { align: 'center' }); y += 5; }
    if (info.phone)   { doc.text('Phone: ' + info.phone, pageW/2, y, { align: 'center' }); y += 5; }
    if (info.bin)     { doc.text('BIN: ' + info.bin, pageW/2, y, { align: 'center' }); y += 5; }

    doc.setLineWidth(0.5); doc.line(14, y, pageW-14, y); y += 6;
    doc.setFontSize(12); doc.setFont(undefined, 'bold');
    doc.text(title.toUpperCase(), 14, y);
    doc.setFontSize(9); doc.setFont(undefined, 'normal');
    doc.text('Period: ' + dateRange, 14, y+5);
    doc.text('Generated: ' + new Date().toLocaleDateString(), pageW-14, y+5, { align: 'right' });
    y += 13;

    doc.autoTable({
        html: table,
        startY: y,
        styles: { fontSize: 8, cellPadding: 3 },
        headStyles: { fillColor: [30, 41, 59], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [248, 250, 252] },
        footStyles: { fillColor: [240, 246, 251], textColor: [34, 113, 177], fontStyle: 'bold' },
        margin: { left: 14, right: 14 }
    });

    var fy = doc.lastAutoTable.finalY + 8;
    doc.setLineWidth(0.3); doc.setLineDashPattern([2,2], 0);
    doc.line(14, fy, pageW-14, fy); doc.setLineDashPattern([], 0);
    doc.setFontSize(8);
    doc.text('Thank You for Visiting!', pageW/2, fy+5, { align: 'center' });
    doc.text('Software by: Nazmul Hosen', pageW/2, fy+10, { align: 'center' });

    doc.save('order-report-' + new Date().toISOString().slice(0,10) + '.pdf');
}
</script>


<style>
    @media print {
    body.qrrs-printing-in-progress > * {
        display: none !important;
    }

    body.qrrs-printing-in-progress .report-layout,
    body.qrrs-printing-in-progress .report-layout main,
    body.qrrs-printing-in-progress #order-report-result {
        display: block !important;
        position: relative !important;
        width: 100% !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        float: none !important;
        overflow: visible !important;
    }
    
    #order-report-action-bar, button, .report-layout aside {
        display: none !important;
    }

    body {
        background: #ffffff !important;
        color: #000000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>