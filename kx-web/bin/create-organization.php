<?php
declare(strict_types=1);

/**
 * One-time bootstrap PER ORGANIZATION (site admin, or later the /admin UI):
 * creates the organization and prints its provisioning key once.
 * After this, the organization creates all its competitions directly
 * from kx-server via POST /api/v1/competitions — no admin needed.
 *
 * Usage:
 *   php bin/create-organization.php "<org-name>" <country> <contact-email>
 * Example:
 *   php bin/create-organization.php "Melonta- ja soutuliitto" FIN office@example.fi
 */

use KxWeb\Db;

$appRoot = getenv('KX_APP_ROOT') ?: dirname(__DIR__);
require $appRoot . '/src/autoload.php';
$config = require $appRoot . '/config/config.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
if ($argc < 4) {
    exit("Usage: php bin/create-organization.php \"<org-name>\" <country> <contact-email>\n");
}
[, $name, $country, $email] = $argv;

if (!preg_match('/^[A-Z]{3}$/', $country)) {
    exit("Country must be a 3-letter code, e.g. FIN\n");
}

$pdo = Db::pdo($config['db']);

$stmt = $pdo->prepare('SELECT org_id FROM organization WHERE name = ?');
$stmt->execute([$name]);
if ($stmt->fetchColumn()) {
    exit("Organization already exists. To rotate its key, extend this script or use the admin UI.\n");
}

$orgId  = Db::uuid();
$secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$orgKey = 'org.' . $orgId . '.' . $secret;

$pdo->prepare(
    "INSERT INTO organization (org_id, name, country, contact_email, status, org_key_hash, org_key_hint)
     VALUES (?, ?, ?, ?, 'active', ?, ?)"
)->execute([
    $orgId, $name, $country, $email,
    password_hash($secret, PASSWORD_DEFAULT),
    substr($secret, -4),
]);

echo "Organization created.\n";
echo "  org_id: $orgId\n";
echo "  Organization key (give to the club, shown only once):\n";
echo "  $orgKey\n";
echo "\nEnter this key in kx-server settings; kx-server can then create\n";
echo "competitions itself via POST /api/v1/competitions.\n";
