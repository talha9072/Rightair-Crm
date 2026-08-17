<?php
/**
 * Offline test for the invoice reference -> WooCommerce order number parsing.
 *
 * Stubs the handful of WordPress functions the modules touch so the real
 * plugin code can be loaded and exercised with no WordPress, no database,
 * no CRM API calls and no live webhook.
 */

define('ABSPATH', __DIR__);

$GLOBALS['racrm_test_log'] = [];

function racrm_log($message) {
    $GLOBALS['racrm_test_log'][] = $message;
}

function add_action() {}
function add_filter() {}
function register_rest_route() {}
function get_option($name, $default = '') { return $default; }
function wp_json_encode($data) { return json_encode($data); }

// Pass-through filter so the production filter hook is exercised.
function apply_filters($hook, $value) { return $value; }

// Guard: these must never be reached by these tests. If the code under test
// tries to talk to Zoho, the test fails loudly instead of hitting the network.
function racrm_api_get($endpoint) {
    throw new RuntimeException("NETWORK CALL ATTEMPTED: GET {$endpoint}");
}
function racrm_api_put($endpoint, $payload) {
    throw new RuntimeException("NETWORK CALL ATTEMPTED: PUT {$endpoint}");
}
function racrm_invoice_module_name() { return 'CustomModule5001'; }

$plugin = $argv[1] ?? dirname(__DIR__);

require_once $plugin . '/includes/invoice-linking/invoice-webhook.php';
require_once $plugin . '/includes/invoice-linking/invoice-linker.php';

$pass = 0;
$fail = 0;

