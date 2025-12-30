<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Compare_Table_Admin {
    const META_KEY = '_buyruz_compare_table';
    const MIN_COLUMNS = 1;
    const MAX_COLUMNS = 6;
    const ADMIN_PAGE = 'buyruz-compare-editor';
    private static $processed = array();
    private static $blocked_new_editor = false;
    private static $panel_rendered = false;

    public static function init() {
        add_filter( 'woocommerce_admin_features', array( __CLASS__, 'guard_product_editor_features' ), 5 );
        add_filter( 'woocommerce_new_product_management_experience_enabled', '__return_false', 5 );
        add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_product' ), 20, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'maybe_show_editor_notice' ) );
        add_action( 'add_meta_boxes_product', array( __CLASS__, 'register_fallback_metabox' ), 5 );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_filter( 'post_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_tab' ), 25 );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_tab' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_object' ) );
        add_action( 'save_post_product', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_footer', array( __CLASS__, 'maybe_hide_duplicate_fallback' ) );
    }

    public static function add_product_tab( $tabs ) {
        $tabs['brz_compare'] = array(
            'label'    => 'جدول مقایسه',
            'target'   => 'brz_compare_table_panel',
            'class'    => array(),
            'priority' => 62,
        );

        return $tabs;
    }

    public static function guard_product_editor_features( $features ) {
        if ( ! is_array( $features ) ) {
            return $features;
        }

        $blocked = array();
        $needles = array( 'product_block_editor', 'product-block-editor', 'new-product-management-experience' );

        foreach ( $features as $feature ) {
            $keep = true;
            if ( is_string( $feature ) ) {
                foreach ( $needles as $needle ) {
                    if ( '' !== $needle && strpos( $feature, $needle ) !== false ) {
                        $keep = false;
                        break;
                    }
                }
            }
            if ( $keep ) {
                $blocked[] = $feature;
            } else {
                self::$blocked_new_editor = true;
            }
        }

        return array_values( $blocked );
    }

    public static function disable_block_editor_for_product( $use_block_editor, $post_type ) {
        if ( 'product' !== $post_type ) {
            return $use_block_editor;
        }

        if ( $use_block_editor ) {
            self::$blocked_new_editor = true;
        }

        return false;
    }

    public static function maybe_show_editor_notice() {
        if ( ! self::$blocked_new_editor ) {
            return;
        }

        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( empty( $screen ) || 'product' !== $screen->post_type ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html( 'برای نمایش تب «جدول مقایسه»، ویرایشگر جدید محصول ووکامرس غیرفعال و حالت کلاسیک فعال شد.' );
        echo '</p></div>';
    }

    public static function register_fallback_metabox() {
        add_meta_box(
            'brz-compare-table-fallback',
            'جدول مقایسه محصول',
            array( __CLASS__, 'render_fallback_metabox' ),
            'product',
            'normal',
            'high'
        );
    }

    public static function enqueue( $hook ) {
        $screen = get_current_screen();
        $is_editor_page = ( ! empty( $screen ) && self::ADMIN_PAGE === $screen->id );

        if ( 'post.php' !== $hook && 'post-new.php' !== $hook && ! $is_editor_page ) {
            return;
        }
        if ( empty( $screen ) || ( 'product' !== $screen->post_type && ! $is_editor_page ) ) {
            return;
        }

        wp_enqueue_style( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.css', array(), BRZ_VERSION );
        wp_enqueue_script(
            'brz-compare-table-admin',
            BRZ_URL . 'assets/admin/product-compare-lite.js',
            array(),
            BRZ_VERSION,
            true
        );
    }

    public static function render_product_tab() {
        global $post;

        if ( empty( $post ) || 'product' !== $post->post_type ) {
            return;
        }

        self::$panel_rendered = true;
        ?>
        <div id="brz_compare_table_panel" class="panel woocommerce_options_panel">
            <?php self::render_editor_inner( $post ); ?>
        </div>
        <?php
    }

    public static function render_fallback_metabox( $post ) {
        self::render_editor_inner( $post );
    }

    public static function save_product_object( $product ) {
        if ( empty( $product ) ) {
            return;
        }
        $post_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? $product->get_id() : 0;
        if ( $post_id ) {
            self::save( $post_id );
        }
    }

    public static function save( $post_id, $post = null ) {
        if ( isset( self::$processed[ $post_id ] ) ) {
            return;
        }
        if ( ! self::should_process_request( $post_id ) ) {
            return;
        }

        self::$processed[ $post_id ] = true;
        $payload = self::sanitize_payload( self::collect_from_request() );
        self::persist_payload( $post_id, $payload );
    }

    public static function get_meta( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $raw ) ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            return array();
        }
        return $decoded;
    }

    private static function editor_data( $post_id ) {
        $meta    = self::get_meta( $post_id );
        $columns = array();

        if ( isset( $meta['columns'] ) && is_array( $meta['columns'] ) ) {
            foreach ( $meta['columns'] as $col ) {
                $columns[] = sanitize_text_field( self::normalize_cell( $col ) );
            }
        }

        $columns       = array_slice( $columns, 0, self::MAX_COLUMNS );
        $columns_count = count( $columns );

        if ( 0 === $columns_count ) {
            // شروع خالی: یک ستون بدون مقدار برای ویرایش
            $columns       = array( '' );
            $columns_count = 1;
        }

        $rows_raw = isset( $meta['rows'] ) && is_array( $meta['rows'] ) ? $meta['rows'] : array();
        $rows     = array();
        if ( ! empty( $rows_raw ) ) {
            foreach ( $rows_raw as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $clean_row = array();
                for ( $i = 0; $i < $columns_count; $i++ ) {
                    $clean_row[] = isset( $row[ $i ] ) ? sanitize_text_field( self::normalize_cell( $row[ $i ] ) ) : '';
                }
                $rows[] = $clean_row;
            }
        }

        // حداقل یک ردیف بدنه برای شروع ویرایش
        if ( empty( $rows ) ) {
            $rows[] = array_fill( 0, $columns_count, '' );
        }

        return array(
            'title'   => isset( $meta['title'] ) ? sanitize_text_field( $meta['title'] ) : '',
            'columns' => $columns,
            'rows'    => $rows,
        );
    }

    private static function collect_from_request() {
        $title   = isset( $_POST['brz_compare_title'] ) ? wp_unslash( $_POST['brz_compare_title'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $columns = isset( $_POST['brz_compare_columns'] ) ? (array) wp_unslash( $_POST['brz_compare_columns'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $rows    = isset( $_POST['brz_compare_rows'] ) ? (array) wp_unslash( $_POST['brz_compare_rows'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return array(
            'title'   => $title,
            'columns' => $columns,
            'rows'    => $rows,
        );
    }

    private static function sanitize_payload( array $raw ) {
        $title   = isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';

        $columns  = array();
        if ( isset( $raw['columns'] ) && is_array( $raw['columns'] ) ) {
            foreach ( $raw['columns'] as $col ) {
                $columns[] = sanitize_text_field( self::normalize_cell( $col ) );
            }
        }

        $columns = array_slice( $columns, 0, self::MAX_COLUMNS );
        $columns = array_values( $columns );

        $rows_raw = isset( $raw['rows'] ) && is_array( $raw['rows'] ) ? $raw['rows'] : array();
        $column_count = max( min( count( $columns ), self::MAX_COLUMNS ), self::MIN_COLUMNS );
        $first_row    = reset( $rows_raw );
        $row_width    = is_array( $first_row ) ? count( $first_row ) : 0;
        if ( $row_width > $column_count ) {
            $column_count = min( $row_width, self::MAX_COLUMNS );
        }
        if ( $column_count > count( $columns ) ) {
            $columns = array_pad( $columns, $column_count, '' );
        }
        $column_has_value = array_fill( 0, $column_count, false );
        $prepared_rows    = array();

        if ( ! empty( $rows_raw ) ) {
            foreach ( $rows_raw as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $clean_row = array();
                $has_value = false;
                for ( $i = 0; $i < $column_count; $i++ ) {
                    $cell        = isset( $row[ $i ] ) ? self::normalize_cell( $row[ $i ] ) : '';
                    $clean_cell  = sanitize_text_field( $cell );
                    $clean_row[] = $clean_cell;
                    if ( '' !== $clean_cell ) {
                        $has_value = true;
                        $column_has_value[ $i ] = true;
                    }
                }
                if ( $has_value ) {
                    $prepared_rows[] = $clean_row;
                }
            }
        }

        // حذف ستون‌هایی که هیچ هدر و دیتایی ندارند
        $keep_map = array();
        for ( $i = 0; $i < $column_count; $i++ ) {
            if ( ! empty( $columns[ $i ] ) || ! empty( $column_has_value[ $i ] ) ) {
                $keep_map[] = $i;
            }
        }

        if ( empty( $keep_map ) || empty( $prepared_rows ) ) {
            return array();
        }

        $final_columns = array();
        foreach ( $keep_map as $index ) {
            $final_columns[] = isset( $columns[ $index ] ) ? $columns[ $index ] : '';
        }

        $rows = array();
        foreach ( $prepared_rows as $row ) {
            $mapped = array();
            foreach ( $keep_map as $index ) {
                $mapped[] = isset( $row[ $index ] ) ? $row[ $index ] : '';
            }
            if ( array_filter( $mapped, 'strlen' ) ) {
                $rows[] = $mapped;
            }
        }

        if ( empty( $rows ) ) {
            return array();
        }

        return array(
            'title'   => $title,
            'columns' => array_slice( $final_columns, 0, self::MAX_COLUMNS ),
            'rows'    => $rows,
        );
    }

    private static function persist_payload( $post_id, array $payload ) {
        if ( empty( $payload ) ) {
            delete_post_meta( $post_id, self::META_KEY );
            return;
        }

        update_post_meta( $post_id, self::META_KEY, wp_json_encode( $payload ) );
    }

    private static function should_process_request( $post_id ) {
        if ( ! isset( $_POST['brz_compare_table_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['brz_compare_table_nonce'] ), 'brz_compare_table_save' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return false;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return false;
        }

        if ( ! current_user_can( 'edit_product', $post_id ) ) {
            return false;
        }

        return true;
    }

    private static function default_columns() {
        $saved = class_exists( 'BRZ_Settings' ) ? BRZ_Settings::get( 'compare_table_columns', array() ) : array();
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return array_slice( array_values( array_filter( $saved, 'strlen' ) ), 0, self::MAX_COLUMNS );
    }

    private static function normalize_cell( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $value = (string) $value;

        // Decode escaped \uXXXX sequences
        if ( strpos( $value, '\\u' ) !== false ) {
            $decoded = json_decode( '"' . str_replace( array( "\r", "\n" ), '', addslashes( $value ) ) . '"', true );
            if ( is_string( $decoded ) ) {
                $value = $decoded;
            }
        }

        // Decode bare uXXXX sequences that ممکن است قبلاً بک‌اسلش‌شان حذف شده باشد.
        if ( preg_match( '/u[0-9a-fA-F]{4}/', $value ) ) {
            $value = preg_replace_callback(
                '/u([0-9a-fA-F]{4})/',
                function( $m ) {
                    return html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES, 'UTF-8' );
                },
                $value
            );
        }

        return $value;
    }

    public static function register_admin_page() {
        add_submenu_page(
            null,
            'جدول مقایسه',
            'جدول مقایسه',
            'edit_products',
            self::ADMIN_PAGE,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function add_row_action( $actions, $post ) {
        if ( empty( $post ) || 'product' !== $post->post_type ) {
            return $actions;
        }
        if ( ! current_user_can( 'edit_product', $post->ID ) ) {
            return $actions;
        }

        $url = add_query_arg(
            array(
                'page'     => self::ADMIN_PAGE,
                'product'  => $post->ID,
                '_wpnonce' => wp_create_nonce( 'brz_compare_editor_' . $post->ID ),
            ),
            admin_url( 'admin.php' )
        );

        $actions['brz_compare'] = '<a href="' . esc_url( $url ) . '">جدول مقایسه</a>';
        return $actions;
    }

    public static function render_admin_page() {
        $product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $product_id ) {
            echo '<div class="notice notice-error"><p>محصولی انتخاب نشده است.</p></div>';
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, 'brz_compare_editor_' . $product_id ) ) {
            echo '<div class="notice notice-error"><p>دسترسی مجاز نیست.</p></div>';
            return;
        }

        if ( ! current_user_can( 'edit_product', $product_id ) ) {
            echo '<div class="notice notice-error"><p>دسترسی کافی برای ویرایش این محصول ندارید.</p></div>';
            return;
        }

        $product = get_post( $product_id );
        if ( ! $product || 'product' !== $product->post_type ) {
            echo '<div class="notice notice-error"><p>محصول یافت نشد.</p></div>';
            return;
        }

        echo '<div class="wrap" dir="rtl">';
        echo '<h1 class="wp-heading-inline">جدول مقایسه محصول</h1>';
        echo ' <a class="page-title-action" href="' . esc_url( get_edit_post_link( $product_id, '' ) ) . '">بازگشت به ویرایش محصول</a>';
        echo '<hr class="wp-header-end" />';
        echo '<div style="max-width:1200px;">';
        self::render_editor_inner( $product );
        echo '</div>';
        echo '</div>';
    }

    private static function render_editor_inner( $post ) {
        $post_id       = is_object( $post ) ? $post->ID : (int) $post;
        $data          = self::editor_data( $post_id );
        $defaults      = self::default_columns();
        $max_columns   = self::MAX_COLUMNS;
        $nonce         = wp_create_nonce( 'brz_compare_table_save' );
        $columns_count = count( $data['columns'] );
        ?>
        <div class="brz-compare-box brz-compare-modern" data-default-columns="<?php echo esc_attr( wp_json_encode( $defaults ) ); ?>" data-product-id="<?php echo esc_attr( $post_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-max-cols="<?php echo esc_attr( $max_columns ); ?>">
            <?php wp_nonce_field( 'brz_compare_table_save', 'brz_compare_table_nonce' ); ?>

            <div class="brz-compare-top">
                <div>
                    <h3>جدول مقایسه محصول</h3>
                    <p class="description">عنوان جدول اختیاری است. سطر اول هدر جدول است و سایر سطرها داده‌ها هستند.</p>
                </div>
                <div class="brz-compare-top__title">
                    <label for="brz-compare-title"><strong>عنوان جدول (اختیاری)</strong></label>
                    <input id="brz-compare-title" type="text" name="brz_compare_title" class="widefat" value="<?php echo esc_attr( $data['title'] ); ?>" placeholder="مثلاً جدول سایزبندی" />
                </div>
            </div>

            <hr class="brz-compare-divider" />

            <div class="brz-compare-sheet">
                <div class="brz-compare-sheet__actions">
                    <div class="brz-compare-sheet__hint">برای افزودن ستون/سطر از دکمه‌های سبز و برای حذف از قرمز استفاده کنید. سطر اول هدر است.</div>
                    <div class="brz-compare-sheet__buttons">
                        <div class="brz-compare-sheet__group">
                            <button type="button" class="brz-compare-btn brz-compare-btn--danger" data-remove-col aria-label="حذف ستون">−</button>
                            <button type="button" class="brz-compare-btn brz-compare-btn--success" data-add-col aria-label="افزودن ستون">+</button>
                        </div>
                        <div class="brz-compare-sheet__group">
                            <button type="button" class="brz-compare-btn brz-compare-btn--danger" data-remove-row aria-label="حذف ردیف">−</button>
                            <button type="button" class="brz-compare-btn brz-compare-btn--success" data-add-row aria-label="افزودن ردیف">+</button>
                        </div>
                    </div>
                </div>

                <div class="brz-compare-table-card brz-compare-table-card--modern">
                    <div class="brz-compare-grid" id="brz-compare-grid" data-max="<?php echo esc_attr( $max_columns ); ?>">
                        <div class="brz-compare-row brz-compare-row--header">
                            <?php foreach ( $data['columns'] as $col_index => $col_value ) : ?>
                                <div class="brz-compare-cell">
                                    <input type="text" name="brz_compare_columns[]" value="<?php echo esc_attr( $col_value ); ?>" placeholder="هدر <?php echo esc_attr( $col_index + 1 ); ?>" />
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ( $data['rows'] as $r_index => $row ) : ?>
                            <div class="brz-compare-row" data-row="<?php echo esc_attr( $r_index ); ?>">
                                <button type="button" class="brz-compare-btn brz-compare-btn--danger brz-compare-remove-row" aria-label="حذف ردیف">−</button>
                                <?php for ( $c = 0; $c < $columns_count; $c++ ) : ?>
                                    <?php $cell = isset( $row[ $c ] ) ? $row[ $c ] : ''; ?>
                                    <div class="brz-compare-cell">
                                        <input type="text" name="brz_compare_rows[<?php echo esc_attr( $r_index ); ?>][<?php echo esc_attr( $c ); ?>]" value="<?php echo esc_attr( $cell ); ?>" placeholder="—" />
                                    </div>
                                <?php endfor; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function maybe_hide_duplicate_fallback() {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        $is_product_screen = ( $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'post-new' ), true ) );
        if ( ! $is_product_screen ) {
            return;
        }

        ?>
        <script>
        (function() {
            // Remove fallback metabox only when the WooCommerce tab (link + panel) is present to avoid duplicate UIs.
            var tabPanel = document.getElementById('brz_compare_table_panel');
            var tabLink = document.querySelector('.wc-tabs a[href="#brz_compare_table_panel"]');
            var fallback = document.getElementById('brz-compare-table-fallback');
            var tabVisible = tabLink && tabLink.offsetParent !== null;
            var panelVisible = tabPanel && tabPanel.offsetParent !== null;
            if (tabVisible && panelVisible && fallback) { fallback.remove(); }
        })();
        </script>
        <?php
    }
}
