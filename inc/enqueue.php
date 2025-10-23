<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_Enqueue {
    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend' ) );
    }

    public static function frontend() {
        if ( ! RFA_Detector::should_load() ) { return; }

        $opts = RFA_Settings::get();

        // JS
        if ( ! empty( $opts['enable_js'] ) ) {
            $data = array(
                'singleOpen'     => ! empty($opts['single_open']),
                'animate'        => ! empty($opts['animate']),
                'compactMobile'  => ! empty($opts['compact_mobile']),
                'selector'       => isset($opts['custom_selector']) ? $opts['custom_selector'] : '.rank-math-faq',
                'strategy'       => isset($opts['load_strategy']) ? $opts['load_strategy'] : 'auto',
            );
            wp_register_script(
                'rfa-faq',
                RFA_URL . 'assets/js/faq.js',
                array(),
                RFA_VERSION,
                array( 'in_footer' => true, 'strategy' => 'defer' )
            );
            wp_add_inline_script( 'rfa-faq', 'window.RFA='.wp_json_encode($data).';', 'before' );
            wp_enqueue_script( 'rfa-faq' );
        }

        // CSS
        if ( ! empty( $opts['enable_css'] ) ) {
            $brand = isset($opts['brand_color']) ? $opts['brand_color'] : '#ff5668';
            if ( ! empty( $opts['inline_css'] ) ) {
                $css = @file_get_contents( RFA_PATH . 'assets/css/faq.css' );
                if ( $css ) {
                    // Inject brand color
                    $css = str_replace( '#RFA_BRAND#', esc_html( $brand ), $css );
                    wp_register_style( 'rfa-faq', false, array(), RFA_VERSION );
                    wp_enqueue_style( 'rfa-faq' );
                    wp_add_inline_style( 'rfa-faq', $css );
                }
            } else {
                wp_register_style( 'rfa-faq', RFA_URL . 'assets/css/faq.css', array(), RFA_VERSION );
                wp_enqueue_style( 'rfa-faq' );
                // Small inline var for brand
                $inline = ':root{--rfa-brand: '.$brand.';}';
                wp_add_inline_style( 'rfa-faq', $inline );
            }
        }
    }
}
RFA_Enqueue::init();
