<?php
function qrrs_check_access($required_role) {
    if ( ! is_user_logged_in() ) {
        wp_redirect( home_url('/login/') );
        exit;
    }

    $user = wp_get_current_user();
    if ( ! in_array($required_role, $user->roles) && ! in_array('administrator', $user->roles) ) {
        wp_die('You do not have permission to access this page.');
    }
}

