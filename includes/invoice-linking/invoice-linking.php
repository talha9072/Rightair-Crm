<?php
/**
 * Invoice-linking feature loader for Right Air CRM.
 *
 * Lightweight system that attaches existing Zoho CRM Invoice records
 * (created by Zoho Books) to existing CRM Deals (created by the WooCommerce
 * → Zoho CRM integration). It does not create any CRM records itself.
 *
 * Flow:
 *   1. Zoho Books workflow POSTs an invoice to /wp-json/racrm/v1/books-invoice
 *   2. The webhook stores it in the wp_racrm_invoice_queue table
 *   3. A 5-minute cron worker links each invoice to its Deal
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Feature constants.
if (!defined('RACRM_INVOICE_DB_VERSION')) {
    define('RACRM_INVOICE_DB_VERSION', '1.1.0');
}

// Maximum number of link attempts before a record is marked failed.
if (!defined('RACRM_MAX_INVOICE_ATTEMPTS')) {
    define('RACRM_MAX_INVOICE_ATTEMPTS', 20);
}

// Age (in days) after which processed/failed records are purged.
if (!defined('RACRM_INVOICE_RETENTION_DAYS')) {
    define('RACRM_INVOICE_RETENTION_DAYS', 30);
}

/**
 * CRM custom module API name that holds invoice records.
 *
 * Defaults to CustomModule5001 (Zoho Books-created CRM invoices) and is
 * overridable via the `racrm_invoice_module` option or filter.
 *
 * @return string
 */
function racrm_invoice_module_name() {
    $module = get_option('racrm_invoice_module', 'CustomModule5001');
    return apply_filters('racrm_invoice_module_name', $module);
}

$racrm_invoice_dir = __DIR__ . '/';

require_once $racrm_invoice_dir . 'invoice-queue-table.php';
require_once $racrm_invoice_dir . 'invoice-repository.php';
require_once $racrm_invoice_dir . 'invoice-linker.php';
require_once $racrm_invoice_dir . 'invoice-webhook.php';
require_once $racrm_invoice_dir . 'invoice-cron.php';

// Make sure the queue table exists even on already-active installs.
add_action('init', 'racrm_maybe_install_invoice_queue_table');
