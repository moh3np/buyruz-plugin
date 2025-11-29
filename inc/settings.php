<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_Settings {
    private static function sections_meta() {
        $sections = array(
            'rfa_main'    => array(
                'title'       => 'نمایش و تجربه کاربری',
                'description' => 'کنترل کامل روی ظاهر آکاردئون و رفتار تعاملی آن. تغییرات این بخش فوراً روی صفحات دارای Rank Math FAQ اعمال می‌شوند.',
                'footer'      => 'نکته: در صورت غیرفعال کردن استایل یا اسکریپت، ساختار FAQ بدون آکاردئون نمایش داده می‌شود تا دسترسی‌پذیری حفظ شود.',
            ),
            'rfa_load'    => array(
                'title'       => 'بارگذاری هوشمند',
                'description' => 'استراتژی فعال‌سازی افزونه را مشخص کنید تا فقط در صفحاتی که واقعاً نیاز دارند منابع بارگذاری شوند.',
                'footer'      => 'برای بهترین کارایی، حالت خودکار پیشنهاد می‌شود مگر در شرایط خاص صفحه‌سازها که نیاز به سلکتور سفارشی دارند.',
            ),
            'rfa_debug'   => array(
                'title'       => 'دیباگ و لاگ‌ها',
                'description' => 'کنترل کامل روی سطح لاگ‌گیری افزونه برای عیب‌یابی نسخه‌های آینده و ردیابی خطاهای احتمالی.',
                'footer'      => 'پوشهٔ لاگ در مسیر افزونه نگه‌داری می‌شود. برای جلوگیری از پر شدن دیسک، مدت نگهداری با مقدار پیش‌فرض ۷ روز محدود شده است.',
            ),
            'rfa_guidelines' => array(
                'title'       => 'راهنمای توسعه و پاکسازی',
                'description' => 'توصیه‌های کلیدی برای افزودن ماژول‌های آینده بدون قربانی کردن سرعت و سلامت دیتابیس.',
                'callback'    => array( __CLASS__, 'render_guidelines_card' ),
            ),
        );

        return apply_filters( 'rfa/settings/sections_meta', $sections );
    }

    public static function get( $key = null, $default = null ) {
        $opts = get_option( RFA_OPTION, array() );
        if ( $key === null ) { return $opts; }
        return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
    }

    public static function page() {
        add_menu_page(
            'تنظیمات بایروز',
            'تنظیمات بایروز',
            'manage_options',
            'rfa-settings',
            array( __CLASS__, 'render' ),
            'dashicons-admin-generic',
            3
        );
    }

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function register() {
        register_setting( 'rfa_group', RFA_OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );

        add_settings_section( 'rfa_main', 'تنظیمات اصلی', '__return_false', 'rfa-settings' );

        add_settings_field( 'enable_css', 'فعال‌سازی CSS', function(){
            $v = self::get( 'enable_css', 1 );
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[enable_css]" value="1" '.checked( 1, $v, false ).'> بارگذاری استایل</label>';
            echo '<p class="description">با غیرفعال‌شدن، هیچ استایلی تزریق نمی‌شود.</p>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'inline_css', 'درون‌خطی کردن CSS', function(){
            $v = self::get( 'inline_css', 1 );
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[inline_css]" value="1" '.checked( 1, $v, false ).'> افزودن CSS به صورت inline</label>';
            echo '<p class="description">درون‌خطی باعث حذف درخواست فایل جداگانه می‌شود و برای سرعت بهتر است.</p>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'brand_color', 'رنگ برند', function(){
            $v = esc_attr( self::get( 'brand_color', '#ff5668' ) );
            echo '<input type="text" class="regular-text" name="'.RFA_OPTION.'[brand_color]" value="'.$v.'" />';
            echo '<p class="description">مثال: #ff5668</p>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'enable_js', 'فعال‌سازی JS', function(){
            $v = self::get( 'enable_js', 1 );
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[enable_js]" value="1" '.checked( 1, $v, false ).'> بارگذاری اسکریپت آکاردئون</label>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'single_open', 'فقط یک مورد باز', function(){
            $v = self::get( 'single_open', 1 );
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[single_open]" value="1" '.checked( 1, $v, false ).'> همواره فقط یک سؤال باز باشد</label>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'animate', 'انیمیشن نرم', function(){
            $v = self::get( 'animate', 1 );
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[animate]" value="1" '.checked( 1, $v, false ).'> فعال</label>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'compact_mobile', 'نسخه موبایل فشرده', function(){
            $v = self::get( 'compact_mobile', 1 );
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[compact_mobile]" value="1" '.checked( 1, $v, false ).'> فعال</label>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_section( 'rfa_load', 'استراتژی بارگذاری', '__return_false', 'rfa-settings' );

        add_settings_field( 'load_strategy', 'حالت بارگذاری', function(){
            $v = self::get( 'load_strategy', 'auto' );
            ?>
            <label><input type="radio" name="<?php echo RFA_OPTION; ?>[load_strategy]" value="auto" <?php checked( 'auto', $v ); ?>> خودکار (وقتی FAQ موجود باشد)</label><br/>
            <label><input type="radio" name="<?php echo RFA_OPTION; ?>[load_strategy]" value="all" <?php checked( 'all', $v ); ?>> همه صفحات</label><br/>
            <label><input type="radio" name="<?php echo RFA_OPTION; ?>[load_strategy]" value="selector" <?php checked( 'selector', $v ); ?>> فقط صفحات دارای سلکتور سفارشی</label>
            <?php
        }, 'rfa-settings', 'rfa_load' );

        add_settings_field( 'custom_selector', 'سلکتور سفارشی', function(){
            $v = esc_attr( self::get( 'custom_selector', '.rank-math-faq' ) );
            echo '<input type="text" class="regular-text" name="'.RFA_OPTION.'[custom_selector]" value="'.$v.'" />';
            echo '<p class="description">وقتی حالت "selector" فعال است، اگر این سلکتور در HTML صفحه وجود داشته باشد، افزونه فعال می‌شود.</p>';
        }, 'rfa-settings', 'rfa_load' );

        add_settings_section( 'rfa_debug', 'دیباگ و لاگ‌ها', '__return_false', 'rfa-settings' );

        add_settings_field( 'debug_enabled', 'فعال‌سازی دیباگ', function(){
            $enabled = (bool) self::get( 'debug_enabled', 0 );
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[debug_enabled]" value="1" '.checked( true, $enabled, false ).'> هنگام فعال بودن، لاگ‌گیری بر اساس انتخاب‌های زیر انجام می‌شود.</label>';
        }, 'rfa-settings', 'rfa_debug' );

        add_settings_field( 'debug_components', 'بخش‌های قابل لاگ', array( __CLASS__, 'render_debug_components_field' ), 'rfa-settings', 'rfa_debug' );

        add_settings_field( 'debug_mask_sensitive', 'ماسک داده‌های حساس', function(){
            $mask = (bool) self::get( 'debug_mask_sensitive', 1 );
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[debug_mask_sensitive]" value="1" '.checked( true, $mask, false ).'> مقادیر شامل توکن، Authorization و رشته‌های محرمانه در لاگ‌ها با [masked] جایگزین شوند.</label>';
            echo '<p class="description">در صورت نیاز به لاگ کامل برای محیط‌های آزمایشی می‌توانید این گزینه را غیرفعال کنید، اما نسبت به امنیت فایل دقت کنید.</p>';
        }, 'rfa-settings', 'rfa_debug' );

        add_settings_field( 'debug_retention_days', 'مدت نگهداری لاگ', function(){
            $days = (int) self::get( 'debug_retention_days', 7 );
            if ( $days < 1 ) { $days = 1; }
            echo '<input type="number" class="small-text" name="'.RFA_OPTION.'[debug_retention_days]" value="'.esc_attr( $days ).'" min="1" max="30" />';
            echo '<p class="description">به‌صورت پیش‌فرض لاگ‌ها هر ۷ روز پاکسازی می‌شوند. می‌توانید بین ۱ تا ۳۰ روز تنظیم کنید.</p>';
        }, 'rfa-settings', 'rfa_debug' );

        add_settings_field( 'debug_log_path', 'محل ذخیره لاگ', array( __CLASS__, 'render_debug_log_path_field' ), 'rfa-settings', 'rfa_debug' );
    }

    public static function render_debug_components_field() {
        $selected = (array) self::get( 'debug_components', array() );
        $components = RFA_Debug::available_components();

        if ( empty( $components ) ) {
            echo '<p class="description">در حال حاضر هیچ بخشی برای لاگ‌گیری تعریف نشده است.</p>';
            return;
        }

        foreach ( $components as $key => $meta ) {
            $checked = in_array( $key, $selected, true );
            echo '<label style="display:block;margin-bottom:4px;">';
            echo '<input type="checkbox" name="'.RFA_OPTION.'[debug_components][]" value="'.esc_attr( $key ).'" '.checked( true, $checked, false ).'> ';
            echo '<strong>'.esc_html( $meta['label'] ).'</strong>';
            if ( ! empty( $meta['description'] ) ) {
                echo '<span class="description" style="margin-right:6px;">'.esc_html( $meta['description'] ).'</span>';
            }
            echo '</label>';
        }

        echo '<p class="description">پس از اضافه شدن بخش‌های جدید، می‌توانید لاگ‌گیری آن‌ها را از اینجا فعال کنید.</p>';
    }

    public static function render_debug_log_path_field() {
        $dir = RFA_Debug::get_log_directory();
        echo '<p><code style="direction:ltr;display:inline-block;">'.esc_html( $dir ).'</code></p>';

        if ( ! file_exists( $dir ) ) {
            echo '<p class="description">پوشه به صورت خودکار هنگام اولین ثبت لاگ ساخته می‌شود. اطمینان حاصل کنید دسترسی نوشتن روی پوشهٔ افزونه وجود داشته باشد.</p>';
            return;
        }

        $files = glob( trailingslashit( $dir ) . 'rfa-*.log' );
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

    public static function render() {
        $sections = self::sections_meta();
        ?>
        <div class="rfa-admin-wrap" dir="rtl">
            <div class="rfa-hero">
                <h1>کانون تنظیمات بایروز</h1>
                <p>از اینجا می‌توانید همهٔ قابلیت‌های اختصاصی بایروز را مدیریت کنید. تمرکز ما بر تجربهٔ کاربری مدرن، سرعت بالا و سادگی مدیریت است.</p>
                <ul>
                    <li>استفاده از بهترین الگوهای طراحی رابط و تعامل برای مدیر و کاربر.</li>
                    <li>پایداری عملکرد با بارگذاری شرطی منابع و پاکسازی داده‌های اضافه.</li>
                    <li>آمادگی برای افزودن ماژول‌های جدید بدون پیچیده شدن داشبورد.</li>
                </ul>
            </div>

            <?php settings_errors( 'rfa_group' ); ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'rfa_group' ); ?>

                <div class="rfa-section-grid">
                    <?php foreach ( $sections as $id => $meta ) : ?>
                        <section class="rfa-card" id="<?php echo esc_attr( $id ); ?>">
                            <div class="rfa-card__header">
                                <h2><?php echo esc_html( $meta['title'] ); ?></h2>
                                <?php if ( ! empty( $meta['description'] ) ) : ?>
                                    <p><?php echo esc_html( $meta['description'] ); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="rfa-card__body">
                                <?php
                                if ( isset( $meta['callback'] ) && is_callable( $meta['callback'] ) ) {
                                    call_user_func( $meta['callback'] );
                                } else {
                                    echo '<table class="form-table" role="presentation"><tbody>';
                                    do_settings_fields( 'rfa-settings', $id );
                                    echo '</tbody></table>';
                                }
                                ?>
                            </div>

                            <?php if ( ! empty( $meta['footer'] ) ) : ?>
                                <div class="rfa-card__footer">
                                    <?php echo wp_kses_post( $meta['footer'] ); ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <div class="rfa-save-bar">
                    <?php submit_button( 'ذخیره تغییرات', 'primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
        <?php
    }

    public static function enqueue_assets( $hook ) {
        if ( 'toplevel_page_rfa-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'rfa-settings-admin', RFA_URL . 'assets/admin/settings.css', array(), RFA_VERSION );
    }

    private static function render_guidelines_card() {
        ?>
        <div class="rfa-alert">
            <strong>اصول کلی توسعه:</strong>
            <ul>
                <li>هر ماژول جدید باید در صورت غیرفعال شدن، داده‌های خود را از دیتابیس یا کش پاک کند.</li>
                <li>بارگذاری فایل‌ها باید فقط در صورت نیاز هر صفحه انجام شود؛ از هوک‌های شرطی یا دیفر استفاده کنید.</li>
                <li>برای حفظ سرعت، اسکریپت‌ها و استایل‌های بایروز را در یک صف نگه دارید و از وابستگی‌های سنگین پرهیز کنید.</li>
                <li>رابط کاربری باید با الگوهای طراحی وردپرس هماهنگ باشد اما حس مدرن و ساده‌ای ارائه دهد.</li>
            </ul>
        </div>
        <?php
    }

    public static function sanitize( $input ) {
        $existing = self::get();
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        $output = array();

        $allowed_debug_components = array_keys( RFA_Debug::available_components() );

        $output['debug_enabled'] = isset( $input['debug_enabled'] ) ? 1 : 0;
        unset( $input['debug_enabled'] );

        if ( isset( $input['debug_components'] ) ) {
            $components = array_map( 'sanitize_text_field', (array) $input['debug_components'] );
            $components = array_values( array_intersect( $components, $allowed_debug_components ) );
        } else {
            $components = array();
        }
        $output['debug_components'] = $components;
        unset( $input['debug_components'] );

        $output['debug_mask_sensitive'] = isset( $input['debug_mask_sensitive'] ) ? 1 : 0;
        unset( $input['debug_mask_sensitive'] );

        if ( isset( $input['debug_retention_days'] ) ) {
            $days = (int) $input['debug_retention_days'];
            if ( $days < 1 ) {
                $days = 1;
            } elseif ( $days > 30 ) {
                $days = 30;
            }
            $output['debug_retention_days'] = $days;
            unset( $input['debug_retention_days'] );
        } elseif ( isset( $existing['debug_retention_days'] ) ) {
            $output['debug_retention_days'] = (int) $existing['debug_retention_days'];
        } else {
            $output['debug_retention_days'] = 7;
        }

        foreach ( $input as $key => $value ) {
            if ( is_string( $value ) ) {
                $output[ $key ] = sanitize_text_field( $value );
            } else {
                $output[ $key ] = $value;
            }
        }

        return $output;
    }
}
RFA_Settings::init();
