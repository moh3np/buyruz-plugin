<?php
/**
 * Plugin Name: تنظیمات بایروز
 * Plugin URI: https://github.com/Codruz/buyruz-plugin.git
 * Description: تنظیمات بایروز، مرکز مدیریت امکانات اختصاصی افزونه شامل آکاردئون FAQ و سایر قابلیت‌های سفارشی. از اینجا می‌توانید رفتار افزونه‌های بایروز را تنظیم کنید.
 * Version: 1.3.1
 * Author: کُدروز
 * Author URI: https://codruz.ir
 * License: Proprietary
 * Text Domain: rm-faq-accordion
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RFA_VERSION', '1.3.1' );
define( 'RFA_PATH', plugin_dir_path( __FILE__ ) );
define( 'RFA_URL', plugin_dir_url( __FILE__ ) );
define( 'RFA_OPTION', 'rfa_options' );

require_once RFA_PATH . 'inc/settings.php';
require_once RFA_PATH . 'inc/detector.php';
require_once RFA_PATH . 'inc/enqueue.php';
require_once RFA_PATH . 'inc/updater.php';
require_once RFA_PATH . 'inc/integration-rankmath.php';

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $links['settings'] = '<a href="' . esc_url( admin_url( 'admin.php?page=rfa-settings' ) ) . '">تنظیمات</a>';
    return $links;
} );

/**
 * Defaults on activation
 */
register_activation_hook( __FILE__, function(){
    $defaults = array(
        'enable_css'      => 1,
        'inline_css'      => 1,
        'brand_color'     => '#ff5668',
        'enable_js'       => 1,
        'single_open'     => 1,
        'animate'         => 1,
        'compact_mobile'  => 1,
        'load_strategy'   => 'auto', // auto | all | selector
        'custom_selector' => '.rank-math-faq',
    );
    $current = get_option( RFA_OPTION, array() );
    update_option( RFA_OPTION, wp_parse_args( $current, $defaults ), false );
});

/**
 * Clean settings on uninstall from uninstall.php
 */
