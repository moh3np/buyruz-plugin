<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class MyPlugin_RankMath_Faq_Append {
    private static $enabled_cache = null;
    private static $id_cache      = array();
    private static $content_cache = array();
    private static $faq_cache     = array();
    private static $primed        = false;
    private static $appended_ids  = array();

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

        // اضافه کردن به the_content با اولویت پایین‌تر
        add_filter( 'the_content', array( __CLASS__, 'append_faq_to_content' ), 20 );
        
        // هوک‌های اختصاصی ووکامرس برای تب توضیحات
        add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'append_faq_to_wc_description' ), 99, 2 );
        add_filter( 'woocommerce_short_description', array( __CLASS__, 'maybe_append_faq_to_short_desc' ), 99, 1 );
        
        // هوک مستقیم روی تب توضیحات ووکامرس
        add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'modify_description_tab' ), 98 );
        
        // Fallback: اضافه کردن پس از محتوای تب توضیحات
        add_action( 'woocommerce_product_after_tabs', array( __CLASS__, 'append_faq_after_tabs' ), 5 );
        
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

    /**
     * Modify the description tab to include FAQ.
     */
    public static function modify_description_tab( $tabs ) {
        if ( ! is_singular( 'product' ) ) {
            return $tabs;
        }

        if ( isset( $tabs['description'] ) && isset( $tabs['description']['callback'] ) ) {
            $tabs['description']['callback'] = array( __CLASS__, 'description_tab_with_faq' );
        }

        return $tabs;
    }

    /**
     * Custom description tab callback that includes FAQ.
     */
    public static function description_tab_with_faq() {
        global $product;

        if ( ! $product ) {
            $product = wc_get_product( get_the_ID() );
        }

        if ( ! $product ) {
            return;
        }

        $heading = apply_filters( 'woocommerce_product_description_heading', __( 'Description', 'woocommerce' ) );

        echo '<h2>' . esc_html( $heading ) . '</h2>';

        // Get description with our FAQ appended
        $description = $product->get_description();
        
        // Apply the_content filters without adding our FAQ again (already in description)
        $description = apply_filters( 'the_content', $description );

        echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Append FAQ after tabs as a fallback.
     */
    public static function append_faq_after_tabs() {
        global $product;

        if ( ! $product ) {
            return;
        }

        $post_id = $product->get_id();

        // اگر قبلاً اضافه شده، تکرار نکن
        if ( isset( self::$appended_ids[ $post_id ] ) ) {
            return;
        }

        // فقط اگر FAQ در توضیحات نیست
        $description = $product->get_description();
        if ( stripos( $description, 'rank-math-faq' ) !== false ) {
            return;
        }

        // چک کنیم آیا FAQ برای این محصول وجود دارد
        $faq_html = self::get_faq_html( $post_id );
        if ( empty( $faq_html ) ) {
            return;
        }

        self::$appended_ids[ $post_id ] = true;
        echo '<div class="brz-faq-section">' . $faq_html . '</div>';
    }

    public static function maybe_append_faq_to_short_desc( $short_description ) {
        // برای توضیح کوتاه، FAQ اضافه نمی‌کنیم
        return $short_description;
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

        // برای ووکامرس، is_singular('product') ممکن است قبل از wp کار نکند
        if ( ! $relaxed ) {
            if ( ! is_singular( 'product' ) ) {
                return false;
            }
            if ( ! in_the_loop() || ! is_main_query() ) {
                return false;
            }
        } else {
            // در حالت relaxed، فقط چک می‌کنیم که post_type درست باشد
            $post_type = get_post_type( $post_id );
            if ( 'product' !== $post_type ) {
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

        // جلوگیری از تکرار
        if ( isset( self::$appended_ids[ $post_id ] ) ) {
            return $content;
        }

        return self::append_faq_html( $content, $post_id );
    }

    public static function append_faq_to_wc_description( $content, $product ) {
        $post_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;

        if ( ! $post_id ) {
            return $content;
        }

        // چک کنیم آیا در صفحه محصول هستیم
        if ( ! is_singular( 'product' ) && ! self::is_product_context() ) {
            return $content;
        }

        if ( ! self::should_run( $post_id, true ) ) {
            return $content;
        }

        // جلوگیری از تکرار
        if ( isset( self::$appended_ids[ $post_id ] ) ) {
            return $content;
        }

        return self::append_faq_html( $content, $post_id );
    }

    /**
     * Check if we're in a product context (even before main query).
     */
    private static function is_product_context() {
        global $product;
        if ( $product && is_a( $product, 'WC_Product' ) ) {
            return true;
        }
        
        // چک از طریق URL یا post
        $post = get_post();
        if ( $post && 'product' === $post->post_type ) {
            return true;
        }
        
        return false;
    }

    public static function prime_global_content() {
        if ( self::$primed ) {
            return;
        }

        if ( ! is_singular( 'product' ) ) {
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

        // فقط prime می‌کنیم، نه تغییر در post_content
        // تغییر مستقیم post_content می‌تواند مشکل‌ساز باشد
        self::$primed = true;
    }

    /**
     * Get FAQ HTML for a product (with caching).
     */
    public static function get_faq_html( $post_id ) {
        if ( isset( self::$faq_cache[ $post_id ] ) ) {
            return self::$faq_cache[ $post_id ];
        }

        // روش ۱: استفاده از shortcode رنک‌مث
        $snippet_id = self::extract_snippet_id( $post_id );
        if ( ! empty( $snippet_id ) ) {
            $shortcode = '[rank_math_rich_snippet id="' . sanitize_text_field( $snippet_id ) . '"]';
            $faq_html  = do_shortcode( $shortcode );

            if ( self::has_meaningful_html( $faq_html ) ) {
                self::$faq_cache[ $post_id ] = $faq_html;
                return $faq_html;
            }
        }

        // روش ۲: استخراج مستقیم از Schema DB
        $faq_html = self::render_faq_from_schema( $post_id );
        if ( ! empty( $faq_html ) ) {
            self::$faq_cache[ $post_id ] = $faq_html;
            return $faq_html;
        }

        self::$faq_cache[ $post_id ] = '';
        return '';
    }

    private static function append_faq_html( $content, $post_id ) {
        if ( isset( self::$content_cache[ $post_id ] ) ) {
            return self::$content_cache[ $post_id ];
        }

        // اگر قبلاً FAQ دارد، تغییر نده
        if ( stripos( (string) $content, '[rank_math_rich_snippet' ) !== false ) {
            self::$content_cache[ $post_id ] = $content;
            return $content;
        }

        if ( stripos( (string) $content, 'rank-math-faq' ) !== false ) {
            self::$content_cache[ $post_id ] = $content;
            return $content;
        }

        $faq_html = self::get_faq_html( $post_id );

        if ( empty( $faq_html ) ) {
            self::$content_cache[ $post_id ] = $content;
            return $content;
        }

        self::$appended_ids[ $post_id ] = true;
        self::$content_cache[ $post_id ] = $content . "\n\n" . $faq_html;

        return self::$content_cache[ $post_id ];
    }

    /**
     * Check if HTML has meaningful content.
     */
    private static function has_meaningful_html( $html ) {
        if ( ! is_string( $html ) ) {
            return false;
        }

        $stripped = trim( wp_strip_all_tags( $html ) );
        return strlen( $stripped ) > 10;
    }

    /**
     * Render FAQ directly from Rank Math schema data.
     * Fallback when shortcode doesn't work.
     */
    private static function render_faq_from_schema( $post_id ) {
        $faq_items = self::extract_faq_from_schema( $post_id );

        if ( empty( $faq_items ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="rank-math-faq brz-faq-generated">
            <?php foreach ( $faq_items as $index => $item ) : ?>
                <div class="rank-math-faq-item" id="faq-item-<?php echo esc_attr( $post_id . '-' . $index ); ?>">
                    <div class="rank-math-question"><?php echo wp_kses_post( $item['question'] ); ?></div>
                    <div class="rank-math-answer"><?php echo wp_kses_post( $item['answer'] ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Extract FAQ questions and answers from Rank Math schema.
     */
    private static function extract_faq_from_schema( $post_id ) {
        if ( ! class_exists( '\RankMath\Schema\DB' ) ) {
            return array();
        }

        $schemas = \RankMath\Schema\DB::get_schemas( $post_id );

        if ( ! is_array( $schemas ) || empty( $schemas ) ) {
            return array();
        }

        $faq_items = array();

        foreach ( $schemas as $schema_id => $schema ) {
            if ( is_object( $schema ) ) {
                $schema = (array) $schema;
            }

            if ( ! is_array( $schema ) ) {
                continue;
            }

            // چک نوع schema
            if ( ! self::is_faq_schema( $schema ) ) {
                continue;
            }

            // استخراج سوالات از mainEntity
            $main_entity = isset( $schema['mainEntity'] ) ? $schema['mainEntity'] : array();

            if ( is_object( $main_entity ) ) {
                $main_entity = (array) $main_entity;
            }

            if ( ! is_array( $main_entity ) ) {
                continue;
            }

            foreach ( $main_entity as $qa ) {
                if ( is_object( $qa ) ) {
                    $qa = (array) $qa;
                }

                if ( ! is_array( $qa ) ) {
                    continue;
                }

                // چک نوع Question
                $qa_type = isset( $qa['@type'] ) ? $qa['@type'] : '';
                if ( is_array( $qa_type ) ) {
                    $qa_type = reset( $qa_type );
                }
                if ( strtolower( (string) $qa_type ) !== 'question' ) {
                    continue;
                }

                $question = isset( $qa['name'] ) ? $qa['name'] : '';
                $answer   = '';

                // استخراج پاسخ
                if ( isset( $qa['acceptedAnswer'] ) ) {
                    $accepted = $qa['acceptedAnswer'];
                    if ( is_object( $accepted ) ) {
                        $accepted = (array) $accepted;
                    }
                    if ( is_array( $accepted ) && isset( $accepted['text'] ) ) {
                        $answer = $accepted['text'];
                    } elseif ( is_string( $accepted ) ) {
                        $answer = $accepted;
                    }
                }

                if ( ! empty( $question ) && ! empty( $answer ) ) {
                    $faq_items[] = array(
                        'question' => $question,
                        'answer'   => $answer,
                    );
                }
            }
        }

        return $faq_items;
    }

    public static function extract_snippet_id( $post_id ) {
        $post_id = absint( $post_id );

        if ( ! $post_id ) {
            return '';
        }

        if ( isset( self::$id_cache[ $post_id ] ) ) {
            return self::$id_cache[ $post_id ];
        }

        // روش ۱: استفاده از Schema DB رنک‌مث (قابل اعتمادترین)
        if ( class_exists( '\RankMath\Schema\DB' ) && method_exists( '\RankMath\Schema\DB', 'get_schemas' ) ) {
            $schemas = \RankMath\Schema\DB::get_schemas( $post_id );
            if ( is_array( $schemas ) ) {
                foreach ( $schemas as $schema_id => $schema ) {
                    if ( ! self::is_faq_schema( $schema ) ) {
                        continue;
                    }

                    // schema_id خود ID است
                    if ( ! empty( $schema_id ) && is_string( $schema_id ) ) {
                        self::$id_cache[ $post_id ] = $schema_id;
                        return $schema_id;
                    }

                    // استخراج از @id
                    $found = self::extract_id_from_schema( $schema );
                    if ( $found ) {
                        self::$id_cache[ $post_id ] = $found;
                        return $found;
                    }
                }
            }
        }

        // روش ۲: جستجو در post meta
        $keys = array(
            'rank_math_schema',
            'rank_math_rich_snippet',
            'rank_math_rich_snippet_id',
            'rank_math_schema_FAQPage',
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

    /**
     * Extract ID from @id field in schema.
     */
    private static function extract_id_from_schema( $schema ) {
        if ( is_object( $schema ) ) {
            $schema = (array) $schema;
        }

        if ( ! is_array( $schema ) ) {
            return '';
        }

        // چک @id
        if ( isset( $schema['@id'] ) && is_string( $schema['@id'] ) ) {
            // استخراج s-xxxxx از URL مانند https://site.com/product/name/#s-29a07cbb
            if ( preg_match( '/#(s-[a-zA-Z0-9-]+)/', $schema['@id'], $matches ) ) {
                return $matches[1];
            }
            // یا مستقیماً s-xxxxx
            if ( strpos( $schema['@id'], 's-' ) === 0 ) {
                return $schema['@id'];
            }
        }

        // چک metadata.shortcode
        if ( isset( $schema['metadata']['shortcode'] ) ) {
            return $schema['metadata']['shortcode'];
        }

        return '';
    }

    private static function is_faq_schema( $schema ) {
        if ( is_object( $schema ) ) {
            $schema = (array) $schema;
        }

        if ( ! is_array( $schema ) ) {
            return false;
        }

        if ( ! isset( $schema['@type'] ) ) {
            return false;
        }

        $type = $schema['@type'];

        if ( is_array( $type ) ) {
            $type = array_map( 'strtolower', $type );
            return in_array( 'faqpage', $type, true );
        }

        if ( is_string( $type ) ) {
            return strtolower( $type ) === 'faqpage';
        }

        return false;
    }

    private static function scan_for_snippet_id( $value ) {
        if ( is_string( $value ) ) {
            $value = trim( $value );
            if ( strlen( $value ) >= 5 && strpos( $value, 's-' ) === 0 ) {
                return $value;
            }
            if ( str_starts_with( $value, '#s-' ) && strlen( $value ) >= 6 ) {
                return ltrim( $value, '#' );
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
