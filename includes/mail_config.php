<?php
// includes/mail_config.php
//
// Single source of truth for the shared SMTP credential and sender address
// (sigit.ramadan@kawanlamacorp.com via Google Workspace, replacing the
// Hostinger-restricted corporateprocurement@vssportal.online). Read by
// includes/smtp_config.php (root app) and workspace/includes/mailer.php, so a
// mailbox swap or password rotation is one change, in the environment, not in source.
//
// from_email follows username unless SMTP_FROM_EMAIL is set: kawanlamacorp.com
// publishes DMARC p=reject, so the From address must belong to the account that
// authenticates on the SMTP server, or recipients reject the mail outright.
//
// Resolution order for each value:
//   1. Container environment (getenv) -- the VPS Docker deploy, wired through
//      docker-compose.prod.yml's `environment:` block from the untracked .env.
//   2. includes/mail_secrets.local.php -- an untracked file returning an array,
//      for a checkout with no docker-compose environment (see the .example file).
//   3. Default. host/port/username/from-name default to today's known-good
//      values. The password has NO default: when it is not set it resolves to
//      '', so SMTP login fails and is logged instead of a stale password being
//      used silently. Set SMTP_PASSWORD in the production .env.

/**
 * @return array{host:string,port:int,username:string,password:string,from_email:string,from_name:string}
 */
function mail_smtp_config(): array
{
    static $local = null;
    if ($local === null) {
        $localFile = __DIR__ . '/mail_secrets.local.php';
        $loaded = is_file($localFile) ? require $localFile : null;
        $local = is_array($loaded) ? $loaded : [];
    }

    $pick = static function (string $envKey, string $localKey, string $default) use ($local): string {
        $env = getenv($envKey);
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (isset($local[$localKey]) && $local[$localKey] !== '') {
            return (string) $local[$localKey];
        }
        return $default;
    };

    $username = $pick('SMTP_USERNAME', 'username', 'sigit.ramadan@kawanlamacorp.com');

    return [
        'host'       => $pick('SMTP_HOST', 'host', 'smtp.gmail.com'),
        'port'       => (int) $pick('SMTP_PORT', 'port', '465'),
        'username'   => $username,
        'password'   => $pick('SMTP_PASSWORD', 'password', ''),
        'from_email' => $pick('SMTP_FROM_EMAIL', 'from_email', $username),
        'from_name'  => $pick('SMTP_FROM_NAME', 'from_name', 'Saber eProc'),
    ];
}
