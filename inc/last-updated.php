<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_Last_Updated {
    const SLUG = 'rm-faq-accordion';

    public static function init() {
        add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_last_updated' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
        add_action( 'admin_init', array( __CLASS__, 'cleanup_legacy_updater_data' ) );
    }

    public static function inject_last_updated( $transient ) {
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = array();
        }

        $last_updated = self::get_last_updated();
        if ( ! $last_updated ) {
            return $transient;
        }

        $plugin_file = self::plugin_basename();

        $transient->no_update[ $plugin_file ] = (object) array(
            'slug'         => self::SLUG,
            'plugin'       => $plugin_file,
            'new_version'  => defined( 'RFA_VERSION' ) ? RFA_VERSION : '',
            'last_updated' => $last_updated,
            'package'      => null,
        );

        return $transient;
    }

    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
            return $result;
        }

        $info = new stdClass();
        $info->name          = 'تنظیمات بایروز';
        $info->slug          = self::SLUG;
        $info->version       = defined( 'RFA_VERSION' ) ? RFA_VERSION : '';
        $info->author        = 'کُدروز';
        $info->requires      = '5.8';
        $info->tested        = get_bloginfo( 'version' );
        $info->last_updated  = self::get_last_updated();
        $info->sections      = array(
            'description' => wpautop( 'تنظیمات بایروز مرکز هماهنگی و مدیریت قابلیت‌های اختصاصی بایروز است و از این صفحه کنترل می‌شود.' ),
        );
        $info->download_link = '';

        return $info;
    }

    private static function get_last_updated() {
        $path = RFA_PATH . 'rm-faq-accordion.php';
        $mtime = @filemtime( $path );
        if ( ! $mtime ) {
            return '';
        }

        return gmdate( 'Y-m-d H:i:s', $mtime );
    }

    public static function cleanup_legacy_updater_data() {
        delete_transient( 'rfa_remote_meta' );
        delete_transient( 'rfa_update_error' );
        delete_transient( 'rfa_token_expiry' );
    }

    private static function plugin_basename() {
        return plugin_basename( RFA_PATH . 'rm-faq-accordion.php' );
    }
}
