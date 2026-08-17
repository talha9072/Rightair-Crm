<?php
/**
 * Offline test for the quote-vs-converted-order detection and the
 * quote-conversion safety net. No WordPress, no WooCommerce, no CRM calls.
 */

define('ABSPATH', __DIR__);

$GLOBALS['racrm_test_log']  = [];
$GLOBALS['racrm_sync_calls'] = [];

function racrm_log($message) { $GLOBALS['racrm_test_log'][] = $message; }
function add_action() {}
function add_filter() {}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
class WP_Error {
    private $msg;
    public function __construct($code = '', $msg = '') { $this->msg = $msg; }
    public function get_error_message() { return $this->msg; }
}

/** Minimal stand-in for a WooCommerce order. */
class WC_Order {
    private $id;
    private $status;
    private $meta;

    public function __construct($id, $status, array $meta = []) {
        $this->id     = $id;
        $this->status = $status;
        $this->meta   = $meta;
    }
    public function get_id()   { return $this->id; }
    public function get_status() { return $this->status; }
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
}

$plugin = $argv[1] ?? dirname(__DIR__);
require_once $plugin . '/includes/crm-order-sync.php';

// Record delegation instead of performing a real sync. Declared AFTER the
// include so the real function (which lives in crm-deals.php) is never loaded.
function racrm_create_deal_from_order($order_id) {
    $GLOBALS['racrm_sync_calls'][] = $order_id;
    return ['id' => 'FAKE_DEAL'];
}
function wc_get_order($id) { return $GLOBALS['racrm_test_orders'][$id] ?? false; }

