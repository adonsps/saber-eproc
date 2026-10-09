<?php
// workspace/automation/test_gemini_guard.php
//
// No network, no DB, no Gemini: exercises includes/gemini_guard.php against a
// throwaway temp dir. Run:  php workspace/automation/test_gemini_guard.php

putenv('GEMINI_GUARD_DIR=' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gg_test_' . bin2hex(random_bytes(4)));
putenv('GEMINI_GUARD_PER_MIN=3');
putenv('GEMINI_GUARD_PER_DAY=5');
require __DIR__ . '/../../includes/gemini_guard.php';

$fail = 0;
function check(string $name, bool $cond): void {
    global $fail;
    echo ($cond ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$cond) $fail++;
}

$t = 1_800_000_000; // fixed instant, minute-aligned
$t -= $t % 86400;   // day-aligned too

// 1. per-minute budget (web)
$r = [];
for ($i = 0; $i < 4; $i++) $r[] = gemini_guard_allow('key-A', $t, false)['ok'];
check('3 calls allowed in a minute, 4th refused', $r === [true, true, true, false]);
check('refusal says per_minute', gemini_guard_allow('key-A', $t, false)['reason'] === 'per_minute');
check('next minute is allowed again', gemini_guard_allow('key-A', $t + 60, false)['ok'] === true);

// 2. per-day budget (web): 3 + 1 used so far on this day; 1 more allowed, then refused
check('5th call of the day allowed', gemini_guard_allow('key-A', $t + 120, false)['ok'] === true);
check('6th call of the day refused (per_day)', gemini_guard_allow('key-A', $t + 180, false)['reason'] === 'per_day');
check('new day resets', gemini_guard_allow('key-A', $t + 86400, false)['ok'] === true);

// 3. keys are independent
check('other key unaffected', gemini_guard_allow('key-B', $t, false)['ok'] === true);

// 4. circuit breaker
gemini_guard_report('key-C', 200, $t);
check('200 does not trip', gemini_guard_allow('key-C', $t, false)['ok'] === true);
gemini_guard_report('key-C', 429, $t);
$g = gemini_guard_allow('key-C', $t + 10, false);
check('429 trips for 5 min', !$g['ok'] && $g['reason'] === 'breaker' && $g['retry_after'] === 290);
check('allowed again after 5 min', gemini_guard_allow('key-C', $t + 301, false)['ok'] === true);
gemini_guard_report('key-D', 402, $t);
check('402 trips for 30 min', gemini_guard_allow('key-D', $t + 1799, false)['ok'] === false
    && gemini_guard_allow('key-D', $t + 1801, false)['ok'] === true);
gemini_guard_report('key-E', 503, $t);
check('503 does not trip', gemini_guard_allow('key-E', $t, false)['ok'] === true);

// 5. CLI: exempt from budgets, still honours the breaker
$all = true;
for ($i = 0; $i < 20; $i++) $all = $all && gemini_guard_allow('key-F', $t, true)['ok'];
check('CLI is not budget-limited', $all);
gemini_guard_report('key-F', 402, $t);
check('CLI still stopped by the breaker', gemini_guard_allow('key-F', $t + 5, true)['ok'] === false);

// 6. per-user throttle
$u = [];
for ($i = 0; $i < 4; $i++) $u[] = gemini_guard_user('doc', '42', 3, 600, $t);
check('user: 3 allowed, 4th refused', $u === [true, true, true, false]);
check('user: another user unaffected', gemini_guard_user('doc', '43', 3, 600, $t) === true);
check('user: another bucket unaffected', gemini_guard_user('biz', '42', 3, 600, $t) === true);
check('user: window expiry resets', gemini_guard_user('doc', '42', 3, 600, $t + 601) === true);

// 7. keys are never written to disk
$leak = false;
foreach (glob(gemini_guard_dir() . '/*') as $f) {
    if (strpos(file_get_contents($f), 'key-') !== false || strpos(basename($f), 'key-') !== false) $leak = true;
}
check('no raw key in state files or names', !$leak);

// 8. unusable state dir fails open
putenv('GEMINI_GUARD_DIR=/proc/definitely/not/writable');
check('broken state dir fails open', gemini_guard_allow('key-Z', $t, false)['ok'] === true
    && gemini_guard_user('x', '1', 1, 60, $t) === true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
