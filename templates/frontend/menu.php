<?php
global $wpdb;
$table_db    = $wpdb->prefix . 'qrrs_tables';
$cat_table   = $wpdb->prefix . 'qrrs_categories';
$items_table = $wpdb->prefix . 'qrrs_items';
$res_table   = $wpdb->prefix . 'qrrs_restaurants'; 

$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
$current_table = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_db WHERE qr_token = %s", trim($token)));

if (!$current_table) {
    echo "<div style='text-align:center; padding:50px;'><h3>Invalid QR Code!</h3></div>";
    return;
}

$res_id = $current_table->restaurant_id;
$res_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM $res_table WHERE id = %d", $res_id));
$db_tax = $res_info->tax_percent ?? 0;
$db_sc  = $res_info->service_charge_percent ?? 0;

$categories = $wpdb->get_results($wpdb->prepare("SELECT * FROM $cat_table WHERE restaurant_id = %d ORDER BY id ASC", $res_id));
$items      = $wpdb->get_results($wpdb->prepare("SELECT * FROM $items_table WHERE restaurant_id = %d", $res_id));
?>

<style>
 
</style>

<div class="menu-wrapper">
    <div class="sidebar-left">
        <div class="cat-item active" data-cat="all">🏠<h4>ALL</h4></div>
        <?php foreach($categories as $cat): 
            $c_name = $cat->category_name ?? $cat->name ?? 'Category';
            $c_img  = $cat->image_url ?? $cat->image ?? '';
        ?>
            <div class="cat-item" data-cat="<?php echo $cat->id; ?>">
                <img src="<?php echo $c_img; ?>" class="cat-icon" onerror="this.src='https://via.placeholder.com/50'">
                <h4><?php echo esc_html($c_name); ?></h4>
            </div>
        <?php endforeach; ?>
    </div>

    

    <!-- <div class="sidebar-top">
        <div class="cat-item active" data-cat="all">🏠<h4>ALL</h4></div>
        <?php foreach($categories as $cat): 
            $c_name = $cat->category_name ?? $cat->name ?? 'Category';
            $c_img  = $cat->image_url ?? $cat->image ?? '';
        ?>
            <div class="cat-item" data-cat="<?php echo $cat->id; ?>">
                <img src="<?php echo $c_img; ?>" class="cat-icon" onerror="this.src='https://via.placeholder.com/50'">
                <h4><?php echo esc_html($c_name); ?></h4>
            </div>
        <?php endforeach; ?>
    </div> -->

    <div class="main-content">
        <div class="table-sticky-header">
            <span style="font-weight:bold; color:#1e293b;"><?php echo esc_html($current_table->table_name); ?></span>
            <span style="font-size:12px; color:#64748b;"><?php echo esc_html($res_info->restaurant_name); ?></span>
        </div>
        <div class="item-grid">
            <?php foreach($items as $item): 
                $i_name  = $item->item_name ?? $item->name ?? 'Item';
                $i_img   = $item->image_url ?? $item->item_image ?? '';
                $i_price = $item->price ?? 0;
                $i_cat   = $item->category_id ?? 0;
                $is_avail = isset($item->is_available) ? intval($item->is_available) : 1;
                $item_json = json_encode($item);
            ?>
            <div class="item-card <?php echo ($is_avail === 0) ? 'out-of-stock' : ''; ?>" 
                 id="card-<?php echo $item->id; ?>" 
                 data-cat-id="<?php echo $i_cat; ?>"
                 onclick='<?php echo ($is_avail === 1) ? "prepareItem($item_json)" : ""; ?>'>
                
                <div class="item-qty-badge" id="badge-<?php echo $item->id; ?>">0</div>
                <img src="<?php echo $i_img; ?>" class="item-img" onerror="this.src='https://via.placeholder.com/300x150?text=Food'">
                
                <div style="padding:15px;">
                    <h4 style="margin:0 0 10px 0; font-size:15px; color:#334155;"><?php echo esc_html($i_name); ?></h4>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:700; color:var(--primary); font-size:16px;"><?php echo number_format($i_price, 2); ?>৳</span>
                        
                        <div id="action-<?php echo $item->id; ?>" onclick="event.stopPropagation();">
                            <?php if($is_avail === 0): ?>
                                <span style="color: #ef4444; font-size: 11px; font-weight: bold; padding: 4px 8px; background: #fee2e2; border-radius: 6px;">Not Available Now</span>
                            <?php else: ?>
                                <div class="card-controls" id="controls-<?php echo $item->id; ?>">
                                    <button class="qty-btn" onclick="updateQty(<?php echo $item->id; ?>, -1); event.stopPropagation();">-</button>
                                    <span class="q-text" style="font-weight:bold; margin: 0 10px;">0</span>
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

    <div class="sidebar-right">
        <div style="padding:20px; border-bottom:1px solid #f1f5f9;">
            <div style="font-weight:bold; font-size:20px; color:#1e293b;">
                Order Details
            </div>
            <div style="font-size:12px; color:var(--primary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">
                <?php echo esc_html($current_table->table_name); ?>  <span style="float:right; text-transform:capitalize;">at <?php echo esc_html($res_info->restaurant_name); ?></span>
            </div>
        </div>
        
        <div id="cart-list" style="flex:1; overflow-y:auto; padding:15px;"></div>
        <div id="cart-summary" style="padding:20px; background:#f8fafc; border-top:1px solid #e2e8f0;"></div>
    </div>
