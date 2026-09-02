<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$orders_table = $wpdb->prefix . 'qrrs_orders';
$items_table  = $wpdb->prefix . 'qrrs_order_items';
$today        = current_time('Y-m-d');


/**
 * Restaurant ID Logic (Admin Session + Staff Logic)
 */
if ( current_user_can('administrator') ) {
    if ( ! session_id() ) session_start();
    $active_res_id = isset($_SESSION['qrrs_active_res_id']) ? intval($_SESSION['qrrs_active_res_id']) : 0;
} else {
    $active_res_id = get_user_meta(get_current_user_id(), 'assigned_restaurant', true);
}

if (!$active_res_id) {
    echo '<div style="padding:50px; text-align:center;"><h3>❌ Please select a restaurant from the dashboard first.</h3></div>';
    return;
}


if ( isset($_POST['complete_order_id']) ) {
    $order_to_complete  = intval($_POST['complete_order_id']);
    $discount_type      = sanitize_text_field($_POST['discount_type'] ?? 'none');
    $discount_value     = floatval($_POST['discount_value'] ?? 0);
    $payment_method     = sanitize_text_field($_POST['payment_method'] ?? 'cash');
    $amount_received    = floatval($_POST['amount_received'] ?? 0);


    $orig_order = $wpdb->get_row($wpdb->prepare(
        "SELECT grand_total FROM $orders_table WHERE id = %d AND restaurant_id = %d",
        $order_to_complete, $active_res_id
    ));

    if ($orig_order) {
        $grand_total     = floatval($orig_order->grand_total);
        $discount_amount = 0;


        if ($discount_type === 'percent') {
            $discount_value  = min(max($discount_value, 0), 100);
            $discount_amount = round($grand_total * $discount_value / 100, 2);
        } elseif ($discount_type === 'flat') {
            $discount_amount = min(max($discount_value, 0), $grand_total);
        }

        $final_total = round($grand_total - $discount_amount, 2);

        $cash_returned = 0;
        if ($amount_received > $final_total) {
            $cash_returned = $amount_received - $final_total;
        }

        $actual_collection = ($amount_received > $final_total) ? $final_total : $amount_received;

        $wpdb->update(
            $orders_table,
            array(
                'order_status'    => 'completed',
                'payment_status'  => 'paid',
                'discount_amount' => $discount_amount,
                'final_total'     => $final_total,
                'payment_method'  => $payment_method,
                'amount_received' => $amount_received, 
                'cash_returned'   => $cash_returned,  
            ),
            array('id' => $order_to_complete, 'restaurant_id' => $active_res_id),
            array('%s', '%s', '%f', '%f', '%s', '%f', '%f'), 
            array('%d', '%d')
        );

        ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            showToastPopup("✅ Payment via <?php echo esc_js(ucfirst($payment_method)); ?> settled successfully!");
        });
    </script>
    <?php

    }
}

