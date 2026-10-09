<?php
// workspace/pages/public_eval_v2.php
//
// Vendor Evaluation v2 — Form 1 (no-login dynamic requestor evaluation form).
//
// Thin wrapper: all GET/POST/render/write logic lives in
// vendor_eval_v2_public_engine.php (ve2_render_public_eval_form()), shared
// with Form 2 (public_eval_v2_project.php, Project Retail — New Store) so the
// two forms can never drift from each other except in the one thing that
// differs between them: the forced question set.
//
// Routing here is the normal chain: manual override -> L3 -> L2 -> Default.
//
// Safety invariants (enforced inside the shared engine):
//   • Never reads or writes vendor_evaluations (v1) or writes the prs table.
//   • No auth.php / session-role requirement — public page.
//   • No WA / email / Gemini / external API calls.
//   • All DB access uses prepared statements (PDO).
//   • All output goes through h() (htmlspecialchars).
//   • No DB error messages exposed to users.
//   • session_start() is used solely for the CSRF token.

date_default_timezone_set('Asia/Jakarta');
session_start();
require_once '../config/database.php';                       // provides $pdo (PDO)
require_once '../includes/vendor_eval_v2_routing.php';        // ve2_override_set() — manual override wins over auto routing
require_once '../includes/vendor_eval_v2_public_engine.php';  // ve2_render_public_eval_form()

ve2_render_public_eval_form($pdo);
