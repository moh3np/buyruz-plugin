<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Detector {
    public static function should_load() {
        $opts = BRZ_Settings::get();
        $strategy = isset($opts['load_strategy']) ? $opts['load_strategy'] : 'auto';

        if ( $strategy === 'all' ) { return true; }

        if ( is_admin() ) { return false; }

        if ( ! is_singular() ) { return false; }

        global $post;
        if ( ! $post ) { return false; }
        $content = $post->post_content;

        if ( $strategy === 'selector' ) {
            $sel = isset($opts['custom_selector']) ? (string) $opts['custom_selector'] : '.rank-math-faq';
            // Best effort: remove CSS punctuation to extract a keyword to search for in HTML/class attributes.
            $needle = trim( str_replace( array('.', '#'), ' ', $sel ) );
            $needle = preg_replace( '/\s+/', ' ', $needle );
            $needle = explode(' ', $needle);
            $needle = $needle ? $needle[0] : 'rank-math-faq';
            if ( stripos( $content, $needle ) !== false ) { return true; }
            // also accept default RM markers
            if ( stripos( $content, 'rank-math-faq' ) !== false ) { return true; }
            if ( function_exists('has_shortcode') && has_shortcode( $content, 'rank_math_faq' ) ) { return true; }
            if ( function_exists('has_block') && has_block( 'rank-math/faq-block', $post ) ) { return true; }
            return false;
        }

        // auto
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
}
