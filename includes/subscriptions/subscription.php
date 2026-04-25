<?php
if ( ! defined( 'ABSPATH' ) ) exit;
QRRS_Auth::is_admin_only();

$license = qrrs_check_system_license();
$success_msg = '';

// Handle Renewal Request Form
if ( isset($_POST['send_renewal_request']) ) {
    $restaurant_name = get_bloginfo('name');
    $admin_email     = get_option('admin_email');
    $selected_plan   = sanitize_text_field($_POST['selected_plan']);
    
    // Developer Email - Ekhane tomar mail boshao
    $to = 'contact@nazmulh.com'; 
    $subject = 'RMS Plugin Renewal Request [' . $selected_plan . ']: ' . $restaurant_name;
    
    $message = "Hello Nazmul,\n\n";
    $message .= "A new renewal request has been received.\n\n";
    $message .= "Restaurant Name: " . $restaurant_name . "\n";
    $message .= "Selected Package: " . $selected_plan . "\n";
    $message .= "Current Expiry: " . date('d M, Y', strtotime($license['expiry_date'])) . "\n";
    $message .= "Admin Email: " . $admin_email . "\n\n";
    $message .= "Please process the invoice for this client.";

    $headers = array('Content-Type: text/plain; charset=UTF-8', 'From: ' . $restaurant_name . ' <' . $admin_email . '>');

    if ( wp_mail($to, $subject, $message, $headers) ) {
        $success_msg = "Request sent! The developer will contact you shortly for the " . $selected_plan . " plan.";
    }
}
?>

<div class="qrrs-card" style="max-width: 800px; margin: 20px auto; padding: 30px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h2 style="margin: 0; color: #333;">System License & Renewal</h2>
        <p style="color: #666;">Current Status: 
            <span style="font-weight: bold; color: <?php echo ($license['days_left'] <= 15) ? '#e53e3e' : '#38a169'; ?>">
                <?php echo $license['is_expired'] ? 'Expired' : 'Active (' . $license['days_left'] . ' Days Remaining)'; ?>
            </span>
        </p>
    </div>

    <?php if ( $success_msg ) : ?>
        <div style="background: #e6fffa; border: 1px solid #38a169; color: #234e52; padding: 15px; border-radius: 6px; margin-bottom: 25px; text-align: center;">
            <strong>Success!</strong> <?php echo $success_msg; ?>
        </div>
    <?php endif; ?>

    <?php if ( $license['days_left'] <= 15 ) : ?>
    <form method="POST">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 30px;">
            
            <label class="plan-card">
                <input type="radio" name="selected_plan" value="Monthly (299 BDT)" checked>
                <div class="plan-content">
                    <span class="duration">Monthly</span>
                    <span class="price">299৳</span>
                </div>
            </label>

            <label class="plan-card">
                <input type="radio" name="selected_plan" value="Quarterly (899 BDT)">
                <div class="plan-content">
                    <span class="duration">Quarterly</span>
                    <span class="price">899৳</span>
                </div>
            </label>

            <label class="plan-card">
                <input type="radio" name="selected_plan" value="Half Yearly (1699 BDT)">
                <div class="plan-content">
                    <span class="duration">Half Yearly</span>
                    <span class="price">1699৳</span>
                </div>
            </label>

            <label class="plan-card">
                <input type="radio" name="selected_plan" value="Yearly (2999 BDT)">
                <div class="plan-content">
                    <span class="duration">Yearly</span>
                    <span class="price">2999৳</span>
                </div>
            </label>

        </div>

        <div style="text-align: center;">
            <button type="submit" name="send_renewal_request" class="save-btn" style="background: #222; color: #fff; padding: 12px 40px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: bold;">
                Confirm & Request Renewal
            </button>
        </div>
    </form>
    <?php else: ?>
        <div style="text-align: center; padding: 40px; background: #f9f9f9; border-radius: 10px; border: 1px dashed #ccc;">
            <p style="color: #888; margin: 0;">Renewal options will be available when the license has less than 15 days remaining.</p>
        </div>
    <?php endif; ?>
</div>

<style>
    .plan-card { cursor: pointer; position: relative; }
    .plan-card input { position: absolute; opacity: 0; }
    .plan-content { 
        border: 2px solid #eee; padding: 20px 10px; border-radius: 10px; text-align: center; 
        transition: all 0.3s ease; background: #fff; 
    }
    .plan-card input:checked + .plan-content { 
        border-color: #222; background: #f8f9fa; transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
    }
    .duration { display: block; font-size: 14px; color: #666; margin-bottom: 5px; }
    .price { display: block; font-size: 20px; font-weight: bold; color: #222; }
</style>