<?php
// config/announcement_wa_groups.php
//
// WhatsApp groups that receive an automatic teaser when a CONFIDENTIAL
// announcement is PUBLISHED (see includes/announcement_helper.php ->
// announcement_send_wa_on_publish()). Sending uses the same Fonnte token/config
// as vendor evaluation (includes/fonnte.php).
//
// IMPORTANT: values MUST be Fonnte GROUP IDs (they end with "@g.us"), not phone
// numbers or group names. Get them from the Fonnte dashboard: Device -> Group.
// The WhatsApp number connected to the device must be a member of each group.
// The array key is just a human-readable label; only the value is used to send.
//
// To add more groups, append more 'Label' => 'id@g.us' lines.

return [
    'groups' => [
        'GP Workspace' => '120363407445694987@g.us',
    ],
];
