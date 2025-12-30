<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Compare_Table_Admin {
    const META_KEY = '_buyruz_compare_table';

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
        add_action( 'save_post_product', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function add_metabox() {
        add_meta_box(
            'brz-compare-table',
            'جدول متا',
            array( __CLASS__, 'render_metabox' ),
            'product',
            'normal',
            'high'
        );
    }

    public static function enqueue( $hook ) {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }
        $screen = get_current_screen();
        if ( empty( $screen ) || 'product' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_style( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.css', array(), BRZ_VERSION );
        wp_enqueue_script(
            'brz-compare-table-admin',
            BRZ_URL . 'assets/admin/product-compare.js',
            array(),
            BRZ_VERSION,
            true
        );
    }

    public static function render_metabox( $post ) {
        $data = self::get_meta( $post->ID );

        $enabled = ! empty( $data['enabled'] );
        $title   = isset( $data['title'] ) ? $data['title'] : '';
        $columns = isset( $data['columns'] ) && is_array( $data['columns'] ) ? $data['columns'] : self::default_columns();
        $columns = array_slice( array_merge( $columns, self::default_columns() ), 0, 3 );
        $rows    = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();

        wp_nonce_field( 'brz_compare_table_save', 'brz_compare_table_nonce' );
        ?>
        <div class="brz-compare-box" data-default-columns="<?php echo esc_attr( wp_json_encode( self::default_columns() ) ); ?>">
            <p>
                <label>
                    <input type="checkbox" name="brz_compare_enabled" value="1" <?php checked( true, $enabled ); ?> />
                    فعال‌سازی جدول برای این محصول
                </label>
            </p>
            <p>
                <label for="brz-compare-title"><strong>عنوان جدول (اختیاری)</strong></label><br />
                <input id="brz-compare-title" type="text" name="brz_compare_title" class="widefat" value="<?php echo esc_attr( $title ); ?>" />
            </p>

            <div class="brz-compare-section">
                <h4>ستون‌ها</h4>
                <p class="description">نام ستون‌ها را ویرایش کنید. حداقل سه ستون ثابت هستند.</p>
                <div class="brz-compare-columns" data-fixed="3">
                    <?php
                    for ( $i = 0; $i < 3; $i++ ) :
                        $value = isset( $columns[ $i ] ) ? $columns[ $i ] : '';
                        ?>
                        <input type="text" name="brz_compare_columns[]" value="<?php echo esc_attr( $value ); ?>" placeholder="ستون <?php echo esc_attr( $i + 1 ); ?>" class="regular-text" />
                    <?php endfor; ?>
                </div>
            </div>

            <div class="brz-compare-section">
                <h4>ردیف‌ها</h4>
                <p class="description">برای هر ردیف، مقدار سه ستون را وارد کنید. می‌توانید ردیف جدید اضافه یا حذف کنید.</p>
                <table class="widefat striped brz-compare-table" id="brz-compare-rows">
                    <thead>
                        <tr>
                            <?php foreach ( $columns as $col ) : ?>
                                <th><?php echo esc_html( $col ); ?></th>
                            <?php endforeach; ?>
                            <th style="width:90px;">حذف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ( empty( $rows ) ) {
                            $rows = array( array( '', '', '' ) );
                        }
                        foreach ( $rows as $r_index => $row ) :
                            $row = is_array( $row ) ? $row : array();
                            ?>
                            <tr>
                                <?php for ( $c = 0; $c < 3; $c++ ) : ?>
                                    <?php $cell = isset( $row[ $c ] ) ? $row[ $c ] : ''; ?>
                                    <td>
                                        <input type="text" name="brz_compare_rows[<?php echo esc_attr( $r_index ); ?>][<?php echo esc_attr( $c ); ?>]" value="<?php echo esc_attr( $cell ); ?>" class="widefat" />
                                    </td>
                                <?php endfor; ?>
                                <td><button type="button" class="button link-delete brz-compare-remove-row">&times;</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button button-secondary" id="brz-compare-add-row">افزودن ردیف</button></p>
            </div>

            <div class="brz-compare-section">
                <h4>راهنما</h4>
                <p>از توکن <code>[[COMPARE_TABLE]]</code> در توضیحات محصول استفاده کنید تا جدول دقیقاً در همان نقطه نمایش داده شود. در غیر این صورت جدول به انتهای توضیحات اضافه می‌شود.</p>
                <p class="description">پیش‌نمایش ساده:</p>
                <div class="brz-compare-preview" id="brz-compare-preview"></div>
            </div>
        </div>
        <?php
    }

    public static function save( $post_id, $post ) {
        if ( ! isset( $_POST['brz_compare_table_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['brz_compare_table_nonce'] ), 'brz_compare_table_save' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_product', $post_id ) ) {
            return;
        }

        $enabled = isset( $_POST['brz_compare_enabled'] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $title   = isset( $_POST['brz_compare_title'] ) ? sanitize_text_field( wp_unslash( $_POST['brz_compare_title'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $columns_raw = isset( $_POST['brz_compare_columns'] ) ? (array) wp_unslash( $_POST['brz_compare_columns'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $columns     = array();
        foreach ( $columns_raw as $col ) {
            $columns[] = sanitize_text_field( $col );
        }
        $columns = array_slice( $columns, 0, 3 ); // سه ستون ثابت
        $columns = array_pad( $columns, 3, '' );

        $rows_raw = isset( $_POST['brz_compare_rows'] ) ? (array) wp_unslash( $_POST['brz_compare_rows'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $rows     = array();
        foreach ( $rows_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean_row = array();
            for ( $i = 0; $i < 3; $i++ ) {
                $cell = isset( $row[ $i ] ) ? $row[ $i ] : '';
                $clean_row[] = sanitize_text_field( $cell );
            }
            // حداقل یک مقدار غیرخالی برای نگه‌داشتن ردیف لازم است
            $has_value = false;
            foreach ( $clean_row as $cell_value ) {
                if ( $cell_value !== '' ) {
                    $has_value = true;
                    break;
                }
            }
            if ( $has_value ) {
                $rows[] = $clean_row;
            }
        }

        if ( ! $enabled || empty( $rows ) ) {
            delete_post_meta( $post_id, self::META_KEY );
            return;
        }

        $payload = array(
            'enabled' => 1,
            'title'   => $title,
            'columns' => $columns,
            'rows'    => $rows,
        );

        update_post_meta( $post_id, self::META_KEY, wp_json_encode( $payload ) );
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

    private static function default_columns() {
        $saved = class_exists( 'BRZ_Settings' ) ? BRZ_Settings::get( 'compare_table_columns', array() ) : array();
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        $saved = array_filter( $saved, 'strlen' );
        if ( empty( $saved ) ) {
            return array( 'نام محصول مشابه', 'سبک', 'تمایز کلیدی' );
        }
        return array_slice( array_merge( $saved, array( 'نام محصول مشابه', 'سبک', 'تمایز کلیدی' ) ), 0, 3 );
    }
}
