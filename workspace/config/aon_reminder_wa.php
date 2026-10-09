<?php
// config/aon_reminder_wa.php
//
// WhatsApp group(s) that receive the AON reminder — contracts approaching the
// end of their validity, and SKUs down to their last 30% of contract quantity.
// The two arrive in one message but in their own headed sections, because they
// are answered differently: one by ordering against the contract, the other by
// renewing it.
// Sending uses the same Fonnte token/config as vendor evaluation and
// announcements (includes/fonnte.php).
//
// THE QUANTITY HALF IS SENT BY THE IMPORT, not by a clock: every AON upload and
// every automated K2 import batch calls aon_reminder_after_import() once its data
// is committed, so the group hears about a SKU within seconds of the data that
// put it in trouble. A Category Manager can also send on demand from Master AON.
//
// THE EXPIRY HALF IS MOSTLY ON THE CLOCK, since no upload moves a date: the two-
// and one-month notices fire once each from whatever trigger first sees them, and
// the final month then repeats every Monday at 14:00 WIB (the next working day
// when that Monday is a company holiday). That weekly slot is cron/
// aon_reminder_cron.php's job and REQUIRES the cron to be registered at or after
// 14:00 WIB — see the header of that file.
//
// All of them go through includes/aon_reminder.php and share one per-SKU-per-
// reason flag, so the same SKU cannot produce two messages for the same reason
// in the same slot.
//
// IMPORTANT: values MUST be Fonnte GROUP IDs (they end with "@g.us"), not phone
// numbers or group names. Get them from the Fonnte dashboard: Device -> Group.
// The WhatsApp number connected to the device must be a member of each group.
// The array key is just a human-readable label; only the value is used to send.
//
// ─────────────────────────────────────────────────────────────────────────────
// EMPTYING THIS LIST IS THE OFF SWITCH, and the ONLY one. With no group ID every
// sender -- import hook, manual button and cron alike -- runs in DRY RUN: it
// works out exactly what it would send and records nothing, so a dry run never
// consumes the one-shot T-2M stage or today's slot for a message nobody
// received. There is no separate enable flag: an AON import with a group ID in
// this file WILL message that group, in every environment that has it, local
// included. `--dry-run` forces the same behaviour on the cron without editing
// this file.
//
// Deliberately NOT sharing config/announcement_wa_groups.php: that group is the
// audience for confidential announcements, which is a different set of people
// from whoever should be chased about AON contract renewals.
// ─────────────────────────────────────────────────────────────────────────────

return [
    'groups' => [
        'STC' => '120363409897890824@g.us',
    ],
];
