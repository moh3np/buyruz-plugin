<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Detector {
    public static function should_load() {
        if ( is_admin() ) { return false; }

        if ( ! is_singular() ) { return false; }

        global $post;
        if ( ! $post ) { return false; }
        $content = $post->post_content;

        // Shortcode detection
        if ( function_exists('has_shortcode') && has_shortcode( $content, 'rank_math_faq' ) ) {
            return true;
        }
        if ( function_exists('has_shortcode') && has_shortcode( $content, 'rank_math_rich_snippet' ) ) {
            return true;
        }
        // Block detection
        if ( function_exists('has_block') && has_block( 'rank-math/faq-block', $post ) ) {
            return true;
        }
        // Fallback: look for class in content
        if ( strpos( $content, 'rank-math-faq' ) !== false ) {
            return true;
        }
        if ( strpos( $content, 'rank-math-rich-snippet' ) !== false ) {
            return true;
        }
        if ( strpos( $content, 'rank_math_rich_snippet' ) !== false ) {
            return true;
        }

        if ( class_exists( '\RankMath\Schema\DB' ) ) {
            $schemas = \RankMath\Schema\DB::get_schemas( $post->ID );
            if ( is_array( $schemas ) ) {
                foreach ( $schemas as $schema ) {
                    if ( isset( $schema['@type'] ) && strtolower( $schema['@type'] ) === 'faqpage' ) {
                        return true;
                    }
                    if ( isset( $schema['@type'] ) && is_array( $schema['@type'] ) && in_array( 'FAQPage', $schema['@type'], true ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function should_load_table_styles( array $targets ) {
        if ( is_admin() ) { return false; }

        if ( empty( $targets ) ) { return false; }

        $targets = array_unique( $targets );

        $is_product  = ( function_exists( 'is_product' ) && is_product() ) || is_singular( 'product' );
        $is_page     = is_page();
        $is_category = is_category() || ( function_exists( 'is_product_category' ) && is_product_category() );

        if ( $is_product && in_array( 'product', $targets, true ) ) {
            return true;
        }

        if ( $is_page && in_array( 'page', $targets, true ) ) {
            return true;
        }

        if ( $is_category && in_array( 'category', $targets, true ) ) {
            return true;
        }

        return false;
    }
}
