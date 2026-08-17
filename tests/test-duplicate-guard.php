<?php
/**
 * Offline test for the duplicate-Deal guard inside the shared
 * racrm_create_deal_from_order() routine. No WordPress, no CRM calls.
 */

define('ABSPATH', __DIR__);

$GLOBALS['racrm_test_log'] = [];
$GLOBALS['racrm_posts']    = [];   // endpoints POSTed to the CRM
$GLOBALS['racrm_saved']    = [];   // order id => deal id written back

function racrm_log($m) { $GLOBALS['racrm_test_log'][] = $m; }
function add_action() {}
function is_wp_error($t) { return $t instanceof WP_Error; }

class WP_Error {
    private $code, $msg;
    public function __construct($code = '', $msg = '') { $this->code = $code; $this->msg = $msg; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; }
}

class WC_Order {
    private $id, $meta;
    public function __construct($id, array $meta = []) { $this->id = $id; $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function save() { $GLOBALS['racrm_saved'][$this->id] = $this->meta['_racrm_deal_id'] ?? ''; }
    // Fields read while building the payload.
    public function get_billing_first_name() { return 'Test'; }
    public function get_billing_last_name()  { return 'Customer'; }
    public function get_billing_email()      { return 'test@example.com'; }
    public function get_billing_phone()      { return '0100000000'; }
    public function get_billing_address_1()  { return '1 Road'; }
    public function get_billing_city()       { return 'Cape Town'; }
    public function get_billing_state()      { return 'WC'; }
    public function get_billing_postcode()   { return '8001'; }
    public function get_billing_country()    { return 'ZA'; }
    public function get_status()             { return 'processing'; }
    public function get_payment_method()     { return 'ozow'; }
    public function get_currency()           { return 'ZAR'; }
    public function get_total()              { return '19448.01'; }
}

function wc_get_order($id) { return $GLOBALS['racrm_test_orders'][$id] ?? false; }

// CRM collaborators: record calls, never touch the network.
function racrm_find_contact_by_email($email) { return false; }
function racrm_get_account_from_contact($c)  { return ''; }
function racrm_create_account($p)            { return 'ACC1'; }
function racrm_create_contact($p)            { return 'CON1'; }
function racrm_api_post($endpoint, $payload) {
    $GLOBALS['racrm_posts'][] = $endpoint;
    return ['data' => [['code' => 'SUCCESS', 'details' => ['id' => 'NEW_DEAL_ID']]]];
}

require_once ($argv[1] ?? dirname(__DIR__)) . '/includes/crm-deals.php';

$pass = 0; $fail = 0;
function check($label, $expected, $actual) {
    global $pass, $fail;
    if ($expected === $actual) { $pass++; printf("  PASS  %-52s => %s\n", $label, json_encode($actual)); }
    else { $fail++; printf("  FAIL  %-52s => expected %s, got %s\n", $label, json_encode($expected), json_encode($actual)); }
}

echo "\n=== 1. Order with an existing Deal is refused ===\n";
$GLOBALS['racrm_test_orders'] = [295326 => new WC_Order(295326, ['_racrm_deal_id' => 'EXISTING_DEAL'])];
$GLOBALS['racrm_posts'] = [];
$r = racrm_create_deal_from_order(295326);
check('returns WP_Error',        true, is_wp_error($r));
check('error code',   'deal_exists', $r->get_error_code());
check('NO CRM write attempted',    [], $GLOBALS['racrm_posts']);
check('mentions existing deal',  true, strpos($r->get_error_message(), 'EXISTING_DEAL') !== false);

echo "\n=== 2. Order without a Deal still syncs normally (no regression) ===\n";
$GLOBALS['racrm_test_orders'] = [295327 => new WC_Order(295327)];
$GLOBALS['racrm_posts'] = [];
$GLOBALS['racrm_saved'] = [];
$r = racrm_create_deal_from_order(295327);
check('not an error',            false, is_wp_error($r));
check('deal id returned', 'NEW_DEAL_ID', $r['id']);
check('CRM write happened',   ['/Deals'], $GLOBALS['racrm_posts']);
check('deal id saved to order', 'NEW_DEAL_ID', $GLOBALS['racrm_saved'][295327]);

echo "\n=== 3. Empty meta values are not mistaken for an existing Deal ===\n";
foreach ([['empty string', ''], ['zero string', '0']] as $case) {
    list($label, $value) = $case;
    $GLOBALS['racrm_test_orders'] = [400 => new WC_Order(400, ['_racrm_deal_id' => $value])];
    $GLOBALS['racrm_posts'] = [];
    $r = racrm_create_deal_from_order(400);
    check("{$label} meta -> proceeds", false, is_wp_error($r));
}

echo "\n=== 4. \$force allows deliberate re-creation ===\n";
$GLOBALS['racrm_test_orders'] = [295326 => new WC_Order(295326, ['_racrm_deal_id' => 'EXISTING_DEAL'])];
$GLOBALS['racrm_posts'] = [];
$r = racrm_create_deal_from_order(295326, true);
check('force bypasses guard', false, is_wp_error($r));
check('force does write',  ['/Deals'], $GLOBALS['racrm_posts']);

echo "\n=== 5. Missing order still errors before any guard work ===\n";
$GLOBALS['racrm_test_orders'] = [];
$GLOBALS['racrm_posts'] = [];
$r = racrm_create_deal_from_order(123456);
check('order_not_found', 'order_not_found', $r->get_error_code());
check('no CRM write',                  [], $GLOBALS['racrm_posts']);

echo "\n=== 6. Refusal is logged for the audit trail ===\n";
$GLOBALS['racrm_test_log'] = [];
$GLOBALS['racrm_test_orders'] = [295326 => new WC_Order(295326, ['_racrm_deal_id' => 'EXISTING_DEAL'])];
racrm_create_deal_from_order(295326);
check('logs the refusal', true, strpos(implode("\n", $GLOBALS['racrm_test_log']), 'Create Deal refused') !== false);

printf("\n----------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
