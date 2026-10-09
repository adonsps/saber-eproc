<?php
// workspace/includes/vendor_eval_v2_fonnte.php
//
// Vendor Evaluation v2 — dedicated Fonnte WhatsApp sender.
//
// Vendor Eval reminders use their own Fonnte token/device, never the shared
// FONNTE_TOKEN constant that RFQ and other features use (includes/fonnte.php).
// If no VE2 token is configured, ve2_send_fonnte_wa() fails closed — it never
// falls back to the shared token. Email sending is unaffected by this file.
//
// This file is a pure library: including it performs no network calls, no
// token lookup, no side effects. Everything happens inside the functions below.

if (!defined('VE2_FONNTE_CONNECT_TIMEOUT')) { define('VE2_FONNTE_CONNECT_TIMEOUT', 10); }
if (!defined('VE2_FONNTE_TIMEOUT'))         { define('VE2_FONNTE_TIMEOUT', 20); }

/** Local, untracked config file — see workspace/config/vendor_eval_fonnte.local.php.example. */
if (!defined('VE2_FONNTE_LOCAL_CONFIG')) {
    define('VE2_FONNTE_LOCAL_CONFIG', __DIR__ . '/../config/vendor_eval_fonnte.local.php');
}

/** Load the local VE2 Fonnte config array, or [] if the file is absent/invalid. */
function ve2_fonnte_local_config(): array
{
    if (!is_file(VE2_FONNTE_LOCAL_CONFIG)) {
        return [];
    }
    $cfg = require VE2_FONNTE_LOCAL_CONFIG;
    return is_array($cfg) ? $cfg : [];
}

/**
 * Resolve the Vendor-Eval-specific Fonnte token.
 *
 * Priority: getenv('VE2_FONNTE_TOKEN') then the local config file's 'token' key.
 * Returns '' when neither source has a non-empty token — callers must treat
 * that as "not configured" and fail closed. Never reads the shared
 * FONNTE_TOKEN constant.
 */
function ve2_fonnte_token(): string
{
    $env = getenv('VE2_FONNTE_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $cfg = ve2_fonnte_local_config();
    if (isset($cfg['token']) && is_string($cfg['token']) && trim($cfg['token']) !== '') {
        return trim($cfg['token']);
    }

    return '';
}

/** Informational only — not sent to the Fonnte API (the token alone selects the device). */
function ve2_fonnte_sender_number(): string
{
    $cfg = ve2_fonnte_local_config();
    return isset($cfg['sender_number']) ? trim((string)$cfg['sender_number']) : '';
}

/** Mask a phone number for logs: 6281*****734. Independent copy so this file has no
 *  load-order dependency on vendor_eval_v2_sender.php's ve2_mask_phone(). */
function ve2_fonnte_mask_phone(string $wa): string
{
    $d = preg_replace('/\D+/', '', $wa);
    $n = strlen($d);
    if ($n === 0)  { return ''; }
    if ($n <= 6)   { return str_repeat('*', $n); }
    return substr($d, 0, 4) . str_repeat('*', $n - 7) . substr($d, -3);
}

/** Strip the VE2 Fonnte token from a log line, defence in depth alongside never
 *  logging it directly. */
function ve2_fonnte_redact(string $text): string
{
    $token = ve2_fonnte_token();
    if ($token !== '') {
        $text = str_replace($token, '***REDACTED***', $text);
    }
    return $text;
}

/**
 * Vendor Evaluation v2 — dedicated WhatsApp send.
 *
 * Same result contract as the shared sendFonnteWA() in includes/fonnte.php:
 *   ok               bool   true ONLY when the provider confirmed acceptance
 *   http_code        int
 *   curl_errno       int
 *   curl_error       string
 *   provider_status  bool|null
 *   reason           string   short, safe explanation for the operator/UI
 *
 * Fails closed — ok=false, reason='Vendor Eval Fonnte token is not configured.'
 * — when no VE2 token is available. Never reads or falls back to FONNTE_TOKEN.
 * The raw provider body and the token are never logged; the message is logged
 * only as a hash+length, and the target is masked.
 */
function ve2_send_fonnte_wa(string $target, string $message): array
{
    $result = [
        'ok'              => false,
        'http_code'       => 0,
        'curl_errno'      => 0,
        'curl_error'      => '',
        'provider_status' => null,
        'reason'          => '',
    ];

    $token = ve2_fonnte_token();
    if ($token === '') {
        $result['reason'] = 'Vendor Eval Fonnte token is not configured.';
        error_log('[vendor_eval_v2_fonnte] wa_send blocked: token not configured (checked VE2_FONNTE_TOKEN env and local config)');
        return $result;
    }

    if (!function_exists('curl_init')) {
        $result['reason'] = 'cURL extension is not available on this server';
        error_log('[vendor_eval_v2_fonnte] wa_send failed: ' . $result['reason']);
        return $result;
    }

    $target = trim($target);
    if ($target === '') {
        $result['reason'] = 'empty WhatsApp target';
        return $result;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token],
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => VE2_FONNTE_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => VE2_FONNTE_TIMEOUT,
        CURLOPT_POSTFIELDS => [
            'target'      => $target,
            'message'     => $message,
            'countryCode' => '62',
        ],
    ]);

    $body                 = curl_exec($curl);
    $result['curl_errno'] = curl_errno($curl);
    $result['curl_error'] = ve2_fonnte_redact((string)curl_error($curl));
    $result['http_code']  = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $log_ctx = [
        'event'      => 've2_wa_send',
        'target'     => ve2_fonnte_mask_phone($target),
        'msg_sha256' => hash('sha256', $message),
        'msg_len'    => strlen($message),
        'http_code'  => $result['http_code'],
        'curl_errno' => $result['curl_errno'],
    ];

    if ($result['curl_errno'] !== 0 || $body === false) {
        $result['reason'] = 'transport error contacting Fonnte'
            . ($result['curl_error'] !== '' ? ': ' . $result['curl_error'] : '');
        error_log('[vendor_eval_v2_fonnte] ' . json_encode($log_ctx + ['ok' => false, 'reason' => $result['reason']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $result;
    }

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        $result['reason'] = 'Fonnte returned HTTP ' . $result['http_code'];
        error_log('[vendor_eval_v2_fonnte] ' . json_encode($log_ctx + ['ok' => false, 'reason' => $result['reason']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $result;
    }

    $parsed = json_decode((string)$body, true);
    if (!is_array($parsed)) {
        $result['reason'] = 'Fonnte returned an unrecognised (non-JSON) response';
        error_log('[vendor_eval_v2_fonnte] ' . json_encode($log_ctx + ['ok' => false, 'reason' => $result['reason']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $result;
    }

    $status = $parsed['status'] ?? null;
    $result['provider_status'] = is_bool($status) ? $status : null;
    $accepted = ($status === true || $status === 1 || $status === '1' || $status === 'true');

    if (!$accepted) {
        // Fonnte puts the human-readable cause in `reason`, occasionally `detail`.
        $why = (string)($parsed['reason'] ?? $parsed['detail'] ?? 'provider rejected the message');
        $result['reason'] = 'Fonnte rejected the message: ' . substr($why, 0, 200);
        error_log('[vendor_eval_v2_fonnte] ' . json_encode($log_ctx + ['ok' => false, 'reason' => $result['reason']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $result;
    }

    $result['ok']     = true;
    $result['reason'] = (string)($parsed['detail'] ?? 'accepted by provider');
    error_log('[vendor_eval_v2_fonnte] ' . json_encode($log_ctx + ['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $result;
}
