<?php
// pages/ajax_pr_ticket_detail.php
// Returns detailed PR info and line items for a given ticket_no.
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pr_status_helper.php';

$ticket_no = trim($_GET['ticket_no'] ?? '');

if ($ticket_no === '') {
    echo '<div class="p-6 text-center text-slate-500">Ticket number is required.</div>';
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT p.*, m.team_name as sourcing_team_name
        FROM prs p
        LEFT JOIN pgroup_team_mappings m ON p.p_group = m.p_group
        WHERE p.ticket_no = ?
        ORDER BY CAST(p.ticket_line_item AS UNSIGNED) ASC
    ");
    $stmt->execute([$ticket_no]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($lines)) {
        echo '<div class="p-8 text-center text-slate-500"><i class="fas fa-search text-3xl mb-3 text-slate-300 block"></i>No PR record found for ticket: <strong>' . htmlspecialchars($ticket_no) . '</strong></div>';
        exit;
    }

    $header = $lines[0];
    $display_status = compute_pr_display_status(
        $header['status_ticket'] ?? '',
        $header['canvassing_status'] ?? '',
        $header['po_number'] ?? '',
        $header['canvassing_no'] ?? ''
    );

    $total_baseline = 0;
    foreach ($lines as $l) {
        $qty = (float)($l['qty'] ?? 0);
        $price = (float)($l['baseline_price'] ?? 0);
        $total_baseline += ($qty * $price);
    }
    ?>
    <div class="space-y-6">
        <!-- Header Info Banner -->
        <div class="bg-gradient-to-r from-slate-900 to-[#0F1E36] text-white p-5 rounded-xl shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-extrabold bg-blue-500/20 text-sky-300 border border-sky-400/30 uppercase tracking-wide">
                        <?= htmlspecialchars($header['ticket_type'] ?: 'Reguler') ?>
                    </span>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-white/10 text-slate-200">
                        Status: <?= htmlspecialchars($display_status) ?>
                    </span>
                </div>
                <h3 class="text-xl font-extrabold text-white tracking-tight flex items-center gap-2">
                    <i class="fas fa-file-invoice text-sky-400"></i>
                    PR Ticket: <?= htmlspecialchars($header['ticket_no']) ?>
                </h3>
                <p class="text-xs text-slate-300 mt-1">
                    Submitted on: <strong><?= !empty($header['pr_date']) ? date('d M Y', strtotime($header['pr_date'])) : '--' ?></strong>
                    &bull; Created: <?= !empty($header['created_at']) ? date('d M Y H:i', strtotime($header['created_at'])) : '--' ?>
                </p>
            </div>
            <div class="text-left md:text-right bg-white/5 p-3 rounded-lg border border-white/10">
                <div class="text-[11px] uppercase font-bold text-slate-400">Total Estimated Budget</div>
                <div class="text-lg font-black text-sky-300">
                    Rp <?= number_format($total_baseline, 0, ',', '.') ?>
                </div>
                <div class="text-[11px] text-slate-300"><?= count($lines) ?> Line Item(s)</div>
            </div>
        </div>

        <!-- Meta Details Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">Requestor Details</div>
                <div class="space-y-1 text-sm">
                    <div><span class="text-slate-500 text-xs">Name:</span> <strong class="text-slate-800"><?= htmlspecialchars($header['requestor_name'] ?: '--') ?></strong></div>
                    <div><span class="text-slate-500 text-xs">Site / Branch:</span> <strong class="text-slate-800"><?= htmlspecialchars($header['site_allocation'] ?: '--') ?></strong></div>
                    <?php if (!empty($header['ticket_helpdesk_no'])): ?>
                    <div><span class="text-slate-500 text-xs">Helpdesk Ref:</span> <span class="font-mono text-xs bg-white px-2 py-0.5 rounded border border-slate-200"><?= htmlspecialchars($header['ticket_helpdesk_no']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">Purchasing Org & Group</div>
                <div class="space-y-1 text-sm">
                    <div><span class="text-slate-500 text-xs">P.Org:</span> <strong class="text-slate-800"><?= htmlspecialchars($header['p_org'] ?: '--') ?></strong></div>
                    <div><span class="text-slate-500 text-xs">P.Group:</span> <strong class="text-blue-700 bg-blue-50 px-2 py-0.5 rounded border border-blue-200"><?= htmlspecialchars($header['p_group'] ?: '--') ?></strong></div>
                    <div><span class="text-slate-500 text-xs">Team:</span> <span class="text-slate-700 text-xs"><?= htmlspecialchars($header['sourcing_team_name'] ?: '--') ?></span></div>
                </div>
            </div>

            <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">Sourcing & Fulfillment</div>
                <div class="space-y-1 text-sm">
                    <div><span class="text-slate-500 text-xs">Assigned PIC:</span> <strong class="text-slate-800"><?= htmlspecialchars($header['assigned_pic'] ?: 'Unassigned') ?></strong></div>
                    <div><span class="text-slate-500 text-xs">Canvassing No:</span> <span class="font-mono text-xs"><?= htmlspecialchars($header['canvassing_no'] ?: '--') ?></span></div>
                    <div><span class="text-slate-500 text-xs">PO Number:</span> <span class="font-mono text-xs font-bold text-emerald-700"><?= htmlspecialchars($header['po_number'] ?: '--') ?></span></div>
                </div>
            </div>
        </div>

        <!-- Line Items Table -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
            <div class="px-4 py-3 bg-slate-100/70 border-b border-slate-200 flex items-center justify-between">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center gap-2">
                    <i class="fas fa-list text-blue-600"></i> Line Items (<?= count($lines) ?>)
                </h4>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase text-[10px] tracking-wider">
                            <th class="py-2.5 px-3 w-12 text-center">Line</th>
                            <th class="py-2.5 px-3">Description & Specifications</th>
                            <th class="py-2.5 px-3 w-28">SKU Code</th>
                            <th class="py-2.5 px-3 w-20 text-right">Qty</th>
                            <th class="py-2.5 px-3 w-16 text-center">UoM</th>
                            <th class="py-2.5 px-3 w-28 text-right">Est. Unit Price</th>
                            <th class="py-2.5 px-3 w-32 text-right">Subtotal</th>
                            <th class="py-2.5 px-3 w-24 text-center">Target Date</th>
                            <th class="py-2.5 px-3 w-20 text-center">Doc Link</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($lines as $item): 
                            $line_subtotal = (float)($item['qty'] ?? 0) * (float)($item['baseline_price'] ?? 0);
                        ?>
                        <tr class="hover:bg-blue-50/50 transition">
                            <td class="py-3 px-3 text-center font-bold text-slate-500 bg-slate-50/50">
                                <?= htmlspecialchars($item['ticket_line_item']) ?>
                            </td>
                            <td class="py-3 px-3">
                                <div class="font-semibold text-slate-900 leading-snug"><?= nl2br(htmlspecialchars($item['item_description'])) ?></div>
                            </td>
                            <td class="py-3 px-3 text-left">
                                <?php if (empty($item['sku']) || $item['sku'] === 'NEW-ITEM'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200 whitespace-nowrap">
                                    <i class="fas fa-sparkles text-[9px] text-amber-600"></i> Item Baru
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-blue-50 text-blue-700 border border-blue-200 whitespace-nowrap">
                                    <i class="fas fa-barcode text-[9px]"></i> <?= htmlspecialchars($item['sku']) ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-3 text-right font-bold text-slate-800">
                                <?= number_format((float)($item['qty'] ?? 0), 2, ',', '.') ?>
                            </td>
                            <td class="py-3 px-3 text-center">
                                <span class="bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px] font-bold border border-slate-200">
                                    <?= htmlspecialchars($item['uom'] ?: 'PCS') ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-right text-slate-700">
                                <?= !empty($item['baseline_price']) ? 'Rp ' . number_format((float)$item['baseline_price'], 0, ',', '.') : '--' ?>
                            </td>
                            <td class="py-3 px-3 text-right font-bold text-blue-900">
                                <?= $line_subtotal > 0 ? 'Rp ' . number_format($line_subtotal, 0, ',', '.') : '--' ?>
                            </td>
                            <td class="py-3 px-3 text-center text-slate-500">
                                <?= !empty($item['delivery_date']) ? date('d/m/Y', strtotime($item['delivery_date'])) : '--' ?>
                            </td>
                            <td class="py-3 px-3 text-center">
                                <?php if (!empty($item['quotation_link'])): ?>
                                <a href="<?= htmlspecialchars($item['quotation_link']) ?>" target="_blank" class="inline-flex items-center text-blue-600 hover:text-blue-800 text-[11px] font-semibold" title="Open Specification / Quotation Link">
                                    <i class="fas fa-external-link-alt mr-1"></i> View
                                </a>
                                <?php else: ?>
                                <span class="text-slate-300">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-50 font-bold border-t border-slate-200 text-slate-800">
                            <td colspan="6" class="py-2.5 px-3 text-right uppercase text-[11px]">Total Requisition Estimated Budget:</td>
                            <td class="py-2.5 px-3 text-right font-black text-blue-900 text-sm">
                                Rp <?= number_format($total_baseline, 0, ',', '.') ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php
} catch (\Throwable $e) {
    echo '<div class="p-6 text-center text-rose-600 font-semibold"><i class="fas fa-exclamation-triangle mr-2"></i>Error loading ticket details: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
