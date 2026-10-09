<?php
// includes/ca_intercompany_exceptions.php
//
// Intercompany / Intercompany New Store tickets are normally OUT of Cost
// Avoidance: they cannot be given an Initial Quotation Price and the Cost
// Avoidance report leaves them out. A few tickets are the exception by special
// request; they are listed here, by exact ticket number, and nowhere else.
//
// This list is deliberately a short, reviewed constant in code (not a setting a
// screen can change): adding a ticket is a code change that goes through a PR.
// It affects ONLY the Initial Quotation Price / Cost Avoidance rules. Routing,
// AI assignment and reassignment of Intercompany tickets are not touched.
//
// Requested 2026-10-06: T2610A90001, T2610A90003 (TIGS).

if (!function_exists('ca_intercompany_exception_tickets')) {
    function ca_intercompany_exception_tickets(): array {
        return ['T2610A90001', 'T2610A90003'];
    }
}

/** Is this ticket type one of the Intercompany types? (case-insensitive) */
if (!function_exists('ca_is_intercompany_type')) {
    function ca_is_intercompany_type($ticketType): bool {
        return in_array(strtolower(trim((string)$ticketType)), ['intercompany', 'intercompany new store'], true);
    }
}

/** Is this specific ticket allowed into Cost Avoidance despite being Intercompany? */
if (!function_exists('ca_is_intercompany_exception')) {
    function ca_is_intercompany_exception($ticketNo): bool {
        return in_array(strtoupper(trim((string)$ticketNo)), ca_intercompany_exception_tickets(), true);
    }
}

/**
 * SQL predicate: TRUE for every line that is NOT blocked as Intercompany, i.e.
 * a non-Intercompany type, or a ticket on the exception list. Replaces the old
 * `IFNULL(ticket_type,'') NOT IN ('Intercompany','Intercompany New Store')`.
 * $alias is the prs table alias ('' = unqualified columns).
 */
if (!function_exists('ca_not_intercompany_sql')) {
    function ca_not_intercompany_sql(string $alias = 'p'): string {
        $a = ($alias !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) ? $alias . '.' : '';
        $tickets = array_values(array_filter(ca_intercompany_exception_tickets(), function ($t) {
            return preg_match('/^[A-Z0-9]+$/', $t) === 1;   // fixed internal values; guard anyway
        }));
        $sql = "IFNULL({$a}ticket_type, '') NOT IN ('Intercompany', 'Intercompany New Store')";
        if ($tickets) {
            $sql = "({$sql} OR {$a}ticket_no IN ('" . implode("','", $tickets) . "'))";
        }
        return $sql;
    }
}
