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

    add_submenu_page(
        'right-air-crm-settings',
        'Create CRM Deal',
        'Create Deal',
        'manage_options',
        'right-air-crm-create-deal',
        'racrm_render_create_deal_page'
    );

    add_submenu_page(
        'right-air-crm-settings',
        'Invoice Linking',
        'Invoice Linking',
        'manage_options',
        'right-air-crm-invoice-linking',
        'racrm_render_invoice_linking_page'
    );
});

/**
 * Render the Invoice Linking admin page.
 *
 * Provides the mandatory webhook secret setting and a manual
 * "Run Queue Now" button for testing without waiting for cron.
 */
function racrm_render_invoice_linking_page() {
    $message = '';

    // Save the webhook secret.
    if (isset($_POST['racrm_save_invoice_settings'])) {
        check_admin_referer('racrm_invoice_settings_action');
        update_option('racrm_books_webhook_secret', sanitize_text_field($_POST['webhook_secret']));
        update_option('racrm_invoice_module', sanitize_text_field($_POST['invoice_module']));
        $message .= '<div class="updated"><p>Invoice linking settings saved.</p></div>';
    }

    // Manually run the queue worker.
    if (isset($_POST['racrm_run_queue_now'])) {
        check_admin_referer('racrm_invoice_settings_action');
        $result = racrm_run_invoice_queue(20);
        $message .= sprintf(
            '<div class="updated"><p><strong>Queue run complete.</strong> Processed: %d &nbsp;|&nbsp; Failed: %d &nbsp;|&nbsp; Pending: %d</p></div>',
            (int) $result['processed'],
            (int) $result['failed'],
            (int) $result['pending']
        );
    }

    // Requeue specific failed records, then immediately retry them.
    //
    // Deliberately ID-scoped rather than "retry everything": most failed rows
    // are invoices with no WooCommerce order at all (Zoho Sales Order
    // references like "SO02276", or an empty reference_number). Those can never
    // link, so a blanket retry would burn 20 pointless CRM lookups on each one.
    if (isset($_POST['racrm_retry_failed'])) {
        check_admin_referer('racrm_invoice_settings_action');

        $raw_ids = isset($_POST['retry_ids']) ? sanitize_text_field($_POST['retry_ids']) : '';
        $ids     = array_filter(array_map('absint', preg_split('/[^0-9]+/', $raw_ids)));

        if (empty($ids)) {
            $message .= '<div class="notice notice-error"><p>Enter at least one queue ID to retry.</p></div>';
        } else {
            $requeued = 0;
            foreach ($ids as $queue_id) {
                $requeued += racrm_invoice_queue_requeue_failed($queue_id);
            }

            if ($requeued > 0) {
                $result = racrm_run_invoice_queue(max(20, $requeued));
                $message .= sprintf(
                    '<div class="updated"><p><strong>Requeued %d record(s).</strong> Processed: %d &nbsp;|&nbsp; Failed: %d &nbsp;|&nbsp; Pending: %d</p></div>',
                    (int) $requeued,
                    (int) $result['processed'],
                    (int) $result['failed'],
                    (int) $result['pending']
                );
            } else {
                $message .= '<div class="notice notice-info"><p>None of those IDs are failed, unprocessed records.</p></div>';
            }
        }
    }

    $webhook_secret = get_option('racrm_books_webhook_secret', '');
    $invoice_module = get_option('racrm_invoice_module', 'CustomModule5001');
    $counts         = function_exists('racrm_invoice_queue_counts') ? racrm_invoice_queue_counts() : ['processed' => 0, 'failed' => 0, 'pending' => 0];
    $webhook_url    = esc_url(rest_url('racrm/v1/books-invoice'));
    ?>
    <div class="wrap">
        <h1>Invoice Linking</h1>
        <?php echo $message; ?>
        <p>Links existing Zoho Books invoices (CRM Invoice records) to existing CRM Deals. This module never creates Accounts, Contacts, Deals or Invoices.</p>

        <h2>Queue Status</h2>
        <p>
            <strong>Processed:</strong> <?php echo (int) $counts['processed']; ?> &nbsp;|&nbsp;
            <strong>Failed:</strong> <?php echo (int) $counts['failed']; ?> &nbsp;|&nbsp;
            <strong>Pending:</strong> <?php echo (int) $counts['pending']; ?>
        </p>

        <form method="post" action="">
            <?php wp_nonce_field('racrm_invoice_settings_action'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="webhook_secret">Webhook Secret</label></th>
                    <td>
                        <input name="webhook_secret" type="text" id="webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="large-text">
                        <p class="description"><strong>Required.</strong> Zoho Books must send this value in the <code>X-RACRM-Token</code> header. Requests without a matching token are rejected.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="invoice_module">CRM Invoice Module</label></th>
                    <td>
                        <input name="invoice_module" type="text" id="invoice_module" value="<?php echo esc_attr($invoice_module); ?>" class="regular-text">
                        <p class="description">API name of the CRM module holding invoice records. Default: <code>CustomModule5001</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Webhook URL</th>
                    <td><code><?php echo $webhook_url; ?></code></td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'racrm_save_invoice_settings', false); ?>
            &nbsp;
            <?php submit_button('Run Queue Now', 'secondary', 'racrm_run_queue_now', false); ?>
        </form>

        <h2>Retry Failed Records</h2>
        <form method="post" action="">
            <?php wp_nonce_field('racrm_invoice_settings_action'); ?>
            <p>
                <label for="retry_ids">Queue IDs</label>
                <input name="retry_ids" type="text" id="retry_ids" value="" class="regular-text" placeholder="e.g. 418, 361">
                <?php submit_button('Retry These Records', 'secondary', 'racrm_retry_failed', false); ?>
            </p>
            <p class="description">
                Resets the given failed records to pending and runs the queue immediately. Use it after
                creating a Deal that was missing when the invoice first arrived.<br>
                Only retry rows whose <code>order_number</code> is a real WooCommerce order. Rows with a
                Zoho Sales Order reference (<code>SO&hellip;</code>) or a blank reference have no
                WooCommerce order to link to and will simply fail again.
            </p>
        </form>
    </div>
    <?php
}

