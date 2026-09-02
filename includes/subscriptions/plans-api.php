<?php
/**
 * 🎯 Nazmulh RMS - Central Plans API & Automated License Generator
 * URL: https://nazmulh.com/plans-api.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
date_default_timezone_set('Asia/Dhaka');

// সিকিউরিটি টোকেন (অনুমোদন লিংক সুরক্ষিত করার জন্য)
define('ACTION_SECRET_TOKEN', 'my_super_secret_webhook_token_2026');
// লাইসেন্স কি জেনারেট করার সিক্রেট সল্ট
define('QRRS_LICENSE_SECRET', 'qrrs_#Na2m1_$ecret_S@lt_2024');

// ১. সেন্ট্রাল প্ল্যান এবং প্রাইজ লিস্ট (এখানে পরিবর্তন করলে সবার সাইটে আপডেট হবে)
$plans = array(
    'monthly'     => array('name' => 'Monthly',     'price' => '1444৳ / $12',  'days' => 30,    'badge' => 'Basic'),
    'quarterly'   => array('name' => 'Quarterly',   'price' => '3939৳ / $32',  'days' => 91,    'badge' => 'Save 11%'),
    'half_yearly' => array('name' => 'Half Yearly', 'price' => '6999৳ / $57',  'days' => 182,   'badge' => 'Save 18%'),
    'yearly'      => array('name' => 'Yearly',      'price' => '12999৳ / $99', 'days' => 365,   'badge' => 'Best Value')
);

// ক্লায়েন্টদের জন্য JSON API রেসপন্স
if (!isset($_GET['action'])) {
    header('Content-Type: application/json');
    echo json_encode($plans);
    exit;
}

// ২. ১-ক্লিক এপ্রুভাল রিকোয়েস্ট হ্যান্ডলার (Approve Button এ ক্লিক করলে এটি চলবে)
$mail_status = "";
$final_key   = "";
$clean_domain= "";
$plan        = "";
$client_email= "";

if (isset($_GET['action']) && $_GET['action'] === 'approve_license') {
    $token   = $_GET['token'] ?? '';
    $domain  = $_GET['domain'] ?? '';
    $plan_id = $_GET['plan'] ?? 'yearly';
    $email   = $_GET['email'] ?? '';

    // সিকিউরিটি টোকেন ভ্যালিডেশন
    if ($token !== ACTION_SECRET_TOKEN) {
        die("❌ Security Violation: Invalid Token Passed.");
    }

    if (empty($domain) || empty($email)) {
        die("❌ Missing Parameters: Domain or Email missing.");
    }

    // ডোমেইন ফরম্যাটিং (www বাদ দেওয়া এবং ট্রিম করা)
    $clean_domain = strtolower(trim($domain));
    $clean_domain = preg_replace('/^https?:\/\//', '', $clean_domain);
    $clean_domain = preg_replace('/^www\./', '', $clean_domain);
    $clean_domain = rtrim($clean_domain, '/');

    // দিন নির্ধারণ
    $days = $plans[$plan_id]['days'] ?? 365;
    $plan = $plans[$plan_id]['name'] ?? 'Yearly';

    // টার্গেট এক্সপায়ারি ডেট ক্যালকুলেশন
    $d = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $d->modify("+$days days");
    $expiry_date = $d->format('Y-m-d');

    // 🔐 ব্যাকএন্ড অটোমেটিক ক্রিপ্টোগ্রাফিক কি জেনারেশন (HMAC-SHA256)
    $payload = $clean_domain . '|' . $expiry_date;
    $hash    = hash_hmac('sha256', $payload, QRRS_LICENSE_SECRET);
    $final_key = base64_encode($payload . '|' . $hash);

    // 📧 PHPMailer এর মাধ্যমে ক্লায়েন্টকে অটোমেটিক লাইসেন্স কি রিপ্লাই পাঠানো
    $mail = new PHPMailer(true);
    try {
        // SMTP Configuration (আপনার নিজস্ব সিপ্যানেল বা মেইল সার্ভারের তথ্যাদি দিন)
        $mail->isSMTP();
        $mail->Host       = 'mail.nazmulh.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'contact@nazmulh.com'; 
        $mail->Password   = 'cont_Naz@220224#$'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_TLS;
        $mail->Port       = 587;

        $mail->setFrom('contact@nazmulh.com', 'Nazmulh RMS Authority');
        $mail->addAddress($email);
        $mail->addReplyTo('contact@nazmulh.com', 'Support');

        $mail->isHTML(true);
        $mail->Subject = 'Payment Confirmed & License Activated - Nazmulh RMS';

        // ইমেইল টেমপ্লেট বডি (RMS Key replay.html ডিজাইন)
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="margin:0; padding:0; background:#f4f6f8; font-family:Arial, sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:30px 0;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 6px rgba(0,0,0,0.05);">
            <tr>
                <td style="background:#0f172a; padding:25px; text-align:center;">
                    <h2 style="color:#ffffff; margin:0; font-size:24px; font-weight:700;">Nazmulh RMS</h2>
                </td>
            </tr>
            <tr>
                <td style="padding:30px;">
                    <h3 style="color:#0f172a; margin-top:0; font-size:18px;">✅ Payment Confirmed</h3>
                    <p style="color:#475569; font-size:14px; line-height:1.6;">
                        Hello,<br><br>
                        We have successfully received your payment. Your system activation request has been approved. Please copy your secure production license key below and paste it into your System License Panel.
                    </p>
                    
                    <div style="background:#f1f5f9; border-left:4px solid #4f46e5; padding:15px; margin:20px 0; border-radius:4px;">
                        <span style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:5px;">YOUR LICENSE KEY:</span>
                        <h2 style="font-family:monospace; font-size:13px; color:#0f172a; word-break:break-all; margin:0; background:#ffffff; padding:10px; border:1px solid #e2e8f0; border-radius:4px; user-select:all;">
                            '.$final_key.'
                        </h2>
                    </div>

                    <h3 style="margin-top:30px; color:#0f172a; font-size:16px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">🧾 Activation & Order Summary</h3>
                    <table width="100%" cellpadding="8" cellspacing="0" style="font-size:14px; color:#334155;">
                        <tr style="background:#f8fafc;"><td><strong>Authorized Domain</strong></td><td>'.$clean_domain.'</td></tr>
                        <tr><td><strong>Subscription Plan</strong></td><td>'.$plan.' ('.$days.' Days)</td></tr>
                        <tr style="background:#f8fafc;"><td><strong>Expiry Date</strong></td><td>'.$expiry_date.'</td></tr>
                        <tr><td><strong>Status</strong></td><td style="color:#16a34a; font-weight:600;">Active / Verified</td></tr>
                    </table>

                    <p style="color:#64748b; font-size:13px; margin-top:30px; line-height:1.5;">
                        Need assist? Feel free to contact our technical operation unit at <a href="mailto:contact@nazmulh.com" style="color:#4f46e5; text-decoration:none;">contact@nazmulh.com</a>.
                    </p>
                </td>
            </tr>
            </table>
        </td></tr>
        </table>
        </body>
        </html>';

        $mail->send();
        $mail_status = "Successfully generated and dispatching mail to client!";
    } catch (Exception $e) {
        $mail_status = "Key Generated internally but Email sending failed: {$mail->ErrorInfo}";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nazmulh RMS Authority Console</title>
    <style>
        body { background: #0f172a; color: #f8fafc; font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 35px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); text-align: center; max-width: 500px; width: 100%; border: 1px solid #334155; }
        .icon { font-size: 48px; color: #22c55e; margin-bottom: 15px; }
        h2 { margin: 0 0 10px 0; font-size: 24px; color: #fff; }
        p { color: #94a3b8; font-size: 14px; margin: 8px 0; }
        .key-box { background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: 12px; margin-top: 15px; }
        textarea { width: 100%; height: 80px; background: #1e293b; border: 1px solid #475569; border-radius: 4px; color: #22c55e; font-family: monospace; font-size: 12px; padding: 8px; resize: none; box-sizing: border-box; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="card">
        <?php if (isset($_GET['action'])): ?>
            <div class="icon">✓</div>
            <h2>License Processing Done</h2>
            <p style="color: #e2e8f0; font-weight:600;"><?php echo $mail_status; ?></p>
            <p>Domain: <strong><?php echo htmlspecialchars($clean_domain); ?></strong></p>
            <p>Plan Type: <strong><?php echo htmlspecialchars($plan); ?></strong></p>
            <p>Client Email: <code><?php echo htmlspecialchars($client_email); ?></code></p>
            <div class="key-box">
                <span style="font-size:11px; color:#64748b; display:block; text-align:left;">Backup License Key:</span>
                <textarea readonly onclick="this.select();"><?php echo htmlspecialchars($final_key); ?></textarea>
            </div>
        <?php else: ?>
            <h2>Nazmulh RMS Endpoint</h2>
            <p>Central Synchronization & Hook Infrastructure Status: ONLINE</p>
        <?php endif; ?>
    </div>
</body>
</html>