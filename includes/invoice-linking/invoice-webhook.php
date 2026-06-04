<?php
/**
 * REST webhook endpoint for Zoho Books invoices.
 *
 * Receives the Zoho Books workflow webhook, parses the relevant fields,
 * extracts the WooCommerce order number and queues the record for the
 * cron worker. It deliberately does NOT perform any CRM linking here so
 * that a Books invoice arriving before the CRM Deal has finished being
 * created does not fail permanently.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the REST route.
 */
function racrm_register_invoice_webhook() {
    register_rest_route('racrm/v1', '/books-invoice', [
        'methods'             => 'POST',
        'callback'            => 'racrm_handle_books_invoice_webhook',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'racrm_register_invoice_webhook');

/**
 * Validate the mandatory shared secret on the incoming request.
 *
 * The request must supply the configured secret (option
 * `racrm_books_webhook_secret`) via the `X-RACRM-Token` header. If no secret
 * is configured, or the token does not match, the request is rejected.
 *
 * @param WP_REST_Request $request
 * @return bool
 */
function racrm_books_invoice_webhook_authorized($request) {
    $secret = (string) get_option('racrm_books_webhook_secret', '');

    if ($secret === '') {
        racrm_log('[Invoice Queue] Webhook rejected: no webhook secret configured.');
        return false;
    }

    $provided = $request->get_header('x_racrm_token');

    if (is_string($provided) && hash_equals($secret, $provided)) {
        return true;
    }

    racrm_log('[Invoice Queue] Webhook rejected: invalid or missing token.');
    return false;
}

/**
 * Handle the webhook payload.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function racrm_handle_books_invoice_webhook($request) {
    racrm_log('[Invoice Queue] Webhook received');

    if (!racrm_books_invoice_webhook_authorized($request)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid webhook token',
        ], 403);
    }

    $payload = $request->get_json_params();

    if (empty($payload) || !is_array($payload)) {
        // Fall back to form-encoded params (Zoho can post either).
        $payload = $request->get_params();
    }

    $parsed = racrm_parse_books_invoice_payload($payload);

    if (empty($parsed['invoice_id'])) {
        racrm_log('[Invoice Queue] Webhook missing invoice_id in payload: ' . wp_json_encode($payload));
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing invoice_id.',
        ], 400);
    }

    $queue_id = racrm_invoice_queue_insert([
        'invoice_id'     => $parsed['invoice_id'],
        'invoice_number' => $parsed['invoice_number'],
        'order_number'   => $parsed['order_number'],
        'payload_json'   => wp_json_encode($payload),
    ]);

    if (!$queue_id) {
        racrm_log('[Invoice Queue] Failed to enqueue invoice ' . $parsed['invoice_id']);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to enqueue invoice.',
        ], 500);
    }

    racrm_log(sprintf(
        '[Invoice Queue] Invoice queued (#%d): invoice_id=%s, invoice_number=%s, order_number=%s',
        $queue_id,
        $parsed['invoice_id'],
        $parsed['invoice_number'],
        $parsed['order_number']
    ));

    return new WP_REST_Response([
        'success'  => true,
        'queue_id' => $queue_id,
    ], 200);
}

/**
 * Extract the fields we care about from a Books webhook payload.
 *
 * Zoho Books sends the invoice fields nested under an "invoice" key. We read
 * from that wrapper when present, and fall back to the top level so flat
 * payloads (or custom webhook templates) remain supported.
 *
 * @param array $payload
 * @return array { invoice_id, invoice_number, order_number }
 */
function racrm_parse_books_invoice_payload($payload) {
    // Prefer the nested "invoice" object Zoho Books actually sends.
    $source = $payload;
    if (isset($payload['invoice']) && is_array($payload['invoice'])) {
        $source = $payload['invoice'];
    }

    $invoice_id     = racrm_payload_first($source, ['invoice_id', 'Invoice_ID', 'invoiceid']);
    $invoice_number = racrm_payload_first($source, ['invoice_number', 'Invoice_Number', 'invoicenumber', 'number']);
    $reference      = racrm_payload_first($source, ['reference_number', 'Reference_Number', 'referencenumber', 'reference']);

    $invoice_id     = trim((string) $invoice_id);
    $invoice_number = trim((string) $invoice_number);
    $reference      = trim((string) $reference);
    $order_number   = racrm_extract_order_number($reference);

    racrm_log("[Invoice Queue] Parsed invoice_id={$invoice_id}");
    racrm_log("[Invoice Queue] Parsed invoice_number={$invoice_number}");
    racrm_log("[Invoice Queue] Parsed reference_number={$reference}");
    racrm_log("[Invoice Queue] Parsed order_number={$order_number}");

    return [
        'invoice_id'     => $invoice_id,
        'invoice_number' => $invoice_number,
        'order_number'   => $order_number,
    ];
}

/**
 * Return the first non-empty value found among the given keys.
 *
 * @param array $payload
 * @param array $keys
 * @return string
 */
function racrm_payload_first($payload, $keys) {
    foreach ($keys as $key) {
        if (isset($payload[$key]) && $payload[$key] !== '') {
            return $payload[$key];
        }
    }
    return '';
}

/**
 * Extract the numeric WooCommerce order number from a reference string.
 *
 * Example: "WC Order #293671" => "293671".
 *
 * @param string $reference
 * @return string Order number, or empty string if none found.
 */
function racrm_extract_order_number($reference) {
    if (preg_match('/(\d+)/', (string) $reference, $matches)) {
        return $matches[1];
    }
    return '';
}
