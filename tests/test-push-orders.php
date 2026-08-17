<?php
/**
 * Offline test for the "Push Orders to CRM" screen logic.
 * No WordPress, no WooCommerce, no CRM calls, no database.
 */

define('ABSPATH', __DIR__);

$GLOBALS['racrm_test_log']   = [];
$GLOBALS['racrm_deal_calls'] = [];
$GLOBALS['racrm_marked']     = [];   // queue id => processed|failed|requeued
$GLOBALS['racrm_queue_rows'] = [];   // order_number => rows

function racrm_log($m) { $GLOBALS['racrm_test_log'][] = $m; }
function add_action() {}
function absint($v) { return abs((int) $v); }
function is_wp_error($t) { return $t instanceof WP_Error; }
class WP_Error {
    private $m;
    public function __construct($c = '', $m = '') { $this->m = $m; }
    public function get_error_message() { return $this->m; }
}

class WC_Order {
    private $id, $meta;
    public function __construct($id, array $meta = []) { $this->id = $id; $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
}

function wc_get_order($id) { return $GLOBALS['racrm_test_orders'][$id] ?? false; }

// --- stubs for the CRM + queue collaborators -------------------------------
function racrm_create_deal_from_order($order_id) {
    $GLOBALS['racrm_deal_calls'][] = $order_id;
    if ($order_id === 999) {
        return new WP_Error('crm_error', 'CRM Error: rejected');
    }
    return ['id' => 'DEAL_' . $order_id, 'Deal_Name' => 'Order | WC Order #' . $order_id];
}
function racrm_invoice_queue_get_by_order_number($n) { return $GLOBALS['racrm_queue_rows'][$n] ?? []; }
function racrm_invoice_queue_requeue_failed($id = null) { $GLOBALS['racrm_marked'][$id] = 'requeued'; return 1; }
function racrm_invoice_queue_mark_processed($id) { $GLOBALS['racrm_marked'][$id] = 'processed'; }
function racrm_invoice_queue_mark_failed($id, $e = '') { $GLOBALS['racrm_marked'][$id] = 'failed'; }
function racrm_link_invoice_record($record) {
    // Behaviour keyed off the fake row so each branch can be driven.
    return $GLOBALS['racrm_link_result'][$record->id] ?? ['success' => true, 'retryable' => false, 'error' => ''];
}

require_once ($argv[1] ?? dirname(__DIR__)) . '/includes/admin-push-orders.php';

$pass = 0; $fail = 0;
function check($label, $expected, $actual) {
    global $pass, $fail;
    if ($expected === $actual) { $pass++; printf("  PASS  %-50s => %s\n", $label, json_encode($actual)); }
    else { $fail++; printf("  FAIL  %-50s => expected %s, got %s\n", $label, json_encode($expected), json_encode($actual)); }
}

echo "\n=== 1. Order-number parsing accepts whatever is pasted ===\n";
check('comma separated',   [295326, 295146], racrm_parse_order_number_list('295326, 295146'));
check('with # prefixes',   [295326, 295146], racrm_parse_order_number_list('#295326 #295146'));
check('newlines',          [295326, 295146], racrm_parse_order_number_list("295326\n295146"));
check('mixed separators',  [1, 2, 3],        racrm_parse_order_number_list('1, 2  ;  3'));
check('duplicates removed',[295326],         racrm_parse_order_number_list('295326, 295326, #295326'));
check('zero ignored',      [5],              racrm_parse_order_number_list('0, 5'));
check('empty input',       [],               racrm_parse_order_number_list(''));
check('no digits',         [],               racrm_parse_order_number_list('abc, ---'));
check('order preserved',   [3, 1, 2],        racrm_parse_order_number_list('3,1,2'));

echo "\n=== 2. Missing order is reported, not fatal ===\n";
$GLOBALS['racrm_test_orders'] = [];
$GLOBALS['racrm_deal_calls'] = [];
$r = racrm_push_order_to_crm(123456);
check('state', 'error', $r['state']);
check('note', 'Order not found.', $r['note']);
check('no CRM call made', [], $GLOBALS['racrm_deal_calls']);

echo "\n=== 3. Already-synced order is NEVER duplicated ===\n";
$GLOBALS['racrm_test_orders'] = [700 => new WC_Order(700, ['_racrm_deal_id' => 'EXISTING_DEAL'])];
$GLOBALS['racrm_deal_calls'] = [];
$GLOBALS['racrm_queue_rows'] = [];
$r = racrm_push_order_to_crm(700);
check('state', 'already', $r['state']);
check('reports existing deal', 'EXISTING_DEAL', $r['deal']);
check('no second Deal created', [], $GLOBALS['racrm_deal_calls']);

echo "\n=== 4. Happy path: Deal created and invoice linked ===\n";
$GLOBALS['racrm_test_orders'] = [295326 => new WC_Order(295326)];
$GLOBALS['racrm_deal_calls'] = [];
$GLOBALS['racrm_marked']     = [];
$GLOBALS['racrm_queue_rows'] = ['295326' => [(object) ['id' => 418, 'failed' => 1, 'attempts' => 20, 'order_number' => '295326', 'invoice_id' => 'INV1']]];
$GLOBALS['racrm_link_result'] = [418 => ['success' => true, 'retryable' => false, 'error' => '']];
$r = racrm_push_order_to_crm(295326);
check('state', 'ok', $r['state']);
check('deal id', 'DEAL_295326', $r['deal']);
check('deal created once', [295326], $GLOBALS['racrm_deal_calls']);
check('failed row was requeued then processed', 'processed', $GLOBALS['racrm_marked'][418]);
check('invoice summary', '1 linked', $r['invoice']);

echo "\n=== 5. CRM failure does not touch the invoice queue ===\n";
$GLOBALS['racrm_test_orders'] = [999 => new WC_Order(999)];
$GLOBALS['racrm_marked']     = [];
$GLOBALS['racrm_queue_rows'] = ['999' => [(object) ['id' => 500, 'failed' => 1, 'attempts' => 20, 'order_number' => '999', 'invoice_id' => 'INV2']]];
$r = racrm_push_order_to_crm(999);
check('state', 'error', $r['state']);
check('error surfaced', 'CRM Error: rejected', $r['note']);
check('queue untouched', [], $GLOBALS['racrm_marked']);

echo "\n=== 6. Order with no queued invoice ===\n";
$GLOBALS['racrm_test_orders'] = [800 => new WC_Order(800)];
$GLOBALS['racrm_queue_rows'] = [];
$r = racrm_push_order_to_crm(800);
check('state', 'ok', $r['state']);
check('invoice column', 'No invoice queued', $r['invoice']);

echo "\n=== 7. Retryable invoice failure is left for the cron worker ===\n";
$GLOBALS['racrm_test_orders'] = [801 => new WC_Order(801)];
$GLOBALS['racrm_marked']     = [];
$GLOBALS['racrm_queue_rows'] = ['801' => [(object) ['id' => 600, 'failed' => 0, 'attempts' => 3, 'order_number' => '801', 'invoice_id' => 'INV3']]];
$GLOBALS['racrm_link_result'] = [600 => ['success' => false, 'retryable' => true, 'error' => 'Invoice not found']];
$r = racrm_push_order_to_crm(801);
check('not marked processed or failed', false, isset($GLOBALS['racrm_marked'][600]));
check('reported as pending', true, strpos($r['invoice'], '1 pending') !== false);

echo "\n=== 8. Non-retryable invoice is closed out, not retried forever ===\n";
$GLOBALS['racrm_test_orders'] = [802 => new WC_Order(802)];
$GLOBALS['racrm_marked']     = [];
$GLOBALS['racrm_queue_rows'] = ['802' => [(object) ['id' => 601, 'failed' => 0, 'attempts' => 0, 'order_number' => '802', 'invoice_id' => 'INV4']]];
$GLOBALS['racrm_link_result'] = [601 => ['success' => false, 'retryable' => false, 'error' => 'Not a WooCommerce order invoice']];
$r = racrm_push_order_to_crm(802);
check('marked failed', 'failed', $GLOBALS['racrm_marked'][601]);
check('reason shown', true, strpos($r['invoice'], 'Not a WooCommerce order invoice') !== false);

echo "\n=== 9. Cap protects against a huge paste ===\n";
$many = implode(',', range(1, 50));
check('parsed all 50', 50, count(racrm_parse_order_number_list($many)));
check('cap constant', 20, RACRM_PUSH_MAX_ORDERS);

printf("\n----------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
