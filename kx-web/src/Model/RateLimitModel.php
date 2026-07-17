<?php
declare(strict_types=1);

namespace KxWeb\Model;

use PDO;

final class RateLimitModel
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $windowSeconds,
        private readonly int $maxHits,
    ) {
    }

    /** Fixed-window counter. Returns true when the request is allowed. */
    public function allow(string $bucket): bool
    {
        $window = gmdate('Y-m-d H:i:00', (int)(floor(time() / $this->windowSeconds) * $this->windowSeconds));

        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limit (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        );
        $stmt->execute([$bucket, $window]);

        $stmt = $this->pdo->prepare(
            'SELECT hits FROM rate_limit WHERE bucket = ? AND window_start = ?'
        );
        $stmt->execute([$bucket, $window]);
        return (int)$stmt->fetchColumn() <= $this->maxHits;
    }
}
