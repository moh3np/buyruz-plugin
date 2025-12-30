<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Compare_Table {
    const META_KEY = '_buyruz_compare_table';
    private static $cache = array();
    private static $rendered = array();

    public static function init() {
        add_filter( 'the_content', array( __CLASS__, 'inject_into_content' ), 25 );
        add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'inject_into_wc_description' ), 25, 2 );
    }

    public static function has_table( $post_id ) {
        $data = self::get_table_data( $post_id );
        return ! empty( $data['rows'] );
    }

    private static function get_table_data( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) { return array(); }

        if ( isset( self::$cache[ $post_id ] ) ) {
            return self::$cache[ $post_id ];
        }

        $raw = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $raw ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        if ( empty( $decoded['enabled'] ) || empty( $decoded['rows'] ) || ! is_array( $decoded['rows'] ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $rows = array();
        foreach ( $decoded['rows'] as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean = array();
            foreach ( $row as $cell ) {
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

        $columns = array();
        if ( isset( $decoded['columns'] ) && is_array( $decoded['columns'] ) ) {
            foreach ( $decoded['columns'] as $col ) {
                $columns[] = is_string( $col ) ? $col : '';
            }
        }
        $columns = array_slice( $columns, 0, 3 );
        if ( count( $columns ) < 3 ) {
            $columns = array_merge( $columns, array_fill( 0, 3 - count( $columns ), '' ) );
        }
        if ( empty( array_filter( $columns, 'strlen' ) ) ) {
            $columns = array( 'نام محصول مشابه', 'سبک', 'تمایز کلیدی' );
        }

        $title = isset( $decoded['title'] ) ? $decoded['title'] : '';

        self::$cache[ $post_id ] = array(
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

        if ( isset( self::$rendered[ $post_id ] ) ) {
            return $content;
        }

        $html = self::render_table( $data );
        if ( empty( $html ) ) {
            return $content;
        }

        if ( strpos( $content, '[[COMPARE_TABLE]]' ) !== false ) {
            $content = str_replace( '[[COMPARE_TABLE]]', $html, $content );
        } else {
            $content .= $html;
        }

        self::$rendered[ $post_id ] = true;
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
                                <td><?php echo esc_html( isset( $row[ $index ] ) ? $row[ $index ] : '' ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
