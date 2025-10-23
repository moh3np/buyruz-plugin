<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RFA_Settings {
    public static function get( $key = null, $default = null ) {
        $opts = get_option( RFA_OPTION, array() );
        if ( $key === null ) { return $opts; }
        return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
    }

    public static function page() {
        add_options_page(
            'RM FAQ Accordion',
            'RM FAQ Accordion',
            'manage_options',
            'rfa-settings',
            array( __CLASS__, 'render' )
        );
    }

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        register_setting( 'rfa_group', RFA_OPTION );

        add_settings_section( 'rfa_main', 'تنظیمات اصلی', '__return_false', 'rfa-settings' );

        add_settings_field( 'enable_css', 'فعال‌سازی CSS', function(){
            $v = self::get('enable_css',1);
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[enable_css]" value="1" '.checked(1,$v,false).'> بارگذاری استایل</label>';
            echo '<p class="description">با غیرفعال‌شدن، هیچ استایلی تزریق نمی‌شود.</p>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'inline_css', 'درون‌خطی کردن CSS', function(){
            $v = self::get('inline_css',1);
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[inline_css]" value="1" '.checked(1,$v,false).'> افزودن CSS به صورت inline</label>';
            echo '<p class="description">درون‌خطی باعث حذف درخواست فایل جداگانه می‌شود و برای سرعت بهتر است.</p>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'brand_color', 'رنگ برند', function(){
            $v = esc_attr( self::get('brand_color','#ff5668') );
            echo '<input type="text" class="regular-text" name="'.RFA_OPTION.'[brand_color]" value="'.$v.'" />';
            echo '<p class="description">مثال: #ff5668</p>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'enable_js', 'فعال‌سازی JS', function(){
            $v = self::get('enable_js',1);
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[enable_js]" value="1" '.checked(1,$v,false).'> بارگذاری اسکریپت آکاردئون</label>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'single_open', 'فقط یک مورد باز', function(){
            $v = self::get('single_open',1);
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[single_open]" value="1" '.checked(1,$v,false).'> همواره فقط یک سؤال باز باشد</label>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'animate', 'انیمیشن نرم', function(){
            $v = self::get('animate',1);
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[animate]" value="1" '.checked(1,$v,false).'> فعال</label>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_field( 'compact_mobile', 'نسخه موبایل فشرده', function(){
            $v = self::get('compact_mobile',1);
            echo '<label><input type="checkbox" name="'.RFA_OPTION.'[compact_mobile]" value="1" '.checked(1,$v,false).'> فعال</label>';
        }, 'rfa-settings', 'rfa_main' );

        add_settings_section( 'rfa_load', 'استراتژی بارگذاری', '__return_false', 'rfa-settings' );

        add_settings_field( 'load_strategy', 'حالت بارگذاری', function(){
            $v = self::get('load_strategy','auto');
            ?>
            <label><input type="radio" name="<?php echo RFA_OPTION; ?>[load_strategy]" value="auto" <?php checked('auto',$v); ?>> خودکار (وقتی FAQ موجود باشد)</label><br/>
            <label><input type="radio" name="<?php echo RFA_OPTION; ?>[load_strategy]" value="all" <?php checked('all',$v); ?>> همه صفحات</label><br/>
            <label><input type="radio" name="<?php echo RFA_OPTION; ?>[load_strategy]" value="selector" <?php checked('selector',$v); ?>> فقط صفحات دارای سلکتور سفارشی</label>
            <?php
        }, 'rfa-settings', 'rfa_load' );

        add_settings_field( 'custom_selector', 'سلکتور سفارشی', function(){
            $v = esc_attr( self::get('custom_selector','.rank-math-faq') );
            echo '<input type="text" class="regular-text" name="'.RFA_OPTION.'[custom_selector]" value="'.$v.'" />';
            echo '<p class="description">وقتی حالت "selector" فعال است، اگر این سلکتور در HTML صفحه وجود داشته باشد، افزونه فعال می‌شود.</p>';
        }, 'rfa-settings', 'rfa_load' );
    }

    public static function render() {
        ?>
        <div class="wrap" dir="rtl">
            <h1>RM FAQ Accordion (Rank Math)</h1>
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
}
RFA_Settings::init();
