<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_Updater {
    const OWNER = 'Codruz';
    const REPO  = 'buyruz-plugin';
    const PLUGIN_FILE = 'rm-faq-accordion.php';
    const TRANSIENT_META = 'rfa_remote_meta';
    const TRANSIENT_ERROR = 'rfa_update_error';
    const TRANSIENT_TOKEN_EXPIRY = 'rfa_token_expiry';
    const USER_AGENT = 'RM-FAQ-Accordion-Updater';
    const HTTP_TIMEOUT = 20;
    const MAX_CACHE_TTL = 10 * MINUTE_IN_SECONDS;

    private static $remote_meta = null;

    public static function init() {
        add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
        add_filter( 'http_request_args', array( __CLASS__, 'authorize_github_requests' ), 10, 2 );
        add_filter( 'http_request_host_is_external', array( __CLASS__, 'allow_github_hosts' ), 10, 2 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_package_source' ), 10, 4 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
        add_action( 'network_admin_notices', array( __CLASS__, 'admin_notice' ) );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache_after_upgrade' ), 10, 2 );
        add_action( 'in_plugin_update_message-' . self::plugin_basename(), array( __CLASS__, 'render_inline_update_message' ), 10, 2 );

        if ( self::is_force_refresh() ) {
            delete_transient( self::TRANSIENT_META );
        }
    }

    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        $plugin_file = self::plugin_basename();

        if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
            $transient->checked = array();
        }

        $remote = self::get_remote_meta();
        if ( empty( $remote['version'] ) || empty( $remote['package'] ) ) {
            return $transient;
        }

        $local_version = self::get_local_version();

        if ( version_compare( $remote['version'], $local_version, '>' ) ) {
            $transient->response[ $plugin_file ] = (object) array(
                'slug'           => 'rm-faq-accordion',
                'plugin'         => $plugin_file,
                'new_version'    => $remote['version'],
                'url'            => 'https://github.com/' . self::OWNER . '/' . self::REPO,
                'package'        => $remote['package'],
                'upgrade_notice' => ! empty( $remote['notice'] ) ? $remote['notice'] : '',
            );
        } else {
            $transient->no_update[ $plugin_file ] = (object) array(
                'slug'        => 'rm-faq-accordion',
                'plugin'      => $plugin_file,
                'new_version' => $remote['version'],
            );
        }

        return $transient;
    }

    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'rm-faq-accordion' !== $args->slug ) {
            return $result;
        }

        $remote = self::get_remote_meta();
        if ( empty( $remote['version'] ) ) {
            return $result;
        }

        $info = new stdClass();
        $info->name           = 'تنظیمات بایروز';
        $info->slug           = 'rm-faq-accordion';
        $info->version        = $remote['version'];
        $info->author         = 'کُدروز';
        $info->author_profile = 'https://github.com/' . self::OWNER;
        $info->homepage       = 'https://github.com/' . self::OWNER . '/' . self::REPO;
        $info->requires       = '5.8';
        $info->tested         = get_bloginfo( 'version' );
        $info->sections       = array(
            'description' => wpautop( self::get_local_readme_description() ),
            'changelog'   => wpautop( self::format_markdown_section( $remote['changelog'] ?? '' ) ),
        );
        $info->download_link = $remote['package'];

        return $info;
    }

    public static function authorize_github_requests( $args, $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return $args;
        }

        $host = strtolower( $parts['host'] );
        $path = isset( $parts['path'] ) ? $parts['path'] : '';

        if ( ! self::url_targets_repo( $host, $path ) ) {
            return $args;
        }

        $token = RFA_Settings::get( 'github_token', '' );

        if ( $token ) {
            $args['headers']['Authorization'] = 'token ' . $token;
        }

        $args['headers']['User-Agent'] = self::USER_AGENT;

        if ( 'api.github.com' === $host ) {
            $args['headers']['Accept'] = 'application/vnd.github+json';
        } elseif ( 'codeload.github.com' === $host ) {
            $args['headers']['Accept'] = 'application/zip';
        }

        if ( empty( $args['timeout'] ) || $args['timeout'] < self::HTTP_TIMEOUT ) {
            $args['timeout'] = self::HTTP_TIMEOUT;
        }

        return $args;
    }

    public static function allow_github_hosts( $is_external, $host ) {
        if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
            return $is_external;
        }

        $host    = strtolower( $host );
        $allowed = array(
            'api.github.com',
            'github.com',
            'codeload.github.com',
            'raw.githubusercontent.com',
        );

        if ( in_array( $host, $allowed, true ) ) {
            return false;
        }

        return $is_external;
    }

    public static function admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $error = get_transient( self::TRANSIENT_ERROR );
        if ( empty( $error ) || empty( $error['message'] ) ) {
            return;
        }

        $screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $allowed_ids = array( 'plugins', 'update-core', 'settings_page_rfa-settings' );

        if ( $screen && ! in_array( $screen->id, $allowed_ids, true ) ) {
            return;
        }

        $message_parts = array();

        if ( ! empty( $error['status'] ) ) {
            $message_parts[] = 'وضعیت HTTP ' . $error['status'];
        }

        $message_parts[] = $error['message'];

        $extra = '';
        if ( ! empty( $error['rate_limit'] ) ) {
            $extra .= ' محدودیت نرخ گیت‌هاب فعال شده است؛ افزودن توکن با سطح public_repo (یا repo برای مخزن خصوصی) فشار را برطرف می‌کند.';
        } elseif ( ! empty( $error['needs_token'] ) ) {
            $extra .= ' برای دسترسی کامل به نسخه‌ها، توکن با سطح دسترسی مناسب را در تنظیمات ثبت کنید.';
        }

        $notice = implode( ' — ', $message_parts );
        if ( $extra ) {
            $notice .= ' ' . $extra;
        }

        echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>';
    }

    public static function render_inline_update_message( $plugin_data, $response ) {
        $remote = self::get_remote_meta();

        if ( ! empty( $remote['error'] ) ) {
            echo '<p class="update-message notice inline notice-error"><span>' . esc_html( $remote['error'] ) . '</span></p>';
            return;
        }

        if ( ! empty( $remote['notice'] ) ) {
            echo '<p class="update-message notice inline notice-info"><span>' . esc_html( $remote['notice'] ) . '</span></p>';
        }
    }

    public static function rename_package_source( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
            return $source;
        }

        $plugin_file = self::plugin_basename();

        $targets = array();
        if ( ! empty( $hook_extra['plugin'] ) ) {
            $targets[] = $hook_extra['plugin'];
        }
        if ( ! empty( $hook_extra['plugins'] ) ) {
            $targets = array_merge( $targets, (array) $hook_extra['plugins'] );
        }

        if ( ! in_array( $plugin_file, $targets, true ) ) {
            return $source;
        }

        if ( ! is_string( $source ) || ! is_dir( $source ) ) {
            return $source;
        }

        $target_dirname = self::plugin_directory_name();
        $desired_path   = trailingslashit( $remote_source ) . $target_dirname;

        if ( trailingslashit( $source ) === trailingslashit( $desired_path ) ) {
            return $source;
        }

        if ( file_exists( $desired_path ) ) {
            self::remove_dir( $desired_path );
        }

        if ( function_exists( 'move_dir' ) ) {
            $result = move_dir( $source, $desired_path, true );
            if ( ! is_wp_error( $result ) ) {
                return $desired_path;
            }
        }

        if ( @rename( $source, $desired_path ) ) {
            return $desired_path;
        }

        return $source;
    }

    public static function clear_cache_after_upgrade( $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
            return;
        }

        $plugin_file = self::plugin_basename();

        $targets = array();
        if ( ! empty( $hook_extra['plugin'] ) ) {
            $targets[] = $hook_extra['plugin'];
        }
        if ( ! empty( $hook_extra['plugins'] ) ) {
            $targets = array_merge( $targets, (array) $hook_extra['plugins'] );
        }

        if ( in_array( $plugin_file, $targets, true ) ) {
            delete_transient( self::TRANSIENT_META );
            delete_transient( self::TRANSIENT_ERROR );
        }
    }

    private static function get_remote_meta() {
        if ( null !== self::$remote_meta ) {
            return self::$remote_meta;
        }

        $force_refresh = self::is_force_refresh();
        if ( ! $force_refresh ) {
            $cached = get_transient( self::TRANSIENT_META );
            if ( $cached && is_array( $cached ) ) {
                self::$remote_meta = $cached;
                return $cached;
            }
        } else {
            delete_transient( self::TRANSIENT_META );
        }

        $meta = array(
            'version'         => '',
            'package'         => '',
            'changelog'       => '',
            'notice'          => '',
            'branch'          => '',
            'file_version'    => '',
            'release_version' => '',
            'download_type'   => '',
            'error'           => '',
        );

        $repo = self::get_repo_info();
        if ( is_wp_error( $repo ) ) {
            $meta['error'] = $repo->get_error_message();
            return self::cache_after_fetch( $meta );
        }

        $branch         = self::determine_branch( $repo );
        $meta['branch'] = $branch;

        $release = self::get_latest_release();
        if ( is_wp_error( $release ) ) {
            $meta['error'] = $release->get_error_message();
        } elseif ( $release ) {
            $release_version = self::normalize_version( $release['tag_name'] ?? '' );
            if ( $release_version ) {
                $meta['release_version'] = $release_version;
                $meta['version']         = $release_version;
                $meta['package']         = isset( $release['zipball_url'] ) ? $release['zipball_url'] : '';
                $meta['download_type']   = 'release';
                $meta['changelog']       = isset( $release['body'] ) ? $release['body'] : '';
                $meta['notice']          = self::extract_notice( $meta['changelog'] );
            }
        }

        $plugin_contents = self::get_file_from_branch( self::PLUGIN_FILE, $branch );
        if ( is_wp_error( $plugin_contents ) ) {
            $meta['error'] = $plugin_contents->get_error_message();
            return self::cache_after_fetch( $meta );
        }

        $file_version = self::extract_version_from_contents( $plugin_contents );
        if ( $file_version ) {
            $meta['file_version'] = $file_version;
            if ( empty( $meta['version'] ) || version_compare( $file_version, $meta['version'], '>' ) ) {
                $meta['version']       = $file_version;
                $meta['package']       = self::build_branch_package_url( $branch );
                $meta['download_type'] = 'branch';
            }
        }

        if ( empty( $meta['changelog'] ) ) {
            $changelog_contents = self::get_file_from_branch( 'CHANGELOG.md', $branch, true );
            if ( is_wp_error( $changelog_contents ) ) {
                $meta['error'] = $changelog_contents->get_error_message();
            } elseif ( $changelog_contents ) {
                $meta['changelog'] = $changelog_contents;
                if ( empty( $meta['notice'] ) ) {
                    $meta['notice'] = self::extract_notice( $meta['changelog'] );
                }
            }
        }

        if ( empty( $meta['package'] ) && ! empty( $meta['version'] ) ) {
            $meta['package']       = self::build_branch_package_url( $branch );
            $meta['download_type'] = 'branch';
        }

        if ( empty( $meta['error'] ) ) {
            self::clear_error();
        }

        return self::cache_after_fetch( $meta );
    }

    private static function cache_after_fetch( array $meta ) {
        self::$remote_meta = $meta;
        $context = empty( $meta['error'] ) ? 'success' : 'error';
        $ttl     = self::cache_ttl( $context );

        if ( $ttl > 0 ) {
            set_transient( self::TRANSIENT_META, $meta, $ttl );
        }

        if ( ! empty( $meta['error'] ) ) {
            self::record_error_from_message( $meta['error'] );
        }

        return $meta;
    }

    private static function cache_ttl( $context ) {
        $default = ( 'error' === $context ) ? 2 * MINUTE_IN_SECONDS : self::MAX_CACHE_TTL;
        $ttl     = (int) apply_filters( 'rfa_remote_meta_ttl', $default, $context );
        $ttl     = max( MINUTE_IN_SECONDS, $ttl );
        return (int) min( $ttl, self::MAX_CACHE_TTL );
    }

    private static function determine_branch( $repo ) {
        $branch = RFA_Settings::get( 'github_branch', '' );

        if ( ! $branch ) {
            if ( is_array( $repo ) && ! empty( $repo['default_branch'] ) ) {
                $branch = $repo['default_branch'];
            } else {
                $branch = 'main';
            }
        }

        return apply_filters( 'rfa_update_branch', $branch, $repo );
    }

    private static function get_repo_info() {
        $response = self::api_get( 'repos/' . self::OWNER . '/' . self::REPO );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( ! $response ) {
            return new WP_Error( 'rfa_empty_repo', 'پاسخ نامعتبر از GitHub هنگام خواندن اطلاعات مخزن.' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'rfa_invalid_repo_json', 'ساختار JSON اطلاعات مخزن غیرمنتظره است.' );
        }

        return $body;
    }

    private static function get_latest_release() {
        $response = self::api_get(
            'repos/' . self::OWNER . '/' . self::REPO . '/releases/latest',
            array(),
            array( 'allow_status' => array( 404 ) )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( null === $response ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'rfa_invalid_release_json', 'پاسخ JSON مربوط به آخرین ریلیز نامعتبر است.' );
        }

        return $body;
    }

    private static function get_file_from_branch( $file, $branch, $allow_missing = false ) {
        $endpoint = sprintf(
            'repos/%s/%s/contents/%s?ref=%s',
            self::OWNER,
            self::REPO,
            ltrim( $file, '/' ),
            rawurlencode( $branch )
        );

        $response = self::api_get( $endpoint, array(), $allow_missing ? array( 'allow_status' => array( 404 ) ) : array() );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( null === $response ) {
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['content'] ) ) {
            return new WP_Error( 'rfa_empty_content', 'محتوای فایل در GitHub خالی یا غیرقابل پردازش است.' );
        }

        $decoded = base64_decode( $body['content'], true );
        if ( false === $decoded ) {
            return new WP_Error( 'rfa_decode_failed', 'امکان تبدیل محتوای base64 فایل در GitHub وجود ندارد.' );
        }

        return $decoded;
    }

    private static function build_branch_package_url( $branch ) {
        return sprintf(
            'https://codeload.github.com/%s/%s/zip/refs/heads/%s',
            self::OWNER,
            self::REPO,
            rawurlencode( $branch )
        );
    }

    private static function extract_version_from_contents( $contents ) {
        if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $matches ) ) {
            return trim( $matches[1] );
        }

        return '';
    }

    private static function normalize_version( $version ) {
        $version = trim( (string) $version );
        if ( '' === $version ) {
            return '';
        }

        $version = preg_replace( '/^v/iu', '', $version );
        return trim( $version );
    }

    private static function api_get( $endpoint, $args = array(), $options = array() ) {
        $endpoint = ltrim( $endpoint, '/' );
        $url      = 'https://api.github.com/' . $endpoint;
        return self::remote_get( $url, $args, $options );
    }

    private static function remote_get( $url, $args = array(), $options = array() ) {
        $args = wp_parse_args(
            $args,
            array(
                'timeout' => self::HTTP_TIMEOUT,
            )
        );

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            self::record_error_from_wp_error( $response );
            return $response;
        }

        $code    = (int) wp_remote_retrieve_response_code( $response );
        $allowed = isset( $options['allow_status'] ) ? (array) $options['allow_status'] : array();

        if ( $code >= 400 && ! in_array( $code, $allowed, true ) ) {
            $error = self::error_from_response( $response, $code );
            self::record_error_from_wp_error( $error );
            return $error;
        }

        $expiry = wp_remote_retrieve_header( $response, 'github-authentication-token-expiration' );
        if ( $expiry ) {
            set_transient( self::TRANSIENT_TOKEN_EXPIRY, $expiry, DAY_IN_SECONDS );
        }

        if ( $code >= 400 ) {
            return null;
        }

        return $response;
    }

    private static function error_from_response( $response, $status ) {
        $body    = wp_remote_retrieve_body( $response );
        $message = self::build_error_message( $status, $body );

        $data = array( 'status' => $status );

        if ( in_array( $status, array( 401, 403 ), true ) ) {
            $data['needs_token'] = true;
        }

        if ( in_array( $status, array( 403, 429 ), true ) ) {
            $data['rate_limit'] = self::mentions_rate_limit( $body );
        }

        return new WP_Error( 'rfa_http_' . $status, $message, $data );
    }

    private static function build_error_message( $status, $body ) {
        $summary = '';
        if ( $body ) {
            $json = json_decode( $body, true );
            if ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
                $summary = $json['message'];
            } else {
                $summary = wp_trim_words( wp_strip_all_tags( $body ), 20, '…' );
            }
        }

        if ( '' === $summary ) {
            $summary = 'پاسخ نامعتبر از GitHub دریافت شد.';
        }

        $message = sprintf(
            'خطا در دریافت اطلاعات از GitHub (%d): %s',
            (int) $status,
            $summary
        );

        return $message;
    }

    private static function mentions_rate_limit( $body ) {
        if ( ! $body ) {
            return false;
        }

        if ( false !== stripos( $body, 'rate limit' ) ) {
            return true;
        }

        $json = json_decode( $body, true );
        if ( isset( $json['documentation_url'] ) && false !== stripos( $json['documentation_url'], 'rate-limit' ) ) {
            return true;
        }

        return false;
    }

    private static function record_error_from_wp_error( $error ) {
        if ( ! is_wp_error( $error ) ) {
            return;
        }

        $data   = $error->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? $data['status'] : null;

        $payload = array(
            'message' => $error->get_error_message(),
            'status'  => $status,
        );

        if ( is_array( $data ) && ! empty( $data['needs_token'] ) ) {
            $payload['needs_token'] = true;
        }

        if ( is_array( $data ) && ! empty( $data['rate_limit'] ) ) {
            $payload['rate_limit'] = true;
        }

        set_transient( self::TRANSIENT_ERROR, $payload, MINUTE_IN_SECONDS * 10 );
    }

    private static function record_error_from_message( $message ) {
        if ( ! $message ) {
            return;
        }

        $existing = get_transient( self::TRANSIENT_ERROR );
        if ( ! $existing ) {
            set_transient(
                self::TRANSIENT_ERROR,
                array(
                    'message' => $message,
                ),
                MINUTE_IN_SECONDS * 10
            );
        }
    }

    private static function clear_error() {
        delete_transient( self::TRANSIENT_ERROR );
    }

    private static function get_local_readme_description() {
        $path = RFA_PATH . 'README.md';
        if ( ! file_exists( $path ) ) {
            return 'اطلاعات بیشتر در مخزن GitHub موجود است.';
        }

        $contents = file_get_contents( $path );
        if ( false === $contents ) {
            return 'اطلاعات بیشتر در مخزن GitHub موجود است.';
        }

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

        $section = self::get_version_section( $markdown );
        return esc_html( $section );
    }

    private static function get_version_section( $markdown ) {
        if ( ! $markdown ) {
            return '';
        }

        if ( preg_match( '/##\s*\[[^\]]+\][^\n]*\n(?P<body>.*?)(?=^##\s*\[|\z)/ms', $markdown, $matches ) ) {
            return trim( $matches[0] );
        }

        return trim( $markdown );
    }

    private static function extract_notice( $markdown ) {
        $section = self::get_version_section( $markdown );
        if ( ! $section ) {
            return '';
        }

        if ( preg_match( '/-\s+(.*)/', $section, $m ) ) {
            return wp_strip_all_tags( trim( $m[1] ) );
        }

        return wp_strip_all_tags( $section );
    }

    private static function url_targets_repo( $host, $path ) {
        $owner = strtolower( self::OWNER );
        $repo  = strtolower( self::REPO );
        $path  = '/' . ltrim( strtolower( $path ), '/' );

        if ( 'api.github.com' === $host ) {
            return 0 === strpos( $path, '/repos/' . $owner . '/' . $repo );
        }

        if ( in_array( $host, array( 'github.com', 'codeload.github.com', 'raw.githubusercontent.com' ), true ) ) {
            return 0 === strpos( $path, '/' . $owner . '/' . $repo );
        }

        return false;
    }

    private static function plugin_basename() {
        return plugin_basename( self::plugin_path() );
    }

    private static function plugin_path() {
        return RFA_PATH . self::PLUGIN_FILE;
    }

    private static function plugin_directory_name() {
        return basename( rtrim( RFA_PATH, '/\\' ) );
    }

    private static function get_local_version() {
        static $version = null;

        if ( null === $version ) {
            $data    = get_file_data( self::plugin_path(), array( 'Version' => 'Version' ) );
            $version = ! empty( $data['Version'] ) ? $data['Version'] : '0.0.0';
        }

        return $version;
    }

    private static function is_force_refresh() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }

        if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only.
            if ( ! function_exists( 'current_user_can' ) || current_user_can( 'update_plugins' ) ) {
                return true;
            }
        }

        return false;
    }

    private static function remove_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = scandir( $dir );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if ( is_dir( $path ) ) {
                self::remove_dir( $path );
            } else {
                @unlink( $path );
            }
        }

        @rmdir( $dir );
    }
}
RFA_Updater::init();
