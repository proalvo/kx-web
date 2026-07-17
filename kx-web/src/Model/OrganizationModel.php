<?php
declare(strict_types=1);

namespace KxWeb\Model;

use PDO;

final class OrganizationModel
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Resolve an organization provisioning key.
     * Key format: "org.{org_id}.{secret}" — the "org." prefix distinguishes
     * it from per-competition keys ("{competition_id}.{secret}") so both can
     * share the Authorization: Bearer header.
     *
     * @return array<string,mixed>|null organization row
     */
    public function findByOrgKey(string $key): ?array
    {
        if (!str_starts_with($key, 'org.')) {
            return null;
        }
        $rest = substr($key, 4);
        $dot = strpos($rest, '.');
        if ($dot === false) {
            return null;
        }
        $orgId  = substr($rest, 0, $dot);
        $secret = substr($rest, $dot + 1);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM organization WHERE org_id = ? AND status = 'active'"
        );
        $stmt->execute([$orgId]);
        $row = $stmt->fetch();

        if ($row && $row['org_key_hash'] !== '' && password_verify($secret, $row['org_key_hash'])) {
            return $row;
        }
        return null;
    }

    /** Make a URL slug unique by appending -2, -3, ... if needed. */
    public function uniqueSlug(string $base): string
    {
        $slug = $base;
        $n = 1;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM competition WHERE slug = ?');
        while (true) {
            $stmt->execute([$slug]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $slug;
            }
            $n++;
            $slug = substr($base, 0, 76) . '-' . $n; // keep within 80 chars
        }
    }

    public static function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        // transliterate the common Nordic letters, then strip the rest
        $s = strtr($s, ['ä' => 'a', 'ö' => 'o', 'å' => 'a', 'é' => 'e', 'ü' => 'u']);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s !== '' ? substr($s, 0, 80) : 'competition';
    }
}
