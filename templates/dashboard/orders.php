<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$sound_url = QRRS_URL . 'assets/sounds/notification 01.mp3';

/**
 * FIXED: Restaurant ID Logic for AJAX
 */
if ( current_user_can('administrator') ) {
    if ( ! session_id() ) session_start();
    $active_res_id = isset($_SESSION['qrrs_active_res_id']) ? intval($_SESSION['qrrs_active_res_id']) : 0;
} else {
    $active_res_id = get_user_meta(get_current_user_id(), 'assigned_restaurant', true);
}
?>


<div class="order-dash-wrapper">
    <?php if (!$active_res_id): ?>
        <div style="text-align:center; padding:100px;">
            <h2><img width="100" height="163" src="../assets/images/restaurant.png" alt=""> Please select a restaurant first to see orders.</h2>
        </div>
    <?php else: ?>
        <div class="dash-header">
            <div>
                <h2 style="margin:0;"><span class="material-icons-outlined">fastfood</span> Orders Dashboard</h2>
                <small id="live-clock" style="color: #636e72; font-weight: 600;"></small>
            </div>
            <button id="dashSoundToggle" class="sound-control"><span class="material-icons-outlined">notifications_active</span> Sound On</button>
        </div>

        <div class="orders-stats-grid">
            <div class="stat-card" style="border-color:#34495e;"><small>Total</small><strong id="stat-all-total">0</strong></div>
            <div class="stat-card" style="border-color:#3498db;"><small>Pending</small><strong id="stat-all-pending">0</strong></div>
            <div class="stat-card" style="border-color:#f39c12;"><small>Kitchen</small><strong id="stat-all-preparing">0</strong></div>
            <div class="stat-card" style="border-color:#9b59b6;"><small>Served</small><strong id="stat-all-served">0</strong></div>
            <div class="stat-card" style="border-color:#d35400;"><small>Billing</small><strong id="stat-all-settling">0</strong></div>
            <div class="stat-card" style="border-color:#2ecc71;"><small>Completed</small><strong id="stat-all-completed">0</strong></div>
            <div class="stat-card" style="border-color:#ff7675;"><small>Cancelled</small><strong id="stat-all-cancelled">0</strong></div>
        </div>

        <div id="orders-display-grid" class="order-grid"></div>
    <?php endif; ?>
</div>

<audio id="dashNotificationSound" preload="auto">
    <source src="<?php echo $sound_url; ?>" type="audio/mpeg">
</audio>

<script>
let lastOrderCountDash = 0;
let isMutedDash = false;
const dashSound = document.getElementById('dashNotificationSound');
const qr_nonce = '<?php echo wp_create_nonce("qr_order_nonce"); ?>';
const activeResId = '<?php echo $active_res_id; ?>'; 
setInterval(() => {
    if(document.getElementById('live-clock')) {
        document.getElementById('live-clock').innerText = new Date().toLocaleTimeString() + ' | ' + new Date().toLocaleDateString();
    }
}, 1000);

jQuery('#dashSoundToggle').click(function() {
    isMutedDash = !isMutedDash;
    jQuery(this).html(isMutedDash ? '<span class="material-icons-outlined">notifications_off</span> Sound Off' : '<span class="material-icons-outlined">notifications_active</span> Sound On').css('background', isMutedDash ? '#f8d7da' : '#fff');
    if(!isMutedDash) dashSound.play().then(() => dashSound.pause());
});

