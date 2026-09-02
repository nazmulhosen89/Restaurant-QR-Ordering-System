<?php

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <?php wp_head(); ?>
    <style>
        header, footer, .site-header, .site-footer, #masthead, #colophon, #wpadminbar, .nav-menu {
            display: none !important;
        }

        .success-icon {
            animation: scaleIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes scaleIn {
            0% { transform: scale(0); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        #successPopup .v-modal-content {
            border-top: 5px solid #22c55e;
        }

        @keyframes fadeSlideUp {
            0% { transform: translateY(30px); opacity: 0; }
            100% { transform: translateY(0); opacity: 1; }
        }
        .occupied-card {
            animation: fadeSlideUp 0.5s ease forwards;
        }
        .occupied-icon {
            animation: scaleIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) 0.1s both;
        }
        @keyframes pulse-ring {
            0%   { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(249,115,22,0.4); }
            70%  { transform: scale(1);   box-shadow: 0 0 0 15px rgba(249,115,22,0); }
            100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(249,115,22,0); }
        }
        .pulse-orange {
            animation: pulse-ring 2s infinite;
        }
    </style>
</head>
<body <?php body_class(); ?>>

<?php
global $wpdb;
$table_db    = $wpdb->prefix . 'qrrs_tables';
$cat_table   = $wpdb->prefix . 'qrrs_categories';
$items_table = $wpdb->prefix . 'qrrs_items';
$res_table   = $wpdb->prefix . 'qrrs_restaurants';

$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
$current_table = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_db WHERE qr_token = %s", trim($token)
));

if (!$current_table) {
    echo "<div style='display:flex; flex-direction:column; align-items:center; justify-content:center; height:100vh; text-align:center; padding:30px; background:#f8fafc;'>
            <svg width='70' height='70' viewBox='0 0 24 24' fill='none' stroke='#ef4444' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
                <circle cx='12' cy='12' r='10'/><line x1='15' y1='9' x2='9' y2='15'/><line x1='9' y1='9' x2='15' y2='15'/>
            </svg>
            <h3 style='margin:20px 0 10px; color:#1e293b;'>Invalid QR Code</h3>
            <p style='color:#64748b;'>Please scan a valid QR code.</p>
          </div>";
    wp_footer();
    echo "</body></html>";
    return;
}

$res_id   = $current_table->restaurant_id;
$res_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM $res_table WHERE id = %d", $res_id));
$db_tax   = $res_info->tax_percent ?? 0;
$db_sc    = $res_info->service_charge_percent ?? 0;

$today_start = current_time('Y-m-d 00:00:00');
$today_end   = current_time('Y-m-d 23:59:59');

