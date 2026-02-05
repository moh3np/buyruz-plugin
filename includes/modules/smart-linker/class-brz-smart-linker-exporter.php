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
     *
     * @return array
     */
    public static function generate_export() {
        // First, refresh local index
        BRZ_Smart_Linker_Sync::refresh_local_index();

        // Get all content from both sites
        $all_content = BRZ_Smart_Linker_DB::get_content_index();

        // Organize by type
        $export = array(
            'meta' => array(
                'exported_at'   => current_time( 'c' ),
                'plugin_version'=> defined( 'BRZ_VERSION' ) ? BRZ_VERSION : '1.0.0',
                'site_url'      => home_url(),
                'total_items'   => count( $all_content ),
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

        $prompt = <<<PROMPT
# دستورالعمل لینک‌سازی داخلی هوشمند

تو یک متخصص سئو و لینک‌سازی داخلی هستی. وظیفه تو تحلیل محتوای دو سایت (فروشگاه و بلاگ) و پیشنهاد لینک‌های داخلی بهینه است.

## آمار محتوا
- محصولات: {$counts['products']} عدد
- مقالات: {$counts['posts']} عدد
- صفحات: {$counts['pages']} عدد
- دسته‌بندی محصولات: {$counts['product_categories']} عدد
- تگ‌ها: {$counts['tags']} عدد

## قوانین لینک‌سازی (بسیار مهم)

### ✅ لینک‌های مجاز:
1. از مقالات بلاگ → به محصولات مرتبط (اولویت بالا)
2. از مقالات بلاگ → به دسته‌بندی محصولات
3. از محصولات → به مقالات مرتبط (فقط در انتهای توضیحات)
4. از محصولات → به محصولات مرتبط
5. از صفحات → به محصولات یا مقالات مرتبط

### ❌ لینک‌های ممنوع:
1. به آیتم‌هایی که is_linkable: false دارند لینک نده
2. به محصولات ناموجود (stock_status: outofstock) لینک نده
3. به دسته‌بندی‌های بلاگ اصلاً لینک نده (noindex هستند)
4. لینک به همان صفحه (self-linking) ممنوع است

### 📝 قوانین Anchor Text:
1. از focus_keyword استفاده کن اگر موجود است
2. Anchor text باید طبیعی و فارسی باشد
3. از عنوان محصول/مقاله به صورت طبیعی استفاده کن
4. حداکثر ۳ لینک در هر ۱۰۰۰ کلمه

### 🎯 اولویت‌بندی:
- high: لینک از مقاله به محصول با focus_keyword مشترک
- medium: لینک بر اساس دسته‌بندی مشترک
- low: لینک‌های عمومی مرتبط

## داده‌های اضافی (اگر موجود است)
اگر فایل‌های زیر را آپلود کردم، از آنها برای اولویت‌بندی استفاده کن:
- **Search Console CSV**: کلماتی با impression/click بالا اولویت بیشتری دارند
- **Analytics CSV**: صفحات پربازدید اولویت بالاتری برای دریافت لینک دارند

## فرمت خروجی مورد انتظار

یک JSON array بدون هیچ توضیح اضافی:

```json
[
    {
        "source_id": 456,
        "source_site": "blog",
        "source_type": "post",
        "keyword": "خرید لپ تاپ ایسوس",
        "target_id": 123,
        "target_site": "shop", 
        "target_url": "https://shop.example.com/product/asus-laptop",
        "priority": "high",
        "reason": "focus_keyword مشترک با محصول"
    }
]
```

## نکات مهم
- فقط JSON خروجی بده، بدون توضیح اضافی
- همه لینک‌ها را در یک array قرار بده
- source_id همان ID پستی است که باید لینک در آن قرار گیرد
- target_url آدرس کامل مقصد است

حالا فایل JSON محتوا را تحلیل کن و لینک‌های پیشنهادی را خروجی بده.
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

        wp_send_json_success( array(
            'json'   => $export,
            'prompt' => self::generate_prompt( $export ),
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
