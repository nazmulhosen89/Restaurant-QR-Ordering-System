<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$active_res_id = isset($active_res_id) ? $active_res_id : (isset($_SESSION['qrrs_active_res_id']) ? $_SESSION['qrrs_active_res_id'] : 0);
if ( ! $active_res_id ) return;

$restaurant  = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $active_res_id
));
$res_name    = !empty($restaurant->restaurant_name) ? $restaurant->restaurant_name : get_bloginfo('name');
$res_address = !empty($restaurant->address)         ? $restaurant->address         : '';
$res_phone   = !empty($restaurant->phone)           ? $restaurant->phone           : '';
$res_logo    = !empty($restaurant->restaurant_logo) ? $restaurant->restaurant_logo : '';
$res_bin     = !empty($restaurant->bin_number)      ? $restaurant->bin_number      : '';

$categories = $wpdb->get_results($wpdb->prepare(
    "SELECT id, category_name FROM {$wpdb->prefix}qrrs_categories WHERE restaurant_id = %d",
    $active_res_id
));
?>

<div class="report-layout">

    <!-- Left Sidebar -->
    <aside class="sales-sidebar" style="width: 280px; background: #fff; border-radius: 12px; border: 1px solid #eee; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); flex-shrink:0;">
        <h4 style="margin-top: 0; color: #1e293b; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">Filter Reports</h4>

        <form id="sales-filter-form" style="display: flex; flex-direction: column; gap: 15px; margin-top: 15px; padding: 0;">
            <input type="hidden" name="restaurant_id" value="<?php echo $active_res_id; ?>">

            <!-- Report Type -->
            <div class="filter-group">
                <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Report Type</label>
                <select name="report_type" id="report-type-select" class="filter-input">
                    <option value="general_sales">Sales Report</option>
                    <option value="item_wise">Item-wise Sales Report</option>
                    <option value="category_wise">Category-wise Sales Report</option>
                    <option value="order_type_wise">Dine-in vs Takeaway vs Delivery</option>
                </select>
            </div>

            <!-- Date Range Picker -->
            <div class="filter-group">
                <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Select Date Range</label>
                <div class="date-input-wrapper" style="position:relative; margin-top:5px;">
                    <input type="text" id="qrrs-date-range" name="date_range" class="filter-input" placeholder="Select dates..." readonly style="cursor:pointer; background:#fff; width:100%; padding-right:30px;">
                    <span style="position:absolute; right:10px; top:10px; opacity:0.5;">📅</span>
                </div>
            </div>

            <!-- Category -->
            <div class="filter-group">
                <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Category</label>
                <select name="category_id" class="filter-input">
                    <option value="all">All Categories</option>
                    <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat->id; ?>"><?php echo esc_html($cat->category_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="button" id="generate-sales-report" class="qrrs-btn-primary">
                Generate Report
            </button>
        </form>
    </aside>

    <!-- Right Content Area -->
    <main class="sales-main-content" style="flex: 1; background: #fff; border-radius: 12px; border: 1px solid #eee; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); min-width:0;">

        <!-- Action Bar -->
        <div id="report-action-bar" style="display:none; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f1f5f9;">
            <h4 id="report-action-title" style="margin:0; color:#1e293b; font-size:15px;">Sales Report</h4>
            <div style="display:flex; gap:8px;">
                <button onclick="printSalesReport()" class="export-btn" style="background:#f8fafc; color:#1e293b; border:1px solid #e2e8f0; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print
                </button>
                <button onclick="exportToExcel()" class="export-btn" style="background:#1a7431; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    Excel
                </button>
                <button onclick="exportToPDF()" class="export-btn" style="background:#c0392b; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    PDF
                </button>
            </div>
        </div>

        <div id="sales-report-result">
            <div class="report-placeholder">
                <div style="font-size: 50px; opacity:0.2;">📊</div>
                <h4>Ready to Generate Report</h4>
                <p>Choose your parameters and click the button.</p>
            </div>
        </div>
    </main>
</div>



<script>
var qrrsResInfo = {
    name:    "<?php echo esc_js($res_name); ?>",
    address: "<?php echo esc_js($res_address); ?>",
    phone:   "<?php echo esc_js($res_phone); ?>",
    logo:    "<?php echo esc_js($res_logo); ?>",
    bin:     "<?php echo esc_js($res_bin); ?>"
};

