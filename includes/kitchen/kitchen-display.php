<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Kitchen Display System View
 */
function qrrs_render_kitchen_display($restaurant_id) {
    ?>
    <div class="qrrs-kitchen-container">
        <div class="kitchen-top-bar">
            <div class="header-left">
                <h1>👨‍🍳 Kitchen Console</h1>
                <span id="live-indicator" class="pulse-red">Connecting...</span>
            </div>
            <div class="header-right">
                <button id="startKitchenBtn" class="btn-start">Start Kitchen Sound</button>
                <div class="clock" id="kitchen-clock">00:00:00</div>
            </div>
        </div>

        <div id="kitchen-orders-grid" class="kitchen-kanban">
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading Active Orders...</p>
            </div>
        </div>

        <audio id="orderNotificationSound" src="<?php echo QRRS_URL . 'assets/sounds/notification.mp3'; ?>" preload="auto"></audio>
    </div>

    <style>
        .qrrs-kitchen-container { padding: 20px; background: #f0f2f5; min-height: 100vh; font-family: 'Segoe UI', Roboto, sans-serif; }
        .kitchen-top-bar { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .kitchen-top-bar h1 { margin: 0; font-size: 24px; color: #1a202c; }
        
        /* Kanban Grid */
        .kitchen-kanban { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        
        /* Order Card Styling */
        .kitchen-order-card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.07); border-top: 6px solid #e53e3e; display: flex; flex-direction: column; transition: transform 0.2s; }
        .kitchen-order-card:hover { transform: translateY(-5px); }
        .card-header { padding: 15px; background: #fff5f5; border-bottom: 1px solid #fed7d7; display: flex; justify-content: space-between; align-items: center; }
        .card-header h3 { margin: 0; font-size: 18px; color: #c53030; }
        .time-badge { font-size: 12px; background: #feb2b2; color: #9b2c2c; padding: 2px 8px; border-radius: 12px; }
        
        .card-content { padding: 15px; flex-grow: 1; }
        .item-list { list-style: none; padding: 0; margin: 0; }
        .item-list li { padding: 10px 0; border-bottom: 1px dashed #edf2f7; display: flex; align-items: flex-start; }
        .item-list li:last-child { border-bottom: none; }
        .item-qty { background: #2d3748; color: #fff; min-width: 25px; height: 25px; display: flex; align-items: center; justify-content: center; border-radius: 4px; font-size: 12px; margin-right: 12px; font-weight: bold; }
        .item-info .item-name { font-weight: 600; color: #2d3748; font-size: 16px; }
        .item-info .item-variants { display: block; font-size: 12px; color: #718096; margin-top: 4px; font-style: italic; }
        
        .card-actions { padding: 15px; background: #f8fafc; }
        .btn-complete { width: 100%; background: #38a169; color: white; border: none; padding: 12px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        .btn-complete:hover { background: #2f855a; }

        /* Animation for pulse */
        .pulse-red { color: #e53e3e; font-weight: bold; font-size: 14px; }
        .pulse-red::before { content: '●'; margin-right: 5px; }

        .loading-state { grid-column: 1 / -1; text-align: center; padding: 50px; color: #718096; }
    </style>

    <script>
        setInterval(() => {
            const now = new Date();
            document.getElementById('kitchen-clock').innerText = now.toLocaleTimeString();
        }, 1000);

        document.getElementById('startKitchenBtn').addEventListener('click', function() {
            const audio = document.getElementById('orderNotificationSound');
            audio.play().then(() => {
                audio.pause();
                this.style.background = '#38a169';
                this.innerText = '🔊 Sound Active';
            });
        });
    </script>
    <?php
}