$active_order = $wpdb->get_row($wpdb->prepare("
    SELECT 
        o.id, 
        o.created_at, 
        o.grand_total,
        u.display_name AS waiter_name,
        COUNT(oi.id) AS item_count
    FROM {$wpdb->prefix}qrrs_orders o
    LEFT JOIN {$wpdb->prefix}users u ON o.waiter_id = u.ID
    LEFT JOIN {$wpdb->prefix}qrrs_order_items oi ON o.id = oi.order_id
    WHERE o.restaurant_id = %d
      AND o.table_name = %s
      AND o.order_status NOT IN ('completed', 'cancelled', 'billing')
      AND o.created_at BETWEEN %s AND %s
    GROUP BY o.id
    LIMIT 1
", $res_id, $current_table->table_name, $today_start, $today_end));

if ($active_order) {
    $time_ago   = human_time_diff(strtotime($active_order->created_at), current_time('timestamp'));
    $staff_name = !empty($active_order->waiter_name) ? $active_order->waiter_name : 'Staff';
    $item_count = intval($active_order->item_count);
    ?>
    <div style="
        display:flex; flex-direction:column; align-items:center; justify-content:center;
        min-height:100vh; padding:30px 20px; text-align:center;
        background: linear-gradient(135deg, #fff7ed 0%, #f8fafc 60%, #fef3c7 100%);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    ">
        <!-- Animated Icon -->
        <div class="occupied-icon" style="
            width:100px; height:100px; background:#fff;
            border-radius:50%; display:flex; align-items:center; justify-content:center;
            box-shadow: 0 8px 30px rgba(249,115,22,0.2);
            margin-bottom:25px;
        " class="pulse-orange">
            <svg width="52" height="52" viewBox="0 0 24 24" fill="none" 
                 stroke="#f97316" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </div>

        <!-- Main Card -->
        <div class="occupied-card" style="
            background:#fff; border-radius:20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            padding:30px 25px; width:100%; max-width:340px;
        ">
            <!-- Status Badge -->
            <div style="
                display:inline-flex; align-items:center; gap:6px;
                background:#fff7ed; border:1px solid #fed7aa;
                color:#c2410c; border-radius:20px;
                padding:5px 14px; font-size:12px; font-weight:700;
                margin-bottom:18px; text-transform:uppercase; letter-spacing:0.5px;
            ">
                <span style="width:7px; height:7px; background:#f97316; border-radius:50%; 
                             display:inline-block; animation:pulse-ring 2s infinite;"></span>
                Occupied
            </div>

            <h2 style="margin:0 0 6px; color:#1e293b; font-size:22px; font-weight:800;">
                <?php echo esc_html($current_table->table_name); ?>
            </h2>
            <p style="color:#94a3b8; font-size:14px; margin:0 0 22px;">
                <?php echo esc_html($res_info->restaurant_name ?? ''); ?>
            </p>

            <!-- Info Rows -->
            <div style="border-top:1px solid #f1f5f9; padding-top:18px;">
                <!-- <div style="
                    display:flex; justify-content:space-between; align-items:center;
                    padding:10px 14px; background:#f8fafc; border-radius:10px; margin-bottom:8px;
                ">
                    <span style="color:#64748b; font-size:13px; display:flex; align-items:center; gap:6px;">
                        👤 Staff
                    </span>
                    <span style="font-weight:700; font-size:13px; color:#1e293b;">
                        <?php echo esc_html($staff_name); ?>
                    </span>
                </div>
                <div style="
                    display:flex; justify-content:space-between; align-items:center;
                    padding:10px 14px; background:#f8fafc; border-radius:10px; margin-bottom:8px;
                ">
                    <span style="color:#64748b; font-size:13px;">🛒 Items Ordered</span>
                    <span style="font-weight:700; font-size:13px; color:#1e293b;">
                        <?php echo $item_count; ?> items
                    </span>
                </div> -->
                <div style="
                    display:flex; justify-content:space-between; align-items:center;
                    padding:10px 14px; background:#f8fafc; border-radius:10px;
                ">
                    <span style="color:#64748b; font-size:13px;">🕐 Order Placed</span>
                    <span style="font-weight:700; font-size:13px; color:#1e293b;">
                        <?php echo $time_ago; ?> ago
                    </span>
                </div>
            </div>

            <!-- Help Text -->
            <div style="
                margin-top:22px; padding:14px;
                background: linear-gradient(135deg, #fff7ed, #fef9c3);
                border-radius:12px; border:1px solid #fed7aa;
                color:#92400e; font-size:13px; font-weight:600; line-height:1.5;
            ">
                🙏 An order is already active on this table.<br>
                <span style="font-weight:400; color:#b45309;">Please call a waiter for assistance.</span>
            </div>
        </div>

        <p style="margin-top:25px; font-size:11px; color:#cbd5e1; letter-spacing:0.5px;">
            Powered by QR Restaurant System
        </p>
    </div>
    <?php
    wp_footer();
    echo "</body></html>";
    return;
}

$categories = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $cat_table WHERE restaurant_id = %d ORDER BY id ASC", $res_id
));
$items = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $items_table WHERE restaurant_id = %d", $res_id
));
?>

