<?php
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
delete_option( 'brz_options' );
delete_option( 'rfa_options' );
delete_option( 'myplugin_enable_rankmath_faq_append' );
delete_option( 'myplugin_enable_wc_product_shortcodes' );
