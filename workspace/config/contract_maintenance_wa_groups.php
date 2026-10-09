<?php
// config/contract_maintenance_wa_groups.php
//
// WhatsApp group(s) that receive the Contract Maintenance reminder, both the
// manual "Shoot Reminder" button (Category Manager selects specific contracts
// on the dashboard) and the auto reminder that fires once per contract PER
// STAGE at 3, 2 and 1 calendar month(s) before its end date (workspace/cron/
// contract_maintenance_reminder_cron.php). Sending uses the same Fonnte
// token/config as AON and announcements (includes/fonnte.php).
//
// IMPORTANT: values MUST be Fonnte GROUP IDs (they end with "@g.us"), not phone
// numbers or group names. Get them from the Fonnte dashboard: Device -> Group.
// The WhatsApp number connected to the device must be a member of each group.
// The array key is just a human-readable label; only the value is used to send.
//
// ─────────────────────────────────────────────────────────────────────────────
// EMPTYING THIS LIST IS THE OFF SWITCH, and the ONLY one (same convention as
// config/aon_reminder_wa.php). With no group ID both senders, the manual
// button and the auto cron, run in DRY RUN: the message is computed and
// returned, nothing is sent, and nothing is logged, so a dry run never
// consumes any of the three auto stages' one-shot per contract. Left empty
// deliberately until the Facility/Property team's actual WhatsApp group ID is
// provided.
//
// Deliberately NOT sharing config/aon_reminder_wa.php or
// config/announcement_wa_groups.php: different audience from either.
// ─────────────────────────────────────────────────────────────────────────────

return [
    'groups' => [
        // 'Facility/MFM' => 'PASTE_GROUP_ID_HERE@g.us',
    ],
];
