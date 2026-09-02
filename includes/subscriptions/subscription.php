<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$license = qrrs_check_system_license();
$success_msg = '';
$error_msg   = '';

/**
 * ১. Renewal request handle
 */
if ( isset($_POST['send_renewal_request']) && check_admin_referer('qrrs_renewal_request', 'qrrs_renewal_nonce') ) {

    $restaurant_name = get_bloginfo('name');
    $admin_email     = sanitize_email($_POST['customer_email'] ?? get_option('admin_email'));
    $selected_plan   = sanitize_text_field($_POST['selected_plan'] ?? '');
    $selected_price  = sanitize_text_field($_POST['selected_price'] ?? '');
    $txn_id          = sanitize_text_field($_POST['transaction_id'] ?? '');
    $site_url        = home_url();

    $to      = 'contact@nazmulh.com';
    $subject = '[RMS Renewal] ' . $selected_plan . ' - ' . $restaurant_name;

    $logo = get_site_icon_url(100);

    $message = '
    <div style="font-family: Arial, sans-serif; background:#f4f6f8; padding:20px;">
      <div style="max-width:600px; margin:auto; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0;">
        
        <div style="background:#4f46e5; color:#fff; padding:20px; text-align:center;">
          '.($logo ? '<img src="'.esc_url($logo).'" style="max-height:60px; margin-bottom:10px;"><br>' : '').'
          <h2 style="margin:0; font-size:20px;">RMS Renewal Request</h2>
        </div>

        <div style="padding:20px; color:#334155; font-size:14px; line-height:1.6;">
          
          <p><strong>Restaurant:</strong> '.esc_html($restaurant_name).'</p>
          <p><strong>Website:</strong> <a href="'.esc_url($site_url).'" target="_blank">'.esc_html($site_url).'</a></p>
          <p><strong>Email:</strong> '.esc_html($admin_email).'</p>

          <hr style="border:none; border-top:1px solid #e2e8f0; margin:20px 0;">

          <p><strong>Plan:</strong> '.esc_html($selected_plan).'</p>
          <p><strong>Amount:</strong> '.esc_html($selected_price).'৳</p>
          <p><strong>bKash Txn ID:</strong> 
            <span style="font-weight:700; color:#0f172a;">'.esc_html($txn_id).'</span>
          </p>

          <hr style="border:none; border-top:1px solid #e2e8f0; margin:20px 0;">

          <p style="font-size:12px; color:#64748b;">
            Sent at: '.date_i18n('d M Y, h:i A').'
          </p>

        </div>

        <div style="background:#f8fafc; padding:14px; text-align:center; font-size:12px; color:#94a3b8;">
          Please verify payment and send license key to the customer.
        </div>

      </div>
    </div>
    ';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
    ];

    if ( wp_mail($to, $subject, $message, $headers) ) {
        $success_msg = $selected_plan;
    } else {
        $error_msg = 'Mail sending failed. Please contact the developer directly.';
    }
}

/**
 * ২. License key activation handler
 */
if ( isset($_POST['activate_license_key']) && check_admin_referer('qrrs_activate_license', 'qrrs_activate_nonce') ) {
    $new_key = sanitize_text_field($_POST['qrrs_license_key']);
    update_option('qrrs_license_key', $new_key);
    
    delete_transient('qrrs_license_status');
    if ( function_exists('wp_cache_flush') ) {
        wp_cache_flush();
    }
    
    if ( function_exists('qrrs_check_system_license') ) {
        qrrs_check_system_license(); 
    }

    wp_redirect(
        esc_url_raw(
            add_query_arg('license_activated', '1')
        )
    );
    exit;
}

$activation_done = isset($_GET['license_activated']) && $_GET['license_activated'] === '1';
?>

