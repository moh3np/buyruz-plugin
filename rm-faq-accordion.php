<?php
/**
 * Plugin Name: تنظیمات بایروز
 * Plugin URI: https://github.com/Codruz/buyruz-plugin.git
 * Description: تنظیمات بایروز، مرکز مدیریت و هماهنگ‌سازی قابلیت‌ها و تنظیمات اختصاصی بایروز در سایت شماست. از این صفحه می‌توانید رفتار افزونه‌های بایروز را یکپارچه کنترل کنید.
 * Version: 1.3.11
 * Author: کُدروز
 * Author URI: https://codruz.ir
 * License: Proprietary
 * Text Domain: rm-faq-accordion
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$plugin_header = get_file_data(
    __FILE__,
    array(
        'Version' => 'Version',
    )
);
define( 'RFA_VERSION', isset( $plugin_header['Version'] ) ? $plugin_header['Version'] : '0.0.0' );
define( 'RFA_PATH', plugin_dir_path( __FILE__ ) );
define( 'RFA_URL', plugin_dir_url( __FILE__ ) );
define( 'RFA_OPTION', 'rfa_options' );
define( 'RFA_LOG_DIR', trailingslashit( RFA_PATH ) . 'logs/' );

require_once RFA_PATH . 'inc/debug.php';
require_once RFA_PATH . 'inc/settings.php';
require_once RFA_PATH . 'inc/detector.php';
require_once RFA_PATH . 'inc/enqueue.php';
require_once RFA_PATH . 'inc/last-updated.php';
require_once RFA_PATH . 'inc/integration-rankmath.php';

RFA_Debug::init();
RFA_Last_Updated::init();

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
