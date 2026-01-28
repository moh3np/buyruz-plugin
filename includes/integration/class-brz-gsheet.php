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

        // Prefer direct Sheets OAuth if tokens موجود باشد
        $token = self::get_access_token( $settings );
        if ( $token ) {
            return self::send_via_google_api( $route, $payload, $token, $settings );
        }

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

    /**
     * Build OAuth URL.
     */
    public static function build_auth_url( array $settings ) {
        $client_id = isset( $settings['google_client_id'] ) ? $settings['google_client_id'] : '';
        if ( empty( $client_id ) ) { return ''; }
        $redirect = admin_url( 'admin-post.php?action=brz_gsheet_oauth_cb' );
        $state = wp_create_nonce( 'brz_gsheet_state' );
        $params = array(
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect,
            'scope'         => 'https://www.googleapis.com/auth/spreadsheets',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        );
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Handle OAuth start.
     */
    public static function handle_oauth_start() {
        check_admin_referer( 'brz_gsheet_oauth' );
        $settings = BRZ_Smart_Linker::get_settings();
        $url = self::build_auth_url( $settings );
        if ( empty( $url ) ) {
            wp_die( 'Client ID تنظیم نشده است.' );
        }
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Handle OAuth callback.
     */
    public static function handle_oauth_callback() {
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $state, 'brz_gsheet_state' ) ) {
            wp_die( 'Invalid state' );
        }
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $code ) ) {
            wp_die( 'Missing code' );
        }
        $settings = BRZ_Smart_Linker::get_settings();
        $resp = self::exchange_code( $code, $settings );
        if ( is_wp_error( $resp ) ) {
            wp_die( $resp->get_error_message() );
        }
        update_option( 'brz_gsheet_tokens', $resp, false );
        wp_safe_redirect( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=connections&brz-msg=gsheet-connected' ) );
        exit;
    }

    /**
     * Get a valid access token (refresh if needed).
     */
    public static function get_access_token( array $settings ) {
        $tokens = get_option( 'brz_gsheet_tokens', array() );
        if ( empty( $tokens['access_token'] ) ) {
            return null;
        }
        $expires = isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0;
        if ( time() >= $expires && ! empty( $tokens['refresh_token'] ) ) {
            $refreshed = self::refresh_token( $tokens, $settings );
            if ( is_wp_error( $refreshed ) ) {
                return null;
            }
            $tokens = $refreshed;
        }
        return $tokens['access_token'];
    }

    /**
     * Exchange code for tokens.
     */
    private static function exchange_code( $code, array $settings ) {
        $client_id = isset( $settings['google_client_id'] ) ? $settings['google_client_id'] : '';
        $client_secret = isset( $settings['google_client_secret'] ) ? $settings['google_client_secret'] : '';
        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new WP_Error( 'brz_gsheet_missing_client', 'Client ID/Secret تنظیم نشده است.' );
        }
        $redirect = admin_url( 'admin-post.php?action=brz_gsheet_oauth_cb' );
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect,
                'grant_type'    => 'authorization_code',
            ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['access_token'] ) ) {
            return new WP_Error( 'brz_gsheet_bad_token', 'پاسخ نامعتبر توکن' );
        }
        $expires_at = time() + (int) $data['expires_in'] - 60;
        return array(
            'access_token'  => $data['access_token'],
            'refresh_token' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : '',
            'expires_at'    => $expires_at,
        );
    }

    /**
     * Refresh token.
     */
    private static function refresh_token( array $tokens, array $settings ) {
        $client_id = isset( $settings['google_client_id'] ) ? $settings['google_client_id'] : '';
        $client_secret = isset( $settings['google_client_secret'] ) ? $settings['google_client_secret'] : '';
        if ( empty( $client_id ) || empty( $client_secret ) || empty( $tokens['refresh_token'] ) ) {
            return new WP_Error( 'brz_gsheet_missing_refresh', 'اطلاعات رفرش ناقص است.' );
        }
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array(
                'refresh_token' => $tokens['refresh_token'],
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'grant_type'    => 'refresh_token',
            ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['access_token'] ) ) {
            return new WP_Error( 'brz_gsheet_bad_refresh', 'رفرش توکن نامعتبر' );
        }
        $tokens['access_token'] = $data['access_token'];
        $tokens['expires_at']   = time() + (int) $data['expires_in'] - 60;
        update_option( 'brz_gsheet_tokens', $tokens, false );
        return $tokens;
    }

    /**
     * Send via Google Sheets API when OAuth tokens موجود است.
     */
    private static function send_via_google_api( $route, array $payload, $access_token, array $settings ) {
        // For now we only implement add_suggestions -> append rows
        if ( 'add_suggestions' !== $route ) {
            return new WP_Error( 'brz_gsheet_route', 'Route پشتیبانی نمی‌شود.' );
        }
        if ( empty( $settings['sheet_id'] ) ) {
            return new WP_Error( 'brz_gsheet_sheetid', 'Sheet ID تنظیم نشده است.' );
        }
        $values = array();
        if ( ! empty( $payload['rows'] ) && is_array( $payload['rows'] ) ) {
            foreach ( $payload['rows'] as $row ) {
                $values[] = array(
                    isset( $row['id'] ) ? $row['id'] : '',
                    isset( $row['post_id'] ) ? $row['post_id'] : '',
                    isset( $row['keyword'] ) ? $row['keyword'] : '',
                    isset( $row['target_url'] ) ? $row['target_url'] : '',
                    isset( $row['status'] ) ? $row['status'] : 'Pending',
                    isset( $row['date'] ) ? $row['date'] : current_time( 'mysql' ),
                );
            }
        }
        $body = array( 'values' => $values );
        $range = 'Link Suggestions!A:F';
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $settings['sheet_id'] ) . '/values/' . rawurlencode( $range ) . ':append?valueInputOption=USER_ENTERED';
        $resp = self::authorized_request( 'POST', $url, $access_token, $body );
        return $resp;
    }

    /**
     * Generic authorized request.
     */
    private static function authorized_request( $method, $url, $access_token, $body = null ) {
        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 15,
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }
        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data;
    }

    /**
     * Quick connectivity test using Sheets API.
     */
    public static function test_connection( array $settings ) {
        $token = self::get_access_token( $settings );
        if ( ! $token ) {
            return new WP_Error( 'brz_gsheet_no_token', 'ابتدا اتصال گوگل را انجام دهید.' );
        }
        $sheet_id = isset( $settings['sheet_id'] ) ? $settings['sheet_id'] : '';
        if ( empty( $sheet_id ) ) {
            return new WP_Error( 'brz_gsheet_sheetid', 'Sheet ID تنظیم نشده است.' );
        }
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $sheet_id );
        return self::authorized_request( 'GET', $url, $token );
    }
}
