<?php

/**
 * Simple OpenClaw connectivity test
 */

header('Content-Type: application/json');

$openclaw_url = 'http://172.17.76.22:18789/v1/chat/completions';
$auth_token = 'rawwire-local-dev-2025';

echo "Testing connectivity to: {$openclaw_url}\n\n";

// Simple test request
$body = json_encode([
    'model' => 'venice/llama-3.3-70b',
    'messages' => [['role' => 'user', 'content' => 'Say hello']],
    'max_tokens' => 20,
]);

$ch = curl_init($openclaw_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $auth_token,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$errno = curl_errno($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo json_encode([
    'response' => $response ? json_decode($response, true) : null,
    'error' => $error,
    'errno' => $errno,
    'http_code' => $info['http_code'],
    'connect_time' => $info['connect_time'],
    'total_time' => $info['total_time'],
], JSON_PRETTY_PRINT);
