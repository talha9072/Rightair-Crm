<?php
/**
 * "Push Orders to CRM" admin screen.
 *
 * Recovery tool for orders that never reached the CRM automatically - for
 * example a quote conversion that was skipped, or an order placed while the
 * CRM API was unreachable.
 *
 * Enter one or more WooCommerce order numbers and each one is pushed in a
 * single action:
 *
 *   1. Create the CRM Deal (Account / Contact reuse handled by the shared
 *      racrm_create_deal_from_order() routine).
 *   2. Link any Zoho Books invoice already waiting in the queue for that order.
 *
 * This is a deliberate MANUAL OVERRIDE: it does not apply the automatic
 * quote/confirmed-sale gates, because the operator is explicitly asking for
 * this order to be pushed. It does still refuse to create a second Deal for an
 * order that already has one.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maximum orders accepted per submission.
 *
 * Each order costs several Zoho API round-trips, so a large paste is capped to
 * keep the request inside PHP's execution limit. The UI reports anything
 * skipped, so nothing is silently dropped.
 */
if (!defined('RACRM_PUSH_MAX_ORDERS')) {
    define('RACRM_PUSH_MAX_ORDERS', 20);
}

/**
 * Register the submenu entry.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'right-air-crm-settings',
        'Push Orders to CRM',
        'Push Orders',
        'manage_options',
        'right-air-crm-push-orders',
        'racrm_render_push_orders_page'
    );
}, 11);

/**
 * Parse a free-text list of order numbers into unique positive integers.
 *
 * Accepts commas, spaces, new lines, "#" prefixes - whatever the operator
 * pastes in.
 *
 * @param string $raw
 * @return array List of order ids.
 */
