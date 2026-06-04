<?php
/**
 * Contacts Module for Right Air CRM
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Find a CRM contact by email address
 *
 * @param string $email
 * @return array|bool Contact data or false if not found.
 */
function racrm_find_contact_by_email($email) {
    if (empty($email)) {
        return false;
    }

    racrm_log("[CRM] Searching contact by email: {$email}");

    // Using Zoho CRM Search API
    $endpoint = "/Contacts/search?email=" . urlencode($email);
    $response = racrm_api_get($endpoint);

    if (isset($response['data']) && is_array($response['data']) && count($response['data']) > 0) {
        $contact = $response['data'][0];
        racrm_log("[CRM] Contact found: " . $contact['id']);
        return $contact;
    }

    racrm_log("[CRM] Contact not found for email: {$email}");
    return false;
}

/**
 * Create a new CRM contact
 *
 * @param array $contact_data
 * @return string|bool Contact ID on success, false on failure.
 */
function racrm_create_contact($contact_data) {
    racrm_log("[CRM] Creating contact for: " . ($contact_data['Email'] ?? 'Unknown'));

    $payload = [
        'data' => [$contact_data]
    ];

    $response = racrm_api_post('/Contacts', $payload);

    if (!empty($response['data'][0]['code']) && $response['data'][0]['code'] === 'SUCCESS') {
        $contact_id = $response['data'][0]['details']['id'];
        racrm_log("[CRM] Contact created: " . $contact_id);
        return $contact_id;
    }

    $error = json_encode($response);
    racrm_log("[CRM] Contact creation failed: " . $error);
    return false;
}
