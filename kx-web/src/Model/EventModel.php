<?php
declare(strict_types=1);

namespace KxWeb\Model;

use PDO;

final class EventModel
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Upsert one event from an EventSync payload. */
    public function upsertFromSync(string $competitionId, array $e): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO event (event_id, competition_id, event_code, event_name, gates, sort_order)
             VALUES (:event_id, :competition_id, :event_code, :event_name, :gates, :sort_order)
             ON DUPLICATE KEY UPDATE
                event_code = VALUES(event_code),
                event_name = VALUES(event_name),
                gates      = VALUES(gates),
                sort_order = VALUES(sort_order)'
        );
        $stmt->execute([
            'event_id'       => (string)$e['event_id'],
            'competition_id' => $competitionId,
            'event_code'     => (string)$e['event_code'],
            'event_name'     => (string)$e['event_name'],
            'gates'          => (int)$e['gates'],
            'sort_order'     => (int)($e['sort_order'] ?? 0),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $competitionId, string $eventCode): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM event WHERE competition_id = ? AND event_code = ?'
        );
        $stmt->execute([$competitionId, $eventCode]);
        return $stmt->fetch() ?: null;
    }

    /** Events of a competition with their phase statuses. @return list<array<string,mixed>> */
    public function listWithPhases(string $competitionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.event_code, e.event_name, e.gates, e.sort_order,
                    p.phase, p.status, p.updated_at
             FROM event e
             LEFT JOIN phase p ON p.event_id = e.event_id AND p.status <> \'hidden\'
             WHERE e.competition_id = ?
             ORDER BY e.sort_order, e.event_code,
                      FIELD(p.phase, \'TIME_TRIAL\',\'QUALIFICATION\',\'QUARTER_FINAL\',
                                     \'SEMI_FINAL\',\'FINAL\',\'OFFICIAL_RESULT\')'
        );
        $stmt->execute([$competitionId]);

        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $code = $row['event_code'];
            if (!isset($events[$code])) {
                $events[$code] = [
                    'event_code' => $code,
                    'event_name' => $row['event_name'],
                    'gates'      => (int)$row['gates'],
                    'phases'     => [],
                ];
            }
            if ($row['phase'] !== null) {
                $events[$code]['phases'][] = [
                    'phase'      => $row['phase'],
                    'status'     => $row['status'],
                    'updated_at' => $row['updated_at'],
                ];
            }
        }
        return array_values($events);
    }
}
