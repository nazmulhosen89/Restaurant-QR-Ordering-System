<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$orders_table = $wpdb->prefix . 'qrrs_orders';
$items_table  = $wpdb->prefix . 'qrrs_order_items';
$today        = current_time('Y-m-d');

// --- ১. পেমেন্ট কমপ্লিট করার লজিক ---
if ( isset($_POST['complete_order_id']) ) {
    $order_to_complete = intval($_POST['complete_order_id']);
    $wpdb->update(
        $orders_table,
        array('order_status' => 'completed'),
        array('id' => $order_to_complete),
        array('%s'),
        array('%d')
    );
    echo "<div class='success-msg'>✅ Order settled successfully!</div>";
}

// --- ২. স্ট্যাটস ক্যালকুলেশন ---
$billing_stats = $wpdb->get_row($wpdb->prepare("
    SELECT 
        COUNT(id) as total_orders,
        SUM(CASE WHEN order_status IN ('pending', 'processing') THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN order_status = 'completed' THEN grand_total ELSE 0 END) as total_collection,
        SUM(CASE WHEN order_status IN ('ready', 'settle_bill') THEN grand_total ELSE 0 END) as pending_collection,
        AVG(CASE WHEN order_status = 'completed' THEN grand_total END) as avg_order
    FROM $orders_table 
    WHERE DATE(created_at) = %s", $today));

$selected_order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
?>

<div class="billing-container">
    <h2 style="margin-bottom: 20px;">💳 Billing & POS System (<?php echo date('d M, Y', strtotime($today)); ?>)</h2>

    <div class="billing-stats-grid">
        <div class="b-stat-card b-total"><small>Today's Total Orders</small><strong><?php echo $billing_stats->total_orders ?: 0; ?></strong></div>
        <div class="b-stat-card b-pending-order"><small>Orders In Kitchen</small><strong><?php echo $billing_stats->pending_orders ?: 0; ?></strong></div>
        <div class="b-stat-card b-collection"><small>Total Collection</small><strong><?php echo number_format($billing_stats->total_collection ?: 0, 2); ?> ৳</strong></div>
        <div class="b-stat-card b-pending-cash"><small>Pending Collection</small><strong style="color: #e67e22;"><?php echo number_format($billing_stats->pending_collection ?: 0, 2); ?> ৳</strong></div>
        <div class="b-stat-card b-avg"><small>Avg. Order Value</small><strong><?php echo number_format($billing_stats->avg_order ?: 0, 2); ?> ৳</strong></div>
    </div>

    <div class="billing-main-wrapper">
        <div class="order-selection-list">
            <h4 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">Orders for Settle (Served)</h4>
            <?php
            $settle_orders = $wpdb->get_results($wpdb->prepare(
                "SELECT id, table_name, grand_total, created_at, order_status 
                FROM $orders_table 
                WHERE order_status IN ('ready', 'settle_bill') 
                AND DATE(created_at) = %s 
                ORDER BY FIELD(order_status, 'settle_bill', 'ready'), id DESC", $today
            ));
            
            if($settle_orders):
                foreach($settle_orders as $so):
                    $is_billing = ($so->order_status === 'settle_bill') ? 'border-left: 5px solid #e74c3c;' : '';
                    $status_text = ($so->order_status === 'settle_bill') ? '🔔 Waiting for Bill' : 'Served';
                    
                    $active_style = ($selected_order_id == $so->id) ? 'border: 2px solid #2ecc71; background: #fafffa;' : '';
                    // Generate Invoice Number
                    $inv_no = '#' . date('Ym', strtotime($so->created_at)) . str_pad($so->id, 4, '0', STR_PAD_LEFT);
                    
                    echo "<div onclick=\"window.location.href='?tab=billing&order_id={$so->id}'\" 
                               style='padding:12px; border-radius:8px; border:1px solid #eee; margin-bottom:10px; cursor:pointer; {$active_style} transition:0.3s;'>
                            <div style='display:flex; justify-content:space-between;'>
                                <strong>{$so->table_name}</strong>
                                <span style='color:#27ae60; font-weight:bold;'>".number_format($so->grand_total, 2)." ৳</span>
                            </div>
                            <small style='color:#b2bec3;'>Order ID: {$inv_no} | Status: Served</small>
                          </div>";
                endforeach;
            else:
                echo "<div style='text-align:center; color:#999; padding:20px;'>No served orders waiting for payment.</div>";
            endif;
            ?>
        </div>

        <div class="billing-form-area" id="billing-invoice-render">
            <?php if($selected_order_id): 
                $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $orders_table WHERE id = %d AND DATE(created_at) = %s", $selected_order_id, $today));
                if($order):
                    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $items_table WHERE order_id = %d", $selected_order_id));
                    
                    // ✅ সংশোধিত অংশ: ক্যালকুলেশন বাদ দিয়ে সরাসরি ডাটাবেস কলাম ব্যবহার
                    $subtotal = $order->total_amount; 
                    $full_invoice_no = '#' . date('Ym', strtotime($order->created_at)) . str_pad($order->id, 4, '0', STR_PAD_LEFT);
            ?>

            <div class="no-print">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h3 style="margin:0;"><span style="color:#e67e22;">Invoice <?php echo $full_invoice_no; ?></span> <br> <span style="color:#2d3436;"><?php echo esc_html($order->table_name); ?></span></h3>
                    </div>
                    <button onclick="window.print()" class="button" style="background:#34495e; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">🖨️ Print Bill</button>
                </div>
                <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">
                
                <div id="invoice-items-load">
                    <?php foreach($items as $item): ?>
                        <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f9f9f9;">
                            <span><?php echo esc_html($item->item_name); ?> (x<?php echo $item->quantity; ?>)</span>
                            <strong><?php echo number_format($item->price * $item->quantity, 2); ?> ৳</strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #f1f1f1;">
                    <div style="display:flex; justify-content:space-between; margin-bottom: 6px; color: #666;">
                        <span>Subtotal</span>
                        <span><?php echo number_format($subtotal, 2); ?> ৳</span>
                    </div>
                    
                    <?php if($order->tax_amount > 0): ?>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 6px; color: #666;">
                        <span>VAT / Tax</span>
                        <span><?php echo number_format($order->tax_amount, 2); ?> ৳</span>
                    </div>
                    <?php endif; ?>

                    <?php if($order->service_charge > 0): ?>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 6px; color: #666;">
                        <span>Service Charge</span>
                        <span><?php echo number_format($order->service_charge, 2); ?> ৳</span>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex; justify-content:space-between; font-size:22px; font-weight:bold; color:#27ae60; margin-top:10px; border-top:2px solid #ddd; padding-top:10px;">
                        <span>Grand Total</span>
                        <span><?php echo number_format($order->grand_total, 2); ?> ৳</span>
                    </div>
                </div>

                <form method="POST" action="?tab=billing" style="margin-top:20px;">
                    <input type="hidden" name="complete_order_id" value="<?php echo $selected_order_id; ?>">
                    <button type="submit" style="width:100%; height:55px; background:#2ecc71; border:none; color:white; font-size:18px; font-weight:bold; border-radius:8px; cursor:pointer; transition: 0.3s;">COMPLETE PAYMENT & SETTLE</button>
                </form>
            </div>
            
<div id="pos-print-area" class="print-only">
    <div style="width: 100%; font-family: 'Courier New', Courier, monospace; color: #000;">
        
        <?php 
        /**
         * যেহেতু payment.php আছে: includes/billing/ ফাইলে
         * আর header.php আছে: templates/partials/ ফাইলে
         * তাই আমাদের ২ ধাপ উপরে গিয়ে templates ফোল্ডারে ঢুকতে হবে।
         */
        $base_path = plugin_dir_path( dirname( __FILE__, 2 ) ); // এটি প্লাগইন রুট ডিরেক্টরিতে নিয়ে যাবে
        $header_path = $base_path . 'templates/partials/header.php';
        $footer_path = $base_path . 'templates/partials/footer.php';

        if ( file_exists( $header_path ) ) {
            include $header_path;
        } else {
            // ডিবাগিং এর জন্য: যদি ফাইল না পায় তবে এই কমেন্টটি সোর্সে দেখাবে
            echo "";
            echo "<h2 style='text-align:center;'>MY RESTAURANT</h2>";
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
                    <td style="text-align: center; vertical-align: top; padding-top: 5px;"><?php echo $item->quantity; ?></td>
                    <td style="text-align: right; vertical-align: top; padding-top: 5px;"><?php echo number_format($item->price * $item->quantity, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:10px; font-size: 13px; border-top: 1px dashed #000; padding-top:5px;">
            <div style="display:flex; justify-content:space-between;"><span>Subtotal:</span><span><?php echo number_format($subtotal, 2); ?></span></div>
            
            <?php if($order->tax_amount > 0): ?>
                <div style="display:flex; justify-content:space-between;"><span>VAT/Tax:</span><span><?php echo number_format($order->tax_amount, 2); ?></span></div>
            <?php endif; ?>

            <?php if($order->service_charge > 0): ?>
                <div style="display:flex; justify-content:space-between;"><span>S. Charge:</span><span><?php echo number_format($order->service_charge, 2); ?></span></div>
            <?php endif; ?>

            <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:16px; margin-top:5px; border-top: 1px double #000; padding-top:5px;">
                <span>Total:</span><span><?php echo number_format($order->grand_total, 2); ?> ৳</span>
            </div>
        </div>

        <?php 
        if ( file_exists( $footer_path ) ) {
            include $footer_path;
        } 
        ?>
    </div>
</div>

            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    /* ... existing styles remain same ... */
    .billing-stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-bottom: 30px; }
    .b-stat-card { background: #fff; padding: 20px; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-top: 4px solid #eee; }
    .b-stat-card strong { display: block; font-size: 20px; margin-top: 8px; color: #2d3436; }
    .b-stat-card small { color: #636e72; text-transform: uppercase; font-size: 11px; font-weight: bold; }
    .b-total { border-color: #3498db; }
    .b-pending-order { border-color: #f1c40f; }
    .b-collection { border-color: #2ecc71; }
    .b-pending-cash { border-color: #e67e22; }
    .b-avg { border-color: #9b59b6; }
    .billing-main-wrapper { display: grid; grid-template-columns: 350px 1fr; gap: 20px; }
    .order-selection-list { background: #fff; border-radius: 10px; padding: 15px; border: 1px solid #ddd; max-height: 600px; overflow-y: auto; }
    .billing-form-area { background: #fff; border-radius: 10px; padding: 25px; border: 1px solid #ddd; min-height: 400px; }
    .print-only { display: none; }
    @media print {
        body * { visibility: hidden; }
        #pos-print-area, #pos-print-area * { visibility: visible; }
        #pos-print-area { position: absolute; left: 0; top: 0; width: 80mm; display: block !important; font-family: 'Courier New', Courier, monospace; color: #000; }
        .no-print, .qrrs-sidebar, .qrrs-header, .billing-stats-grid, .order-selection-list { display: none !important; }
        @page { size: 80mm auto; margin: 0; }
    }
    .success-msg { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; }

    .print-only { display: none; }

@media print {
    /* ১. ব্রাউজারের মার্জিন এবং পেজ সাইজ ফিক্স করা */
    @page { 
        size: 80mm auto; 
        margin: 0mm !important; 
    }

    /* ২. পুরো বডি এবং এইচটিএমএল এর হাইট রিসেট */
    html, body {
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden; /* কন্টেন্টের বাইরে বাড়তি অংশ ব্লক করবে */
    }

    /* ৩. প্রিন্ট এরিয়া সেটিংস */
    #pos-print-area { 
        display: block !important;
        width: 72mm; /* ৮০মিমি রোল এর জন্য সেফ সাইড */
        margin: 0;
        padding: 0 2mm 5mm 2mm; /* নিচে সামান্য গ্যাপ রাখা কাটার সুবিধার জন্য */
        position: relative;
        page-break-after: avoid !important;
        page-break-before: avoid !important;
    }

    /* ৪. অপ্রয়োজনীয় সব কিছু হাইড করা */
    body * { visibility: hidden; }
    #pos-print-area, #pos-print-area * { 
        visibility: visible !important; 
    }

    /* ৫. বিলের নিচে যেন কোনো এক্সট্রা মার্জিন না থাকে */
    .pos-footer {
        margin-bottom: 0 !important;
        padding-bottom: 0 !important;
    }
}


</style>