function racrm_parse_order_number_list($raw) {
    $parts = preg_split('/[^0-9]+/', (string) $raw);
    $ids   = [];

    foreach ((array) $parts as $part) {
        $id = absint($part);
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * Push a single order to the CRM: create the Deal, then link its invoice.
 *
 * @param int $order_id
 * @return array {
 *     @type int    $order_id
 *     @type string $state   ok | already | error
 *     @type string $deal    CRM Deal id, when known.
 *     @type string $invoice Human readable invoice-link outcome.
 *     @type string $note    Explanation for the operator.
 * }
 */
function racrm_push_order_to_crm($order_id) {
    $row = [
        'order_id' => $order_id,
        'state'    => 'error',
        'deal'     => '',
        'invoice'  => '',
        'note'     => '',
    ];

    if (!function_exists('wc_get_order')) {
        $row['note'] = 'WooCommerce is not active.';
        return $row;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        $row['note'] = 'Order not found.';
        return $row;
    }

    // Never create a second Deal for an order that already has one.
    $existing = $order->get_meta('_racrm_deal_id');
    if (!empty($existing)) {
        $row['state'] = 'already';
        $row['deal']  = $existing;
        $row['note']  = 'Already synced - no new Deal created.';
        $row['invoice'] = racrm_push_link_order_invoices($order_id);
        return $row;
    }

    racrm_log("[CRM] Manual push requested for Order #{$order_id} (Push Orders screen).");

    $result = racrm_create_deal_from_order($order_id);

    if (is_wp_error($result)) {
        $row['note'] = $result->get_error_message();
        return $row;
    }

    $row['state'] = 'ok';
    $row['deal']  = isset($result['id']) ? $result['id'] : '';
    $row['note']  = 'Deal created.';

    // Now that the Deal exists, any invoice that gave up waiting can be linked.
    $row['invoice'] = racrm_push_link_order_invoices($order_id);

    return $row;
}

/**
 * Link any queued Books invoices for an order, including ones already marked
 * failed because the Deal did not exist at the time.
 *
 * @param int $order_id
 * @return string Human readable outcome for the results table.
 */
function racrm_push_link_order_invoices($order_id) {
    if (!function_exists('racrm_invoice_queue_get_by_order_number')) {
        return '—';
    }

    $records = racrm_invoice_queue_get_by_order_number((string) $order_id);

    if (empty($records)) {
        return 'No invoice queued';
    }

    $linked  = 0;
    $waiting = 0;
    $errors  = [];

    foreach ($records as $record) {
        // Clear any previous "gave up" state so this attempt is allowed.
        if (!empty($record->failed)) {
            racrm_invoice_queue_requeue_failed($record->id);
            $record->failed   = 0;
            $record->attempts = 0;
        }

        $outcome = racrm_link_invoice_record($record);

        if (!empty($outcome['success'])) {
            racrm_invoice_queue_mark_processed($record->id);
            $linked++;
            continue;
        }

        if (empty($outcome['retryable'])) {
            // Permanently unlinkable (e.g. not a WooCommerce order invoice).
            racrm_invoice_queue_mark_failed($record->id, $outcome['error']);
            $errors[] = sprintf('#%d %s', $record->id, $outcome['error']);
            continue;
        }

        // Left pending so the normal 5-minute worker keeps trying.
        $waiting++;
        $errors[] = sprintf('#%d %s (will retry)', $record->id, $outcome['error']);
    }

    $summary = [];
    if ($linked > 0) {
        $summary[] = sprintf('%d linked', $linked);
    }
    if ($waiting > 0) {
        $summary[] = sprintf('%d pending', $waiting);
    }
    if (!empty($errors)) {
        $summary[] = implode('; ', $errors);
    }

    return empty($summary) ? '—' : implode(' · ', $summary);
}

/**
 * Render the Push Orders screen.
 */
function racrm_render_push_orders_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'right-air-crm'));
    }

    $raw     = '';
    $results = [];
    $notice  = '';

    if (isset($_POST['racrm_push_orders'])) {
        check_admin_referer('racrm_push_orders_action');

        $raw = isset($_POST['order_numbers']) ? sanitize_textarea_field($_POST['order_numbers']) : '';
        $ids = racrm_parse_order_number_list($raw);

        if (empty($ids)) {
            $notice = '<div class="notice notice-error"><p>Enter at least one order number.</p></div>';
        } else {
            if (count($ids) > RACRM_PUSH_MAX_ORDERS) {
                $skipped = array_slice($ids, RACRM_PUSH_MAX_ORDERS);
                $ids     = array_slice($ids, 0, RACRM_PUSH_MAX_ORDERS);
                $notice .= sprintf(
                    '<div class="notice notice-warning"><p>Processing the first %d orders. <strong>Not processed:</strong> %s &mdash; submit them separately.</p></div>',
                    (int) RACRM_PUSH_MAX_ORDERS,
                    esc_html(implode(', ', $skipped))
                );
            }

            foreach ($ids as $id) {
                $results[] = racrm_push_order_to_crm($id);
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Push Orders to CRM</h1>
        <?php echo $notice; ?>

        <p>
            Manually push WooCommerce orders that never reached the CRM. For each order this
            creates the Zoho CRM Deal and then links any Zoho Books invoice already waiting
            in the queue for it.
        </p>
        <p>
            <strong>Manual override.</strong> The automatic rules (quote must be converted, and
            a converted quote must be <code>processing</code> or <code>completed</code>) are not
            applied here &mdash; you are explicitly asking for these orders to be pushed. An order
            that already has a Deal is reported and skipped, never duplicated.
        </p>

        <?php if (!empty($results)) : ?>
            <h2>Results</h2>
            <table class="widefat striped" style="max-width:1000px">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Result</th>
                        <th>CRM Deal</th>
                        <th>Invoice</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row) : ?>
                    <?php
                    $labels = [
                        'ok'      => '<span style="color:#127a3d;font-weight:600">Created</span>',
                        'already' => '<span style="color:#8a6d1f;font-weight:600">Already synced</span>',
                        'error'   => '<span style="color:#b32d2e;font-weight:600">Failed</span>',
                    ];
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $row['order_id'] . '&action=edit')); ?>">
                                #<?php echo (int) $row['order_id']; ?>
                            </a>
                        </td>
                        <td><?php echo isset($labels[$row['state']]) ? $labels[$row['state']] : esc_html($row['state']); ?></td>
                        <td><?php echo $row['deal'] !== '' ? '<code>' . esc_html($row['deal']) . '</code>' : '&mdash;'; ?></td>
                        <td><?php echo esc_html($row['invoice'] !== '' ? $row['invoice'] : '—'); ?></td>
                        <td><?php echo esc_html($row['note']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Full detail for every step is in <code>logs/crm-debug.txt</code>.</p>
        <?php endif; ?>

        <h2>Order Numbers</h2>
        <form method="post" action="">
            <?php wp_nonce_field('racrm_push_orders_action'); ?>
            <textarea name="order_numbers" id="order_numbers" rows="5" class="large-text code"
                      placeholder="295326, 295146, 294582"><?php echo esc_textarea($raw); ?></textarea>
            <p class="description">
                One or more WooCommerce order numbers, separated by commas, spaces or new lines
                (<code>#</code> is ignored). Up to <?php echo (int) RACRM_PUSH_MAX_ORDERS; ?> per submission.
            </p>
            <?php submit_button('Push to CRM', 'primary', 'racrm_push_orders'); ?>
        </form>
    </div>
    <?php
}