function loadAllOrders() {
    if(!activeResId) return;

    jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
        action: 'fetch_all_orders_dashboard',
        restaurant_id: activeResId,
        security: qr_nonce
    }, function(res) {
        if (!res.success) return;

        const s = res.data.stats;
        jQuery('#stat-all-total').text(s.total || 0);
        jQuery('#stat-all-pending').text(s.pending || 0);
        jQuery('#stat-all-preparing').text(s.preparing || 0);
        jQuery('#stat-all-served').text(s.served || 0);
        jQuery('#stat-all-settling').text(s.settling || 0);
        jQuery('#stat-all-completed').text(s.completed || 0);
        jQuery('#stat-all-cancelled').text(s.cancelled || 0);

        const orders = res.data.orders || [];
        
        
        if (orders.length > lastOrderCountDash && !isMutedDash) {
            dashSound.currentTime = 0;
            dashSound.play().catch(e => console.log('Intervention required'));
        }
        lastOrderCountDash = orders.length;

        let html = '';
        orders.forEach(o => {
            let cardClass = 'card-' + o.status;
            let badgeClass = 'badge-' + o.status;
            let footerHtml = '';
            let billSummaryHtml = '';

            const showPricing = (o.status === 'served' || o.status === 'billing' || o.status === 'settle_bill');

            let itemsProcessedHtml = '';
            if (o.items && Array.isArray(o.items)) {
                o.items.forEach(item => {
                    let isAdditional = (item.item_type === 'additional');
                    let itemStyle = isAdditional ? 'color: #8b5cf6; font-style: italic;' : 'color: #2d3436;';
                    let additionalLabel = isAdditional ? '<small style="color:#8b5cf6; font-weight:bold;">[ADDITIONAL]</small> ' : '';
                    
                   let priceDisplay = showPricing 
                        ? `<span style="float:right; font-weight:700; color:#2d3436;">${parseFloat(item.line_total || 0).toFixed(2)}</span>` 
                        : '';

                    itemsProcessedHtml += `
                        <div style="margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; ${itemStyle}">
                            ${priceDisplay}
                            <span style="font-weight:700;">${item.qty}x</span> ${additionalLabel}${item.name}
                            ${item.variant_name ? `<br><small style="margin-left:20px; color:#e67e22;"><span class="material-icons-outlined" style="font-size:13px;">subdirectory_arrow_right</span> ${item.variant_name}</small>` : ''}
                        </div>`;
                });
            } else {
                itemsProcessedHtml = o.items_html;
            }

            if (showPricing) {
                let rawTotal = parseFloat(o.subtotal || 0) + parseFloat(o.vat_amount || 0) + parseFloat(o.service_charge || 0);
                
                let roundTotal = Math.round(rawTotal); 
                
                billSummaryHtml = `
                    <div class="bill-summary">
                        <div class="bill-row"><span>Subtotal</span> <span>${parseFloat(o.subtotal || 0).toFixed(2)}</span></div>
                        <div class="bill-row"><span>VAT</span> <span>${parseFloat(o.vat_amount || 0).toFixed(2)}</span></div>
                        <div class="bill-row"><span>Service</span> <span>${parseFloat(o.service_charge || 0).toFixed(2)}</span></div>
                        <div class="bill-row bill-total" style="border-top: 2px dashed #27ae60;">
                            <strong>Total</strong> 
                            <strong>৳ ${roundTotal}</strong>
                        </div>
                        ${rawTotal !== roundTotal ? `<small style="display:block; text-align:right; color:#888;">Actual: ৳ ${rawTotal.toFixed(2)}</small>` : ''}
                    </div>
                `;
            }

            if (o.status === 'pending') {
                footerHtml = `<button onclick="updateDashStatus(${o.id}, 'cancelled')" class="dash-btn btn-cancel">Cancel</button>
                            <button onclick="updateDashStatus(${o.id}, 'processing')" class="dash-btn btn-process"><span class="material-icons-outlined">outdoor_grill</span> Start Cooking</button>`;
            } else if (o.status === 'processing') {
                footerHtml = `<button onclick="updateDashStatus(${o.id}, 'ready')" class="dash-btn btn-ready" style="width:100%"><span class="material-icons-outlined">check_box</span> Order Ready</button>`;
            } else if (o.status === 'ready') {
                footerHtml = `<button onclick="updateDashStatus(${o.id}, 'served')" class="dash-btn btn-serve" style="width:100%"><span class="material-icons-outlined">dinner_dining</span> Order Served</button>`;
            } else if (o.status === 'served') {
                footerHtml = `<button onclick="updateDashStatus(${o.id}, 'billing')" class="dash-btn btn-finalize" style="width:100%; background:#d35400; color:#fff;"><span class="material-icons-outlined">receipt_long</span> Close Order</button>`;
            } else if (o.status === 'billing') {
                footerHtml = `<button onclick="goToBilling(${o.id})" class="dash-btn btn-pay" style="width:100%"><span class="material-icons-outlined">point_of_sale</span> Collect Payment & Complete</button>`;
            }

           html += `
            <div class="order-card ${cardClass}" id="dash-ord-${o.id}">
                <div class="card-head">
                    <div>
                        <strong style="font-size:18px; color:#2d3436;">${o.table_name}</strong><br>
                        <small style="color:#888; font-weight:600;">#ORD-${o.id} • ${o.time_ago}</small>
                    </div>
                    <span class="status-badge ${badgeClass}">${o.status.replace('_', ' ')}</span>
                </div>
                <div class="card-body">
                    <div style="background:#f9f9f9; padding:12px; border-radius:10px; border:1px dashed #ddd;">
                        ${itemsProcessedHtml}
                    </div>
                    ${billSummaryHtml}
                </div>
                <div class="card-footer" style="display:flex;">
                    ${footerHtml}
                </div>
            </div>`;
        });

        jQuery('#orders-display-grid').html(html || '<div style="grid-column:1/-1;text-align:center;padding:100px;">No active orders.</div>');
    });
}

function updateDashStatus(id, status) {
    const card = jQuery('#dash-ord-' + id);
    
    card.css('opacity', '0.5').css('pointer-events', 'none');

    jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
        action: 'update_dashboard_order_status',
        order_id: id,
        status: status,
        security: qr_nonce
    }, function(res) {
        if(res.success) {
            loadAllOrders();
        } else {
            card.css('opacity', '1').css('pointer-events', 'auto');
            alert('Failed: ' + (res.data || 'Unknown error'));
        }
    });
}

function goToBilling(orderId) {
    window.location.href = `?tab=billing&order_id=${orderId}`;
}

jQuery(document).ready(loadAllOrders);
setInterval(loadAllOrders, 50000);
</script>