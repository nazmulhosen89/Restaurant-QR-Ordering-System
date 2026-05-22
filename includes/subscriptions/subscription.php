<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$license = qrrs_check_system_license();
$success_msg = '';
$error_msg   = '';

/**
 * ১. Renewal request handle (payment info সহ mail পাঠাবে)
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

    // Auto logo (site icon)
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
 * ২. License key activation handle করা
 */
if ( isset($_POST['activate_license_key']) && check_admin_referer('qrrs_activate_license', 'qrrs_activate_nonce') ) {
    $new_key = sanitize_text_field($_POST['qrrs_license_key']);
    update_option('qrrs_license_key', $new_key);
    
    // ১. ট্রান্সিয়েন্ট ডিলিট করার পাশাপাশি অবজেক্ট ক্যাশ ক্লিয়ার করা (লাইভ সার্ভারের জন্য)
    delete_transient('qrrs_license_status');
    if ( function_exists('wp_cache_flush') ) {
        wp_cache_flush(); // ওয়ান-ক্লিক সব ইন্টারনাল ক্যাশ ক্লিয়ার করবে
    }
    
    // ২. লাইসেন্স চেক ফাংশনটিকে ফোর্সফুলি একবার রান করিয়ে ডেটা আপডেট করে নেওয়া
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
        
        // কন্ডিশন ১: লাইসেন্স যদি সফলভাবে অ্যাক্টিভেট হয় (Expired না হয়)
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
        // কন্ডিশন ২: কি সাবমিট করার পরেও যদি লাইসেন্স এক্সপায়ার্ড বা ইনভ্যালিড দেখায়
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
                <input type="radio" name="plan_picker" value="Monthly|299" onchange="qrrsPlanChange(this)">
                <div class="qrrs-plan-inner">
                    <span class="qrrs-plan-dur">Monthly</span>
                    <span class="qrrs-plan-price">299৳</span>
                </div>
            </label>
            <label class="qrrs-plan-card">
                <input type="radio" name="plan_picker" value="Quarterly|899" onchange="qrrsPlanChange(this)">
                <div class="qrrs-plan-inner">
                    <span class="qrrs-plan-dur">Quarterly</span>
                    <span class="qrrs-plan-price">899৳</span>
                    <span class="qrrs-save-badge">Save 98৳</span>
                </div>
            </label>
            <label class="qrrs-plan-card">
                <input type="radio" name="plan_picker" value="Half Yearly|1699" onchange="qrrsPlanChange(this)">
                <div class="qrrs-plan-inner">
                    <span class="qrrs-plan-dur">Half Yearly</span>
                    <span class="qrrs-plan-price">1699৳</span>
                    <span class="qrrs-save-badge">Save 95৳</span>
                </div>
            </label>
            <label class="qrrs-plan-card">
                <input type="radio" name="plan_picker" value="Yearly|2999" checked onchange="qrrsPlanChange(this)">
                <div class="qrrs-plan-inner">
                    <span class="qrrs-plan-dur">Yearly</span>
                    <span class="qrrs-plan-price">2999৳</span>
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
                        Amount: <strong id="modal-plan-price">2999৳</strong>
                    </div>
                </div>
            </div>

            <form method="POST" id="qrrs-payment-form">
                <?php wp_nonce_field('qrrs_renewal_request', 'qrrs_renewal_nonce'); ?>
                <input type="hidden" name="selected_plan"  id="inp-plan"  value="Yearly">
                <input type="hidden" name="selected_price" id="inp-price" value="2999">

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
.qrrs-sub-wrap { 
    max-width: 650px; 
    margin: 40px auto; 
    padding: 25px;
    height: calc(100vh - 310px);
    overflow-y: auto;
    background: #ffffff;
    border-radius: 20px;
    box-shadow: rgba(99, 99, 99, 0.15) 0px 4px 12px 0px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #334155;
    box-sizing: border-box;
}

.qrrs-sub-wrap::-webkit-scrollbar {
    width: 6px;
}
.qrrs-sub-wrap::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}
.qrrs-sub-wrap::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.qrrs-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 24px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.02);
    position: relative;
    overflow: hidden;
    box-sizing: border-box;
}

.qrrs-card-title {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 24px 0;
    text-align: center;
}

