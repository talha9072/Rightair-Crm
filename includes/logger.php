<?php
/**
 * Debug Logger for Right Air CRM
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log a message to the CRM debug log file
 *
 * @param mixed $message The message or data to log.
 */
function racrm_log($message) {
    if (is_array($message) || is_object($message)) {
        $message = print_r($message, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] {$message}" . PHP_EOL;

    // Ensure logs directory exists
    $log_dir = dirname(RACRM_LOG_FILE);
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    // Append to file
    error_log($formatted_message, 3, RACRM_LOG_FILE);
}
