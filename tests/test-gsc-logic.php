<?php
/**
 * Test GSC URL Normalization Logic
 * Run this to verify how the plugin handles different GSC property formats.
 */

function mock_trailingslashit($string) {
    return rtrim($string, '/\\') . '/';
}

function normalize_property_url_test($url) {
    $url = trim($url);
    if (strpos($url, 'sc-domain:') !== 0) {
        $url = mock_trailingslashit($url);
    }
    return $url;
}

$test_cases = [
    'https://moulin-a-cafe.org' => 'https://moulin-a-cafe.org/',
    'https://moulin-a-cafe.org/' => 'https://moulin-a-cafe.org/',
    'sc-domain:moulin-a-cafe.org' => 'sc-domain:moulin-a-cafe.org',
    ' sc-domain:moulin-a-cafe.org ' => 'sc-domain:moulin-a-cafe.org',
    'http://v2.example.com' => 'http://v2.example.com/',
];

echo "--- GSC Property URL Normalization Tests (v2) ---\n";
foreach ($test_cases as $input => $expected) {
    $result = normalize_property_url_test($input);
    $status = ($result === $expected) ? "✅ PASS" : "❌ FAIL (Got: $result)";
    echo "Input: $input\nExpected: $expected\nResult: $status\n\n";
}
