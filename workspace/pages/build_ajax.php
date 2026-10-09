<?php
$content = file_get_contents('/Users/adonispallassutanto/Documents/vms/workspace/pages/dashboard_mgr.php');

// We want to extract everything from line 1 to line 548 (the end of backend logic)
$parts = explode("?>\n\n<script", $content);
$backend = $parts[0] . "?>";

// Now we need to modify $backend to output JSON instead of stopping
$backend .= "\n\n" . <<<'JSON'
// Format $all_prs for DataTables
$dt_data = [];
foreach ($all_prs as $pr) {
    if (($pr['_row_type'] ?? 'nonproject') === 'project') {
        $p_intercompany = !empty($pr['is_intercompany']);
        $p_assigned     = trim((string)($pr['assigned_pic'] ?? '')) !== '';
        $statusClass = 'bg-gray-100 text-gray-800 border-gray-200';
        if ($pr['display_status'] === 'Outstanding') $statusClass = 'bg-red-100 text-red-800 border-red-200';
        if ($pr['display_status'] === 'Escalated')   $statusClass = 'bg-orange-100 text-orange-800 border-orange-200';
        if ($pr['display_status'] === 'Sendback')    $statusClass = 'bg-purple-100 text-purple-800 border-purple-200';
        if ($pr['display_status'] === 'Canvassing Cancelled') $statusClass = 'bg-red-500 text-white border-red-600';
        if ($pr['display_status'] === 'On Progress') $statusClass = 'bg-yellow-100 text-yellow-800 border-yellow-200';
        if ($pr['display_status'] === 'PO Created')  $statusClass = 'bg-green-100 text-green-800 border-green-200';

        $col0 = '<td class="line-select-col hidden px-4 py-3 text-center"></td>';
        
        $col1 = '<td class="px-4 py-3 text-sm font-medium text-indigo-700 cursor-copy ticket-no-cell relative hover:text-indigo-900 group" title="Click to copy">' .
                '<span class="ticket-no-text">' . htmlspecialchars($pr['ticket_no']) . '</span>' .
                '<span class="absolute left-1/2 -top-6 -translate-x-1/2 bg-gray-800 text-white text-[10px] px-2 py-1 rounded opacity-0 transition-opacity duration-200 pointer-events-none copy-toast whitespace-nowrap">Copied!</span>' .
                '<div class="mt-0.5">' .
                '<span class="inline-block bg-gray-50 text-gray-700 border border-gray-200 py-0.5 px-1.5 rounded text-[10px] font-bold">' . htmlspecialchars($pr['p_group']) . '</span>' .
                '<span class="text-[10px] font-bold text-indigo-500 ml-1 tracking-wide" title="' . htmlspecialchars($pr['project_group_name']) . '">' . htmlspecialchars($pr['team_code']) . '</span>' .
                '</div></td>';
                
        $col2 = '<td class="px-4 py-3 text-sm text-gray-700" data-order="' . ($pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '') . '">' . ($pr['pr_date'] ? date('d M Y', strtotime($pr['pr_date'])) : '-') . '</td>';
        
        $col3 = '<td class="px-4 py-3 text-sm text-gray-700">' . htmlspecialchars($pr['p_org']) . '</td>';
        
        $col4 = '<td class="px-4 py-3 text-sm text-gray-700">' . htmlspecialchars($pr['requestor_name']) . '</td>';
        
        $linesHtml = (($pr['line_count'] ?? 1) > 1) ? '<div class="text-[10px] text-gray-400 mt-0.5">' . (int)$pr['line_count'] . ' lines</div>' : '';
        $col5 = '<td class="px-4 py-3 text-sm text-gray-700">' .
                '<div class="truncate max-w-[200px] text-indigo-600 hover:text-indigo-900 cursor-pointer hover:underline pr-taxonomy-clickable" title="View Taxonomy: ' . htmlspecialchars($pr['item_description']) . '" data-ticket-no="' . htmlspecialchars($pr['ticket_no']) . '">' . htmlspecialchars($pr['item_description']) . '</div>' .
                $linesHtml . '</td>';
                
        $canvDateHtml = $pr['canvassing_date'] ? '<div class="text-[10px] text-gray-400 mt-0.5">' . date('d M Y', strtotime($pr['canvassing_date'])) . '</div>' : '';
        $col6 = '<td class="px-4 py-3 text-sm text-gray-700" data-search="' . htmlspecialchars($pr['canvassing_no'] ?: '') . '">' .
                '<div>' . htmlspecialchars($pr['canvassing_no'] ?: '--') . '</div>' . $canvDateHtml . '</td>';
                
        $col7 = '<td class="px-4 py-3 text-sm" data-search="' . htmlspecialchars($pr['display_status']) . '">' .
                '<span class="' . $statusClass . ' py-1 px-2 rounded-md text-[10px] font-bold border inline-block w-full text-center">' . htmlspecialchars($pr['display_status']) . '</span></td>';
                
        $col8 = '<td class="px-4 py-3 text-sm text-gray-700 text-center">' . htmlspecialchars($pr['po_number'] ?: '-') . '</td>';
        
        $assignedHtml = '';
        if ($p_intercompany) {
            $assignedHtml = '<span class="inline-flex items-center px-3 py-1.5 border border-blue-200 shadow-sm text-xs font-medium rounded text-blue-800 bg-blue-50" title="Routed via admin mappings / system rule">' .
                            '<span class="font-semibold">' . htmlspecialchars($pr['assigned_pic'] ?: '--') . '</span>' .
                            '<span class="ml-1 text-[10px] text-blue-500 font-semibold">&middot; System</span></span>';
        } else {
            $assignedContent = '';
            if ($p_assigned) {
                $assignedContent = '<span class="text-indigo-700 font-semibold">' . htmlspecialchars($pr['assigned_pic']) . ' (Manual)</span>';
            } elseif (!empty($pr['resolved_officer'])) {
                $assignedContent = '<span class="text-amber-700 font-semibold">' . htmlspecialchars($pr['resolved_officer']) . '</span><span class="ml-1 text-[9px] text-amber-500 font-bold uppercase tracking-wider">GP Officer</span>';
            } else {
                $assignedContent = '<span class="text-yellow-600 italic">Unassigned</span>';
            }
            $assignedHtml = '<button type="button" class="project-assign-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors" ' .
                            'data-ticket="' . htmlspecialchars($pr['ticket_no']) . '" data-pgroup="' . htmlspecialchars($pr['p_group']) . '" data-officer="' . htmlspecialchars($pr['assigned_pic'] ?? '') . '">' .
                            $assignedContent . '<svg class="ml-2 h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg></button>';
        }
        $col9 = '<td class="px-4 py-3 text-sm" data-search="' . htmlspecialchars($pr['assigned_pic'] ?: ($pr['resolved_officer'] ?: 'Unassigned')) . '">' . $assignedHtml . '</td>';

        $dt_data[] = [
            "DT_RowClass" => "hover:bg-indigo-50 transition border-b border-gray-100 project-row",
            "DT_RowAttr" => [
                "data-row-type" => "project",
                "data-group" => (int)$pr['project_group_number'],
                "data-pgroup" => htmlspecialchars($pr['p_group']),
                "data-assigned-by-ai" => (int)($pr['assigned_by_ai'] ?? 0),
                "data-ticket-type" => htmlspecialchars(strtolower($pr['ticket_type'] ?? ''))
            ],
            "col0" => $col0, "col1" => $col1, "col2" => $col2, "col3" => $col3,
            "col4" => $col4, "col5" => $col5, "col6" => $col6, "col7" => $col7,
            "col8" => $col8, "col9" => $col9
        ];
    } else {
        $statusClass = 'bg-gray-100 text-gray-800 border-gray-200';
        if ($pr['display_status'] === 'Outstanding') $statusClass = 'bg-red-100 text-red-800 border-red-200';
        if ($pr['display_status'] === 'Escalated') $statusClass = 'bg-orange-100 text-orange-800 border-orange-200';
        if ($pr['display_status'] === 'Sendback') $statusClass = 'bg-purple-100 text-purple-800 border-purple-200';
        if ($pr['display_status'] === 'Canvassing Cancelled') $statusClass = 'bg-red-500 text-white border-red-600';
        if ($pr['display_status'] === 'On Progress') $statusClass = 'bg-yellow-100 text-yellow-800 border-yellow-200';
        if ($pr['display_status'] === 'PO Created') $statusClass = 'bg-green-100 text-green-800 border-green-200';
        
        $col0 = '<td class="line-select-col hidden px-4 py-3 text-center"><input type="checkbox" class="line-select-checkbox h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" data-ticket="' . htmlspecialchars($pr['ticket_no']) . '" data-line="' . htmlspecialchars($pr['ticket_line_item'] ?? '') . '" value="' . htmlspecialchars($pr['ticket_no'] . '|' . ($pr['ticket_line_item'] ?? '')) . '"></td>';
        
        $col1 = '<td class="px-4 py-3 text-sm font-medium text-indigo-700 cursor-copy ticket-no-cell relative hover:text-indigo-900 group" title="Click to copy"><span class="ticket-no-text">' . htmlspecialchars($pr['ticket_no']) . '</span><span class="absolute left-1/2 -top-6 -translate-x-1/2 bg-gray-800 text-white text-[10px] px-2 py-1 rounded opacity-0 transition-opacity duration-200 pointer-events-none copy-toast whitespace-nowrap">Copied!</span></td>';
        
        $col2 = '<td class="px-4 py-3 text-sm text-gray-700" data-order="' . ($pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '') . '">' . ($pr['pr_date'] ? date('d M Y', strtotime($pr['pr_date'])) : '-') . '</td>';
        
        $col3 = '<td class="px-4 py-3 text-sm text-gray-700">' . htmlspecialchars($pr['p_org']) . '</td>';
        
        $col4 = '<td class="px-4 py-3 text-sm text-gray-700">' . htmlspecialchars($pr['requestor_name']) . '</td>';
        
        $linesHtml = (($pr['ticket_line_item'] ?? '') !== '') ? '<div class="text-[10px] text-gray-400 mt-0.5">Line ' . htmlspecialchars($pr['ticket_line_item']) . '</div>' : '';
        $col5 = '<td class="px-4 py-3 text-sm text-gray-700"><div class="truncate max-w-[200px] text-indigo-600 hover:text-indigo-900 cursor-pointer hover:underline pr-taxonomy-clickable" title="View Taxonomy: ' . htmlspecialchars($pr['item_description']) . '" data-ticket-no="' . htmlspecialchars($pr['ticket_no']) . '">' . htmlspecialchars($pr['item_description']) . '</div>' . $linesHtml . '</td>';
        
        $canvDateHtml = $pr['canvassing_date'] ? '<div class="text-[10px] text-gray-400 mt-0.5">' . date('d M Y', strtotime($pr['canvassing_date'])) . '</div>' : '';
        $col6 = '<td class="px-4 py-3 text-sm text-gray-700" data-search="' . htmlspecialchars($pr['canvassing_no'] ?: '') . '"><div>' . htmlspecialchars($pr['canvassing_no'] ?: '--') . '</div>' . $canvDateHtml . '</td>';
        
        $col7 = '<td class="px-4 py-3 text-sm" data-search="' . htmlspecialchars($pr['display_status']) . '"><span class="' . $statusClass . ' py-1 px-2 rounded-md text-[10px] font-bold border inline-block w-full text-center">' . htmlspecialchars($pr['display_status']) . '</span></td>';
        
        $col8 = '<td class="px-4 py-3 text-sm text-gray-700 text-center">' . htmlspecialchars($pr['po_number'] ?: '-') . '</td>';
        
        $assignedContent = '';
        if ($pr['assigned_pic']) {
            $assignedContent = '<span class="' . (!$pr['assigned_by_ai'] ? 'text-indigo-700 font-semibold' : '') . '">' . htmlspecialchars($pr['assigned_pic']) . (!$pr['assigned_by_ai'] ? ' (Manual)' : '') . '</span>';
        } else {
            $assignedContent = '<span class="text-yellow-600 italic">Unassigned</span>';
        }
        $col9 = '<td class="px-4 py-3 text-sm" data-search="' . htmlspecialchars($pr['assigned_pic'] ?: 'Unassigned') . '"><button type="button" class="open-modal-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors" data-ticket="' . htmlspecialchars($pr['ticket_no']) . '" data-line="' . htmlspecialchars($pr['ticket_line_item'] ?? '') . '" data-pic="' . htmlspecialchars($pr['assigned_pic'] ?? '') . '">' . $assignedContent . '<svg class="ml-2 h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg></button></td>';

        $dt_data[] = [
            "DT_RowClass" => "hover:bg-indigo-50 transition border-b border-gray-100",
            "DT_RowAttr" => [
                "data-row-type" => "nonproject",
                "data-group" => "",
                "data-team" => htmlspecialchars($pr['np_team'] ?? ''),
                "data-assigned-pic" => htmlspecialchars($pr['assigned_pic'] ?? ''),
                "data-assigned-by-ai" => (int)$pr['assigned_by_ai'],
                "data-ticket-type" => htmlspecialchars(strtolower($pr['ticket_type'] ?? ''))
            ],
            "col0" => $col0, "col1" => $col1, "col2" => $col2, "col3" => $col3,
            "col4" => $col4, "col5" => $col5, "col6" => $col6, "col7" => $col7,
            "col8" => $col8, "col9" => $col9
        ];
    }
}

