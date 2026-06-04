<?php
/**
 * Automatic Order Sync for Right Air CRM
 *
 * Adds automatic CRM syncing for genuine WooCommerce customer orders.
 *
 * This module ONLY orchestrates and guards. The actual CRM record creation
 * (Account / Contact / Deal lookup and creation) is delegated to the existing
 * racrm_create_deal_from_order() in crm-deals.php, so the manual "Create Deal"
 * admin flow and the automatic flow share identical CRM logic.
 *
 * Quotes (YITH Request a Quote) and draft orders are explicitly excluded so
 * they never create CRM Deals automatically.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order statuses that represent a YITH "Request a Quote" record.
 *
 * These are never treated as real customer orders.
 *
 * @return array
 */
function racrm_quote_order_statuses() {
    return [
        'ywraq-new',
        'ywraq-pending',
        'ywraq-accepted',
        'ywraq-rejected',
        'ywraq-expired',
    ];
}

/**
 * Determine whether an order is a quote (YITH Request a Quote) rather than a
 * real customer order.
 *
 * Detection is based on the site's actual quote plugin (YITH Request a Quote
 * Premium), which marks quote orders with the `ywraq_raq` = 'yes' meta and
 * places them in a dedicated `ywraq-*` order status.
 *
 * @param WC_Order $order
 * @return bool True if the order is a quote.
 */
function racrm_order_is_quote($order) {
    // Primary identifier: YITH stamps this meta on every quote record.
    if ($order->get_meta('ywraq_raq') === 'yes') {
        return true;
    }

    // Secondary guard: the order sits in a quote-specific status.
    if (in_array($order->get_status(), racrm_quote_order_statuses(), true)) {
        return true;
    }

    return false;
}

/**
 * Adapter for the classic checkout hook.
 *
 * Signature: woocommerce_checkout_order_processed( $order_id, $posted_data, $order )
 *
 * @param int $order_id
 */
function racrm_auto_sync_on_checkout($order_id) {
    racrm_maybe_auto_sync_order($order_id, 'woocommerce_checkout_order_processed');
}
add_action('woocommerce_checkout_order_processed', 'racrm_auto_sync_on_checkout', 20, 1);

/**
 * Adapter for the Block / Store API checkout hook.
 *
 * Signature: woocommerce_store_api_checkout_order_processed( $order )
 *
 * @param WC_Order $order
 */
function racrm_auto_sync_on_store_api_checkout($order) {
    if ($order instanceof WC_Order) {
        racrm_maybe_auto_sync_order($order->get_id(), 'woocommerce_store_api_checkout_order_processed');
    }
}
add_action('woocommerce_store_api_checkout_order_processed', 'racrm_auto_sync_on_store_api_checkout', 20, 1);

/**
 * Guarded entry point for automatic CRM sync.
 *
 * Runs all safety checks (WooCommerce active, order valid, quote detection,
 * duplicate protection) and only then delegates to the shared CRM creation
 * routine. The manual admin flow is unaffected by this function.
 *
 * @param int    $order_id
 * @param string $hook      Name of the WooCommerce hook that triggered the sync.
 * @return void
 */
function racrm_maybe_auto_sync_order($order_id, $hook) {
    // Prevent the same order being processed twice within a single request
    // (e.g. if more than one trigger hook fires).
    static $processed = [];
    if (isset($processed[$order_id])) {
        return;
    }
    $processed[$order_id] = true;

    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        racrm_log("[CRM] Auto sync aborted: Order #{$order_id} not found.");
        return;
    }

    $is_quote = racrm_order_is_quote($order);
    $existing_deal_id = $order->get_meta('_racrm_deal_id');

    racrm_log("[CRM] ==================================================");
    racrm_log("[CRM] Automatic CRM Sync");
    racrm_log("[CRM] Trigger Hook: {$hook}");
    racrm_log("[CRM] Order ID: {$order_id}");
    racrm_log("[CRM] Order Status: " . $order->get_status());
    racrm_log("[CRM] Quote Detected: " . ($is_quote ? 'YES' : 'NO'));

    // 1. Quotes must never create CRM Deals automatically.
    if ($is_quote) {
        racrm_log("[CRM] CRM Sync Allowed: NO");
        racrm_log("[CRM] Reason: Order is a quote (YITH Request a Quote).");
        racrm_log("[CRM] ==================================================");
        return;
    }

    // 2. Duplicate protection: never create a second Deal for the same order.
    if (!empty($existing_deal_id)) {
        racrm_log("[CRM] CRM Sync Allowed: NO");
        racrm_log("[CRM] Existing Deal ID: {$existing_deal_id}");
        racrm_log("[CRM] Deal already exists for Order #{$order_id}");
        racrm_log("[CRM] ==================================================");
        return;
    }

    racrm_log("[CRM] Existing Deal ID: none");
    racrm_log("[CRM] CRM Sync Allowed: YES");
    racrm_log("[CRM] ==================================================");

    // 3. Delegate to the shared CRM logic (Contact search/reuse, Account
    //    reuse/creation, Contact creation, Deal creation, save Deal ID).
    $result = racrm_create_deal_from_order($order_id);

    if (is_wp_error($result)) {
        racrm_log("[CRM] Deal Created: NO");
        racrm_log("[CRM] Reason: " . $result->get_error_message() . " (Order #{$order_id})");
    } else {
        racrm_log("[CRM] Deal Created: YES");
        racrm_log("[CRM] Deal ID: " . (isset($result['id']) ? $result['id'] : 'unknown') . " (Order #{$order_id})");
    }
}
