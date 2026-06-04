<?php
/**
 * Invoice queue table installer for Right Air CRM.
 *
 * Creates and maintains the {prefix}racrm_invoice_queue table used to
 * buffer incoming Zoho Books invoice webhooks before they are linked
 * to CRM Deals by the cron worker.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fully-qualified queue table name (with WordPress prefix).
 *
 * @return string
 */
function racrm_invoice_queue_table() {
    global $wpdb;
    return $wpdb->prefix . 'racrm_invoice_queue';
}

/**
 * Create or upgrade the invoice queue table.
 *
 * Safe to call repeatedly; dbDelta only applies the diff.
 */
function racrm_install_invoice_queue_table() {
    global $wpdb;

    $table           = racrm_invoice_queue_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_id VARCHAR(50) NOT NULL DEFAULT '',
        invoice_number VARCHAR(50) NOT NULL DEFAULT '',
        order_number VARCHAR(50) NOT NULL DEFAULT '',
        payload_json LONGTEXT NULL,
        processed TINYINT(1) NOT NULL DEFAULT 0,
        failed TINYINT(1) NOT NULL DEFAULT 0,
        attempts INT(11) NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY  (id),
        KEY processed (processed),
        KEY failed (failed),
        KEY invoice_id (invoice_id),
        KEY order_number (order_number)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('racrm_invoice_queue_db_version', RACRM_INVOICE_DB_VERSION);
}

/**
 * Ensure the queue table exists on normal page loads.
 *
 * Guards against installs that activated the plugin before this feature
 * existed (where the activation hook never ran for this table).
 */
function racrm_maybe_install_invoice_queue_table() {
    $installed = get_option('racrm_invoice_queue_db_version');

    if ($installed !== RACRM_INVOICE_DB_VERSION) {
        racrm_install_invoice_queue_table();
    }
}
