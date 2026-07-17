<?php
declare(strict_types=1);

namespace KxWeb;

use KxWeb\Http\HttpException;
use KxWeb\Http\Request;
use KxWeb\Http\Response;

/**
 * Tiny router. Routes are literal paths with {param} placeholders,
 * e.g. /competition/{slug}/{eventCode}. Params match one path segment ([^/]+).
 */
final class Router
{
    /** @var array<string, list<array{pattern:string, names:list<string>, handler:array{class-string,string}}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    /** @param array{class-string,string} $handler [ControllerClass::class, 'method'] */
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param array{class-string,string} $handler */
    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param array{class-string,string} $handler */
    private function add(string $method, string $path, array $handler): void
    {
        $names = [];
        $pattern = preg_replace_callback(
            '/\{(\w+)\}/',
            function (array $m) use (&$names): string {
                $names[] = $m[1];
                return '([^/]+)';
            },
            $path
        );
        $this->routes[$method][] = [
            'pattern' => '#^' . $pattern . '$#',
            'names'   => $names,
            'handler' => $handler,
        ];
    }

    /** @param array<string,mixed> $config */
    public function dispatch(Request $request, array $config): Response
    {
        $candidates = $this->routes[$request->method] ?? [];
        foreach ($candidates as $route) {
            if (preg_match($route['pattern'], $request->path, $m)) {
                array_shift($m);
                $request->params = array_combine($route['names'], array_map('urldecode', $m)) ?: [];
                [$class, $method] = $route['handler'];
                $controller = new $class($config);
                return $controller->$method($request);
            }
        }

        // Path exists under another method? -> 405, otherwise 404
        foreach ($this->routes as $method => $routes) {
            if ($method === $request->method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $request->path)) {
                    throw new HttpException(405, 'Method not allowed');
                }
            }
        }
        throw new HttpException(404, 'Not found');
    }
}
