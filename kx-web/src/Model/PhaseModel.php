<?php
declare(strict_types=1);

namespace KxWeb\Model;

use KxWeb\Db;
use PDO;

final class PhaseModel
{
    public const PHASES = [
        'TIME_TRIAL', 'QUALIFICATION', 'REPECHAGE', 'QUARTER_FINAL',
        'SEMI_FINAL', 'FINAL', 'OFFICIAL_RESULT',
    ];

    public const STATUSES = ['hidden', 'startlist', 'live', 'official'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Replace the full state of one phase (the /api/v1/phase workhorse).
     * Idempotent: delete + insert inside the caller's transaction.
     *
     * @param list<array<string,mixed>> $entries
     * @return int rows written
     */
    public function replaceSnapshot(string $eventId, string $phase, string $status, array $entries): int
    {
        // Upsert the phase row
        $stmt = $this->pdo->prepare(
            'INSERT INTO phase (phase_id, event_id, phase, status, published_at)
             VALUES (:phase_id, :event_id, :phase, :status,
                     IF(:status2 <> \'hidden\', NOW(), NULL))
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                published_at = COALESCE(published_at, VALUES(published_at))'
        );
        $stmt->execute([
            'phase_id' => Db::uuid(),
            'event_id' => $eventId,
            'phase'    => $phase,
            'status'   => $status,
            'status2'  => $status,
        ]);

        $phaseId = $this->phaseId($eventId, $phase);

        // Full snapshot replace
        $this->pdo->prepare('DELETE FROM phase_entry WHERE phase_id = ?')->execute([$phaseId]);

        $ins = $this->pdo->prepare(
            'INSERT INTO phase_entry
                (entry_id, phase_id, grp, slot_no, bib, rank,
                 first_name, last_name, club, country, icf_id, nf_id,
                 score, dns, dnf, dsq, ral,
                 gate1, gate2, gate3, gate4, gate5, gate6, gate7, gate8)
             VALUES
                (:entry_id, :phase_id, :grp, :slot_no, :bib, :rank,
                 :first_name, :last_name, :club, :country, :icf_id, :nf_id,
                 :score, :dns, :dnf, :dsq, :ral,
                 :g1, :g2, :g3, :g4, :g5, :g6, :g7, :g8)'
        );

        $written = 0;
        foreach ($entries as $e) {
            $gates = $e['gates'] ?? [];
            $ins->execute([
                'entry_id'   => Db::uuid(),
                'phase_id'   => $phaseId,
                'grp'        => (int)($e['grp'] ?? 1),
                'slot_no'    => (int)$e['slot_no'],
                'bib'        => isset($e['bib']) && $e['bib'] !== null ? (string)$e['bib'] : null,
                'rank'       => isset($e['rank']) ? (int)$e['rank'] : null,
                'first_name' => (string)$e['first_name'],
                'last_name'  => (string)$e['last_name'],
                'club'       => (string)($e['club'] ?? ''),
                'country'    => (string)($e['country'] ?? ''),
                'icf_id'     => isset($e['icf_id']) ? (string)$e['icf_id'] : null,
                'nf_id'      => isset($e['nf_id']) ? (string)$e['nf_id'] : null,
                'score'      => isset($e['score']) ? (string)$e['score'] : null,
                'dns'        => (int)(bool)($e['dns'] ?? false),
                'dnf'        => (int)(bool)($e['dnf'] ?? false),
                'dsq'        => (int)(bool)($e['dsq'] ?? false),
                'ral'        => (int)(bool)($e['ral'] ?? false),
                'g1' => self::gate($gates, 0), 'g2' => self::gate($gates, 1),
                'g3' => self::gate($gates, 2), 'g4' => self::gate($gates, 3),
                'g5' => self::gate($gates, 4), 'g6' => self::gate($gates, 5),
                'g7' => self::gate($gates, 6), 'g8' => self::gate($gates, 7),
            ]);
            $written++;
        }
        return $written;
    }

    public function hide(string $eventId, ?string $phase): void
    {
        if ($phase !== null) {
            $stmt = $this->pdo->prepare(
                "UPDATE phase SET status = 'hidden' WHERE event_id = ? AND phase = ?"
            );
            $stmt->execute([$eventId, $phase]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE phase SET status = 'hidden' WHERE event_id = ?"
            );
            $stmt->execute([$eventId]);
        }
    }

    /**
     * Public shape of one phase (PhasePublic schema), or null when hidden/missing.
     * @return array<string,mixed>|null
     */
    public function publicPhase(string $competitionId, string $eventCode, string $phase): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.phase_id, p.phase, p.status, p.updated_at,
                    e.event_code, e.event_name, e.gates
             FROM phase p
             JOIN event e ON e.event_id = p.event_id
             WHERE e.competition_id = ? AND e.event_code = ?
               AND p.phase = ? AND p.status <> 'hidden'"
        );
        $stmt->execute([$competitionId, $eventCode, $phase]);
        $head = $stmt->fetch();
        if (!$head) {
            return null;
        }
        return $this->assemblePublic($head);
    }

    /** Currently live phase of a competition, if any. @return array<string,mixed>|null */
    public function livePhase(string $competitionId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.phase_id, p.phase, p.status, p.updated_at,
                    e.event_code, e.event_name, e.gates
             FROM phase p
             JOIN event e ON e.event_id = p.event_id
             WHERE e.competition_id = ? AND p.status = 'live'
             ORDER BY p.updated_at DESC
             LIMIT 1"
        );
        $stmt->execute([$competitionId]);
        $head = $stmt->fetch();
        return $head ? $this->assemblePublic($head) : null;
    }

