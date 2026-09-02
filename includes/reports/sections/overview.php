<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$today = current_time('Y-m-d');
$currency = get_qrrs_restaurant_currency($active_res_id);

if (!isset($active_res_id) || !$active_res_id) {
    echo '<p>Error: No active restaurant found.</p>';
    return;
}

$stats = $wpdb->get_row($wpdb->prepare(
    "SELECT 
        IFNULL(SUM(grand_total), 0) as sales, 
        COUNT(id) as orders,
        IFNULL(AVG(grand_total), 0) as avg_val
     FROM {$wpdb->prefix}qrrs_orders 
     WHERE restaurant_id = %d AND DATE(created_at) = %s AND order_status != 'cancelled'", 
    $active_res_id, $today
));

$sales_trend = $wpdb->get_results($wpdb->prepare(
    "SELECT DATE(created_at) as date, SUM(grand_total) as daily_total 
     FROM {$wpdb->prefix}qrrs_orders 
     WHERE restaurant_id = %d AND order_status != 'cancelled' 
     AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY DATE(created_at) ORDER BY date ASC",
    $active_res_id
));

$trend_labels = []; $trend_data = [];
foreach ($sales_trend as $row) {
    $trend_labels[] = date('D', strtotime($row->date));
    $trend_data[] = $row->daily_total;
}

$staff_performance = $wpdb->get_results($wpdb->prepare(
    "SELECT u.display_name, COUNT(o.id) as order_count 
     FROM {$wpdb->prefix}qrrs_orders o
     JOIN {$wpdb->base_prefix}users u ON o.waiter_id = u.ID
     WHERE o.restaurant_id = %d AND o.order_status != 'cancelled'
     GROUP BY o.waiter_id ORDER BY order_count DESC LIMIT 5",
    $active_res_id
));

$staff_labels = []; $staff_counts = [];
foreach ($staff_performance as $sp) {
    $staff_labels[] = $sp->display_name;
    $staff_counts[] = $sp->order_count;
}


$payments = $wpdb->get_results($wpdb->prepare(
    "SELECT payment_method, COUNT(id) as count 
     FROM {$wpdb->prefix}qrrs_orders 
     WHERE restaurant_id = %d AND order_status != 'cancelled'
     GROUP BY payment_method", 
    $active_res_id
));

$pay_labels = []; $pay_counts = [];
foreach($payments as $p) {
    $pay_labels[] = !empty($p->payment_method) ? ucfirst($p->payment_method) : 'Other';
    $pay_counts[] = $p->count;
}


$top_items = $wpdb->get_results($wpdb->prepare(
    "SELECT item_name, SUM(quantity) as qty, SUM(price * quantity) as revenue 
     FROM {$wpdb->prefix}qrrs_order_items 
     WHERE restaurant_id = %d 
     GROUP BY item_name ORDER BY qty DESC LIMIT 5", 
    $active_res_id
));
?>

<div class="overview-wrapper"">
    
    <!-- Stat Cards Row -->
    <div class="qrrs-stat-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px;">
        
        <div class="qrrs-card stat-card sales">
            <div class="stat-icon">💰</div>
            <div class="stat-details">
                <span class="stat-label">Today's Sales</span>
                <h2 class="stat-value"><?php echo esc_html($currency) . number_format((float)($stats->sales ?? 0), 2); ?></h2>
            </div>
        </div>

        <div class="qrrs-card stat-card orders">
            <div class="stat-icon">📦</div>
            <div class="stat-details">
                <span class="stat-label">Today's Orders</span>
                <h2 class="stat-value"><?php echo intval($stats->orders ?? 0); ?></h2>
            </div>
        </div>

        <div class="qrrs-card stat-card avg-value">
            <div class="stat-icon">📈</div>
            <div class="stat-details">
                <span class="stat-label">Avg Order Value</span>
                <h2 class="stat-value"><?php echo esc_html($currency) . number_format((float)($stats->avg_val ?? 0), 2); ?></h2>
            </div>
        </div>

    </div>

    <!-- Main Charts Grid -->
    <div class="qrrs-main-grid" style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 25px; margin-bottom: 25px;">
        <div class="qrrs-card chart-container">
            <div class="card-header"><h4 style="margin:0;">Last 7 Days Sales</h4></div>
            <div style="height: 300px; padding-top: 15px;"><canvas id="monthlySalesChart"></canvas></div>
        </div>
        <div class="qrrs-card chart-container">
            <div class="card-header"><h4 style="margin:0;">Payment Methods</h4></div>
            <div style="height: 300px; padding-top: 15px;"><canvas id="paymentMethodChart"></canvas></div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="qrrs-bottom-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
        <div class="qrrs-card">
            <div class="card-header"><h4 style="margin:0;">Staff Performance</h4></div>
            <div style="height: 250px; padding-top: 15px;"><canvas id="staffPerformanceChart"></canvas></div>
        </div>
        <div class="qrrs-card">
            <div class="card-header"><h4 style="margin:0;">Top Selling Items</h4></div>
            <div class="table-responsive" style="margin-top: 15px;">
                <table class="qrrs-table">
                    <thead><tr><th>Item Name</th><th>Qty</th><th style="text-align:right;">Revenue</th></tr></thead>
                    <tbody>
                        <?php if($top_items): foreach($top_items as $item): ?>
                        <tr>
                            <td style="font-weight: 500;"><?php echo esc_html($item->item_name); ?></td>
                            <td><span class="badge-qty"><?php echo $item->qty; ?></span></td>
                            <td style="text-align:right; font-weight: 600; color: #2ecc71;"><?php echo esc_html($currency) . number_format($item->revenue, 2); ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" style="text-align:center; padding: 20px;">No data found today.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>



<script>
jQuery(document).ready(function($) {
    new Chart(document.getElementById('monthlySalesChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($trend_labels); ?>,
            datasets: [{
                label: 'Revenue',
                data: <?php echo json_encode($trend_data); ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.05)',
                fill: true,
                tension: 0.4
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    new Chart(document.getElementById('paymentMethodChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($pay_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($pay_counts); ?>,
                backgroundColor: ['#2ecc71', '#3b82f6', '#f59e0b', '#ef4444']
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    new Chart(document.getElementById('staffPerformanceChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($staff_labels); ?>,
            datasets: [{
                label: 'Orders Processed',
                data: <?php echo json_encode($staff_counts); ?>,
                backgroundColor: '#8b5cf6',
                borderRadius: 8
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });
});
</script>