/**
 * Render the manual Deal creation page
 */
function racrm_render_create_deal_page() {
    $message = '';
    $order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : '';

    if (isset($_POST['racrm_manual_create_deal']) && !empty($order_id)) {
        check_admin_referer('racrm_create_deal_action');

        // Refuse to create a second Deal for an order that already has one -
        // re-submitting this form would otherwise duplicate the Deal in Zoho.
        $existing_order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        $existing_deal  = $existing_order ? $existing_order->get_meta('_racrm_deal_id') : '';

        if (!empty($existing_deal)) {
            $message = sprintf(
                '<div class="notice notice-warning"><p><strong>Order %d already has a CRM Deal</strong> (<code>%s</code>). No new Deal was created.</p></div>',
                (int) $order_id,
                esc_html($existing_deal)
            );
            $result = null;
        } else {
            $result = racrm_create_deal_from_order($order_id);
        }

        // $result stays null when the duplicate guard above already reported.
        if (is_wp_error($result)) {
            $message = '<div class="error"><p>❌ ' . esc_html($result->get_error_message()) . '</p></div>';
        } elseif (is_array($result)) {
            $message = sprintf(
                '<div class="updated">
                    <p><strong>✅ CRM Deal Created Successfully</strong></p>
                    <p>Order: %d</p>
                    <p>Deal Name: %s</p>
                    <p>CRM Deal ID: %s</p>
                </div>',
                $order_id,
                esc_html($result['Deal_Name']),
                esc_html($result['id'])
            );
        }
    }
    ?>
    <div class="wrap">
        <h1>Create CRM Deal</h1>
        <?php echo $message; ?>
        <p>Manually create a Zoho CRM Deal from an existing WooCommerce Order.</p>

        <form method="post" action="">
            <?php wp_nonce_field('racrm_create_deal_action'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="order_id">Order Number</label></th>
                    <td>
                        <input name="order_id" type="number" id="order_id" value="<?php echo esc_attr($order_id); ?>" class="regular-text" required placeholder="e.g. 293666">
                        <p class="description">Enter the WooCommerce Order ID.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Create CRM Deal', 'primary', 'racrm_manual_create_deal'); ?>
        </form>
    </div>
    <?php
}

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

    // Log the values read from the database for debugging
    racrm_log("Settings page read: access_token_present=" . (!empty($access_token) ? 'yes' : 'no') . ", token_expiry=" . var_export($token_expiry, true) . ", last_refresh=" . var_export($last_refresh, true));

    // Connection Status check
    $is_connected = !empty($access_token) && intval($token_expiry) > time();

    racrm_log("Settings connection evaluation: is_connected=" . ($is_connected ? 'true' : 'false') . ", token_expiry=" . var_export($token_expiry, true) . ", now=" . time());
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
