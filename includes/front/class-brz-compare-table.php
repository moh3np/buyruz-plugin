<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Compare_Table {
    const META_KEY = '_buyruz_compare_table';
    const META_ID_KEY = '_buyruz_compare_table_id';
    const MIN_COLUMNS = 1;
    const MAX_COLUMNS = 6;
    private static $cache = array();
    private static $rendered = array();

    public static function init() {
        add_filter( 'the_content', array( __CLASS__, 'inject_into_content' ), 25 );
        add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'inject_into_wc_description' ), 25, 2 );
        add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'render_after_summary' ), 25 );
        add_shortcode( 'buyruz_compare_table', array( __CLASS__, 'shortcode' ) );
        add_shortcode( 'brz_compare_table', array( __CLASS__, 'shortcode' ) );
    }

    public static function has_table( $post_id ) {
        $data = self::get_table_data( $post_id );
        return ! empty( $data['rows'] );
    }

    public static function get_table_id( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return '';
        }

        $existing   = get_post_meta( $post_id, self::META_ID_KEY, true );
        $normalized = self::normalize_table_id( $existing, $post_id );

        if ( $normalized !== $existing ) {
            update_post_meta( $post_id, self::META_ID_KEY, $normalized );
        }

        return $normalized;
    }

    private static function normalize_table_id( $value, $post_id ) {
        $value = is_string( $value ) ? $value : '';
        $value = preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
        if ( empty( $value ) ) {
            $value = 'brz-ct-' . absint( $post_id );
        }
        return $value;
    }

    private static function product_id_from_table_id( $value ) {
        if ( is_numeric( $value ) ) {
            return absint( $value );
        }

        if ( is_string( $value ) && preg_match( '/(\\d+)/', $value, $m ) ) {
            return absint( $m[1] );
        }

        return 0;
    }

    private static function get_table_data( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) { return array(); }

        $table_id = self::get_table_id( $post_id );

        if ( isset( self::$cache[ $post_id ] ) ) {
            return self::$cache[ $post_id ];
        }

        $raw = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $raw ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $decoded = is_array( $raw ) ? $raw : json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE && ! is_array( $raw ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        if ( ! is_array( $decoded ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $rows_raw = isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ? $decoded['rows'] : array();
        if ( empty( $rows_raw ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $enabled = array_key_exists( 'enabled', $decoded ) ? (bool) $decoded['enabled'] : true;
        if ( ! $enabled ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $columns = array();
        if ( isset( $decoded['columns'] ) && is_array( $decoded['columns'] ) ) {
            foreach ( $decoded['columns'] as $col ) {
                $columns[] = is_string( $col ) ? $col : '';
            }
        }
        $columns      = array_values( array_slice( $columns, 0, self::MAX_COLUMNS ) );
        $column_count = min( max( count( $columns ), self::MIN_COLUMNS ), self::MAX_COLUMNS );
        $first_row    = reset( $rows_raw );
        $row_width    = is_array( $first_row ) ? count( $first_row ) : 0;
        if ( $row_width > $column_count ) {
            $column_count = min( $row_width, self::MAX_COLUMNS );
        }
        if ( $column_count > count( $columns ) ) {
            $columns = array_pad( $columns, $column_count, '' );
        }

        $rows = array();
        foreach ( $rows_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean = array();
            for ( $i = 0; $i < $column_count; $i++ ) {
                $cell = isset( $row[ $i ] ) ? $row[ $i ] : '';
                $clean[] = is_string( $cell ) ? $cell : '';
            }
            if ( array_filter( $clean, 'strlen' ) ) {
                $rows[] = $clean;
            }
        }

        if ( empty( $rows ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $title = isset( $decoded['title'] ) ? $decoded['title'] : '';
        if ( empty( $title ) && class_exists( 'BRZ_Settings' ) ) {
            $fallback_title = BRZ_Settings::get( 'compare_table_default_title', '' );
            if ( ! empty( $fallback_title ) ) {
                $title = $fallback_title;
            }
        }

        self::$cache[ $post_id ] = array(
            'id'      => $table_id,
            'title'   => $title,
            'columns' => $columns,
            'rows'    => $rows,
        );

        return self::$cache[ $post_id ];
    }

    public static function inject_into_wc_description( $content, $product ) {
        if ( ! is_singular( 'product' ) ) {
            return $content;
        }
        $post_id = $product ? $product->get_id() : 0;
        return self::maybe_inject( $content, $post_id );
    }

    public static function inject_into_content( $content ) {
        if ( ! is_singular( 'product' ) ) {
            return $content;
        }

        $post_id = get_the_ID();
        return self::maybe_inject( $content, $post_id );
    }

    private static function maybe_inject( $content, $post_id ) {
        $data = self::get_table_data( $post_id );
        if ( empty( $data ) ) {
            return $content;
        }

        $html = self::render_table( $data );
        if ( empty( $html ) ) {
            return $content;
        }

        self::$rendered[ $post_id ] = true;

        if ( strpos( $content, '[[COMPARE_TABLE]]' ) !== false ) {
            $content = str_replace( '[[COMPARE_TABLE]]', $html, $content );
        } else {
            $content .= $html;
        }

        return $content;
    }

    private static function render_table( $data ) {
        if ( empty( $data['rows'] ) || empty( $data['columns'] ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="buyruz-table-wrap">
            <?php if ( ! empty( $data['title'] ) ) : ?>
                <h3 class="buyruz-table-title"><?php echo esc_html( $data['title'] ); ?></h3>
            <?php endif; ?>
            <table class="buyruz-table">
                <thead>
                    <tr>
                        <?php foreach ( $data['columns'] as $col ) : ?>
                            <th><?php echo esc_html( $col ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $data['rows'] as $row ) : ?>
                        <tr>
                            <?php foreach ( $data['columns'] as $index => $col ) : ?>
                                <td data-label="<?php echo esc_attr( $data['columns'][ $index ] ); ?>"><?php echo esc_html( isset( $row[ $index ] ) ? $row[ $index ] : '' ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'id'         => '',
                'product_id' => '',
            ),
            $atts,
            'buyruz_compare_table'
        );

        $post_id = 0;
        if ( ! empty( $atts['product_id'] ) ) {
            $post_id = absint( $atts['product_id'] );
        }

        if ( ! $post_id && ! empty( $atts['id'] ) ) {
            $post_id = self::product_id_from_table_id( $atts['id'] );
        }

        if ( ! $post_id ) {
            return '';
        }

        $expected_id = self::get_table_id( $post_id );
        if ( ! empty( $atts['id'] ) ) {
            $input_id = self::normalize_table_id( $atts['id'], $post_id );
            if ( $expected_id && $input_id && $input_id !== $expected_id ) {
                return '';
            }
        }

        $data = self::get_table_data( $post_id );
        if ( empty( $data ) ) {
            return '';
        }

        return self::render_table( $data );
    }

    public static function render_after_summary() {
        if ( ! is_singular( 'product' ) ) {
            return;
        }

        $post_id = get_the_ID();
        if ( isset( self::$rendered[ $post_id ] ) ) {
            return;
        }

        $data = self::get_table_data( $post_id );
        if ( empty( $data ) ) {
            return;
        }

        $html = self::render_table( $data );
        if ( empty( $html ) ) {
            return;
        }

        self::$rendered[ $post_id ] = true;
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