<div class="menu-wrapper">
    <!-- Left Sidebar: Categories -->
    <div class="sidebar-left">
        <div class="cat-item active" data-cat="all">🏠<h4>ALL</h4></div>
        <?php foreach($categories as $cat): 
            $c_name = $cat->category_name ?? $cat->name ?? 'Category';
            $c_img  = $cat->image_url ?? $cat->image ?? '';
        ?>
            <div class="cat-item" data-cat="<?php echo $cat->id; ?>">
                <img src="<?php echo esc_url($c_img); ?>" class="cat-icon" 
                     onerror="this.src='https://via.placeholder.com/50'">
                <h4><?php echo esc_html($c_name); ?></h4>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Main Content: Items -->
    <div class="main-content">
        <div class="table-sticky-header">
            <div>
                <div style="font-weight:bold; color:#1e293b;">
                    <?php echo esc_html($current_table->table_name); ?>
                </div>
                <div style="font-size:12px; color:#64748b;">
                    <?php echo esc_html($res_info->restaurant_name ?? ''); ?>
                </div>
            </div>
        </div>

        <div class="item-grid">
            <?php foreach($items as $item): 
                $i_name   = $item->item_name ?? $item->name ?? 'Item';
                $i_img    = $item->image_url ?? $item->item_image ?? '';
                $i_price  = $item->price ?? 0;
                $i_cat    = $item->category_id ?? 0;
                $is_avail = isset($item->is_available) ? intval($item->is_available) : 1;
                $item_json = json_encode($item);
            ?>
            <div class="item-card <?php echo ($is_avail === 0) ? 'out-of-stock' : ''; ?>" 
                 id="card-<?php echo $item->id; ?>" 
                 data-cat-id="<?php echo $i_cat; ?>"
                 onclick='<?php echo ($is_avail === 1) ? "prepareItem($item_json)" : ""; ?>'>
                
                <div class="item-qty-badge" id="badge-<?php echo $item->id; ?>">0</div>
                <img src="<?php echo esc_url($i_img); ?>" class="item-img" 
                     onerror="this.src='https://via.placeholder.com/300x150?text=Food'">
                
                <div style="padding:15px;">
                    <h4 style="margin:0 0 10px 0; font-size:15px; color:#334155; height:40px; overflow:hidden;">
                        <?php echo esc_html($i_name); ?>
                    </h4>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:700; color:var(--primary); font-size:16px;">
                            <?php echo number_format($i_price, 2); ?>৳
                        </span>
                        <div id="action-<?php echo $item->id; ?>" onclick="event.stopPropagation();">
                            <?php if($is_avail === 0): ?>
                                <span style="color:#ef4444; font-size:11px; font-weight:bold;">N/A</span>
                            <?php else: ?>
                                <div class="card-controls" id="controls-<?php echo $item->id; ?>">
                                    <button class="qty-btn" onclick="updateQty(<?php echo $item->id; ?>, -1); event.stopPropagation();">-</button>
                                    <span class="q-text" style="font-weight:bold; margin:0 5px;">0</span>
                                    <button class="qty-btn" onclick="updateQty(<?php echo $item->id; ?>, 1); event.stopPropagation();">+</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right Sidebar: Cart -->
    <div class="sidebar-right">
        <div style="padding:20px; border-bottom:1px solid #f1f5f9;">
            <div style="font-weight:bold; font-size:20px; color:#1e293b;">Order Details</div>
        </div>
        <div id="cart-list" style="flex:1; overflow-y:auto; padding:15px;"></div>
        <div id="cart-summary" style="padding:20px; background:#f8fafc; border-top:1px solid #e2e8f0;"></div>
    </div>
</div>

<!-- Mobile Floating Button -->
<div id="mobile-order-btn" class="floating-order-btn" onclick="showOrderPreview()">
    <div>
        <span class="count-tag" id="mobile-count">0 Items</span>
        <span>View Order</span>
    </div>
    <span id="mobile-total">0.00৳</span>
</div>

<!-- Variant / Confirm Modal -->
<div id="vModal" class="v-modal">
    <div class="v-modal-content" id="vBody"></div>
</div>

<!-- Success Popup -->
<div id="successPopup" class="v-modal" style="display:none; z-index:9999;">
    <div class="v-modal-content" style="text-align:center; padding:40px 20px;">
        <div class="success-icon">
            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#22c55e" 
                 stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>
        <h2 style="margin:20px 0 10px; color:#1e293b;">Thank You!</h2>
        <p style="color:#64748b; font-size:16px;">Your order has been placed successfully.</p>
        <p style="margin-top:15px; font-size:13px; color:#94a3b8;">
            Redirecting to home in <span id="timer">3</span> seconds...
        </p>
    </div>
</div>

<script>
const qrrs_vars = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce: '<?php echo wp_create_nonce("qr_order_nonce"); ?>'
};

const TAX_RATE = <?php echo floatval($db_tax); ?>;
const SC_RATE  = <?php echo floatval($db_sc); ?>;
let cart = [];