<div class="qrrs-sub-wrap">

    <?php 
    if ( $activation_done ) : 
        $license = qrrs_check_system_license();
        
        if ( !$license['is_expired'] ) : 
    ?>
            <div class="qrrs-alert success animate-fade-in">
                <span class="alert-icon">✓</span>
                <div>
                    <strong>License Activated!</strong><br>
                    Your license is now active — <strong><?php echo $license['days_left']; ?> days</strong> remaining until <?php echo date('d M Y', strtotime($license['expiry_date'])); ?>.
                </div>
            </div>
    <?php 
        else : 
    ?>
            <div class="qrrs-alert danger animate-fade-in">
                <span class="alert-icon">✕</span>
                <div>
                    <strong>Activation Failed!</strong><br>
                    The license key appears invalid or expired. Please double-check and try again.
                </div>
            </div>
    <?php 
        endif; 
    endif; 
    ?>

    <?php if ( $error_msg ) : ?>
    <div class="qrrs-alert danger animate-fade-in">
        <span class="alert-icon">✕</span>
        <div><?php echo esc_html($error_msg); ?></div>
    </div>
    <?php endif; ?>

    <?php if ( $license['days_left'] <= 15 && !$success_msg ) : ?>

    <div class="qrrs-card" id="step-plan">
        <div class="qrrs-step-header">
            <span class="qrrs-step-num active">1</span>
            <span class="qrrs-step-line"></span>
            <span class="qrrs-step-num inactive">2</span>
            <span class="qrrs-step-line"></span>
            <span class="qrrs-step-num inactive">3</span>
        </div>

        <div style="text-align: center; margin-bottom: 30px;">
            <h2 style="margin: 0 0 6px 0; color: #0f172a; font-weight: 700; font-size: 22px;">System License & Renewal</h2>
            <p style="color: #64748b; margin: 0; font-size: 14px;">Current Status: 
                <span style="font-weight: 700; color: <?php echo ($license['days_left'] <= 15) ? '#ef4444' : '#16a34a'; ?>">
                    <?php echo $license['is_expired'] ? 'Expired' : 'Active (' . $license['days_left'] . ' Days Remaining)'; ?>
                </span>
            </p>
        </div>

        <div class="qrrs-plan-grid">
            <label class="qrrs-plan-card">
                <input type="radio" name="plan_picker" value="Monthly|1444৳ / $12" onchange="qrrsPlanChange(this)">
                <div class="qrrs-plan-inner">
                    <span class="qrrs-plan-dur">Monthly</span>
                    <span class="qrrs-plan-price">1444৳ / $12</span>
                </div>
            </label>
            <label class="qrrs-plan-card">
                <input type="radio" name="plan_picker" value="Quarterly|3939৳ / $32" onchange="qrrsPlanChange(this)">
                <div class="qrrs-plan-inner">
                    <span class="qrrs-plan-dur">Quarterly</span>
                    <span class="qrrs-plan-price">3939৳ / $32</span>
                    <span class="qrrs-save-badge">Save 11%</span>
                </div>
            </label>
            <label class="qrrs-plan-card">
                <input type="radio" name="plan_picker" value="Half Yearly|6999৳ / $57" onchange="qrrsPlanChange(this)">
                <div class="qrrs-plan-inner">
                    <span class="qrrs-plan-dur">Half Yearly</span>
                    <span class="qrrs-plan-price">6999৳ / $57</span>
                    <span class="qrrs-save-badge">Save 18%</span>
                </div>
            </label>
            <label class="qrrs-plan-card">
                <input type="radio" name="plan_picker" value="Yearly|12999৳ / $99" checked onchange="qrrsPlanChange(this)">
                <div class="qrrs-plan-inner">
                    <span class="qrrs-plan-dur">Yearly</span>
                    <span class="qrrs-plan-price">12999৳ / $99</span>
                    <span class="qrrs-save-badge best">Best Value</span>
                </div>
            </label>
        </div>

        <button class="qrrs-btn-primary" onclick="qrrsOpenPayment()">
            Continue to Payment 
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:8px;"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </button>
    </div>

    <div id="qrrs-payment-modal" style="display:none;">
        <div class="qrrs-card" id="step-payment">
            <div class="qrrs-step-header">
                <span class="qrrs-step-num done">✓</span>
                <span class="qrrs-step-line active"></span>
                <span class="qrrs-step-num active">2</span>
                <span class="qrrs-step-line"></span>
                <span class="qrrs-step-num inactive">3</span>
            </div>

            <h3 class="qrrs-card-title">Complete bKash Payment</h3>

            <div class="qrrs-bkash-box">
                <div class="qrrs-bkash-qr">
                    <img src="<?php echo QRRS_URL; ?>assets/images/BkashQR.jpeg" 
                         onerror="this.parentElement.innerHTML='<span style=\'font-size:14px;color:#e2125d;font-weight:700;\'>bKash<br>QR</span>'"
                         alt="bKash QR">
                </div>
                <div class="qrrs-bkash-info">
                    <div class="qrrs-bkash-label">bKash Personal Number</div>
                    <div class="qrrs-bkash-num">01511114910</div>
                    <div class="qrrs-order-summary">
                        Plan: <strong id="modal-plan-name">Yearly</strong> &nbsp;•&nbsp; 
                        Amount: <strong id="modal-plan-price">12999৳ / $99</strong>
                    </div>
                </div>
            </div>

            <form method="POST" id="qrrs-payment-form">
                <?php wp_nonce_field('qrrs_renewal_request', 'qrrs_renewal_nonce'); ?>
                <input type="hidden" name="selected_plan"  id="inp-plan"  value="Yearly">
                <input type="hidden" name="selected_price" id="inp-price" value="12999৳ / $99">

                <div class="qrrs-field">
                    <label>bKash Transaction ID (TxnID) <span class="required">*</span></label>
                    <input type="text" name="transaction_id" placeholder="e.g. 8N6A2X3T1P" required style="text-transform: uppercase;">
                </div>
                <div class="qrrs-field">
                    <label>Your Email Address (For Key Delivery) <span class="required">*</span></label>
                    <input type="email" name="customer_email" 
                           value="<?php echo esc_attr(get_option('admin_email')); ?>" required>
                </div>

                <div class="qrrs-form-actions">
                    <button type="submit" name="send_renewal_request" class="qrrs-btn-primary qrrs-btn-bkash-submit">
                        Submit Payment Confirmation
                    </button>
                    <button type="button" class="qrrs-btn-outline" onclick="qrrsClosePayment()">
                        ← Back to Plans
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>

    <?php if ( $success_msg ) : ?>

    <div class="qrrs-card" id="step-success">
        <div class="qrrs-step-header">
            <span class="qrrs-step-num done">✓</span>
            <span class="qrrs-step-line active"></span>
            <span class="qrrs-step-num done">✓</span>
            <span class="qrrs-step-line active"></span>
            <span class="qrrs-step-num active">3</span>
        </div>

        <div class="qrrs-success-msg">
            <div class="success-icon-wrap">✓</div>
            <h3>Payment Confirmation Submitted</h3>
            <p>Your <strong><?php echo esc_html($success_msg); ?></strong> plan request has been received. The developer will verify your bKash payment and email the license key to you shortly.</p>
        </div>

        <div class="qrrs-key-box">
            <h4>🔑 Activate Your License Key</h4>
            <p class="qrrs-key-hint">Once you receive the key via email, paste it below to upgrade your system instantly.</p>
            <form method="POST">
                <?php wp_nonce_field('qrrs_activate_license', 'qrrs_activate_nonce'); ?>
                <div class="qrrs-key-input-row">
                    <input type="text" name="qrrs_license_key" 
                           placeholder="Enter your license key..."
                           autocomplete="off" spellcheck="false" required>
                    <button type="submit" name="activate_license_key" class="qrrs-btn-activate">
                        Activate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>

    <?php if ( !$success_msg && !$activation_done && !$license['is_expired'] && $license['days_left'] > 15 ) : ?>
    <div class="qrrs-card status-active-banner">
        <div style="display:flex; align-items:center; gap:16px;">
            <div class="status-dot-container">
                <span class="status-dot"></span>
                <span class="status-dot-pulse"></span>
            </div>
            <div>
                <h3 class="qrrs-card-title" style="margin:0 0 4px 0; font-size: 16px;">System License Status</h3>
                <p style="color:#16a34a; font-weight:600; margin:0; font-size:14px; display: flex; align-items: center; gap: 6px;">
                    Active — <?php echo $license['days_left']; ?> days remaining <span style="color: #94a3b8; font-weight: normal;">(until <?php echo date('d M Y', strtotime($license['expiry_date'])); ?>)</span>
                </p>
            </div>
        </div>
        <p style="color:#64748b; font-size:13px; margin:20px 0 0 0; padding-top:14px; border-top:1px dashed #e2e8f0; line-height: 1.5;">
            🔒 Renewal options will unlock automatically when your license has 15 days or less remaining.
        </p>
    </div>
    <?php endif; ?>

    <?php if ( !$success_msg ) : ?>
    <div class="qrrs-card manual-update-card" style="margin-bottom: 0 !important;">
        <h4 style="margin:0 0 6px 0; font-size:14px; color:#1e293b; font-weight: 700;">Update License Key Manually</h4>
        <p style="margin:0 0 16px 0; font-size:13px; color:#64748b;">If you already have a new key, update it directly below.</p>
        <form method="POST">
            <?php wp_nonce_field('qrrs_activate_license', 'qrrs_activate_nonce'); ?>
            <div class="qrrs-key-input-row">
                <input type="text" name="qrrs_license_key" 
                       value="<?php echo esc_attr(get_option('qrrs_license_key')); ?>"
                       placeholder="Paste new license key..." autocomplete="off" spellcheck="false" required>
                <button type="submit" name="activate_license_key" class="qrrs-btn-activate">
                    Update Key
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>

