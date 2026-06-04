<?php
/**
 * Data-access helpers for the invoice queue table.
 *
 * Thin, single-purpose wrappers around $wpdb so the webhook and cron
 * worker never deal with raw SQL.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Insert a new queue record for an incoming Books invoice.
 *
 * @param array $data {
 *     @type string $invoice_id     Zoho Books / CRM invoice id.
 *     @type string $invoice_number Human readable invoice number.
 *     @type string $order_number   Extracted WooCommerce order number.
 *     @type string $payload_json   Raw webhook payload as JSON.
 * }
 * @return int|false Inserted row id, or false on failure.
 */
function racrm_invoice_queue_insert($data) {
    global $wpdb;

    $now = current_time('mysql');

    $result = $wpdb->insert(
        racrm_invoice_queue_table(),
        [
            'invoice_id'     => (string) ($data['invoice_id'] ?? ''),
            'invoice_number' => (string) ($data['invoice_number'] ?? ''),
            'order_number'   => (string) ($data['order_number'] ?? ''),
            'payload_json'   => (string) ($data['payload_json'] ?? ''),
            'processed'      => 0,
            'attempts'       => 0,
            'created_at'     => $now,
            'updated_at'     => $now,
        ],
        ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
    );

    if ($result === false) {
        return false;
    }

    return (int) $wpdb->insert_id;
}

/**
 * Fetch unprocessed queue records, oldest first.
 *
 * @param int $limit Maximum number of records to return.
 * @return array Array of row objects.
 */
function racrm_invoice_queue_get_pending($limit = 20) {
    global $wpdb;

    $table = racrm_invoice_queue_table();
    $limit = max(1, (int) $limit);

    $sql = $wpdb->prepare(
        "SELECT * FROM {$table} WHERE processed = 0 AND failed = 0 ORDER BY id ASC LIMIT %d",
        $limit
    );

    return $wpdb->get_results($sql);
}

/**
 * Mark a queue record as successfully processed.
 *
 * @param int $id Queue row id.
 * @return void
 */
function racrm_invoice_queue_mark_processed($id) {
    global $wpdb;

    $wpdb->update(
        racrm_invoice_queue_table(),
        [
            'processed'  => 1,
            'updated_at' => current_time('mysql'),
        ],
        ['id' => (int) $id],
        ['%d', '%s'],
        ['%d']
    );
}

/**
 * Increment the attempt counter for a queue record (retry later).
 *
 * @param int    $id      Queue row id.
 * @param int    $current Current attempt count.
 * @param string $error   Reason this attempt did not complete.
 * @return void
 */
function racrm_invoice_queue_increment_attempts($id, $current, $error = '') {
    global $wpdb;

    $wpdb->update(
        racrm_invoice_queue_table(),
        [
            'attempts'   => (int) $current + 1,
            'last_error' => (string) $error,
            'updated_at' => current_time('mysql'),
        ],
        ['id' => (int) $id],
        ['%d', '%s', '%s'],
        ['%d']
    );
}

/**
 * Mark a queue record as permanently failed.
 *
 * @param int    $id    Queue row id.
 * @param string $error Reason for the failure.
 * @return void
 */
function racrm_invoice_queue_mark_failed($id, $error = '') {
    global $wpdb;

    $wpdb->update(
        racrm_invoice_queue_table(),
        [
            'failed'     => 1,
            'last_error' => (string) $error,
            'updated_at' => current_time('mysql'),
        ],
        ['id' => (int) $id],
        ['%d', '%s', '%s'],
        ['%d']
    );
}

/**
 * Return queue counts grouped by state.
 *
 * @return array { processed, failed, pending }
 */
function racrm_invoice_queue_counts() {
    global $wpdb;

    $table = racrm_invoice_queue_table();

    return [
        'processed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE processed = 1"),
        'failed'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE failed = 1"),
        'pending'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE processed = 0 AND failed = 0"),
    ];
}

/**
 * Delete processed and failed records older than the given age.
 *
 * @param int $days Maximum age in days.
 * @return int Number of rows deleted.
 */
function racrm_invoice_queue_cleanup($days = 30) {
    global $wpdb;

    $table  = racrm_invoice_queue_table();
    $days   = max(1, (int) $days);
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    $sql = $wpdb->prepare(
        "DELETE FROM {$table} WHERE (processed = 1 OR failed = 1) AND updated_at < %s",
        $cutoff
    );

    return (int) $wpdb->query($sql);
}
