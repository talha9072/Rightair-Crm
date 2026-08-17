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
 * Fetch not-yet-linked queue records for a given WooCommerce order number.
 *
 * Includes records that were marked failed, so the manual push tool can pick up
 * an invoice that gave up while its Deal did not exist yet.
 *
 * @param string $order_number
 * @return array Array of row objects, oldest first.
 */
function racrm_invoice_queue_get_by_order_number($order_number) {
    global $wpdb;

    $table = racrm_invoice_queue_table();

    $sql = $wpdb->prepare(
        "SELECT * FROM {$table} WHERE order_number = %s AND processed = 0 ORDER BY id ASC",
        (string) $order_number
    );

    return $wpdb->get_results($sql);
}

/**
 * Reset failed records back to pending so the worker retries them.
 *
 * A record is marked failed once it exhausts RACRM_MAX_INVOICE_ATTEMPTS - most
 * often with "Deal not found", because the Deal had not been created yet. Once
 * the missing Deal exists the invoice can be linked, but the worker only picks
 * up rows where `failed = 0`, so those rows need to be requeued explicitly.
 *
 * Attempts are reset to 0 to give each requeued record a full retry budget.
 *
 * @param int|null $id Specific queue row to requeue, or null for all failed rows.
 * @return int Number of rows requeued.
 */
function racrm_invoice_queue_requeue_failed($id = null) {
    global $wpdb;

    $table = racrm_invoice_queue_table();
    $now   = current_time('mysql');

    if ($id !== null) {
        $sql = $wpdb->prepare(
            "UPDATE {$table} SET failed = 0, attempts = 0, last_error = '', updated_at = %s
             WHERE id = %d AND failed = 1 AND processed = 0",
            $now,
            (int) $id
        );
    } else {
        $sql = $wpdb->prepare(
            "UPDATE {$table} SET failed = 0, attempts = 0, last_error = '', updated_at = %s
             WHERE failed = 1 AND processed = 0",
            $now
        );
    }

    $requeued = (int) $wpdb->query($sql);

    if ($requeued > 0) {
        racrm_log("[Invoice Queue] Requeued {$requeued} failed record(s) for retry.");
    }

    return $requeued;
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
