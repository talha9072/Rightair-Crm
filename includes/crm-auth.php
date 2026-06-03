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

    // Buffer of 300 seconds (5 minutes) for safety
    $buffer = 300;
    $time_to_expiry = intval($expiry) - time();

    if (empty($access_token)) {
        racrm_log("🔍 Access token missing. Triggering auto-refresh...");
        return racrm_refresh_access_token();
    }

    if ($time_to_expiry <= $buffer) {
        racrm_log("⏰ Token expired or expires soon ({$time_to_expiry}s remaining). Triggering auto-refresh...");
        return racrm_refresh_access_token();
    }

    racrm_log("🔑 Access token is still valid ({$time_to_expiry}s remaining).");
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
    
    $body_params = [
        'grant_type'    => 'refresh_token',
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
    ];

    $args = [
        'body'       => http_build_query($body_params),
        'headers'    => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
        'timeout'    => 30,
        'sslverify'  => true,
    ];

    racrm_log("racrm_refresh_access_token() called. client_id=" . esc_html(substr($client_id, 0, 10)) . "..., refresh_token_present=" . (!empty($refresh_token) ? 'yes' : 'no'));
    racrm_log("Token refresh request URL: " . $token_url);
    racrm_log("Token refresh request parameters: " . print_r($body_params, true));

    $response = wp_remote_post($token_url, $args);

    if (is_wp_error($response)) {
        racrm_log("❌ Token refresh HTTP error: " . $response->get_error_message());
        racrm_log("Full WP_Error: " . print_r($response, true));
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    racrm_log("🔁 Token endpoint responded with HTTP code: " . var_export($response_code, true));
    racrm_log("🔁 Token endpoint response body: " . $response_body);

    $body = json_decode($response_body, true);

    if (!empty($body['access_token'])) {
        $new_token = $body['access_token'];
        $expires_in = isset($body['expires_in']) ? intval($body['expires_in']) : 3600;

        racrm_log("🔐 Access token received from Zoho (before save): " . $new_token);

        $saved_token_result = update_option('racrm_access_token', $new_token);
        $saved_expiry_result = update_option('racrm_token_expiry', time() + $expires_in);
        $saved_last_refresh_result = update_option('racrm_token_last_refresh', time());

        racrm_log("update_option('racrm_access_token') returned: " . ($saved_token_result ? 'true' : 'false'));
        racrm_log("update_option('racrm_token_expiry') returned: " . ($saved_expiry_result ? 'true' : 'false'));
        racrm_log("update_option('racrm_token_last_refresh') returned: " . ($saved_last_refresh_result ? 'true' : 'false'));

        $stored_token = get_option('racrm_access_token');
        $stored_expiry = get_option('racrm_token_expiry');

        racrm_log("Saved access token (from DB): " . var_export($stored_token, true));
        racrm_log("Saved token expiry (from DB): " . var_export($stored_expiry, true));

        $is_connected_after_save = !empty($stored_token) && intval($stored_expiry) > time();
        racrm_log("Connection status after save: " . ($is_connected_after_save ? 'connected' : 'not connected') . " (expiry={$stored_expiry}, now=" . time() . ")");

        racrm_log("✅ Access token refreshed successfully. Expires in {$expires_in}s.");
        return $new_token;
    } else {
        $error_msg = isset($body['error']) ? $body['error'] : 'Unknown error';
        racrm_log("❌ Token refresh failed. Response decoded: " . print_r($body, true));
        return false;
    }
}
