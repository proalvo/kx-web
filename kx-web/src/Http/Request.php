<?php
declare(strict_types=1);

namespace KxWeb\Http;

final class Request
{
    /** @param array<string,string> $params Route parameters, filled by the router */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly string $rawBody,
        public readonly ?string $bearerToken,
        public readonly string $ip,
        public array $params = [],
    ) {
    }

    /**
     * @param string $basePath URL prefix the app is mounted under,
     *                         '' for a (sub)domain root or e.g. '/kx-results'
     *                         for a subdirectory install. Stripped before routing.
     */
    public static function fromGlobals(string $basePath = ''): self
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';

        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        $auth = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        $token = null;
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            $token = $m[1];
        }

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            rawBody: file_get_contents('php://input') ?: '',
            bearerToken: $token,
            ip: $_SERVER['REMOTE_ADDR'] ?? '',
        );
    }

    /** Decode the JSON body or fail with 422. @return array<string,mixed> */
    public function json(): array
    {
        $data = json_decode($this->rawBody, true);
        if (!is_array($data)) {
            throw new HttpException(422, 'Request body must be a JSON object');
        }
        return $data;
    }
}
