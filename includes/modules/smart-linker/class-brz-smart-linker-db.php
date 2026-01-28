<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Persistence layer for Smart Internal Linking.
 *
 * Responsibility:
 * - Table name resolution
 * - Schema creation / migrations
 * - CRUD helpers with strict sanitization
 */
class BRZ_Smart_Linker_DB {
    const TABLE_SUFFIX = 'smart_links_log';
    const CACHE_SUFFIX = 'buyruz_remote_cache';

    /**
     * Return fully-qualified table name with WordPress prefix.
     *
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Create or upgrade the log table.
     * Uses dbDelta to stay compatible across environments.
     */
    public static function migrate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table           = self::table();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            keyword varchar(191) NOT NULL,
            target_url text NOT NULL,
            fingerprint varchar(191) NOT NULL,
            status ENUM('pending','approved','active','user_deleted','manual_override') NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            UNIQUE KEY fingerprint (fingerprint)
        ) {$charset_collate};";

        dbDelta( $sql );

        // Remote cache table for peer-to-peer sync
        $cache = self::cache_table();
        $sql_cache = "CREATE TABLE {$cache} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            remote_id bigint(20) unsigned NOT NULL,
            type varchar(20) NOT NULL,
            title varchar(255) NOT NULL,
            url text NOT NULL,
            categories text NULL,
            stock_status varchar(40) NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            UNIQUE KEY remote_unique (remote_id,type)
        ) {$charset_collate};";
        dbDelta( $sql_cache );
    }

    /**
     * Insert or update (by fingerprint) a suggestion row.
     *
     * @param array $data associative array: post_id, keyword, target_url, fingerprint, status.
     * @return int|false Inserted row ID or existing row ID.
     */
    public static function upsert( array $data ) {
        global $wpdb;
        $defaults = array(
            'post_id'     => 0,
            'keyword'     => '',
            'target_url'  => '',
            'fingerprint' => '',
            'status'      => 'pending',
        );

        $data = wp_parse_args( $data, $defaults );

        if ( empty( $data['fingerprint'] ) ) {
            return false;
        }

        $table = self::table();

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE fingerprint = %s",
                $data['fingerprint']
            )
        );

        if ( $existing_id ) {
            $wpdb->update(
                $table,
                array(
                    'post_id'    => (int) $data['post_id'],
                    'keyword'    => sanitize_text_field( $data['keyword'] ),
                    'target_url' => esc_url_raw( $data['target_url'] ),
                    'status'     => self::normalize_status( $data['status'] ),
                ),
                array( 'id' => (int) $existing_id ),
                array( '%d', '%s', '%s', '%s' ),
                array( '%d' )
            );
            return (int) $existing_id;
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'post_id'     => (int) $data['post_id'],
                'keyword'     => sanitize_text_field( $data['keyword'] ),
                'target_url'  => esc_url_raw( $data['target_url'] ),
                'fingerprint' => sanitize_text_field( $data['fingerprint'] ),
                'status'      => self::normalize_status( $data['status'] ),
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Fetch rows by status.
     *
     * @param array $statuses
     * @return array
     */
    public static function get_by_status( array $statuses ) {
        global $wpdb;
        $table = self::table();
        $where = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $sql = "SELECT * FROM {$table} WHERE status IN ({$where}) ORDER BY created_at ASC";
        $prepared = $wpdb->prepare( $sql, $statuses );

        return $wpdb->get_results( $prepared, ARRAY_A );
    }

    /**
     * Fetch rows for a specific post by status.
     *
     * @param int   $post_id
     * @param array $statuses
     * @return array
     */
    public static function get_for_post( $post_id, array $statuses ) {
        global $wpdb;
        $table = self::table();
        $where = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $sql = "SELECT * FROM {$table} WHERE post_id = %d AND status IN ({$where})";
        $prepared = $wpdb->prepare(
            $sql,
            array_merge( array( (int) $post_id ), $statuses )
        );

        return $wpdb->get_results( $prepared, ARRAY_A );
    }

    /**
     * Update status for provided IDs.
     *
     * @param array  $ids
     * @param string $status
     * @return int rows updated
     */
    public static function set_status( array $ids, $status ) {
        if ( empty( $ids ) ) {
            return 0;
        }

        global $wpdb;
        $table   = self::table();
        $ids_sql = implode( ',', array_map( 'intval', $ids ) );
        $status  = self::normalize_status( $status );

        return $wpdb->query( "UPDATE {$table} SET status = '{$status}' WHERE id IN ({$ids_sql})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Update status for provided fingerprints.
     *
     * @param array  $fingerprints
     * @param string $status
     */
    public static function set_status_by_fingerprint( array $fingerprints, $status ) {
        global $wpdb;
        if ( empty( $fingerprints ) ) {
            return;
        }

        $table  = self::table();
        $status = self::normalize_status( $status );

        $placeholders = implode( ',', array_fill( 0, count( $fingerprints ), '%s' ) );
        $sql          = "UPDATE {$table} SET status = %s WHERE fingerprint IN ({$placeholders})";
        $params       = array_merge( array( $status ), $fingerprints );

        $wpdb->query( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Return active links for the given post ID.
     *
     * @param int $post_id
     * @return array
     */
    public static function active_for_post( $post_id ) {
        return self::get_for_post( $post_id, array( 'active' ) );
    }

    /**
     * Cache table name with prefix.
     */
    public static function cache_table() {
        global $wpdb;
        return $wpdb->prefix . self::CACHE_SUFFIX;
    }

    /**
     * Replace cache rows for a type in bulk.
     *
     * @param string $type product|post
     * @param array  $items
     */
    public static function replace_cache( $type, array $items ) {
        global $wpdb;
        $table = self::cache_table();
        $type  = sanitize_key( $type );

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE type = %s", $type ) );

        foreach ( $items as $item ) {
            $wpdb->insert(
                $table,
                array(
                    'remote_id'    => isset( $item['remote_id'] ) ? (int) $item['remote_id'] : 0,
                    'type'         => $type,
                    'title'        => sanitize_text_field( isset( $item['title'] ) ? $item['title'] : '' ),
                    'url'          => esc_url_raw( isset( $item['url'] ) ? $item['url'] : '' ),
                    'categories'   => isset( $item['categories'] ) ? maybe_serialize( $item['categories'] ) : '',
                    'stock_status' => sanitize_text_field( isset( $item['stock_status'] ) ? $item['stock_status'] : '' ),
                    'updated_at'   => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
        }
    }

    /**
     * Fetch cache rows with optional keyword filter.
     */
    public static function search_cache( $type, $keyword = '', $limit = 20 ) {
        global $wpdb;
        $table = self::cache_table();
        $type  = sanitize_key( $type );
        $limit = (int) $limit;
        if ( $limit < 1 ) { $limit = 20; }

        if ( $keyword ) {
            $like = '%' . $wpdb->esc_like( $keyword ) . '%';
            $sql  = $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND title LIKE %s ORDER BY updated_at DESC LIMIT %d", $type, $like, $limit );
        } else {
            $sql  = $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY updated_at DESC LIMIT %d", $type, $limit );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Restrict status to allowed ENUM values.
     *
     * @param string $status
     * @return string
     */
    private static function normalize_status( $status ) {
        $status = sanitize_key( $status );
        $allowed = class_exists( 'BRZ_Smart_Linker' ) ? BRZ_Smart_Linker::statuses() : array( 'pending', 'approved', 'active', 'user_deleted', 'manual_override' );
        if ( in_array( $status, $allowed, true ) ) {
            return $status;
        }
        return 'pending';
    }
}
