<?php
// ajax_check_vendor_duplicate.php
//
// "Is this company already in the register?" — asked by register.php the moment
// the vendor submits, before an account is created.
//
// Registration only ever de-duplicated on `users.email`, so one company could
// sign up once per mailbox it owned; the register accumulated 31 clusters of
// the same real company that way. This endpoint asks the question that actually
// matters, and hands the answer to the vendor to confirm.
//
// TWO TIERS, because the two matching signals do not deserve the same power
// (see includes/vendor_name_similarity.php for how each is computed):
//
//   block — character similarity >= 95%. A typo, a dot, a case difference:
//           "PT. ARSINSI PRIMA CIPTA" vs "PT ARSINSI PRIMA CIPTA". Precise
//           enough to stop a registration on, once the vendor confirms.
//
//   warn  — same distinctive words, at most one extra: "PT MITRA PRINTING" vs
//           "CV MITRA ABADI PRINTING". Character similarity structurally cannot
//           see this (different lengths cap that pair at 85%), but the signal is
//           only about half precise — it also links "MAKMUR JAYA" to "PT Yoga
//           Jaya Makmur", which are unrelated companies. So it informs the
//           vendor and never blocks: a false positive here costs nothing, while
//           a false block would wall a legitimate new vendor out of the portal.
//
// The response carries the registered company name and a MASKED email — enough
// for a vendor to recognise their own mailbox, not enough to harvest addresses.
//
// PUBLIC: no login, by necessity — the caller is registering. Writes nothing.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/vendor_name_similarity.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Enumeration guard. The form needs one call per submit, so a real vendor never
 *  approaches this; a script walking the register hits it quickly. */
const DUP_MAX_CHECKS     = 40;
const DUP_WINDOW_SECONDS = 600;

function dup_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dup_respond(['ok' => false, 'error' => 'method'], 405);
}

$companyName = trim((string) ($_POST['company_name'] ?? ''));
if ($companyName === '' || mb_strlen($companyName) > 200) {
    // Nothing to match on. Registration proceeds; the form's own `required`
    // handles an empty company name.
    dup_respond(['ok' => true, 'level' => 'none', 'matches' => []]);
}

// --- throttle (per session, sliding window) ---------------------------------
$now = time();
$hits = array_values(array_filter(
    (array) ($_SESSION['dup_check_hits'] ?? []),
    static fn($t) => $now - (int) $t < DUP_WINDOW_SECONDS
));
if (count($hits) >= DUP_MAX_CHECKS) {
    // Fail OPEN: a throttled caller is told "no match" rather than being blocked
    // from registering. The gate is a duplicate check, not an access control.
    $_SESSION['dup_check_hits'] = $hits;
    dup_respond(['ok' => true, 'level' => 'none', 'matches' => [], 'throttled' => true]);
}
$hits[] = $now;
$_SESSION['dup_check_hits'] = $hits;

// --- match ------------------------------------------------------------------
if (!($conn instanceof mysqli)) {
    // The register must not become unreachable because this lookup failed.
    dup_respond(['ok' => true, 'level' => 'none', 'matches' => []]);
}

$result = vendor_duplicate_check($conn, $companyName);

// The client echoes this back as `duplicate_ack` when the vendor answers "this
// is a different company", so an acknowledgement can't carry over to a name the
// vendor edited afterwards. It is a correctness check, not a security boundary —
// the vendor is always free to answer "not mine".
$result['ack'] = vendor_duplicate_ack_token($companyName);

dup_respond(['ok' => true] + $result);
