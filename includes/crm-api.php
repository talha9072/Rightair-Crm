<?php
/**
 * API Helper Functions for Right Air CRM
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generic API Request Wrapper
 *
 * @param string $method  HTTP Method (GET, POST, PUT, DELETE).
 * @param string $endpoint API Endpoint after domain (e.g., /Contacts).
 * @param array  $body    Request body for POST/PUT.
 * @return array|bool     Parsed JSON response or false on failure.
 */
function racrm_api_request($method, $endpoint, $body = []) {
    $api_domain = get_option('racrm_api_domain', 'https://www.zohoapis.com');
    // Normalize endpoint (ensure starts with /crm/v2/ or similar if needed, user might just pass module)
    // Actually Zoho CRM V2 usually looks like https://www.zohoapis.com/crm/v2/Contacts
    if (strpos($endpoint, 'http') !== 0) {
        $url = rtrim($api_domain, '/') . '/crm/v2/' . ltrim($endpoint, '/');
    } else {
        $url = $endpoint;
    }

    $token = racrm_get_access_token();
    if (!$token) {
        racrm_log("❌ API Request failed: Could not obtain access token.");
        return false;
    }

    racrm_log("✅ API Request using token: " . substr($token, 0, 10) . "...");

    $args = [
        'method'    => $method,
        'headers'   => [
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'timeout'   => 30,
        'sslverify' => true,
    ];

    if (!empty($body)) {
        $args['body'] = json_encode($body);
    }

    racrm_log("🌐 API Request: {$method} {$url}");
    if (!empty($body)) {
        racrm_log("📦 Request Body: " . json_encode($body));
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        racrm_log("❌ API Request HTTP Error: " . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    racrm_log("📩 API Response Code: {$code}");
    racrm_log("📩 API Response Body: " . $response_body);

    if ($code >= 200 && $code < 300) {
        return $data;
    } else {
        racrm_log("❌ API Request failed with status {$code}. Response: " . $response_body);
        return $data; // Return data anyway as it might contain specific Zoho error messages
    }
}

/**
 * Performing a GET request
 */
function racrm_api_get($endpoint) {
    return racrm_api_request('GET', $endpoint);
}

/**
 * Performing a POST request
 */
function racrm_api_post($endpoint, $body = []) {
    return racrm_api_request('POST', $endpoint, $body);
}

/**
 * Performing a PUT request
 */
function racrm_api_put($endpoint, $body = []) {
    return racrm_api_request('PUT', $endpoint, $body);
}