$evalQuery = "
    SELECT p.po_number, p.vendor_name, p.requestor_name, MAX(p.gr_date) as gr_date
    FROM prs p
    LEFT JOIN vendor_evaluations v ON p.po_number = v.po_number
    WHERE p.po_number IS NOT NULL AND p.po_number != ''
      AND p.vendor_name IS NOT NULL AND p.vendor_name != ''
      AND v.id IS NULL
      AND (p.pr_date >= '2026-05-01' OR p.pr_date IS NULL OR (p.canvassing_no IS NOT NULL AND p.canvassing_no != '') OR (p.po_number IS NOT NULL AND p.po_number != ''))
    GROUP BY p.po_number, p.vendor_name, p.requestor_name
    HAVING MAX(p.gr_date) IS NOT NULL AND MAX(p.gr_date) <= CURDATE()
    ORDER BY gr_date ASC
";
$pending_evals = $pdo->query($evalQuery)->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'data' => $dt_data,
    'chart_labels' => $chart_labels,
    'chart_counts' => $chart_counts,
    'chart_raw_data' => $chart_raw_data,
    'pending_evals' => $pending_evals,
    'last_updated' => $last_updated_label,
    'unique_tickets' => array_values($unique_tickets),
    'unique_porgs' => array_values($unique_porgs),
    'unique_requestors' => array_values($unique_requestors),
    'unique_statuses' => array_values($unique_statuses),
    'all_pics' => array_values($all_pics),
    'np_pics_by_team' => $np_pics_by_team
]);
JSON;

file_put_contents('/Users/adonispallassutanto/Documents/vms/workspace/pages/ajax_dashboard_data.php', $backend);
echo "Done building ajax_dashboard_data.php\n";
