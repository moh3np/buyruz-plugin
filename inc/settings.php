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
            'rfa_updates' => array(
                'title'       => 'به‌روزرسانی خودکار از گیت‌هاب',
                'description' => 'اتصال پایدار به GitHub با امکان انتخاب شاخه و استفادهٔ اختیاری از توکن برای جلوگیری از محدودیت نرخ یا دسترسی به مخزن خصوصی.',
                'footer'      => 'در صورت تغییر شاخه یا توکن، کش به‌روزرسانی و هشدارها پاک می‌شوند تا بررسی تازه انجام شود.',
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

        add_settings_section( 'rfa_updates', 'به‌روزرسانی خودکار', '__return_false', 'rfa-settings' );

        add_settings_field( 'github_token', 'توکن دسترسی گیت‌هاب', function(){
            $v = self::get( 'github_token', '' );
            $expiry = get_transient( 'rfa_token_expiry' );
            $type = $v ? 'text' : 'password';
            $value_attr = $v ? ' value="'.esc_attr( $v ).'"' : ' value=""';
            echo '<input type="'.$type.'" class="regular-text" name="'.RFA_OPTION.'[github_token]"'.$value_attr.' autocomplete="off" placeholder="github_pat_..." style="direction:ltr" />';
            if ( $v ) {
                echo '<p class="description">توکن ذخیره شده است. برای جایگزینی، مقدار جدید را وارد کنید.</p>';
                echo '<input type="hidden" name="'.RFA_OPTION.'[github_token_existing]" value="1" />';
                echo '<label><input type="checkbox" name="'.RFA_OPTION.'[github_token_clear]" value="1"> حذف توکن ذخیره‌شده</label>';
            } else {
                echo '<p class="description">در صورت خالی بودن، درخواست‌های عمومی بدون توکن انجام می‌شود. برای مخزن خصوصی یا جلوگیری از محدودیت نرخ، توکن با سطح <code>public_repo</code> (یا <code>repo</code> برای خصوصی) را ثبت کنید.</p>';
            }
            if ( $expiry ) {
                $timestamp = strtotime( $expiry );
                if ( $timestamp ) {
                    $now        = current_time( 'timestamp' );
                    $difference = $timestamp - $now;
                    if ( $difference > 0 ) {
                        $days = (int) ceil( $difference / DAY_IN_SECONDS );
                        echo '<p class="description">توکن تا '. esc_html( number_format_i18n( $days ) ) .' روز دیگر معتبر است.</p>';
                    } elseif ( abs( $difference ) < DAY_IN_SECONDS ) {
                        echo '<p class="description">توکن امروز منقضی می‌شود.</p>';
                    } else {
                        $days = (int) ceil( abs( $difference ) / DAY_IN_SECONDS );
                        echo '<p class="description">توکن '. esc_html( number_format_i18n( $days ) ) .' روز پیش منقضی شده است.</p>';
                    }
                } else {
                    echo '<p class="description">زمان انقضای توکن: '. esc_html( $expiry ) .'</p>';
                }
            } else {
                echo '<p class="description">در صورت ارائه از سوی GitHub، تاریخ انقضا پس از اولین ارتباط نمایش داده می‌شود.</p>';
            }
        }, 'rfa-settings', 'rfa_updates' );

        add_settings_field( 'github_branch', 'شاخهٔ به‌روزرسانی', function(){
            $branch = self::get( 'github_branch', '' );
            echo '<input type="text" class="regular-text" name="'.RFA_OPTION.'[github_branch]" value="'.esc_attr( $branch ).'" placeholder="main" style="direction:ltr" />';
            echo '<p class="description">اگر خالی بماند، شاخهٔ پیش‌فرض مخزن در GitHub استفاده می‌شود. این مقدار با فیلتر <code>rfa_update_branch</code> نیز قابل تغییر است.</p>';
        }, 'rfa-settings', 'rfa_updates' );

        add_settings_field( 'github_guidance', 'راهنمای اتصال', array( __CLASS__, 'render_updates_guidance_field' ), 'rfa-settings', 'rfa_updates' );
    }

    public static function render_updates_guidance_field() {
        $hosts = array(
            'api.github.com',
            'github.com',
            'codeload.github.com',
            'raw.githubusercontent.com',
        );

        echo '<p class="description">پیش از فعال‌سازی خودکار، نکات زیر را بررسی کنید:</p>';
        echo '<ul style="margin:0 0 0 1.5em; list-style: disc;">';
        echo '<li>توکن اختیاری است؛ برای ریپوی عمومی سطح <code>public_repo</code> کافی است و برای ریپوی خصوصی سطح <code>repo</code> را انتخاب کنید.</li>';
        echo '<li>شاخهٔ تعیین‌شده در این صفحه روی همهٔ درخواست‌ها (متادیتا و بستهٔ ZIP) اعمال می‌شود؛ در صورت خالی بودن شاخهٔ پیش‌فرض GitHub استفاده می‌شود.</li>';
        echo '<li>اگر <code>WP_HTTP_BLOCK_EXTERNAL</code> فعال باشد، دامنه‌های '. esc_html( implode( ', ', $hosts ) ) .' باید در <code>WP_ACCESSIBLE_HOSTS</code> قرار بگیرند.</li>';
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

        $existing_token_preserved = false;

        $clear_requested = isset( $input['github_token_clear'] );

        if ( isset( $input['github_token_existing'] ) && empty( $input['github_token'] ) && ! $clear_requested ) {
            if ( isset( $existing['github_token'] ) ) {
                $output['github_token'] = $existing['github_token'];
                $existing_token_preserved = true;
            }
        } elseif ( isset( $input['github_token'] ) ) {
            $token = sanitize_text_field( trim( $input['github_token'] ) );
            if ( $token ) {
                $output['github_token'] = $token;
            }
        }

        unset( $input['github_token'], $input['github_token_existing'], $input['github_token_clear'] );

        foreach ( $input as $key => $value ) {
            if ( is_string( $value ) ) {
                $output[ $key ] = sanitize_text_field( $value );
            } else {
                $output[ $key ] = $value;
            }
        }

        delete_transient( 'rfa_remote_meta' );
        delete_transient( 'rfa_update_error' );
        if ( ! isset( $output['github_token'] ) && ! $existing_token_preserved ) {
            delete_transient( 'rfa_token_expiry' );
        }

        return $output;
    }
}
RFA_Settings::init();