function prepareItem(item) {
    let rawVar = item.variants || item.variants_json || "";
    let variants = [];
    try {
        if (typeof rawVar === 'string' && rawVar.trim() !== "" && rawVar !== "[]") {
            variants = JSON.parse(rawVar);
        } else if (Array.isArray(rawVar)) {
            variants = rawVar;
        }
    } catch(e) { variants = []; }

    if (variants.length > 0) {
        let name = item.item_name || item.name;
        let html = `<h3 style="margin:0 0 15px 0;">${name}</h3>
                    <p style="color:#64748b; font-size:14px; margin-bottom:15px;">Select Variant:</p>`;
        variants.forEach(v => {
            html += `<label style="display:flex; align-items:center; gap:12px; padding:12px; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px; cursor:pointer;">
                        <input type="checkbox" class="v_opt_cb" value="${v}" style="width:18px; height:18px;">
                        <span style="font-weight:500;">${v}</span>
                     </label>`;
        });
        html += `<button onclick='confirmAdd(${JSON.stringify(item)})' 
                         style="width:100%; background:var(--primary); color:#fff; border:none; padding:15px; border-radius:12px; font-weight:bold; cursor:pointer; font-size:16px;">
                     Add to Order
                 </button>`;
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
    if (exist) {
        exist.qty++;
    } else {
        cart.push({
            key: key,
            id: item.id,
            name: item.item_name || item.name,
            price: parseFloat(item.price),
            variants: variants,
            qty: 1,
            tax_free: parseInt(item.is_tax_free || 0)
        });
    }
    render();
}

function updateQty(id, delta) {
    let itemIdx = cart.findIndex(x => x.id == id);
    if (itemIdx > -1) {
        cart[itemIdx].qty += delta;
        if (cart[itemIdx].qty <= 0) cart.splice(itemIdx, 1);
        render();
    }
}

function render() {
    let html = '';
    let sub = 0, taxable = 0, totalItemsCount = 0;

    document.querySelectorAll('.item-card').forEach(c => {
        c.classList.remove('selected');
        let id = c.id.replace('card-', '');
        let b = document.getElementById('badge-' + id);
        let ctrl = document.getElementById('controls-' + id);
        if (b) b.style.display = 'none';
        if (ctrl) ctrl.style.display = 'none';
    });

    cart.forEach(i => {
        let total = i.price * i.qty;
        sub += total;
        totalItemsCount += i.qty;
        if (i.tax_free === 0) taxable += total;

        let b    = document.getElementById('badge-' + i.id);
        let ctrl = document.getElementById('controls-' + i.id);
        let card = document.getElementById('card-' + i.id);

        if (b)    { b.innerText = i.qty; b.style.display = 'flex'; }
        if (ctrl) { ctrl.style.display = 'flex'; ctrl.querySelector('.q-text').innerText = i.qty; }
        if (card) card.classList.add('selected');

        html += `<div style="display:flex; justify-content:space-between; margin-bottom:15px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
                    <div>
                        <div style="font-weight:600; font-size:14px; color:#1e293b;">${i.name}</div>
                        <div style="font-size:12px; color:#64748b;">
                            ${i.qty} x ${i.price.toFixed(2)}৳ 
                            ${i.variants.length ? '<br>[' + i.variants.join(', ') + ']' : ''}
                        </div>
                    </div>
                    <div style="font-weight:700; color:#1e293b;">${total.toFixed(2)}৳</div>
                 </div>`;
    });

    document.getElementById('cart-list').innerHTML = cart.length
        ? html
        : '<div style="text-align:center; color:#94a3b8; margin-top:50px;">Your cart is empty</div>';

    let vat   = taxable * (TAX_RATE / 100);
    let sc    = sub * (SC_RATE / 100);
    let grand = sub + vat + sc;

    if (cart.length > 0) {
        document.getElementById('cart-summary').innerHTML = `
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;">
                <span>Subtotal</span><span>${sub.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;">
                <span>VAT (${TAX_RATE}%)</span><span>${vat.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:8px;">
                <span>S. Charge (${SC_RATE}%)</span><span>${sc.toFixed(2)}৳</span>
            </div>
            <div class="total-box" style="display:flex; justify-content:space-between;">
                <span>Total</span><span>${grand.toFixed(2)}৳</span>
            </div>
            <button style="width:100%; background:var(--primary); color:#fff; border:none; padding:16px; border-radius:14px; font-weight:bold; margin-top:20px; cursor:pointer; font-size:16px;" 
                    onclick="showOrderPreview()">
                PLACE ORDER
            </button>`;
    } else {
        document.getElementById('cart-summary').innerHTML = '';
    }

    const mobileBtn = document.getElementById('mobile-order-btn');
    if (mobileBtn) {
        if (cart.length > 0 && window.innerWidth <= 799) {
            mobileBtn.style.display = 'flex';
            document.getElementById('mobile-count').innerText = totalItemsCount + ' Items';
            document.getElementById('mobile-total').innerText = grand.toFixed(2) + '৳';
        } else {
            mobileBtn.style.display = 'none';
        }
    }
}

function showOrderPreview() {
    if (cart.length === 0) return alert('Your cart is empty!');

    let sub = 0, taxable = 0;
    let itemsHtml = '';

    cart.forEach(i => {
        let total = i.price * i.qty;
        sub += total;
        if (i.tax_free === 0) taxable += total;
        itemsHtml += `<div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:14px; border-bottom:1px solid #f1f5f9; padding-bottom:5px;">
                        <span>${i.qty}x ${i.name} 
                            ${i.variants.length ? '<br><small style="color:#64748b;">(' + i.variants.join(', ') + ')</small>' : ''}
                        </span>
                        <span style="font-weight:600;">${total.toFixed(2)}৳</span>
                      </div>`;
    });

    let vat   = taxable * (TAX_RATE / 100);
    let sc    = sub * (SC_RATE / 100);
    let grand = sub + vat + sc;

    let previewHtml = `
        <div style="text-align:center; margin-bottom:20px;">
            <span style="background:var(--primary); color:#fff; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:bold;">
                CONFIRM ORDER
            </span>
            <h2 style="margin:10px 0 0 0; color:#1e293b;">
                <?php echo esc_html($current_table->table_name); ?>
            </h2>
        </div>
        <div style="max-height:200px; overflow-y:auto; margin-bottom:15px; padding:10px; border:1px solid #f1f5f9; border-radius:10px;">
            ${itemsHtml}
        </div>
        <div style="background:#f8fafc; padding:15px; border-radius:12px; border:1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;">
                <span>Subtotal:</span><span>${sub.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;">
                <span>VAT (${TAX_RATE}%):</span><span>${vat.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;">
                <span>S. Charge (${SC_RATE}%):</span><span>${sc.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:18px; border-top:1px dashed #cbd5e1; padding-top:10px; margin-top:10px; color:#1e293b;">
                <span>Grand Total:</span>
                <span style="color:var(--primary);">${grand.toFixed(2)}৳</span>
            </div>
        </div>
        <button id="finalConfirmBtn" onclick="processFinalOrder(${grand})" 
                style="width:100%; background:#22c55e; color:#fff; border:none; padding:15px; border-radius:12px; font-weight:bold; margin-top:15px; cursor:pointer; font-size:16px;">
            CONFIRM & SEND
        </button>
        <button onclick="closeM()" 
                style="width:100%; background:none; border:none; margin-top:10px; color:#94a3b8; cursor:pointer;">
            Keep Ordering
        </button>`;

    document.getElementById('vBody').innerHTML = previewHtml;
    document.getElementById('vModal').style.display = 'flex';
}

function processFinalOrder(grandTotal) {
    const btn = document.getElementById('finalConfirmBtn');
    btn.disabled = true;
    btn.innerText = 'Processing...';

    let sub     = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
    let taxable = cart.filter(i => i.tax_free === 0).reduce((sum, i) => sum + (i.price * i.qty), 0);
    let vatVal  = taxable * (TAX_RATE / 100);
    let scVal   = sub * (SC_RATE / 100);

    let processedCart = cart.map(item => ({
        id: item.id,
        name: item.name,
        price: item.price,
        qty: item.qty,
        variants_selected: item.variants.join(', ')
    }));

    jQuery.ajax({
        url: qrrs_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'place_qr_order',
            restaurant_id: <?php echo intval($res_id); ?>,
            table_id: <?php echo intval($current_table->id); ?>,
            items: JSON.stringify(processedCart),
            subtotal: sub,
            tax_amount: vatVal,
            service_charge: scVal,
            grand_total: grandTotal,
            security: qrrs_vars.nonce
        },
        success: function(response) {
            if (response.success) {
                closeM();

                document.getElementById('successPopup').style.display = 'flex';

                let timeLeft = 3;
                let timerEl  = document.getElementById('timer');
                let countdown = setInterval(function() {
                    timeLeft--;
                    timerEl.innerText = timeLeft;
                    if (timeLeft <= 0) {
                        clearInterval(countdown);
                        window.location.href = "<?php echo home_url(); ?>";
                    }
                }, 1000);
            } else {
                alert('Error: ' + (response.data || 'Unknown error'));
                btn.disabled = false;
                btn.innerText = 'TRY AGAIN';
            }
        },
        error: function() {
            alert('Server Error. Please try again.');
            btn.disabled = false;
            btn.innerText = 'TRY AGAIN';
        }
    });
}

function closeM() { document.getElementById('vModal').style.display = 'none'; }

document.querySelectorAll('.cat-item').forEach(el => {
    el.onclick = function() {
        document.querySelectorAll('.cat-item').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        let cat = this.dataset.cat;
        document.querySelectorAll('.item-card').forEach(c => {
            c.style.display = (cat === 'all' || c.dataset.catId === cat) ? 'block' : 'none';
        });
    };
});
</script>

<?php wp_footer(); ?>
</body>
</html>