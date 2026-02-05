<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Smart Internal Linking module bootstrapper.
 *
 * Keeps public API small: BRZ_Smart_Linker::init()
 */
class BRZ_Smart_Linker {
    const OPTION_KEY          = 'brz_smart_linker';
    const CRON_PROCESS_HOOK   = 'brz_smart_linker_process_queue';
    const CRON_APPROVAL_HOOK  = 'brz_smart_linker_poll_approvals';
    const STATUS_PENDING      = 'pending';
    const STATUS_APPROVED     = 'approved';
    const STATUS_ACTIVE       = 'active';
    const STATUS_USER_DELETED = 'user_deleted';
    const STATUS_MANUAL       = 'manual_override';
    const DEFAULT_DENSITY     = 3; // links per 1000 words

    /**
     * List of valid statuses for validation.
     *
     * @return string[]
     */
    public static function statuses() {
        return array(
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_ACTIVE,
            self::STATUS_USER_DELETED,
            self::STATUS_MANUAL,
        );
    }

    /**
     * Entry point.
     */
    public static function init() {
        // Activation hook
        register_activation_hook( BRZ_PATH . 'buyruz-settings.php', array( __CLASS__, 'on_activate' ) );
        register_deactivation_hook( BRZ_PATH . 'buyruz-settings.php', array( __CLASS__, 'on_deactivate' ) );

        // Admin (page rendered via BRZ_Settings::render_module_settings)
        add_action( 'admin_post_brz_smart_linker_save', array( __CLASS__, 'handle_save_settings' ) );
        add_action( 'admin_post_brz_smart_linker_process_json', array( __CLASS__, 'handle_process_json' ) );
        add_action( 'wp_ajax_brz_smart_linker_generate', array( __CLASS__, 'ajax_generate' ) );
        add_action( 'wp_ajax_brz_smart_linker_save', array( __CLASS__, 'handle_save_settings_ajax' ) );
        add_action( 'admin_post_brz_smart_linker_clear_logs', array( __CLASS__, 'handle_clear_logs' ) );
        add_action( 'admin_post_brz_smart_linker_purge_pending', array( __CLASS__, 'handle_purge_pending' ) );
        add_action( 'wp_ajax_brz_smart_linker_sync_cache', array( __CLASS__, 'ajax_sync_cache' ) );
        add_action( 'wp_ajax_brz_smart_linker_analyze', array( __CLASS__, 'ajax_analyze' ) );
        add_action( 'wp_ajax_brz_smart_linker_apply', array( __CLASS__, 'ajax_apply' ) );
        add_action( 'wp_ajax_brz_smart_linker_test_gsheet', array( __CLASS__, 'ajax_test_gsheet' ) );
        add_action( 'wp_ajax_brz_smart_linker_test_peer', array( __CLASS__, 'ajax_test_peer' ) );
        add_action( 'admin_post_brz_gsheet_oauth_start', array( 'BRZ_GSheet', 'handle_oauth_start' ) );
        add_action( 'admin_post_brz_gsheet_oauth_cb', array( 'BRZ_GSheet', 'handle_oauth_callback' ) );

        // v3.0 AJAX handlers
        add_action( 'wp_ajax_brz_smart_linker_sync_peer', array( 'BRZ_Smart_Linker_Sync', 'ajax_sync_from_peer' ) );
        add_action( 'wp_ajax_brz_smart_linker_export', array( 'BRZ_Smart_Linker_Exporter', 'ajax_export' ) );
        add_action( 'wp_ajax_brz_smart_linker_import', array( 'BRZ_Smart_Linker_Importer', 'ajax_import' ) );
        add_action( 'wp_ajax_brz_smart_linker_update_status', array( 'BRZ_Smart_Linker_Importer', 'ajax_update_status' ) );
        add_action( 'wp_ajax_brz_smart_linker_apply_links', array( 'BRZ_Smart_Linker_Importer', 'ajax_apply_links' ) );

        // Initialize Sync module (registers REST API routes for peer communication)
        BRZ_Smart_Linker_Sync::init();

        // Cron / background
        add_action( 'init', array( __CLASS__, 'maybe_migrate_table' ), 1 );
        add_action( 'init', array( __CLASS__, 'ensure_cron_events' ) );
        add_action( self::CRON_PROCESS_HOOK, array( __CLASS__, 'process_queue' ) );
        add_action( self::CRON_APPROVAL_HOOK, array( __CLASS__, 'poll_approvals' ) );

        // REST provider endpoint
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    /**
     * Activation tasks: create table + schedule.
     */
    public static function on_activate() {
        BRZ_Smart_Linker_DB::migrate();
        self::ensure_cron_events();
    }

    /**
     * Cleanup scheduled tasks on deactivation.
     */
    public static function on_deactivate() {
        wp_clear_scheduled_hook( self::CRON_PROCESS_HOOK );
        wp_clear_scheduled_hook( self::CRON_APPROVAL_HOOK );
    }

    /**
     * Guard to create the table if the plugin is updated without re-activation.
     */
    public static function maybe_migrate_table() {
        global $wpdb;
        
        // Check v3.0 content_index table
        $content_table = BRZ_Smart_Linker_DB::content_index_table();
        $content_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $content_table ) );
        
