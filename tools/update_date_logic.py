import os

directory = '/Users/adonispallassutanto/Documents/vms/workspace/pages'

files_to_check = [
    'dashboard_admin.php',
    'dashboard_pic.php',
    'auto_assign.php',
    'ajax_process_batch.php',
    'dashboard_project_head.php',
    'dashboard_mgr.php'
]

for filename in files_to_check:
    filepath = os.path.join(directory, filename)
    if os.path.exists(filepath):
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()
        
        # Replace prs.pr_date
        content = content.replace(
            "(prs.pr_date >= '2026-05-01' OR prs.pr_date IS NULL)",
            "(prs.pr_date >= '2026-05-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != ''))"
        )
        
        # Replace p.pr_date
        content = content.replace(
            "(p.pr_date >= '2026-05-01' OR p.pr_date IS NULL)",
            "(p.pr_date >= '2026-05-01' OR p.pr_date IS NULL OR (p.canvassing_no IS NOT NULL AND p.canvassing_no != '') OR (p.po_number IS NOT NULL AND p.po_number != ''))"
        )
        
        # Replace pr_date
        content = content.replace(
            "(pr_date >= '2026-05-01' OR pr_date IS NULL)",
            "(pr_date >= '2026-05-01' OR pr_date IS NULL OR (canvassing_no IS NOT NULL AND canvassing_no != '') OR (po_number IS NOT NULL AND po_number != ''))"
        )
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        
        print(f"Updated {filename}")
