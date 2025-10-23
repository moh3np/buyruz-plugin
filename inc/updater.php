<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_Updater {
    const OWNER = 'Codruz';
    const REPO  = 'buyruz-plugin';

    public static function init() {
        add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
        add_filter( 'http_request_args', array( __CLASS__, 'authorize_github_requests' ), 10, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
        add_action( 'network_admin_notices', array( __CLASS__, 'admin_notice' ) );
    }

    public static function inject_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = self::get_remote_meta();
        if ( ! $remote || empty( $remote['version'] ) ) {
            return $transient;
        }

        if ( version_compare( $remote['version'], RFA_VERSION, '>' ) ) {
            $plugin_file = plugin_basename( RFA_PATH . 'rm-faq-accordion.php' );
            $transient->response[ $plugin_file ] = (object) array(
                'slug'        => 'rm-faq-accordion',
                'plugin'      => $plugin_file,
                'new_version' => $remote['version'],
                'url'         => 'https://github.com/Codruz/buyruz-plugin.git',
                'package'     => $remote['package'],
            );
        }

        return $transient;
    }

    public static function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== 'rm-faq-accordion' ) {
            return $result;
        }

        $remote = self::get_remote_meta();
        if ( ! $remote ) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'تنظیمات بایروز';
        $info->slug = 'rm-faq-accordion';
        $info->version = $remote['version'];
        $info->author = 'کُدروز';
        $info->author_profile = 'https://github.com/Codruz';
        $info->homepage = 'https://github.com/Codruz/buyruz-plugin.git';
        $info->requires = '5.8';
        $info->tested = get_bloginfo( 'version' );
        $info->sections = array(
            'description' => wpautop( self::get_local_readme_description() ),
            'changelog'   => wpautop( self::format_markdown_section( $remote['changelog'] ?? '' ) ),
        );
        $info->download_link = $remote['package'];

        return $info;
    }

    public static function authorize_github_requests( $args, $url ) {
        if ( strpos( $url, 'github.com/'.self::OWNER.'/'.self::REPO ) === false && strpos( $url, 'codeload.github.com/'.self::OWNER.'/'.self::REPO ) === false && strpos( $url, 'api.github.com/repos/'.self::OWNER.'/'.self::REPO ) === false ) {
            return $args;
        }

        $token = RFA_Settings::get( 'github_token', '' );
        if ( $token ) {
            $args['headers']['Authorization'] = 'token ' . $token;
        }
        $args['headers']['User-Agent'] = 'RM-FAQ-Accordion-Updater';
        $args['headers']['Accept'] = 'application/vnd.github+json';

        return $args;
    }

    public static function admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && ! in_array( $screen->id, array( 'plugins', 'settings_page_rfa-settings' ), true ) ) {
            return;
        }

        $token = RFA_Settings::get( 'github_token', '' );
        $settings_url = admin_url( 'admin.php?page=rfa-settings' );

        if ( ! $token ) {
            echo '<div class="notice notice-warning"><p>برای فعال‌شدن بررسی خودکار نسخه لطفاً <a href="'.esc_url( $settings_url ).'">توکن گیت‌هاب را در تنظیمات افزونه</a> ثبت کنید.</p></div>';
            return;
        }

        $error = get_transient( 'rfa_token_error' );
        if ( $error ) {
            echo '<div class="notice notice-error"><p>'.esc_html( $error ).'</p></div>';
        }
    }

    private static function get_remote_meta() {
        $cached = get_transient( 'rfa_remote_meta' );
        if ( $cached ) {
            return $cached;
        }

        $token = RFA_Settings::get( 'github_token', '' );
        if ( ! $token ) {
            return null;
        }

        $meta = array(
            'version'   => '',
            'package'   => '',
            'changelog' => '',
        );

        $file = self::request( 'contents/rm-faq-accordion.php?ref=main' );
        if ( is_wp_error( $file ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $file ), true );
        if ( empty( $body['content'] ) ) {
            return null;
        }

        $decoded = base64_decode( $body['content'] );
        if ( $decoded && preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $decoded, $m ) ) {
            $meta['version'] = trim( $m[1] );
        }

        $changes = self::request( 'contents/CHANGELOG.md?ref=main' );
        if ( ! is_wp_error( $changes ) ) {
            $data = json_decode( wp_remote_retrieve_body( $changes ), true );
            if ( ! empty( $data['content'] ) ) {
                $meta['changelog'] = base64_decode( $data['content'] );
            }
        }

        $meta['package'] = 'https://api.github.com/repos/'.self::OWNER.'/'.self::REPO.'/zipball/main';

        if ( empty( $meta['version'] ) ) {
            return null;
        }

        set_transient( 'rfa_remote_meta', $meta, HOUR_IN_SECONDS );

        return $meta;
    }

    private static function request( $endpoint ) {
        $token = RFA_Settings::get( 'github_token', '' );
        if ( ! $token ) {
            return new WP_Error( 'rfa_no_token', 'توکن گیت‌هاب وارد نشده است.' );
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'token ' . $token,
                'User-Agent'    => 'RM-FAQ-Accordion-Updater',
                'Accept'        => 'application/vnd.github+json',
            ),
            'timeout' => 20,
        );

        $response = wp_remote_get( 'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/' . $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            set_transient( 'rfa_token_error', 'خطا در ارتباط با GitHub: ' . $response->get_error_message(), HOUR_IN_SECONDS );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $expiry = wp_remote_retrieve_header( $response, 'github-authentication-token-expiration' );
        if ( $expiry ) {
            set_transient( 'rfa_token_expiry', $expiry, DAY_IN_SECONDS );
        }

        if ( $code >= 400 ) {
            $message = self::extract_error_message( $response );
            set_transient( 'rfa_token_error', $message, HOUR_IN_SECONDS );
            return new WP_Error( 'rfa_http_'.$code, $message );
        }

        delete_transient( 'rfa_token_error' );
        return $response;
    }

    private static function extract_error_message( $response ) {
        $body = wp_remote_retrieve_body( $response );
        if ( $body ) {
            $json = json_decode( $body, true );
            if ( isset( $json['message'] ) ) {
                return 'پاسخ GitHub: ' . $json['message'];
            }
        }
        return 'پاسخ نامعتبر از GitHub دریافت شد.';
    }

    private static function get_local_readme_description() {
        $path = RFA_PATH . 'README.md';
        if ( ! file_exists( $path ) ) {
            return 'اطلاعات بیشتر در مخزن GitHub موجود است.';
        }
        $contents = file_get_contents( $path );
        $parts = preg_split( '/^##\s+/m', $contents );
        if ( ! empty( $parts[0] ) ) {
            $text = trim( $parts[0] );
            return esc_html( $text );
        }
        return esc_html( $contents );
    }

    private static function format_markdown_section( $markdown ) {
        if ( ! $markdown ) {
            return 'هنوز یادداشت تغییری ثبت نشده است.';
        }
        $section = $markdown;
        if ( preg_match( '/##\s*\[[^\]]+\][^\n]*\n(?P<body>.*?)(?=^##\s*\[|\z)/ms', $markdown, $matches ) ) {
            $section = trim( $matches[0] );
        }
        return esc_html( $section );
    }
}
RFA_Updater::init();
