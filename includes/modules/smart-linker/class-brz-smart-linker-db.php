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