    /** @param array<string,mixed> $head */
    private function assemblePublic(array $head): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT grp, slot_no, bib, rank, first_name, last_name, club, country,
                    score, dns, dnf, dsq, ral,
                    gate1, gate2, gate3, gate4, gate5, gate6, gate7, gate8
             FROM phase_entry
             WHERE phase_id = ?
             ORDER BY grp, COALESCE(rank, 255), slot_no'
        );
        $stmt->execute([$head['phase_id']]);

        $gateCount = (int)$head['gates'];
        $entries = [];
        foreach ($stmt->fetchAll() as $r) {
            $gates = [];
            for ($i = 1; $i <= $gateCount; $i++) {
                $v = $r['gate' . $i];
                $gates[] = $v === null ? null : (int)$v;
            }
            $entries[] = [
                'grp'        => (int)$r['grp'],
                'slot_no'    => (int)$r['slot_no'],
                'bib'        => $r['bib'],
                'rank'       => $r['rank'] === null ? null : (int)$r['rank'],
                'first_name' => $r['first_name'],
                'last_name'  => $r['last_name'],
                'club'       => $r['club'],
                'country'    => $r['country'],
                'score'      => $r['score'] === null ? null : (float)$r['score'],
                'dns'        => (bool)$r['dns'],
                'dnf'        => (bool)$r['dnf'],
                'dsq'        => (bool)$r['dsq'],
                'ral'        => (bool)$r['ral'],
                'gates'      => $gates,
            ];
        }

        return [
            'event_code' => $head['event_code'],
            'event_name' => $head['event_name'],
            'phase'      => $head['phase'],
            'status'     => $head['status'],
            'gates'      => $gateCount,
            'updated_at' => $head['updated_at'],
            'entries'    => $entries,
        ];
    }

    private function phaseId(string $eventId, string $phase): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT phase_id FROM phase WHERE event_id = ? AND phase = ?'
        );
        $stmt->execute([$eventId, $phase]);
        return (string)$stmt->fetchColumn();
    }

    /** @param list<int|null> $gates */
    private static function gate(array $gates, int $index): ?int
    {
        $v = $gates[$index] ?? null;
        return $v === null ? null : (int)$v;
    }
}
