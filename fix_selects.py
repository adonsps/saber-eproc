import glob

files = [
    "workspace/pages/dashboard_mgr.php",
    "workspace/pages/dashboard_pic.php",
    "workspace/pages/dashboard_admin.php",
    "workspace/pages/dashboard_project_head.php"
]

for file_path in files:
    try:
        with open(file_path, "r") as f:
            content = f.read()

        # Update SQL queries to include prs.canvassing_status
        content = content.replace(
            "prs.canvassing_no, prs.canvassing_date",
            "prs.canvassing_no, prs.canvassing_status, prs.canvassing_date"
        )
        
        # In dashboard_mgr.php, $all_prs[] array builder
        content = content.replace(
            "'canvassing_no'        => $g['canvassing_no'],\n            'canvassing_date'      => $g['canvassing_date'],",
            "'canvassing_no'        => $g['canvassing_no'],\n            'canvassing_status'    => $g['canvassing_status'],\n            'canvassing_date'      => $g['canvassing_date'],"
        )

        with open(file_path, "w") as f:
            f.write(content)
        print(f"Updated {file_path}")
    except Exception as e:
        print(f"Failed {file_path}: {e}")

