<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$current_user_id = get_current_user_id();

// ১. Restaurant ID & Staff Logic
$staff_info = $wpdb->get_row($wpdb->prepare(
    "SELECT restaurant_id FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d AND status = 'active'",
    $current_user_id
));
$restaurant_id = $staff_info ? $staff_info->restaurant_id : get_user_meta($current_user_id, 'assigned_restaurant', true);

if (!$restaurant_id) {
    echo '<div style="padding:50px; text-align:center;"><h3>No restaurant assigned to your account.</h3></div>';
    return;
}

// ২. Data Fetching
$res_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $restaurant_id));
$db_tax = $res_info->tax_percent ?? 0;
$db_sc  = $res_info->service_charge_percent ?? 0;

$tables = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_tables WHERE restaurant_id = %d ORDER BY id ASC", $restaurant_id));
$categories = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_categories WHERE restaurant_id = %d ORDER BY id ASC", $restaurant_id));
$items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qrrs_items WHERE restaurant_id = %d", $restaurant_id));
?>

<style>
    :root { 
        --primary: #f97316; 
        --primary-dark: #ea580c;
        --bg: #f1f5f9; 
        --white: #ffffff; 
        --text-main: #1e293b;
        --text-muted: #64748b;
        --radius: 12px;
    }
    
    .pos-wrapper { 
        display: flex; 
        height: 90vh; 
        background: var(--bg); 
        position: relative; 
        border-radius: var(--radius); 
        overflow: hidden; 
        font-family: 'Inter', sans-serif;
        border: 1px solid #e2e8f0;
    }
    
    /* Sidebar Left */
    .sidebar-left { width: 100px; background: #fff; border-right: 1px solid #e2e8f0; overflow-y: auto; flex-shrink: 0; }
    .cat-item { padding: 15px 5px; cursor: pointer; text-align: center; border-bottom: 1px solid #f8fafc; transition: 0.2s; }
    .cat-item.active { background: #fff7ed; border-left: 4px solid var(--primary); }
    .cat-icon { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; margin-bottom: 5px; background: #f1f5f9; }
    .cat-item h4 { font-size: 11px; margin: 0; color: var(--text-main); text-transform: uppercase; }

    /* Main Content */
    .main-content { flex: 1; overflow-y: auto; padding: 20px; }
    .item-grid { display: grid; gap: 15px; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
    
    .item-card { 
        background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; 
        overflow: hidden; position: relative; cursor: pointer; transition: 0.2s; 
    }
    .item-card:hover { border-color: var(--primary); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .item-card.selected { border-color: var(--primary); background: #fffaf5; box-shadow: 0 0 0 2px var(--primary); }
    
    .item-img { width: 100%; height: 110px; object-fit: cover; }
    
    /* Badge Fix: শুরুতে হাইড থাকবে */
    .item-qty-badge { 
        position: absolute; top: 8px; right: 8px; 
        background: var(--primary); color: #fff; 
        min-width: 22px; height: 22px; padding: 2px;
        border-radius: 50%; display: none; /* JS will handle showing this */
        align-items: center; justify-content: center; 
        font-size: 12px; font-weight: bold; border: 2px solid #fff; z-index: 10;
    }

    /* Right Sidebar - Cart */
    .sidebar-right { width: 350px; background: #fff; border-left: 1px solid #e2e8f0; display: flex; flex-direction: column; }
    .cart-item-row { padding: 12px; border-bottom: 1px solid #f1f5f9; transition: 0.2s; }
    .qty-btn { border: 1px solid #e2e8f0; background: #fff; padding: 2px 8px; border-radius: 4px; cursor: pointer; font-weight: bold; }
    .qty-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

    /* Modal / Popup Fix */
    .v-modal { 
        position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); 
        display: none; /* JS toggles this */
        align-items: center; justify-content: center; 
        z-index: 99999; backdrop-filter: blur(4px); 
    }
    .v-modal-content { 
        background: #fff; width: 90%; max-width: 400px; 
        border-radius: 20px; padding: 25px; 
        box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);
    }

    /* Buttons */
    .btn-place-order { 
        width: 100%; background: var(--primary); color: #fff; border: none; 
        padding: 16px; border-radius: 12px; font-weight: bold; cursor: pointer; font-size: 16px;
        transition: 0.2s;
    }
    .btn-place-order:hover { background: var(--primary-dark); }

    /* Overlay */
    .pos-overlay { position: absolute; inset: 0; background: #fff; z-index: 1000; display: flex; align-items: center; justify-content: center; }
    .selection-card { background: #fff; border: 1px solid #e2e8f0; width: 450px; padding: 40px; border-radius: 24px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    
    .type-box { 
        border: 2px solid #f1f5f9; border-radius: 16px; padding: 20px; 
        cursor: pointer; transition: 0.2s; font-weight: bold; 
    }
    .type-box:hover { border-color: var(--primary); color: var(--primary); background: #fff7ed; }
</style>

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
            <h3>Select Table</h3>
            <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; margin-top:20px; max-height:300px; overflow-y:auto; padding:10px;">
                <?php foreach($tables as $t): ?>
                    <div class="cat-item" style="border:1px solid #eee; border-radius:10px; padding:10px;" onclick="selectTable(<?php echo $t->id; ?>, '<?php echo esc_js($t->table_name); ?>')"><?php echo $t->table_name; ?></div>
                <?php endforeach; ?>
            </div>
            <button onclick="jQuery('#step-table').hide(); jQuery('#step-type').show();" style="margin-top:15px; background:none; border:none; color:#666; cursor:pointer;">← Back</button>
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

    // ক্যালকুলেশনগুলো পুনরায় নিশ্চিত করা
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
            table_id: orderMeta.table_id, // এটি PHP-তে শুধু নাম খোঁজার জন্য ব্যবহৃত হবে
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
                alert('Order placed successfully!');
                location.reload(); 
            } else { 
                // সার্ভার সাইড থেকে আসা নির্দিষ্ট এরর মেসেজ দেখাবে
                alert('Order Failed: ' + res.data); 
                resetOrderBtn(orderBtn);
            }
        },
        error: function(xhr) {
            // যদি সার্ভার থেকে কোনো রেসপন্সই না আসে (যেমন ৫০০০ এরর)
            console.log(xhr.responseText);
            alert('Server Error: Check console for details.');
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