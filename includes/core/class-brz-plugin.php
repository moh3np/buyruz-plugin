<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Plugin {
    /**
     * Bootstrap core modules.
     */
    public static function init() {
        if ( ! BRZ_Guard::ready() ) {
            return;
        }
        self::cleanup_legacy_update_artifacts();
        self::migrate_legacy_options();
        foreach ( self::modules() as $class ) {
            if ( class_exists( $class ) && method_exists( $class, 'init' ) ) {
                call_user_func( array( $class, 'init' ) );
            }
        }
    }

    /**
     * Declarative list of modules to load.
     *
     * @return string[]
     */
    private static function modules() {
        $modules = array( 'BRZ_Settings', 'BRZ_Tag_Sync_Guard', 'BRZ_Compare_Table_Admin', 'BRZ_Compare_Table', 'BRZ_Rest' );

        $active = BRZ_Modules::active_classes();
        if ( ! empty( $active ) ) {
            $modules = array_merge( $modules, $active );
        }

        return (array) apply_filters( 'brz/modules', $modules );
    }

    /**
     * Remove leftovers from the old GitHub update system.
     */
    private static function cleanup_legacy_update_artifacts() {
        delete_transient( 'brz_remote_meta' );
        delete_transient( 'brz_update_error' );
        delete_transient( 'brz_token_expiry' );
        delete_transient( 'rfa_remote_meta' );
        delete_transient( 'rfa_update_error' );
        delete_transient( 'rfa_token_expiry' );
    }

    /**
     * Preserve legacy settings when moving from RFA prefix to BRZ.
     */
    private static function migrate_legacy_options() {
        $new = get_option( BRZ_OPTION, null );
        if ( null !== $new ) {
            return;
        }

        $legacy = get_option( 'rfa_options', null );
        if ( is_array( $legacy ) ) {
            update_option( BRZ_OPTION, $legacy, false );
        }
    }
}
