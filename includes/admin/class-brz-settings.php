<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Settings {
    const CAPABILITY  = 'manage_options';
    const PARENT_SLUG = 'buyruz-dashboard';
    const MENU_POSITION = 2;
    private static $options_cache = null;

    private static function sections_meta() {
        $sections = array(
            'brz_main'    => array(
                'title'       => 'نمایش و تجربه کاربری',
                'description' => 'کنترل رنگ برند، انیمیشن و رفتار آکاردئون FAQ برای همهٔ سایت.',
                'footer'      => 'تغییرات این بخش فوراً روی صفحات دارای Rank Math FAQ اعمال می‌شود.',
            ),
            'brz_load'    => array(
                'title'       => 'بارگذاری و بهینه‌سازی',
                'description' => 'استراتژی بارگذاری منابع را برای اولویت Performance مشخص کنید.',
                'footer'      => 'حالت خودکار توصیه می‌شود مگر در شرایط خاص صفحه‌سازها که نیاز به سلکتور سفارشی دارند.',
            ),
            'brz_debug'   => array(
                'title'       => 'دیباگ و لاگ‌ها',
                'description' => 'ثبت رخدادها برای عیب‌یابی بدون قربانی کردن سرعت.',
                'footer'      => 'پوشهٔ لاگ در مسیر افزونه نگه‌داری می‌شود و پاکسازی خودکار بر اساس تعداد روز تنظیم‌شده انجام می‌شود.',
            ),
            'brz_guidelines' => array(
                'title'       => 'راهنمای توسعه و پاکسازی',
                'description' => 'توصیه‌های کلیدی برای افزودن ماژول‌های آینده بدون قربانی کردن سرعت و سلامت دیتابیس.',
                'callback'    => array( __CLASS__, 'render_guidelines_card' ),
            ),
        );

        return apply_filters( 'brz/settings/sections_meta', $sections );
    }

    private static function nav_items() {
        $items = array(
            array(
                'slug'  => self::PARENT_SLUG,
                'label' => 'پیشخوان',
            ),
            array(
                'slug'  => 'buyruz-general',
                'label' => 'تنظیمات عمومی',
            ),
        );

        foreach ( self::module_nav_items() as $slug => $meta ) {
            $items[] = array(
                'slug'   => 'buyruz-module-' . $slug,
                'label'  => isset( $meta['label'] ) ? $meta['label'] : $slug,
                'module' => $slug,
            );
        }

        return $items;
    }

    private static function module_nav_items() {
        $modules = BRZ_Modules::registry();
        unset( $modules['frontend'] ); // تنظیمات هسته در بخش عمومی مدیریت می‌شود.
        return $modules;
    }

    public static function get( $key = null, $default = null ) {
        if ( null === self::$options_cache ) {
            self::$options_cache = get_option( BRZ_OPTION, array() );
        }
        $opts = self::$options_cache;
        if ( $key === null ) { return $opts; }
        return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
    }

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_brz_toggle_module', array( __CLASS__, 'handle_toggle_module' ) );
        add_action( 'wp_ajax_brz_toggle_module', array( __CLASS__, 'handle_toggle_module_ajax' ) );
        add_action( 'wp_ajax_brz_save_settings', array( __CLASS__, 'handle_save_settings_ajax' ) );
    }

    public static function register() {
        register_setting( 'brz_group', BRZ_OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );

        add_settings_section( 'brz_main', 'نمایش و تجربه کاربری', '__return_false', 'brz-settings' );

        add_settings_field( 'enable_css', 'فعال‌سازی CSS', function(){
            $v = self::get( 'enable_css', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[enable_css]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[enable_css]" value="1" '.checked( 1, $v, false ).'> بارگذاری استایل</label>';
            echo '<p class="description">با غیرفعال‌شدن، هیچ استایلی تزریق نمی‌شود.</p>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'inline_css', 'درون‌خطی کردن CSS', function(){
            $v = self::get( 'inline_css', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[inline_css]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[inline_css]" value="1" '.checked( 1, $v, false ).'> افزودن CSS به صورت inline</label>';
            echo '<p class="description">درون‌خطی باعث حذف درخواست فایل جداگانه می‌شود و برای سرعت بهتر است.</p>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'brand_color', 'رنگ برند', function(){
            $v = esc_attr( self::get( 'brand_color', '#ff5668' ) );
            echo '<input type="text" class="regular-text" name="'.BRZ_OPTION.'[brand_color]" value="'.$v.'" />';
            echo '<p class="description">مثال: #ff5668</p>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'enable_js', 'فعال‌سازی JS', function(){
            $v = self::get( 'enable_js', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[enable_js]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[enable_js]" value="1" '.checked( 1, $v, false ).'> بارگذاری اسکریپت آکاردئون</label>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'single_open', 'فقط یک مورد باز', function(){
            $v = self::get( 'single_open', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[single_open]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[single_open]" value="1" '.checked( 1, $v, false ).'> همواره فقط یک سؤال باز باشد</label>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'animate', 'انیمیشن نرم', function(){
            $v = self::get( 'animate', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[animate]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[animate]" value="1" '.checked( 1, $v, false ).'> فعال</label>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'compact_mobile', 'نسخه موبایل فشرده', function(){
            $v = self::get( 'compact_mobile', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[compact_mobile]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[compact_mobile]" value="1" '.checked( 1, $v, false ).'> فعال</label>';
        }, 'brz-settings', 'brz_main' );

        add_settings_section( 'brz_load', 'بارگذاری هوشمند', '__return_false', 'brz-settings' );

        add_settings_field( 'load_strategy', 'حالت بارگذاری', function(){
            $v = self::get( 'load_strategy', 'auto' );
            ?>
            <label><input type="radio" name="<?php echo BRZ_OPTION; ?>[load_strategy]" value="auto" <?php checked( 'auto', $v ); ?>> خودکار (وقتی FAQ موجود باشد)</label><br/>
            <label><input type="radio" name="<?php echo BRZ_OPTION; ?>[load_strategy]" value="all" <?php checked( 'all', $v ); ?>> همه صفحات</label><br/>
            <label><input type="radio" name="<?php echo BRZ_OPTION; ?>[load_strategy]" value="selector" <?php checked( 'selector', $v ); ?>> فقط صفحات دارای سلکتور سفارشی</label>
            <?php
        }, 'brz-settings', 'brz_load' );

        add_settings_field( 'custom_selector', 'سلکتور سفارشی', function(){
            $v = esc_attr( self::get( 'custom_selector', '.rank-math-faq' ) );
            echo '<input type="text" class="regular-text" name="'.BRZ_OPTION.'[custom_selector]" value="'.$v.'" />';
            echo '<p class="description">وقتی حالت "selector" فعال است، اگر این سلکتور در HTML صفحه وجود داشته باشد، افزونه فعال می‌شود.</p>';
        }, 'brz-settings', 'brz_load' );

        add_settings_section( 'brz_debug', 'دیباگ و لاگ‌ها', '__return_false', 'brz-settings' );

        add_settings_field( 'debug_enabled', 'فعال‌سازی دیباگ', function(){
            $enabled = (bool) self::get( 'debug_enabled', 0 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[debug_enabled]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[debug_enabled]" value="1" '.checked( true, $enabled, false ).'> هنگام فعال بودن، لاگ‌گیری بر اساس انتخاب‌های زیر انجام می‌شود.</label>';
        }, 'brz-settings', 'brz_debug' );

        add_settings_field( 'debug_components', 'بخش‌های قابل لاگ', array( __CLASS__, 'render_debug_components_field' ), 'brz-settings', 'brz_debug' );

        add_settings_field( 'debug_mask_sensitive', 'ماسک داده‌های حساس', function(){
            $mask = (bool) self::get( 'debug_mask_sensitive', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[debug_mask_sensitive]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[debug_mask_sensitive]" value="1" '.checked( true, $mask, false ).'> مقادیر شامل توکن، Authorization و رشته‌های محرمانه در لاگ‌ها با [masked] جایگزین شوند.</label>';
            echo '<p class="description">در صورت نیاز به لاگ کامل برای محیط‌های آزمایشی می‌توانید این گزینه را غیرفعال کنید، اما نسبت به امنیت فایل دقت کنید.</p>';
        }, 'brz-settings', 'brz_debug' );

        add_settings_field( 'debug_retention_days', 'مدت نگهداری لاگ', function(){
            $days = (int) self::get( 'debug_retention_days', 7 );
            if ( $days < 1 ) { $days = 1; }
            echo '<input type="number" class="small-text" name="'.BRZ_OPTION.'[debug_retention_days]" value="'.esc_attr( $days ).'" min="1" max="30" />';
            echo '<p class="description">به‌صورت پیش‌فرض لاگ‌ها هر ۷ روز پاکسازی می‌شوند. می‌توانید بین ۱ تا ۳۰ روز تنظیم کنید.</p>';
        }, 'brz-settings', 'brz_debug' );

        add_settings_field( 'debug_log_path', 'محل ذخیره لاگ', array( __CLASS__, 'render_debug_log_path_field' ), 'brz-settings', 'brz_debug' );
    }

    public static function page() {
        $capability = self::CAPABILITY;

        add_menu_page(
            'تنظیمات بایروز',
            'تنظیمات بایروز',
            $capability,
            self::PARENT_SLUG,
            array( __CLASS__, 'render_page' ),
            'dashicons-admin-generic',
            self::MENU_POSITION
        );

        add_submenu_page(
            self::PARENT_SLUG,
            'تنظیمات عمومی',
            'تنظیمات عمومی',
            $capability,
            'buyruz-general',
            array( __CLASS__, 'render_page' )
        );

        foreach ( self::module_nav_items() as $slug => $meta ) {
            add_submenu_page(
                self::PARENT_SLUG,
                isset( $meta['label'] ) ? $meta['label'] : $slug,
                isset( $meta['label'] ) ? $meta['label'] : $slug,
                $capability,
                'buyruz-module-' . $slug,
                array( __CLASS__, 'render_page' )
            );
        }

        global $submenu;
        if ( isset( $submenu[ self::PARENT_SLUG ][0][0] ) ) {
            $submenu[ self::PARENT_SLUG ][0][0] = 'پیشخوان';
        }
    }

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::PARENT_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'buyruz-general' === $page ) {
            self::render_general_settings();
            return;
        }

        if ( strpos( $page, 'buyruz-module-' ) === 0 ) {
            $slug = substr( $page, strlen( 'buyruz-module-' ) );
            self::render_module_settings( $slug );
            return;
        }

        self::render_dashboard();
    }

    private static function render_shell( $active_slug, callable $content_cb ) {
        $brand = esc_attr( self::get( 'brand_color', '#ff5668' ) );
        ?>
        <div class="brz-admin-wrap" dir="rtl" style="--brz-brand: <?php echo $brand; ?>;">
            <div id="brz-snackbar" class="brz-snackbar" aria-live="polite"></div>
            <?php self::render_hero( $active_slug ); ?>
            <?php self::render_top_nav( $active_slug ); ?>

            <div class="brz-content">
                <?php call_user_func( $content_cb ); ?>
            </div>
        </div>
        <?php
    }

    private static function render_hero( $active_slug ) {
        $stats = self::hero_stats();
        $cta_href  = admin_url( 'admin.php?page=buyruz-general' );
        $cta_label = 'ویرایش تنظیمات عمومی';
        if ( 'buyruz-general' === $active_slug ) {
            $cta_href  = admin_url( 'admin.php?page=' . self::PARENT_SLUG );
            $cta_label = 'دیدن پیشخوان ماژول‌ها';
        }
        ?>
        <div class="brz-hero">
            <div class="brz-hero__content">
                <div class="brz-hero__eyebrow">تنظیمات بایروز</div>
                <div class="brz-hero__title-row">
                    <h1>پنل ماژول‌ها و تنظیمات</h1>
                    <span class="brz-hero__version">نسخه <?php echo esc_html( BRZ_VERSION ); ?></span>
                </div>
                <p class="brz-hero__desc">چیدمان الهام‌گرفته از Rank Math با تمرکز بر سرعت، نمایش ماژول‌ها و ذخیره‌سازی بدون رفرش.</p>
                <div class="brz-hero__meta">
                    <?php foreach ( $stats as $stat ) : ?>
                        <div class="brz-pill">
                            <div class="brz-pill__label"><?php echo esc_html( $stat['label'] ); ?></div>
                            <div class="brz-pill__value"><?php echo esc_html( $stat['value'] ); ?></div>
                            <?php if ( ! empty( $stat['hint'] ) ) : ?>
                                <div class="brz-pill__hint"><?php echo esc_html( $stat['hint'] ); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="brz-hero__aside">
                <div class="brz-hero__badge">بدون رفرش ذخیره می‌شود</div>
                <p>تغییرات فرم‌ها و وضعیت ماژول‌ها به‌صورت آنی اعمال می‌شوند و صفحه روی همین نما باقی می‌ماند.</p>
                <a class="brz-hero__cta" href="<?php echo esc_url( $cta_href ); ?>"><?php echo esc_html( $cta_label ); ?></a>
            </div>
        </div>
        <?php
    }

    private static function hero_stats() {
        $registry = BRZ_Modules::registry();
        $states   = BRZ_Modules::get_states();
        $total    = count( $registry );
        $active   = 0;
        foreach ( $states as $state ) {
            if ( ! empty( $state ) ) {
                $active++;
            }
        }

        $strategy       = self::get( 'load_strategy', 'auto' );
        $selector       = self::get( 'custom_selector', '.rank-math-faq' );
        $strategy_label = 'خودکار';
        $strategy_hint  = 'تشخیص خودکار FAQ در محتوا';
        if ( 'all' === $strategy ) {
            $strategy_label = 'همهٔ صفحات';
            $strategy_hint  = 'منابع همیشه آماده هستند';
        } elseif ( 'selector' === $strategy ) {
            $strategy_label = 'سلکتور سفارشی';
            $strategy_hint  = 'فعال زمانی که ' . $selector . ' وجود داشته باشد';
        }

        $css_on   = self::get( 'enable_css', 1 );
        $js_on    = self::get( 'enable_js', 1 );
        $inline   = self::get( 'inline_css', 1 );
        $debug_on = self::get( 'debug_enabled', 0 );

        return array(
            array(
                'label' => 'وضعیت ماژول‌ها',
                'value' => $active . ' / ' . $total,
                'hint'  => 'سوئیچ‌ها فوری و بدون رفرش عمل می‌کنند.',
            ),
            array(
                'label' => 'استراتژی بارگذاری',
                'value' => $strategy_label,
                'hint'  => $strategy_hint,
            ),
            array(
                'label' => 'استایل و دیباگ',
                'value' => ( $css_on ? 'CSS روشن' : 'CSS خاموش' ) . ' · ' . ( $js_on ? 'JS روشن' : 'JS خاموش' ),
                'hint'  => ( $inline ? 'CSS اینلاین' : 'فایل مجزا' ) . ' | دیباگ ' . ( $debug_on ? 'فعال' : 'خاموش' ),
            ),
        );
    }

    private static function render_top_nav( $active_slug ) {
        ?>
        <div class="brz-top-nav">
            <div class="brz-top-nav__intro">
                <span class="brz-chip">ماژول‌ها و تنظیمات</span>
                <strong>Buyruz Suite</strong>
            </div>
            <div class="brz-top-nav__items">
                <?php foreach ( self::nav_items() as $item ) : ?>
                    <?php $is_active = ( $item['slug'] === $active_slug ); ?>
                    <a class="brz-top-nav__item <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['slug'] ) ); ?>">
                        <span><?php echo esc_html( $item['label'] ); ?></span>
                        <?php if ( isset( $item['module'] ) ) : ?>
                            <small>ماژول</small>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private static function render_notices() {
        settings_errors( 'brz_group' );

        if ( empty( $_GET['brz-msg'] ) || empty( $_GET['module'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $msg    = sanitize_key( wp_unslash( $_GET['brz-msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $module = sanitize_key( wp_unslash( $_GET['module'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $registry = BRZ_Modules::registry();
        $label    = isset( $registry[ $module ]['label'] ) ? $registry[ $module ]['label'] : $module;

        if ( 'module-on' === $msg ) {
            echo '<div class="notice notice-success"><p>' . esc_html( sprintf( '%s فعال شد.', $label ) ) . '</p></div>';
        } elseif ( 'module-off' === $msg ) {
            echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( '%s غیرفعال شد.', $label ) ) . '</p></div>';
        } elseif ( 'module-error' === $msg ) {
            echo '<div class="notice notice-error"><p>' . esc_html( sprintf( 'تغییر وضعیت %s امکان‌پذیر نبود.', $label ) ) . '</p></div>';
        }
    }

    private static function render_dashboard() {
        $modules = BRZ_Modules::registry();
        $states  = BRZ_Modules::get_states();

        self::render_shell( self::PARENT_SLUG, function() use ( $modules, $states ) {
            self::render_notices();
            ?>
            <div class="brz-section-header brz-section-header--modules">
                <div>
                    <h2>پیشخوان ماژول‌ها</h2>
                    <p>شبکهٔ مدرن و واکنش‌گرا برای کنترل سریع ماژول‌ها بدون رفرش صفحه.</p>
                </div>
                <div class="brz-section-actions">
                    <span class="brz-status is-on">واکنش‌گرا و سریع</span>
                    <a class="brz-button brz-button--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-general' ) ); ?>">تنظیمات عمومی</a>
                </div>
            </div>

            <div class="brz-inline-alert">
                ذخیره و تغییر وضعیت ماژول‌ها به‌صورت زنده و بدون رفرش انجام می‌شود. شبکهٔ کارت‌ها روی موبایل و دسکتاپ بهینه شده است.
            </div>

            <div class="brz-grid">
                <div class="brz-grid__main">
                    <div class="brz-module-grid">
                        <?php foreach ( $modules as $slug => $meta ) : ?>
                            <?php $enabled = ! empty( $states[ $slug ] ); ?>
                            <?php $icon = self::module_icon_letter( $meta ); ?>
                            <div class="brz-module-card <?php echo $enabled ? 'is-active' : 'is-inactive'; ?>" data-module="<?php echo esc_attr( $slug ); ?>">
                                <div class="brz-module-card__badge">ماژول</div>
                                <div class="brz-module-card__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></div>
                                <h3 class="brz-module-card__title"><?php echo esc_html( $meta['label'] ); ?></h3>
                                <?php if ( ! empty( $meta['description'] ) ) : ?>
                                    <p class="brz-module-card__desc"><?php echo esc_html( $meta['description'] ); ?></p>
                                <?php endif; ?>
                                <?php
                                if ( 'faq_rankmath' === $slug && ! class_exists( '\RankMath\Schema\DB' ) ) {
                                    echo '<p class="brz-warning">برای استفاده، افزونه Rank Math باید فعال باشد.</p>';
                                }
                                ?>
                                <div class="brz-module-card__footer">
                                    <div class="brz-toggle-wrap">
                                        <span class="brz-toggle-label"><?php echo $enabled ? 'روشن' : 'خاموش'; ?></span>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-toggle-form" data-module="<?php echo esc_attr( $slug ); ?>" data-label="<?php echo esc_attr( $meta['label'] ); ?>">
                                            <?php wp_nonce_field( 'brz_toggle_module_' . $slug ); ?>
                                            <input type="hidden" name="action" value="brz_toggle_module" />
                                            <input type="hidden" name="module" value="<?php echo esc_attr( $slug ); ?>" />
                                            <input type="hidden" name="state" value="<?php echo $enabled ? '0' : '1'; ?>" />
                                            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>" />
                                            <button type="submit" class="brz-toggle-switch <?php echo $enabled ? 'is-on' : 'is-off'; ?>">
                                                <span class="screen-reader-text"><?php echo $enabled ? 'غیرفعال کردن ماژول' : 'فعال کردن ماژول'; ?></span>
                                            </button>
                                        </form>
                                    </div>
                                    <a class="brz-link" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-' . $slug ) ); ?>">تنظیمات</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <aside class="brz-grid__aside">
                    <?php self::render_support_card(
                        'بهینه و تمیز',
                        array(
                            'بدون رفرش و بدون ایجاد دادهٔ اضافی در دیتابیس ذخیره می‌شود.',
                            'شبکهٔ کارت‌ها روی موبایل فشرده و دو ستونه می‌شود تا دید بهتری بدهد.',
                            'اگر ماژول غیرفعال باشد، هیچ فایل یا هوکی از آن لود نمی‌شود.',
                        ),
                        'راهنما'
                    ); ?>
                </aside>
            </div>
            <?php
        } );
    }

    private static function render_general_settings() {
        self::render_shell( 'buyruz-general', function() {
            self::render_notices();
            ?>
            <div class="brz-section-header">
                <div>
                    <h2>تنظیمات عمومی</h2>
                    <p>تنظیمات اصلی نمایش و بارگذاری FAQ برای همهٔ ماژول‌ها.</p>
                </div>
                <div class="brz-section-actions">
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>">بازگشت به پیشخوان</a>
                </div>
            </div>

            <div class="brz-inline-alert brz-inline-alert--info">
                ذخیرهٔ تنظیمات به‌صورت لحظه‌ای و بدون رفرش انجام می‌شود. برای سرعت بیشتر، حالت «سلکتور سفارشی» را در صفحات سازنده امتحان کنید.
            </div>

            <div class="brz-grid">
                <div class="brz-grid__main">
                    <form method="post" action="options.php" class="brz-settings-form" data-context="general">
                        <?php
                        settings_fields( 'brz_group' );
                        echo '<input type="hidden" name="' . BRZ_OPTION . '[brz_form_context]" value="general" />';
                        self::render_section_cards( array( 'brz_main', 'brz_load' ) );
                        ?>
                        <div class="brz-save-bar">
                            <span class="brz-save-state" aria-live="polite">تغییرات بدون رفرش ذخیره می‌شود.</span>
                            <?php submit_button( 'ذخیره تغییرات', 'primary', 'submit', false ); ?>
                        </div>
                    </form>
                </div>
                <aside class="brz-grid__aside">
                    <?php self::render_support_card(
                        'مسیر بهینه‌سازی',
                        array(
                            'CSS/JS فقط وقتی لود می‌شود که FAQ یا سلکتور سفارشی وجود داشته باشد.',
                            'برای صفحات شلوغ، گزینهٔ «اینلاین» درخواست اضافی را حذف می‌کند.',
                            'غیرفعال کردن هر گزینه فوراً اعمال می‌شود و دادهٔ اضافی در دیتابیس باقی نمی‌گذارد.',
                        ),
                        'راهنمای سرعت'
                    ); ?>
                    <?php self::render_guidelines_card(); ?>
                </aside>
            </div>
            <?php
        } );
    }

    private static function render_module_settings( $module_slug ) {
        $modules = BRZ_Modules::registry();
        $states  = BRZ_Modules::get_states();
        $active  = ! empty( $states[ $module_slug ] );

        self::render_shell( 'buyruz-module-' . $module_slug, function() use ( $modules, $module_slug, $active ) {
            self::render_notices();

            if ( 'debug' === $module_slug ) {
                ?>
                <div class="brz-section-header">
                    <div>
                        <h2>دیباگ و لاگ‌ها</h2>
                        <p>کنترل کامل روی لاگ‌ها بدون اثرگذاری روی عملکرد.</p>
                    </div>
                    <div class="brz-section-actions">
                        <span class="brz-status <?php echo $active ? 'is-on' : 'is-off'; ?>"><?php echo $active ? 'ماژول فعال است' : 'ماژول غیرفعال است'; ?></span>
                    </div>
                </div>
                <div class="brz-inline-alert brz-inline-alert--warning">
                    دیباگ فقط هنگام نیاز فعال شود؛ ذخیره‌سازی لاگ به صورت زنده و بدون رفرش فعال/غیرفعال می‌شود.
                </div>
                <div class="brz-grid">
                    <div class="brz-grid__main">
                        <form method="post" action="options.php" class="brz-settings-form" data-context="debug">
                            <?php
                            settings_fields( 'brz_group' );
                            echo '<input type="hidden" name="' . BRZ_OPTION . '[brz_form_context]" value="debug" />';
                            self::render_section_cards( array( 'brz_debug' ) );
                            ?>
                            <div class="brz-save-bar">
                                <span class="brz-save-state" aria-live="polite">تغییرات بدون رفرش ذخیره می‌شود.</span>
                                <?php submit_button( 'ذخیره تنظیمات دیباگ', 'primary', 'submit', false ); ?>
                            </div>
                        </form>
                    </div>
                    <aside class="brz-grid__aside">
                        <?php self::render_support_card(
                            'پاکسازی لاگ‌ها',
                            array(
                                'مسیر لاگ در کارت بالا نمایش داده می‌شود تا سریع به آن دسترسی داشته باشید.',
                                'پاکسازی خودکار بر اساس تعداد روز تعیین‌شده انجام می‌شود و فضا اشغال نمی‌ماند.',
                                'برای محیط تولید، گزینهٔ ماسک داده‌های حساس را روشن نگه دارید.',
                            ),
                            'توصیه'
                        ); ?>
                    </aside>
                </div>
                <?php
                return;
            }

            if ( 'faq_rankmath' === $module_slug ) {
                self::render_rankmath_module_card( $active );
                return;
            }

            $label = isset( $modules[ $module_slug ]['label'] ) ? $modules[ $module_slug ]['label'] : $module_slug;
            self::render_generic_module_card( $label, $active );
        } );
    }

    private static function render_rankmath_module_card( $active ) {
        $rankmath_active = class_exists( '\RankMath\Schema\DB' );
        ?>
        <div class="brz-section-header">
            <div>
                <h2>سوالات متداول محصولات (Rank Math)</h2>
                <p>خروجی FAQ Rank Math را به آکاردئون بایروز تبدیل می‌کند.</p>
            </div>
            <div class="brz-section-actions">
                <span class="brz-status <?php echo $active ? 'is-on' : 'is-off'; ?>"><?php echo $active ? 'ماژول فعال است' : 'ماژول غیرفعال است'; ?></span>
            </div>
        </div>

        <div class="brz-grid">
            <div class="brz-grid__main">
                <div class="brz-card">
                    <div class="brz-card__body">
                        <ul class="brz-checklist">
                            <li>نیازمند افزونه Rank Math فعال.</li>
                            <li>در صفحات دارای FAQ Rank Math به صورت خودکار HTML را به آکاردئون تبدیل می‌کند.</li>
                            <li>برای توقف موقت، از پیشخوان ماژول را غیرفعال کنید.</li>
                        </ul>
                        <?php if ( ! $rankmath_active ) : ?>
                            <p class="brz-warning">Rank Math روی این سایت فعال نیست. پس از فعال‌سازی، این ماژول به صورت خودکار FAQها را مدیریت می‌کند.</p>
                        <?php endif; ?>
                    </div>
                    <div class="brz-card__footer">
                        <a class="brz-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>">رفتن به پیشخوان برای تغییر وضعیت ماژول</a>
                    </div>
                </div>
            </div>
            <aside class="brz-grid__aside">
                <?php self::render_support_card(
                    'نکات هماهنگی',
                    array(
                        'با خاموش شدن ماژول، هیچ خروجی یا فایل اضافه‌ای بارگذاری نمی‌شود.',
                        'در حالت موبایل، فاصله‌ها فشرده می‌شوند تا FAQ خواناتر باشد.',
                        'برای تغییر رنگ برند، از تنظیمات عمومی استفاده کنید.',
                    ),
                    'سازگاری'
                ); ?>
            </aside>
        </div>
        <?php
    }

    private static function render_generic_module_card( $label, $active ) {
        ?>
        <div class="brz-grid">
            <div class="brz-grid__main">
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h2><?php echo esc_html( $label ); ?></h2>
                        <p>در حال حاضر تنظیمات اختصاصی برای این ماژول تعریف نشده است.</p>
                    </div>
                    <div class="brz-card__body">
                        <p>برای تغییر وضعیت فعال/غیرفعال به پیشخوان برگردید.</p>
                        <p class="brz-status <?php echo $active ? 'is-on' : 'is-off'; ?>"><?php echo $active ? 'فعال' : 'غیرفعال'; ?></p>
                    </div>
                    <div class="brz-card__footer">
                        <a class="brz-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>">بازگشت به پیشخوان</a>
                    </div>
                </div>
            </div>
            <aside class="brz-grid__aside">
                <?php self::render_support_card(
                    'ساده و تمیز',
                    array(
                        'تا زمانی که فعال نباشد، هیچ فایل یا هوکی از این ماژول لود نمی‌شود.',
                        'می‌توانید بعداً تنظیمات اختصاصی را اضافه کنید بدون آنکه دیتابیس آلوده شود.',
                        'برای فعال/غیرفعال کردن، از پیشخوان استفاده کنید؛ لحظه‌ای اعمال می‌شود.',
                    ),
                    'اطلاعات'
                ); ?>
            </aside>
        </div>
        <?php
    }

    private static function render_section_cards( array $sections ) {
        $all_sections = self::sections_meta();
        echo '<div class="brz-card-stack">';
        foreach ( $sections as $id ) {
            if ( empty( $all_sections[ $id ] ) ) { continue; }
            $meta = $all_sections[ $id ];
            echo '<section class="brz-card" id="' . esc_attr( $id ) . '">';
            echo '<div class="brz-card__header">';
            echo '<h2>' . esc_html( $meta['title'] ) . '</h2>';
            if ( ! empty( $meta['description'] ) ) {
                echo '<p>' . esc_html( $meta['description'] ) . '</p>';
            }
            echo '</div>';
            echo '<div class="brz-card__body">';
            if ( isset( $meta['callback'] ) && is_callable( $meta['callback'] ) ) {
                call_user_func( $meta['callback'] );
            } else {
                echo '<table class="form-table" role="presentation"><tbody>';
                do_settings_fields( 'brz-settings', $id );
                echo '</tbody></table>';
            }
            echo '</div>';
            if ( ! empty( $meta['footer'] ) ) {
                echo '<div class="brz-card__footer">' . wp_kses_post( $meta['footer'] ) . '</div>';
            }
            echo '</section>';
        }
        echo '</div>';
    }

    public static function render_debug_components_field() {
        $selected = (array) self::get( 'debug_components', array() );
        $components = BRZ_Debug::available_components();

        if ( empty( $components ) ) {
            echo '<p class="description">در حال حاضر هیچ بخشی برای لاگ‌گیری تعریف نشده است.</p>';
            return;
        }

        foreach ( $components as $key => $meta ) {
            $checked = in_array( $key, $selected, true );
            echo '<label style="display:block;margin-bottom:4px;">';
            echo '<input type="checkbox" name="'.BRZ_OPTION.'[debug_components][]" value="'.esc_attr( $key ).'" '.checked( true, $checked, false ).'> ';
            echo '<strong>'.esc_html( $meta['label'] ).'</strong>';
            if ( ! empty( $meta['description'] ) ) {
                echo '<span class="description" style="margin-right:6px;">'.esc_html( $meta['description'] ).'</span>';
            }
            echo '</label>';
        }

        echo '<p class="description">پس از اضافه شدن بخش‌های جدید، می‌توانید لاگ‌گیری آن‌ها را از اینجا فعال کنید.</p>';
    }

    public static function render_debug_log_path_field() {
        $dir = BRZ_Debug::get_log_directory();
        echo '<p><code style="direction:ltr;display:inline-block;">'.esc_html( $dir ).'</code></p>';

        if ( ! file_exists( $dir ) ) {
            echo '<p class="description">پوشه به صورت خودکار هنگام اولین ثبت لاگ ساخته می‌شود. اطمینان حاصل کنید دسترسی نوشتن روی پوشهٔ افزونه وجود داشته باشد.</p>';
            return;
        }

        $files = glob( trailingslashit( $dir ) . 'brz-*.log' );
        if ( empty( $files ) ) {
            echo '<p class="description">هنوز هیچ لاگی ایجاد نشده است.</p>';
            return;
        }

        sort( $files );
        $recent = array_slice( $files, -5 );

        echo '<p class="description">نمونه فایل‌های اخیر:</p>';
        echo '<ul style="margin:0 0 0 1.5em; list-style: disc;">';
        foreach ( $recent as $file ) {
            $basename = basename( $file );
            echo '<li><code>'.esc_html( $basename ).'</code></li>';
        }
        echo '</ul>';
    }

    private static function module_icon_letter( $meta ) {
        $label = isset( $meta['label'] ) ? $meta['label'] : '';
        if ( function_exists( 'mb_substr' ) ) {
            $char = mb_substr( $label, 0, 1, 'UTF-8' );
        } else {
            $char = substr( $label, 0, 1 );
        }
        return $char ? $char : '•';
    }

    private static function render_guidelines_card() {
        ?>
        <div class="brz-card">
            <div class="brz-card__header">
                <h2>راهنمای توسعه و پاکسازی</h2>
            </div>
            <div class="brz-card__body">
                <ul class="brz-checklist">
                    <li>هر ماژول جدید باید در صورت غیرفعال شدن، داده‌های خود را از دیتابیس یا کش پاک کند.</li>
                    <li>بارگذاری فایل‌ها باید فقط در صورت نیاز هر صفحه انجام شود؛ از هوک‌های شرطی یا دیفر استفاده کنید.</li>
                    <li>برای حفظ سرعت، اسکریپت‌ها و استایل‌های بایروز را در یک صف نگه دارید و از وابستگی‌های سنگین پرهیز کنید.</li>
                    <li>رابط کاربری باید با الگوهای طراحی وردپرس هماهنگ باشد اما حس مدرن و ساده‌ای ارائه دهد.</li>
                </ul>
            </div>
        </div>
        <?php
    }

    private static function render_support_card( $title, array $items, $badge = '' ) {
        echo '<div class="brz-side-card">';
        if ( ! empty( $badge ) ) {
            echo '<span class="brz-side-card__badge">' . esc_html( $badge ) . '</span>';
        }
        echo '<h3 class="brz-side-card__title">' . esc_html( $title ) . '</h3>';
        if ( ! empty( $items ) ) {
            echo '<ul class="brz-side-card__list">';
            foreach ( $items as $item ) {
                echo '<li>' . esc_html( $item ) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    public static function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'buyruz-' ) === false ) {
            return;
        }
        wp_enqueue_style( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.css', array(), BRZ_VERSION );
        wp_enqueue_script( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.js', array(), BRZ_VERSION, true );
        wp_localize_script(
            'brz-settings-admin',
            'brzSettings',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'successOn'    => 'ماژول فعال شد',
                'successOff'   => 'ماژول غیرفعال شد',
                'failText'     => 'تغییر وضعیت انجام نشد. دوباره تلاش کنید.',
                'nonceField'   => '_wpnonce',
                'screenReader' => 'تغییر وضعیت ماژول',
                'saveNonce'    => wp_create_nonce( 'brz_save_settings' ),
                'savingText'   => 'در حال ذخیره...',
                'savedText'    => 'تنظیمات ذخیره شد',
                'saveFailText' => 'ذخیره انجام نشد. دوباره تلاش کنید.',
            )
        );
    }

    public static function handle_toggle_module() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'شما مجوز کافی ندارید.', 'buyruz' ) );
        }

        $slug = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $state = isset( $_POST['state'] ) ? (int) wp_unslash( $_POST['state'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=' . self::PARENT_SLUG ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( empty( $redirect ) ) {
            $redirect = admin_url( 'admin.php?page=' . self::PARENT_SLUG );
        }

        if ( empty( $slug ) || null === $state ) {
            wp_safe_redirect( add_query_arg( array( 'page' => self::PARENT_SLUG, 'brz-msg' => 'module-error', 'module' => $slug ), $redirect ) );
            exit;
        }

        check_admin_referer( 'brz_toggle_module_' . $slug );

        $result = self::toggle_module_state( $slug, $state );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => self::PARENT_SLUG, 'brz-msg' => 'module-error', 'module' => $slug ), $redirect ) );
            exit;
        }

        $msg = $state ? 'module-on' : 'module-off';
        wp_safe_redirect( add_query_arg( array( 'page' => self::PARENT_SLUG, 'brz-msg' => $msg, 'module' => $slug ), $redirect ) );
        exit;
    }

    public static function handle_toggle_module_ajax() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        $slug  = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $state = isset( $_POST['state'] ) ? (int) wp_unslash( $_POST['state'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( empty( $slug ) || null === $state ) {
            wp_send_json_error( array( 'message' => 'دادهٔ نامعتبر' ), 400 );
        }

        check_ajax_referer( 'brz_toggle_module_' . $slug );

        $result = self::toggle_module_state( $slug, $state );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success(
            array(
                'state'  => $result['state'],
                'label'  => $result['label'],
                'text'   => $result['state'] ? 'فعال' : 'غیرفعال',
                'status' => $result['state'] ? 'on' : 'off',
            )
        );
    }

    public static function handle_save_settings_ajax() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        check_ajax_referer( 'brz_save_settings', 'security' );

        $input = isset( $_POST[ BRZ_OPTION ] ) ? (array) wp_unslash( $_POST[ BRZ_OPTION ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $sanitized = self::sanitize( $input );

        update_option( BRZ_OPTION, $sanitized, false );
        self::$options_cache = $sanitized;

        wp_send_json_success(
            array(
                'message' => 'تنظیمات ذخیره شد.',
                'accent'  => isset( $sanitized['brand_color'] ) ? $sanitized['brand_color'] : self::get( 'brand_color', '#ff5668' ),
            )
        );
    }

    private static function toggle_module_state( $slug, $state ) {
        $registry = BRZ_Modules::registry();
        if ( empty( $registry[ $slug ] ) ) {
            return new WP_Error( 'brz_invalid_module', 'ماژول معتبر نیست' );
        }

        $states = BRZ_Modules::get_states();
        $states[ $slug ] = $state ? 1 : 0;

        $current = self::get();
        if ( ! is_array( $current ) ) {
            $current = array();
        }

        $current['modules'] = $states;
        update_option( BRZ_OPTION, $current, false );
        self::$options_cache = $current;

        $label = isset( $registry[ $slug ]['label'] ) ? $registry[ $slug ]['label'] : $slug;

        return array(
            'state' => $states[ $slug ],
            'label' => $label,
        );
    }

    public static function sanitize( $input ) {
        $existing = self::get();
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        $output  = $existing;
        $context = '';

        if ( isset( $input['brz_form_context'] ) ) {
            $context = sanitize_key( $input['brz_form_context'] );
            unset( $input['brz_form_context'] );
        }

        // Modules (preserve by default)
        if ( isset( $input['modules'] ) && is_array( $input['modules'] ) ) {
            $states = array();
            foreach ( BRZ_Modules::registry() as $slug => $meta ) {
                $states[ $slug ] = ! empty( $input['modules'][ $slug ] ) ? 1 : 0;
            }
            $output['modules'] = $states;
            unset( $input['modules'] );
        } elseif ( ! isset( $output['modules'] ) ) {
            $output['modules'] = BRZ_Modules::default_states();
        }

        // General settings.
        if ( 'general' === $context || isset( $input['enable_css'] ) || isset( $input['load_strategy'] ) ) {
            $checkboxes = array( 'enable_css', 'inline_css', 'enable_js', 'single_open', 'animate', 'compact_mobile' );
            foreach ( $checkboxes as $checkbox ) {
                if ( isset( $input[ $checkbox ] ) ) {
                    $output[ $checkbox ] = $input[ $checkbox ] ? 1 : 0;
                    unset( $input[ $checkbox ] );
                }
            }

            if ( isset( $input['brand_color'] ) ) {
                $output['brand_color'] = sanitize_text_field( $input['brand_color'] );
                unset( $input['brand_color'] );
            }

            if ( isset( $input['load_strategy'] ) ) {
                $allowed = array( 'auto', 'all', 'selector' );
                $strategy = sanitize_text_field( $input['load_strategy'] );
                $output['load_strategy'] = in_array( $strategy, $allowed, true ) ? $strategy : 'auto';
                unset( $input['load_strategy'] );
            }

            if ( isset( $input['custom_selector'] ) ) {
                $output['custom_selector'] = sanitize_text_field( $input['custom_selector'] );
                unset( $input['custom_selector'] );
            }
        }

        // Debug settings.
        if ( 'debug' === $context || isset( $input['debug_enabled'] ) || isset( $input['debug_components'] ) ) {
            $allowed_debug_components = array_keys( BRZ_Debug::available_components() );

            if ( isset( $input['debug_enabled'] ) ) {
                $output['debug_enabled'] = isset( $input['debug_enabled'] ) ? 1 : 0;
                unset( $input['debug_enabled'] );
            }

            if ( isset( $input['debug_components'] ) ) {
                $components = array_map( 'sanitize_text_field', (array) $input['debug_components'] );
                $components = array_values( array_intersect( $components, $allowed_debug_components ) );
                $output['debug_components'] = $components;
                unset( $input['debug_components'] );
            } elseif ( 'debug' === $context ) {
                $output['debug_components'] = array();
            }

            if ( isset( $input['debug_mask_sensitive'] ) ) {
                $output['debug_mask_sensitive'] = $input['debug_mask_sensitive'] ? 1 : 0;
                unset( $input['debug_mask_sensitive'] );
            }

            if ( isset( $input['debug_retention_days'] ) ) {
                $days = (int) $input['debug_retention_days'];
                if ( $days < 1 ) {
                    $days = 1;
                } elseif ( $days > 30 ) {
                    $days = 30;
                }
                $output['debug_retention_days'] = $days;
                unset( $input['debug_retention_days'] );
            }
        }

        // Any remaining string values.
        foreach ( $input as $key => $value ) {
            if ( is_string( $value ) ) {
                $output[ $key ] = sanitize_text_field( $value );
            } else {
                $output[ $key ] = $value;
            }
        }

        self::$options_cache = $output;
        return $output;
    }
}
BRZ_Settings::init();
