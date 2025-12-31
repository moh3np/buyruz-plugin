<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Enqueue {
    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend' ) );
    }

    public static function frontend() {
        $opts = BRZ_Settings::get();
        $should_load_faq = BRZ_Detector::should_load();

        if ( ! $should_load_faq ) { return; }

        // JS
        if ( $should_load_faq && ! empty( $opts['enable_js'] ) ) {
            $data = array(
                'singleOpen'     => ! empty($opts['single_open']),
                'animate'        => ! empty($opts['animate']),
                'selector'       => '.rank-math-faq',
            );
            wp_register_script(
                'brz-faq',
                BRZ_URL . 'assets/js/faq.js',
                array(),
                BRZ_VERSION,
                array( 'in_footer' => true, 'strategy' => 'defer' )
            );
            wp_add_inline_script( 'brz-faq', 'window.BRZ='.wp_json_encode($data).';', 'before' );
            wp_enqueue_script( 'brz-faq' );
        }

        // CSS
        if ( $should_load_faq && ! empty( $opts['enable_css'] ) ) {
            $brand = isset($opts['brand_color']) ? $opts['brand_color'] : '#ff5668';
            $css_vars = ':root{--brz-brand: '.$brand.';}';

            if ( ! empty( $opts['inline_css'] ) ) {
                $css = @file_get_contents( BRZ_PATH . 'assets/css/faq.css' );
                if ( $css ) {
                    wp_register_style( 'brz-faq', false, array(), BRZ_VERSION );
                    wp_enqueue_style( 'brz-faq' );
                    wp_add_inline_style( 'brz-faq', $css_vars . $css );
                } else {
                    // Fallback if file read fails
                    wp_register_style( 'brz-faq', BRZ_URL . 'assets/css/faq.css', array(), BRZ_VERSION );
                    wp_enqueue_style( 'brz-faq' );
                    wp_add_inline_style( 'brz-faq', $css_vars );
                }
            } else {
                wp_register_style( 'brz-faq', BRZ_URL . 'assets/css/faq.css', array(), BRZ_VERSION );
                wp_enqueue_style( 'brz-faq' );
                wp_add_inline_style( 'brz-faq', $css_vars );
            }
        }
    }
}