</div>

<!-- Floating Button for Mobile -->
<div id="mobile-order-btn" class="floating-order-btn" onclick="showOrderPreview()">
    <div>
        <span class="count-tag" id="mobile-count">0 Items</span>
        <span>View Order</span>
    </div>
    <span id="mobile-total">0.00৳</span>
</div>

<div id="vModal" class="v-modal">
    <div class="v-modal-content" id="vBody"></div>
</div>

<script>
const qrrs_vars = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce: '<?php echo wp_create_nonce("qr_order_nonce"); ?>' 
};

const TAX_RATE = <?php echo floatval($db_tax); ?>;
const SC_RATE  = <?php echo floatval($db_sc); ?>;
let cart = [];

// ১. আইটেম প্রিপেয়ার করা (Variants থাকলে পপআপ দেখাবে)
function prepareItem(item) {
    let rawVar = item.variants || item.variants_json || "";
    let variants = [];
    try {
        if (typeof rawVar === 'string' && rawVar.trim() !== "" && rawVar !== "[]") variants = JSON.parse(rawVar);
        else if (Array.isArray(rawVar)) variants = rawVar;
    } catch(e) { variants = []; }

    if(variants.length > 0) {
        let name = item.item_name || item.name;
        let html = `<h3 style="margin:0 0 15px 0;">${name}</h3><p style="color:#64748b; font-size:14px; margin-bottom:15px;">Customize your order:</p>`;
        variants.forEach(v => {
            html += `
            <label style="display:flex; align-items:center; gap:12px; padding:12px; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px; cursor:pointer;">
                <input type="checkbox" class="v_opt_cb" value="${v}" style="width:20px; height:20px; accent-color:var(--primary);">
                <span style="font-weight:500;">${v}</span>
            </label>`;
        });
        html += `<button onclick='confirmAdd(${JSON.stringify(item)})' style="width:100%; background:var(--primary); color:#fff; border:none; padding:15px; border-radius:12px; font-weight:bold; margin-top:15px; cursor:pointer; font-size:16px;">Add to Order</button>`;
        html += `<button onclick="closeM()" style="width:100%; background:none; border:none; margin-top:10px; color:#94a3b8; cursor:pointer;">Maybe later</button>`;
        document.getElementById('vBody').innerHTML = html;
        document.getElementById('vModal').style.display = 'flex';
    } else {
        addToCart(item, []);
    }
}

// ২. ভেরিয়েন্ট কনফার্ম করা
function confirmAdd(item) {
    let selected = [];
    document.querySelectorAll('.v_opt_cb:checked').forEach(cb => selected.push(cb.value));
    addToCart(item, selected);
    closeM();
}

// ৩. কার্টে যোগ করা
function addToCart(item, variants) {
    let key = item.id + variants.join('');
    let exist = cart.find(x => x.key === key);
    if(exist) { 
        exist.qty++; 
    } else {
        cart.push({ 
            key: key, id: item.id, name: item.item_name || item.name, 
            price: parseFloat(item.price), variants: variants, 
            qty: 1, tax_free: parseInt(item.is_tax_free || 0) 
        });
    }
    render();
}

// ৪. কোয়ান্টিটি আপডেট (Plus/Minus বাটন)
function updateQty(id, delta) {
    let itemInCart = cart.find(x => x.id == id);
    if(itemInCart) {
        itemInCart.qty += delta;
        if(itemInCart.qty <= 0) cart = cart.filter(x => x.key !== itemInCart.key);
        render();
    }
}

