<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_Settings {
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
                echo '<p class="description">برای دسترسی به مخزن خصوصی و پیشنهاد نسخه‌های جدید لازم است.</p>';
            }
            if ( $expiry ) {
                echo '<p class="description">تاریخ انقضای توکن: '. esc_html( $expiry ) .'</p>';
            } else {
                echo '<p class="description">در صورت ارائه از سوی GitHub، تاریخ انقضا پس از اولین ارتباط نمایش داده می‌شود.</p>';
            }
        }, 'rfa-settings', 'rfa_updates' );
    }

    public static function render() {
        ?>
        <div class="wrap" dir="rtl">
            <h1>تنظیمات بایروز</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'rfa_group' );
                do_settings_sections( 'rfa-settings' );
                submit_button();
                ?>
            </form>
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
        delete_transient( 'rfa_token_error' );
        if ( ! isset( $output['github_token'] ) && ! $existing_token_preserved ) {
            delete_transient( 'rfa_token_expiry' );
        }

        return $output;
    }
}
RFA_Settings::init();
