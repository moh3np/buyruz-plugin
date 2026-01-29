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
                    <p>مدیریت همه اتصال‌ها: گوگل شیت، فروشگاه ↔ بلاگ، API هوش مصنوعی.</p>
                </div>
                <div class="brz-hero__aside">
                    <div class="brz-hero__badge">اتصالات</div>
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
                        <?php self::render_bi(); ?>
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
                        <th scope="row"><label for="brz-sl-sheet-id">Google Sheet ID <span class="dashicons dashicons-editor-help" title="شناسه شیت را از URL شیت بردارید."></span></label></th>
                        <td><input type="text" id="brz-sl-sheet-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[sheet_id]" class="regular-text" value="<?php echo esc_attr( $settings['sheet_id'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-sheet-url">Web App URL <span class="dashicons dashicons-editor-help" title="آدرس Web App منتشر شده از Google Apps Script."></span></label></th>
                        <td>
                            <input type="url" id="brz-sl-sheet-url" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[sheet_web_app]" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['sheet_web_app'] ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-client-id">Google Client ID <span class="dashicons dashicons-editor-help" title="Client ID اپ OAuth از Google Cloud Console."></span></label></th>
                        <td><input type="text" id="brz-sl-client-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_client_id]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['google_client_id'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-client-secret">Google Client Secret</label></th>
                        <td>
                            <input type="password" id="brz-sl-client-secret" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_client_secret]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['google_client_secret'] ); ?>" />
                            <p class="description">Redirect URL: <?php echo esc_url( admin_url( 'admin-post.php?action=brz_gsheet_oauth_cb' ) ); ?></p>
                            <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=brz_gsheet_oauth_start' ), 'brz_gsheet_oauth' ) ); ?>">اتصال به گوگل</a>
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
                        <th scope="row"><label for="brz-sl-remote-key">Remote API Key</label></th>
                        <td>
                            <input type="text" id="brz-sl-remote-key" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_api_key]" class="regular-text" value="<?php echo esc_attr( $settings['remote_api_key'] ); ?>" />
                            <button type="button" class="button" id="brz-sl-test-peer">تست اتصال ریموت</button>
                            <span class="description" id="brz-sl-peer-status"></span>
                        </td>
                    </tr>
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

    private static function render_bi() {
        $enabled = class_exists( 'BRZ_Modules' ) ? BRZ_Modules::is_enabled( 'bi_exporter' ) : true;
        $state   = class_exists( 'BRZ_BI_Exporter' ) ? BRZ_BI_Exporter::get_state() : array();
        $status  = 'منتظر اولین اجرا';
        if ( ! empty( $state['status'] ) ) {
            if ( 'running' === $state['status'] ) {
                $status = 'در حال پردازش (' . (int) $state['processed'] . ' / ' . (int) $state['total'] . ')';
            } elseif ( 'finished' === $state['status'] ) {
                $status = 'آماده - ' . mysql2date( 'Y-m-d H:i', $state['finished_at'] );
            }
        }
        $regen_nonce  = wp_create_nonce( 'brz_bi_exporter_regen' );
        $status_nonce = wp_create_nonce( 'brz_bi_exporter_status' );
        $download_url = wp_nonce_url( admin_url( 'admin-post.php?action=brz_bi_exporter_download' ), 'brz_bi_exporter_download' );
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header"><h3>تحلیل سایت (BI Dump)</h3></div>
            <div class="brz-card__body">
                <p class="description">گزارش JSON فشرده از تمام محصولات/پست‌ها برای ممیزی سئو و فروش. پردازش در پس‌زمینه انجام می‌شود.</p>
                <?php if ( ! $enabled ) : ?>
                    <p class="description" style="color:#b91c1c;">ماژول هوش تجاری غیرفعال است. از پیشخوان ماژول‌ها فعال کنید.</p>
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . BRZ_Settings::PARENT_SLUG ) ); ?>">رفتن به پیشخوان</a>
                <?php else : ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                        <button type="button" class="button button-primary" id="brz-bi-mini-regenerate" data-nonce="<?php echo esc_attr( $regen_nonce ); ?>">بازسازی گزارش</button>
                        <button type="button" class="button" id="brz-bi-mini-refresh" data-nonce="<?php echo esc_attr( $status_nonce ); ?>">به‌روزرسانی وضعیت</button>
                        <a class="button" href="<?php echo esc_url( $download_url ); ?>">دانلود JSON</a>
                        <a class="brz-link" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-bi_exporter' ) ); ?>">جزئیات و تنظیمات</a>
                    </div>
                    <p id="brz-bi-mini-status" class="description" style="margin-top:8px;"><?php echo esc_html( $status ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function inline_js() {
        $nonce = wp_create_nonce( 'brz_smart_linker_save' );
        $bi_regen = wp_create_nonce( 'brz_bi_exporter_regen' );
        $bi_status = wp_create_nonce( 'brz_bi_exporter_status' );
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

            const biNonces = {
                regen: '<?php echo esc_js( $bi_regen ); ?>',
                status: '<?php echo esc_js( $bi_status ); ?>'
            };

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

            // BI mini controls
            const biRegen=document.getElementById('brz-bi-mini-regenerate');
            const biRefresh=document.getElementById('brz-bi-mini-refresh');
            const biStatusEl=document.getElementById('brz-bi-mini-status');

            function updateBiStatus(text,isError){
                if(!biStatusEl) return;
                biStatusEl.textContent=text||'';
                biStatusEl.style.color = isError ? '#b91c1c' : '';
            }

            function pollBiStatus(){
                if(!biStatusEl || !window.ajaxurl) return;
                const fd=new FormData();fd.append('action','brz_bi_exporter_status');fd.append('_wpnonce',biNonces.status);
                fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{
                    if(j?.success && j.data?.state){
                        const st=j.data.state;
                        if(st.status==='running'){
                            updateBiStatus('در حال پردازش ('+(st.processed||0)+' / '+(st.total||0)+')');
                        }else if(st.status==='finished'){
                            updateBiStatus('آماده - '+(st.finished_at||''));
                        }
                    }
                }).catch(()=>{});
            }

            if(biRegen){
                biRegen.addEventListener('click',()=>{
                    updateBiStatus('در حال صف‌بندی...');
                    const fd=new FormData();fd.append('action','brz_bi_exporter_regenerate');fd.append('_wpnonce',biNonces.regen);
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{
                        if(j?.success){
                            updateBiStatus('در صف پردازش...');
                            pollBiStatus();
                        }else{
                            updateBiStatus('خطا در صف‌بندی',true);
                        }
                    }).catch(()=>updateBiStatus('خطا در صف‌بندی',true));
                });
            }
            if(biRefresh){
                biRefresh.addEventListener('click',()=>{
                    pollBiStatus();
                });
            }
        })();
        </script>
        <?php
    }
}