// ৫. রেন্ডার ফাংশন (সবচেয়ে গুরুত্বপূর্ণ পরিবর্তন এখানে)
function render() {
    let html = '';
    let sub = 0, taxable = 0;
    let totalItemsCount = 0;

    // কার্ড UI রিসেট
    document.querySelectorAll('.item-card').forEach(c => {
        c.classList.remove('selected');
        let id = c.id.replace('card-','');
        let b = document.getElementById('badge-'+id);
        let ctrl = document.getElementById('controls-'+id);
        if(b) b.style.display = 'none';
        if(ctrl) ctrl.style.display = 'none';
    });

    // কার্ট আইটেম প্রসেস
    cart.forEach(i => {
        let total = i.price * i.qty;
        sub += total;
        totalItemsCount += i.qty;
        if(i.tax_free === 0) taxable += total;

        let b = document.getElementById('badge-'+i.id);
        let ctrl = document.getElementById('controls-'+i.id);
        let card = document.getElementById('card-'+i.id);
        if(b) { b.innerText = i.qty; b.style.display = 'flex'; }
        if(ctrl) { 
            ctrl.style.display = 'flex'; 
            ctrl.querySelector('.q-text').innerText = i.qty; 
        }
        if(card) card.classList.add('selected');

        html += `
        <div style="display:flex; justify-content:space-between; margin-bottom:15px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
            <div>
                <div style="font-weight:600; font-size:14px; color:#1e293b;">${i.name}</div>
                <div style="font-size:12px; color:#64748b;">${i.qty} x ${i.price.toFixed(2)}৳ ${i.variants.length ? '<br>['+i.variants.join(', ')+']' : ''}</div>
            </div>
            <div style="font-weight:700; color:#1e293b;">${total.toFixed(2)}৳</div>
        </div>`;
    });

    // সাইডবার লিস্ট আপডেট
    document.getElementById('cart-list').innerHTML = cart.length ? html : '<div style="text-align:center; color:#94a3b8; margin-top:50px;">Your cart is empty</div>';

    // সামারি ক্যালকুলেশন
    let vat = taxable * (TAX_RATE / 100);
    let sc = sub * (SC_RATE / 100);
    let grand = sub + vat + sc;

    if(cart.length > 0) {
        document.getElementById('cart-summary').innerHTML = `
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;"><span>Subtotal</span><span>${sub.toFixed(2)}৳</span></div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:5px;"><span>VAT (${TAX_RATE}%)</span><span>${vat.toFixed(2)}৳</span></div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#64748b; margin-bottom:8px;"><span>S. Charge (${SC_RATE}%)</span><span>${sc.toFixed(2)}৳</span></div>
            <div class="total-box" style="display:flex; justify-content:space-between;"><span>Total</span><span>${grand.toFixed(2)}৳</span></div>
            <button style="width:100%; background:var(--primary); color:#fff; border:none; padding:16px; border-radius:14px; font-weight:bold; margin-top:20px; cursor:pointer; font-size:16px;" onclick="showOrderPreview()">PLACE ORDER</button>
        `;
    } else { 
        document.getElementById('cart-summary').innerHTML = ''; 
    }

    // --- ফ্লোটিং মোবাইল বাটন কন্ট্রোল ---
    const mobileBtn = document.getElementById('mobile-order-btn');
    const mobileCount = document.getElementById('mobile-count');
    const mobileTotal = document.getElementById('mobile-total');

    if(mobileBtn) {
        if(cart.length > 0 && window.innerWidth <= 799) {
            mobileBtn.style.display = 'flex';
            mobileCount.innerText = totalItemsCount + (totalItemsCount > 1 ? ' Items' : ' Item');
            mobileTotal.innerText = grand.toFixed(2) + '৳';
        } else {
            mobileBtn.style.display = 'none';
        }
    }
}