.qrrs-step-header {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 35px;
}
.qrrs-step-num {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700;
    z-index: 2;
    box-shadow: 0 0 0 6px #fff;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.qrrs-step-num.active   { background: #4f46e5; color: #fff; box-shadow: 0 0 0 6px #fff, 0 0 0 9px rgba(79, 70, 229, 0.15); }
.qrrs-step-num.done     { background: #e0f2fe; color: #0284c7; }
.qrrs-step-num.inactive { background: #f1f5f9; color: #94a3b8; }

.qrrs-step-line {
    flex: 1;
    height: 4px;
    background: #f1f5f9;
    margin: 0 -2px;
    z-index: 1;
    max-width: 100px;
    border-radius: 2px;
}
.qrrs-step-line.active { background: #0284c7; }

.qrrs-plan-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr); 
    gap: 14px;
    margin-bottom: 25px;
}
@media (min-width: 600px) {
    .qrrs-plan-grid {
        grid-template-columns: repeat(4, 1fr); 
    }
}
.qrrs-plan-card { cursor: pointer; position: relative; }
.qrrs-plan-card input { display: none; }
.qrrs-plan-inner {
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    padding: 22px 10px;
    text-align: center;
    transition: all 0.25s ease;
    background: #fff;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
}
.qrrs-plan-card input:checked + .qrrs-plan-inner {
    border-color: #4f46e5;
    background: #f5f3ff;
    transform: translateY(-4px);
    box-shadow: 0 10px 15px -6px rgba(79, 70, 229, 0.2);
}
.qrrs-plan-dur   { display: block; font-size: 12px; color: #64748b; font-weight: 600; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;}
.qrrs-plan-price { display: block; font-size: 24px; font-weight: 800; color: #0f172a; }
.qrrs-save-badge {
    display: inline-block; margin-top: 10px;
    font-size: 11px; font-weight: 700; padding: 3px 8px;
    background: #dcfce7; color: #15803d;
    border-radius: 50px;
    align-self: center;
}
.qrrs-save-badge.best { background: #fef9c3; color: #854d0e; }

.qrrs-bkash-box {
    display: flex; 
    flex-direction: column;
    gap: 20px; 
    align-items: center;
    background: #e2125d; 
    border-radius: 16px;
    padding: 24px;
    color: #fff;
    margin-bottom: 25px;
    box-shadow: 0 6px 20px rgba(226, 18, 93, 0.15);
    box-sizing: border-box;
    text-align: center;
}
@media (min-width: 520px) {
    .qrrs-bkash-box {
        flex-direction: row;
        text-align: left;
    }
}
.qrrs-bkash-qr {
    width: 150px; 
    height: 150px;
    border-radius: 14px;
    overflow: hidden;
    flex-shrink: 0;
    background: #ffffff;
    display: flex; 
    align-items: center; 
    justify-content: center;
    padding: 6px;
    box-sizing: border-box;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.qrrs-bkash-qr img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px;}
.qrrs-bkash-info { flex: 1; width: 100%; }
.qrrs-bkash-label { font-size: 12px; color: rgba(255,255,255,0.85); font-weight: 600; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.qrrs-bkash-num   { font-size: 30px; font-weight: 800; letter-spacing: 1px; line-height: 1.1; color: #ffffff; }
.qrrs-order-summary { font-size: 14px; color: rgba(255,255,255,0.9); margin-top: 14px; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 10px; }
.qrrs-order-summary strong { color: #fff; font-weight: 700; background: rgba(0,0,0,0.12); padding: 2px 8px; border-radius: 4px; }

.qrrs-field { margin-bottom: 20px; }
.qrrs-field label {
    display: block; font-size: 14px; font-weight: 600;
    color: #344155; margin-bottom: 8px;
}
.qrrs-field label .required { color: #ef4444; margin-left: 2px;}
.qrrs-field input {
    width: 100%; padding: 12px 14px;
    border: 1px solid #cbd5e1; border-radius: 12px;
    font-size: 15px; color: #0f172a;
    outline: none; transition: all 0.2s;
    box-sizing: border-box;
    background: #f8fafc;
}
.qrrs-field input:focus { border-color: #4f46e5; background: #fff; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }

.qrrs-form-actions {
    display: block;
    margin-top: 25px;
}
.qrrs-form-actions .qrrs-btn-bkash-submit {
    width: 100%;
    background: #0284c7; 
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.15);
    padding: 14px;
    font-size: 15px;
    margin-bottom: 12px;
    display: flex;
    justify-content: center;
    align-items: center;
    border: none;
    border-radius: 12px;
    color: #fff;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}
.qrrs-form-actions .qrrs-btn-bkash-submit:hover {
    background: #0369a1;
    box-shadow: 0 6px 16px rgba(2, 132, 199, 0.25);
    transform: translateY(-1px);
}
.qrrs-form-actions .qrrs-btn-outline {
    width: 100%;
    margin-top: 0;
    padding: 14px;
    border: 1px solid #cbd5e1;
    color: #475569;
    font-size: 15px;
    background: transparent;
    display: flex;
    justify-content: center;
    align-items: center;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.qrrs-form-actions .qrrs-btn-outline:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}

.qrrs-btn-primary {
    display: flex; align-items: center; justify-content: center;
    width: 100%; background: #4f46e5; color: #fff;
    border: none; border-radius: 12px;
    padding: 15px; font-size: 16px; font-weight: 700;
    cursor: pointer; transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
    box-sizing: border-box;
}
.qrrs-btn-primary:hover { background: #4338ca; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(79, 70, 229, 0.25); }

.qrrs-success-msg {
    text-align: center; padding: 15px 0 30px 0;
}
.success-icon-wrap {
    width: 64px; height: 64px; background: #dcfce7; color: #16a34a;
    font-size: 28px; font-weight: bold; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;
    box-shadow: 0 0 0 8px #f0fdf4;
}
.qrrs-success-msg h3 { margin: 0 0 10px 0; color: #0f172a; font-size: 22px; font-weight: 700; }
.qrrs-success-msg p  { color: #64748b; font-size: 15px; line-height: 1.6; margin: 0; max-width: 500px; margin: 0 auto; }

.qrrs-key-box {
    background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 16px; padding: 24px;
}
.qrrs-key-box h4 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0; }
.qrrs-key-hint   { font-size: 13px; color: #64748b; margin: 0 0 18px 0; }

.qrrs-key-input-row { display: flex; gap: 12px; }
.qrrs-key-input-row input {
    flex: 1; padding: 14px;
    border: 1px solid #cbd5e1; border-radius: 12px;
    font-size: 15px; font-family: inherit; color: #0f172a; outline: none;
    box-sizing: border-box;
}
.qrrs-key-input-row input[type="text"], 
.manual-update-card input[type="text"] {
    word-break: break-all !important;
    white-space: normal !important;
    text-overflow: clip !important;
    overflow-wrap: break-word !important;
}
.qrrs-key-input-row input:focus { border-color: #4f46e5; background: #fff; }
.qrrs-btn-activate {
    background: #0f172a; color: #fff; border: none;
    border-radius: 12px; padding: 0 28px;
    font-size: 15px; font-weight: 700; cursor: pointer;
    white-space: nowrap; transition: all 0.2s;
}
.qrrs-btn-activate:hover { background: #1e293b; }

.status-active-banner { border-left: 5px solid #22c55e; }
.status-dot-container { position: relative; width: 12px; height: 12px; flex-shrink: 0; }
.status-dot { width: 12px; height: 12px; background: #22c55e; border-radius: 50%; display: block; position: absolute; top:0; left:0; z-index: 2;}
.status-dot-pulse { width: 12px; height: 12px; background: #22c55e; border-radius: 50%; display: block; position: absolute; top:0; left:0; animation: pulse 2s infinite; z-index: 1;}

@keyframes pulse { 
    0% { transform: scale(1); opacity: 0.8; } 
    100% { transform: scale(2.5); opacity: 0; } 
}

.manual-update-card { background: #fafafa; border-style: dashed; }

.qrrs-alert {
    display: flex; gap: 14px; align-items: center;
    padding: 16px 20px; border-radius: 14px; margin-bottom: 24px; font-size: 14px; line-height: 1.5;
}
.alert-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; flex-shrink: 0; }
.qrrs-alert.success { background: #f0fdf4; color: #14532d; border: 1px solid #bbf7d0; }
.qrrs-alert.success .alert-icon { background: #22c55e; color: #fff; }
.qrrs-alert.danger  { background: #fef2f2; color: #7f1d1d; border: 1px solid #fca5a5; }
.qrrs-alert.danger .alert-icon { background: #ef4444; color: #fff; }

.qrrs-alert div, .qrrs-success-msg p {
    word-break: break-word !important;
    overflow-wrap: break-word !important;
}

.animate-fade-in { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
</style>

<script>
const plans = {
    'Monthly|299':      { name: 'Monthly',     price: '299' },
    'Quarterly|899':    { name: 'Quarterly',   price: '899' },
    'Half Yearly|1699': { name: 'Half Yearly', price: '1699' },
    'Yearly|2999':      { name: 'Yearly',      price: '2999' }
};

let currentPlan = { name: 'Yearly', price: '2999' };

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