$pass = 0; $fail = 0;
function check($label, $expected, $actual) {
    global $pass, $fail;
    if ($expected === $actual) { $pass++; printf("  PASS  %-52s => %s\n", $label, var_export($actual, true)); }
    else { $fail++; printf("  FAIL  %-52s => expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true)); }
}

$RAQ = ['ywraq_raq' => 'yes'];

echo "\n=== 1. Open quotes are still correctly blocked (no regression) ===\n";
foreach (['ywraq-new', 'ywraq-pending', 'ywraq-accepted', 'ywraq-rejected', 'ywraq-expired'] as $status) {
    check("open quote in {$status}", true, racrm_order_is_quote(new WC_Order(1, $status, $RAQ)));
}

echo "\n=== 2. Converted quotes are NOT quotes (the bug) ===\n";
// Order 295326's exact live state: real status, origin marker still set,
// and ywraq_raq_status still present because YITH never persisted its delete.
$converted = new WC_Order(295326, 'processing', [
    'ywraq_raq'        => 'yes',
    'ywraq_raq_status' => 'accepted',
]);
check('295326 (processing, ywraq_raq=yes) is quote', false, racrm_order_is_quote($converted));
check('295326 originated as quote',                  true, racrm_order_originated_as_quote($converted));
foreach (['pending', 'processing', 'on-hold', 'completed'] as $status) {
    check("converted quote in {$status} is quote", false, racrm_order_is_quote(new WC_Order(2, $status, $RAQ)));
}

echo "\n=== 3. Ordinary orders are unaffected ===\n";
check('plain processing order is quote',       false, racrm_order_is_quote(new WC_Order(3, 'processing')));
check('plain order originated as quote',       false, racrm_order_originated_as_quote(new WC_Order(3, 'processing')));
check('refunded order is quote',               false, racrm_order_is_quote(new WC_Order(3, 'refunded')));

echo "\n=== 4. Safety net fires only for paid converted quotes ===\n";
// Should sync: quote-origin order reaching a real fulfilment status.
// Distinct order ids: racrm_maybe_auto_sync_order() keeps a per-request static
// guard, so reusing one id would (correctly) suppress the later iterations.
foreach (['processing' => 510, 'completed' => 512] as $to => $id) {
    $GLOBALS['racrm_sync_calls'] = [];
    $o = new WC_Order($id, $to, $RAQ);
    $GLOBALS['racrm_test_orders'] = [$id => $o];
    racrm_auto_sync_on_quote_conversion($id, 'ywraq-accepted', $to, $o);
    check("ywraq-accepted -> {$to} syncs", [$id], $GLOBALS['racrm_sync_calls']);
}

// on-hold is awaiting EFT payment, not a confirmed sale.
$GLOBALS['racrm_sync_calls'] = [];
$o = new WC_Order(511, 'on-hold', $RAQ);
$GLOBALS['racrm_test_orders'] = [511 => $o];
racrm_auto_sync_on_quote_conversion(511, 'ywraq-accepted', 'on-hold', $o);
check('ywraq-accepted -> on-hold does NOT sync', [], $GLOBALS['racrm_sync_calls']);

echo "\n=== 5. Safety net must NOT fire in these cases ===\n";
// Accepted but unpaid: no Deal yet, by design.
$GLOBALS['racrm_sync_calls'] = [];
$o = new WC_Order(501, 'pending', $RAQ);
$GLOBALS['racrm_test_orders'] = [501 => $o];
racrm_auto_sync_on_quote_conversion(501, 'ywraq-accepted', 'pending', $o);
check('accepted -> pending (unpaid) does not sync', [], $GLOBALS['racrm_sync_calls']);

// Plain order: already handled by the checkout hooks.
$GLOBALS['racrm_sync_calls'] = [];
$o = new WC_Order(502, 'processing');
$GLOBALS['racrm_test_orders'] = [502 => $o];
racrm_auto_sync_on_quote_conversion(502, 'pending', 'processing', $o);
check('non-quote order does not re-sync', [], $GLOBALS['racrm_sync_calls']);

// Already synced: duplicate protection.
$GLOBALS['racrm_sync_calls'] = [];
$o = new WC_Order(503, 'completed', ['ywraq_raq' => 'yes', '_racrm_deal_id' => 'EXISTING']);
$GLOBALS['racrm_test_orders'] = [503 => $o];
racrm_auto_sync_on_quote_conversion(503, 'processing', 'completed', $o);
check('existing deal id does not duplicate', [], $GLOBALS['racrm_sync_calls']);

// Cancelled / failed / refunded must not create Deals.
// Distinct ids again, so a wrongly-syncing status cannot hide behind the
// per-request guard and make the following assertions pass vacuously.
foreach (['cancelled' => 520, 'failed' => 521, 'refunded' => 522] as $to => $id) {
    $GLOBALS['racrm_sync_calls'] = [];
    $o = new WC_Order($id, $to, $RAQ);
    $GLOBALS['racrm_test_orders'] = [$id => $o];
    racrm_auto_sync_on_quote_conversion($id, 'ywraq-accepted', $to, $o);
    check("ywraq-accepted -> {$to} does not sync", [], $GLOBALS['racrm_sync_calls']);
}

// Non-order argument must be ignored, not fatal.
$GLOBALS['racrm_sync_calls'] = [];
racrm_auto_sync_on_quote_conversion(505, 'pending', 'processing', null);
check('null order is ignored safely', [], $GLOBALS['racrm_sync_calls']);

echo "\n=== 6. Guarded entry point blocks open quotes end to end ===\n";
$GLOBALS['racrm_sync_calls'] = [];
$o = new WC_Order(600, 'ywraq-pending', $RAQ);
$GLOBALS['racrm_test_orders'] = [600 => $o];
racrm_maybe_auto_sync_order(600, 'test');
check('open quote never creates a deal', [], $GLOBALS['racrm_sync_calls']);

$GLOBALS['racrm_sync_calls'] = [];
$o = new WC_Order(601, 'processing', $RAQ);
$GLOBALS['racrm_test_orders'] = [601 => $o];
racrm_maybe_auto_sync_order(601, 'test');
check('converted quote (processing) creates a deal', [601], $GLOBALS['racrm_sync_calls']);

// Same-request double fire must be deduped.
racrm_maybe_auto_sync_order(601, 'test again');
check('same-request duplicate suppressed', [601], $GLOBALS['racrm_sync_calls']);

echo "\n=== 8. The confirmed-sale gate applies on the checkout path too ===\n";
// This is order 295326's original scenario: checkout fires while the converted
// quote is still 'pending'. It must NOT create a Deal yet.
foreach (['pending' => 610, 'on-hold' => 611] as $status => $id) {
    $GLOBALS['racrm_sync_calls'] = [];
    $o = new WC_Order($id, $status, $RAQ);
    $GLOBALS['racrm_test_orders'] = [$id => $o];
    racrm_auto_sync_on_checkout($id);
    check("converted quote at checkout ({$status}) waits", [], $GLOBALS['racrm_sync_calls']);
}

// Ordinary orders are NOT gated - unchanged behaviour since June.
$GLOBALS['racrm_sync_calls'] = [];
$o = new WC_Order(620, 'pending');
$GLOBALS['racrm_test_orders'] = [620 => $o];
racrm_auto_sync_on_checkout(620);
check('plain order at checkout (pending) still syncs', [620], $GLOBALS['racrm_sync_calls']);

echo "\n=== 9. Bailing early must not poison a later valid sync ===\n";
// Checkout creates the converted quote as pending (gate blocks it), then the
// gateway moves it to processing in the SAME request. The second attempt must
// still sync - i.e. the dedup guard must not have claimed the order on bail.
$GLOBALS['racrm_sync_calls'] = [];
$pending = new WC_Order(700, 'pending', $RAQ);
$GLOBALS['racrm_test_orders'] = [700 => $pending];
racrm_auto_sync_on_checkout(700);
check('step 1: pending is blocked', [], $GLOBALS['racrm_sync_calls']);

$paid = new WC_Order(700, 'processing', $RAQ);
$GLOBALS['racrm_test_orders'] = [700 => $paid];
racrm_auto_sync_on_quote_conversion(700, 'pending', 'processing', $paid);
check('step 2: same request, now processing, syncs', [700], $GLOBALS['racrm_sync_calls']);

echo "\n=== 10. Status lists ===\n";
check('quote statuses', ['ywraq-new','ywraq-pending','ywraq-accepted','ywraq-rejected','ywraq-expired'], racrm_quote_order_statuses());
check('syncable statuses', ['processing','completed'], racrm_syncable_order_statuses());

printf("\n----------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
