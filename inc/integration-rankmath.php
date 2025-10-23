<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_RankMath_Integration {
    public static function init() {
        if ( ! class_exists( '\RankMath\Schema\DB' ) ) {
            return;
        }

        add_filter( 'rank_math/snippet/html', array( __CLASS__, 'replace_faq_markup' ), 10, 4 );
    }

    public static function replace_faq_markup( $html, $schema, $post, $shortcode ) {
        $types = array();

        if ( is_admin() && ! wp_doing_ajax() && ! wp_doing_rest() && ! wp_doing_cron() ) {
            return $html;
        }

        if ( ! isset( $schema['@type'] ) ) {
            return $html;
        }

        if ( is_array( $schema['@type'] ) ) {
            $types = array_map( 'strtolower', $schema['@type'] );
        } else {
            $types[] = strtolower( (string) $schema['@type'] );
        }

        if ( ! in_array( 'faqpage', $types, true ) ) {
            return $html;
        }

        if ( empty( $schema['mainEntity'] ) || ! is_array( $schema['mainEntity'] ) ) {
            return $html;
        }

        $items = array();

        foreach ( $schema['mainEntity'] as $entity ) {
            if ( ! is_array( $entity ) ) {
                continue;
            }

            if ( ! isset( $entity['@type'] ) || strtolower( (string) $entity['@type'] ) !== 'question' ) {
                continue;
            }

            $question = isset( $entity['name'] ) ? $entity['name'] : '';
            $answer   = isset( $entity['acceptedAnswer']['text'] ) ? $entity['acceptedAnswer']['text'] : '';

            if ( empty( $question ) || empty( $answer ) ) {
                continue;
            }

            $id = '';
            if ( ! empty( $entity['url'] ) ) {
                $parts = wp_parse_url( $entity['url'] );
                if ( ! empty( $parts['fragment'] ) ) {
                    $id = sanitize_html_class( $parts['fragment'] );
                }
            }

            $items[] = array(
                'question' => $question,
                'answer'   => $answer,
                'id'       => $id,
            );
        }

        if ( empty( $items ) ) {
            return $html;
        }

        ob_start();
        ?>
        <div class="rank-math-faq">
            <?php foreach ( $items as $item ) : ?>
                <div class="rank-math-faq-item" <?php echo $item['id'] ? 'id="' . esc_attr( $item['id'] ) . '"' : ''; ?>>
                    <div class="rank-math-question"><?php echo wp_kses_post( $item['question'] ); ?></div>
                    <div class="rank-math-answer"><?php echo wp_kses_post( $item['answer'] ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        $output = ob_get_clean();

        /**
         * Allow developers to filter the generated FAQ markup.
         *
         * @param string $output Generated HTML.
         * @param array  $items  Sanitized FAQ items.
         * @param array  $schema Original schema array.
         */
        return apply_filters( 'rfa/rankmath/faq_markup', $output, $items, $schema );
    }
}

RFA_RankMath_Integration::init();
