<?php
/*
 * Plugin Name: WooCommerce Solid Payment Gateway Subscribe
 * Description: Take credit card payments on your store and subscribe to a plan.
 * Author: Codi
 * Author URI: http://david-freedman.com.ua
 * Version: 1.0.0
 * Text Domain: wc-solid
 * Requires at least: 5.6
 * Tested up to: 6.0
 * WC tested up to: 6.7
 * WC requires at least: 6.0
 * Requires PHP: 7.1
 */


define( 'WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_VERSION', '1.1.1' ); // WRCS: DEFINED_VERSION.
define( 'WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_MIN_WC_VERSION', '6.0' );
define( 'WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_MIN_WCS_VERSION', '2.0' );

/**
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'solid_gateway_subscribe_init' );

function solid_gateway_subscribe_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }
    load_plugin_textdomain( 'wc-solid', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    // Show notice for outdated version of WooCommerce.
    if ( ! class_exists( 'WooCommerce' ) || version_compare( WC()->version, WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_MIN_WC_VERSION, '<' ) ) {
        add_action( 'admin_notices', 'woocommerce_solid_subscribe_outdated_wc_notice' );
        return;
    }

    require plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
    require_once 'includes/class-wc-solid-logger.php';
    require_once 'includes/class-wc-solid-webhooks-handler.php';
    require_once 'includes/class-wc-solid-subscribe.php';
    require_once 'includes/class-wc-solid-gateway.php';
    require_once 'includes/class-wc-solid-model.php';
    require_once 'includes/class-wc-solid-payment-token.php';

    if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        add_filter('woocommerce_payment_gateways', 'solid_add_subscribe_gateway_class');
//        add_filter('woocommerce_payment_gateways', 'solid_add_gateway_class');
    }
    add_action('wp_ajax_solid_pause_subscription', [WC_Solid_Gateway_Subscribe::get_instance(), 'pause_subscription']);
    add_action('wp_ajax_nopriv_solid_pause_subscription', [WC_Solid_Gateway_Subscribe::get_instance(), 'pause_subscription']);
    add_action('wp_ajax_solid_resume_subscription', [WC_Solid_Gateway_Subscribe::get_instance(), 'resume_subscription']);
    add_action('wp_ajax_nopriv_solid_resume_subscription', [WC_Solid_Gateway_Subscribe::get_instance(), 'resume_subscription']);
    add_action('wp_ajax_solid_restore_subscription', [WC_Solid_Gateway_Subscribe::get_instance(), 'restore_subscription']);
    add_action('wp_ajax_nopriv_solid_restore_subscription', [WC_Solid_Gateway_Subscribe::get_instance(), 'restore_subscription']);
    add_action('woocommerce_subscription_status_updated', [WC_Solid_Gateway_Subscribe::get_instance(), 'send_status_change_to_gateway'], 10, 3);
}

register_activation_hook(__FILE__, function () {
    if ( ! class_exists( 'WC_Solid_Subscribe_Model' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-solid-model.php';
    }

    WC_Solid_Subscribe_Model::create_table();
});

register_activation_hook(__FILE__, function () {
    if ( ! class_exists( 'WC_Solid_Product_Model' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-solid-model.php';
    }

    WC_Solid_Product_Model::create_table();
});

register_activation_hook(__FILE__, function () {
    if ( ! class_exists( 'WC_Solid_Product_List' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-solid-model.php';
    }

    WC_Solid_Product_List::create_table();
});

/**
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
function solid_add_subscribe_gateway_class($gateways) {
    if (class_exists('WC_Solid_Gateway_Subscribe')) {
        $gateways[] = WC_Solid_Gateway_Subscribe::get_instance();
    }
    return $gateways;
}

//function solid_add_gateway_class($gateways)
//{
//    if (class_exists('WC_Solid_Gateway')) {
//        $gateways[] = 'WC_Solid_Gateway';
//    }
//    return $gateways;
//}


/**
 * Show notice for outdated version of WooCommerce Subscriptions.
 */
function woocommerce_solid_subscribe_outdated_wc_notice() {
    echo '<div class="notice notice-error"><p>';
    // translators: %s Minimum WooCommerce Subscriptions version.
    echo esc_html( sprintf('This version of WooCommerce Solid Payment Gateway Subscribe requires WooCommerce %s or newer.', WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_MIN_WC_VERSION ) );
    echo '</p></div>';
}
