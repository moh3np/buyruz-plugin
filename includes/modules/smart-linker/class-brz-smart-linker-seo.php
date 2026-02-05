<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Smart Linker SEO Integration - RankMath Pro integration.
 *
 * Extracts focus keywords and SEO data for enhanced link suggestions.
 */
class BRZ_Smart_Linker_SEO {

    /**
     * Check if RankMath is available.
     *
     * @return bool
     */
    public static function has_rankmath() {
        return class_exists( 'RankMath' );
    }

    /**
     * Get focus keyword for a post.
     *
     * @param int $post_id
     * @return string
     */
    public static function get_focus_keyword( $post_id ) {
        if ( ! self::has_rankmath() ) {
            return '';
        }

        $keyword = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        return $keyword ? sanitize_text_field( $keyword ) : '';
    }

    /**
     * Get secondary/pillar keywords for a post.
     *
     * @param int $post_id
     * @return array
     */
    public static function get_secondary_keywords( $post_id ) {
        if ( ! self::has_rankmath() ) {
            return array();
        }

        // RankMath stores multiple focus keywords comma-separated
        $keywords = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        
        if ( ! $keywords ) {
            return array();
        }

        $parts = array_filter( array_map( 'trim', explode( ',', $keywords ) ) );
        
        // First one is primary, rest are secondary
        if ( count( $parts ) > 1 ) {
            array_shift( $parts );
            return $parts;
        }

        return array();
    }

    /**
     * Check if a post/term is noindex.
     *
     * @param int    $object_id
     * @param string $object_type 'post' or 'term'
     * @return bool
     */
    public static function is_noindex( $object_id, $object_type = 'post' ) {
        if ( ! self::has_rankmath() ) {
            return false;
        }

        if ( 'term' === $object_type ) {
            $robots = get_term_meta( $object_id, 'rank_math_robots', true );
        } else {
            $robots = get_post_meta( $object_id, 'rank_math_robots', true );
        }

        if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get SEO score for a post.
     *
     * @param int $post_id
     * @return int 0-100
     */
    public static function get_seo_score( $post_id ) {
        if ( ! self::has_rankmath() ) {
            return 0;
        }

        $score = get_post_meta( $post_id, 'rank_math_seo_score', true );
        return $score ? absint( $score ) : 0;
    }

    /**
     * Get internal link count for a post (RankMath analysis).
     *
     * @param int $post_id
     * @return int
     */
    public static function get_internal_link_count( $post_id ) {
        if ( ! self::has_rankmath() ) {
            return 0;
        }

        $internal = get_post_meta( $post_id, 'rank_math_internal_links_count', true );
        return $internal ? absint( $internal ) : 0;
    }

    /**
     * Collect all SEO data for a post.
     *
     * @param int $post_id
     * @return array
     */
    public static function collect_seo_data( $post_id ) {
        return array(
            'focus_keyword'       => self::get_focus_keyword( $post_id ),
            'secondary_keywords'  => self::get_secondary_keywords( $post_id ),
            'is_noindex'          => self::is_noindex( $post_id, 'post' ),
            'seo_score'           => self::get_seo_score( $post_id ),
            'internal_link_count' => self::get_internal_link_count( $post_id ),
        );
    }

    /**
     * Get posts that need more internal links.
     *
     * @param int $min_links Minimum links threshold
     * @param int $limit
     * @return array Post IDs
     */
    public static function get_posts_needing_links( $min_links = 3, $limit = 50 ) {
        if ( ! self::has_rankmath() ) {
            return array();
        }

        global $wpdb;

        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = 'rank_math_internal_links_count'
            WHERE p.post_type IN ('post', 'page', 'product')
            AND p.post_status = 'publish'
            AND (m.meta_value IS NULL OR CAST(m.meta_value AS UNSIGNED) < %d)
            ORDER BY p.post_date DESC
            LIMIT %d",
            $min_links,
            $limit
        ) );

        return array_map( 'absint', $results );
    }

    /**
     * Get high-value content by SEO score.
     *
     * @param int $min_score
     * @param int $limit
     * @return array
     */
    public static function get_high_value_content( $min_score = 70, $limit = 50 ) {
        if ( ! self::has_rankmath() ) {
            return array();
        }

        global $wpdb;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, m.meta_value as seo_score
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = 'rank_math_seo_score'
            WHERE p.post_type IN ('post', 'page', 'product')
            AND p.post_status = 'publish'
            AND CAST(m.meta_value AS UNSIGNED) >= %d
            ORDER BY CAST(m.meta_value AS UNSIGNED) DESC
            LIMIT %d",
            $min_score,
            $limit
        ), ARRAY_A );

        return $results;
    }

    /**
     * Get all focus keywords in the site.
     *
     * @return array Keyword => Post ID map
     */
    public static function get_focus_keywords_map() {
        if ( ! self::has_rankmath() ) {
            return array();
        }

        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT p.ID, p.post_type, m.meta_value as focus_keyword
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = 'rank_math_focus_keyword'
            WHERE p.post_status = 'publish'
            AND m.meta_value IS NOT NULL
            AND m.meta_value != ''",
            ARRAY_A
        );

        $map = array();
        foreach ( $results as $row ) {
            // Handle multiple keywords (comma-separated)
            $keywords = array_filter( array_map( 'trim', explode( ',', $row['focus_keyword'] ) ) );
            foreach ( $keywords as $kw ) {
                $kw = mb_strtolower( $kw, 'UTF-8' );
                if ( ! isset( $map[ $kw ] ) ) {
                    $map[ $kw ] = array();
                }
                $map[ $kw ][] = array(
                    'post_id'   => (int) $row['ID'],
                    'post_type' => $row['post_type'],
                );
            }
        }

        return $map;
    }
}
