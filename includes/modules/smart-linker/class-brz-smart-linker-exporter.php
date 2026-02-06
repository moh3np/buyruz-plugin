<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Smart Linker Exporter - Generates JSON export and AI prompts.
 *
 * Exports unified content from both sites for AI analysis.
 */
class BRZ_Smart_Linker_Exporter {

    /**
     * Generate unified JSON export for AI consumption.
     * Automatically fetches from both local and peer sites.
     * Uses raw peer API items directly (bypasses DB) for reliability.
     * Products/posts/pages: only linkable items included.
     * Terms (categories/tags): all included (AI uses is_linkable flag).
     *
     * @return array
     */
    public static function generate_export() {
        // Step 1: Refresh local index
        BRZ_Smart_Linker_Sync::refresh_local_index();

        // Step 2: Fetch from peer site (stores in DB + returns raw items)
        $peer_result = BRZ_Smart_Linker_Sync::fetch_peer_and_merge();
        $peer_warning = isset( $peer_result['warning'] ) ? $peer_result['warning'] : null;
        $peer_count   = isset( $peer_result['count'] ) ? $peer_result['count'] : 0;
        $peer_items   = isset( $peer_result['items'] ) ? $peer_result['items'] : array();

        // Step 3: Get local content from DB (all items, just refreshed in step 1)
        $local_content = BRZ_Smart_Linker_DB::get_content_index( 'local' );
        $local_count   = count( $local_content );

        // Fallback: If local content_index is empty, get from WordPress directly
        if ( empty( $local_content ) ) {
            $local_content = self::get_local_content_fallback();
            $local_count   = count( $local_content );
        }

        // Step 4: Combine local content + raw peer items (bypass DB for peer reliability)
        $all_content = array_merge( $local_content, $peer_items );

        // Organize by type
        $export = array(
            'meta' => array(
                'exported_at'    => current_time( 'c' ),
                'plugin_version' => defined( 'BRZ_VERSION' ) ? BRZ_VERSION : '1.0.0',
                'site_url'       => home_url(),
                'total_items'    => 0,
                'local_count'    => $local_count,
                'peer_count'     => $peer_count,
                'warning'        => $peer_warning,
            ),
            'products'           => array(),
            'posts'              => array(),
            'pages'              => array(),
            'product_categories' => array(),
            'tags'               => array(),
        );

        foreach ( $all_content as $item ) {
            $formatted   = self::format_item_for_export( $item );
            $is_linkable = isset( $item['is_linkable'] ) ? (int) $item['is_linkable'] : 1;
            $post_type   = isset( $item['post_type'] ) ? $item['post_type'] : '';

            switch ( $post_type ) {
                case 'product':
                    if ( $is_linkable ) {
                        $export['products'][] = $formatted;
                    }
                    break;
                case 'post':
                    if ( $is_linkable ) {
                        $export['posts'][] = $formatted;
                    }
                    break;
                case 'page':
                    if ( $is_linkable ) {
                        $export['pages'][] = $formatted;
                    }
                    break;
                case 'term_product_cat':
                    $export['product_categories'][] = $formatted;
                    break;
                case 'term_product_tag':
                    $export['tags'][] = $formatted;
                    break;
            }
        }

        // Fallback: ensure taxonomy terms if still missing
        self::ensure_taxonomy_terms( $export );

        // Update counts
        $export['meta']['counts'] = array(
            'products'           => count( $export['products'] ),
            'posts'              => count( $export['posts'] ),
            'pages'              => count( $export['pages'] ),
            'product_categories' => count( $export['product_categories'] ),
            'tags'               => count( $export['tags'] ),
        );

        $export['meta']['total_items'] = array_sum( $export['meta']['counts'] );

        return $export;
    }

