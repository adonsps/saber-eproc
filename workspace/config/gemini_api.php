<?php
// config/gemini_api.php

require_once __DIR__ . '/../../includes/gemini_guard.php';

// One Gemini API key per feature group, read from env — see .env.example.
function gemini_key_po_taxonomy(): string    { return trim((string) getenv('AI_PO_TAXONOMY_API_KEY')); }
function gemini_key_ticket_assign(): string  { return trim((string) getenv('AI_TICKET_ASSIGN_API_KEY')); }
function gemini_key_sku(): string            { return trim((string) getenv('AI_SKU_API_KEY')); }
function gemini_key_vendor_suggest(): string { return trim((string) getenv('AI_VENDOR_SUGGEST_API_KEY')); }
function gemini_key_sla_chat(): string       { return trim((string) getenv('AI_SLA_CHAT_API_KEY')); }
function gemini_key_admin_misc(): string     { return trim((string) getenv('AI_ADMIN_MISC_API_KEY')); }

function call_gemini_api($prompt, $maxRetries = 3, $apiKey = '') {
    $apiKey = trim((string) $apiKey);
    if ($apiKey === '') {
        throw new Exception('Gemini API key not configured for this feature. Set the matching AI_*_API_KEY env var.');
    }
    // You can switch this to 'gemini-1.5-flash' if 2.5 continues to be too busy
    // The key travels in a header, not the URL, so it can never end up in a
    // proxy/access log, a cURL error string or a stack trace.
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    $data = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ],
        "generationConfig" => [
            "responseMimeType" => "application/json"
        ]
    ];

    $attempt = 0;
    
    while ($attempt < $maxRetries) {
        $attempt++;

        // Spend guard: per-key budget + circuit breaker. Every retry counts, so a
        // 503 storm can no longer multiply into unbounded calls.
        $guard = gemini_guard_allow($apiKey);
        if (!$guard['ok']) {
            throw new Exception('Gemini temporarily paused for this feature (' . $guard['reason'] . '); retry in ' . $guard['retry_after'] . 's.');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        gemini_guard_report($apiKey, $httpCode);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        $responseData = json_decode($response, true);
        
        // If successful, return the text immediately
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            return $responseData['candidates'][0]['content']['parts'][0]['text'];
        } 
        
        // 402 / 403 / 429 are never retried: the guard has just paused the key, and
        // hammering Google while it says "payment required" or "slow down" is exactly
        // how the Oct 2026 request storm built up.
        if (in_array($httpCode, [402, 403, 429], true)) {
            throw new Exception("Gemini API refused the request (HTTP $httpCode); the key is paused for a while.");
        }

        // If we get a 503 (Server Busy) error, wait and try again
        if (isset($responseData['error']['code']) && $responseData['error']['code'] == 503) {
            if ($attempt < $maxRetries) {
                sleep(3); // Wait 3 seconds before knocking on Google's door again
                continue; 
            }
        }
        
        // If it's a different error, or we ran out of retries, show the error
        $rawError = htmlspecialchars(print_r($response, true));
        throw new Exception("API Error after $attempt attempts. Google said: <br><pre class='bg-gray-100 p-4 mt-2 rounded text-xs'>" . $rawError . "</pre>");
    }
}
?>