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
 * @param int $order_id
 * @return array|WP_Error Deal data on success, WP_Error on failure.
 */
function racrm_create_deal_from_order($order_id) {
    if (!function_exists('wc_get_order')) {
        return new WP_Error('wc_missing', 'WooCommerce is not active.');
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        racrm_log("❌ Create Deal failed: Order #{$order_id} not found.");
        return new WP_Error('order_not_found', "Order #{$order_id} not found.");
    }

    racrm_log("🛒 Order #{$order_id} loaded for CRM Deal creation.");

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

    // 1.1 Contact & Account Lookup Flow
    $account_id = '';
    $contact = racrm_find_contact_by_email($email);

    if ($contact) {
        $account_id = racrm_get_account_from_contact($contact);
    } else {
        racrm_log("[CRM] Contact not found");
        
        // Create Account
        racrm_log("[CRM] Creating account");
        $account_id = racrm_create_account([
            'Account_Name' => $full_name,
            'Phone'        => $phone,
            'Billing_Street' => $address,
            'Billing_City'   => $city,
            'Billing_State'  => $state,
            'Billing_Code'   => $postcode,
            'Billing_Country'=> $country,
        ]);

        if ($account_id) {
            // Create Contact
            racrm_log("[CRM] Creating contact");
            racrm_create_contact([
                'First_Name'   => $first_name,
                'Last_Name'    => $last_name,
                'Email'        => $email,
                'Phone'        => $phone,
                'Account_Name' => [
                    'id' => $account_id
                ]
            ]);
        }
    }

    if ($account_id) {
        racrm_log("[CRM] Attaching account to deal: " . $account_id);
    }

    // 2. Map Payment Option
    $payment_method = $order->get_payment_method();
    $payment_option = '';
    
    $payment_mapping = [
        'bacs'    => 'EFT - Direct Payment to Right Air',
        'ozow'    => 'Ozow',
        'payfast' => 'PayFast - Credit Card Payments',
    ];

    if (isset($payment_mapping[$payment_method])) {
        $payment_option = $payment_mapping[$payment_method];
    }

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
        'Payment_Option' => $payment_option,
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

    racrm_log("📦 CRM Payload for Order #{$order_id}: " . json_encode($deal_payload));

    // 5. Send to Zoho
    $response = racrm_api_post('/Deals', $deal_payload);

    if (!$response) {
        racrm_log("❌ CRM API Request failed for Order #{$order_id}.");
        return new WP_Error('api_failed', 'CRM API Request failed. Check logs.');
    }

    // 6. Handle Response
    if (!empty($response['data'][0]['code']) && $response['data'][0]['code'] === 'SUCCESS') {
        $deal_id = $response['data'][0]['details']['id'];
        
        // Save to order meta
        $order->update_meta_data('_racrm_deal_id', $deal_id);
        $order->save();

        racrm_log("✅ CRM Deal created successfully for Order #{$order_id}. Deal ID: {$deal_id}");

        return [
            'id'        => $deal_id,
            'Deal_Name' => 'Order | WC Order #' . $order_id
        ];
    } else {
        $error_json = json_encode($response);
        racrm_log("❌ CRM Validation/Creation failed for Order #{$order_id}. Response: " . $error_json);
        $error_msg = isset($response['data'][0]['message']) ? $response['data'][0]['message'] : 'Unknown CRM error';
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
