
<?php
function handle_billing_load() {
    if ( isset($_GET['tab']) && $_GET['tab'] === 'billing' && isset($_GET['order_id']) ) {
        $order_id = intval($_GET['order_id']);
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'qrrs_orders', 
            ['order_status' => 'settle_bill'], 
            ['id' => $order_id]
        );
    }
}
add_action('admin_init', 'handle_billing_load');