jQuery(document).ready(function($) {

    $("#qrrs-date-range").flatpickr({
        mode: "range",
        dateFormat: "Y-m-d",
        defaultDate: ["<?php echo date('Y-m-01'); ?>", "<?php echo date('Y-m-d'); ?>"]
    });

    $('#generate-sales-report').on('click', function() {
        var $btn       = $(this);
        var reportType = $('#report-type-select').val();

        $btn.prop('disabled', true).text('Generating...');

        var ajaxAction = 'fetch_sales_report_data';
        if (reportType === 'item_wise') {
            ajaxAction = 'fetch_item_wise_report';
        }
        if (reportType === 'category_wise') { ajaxAction = 'fetch_category_wise_report'; }

        if (reportType === 'order_type_wise'){ ajaxAction = 'fetch_order_type_report'; }
        
        var titles = {
            'general_sales'   : 'Sales Report',
            'item_wise'       : 'Item-wise Sales Report',
            'category_wise'   : 'Category-wise Sales Report',
            'order_type_wise' : 'Order Type Report'
        };
        $('#report-action-title').text(titles[reportType] || 'Sales Report');

        var formData = $('#sales-filter-form').serialize();

        $.post(qrrs_vars.ajax_url, {
            action:   ajaxAction,
            formData: formData,
            security: qrrs_vars.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Generate Report');
            if (response.success && response.data && response.data.data) {
                $('#sales-report-result').html(response.data.data);
                $('#report-action-bar').css('display', 'flex');
            } else {
                $('#sales-report-result').html('<p style="text-align:center; color:#e74c3c; padding:40px;">No data found for the selected period.</p>');
            }
        });
    });

});

// ============ SHARED HEADER/FOOTER BUILDER ============
function buildReportHeader(dateRange) {
    var logoHtml = qrrsResInfo.logo
        ? '<img src="' + qrrsResInfo.logo + '" style="max-width:80px; height:auto; margin-bottom:6px;"><br>'
        : '';
    var binHtml = qrrsResInfo.bin
        ? '<p style="margin:2px 0; font-size:11px;">BIN: ' + qrrsResInfo.bin + '</p>'
        : '';
    var reportTitle = document.getElementById('report-action-title')
        ? document.getElementById('report-action-title').textContent
        : 'Sales Report';

    return '<div style="text-align:center; font-family:Courier New,monospace; margin-bottom:20px; padding-bottom:12px; border-bottom:2px double #000;">'
        + logoHtml
        + '<h2 style="margin:0; font-size:20px; text-transform:uppercase;">' + qrrsResInfo.name + '</h2>'
        + '<p style="margin:2px 0; font-size:13px;">' + qrrsResInfo.address + '</p>'
        + '<p style="margin:2px 0; font-size:13px;">Phone: ' + qrrsResInfo.phone + '</p>'
        + binHtml
        + '<p style="margin:8px 0 0; font-size:13px; font-weight:bold; letter-spacing:0.05em;">'
        + reportTitle.toUpperCase() + ' &mdash; ' + dateRange
        + '</p>'
        + '</div>';
}

function buildReportFooter() {
    return '<div style="text-align:center; margin-top:25px; padding-top:10px; border-top:1px dashed #000; font-size:12px;">'
        + '<p style="margin:2px 0;">Thank You for Visiting!</p>'
        + '<p style="margin:2px 0; font-weight:bold;">Software by: Nazmul Hosen</p>'
        + '</div>';
}

// ============ PRINT ============
function printSalesReport() {
    var dateRange = document.getElementById('qrrs-date-range').value || '';

    var contentEl = document.getElementById('sales-report-result');
    var clone = contentEl.cloneNode(true);

    clone.querySelectorAll('.item-img-cell img, .item-img-cell .no-img-sm').forEach(function(el) { el.remove(); });
    clone.querySelectorAll('.item-rank-cards').forEach(function(el) { el.remove(); }); // top cards সরাও (image ছাড়া awkward)

    var content = clone.innerHTML;

    var win = window.open('', '_blank');
    win.document.write(
        '<html><head><title>Report</title><style>'
        + 'body { font-family: Arial, sans-serif; padding: 20px; color: #000; }'
        + 'table { width: 100%; border-collapse: collapse; }'
        + 'th { background: #f8fafc; padding: 10px; text-align: left; border-bottom: 2px solid #ccc; font-size: 12px; }'
        + 'td { padding: 9px 10px; border-bottom: 1px solid #eee; font-size: 12px; vertical-align: middle; }'
        + 'tr:last-child td { border-top: 2px solid #2271b1; font-weight: bold; background: #f0f6fb; }'
        + '.report-summary-cards { display: flex; gap: 10px; margin-bottom: 20px; }'
        + '.summary-card { background: #1e293b; color: #fff; padding: 14px 18px; border-radius: 8px; flex: 1; }'
        + '.item-img-cell { display: flex; align-items: center; gap: 6px; }'
        + '@media print { button { display: none; } }'
        + '</style></head><body>'
        + buildReportHeader(dateRange)
        + content
        + buildReportFooter()
        + '</body></html>'
    );
    win.document.close();
    win.focus();
    setTimeout(function() { win.print(); win.close(); }, 600);
}

