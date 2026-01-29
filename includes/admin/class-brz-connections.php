<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BRZ_Connections {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            BRZ_Settings::PARENT_SLUG,
            'اتصالات',
            'اتصالات',
            BRZ_Settings::CAPABILITY,
            'buyruz-connections',
            array( __CLASS__, 'render_page' ),
            3
        );
    }

    public static function render_page() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            return;
        }
        $settings = BRZ_Smart_Linker::get_settings();
        $brand = esc_attr( BRZ_Settings::get( 'brand_color', '#ff5668' ) );
        ?>
        <div class="brz-admin-wrap" dir="rtl" style="--brz-brand: <?php echo $brand; ?>;">
            <div class="brz-hero">
                <div class="brz-hero__content">
                    <div class="brz-hero__title-row">
                        <h1>اتصالات بایروز</h1>
                        <span class="brz-hero__version">نسخه <?php echo esc_html( BRZ_VERSION ); ?></span>
                    </div>
                    <p>یکپارچه‌سازی بلاگ ↔ فروشگاه، Google Sheet و API هوش مصنوعی با یک مسیر تنظیماتی.</p>
                </div>
            </div>

            <div class="brz-card">
                <h2 class="nav-tab-wrapper">
                    <a class="nav-tab nav-tab-active" data-brz-tab="gsheet">گوگل شیت</a>
                    <a class="nav-tab" data-brz-tab="peer">فروشگاه / بلاگ</a>
                    <a class="nav-tab" data-brz-tab="ai">API هوش مصنوعی</a>
                    <a class="nav-tab" data-brz-tab="bi">تحلیل سایت</a>
                </h2>
                <div class="brz-card__body">
                    <div class="brz-tab-pane" data-pane="gsheet">
                        <?php self::render_gsheet( $settings ); ?>
                    </div>
                    <div class="brz-tab-pane" data-pane="peer" style="display:none;">
                        <?php self::render_peer( $settings ); ?>
                    </div>
                    <div class="brz-tab-pane" data-pane="ai" style="display:none;">
                        <?php self::render_ai(); ?>
                    </div>
                    <div class="brz-tab-pane" data-pane="bi" style="display:none;">
                        <?php self::render_bi_settings(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php self::inline_js(); ?>
        <?php
    }

    private static function render_gsheet( $settings ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-connections' ) ); ?>" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="brz-sl-sheet-id">Google Sheet ID <span class="dashicons dashicons-editor-help" title="شناسه شیت در URL بین /d/ و /edit قرار دارد."></span></label></th>
                        <td><input type="text" id="brz-sl-sheet-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[sheet_id]" class="regular-text" value="<?php echo esc_attr( $settings['sheet_id'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-client-id">OAuth Client ID <span class="dashicons dashicons-editor-help" title="کلاینت OAuth 2.0 (Web application) از Google Cloud Console. Redirect URI: <?php echo esc_url( admin_url( 'admin-post.php?action=brz_gsheet_oauth_cb' ) ); ?>"></span></label></th>
                        <td><input type="text" id="brz-sl-client-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_client_id]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['google_client_id'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-client-secret">OAuth Client Secret</label></th>
                        <td><input type="password" id="brz-sl-client-secret" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_client_secret]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['google_client_secret'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-refresh-token">Refresh Token <span class="dashicons dashicons-editor-help" title="پس از احراز هویت، رفرش توکن را ذخیره کنید تا دسترسی پایدار باشد."></span></label></th>
                        <td><input type="text" id="brz-sl-refresh-token" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_refresh_token]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( isset( $settings['google_refresh_token'] ) ? $settings['google_refresh_token'] : '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Auth</label></th>
                        <td>
                            <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=brz_gsheet_oauth_start' ), 'brz_gsheet_oauth' ) ); ?>">اتصال / نوسازی توکن</a>
                            <button type="button" class="button" id="brz-sl-test-gsheet">تست اتصال</button>
                            <span class="description" id="brz-sl-gsheet-status"></span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( 'ذخیره اتصال گوگل', 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    private static function render_peer( $settings ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-connections' ) ); ?>" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="brz-sl-remote-endpoint">Remote API Endpoint <span class="dashicons dashicons-editor-help" title="آدرس endpoint سایت مقابل برای اینونتوری."></span></label></th>
                        <td><input type="url" id="brz-sl-remote-endpoint" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_endpoint]" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['remote_endpoint'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-remote-key">Remote API Key <span class="dashicons dashicons-editor-help" title="توکن ایمن سرور مقابل برای full-dump."></span></label></th>
                        <td>
                            <input type="text" id="brz-sl-remote-key" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_api_key]" class="regular-text" value="<?php echo esc_attr( $settings['remote_api_key'] ); ?>" />
                            <button type="button" class="button" id="brz-sl-test-peer">تست اتصال ریموت</button>
                            <span class="description" id="brz-sl-peer-status"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-role">نقش این سایت</label></th>
                        <td>
                            <select id="brz-sl-role" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[site_role]">
                                <option value="shop" <?php selected( $settings['site_role'], 'shop' ); ?>>Shop (WooCommerce)</option>
                                <option value="blog" <?php selected( $settings['site_role'], 'blog' ); ?>>Blog (WordPress)</option>
                            </select>
                            <p class="description">بر اساس نقش، گره shop/blog در JSON تعیین می‌شود.</p>
                        </td>
                    </tr>
                    <input type="hidden" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[mode]" value="api" />
                </tbody>
            </table>
            <div class="brz-save-bar" style="display:flex;gap:8px;align-items:center;">
                <?php submit_button( 'ذخیره اتصال ریموت', 'primary', 'submit', false ); ?>
                <button type="button" class="button" id="brz-sl-sync-btn">Sync Data</button>
                <span id="brz-sl-sync-status" class="description"></span>
            </div>
        </form>
        <?php
    }

    private static function render_ai() {
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header"><h3>API هوش مصنوعی</h3></div>
            <div class="brz-card__body">
                <p class="description">در اینجا می‌توانید اتصال به APIهای هوش مصنوعی (OpenAI و ...) را مدیریت کنید. (Placeholder)</p>
            </div>
        </div>
        <?php
    }

    private static function render_bi_settings() {
        if ( ! class_exists( 'BRZ_BI_Exporter' ) ) {
            echo '<p class="description">ماژول تحلیل سایت فعال نیست.</p>';
            return;
        }
        $settings = BRZ_BI_Exporter::get_settings();
        $save_nonce = wp_create_nonce( 'brz_bi_exporter_save' );
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header"><h3>تنظیمات ارتباط تحلیل سایت</h3></div>
            <div class="brz-card__body">
                <form id="brz-bi-settings-form" class="brz-settings-form">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $save_nonce ); ?>" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="brz-bi-api-key">کلید API <span class="dashicons dashicons-editor-help" data-tip="کلید محافظت از endpoint full-dump؛ در query یا هدر X-Buyruz-Key استفاده می‌شود."></span></label></th>
                                <td>
                                    <input type="text" id="brz-bi-api-key" name="<?php echo esc_attr( BRZ_BI_Exporter::OPTION_KEY ); ?>[api_key]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brz-bi-remote-endpoint">Remote Endpoint <span class="dashicons dashicons-editor-help" data-tip="آدرس کامل endpoint سایت مقابل، مثلا https://peer-site.com/wp-json/buyruz/v1/full-dump"></span></label></th>
                                <td>
                                    <input type="url" id="brz-bi-remote-endpoint" name="<?php echo esc_attr( BRZ_BI_Exporter::OPTION_KEY ); ?>[remote_endpoint]" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['remote_endpoint'] ); ?>" placeholder="https://peer-site.com/wp-json/buyruz/v1/full-dump" />
                                    <p class="description">درخواست با scope=local ارسال می‌شود تا از لوپ Shop ↔ Blog جلوگیری شود.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brz-bi-remote-key">Remote API Key <span class="dashicons dashicons-editor-help" data-tip="همان کلیدی که روی سایت مقابل برای full-dump تنظیم شده است."></span></label></th>
                                <td>
                                    <input type="text" id="brz-bi-remote-key" name="<?php echo esc_attr( BRZ_BI_Exporter::OPTION_KEY ); ?>[remote_api_key]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['remote_api_key'] ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brz-bi-role">نقش این سایت <span class="dashicons dashicons-editor-help" data-tip="Shop برای ووکامرس، Blog برای وردپرس معمولی؛ در ساخت master JSON گره shop/blog تعیین می‌شود."></span></label></th>
                                <td>
                                    <select id="brz-bi-role" name="<?php echo esc_attr( BRZ_BI_Exporter::OPTION_KEY ); ?>[site_role]">
                                        <option value="shop" <?php selected( $settings['site_role'], 'shop' ); ?>>Shop (WooCommerce)</option>
                                        <option value="blog" <?php selected( $settings['site_role'], 'blog' ); ?>>Blog (WordPress)</option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="brz-save-bar" style="display:flex;gap:8px;align-items:center;">
                        <button type="submit" class="button button-primary">ذخیره تنظیمات</button>
                        <span id="brz-bi-save-status" class="description"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private static function inline_js() {
        $nonce = wp_create_nonce( 'brz_smart_linker_save' );
        ?>
        <script>
        (function(){
            const tabs = document.querySelectorAll('.nav-tab-wrapper .nav-tab');
            const panes = document.querySelectorAll('.brz-tab-pane');
            tabs.forEach(tab=>{
                tab.addEventListener('click', ()=>{
                    tabs.forEach(t=>t.classList.remove('nav-tab-active'));
                    tab.classList.add('nav-tab-active');
                    const target = tab.getAttribute('data-brz-tab');
                    panes.forEach(p=>p.style.display = (p.dataset.pane === target ? 'block' : 'none'));
                });
            });

            const ajaxForms = document.querySelectorAll('form[data-ajax="1"]');
            ajaxForms.forEach(form=>{
                form.addEventListener('submit', function(e){
                    if (!window.ajaxurl) return;
                    e.preventDefault();
                    const data = new FormData(form);
                    data.append('action','brz_smart_linker_save');
                    data.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data})
                        .then(r=>r.json()).then(json=>{
                            alert(json && json.success ? 'ذخیره شد' : 'خطا در ذخیره');
                        }).catch(()=>alert('خطا در ذخیره'));
                });
            });

            // BI settings ajax save
            const biForm = document.getElementById('brz-bi-settings-form');
            const biStatus = document.getElementById('brz-bi-save-status');
            if(biForm){
                biForm.addEventListener('submit', function(e){
                    e.preventDefault();
                    const fd=new FormData(biForm);
                    fd.append('action','brz_bi_exporter_save');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
                        .then(r=>r.json())
                        .then(j=>{
                            if(j?.success){ biStatus.textContent='ذخیره شد'; }
                            else { biStatus.textContent='خطا در ذخیره'; biStatus.style.color='#b91c1c'; }
                        })
                        .catch(()=>{ biStatus.textContent='خطا در ذخیره'; biStatus.style.color='#b91c1c'; });
                });
            }

            // Help tooltips on click
            document.querySelectorAll('.dashicons-editor-help').forEach(icon=>{
                const tip = icon.dataset.tip || icon.getAttribute('title');
                if(tip){
                    icon.setAttribute('role','button');
                    icon.setAttribute('tabindex','0');
                    icon.addEventListener('click', ()=>alert(tip));
                    icon.addEventListener('keydown', (ev)=>{ if(ev.key==='Enter' || ev.key===' '){ ev.preventDefault(); icon.click(); }});
                }
            });

            const syncBtn=document.getElementById('brz-sl-sync-btn');
            if(syncBtn){
                const status=document.getElementById('brz-sl-sync-status');
                syncBtn.addEventListener('click',()=>{
                    const fd=new FormData();fd.append('action','brz_smart_linker_sync_cache');fd.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{status.textContent=j?.data?.message||'Done';}).catch(()=>status.textContent='خطا');
                });
            }

            const testG=document.getElementById('brz-sl-test-gsheet');
            if(testG){
                const s=document.getElementById('brz-sl-gsheet-status');
                testG.addEventListener('click',()=>{
                    s.textContent='Testing...';
                    const fd=new FormData();fd.append('action','brz_smart_linker_test_gsheet');fd.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{s.textContent=j?.data?.message||'OK';}).catch(()=>s.textContent='خطا');
                });
            }

            const testP=document.getElementById('brz-sl-test-peer');
            if(testP){
                const s=document.getElementById('brz-sl-peer-status');
                testP.addEventListener('click',()=>{
                    s.textContent='Testing...';
                    const fd=new FormData();fd.append('action','brz_smart_linker_test_peer');fd.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{s.textContent=j?.data?.message||'OK';}).catch(()=>s.textContent='خطا');
                });
            }
        })();
        </script>
        <?php
    }
}
