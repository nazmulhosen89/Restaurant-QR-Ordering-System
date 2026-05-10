<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
    wp_redirect( home_url( '/restaurant-login/' ) ); // আপনার লগইন পেজের স্লাগ দিন
    exit;
}

// ২. ইউজারের ওয়েটার বা ম্যানেজার পারমিশন আছে কি না চেক
if ( ! current_user_can( 'qr_waiter' ) && ! current_user_can( 'administrator' ) && ! current_user_can( 'manager' ) ) {
    wp_die( 'Access Denied: You do not have permission to view the Waiter Terminal.', 'Permission Error' );
}

wp_enqueue_script( 'qrrs-app-js' );

global $wpdb;

$current_user      = wp_get_current_user();
$table_tables      = $wpdb->prefix . 'qrrs_tables';
$table_orders      = $wpdb->prefix . 'qrrs_orders';
$table_items_db    = $wpdb->prefix . 'qrrs_order_items';
$user_res_id       = get_user_meta( get_current_user_id(), 'restaurant_id', true ) ?: 1;
$current_waiter_id = get_current_user_id();

// $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'terminal';

$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'terminal';

// ১. স্ট্যাটাস কুয়েরি (নতুন রিকোয়েস্ট অনুযায়ী)
$stats = $wpdb->get_row($wpdb->prepare("
    SELECT 
        COUNT(id) AS resto_total,
        SUM(CASE WHEN waiter_id = %d THEN 1 ELSE 0 END) AS my_total,
        SUM(CASE WHEN waiter_id = %d AND order_status = 'pending' THEN 1 ELSE 0 END) AS my_pending,
        SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) AS resto_kitchen,
        SUM(CASE WHEN waiter_id = %d AND order_status = 'ready' THEN 1 ELSE 0 END) AS my_ready,
        SUM(CASE WHEN waiter_id = %d AND order_status = 'served' THEN 1 ELSE 0 END) AS my_served,
        SUM(CASE WHEN waiter_id = %d AND order_status = 'completed' THEN 1 ELSE 0 END) AS my_completed
    FROM $table_orders
    WHERE restaurant_id = %d AND DATE(created_at) = CURDATE()
", $current_waiter_id, $current_waiter_id, $current_waiter_id, $current_waiter_id, $current_waiter_id, $user_res_id));

// ১. ফ্লোর প্ল্যানের জন্য কুয়েরি (টেবিল নাম অনুযায়ী সিরিয়াল)
$tables_data = $wpdb->get_results($wpdb->prepare("
    SELECT t.*, 
        o.order_status, 
        o.id AS active_order_id,
        o.waiter_id,
        o.created_at,
        u.display_name as waiter_name
    FROM $table_tables t
    LEFT JOIN $table_orders o ON t.table_name = o.table_name 
        AND o.restaurant_id = %d 
        AND o.order_status NOT IN ('completed','cancelled','billing')
        AND DATE(o.created_at) = CURDATE()
    LEFT JOIN {$wpdb->prefix}users u ON o.waiter_id = u.ID
    WHERE t.restaurant_id = %d
    GROUP BY t.table_name
    /* ফ্লোর প্ল্যান হবে টেবিল নম্বর অনুযায়ী: Table 1, Table 2... */
    ORDER BY CAST(SUBSTRING_INDEX(t.table_name,' ',-1) AS UNSIGNED) ASC, t.table_name ASC
", $user_res_id, $user_res_id));

// ২. অপারেশনাল টাস্কের জন্য আলাদা কুয়েরি (নতুন অর্ডার আগে আসবে)
$where_clause = "AND (o.waiter_id = $current_waiter_id OR o.waiter_id = 0)";
// যদি ইউজার অ্যাডমিনিস্ট্রেটর হয় তবে সব দেখাবে
if ( current_user_can('administrator') ) {
    $where_clause = ""; 
}
$active_orders = $wpdb->get_results($wpdb->prepare("
    SELECT o.*, u.display_name as waiter_name
    FROM $table_orders o
    LEFT JOIN {$wpdb->prefix}users u ON o.waiter_id = u.ID
    WHERE o.restaurant_id = %d 
      AND o.order_status NOT IN ('completed','cancelled','billing')
      AND DATE(o.created_at) = CURDATE()
      AND (
          o.waiter_id = %d           /* ১. ওয়েটারের নিজের অর্ডার */
          OR o.waiter_id = 0         /* ২. কাস্টমারের QR অর্ডার */
          OR o.waiter_id IN (        /* ৩. অ্যাডমিন বা ম্যানেজারদের আইডি থেকে করা অর্ডার */
              SELECT ID FROM {$wpdb->base_prefix}users u 
              INNER JOIN {$wpdb->base_prefix}usermeta um ON u.ID = um.user_id 
              WHERE um.meta_key = '{$wpdb->prefix}capabilities' 
              AND (um.meta_value LIKE '%%administrator%%' OR um.meta_value LIKE '%%manager%%')
          )
      )
    ORDER BY o.id DESC
", $user_res_id, $current_waiter_id));

if (!$tables_data) $tables_data = [];

$res_table = $wpdb->prefix . 'qrrs_restaurants';
$res_info  = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $res_table WHERE id = %d", $user_res_id) );
$db_tax    = isset($res_info->tax_percent) ? floatval($res_info->tax_percent) : 0;
$db_sc     = isset($res_info->service_charge_percent) ? floatval($res_info->service_charge_percent) : 0;

$item_cols = $wpdb->get_col("SHOW COLUMNS FROM $table_items_db");
$has_item_type = in_array('item_type', $item_cols);
?>

<div id="waiter-terminal-ultra">

    <div class="ultra-header">
        <div class="h-left">
            <div class="app-icon">W</div>
            <div class="brand-info">
                <h1>WAITER TERMINAL</h1>
                <div class="live-status"><span class="dot"></span> System Online</div>
            </div>
        </div>
        <div class="h-right">
            <div id="sound-btn" onclick="enableSound()" class="sound-toggle off" title="Click to enable sound">🔇</div>
            <div class="clock" id="live-clock">00:00:00</div>

            <div class="kitchen-user-nav" style="position:relative;">
                <div class="user-chip-premium" onclick="jQuery('#user-dropdown').toggle();">
                    <div class="u-avatar-premium">
                        <?php echo strtoupper(substr($current_user->display_name, 0, 1)); ?>
                    </div>
                    <div class="u-info-premium">
                        <span class="u-name"><?php echo esc_html($current_user->display_name); ?></span>
                        <small class="u-role">Waiter Staff</small>
                    </div>
                    <span class="u-arrow">▾</span>
                </div>

                <div id="user-dropdown" class="u-drop-premium">
                    <a href="?tab=profile"><span>👤</span> Profile Settings</a>
                    <a href="?tab=terminal"><span>📊</span> My Performance</a>
                    <hr style="border:0; border-top:1px solid rgba(255,255,255,0.05); margin:5px 0;">
                    <a href="<?php echo wp_logout_url(home_url('/restaurant-login/')); ?>" class="logout-link">
                        <span>👋</span> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php if ( $current_tab == 'profile' ): ?>
        <div style="max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; color: #333;">
            <?php 
            $profile_path = QRRS_PATH . 'includes/user/profile.php';
            if ( file_exists( $profile_path ) ) include $profile_path;
            ?>
        </div>
    <?php else: ?>
    <div class="ultra-stats-grid">
        <div class="u-stat-card"><div class="s-icon">🏢</div><div class="s-info"><span>Resto. Total</span><strong><?php echo intval($stats->resto_total); ?></strong></div></div>
        <div class="u-stat-card mine"><div class="s-icon">👤</div><div class="s-info"><span>My Total</span><strong><?php echo intval($stats->my_total); ?></strong></div></div>
        <div class="u-stat-card mine"><div class="s-icon">⏳</div><div class="s-info"><span>My Pending</span><strong><?php echo intval($stats->my_pending); ?></strong></div></div>
        <div class="u-stat-card"><div class="s-icon">🍳</div><div class="s-info"><span>Resto. Kitchen</span><strong><?php echo intval($stats->resto_kitchen); ?></strong></div></div>
        <div class="u-stat-card mine"><div class="s-icon">🔔</div><div class="s-info"><span>My Ready</span><strong><?php echo intval($stats->my_ready); ?></strong></div></div>
        <div class="u-stat-card mine"><div class="s-icon">🚀</div><div class="s-info"><span>My Served</span><strong><?php echo intval($stats->my_served); ?></strong></div></div>
        <div class="u-stat-card mine"><div class="s-icon">✅</div><div class="s-info"><span>My Done</span><strong><?php echo intval($stats->my_completed); ?></strong></div></div>
    </div>

    <div class="ultra-main-layout">
        

        <aside class="ultra-sidebar">
            <div class="panel-head">
                <h2>Floor Plan</h2>
                <span class="count-badge"><?php echo count($tables_data); ?> Tables</span>
            </div>
            <div class="floor-grid">
                <?php foreach ($tables_data as $table):
                    $is_occupied = !empty($table->active_order_id);
                    $w_id = intval($table->waiter_id);
                    
                    $user_label = "";
                    if ($is_occupied) {
                        if ($w_id === 0) $user_label = "Customer (QR)";
                        else if ($w_id === $current_waiter_id) $user_label = "Me";
                        else $user_label = $table->waiter_name ?: "Other Waiter";
                    }
                ?>
                <div class="floor-table <?php echo $is_occupied ? 'is-busy' : 'is-free'; ?>"
                    <?php if (!$is_occupied) echo 'onclick="openPopup(\'new\',null,\''.esc_js($table->table_name).'\')"'; ?>>
                    <div class="table-name"><?php echo esc_html($table->table_name); ?></div>
                        <div style="font-size: 9px; opacity: 0.6;">🪑 Cap: <?php echo intval($table->capacity); ?></div>

                        <?php if ($is_occupied): ?>
                            <div class="table-status" style="color:var(--wt-cyan); font-weight:bold;"><?php echo ucfirst($table->order_status); ?></div>
                            <div class="table-user" style="font-size:9px; opacity:0.8; margin-top:2px;">👤 <?php echo esc_html($user_label); ?></div>
                        <?php else: ?>
                            <div class="table-status">Vacant</div>
                            <div class="add-mark">+</div>
                        <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </aside>

        <main class="ultra-content">
            <div class="panel-head">
                <h2>Operational Tasks</h2>
                <button class="add-order-btn" onclick="openPopup('new',null,null)">+ New Service Order</button>
            </div>

            <div class="service-flow-grid">
    <?php 
    $active_exists = false;
    // এখানে ২য় কুয়েরি থেকে আসা $active_orders ব্যবহার করা হচ্ছে
    if ( ! empty( $active_orders ) ) :
        foreach ( $active_orders as $order ) :
            
            $order_id = intval($order->id); 
            
            // আইটেম চেক (আইটেম ছাড়া কার্ড দেখাবে না)
            $item_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_items_db WHERE order_id = %d", $order_id));
            if ( ! $item_count ) continue;

            $active_exists = true;
            $status    = $order->order_status;
            $is_ready  = ($status === 'ready');
            $can_edit  = ($status === 'pending');
            $can_add   = in_array($status, ['pending', 'processing', 'ready', 'served']);

            $steps     = ['pending'=>'Pending', 'processing'=>'Cooking', 'ready'=>'Ready', 'served'=>'Served'];
            $step_keys = array_keys($steps);
            $cur_idx   = array_search($status, $step_keys);
            if ($cur_idx === false) $cur_idx = 0;

            // আইটেম কুয়েরি
            $orig_items = $wpdb->get_results($wpdb->prepare(
                "SELECT item_name, quantity, COALESCE(item_status, 'pending') as item_status 
                FROM $table_items_db 
                WHERE order_id = %d AND (item_type != 'additional' OR item_type IS NULL OR item_type = '')
                ORDER BY id ASC", $order_id
            ));
            $add_items = $wpdb->get_results($wpdb->prepare(
                "SELECT item_name, quantity, COALESCE(item_status, 'pending') as item_status 
                FROM $table_items_db 
                WHERE order_id = %d AND item_type = 'additional'
                ORDER BY id ASC", $order_id
            ));
        ?>
            <div class="task-card <?php echo $is_ready ? 'is-ready-pulse' : ''; ?>">
                <div class="task-header">
                    <span class="task-table">
                        <?php echo esc_html($order->table_name); ?>
                        <?php 
                        $order_time = strtotime($order->created_at);
                        $current_time = current_time('timestamp');
                        if (($current_time - $order_time) < 300): 
                        ?>
                            <span class="new-tag-pulse" style="background:#22c55e; color:#fff; font-size:9px; padding:2px 6px; border-radius:4px; margin-left:5px;">NEW</span>
                        <?php endif; ?>
                    </span>
                    <div style="text-align:right">
                        <span class="task-id">#ORD-<?php echo $order_id; ?></span><br>
                        <span style="font-size:9px; opacity:0.7;">👤 <?php echo $order->waiter_id == 0 ? 'Customer (QR)' : esc_html($order->waiter_name); ?></span>
                    </div>
                </div>

                <div class="status-bar">
                    <?php foreach ($steps as $k => $label):
                        $idx = array_search($k, $step_keys);
                        $cls = $idx < $cur_idx ? 'done' : ($idx === $cur_idx ? 'active' : '');
                    ?>
                        <div class="sb-step <?php echo $cls; ?>">
                            <div class="sb-dot"></div>
                            <span><?php echo $label; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="task-items">
                    <?php foreach ($orig_items as $it): 
                        $is_done = in_array($it->item_status, ['ready']);
                    ?>
                        <div class="t-row <?php echo $is_done ? 'item-done' : ''; ?>">
                            <span><?php echo esc_html($it->item_name); ?></span>
                            <div style="display:flex; align-items:center; gap:5px;">
                                <?php if ($is_done): ?>
                                    <span class="item-done-badge">✓</span>
                                <?php endif; ?>
                                <b>x<?php echo intval($it->quantity); ?></b>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($add_items)): ?>
                        <div class="additional-section">
                            <div class="additional-label">➕ Additional Items</div>
                            <?php foreach ($add_items as $it): 
                                $is_done = in_array($it->item_status, ['ready', 'processing']);
                            ?>
                                <div class="t-row t-row-add <?php echo $is_done ? 'item-done' : ''; ?>">
                                    <span><?php echo esc_html($it->item_name); ?></span>
                                    <div style="display:flex; align-items:center; gap:5px;">
                                        <?php if ($is_done): ?>
                                            <span class="item-done-badge">✓</span>
                                        <?php endif; ?>
                                        <b>x<?php echo intval($it->quantity); ?></b>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="task-footer">
                    <?php 
                    /**
                     * ১. এডিট বাটন: শুধুমাত্র Pending থাকলে দেখাবে।
                     */
                    if ( $status === 'pending' ) : 
                    ?>
                        <button class="btn-edit-order" 
                            style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);"
                            onclick="handleEditClick(<?php echo $order_id; ?>, '<?php echo esc_js($order->table_name); ?>', '<?php echo $status; ?>', <?php echo $order->waiter_id; ?>)">
                            ✏️ Edit Order
                        </button>
                        <div class="pending-status-notice" style="text-align:center; color: #94a3b8; font-size: 12px; padding: 5px; width:100%;">⏳ Waiting for Kitchen...</div>
                    
                    <?php 
                    /**
                     * ২. রান্না বা তার পরের স্ট্যাটাসগুলো।
                     */
                    elseif ($status === 'processing'): ?>
                        <div class="kitchen-loader" style="width:100%; text-align:center; color: #38bdf8; font-size: 12px; padding-bottom: 10px;">👨‍🍳 Cooking in progress...</div>
                    
                    <?php elseif ($status === 'ready'): ?>
                        <button class="btn-action deliver pulse-btn" onclick="changeStatus(<?php echo $order_id; ?>, 'served')">🚀 SERVED NOW</button>
                    
                    <?php elseif ($status === 'served'): ?>
                        <button class="btn-action billing-btn" onclick="changeStatus(<?php echo $order_id; ?>, 'billing')">🧾 CLOSE ORDER → BILLING</button>
                    <?php endif; ?>

                    <?php 
                    /**
                     * ৩. অ্যাড আইটেম বাটন: শুধুমাত্র স্ট্যাটাস Pending না হলে (অর্থাৎ সরে গেলে) দেখাবে।
                     */
                    if ( $status !== 'pending' && $can_add ) : 
                    ?>
                        <button class="btn-sm btn-add-item" 
                            onclick="openPopup('add', <?php echo $order_id; ?>, '<?php echo esc_js($order->table_name); ?>')">
                            ➕ Add Items
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php 
        endforeach; 
    endif; // এখানে লুপ শেষ হচ্ছে

    if ( ! $active_exists ) echo '<div class="empty-state">No orders available.</div>'; 
    ?>
</div>
        </main>
        <?php endif; ?>
    </div>


    <!-- UNIVERSAL ORDER POPUP -->
    <div id="order-popup" class="ultra-modal" style="display:none;">
        <div class="modal-box order-modal-box">

            <!-- Step 1: Table Select -->
            <div id="popup-step-1" style="display:none;">
                <div class="modal-header-minimal">
                    <h3>Select a Vacant Table</h3>
                    <button onclick="closePopup()" class="close-x">&times;</button>
                </div>
                <div class="v-table-grid-premium">
                    <?php
                    $vacant_found = false;
                    foreach ($tables_data as $t):
                        if (!empty($t->active_order_id)) continue;
                        $vacant_found = true;
                    ?>
                    <div class="v-table-box" onclick="selectTable('<?php echo esc_js($t->table_name); ?>')">
                        <div style="font-size:28px;margin-bottom:8px;">🪑</div>
                        <strong><?php echo esc_html($t->table_name); ?></strong>
                        <span style="display:block;font-size:11px;opacity:.5;margin-top:4px;">Capacity: <?php echo intval($t->capacity); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$vacant_found) echo '<div style="grid-column:1/-1;text-align:center;padding:40px;opacity:.5;">No vacant tables right now.</div>'; ?>
                </div>
            </div>

            <!-- Step 2: Menu + Cart -->
            <div id="popup-step-2" style="display:none;height:100%;">
                <div class="order-interface">
                    <div class="order-topbar">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <button id="topbar-back-btn" onclick="goBackToStep1()" class="back-btn" style="display:none;">← Change Table</button>
                            <div class="table-pill" id="topbar-pill">Table: --</div>
                            <span id="topbar-mode-badge" class="mode-badge"></span>
                        </div>
                        <button onclick="closePopup()" class="close-x">&times;</button>
                    </div>
                    <div class="menu-layout">
                        <div class="cat-sidebar" id="cat-sidebar">
                            <div class="cat-item-w active" onclick="filterCat('all',this)">
                                <div style="font-size:22px;">🏠</div><h4>ALL</h4>
                            </div>
                        </div>
                        <div class="items-area">
                            <div class="item-grid-w" id="waiter-menu-list">
                                <div style="grid-column:1/-1;text-align:center;padding:60px;opacity:.5;">Loading Menu...</div>
                            </div>
                        </div>
                        <div class="cart-sidebar">
                            <div class="cart-title" id="cart-title">Order Details</div>
                            <div class="cart-list-w" id="waiter-cart-items">
                                <div style="text-align:center;color:#94a3b8;margin-top:40px;font-size:13px;">Your cart is empty</div>
                            </div>
                            <div class="cart-summary-w" id="waiter-cart-summary"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Variant / Confirm Modal -->
    <div id="variant-modal" class="v-modal-overlay" style="display:none;">
        <div class="v-modal-box" id="variant-modal-body"></div>
    </div>

    <!-- Toast -->
    <div id="wt-toast" class="wt-toast"></div>
    <!-- <audio id="order-notification-sound" src="<?php echo plugins_url('assets/sounds/notification_03.mp3', __FILE__); ?>" preload="auto"></audio> -->

</div>


<div id="qr-claim-modal" class="ultra-modal" style="display:none; z-index: 999999;">
    <div class="modal-box claim-modal-box">
        <div class="claim-icon-wrapper">
            <span class="material-icons-outlined">qr_code_scanner</span>
            <div class="claim-badge">?</div>
        </div>
        <h2>QR Order Ownership</h2>
        <p>This is a <strong>Customer QR Order</strong>. To edit or modify this, you must take responsibility for this table.</p>
        <div class="claim-info-note">
            <span class="material-icons-outlined">info</span>
            This order will be recorded under your name.
        </div>
        <div class="claim-actions">
            <button id="confirm-claim-btn" class="btn-confirm-claim">Yes, Claim & Edit</button>
            <button onclick="closeClaimModal()" class="btn-cancel-claim">Not Now</button>
        </div>
    </div>
</div>



<script>
(function($){
    const TAX_RATE = <?php echo floatval($db_tax); ?>;
    const SC_RATE  = <?php echo floatval($db_sc); ?>;
    const RES_ID   = <?php echo intval($user_res_id); ?>;

    let cart          = [];
    let existingItems = [];
    let allItems      = [];
    let allCategories = [];
    let currentMode      = 'new';
    let currentOrderId   = null;
    let currentTableName = '';
    let autoRefreshTimer;
    
    // --- Notification & Sound Variables ---
    let lastOrderCount = $('.task-card').length;
    let lastOrderStatuses = {};

    function syncCurrentStatuses() {
        $('.task-card').each(function(){
            let id = $(this).find('.task-id').text().trim();
            let status = $(this).find('.sb-step.active span').text().trim();
            lastOrderStatuses[id] = status;
        });
    }
    syncCurrentStatuses();

    // ====== SOUND ENGINE ======
    var _audioCtx = null;
    var _soundEnabled = (localStorage.getItem('wt_sound') === '1');

    (function(){
        var btn = document.getElementById('sound-btn');
        if (!btn) return;
        if (_soundEnabled) { btn.textContent = '🔔'; btn.className = 'sound-toggle on'; }
        else               { btn.textContent = '🔇'; btn.className = 'sound-toggle off'; }
    })();

    window.enableSound = function() {
        try {
            if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            _soundEnabled = !_soundEnabled;
            localStorage.setItem('wt_sound', _soundEnabled ? '1' : '0');
            var btn = document.getElementById('sound-btn');
            if (_soundEnabled) {
                btn.textContent = '🔔'; btn.className = 'sound-toggle on';
                _playBeeps([880], 'sine', 0.3);
                showToast('🔔 Sound enabled!', 'success');
            } else {
                btn.textContent = '🔇'; btn.className = 'sound-toggle off';
                showToast('🔇 Sound disabled', 'info');
            }
        } catch(e) { showToast('Sound not supported', 'error'); }
    };

    function _playBeeps(freqs, type, dur) {
        if (!_soundEnabled || !_audioCtx) return;
        if (_audioCtx.state === 'suspended') _audioCtx.resume();
        freqs.forEach(function(freq, i) {
            var osc  = _audioCtx.createOscillator();
            var gain = _audioCtx.createGain();
            osc.connect(gain); gain.connect(_audioCtx.destination);
            osc.type = type;
            var t = _audioCtx.currentTime + i * (dur + 0.06);
            osc.frequency.setValueAtTime(freq, t);
            gain.gain.setValueAtTime(0, t);
            gain.gain.linearRampToValueAtTime(0.35, t + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, t + dur);
            osc.start(t); osc.stop(t + dur);
        });
    }

    function playNotificationSound() { _playBeeps([880, 1100], 'sine', 0.45); }
    function playQRSound() { _playBeeps([660, 660, 880], 'square', 0.2); }
    // ====== END SOUND ENGINE ======

    // --- Background Check Logic (উন্নত ভার্সন) ---
    function checkUpdates() {
        // পপআপ খোলা থাকলে নোটিফিকেশন বন্ধ থাকবে
        if (!$('#order-popup').is(':hidden') || !$('#variant-modal').is(':hidden') || $('.v-modal-overlay').is(':visible')) return;

        $.ajax({
            url: window.location.href,
            type: 'GET',
            cache: false,
            success: function(data) {
                let $html = $(data);
                let $newTasks = $html.find('.service-flow-grid');
                let currentOrderIds = [];
                let hasReadyStatusChange = false; // স্পেশাল চেক ready এর জন্য
                let hasOtherStatusChange = false;
                let newStatuses = {};

                $newTasks.find('.task-card').each(function(){
                    let id = $(this).find('.task-id').text().trim();
                    // স্ট্যাটাস টেক্সট নিয়ে সেটাকে ছোট হাতের অক্ষরে রূপান্তর (Case-insensitive check)
                    let status = $(this).find('.sb-step.active span').text().trim().toLowerCase();
                    
                    currentOrderIds.push(id);
                    newStatuses[id] = status;

                    // যদি আগের রেকর্ডে এই আইডি থাকে এবং এখন স্ট্যাটাস বদলেছে
                    if (lastOrderStatuses[id] && lastOrderStatuses[id] !== status) {
                        // যদি নতুন স্ট্যাটাস ready হয়
                        if (status === 'ready') {
                            hasReadyStatusChange = true;
                        } else {
                            hasOtherStatusChange = true;
                        }
                    }
                });

                // নতুন অর্ডার চেক
                let hasNewOrder = currentOrderIds.some(id => !lastOrderStatuses.hasOwnProperty(id));

                if (hasNewOrder) {
                    console.log("New order detected!");
                    playQRSound(); // কিউআর সাউন্ড
                    showToast("🛎️ New Order Received!", "success");
                    setTimeout(() => { location.reload(); }, 2000);
                } 
                else if (hasReadyStatusChange) {
                    console.log("Status is READY - Playing sound now!");
                    playNotificationSound(); // রেডি সাউন্ড (সাউন্ড ইঞ্জিনের বড় ডিং)
                    showToast("✅ Order is READY to serve!", "success");
                    setTimeout(() => { location.reload(); }, 2500);
                }
                else if (hasOtherStatusChange) {
                    console.log("Other status change detected.");
                    // অন্য চেঞ্জে সাউন্ড না চাইলে এটা অফ রাখতে পারেন
                    showToast("🔄 Order Status Updated", "info");
                    setTimeout(() => { location.reload(); }, 2000);
                }

                lastOrderStatuses = newStatuses;
            }
        });
    }
    setInterval(checkUpdates, 15000);

    /* ====== Toast ====== */
    function showToast(msg, type) {
        var t = document.getElementById('wt-toast');
        t.textContent = msg;
        t.className = 'wt-toast ' + (type||'success') + ' show';
        setTimeout(function(){ t.classList.remove('show'); }, 3000);
    }

    /* ====== Clock ====== */
    setInterval(function(){
        var el = document.getElementById('live-clock');
        if (el) el.innerText = new Date().toLocaleTimeString();
    }, 1000);

    /* ====== Auto Refresh (Safety) ====== */
    function startRefresh() {
        autoRefreshTimer = setInterval(function(){
            if ($('#order-popup').is(':hidden') && $('#variant-modal').is(':hidden') && $('.v-modal-overlay').is(':hidden'))
                location.reload();
        }, 60000); 
    }
    function stopRefresh() { clearInterval(autoRefreshTimer); }
    startRefresh();

    /* ====== Open Popup ====== */
    window.openPopup = function(mode, orderId, tableName, currentStatus) {
        stopRefresh();
        currentMode      = mode;
        currentOrderId   = orderId   || null;
        currentTableName = tableName || '';
        cart = [];

        if(mode === 'edit' && currentStatus !== 'pending') {
            showToast('Cannot edit order once cooking starts!', 'error');
            return;
        }
        
        if (mode === 'new' && !tableName) {
            $('#popup-step-1').show();
            $('#popup-step-2').hide();
        } else {
            $('#popup-step-1').hide();
            setupStep2();
        }
        $('#order-popup').fadeIn('fast');
        if (allItems.length === 0) loadMenu(afterMenuLoaded);
        else afterMenuLoaded();
    };

    function setupStep2() {
        var pill    = document.getElementById('topbar-pill');
        var badge   = document.getElementById('topbar-mode-badge');
        var backBtn = document.getElementById('topbar-back-btn');

        pill.textContent = 'Table: ' + currentTableName;
        document.getElementById('cart-title').textContent =
            currentMode === 'edit' ? 'Edit Order' :
            currentMode === 'add'  ? '➕ Additional Items' : 'Order Details';

        if (currentMode === 'new') {
            pill.style.background = 'var(--primary)';
            badge.textContent = ''; badge.className = 'mode-badge';
            backBtn.style.display = 'none';
        } else if (currentMode === 'edit') {
            pill.style.background = '#f59e0b';
            badge.textContent = '✏️ Editing #ORD-' + currentOrderId;
            badge.className = 'mode-badge edit';
            backBtn.style.display = 'none';
        } else {
            pill.style.background = '#8b5cf6';
            badge.textContent = '➕ Adding to #ORD-' + currentOrderId;
            badge.className = 'mode-badge add';
            backBtn.style.display = 'none';
        }
        $('#popup-step-2').show();

        if (currentMode === 'edit') {
            $.post(qrrs_vars.ajax_url, {
                action: 'qrrs_get_order_for_edit',
                order_id: currentOrderId,
                security: qrrs_vars.nonce
            }, function(res) {
                if (res.success) { cart = res.data; renderMenuItems(allItems); renderCart(); }
            });
        }

        if (currentMode === 'add') {
            existingItems = [];
            $.post(qrrs_vars.ajax_url, {
                action: 'qrrs_get_all_order_items',
                order_id: currentOrderId,
                security: qrrs_vars.nonce
            }, function(res) {
                if (res.success) { existingItems = res.data; renderCart(); }
            });
        }
    }

    window.selectTable = function(name) {
        currentTableName = name;
        $('#popup-step-1').hide();
        setupStep2();
        renderMenuItems(allItems);
    };
    window.goBackToStep1 = function() { $('#popup-step-2').hide(); $('#popup-step-1').show(); };
    window.closePopup = function() {
        $('#order-popup').fadeOut('fast');
        cart = []; renderCart(); startRefresh();
    };

    /* ====== Load Menu ====== */
    function loadMenu(cb) {
        $('#waiter-menu-list').html('<div style="grid-column:1/-1;text-align:center;padding:60px;opacity:.5;">Loading...</div>');
        $.post(qrrs_vars.ajax_url, {
            action:'qrrs_get_waiter_menu', restaurant_id:RES_ID, security:qrrs_vars.qr_nonce
        }, function(res) {
            if (res.success) {
                allCategories = res.data.categories || [];
                allItems      = res.data.items      || [];
                if (cb) cb(); else renderMenuItems(allItems);
            } else {
                $('#waiter-menu-list').html('<div style="color:red;padding:20px;grid-column:1/-1;">'+res.data+'</div>');
            }
        });
    }
    function afterMenuLoaded() {
        renderCategorySidebar(allCategories);
        if (currentMode !== 'edit') renderMenuItems(allItems);
    }

    /* ====== Category Sidebar ====== */
    function renderCategorySidebar(cats) {
        var html = '<div class="cat-item-w active" onclick="filterCat(\'all\',this)"><div style="font-size:22px;">🏠</div><h4>ALL</h4></div>';
        cats.forEach(function(c){
            var name = c.category_name || c.name || '';
            var img  = c.image_url || c.image || '';
            html += '<div class="cat-item-w" onclick="filterCat(\''+c.id+'\',this)">'
                  + (img ? '<img src="'+img+'" class="cat-icon-w" onerror="this.src=\'https://via.placeholder.com/42\'">' : '<div style="font-size:22px;">🍽️</div>')
                  + '<h4>'+name+'</h4></div>';
        });
        $('#cat-sidebar').html(html);
    }
    window.filterCat = function(catId, el) {
        $('.cat-item-w').removeClass('active'); $(el).addClass('active');
        renderMenuItems(catId==='all' ? allItems : allItems.filter(function(i){ return String(i.category_id)===String(catId); }));
    };

    /* ====== Render Items ====== */
    function renderMenuItems(items) {
        window._menuItems = items;
        if (!items||!items.length) {
            $('#waiter-menu-list').html('<div style="grid-column:1/-1;text-align:center;padding:40px;opacity:.5;">No items.</div>');
            return;
        }
        var html = '';
        items.forEach(function(item, idx){
            var inCart = cart.find(function(x){ return x.id==item.id; });
            var qty    = inCart ? inCart.qty : 0;
            var img    = item.image_url || '';
            html += '<div class="item-card-w '+(qty>0?'selected':'')+'" id="wcard-'+item.id+'" onclick="prepareItem('+idx+')">'
                  +   '<div class="item-qty-badge-w" id="wbadge-'+item.id+'" style="'+(qty>0?'display:flex':'')+'">' + qty + '</div>'
                  +   (img ? '<img src="'+img+'" class="item-img-w" style="font-size:0" onerror="this.outerHTML=\'<div class=item-img-w>🍽️</div>\'">' : '<div class="item-img-w">🍽️</div>')
                  +   '<div class="item-body-w"><h4>'+(item.item_name||'Item')+'</h4>'
                  +   '<div class="item-bottom-w">'
                  +     '<span class="item-price-w">৳'+parseFloat(item.price).toFixed(2)+'</span>'
                  +     '<div class="item-ctrl-w" id="wctrl-'+item.id+'" style="'+(qty>0?'display:flex':'')+'" onclick="event.stopPropagation();">'
                  +       '<button class="qty-btn-w" onclick="changeQty('+item.id+',-1);event.stopPropagation();">−</button>'
                  +       '<span class="qty-num-w" id="wqnum-'+item.id+'">'+qty+'</span>'
                  +       '<button class="qty-btn-w" onclick="changeQty('+item.id+',1);event.stopPropagation();">+</button>'
                  +     '</div>'
                  +   '</div></div></div>';
        });
        $('#waiter-menu-list').html(html);
    }

    /* ====== Variants handling ====== */
    window.prepareItem = function(idx) {
        var item = window._menuItems[idx]; if (!item) return;
        var rawVar = item.variants || '', variants = [];
        try { if (typeof rawVar==='string'&&rawVar.trim()&&rawVar!=='[]') variants=JSON.parse(rawVar); else if (Array.isArray(rawVar)) variants=rawVar; } catch(e){}
        if (variants.length > 0) {
            var html = '<h3 style="margin:0 0 15px 0;">'+item.item_name+'</h3><p style="color:#64748b;font-size:14px;margin-bottom:15px;">Customize:</p>';
            variants.forEach(function(v){
                html += '<label style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:10px;cursor:pointer;">'
                      + '<input type="checkbox" class="v_opt_cb" value="'+v+'" style="width:20px;height:20px;accent-color:var(--primary);">'
                      + '<span style="font-weight:500;">'+v+'</span></label>';
            });
            html += '<button onclick="confirmVariant('+idx+')" style="width:100%;background:var(--primary);color:#fff;border:none;padding:15px;border-radius:12px;font-weight:bold;margin-top:10px;cursor:pointer;">Add to Order</button>';
            html += '<button onclick="closeVariant()" style="width:100%;background:none;border:none;margin-top:8px;color:#94a3b8;cursor:pointer;">Maybe later</button>';
            $('#variant-modal-body').html(html);
            $('#variant-modal').show();
        } else { addToCart(item, []); }
    };
    window.confirmVariant = function(idx) {
        var item = window._menuItems[idx]; var sel = [];
        document.querySelectorAll('.v_opt_cb:checked').forEach(function(cb){ sel.push(cb.value); });
        addToCart(item, sel); closeVariant();
    };
    window.closeVariant = function() { $('#variant-modal').hide(); };

    /* ====== Cart Management ====== */
    function addToCart(item, variants) {
        var key = item.id+(variants.length?'-'+variants.join('-'):'');
        var ex = cart.find(function(x){ return x.key===key; });
        if (ex) ex.qty++; else cart.push({key:key,id:item.id,name:item.item_name,price:parseFloat(item.price),variants:variants,qty:1,tax_free:parseInt(item.is_tax_free||0)});
        syncUI(item.id); renderCart();
    }
    window.changeQty = function(id, delta) {
        var it = cart.find(function(x){ return x.id==id; }); if (!it) return;
        it.qty += delta;
        if (it.qty<=0) cart = cart.filter(function(x){ return x.id!=id; });
        syncUI(id); renderCart();
    };
    function syncUI(id) {
        var inCart=cart.find(function(x){ return x.id==id; }); var qty=inCart?inCart.qty:0;
        var badge=document.getElementById('wbadge-'+id), ctrl=document.getElementById('wctrl-'+id),
            qnum=document.getElementById('wqnum-'+id), card=document.getElementById('wcard-'+id);
        if (badge){badge.innerText=qty;badge.style.display=qty>0?'flex':'none';}
        if (ctrl) ctrl.style.display=qty>0?'flex':'none';
        if (qnum) qnum.innerText=qty;
        if (card) qty>0?card.classList.add('selected'):card.classList.remove('selected');
    }

    /* ====== Render Cart ====== */
    function renderCart() {
        if (!cart.length) {
            $('#waiter-cart-items').html('<div style="text-align:center;color:#94a3b8;margin-top:40px;font-size:13px;">Your cart is empty</div>');
            $('#waiter-cart-summary').html(''); return;
        }
        var html='', sub=0, taxable=0;
        cart.forEach(function(i){
            var total=i.price*i.qty; sub+=total;
            if (!i.tax_free) taxable+=total;
            html+='<div class="cart-row-w"><div><div class="cr-name">'+i.name+'</div>'
                + '<div class="cr-sub">'+i.qty+' x ৳'+i.price.toFixed(2)+(i.variants.length?'<br>['+i.variants.join(', ')+']':'')+'</div></div>'
                + '<div class="cr-total">৳'+total.toFixed(2)+'</div></div>';
        });
        $('#waiter-cart-items').html(html);

        if (currentMode === 'add') {
            // Existing items দেখাও (read-only)
            var existingHtml = '';
            if (existingItems && existingItems.length) {
                existingHtml += '<div style="font-size:10px;color:#94a3b8;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;">📋 Existing Items</div>';
                existingItems.forEach(function(i){
                    var isAdd = (i.item_type === 'additional');
                    existingHtml += '<div class="cart-row-w" style="opacity:0.55;">'
                        + '<div><div class="cr-name" style="'+(isAdd?'color:#8b5cf6;':'')+'">'+i.name+(isAdd?' <small>[+]</small>':'')+'</div>'
                        + '<div class="cr-sub">'+i.qty+' x ৳'+parseFloat(i.price).toFixed(2)+'</div></div>'
                        + '<div class="cr-total">৳'+(i.price*i.qty).toFixed(2)+'</div></div>';
                });
                existingHtml += '<div style="border-top:2px dashed #e2e8f0;margin:10px 0;"></div>'
                            + '<div style="font-size:10px;color:#8b5cf6;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;">➕ New Items</div>';
            }

            // New items cart HTML (html variable already built above)
            $('#waiter-cart-items').html(existingHtml + html);

            // VAT/SC calculate on new items
            var taxable_new = 0;
            cart.forEach(function(i){ if(!i.tax_free) taxable_new += i.price*i.qty; });
            var addVat   = taxable_new * (TAX_RATE / 100);
            var addSc    = sub * (SC_RATE / 100);
            var addGrand = sub + addVat + addSc;

            $('#waiter-cart-summary').html(
                '<div class="sum-row"><span>New Items Subtotal</span><span>৳'+sub.toFixed(2)+'</span></div>'
            + '<div class="sum-row"><span>VAT ('+TAX_RATE+'%)</span><span>৳'+addVat.toFixed(2)+'</span></div>'
            + '<div class="sum-row"><span>S. Charge ('+SC_RATE+'%)</span><span>৳'+addSc.toFixed(2)+'</span></div>'
            + '<div class="sum-total"><span>Total to Add</span><span>৳'+addGrand.toFixed(2)+'</span></div>'
            + '<button class="place-order-btn" style="background:#8b5cf6;" onclick="submitOrder('+addGrand+','+sub+','+addVat+','+addSc+')">ADD TO ORDER ➕</button>'
            );
            return;
        }

        var vat=taxable*(TAX_RATE/100), sc=sub*(SC_RATE/100), grand=sub+vat+sc;
        var btnLabel = currentMode==='edit' ? 'SAVE CHANGES' : 'PLACE ORDER';
        var btnColor = currentMode==='edit' ? '#f59e0b'       : 'var(--primary)';
        $('#waiter-cart-summary').html(
            '<div class="sum-row"><span>Subtotal</span><span>৳'+sub.toFixed(2)+'</span></div>'
          + '<div class="sum-row"><span>VAT ('+TAX_RATE+'%)</span><span>৳'+vat.toFixed(2)+'</span></div>'
          + '<div class="sum-row"><span>S. Charge ('+SC_RATE+'%)</span><span>৳'+sc.toFixed(2)+'</span></div>'
          + '<div class="sum-total"><span>Total</span><span>৳'+grand.toFixed(2)+'</span></div>'
          + '<button class="place-order-btn" style="background:'+btnColor+';" onclick="showConfirm('+grand+','+sub+','+vat+','+sc+')">'+btnLabel+'</button>'
        );
    }

    /* ====== Confirm & Submit ====== */
    window.showConfirm = function(grand, sub, vat, sc) {
        if (!cart.length) return;
        var label = currentMode==='edit' ? 'Save Changes' : 'Confirm Order';
        var itemsHtml='';
        cart.forEach(function(i){ itemsHtml+='<div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px;border-bottom:1px solid #f1f5f9;padding-bottom:5px;"><span>'+i.qty+'x '+i.name+'</span><span style="font-weight:600;">৳'+(i.price*i.qty).toFixed(2)+'</span></div>'; });
        var html='<h3 style="margin:0 0 15px 0;border-bottom:2px solid var(--primary);padding-bottom:10px;">'+label+'</h3>'
               + '<div style="max-height:260px;overflow-y:auto;margin-bottom:15px;">'+itemsHtml+'</div>'
               + '<div style="background:#f8fafc;padding:14px;border-radius:10px;">'
               + '<div style="display:flex;justify-content:space-between;font-size:13px;"><span>Subtotal:</span><span>৳'+sub.toFixed(2)+'</span></div>'
               + '<div style="display:flex;justify-content:space-between;font-size:13px;"><span>Tax & Charges:</span><span>৳'+(vat+sc).toFixed(2)+'</span></div>'
               + '<div style="display:flex;justify-content:space-between;font-weight:bold;font-size:18px;margin-top:8px;color:var(--primary);"><span>Grand Total:</span><span>৳'+grand.toFixed(2)+'</span></div>'
               + '</div>'
               + '<button id="finalBtn" onclick="submitOrder('+grand+','+sub+','+vat+','+sc+')" style="width:100%;background:#22c55e;color:#fff;border:none;padding:14px;border-radius:12px;font-weight:bold;margin-top:14px;cursor:pointer;font-size:15px;">CONFIRM</button>'
               + '<button onclick="closeVariant()" style="width:100%;background:none;border:none;margin-top:8px;color:#94a3b8;cursor:pointer;">Cancel</button>';
        $('#variant-modal-body').html(html);
        $('#variant-modal').show();
    };

    window.submitOrder = function(grand, sub, vat, sc) {
        if (!cart.length) { showToast('Cart is empty!','error'); return; }
        var btn = document.getElementById('finalBtn') || document.querySelector('#waiter-cart-summary .place-order-btn');
        if (btn) { btn.disabled=true; btn.textContent='Sending...'; }
        
        // if (currentMode==='add') { sub=cart.reduce(function(s,i){return s+(i.price*i.qty);},0); vat=0; sc=0; grand=sub; }

        var processed = cart.map(function(i){ return {id:i.id,name:i.name,price:i.price,qty:i.qty,variants_selected:i.variants.join(', ')}; });

        $.post(qrrs_vars.ajax_url, {
            action:'qrrs_submit_waiter_order', security:qrrs_vars.nonce,
            order_mode:currentMode, order_id:currentOrderId||0,
            table_name:currentTableName, restaurant_id:RES_ID,
            items:JSON.stringify(processed),
            subtotal:sub||0, tax_amount:vat||0, service_charge:sc||0, grand_total:grand||0
        }, function(res){
            if (res.success) {
                closeVariant(); closePopup();
                showToast('✅ Done!','success');
                setTimeout(function(){ location.reload(); }, 2000);
            } else {
                showToast('Error: '+res.data,'error');
                if (btn){btn.disabled=false;btn.textContent='Retry';}
            }
        });
    };

    window.changeStatus = function(orderId, status) {
        $.post(qrrs_vars.ajax_url, {action:'qrrs_update_order_status', order_id:orderId, status:status, security:qrrs_vars.nonce},
        function(res){
            if (res.success) {
                showToast('Order ' + status, 'success');
                setTimeout(function(){ location.reload(); }, 1000);
            }
        });
    };


    window.handleEditClick = function(orderId, tableName, status, waiterId) {
        if (waiterId === 0) {
            // কাস্টম মডাল ওপেন করা
            $('#qr-claim-modal').fadeIn('fast');
            
            // কনফার্ম বাটনে ইভেন্ট সেট করা (আগের ইভেন্ট থাকলে তা ক্লিয়ার করে)
            $('#confirm-claim-btn').off('click').on('click', function() {
                $(this).prop('disabled', true).text('Processing...');
                
                claimOrder(orderId, function() {
                    $('#qr-claim-modal').fadeOut('fast');
                    openPopup('edit', orderId, tableName, status);
                    // বাটন রিসেট
                    $('#confirm-claim-btn').prop('disabled', false).text('Yes, Claim & Edit');
                });
            });
        } else {
            openPopup('edit', orderId, tableName, status);
        }
    };

    window.closeClaimModal = function() {
        $('#qr-claim-modal').fadeOut('fast');
    };

    // AJAX এর মাধ্যমে অর্ডারের মালিকানা দাবি করা
    function claimOrder(orderId, callback) {
        $.post(qrrs_vars.ajax_url, {
            action: 'qrrs_claim_qr_order',
            order_id: orderId,
            security: qrrs_vars.nonce
        }, function(res) {
            if (res.success) {
                showToast('✅ Order assigned to you!', 'success');
                if (callback) callback();
            } else {
                showToast('Error: ' + res.data, 'error');
            }
        });
    }
    

    jQuery(document).on('click', function(event) {
    if (!jQuery(event.target).closest('.kitchen-user-nav').length) {
        jQuery('#user-dropdown').hide();
    }
});

})(jQuery);
</script>

<style>
.claim-modal-box {
    max-width: 400px !important;
    text-align: center;
    padding: 40px 30px !important;
    border-radius: 24px !important;
    background: #111315 !important; /* Deeper Dark for warning contrast */
    border: 1px solid rgba(245, 158, 11, 0.3); /* Amber border */
    box-shadow: 0 0 30px rgba(0,0,0,0.5);
}

.claim-icon-wrapper {
    position: relative;
    width: 80px;
    height: 80px;
    background: rgba(245, 158, 11, 0.1); /* Amber background */
    border: 2px solid #f59e0b; /* Amber border */
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    animation: warning-pulse 2s infinite; /* Attention grabber */
}

@keyframes warning-pulse {
    0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
    70% { box-shadow: 0 0 0 15px rgba(245, 158, 11, 0); }
    100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
}

.claim-icon-wrapper .material-icons-outlined {
    font-size: 40px;
    color: #f59e0b; /* Amber color icon */
    font-weight: bold;
}

.claim-badge {
    position: absolute;
    top: 0; right: 0;
    background: #ef4444; /* Critical Red badge */
    color: #fff;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    font-size: 16px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid #111315;
}

.claim-modal-box h2 {
    color: #f59e0b; /* Amber Heading */
    font-size: 24px;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.claim-modal-box p {
    color: #cbd5e1;
    font-size: 14px;
    line-height: 1.6;
}

.claim-info-note {
    background: rgba(245, 158, 11, 0.07); /* Amber tint note */
    padding: 12px;
    border-radius: 12px;
    margin: 25px 0;
    font-size: 13px;
    color: #fbd38d; /* Light Amber text */
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px dashed rgba(245, 158, 11, 0.3);
}

.claim-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.btn-confirm-claim {
    background: #f59e0b !important; /* Warning Orange/Amber */
    color: #000 !important;
    border: none;
    padding: 16px;
    border-radius: 12px;
    font-weight: 800;
    font-size: 15px;
    cursor: pointer;
    transition: 0.3s ease;
    text-transform: uppercase;
}

.btn-confirm-claim:hover { 
    background: #d97706 !important; /* Darker amber */
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
}

.btn-cancel-claim {
    background: transparent;
    color: #94a3b8;
    border: none;
    padding: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.btn-cancel-claim:hover {
    color: #fff;
}
</style>