    /**
     * Ensure taxonomy terms are present in export.
     * First tries local WordPress taxonomy functions, then falls back to content_index DB
     * (for peer terms when taxonomy doesn't exist locally, e.g. product_cat on blog site).
     *
     * @param array &$export Export data array (modified by reference)
     */
    private static function ensure_taxonomy_terms( array &$export ) {
        // Detect if product tags already exist in export
        $has_product_tag = false;
        foreach ( $export['tags'] as $tag ) {
            $type = isset( $tag['type'] ) ? $tag['type'] : '';
            if ( 'term_product_tag' === $type ) {
                $has_product_tag = true;
                break;
            }
        }

        // Product categories fallback
        if ( empty( $export['product_categories'] ) ) {
            if ( taxonomy_exists( 'product_cat' ) ) {
                $product_cats = get_terms( array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => true,
                ) );
                if ( ! is_wp_error( $product_cats ) && ! empty( $product_cats ) ) {
                    foreach ( $product_cats as $term ) {
                        $formatted = self::format_term_for_export( $term, 'term_product_cat' );
                        if ( $formatted ) {
                            $export['product_categories'][] = $formatted;
                        }
                    }
                }
            } else {
                $db_terms = BRZ_Smart_Linker_DB::get_content_index( null, false, 'term_product_cat' );
                foreach ( $db_terms as $item ) {
                    $export['product_categories'][] = self::format_item_for_export( $item );
                }
            }
        }

