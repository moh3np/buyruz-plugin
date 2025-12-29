<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class MyPlugin_RankMath_Faq_Append {
    private static $enabled_cache = null;
    private static $id_cache      = array();
    private static $content_cache = array();
    private static $primed        = false;

    public static function init() {
        if ( ! self::is_enabled() ) {
            return;
        }

        if ( self::is_blocked_request() ) {
            return;
        }

        if ( ! self::rankmath_active() ) {
            return;
        }

        add_filter( 'the_content', array( __CLASS__, 'append_faq_to_content' ), 20 );
        add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'append_faq_to_wc_description' ), 19, 2 );
        add_action( 'wp', array( __CLASS__, 'prime_global_content' ) );
    }

    public static function is_enabled() {
        if ( null !== self::$enabled_cache ) {
            return self::$enabled_cache;
        }

        $flag = get_option( 'myplugin_enable_rankmath_faq_append', 0 );

        self::$enabled_cache = ! empty( $flag );

        return self::$enabled_cache;
    }

    public static function should_run( $post_id, $relaxed = false ) {
        if ( ! self::is_enabled() ) {
            return false;
        }

        if ( self::is_blocked_request() ) {
            return false;
        }

        if ( ! self::rankmath_active() ) {
            return false;
        }

        if ( ! is_singular( 'product' ) ) {
            return false;
        }

        if ( ! $relaxed ) {
            if ( ! in_the_loop() || ! is_main_query() ) {
                return false;
            }
        }

        $post_id = absint( $post_id );

        return $post_id > 0;
    }

    public static function append_faq_to_content( $content ) {
        global $post;

        $post_id = $post ? $post->ID : 0;

        if ( ! self::should_run( $post_id ) ) {
            return $content;
        }

        return self::append_faq_html( $content, $post_id );
    }

    public static function append_faq_to_wc_description( $content, $product ) {
        $post_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;

        if ( ! self::should_run( $post_id, true ) ) {
            return $content;
        }

        return self::append_faq_html( $content, $post_id );
    }

    public static function prime_global_content() {
        if ( self::$primed ) {
            return;
        }

        $post = get_post();
        if ( ! $post ) {
            return;
        }

        $post_id = $post->ID;

        if ( ! self::should_run( $post_id, true ) ) {
            return;
        }

        $post->post_content = self::append_faq_html( $post->post_content, $post_id );

        self::$primed = true;
    }

    private static function append_faq_html( $content, $post_id ) {
        if ( isset( self::$content_cache[ $post_id ] ) ) {
            return self::$content_cache[ $post_id ];
        }

        if ( stripos( (string) $content, '[rank_math_rich_snippet' ) !== false ) {
            self::$content_cache[ $post_id ] = $content;
            return $content;
        }

        $snippet_id = self::extract_snippet_id( $post_id );

        if ( empty( $snippet_id ) ) {
            self::$content_cache[ $post_id ] = $content;
            return $content;
        }

        $shortcode = '[rank_math_rich_snippet id="' . sanitize_text_field( $snippet_id ) . '"]';
        $faq_html  = do_shortcode( $shortcode );

        $has_meaningful_html = is_string( $faq_html ) && strlen( trim( wp_strip_all_tags( $faq_html ) ) ) > 10;

        if ( ! $has_meaningful_html ) {
            self::$content_cache[ $post_id ] = $content;
            return $content;
        }

        self::$content_cache[ $post_id ] = $content . "\n\n" . $faq_html;

        return self::$content_cache[ $post_id ];
    }

    public static function extract_snippet_id( $post_id ) {
        $post_id = absint( $post_id );

        if ( ! $post_id ) {
            return '';
        }

        if ( isset( self::$id_cache[ $post_id ] ) ) {
            return self::$id_cache[ $post_id ];
        }

        $keys = array(
            'rank_math_schema',
            'rank_math_rich_snippet',
            'rank_math_rich_snippet_id',
        );

        foreach ( $keys as $key ) {
            $raw = get_post_meta( $post_id, $key, true );

            if ( empty( $raw ) ) {
                continue;
            }

            $value = maybe_unserialize( $raw );

            if ( is_string( $value ) && self::looks_like_json( $value ) ) {
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) ) {
                    $value = $decoded;
                }
            }

            $found = self::scan_for_snippet_id( $value );

            if ( $found ) {
                self::$id_cache[ $post_id ] = $found;
                return $found;
            }
        }

        self::$id_cache[ $post_id ] = '';

        return '';
    }

    private static function scan_for_snippet_id( $value ) {
        if ( is_string( $value ) ) {
            $value = trim( $value );
            if ( strlen( $value ) >= 10 && strpos( $value, 's-' ) === 0 ) {
                return $value;
            }
            return '';
        }

        if ( is_object( $value ) ) {
            $value = (array) $value;
        }

        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                $found = self::scan_for_snippet_id( $item );
                if ( $found ) {
                    return $found;
                }
            }
        }

        return '';
    }

    private static function looks_like_json( $value ) {
        if ( ! is_string( $value ) ) {
            return false;
        }

        $value = trim( $value );
        if ( strlen( $value ) < 2 ) {
            return false;
        }

        $first = $value[0];
        $last  = substr( $value, -1 );

        return ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last );
    }

    private static function is_blocked_request() {
        if ( is_admin() ) {
            return true;
        }

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return true;
        }

        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return true;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }

        return false;
    }

    private static function rankmath_active() {
        if ( class_exists( '\RankMath\Schema\DB' ) ) {
            return true;
        }

        return defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath\Helper' );
    }
}
