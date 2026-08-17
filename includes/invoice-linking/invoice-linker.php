<?php
/**
 * Core invoice-linking logic.
 *
 * Finds an existing CRM Deal (by WooCommerce order number) and an existing
 * CRM Invoice record (by Invoice_ID in the invoice custom module), then
 * links them by setting the Invoice's Potential_Name lookup to the Deal.
 *
 * This file NEVER creates Accounts, Contacts, Deals or Invoices.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attempt to link a single queued invoice to its CRM Deal.
 *
 * @param object $record Queue row.
 * @return array {
 *     @type bool   $success   True if the record can be marked processed.
 *     @type bool   $retryable True if the record should be retried later.
 *     @type string $error     Human readable reason when not successful.
 * }
 */
function racrm_link_invoice_record($record) {
    $order_number = (string) $record->order_number;
    $invoice_id   = (string) $record->invoice_id;

    racrm_log("[Invoice Queue] Processing invoice_id={$invoice_id}, order_number={$order_number} (queue #{$record->id})");

    if ($invoice_id === '') {
        racrm_log("[Invoice Queue] Failed: missing invoice_id (queue #{$record->id}). Not retryable.");
        // Nothing we can do with this record; do not retry.
        return ['success' => false, 'retryable' => false, 'error' => 'Missing invoice_id'];
    }

    // No WooCommerce order number means the invoice was raised against
    // something other than a Woo order (a Zoho Sales Order, or no reference at
    // all). There is no Deal to find, so retrying can never help.
    if ($order_number === '') {
        racrm_log("[Invoice Queue] Skipped: no WooCommerce order in invoice reference (queue #{$record->id}). Not retryable.");
        return ['success' => false, 'retryable' => false, 'error' => 'Not a WooCommerce order invoice'];
    }

    $deal_id = racrm_find_deal_id_by_order_number($order_number);
    if (!$deal_id) {
        racrm_log("[Invoice Queue] Failed: Deal not found for order #{$order_number} (queue #{$record->id}). Will retry.");
        return ['success' => false, 'retryable' => true, 'error' => 'Deal not found'];
    }

    racrm_log("[Invoice Queue] Deal found: {$deal_id} (order #{$order_number}, queue #{$record->id})");

    $invoice_record_id = racrm_find_invoice_record_id($invoice_id);
    if (!$invoice_record_id) {
        racrm_log("[Invoice Queue] Failed: Invoice not found for Invoice_ID {$invoice_id} (queue #{$record->id}). Will retry.");
        return ['success' => false, 'retryable' => true, 'error' => 'Invoice not found'];
    }

    racrm_log("[Invoice Queue] Invoice found: {$invoice_record_id} (Invoice_ID {$invoice_id}, queue #{$record->id})");

    if (racrm_attach_invoice_to_deal($invoice_record_id, $deal_id, $record->id)) {
        racrm_log("[Invoice Queue] Invoice linked successfully: Invoice {$invoice_record_id} -> Deal {$deal_id} (queue #{$record->id})");
        return ['success' => true, 'retryable' => false, 'error' => ''];
    }

    racrm_log("[Invoice Queue] Failed: Invoice link update failed for Invoice {$invoice_record_id} -> Deal {$deal_id} (queue #{$record->id}). Will retry.");
    return ['success' => false, 'retryable' => true, 'error' => 'Invoice link update failed'];
}

/**
 * Find a CRM Deal id from a WooCommerce order number.
 *
 * The WooCommerce → CRM integration stores the order number inside the
 * Deal name as "Order | WC Order #{order_number}". We search Deal_Name by
 * default; the criteria is filterable so a differently-named integration
 * can override it without code changes.
 *
 * @param string $order_number
 * @return string|false Deal record id, or false if not found.
 */
function racrm_find_deal_id_by_order_number($order_number) {
    $default_criteria = '(Deal_Name:equals:Order | WC Order #' . $order_number . ')';

    /**
     * Filter the Zoho CRM search criteria used to locate the Deal.
     *
     * @param string $default_criteria
     * @param string $order_number
     */
    $criteria = apply_filters('racrm_deal_order_search_criteria', $default_criteria, $order_number);

    $endpoint = '/Deals/search?criteria=' . rawurlencode($criteria);
    $response = racrm_api_get($endpoint);

    if (!empty($response['data'][0]['id'])) {
        $deal_id = $response['data'][0]['id'];
        racrm_log("✅ Deal found for order #{$order_number}: {$deal_id}");
        return $deal_id;
    }

    return false;
}

/**
 * Find a CRM Invoice record id by its Invoice_ID field.
 *
 * @param string $invoice_id Zoho Books / CRM invoice id value.
 * @return string|false Invoice record id, or false if not found.
 */
function racrm_find_invoice_record_id($invoice_id) {
    $module   = racrm_invoice_module_name();
    $criteria = '(' . $module . '_External_Id__s:equals:' . $invoice_id . ')';

    $endpoint = '/' . $module . '/search?criteria=' . rawurlencode($criteria);
    $response = racrm_api_get($endpoint);

    if (!empty($response['data'][0]['id'])) {
        $record_id = $response['data'][0]['id'];
        racrm_log("[Invoice Queue] Invoice found: {$record_id}");
        return $record_id;
    }

    return false;
}

/**
 * Link an existing CRM Invoice record to an existing CRM Deal.
 *
 * @param string $invoice_record_id CRM Invoice record id (in invoice module).
 * @param string $deal_id           CRM Deal id.
 * @param int    $queue_id          Queue row id (for logging context).
 * @return bool True on success.
 */
function racrm_attach_invoice_to_deal($invoice_record_id, $deal_id, $queue_id) {
    $module = racrm_invoice_module_name();

    racrm_log("[Invoice Queue] Linking invoice {$invoice_record_id} to deal {$deal_id}");

    $payload = [
        'data' => [
            [
                'id'             => $invoice_record_id,
                'Potential_Name' => ['id' => $deal_id],
            ],
        ],
    ];

    $response = racrm_api_put('/' . $module, $payload);

    if (!empty($response['data'][0]['code']) && $response['data'][0]['code'] === 'SUCCESS') {
        racrm_log("[Invoice Queue] Invoice linked successfully");
        racrm_log("🔗 Linked CRM Invoice {$invoice_record_id} to Deal {$deal_id} (queue #{$queue_id}).");
        return true;
    }

    racrm_log("❌ Failed to link CRM Invoice {$invoice_record_id} to Deal {$deal_id} (queue #{$queue_id}): " . wp_json_encode($response));
    return false;
}