<style>
</style>

<script>
const plans = {
    'Monthly|1444৳ / $12':      { name: 'Monthly',     price: '1444৳ / $12' },
    'Quarterly|3939৳ / $32':    { name: 'Quarterly',   price: '3939৳ / $32' },
    'Half Yearly|6999৳ / $57': { name: 'Half Yearly', price: '6999৳ / $57' },
    'Yearly|12999৳ / $99':      { name: 'Yearly',      price: '12999৳ / $99' }
};

let currentPlan = { name: 'Yearly', price: '12999৳ / $99' };

function qrrsPlanChange(radio) {
    currentPlan = plans[radio.value];
}

function qrrsOpenPayment() {
    const checked = document.querySelector('input[name="plan_picker"]:checked');
    if (!checked) { alert('Please select a renewal plan.'); return; }
    currentPlan = plans[checked.value];
    document.getElementById('modal-plan-name').textContent  = currentPlan.name;
    document.getElementById('modal-plan-price').textContent = currentPlan.price + '৳';
    document.getElementById('inp-plan').value  = currentPlan.name;
    document.getElementById('inp-price').value = currentPlan.price;
    document.getElementById('step-plan').style.display    = 'none';
    
    const modal = document.getElementById('qrrs-payment-modal');
    modal.style.display = 'block';
    modal.classList.add('animate-fade-in');
}

function qrrsClosePayment() {
    document.getElementById('step-plan').style.display    = 'block';
    document.getElementById('qrrs-payment-modal').style.display = 'none';
}
</script>