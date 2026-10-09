<?php
// workspace/pages/public_eval_v2_project.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail — New Store).
//
// Dual-team submission page: reached via ?po=...&team=ops|project, normally
// from a row on public_eval_v2_project_worklist.php's shared, no-login,
// globally-tokened worklist. See vendor_eval_v2_form2_team_engine.php for
// the full contract (two independent submission slots per PO, one per
// team; edit-in-place while the other team hasn't submitted; locked once
// both have; final grade = average, computed on read).
//
// This is a SEPARATE engine from Form 1's (public_eval_v2.php ->
// vendor_eval_v2_public_engine.php) — Form 1 is completely unaffected by
// this file's dispatch to the team engine. The question set is forced per
// team — team=ops gets VE_PROJECT_RETAIL_OPS_V1 (business-provided
// "Retail Operation POV" rubric, added 2026-08-31), team=project keeps
// VE_PROJECT_RETAIL_V1 — bypassing the L3/L2/Default routing chain and any
// manual override entirely either way. The PO must additionally pass the
// Form 2 eligibility gate (vendor_eval_v2_form2_eligibility.php) — p_group
// matches [A-Z]97 or A77, is_new_store = 1 on every line item, and every
// distinct site on the PO has an arrived Store Opening Date.
//
// Safety invariants (enforced inside the team engine):
//   • Never reads or writes vendor_evaluations (v1) or writes the prs table.
//   • No auth.php / session-role requirement — public page.
//   • No WA / email / Gemini / external API calls.
//   • All DB access uses prepared statements (PDO).
//   • All output goes through h() (htmlspecialchars).
//   • No DB error messages exposed to users.
//   • session_start() is used solely for the CSRF token.

date_default_timezone_set('Asia/Jakarta');

// Never let the browser (or its back/forward cache) serve a stale snapshot of
// this form — submission/lock state must always be re-fetched live, even on
// back-button navigation after a submit or a block page.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

session_start();
require_once '../config/database.php';                         // provides $pdo (PDO)
require_once '../includes/vendor_eval_v2_form2_team_engine.php'; // ve2_render_form2_team_eval()

// Team is re-parsed (and re-validated) inside ve2_render_form2_team_eval()
// itself; this early read only decides which question set to force before
// calling it — an invalid/missing team still gets blocked there as before.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$team   = trim((string)(($method === 'POST' ? $_POST['team'] : $_GET['team']) ?? ''));
$forced_set_code = $team === 'ops' ? 'VE_PROJECT_RETAIL_OPS_V1' : 'VE_PROJECT_RETAIL_V1';

ve2_render_form2_team_eval($pdo, $forced_set_code);
