<?php

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'qrrs_public_menu', 'qrrs_render_public_menu' );

function qrrs_render_public_menu( $atts ) {
    $atts = shortcode_atts( [ 'restaurant_id' => 0 ], $atts, 'qrrs_public_menu' );
    $restaurant_id = intval( $atts['restaurant_id'] );

    if ( ! $restaurant_id ) {
        return '<p style="color:red; text-align:center;">Please provide a valid restaurant_id.</p>';
    }

    global $wpdb;
    $prefix = $wpdb->prefix . 'qrrs_';

   $categories = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$prefix}categories WHERE restaurant_id = %d ORDER BY id ASC",
        $restaurant_id
    ) );

   $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$prefix}items WHERE restaurant_id = %d AND is_available = 1 ORDER BY category_id ASC, id ASC",
        $restaurant_id
    ) );

    if ( empty( $items ) ) {
        return '<p style="color:#666; text-align:center; padding:50px;">No menu items found for this restaurant.</p>';
    }

    $grouped = [];
    foreach ( $items as $item ) {
        $grouped[ $item->category_id ][] = $item;
    }

    ob_start();
    ?>


    <div class="qrrs-menu-wrap" id="qrrs-menu-<?php echo $restaurant_id; ?>">

        <div class="qrrs-cat-tabs">
            <div class="qrrs-tab-btn active" data-cat="all">
                <div class="qrrs-cat-img-circle">
                    <img src="<?php echo QRRS_URL . 'assets/images/default-cat.png'; ?>" alt="All">
                </div>
                <span class="cat-name">All Items</span>
            </div>

            <?php foreach ( $categories as $cat ) : ?>
                <?php if ( isset( $grouped[ $cat->id ] ) ) : ?>
                <div class="qrrs-tab-btn" data-cat="<?php echo esc_attr( $cat->id ); ?>">
                    <div class="qrrs-cat-img-circle">
                        <?php 
                        $cat_img = !empty($cat->image) ? $cat->image : QRRS_URL . 'assets/images/default-cat.png';
                        ?>
                        <img src="<?php echo esc_url($cat_img); ?>" alt="<?php echo esc_attr($cat->category_name); ?>">
                    </div>
                    <span class="cat-name"><?php echo esc_html( $cat->category_name ); ?></span>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="qrrs-menu-grid">
            <?php foreach ( $items as $item ) : ?>
            <div class="qrrs-menu-card" data-cat="<?php echo esc_attr( $item->category_id ); ?>">
                <div class="qrrs-menu-img">
                    <?php if ( ! empty( $item->item_image ) ) : ?>
                        <img src="<?php echo esc_url( $item->item_image ); ?>" alt="<?php echo esc_attr( $item->item_name ); ?>">
                    <?php else : ?>
                        <div style="display:flex; height:100%; align-items:center; justify-content:center; font-size:40px; background:#252525;">🍽️</div>
                    <?php endif; ?>
                </div>

                <div class="qrrs-card-content">
                    <span class="qrrs-cat-badge">
                        <?php 
                        foreach($categories as $c) { if($c->id == $item->category_id) echo esc_html($c->category_name); }
                        ?>
                    </span>
                    <h3 class="qrrs-item-name"><?php echo esc_html( $item->item_name ); ?></h3>
                    <p class="qrrs-item-desc"><?php echo esc_html( $item->description ); ?></p>
                    
                    <div class="qrrs-card-footer">
                        <span class="qrrs-price">৳ <?php echo number_format( $item->price, 2 ); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="qrrs-pagination"></div>
    </div>

    <script>
    (function() {
        const itemsPerPage = 6; 
        let currentPage = 1;
        const wrap = document.getElementById('qrrs-menu-<?php echo $restaurant_id; ?>');
        if (!wrap) return;

        const cards = Array.from(wrap.querySelectorAll('.qrrs-menu-card'));
        const paginationWrap = wrap.querySelector('.qrrs-pagination');
        const tabs = wrap.querySelectorAll('.qrrs-tab-btn');

        function renderMenu(filteredCards) {
            const totalPages = Math.ceil(filteredCards.length / itemsPerPage);
            
            cards.forEach(c => c.classList.add('qrrs-hidden'));

            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            filteredCards.slice(start, end).forEach(c => c.classList.remove('qrrs-hidden'));

            updatePagination(totalPages, filteredCards);
        }

        function updatePagination(totalPages, currentFiltered) {
            paginationWrap.innerHTML = '';
            if (totalPages <= 1) return;

            for (let i = 1; i <= totalPages; i++) {
                const btn = document.createElement('button');
                btn.innerText = i;
                btn.className = 'qrrs-page-btn ' + (i === currentPage ? 'active' : '');
                btn.onclick = function() {
                    currentPage = i;
                    renderMenu(currentFiltered);
                    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                };
                paginationWrap.appendChild(btn);
            }
        }

        tabs.forEach(tab => {
            tab.onclick = function() {
                tabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                const cat = this.dataset.cat;
                currentPage = 1; 

                const filtered = cards.filter(c => cat === 'all' || c.dataset.cat === cat);
                renderMenu(filtered);
            };
        });
        
        renderMenu(cards);
    })();
    </script>

    <?php
    return ob_get_clean();
}