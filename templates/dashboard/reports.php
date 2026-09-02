<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'qrrs_get_active_restaurant_id' ) ) {
    require_once QRRS_PATH . 'includes/reports/report-functions.php';
}
$current_res_id = qrrs_get_active_restaurant_id();
$is_admin       = current_user_can( 'administrator' );
?>



<div class="qrrs-reports-wrap">

    <!-- TOP TABS -->
    <div class="qrrs-report-tabs">
        <button class="qr-tab-btn active" data-section="dashboard">
            📊 Overview
        </button>
        <button class="qr-tab-btn" data-section="sales">
            💰 Sales Report
        </button>
        <button class="qr-tab-btn" data-section="orders">
            🧾 Order Report
        </button>
        <button class="qr-tab-btn" data-section="items">
            🍽️ Item Performance
        </button>
        <button class="qr-tab-btn" data-section="kitchen">
            👨‍🍳 Kitchen / KOT
        </button>
        <button class="qr-tab-btn" data-section="staff">
            👥 Staff Report
        </button>
        <button class="qr-tab-btn" data-section="payment">
            💳 Payment Report
        </button>
        <button class="qr-tab-btn" data-section="vat">
            🧾 VAT / Service
        </button>
    </div>

    <!-- DYNAMIC CONTENT AREA -->
    <div id="qrrs-report-content">
        <div class="qrrs-loading">
            <div class="qrrs-spinner"></div> Loading...
        </div>
    </div>

</div>

<script>
(function($) {
    var currentSection = '';

   function loadReportSection(section) {
        if (currentSection === section) return;
        currentSection = section;

        $('.qr-tab-btn').removeClass('active');
        $('.qr-tab-btn[data-section="' + section + '"]').addClass('active');

        $('#qrrs-report-content').html(
            '<div class="qrrs-loading"><div class="qrrs-spinner"></div> Loading...</div>'
        );

        $.ajax({
            url: qrrs_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'qrrs_load_report_section',
                security: qrrs_vars.nonce,
                report_type: section,
                restaurant_id: <?php echo esc_js( $current_res_id ); ?>
            },
            success: function(res) {
                if (res.success) {
                    $('#qrrs-report-content').html(res.data);
                    if (section === 'dashboard') initDashboardCharts();
                } else {
                    $('#qrrs-report-content').html('<p style="color:#e74c4c;padding:20px;">Error loading section.</p>');
                }
            },
            error: function() {
                $('#qrrs-report-content').html('<p style="color:#e74c4c;padding:20px;">Server error. Please try again.</p>');
            }
        });
    }

    $(document).on('click', '.qr-tab-btn', function() {
        loadReportSection($(this).data('section'));
    });

    loadReportSection('dashboard');

})(jQuery);
</script>