<?php
declare(strict_types=1);

namespace KxWeb\Controller;

use KxWeb\Db;
use KxWeb\Http\HttpException;
use KxWeb\Http\Request;
use KxWeb\Model\CompetitionModel;
use PDO;

abstract class BaseController
{
    protected PDO $pdo;

    /** @param array<string,mixed> $config */
    public function __construct(protected readonly array $config)
    {
        $this->pdo = Db::pdo($config['db']);
    }

    /**
     * Authenticate a sync request: Bearer key -> competition row.
     * @return array<string,mixed> competition row
     */
    protected function authenticate(Request $request): array
    {
        if ($request->bearerToken === null) {
            throw new HttpException(401, 'Missing Authorization: Bearer <api_key>');
        }
        $competition = (new CompetitionModel($this->pdo))->findByApiKey($request->bearerToken);
        if ($competition === null) {
            throw new HttpException(401, 'Invalid API key');
        }
        return $competition;
    }

    /** Build an app URL honoring the configured base_path. */
    protected function url(string $path): string
    {
        $base = rtrim((string)($this->config['base_path'] ?? ''), '/');
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Require fields in a JSON payload, 422 with details when missing.
     * @param array<string,mixed> $data
     * @param list<string> $fields
     */
    protected function requireFields(array $data, array $fields): void
    {
        $missing = array_values(array_filter($fields, fn ($f) => !array_key_exists($f, $data)));
        if ($missing !== []) {
            throw new HttpException(422, 'Missing required fields: ' . implode(', ', $missing));
        }
    }
}
