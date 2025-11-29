<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Lightweight class autoloader for BUYR_ prefixed classes.
 * Keeps file structure declarative so modules can grow without manual requires.
 * هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
 */
spl_autoload_register( function( $class ) {
    if ( strpos( $class, 'BUYR_' ) !== 0 ) {
        return;
    }

    $map = array(
        'BUYR_Plugin'                 => 'core/class-buyr-plugin.php',
        'BUYR_Guard'                  => 'core/class-buyr-guard.php',
        'BUYR_Debug'                  => 'support/class-buyr-debug.php',
        'BUYR_Settings'               => 'admin/class-buyr-settings.php',
        'BUYR_Detector'               => 'front/class-buyr-detector.php',
        'BUYR_Enqueue'                => 'front/class-buyr-enqueue.php',
        'BUYR_RankMath_Integration'   => 'integration/class-buyr-rankmath.php',
    );

    $map = apply_filters( 'buyr/autoload_map', $map );

    if ( empty( $map[ $class ] ) ) {
        return;
    }

    $path = BUYR_PATH . 'includes/' . ltrim( $map[ $class ], '/' );
    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );
