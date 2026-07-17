<?php
declare(strict_types=1);

namespace KxWeb\Controller;

use KxWeb\Http\HttpException;
use KxWeb\Http\Request;
use KxWeb\Http\Response;
use KxWeb\Model\CompetitionModel;
use KxWeb\Model\EventModel;
use KxWeb\Model\PhaseModel;
use KxWeb\Model\RateLimitModel;
use KxWeb\Model\SyncLogModel;

/**
 * Push endpoints called by kx-server. All payloads are full snapshots,
 * all handlers are idempotent and wrapped in a transaction.
 */
final class SyncController extends BaseController
{
    /** POST /api/v1/competition */
    public function competition(Request $request): Response
    {
        $competition = $this->guard($request);
        $payload = $request->json();
        $this->requireFields($payload, ['competition_id', 'name', 'country', 'start_date', 'end_date', 'events']);

        if ($payload['competition_id'] !== $competition['competition_id']) {
            throw new HttpException(422, 'competition_id does not match the API key');
        }

        $updated = $this->tx(function () use ($competition, $payload): int {
            (new CompetitionModel($this->pdo))
                ->upsertFromSync($competition['competition_id'], $payload);

            $events = new EventModel($this->pdo);
            $n = 1;
            foreach ($payload['events'] as $e) {
                $this->requireFields($e, ['event_id', 'event_code', 'event_name', 'gates']);
                $events->upsertFromSync($competition['competition_id'], $e);
                $n++;
            }
            return $n;
        });

        return $this->ok($request, $competition, 'competition', $updated);
    }

    /** POST /api/v1/phase — the workhorse */
    public function phase(Request $request): Response
    {
        $competition = $this->guard($request);
        $payload = $request->json();
        $this->requireFields($payload, ['event_code', 'phase', 'status', 'entries']);
        $this->validatePhaseEnum($payload);

        $event = (new EventModel($this->pdo))
            ->findByCode($competition['competition_id'], (string)$payload['event_code']);
        if ($event === null) {
            throw new HttpException(404, 'Unknown event_code for this competition (push /api/v1/competition first)');
        }

        $updated = $this->tx(fn (): int => (new PhaseModel($this->pdo))->replaceSnapshot(
            $event['event_id'],
            (string)$payload['phase'],
            (string)$payload['status'],
            $payload['entries'],
        ));

        return $this->ok($request, $competition, 'phase', $updated);
    }

    /** POST /api/v1/full — competition + events + all phases in one transaction */
    public function full(Request $request): Response
    {
        $competition = $this->guard($request);
        $payload = $request->json();
        $this->requireFields($payload, ['competition_id', 'name', 'country', 'start_date', 'end_date', 'events', 'phases']);

        if ($payload['competition_id'] !== $competition['competition_id']) {
            throw new HttpException(422, 'competition_id does not match the API key');
        }

        $updated = $this->tx(function () use ($competition, $payload): int {
            (new CompetitionModel($this->pdo))
                ->upsertFromSync($competition['competition_id'], $payload);

            $events = new EventModel($this->pdo);
            $byCode = [];
            $n = 1;
            foreach ($payload['events'] as $e) {
                $this->requireFields($e, ['event_id', 'event_code', 'event_name', 'gates']);
                $events->upsertFromSync($competition['competition_id'], $e);
                $byCode[(string)$e['event_code']] = (string)$e['event_id'];
                $n++;
            }

            $phases = new PhaseModel($this->pdo);
            foreach ($payload['phases'] as $p) {
                $this->requireFields($p, ['event_code', 'phase', 'status', 'entries']);
                $this->validatePhaseEnum($p);
                $eventId = $byCode[(string)$p['event_code']]
                    ?? throw new HttpException(422, 'Phase references unknown event_code ' . $p['event_code']);
                $n += $phases->replaceSnapshot(
                    $eventId, (string)$p['phase'], (string)$p['status'], $p['entries']
                );
            }
            return $n;
        });

        return $this->ok($request, $competition, 'full', $updated);
    }

    /** POST /api/v1/unpublish */
    public function unpublish(Request $request): Response
    {
        $competition = $this->guard($request);
        $payload = $request->json();
        $scope = (string)($payload['scope'] ?? 'phase');

        $updated = $this->tx(function () use ($competition, $payload, $scope): int {
            $phases = new PhaseModel($this->pdo);
            if ($scope === 'competition') {
                $stmt = $this->pdo->prepare(
                    "UPDATE competition SET status = 'draft' WHERE competition_id = ?"
                );
                $stmt->execute([$competition['competition_id']]);
                return 1;
            }
            $this->requireFields($payload, ['event_code']);
            $event = (new EventModel($this->pdo))
                ->findByCode($competition['competition_id'], (string)$payload['event_code']);
            if ($event === null) {
                throw new HttpException(404, 'Unknown event_code for this competition');
            }
            $phases->hide($event['event_id'], isset($payload['phase']) ? (string)$payload['phase'] : null);
            return 1;
        });

        return $this->ok($request, $competition, 'unpublish', $updated);
    }

    // ------------------------------------------------------------------

    /** Auth + rate limit, shared by every sync endpoint. @return array<string,mixed> */
    private function guard(Request $request): array
    {
        $competition = $this->authenticate($request);

        $rl = new RateLimitModel(
            $this->pdo,
            (int)$this->config['rate_limit']['window_seconds'],
            (int)$this->config['rate_limit']['max_hits'],
        );
        if (!$rl->allow('api:' . $competition['competition_id'])) {
            throw new HttpException(429, 'Rate limit exceeded, retry later');
        }
        return $competition;
    }

    /** Run a callable in a transaction, rolling back on any throwable. */
    private function tx(callable $fn): int
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $p */
    private function validatePhaseEnum(array $p): void
    {
        if (!in_array($p['phase'], PhaseModel::PHASES, true)) {
            throw new HttpException(422, 'Invalid phase: ' . (string)$p['phase']);
        }
        if (!in_array($p['status'], PhaseModel::STATUSES, true)) {
            throw new HttpException(422, 'Invalid status: ' . (string)$p['status']);
        }
        if (!is_array($p['entries'])) {
            throw new HttpException(422, 'entries must be an array');
        }
    }

    /** Log + standard SyncOk response. @param array<string,mixed> $competition */
    private function ok(Request $request, array $competition, string $endpoint, int $updated): Response
    {
        $hash = hash('sha256', $request->rawBody);
        (new SyncLogModel($this->pdo))->log(
            $competition['competition_id'], '/api/v1/' . $endpoint, $hash, $request->ip, 'ok'
        );
        return Response::json(['ok' => true, 'updated' => $updated, 'payload_hash' => $hash]);
    }
}
