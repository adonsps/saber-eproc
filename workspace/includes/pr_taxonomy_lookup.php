<?php
// includes/pr_taxonomy_lookup.php
//
// The query pair behind both "click a ticket to see its lines" modals:
// dashboard_mgr.php (ajax_get_pr_taxonomy.php) and sla_report.php
// (ajax_sla_get_pr_taxonomy.php). The two endpoints used to carry a
// byte-for-byte copy of it, the SLA one differing only by dropping the PIC
// columns — so that difference is the one parameter below.

/**
 * @param bool $with_pic Include PIC data: master_data.pic_names on every row,
 *                       plus the pic_list dropdown source. Only the Command
 *                       Center modal edits PIC; the SLA modal never reads them.
 */
function pr_taxonomy_lookup(PDO $pdo, string $ticket_no, bool $with_pic): array {
    // Both interpolations are literals from this file, never request input.
    $line_cols = 'm.level_0, m.level_1, m.level_2, m.level_3, m.level_4';
    $md_cols   = 'id, level_0, level_1, level_2, level_3, level_4';
    if ($with_pic) {
        $line_cols .= ', m.pic_names';
        $md_cols   .= ', pic_names';
    }

    // 1. All line items for this ticket
    $stmt = $pdo->prepare("
        SELECT p.ticket_line_item, p.item_description, p.master_data_id, p.assigned_pic,
               {$line_cols}
        FROM prs p
        LEFT JOIN master_data m ON p.master_data_id = m.id
        WHERE p.ticket_no = ?
        ORDER BY p.ticket_line_item ASC
    ");
    $stmt->execute([$ticket_no]);
    $line_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. All active/registered master data for the dropdown
    $mdStmt = $pdo->query("SELECT {$md_cols} FROM master_data WHERE status IN ('active', 'registered') ORDER BY level_4 ASC, id DESC");
    $master_data_list = $mdStmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        'ticket_no'        => $ticket_no,
        'line_items'       => $line_items,
        'master_data_list' => $master_data_list,
    ];

    // 3. All PICs for the dropdown
    if ($with_pic) {
        $picStmt = $pdo->query("SELECT name FROM users WHERE role IN ('PIC', 'Admin') ORDER BY name ASC");
        $data['pic_list'] = $picStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    return $data;
}
