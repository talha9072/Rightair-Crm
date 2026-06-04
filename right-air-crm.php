<?php
/**
 * Plugin Name:       Right Air CRM
 * Plugin URI:        https://rightair.co.za
 * Description:       Standalone Zoho CRM integration for Right Air. Provides authentication, API helpers, and logging.
 * Version:           1.0.0
 * Author:            Right Air
 * Author URI:        https://rightair.co.za
 * Text Domain:       right-air-crm
 * Domain Path:       /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('RACRM_VERSION', '1.0.0');
define('RACRM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RACRM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RACRM_LOG_FILE', RACRM_PLUGIN_DIR . 'logs/crm-debug.txt');

// Include required files
require_once RACRM_PLUGIN_DIR . 'includes/logger.php';
require_once RACRM_PLUGIN_DIR . 'includes/crm-auth.php';
require_once RACRM_PLUGIN_DIR . 'includes/crm-api.php';
require_once RACRM_PLUGIN_DIR . 'includes/settings.php';
require_once RACRM_PLUGIN_DIR . 'includes/crm-deals.php';
require_once RACRM_PLUGIN_DIR . 'includes/crm-contacts.php';
require_once RACRM_PLUGIN_DIR . 'includes/crm-accounts.php';

/**
 * Show admin warnings if credentials are missing
 */
add_action('admin_notices', function() {
    // Only show to admins
    if (!current_user_can('manage_options')) {
        return;
    }

    $client_id = get_option('racrm_client_id');
    $client_secret = get_option('racrm_client_secret');
    $refresh_token = get_option('racrm_refresh_token');

    if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e('<strong>Right Air CRM:</strong> Please configure your Zoho CRM credentials in the settings page to enable integration.', 'right-air-crm'); ?></p>
        </div>
        <?php
    }
});
