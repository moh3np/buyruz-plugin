<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Shared partial for connections tab
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" data-ajax="1">
    <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
    <input type="hidden" name="action" value="brz_smart_linker_save" />
    <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-smart_linker&tab=connections' ) ); ?>" />
    <h3>Google Sheet / App Script</h3>
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><label for="brz-sl-sheet-id">Google Sheet ID</label></th>
                <td>
                    <input type="text" id="brz-sl-sheet-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[sheet_id]" class="regular-text" value="<?php echo esc_attr( $settings['sheet_id'] ); ?>" />
                    <p class="description" dir="ltr">مثال: 1Abc...xyz</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="brz-sl-sheet-url">Web App URL</label></th>
                <td>
                    <input type="url" id="brz-sl-sheet-url" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[sheet_web_app]" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['sheet_web_app'] ); ?>" />
                    <p class="description">آدرس Web App منتشر شده از Google Apps Script.</p>
                    <button type="button" class="button" id="brz-sl-test-gsheet">تست اتصال</button>
                    <span class="description" id="brz-sl-gsheet-status"></span>
                </td>
            </tr>
        </tbody>
    </table>

    <h3>Peer-to-Peer (Shop ↔ Blog)</h3>
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><label for="brz-sl-remote-endpoint">Remote API Endpoint</label></th>
                <td>
                    <input type="url" id="brz-sl-remote-endpoint" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_endpoint]" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['remote_endpoint'] ); ?>" />
                    <p class="description">مثال: https://blog.example.com/wp-json/buyruz/v1/inventory</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="brz-sl-remote-key">Remote API Key</label></th>
                <td>
                    <input type="text" id="brz-sl-remote-key" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_api_key]" class="regular-text" value="<?php echo esc_attr( $settings['remote_api_key'] ); ?>" />
                    <p class="description">برای احراز هویت درخواست Sync استفاده می‌شود.</p>
                    <button type="button" class="button" id="brz-sl-test-peer">تست اتصال ریموت</button>
                    <span class="description" id="brz-sl-peer-status"></span>
                </td>
            </tr>
        </tbody>
    </table>
    <div class="brz-save-bar" style="display:flex;gap:8px;align-items:center;">
        <?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
        <button type="button" class="button" id="brz-sl-sync-btn">Sync Data</button>
        <span id="brz-sl-sync-status" class="description"></span>
    </div>
</form>
