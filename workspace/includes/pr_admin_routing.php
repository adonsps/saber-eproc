<?php
// includes/pr_admin_routing.php
// Shared "route this PR line to the Admin team, bypassing the AI dispatcher"
// rule.
//
// This started life inline in includes/excel_parser.php as the Intercompany
// routing rule. AON lines now follow the exact same rule, so it lives in one
// place instead of being copy-pasted per ticket type -- and the one-off
// backfill script (workspace/reassign_aon.php) can state the same mapping in
// SQL without the two drifting apart silently.

if (!function_exists('pr_admin_route_pic')) {
    /**
     * Resolve the Admin-team PIC a system-routed PR line belongs to.
     *
     * @param string $p_org           prs.p_org for the line
     * @param string $p_group         prs.p_group for the line
     * @param array  $allowed_pgroups UPPERCASED pgroup_code => ['label' => ..., 'team_name' => ...]
     * @return string|null the routed name, or null when no rule matches — the
     *                     caller then leaves the line to the AI dispatcher, same
     *                     as the original inline Intercompany behaviour.
     */
    function pr_admin_route_pic($p_org, $p_group, array $allowed_pgroups, array $admin_rules = []) {
        $p_org_key   = strtoupper(trim((string)$p_org));
        $p_group_key = strtoupper(trim((string)$p_group));
        $pgroup_label = '';
        
        if (isset($allowed_pgroups[$p_group_key])) {
            $pgroup_label = strtoupper(trim($allowed_pgroups[$p_group_key]['label'] ?? ''));
        }

        // Apply rules in order of priority: PORG, PGROUP, PGROUP_LABEL
        
        // 1. Check PORG
        foreach ($admin_rules as $rule) {
            if ($rule['rule_type'] === 'PORG' && strtoupper(trim($rule['rule_value'])) === $p_org_key) {
                return $rule['pic_name'];
            }
        }
        
        // 2. Check PGROUP
        foreach ($admin_rules as $rule) {
            if ($rule['rule_type'] === 'PGROUP' && strtoupper(trim($rule['rule_value'])) === $p_group_key) {
                return $rule['pic_name'];
            }
        }
        
        // 3. Check PGROUP_LABEL
        if ($pgroup_label !== '') {
            foreach ($admin_rules as $rule) {
                if ($rule['rule_type'] === 'PGROUP_LABEL' && strtoupper(trim($rule['rule_value'])) === $pgroup_label) {
                    return $rule['pic_name'];
                }
            }
        }

        return null;
    }
}
