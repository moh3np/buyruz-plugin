<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_Detector {
    public static function should_load() {
        $opts = RFA_Settings::get();
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
        // Block detection
        if ( function_exists('has_block') && has_block( 'rank-math/faq-block', $post ) ) {
            return true;
        }
        // Fallback: look for class in content
        if ( strpos( $content, 'rank-math-faq' ) !== false ) {
            return true;
        }

        return false;
    }
}
