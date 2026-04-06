<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sil_Gsc_Handler
{
    private $client_id;
    private $client_secret;
    private $redirect_uri;

    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const API_BASE_URL = 'https://www.googleapis.com/webmasters/v3/sites/';

    public function __construct()
    {
        $this->client_id = get_option('sil_gsc_client_id');
        $this->client_secret = get_option('sil_gsc_client_secret');
        $this->redirect_uri = admin_url('admin-ajax.php?action=sil_gsc_oauth_callback');
    }

    /**
     * Normalize the property URL for Google Search Console.
     * Special case: sc-domain properties MUST NOT have a trailing slash.
     */
    private function normalize_property_url($url) {
        $url = trim($url);
        if (strpos($url, 'sc-domain:') !== 0) {
            $url = trailingslashit($url);
        }
        return $url;
    }

    /**
     * Generate the OAuth authorization URL.
     */
    public function get_auth_url()
    {
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent' // Force to get refresh token
        ];

        return add_query_arg($params, self::AUTH_URL);
    }

    /**
     * Exchange authorization code for tokens.
     */
    public function fetch_access_token_with_auth_code($code)
    {
        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'code' => $code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri' => $this->redirect_uri,
                'grant_type' => 'authorization_code'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            throw new Exception($data['error_description'] ?? $data['error']);
        }

        if (isset($data['access_token'])) {
            $data['created'] = time();
            return $data;
        }

        throw new Exception('Invalid response from Google OAuth server.');
    }

    /**
     * Refresh the access token using the refresh token.
     */
    public function refresh_access_token($refresh_token)
    {
        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'refresh_token' => $refresh_token,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'refresh_token'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            $error_desc = $data['error_description'] ?? $data['error'];
            if ($data['error'] === 'invalid_grant' || strpos($error_desc, 'expired') !== false || strpos($error_desc, 'revoked') !== false) {
                delete_option('sil_gsc_oauth_tokens');
                throw new Exception('La connexion à Google a expiré ou été révoquée par sécurité. Veuillez vous reconnecter depuis l\'onglet "Réglages GSC".');
            }
            throw new Exception($error_desc);
        }

        if (isset($data['access_token'])) {
            $data['created'] = time();
            return $data;
        }

        throw new Exception('Invalid response during token refresh.');
    }

    /**
     * Ensure we have a valid access token. Refresh if needed.
     */
    public function get_valid_access_token()
    {
        $tokens = get_option('sil_gsc_oauth_tokens');

        if (empty($tokens) || !is_array($tokens)) {
            throw new Exception("Aucun token disponible.");
        }

        // Check if token is expired (giving a 60-second buffer)
        $created = $tokens['created'] ?? 0;
        $expires_in = $tokens['expires_in'] ?? 3600;

        if (time() >= ($created + $expires_in - 60)) {
            if (empty($tokens['refresh_token'])) {
                throw new Exception("Token expiré et aucun refresh_token disponible.");
            }

            // Attempt to refresh
            $new_tokens = $this->refresh_access_token($tokens['refresh_token']);
            // The refresh response doesn't always contain the refresh_token. Preserve the old one.
            if (!isset($new_tokens['refresh_token'])) {
                $new_tokens['refresh_token'] = $tokens['refresh_token'];
            }

            update_option('sil_gsc_oauth_tokens', $new_tokens);
            return $new_tokens['access_token'];
        }

        return $tokens['access_token'];
    }

    /**
     * Post a query to the Search Analytics API.
     */
    public function query_search_analytics($site_url, $request_body)
    {
        $access_token = $this->get_valid_access_token();
        $site_url = $this->normalize_property_url($site_url);
        $url = self::API_BASE_URL . urlencode($site_url) . '/searchAnalytics/query';

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($request_body),
            'timeout' => 45 // GSC queries can take time
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($response_code !== 200) {
            $error_msg = $data['error']['message'] ?? 'API Error HTTP ' . $response_code;
            if ($response_code === 401) {
                delete_option('sil_gsc_oauth_tokens');
                throw new Exception('L\'accès API a été refusé (Token invalide ou expiré). Veuillez vous reconnecter depuis l\'onglet "Réglages GSC".');
            }
            throw new Exception($error_msg);
        }

        return $data;
    }

    /**
     * Inspect a specific URL using the URL Inspection API.
     */
    public function inspect_url($site_url, $inspection_url)
    {
        $access_token = $this->get_valid_access_token();
        $site_url = $this->normalize_property_url($site_url);
        $url = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

        $request_body = [
            'inspectionUrl' => $inspection_url,
            'siteUrl' => $site_url,
            'languageCode' => get_locale() // Optional but good practice
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($request_body),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($response_code !== 200) {
            $error_msg = $data['error']['message'] ?? 'API Error HTTP ' . $response_code;
            if ($response_code === 401) {
                delete_option('sil_gsc_oauth_tokens');
                throw new Exception('L\'accès API a été refusé (Token invalide ou expiré). Veuillez vous reconnecter depuis l\'onglet "Réglages GSC".');
            }
            throw new Exception($error_msg);
        }

        return $data;
    }
}
