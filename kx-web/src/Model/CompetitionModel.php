<?php
declare(strict_types=1);

namespace KxWeb\Model;

use PDO;

final class CompetitionModel
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Resolve an API key to a competition row, or null.
     * Keys have the form "{competition_id}.{secret}" so we can look up the
     * row first and then verify the secret against its bcrypt hash —
     * avoiding a full-table hash scan.
     *
     * @return array<string,mixed>|null
     */
    public function findByApiKey(string $apiKey): ?array
    {
        $dot = strpos($apiKey, '.');
        if ($dot === false) {
            return null;
        }
        $competitionId = substr($apiKey, 0, $dot);
        $secret        = substr($apiKey, $dot + 1);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM competition WHERE competition_id = ?'
        );
        $stmt->execute([$competitionId]);
        $row = $stmt->fetch();

        if ($row && password_verify($secret, $row['api_key_hash'])) {
            return $row;
        }
        return null;
    }

    /** Upsert from a CompetitionSync payload (id and slug never change here). */
    public function upsertFromSync(string $competitionId, array $p): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE competition SET
                name = :name, country = :country, location = :location,
                start_date = :start_date, end_date = :end_date,
                time_zone = :time_zone, comp_type = :comp_type
             WHERE competition_id = :id'
        );
        $stmt->execute([
            'name'       => (string)$p['name'],
            'country'    => (string)$p['country'],
            'location'   => (string)($p['location'] ?? ''),
            'start_date' => (string)$p['start_date'],
            'end_date'   => (string)$p['end_date'],
            'time_zone'  => (string)($p['time_zone'] ?? 'Europe/Helsinki'),
            'comp_type'  => (string)($p['comp_type'] ?? 'Domestic'),
            'id'         => $competitionId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listPublished(?string $status, ?string $country): array
    {
        $sql = "SELECT c.slug, c.name, c.country, c.location,
                       c.start_date, c.end_date, o.name AS organization
                FROM competition c
                JOIN organization o ON o.org_id = c.org_id
                WHERE c.status = 'published'";
        $args = [];

        if ($country !== null) {
            $sql .= ' AND c.country = ?';
            $args[] = $country;
        }
        // upcoming / ongoing / past relative to today (UTC date is close enough for listing)
        $today = gmdate('Y-m-d');
        switch ($status) {
            case 'upcoming':
                $sql .= ' AND c.start_date > ?';
                $args[] = $today;
                break;
            case 'ongoing':
                $sql .= ' AND c.start_date <= ? AND c.end_date >= ?';
                $args[] = $today;
                $args[] = $today;
                break;
            case 'past':
                $sql .= ' AND c.end_date < ?';
                $args[] = $today;
                break;
        }
        $sql .= ' ORDER BY c.start_date DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findPublishedBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, o.name AS organization
             FROM competition c
             JOIN organization o ON o.org_id = c.org_id
             WHERE c.slug = ? AND c.status = 'published'"
        );
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }
}
