<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BRZ_Connections {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_brz_regenerate_api_key', array( __CLASS__, 'ajax_regenerate_api_key' ) );
    }

    /**
     * Regenerate local API key via AJAX.
     */
    public static function ajax_regenerate_api_key() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $settings = BRZ_Smart_Linker::get_settings();
        $new_key  = wp_generate_password( 32, false );
        $settings['local_api_key'] = $new_key;
        update_option( BRZ_Smart_Linker::OPTION_KEY, $settings, false );

        wp_send_json_success( array( 'new_key' => $new_key, 'message' => 'کلید جدید ساخته شد.' ) );
    }

    public static function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'buyruz-connections' ) ) {
            return;
        }
        wp_enqueue_style( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.css', array(), BRZ_VERSION );
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
        $brand = esc_attr( BRZ_Settings::get( 'brand_color', '#1a73e8' ) );
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
                        <th scope="row"><label for="brz-sl-sheet-id">Google Sheet ID <span class="brz-help-tip" data-tip="شناسه شیت در URL بین /d/ و /edit قرار دارد.">?</span></label></th>
                        <td><input type="text" id="brz-sl-sheet-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[sheet_id]" class="regular-text" value="<?php echo esc_attr( $settings['sheet_id'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-client-id">OAuth Client ID <span class="brz-help-tip" data-tip="کلاینت OAuth 2.0 (Web application) از Google Cloud Console.">?</span></label></th>
                        <td><input type="text" id="brz-sl-client-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_client_id]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['google_client_id'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-client-secret">OAuth Client Secret</label></th>
                        <td><input type="password" id="brz-sl-client-secret" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_client_secret]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['google_client_secret'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-refresh-token">Refresh Token <span class="brz-help-tip" data-tip="پس از احراز هویت، رفرش توکن را ذخیره کنید.">?</span></label></th>
                        <td><input type="text" id="brz-sl-refresh-token" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_refresh_token]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( isset( $settings['google_refresh_token'] ) ? $settings['google_refresh_token'] : '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>احراز هویت</label></th>
                        <td>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=brz_gsheet_oauth_start' ), 'brz_gsheet_oauth' ) ); ?>">اتصال / نوسازی توکن</a>
                                <button type="button" class="button" id="brz-sl-test-gsheet">تست اتصال</button>
                                <span class="description" id="brz-sl-gsheet-status"></span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( 'ذخیره اتصال گوگل', 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    private static function render_peer( $settings ) {
        // Get local endpoint and API key for this site
        $local_endpoint = rest_url( 'brz/v1/inventory' );
        $local_api_key  = $settings['local_api_key'] ?? '';
        ?>
        <!-- اطلاعات API این سایت -->
        <div class="brz-card brz-card--sub" style="margin-bottom: 24px; background: linear-gradient(135deg, #e0f2fe, #f0fdf4); border: 1px solid #bae6fd;">
            <div class="brz-card__header" style="border-bottom: 1px solid #bae6fd;">
                <h3 style="display: flex; align-items: center; gap: 8px;">
                    <span style="width: 24px; height: 24px; background: #22c55e; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 12px;">📡</span>
                    اطلاعات API این سایت (برای اشتراک با سایت دیگر)
                </h3>
            </div>
            <div class="brz-card__body">
                <p class="description" style="margin-bottom: 16px;">این اطلاعات را کپی کنید و در سایت مقصد (که می‌خواهد از این سایت داده دریافت کند) در بخش Remote وارد کنید.</p>
                
                <table class="form-table" role="presentation" style="margin: 0;">
                    <tbody>
                        <tr>
                            <th scope="row" style="width: 140px;"><label>Endpoint این سایت</label></th>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="text" id="brz-local-endpoint" class="regular-text code" dir="ltr" value="<?php echo esc_url( $local_endpoint ); ?>" readonly style="background: #fff;" />
                                    <button type="button" class="button brz-copy-btn" data-target="brz-local-endpoint" title="کپی">📋</button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>API Key این سایت</label></th>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="text" id="brz-local-apikey" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $local_api_key ); ?>" readonly style="background: #fff;" />
                                    <button type="button" class="button brz-copy-btn" data-target="brz-local-apikey" title="کپی">📋</button>
                                    <button type="button" class="button" id="brz-regenerate-key" title="ساخت کلید جدید">🔄</button>
                                </div>
                                <p class="description" style="margin-top: 8px;">این کلید خودکار ساخته شده. برای امنیت بیشتر می‌توانید آن را رژنریت کنید.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- اتصال به سایت ریموت -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-connections' ) ); ?>" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="brz-sl-remote-endpoint">آدرس API سایت مقابل</label></th>
                        <td>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <span class="brz-help-tip" data-tip="آدرس endpoint سایت مقابل برای دریافت داده.">؟</span>
                                <input type="url" id="brz-sl-remote-endpoint" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_endpoint]" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['remote_endpoint'] ); ?>" style="flex:1;" />
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-remote-key">کلید API سایت مقابل</label></th>
                        <td>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <span class="brz-help-tip" data-tip="کلید API که از سایت مقابل کپی کردید.">؟</span>
                                <input type="text" id="brz-sl-remote-key" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_api_key]" class="regular-text" value="<?php echo esc_attr( $settings['remote_api_key'] ); ?>" style="flex:1;" />
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>تست اتصال</label></th>
                        <td>
                            <button type="button" class="button" id="brz-sl-test-peer">تست اتصال ریموت</button>
                            <span class="description" id="brz-sl-peer-status"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-role">نقش این سایت</label></th>
                        <td>
                            <select id="brz-sl-role" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[site_role]">
                                <option value="shop" <?php selected( $settings['site_role'], 'shop' ); ?>>فروشگاه (ووکامرس)</option>
                                <option value="blog" <?php selected( $settings['site_role'], 'blog' ); ?>>بلاگ (وردپرس)</option>
                            </select>
                            <p class="description">بر اساس نقش، نوع داده‌هایی که به اشتراک گذاشته می‌شود تعیین می‌شود.</p>
                        </td>
                    </tr>
                    <input type="hidden" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[mode]" value="api" />
                </tbody>
            </table>
            <p class="submit" style="display:flex;gap:8px;align-items:center;margin-top:16px;">
                <?php submit_button( 'ذخیره اتصال ریموت', 'primary', 'submit', false ); ?>
                <button type="button" class="button" id="brz-sl-sync-btn">همگام‌سازی داده</button>
                <span id="brz-sl-sync-status" class="description"></span>
            </p>
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

            // Copy buttons functionality
            document.querySelectorAll('.brz-copy-btn').forEach(btn=>{
                btn.addEventListener('click', ()=>{
                    const targetId = btn.dataset.target;
                    const input = document.getElementById(targetId);
                    if(input){
                        navigator.clipboard.writeText(input.value).then(()=>{
                            const orig = btn.textContent;
                            btn.textContent = '✓';
                            btn.style.background = '#22c55e';
                            btn.style.color = '#fff';
                            setTimeout(()=>{ btn.textContent = orig; btn.style.background = ''; btn.style.color = ''; }, 1500);
                        });
                    }
                });
            });

            // Regenerate API key
            const regenBtn = document.getElementById('brz-regenerate-key');
            if(regenBtn){
                regenBtn.addEventListener('click', ()=>{
                    if(!confirm('آیا کلید جدید بسازم؟ کلید قبلی دیگر کار نخواهد کرد.')) return;
                    regenBtn.textContent = '⏳';
                    regenBtn.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'brz_regenerate_api_key');
                    fd.append('_wpnonce', '<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd})
                        .then(r=>r.json())
                        .then(j=>{
                            if(j?.success && j?.data?.new_key){
                                document.getElementById('brz-local-apikey').value = j.data.new_key;
                                regenBtn.textContent = '✓';
                                setTimeout(()=>{ regenBtn.textContent = '🔄'; regenBtn.disabled = false; }, 1500);
                            } else {
                                regenBtn.textContent = '❌';
                                setTimeout(()=>{ regenBtn.textContent = '🔄'; regenBtn.disabled = false; }, 1500);
                            }
                        })
                        .catch(()=>{ regenBtn.textContent = '🔄'; regenBtn.disabled = false; });
                });
            }

            // Help tooltips - modern popup instead of alert
            document.querySelectorAll('.brz-help-tip').forEach(icon=>{
                const tip = icon.dataset.tip;
                if(tip){
                    icon.setAttribute('role','button');
                    icon.setAttribute('tabindex','0');
                    icon.setAttribute('aria-label','راهنما');
                    
                    // Create tooltip element
                    const tooltip = document.createElement('span');
                    tooltip.className = 'brz-tooltip';
                    tooltip.textContent = tip;
                    icon.appendChild(tooltip);
                    
                    // Toggle on click
                    icon.addEventListener('click', (e)=>{
                        e.stopPropagation();
                        document.querySelectorAll('.brz-help-tip.is-open').forEach(el=>{ if(el!==icon) el.classList.remove('is-open'); });
                        icon.classList.toggle('is-open');
                    });
                    icon.addEventListener('keydown', (ev)=>{ if(ev.key==='Enter' || ev.key===' '){ ev.preventDefault(); icon.click(); }});
                }
            });
            // Close tooltips when clicking outside
            document.addEventListener('click', ()=>{ document.querySelectorAll('.brz-help-tip.is-open').forEach(el=>el.classList.remove('is-open')); });

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
