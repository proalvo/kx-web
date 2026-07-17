<?php
declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the KxWeb\ namespace.
 * No composer required — works on any shared host.
 */
spl_autoload_register(function (string $class): void {
    $prefix  = 'KxWeb\\';
    $baseDir = __DIR__ . '/';
    if (str_starts_with($class, $prefix)) {
        $path = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
