<?php
// includes/fonnte.php

// 1. Set your token here ONCE
if (!defined('FONNTE_TOKEN')) {
    define('FONNTE_TOKEN', 'DKvem5w8wnHCkuvDjJqL');
}

// Network limits — a hung provider must never hold a web request open.
if (!defined('FONNTE_CONNECT_TIMEOUT')) { define('FONNTE_CONNECT_TIMEOUT', 10); }
if (!defined('FONNTE_TIMEOUT'))         { define('FONNTE_TIMEOUT', 20); }

/**
 * Replace the API token (and anything that looks like one) before it reaches a log.
 */
function fonnteRedact($text) {
    $text = (string)$text;
    if (defined('FONNTE_TOKEN') && FONNTE_TOKEN !== '') {
        $text = str_replace(FONNTE_TOKEN, '***REDACTED***', $text);
    }
    return $text;
}

/**
 * Append a diagnostic line to the server-side WA log. Never echoes to the client.
 * The message body is not logged (it carries requestor names and worklist tokens);
 * only its hash and length are recorded.
 */
function fonnteLog(array $ctx) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . fonnteRedact(json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    @file_put_contents(__DIR__ . '/wa_send_debug.txt', $line, FILE_APPEND);
}

/**
 * Centralized WhatsApp send.
 *
 * Returns a structured result. Callers that predate this signature ignore the
 * return value, so behaviour for them is unchanged apart from the timeouts.
 *
 *   ok               bool   true ONLY when the provider confirmed acceptance
 *   http_code        int
 *   curl_errno       int
 *   curl_error       string
 *   provider_status  bool|null   the provider's own `status` field, when present
 *   reason           string      short, safe explanation for the operator/UI
 *
 * The raw provider body is never returned — it is written to the server-side log
 * only, redacted. A transport-level 200 is NOT treated as success: Fonnte reports
 * rejected sends (invalid token, bad number, quota) with status=false and HTTP 200.
 */
function sendFonnteWA($target, $message) {
    return fonnteDispatch($target, $message, ['countryCode' => '62'], 'wa_send');
}

/**
 * Send to a WhatsApp GROUP and say whether the provider accepted it.
 *
 * Same endpoint and the same success test as sendFonnteWA() -- only the target
 * differs (a Fonnte group ID, e.g. 1203...@g.us) and countryCode is omitted,
 * since it only applies to phone-number targets.
 *
 * Distinct from the older sendFonnteGroup() below, which hands back the raw
 * provider body: a caller that records "this reminder has been sent" has to be
 * able to tell acceptance from an HTTP 200 carrying status=false, or it will
 * mark reminders as delivered that were rejected.
 */
function sendFonnteGroupChecked($groupId, $message) {
    return fonnteDispatch($groupId, $message, [], 'wa_group_send');
}

/**
 * Shared transport behind sendFonnteWA() and sendFonnteGroupChecked().
 *
 * A transport-level 200 is NOT treated as success: Fonnte reports rejected sends
 * (invalid token, bad number, quota) with status=false and HTTP 200.
 */
function fonnteDispatch($target, $message, array $extraFields, $event) {
    $result = [
        'ok'              => false,
        'http_code'       => 0,
        'curl_errno'      => 0,
        'curl_error'      => '',
        'provider_status' => null,
        'reason'          => '',
    ];

    if (!function_exists('curl_init')) {
        $result['reason'] = 'cURL extension is not available on this server';
        fonnteLog(['event' => $event, 'ok' => false, 'reason' => $result['reason']]);
        return $result;
    }
    if (!defined('FONNTE_TOKEN') || FONNTE_TOKEN === '') {
        $result['reason'] = 'Fonnte token is not configured';
        fonnteLog(['event' => $event, 'ok' => false, 'reason' => $result['reason']]);
        return $result;
    }

    $target  = trim((string)$target);
    $message = (string)$message;
    if ($target === '') {
        $result['reason'] = 'empty WhatsApp target';
        fonnteLog(['event' => $event, 'ok' => false, 'reason' => $result['reason']]);
        return $result;
    }

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.fonnte.com/send',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => array("Authorization: " . FONNTE_TOKEN),
      CURLOPT_POST => true,
      CURLOPT_CONNECTTIMEOUT => FONNTE_CONNECT_TIMEOUT,
      CURLOPT_TIMEOUT => FONNTE_TIMEOUT,
      CURLOPT_POSTFIELDS => array_merge(array(
          'target' => $target,
          'message' => $message,
      ), $extraFields),
    ));

    $body                 = curl_exec($curl);
    $result['curl_errno'] = curl_errno($curl);
    $result['curl_error'] = curl_error($curl);
    $result['http_code']  = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $log = [
        'event'      => $event,
        'target'     => $target,
        'msg_sha256' => hash('sha256', $message),
        'msg_len'    => strlen($message),
        'http_code'  => $result['http_code'],
        'curl_errno' => $result['curl_errno'],
    ];

    if ($result['curl_errno'] !== 0 || $body === false) {
        $result['reason'] = 'transport error contacting Fonnte'
            . ($result['curl_error'] !== '' ? ': ' . $result['curl_error'] : '');
        $log['ok']     = false;
        $log['reason'] = $result['reason'];
        fonnteLog($log);
        return $result;
    }

    // Raw body goes to the server log only, truncated and redacted.
    $log['provider_body'] = substr((string)$body, 0, 500);

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        $result['reason'] = 'Fonnte returned HTTP ' . $result['http_code'];
        $log['ok']     = false;
        $log['reason'] = $result['reason'];
        fonnteLog($log);
        return $result;
    }

    $parsed = json_decode((string)$body, true);
    if (!is_array($parsed)) {
        $result['reason'] = 'Fonnte returned an unrecognised (non-JSON) response';
        $log['ok']     = false;
        $log['reason'] = $result['reason'];
        fonnteLog($log);
        return $result;
    }

    $status = $parsed['status'] ?? null;
    $result['provider_status'] = is_bool($status) ? $status : null;
    $accepted = ($status === true || $status === 1 || $status === '1' || $status === 'true');

    if (!$accepted) {
        // Fonnte puts the human-readable cause in `reason`, occasionally `detail`.
        $why = (string)($parsed['reason'] ?? $parsed['detail'] ?? 'provider rejected the message');
        $result['reason'] = 'Fonnte rejected the message: ' . substr($why, 0, 200);
        $log['ok']     = false;
        $log['reason'] = $result['reason'];
        fonnteLog($log);
        return $result;
    }

    $result['ok']     = true;
    $result['reason'] = (string)($parsed['detail'] ?? 'accepted by provider');
    $log['ok']        = true;
    fonnteLog($log);
    return $result;
}

// Send to a WhatsApp GROUP. Same token/endpoint as sendFonnteWA(), but the
//    target is a Fonnte group ID (e.g. 1203...@g.us) and countryCode is omitted
//    (it only applies to phone-number targets). Returns the raw API response.
function sendFonnteGroup($groupId, $message) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.fonnte.com/send',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => array("Authorization: " . FONNTE_TOKEN),
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => array(
          'target'  => $groupId,
          'message' => $message,
      ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
}
?>
