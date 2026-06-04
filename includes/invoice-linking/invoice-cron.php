<?php
/**
 * Cron worker for the invoice queue.
 *
 * Runs every 5 minutes, processing unprocessed queue records by attempting
 * to link each Books invoice to its CRM Deal. Records whose Deal is not yet
 * available are left unprocessed (attempts incremented) so a later run can
 * retry them.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register a custom 5-minute cron schedule.
 *
 * @param array $schedules
 * @return array
 */
function racrm_add_cron_schedule($schedules) {
    if (!isset($schedules['racrm_five_minutes'])) {
        $schedules['racrm_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every 5 Minutes (Right Air CRM)', 'right-air-crm'),
        ];
    }
    return $schedules;
}
add_filter('cron_schedules', 'racrm_add_cron_schedule');

/**
 * Ensure the recurring events are scheduled.
 */
function racrm_schedule_invoice_cron() {
    if (!wp_next_scheduled('racrm_process_invoice_queue')) {
        wp_schedule_event(time(), 'racrm_five_minutes', 'racrm_process_invoice_queue');
    }

    if (!wp_next_scheduled('racrm_cleanup_invoice_queue')) {
        wp_schedule_event(time(), 'daily', 'racrm_cleanup_invoice_queue');
    }
}
add_action('init', 'racrm_schedule_invoice_cron');

/**
 * Clear the recurring events (used on plugin deactivation).
 */
function racrm_unschedule_invoice_cron() {
    $timestamp = wp_next_scheduled('racrm_process_invoice_queue');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'racrm_process_invoice_queue');
    }

    $cleanup = wp_next_scheduled('racrm_cleanup_invoice_queue');
    if ($cleanup) {
        wp_unschedule_event($cleanup, 'racrm_cleanup_invoice_queue');
    }
}

/**
 * Cron callback: process pending invoice queue records.
 */
function racrm_process_invoice_queue() {
    racrm_run_invoice_queue();
}
add_action('racrm_process_invoice_queue', 'racrm_process_invoice_queue');

/**
 * Process pending queue records and return a result summary.
 *
 * Shared by the cron callback and the manual "Run Queue Now" admin action.
 *
 * @param int $limit Maximum records to process in this run.
 * @return array { processed, failed, pending }
 */
function racrm_run_invoice_queue($limit = 20) {
    $records = racrm_invoice_queue_get_pending($limit);

    $processed = 0;
    $failed    = 0;

    if (!empty($records)) {
        racrm_log('[Invoice Queue] Worker started: ' . count($records) . ' pending record(s).');

        foreach ($records as $record) {
            $result = racrm_link_invoice_record($record);

            if (!empty($result['success'])) {
                racrm_invoice_queue_mark_processed($record->id);
                $processed++;
                continue;
            }

            // Non-retryable failure: mark failed immediately.
            if (empty($result['retryable'])) {
                racrm_invoice_queue_mark_failed($record->id, $result['error']);
                $failed++;
                continue;
            }

            // Retryable failure: record the attempt.
            racrm_invoice_queue_increment_attempts($record->id, $record->attempts, $result['error']);

            // Give up after the maximum number of attempts.
            if (((int) $record->attempts + 1) >= RACRM_MAX_INVOICE_ATTEMPTS) {
                racrm_invoice_queue_mark_failed($record->id, $result['error']);
                racrm_log("[Invoice Queue] Giving up on queue #{$record->id} after " . RACRM_MAX_INVOICE_ATTEMPTS . " attempts: {$result['error']}");
                $failed++;
            }
        }

        racrm_log('[Invoice Queue] Worker finished.');
    }

    $counts = racrm_invoice_queue_counts();

    return [
        'processed' => $processed,
        'failed'    => $failed,
        'pending'   => $counts['pending'],
    ];
}

/**
 * Cron callback: delete old processed/failed records.
 */
function racrm_cleanup_invoice_queue() {
    $deleted = racrm_invoice_queue_cleanup(RACRM_INVOICE_RETENTION_DAYS);
    if ($deleted > 0) {
        racrm_log("[Invoice Queue] Cleanup removed {$deleted} record(s) older than " . RACRM_INVOICE_RETENTION_DAYS . ' days.');
    }
}
add_action('racrm_cleanup_invoice_queue', 'racrm_cleanup_invoice_queue');
