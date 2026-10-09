import glob

files = [
    "workspace/pages/dashboard_mgr.php",
    "workspace/pages/dashboard_pic.php",
    "workspace/pages/dashboard_admin.php",
    "workspace/pages/dashboard_project_head.php"
]

target_logic_find = """    if (strpos($canvassing_status, 'cancel') !== false) {
        $pr['display_status'] = 'Canvassing Cancelled';
    } elseif (strpos($raw_status, 'escalated') !== false) {"""

target_logic_replace = """    if (strpos($canvassing_status, 'cancel') !== false) {
        $pr['display_status'] = 'Canvassing Cancelled';
    } elseif (strpos($canvassing_status, 'waiting for approval') !== false) {
        $pr['display_status'] = 'Waiting Canvassing Approval';
    } elseif (strpos($raw_status, 'escalated') !== false) {"""

target_style_find = """                                if ($pr['display_status'] === 'Canvassing Cancelled') $statusClass = 'bg-red-500 text-white border-red-600';"""

target_style_replace = """                                if ($pr['display_status'] === 'Waiting Canvassing Approval') $statusClass = 'bg-blue-100 text-blue-800 border-blue-200';
                                if ($pr['display_status'] === 'Canvassing Cancelled') $statusClass = 'bg-red-500 text-white border-red-600';"""

# Special target style for dashboard_mgr.php which has two tables
target_style_find2 = """                        if ($pr['display_status'] === 'Canvassing Cancelled') $statusClass = 'bg-red-500 text-white border-red-600';"""

target_style_replace2 = """                        if ($pr['display_status'] === 'Waiting Canvassing Approval') $statusClass = 'bg-blue-100 text-blue-800 border-blue-200';
                        if ($pr['display_status'] === 'Canvassing Cancelled') $statusClass = 'bg-red-500 text-white border-red-600';"""


for file_path in files:
    try:
        with open(file_path, "r") as f:
            content = f.read()

        content = content.replace(target_logic_find, target_logic_replace)
        content = content.replace(target_style_find, target_style_replace)
        
        # Only mgr has the 24 spaces indentation one for the project table
        if file_path == "workspace/pages/dashboard_mgr.php":
            content = content.replace(target_style_find2, target_style_replace2)

        with open(file_path, "w") as f:
            f.write(content)
        print(f"Updated {file_path}")
    except Exception as e:
        print(f"Failed {file_path}: {e}")

