<?php
declare(strict_types=1);

/**
 * KX-Web — front controller
 *
 * All requests are rewritten here (see .htaccess). Dependency-free:
 * plain PHP 8.1+, PDO_MySQL. Suitable for shared hosting.
 */

use KxWeb\Http\Request;
use KxWeb\Http\Response;
use KxWeb\Router;

/*
 * KX_APP_ROOT: directory containing src/ and config/.
 *
 * Default layout (own subdomain): app root is one level above public/.
 * Subdirectory install (e.g. public_html/kx-results/ on a site that
 * already has content): copy only the contents of public/ into the
 * subdirectory, put src/ + config/ somewhere OUTSIDE the webroot
 * (e.g. /home/USER/kx-web-app) and set the path below — or set the
 * KX_APP_ROOT environment variable in the hosting panel / .htaccess.
 */
$appRoot = getenv('KX_APP_ROOT') ?: dirname(__DIR__);

require $appRoot . '/src/autoload.php';

$config = require $appRoot . '/config/config.php';

error_reporting(E_ALL);
ini_set('display_errors', $config['debug'] ? '1' : '0');
date_default_timezone_set('UTC');

$router = new Router();

// ---------------------------------------------------------------------
// Sync API (POST, Bearer API key) — called by kx-server
// ---------------------------------------------------------------------
$router->post('/api/v1/competitions', [KxWeb\Controller\ProvisioningController::class, 'createCompetition']); // create (org key)
$router->post('/api/v1/competition', [KxWeb\Controller\SyncController::class, 'competition']);        // sync (competition key)
$router->post('/api/v1/phase',       [KxWeb\Controller\SyncController::class, 'phase']);
$router->post('/api/v1/full',        [KxWeb\Controller\SyncController::class, 'full']);
$router->post('/api/v1/unpublish',   [KxWeb\Controller\SyncController::class, 'unpublish']);

// ---------------------------------------------------------------------
// Public JSON API (GET, no auth) — used by spectator pages for polling
// ---------------------------------------------------------------------
$router->get('/api/v1/public/competitions',
    [KxWeb\Controller\PublicApiController::class, 'listCompetitions']);
$router->get('/api/v1/public/competitions/{slug}',
    [KxWeb\Controller\PublicApiController::class, 'getCompetition']);
$router->get('/api/v1/public/competitions/{slug}/events/{eventCode}/phases/{phase}',
    [KxWeb\Controller\PublicApiController::class, 'getPhase']);
$router->get('/api/v1/public/competitions/{slug}/live',
    [KxWeb\Controller\PublicApiController::class, 'getLive']);

// ---------------------------------------------------------------------
// HTML pages (server-rendered) — minimal placeholders for now
// ---------------------------------------------------------------------
$router->get('/',                          [KxWeb\Controller\PageController::class, 'home']);
$router->get('/competition/{slug}',                  [KxWeb\Controller\PageController::class, 'competition']);
$router->get('/competition/{slug}/{eventCode}',      [KxWeb\Controller\PageController::class, 'event']);
$router->get('/competition/{slug}/{eventCode}/{phase}', [KxWeb\Controller\PageController::class, 'phase']);

try {
    $request  = Request::fromGlobals($config['base_path'] ?? '');
    $response = $router->dispatch($request, $config);
} catch (\KxWeb\Http\HttpException $e) {
    $response = Response::json(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (\Throwable $e) {
    error_log('[kx-web] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $response = Response::json(
        ['ok' => false, 'error' => $config['debug'] ? $e->getMessage() : 'Internal server error'],
        500
    );
}

$response->send();