        // Product tags fallback (checked independently)
        if ( ! $has_product_tag ) {
            if ( taxonomy_exists( 'product_tag' ) ) {
                $product_tags = get_terms( array(
                    'taxonomy'   => 'product_tag',
                    'hide_empty' => true,
                ) );
                if ( ! is_wp_error( $product_tags ) && ! empty( $product_tags ) ) {
                    foreach ( $product_tags as $term ) {
                        $formatted = self::format_term_for_export( $term, 'term_product_tag' );
                        if ( $formatted ) {
                            $export['tags'][] = $formatted;
                        }
                    }
                }
            } else {
                $db_tags = BRZ_Smart_Linker_DB::get_content_index( null, false, 'term_product_tag' );
                foreach ( $db_tags as $item ) {
                    $export['tags'][] = self::format_item_for_export( $item );
                }
            }
        }


    }

    /**
     * Format a WP_Term directly for export (bypassing the DB).
     *
     * @param WP_Term $term
     * @param string  $type The post_type value (e.g. 'term_product_cat')
     * @return array|null
     */
    private static function format_term_for_export( $term, $type ) {
        $url = get_term_link( $term );
        if ( is_wp_error( $url ) ) {
            return null;
        }

        // Check RankMath noindex
        $is_linkable = true;
        if ( class_exists( 'RankMath' ) ) {
            $robots = get_term_meta( $term->term_id, 'rank_math_robots', true );
            if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
                $is_linkable = false;
            }
        }

        // Get focus keyword from RankMath if available
        $focus_keyword = '';
        if ( class_exists( 'RankMath' ) ) {
            $focus_keyword = get_term_meta( $term->term_id, 'rank_math_focus_keyword', true );
            if ( ! is_string( $focus_keyword ) ) {
                $focus_keyword = '';
            }
        }

        return array(
            'id'                 => (int) $term->term_id,
            'site'               => 'local',
            'type'               => $type,
            'title'              => $term->name,
            'url'                => $url,
            'categories'         => array( $term->name ),
            'focus_keyword'      => $focus_keyword,
            'secondary_keywords' => array(),
            'word_count'         => str_word_count( wp_strip_all_tags( $term->description ) ),
            'is_linkable'        => $is_linkable,
            'stock_status'       => '',
            'price'              => '',
            'excerpt'            => mb_substr( wp_strip_all_tags( $term->description ), 0, 1000, 'UTF-8' ),
        );
    }
    
    /**
     * Fallback: Get local content directly from WordPress when content_index table is empty.
     *
     * @return array
     */
    private static function get_local_content_fallback() {
        $settings = BRZ_Smart_Linker::get_settings();
        $site_role = isset( $settings['site_role'] ) ? $settings['site_role'] : 'shop';
        
        $post_types = array( 'post', 'page' );
        if ( 'shop' === $site_role && post_type_exists( 'product' ) ) {
            $post_types[] = 'product';
        }
        
        $posts = get_posts( array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ) );
        
        $items = array();
        foreach ( $posts as $post ) {
            $items[] = BRZ_Smart_Linker_Sync::build_content_data( $post, $site_role );
        }
        
        return $items;
    }

    /**
     * Format a content item for export.
     *
     * @param array $item
     * @return array
     */
    private static function format_item_for_export( array $item ) {
        $categories = $item['category_names'];
        if ( is_string( $categories ) ) {
            $categories = json_decode( $categories, true );
        }

        $secondary = $item['secondary_keywords'];
        if ( is_string( $secondary ) ) {
            $secondary = json_decode( $secondary, true );
        }

        return array(
            'id'                 => (int) $item['post_id'],
            'site'               => $item['site_id'],
            'type'               => $item['post_type'],
            'title'              => $item['title'],
            'url'                => $item['url'],
            'categories'         => is_array( $categories ) ? $categories : array(),
            'focus_keyword'      => $item['focus_keyword'],
            'secondary_keywords' => is_array( $secondary ) ? $secondary : array(),
            'word_count'         => (int) $item['word_count'],
            'is_linkable'        => (bool) $item['is_linkable'],
            'stock_status'       => $item['stock_status'],
            'price'              => $item['price'],
            'excerpt'            => $item['content_excerpt'],
        );
    }

    /**
     * Generate optimized prompt for AI.
     *
     * @param array $export The export data
     * @return string
     */
    public static function generate_prompt( ?array $export = null ) {
        if ( null === $export ) {
            $export = self::generate_export();
        }

        $counts = $export['meta']['counts'];
        $site_url = $export['meta']['site_url'];

        $prompt = <<<PROMPT
# 🔗 درخواست لینک‌سازی داخلی هوشمند

## نقش تو
متخصص سئو و لینک‌سازی داخلی. تحلیل محتوای دو سایت (فروشگاه + بلاگ) و پیشنهاد لینک‌های بهینه.

## داده‌های ورودی
**JSON محتوا:** {$counts['products']} محصول | {$counts['posts']} مقاله | {$counts['pages']} صفحه | {$counts['product_categories']} دسته‌بندی محصول | {$counts['tags']} تگ

### داده‌های اختیاری (اگر آپلود شدند):
- **Google Search Console CSV**: اولویت به کلمات با Impression/Click بالا
- **Google Analytics CSV**: اولویت به صفحات پربازدید برای دریافت لینک

---

## قوانین لینک‌سازی

### ✅ مجاز
| از | به |
|---|---|
| مقاله بلاگ | محصول مرتبط (اولویت ۱) |
| مقاله بلاگ | دسته‌بندی محصولات |
| محصول | مقاله مرتبط (انتهای توضیحات) |
| محصول | محصول مرتبط |
| صفحه | محصول یا مقاله |

### ❌ ممنوع
- `is_linkable: false` → لینک نده
- `stock_status: outofstock` → لینک نده  
- دسته‌بندی بلاگ (noindex) → لینک نده
- Self-linking → ممنوع

### Anchor Text
1. اول `focus_keyword` (اگر موجود)
2. سپس عنوان طبیعی فارسی
3. حداکثر **3 لینک / 1000 کلمه**

### اولویت
- `high`: focus_keyword مشترک
- `medium`: دسته مشترک یا صفحه پربازدید (از Analytics)
- `low`: ارتباط موضوعی

---

## خروجی

**فقط** یک JSON array (بدون توضیح):

```json
[
  {
    "source_id": 456,
    "source_site": "blog",
    "keyword": "خرید لپ تاپ ایسوس",
    "target_id": 123,
    "target_site": "shop",
    "target_url": "https://example.com/product/laptop",
    "priority": "high",
    "reason": "focus_keyword مشترک"
  }
]
```

**توجه:** source_id = پستی که لینک در آن قرار می‌گیرد | target_url = مقصد لینک

---

**محتوای JSON را تحلیل کن و لینک‌های پیشنهادی را خروجی بده.**
PROMPT;

        return $prompt;
    }

    /**
     * Get export as downloadable JSON.
     *
     * @return string JSON string
     */
    public static function get_json_download() {
        $export = self::generate_export();
        return wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    /**
     * AJAX handler for export.
     */
    public static function ajax_export() {
        check_ajax_referer( 'brz_smart_linker_export' );

        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $export = self::generate_export();
        $warning = isset( $export['meta']['warning'] ) ? $export['meta']['warning'] : null;

        wp_send_json_success( array(
            'json'    => $export,
            'prompt'  => self::generate_prompt( $export ),
            'warning' => $warning,
        ) );
    }

    /**
     * AJAX handler for sync from peer.
     */
    public static function ajax_sync_peer() {
        check_ajax_referer( 'brz_smart_linker_export' );

        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $result = BRZ_Smart_Linker_Sync::sync_from_peer();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => sprintf( 'سینک موفق: %d آیتم از %s دریافت شد.', $result['count'], $result['site_id'] ),
            'count'   => $result['count'],
        ) );
    }
}
