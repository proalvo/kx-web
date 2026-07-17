<?php
declare(strict_types=1);

namespace KxWeb\Controller;

use KxWeb\Http\HttpException;
use KxWeb\Http\Request;
use KxWeb\Http\Response;
use KxWeb\Model\CompetitionModel;
use KxWeb\Model\EventModel;
use KxWeb\Model\PhaseModel;

/**
 * Read-only JSON endpoints used by the website's own pages (polling)
 * and available to third parties. No authentication.
 */
final class PublicApiController extends BaseController
{
    /** GET /api/v1/public/competitions */
    public function listCompetitions(Request $request): Response
    {
        $status  = $request->query['status'] ?? null;
        $country = $request->query['country'] ?? null;
        if ($country !== null && !preg_match('/^[A-Z]{3}$/', $country)) {
            throw new HttpException(422, 'country must be a 3-letter code');
        }

        $rows = (new CompetitionModel($this->pdo))->listPublished($status, $country);
        return Response::json($rows, 200, ['Cache-Control' => 'max-age=60']);
    }

    /** GET /api/v1/public/competitions/{slug} */
    public function getCompetition(Request $request): Response
    {
        $competition = $this->publishedCompetition($request->params['slug']);
        $events = (new EventModel($this->pdo))->listWithPhases($competition['competition_id']);

        return Response::json([
            'slug'         => $competition['slug'],
            'name'         => $competition['name'],
            'country'      => $competition['country'],
            'location'     => $competition['location'],
            'start_date'   => $competition['start_date'],
            'end_date'     => $competition['end_date'],
            'organization' => $competition['organization'],
            'events'       => $events,
        ], 200, ['Cache-Control' => 'max-age=30']);
    }

    /** GET /api/v1/public/competitions/{slug}/events/{eventCode}/phases/{phase} */
    public function getPhase(Request $request): Response
    {
        $competition = $this->publishedCompetition($request->params['slug']);

        $phaseName = strtoupper($request->params['phase']);
        if (!in_array($phaseName, PhaseModel::PHASES, true)) {
            throw new HttpException(404, 'Unknown phase');
        }

        $data = (new PhaseModel($this->pdo))->publicPhase(
            $competition['competition_id'],
            $request->params['eventCode'],
            $phaseName,
        );
        if ($data === null) {
            throw new HttpException(404, 'Phase not published');
        }

        return $this->cachedJson($data);
    }

    /** GET /api/v1/public/competitions/{slug}/live */
    public function getLive(Request $request): Response
    {
        $competition = $this->publishedCompetition($request->params['slug']);
        $live = (new PhaseModel($this->pdo))->livePhase($competition['competition_id']);

        if ($live === null) {
            return Response::json(
                ['live' => null], 200,
                ['Cache-Control' => 'max-age=' . (int)$this->config['cache']['live_max_age']]
            );
        }
        return $this->cachedJson(['live' => $live], $live);
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function publishedCompetition(string $slug): array
    {
        $competition = (new CompetitionModel($this->pdo))->findPublishedBySlug($slug);
        if ($competition === null) {
            throw new HttpException(404, 'Competition not found');
        }
        return $competition;
    }

    /**
     * JSON response with ETag / If-None-Match and status-dependent
     * Cache-Control (short while live, long when official).
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $phase phase data for cache decisions (defaults to $body)
     */
    private function cachedJson(array $body, ?array $phase = null): Response
    {
        $phase ??= $body;
        // ETag must be content-addressed: hash the actual response body.
        // (Deriving it from phase.updated_at + entry count was a bug — a
        // snapshot replace keeps the count identical and MariaDB skips the
        // phase-row timestamp bump when values are unchanged, so results
        // could change without the ETag changing, and pollers got stale
        // 304s / stale browser-cached bodies.)
        $etag = '"' . hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE) ?: '') . '"';

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch === $etag) {
            return Response::notModified($etag);
        }

        $maxAge = ($phase['status'] ?? '') === 'official'
            ? (int)$this->config['cache']['official_max_age']
            : (int)$this->config['cache']['live_max_age'];

        return Response::json($body, 200, [
            'ETag'          => $etag,
            'Cache-Control' => 'max-age=' . $maxAge,
        ]);
    }
}
