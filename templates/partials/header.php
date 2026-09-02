<?php 
global $wpdb;
$res_table = $wpdb->prefix . 'qrrs_restaurants';

$restaurant = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $res_table WHERE id = %d", 
    $order->restaurant_id
));

$res_name    = !empty($restaurant->restaurant_name) ? $restaurant->restaurant_name : get_bloginfo('name');
$res_address = !empty($restaurant->address) ? $restaurant->address : 'Address not set';
$res_phone   = !empty($restaurant->phone) ? $restaurant->phone : 'Phone not set';
$res_logo    = !empty($restaurant->restaurant_logo) ? $restaurant->restaurant_logo : '';
?>

<div class="pos-header" style="text-align:center; margin-bottom:15px; font-family: 'Courier New', Courier, monospace;">
    
    <?php if($res_logo): ?>
        <img src="<?php echo esc_url($res_logo); ?>" style="max-width: 80px; height: auto; margin-bottom: 5px;">
    <?php endif; ?>

    <h2 style="margin:0; text-transform:uppercase; font-size: 20px;"><?php echo esc_html($res_name); ?></h2>
    <p style="margin:2px 0; font-size:13px; line-height: 1.2;"><?php echo esc_html($res_address); ?></p>
    <p style="margin:2px 0; font-size:13px;">Phone: <?php echo esc_html($res_phone); ?></p>
    <?php if($restaurant->bin_number): ?>
        <p style="margin:2px 0; font-size:11px;">BIN: <?php echo esc_html($restaurant->bin_number); ?></p>
    <?php endif; ?>
    <div style="border-bottom: 1px double #000; margin-top:10px;"></div>
</div>