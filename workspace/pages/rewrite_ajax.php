<?php
$dashboard = file_get_contents('/Users/adonispallassutanto/Documents/vms/workspace/pages/dashboard_mgr.php');

// We need to cut out the data fetching part of dashboard_mgr.php
// and replace it with just the top cards rendering via JS, OR we can just fetch the Top Cards in dashboard_mgr.php using SQL GROUP BY.
// Actually, if we just do AJAX DataTables, the Top Cards can stay exactly as they are in dashboard_mgr.php!
// The ONLY thing we change is removing `$all_prs` HTML output and using AJAX!