function check($label, $expected, $actual) {
    global $pass, $fail;
    $ok = ($expected === $actual);
    if ($ok) {
        $pass++;
        printf("  PASS  %-46s => %s\n", $label, var_export($actual, true));
    } else {
        $fail++;
        printf("  FAIL  %-46s => expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true));
    }
}

echo "\n=== 1. Real WooCommerce references MUST still resolve ===\n";
// These are the reference strings the integration actually writes, taken
// verbatim from the production log.
check('WC Order #295326',        '295326', racrm_extract_order_number('WC Order #295326'));
check('WC Order #295146',        '295146', racrm_extract_order_number('WC Order #295146'));
check('WC Order #295430',        '295430', racrm_extract_order_number('WC Order #295430'));
check('WC Order #295398',        '295398', racrm_extract_order_number('WC Order #295398'));
check('WC Order #21944 (old id)', '21944', racrm_extract_order_number('WC Order #21944'));

echo "\n=== 2. Formatting tolerance (hand-edited references) ===\n";
check('lowercase',              '295326', racrm_extract_order_number('wc order #295326'));
check('no hash',                '295326', racrm_extract_order_number('WC Order 295326'));
check('extra spacing',          '295326', racrm_extract_order_number('WC  Order  #  295326'));
check('no spacing',             '295326', racrm_extract_order_number('WCOrder#295326'));
check('surrounding text',       '295430', racrm_extract_order_number('Ref: WC Order #295430 paid'));
check('leading zeros stripped', '295326', racrm_extract_order_number('WC Order #0295326'));
check('untrimmed whitespace',   '295326', racrm_extract_order_number("  WC Order #295326\n"));

echo "\n=== 3. Zoho Sales Orders MUST be rejected (the retry-storm cause) ===\n";
// Each of these previously became a bogus order number and burned 20 retries.
check('SO02276', '', racrm_extract_order_number('SO02276'));
check('SO02245', '', racrm_extract_order_number('SO02245'));
check('SO02289', '', racrm_extract_order_number('SO02289'));
check('SO02176', '', racrm_extract_order_number('SO02176'));
check('SO 02276 (spaced)', '', racrm_extract_order_number('SO 02276'));
check('Sales Order 02276', '', racrm_extract_order_number('Sales Order 02276'));

echo "\n=== 4. Other junk references MUST be rejected ===\n";
check('184543064',   '', racrm_extract_order_number('184543064'));
check('217919396',   '', racrm_extract_order_number('217919396'));
check('bare 2',      '', racrm_extract_order_number('2'));
check('bare 13',     '', racrm_extract_order_number('13'));
check('bare 158',    '', racrm_extract_order_number('158'));
check('bare 758',    '', racrm_extract_order_number('758'));
check('bare 2026',   '', racrm_extract_order_number('2026'));
check('empty string', '', racrm_extract_order_number(''));
check('whitespace',   '', racrm_extract_order_number('   '));
check('null',         '', racrm_extract_order_number(null));
check('no digits',    '', racrm_extract_order_number('WC Order #'));

echo "\n=== 5. Full payload parsing (nested Zoho Books shape) ===\n";
$woo = racrm_parse_books_invoice_payload([
    'invoice' => [
        'invoice_id'       => '5935901000026300003',
        'invoice_number'   => 'IN32254',
        'reference_number' => 'WC Order #295326',
    ],
]);
check('woo invoice -> order_number', '295326', $woo['order_number']);
check('woo invoice -> invoice_id', '5935901000026300003', $woo['invoice_id']);
check('woo invoice -> invoice_number', 'IN32254', $woo['invoice_number']);

$so = racrm_parse_books_invoice_payload([
    'invoice' => [
        'invoice_id'       => '5935901000026300099',
        'invoice_number'   => 'IN32289',
        'reference_number' => 'SO02289',
    ],
]);
check('SO invoice -> order_number empty', '', $so['order_number']);
check('SO invoice -> invoice_id kept', '5935901000026300099', $so['invoice_id']);

$flat = racrm_parse_books_invoice_payload([
    'invoice_id'       => '123',
    'invoice_number'   => 'IN1',
    'reference_number' => 'WC Order #294582',
]);
check('flat payload still supported', '294582', $flat['order_number']);

echo "\n=== 6. Worker classification: skipped rows must NOT be retried ===\n";
// A row with no Woo order number must be non-retryable, and must never
// reach the CRM API (the stubs above throw if it tries).
$record = (object) ['id' => 999, 'order_number' => '', 'invoice_id' => '5935901000026300099'];
$result = racrm_link_invoice_record($record);
check('no order number -> success', false, $result['success']);
check('no order number -> retryable', false, $result['retryable']);
check('no order number -> error', 'Not a WooCommerce order invoice', $result['error']);

$record2 = (object) ['id' => 998, 'order_number' => '295326', 'invoice_id' => ''];
$result2 = racrm_link_invoice_record($record2);
check('no invoice id -> retryable', false, $result2['retryable']);
check('no invoice id -> error', 'Missing invoice_id', $result2['error']);

echo "\n=== 7. A real Woo order still reaches the CRM lookup ===\n";
// Proves the fix did not accidentally short-circuit legitimate invoices:
// this one MUST get as far as the Deal search (our stub throws to prove it).
$record3 = (object) ['id' => 997, 'order_number' => '295326', 'invoice_id' => '5935901000026300003'];
try {
    racrm_link_invoice_record($record3);
    check('valid row reaches Deal search', 'threw', 'did not reach API');
} catch (RuntimeException $e) {
    $reached = strpos($e->getMessage(), 'Deals/search') !== false;
    check('valid row reaches Deal search', true, $reached);
}

echo "\n=== 8. Skip reason is logged for operators ===\n";
$GLOBALS['racrm_test_log'] = [];
racrm_parse_books_invoice_payload(['reference_number' => 'SO02289', 'invoice_id' => 'x']);
$logged = implode("\n", $GLOBALS['racrm_test_log']);
check('logs "not a WooCommerce order"', true, strpos($logged, 'not a WooCommerce order') !== false);
check('logs the offending reference', true, strpos($logged, 'SO02289') !== false);

printf("\n----------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
