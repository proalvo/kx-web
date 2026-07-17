<?php
declare(strict_types=1);

/**
 * KX-Web configuration.
 * Copy to config.local.php on the server and adjust; config.local.php
 * overrides these values and is never committed.
 */

$config = [
    'debug' => false,

    /*
     * URL prefix the app is mounted under.
     *   ''            -> own domain/subdomain, e.g. https://results.example.fi/
     *   '/kx-results' -> subdirectory of an existing site,
     *                    e.g. https://example.fi/kx-results/
     * Used for routing (prefix is stripped from incoming paths) and for
     * generating links in HTML pages and the JS poller.
     */
    'base_path' => '',

    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=kxweb;charset=utf8mb4',
        'user'     => 'kxweb',
        'password' => 'CHANGE_ME',
    ],

    // Public polling cache lifetimes (seconds)
    'cache' => [
        'live_max_age'     => 5,
        'official_max_age' => 3600,
    ],

    // Sync API rate limit: max requests per key per fixed window
    'rate_limit' => [
        'window_seconds' => 60,
        'max_hits'       => 60,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
