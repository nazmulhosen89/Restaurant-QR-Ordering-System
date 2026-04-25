
<?php
// Billing logic update for Served orders
function handle_billing_load() {
    if ( isset($_GET['tab']) && $_GET['tab'] === 'billing' && isset($_GET['order_id']) ) {
        $order_id = intval($_GET['order_id']);
        global $wpdb;
        
        // স্ট্যাটাস আপডেট করে 'settle_bill' করে দেওয়া যেন পেমেন্ট সেকশনে ইনভয়েসটি দেখায়
        $wpdb->update(
            $wpdb->prefix . 'qrrs_orders', 
            ['order_status' => 'settle_bill'], 
            ['id' => $order_id]
        );
    }
}
// এই ফাংশনটি আপনার প্লাগইন লোড হওয়ার সময় বা অ্যাডমিন ইনিট হুকে কল করা থাকতে হবে।
add_action('admin_init', 'handle_billing_load');