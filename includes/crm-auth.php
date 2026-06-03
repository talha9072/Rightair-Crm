<?php
/**
 * Authentication Handling for Right Air CRM (Zoho OAuth)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get current access token, refreshing if expired
 *
 * @return string|bool Access token or false on failure.
 */
function racrm_get_access_token() {
    $access_token = get_option('racrm_access_token');
    $expiry       = get_option('racrm_token_expiry', 0);

    // Buffer of 60 seconds for safety
    if (empty($access_token) || time() >= ($expiry - 60)) {
        racrm_log("🔄 Access token missing or expired. Refreshing...");
        return racrm_refresh_access_token();
    }

    return $access_token;
}

/**
 * Refresh the Zoho CRM access token using the refresh token
 *
 * @return string|bool New access token or false on failure.
 */
function racrm_refresh_access_token() {
    $client_id     = get_option('racrm_client_id');
    $client_secret = get_option('racrm_client_secret');
    $refresh_token = get_option('racrm_refresh_token');

    if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
        racrm_log("❌ Token refresh failed: Missing credentials (Client ID, Secret, or Refresh Token).");
        return false;
    }

    $token_url = 'https://accounts.zoho.com/oauth/v2/token';
    
    $args = [
        'body' => [
            'grant_type'    => 'refresh_token',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
        ],
        'timeout'    => 30,
        'sslverify'  => true,
    ];

    $response = wp_remote_post($token_url, $args);

    if (is_wp_error($response)) {
        racrm_log("❌ Token refresh HTTP error: " . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($body['access_token'])) {
        $new_token = $body['access_token'];
        $expires_in = isset($body['expires_in']) ? intval($body['expires_in']) : 3600;
        
        update_option('racrm_access_token', $new_token);
        update_option('racrm_token_expiry', time() + $expires_in);
        update_option('racrm_token_last_refresh', time());

        racrm_log("✅ Access token refreshed successfully. Expires in {$expires_in}s.");
        return $new_token;
    } else {
        $error_msg = isset($body['error']) ? $body['error'] : 'Unknown error';
        racrm_log("❌ Token refresh failed. Response: " . print_r($body, true));
        return false;
    }
}
