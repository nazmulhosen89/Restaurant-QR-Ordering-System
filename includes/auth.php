<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * User Roles and Authentication Handler
 */
class QRRS_Auth {

    public function __construct() {
        // Plugin activation ba init-e roles check kora
        add_action( 'init', [ $this, 'register_custom_roles' ] );
        
        // Frontend Login Logic handle kora
        add_action( 'init', [ $this, 'handle_frontend_login' ] );

        // Staff-der jonno wp-admin dashboard access block kora
        add_action( 'admin_init', [ $this, 'block_admin_access' ] );

        // Admin bar lukonor jonno
        add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar_for_staff' ] );
    }

    /**
     * Restaurant roles create kora
     */
    public function register_custom_roles() {
        $roles = [
            'qr_manager' => [
                'name' => __( 'Restaurant Manager', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true, 'upload_files' => true ]
            ],
            'qr_waiter' => [
                'name' => __( 'Waiter', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true ]
            ],
            'qr_kitchen' => [
                'name' => __( 'Kitchen Staff', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true ]
            ]
        ];

        foreach ( $roles as $role_id => $role_data ) {
            if ( ! get_role( $role_id ) ) {
                add_role( $role_id, $role_data['name'], $role_data['caps'] );
            }
        }
    }

    /**
     * Frontend Login Logic
     */
    public function handle_frontend_login() {
        if ( isset( $_POST['qrrs_login_nonce'] ) && wp_verify_nonce( $_POST['qrrs_login_nonce'], 'qrrs_login_action' ) ) {
            
            $creds = [
                'user_login'    => sanitize_text_field( $_POST['log'] ),
                'user_password' => $_POST['pwd'],
                'remember'      => isset( $_POST['rememberme'] ) ? true : false,
            ];

            $user = wp_signon( $creds, is_ssl() );

            if ( ! is_wp_error( $user ) ) {
                $this->redirect_user_by_role( $user );
            } else {
                wp_redirect( add_query_arg( 'login', 'failed', home_url( '/restaurant-login/' ) ) );
                exit;
            }
        }
    }

    /**
     * Role onujayi specific dashboard-e pathano
     */
    public function redirect_user_by_role( $user ) {
        $user_roles = (array) $user->roles;

        if ( in_array( 'administrator', $user_roles ) || in_array( 'qr_manager', $user_roles ) ) {
            wp_redirect( home_url( '/restaurant-dashboard/' ) );
        } elseif ( in_array( 'qr_waiter', $user_roles ) ) {
            wp_redirect( home_url( '/waiter-dashboard/' ) );
        } elseif ( in_array( 'qr_kitchen', $user_roles ) ) {
            wp_redirect( home_url( '/kitchen-dashboard/' ) );
        } else {
            wp_redirect( home_url() );
        }
        exit;
    }

    /**
     * Strict Permission Check (Fixed: Auto Redirect instead of wp_die)
     */
    public static function has_permission( $required_role ) {
        // ১. প্রিন্ট ভিউ বা পিডিএফ ডাউনলোড অ্যাকশন রিকোয়েস্ট হলে ডাইরেক্ট পাস করিয়ে দেবো
        if ( isset($_GET['action']) && ($_GET['action'] === 'print' || isset($_GET['pdf'])) ) {
            return true;
        }

        // ২. লগইন না থাকলে সোজা লগইন পেজে
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/restaurant-login/' ) );
            exit;
        }

        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;

        // এডমিন হলে সব পেজেই এক্সেস পাবে
        if ( in_array( 'administrator', $user_roles ) ) {
            return true;
        }

        // ৩. কন্ডিশন চেক: ইউজারের কি এই পেজ দেখার পারমিশন আছে?
        $roles_to_check = is_array($required_role) ? $required_role : array($required_role);
        $has_access = false;
        foreach ( $roles_to_check as $role ) {
            if ( in_array( $role, $user_roles ) ) {
                $has_access = true;
                break;
            }
        }

        // যদি এক্সেস থাকে, তাহলে পেজ লোড হতে দাও
        if ( $has_access ) {
            return true;
        }

        // ৪. পারমিশন না থাকলে কুৎসিত সাদা স্ক্রিন না দেখিয়ে যার যার ড্যাশবোর্ডে রিডাইরেক্ট করুন
        if ( in_array( 'qr_waiter', $user_roles ) ) {
            wp_redirect( home_url( '/waiter-dashboard/' ) );
        } elseif ( in_array( 'qr_kitchen', $user_roles ) ) {
            wp_redirect( home_url( '/kitchen-dashboard/' ) );
        } elseif ( in_array( 'qr_manager', $user_roles ) ) {
            wp_redirect( home_url( '/restaurant-dashboard/' ) );
        } else {
            wp_redirect( home_url() ); // অন্য কোনো ইউজার হলে হোম পেজে
        }
        exit;
    }

    /**
     * Sudhu Administrator kina ta check kora
     */
    public static function is_admin_only() {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( __( 'This action is restricted to the Administrator / Restaurant only.', 'qr-restaurant-system' ) );
        }
    }

    /**
     * Staff der jonno wp-admin access block kora
     */
    public function block_admin_access() {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            if ( ! current_user_can( 'administrator' ) ) {
                wp_redirect( home_url() );
                exit;
            }
        }
    }

    public function hide_admin_bar_for_staff() {
        return current_user_can( 'administrator' );
    }
}

new QRRS_Auth();