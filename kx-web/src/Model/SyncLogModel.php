<?php
declare(strict_types=1);

namespace KxWeb\Model;

use PDO;

final class SyncLogModel
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        string $competitionId,
        string $endpoint,
        string $payloadHash,
        string $ip,
        string $result,
        string $message = ''
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_log (competition_id, endpoint, payload_hash, ip, result, message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$competitionId, $endpoint, $payloadHash, $ip, $result, mb_substr($message, 0, 500)]);
    }
}
