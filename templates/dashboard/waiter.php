<?php
if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_script( 'qrrs-app-js' );

global $wpdb;

$current_user      = wp_get_current_user();
$table_tables      = $wpdb->prefix . 'qrrs_tables';
$table_orders      = $wpdb->prefix . 'qrrs_orders';
$table_items_db    = $wpdb->prefix . 'qrrs_order_items';
$user_res_id       = get_user_meta( get_current_user_id(), 'restaurant_id', true ) ?: 1;
$current_waiter_id = get_current_user_id();

$res_table = $wpdb->prefix . 'qrrs_restaurants';
$res_info  = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $res_table WHERE id = %d", $user_res_id) );
$db_tax    = isset($res_info->tax_percent)            ? floatval($res_info->tax_percent)            : 0;
$db_sc     = isset($res_info->service_charge_percent) ? floatval($res_info->service_charge_percent) : 0;

$stats = $wpdb->get_row($wpdb->prepare("
    SELECT
        COUNT(id)                                                        AS total,
        SUM(CASE WHEN order_status = 'pending'    THEN 1 ELSE 0 END)   AS pending,
        SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END)   AS kitchen,
        SUM(CASE WHEN order_status = 'ready'      THEN 1 ELSE 0 END)   AS ready,
        SUM(CASE WHEN order_status = 'served'     THEN 1 ELSE 0 END)   AS served,
        SUM(CASE WHEN order_status = 'billing'    THEN 1 ELSE 0 END)   AS billing,
        SUM(CASE WHEN order_status = 'completed'  THEN 1 ELSE 0 END)   AS completed,
        SUM(CASE WHEN waiter_id = %d              THEN 1 ELSE 0 END)   AS my_total
    FROM $table_orders
    WHERE restaurant_id = %d AND DATE(created_at) = CURDATE()
", $current_waiter_id, $user_res_id));

$tables_data = $wpdb->get_results($wpdb->prepare("
    SELECT t.*,
        (SELECT order_status FROM $table_orders
         WHERE table_name = t.table_name AND restaurant_id = %d
           AND order_status NOT IN ('completed','cancelled','billing','served')
           AND DATE(created_at) = CURDATE()
         ORDER BY id DESC LIMIT 1) AS order_status,
        (SELECT id FROM $table_orders
         WHERE table_name = t.table_name AND restaurant_id = %d
           AND order_status NOT IN ('completed','cancelled','billing','served')
           AND DATE(created_at) = CURDATE()
         ORDER BY id DESC LIMIT 1) AS active_order_id
    FROM $table_tables t
    WHERE t.restaurant_id = %d
    GROUP BY t.table_name
    ORDER BY CAST(SUBSTRING_INDEX(t.table_name,' ',-1) AS UNSIGNED) ASC
", $user_res_id, $user_res_id, $user_res_id));

// item_type column আছে কিনা check
$item_cols = $wpdb->get_col("SHOW COLUMNS FROM $table_items_db");
$has_item_type = in_array('item_type', $item_cols);
?>

<div id="waiter-terminal-ultra">

    <!-- HEADER -->
    <header class="ultra-header">
        <div class="h-left">
            <div class="app-icon">W</div>
            <div class="brand-info">
                <h1>WAITER TERMINAL</h1>
                <div class="live-status"><span class="dot"></span> Online System</div>
            </div>
        </div>
        <div class="h-right">
            <div class="clock" id="live-clock">00:00:00</div>
            <div class="user-chip" onclick="jQuery('#u-drop').toggle()">
                <div class="u-avatar"><?php echo strtoupper(substr($current_user->display_name,0,1)); ?></div>
                <span><?php echo esc_html($current_user->display_name); ?></span>
                <div id="u-drop" class="u-drop">
                    <a href="<?php echo wp_logout_url(home_url('/restaurant-login/')); ?>">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- STATS -->
    <div class="ultra-stats-grid">
        <div class="u-stat-card mine"><div class="s-icon">👤</div><div class="s-info"><span>My Orders</span><strong><?php echo intval($stats->my_total); ?></strong></div></div>
        <div class="u-stat-card"><div class="s-icon">⏳</div><div class="s-info"><span>Pending</span><strong><?php echo intval($stats->pending); ?></strong></div></div>
        <div class="u-stat-card"><div class="s-icon">📄</div><div class="s-info"><span>Total</span><strong><?php echo intval($stats->total); ?></strong></div></div>
        <div class="u-stat-card"><div class="s-icon">🍳</div><div class="s-info"><span>Kitchen</span><strong><?php echo intval($stats->kitchen); ?></strong></div></div>
        <div class="u-stat-card"><div class="s-icon">🔔</div><div class="s-info"><span>Ready</span><strong><?php echo intval($stats->ready); ?></strong></div></div>
        <div class="u-stat-card"><div class="s-icon">🚀</div><div class="s-info"><span>Served</span><strong><?php echo intval($stats->served); ?></strong></div></div>
        <div class="u-stat-card"><div class="s-icon">🧾</div><div class="s-info"><span>Billing</span><strong><?php echo intval($stats->billing); ?></strong></div></div>
        <div class="u-stat-card"><div class="s-icon">✅</div><div class="s-info"><span>Done</span><strong><?php echo intval($stats->completed); ?></strong></div></div>
    </div>

    <!-- MAIN LAYOUT -->
    <div class="ultra-main-layout">

        <aside class="ultra-sidebar">
            <div class="panel-head">
                <h2>Floor Plan</h2>
                <span class="count-badge"><?php echo count($tables_data); ?> Tables</span>
            </div>
            <div class="floor-grid">
                <?php foreach ($tables_data as $table):
                    $is_occupied = !empty($table->active_order_id);
                ?>
                <div class="floor-table <?php echo $is_occupied ? 'is-busy' : 'is-free'; ?>"
                     <?php if (!$is_occupied) echo 'onclick="openPopup(\'new\',null,\''.esc_js($table->table_name).'\')"'; ?>>
                    <div class="table-name"><?php echo esc_html($table->table_name); ?></div>
                    <div class="table-status"><?php echo $is_occupied ? ucfirst($table->order_status) : 'Free'; ?></div>
                    <?php if (!$is_occupied): ?><div class="add-mark">+</div><?php endif; ?>
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
                if ($tables_data) foreach ($tables_data as $order):
                    if (!$order->active_order_id) continue;
                    $active_exists = true;
                    $status    = $order->order_status;
                    $order_id  = intval($order->active_order_id);
                    $is_ready  = ($status === 'ready');
                    $can_edit  = ($status === 'pending');
                    $can_add   = in_array($status, ['pending','processing','ready','served']);

                    // Status bar steps
                    $steps = ['pending'=>'Pending','processing'=>'Cooking','ready'=>'Ready','served'=>'Served','billing'=>'Billing'];
                    $step_keys = array_keys($steps);
                    $cur_idx   = array_search($status, $step_keys);
                    if ($cur_idx === false) $cur_idx = 0;

                    // Original items
                    if ($has_item_type) {
                        $orig_items = $wpdb->get_results($wpdb->prepare(
                            "SELECT item_name, quantity FROM $table_items_db
                             WHERE order_id = %d AND (item_type = 'original' OR item_type IS NULL OR item_type = '')
                             ORDER BY id ASC", $order_id
                        ));
                        $add_items  = $wpdb->get_results($wpdb->prepare(
                            "SELECT item_name, quantity FROM $table_items_db
                             WHERE order_id = %d AND item_type = 'additional'
                             ORDER BY id ASC", $order_id
                        ));
                    } else {
                        $orig_items = $wpdb->get_results($wpdb->prepare(
                            "SELECT item_name, quantity FROM $table_items_db WHERE order_id = %d ORDER BY id ASC", $order_id
                        ));
                        $add_items  = [];
                    }
                ?>
                <div class="task-card <?php echo $is_ready ? 'is-ready-pulse' : ''; ?>">

                    <div class="task-header">
                        <span class="task-table"><?php echo esc_html($order->table_name); ?></span>
                        <span class="task-id">#ORD-<?php echo $order_id; ?></span>
                    </div>

                    <!-- Status Progress Bar -->
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

                    <!-- Original Items -->
                    <div class="task-items">
                        <?php foreach ($orig_items as $it): ?>
                        <div class="t-row">
                            <span><?php echo esc_html($it->item_name); ?></span>
                            <b>x<?php echo intval($it->quantity); ?></b>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Additional Items (same card, visually separated) -->
                    <?php if (!empty($add_items)): ?>
                    <div class="additional-section">
                        <div class="additional-label">➕ Additional Items</div>
                        <?php foreach ($add_items as $it): ?>
                        <div class="t-row t-row-add">
                            <span><?php echo esc_html($it->item_name); ?></span>
                            <b>x<?php echo intval($it->quantity); ?></b>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Footer Actions -->
                    <div class="task-footer">

                        <?php if ($status === 'pending'): ?>
                            <div class="kitchen-loader" style="color:#f59e0b;">⏳ Waiting for kitchen...</div>

                        <?php elseif ($status === 'processing'): ?>
                            <div class="kitchen-loader">👨‍🍳 Cooking in progress...</div>

                        <?php elseif ($status === 'ready'): ?>
                            <button class="btn-action deliver pulse-btn"
                                onclick="changeStatus(<?php echo $order_id; ?>, 'served')">
                                🚀 SERVED NOW
                            </button>

                        <?php elseif ($status === 'served'): ?>
                            <button class="btn-action billing-btn"
                                onclick="changeStatus(<?php echo $order_id; ?>, 'billing')">
                                🧾 CLOSE ORDER → BILLING
                            </button>

                        <?php endif; ?>

                        <?php if ($can_add): ?>
                            <button class="btn-sm btn-add-item"
                                onclick="openPopup('add', <?php echo $order_id; ?>, '<?php echo esc_js($order->table_name); ?>')">
                                ➕ Add Items
                            </button>
                        <?php endif; ?>

                        <?php if ($can_edit): ?>
                            <button class="btn-sm btn-edit"
                                onclick="openPopup('edit', <?php echo $order_id; ?>, '<?php echo esc_js($order->table_name); ?>')">
                                ✏️ Edit Order
                            </button>
                        <?php endif; ?>

                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$active_exists) echo '<div class="empty-state">System is idle. All orders up to date.</div>'; ?>
            </div>
        </main>
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

</div>


<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap');

#waiter-terminal-ultra {
    --primary: #f97316;
    --wt-bg:      #090b0d;
    --wt-surface: #14171b;
    --wt-border:  rgba(255,255,255,0.06);
    --wt-cyan:    #00d2d3;
    background: var(--wt-bg);
    color: #fff;
    font-family: 'Outfit', sans-serif;
    min-height: 100vh;
    padding: 20px;
    box-sizing: border-box;
}

/* Toast */
.wt-toast { position:fixed; bottom:30px; left:50%; transform:translateX(-50%) translateY(20px); background:#1e293b; color:#fff; padding:14px 28px; border-radius:50px; font-size:15px; font-weight:600; z-index:999999; opacity:0; transition:all .35s ease; pointer-events:none; box-shadow:0 8px 30px rgba(0,0,0,.35); white-space:nowrap; }
.wt-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
.wt-toast.success { background:linear-gradient(135deg,#22c55e,#16a34a); }
.wt-toast.error   { background:linear-gradient(135deg,#ef4444,#dc2626); }
.wt-toast.info    { background:linear-gradient(135deg,#3b82f6,#1d4ed8); }

/* Header */
.ultra-header { display:flex; justify-content:space-between; align-items:center; padding-bottom:20px; }
.h-left,.h-right { display:flex; align-items:center; gap:12px; }
.app-icon { background:var(--wt-cyan); color:#000; width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:20px; }
.brand-info h1 { margin:0; font-size:18px; letter-spacing:1px; }
.live-status { font-size:11px; opacity:.6; display:flex; align-items:center; gap:5px; }
.dot { width:6px; height:6px; background:#2ecc71; border-radius:50%; box-shadow:0 0 8px #2ecc71; }
.clock { background:var(--wt-surface); padding:8px 15px; border-radius:50px; border:1px solid var(--wt-border); font-size:13px; }
.user-chip { display:flex; align-items:center; gap:10px; background:var(--wt-surface); padding:5px 15px 5px 5px; border-radius:50px; cursor:pointer; position:relative; }
.u-avatar { width:32px; height:32px; background:#2c3e50; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:14px; }
.u-drop { position:absolute; top:110%; right:0; background:var(--wt-surface); border:1px solid var(--wt-border); padding:10px 15px; border-radius:10px; display:none; z-index:999; }
.u-drop a { color:#fff; text-decoration:none; font-size:13px; }

/* Stats */
.ultra-stats-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:12px; margin-bottom:25px; }
.u-stat-card { background:var(--wt-surface); padding:14px; border-radius:16px; border:1px solid var(--wt-border); display:flex; align-items:center; gap:10px; }
.u-stat-card.mine { border-color:var(--wt-cyan); background:rgba(0,210,211,0.05); }
.s-icon { font-size:18px; background:rgba(255,255,255,0.03); width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
.s-info span { font-size:10px; opacity:.5; display:block; }
.s-info strong { font-size:18px; }

/* Main */
.ultra-main-layout { display:grid; grid-template-columns:280px 1fr; gap:25px; }
.panel-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
.panel-head h2 { font-size:12px; opacity:.4; text-transform:uppercase; letter-spacing:2px; margin:0; }
.count-badge { font-size:12px; background:rgba(255,255,255,0.05); padding:4px 10px; border-radius:50px; }
.add-order-btn { background:var(--wt-cyan); color:#000; border:none; padding:10px 18px; border-radius:12px; font-weight:800; cursor:pointer; font-size:13px; }

/* Floor */
.floor-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.floor-table { background:var(--wt-surface); padding:18px 10px; border-radius:18px; border:1px solid var(--wt-border); text-align:center; cursor:pointer; transition:.3s; position:relative; }
.is-free:hover { border-color:var(--wt-cyan); transform:translateY(-2px); }
.is-busy { border-left:4px solid var(--wt-cyan); cursor:default; }
.table-name { font-weight:800; font-size:16px; }
.table-status { font-size:10px; opacity:.5; margin-top:4px; text-transform:capitalize; }
.add-mark { position:absolute; top:8px; right:10px; color:var(--wt-cyan); font-weight:900; }

/* Task Cards */
.service-flow-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:18px; }
.task-card { background:var(--wt-surface); border-radius:20px; border:1px solid var(--wt-border); padding:18px; display:flex; flex-direction:column; }
.is-ready-pulse { border-color:#10b981; box-shadow:0 0 25px rgba(16,185,129,0.2); animation:readyPulse 2s infinite; }
@keyframes readyPulse { 0%,100%{ border-color:#10b981; } 50%{ border-color:transparent; } }
.task-header { display:flex; justify-content:space-between; border-bottom:1px solid var(--wt-border); padding-bottom:10px; margin-bottom:12px; }
.task-table { background:var(--wt-cyan); color:#000; padding:3px 10px; border-radius:6px; font-weight:800; font-size:12px; }
.task-id { opacity:.4; font-size:12px; }

/* Status Progress */
.status-bar { display:flex; align-items:flex-start; margin-bottom:14px; }
.sb-step { display:flex; flex-direction:column; align-items:center; flex:1; position:relative; font-size:9px; opacity:.3; text-align:center; }
.sb-step:not(:last-child)::after { content:''; position:absolute; top:6px; left:50%; width:100%; height:2px; background:rgba(255,255,255,0.08); z-index:0; }
.sb-step.done::after,.sb-step.active::after { background:var(--wt-cyan); }
.sb-dot { width:13px; height:13px; border-radius:50%; background:rgba(255,255,255,0.1); border:2px solid rgba(255,255,255,0.15); z-index:1; margin-bottom:4px; }
.sb-step.done .sb-dot  { background:var(--wt-cyan); border-color:var(--wt-cyan); }
.sb-step.active .sb-dot{ background:var(--wt-cyan); border-color:var(--wt-cyan); box-shadow:0 0 8px var(--wt-cyan); }
.sb-step.done,.sb-step.active { opacity:1; }
.sb-step.active span { color:var(--wt-cyan); }

/* Items */
.task-items { flex:1; }
.t-row { display:flex; justify-content:space-between; font-size:13px; margin-bottom:5px; color:#bbb; }

/* Additional Items Section */
.additional-section { margin-top:10px; padding-top:10px; border-top:1px dashed rgba(139,92,246,0.4); }
.additional-label { font-size:10px; font-weight:700; color:#8b5cf6; letter-spacing:.5px; margin-bottom:6px; }
.t-row-add { color:#a78bfa; }

/* Footer */
.task-footer { margin-top:14px; display:flex; flex-direction:column; gap:8px; }
.btn-action { width:100%; padding:13px; border:none; border-radius:10px; font-weight:800; cursor:pointer; font-size:14px; }
.deliver,.pulse-btn { background:linear-gradient(135deg,#10b981,#059669); color:#fff; }
.billing-btn { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; }
.kitchen-loader { text-align:center; color:#e67e22; font-size:13px; font-style:italic; padding:8px 0; }
.btn-sm { padding:8px 14px; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; width:100%; text-align:center; }
.btn-add-item { background:rgba(139,92,246,0.12); color:#8b5cf6; border:1px solid rgba(139,92,246,0.3); }
.empty-state { grid-column:1/-1; text-align:center; padding:60px; opacity:.3; }

/* Modal */
.ultra-modal { position:fixed; inset:0; background:rgba(0,0,0,0.85); backdrop-filter:blur(8px); z-index:9999; display:flex; align-items:center; justify-content:center; }
.order-modal-box { width:96vw; max-width:1300px; height:92vh; background:#fff; border-radius:20px; overflow:hidden; display:flex; flex-direction:column; color:#1e293b; }
.modal-header-minimal { display:flex; justify-content:space-between; align-items:center; padding:20px 28px; border-bottom:1px solid #e2e8f0; }
.modal-header-minimal h3 { margin:0; font-size:20px; color:#1e293b; }
.close-x { background:none; border:none; font-size:28px; cursor:pointer; color:#94a3b8; line-height:1; padding:0; }
.close-x:hover { color:#1e293b; }
.v-table-grid-premium { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:16px; padding:28px; overflow-y:auto; }
.v-table-box { background:#f8fafc; border:1px solid #e2e8f0; padding:22px 12px; border-radius:16px; text-align:center; cursor:pointer; transition:.25s; color:#1e293b; }
.v-table-box:hover { border-color:var(--primary); background:#fff7ed; transform:translateY(-3px); }
.v-table-box strong { display:block; margin-top:4px; font-size:15px; }

/* Order Interface */
.order-interface { display:flex; flex-direction:column; height:100%; overflow:hidden; }
.order-topbar { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; flex-shrink:0; }
.table-pill { background:var(--primary); color:#fff; padding:6px 16px; border-radius:50px; font-weight:800; font-size:14px; }
.back-btn { background:#fff; border:1px solid #e2e8f0; color:#334155; padding:7px 14px; border-radius:8px; cursor:pointer; font-size:13px; }
.mode-badge { font-size:12px; padding:4px 12px; border-radius:50px; font-weight:700; }
.mode-badge.add  { background:rgba(139,92,246,0.12); color:#8b5cf6; border:1px solid rgba(139,92,246,0.3); }
.mode-badge.edit { background:rgba(251,191,36,0.12); color:#f59e0b; border:1px solid rgba(251,191,36,0.3); }

/* Menu */
.menu-layout { display:flex; flex:1; overflow:hidden; }
.cat-sidebar { width:90px; background:#fff; border-right:1px solid #e2e8f0; overflow-y:auto; text-align:center; flex-shrink:0; }
.cat-item-w { padding:14px 5px; cursor:pointer; border-bottom:1px solid #f1f5f9; transition:.2s; }
.cat-item-w.active { background:#fff7ed; border-left:4px solid var(--primary); color:var(--primary); }
.cat-item-w h4 { font-size:11px; margin:4px 0 0 0; color:inherit; font-family:'Outfit',sans-serif; }
.cat-icon-w { width:42px; height:42px; border-radius:50%; object-fit:cover; background:#eee; margin-bottom:4px; }
.items-area { flex:1; overflow-y:auto; padding:18px; background:#f8fafc; }
.item-grid-w { display:grid; gap:16px; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); align-content:start; }

/* Item Cards */
.item-card-w { background:#fff; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden; cursor:pointer; transition:.3s; position:relative; }
.item-card-w:hover { transform:translateY(-3px); box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); }
.item-card-w.selected { border-color:var(--primary); background:#fffaf5; box-shadow:0 0 0 2px var(--primary); }
.item-img-w { width:100%; height:130px; object-fit:cover; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:36px; }
.item-qty-badge-w { position:absolute; top:8px; right:8px; background:var(--primary); color:#fff; width:26px; height:26px; border-radius:50%; display:none; align-items:center; justify-content:center; font-size:12px; font-weight:bold; border:2px solid #fff; z-index:5; }
.item-body-w { padding:12px; }
.item-body-w h4 { margin:0 0 8px 0; font-size:14px; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.item-bottom-w { display:flex; justify-content:space-between; align-items:center; }
.item-price-w { font-weight:700; color:var(--primary); font-size:15px; }
.item-ctrl-w { display:none; align-items:center; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:2px; }
.qty-btn-w { background:#fff; border:1px solid #e2e8f0; width:28px; height:28px; border-radius:6px; cursor:pointer; font-weight:bold; font-size:14px; }
.qty-num-w { font-weight:bold; margin:0 8px; font-size:14px; min-width:16px; text-align:center; }

/* Cart */
.cart-sidebar { width:300px; background:#fff; border-left:1px solid #e2e8f0; display:flex; flex-direction:column; flex-shrink:0; }
.cart-title { padding:18px 20px; font-weight:700; font-size:16px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
.cart-list-w { flex:1; overflow-y:auto; padding:14px; }
.cart-row-w { display:flex; justify-content:space-between; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f1f5f9; }
.cart-row-w .cr-name { font-weight:600; font-size:13px; color:#1e293b; }
.cart-row-w .cr-sub  { font-size:11px; color:#64748b; margin-top:2px; }
.cart-row-w .cr-total{ font-weight:700; color:#1e293b; font-size:14px; white-space:nowrap; }
.cart-summary-w { padding:16px; background:#f8fafc; border-top:1px solid #e2e8f0; flex-shrink:0; }
.sum-row { display:flex; justify-content:space-between; font-size:13px; color:#64748b; margin-bottom:5px; }
.sum-total { display:flex; justify-content:space-between; font-weight:800; font-size:17px; color:#1e293b; margin-top:10px; padding-top:10px; border-top:2px dashed #e2e8f0; }
.place-order-btn { width:100%; background:var(--primary); color:#fff; border:none; padding:15px; border-radius:12px; font-weight:800; font-size:15px; cursor:pointer; margin-top:14px; }
.place-order-btn:disabled { opacity:.5; cursor:not-allowed; }

/* Variant modal */
.v-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:99999; backdrop-filter:blur(3px); }
.v-modal-box { background:#fff; width:90%; max-width:400px; border-radius:20px; padding:25px; color:#1e293b; animation:popIn .2s ease-out; max-height:90vh; overflow-y:auto; }
@keyframes popIn { from{transform:scale(.9);opacity:0} to{transform:scale(1);opacity:1} }

@media (max-width:900px) {
    .ultra-stats-grid { grid-template-columns:repeat(4,1fr); }
    .ultra-main-layout { grid-template-columns:1fr; }
    .cat-sidebar { width:100%; height:90px; display:flex; overflow-x:auto; overflow-y:hidden; border-right:none; border-bottom:1px solid #e2e8f0; }
    .menu-layout { flex-direction:column; }
    .cat-item-w { flex:0 0 auto; width:80px; }
    .cat-item-w.active { border-left:none; border-bottom:3px solid var(--primary); }
    .cart-sidebar { width:100%; height:280px; border-left:none; border-top:1px solid #e2e8f0; }
}
</style>


<script>
(function($){
    const TAX_RATE = <?php echo floatval($db_tax); ?>;
    const SC_RATE  = <?php echo floatval($db_sc); ?>;
    const RES_ID   = <?php echo intval($user_res_id); ?>;

    let cart          = [];
    let allItems      = [];
    let allCategories = [];
    let currentMode      = 'new';
    let currentOrderId   = null;
    let currentTableName = '';
    let autoRefreshTimer;

    /* ====== Toast ====== */
    function showToast(msg, type) {
        var t = document.getElementById('wt-toast');
        t.textContent = msg;
        t.className = 'wt-toast ' + (type||'success');
        t.classList.add('show');
        setTimeout(function(){ t.classList.remove('show'); }, 3000);
    }

    /* ====== Clock ====== */
    setInterval(function(){
        var el = document.getElementById('live-clock');
        if (el) el.innerText = new Date().toLocaleTimeString();
    }, 1000);

    /* ====== Auto Refresh ====== */
    function startRefresh() {
        autoRefreshTimer = setInterval(function(){
            if ($('#order-popup').is(':hidden') && $('#variant-modal').is(':hidden'))
                location.reload();
        }, 20000);
    }
    function stopRefresh() { clearInterval(autoRefreshTimer); }
    startRefresh();

    /* ====== Open Popup ====== */
    window.openPopup = function(mode, orderId, tableName) {
        stopRefresh();
        currentMode      = mode;
        currentOrderId   = orderId   || null;
        currentTableName = tableName || '';
        cart = [];

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
        }).fail(function(xhr){
            $('#waiter-menu-list').html('<div style="color:red;padding:20px;grid-column:1/-1;">AJAX Failed: '+xhr.status+'</div>');
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

    /* ====== Variants ====== */
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

    /* ====== Cart ====== */
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
            // Add mode: শুধু item total দেখাবে, tax নতুন করে add হবে না
            $('#waiter-cart-summary').html(
                '<div class="sum-total" style="font-size:15px;"><span>Items Total</span><span>৳'+sub.toFixed(2)+'</span></div>'
              + '<button class="place-order-btn" style="background:#8b5cf6;" onclick="submitOrder()">ADD TO ORDER ➕</button>'
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

        if (currentMode==='add') { sub=cart.reduce(function(s,i){return s+(i.price*i.qty);},0); vat=0; sc=0; grand=sub; }

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
                var msgs = {new:'✅ Order sent to kitchen!', edit:'✅ Order updated!', add:'✅ Items added!'};
                showToast(msgs[currentMode]||'✅ Done!','success');
                setTimeout(function(){ location.reload(); }, 2000);
            } else {
                showToast('Error: '+res.data,'error');
                if (btn){btn.disabled=false;btn.textContent='Retry';}
            }
        }).fail(function(){
            showToast('Network error.','error');
            if (btn){btn.disabled=false;btn.textContent='Retry';}
        });
    };

    /* ====== Status Change ====== */
    window.changeStatus = function(orderId, status) {
        $.post(qrrs_vars.ajax_url, {action:'qrrs_update_order_status',order_id:orderId,status:status,security:qrrs_vars.nonce},
        function(res){
            if (res.success) {
                var msgs = {served:'🚀 Marked as Served!', billing:'🧾 Order sent to Billing!'};
                showToast(msgs[status]||'✅ Updated!','success');
                setTimeout(function(){ location.reload(); },1500);
            } else showToast('Error: '+res.data,'error');
        });
    };

})(jQuery);
</script>