<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// এখানে আপনি চাইলে ওয়েটারের জন্য স্পেসিফিক স্টাইল বা স্ক্রিপ্ট এনকিউ (enqueue) করতে পারেন
// আপাতত এটি টেম্পলেট ফাইলটি লোড করার দায়িত্ব পালন করবে।
function qrrs_render_waiter_dashboard() {
    include QRRS_PLUGIN_PATH . 'templates/dashboard/waiter.php';
}