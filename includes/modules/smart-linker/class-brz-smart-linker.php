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
        $table = BRZ_Smart_Linker_DB::table();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
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
            'sheet_id'       => '',
            'sheet_web_app'  => '',
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
        );

        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Render admin UI (called from BRZ_Settings).
     */
    public static function render_module_content() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            return;
        }

        $settings = self::get_settings();
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $active_tab, array( 'general', 'strategy', 'exclusions', 'connections', 'workbench', 'maintenance' ), true ) ) {
            $active_tab = 'general';
        }

        self::render_notices();
        ?>
        <style>
        /* جلوگیری از تکرار هدر و مسیرها در صفحه لینک‌ساز هوشمند */
        .brz-admin-wrap .brz-hero:not(:first-of-type) { display: none; }
        .brz-admin-wrap .brz-side-nav:not(:first-of-type) { display: none; }
        </style>
        <div class="brz-section-header">
            <div>
                <h2>لینک‌ساز هوشمند</h2>
                <p>لینک‌سازی داخلی با سینک دوطرفه بین سایت‌ها و Google Sheet.</p>
            </div>
        </div>

        <div class="brz-card">
            <h2 class="nav-tab-wrapper">
                <a class="nav-tab <?php echo ( 'general' === $active_tab ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=general' ) ); ?>">عمومی</a>
                <a class="nav-tab <?php echo ( 'strategy' === $active_tab ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=strategy' ) ); ?>">استراتژی</a>
                <a class="nav-tab <?php echo ( 'connections' === $active_tab ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=connections' ) ); ?>">اتصالات</a>
                <a class="nav-tab <?php echo ( 'exclusions' === $active_tab ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=exclusions' ) ); ?>">مستثنیات</a>
                <a class="nav-tab <?php echo ( 'workbench' === $active_tab ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=workbench' ) ); ?>">میز کار</a>
                <a class="nav-tab <?php echo ( 'maintenance' === $active_tab ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=maintenance' ) ); ?>">نگهداری</a>
            </h2>

            <div class="brz-card__body">
                <?php
                if ( 'general' === $active_tab ) {
                    self::render_general_tab( $settings );
                } elseif ( 'strategy' === $active_tab ) {
                    self::render_strategy_tab( $settings );
                } elseif ( 'connections' === $active_tab ) {
                    self::render_connections_tab( $settings );
                } elseif ( 'exclusions' === $active_tab ) {
                    self::render_exclusions_tab( $settings );
                } elseif ( 'workbench' === $active_tab ) {
                    self::render_workbench_tab( $settings );
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
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="brz-sl-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=general' ) ); ?>" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="brz-sl-role">نقش سایت</label></th>
                        <td>
                            <select id="brz-sl-role" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[site_role]">
                                <option value="shop" <?php selected( $settings['site_role'], 'shop' ); ?>>Shop (WooCommerce)</option>
                                <option value="blog" <?php selected( $settings['site_role'], 'blog' ); ?>>Blog (WordPress)</option>
                            </select>
                            <p class="description">بر اساس نقش، endpoint دادهٔ متناسب برمی‌گرداند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-mode">حالت کار</label></th>
                        <td>
                            <label class="brz-toggle-wrap">
                                <input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mode]" value="manual" />
                                <input type="checkbox" id="brz-sl-mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mode]" value="api" <?php checked( $settings['mode'], 'api' ); ?> />
                                <span class="brz-toggle-switch"></span>
                                <span class="brz-toggle-label">API Mode</span>
                            </label>
                            <p class="description">در حالت API، ارسال/دریافت خودکار با Google Sheet انجام می‌شود؛ در حالت Manual فقط داده‌ها ثبت می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-api-key">API Key</label></th>
                        <td>
                            <input type="text" id="brz-sl-api-key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" class="regular-text" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off" />
                            <p class="description">برای آینده؛ در حال حاضر اختیاری است.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="brz-save-bar" style="display:flex;gap:8px;align-items:center;">
                <?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
            </div>
        </form>
        <?php
    }

    private static function render_strategy_tab( $settings ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" id="brz-sl-strategy-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=strategy' ) ); ?>" />
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

    private static function render_connections_tab( $settings ) {
        $partial = BRZ_PATH . 'includes/modules/smart-linker/partials-connections.php';
        if ( file_exists( $partial ) ) {
            include $partial;
        }
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

        foreach ( $by_post as $post_id => $rows ) {
            $post = get_post( $post_id );
            if ( ! $post ) { continue; }
            $content = $post->post_content;
            $injector = new BRZ_Smart_Linker_Link_Injector( $post_id, $content, $post->post_type );
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
            $resp = BRZ_GSheet::send_route( 'ping', array( 'ping' => 'pong' ), $settings );
            if ( is_wp_error( $resp ) ) {
                wp_send_json_error( array( 'message' => $resp->get_error_message() ) );
            }
            wp_send_json_success( array( 'message' => 'ارتباط با وب‌اپ برقرار است.' ) );
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

            $injector = new BRZ_Smart_Linker_Link_Injector( $post_id, $content, $post->post_type );
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
        register_rest_route( 'buyruz/v1', '/inventory', array(
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
        $api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        $incoming = $request->get_param( 'api_key' );
        if ( empty( $api_key ) || $incoming !== $api_key ) {
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
