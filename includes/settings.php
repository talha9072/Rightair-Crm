<?php
/**
 * Admin Settings Page for Right Air CRM
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the settings page
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Right Air CRM Settings',
        'Right Air CRM',
        'manage_options',
        'right-air-crm-settings',
        'racrm_render_settings_page',
        'dashicons-groups',
        30
    );
});

/**
 * Render the settings page
 */
function racrm_render_settings_page() {
    // 1. Handle form submission
    if (isset($_POST['racrm_save_settings'])) {
        check_admin_referer('racrm_settings_action');

        update_option('racrm_client_id', sanitize_text_field($_POST['client_id']));
        update_option('racrm_client_secret', sanitize_text_field($_POST['client_secret']));
        update_option('racrm_refresh_token', sanitize_text_field($_POST['refresh_token']));
        update_option('racrm_org_id', sanitize_text_field($_POST['org_id']));
        update_option('racrm_api_domain', esc_url_raw($_POST['api_domain']));

        echo '<div class="updated"><p>Settings saved successfully!</p></div>';
        
        // Log the event
        racrm_log("⚙️ Settings updated by " . wp_get_current_user()->user_login);
    }

    // 2. Handle Test Token Refresh Action
    if (isset($_GET['test_connection']) && $_GET['test_connection'] == 1) {
        $token = racrm_refresh_access_token();
        if ($token) {
            echo '<div class="updated"><p>Successfully refreshed access token!</p></div>';
        } else {
            echo '<div class="error"><p>Failed to refresh access token. Check the debug log for details.</p></div>';
        }
    }

    // 3. Handle Test API Connection Action
    $api_test_content = '';
    if (isset($_GET['test_api']) && $_GET['test_api'] == 1) {
        $endpoint = 'https://www.zohoapis.com/crm/v8/users';
        $response = racrm_api_get($endpoint);
        
        if ($response && (isset($response['users']) || (isset($response['status']) && $response['status'] === 'success'))) {
            $api_test_content = '<div class="updated"><p>✅ Connected to Zoho CRM</p><details><summary>View API Response</summary><pre>' . esc_html(print_r($response, true)) . '</pre></details></div>';
        } else {
            $api_test_content = '<div class="error"><p>❌ CRM API Error</p><pre>' . esc_html(print_r($response, true)) . '</pre></div>';
        }
    }

    // 4. Fetch CURRENT settings for display (after possible updates/refreshes)
    $client_id     = get_option('racrm_client_id', '');
    $client_secret = get_option('racrm_client_secret', '');
    $refresh_token = get_option('racrm_refresh_token', '');
    $org_id        = get_option('racrm_org_id', '');
    $api_domain    = get_option('racrm_api_domain', 'https://www.zohoapis.com');
    $access_token  = get_option('racrm_access_token', '');
    $token_expiry  = get_option('racrm_token_expiry', 0);
    $last_refresh  = get_option('racrm_token_last_refresh', 0);

    // Connection Status check
    $is_connected = !empty($access_token) && $token_expiry > time();
    ?>
    <div class="wrap">
        <h1>Right Air CRM Settings</h1>
        <?php echo $api_test_content; ?>
        <p>Configure your Zoho CRM API integration. Make sure these credentials are for a <strong>Zoho CRM Self-Client</strong> or Application.</p>

        <form method="post" action="">
            <?php wp_nonce_field('racrm_settings_action'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="client_id">CRM Client ID</label></th>
                    <td>
                        <input name="client_id" type="text" id="client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text">
                        <p class="description">Obtained from the Zoho API Console.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="client_secret">CRM Client Secret</label></th>
                    <td>
                        <input name="client_secret" type="password" id="client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="refresh_token">CRM Refresh Token</label></th>
                    <td>
                        <input name="refresh_token" type="text" id="refresh_token" value="<?php echo esc_attr($refresh_token); ?>" class="large-text">
                        <p class="description">A permanent refresh token generated via OAuth flow.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="org_id">CRM Organization ID</label></th>
                    <td>
                        <input name="org_id" type="text" id="org_id" value="<?php echo esc_attr($org_id); ?>" class="regular-text">
                        <p class="description">(Optional) For future multi-org support.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="api_domain">CRM API Domain</label></th>
                    <td>
                        <input name="api_domain" type="text" id="api_domain" value="<?php echo esc_url($api_domain); ?>" class="regular-text">
                        <p class="description">Default: <code>https://www.zohoapis.com</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Connection Status</th>
                    <td>
                        <?php if ($is_connected): ?>
                            <span style="color: green; font-weight: bold;">✅ Connected</span>
                            <p class="description">Last Token Refresh: <?php echo $last_refresh ? date('Y-m-d H:i:s', $last_refresh) : 'Never'; ?></p>
                            <p class="description">Token Expires: <?php echo date('Y-m-d H:i:s', $token_expiry); ?></p>
                        <?php else: ?>
                            <span style="color: red; font-weight: bold;">❌ Not Connected</span>
                            <p class="description">No valid access token found or token has expired.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings', 'primary', 'racrm_save_settings'); ?>
        </form>

        <hr>
        <h2>Debug & Tools</h2>
        <p>Log file location: <code><?php echo esc_html(str_replace(ABSPATH, '', RACRM_LOG_FILE)); ?></code></p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=right-air-crm-settings&test_connection=1')); ?>" class="button button-secondary">Test Token Refresh</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=right-air-crm-settings&test_api=1')); ?>" class="button button-secondary">Test CRM Connection</a>
    </div>
    <?php
}
