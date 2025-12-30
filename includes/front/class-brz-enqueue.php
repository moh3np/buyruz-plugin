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

        $table_targets = array();
        if ( isset( $opts['table_styles_targets'] ) && is_array( $opts['table_styles_targets'] ) ) {
            $table_targets = array_values( array_intersect( $opts['table_styles_targets'], array( 'product', 'page', 'category' ) ) );
        }
        $should_load_tables = ! empty( $opts['table_styles_enabled'] ) && BRZ_Detector::should_load_table_styles( $table_targets );

        // بارگذاری استایل جدول در صورت وجود جدول مقایسه حتی اگر گزینهٔ عمومی خاموش باشد
        $post_id = is_singular( 'product' ) ? get_the_ID() : 0;
        if ( $post_id && class_exists( 'BRZ_Compare_Table' ) && BRZ_Compare_Table::has_table( $post_id ) ) {
            $should_load_tables = true;
        }

        if ( ! $should_load_faq && ! $should_load_tables ) { return; }

        // JS
        if ( $should_load_faq && ! empty( $opts['enable_js'] ) ) {
            $data = array(
                'singleOpen'     => ! empty($opts['single_open']),
                'animate'        => ! empty($opts['animate']),
                'compactMobile'  => ! empty($opts['compact_mobile']),
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
            if ( ! empty( $opts['inline_css'] ) ) {
                $css = @file_get_contents( BRZ_PATH . 'assets/css/faq.css' );
                if ( $css ) {
                    // Inject brand color
                    $css = str_replace( '#BRZ_BRAND#', esc_html( $brand ), $css );
                    wp_register_style( 'brz-faq', false, array(), BRZ_VERSION );
                    wp_enqueue_style( 'brz-faq' );
                    wp_add_inline_style( 'brz-faq', $css );
                }
            } else {
                wp_register_style( 'brz-faq', BRZ_URL . 'assets/css/faq.css', array(), BRZ_VERSION );
                wp_enqueue_style( 'brz-faq' );
                // Small inline var for brand
                $inline = ':root{--brz-brand: '.$brand.';}';
                wp_add_inline_style( 'brz-faq', $inline );
            }
        }

        if ( $should_load_tables ) {
            $handle        = 'brz-table-style';
            $css_file      = BRZ_PATH . 'assets/css/table.css';
            $css_url       = BRZ_URL . 'assets/css/table.css';
            $inline_loaded = false;

            if ( ! empty( $opts['inline_css'] ) ) {
                $css = @file_get_contents( $css_file );
                if ( $css ) {
                    $inline_loaded = true;
                    wp_register_style( $handle, false, array(), BRZ_VERSION );
                    wp_enqueue_style( $handle );
                    wp_add_inline_style( $handle, $css );
                }
            }

            if ( ! $inline_loaded ) {
                wp_register_style( $handle, $css_url, array(), BRZ_VERSION );
                wp_enqueue_style( $handle );
            }
        }
    }
}
