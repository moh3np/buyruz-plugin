<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Centralized Google Sheet transport layer.
 *
 * Goal: هر فیچری که نیاز به ارسال دیتا به Web App گوگل شیت دارد از این کلاس استفاده کند.
 * - Single entry point: BRZ_GSheet::send_route( 'add_suggestions', $payload, $settings )
 * - Handles auth (api_key)، retries و خطایابی
 */
class BRZ_GSheet {
    /**
     * Generic sender.
     *
     * @param string $route   Logical route name (e.g., add_suggestions).
     * @param array  $payload Data to send.
     * @param array  $settings Associative array containing sheet_web_app, api_key.
     *
     * @return array|WP_Error
     */
    public static function send_route( $route, array $payload, array $settings ) {
        $web_app = isset( $settings['sheet_web_app'] ) ? esc_url_raw( $settings['sheet_web_app'] ) : '';
        $api_key = isset( $settings['api_key'] ) ? sanitize_text_field( $settings['api_key'] ) : '';

        if ( empty( $web_app ) ) {
            return new WP_Error( 'brz_gsheet_missing_url', 'Web App URL تعریف نشده است.' );
        }

        $body = array_merge(
            array(
                'action'  => $route,
                'api_key' => $api_key,
            ),
            $payload
        );

        $response = wp_remote_post( $web_app, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'brz_gsheet_bad_json', 'پاسخ نامعتبر از Web App' );
        }

        return $data;
    }

    /**
     * Convenience method for sending link suggestions.
     *
     * @param array $rows
     * @param array $settings
     * @return array|WP_Error
     */
    public static function send_suggestions( array $rows, array $settings ) {
        return self::send_route( 'add_suggestions', array( 'rows' => $rows ), $settings );
    }
}
