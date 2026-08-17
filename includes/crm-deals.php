<?php
/**
 * Deals Module for Right Air CRM
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create a Zoho CRM Deal from a WooCommerce Order
 *
 * Refuses to create a second Deal for an order that already has one. Every
 * caller is expected to check first, but the guard lives here as well so that
 * no current or future entry point can duplicate a Deal in Zoho - a duplicate
 * is painful to clean up because Books invoices may already be attached to the
 * original.
 *
 * @param int  $order_id
 * @param bool $force    Skip the duplicate guard. Only for deliberate
 *                       re-creation (e.g. the Deal was deleted in Zoho).
 * @return array|WP_Error Deal data on success, WP_Error on failure.
 */
function racrm_create_deal_from_order($order_id, $force = false) {
    if (!function_exists('wc_get_order')) {
        return new WP_Error('wc_missing', 'WooCommerce is not active.');
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        racrm_log("[CRM] Create Deal failed: Order #{$order_id} not found.");
        return new WP_Error('order_not_found', "Order #{$order_id} not found.");
    }

    // Duplicate protection - the last line of defence before the CRM write.
    $existing_deal_id = $order->get_meta('_racrm_deal_id');
    if (!$force && !empty($existing_deal_id)) {
        racrm_log("[CRM] Create Deal refused: Order #{$order_id} already has Deal {$existing_deal_id}.");
        return new WP_Error(
            'deal_exists',
            sprintf('Order #%d already has CRM Deal %s. No new Deal created.', $order_id, $existing_deal_id)
        );
    }

    racrm_log("[CRM] ==================================================");
    racrm_log("[CRM] Starting CRM sync for Order #{$order_id}");

    // 1. Prepare Customer Data
    $first_name = $order->get_billing_first_name();
    $last_name  = $order->get_billing_last_name();
    $full_name  = trim($first_name . ' ' . $last_name);
    $email      = $order->get_billing_email();
    $phone      = $order->get_billing_phone();
    $address    = $order->get_billing_address_1();
    $city       = $order->get_billing_city();
    $state      = $order->get_billing_state();
    $postcode   = $order->get_billing_postcode();
    $country    = $order->get_billing_country();

    racrm_log("[CRM] WooCommerce Status: " . $order->get_status());
    racrm_log("[CRM] Customer Email: {$email}");
    racrm_log("[CRM] ==================================================");

    // 1.1 Contact & Account Lookup Flow
    $account_id = '';
    $contact_id = '';

    racrm_log("[CRM] Searching Contact");
    racrm_log("[CRM] Email: {$email}");
    $t_contact = microtime(true);
    $contact = racrm_find_contact_by_email($email);
    racrm_log("[CRM] Contact Search took " . round((microtime(true) - $t_contact) * 1000) . "ms (Order #{$order_id})");

    if ($contact) {
        $contact_id = isset($contact['id']) ? $contact['id'] : '';
        racrm_log("[CRM] Contact Found");
        racrm_log("[CRM] Contact ID: {$contact_id}");

        racrm_log("[CRM] Searching Account (from contact)");
        $account_id = racrm_get_account_from_contact($contact);
        if ($account_id) {
            racrm_log("[CRM] Account Found");
            racrm_log("[CRM] Account ID: {$account_id}");
        } else {
            racrm_log("[CRM] Account not linked to contact {$contact_id} (Order #{$order_id})");
        }
    } else {
        racrm_log("[CRM] Contact not found - creating Account then Contact (Order #{$order_id})");

        // Create Account
        racrm_log("[CRM] Creating Account");
        $account_payload = [
            'Account_Name'   => $full_name,
            'Phone'          => $phone,
            'Billing_Street' => $address,
            'Billing_City'   => $city,
            'Billing_State'  => $state,
            'Billing_Code'   => $postcode,
            'Billing_Country'=> $country,
        ];
        $t_account = microtime(true);
        $account_id = racrm_create_account($account_payload);
        racrm_log("[CRM] Account Create took " . round((microtime(true) - $t_account) * 1000) . "ms (Order #{$order_id})");

        if ($account_id) {
            racrm_log("[CRM] Account Created");
            racrm_log("[CRM] Account ID: {$account_id}");

            // Create Contact
            racrm_log("[CRM] Creating Contact");
            $t_contact_create = microtime(true);
            $contact_id = racrm_create_contact([
                'First_Name'   => $first_name,
                'Last_Name'    => $last_name,
                'Email'        => $email,
                'Phone'        => $phone,
                'Account_Name' => [
                    'id' => $account_id
                ]
            ]);
            racrm_log("[CRM] Contact Create took " . round((microtime(true) - $t_contact_create) * 1000) . "ms (Order #{$order_id})");

            if ($contact_id) {
                racrm_log("[CRM] Contact Created");
                racrm_log("[CRM] Contact ID: {$contact_id}");
            } else {
                racrm_log("[CRM] ERROR");
                racrm_log("[CRM] Step: Contact Creation");
                racrm_log("[CRM] Order: {$order_id}");
            }
        } else {
            racrm_log("[CRM] ERROR");
            racrm_log("[CRM] Step: Account Creation");
            racrm_log("[CRM] Order: {$order_id}");
        }
    }

    if ($account_id) {
        racrm_log("[CRM] Attaching account to deal: " . $account_id);
    }

    // 2. Map Payment Option -> CRM Payment_Status (picklist value)
    $payment_method = $order->get_payment_method();
    $payment_status = '';

    // WooCommerce payment method => Zoho CRM Payment_Status picklist value.
    // Verified against live CRM behaviour: the field does NOT use separate API
    // values, so the visible picklist option string must be sent verbatim.
    $payment_mapping = [
        'bacs'    => 'EFT - Direct Payment to Right Air',
        'eft'     => 'EFT - Direct Payment to Right Air',
        'ozow'    => 'Ozow',
        'payfast' => 'PayFast - Credit Card Payments',
    ];

    racrm_log("[CRM] Woo Payment Method: " . ($payment_method ?: 'none'));

    if (isset($payment_mapping[$payment_method])) {
        $payment_status = $payment_mapping[$payment_method];
    } else {
        racrm_log("[CRM] Unmapped payment gateway: " . ($payment_method ?: 'none'));
    }

    racrm_log("[CRM] CRM Payment_Status Value: " . ($payment_status !== '' ? $payment_status : '(empty)'));

    // 3. Build Description
    $description = sprintf(
        "WC Order #%d\n\nCustomer:\n%s %s\n\nEmail:\n%s\n\nPhone:\n%s\n\nOrder Total:\n%s %s",
        $order_id,
        $first_name,
        $last_name,
        $email,
        $phone,
        $order->get_currency(),
        $order->get_total()
    );

    // 4. Construct CRM Payload
    $deal_data = [
        'Deal_Name'      => 'Order | WC Order #' . $order_id,
        'Stage'          => 'New order',
        'First_Name'     => $first_name,
        'Last_Name'      => $last_name,
        'Email'          => $email,
        'Phone'          => $phone,
        'Street'         => $address,
        'Citt'           => $city, // Mapping requested 'Citt' instead of 'City'
        'State'          => $state,
        'Zip_Code'       => $postcode,
        'Country'        => $country,
        'Amount'         => floatval($order->get_total()),
        'Expected_Revenue' => floatval($order->get_total()),
        'Lead_Source'    => 'Online Order',
        'Payment_Status' => $payment_status,
        'Description'    => $description,
        // Fields to leave empty explicitly or just not send
        'Invoice_Number' => '',
        'Invoice_Number1'=> '',
    ];

    // Attach Account if exists
    if ($account_id) {
        $deal_data['Account_Name'] = [
            'id' => $account_id
        ];
    }

    $deal_payload = [
        'data' => [$deal_data]
    ];

    racrm_log("[CRM] Creating Deal");
    racrm_log("[CRM] Deal Payload: " . json_encode($deal_payload));
    racrm_log("[CRM] Deal API Endpoint: /Deals");

    // 5. Send to Zoho
    $t_deal = microtime(true);
    $response = racrm_api_post('/Deals', $deal_payload);
    racrm_log("[CRM] Deal Create took " . round((microtime(true) - $t_deal) * 1000) . "ms (Order #{$order_id})");

    $deal_status_code = isset($response['data'][0]['code']) ? $response['data'][0]['code'] : 'UNKNOWN';
    racrm_log("[CRM] Deal API Response Code: {$deal_status_code}");
    racrm_log("[CRM] Deal API Response: " . json_encode($response));

    if (!$response) {
        racrm_log("[CRM] ERROR");
        racrm_log("[CRM] Step: Deal Creation");
        racrm_log("[CRM] Response: CRM API Request failed (no response)");
        racrm_log("[CRM] Order: {$order_id}");
        racrm_log("[CRM] CRM Sync Failed");
        racrm_log("[CRM] Order: {$order_id}");
        racrm_log("[CRM] Failure Step: Deal Creation");
        racrm_log("[CRM] Reason: CRM API Request failed");
        return new WP_Error('api_failed', 'CRM API Request failed. Check logs.');
    }

    // 6. Handle Response
    if (!empty($response['data'][0]['code']) && $response['data'][0]['code'] === 'SUCCESS') {
        $deal_id = $response['data'][0]['details']['id'];

        // Save to order meta
        $order->update_meta_data('_racrm_deal_id', $deal_id);
        $order->save();

        racrm_log("[CRM] Deal ID: {$deal_id}");
        racrm_log("[CRM] Deal Name: " . $deal_data['Deal_Name']);
        racrm_log("[CRM] ==================================================");
        racrm_log("[CRM] CRM Sync Complete");
        racrm_log("[CRM] Order: {$order_id}");
        racrm_log("[CRM] Account ID: " . ($account_id ?: 'none'));
        racrm_log("[CRM] Contact ID: " . ($contact_id ?: 'none'));
        racrm_log("[CRM] Deal ID: {$deal_id}");
        racrm_log("[CRM] ==================================================");

        return [
            'id'        => $deal_id,
            'Deal_Name' => 'Order | WC Order #' . $order_id
        ];
    } else {
        $error_json = json_encode($response);
        $error_msg = isset($response['data'][0]['message']) ? $response['data'][0]['message'] : 'Unknown CRM error';

        racrm_log("[CRM] ERROR");
        racrm_log("[CRM] Step: Deal Creation");
        racrm_log("[CRM] Response: " . $error_json);
        racrm_log("[CRM] Order: {$order_id}");
        racrm_log("[CRM] CRM Sync Failed");
        racrm_log("[CRM] Order: {$order_id}");
        racrm_log("[CRM] Failure Step: Deal Creation");
        racrm_log("[CRM] Reason: {$error_msg}");

        return new WP_Error('crm_error', 'CRM Error: ' . $error_msg);
    }
}

/**
 * Find a CRM deal by some criteria
 *
 * @param array $criteria
 * @return array|bool Deal data or false.
 */
function racrm_find_deal($criteria) {
    // TODO: Implement deal search logic
    racrm_log("🔍 Placeholder: Searching for deal with criteria: " . json_encode($criteria));
    
    return false;
}

/**
 * Create a new CRM deal
 *
 * @param array $deal_data
 * @return int|bool Result status or false.
 */
function racrm_create_deal($deal_data) {
    // TODO: Implement deal creation logic
    // Endpoint: /Deals
    racrm_log("➕ Placeholder: Creating new deal: " . json_encode($deal_data));
    
    return false;
}
