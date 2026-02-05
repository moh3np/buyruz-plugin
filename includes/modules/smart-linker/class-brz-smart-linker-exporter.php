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
     * Only includes linkable (index) content - noindex items are excluded.
     *
     * @return array
     */
    public static function generate_export() {
        // Step 1: Refresh local index
        BRZ_Smart_Linker_Sync::refresh_local_index();

        // Step 2: Fetch from peer site (will merge into content_index table)
        $peer_result = BRZ_Smart_Linker_Sync::fetch_peer_and_merge();
        $peer_warning = isset( $peer_result['warning'] ) ? $peer_result['warning'] : null;
        $peer_count = isset( $peer_result['count'] ) ? $peer_result['count'] : 0;

        // Step 3: Get ONLY linkable content (noindex items are excluded)
        $all_content = BRZ_Smart_Linker_DB::get_content_index( null, true ); // true = only_linkable
        
        // Fallback: If content_index is empty, get local content directly from WordPress
        $local_count = 0;
        $local_content_from_db = BRZ_Smart_Linker_DB::get_content_index( 'local', true );
        $local_count = count( $local_content_from_db );
        
        if ( empty( $local_content_from_db ) ) {
            // Table doesn't exist or is empty - get directly from WordPress
            $local_content = self::get_local_content_fallback();
            $all_content = array_merge( $all_content, $local_content );
            $local_count = count( $local_content );
        }

        // Organize by type
        $export = array(
            'meta' => array(
                'exported_at'    => current_time( 'c' ),
                'plugin_version' => defined( 'BRZ_VERSION' ) ? BRZ_VERSION : '1.0.0',
                'site_url'       => home_url(),
                'total_items'    => count( $all_content ),
                'local_count'    => $local_count,
                'peer_count'     => $peer_count,
                'warning'        => $peer_warning,
            ),
            'products'           => array(),
            'posts'              => array(),
            'pages'              => array(),
            'product_categories' => array(),
            'post_categories'    => array(),
            'tags'               => array(),
        );

        foreach ( $all_content as $item ) {
            $formatted = self::format_item_for_export( $item );

            switch ( $item['post_type'] ) {
                case 'product':
                    $export['products'][] = $formatted;
                    break;
                case 'post':
                    $export['posts'][] = $formatted;
                    break;
                case 'page':
                    $export['pages'][] = $formatted;
                    break;
                case 'term_product_cat':
                    $export['product_categories'][] = $formatted;
                    break;
                case 'term_category':
                    $export['post_categories'][] = $formatted;
                    break;
                case 'term_post_tag':
                    $export['tags'][] = $formatted;
                    break;
            }
        }

        // Update counts
        $export['meta']['counts'] = array(
            'products'           => count( $export['products'] ),
            'posts'              => count( $export['posts'] ),
            'pages'              => count( $export['pages'] ),
            'product_categories' => count( $export['product_categories'] ),
            'post_categories'    => count( $export['post_categories'] ),
            'tags'               => count( $export['tags'] ),
        );

        return $export;
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
**JSON محتوا:** {$counts['products']} محصول | {$counts['posts']} مقاله | {$counts['pages']} صفحه | {$counts['product_categories']} دسته‌بندی

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
