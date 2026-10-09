<?php
/**
 * tools/vss_maintenance.php
 *
 * Turn VSS maintenance mode on/off from the shell, without needing the VSS
 * database to be up. This is the tool to reach for when the reason you want
 * maintenance mode is that you are about to restore or repair the database:
 * the DB flag cannot protect a restore of the database it lives in.
 *
 * Usage (from the repo root):
 *   docker-compose exec app php tools/vss_maintenance.php status
 *   docker-compose exec app php tools/vss_maintenance.php on  "restore vmsportal dump"
 *   docker-compose exec app php tools/vss_maintenance.php off "restore selesai"
 *
 * `on`/`off` only touch the on-disk flag, which the gate checks first and which
 * a database restore cannot clear. The DB row is updated too when the database
 * happens to be reachable, so the admin UI stays in sync; if it is not
 * reachable, that is reported and the flag still stands on its own.
 *
 * Writes nothing except the flag file and (best effort) the maintenance_settings
 * / maintenance_log rows. It never touches vendor data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/maintenance_gate.php';

$command = $argv[1] ?? 'status';
$note    = $argv[2] ?? '';
$by      = 'cli:' . (getenv('USER') ?: getenv('USERNAME') ?: 'unknown');

/** Best-effort VSS connection; null when the database is unreachable. */
function vss_cli_connect(): ?mysqli
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $db   = getenv('VSS_DB_NAME') ?: 'u894129685_vmsportal';
    $user = getenv('VSS_DB_USER') ?: 'u894129685_adonisvm';
    $pass = getenv('VSS_DB_PASSWORD') ?: 'Xcel007!';

    try {
        $c = @new mysqli($host, $user, $pass, $db);
    } catch (\Throwable $e) {
        return null;
    }
    return $c->connect_error ? null : $c;
}

$conn = vss_cli_connect();

switch ($command) {
    case 'status':
        $state = vss_maintenance_state($conn);
        $meta  = vss_maintenance_file_meta();

        echo "VSS maintenance status\n";
        echo "  effective : " . ($state['active'] ? 'ON' : 'OFF')
            . " (source: {$state['source']})\n";
        echo "  flag file : " . vss_maintenance_flag_path()
            . (vss_maintenance_file_active() ? "  [PRESENT]\n" : "  [absent]\n");
        if ($meta) {
            echo "              note=" . ($meta['note'] ?? '')
                . " by=" . ($meta['updated_by'] ?? '')
                . " at=" . ($meta['updated_at'] ?? '') . "\n";
        }
        if ($conn === null) {
            echo "  database  : UNREACHABLE (gate fails closed, vendors are held off)\n";
        } else {
            $row = @$conn->query("SELECT is_active, note, updated_by, updated_at FROM maintenance_settings WHERE id = 1");
            if ($row === false) {
                echo "  database  : maintenance_settings unreadable (gate fails closed)\n";
            } else {
                $r = $row->fetch_assoc();
                echo "  database  : is_active=" . (int)($r['is_active'] ?? 0)
                    . " note=" . ($r['note'] ?? '')
                    . " by=" . ($r['updated_by'] ?? '')
                    . " at=" . ($r['updated_at'] ?? '') . "\n";
            }
        }
        exit(0);

    case 'on':
    case 'off':
        $on = ($command === 'on');
        if ($note === '') {
            fwrite(STDERR, "A note is required, e.g.  php tools/vss_maintenance.php $command \"restore vmsportal dump\"\n");
            exit(1);
        }

        [$ok, $message] = vss_maintenance_set_file($on, $note, $by);
        echo ($ok ? "[ok]   " : "[FAIL] ") . $message . "\n";
        if (!$ok) {
            exit(1);
        }

        if ($conn === null) {
            echo "[warn] VSS database unreachable; DB row not updated. The flag file is authoritative, so maintenance is "
                . ($on ? "ON" : "OFF") . " regardless.\n";
            exit(0);
        }

        $stmt = $conn->prepare("INSERT INTO maintenance_settings (id, is_active, note, updated_by, updated_at)
                                VALUES (1, ?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), note=VALUES(note),
                                                        updated_by=VALUES(updated_by), updated_at=NOW()");
        if ($stmt === false) {
            echo "[warn] Could not update maintenance_settings: " . $conn->error . "\n";
            exit(0);
        }
        $active = $on ? 1 : 0;
        $stmt->bind_param("iss", $active, $note, $by);
        $stmt->execute();

        $label = $on ? 'ON' : 'OFF';
        $log   = $conn->prepare("INSERT INTO maintenance_log (action, note, changed_by) VALUES (?,?,?)");
        if ($log !== false) {
            $log->bind_param("sss", $label, $note, $by);
            $log->execute();
        }

        echo "[ok]   database row + audit log updated (is_active=$active)\n";
        exit(0);

    default:
        fwrite(STDERR, "Unknown command '$command'. Use: status | on <note> | off <note>\n");
        exit(1);
}
