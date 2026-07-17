<?php
declare(strict_types=1);

namespace KxWeb\Controller;

use KxWeb\Db;
use KxWeb\Http\HttpException;
use KxWeb\Http\Request;
use KxWeb\Http\Response;
use KxWeb\Model\OrganizationModel;
use KxWeb\Model\RateLimitModel;

/**
 * Provisioning endpoint: lets kx-server create a competition on the
 * website using an ORGANIZATION key ("org.{org_id}.{secret}") and
 * receive the per-competition API key — no site admin involvement
 * per competition. The competition key is returned ONCE and stored
 * only as a hash.
 */
final class ProvisioningController extends BaseController
{
    /** POST /api/v1/competitions  (note: plural = create; singular /competition = sync) */
    public function createCompetition(Request $request): Response
    {
        $org = $this->authenticateOrg($request);

        $rl = new RateLimitModel(
            $this->pdo,
            (int)$this->config['rate_limit']['window_seconds'],
            (int)$this->config['rate_limit']['max_hits'],
        );
        if (!$rl->allow('provision:' . $org['org_id'])) {
            throw new HttpException(429, 'Rate limit exceeded, retry later');
        }

        $p = $request->json();
        $this->requireFields($p, ['competition_id', 'name', 'country', 'start_date', 'end_date']);

        if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$p['competition_id'])) {
            throw new HttpException(422, 'competition_id must be a UUID');
        }
        if (!preg_match('/^[A-Z]{3}$/', (string)$p['country'])) {
            throw new HttpException(422, 'country must be a 3-letter code');
        }

        // Idempotency: same competition_id from the same org -> conflict info,
        // never a silent duplicate and never a key leak.
        $stmt = $this->pdo->prepare('SELECT org_id, slug FROM competition WHERE competition_id = ?');
        $stmt->execute([(string)$p['competition_id']]);
        if ($existing = $stmt->fetch()) {
            if ($existing['org_id'] === $org['org_id']) {
                throw new HttpException(409,
                    'Competition already exists (slug: ' . $existing['slug'] . '). ' .
                    'The API key is shown only at creation — if it was lost, ' .
                    'regenerate it on the website or create a new competition_id.');
            }
            throw new HttpException(409, 'competition_id already in use');
        }

        $orgModel = new OrganizationModel($this->pdo);
        $slug = $orgModel->uniqueSlug(
            isset($p['slug']) && $p['slug'] !== ''
                ? OrganizationModel::slugify((string)$p['slug'])
                : OrganizationModel::slugify((string)$p['name'])
        );

        // Competition API key: "{competition_id}.{secret}", secret shown once
        $secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $apiKey = $p['competition_id'] . '.' . $secret;

        $stmt = $this->pdo->prepare(
            "INSERT INTO competition
                (competition_id, org_id, slug, name, country, location,
                 start_date, end_date, time_zone, comp_type, status,
                 api_key_hash, api_key_hint)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?, ?)"
        );
        $stmt->execute([
            (string)$p['competition_id'],
            $org['org_id'],
            $slug,
            (string)$p['name'],
            (string)$p['country'],
            (string)($p['location'] ?? ''),
            (string)$p['start_date'],
            (string)$p['end_date'],
            (string)($p['time_zone'] ?? 'Europe/Helsinki'),
            (string)($p['comp_type'] ?? 'Domestic'),
            password_hash($secret, PASSWORD_DEFAULT),
            substr($secret, -4),
        ]);

        return Response::json([
            'ok'      => true,
            'slug'    => $slug,
            'api_key' => $apiKey,   // shown ONCE — kx-server must store it now
            'public_url' => rtrim((string)($this->config['base_path'] ?? ''), '/') . '/competition/' . $slug,
        ], 201);
    }

    /** @return array<string,mixed> organization row */
    private function authenticateOrg(Request $request): array
    {
        if ($request->bearerToken === null) {
            throw new HttpException(401, 'Missing Authorization: Bearer <org_key>');
        }
        $org = (new OrganizationModel($this->pdo))->findByOrgKey($request->bearerToken);
        if ($org === null) {
            throw new HttpException(401, 'Invalid organization key');
        }
        return $org;
    }
}