// ============ EXCEL ============
function exportToExcel() {
    var table = document.querySelector('#sales-report-result table');
    if (!table) { alert('Please generate a report first.'); return; }

    if (typeof XLSX === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
        script.onload = function() { doExcelExport(table); };
        document.head.appendChild(script);
    } else {
        doExcelExport(table);
    }
}

function doExcelExport(table) {
    var clone = table.cloneNode(true);
    clone.querySelectorAll('img, .no-img-sm').forEach(function(el) { el.remove(); });

    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.table_to_sheet(clone);
    XLSX.utils.book_append_sheet(wb, ws, 'Report');
    XLSX.writeFile(wb, 'report-' + new Date().toISOString().slice(0, 10) + '.xlsx');
}

// ============ PDF ============
function exportToPDF() {
    var table = document.querySelector('#sales-report-result table');
    if (!table) { alert('Please generate a report first.'); return; }

    if (typeof window.jspdf === 'undefined') {
        var s1 = document.createElement('script');
        s1.src = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js';
        s1.onload = function() {
            var s2 = document.createElement('script');
            s2.src = 'https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js';
            s2.onload = function() { doPDFExport(table); };
            document.head.appendChild(s2);
        };
        document.head.appendChild(s1);
    } else {
        doPDFExport(table);
    }
}

function doPDFExport(table) {
    var dateRange   = document.getElementById('qrrs-date-range').value || '';
    var reportTitle = document.getElementById('report-action-title')
        ? document.getElementById('report-action-title').textContent
        : 'Report';

    var { jsPDF } = window.jspdf;
    var doc   = new jsPDF({ orientation: 'landscape' });
    var pageW = doc.internal.pageSize.getWidth();
    var y     = 12;

    // Logo
    if (qrrsResInfo.logo) {
        try {
            doc.addImage(qrrsResInfo.logo, 'PNG', (pageW / 2) - 12, y, 24, 14);
            y += 18;
        } catch(e) { y += 2; }
    }

    // Restaurant name
    doc.setFontSize(16);
    doc.setFont(undefined, 'bold');
    doc.text(qrrsResInfo.name.toUpperCase(), pageW / 2, y, { align: 'center' });
    y += 6;

    doc.setFontSize(9);
    doc.setFont(undefined, 'normal');
    if (qrrsResInfo.address) { doc.text(qrrsResInfo.address, pageW / 2, y, { align: 'center' }); y += 5; }
    if (qrrsResInfo.phone)   { doc.text('Phone: ' + qrrsResInfo.phone, pageW / 2, y, { align: 'center' }); y += 5; }
    if (qrrsResInfo.bin)     { doc.text('BIN: ' + qrrsResInfo.bin, pageW / 2, y, { align: 'center' }); y += 5; }

    // Divider
    doc.setLineWidth(0.5);
    doc.line(14, y, pageW - 14, y);
    y += 6;

    // Title & date
    doc.setFontSize(12);
    doc.setFont(undefined, 'bold');
    doc.text(reportTitle.toUpperCase(), 14, y);
    doc.setFontSize(9);
    doc.setFont(undefined, 'normal');
    doc.text('Period: ' + dateRange, 14, y + 5);
    doc.text('Generated: ' + new Date().toLocaleDateString(), pageW - 14, y + 5, { align: 'right' });
    y += 13;

    var clone = table.cloneNode(true);
    clone.querySelectorAll('img, .no-img-sm, .no-img').forEach(function(el) { el.remove(); });

    doc.autoTable({
        html: clone,
        startY: y,
        styles: { fontSize: 8, cellPadding: 3 },
        headStyles: { fillColor: [30, 41, 59], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [248, 250, 252] },
        footStyles: { fillColor: [240, 246, 251], textColor: [34, 113, 177], fontStyle: 'bold' },
        margin: { left: 14, right: 14 }
    });

    // Footer
    var finalY = doc.lastAutoTable.finalY + 8;
    doc.setLineWidth(0.3);
    doc.setLineDashPattern([2, 2], 0);
    doc.line(14, finalY, pageW - 14, finalY);
    doc.setLineDashPattern([], 0);
    doc.setFontSize(8);
    doc.text('Thank You for Visiting!', pageW / 2, finalY + 5, { align: 'center' });
    doc.text('Software by: Nazmul Hosen', pageW / 2, finalY + 10, { align: 'center' });

    doc.save('report-' + new Date().toISOString().slice(0, 10) + '.pdf');
}
</script>