$billing_stats = $wpdb->get_row($wpdb->prepare("
    SELECT 
        COUNT(id) as total_orders,
        SUM(CASE WHEN order_status IN ('pending', 'processing') THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN order_status = 'completed' THEN COALESCE(final_total, grand_total) ELSE 0 END) as total_collection,
        SUM(CASE WHEN order_status IN ('ready', 'settle_bill', 'served', 'billing') THEN grand_total ELSE 0 END) as pending_collection,
        AVG(CASE WHEN order_status = 'completed' THEN COALESCE(final_total, grand_total) END) as avg_order
    FROM $orders_table 
    WHERE restaurant_id = %d AND DATE(created_at) = %s", $active_res_id, $today));

$settle_orders = $wpdb->get_results($wpdb->prepare(
    "SELECT id, table_name, grand_total, created_at, order_status 
    FROM $orders_table 
    WHERE restaurant_id = %d 
    AND order_status IN ('ready', 'settle_bill', 'served', 'billing') 
    AND DATE(created_at) = %s 
    ORDER BY FIELD(order_status, 'billing', 'settle_bill', 'ready', 'served'), id DESC",
    $active_res_id, $today
));

$selected_order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
?>

<!-- Success Toast Popup -->
<div id="qrrs-toast-success" style="display:none; position:fixed; top:20px; right:20px; background:#2ecc71; color:white; padding:15px 25px; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.2); z-index:100000; align-items:center; gap:12px; font-weight:600; border-left: 5px solid #1b7a43; animation: slideInRight 0.4s ease-out;">
    <span id="toast-message"></span>
</div>

<style>
@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes slideOutRight {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}

#manager-custom-alert, #manager-custom-confirm {
    backdrop-filter: blur(2px);
}
</style>


<div class="billing-container">
    <h2 style="margin-bottom: 20px;">💳 Billing & POS System (<?php echo date('d M, Y', strtotime($today)); ?>)</h2>

    <div class="billing-stats-grid">
        <div class="b-stat-card b-total"><small>Today's Total Orders</small><strong><?php echo $billing_stats->total_orders ?: 0; ?></strong></div>
        <div class="b-stat-card b-pending-order"><small>Orders In Kitchen</small><strong><?php echo $billing_stats->pending_orders ?: 0; ?></strong></div>
        <div class="b-stat-card b-collection"><small>Total Collection</small><strong><?php echo number_format(round($billing_stats->total_collection ?: 0), 2); ?> ৳</strong></div>
        <div class="b-stat-card b-pending-cash"><small>Pending Collection</small><strong style="color: #e67e22;"><?php echo number_format(round($billing_stats->pending_collection ?: 0), 2); ?> ৳</strong></div>
        <div class="b-stat-card b-avg"><small>Avg. Order Value</small><strong><?php echo number_format($billing_stats->avg_order ?: 0, 2); ?> ৳</strong></div>
    </div>

    <div class="billing-main-wrapper">
        <div class="order-selection-list">
            <h4 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">Orders for Settle</h4>
            <?php
            if ($settle_orders):
                foreach ($settle_orders as $so):
                    $inv_no = '#' . date('Ym', strtotime($so->created_at)) . str_pad($so->id, 4, '0', STR_PAD_LEFT);
                    $active_style = ($selected_order_id == $so->id) ? 'border: 2px solid #2ecc71; background: #fafffa;' : '';
                    echo "<div onclick=\"window.location.href='?tab=billing&order_id={$so->id}'\" 
                               style='padding:12px; border-radius:8px; border:1px solid #eee; margin-bottom:10px; cursor:pointer; {$active_style} transition:0.3s;'>
                            <div style='display:flex; justify-content:space-between;'>
                                <strong>{$so->table_name}</strong>
                                <span style='color:#27ae60; font-weight:bold;'>".number_format(round($so->grand_total), 2)." ৳</span>
                            </div>
                            <small style='color:#b2bec3;'>Order: {$inv_no} | Status: {$so->order_status}</small>
                          </div>";
                endforeach;
            else:
                echo "<div style='text-align:center; color:#999; padding:20px;'>No served orders waiting for payment.</div>";
            endif;
            ?>
        </div>

        <div class="billing-form-area" id="billing-invoice-render">
            <?php if ($selected_order_id):
                $order = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $orders_table WHERE id = %d AND restaurant_id = %d",
                    $selected_order_id, $active_res_id
                ));
                if ($order):
                    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $items_table WHERE order_id = %d", $selected_order_id));
                    $subtotal = $order->total_amount;
                    $full_invoice_no = '#' . date('Ym', strtotime($order->created_at)) . str_pad($order->id, 4, '0', STR_PAD_LEFT);
            ?>

            <div class="no-print">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h3 style="margin:0; font-size: 22px;">
                            <span style="color:#e67e22;">Invoice <?php echo $full_invoice_no; ?></span><br>
                            <span style="color:#2d3436;"><?php echo esc_html($order->table_name); ?></span>
                        </h3>
                    </div>
                    <button onclick="window.print()" class="button" style="background:#34495e; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">🖨️ Print Bill</button>
                </div>
                <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                <div style="float: left;height: calc(100vh - 578px);width: 100%;overflow-y: auto;">
                    <div id="invoice-items-load">
                        <?php foreach ($items as $item): ?>
                            <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f9f9f9;">
                                <span><?php echo esc_html($item->item_name); ?> (x<?php echo $item->quantity; ?>)</span>
                                <strong><?php echo number_format($item->price * $item->quantity, 2); ?> ৳</strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php 
                        $original_grand = $order->grand_total; 
                        $rounded_grand  = round($original_grand); 
                        $adjustment     = $rounded_grand - $original_grand; 
                    ?>

                    <div style="margin-top:20px; padding:15px; background:#f8f9fa; border-radius:8px; border:1px solid #f1f1f1; font-size:12px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:6px; color:#666;">
                            <span>Subtotal</span><span><?php echo number_format($order->total_amount, 2); ?> ৳</span>
                        </div>
                        
                        <?php if ($order->tax_amount > 0): ?>
                        <div style="display:flex; justify-content:space-between; margin-bottom:6px; color:#666;">
                            <span>VAT</span><span><?php echo number_format($order->tax_amount, 2); ?> ৳</span>
                        </div>
                        <?php endif; ?>

                        <?php if ($order->service_charge > 0): ?>
                        <div style="display:flex; justify-content:space-between; margin-bottom:6px; color:#666;">
                            <span>Service Charge</span><span><?php echo number_format($order->service_charge, 2); ?> ৳</span>
                        </div>
                        <?php endif; ?>

                        <div style="display:flex; justify-content:space-between; margin-bottom:6px; color:#666; font-style: italic;">
                            <span>Adjustment (<?php echo ($adjustment >= 0) ? '+' : '-'; ?>)</span>
                            <span><?php echo number_format(abs($adjustment), 2); ?> ৳</span>
                        </div>

                        <div style="display:flex; justify-content:space-between; font-size:22px; font-weight:bold; color:#27ae60; margin-top:10px; border-top:2px solid #ddd; padding-top:10px;">
                            <span>Grand Total</span>
                            <span><?php echo number_format($rounded_grand, 2); ?> ৳</span>
                        </div>
                    </div>

                    <button type="button"
                        onclick="openPaymentModal(<?php echo $selected_order_id; ?>, <?php echo floatval($order->grand_total); ?>, '<?php echo esc_js($full_invoice_no); ?>', '<?php echo esc_js($order->table_name); ?>')"
                        style="width:100%; height:55px; background:#2ecc71; border:none; color:white; font-size:18px; font-weight:bold; border-radius:8px; cursor:pointer; margin-top:20px; transition:0.3s;">
                        💳 COLLECT PAYMENT & SETTLE
                    </button>
                </div>
            </div>

            <div id="pos-print-area" class="print-only">
                <div style="width: 100%; font-family: 'Courier New', Courier, monospace; color: #000;">
                    <?php 
                    $base_path = plugin_dir_path( dirname( __FILE__, 2 ) );
                    $header_path = $base_path . 'templates/partials/header.php';
                    $footer_path = $base_path . 'templates/partials/footer.php';

                    if ( file_exists( $header_path ) ) {
                        include $header_path;
                    } else {
                        echo "<h2 style='text-align:center;'>RESTAURANT BILL</h2>";
                    }
                    ?>

                    <div style="text-align: center; margin-bottom: 10px; border-bottom: 1px dashed #000; padding-bottom: 5px;">
                        <h4 style="margin: 5px 0; font-size: 16px;"><?php echo esc_html($order->table_name); ?></h4>
                        <div style="font-size: 12px;">
                            <span>Inv: <?php echo $full_invoice_no; ?></span><br>
                            <span>Date: <?php echo date('d-m-Y h:i A', strtotime($order->created_at)); ?></span>
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="border-bottom: 1px dashed #000;">
                                <th style="text-align: left; padding: 5px 0;">Item</th>
                                <th style="text-align: center;">Qty</th>
                                <th style="text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $item): ?>
                            <tr>
                                <td style="padding: 5px 0; line-height: 1.2;"><?php echo esc_html($item->item_name); ?></td>
                                <td style="text-align: center;"><?php echo $item->quantity; ?></td>
                                <td style="text-align: right;"><?php echo number_format($item->price * $item->quantity, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="margin-top:10px; font-size: 13px; border-top: 1px dashed #000; padding-top:5px;">
                        <div style="display:flex; justify-content:space-between;">
                            <span>Subtotal:</span><span><?php echo number_format($order->total_amount, 2); ?></span>
                        </div>

                        <?php if($order->tax_amount > 0): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>VAT:</span><span><?php echo number_format($order->tax_amount, 2); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if($order->service_charge > 0): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>S. Charge:</span><span><?php echo number_format($order->service_charge, 2); ?></span>
                            </div>
                        <?php endif; ?>

                        <div style="display:flex; justify-content:space-between; font-style: italic;">
                            <span>Adjustment:</span>
                            <span><?php echo ($adjustment >= 0 ? '+' : '-') . number_format(abs($adjustment), 2); ?></span>
                        </div>

                        <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:16px; margin-top:5px; border-top: 1px double #000; padding-top:5px;">
                            <span>Total:</span><span><?php echo number_format($rounded_grand, 2); ?> ৳</span>
                        </div>
                    </div>

                    <?php if ( file_exists( $footer_path ) ) include $footer_path; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===================== PAYMENT MODAL ===================== -->
<div id="qrrs-payment-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:16px; padding:30px; width:460px; max-width:94vw; max-height:92vh; overflow-y:auto; position:relative; box-shadow:0 20px 60px rgba(0,0,0,0.25);">
        
        <button onclick="closePaymentModal()" style="position:absolute; top:15px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; color:#b2bec3; line-height:1;">✕</button>

        <div style="margin-bottom:20px;">
            <p style="margin:0 0 2px; font-size:13px; color:#636e72;" id="modal-inv-label">Invoice —</p>
            <p style="margin:0; font-size:20px; font-weight:bold; color:#2d3436;">
                Grand Total: <span id="modal-grand-display" style="color:#27ae60;"></span>
            </p>
        </div>

        <!-- Discount Section -->
        <div style="background:#f8f9fa; border-radius:10px; padding:16px; margin-bottom:16px;">
            <p style="margin:0 0 10px; font-size:12px; font-weight:600; color:#636e72; text-transform:uppercase; letter-spacing:0.05em;">Discount</p>
            <div style="display:flex; gap:8px; margin-bottom:12px;">
                <button type="button" id="btn-discount-percent"
                    onclick="setDiscountType('percent')"
                    style="flex:1; padding:8px; border-radius:8px; border:2px solid #2ecc71; background:#eafaf1; color:#1e8449; font-size:13px; font-weight:600; cursor:pointer;">
                    % Percentage
                </button>
                <button type="button" id="btn-discount-flat"
                    onclick="setDiscountType('flat')"
                    style="flex:1; padding:8px; border-radius:8px; border:1px solid #dfe6e9; background:#fff; color:#636e72; font-size:13px; cursor:pointer;">
                    ৳ Flat Amount
                </button>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <input type="number" id="discount-value-input" min="0" value="0" placeholder="0"
                    oninput="recalculate()"
                    style="width:90px; padding:8px 10px; border-radius:8px; border:1px solid #dfe6e9; font-size:15px;">
                <span id="discount-unit-label" style="font-size:13px; color:#636e72;">%</span>
                <span style="font-size:13px; color:#636e72;">→ Discount: <strong id="discount-amount-display" style="color:#2d3436;">0.00 ৳</strong></span>
            </div>
        </div>

        <!-- Payable Amount -->
        <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:#eafaf1; border-radius:10px; margin-bottom:16px; border:1px solid #d5f5e3;">
            <span style="font-size:14px; color:#1e8449; font-weight:600;">Payable Amount</span>
            <span id="payable-amount-display" style="font-size:22px; font-weight:bold; color:#27ae60;">0.00 ৳</span>
        </div>

        <!-- Payment Method -->
        <p style="margin:0 0 8px; font-size:12px; font-weight:600; color:#636e72; text-transform:uppercase; letter-spacing:0.05em;">Payment Method</p>
        <div style="display:flex; gap:8px; margin-bottom:16px;" id="payment-methods">
            <?php foreach (['cash' => '💵 Cash', 'card' => '💳 Card', 'bkash' => '🔴 bKash', 'nagad' => '🟠 Nagad'] as $val => $label): ?>
                <button type="button" data-method="<?php echo $val; ?>"
                    onclick="setPaymentMethod('<?php echo $val; ?>')"
                    style="flex:1; padding:9px 4px; border-radius:8px; border:1px solid #dfe6e9; background:#fff; font-size:12px; cursor:pointer; transition:0.2s;">
                    <?php echo $label; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Amount Received -->
        <p style="margin:0 0 6px; font-size:12px; font-weight:600; color:#636e72; text-transform:uppercase; letter-spacing:0.05em;">Amount Received</p>
        <input type="number" id="amount-received-input" min="0" step="0.01" placeholder="0.00"
            oninput="calcChange()"
            style="width:100%; box-sizing:border-box; padding:12px; border-radius:8px; border:1px solid #dfe6e9; font-size:20px; margin-bottom:12px;">

        <!-- Change -->
        <div id="change-row" style="display:flex; justify-content:space-between; padding:10px 16px; background:#eafaf1; border-radius:8px; margin-bottom:20px;">
            <span style="font-size:14px; color:#1e8449;">Change to Return</span>
            <strong id="change-display" style="font-size:16px; color:#27ae60;">0.00 ৳</strong>
        </div>

        <!-- Hidden form values -->
        <form method="POST" action="?tab=billing" id="payment-confirm-form">
            <input type="hidden" name="complete_order_id" id="hidden-order-id">
            <input type="hidden" name="discount_type"     id="hidden-discount-type"  value="none">
            <input type="hidden" name="discount_value"    id="hidden-discount-value" value="0">
            <input type="hidden" name="payment_method"    id="hidden-payment-method" value="cash">
            <input type="hidden" name="amount_received"   id="hidden-amount-received" value="0">

            <button type="submit" id="confirm-payment-btn"
                style="width:100%; padding:16px; background:#2ecc71; border:none; border-radius:10px; color:#fff; font-size:17px; font-weight:bold; cursor:pointer; transition:0.2s;">
                ✅ Confirm Payment & Settle
            </button>
        </form>

    </div>

    
</div>


<div id="manager-custom-alert" class="v-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; padding:25px; width:360px; text-align:center; box-shadow:0 15px 40px rgba(0,0,0,0.2);">
        <div style="font-size:40px; color:#e74c3c; margin-bottom:10px;">⚠</div>
        <h3 style="margin:0 0 10px 0; color:#1e293b; font-size:18px;">Attention</h3>
        <p id="custom-alert-msg" style="color:#64748b; font-size:14px; margin:0 0 20px 0; line-height:1.4;"></p>
        <button onclick="closeCustomAlert()" style="width:100%; padding:12px; background:#1e293b; color:#fff; border:none; border-radius:8px; font-weight:bold; cursor:pointer; font-size:14px;">OK</button>
    </div>
</div>

<div id="manager-custom-confirm" class="v-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center;">
    <div class="warning-flash" style="background:#fff; border-radius:12px; padding:25px; width:380px; text-align:center; box-shadow:0 15px 40px rgba(0,0,0,0.2);">
        <div class="blink" style="font-size:40px; color:#f1c40f; margin-bottom:10px;">❓</div>
        <h3 class="blink" style="margin:0 0 10px 0; color:red; font-size:25px;">Short Payment</h3>
        <p id="custom-confirm-msg" style="color:#64748b; font-size:14px; margin:0 0 20px 0; line-height:1.4;"></p>
        <div style="display:flex; gap:10px;">
            <button onclick="closeCustomConfirm(false)" style="flex:1; padding:12px; background:#fff; color:#64748b; border:1px solid #dfe6e9; border-radius:8px; font-weight:bold; cursor:pointer; font-size:14px;">Cancel</button>
            <!-- <button onclick="closeCustomConfirm(true)" style="flex:1; padding:12px; background:#2ecc71; color:#fff; border:none; border-radius:8px; font-weight:bold; cursor:pointer; font-size:14px;">Yes, Confirm</button> -->
        </div>
    </div>
</div>
<script>
var _grandTotal    = 0;
var _discountType  = 'percent';
var _paymentMethod = 'cash';

function openPaymentModal(orderId, grandTotal, invNo, tableName) {
    _grandTotal    = Math.round(grandTotal); 
    _discountType  = 'percent';
    _paymentMethod = 'cash';

    document.getElementById('modal-inv-label').textContent = 'Invoice ' + invNo + ' — ' + tableName;
    
    document.getElementById('modal-grand-display').textContent = _grandTotal.toFixed(2) + ' ৳';
    
    document.getElementById('hidden-order-id').value = orderId;
    document.getElementById('discount-value-input').value = 0;
    document.getElementById('amount-received-input').value = '';

    setDiscountType('percent');
    setPaymentMethod('cash');
    recalculate();

    document.getElementById('qrrs-payment-modal').style.display = 'flex';
}

function closePaymentModal() {
    document.getElementById('qrrs-payment-modal').style.display = 'none';
}

document.getElementById('qrrs-payment-modal').addEventListener('click', function(e) {
    if (e.target === this) closePaymentModal();
});

function setDiscountType(type) {
    _discountType = type;
    var btnP = document.getElementById('btn-discount-percent');
    var btnF = document.getElementById('btn-discount-flat');
    var activeStyle  = 'flex:1; padding:8px; border-radius:8px; border:2px solid #2ecc71; background:#eafaf1; color:#1e8449; font-size:13px; font-weight:600; cursor:pointer;';
    var inactiveStyle = 'flex:1; padding:8px; border-radius:8px; border:1px solid #dfe6e9; background:#fff; color:#636e72; font-size:13px; cursor:pointer;';

    if (type === 'percent') {
        btnP.style.cssText = activeStyle;
        btnF.style.cssText = inactiveStyle;
        document.getElementById('discount-unit-label').textContent = '%';
    } else {
        btnF.style.cssText = activeStyle;
        btnP.style.cssText = inactiveStyle;
        document.getElementById('discount-unit-label').textContent = '৳';
    }

    document.getElementById('discount-value-input').value = 0;
    document.getElementById('hidden-discount-type').value = type;
    recalculate();
}

function setPaymentMethod(method) {
    _paymentMethod = method;
    document.getElementById('hidden-payment-method').value = method;
    document.querySelectorAll('#payment-methods button').forEach(function(btn) {
        if (btn.dataset.method === method) {
            btn.style.border = '2px solid #2ecc71';
            btn.style.background = '#eafaf1';
            btn.style.fontWeight = '600';
            btn.style.color = '#1e8449';
        } else {
            btn.style.border = '1px solid #dfe6e9';
            btn.style.background = '#fff';
            btn.style.fontWeight = '400';
            btn.style.color = '#636e72';
        }
    });
}

function recalculate() {
    var val = parseFloat(document.getElementById('discount-value-input').value) || 0;
    var discountAmt = 0;

    if (_discountType === 'percent') {
        val = Math.min(Math.max(val, 0), 100);
        discountAmt = (_grandTotal * val / 100);
    } else {
        val = Math.min(Math.max(val, 0), _grandTotal);
        discountAmt = val;
    }

    
    var payable = Math.round(_grandTotal - discountAmt);

    document.getElementById('discount-amount-display').textContent = discountAmt.toFixed(2) + ' ৳';
    document.getElementById('payable-amount-display').textContent  = payable.toFixed(2) + ' ৳';
    document.getElementById('hidden-discount-value').value  = val;

    calcChange(payable);
}

function calcChange(payableOverride) {
    var payable  = payableOverride !== undefined ? payableOverride
                 : parseFloat(document.getElementById('payable-amount-display').textContent) || 0;
    var received = parseFloat(document.getElementById('amount-received-input').value) || 0;
    document.getElementById('hidden-amount-received').value = received;

    var change = received - payable;
    var changeRow = document.getElementById('change-row');
    var changeDisplay = document.getElementById('change-display');

    if (change >= 0) {
        changeDisplay.textContent = change.toFixed(2) + ' ৳';
        changeRow.style.background = '#eafaf1';
        changeDisplay.style.color = '#27ae60';
    } else {
        changeDisplay.textContent = '⚠ Short: ' + Math.abs(change).toFixed(2) + ' ৳';
        changeRow.style.background = '#fef9e7';
        changeDisplay.style.color = '#e67e22';
    }
}
var confirmCallback = null;

function showCustomAlert(message) {
    document.getElementById('custom-alert-msg').textContent = message;
    document.getElementById('manager-custom-alert').style.display = 'flex';
}

function closeCustomAlert() {
    document.getElementById('manager-custom-alert').style.display = 'none';
    document.getElementById('amount-received-input').focus();
}

function showCustomConfirm(message, callback) {
    document.getElementById('custom-confirm-msg').textContent = message;
    document.getElementById('manager-custom-confirm').style.display = 'flex';
    confirmCallback = callback;
}

function closeCustomConfirm(isConfirmed) {
    document.getElementById('manager-custom-confirm').style.display = 'none';
    if (isConfirmed && typeof confirmCallback === 'function') {
        confirmCallback();
    } else {
        document.getElementById('amount-received-input').focus();
    }
    confirmCallback = null;
}

document.getElementById('amount-received-input').addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault(); 
        validateAndSubmitPayment();
    }
});

document.getElementById('confirm-payment-btn').addEventListener('click', function(e) {
    e.preventDefault(); 
    validateAndSubmitPayment();
});

function validateAndSubmitPayment() {
    var received = parseFloat(document.getElementById('amount-received-input').value) || 0;
    var payable  = parseFloat(document.getElementById('payable-amount-display').textContent) || 0;

    if (received <= 0) {
        showCustomAlert('Please enter the amount received.');
        return;
    }

    if (received < payable) {
        showCustomConfirm('Amount received is less than payable. Please check the amount', function() {
            document.getElementById('payment-confirm-form').submit();
        });
    } else {
        document.getElementById('payment-confirm-form').submit();
    }
}

function printReceipt() {
    window.print();
}

function showToastPopup(message) {
    var toast = document.getElementById('qrrs-toast-success');
    var msgSpan = document.getElementById('toast-message');
    
    msgSpan.textContent = message;
    toast.style.display = 'flex';

    setTimeout(function() {
        toast.style.animation = 'slideOutRight 0.4s ease-in forwards';
        setTimeout(function() {
            toast.style.display = 'none';
            toast.style.animation = 'slideInRight 0.4s ease-out'; 
        }, 400);
    }, 3000);
}
</script>

