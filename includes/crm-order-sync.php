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
 * Statuses at which a CONVERTED QUOTE is allowed to create a CRM Deal.
 *
 * A quote-origin order only syncs once it reaches one of these statuses, so a
 * quote the customer accepts but never actually pays for never becomes a Deal.
 *
 * Note this gate applies to quote-origin orders ONLY - ordinary WooCommerce
 * orders continue to sync at checkout regardless of status, as they always have.
 *
 * `on-hold` is deliberately excluded: it is where WooCommerce parks EFT/BACS
 * orders that are awaiting payment, which is not yet a confirmed sale. Such an
 * order syncs automatically as soon as it moves to processing or completed, and
 * can be pushed sooner with the manual "Create Deal" screen if needed.
 *
 * @return array
 */
function racrm_syncable_order_statuses() {
    return [
        'processing',
        'completed',
    ];
}

/**
 * Determine whether an order ORIGINATED as a YITH quote request.
 *
 * YITH stamps `ywraq_raq` = 'yes' when the quote record is created and never
 * removes it, so this stays true for the whole life of the order - including
 * long after the quote has been converted into a real order. It therefore
 * describes the order's origin and must never be used on its own to block
 * CRM syncing.
 *
 * @param WC_Order $order
 * @return bool True if the order started life as a quote request.
 */
function racrm_order_originated_as_quote($order) {
    return $order->get_meta('ywraq_raq') === 'yes';
}

/**
 * Determine whether an order is CURRENTLY an open quote rather than a real
 * customer order.
 *
 * Detection is based on order status alone. YITH Request a Quote Premium always
 * places live quote records in a dedicated `wc-ywraq-*` status (`ywraq-new` on
 * creation, then `ywraq-pending` / `ywraq-accepted` / `ywraq-rejected` /
 * `ywraq-expired`) and moves the order into a normal WooCommerce status the
 * moment the quote is converted into an order.
 *
 * The `ywraq_raq` meta is deliberately NOT used here. It is a permanent origin
 * marker that YITH never deletes, so treating it as "is a quote" made every
 * quote-to-order conversion permanently invisible to the CRM. Use
 * racrm_order_originated_as_quote() when the origin is what matters.
 *
 * @param WC_Order $order
 * @return bool True if the order is still an open quote.
 */
function racrm_order_is_quote($order) {
    return in_array($order->get_status(), racrm_quote_order_statuses(), true);
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
 * Safety net for quote-to-order conversions that never reach a checkout hook.
 *
 * When a customer accepts a quote, YITH can convert the SAME order record
 * instead of sending it through the normal checkout flow (the "pay for order"
 * route moves the quote straight from `ywraq-accepted` to `pending`, and an
 * admin can bump the status by hand). Neither path fires
 * `woocommerce_checkout_order_processed`, so without this hook such an order
 * would never be offered to the CRM at all.
 *
 * To avoid creating Deals for quotes that are accepted but never paid, this
 * only syncs once the converted order reaches a real fulfilment status
 * (see racrm_syncable_order_statuses()). Orders that DID go through checkout
 * are already synced by the hooks above and are skipped here by the existing
 * `_racrm_deal_id` duplicate guard.
 *
 * Signature: woocommerce_order_status_changed( $order_id, $from, $to, $order )
 *
 * @param int      $order_id
 * @param string   $from     Previous status (no `wc-` prefix).
 * @param string   $to       New status (no `wc-` prefix).
 * @param WC_Order $order
 * @return void
 */
function racrm_auto_sync_on_quote_conversion($order_id, $from, $to, $order = null) {
    if (!$order instanceof WC_Order) {
        return;
    }

    // Only interested in orders that began life as a quote request. Ordinary
    // orders are handled by the checkout hooks and must not be re-synced here.
    if (!racrm_order_originated_as_quote($order)) {
        return;
    }

    // Wait until the converted quote is actually a live order.
    if (!in_array($to, racrm_syncable_order_statuses(), true)) {
        return;
    }

    // Already synced (e.g. it went through checkout) - nothing to do, and no
    // need to log a full sync block for every later status change.
    if (!empty($order->get_meta('_racrm_deal_id'))) {
        return;
    }

    racrm_log("[CRM] Converted quote detected: Order #{$order_id} moved {$from} -> {$to}");

    racrm_maybe_auto_sync_order($order_id, "woocommerce_order_status_changed ({$from} -> {$to})");
}
add_action('woocommerce_order_status_changed', 'racrm_auto_sync_on_quote_conversion', 20, 4);

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
    // Prevent the same order being synced twice within a single request (e.g.
    // if more than one trigger hook fires).
    //
    // The flag is set only once an order actually passes every gate and is
    // handed to the CRM - NOT on early return. Otherwise a request that bails
    // early (say, checkout creates the order as pending, then the gateway
    // immediately moves it to processing) would have its later, valid sync
    // silently suppressed.
    static $processed = [];
    if (isset($processed[$order_id])) {
        return;
    }

    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        racrm_log("[CRM] Auto sync aborted: Order #{$order_id} not found.");
        return;
    }

    $is_quote = racrm_order_is_quote($order);
    $from_quote = racrm_order_originated_as_quote($order);
    $existing_deal_id = $order->get_meta('_racrm_deal_id');

    racrm_log("[CRM] ==================================================");
    racrm_log("[CRM] Automatic CRM Sync");
    racrm_log("[CRM] Trigger Hook: {$hook}");
    racrm_log("[CRM] Order ID: {$order_id}");
    racrm_log("[CRM] Order Status: " . $order->get_status());
    racrm_log("[CRM] Open Quote: " . ($is_quote ? 'YES' : 'NO'));
    racrm_log("[CRM] Originated As Quote: " . ($from_quote ? 'YES' : 'NO'));

    // 1. Open (unconverted) quotes must never create CRM Deals automatically.
    //    Orders that merely ORIGINATED as a quote and have since been converted
    //    are real orders and must sync normally.
    if ($is_quote) {
        racrm_log("[CRM] CRM Sync Allowed: NO");
        racrm_log("[CRM] Reason: Order is still an open quote (YITH Request a Quote).");
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

    // 3. A converted quote only becomes a Deal once it is a confirmed sale.
    //    This applies on every path (checkout and status change) so the rule
    //    cannot be bypassed by whichever hook happens to fire first. Ordinary
    //    orders are not gated here and keep their existing behaviour.
    if ($from_quote && !in_array($order->get_status(), racrm_syncable_order_statuses(), true)) {
        racrm_log("[CRM] CRM Sync Allowed: NO");
        racrm_log("[CRM] Reason: Converted quote is not yet a confirmed sale (status: " . $order->get_status() . ").");
        racrm_log("[CRM] Waiting for: " . implode(' or ', racrm_syncable_order_statuses()));
        racrm_log("[CRM] ==================================================");
        return;
    }

    racrm_log("[CRM] Existing Deal ID: none");
    racrm_log("[CRM] CRM Sync Allowed: YES");
    racrm_log("[CRM] ==================================================");

    // Claim this order now that it has passed every gate, so a second hook in
    // the same request cannot create a duplicate Deal.
    $processed[$order_id] = true;

    // 4. Delegate to the shared CRM logic (Contact search/reuse, Account
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
