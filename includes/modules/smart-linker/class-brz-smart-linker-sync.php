<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Smart Linker Sync - REST API endpoints for peer-to-peer synchronization.
 *
 * Enables two sites (Shop & Blog) to share their content index.
 */
class BRZ_Smart_Linker_Sync {

    /**
     * Initialize REST routes.
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register REST API endpoints.
     */
    public static function register_routes() {
        // Get local content index (for peer to fetch)
        register_rest_route( 'brz/v1', '/linker/inventory', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'get_inventory' ),
            'permission_callback' => array( __CLASS__, 'check_api_key' ),
        ) );

        // Receive content index from peer
        register_rest_route( 'brz/v1', '/linker/sync', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'receive_sync' ),
            'permission_callback' => array( __CLASS__, 'check_api_key' ),
        ) );

        // Push approved links to peer for application
        register_rest_route( 'brz/v1', '/linker/apply-links', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'apply_remote_links' ),
            'permission_callback' => array( __CLASS__, 'check_api_key' ),
        ) );
    }

    /**
     * Verify API key from request.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function check_api_key( WP_REST_Request $request ) {
        $settings = BRZ_Smart_Linker::get_settings();
        $local_key = isset( $settings['local_api_key'] ) ? $settings['local_api_key'] : '';

        if ( empty( $local_key ) ) {
            return false;
        }

        $provided = $request->get_param( 'api_key' );
        if ( empty( $provided ) ) {
            $provided = $request->get_header( 'X-BRZ-API-Key' );
        }

        return hash_equals( $local_key, (string) $provided );
    }

    /**
     * Get local content inventory.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_inventory( WP_REST_Request $request ) {
        $settings = BRZ_Smart_Linker::get_settings();
        $site_role = isset( $settings['site_role'] ) ? $settings['site_role'] : 'shop';

        // Refresh local index before sending
        self::refresh_local_index();

        // Get local content
        $content = BRZ_Smart_Linker_DB::get_content_index( 'local' );

        return rest_ensure_response( array(
            'success'   => true,
            'site_role' => $site_role,
            'site_url'  => home_url(),
            'count'     => count( $content ),
            'items'     => $content,
        ) );
    }

    /**
     * Receive and store content from peer site.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function receive_sync( WP_REST_Request $request ) {
        $body = $request->get_json_params();

        if ( empty( $body['items'] ) || ! is_array( $body['items'] ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'No items provided',
            ), 400 );
        }

        $site_id = isset( $body['site_id'] ) ? sanitize_key( $body['site_id'] ) : 'peer';

        // Clear old data for this site
        BRZ_Smart_Linker_DB::clear_content_index( $site_id );

        // Insert new data
        $count = 0;
        foreach ( $body['items'] as $item ) {
            $item['site_id'] = $site_id;
            if ( BRZ_Smart_Linker_DB::upsert_content( $item ) ) {
                $count++;
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => sprintf( 'Received %d items from %s', $count, $site_id ),
            'count'   => $count,
        ) );
    }

    /**
     * Apply links received from peer.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function apply_remote_links( WP_REST_Request $request ) {
        $body = $request->get_json_params();

        if ( empty( $body['links'] ) || ! is_array( $body['links'] ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'No links provided',
            ), 400 );
        }

        $settings = BRZ_Smart_Linker::get_settings();
        $applied = 0;

        foreach ( $body['links'] as $link ) {
            $post_id = isset( $link['source_id'] ) ? absint( $link['source_id'] ) : 0;
            $post = get_post( $post_id );

            if ( ! $post ) {
                continue;
            }

            $injector = new BRZ_Smart_Linker_Link_Injector(
                $post_id,
                $post->post_content,
                $post->post_type,
                $settings
            );

            $result = $injector->inject( array( $link ) );

            if ( $result['changed'] ) {
                wp_update_post( array(
                    'ID'           => $post_id,
                    'post_content' => $result['content'],
                ) );
                $applied++;
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => sprintf( 'Applied %d links', $applied ),
            'applied' => $applied,
        ) );
    }

    /**
     * Refresh local content index from WordPress posts.
     */
    public static function refresh_local_index() {
        $settings = BRZ_Smart_Linker::get_settings();
        $site_role = isset( $settings['site_role'] ) ? $settings['site_role'] : 'shop';

        // Get post types to index
        $post_types = array( 'post', 'page' );
        if ( 'shop' === $site_role && post_type_exists( 'product' ) ) {
            $post_types[] = 'product';
        }

        // Query all published content
        $posts = get_posts( array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) );

        foreach ( $posts as $post ) {
            $data = self::build_content_data( $post, $site_role );
            BRZ_Smart_Linker_DB::upsert_content( $data );
        }

        // Index product categories if shop
        if ( 'shop' === $site_role && taxonomy_exists( 'product_cat' ) ) {
            self::index_terms( 'product_cat', $site_role );
        }

        // Index post tags
        self::index_terms( 'post_tag', $site_role );
    }

    /**
     * Build content data array from a post.
     *
     * @param WP_Post $post
     * @param string  $site_role
     * @return array
     */
    private static function build_content_data( WP_Post $post, $site_role ) {
        $content = wp_strip_all_tags( $post->post_content );
        $word_count = str_word_count( $content );
        $excerpt = mb_substr( $content, 0, 1000, 'UTF-8' );

        // Get categories
        $taxonomy = 'product' === $post->post_type ? 'product_cat' : 'category';
        $terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'all' ) );
        $cat_ids = array();
        $cat_names = array();

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $cat_ids[] = $term->term_id;
                $cat_names[] = $term->name;
            }
        }

        // RankMath focus keyword
        $focus_keyword = '';
        $secondary_keywords = array();

        if ( class_exists( 'RankMath' ) ) {
            $focus_keyword = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
            $secondary = get_post_meta( $post->ID, 'rank_math_pillar_content', true );
            if ( $secondary ) {
                $secondary_keywords = array_filter( array_map( 'trim', explode( ',', $secondary ) ) );
            }
        }

        // Stock status for products
        $stock_status = '';
        $price = '';
        if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $stock_status = $product->get_stock_status();
                $price = $product->get_price();
            }
        }

        // Determine if linkable (noindex check)
        $is_linkable = 1;
        if ( class_exists( 'RankMath' ) ) {
            $robots = get_post_meta( $post->ID, 'rank_math_robots', true );
            if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
                $is_linkable = 0;
            }
        }

        return array(
            'site_id'            => 'local',
            'post_id'            => $post->ID,
            'post_type'          => $post->post_type,
            'title'              => get_the_title( $post ),
            'url'                => get_permalink( $post ),
            'category_ids'       => $cat_ids,
            'category_names'     => $cat_names,
            'focus_keyword'      => $focus_keyword,
            'secondary_keywords' => $secondary_keywords,
            'content_excerpt'    => $excerpt,
            'word_count'         => $word_count,
            'is_linkable'        => $is_linkable,
            'stock_status'       => $stock_status,
            'price'              => $price,
        );
    }

    /**
     * Index taxonomy terms (categories, tags).
     *
     * @param string $taxonomy
     * @param string $site_role
     */
    private static function index_terms( $taxonomy, $site_role ) {
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
        ) );

        if ( is_wp_error( $terms ) ) {
            return;
        }

        foreach ( $terms as $term ) {
            // Check if noindex (blog categories are noindex)
            $is_linkable = 1;
            if ( 'category' === $taxonomy ) {
                $is_linkable = 0; // Blog categories are noindex per user requirement
            }

            if ( class_exists( 'RankMath' ) ) {
                $robots = get_term_meta( $term->term_id, 'rank_math_robots', true );
                if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
                    $is_linkable = 0;
                }
            }

            $data = array(
                'site_id'            => 'local',
                'post_id'            => $term->term_id,
                'post_type'          => 'term_' . $taxonomy,
                'title'              => $term->name,
                'url'                => get_term_link( $term ),
                'category_ids'       => array( $term->term_id ),
                'category_names'     => array( $term->name ),
                'focus_keyword'      => '',
                'secondary_keywords' => array(),
                'content_excerpt'    => $term->description,
                'word_count'         => str_word_count( $term->description ),
                'is_linkable'        => $is_linkable,
                'stock_status'       => '',
                'price'              => '',
            );

            BRZ_Smart_Linker_DB::upsert_content( $data );
        }
    }

    /**
     * Fetch and store content from peer site.
     *
     * @return array|WP_Error
     */
    public static function sync_from_peer() {
        $settings = BRZ_Smart_Linker::get_settings();

        if ( empty( $settings['remote_endpoint'] ) || empty( $settings['remote_api_key'] ) ) {
            return new WP_Error( 'no_peer', 'Remote endpoint or API key not configured' );
        }

        $url = trailingslashit( $settings['remote_endpoint'] ) . 'wp-json/brz/v1/linker/inventory';
        $url = add_query_arg( 'api_key', $settings['remote_api_key'], $url );

        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['success'] ) || empty( $body['items'] ) ) {
            return new WP_Error( 'invalid_response', 'Invalid response from peer' );
        }

        $site_id = isset( $body['site_role'] ) ? $body['site_role'] : 'peer';

        // Clear and repopulate
        BRZ_Smart_Linker_DB::clear_content_index( $site_id );

        $count = 0;
        foreach ( $body['items'] as $item ) {
            $item['site_id'] = $site_id;
            if ( BRZ_Smart_Linker_DB::upsert_content( $item ) ) {
                $count++;
            }
        }

        return array(
            'success' => true,
            'count'   => $count,
            'site_id' => $site_id,
        );
    }

    /**
     * AJAX handler for syncing from peer site.
     */
    public static function ajax_sync_from_peer() {
        check_ajax_referer( 'brz_smart_linker_export' );

        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        $result = self::sync_from_peer();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => sprintf( 'سینک موفق: %d آیتم از %s دریافت شد.', $result['count'], $result['site_id'] ),
            'count'   => $result['count'],
        ) );
    }
}
