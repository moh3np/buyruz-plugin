<?php
/**
 * Plugin Name: RM FAQ Accordion (Rank Math)
 * Description: آکاردئون سبک و ماژولار برای FAQهای Rank Math با یک پرسش باز در هر لحظه، هماهنگ با موبایل و دسکتاپ. بارگذاری شرطی و بهینه برای سئو.
 * Version: 1.0.0
 * Author: Atlas Assistant
 * License: GPLv2 or later
 * Text Domain: rm-faq-accordion
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RFA_VERSION', '1.0.0' );
define( 'RFA_PATH', plugin_dir_path( __FILE__ ) );
define( 'RFA_URL', plugin_dir_url( __FILE__ ) );
define( 'RFA_OPTION', 'rfa_options' );

require_once RFA_PATH . 'inc/settings.php';
require_once RFA_PATH . 'inc/detector.php';
require_once RFA_PATH . 'inc/enqueue.php';

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
