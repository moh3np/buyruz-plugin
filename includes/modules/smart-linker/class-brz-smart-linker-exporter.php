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

        // Read export filter settings
        $settings     = BRZ_Smart_Linker::get_settings();
        $filter_map   = array(
            'product'          => isset( $settings['export_filter_products'] ) ? $settings['export_filter_products'] : 'index',
            'post'             => isset( $settings['export_filter_posts'] ) ? $settings['export_filter_posts'] : 'index',
            'page'             => isset( $settings['export_filter_pages'] ) ? $settings['export_filter_pages'] : 'index',
            'term_product_cat' => isset( $settings['export_filter_product_categories'] ) ? $settings['export_filter_product_categories'] : 'all',
            'term_product_tag' => isset( $settings['export_filter_tags'] ) ? $settings['export_filter_tags'] : 'all',
        );

        foreach ( $all_content as $item ) {
            $formatted   = self::format_item_for_export( $item );
            $is_linkable = isset( $item['is_linkable'] ) ? (int) $item['is_linkable'] : 1;
            $post_type   = isset( $item['post_type'] ) ? $item['post_type'] : '';

            // Apply export filter: skip noindex items if filter is 'index'
            $filter = isset( $filter_map[ $post_type ] ) ? $filter_map[ $post_type ] : 'index';
            if ( 'index' === $filter && ! $is_linkable ) {
                continue;
            }

            switch ( $post_type ) {
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

        // Map to actual site_role
        static $local_role_term = null;
        if ( null === $local_role_term ) {
            $s = BRZ_Smart_Linker::get_settings();
            $local_role_term = isset( $s['site_role'] ) ? $s['site_role'] : 'shop';
        }

        // Focus keyword fallback: use term name
        if ( empty( $focus_keyword ) ) {
            $focus_keyword = $term->name;
        }

        // Persian-aware word count
        $desc_plain = wp_strip_all_tags( $term->description );
        $wc = empty( trim( $desc_plain ) ) ? 0 : count( preg_split( '/\s+/u', trim( $desc_plain ), -1, PREG_SPLIT_NO_EMPTY ) );

        return array(
            'id'                 => (int) $term->term_id,
            'site'               => $local_role_term,
            'type'               => $type,
            'title'              => $term->name,
            'url'                => $url,
            'categories'         => array( $term->name ),
            'focus_keyword'      => $focus_keyword,
            'secondary_keywords' => array(),
            'word_count'         => $wc,
            'is_linkable'        => $is_linkable,
            'stock_status'       => '',
            'price'              => '',
            'content'            => $term->description,
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
        // Map 'local' site_id to actual site_role for clarity
        static $local_role = null;
        if ( null === $local_role ) {
            $s = BRZ_Smart_Linker::get_settings();
            $local_role = isset( $s['site_role'] ) ? $s['site_role'] : 'shop';
        }
        $site = ( 'local' === $item['site_id'] ) ? $local_role : $item['site_id'];

        $categories = $item['category_names'];
        if ( is_string( $categories ) ) {
            $categories = json_decode( $categories, true );
        }

        $secondary = $item['secondary_keywords'];
        if ( is_string( $secondary ) ) {
            $secondary = json_decode( $secondary, true );
        }

        // Focus keyword fallback: use cleaned title
        $focus_keyword = isset( $item['focus_keyword'] ) ? $item['focus_keyword'] : '';
        if ( empty( $focus_keyword ) && ! empty( $item['title'] ) ) {
            $focus_keyword = trim( preg_replace( '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2700}-\x{27BF}]/u', '', $item['title'] ) );
        }

        return array(
            'id'                 => (int) $item['post_id'],
            'site'               => $site,
            'type'               => $item['post_type'],
            'title'              => $item['title'],
            'url'                => $item['url'],
            'categories'         => is_array( $categories ) ? $categories : array(),
            'focus_keyword'      => $focus_keyword,
            'secondary_keywords' => is_array( $secondary ) ? $secondary : array(),
            'word_count'         => (int) $item['word_count'],
            'is_linkable'        => (bool) $item['is_linkable'],
            'stock_status'       => $item['stock_status'],
            'price'              => $item['price'],
            'content'            => $item['content_excerpt'],
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
# 🔗 درخواست لینک‌سازی داخلی هوشمند (Smart Internal Linking)

## 🎯 نقش
تو یک متخصص ارشد سئو هستی با تمرکز بر:
- **Internal Linking Architecture** (معماری لینک‌سازی داخلی)
- **GEO (Generative Engine Optimization)** - بهینه‌سازی برای موتورهای مولد (Google AI Overviews, Perplexity, etc.)
- **Topical Authority** (اقتدار موضوعی) و **E-E-A-T Signals**

## 📊 ساختار سایت

### دو سایت (یک دامنه):
| سایت | نقش | URL Pattern | Intent |
|---|---|---|---|
| **shop** ({$site_url}) | فروشگاه | `/product/`, `/toys/`, `/product-tag/` | Transactional |
| **blog** ({$site_url}/mag) | مجله | `/mag/` | Informational / Educational |

### آمار محتوا:
{$counts['products']} محصول | {$counts['posts']} مقاله | {$counts['pages']} صفحه | {$counts['product_categories']} دسته‌بندی محصول | {$counts['tags']} تگ محصول

### انواع محتوا و Intent:
| نوع | کلید `type` | سایت | Intent |
|---|---|---|---|
| محصول | `product` | shop | Transactional |
| مقاله | `post` | blog | Informational |
| صفحه | `page` | shop/blog | Mixed |
| دسته‌بندی محصول | `term_product_cat` | shop | Navigational/Transactional |
| تگ محصول | `term_product_tag` | shop | Navigational |

### فیلدهای هر آیتم:
- `content`: متن کامل با لینک‌های فعلی. **لینک‌های موجود** با `<a href="...">` مشخص‌اند — دوباره پیشنهاد نده.
- `focus_keyword`: کلمه کلیدی کانونی (از RankMath). اگر خالی بود، مقدار `title` جایگزین شده.
- `word_count`: تعداد کلمات فارسی.
- `is_linkable`: آیا index (true) یا noindex (false) است.
- `stock_status`: وضعیت موجودی محصول (instock/outofstock).

---

## 📐 قوانین لینک‌سازی

### ✅ ماتریس لینک مجاز:
| از ↓ به → | محصول | مقاله | صفحه | دسته محصول | تگ محصول |
|---|---|---|---|---|---|
| **مقاله** | ✅ اولویت ۱ | ✅ | ✅ | ✅ | ✅ |
| **محصول** | ✅ مرتبط | ✅ انتهای توضیحات | ❌ | ✅ | ✅ |
| **صفحه** | ✅ | ✅ | ⚠️ فقط مرتبط | ✅ | ❌ |
| **دسته محصول** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **تگ محصول** | ✅ | ❌ | ❌ | ❌ | ❌ |

### ❌ قوانین انتقادی:
1. **`is_linkable: false`** → هرگز به این آیتم لینک نده (noindex)
2. **`stock_status: "outofstock"`** → لینک نده
3. **Self-linking** ممنوع (`source_id == target_id` در همان سایت)
4. **لینک تکراری** ممنوع: یک مقصد فقط یک بار در هر صفحه
5. **لینک‌های موجود**: اگر `<a href="...">` در `content` مبدأ وجود دارد، به همان مقصد دوباره لینک نده
6. صفحات **noindex** می‌توانند مبدأ باشند ولی هرگز مقصد نباشند

### 🔤 Anchor Text (متن لنگر) — قانون تنوع:
**SpamBrain-safe distribution (بسیار مهم):**
- **۳۰-۴۰٪ Keyword-rich**: از `focus_keyword` مقصد یا تغییرات معنایی (semantic variation)
- **۴۰-۵۰٪ Descriptive/Contextual**: عبارات طبیعی فارسی که مقصد را در context توصیف می‌کنند
- **۱۰-۱۵٪ Branded/Navigational**: نام دسته‌بندی، نام بخش
- **۰٪ Generic**: هرگز «کلیک کنید» یا «اینجا» — بی‌ارزش برای سئو

**نکته**: برای لینک‌سازی داخلی، exact match anchor text بیشتر از external مجاز است ولی باید طبیعی و در context جمله قرار گیرد.

### 📏 تراکم لینک (بر اساس word_count):
| تعداد کلمات | حداکثر لینک جدید |
|---|---|
| ≤ 300 | ۱ (قانون حداقل ارزش) |
| 300 – 1000 | ۲ |
| 1000 – 2000 | ۴ |
| 2000 – 3000 | ۶ |
| 3000+ | ۳ لینک / ۱۰۰۰ کلمه |

**قانون حداقل ارزش**: حتی اگر `word_count` کم یا ۰ باشد، اگر `focus_keyword` مقصد در `content` مبدأ وجود دارد، ۱ لینک مجاز است.

### 🎯 اولویت‌بندی (GEO-Optimized):
1. **critical**: `focus_keyword` مشترک بین مبدأ و مقصد
2. **high**: دسته‌بندی مشترک + ارتباط موضوعی قوی
3. **medium**: Intent complementary (مقاله آموزشی ↔ محصول تراکنشی = پل ارزشمند)
4. **low**: ارتباط موضوعی ضعیف ولی مفید

### 🌐 اصول GEO (Generative Engine Optimization):
- **Topic Clusters**: مقالات بلاگ = Spoke، صفحات دسته‌بندی = Pillar. لینک‌ها باید خوشه‌های موضوعی بسازند.
- **Entity Linking**: لینک بر اساس موجودیت‌های مشترک (مثلاً entity «مافیا» بین مقاله و محصول)
- **Intent Bridge**: مقاله آموزشی → محصول = پل تراکنشی (ارزشمندترین نوع لینک)
- **Orphan Prevention**: هر صفحه `is_linkable: true` باید حداقل ۱ لینک ورودی داشته باشد

---

## 📤 خروجی

**فقط** یک JSON array خالص (بدون توضیح، بدون markdown):

```json
[
  {
    "source_id": 456,
    "source_site": "blog",
    "keyword": "بازی فکری مافیا",
    "target_id": 123,
    "target_site": "shop",
    "target_url": "https://example.com/product/mafia-game",
    "priority": "critical",
    "anchor_type": "keyword-rich",
    "reason": "focus_keyword مشترک + intent bridge"
  }
]
```

### فیلدهای خروجی:
| فیلد | توضیح |
|---|---|
| `source_id` | ID پستی که لینک در آن قرار می‌گیرد |
| `source_site` | `"shop"` یا `"blog"` |
| `keyword` | Anchor text (متن لنگر) |
| `target_id` | ID مقصد |
| `target_site` | `"shop"` یا `"blog"` |
| `target_url` | URL کامل مقصد |
| `priority` | `"critical"` / `"high"` / `"medium"` / `"low"` |
| `anchor_type` | `"keyword-rich"` / `"descriptive"` / `"navigational"` |
| `reason` | دلیل کوتاه (برای بررسی انسانی) |

---

**⚡ شروع کن: JSON محتوا را تحلیل کن و لینک‌های پیشنهادی را خروجی بده.**

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
