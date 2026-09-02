<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'qrrs_category_list', 'qrrs_render_category_list' );

function qrrs_render_category_list( $atts ) {
    $atts = shortcode_atts( [ 'restaurant_id' => 0 ], $atts, 'qrrs_category_list' );
    $restaurant_id = intval( $atts['restaurant_id'] );

    if ( ! $restaurant_id ) return '';

    global $wpdb;
    $table = $wpdb->prefix . 'qrrs_categories';

    $categories = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE restaurant_id = %d ORDER BY id ASC",
        $restaurant_id
    ) );

    if ( empty( $categories ) ) return '';

    ob_start();
    ?>


    <div class="qrrs-only-categories">
        <?php foreach ( $categories as $cat ) : 
            $img = !empty($cat->image) ? $cat->image : QRRS_URL . 'assets/images/default-cat.png';
        ?>
            <div class="qrrs-cat-item">
                <div class="qrrs-cat-thumb">
                    <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($cat->category_name); ?>">
                </div>
                <span class="qrrs-cat-name"><?php echo esc_html($cat->category_name); ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}