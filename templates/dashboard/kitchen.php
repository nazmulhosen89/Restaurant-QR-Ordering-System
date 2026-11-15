<?php
if (!defined('ABSPATH')) exit;

if ( ! is_user_logged_in() ) {
    wp_redirect( home_url( '/restaurant-login/' ) ); 
    exit;
}

// 2
if ( ! current_user_can( 'qr_kitchen' ) && ! current_user_can( 'administrator' ) && ! current_user_can( 'manager' ) ) {
    wp_die( 'Access Denied: You do not have permission to view the Waiter Terminal.', 'Permission Error' );
}

$current_tab  = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'orders';
$current_user = wp_get_current_user();
$sound_url    = QRRS_URL . 'assets/sounds/notification 01.mp3';
?>


<div class="kitchen-wrapper">
    <div class="kitchen-header">
        <div style="display:flex; align-items:center; gap:20px;">
            <div>
                <h2 style="margin:0; color:#fff; font-size:20px; font-weight:700;">👨‍🍳 Kitchen Display</h2>
                <div id="kitchen-clock" style="color:#636e72; font-weight:600; font-size:13px;"></div>
            </div>
            <button id="soundToggle" class="sound-toggle-btn">
                <span class="icon">🔔</span> <span class="text">Sound On</span>
            </button>
        </div>

        <div class="kitchen-user-nav" style="position:relative;">
            <div style="display:flex; align-items:center; gap:12px; cursor:pointer; padding:8px 15px; border-radius:10px; background:rgba(255,255,255,0.05); border:1px solid #3d3d3d;" onclick="jQuery('#user-dropdown').toggle();">
                <div style="width:35px; height:35px; background:#00d2d3; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold;">
                    <?php echo strtoupper(substr($current_user->display_name, 0, 1)); ?>
                </div>
                <div style="text-align:left; line-height:1.2;">
                    <span style="display:block; font-size:13px; font-weight:700; color:#fff;"><?php echo esc_html($current_user->display_name); ?></span>
                    <small style="color:#888; font-size:11px;">Kitchen Staff</small>
                </div>
            </div>
            <div id="user-dropdown" style="display:none; position:absolute; right:0; top:55px; background:#2d2d2d; min-width:200px; box-shadow:0 10px 30px rgba(0,0,0,0.5); border-radius:10px; z-index:1000; overflow:hidden; border:1px solid #3d3d3d;">
                <a href="?tab=requisition"><span>INV</span> Requisition</a>
                <a href="?tab=profile"><span>👤</span> Profile Settings</a>
                <a href="?tab=orders"><span>🍳</span> Orders View</a>
                <a href="<?php echo wp_logout_url(home_url('/restaurant-login/')); ?>" style="color:#ff7675;"><span>👋</span> Logout</a>
            </div>
        </div>
    </div>

    <?php if ($current_tab === 'profile'): ?>
        <div style="max-width:800px; margin:0 auto; background:#fff; padding:30px; border-radius:12px; color:#333;">
            <?php
            $profile_path = QRRS_PATH . 'includes/user/profile.php';
            if (file_exists($profile_path)) include $profile_path;
            ?>
        </div>
    <?php elseif ($current_tab === 'requisition'): ?>
        <?php
        if ( function_exists( 'qrrs_inventory_render_requisition_panel' ) ) {
            qrrs_inventory_render_requisition_panel( 'kitchen' );
        }
        ?>
    <?php else: ?>
        <div class="kitchen-stats">
            <div class="k-stat-box" style="border-color:#00d2d3;"><strong><span id="k-total">0</span></strong><small>Total Orders</small></div>
            <div class="k-stat-box" style="border-color:#3498db;"><strong><span id="k-confirmed">0</span></strong><small>Confirmed</small></div>
            <div class="k-stat-box" style="border-color:#f1c40f;"><strong><span id="k-table">0</span></strong><small>Table Orders</small></div>
            <div class="k-stat-box" style="border-color:#e67e22;"><strong><span id="k-takeaway">0</span></strong><small>Take Away</small></div>
            <div class="k-stat-box" style="border-color:#2ecc71;"><strong><span id="k-completed">0</span></strong><small>Completed</small></div>
            <div class="k-stat-box" style="border-color:#e74c3c;"><strong><span id="k-cancelled">0</span></strong><small>Cancelled</small></div>
        </div>
        <div id="kitchen-display-grid" class="kitchen-grid"></div>
    <?php endif; ?>
</div>

<audio id="orderNotificationSound" preload="auto">
    <source src="<?php echo esc_url($sound_url); ?>" type="audio/mpeg">
</audio>

<script>
let lastOrderCount = 0;
let isMuted = false;
const orderSound  = document.getElementById('orderNotificationSound');
const soundBtn    = document.getElementById('soundToggle');

// Sound toggle
soundBtn.addEventListener('click', function() {
    isMuted = !isMuted;
    if (isMuted) {
        this.classList.add('muted');
        this.querySelector('.icon').innerText = '🔇';
        this.querySelector('.text').innerText = 'Sound Off';
    } else {
        this.classList.remove('muted');
        this.querySelector('.icon').innerText = '🔔';
        this.querySelector('.text').innerText = 'Sound On';
        orderSound.play().then(() => orderSound.pause()).catch(()=>{});
    }
});

