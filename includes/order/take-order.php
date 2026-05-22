<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$current_user_id = get_current_user_id();

/**
 * FIXED: Restaurant ID Logic (Admin Session + Staff Logic)
 */
if ( current_user_can('administrator') ) {
    if ( ! session_id() ) session_start();
    $restaurant_id = isset($_SESSION['qrrs_active_res_id']) 
        ? intval($_SESSION['qrrs_active_res_id']) : 0;
} else {
    $staff_info = $wpdb->get_row($wpdb->prepare(
        "SELECT restaurant_id FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ));
    $restaurant_id = $staff_info ? $staff_info->restaurant_id : intval( get_user_meta( $current_user_id, 'assigned_restaurant', true ) );
}

if (!$restaurant_id) {
    echo '<div style="padding:50px; text-align:center;"><h3>Please select a restaurant from the dashboard first.</h3></div>';
    return;
}

// ২. Data Fetching (Bakita ager motoi thakbe)
$res_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $restaurant_id));
$db_tax = $res_info->tax_percent ?? 0;
$db_sc  = $res_info->service_charge_percent ?? 0;

// ১. ফ্লোর প্ল্যান এবং টেবিল স্ট্যাটাস কুয়েরি (ইউজার ও স্ট্যাটাস সহ)
$tables = $wpdb->get_results($wpdb->prepare("
    SELECT t.*, 
        o.order_status, 
        o.id AS active_order_id,
        o.waiter_id,
        u.display_name as waiter_name
    FROM {$wpdb->prefix}qrrs_tables t
    LEFT JOIN {$wpdb->prefix}qrrs_orders o ON t.table_name = o.table_name 
        AND o.restaurant_id = %d 
        AND o.order_status NOT IN ('completed','cancelled','billing')
        AND DATE(o.created_at) = CURDATE()
    LEFT JOIN {$wpdb->prefix}users u ON o.waiter_id = u.ID
    WHERE t.restaurant_id = %d
    GROUP BY t.table_name
    ORDER BY CAST(SUBSTRING_INDEX(t.table_name,' ',-1) AS UNSIGNED) ASC, t.table_name ASC
", $restaurant_id, $restaurant_id));

$categories = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_categories WHERE restaurant_id = %d ORDER BY id ASC", $restaurant_id));
$items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_items WHERE restaurant_id = %d", $restaurant_id));

// Occupied tables: today's active orders (not completed/cancelled)
$today_start = current_time('Y-m-d 00:00:00');
$today_end   = current_time('Y-m-d 23:59:59');

$occupied_tables_data = $wpdb->get_results($wpdb->prepare("
    SELECT 
        o.table_name,
        o.created_at,
        o.grand_total,
        COUNT(oi.id) as item_count,
        u.display_name as taken_by
    FROM {$wpdb->prefix}qrrs_orders o
    LEFT JOIN {$wpdb->prefix}users u ON o.waiter_id = u.ID
    LEFT JOIN {$wpdb->prefix}qrrs_order_items oi ON o.id = oi.order_id
    WHERE o.restaurant_id = %d 
    AND o.order_status NOT IN ('completed', 'cancelled')
    AND o.created_at BETWEEN %s AND %s
    GROUP BY o.table_name
", $restaurant_id, $today_start, $today_end));

$occupied_map = [];
foreach($occupied_tables_data as $ot) {
    $occupied_map[$ot->table_name] = $ot;
}
$occupied_table_names = array_keys($occupied_map);

$plugin_url = plugin_dir_url(dirname(dirname(__FILE__)));

?>



<div class="pos-wrapper">
    <div id="pos-overlay" class="pos-overlay">
        <div id="step-type" class="selection-card">
            <h1 style="margin-bottom:20px;">New Order</h1>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div class="cat-item" style="border:1px solid #eee; border-radius:15px; padding:25px;" onclick="selectType('dine_in')">🍽️<br>Dine In</div>
                <div class="cat-item" style="border:1px solid #eee; border-radius:15px; padding:25px;" onclick="selectType('take_out')">🛍️<br>Take Out</div>
            </div>
        </div>
        <div id="step-table" class="selection-card" style="display:none;">
            <div class="table-modal-header">
                <h3>🪑 Select Table</h3>
                <div class="table-legend">
                    <div class="legend-dot"><span style="background:#22c55e;"></span> Free</div>
                    <div class="legend-dot"><span style="background:#f97316;"></span> Occupied</div>
                </div>
            </div>

            <div class="table-grid-container">
                <?php 
                $free_count = 0;
                $occupied_count = 0;
                foreach($tables as $t): 
                    $is_occupied = in_array($t->table_name, $occupied_table_names);
                    $capacity = !empty($t->capacity) ? intval($t->capacity) : 4;
                    
                    if($is_occupied) {
                        $occupied_count++;
                        $odata = $occupied_map[$t->table_name] ?? null;
                        $taken_by = $odata ? ($odata->taken_by ?: 'Staff') : 'Customer';
                        $item_count = $odata ? intval($odata->item_count) : 0;
                        $time_ago = $odata ? human_time_diff(strtotime($odata->created_at), current_time('timestamp')) . ' ago' : '';
                        $grand_total = $odata ? number_format($odata->grand_total, 0) : '0';
                        $onclick = "showOccupiedAlert('" . esc_js($t->table_name) . "', '" . esc_js($taken_by) . "', '{$item_count}', '{$time_ago}')";
                    } else {
                        $free_count++;
                        $onclick = "selectTable({$t->id}, '" . esc_js($t->table_name) . "')";
                    }

                    // Capacity chairs icons
                    $chair_icons = '';
                    for($i = 0; $i < min($capacity, 6); $i++) $chair_icons .= '🪑';
                ?>
                <div class="tbl-card <?php echo $is_occupied ? 'occupied' : 'free'; ?>" 
                    onclick="<?php echo $onclick; ?>">
                    
                    <?php if($is_occupied): ?>
                    <div class="tbl-tooltip">
                        👤 <?php echo esc_html($taken_by); ?> · <?php echo $item_count; ?> items · <?php echo $time_ago; ?>
                    </div>
                    <?php endif; ?>

                    <span class="tbl-icon">
                        <img src="<?php echo $is_occupied 
                            ? esc_url($plugin_url . 'assets/images/table-occupied.png')
                            : esc_url($plugin_url . 'assets/images/table-free.png'); ?>" 
                            style="width:auto; height:45px; object-fit:contain;">
                    </span>
                    <div class="tbl-name"><?php echo esc_html($t->table_name); ?></div>
                    <div class="tbl-capacity">👥 <?php echo $capacity; ?> seats</div>
                    <div class="tbl-status-pill">
                        <?php echo $is_occupied ? '● Occupied' : '● Free'; ?>
                    </div>
                    <?php if($is_occupied): ?>
                    <div class="tbl-waiter-info">
                        <!-- <div class="tbl-waiter-avatar"><?php echo strtoupper(substr($taken_by, 0, 1)); ?></div> -->
                        <div class="tbl-waiter-name"> <?php echo esc_html($taken_by); ?></div>
                    </div>
                    <div class="tbl-order-meta"><?php echo $item_count; ?> items · <?php echo $time_ago; ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="table-modal-footer">
                <div class="table-stats-text">
                    <strong><?php echo $free_count; ?></strong> free · 
                    <strong><?php echo $occupied_count; ?></strong> occupied
                </div>
                <button onclick="jQuery('#step-table').hide(); jQuery('#step-type').show();" 
                        style="background:none; border:1px solid #e2e8f0; padding:8px 16px; border-radius:8px; color:#64748b; cursor:pointer; font-size:13px; font-weight:600;">
                    ← Back
                </button>
            </div>
        </div>
    </div>

    <div class="sidebar-left">
        <div class="cat-item active" data-cat="all">🏠<h4>ALL</h4></div>
        <?php foreach($categories as $cat): 
            // ডাটাবেজ থেকে ইমেজের URL চেক করা হচ্ছে
            $c_img = !empty($cat->image_url) ? $cat->image_url : (!empty($cat->image) ? $cat->image : '');
        ?>
            <div class="cat-item" data-cat="<?php echo $cat->id; ?>">
                <?php if($c_img): ?>
                    <img src="<?php echo esc_url($c_img); ?>" class="cat-icon" onerror="this.src='https://via.placeholder.com/50'">
                <?php else: ?>
                    <div class="cat-icon" style="background:#eee; display:flex; align-items:center; justify-content:center; font-size:20px; border-radius:50%; margin: 0 auto 5px;">📁</div>
                <?php endif; ?>
                <h4><?php echo esc_html($cat->category_name); ?></h4>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="main-content">
        <div class="item-grid">
            <?php foreach($items as $item): 
                // ইমেজ কলাম চেক করা হচ্ছে (image_url অথবা item_image)
                $i_img = !empty($item->image_url) ? $item->image_url : (!empty($item->item_image) ? $item->item_image : '');
                $item_json = json_encode($item);
                $is_avail = isset($item->is_available) ? intval($item->is_available) : 1;
            ?>
            <div class="item-card <?php echo ($is_avail === 0) ? 'out-of-stock' : ''; ?>" 
                id="card-<?php echo $item->id; ?>" 
                data-cat-id="<?php echo $item->category_id; ?>"
                onclick='<?php echo ($is_avail === 1) ? "prepareItem($item_json)" : ""; ?>'>
                
                <div class="item-qty-badge" id="badge-<?php echo $item->id; ?>">0</div>
                
                <img src="<?php echo esc_url($i_img); ?>" class="item-img" onerror="this.src='https://via.placeholder.com/300x150?text=Food'">
                
                <div style="padding:12px;">
                    <h4 style="margin:0 0 5px 0; font-size:14px; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?php echo esc_html($item->item_name); ?>
                    </h4>
                    <span style="font-weight:700; color:var(--primary); font-size:15px;"><?php echo number_format($item->price, 2); ?>৳</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sidebar-right">
        <div style="padding:20px; border-bottom:1px solid #f1f5f9;">
            <div style="font-weight:bold; font-size:18px;">Order Details</div>
            <small id="order-meta-label" style="color:var(--primary); font-weight:bold;"></small>
        </div>
        <div id="cart-list" style="flex:1; overflow-y:auto; padding:15px;"></div>
        <div id="cart-summary" style="padding:20px; background:#f8fafc; border-top:1px solid #e2e8f0;"></div>
    </div>
</div>

<div id="vModal" class="v-modal">
    <div class="v-modal-content" id="vBody"></div>
</div>


<div id="successPopup" class="v-modal" style="display:none; z-index: 9999;">
    <div class="v-modal-content" style="text-align:center; padding: 30px; border-radius: 15px; max-width: 350px;">
        <div class="success-icon" style="margin-bottom: 15px;">
            <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        <h2 style="margin: 0 0 10px 0; color: #1e293b; font-size: 20px;">Order Placed</h2>
        <p style="color: #64748b; font-size: 14px; margin: 0;">The order has been recorded successfully.</p>
        
        <div style="margin-top: 20px; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; pt: 15px;">
            Refreshing in <span id="timer" style="font-weight: bold; color: #1e293b;">3</span>s...
        </div>
    </div>
</div>

<style>
#successPopup {
    background: rgba(0, 0, 0, 0.5) !important;
    backdrop-filter: blur(2px);
}
.success-icon {
    animation: popIn 0.4s ease-out;
}
@keyframes popIn {
    0% { transform: scale(0.5); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}
</style>

<script>
const TAX_RATE = <?php echo floatval($db_tax); ?>;
const SC_RATE  = <?php echo floatval($db_sc); ?>;
const AJAX_URL = '<?php echo admin_url("admin-ajax.php"); ?>';
const NONCE    = '<?php echo wp_create_nonce("qr_order_nonce"); ?>';

let cart = [];
let orderMeta = { type: '', table_id: 0, table_name: '' };

// --- Initial Setup Functions ---
function selectType(t) { 
    orderMeta.type = t; 
    if(t === 'dine_in') { jQuery('#step-type').hide(); jQuery('#step-table').show(); } 
    else { orderMeta.table_name = 'Take Out'; finalizeSelection(); }
}
function selectTable(id, n) { orderMeta.table_id = id; orderMeta.table_name = n; finalizeSelection(); }
function finalizeSelection() { 
    jQuery('#pos-overlay').fadeOut(); 
    jQuery('#order-meta-label').text(orderMeta.table_name); 
}

// --- Menu Core Logic (Same as your menu.php) ---
function prepareItem(item) {
    let rawVar = item.variants || item.variants_json || "";
    let variants = [];
    try {
        if (typeof rawVar === 'string' && rawVar.trim() !== "" && rawVar !== "[]") variants = JSON.parse(rawVar);
        else if (Array.isArray(rawVar)) variants = rawVar;
    } catch(e) { variants = []; }

    if(variants.length > 0) {
        let html = `<h3>${item.item_name}</h3><p style="color:#64748b; font-size:14px;">Customize order:</p>`;
        variants.forEach(v => {
            html += `
            <label style="display:flex; align-items:center; gap:12px; padding:12px; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px; cursor:pointer;">
                <input type="checkbox" class="v_opt_cb" value="${v}" style="width:20px; height:20px; accent-color:var(--primary);">
                <span style="font-weight:500;">${v}</span>
            </label>`;
        });
        html += `<button onclick='confirmAdd(${JSON.stringify(item)})' class="btn-place-order">Add to Order</button>`;
        html += `<button onclick="closeM()" style="width:100%; background:none; border:none; margin-top:10px; color:#94a3b8; cursor:pointer;">Cancel</button>`;
        document.getElementById('vBody').innerHTML = html;
        document.getElementById('vModal').style.display = 'flex';
    } else {
        addToCart(item, []);
    }
}

function confirmAdd(item) {
    let selected = [];
    document.querySelectorAll('.v_opt_cb:checked').forEach(cb => selected.push(cb.value));
    addToCart(item, selected);
    closeM();
}

function addToCart(item, variants) {
    let key = item.id + variants.join('');
    let exist = cart.find(x => x.key === key);
    if(exist) { exist.qty++; } 
    else {
        cart.push({ 
            key: key, id: item.id, name: item.item_name, 
            price: parseFloat(item.price), variants: variants, 
            qty: 1, tax_free: parseInt(item.is_tax_free || 0) 
        });
    }
    render();
}

function updateQty(key, delta) {
    let idx = cart.findIndex(x => x.key === key);
    if(idx > -1) {
        cart[idx].qty += delta;
        if(cart[idx].qty <= 0) cart.splice(idx, 1);
        render();
    }
}

function render() {
    let html = '', sub = 0, taxable = 0;
    
    // Reset UI
    document.querySelectorAll('.item-qty-badge').forEach(b => b.style.display = 'none');
    document.querySelectorAll('.item-card').forEach(c => c.classList.remove('selected'));

    cart.forEach(i => {
        let total = i.price * i.qty;
        sub += total;
        if(i.tax_free === 0) taxable += total;

        let b = document.getElementById('badge-'+i.id);
        if(b) {
            let totalQtyForId = cart.filter(x => x.id == i.id).reduce((sum, x) => sum + x.qty, 0);
            b.innerText = totalQtyForId; b.style.display = 'flex';
            document.getElementById('card-'+i.id).classList.add('selected');
        }

        html += `
        <div style="display:flex; justify-content:space-between; margin-bottom:15px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
            <div style="flex:1;">
                <div style="font-weight:600; font-size:14px;">${i.name}</div>
                <div style="font-size:12px; color:#64748b;">${i.qty} x ${i.price.toFixed(2)}৳ ${i.variants.length ? '<br>['+i.variants.join(', ')+']' : ''}</div>
                <div style="margin-top:5px;">
                    <button onclick="updateQty('${i.key}', -1)" style="border:1px solid #ddd; padding:2px 8px; border-radius:4px; cursor:pointer;">-</button>
                    <button onclick="updateQty('${i.key}', 1)" style="border:1px solid #ddd; padding:2px 8px; border-radius:4px; cursor:pointer;">+</button>
                </div>
            </div>
            <div style="font-weight:700;">${total.toFixed(2)}৳</div>
        </div>`;
    });

    document.getElementById('cart-list').innerHTML = cart.length ? html : '<div style="text-align:center; color:#94a3b8; margin-top:50px;">Empty Cart</div>';

    if(cart.length > 0) {
        // ভ্যাট ক্যালকুলেশন
        let vat = taxable * (TAX_RATE / 100);
        
        // সার্ভিস চার্জ ক্যালকুলেশন (শর্ত সাপেক্ষে)
        let sc = 0;
        let scDisplay = '';

        if(orderMeta.type === 'dine_in') {
            sc = sub * (SC_RATE / 100);
            scDisplay = `
                <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;">
                    <span>S. Charge (${SC_RATE}%)</span><span>${sc.toFixed(2)}৳</span>
                </div>`;
        } else {
            // Take Out হলে সার্ভিস চার্জ ০ দেখাবে অথবা লাইনটি হাইড করে দিবে
            scDisplay = `
                <div style="display:flex; justify-content:space-between; font-size:14px; color:#22c55e; margin-bottom:5px;">
                    <span>S. Charge</span><span>0.00৳ (Take Out)</span>
                </div>`;
        }

        let grand = sub + vat + sc;

        document.getElementById('cart-summary').innerHTML = `
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;">
                <span>Subtotal</span><span>${sub.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;">
                <span>VAT (${TAX_RATE}%)</span><span>${vat.toFixed(2)}৳</span>
            </div>
            ${scDisplay}
            <div class="total-box" style="display:flex; justify-content:space-between; border-top: 1px dashed #ccc; padding-top:10px;">
                <span>Total</span><span>${grand.toFixed(2)}৳</span>
            </div>
            <button class="btn-place-order" style="margin-top:15px;" onclick="placeOrder(${grand})">PLACE ORDER</button>
        `;
    } else { 
        document.getElementById('cart-summary').innerHTML = ''; 
    }
}

function placeOrder(grandTotal) {
    const orderBtn = document.querySelector('.btn-place-order');
    if(orderBtn) {
        orderBtn.disabled = true;
        orderBtn.innerText = 'Placing...';
    }

    let sub = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
    let taxable = cart.filter(i => i.tax_free === 0).reduce((sum, i) => sum + (i.price * i.qty), 0);
    let vatVal = taxable * (TAX_RATE / 100);
    let scVal = (orderMeta.type === 'dine_in') ? sub * (SC_RATE / 100) : 0;

    let processedCart = cart.map(item => {
        return {
            id: item.id,
            name: item.name,
            price: item.price,
            qty: item.qty,
            variants_selected: item.variants ? item.variants.join(', ') : '' 
        };
    });

    jQuery.ajax({
        url: AJAX_URL,
        type: 'POST',
        data: {
            action: 'place_qr_order',
            restaurant_id: <?php echo $restaurant_id; ?>,
            table_id: orderMeta.table_id,
            order_type: orderMeta.type,
            items: JSON.stringify(processedCart),
            subtotal: sub,
            tax_amount: vatVal,
            service_charge: scVal,
            grand_total: grandTotal,
            security: NONCE
        },
        success: function(res) {
            if(res.success) { 
                // ১. সাকসেস পপআপ দেখানো
                document.getElementById('successPopup').style.display = 'flex';
                
                // ২. টাইমার এবং রিলোড লজিক
                let timeLeft = 3;
                let timerElement = document.getElementById('timer');
                let countdown = setInterval(function() {
                    timeLeft--;
                    timerElement.innerText = timeLeft;
                    if(timeLeft <= 0) {
                        clearInterval(countdown);
                        location.reload(); // পেজ রিলোড হবে
                    }
                }, 1000);

            } else { 
                alert('Order Failed: ' + res.data); 
                resetOrderBtn(orderBtn);
            }
        },
        error: function(xhr) {
            alert('Server Error. Please try again.');
            resetOrderBtn(orderBtn);
        }
    });
}

function resetOrderBtn(btn) {
    if(btn) {
        btn.disabled = false;
        btn.innerText = 'PLACE ORDER';
    }
}


function showOccupiedAlert(tableName, takenBy, itemCount, timeAgo) {
    document.getElementById('vBody').innerHTML = `
        <div style="text-align:center; padding:10px 0;">
            <div style="font-size:48px; margin-bottom:12px;">🔒</div>
            <h3 style="margin:0 0 8px; color:#1e293b;">${tableName} is Occupied</h3>
            <p style="color:#64748b; margin:0 0 20px; font-size:14px;">This table currently has an active order</p>
            <div style="background:#f8fafc; border-radius:12px; padding:16px; text-align:left; border:1px solid #e2e8f0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                    <span style="color:#64748b; font-size:13px;">👤 Taken by</span>
                    <span style="font-weight:700; font-size:13px; color:#1e293b;">${takenBy}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                    <span style="color:#64748b; font-size:13px;">🛒 Items</span>
                    <span style="font-weight:700; font-size:13px; color:#1e293b;">${itemCount} items</span>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span style="color:#64748b; font-size:13px;">🕐 Order Time</span>
                    <span style="font-weight:700; font-size:13px; color:#1e293b;">${timeAgo}</span>
                </div>
            </div>
            <button onclick="closeM()" class="btn-place-order" 
                    style="margin-top:20px; background:#1e293b;">
                Got it
            </button>
        </div>`;
    document.getElementById('vModal').style.display = 'flex';
}

function closeM() { document.getElementById('vModal').style.display = 'none'; }

// Category Filter
document.querySelectorAll('.cat-item[data-cat]').forEach(el => {
    el.onclick = function() {
        document.querySelectorAll('.cat-item').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        let cat = this.dataset.cat;
        document.querySelectorAll('.item-card').forEach(c => {
            c.style.display = (cat === 'all' || c.dataset.catId === cat) ? 'block' : 'none';
        });
    }
});
</script>