<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$sound_url = QRRS_URL . 'assets/sounds/notification 01.mp3';
?>

<style>
    /* .order-dash-wrapper { background: #f0f2f5; padding: 25px; font-family: 'Segoe UI', sans-serif; min-height: 100vh; } */
    .dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    
    /* Stats Grid */
    .orders-stats-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 12px; margin-bottom: 25px; }
    .stat-card { background: #fff; padding: 15px; border-radius: 12px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-bottom: 4px solid #ddd; }
    .stat-card strong { font-size: 22px; display: block; color: #2d3436; }
    .stat-card small { font-size: 10px; text-transform: uppercase; color: #636e72; font-weight: 700; }

    
    .order-dash-wrapper { background: #f0f2f5; padding: 25px; font-family: 'Segoe UI', sans-serif; min-height: 100vh; }
    .order-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }

    /* Base Card Style */
    .order-card { 
        background: #fff; border-radius: 15px; overflow: hidden; 
        box-shadow: 0 4px 10px rgba(0,0,0,0.05); display: flex; 
        flex-direction: column; border-left: 8px solid #dfe6e9; /* বাম পাশে মোটা বর্ডার */
        transition: 0.3s ease;
    }

    /* 🎨 Status Based Colors */
    .card-pending { border-left-color: #3498db; } /* Blue */
    .card-processing { border-left-color: #f39c12; background: #fffcf5; } /* Orange/Kitchen */
    .card-ready { border-left-color: #2ecc71; } /* Green */
    
    /* 🚨 Billing/Urgent Status */
    .card-settle_bill { 
        border-left-color: #e74c3c; 
        background: #fff5f5; 
        animation: pulse-red 2s infinite; 
    }

    @keyframes pulse-red {
        0% { box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(231, 76, 60, 0); }
        100% { box-shadow: 0 0 0 0 rgba(231, 76, 60, 0); }
    }

    /* Badges */
    .status-badge { 
        font-size: 11px; padding: 4px 10px; border-radius: 50px; 
        text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px;
    }
    .badge-pending { background: #ebf5ff; color: #1976d2; }
    .badge-processing { background: #fff4e5; color: #bf6a02; }
    .badge-ready { background: #e8f5e9; color: #2e7d32; }
    .badge-settle_bill { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

    .card-head { padding: 15px; border-bottom: 1px solid #f1f1f1; display: flex; justify-content: space-between; align-items: start; }
    .card-body { padding: 15px; flex-grow: 1; font-size: 14px; }
    
    .dash-btn { flex: 1; padding: 14px; border: none; cursor: pointer; font-weight: 700; font-size: 12px; text-transform: uppercase; }
    .btn-complete { background: #27ae60; color: #fff; width: 100%; }
    .btn-cancel { background: #fdf0f0; color: #c0392b; border-right: 1px solid #eee; }
    .btn-view { background: #f8f9fa; color: #2d3436; }
    .btn-process { background: #f39c12; color: #fff; } /* কিচেনে পাঠানোর জন্য */
    .btn-ready { background: #3498db; color: #fff; }    /* রেডি করার জন্য */
    .btn-complete { background: #27ae60; color: #fff; } /* কমপ্লিট করার জন্য */
    
    .dash-btn:hover { opacity: 0.9; filter: brightness(1.1); }
    /* Sound Button */
    .sound-control { background: #fff; border: 1px solid #ddd; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; }

    /* নতুন বিলিং স্টাইল */
    .bill-summary { margin-top: 12px; padding: 10px; background: #fcfcfc; border-radius: 8px; border: 1px dashed #ccc; font-family: 'Courier New', Courier, monospace; }
    .bill-row { display: flex; justify-content: space-between; margin-bottom: 3px; font-size: 13px; color: #444; }
    .bill-total { border-top: 1px solid #ddd; margin-top: 5px; padding-top: 5px; font-weight: 800; color: #27ae60; font-size: 14px; }
    .item-variant-label { font-size: 11px; color: #e67e22; display: block; margin-top: 2px; }
    .item-price-val { float: right; font-weight: bold; }


/* স্ট্যাটাস কালার আপডেট */
.card-served { border-left-color: #1abc9c; }
.card-completed { border-left-color: #9b59b6; }

.badge-served { background: #e0fcf5; color: #16a085; }
.badge-completed { background: #f3e5f5; color: #8e24aa; }

/* নতুন বাটন কালার */
.btn-serve { background: #1abc9c; color: #fff; }
.btn-finalize { background: #9b59b6; color: #fff; }
.btn-pay { background: #27ae60; color: #fff; }
</style>

<div class="order-dash-wrapper">
    <div class="dash-header">
        <div>
            <h2 style="margin:0;">📦 Orders Dashboard</h2>
            <small id="live-clock" style="color: #636e72; font-weight: 600;"></small>
        </div>
        <button id="dashSoundToggle" class="sound-control">🔔 Sound On</button>
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
</div>

<audio id="dashNotificationSound" preload="auto">
    <source src="<?php echo $sound_url; ?>" type="audio/mpeg">
</audio>

<script>
let lastOrderCountDash = 0;
let isMutedDash = false;
const dashSound = document.getElementById('dashNotificationSound');
const qr_nonce = '<?php echo wp_create_nonce("qr_order_nonce"); ?>';

// ১. টাইম আপডেট
setInterval(() => {
    document.getElementById('live-clock').innerText = new Date().toLocaleTimeString() + ' | ' + new Date().toLocaleDateString();
}, 1000);

// ২. সাউন্ড টগল
jQuery('#dashSoundToggle').click(function() {
    isMutedDash = !isMutedDash;
    jQuery(this).html(isMutedDash ? '🔇 Sound Off' : '🔔 Sound On').css('background', isMutedDash ? '#f8d7da' : '#fff');
    if(!isMutedDash) dashSound.play().then(() => dashSound.pause());
});

// ৩. ডাটা লোড করা
function loadAllOrders() {
    jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
        action: 'fetch_all_orders_dashboard',
        security: '<?php echo wp_create_nonce("qr_order_nonce"); ?>'
    }, function(res) {
        if (!res.success) return;

        // Stats Update
        const s = res.data.stats;
        jQuery('#stat-all-total').text(s.total || 0);
        jQuery('#stat-all-pending').text(s.pending || 0);
        jQuery('#stat-all-preparing').text(s.preparing || 0);
        jQuery('#stat-all-served').text(s.served || 0);
        jQuery('#stat-all-settling').text(s.settling || 0);
        jQuery('#stat-all-completed').text(s.completed || 0);
        jQuery('#stat-all-cancelled').text(s.cancelled || 0);

        const orders = res.data.orders || [];
        
        // 🔔 Sound Logic
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

            // 🔥 আইটেম লিস্ট প্রোসেসিং (ভেরিয়েন্ট এবং প্রাইস সহ)
           let itemsProcessedHtml = '';
            if (o.items && Array.isArray(o.items)) {
                o.items.forEach(item => {
                    let priceDisplay = (o.status === 'ready' || o.status === 'settle_bill') 
                        ? `<span style="float:right; font-weight:700; color:#2d3436;">${parseFloat(item.line_total).toFixed(2)}</span>` 
                        : '';
                    
                    let variantDisplay = item.variant_name 
                        ? `<div style="font-size: 11px; color: #e67e22; margin-left: 20px; font-style: italic;">↳ ${item.variant_name}</div>` 
                        : '';

                    itemsProcessedHtml += `
                        <div style="margin-bottom: 10px; line-height: 1.4;">
                            ${priceDisplay}
                            <span style="font-weight:600;">${item.qty}x</span> ${item.name}
                            ${variantDisplay}
                        </div>`;
                });
            } else {
                itemsProcessedHtml = o.items_html;
            }

            // 💰 বিলিং ক্যালকুলেশন লজিক (কেবল রেডি স্ট্যাটাস হলে)
           if (o.status === 'ready' || o.status === 'settle_bill') {
                billSummaryHtml = `
                    <div class="bill-summary" style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-top: 15px;">
                        <div class="bill-row" style="display:flex; justify-content:space-between; margin-bottom:4px;">
                            <span style="color:#636e72;">Subtotal</span> 
                            <span style="font-weight:600;">${parseFloat(o.subtotal || 0).toFixed(2)}</span>
                        </div>
                        <div class="bill-row" style="display:flex; justify-content:space-between; margin-bottom:4px;">
                            <span style="color:#636e72;">VAT</span> 
                            <span style="font-weight:600;">${parseFloat(o.vat_amount || 0).toFixed(2)}</span>
                        </div>
                        <div class="bill-row" style="display:flex; justify-content:space-between; margin-bottom:4px;">
                            <span style="color:#636e72;">Service</span> 
                            <span style="font-weight:600;">${parseFloat(o.service_charge || 0).toFixed(2)}</span>
                        </div>
                        <div class="bill-row bill-total" style="display:flex; justify-content:space-between; margin-top:8px; padding-top:8px; border-top:2px dashed #ddd; color:#27ae60; font-size:16px;">
                            <strong>Total</strong> 
                            <strong>${parseFloat(o.total_amount || 0).toFixed(2)}</strong>
                        </div>
                    </div>
                `;
            }

            // 🔥 ডাইনামিক ওয়ার্কফ্লো বাটন লজিক (৬টি ধাপ অনুযায়ী)
            if (o.status === 'pending') {
                footerHtml = `
                    <button onclick="updateDashStatus(${o.id}, 'cancelled')" class="dash-btn btn-cancel">Cancel</button>
                    <button onclick="updateDashStatus(${o.id}, 'processing')" class="dash-btn btn-process">🔥 Start Cooking</button>
                `;
            } 
            else if (o.status === 'processing') {
                footerHtml = `
                    <button onclick="updateDashStatus(${o.id}, 'ready')" class="dash-btn btn-ready" style="width:100%">✅ Mark as Ready</button>
                `;
            } 
            else if (o.status === 'ready') {
                footerHtml = `
                    <button onclick="updateDashStatus(${o.id}, 'served')" class="dash-btn btn-serve" style="width:100%">🍽️ Served</button>
                `;
            } 
            else if (o.status === 'served') {
                footerHtml = `
                    <button onclick="updateDashStatus(${o.id}, 'completed')" class="dash-btn btn-finalize" style="width:100%">🏁 Order Completed</button>
                `;
            } 
            else if (o.status === 'completed') {
                footerHtml = `
                    <button onclick="goToBilling(${o.id})" class="dash-btn btn-pay" style="width:100%">💰 Pay Bill</button>
                `;
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
    let msg = "Are you sure?";
    if(status === 'processing') msg = "Send this order to Kitchen?";
    if(status === 'ready') msg = "Is this order ready to serve?";
    if(status === 'served') msg = "Mark this order as served?";
    if(status === 'completed') msg = "Mark this order as completed?";
    if(status === 'cancelled') msg = "Cancel this order?";
    
    if(!confirm(msg)) return;

    const card = jQuery('#dash-ord-' + id);
    card.css('opacity', '0.5').css('pointer-events', 'none');

    jQuery.ajax({
        url: '<?php echo admin_url("admin-ajax.php"); ?>',
        type: 'POST',
        data: {
            action: 'update_dashboard_order_status',
            order_id: id,
            status: status,
            security: qr_nonce
        },
        success: function(res) {
            if(res.success) {
                loadAllOrders();
            } else {
                alert('Failed: ' + (res.data || 'Unknown error'));
                card.css('opacity', '1').css('pointer-events', 'auto');
            }
        }
    });
}

function goToBilling(orderId) {
    const billingUrl = `?tab=billing&order_id=${orderId}`;
    window.location.href = billingUrl;
}
jQuery(document).ready(loadAllOrders);
setInterval(loadAllOrders, 5000);
</script>