        // Check v3.0 pending_links table
        $pending_table = BRZ_Smart_Linker_DB::pending_links_table();
        $pending_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pending_table ) );
        
        // Run migration if any table is missing
        if ( $content_exists !== $content_table || $pending_exists !== $pending_table ) {
            BRZ_Smart_Linker_DB::migrate();
        }
    }

    /**
     * Settings getter with defaults.
     *
     * @return array
     */
    public static function get_settings() {
        $defaults = array(
            'mode'           => 'manual', // manual|api
            'api_key'        => '',
            'local_api_key'  => '', // API key for this site (others use to connect here)
            'sheet_id'       => '',
            'sheet_web_app'  => '',
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_refresh_token' => '',
            'link_density'   => self::DEFAULT_DENSITY,
            'open_new_tab'   => 1,
            'nofollow'       => 1,
            'prevent_self'   => 1,
            'site_role'      => 'shop', // shop|blog
            'remote_endpoint'=> '',
            'remote_api_key' => '',
            'exclude_post_types' => array( 'post', 'product' ),
            'exclude_categories' => '',
            'exclude_html_tags'  => 'h1,h2,h3',
            'ai_provider'    => 'openai',
            'ai_api_key'     => '',
            'ai_base_url'    => '',
            'ai_model'       => '',
        );

        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        $settings = wp_parse_args( $saved, $defaults );
        
        // Auto-generate local_api_key if empty
        if ( empty( $settings['local_api_key'] ) ) {
            $settings['local_api_key'] = wp_generate_password( 32, false );
            update_option( self::OPTION_KEY, $settings, false );
        }
        
        return $settings;
    }

    /**
     * Render admin UI (called from BRZ_Settings).
     */
    public static function render_module_content() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            return;
        }

        $settings = self::get_settings();
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $valid_tabs = array( 'export', 'import', 'review', 'applied', 'strategy', 'exclusions', 'maintenance' );
        if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
            $active_tab = 'export';
        }

        self::render_notices();
        ?>
        <style>
        /* ==================== Smart Linker v3.1 - Professional UI ==================== */
        
        /* حذف padding های وردپرس برای full-width */
        #wpcontent { padding-left: 0 !important; }
        #wpbody-content { padding-bottom: 0 !important; }
        .wrap { margin: 0 !important; max-width: none !important; }
        .brz-admin-wrap { margin: 0 !important; padding: 0 !important; }
        .brz-content-wrapper { margin: 0 !important; padding: 0 !important; max-width: none !important; }
        .brz-admin-wrap .brz-hero:not(:first-of-type) { display: none; }
        .brz-admin-wrap .brz-side-nav:not(:first-of-type) { display: none; }
        
        /* Hero Section - با فاصله مناسب */
        .brz-sl-hero { 
            margin: 0; 
            padding: 28px 40px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            display: flex; 
            align-items: center; 
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
        }
        .brz-sl-hero h2 { margin: 0 0 6px 0; font-size: 26px; color: #fff; font-weight: 700; }
        .brz-sl-hero p { margin: 0; color: rgba(255,255,255,0.9); font-size: 14px; }
        .brz-sl-badge { 
            background: rgba(255,255,255,0.2); 
            backdrop-filter: blur(10px);
            color: #fff; 
            border-radius: 999px; 
            padding: 8px 16px; 
            font-size: 12px; 
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        /* Shell Container - فاصله مناسب از اطراف */
        .brz-sl-shell { 
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%); 
            padding: 24px 40px 40px 40px; 
            min-height: calc(100vh - 200px);
        }
        
        /* Tabs - طراحی مدرن */
        .brz-sl-tabs { 
            display: flex; 
            gap: 8px; 
            flex-wrap: wrap; 
            margin: 0 0 24px 0; 
            padding: 16px 20px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .brz-sl-tab { 
            border: none; 
            border-radius: 10px; 
            padding: 12px 20px; 
            background: #f1f5f9; 
            color: #475569; 
            text-decoration: none; 
            font-size: 14px; 
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .brz-sl-tab:hover { 
            background: #e2e8f0; 
            color: #1e293b;
            transform: translateY(-1px);
        }
        .brz-sl-tab.is-active { 
            background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%); 
            color: #fff; 
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        .brz-sl-count { 
            background: #ef4444; 
            color: #fff; 
            border-radius: 999px; 
            padding: 2px 8px; 
            font-size: 11px; 
            margin-right: 6px;
        }
        
        /* Content Cards */
        .brz-sl-card {
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }
        .brz-sl-card h3 {
            margin: 0 0 12px 0;
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
        }
        .brz-sl-card p {
            margin: 0 0 20px 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }
        
        /* Buttons - دکمه‌های مدرن */
        .brz-sl-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .brz-sl-btn--primary {
            background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
        }
        .brz-sl-btn--primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
        }
        .brz-sl-btn--secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
        .brz-sl-btn--secondary:hover {
            background: #e2e8f0;
        }
        
        /* Stats Grid */
        .brz-sl-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .brz-sl-stat {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 16px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid #bae6fd;
        }
        .brz-sl-stat strong {
            display: block;
            font-size: 24px;
            font-weight: 700;
            color: #0369a1;
        }
        .brz-sl-stat span {
            font-size: 12px;
            color: #0c4a6e;
        }
        
        /* Textareas */
        .brz-sl-textarea {
            width: 100%;
            min-height: 200px;
            padding: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Vazirmatn', monospace;
            font-size: 13px;
            line-height: 1.6;
            resize: vertical;
            background: #f8fafc;
        }
        .brz-sl-textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        /* Messages */
        .brz-sl-success {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            border: 1px solid #86efac;
            color: #166534;
            padding: 16px 20px;
            border-radius: 12px;
            margin-top: 16px;
        }
        .brz-sl-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #fcd34d;
            color: #92400e;
            padding: 16px 20px;
            border-radius: 12px;
            margin-top: 16px;
        }
        
        /* Two Column Layout */
        .brz-sl-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .brz-sl-hero { padding: 20px; }
            .brz-sl-shell { padding: 16px; }
            .brz-sl-tabs { padding: 12px; }
            .brz-sl-grid { grid-template-columns: 1fr; }
        }
        </style>

        <div class="brz-sl-hero">
            <div>
                <h2>لینک‌ساز هوشمند v3.1</h2>
                <p>لینک‌سازی داخلی هوشمند با AI - سینک یکپارچه بین سایت‌ها</p>
            </div>
            <span class="brz-sl-badge">Smart Linker</span>
        </div>

        <div class="brz-sl-shell">
            <div class="brz-sl-tabs" role="tablist">
                <a class="brz-sl-tab <?php echo ( 'export' === $active_tab ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=export' ) ); ?>">📤 خروجی</a>
                <a class="brz-sl-tab <?php echo ( 'import' === $active_tab ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=import' ) ); ?>">📥 ورودی</a>
                <?php $pending_count = BRZ_Smart_Linker_DB::get_pending_counts(); ?>
                <a class="brz-sl-tab <?php echo ( 'review' === $active_tab ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=review' ) ); ?>">✅ بررسی <?php if ( $pending_count['pending'] > 0 ) : ?><span class="brz-sl-count"><?php echo esc_html( $pending_count['pending'] ); ?></span><?php endif; ?></a>
                <a class="brz-sl-tab <?php echo ( 'applied' === $active_tab ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=applied' ) ); ?>">🔗 اعمال‌شده</a>
                <a class="brz-sl-tab <?php echo ( 'analytics' === $active_tab ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=analytics' ) ); ?>">📊 آنالیز</a>
                <a class="brz-sl-tab <?php echo ( 'strategy' === $active_tab ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=strategy' ) ); ?>">⚙️ تنظیمات</a>
                <a class="brz-sl-tab <?php echo ( 'maintenance' === $active_tab ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=maintenance' ) ); ?>">🔧 نگهداری</a>
            </div>

            <div>
                <?php
                if ( 'export' === $active_tab ) {
                    self::render_export_tab( $settings );
                } elseif ( 'import' === $active_tab ) {
                    self::render_import_tab( $settings );
                } elseif ( 'review' === $active_tab ) {
                    self::render_review_tab( $settings );
                } elseif ( 'applied' === $active_tab ) {
                    self::render_applied_tab( $settings );
                } elseif ( 'analytics' === $active_tab ) {
                    self::render_analytics_tab( $settings );
                } elseif ( 'strategy' === $active_tab ) {
                    self::render_strategy_tab( $settings );
                    self::render_exclusions_tab( $settings );
                } else {
                    self::render_maintenance_tab( $settings );
                }
                ?>
            </div>
        </div>
        <?php self::render_inline_js(); ?>
        <?php
    }

    private static function render_general_tab( $settings ) {
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header"><h3>تنظیمات اتصال</h3></div>
            <div class="brz-card__body">
                <p>تنظیمات اتصال (API Key، نقش سایت، حالت کار) فقط از صفحه «اتصالات» مدیریت می‌شود.</p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-connections' ) ); ?>">رفتن به تنظیمات اتصالات</a>
            </div>
        </div>
        <?php
    }

    // ============================
    // v3.0 Tab Render Methods
    // ============================

    /**
     * Render Export tab - Generate JSON and AI prompt.
     */
    private static function render_export_tab( $settings ) {
        // Determine site type for appropriate labels
        $site_role = isset( $settings['site_role'] ) ? $settings['site_role'] : 'blog';
        $is_shop   = ( 'shop' === $site_role );
        ?>
        <style>
        .brz-sl-export-card { 
            background: #fff; 
            border: 1px solid #e2e8f0; 
            border-radius: 16px; 
            padding: 28px; 
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .brz-sl-export-card h3 { 
            margin: 0 0 12px 0; 
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
        }
        .brz-sl-export-card p {
            margin: 0 0 20px 0;
            color: #64748b;
            font-size: 14px;
        }
        .brz-sl-export-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 20px; 
        }
        @media (max-width: 1200px) { 
            .brz-sl-export-grid { grid-template-columns: 1fr; } 
        }
        .brz-sl-stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); 
            gap: 12px; 
            margin: 20px 0; 
        }
        .brz-sl-stat-item { 
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 16px; 
            border-radius: 12px; 
            text-align: center;
            border: 1px solid #bae6fd;
        }
        .brz-sl-stat-item strong { 
            display: block;
            font-size: 22px; 
            font-weight: 700;
            color: #0369a1; 
        }
        .brz-sl-stat-item span { 
            font-size: 12px; 
            color: #0c4a6e;
        }
        .brz-sl-export-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 16px 32px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
            transition: all 0.2s ease;
        }
        .brz-sl-export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
        }
        .brz-sl-export-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .brz-sl-textarea { 
            width: 100%; 
            min-height: 280px; 
            padding: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Vazirmatn', monospace; 
            font-size: 12px; 
            direction: ltr;
            background: #f8fafc;
            resize: vertical;
        }
        .brz-sl-textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .brz-sl-copy-btn { 
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px; 
            border-radius: 8px; 
            border: 1px solid #e2e8f0;
            background: #f1f5f9;
            color: #475569;
            cursor: pointer; 
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .brz-sl-copy-btn:hover { 
            background: #e2e8f0; 
        }
        .brz-sl-warning { 
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #fcd34d;
            color: #92400e;
            padding: 16px 20px;
            border-radius: 12px;
            margin-top: 16px;
            font-size: 14px;
        }
        .brz-sl-success { 
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            border: 1px solid #86efac;
            color: #166534;
            padding: 16px 20px;
            border-radius: 12px;
            margin-top: 16px;
            font-size: 14px;
        }
        </style>

        <div class="brz-sl-export-card">
            <h3>📤 تولید Export یکپارچه</h3>
            <p>با یک کلیک، داده‌های هر دو سایت (محلی و همتا) ایندکس و ترکیب می‌شوند و JSON + پرامپت آماده می‌شود.</p>
            
            <div class="brz-sl-stats-grid" id="brz-sl-export-stats">
                <?php if ( $is_shop ) : ?>
                    <div class="brz-sl-stat-item"><strong>0</strong><span>محصولات</span></div>
                    <div class="brz-sl-stat-item"><strong>0</strong><span>صفحات</span></div>
                    <div class="brz-sl-stat-item"><strong>0</strong><span>دسته‌بندی محصولات</span></div>
                    <div class="brz-sl-stat-item"><strong>0</strong><span>تگ محصولات</span></div>
                <?php else : ?>
                    <div class="brz-sl-stat-item"><strong>0</strong><span>مقالات</span></div>
                    <div class="brz-sl-stat-item"><strong>0</strong><span>صفحات</span></div>
                <?php endif; ?>
            </div>
            
            <button type="button" class="brz-sl-export-btn" id="brz-sl-generate-export">⚡ تولید Export یکپارچه</button>
            <div id="brz-sl-export-message"></div>
        </div>

        <div class="brz-sl-export-grid">
            <div class="brz-sl-export-card">
                <h3>📋 پرامپت AI</h3>
                <textarea class="brz-sl-textarea" id="brz-sl-prompt" readonly placeholder="ابتدا روی «تولید Export یکپارچه» کلیک کنید..."></textarea>
                <div style="margin-top: 12px;">
                    <button type="button" class="brz-sl-copy-btn" onclick="navigator.clipboard.writeText(document.getElementById('brz-sl-prompt').value);this.innerHTML='✅ کپی شد';setTimeout(()=>this.innerHTML='📋 کپی پرامپت',1500);">📋 کپی پرامپت</button>
                </div>
            </div>
            <div class="brz-sl-export-card">
                <h3>📄 فایل JSON</h3>
                <textarea class="brz-sl-textarea" id="brz-sl-json" readonly placeholder="ابتدا روی «تولید Export یکپارچه» کلیک کنید..."></textarea>
                <div style="margin-top: 12px; display: flex; gap: 12px;">
                    <button type="button" class="brz-sl-copy-btn" id="brz-sl-download-json">💾 دانلود JSON</button>
                    <button type="button" class="brz-sl-copy-btn" onclick="navigator.clipboard.writeText(document.getElementById('brz-sl-json').value);this.innerHTML='✅ کپی شد';setTimeout(()=>this.innerHTML='📋 کپی JSON',1500);">📋 کپی JSON</button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var nonce = '<?php echo wp_create_nonce( 'brz_smart_linker_export' ); ?>';
            var isShop = <?php echo $is_shop ? 'true' : 'false'; ?>;
            
            document.getElementById('brz-sl-generate-export').onclick = function() {
                var btn = this; 
                btn.disabled = true; 
                btn.innerHTML = '⏳ در حال پردازش... (ممکن است چند ثانیه طول بکشد)';
                document.getElementById('brz-sl-export-message').innerHTML = '';
                
                jQuery.post(ajaxurl, {action: 'brz_smart_linker_export', _ajax_nonce: nonce}, function(r) {
                    btn.disabled = false; 
                    btn.textContent = '⚡ تولید Export یکپارچه';
                    
                    if (r.success) {
                        document.getElementById('brz-sl-prompt').value = r.data.prompt;
                        document.getElementById('brz-sl-json').value = JSON.stringify(r.data.json, null, 2);
                        
                        var c = r.data.json.meta.counts;
                        var peerCount = r.data.json.meta.peer_count || 0;
                        
                        // Generate stats based on site type
                        var statsHtml = '';
                        if (isShop) {
                            statsHtml = '<div class="brz-sl-stat-item"><strong>'+c.products+'</strong><span>محصولات</span></div>' +
                                '<div class="brz-sl-stat-item"><strong>'+c.pages+'</strong><span>صفحات</span></div>' +
                                '<div class="brz-sl-stat-item"><strong>'+c.product_categories+'</strong><span>دسته‌بندی محصولات</span></div>' +
                                '<div class="brz-sl-stat-item"><strong>'+c.tags+'</strong><span>تگ محصولات</span></div>';
                        } else {
                            statsHtml = '<div class="brz-sl-stat-item"><strong>'+c.posts+'</strong><span>مقالات</span></div>' +
                                '<div class="brz-sl-stat-item"><strong>'+c.pages+'</strong><span>صفحات</span></div>';
                        }
                        document.getElementById('brz-sl-export-stats').innerHTML = statsHtml;
                        
                        // Show success or warning message
                        var msgDiv = document.getElementById('brz-sl-export-message');
                        if (r.data.warning) {
                            msgDiv.innerHTML = '<div class="brz-sl-warning">⚠️ ' + r.data.warning + '</div>';
                        } else {
                            msgDiv.innerHTML = '<div class="brz-sl-success">✅ Export موفق! ' + r.data.json.meta.total_items + ' آیتم از ' + (peerCount > 0 ? '2 سایت' : 'سایت محلی') + ' دریافت شد.</div>';
                        }
                    } else {
                        document.getElementById('brz-sl-export-message').innerHTML = '<div class="brz-sl-warning">❌ خطا: ' + (r.data.message || 'خطای ناشناخته') + '</div>';
                    }
                }).fail(function() {
                    btn.disabled = false;
                    btn.textContent = '⚡ تولید Export یکپارچه';
                    document.getElementById('brz-sl-export-message').innerHTML = '<div class="brz-sl-warning">❌ خطای شبکه</div>';
                });
            };
            
            document.getElementById('brz-sl-download-json').onclick = function() {
                var j = document.getElementById('brz-sl-json').value; 
                if(!j) { alert('ابتدا Export تولید کنید'); return; }
                var a = document.createElement('a'); 
                a.href = URL.createObjectURL(new Blob([j],{type:'application/json'}));
                a.download = 'smart-linker-export.json'; 
                a.click();
            };
        })();
        </script>
        <?php
    }

    private static function render_import_tab( $settings ) {
        ?>
        <div class="brz-sl-card">
            <h3>📥 وارد کردن پاسخ AI</h3>
            <p>پاسخ JSON که از ChatGPT یا Gemini دریافت کردید را اینجا قرار دهید.</p>
            <form id="brz-sl-import-form">
                <?php wp_nonce_field( 'brz_smart_linker_import', '_ajax_nonce' ); ?>
                <textarea class="brz-sl-textarea" name="json" id="brz-sl-import-json" placeholder='[{"source_id": 123, "keyword": "...", "target_id": 456, "target_url": "..."}]'></textarea>
                <div style="margin-top: 12px;">
                    <button type="submit" class="brz-sl-btn brz-sl-btn--primary">📥 وارد کردن</button>
                    <span id="brz-sl-import-status" style="margin-right: 12px;"></span>
                </div>
            </form>
        </div>
        <script>
        document.getElementById('brz-sl-import-form').onsubmit = function(e) {
            e.preventDefault(); var s = document.getElementById('brz-sl-import-status'), btn = this.querySelector('button');
            btn.disabled = true; btn.textContent = '⏳...';
            jQuery.post(ajaxurl, {action: 'brz_smart_linker_import', _ajax_nonce: this._ajax_nonce.value, json: document.getElementById('brz-sl-import-json').value}, function(r) {
                btn.disabled = false; btn.textContent = '📥 وارد کردن';
                s.innerHTML = r.success ? '<span style="color:green">✅ '+r.data.message+'</span>' : '<span style="color:red">❌ '+r.data.message+'</span>';
            });
        };
        </script>
        <?php
    }

    private static function render_review_tab( $settings ) {
        $links = BRZ_Smart_Linker_Importer::get_links_with_preview( 'pending', 100 );
        $counts = BRZ_Smart_Linker_DB::get_pending_counts();
        ?>
        <div class="brz-sl-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3 style="margin:0;">✅ بررسی لینک‌ها</h3>
                    <p style="margin:8px 0 0;color:#64748b;">منتظر: <strong><?php echo esc_html( $counts['pending'] ); ?></strong> | تأیید: <strong><?php echo esc_html( $counts['approved'] ); ?></strong></p>
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="brz-sl-btn brz-sl-btn--primary" id="brz-sl-approve-all" <?php echo empty($links)?'disabled':''; ?>>✅ تأیید همه</button>
                    <?php if ( $counts['approved'] > 0 ) : ?>
                    <button class="brz-sl-btn brz-sl-btn--primary" id="brz-sl-apply-approved" style="background:linear-gradient(135deg,#16a34a,#059669);">🚀 اعمال <?php echo esc_html($counts['approved']); ?> لینک</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ( empty( $links ) ) : ?>
                <div class="brz-sl-empty"><p style="font-size:48px;margin:0;">📭</p><p>لینکی وجود ندارد.</p></div>
            <?php else : ?>
                <table class="brz-sl-review-table"><thead><tr><th>مبدأ</th><th>کلمه</th><th>مقصد</th><th>اولویت</th><th>عمل</th></tr></thead><tbody>
                <?php foreach ( $links as $link ) : ?>
                <tr data-id="<?php echo esc_attr($link['id']); ?>">
                    <td><a href="<?php echo esc_url($link['source_edit_url']); ?>" target="_blank"><?php echo esc_html($link['source_title']); ?></a><div class="brz-sl-context"><?php echo $link['context']; ?></div></td>
                    <td><strong><?php echo esc_html($link['keyword']); ?></strong></td>
                    <td><a href="<?php echo esc_url($link['target_url']); ?>" target="_blank"><?php echo esc_html($link['target_title']); ?></a></td>
                    <td><span class="brz-sl-priority brz-sl-priority--<?php echo esc_attr($link['priority']); ?>"><?php echo esc_html($link['priority']); ?></span></td>
                    <td><div class="brz-sl-actions"><button class="brz-sl-action-btn brz-sl-action-btn--approve" data-id="<?php echo esc_attr($link['id']); ?>">✅</button><button class="brz-sl-action-btn brz-sl-action-btn--reject" data-id="<?php echo esc_attr($link['id']); ?>">❌</button></div></td>
                </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <script>
        (function() {
            var n = '<?php echo wp_create_nonce('brz_smart_linker_review'); ?>', an = '<?php echo wp_create_nonce('brz_smart_linker_apply'); ?>';
            function upd(ids,s,cb){jQuery.post(ajaxurl,{action:'brz_smart_linker_update_status',_ajax_nonce:n,ids:ids,status:s},cb);}
            document.querySelectorAll('.brz-sl-action-btn--approve').forEach(function(b){b.onclick=function(){upd([this.dataset.id],'approved',function(){location.reload();});}});
            document.querySelectorAll('.brz-sl-action-btn--reject').forEach(function(b){b.onclick=function(){upd([this.dataset.id],'rejected',function(){location.reload();});}});
            document.getElementById('brz-sl-approve-all')?.addEventListener('click',function(){var ids=[];document.querySelectorAll('tr[data-id]').forEach(function(r){ids.push(r.dataset.id);});if(ids.length&&confirm('تأیید '+ids.length+' لینک?'))upd(ids,'approved',function(){location.reload();});});
            document.getElementById('brz-sl-apply-approved')?.addEventListener('click',function(){if(!confirm('اعمال لینک‌ها?'))return;this.disabled=true;this.textContent='⏳...';jQuery.post(ajaxurl,{action:'brz_smart_linker_apply_links',_ajax_nonce:an},function(r){alert(r.success?r.data.message:r.data.message);location.reload();});});
        })();
        </script>
        <?php
    }

    private static function render_applied_tab( $settings ) {
        $links = BRZ_Smart_Linker_DB::get_pending_links( 'applied', 100 );
        ?>
        <div class="brz-sl-card">
            <h3>🔗 لینک‌های اعمال‌شده</h3>
            <?php if ( empty( $links ) ) : ?>
                <div class="brz-sl-empty"><p style="font-size:48px;margin:0;">📝</p><p>هنوز لینکی اعمال نشده.</p></div>
            <?php else : ?>
                <table class="brz-sl-review-table"><thead><tr><th>مبدأ</th><th>کلمه</th><th>مقصد</th><th>تاریخ</th></tr></thead><tbody>
                <?php foreach ( $links as $link ) : $p=get_post($link['source_id']); ?>
                <tr><td><?php echo $p?esc_html(get_the_title($p)):'(حذف)'; ?></td><td><strong><?php echo esc_html($link['keyword']); ?></strong></td><td><a href="<?php echo esc_url($link['target_url']); ?>" target="_blank"><?php echo esc_html($link['target_url']); ?></a></td><td><?php echo esc_html($link['applied_at']); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_strategy_tab( $settings ) {

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" id="brz-sl-strategy-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=strategy' ) ); ?>" />
            
            <h3 style="margin-top:0;">⚙️ تنظیمات لینک‌سازی</h3>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="brz-sl-density">چگالی لینک (در هر 1000 کلمه)</label></th>
                        <td>
                            <input type="range" id="brz-sl-density" min="0" max="15" step="1" value="<?php echo esc_attr( (int) $settings['link_density'] ); ?>" oninput="document.getElementById('brz-sl-density-val').textContent=this.value;" />
                            <input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[link_density]" id="brz-sl-density-hidden" value="<?php echo esc_attr( (int) $settings['link_density'] ); ?>" />
                            <span class="description" style="margin-right:8px;">مقدار فعلی: <strong id="brz-sl-density-val"><?php echo esc_html( (int) $settings['link_density'] ); ?></strong></span>
                            <p class="description">تعداد حداکثر لینک‌های داخلی که برای هر ۱۰۰۰ کلمه پیشنهاد/تزریق می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ویژگی‌های لینک</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[open_new_tab]" value="1" <?php checked( ! empty( $settings['open_new_tab'] ) ); ?> /> باز شدن در تب جدید</label><br/>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[nofollow]" value="1" <?php checked( ! empty( $settings['nofollow'] ) ); ?> /> افزودن rel="nofollow"</label>
                            <p class="description">برای لینک‌های تزریق‌شده اعمال می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Self-Linking</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[prevent_self]" value="1" <?php checked( ! empty( $settings['prevent_self'] ) ); ?> /> جلوگیری از لینک به همان صفحه</label>
                            <p class="description">اگر مقصد برابر URL همان پست باشد، لینک ساخته نمی‌شود.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top:24px;">📊 تنظیمات تب آنالیز</h3>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">ستون‌های نمایشی</th>
                        <td>
                            <label style="display:inline-block;margin-left:16px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[analytics_show_type]" value="1" <?php checked( isset( $settings['analytics_show_type'] ) ? $settings['analytics_show_type'] : true ); ?> /> نوع</label>
                            <label style="display:inline-block;margin-left:16px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[analytics_show_site]" value="1" <?php checked( isset( $settings['analytics_show_site'] ) ? $settings['analytics_show_site'] : true ); ?> /> سایت</label>
                            <label style="display:inline-block;margin-left:16px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[analytics_show_outbound]" value="1" <?php checked( isset( $settings['analytics_show_outbound'] ) ? $settings['analytics_show_outbound'] : true ); ?> /> لینک خروجی</label>
                            <label style="display:inline-block;margin-left:16px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[analytics_show_inbound]" value="1" <?php checked( isset( $settings['analytics_show_inbound'] ) ? $settings['analytics_show_inbound'] : true ); ?> /> لینک ورودی</label>
                            <label style="display:inline-block;margin-left:16px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[analytics_show_keyword]" value="1" <?php checked( isset( $settings['analytics_show_keyword'] ) ? $settings['analytics_show_keyword'] : true ); ?> /> کلمه کلیدی</label>
                            <label style="display:inline-block;margin-left:16px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[analytics_show_seo]" value="1" <?php checked( isset( $settings['analytics_show_seo'] ) ? $settings['analytics_show_seo'] : true ); ?> /> وضعیت SEO</label>
                            <label style="display:inline-block;margin-left:16px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[analytics_show_wordcount]" value="1" <?php checked( ! empty( $settings['analytics_show_wordcount'] ) ); ?> /> تعداد کلمات</label>
                            <label style="display:inline-block;margin-left:16px;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[analytics_show_updated]" value="1" <?php checked( ! empty( $settings['analytics_show_updated'] ) ); ?> /> آخرین بروزرسانی</label>
                            <p class="description">ستون‌های انتخاب‌شده در تب آنالیز نمایش داده می‌شوند.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top:24px;">🗑️ تنظیمات حذف</h3>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">حذف داده‌ها</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> /> حذف تمام جداول دیتابیس هنگام حذف افزونه</label>
                            <p class="description" style="color:#dc2626;">⚠️ با فعال کردن این گزینه، هنگام حذف (Uninstall) افزونه، تمام داده‌های Smart Linker از جمله ایندکس محتوا و لینک‌های pending پاک می‌شوند.</p>
                            <p class="description" style="color:#059669;">✅ توجه: لینک‌های اعمال‌شده در محتوا حفظ می‌شوند زیرا مستقیماً در post_content ذخیره شده‌اند.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    private static function render_exclusions_tab( $settings ) {
        $post_types = array(
            'post'    => 'نوشته',
            'product' => 'محصول',
            'page'    => 'برگه',
        );
        $selected_pt = is_array( $settings['exclude_post_types'] ) ? $settings['exclude_post_types'] : array();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=exclusions' ) ); ?>" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">پست‌تایپ‌ها</th>
                        <td>
                            <?php foreach ( $post_types as $slug => $label ) : ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[exclude_post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_pt, true ) ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">پست‌تایپ‌های انتخاب‌شده از فرآیند پیشنهاد/تزریق مستثنا می‌شوند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-exclude-cats">دسته‌های مستثنا</label></th>
                        <td>
                            <input type="text" id="brz-sl-exclude-cats" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[exclude_categories]" class="regular-text" value="<?php echo esc_attr( $settings['exclude_categories'] ); ?>" />
                            <p class="description">اسلاگ یا ID دسته‌ها را با کاما جدا کنید (مثال: news,offers).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-exclude-tags">تگ‌های HTML ممنوع</label></th>
                        <td>
                            <input type="text" id="brz-sl-exclude-tags" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[exclude_html_tags]" class="regular-text" value="<?php echo esc_attr( $settings['exclude_html_tags'] ); ?>" />
                            <p class="description">لیست تگ‌هایی که نباید درون آنها لینک قرار گیرد (کاما جدا): h1,h2,h3,strong</p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    /**
     * Render Analytics tab - shows all content with link counts
     */
    private static function render_analytics_tab( $settings ) {
        // Get all content for analytics (including noindex items)
        $all_content = BRZ_Smart_Linker_DB::get_content_index();
        
        // Get analytics settings (which columns to show)
        $show_type = isset( $settings['analytics_show_type'] ) ? $settings['analytics_show_type'] : true;
        $show_site = isset( $settings['analytics_show_site'] ) ? $settings['analytics_show_site'] : true;
        $show_outbound = isset( $settings['analytics_show_outbound'] ) ? $settings['analytics_show_outbound'] : true;
        $show_inbound = isset( $settings['analytics_show_inbound'] ) ? $settings['analytics_show_inbound'] : true;
        $show_keyword = isset( $settings['analytics_show_keyword'] ) ? $settings['analytics_show_keyword'] : true;
        $show_seo = isset( $settings['analytics_show_seo'] ) ? $settings['analytics_show_seo'] : true;
        $show_wordcount = isset( $settings['analytics_show_wordcount'] ) ? $settings['analytics_show_wordcount'] : false;
        $show_updated = isset( $settings['analytics_show_updated'] ) ? $settings['analytics_show_updated'] : false;
        ?>
        <style>
        .brz-analytics-filters { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; align-items: center; }
        .brz-analytics-filters select, .brz-analytics-filters input { padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; }
        .brz-analytics-filters input[type="search"] { width: 250px; }
        .brz-analytics-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .brz-analytics-table th { background: linear-gradient(135deg, #1e293b, #334155); color: #fff; padding: 14px 12px; text-align: right; font-weight: 500; font-size: 13px; }
        .brz-analytics-table td { padding: 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
        .brz-analytics-table tr:hover { background: #f8fafc; }
        .brz-analytics-table tr.noindex { background: #fef3c7; }
        .brz-analytics-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
        .brz-analytics-badge--index { background: #d1fae5; color: #065f46; }
        .brz-analytics-badge--noindex { background: #fef3c7; color: #92400e; }
        .brz-analytics-badge--type { background: #e0e7ff; color: #3730a3; }
        .brz-analytics-badge--site { background: #f3e8ff; color: #7c3aed; }
        .brz-analytics-count { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; border-radius: 50%; font-weight: 600; font-size: 12px; }
        .brz-analytics-count--out { background: #dbeafe; color: #1e40af; }
        .brz-analytics-count--in { background: #dcfce7; color: #166534; }
        .brz-analytics-stats { display: flex; gap: 16px; margin-bottom: 20px; }
        .brz-analytics-stat { background: #fff; padding: 16px 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .brz-analytics-stat strong { font-size: 24px; color: #2563eb; display: block; }
        .brz-analytics-stat span { font-size: 12px; color: #64748b; }
        </style>

        <div class="brz-analytics-stats">
            <div class="brz-analytics-stat"><strong id="brz-total-count"><?php echo count( $all_content ); ?></strong><span>کل محتوا</span></div>
            <div class="brz-analytics-stat"><strong id="brz-linkable-count"><?php echo count( array_filter( $all_content, function( $item ) { return ! empty( $item['is_linkable'] ); } ) ); ?></strong><span>قابل لینک (index)</span></div>
            <div class="brz-analytics-stat"><strong id="brz-noindex-count"><?php echo count( array_filter( $all_content, function( $item ) { return empty( $item['is_linkable'] ); } ) ); ?></strong><span>noindex</span></div>
        </div>

        <div class="brz-analytics-filters">
            <select id="brz-filter-type">
                <option value="">همه انواع</option>
                <option value="product">محصول</option>
                <option value="post">مقاله</option>
                <option value="page">صفحه</option>
                <option value="term_product_cat">دسته محصول</option>
                <option value="term_category">دسته مقاله</option>
            </select>
            <select id="brz-filter-site">
                <option value="">همه سایت‌ها</option>
                <option value="local">محلی</option>
                <option value="shop">فروشگاه</option>
                <option value="blog">وبلاگ</option>
            </select>
            <select id="brz-filter-seo">
                <option value="">همه وضعیت‌ها</option>
                <option value="index">index</option>
                <option value="noindex">noindex</option>
            </select>
            <input type="search" id="brz-filter-search" placeholder="جستجو در عنوان...">
            <button type="button" class="brz-sl-btn brz-sl-btn--secondary" id="brz-refresh-analytics">🔄 بروزرسانی</button>
        </div>

        <table class="brz-analytics-table">
            <thead>
                <tr>
                    <th>عنوان</th>
                    <?php if ( $show_type ) : ?><th>نوع</th><?php endif; ?>
                    <?php if ( $show_site ) : ?><th>سایت</th><?php endif; ?>
                    <?php if ( $show_outbound ) : ?><th>خروجی</th><?php endif; ?>
                    <?php if ( $show_inbound ) : ?><th>ورودی</th><?php endif; ?>
                    <?php if ( $show_keyword ) : ?><th>کلمه کلیدی</th><?php endif; ?>
                    <?php if ( $show_seo ) : ?><th>SEO</th><?php endif; ?>
                    <?php if ( $show_wordcount ) : ?><th>کلمات</th><?php endif; ?>
                    <?php if ( $show_updated ) : ?><th>آخرین بروزرسانی</th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="brz-analytics-body">
                <?php foreach ( $all_content as $item ) : 
                    $is_linkable = ! empty( $item['is_linkable'] );
                    $type_labels = array(
                        'product' => 'محصول',
                        'post' => 'مقاله',
                        'page' => 'صفحه',
                        'term_product_cat' => 'دسته محصول',
                        'term_category' => 'دسته مقاله',
                        'term_post_tag' => 'برچسب',
                    );
                    $type_label = isset( $type_labels[ $item['post_type'] ] ) ? $type_labels[ $item['post_type'] ] : $item['post_type'];
                ?>
                <tr class="<?php echo $is_linkable ? '' : 'noindex'; ?>" 
                    data-type="<?php echo esc_attr( $item['post_type'] ); ?>"
                    data-site="<?php echo esc_attr( $item['site_id'] ); ?>"
                    data-seo="<?php echo $is_linkable ? 'index' : 'noindex'; ?>"
                    data-title="<?php echo esc_attr( strtolower( $item['title'] ) ); ?>">
                    <td>
                        <a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" style="color: #2563eb; text-decoration: none;">
                            <?php echo esc_html( $item['title'] ); ?>
                        </a>
                    </td>
                    <?php if ( $show_type ) : ?><td><span class="brz-analytics-badge brz-analytics-badge--type"><?php echo esc_html( $type_label ); ?></span></td><?php endif; ?>
                    <?php if ( $show_site ) : ?><td><span class="brz-analytics-badge brz-analytics-badge--site"><?php echo esc_html( $item['site_id'] ); ?></span></td><?php endif; ?>
                    <?php if ( $show_outbound ) : ?><td><span class="brz-analytics-count brz-analytics-count--out">0</span></td><?php endif; ?>
                    <?php if ( $show_inbound ) : ?><td><span class="brz-analytics-count brz-analytics-count--in">0</span></td><?php endif; ?>
                    <?php if ( $show_keyword ) : ?><td><?php echo esc_html( $item['focus_keyword'] ?: '—' ); ?></td><?php endif; ?>
                    <?php if ( $show_seo ) : ?><td><span class="brz-analytics-badge <?php echo $is_linkable ? 'brz-analytics-badge--index' : 'brz-analytics-badge--noindex'; ?>"><?php echo $is_linkable ? 'index' : 'noindex'; ?></span></td><?php endif; ?>
                    <?php if ( $show_wordcount ) : ?><td><?php echo esc_html( $item['word_count'] ?: '—' ); ?></td><?php endif; ?>
                    <?php if ( $show_updated ) : ?><td style="font-size:11px;"><?php echo esc_html( $item['last_synced'] ); ?></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <script>
        (function() {
            function filterTable() {
                var type = document.getElementById('brz-filter-type').value;
                var site = document.getElementById('brz-filter-site').value;
                var seo = document.getElementById('brz-filter-seo').value;
                var search = document.getElementById('brz-filter-search').value.toLowerCase();
                var rows = document.querySelectorAll('#brz-analytics-body tr');
                var shown = 0;
                
                rows.forEach(function(row) {
                    var show = true;
                    if (type && row.dataset.type !== type) show = false;
                    if (site && row.dataset.site !== site) show = false;
                    if (seo && row.dataset.seo !== seo) show = false;
                    if (search && row.dataset.title.indexOf(search) === -1) show = false;
                    row.style.display = show ? '' : 'none';
                    if (show) shown++;
                });
                document.getElementById('brz-total-count').textContent = shown;
            }
            
            document.getElementById('brz-filter-type').onchange = filterTable;
            document.getElementById('brz-filter-site').onchange = filterTable;
            document.getElementById('brz-filter-seo').onchange = filterTable;
            document.getElementById('brz-filter-search').oninput = filterTable;
            
            document.getElementById('brz-refresh-analytics').onclick = function() {
                location.reload();
            };
        })();
        </script>
        <?php
    }

    private static function render_maintenance_tab( $settings ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        $redirect = admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=maintenance' );
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header">
                <h3>ابزارهای پاکسازی</h3>
                <p>حفظ سلامت دیتابیس لینک‌ها.</p>
            </div>
            <div class="brz-card__body">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
                    <?php wp_nonce_field( 'brz_smart_linker_clear_logs' ); ?>
                    <input type="hidden" name="action" value="brz_smart_linker_clear_logs" />
                    <input type="hidden" name="redirect" value="<?php echo esc_url( $redirect ); ?>" />
                    <?php submit_button( 'Clear Logs', 'secondary', 'submit', false, array( 'onclick' => "return confirm('تمامی ردیف‌های لاگ حذف شود؟');" ) ); ?>
                    <p class="description">تمامی رکوردهای جدول smart_links_log حذف می‌شوند.</p>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'brz_smart_linker_purge_pending' ); ?>
                    <input type="hidden" name="action" value="brz_smart_linker_purge_pending" />
                    <input type="hidden" name="redirect" value="<?php echo esc_url( $redirect ); ?>" />
                    <?php submit_button( 'Purge Pending Links', 'delete', 'submit', false, array( 'onclick' => "return confirm('تمامی رکوردهای pending حذف شوند؟');" ) ); ?>
                    <p class="description">فقط رکوردهای در وضعیت pending پاک می‌شوند تا صف صفر شود.</p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Workbench UI for manual flow.
     */
    private static function render_workbench_tab( $settings ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header">
                <h3>میز کار لینک‌سازی</h3>
                <p>سه گام دستی: انتخاب محتوا، ساخت پرامپت، اعمال پاسخ.</p>
            </div>
            <div class="brz-card__body">
                <ol class="brz-checklist">
                    <li><strong>گام ۱: انتخاب محتوا</strong></li>
                </ol>
                <div style="margin-bottom:16px;">
                    <select id="brz-sl-workbench-post" style="width:100%;" aria-label="انتخاب پست/محصول">
                        <option value="">-- انتخاب پست یا محصول --</option>
                    </select>
                    <button type="button" class="button button-primary" id="brz-sl-analyze-btn" style="margin-top:8px;">Analyze &amp; Prepare Prompt</button>
                    <span class="description" id="brz-sl-analyze-status"></span>
                </div>

                <ol class="brz-checklist" start="2">
                    <li><strong>گام ۲: پرامپت</strong></li>
                </ol>
                <textarea id="brz-sl-prompt" class="large-text code" rows="8" readonly></textarea>
                <button type="button" class="button" id="brz-sl-copy-prompt" style="margin-top:6px;">Copy to Clipboard</button>

                <ol class="brz-checklist" start="3">
                    <li><strong>گام ۳: پاسخ مدل</strong></li>
                </ol>
                <textarea id="brz-sl-response" class="large-text code" rows="8" placeholder='Paste JSON response here'></textarea>
                <button type="button" class="button button-primary" id="brz-sl-apply-btn" style="margin-top:6px;">Process &amp; Apply</button>
                <span class="description" id="brz-sl-apply-status"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Save settings handler.
     */
    public static function handle_save_settings() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permission denied', 'buyruz-settings' ) );
        }
        check_admin_referer( 'brz_smart_linker_save' );

        $input   = isset( $_POST[ self::OPTION_KEY ] ) ? (array) wp_unslash( $_POST[ self::OPTION_KEY ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $cleaned = self::sanitize_settings( $input );
        update_option( self::OPTION_KEY, $cleaned, false );

        $redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=general' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        wp_safe_redirect( add_query_arg( 'brz-msg', 'saved', $redirect ) );
        exit;
    }

    /**
     * AJAX save handler (no page refresh).
     */
    public static function handle_save_settings_ajax() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $input   = isset( $_POST[ self::OPTION_KEY ] ) ? (array) wp_unslash( $_POST[ self::OPTION_KEY ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $cleaned = self::sanitize_settings( $input );
        update_option( self::OPTION_KEY, $cleaned, false );

        wp_send_json_success( array( 'message' => 'تنظیمات ذخیره شد.' ) );
    }

    /**
     * Generate structured JSON of recently modified posts for copy-paste.
     */
    public static function ajax_generate() {
        check_ajax_referer( 'brz_smart_linker_generate' );

        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $posts = get_posts( array(
            'post_type'      => array( 'post', 'product', 'page' ),
            'post_status'    => 'publish',
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'posts_per_page' => 10,
        ) );

        $payload = array();
        foreach ( $posts as $p ) {
            $payload[] = array(
                'post_id'    => $p->ID,
                'post_title' => get_the_title( $p ),
                'post_url'   => get_permalink( $p ),
                'content'    => wp_strip_all_tags( $p->post_content ),
                'keyword'    => '',
                'target_url' => '',
                'related'    => array(),
            );
        }

        wp_send_json_success( $payload );
    }

    /**
     * Handle JSON pasted by user; store as pending and push to Sheet.
     */
    public static function handle_process_json() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permission denied', 'buyruz-settings' ) );
        }
        check_admin_referer( 'brz_smart_linker_process_json' );

        $raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $data = json_decode( $raw, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=maintenance&brz-msg=invalid-json' ) );
            exit;
        }

        $rows = isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : $data;

        $inserted_rows = array();

        foreach ( $rows as $row ) {
            $post_id    = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
            $keyword    = isset( $row['keyword'] ) ? sanitize_text_field( $row['keyword'] ) : '';
            $target_url = isset( $row['target_url'] ) ? esc_url_raw( $row['target_url'] ) : '';

            if ( $post_id < 1 || empty( $keyword ) || empty( $target_url ) ) {
                continue;
            }

            $fingerprint = self::fingerprint( $post_id, $keyword, $target_url );

            // Skip if user deleted this combo before.
            $user_deleted = self::is_user_deleted( $fingerprint );
            if ( $user_deleted ) {
                continue;
            }

            $id = BRZ_Smart_Linker_DB::upsert( array(
                'post_id'     => $post_id,
                'keyword'     => $keyword,
                'target_url'  => $target_url,
                'fingerprint' => $fingerprint,
                'status'      => self::STATUS_PENDING,
            ) );

            if ( $id ) {
                $inserted_rows[] = array(
                    'id'         => $id,
                    'post_id'    => $post_id,
                    'keyword'    => $keyword,
                    'target_url' => $target_url,
                    'status'     => 'Pending',
                    'date'       => current_time( 'mysql' ),
                );
            }
        }

        if ( ! empty( $inserted_rows ) ) {
            self::push_to_sheet( $inserted_rows );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=maintenance&brz-msg=processed' ) );
        exit;
    }

    /**
     * Sync remote cache via AJAX.
     */
    public static function ajax_sync_cache() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }
        $settings = self::get_settings();
        if ( empty( $settings['remote_endpoint'] ) || empty( $settings['remote_api_key'] ) ) {
            wp_send_json_error( array( 'message' => 'Remote endpoint/API key تنظیم نشده است.' ) );
        }

        $type_to_store = ( 'shop' === $settings['site_role'] ) ? 'post' : 'product';

        $response = wp_remote_get( add_query_arg( 'api_key', rawurlencode( $settings['remote_api_key'] ), $settings['remote_endpoint'] ), array( 'timeout' => 20 ) );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'خطا در درخواست: ' . $response->get_error_message() ) );
        }
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data ) ) {
            wp_send_json_error( array( 'message' => 'پاسخ نامعتبر از ریموت' ) );
        }

        $items = array();
        foreach ( $data as $row ) {
            $items[] = array(
                'remote_id'    => isset( $row['id'] ) ? (int) $row['id'] : 0,
                'title'        => isset( $row['title'] ) ? $row['title'] : '',
                'url'          => isset( $row['permalink'] ) ? $row['permalink'] : '',
                'categories'   => isset( $row['categories'] ) ? $row['categories'] : array(),
                'stock_status' => isset( $row['stock_status'] ) ? $row['stock_status'] : '',
            );
        }

        BRZ_Smart_Linker_DB::replace_cache( $type_to_store, $items );

        wp_send_json_success( array( 'message' => 'Sync انجام شد (' . count( $items ) . ' آیتم).' ) );
    }

    /**
     * Analyze selected post/product and build prompt.
     */
    public static function ajax_analyze() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'پست انتخاب نشده است.' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => 'پست یافت نشد.' ) );
        }

        $settings   = self::get_settings();
        $source_type = $post->post_type === 'product' ? 'product' : 'post';
        // Determine which cache type to pull based on site role
        $target_type = ( 'blog' === $settings['site_role'] ) ? 'product' : 'post';

        $title_kw = wp_trim_words( $post->post_title, 8, '' );
        $cache_rows = BRZ_Smart_Linker_DB::search_cache( $target_type, $title_kw, 20 );
        if ( empty( $cache_rows ) ) {
            $cache_rows = BRZ_Smart_Linker_DB::search_cache( $target_type, '', 20 );
        }

        $content = wp_strip_all_tags( $post->post_content );
        $links   = array();
        foreach ( $cache_rows as $row ) {
            $links[] = array(
                'title' => $row['title'],
                'url'   => $row['url'],
                'type'  => $row['type'],
            );
        }

        $prompt = "I have this content:\n" . mb_substr( $content, 0, 2000, 'UTF-8' ) . "\n\nLink these keywords to these URLs:\n";
        foreach ( $links as $l ) {
            $prompt .= "- " . $l['title'] . " => " . $l['url'] . "\n";
        }
        $prompt .= "\nReturn JSON array: [{\"post_id\": " . $post_id . ", \"keyword\": \"...\", \"target_url\": \"...\", \"target_type\": \"" . $target_type . "\"}]";

        wp_send_json_success( array(
            'prompt' => $prompt,
            'post'   => array( 'id' => $post_id, 'title' => $post->post_title, 'type' => $source_type ),
            'links'  => $links,
        ) );
    }

    /**
     * Apply pasted JSON directly to a post.
     */
    public static function ajax_apply() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $data = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            wp_send_json_error( array( 'message' => 'JSON نامعتبر است.' ) );
        }

        $by_post = array();
        foreach ( $data as $row ) {
            $pid = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
            if ( ! $pid ) { continue; }
            $by_post[ $pid ][] = $row;
        }

        $summary = array( 'products' => 0, 'posts' => 0 );

        $settings = self::get_settings();

        foreach ( $by_post as $post_id => $rows ) {
            $post = get_post( $post_id );
            if ( ! $post ) { continue; }
            $content = $post->post_content;
            $injector = new BRZ_Smart_Linker_Link_Injector( $post_id, $content, $post->post_type, $settings );
            $result   = $injector->inject( $rows );
            if ( $result['changed'] ) {
                wp_update_post( array(
                    'ID'           => $post_id,
                    'post_content' => $result['content'],
                ) );
                foreach ( $rows as $r ) {
                    if ( isset( $r['target_type'] ) && 'product' === $r['target_type'] ) {
                        $summary['products']++;
                    } else {
                        $summary['posts']++;
                    }
                }
            }
        }

        wp_send_json_success( array(
            'message' => sprintf( '%d Links applied (%d Products, %d Posts).', $summary['products'] + $summary['posts'], $summary['products'], $summary['posts'] ),
        ) );
    }

    /**
     * Test connectivity to Google Sheet Web App.
     */
    public static function ajax_test_gsheet() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }
        $settings = self::get_settings();
        if ( class_exists( 'BRZ_GSheet' ) ) {
            $resp = BRZ_GSheet::test_connection( $settings );
            if ( is_wp_error( $resp ) ) {
                wp_send_json_error( array( 'message' => $resp->get_error_message() ) );
            }
            wp_send_json_success( array( 'message' => 'ارتباط با Google Sheet برقرار است.' ) );
        }
        wp_send_json_error( array( 'message' => 'ماژول GSheet در دسترس نیست.' ) );
    }

    /**
     * Test remote peer connectivity.
     */
    public static function ajax_test_peer() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }
        $settings = self::get_settings();
        if ( empty( $settings['remote_endpoint'] ) ) {
            wp_send_json_error( array( 'message' => 'Remote endpoint تنظیم نشده است.' ) );
        }
        $response = wp_remote_get( add_query_arg( 'api_key', rawurlencode( $settings['remote_api_key'] ), $settings['remote_endpoint'] ), array( 'timeout' => 15 ) );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( array( 'message' => 'پاسخ نامعتبر از ریموت' ) );
        }
        wp_send_json_success( array( 'message' => 'ارتباط موفق. آیتم‌ها: ' . count( (array) $data ) ) );
    }

    /**
     * Compose unique hash.
     *
     * @param int    $post_id
     * @param string $keyword
     * @param string $target_url
     * @return string
     */
    public static function fingerprint( $post_id, $keyword, $target_url ) {
        return md5( implode( '|', array( (int) $post_id, strtolower( $keyword ), trim( $target_url ) ) ) );
    }

    /**
     * Ensure cron events exist.
     */
    public static function ensure_cron_events() {
        if ( ! wp_next_scheduled( self::CRON_PROCESS_HOOK ) ) {
            wp_schedule_event( time() + 120, 'hourly', self::CRON_PROCESS_HOOK );
        }
        if ( ! wp_next_scheduled( self::CRON_APPROVAL_HOOK ) ) {
            wp_schedule_event( time() + 180, 'hourly', self::CRON_APPROVAL_HOOK );
        }
    }

    /**
     * Pull approved rows from Sheet (if API mode enabled).
     */
    public static function poll_approvals() {
        $settings = self::get_settings();
        if ( 'api' !== $settings['mode'] || empty( $settings['sheet_web_app'] ) ) {
            return;
        }

        $response = self::remote_post( $settings['sheet_web_app'], array(
            'action'  => 'get_approvals',
            'api_key' => $settings['api_key'],
        ) );

        if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
            return;
        }

        $approved_ids = array();
        $manual_ids   = array();

        foreach ( $response['data'] as $row ) {
            if ( empty( $row['id'] ) ) {
                continue;
            }
            $status = isset( $row['status'] ) ? strtolower( $row['status'] ) : '';
            if ( 'approved' === $status ) {
                $approved_ids[] = (int) $row['id'];
            } elseif ( 'rejected' === $status ) {
                $manual_ids[] = (int) $row['id'];
            }
        }

        BRZ_Smart_Linker_DB::set_status( $approved_ids, self::STATUS_APPROVED );
        BRZ_Smart_Linker_DB::set_status( $manual_ids, self::STATUS_MANUAL );
    }

    /**
     * Process queue: inject approved links, mark deletions.
     */
    public static function process_queue() {
        $approved = BRZ_Smart_Linker_DB::get_by_status( array( self::STATUS_APPROVED, self::STATUS_ACTIVE ) );
        if ( empty( $approved ) ) {
            return;
        }

        // Group by post to reduce DOM parsing overhead.
        $by_post = array();
        foreach ( $approved as $row ) {
            $by_post[ $row['post_id'] ][] = $row;
        }

        foreach ( $by_post as $post_id => $rows ) {
            $post = get_post( $post_id );
            if ( ! $post || 'trash' === $post->post_status ) {
                continue;
            }

            $content = $post->post_content;

            // Detect deletions on active links.
            $active_rows = array_filter( $rows, function( $r ) {
                return self::STATUS_ACTIVE === $r['status'];
            } );
            self::detect_user_deletions( $post_id, $content, $active_rows );

            // Inject only rows that are approved and not active yet.
            $to_inject = array_filter( $rows, function( $r ) {
                return self::STATUS_APPROVED === $r['status'];
            } );

            if ( empty( $to_inject ) ) {
                continue;
            }

            $settings = self::get_settings();
            $injector = new BRZ_Smart_Linker_Link_Injector( $post_id, $content, $post->post_type, $settings );
            $result   = $injector->inject( $to_inject );

            if ( $result['changed'] ) {
                // Persist content
                wp_update_post( array(
                    'ID'           => $post_id,
                    'post_content' => $result['content'],
                ) );

                BRZ_Smart_Linker_DB::set_status( wp_list_pluck( $to_inject, 'id' ), self::STATUS_ACTIVE );
            }
        }
    }

    /**
     * Mark user_deleted when previously active links are gone.
     *
     * @param int    $post_id
     * @param string $content
     * @param array  $active_rows
     */
    private static function detect_user_deletions( $post_id, $content, array $active_rows ) {
        if ( empty( $active_rows ) ) {
            return;
        }

        $body = wp_strip_all_tags( $content );
        $missing_fps = array();

        foreach ( $active_rows as $row ) {
            $keyword    = isset( $row['keyword'] ) ? $row['keyword'] : '';
            $target_url = isset( $row['target_url'] ) ? $row['target_url'] : '';

            $has_keyword = ( false !== stripos( $body, $keyword ) );
            $has_anchor  = ( false !== stripos( $content, $target_url ) );

            if ( ! $has_keyword || ! $has_anchor ) {
                $missing_fps[] = $row['fingerprint'];
            }
        }

        if ( ! empty( $missing_fps ) ) {
            BRZ_Smart_Linker_DB::set_status_by_fingerprint( $missing_fps, self::STATUS_USER_DELETED );
        }
    }

    /**
     * Push pending rows to Google Sheet via Web App.
     *
     * @param array $rows
     */
    private static function push_to_sheet( array $rows ) {
        $settings = self::get_settings();
        if ( empty( $settings['sheet_web_app'] ) ) {
            return;
        }

        $payload = array(
            'action' => 'add_suggestions',
            'api_key' => $settings['api_key'],
            'rows'   => $rows,
        );

        if ( class_exists( 'BRZ_GSheet' ) ) {
            BRZ_GSheet::send_route( 'add_suggestions', $payload, $settings );
        }
    }

    /**
     * Safe remote POST helper.
     *
     * @param string $url
     * @param array  $body
     * @return array
     */
    private static function remote_post( $url, array $body ) {
        $response = wp_remote_post( $url, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $data = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $data, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Check if combo was user-deleted.
     *
     * @param string $fingerprint
     * @return bool
     */
    private static function is_user_deleted( $fingerprint ) {
        global $wpdb;
        $table = BRZ_Smart_Linker_DB::table();
        $status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$table} WHERE fingerprint = %s LIMIT 1",
                $fingerprint
            )
        );
        return ( self::STATUS_USER_DELETED === $status );
    }

    /**
     * Sanitize incoming settings.
     *
     * @param array $input
     * @return array
     */
    private static function sanitize_settings( array $input ) {
        $cleaned = array();
        $cleaned['mode']          = ( isset( $input['mode'] ) && 'api' === $input['mode'] ) ? 'api' : 'manual';
        $cleaned['api_key']       = sanitize_text_field( isset( $input['api_key'] ) ? $input['api_key'] : '' );
        $cleaned['sheet_id']      = sanitize_text_field( isset( $input['sheet_id'] ) ? $input['sheet_id'] : '' );
        $cleaned['sheet_web_app'] = esc_url_raw( isset( $input['sheet_web_app'] ) ? $input['sheet_web_app'] : '' );
        $cleaned['google_client_id']     = sanitize_text_field( isset( $input['google_client_id'] ) ? $input['google_client_id'] : '' );
        $cleaned['google_client_secret'] = sanitize_text_field( isset( $input['google_client_secret'] ) ? $input['google_client_secret'] : '' );
        $cleaned['site_role']     = ( isset( $input['site_role'] ) && 'blog' === $input['site_role'] ) ? 'blog' : 'shop';
        $cleaned['remote_endpoint']= esc_url_raw( isset( $input['remote_endpoint'] ) ? $input['remote_endpoint'] : '' );
        $cleaned['remote_api_key'] = sanitize_text_field( isset( $input['remote_api_key'] ) ? $input['remote_api_key'] : '' );
        $cleaned['link_density']  = max( 0, min( 15, (int) ( isset( $input['link_density'] ) ? $input['link_density'] : self::DEFAULT_DENSITY ) ) );
        $cleaned['open_new_tab']  = empty( $input['open_new_tab'] ) ? 0 : 1;
        $cleaned['nofollow']      = empty( $input['nofollow'] ) ? 0 : 1;
        $cleaned['prevent_self']  = empty( $input['prevent_self'] ) ? 0 : 1;

        $allowed_pt = array( 'post', 'product', 'page' );
        $selected   = isset( $input['exclude_post_types'] ) ? (array) $input['exclude_post_types'] : array();
        $cleaned['exclude_post_types'] = array_values( array_intersect( $allowed_pt, $selected ) );

        $cleaned['exclude_categories'] = sanitize_text_field( isset( $input['exclude_categories'] ) ? $input['exclude_categories'] : '' );
        $cleaned['exclude_html_tags']  = sanitize_text_field( isset( $input['exclude_html_tags'] ) ? $input['exclude_html_tags'] : '' );

        return $cleaned;
    }

    /**
     * Handle "Clear Logs" button.
     */
    public static function handle_clear_logs() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permission denied', 'buyruz-settings' ) );
        }
        check_admin_referer( 'brz_smart_linker_clear_logs' );

        global $wpdb;
        $table = BRZ_Smart_Linker_DB::table();
        $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=maintenance' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        wp_safe_redirect( add_query_arg( 'brz-msg', 'logs-cleared', $redirect ) );
        exit;
    }

    /**
     * Handle "Purge Pending Links" button.
     */
    public static function handle_purge_pending() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permission denied', 'buyruz-settings' ) );
        }
        check_admin_referer( 'brz_smart_linker_purge_pending' );

        global $wpdb;
        $table = BRZ_Smart_Linker_DB::table();
        $wpdb->delete( $table, array( 'status' => self::STATUS_PENDING ), array( '%s' ) );

        $redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=maintenance' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        wp_safe_redirect( add_query_arg( 'brz-msg', 'pending-purged', $redirect ) );
        exit;
    }

    /**
     * Notices for the module page.
     */
    private static function render_notices() {
        if ( empty( $_GET['brz-msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $msg = sanitize_key( wp_unslash( $_GET['brz-msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $class = 'notice-info';
        $text  = '';
        if ( 'saved' === $msg ) {
            $class = 'notice-success';
            $text  = 'تنظیمات ذخیره شد.';
        } elseif ( 'logs-cleared' === $msg ) {
            $class = 'notice-success';
            $text  = 'جدول لاگ خالی شد.';
        } elseif ( 'pending-purged' === $msg ) {
            $class = 'notice-warning';
            $text  = 'رکوردهای pending حذف شدند.';
        } elseif ( 'invalid-json' === $msg ) {
            $class = 'notice-error';
            $text  = 'JSON نامعتبر بود.';
        } elseif ( 'processed' === $msg ) {
            $class = 'notice-success';
            $text  = 'داده‌ها پردازش و ارسال شدند.';
        }

        if ( $text ) {
            echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $text ) . '</p></div>';
        }
    }

    /**
     * Inline JS for AJAX saves and slider binding.
     */
    private static function render_inline_js() {
        $nonce = wp_create_nonce( 'brz_smart_linker_save' );
        ?>
        <script>
        (function(){
            var forms = document.querySelectorAll('form[data-ajax="1"]');
            forms.forEach(function(form){
                form.addEventListener('submit', function(e){
                    if (!window.ajaxurl) { return; }
                    e.preventDefault();
                    var btn = form.querySelector('button[type="submit"], input[type="submit"]');
                    if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
                    var data = new FormData(form);
                    data.append('action','brz_smart_linker_save');
                    data.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');

                    fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
                        .then(function(res){ if(!res.ok){throw new Error('bad');} return res.json(); })
                        .then(function(json){
                            var toast = document.getElementById('brz-snackbar');
                            if (toast) {
                                toast.textContent = (json && json.data && json.data.message) ? json.data.message : 'تنظیمات ذخیره شد.';
                                toast.classList.add('is-visible');
                                setTimeout(function(){ toast.classList.remove('is-visible'); }, 2400);
                            }
                        })
                        .catch(function(){
                            alert('ذخیره انجام نشد. دوباره تلاش کنید.');
                        })
                        .finally(function(){
                            if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
                        });
                });
            });

            var density = document.getElementById('brz-sl-density');
            var hidden  = document.getElementById('brz-sl-density-hidden');
            if (density && hidden) {
                density.addEventListener('input', function(){
                    hidden.value = density.value;
                });
            }

            // Sync button
            var syncBtn = document.getElementById('brz-sl-sync-btn');
            if (syncBtn) {
                var syncStatus = document.getElementById('brz-sl-sync-status');
                syncBtn.addEventListener('click', function(){
                    if (!window.ajaxurl) { return; }
                    syncBtn.disabled = true;
                    if (syncStatus) { syncStatus.textContent = 'Sync in progress...'; }
                    var data = new FormData();
                    data.append('action','brz_smart_linker_sync_cache');
                    data.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data})
                        .then(function(res){ if(!res.ok){throw new Error('bad');} return res.json(); })
                        .then(function(json){
                            var msg = (json && json.data && json.data.message) ? json.data.message : 'Sync completed.';
                            if (syncStatus) { syncStatus.textContent = msg; }
                        })
                        .catch(function(){ if (syncStatus) { syncStatus.textContent = 'Sync failed.'; } })
                        .finally(function(){ syncBtn.disabled = false; });
                });
            }

            // Test GSheet
            var testG = document.getElementById('brz-sl-test-gsheet');
            if (testG) {
                var statusG = document.getElementById('brz-sl-gsheet-status');
                testG.addEventListener('click', function(){
                    if (statusG) statusG.textContent = 'Testing...';
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:(function(){var d=new FormData();d.append('action','brz_smart_linker_test_gsheet');d.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');return d;})()})
                        .then(res=>{if(!res.ok)throw new Error('bad');return res.json();})
                        .then(json=>{ if(statusG) statusG.textContent = (json && json.data && json.data.message) ? json.data.message : 'OK'; })
                        .catch(()=>{ if(statusG) statusG.textContent = 'خطا در تست'; });
                });
            }

            // Test Peer
            var testP = document.getElementById('brz-sl-test-peer');
            if (testP) {
                var statusP = document.getElementById('brz-sl-peer-status');
                testP.addEventListener('click', function(){
                    if (statusP) statusP.textContent = 'Testing...';
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:(function(){var d=new FormData();d.append('action','brz_smart_linker_test_peer');d.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');return d;})()})
                        .then(res=>{if(!res.ok)throw new Error('bad');return res.json();})
                        .then(json=>{ if(statusP) statusP.textContent = (json && json.data && json.data.message) ? json.data.message : 'OK'; })
                        .catch(()=>{ if(statusP) statusP.textContent = 'خطا در تست'; });
                });
            }

            // Workbench select with Select2 if available
            var wbSelect = jQuery && jQuery('#brz-sl-workbench-post');
            if (wbSelect && wbSelect.select2) {
                wbSelect.select2({
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'brz_smart_linker_generate',
                                _wpnonce: '<?php echo wp_create_nonce( 'brz_smart_linker_generate' ); ?>',
                                s: params.term || ''
                            };
                        },
                        processResults: function (data) {
                            var items = (data && data.data) ? data.data : [];
                            return { results: items.map(function(item){ return {id:item.post_id, text:item.post_title}; }) };
                        }
                    },
                    minimumInputLength: 2,
                    placeholder: 'جستجوی پست یا محصول'
                });
            }

            // Analyze button
            var analyzeBtn = document.getElementById('brz-sl-analyze-btn');
            if (analyzeBtn) {
                analyzeBtn.addEventListener('click', function(){
                    var select = document.getElementById('brz-sl-workbench-post');
                    if (!select || !select.value) { alert('یک پست/محصول انتخاب کنید'); return; }
                    var statusEl = document.getElementById('brz-sl-analyze-status');
                    if (statusEl) statusEl.textContent = 'در حال تحلیل...';
                    var data = new FormData();
                    data.append('action','brz_smart_linker_analyze');
                    data.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    data.append('post_id', select.value);
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data})
                        .then(res=>{ if(!res.ok) throw new Error('bad'); return res.json(); })
                        .then(json=>{
                            if (!json || !json.success) { throw new Error('bad'); }
                            document.getElementById('brz-sl-prompt').value = json.data.prompt || '';
                            if (statusEl) statusEl.textContent = 'پرامپت آماده شد.';
                        })
                        .catch(()=>{ if (statusEl) statusEl.textContent = 'خطا در تحلیل.'; });
                });
            }

            // Copy prompt
            var copyBtn = document.getElementById('brz-sl-copy-prompt');
            if (copyBtn) {
                copyBtn.addEventListener('click', function(){
                    var ta = document.getElementById('brz-sl-prompt');
                    if (!ta) return;
                    ta.select();
                    document.execCommand('copy');
                });
            }

            // Apply response
            var applyBtn = document.getElementById('brz-sl-apply-btn');
            if (applyBtn) {
                applyBtn.addEventListener('click', function(){
                    var ta = document.getElementById('brz-sl-response');
                    var statusEl = document.getElementById('brz-sl-apply-status');
                    if (!ta || !ta.value) { alert('ابتدا JSON را وارد کنید'); return; }
                    if (statusEl) statusEl.textContent = 'در حال اعمال...';
                    var data = new FormData();
                    data.append('action','brz_smart_linker_apply');
                    data.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    data.append('payload', ta.value);
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data})
                        .then(res=>{ if(!res.ok) throw new Error('bad'); return res.json(); })
                        .then(json=>{
                            if (!json || !json.success) { throw new Error('bad'); }
                            if (statusEl) statusEl.textContent = json.data.message || 'انجام شد';
                        })
                        .catch(()=>{ if (statusEl) statusEl.textContent = 'خطا در اعمال لینک‌ها'; });
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Register REST endpoints for inventory provider.
     */
    public static function register_rest_routes() {
        register_rest_route( 'brz/v1', '/inventory', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'rest_inventory' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * REST callback to expose inventory/posts based on site_role.
     */
    public static function rest_inventory( WP_REST_Request $request ) {
        $settings = self::get_settings();
        $local_api_key = isset( $settings['local_api_key'] ) ? $settings['local_api_key'] : '';
        $incoming = $request->get_param( 'api_key' );
        if ( empty( $local_api_key ) || $incoming !== $local_api_key ) {
            return new WP_REST_Response( array( 'message' => 'Forbidden' ), 403 );
        }

        $is_shop = ( 'shop' === $settings['site_role'] );
        if ( $is_shop ) {
            $posts = get_posts( array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 200,
            ) );
        } else {
            $posts = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 200,
            ) );
        }

        $payload = array();
        foreach ( $posts as $p ) {
            $cats = wp_get_post_terms( $p->ID, $is_shop ? 'product_cat' : 'category', array( 'fields' => 'names' ) );
            $payload[] = array(
                'id'          => $p->ID,
                'title'       => get_the_title( $p ),
                'permalink'   => get_permalink( $p ),
                'categories'  => $cats,
                'stock_status'=> $is_shop ? get_post_meta( $p->ID, '_stock_status', true ) : '',
            );
        }

        return rest_ensure_response( $payload );
    }
}