// ৬. অর্ডার প্রিভিউ (পপআপ)
function showOrderPreview() {
    if (cart.length === 0) return alert('Your cart is empty!');

    let sub = 0, taxable = 0;
    let itemsHtml = '';

    cart.forEach(i => {
        let total = i.price * i.qty;
        sub += total;
        if(i.tax_free === 0) taxable += total;
        
        itemsHtml += `
        <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:14px; border-bottom:1px solid #f1f5f9; padding-bottom:5px;">
            <span>${i.qty}x ${i.name} ${i.variants.length ? '<br><small style="color:#64748b;">('+i.variants.join(', ')+')</small>' : ''}</span>
            <span style="font-weight:600;">${total.toFixed(2)}৳</span>
        </div>`;
    });

    // আলাদা ক্যালকুলেশন
    let vat = taxable * (TAX_RATE / 100);
    let sc = sub * (SC_RATE / 100);
    let grand = sub + vat + sc;

    let previewHtml = `
        <div style="text-align:center; margin-bottom:20px;">
             <span style="background:var(--primary); color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:bold;">CONFIRMING ORDER</span>
             <h2 style="margin:10px 0 0 0; color:#1e293b;"><?php echo esc_html($current_table->table_name); ?></h2>
        </div>
        
        <div style="max-height: 250px; overflow-y: auto; margin-bottom:15px; border: 1px solid #f1f5f9; padding: 10px; border-radius: 8px;">
            ${itemsHtml}
        </div>

        <div style="background:#f8fafc; padding:15px; border-radius:12px; border: 1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#475569; margin-bottom:6px;">
                <span>Subtotal:</span>
                <span>${sub.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#475569; margin-bottom:6px;">
                <span>VAT (${TAX_RATE}%):</span>
                <span>${vat.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:14px; color:#475569; margin-bottom:10px;">
                <span>Service Charge (${SC_RATE}%):</span>
                <span>${sc.toFixed(2)}৳</span>
            </div>
            <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:18px; border-top: 2px dashed #cbd5e1; pt:10px; margin-top:5px; color:#1e293b;">
                <span style="padding-top:10px;">Grand Total:</span>
                <span style="padding-top:10px; color:var(--primary);">${grand.toFixed(2)}৳</span>
            </div>
        </div>

        <button id="finalConfirmBtn" onclick="processFinalOrder(${grand})" style="width:100%; background:#22c55e; color:#fff; border:none; padding:15px; border-radius:12px; font-weight:bold; margin-top:15px; cursor:pointer; font-size:16px; box-shadow: 0 4px 6px -1px rgba(34, 197, 94, 0.2);">
            CONFIRM ORDER
        </button>
        <button onclick="closeM()" style="width:100%; background:none; border:none; margin-top:10px; color:#94a3b8; cursor:pointer; font-weight:500;">Cancel</button>
    `;

    document.getElementById('vBody').innerHTML = previewHtml;
    document.getElementById('vModal').style.display = 'flex';
}

// ৭. ফাইনাল অর্ডার প্রসেস (AJAX)
function processFinalOrder(grandTotal) {
    const btn = document.getElementById('finalConfirmBtn');
    btn.disabled = true;
    btn.innerText = 'Sending...';

    let sub = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
    let taxable = cart.filter(i => i.tax_free === 0).reduce((sum, i) => sum + (i.price * i.qty), 0);
    let vatVal = taxable * (TAX_RATE / 100);
    let scVal = sub * (SC_RATE / 100);

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
        url: qrrs_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'place_qr_order',
            restaurant_id: <?php echo $res_id; ?>,
            table_id: <?php echo $current_table->id; ?>,
            items: JSON.stringify(processedCart),
            subtotal: sub,
            tax_amount: vatVal,
            service_charge: scVal,
            grand_total: grandTotal,
            security: qrrs_vars.nonce 
        },
        success: function(response) {
            if(response.success) {
                closeM(); 
                cart = []; 
                render();
                alert('Order Placed Successfully!');
                location.reload(); 
            } else {
                alert('Server Error: ' + (response.data || 'Unknown error'));
                btn.disabled = false;
                btn.innerText = 'TRY AGAIN';
            }
        },
        error: function(xhr, status, error) {
            console.error(xhr.responseText);
            alert('Request Failed: ' + status);
            btn.disabled = false;
            btn.innerText = 'TRY AGAIN';
        }
    });
}

function closeM() { document.getElementById('vModal').style.display = 'none'; }

// ক্যাটাগরি ফিল্টার
document.querySelectorAll('.cat-item').forEach(el => {
    el.onclick = function() {
        document.querySelectorAll('.cat-item').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        let cat = this.dataset.cat;
        document.querySelectorAll('.item-card').forEach(c => {
            c.style.display = (cat === 'all' || c.dataset.catId === cat) ? 'block' : 'none';
        });
    }
});

// উইন্ডো রিসাইজ হ্যান্ডলার
window.onresize = function() { render(); };

</script>