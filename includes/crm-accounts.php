<?php
/**
 * Accounts Module for Right Air CRM
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create a new CRM account
 *
 * @param array $account_data
 * @return string|bool Account ID on success, false on failure.
 */
function racrm_create_account($account_data) {
    if (empty($account_data['Account_Name'])) {
        racrm_log("[CRM] Account creation failed: Account_Name is required.");
        return false;
    }

    racrm_log("[CRM] Creating Account: " . $account_data['Account_Name']);

    $payload = [
        'data' => [$account_data]
    ];

    racrm_log("[CRM] Account Payload: " . json_encode($payload));

    $response = racrm_api_post('/Accounts', $payload);

    racrm_log("[CRM] Account Create Response: " . json_encode($response));

    if (!empty($response['data'][0]['code']) && $response['data'][0]['code'] === 'SUCCESS') {
        $account_id = $response['data'][0]['details']['id'];
        racrm_log("[CRM] Account created: " . $account_id);
        return $account_id;
    }

    $error = json_encode($response);
    racrm_log("[CRM] Account creation failed: " . $error);
    return false;
}

/**
 * Extract Account ID from a Contact record
 *
 * @param array $contact
 * @return string|bool Account ID or false
 */
function racrm_get_account_from_contact($contact) {
    if (!empty($contact['Account_Name']['id'])) {
        $account_id = $contact['Account_Name']['id'];
        racrm_log("[CRM] Account found: " . $account_id);
        return $account_id;
    }

    return false;
}