// Clock
function updateKitchenClock() {
    const el = document.getElementById('kitchen-clock');
    if (el) el.innerText = new Date().toLocaleTimeString('en-US', {hour12:true}) + ' | ' + new Date().toLocaleDateString('en-GB', {day:'numeric', month:'short'});
}
setInterval(updateKitchenClock, 1000);
updateKitchenClock();

// ✅ Items HTML builder
function buildItemsHtml(items) {
    let originalHtml    = '';
    let additionalHtml  = '';
    let hasAdditional   = false;

    items.forEach(function(item) {
        const isAdditional = (item.item_type === 'additional');
        const statusClass  = 'status-' + item.item_status;  // status-pending / status-processing / status-ready
        const typeClass    = isAdditional ? 'type-additional' : 'type-original';

        // Badge
        let badge = '';
        if (item.item_status === 'ready') {
            badge = '<span class="k-item-badge badge-done">✓ Done</span>';
        } else if (item.item_status === 'processing') {
            badge = '<span class="k-item-badge badge-cooking">Cooking</span>';
        } else if (isAdditional) {
            badge = '<span class="k-item-badge badge-new">New ➕</span>';
        }

        const variantHtml = item.variant
            ? `<div class="k-variant">↳ ${item.variant}</div>` : '';

        const rowHtml = `
            <div class="k-item-row ${typeClass} ${statusClass}">
                <span class="k-item-qty">${item.qty}x</span>
                <div style="flex:1;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                        <span>${item.name}</span>
                        ${badge}
                    </div>
                    ${variantHtml}
                </div>
            </div>`;

        if (isAdditional) {
            hasAdditional = true;
            additionalHtml += rowHtml;
        } else {
            originalHtml += rowHtml;
        }
    });

    let finalHtml = originalHtml;

    if (hasAdditional) {
        finalHtml += `
            <div class="k-additional-divider">➕ Additional Items</div>
            ${additionalHtml}`;
    }

    return finalHtml || '<div style="color:#aaa; font-size:13px;">No items</div>';
}

// ✅ Main fetch function
function loadKitchenOrders() {
    if ('<?php echo esc_js($current_tab); ?>' !== 'orders') return;

    jQuery.post(qrrs_vars.ajax_url, {
        action:   'fetch_kitchen_orders',
        security: qrrs_vars.qr_nonce
    }, function(res) {
        if (!res.success) return;

        // Stats
        const s = res.data.stats;
        jQuery('#k-total').text(s.total || 0);
        jQuery('#k-confirmed').text(s.confirmed || 0);
        jQuery('#k-table').text(s.table_order || 0);
        jQuery('#k-takeaway').text(s.take_away || 0);
        jQuery('#k-completed').text(s.complete || 0);
        jQuery('#k-cancelled').text(s.cancel || 0);

        const orders = res.data.orders || [];

        // Sound notification
        if (orders.length > lastOrderCount && !isMuted) {
            orderSound.currentTime = 0;
            orderSound.play().catch(() => {});
        }
        lastOrderCount = orders.length;

        if (!orders.length) {
            jQuery('#kitchen-display-grid').html('<div style="grid-column:1/-1; text-align:center; padding:100px; color:#636e72;"><h3>No active orders.</h3></div>');
            return;
        }

        let html = '';
        orders.forEach(function(o) {
            const itemsHtml = buildItemsHtml(o.items || []);

            const borderColor = o.raw_status === 'processing' ? '#f39c12'
                              : o.raw_status === 'ready'      ? '#27ae60'
                              : '#00d2d3';

            html += `
            <div class="k-card" id="order-${o.id}" style="border-top-color:${borderColor};">
                <div class="k-card-header">
                    <div>
                        <span style="font-weight:bold; font-size:11px; color:#e67e22;">#ORD-${o.id}</span><br>
                        <strong style="font-size:17px; color:#2d3436;">${o.table_name}</strong>
                    </div>
                    <div style="text-align:right;">
                        <small style="color:#888; display:block; font-size:11px;">${o.time_ago}</small>
                        <span style="font-size:9px; background:#f1f2f6; padding:2px 6px; border-radius:4px; text-transform:uppercase; font-weight:700;">${o.raw_status.replace('_',' ')}</span>
                    </div>
                </div>
                <div class="k-card-body">
                    ${itemsHtml}
                </div>
                <div class="k-card-footer">
                    <button onclick="updateKitchenStatus(${o.id}, '${o.next_status}')"
                            class="k-action-btn ${o.btn_class}">
                        ${o.btn_label}
                    </button>
                </div>
            </div>`;
        });

        jQuery('#kitchen-display-grid').html(html);
    });
}

function updateKitchenStatus(id, status) {
    const card = jQuery('#order-' + id);
    card.css({'opacity': '0.5', 'pointer-events': 'none'});

    jQuery.post(qrrs_vars.ajax_url, {
        action:   'update_qr_order_status',
        security: qrrs_vars.qr_nonce,
        order_id: id,
        status:   status
    }, function(res) {
        if (res.success) {
            lastOrderCount = Math.max(0, lastOrderCount - 1);
            loadKitchenOrders();
        } else {
            card.css({'opacity': '1', 'pointer-events': 'auto'});
        }
    });
}

jQuery(document).on('click', function(e) {
    if (!jQuery(e.target).closest('.kitchen-user-nav').length) {
        jQuery('#user-dropdown').hide();
    }
});

jQuery(document).ready(function() {
    loadKitchenOrders();
    setInterval(loadKitchenOrders, 5000);
});
</script>
